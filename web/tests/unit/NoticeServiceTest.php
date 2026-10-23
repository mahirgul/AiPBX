<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/NoticeService.php';

/** The top bar's bell: open problems, recent changes, "new" per user. */
final class NoticeServiceTest extends TestCase
{
    private const USER = 990001;

    protected function setUp(): void
    {
        $db = getDB();
        $db->exec('DELETE FROM sys_notices');
        $db->exec('DELETE FROM health_checks');
        $db->exec('DELETE FROM health_events');
        $db->exec("DELETE FROM sys_settings WHERE setting_key LIKE 'notices_seen_u%' OR setting_key IN ('health_recipients', 'health_extra_emails')");
    }

    private function keys(array $items): array
    {
        return array_column($items, 'key');
    }

    public function testARaisedProblemIsOpenUntilResolved(): void
    {
        NoticeService::raise('cq:trunk1', 'warn', 'Trunk 1 loses media', '6 % loss', '/plugin/call-quality', 'call-quality');
        $b = NoticeService::forUser(self::USER);
        $this->assertContains('notice:cq:trunk1', $this->keys($b['open']));
        $this->assertSame('warn', $b['worst']);
        $this->assertNotContains('notice:cq:trunk1', $this->keys($b['recent']), 'not listed twice while open');

        NoticeService::resolve('cq:trunk1');
        $b = NoticeService::forUser(self::USER);
        $this->assertNotContains('notice:cq:trunk1', $this->keys($b['open']));
        $recent = array_values(array_filter($b['recent'], fn($i) => $i['key'] === 'notice:cq:trunk1'));
        $this->assertSame('ok', $recent[0]['severity'], 'the fix shows as a recent change');
    }

    public function testRaisingAgainKeepsOneRowAndItsFirstTime(): void
    {
        NoticeService::raise('k', 'warn', 'T', 'one');
        getDB()->exec("UPDATE sys_notices SET raised_at = '2026-01-01 00:00:00'");
        NoticeService::raise('k', 'fail', 'T', 'two');
        $row = getDB()->query("SELECT * FROM sys_notices WHERE notice_key = 'k'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-01-01 00:00:00', $row['raised_at']);
        $this->assertSame('fail', $row['severity']);
        $this->assertSame('two', $row['message']);
        $this->assertSame(1, (int) getDB()->query('SELECT COUNT(*) FROM sys_notices')->fetchColumn());
    }

    public function testOnlyPortalPathsAreKeptAsLinks(): void
    {
        NoticeService::raise('a', 'warn', 'T', '', 'javascript:alert(1)');
        NoticeService::raise('b', 'warn', 'T', '', 'https://evil.example/');
        NoticeService::raise('c', 'warn', 'T', '', '/trunks?id=3');
        $links = getDB()->query('SELECT notice_key, link FROM sys_notices ORDER BY notice_key')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['a' => '', 'b' => '', 'c' => '/trunks?id=3'], $links);
    }

    public function testFailedHealthCheckIsOpenAndFailedComesFirst(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO health_checks (check_key, status, message, checked_at, changed_at) VALUES
            ('disk', 'warn', '/ 90 %', NOW(), NOW() - INTERVAL 2 HOUR),
            ('trunks', 'fail', 'nectip unreachable', NOW(), NOW()),
            ('memory', 'ok', 'fine', NOW(), NOW())");
        $b = NoticeService::forUser(self::USER);
        $keys = $this->keys($b['open']);
        $this->assertSame('health:trunks', $keys[0]);
        $this->assertContains('health:disk', $keys);
        $this->assertNotContains('health:memory', $keys);
        $this->assertSame('fail', $b['worst']);
    }

    public function testASwitchedOffCheckIsNotShown(): void
    {
        getDB()->exec("INSERT INTO health_checks (check_key, interval_min, status, message, checked_at, changed_at) VALUES ('disk', 0, 'fail', 'x', NOW(), NOW())");
        $this->assertNotContains('health:disk', $this->keys(NoticeService::forUser(self::USER)['open']));
    }

    public function testNoRecipientsAndStoppedChecksAreProblemsOfTheirOwn(): void
    {
        $checks = HealthService::checks();
        $keys = $this->keys(NoticeService::alarmPath($checks, 3600));
        $this->assertContains('alarm:checks_stopped', $keys);
        $this->assertContains('alarm:no_recipients', $keys);

        getDB()->exec("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('health_extra_emails', 'ops@example.com')");
        $this->assertSame([], NoticeService::alarmPath($checks, 60));
        $this->assertSame([], NoticeService::alarmPath($checks, null), 'a fresh install has no result yet');
    }

    public function testNewCountsChangesSinceTheUserLastLooked(): void
    {
        NoticeService::raise('a', 'warn', 'A');
        $this->assertGreaterThan(0, NoticeService::forUser(self::USER)['new']);
        getDB()->exec("UPDATE sys_notices SET raised_at = NOW() - INTERVAL 1 MINUTE, updated_at = NOW() - INTERVAL 1 MINUTE");
        NoticeService::markSeen(self::USER);
        $this->assertSame(0, NoticeService::forUser(self::USER)['new']);
        $this->assertGreaterThan(0, NoticeService::forUser(self::USER + 1)['new'], 'another administrator has not looked yet');
    }

    public function testProblemsOfADisabledPluginAreNotShown(): void
    {
        NoticeService::raise('x:1', 'warn', 'From a plugin', '', '', 'no-such-plugin-installed');
        $this->assertContains('notice:x:1', $this->keys(NoticeService::forUser(self::USER)['open']), 'unknown source: shown');
        $plugins = PluginManager::allPlugins();
        $off = array_values(array_filter($plugins, fn($p) => !$p['enabled']));
        if (!$off) {
            $this->markTestSkipped('no disabled plugin in the catalog');
        }
        NoticeService::raise('x:2', 'warn', 'From a disabled plugin', '', '', (string) $off[0]['slug']);
        $this->assertNotContains('notice:x:2', $this->keys(NoticeService::forUser(self::USER)['open']));
    }

    public function testTheAlertsCheckIsNotListedTwice(): void
    {
        // "alerts" only carries raised problems to the e-mails; the bell lists them one by one.
        NoticeService::raise('p:1', 'warn', 'From a plugin');
        getDB()->exec("INSERT INTO health_checks (check_key, status, message, checked_at, changed_at) VALUES ('alerts', 'warn', 'From a plugin', NOW(), NOW())");
        $keys = $this->keys(NoticeService::forUser(self::USER)['open']);
        $this->assertContains('notice:p:1', $keys);
        $this->assertNotContains('health:alerts', $keys);
        $this->assertSame(['p:1'], array_column(NoticeService::openNotices(), 'notice_key'));
    }

    public function testCallQualityLinksToItsPage(): void
    {
        getDB()->exec("INSERT INTO health_checks (check_key, status, message, checked_at, changed_at) VALUES ('call_quality', 'warn', 'Trunk VOIP is losing audio', NOW(), NOW())");
        $open = NoticeService::forUser(self::USER)['open'];
        $cq = array_values(array_filter($open, fn($i) => $i['key'] === 'health:call_quality'));
        $this->assertSame('/call-quality', $cq[0]['link']);
    }
}
