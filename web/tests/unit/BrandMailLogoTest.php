<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/BrandSettingsService.php';

/**
 * #16 / #12: the e-mail logo choice (normal / dark theme) and the option to
 * leave the brand name out of the e-mail header are saved; removing the dark
 * logo clears only that setting.
 */
final class BrandMailLogoTest extends TestCase
{
    private array $before = [];

    protected function setUp(): void
    {
        $this->before = getDB()->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    protected function tearDown(): void
    {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach (BrandSettingsService::defaults() as $k => $v) {
            if (array_key_exists($k, $this->before)) {
                $stmt->execute([$k, $this->before[$k]]);
            } else {
                $db->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([$k]);
            }
        }
    }

    public function testMailLogoOptionsAreSaved(): void
    {
        $_SESSION['user_id'] = null;
        $res = BrandSettingsService::saveSettings([
            'csrf_token' => getCSRFToken(),
            'site_title' => 'AiPBX',
            'brand_title' => 'Acme',
            'brand_sub' => 'PBX',
            'mail_logo_variant' => 'dark',
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame('dark', (string) getSystemSetting('mail_logo_variant', ''));
        // Unchecked box = the brand name is left out of the e-mail header.
        $this->assertSame('0', (string) getSystemSetting('mail_show_brand_title', ''));

        $res = BrandSettingsService::saveSettings([
            'csrf_token' => getCSRFToken(),
            'site_title' => 'AiPBX',
            'brand_title' => 'Acme',
            'brand_sub' => 'PBX',
            'mail_logo_variant' => 'something-else',
            'mail_show_brand_title' => '1',
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame('light', (string) getSystemSetting('mail_logo_variant', ''));
        $this->assertSame('1', (string) getSystemSetting('mail_show_brand_title', ''));
    }

    public function testRemovingTheDarkLogoKeepsTheNormalOne(): void
    {
        $_SESSION['user_id'] = null;
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute(['site_logo_image', '/assets/images/brand/logo.png?v=1']);
        $stmt->execute(['site_logo_image_dark', '/assets/images/brand/logo_dark.png?v=1']);

        $res = BrandSettingsService::saveSettings([
            'csrf_token' => getCSRFToken(),
            'site_title' => 'AiPBX',
            'brand_title' => 'Acme',
            'brand_sub' => 'PBX',
            'site_logo_type' => 'image',
            'remove_logo_image_dark' => '1',
        ]);
        $this->assertTrue($res['success'], $res['error'] ?? '');
        $this->assertSame('', (string) getSystemSetting('site_logo_image_dark', ''));
        $this->assertSame('/assets/images/brand/logo.png?v=1', (string) getSystemSetting('site_logo_image', ''));
    }
}
