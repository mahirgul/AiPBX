<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Queue improvements: several periodic announcements (periodic_announce becomes a comma list,
 * played in turn or at random), member levels (skill-based routing: a lower level is rung first),
 * and the after-call survey (the caller rates the agent 1-5 when the agent hangs up).
 */
final class AddQueueLevelsAndSurvey extends AbstractMigration
{
    public function up(): void
    {
        $q = $this->table('pbx_queues');
        $cols = [
            'periodic_random' => ['boolean', ['default' => 0, 'null' => false]],
            'member_levels_json' => ['text', ['null' => true]],
            'survey_enabled' => ['boolean', ['default' => 0, 'null' => false]],
            'survey_announce' => ['string', ['limit' => 255, 'null' => true]],
        ];
        foreach ($cols as $name => [$type, $opts]) {
            if (!$q->hasColumn($name)) {
                $q->addColumn($name, $type, $opts);
            }
        }
        $q->update();
        if ($q->hasColumn('periodic_announce')) {
            // One file name could not hold several.
            $this->execute("ALTER TABLE pbx_queues MODIFY periodic_announce VARCHAR(1000) NOT NULL DEFAULT ''");
        }
        if (!$this->hasTable('queue_survey_results')) {
            $this->execute("
                CREATE TABLE queue_survey_results (
                    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    queue_name VARCHAR(64) NOT NULL,
                    agent VARCHAR(20) NOT NULL DEFAULT '',
                    caller VARCHAR(40) NOT NULL DEFAULT '',
                    score TINYINT NOT NULL,
                    uniqueid VARCHAR(64) NOT NULL DEFAULT '',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_queue_date (queue_name, created_at),
                    KEY idx_agent_date (agent, created_at),
                    UNIQUE KEY uq_uniqueid (uniqueid)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS queue_survey_results');
        $q = $this->table('pbx_queues');
        foreach (['periodic_random', 'member_levels_json', 'survey_enabled', 'survey_announce'] as $c) {
            if ($q->hasColumn($c)) {
                $q->removeColumn($c);
            }
        }
        $q->update();
    }
}
