<?php

use Phinx\Migration\AbstractMigration;

/**
 * v_chat_users: the part of sys_users the chat service (chat/*.go) reads, as one named contract.
 *
 * sys_users also holds the portal login, the SIP account, the voicemail PIN and the call-routing settings, and the chat
 * service used to read it directly, so a change to that table could break chat without a line of chat code changing.
 * Chat now reads this view; its columns are the whole of what chat may know about a user. SQL SECURITY INVOKER: the
 * view grants nothing, the database user chat runs as must already be allowed to read sys_users.
 */
final class CreateChatUsersView extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE OR REPLACE SQL SECURITY INVOKER VIEW `v_chat_users` AS SELECT
            `id`, `username`, `extension`, `full_name`, `email`, `role`, `is_active`, `is_device`, `extension_type`, `token_epoch`
            FROM `sys_users`');
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS `v_chat_users`');
    }
}
