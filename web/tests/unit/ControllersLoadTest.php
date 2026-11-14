<?php

use PHPUnit\Framework\TestCase;

/**
 * Every routed controller class can be loaded. A clash with BaseController
 * (a private method named like a protected one) is a fatal error only when
 * the page is opened, so no other test would see it.
 */
final class ControllersLoadTest extends TestCase
{
    public function testEveryRoutedControllerLoads(): void
    {
        $routes = require __DIR__ . '/../../src/routes.php';
        $classes = [];
        foreach ($routes as $r) {
            if (is_array($r) && !empty($r['controller'])) {
                $classes[$r['controller']] = true;
            }
        }
        $this->assertNotEmpty($classes);
        foreach (array_keys($classes) as $class) {
            $this->assertTrue(class_exists($class), "$class does not load");
        }
    }
}
