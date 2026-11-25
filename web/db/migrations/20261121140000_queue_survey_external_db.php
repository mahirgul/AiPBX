<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class QueueSurveyExternalDb extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pbx_queues')) {
            return;
        }
        $table = $this->table('pbx_queues');
        $changed = false;
        if (!$table->hasColumn('survey_db_id')) {
            $table->addColumn('survey_db_id', 'integer', ['null' => true, 'default' => null, 'after' => 'survey_announce']);
            $changed = true;
        }
        if (!$table->hasColumn('survey_write_sql')) {
            $table->addColumn('survey_write_sql', 'text', ['null' => true, 'default' => null, 'after' => 'survey_db_id']);
            $changed = true;
        }
        if ($changed) {
            $table->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('pbx_queues')) {
            return;
        }
        $table = $this->table('pbx_queues');
        $changed = false;
        if ($table->hasColumn('survey_write_sql')) {
            $table->removeColumn('survey_write_sql');
            $changed = true;
        }
        if ($table->hasColumn('survey_db_id')) {
            $table->removeColumn('survey_db_id');
            $changed = true;
        }
        if ($changed) {
            $table->update();
        }
    }
}
