<?php
require_once __DIR__ . '/../services/QrLoginService.php';

/**
 * /mobile-login?token=… — davet e-postasındaki "Mobil uygulamaya giriş"
 * bağlantısının açtığı anonim sayfa.
 *
 * Telefonda: uygulamayı açan buton (Android intent://, uygulama yoksa Play
 * Store; iOS aipbx://). Bilgisayarda: uygulamayla okutulacak QR. Sayfa kodu
 * HARCAMAZ (e-posta güvenlik tarayıcıları bağlantıyı önceden açar); kod
 * yalnızca uygulama /api/mobile/qr_login.php ile giriş yaptığında harcanır.
 */
class MobileLoginController extends BaseController
{
    public static function index(): void
    {
        // Token URL'de: başka siteye (Play Store bağlantısı) Referer ile
        // sızmasın, arama motoru/proxy önbelleğine girmesin.
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
