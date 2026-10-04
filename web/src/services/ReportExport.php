<?php
require_once __DIR__ . '/XlsxWriter.php';

/**
 * Branded PDF and Excel exports of the report pages.
 *
 * A report describes itself once and both formats are produced from it:
 *
 *   [
 *     'title'    => 'Call Reports',
 *     'period'   => '01.10.2026 – 03.10.2026',
 *     'filters'  => ['Direction' => 'Inbound', ...],          // only the ones in use
 *     'kpis'     => [['label', 'value', 'sub'], ...],          // headline tiles
 *     'sections' => [[
 *         'title'   => 'Calls',
 *         'note'    => 'optional line under the title',
 *         'chart'   => ['labels' => [...], 'series' => [['label', 'colour key', [values]]]],
 *         'columns' => [['Caller', 'text'], ['Talk', 'dur'], ...],   // text|int|dur|pct|datetime|number
 *         'rows'    => [[raw values], ...],
 *         'pdf_columns' => [0, 2, 5],   // optional: columns the PDF shows (wide tables)
 *         'total'   => 12345,           // optional: row count when rows were capped
 *     ], ...],
 *   ]
 *
 * Values stay raw (seconds, timestamps, percentages 0-100): the PDF formats
 * them for reading, the workbook stores real numbers and dates with Excel
 * formats so they can be summed and filtered.
 */
final class ReportExport
{
    /** A PDF is for reading; the workbook carries everything. */
    public const PDF_MAX_ROWS = 2000;
    public const XLSX_MAX_ROWS = 100000;

    /** @return array{title: string, sub: string, color: string, logo: ?string} */
    public static function brand(): array
    {
        $color = (string) getSystemSetting('brand_color_primary', '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#0284c7';   // the light theme's primary: prints well
        }
        $logo = null;
        if (getSystemSetting('site_logo_type', 'image') === 'image') {
            $url = (string) getSystemSetting('site_logo_image', BRAND_DEFAULT_LOGO_URL);
            $path = realpath(dirname(__DIR__, 2) . '/' . ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/'));
            $webRoot = realpath(dirname(__DIR__, 2));
            // mPDF reads png/jpg/svg; anything else (webp) falls back to the default logo.
            if ($path && $webRoot && str_starts_with($path, $webRoot . '/') && preg_match('/\.(png|jpe?g|svg)$/i', $path)) {
                $logo = $path;
            } else {
                $logo = realpath(dirname(__DIR__, 2) . BRAND_DEFAULT_LOGO_URL) ?: null;
            }
        }
        return [
            'title' => (string) getSystemSetting('brand_title', 'AiPBX'),
            'sub' => (string) getSystemSetting('brand_sub', ''),
            'color' => strtolower($color),
            'logo' => $logo,
        ];
    }

    /** Text value of a cell for reading (PDF). */
    public static function format($v, string $type): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        switch ($type) {
            case 'dur':
                $s = max(0, (int) $v);
                return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
            case 'pct':
                return '%' . number_format((float) $v, 1, ',', '.');
            case 'datetime':
                return date('d.m.Y H:i:s', (int) $v);
            case 'int':
                return number_format((int) $v, 0, ',', '.');
            case 'number':
                return number_format((float) $v, 2, ',', '.');
            default:
                return (string) $v;
        }
    }

    /** Cell for the workbook: real numbers with the matching Excel format. */
    private static function xlsxCell($v, string $type)
    {
        if ($v === null || $v === '') {
            return '';
        }
        return match ($type) {
            'dur' => ['v' => max(0, (int) $v) / 86400, 's' => XlsxWriter::S_DURATION],
            'pct' => ['v' => round((float) $v / 100, 4), 's' => XlsxWriter::S_PERCENT],
            'datetime' => ['v' => XlsxWriter::serialDate((int) $v), 's' => XlsxWriter::S_DATETIME],
            'int' => ['v' => (int) $v, 's' => XlsxWriter::S_INT],
            'number' => ['v' => (float) $v, 's' => XlsxWriter::S_DECIMAL],
            // Text stays text: phone numbers keep their leading 0 and "+".
            default => (string) $v,
        };
    }

    private static function meta(array $doc): array
    {
        $user = function_exists('getCurrentUser') ? (getCurrentUser() ?: []) : [];
        return [
            'generated' => date('d.m.Y H:i'),
            'by' => trim((string) ($user['full_name'] ?? $user['username'] ?? '')),
        ];
    }

