<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/SsoOnlyService.php';

/** A redirect on /login made every update on an SSO-only system roll back. */
final class SsoOnlyLoginTest extends TestCase
{
    public function testOnlyABrowserGoesOnToTheProvider(): void
    {
        $this->assertTrue(SsoOnlyService::redirectsToProvider('text/html,application/xhtml+xml,*/*;q=0.8'));
        $this->assertFalse(SsoOnlyService::redirectsToProvider('*/*'), 'curl: update and health check');
        $this->assertFalse(SsoOnlyService::redirectsToProvider(''));
    }
}
