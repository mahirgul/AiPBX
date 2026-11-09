<?php
/** Run only inside a disposable installed test system: AIPBX_E2E=1 php chat_security.php. */
if (getenv('AIPBX_E2E') !== '1') {
    fwrite(STDERR, "Set AIPBX_E2E=1 only on a disposable test installation.\n");
    exit(2);
}
require (getenv('INSTALL_DIR') ?: '/opt/aipbx') . '/web/api/mobile/auth_helper.php';
$db = getDB();
$ids = [];
$conversation = null;
$tmp = null;
$passed = 0;
function requestCheck(string $path, ?string $token, mixed $body, int $expected): void {
    global $passed;
    $ch = curl_init('http://127.0.0.1:8086' . $path);
    $headers = $token === null ? [] : ['Authorization: Bearer ' . $token];
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_string($body)) { $headers[] = 'Content-Type: application/json'; }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status !== $expected) {
        throw new RuntimeException("$path: expected $expected, got $status");
    }
    echo "PASS $path -> $status\n";
    $passed++;
}
try {
    $prefix = 'e2e_chat_' . bin2hex(random_bytes(5));
    $exts = [];
    for ($i = 0; $i < 2; $i++) {
        $ext = (string)random_int(90000000, 99999999);
        $stmt = $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, extension, extension_type, is_active) VALUES (?, '', 'E2E chat test', 'user', ?, 'sip', 1)");
        $stmt->execute([$prefix . '_' . $i, $ext]);
        $ids[] = (int)$db->lastInsertId();
        $exts[] = $ext;
    }
    $tokens = array_map(fn($id) => generateMobileToken($id, 300), $ids);
    $db->prepare("INSERT INTO chat_conversations (type, created_by, created_at, updated_at) VALUES ('group', ?, NOW(), NOW())")->execute([$exts[0]]);
    $conversation = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO chat_participants (conversation_id, extension, joined_at) VALUES (?, ?, NOW())')->execute([$conversation, $exts[0]]);
    requestCheck('/api/messages?conversation_id=' . $conversation, $tokens[0], null, 200);
    requestCheck('/api/messages?conversation_id=' . $conversation, $tokens[1], null, 403);
    requestCheck('/api/messages', $tokens[1], json_encode(['conversation_id' => $conversation, 'message' => 'forbidden']), 403);
    requestCheck('/media/images/nonexistent.jpg', null, null, 401);
    requestCheck('/api/messages', $tokens[0], json_encode(['conversation_id' => $conversation, 'message' => 'test', 'attachment_url' => 'https://attacker.invalid/a.jpg']), 400);
    $tmp = tempnam(sys_get_temp_dir(), 'e2e-upload-');
    file_put_contents($tmp, '<script>alert(1)</script>');
    requestCheck('/api/upload', $tokens[0], ['file' => new CURLFile($tmp, 'text/html', 'attack.html')], 400);
    echo "== $passed passed\n";
} finally {
    if ($tmp !== null) { @unlink($tmp); }
    if ($conversation !== null) {
        $db->prepare('DELETE FROM chat_messages WHERE conversation_id = ?')->execute([$conversation]);
        $db->prepare('DELETE FROM chat_participants WHERE conversation_id = ?')->execute([$conversation]);
        $db->prepare('DELETE FROM chat_conversations WHERE id = ?')->execute([$conversation]);
    }
    foreach ($ids as $id) { $db->prepare('DELETE FROM sys_users WHERE id = ?')->execute([$id]); }
}
