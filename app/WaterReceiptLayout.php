<?php
declare(strict_types=1);

/**
 * Water Billing receipts printed on a pre-printed form (version 46).
 *
 * Two receipts can be printed in two ways, chosen on Company, Security & Closing Date > Water Billing:
 *   - Printed:     the whole form is printed on plain paper (the frames, the titles, the labels and the entries), as
 *                  before;
 *   - Pre-Printed: only the entries are printed, each where the pre-printed form has room for it.
 * The receipts are the water bill / collection receipt (Collections, Payment History: two copies side by side, the
 * Cooperative's and the Consumer's) and the official receipt (Generate Official Receipt).
 *
 * The layout of the pre-printed form is set on Water Billing > Setup > Receipt Layout Setup, as a passbook is on
 * Passbook Types: the paper, the top, right, bottom and left margins, and for each part of the receipt (the header, the
 * lines of charges, the totals) its origin and the entries it prints, each placed from that origin (Left, Top), with
 * its width, alignment, format and font size. Every measurement is in tups (1,440 tups = 1 inch, 567 = 1 cm). The
 * default layouts print each entry where the Printed receipt prints it (measured on it), so a pre-printed form of the
 * same design takes them as they are.
 *
 * The margins frame the printable area: nothing is printed in them. Left and Top of every part count from the top-left
 * corner of that area. The lines of charges print from Lines Top, one Interval apart, at most Max Lines: when a
 * receipt has more, the last line takes the rest together.
 */
final class WaterReceiptLayout
{
    public const KINDS = ['collection' => 'Water bill / collection receipt', 'official' => 'Official receipt'];
    public const TYPES = ['printed' => 'Printed', 'preprinted' => 'Pre-Printed'];
    public const TYPE_SETTING = ['collection' => 'water_collection_receipt_type', 'official' => 'water_official_receipt_type'];
    public const LAYOUT_SETTING = ['collection' => 'water_receipt_layout_collection', 'official' => 'water_receipt_layout_official'];
    public const SECTIONS = ['header' => 'Header', 'body' => 'Lines', 'footer' => 'Totals'];
    public const COPY_LABELS = ["Cooperative's Copy", "Consumer's Copy"];
    /** The most entries a part (header, lines, totals) keeps */
    public const MAX_ITEMS = 60;

    /** The entries each part can print: key => [label, kind]. Kinds: text, money, date. "text" may hold {key} of the others. */
    public const FIELDS = [
        'collection' => [
            'header' => [
                'account' => ['Account #', 'text'], 'name' => ["Concessionaire's name", 'text'], 'number' => ['Number (receipt no.)', 'text'],
                'address' => ['Address', 'text'], 'rate_code' => ['Rate code', 'text'],
                'prev_date' => ['Previous reading date', 'text'], 'pres_date' => ['Present reading date', 'text'], 'due_date' => ['Due date', 'text'],
                'charge_mode' => ['Charge mode', 'text'], 'disconnection' => ['Disconnection date', 'text'],
                'present' => ['Present reading', 'text'], 'previous' => ['Previous reading', 'text'], 'usage' => ['Usage (m3)', 'text'],
                'water_fee' => ['Water fee', 'money'], 'arrears' => ['Arrears', 'money'], 'text' => ['Text', 'text'],
            ],
            'body' => ['description' => ['Charge', 'text'], 'amount' => ['Amount', 'money'], 'text' => ['Text', 'text']],
            'footer' => [
                'paid_at' => ['Date - time of payment', 'text'], 'amount_due' => ['Total amount due', 'money'], 'words' => ['Amount in words', 'text'],
                'cash' => ['Cash tendered', 'money'], 'change' => ['Change', 'money'], 'cashier' => ['Cashier', 'text'],
                'copy' => ["Copy (Cooperative's / Consumer's)", 'text'], 'text' => ['Text', 'text'],
            ],
        ],
        'official' => [
            'header' => ['date' => ['Date', 'date'], 'name' => ['Customer name', 'text'], 'account' => ['Account #', 'text'], 'number' => ['Receipt no. (system)', 'text'],
                'text' => ['Text', 'text']],
            'body' => ['description' => ['Service / transaction', 'text'], 'amount' => ['Amount', 'money'], 'text' => ['Text', 'text']],
            'footer' => ['total_sales' => ['Total sales', 'money'], 'total_due' => ['Total due', 'money'], 'total_amount_due' => ['Total amount due', 'money'],
                'exempt_sales' => ['Exempt sales', 'money'], 'cash_mark' => ['Cash (check mark)', 'text'], 'cashier' => ['Cashier', 'text'], 'text' => ['Text', 'text']],
        ],
    ];

