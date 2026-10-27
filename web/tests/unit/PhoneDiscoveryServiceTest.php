<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/services/PhoneDiscoveryService.php';

/**
 * Phones found on the network: the pool policy and the one rule that matters.
 *
 * The tests that carry the safety argument are
 * testAnAlreadyProvisionedPhoneIsNeverProvisionedAgain() and
 * testIgnoredPhonesStayIgnored(): if either ever goes red, an automatic run has
 * started touching equipment somebody already configured, which is the one
 * thing this module must not do.
 */
final class PhoneDiscoveryServiceTest extends TestCase
{
    private const MAC = '001565aabbcc';
    private const MAC2 = '000b82112233';

    /** CSRF helper: these actions go through PBXHelper::handleAction like the page does. */
    private const CSRF = 'phonedisc-test-token';

    protected function setUp(): void
    {
        Fixtures::load();
        $_SESSION = ['csrf_token' => self::CSRF, 'user_id' => null];
        $db = getDB();
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['pbx_phone_discoveries', 'pbx_phones', 'pbx_phones_waiting'] as $table) {
            $db->exec("TRUNCATE TABLE `{$table}`");
        }
        $db->exec("DELETE FROM sys_users WHERE username LIKE 'phonedisc%'");
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'provision_discovery%'");
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    // ------------------------------------------------------------ fixtures

