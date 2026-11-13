<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/auth.php';

/**
 * Changing the password of a stolen account has to throw the thief out of the
 * browser session too, not only out of the phones.
 */
final class SessionEpochTest extends TestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO sys_users (username, password_hash, full_name, role, is_active) VALUES (?, 'x', 'Epoch', 'user', 1)")
            ->execute(['epoch_test_' . bin2hex(random_bytes(4))]);
        $this->userId = (int) $db->lastInsertId();
        $_SESSION = ['user_id' => $this->userId];
    }

    protected function tearDown(): void
    {
        getDB()->prepare('DELETE FROM sys_users WHERE id = ?')->execute([$this->userId]);
        $_SESSION = [];
    }

    private function bumpEpoch(): void
    {
        getDB()->prepare('UPDATE sys_users SET token_epoch = token_epoch + 1 WHERE id = ?')->execute([$this->userId]);
    }

    public function testASessionFromBeforeTheCheckStaysValid(): void
    {
        $this->assertArrayNotHasKey('session_epoch', $_SESSION);
        $this->assertTrue(_isSessionEpochCurrent());
        $this->assertArrayHasKey('session_epoch', $_SESSION);
    }

    public function testAPasswordChangeEndsTheSession(): void
    {
        $this->assertTrue(_isSessionEpochCurrent());
        $this->bumpEpoch();
        $this->assertFalse(_isSessionEpochCurrent());
    }

    public function testARoleChangeReachesTheOpenSession(): void
    {
        $_SESSION['user_role'] = 'admin';
        $this->assertTrue(_isSessionEpochCurrent());
        $this->assertSame('user', $_SESSION['user_role'], 'the role in the database wins');
    }

    public function testWhoChangedTheirOwnPasswordStaysSignedIn(): void
    {
        $this->assertTrue(_isSessionEpochCurrent());
        $this->bumpEpoch();
        refreshOwnSessionEpoch($this->userId);
        $this->assertTrue(_isSessionEpochCurrent());
    }

    public function testChangingSomeoneElsesPasswordDoesNotRefreshTheirSession(): void
    {
        $this->assertTrue(_isSessionEpochCurrent());
        $this->bumpEpoch();
        refreshOwnSessionEpoch($this->userId + 1);
        $this->assertFalse(_isSessionEpochCurrent());
    }

    public function testADisabledAccountLosesItsSession(): void
    {
        $this->assertTrue(_isSessionEpochCurrent());
        getDB()->prepare('UPDATE sys_users SET is_active = 0 WHERE id = ?')->execute([$this->userId]);
        $this->assertFalse(_isSessionEpochCurrent());
    }

    public function testARemovedAccountLosesItsSession(): void
    {
        $_SESSION['user_id'] = 2000000000;
        $this->assertFalse(_isSessionEpochCurrent());
    }

    public function testSigningInAsAnotherUserInTheSameSessionIsStampedAnew(): void
    {
        $_SESSION['session_epoch'] = ($this->userId + 1) . ':7';
        $this->assertTrue(_isSessionEpochCurrent());
        $this->assertSame($this->userId . ':0', $_SESSION['session_epoch']);
    }
}
