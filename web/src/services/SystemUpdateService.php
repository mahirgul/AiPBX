<?php
require_once __DIR__ . '/../priv_helper.php';

/**
 * Sistem Güncelleme sayfası.
 *
 * Güncellemenin kendisi root yetkisiyle /usr/local/sbin/aipbx-update tarafından
 * yapılır (bkz. conf/sbin/aipbx-update); portal onu yalnızca aipbx-priv
 * üzerinden başlatır ve ilerlemeyi aipbx-update'in yazdığı durum dosyalarından
 * okur. Güncelleme sırasında Apache yeniden başladığı için işlem istek içinde
 * değil, ayrı bir systemd biriminde koşar.
 */
class SystemUpdateService
{
    public const CHECK_FILE = '/var/lib/aipbx/update-check.json';
    public const STATUS_FILE = '/var/lib/aipbx/update-status.json';
    public const LOG_FILE = '/var/log/aipbx/update.log';

    public static function currentVersion(): string
    {
        return AIPBX_VERSION;
    }

    /** Son sürüm kontrolünün sonucu (günlük cron veya "Kontrol et"). */
    public static function lastCheck(): ?array
    {
        return self::readJson(self::CHECK_FILE);
    }

    /** Son/süren güncellemenin durumu. */
    public static function status(): ?array
    {
        return self::readJson(self::STATUS_FILE);
    }

    public static function isRunning(): bool
    {
        $st = self::status();
        return $st !== null && ($st['state'] ?? '') === 'running';
    }

    /** Güncelleme günlüğünün son satırları (arayüzde ilerleme için). */
    public static function logTail(int $lines = 60): string
    {
        if (!is_readable(self::LOG_FILE)) {
            return '';
        }
        $size = filesize(self::LOG_FILE);
        $fh = fopen(self::LOG_FILE, 'rb');
        if (!$fh) {
            return '';
        }
        fseek($fh, max(0, $size - 64 * 1024));
        $data = (string) stream_get_contents($fh);
        fclose($fh);
        // install.sh renkli çıktı üretir; terminal renk kodları sayfada çöp gibi görünür.
        $data = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $data);
        $all = preg_split('/\r?\n/', rtrim($data));
        return implode("\n", array_slice($all, -$lines));
    }

    /** GitHub'da yeni sürüm var mı (aipbx-update --check). */
    public static function check(): array
    {
        $res = PrivHelper::run(['update', 'check']);
        $check = self::lastCheck();
        if (!$res['success'] || empty($check['ok'])) {
            return ['success' => false, 'error' => $check['error'] ?? ($res['output'] ?: 'Sürüm kontrolü yapılamadı.')];
        }
        return ['success' => true, 'check' => $check];
    }

    /** Güncellemeyi arka planda başlatır. */
    public static function start(bool $allowCalls): array
    {
        if (self::isRunning()) {
            return ['success' => false, 'error' => 'Bir güncelleme zaten sürüyor.'];
        }
        $args = ['update', 'start'];
        if ($allowCalls) {
            $args[] = '--allow-calls';
        }
        $res = PrivHelper::run($args);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['output'] ?: 'Güncelleme başlatılamadı.'];
        }
        writeAuditLog(null, 'system', 'update', 'Sistem güncellemesi başlatıldı (' . AIPBX_VERSION . ')', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true];
    }

    private static function readJson(string $file): ?array
    {
        if (!is_readable($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
}
