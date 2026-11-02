<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/text_fax_helper.php';

/**
 * The cover page of a sent fax: one template for the whole organisation, set on the Fax Units page.
 * The cover is made as a PDF and goes in front of the document before it becomes a fax image, so the
 * archived PDF shows what was sent.
 */
final class FaxCoverService
{
    public const MODES = ['off', 'optional', 'required'];

    /** @return array{mode: string, company: string, notice: string, logo: bool} */
    public static function settings(): array
    {
        $mode = (string) getSystemSetting('fax_cover_mode', 'off');
        return [
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'off',
            'company' => (string) getSystemSetting('fax_cover_company', ''),
            'notice' => (string) getSystemSetting('fax_cover_notice', ''),
            'logo' => getSystemSetting('fax_cover_logo', '0') === '1',
        ];
    }

    public static function saveSettings(array $post): array
    {
        if (!verifyCSRFToken($post['csrf_token'] ?? '')) {
            return ['success' => false, 'error' => t('common.invalid_csrf')];
        }
        $values = [
            'fax_cover_mode' => in_array($post['fax_cover_mode'] ?? '', self::MODES, true) ? $post['fax_cover_mode'] : 'off',
            'fax_cover_company' => mb_substr(trim((string) ($post['fax_cover_company'] ?? '')), 0, 120),
            'fax_cover_notice' => mb_substr(trim((string) ($post['fax_cover_notice'] ?? '')), 0, 1000),
            'fax_cover_logo' => empty($post['fax_cover_logo']) ? '0' : '1',
        ];
        $stmt = getDB()->prepare('INSERT INTO sys_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        writeAuditLog(null, 'fax_cover', 'general', 'Fax cover page settings', 'update', $_SESSION['user_id'] ?? null);
        return ['success' => true, 'message' => t('fax_cover.saved')];
    }

    /** Should this fax get a cover? An administrator's "required" cannot be turned off in the form. */
    public static function wanted(array $post, ?array $settings = null): bool
    {
        $mode = ($settings ?? self::settings())['mode'];
        return $mode === 'required' || ($mode === 'optional' && !empty($post['cover_enabled']));
    }

    /**
     * What the sender typed, limited in length.
     *
     * @return array{to: string, subject: string, message: string}
     */
    public static function fieldsFromPost(array $post): array
    {
        return [
            'to' => mb_substr(trim((string) ($post['cover_to'] ?? '')), 0, 120),
            'subject' => mb_substr(trim((string) ($post['cover_subject'] ?? '')), 0, 200),
            'message' => mb_substr(trim((string) ($post['cover_message'] ?? '')), 0, 2000),
        ];
    }

    /**
     * The page as HTML for mPDF. Everything from people is escaped, so no tag or address in it can
     * reach mPDF. Black on white only: a fax has no greys.
     *
     * @param array{to: string, subject: string, message: string} $fields
     * @param array{company: string, notice: string} $settings
     * @param array{name: string, number: string, to_number: string, date: string, pages: int} $facts
     */
    public static function html(array $fields, array $settings, array $facts, ?string $logoPath = null): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $row = static fn(string $label, string $value): string => '<tr><td style="width: 32%; padding: 6px 0; font-weight: bold; border-bottom: 1px solid #000;">'
            . $e($label) . '</td><td style="padding: 6px 0; border-bottom: 1px solid #000;">' . nl2br($e($value)) . '</td></tr>';

        $html = '';
        if ($logoPath !== null) {
            $html .= '<div style="margin-bottom: 8mm;"><img src="' . $e($logoPath) . '" style="max-height: 22mm; max-width: 70mm;"></div>';
        }
        if ($settings['company'] !== '') {
            $html .= '<div style="font-size: 16pt; font-weight: bold;">' . $e($settings['company']) . '</div>';
        }
        $html .= '<div style="font-size: 30pt; font-weight: bold; margin: 8mm 0 8mm;">FAX</div>';
        $html .= '<table style="width: 100%; font-size: 12pt; border-collapse: collapse;">'
            . $row(t('fax_cover.label_to'), trim($fields['to'] . "\n" . $facts['to_number']))
            . $row(t('fax_cover.label_from'), trim($facts['name'] . "\n" . $facts['number']))
            . $row(t('fax_cover.label_date'), $facts['date'])
            . $row(t('fax_cover.label_pages'), (string) $facts['pages'])
            . $row(t('fax_cover.label_subject'), $fields['subject'])
            . '</table>';
        if ($fields['message'] !== '') {
            $html .= '<div style="font-size: 12pt; margin-top: 10mm;">' . nl2br($e($fields['message'])) . '</div>';
        }
        if ($settings['notice'] !== '') {
            $html .= '<div style="font-size: 9pt; margin-top: 14mm; border-top: 2px solid #000; padding-top: 3mm;">' . nl2br($e($settings['notice'])) . '</div>';
        }
        return $html;
    }