    public static function xlsx(array $doc): string
    {
        $brand = self::brand();
        $meta = self::meta($doc);
        $w = new XlsxWriter($brand['color']);

        // Summary sheet: what this is, when, which filters, the headline figures.
        $rows = [
            [['v' => $brand['title'] . ' · ' . $doc['title'], 's' => XlsxWriter::S_TITLE]],
            [['v' => $doc['period'] ?? '', 's' => XlsxWriter::S_MUTED]],
            [['v' => sprintf(t('export.generated_by'), $meta['generated'], $meta['by'] ?: '-'), 's' => XlsxWriter::S_MUTED]],
            [],
        ];
        if (!empty($doc['filters'])) {
            $rows[] = [['v' => t('export.filters'), 's' => XlsxWriter::S_BOLD]];
            foreach ($doc['filters'] as $label => $value) {
                $rows[] = [(string) $label, (string) $value];
            }
            $rows[] = [];
        }
        if (!empty($doc['kpis'])) {
            $rows[] = [['v' => t('export.summary'), 's' => XlsxWriter::S_BOLD]];
            foreach ($doc['kpis'] as $k) {
                $rows[] = [(string) $k[0], (string) $k[1], (string) ($k[2] ?? '')];
            }
        }
        $w->addSheet(t('export.summary'), $rows, [34, 22, 40]);

        foreach ($doc['sections'] as $sec) {
            if (empty($sec['columns'])) {
                continue;
            }
            $sheet = [[['v' => $sec['title'], 's' => XlsxWriter::S_TITLE]]];
            if (!empty($sec['note'])) {
                $sheet[] = [['v' => $sec['note'], 's' => XlsxWriter::S_MUTED]];
            }
            $headerRow = count($sheet) + 1;
            $sheet[] = array_map(fn($c) => ['v' => $c[0], 's' => XlsxWriter::S_HEADER], $sec['columns']);
            $widths = array_map(fn($c) => max(10, mb_strlen($c[0]) + 2), $sec['columns']);
            foreach (array_slice($sec['rows'], 0, self::XLSX_MAX_ROWS) as $row) {
                $cells = [];
                foreach ($sec['columns'] as $i => [$label, $type]) {
                    $v = $row[$i] ?? '';
                    $cells[] = self::xlsxCell($v, $type);
                    $len = $type === 'datetime' ? 19 : mb_strlen(self::format($v, $type));
                    $widths[$i] = min(60, max($widths[$i], $len + 2));
                }
                $sheet[] = $cells;
            }
            $n = count($sec['columns']);
            $w->addSheet($sec['title'], $sheet, $widths, $headerRow + 1, [$headerRow, 1, count($sheet), $n]);
        }
        return $w->build();
    }

