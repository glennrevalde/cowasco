<?php
declare(strict_types=1);

/**
 * v39: a small PDF writer for "Save as PDF" (no library to install): pages of any size, text in the standard
 * Helvetica and Helvetica-Bold fonts (every PDF reader has them), lines and filled boxes. Enough for the reports and
 * the Cash Count Sheet, which are text in tables.
 *
 * Measures are in points (72 to the inch) from the top-left corner of the page. Text is written in the Windows-1252
 * code page of the standard fonts: letters such as ñ and é print; a sign the fonts do not have (the peso sign) is
 * written out ("PHP").
 */
final class SimplePdf
{
    /** Widths of the Helvetica characters 32 to 126, in thousandths of the font size */
    private const W_REG = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667,
        944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500,
        278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];
    private const W_BOLD = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667,
        944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556,
        333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584];

    private array $pages = [];
    private string $cur = '';
    public float $w;
    public float $h;
    private string $title;

    /** A page size in points: Letter 612 x 792 */
    public function __construct(float $w = 612, float $h = 792, string $title = '')
    {
        $this->w = $w;
        $this->h = $h;
        $this->title = $title;
    }

    public function addPage(): void
    {
        if ($this->cur !== '' || $this->pages) {
            $this->pages[] = $this->cur;
        }
        $this->cur = '';
    }

    /** UTF-8 text as the fonts' Windows-1252 bytes */
    public static function enc(string $s): string
    {
        $s = str_replace(['₱', "\u{2011}", "\u{2013}", "\u{2014}", "\u{00A0}"], ['PHP ', '-', '-', '-', ' '], $s);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    /** The width of a text in points */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $t = self::enc($s);
        $wid = $bold ? self::W_BOLD : self::W_REG;
        $sum = 0;
        $n = strlen($t);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($t[$i]);
            $sum += ($c >= 32 && $c <= 126) ? $wid[$c - 32] : 556;
        }
        return $sum * $size / 1000;
    }

    /** A text cut to a width, with "..." when it does not fit */
    public function fit(string $s, float $maxW, float $size, bool $bold = false): string
    {
        if ($maxW <= 0 || $this->textWidth($s, $size, $bold) <= $maxW) {
            return $s;
        }
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while ($chars && $this->textWidth(implode('', $chars) . '...', $size, $bold) > $maxW) {
            array_pop($chars);
        }
        return implode('', $chars) . '...';
    }

    /** A text split into lines no wider than $maxW */
    public function wrap(string $s, float $maxW, float $size, bool $bold = false): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $s) ?: [] as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $this->textWidth($try, $size, $bold) > $maxW) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $this->fit($line, $maxW, $size, $bold);
        }
        return $lines;
    }

    /**
     * Writes a text with its baseline at $y. $align: L (from $x), R (ending at $x), C (centred on $x).
     * $gray: 0 black to 1 white.
     */
    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, string $align = 'L', float $gray = 0): void
    {
        if ($s === '') {
            return;
        }
        $w = $this->textWidth($s, $size, $bold);
        if ($align === 'R') {
            $x -= $w;
        } elseif ($align === 'C') {
            $x -= $w / 2;
        }
        $t = strtr(self::enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $this->cur .= sprintf("BT %.3F g /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $gray, $bold ? 'F2' : 'F1', $size, $x, $this->h - $y, $t);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0): void
    {
        $this->cur .= sprintf("%.3F G %.2F w %.2F %.2F m %.2F %.2F l S\n", $gray, $width, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    /** A box filled with a gray (0 black to 1 white), its top-left corner at $x, $y */
    public function fill(float $x, float $y, float $w, float $h, float $gray = 0.93): void
    {
        $this->cur .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f\n", $gray, $x, $this->h - $y - $h, $w, $h);
    }

    /** A box outline */
    public function box(float $x, float $y, float $w, float $h, float $width = 0.5, float $gray = 0): void
    {
        $this->cur .= sprintf("%.3F G %.2F w %.2F %.2F %.2F %.2F re S\n", $gray, $width, $x, $this->h - $y - $h, $w, $h);
    }

    /** The PDF file */
    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->cur;
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $info = '<< /Producer (G-SMaRT Accounting) /Title (' . strtr(self::enc($this->title), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') /CreationDate (D:' . date('YmdHis') . ') >>';
        $objs[5] = $info;
        $kids = [];
        $n = 6;
        foreach ($pages as $content) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = $pageId . ' 0 R';
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $contentId);
            $objs[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Sends the PDF to the browser as a file to save */
    public function download(string $filename): never
    {
        $pdf = $this->output();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        echo $pdf;
        exit;
    }
}
