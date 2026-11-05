<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/asterisk_helper.php';
require_once dirname(__DIR__) . '/asterisk_sync.php';

/**
 * Administrator's own PJSIP options on the sections AiPBX generates (/advanced-config). They are
 * added with `[name](+type=...)` by SyncAdvancedPjsip.php.
 *
 * Option names are checked against Asterisk's own documentation before saving, because a mistake
 * does not show when it happens: `pjsip reload` reports success, and at the next restart the whole
 * object is gone. Measured on Asterisk 22 with `bogus_option=1` on an endpoint.
 */
final class AdvancedPjsipService
{
    /** Types that can be extended => the module whose help lists their options. */
    public const OBJECT_TYPES = [
        'transport' => 'res_pjsip',
        'endpoint' => 'res_pjsip',
        'aor' => 'res_pjsip',
        'auth' => 'res_pjsip',
        'identify' => 'res_pjsip_endpoint_identifier_ip',
        'registration' => 'res_pjsip_outbound_registration',
    ];

    /** File => the command that makes Asterisk read it again. All are measured to work on a reload. */
    public const CONFIG_FILES = [
        'pjsip' => 'pjsip reload',
        'rtp' => 'module reload res_rtp_asterisk.so',
        'features' => 'module reload features',
        'queues' => 'queue reload all',
        'voicemail' => 'voicemail reload',
        'musiconhold' => 'moh reload',
        'cdr' => 'module reload cdr',
        'logger' => 'logger reload',
        'manager' => 'manager reload',
        'udptl' => 'module reload udptl',
        'indications' => 'module reload indications',
        'confbridge' => 'module reload app_confbridge',
    ];

    /** PJSIP groups an entry can name instead of one object. */
    public const TARGETS = ['object', 'extensions', 'trunks', 'model', 'new', 'raw'];

    /**
     * Files where Asterisk reads an option with the first value it finds, so a later line cannot
     * replace one the file already has. Measured on rtp (dtmftimeout, rtpchecksums on Asterisk 22);
     * voicemail and logger read their [general] the same way.
     */
    public const FIRST_VALUE_WINS = ['rtp', 'voicemail', 'logger'];

    /** Options that add to a list rather than replace a value. */
    private const LIST_OPTIONS = ['allow', 'disallow', 'match', 'contact', 'member', 'permit', 'deny', 'set_var'];

    private const SECRET_OPTIONS = ['password', 'secret', 'md5_cred', 'oauth_secret', 'refresh_token'];

