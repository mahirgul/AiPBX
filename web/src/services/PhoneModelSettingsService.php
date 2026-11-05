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
        return self::parse(self::all()[$model] ?? '', $model)['settings'];
    }

    /** @return array<string, array<string, string>> name => attributes given in Snom XML (perm, label …) */
    public static function attributesForModel(string $model): array
    {
        return self::parse(self::all()[$model] ?? '', $model)['attributes'];
    }

    /**
     * Snom's own format, one element per line or a whole file: <language perm="RW">Deutsch</language>.
     * idx becomes "name.idx" like the plain form, and the wrappers (settings, phone-settings,
     * functionKeys) only hold what is inside them.
     *
     * @return array{settings: array<string, string>, attributes: array<string, array<string, string>>, ok: bool}
     */
    public static function parseSnomXml(string $xml): array
    {
        $out = ['settings' => [], 'attributes' => [], 'ok' => false];
        $xml = trim((string) preg_replace('/<\?xml[^>]*\?>/i', '', $xml));
        // No DOCTYPE: nothing here needs entities, and they can read files.
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return $out;
        }
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string('<aipbx>' . $xml . '</aipbx>', 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            return $out;
        }
        $walk = static function (\SimpleXMLElement $node) use (&$walk, &$out): void {
            foreach ($node->children() as $child) {
                if ($child->count() > 0) {
                    $walk($child);
                    continue;
                }
                $name = $child->getName();
                $attrs = [];
                foreach ($child->attributes() as $key => $value) {
                    $attrs[(string) $key] = (string) $value;
                }
                if (isset($attrs['idx'])) {
                    $name .= '.' . $attrs['idx'];
                    unset($attrs['idx']);
                }
                $out['settings'][$name] = trim((string) $child);
                if ($attrs !== []) {
                    $out['attributes'][$name] = $attrs;
                }
            }
        };
        $walk($doc);
        $out['ok'] = true;
        return $out;
    }

    /**
     * Blank lines and lines starting with # are skipped. A later line wins.
     * Snom models also take lines in Snom's XML, which are read together after the rest.
     *
     * @return array{settings: array<string, string>, attributes: array<string, array<string, string>>, errors: list<string>}
     */
    public static function parse(string $text, string $model = ''): array
    {
        $settings = [];
        $errors = [];
        $xmlLines = [];
        $isSnom = (PhoneModels::get($model)['vendor'] ?? '') === 'snom';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $i => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($isSnom && $line[0] === '<') {
                $xmlLines[] = $line;
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
        $attributes = [];
        if ($xmlLines !== []) {
            $xml = self::parseSnomXml(implode("\n", $xmlLines));
            if (!$xml['ok']) {
                $errors[] = t('phones.extra_err_xml');
            }
            foreach ($xml['settings'] as $name => $value) {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}(\.[0-9]{1,4})?$/D', $name) !== 1 || strlen($value) > self::MAX_VALUE || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    $errors[] = sprintf(t('phones.extra_err_xml_item'), mb_substr($name, 0, 60));
                    continue;
                }
                $settings[$name] = $value;
            }
            $attributes = $xml['attributes'];
            if (count($settings) > self::MAX_LINES) {
                $errors[] = sprintf(t('phones.extra_err_many'), self::MAX_LINES);
            }
        }
        return ['settings' => $settings, 'attributes' => $attributes, 'errors' => $errors];
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
            $parsed = self::parse($text, $model);
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
