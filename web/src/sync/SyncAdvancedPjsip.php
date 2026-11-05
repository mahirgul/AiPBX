<?php
/**
 * Administrator's PJSIP options (/etc/asterisk/pbx/pjsip_zz_advanced.conf) and lines for other
 * Asterisk files (/etc/asterisk/pbx/user_<file>.conf, pulled in by a #tryinclude at the end of the
 * file: the installer and the rtp and udptl generators add it).
 *
 * The name sorts after every other pjsip_*.conf, because `[name](+type=...)` adds to a section that
 * must already have been read. The type filter is needed as an endpoint and an aor often share a
 * name.
 *
 * Every PJSIP reload rewrites the PJSIP file from the last list that applied cleanly, leaving out objects
 * that no longer exist. Applying a changed list is the only place a new list is tried.
 */

const ADVANCED_PJSIP_FILE = 'pjsip_zz_advanced.conf';
const ADVANCED_PJSIP_APPLIED = 'advanced_pjsip_applied';

function syncAdvancedPjsip()
{
    return withSyncLock('advanced_pjsip', '__syncAdvancedPjsipBody');
}

/**
 * The objects a set of pjsip configuration files defines, as "type:name". A section's type is its
 * own type= or comes from the templates it names.
 *
 * @param list<string> $contents
 * @return list<string>
 */
function pjsipObjectsInConfigs(array $contents): array
{
    $sections = [];
    $current = -1;
    foreach ($contents as $content) {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (preg_match('/^\[([^\]]+)\]\s*(?:\(([^)]*)\))?/', $line, $m)) {
                $flags = array_values(array_filter(array_map('trim', explode(',', $m[2] ?? ''))));
                $sections[++$current] = [
                    'name' => $m[1],
                    'template' => in_array('!', $flags, true),
                    'parents' => array_values(array_diff($flags, ['!', '+'])),
                    'type' => '',
                ];
            } elseif ($current >= 0 && preg_match('/^type\s*=\s*([A-Za-z_]+)/', $line, $m)) {
                $sections[$current]['type'] = strtolower($m[1]);
            }
        }
    }
    $templates = [];
    foreach ($sections as $s) {
        if ($s['template']) {
            $templates[$s['name']] = $s;
        }
    }
    $typeOf = function (array $s, int $depth = 0) use (&$typeOf, $templates): string {
        if ($s['type'] !== '' || $depth > 8) {
            return $s['type'];
        }
        foreach ($s['parents'] as $parent) {
            if (isset($templates[$parent]) && ($type = $typeOf($templates[$parent], $depth + 1)) !== '') {
                return $type;
            }
        }
        return '';
    };
    $objects = [];
    foreach ($sections as $s) {
        if (!$s['template'] && ($type = $typeOf($s)) !== '') {
            $objects[$type . ':' . $s['name']] = true;
        }
    }
    return array_keys($objects);
}

/**
 * Object names of a group of extensions, by type: only the SIP objects. A browser or the mobile
 * client needs its own endpoint settings, so a group does not touch those.
 *
 * @param list<string> $extensions
 * @return array<string, list<string>>
 */
function advancedExtensionObjects(array $extensions): array
{
    $names = ['endpoint' => [], 'aor' => [], 'auth' => [], 'identify' => []];
    foreach ($extensions as $ext) {
        $ext = (string) preg_replace('/[^0-9]/', '', $ext);
        if ($ext !== '') {
            $names['endpoint'][] = $names['aor'][] = "{$ext}-sip";
            $names['auth'][] = "auth{$ext}-sip";
            $names['identify'][] = "{$ext}-sip-identify";
        }
    }
    return $names;
}

/**
 * Object names of the trunks, by type.
 *
 * @param list<string> $trunks
 * @param array<string, string> $aors trunk => its aor, where that is not the trunk's name (a
 *        registering trunk's aor is named after its user)
 * @return array<string, list<string>>
 */
function advancedTrunkObjects(array $trunks, array $aors = []): array
{
    $names = ['endpoint' => [], 'aor' => [], 'auth' => [], 'identify' => [], 'registration' => []];
    foreach ($trunks as $trunk) {
        $names['endpoint'][] = $names['identify'][] = $trunk;
        $names['aor'][] = $aors[$trunk] ?? $trunk;
        $names['auth'][] = "{$trunk}-auth";
        $names['registration'][] = "{$trunk}-reg";
    }
    return $names;
}

/**
 * The aor each endpoint in a set of configuration files names first.
 *
 * @param list<string> $contents
 * @return array<string, string>
 */