    public const FORMATS = [
        'date'  => ['m/d/Y' => '11/02/2026', 'n/j/y' => '11/2/26', 'm/d/y' => '11/02/26', 'M j, Y' => 'Nov 2, 2026', 'd-M-y' => '02-Nov-26'],
        'money' => ['comma' => '1,234.56', 'plain' => '1234.56', 'whole' => '1,235'],
    ];
    public const ZERO = ['' => 'blank', '-' => 'a dash', '0' => '0.00'];
    public const ALIGN = ['l' => 'Left', 'r' => 'Right', 'c' => 'Centre'];
    public const FONTS = ['mono' => 'Dot matrix (Courier New)', 'sans' => 'Arial'];
    public const CPI = [0 => 'As the font size', 10 => '10 (pica)', 12 => '12 (elite)', 15 => '15', 17 => '17 (condensed)', 20 => '20 (condensed elite)'];
    public const PAGE_SIZES = [
        '12474x5670'  => 'Water bill 22 x 10 cm (two copies side by side)',
        '12960x7920'  => 'Official receipt 9 x 5.5 in',
        '6237x5670'   => 'Half of a water bill 11 x 10 cm (one copy)',
        '12240x7920'  => 'Half letter 8.5 x 5.5 in',
        '12240x15840' => 'Letter 8.5 x 11 in (test on plain paper)',
        '11906x16838' => 'A4 21 x 29.7 cm (test on plain paper)',
    ];
    /** The measurements of a layout: key => [label, min, max] (tups unless said) */
    public const MEASURES = [
        'page_width' => ['Paper width', 1440, 30000], 'page_height' => ['Paper height', 1440, 30000],
        'margin_top' => ['Top margin', 0, 7200], 'margin_right' => ['Right margin', 0, 7200], 'margin_bottom' => ['Bottom margin', 0, 7200], 'margin_left' => ['Left margin', 0, 7200],
        'header_top' => ['Header: Top', -14400, 30000], 'header_left' => ['Header: Left', -14400, 30000],
        'body_top' => ['Lines: Top', -14400, 30000], 'body_left' => ['Lines: Left', -14400, 30000], 'body_interval' => ['Lines: Interval', 50, 2000], 'max_lines' => ['Max Lines', 1, 40],
        'footer_top' => ['Totals: Top', -14400, 30000], 'footer_left' => ['Totals: Left', -14400, 30000],
        'copies' => ['Copies across the paper', 1, 2], 'copy_left' => ['Second copy: Left', 0, 30000], 'copy_top' => ['Second copy: Top', 0, 30000],
    ];

    // ================================================================================== the setting

    /** Printed or Pre-Printed for a receipt (the company setting) */
    public static function type(string $kind): string
    {
        $v = Settings::get(self::TYPE_SETTING[$kind] ?? '', 'printed');
        return isset(self::TYPES[$v]) ? $v : 'printed';
    }

    /** The saved layout of a receipt, or its default */
    public static function get(string $kind): array
    {
        $j = json_decode(Settings::get(self::LAYOUT_SETTING[$kind] ?? '', ''), true);
        return is_array($j) && isset($j['setup'], $j['body']) ? self::clean($kind, $j) : self::defaultLayout($kind);
    }

    public static function isCustom(string $kind): bool
    {
        $j = json_decode(Settings::get(self::LAYOUT_SETTING[$kind] ?? '', ''), true);
        return is_array($j) && isset($j['setup']);
    }

    public static function save(string $kind, array $layout, string $pageKey, string $reason = ''): void
    {
        if (!isset(self::KINDS[$kind])) {
            fail('Unknown receipt.');
        }
        $clean = self::clean($kind, $layout);
        $old = self::get($kind);
        Settings::set(self::LAYOUT_SETTING[$kind], json_encode($clean, JSON_UNESCAPED_UNICODE));
        Audit::log('edit', 'water', 'setting', null, 'Receipt layout: ' . self::KINDS[$kind], ['page_key' => $pageKey, 'before' => $old, 'after' => $clean] + ($reason !== '' ? ['reason' => $reason] : []));
    }

