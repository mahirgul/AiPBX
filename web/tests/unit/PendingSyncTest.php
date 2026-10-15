<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tests/Fixtures.php';
require_once dirname(__DIR__, 2) . '/src/asterisk_sync.php';

/**
 * The contract of the deferred reload ("Apply" page) system.
 *
 * Contract: a service save must NOT TOUCH the Asterisk config, only drop a row
 * into sys_pending_sync. When this contract breaks, the admin's approval flow
 * is silently bypassed — exactly that happened on 2026-08-24
 * (PBXHelper::toggleStatus() had been skipped in the rollout, kept reloading
 * instantly and went unnoticed for weeks).
 */
final class PendingSyncTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::load();
    }

    public function testMarkingCreatesARow(): void
    {
        markPendingSync('trunks', 'trunk', 1, 'Test Trunk', 'update', null);

        $n = (int) getDB()->query(
            "SELECT COUNT(*) FROM sys_pending_sync WHERE domain='trunks'"
        )->fetchColumn();
        $this->assertSame(1, $n);
    }

    public function testMarkingTheSameRecordTwiceMakesNoDuplicate(): void
    {
        markPendingSync('trunks', 'trunk', 1, 'Test Trunk', 'update', null);
        markPendingSync('trunks', 'trunk', 1, 'Test Trunk (degisti)', 'update', null);

        $n = (int) getDB()->query(
            "SELECT COUNT(*) FROM sys_pending_sync WHERE domain='trunks'"
        )->fetchColumn();
        $this->assertSame(1, $n, '(domain, entity_type, entity_id) must be unique');
    }

    public function testDifferentDomainsCountSeparately(): void
    {
        $this->assertSame(0, getPendingSyncCount());

        markPendingSync('trunks', 'trunk', 1, 'Test Trunk', 'update', null);
        markPendingSync('queues', 'queue', 1, 'Test Queue', 'update', null);

        $this->assertSame(2, getPendingSyncCount());
    }

    public function testMarkingDoesNotTouchTheConfFile(): void
    {
        $f = ASTERISK_PBX_DIR . '/pjsip_trunks.conf';
        file_put_contents($f, "; previous content\n");
        $once = md5_file($f);

        markPendingSync('trunks', 'trunk', 1, 'Test Trunk', 'update', null);

        clearstatcache();
        $this->assertSame($once, md5_file($f),
            'markPendingSync changed the config file — the deferred reload contract is broken!');
    }

    public function testUnknownDomainIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        markPendingSync('__missing_domain__', 'trunk', 1, 'X', 'update', null);
    }

    public function testListShowsTheMarkedRecord(): void
    {
        markPendingSync('trunks', 'trunk', 42, 'Ozel Etiket', 'create', null);

        // getPendingSyncList() does NOT return a flat array but one grouped by
        // domain, ['trunks' => [row, ...]] (the Apply page lists the changes
        // under domain headings).
        $list = getPendingSyncList();
        $this->assertArrayHasKey('trunks', $list);
        $this->assertCount(1, $list['trunks']);
        $this->assertSame('Ozel Etiket', $list['trunks'][0]['entity_label']);
        $this->assertSame('create', $list['trunks'][0]['action']);
        $this->assertSame('trunk', $list['trunks'][0]['entity_type']);
    }

    public function testDeleteMarkIsRecordedToo(): void
    {
        markPendingSync('queues', 'queue', 7, 'Silinen Kuyruk', 'delete', null);

        $row = getDB()->query(
            "SELECT action, entity_label FROM sys_pending_sync WHERE domain='queues'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('delete', $row['action']);
        $this->assertSame('Silinen Kuyruk', $row['entity_label']);
    }

    public function testApiDirectApplyWorksWithValidCsrf(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_username'] = 'admin';

        $csrf = getCSRFToken();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;

        ob_start();
        require dirname(__DIR__, 2) . '/api/pending_sync.php';
        $out = ob_get_clean();

        $res = json_decode($out, true);
        $this->assertIsArray($res);
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame(0, $res['remaining_count']);
    }
}
