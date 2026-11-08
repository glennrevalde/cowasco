<?php
declare(strict_types=1);

/**
 * Members & Others > Consolidated Import: one file for the members of a cooperative with everything they have, the
 * four imports of the program in one (Import Members, Import Savings, Import Loans, Upload Bill Balances).
 *
 * The file has no fixed format. Its first row names the columns; each column is recognised by its name (the names of
 * the four imports and the usual other names) and the user may say what any column holds before the lines are made
 * (the choice is remembered for the next file). Only the columns of what is imported are needed. A member may have
 * any number of savings accounts, loans and bill balances: on one row (savings2_code, loan2_principal ... for the
 * second one) or on the rows under the member's row (a row with no member name continues the member above; a row with
 * the same member no., or the same name and date of birth, is the same member). The water-service account of a
 * member is named by its account no. (the member becomes the same person as its holder) or opened from a rate code and
 * a pipe size; a fixed share capital billing is set up from its amount.
 *
 * The file is read into the database (coop_import_raw), not kept in the browser: a file of thousands of rows is read in
 * pieces and checked in steps. Each member, savings account, loan and bill balance is a line of the review
 * (coop_import_lines) with its problems, checked by the checks of the four imports (the member's accounts as of a
 * member not yet on file); a value is corrected in the review or a line removed (a member with its lines), and every
 * line is checked again before anything is saved. Saving makes the members, then their water-service accounts,
 * savings, fixed share capital billings, loans and bill balances, with the postings of the four imports, a few
 * members at a time (each group in one transaction), so thousands of members are saved without the server timing out;
 * an interrupted saving goes on where it stopped.
 */
final class ConsolidatedImport
{
    public const PAGE = 'coop.consolidated_import';

    /** The parts of a member's row */
    public const SECTIONS = [
        'member' => 'Member', 'water' => 'Water service', 'fsc' => 'Fixed share capital billing',
        'savings' => 'Savings', 'loan' => 'Loans', 'bill' => 'Bill balances',
    ];

    /** The lines of the review: kind => [title, one, many] */
    public const KINDS = [
        'member' => ['Members', 'member', 'members'],
        'savings' => ['Savings', 'savings account', 'savings accounts'],
        'loan' => ['Loans', 'loan', 'loans'],
        'bill' => ['Bill balances', 'bill balance', 'bill balances'],
    ];

    /** The sections whose columns repeat on a row (savings2_code ...), and the line kind they make */
    public const REPEAT = ['savings' => 'savings', 'loan' => 'loan', 'bill' => 'bill'];

    /** The largest file and the most rows read */
    public const MAX_FILE_BYTES = 150 * 1024 * 1024;
    public const MAX_ROWS = 60000;

    /** The pieces a file is sent in (under any upload_max_filesize / post_max_size of the server) */
    public const CHUNK_BYTES = 1024 * 1024;

    /** The members saved in one transaction, and the seconds a check or a save step takes at most */
    public const SAVE_MEMBERS = 40;
    public const STEP_SECONDS = 6.0;

    /** The lines shown on a page of the review */
    public const PAGE_LINES = 50;

    /** Consolidated field => the column of the import that checks and saves it */
    private const SAVINGS_MAP = ['savings_code' => 'savings_code', 'savings_balance' => 'amount', 'savings_account_no' => 'account_no', 'savings_passbook_no' => 'passbook_no',
        'savings_opened_on' => 'opened_on', 'savings_cut_off_date' => 'cut_off_date', 'savings_txn_code' => 'savings_txn_code'];
    private const LOAN_MAP = ['loan_name' => 'loan_name', 'loan_principal' => 'principal', 'loan_term' => 'term', 'loan_mode' => 'mode', 'loan_amort_type' => 'amort_type',
        'loan_rate' => 'rate', 'loan_release_date' => 'release_date', 'loan_principal_paid' => 'principal_paid', 'loan_interest_paid' => 'interest_paid',
        'loan_penalties_paid' => 'penalties_paid', 'loan_cut_off_date' => 'cut_off_date', 'loan_past_due_interest' => 'past_due_interest',
        'loan_overdue_penalties' => 'overdue_penalties', 'loan_first_due' => 'first_due', 'loan_no' => 'loan_no', 'loan_pn_no' => 'pn_no',
        'loan_accrued_interest' => 'accrued_interest', 'loan_balancing_account' => 'balancing_account'];
    private const BILL_MAP = ['bill_type' => 'billing_type', 'bill_charge_code' => 'charge_code', 'bill_balance' => 'balance', 'bill_date' => 'bill_date',
        'bill_due_date' => 'due_date', 'bill_installments' => 'installments', 'bill_payment_mode' => 'payment_mode', 'bill_share_capital_account' => 'share_capital_account'];

    /** The balance columns of one row per member (Upload Bill Balances): billing type => [balance, charge category] */
    private const WIDE_BILLS = [
        'recurring' => ['recurring_balance', 'recurring_code'], 'installment' => ['installment_balance', 'installment_code'],
        'one_time' => ['one_time_balance', 'one_time_code'], 'share_capital' => ['share_capital_balance', null],
    ];

    private static ?array $fields = null;

    // ================================================================== the columns a file may have

    /**
     * Every column the import knows: key => label, section, type, size, hint, req (needed for that part), more (shown
     * under the line rather than in the grid), options (a list), file (only in the file: it makes bill lines)
     */
    public static function fields(): array
    {
        if (self::$fields !== null) {
            return self::$fields;
        }
        self::loadWater();
        $f = [];
        foreach (CsvImports::cols('members') as $k => $c) {
            $f[$k] = ['section' => 'member'] + $c;
        }
        // a file with the whole name in one column: split into the last, first and middle name
        $f['member_name'] = ['section' => 'member', 'file' => true, 'label' => 'Member name (whole name)', 'hint' => '"Dela Cruz, Juan S." or "Juan S. Dela Cruz": split into the last, first and middle name when those columns are not in the file.'];
        $rateCodes = [];
        $pipes = [];
        $billCodes = [];
        try {
            foreach (DB::all('SELECT rc_id, rc_code, rc_name FROM rate_code ORDER BY rc_code, rc_name') as $r) {
                $rateCodes[(string)$r['rc_id']] = trim($r['rc_code'] . ' ' . $r['rc_name']);
            }
            foreach (DB::all('SELECT pipe_size_id, size_code, size_label FROM water_pipe_sizes WHERE is_active = 1 ORDER BY pipe_size_id') as $r) {
                $pipes[(string)$r['size_code']] = (string)$r['size_label'];
            }
            foreach (DB::all('SELECT code FROM bill_codes ORDER BY code') as $r) {
                $billCodes[(string)$r['code']] = (string)$r['code'];
            }
        } catch (Throwable $e) {
            // the water module is not installed
        }
        $f += [
            'water_account_no' => ['section' => 'water', 'label' => 'Water account no.', 'size' => 'sm', 'hint' => 'The member\'s water-service account (Water Service Accounts): the member becomes the same person as its holder, and the member\'s bill balances go to it.'],
            'water_rate_code' => ['section' => 'water', 'label' => 'Water rate code', 'type' => 'select', 'options' => $rateCodes, 'size' => 'md', 'more' => true, 'hint' => 'To open a water-service account for the member: the rate code / classification (Rate Codes), with the pipe size.'],
            'water_pipe_size' => ['section' => 'water', 'label' => 'Water pipe size', 'type' => 'select', 'options' => $pipes, 'size' => 'sm', 'more' => true, 'hint' => 'The pipe size of the water-service account to open (1/2, 3/4 ...).'],
            'water_zone' => ['section' => 'water', 'label' => 'Water zone', 'type' => 'num', 'size' => 'xs', 'more' => true, 'hint' => 'The zone of the account to open (a number).'],
            'water_sequence' => ['section' => 'water', 'label' => 'Meter sequence', 'size' => 'sm', 'more' => true, 'hint' => 'The reading sequence of the account to open.'],
            'water_connection_date' => ['section' => 'water', 'label' => 'Connection date', 'type' => 'date', 'size' => 'sm', 'more' => true, 'hint' => 'The connection date of the account to open. Blank: today.'],
            'fsc_amount' => ['section' => 'fsc', 'label' => 'Fixed share capital amount', 'type' => 'money', 'size' => 'sm', 'more' => true, 'hint' => 'Puts the member on Fixed Share Capital Billing for this amount (the member\'s share capital account of the file). Needed for a fixed share capital bill balance of a new member.'],
            'fsc_frequency' => ['section' => 'fsc', 'label' => 'Fixed share capital frequency', 'type' => 'select', 'options' => array_map(fn($x) => $x[0], ShareCapitalBilling::FREQUENCIES), 'size' => 'sm', 'more' => true, 'hint' => 'Monthly, Quarterly, Semi-annually or Annually. Blank: Monthly.'],
            'fsc_posting_day' => ['section' => 'fsc', 'label' => 'Fixed share capital posting day', 'type' => 'num', 'size' => 'xs', 'more' => true, 'hint' => 'The day of the month a bill is posted (1 to 31). Blank: 1.'],
            'fsc_start_date' => ['section' => 'fsc', 'label' => 'Fixed share capital start date', 'type' => 'date', 'size' => 'sm', 'more' => true, 'hint' => 'Blank: the day after the cut-off date.'],
            'fsc_term_days' => ['section' => 'fsc', 'label' => 'Fixed share capital term (days)', 'type' => 'num', 'size' => 'xs', 'more' => true, 'hint' => 'Days from a bill to its due date. Blank: no due date.'],
        ];
        $sav = CsvImports::cols('savings');
        foreach (self::SAVINGS_MAP as $k => $t) {
            $c = $sav[$t];
            unset($c['filled']);
            $label = $t === 'amount' ? 'Savings balance' : ($t === 'account_no' ? 'Savings account no.' : ($t === 'passbook_no' ? 'Savings passbook no.' : ($t === 'opened_on' ? 'Savings opened on' : $c['label'])));
            $f[$k] = ['section' => 'savings', 'label' => $label, 'req' => in_array($t, ['savings_code', 'amount'], true)] + $c;
            $f[$k]['more'] = in_array($t, ['passbook_no', 'opened_on', 'cut_off_date', 'savings_txn_code'], true);
            if ($t === 'cut_off_date' || $t === 'savings_txn_code') {
                $f[$k]['hint'] = $t === 'cut_off_date' ? 'The date of the balance. Blank: the cut-off date of the page.' : 'A deposit code with a GL account. Blank: the code chosen on the page.';
            }
            if ($t === 'amount') {
                $f[$k]['hint'] = 'The balance of the account at the cut-off date (0 opens the account without a balance).';
            }
        }
        $loan = LoanImport::cols();
        foreach (self::LOAN_MAP as $k => $t) {
            $c = $loan[$t];
            unset($c['filled']);
            $req = !empty($c['req']) && !in_array($t, ['cut_off_date', 'rate'], true);
            $label = in_array($t, ['principal', 'term', 'mode', 'rate', 'release_date', 'first_due', 'past_due_interest', 'overdue_penalties', 'accrued_interest', 'balancing_account', 'cut_off_date'], true)
                ? 'Loan ' . lcfirst((string)$c['label']) : (string)$c['label'];
            $f[$k] = ['section' => 'loan', 'label' => $label] + $c;
            $f[$k]['req'] = $req;
            if ($t === 'cut_off_date') {
                $f[$k]['hint'] = 'The date of the totals paid. Blank: the cut-off date of the page.';
                $f[$k]['more'] = true;
            }
            if ($t === 'balancing_account') {
                $f[$k]['hint'] = 'The code of the GL account. Blank: the account chosen on the page.';
            }
        }
        $types = [];
        foreach (BBU_TYPES as [$label]) {
            $types[$label] = $label;
        }
        $f += [
            'bill_type' => ['section' => 'bill', 'label' => 'Billing type', 'type' => 'select', 'options' => $types, 'req' => true, 'size' => 'lg', 'hint' => 'Recurring, Installment, One-Time or Fixed Share Capital.'],
            'bill_charge_code' => ['section' => 'bill', 'label' => 'Charge category', 'type' => 'select', 'options' => $billCodes, 'size' => 'md', 'hint' => 'The CODE NAME of the bill code (a recurring, installment or one-time balance).'],
            'bill_balance' => ['section' => 'bill', 'label' => 'Bill balance', 'type' => 'money', 'req' => true, 'size' => 'sm', 'hint' => 'The amount still unpaid.'],
            'bill_date' => ['section' => 'bill', 'label' => 'Bill date', 'type' => 'date', 'size' => 'sm', 'hint' => 'Blank: the cut-off date of the page.'],
            'bill_due_date' => ['section' => 'bill', 'label' => 'Bill due date', 'type' => 'date', 'size' => 'sm', 'hint' => 'An installment balance: the due date of the first installment left. Blank: the bill date.'],
            'bill_installments' => ['section' => 'bill', 'label' => 'Installments left', 'type' => 'num', 'size' => 'xs', 'more' => true, 'hint' => 'An installment balance: in how many installments it is paid. Blank: 1.'],
            'bill_payment_mode' => ['section' => 'bill', 'label' => 'Installment payment mode', 'type' => 'select', 'options' => ['Monthly' => 'Monthly', 'Quarterly' => 'Quarterly', 'Semi-annually' => 'Semi-annually', 'Annually' => 'Annually'], 'size' => 'sm', 'more' => true, 'hint' => 'Blank: Monthly.'],
            'bill_share_capital_account' => ['section' => 'bill', 'label' => 'Share capital account', 'size' => 'sm', 'more' => true, 'hint' => 'A fixed share capital balance of a member with two accounts on fixed billing.'],
            // one row per member, as Upload Bill Balances reads it: each balance column becomes a bill line
            'recurring_balance' => ['section' => 'bill', 'file' => true, 'label' => 'Recurring balance', 'type' => 'money', 'hint' => 'A recurring bill balance (with recurring_code).'],
            'recurring_code' => ['section' => 'bill', 'file' => true, 'label' => 'Recurring charge category', 'hint' => 'The code name of the bill code of the recurring plan.'],
            'installment_balance' => ['section' => 'bill', 'file' => true, 'label' => 'Installment balance', 'type' => 'money', 'hint' => 'An installment balance (with installment_code, installments, payment_mode).'],
            'installment_code' => ['section' => 'bill', 'file' => true, 'label' => 'Installment charge category', 'hint' => 'The code name of the bill code of the installment.'],
            'installments' => ['section' => 'bill', 'file' => true, 'label' => 'Installments left (installment balance)', 'type' => 'num'],
            'payment_mode' => ['section' => 'bill', 'file' => true, 'label' => 'Payment mode (installment balance)'],
            'one_time_balance' => ['section' => 'bill', 'file' => true, 'label' => 'One-time balance', 'type' => 'money', 'hint' => 'A one-time bill balance (with one_time_code).'],
            'one_time_code' => ['section' => 'bill', 'file' => true, 'label' => 'One-time charge category', 'hint' => 'The code name of the bill code of the charge.'],
            'share_capital_balance' => ['section' => 'bill', 'file' => true, 'label' => 'Fixed share capital balance', 'type' => 'money', 'hint' => 'The unpaid fixed share capital billed to the member.'],
        ];
        foreach ($f as $k => &$c) {
            $c['type'] ??= 'text';
            $c['size'] ??= 'md';
            $c['req'] = !empty($c['req']);
            $c['more'] = !empty($c['more']);
            $c['rep'] = isset(self::REPEAT[$c['section']]) && empty($c['file']);
        }
        unset($c);
        return self::$fields = $f;
    }