    public static function reset(string $kind, string $pageKey): void
    {
        $old = self::get($kind);
        Settings::set(self::LAYOUT_SETTING[$kind], '');
        Audit::log('edit', 'water', 'setting', null, 'Receipt layout: ' . self::KINDS[$kind], ['page_key' => $pageKey, 'before' => $old, 'reason' => 'Back to the default layout']);
    }

    // ================================================================================== the default layouts

    private static function it(string $f, int $x, int $y, int $w = 0, string $a = 'l', string $fmt = '', float $fs = 0, string $t = '', string $z = ''): array
    {
        return ['f' => $f, 'x' => $x, 'y' => $y, 'w' => $w, 'a' => $a, 'fmt' => $fmt, 'z' => $z, 't' => $t, 'fs' => $fs];
    }

    /**
     * Where the Printed receipts put each entry (measured on them): the water bill is 22 x 10 cm with the Cooperative's
     * copy on the left and the Consumer's copy 6,322 tups to its right; the official receipt is 9 x 5.5 inches.
     */
    public static function defaultLayout(string $kind): array
    {
        $it = [self::class, 'it'];
        if ($kind === 'official') {
            return [
                'setup' => ['page_width' => 12960, 'page_height' => 7920, 'margin_top' => 0, 'margin_right' => 0, 'margin_bottom' => 0, 'margin_left' => 0,
                    'header_top' => 0, 'header_left' => 0, 'body_top' => 2660, 'body_left' => 0, 'body_interval' => 265, 'max_lines' => 11,
                    'footer_top' => 0, 'footer_left' => 0, 'copies' => 1, 'copy_left' => 0, 'copy_top' => 0, 'font' => 'mono', 'cpi' => 0, 'font_size_pt' => '6.0'],
                'header' => [$it('date', 8034, 1173, 1237, 'c', 'm/d/Y'), $it('name', 2459, 1480, 0, 'l', '', 5.5)],
                'body' => [$it('description', 1415, 0, 6714, 'c', '', 5.5), $it('amount', 8128, 0, 1331, 'r', 'comma', 5.5)],
                'footer' => [$it('total_sales', 9558, 1973, 1902, 'r', 'comma'), $it('total_due', 9558, 2750, 1902, 'r', 'comma'),
                    $it('total_amount_due', 9558, 3528, 1902, 'r', 'comma'), $it('exempt_sales', 9558, 4305, 1902, 'r', 'comma')],
            ];
        }
        return [
            'setup' => ['page_width' => 12474, 'page_height' => 5670, 'margin_top' => 0, 'margin_right' => 0, 'margin_bottom' => 0, 'margin_left' => 0,
                'header_top' => 0, 'header_left' => 0, 'body_top' => 3406, 'body_left' => 0, 'body_interval' => 139, 'max_lines' => 2,
                'footer_top' => 0, 'footer_left' => 0, 'copies' => 2, 'copy_left' => 6322, 'copy_top' => 0, 'font' => 'mono', 'cpi' => 0, 'font_size_pt' => '6.4'],
            'header' => [
                $it('account', 241, 1452, 1134, 'c'), $it('name', 1458, 1452, 3260, 'l', '', 6.9), $it('number', 4822, 1458, 992, 'r', '', 6.0),
                $it('address', 2297, 1656), $it('rate_code', 2297, 1848),
                $it('prev_date', 1159, 2449, 1196, 'c', '', 6.1), $it('pres_date', 1159, 2664, 1196, 'c', '', 6.1), $it('due_date', 1159, 2880, 1196, 'c', '', 6.1),
                $it('charge_mode', 1159, 3096, 1196, 'c', '', 6.1), $it('disconnection', 1159, 3312, 1196, 'c', '', 6.1),
                $it('present', 2410, 2399, 1191, 'c'), $it('previous', 3616, 2399, 1176, 'c'), $it('usage', 4807, 2399, 1119, 'c'),
                $it('water_fee', 5087, 2589, 794, 'r', 'comma', 6.1), $it('arrears', 5087, 3267, 794, 'r', 'comma', 5.4),
            ],
            'body' => [$it('description', 2484, 0, 0, 'l', '', 5.4), $it('amount', 5087, 0, 794, 'r', 'comma', 5.4)],
            'footer' => [
                $it('paid_at', 281, 3607, 2075, 'l', '', 5.8), $it('amount_due', 4208, 3607, 1663, 'r', 'comma', 5.8), $it('words', 1426, 4112, 4440, 'l', '', 5.2),
                $it('cash', 1999, 4304, 1287, 'c', 'comma'), $it('amount_due', 3286, 4304, 1287, 'c', 'comma'), $it('change', 4573, 4304, 1287, 'c', 'comma'),
            ],
        ];
    }

