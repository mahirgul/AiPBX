<?php
/** Disposable installed system only: AIPBX_E2E=1 php scripts/e2e/proxy_https.php. */
if (getenv('AIPBX_E2E') !== '1' || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run as root with AIPBX_E2E=1 only in a disposable installation.\n");
    exit(2);
}
$install = getenv('AIPBX_INSTALL_DIR') ?: '/opt/aipbx';
require $install . '/web/config.php';
require_once $install . '/web/src/services/ReverseProxyService.php';
$db = getDB();
$domain = (string) portalEnv('PORTAL_DOMAIN');
$cookie = tempnam(sys_get_temp_dir(), 'proxy-e2e-');
$saved = $db->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key LIKE 'proxy_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$userId = null;
$invalidConfig = null;
$passed = 0;

function proxyCheck(bool $ok, string $name): void {
    global $passed;
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS $name\n";
    $passed++;
}

/** @return array{int, string} */
function proxyRequest(string $url, array $headers = [], ?array $post = null, bool $session = false): array {
    global $domain, $cookie;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$domain . ':443:127.0.0.1'], CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PATH_AS_IS => true]);
    if ($session) { curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie]); }
    if ($post !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code === 0) { throw new RuntimeException('HTTP request failed: ' . curl_error($ch)); }
    return [$code, is_string($body) ? $body : ''];
}

function proxySave(bool $enabled, string $trusted, bool $expectSuccess = true): void {
    global $domain;
    [$code, $page] = proxyRequest('https://' . $domain . '/reverse-proxy', session: true);
    if ($code !== 200 || !preg_match('/name="csrf_token" value="([^"]+)"/', $page, $match)) {
        throw new RuntimeException('Could not read reverse proxy form');
    }
    $post = ['action' => 'save_settings', 'csrf_token' => $match[1],
        'proxy_trusted_proxies' => $trusted, 'proxy_external_fqdn' => $domain,
        'proxy_external_port' => 443, 'proxy_tls_termination' => 'edge', 'proxy_type' => 'nginx'];
    if ($enabled) { $post['proxy_enabled'] = '1'; }
    [$code, $body] = proxyRequest('https://' . $domain . '/reverse-proxy', post: $post, session: true);
    $message = $expectSuccess ? 'reverse_proxy.msg_saved_ok' : 'reverse_proxy.err_apply_failed';
    if ($code !== 200 || !str_contains($body, t($message))) {
        throw new RuntimeException('Proxy settings were not applied through the Apache portal');
    }
}