function pjsipEndpointAors(array $contents): array
{
    $aors = [];
    foreach ($contents as $content) {
        foreach (preg_split('/^(?=\[)/m', $content) ?: [] as $section) {
            if (preg_match('/^\[([^\]]+)\]/', $section, $name) && preg_match('/^type=endpoint\s*$/m', $section)
                && preg_match('/^aors=([^,\s]+)/m', $section, $aor)) {
                $aors[$name[1]] = $aor[1];
            }
        }
    }
    return $aors;
}

/**
 * The groups an entry can name, as they are now, so a new extension gets its group's options on the
 * next reload.
 *
 * @return array<string, array<string, list<string>>>
 */
function advancedPjsipGroups(): array
{
    $db = getDB();
    $groups = [
        'extensions' => advancedExtensionObjects(array_map('strval', $db->query("SELECT extension FROM sys_users WHERE extension IS NOT NULL AND extension <> '' AND is_active = 1 AND extension_type = 'sip'")->fetchAll(PDO::FETCH_COLUMN))),
        'trunks' => advancedTrunkObjects(array_map('strval', $db->query('SELECT trunk_name FROM pbx_trunks')->fetchAll(PDO::FETCH_COLUMN)), pjsipEndpointAors(advancedPjsipSourceFiles())),
    ];
    $models = [];
    foreach ($db->query("SELECT p.model, u.extension FROM pbx_phones p JOIN sys_users u ON u.id = p.user_id WHERE u.extension IS NOT NULL AND u.extension <> ''")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $models[(string) $row['model']][] = (string) $row['extension'];
    }
    foreach ($models as $model => $extensions) {
        $groups['model:' . $model] = advancedExtensionObjects($extensions);
    }
    return $groups;
}

/**
 * The existing objects an entry adds to.
 *
 * @param array<string, mixed> $e
 * @param list<string> $objects
 * @param array<string, array<string, list<string>>> $groups
 * @return list<string> object names
 */
function advancedEntryObjects(array $e, array $objects, array $groups): array
{
    $type = (string) $e['object_type'];
    $name = (string) $e['object_name'];
    $target = (string) ($e['target'] ?? 'object');
    if ($target === 'raw' || $target === 'new') {
        return [];
    }
    $key = $target === 'model' ? 'model:' . $name : $target;
    $names = $target === 'object' ? [$name] : ($groups[$key][$type] ?? []);
    $known = array_flip($objects);
    return array_values(array_filter($names, static fn(string $n): bool => isset($known[$type . ':' . $n])));
}

/**
 * A raw entry's text without additions to sections that do not exist: one such line stops Asterisk
 * reading the rest of the file, and a queue or an object can be deleted long after the text was
 * written.
 *
 * @param list<string> $known section names
 */
function advancedRawText(string $raw, array $known): string
{
    $out = [];
    $skip = false;
    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*(\([^)]*\))?/', $line, $m)) {
            $adds = strpos($m[2] ?? '', '+') !== false;
            $skip = $adds && !in_array($m[1], $known, true);
            if ($skip) {
                $out[] = "; [{$m[1]}] left out: this section does not exist";
                continue;
            }
            $known[] = $m[1];
        }
        if (!$skip) {
            $out[] = $line;
        }
    }
    return trim(implode("\n", $out));
}

/**
 * The objects AiPBX generates plus those the list itself defines, as "type:name", which entries can
 * also add to.
 *
 * @param list<array<string, mixed>> $entries
 * @param list<string> $objects
 * @return list<string>
 */
function advancedObjectsWithOwn(array $entries, array $objects): array
{
    $texts = [];
    foreach ($entries as $e) {
        if (($e['config_file'] ?? 'pjsip') !== 'pjsip' || empty($e['is_active'])) {
            continue;
        }
        if (($e['target'] ?? 'object') === 'raw') {
            $texts[] = (string) $e['options'];
        } elseif (($e['target'] ?? 'object') === 'new' && !in_array($e['object_type'] . ':' . $e['object_name'], $objects, true)) {
            $objects[] = $e['object_type'] . ':' . $e['object_name'];
        }
    }
    return array_values(array_unique(array_merge($objects, pjsipObjectsInConfigs($texts))));
}

/**
 * Pure generator. Entries whose object is not in $objects are left out: adding to a section that
 * does not exist stops Asterisk reading the rest of the file. The list's own objects come first so
 * entries can add to them, then groups, so an entry on one object wins over its group. A new object
 * whose name AiPBX now generates too is left out.
 *
 * @param list<array<string, mixed>> $entries
 * @param list<string> $objects
 * @param array<string, array<string, list<string>>> $groups
 */
