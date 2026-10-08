<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * E-mail templates edited by an administrator (Admin → E-Mail → Templates),
 * one row per template and language. A template without a row uses the
 * built-in text of the language files (mailtpl.<key>.subject / .body).
 */
final class CreateMailTemplates extends AbstractMigration
{
    public function change(): void
    {
        $this->table('mail_templates', ['id' => false, 'primary_key' => ['template_key', 'lang']])
            ->addColumn('template_key', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('lang', 'string', ['limit' => 8, 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('body', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('updated_by', 'integer', ['null' => true, 'default' => null])
            ->create();
    }
}
