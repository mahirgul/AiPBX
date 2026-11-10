#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/core/PluginManager.php';
require_once dirname(__DIR__) . '/src/services/BundledPluginMigrationService.php';
BundledPluginMigrationService::migrateVoicemail(getDB(), PluginManager::customDir());
BundledPluginMigrationService::migrateAutoDialer(getDB(), PluginManager::customDir());
BundledPluginMigrationService::migrateAssistant(getDB(), PluginManager::customDir());