    /** Forget the lists of the columns (a bill code or a product added since) */
    public static function reset(): void
    {
        self::$fields = null;
        self::$memo = [];
    }

    /** The water code the bill balances use (Upload Bill Balances); the request keeps the strict SQL mode of the accounting pages */
    public static function loadWater(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $relaxed = DB::isRelaxed();
        MemberDefaults::loadWaterHelpers();
        require_once APP_ROOT . '/water/common/importReviewHelpers.php';
        require_once APP_ROOT . '/water/common/billBalanceUploadHelpers.php';
        if (!$relaxed) {
            DB::restoreStrict();
        }
    }

    /** Lists read once a request: the member types, branches, savings products */
    private static array $memo = [];

    private static function memo(string $key, callable $fn)
    {
        return self::$memo[$key] ??= $fn();
    }

    /**
     * A whole name in one column ("Dela Cruz, Juan S.", "Juan S. Dela Cruz") as the last, first and middle name, when
     * the file has no columns for them. Returns the values and whether the name was split.
     */
    public static function splitName(array $mv): array
    {
        $whole = trim((string)($mv['member_name'] ?? ''));
        unset($mv['member_name']);
        if ($whole === '' || trim((string)($mv['last_name'] ?? '')) !== '' || trim((string)($mv['first_name'] ?? '')) !== '') {
            return [$mv, false];
        }
        $whole = (string)preg_replace('/\s+/u', ' ', $whole);
        $initial = fn(string $w) => (bool)preg_match('/^\p{L}\.?$|^\p{L}{1,2}\.$/u', $w);
        if (str_contains($whole, ',')) {
            [$last, $rest] = array_map('trim', explode(',', $whole, 2));
            $words = $rest === '' ? [] : explode(' ', $rest);
        } else {
            $words = explode(' ', $whole);
            $last = count($words) > 1 ? (string)array_pop($words) : '';
            // "Juan S. Dela Cruz": the words after the middle initial are the last name
            foreach ($words as $i => $w) {
                if ($i > 0 && $initial($w)) {
                    $last = trim(implode(' ', array_slice($words, $i + 1)) . ' ' . $last);
                    $words = array_slice($words, 0, $i + 1);
                    break;
                }
            }
        }
        $middle = '';
        if (count($words) > 1 && $initial((string)end($words))) {
            $middle = (string)array_pop($words);
        }
        $mv['last_name'] = $last;
        $mv['first_name'] = implode(' ', $words);
        if ($middle !== '' && trim((string)($mv['middle_name'] ?? '')) === '') {
            $mv['middle_name'] = $middle;
        }
        return [array_filter($mv, fn($v) => $v !== ''), true];
    }

    /** The other names of the columns (as the four imports and people write them) */
    public static function aliases(): array
    {
        $fields = self::fields();
        $a = [];
        foreach (CsvImports::aliases('members') as $k => $t) {
            if (isset($fields[$t]) && $fields[$t]['section'] === 'member') {
                $a[$k] = $t;
            }
        }
        $a += [
            'name' => 'member_name', 'full_name' => 'member_name', 'fullname' => 'member_name', 'name_of_member' => 'member_name', 'members_name' => 'member_name',
            'member_code' => 'member_no', 'member_number' => 'member_no', 'member_id' => 'member_no', 'membership_no' => 'member_no', 'type_of_member' => 'member_type',
            'water_account' => 'water_account_no', 'water_account_number' => 'water_account_no', 'water_acct' => 'water_account_no', 'water_acct_no' => 'water_account_no',
            'concessionaire_no' => 'water_account_no', 'rate_code' => 'water_rate_code', 'classification' => 'water_rate_code', 'pipe_size' => 'water_pipe_size', 'zone' => 'water_zone',
            'meter_sequence' => 'water_sequence', 'reading_sequence' => 'water_sequence', 'connection_date' => 'water_connection_date', 'date_connected' => 'water_connection_date',
            'fixed_share_capital_amount' => 'fsc_amount', 'fsc' => 'fsc_amount', 'share_capital_fixed_amount' => 'fsc_amount', 'fixed_amount' => 'fsc_amount',
            'fixed_share_capital_frequency' => 'fsc_frequency', 'fsc_freq' => 'fsc_frequency', 'posting_day' => 'fsc_posting_day', 'fixed_share_capital_posting_day' => 'fsc_posting_day',
            'fixed_share_capital_start_date' => 'fsc_start_date', 'fsc_start' => 'fsc_start_date', 'fixed_share_capital_term' => 'fsc_term_days', 'fsc_term' => 'fsc_term_days',
            'savings_product' => 'savings_code', 'savings_type' => 'savings_code', 'savings' => 'savings_code', 'saving_code' => 'savings_code', 'sav_code' => 'savings_code',
            'savings_amount' => 'savings_balance', 'saving_balance' => 'savings_balance', 'savings_bal' => 'savings_balance', 'sav_balance' => 'savings_balance', 'sav_amount' => 'savings_balance',
            'savings_account' => 'savings_account_no', 'savings_account_number' => 'savings_account_no', 'savings_acct_no' => 'savings_account_no', 'sav_account_no' => 'savings_account_no',
            'savings_passbook' => 'savings_passbook_no', 'savings_opened' => 'savings_opened_on', 'savings_date_opened' => 'savings_opened_on', 'savings_cutoff_date' => 'savings_cut_off_date',
            'savings_transaction_code' => 'savings_txn_code', 'transaction_code' => 'savings_txn_code', 'txn_code' => 'savings_txn_code',
            'loan' => 'loan_name', 'loan_product' => 'loan_name', 'loan_type' => 'loan_name', 'loan_code' => 'loan_name', 'loan_amount' => 'loan_principal', 'principal_loan' => 'loan_principal',
            'principal' => 'loan_principal', 'loan_principal_amount' => 'loan_principal', 'term' => 'loan_term', 'terms' => 'loan_term', 'loan_terms' => 'loan_term',
            'terms_months_days' => 'loan_term', 'mode' => 'loan_mode', 'mode_of_payment' => 'loan_mode', 'loan_mode_of_payment' => 'loan_mode', 'amort_type' => 'loan_amort_type',
            'amortization_type' => 'loan_amort_type', 'loan_amortization_type' => 'loan_amort_type', 'rate' => 'loan_rate', 'interest_rate' => 'loan_rate', 'loan_interest_rate' => 'loan_rate',
            'release_date' => 'loan_release_date', 'date_released' => 'loan_release_date', 'loan_date_released' => 'loan_release_date', 'principal_paid' => 'loan_principal_paid',
            'interest_paid' => 'loan_interest_paid', 'penalties_paid' => 'loan_penalties_paid', 'penalty_paid' => 'loan_penalties_paid', 'loan_penalty_paid' => 'loan_penalties_paid',
            'loan_cutoff_date' => 'loan_cut_off_date', 'past_due_interest' => 'loan_past_due_interest', 'overdue_penalties' => 'loan_overdue_penalties', 'first_due' => 'loan_first_due',
            'first_due_date' => 'loan_first_due', 'loan_first_due_date' => 'loan_first_due', 'loan_number' => 'loan_no', 'loan_account_no' => 'loan_no', 'pn_no' => 'loan_pn_no', 'pn_number' => 'loan_pn_no',
            'accrued_interest' => 'loan_accrued_interest', 'balancing_account' => 'loan_balancing_account', 'balancing_gl_account' => 'loan_balancing_account',
            'billing_type' => 'bill_type', 'bill_billing_type' => 'bill_type', 'balance_type' => 'bill_type', 'charge_category' => 'bill_charge_code', 'charge_code' => 'bill_charge_code',
            'bill_code' => 'bill_charge_code', 'bill_charge_category' => 'bill_charge_code', 'bill_amount' => 'bill_balance', 'bill_unpaid' => 'bill_balance', 'unpaid_balance' => 'bill_balance',
            'bill_balance_amount' => 'bill_balance', 'bill_due' => 'bill_due_date', 'due_date' => 'bill_due_date', 'bill_installments_left' => 'bill_installments',
            'installments_left' => 'bill_installments', 'bill_mode' => 'bill_payment_mode', 'bill_share_capital' => 'bill_share_capital_account',
            'recurring' => 'recurring_balance', 'recurring_billing' => 'recurring_balance', 'recurring_amount' => 'recurring_balance', 'recurring_charge' => 'recurring_code',
            'recurring_charge_category' => 'recurring_code', 'installment' => 'installment_balance', 'installment_billing' => 'installment_balance', 'installment_amount' => 'installment_balance',
            'installment_charge_category' => 'installment_code', 'one_time' => 'one_time_balance', 'onetime' => 'one_time_balance', 'one_time_billing' => 'one_time_balance',
            'one_time_amount' => 'one_time_balance', 'one_time_charge_category' => 'one_time_code', 'onetime_code' => 'one_time_code', 'fixed_share_capital' => 'share_capital_balance',
            'fixed_share_capital_balance' => 'share_capital_balance', 'fixed_share_capital_billing' => 'share_capital_balance',
        ];
        return $a;
    }

    /** The column names chosen on this database for the names a file had (remembered from the column choices) */
    public static function learned(): array
    {
        $v = json_decode((string)Settings::get('consolidated_import_columns'), true);
        return is_array($v) ? $v : [];
    }

    /** What a column of the file holds, from its name: [field, slot] (slot 1 for the first savings account ...), null when not known */
    public static function guess(string $header): ?array
    {
        $fields = self::fields();
        $aliases = self::aliases();
        $k = CsvReview::headerKey($header);
        if ($k === '') {
            return null;
        }
        $learned = self::learned();
        if (isset($learned[$k])) {
            [$f, $slot] = array_pad((array)$learned[$k], 2, 1);
            return $f === '' ? null : (isset($fields[$f]) ? [$f, max(1, (int)$slot)] : null);
        }
        $one = fn(string $x) => isset($fields[$x]) ? $x : ($aliases[$x] ?? null);
        if ($f = $one($k)) {
            return [$f, 1];
        }
        // a repeated column: savings_code_2, savings_balance2, savings2_code, loan_2_principal, bill3_balance
        $tries = [];
        if (preg_match('/^(.*?)_?(\d{1,2})$/', $k, $m) && $m[1] !== '') {
            $tries[] = [rtrim($m[1], '_'), (int)$m[2]];
        }
        if (preg_match('/^(savings|saving|sav|loans?|bills?)_?(\d{1,2})_(.+)$/', $k, $m)) {
            $sec = match (true) {
                str_starts_with($m[1], 'sav') => 'savings',
                str_starts_with($m[1], 'loan') => 'loan',
                default => 'bill',
            };
            $tries[] = [$sec . '_' . $m[3], (int)$m[2]];
            $tries[] = [$m[3], (int)$m[2]];
            if ($sec === 'loan') {
                $tries[] = ['loan_' . preg_replace('/^loan_/', '', $m[3]), (int)$m[2]];
            }
        }
        foreach ($tries as [$base, $slot]) {
            $f = $one($base);
            if ($f && $fields[$f]['rep'] && $slot >= 1 && $slot <= 30) {
                return [$f, $slot];
            }
        }
        return null;
    }

    // ================================================================== the files

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM coop_import_batches WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM coop_import_lines WHERE 1 = 0');
                DB::value('SELECT COUNT(*) FROM coop_import_raw WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function batch(int $id): ?array
    {
        $b = $id > 0 ? DB::row('SELECT * FROM coop_import_batches WHERE id = :id', ['id' => $id]) : null;
        if (!$b) {
            return null;
        }
        $b['headers'] = json_decode((string)$b['headers_json'], true) ?: [];
        $b['mapping'] = json_decode((string)$b['mapping_json'], true) ?: [];
        $b['options'] = self::options($b);
        $b['result'] = json_decode((string)$b['result_json'], true) ?: [];
        return $b;
    }

    /** The values for the blank cells of a file (the page's), with their defaults */
    public static function options(array $b): array
    {
        $o = json_decode((string)($b['options_json'] ?? ''), true);
        $o = is_array($o) ? $o : [];
        $codes = CsvImports::importCodes();
        return $o + [
            'cut_off_date' => today(),
            'savings_txn_code' => isset($codes['INI']) ? 'INI' : (string)(array_key_first($codes) ?? ''),
            'balancing_account' => (string)(DB::value('SELECT code FROM accounts WHERE id = :id', ['id' => Accts::get('opening_equity')]) ?: ''),
            'class_id' => '',
            'installments' => '1',
            'payment_mode' => 'Monthly',
        ];
    }

    /** The files read, newest first */
    public static function batches(int $limit = 20): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all('SELECT b.*, u.full_name, u.username FROM coop_import_batches b LEFT JOIN users u ON u.id = b.created_by
                         WHERE b.status <> \'cancelled\' ORDER BY b.id DESC LIMIT ' . max(1, min(100, $limit)));
    }