function buildAdvancedPjsipConf(array $entries, array $objects, array $groups = []): string
{
    $conf = "; Administrator's options from the Advanced configuration page (kept in the database).\n";
    $conf .= "; Do not edit this file: every PJSIP reload rewrites it.\n\n";
    $rank = ['raw' => -2, 'new' => -1, 'extensions' => 0, 'trunks' => 1, 'model' => 2, 'object' => 3];
    $entries = array_values(array_filter($entries, static fn(array $e): bool => ($e['config_file'] ?? 'pjsip') === 'pjsip' && !empty($e['is_active'])));
    usort($entries, static fn(array $a, array $b): int => ($rank[$a['target'] ?? 'object'] ?? 3) <=> ($rank[$b['target'] ?? 'object'] ?? 3));
    $all = advancedObjectsWithOwn($entries, $objects);
    $names = array_map(static fn(string $o): string => substr($o, (int) strpos($o, ':') + 1), $objects);
    foreach ($entries as $e) {
        $type = preg_replace('/[^a-z_]/', '', (string) $e['object_type']);
        $target = (string) ($e['target'] ?? 'object');
        if ($target === 'raw') {
            if (AdvancedPjsipService::checkRaw((string) $e['options'], 'pjsip') === []) {
                $conf .= advancedRawText((string) $e['options'], $names) . "\n\n";
            }
        } elseif ($target === 'new') {
            $name = preg_replace('/[^A-Za-z0-9_.+-]/', '', (string) $e['object_name']);
            if (!in_array($type . ':' . $name, $objects, true)) {
                $conf .= "[{$name}]\ntype={$type}\n" . trim((string) $e['options']) . "\n\n";
            }
        } else {
            foreach (advancedEntryObjects($e, $all, $groups) as $object) {
                $name = preg_replace('/[^A-Za-z0-9_.+-]/', '', $object);
                $conf .= "[{$name}](+type={$type})\n" . trim((string) $e['options']) . "\n\n";
            }
        }
    }
    return $conf;
}

/**
 * Pure generator for another file than pjsip. Options are checked again: a row from a restored
 * backup or from SQL never went through the page. Raw text comes first, as it can start sections
 * the other entries add to.
 *
 * @param list<array<string, mixed>> $entries
 * @param list<string> $sections sections the file has
 */
function buildAdvancedFileConf(string $file, array $entries, array $sections): string
{
    $conf = "; Administrator's lines from the Advanced configuration page (kept in the database).\n";
    $conf .= "; Do not edit this file: Apply rewrites it.\n\n";
    $entries = array_values(array_filter($entries, static fn(array $e): bool => ($e['config_file'] ?? 'pjsip') === $file && !empty($e['is_active'])));
    foreach ($entries as $e) {
        if (($e['target'] ?? 'object') === 'raw' && AdvancedPjsipService::checkRaw((string) $e['options'], $file) === []) {
            $text = advancedRawText((string) $e['options'], $sections);
            $conf .= $text . "\n\n";
            preg_match_all('/^\s*\[([^\]]+)\]/m', $text, $m);
            $sections = array_merge($sections, $m[1]);
        }
    }
    foreach ($entries as $e) {
        $section = (string) $e['object_name'];
        if (($e['target'] ?? 'object') === 'raw' || !in_array($section, $sections, true) || AdvancedPjsipService::sectionDenied($file, $section)) {
            continue;
        }
        $check = AdvancedPjsipService::checkOptions((string) $e['options'], [], $file);
        if ($check['errors'] !== [] || $check['lines'] === []) {
            continue;
        }
        $conf .= "[{$section}](+)\n" . implode("\n", $check['lines']) . "\n\n";
    }
    return $conf;
}

/**
 * Values each object has from the generated configuration, its templates included, as
 * "type:name" => option => value.
 *
 * @param list<string> $contents
 * @return array<string, array<string, string>>
 */
function pjsipObjectValues(array $contents): array
{
    $sections = [];
    foreach ($contents as $content) {
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (preg_match('/^\[([^\]]+)\]\s*(?:\(([^)]*)\))?/', $line, $m)) {
                $flags = array_values(array_filter(array_map('trim', explode(',', $m[2] ?? ''))));
                $sections[] = ['name' => $m[1], 'template' => in_array('!', $flags, true), 'parents' => array_values(array_diff($flags, ['!', '+'])), 'values' => []];
            } elseif ($sections !== [] && preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                $sections[count($sections) - 1]['values'][$m[1]] = $m[2];
            }
        }
    }
    $templates = [];
    foreach ($sections as $s) {
        if ($s['template']) {
            $templates[$s['name']] = $s;
        }
    }
    $merged = function (array $s, int $depth = 0) use (&$merged, $templates): array {
        $values = [];
        foreach ($s['parents'] as $parent) {
            if (isset($templates[$parent]) && $depth < 8) {
                $values = array_merge($values, $merged($templates[$parent], $depth + 1));
            }
        }
        return array_merge($values, $s['values']);
    };
    $objects = [];
    foreach ($sections as $s) {
        $values = $merged($s);
        if (!$s['template'] && isset($values['type'])) {
            $type = strtolower($values['type']);
            unset($values['type']);
            $objects[$type . ':' . $s['name']] = $values;
        }
    }
    return $objects;
}

