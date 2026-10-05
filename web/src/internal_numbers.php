<?php
/**
 * Internal destination numbers — central registry and conflict validation.
 *
 * This file knows "which entities can carry a number" and "is this number
 * free". It does NOT know about dialplan generation — that lives in
 * src/sync/SyncInternalNumbers.php. The split exists so the generator side
 * can be reduced to a testable pure function (buildInternalNumbersConf).
 *
 * TO GIVE A NEW ENTITY TYPE A NUMBER: add one row to INTERNAL_NUMBER_SOURCES.
 * Validation, generation, the panel list and the smoke-test rule all read
 * from there.
 */

require_once __DIR__ . '/../config.php';

/**
 * dest_type / dest_col: the values used in the
 * buildDestinationLines($dest_type, $dest_id) call.
 *
 * CAUTION — dest_col of the 'hangup' row: the 'hangup' branch of
 * buildDestinationLines() looks dest_id up as `pbx_hangup_actions.action_key`
 * ("WHERE ha.action_key = ?"), NOT as a numeric id. With 'id' here the query
 * finds no row and every call silently becomes a plain Hangup() — the chosen
 * "busy tone"/"network busy" behaviour and its announcement are lost.
 */
const INTERNAL_NUMBER_SOURCES = [
    'ivr' => [
        'table' => 'pbx_ivrs', 'label_col' => 'title',
        'dest_type' => 'ivr', 'dest_col' => 'id',
        'ui' => 'IVR Menüsü',
    ],
    'queue' => [
        'table' => 'pbx_queues', 'label_col' => 'title',
        'dest_type' => 'queue', 'dest_col' => 'id',
        'ui' => 'Kuyruk',
    ],
    'time_condition' => [
        'table' => 'pbx_time_conditions', 'label_col' => 'title',
        'dest_type' => 'time_condition', 'dest_col' => 'id',
        'ui' => 'Zaman Koşulu',
    ],
    'announcement' => [
        'table' => 'pbx_announcements', 'label_col' => 'title',
        'dest_type' => 'announcement', 'dest_col' => 'id',
        'ui' => 'Anons',
    ],
    'ring_group' => [
        'table' => 'pbx_ring_groups', 'label_col' => 'name',
        'dest_type' => 'ring_group', 'dest_col' => 'id',
        'ui' => 'Çalma Grubu',
    ],
    'conference' => [
        'table' => 'pbx_conferences', 'label_col' => 'title',
        'dest_type' => 'conference', 'dest_col' => 'id',
        'ui' => 'Konferans Odası',
    ],
    'hangup' => [
        'table' => 'pbx_hangup_actions', 'label_col' => 'title',
        'dest_type' => 'hangup', 'dest_col' => 'action_key',
        'ui' => 'Çağrı Sonlandırma',
    ],
];

/** Keeps only the digits of a user input. */
function internalNumberSanitize($raw): string
{
    return preg_replace('/[^0-9]/', '', trim((string) $raw));
}

/**
 * Returns ALL active entities with a number in one list.
 * Sorted by number — so the diff of the generated conf file stays stable.
 */
function internalNumberEntries(): array
{
    $db = getDB();
    $out = [];

    foreach (INTERNAL_NUMBER_SOURCES as $key => $src) {
        $sql = "SELECT id, internal_number, {$src['label_col']} AS label, {$src['dest_col']} AS dest_id
                FROM {$src['table']}
                WHERE is_active = 1 AND internal_number IS NOT NULL AND internal_number <> ''";
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $num = internalNumberSanitize($row['internal_number']);
            if ($num === '') continue;
            $out[] = [
                'number'    => $num,
                'source'    => $key,
                'dest_type' => $src['dest_type'],
                'dest_id'   => (string) $row['dest_id'],
                'label'     => (string) $row['label'],
                'row_id'    => (int) $row['id'],
            ];
        }
    }

    usort($out, fn($a, $b) => strcmp($a['number'], $b['number']));
    return $out;
}

/**
 * When the number is used by someone, returns "who" in human-readable form;
 * null when it is free.
 *
 * $skipSource/$skipId: so the record's OWN number does not count as a
 * conflict (saving while editing without changing the number must not fail).
 */
function internalNumberOwner(string $number, ?string $skipSource = null, int $skipId = 0): ?string
{
    $number = internalNumberSanitize($number);
    if ($number === '') return null;
    $db = getDB();

    // While a user is being edited, their own extension is not a conflict ($skipSource = 'user').
    if ($skipSource === 'user' && $skipId > 0) {
        $u = $db->prepare("SELECT full_name, extension_type FROM sys_users WHERE extension = ? AND id != ? LIMIT 1");
        $u->execute([$number, $skipId]);
    } else {
        $u = $db->prepare("SELECT full_name, extension_type FROM sys_users WHERE extension = ? LIMIT 1");
        $u->execute([$number]);
    }
    if ($row = $u->fetch(PDO::FETCH_ASSOC)) {
        $tip = ($row['extension_type'] === 'fax') ? 'faks dahilisi' : 'dahili';
        return "{$tip}: " . $row['full_name'];
    }

    $f = $db->prepare("SELECT title FROM pbx_feature_codes WHERE code = ? LIMIT 1");
    $f->execute([$number]);
    if ($title = $f->fetchColumn()) {
        return "özellik kodu: {$title}";
    }

    foreach (INTERNAL_NUMBER_SOURCES as $key => $src) {
        $sql = "SELECT {$src['label_col']} AS label FROM {$src['table']} WHERE internal_number = ?";
        $params = [$number];
        if ($key === $skipSource && $skipId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $skipId;
        }
        $st = $db->prepare($sql . " LIMIT 1");
        $st->execute($params);
        if ($label = $st->fetchColumn()) {
            return $src['ui'] . ": " . $label;
        }
    }

    return null;
}

