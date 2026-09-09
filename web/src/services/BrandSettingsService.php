<?php
/**
 * Brand & Appearance (Marka & Görünüm) Service
 */
require_once __DIR__ . '/../asterisk_sync.php';

class BrandSettingsService {
    const UPLOAD_DIR_SUFFIX = '/assets/images/brand';
    const UPLOAD_URL = '/assets/images/brand';

    public static function defaults(): array
    {
        return [
            'site_title' => 'AI PBX Portalı',
            'brand_title' => 'AI PBX',
            'brand_sub' => 'Santral & Çağrı Merkezi',
            'site_logo_type' => 'icon',
            'site_logo_icon' => 'fa-network-wired',
            'site_logo_image' => '',
            'site_favicon_url' => '',
            'brand_color_primary' => '',
            'brand_color_secondary' => '',
        ];
    }

    public static function uploadDir(): string
    {
        return dirname(__DIR__, 2) . self::UPLOAD_DIR_SUFFIX;
    }

    /**
     * Logo/favicon dosyasını doğrulayıp uploadDir() altına kaydeder.
     * Aynı $baseName ile eski uzantılı dosyaları temizler (birikmiş yetim dosya kalmasın diye).
     * Yeni dosya yoksa null döner (mevcut ayar korunur).
     */
    public static function saveBrandUpload($fileKey, $baseName, array $allowedExt, $maxBytes)
    {
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('Dosya yüklenirken hata oluştu (kod: ' . $_FILES[$fileKey]['error'] . ')');
        }
        if ($_FILES[$fileKey]['size'] > $maxBytes) {
            throw new \Exception('Dosya çok büyük (maks ' . round($maxBytes / 1024 / 1024, 1) . 'MB).');
        }
        $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            throw new \Exception('Desteklenmeyen dosya türü (.' . htmlspecialchars($ext) . '). İzin verilenler: ' . implode(', ', $allowedExt));
        }
        $uploadDir = self::uploadDir();
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        foreach (glob($uploadDir . '/' . $baseName . '.*') as $old) {
            @unlink($old);
        }
        $target = $uploadDir . '/' . $baseName . '.' . $ext;
        if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $target)) {
            throw new \Exception('Dosya kaydedilemedi (yazma izni sorunu olabilir).');
        }
        @chmod($target, 0644);

        // SVG içine gömülü <script>/olay-işleyici (onload= vb.) tarayıcının bu
        // dosyayı DOĞRUDAN (bir <img> üzerinden değil) açması durumunda çalışabilir
        // — logo her yerde <img src="..."> ile kullanıldığı için bu yol normal
        // kullanımda tetiklenmiyor, ama dosyanın URL'sine doğrudan gidilirse (ör.
        // yeni sekmede açma) risk oluşturuyordu (2026-08-21 denetiminde bulundu).
        // Basit ama etkili bir temizlik: script bloklarını, on*= olay işleyicilerini
        // ve javascript: URI'larını kaldır.
        if ($ext === 'svg') {
            $svg = @file_get_contents($target);
            if ($svg !== false) {
                $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);
                $svg = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $svg);
                $svg = preg_replace('/(href|xlink:href)\s*=\s*(["\'])\s*javascript:.*?\2/is', '$1=$2#$2', $svg);
                file_put_contents($target, $svg);
            }
        }
        return self::UPLOAD_URL . '/' . $baseName . '.' . $ext . '?v=' . time();
    }

    public static function isValidHexColor($v)
    {
        return $v === '' || preg_match('/^#[0-9A-Fa-f]{6}$/', $v);
    }

    /**
     * @return array{success:bool, message?:string, error?:string}
     */
    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => 'Geçersiz CSRF güvenlik doğrulama kodu!'];
        }

        $defaults = self::defaults();
        $uploadDir = self::uploadDir();

        try {
            $db = getDB();
            $current = array_merge($defaults, $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR));

            $primary = trim($post['brand_color_primary'] ?? '');
            $secondary = trim($post['brand_color_secondary'] ?? '');
            if (!self::isValidHexColor($primary) || !self::isValidHexColor($secondary)) {
                throw new \Exception('Renk kodları #RRGGBB biçiminde olmalı (ör. #0284c7).');
            }

            $new_settings = [
                'site_title' => trim($post['site_title'] ?? $defaults['site_title']) ?: $defaults['site_title'],
                'brand_title' => trim($post['brand_title'] ?? $defaults['brand_title']) ?: $defaults['brand_title'],
                'brand_sub' => trim($post['brand_sub'] ?? $defaults['brand_sub']) ?: $defaults['brand_sub'],
                'site_logo_type' => (($post['site_logo_type'] ?? 'icon') === 'image') ? 'image' : 'icon',
                'site_logo_icon' => trim($post['site_logo_icon'] ?? $defaults['site_logo_icon']) ?: $defaults['site_logo_icon'],
                'site_logo_image' => $current['site_logo_image'],
                'site_favicon_url' => $current['site_favicon_url'],
                'brand_color_primary' => $primary,
                'brand_color_secondary' => $secondary,
            ];

            if (!empty($post['remove_logo_image'])) {
                foreach (glob($uploadDir . '/logo.*') as $old) { @unlink($old); }
                $new_settings['site_logo_image'] = '';
            }
            if (!empty($post['remove_favicon'])) {
                foreach (glob($uploadDir . '/favicon.*') as $old) { @unlink($old); }
                $new_settings['site_favicon_url'] = '';
            }

            $uploaded_logo = self::saveBrandUpload('logo_file', 'logo', ['png', 'jpg', 'jpeg', 'svg', 'webp'], 2 * 1024 * 1024);
            if ($uploaded_logo !== null) {
                $new_settings['site_logo_image'] = $uploaded_logo;
                $new_settings['site_logo_type'] = 'image';
            }

            $uploaded_favicon = self::saveBrandUpload('favicon_file', 'favicon', ['ico', 'png'], 512 * 1024);
            if ($uploaded_favicon !== null) {
                $new_settings['site_favicon_url'] = $uploaded_favicon;
            }

            $stmt = $db->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($new_settings as $k => $v) {
                $stmt->execute([$k, $v]);
            }

            writeAuditLog(null, 'brand_settings', 'general', 'Marka & Görünüm Ayarları güncellendi', 'update', $_SESSION['user_id'] ?? null);
            return ['success' => true, 'message' => 'Marka & görünüm ayarları kaydedildi!'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