/**
 * Values in the sections of a file, as section => option => value. The first value of an option is
 * the one kept: a file where a later one wins has the same option only once when generated.
 *
 * @param list<string> $contents
 * @return array<string, array<string, string>>
 */
function configSectionValues(array $contents): array
{
    $sections = [];
    foreach ($contents as $content) {
        $current = null;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                $current = $m[1];
            } elseif ($current !== null && $line !== '' && $line[0] !== ';' && preg_match('/^([A-Za-z0-9_.*-]+)\s*=>?\s*(.*)$/', $line, $m)) {
                $sections[$current][$m[1]] ??= trim((string) preg_replace('/\s*;.*$/', '', $m[2]));
            }
        }
    }
    return $sections;
}

/** @return list<string> contents of a file and of the generated files it includes */
function advancedFileContents(string $file): array
{
    if ($file === 'manager') {
        return [];
    }
    $paths = array_merge([ASTERISK_CONF_DIR . "/{$file}.conf"], glob(ASTERISK_PBX_DIR . "/{$file}_*.conf") ?: []);
    return array_map(static fn(string $p): string => (string) file_get_contents($p), array_values(array_filter($paths, 'is_readable')));
}

/**
 * What the list's table says about an entry: "ok", "missing" (its object or section is not there),
 * "taken" (a new object's name is generated now).
 *
 * @param array<string, mixed> $e
 * @param list<string> $objects objects with the list's own
 * @param list<string> $generated objects AiPBX generates
 * @param array<string, array<string, list<string>>> $groups
 * @param array<string, list<string>> $sections
 */
function advancedEntryState(array $e, array $objects, array $generated, array $groups, array $sections): string
{
    $target = (string) ($e['target'] ?? 'object');
    if ($target === 'raw') {
        return 'ok';
    }
    if ($target === 'new') {
        return in_array($e['object_type'] . ':' . $e['object_name'], $generated, true) ? 'taken' : 'ok';
    }
    if (($e['config_file'] ?? 'pjsip') === 'pjsip') {
        return advancedEntryObjects($e, $objects, $groups) === [] ? 'missing' : 'ok';
    }
    return in_array($e['object_name'], $sections[$e['config_file']] ?? [], true) ? 'ok' : 'missing';
}

/**
 * Sections of a configuration file and of the generated files it includes. manager.conf cannot be
 * read by the portal, and only its [general] can be changed.
 *
 * @return list<string>
 */
function advancedFileSections(string $file): array
{
    if ($file === 'manager') {
        return ['general'];
    }
    $sections = [];
    foreach (advancedFileContents($file) as $content) {
        if (preg_match_all('/^\[([^\]]+)\]/m', $content, $m)) {
            array_push($sections, ...$m[1]);
        }
    }
    return array_values(array_unique($sections));
}

/** @return list<string> contents of the pjsip files apart from this module's */
function advancedPjsipSourceFiles(): array
{
    $paths = array_merge(['/etc/asterisk/pjsip.conf'], glob(ASTERISK_PBX_DIR . '/pjsip_*.conf') ?: []);
    $contents = [];
    foreach ($paths as $path) {
        if (basename($path) !== ADVANCED_PJSIP_FILE && is_readable($path)) {
            $contents[] = (string) file_get_contents($path);
        }
    }
    return $contents;
}

/** @return list<string> */
function advancedPjsipObjects(): array
{
    return pjsipObjectsInConfigs(advancedPjsipSourceFiles());
}

/**
 * Called before every PJSIP reload, so a removed extension cannot leave an addition behind. Never
 * throws: a reload of anything else must not fail because of this.
 */
function refreshAdvancedPjsipOverlay(): void
{
    try {
        $applied = json_decode((string) getSystemSetting(ADVANCED_PJSIP_APPLIED, '[]'), true);
        $conf = buildAdvancedPjsipConf(is_array($applied) ? $applied : [], advancedPjsipObjects(), advancedPjsipGroups());
        $path = ASTERISK_PBX_DIR . '/' . ADVANCED_PJSIP_FILE;
        if (!is_file($path) || (string) file_get_contents($path) !== $conf) {
            writePBXConf(ADVANCED_PJSIP_FILE, $conf);
        }
    } catch (\Throwable $e) {
        error_log('[advanced_pjsip] ' . $e->getMessage());
    }
}