/**
 * Throws \Exception when the number cannot be saved. An empty number is
 * allowed (the field is optional) and passes silently.
 *
 * Length limits: at least 2, because a single-digit number gets mixed up with
 * IVR key matches and emergency shortcuts; at most 6, because the column is
 * VARCHAR(10) and 7+ digits enter outside-number territory.
 */
function assertInternalNumberAvailable(string $number, string $sourceKey, int $ownerId): void
{
    $number = internalNumberSanitize($number);
    if ($number === '') return;

    $len = strlen($number);
    if ($len < 2 || $len > 6) {
        throw new \Exception("Dahili hedef numarası 2-6 hane arasında olmalıdır (girilen: {$number}).");
    }

    $owner = internalNumberOwner($number, $sourceKey, $ownerId);
    if ($owner !== null) {
        throw new \Exception("{$number} numarası zaten kullanımda ({$owner}). Lütfen başka bir numara seçin.");
    }
}

/**
 * Converts an Asterisk number pattern ("_[4-9]XXX") to PCRE.
 *
 * Only the subset really used in outbound route patterns is supported:
 * X Z N dot bang, bracket ranges and plain digits. On an unsupported
 * character it returns null and the caller SKIPS that pattern — a false
 * alarm is worse than staying silent.
 */
function asteriskPatternToRegex(string $pattern): ?string
{
    $p = trim($pattern);
    if ($p === '') return null;

    if ($p[0] !== '_') {
        return '/^' . preg_quote($p, '/') . '$/';
    }

    $p = substr($p, 1);
    $re = '';
    $len = strlen($p);
    for ($i = 0; $i < $len; $i++) {
        $c = $p[$i];
        if ($c === 'X')            { $re .= '[0-9]'; }
        elseif ($c === 'Z')        { $re .= '[1-9]'; }
        elseif ($c === 'N')        { $re .= '[2-9]'; }
        elseif ($c === '.')        { $re .= '.+'; }
        elseif ($c === '!')        { $re .= '.*'; }
        elseif ($c === '-')        { /* Asterisk desenlerinde tire yok sayılır */ }
        elseif (ctype_digit($c))   { $re .= $c; }
        elseif ($c === '[') {
            $end = strpos($p, ']', $i);
            if ($end === false) return null;
            $ic = substr($p, $i + 1, $end - $i - 1);
            if (!preg_match('/^[0-9\-]+$/', $ic)) return null;
            $re .= '[' . $ic . ']';
            $i = $end;
        }
        else { return null; }
    }

    return '/^' . $re . '$/';
}

/**
 * Returns a warning text when the number also matches an active outbound
 * route pattern. It does NOT BLOCK — Asterisk's match order already prefers
 * the internal destination (from-internal-pbx-ortak searches its own include
 * before outbound), but the admin needs to know this number no longer goes
 * out through the PBX.
 */
function internalNumberRouteWarning(string $number): ?string
{
    $number = internalNumberSanitize($number);
    if ($number === '') return null;

    $rows = getDB()->query(
        "SELECT route_name, match_pattern FROM pbx_outbound_routes WHERE is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $re = asteriskPatternToRegex((string) $r['match_pattern']);
        if ($re === null) continue;
        if (preg_match($re, $number) === 1) {
            return "{$number} numarası '" . $r['route_name'] . "' giden rotasının deseni ("
                 . $r['match_pattern'] . ") ile de eşleşiyor. Dahili hedef önce çalışır, "
                 . "yani bu numara artık dış hatta çıkmayacak.";
        }
    }

    return null;
}

/**
 * Throws a clear error when the number is used by another record (extension,
 * feature code, IVR, queue, conference, ring group…).
 *
 * The conference and ring group services called this function but it had
 * never been defined: saving died with "Call to undefined function".
 *
 * @param string|null $source  the record's own source ('conference', 'ring_group', 'user'…)
 * @param int         $id      id of the edited record (its own number is not a conflict)
 */
function internalNumberValidate(string $number, ?string $source = null, int $id = 0): void
{
    $owner = internalNumberOwner($number, $source, $id);
    if ($owner !== null) {
        throw new \Exception("{$number} numarası zaten kullanımda ({$owner}).");
    }
}
