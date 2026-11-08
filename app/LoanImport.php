<?php
declare(strict_types=1);

/**
 * Import Loans (Loan Accounts Center): the loans of the former system from a CSV file, reviewed in a grid first
 * (app/CsvReview.php), then made Active all at once.
 *
 * A row gives the loan as it was released (product, principal, term, mode of payment, amortization type, rate,
 * release date) and what was paid on it up to the cut-off date (principal, interest and penalties, each as one total).
 * The loan is rebuilt from it:
 *  - its amortization schedule from the release date, as a released loan's;
 *  - the totals paid are taken as paid on the due dates of the schedule, in order (the former system gives one total,
 *    not the dates of the payments): the principal paid on the principal of the amortizations, the interest paid on
 *    their interest; what the totals do not cover by the cut-off date is past due;
 *  - the overdue penalties at the cut-off date: those given in the file, or worked out by the product's penalty terms
 *    on the past-due amortizations (as Post Loan Penalties would), less the penalties already paid;
 *  - the past due interest of a loan already matured at the cut-off date: the one given, or worked out on the
 *    principal still unpaid from the maturity date to the cut-off date at the loan's rate (none when the product
 *    charges no additional interest), less the interest paid beyond the schedule.
 * One journal voucher per cut-off date and balancing account: each loan's receivable debited with its principal
 * balance, the balancing account chosen credited with the total. The interest and penalties stay in the loan's own
 * ledger, as on a released loan (income when collected or accrued).
 */
final class LoanImport
{
    /**
     * Who a row's member code names (the consolidated import of Member Center gives the members of its file, not yet on
     * file); null: the member on file
     */
    public static ?Closure $memberResolver = null;

    /** The lists check() reads are read once a request (the consolidated import checks one member at a time) */
    public static bool $memo = false;

    private static array $lists = [];

    /** How the loan is amortized: the interest method and the amortization type of the loan */
    public const AMORT = ['equal_amortization' => 'Diminishing, equal amortization', 'equal_principal' => 'Diminishing, equal principal', 'straight' => 'Straight (add-on)'];

    public static function cols(): array
    {
        $modes = array_map(fn($m) => $m[0], Coop::MODES);
        return [
            'member_code' => ['label' => 'Member code', 'req' => true, 'size' => 'sm', 'hint' => 'The member no. of the borrower (imported first).'],
            'member_name' => ['label' => 'Member name', 'req' => true, 'size' => 'lg', 'hint' => 'Checked against the member no.: every word of the last name and the first name.'],
            'loan_name' => ['label' => 'Loan name', 'req' => true, 'type' => 'select', 'options' => self::products(), 'size' => 'md', 'hint' => 'The loan product: its code or its name (Loan Products).'],
            'principal' => ['label' => 'Principal loan', 'req' => true, 'type' => 'money', 'size' => 'sm', 'hint' => 'The principal released.'],
            'term' => ['label' => 'Terms (months / days)', 'req' => true, 'size' => 'sm', 'hint' => 'In months (12, 12 months, 12M), days (180 days, 180D) or years (1 year).'],
            'mode' => ['label' => 'Mode of payment', 'req' => true, 'type' => 'select', 'options' => $modes, 'size' => 'sm', 'hint' => implode(', ', $modes) . '.'],
            'amort_type' => ['label' => 'Amortization type', 'req' => true, 'type' => 'select', 'options' => self::AMORT, 'size' => 'md', 'hint' => 'Diminishing, equal amortization; Diminishing, equal principal; or Straight (add-on).'],
            'rate' => ['label' => 'Interest rate % a year', 'req' => true, 'size' => 'xs', 'hint' => '18 or 18% a year; 1.5% a month is taken as 18 a year. Blank: the rate of the product.'],
            'release_date' => ['label' => 'Release date', 'req' => true, 'type' => 'date', 'size' => 'sm'],
            'principal_paid' => ['label' => 'Principal paid', 'req' => true, 'type' => 'money', 'size' => 'sm', 'hint' => 'The principal paid up to the cut-off date, one total.'],
            'interest_paid' => ['label' => 'Interest paid', 'req' => true, 'type' => 'money', 'size' => 'sm', 'hint' => 'The interest paid up to the cut-off date, one total.'],
            'penalties_paid' => ['label' => 'Penalties paid', 'req' => true, 'type' => 'money', 'size' => 'sm', 'hint' => 'The penalties paid up to the cut-off date, one total.'],
            'cut_off_date' => ['label' => 'Cut-off date', 'req' => true, 'filled' => true, 'type' => 'date', 'size' => 'sm', 'hint' => 'The date of the totals paid. Blank: the date chosen on the page.'],
            'past_due_interest' => ['label' => 'Past due interest', 'type' => 'money', 'more' => true, 'hint' => 'Optional: the past due interest still unpaid at the cut-off date, as worked out by hand. Blank: worked out by the program.'],
            'overdue_penalties' => ['label' => 'Overdue penalties', 'type' => 'money', 'more' => true, 'hint' => 'Optional: the penalties still unpaid at the cut-off date, as worked out by hand. Blank: worked out by the program.'],
            'first_due' => ['label' => 'First due date', 'type' => 'date', 'more' => true, 'hint' => 'Blank: one period after the release date (a lump sum: at the end of the term).'],
            'loan_no' => ['label' => 'Loan no.', 'more' => true, 'hint' => 'The loan no. of the former system. Blank: the next loan no.'],
            'pn_no' => ['label' => 'PN no.', 'more' => true, 'hint' => 'The promissory note no. (up to 30 characters).'],
            'accrued_interest' => ['label' => 'Accrued interest', 'type' => 'money', 'more' => true, 'hint' => 'Optional: the unpaid interest already booked as income in the former books (Interest Receivable at the cut-off date). It is debited to Loan Interest Receivable, and the interest accrual does not book it again. Blank: none.'],
            'balancing_account' => ['label' => 'Balancing GL account', 'more' => true, 'size' => 'sm', 'hint' => 'The code of the GL account. Blank: the account chosen on the page.'],
        ];
    }

