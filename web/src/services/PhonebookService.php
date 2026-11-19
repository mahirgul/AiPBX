<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../secret_box.php';

/**
 * The shared phonebook (phonebook_contacts) and the switch of the LDAP
 * phonebook the chat service serves to desk phones (chat/ldap.go).
 * Rows of source "manual" are kept here; rows a plugin writes are read-only.
 */
final class PhonebookService
{
    public const BASE_DN = 'ou=phonebook,dc=aipbx';
    public const BIND_DN = 'cn=phonebook,dc=aipbx';
    public const FIELDS = ['display_name', 'first_name', 'last_name', 'company', 'phone_work', 'phone_mobile', 'phone_other', 'email'];
    private const LIMITS = ['display_name' => 150, 'first_name' => 80, 'last_name' => 80, 'company' => 120,
        'phone_work' => 40, 'phone_mobile' => 40, 'phone_other' => 40, 'email' => 191];
    /** Number labels, in the order the form offers them. */
    public const LABELS = ['mobile', 'work', 'work2', 'home', 'fax', 'other'];
    public const MAX_NUMBERS = 12;
    /** caller_match_digits choices: 0 compares the whole number. */
    public const MATCH_DIGITS = [0, 7, 8, 9, 10, 11, 12];

    /** @return list<array<string, mixed>> */
    public static function contacts(): array
    {
        self::syncNumbers();
        $rows = getDB()->query('SELECT * FROM phonebook_contacts ORDER BY display_name, last_name, first_name LIMIT 20000')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return self::withNumbers($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> the rows with 'numbers' => list of {label, number}
     */
    public static function withNumbers(array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = [];
        }
        foreach (array_chunk(array_keys($byId), 1000) as $ids) {
            $st = getDB()->prepare('SELECT contact_id, label, number FROM phonebook_numbers WHERE contact_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY contact_id, sort, id');
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $byId[(int) $n['contact_id']][] = ['label' => (string) $n['label'], 'number' => (string) $n['number']];
            }
        }
        foreach ($rows as &$r) {
            $r['numbers'] = $byId[(int) $r['id']] ?? [];
            unset($r['numbers_indexed_at']);
        }
        return $rows;
    }

