<?php

/**
 * Minimal Office Open XML (.xlsx) writer: typed cells, a branded header
 * style, column widths, frozen header rows and auto-filter. Enough for
 * report exports without pulling in a spreadsheet library.
 *
 * A cell is a scalar (text or number) or ['v' => value, 's' => style].
 */
final class XlsxWriter
{
    public const S_TEXT = 0;
    public const S_HEADER = 1;
    public const S_INT = 2;
    public const S_DURATION = 3;   // value in days (seconds / 86400), shown [h]:mm:ss
    public const S_PERCENT = 4;    // value as a fraction (0.753), shown 75.3%
    public const S_DATETIME = 5;   // Excel serial date
    public const S_TITLE = 6;
    public const S_MUTED = 7;
    public const S_BOLD = 8;
    public const S_DECIMAL = 9;

    /** @var list<array{name: string, rows: array, widths: array, freeze: ?int, filter: ?array}> */
    private array $sheets = [];

    public function __construct(private string $accentHex = '0284C7')
    {
        $this->accentHex = strtoupper(ltrim($accentHex, '#'));
        if (!preg_match('/^[0-9A-F]{6}$/', $this->accentHex)) {
            $this->accentHex = '0284C7';
        }
    }

    /**
     * @param list<list<mixed>> $rows
     * @param list<float>       $widths   column widths in characters
     * @param ?int              $freezeRow rows above this (1-based) stay visible
     * @param ?array{0:int,1:int,2:int,3:int} $filter [firstRow, firstCol, lastRow, lastCol], 1-based
     */
    public function addSheet(string $name, array $rows, array $widths = [], ?int $freezeRow = null, ?array $filter = null): void
    {
        // Excel forbids [ ] : * ? / \ in sheet names.
        $name = preg_replace('#[\[\]:*?/\\\\]#', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '') ?: 'Sheet';
        $name = mb_substr($name, 0, 31);
        $base = $name;
        $n = 2;
        while (in_array(mb_strtolower($name), array_map(fn($s) => mb_strtolower($s['name']), $this->sheets), true)) {
            $suffix = ' (' . $n++ . ')';
            $name = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        }
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths, 'freeze' => $freezeRow, 'filter' => $filter];
    }

    /** Unix timestamp → Excel serial date in the server's time zone. */
    public static function serialDate(int $ts): float
    {
        return ($ts + (int) date('Z', $ts)) / 86400 + 25569;
    }

    public static function column(int $index): string
    {
        $s = '';
        for ($n = $index; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    /** @return string the .xlsx file contents */
    public function build(): string
    {
        if (!$this->sheets) {
            $this->addSheet('Sheet', []);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheet($sheet));
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function esc(string $s): string
    {
        // XML 1.0 forbids most control characters; they break Excel's parser.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($this->sheets as $i => $_) {
            $x .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $x . '</Types>';
    }

    private function workbook(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $s) {
            $x .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $x .= '</sheets><definedNames>';
        foreach ($this->sheets as $i => $s) {
            if ($s['filter']) {
                [$r1, $c1, $r2, $c2] = $s['filter'];
                $ref = "'" . str_replace("'", "''", $s['name']) . "'!$" . self::column($c1) . '$' . $r1 . ':$' . self::column($c2) . '$' . max($r1, $r2);
                $x .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::esc($ref) . '</definedName>';
            }
        }
        return $x . '</definedNames></workbook>';
    }

    private function workbookRels(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $_) {
            $x .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $n = count($this->sheets) + 1;
        return $x . '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function styles(): string
    {
        $a = 'FF' . $this->accentHex;
        // Header text: white on dark accents, near-black on light ones.
        [$r, $g, $b] = array_map('hexdec', str_split($this->accentHex, 2));
        $headerText = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? 'FF0F172A' : 'FFFFFFFF';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="[h]:mm:ss"/><numFmt numFmtId="165" formatCode="0.0%"/><numFmt numFmtId="166" formatCode="dd.mm.yyyy hh:mm:ss"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="' . $headerText . '"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><color rgb="' . $a . '"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="' . $a . '"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="10">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs></styleSheet>';
    }

    private function sheet(array $s): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($s['freeze']) {
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . ($s['freeze'] - 1) . '" topLeftCell="A' . $s['freeze'] . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($s['widths']) {
            $x .= '<cols>';
            foreach ($s['widths'] as $i => $w) {
                $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round((float) $w, 1) . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($s['rows'] as $ri => $row) {
            $r = $ri + 1;
            $x .= '<row r="' . $r . '">';
            foreach (array_values($row) as $ci => $cell) {
                $style = self::S_TEXT;
                $v = $cell;
                if (is_array($cell)) {
                    $v = $cell['v'] ?? null;
                    $style = (int) ($cell['s'] ?? self::S_TEXT);
                }
                if ($v === null || $v === '') {
                    if ($style !== self::S_TEXT) {
                        $x .= '<c r="' . self::column($ci + 1) . $r . '" s="' . $style . '"/>';
                    }
                    continue;
                }
                $ref = self::column($ci + 1) . $r;
                if ((is_int($v) || is_float($v)) && is_finite((float) $v)) {
                    $x .= '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($s['filter']) {
            [$r1, $c1, $r2, $c2] = $s['filter'];
            $x .= '<autoFilter ref="' . self::column($c1) . $r1 . ':' . self::column($c2) . max($r1, $r2) . '"/>';
        }
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
        return $x . '</worksheet>';
    }
}
