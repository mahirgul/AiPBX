<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The health checks stop mailing alarms themselves: a failed or warning check
 * writes a notice (web/src/services/HealthService.php), and the notice cabinet
 * decides about the e-mail, the quiet hours and how often a lasting problem is
 * repeated (docs/notices.md).
 *
 * Two pieces of state go with the old path:
 *
 *  - `health_checks.alerted_status` / `alerted_at` only existed to remember
 *    what was mailed and when. The cabinet's dedupe window replaces both, so
 *    the columns are dropped.
 *  - The old settings are carried over, once, so an install that was mailing
 *    alarms keeps mailing them: `health_repeat_hours` becomes the cabinet's
 *    window, and the alarm recipients become `notice_email_to` with notice
 *    mail switched on. Settings an administrator already saved are never
 *    overwritten.
 *
 * The daily summary keeps its own recipients (`health_recipients`,
 * `health_extra_emails`); it is a report, not an alarm.
 */
final class RetireHealthAlarmMail extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('health_checks')) {
            $table = $this->table('health_checks');
            foreach (['alerted_status', 'alerted_at'] as $column) {
                if ($table->hasColumn($column)) {
                    $table->removeColumn($column);
                }
            }
            $table->update();
        }

        $this->carryRepeatHours();
        $this->carryRecipients();
    }

    public function down(): void
    {
        if (!$this->hasTable('health_checks')) {
            return;
        }
        $table = $this->table('health_checks');
        if (!$table->hasColumn('alerted_status')) {
            $table->addColumn('alerted_status', 'string', ['limit' => 8, 'default' => '']);
        }
        if (!$table->hasColumn('alerted_at')) {
            $table->addColumn('alerted_at', 'datetime', ['null' => true]);
        }
        $table->update();
        // The copied notice settings stay: they are the live configuration now.
    }

    /** "Repeat the alarm every N hours" becomes the cabinet's dedupe window. */
    private function carryRepeatHours(): void
    {
        $repeat = (int) $this->setting('health_repeat_hours');
        if ($this->setting('notice_window_hours') !== null || $repeat <= 0) {
            return;
        }
        $this->writeSetting('notice_window_hours', (string) min(168, $repeat));
    }

    /**
     * The old alarm recipients become the cabinet's recipients, and its mail
     * is switched on — an install that mailed alarms must not go quiet just
     * because the sender changed.
     */
    private function carryRecipients(): void
    {
        if ($this->setting('notice_email') !== null) {
            return;
        }
        $addresses = [];

        $ids = json_decode((string) $this->setting('health_recipients', '[]'), true);
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        if ($ids !== [] && $this->hasTable('sys_users')) {
            $rows = $this->fetchAll(
                'SELECT email FROM sys_users WHERE id IN (' . implode(',', $ids) . ") AND is_active = 1 AND email <> ''"
            );
            foreach ($rows as $row) {
                $addresses[] = (string) $row['email'];
            }
        }

        foreach (preg_split('/[\s,;]+/', (string) $this->setting('health_extra_emails', '')) ?: [] as $address) {
            $addresses[] = trim($address);
        }

        $addresses = array_values(array_unique(array_filter($addresses, static fn(string $a): bool =>
            filter_var($a, FILTER_VALIDATE_EMAIL) !== false)));
        if ($addresses === []) {
            return;
        }

        $this->writeSetting('notice_email', '1');
        $this->writeSetting('notice_email_to', implode(',', $addresses));
    }

    /**
     * @return string|null the stored value, or null when the key was never set
     *
     * The key is inlined because Phinx's fetchRow() runs the SQL through
     * query(), which takes no parameters; every caller below passes a literal.
     */
    private function setting(string $key, ?string $default = null): ?string
    {
        if (!$this->hasTable('sys_settings') || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
            return $default;
        }
        $row = $this->fetchRow("SELECT setting_value FROM sys_settings WHERE setting_key = '{$key}'");
        return $row === false || $row === null ? $default : (string) $row['setting_value'];
    }

    private function writeSetting(string $key, string $value): void
    {
        $this->execute(
            'INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }
}
