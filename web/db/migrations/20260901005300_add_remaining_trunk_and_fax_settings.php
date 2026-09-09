<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Dış Hat (SIP Trunk) yapılandırmasındaki tüm kalan sabit ayarları dinamikleştirir:
 * - Ağ & Proxy: outbound_proxy, match_hosts
 * - T.38 UDPTL: t38_udptl_ec, t38_udptl_nat
 * - NAT & Sinyalizasyon: rtp_symmetric, rewrite_contact, force_rport
 * - Kayıt & AOR: registration_retry_interval, max_contacts
 */
final class AddRemainingTrunkAndFaxSettings extends AbstractMigration
{
    public function change(): void
    {
        $this->table('pbx_trunks')
            ->addColumn('outbound_proxy', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'port',
            ])
            ->addColumn('match_hosts', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'outbound_proxy',
            ])
            ->addColumn('t38_udptl_ec', 'string', [
                'limit' => 20,
                'default' => 'redundancy',
                'after' => 't38_support',
            ])
            ->addColumn('t38_udptl_nat', 'string', [
                'limit' => 10,
                'default' => 'yes',
                'after' => 't38_udptl_ec',
            ])
            ->addColumn('rtp_symmetric', 'string', [
                'limit' => 10,
                'default' => 'yes',
                'after' => 'direct_media',
            ])
            ->addColumn('rewrite_contact', 'string', [
                'limit' => 10,
                'default' => 'yes',
                'after' => 'rtp_symmetric',
            ])
            ->addColumn('force_rport', 'string', [
                'limit' => 10,
                'default' => 'yes',
                'after' => 'rewrite_contact',
            ])
            ->addColumn('registration_retry_interval', 'integer', [
                'default' => 60,
                'after' => 'registration_expiration',
            ])
            ->addColumn('max_contacts', 'integer', [
                'default' => 1,
                'after' => 'registration_retry_interval',
            ])
            ->update();
    }
}
