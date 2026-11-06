<?php
declare(strict_types=1);

/**
 * Passbook printing, set up as in the cooperative's former program (Passbook Type Setup). Every measurement is in
 * tups (twips): 1,440 tups = 1 inch.
 *
 * A passbook type is a savings passbook or a loan passbook; each kind has its own design. A design has four parts,
 * each printed from its own origin (top / left) on the passbook type:
 *  - Cover:  the inside cover (savings: Account Name, Account Number, Address, Contact Number, Branch);
 *  - Header: printed on a page before its first line (savings: Account Name and Number; loan: Loan Type, Term and
 *            Due Date);
 *  - Body:   one line per transaction (savings: line no., date, transaction code, deposit or interest, withdrawal,
 *            balance, teller ID; loan: line no., date, reference no., release, loan payment, loan balance, interest
 *            payment, fines / surcharges payment, teller ID). Line 1 prints at Body Top, every next line one Interval
 *            lower;
 *  - Footer: Print Passbook Footer, and the foot of a page that is full while lines continue on the next page.
 * The user places every field (left / top from the origin of its part, width, alignment, format) on Passbook Types.
 *
 * Lines: printing starts on line 1 of the passbook (in a loan passbook the release is line 1). Start Line Skip and
 * No. of Line Skip are the lines never printed on (the centre fold): Start Line Skip 13 and No. of Line Skip 2 leave
 * lines 13 and 14 blank, so after line 12 the next entry prints on line 15. Max Lines is the last line of a page: the
 * entry after it goes to line 1 of the next page, after the header.
 *
 * Printing is two steps: the page is printed, then the teller confirms it came out right and only then the lines are
 * marked printed (a jam or a misfeed is simply printed again). Reprints and new passbooks need a reason and are logged
 * in passbook_prints and the audit trail.
 */
final class Passbook
{
    public const LEDGERS = ['savings' => 'Savings passbook', 'loan' => 'Loan passbook'];
    public const SECTIONS = ['cover' => 'Cover', 'header' => 'Header', 'body' => 'Body', 'footer' => 'Footer'];

    /**
     * The data each part of a passbook can print: key => [label, kind]. Kinds: text, date, money, int, term, rate.
     * "text" is a fixed text, which may hold {key} of the other fields of its part.
     */
    public const FIELDS = [
        'savings' => [
            'cover'  => ['name' => ['Account Name', 'text'], 'number' => ['Account Number', 'text'], 'address' => ['Address', 'text'], 'contact' => ['Contact Number', 'text'],
                         'branch' => ['Branch', 'text'], 'passbook_no' => ['Passbook No.', 'text'], 'member_no' => ['Member ID', 'text'], 'product' => ['Savings product', 'text'],
                         'opened' => ['Date opened', 'date'], 'text' => ['Text', 'text']],
            'header' => ['name' => ['Account Name', 'text'], 'number' => ['Account Number', 'text'], 'product' => ['Savings product', 'text'], 'passbook_no' => ['Passbook No.', 'text'],
                         'member_no' => ['Member ID', 'text'], 'branch' => ['Branch', 'text'], 'page' => ['Page no.', 'int'], 'text' => ['Text', 'text']],
            'body'   => ['line' => ['Line no.', 'int'], 'date' => ['Date', 'date'], 'code' => ['Transaction code', 'text'], 'credit' => ['Deposit / interest', 'money'],
                         'debit' => ['Withdrawal', 'money'], 'balance' => ['Balance', 'money'], 'teller' => ['Teller ID', 'text'], 'ref' => ['Reference no.', 'text']],
            'footer' => ['balance' => ['Balance', 'money'], 'date' => ['Print date', 'date'], 'page' => ['Page no.', 'int'], 'next_page' => ['Next page no.', 'int'],
                         'number' => ['Account Number', 'text'], 'name' => ['Account Name', 'text'], 'text' => ['Text', 'text']],
        ],
        'loan' => [
            'cover'  => ['name' => ['Member Name', 'text'], 'number' => ['Loan No.', 'text'], 'address' => ['Address', 'text'], 'contact' => ['Contact Number', 'text'],
                         'branch' => ['Branch', 'text'], 'member_no' => ['Member ID', 'text'], 'loan_type' => ['Loan Type', 'text'], 'amount' => ['Loan amount', 'money'],
                         'release_date' => ['Date released', 'date'], 'due' => ['Due Date', 'date'], 'term' => ['Term', 'term'], 'pn_no' => ['P.N. No.', 'text'], 'text' => ['Text', 'text']],
            'header' => ['loan_type' => ['Loan Type', 'text'], 'term' => ['Term', 'term'], 'due' => ['Due Date', 'date'], 'name' => ['Member Name', 'text'], 'number' => ['Loan No.', 'text'],
                         'pn_no' => ['P.N. No.', 'text'], 'amount' => ['Loan amount', 'money'], 'release_date' => ['Date released', 'date'], 'rate' => ['Interest rate', 'rate'],
                         'page' => ['Page no.', 'int'], 'text' => ['Text', 'text']],
            'body'   => ['line' => ['Line no.', 'int'], 'date' => ['Date', 'date'], 'ref' => ['Reference No.', 'text'], 'release' => ['Release', 'money'],
                         'prin' => ['Loan payment (principal)', 'money'], 'bal' => ['Loan balance', 'money'], 'int' => ['Interest payment', 'money'],
                         'fines' => ['Fines / penalties / surcharges', 'money'], 'teller' => ['Teller ID', 'text'], 'total' => ['Total payment', 'money'], 'code' => ['Code', 'text']],
            'footer' => ['balance' => ['Loan balance', 'money'], 'date' => ['Print date', 'date'], 'page' => ['Page no.', 'int'], 'next_page' => ['Next page no.', 'int'],
                         'number' => ['Loan No.', 'text'], 'name' => ['Member Name', 'text'], 'text' => ['Text', 'text']],
        ],
    ];