    public static function aliases(): array
    {
        return [
            'member_no' => 'member_code', 'member_number' => 'member_code', 'member_id' => 'member_code', 'name' => 'member_name', 'borrower' => 'member_name',
            'loan_product' => 'loan_name', 'product' => 'loan_name', 'loan_type' => 'loan_name', 'loan' => 'loan_name', 'principal_loan' => 'principal', 'loan_amount' => 'principal', 'amount' => 'principal',
            'terms' => 'term', 'terms_months_days' => 'term', 'term_months_days' => 'term', 'terms_months' => 'term', 'mode_of_payment' => 'mode', 'payment_mode' => 'mode',
            'amortization_type' => 'amort_type', 'amortization' => 'amort_type', 'interest_rate' => 'rate', 'rate_a_year' => 'rate', 'date_released' => 'release_date', 'released' => 'release_date',
            'principal_amount_paid' => 'principal_paid', 'principal_payments' => 'principal_paid', 'interest_payments' => 'interest_paid', 'penalty_paid' => 'penalties_paid', 'penalties_payments' => 'penalties_paid',
            'cutoff_date' => 'cut_off_date', 'cut_off' => 'cut_off_date', 'as_of' => 'cut_off_date', 'past_due_interest_optional' => 'past_due_interest', 'over_due_penalties' => 'overdue_penalties',
            'over_due_penalties_optional' => 'overdue_penalties', 'overdue_penalties_optional' => 'overdue_penalties', 'penalties' => 'overdue_penalties', 'first_due_date' => 'first_due',
            'loan_number' => 'loan_no', 'account_no' => 'loan_no', 'pn_number' => 'pn_no', 'gl_account' => 'balancing_account', 'balancing_gl_account' => 'balancing_account',
        ];
    }

    public static function samples(): array
    {
        $p = array_keys(self::products());
        $code = (string)($p[0] ?? '');
        return [
            ['member_code' => '100201', 'member_name' => 'Dela Cruz, Juan S.', 'loan_name' => $code, 'principal' => '50000.00', 'term' => '12 months', 'mode' => 'Monthly', 'amort_type' => 'Diminishing, equal amortization',
                'rate' => '18', 'release_date' => Coop::addMonths(today(), -7), 'principal_paid' => '20000.00', 'interest_paid' => '3500.00', 'penalties_paid' => '0', 'cut_off_date' => today()],
            ['member_code' => '100202', 'member_name' => 'Reyes, Maria Luisa', 'loan_name' => (string)($p[1] ?? $code), 'principal' => '20000.00', 'term' => '6 months', 'mode' => 'Semi-monthly', 'amort_type' => 'Straight (add-on)',
                'rate' => '1% a month', 'release_date' => Coop::addMonths(today(), -9), 'principal_paid' => '15000.00', 'interest_paid' => '900.00', 'penalties_paid' => '150.00', 'cut_off_date' => today(),
                'past_due_interest' => '', 'overdue_penalties' => '420.00'],
        ];
    }

    /** product code => "code name" of the active loan products */
    public static function products(): array
    {
        return DB::pairs("SELECT code, CONCAT(code, ' ', name) FROM loan_products WHERE is_active = 1 ORDER BY sort_order, code");
    }

    /** account code => "code name" of the active GL accounts */
    public static function accounts(): array
    {
        return DB::pairs("SELECT code, CONCAT(code, ' ', name) FROM accounts WHERE is_active = 1 ORDER BY code");
    }