    /** A SIP user with an extension (the pool's material). */
    private function user(string $extension, string $name = 'Test User', bool $active = true, string $type = 'sip'): int
    {
        $stmt = getDB()->prepare(
            'INSERT INTO sys_users (username, password_hash, full_name, extension, extension_type, is_active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $username = 'phonedisc' . $extension;
        $stmt->execute([$username, 'x', $name, $extension, $type, $active ? 1 : 0]);
        return (int) getDB()->lastInsertId();
    }

    private function discovery(string $mac, string $vendor = 'yealink', string $status = 'new', string $seen = 'NOW()'): int
    {
        // $seen is one of two literals this test uses, never user input.
        getDB()->prepare(
            "INSERT INTO pbx_phone_discoveries (mac, ip, vendor, source, status, seen_count, first_seen_at, last_seen_at)
             VALUES (?, '192.168.10.21', ?, 'arp', ?, 3, NOW(), {$seen})"
        )->execute([PhoneModels::normalizeMac($mac), $vendor, $status]);
        return (int) getDB()->lastInsertId();
    }

    private function phone(string $mac, string $model = 'yealink-t46u', int $userId = 0): int
    {
        getDB()->prepare(
            'INSERT INTO pbx_phones (mac, model, user_id, token, admin_password) VALUES (?, ?, ?, ?, ?)'
        )->execute([PhoneModels::normalizeMac($mac), $model, $userId ?: null, bin2hex(random_bytes(8)), '']);
        return (int) getDB()->lastInsertId();
    }

    private function setting(string $key, string $value): void
    {
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
    }

    private function discoverState(string $mac): string
    {
        $stmt = getDB()->prepare('SELECT status FROM pbx_phone_discoveries WHERE mac = ?');
        $stmt->execute([PhoneModels::normalizeMac($mac)]);
        return (string) $stmt->fetchColumn();
    }

    // -------------------------------------------------------------- parsing

    public function testPoolRangesAndSingles(): void
    {
        $bad = [];
        $pool = PhoneDiscoveryService::parsePool("5000-5002, 5004\n5005", $bad);
        $this->assertSame(['5000', '5001', '5002', '5004', '5005'], $pool);
        $this->assertSame([], $bad, 'a range and single extensions are both valid');
    }

    public function testPoolRejectsWhatIsNotAnExtension(): void
    {
        $bad = [];
        $pool = PhoneDiscoveryService::parsePool('5000-5099, abc, 5, 9000-1000, 12', $bad);
        $this->assertSame(['abc', '5', '9000-1000'], $bad, 'a backwards range and single digits are refused');
        $this->assertCount(101, $pool, 'the valid entries are kept: 5000-5099 and 12');
        $this->assertContains('12', $pool, 'a two-digit extension is an extension');
        $this->assertContains('5099', $pool, 'the whole range is expanded');
    }

    public function testPoolKeepsTheAdministratorsOrderAndDropsDuplicates(): void
    {
        $this->assertSame(['5001', '5000'], PhoneDiscoveryService::parsePool('5001,5000,5001 5000'),
            'the order the administrator wrote is the order the pool is used in');
    }

    public function testModelsNeedAVendorAndACatalogModel(): void
    {
        $bad = [];
        $models = PhoneDiscoveryService::parseModels("yealink=yealink-t46u\ngrandstream=grandstream-grp2612", $bad);
        $this->assertSame(['yealink' => 'yealink-t46u', 'grandstream' => 'grandstream-grp2612'], $models);
        $this->assertSame([], $bad);

        $bad = [];
        $models = PhoneDiscoveryService::parseModels("nokia=nokia-e61\ncisco=yealink-t46u\nsnom", $bad);
        $this->assertSame([], $models);
        $this->assertSame(['nokia=nokia-e61', 'cisco=yealink-t46u', 'snom'], $bad,
            'an unknown vendor, a model of another vendor and a missing model are all refused');
    }

    public function testStateOfAnAlreadyProvisionedPhoneWinsOverItsStatus(): void
    {
        $this->assertSame('provisioned', PhoneDiscoveryService::stateOf(['status' => 'new', 'live_phone_id' => 7]));
        $this->assertSame('ignored', PhoneDiscoveryService::stateOf(['status' => 'ignored']));
        $this->assertSame('deleted', PhoneDiscoveryService::stateOf(['status' => 'provisioned']));
        $this->assertSame('new', PhoneDiscoveryService::stateOf(['status' => 'new']));
    }

    /**
     * The header badge and the row badges are two readings of the same table,
     * so they have to agree.
     *
     * summary() classifies rows with stateOf(), which looks for the joined
     * phone under the name `live_phone_id`. Its own query used to alias the
     * column `live` — a row with a phone then lost that column, fell through to
     * its status, and a phone added by hand while the feature was off was
     * counted as "new" forever while its own row said "provisioned". The
     * comparison below fails on any such mismatch, whichever side drifts.
     */
    public function testSummaryCountsWhatTheRowBadgesShow(): void
    {
        // Not a phone: the only row that is genuinely new.
        $this->discovery(self::MAC);
        // A MAC added by hand while its row was still 'new'. reconcile() runs
        // only inside a configured automatic run, so with the feature off this
        // is the state the panel opens on.
        $this->phone(self::MAC2);
        $this->discovery(self::MAC2);
        // The same, and ignored as well: the phone that exists still wins.
        $this->phone('001565aabbcd');
        $this->discovery('001565aabbcd', 'yealink', 'ignored');
        // Provisioned, and that phone has since been deleted from the list.
        $this->discovery('000b82112234', 'grandstream', 'provisioned');

        $shown = ['new' => 0, 'ignored' => 0, 'provisioned' => 0, 'deleted' => 0];
        foreach (PhoneDiscoveryService::listDiscovered() as $row) {
            $shown[$row['state']]++;
        }

        $summary = PhoneDiscoveryService::summary();
        $this->assertSame(1, $summary['new'], 'only the MAC that is not a phone is new');
        $this->assertSame(2, $summary['provisioned'], 'a phone that exists makes its row provisioned, whatever its status says');
        $this->assertSame(0, $summary['ignored'], 'an ignored row that is now a phone is provisioned, not ignored');
        $this->assertSame(1, $summary['deleted'], 'a provisioned row whose phone is gone is deleted');
        $this->assertSame($shown, $summary, 'the header badge counts the states the rows show');
    }

    // ----------------------------------------------------- the automatic step

    public function testNothingIsProvisionedWithoutAPool(): void
    {
        $this->user('5100');
        $this->discovery(self::MAC);
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $result = PhoneDiscoveryService::provisionFromPool();
        $this->assertSame([], $result['created'], 'no pool means no phone is created');
        $this->assertSame(0, (int) getDB()->query('SELECT COUNT(*) FROM pbx_phones')->fetchColumn());
        $this->assertSame('new', $this->discoverState(self::MAC), 'the row stays a candidate for a human');
    }

    public function testNothingIsProvisionedWithoutAModelForThatVendor(): void
    {
        $this->user('5100');
        $this->discovery(self::MAC);
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'grandstream=grandstream-grp2612');

        $result = PhoneDiscoveryService::provisionFromPool();
        $this->assertSame([], $result['created']);
        $this->assertStringContainsString('yealink', implode(' ', $result['skipped']));
    }

