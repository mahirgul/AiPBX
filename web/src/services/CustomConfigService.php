<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../asterisk_helper.php';
require_once __DIR__ . '/../sync/ConfWriter.php';      // writePBXConf(): the write layer, not the orchestrator

/**
 * Manages Issabel-style *_custom.conf Asterisk include files (/etc/asterisk/pbx/*_custom.conf).
 * Admins only.
 */
final class CustomConfigService
{
    public const FILES = [
        'extensions' => [
            'filename' => 'extensions_zz_custom.conf',
            'reload' => 'dialplan reload',
            'first_value_wins' => false,
        ],
        'pjsip' => [
            'filename' => 'pjsip_zz_custom.conf',
            'reload' => 'pjsip reload',
            'first_value_wins' => false,
        ],
        'queues' => [
            'filename' => 'queues_custom.conf',
            'reload' => 'queue reload all',
            'first_value_wins' => false,
        ],
        'musiconhold' => [
            'filename' => 'musiconhold_custom.conf',
            'reload' => 'moh reload',
            'first_value_wins' => false,
        ],
        'voicemail' => [
            'filename' => 'voicemail_custom.conf',
            'reload' => 'voicemail reload',
            'first_value_wins' => true,
        ],
        'features' => [
            'filename' => 'features_custom.conf',
            'reload' => 'module reload features',
            'first_value_wins' => false,
        ],
        'confbridge' => [
            'filename' => 'confbridge_custom.conf',
            'reload' => 'module reload app_confbridge',
            'first_value_wins' => false,
        ],
        'manager' => [
            'filename' => 'manager_custom.conf',
            'reload' => 'module reload manager',
            'first_value_wins' => false,
        ],
        'cdr' => [
            'filename' => 'cdr_custom.conf',
            'reload' => 'module reload cdr',
            'first_value_wins' => false,
        ],
        'logger' => [
            'filename' => 'logger_custom.conf',
            'reload' => 'module reload logger',
            'first_value_wins' => true,
        ],
        'rtp' => [
            'filename' => 'rtp_custom.conf',
            'reload' => 'module reload res_rtp_asterisk.so',
            'first_value_wins' => true,
        ],
        'udptl' => [
            'filename' => 'udptl_custom.conf',
            'reload' => 'module reload udptl',
            'first_value_wins' => false,
        ],
        'indications' => [
            'filename' => 'indications_custom.conf',
            'reload' => 'module reload indications',
            'first_value_wins' => false,
        ],
        'res_parking' => [
            'filename' => 'res_parking_custom.conf',
            'reload' => 'module reload res_parking.so',
            'first_value_wins' => false,
        ],
    ];

    public static function ensureFilesExist(): void
    {
        if (!is_dir(ASTERISK_PBX_DIR)) {
            @mkdir(ASTERISK_PBX_DIR, 0755, true);
        }
        foreach (self::FILES as $key => $meta) {
            $path = ASTERISK_PBX_DIR . '/' . $meta['filename'];
            if (!is_file($path)) {
                $header = "; Custom {$key} configuration (included at end of {$key}.conf)\n";
                @FileHelper::writeFile($path, $header, 'asterisk', 'asterisk', 0640);
            }
        }
    }

    public static function listFiles(): array
    {
        self::ensureFilesExist();
        $out = [];
        foreach (self::FILES as $key => $meta) {
            $content = self::read($key);
            $out[$key] = $meta + [
                'key' => $key,
                'has_content' => self::hasNonCommentLines($content),
            ];
        }
        return $out;
    }

    public static function hasNonCommentLines(string $content): bool
    {
        foreach (explode("\n", $content) as $line) {
            $trim = trim($line);
            if ($trim !== '' && $trim[0] !== ';') {
                return true;
            }
        }
        return false;
    }

    public static function read(string $key): string
    {
        if (!isset(self::FILES[$key])) {
            return '';
        }
        $path = ASTERISK_PBX_DIR . '/' . self::FILES[$key]['filename'];
        if (!is_file($path)) {
            return "; Custom {$key} configuration (included at end of {$key}.conf)\n";
        }
        $raw = @file_get_contents($path);
        return is_string($raw) ? $raw : '';
    }

    /**
     * The administrator's own file: anything Asterisk reads is allowed (AMI users, mailcmd ...),
     * that is their choice. Only # directives are refused, and the portal's own AMI login in
     * manager.conf, without which call events and the web phone stop.
     */
    public static function validateContent(string $content, string $key): ?string
    {
        if (strpos($content, "\0") !== false) {
            return t('custom_configs.err_nul');
        }
        if (preg_match('/^\s*#(exec|include|tryinclude)\b/mi', $content)) {
            return t('custom_configs.err_directive');
        }
        $ami = defined('AMI_USER') ? strtolower((string) AMI_USER) : '';
        if ($key === 'manager' && $ami !== '' && preg_match_all('/^\s*\[([^\]]+)\]/m', $content, $m)) {
            foreach ($m[1] as $section) {
                if (strtolower(trim($section)) === $ami) {
                    return sprintf(t('advanced_config.err_denied_section'), $section);
                }
            }
        }
        return null;
    }

    /** execCLI() reports success for a refused or unknown command too: read its answer. */
    private static function reload(string $command): array
    {
        $r = AsteriskHelper::execCLI($command);
        $out = (string) ($r['output'] ?? '');
        if (preg_match("/don't have permissions|No such (module|command)|Unable to|failed/i", $out)) {
            $r['success'] = false;
        }
        return $r;
    }

    public static function save(string $key, string $content, string $csrfToken): array
    {
        if (!verifyCSRFToken($csrfToken)) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        if (!isset(self::FILES[$key])) {
            return ['success' => false, 'error' => t('custom_configs.err_unknown_file')];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        if ($normalized !== '' && substr($normalized, -1) !== "\n") {
            $normalized .= "\n";
        }

        $validationError = self::validateContent($normalized, $key);
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }

        self::ensureFilesExist();
        $meta = self::FILES[$key];
        $filename = $meta['filename'];
        $path = ASTERISK_PBX_DIR . '/' . $filename;
        $previous = is_file($path) ? @file_get_contents($path) : false;

        try {
            writePBXConf($filename, $normalized);
            AsteriskHelper::assertReloadsOk([self::reload($meta['reload'])], $filename);
        } catch (\Throwable $e) {
            if (is_string($previous)) {
                try {
                    writePBXConf($filename, $previous);
                    AsteriskHelper::execCLI($meta['reload']);
                } catch (\Throwable $ignored) {
                }
            }
            return ['success' => false, 'error' => sprintf(t('custom_configs.err_save_reload'), $filename, $e->getMessage())];
        }

        writeAuditLog('system', 'custom_config', 0, $filename, 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => sprintf(t('custom_configs.msg_saved'), $filename)];
    }
}
