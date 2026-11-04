<?php
require_once __DIR__ . '/phones/PhoneModels.php';
require_once __DIR__ . '/phones/PhoneTemplates.php';

/**
 * Extra settings per phone model: "name = value" lines an administrator adds to what
 * AiPBX writes for that model. They live in the database, so an update keeps them, and
 * a setting the template also writes takes the value given here.
 */
class PhoneModelSettingsService
{
    private const MAX_LINES = 200;
    private const MAX_VALUE = 500;

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @return array<string, string> model => text */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (getDB()->query('SELECT model, extra_settings FROM pbx_phone_model_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                self::$cache[(string) $r['model']] = (string) $r['extra_settings'];
            }
        }
        return self::$cache;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string, string> name => value for the templates */
    public static function forModel(string $model): array
    {
        return self::parse(self::all()[$model] ?? '')['settings'];
    }

    /**
     * Blank lines and lines starting with # are skipped. A later line wins.
     * @return array{settings: array<string, string>, errors: list<string>}
     */
    public static function parse(string $text): array
    {
        $settings = [];
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($i >= self::MAX_LINES) {
                $errors[] = sprintf(t('phones.extra_err_many'), self::MAX_LINES);
                break;
            }
            $at = strpos($line, '=');
            $name = $at === false ? '' : trim(substr($line, 0, $at));
            $value = $at === false ? '' : trim(substr($line, $at + 1));
            if ($at === false || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,99}$/D', $name) !== 1) {
                $errors[] = sprintf(t('phones.extra_err_line'), $i + 1);
                continue;
            }
            if (strlen($value) > self::MAX_VALUE || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                $errors[] = sprintf(t('phones.extra_err_line'), $i + 1);
                continue;
            }
            $settings[$name] = $value;
        }
        return ['settings' => $settings, 'errors' => $errors];
    }

    /** @return array<string, string> model key => label, of the models that can use this */
    public static function supportedModels(): array
    {
        $out = [];
        foreach (PhoneModels::MODELS as $key => $m) {
            $tpl = PhoneTemplates::forModel($key);
            if ($tpl !== null && $tpl->supportsExtraSettings()) {
                $out[$key] = (string) $m['label'];
            }
        }
        return $out;
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $model = (string) ($data['model'] ?? '');
            if (!isset(self::supportedModels()[$model])) {
                throw new \Exception(t('phones.extra_err_model'));
            }
            $text = trim(str_replace("\r\n", "\n", (string) ($data['extra_settings'] ?? '')));
            $parsed = self::parse($text);
            if ($parsed['errors'] !== []) {
                throw new \Exception(implode(' ', $parsed['errors']));
            }
            $db = getDB();
            if ($text === '') {
                $db->prepare('DELETE FROM pbx_phone_model_settings WHERE model = ?')->execute([$model]);
            } else {
                $db->prepare('INSERT INTO pbx_phone_model_settings (model, extra_settings) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE extra_settings = VALUES(extra_settings)')->execute([$model, $text]);
            }
            self::resetCache();
            writeAuditLog('phones', 'phone_model', $model, "Extra settings of {$model}: " . count($parsed['settings']), 'update', $_SESSION['user_id'] ?? null);
            PhoneProvisionService::exportTftpQuietly();
            return t('phones.extra_saved');
        });
    }
}