    /** The formats a field can print in, by its kind */
    public const FORMATS = [
        'date'  => ['n/j/y' => '6/12/25', 'm/d/y' => '06/12/25', 'm/d/Y' => '06/12/2025', 'M j, Y' => 'Jun 12, 2025', 'd/m/Y' => '12/06/2025'],
        'money' => ['comma' => '1,234.56', 'plain' => '1234.56', 'whole' => '1,235'],
        'term'  => ['mos' => '36mos', 'months' => '36 months', 'num' => '36'],
        'rate'  => ['pct' => '18.00%', 'pct0' => '18%'],
    ];
    public const ZERO = ['' => 'blank', '-' => 'a dash', '0' => '0.00'];
    public const ALIGN = ['l' => 'Left', 'r' => 'Right', 'c' => 'Centre'];
    public const HEADER_MODES = ['new_page' => 'On a new page, before line 1', 'every' => 'With every printing', 'never' => 'Never (preprinted)'];
    public const FONTS = ['mono' => 'Dot matrix (Courier New)', 'sans' => 'Arial'];
    /** Characters per inch, as set on a dot-matrix printer (condensed print): 0 = the width of the font size */
    public const CPI = [0 => 'As the font size', 10 => '10 (pica)', 12 => '12 (elite)', 15 => '15', 17 => '17 (condensed)', 20 => '20 (condensed elite)'];
    /** Page sizes offered on Passbook Types (tups): one design fits any passbook */
    public const PAGE_SIZES = [
        '6480x10080'  => 'Passbook 4.5 x 7 in (portrait)',
        '6840x9720'   => 'Passbook 4.75 x 6.75 in (portrait)',
        '7200x10080'  => 'Passbook 5 x 7 in (portrait)',
        '8640x11520'  => 'Passbook 6 x 8 in (portrait)',
        '12240x8640'  => 'Passbook 8.5 x 6 in (landscape)',
        '12240x15840' => 'Letter 8.5 x 11 in (test on plain paper)',
    ];
    /**
     * The kinds of loan ledger entries a loan passbook prints (as the Loan Ledger Form's passbook view). A loan adjustment
     * prints too (version 37: Print to Passbook on Loan Adjustments); the adjustments recorded before that version were
     * marked as passed over by the upgrade 2027_01_31_adjustment_passbook.sql, so they do not come out now.
     */
    public const LOAN_KINDS = ['release', 'payment', 'correction', 'restructure', 'recovery', 'adjustment'];

    // ================================================================================== the account and its lines

    /** The account and its passbook type: ['ledger' => savings|loan, 'account' => row, 'type' => row] */
    public static function open(string $ledger, string $no): ?array
    {
        if ($ledger === 'loan') {
            $a = Loans::byNo($no);
            if (!$a) {
                return null;
            }
            $typeId = (int)DB::value('SELECT passbook_type_id FROM loan_products WHERE id = :p', ['p' => $a['product_id']]);
            $a['account_no'] = $a['loan_no'];
        } else {
            $a = Savings::byNo($no);
            if (!$a) {
                return null;
            }
            $typeId = (int)($a['passbook_type_id'] ?: DB::value('SELECT passbook_type_id FROM sav_products WHERE id = :p', ['p' => $a['product_id']]));
        }
        $type = $typeId ? self::type($typeId) : null;
        return ['ledger' => $ledger, 'account' => $a, 'type' => $type];
    }

    /** A passbook type with its design (the "layout" key: the four parts and the options) */
    public static function type(int $id): ?array
    {
        $t = DB::row('SELECT * FROM passbook_types WHERE id = :id', ['id' => $id]);
        if (!$t) {
            return null;
        }
        $t['ledger'] = self::ledgerOf($t);
        $t['layout'] = self::layout($t);
        return $t;
    }

    /** savings or loan: the column (version 34), or for a type not yet saved since, what uses it */
    public static function ledgerOf(array $t): string
    {
        if (isset($t['ledger']) && isset(self::LEDGERS[$t['ledger']])) {
            return $t['ledger'];
        }
        if (!empty($t['id']) && DB::value('SELECT id FROM loan_products WHERE passbook_type_id = :t LIMIT 1', ['t' => $t['id']])) {
            return 'loan';
        }
        return stripos((string)($t['name'] ?? ''), 'loan') !== false ? 'loan' : 'savings';
    }

