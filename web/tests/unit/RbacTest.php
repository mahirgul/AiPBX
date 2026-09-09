<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once '/var/www/html/auth.php';

/**
 * Yetki yükseltme DEVRE KESİCİSİ (auth.php::hasModulePermission).
 *
 * 'roles' / 'system_users' / 'firewall' / 'fail2ban' modülleri
 * sys_role_permissions tablosunda NE YAZARSA YAZSIN yalnızca admin'e açık
 * olmalı. Gerekçesi gerçek bir açıktan geliyor (2026-08-21): roles.php'nin
 * izin matrisi bu modülleri sıradan bir modül gibi sunuyordu ve
 * read_only_admin rolü DB'de 'roles' için can_access=1 olarak yapılandırılmıştı
 * — yani o rol kendi rolünü admin yapıp tam yetki yükseltmesi sağlayabilirdi.
 *
 * NEDEN BURADA, DUMAN TESTİNDE DEĞİL: canlı veritabanında hiçbir role bu
 * modüller için can_access=1 verilmemiş, dolayısıyla sayfa normal izin
 * yolundan da kapalı — duman testi devre kesiciyi İZOLE EDEMİYOR (bu,
 * bin/smoke.php üzerinde ölçülerek doğrulandı). Burada izin satırlarını
 * kendimiz yazabildiğimiz için gerçek testi yapabiliyoruz.
 */
final class RbacTest extends TestCase
{
    private const KILITLI = ['roles', 'system_users', 'firewall', 'fail2ban'];

    protected function setUp(): void
    {
        if (DB_NAME !== 'asterisk_test') {
            $this->fail('testler yalnizca asterisk_test uzerinde kosmali');
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];

        $db = getDB();
        $db->exec('TRUNCATE TABLE sys_role_permissions');

        // KRİTİK KURULUM: admin OLMAYAN rollere kilitli modüller için DB'de
        // AÇIKÇA TAM YETKİ ver. Devre kesici çalışıyorsa bu satırlar hiçbir
        // işe yaramamalı.
        $stmt = $db->prepare(
            'INSERT INTO sys_role_permissions (role_key, module_key, can_view, can_access, can_edit, can_delete)
             VALUES (?, ?, 1, 1, 1, 1)'
        );
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user'] as $rol) {
            foreach (self::KILITLI as $modul) {
                $stmt->execute([$rol, $modul]);
            }
        }
    }

    public static function kilitliModuller(): array
    {
        return [['roles'], ['system_users'], ['firewall'], ['fail2ban']];
    }

    #[DataProvider('kilitliModuller')]
    public function testDbdeACIKCA_IZIN_VERILSE_BILE_adminDisindakiRollerReddedilir(string $modul): void
    {
        foreach (['read_only_admin', 'cc_agent', 'cc_manager', 'fax_user'] as $rol) {
            $_SESSION['user_role'] = $rol;

            $this->assertFalse(
                hasModulePermission($modul, 'access'),
                "'{$rol}' rolu '{$modul}' modulune erisebiliyor — DEVRE KESICI GEVSEMIS! "
                . 'Bu, yetki yukseltmesine acik bir durum.'
            );
        }
    }

    #[DataProvider('kilitliModuller')]
    public function testAdminKilitliModulleriHerZamanGorebilir(string $modul): void
    {
        $_SESSION['user_role'] = 'admin';

        $this->assertTrue(
            hasModulePermission($modul, 'access'),
            "admin '{$modul}' modulune erisemiyor — kendini disarida birakma (kilitlenme) riski!"
        );
    }

    public function testBilinmeyenRolKilitliModulleriGoremez(): void
    {
        $_SESSION['user_role'] = '__olmayan_rol__';
        foreach (self::KILITLI as $modul) {
            $this->assertFalse(hasModulePermission($modul, 'access'));
        }
    }

    public function testOturumsuzKullaniciKilitliModulleriGoremez(): void
    {
        unset($_SESSION['user_role']);
        foreach (self::KILITLI as $modul) {
            $this->assertFalse(hasModulePermission($modul, 'access'));
        }
    }
}
