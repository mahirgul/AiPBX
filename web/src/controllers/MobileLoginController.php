<?php
require_once __DIR__ . '/../services/QrLoginService.php';

/**
 * /mobile-login?token=… — the anonymous page opened by the "Sign in to the
 * mobile app" link in the invitation email.
 *
 * On a phone: a button that opens the app (Android intent://, the Play Store
 * if the app is missing; iOS aipbx://). On a computer: a QR to scan with the
 * app. The page does NOT SPEND the code (email security scanners open the
 * link beforehand); the code is spent only when the app signs in through
 * /api/mobile/qr_login.php.
 */
class MobileLoginController extends BaseController
{
    public static function index(): void
    {
        // The token is in the URL: it must not leak to another site (the Play
        // Store link) via Referer or land in a search engine/proxy cache.
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');

        $info = QrLoginService::inspectToken((string) ($_GET['token'] ?? ''));

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $platform = 'desktop';
        if (stripos($ua, 'Android') !== false) {
            $platform = 'android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $platform = 'ios';
        }

        $data = [
            'info' => $info,
            'platform' => $platform,
            'site_title' => getSystemSetting('site_title', 'AI PBX Portalı'),
            'brand_title' => getSystemSetting('brand_title', 'AI PBX'),
            'brand_sub' => getSystemSetting('brand_sub', 'Santral & Çağrı Merkezi'),
            'play_url' => 'https://play.google.com/store/apps/details?id=' . QrLoginService::ANDROID_PACKAGE,
        ];

        if ($info['valid']) {
            $data['app_link'] = QrLoginService::appLink($info['server_url'], $info['token']);
            $data['android_link'] = QrLoginService::androidIntentLink($info['server_url'], $info['token']);
            $data['qr'] = QrLoginService::renderQr($info['payload']);
            $data['expires_at'] = $info['payload']['exp'];
        }

        static::renderAuthPage('mobile_login/index', $data, ['title' => t('mobile_login.page_title'), 'head' => '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">']);
    }
}