    /** One contact with its numbers, or null. */
    public static function find(int $id): ?array
    {
        self::syncNumbers($id);
        $st = getDB()->prepare('SELECT * FROM phonebook_contacts WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::withNumbers([$row])[0] : null;
    }

    /**
     * The key two spellings of one number share: digits only, an international 00 read
     * as +, then the last N digits (setting caller_match_digits; 0 keeps them all up to 40).
     * Numbers shorter than N (extensions, short codes) are compared whole.
     */
    public static function matchKey(string $number, ?int $digits = null): string
    {
        $d = preg_replace('/\D/', '', $number) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        $digits ??= self::matchDigits();
        if ($digits > 0 && strlen($d) > $digits) {
            return substr($d, -$digits);
        }
        return strlen($d) > 40 ? substr($d, 0, 40) : $d;
    }

    /**
     * The name a phone shows for a contact: "Name - Company" when both are set, only what
     * a caller ID can carry (no quotes, angle brackets or dialplan metacharacters), 60 at most.
     */
    public static function callerIdName(array $contact): string
    {
        $name = trim((string) ($contact['display_name'] ?? ''));
        $company = trim((string) ($contact['company'] ?? ''));
        if ($company !== '' && $company !== $name) {
            $name = $name !== '' ? ($name . ' - ' . $company) : $company;
        }
        $name = preg_replace('/[\x00-\x1F\x7F"<>\\\\;$`{}\[\]:(),?]/u', '', $name) ?? '';
        return trim(mb_substr($name, 0, 60));
    }

    public static function matchDigits(): int
    {
        $v = (int) getSystemSetting('caller_match_digits', '10');
        return in_array($v, self::MATCH_DIGITS, true) ? $v : 10;
    }

    /** Change caller_match_digits and recompute every key. */
    public static function saveMatchDigits(int $digits): bool
    {
        if (!in_array($digits, self::MATCH_DIGITS, true)) {
            return false;
        }
        $db = getDB();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }
        try {
            $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('caller_match_digits', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([(string) $digits]);
            $up = $db->prepare('UPDATE phonebook_numbers SET match_key = ? WHERE id = ?');
            foreach ($db->query('SELECT id, number FROM phonebook_numbers')->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $up->execute([self::matchKey((string) $n['number'], $digits), (int) $n['id']]);
            }
            if ($ownTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
        return true;
    }

    /**
     * The contact a number belongs to (caller name, the "save to address book" button),
     * or null. Several contacts with the number: the manual one first, then the oldest.
     */
    public static function findByNumber(string $number): ?array
    {
        $key = self::matchKey($number);
        if (strlen($key) < 3) {
            return null;
        }
        self::syncNumbers();
        $st = getDB()->prepare("SELECT c.*, n.label AS matched_label FROM phonebook_numbers n
            JOIN phonebook_contacts c ON c.id = n.contact_id WHERE n.match_key = ?
            ORDER BY c.source = 'manual' DESC, c.id LIMIT 1");
        $st->execute([$key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::withNumbers([$row])[0] : null;
    }

    /**
     * Names for many numbers at once (the call list). @param list<string> $numbers
     * @return array<string, string> number => display name, only the ones found
     */
    public static function namesFor(array $numbers): array
    {
        $keys = [];
        foreach (array_unique($numbers) as $n) {
            $k = self::matchKey((string) $n);
            if (strlen($k) >= 3) {
                $keys[$k][] = (string) $n;
            }
        }
        if (!$keys) {
            return [];
        }
        self::syncNumbers();
        $st = getDB()->prepare('SELECT n.match_key, c.display_name FROM phonebook_numbers n JOIN phonebook_contacts c ON c.id = n.contact_id
            WHERE n.match_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ") ORDER BY c.source = 'manual', c.id DESC");
        $st->execute(array_map('strval', array_keys($keys)));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach ($keys[(string) $r['match_key']] ?? [] as $n) {
                $out[$n] = (string) $r['display_name']; // the last row wins: manual, oldest
            }
        }
        return $out;
    }

    /**
     * Index the numbers of rows that were never indexed, and of rows a plugin changed
     * since (plugins write the three phone columns only).
     */
    public static function syncNumbers(?int $contactId = null): void
    {
        $db = getDB();
        if ($contactId !== null) {
            $st = $db->prepare(
                "SELECT id, phone_work, phone_mobile, phone_other FROM phonebook_contacts
                 WHERE id = ? AND (numbers_indexed_at IS NULL OR (source <> 'manual' AND numbers_indexed_at <= updated_at))"
            );
            $st->execute([$contactId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Two index-backed queries instead of a single OR that triggers a full table scan.
            $rows = $db->query(
                "SELECT id, phone_work, phone_mobile, phone_other FROM phonebook_contacts
                 WHERE numbers_indexed_at IS NULL LIMIT 5000"
            )->fetchAll(PDO::FETCH_ASSOC);
            $rem = 5000 - count($rows);
            if ($rem > 0) {
                $more = $db->query(
                    "SELECT id, phone_work, phone_mobile, phone_other FROM phonebook_contacts
                     WHERE source <> 'manual' AND numbers_indexed_at IS NOT NULL AND numbers_indexed_at <= updated_at
                     LIMIT {$rem}"
                )->fetchAll(PDO::FETCH_ASSOC);
                if ($more) {
                    $rows = array_merge($rows, $more);
                }
            }
        }
        foreach ($rows as $r) {
            // null: the phone columns are read again under the row lock, so a plugin
            // update between this query and the lock is indexed, not overwritten.
            self::writeNumbers((int) $r['id'], null);
        }
    }

    /** @return list<array{label: string, number: string}> */
    private static function numbersFromColumns(array $in): array
    {
        $out = [];
        foreach (['phone_mobile' => 'mobile', 'phone_work' => 'work', 'phone_other' => 'other'] as $col => $label) {
            $n = self::cleanNumber((string) ($in[$col] ?? ''));
            if ($n !== '') {
                $out[] = ['label' => $label, 'number' => $n];
            }
        }
        return $out;
    }

    private static function cleanNumber(string $v): string
    {
        return mb_substr(trim(preg_replace('/[^0-9+*# ]/', '', $v) ?? ''), 0, 40);
    }

    /**
     * The numbers of a form or API call: numbers_label[] + numbers_value[], or
     * numbers = [{label, number}], or else the three phone fields.
     *
     * @return list<array{label: string, number: string}>
     */
    public static function numbersFromInput(array $in): array
    {
        $pairs = [];
        if (isset($in['numbers_value']) && is_array($in['numbers_value'])) {
            foreach (array_values($in['numbers_value']) as $i => $v) {
                $pairs[] = ['label' => (string) (($in['numbers_label'] ?? [])[$i] ?? 'other'), 'number' => (string) $v];
            }
        } elseif (isset($in['numbers']) && is_array($in['numbers'])) {
            foreach ($in['numbers'] as $n) {
                if (is_array($n)) {
                    $pairs[] = ['label' => (string) ($n['label'] ?? 'other'), 'number' => (string) ($n['number'] ?? '')];
                }
            }
        } else {
            return self::numbersFromColumns($in);
        }
        $out = [];
        $seen = [];
        foreach ($pairs as $p) {
            $n = self::cleanNumber($p['number']);
            $label = in_array($p['label'], self::LABELS, true) ? $p['label'] : 'other';
            if ($n === '' || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $out[] = ['label' => $label, 'number' => $n];
        }
        return array_slice($out, 0, self::MAX_NUMBERS);
    }

    /** @param list<array{label: string, number: string}>|null $numbers null: from the row's phone columns, read under its lock */
    private static function writeNumbers(int $contactId, ?array $numbers): void
    {
        $db = getDB();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }
        try {
            $lock = $db->prepare('SELECT phone_work, phone_mobile, phone_other FROM phonebook_contacts WHERE id = ? FOR UPDATE');
            $lock->execute([$contactId]);
            $row = $lock->fetch(PDO::FETCH_ASSOC);
            if ($numbers === null) {
                $numbers = is_array($row) ? self::numbersFromColumns($row) : [];
            }
            $db->prepare('DELETE FROM phonebook_numbers WHERE contact_id = ?')->execute([$contactId]);
            $ins = $db->prepare('INSERT INTO phonebook_numbers (contact_id, label, number, match_key, sort) VALUES (?, ?, ?, ?, ?)');
            $digits = self::matchDigits();
            foreach ($numbers as $i => $n) {
                $ins->execute([$contactId, $n['label'], $n['number'], self::matchKey($n['number'], $digits), $i]);
            }
            // Advance past updated_at so <= catches same-second plugin edits after older indexing without re-indexing endlessly.
            $db->prepare('UPDATE phonebook_contacts SET numbers_indexed_at = GREATEST(NOW(), COALESCE(updated_at, NOW()) + INTERVAL 1 SECOND), updated_at = updated_at WHERE id = ?')
                ->execute([$contactId]);
            if ($ownTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx) {
                try {
                    $db->rollBack();
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    /**
     * The three columns desk phones read over LDAP: the first mobile, the first work
     * number and the first of the rest.
     *
     * @param list<array{label: string, number: string}> $numbers
     * @return array{phone_work: string, phone_mobile: string, phone_other: string}
     */
    private static function columnsFromNumbers(array $numbers): array
    {
        $pick = function (array $labels) use ($numbers): string {
            foreach ($numbers as $n) {
                if (in_array($n['label'], $labels, true)) {
                    return $n['number'];
                }
            }
            return '';
        };
        $cols = ['phone_work' => $pick(['work', 'work2']), 'phone_mobile' => $pick(['mobile'])];
        $used = array_filter([$cols['phone_work'], $cols['phone_mobile']]);
        $cols['phone_other'] = '';
        foreach ($numbers as $n) {
            if (!in_array($n['number'], $used, true)) {
                $cols['phone_other'] = $n['number'];
                break;
            }
        }
        return $cols;
    }

    /** @return array<string, int> rows per source */
    public static function countsBySource(): array
    {
        $out = [];
        foreach (getDB()->query('SELECT source, COUNT(*) n FROM phonebook_contacts GROUP BY source')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['source']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Clean one contact: trimmed, cut to the column size, numbers keep only
     * digits, + * # and spaces. A contact needs a name and a number.
     *
     * @return array{0: array<string, mixed>|null, 1: string} the row (with 'numbers') or null, and the error key
     */
    public static function clean(array $in): array
    {
        $row = [];
        foreach (self::FIELDS as $f) {
            $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($in[$f] ?? '')) ?? '');
            if (str_starts_with($f, 'phone_')) {
                $v = trim(preg_replace('/[^0-9+*# ]/', '', $v) ?? '');
            }
            $row[$f] = mb_substr($v, 0, self::LIMITS[$f]);
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            return [null, 'phonebook.err_email'];
        }
        if ($row['display_name'] === '') {
            $row['display_name'] = trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['company'];
        }
        if ($row['display_name'] === '') {
            return [null, 'phonebook.err_name'];
        }
        $numbers = self::numbersFromInput($in);
        if (!$numbers) {
            return [null, 'phonebook.err_number'];
        }
        $row = array_merge($row, self::columnsFromNumbers($numbers));
        $row['numbers'] = $numbers;
        return [$row, ''];
    }

    /** Add (id 0) or change a manual contact. @return string error key, '' when saved */
    public static function save(int $id, array $in): string
    {
        return self::store($id, $in)[1];
    }

    /**
     * save() that also says which row: an external_id (CRM sync over the API) updates the
     * contact it was stored with instead of adding a second one.
     *
     * @return array{0: int, 1: string} the id (0 when refused) and the error key
     */
    public static function store(int $id, array $in): array
    {
        [$row, $err] = self::clean($in);
        if ($row === null) {
            return [0, $err];
        }
        $numbers = $row['numbers'];
        unset($row['numbers']);
        $db = getDB();
        $cols = self::FIELDS;
        $externalId = mb_substr(trim((string) ($in['external_id'] ?? '')), 0, 191);
        if ($id <= 0 && $externalId !== '') {
            $st = $db->prepare("SELECT id FROM phonebook_contacts WHERE source = 'manual' AND external_id = ?");
            $st->execute([$externalId]);
            $id = (int) $st->fetchColumn();
        }
        if ($id > 0) {
            if (!self::exists($id)) {
                return [0, 'common.action_failed'];
            }
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            $db->prepare("UPDATE phonebook_contacts SET $set WHERE id = ? AND source = 'manual'")->execute([...array_values($row), $id]);
        } else {
            $db->prepare('INSERT INTO phonebook_contacts (source, external_id, ' . implode(', ', $cols) . ") VALUES ('manual', ?, " . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute([$externalId !== '' ? $externalId : null, ...array_values($row)]);
            $id = (int) $db->lastInsertId();
        }
        self::writeNumbers($id, $numbers);
        return [$id, ''];
    }

    /**
     * Search for the API: a name, company or e-mail part, or a number (any spelling).
     *
     * @return array{0: list<array<string, mixed>>, 1: int} rows and the total
     */
    public static function search(string $q, int $limit, int $offset): array
    {
        self::syncNumbers();
        $db = getDB();
        $where = '1=1';
        $params = [];
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where = '(display_name LIKE ? OR company LIKE ? OR email LIKE ?';
            $params = [$like, $like, $like];
            $key = self::matchKey($q);
            if (strlen($key) >= 3 && preg_match('/^[0-9+()\s.-]+$/', $q)) {
                $where .= ' OR id IN (SELECT contact_id FROM phonebook_numbers WHERE match_key = ?)';
                $params[] = $key;
            }
            $where .= ')';
        }
        $st = $db->prepare("SELECT COUNT(*) FROM phonebook_contacts WHERE $where");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $st = $db->prepare("SELECT * FROM phonebook_contacts WHERE $where ORDER BY display_name, id LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $st->execute($params);
        return [self::withNumbers($st->fetchAll(PDO::FETCH_ASSOC) ?: []), $total];
    }

    private static function exists(int $id): bool
    {
        $st = getDB()->prepare("SELECT 1 FROM phonebook_contacts WHERE id = ? AND source = 'manual'");
        $st->execute([$id]);
        return (bool) $st->fetchColumn();
    }

    public static function delete(int $id): bool
    {
        $st = getDB()->prepare("DELETE FROM phonebook_contacts WHERE id = ? AND source = 'manual'");
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /**
     * CSV: name, company, work phone, mobile, other phone, e-mail (a header
     * row is skipped; ; or , as separator).
     *
     * @return array{0: int, 1: int} added, skipped
     */
    public static function importCsv(string $path): array
    {
        $h = @fopen($path, 'r');
        if (!$h) {
            return [0, 0];
        }
        $first = (string) fgets($h);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($h);
        $added = $skipped = 0;
        $line = 0;
        while (($r = fgetcsv($h, 4000, $sep, '"', '')) !== false) {
            $line++;
            if ($line === 1) {
                $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($r[0] ?? '')) ?? '';
                if (self::isCsvHeaderRow($r)) {
                    continue; // header
                }
            }
            if (count(array_filter($r, fn($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $in = array_combine(['display_name', 'company', 'phone_work', 'phone_mobile', 'phone_other', 'email'],
                array_pad(array_slice($r, 0, 6), 6, ''));
            if (self::save(0, $in) === '') {
                $added++;
            } else {
                $skipped++;
            }
        }
        fclose($h);
        return [$added, $skipped];
    }

    /**
     * Header rows sometimes contain digits (e.g. "Phone 1", "Phone 2"); skip
     * line 1 unless at least one phone column looks like a number without letters.
     *
     * @param array<int, string|null> $r
     */
    private static function isCsvHeaderRow(array $r): bool
    {
        $phoneCells = array_slice($r, 2, 3);
        $joinedPhones = implode('', $phoneCells);
        if (!preg_match('/[0-9]/', $joinedPhones)) {
            return true;
        }
        foreach ($phoneCells as $cell) {
            $cell = trim((string) $cell);
            if ($cell !== '' && !preg_match('/\p{L}/u', $cell) && preg_match('/[0-9]/', $cell)) {
                return false;
            }
        }
        return preg_match('/\p{L}/u', $joinedPhones) === 1;
    }

    // ---- LDAP server switch ----

    /** @return array{enabled: bool, anonymous: bool, password: string} */
    public static function ldapSettings(): array
    {
        $st = getDB()->query("SELECT setting_key, setting_value FROM sys_settings WHERE setting_key IN ('ldap_server_enabled', 'ldap_server_anonymous', 'ldap_server_password')");
        $s = $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        return [
            'enabled' => ($s['ldap_server_enabled'] ?? '0') === '1',
            'anonymous' => ($s['ldap_server_anonymous'] ?? '0') === '1',
            'password' => SecretBox::decrypt((string) ($s['ldap_server_password'] ?? '')),
        ];
    }

    /** Turning it on the first time makes the password phones sign in with. */
    public static function saveLdapSettings(bool $enabled, bool $anonymous, bool $newPassword = false): void
    {
        $pw = self::ldapSettings()['password'];
        if ($pw === '' || $newPassword) {
            $pw = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Km'), '=');
        }
        $st = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (['ldap_server_enabled' => $enabled ? '1' : '0', 'ldap_server_anonymous' => $anonymous ? '1' : '0',
            'ldap_server_password' => SecretBox::encrypt($pw)] as $k => $v) {
            $st->execute([$k, $v]);
        }
    }

    /** Is the chat service answering on the port (it follows the switch within 15 s)? */
    public static function listening(int $port): bool
    {
        // A closed port is an answer here, not an error: no warning for it.
        set_error_handler(static fn(): bool => true);
        try {
            $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        } finally {
            restore_error_handler();
        }
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }
}
