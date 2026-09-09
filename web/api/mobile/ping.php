<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config.php';

$site_title = getSystemSetting('site_title', 'AI PBX');
$brand_title = getSystemSetting('brand_title', 'AI PBX');
$brand_sub = getSystemSetting('brand_sub', 'İletişim Sistemi');

echo json_encode([
    'success' => true,
    'service' => 'AI-PBX',
    'version' => '1.0',
    'site_title' => $site_title,
    'brand_title' => $brand_title,
    'brand_sub' => $brand_sub,
    'server_time' => time()
], JSON_UNESCAPED_UNICODE);