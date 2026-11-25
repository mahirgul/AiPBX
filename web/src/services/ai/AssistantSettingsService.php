<?php
/**
 * Settings for the PBX Assistant (`sys_settings` keys prefixed with `assistant.*`).
 *
 * Controls:
 *  - `assistant.model`:        '' (by language), 'rules_only', 'cloud:<provider>', or 'local:<id>'
 *  - `assistant.roles`:        JSON map role_key => {ask: bool, change: bool} (default: admin only)
 *  - `assistant.home_roles`:   JSON map role_key => 'assistant' | 'default' (default: admin => assistant)
 *  - `assistant.history_days`: int days to keep per-user chat history (default 10, 0 = off)
 *  - `assistant.suggestions`:  JSON list of 3-5 prompt examples (empty => built-in defaults)
 */
class AssistantSettingsService
{
    public const DEFAULT_HISTORY_DAYS = 10;
    public const MAX_HISTORY_DAYS = 365;

    public static function defaultSuggestions(): array
    {
        return [
            'How many calls today?',
            'Why is 102 not ringing?',
            'Forward 101 to 203',
            'Where are queue settings?',
        ];
    }

    public static function isCloudBlocked(): bool
    {
        return function_exists('getSystemSetting')
            && getSystemSetting('privacy_block_cloud_ai', '0') === '1';
    }

    public static function model(): string
    {
        if (!function_exists('getSystemSetting')) {
            return '';
        }
        return trim((string) getSystemSetting('assistant.model', ''));
    }

    /**
     * Faz 3: Returns the Assistant chat binding from `ai_bindings`,
     * falling back to legacy `sys_settings['assistant.model']` if no row exists yet.
     *
     * @return array{mode: string, model_ref_id: ?string, from_binding?: bool}
     */
    public static function binding(): array
    {
        try {
            $st = getDB()->prepare(
                "SELECT mode, model_ref_id FROM ai_bindings WHERE feature = 'assistant' AND scope_ref = 'global' AND task = 'chat' AND language = 'default' LIMIT 1"
            );
            $st->execute();
            $row = $st->fetch();
            if ($row) {
                return [
                    'mode' => (string) $row['mode'],
                    'model_ref_id' => $row['model_ref_id'] !== null ? (string) $row['model_ref_id'] : null,
                    'from_binding' => true,
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet in isolated unit tests.
        }

        $legacy = self::model();
        if ($legacy === 'rules_only') {
            return ['mode' => 'off', 'model_ref_id' => null, 'from_binding' => false];
        }
        if (str_starts_with($legacy, 'cloud:') || str_starts_with($legacy, 'local:')) {
            return ['mode' => 'explicit', 'model_ref_id' => $legacy, 'from_binding' => false];
        }
        // Nothing chosen (a new installation): rules only, no language model until one is chosen.
        return ['mode' => 'off', 'model_ref_id' => null, 'from_binding' => false];
    }

    public static function isCloudModel(string $model): bool
    {
        return str_starts_with($model, 'cloud:');
    }

    public static function isValidModel(string $model): bool
    {
        if ($model === '' || $model === 'rules_only') {
            return true;
        }
        if (preg_match('/^cloud:[a-z_]{2,20}$/', $model)) {
            return true;
        }
        return (bool) preg_match('/^local:[a-z0-9][a-z0-9._-]{1,63}$/', $model);
    }

    /**
     * @return array<string, array{ask: bool, change: bool}>
     */
    public static function rolesConfig(): array
    {
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting('assistant.roles', '') : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $role => $perm) {
                if (!is_string($role) || $role === '' || !is_array($perm)) {
                    continue;
                }
                $change = !empty($perm['change']);
                $ask = $change || !empty($perm['ask']);
                $out[$role] = ['ask' => $ask, 'change' => $change];
            }
        }
        $out['admin'] = ['ask' => true, 'change' => true];
        return $out;
    }

    public static function canAsk(string $role): bool
    {
        if ($role === 'admin') {
            return true;
        }
        if ($role === '') {
            return false;
        }
        $cfg = self::rolesConfig();
        return !empty($cfg[$role]['ask']);
    }

