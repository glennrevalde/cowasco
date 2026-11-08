<?php
declare(strict_types=1);

/**
 * A small reader of Excel workbooks (.xlsx) for the import pages: the cells of the first worksheet as text, row by
 * row, as a CSV file would give them. Numbers keep the digits Excel stored (an account number is not turned into
 * 1.0001E+10); a cell shown as a date comes back as YYYY-MM-DD. Needs PHP's zip extension (on in XAMPP); without it
 * the user is asked for a CSV file.
 */
final class XlsxReader
{
    public static function available(): bool
    {
        return class_exists('ZipArchive');
    }

    /** The main namespace of a worksheet (written with or without a prefix: <worksheet> or <x:worksheet>) */
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** The largest worksheet and shared-strings parts read (uncompressed): a real import file is a few MB */
    private const MAX_PART_BYTES = 25 * 1024 * 1024;

    /** The columns read: an import has about ten; a cell far to the right (ZZZZ1) is not read */
    private const MAX_COLS = 64;

    /** The last row read: a cell far below the data is not read */
    private const MAX_ROW = 200000;

    /**
     * The rows of the first worksheet (a list of lists of strings, the empty cells as ''), at most $max rows that hold
     * something (rows of empty, merely formatted cells are left out). Fails with a message the user can act on when
     * the file is not a workbook.
     */
    public static function rows(string $path, int $max = 20000, int $maxCols = self::MAX_COLS): array
    {
        return array_values(self::numbered($path, $max, $maxCols));
    }

    /**
     * The rows that hold something, keyed by their row number in the worksheet. $maxCols: the columns read (the
     * consolidated import of members has a column for every detail of a member and of each of the member's accounts)
     */
    public static function numbered(string $path, int $max = 20000, int $maxCols = self::MAX_COLS): array
    {
        if (!self::available()) {
            fail('Excel files cannot be read on this server (PHP zip extension is off). Save the file as CSV (Comma delimited) and choose it again.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            fail('The file is not an Excel workbook (.xlsx). Save it from Excel as "Excel Workbook" or as CSV.');
        }
        try {
            $sheetPath = self::firstSheetPath($zip);
            $strings = self::sharedStrings($zip);
            $dateStyles = self::dateStyles($zip);
            $xml = self::part($zip, $sheetPath);
            if ($xml === null) {
                fail('The workbook has no worksheet.');
            }
        } finally {
            $zip->close();
        }
        $prev = libxml_use_internal_errors(true);
        $sheet = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        unset($xml);
        $root = $sheet === false ? null : $sheet->children(self::NS);
        if ($root === null || !isset($root->sheetData)) {
            fail('The worksheet of the workbook could not be read.');
        }
        $data = $root->sheetData;
        $rows = [];
        $seq = 0;
        foreach ($data->row as $row) {
            $seq++;
            $r = (int)($row->attributes()['r'] ?? 0);
            $r = $r > 0 ? $r : $seq;
            if ($r > self::MAX_ROW) {
                break;
            }
            $cells = [];
            $next = 0;
            foreach ($row->c as $c) {
                $ref = (string)($c->attributes()['r'] ?? '');
                $col = $ref !== '' ? self::colIndex(preg_replace('/\d+/', '', $ref)) : $next;
                $next = $col + 1;
                if ($col >= $maxCols) {
                    continue;
                }
                $text = self::cellText($c, $strings, $dateStyles);
                if ($text !== '') {
                    $cells[$col] = $text;
                }
            }
            if (!$cells) {
                continue;   // an empty row (or one of empty cells with a format only)
            }
            $line = [];
            for ($i = 0, $n = max(array_keys($cells)); $i <= $n; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[$r] = $line;
            if (count($rows) > $max) {
                fail('The workbook has more than ' . number_format($max) . ' rows. Split it into smaller files.');
            }
        }
        ksort($rows);
        return $rows;
    }

    /**
     * The rows as the text of a CSV file (for CsvReview::readText), an empty line for each empty row between them, so
     * "line N" of the review is row N of the worksheet.
     */
    public static function csvText(string $path, int $max = 20000): string
    {
        $fh = fopen('php://temp', 'r+');
        $at = 0;
        foreach (self::numbered($path, $max) as $r => $line) {
            if ($at > 0) {
                fwrite($fh, str_repeat("\n", max(0, $r - $at - 1)));
            }
            fputcsv($fh, $line, ',', '"', '');
            $at = $r;
        }
        rewind($fh);
        $text = (string)stream_get_contents($fh);
        fclose($fh);
        return $text;
    }

    /** A part of the workbook, refused when it is larger than a real import file could be */
    private static function part(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ((int)$stat['size'] > self::MAX_PART_BYTES) {
            fail('The workbook is too large to read here (' . number_format($stat['size'] / 1048576, 1) . ' MB of ' . basename($name) . '). Save it as CSV, or split it into smaller files.');
        }
        $data = $zip->getFromName($name);
        return $data === false ? null : $data;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false && preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)
            && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*\bTarget="([^"]+)"/', $rels, $t)) {
            $target = ltrim($t[1], '/');
            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/sharedStrings.xml');
        if ($xml === null) {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        $out = [];
        if ($doc === false) {
            return $out;
        }
        foreach ($doc->children(self::NS)->si as $si) {
            if (isset($si->t)) {
                $out[] = (string)$si->t;
            } else {
                $s = '';
                foreach ($si->r as $run) {
                    $s .= (string)$run->t;
                }
                $out[] = $s;
            }
        }
        return $out;
    }