    public function testAPoolExtensionWithAPhoneIsNotTaken(): void
    {
        $taken = $this->user('5100');
        $this->user('5101');
        $this->phone('c4fc22000001', 'yealink-t46u', $taken);   // 5100 already has a phone
        $this->discovery(self::MAC);
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5101');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $result = PhoneDiscoveryService::provisionFromPool();
        $this->assertCount(1, $result['created']);
        $row = getDB()->query('SELECT p.user_id, u.extension FROM pbx_phones p JOIN sys_users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('5101', $row['extension'], 'the extension whose phone already exists is skipped');
    }

    public function testAnAlreadyProvisionedPhoneIsNeverProvisionedAgain(): void
    {
        $user = $this->user('5100');
        $existing = $this->phone(self::MAC, 'yealink-t48u', $user);
        $this->discovery(self::MAC);
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $result = PhoneDiscoveryService::provisionFromPool();

        $this->assertSame([], $result['created']);
        $this->assertSame(1, (int) getDB()->query('SELECT COUNT(*) FROM pbx_phones')->fetchColumn(),
            'the existing phone was not duplicated');
        $row = getDB()->query('SELECT model, user_id FROM pbx_phones WHERE mac = ' . getDB()->quote(self::MAC))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('yealink-t48u', $row['model'], 'its model was not overwritten');
        $this->assertSame($user, (int) $row['user_id'], 'its extension was not overwritten');
        $this->assertSame('provisioned', $this->discoverState(self::MAC));
        $stmt = getDB()->prepare('SELECT phone_id FROM pbx_phone_discoveries WHERE mac = ?');
        $stmt->execute([self::MAC]);
        $this->assertSame($existing, (int) $stmt->fetchColumn(), 'the row points at the phone that already existed');
    }

    public function testTheApproveButtonRefusesAnAlreadyProvisionedPhoneToo(): void
    {
        $this->phone(self::MAC);
        $id = $this->discovery(self::MAC);
        $result = PhoneDiscoveryService::approve($id, 'yealink-t46u', 0, self::CSRF);
        $this->assertFalse($result['success'], 'a manual approve cannot re-provision a provisioned phone');
        $this->assertSame(1, (int) getDB()->query('SELECT COUNT(*) FROM pbx_phones')->fetchColumn());
    }

    public function testIgnoredPhonesStayIgnored(): void
    {
        $this->user('5100');
        $this->discovery(self::MAC, 'yealink', 'ignored');
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $result = PhoneDiscoveryService::provisionFromPool();
        $this->assertSame([], $result['created']);
        $this->assertSame('ignored', $this->discoverState(self::MAC));
    }

    public function testAPhoneNotSeenForADayIsNotProvisionedOnItsOwn(): void
    {
        $this->user('5100');
        $this->discovery(self::MAC, 'yealink', 'new', '(NOW() - INTERVAL 3 DAY)');
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $this->assertSame([], PhoneDiscoveryService::provisionFromPool()['created']);
    }

    public function testAConfiguredRunProvisionsFromThePoolAndMarksTheRow(): void
    {
        $user = $this->user('5100');
        $id = $this->discovery(self::MAC);
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $result = PhoneDiscoveryService::provisionFromPool();

        $this->assertCount(1, $result['created']);
        $this->assertSame('provisioned', $this->discoverState(self::MAC));
        $row = getDB()->query('SELECT * FROM pbx_phones')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(self::MAC, $row['mac']);
        $this->assertSame('yealink-t46u', $row['model']);
        $this->assertSame($user, (int) $row['user_id'], 'the phone took the pool extension');
        $this->assertNotSame('', (string) $row['token'], 'it went through the normal provisioning path');
        $stmt = getDB()->prepare('SELECT phone_id FROM pbx_phone_discoveries WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertSame((int) $row['id'], (int) $stmt->fetchColumn());
    }

    public function testThePoolStopsTheRunWhenItIsFull(): void
    {
        $this->user('5100');
        $first = $this->discovery(self::MAC);
        $second = $this->discovery(self::MAC2, 'grandstream');
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5100');
        $this->setting('provision_discovery_models', "yealink=yealink-t46u\ngrandstream=grandstream-grp2612");

        $result = PhoneDiscoveryService::provisionFromPool();

        $this->assertCount(1, $result['created'], 'one free extension, one phone');
        $this->assertSame('provisioned', $this->discoverState(self::MAC));
        $this->assertSame('new', $this->discoverState(self::MAC2), 'the second phone waits, it is not lost');
        $this->assertNotSame($first, $second);
    }

    public function testAPhoneAddedByHandIsReconciledIntoTheRecord(): void
    {
        // The engine saw the MAC first, then somebody used the + form: the row
        // must stop being an automatic candidate even though the engine still
        // reports the same MAC (it never rewrites portal columns itself).
        $id = $this->discovery(self::MAC);
        $this->phone(self::MAC);
        $this->setting('provision_discovery', '1');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $result = PhoneDiscoveryService::provisionFromPool();

        $this->assertSame(1, $result['reconciled']);
        $this->assertSame('provisioned', $this->discoverState(self::MAC));
        $this->assertSame([], $result['created'], 'and it is not duplicated');
        $this->assertSame([], $result['skipped'], 'it is not even a candidate any more');
        $this->assertSame(1, (int) getDB()->query('SELECT COUNT(*) FROM pbx_phones')->fetchColumn());
        $stmt = getDB()->prepare('SELECT phone_id FROM pbx_phone_discoveries WHERE id = ?');
        $stmt->execute([$id]);
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn());
    }

    public function testReconcileLeavesAFreeRowAlone(): void
    {
        $this->discovery(self::MAC);
        $this->assertSame(0, PhoneDiscoveryService::reconcile());
        $this->assertSame('new', $this->discoverState(self::MAC));
    }

    public function testDiscoveryOffStopsEvenAConfiguredRun(): void
    {
        $this->user('5100');
        $this->discovery(self::MAC);
        $this->setting('provision_discovery', '0');
        $this->setting('provision_discovery_pool', '5100-5109');
        $this->setting('provision_discovery_models', 'yealink=yealink-t46u');

        $this->assertSame([], PhoneDiscoveryService::provisionFromPool()['created']);
    }

    public function testPoolFreeCountsOnlyExtensionsWithoutAPhone(): void
    {
        $taken = $this->user('5100');
        $this->user('5101');
        $this->user('5102', 'Test User', false);          // inactive
        $this->user('5103', 'Fax User', true, 'fax');     // not a SIP extension
        $this->phone('c4fc22000001', 'yealink-t46u', $taken);

        $this->assertSame(1, PhoneDiscoveryService::poolFree(PhoneDiscoveryService::parsePool('5100-5103')));
    }

    public function testDefaultModelForVendorPrefersTheConfiguredOne(): void
    {
        $this->setting('provision_discovery_models', 'yealink=yealink-t48u');
        $this->assertSame('yealink-t48u', PhoneDiscoveryService::defaultModelForVendor('yealink'));
        // Not configured: the first catalog model of that vendor, for the form's default.
        $this->assertSame('grandstream-grp2612', PhoneDiscoveryService::defaultModelForVendor('grandstream'));
        $this->assertSame('', PhoneDiscoveryService::defaultModelForVendor('nokia'));
    }

    public function testTheSettingsAreSavedAndChecked(): void
    {
        PhoneDiscoveryService::saveDiscoverySettings([
            'provision_discovery' => '1',
            'provision_discovery_sweep' => '1',
            'provision_discovery_networks' => "192.168.10.0/24\n192.168.11.5",
            'provision_discovery_pool' => '5000-5001',
            'provision_discovery_models' => 'yealink=yealink-t46u',
        ]);
        $settings = PhoneDiscoveryService::settings();
        $this->assertSame('1', $settings['provision_discovery']);
        $this->assertSame('1', $settings['provision_discovery_sweep']);
        $this->assertSame("192.168.10.0/24\n192.168.11.5", $settings['provision_discovery_networks']);
        $this->assertSame('5000,5001', $settings['provision_discovery_pool']);

        $this->expectException(Exception::class);
        PhoneDiscoveryService::saveDiscoverySettings(['provision_discovery_pool' => 'not-a-range']);
    }
}
