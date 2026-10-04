<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/services/ReportExport.php';

/**
 * Report exports: the workbook must open in Excel with real numbers, dates
 * and durations; the PDF must render with Turkish text and charts.
 */
final class ReportExportTest extends TestCase
{
    private static function doc(int $rows = 3): array
    {
        return [
            'title' => 'Çağrı Raporları', 'period' => '01.10.2026 – 03.10.2026',
            'filters' => ['Yön' => 'Gelen'],
            'kpis' => [['Toplam', '689', 'çağrı'], ['Cevaplanan', '329', '']],
            'sections' => [
                ['title' => 'Saatlere göre', 'columns' => [],
                 'chart' => ['labels' => ['08', '09'], 'series' => [['Cevaplanan', 'primary', [5, 20]], ['Kaybedilen', 'danger', [1, 0]]]]],
                ['title' => 'Çağrılar: [tümü]', 'columns' => [['Tarih', 'datetime'], ['Arayan', 'text'], ['Konuşma', 'dur'], ['Oran', 'pct'], ['Adet', 'int']],
                 'rows' => array_map(fn($i) => [1759474800 + $i * 60, '05321112233', 95, 33.3, $i], range(1, $rows))],
            ],
        ];
    }

    public function testFormatting(): void
    {
        $this->assertSame('01:35', ReportExport::format(95, 'dur'));
        $this->assertSame('1:01:01', ReportExport::format(3661, 'dur'));
        $this->assertSame('%33,3', ReportExport::format(33.333, 'pct'));
        $this->assertSame('12.345', ReportExport::format(12345, 'int'));
        $this->assertSame('', ReportExport::format(null, 'dur'));
        $this->assertSame('05321112233', ReportExport::format('05321112233', 'text'));
    }

    public function testWorkbook(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, ReportExport::xlsx(self::doc()));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file) === true);
        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
            $xml = $zip->getFromName($part);
            $this->assertNotFalse($xml, $part);
            $this->assertNotFalse(@simplexml_load_string($xml), "{$part} is well-formed XML");
        }
        $wb = $zip->getFromName('xl/workbook.xml');
        $this->assertStringContainsString('name="Özet"', $wb);
        $this->assertStringContainsString('name="Çağrılar tümü"', $wb, 'characters Excel forbids in sheet names are replaced');
        $sheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        // Phone numbers stay text (leading 0), durations are fractions of a day, percentages fractions.
        $this->assertStringContainsString('<t xml:space="preserve">05321112233</t>', $sheet);
        $this->assertStringContainsString('s="' . XlsxWriter::S_DURATION . '"><v>' . (95 / 86400) . '</v>', $sheet);
        $this->assertStringContainsString('s="' . XlsxWriter::S_PERCENT . '"><v>0.333</v>', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A2:E5"/>', $sheet);
        $this->assertStringContainsString('state="frozen"', $sheet);
        $zip->close();
        unlink($file);
    }

    public function testXlsxHelpers(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AZ', 'BA'], array_map([XlsxWriter::class, 'column'], [1, 26, 27, 52, 53]));
        $ts = mktime(12, 0, 0, 1, 1, 2026);
        $this->assertEqualsWithDelta(46023.5, XlsxWriter::serialDate($ts), 0.0001, '2026-01-01 12:00 local');
    }

    public function testPdf(): void
    {
        $pdf = ReportExport::pdf(self::doc(2500));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10000, strlen($pdf));
    }

    public function testChartSvg(): void
    {
        $svg = ReportExport::barSvg(['labels' => ['a', 'b'], 'series' => [['x', 'primary', [3, 0]], ['y', 'danger', [1, 2]]]], '#123456');
        $this->assertNotFalse(@simplexml_load_string($svg));
        $this->assertStringContainsString('fill="#123456"', $svg);
        $this->assertStringContainsString('fill="#dc2626"', $svg);
        $this->assertStringNotContainsString('var(', $svg, 'no CSS variables in a PDF');
    }
}