    /**
     * Recipes only fill the form; the administrator still reads and saves it. allow= adds to what
     * an endpoint has (measured), so an order needs disallow=all first.
     */
    public const RECIPES = [
        'nat' => ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'options' => "force_rport=yes\nrewrite_contact=yes\nrtp_symmetric=yes"],
        'tos' => ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'options' => "tos_audio=ef\ntos_video=af41\ncos_audio=5\ncos_video=4"],
        'send_pai' => ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'options' => 'send_pai=yes'],
        'codecs' => ['config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'options' => "disallow=all\nallow=opus,g722,alaw,ulaw"],
        'voicemail_tz' => ['config_file' => 'voicemail', 'target' => 'object', 'object_name' => 'general', 'options' => 'tz=european'],
        'trunk' => ['config_file' => 'pjsip', 'target' => 'raw', 'options' => "; Replace USER, PASSWORD and HOST. CONTEXT is your own context from Custom Dialplan: AiPBX makes none for this trunk.\n[mytrunk-auth]\ntype=auth\nauth_type=userpass\nusername=USER\npassword=PASSWORD\n\n[mytrunk]\ntype=aor\ncontact=sip:HOST:5060\nqualify_frequency=60\n\n[mytrunk]\ntype=endpoint\ntransport=transport-udp\ncontext=CONTEXT\ndisallow=all\nallow=alaw,ulaw\naors=mytrunk\noutbound_auth=mytrunk-auth\n\n[mytrunk]\ntype=identify\nendpoint=mytrunk\nmatch=HOST\n"],
    ];

    private const IMPORT_MAX_BYTES = 1048576;

    /** Where Asterisk's help lists a section's options: file => section => [module, type]. */
    private const SECTION_HELP = [
        'features' => ['general' => ['features', 'globals'], 'featuremap' => ['features', 'featuremap']],
        'udptl' => ['general' => ['udptl', 'global']],
        'cdr' => ['general' => ['cdr', 'general']],
    ];

    /**
     * Options refused here, in lower case: those that run a program or write outside Asterisk's
     * logs, open AMI to others, or that AiPBX's own pages and firewall depend on. Rollback reads
     * messages.log, so that channel stays too.
     */
    private const DENIED_OPTIONS = [
        'rtp' => ['rtpstart', 'rtpend'],
        'udptl' => ['udptlstart', 'udptlend'],
        'voicemail' => ['mailcmd', 'externnotify', 'externpass', 'externpassnotify', 'externpasscheck', 'smdienable', 'smdiport'],
        'musiconhold' => ['mode', 'application'],
        'logger' => ['exec_after_rotate', 'messages.log'],
        'manager' => ['enabled', 'webenabled', 'port', 'bindaddr', 'tlsenable', 'tlsbindaddr', 'tlscertfile', 'tlsprivatekey'],
    ];

    /** Sections refused in a file: the applicationmap runs dialplan applications from DTMF. */
    private const DENIED_SECTIONS = ['features' => ['applicationmap', 'featuregroup']];

    private const KEEP_VERSIONS = 50;

    /**
     * Options of a type in Asterisk's help text.
     *
     * @return list<string>
     */
    public static function parseHelpOptions(string $help): array
    {
        $help = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $help);
        $at = strpos($help, '[Config Options]');
        if ($at === false) {
            return [];
        }
        preg_match_all('/^([A-Za-z0-9_]+)\s+-- /m', substr($help, $at), $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * Names and one-line descriptions of a type's options in Asterisk's help text.
     *
     * @return array<string, string>
     */
    public static function parseHelpDescriptions(string $help): array
    {
        $help = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $help);
        $at = strpos($help, '[Config Options]');
        if ($at === false || !preg_match_all('/^([A-Za-z0-9_]+)\s+-- (.*?)\s*$/m', substr($help, $at), $m)) {
            return [];
        }
        return array_combine($m[1], $m[2]);
    }

    /**
     * What the form offers while typing: option => description, by PJSIP type and by section of the
     * files whose options Asterisk lists.
     *
     * @return array{pjsip: array<string, array<string, string>>, files: array<string, array<string, array<string, string>>>}
     */
    public static function helpCatalog(): array
    {
        $catalog = ['pjsip' => [], 'files' => []];
        foreach (self::OBJECT_TYPES as $type => $module) {
            $catalog['pjsip'][$type] = self::parseHelpDescriptions(self::helpText($module, $type));
        }
        foreach (self::SECTION_HELP as $file => $sections) {
            foreach ($sections as $section => [$module, $type]) {
                $catalog['files'][$file][$section] = self::parseHelpDescriptions(self::helpText($module, $type));
            }
        }
        return $catalog;
    }

    /**
     * Options Asterisk knows for a type; empty when it cannot be asked.
     *
     * @return list<string>
     */
    public static function optionNames(string $type): array
    {
        return isset(self::OBJECT_TYPES[$type]) ? self::helpOptions(self::OBJECT_TYPES[$type], $type) : [];
    }

    /**
     * Options Asterisk's help lists for a section of another file; empty when it lists none, as
     * for rtp, queues, voicemail, musiconhold, logger, manager and indications.
     *
     * @return list<string>
     */
    public static function sectionOptionNames(string $file, string $section): array
    {
        $help = self::SECTION_HELP[$file][$section] ?? null;
        return $help === null ? [] : self::helpOptions($help[0], $help[1]);
    }

    /** @return list<string> */
    private static function helpOptions(string $module, string $type): array
    {
        return self::parseHelpOptions(self::helpText($module, $type));
    }

    private static function helpText(string $module, string $type): string
    {
        static $cache = [];
        $key = $module . ' ' . $type;
        if (!isset($cache[$key])) {
            // Not by its success flag: the words of the help text can look like a failed command.
            $cache[$key] = (string) AsteriskHelper::execCLI('config show help ' . $key)['output'];
        }
        return $cache[$key];
    }

    /**
     * Problems in the text of a raw entry. Asterisk's load result decides the rest, but what the
     * form refuses stays refused here: directives, and the options and sections of DENIED_*.
     *
     * @return list<string>
     */
    public static function checkRaw(string $text, string $file): array
    {
        $errors = [];
        $inSection = false;
        foreach (explode("\n", str_replace("\r", '', $text)) as $i => $raw) {
            $line = trim($raw);
            $at = $i + 1;
            if ($line === '' || $line[0] === ';') {
                continue;
            }
            if ($line[0] === '#' || preg_match('/[^\x20-\x7e\t]/', $line)) {
                $errors[] = sprintf(t('advanced_config.err_line'), $at);
            } elseif (preg_match('/^\[([A-Za-z0-9_.+-]+)\]\s*(\([^)]*\))?\s*(;.*)?$/', $line, $m)) {
                $inSection = true;
                if (self::sectionDenied($file, $m[1])) {
                    $errors[] = sprintf(t('advanced_config.err_denied_section'), $m[1]);
                }
            } elseif (!$inSection || !preg_match('/^([A-Za-z0-9_.*-]+)\s*(=>|=)/', $line, $m)) {
                $errors[] = sprintf(t('advanced_config.err_line'), $at);
            } elseif (in_array(strtolower($m[1]), self::DENIED_OPTIONS[$file] ?? [], true)) {
                $errors[] = sprintf(t('advanced_config.err_denied_option'), $m[1], $at);
            }
        }
        return $errors;
    }

    /** @param array<string, mixed> $e */
    public static function needsRestart(array $e): bool
    {
        if (($e['config_file'] ?? 'pjsip') !== 'pjsip') {
            return false;
        }
        // allow_reload is off by default: a reload shows the new value, the open socket keeps the old.
        return ($e['object_type'] ?? '') === 'transport'
            || (($e['target'] ?? '') === 'raw' && preg_match('/^\s*type\s*=\s*transport\b/mi', (string) $e['options']) === 1);
    }

    /**
     * The checked row a form or an imported entry stands for.
     *
     * @param array<string, mixed> $data
     * @return array{config_file: string, target: string, object_type: string, object_name: string, options: string}
     */
    private static function checkedEntry(array $data, bool $importing): array
    {
        [$file, $target, $type, $name] = self::entryPlace($data, $importing);
        $text = (string) ($data['options'] ?? '');
        if ($target === 'raw') {
            $errors = self::checkRaw($text, $file);
            if ($errors !== []) {
                throw new \Exception(implode(' ', $errors));
            }
            $text = trim(str_replace("\r", '', $text));
            if ($text === '') {
                throw new \Exception(t('advanced_config.err_no_options'));
            }
            return ['config_file' => $file, 'target' => $target, 'object_type' => '', 'object_name' => '', 'options' => $text];
        }
        $known = $file === 'pjsip' ? self::optionNames($type) : self::sectionOptionNames($file, $name);
        $check = self::checkOptions($text, $known, $file);
        if ($check['errors'] !== []) {
            throw new \Exception(implode(' ', $check['errors']));
        }
        if ($check['lines'] === []) {
            throw new \Exception(t('advanced_config.err_no_options'));
        }
        return ['config_file' => $file, 'target' => $target, 'object_type' => $type, 'object_name' => $name, 'options' => implode("\n", $check['lines'])];
    }

    public static function sectionDenied(string $file, string $section): bool
    {
        if ($file === 'manager' && strtolower($section) !== 'general') {
            return true; // The other section of manager.conf is the portal's own AMI login.
        }
        return in_array(strtolower($section), self::DENIED_SECTIONS[$file] ?? [], true);
    }

    /**
     * Normalised option lines, or the problems found in them.
     *
     * @param list<string> $known option names; empty skips the name check
     * @return array{lines: list<string>, errors: list<string>}
     */
    public static function checkOptions(string $text, array $known, string $file = 'pjsip'): array
    {
        // Other files also have "name => value" lines and names such as messages.log or a DTMF key.
        $format = $file === 'pjsip' ? '/^([A-Za-z0-9_]+)\s*(=)\s*(.*)$/' : '/^([A-Za-z0-9_.*-]+)\s*(=>|=)\s*(.*)$/';
        $lines = [];
        $errors = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $i => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === ';') {
                continue;
            }
            $at = $i + 1;
            // #include and #exec would read files or run programs, [ would start another section.
            if ($line[0] === '#' || $line[0] === '[' || preg_match('/[^\x20-\x7e]/', $line)) {
                $errors[] = sprintf(t('advanced_config.err_line'), $at);
                continue;
            }
            if (!preg_match($format, $line, $m)) {
                $errors[] = sprintf(t('advanced_config.err_line'), $at);
                continue;
            }
            $name = $m[1];
            if ($name === 'type') {
                $errors[] = sprintf(t('advanced_config.err_type_option'), $at);
                continue;
            }
            if (in_array(strtolower($name), self::DENIED_OPTIONS[$file] ?? [], true)) {
                $errors[] = sprintf(t('advanced_config.err_denied_option'), $name, $at);
                continue;
            }
            if ($known !== [] && !in_array($name, $known, true)) {
                $errors[] = sprintf(t('advanced_config.err_unknown_option'), $name, $at);
                continue;
            }
            $lines[] = $m[2] === '=>' ? $name . ' => ' . $m[3] : $name . '=' . $m[3];
        }
        return ['lines' => $lines, 'errors' => $errors];
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return getDB()->query('SELECT * FROM pbx_advanced_pjsip ORDER BY config_file, target, object_type, object_name')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public static function versions(): array
    {
        return getDB()->query('SELECT v.id, v.label, v.created_at, u.username FROM pbx_advanced_pjsip_versions v LEFT JOIN sys_users u ON u.id = v.created_by ORDER BY v.id DESC LIMIT ' . self::KEEP_VERSIONS)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function save(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $id = (int) ($data['id'] ?? 0);
            $entry = self::checkedEntry($data, false);
            ['config_file' => $file, 'target' => $target, 'object_type' => $type, 'object_name' => $name] = $entry;
            $db = getDB();
            $same = $db->prepare('SELECT id FROM pbx_advanced_pjsip WHERE config_file = ? AND target = ? AND object_type = ? AND object_name = ? AND id <> ?');
            $same->execute([$file, $target, $type, $name, $id]);
            $label = self::label(['config_file' => $file, 'target' => $target, 'object_type' => $type, 'object_name' => $name]);
            if ($same->fetchColumn() !== false) {
                throw new \Exception(sprintf(t('advanced_config.err_exists'), ...array_pad(explode(' ', $label, 2), 2, '')));
            }
            self::keepVersion($label);
            $row = [
                $entry['options'],
                mb_substr(trim((string) ($data['note'] ?? '')), 0, 200),
                empty($data['is_active']) ? 0 : 1,
                $file,
                $target,
                $type,
                $name,
            ];
            if ($id > 0) {
                $row[] = $id;
                $db->prepare('UPDATE pbx_advanced_pjsip SET options = ?, note = ?, is_active = ?, config_file = ?, target = ?, object_type = ?, object_name = ?, updated_at = NOW() WHERE id = ?')->execute($row);
            } else {
                $db->prepare('INSERT INTO pbx_advanced_pjsip (options, note, is_active, config_file, target, object_type, object_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')->execute($row);
            }
            self::markChanged($label, $id > 0 ? 'update' : 'create');
            return t('advanced_config.saved');
        });
    }

    public static function delete(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $stmt = $db->prepare('SELECT config_file, target, object_type, object_name FROM pbx_advanced_pjsip WHERE id = ?');
            $stmt->execute([(int) ($data['id'] ?? 0)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \Exception(t('advanced_config.err_object'));
            }
            $label = self::label($row);
            self::keepVersion($label);
            $db->prepare('DELETE FROM pbx_advanced_pjsip WHERE id = ?')->execute([(int) $data['id']]);
            self::markChanged($label, 'delete');
            return t('advanced_config.deleted');
        });
    }

    /** Puts the list back as it was before a change. The present list becomes a version too. */
    public static function restore(array $data): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($data) {
            $db = getDB();
            $stmt = $db->prepare('SELECT snapshot, created_at FROM pbx_advanced_pjsip_versions WHERE id = ?');
            $stmt->execute([(int) ($data['version_id'] ?? 0)]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            $rows = $version ? json_decode((string) $version['snapshot'], true) : null;
            if (!is_array($rows)) {
                throw new \Exception(t('advanced_config.err_version'));
            }
            $label = sprintf(t('advanced_config.restored_label'), (string) $version['created_at']);
            self::keepVersion($label);
            $db->beginTransaction();
            try {
                $db->exec('DELETE FROM pbx_advanced_pjsip');
                $insert = $db->prepare('INSERT INTO pbx_advanced_pjsip (config_file, target, object_type, object_name, options, note, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
                foreach ($rows as $r) {
                    $insert->execute([$r['config_file'] ?? 'pjsip', $r['target'] ?? 'object', $r['object_type'], $r['object_name'], $r['options'], $r['note'] ?? null, (int) ($r['is_active'] ?? 1)]);
                }
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            self::markChanged($label, 'update');
            return t('advanced_config.restored');
        });
    }

    /** The list as a file. Options can hold passwords, so this is for the administrator only. */
    public static function exportJson(): string
    {
        $rows = getDB()->query('SELECT config_file, target, object_type, object_name, options, note, is_active FROM pbx_advanced_pjsip ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        writeAuditLog('advanced_pjsip', 'advanced_pjsip', 0, 'export', 'export', $_SESSION['user_id'] ?? null);
        return (string) json_encode(['format' => 'aipbx-advanced-config', 'version' => 1, 'entries' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Replaces the list with a file made by exportJson(). All or nothing: every entry passes the
     * same checks as the form. The present list becomes a version.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $upload one entry of $_FILES
     */
    public static function import(array $data, ?array $upload): array
    {
        return PBXHelper::handleAction($data['csrf_token'] ?? '', function () use ($upload) {
            $path = (string) ($upload['tmp_name'] ?? '');
            if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($path) || (int) ($upload['size'] ?? 0) > self::IMPORT_MAX_BYTES) {
                throw new \Exception(t('advanced_config.err_import_file'));
            }
            $count = self::replaceFromJson((string) file_get_contents($path));
            return sprintf(t('advanced_config.imported'), $count);
        });
    }

    /** @return int entries now in the list */
    public static function replaceFromJson(string $json): int
    {
        $doc = json_decode($json, true);
        if (!is_array($doc) || ($doc['format'] ?? '') !== 'aipbx-advanced-config' || !is_array($doc['entries'] ?? null)) {
            throw new \Exception(t('advanced_config.err_import_file'));
        }
        $rows = [];
        $seen = [];
        foreach (array_values($doc['entries']) as $i => $row) {
            if (!is_array($row)) {
                throw new \Exception(sprintf(t('advanced_config.err_import_entry'), $i + 1, t('advanced_config.err_object')));
            }
            try {
                $entry = self::checkedEntry($row, true);
            } catch (\Exception $e) {
                throw new \Exception(sprintf(t('advanced_config.err_import_entry'), $i + 1, $e->getMessage()));
            }
            $key = json_encode([$entry['config_file'], $entry['target'], $entry['object_type'], $entry['object_name']]);
            if (isset($seen[$key])) {
                throw new \Exception(sprintf(t('advanced_config.err_import_entry'), $i + 1, self::label($entry)));
            }
            $seen[$key] = true;
            $entry['note'] = mb_substr(trim((string) ($row['note'] ?? '')), 0, 200);
            $entry['is_active'] = empty($row['is_active']) ? 0 : 1;
            $rows[] = $entry;
        }
        $label = t('advanced_config.import_label');
        self::keepVersion($label);
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM pbx_advanced_pjsip');
            $insert = $db->prepare('INSERT INTO pbx_advanced_pjsip (config_file, target, object_type, object_name, options, note, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            foreach ($rows as $r) {
                $insert->execute([$r['config_file'], $r['target'], $r['object_type'], $r['object_name'], $r['options'], $r['note'], $r['is_active']]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::markChanged($label, 'update');
        return count($rows);
    }

    /**
     * The checked file, target, type and name of a submitted entry. Another file than pjsip has a
     * section as its name and no type; a group has no name except the phone model; a raw entry has
     * neither. An imported model need not have a phone here yet: the list says "not found".
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function entryPlace(array $data, bool $importing = false): array
    {
        $file = (string) ($data['config_file'] ?? 'pjsip');
        $name = trim((string) ($data['object_name'] ?? ''));
        if (!isset(self::CONFIG_FILES[$file])) {
            throw new \Exception(t('advanced_config.err_object'));
        }
        if (($data['target'] ?? '') === 'raw') {
            return [$file, 'raw', '', ''];
        }
        if ($file !== 'pjsip') {
            if (!preg_match('/^[A-Za-z0-9_.+-]{1,80}$/D', $name)) {
                throw new \Exception(t('advanced_config.err_section'));
            }
            if (self::sectionDenied($file, $name)) {
                throw new \Exception(sprintf(t('advanced_config.err_denied_section'), $name));
            }
            return [$file, 'object', '', $name];
        }
        $target = (string) ($data['target'] ?? 'object');
        $type = (string) ($data['object_type'] ?? '');
        if (!isset(self::OBJECT_TYPES[$type]) || !in_array($target, self::TARGETS, true) || (!in_array($target, ['object', 'new'], true) && $type === 'transport')) {
            throw new \Exception(t('advanced_config.err_object'));
        }
        if ($target === 'extensions' || $target === 'trunks') {
            return [$file, $target, $type, ''];
        }
        if ($target === 'model') {
            $valid = $importing ? preg_match('/^[A-Za-z0-9 _.+-]{1,80}$/D', $name) === 1 : in_array($name, self::phoneModels(), true);
            if (!$valid) {
                throw new \Exception(t('advanced_config.err_object'));
            }
            return [$file, $target, $type, $name];
        }
        if (!preg_match('/^[A-Za-z0-9_.+-]{1,80}$/D', $name)) {
            throw new \Exception(t('advanced_config.err_object'));
        }
        return [$file, $target, $type, $name];
    }

    /** @return list<string> phone models in use */
    public static function phoneModels(): array
    {
        return array_map('strval', getDB()->query("SELECT DISTINCT model FROM pbx_phones WHERE model IS NOT NULL AND model <> '' ORDER BY model")->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Generated values an entry's lines differ from, with whether the entry's value is the one used.
     *
     * @param array<string, string> $generated option => value in the object or section
     * @return list<array{name: string, value: string, wins: bool}>
     */
    public static function replacedValues(array $e, array $generated): array
    {
        $file = (string) ($e['config_file'] ?? 'pjsip');
        $wins = !in_array($file, self::FIRST_VALUE_WINS, true);
        $found = [];
        foreach (explode("\n", (string) $e['options']) as $line) {
            if (!preg_match('/^([^=\s]+)\s*=>?\s*(.*)$/', trim($line), $m) || in_array(strtolower($m[1]), self::LIST_OPTIONS, true)
                || !array_key_exists($m[1], $generated) || $generated[$m[1]] === $m[2]) {
                continue;
            }
            $found[] = ['name' => $m[1], 'value' => in_array(strtolower($m[1]), self::SECRET_OPTIONS, true) ? '***' : $generated[$m[1]], 'wins' => $wins];
        }
        return $found;
    }

    /** @param array<string, mixed> $e */
    public static function label(array $e): string
    {
        $file = (string) ($e['config_file'] ?? 'pjsip');
        $target = (string) ($e['target'] ?? 'object');
        if ($target === 'raw') {
            return "{$file} " . t('advanced_config.target_raw');
        }
        if ($file !== 'pjsip') {
            return "{$file} {$e['object_name']}";
        }
        return trim("{$e['object_type']} " . ($target === 'object' ? '' : t('advanced_config.target_' . $target) . ' ') . $e['object_name']);
    }

    private static function keepVersion(string $label): void
    {
        $db = getDB();
        $rows = $db->query('SELECT config_file, target, object_type, object_name, options, note, is_active FROM pbx_advanced_pjsip')->fetchAll(PDO::FETCH_ASSOC);
        $db->prepare('INSERT INTO pbx_advanced_pjsip_versions (snapshot, label, created_by) VALUES (?, ?, ?)')
            ->execute([json_encode($rows), mb_substr($label, 0, 200), $_SESSION['user_id'] ?? null]);
        $db->exec('DELETE FROM pbx_advanced_pjsip_versions WHERE id NOT IN (SELECT id FROM (SELECT id FROM pbx_advanced_pjsip_versions ORDER BY id DESC LIMIT ' . self::KEEP_VERSIONS . ') keep)');
    }

    private static function markChanged(string $label, string $action): void
    {
        $uid = $_SESSION['user_id'] ?? null;
        markPendingSync('advanced_pjsip', 'advanced_pjsip', 0, $label, $action, $uid);
        writeAuditLog('advanced_pjsip', 'advanced_pjsip', 0, $label, $action, $uid);
    }
}
