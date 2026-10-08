<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/services/ForgotPasswordService.php';

/** "Forgot your password?" on the sign-in page (#13). */
final class ForgotPasswordTest extends TestCase
{
    private PDO $db;
    private string $ip;

    protected function setUp(): void
    {
        $this->db = getDB();
        $this->ip = '198.51.100.' . random_int(1, 254);
        $this->db->exec("DELETE FROM sys_users WHERE username IN ('forgot_a', 'forgot_noemail')");
        $this->db->exec("DELETE FROM sys_login_logs WHERE status = 'RESET_REQ'");
        $this->db->exec("INSERT INTO sys_users (username, password_hash, full_name, email, role, is_active) VALUES
            ('forgot_a', '', 'Forgot A', 'forgot-a@example.com', 'user', 1),
            ('forgot_noemail', '', 'No Mail', '', 'user', 1)");
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM sys_users WHERE username IN ('forgot_a', 'forgot_noemail')");
        $this->db->exec("DELETE FROM sys_login_logs WHERE status = 'RESET_REQ'");
    }

    private function token(string $user): ?string
    {
        $v = $this->db->query("SELECT reset_token FROM sys_users WHERE username = " . $this->db->quote($user))->fetchColumn();
        return $v ?: null;
    }

    public function testUnknownAndKnownAccountsGetTheSameAnswer(): void
    {
        $unknown = ForgotPasswordService::request('nobody-here', $this->ip);
        $noMail = ForgotPasswordService::request('forgot_noemail', $this->ip);
        $known = ForgotPasswordService::request('forgot_a', $this->ip);
        $this->assertSame(['success' => true], $unknown);
        $this->assertSame($unknown, $noMail);
        $this->assertSame($unknown, $known);
        $this->assertNull($this->token('forgot_noemail'));
    }

    public function testEmailAddressFindsTheAccountAndSecondRequestIsNotSent(): void
    {
        ForgotPasswordService::request('forgot-a@example.com', $this->ip);
        $first = $this->token('forgot_a');
        if ($first === null) {
            $this->markTestSkipped('mail() is not available here (the token is withdrawn when sending fails)');
        }
        ForgotPasswordService::request('forgot_a', $this->ip);
        $this->assertSame($first, $this->token('forgot_a'), 'a second request within 10 minutes sends nothing new');
    }

    public function testRequestsPerIpAreLimited(): void
    {
        for ($i = 0; $i < ForgotPasswordService::MAX_PER_IP_HOUR; $i++) {
            $this->assertTrue(ForgotPasswordService::request('nobody-' . $i, $this->ip)['success']);
        }
        $res = ForgotPasswordService::request('forgot_a', $this->ip);
        $this->assertFalse($res['success']);
        $this->assertNull($this->token('forgot_a'));
    }

    public function testLinkDoesNotTrustTheHostHeader(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $this->assertStringNotContainsString('evil.example', ForgotPasswordService::portalUrl());
        $this->assertStringStartsWith('https://', ForgotPasswordService::portalUrl());
        unset($_SERVER['HTTP_HOST']);
    }
}
