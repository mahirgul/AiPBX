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

    /** @return list<array<string, mixed>> */
    public static function contacts(): array
    {
        return getDB()->query('SELECT * FROM phonebook_contacts ORDER BY display_name, last_name, first_name LIMIT 20000')->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
     * Clean one contact: trimmed, cut to the column size, phones keep only
     * digits, + * # and spaces. A contact needs a name and a number.
     *
     * @return array{0: array<string, string>|null, 1: string} the row or null, and the error key
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
        if ($row['phone_work'] === '' && $row['phone_mobile'] === '' && $row['phone_other'] === '') {
            return [null, 'phonebook.err_number'];
        }
        return [$row, ''];
    }

    /** Add (id 0) or change a manual contact. @return string error key, '' when saved */
    public static function save(int $id, array $in): string
    {
        [$row, $err] = self::clean($in);
        if ($row === null) {
            return $err;
        }
        $db = getDB();
        $cols = self::FIELDS;
        if ($id > 0) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            $st = $db->prepare("UPDATE phonebook_contacts SET $set WHERE id = ? AND source = 'manual'");
            $st->execute([...array_values($row), $id]);
            return $st->rowCount() > 0 || self::exists($id) ? '' : 'common.action_failed';
        }
        $db->prepare('INSERT INTO phonebook_contacts (source, ' . implode(', ', $cols) . ") VALUES ('manual', " . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
        return '';
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
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }
}
