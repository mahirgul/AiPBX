<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/repositories/RoleRepository.php';

final class AiSettingsMergeTest extends TestCase
{
    public function testLegacyUnifiedAiPluginAndSettingsAreRetired(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertDirectoryDoesNotExist($root . '/plugins/ai');
        $this->assertFileDoesNotExist($root . '/web/src/controllers/AiSettingsController.php');
        $this->assertDirectoryDoesNotExist($root . '/web/templates/views/ai_settings');

        $routes = require $root . '/web/src/routes.php';
        $this->assertArrayNotHasKey('/ai-settings', $routes);
        $this->assertArrayHasKey('/ai-local', $routes);
        $this->assertArrayHasKey('/ai-cloud', $routes);
        $this->assertArrayHasKey('/ai-voicemail', $routes);
        $this->assertArrayHasKey('/ai-call-analysis', $routes);

        $indexSrc = file_get_contents($root . '/web/index.php');
        $this->assertIsString($indexSrc);
        $this->assertStringContainsString("'/ai-models'               => '/ai-local'", $indexSrc);
        $this->assertStringContainsString("'/ai-settings'             => '/ai-local'", $indexSrc);

        $this->assertArrayNotHasKey('ai_settings', RoleRepository::modulesDefinition());
    }
}
