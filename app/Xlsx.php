<?php
declare(strict_types=1);

/**
 * A small Excel (.xlsx) writer for report exports: one or more sheets of rows, numbers kept as numbers, a bold
 * title and heading row, money shown with two decimals. Needs PHP's zip extension; without it the export falls
 * back to a CSV file (which Excel also opens).
 */
final class Xlsx
{
    /** @var array<int,array{name:string,rows:array,bold:array,money:array}> */
    private array $sheets = [];

    public static function available(): bool
    {
        return class_exists('ZipArchive');
    }

    /**
     * Adds a sheet. $rows: list of rows (each a list of cells: string, int, float or null).
     * $bold: row indexes printed bold (headings, totals). $money: column indexes shown with two decimals.
     */
    public function sheet(string $name, array $rows, array $bold = [], array $money = []): self
    {
        $name = trim(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $name)) ?: 'Sheet' . (count($this->sheets) + 1);
        $this->sheets[] = ['name' => mb_substr($name, 0, 31), 'rows' => $rows, 'bold' => array_flip($bold), 'money' => array_flip($money)];
        return $this;
    }

    /** Sends the workbook as a download and stops */
    public function download(string $filename): never
    {
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', preg_replace('/\.xlsx$/i', '', $filename)) . '.xlsx';
        if (!self::available()) {
            // no zip extension on this server: the first sheet as CSV
            $rows = $this->sheets[0]['rows'] ?? [];
            $head = array_shift($rows) ?? [];
            csv_download(preg_replace('/\.xlsx$/', '.csv', $filename), $head, $rows);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function save(string $path): void
    {
        if (!$this->sheets) {
            $this->sheet('Sheet1', [[]]);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the Excel file.');
        }
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $wb .= '<sheet name="' . self::x($s['name']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->sheetXml($s));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        // styles: 0 normal, 1 bold, 2 money, 3 bold money
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
    }

    private function sheetXml(array $s): string
    {
        $widths = [];
        $data = '';
        foreach (array_values($s['rows']) as $r => $row) {
            $bold = isset($s['bold'][$r]);
            $data .= '<row r="' . ($r + 1) . '">';
            foreach (array_values((array)$row) as $c => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::col($c) . ($r + 1);
                $money = isset($s['money'][$c]);
                $num = is_int($v) || is_float($v) || ($money && is_string($v) && is_numeric($v));
                $len = mb_strlen($num ? number_format((float)$v, 2) : (string)$v);
                $widths[$c] = max($widths[$c] ?? 8, min(60, $len + 2));
                if ($num) {
                    $style = $money ? ($bold ? 3 : 2) : ($bold ? 1 : 0);
                    $data .= '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . (0 + $v) . '</v></c>';
                } else {
                    $data .= '<c r="' . $ref . '" t="inlineStr"' . ($bold ? ' s="1"' : '') . '><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $cols = '';
        if ($widths) {
            ksort($widths);
            $cols = '<cols>';
            foreach ($widths as $c => $w) {
                $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $cols . '<sheetData>' . $data . '</sheetData></worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $v): string
    {
        // characters XML 1.0 does not allow are left out
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