    /** A layout as saved: only the entries of its receipt and part, every value within its range */
    public static function clean(string $kind, array $j): array
    {
        $def = self::defaultLayout($kind);
        $in = (array)($j['setup'] ?? []);
        $setup = [];
        foreach (self::MEASURES as $k => [$label, $lo, $hi]) {
            $v = isset($in[$k]) && is_numeric($in[$k]) ? (int)round((float)$in[$k]) : (int)$def['setup'][$k];
            $setup[$k] = max($lo, min($hi, $v));
        }
        $setup['font'] = isset(self::FONTS[$in['font'] ?? '']) ? (string)$in['font'] : $def['setup']['font'];
        $setup['cpi'] = isset(self::CPI[(int)($in['cpi'] ?? -1)]) ? (int)$in['cpi'] : (int)$def['setup']['cpi'];
        $fs = (float)str_replace(',', '.', (string)($in['font_size_pt'] ?? $def['setup']['font_size_pt']));
        $setup['font_size_pt'] = number_format($fs >= 4 && $fs <= 20 ? $fs : (float)$def['setup']['font_size_pt'], 1, '.', '');
        $out = ['setup' => $setup];
        foreach (array_keys(self::SECTIONS) as $sec) {
            $out[$sec] = [];
            foreach ((array)($j[$sec] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (count($out[$sec]) >= self::MAX_ITEMS) {
                    break;   // a form has far fewer entries; the layout stays well inside its setting
                }
                $f = (string)($item['f'] ?? '');
                if (!isset(self::FIELDS[$kind][$sec][$f])) {
                    continue;
                }
                $k = self::FIELDS[$kind][$sec][$f][1];
                $fmt = (string)($item['fmt'] ?? '');
                if (!isset(self::FORMATS[$k][$fmt])) {
                    $fmt = isset(self::FORMATS[$k]) ? (string)array_key_first(self::FORMATS[$k]) : '';
                }
                $fsz = (float)($item['fs'] ?? 0);
                $out[$sec][] = ['f' => $f, 'x' => max(-14400, min(30000, (int)($item['x'] ?? 0))), 'y' => max(-14400, min(30000, (int)($item['y'] ?? 0))),
                    'w' => max(0, min(30000, (int)($item['w'] ?? 0))), 'a' => isset(self::ALIGN[$item['a'] ?? '']) ? (string)$item['a'] : 'l', 'fmt' => $fmt,
                    'z' => $k === 'money' && isset(self::ZERO[(string)($item['z'] ?? '')]) ? (string)($item['z'] ?? '') : '', 't' => mb_substr((string)($item['t'] ?? ''), 0, 160),
                    'fs' => $fsz >= 4 && $fsz <= 20 ? round($fsz, 1) : 0];
            }
        }
        return $out;
    }

    /** Problems of a layout the user should see (nothing is refused for them) */
    public static function warnings(string $kind, array $l): array
    {
        $s = $l['setup'];
        $out = [];
        if ($s['margin_left'] + $s['margin_right'] >= $s['page_width'] || $s['margin_top'] + $s['margin_bottom'] >= $s['page_height']) {
            $out[] = 'The margins leave no room to print on.';
        }
        if (!$l['body']) {
            $out[] = 'The lines print nothing: the charges of a receipt will not come out.';
        }
        if ($s['copies'] === 2 && $s['copy_left'] === 0 && $s['copy_top'] === 0) {
            $out[] = 'The second copy prints over the first one: set its Left or Top.';
        }
        return $out;
    }

    // ================================================================================== values

    /** Sample entries to show a layout and to print a test page */
    public static function sample(string $kind): array
    {
        if ($kind === 'official') {
            return ['values' => ['date' => date('Y-m-d'), 'name' => 'SANTOS, MARIA', 'account' => '50012', 'number' => 'COL-20261102-104803-2AEB',
                    'total_sales' => '1924.00', 'total_due' => '1924.00', 'total_amount_due' => '1924.00', 'exempt_sales' => '1924.00', 'cash_mark' => '✓', 'cashier' => 'Reyes, Ana'],
                'lines' => [['description' => 'CONNECTION FEE', 'amount' => '250.00'], ['description' => 'WATER BILL', 'amount' => '1599.00'],
                    ['description' => 'PENALTY', 'amount' => '50.00'], ['description' => 'COMPROMISE', 'amount' => '25.00']]];
        }
        return ['values' => ['account' => '50012', 'name' => 'SANTOS, MARIA', 'number' => 'COL-20261102-104803-2AEB', 'address' => 'PUROK 3, POBLACION, CORDOVA, CEBU',
                'rate_code' => 'A', 'prev_date' => '01-SEP-26', 'pres_date' => '01-OCT-26', 'due_date' => '16-OCT-26', 'charge_mode' => 'MANUAL', 'disconnection' => '',
                'present' => '251', 'previous' => '0', 'usage' => '251', 'water_fee' => '1599.00', 'arrears' => '0',
                'paid_at' => date('n/j/Y') . ' 10:48:03 AM', 'amount_due' => '1924.00', 'words' => 'ONE THOUSAND NINE HUNDRED TWENTY FOUR PESOS AND NO CENTAVOS',
                'cash' => '1950.00', 'change' => '26.00', 'cashier' => 'Reyes, Ana'],
            'lines' => [['description' => 'CONNECTION FEE', 'amount' => '250.00'], ['description' => 'PENALTY', 'amount' => '50.00'], ['description' => 'COMPROMISE', 'amount' => '25.00']]];
    }

    /** A money string as shown on the receipts ("1,599.00") back to a number string */
    public static function num($v): string
    {
        $s = trim(str_replace([',', '₱', ' '], '', (string)$v));
        return is_numeric($s) ? $s : '0';
    }

    /** The entries of a collection receipt (cr_fetch_receipt()['layout']) */
    public static function collectionValues(array $layout, string $cashier = ''): array
    {
        $vals = ['account' => (string)($layout['account_number'] ?? ''), 'name' => (string)($layout['concessionaire_name'] ?? ''), 'number' => (string)($layout['receipt_number'] ?? ''),
            'address' => (string)($layout['address'] ?? ''), 'rate_code' => (string)($layout['rate_code'] ?? ''), 'prev_date' => (string)($layout['previous_reading_date'] ?? ''),
            'pres_date' => (string)($layout['present_reading_date'] ?? ''), 'due_date' => (string)($layout['due_date'] ?? ''), 'charge_mode' => (string)($layout['charge_mode'] ?? ''),
            'disconnection' => (string)($layout['disconnection_date'] ?? ''), 'present' => (string)($layout['present_reading'] ?? ''), 'previous' => (string)($layout['previous_reading'] ?? ''),
            'usage' => (string)($layout['usage'] ?? ''), 'water_fee' => self::num($layout['water_fee'] ?? ''), 'arrears' => self::num($layout['arrears'] ?? ''),
            'paid_at' => (string)($layout['date_time_of_payment'] ?? ''), 'amount_due' => self::num($layout['amount_due'] ?? ''), 'words' => (string)($layout['amount_in_words'] ?? ''),
            'cash' => self::num($layout['cash'] ?? ''), 'change' => self::num($layout['change'] ?? ''), 'cashier' => $cashier];
        $lines = [];
        foreach ((array)($layout['other_fee_items'] ?? []) as $fee) {
            $lines[] = ['description' => (string)($fee['description'] ?? ''), 'amount' => self::num($fee['amount'] ?? '')];
        }
        return ['values' => $vals, 'lines' => $lines];
    }

    /** A value as printed by an entry of the layout */
    public static function format(string $kind, string $sec, array $item, array $vals): string
    {
        $f = (string)$item['f'];
        if ($f === 'text') {
            $rep = [];
            foreach (self::FIELDS[$kind][$sec] as $k => [$label, $kd]) {
                if ($k !== 'text') {
                    $rep['{' . $k . '}'] = self::format($kind, $sec, ['f' => $k, 'fmt' => isset(self::FORMATS[$kd]) ? (string)array_key_first(self::FORMATS[$kd]) : '', 'z' => ''], $vals);
                }
            }
            return strtr((string)($item['t'] ?? ''), $rep);
        }
        $k = self::FIELDS[$kind][$sec][$f][1] ?? 'text';
        $raw = $vals[$f] ?? '';
        $fmt = (string)($item['fmt'] ?? '');
        if ($k === 'date') {
            $d = substr((string)$raw, 0, 10);
            return $d !== '' && valid_date($d) ? date(isset(self::FORMATS['date'][$fmt]) ? $fmt : 'm/d/Y', strtotime($d)) : (string)$raw;
        }
        if ($k === 'money') {
            $s = self::num($raw);
            if ($raw === '' || $raw === null) {
                return '';
            }
            if (abs((float)$s) < 0.005) {
                return ['-' => '-', '0' => $fmt === 'whole' ? '0' : '0.00'][(string)($item['z'] ?? '')] ?? '';
            }
            $n = round((float)$s, 2);
            $t = match ($fmt) {
                'plain' => number_format(abs($n), 2, '.', ''),
                'whole' => number_format(abs($n), 0),
                default => number_format(abs($n), 2),
            };
            return ($n < 0 ? '-' : '') . $t;
        }
        return (string)$raw;
    }

    /** The lines that fit: more than Max Lines leaves the last one with the rest of them together */
    public static function fitLines(array $lines, int $max, string $restLabel): array
    {
        $max = max(1, $max);
        if (count($lines) <= $max) {
            foreach ($lines as $i => &$l) {
                $l['_src'] = [$i];
            }
            return $lines;
        }
        $keep = array_slice($lines, 0, $max - 1);
        foreach ($keep as $i => &$k) {
            $k['_src'] = [$i];
        }
        unset($k);
        $sum = '0';
        $src = [];
        foreach (array_slice($lines, $max - 1, null, true) as $i => $l) {
            $sum = Dec::add($sum, self::num($l['amount'] ?? '0'), 2);
            $src[] = $i;
        }
        $keep[] = ['description' => $restLabel, 'amount' => $sum, '_src' => $src];
        return $keep;
    }

    // ================================================================================== printing

    private static function inch(int $tw): string
    {
        return rtrim(rtrim(number_format($tw / 1440, 4, '.', ''), '0'), '.') . 'in';
    }

    /** The horizontal scale of the characters for the characters per inch (condensed print), as on a passbook */
    public static function squeeze(array $setup, float $fs): float
    {
        $cpi = (int)($setup['cpi'] ?? 0);
        if ($cpi <= 0 || $fs <= 0) {
            return 1.0;
        }
        $natural = (($setup['font'] ?? 'mono') === 'sans' ? 129.5 : 120) / $fs;
        return round(min(2.0, max(0.3, $natural / $cpi)), 4);
    }

    private static function place(string $kind, array $l, string $sec, array $item, array $vals, int $x0, int $y0, array $data): string
    {
        $v = self::format($kind, $sec, $item, $vals);
        $s = $l['setup'];
        $fs = (float)$item['fs'] > 0 ? (float)$item['fs'] : (float)$s['font_size_pt'];
        $style = 'left:' . self::inch($x0 + (int)$item['x']) . ';top:' . self::inch($y0 + (int)$item['y']);
        if ((int)$item['w'] > 0) {
            $style .= ';width:' . self::inch((int)$item['w']) . ';text-align:' . ['l' => 'left', 'r' => 'right', 'c' => 'center'][$item['a']];
        }
        if ((float)$item['fs'] > 0) {
            $style .= ';font-size:' . $fs . 'pt';
        }
        $k = self::squeeze($s, $fs);
        if ($k !== 1.0) {
            $style .= ';transform:scaleX(' . rtrim(rtrim(number_format($k, 4, '.', ''), '0'), '.') . ');transform-origin:'
                . ((int)$item['w'] > 0 && $item['a'] === 'r' ? 'right' : ((int)$item['w'] > 0 && $item['a'] === 'c' ? 'center' : 'left')) . ' top';
        }
        $kd = self::FIELDS[$kind][$sec][$item['f']][1] ?? 'text';
        $attrs = ' data-f="' . h((string)$item['f']) . '" data-kind="' . h($kd) . '" data-fmt="' . h((string)$item['fmt']) . '" data-z="' . h((string)$item['z']) . '"';
        foreach ($data as $dk => $dv) {
            $attrs .= ' data-' . $dk . '="' . h((string)$dv) . '"';
        }
        return '<div class="wrl-f"' . $attrs . ' style="' . $style . '">' . h($v) . '</div>';
    }

    /**
     * The pre-printed receipt: the paper with only the entries on it. $vals are the entries of the header and the
     * totals, $lines the lines of charges ([description, amount]). Every entry carries data-f (its field), data-kind,
     * data-fmt, data-z and, on a line, data-line (and data-src: the lines it stands for), so a page can change it.
     */
    public static function render(string $kind, array $l, array $vals, array $lines, string $restLabel = 'OTHER CHARGES'): string
    {
        $s = $l['setup'];
        $area = 'left:' . self::inch($s['margin_left']) . ';top:' . self::inch($s['margin_top'])
            . ';width:' . self::inch(max(0, $s['page_width'] - $s['margin_left'] - $s['margin_right'])) . ';height:' . self::inch(max(0, $s['page_height'] - $s['margin_top'] - $s['margin_bottom']));
        $html = '<div class="wrl-page' . ($s['font'] === 'sans' ? ' wrl-sans' : '') . '" data-wrl="' . h($kind) . '" style="width:' . self::inch($s['page_width']) . ';height:' . self::inch($s['page_height'])
            . ';font-size:' . (float)$s['font_size_pt'] . 'pt"><div class="wrl-area" style="' . $area . '">';
        $fit = self::fitLines($lines, (int)$s['max_lines'], $restLabel);
        for ($c = 0; $c < ($kind === 'collection' ? (int)$s['copies'] : 1); $c++) {
            $dx = $c * (int)$s['copy_left'];
            $dy = $c * (int)$s['copy_top'];
            $v = ['copy' => self::COPY_LABELS[$c] ?? ''] + $vals;
            foreach ($l['header'] as $it) {
                $html .= self::place($kind, $l, 'header', $it, $v, $dx + (int)$s['header_left'], $dy + (int)$s['header_top'], ['copy' => $c]);
            }
            foreach ($fit as $i => $line) {
                foreach ($l['body'] as $it) {
                    $html .= self::place($kind, $l, 'body', $it, $line + $v, $dx + (int)$s['body_left'], $dy + (int)$s['body_top'] + $i * (int)$s['body_interval'],
                        ['copy' => $c, 'line' => $i, 'src' => implode(',', $line['_src'] ?? [$i])]);
                }
            }
            foreach ($l['footer'] as $it) {
                $html .= self::place($kind, $l, 'footer', $it, $v, $dx + (int)$s['footer_left'], $dy + (int)$s['footer_top'], ['copy' => $c]);
            }
        }
        return $html . '</div></div>';
    }

    /** The style of a pre-printed receipt page (screen and print) */
    public static function css(array $l, bool $printOnly = false): string
    {
        $s = $l['setup'];
        $css = '.wrl-page{position:relative;background:#fff;color:#000;overflow:hidden;font-family:"Courier New",Courier,monospace;line-height:1.15;box-sizing:border-box;print-color-adjust:exact;-webkit-print-color-adjust:exact}'
            . '.wrl-page.wrl-sans{font-family:Arial,Helvetica,sans-serif}'
            . '.wrl-area{position:absolute;overflow:hidden}'
            . '.wrl-f{position:absolute;white-space:nowrap}'
            . '@media screen{.wrl-page{box-shadow:0 10px 28px rgba(15,23,42,.16);outline:1px dashed #cbd5e1;outline-offset:-1px}.wrl-area{outline:1px dotted #e2e8f0}}'
            . '@page{size:' . self::inch($s['page_width']) . ' ' . self::inch($s['page_height']) . ';margin:0}';
        return $css;
    }

    /** The JSON a page's script needs to show and change the entries as the editor of a receipt changes them */
    public static function formatsJson(): string
    {
        return json_encode(['formats' => self::FORMATS, 'zero' => self::ZERO], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