    public static function pdf(array $doc): string
    {
        $brand = self::brand();
        $meta = self::meta($doc);
        $tmpDir = sys_get_temp_dir() . '/aipbx_mpdf';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0770, true);
        }
        $wide = max(array_map(fn($s) => count($s['pdf_columns'] ?? $s['columns'] ?? []), $doc['sections'] ?: [[]])) > 7;
        $mpdf = new \Mpdf\Mpdf([
            'format' => $wide ? 'A4-L' : 'A4',
            'tempDir' => $tmpDir,
            'default_font' => 'dejavusans',
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 30, 'margin_bottom' => 16,
            'margin_header' => 8, 'margin_footer' => 8,
        ]);
        $mpdf->SetTitle($brand['title'] . ' - ' . $doc['title']);
        $mpdf->SetAuthor($brand['title']);
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $c = $brand['color'];

        $logo = $brand['logo'] ? '<img src="' . $h($brand['logo']) . '" style="height: 34px;" />' : '';
        $header = '<table width="100%" style="border-bottom: 2px solid ' . $c . '; padding-bottom: 4px;"><tr>'
            . '<td width="50" style="vertical-align: middle;">' . $logo . '</td>'
            . '<td style="vertical-align: middle;"><div style="font-size: 13pt; font-weight: bold; color: #0f172a;">' . $h($brand['title']) . '</div>'
            . '<div style="font-size: 8pt; color: #64748b;">' . $h($brand['sub']) . '</div></td>'
            . '<td style="text-align: right; vertical-align: middle;"><div style="font-size: 13pt; font-weight: bold; color: ' . $c . ';">' . $h($doc['title']) . '</div>'
            . '<div style="font-size: 8pt; color: #64748b;">' . $h($doc['period'] ?? '') . '</div></td>'
            . '</tr></table>';
        $footer = '<table width="100%" style="font-size: 7.5pt; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 3px;"><tr>'
            . '<td>' . $h(sprintf(t('export.generated_by'), $meta['generated'], $meta['by'] ?: '-')) . '</td>'
            . '<td style="text-align: right;">' . $h(t('export.page')) . ' {PAGENO} / {nbpg}</td></tr></table>';
        $mpdf->SetHTMLHeader($header);
        $mpdf->SetHTMLFooter($footer);

        $css = '<style>
            body { font-family: dejavusans; font-size: 8.5pt; color: #0f172a; }
            h2 { font-size: 11pt; color: ' . $c . '; margin: 14px 0 4px 0; }
            .note { font-size: 7.5pt; color: #64748b; margin-bottom: 4px; }
            table.data { width: 100%; border-collapse: collapse; }
            table.data th { background-color: ' . $c . '; color: ' . self::onColor($c) . '; font-size: 7.5pt; text-align: left; padding: 4px 5px; }
            table.data td { font-size: 7.5pt; padding: 3px 5px; border-bottom: 0.5px solid #e2e8f0; }
            table.data tr.odd td { background-color: #f8fafc; }
            td.num { text-align: right; }
            .filters { font-size: 7.5pt; color: #334155; margin-bottom: 6px; }
        </style>';
        $html = $css;

        if (!empty($doc['filters'])) {
            $parts = [];
            foreach ($doc['filters'] as $label => $value) {
                $parts[] = '<b>' . $h($label) . ':</b> ' . $h($value);
            }
            $html .= '<div class="filters">' . implode(' &nbsp;·&nbsp; ', $parts) . '</div>';
        }
        if (!empty($doc['kpis'])) {
            $perRow = $wide ? 6 : 4;
            $html .= '<table width="100%" style="border-collapse: separate; border-spacing: 4px;">';
            foreach (array_chunk($doc['kpis'], $perRow) as $chunk) {
                $html .= '<tr>';
                foreach ($chunk as $k) {
                    // Inline styles: mPDF does not apply descendant selectors inside table cells.
                    $html .= '<td width="' . floor(100 / $perRow) . '%" style="border: 0.5px solid #e2e8f0; padding: 6px 8px;">'
                        . '<div style="font-size: 7pt; color: #64748b;">' . $h($k[0]) . '</div>'
                        . '<div style="font-size: 14pt; font-weight: bold; color: #0f172a;">' . $h($k[1]) . '</div>'
                        . (!empty($k[2]) ? '<div style="font-size: 6.5pt; color: #64748b;">' . $h($k[2]) . '</div>' : '') . '</td>';
                }
                $html .= str_repeat('<td></td>', $perRow - count($chunk)) . '</tr>';
            }
            $html .= '</table>';
        }
        $mpdf->WriteHTML($html);

        $svgFiles = [];
        foreach ($doc['sections'] as $sec) {
            $part = '<h2>' . $h($sec['title']) . '</h2>';
            if (!empty($sec['note'])) {
                $part .= '<div class="note">' . $h($sec['note']) . '</div>';
            }
            if (!empty($sec['chart']['labels'])) {
                $svg = self::barSvg($sec['chart'], $brand['color']);
                $file = tempnam($tmpDir, 'chart') . '.svg';
                file_put_contents($file, $svg);
                $svgFiles[] = $file;
                $part .= self::legend($sec['chart'], $brand['color']) . '<img src="' . $h($file) . '" style="width: 100%;" />';
            }
            if (!empty($sec['columns'])) {
                $rows = $sec['rows'];
                // Wide tables: the PDF shows a readable subset, the workbook has every column.
                if (!empty($sec['pdf_columns'])) {
                    $keep = $sec['pdf_columns'];
                    $sec['columns'] = array_values(array_intersect_key($sec['columns'], array_flip($keep)));
                    $rows = array_map(fn($r) => array_values(array_intersect_key($r, array_flip($keep))), $rows);
                }
                if (!$rows) {
                    $part .= '<div class="note">' . $h(t('export.no_rows')) . '</div>';
                } else {
                    $part .= '<table class="data"><thead><tr>';
                    foreach ($sec['columns'] as [$label, $type]) {
                        $part .= '<th' . (in_array($type, ['int', 'dur', 'pct', 'number'], true) ? ' style="text-align: right;"' : '') . '>' . $h($label) . '</th>';
                    }
                    $part .= '</tr></thead><tbody>';
                    foreach (array_slice($rows, 0, self::PDF_MAX_ROWS) as $i => $row) {
                        $part .= '<tr' . ($i % 2 ? ' class="odd"' : '') . '>';
                        foreach ($sec['columns'] as $ci => [$label, $type]) {
                            $num = in_array($type, ['int', 'dur', 'pct', 'number'], true);
                            $part .= '<td' . ($num ? ' class="num"' : '') . '>' . $h(self::format($row[$ci] ?? '', $type)) . '</td>';
                        }
                        $part .= '</tr>';
                    }
                    $part .= '</tbody></table>';
                    $total = (int) ($sec['total'] ?? count($rows));
                    if ($total > self::PDF_MAX_ROWS) {
                        $part .= '<div class="note">' . $h(sprintf(t('export.pdf_truncated'), self::PDF_MAX_ROWS, $total)) . '</div>';
                    }
                }
            }
            $mpdf->WriteHTML($part);
        }
        $out = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        foreach ($svgFiles as $f) {
            @unlink($f);
            @unlink(substr($f, 0, -4));
        }
        return $out;
    }

    /** Readable text colour on a fill. */
    private static function onColor(string $hex): string
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#0f172a' : '#ffffff';
    }

    /** Series colours for print: the brand colour, then fixed status colours. */
    private static function seriesColor(string $key, string $brand): string
    {
        return ['primary' => $brand, 'danger' => '#dc2626', 'warning' => '#d97706', 'success' => '#16a34a', 'muted' => '#94a3b8'][$key] ?? $brand;
    }

    private static function legend(array $chart, string $brand): string
    {
        $out = '<div style="font-size: 7.5pt; margin-bottom: 2px;">';
        foreach ($chart['series'] as [$label, $color]) {
            $out .= '<span style="color: ' . self::seriesColor($color, $brand) . ';">■</span> ' . htmlspecialchars($label) . ' &nbsp; ';
        }
        return $out . '</div>';
    }

    /** Stacked bars as standalone SVG (concrete colours: no CSS variables in a PDF). */
    public static function barSvg(array $chart, string $brand): string
    {
        $labels = array_values($chart['labels']);
        $n = max(1, count($labels));
        $totals = array_fill(0, $n, 0);
        foreach ($chart['series'] as [, , $values]) {
            foreach (array_values($values) as $i => $v) {
                $totals[$i] += (int) $v;
            }
        }
        $max = max(1, max($totals));
        $mag = 10 ** floor(log10($max));
        $top = max(1, ceil($max / $mag * 2) / 2 * $mag);
        $W = 1000; $H = 260; $left = 46; $bottom = 26; $plotH = $H - $bottom - 10;
        $step = ($W - $left - 10) / $n;
        $barW = max(4, min(44, $step * 0.62));
        $e = fn($s) => htmlspecialchars((string) $s, ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '">';
        foreach ([0, 0.5, 1] as $f) {
            $y = 10 + $plotH - $plotH * $f;
            $svg .= '<line x1="' . $left . '" x2="' . ($W - 6) . '" y1="' . $y . '" y2="' . $y . '" stroke="#e2e8f0" stroke-width="1"/>';
            $svg .= '<text x="' . ($left - 6) . '" y="' . ($y + 4) . '" text-anchor="end" font-size="12" fill="#64748b" font-family="DejaVu Sans">' . (int) round($top * $f) . '</text>';
        }
        $every = (int) ceil($n / 31);
        foreach ($labels as $i => $label) {
            $x = $left + $i * $step + ($step - $barW) / 2;
            $y = 10 + $plotH;
            foreach ($chart['series'] as [, $color, $values]) {
                $v = (int) (array_values($values)[$i] ?? 0);
                if ($v <= 0) {
                    continue;
                }
                $bh = $plotH * $v / $top;
                $y -= $bh;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y + 1, 1) . '" width="' . round($barW, 1) . '" height="' . round(max(1, $bh - 2), 1) . '" rx="2" fill="' . self::seriesColor($color, $brand) . '"/>';
            }
            if ($i % $every === 0) {
                $svg .= '<text x="' . round($left + $i * $step + $step / 2, 1) . '" y="' . ($H - 8) . '" text-anchor="middle" font-size="12" fill="#64748b" font-family="DejaVu Sans">' . $e($label) . '</text>';
            }
        }
        return $svg . '</svg>';
    }

    /** Sends the file and ends the request; logs who exported what. */
    public static function send(array $doc, string $format, string $slug): void
    {
        $body = $format === 'xlsx' ? self::xlsx($doc) : self::pdf($doc);
        $name = $slug . '-' . date('Ymd-Hi') . '.' . ($format === 'xlsx' ? 'xlsx' : 'pdf');
        // Who exported which call data, from the web (not smoke/CLI runs).
        if (PHP_SAPI !== 'cli' && function_exists('writeAuditLog')) {
            writeAuditLog(null, 'report', $slug, $doc['title'] . ' (' . ($doc['period'] ?? '') . ') → ' . strtoupper($format), 'export', $_SESSION['user_id'] ?? null);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
        echo $body;
        exit;
    }
}