    public static function canChange(string $role): bool
    {
        if ($role === 'read_only_admin') {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        if ($role === '') {
            return false;
        }
        $cfg = self::rolesConfig();
        return !empty($cfg[$role]['ask']) && !empty($cfg[$role]['change']);
    }

    /**
     * Per-role knowledge collection assignment for Assistant answers:
     * 0 = language default, -1 = disabled, > 0 = specific collection id.
     *
     * @return array<string, int>
     */
    public static function roleCollectionsConfig(): array
    {
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting('assistant.role_kb', '') : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $role => $cid) {
                if (is_string($role) && $role !== '') {
                    $out[$role] = (int) $cid;
                }
            }
        }
        return $out;
    }

    public static function roleCollection(string $role): int
    {
        return (int) (self::roleCollectionsConfig()[$role] ?? 0);
    }

    public static function defaultHomePath(string $role): string
    {
        return match ($role) {
            'admin' => '/dashboard',
            'cc_agent' => '/cc-agent',
            'cc_manager' => '/cc-supervisor',
            'fax_user' => '/fax-inbox',
            default => '/my-phone',
        };
    }

    /**
     * @return array<string, string> role_key => 'assistant'|'default'
     */
    public static function homeRolesConfig(): array
    {
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting('assistant.home_roles', '') : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $role => $target) {
                if (!is_string($role) || $role === '') {
                    continue;
                }
                $out[$role] = ($target === 'assistant' || $target === '/assistant') ? 'assistant' : 'default';
            }
        }
        if (!isset($out['admin'])) {
            $out['admin'] = 'assistant';
        }
        return $out;
    }

    public static function isPluginEnabled(): bool
    {
        $pm = dirname(__DIR__, 2) . '/core/PluginManager.php';
        if (!is_file($pm)) {
            return false;
        }
        require_once $pm;
        return PluginManager::isEnabled('pbx-assistant');
    }

    /**
     * Landing path for $role, falling back to the role's default home page
     * if pbx-assistant is disabled, or if $role is not allowed to ask.
     */
    public static function homePath(string $role): string
    {
        if (!self::isPluginEnabled()) {
            return self::defaultHomePath($role);
        }
        $homeRoles = self::homeRolesConfig();
        $choice = $homeRoles[$role] ?? ($role === 'admin' ? 'assistant' : 'default');
        if ($choice === 'assistant' && self::canAsk($role)) {
            return '/assistant';
        }
        return self::defaultHomePath($role);
    }

    public static function historyDays(): int
    {
        if (!function_exists('getSystemSetting')) {
            return self::DEFAULT_HISTORY_DAYS;
        }
        $raw = trim((string) getSystemSetting('assistant.history_days', (string) self::DEFAULT_HISTORY_DAYS));
        if ($raw === '' || !is_numeric($raw)) {
            return self::DEFAULT_HISTORY_DAYS;
        }
        $days = (int) $raw;
        if ($days < 0) {
            return 0;
        }
        return min(self::MAX_HISTORY_DAYS, $days);
    }

    /**
     * Custom suggestions configured by admin, or empty array if none.
     *
     * @return list<string>
     */
    public static function customSuggestions(): array
    {
        $raw = function_exists('getSystemSetting') ? (string) getSystemSetting('assistant.suggestions', '') : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            $s = trim((string) $item);
            if ($s !== '') {
                $out[] = $s;
            }
            if (count($out) >= 5) {
                break;
            }
        }
        return $out;
    }

    /**
     * Effective suggestions for the welcome screen (custom if set, otherwise defaults).
     *
     * @return list<string>
     */
    public static function suggestions(): array
    {
        $custom = self::customSuggestions();
        return $custom !== [] ? $custom : self::defaultSuggestions();
    }

    /**
     * All settings for the AI Settings -> PBX Assistant tab.
     */
    public static function all(): array
    {
        $model = self::model();
        $binding = self::binding();
        $effectiveRef = (string) ($binding['model_ref_id'] ?? $model);
        return [
            'model'                  => $model,
            'binding'                => $binding,
            'roles'                  => self::rolesConfig(),
            'home_roles'             => self::homeRolesConfig(),
            'role_kb'                => self::roleCollectionsConfig(),
            'history_days'           => self::historyDays(),
            'suggestions'            => self::suggestions(),
            'custom_suggestions'     => self::customSuggestions(),
            'privacy_block_cloud_ai' => self::isCloudBlocked(),
            'is_cloud_model'         => self::isCloudModel($effectiveRef),
        ];
    }

    /**
     * Persist settings from the Assistant Settings form.
     *
     * @param list<string>|null $roleKeys Known role keys (fetched from sys_roles when null).
     */
    public static function save(array $post, ?array $roleKeys = null): void
    {
        require_once __DIR__ . '/AiBindingService.php';
        $mode = trim((string) ($post['assistant_mode'] ?? ''));
        $ref = trim((string) ($post['assistant_model_ref'] ?? ''));

        if ($mode !== '' && in_array($mode, AiBindingService::MODES, true)) {
            if ($mode === 'explicit') {
                if ($ref === '' || !self::isValidModel($ref) || $ref === 'rules_only') {
                    throw new \InvalidArgumentException(function_exists('t') ? t('ai_cloud.err_engine') : 'Invalid assistant model.');
                }
                $model = $ref;
            } elseif ($mode === 'off') {
                $ref = null;
                $model = 'rules_only';
            } else {
                $ref = null;
                $model = '';
            }
        } else {
            $model = trim((string) ($post['assistant_model'] ?? ''));
            if (!self::isValidModel($model)) {
                throw new \InvalidArgumentException(function_exists('t') ? t('ai_cloud.err_engine') : 'Invalid assistant model.');
            }
            if ($model === 'rules_only') {
                $mode = 'off';
                $ref = null;
            } elseif (str_starts_with($model, 'cloud:') || str_starts_with($model, 'local:')) {
                $mode = 'explicit';
                $ref = $model;
            } else {
                $mode = 'local_default';
                $ref = null;
            }
        }

        if ($roleKeys === null) {
            $roleKeys = self::knownRoleKeys();
        }

        $roleAsk = is_array($post['role_ask'] ?? null) ? $post['role_ask'] : [];
        $roleChange = is_array($post['role_change'] ?? null) ? $post['role_change'] : [];
        $roles = [];
        foreach ($roleKeys as $rk) {
            if ($rk === '') {
                continue;
            }
            if ($rk === 'admin') {
                $roles[$rk] = ['ask' => true, 'change' => true];
                continue;
            }
            $change = !empty($roleChange[$rk]);
            $ask = $change || !empty($roleAsk[$rk]);
            $roles[$rk] = ['ask' => $ask, 'change' => $change];
        }
        $roles['admin'] = ['ask' => true, 'change' => true];

        $homeInput = is_array($post['home_role'] ?? null) ? $post['home_role'] : [];
        $homeRoles = [];
        foreach ($roleKeys as $rk) {
            if ($rk === '') {
                continue;
            }
            $val = (string) ($homeInput[$rk] ?? ($rk === 'admin' ? 'assistant' : 'default'));
            $homeRoles[$rk] = $val === 'assistant' ? 'assistant' : 'default';
        }

        $roleKbInput = is_array($post['role_kb'] ?? null) ? $post['role_kb'] : [];
        $roleKb = [];
        foreach ($roleKeys as $rk) {
            if ($rk === '') {
                continue;
            }
            $cid = (int) ($roleKbInput[$rk] ?? 0);
            $roleKb[$rk] = $cid < 0 ? -1 : $cid;
        }

        $daysRaw = $post['history_days'] ?? self::DEFAULT_HISTORY_DAYS;
        $days = is_numeric($daysRaw) ? (int) $daysRaw : self::DEFAULT_HISTORY_DAYS;
        $days = max(0, min(self::MAX_HISTORY_DAYS, $days));

        $rawSuggestions = (string) ($post['suggestions'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $rawSuggestions) ?: [];
        $suggestions = [];
        foreach ($lines as $line) {
            $s = trim($line);
            if ($s !== '') {
                $suggestions[] = mb_substr($s, 0, 200);
            }
            if (count($suggestions) >= 5) {
                break;
            }
        }

        setSystemSetting('assistant.model', $model);
        try {
            AiBindingService::saveBinding('assistant', 'chat', $mode, $ref, 'default', 'global');
        } catch (\Throwable $e) {
            // Non-fatal if ai_bindings table is absent in isolated unit tests.
        }
        setSystemSetting('assistant.roles', (string) json_encode($roles, JSON_UNESCAPED_UNICODE));
        setSystemSetting('assistant.home_roles', (string) json_encode($homeRoles, JSON_UNESCAPED_UNICODE));
        setSystemSetting('assistant.role_kb', (string) json_encode($roleKb, JSON_UNESCAPED_UNICODE));
        setSystemSetting('assistant.history_days', (string) $days);
        require_once __DIR__ . '/AssistantChatService.php';
        AssistantChatService::pruneOldChats($days);
        setSystemSetting('assistant.suggestions', (string) json_encode($suggestions, JSON_UNESCAPED_UNICODE));

        if (function_exists('writeAuditLog')) {
            writeAuditLog('ai_settings', 'sys_settings', 'assistant', 'PBX Assistant settings', 'update', $_SESSION['user_id'] ?? null);
        }
    }

    /** @return list<string> */
    private static function knownRoleKeys(): array
    {
        try {
            $rows = getDB()->query('SELECT role_key FROM sys_roles ORDER BY is_system DESC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($rows) && $rows !== []) {
                return array_values(array_map('strval', $rows));
            }
        } catch (\Throwable $e) {
            // Fall through to built-in roles.
        }
        return ['admin', 'user', 'cc_agent', 'cc_manager', 'fax_user', 'read_only_admin'];
    }
}
