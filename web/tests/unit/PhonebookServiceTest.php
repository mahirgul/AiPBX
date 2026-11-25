<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/PhonebookService.php';

final class PhonebookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM phonebook_contacts WHERE source IN ('manual', 'test-sync')");
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ldap_server_%' OR setting_key = 'caller_match_digits'");
    }

    public static function tearDownAfterClass(): void
    {
        getDB()->exec("DELETE FROM phonebook_contacts WHERE source IN ('manual', 'test-sync')");
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ldap_server_%' OR setting_key = 'caller_match_digits'");
    }

    public function testAContactNeedsANameAndANumberAndIsCleaned(): void
    {
        $this->assertSame('phonebook.err_number', PhonebookService::clean(['display_name' => 'Acme'])[1]);
        $this->assertSame('phonebook.err_name', PhonebookService::clean(['phone_work' => '123'])[1]);
        $this->assertSame('phonebook.err_email', PhonebookService::clean(['display_name' => 'A', 'phone_work' => '1', 'email' => 'nope'])[1]);

        [$row] = PhonebookService::clean(['first_name' => ' Kemal ', 'last_name' => 'Kaya', 'phone_mobile' => '+90 (532) 111-22-33<x>']);
        $this->assertSame('Kemal Kaya', $row['display_name']);
        $this->assertSame('+90 532 1112233', $row['phone_mobile']);

        [$row] = PhonebookService::clean(['company' => 'Acme Ltd', 'phone_work' => '0212 555 12 34']);
        $this->assertSame('Acme Ltd', $row['display_name'], 'a company alone names the contact');
    }

    public function testOnlyManualContactsAreChangedOrDeletedHere(): void
    {
        $this->assertSame('', PhonebookService::save(0, ['display_name' => 'Supplier', 'phone_work' => '1234']));
        $db = getDB();
        $db->exec("INSERT INTO phonebook_contacts (source, external_id, display_name, phone_work) VALUES ('test-sync', 'x1', 'From AD', '999')");
        $synced = (int) $db->lastInsertId();

        $this->assertNotSame('', PhonebookService::save($synced, ['display_name' => 'Changed', 'phone_work' => '1']));
        $this->assertFalse(PhonebookService::delete($synced));
        $this->assertSame('From AD', $db->query("SELECT display_name FROM phonebook_contacts WHERE id = $synced")->fetchColumn());

        $manual = (int) $db->query("SELECT id FROM phonebook_contacts WHERE source = 'manual'")->fetchColumn();
        $this->assertSame('', PhonebookService::save($manual, ['display_name' => 'Supplier Ltd', 'phone_work' => '1234']));
        $this->assertSame('', PhonebookService::save($manual, ['display_name' => 'Supplier Ltd', 'phone_work' => '1234']), 'saving unchanged is fine');
        $this->assertTrue(PhonebookService::delete($manual));
        $this->assertSame(['test-sync' => 1], PhonebookService::countsBySource());
    }

    public function testCsvImportSkipsTheHeaderAndBadRows(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'pb');
        file_put_contents($csv, "\xEF\xBB\xBFname;company;work;mobile;other;email\n"
            . "Ayşe;Acme;0212 555 00 01;;;ayse@example.com\n"
            . "No Number;Acme;;;;\n"
            . ";;;;;\n"
            . "Mehmet;;;0532 000 00 02;;\n");
        try {
            $this->assertSame([2, 1], PhonebookService::importCsv($csv));
        } finally {
            unlink($csv);
        }
        $names = array_column(PhonebookService::contacts(), 'display_name');
        $this->assertSame(['Ayşe', 'Mehmet'], $names);
    }

    public function testCsvImportSkipsHeaderWithDigits(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'pb');
        file_put_contents($csv, "First Name,Last Name,Company,Phone 1,Phone 2,Other,Email\n"
            . "Ali Yılmaz,Acme,0212 555 00 03,0532 000 00 04,,ali@example.com\n");
        try {
            $this->assertSame([1, 0], PhonebookService::importCsv($csv));
        } finally {
            unlink($csv);
        }
        $names = array_column(PhonebookService::contacts(), 'display_name');
        $this->assertSame(['Ali Yılmaz'], $names);
    }

    public function testTurningLdapOnMakesAPasswordAndKeepsItEncrypted(): void
    {
        $this->assertFalse(PhonebookService::ldapSettings()['enabled']);
        PhonebookService::saveLdapSettings(true, false);
        $s = PhonebookService::ldapSettings();
        $this->assertTrue($s['enabled']);
        $this->assertFalse($s['anonymous']);
        $this->assertGreaterThanOrEqual(16, strlen($s['password']));
        $stored = (string) getDB()->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'ldap_server_password'")->fetchColumn();
        $this->assertStringStartsWith('sb1:', $stored);
        $this->assertStringNotContainsString($s['password'], $stored);

        PhonebookService::saveLdapSettings(false, true);
        $this->assertSame($s['password'], PhonebookService::ldapSettings()['password'], 'saving keeps the password');
        PhonebookService::saveLdapSettings(true, false, true);
        $this->assertNotSame($s['password'], PhonebookService::ldapSettings()['password']);
    }

    public function testAContactKeepsManyLabelledNumbersAndPhonesSeeThreeOfThem(): void
    {
        [$id, $err] = PhonebookService::store(0, [
            'display_name' => 'Ayse Demir',
            'numbers_label' => ['work', 'mobile', 'work2', 'home', 'fax', 'bogus'],
            'numbers_value' => ['0212 555 12 34', '+90 532 111 22 33', '0212 555 12 35', '0216 444 00 00', '0212 555 12 99', '0532 111 22 33'],
        ]);
        $this->assertSame('', $err);
        $c = PhonebookService::find($id);
        $this->assertCount(6, $c['numbers'], 'the same number in another spelling is still another row');
        $this->assertSame('other', $c['numbers'][5]['label'], 'an unknown label becomes other');
        $this->assertSame('0212 555 12 34', $c['phone_work']);
        $this->assertSame('+90 532 111 22 33', $c['phone_mobile']);
        $this->assertSame('0212 555 12 35', $c['phone_other'], 'the first number not shown yet');
    }

    public function testACallerIsFoundInAnySpelling(): void
    {
        [$id] = PhonebookService::store(0, ['display_name' => 'Kemal', 'numbers' => [['label' => 'mobile', 'number' => '+90 532 111 22 33']]]);
        foreach (['05321112233', '00905321112233', '+905321112233', '5321112233'] as $n) {
            $this->assertSame($id, (int) (PhonebookService::findByNumber($n)['id'] ?? 0), $n);
        }
        $this->assertNull(PhonebookService::findByNumber('05321112234'));
        $this->assertSame(['0532 111 22 33' => 'Kemal'], PhonebookService::namesFor(['0532 111 22 33', '999']));

        // Seven digits: a number from another area code now matches too.
        $this->assertTrue(PhonebookService::saveMatchDigits(7));
        $this->assertSame($id, (int) (PhonebookService::findByNumber('0212 111 22 33')['id'] ?? 0));
        $this->assertTrue(PhonebookService::saveMatchDigits(0));
        $this->assertNull(PhonebookService::findByNumber('05321112233'), 'the whole number: 0 532... is not +90 532...');
        $this->assertNotNull(PhonebookService::findByNumber('0090 532 111 22 33'));
        $this->assertFalse(PhonebookService::saveMatchDigits(3));

        // 40-digit whole-number match_key must fit VARCHAR(40) and cap longer inputs cleanly.
        $forty = str_repeat('1234567890', 4);
        $fifty = $forty . '9999999999';
        $this->assertSame(40, strlen(PhonebookService::matchKey($fifty, 0)));
        [$longId, $longErr] = PhonebookService::store(0, ['display_name' => 'Long Number', 'phone_work' => $forty]);
        $this->assertSame('', $longErr);
        $this->assertSame($longId, (int) (PhonebookService::findByNumber($forty)['id'] ?? 0));
    }

    public function testRowsAPluginWritesAreIndexedToo(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO phonebook_contacts (source, external_id, display_name, phone_mobile) VALUES ('test-sync', 'x2', 'From AD', '0532 999 88 77')");
        $this->assertSame('From AD', PhonebookService::findByNumber('+90 532 999 88 77')['display_name'] ?? null);
        $db->exec("UPDATE phonebook_contacts SET phone_mobile = '0532 999 88 66', updated_at = NOW() + INTERVAL 1 SECOND WHERE external_id = 'x2'");
        $this->assertNull(PhonebookService::findByNumber('05329998877'), 'the plugin changed the number');
        $this->assertNotNull(PhonebookService::findByNumber('05329998866'));

        // Calling syncNumbers() again when nothing changed must not loop/re-index.
        $before = (string) $db->query("SELECT numbers_indexed_at FROM phonebook_contacts WHERE external_id = 'x2'")->fetchColumn();
        PhonebookService::syncNumbers();
        $after = (string) $db->query("SELECT numbers_indexed_at FROM phonebook_contacts WHERE external_id = 'x2'")->fetchColumn();
        $this->assertSame($before, $after);

        // find() indexes an unindexed plugin row for that single contact.
        $db->exec("INSERT INTO phonebook_contacts (source, external_id, display_name, phone_work) VALUES ('test-sync', 'x3', 'Single Find AD', '0212 888 77 66')");
        $singleId = (int) $db->lastInsertId();
        $found = PhonebookService::find($singleId);
        $this->assertNotNull($found);
        $this->assertCount(1, $found['numbers']);
        $this->assertSame('0212 888 77 66', $found['numbers'][0]['number']);
    }

    public function testAnExternalIdUpdatesInsteadOfAddingTwice(): void
    {
        [$a] = PhonebookService::store(0, ['display_name' => 'CRM One', 'external_id' => 'crm-7', 'phone_work' => '0212 000 00 01']);
        [$b] = PhonebookService::store(0, ['display_name' => 'CRM One Ltd', 'external_id' => 'crm-7', 'phone_work' => '0212 000 00 02']);
        $this->assertSame($a, $b);
        $this->assertSame('CRM One Ltd', PhonebookService::find($a)['display_name']);
        [$rows, $total] = PhonebookService::search('0212 000 00 02', 10, 0);
        $this->assertSame(1, $total);
        $this->assertSame($a, (int) $rows[0]['id']);
        $this->assertSame(0, PhonebookService::search('0212 000 00 01', 10, 0)[1], 'the old number is gone');
    }

    public function testTheCallerIdNameCarriesNothingADialplanCouldTrip(): void
    {
        $this->assertSame('Ayse - ACME Ltd', PhonebookService::callerIdName(['display_name' => 'Ayse', 'company' => 'ACME Ltd']));
        $this->assertSame('ACME', PhonebookService::callerIdName(['display_name' => 'ACME', 'company' => 'ACME']));
        $this->assertSame('BadName f', PhonebookService::callerIdName(['display_name' => "Bad\"<Name>\\; \${f}:(),?\n", 'company' => '']));
        $this->assertSame(60, mb_strlen(PhonebookService::callerIdName(['display_name' => str_repeat('ş', 80)])));
    }

    public function testPhonebookNameEndpointRejectsProxyHeadersStripsNewlinesAndSkipsSession(): void
    {
        $contact = ['display_name' => "Ayse\r\n", 'company' => 'ACME', 'phone_mobile' => '0532 111 22 33'];
        [$id] = PhonebookService::store(0, $contact, true);
        PhonebookService::store($id, $contact);
        $envFile = dirname(__DIR__) . '/.env.test';
        $endpoint = dirname(__DIR__, 2) . '/api/phonebook_name.php';

        $run = static function (array $server, string $num, bool $includeVip = false) use ($envFile, $endpoint): array {
            $code = '<?php
foreach (file(' . var_export($envFile, true) . ', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line !== "" && $line[0] !== "#" && strpos($line, "=") !== false) putenv($line);
}
$_SERVER = array_merge($_SERVER, ' . var_export($server, true) . ');
$_GET["num"] = ' . var_export($num, true) . ';
$_GET["include_vip"] = ' . var_export($includeVip ? '1' : '0', true) . ';
register_shutdown_function(static function () {
    echo "\n__META__:" . json_encode(["status" => http_response_code() ?: 200, "session" => session_status()]);
});
require ' . var_export($endpoint, true) . ';';
            $proc = proc_open('php', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $code);
            fclose($pipes[0]);
            $out = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            [$body, $metaRaw] = explode("\n__META__:", $out, 2);
            return ['body' => $body, 'meta' => json_decode($metaRaw, true)];
        };

        $ok = $run(['REMOTE_ADDR' => '127.0.0.1'], '+905321112233');
        $this->assertSame(200, $ok['meta']['status']);
        $this->assertSame(PHP_SESSION_NONE, $ok['meta']['session']);
        $this->assertSame('NAME:Ayse - ACME', $ok['body'], 'no trailing newline and CR/LF stripped');
        $this->assertSame('VIP:Ayse - ACME', $run(['REMOTE_ADDR' => '127.0.0.1'], '+905321112233', true)['body']);
        PhonebookService::store($id, $contact, false);
        $this->assertSame('NAME:Ayse - ACME', $run(['REMOTE_ADDR' => '127.0.0.1'], '+905321112233', true)['body']);

        $miss = $run(['REMOTE_ADDR' => '127.0.0.1'], '05320000000');
        $this->assertSame('NONE', $miss['body']);

        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_PROTO'] as $hdr) {
            $blocked = $run(['REMOTE_ADDR' => '127.0.0.1', $hdr => '10.0.0.1'], '+905321112233');
            $this->assertSame(403, $blocked['meta']['status'], $hdr);
            $this->assertSame('NONE', $blocked['body'], $hdr);
        }
    }

    public function testExternalDbSettingsAndUpsertSync(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO pbx_external_dbs (name, driver, host, port, database_name, username, is_active, allow_write)
                       VALUES ('zz_crm', 'mysql', '127.0.0.1', 3306, 'crm', 'u', 1, 0)");
            $dbId = (int) $db->lastInsertId();
            $queryKey = 'zz_pb_query_' . $dbId;

            $sql = 'SELECT id AS external_id, name AS display_name, company, phone AS phone_work FROM customers';
            $query = [
                'query_key' => $queryKey, 'description' => 'Phonebook import', 'external_db_id' => $dbId,
                'query_sql' => $sql, 'access_mode' => 'public', 'is_active' => 1,
            ];
            $queryId = ExternalDbQueryService::save($query);
            PhonebookService::saveExternalDbSettings($dbId, $sql, true, $queryKey);
            $cfg = PhonebookService::externalDbSettings();
            $this->assertSame($dbId, $cfg['db_id']);
            $this->assertSame($sql, $cfg['sql']);
            $this->assertTrue($cfg['enabled']);
            $this->assertSame($queryKey, $cfg['query_key']);

            ExternalDbQueryService::save(['id' => $queryId, 'is_active' => 0] + $query);
            $disabled = PhonebookService::externalDbSettings();
            $this->assertSame(0, $disabled['db_id']);
            $this->assertSame('', $disabled['sql']);
            $this->assertFalse($disabled['enabled']);

            PhonebookService::saveExternalDbSettings($dbId, $sql, true);
            $this->assertTrue(PhonebookService::externalDbSettings()['enabled'], 'Legacy imports without a query key still work.');

            $res1 = PhonebookService::importExternalRows([
                ['external_id' => 'cust-100', 'display_name' => 'Veli Kaya', 'company' => 'Beta Ltd', 'phone_work' => '0212 555 00 01'],
                ['external_id' => 'cust-101', 'display_name' => '', 'phone_work' => ''],
            ]);
            $this->assertSame(['added' => 1, 'updated' => 0, 'skipped' => 1], $res1);
            $this->assertSame('Veli Kaya', PhonebookService::findByNumber('02125550001')['display_name'] ?? null);

            $res2 = PhonebookService::importExternalRows([
                ['external_id' => 'cust-100', 'display_name' => 'Veli Kaya (VIP)', 'company' => 'Beta AS', 'phone_work' => '0212 555 00 99'],
            ]);
            $this->assertSame(['added' => 0, 'updated' => 1, 'skipped' => 0], $res2);
            $this->assertSame('Veli Kaya (VIP)', PhonebookService::findByNumber('02125550099')['display_name'] ?? null);

            $this->expectException(InvalidArgumentException::class);
            PhonebookService::saveExternalDbSettings($dbId, 'DELETE FROM customers', false);
        } finally {
            $db->rollBack();
        }
    }

    public function testCustomerIdCreateUpdateAndSearch(): void
    {
        [$id, $err] = PhonebookService::store(0, [
            'customer_id' => 'CUST-ABC-01',
            'display_name' => 'Zeynep Acar',
            'phone_mobile' => '0533 222 33 44',
        ]);
        $this->assertSame('', $err);
        $found = PhonebookService::findByNumber('+905332223344');
        $this->assertNotNull($found);
        $this->assertSame('CUST-ABC-01', $found['customer_id']);
        $this->assertSame('CUST-ABC-01', $found['external_id']);

        [$updatedId, $uErr] = PhonebookService::store($id, [
            'external_id' => 'CUST-ABC-02',
            'display_name' => 'Zeynep Acar',
            'phone_mobile' => '0533 222 33 44',
        ]);
        $this->assertSame('', $uErr);
        $this->assertSame($id, $updatedId);
        $this->assertSame('CUST-ABC-02', PhonebookService::find($id)['customer_id'] ?? null);

        [$rows, $total] = PhonebookService::search('CUST-ABC-02', 10, 0);
        $this->assertSame(1, $total);
        $this->assertSame($id, (int) $rows[0]['id']);

        [$afmId, $afmErr] = PhonebookService::store(0, [
            'display_name' => 'Mehmet Bey',
            'company' => 'AFM Şirketi',
            'sub_extension' => '1000#w',
            'phone_work' => '0212 444 00 10',
        ]);
        $this->assertSame('', $afmErr);
        $afm = PhonebookService::find($afmId);
        $this->assertSame('1000#w', $afm['sub_extension'] ?? null);

        [$multiRows, $multiTotal] = PhonebookService::search('AFM Mehmet', 10, 0);
        $this->assertSame(1, $multiTotal);
        $this->assertSame('1000#w', $multiRows[0]['sub_extension'] ?? null);
    }
}
