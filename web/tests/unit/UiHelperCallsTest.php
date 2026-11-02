<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A call to a UIHelper method that does not exist fails only when someone clicks: Add, Edit and Copy
 * of Outbound Call Normalization stayed broken that way, past every other check.
 */
final class UiHelperCallsTest extends TestCase
{
    public function testEveryUiHelperMethodThatIsCalledExists(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root . '/assets/js/ui_helper.js');
        preg_match_all('/^ {4}(?:async +)?([a-zA-Z]+)\s*\(/m', $helper, $m);
        $defined = array_flip($m[1]);

        $files = array_merge(
            glob($root . '/assets/js/*.js') ?: [],
            glob($root . '/templates/*.php') ?: [],
            glob($root . '/templates/*/*.php') ?: [],
            glob($root . '/templates/*/*/*.php') ?: [],
        );
        $this->assertGreaterThan(50, count($files));
        foreach ($files as $file) {
            if (str_ends_with($file, '.min.js')) {
                continue;
            }
            preg_match_all('/UIHelper\.([a-zA-Z]+)/', (string) file_get_contents($file), $calls);
            foreach ($calls[1] as $method) {
                $this->assertArrayHasKey($method, $defined, basename($file) . " calls UIHelper.{$method}");
            }
        }
    }

    public function testFormPostsRespectCancelInAConfirmation(): void
    {
        $router = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/js/spa_router.js');
        $at = strpos($router, "document.body.addEventListener('submit'");
        $this->assertNotFalse($at);
        $this->assertStringContainsString('if (e.defaultPrevented) return;', substr($router, (int) $at, 200), 'without it, Cancel still deletes');
    }
}
