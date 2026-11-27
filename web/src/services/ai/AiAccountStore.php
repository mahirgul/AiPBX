<?php
/**
 * The accounts of cloud AI providers: the list (add, copy, rename, enable, delete), each account's settings and
 * secrets in sys_settings, whether it is complete enough to use, and the page's view of them.
 *
 * Keys are stored encrypted as ai_tts.<account>.<field> — the prefix of the Cloud TTS page, which used them first,
 * so existing accounts keep working unchanged. One provider (type) can have several accounts, e.g. two OpenAI keys or
 * two Ollama servers. The list is the setting ai_cloud_accounts: account id => type, name, enabled. An account stored
 * before that list existed has the type's id ("openai"); it needs no entry. A later account of the same type gets an id
 * like "openai_b". Everything that differs between providers looks at typeOf($id), never at the account id.
 */

require_once dirname(__DIR__, 2) . '/helpers.php';
require_once dirname(__DIR__, 2) . '/secret_box.php';
require_once dirname(__DIR__, 2) . '/audit_log.php';
require_once __DIR__ . '/CloudAiException.php';
require_once __DIR__ . '/AiEndpointGuard.php';
require_once __DIR__ . '/AiUsageMeter.php';
require_once __DIR__ . '/AiProviderManifest.php';
require_once __DIR__ . '/AiProviderCatalog.php';

final class AiAccountStore
{
    public const ACCOUNTS_SETTING = 'ai_cloud_accounts';
    public const MAX_ACCOUNTS = 50;