    /** Starts a file: its batch, before its pieces are sent */
    public static function create(string $fileName, int $bytes): int
    {
        $fileName = mb_substr(trim(basename(str_replace('\\', '/', $fileName))), 0, 190);
        if (!preg_match('/\.(csv|txt|tsv|xlsx)$/i', $fileName)) {
            fail(preg_match('/\.xls$/i', $fileName) ? 'Old Excel files (.xls) are not read: in Excel, File > Save As > Excel Workbook (.xlsx) or CSV.' : 'Choose a CSV file (.csv) or an Excel workbook (.xlsx).');
        }
        if ($bytes <= 0) {
            fail('The file is empty.');
        }
        if ($bytes > self::MAX_FILE_BYTES) {
            fail('The file is larger than ' . (self::MAX_FILE_BYTES >> 20) . ' MB: split it into smaller files.');
        }
        $now = date('Y-m-d H:i:s');
        $id = (int)DB::insert('coop_import_batches', ['file_name' => $fileName, 'file_bytes' => $bytes, 'status' => 'reading', 'created_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
        DB::update('coop_import_batches', ['batch_no' => 'CI-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT)], 'id = :id', ['id' => $id]);
        return $id;
    }

    private static function filePath(array $b): string
    {
        $dir = Tenant::storage('imports');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fail('The folder storage/imports could not be created. Make the storage folder writable by the web server.');
        }
        // pieces of files sent and never read (the browser closed): gone after two days
        foreach (glob($dir . '/consolidated_*') ?: [] as $old) {
            if (is_file($old) && filemtime($old) < time() - 2 * 86400) {
                @unlink($old);
            }
        }
        return $dir . '/consolidated_' . DB::databaseName() . '_' . (int)$b['id'] . '.' . (preg_match('/\.xlsx$/i', (string)$b['file_name']) ? 'xlsx' : 'csv');
    }

    /** One piece of the file (in order: piece 0 starts the file) */
    public static function storeChunk(array $b, int $index, string $data): int
    {
        if ($b['status'] !== 'reading') {
            fail('This file was read already.');
        }
        $path = self::filePath($b);
        $have = $index === 0 ? 0 : (is_file($path) ? (int)filesize($path) : 0);
        if ($index > 0 && $have !== $index * self::CHUNK_BYTES) {
            fail('A piece of the file went missing on the way: read the file again.');
        }
        if ($have + strlen($data) > (int)$b['file_bytes'] || strlen($data) > self::CHUNK_BYTES) {
            fail('The file is not the size it was said to be: read it again.');
        }
        if (file_put_contents($path, $data, $index === 0 ? 0 : FILE_APPEND) === false) {
            fail('The file could not be kept on the server. Make the storage folder writable by the web server.');
        }
        return $have + strlen($data);
    }

    /**
     * Reads the whole file into coop_import_raw (its first row with something in it names the columns), works out what
     * each column holds and makes the lines. Returns the batch.
     */
    public static function parse(array $b): array
    {
        $path = self::filePath($b);
        if (!is_file($path) || (int)filesize($path) !== (int)$b['file_bytes']) {
            fail('The file did not arrive whole: read it again.');
        }
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');
        $rows = self::fileRows($path, (string)$b['file_name']);
        $headers = null;
        $count = 0;
        DB::run('DELETE FROM coop_import_raw WHERE batch_id = :b', ['b' => $b['id']]);
        try {
            self::readRows($b, $rows, $headers, $count);
        } finally {
            @unlink($path);
        }
        if ($headers === null) {
            fail('The file is empty.');
        }
        if (!$count) {
            fail('The file has its column names and no data under them.');
        }
        while ($headers && end($headers) === '') {
            array_pop($headers);
        }
        $fields = self::fields();
        $mapping = [];
        $used = [];
        foreach ($headers as $i => $h) {
            $g = self::guess((string)$h);
            if ($g) {
                // the same column name again ("Savings Code", "Savings Balance", "Savings Code" ...): the next account
                while (isset($used[$g[0] . '#' . $g[1]]) && $fields[$g[0]]['rep'] && $g[1] < 30) {
                    $g[1]++;
                }
                if (isset($used[$g[0] . '#' . $g[1]])) {
                    $g = null;
                }
            }
            if ($g) {
                $used[$g[0] . '#' . $g[1]] = true;
            }
            $mapping[$i] = $g ? ['field' => $g[0], 'slot' => $g[1]] : ['field' => '', 'slot' => 1];
        }
        DB::update('coop_import_batches', ['headers_json' => json_encode($headers, JSON_UNESCAPED_UNICODE), 'mapping_json' => json_encode($mapping),
            'file_rows' => $count, 'status' => 'review', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
        $b = self::batch((int)$b['id']);
        self::build($b);
        return self::batch((int)$b['id']);
    }

    /** The rows of the file into coop_import_raw, a few hundred a statement; the first row with something in it names the columns */
    private static function readRows(array $b, iterable $rows, ?array &$headers, ?int &$count): void
    {
        $headers = null;
        $count = 0;
        $buffer = [];
        $bytes = 0;
        $flush = function () use (&$buffer, &$bytes, $b) {
            if (!$buffer) {
                return;
            }
            $sql = [];
            $params = [];
            foreach ($buffer as $i => [$r, $cells]) {
                $sql[] = "(:b$i, :r$i, :c$i)";
                $params["b$i"] = (int)$b['id'];
                $params["r$i"] = $r;
                $params["c$i"] = $cells;
            }
            DB::run('INSERT INTO coop_import_raw (batch_id, file_row, cells) VALUES ' . implode(',', $sql), $params);
            $buffer = [];
            $bytes = 0;
        };
        foreach ($rows as $r => $line) {
            $line = array_map(fn($v) => trim((string)$v), $line);
            if (!array_filter($line, fn($v) => $v !== '')) {
                continue;
            }
            if ($headers === null) {
                $headers = $line;
                continue;
            }
            if (++$count > self::MAX_ROWS) {
                fail('The file has more than ' . number_format(self::MAX_ROWS) . ' rows: split it into smaller files.');
            }
            $cells = json_encode(array_slice($line, 0, max(count($headers), 1)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $buffer[] = [(int)$r, $cells];
            $bytes += strlen($cells) + 32;
            // a few hundred rows a statement, kept well under max_allowed_packet (1 MB on MySQL 5.5)
            if (count($buffer) >= 400 || $bytes > 400000) {
                $flush();
            }
        }
        $flush();
    }

    /** The rows of a CSV or Excel file: row number => cells */
    private static function fileRows(string $path, string $name): iterable
    {
        if (preg_match('/\.xlsx$/i', $name)) {
            return XlsxReader::numbered($path, self::MAX_ROWS + 1, 400);
        }
        return (function () use ($path) {
            $fh = Importer::openAsUtf8($path);
            $first = (string)fgets($fh);
            rewind($fh);
            $delim = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
            $n = 0;
            while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
                $n++;
                if ($r === [null]) {
                    continue;
                }
                yield $n => $r;
            }
            fclose($fh);
        })();
    }

    /**
     * The choices of what each column holds, as the user made them: saved on the file, remembered for the next file
     * with the same column names, and the lines made again from the rows of the file
     */
    public static function setMapping(array $b, array $posted): array
    {
        $fields = self::fields();
        $mapping = [];
        $learned = self::learned();
        $used = [];
        foreach ($b['headers'] as $i => $h) {
            $f = (string)($posted[$i]['field'] ?? '');
            $slot = max(1, min(30, (int)($posted[$i]['slot'] ?? 1)));
            if ($f !== '' && !isset($fields[$f])) {
                fail('Column ' . $h . ': choose what it holds from the list.');
            }
            if ($f !== '' && !$fields[$f]['rep']) {
                $slot = 1;
            }
            if ($f !== '') {
                $k = $f . '#' . $slot;
                if (isset($used[$k])) {
                    fail('Columns ' . $b['headers'][$used[$k]] . ' and ' . $h . ' both hold ' . $fields[$f]['label'] . ($fields[$f]['rep'] ? ' ' . $slot : '') . ': choose another for one of them.');
                }
                $used[$k] = $i;
            }
            $mapping[$i] = ['field' => $f, 'slot' => $slot];
            $hk = CsvReview::headerKey((string)$h);
            $was = $b['mapping'][$i] ?? ['field' => '', 'slot' => 1];
            if ($hk !== '' && ($was['field'] !== $f || (int)$was['slot'] !== $slot)) {
                $learned[$hk] = [$f, $slot];
            }
        }
        Settings::set('consolidated_import_columns', json_encode($learned, JSON_UNESCAPED_UNICODE));
        DB::update('coop_import_batches', ['mapping_json' => json_encode($mapping), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
        $b = self::batch((int)$b['id']);
        self::build($b);
        return self::batch((int)$b['id']);
    }

    /** The values for the blank cells, as chosen on the page */
    public static function setOptions(array $b, array $posted): array
    {
        $o = $b['options'];
        foreach (['cut_off_date', 'savings_txn_code', 'balancing_account', 'class_id', 'installments', 'payment_mode'] as $k) {
            if (array_key_exists($k, $posted)) {
                $o[$k] = mb_substr(trim((string)$posted[$k]), 0, 60);
            }
        }
        DB::update('coop_import_batches', ['options_json' => json_encode($o), 'check_cursor' => 0, 'checked_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
        DB::run("UPDATE coop_import_lines SET checked = 0 WHERE batch_id = :b AND status = 'pending'", ['b' => $b['id']]);
        return self::batch((int)$b['id']);
    }

    // ================================================================== the lines

    /**
     * Makes the lines of the review from the rows of the file and the choices of what each column holds: a member line
     * for each member (the rows of one member together), a line for each savings account, loan and bill balance.
     * Every correction made before is replaced.
     */
    public static function build(array $b): void
    {
        if ($b['status'] !== 'review') {
            fail('The lines of this file cannot be made again: it is ' . $b['status'] . '.');
        }
        @set_time_limit(600);
        $fields = self::fields();
        $id = (int)$b['id'];
        $cols = [];   // column => [field, slot]
        foreach ($b['mapping'] as $i => $m) {
            if (($m['field'] ?? '') !== '') {
                $cols[(int)$i] = [(string)$m['field'], (int)($m['slot'] ?? 1)];
            }
        }
        $lineNo = 0;
        $members = [];        // identity => the member's line no.
        $current = null;      // the line no. of the member of the rows above
        $memberVals = [];     // member line no. => its values
        $buffer = [];         // line no. => [kind, file row, values, member line no.], written a few hundred at a time
        $bytes = 0;
        $flush = function () use (&$buffer, &$bytes, $id) {
            if (!$buffer) {
                return;
            }
            $sql = [];
            $p = [];
            $i = 0;
            foreach ($buffer as $no => [$kind, $row, $vals, $memberLine]) {
                $vals = array_filter($vals, fn($v) => $v !== '');
                [$keyNo, $keyPerson, $keyWater, $amount] = self::keys($kind, $vals);
                $sql[] = "($id, :k$i, $no, $memberLine, $row, :v$i, :n$i, :p$i, :w$i, :a$i, 'pending', 0, 0)";
                $p["k$i"] = $kind;
                $p["v$i"] = json_encode($vals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $p["n$i"] = $keyNo;
                $p["p$i"] = $keyPerson;
                $p["w$i"] = $keyWater;
                $p["a$i"] = $amount;
                $i++;
            }
            DB::run('INSERT INTO coop_import_lines (batch_id, kind, line_no, member_line_no, file_row, vals, key_no, key_person, key_water, amount, status, checked, bad) VALUES '
                . implode(',', $sql), $p);
            $buffer = [];
            $bytes = 0;
        };
        $add = function (string $kind, int $row, array $vals, ?int $memberLine) use (&$buffer, &$bytes, &$lineNo, $flush): int {
            $no = ++$lineNo;
            $buffer[$no] = [$kind, $row, $vals, $memberLine ?? $no];
            $bytes += strlen(json_encode($vals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) + 200;
            // a few hundred lines a statement, kept well under max_allowed_packet (1 MB on MySQL 5.5)
            if (count($buffer) >= 300 || $bytes > 300000) {
                $flush();
            }
            return $no;
        };
        // the details of a member found on a later row fill what its line left blank
        $fill = function (int $ln, array $mv) use (&$buffer, &$memberVals, $id) {
            $more = array_diff_key($mv, $memberVals[$ln]);
            if (!$more) {
                return;
            }
            $memberVals[$ln] += $more;
            if (isset($buffer[$ln])) {
                $buffer[$ln][2] = $memberVals[$ln];
                return;
            }
            $vals = array_filter($memberVals[$ln], fn($v) => $v !== '');
            [$keyNo, $keyPerson, $keyWater] = self::keys('member', $vals);
            DB::update('coop_import_lines', ['vals' => json_encode($vals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'key_no' => $keyNo, 'key_person' => $keyPerson,
                'key_water' => $keyWater], 'batch_id = :b AND line_no = :l', ['b' => $id, 'l' => $ln]);
        };
        DB::tx(function () use ($id, $cols, $fields, &$current, &$members, &$memberVals, $add, $fill, $flush) {
            DB::run('DELETE FROM coop_import_lines WHERE batch_id = :b', ['b' => $id]);
            $last = -1;
            while (true) {
                $raw = DB::all('SELECT file_row, cells FROM coop_import_raw WHERE batch_id = :b AND file_row > :r ORDER BY file_row LIMIT 2000', ['b' => $id, 'r' => $last]);
                if (!$raw) {
                    break;
                }
                foreach ($raw as $rr) {
                    $last = (int)$rr['file_row'];
                    $cells = json_decode((string)$rr['cells'], true) ?: [];
                    $row = (int)$rr['file_row'];
                    $by = [];     // section => slot => field => value
                    foreach ($cols as $i => [$f, $slot]) {
                        $v = trim((string)($cells[$i] ?? ''));
                        if ($v !== '') {
                            $by[$fields[$f]['section']][$fields[$f]['rep'] ? $slot : 1][$f] = $v;
                        }
                    }
                    [$mv, $split] = self::splitName(($by['member'][1] ?? []) + ($by['water'][1] ?? []) + ($by['fsc'][1] ?? []));
                    if ($split) {
                        $mv['_split'] = '1';
                    }
                    $ident = self::identity($mv);
                    if ($ident !== null) {
                        if (isset($members[$ident])) {
                            // the same member on another row (its member no., or its name and date of birth)
                            $current = $members[$ident];
                            $fill($current, $mv);
                        } else {
                            $current = $add('member', $row, $mv, null);
                            $members[$ident] = $current;
                            $memberVals[$current] = $mv;
                        }
                    } elseif ($mv) {
                        if ($current === null) {
                            // member details with no name or member no., and no member above: a member line in red
                            $current = $add('member', $row, $mv, null);
                            $memberVals[$current] = $mv;
                        } else {
                            // a row under the member's row with more of the member's details
                            $fill($current, $mv);
                        }
                    }
                    $accounts = self::accountsOfRow($by);
                    if ($accounts && $current === null) {
                        // accounts before any member: their member line is in red, so they are not lost
                        $current = $add('member', $row, [], null);
                        $memberVals[$current] = [];
                    }
                    foreach ($accounts as [$kind, $vals]) {
                        $add($kind, $row, $vals, $current);
                    }
                }
            }
            $flush();
            // each line to its member's line
            DB::run('UPDATE coop_import_lines l JOIN coop_import_lines m ON m.batch_id = l.batch_id AND m.line_no = l.member_line_no
                        SET l.member_line_id = m.id WHERE l.batch_id = :b', ['b' => $id]);
        });
        DB::update('coop_import_batches', ['check_cursor' => 0, 'checked_at' => null, 'result_json' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    /** Who a row's member is, by its member no. or its name and date of birth; null when the row names no member */
    private static function identity(array $mv): ?string
    {
        $no = mb_strtolower(trim((string)($mv['member_no'] ?? '')));
        if ($no !== '') {
            return 'no:' . $no;
        }
        $last = mb_strtolower(trim((string)($mv['last_name'] ?? '')));
        $first = mb_strtolower(trim((string)($mv['first_name'] ?? '')));
        if ($last === '' && $first === '') {
            return null;
        }
        return 'nb:' . $last . '|' . $first . '|' . mb_strtolower(trim((string)($mv['middle_name'] ?? ''))) . '|' . (CsvReview::date((string)($mv['birth_date'] ?? '')) ?? trim((string)($mv['birth_date'] ?? '')));
    }

    /** The savings accounts, loans and bill balances a row holds: [[kind, values], ...] in the order of their columns */
    private static function accountsOfRow(array $by): array
    {
        $out = [];
        foreach ([['savings', ['savings_code', 'savings_balance', 'savings_account_no']], ['loan', ['loan_name', 'loan_principal', 'loan_no']], ['bill', ['bill_type', 'bill_charge_code', 'bill_balance']]] as [$sec, $needs]) {
            $slots = $by[$sec] ?? [];
            ksort($slots);
            foreach ($slots as $vals) {
                $vals = array_filter($vals, fn($k) => empty(self::fields()[$k]['file']), ARRAY_FILTER_USE_KEY);
                if (array_intersect_key($vals, array_flip($needs))) {
                    $out[] = [$sec, $vals];
                }
            }
        }
        // the balance columns of one row per member (Upload Bill Balances): a bill line for each balance
        $w = $by['bill'][1] ?? [];
        foreach (self::WIDE_BILLS as $type => [$balKey, $codeKey]) {
            if (($w[$balKey] ?? '') === '') {
                continue;
            }
            $vals = ['bill_type' => BBU_TYPES[$type][0], 'bill_balance' => $w[$balKey]];
            if ($codeKey && ($w[$codeKey] ?? '') !== '') {
                $vals['bill_charge_code'] = $w[$codeKey];
            }
            foreach (['bill_date', 'bill_due_date', 'bill_share_capital_account'] as $k) {
                if (($w[$k] ?? '') !== '' && ($k !== 'bill_share_capital_account' || $type === 'share_capital')) {
                    $vals[$k] = $w[$k];
                }
            }
            if ($type === 'installment') {
                foreach (['installments' => 'bill_installments', 'payment_mode' => 'bill_payment_mode'] as $from => $to) {
                    $v = $w[$from] ?? ($w[$to] ?? '');
                    if ($v !== '') {
                        $vals[$to] = $v;
                    }
                }
            }
            $out[] = ['bill', $vals];
        }
        return $out;
    }

    /** The keys a line is found by for its duplicates, and its amount: [key_no, key_person, key_water, amount] */
    private static function keys(string $kind, array $vals): array
    {
        $low = fn($v) => ($v = mb_strtolower(trim((string)$v))) === '' ? null : mb_substr($v, 0, 60);
        $amount = fn($v) => ($a = CsvReview::amount((string)$v)) === null || abs((float)$a) > 9999999999999 ? null : $a;
        return match ($kind) {
            'member' => [$low($vals['member_no'] ?? ''), ($last = trim((string)($vals['last_name'] ?? ''))) !== '' && trim((string)($vals['first_name'] ?? '')) !== '' && ($bd = CsvReview::date((string)($vals['birth_date'] ?? '')))
                ? mb_substr(mb_strtolower(Members::fullName(['last_name' => $last, 'first_name' => trim((string)$vals['first_name']), 'middle_name' => self::middle((string)($vals['middle_name'] ?? '')), 'suffix' => trim((string)($vals['suffix'] ?? ''))])) . '|' . $bd, 0, 190)
                : null, $low($vals['water_account_no'] ?? ''), null],
            'savings' => [$low($vals['savings_account_no'] ?? ''), null, null, $amount($vals['savings_balance'] ?? '')],
            'loan' => [$low($vals['loan_no'] ?? ''), null, null, $amount($vals['loan_principal'] ?? '')],
            // a bill balance: its billing type (the share capital balances make no journal entry, so need no class)
            default => [bbu_type_key((string)($vals['bill_type'] ?? '')), null, null, $amount($vals['bill_balance'] ?? '')],
        };
    }

    private static function middle(string $v): string
    {
        $v = trim($v);
        return in_array(mb_strtolower($v), ['-', '--', 'n/a', 'na', 'none', '.'], true) ? '' : $v;
    }

    // ================================================================== the check

    /**
     * Checks the next members (with their lines) for at most STEP_SECONDS, from where the last step stopped. Returns
     * ['done' => all checked, 'checked' => members checked so far, 'members' => members in all].
     */
    public static function checkStep(array $b): array
    {
        @set_time_limit(300);
        $start = microtime(true);
        $cursor = (int)$b['check_cursor'];
        $total = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending'", ['b' => $b['id']]);
        do {
            $ids = DB::column("SELECT id FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending' AND line_no > :c ORDER BY line_no LIMIT 50",
                ['b' => $b['id'], 'c' => $cursor]);
            if (!$ids) {
                break;
            }
            self::checkMembers($b, array_map('intval', $ids));
            $cursor = (int)DB::value('SELECT MAX(line_no) FROM coop_import_lines WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        } while (microtime(true) - $start < self::STEP_SECONDS);
        $done = !DB::value("SELECT id FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending' AND line_no > :c LIMIT 1", ['b' => $b['id'], 'c' => $cursor]);
        if ($done) {
            // a line of no member (its member removed) is in red
            self::orphans($b);
        }
        DB::update('coop_import_batches', ['check_cursor' => $done ? 0 : $cursor, 'checked_at' => $done ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id', ['id' => $b['id']]);
        $checked = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending' AND line_no <= :c", ['b' => $b['id'], 'c' => $done ? PHP_INT_MAX : $cursor]);
        return ['done' => $done, 'checked' => $done ? $total : $checked, 'members' => $total];
    }

    /** The lines whose member line was removed */
    private static function orphans(array $b): void
    {
        foreach (DB::all("SELECT l.id FROM coop_import_lines l JOIN coop_import_lines m ON m.id = l.member_line_id
                           WHERE l.batch_id = :b AND l.kind <> 'member' AND l.status = 'pending' AND m.status <> 'pending'", ['b' => $b['id']]) as $l) {
            self::store((int)$l['id'], ['errors' => ['Its member was removed: remove this line too, or put the member back.'], 'notes' => [], 'info' => '', 'cells' => []]);
        }
    }

    /**
     * Checks members and their lines with the checks of the four imports, and the file as a whole (the same member,
     * account no., loan no. or water-service account twice), and keeps the result on each line.
     */
    public static function checkMembers(array $b, array $memberIds): void
    {
        if (!$memberIds) {
            return;
        }
        CsvImports::$memo = true;
        LoanImport::$memo = true;
        self::loadWater();
        $o = $b['options'];
        $all = DB::all('SELECT * FROM coop_import_lines WHERE batch_id = :b AND status = \'pending\' AND member_line_id IN (' . implode(',', array_map('intval', $memberIds)) . ') ORDER BY line_no',
            ['b' => $b['id']]);
        $groups = [];
        foreach ($all as $l) {
            $l['v'] = json_decode((string)$l['vals'], true) ?: [];
            $groups[(int)$l['member_line_id']][$l['kind']][] = $l;
        }
        foreach ($groups as $mid => $g) {
            if (empty($g['member'][0])) {
                continue;
            }
            foreach (self::checkGroup($b, $g, $o) as $lineId => $res) {
                self::store($lineId, $res);
            }
        }
    }

    /** Keeps the result of a line's check */
    private static function store(int $lineId, array $r): void
    {
        $errs = array_values(array_unique(array_map('strval', (array)$r['errors'])));
        DB::update('coop_import_lines', [
            'errors' => $errs ? json_encode($errs, JSON_UNESCAPED_UNICODE) : null,
            'notes' => $r['notes'] ? json_encode(array_values(array_unique(array_map('strval', (array)$r['notes']))), JSON_UNESCAPED_UNICODE) : null,
            'info' => mb_substr((string)$r['info'], 0, 2000) ?: null,
            'cells' => $r['cells'] ? json_encode(array_values(array_unique((array)$r['cells']))) : null,
            'bad' => $errs ? 1 : 0, 'checked' => 1,
        ], 'id = :id', ['id' => $lineId]);
    }

    /**
     * One member and its lines: line id => [errors, notes, info, cells]. Also gives, for saving, the member as it is
     * on file or as it will be made ($ctx).
     */
    public static function checkGroup(array $b, array $g, array $o, ?array &$ctx = null): array
    {
        $fields = self::fields();
        $ml = $g['member'][0];
        $mv = $ml['v'];
        $res = [];
        $out = function (int $id) use (&$res) {
            return $res[$id] ??= ['errors' => [], 'notes' => [], 'info' => '', 'cells' => []];
        };
        $err = function (int $id, string $m, array $cells = []) use (&$res, $out) {
            $out($id);
            $res[$id]['errors'][] = $m;
            foreach ($cells as $c) {
                $res[$id]['cells'][] = $c;
            }
        };
        $note = function (int $id, string $m) use (&$res, $out) {
            $out($id);
            $res[$id]['notes'][] = $m;
        };
        $mid = (int)$ml['id'];
        $out($mid);
        $line = (int)$ml['line_no'];
        $no = trim((string)($mv['member_no'] ?? ''));
        $existing = $no !== '' ? DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $no]) : null;
        $member = null;           // the member as the checks of the imports see it
        $memberName = '';
        // the same member no. on two member lines (a line corrected to another member's number)
        if ($ml['key_no']) {
            $first = DB::value("SELECT MIN(line_no) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status IN ('pending', 'saved') AND key_no = :k AND id <> :id",
                ['b' => $b['id'], 'k' => $ml['key_no'], 'id' => $mid]);
            if ($first && (int)$first < $line) {
                $err($mid, 'Member no. ' . $no . ' is also on line ' . $first . ' of the file: one member line for each member (its accounts on the rows under it), or remove this line.', ['member_no']);
            }
        }
        if (!empty($mv['_split'])) {
            $note($mid, 'The whole name of the file was split into the last name, first name and middle name: check them.');
        }
        if ($existing) {
            // a member on file: only the accounts and balances of the file are added
            $given = trim(trim((string)($mv['last_name'] ?? '')) . ', ' . trim((string)($mv['first_name'] ?? '')), ', ');
            if ($given !== '' && !CsvReview::nameMatches($given, (string)$existing['last_name'], (string)$existing['first_name'], (string)$existing['full_name'])) {
                $err($mid, 'Member no. ' . $no . ' is on file as ' . $existing['full_name'] . ', not ' . $given . '.', ['member_no', 'last_name', 'first_name']);
            } elseif (!Coop::branchAllowed($existing['branch_id'] ? (int)$existing['branch_id'] : null)) {
                $err($mid, 'Member ' . $no . ' belongs to another branch.', ['member_no']);
            }
            $details = array_diff(array_keys(array_filter($mv, fn($v) => $v !== '')), ['member_no', 'last_name', 'first_name', 'middle_name', 'suffix', 'birth_date']);
            $details = array_filter($details, fn($k) => ($fields[$k]['section'] ?? '') === 'member');
            $note($mid, 'Member ' . $no . ' (' . $existing['full_name'] . ') is on file: the file adds the member\'s accounts and balances' . ($details ? '; the member\'s details on file are not changed.' : '.'));
            $member = $existing;
            $memberName = (string)$existing['full_name'];
            $res[$mid]['info'] = h($existing['full_name']) . ' <span class="ci-dim">member no. ' . h($no) . ', on file</span>';
        } else {
            $row = ['_line' => $line];
            foreach (self::memo('member_cols', fn() => CsvImports::cols('members')) as $k => $_) {
                $row[$k] = trim((string)($mv[$k] ?? ''));
            }
            [$rows] = CsvImports::check('members', [$row], []);
            $r = $rows[0];
            foreach ($r['_errors'] as $e) {
                $out($mid);
                // a person on file under another number: the accounts go to that member with its number on the line
                $res[$mid]['errors'][] = preg_match('/is already a member: (\S+)\.$/u', $e, $mm)
                    ? $e . ' To add the accounts to that member, enter member no. ' . $mm[1] . ' on this line.' : $e;
            }
            foreach (array_keys(CsvReview::named($r['_errors'], self::memo('member_cols', fn() => CsvImports::cols('members')), (array)($r['_cells'] ?? []))) as $c) {
                $res[$mid]['cells'][] = $c;
            }
            foreach ($r['_notes'] as $n) {
                $note($mid, $n);
            }
            $res[$mid]['info'] = (string)$r['_info'];
            // the same person twice in the file (another member no.)
            if ($ml['key_person']) {
                $first = DB::value("SELECT MIN(line_no) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status IN ('pending', 'saved') AND key_person = :k AND id <> :id",
                    ['b' => $b['id'], 'k' => $ml['key_person'], 'id' => $mid]);
                if ($first && (int)$first < $line) {
                    $err($mid, 'The same person (name and date of birth) is also on line ' . $first . ' of the file, with another member no.', ['last_name', 'first_name', 'birth_date']);
                }
            }
            [$types, $branches] = self::memo('types_branches', function () {
                $types = [];
                foreach (Members::types() as $t) {
                    $types[mb_strtolower((string)$t['code'])] = $t;
                    $types[mb_strtolower((string)$t['name'])] = $t;
                }
                $branches = [];
                foreach (Coop::branches() as $br) {
                    $branches[mb_strtolower((string)$br['code'])] = $br;
                    $branches[mb_strtolower((string)$br['name'])] = $br;
                }
                return [$types, $branches];
            });
            $t = $types[mb_strtolower(trim((string)($mv['member_type'] ?? '')))] ?? null;
            $br = trim((string)($mv['branch'] ?? '')) !== '' ? ($branches[mb_strtolower(trim((string)$mv['branch']))] ?? null) : null;
            $memberName = Members::fullName(['last_name' => trim((string)($mv['last_name'] ?? '')), 'first_name' => trim((string)($mv['first_name'] ?? '')),
                'middle_name' => self::middle((string)($mv['middle_name'] ?? '')), 'suffix' => trim((string)($mv['suffix'] ?? ''))]);
            $member = ['id' => 0, 'member_no' => $no !== '' ? $no : '(line ' . $line . ')', 'last_name' => trim((string)($mv['last_name'] ?? '')), 'first_name' => trim((string)($mv['first_name'] ?? '')),
                'middle_name' => self::middle((string)($mv['middle_name'] ?? '')), 'full_name' => $memberName, 'status' => 'active', 'hold_flag' => 0, 'hold_reason' => '',
                'branch_id' => $br ? (int)$br['id'] : Coop::defaultBranchId(), 'member_type_id' => $t ? (int)$t['id'] : null, 'party_id' => null];
        }
        $code = (string)$member['member_no'];
        $resolver = function ($asked) use ($code, $member) {
            return trim((string)$asked) === $code ? $member : DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $asked]);
        };

        // ---------------------------------------------------- the water-service account
        $water = self::checkWater($b, $ml, $mv, $existing, $memberName, $err, $note);

        // ---------------------------------------------------- savings
        $savRows = [];
        $savLines = [];
        foreach ($g['savings'] ?? [] as $l) {
            $savRows[] = self::savingsRow($l, $code, $memberName);
            $savLines[] = $l;
        }
        $scAccounts = [];   // the member's share capital accounts of the file: [line, values]
        if ($savRows) {
            CsvImports::$memberResolver = $resolver;
            try {
                [$checked] = CsvImports::check('savings', $savRows, ['savings_txn_code' => $o['savings_txn_code'], 'cut_off_date' => $o['cut_off_date']]);
            } finally {
                CsvImports::$memberResolver = null;
            }
            $products = self::memo('product_types', fn() => array_change_key_case(DB::pairs('SELECT code, product_type FROM sav_products'), CASE_UPPER));
            foreach ($checked as $i => $r) {
                $l = $savLines[$i];
                self::mapResult($res, (int)$l['id'], $r, self::memo('savings_cols', fn() => CsvImports::cols('savings')), self::SAVINGS_MAP, (bool)$res[$mid]['errors'], $line, $code);
                if (($products[mb_strtoupper((string)$r['savings_code'])] ?? '') === 'share_capital') {
                    $scAccounts[] = [$l, $r];
                }
                // the same account no. on another member's line
                if ($l['key_no']) {
                    $first = DB::value("SELECT MIN(line_no) FROM coop_import_lines WHERE batch_id = :b AND kind = 'savings' AND status IN ('pending', 'saved') AND key_no = :k AND member_line_id <> :m",
                        ['b' => $b['id'], 'k' => $l['key_no'], 'm' => $mid]);
                    if ($first && (int)$first < (int)$l['line_no']) {
                        $err((int)$l['id'], 'Account no. ' . $l['v']['savings_account_no'] . ' is also on line ' . $first . ' of the file.', ['savings_account_no']);
                    }
                }
            }
        }

        // ---------------------------------------------------- loans
        $loanRows = [];
        $loanLines = [];
        foreach ($g['loan'] ?? [] as $l) {
            $r = ['_line' => (int)$l['line_no'], 'member_code' => $code, 'member_name' => $memberName];
            foreach (self::LOAN_MAP as $k => $t) {
                $r[$t] = trim((string)($l['v'][$k] ?? ''));
            }
            $loanRows[] = $r;
            $loanLines[] = $l;
        }
        $general = [];
        if ($loanRows) {
            LoanImport::$memberResolver = $resolver;
            try {
                [$checked, , $general] = LoanImport::check($loanRows, ['cut_off_date' => $o['cut_off_date'], 'balancing_account' => $o['balancing_account']]);
            } finally {
                LoanImport::$memberResolver = null;
            }
            foreach ($checked as $i => $r) {
                $l = $loanLines[$i];
                self::mapResult($res, (int)$l['id'], $r, self::memo('loan_cols', fn() => LoanImport::cols()), self::LOAN_MAP, (bool)$res[$mid]['errors'], $line, $code);
                if ($l['key_no']) {
                    $first = DB::value("SELECT MIN(line_no) FROM coop_import_lines WHERE batch_id = :b AND kind = 'loan' AND status IN ('pending', 'saved') AND key_no = :k AND member_line_id <> :m",
                        ['b' => $b['id'], 'k' => $l['key_no'], 'm' => $mid]);
                    if ($first && (int)$first < (int)$l['line_no']) {
                        $err((int)$l['id'], 'Loan no. ' . $l['v']['loan_no'] . ' is also on line ' . $first . ' of the file.', ['loan_no']);
                    }
                }
            }
            foreach ($general as $gm) {
                foreach ($loanLines as $l) {
                    $err((int)$l['id'], $gm, ['loan_balancing_account']);
                }
            }
        }

        // ---------------------------------------------------- the fixed share capital billing
        $fsc = self::checkFsc($ml, $mv, $existing, $scAccounts, $o, $err);

        // ---------------------------------------------------- bill balances
        $billLines = $g['bill'] ?? [];
        if ($billLines) {
            if (!$water) {
                foreach ($billLines as $l) {
                    $err((int)$l['id'], 'The member has no water-service account for the bill balance: give the member\'s water account no., or the water rate code and pipe size to open one.', ['bill_type']);
                }
            } else {
                $accountNo = $water['no'];
                $rows = [];
                foreach ($billLines as $l) {
                    $r = ['_line' => (int)$l['line_no'], '_ref' => 'Row ' . $l['file_row'], 'account_no' => $accountNo, 'member_name' => ''];
                    foreach (self::BILL_MAP as $k => $t) {
                        $r[$t] = trim((string)($l['v'][$k] ?? ''));
                    }
                    $rows[] = $r;
                }
                $bopt = ['memo' => true];
                if ($water['virtual']) {
                    $bopt['virtual'] = [$accountNo => ['pkey' => 0, 'account_number' => $accountNo, 'lastname' => $member['last_name'], 'firstname' => $member['first_name'],
                        'middlename' => $member['middle_name'] ?? '', 'status' => 'A', 'party_id' => null]];
                }
                if (!$existing || ($existing && !$water['member_party'])) {
                    // a member made by the file: the fixed billing it gets (or none)
                    $bopt['sc_plans'] = [$accountNo => $fsc ? ['plan_id' => 0, 'member_id' => 0, 'sav_account_id' => 0, 'account_no' => $fsc['account_no'], 'status' => 'active',
                        'account_status' => 'active', 'next_bill_date' => null] : null];
                    $bopt['sc_missing'] = [$accountNo => $memberName . ' is not on Fixed Share Capital Billing: give the fixed share capital amount (fsc_amount) on the member\'s line, with the member\'s share capital account among the savings, or remove this line.'];
                }
                $checked = DB::withRelaxed(fn() => bbu_check(DB::pdo(), $rows, self::billCommon($o), $bopt));
                $cols = array_flip(self::BILL_MAP);
                foreach ($checked as $i => $r) {
                    $l = $billLines[$i];
                    $id = (int)$l['id'];
                    $out($id);
                    foreach ($r['_errors'] as $j => $e) {
                        $res[$id]['errors'][] = $e;
                        foreach ((array)($r['_cells'][$j] ?? []) as $c) {
                            if (isset($cols[$c])) {
                                $res[$id]['cells'][] = $cols[$c];
                            }
                        }
                    }
                    foreach ($r['_notes'] as $n) {
                        $res[$id]['notes'][] = $n;
                    }
                    $res[$id]['info'] = preg_replace('/^.*?<\/span>\s*/', '', (string)$r['_info']);
                }
            }
        }
        // a share capital balance on the fixed billing the file sets up: what was unpaid before its first bill
        if ($fsc && $fsc['start']) {
            foreach ($billLines as $l) {
                if (bbu_type_key((string)($l['v']['bill_type'] ?? '')) !== 'share_capital') {
                    continue;
                }
                $d = CsvReview::dateStrict(trim((string)($l['v']['bill_date'] ?? '')) !== '' ? (string)$l['v']['bill_date'] : (string)$o['cut_off_date']);
                if ($d && $d >= $fsc['start']) {
                    $err((int)$l['id'], 'The fixed share capital billing of the member starts on ' . fdate($fsc['start']) . ' and bills from then on: date the unpaid share capital before it (the cut-off date), or start the billing later (fsc_start_date).', ['bill_date']);
                }
            }
        }

        // the member line says what the member brings
        $parts = [];
        foreach (['savings' => ['savings account', 'savings accounts'], 'loan' => ['loan', 'loans'], 'bill' => ['bill balance', 'bill balances']] as $k => [$one, $many]) {
            $n = count($g[$k] ?? []);
            if ($n) {
                $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
            }
        }
        if ($water) {
            $parts[] = $water['virtual'] ? 'a water-service account to open' : 'water account ' . $water['no'];
        }
        if ($fsc) {
            $parts[] = 'fixed share capital billing of ' . Dec::money($fsc['amount']) . ' ' . strtolower(ShareCapitalBilling::FREQUENCIES[$fsc['frequency']][0]);
        }
        if ($parts && !$res[$mid]['errors']) {
            $res[$mid]['info'] .= ($res[$mid]['info'] !== '' ? '; ' : '') . h(implode(', ', $parts));
        }
        // a line of the member is in red: said on the member's line
        $badLines = 0;
        foreach ($res as $id => $r) {
            if ($id !== $mid && $r['errors']) {
                $badLines++;
            }
        }
        if ($badLines) {
            $note($mid, $badLines . ' of the member\'s lines ' . ($badLines === 1 ? 'has' : 'have') . ' a problem (see the other tabs).');
        }
        $ctx = ['existing' => $existing, 'member' => $member, 'name' => $memberName, 'water' => $water, 'fsc' => $fsc];
        foreach (array_merge(...array_values($g)) as $l) {
            $out((int)$l['id']);
        }
        return $res;
    }

    /**
     * A savings line as Import Savings reads it. The savings code may be written as a spreadsheet leaves it ("1" for
     * "01") or by the product's name: it becomes the product's code when it names one product only.
     */
    private static function savingsRow(array $l, string $code, string $name): array
    {
        $r = ['_line' => (int)$l['line_no'], 'member_code' => $code, 'member_name' => $name];
        foreach (self::SAVINGS_MAP as $k => $t) {
            $r[$t] = trim((string)($l['v'][$k] ?? ''));
        }
        $products = self::memo('sav_codes', fn() => DB::all('SELECT code, name FROM sav_products'));
        $v = mb_strtoupper($r['savings_code']);
        if ($v !== '' && !in_array($v, array_map(fn($p) => mb_strtoupper((string)$p['code']), $products), true)) {
            $hits = array_values(array_filter($products, fn($p) => (ctype_digit($v) && ctype_digit((string)$p['code']) && ltrim((string)$p['code'], '0') === ltrim($v, '0'))
                || mb_strtoupper(trim((string)$p['name'])) === $v || mb_strtoupper(trim($p['code'] . ' ' . $p['name'])) === $v));
            if (count($hits) === 1) {
                $r['savings_code'] = (string)$hits[0]['code'];
            }
        }
        return $r;
    }

    /** The values of Upload Bill Balances for the blank cells of the bill lines: the page's */
    private static function billCommon(array $o): array
    {
        return ['bill_date' => (string)$o['cut_off_date'], 'due_date' => '', 'recurring_code' => '', 'installment_code' => '', 'one_time_code' => '',
            'installments' => (string)($o['installments'] ?: '1'), 'payment_mode' => (string)($o['payment_mode'] ?: 'Monthly')];
    }

    /** The result of an import's check on a line, with its cells named by the columns of this import */
    private static function mapResult(array &$res, int $id, array $r, array $cols, array $map, bool $memberBad, int $memberLine, string $code): void
    {
        $res[$id] ??= ['errors' => [], 'notes' => [], 'info' => '', 'cells' => []];
        $back = array_flip($map);
        $cells = (array)($r['_cells'] ?? []);
        foreach (array_values($r['_errors']) as $j => $e) {
            $named = array_keys(CsvReview::named([$e], $cols, isset($cells[$j]) ? [$cells[$j]] : []));
            if ($memberBad && (($named && !array_diff($named, ['member_code', 'member_name'])) || str_contains((string)$e, $code))) {
                // about the member (its number or name) while its line is in red: said there, not again on each of its accounts
                continue;
            }
            $res[$id]['errors'][] = $e;
            foreach ($named as $c) {
                if (isset($back[$c])) {
                    $res[$id]['cells'][] = $back[$c];
                }
            }
        }
        if ($memberBad && !$res[$id]['errors'] && $r['_errors']) {
            $res[$id]['errors'][] = 'Correct the member\'s line (line ' . $memberLine . ') first.';
        }
        foreach ($r['_notes'] as $n) {
            $res[$id]['notes'][] = $n;
        }
        // the import names the member first ("Dela Cruz, Juan (100201): ..."): the line is under its member already
        $res[$id]['info'] = (string)preg_replace('/^.*?\):\s*/', '', (string)$r['_info']);
    }

    /**
     * The member's water-service account: named by its account no., or opened from a rate code and a pipe size, or the
     * member's own (a member on file). Returns ['no', 'virtual', 'pkey', 'party_id', 'member_party', 'open' => fields]
     * or null when the member has none.
     */
    private static function checkWater(array $b, array $ml, array $mv, ?array $existing, string $name, callable $err, callable $note): ?array
    {
        $mid = (int)$ml['id'];
        $no = trim((string)($mv['water_account_no'] ?? ''));
        $rc = trim((string)($mv['water_rate_code'] ?? ''));
        $pipe = trim((string)($mv['water_pipe_size'] ?? ''));
        if (!MemberDefaults::waterReady()) {
            if ($no !== '' || $rc !== '') {
                $err($mid, 'The water billing module is not installed: the water columns cannot be imported.', ['water_account_no']);
            }
            return null;
        }
        if ($no !== '' && ($rc !== '' || $pipe !== '')) {
            $err($mid, 'Give the water account no. of an account on file, or the rate code and pipe size of an account to open, not both.', ['water_account_no', 'water_rate_code']);
            return null;
        }
        if ($no !== '') {
            $w = DB::row('SELECT * FROM water_accounts WHERE account_number = :n', ['n' => $no]) ?: (ctype_digit($no) ? DB::row("SELECT * FROM water_accounts WHERE pkey = :n AND (account_number IS NULL OR account_number = '')", ['n' => (int)$no]) : null);
            if (!$w) {
                $err($mid, 'No water-service account ' . $no . ' (Water Service Accounts).', ['water_account_no']);
                return null;
            }
            if (strtoupper((string)$w['status']) !== 'A') {
                $err($mid, 'Water-service account ' . $no . ' is not active.', ['water_account_no']);
                return null;
            }
            $holder = trim($w['lastname'] . ', ' . $w['firstname'], ', ');
            $owner = $w['party_id'] ? DB::row('SELECT id, member_no, full_name FROM members WHERE party_id = :p ORDER BY id LIMIT 1', ['p' => $w['party_id']]) : null;
            if ($existing) {
                if (!$owner || (int)$owner['id'] !== (int)$existing['id']) {
                    $err($mid, 'Water-service account ' . $no . ' is ' . ($owner ? 'member ' . $owner['member_no'] . ' ' . $owner['full_name'] . '\'s' : $holder . '\'s, not the member\'s') . '.', ['water_account_no']);
                    return null;
                }
            } else {
                if ($owner) {
                    $err($mid, 'Water-service account ' . $no . ' is member ' . $owner['member_no'] . ' (' . $owner['full_name'] . ')\'s already: put the member no. ' . $owner['member_no'] . ' on this line to add the accounts to that member.', ['water_account_no']);
                    return null;
                }
                $lastFirst = trim(trim((string)($mv['last_name'] ?? '')) . ', ' . trim((string)($mv['first_name'] ?? '')), ', ');
                if ($lastFirst !== '' && !CsvReview::nameMatches($lastFirst, (string)$w['lastname'], (string)$w['firstname'], $holder)) {
                    $err($mid, 'Water-service account ' . $no . ' is in the name of ' . $holder . ', not ' . $lastFirst . '.', ['water_account_no', 'last_name', 'first_name']);
                    return null;
                }
                $note($mid, 'Becomes the same person as the holder of water-service account ' . $no . ' (' . $holder . ').');
            }
            $first = DB::value("SELECT MIN(line_no) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status IN ('pending', 'saved') AND key_water = :k AND id <> :id",
                ['b' => $b['id'], 'k' => mb_strtolower($no), 'id' => $mid]);
            if ($first && (int)$first < (int)$ml['line_no']) {
                $err($mid, 'Water-service account ' . $no . ' is also on line ' . $first . ' of the file (another member).', ['water_account_no']);
                return null;
            }
            return ['no' => (string)($w['account_number'] ?: $w['pkey']), 'virtual' => false, 'pkey' => (int)$w['pkey'], 'party_id' => $w['party_id'] ? (int)$w['party_id'] : null,
                'member_party' => (bool)$owner, 'open' => null];
        }
        if ($rc !== '' || $pipe !== '') {
            $open = self::waterOpenFields($mv, $err, $mid);
            if (!$open) {
                return null;
            }
            if ($existing && MemberDefaults::waterAccounts($existing)) {
                $err($mid, 'Member ' . $existing['member_no'] . ' has a water-service account already: give its account no. instead of a rate code and pipe size.', ['water_rate_code']);
                return null;
            }
            $note($mid, 'A water-service account is opened for the member (' . $open['label'] . ').');
            return ['no' => 'to open', 'virtual' => true, 'pkey' => 0, 'party_id' => null, 'member_party' => false, 'open' => $open];
        }
        if ($existing) {
            $accounts = MemberDefaults::waterAccounts($existing);
            if (count($accounts) === 1) {
                $w = $accounts[0];
                return ['no' => (string)($w['account_number'] ?: $w['pkey']), 'virtual' => false, 'pkey' => (int)$w['pkey'], 'party_id' => (int)$existing['party_id'], 'member_party' => true, 'open' => null];
            }
            if (count($accounts) > 1) {
                $note($mid, 'Member ' . $existing['member_no'] . ' has ' . count($accounts) . ' water-service accounts: give the water account no. of the bill balances.');
            }
        }
        return null;
    }

    /** The water-service account to open: the rate code, pipe size, zone, sequence and connection date checked */
    private static function waterOpenFields(array $mv, callable $err, int $mid): ?array
    {
        $rcRaw = trim((string)($mv['water_rate_code'] ?? ''));
        $pipeRaw = trim((string)($mv['water_pipe_size'] ?? ''));
        $ok = true;
        $rc = null;
        if ($rcRaw === '') {
            $err($mid, 'Enter the water rate code of the account to open.', ['water_rate_code']);
            $ok = false;
        } else {
            $hits = DB::all('SELECT rc_id, rc_code, rc_name FROM rate_code WHERE rc_id = :id OR LOWER(rc_code) = LOWER(:c) OR LOWER(rc_name) = LOWER(:c2) OR LOWER(CONCAT(rc_code, \' \', rc_name)) = LOWER(:c3)',
                ['id' => ctype_digit($rcRaw) ? (int)$rcRaw : -1, 'c' => $rcRaw, 'c2' => $rcRaw, 'c3' => $rcRaw]);
            if (count($hits) === 1) {
                $rc = $hits[0];
            } elseif ($hits) {
                $err($mid, 'Rate code "' . $rcRaw . '" is ' . count($hits) . ' rate codes (' . implode('; ', array_map(fn($h) => $h['rc_code'] . ' ' . $h['rc_name'], $hits)) . '): write its name.', ['water_rate_code']);
                $ok = false;
            } else {
                $err($mid, 'No rate code "' . $rcRaw . '" (Rate Codes).', ['water_rate_code']);
                $ok = false;
            }
        }
        $pipe = null;
        if ($pipeRaw === '') {
            $err($mid, 'Enter the pipe size of the water-service account to open.', ['water_pipe_size']);
            $ok = false;
        } else {
            $pipe = DB::row('SELECT * FROM water_pipe_sizes WHERE LOWER(size_code) = LOWER(:c) OR LOWER(size_label) = LOWER(:c2) OR pipe_size_id = :id ORDER BY pipe_size_id LIMIT 1',
                ['c' => $pipeRaw, 'c2' => $pipeRaw, 'id' => ctype_digit($pipeRaw) ? (int)$pipeRaw : -1]);
            if (!$pipe || (int)$pipe['is_active'] !== 1) {
                $err($mid, 'No active pipe size "' . $pipeRaw . '" (Water Meter Rates).', ['water_pipe_size']);
                $ok = false;
            }
        }
        if ($ok && $rc && $pipe) {
            try {
                $pdo = DB::pdo();
                water_rate_validate_tiers(water_rate_get_rates_for_configuration($pdo, (int)$rc['rc_id'], (int)$pipe['pipe_size_id']), true, false);
            } catch (Throwable $e) {
                $err($mid, 'Rate code ' . $rc['rc_code'] . ' with pipe size ' . $pipe['size_code'] . ': ' . $e->getMessage() . ' (a complete tariff on Water Meter Rates).', ['water_rate_code', 'water_pipe_size']);
                $ok = false;
            }
        }
        $zone = trim((string)($mv['water_zone'] ?? ''));
        if ($zone !== '' && !ctype_digit($zone)) {
            $err($mid, 'The water zone is a number.', ['water_zone']);
            $ok = false;
        }
        $seq = trim((string)($mv['water_sequence'] ?? ''));
        if ($seq !== '' && (mb_strlen($seq) > 50 || !preg_match('~^[A-Za-z0-9 ._/-]+$~', $seq))) {
            $err($mid, 'The meter sequence may have up to 50 letters, numbers, spaces, periods, underscores, slashes and hyphens.', ['water_sequence']);
            $ok = false;
        }
        $date = trim((string)($mv['water_connection_date'] ?? ''));
        $conn = $date === '' ? null : CsvReview::date($date);
        if ($date !== '' && (!$conn || $conn > today())) {
            $err($mid, 'The connection date "' . $date . '" is not a date before today.', ['water_connection_date']);
            $ok = false;
        }
        return $ok ? ['rc_id' => (int)$rc['rc_id'], 'pipe_size_id' => (int)$pipe['pipe_size_id'], 'zone' => $zone !== '' ? (int)$zone : null, 'sequence' => $seq !== '' ? $seq : null,
            'connection_date' => $conn, 'label' => 'rate code ' . trim($rc['rc_code'] . ' ' . $rc['rc_name']) . ', pipe ' . $pipe['size_label']] : null;
    }

    /**
     * The fixed share capital billing of the member's line: its amount, frequency, posting day, start date and term,
     * and the share capital account it bills. Returns ['amount', 'frequency', 'day', 'start', 'term', 'account_no',
     * 'sav_line' (the savings line of the account, null for an account on file), 'sav_account_id'] or null.
     */
    private static function checkFsc(array $ml, array $mv, ?array $existing, array $scAccounts, array $o, callable $err): ?array
    {
        $mid = (int)$ml['id'];
        $given = array_filter(['fsc_amount', 'fsc_frequency', 'fsc_posting_day', 'fsc_start_date', 'fsc_term_days'], fn($k) => trim((string)($mv[$k] ?? '')) !== '');
        if (!$given) {
            return null;
        }
        if (!ShareCapitalBilling::ready()) {
            $err($mid, 'Fixed Share Capital Billing is not installed on this database.', ['fsc_amount']);
            return null;
        }
        $ok = true;
        $amount = CsvReview::amount((string)($mv['fsc_amount'] ?? ''));
        if (trim((string)($mv['fsc_amount'] ?? '')) === '') {
            $err($mid, 'Enter the fixed share capital amount (the other fixed share capital columns need it).', ['fsc_amount']);
            $ok = false;
        } elseif ($amount === null || !Dec::isPos($amount) || Dec::cmp($amount, ShareCapitalBilling::MAX_AMOUNT) > 0) {
            $err($mid, 'The fixed share capital amount "' . $mv['fsc_amount'] . '" is not an amount above zero.', ['fsc_amount']);
            $ok = false;
        }
        $freqRaw = trim((string)($mv['fsc_frequency'] ?? ''));
        $freq = $freqRaw === '' ? 'monthly' : ShareCapitalBilling::frequencyKey($freqRaw);
        if ($freq === null) {
            $err($mid, 'The fixed share capital frequency is Monthly, Quarterly, Semi-annually or Annually.', ['fsc_frequency']);
            $ok = false;
        }
        $dayRaw = trim((string)($mv['fsc_posting_day'] ?? ''));
        $day = $dayRaw === '' ? 1 : (ctype_digit($dayRaw) ? (int)$dayRaw : 0);
        if ($day < 1 || $day > 31) {
            $err($mid, 'The fixed share capital posting day is a day of the month from 1 to 31.', ['fsc_posting_day']);
            $ok = false;
        }
        $cut = CsvReview::date((string)$o['cut_off_date']) ?? today();
        $startRaw = trim((string)($mv['fsc_start_date'] ?? ''));
        $start = $startRaw === '' ? date('Y-m-d', strtotime($cut . ' +1 day')) : CsvReview::date($startRaw);
        if (!$start) {
            $err($mid, 'The fixed share capital start date "' . $startRaw . '" is not a date.', ['fsc_start_date']);
            $ok = false;
        }
        $termRaw = trim((string)($mv['fsc_term_days'] ?? ''));
        $term = null;
        if ($termRaw !== '' && !in_array(strtolower($termRaw), ['open', 'none', '-'], true)) {
            if (preg_match('/^\d+$/', $termRaw) && (int)$termRaw <= 3650) {
                $term = (int)$termRaw;
            } else {
                $err($mid, 'The fixed share capital term is a whole number of days from 0 to 3650 (or blank: no due date).', ['fsc_term_days']);
                $ok = false;
            }
        }
        // the share capital account it bills: the member's share capital account of the file, or on file
        $accountNo = null;
        $savLine = null;
        $accountId = null;
        if (count($scAccounts) === 1) {
            [$savLine, $r] = $scAccounts[0];
            $accountNo = trim((string)$r['account_no']) !== '' ? trim((string)$r['account_no']) : 'opened on line ' . $savLine['line_no'];
        } elseif (count($scAccounts) > 1) {
            $err($mid, 'The member has ' . count($scAccounts) . ' share capital accounts among the savings: fixed share capital billing is set up for one; set it up on Fixed Share Capital Billing after the import.', ['fsc_amount']);
            $ok = false;
        } elseif ($existing) {
            $open = ShareCapitalBilling::openAccounts((int)$existing['id']);
            if (count($open) !== 1) {
                $err($mid, $open ? 'Member ' . $existing['member_no'] . ' has ' . count($open) . ' share capital accounts: set the fixed billing up on Fixed Share Capital Billing.' : 'Member ' . $existing['member_no'] . ' has no open share capital account: add it among the savings.', ['fsc_amount']);
                $ok = false;
            } else {
                $accountNo = (string)$open[0]['account_no'];
                $accountId = (int)$open[0]['id'];
                if (DB::value("SELECT plan_id FROM share_capital_plans WHERE sav_account_id = :a AND status <> 'deleted'", ['a' => $accountId])) {
                    $err($mid, 'Share capital account ' . $accountNo . ' is on fixed billing already: leave the fixed share capital columns blank.', ['fsc_amount']);
                    $ok = false;
                }
            }
        } else {
            $err($mid, 'Fixed share capital billing bills a share capital account: add the member\'s share capital account among the savings (its savings code is a share capital product).', ['fsc_amount']);
            $ok = false;
        }
        return $ok ? ['amount' => $amount, 'frequency' => $freq, 'day' => $day, 'start' => $start, 'term' => $term,
            'account_no' => $accountNo, 'sav_line' => $savLine ? (int)$savLine['id'] : null, 'sav_account_id' => $accountId] : null;
    }

    // ================================================================== the review

    /** What the review shows above the lines: the lines by kind, their problems, totals, the state of the check and saving */
    public static function summary(array $b): array
    {
        $kinds = [];
        foreach (self::KINDS as $k => $_) {
            $kinds[$k] = ['lines' => 0, 'bad' => 0, 'unchecked' => 0, 'removed' => 0, 'saved' => 0, 'total' => '0.00'];
        }
        foreach (DB::all("SELECT kind, status, COUNT(*) n, SUM(bad) bad, SUM(checked = 0) unchecked, SUM(amount) total FROM coop_import_lines WHERE batch_id = :b GROUP BY kind, status",
            ['b' => $b['id']]) as $r) {
            $k = $r['kind'];
            if (!isset($kinds[$k])) {
                continue;
            }
            if ($r['status'] === 'pending') {
                $kinds[$k]['lines'] = (int)$r['n'];
                $kinds[$k]['bad'] = (int)$r['bad'];
                $kinds[$k]['unchecked'] = (int)$r['unchecked'];
                $kinds[$k]['total'] = Dec::fix(Dec::of($r['total'] ?? '0'));
            } elseif ($r['status'] === 'removed') {
                $kinds[$k]['removed'] = (int)$r['n'];
            } elseif ($r['status'] === 'saved') {
                $kinds[$k]['saved'] = (int)$r['n'];
            }
        }
        $general = self::general($b, $kinds);
        $bad = array_sum(array_column($kinds, 'bad'));
        $unchecked = array_sum(array_column($kinds, 'unchecked'));
        $pending = array_sum(array_column($kinds, 'lines'));
        return ['kinds' => $kinds, 'bad' => $bad, 'unchecked' => $unchecked, 'pending' => $pending, 'general' => $general,
            'ready' => $pending > 0 && !$bad && !$unchecked && !$general && $b['status'] !== 'saved', 'status' => $b['status'],
            'saved_members' => $kinds['member']['saved'], 'members' => $kinds['member']['lines'] + $kinds['member']['saved']];
    }

    /** What stops the whole file, said above the lines */
    private static function general(array $b, array $kinds): array
    {
        $o = $b['options'];
        $out = [];
        if ($kinds['savings']['lines'] && trim((string)$o['savings_txn_code']) === '') {
            $out[] = 'Choose the savings transaction code of the balances (Values for the blank cells).';
        }
        if (($kinds['savings']['lines'] || $kinds['loan']['lines'] || $kinds['bill']['lines']) && !valid_date((string)$o['cut_off_date'])) {
            $out[] = 'Enter the cut-off date (Values for the blank cells).';
        } elseif (valid_date((string)$o['cut_off_date']) && $o['cut_off_date'] > today()) {
            $out[] = 'The cut-off date cannot be after today.';
        }
        if ($kinds['loan']['lines'] && trim((string)$o['balancing_account']) === '') {
            $out[] = 'Choose the balancing GL account of the loans (Values for the blank cells).';
        }
        if ($kinds['bill']['lines'] && function_exists('bbu_class_problem')) {
            $journal = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'bill' AND status = 'pending' AND (key_no IS NULL OR key_no <> 'share_capital')", ['b' => $b['id']]);
            if ($journal && ($p = bbu_class_problem($o['class_id'] ?? null))) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * A page of lines of one kind: ['lines' => [...], 'total' => matching lines, 'page', 'pages']. $f: bad (only the
     * lines in red), q (words in the line), status (pending, removed, saved)
     */
    public static function lines(array $b, string $kind, array $f, int $page): array
    {
        if (!isset(self::KINDS[$kind])) {
            fail('Choose what to show.');
        }
        $where = 'l.batch_id = :b AND l.kind = :k';
        $p = ['b' => $b['id'], 'k' => $kind];
        $status = in_array($f['status'] ?? 'pending', ['pending', 'removed', 'saved'], true) ? (string)($f['status'] ?? 'pending') : 'pending';
        $where .= ' AND l.status = :s';
        $p['s'] = $status;
        if (!empty($f['bad'])) {
            $where .= ' AND l.bad = 1';
        }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where .= ' AND (l.vals LIKE :q OR l.info LIKE :q2 OR m.vals LIKE :q3 OR l.line_no = :qn OR l.file_row = :qr)';
            $p['q'] = $p['q2'] = $p['q3'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $p['qn'] = $p['qr'] = ctype_digit($q) ? (int)$q : -1;
        }
        $total = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines l LEFT JOIN coop_import_lines m ON m.id = l.member_line_id WHERE $where", $p);
        $pages = max(1, (int)ceil($total / self::PAGE_LINES));
        $page = max(1, min($page, $pages));
        $rows = DB::all("SELECT l.*, m.vals AS member_vals, m.line_no AS member_line_no, m.status AS member_status,
                                (SELECT o.id FROM members o WHERE l.kind = 'member' AND l.key_no IS NOT NULL AND o.member_no = l.key_no LIMIT 1) AS on_file,
                                (SELECT o.full_name FROM members o WHERE l.kind <> 'member' AND m.key_no IS NOT NULL AND o.member_no = m.key_no LIMIT 1) AS member_on_file
                           FROM coop_import_lines l LEFT JOIN coop_import_lines m ON m.id = l.member_line_id
                          WHERE $where ORDER BY l.line_no LIMIT " . self::PAGE_LINES . ' OFFSET ' . (($page - 1) * self::PAGE_LINES), $p);
        return ['lines' => array_map([self::class, 'view'], $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** A line as the review shows it */
    public static function view(array $l): array
    {
        $mv = json_decode((string)($l['member_vals'] ?? '{}'), true) ?: [];
        $member = '';
        if ($l['kind'] !== 'member') {
            $name = trim(trim((string)($mv['last_name'] ?? '')) . ', ' . trim((string)($mv['first_name'] ?? '')), ', ');
            if ($name === '' && !empty($l['member_on_file'])) {
                $name = (string)$l['member_on_file'];
            }
            $member = ($name !== '' ? $name : 'Member') . (($mv['member_no'] ?? '') !== '' ? ' (' . $mv['member_no'] . ')' : '') . ', line ' . (int)($l['member_line_no'] ?? 0);
        }
        return ['id' => (int)$l['id'], 'kind' => $l['kind'], 'line' => (int)$l['line_no'], 'row' => (int)$l['file_row'], 'member' => $member,
            'member_line' => (int)($l['member_line_id'] ?? 0), 'values' => json_decode((string)$l['vals'], true) ?: [],
            'errors' => json_decode((string)$l['errors'], true) ?: [], 'notes' => json_decode((string)$l['notes'], true) ?: [], 'info' => (string)$l['info'],
            'cells' => json_decode((string)$l['cells'], true) ?: [], 'checked' => (int)$l['checked'] === 1, 'status' => $l['status'], 'saved_ref' => (string)$l['saved_ref'],
            'on_file' => $l['kind'] === 'member' && !empty($l['on_file']) && $l['status'] === 'pending'];
    }

    /** The columns the review shows for a kind: the columns of the file, and those the kind needs */
    public static function columns(array $b, string $kind): array
    {
        $fields = self::fields();
        $sections = match ($kind) {
            'member' => ['member', 'water', 'fsc'],
            default => [$kind],
        };
        $mapped = [];
        foreach ($b['mapping'] as $m) {
            if (($m['field'] ?? '') !== '') {
                $mapped[$m['field']] = true;
            }
        }
        // the balance columns of one row per member show as bill lines
        foreach (['recurring_balance', 'installment_balance', 'one_time_balance', 'share_capital_balance'] as $k) {
            if (isset($mapped[$k])) {
                $mapped['bill_type'] = $mapped['bill_balance'] = $mapped['bill_date'] = true;
                if ($k !== 'share_capital_balance') {
                    $mapped['bill_charge_code'] = true;
                }
                if ($k === 'installment_balance') {
                    $mapped['bill_installments'] = $mapped['bill_payment_mode'] = $mapped['bill_due_date'] = true;
                }
            }
        }
        if (isset($mapped['installments']) || isset($mapped['payment_mode'])) {
            $mapped['bill_installments'] = $mapped['bill_payment_mode'] = true;
        }
        $out = [];
        foreach ($fields as $k => $c) {
            if (!in_array($c['section'], $sections, true) || !empty($c['file'])) {
                continue;
            }
            if (isset($mapped[$k]) || ($c['req'] && $c['section'] === $kind) || ($kind === 'member' && $c['req'] && $c['section'] === 'member')) {
                $out[] = ['key' => $k, 'label' => $c['label'], 'type' => $c['type'], 'size' => $c['size'], 'req' => $c['req'], 'more' => $c['more'], 'hint' => (string)($c['hint'] ?? ''),
                    'options' => isset($c['options']) ? array_map(fn($v, $lbl) => [(string)$v, (string)$lbl], array_keys($c['options']), array_values($c['options'])) : null];
            }
        }
        return $out;
    }

    // ================================================================== corrections

    /** A value corrected in the review: kept on the line, and the member checked again (with the members it touches) */
    public static function edit(array $b, int $lineId, string $field, string $value): array
    {
        self::editable($b);
        $l = DB::row('SELECT * FROM coop_import_lines WHERE id = :id AND batch_id = :b', ['id' => $lineId, 'b' => $b['id']]);
        if (!$l || $l['status'] !== 'pending') {
            fail('That line is not in the review any more.');
        }
        $fields = self::fields();
        $sections = $l['kind'] === 'member' ? ['member', 'water', 'fsc'] : [$l['kind']];
        if (!isset($fields[$field]) || !in_array($fields[$field]['section'], $sections, true) || !empty($fields[$field]['file'])) {
            fail('That column is not a column of the line.');
        }
        $value = mb_substr(trim($value), 0, 500);
        $vals = json_decode((string)$l['vals'], true) ?: [];
        $before = self::keys($l['kind'], $vals);
        if ($value === '') {
            unset($vals[$field]);
        } else {
            $vals[$field] = $value;
        }
        [$keyNo, $keyPerson, $keyWater, $amount] = self::keys($l['kind'], $vals);
        DB::update('coop_import_lines', ['vals' => json_encode($vals, JSON_UNESCAPED_UNICODE), 'key_no' => $keyNo, 'key_person' => $keyPerson, 'key_water' => $keyWater, 'amount' => $amount, 'checked' => 0],
            'id = :id', ['id' => $lineId]);
        $members = [(int)$l['member_line_id']];
        // the members the old and the new value meet (the same member no., person, account no., loan no. or water account)
        // (a bill balance meets no other member: its key is its billing type)
        foreach ($l['kind'] === 'bill' ? [] : [[$before[0], $keyNo, 'key_no'], [$before[1], $keyPerson, 'key_person'], [$before[2], $keyWater, 'key_water']] as [$old, $new, $col]) {
            $vals2 = array_values(array_filter(array_unique([$old, $new]), fn($v) => $v !== null && $v !== ''));
            if ($vals2) {
                [$in, $p] = DB::in('k', $vals2);
                foreach (DB::column("SELECT DISTINCT member_line_id FROM coop_import_lines WHERE batch_id = :b AND kind = :kind AND status = 'pending' AND $col IN $in",
                    ['b' => $b['id'], 'kind' => $l['kind']] + $p) as $m) {
                    $members[] = (int)$m;
                }
            }
        }
        self::checkMembers($b, array_values(array_unique($members)));
        return self::touched($b, array_values(array_unique($members)));
    }

    /** Removes a line (a member line with its savings, loans and bill balances) from the import */
    public static function remove(array $b, int $lineId): array
    {
        self::editable($b);
        $l = DB::row('SELECT * FROM coop_import_lines WHERE id = :id AND batch_id = :b', ['id' => $lineId, 'b' => $b['id']]);
        if (!$l || $l['status'] !== 'pending') {
            fail('That line is not in the review any more.');
        }
        $ids = $l['kind'] === 'member'
            ? array_map('intval', DB::column("SELECT id FROM coop_import_lines WHERE batch_id = :b AND member_line_id = :m AND status = 'pending'", ['b' => $b['id'], 'm' => $l['id']]))
            : [(int)$l['id']];
        DB::run("UPDATE coop_import_lines SET status = 'removed' WHERE id IN (" . implode(',', $ids) . ')');
        $members = $l['kind'] === 'member' ? self::meeting($b, $ids) : [(int)$l['member_line_id']];
        self::checkMembers($b, $members);
        return ['removed' => $ids] + self::touched($b, $members);
    }

    /** Puts removed lines back */
    public static function restore(array $b, array $lineIds): array
    {
        self::editable($b);
        $ids = array_values(array_filter(array_map('intval', $lineIds)));
        if (!$ids) {
            fail('Nothing to put back.');
        }
        $rows = DB::all("SELECT * FROM coop_import_lines WHERE batch_id = :b AND status = 'removed' AND id IN (" . implode(',', $ids) . ')', ['b' => $b['id']]);
        if (!$rows) {
            fail('Those lines are not removed.');
        }
        // a member comes back with its lines; a line comes back with its member
        $members = array_merge([0], array_map(fn($r) => (int)$r['member_line_id'], $rows));
        $memberLines = array_merge([0], array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['kind'] === 'member')));
        $rows = DB::all("SELECT * FROM coop_import_lines WHERE batch_id = :b AND status = 'removed' AND (id IN (" . implode(',', $ids) . ')'
            . " OR (kind <> 'member' AND member_line_id IN (" . implode(',', $memberLines) . '))'
            . " OR (kind = 'member' AND id IN (" . implode(',', $members) . ')))', ['b' => $b['id']]);
        DB::run("UPDATE coop_import_lines SET status = 'pending', checked = 0 WHERE id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')');
        $members = array_values(array_unique(array_merge(array_map(fn($r) => (int)$r['member_line_id'], $rows), self::meeting($b, array_map(fn($r) => (int)$r['id'], $rows)))));
        self::checkMembers($b, $members);
        return ['restored' => array_map(fn($r) => (int)$r['id'], $rows)] + self::touched($b, $members);
    }

    /** The members whose lines share a key with these lines (checked again when these come or go) */
    private static function meeting(array $b, array $ids): array
    {
        $out = [];
        foreach (DB::all('SELECT kind, key_no, key_person, key_water, member_line_id FROM coop_import_lines WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $r) {
            $out[] = (int)$r['member_line_id'];
            foreach ($r['kind'] === 'bill' ? [] : ['key_no', 'key_person', 'key_water'] as $col) {
                if ($r[$col] !== null && $r[$col] !== '') {
                    foreach (DB::column("SELECT DISTINCT member_line_id FROM coop_import_lines WHERE batch_id = :b AND kind = :k AND status = 'pending' AND $col = :v",
                        ['b' => $b['id'], 'k' => $r['kind'], 'v' => $r[$col]]) as $m) {
                        $out[] = (int)$m;
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** The lines of the members just checked, and the summary */
    private static function touched(array $b, array $members): array
    {
        $members = array_values(array_filter($members));
        $rows = $members ? DB::all("SELECT l.*, m.vals AS member_vals, m.line_no AS member_line_no, m.status AS member_status,
                                           (SELECT o.id FROM members o WHERE l.kind = 'member' AND l.key_no IS NOT NULL AND o.member_no = l.key_no LIMIT 1) AS on_file,
                                           (SELECT o.full_name FROM members o WHERE l.kind <> 'member' AND m.key_no IS NOT NULL AND o.member_no = m.key_no LIMIT 1) AS member_on_file
                                      FROM coop_import_lines l LEFT JOIN coop_import_lines m ON m.id = l.member_line_id
                                    WHERE l.batch_id = :b AND l.member_line_id IN (" . implode(',', $members) . ') ORDER BY l.line_no', ['b' => $b['id']]) : [];
        $b = self::batch((int)$b['id']);
        return ['lines' => array_map([self::class, 'view'], $rows), 'summary' => self::summary($b)];
    }

    private static function editable(array $b): void
    {
        if (!in_array($b['status'], ['review', 'saving'], true)) {
            fail('This file is ' . $b['status'] . ': its lines cannot be changed.');
        }
    }

    /** Cancels a file that was read and not saved (its lines are kept, the file leaves the list) */
    public static function cancel(array $b): void
    {
        if (!in_array($b['status'], ['reading', 'review'], true)) {
            fail('A file that is being saved, or was saved, cannot be cancelled.');
        }
        DB::update('coop_import_batches', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
        DB::run('DELETE FROM coop_import_raw WHERE batch_id = :b', ['b' => $b['id']]);
        Audit::log('cancel', 'coop', 'consolidated_import', (int)$b['id'], (string)$b['batch_no'] . ' ' . $b['file_name'], ['page_key' => self::PAGE]);
    }

    // ================================================================== saving

    /** The rights saving the lines of a file takes: [what is missing] */
    public static function missingRights(array $b): array
    {
        $kinds = [];
        foreach (DB::all("SELECT kind, COUNT(*) n FROM coop_import_lines WHERE batch_id = :b AND status = 'pending' GROUP BY kind", ['b' => $b['id']]) as $r) {
            $kinds[$r['kind']] = (int)$r['n'];
        }
        $missing = [];
        if (!Access::can(self::PAGE, 'create')) {
            $missing[] = 'Create on Consolidated Import';
        }
        if (!empty($kinds['member']) && !Access::can('coop.member', 'approve')) {
            $missing[] = 'Approve on Member Information (the members are made Active)';
        }
        if (!empty($kinds['savings']) && !Access::can('sav.accounts', 'approve')) {
            $missing[] = 'Approve on the Savings Account Center';
        }
        if (!empty($kinds['loan']) && !Access::can('loan.accounts', 'approve')) {
            $missing[] = 'Approve on the Loan Accounts Center';
        }
        if (!empty($kinds['bill']) && !Access::can('water.bill_balance_upload', 'create')) {
            $missing[] = 'Create on Upload Bill Balances';
        }
        $has = fn(string $key) => (bool)DB::value("SELECT id FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending' AND vals LIKE :k LIMIT 1",
            ['b' => $b['id'], 'k' => '%"' . $key . '":%']);
        if ($has('water_rate_code') && !Access::can('water.member_list', 'create')) {
            $missing[] = 'Create on Water Service Accounts (water-service accounts are opened)';
        }
        if ($has('fsc_amount') && !Access::can('water.share_capital_billing', 'create')) {
            $missing[] = 'Create on Fixed Share Capital Billing';
        }
        return $missing;
    }

    /**
     * Saves the next members of the file with their lines, SAVE_MEMBERS at a time, each group in one transaction, for at
     * most STEP_SECONDS. Returns ['done', 'saved' => members saved so far, 'members' => members in all, 'last' => what
     * the step made].
     */
    public static function saveStep(array $b): array
    {
        if ($b['status'] === 'saved') {
            fail('This file was saved already.');
        }
        $s = self::summary($b);
        if ($s['general'] || $s['bad'] || $s['unchecked']) {
            fail(trim(($s['unchecked'] ? 'Some lines were changed and not checked yet: check the file again. ' : ($s['bad'] ? number_format($s['bad']) . ' line(s) have a problem: correct them or remove them, then save. ' : ''))
                . implode(' ', $s['general'])));
        }
        if ($missing = self::missingRights($b)) {
            fail('Saving this file takes these rights: ' . implode('; ', $missing) . '.');
        }
        @set_time_limit(600);
        if ($b['status'] === 'review') {
            DB::update('coop_import_batches', ['status' => 'saving', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
            $b['status'] = 'saving';
            Audit::log('import', 'coop', 'consolidated_import', (int)$b['id'], (string)$b['batch_no'] . ' ' . $b['file_name'] . ': saving started', ['page_key' => self::PAGE]);
        }
        $start = microtime(true);
        $made = ['members' => 0, 'savings' => 0, 'loans' => 0, 'bills' => 0, 'water' => 0, 'fsc' => 0];
        do {
            $ids = array_map('intval', DB::column("SELECT id FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending' ORDER BY line_no LIMIT " . self::SAVE_MEMBERS,
                ['b' => $b['id']]));
            if (!$ids) {
                break;
            }
            $r = self::saveMembers($b, $ids);
            foreach ($r as $k => $n) {
                $made[$k] += $n;
            }
        } while (microtime(true) - $start < self::STEP_SECONDS);
        $left = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'pending'", ['b' => $b['id']]);
        $saved = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'saved'", ['b' => $b['id']]);
        if (!$left) {
            DB::update('coop_import_batches', ['status' => 'saved', 'saved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
            DB::run('DELETE FROM coop_import_raw WHERE batch_id = :b', ['b' => $b['id']]);
            $sum = self::summary(self::batch((int)$b['id']));
            Audit::log('import', 'coop', 'consolidated_import', (int)$b['id'], (string)$b['batch_no'] . ' ' . $b['file_name'] . ': ' . $sum['kinds']['member']['saved'] . ' member(s), '
                . $sum['kinds']['savings']['saved'] . ' savings account(s), ' . $sum['kinds']['loan']['saved'] . ' loan(s), ' . $sum['kinds']['bill']['saved'] . ' bill balance(s) saved', ['page_key' => self::PAGE]);
        }
        return ['done' => !$left, 'saved' => $saved, 'members' => $saved + $left, 'last' => $made];
    }

    /**
     * Saves members and their lines in one transaction, with the saving of each import: the members (Active), their
     * water-service accounts, savings accounts with their balances, fixed share capital billings, loans, and bill
     * balances. Every line is checked again first; a problem saves none of them.
     */
    private static function saveMembers(array $b, array $memberIds): array
    {
        self::loadWater();
        CsvImports::$memo = true;
        LoanImport::$memo = true;
        $o = $b['options'];
        $file = (string)$b['batch_no'] . ' ' . $b['file_name'];
        $made = ['members' => 0, 'savings' => 0, 'loans' => 0, 'bills' => 0, 'water' => 0, 'fsc' => 0];
        $refs = [];          // line id => what it became
        $problem = null;
        try {
            DB::tx(function () use ($b, $memberIds, $o, $file, &$made, &$refs) {
                $all = DB::all("SELECT * FROM coop_import_lines WHERE batch_id = :b AND status = 'pending' AND member_line_id IN (" . implode(',', $memberIds) . ') ORDER BY line_no FOR UPDATE',
                    ['b' => $b['id']]);
                $groups = [];
                foreach ($all as $l) {
                    $l['v'] = json_decode((string)$l['vals'], true) ?: [];
                    $groups[(int)$l['member_line_id']][$l['kind']][] = $l;
                }
                // checked again, as they are now
                $ctx = [];
                foreach ($memberIds as $mid) {
                    if (empty($groups[$mid]['member'][0])) {
                        continue;
                    }
                    $c = null;
                    $res = self::checkGroup($b, $groups[$mid], $o, $c);
                    foreach ($res as $id => $r) {
                        if ($r['errors']) {
                            throw new UserError('Line ' . DB::value('SELECT line_no FROM coop_import_lines WHERE id = :id', ['id' => $id]) . ': ' . $r['errors'][0]);
                        }
                    }
                    $ctx[$mid] = $c;
                }
                // 1. the members (Active), a member of a water-service account the same person as its holder
                $newRows = [];
                foreach ($ctx as $mid => $c) {
                    if ($c['existing']) {
                        continue;
                    }
                    $mv = $groups[$mid]['member'][0]['v'];
                    $row = ['_line' => (int)$groups[$mid]['member'][0]['line_no']];
                    foreach (CsvImports::cols('members') as $k => $_) {
                        $row[$k] = trim((string)($mv[$k] ?? ''));
                    }
                    if ($c['water'] && !$c['water']['virtual'] && $c['water']['party_id']) {
                        $row['_link_party_id'] = $c['water']['party_id'];
                    }
                    $newRows[$mid] = $row;
                }
                $memberOf = [];      // member line => the member on file
                if ($newRows) {
                    $r = CsvImports::post('members', array_values($newRows), [], $file, self::PAGE);
                    foreach (array_keys($newRows) as $i => $mid) {
                        $memberOf[$mid] = DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $r['refs'][$i]]);
                        $refs[$mid] = 'Member ' . $r['refs'][$i];
                        $made['members']++;
                    }
                }
                foreach ($ctx as $mid => $c) {
                    if ($c['existing']) {
                        $memberOf[$mid] = $c['existing'];
                        $refs[$mid] = 'Member ' . $c['existing']['member_no'] . ' (on file)';
                    }
                }
                // 2. the water-service accounts: opened, or the account named (the member's from now on)
                $waterNo = [];
                foreach ($ctx as $mid => $c) {
                    if (!$c['water']) {
                        continue;
                    }
                    $m = $memberOf[$mid];
                    if ($c['water']['virtual']) {
                        $w = $c['water']['open'];
                        DB::update('members', ['water_rc_id' => $w['rc_id'], 'water_pipe_size_id' => $w['pipe_size_id'], 'water_zone' => $w['zone'], 'water_sequence' => $w['sequence'],
                            'water_connection_date' => $w['connection_date'], 'water_connection_status' => 'active'], 'id = :id', ['id' => $m['id']]);
                        $pkey = MemberDefaults::applyWater((int)$m['id'], self::PAGE);
                        $waterNo[$mid] = (string)(DB::value('SELECT account_number FROM water_accounts WHERE pkey = :p', ['p' => $pkey]) ?: $pkey);
                        $refs[$mid] .= ', water account ' . $waterNo[$mid] . ' opened';
                        $made['water']++;
                    } else {
                        $waterNo[$mid] = $c['water']['no'];
                        if (!$c['existing']) {
                            DB::update('members', ['water_account_id' => $c['water']['pkey']], 'id = :id', ['id' => $m['id']]);
                            $refs[$mid] .= ', water account ' . $waterNo[$mid];
                        }
                    }
                }
                // 3. savings: each account opened Active with its balance (one voucher per cut-off date and code)
                $savRows = [];
                $savIds = [];
                foreach ($ctx as $mid => $c) {
                    foreach ($groups[$mid]['savings'] ?? [] as $l) {
                        $savRows[] = self::savingsRow($l, (string)$memberOf[$mid]['member_no'], (string)$memberOf[$mid]['full_name']);
                        $savIds[] = (int)$l['id'];
                    }
                }
                $savNo = [];         // savings line => the account no. it became
                if ($savRows) {
                    $r = CsvImports::post('savings', $savRows, ['savings_txn_code' => $o['savings_txn_code'], 'cut_off_date' => $o['cut_off_date']], $file, self::PAGE);
                    foreach ($savIds as $i => $id) {
                        $savNo[$id] = (string)($r['refs'][$i] ?? '');
                        $refs[$id] = 'Account ' . $savNo[$id];
                        $made['savings']++;
                    }
                }
                // 4. fixed share capital billings
                foreach ($ctx as $mid => $c) {
                    if (!$c['fsc']) {
                        continue;
                    }
                    $f = $c['fsc'];
                    $accountId = $f['sav_account_id'];
                    if (!$accountId && $f['sav_line']) {
                        $accountId = (int)DB::value('SELECT id FROM sav_accounts WHERE account_no = :n', ['n' => $savNo[$f['sav_line']] ?? '']);
                    }
                    $planId = ShareCapitalBilling::save(['member_id' => (int)$memberOf[$mid]['id'], 'sav_account_id' => $accountId, 'frequency' => $f['frequency'], 'start_date' => $f['start'],
                        'posting_day' => $f['day'], 'amount' => $f['amount'], 'term_days' => $f['term'], 'batch_no' => (string)$b['batch_no']], null, self::PAGE);
                    $refs[$mid] .= ', fixed share capital billing';
                    $made['fsc'] += $planId ? 1 : 0;
                }
                // 5. loans: each made Active with its schedule and what was paid (one voucher per cut-off date and account)
                $loanRows = [];
                $loanIds = [];
                foreach ($ctx as $mid => $c) {
                    foreach ($groups[$mid]['loan'] ?? [] as $l) {
                        $r = ['_line' => (int)$l['line_no'], 'member_code' => $memberOf[$mid]['member_no'], 'member_name' => $memberOf[$mid]['full_name']];
                        foreach (self::LOAN_MAP as $k => $t) {
                            $r[$t] = trim((string)($l['v'][$k] ?? ''));
                        }
                        $loanRows[] = $r;
                        $loanIds[] = (int)$l['id'];
                    }
                }
                if ($loanRows) {
                    $r = LoanImport::post($loanRows, ['cut_off_date' => $o['cut_off_date'], 'balancing_account' => $o['balancing_account']], $file, self::PAGE);
                    foreach ($loanIds as $i => $id) {
                        $refs[$id] = 'Loan ' . ($r['refs'][$i] ?? '');
                        $made['loans']++;
                    }
                }
                // 6. bill balances: the bills of each billing type with their invoices (an upload of Upload Bill Balances)
                $billRows = [];
                $billIds = [];
                foreach ($ctx as $mid => $c) {
                    foreach ($groups[$mid]['bill'] ?? [] as $l) {
                        $r = ['_line' => (int)$l['line_no'], '_ref' => 'Row ' . $l['file_row'], 'account_no' => $waterNo[$mid] ?? '', 'member_name' => ''];
                        foreach (self::BILL_MAP as $k => $t) {
                            $r[$t] = trim((string)($l['v'][$k] ?? ''));
                        }
                        $billRows[] = $r;
                        $billIds[] = (int)$l['id'];
                    }
                }
                if ($billRows) {
                    try {
                        $r = DB::withRelaxed(fn() => bbu_post(DB::pdo(), $billRows, self::billCommon($o), $o['class_id'] ?? null, (int)Auth::id(), $file));
                    } catch (UserError $e) {
                        throw $e;
                    } catch (RuntimeException $e) {
                        throw new UserError($e->getMessage());
                    }
                    foreach ($billIds as $i => $id) {
                        $refs[$id] = $r['upload_no'] . ', line ' . $billRows[$i]['_line'];
                        $made['bills']++;
                    }
                }
                foreach ($refs as $id => $ref) {
                    DB::update('coop_import_lines', ['status' => 'saved', 'saved_ref' => mb_substr($ref, 0, 80)], 'id = :id', ['id' => $id]);
                }
                // every line of the members saved (a line with nothing to make is saved with its member)
                DB::run("UPDATE coop_import_lines SET status = 'saved' WHERE batch_id = :b AND status = 'pending' AND member_line_id IN (" . implode(',', $memberIds) . ')', ['b' => $b['id']]);
            });
        } catch (UserError $e) {
            $problem = $e->getMessage();
        }
        if ($problem !== null) {
            // nothing of these members was saved: their lines are checked again so the problem shows on them
            self::checkMembers($b, $memberIds);
            fail('Saving stopped: ' . $problem . ' Nothing of these ' . count($memberIds) . ' member(s) was saved; the members before them are saved. Correct the line, then save again to go on.');
        }
        DB::update('coop_import_batches', ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $b['id']]);
        return $made;
    }

    // ================================================================== templates

    /** Example rows of a template: wide (a member and accounts on one row) or long (the accounts on the rows under the member) */
    public static function samples(string $layout): array
    {
        $type = (string)(array_key_first(CsvImports::memberTypes()) ?? 'REG');
        $prod = array_keys(CsvImports::savProducts(['ordinary', 'special']));
        $sc = array_keys(CsvImports::savProducts(['share_capital']));
        $loan = DB::column('SELECT name FROM loan_products WHERE is_active = 1 ORDER BY sort_order, code');
        $cut = today();
        $water = (string)(DB::value("SELECT w.account_number FROM water_accounts w WHERE w.status = 'A' AND NOT EXISTS (SELECT 1 FROM members m WHERE m.party_id = w.party_id) ORDER BY w.pkey LIMIT 1") ?: '');
        $waterName = $water !== '' ? DB::row('SELECT lastname, firstname FROM water_accounts WHERE account_number = :n', ['n' => $water]) : null;
        $codes = [];
        try {
            foreach (DB::column('SELECT code FROM bill_codes ORDER BY code_id') as $c) {
                $codes[] = (string)$c;
            }
        } catch (Throwable $e) {
        }
        $fee = $codes[1] ?? 'CONNECTION FEE';
        $m1 = ['member_no' => '100301', 'last_name' => $waterName['lastname'] ?? 'Dela Cruz', 'first_name' => $waterName['firstname'] ?? 'Juan', 'middle_name' => 'Santos',
            'birth_date' => '1985-04-12', 'gender' => 'M', 'civil_status' => 'married', 'member_type' => $type, 'membership_date' => '2015-06-01', 'barangay' => 'Poblacion',
            'city' => 'San Isidro', 'province' => 'Nueva Ecija', 'mobile' => '09171234567', 'water_account_no' => $water];
        $m2 = ['member_no' => '100302', 'last_name' => 'Reyes', 'first_name' => 'Maria Luisa', 'middle_name' => '-', 'birth_date' => '03/28/1990', 'gender' => 'F', 'civil_status' => 'single',
            'member_type' => $type, 'membership_date' => '01/15/2019', 'barangay' => 'San Roque', 'city' => 'Cabanatuan City', 'province' => 'Nueva Ecija'];
        $sav1 = ['savings_code' => $prod[0] ?? '01', 'savings_balance' => '12500.00'];
        $sav2 = ['savings_code' => $sc[0] ?? '03', 'savings_balance' => '5000.00'];
        $ln = ['loan_name' => $loan[0] ?? 'RL', 'loan_principal' => '50000.00', 'loan_term' => '12 months', 'loan_mode' => 'Monthly', 'loan_amort_type' => 'Diminishing, equal amortization',
            'loan_rate' => '18', 'loan_release_date' => Coop::addMonths($cut, -7), 'loan_principal_paid' => '20000.00', 'loan_interest_paid' => '3500.00', 'loan_penalties_paid' => '0'];
        if ($layout === 'wide') {
            $row1 = $m1 + $sav1 + ['savings2_code' => $sav2['savings_code'], 'savings2_balance' => $sav2['savings_balance']] + $ln
                + ($water !== '' ? ['one_time_balance' => '1500.00', 'one_time_code' => $fee] : []);
            $row2 = $m2 + ['savings_code' => $sav2['savings_code'], 'savings_balance' => '2000.00'];
            return [$row1, $row2];
        }
        return [
            $m1 + $sav1,
            $sav2,
            $ln,
            $water !== '' ? ['bill_type' => 'One-Time', 'bill_charge_code' => $fee, 'bill_balance' => '1500.00'] : ['savings_code' => $prod[1] ?? ($prod[0] ?? '01'), 'savings_balance' => '300.00'],
            $m2 + ['savings_code' => $sav2['savings_code'], 'savings_balance' => '2000.00'],
        ];
    }

    /** The template of a layout as CSV or Excel: the columns of the examples, and (Excel) a sheet that explains every column */
    public static function template(string $layout, string $format): never
    {
        $rows = self::samples($layout);
        $head = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) {
                $head[$k] = true;
            }
        }
        $head = array_keys($head);
        $data = array_map(fn($r) => array_map(fn($k) => (string)($r[$k] ?? ''), $head), $rows);
        $name = 'consolidated_import_' . ($layout === 'wide' ? 'one_row_per_member' : 'rows_under_the_member');
        if ($format === 'xlsx' && Xlsx::available()) {
            $help = [['Column', 'Part', 'What it holds', 'Needed']];
            foreach (self::fields() as $k => $c) {
                $help[] = [$k . ($c['rep'] ? ' (2nd: ' . self::secondName($k) . ')' : ''), self::SECTIONS[$c['section']], $c['label'] . (!empty($c['hint']) ? ': ' . $c['hint'] : ''), $c['req'] ? 'Yes, for that part' : ''];
            }
            (new Xlsx())->sheet('Import', array_merge([$head], $data), [0])->sheet('Columns', $help, [0])->download($name . '.xlsx');
        }
        csv_download($name . '.csv', $head, $data);
    }

    /** The name of a repeated column for the second account: savings_code => savings2_code */
    public static function secondName(string $k): string
    {
        return (string)preg_replace('/^(savings|loan|bill)_/', '${1}2_', $k, 1);
    }
}
