<?php
declare(strict_types=1);

/**
 * v39: "Save as PDF" of a report: the report as it prints (its title, heading lines, columns, rows, totals and
 * signature lines), written straight to a PDF file the browser saves, instead of going through the print window.
 * Paper and orientation follow the report's layout (Modify Report > Page); a report with many columns is turned
 * sideways. Rows run on over as many pages as needed, with the column headings again at the top of each page.
 */
final class ReportPdf
{
    private const SIZES = ['a4' => [595.28, 841.89], 'letter' => [612, 792], 'folio' => [612, 936], 'legal' => [612, 1008]];

    /** Plain text of a cell, the way the report shows it */
    public static function cell(array $r, array $c, array $opt): string
    {
        $type = $c['type'] ?? 'text';
        $k = $c['key'];
        if (isset($r['_html'][$k])) {
            $html = (string)$r['_html'][$k];
        } else {
            $v = $r[$k] ?? '';
            $cls = (string)($r['_class'] ?? '');
            $isTotal = str_contains(' ' . $cls . ' ', ' total-row ') || str_contains(' ' . $cls . ' ', ' subtotal-row ');
            $html = $opt && in_array($type, ['money', 'qty', 'pct', 'int'], true) && $v !== '' && $v !== null
                ? ReportLayout::number($v, $type, $opt, $isTotal) : Report::fmt($v, $type);
        }
        return trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Writes the PDF and sends it. $head: company, title, subtitle, lines (heading lines), basis, prepared;
     * $signs: the signature captions (empty: none).
     */
    public static function send(string $file, array $head, array $cols, array $rows, ?array $totals, array $opt, array $signs = [], array $signNames = []): never
    {
        $cols = array_values($cols);
        $land = ($opt['orientation'] ?? 'portrait') === 'landscape' || count($cols) > 7;
        [$pw, $ph] = self::SIZES[$opt['paper'] ?? ''] ?? self::SIZES['letter'];
        if ($land) {
            [$pw, $ph] = [$ph, $pw];
        }
        $pdf = new SimplePdf($pw, $ph, (string)($head['title'] ?? ''));
        $m = 34.0;
        $avail = $pw - 2 * $m;
        $fs = count($cols) > 10 ? 6.6 : 7.6;
        $rh = $fs * 1.62;
        $num = fn(array $c) => in_array($c['type'] ?? 'text', ['money', 'qty', 'pct', 'int'], true);
        if ($totals) {
            $rows[] = $totals + ['_class' => 'total-row'];
        }

        // the widths: what the headings and the cells need, shared out over the page
        $need = [];
        foreach ($cols as $i => $c) {
            // a long heading may take two lines: it asks for half its width (at least its longest word)
            $label = (string)$c['label'];
            $longest = max(array_map(fn($w) => $pdf->textWidth($w, $fs, true), preg_split('/\s+/', $label) ?: [$label]));
            $need[$i] = max($longest, min($pdf->textWidth($label, $fs, true), $pdf->textWidth($label, $fs, true) / 2 + 10)) + 8;
        }
        foreach (array_slice($rows, 0, 400) as $r) {
            $spanRow = preg_match('/(^| )(group-row)( |$)/', (string)($r['_class'] ?? ''));
            foreach ($cols as $i => $c) {
                if ($spanRow && $i === 0) {
                    continue;
                }
                $need[$i] = max($need[$i], $pdf->textWidth(self::cell($r, $c, $opt), $fs, false) + 8);
            }
        }
        foreach ($cols as $i => $c) {
            $need[$i] = min($need[$i], $num($c) ? 110 : 230);
        }
        $sum = array_sum($need);
        if ($sum > $avail) {
            // the text columns give way first, then all of them
            $textSum = array_sum(array_filter($need, fn($w, $i) => !$num($cols[$i]), ARRAY_FILTER_USE_BOTH));
            $over = $sum - $avail;
            if ($textSum > $over + 40 * count(array_filter($cols, fn($c) => !$num($c)))) {
                foreach ($need as $i => $w) {
                    if (!$num($cols[$i])) {
                        $need[$i] = $w - $over * ($w / $textSum);
                    }
                }
            } else {
                foreach ($need as $i => $w) {
                    $need[$i] = $w * $avail / $sum;
                }
            }
        } else {
            // room to spare: the text columns take it
            $spare = $avail - $sum;
            $textCols = array_keys(array_filter($cols, fn($c) => !$num($c)));
            foreach ($textCols ?: array_keys($cols) as $i) {
                $need[$i] += $spare / max(1, count($textCols ?: $cols));
            }
        }
        $x = [];
        $acc = $m;
        foreach ($need as $i => $w) {
            $x[$i] = $acc;
            $acc += $w;
        }

        $page = 0;
        $y = 0.0;
        $foot = function () use ($pdf, &$page, $pw, $ph, $m, $head) {
            $pdf->text($m, $ph - 18, (string)($head['printed'] ?? ''), 6.5, false, 'L', 0.45);
            $pdf->text($pw - $m, $ph - 18, 'Page ' . $page, 6.5, false, 'R', 0.45);
        };
        $headLines = [];
        foreach ($cols as $i => $c) {
            $headLines[$i] = array_slice($pdf->wrap((string)$c['label'], $need[$i] - 6, $fs, true), 0, 2);
        }
        $headH = $rh * max(array_map('count', $headLines) ?: [1]) - ($fs * 0.3);
        $headRow = function () use ($pdf, &$y, $cols, $x, $need, $fs, $rh, $m, $avail, $num, $headLines, $headH) {
            $pdf->fill($m, $y, $avail, $headH + 2, 0.92);
            foreach ($cols as $i => $c) {
                // the lines of a heading sit at the bottom of the heading row, as on screen
                $lines = $headLines[$i];
                $ly = $y + $headH - 2.2 - (count($lines) - 1) * $fs * 1.15;
                foreach ($lines as $l) {
                    $pdf->text($num($c) ? $x[$i] + $need[$i] - 3 : $x[$i] + 3, $ly, $l, $fs, true, $num($c) ? 'R' : 'L', 0.2);
                    $ly += $fs * 1.15;
                }
            }
            $pdf->line($m, $y + $headH + 2, $m + $avail, $y + $headH + 2, 0.6, 0.55);
            $y += $headH + 4;
        };
        $newPage = function (bool $first) use ($pdf, &$page, &$y, $m, $pw, $head, $foot, $headRow) {
            if (!$first) {
                $foot();
            }
            $pdf->addPage();
            $page++;
            $y = $m;
            if ($first) {
                if (!empty($head['company'])) {
                    $pdf->text($pw / 2, $y + 9, (string)$head['company'], 10, true, 'C', 0.15);
                    $y += 13;
                }
                foreach ((array)($head['company_lines'] ?? []) as $l) {
                    $pdf->text($pw / 2, $y + 8, (string)$l, 7.5, false, 'C', 0.35);
                    $y += 10;
                }
                $pdf->text($pw / 2, $y + 15, (string)($head['title'] ?? ''), 14, true, 'C', 0);
                $y += 20;
                foreach (array_filter(array_merge([(string)($head['subtitle'] ?? '')], (array)($head['lines'] ?? []))) as $l) {
                    $pdf->text($pw / 2, $y + 9, (string)$l, 8.5, false, 'C', 0.2);
                    $y += 11;
                }
                foreach ($pdf->wrap((string)($head['basis'] ?? ''), $pw - 2 * $m - 40, 7.5) as $l) {
                    if ($l !== '') {
                        $pdf->text($pw / 2, $y + 8, $l, 7.5, false, 'C', 0.4);
                        $y += 10;
                    }
                }
                $y += 8;
            } else {
                $pdf->text($m, $y + 8, (string)($head['title'] ?? '') . ' (continued)', 8, true, 'L', 0.3);
                $y += 14;
            }
            $headRow();
        };
        $newPage(true);
        if (!$rows) {
            $pdf->text($pw / 2, $y + 14, 'No data for these settings.', 8.5, false, 'C', 0.45);
            $y += 24;
        }
        foreach ($rows as $r) {
            if ($y + $rh > $ph - $m - 14) {
                $newPage(false);
            }
            $cls = ' ' . (string)($r['_class'] ?? '') . ' ';
            $bold = str_contains($cls, ' group-row ') || str_contains($cls, ' subtotal-row ') || str_contains($cls, ' total-row ');
            $indent = str_contains($cls, ' indent-2 ') ? 16 : (str_contains($cls, ' indent-1 ') ? 8 : 0);
            if (str_contains($cls, ' group-row ')) {
                $pdf->fill($m, $y, $avail, $rh, 0.955);
            }
            if (str_contains($cls, ' total-row ')) {
                $pdf->line($m, $y, $m + $avail, $y, 0.9, 0.2);
            } elseif (str_contains($cls, ' subtotal-row ')) {
                $pdf->line($m, $y, $m + $avail, $y, 0.5, 0.45);
            }
            // a heading, subtotal or total line runs on over the empty cells after it
            $span = 1;
            if ($bold && count($cols) > 1 && self::cell($r, $cols[0], $opt) !== '') {
                while ($span < count($cols) && self::cell($r, $cols[$span], $opt) === '') {
                    $span++;
                }
            }
            foreach ($cols as $i => $c) {
                if ($i > 0 && $i < $span) {
                    continue;
                }
                $t = self::cell($r, $c, $opt);
                if ($t === '') {
                    continue;
                }
                $w = $i === 0 ? array_sum(array_slice($need, 0, $span)) : $need[$i];
                $pad = $i === 0 ? 3 + $indent : 3;
                $t = $pdf->fit($t, $w - $pad - 3, $fs, $bold);
                $neg = $num($c) && str_starts_with($t, '(');
                $pdf->text($num($c) ? $x[$i] + $w - 3 : $x[$i] + $pad, $y + $rh - 2.6, $t, $fs, $bold, $num($c) ? 'R' : 'L', $neg ? 0.25 : 0);
            }
            if (!$bold) {
                $pdf->line($m, $y + $rh, $m + $avail, $y + $rh, 0.25, 0.86);
            }
            $y += $rh;
        }
        // the signature lines
        if ($signs) {
            $y += 30;
            if ($y + 30 > $ph - $m - 14) {
                $newPage(false);
                $y += 30;
            }
            $n = min(4, count($signs));
            $cw = ($avail - 24 * ($n - 1)) / $n;
            foreach (array_slice($signs, 0, 4) as $i => $label) {
                $sx = $m + $i * ($cw + 24);
                $pdf->line($sx, $y, $sx + $cw, $y, 0.6, 0.2);
                $yy = $y + 9;
                if (!empty($signNames[$i])) {
                    $pdf->text($sx, $yy, (string)$signNames[$i], 7.5, true, 'L', 0.1);
                    $yy += 10;
                }
                $pdf->text($sx, $yy, (string)$label, 7, false, 'L', 0.4);
            }
        }
        $foot();
        $pdf->download($file);
    }
}