    /**
     * The stored list as it is: account id => type, name, enabled (removed: a type's own
     * account deleted on the page). Entries with an unknown type are left out.
     * @return array<string, array{type: string, name: string, enabled: bool, removed: bool}>
     */
    private static function registry(): array
    {
        $raw = function_exists('getSystemSetting') ? json_decode((string) getSystemSetting(self::ACCOUNTS_SETTING, ''), true) : null;
        $types = AiProviderCatalog::providers();
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $id => $a) {
            if (is_string($id) && preg_match('/^[a-z_]{2,20}$/', $id) && is_array($a) && isset($types[$a['type'] ?? ''])
                && (($a['type'] ?? '') === $id || !isset($types[$id]))) {
                $out[$id] = ['type' => (string) $a['type'], 'name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 60),
                             'enabled' => ($a['enabled'] ?? true) !== false, 'removed' => !empty($a['removed'])];
            }
        }
        return $out;
    }

    private static function saveRegistry(array $reg): void
    {
        setSystemSetting(self::ACCOUNTS_SETTING, (string) json_encode($reg, JSON_UNESCAPED_UNICODE));
    }

    /** An engine choice may name any listed account, or a provider's own account not yet set up. */

    /** typeOf() for a reference that may be stale: '' instead of an error. */
    public static function typeIfKnown(string $id): string
    {
        try {
            return self::typeOf($id);
        } catch (CloudAiException $e) {
            return '';
        }
    }

    /** The provider type of an account ("openai" for "openai_b"). */
    public static function typeOf(string $id): string
    {
        $reg = self::registry();
        if (isset($reg[$id])) {
            return $reg[$id]['type'];
        }
        if (isset(AiProviderCatalog::providers()[$id])) {
            return $id;
        }
        throw new CloudAiException('Unknown provider.');
    }

    /**
     * Every account the page lists: the list's entries, and each type's own account when
     * it has settings (stored before accounts existed) or works without any (local Ollama).
     * @return array<string, array{type: string, name: string, enabled: bool}>
     */
    public static function accounts(): array
    {
        $reg = self::registry();
        $out = [];
        foreach (AiProviderCatalog::providers() as $type => $p) {
            if (!isset($reg[$type]) && self::fieldsComplete($type)) {
                $out[$type] = ['type' => $type, 'name' => '', 'enabled' => true];
            }
        }
        foreach ($reg as $id => $a) {
            if (!$a['removed']) {
                $out[$id] = ['type' => $a['type'], 'name' => $a['name'], 'enabled' => $a['enabled']];
            }
        }
        return $out;
    }

    /** What an account is called on pages: its own name, else its provider's title. */
    public static function label(string $id): string
    {
        $name = self::registry()[$id]['name'] ?? '';
        return $name !== '' ? $name : (string) AiProviderCatalog::providers()[self::typeOf($id)]['title'];
    }

    /** A new account of $type; its settings are saved like any account's. Returns its id. */
    public static function addAccount(string $type, string $name, array $post): string
    {
        if (!isset(AiProviderCatalog::providers()[$type])) {
            throw new CloudAiException('Unknown provider.');
        }
        $reg = self::registry();
        if (count(self::accounts()) >= self::MAX_ACCOUNTS) {
            throw new CloudAiException(sprintf(t('ai_cloud.err_accounts_many'), self::MAX_ACCOUNTS));
        }
        // The type's own id while it is free: a single account keeps the id engine choices know.
        $id = $type;
        if (isset(self::accounts()[$type])) {
            $id = '';
            foreach (range('b', 'z') as $c) {
                $try = substr($type, 0, 18) . '_' . $c;
                if (!isset($reg[$try]) && !isset(AiProviderCatalog::providers()[$try])) {
                    $id = $try;
                    break;
                }
            }
            if ($id === '') {
                throw new CloudAiException(sprintf(t('ai_cloud.err_accounts_many'), self::MAX_ACCOUNTS));
            }
        }
        $reg[$id] = ['type' => $type, 'name' => self::cleanName($name), 'enabled' => true];
        self::saveRegistry($reg);
        try {
            self::save($id, $post);
        } catch (\Throwable $e) {
            unset($reg[$id]);
            if ($id === $type) {
                self::deleteSettings($id);
            }
            self::saveRegistry($reg);
            throw $e;
        }
        return $id;
    }

    /** A new account with the same provider, key and settings under another name ("Copy account": a second model of one provider). */
    public static function copyAccount(string $id): string
    {
        $cfg = self::config($id);
        $post = [];
        foreach (self::provider($id)['fields'] as $f) {
            $v = (string) ($cfg[$f['key']] ?? '');
            // An unset field answers its default: do not store that as a choice.
            if ($v !== '' && ($f['secret'] || $v !== (string) ($f['default'] ?? ''))) {
                $post[$f['key']] = $v;
            }
        }
        return self::addAccount(self::typeOf($id), mb_substr(self::label($id), 0, 52) . ' 2', $post);
    }

    public static function renameAccount(string $id, string $name): void
    {
        $type = self::typeOf($id);
        $reg = self::registry();
        $reg[$id] = ['type' => $type, 'name' => self::cleanName($name), 'enabled' => $reg[$id]['enabled'] ?? true];
        self::saveRegistry($reg);
    }

    /** A switched-off account keeps its settings but no job uses it. */
    public static function setEnabled(string $id, bool $enabled): void
    {
        $type = self::typeOf($id);
        $reg = self::registry();
        $reg[$id] = ['type' => $type, 'name' => $reg[$id]['name'] ?? '', 'enabled' => $enabled];
        self::saveRegistry($reg);
        writeAuditLog('ai_cloud', 'provider', $id, self::label($id) . ($enabled ? ' (enabled)' : ' (disabled)'), 'update', $_SESSION['user_id'] ?? null);
    }

    /** Removes an account with its settings, limits and model list. */
    public static function deleteAccount(string $id): void
    {
        $type = self::typeOf($id);
        $label = self::label($id);
        self::deleteSettings($id);
        $reg = self::registry();
        unset($reg[$id]);
        self::saveRegistry($reg);
        if ($id === $type && self::fieldsComplete($type)) {
            // Works on its defaults alone (local Ollama): remembered as removed, or it would come back.
            $reg[$id] = ['type' => $type, 'name' => '', 'enabled' => false, 'removed' => true];
        }
        self::saveRegistry($reg);
        writeAuditLog('ai_cloud', 'provider', $id, $label . ' (account removed)', 'delete', $_SESSION['user_id'] ?? null);
    }

    private static function deleteSettings(string $id): void
    {
        $db = getDB();
        foreach (["ai_tts.{$id}.%", "ai_limit.{$id}.%"] as $like) {
            $db->prepare('DELETE FROM sys_settings WHERE setting_key LIKE ?')->execute([str_replace('_', '\_', $like)]);
        }
        $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute(['ai_cloud_cache.' . $id]);
    }

    private static function cleanName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name)), 0, 60);
    }

    /**
     * The provider of an account: title, capabilities, fields, sign-up page ('title' is
     * the account's label, 'type' its provider).
     */
    public static function provider(string $id): array
    {
        $type = self::typeOf($id);
        $p = AiProviderCatalog::providers()[$type];
        return ['type' => $type, 'provider_title' => $p['title'], 'title' => self::label($id)] + $p;
    }

    /** Decrypted settings of a provider (server side only). */
    public static function config(string $id): array
    {
        $cfg = [];
        foreach (self::provider($id)['fields'] as $f) {
            $raw = (string) getSystemSetting("ai_tts.{$id}.{$f['key']}", '');
            $cfg[$f['key']] = $f['secret'] ? SecretBox::decrypt($raw) : ($raw !== '' ? $raw : ($f['default'] ?? ''));
        }
        return $cfg;
    }

    public static function isLoopbackOllama(string $id): bool
    {
        if (self::typeIfKnown($id) !== 'ollama') {
            return false;
        }
        $host = strtolower((string) parse_url((string) (self::config($id)['base_url'] ?? ''), PHP_URL_HOST));
        return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }

    public static function configured(string $id, array $post = []): bool
    {
        if (function_exists('getSystemSetting') && getSystemSetting('privacy_block_cloud_ai', '0') === '1' && !self::isLoopbackOllama($id)) {
            return false;
        }
        $a = self::registry()[$id] ?? null;
        if ($a !== null && ($a['removed'] || !$a['enabled'])) {
            return false;
        }
        return self::fieldsComplete($id, $post);
    }

    /** Completeness check for interactive Test / Discover (allows disabled accounts and fresh 2nd accounts). */
    public static function configuredForTest(string $id, array $post = [], bool $isNew = false): bool
    {
        if (function_exists('getSystemSetting') && getSystemSetting('privacy_block_cloud_ai', '0') === '1' && !self::isLoopbackOllama($id)) {
            return false;
        }
        return self::fieldsComplete($id, $post, $isNew);
    }

    /** Whether the account's required fields are filled (stored, or typed in $post). */
    private static function fieldsComplete(string $id, array $post = [], bool $isNew = false): bool
    {
        $fields = self::provider($id)['fields'];
        if ($isNew) {
            $cfg = [];
            foreach ($fields as $f) {
                if (empty($f['secret']) && array_key_exists('default', $f)) {
                    $cfg[$f['key']] = (string) $f['default'];
                }
            }
        } else {
            $cfg = self::config($id);
        }
        foreach ($fields as $f) {
            $k = $f['key'];
            if (isset($post[$k]) && trim((string) $post[$k]) !== '') {
                $cfg[$k] = trim((string) $post[$k]);
            }
        }
        // Google Cloud: either the service account or the API key.
        if (self::typeOf($id) === 'google') {
            return trim($cfg['service_account'] ?? '') !== '' || trim($cfg['api_key'] ?? '') !== '';
        }
        foreach ($fields as $f) {
            if (empty($f['optional']) && trim($cfg[$f['key']] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> configured, switched-on accounts that can do $cap */
    public static function withCap(string $cap): array
    {
        return array_values(array_filter(array_keys(self::accounts()),
            fn($id) => in_array($cap, self::provider($id)['caps'], true) && self::configured($id)));
    }

    /** The page's view of every provider: secrets only masked. */

    /**
     * The page's view of every account (the table): secrets only masked.
     * 'configured' is false for a switched-off account; 'complete' says whether its fields are filled.
     * @return list<array<string, mixed>>
     */
    public static function forPage(): array
    {
        $usage = AiUsageMeter::usageThisMonth();
        $out = [];
        foreach (self::accounts() as $id => $a) {
            $p = self::provider($id);
            $cfg = self::config($id);
            $fields = [];
            foreach ($p['fields'] as $f) {
                $v = $cfg[$f['key']] ?? '';
                $masked = !empty($f['json']) ? (string) (json_decode($v, true)['client_email'] ?? '') : SecretBox::mask($v);
                $fields[] = $f + ['value' => $f['secret'] ? '' : $v, 'masked' => $f['secret'] ? $masked : ''];
            }
            $out[] = ['id' => $id, 'type' => $a['type'], 'name' => $a['name'], 'title' => $p['title'], 'provider_title' => $p['provider_title'],
                      'caps' => $p['caps'], 'url' => $p['url'], 'free' => $p['free'], 'enabled' => $a['enabled'],
                      'configured' => self::configured($id), 'complete' => self::fieldsComplete($id),
                      'fields' => $fields, 'usage' => $usage[$id] ?? null,
                      'limits' => AiUsageMeter::limits($id), 'quota' => AiUsageMeter::usageStatus($id, $usage[$id] ?? null)];
        }
        usort($out, fn($a, $b) => [$a['provider_title'], $a['title']] <=> [$b['provider_title'], $b['title']]);
        return $out;
    }

    public static function save(string $id, array $post): void
    {
        $p = self::provider($id);
        $db = getDB();
        $values = [];
        $clear = !empty($post['clear']);
        foreach ($p['fields'] as $f) {
            $field = $f['key'];
            if (!$clear && str_starts_with($field, 'realtime_') && !array_key_exists($field, $post)) {
                continue;
            }
            $v = $clear ? '' : trim((string) ($post[$field] ?? ''));
            if ($v !== '' && !empty($f['json']) && $p['type'] === 'google') {
                $v = GoogleTts::validateServiceAccount($v);
            }
            if ($v !== '' && $field === 'base_url') {
                $v = AiEndpointGuard::validBaseUrl($v, AiProviderManifest::privateOk($p['type'])) ?? throw new CloudAiException(t('ai_cloud.err_base_url'));
            }
            if ($v !== '' && in_array($field, ['model', 'realtime_model', 'llm_model', 'stt_model'], true) && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,99}$#D', $v)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            if ($v !== '' && $field === 'llm_temperature' && (!is_numeric($v) || $v < 0 || $v > 2)) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            $ranges = ['realtime_vad_threshold' => [0.1, 0.99], 'realtime_silence_ms' => [200, 3000], 'realtime_temperature' => [0.2, 1.2]];
            if ($v !== '' && isset($ranges[$field]) && (!is_numeric($v) || $v < $ranges[$field][0] || $v > $ranges[$field][1])) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            if ($v !== '' && $field === 'realtime_voice' && !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v)) {
                throw new CloudAiException(t('ai_cloud.err_model'));
            }
            if ($v !== '' && $field === 'realtime_noise_reduction' && !array_key_exists($v, $f['options'])) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            if ($v !== '' && $field === 'llm_max_tokens' && (!ctype_digit($v) || (int) $v < 16 || (int) $v > 4096)) {
                throw new CloudAiException(t('ai_cloud.err_number'));
            }
            if ($v !== '' && $field === 'realtime_protocol' && !array_key_exists($v, $f['options'])) {
                throw new CloudAiException(t('ai_cloud.err_realtime_protocol'));
            }
            if ($v !== '' && $field === 'realtime_ws_url' && (strlen($v) > 200
                || !preg_match('~^wss?://[^\s/?#@]+(/[^\s?#]*)?$~D', $v)
                || filter_var($v, FILTER_VALIDATE_URL) === false)) {
                throw new CloudAiException(t('ai_cloud.err_realtime_ws_url'));
            }
            $values[$field] = $v;
        }
        $set = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $del = $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?');
        foreach ($p['fields'] as $f) {
            if (!array_key_exists($f['key'], $values)) {
                continue;
            }
            $key = "ai_tts.{$id}.{$f['key']}";
            if ($clear) {
                $del->execute([$key]);
                continue;
            }
            $v = $values[$f['key']];
            if ($f['secret']) {
                if ($v !== '') {
                    $set->execute([$key, SecretBox::encrypt($v)]);
                }
            } elseif ($v === '') {
                $del->execute([$key]);
            } else {
                $set->execute([$key, mb_substr($v, 0, 200)]);
            }
        }
        AiUsageMeter::saveLimits($id, $post);
        writeAuditLog('ai_cloud', 'provider', $id, $p['title'] . (!empty($post['clear']) ? ' (credentials removed)' : ' (settings saved)'), 'update', $_SESSION['user_id'] ?? null);
    }

    /**
     * What the AI service needs to ask a provider's language model:
     * provider, key and, where the account has them, model and address.
     */
    public static function llmSettings(string $id): array
    {
        $cfg = self::config($id);
        $type = self::typeOf($id);
        // The AI service picks the endpoint by provider: it gets the type, not the account.
        $out = ['provider' => $type, 'api_key' => (string) ($cfg['api_key'] ?? '')];
        // OpenAI's and ElevenLabs' "Model" field names their speech model (Cloud TTS): their language model is llm_model.
        $model = trim((string) ($cfg['llm_model'] ?? ''));
        if (in_array($type, AiProviderCatalog::LLM_FIELDS_OWN, true)) {
            $model = $model ?: trim((string) ($cfg['model'] ?? ''));
            if (trim((string) ($cfg['base_url'] ?? '')) !== '') {
                $out['base_url'] = trim((string) $cfg['base_url']);
            }
        }
        if ($model !== '') {
            $out['model'] = $model;
        }
        foreach (['llm_temperature' => 'temperature', 'llm_max_tokens' => 'max_tokens'] as $key => $name) {
            if (is_numeric($cfg[$key] ?? null)) {
                $out[$name] = $name === 'temperature' ? (float) $cfg[$key] : (int) $cfg[$key];
            }
        }
        return $out;
    }
}
