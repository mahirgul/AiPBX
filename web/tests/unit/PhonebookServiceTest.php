<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/services/PhonebookService.php';

final class PhonebookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        getDB()->exec("DELETE FROM phonebook_contacts WHERE source IN ('manual', 'test-sync')");
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ldap_server_%'");
    }

    public static function tearDownAfterClass(): void
    {
        getDB()->exec("DELETE FROM phonebook_contacts WHERE source IN ('manual', 'test-sync')");
        getDB()->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'ldap_server_%'");
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
}
