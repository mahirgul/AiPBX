<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';
require_once dirname(__DIR__, 2) . '/src/services/LoginCaptchaService.php';

final class LoginCaptchaAndThemeTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        $db = getDB();
        foreach (['theme_default', 'theme_locked', 'login_captcha_provider', 'login_turnstile_site_key', 'login_turnstile_secret'] as $key) {
            $stmt = $db->prepare('SELECT setting_value FROM sys_settings WHERE setting_key = ?');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            $this->saved[$key] = $value === false ? null : (string) $value;
        }
        unset($_SESSION['captcha_turnstile_down']);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            $value === null
                ? getDB()->prepare('DELETE FROM sys_settings WHERE setting_key = ?')->execute([$key])
                : $this->set($key, $value);
        }
        unset($_SESSION['captcha_turnstile_down']);
    }

    private function set(string $key, string $value): void
    {
        getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
        if (function_exists('clearSystemSettingsCache')) {
            clearSystemSettingsCache();
        }
    }

    public function testThemeFollowsTheUsersChoiceThenTheDefault(): void
    {
        $this->set('theme_locked', '0');
        $this->set('theme_default', 'dark');
        $this->assertSame('dark', portalTheme());
        $this->assertSame('dark', portalTheme(null));
        $this->assertSame('light', portalTheme('light'));
        $this->assertSame('dark', portalTheme('nonsense'));
    }

    public function testALockedThemeOverridesTheUsersChoice(): void
    {
        $this->set('theme_default', 'dark');
        $this->set('theme_locked', '1');
        $this->assertTrue(portalThemeLocked());
        $this->assertSame('dark', portalTheme('light'));
    }

    public function testTheSumIsUsedUntilTurnstileIsConfigured(): void
    {
        $this->set('login_captcha_provider', 'math');
        $this->assertFalse(LoginCaptchaService::usesTurnstile());
        $this->assertTrue(LoginCaptchaService::passes(['captcha_answer' => '7'], '127.0.0.1', 7));
        $this->assertFalse(LoginCaptchaService::passes(['captcha_answer' => '8'], '127.0.0.1', 7));
        $this->assertFalse(LoginCaptchaService::passes([], '127.0.0.1', 0), 'no answer never passes');
    }

    public function testTurnstileNeedsBothKeys(): void
    {
        $this->set('login_captcha_provider', 'turnstile');
        $this->set('login_turnstile_site_key', '');
        $this->set('login_turnstile_secret', '');
        $this->assertFalse(LoginCaptchaService::usesTurnstile());

        $this->set('login_turnstile_site_key', 'site');
        $this->set('login_turnstile_secret', SecretBox::encrypt('secret'));
        $this->assertTrue(LoginCaptchaService::usesTurnstile());
    }

    public function testASessionGoesBackToTheSumOnlyWhileCloudflareIsDown(): void
    {
        $this->set('login_captcha_provider', 'turnstile');
        $this->set('login_turnstile_site_key', 'site');
        $this->set('login_turnstile_secret', SecretBox::encrypt('secret'));

        $_SESSION['captcha_turnstile_down'] = time() + 60;
        $this->assertFalse(LoginCaptchaService::usesTurnstile());
        $_SESSION['captcha_turnstile_down'] = time() - 1;
        $this->assertTrue(LoginCaptchaService::usesTurnstile());
    }
}