    /** "12", "12 months", "12M", "180 days", "180D", "1 year" => [n, months|days]; null when it is not a term */
    public static function term(string $v): ?array
    {
        $v = mb_strtolower(trim($v));
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*(m|mo|mos|month|months|d|day|days|y|yr|yrs|year|years)?\.?$/', $v, $m) || (float)$m[1] <= 0) {
            return null;
        }
        $u = $m[2] ?? '';
        if ($u !== '' && $u[0] === 'y') {
            return [(string)((float)$m[1] * 12), 'months'];
        }
        return [rtrim(rtrim(number_format((float)$m[1], 2, '.', ''), '0'), '.'), $u !== '' && $u[0] === 'd' ? 'days' : 'months'];
    }

    /** The mode of payment as typed ("Monthly", "semi monthly", "semi_monthly", "lump sum") */
    public static function mode(string $v): ?string
    {
        $n = fn(string $x) => preg_replace('/[^a-z]/', '', mb_strtolower($x));
        $v = $n($v);
        foreach (Coop::MODES as $k => $m) {
            if ($v === $n($k) || $v === $n($m[0]) || ($k === 'lump_sum' && in_array($v, ['lumpsum', 'lump', 'maturity', 'onetime'], true))
                || ($k === 'semi_monthly' && $v === 'bimonthly') || ($k === 'semi_annual' && $v === 'semiannually') || ($k === 'annual' && in_array($v, ['annually', 'yearly'], true))) {
                return $k;
            }
        }
        return null;
    }

    /** The amortization type as typed: equal amortization (diminishing), equal principal (diminishing) or straight (add-on) */
    public static function amort(string $v): ?string
    {
        $v = preg_replace('/[^a-z]/', '', mb_strtolower($v));
        if ($v === '') {
            return null;
        }
        foreach (['straight' => ['straight', 'addon', 'flat', 'straightaddon'], 'equal_principal' => ['equalprincipal', 'fixedprincipal', 'diminishingequalprincipal', 'diminishingfixedprincipal'],
                  'equal_amortization' => ['equalamortization', 'equalloan', 'annuity', 'diminishing', 'diminishingbalance', 'diminishingequalamortization', 'declining', 'decliningbalance']] as $k => $words) {
            if (in_array($v, $words, true) || $v === preg_replace('/[^a-z]/', '', mb_strtolower(self::AMORT[$k]))) {
                return $k;
            }
        }
        return null;
    }

    /** The rate a year as typed: "18", "18%", "18% a year"; "1.5% a month" is 18 a year. null when it is not a rate */
    public static function rate(string $v): ?string
    {
        $v = mb_strtolower(trim(str_replace(',', '', $v)));
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*%?\s*(.*)$/', $v, $m)) {
            return null;
        }
        $per = trim($m[2]);
        $monthly = (bool)preg_match('/^(a |per |\/ ?)?(month|mo|monthly|mon)\.?$|^pm$/', $per);
        if ($per !== '' && !$monthly && !preg_match('/^(a |per |\/ ?)?(year|yr|annum|annual|annually|pa|p\.a\.)\.?$/', $per)) {
            return null;
        }
        $r = Dec::of($m[1]);
        return Dec::fix($monthly ? Dec::mul($r, '12', 6) : $r, 4);
    }

    /**
     * The loan rebuilt at the cut-off date from the terms ($t: principal, rate, term, unit, mode, method, amort, release,
     * first_due, cutoff, principal_paid, interest_paid, penalties_paid, past_due_interest|null, overdue_penalties|null)
     * and the product $p. Returns the schedule (with what is paid on each amortization), the past due amounts, the
     * past due interest and overdue penalties (given or worked out, with how), the balances, or ['error' => ...].
     */
    public static function rebuild(array $p, array $t): array
    {
        $lump = $t['mode'] === 'lump_sum';
        $start = $t['first_due'] && !$lump ? $t['first_due'] : Coop::nthDue($t['release'], $t['mode'], 1);
        $sched = Loans::schedule($t['principal'], $t['rate'], $t['term'], $t['unit'], $t['mode'], $t['method'], $t['amort'], $start, (int)$p['calendar_days'] ?: 365, (string)($p['diminishing_by'] ?: 'same'));
        $rows = $sched['rows'];
        if ($lump && $t['first_due'] && count($rows) === 1) {
            // a lump sum is due once: a first due date given is its maturity
            $rows[0]['due_date'] = $t['first_due'];
            $sched['due_date'] = $t['first_due'];
        }
        $maturity = $sched['due_date'];
        if (Dec::cmp($t['principal_paid'], $t['principal']) > 0) {
            return ['error' => 'The principal paid (' . Dec::money($t['principal_paid']) . ') is more than the principal loan (' . Dec::money($t['principal']) . ').', 'cells' => ['principal_paid', 'principal']];
        }
        // the totals paid, taken as paid on the due dates in order
        $leftP = $t['principal_paid'];
        $leftI = $t['interest_paid'];
        foreach ($rows as &$r) {
            $r['principal_paid'] = Dec::min($r['principal'], $leftP);
            $leftP = Dec::sub($leftP, $r['principal_paid'], 2);
            $r['interest_paid'] = Dec::min($r['interest'], $leftI);
            $leftI = Dec::sub($leftI, $r['interest_paid'], 2);
        }
        unset($r);
        $excessInterest = $leftI;   // interest paid beyond the schedule: past due interest already paid
        $arrP = '0';
        $arrI = '0';
        $nArr = 0;
        foreach ($rows as $r) {
            if ($r['due_date'] <= $t['cutoff']) {
                $uP = Dec::sub($r['principal'], $r['principal_paid'], 2);
                $uI = Dec::sub($r['interest'], $r['interest_paid'], 2);
                if (Dec::isPos($uP) || Dec::isPos($uI)) {
                    $arrP = Dec::add($arrP, $uP, 2);
                    $arrI = Dec::add($arrI, $uI, 2);
                    $nArr++;
                }
            }
        }
        $principalBal = Dec::sub($t['principal'], $t['principal_paid'], 2);
        $cal = (string)((int)$p['calendar_days'] ?: 365);
        // the past due interest of a matured loan: on the principal unpaid, from the maturity date to the cut-off date
        $pdiHow = '';
        $pdiWorked = '0';
        $addl = Loans::addlInterestReady() ? (string)($p['addl_interest'] ?? 'auto') : 'auto';
        if ($maturity < $t['cutoff'] && Dec::isPos($principalBal) && Dec::isPos($t['rate']) && $addl !== 'none') {
            $days = Coop::daysBetween($maturity, $t['cutoff']);
            $pdiWorked = Dec::round(Dec::div(Dec::mul(Dec::mul($principalBal, $t['rate'], 10), (string)$days, 10), Dec::mul('100', $cal, 4), 10), 2);
            $pdiHow = Dec::money($principalBal) . ' × ' . rtrim(rtrim(Dec::fix($t['rate'], 4), '0'), '.') . ' % × ' . $days . ' days ÷ ' . $cal . ' (matured ' . fdate($maturity) . ')';
        }
        if ($t['past_due_interest'] !== null) {
            $pdi = $t['past_due_interest'];
            $pdiFrom = 'given';
        } else {
            $pdi = Dec::sub($pdiWorked, $excessInterest, 2);
            if (Dec::isNeg($pdi)) {
                $pdi = '0';
            }
            $pdiFrom = $pdiHow !== '' ? 'worked' : 'none';
        }
        if (Dec::isPos($excessInterest) && $t['past_due_interest'] === null && Dec::cmp($excessInterest, $pdiWorked) > 0) {
            return ['error' => 'The interest paid (' . Dec::money($t['interest_paid']) . ') is more than the scheduled interest (' . Dec::money($sched['total_interest']) . ')'
                . (Dec::isPos($pdiWorked) ? ' and the past due interest (' . Dec::money($pdiWorked) . ')' : '') . '. Check the interest paid, or enter the past due interest.', 'cells' => ['interest_paid', 'past_due_interest']];
        }
        // the overdue penalties: by the product's terms on the past-due amortizations, less the penalties already paid
        $penLoan = ['penalty_rate' => $p['penalty_rate'], 'penalty_grace_days' => $p['penalty_grace_days'], 'penalty_base' => $p['penalty_base'], 'calendar_days' => $p['calendar_days'], 'principal_balance' => $principalBal];
        [$penWorked] = Loans::penaltyOn($penLoan, $rows, $t['cutoff'], null);
        if ($t['overdue_penalties'] !== null) {
            $pen = $t['overdue_penalties'];
            $penFrom = 'given';
        } else {
            $pen = Dec::sub($penWorked, $t['penalties_paid'], 2);
            if (Dec::isNeg($pen)) {
                $pen = '0';
            }
            $penFrom = Dec::isPos($penWorked) ? 'worked' : 'none';
        }
        $pdiCharged = Dec::add($pdi, $excessInterest, 2);
        $interestBal = Dec::add(Dec::sub($sched['total_interest'], Dec::min($t['interest_paid'], $sched['total_interest']), 2), $pdi, 2);
        if (!Dec::isPos($principalBal) && !Dec::isPos($interestBal) && !Dec::isPos($pen)) {
            return ['error' => 'The principal, interest and penalties paid cover the whole loan: there is nothing left to import.', 'cells' => ['principal_paid', 'interest_paid', 'penalties_paid']];
        }
        $out = ['rows' => $rows, 'start' => $start, 'maturity' => $maturity, 'total_interest' => $sched['total_interest'], 'installments' => count($rows),
            'arrears_principal' => $arrP, 'arrears_interest' => $arrI, 'arrears_count' => $nArr, 'excess_interest' => $excessInterest,
            'pdi' => $pdi, 'pdi_worked' => $pdiWorked, 'pdi_from' => $pdiFrom, 'pdi_how' => $pdiHow, 'pdi_charged' => $pdiCharged,
            'penalty' => $pen, 'penalty_worked' => $penWorked, 'penalty_from' => $penFrom,
            'principal_balance' => $principalBal, 'interest_balance' => $interestBal, 'penalty_balance' => $pen];
        foreach (['total_interest', 'arrears_principal', 'arrears_interest', 'excess_interest', 'pdi', 'pdi_worked', 'pdi_charged', 'penalty', 'penalty_worked', 'principal_balance', 'interest_balance', 'penalty_balance'] as $k) {
            $out[$k] = Dec::fix($out[$k]);
        }
        return $out;
    }

    /** The loan products, the GL accounts and the receivable accounts, by what a file may write */
    private static function lists(): array
    {
        if (self::$memo && self::$lists) {
            return self::$lists;
        }
        $products = [];
        foreach (DB::all('SELECT * FROM loan_products') as $p) {
            $products[mb_strtolower((string)$p['code'])] = $p;
            $products[mb_strtolower((string)$p['name'])] ??= $p;
            $products[mb_strtolower($p['code'] . ' ' . $p['name'])] = $p;
        }
        $accounts = DB::all('SELECT id, code, name, is_active FROM accounts');
        // the loans receivable (of every product, the defaults, the interest receivable) cannot balance the entry
        $receivables = [];
        foreach (array_merge(DB::column('SELECT receivable_account_id FROM loan_products WHERE receivable_account_id IS NOT NULL'),
                             [Accts::get('loans_receivable'), Accts::get('loan_interest_receivable')]) as $id) {
            if ((int)$id > 0) {
                $receivables[(int)$id] = true;
            }
        }
        $acctBy = [];
        foreach ($accounts as $a) {
            $acctBy[mb_strtolower((string)$a['code'])] = $a;
            $acctBy[mb_strtolower($a['code'] . ' ' . $a['name'])] = $a;
            $acctBy[mb_strtolower((string)$a['name'])] ??= $a;
        }
        $l = [$products, $accounts, $receivables, $acctBy];
        self::$lists = self::$memo ? $l : [];
        return $l;
    }

    /** Checks every row; nothing is saved. $opt: cut_off_date, balancing_account (the page's). Returns [rows, summary, general]. */
    public static function check(array $rows, array $opt): array
    {
        $general = [];
        [$products, $accounts, $receivables, $acctBy] = self::lists();
        if (trim((string)($opt['balancing_account'] ?? '')) === '') {
            $general[] = 'Choose the balancing GL account (the other side of the loans\' journal entry).';
        }
        $seen = [];
        $total = '0';
        foreach ($rows as &$r) {
            foreach (['cut_off_date', 'balancing_account'] as $k) {
                if (trim((string)($r[$k] ?? '')) === '' && trim((string)($opt[$k] ?? '')) !== '') {
                    $r[$k] = (string)$opt[$k];
                }
            }
            $err = [];
            $cells = [];
            $notes = [];
            $info = '';
            // the borrower
            $no = trim((string)$r['member_code']);
            $m = null;
            if ($no === '') {
                $err[] = 'Enter the member code.';
            } elseif (!($m = self::$memberResolver ? (self::$memberResolver)($no) : DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $no]))) {
                $err[] = 'Member code ' . $no . ' is not on file (import the members first).';
            } elseif (!Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
                $err[] = 'Member ' . $no . ' belongs to another branch.';
                $cells[count($err) - 1] = ['member_code'];
                $m = null;
            } else {
                if (trim((string)$r['member_name']) === '') {
                    $err[] = 'Enter the member name.';
                } elseif (!CsvReview::nameMatches((string)$r['member_name'], (string)$m['last_name'], (string)$m['first_name'], (string)$m['full_name'])) {
                    $err[] = 'The name "' . $r['member_name'] . '" is not the name of member ' . $no . ' on file (' . $m['full_name'] . ').';
                    $cells[count($err) - 1] = ['member_name'];
                }
                if ($m['status'] !== 'active') {
                    $err[] = 'Member ' . $no . ' is ' . (Coop::MEMBER_STATUSES[$m['status']] ?? $m['status']) . ': a loan is imported for an active member.';
                    $cells[count($err) - 1] = ['member_code'];
                } elseif ((int)$m['hold_flag'] === 1) {
                    $err[] = 'Member ' . $no . ' is on hold: ' . $m['hold_reason'];
                    $cells[count($err) - 1] = ['member_code'];
                }
            }
            // the loan product
            $p = $products[mb_strtolower(trim((string)$r['loan_name']))] ?? null;
            if (trim((string)$r['loan_name']) === '') {
                $err[] = 'Choose the loan name (the loan product).';
            } elseif (!$p) {
                $err[] = 'Loan "' . $r['loan_name'] . '" is not a loan product.';
                $cells[count($err) - 1] = ['loan_name'];
            } elseif ((int)$p['is_active'] !== 1) {
                $err[] = 'Loan product ' . $p['name'] . ' is not active.';
                $cells[count($err) - 1] = ['loan_name'];
                $p = null;
            } else {
                $r['loan_name'] = (string)$p['code'];
            }
            // the amounts
            $amt = function (string $k, string $what, bool $req) use (&$r, &$err): ?string {
                $v = trim((string)($r[$k] ?? ''));
                if ($v === '') {
                    if ($req) {
                        $err[] = 'Enter the ' . $what . ' (0 when there is none).';
                    }
                    return null;
                }
                $n = CsvReview::amount($v);
                if ($n === null) {
                    $err[] = 'The ' . $what . ' "' . $v . '" is not an amount.';
                    return null;
                }
                if (Dec::isNeg($n)) {
                    $err[] = 'The ' . $what . ' cannot be negative.';
                    return null;
                }
                $r[$k] = Dec::fix($n);
                return Dec::fix($n);
            };
            $principal = $amt('principal', 'principal loan', true);
            if ($principal !== null && !Dec::isPos($principal)) {
                $err[] = 'The principal loan must be more than zero.';
                $principal = null;
            }
            $pPaid = $amt('principal_paid', 'principal paid', true);
            $iPaid = $amt('interest_paid', 'interest paid', true);
            $penPaid = $amt('penalties_paid', 'penalties paid', true);
            $pdiGiven = $amt('past_due_interest', 'past due interest', false);
            $penGiven = $amt('overdue_penalties', 'overdue penalties', false);
            $accrued = $amt('accrued_interest', 'accrued interest', false);
            // the terms
            $term = self::term((string)$r['term']);
            if (trim((string)$r['term']) === '') {
                $err[] = 'Enter the terms (months or days).';
            } elseif (!$term) {
                $err[] = 'The terms "' . $r['term'] . '" are not a term: write months (12 months) or days (180 days).';
            } else {
                $r['term'] = $term[0] . ' ' . $term[1];
            }
            $mode = self::mode((string)$r['mode']);
            if (trim((string)$r['mode']) === '') {
                $err[] = 'Choose the mode of payment.';
            } elseif (!$mode) {
                $err[] = 'Mode of payment "' . $r['mode'] . '" is not one of ' . implode(', ', array_map(fn($x) => $x[0], Coop::MODES)) . '.';
            } else {
                $r['mode'] = $mode;
            }
            $am = trim((string)$r['amort_type']) === '' ? null : self::amort((string)$r['amort_type']);
            if (trim((string)$r['amort_type']) === '') {
                $err[] = 'Choose the amortization type.';
            } elseif (!$am) {
                $err[] = 'Amortization type "' . $r['amort_type'] . '" is not one of ' . implode('; ', self::AMORT) . '.';
            } else {
                $r['amort_type'] = $am;
            }
            $rate = null;
            if (trim((string)$r['rate']) === '') {
                if ($p && $term && $principal !== null) {
                    $rate = Loans::rateFor($p, Loans::termMonths($term[0], $term[1]), $principal, CsvReview::date((string)$r['release_date']) ?: today());
                    $r['rate'] = rtrim(rtrim(Dec::fix($rate, 4), '0'), '.');
                    $notes[] = 'No rate given: the product\'s rate, ' . $r['rate'] . ' % a year.';
                } else {
                    $err[] = 'Enter the interest rate.';
                    $cells[count($err) - 1] = ['rate'];
                }
            } elseif (($rate = self::rate((string)$r['rate'])) === null || Dec::cmp($rate, '100') > 0) {
                $err[] = 'The interest rate "' . $r['rate'] . '" is not a rate (18 or 18% a year; 1.5% a month).';
                $cells[count($err) - 1] = ['rate'];
                $rate = null;
            } else {
                $r['rate'] = rtrim(rtrim(Dec::fix($rate, 4), '0'), '.');
            }
            // the dates
            $date = function (string $k, string $what, bool $req) use (&$r, &$err): ?string {
                $v = trim((string)($r[$k] ?? ''));
                if ($v === '') {
                    if ($req) {
                        $err[] = 'Enter the ' . $what . '.';
                    }
                    return null;
                }
                $d = CsvReview::date($v);
                if (!$d) {
                    $err[] = 'The ' . $what . ' "' . $v . '" is not a date.';
                    return null;
                }
                $r[$k] = $d;
                return $d;
            };
            $release = $date('release_date', 'release date', true);
            $cutoff = $date('cut_off_date', 'cut-off date', true);
            $first = $date('first_due', 'first due date', false);
            if ($cutoff && $cutoff > today()) {
                $err[] = 'The cut-off date cannot be after today.';
            } elseif ($cutoff && ($closing = Settings::closingDate()) && $cutoff <= $closing && !Access::special('closed_period_changes')) {
                $err[] = 'The books are closed through ' . fdate($closing) . ': the cut-off date must be after it.';
            }
            if ($release && $cutoff && $release > $cutoff) {
                $err[] = 'The release date is after the cut-off date.';
            }
            if ($release && $first && $first <= $release) {
                $err[] = 'The first due date must be after the release date.';
            }
            // the balancing account
            $ba = $acctBy[mb_strtolower(trim((string)$r['balancing_account']))] ?? null;
            if (trim((string)$r['balancing_account']) === '') {
                if (trim((string)($opt['balancing_account'] ?? '')) !== '') {
                    $err[] = 'Choose the balancing GL account.';
                }
            } elseif (!$ba || (int)$ba['is_active'] !== 1) {
                $err[] = 'GL account "' . $r['balancing_account'] . '" is not an active account of the chart of accounts.';
                $cells[count($err) - 1] = ['balancing_account'];
                $ba = null;
            } elseif (isset($receivables[(int)$ba['id']])) {
                $err[] = 'GL account ' . $ba['code'] . ' ' . $ba['name'] . ' is a loans receivable account: the balancing account is the other side of the entry (for a migration, normally Opening Balance Equity).';
                $cells[count($err) - 1] = ['balancing_account'];
                $ba = null;
            } else {
                $r['balancing_account'] = (string)$ba['code'];
            }
            // the loan no.
            $loanNo = trim((string)$r['loan_no']);
            if ($loanNo !== '') {
                if (mb_strlen($loanNo) > 30) {
                    $err[] = 'The loan no. is longer than 30 characters.';
                } elseif (DB::value('SELECT id FROM loan_accounts WHERE loan_no = :n', ['n' => $loanNo])) {
                    $err[] = 'Loan no. ' . $loanNo . ' is already on file.';
                } elseif (isset($seen['loan:' . mb_strtolower($loanNo)])) {
                    $err[] = 'Loan no. ' . $loanNo . ' is also on line ' . $seen['loan:' . mb_strtolower($loanNo)] . ' of the file.';
                }
                $seen['loan:' . mb_strtolower($loanNo)] ??= (int)$r['_line'];
            }
            if (mb_strlen((string)$r['pn_no']) > 30) {
                $err[] = 'The PN no. is longer than 30 characters.';
            }
            // the same loan twice: on file already (imported before), or twice in the file
            if ($m && $p && $principal !== null && $release) {
                $dup = DB::value("SELECT loan_no FROM loan_accounts WHERE member_id = :m AND product_id = :p AND release_date = :r AND principal = :a AND status <> 'cancelled' ORDER BY id LIMIT 1",
                    ['m' => $m['id'], 'p' => $p['id'], 'r' => $release, 'a' => $principal]);
                $key = 'same:' . $m['id'] . '|' . $p['id'] . '|' . $release . '|' . $principal;
                if ($dup) {
                    $err[] = 'This loan is already on file as ' . $dup . ' (the same member, loan, release date and principal).';
                    $cells[count($err) - 1] = ['member_code', 'loan_name', 'release_date', 'principal'];
                } elseif (isset($seen[$key])) {
                    $err[] = 'The same loan (member, loan, release date and principal) is also on line ' . $seen[$key] . ' of the file.';
                    $cells[count($err) - 1] = ['member_code', 'loan_name', 'release_date', 'principal'];
                }
                $seen[$key] ??= (int)$r['_line'];
            }
            $res = null;
            if (!$err && $p && $m && $principal !== null && $term && $mode && $am && $rate !== null && $release && $cutoff) {
                $res = self::rebuild($p, ['principal' => $principal, 'rate' => $rate, 'term' => $term[0], 'unit' => $term[1], 'mode' => $mode, 'method' => $am === 'straight' ? 'straight' : 'diminishing',
                    'amort' => $am === 'straight' ? 'equal_amortization' : $am, 'release' => $release, 'first_due' => $first, 'cutoff' => $cutoff, 'principal_paid' => $pPaid, 'interest_paid' => $iPaid,
                    'penalties_paid' => $penPaid, 'past_due_interest' => $pdiGiven, 'overdue_penalties' => $penGiven]);
                if (isset($res['error'])) {
                    $err[] = $res['error'];
                    if (!empty($res['cells'])) {
                        $cells[count($err) - 1] = $res['cells'];
                    }
                    $res = null;
                }
            }
            if ($res) {
                $info = h($m['full_name']) . ' (' . h($m['member_no']) . '): ' . h($p['name']) . ', ' . $res['installments'] . ' amortization(s) to ' . h(fdate($res['maturity']))
                    . '; balance at the cut-off: principal ' . Dec::money($res['principal_balance']) . ', interest ' . Dec::money($res['interest_balance']) . ', penalties ' . Dec::money($res['penalty_balance'])
                    . ($res['arrears_count'] ? '; <strong>past due</strong> ' . $res['arrears_count'] . ' amortization(s), ' . Dec::money(Dec::add($res['arrears_principal'], $res['arrears_interest'], 2)) : '; current');
                if ($res['pdi_from'] === 'worked' && Dec::isPos($res['pdi'])) {
                    $notes[] = 'Past due interest worked out: ' . $res['pdi_how'] . ' = ' . Dec::money($res['pdi_worked']) . (Dec::isPos($res['excess_interest']) ? ', less ' . Dec::money($res['excess_interest']) . ' paid beyond the schedule' : '') . '.';
                } elseif ($res['pdi_from'] === 'given' && Dec::isPos($res['pdi_worked']) && Dec::cmp($res['pdi'], $res['pdi_worked']) !== 0) {
                    $notes[] = 'Past due interest as given (' . Dec::money($res['pdi']) . '); worked out it would be ' . Dec::money($res['pdi_worked']) . '.';
                }
                if ($accrued !== null && Dec::cmp($accrued, $res['interest_balance']) > 0) {
                    $err[] = 'The accrued interest (' . Dec::money($accrued) . ') is more than the interest unpaid at the cut-off date (' . Dec::money($res['interest_balance']) . ').';
                    $cells[count($err) - 1] = ['accrued_interest'];
                } elseif ($accrued !== null && Dec::isPos($accrued)) {
                    $notes[] = 'Accrued interest ' . Dec::money($accrued) . ' entered in Loan Interest Receivable (already income in the former books).';
                }
                if ($res['penalty_from'] === 'worked') {
                    $notes[] = 'Overdue penalties worked out: ' . Dec::money($res['penalty_worked']) . ' on the past-due amortizations' . (Dec::isPos($penPaid) ? ', less ' . Dec::money($penPaid) . ' already paid = ' . Dec::money($res['penalty']) : '') . '.';
                }
                $r['_res'] = $res;
                if ($err) {
                    $res = null;
                }
            }
            if ($m && $p && $res && $p['amount_max'] !== null && Dec::isPos($p['amount_max']) && Dec::cmp($principal, $p['amount_max']) > 0) {
                $notes[] = 'The principal is above the product\'s maximum of ' . Dec::money($p['amount_max']) . ' (imported as it was released).';
            }
            $r['_errors'] = $err;
            $r['_cells'] = $cells;
            $r['_notes'] = $notes;
            $r['_info'] = $info;
            if (!$err && $res) {
                $total = Dec::add($total, $res['principal_balance'], 2);
            }
        }
        unset($r);
        $bad = count(array_filter($rows, fn($x) => $x['_errors']));
        return [$rows, ['rows' => count($rows), 'bad' => $bad, 'total' => Dec::fix($total)], $general];
    }

    /** Checks again and makes every loan Active (all or none). Returns ['count', 'total' (principal balances), 'refs' => loan nos.]. */
    public static function post(array $rows, array $opt, string $fileName, string $pageKey): array
    {
        return DB::tx(function () use ($rows, $opt, $fileName, $pageKey) {
            [$rows, $sum, $general] = self::check($rows, $opt);
            if ($general) {
                fail(implode(' ', $general));
            }
            if (!$rows) {
                fail('There is no row to upload.');
            }
            if ($sum['bad']) {
                fail($sum['bad'] . ' row(s) have a problem: correct them or remove them, then upload.');
            }
            $products = [];
            foreach (DB::all('SELECT * FROM loan_products') as $p) {
                $products[$p['code']] = $p;
            }
            // one journal voucher per cut-off date and balancing account: the receivables debited, the balancing account
            // credited; each line in the class of the member's branch
            $groups = [];
            $members = [];
            foreach ($rows as $i => $r) {
                $groups[$r['cut_off_date'] . '|' . $r['balancing_account']][$i] = $r;
                $members[$i] = DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $r['member_code']]);
            }
            $txnIds = [];
            $sums = [];
            foreach ($groups as $key => $grp) {
                [$cutoff, $acctCode] = explode('|', $key);
                $acctId = (int)DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $acctCode]);
                $gl = [];
                $byClass = [];
                $sumP = '0';
                foreach ($grp as $i => $r) {
                    $p = $products[$r['loan_name']];
                    $m = $members[$i];
                    $classId = Coop::branchClassId($m['branch_id'] ? (int)$m['branch_id'] : Coop::defaultBranchId());
                    $ref = 'Loan ' . ($r['loan_no'] ?: $p['code']) . ' ' . $m['member_no'] . ' (imported)';
                    $acc = Dec::of($r['accrued_interest'] ?? '');
                    foreach ([[(int)($p['receivable_account_id'] ?: Accts::get('loans_receivable')), $r['_res']['principal_balance'], $ref],
                              [Accts::get('loan_interest_receivable'), $acc, 'Accrued interest at the cut-off, ' . $ref]] as [$aid, $amount, $memo]) {
                        if (Dec::isPos($amount)) {
                            $gl[] = ['account_id' => $aid, 'debit' => $amount, 'memo' => $memo, 'member_id' => (int)$m['id'], 'class_id' => $classId];
                            $byClass[(string)$classId] = Dec::add($byClass[(string)$classId] ?? '0', $amount, 2);
                        }
                    }
                    $sumP = Dec::add($sumP, $r['_res']['principal_balance'], 2);
                }
                $txnIds[$key] = null;
                if ($byClass) {
                    foreach ($byClass as $classId => $amount) {
                        $gl[] = ['account_id' => $acctId, 'credit' => $amount, 'memo' => 'Loans imported as of ' . fdate($cutoff), 'class_id' => $classId !== '' ? (int)$classId : null];
                    }
                    $txnIds[$key] = CoopLedger::post('coop_jv', ['txn_date' => $cutoff, 'memo' => 'Loan import as of ' . fdate($cutoff) . ' (balancing ' . $acctCode . ')', 'reason' => 'Import of loan balances'], $gl);
                }
                $sums[$key] = [$sumP, $acctId, $cutoff];
            }
            // the loans with the loan no. of the former system first, so a number drawn for another never takes one of them
            $refs = [];
            $grpRefs = [];
            foreach ([true, false] as $own) {
                foreach ($groups as $key => $grp) {
                    foreach ($grp as $i => $r) {
                        if ((trim((string)$r['loan_no']) !== '') === $own) {
                            $refs[$i] = $grpRefs[$key][$i] = self::make($products[$r['loan_name']], $members[$i], $r, $txnIds[$key], $pageKey);
                        }
                    }
                }
            }
            foreach ($groups as $key => $grp) {
                [$sumP, $acctId, $cutoff] = $sums[$key];
                ksort($grpRefs[$key]);
                DB::insert('coop_imports', ['kind' => 'loans', 'as_of' => $cutoff, 'rows_count' => count($grp), 'total' => Dec::fix($sumP), 'offset_account_id' => $acctId, 'txn_id' => $txnIds[$key],
                    'file_name' => mb_substr($fileName, 0, 190) ?: null, 'summary_json' => json_encode(['source' => 'csv_review', 'loans' => array_values($grpRefs[$key])]), 'created_by' => Auth::id()]);
            }
            ksort($refs);
            Audit::log('import', 'loan', 'csv_loans', null, 'Import Loans: ' . count($rows) . ' loan(s)', ['page_key' => $pageKey, 'amount' => (float)$sum['total'], 'reason' => mb_substr($fileName, 0, 190)]);
            return ['count' => count($rows), 'total' => $sum['total'], 'refs' => array_values($refs)];
        });
    }

    /** One loan: the account, its schedule with what is paid on it, and its ledger before the cut-off. Returns the loan no. */
    private static function make(array $p, array $m, array $r, ?int $txnId, string $pageKey): string
    {
        $res = $r['_res'];
        $branchId = $m['branch_id'] ? (int)$m['branch_id'] : Coop::defaultBranchId();
        [$term, $unit] = explode(' ', $r['term']);
        $am = $r['amort_type'];
        $cutoff = $r['cut_off_date'];
        $loanNo = trim((string)$r['loan_no']) !== '' ? mb_substr(trim((string)$r['loan_no']), 0, 30) : Coop::loanNo($p, $branchId);
        $rate = Dec::fix((string)self::rate((string)$r['rate']), 4);
        $note = 'Imported from the former system as of ' . fdate($cutoff) . ': paid before the cut-off ' . Dec::money($r['principal_paid']) . ' principal, ' . Dec::money($r['interest_paid'])
            . ' interest, ' . Dec::money($r['penalties_paid']) . ' penalties (taken as paid on the due dates)';
        $loanId = DB::insert('loan_accounts', [
            'loan_no' => $loanNo, 'application_id' => null, 'member_id' => $m['id'], 'product_id' => $p['id'], 'branch_id' => $branchId, 'pn_no' => trim((string)$r['pn_no']) ?: null,
            'principal' => Dec::fix($r['principal']), 'rate' => $rate, 'interest_method' => $am === 'straight' ? 'straight' : 'diminishing', 'amort_type' => $am === 'straight' ? 'equal_amortization' : $am,
            'term' => Dec::fix($term), 'term_unit' => $unit, 'mode_of_payment' => $r['mode'], 'calendar_days' => (int)$p['calendar_days'] ?: 365,
            'release_date' => $r['release_date'], 'start_date' => $res['start'], 'due_date' => $res['maturity'], 'release_mode' => 'opening',
            'receivable_account_id' => (int)($p['receivable_account_id'] ?: Accts::get('loans_receivable')), 'interest_account_id' => (int)($p['interest_account_id'] ?: Accts::get('loan_interest_income')),
            'penalty_account_id' => $p['penalty_account_id'] ?: Accts::get('loan_penalty_income'), 'penalty_rate' => $p['penalty_rate'], 'penalty_grace_days' => (int)$p['penalty_grace_days'], 'penalty_base' => $p['penalty_base'],
            'total_interest' => Dec::fix(Dec::add($res['total_interest'], $res['pdi_charged'], 2)), 'net_proceeds' => Dec::fix($r['principal']), 'status' => 'current',
            'principal_balance' => Dec::fix($res['principal_balance']), 'interest_balance' => Dec::fix($res['interest_balance']), 'penalty_balance' => Dec::fix($res['penalty_balance']),
            // penalties up to the cut-off date are counted in the import; new ones run from the day after
            'last_penalty_date' => $cutoff, 'release_txn_id' => $txnId, 'notes' => mb_substr($note, 0, 255), 'created_by' => Auth::id(),
        ]);
        // the schedule in date order: the past due interest is an amortization due at the cut-off date, before those due
        // after it (the collection and the dues take the amortizations by their number)
        $order = [];
        foreach ($res['rows'] as $row) {
            $order[] = [$row['due_date'], 0, $row['inst_no'], $row];
        }
        if (Dec::isPos($res['pdi_charged'])) {
            $order[] = [$cutoff, 1, 0, ['inst_no' => 0, 'due_date' => $cutoff, 'principal' => '0', 'interest' => $res['pdi_charged'], 'total' => $res['pdi_charged'], 'balance_after' => '0']];
        }
        usort($order, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $sid = [];    // by the amortization no. of the rebuilt schedule (0: the past due interest)
        $no = [];     // its no. on the loan
        foreach ($order as $k => [, , $orig, $row]) {
            $no[$orig] = $k + 1;
            $sid[$orig] = DB::insert('loan_schedule', ['loan_id' => $loanId, 'inst_no' => $k + 1, 'due_date' => $row['due_date'], 'principal' => Dec::fix($row['principal']),
                'interest' => Dec::fix($row['interest']), 'total' => Dec::fix($row['total']), 'principal_paid' => '0', 'interest_paid' => '0', 'balance_after' => Dec::fix($row['balance_after']), 'is_paid' => 0]);
        }
        // the ledger: the release, the payments on the due dates (as presumed), the penalties and past due interest at the cut-off
        $bal = ['p' => '0', 'i' => '0', 'pn' => '0'];
        $txn = function (string $date, string $kind, string $memo, array $amounts, array $alloc = []) use ($loanId, $txnId, &$bal) {
            $a = $amounts + ['principal_dr' => '0', 'interest_dr' => '0', 'penalty_dr' => '0', 'principal_cr' => '0', 'interest_cr' => '0', 'penalty_cr' => '0'];
            $bal['p'] = Dec::sub(Dec::add($bal['p'], $a['principal_dr'], 2), $a['principal_cr'], 2);
            $bal['i'] = Dec::sub(Dec::add($bal['i'], $a['interest_dr'], 2), $a['interest_cr'], 2);
            $bal['pn'] = Dec::sub(Dec::add($bal['pn'], $a['penalty_dr'], 2), $a['penalty_cr'], 2);
            $id = DB::insert('loan_txns', ['loan_id' => $loanId, 'txn_date' => $date, 'kind' => $kind, 'memo' => mb_substr($memo, 0, 160)] + array_map(fn($v) => Dec::fix($v), $a)
                + ['principal_bal' => Dec::fix($bal['p']), 'interest_bal' => Dec::fix($bal['i']), 'penalty_bal' => Dec::fix($bal['pn']),
                    // the release (and an accrual before the cut-off) carries the import voucher; the payments of the former
                    // system have none (no cash moves now)
                    'txn_id' => in_array($kind, ['release', 'accrual'], true) ? $txnId : null, 'pb_printed' => 1, 'created_by' => Auth::id()]);
            foreach ($alloc as [$schedId, $pp, $ip, $date2]) {
                DB::insert('loan_payment_alloc', ['loan_txn_id' => $id, 'schedule_id' => $schedId, 'principal' => Dec::fix($pp), 'interest' => Dec::fix($ip)]);
                DB::run('UPDATE loan_schedule SET principal_paid = principal_paid + :p, interest_paid = interest_paid + :i,
                                is_paid = IF(principal_paid >= principal AND interest_paid >= interest, 1, 0), paid_on = IF(principal_paid >= principal AND interest_paid >= interest, :d, NULL)
                          WHERE id = :id', ['p' => Dec::fix($pp), 'i' => Dec::fix($ip), 'd' => $date2, 'id' => $schedId]);
            }
            return $id;
        };
        $txn($r['release_date'], 'release', 'Former system: release', ['principal_dr' => $r['principal'], 'interest_dr' => $res['total_interest']]);
        // on or before the cut-off date: each amortization's payment on its due date; amortizations due later paid in advance at the cut-off date
        $atCut = [];
        foreach ($res['rows'] as $row) {
            if (!Dec::isPos($row['principal_paid']) && !Dec::isPos($row['interest_paid'])) {
                continue;
            }
            if ($row['due_date'] <= $cutoff) {
                $txn($row['due_date'], 'payment', 'Former system: amortization ' . $no[$row['inst_no']] . ' paid', ['principal_cr' => $row['principal_paid'], 'interest_cr' => $row['interest_paid']],
                    [[$sid[$row['inst_no']], $row['principal_paid'], $row['interest_paid'], $row['due_date']]]);
            } else {
                $atCut[] = $row;
            }
        }
        if (Dec::isPos($res['pdi_charged'])) {
            $txn($cutoff, 'pastdue', 'Past due interest to ' . fdate($cutoff) . ($res['pdi_from'] === 'given' ? ' (as given)' : ''), ['interest_dr' => $res['pdi_charged']]);
        }
        $penCharged = Dec::add($r['penalties_paid'], $res['penalty'], 2);
        if (Dec::isPos($penCharged)) {
            $txn($cutoff, 'penalty', 'Former system: penalties to ' . fdate($cutoff), ['penalty_dr' => $penCharged]);
        }
        $advP = '0';
        $advI = '0';
        $alloc = [];
        foreach ($atCut as $row) {
            $advP = Dec::add($advP, $row['principal_paid'], 2);
            $advI = Dec::add($advI, $row['interest_paid'], 2);
            $alloc[] = [$sid[$row['inst_no']], $row['principal_paid'], $row['interest_paid'], $cutoff];
        }
        if (Dec::isPos($res['excess_interest'])) {
            $advI = Dec::add($advI, $res['excess_interest'], 2);
            $alloc[] = [$sid[0], '0', $res['excess_interest'], $cutoff];
        }
        if (Dec::isPos($advP) || Dec::isPos($advI) || Dec::isPos($r['penalties_paid'])) {
            $txn($cutoff, 'payment', 'Former system: paid to the cut-off date', ['principal_cr' => $advP, 'interest_cr' => $advI, 'penalty_cr' => $r['penalties_paid']], $alloc);
        }
        $acc = Dec::of($r['accrued_interest'] ?? '');
        if (Dec::isPos($acc)) {
            // already income in the former books: it stands in the interest receivable until collected (the accrual run counts it)
            $txn($cutoff, 'accrual', 'Interest accrued before the cut-off (former system)', ['interest_dr' => $acc, 'interest_cr' => $acc]);
        }
        // the status as of today (amortizations due since the cut-off date count)
        Loans::refreshBalances($loanId, max($cutoff, today()));
        $loan = Loans::loan($loanId);
        if (Dec::cmp($loan['principal_balance'], $res['principal_balance']) !== 0 || Dec::cmp($loan['interest_balance'], $res['interest_balance']) !== 0 || Dec::cmp($loan['penalty_balance'], $res['penalty_balance']) !== 0) {
            fail('Loan ' . $loanNo . ': the ledger does not give the balances worked out. Nothing was saved.');
        }
        Audit::log('import', 'loan', 'loan_account', $loanId, $loanNo . ' ' . $m['full_name'], ['page_key' => $pageKey, 'amount' => (float)$res['principal_balance'], 'reason' => mb_substr($note, 0, 190)]);
        return $loanNo;
    }
}