    /** The ledger rows not yet printed, oldest first, as passbook lines */
    public static function pending(array $pb): array
    {
        $a = $pb['account'];
        if ($pb['ledger'] === 'loan') {
            $rows = DB::all("SELECT t.*, x.txn_no FROM loan_txns t LEFT JOIN transactions x ON x.id = t.txn_id
                              WHERE t.loan_id = :a AND t.pb_printed = 0 AND t.is_voided = 0 AND t.kind IN ('" . implode("','", self::LOAN_KINDS) . "')
                              ORDER BY t.txn_date, t.id", ['a' => $a['id']]);
            return array_map(fn($t) => self::loanLine($t, $a), $rows);
        }
        $rows = DB::all('SELECT t.* FROM sav_txns t WHERE t.account_id = :a AND t.pb_printed = 0 AND t.is_voided = 0 ORDER BY t.txn_date, t.id', ['a' => $a['id']]);
        return array_map(fn($t) => self::savLine($t), $rows);
    }

    /**
     * A loan ledger row as a passbook line: the reference (the P.N. no. of the release, the OR no. of a payment, EC and
     * the OR no. of an error correction, printed negative in the columns of the payment it negates), the release, the
     * principal, interest and fines paid, the loan (principal) balance and the teller's ID number. A loan adjustment
     * (code AJ, its voucher no. or ADJ) prints a decrease as a payment and an increase negative, in the column of the
     * principal, the interest or the fines it changes.
     */
    public static function loanLine(array $t, ?array $loan = null): array
    {
        $kind = (string)$t['kind'];
        $ref = match ($kind) {
            'release' => (string)(($loan['pn_no'] ?? '') ?: ($t['txn_no'] ?? '') ?: ($t['or_no'] ?? '')),
            'correction' => 'EC ' . (string)(($t['or_no'] ?? '') ?: ($t['txn_no'] ?? '')),
            'adjustment' => (string)(($t['txn_no'] ?? '') ?: 'ADJ'),
            default => (string)(($t['or_no'] ?? '') ?: ($t['txn_no'] ?? '')),
        };
        $code = ['release' => 'LR', 'payment' => 'LP', 'correction' => 'EC', 'restructure' => 'RS', 'recovery' => 'RC', 'adjustment' => 'AJ'][$kind] ?? strtoupper(substr($kind, 0, 2));
        $teller = (int)($t['teller_id'] ?? 0) ?: (int)($t['created_by'] ?? 0);
        $prin = Dec::of($t['principal_cr']);
        $int = Dec::of($t['interest_cr']);
        $fines = Dec::of($t['penalty_cr']);
        if ($kind === 'adjustment') {
            $prin = Dec::sub($t['principal_cr'], $t['principal_dr'], 2);
            $int = Dec::sub($t['interest_cr'], $t['interest_dr'], 2);
            $fines = Dec::sub($t['penalty_cr'], $t['penalty_dr'], 2);
        }
        return ['id' => (int)$t['id'], 'date' => $t['txn_date'], 'ref' => trim($ref), 'code' => $code, 'release' => $kind === 'release' ? Dec::of($t['principal_dr']) : '0',
            'prin' => $prin, 'bal' => Dec::of($t['principal_bal']), 'int' => $int, 'fines' => $fines, 'total' => Dec::add(Dec::add($prin, $int, 2), $fines, 2),
            'balance' => Dec::of($t['principal_bal']), 'teller' => $teller ? (string)$teller : '',
            'line' => isset($t['pb_line']) ? (int)$t['pb_line'] : null, 'page' => isset($t['pb_page']) ? (int)$t['pb_page'] : null];
    }

    /** A savings ledger row as a passbook line; the teller is shown by the ID number of the user */
    public static function savLine(array $t): array
    {
        return ['id' => (int)$t['id'], 'date' => $t['txn_date'], 'code' => $t['txn_code'], 'ref' => (string)($t['trans_no'] ?? ''), 'debit' => Dec::of($t['debit']), 'credit' => Dec::of($t['credit']),
            'balance' => Dec::of($t['balance_after']), 'teller' => $t['teller_id'] ? (string)(int)$t['teller_id'] : '',
            'line' => isset($t['pb_line']) ? (int)$t['pb_line'] : null, 'page' => isset($t['pb_page']) ? (int)$t['pb_page'] : null];
    }

    // ================================================================================== the lines of a page

    /**
     * The line numbers of a page that are never printed on (the centre fold): from Start Line Skip, No. of Line Skip
     * lines (and, for a type set up before version 34 and not yet converted, the lines listed in skip_lines)
     */
    public static function skipped(array $type): array
    {
        $out = [];
        $start = (int)($type['start_line_skip'] ?? 0);
        $n = (int)($type['line_skip'] ?? 0);
        if ($start > 0 && $n > 0) {
            for ($i = $start; $i < $start + $n; $i++) {
                $out[$i] = true;
            }
        }
        foreach (preg_split('/[\s,;]+/', (string)($type['skip_lines'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $l) {
            if (ctype_digit($l) && (int)$l > 0) {
                $out[(int)$l] = true;
            }
        }
        return $out;
    }

    /** The first printable line at or after $line on a page (skipping the fold), or 0 when the page is full */
    public static function printable(array $type, int $line): int
    {
        $skip = self::skipped($type);
        while (isset($skip[$line])) {
            $line++;
        }
        return $line > (int)$type['max_lines'] ? 0 : $line;
    }

    /** Every printable line of a page, in order */
    public static function pageLines(array $type): array
    {
        $out = [];
        for ($l = self::printable($type, 1); $l > 0; $l = self::printable($type, $l + 1)) {
            $out[] = $l;
        }
        return $out;
    }

    /** Where the next line goes: [page, line]. A new passbook starts on line 1; after Max Lines, line 1 of the next page. */
    public static function next(array $pb): array
    {
        $a = $pb['account'];
        $t = $pb['type'];
        $page = max(1, (int)$a['pb_page']);
        $last = (int)$a['pb_last_line'];
        $line = self::printable($t, $last > 0 ? $last + 1 : 1);
        if ($line === 0) {
            $page++;
            $line = self::printable($t, 1) ?: 1;
        }
        return [$page, $line];
    }

    /**
     * The lines that fit on the current page: ['page', 'lines', 'remaining', 'new_page' => true when the printing
     * starts a page (its header is printed first)]
     */
    public static function plan(array $pb): array
    {
        [$page, $line] = self::next($pb);
        $newPage = (int)$pb['account']['pb_last_line'] === 0 || $page > max(1, (int)$pb['account']['pb_page']);
        $pending = self::pending($pb);
        $fit = [];
        foreach ($pending as $p) {
            if ($line === 0) {
                break;
            }
            $p['line'] = $line;
            $fit[] = $p;
            $line = self::printable($pb['type'], $line + 1);
        }
        return ['page' => $page, 'lines' => $fit, 'remaining' => count($pending) - count($fit), 'new_page' => $newPage];
    }

    /** Whether the header prints with this printing: by the passbook type (on a new page, with every printing, never) */
    public static function headerDue(array $pb, array $plan): bool
    {
        $mode = $pb['type']['layout']['opt']['header'] ?? 'new_page';
        return $mode === 'every' || ($mode === 'new_page' && !empty($plan['new_page']) && $plan['lines']);
    }

    /**
     * Every line not yet printed, page by page, for one printing run: the passbook is turned to the next page when a
     * page is full and the printing goes on there (the teller is asked to turn the page). Each page:
     * ['page', 'lines', 'header' => its header prints first, 'forward' => the balance carried to the next page when
     * the page is full and more lines follow (its footer prints when the passbook type says so), 'first', 'last' =>
     * the first and last line numbers]. 'count' is the number of lines of the run.
     */
    public static function run(array $pb): array
    {
        $plan = self::plan($pb);
        $pages = [];
        $page = (int)$plan['page'];
        $lines = array_map(fn($l) => ['page' => $page] + $l, $plan['lines']);
        $header = self::headerDue($pb, $plan);
        $rest = $plan['remaining'] > 0 ? array_slice(self::pending($pb), count($lines)) : [];
        $mode = $pb['type']['layout']['opt']['header'] ?? 'new_page';
        $all = self::pageLines($pb['type']);
        while ($lines) {
            $last = end($lines);
            $pages[] = ['page' => $page, 'lines' => $lines, 'header' => $header, 'forward' => $rest ? (string)$last['balance'] : null,
                'first' => (int)$lines[0]['line'], 'last' => (int)$last['line']];
            // the next page: from its first printable line, after its header
            $page++;
            $lines = [];
            foreach ($all as $n) {
                if (!$rest) {
                    break;
                }
                $l = array_shift($rest);
                $l['line'] = $n;
                $l['page'] = $page;
                $lines[] = $l;
            }
            $header = $mode !== 'never';
            if (!$all) {
                break;
            }
        }
        return ['pages' => $pages, 'count' => array_sum(array_map(fn($p) => count($p['lines']), $pages))];
    }

    /** "page 2 (lines 20 to 26)" and "page 3 (lines 1 to 3)" of a run, for the messages */
    public static function runWords(array $run): string
    {
        $parts = array_map(fn($p) => 'page ' . $p['page'] . ' (' . ($p['first'] === $p['last'] ? 'line ' . $p['first'] : 'lines ' . $p['first'] . ' to ' . $p['last']) . ')', $run['pages']);
        return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts) : (string)($parts[0] ?? '');
    }

    /**
     * The teller confirms the pages of a run that printed right: the first $upto pages of the run (in order; a page
     * can only be confirmed with the pages before it). $posted is what the print window printed (row id => [page,
     * line]); it must still be what the run prints now, or another teller printed (or an earlier-dated entry came
     * in) meanwhile. Returns [lines marked, pages marked].
     */
    public static function commitRun(array $pb, array $posted, int $upto, string $pageKey): array
    {
        return DB::tx(function () use ($pb, $posted, $upto, $pageKey) {
            $acctTable = $pb['ledger'] === 'loan' ? 'loan_accounts' : 'sav_accounts';
            DB::row("SELECT id FROM $acctTable WHERE id = :id FOR UPDATE", ['id' => $pb['account']['id']]);
            // the run as it stands now (with the print line as saved)
            $fresh = self::open($pb['ledger'], (string)$pb['account']['account_no']) ?? $pb;
            $fresh['type'] = $pb['type'];
            $run = self::run($fresh);
            $lines = 0;
            $pages = [];
            foreach ($run['pages'] as $p) {
                if ($p['page'] > $upto) {
                    break;
                }
                $map = [];
                foreach ($p['lines'] as $l) {
                    $got = $posted[(int)$l['id']] ?? null;
                    if (!$got || (int)$got[0] !== (int)$p['page'] || (int)$got[1] !== (int)$l['line']) {
                        fail('The passbook lines changed after they were printed (another teller printed them, or an earlier-dated entry came in). Print the passbook again.');
                    }
                    $map[(int)$l['id']] = (int)$l['line'];
                }
                self::commit($fresh, (int)$p['page'], $map, $pageKey);
                $lines += count($map);
                $pages[] = (int)$p['page'];
            }
            if (!$pages) {
                fail('Nothing was printed.');
            }
            return [$lines, $pages];
        });
    }

    /** The lines of a run as the confirm form posts them: row id => [page, line] (from lines[id] and pg[id]) */
    public static function postedRun(): array
    {
        $out = [];
        $pg = (array)($_POST['pg'] ?? []);
        foreach ((array)($_POST['lines'] ?? []) as $rowId => $line) {
            $out[(int)$rowId] = [(int)($pg[$rowId] ?? $_POST['page'] ?? 0), (int)$line];
        }
        return $out;
    }

    // ================================================================================== the design

    /** The design a new passbook type of the kind starts with */
    public static function defaultLayout(string $ledger): array
    {
        // measured on the passbooks of the former program (a page about 4.5 to 4.75 inches wide, 7 inches high)
        $it = fn(string $f, int $x, int $y = 0, int $w = 0, string $a = 'l', string $fmt = '', string $z = '', string $t = '') => ['f' => $f, 'x' => $x, 'y' => $y, 'w' => $w, 'a' => $a, 'fmt' => $fmt, 'z' => $z, 't' => $t, 'fs' => 0];
        $cover = [$it('name', 0, 0), $it('number', 0, 460), $it('address', 0, 920), $it('contact', 0, 1380), $it('branch', 0, 1840)];
        if ($ledger === 'loan') {
            return [
                'cover'  => $cover,
                'header' => [$it('loan_type', 700, 0), $it('term', 4220, 0, 0, 'l', 'mos'), $it('due', 5600, 0, 0, 'l', 'n/j/y')],
                'body'   => [$it('line', 0, 0, 570, 'r'), $it('date', 680, 0, 0, 'l', 'n/j/y'), $it('ref', 1340, 0), $it('release', 1900, 0, 850, 'r', 'comma'),
                             $it('prin', 2900, 0, 730, 'r', 'comma'), $it('bal', 3800, 0, 900, 'r', 'comma', '0'), $it('int', 4800, 0, 700, 'r', 'comma'),
                             $it('fines', 5700, 0, 510, 'r', 'comma'), $it('teller', 6300, 0, 320, 'r')],
                'footer' => [$it('text', 0, 0, 0, 'l', '', '', 'Loan balance as of {date}: {balance}')],
                'opt'    => ['header' => 'new_page', 'footer_full' => 0, 'font' => 'mono', 'cpi' => 20],
            ];
        }
        return [
            'cover'  => $cover,
            'header' => [$it('name', 160, 0), $it('number', 4430, 0)],
            'body'   => [$it('line', 0, 0, 470, 'r'), $it('date', 530, 0, 0, 'l', 'n/j/y'), $it('code', 1210, 0), $it('credit', 1700, 0, 930, 'r', 'comma', '-'),
                         $it('debit', 2750, 0, 930, 'r', 'comma', '-'), $it('balance', 3750, 0, 1130, 'r', 'comma', '0'), $it('teller', 4950, 0, 290, 'r')],
            'footer' => [$it('text', 0, 0, 0, 'l', '', '', 'Balance as of {date}: {balance}')],
            'opt'    => ['header' => 'new_page', 'footer_full' => 0, 'font' => 'mono', 'cpi' => 20],
        ];
    }

    /** The page and the lines a new passbook type of the kind starts with (the former program's Passbook Type Setup) */
    public static function defaultSetup(string $ledger): array
    {
        return ['page_width' => $ledger === 'loan' ? 6840 : 6480, 'page_height' => $ledger === 'loan' ? 9720 : 10080, 'cover_top' => 900, 'cover_left' => 2400,
            'header_top' => 700, 'header_left' => 300, 'footer_top' => $ledger === 'loan' ? 9300 : 8500, 'footer_left' => 100, 'body_top' => 1230, 'body_left' => $ledger === 'loan' ? 1 : 200,
            'body_interval' => 275, 'start_line_skip' => $ledger === 'loan' ? 13 : 12, 'line_skip' => $ledger === 'loan' ? 2 : 1, 'max_lines' => $ledger === 'loan' ? 29 : 26,
            'font_size_pt' => '9.0'];
    }

    /**
     * The design of a passbook type: layout_json when the type was saved on Passbook Types since version 34, or else
     * its settings of before (the column positions, the header and cover positions, the footer text) turned into a
     * design, with the parts they did not have taken from the default design of its kind.
     */
    public static function layout(array $t): array
    {
        $ledger = self::ledgerOf($t);
        $j = json_decode((string)($t['layout_json'] ?? ''), true);
        if (is_array($j) && isset($j['body'])) {
            return self::clean($ledger, $j);
        }
        $def = self::defaultLayout($ledger);
        $it = fn(string $f, int $x, int $y = 0, int $w = 0, string $a = 'l', string $fmt = '', string $z = '', string $txt = '') => ['f' => $f, 'x' => $x, 'y' => $y, 'w' => $w, 'a' => $a, 'fmt' => $fmt, 'z' => $z, 't' => $txt, 'fs' => 0];
        $out = $def;
        $dash = (int)($t['dash_empty'] ?? 1) === 1 ? '-' : '';
        // the columns of a savings passbook: the first column printed at "Lines: left", the others at their distance from it
        $cols = array_map('intval', (array)json_decode((string)($t['columns_json'] ?? ''), true));
        if ($ledger === 'savings' && $cols) {
            $min = min($cols);
            asort($cols);
            $keys = array_keys($cols);
            $body = [];
            foreach ($keys as $i => $k) {
                if (!isset(self::FIELDS['savings']['body'][$k])) {
                    continue;
                }
                $x = $cols[$k] - $min;
                $next = isset($keys[$i + 1]) ? $cols[$keys[$i + 1]] - $min : (int)$t['page_width'] - (int)$t['body_left'];
                if ($k === 'line') {
                    $body[] = $it('line', $x, 0, 360, 'r');
                } elseif (in_array($k, ['debit', 'credit', 'balance'], true)) {
                    $body[] = $it($k, $x, 0, max(600, $next - $x - 120), 'r', 'comma', $k === 'balance' ? '0' : $dash);
                } else {
                    $body[] = $it($k, $x, 0, 0, 'l', $k === 'date' ? 'n/j/y' : '');
                }
            }
            if (!isset($cols['line']) && isset($cols['date'])) {
                // the line number printed beside the date
                array_unshift($body, $it('line', max(-(int)$t['body_left'], $cols['date'] - $min - 420), 0, 360, 'r'));
            }
            $out['body'] = $body;
            // laid out for the plain width of the font: printed as before
            $out['opt']['cpi'] = 0;
        }
        $hdr = (array)json_decode((string)($t['header_json'] ?? ''), true);
        if ($ledger === 'savings' && $hdr) {
            $out['header'] = [];
            foreach (['name', 'number'] as $k) {
                if (isset($hdr[$k][0], $hdr[$k][1]) && $hdr[$k][0] !== '' && $hdr[$k][1] !== '') {
                    $out['header'][] = $it($k, (int)$hdr[$k][1] - (int)$t['header_left'], (int)$hdr[$k][0] - (int)$t['header_top']);
                }
            }
        }
        $cov = (array)json_decode((string)($t['cover_json'] ?? ''), true);
        if ($cov) {
            $out['cover'] = [];
            foreach (['name', 'number', 'address', 'contact', 'branch'] as $k) {
                if (isset($cov[$k][0], $cov[$k][1]) && $cov[$k][0] !== '' && $cov[$k][1] !== '') {
                    $out['cover'][] = $it($k, (int)$cov[$k][1] - (int)$t['cover_left'], (int)$cov[$k][0] - (int)$t['cover_top']);
                }
            }
        }
        if (trim((string)($t['footer_text'] ?? '')) !== '') {
            $out['footer'] = [$it('text', 0, 0, 0, 'l', '', '', (string)$t['footer_text'])];
        }
        return $out;
    }

    /** A design as saved: only the fields of its kind and part, every value within its range */
    public static function clean(string $ledger, array $j): array
    {
        $out = [];
        foreach (array_keys(self::SECTIONS) as $sec) {
            $out[$sec] = [];
            foreach ((array)($j[$sec] ?? []) as $item) {
                $f = (string)($item['f'] ?? '');
                if (!is_array($item) || !isset(self::FIELDS[$ledger][$sec][$f])) {
                    continue;
                }
                $kind = self::FIELDS[$ledger][$sec][$f][1];
                $fmt = (string)($item['fmt'] ?? '');
                if (!isset(self::FORMATS[$kind][$fmt])) {
                    $fmt = isset(self::FORMATS[$kind]) ? (string)array_key_first(self::FORMATS[$kind]) : '';
                }
                $fs = (float)($item['fs'] ?? 0);
                $out[$sec][] = ['f' => $f, 'x' => max(-14400, min(30000, (int)($item['x'] ?? 0))), 'y' => max(-14400, min(30000, (int)($item['y'] ?? 0))),
                    'w' => max(0, min(30000, (int)($item['w'] ?? 0))), 'a' => isset(self::ALIGN[$item['a'] ?? '']) ? (string)$item['a'] : 'l', 'fmt' => $fmt,
                    'z' => $kind === 'money' && isset(self::ZERO[(string)($item['z'] ?? '')]) ? (string)$item['z'] : '', 't' => mb_substr((string)($item['t'] ?? ''), 0, 160),
                    'fs' => $fs >= 6 && $fs <= 20 ? round($fs, 1) : 0];
            }
        }
        $o = (array)($j['opt'] ?? []);
        $out['opt'] = ['header' => isset(self::HEADER_MODES[$o['header'] ?? '']) ? (string)$o['header'] : 'new_page', 'footer_full' => !empty($o['footer_full']) ? 1 : 0,
            'font' => isset(self::FONTS[$o['font'] ?? '']) ? (string)$o['font'] : 'mono', 'cpi' => isset(self::CPI[(int)($o['cpi'] ?? 0)]) ? (int)$o['cpi'] : 0];
        return $out;
    }

    // ================================================================================== values and formats

    /** The values of the cover, header and footer of an account's passbook */
    public static function accountValues(array $pb, ?string $balance = null, ?int $page = null): array
    {
        $a = $pb['account'];
        $m = !empty($a['member_id']) ? Coop::member((int)$a['member_id']) : null;
        $contact = $m ? trim((string)($m['mobile'] ?: (string)DB::value('SELECT number FROM member_contacts WHERE member_id = :m ORDER BY is_primary DESC, id LIMIT 1', ['m' => $m['id']]))) : '';
        $branch = !empty($a['branch_id']) ? (string)DB::value('SELECT name FROM coop_branches WHERE id = :b', ['b' => $a['branch_id']]) : '';
        $page = $page ?? max(1, (int)$a['pb_page']);
        $v = ['name' => (string)($a['member_name'] ?? ''), 'number' => (string)$a['account_no'], 'address' => $m ? Members::address($m) : '', 'contact' => $contact, 'branch' => $branch,
            'member_no' => (string)($a['member_no'] ?? ''), 'page' => (string)$page, 'next_page' => (string)($page + 1), 'date' => today()];
        if ($pb['ledger'] === 'loan') {
            $v += ['loan_type' => (string)($a['product_name'] ?? ''), 'amount' => Dec::of($a['principal']), 'release_date' => (string)$a['release_date'], 'due' => (string)$a['due_date'],
                'term' => Dec::fix($a['term'], 2) . '|' . $a['term_unit'], 'pn_no' => (string)($a['pn_no'] ?? ''), 'rate' => Dec::fix($a['rate'], 4),
                'balance' => $balance ?? Dec::of($a['principal_balance'])];
        } else {
            $v += ['passbook_no' => (string)($a['passbook_no'] ?? ''), 'product' => (string)($a['product_name'] ?? ''), 'opened' => (string)($a['opened_on'] ?? ''),
                'balance' => $balance ?? Dec::of($a['balance'])];
        }
        return $v;
    }

    /** Sample values to show a design (Passbook Types) and to print a test page */
    public static function sampleValues(string $ledger): array
    {
        $v = ['name' => 'Santos, Maria', 'number' => $ledger === 'loan' ? '01-6-0000012' : '01100001011', 'address' => 'Purok 3, Poblacion, Cordova, Cebu', 'contact' => '0917 555 0123',
            'branch' => 'Head Office', 'member_no' => '100001', 'page' => '1', 'next_page' => '2', 'date' => today()];
        if ($ledger === 'loan') {
            return $v + ['loan_type' => 'Salary Loan', 'amount' => '400000.00', 'release_date' => '2025-06-12', 'due' => '2027-06-12', 'term' => '36.00|months', 'pn_no' => '48229',
                'rate' => '18.0000', 'balance' => '183075.58'];
        }
        return $v + ['passbook_no' => '642', 'product' => 'Regular Savings', 'opened' => '2025-01-15', 'balance' => '293818.94'];
    }

    /** Sample passbook lines (the examples of the former passbooks) */
    public static function sampleLines(string $ledger, int $count): array
    {
        $out = [];
        if ($ledger === 'loan') {
            $bal = '400000.00';
            for ($i = 0; $i < $count; $i++) {
                $date = date('Y-m-d', strtotime('2025-06-12 +' . $i . ' month'));
                if ($i === 0) {
                    $out[] = ['date' => $date, 'ref' => '48229', 'code' => 'LR', 'release' => '400000.00', 'prin' => '0', 'bal' => $bal, 'int' => '0', 'fines' => '0', 'total' => '0', 'balance' => $bal, 'teller' => '13'];
                    continue;
                }
                $prin = Dec::fix((string)(9000 + ($i * 977) % 4000) . '.42');
                $int = Dec::fix((string)(6600 - $i * 40) . '.58');
                $bal = Dec::sub($bal, $prin, 2);
                $fines = $i === 2 ? '3.33' : '0';
                $out[] = ['date' => $date, 'ref' => (string)(177790 + $i * 650), 'code' => 'LP', 'release' => '0', 'prin' => $prin, 'bal' => $bal, 'int' => $int, 'fines' => $fines,
                    'total' => Dec::add(Dec::add($prin, $int, 2), $fines, 2), 'balance' => $bal, 'teller' => $i % 4 === 2 ? '10' : '4'];
            }
            return $out;
        }
        $bal = '301975.68';
        $codes = ['CSD', 'CSD', 'CSW', 'CSD', 'CSD', 'CSW', 'CSD', 'INT', 'CSD', 'CSW'];
        for ($i = 0; $i < $count; $i++) {
            $code = $codes[$i % count($codes)];
            $amt = Dec::fix((string)(350 + ($i * 613) % 4800) . '.00');
            $dep = $code === 'CSW' ? '0' : $amt;
            $wd = $code === 'CSW' ? $amt : '0';
            $bal = Dec::sub(Dec::add($bal, $dep, 2), $wd, 2);
            $out[] = ['date' => date('Y-m-d', strtotime('2026-09-30 +' . intdiv($i, 3) . ' day')), 'code' => $code, 'ref' => sprintf('%07d', 9 + $i), 'credit' => $dep, 'debit' => $wd, 'balance' => $bal, 'teller' => '10'];
        }
        return $out;
    }

    /** A value as printed by a field of the design */
    public static function format(string $ledger, string $section, array $item, array $vals): string
    {
        $f = $item['f'];
        $kind = self::FIELDS[$ledger][$section][$f][1] ?? 'text';
        if ($f === 'text') {
            $rep = [];
            foreach (self::FIELDS[$ledger][$section] as $k => [$label, $kd]) {
                if ($k !== 'text') {
                    $rep['{' . $k . '}'] = self::format($ledger, $section, ['f' => $k, 'fmt' => isset(self::FORMATS[$kd]) ? array_key_first(self::FORMATS[$kd]) : '', 'z' => ''], $vals);
                }
            }
            return strtr((string)($item['t'] ?? ''), $rep);
        }
        $raw = $vals[$f] ?? '';
        $fmt = (string)($item['fmt'] ?? '');
        switch ($kind) {
            case 'date':
                return $raw !== '' && valid_date(substr((string)$raw, 0, 10)) ? date($fmt !== '' && isset(self::FORMATS['date'][$fmt]) ? $fmt : 'n/j/y', strtotime(substr((string)$raw, 0, 10))) : '';
            case 'money':
                $v = Dec::of($raw === '' ? '0' : $raw);
                if (Dec::isZero($v)) {
                    return ['-' => '-', '0' => $fmt === 'plain' ? '0.00' : ($fmt === 'whole' ? '0' : '0.00')][(string)($item['z'] ?? '')] ?? '';
                }
                $abs = Dec::abs($v);
                $txt = match ($fmt) {
                    'plain' => Dec::fix($abs, 2),
                    'whole' => number_format((float)$abs, 0),
                    default => Dec::money($abs),
                };
                return (Dec::isNeg($v) ? '-' : '') . $txt;
            case 'term':
                [$n, $unit] = array_pad(explode('|', (string)$raw), 2, 'months');
                if ($n === '') {
                    return '';
                }
                $n = rtrim(rtrim((string)$n, '0'), '.');
                return match ($fmt) {
                    'months' => $n . ' ' . ($unit === 'days' ? 'days' : 'months'),
                    'num' => $n,
                    default => $n . ($unit === 'days' ? 'days' : 'mos'),
                };
            case 'rate':
                return $raw === '' ? '' : ($fmt === 'pct0' ? rtrim(rtrim(Dec::fix($raw, 2), '0'), '.') : Dec::fix($raw, 2)) . '%';
            default:
                return (string)$raw;
        }
    }

    // ================================================================================== printing

    private static function inch(int $tw): string
    {
        return rtrim(rtrim(number_format($tw / 1440, 4, '.', ''), '0'), '.') . 'in';
    }

    /** The sheet of one passbook page (its size and font from the passbook type) */
    private static function sheetOpen(array $t, float $fontPlus = 0): string
    {
        $font = ($t['layout']['opt']['font'] ?? 'mono') === 'sans' ? ' pb-sans' : '';
        return '<div class="pb-page pb-sheet pb-screen-frame' . $font . '" style="width:' . self::inch((int)$t['page_width']) . ';height:' . self::inch((int)$t['page_height'])
            . ';font-size:' . ((float)$t['font_size_pt'] + $fontPlus) . 'pt">';
    }

    /**
     * The horizontal scale of the characters for the characters per inch of the design (condensed print): Courier New is
     * 0.6 em wide, so 120 / font size characters to the inch; Arial digits about 0.556 em
     */
    public static function squeeze(array $t, float $fs): float
    {
        $cpi = (int)($t['layout']['opt']['cpi'] ?? 0);
        if ($cpi <= 0 || $fs <= 0) {
            return 1.0;
        }
        $natural = (($t['layout']['opt']['font'] ?? 'mono') === 'sans' ? 129.5 : 120) / $fs;
        return round(min(2.0, max(0.3, $natural / $cpi)), 4);
    }

    /** The fields of one part of the design at its origin (+ $dy for a body line) */
    private static function place(array $t, string $section, array $vals, int $dy = 0, float $fontPlus = 0): string
    {
        [$ox, $oy] = match ($section) {
            'cover' => [(int)$t['cover_left'], (int)$t['cover_top']],
            'header' => [(int)$t['header_left'], (int)$t['header_top']],
            'footer' => [(int)$t['footer_left'], (int)$t['footer_top']],
            default => [(int)$t['body_left'], (int)$t['body_top']],
        };
        $html = '';
        foreach ($t['layout'][$section] ?? [] as $item) {
            $v = self::format($t['ledger'], $section, $item, $vals);
            if ($v === '') {
                continue;
            }
            $left = max(0, $ox + (int)$item['x']);
            $style = 'top:' . self::inch(max(0, $oy + (int)$item['y'] + $dy)) . ';left:' . self::inch($left);
            if ((int)$item['w'] > 0 && $item['a'] !== 'l') {
                $style .= ';width:' . self::inch((int)$item['w']) . ';text-align:' . ($item['a'] === 'r' ? 'right' : 'center');
            }
            $fs = ((float)$item['fs'] > 0 ? (float)$item['fs'] : (float)$t['font_size_pt']) + $fontPlus;
            if ((float)$item['fs'] > 0) {
                $style .= ';font-size:' . $fs . 'pt';
            }
            $k = self::squeeze($t, $fs);
            if ($k !== 1.0) {
                $style .= ';transform:scaleX(' . rtrim(rtrim(number_format($k, 4, '.', ''), '0'), '.') . ');transform-origin:' . ($item['a'] === 'r' && (int)$item['w'] > 0 ? 'right' : ($item['a'] === 'c' && (int)$item['w'] > 0 ? 'center' : 'left')) . ' top';
            }
            $html .= '<div class="pb-line" data-f="' . h($item['f']) . '" style="' . $style . '">' . h($v) . '</div>';
        }
        return $html;
    }

    /** The body lines (each at Body Top + (line - 1) x Interval) */
    private static function bodyLines(array $t, array $lines): string
    {
        $html = '';
        foreach ($lines as $l) {
            $html .= self::place($t, 'body', $l + ['line' => (string)(int)$l['line']], ((int)$l['line'] - 1) * (int)$t['body_interval']);
        }
        return $html;
    }

    /**
     * The printed page: the header when $header (a new page), the lines, and the footer when the page is full and
     * more lines follow on the next one ($forward = the balance carried) and the passbook type prints it then.
     */
    public static function render(array $pb, int $page, array $lines, bool $header = false, ?string $forward = null): string
    {
        $t = $pb['type'];
        $html = self::sheetOpen($t);
        if ($header) {
            $html .= self::place($t, 'header', self::accountValues($pb, null, $page));
        }
        $html .= self::bodyLines($t, $lines);
        if ($forward !== null && !empty($t['layout']['opt']['footer_full'])) {
            $html .= self::place($t, 'footer', self::accountValues($pb, $forward, $page));
        }
        return $html . '</div>';
    }

    /** The inside cover */
    public static function renderCover(array $pb): string
    {
        $t = $pb['type'];
        return self::sheetOpen($t, 1) . self::place($t, 'cover', self::accountValues($pb), 0, 1) . '</div>';
    }

    /** The header of the current page alone (a passbook whose header was not printed) */
    public static function renderHeader(array $pb, ?int $page = null): string
    {
        $t = $pb['type'];
        return self::sheetOpen($t) . self::place($t, 'header', self::accountValues($pb, null, $page)) . '</div>';
    }

    /** The footer of the current page (Print Passbook Footer) */
    public static function renderFooter(array $pb, string $balance): string
    {
        $t = $pb['type'];
        return self::sheetOpen($t) . self::place($t, 'footer', self::accountValues($pb, $balance)) . '</div>';
    }

    /**
     * A test page of a passbook type with sample data: 'cover', 'page' (the header, every printable line of a page and
     * the footer) or 'lines' (the line numbers alone, to check the interval and the skipped lines on the passbook).
     */
    public static function renderTest(array $t, string $what): string
    {
        $vals = self::sampleValues($t['ledger']);
        if ($what === 'cover') {
            return self::sheetOpen($t, 1) . self::place($t, 'cover', $vals, 0, 1) . '</div>';
        }
        $nums = self::pageLines($t);
        if ($what === 'lines') {
            $html = self::sheetOpen($t);
            foreach ($nums as $n) {
                $html .= '<div class="pb-line" style="top:' . self::inch((int)$t['body_top'] + ($n - 1) * (int)$t['body_interval']) . ';left:' . self::inch((int)$t['body_left']) . '">'
                    . h(str_pad((string)$n, 2, ' ', STR_PAD_LEFT) . ' ' . str_repeat('-', 30)) . '</div>';
            }
            return $html . '</div>';
        }
        $sample = self::sampleLines($t['ledger'], count($nums));
        $lines = [];
        foreach ($nums as $i => $n) {
            $lines[] = $sample[$i] + ['line' => $n];
        }
        $html = self::sheetOpen($t) . self::place($t, 'header', $vals) . self::bodyLines($t, $lines);
        $last = end($lines);
        return $html . self::place($t, 'footer', ['balance' => $last ? (string)$last['balance'] : '0'] + $vals) . '</div>';
    }

    // ================================================================================== the print state

    /** The printed lines of a date range, by the page and line they were printed on (Reprint passbook) */
    public static function printedBetween(array $pb, string $from, string $to): array
    {
        $a = $pb['account'];
        $pages = [];
        if ($pb['ledger'] === 'loan') {
            $rows = DB::all("SELECT t.*, x.txn_no FROM loan_txns t LEFT JOIN transactions x ON x.id = t.txn_id WHERE t.loan_id = :a AND t.is_voided = 0 AND t.pb_printed = 1 AND t.pb_page IS NOT NULL
                                AND t.txn_date BETWEEN :f AND :t AND t.kind IN ('" . implode("','", self::LOAN_KINDS) . "') ORDER BY t.pb_page, t.pb_line, t.id", ['a' => $a['id'], 'f' => $from, 't' => $to]);
            foreach ($rows as $r) {
                $pages[(int)$r['pb_page']][] = self::loanLine($r, $a);
            }
            return $pages;
        }
        $rows = DB::all('SELECT t.* FROM sav_txns t WHERE t.account_id = :a AND t.is_voided = 0 AND t.pb_printed = 1 AND t.txn_date BETWEEN :f AND :t ORDER BY t.pb_page, t.pb_line, t.id',
            ['a' => $a['id'], 'f' => $from, 't' => $to]);
        foreach ($rows as $r) {
            $pages[(int)$r['pb_page']][] = self::savLine($r);
        }
        return $pages;
    }

    /**
     * Resets the print line (a misprint, or a new passbook given to the member): the next line prints at page $page,
     * line $line; with $fromRowId, that transaction and every one after it are printed again from there.
     */
    public static function resetLine(array $pb, int $page, int $line, ?int $fromRowId, string $reason, string $pageKey): int
    {
        if (trim($reason) === '') {
            fail('Enter the reason for resetting the print line.');
        }
        $t = $pb['type'];
        if ($page < 1 || $line < 1 || $line > (int)$t['max_lines']) {
            fail('Enter a page from 1 and a line from 1 to ' . (int)$t['max_lines'] . '.');
        }
        if (isset(self::skipped($t)[$line])) {
            fail('Line ' . $line . ' is never printed on (Start Line Skip and No. of Line Skip of the passbook type). Choose another line.');
        }
        return DB::tx(function () use ($pb, $page, $line, $fromRowId, $reason, $pageKey) {
            $a = $pb['account'];
            $table = $pb['ledger'] === 'loan' ? 'loan_txns' : 'sav_txns';
            $acctTable = $pb['ledger'] === 'loan' ? 'loan_accounts' : 'sav_accounts';
            $fk = $pb['ledger'] === 'loan' ? 'loan_id' : 'account_id';
            DB::row("SELECT id FROM $acctTable WHERE id = :id FOR UPDATE", ['id' => $a['id']]);
            $n = 0;
            if ($fromRowId) {
                $first = DB::row("SELECT * FROM $table WHERE id = :id AND $fk = :a", ['id' => $fromRowId, 'a' => $a['id']]);
                if (!$first) {
                    fail('Choose a transaction of the account.');
                }
                $n = DB::run("UPDATE $table SET pb_printed = 0, pb_page = NULL, pb_line = NULL WHERE $fk = :a AND is_voided = 0 AND (txn_date > :d OR (txn_date = :d2 AND id >= :id))",
                    ['a' => $a['id'], 'd' => $first['txn_date'], 'd2' => $first['txn_date'], 'id' => $first['id']])->rowCount();
            }
            // the line before the one chosen is the last printed (the fold lines before it count as passed)
            $prev = 0;
            for ($l = $line - 1; $l >= 1; $l--) {
                if (!isset(self::skipped($pb['type'])[$l])) {
                    $prev = $l;
                    break;
                }
            }
            DB::update($acctTable, ['pb_page' => $page, 'pb_last_line' => $prev], 'id = :id', ['id' => $a['id']]);
            DB::insert('passbook_prints', ['account_id' => $a['id'], 'ledger' => $pb['ledger'], 'print_type' => 'reset', 'from_txn' => $fromRowId, 'line_count' => $n, 'page_no' => $page,
                'reason' => mb_substr('Next line page ' . $page . ' line ' . $line . ': ' . $reason, 0, 255), 'printed_by' => Auth::id()]);
            Audit::log('passbook_reset', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$a['id'], $a['account_no'], ['page_key' => $pageKey,
                'reason' => $reason . ' (next line: page ' . $page . ', line ' . $line . ($n ? '; ' . $n . ' transaction(s) to print again' : '') . ')']);
            return $n;
        });
    }

    /** The teller confirms the page printed right: the lines are marked with their page and line */
    public static function commit(array $pb, int $page, array $lineMap, string $pageKey): void
    {
        DB::tx(function () use ($pb, $page, $lineMap, $pageKey) {
            $a = $pb['account'];
            $table = $pb['ledger'] === 'loan' ? 'loan_txns' : 'sav_txns';
            $acctTable = $pb['ledger'] === 'loan' ? 'loan_accounts' : 'sav_accounts';
            $fk = $pb['ledger'] === 'loan' ? 'loan_id' : 'account_id';
            DB::row("SELECT id FROM $acctTable WHERE id = :id FOR UPDATE", ['id' => $a['id']]);
            $last = 0;
            $lastTxn = null;
            foreach ($lineMap as $rowId => $line) {
                $n = DB::run("UPDATE $table SET pb_printed = 1, pb_page = :pg, pb_line = :ln WHERE id = :id AND $fk = :a AND pb_printed = 0", ['pg' => $page, 'ln' => (int)$line, 'id' => (int)$rowId, 'a' => $a['id']])->rowCount();
                if ($n === 0) {
                    fail('The passbook was printed by someone else in the meantime. Reload the page.');
                }
                $last = max($last, (int)$line);
                $lastTxn = (int)$rowId;
            }
            DB::update($acctTable, ['pb_page' => $page, 'pb_last_line' => $last, 'pb_last_txn_id' => $lastTxn], 'id = :id', ['id' => $a['id']]);
            DB::insert('passbook_prints', ['account_id' => $a['id'], 'ledger' => $pb['ledger'], 'print_type' => 'lines', 'from_txn' => (int)array_key_first($lineMap), 'to_txn' => $lastTxn,
                'line_count' => count($lineMap), 'page_no' => $page, 'printed_by' => Auth::id()]);
            Audit::log('passbook_print', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$a['id'], $a['account_no'], ['page_key' => $pageKey, 'reason' => count($lineMap) . ' line(s) on page ' . $page]);
        });
    }

    /** A new passbook (lost, full, damaged): printing starts again on page 1, line 1; the old lines stay marked as printed */
    public static function replace(array $pb, string $passbookNo, string $reason, string $pageKey): void
    {
        if (trim($reason) === '') {
            fail('Enter the reason for the new passbook.');
        }
        $a = $pb['account'];
        $acctTable = $pb['ledger'] === 'loan' ? 'loan_accounts' : 'sav_accounts';
        $upd = ['pb_page' => 1, 'pb_last_line' => 0];
        if ($pb['ledger'] === 'savings') {
            $upd['passbook_no'] = mb_substr(trim($passbookNo), 0, 20) ?: $a['passbook_no'];
            $upd['pb_issued_count'] = (int)$a['pb_issued_count'] + 1;
        }
        DB::update($acctTable, $upd, 'id = :id', ['id' => $a['id']]);
        DB::insert('passbook_prints', ['account_id' => $a['id'], 'ledger' => $pb['ledger'], 'print_type' => 'replace', 'reason' => mb_substr($reason, 0, 255), 'printed_by' => Auth::id()]);
        Audit::log('passbook_replace', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$a['id'], $a['account_no'], ['page_key' => $pageKey, 'reason' => $reason]);
    }

    /** Lines to print again (from a ledger row on), e.g. after a misprint: they go back to unprinted */
    public static function unmark(array $pb, int $fromRowId, string $reason, string $pageKey): int
    {
        if (trim($reason) === '') {
            fail('Enter the reason for reprinting.');
        }
        $a = $pb['account'];
        $table = $pb['ledger'] === 'loan' ? 'loan_txns' : 'sav_txns';
        $fk = $pb['ledger'] === 'loan' ? 'loan_id' : 'account_id';
        $first = DB::row("SELECT * FROM $table WHERE id = :id AND $fk = :a AND pb_printed = 1", ['id' => $fromRowId, 'a' => $a['id']]);
        if (!$first) {
            fail('Choose a printed line.');
        }
        $n = DB::run("UPDATE $table SET pb_printed = 0 WHERE $fk = :a AND pb_printed = 1 AND (pb_page > :pg OR (pb_page = :pg2 AND pb_line >= :ln))",
            ['a' => $a['id'], 'pg' => $first['pb_page'], 'pg2' => $first['pb_page'], 'ln' => $first['pb_line']])->rowCount();
        $acctTable = $pb['ledger'] === 'loan' ? 'loan_accounts' : 'sav_accounts';
        // the line printed before it is the last one (the fold lines in between are passed again)
        $prev = 0;
        for ($l = (int)$first['pb_line'] - 1; $l >= 1; $l--) {
            if (!isset(self::skipped($pb['type'])[$l])) {
                $prev = $l;
                break;
            }
        }
        DB::update($acctTable, ['pb_page' => (int)$first['pb_page'], 'pb_last_line' => $prev], 'id = :id', ['id' => $a['id']]);
        DB::insert('passbook_prints', ['account_id' => $a['id'], 'ledger' => $pb['ledger'], 'print_type' => 'reprint', 'from_txn' => $fromRowId, 'line_count' => $n, 'page_no' => (int)$first['pb_page'], 'reason' => mb_substr($reason, 0, 255), 'printed_by' => Auth::id()]);
        Audit::log('passbook_reprint', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$a['id'], $a['account_no'], ['page_key' => $pageKey, 'reason' => $reason . ' (' . $n . ' line(s))']);
        return $n;
    }
}