try {
    $username = 'e2e_proxy_' . bin2hex(random_bytes(6));
    $password = bin2hex(random_bytes(24));
    $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, is_active, must_reset_password) VALUES (?, ?, 'E2E proxy test', 'admin', 1, 0)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $db->lastInsertId();
    [, $page] = proxyRequest('https://' . $domain . '/login', session: true);
    if (!preg_match('/name="csrf_token" value="([^"]+)"/', $page, $csrf)
        || !preg_match('/(\d+) \+ (\d+) = \?/', $page, $captcha)) {
        throw new RuntimeException('Could not read login challenge');
    }
    proxyRequest('https://' . $domain . '/login', post: ['csrf_token' => $csrf[1],
        'username' => $username, 'password' => $password, 'captcha_answer' => (int)$captcha[1] + (int)$captcha[2]], session: true);
    [$code, $page] = proxyRequest('https://' . $domain . '/reverse-proxy', session: true);
    proxyCheck($code === 200 && str_contains($page, 'proxy_trusted_proxies'), 'temporary admin can configure proxy policy');

    $ip = trim(explode(' ', trim((string) shell_exec('hostname -I')))[0]);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { throw new RuntimeException('IPv4 test interface required'); }
    proxySave(false, '127.0.0.1, ::1');
    foreach (['http://127.0.0.1/login', "http://$ip/login"] as $url) {
        proxyCheck(proxyRequest($url, ['X-Forwarded-Proto: https'])[0] === 301, 'disabled proxy mode rejects forged HTTPS at ' . $url);
    }

    proxySave(true, '127.0.0.1, ::1');
    proxyCheck(proxyRequest('http://127.0.0.1/login', ['X-Forwarded-Proto: https'])[0] === 200, 'trusted proxy may assert HTTPS');
    proxyCheck(proxyRequest('http://[::1]/login', ['X-Forwarded-Proto: https'])[0] === 200, 'IPv6 trusted proxy may assert HTTPS');
    proxyCheck(proxyRequest('http://127.0.0.1/login')[0] === 301, 'trusted proxy without HTTPS assertion is redirected');
    proxyCheck(proxyRequest('http://127.0.0.1/login', ['X-Forwarded-Proto: https, http'])[0] === 301, 'ambiguous forwarded protocol is redirected');
    proxyCheck(proxyRequest("http://$ip/login", ['X-Forwarded-Proto: https', 'X-Forwarded-For: 127.0.0.1'])[0] === 301, 'forged client address cannot turn an untrusted peer into a proxy');
    proxyCheck(proxyRequest("http://$ip/api/plugins/auto-dialer/callback", ['X-Forwarded-Proto: https', 'X-Forwarded-For: 127.0.0.1'])[0] === 301, 'remote plugin callback cannot impersonate loopback');
    proxyCheck(proxyRequest('http://127.0.0.1/api/plugins/auto-dialer/callback')[0] !== 301, 'local Asterisk callback keeps plain HTTP');
    proxyCheck(proxyRequest("http://$ip/provision/invalid-token")[0] !== 301, 'phone provisioning keeps HTTP handling');
    proxyCheck(proxyRequest("http://$ip/.well-known/acme-challenge/e2e-missing")[0] !== 301, 'ACME keeps HTTP handling');
    proxyCheck(proxyRequest("http://$ip/provision/../login", ['X-Forwarded-Proto: https'])[0] === 301, 'normalized provisioning traversal cannot bypass redirect');

    $ws = ['X-Forwarded-Proto: https', 'Connection: Upgrade', 'Upgrade: websocket',
        'Sec-WebSocket-Version: 13', 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==', 'Sec-WebSocket-Protocol: sip'];
    proxyCheck(proxyRequest("http://$ip/ws", $ws)[0] === 301, 'untrusted WebSocket peer is redirected');
    proxyCheck(proxyRequest('http://127.0.0.1/ws', $ws)[0] === 101, 'trusted proxy can establish the Asterisk WebSocket');

    proxySave(true, '127.0.0.0/8, ::1/128');
    proxyCheck(proxyRequest('http://127.0.0.1/login', ['X-Forwarded-Proto: HTTPS'])[0] === 200, 'CIDR proxy trust and case-insensitive HTTPS work');

    proxySave(true, '127.0.0.1, 0:0:0:0:0:0:0:1');
    proxyCheck(proxyRequest('http://[::1]/login', ['X-Forwarded-Proto: https'])[0] === 200
        && ReverseProxyService::isHttps(['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_PROTO' => 'https']),
        'Apache and PHP agree on equivalent IPv6 proxy addresses');

    $policy = '/etc/apache2/aipbx/proxy-https.conf';
    $hash = hash_file('sha256', $policy);
    $invalidConfig = '/etc/apache2/conf-enabled/aipbx-e2e-' . bin2hex(random_bytes(6)) . '.conf';
    file_put_contents($invalidConfig, "AiPBXE2EInvalidDirective\n");
    proxySave(false, '', false);
    proxyCheck(ReverseProxyService::getSettings()['enabled'] && hash_file('sha256', $policy) === $hash, 'failed portal save rolls back DB and Apache policy');
    $result = PrivHelper::run(['proxy-https', 'apply'], json_encode(['enabled' => false, 'trusted_proxies' => ''], JSON_THROW_ON_ERROR));
    proxyCheck(!$result['success'] && hash_file('sha256', $policy) === $hash, 'Apache config failure restores previous policy bytes');
    unlink($invalidConfig);
    $invalidConfig = null;
    proxyCheck(proxyRequest('http://127.0.0.1/login', ['X-Forwarded-Proto: https'])[0] === 200, 'failed apply keeps the previous active policy');
    $result = PrivHelper::run(['proxy-https', 'apply'], json_encode(['enabled' => true, 'trusted_proxies' => "127.0.0.1\nInclude /tmp/evil"], JSON_THROW_ON_ERROR));
    proxyCheck(!$result['success'] && hash_file('sha256', $policy) === $hash, 'privileged writer rejects Apache directive injection');

    proxySave(false, '127.0.0.1, ::1');
    proxyCheck(proxyRequest('http://127.0.0.1/login', ['X-Forwarded-Proto: https'])[0] === 301, 'disabling proxy mode removes the HTTPS exception');
    echo "== $passed passed\n";
} finally {
    if ($invalidConfig !== null && is_file($invalidConfig)) { unlink($invalidConfig); }
    $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'proxy_%'");
    $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($saved as $key => $value) { $stmt->execute([$key, $value]); }
    $settings = ReverseProxyService::getSettings();
    $restored = PrivHelper::run(['proxy-https', 'apply'], json_encode(['enabled' => $settings['enabled'], 'trusted_proxies' => $settings['trusted_proxies']], JSON_THROW_ON_ERROR));
    if ($userId !== null) { $db->prepare('DELETE FROM sys_users WHERE id = ?')->execute([$userId]); }
    @unlink($cookie);
    if (!$restored['success']) { throw new RuntimeException('Could not restore proxy policy'); }
}
