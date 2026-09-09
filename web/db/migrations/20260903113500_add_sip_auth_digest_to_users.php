<?php

use Phinx\Migration\AbstractMigration;

/**
 * SIP aboneleri için Auth Digest (kimlik doğrulama) seçeneği.
 *
 * Varsayılan: 1 (Etkin - Digest Auth uygulanır).
 * 0 olduğunda santral bu abonenin SIP isteklerinde (REGISTER/INVITE)
 * HTTP/SIP Digest kimlik doğrulaması aramaz, istek From başlığındaki
 * dahili numarasıyla doğrudan eşleştirilir.
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
