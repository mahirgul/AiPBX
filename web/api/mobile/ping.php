<?php
require_once __DIR__ . '/auth_helper.php';
mobileApiStart('GET, OPTIONS');

$site_title = getSystemSetting('site_title', 'AI PBX');
$brand_title = getSystemSetting('brand_title', 'AI PBX');
$brand_sub = getSystemSetting('brand_sub', t('mobile_api.brand_sub_default'));

// The company's own logo and colour for the apps (Appearance page). Paths on
// this server; empty when the default AiPBX look is in use. The apps show
// PNG, JPEG and WebP; for an SVG logo they fall back to the favicon.
$own = static function (string $key): string {
    $v = (string) getSystemSetting($key, '');
    return str_starts_with($v, '/') && !str_starts_with($v, '//') ? $v : '';
};
$logo_type = (string) getSystemSetting('site_logo_type', 'image');
$color = (string) getSystemSetting('brand_color_primary', '');

mobileJson([
    'success' => true,
    'service' => 'AI-PBX',
    'version' => '1.0',
    'site_title' => $site_title,
    'brand_title' => $brand_title,
    'brand_sub' => $brand_sub,
    'logo_url' => $logo_type === 'image' ? $own('site_logo_image') : '',
    'logo_url_dark' => $logo_type === 'image' ? $own('site_logo_image_dark') : '',
    'logo_style' => getSystemSetting('site_logo_style', 'square') === 'wide' ? 'wide' : 'square',
    'favicon_url' => $own('site_favicon_url'),
    'brand_color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '',
    'server_time' => time()
]);
