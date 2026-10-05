<?php

use Phinx\Migration\AbstractMigration;

/**
 * Auth digest (authentication) option for SIP subscribers.
 *
 * Default: 1 (on - digest auth applies).
 * With 0 the PBX does not ask for HTTP/SIP digest authentication on this
 * subscriber's SIP requests (REGISTER/INVITE); the request is matched
 * directly by the extension number in the From header.
 */
final class AddSipAuthDigestToUsers extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('sys_users')->hasColumn('sip_auth_digest')) {
            $this->table('sys_users')
                 ->addColumn('sip_auth_digest', 'integer', [
                     'limit' => 1,
                     'default' => 1,
                     'null' => false,
                     'after' => 'sip_password',
                     'comment' => 'SIP kimlik doğrulama (Auth Digest) yapılsın mı: 1=evet, 0=hayır',
                 ])
                 ->update();
        }
    }

    public function down(): void
    {
        if ($this->table('sys_users')->hasColumn('sip_auth_digest')) {
            $this->table('sys_users')->removeColumn('sip_auth_digest')->update();
        }
    }
}
