<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/HealthService.php';

final class HealthServiceCoverageTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private int $candidateUserId = 0;
    private int $inactiveUserId = 0;
    /** @var array<string, string|null> */
    private array $origSettings = [];

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        // Save original health settings
        $db = getDB();
        $keys = [
            'health_recipients',
            'health_extra_emails',
            'health_repeat_hours',
            'health_summary_enabled',
            'health_summary_time',
            'health_run_all',
        ];
        foreach ($keys as $k) {
            $stmt = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
            $stmt->execute([$k]);
            $val = $stmt->fetchColumn();
            $this->origSettings[$k] = $val !== false ? (string)$val : null;
        }

        $passHash = password_hash('Pass_zz_7906!', PASSWORD_DEFAULT);

        // Candidate active user
        $stmt = $db->prepare('INSERT INTO sys_users (username, password_hash, full_name, email, role, extension, is_active, language_preference) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $stmt->execute(['zz_cov_cand_7906', $passHash, 'Candidate User', 'zz_cov_cand_7906@example.com', 'admin', '7909', 'tr']);
        $this->candidateUserId = (int)$db->lastInsertId();

        // Inactive user
        $stmt->execute(['zz_cov_inact_7906', $passHash, 'Inactive User', 'zz_cov_inact_7906@example.com', 'user', '7910', 'en']);
        $this->inactiveUserId = (int)$db->lastInsertId();
        $db->exec("UPDATE sys_users SET is_active = 0 WHERE id = {$this->inactiveUserId}");
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $db->exec("DELETE FROM sys_users WHERE id IN ({$this->candidateUserId}, {$this->inactiveUserId})");

        // Restore original settings
        foreach ($this->origSettings as $k => $val) {
            if ($val === null) {
                $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([$k]);
            } else {
                $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$k, $val]);
            }
        }
        $_SESSION = [];
    }

    public function testSettingsReturnsDefaultStructure(): void
    {
        $settings = HealthService::settings();
        foreach (array_keys(HealthService::SETTINGS) as $key) {
            $this->assertArrayHasKey($key, $settings);
            $this->assertIsString($settings[$key]);
        }
    }

    public function testRecipientIdsParsing(): void
    {
        // Valid JSON with duplicate and zero values
        $parsed = HealthService::recipientIds('[10, 20, 20, -1, 0, "bad", 30]');
        $this->assertSame([10, 20, -1, 30], $parsed);

        // Invalid JSON
        $this->assertSame([], HealthService::recipientIds('invalid-json'));

        // Empty string
        $this->assertSame([], HealthService::recipientIds(''));
    }

    public function testExtraEmailsParsing(): void
    {
        $raw = "alerts@example.com, noc@example.com; ADMIN@EXAMPLE.COM \n not-an-email \n noc@example.com \n";
        $emails = HealthService::extraEmails($raw);

        $this->assertSame(['alerts@example.com', 'noc@example.com', 'ADMIN@EXAMPLE.COM'], $emails);
        $this->assertSame([], HealthService::extraEmails('   invalid  '));
    }

    public function testCandidateUsersExcludesInactiveUsers(): void
    {
        $candidates = HealthService::candidateUsers();
        $candidateIds = array_map('intval', array_column($candidates, 'id'));

        $this->assertContains($this->candidateUserId, $candidateIds);
        $this->assertNotContains($this->inactiveUserId, $candidateIds);
    }

    public function testRecipientsResolutionWithLanguage(): void
    {
        $db = getDB();
        $json = json_encode([$this->candidateUserId]);
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('health_recipients', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$json]);
        $db->prepare("INSERT INTO sys_settings (setting_key, setting_value) VALUES ('health_extra_emails', 'ext@example.com') ON DUPLICATE KEY UPDATE setting_value = 'ext@example.com'")->execute();

        $recipients = HealthService::recipients();
        $this->assertCount(2, $recipients);

        $cand = null;
        $extra = null;
        foreach ($recipients as $r) {
            if ($r['email'] === 'zz_cov_cand_7906@example.com') {
                $cand = $r;
            } elseif ($r['email'] === 'ext@example.com') {
                $extra = $r;
            }
        }

        $this->assertNotNull($cand);
        $this->assertSame('tr', $cand['lang']);
        $this->assertSame($this->candidateUserId, $cand['user_id']);

        $this->assertNotNull($extra);
        $this->assertNull($extra['user_id']);
    }

    public function testSaveHealthSettingsValidation(): void
    {
        // 1. Invalid CSRF
        $resCsrf = HealthService::save(['csrf_token' => 'invalid_csrf']);
        $this->assertFalse($resCsrf['success']);

        $csrf = getCSRFToken();

        // 2. Invalid summary time format
        $resTime = HealthService::save([
            'csrf_token' => $csrf,
            'health_summary_time' => '25:99',
        ]);
        $this->assertFalse($resTime['success']);
        $this->assertStringContainsString(t('health.err_time'), $resTime['error'] ?? '');

        // 3. Invalid extra email
        $resMail = HealthService::save([
            'csrf_token' => $csrf,
            'health_summary_time' => '08:00',
            'health_extra_emails' => 'valid@example.com, invalid-email',
        ]);
        $this->assertFalse($resMail['success']);
        $this->assertStringContainsString('invalid-email', $resMail['error'] ?? '');

        // 4. Invalid quiet hours (a typo must never silence alarms)
        $resQuiet = HealthService::save([
            'csrf_token' => $csrf,
            'health_summary_time' => '08:00',
            'health_quiet_hours' => '22:00',
        ]);
        $this->assertFalse($resQuiet['success']);
        $this->assertStringContainsString(t('health.err_quiet'), $resQuiet['error'] ?? '');

        // 5. Successful save
        $resOk = HealthService::save([
            'csrf_token' => $csrf,
            'health_summary_time' => '09:30',
            'health_extra_emails' => 'alert@example.com',
            'health_repeat_hours' => 12,
            'health_quiet_hours' => '22:00-07:00',
            'health_summary_enabled' => '1',
            'health_recipients' => [$this->candidateUserId],
            'interval' => ['asterisk' => 15, 'disk' => 60],
        ]);
        $this->assertTrue($resOk['success']);

        $this->assertSame('09:30', (string)getSystemSetting('health_summary_time', ''));
        $this->assertSame('12', (string)getSystemSetting('health_repeat_hours', ''));
        $this->assertSame('22:00-07:00', (string)getSystemSetting('health_quiet_hours', ''));
        $this->assertSame('alert@example.com', (string)getSystemSetting('health_extra_emails', ''));
    }

    public function testRequestRunAllQueuing(): void
    {
        // 1. Invalid CSRF
        $resCsrf = HealthService::requestRunAll('bad_csrf');
        $this->assertFalse($resCsrf['success']);

        // 2. Valid CSRF
        $resOk = HealthService::requestRunAll(getCSRFToken());
        $this->assertTrue($resOk['success']);
        $this->assertSame('1', (string)getSystemSetting('health_run_all', '0'));
    }

    public function testDueCheckIntervalCalculations(): void
    {
        $at = fn(int $ago) => date('Y-m-d H:i:s', self::NOW - $ago);

        $checks = [
            // Disabled check: interval <= 0
            'off_check' => ['interval' => 0, 'checked_at' => null],
            // Checked 2 min ago (120s), interval 5 min (300s - 30s slack = 270s) -> not due
            'not_due' => ['interval' => 5, 'checked_at' => $at(120)],
            // Checked 4m35s ago (275s), interval 5 min (slack 270s) -> due!
            'due_slack' => ['interval' => 5, 'checked_at' => $at(275)],
            // Never checked -> due!
            'never_checked' => ['interval' => 60, 'checked_at' => null],
        ];

        $due = HealthService::due($checks, self::NOW, false);
        $this->assertSame(['due_slack', 'never_checked'], $due);

        $dueAll = HealthService::due($checks, self::NOW, true);
        $this->assertSame(['not_due', 'due_slack', 'never_checked'], $dueAll);
    }

    public function testAlarmEmailDecisionsMatrix(): void
    {
        // 1. Frequent check (interval <= 15, e.g. 5 minutes)
        // First failure: streak 1 < needed 2 -> quiet (null)
        $prevOk = ['status' => 'ok', 'bad_streak' => 0, 'alerted_status' => '', 'alerted_at' => null];
        $this->assertNull(HealthService::decide($prevOk, 'warn', 5, self::NOW, 6));

        // Second failure: streak 2 == needed 2 -> alarm! (state change -> mail)
        $prevWarn1 = ['status' => 'warn', 'bad_streak' => 1, 'alerted_status' => '', 'alerted_at' => null];
        $this->assertSame('alarm', HealthService::decide($prevWarn1, 'warn', 5, self::NOW, 6));

        // Third failure unchanged: streak 3, alerted_status 'warn', recent -> null (no mail when unchanged!)
        $prevWarn2 = ['status' => 'warn', 'bad_streak' => 2, 'alerted_status' => 'warn', 'alerted_at' => date('Y-m-d H:i:s', self::NOW - 300)];
        $this->assertNull(HealthService::decide($prevWarn2, 'warn', 5, self::NOW, 6));

        // Worsened from warn to fail -> alarm! (state change to worse -> mail)
        $this->assertSame('alarm', HealthService::decide($prevWarn2, 'fail', 5, self::NOW, 6));

        // Unchanged fail within repeat window (1 hour ago < 6 hours) -> null (no mail when unchanged!)
        $prevFail = ['status' => 'fail', 'bad_streak' => 5, 'alerted_status' => 'fail', 'alerted_at' => date('Y-m-d H:i:s', self::NOW - 3600)];
        $this->assertNull(HealthService::decide($prevFail, 'fail', 5, self::NOW, 6));

        // Fail after repeat window has elapsed (6 hours ago) -> alarm! (repeat mail)
        $prevRepeat = ['status' => 'fail', 'bad_streak' => 10, 'alerted_status' => 'fail', 'alerted_at' => date('Y-m-d H:i:s', self::NOW - (6 * 3600))];
        $this->assertSame('alarm', HealthService::decide($prevRepeat, 'fail', 5, self::NOW, 6));

        // Better but not fixed: fail -> warn -> null (quiet!)
        $this->assertNull(HealthService::decide($prevFail, 'warn', 5, self::NOW, 6));

        // Recovery to ok from an alarmed failure -> recovered! (state change -> mail)
        $this->assertSame('recovered', HealthService::decide($prevFail, 'ok', 5, self::NOW, 6));

        // Unchanged ok -> null (no mail when unchanged!)
        $this->assertNull(HealthService::decide($prevOk, 'ok', 5, self::NOW, 6));

        // Recovery from a hiccup that was never alerted -> null (no recovery mail)
        $this->assertNull(HealthService::decide($prevWarn1, 'ok', 5, self::NOW, 6));

        // 2. Infrequent check (interval = 60 minutes)
        // Needed streak is 1 -> first failure immediately triggers alarm!
        $this->assertSame('alarm', HealthService::decide($prevOk, 'warn', 60, self::NOW, 6));
        $this->assertSame('alarm', HealthService::decide($prevOk, 'fail', 1440, self::NOW, 6));

        // 3. Info and off statuses never trigger alarm
        $this->assertNull(HealthService::decide($prevOk, 'info', 5, self::NOW, 6));
        $this->assertNull(HealthService::decide($prevOk, 'off', 5, self::NOW, 6));
    }

    public function testRunDueWithMailDisabledExecutesSafely(): void
    {
        // Test runDue with mail disabled (mail = false): no mail is sent, purely evaluates checks
        $results = HealthService::runDue(false, false);

        // Verify structure of results: key => [status, message]
        foreach ($results as $res) {
            $this->assertCount(2, $res);
            $this->assertContains($res[0], HealthChecks::STATUSES);
        }
    }

    public function testSendSummaryWithEmptyRecipientsSendsZero(): void
    {
        $sent = HealthService::sendSummary(self::NOW, []);
        $this->assertSame(0, $sent);
    }
}