    /** The logo from the Appearance page as a file on this server; null if there is none. */
    public static function logoPath(): ?string
    {
        $url = (string) preg_replace('/\?.*$/', '', (string) getSystemSetting('site_logo_image', ''));
        if ($url === '') {
            return null;
        }
        require_once __DIR__ . '/BrandSettingsService.php';
        $path = BrandSettingsService::uploadDir() . '/' . basename($url);
        return is_file($path) && preg_match('/\.(png|jpe?g)$/i', $path) ? $path : null;
    }

    /** @return array{name: string, number: string, to_number: string, date: string, pages: int} */
    private static function facts(string $senderExt, string $toNumber, int $pages): array
    {
        $row = DBHelper::fetchOne('SELECT full_name, cid_external FROM sys_users WHERE extension = ? LIMIT 1', [$senderExt]) ?: [];
        return [
            'name' => (string) ($row['full_name'] ?? ''),
            'number' => (string) (($row['cid_external'] ?? '') ?: $senderExt),
            'to_number' => $toNumber,
            'date' => date('Y-m-d H:i'),
            'pages' => $pages,
        ];
    }

    /** Pages of a PDF, counted the way the fax itself will be: by making the image. */
    public static function pageCount(string $pdf): int
    {
        $tif = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        exec(escapeshellarg(GS_BINARY) . ' -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=tiffg4 -r50 -sOutputFile=' . escapeshellarg($tif) . ' ' . escapeshellarg($pdf) . ' 2>/dev/null');
        $count = (int) trim((string) shell_exec('tiffinfo ' . escapeshellarg($tif) . " 2>/dev/null | grep -c 'TIFF Directory'"));
        @unlink($tif);
        return $count;
    }

    /**
     * Puts the cover in front of a PDF, replacing the file.
     *
     * @param array{to: string, subject: string, message: string} $fields
     */
    public static function prepend(string $pdf, array $fields, string $senderExt, string $toNumber): void
    {
        $settings = self::settings();
        $pages = self::pageCount($pdf);
        if ($pages < 1) {
            throw new \RuntimeException(t('fax_cover.err_read'));
        }
        $cover = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        $merged = $pdf . '.merged';
        try {
            TextFaxHelper::htmlToPdf(
                self::html($fields, $settings, self::facts($senderExt, $toNumber, $pages + 1), $settings['logo'] ? self::logoPath() : null),
                $cover
            );
            exec(escapeshellarg(GS_BINARY) . ' -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -sOutputFile=' . escapeshellarg($merged) . ' ' . escapeshellarg($cover) . ' ' . escapeshellarg($pdf) . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0 || !is_file($merged) || filesize($merged) === 0 || !rename($merged, $pdf)) {
                throw new \RuntimeException(t('fax_cover.err_read'));
            }
        } finally {
            @unlink($cover);
            @unlink($merged);
        }
    }

    /**
     * The cover as the far end will receive it: black and white at fax resolution.
     *
     * @param array{company: string, notice: string, logo: bool} $settings
     */
    public static function previewPng(array $fields, array $settings, string $senderExt, string $toNumber): string
    {
        $cover = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        $png = tempnam(sys_get_temp_dir(), 'aipbx_cover');
        try {
            TextFaxHelper::htmlToPdf(
                self::html($fields, $settings, self::facts($senderExt, $toNumber, 1), $settings['logo'] ? self::logoPath() : null),
                $cover
            );
            exec(escapeshellarg(GS_BINARY) . ' -q -dSAFER -dNOPAUSE -dBATCH -sDEVICE=pngmono -r204x196 -dFirstPage=1 -dLastPage=1 -sOutputFile=' . escapeshellarg($png) . ' ' . escapeshellarg($cover) . ' 2>/dev/null');
            return (string) file_get_contents($png);
        } finally {
            @unlink($cover);
            @unlink($png);
        }
    }
}