    /** The style indexes whose number format shows a date */
    private static function dateStyles(ZipArchive $zip): array
    {
        $xml = self::part($zip, 'xl/styles.xml');
        if ($xml === null) {
            return [];
        }
        $custom = [];
        if (preg_match_all('/<numFmt\b[^>]*numFmtId="(\d+)"[^>]*formatCode="([^"]*)"/', $xml, $m, PREG_SET_ORDER)) {
            foreach ($m as $f) {
                $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', html_entity_decode($f[2]));
                $custom[(int)$f[1]] = (bool)preg_match('/[dmy]/i', (string)$code) && !preg_match('/^[#0.,%\s]+$/', (string)$code);
            }
        }
        $out = [];
        if (preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/s', $xml, $x) && preg_match_all('/<xf\b[^>]*?(?:numFmtId="(\d+)")?[^>]*\/?>/', $x[1], $xfs)) {
            foreach ($xfs[0] as $i => $xf) {
                $id = preg_match('/numFmtId="(\d+)"/', $xf, $n) ? (int)$n[1] : 0;
                if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || ($custom[$id] ?? false)) {
                    $out[$i] = true;
                }
            }
        }
        return $out;
    }

    private static function cellText(SimpleXMLElement $c, array $strings, array $dateStyles): string
    {
        $attr = $c->attributes();
        $t = (string)($attr['t'] ?? '');
        if ($t === 's') {
            return trim((string)($strings[(int)$c->v] ?? ''));
        }
        if ($t === 'inlineStr') {
            if (!isset($c->is)) {
                return '';
            }
            if (isset($c->is->t)) {
                return trim((string)$c->is->t);
            }
            $s = '';
            foreach ($c->is->r as $run) {
                $s .= (string)$run->t;
            }
            return trim($s);
        }
        $v = trim((string)$c->v);
        if ($t === 'b') {
            return $v === '1' ? 'TRUE' : 'FALSE';
        }
        if ($v !== '' && is_numeric($v) && isset($dateStyles[(int)($attr['s'] ?? 0)]) && (float)$v > 0 && (float)$v < 2958466) {
            // an Excel date (days since 1899-12-30)
            return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));
        }
        if ($v !== '' && preg_match('/^-?\d+\.\d{6,}$/', $v)) {
            // a binary fraction (12.300000000000001): back to what Excel shows
            $v = rtrim(rtrim(number_format((float)$v, 6, '.', ''), '0'), '.');
        }
        return $v;
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper(substr($letters, 0, 4))) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return max(0, $n - 1);
    }
}
