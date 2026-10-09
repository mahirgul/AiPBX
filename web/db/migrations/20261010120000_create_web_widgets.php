<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Website call widget and call-back form (roadmap item 1, #14).
 *
 * - pbx_web_widgets:         one row per widget. The widget enters the PBX
 *                            like a trunk with its own number (the DID of
 *                            these calls); the server alone decides where the
 *                            call goes (dest_type/dest_id, or an external
 *                            number through one outbound route).
 * - pbx_web_widget_sessions: every request a website makes (call or call-back),
 *                            served or refused. A call's one-time token lives
 *                            here until the dialplan claims it; the rows are
 *                            also the abuse log and the source of the limits.
 *
 * The 'web_widgets' module is admin-only (auth.php circuit breaker): an
 * external destination or the call-back form can cost money.
 */
final class CreateWebWidgets extends AbstractMigration
{
    public function up(): void
    {
        $this->table('pbx_web_widgets', ['signed' => false])
            ->addColumn('public_id', 'string', ['limit' => 24, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('number', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('dest_type', 'string', ['limit' => 32, 'null' => false, 'default' => 'extension'])
            ->addColumn('dest_id', 'string', ['limit' => 64, 'null' => false, 'default' => ''])
            ->addColumn('external_number', 'string', ['limit' => 32, 'null' => false, 'default' => ''])
            ->addColumn('outbound_route_id', 'integer', ['null' => true, 'default' => null])
            ->addColumn('allowed_origins', 'text', ['null' => true])
            ->addColumn('call_enabled', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('callback_enabled', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('callback_prefixes', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('callback_cid', 'string', ['limit' => 32, 'null' => false, 'default' => ''])
            ->addColumn('max_concurrent', 'integer', ['null' => false, 'default' => 2])
            ->addColumn('max_call_seconds', 'integer', ['null' => false, 'default' => 900])
            ->addColumn('ip_hourly_limit', 'integer', ['null' => false, 'default' => 10])
            ->addColumn('daily_limit', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('button_text', 'string', ['limit' => 60, 'null' => false, 'default' => ''])
            ->addColumn('color', 'string', ['limit' => 7, 'null' => false, 'default' => '#2563eb'])
            ->addColumn('position', 'string', ['limit' => 12, 'null' => false, 'default' => 'right'])
            ->addColumn('language', 'string', ['limit' => 8, 'null' => false, 'default' => 'en'])
            ->addColumn('ask_name', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('sip_secret', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['public_id'], ['unique' => true])
            ->addIndex(['number'], ['unique' => true])
            ->create();

        $this->table('pbx_web_widget_sessions', ['signed' => false])
            ->addColumn('widget_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'default' => 'call'])
            ->addColumn('token', 'char', ['limit' => 32, 'null' => true, 'default' => null])
            ->addColumn('result', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false, 'default' => ''])
            ->addColumn('origin', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('caller_name', 'string', ['limit' => 60, 'null' => false, 'default' => ''])
            ->addColumn('caller_number', 'string', ['limit' => 32, 'null' => false, 'default' => ''])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('claimed_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['widget_id', 'created_at'])
            ->addIndex(['widget_id', 'ip', 'created_at'])
            ->addIndex(['created_at'])
            ->addForeignKey('widget_id', 'pbx_web_widgets', 'id', ['delete' => 'CASCADE'])
            ->create();

        // Existing installations only; fresh installs get the row from db/seed.sql.
        $this->execute(
            "INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             SELECT 'admin', 'web_widgets', 1, 1, 1, 1 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM sys_role_permissions WHERE role_key = 'admin' AND module_key = 'web_widgets')
               AND EXISTS (SELECT 1 FROM sys_roles WHERE role_key = 'admin')"
        );
    }

    public function down(): void
    {
        $this->execute("DELETE FROM sys_role_permissions WHERE module_key = 'web_widgets'");
        $this->table('pbx_web_widget_sessions')->drop()->save();
        $this->table('pbx_web_widgets')->drop()->save();
    }
}
