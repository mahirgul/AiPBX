<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, OPTIONS');

$site_title = getSystemSetting('site_title', 'AI PBX');
$brand_title = getSystemSetting('brand_title', 'AI PBX');
$brand_sub = getSystemSetting('brand_sub', 'İletişim Sistemi');

mobileJson([
    'success' => true,
    'service' => 'AI-PBX',
    'version' => '1.0',
    'site_title' => $site_title,
    'brand_title' => $brand_title,
    'brand_sub' => $brand_sub,
    'server_time' => time()
]);
