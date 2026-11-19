<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Holiday calendars (Time Conditions -> Holidays): named lists of closed days, typed in, imported
 * from an .ics file or kept up to date from an .ics address. A time group picks the calendars it
 * closes on (holiday_calendar_ids, comma separated), next to its own dates (holidays_json).
 */
final class CreateHolidayCalendars extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_holiday_calendars')) {
            $this->execute("
                CREATE TABLE pbx_holiday_calendars (
                    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    dates_json MEDIUMTEXT NULL,
                    ics_url VARCHAR(500) NULL,
                    fetched_at DATETIME NULL,
                    fetch_error VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        $groups = $this->table('pbx_time_groups');
        if (!$groups->hasColumn('holiday_calendar_ids')) {
            $groups->addColumn('holiday_calendar_ids', 'string', ['limit' => 255, 'null' => true, 'after' => 'holidays_json'])->update();
        }
    }

    public function down(): void
    {
        $groups = $this->table('pbx_time_groups');
        if ($groups->hasColumn('holiday_calendar_ids')) {
            $groups->removeColumn('holiday_calendar_ids')->update();
        }
        $this->execute('DROP TABLE IF EXISTS pbx_holiday_calendars');
    }
}
