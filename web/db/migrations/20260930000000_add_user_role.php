<?php

use Phinx\Migration\AbstractMigration;

/**
 * Existing installations only: on a fresh install sys_roles is still empty here and
 * db/seed.sql (loaded after the migrations, with fixed ids and INSERT IGNORE) adds
 * the role — inserting it first took id 1 and made the seed skip 'admin'.
 *
 * Plain "user" system role: My Phone, contacts, chat and own call history,
 * without fax or call-center modules. Feature codes already referenced it.
 */
final class AddUserRole extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            "INSERT INTO sys_roles (role_key, role_name, description, is_system)
             SELECT 'user', 'Kullanıcı', 'Telefonum, rehber, sohbet ve kendi arama geçmişi (faks ve çağrı merkezi yetkisi yok)', 1
             FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM sys_roles WHERE role_key = 'user')
               AND EXISTS (SELECT 1 FROM sys_roles WHERE role_key = 'admin')"
        );
    }

    public function down(): void
    {
        $this->execute(
            "DELETE FROM sys_roles WHERE role_key = 'user'
             AND NOT EXISTS (SELECT 1 FROM sys_users WHERE role = 'user')"
        );
    }
}
