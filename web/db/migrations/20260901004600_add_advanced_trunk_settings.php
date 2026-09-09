<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dış Hat (SIP Trunk) yapılandırmasına gelişmiş PJSIP ayarları ekler:
 * - Kimlik Doğrulama & Kayıt: auth_username, auth_password, registration_enabled, registration_expiration
 * - Arayan Bilgisi & Başlıklar: from_user, from_domain, outbound_caller_id, send_pai, send_rpid
 * - Sinyalizasyon & Medya: dtmf_mode, context, max_channels, direct_media, timers
 * - Gelişmiş PJSIP Parametreleri: custom_pjsip_params
 */
final class AddAdvancedTrunkSettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_trunks')
            ->addColumn('auth_username', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'send_caller_name',
            ])
            ->addColumn('auth_password', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'auth_username',
            ])
            ->addColumn('registration_enabled', 'boolean', [
                'default' => false,
                'after' => 'auth_password',
            ])
            ->addColumn('registration_expiration', 'integer', [
                'default' => 3600,
                'null' => true,
                'after' => 'registration_enabled',
            ])
            ->addColumn('from_user', 'string', [
                'limit' => 100,
                'null' => true,
                'default' => null,
                'after' => 'registration_expiration',
            ])
            ->addColumn('from_domain', 'string', [
                'limit' => 150,
                'null' => true,
                'default' => null,
                'after' => 'from_user',
            ])
            ->addColumn('outbound_caller_id', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => null,
                'after' => 'from_domain',
            ])
            ->addColumn('dtmf_mode', 'string', [
                'limit' => 20,
                'default' => 'rfc4733',
                'after' => 'outbound_caller_id',
            ])
            ->addColumn('context', 'string', [
                'limit' => 100,
                'default' => 'from-trunk-inbound',
                'after' => 'dtmf_mode',
            ])
            ->addColumn('max_channels', 'integer', [
                'default' => 0,
                'after' => 'context',
            ])
            ->addColumn('direct_media', 'string', [
                'limit' => 10,
                'default' => 'no',
                'after' => 'max_channels',
            ])
            ->addColumn('timers', 'string', [
                'limit' => 10,
                'default' => 'yes',
                'after' => 'direct_media',
            ])
            ->addColumn('send_pai', 'boolean', [
                'default' => false,
                'after' => 'timers',
            ])
            ->addColumn('send_rpid', 'boolean', [
                'default' => false,
                'after' => 'send_pai',
            ])
            ->addColumn('custom_pjsip_params', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'send_rpid',
            ])
            ->update();
    }
}