/**
 * For a file whose sections the portal generates (queues, musiconhold): called before it is reloaded,
 * so a removed queue cannot leave an addition that stops Asterisk reading the file. Never throws.
 */
function refreshAdvancedFileOverlay(string $file): void
{
    try {
        require_once dirname(__DIR__) . '/services/AdvancedPjsipService.php';
        $name = "user_{$file}.conf";
        $path = ASTERISK_PBX_DIR . '/' . $name;
        if (!is_file($path)) {
            return;
        }
        $applied = json_decode((string) getSystemSetting(ADVANCED_PJSIP_APPLIED, '[]'), true);
        $conf = buildAdvancedFileConf($file, is_array($applied) ? $applied : [], advancedFileSections($file));
        if ((string) file_get_contents($path) !== $conf) {
            writePBXConf($name, $conf);
        }
    } catch (\Throwable $e) {
        error_log('[advanced_config] ' . $e->getMessage());
    }
}

/**
 * What the log says about a reload: lines of Asterisk refusing an object or an option.
 *
 * @return list<string>
 */
function pjsipReloadProblems(string $logTail): array
{
    $problems = [];
    foreach (explode("\n", $logTail) as $line) {
        if (preg_match('/Could not create an object of type|Could not find option suitable|Error parsing|Category addition requested|No \'=\' \(equal sign\)/', $line)) {
            $problems[] = trim((string) preg_replace('/^\[[^\]]*\]\s*/', '', $line));
        }
    }
    return array_values(array_unique($problems));
}

function __syncAdvancedPjsipBody()
{
    require_once dirname(__DIR__) . '/services/AdvancedPjsipService.php';
    $entries = AdvancedPjsipService::all();
    $log = '/var/log/asterisk/messages.log';
    $logSize = is_readable($log) ? (int) filesize($log) : null;

    // Files to write => [content before, reload command]. PJSIP is always read again, as before;
    // another file only when it changes, since a queue or voicemail reload has no reason to run.
    $changes = [];
    $pjsipPath = ASTERISK_PBX_DIR . '/' . ADVANCED_PJSIP_FILE;
    $changes[ADVANCED_PJSIP_FILE] = [
        buildAdvancedPjsipConf($entries, advancedPjsipObjects(), advancedPjsipGroups()),
        is_file($pjsipPath) ? (string) file_get_contents($pjsipPath) : null,
        AdvancedPjsipService::CONFIG_FILES['pjsip'],
    ];
    foreach (AdvancedPjsipService::CONFIG_FILES as $file => $reload) {
        if ($file === 'pjsip') {
            continue;
        }
        $name = "user_{$file}.conf";
        $path = ASTERISK_PBX_DIR . '/' . $name;
        $previous = is_file($path) ? (string) file_get_contents($path) : null;
        $conf = buildAdvancedFileConf($file, $entries, advancedFileSections($file));
        if ($previous !== $conf && ($previous !== null || count(array_filter($entries, static fn(array $e): bool => $e['config_file'] === $file)) > 0)) {
            $changes[$name] = [$conf, $previous, $reload];
        }
    }

    $problems = [];
    foreach ($changes as $name => [$conf, , $reload]) {
        writePBXConf($name, $conf);
        $result = AsteriskHelper::execCLI($reload);
        if (empty($result['success'])) {
            $problems[] = (string) ($result['output'] ?? '');
        }
    }
    if ($problems === [] && $logSize !== null && getenv('AIPBX_NO_ASTERISK') !== '1') {
        usleep(700000); // Asterisk's logger writes from its own queue.
        clearstatcache(true, $log);
        $tail = (string) @file_get_contents($log, false, null, $logSize);
        $problems = pjsipReloadProblems($tail);
    }
    if ($problems !== []) {
        foreach ($changes as $name => [, $previous, $reload]) {
            writePBXConf($name, $previous ?? ($name === ADVANCED_PJSIP_FILE ? buildAdvancedPjsipConf([], []) : ''));
            AsteriskHelper::execCLI($reload);
        }
        throw new \Exception(sprintf(t('advanced_config.err_rolled_back'), implode(' | ', array_slice($problems, 0, 3))));
    }

    getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([ADVANCED_PJSIP_APPLIED, json_encode($entries)]);
    return true;
}
