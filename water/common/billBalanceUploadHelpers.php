<?php
/**
 * Upload Bill Balances (water/views/bill_balance_upload.php): the open balances of the members' and customers' bills
 * of Recurring Billing, Installment Billing, One-Time Billing and Fixed Share Capital Billing, read from one file,
 * shown line by line for review (a line with a problem in red, its cells correctable, any line removable) and then
 * recorded, every line or none.
 *
 * The file may hold one row per member (a balance column for each billing type: a member fills in 1, 2, 3 or all 4)
 * or one row per balance (a Billing type column); a row of the first layout becomes one line per balance in the
 * review.
 *
 * What a balance becomes:
 *   Recurring    a recurring bill on the member's subscription to the plan of its charge category (a member not
 *                enrolled gets an ended subscription that holds the balance only: nothing more is billed)
 *   Installment  an installment contract for the balance, dated the bill date and paid in the installments left
 *                from the due date given
 *   One-Time     a one-time bill
 *   Fixed Share Capital  a share capital bill on the member's fixed billing (no journal entry, as every share
 *                capital bill: the collection is the deposit)
 * The first three are receivables: each gets its invoice, Dr the bill code's A/R, Cr the Legacy Water Balances
 * Clearing account (an opening balance: no income is recognised again, no stock moves, no member discount). Each
 * upload is kept with its lines (water_balance_uploads, water_balance_upload_lines), so the same balance is not
 * uploaded twice.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/billingHelpers.php';
require_once __DIR__ . '/billCreationHelpers.php';
require_once __DIR__ . '/waterBillCodeExclusionHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';

/** billing type => [the word of the file and the review, the page it belongs to] */
const BBU_TYPES = [
    'recurring' => ['Recurring', 'Recurring Billing'],
    'installment' => ['Installment', 'Installment Billing'],
    'one_time' => ['One-Time', 'One-Time Billing'],
    'share_capital' => ['Fixed Share Capital', 'Fixed Share Capital Billing'],
];

/** installment payment mode => [label, months between installments] (installment_bills.payment_mode) */
const BBU_MODES = [
    'monthly' => ['Monthly', 1],
    'quarterly' => ['Quarterly', 3],
    'semi_annually' => ['Semi-annually', 6],
    'annually' => ['Annually', 12],
];

/** The columns of the review, one line per balance; also the file in the one-row-per-balance layout */
const BBU_COLUMNS = [
    'account_no' => ['label' => 'Water account no.', 'req' => true, 'size' => 'sm', 'hint' => 'The account number of the water-service account (an account with no number: its account ID)'],
    'member_name' => ['label' => 'Member / customer', 'size' => 'lg', 'hint' => 'Optional: checked against the name on the account'],
    'billing_type' => ['label' => 'Billing type', 'size' => 'md', 'hint' => 'Recurring, Installment, One-Time or Fixed Share Capital'],
    'charge_code' => ['label' => 'Charge category', 'size' => 'md', 'hint' => 'The CODE NAME of a bill code (recurring, installment and one-time balances); blank: the form\'s for that billing type'],
    'balance' => ['label' => 'Balance', 'type' => 'money', 'size' => 'sm', 'hint' => 'The amount still unpaid'],
    'bill_date' => ['label' => 'Bill date', 'type' => 'date', 'size' => 'md', 'hint' => 'YYYY-MM-DD or MM/DD/YYYY, not after today; blank: the form\'s'],
    'due_date' => ['label' => 'Due date', 'type' => 'date', 'size' => 'md', 'hint' => 'An installment balance: the due date of the first installment left; blank: the form\'s, else the bill date'],
    'installments' => ['label' => 'Installments left', 'type' => 'num', 'size' => 'xs', 'hint' => 'An installment balance: in how many installments it is paid; blank: the form\'s'],
    'payment_mode' => ['label' => 'Payment mode', 'size' => 'md', 'hint' => 'An installment balance: Monthly, Quarterly, Semi-annually or Annually; blank: the form\'s'],
    'share_capital_account' => ['label' => 'Share capital account', 'size' => 'sm', 'hint' => 'A fixed share capital balance of a member with more than one account on fixed billing: the account number'],
];

/** The file in the one-row-per-member layout: a balance column for each billing type, any of them left blank */
const BBU_WIDE_COLUMNS = [
    'account_no' => BBU_COLUMNS['account_no'],
    'member_name' => BBU_COLUMNS['member_name'],
    'recurring_balance' => ['label' => 'Recurring balance', 'type' => 'money', 'size' => 'sm', 'hint' => 'The unpaid balance of the recurring bills; blank: none'],
    'recurring_code' => ['label' => 'Recurring charge category', 'size' => 'md', 'hint' => 'The CODE NAME of the bill code of the recurring plan; blank: the form\'s'],
    'installment_balance' => ['label' => 'Installment balance', 'type' => 'money', 'size' => 'sm', 'hint' => 'The unpaid balance of the installment contract; blank: none'],
    'installment_code' => ['label' => 'Installment charge category', 'size' => 'md', 'hint' => 'The CODE NAME of the bill code of the installment; blank: the form\'s'],
    'installments' => BBU_COLUMNS['installments'],
    'payment_mode' => BBU_COLUMNS['payment_mode'],
    'one_time_balance' => ['label' => 'One-time balance', 'type' => 'money', 'size' => 'sm', 'hint' => 'The unpaid balance of the one-time charges; blank: none'],
    'one_time_code' => ['label' => 'One-time charge category', 'size' => 'md', 'hint' => 'The CODE NAME of the bill code of the charge; blank: the form\'s'],
    'share_capital_balance' => ['label' => 'Fixed share capital balance', 'type' => 'money', 'size' => 'sm', 'hint' => 'The unpaid fixed share capital billed to the member; blank: none'],
    'share_capital_account' => BBU_COLUMNS['share_capital_account'],
    'bill_date' => BBU_COLUMNS['bill_date'],
    'due_date' => BBU_COLUMNS['due_date'],
];

/** Every column a file may have (both layouts, or a mix) */
const BBU_FILE_COLUMNS = BBU_COLUMNS + BBU_WIDE_COLUMNS;

/** The wide columns of each billing type: [balance, charge category] */
const BBU_WIDE = [
    'recurring' => ['recurring_balance', 'recurring_code'],
    'installment' => ['installment_balance', 'installment_code'],
    'one_time' => ['one_time_balance', 'one_time_code'],
    'share_capital' => ['share_capital_balance', null],
];

const BBU_ALIASES = [
    'account' => 'account_no', 'account_number' => 'account_no', 'water_account' => 'account_no', 'water_account_no' => 'account_no',
    'water_account_number' => 'account_no', 'acct' => 'account_no', 'acct_no' => 'account_no', 'account_id' => 'account_no',
    'name' => 'member_name', 'member' => 'member_name', 'customer' => 'member_name', 'customer_name' => 'member_name', 'member_customer' => 'member_name',
    'member_customer_name' => 'member_name', 'concessionaire' => 'member_name', 'account_name' => 'member_name',
    'type' => 'billing_type', 'billing' => 'billing_type', 'bill_type' => 'billing_type', 'balance_type' => 'billing_type',
    'charge' => 'charge_code', 'charge_category' => 'charge_code', 'bill_code' => 'charge_code', 'code_name' => 'charge_code', 'code' => 'charge_code', 'category' => 'charge_code',
    'amount' => 'balance', 'balance_amount' => 'balance', 'unpaid' => 'balance', 'unpaid_balance' => 'balance', 'outstanding' => 'balance',
    'outstanding_balance' => 'balance', 'amount_due' => 'balance', 'open_balance' => 'balance',
    'date' => 'bill_date', 'as_of' => 'bill_date', 'as_of_date' => 'bill_date', 'balance_date' => 'bill_date', 'date_issued' => 'bill_date', 'billing_date' => 'bill_date',
    'due' => 'due_date', 'first_due_date' => 'due_date', 'next_due_date' => 'due_date',
    'installments_left' => 'installments', 'no_of_installments' => 'installments', 'number_of_installments' => 'installments',
    'remaining_installments' => 'installments', 'installment_count' => 'installments', 'terms' => 'installments',
    'mode' => 'payment_mode', 'mode_of_payment' => 'payment_mode', 'frequency' => 'payment_mode',
    'sc_account' => 'share_capital_account', 'share_capital_account_no' => 'share_capital_account', 'share_account' => 'share_capital_account',
    'capital_account' => 'share_capital_account', 'sc_account_no' => 'share_capital_account',
    'recurring' => 'recurring_balance', 'recurring_billing' => 'recurring_balance', 'recurring_amount' => 'recurring_balance', 'recurring_bill' => 'recurring_balance',
    'recurring_charge' => 'recurring_code', 'recurring_charge_category' => 'recurring_code', 'recurring_bill_code' => 'recurring_code',
    'installment' => 'installment_balance', 'installment_billing' => 'installment_balance', 'installment_amount' => 'installment_balance', 'installment_bill' => 'installment_balance',
    'installment_charge' => 'installment_code', 'installment_charge_category' => 'installment_code', 'installment_bill_code' => 'installment_code',
    'one_time' => 'one_time_balance', 'onetime' => 'one_time_balance', 'one_time_billing' => 'one_time_balance', 'one_time_amount' => 'one_time_balance',
    'one_time_charge' => 'one_time_balance', 'one_time_bill' => 'one_time_balance', 'onetime_balance' => 'one_time_balance',
    'one_time_charge_category' => 'one_time_code', 'onetime_code' => 'one_time_code', 'one_time_bill_code' => 'one_time_code',
    'share_capital' => 'share_capital_balance', 'fixed_share_capital' => 'share_capital_balance', 'fixed_share_capital_billing' => 'share_capital_balance',
    'fixed_share_capital_balance' => 'share_capital_balance', 'share_capital_amount' => 'share_capital_balance', 'share_capital_billing' => 'share_capital_balance',
];

/** The cells a balance of each billing type does not use (dimmed in the review) */
const BBU_UNUSED = [
    'recurring' => ['installments', 'payment_mode', 'share_capital_account'],
    'installment' => ['share_capital_account'],
    'one_time' => ['installments', 'payment_mode', 'share_capital_account'],
    'share_capital' => ['charge_code', 'installments', 'payment_mode'],
];

/** The largest balance of one bill (the bills' amounts are DECIMAL(14,2)) */
const BBU_MAX_AMOUNT = 999999999.99;

/** The most balances one upload records */
const BBU_MAX_LINES = 2000;

/** True when the tables of the uploads are installed (migration 2027_04_18_bill_balance_upload.sql) */
function bbu_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $pdo->query('SELECT COUNT(*) FROM water_balance_uploads WHERE 1 = 0');
            $pdo->query('SELECT COUNT(*) FROM water_balance_upload_lines WHERE 1 = 0');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** The billing type a cell names ("Recurring", "one time", "OTB", "Fixed Share Capital" ...); null when it names none */
function bbu_type_key(string $v): ?string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ([
            'recurring' => ['recurring', 'recurringbilling', 'recurringbill', 'recurringbills', 'recurringcharge', 'rec', 'r', 'rb'],
            'installment' => ['installment', 'installmentbilling', 'installmentbill', 'installmentbills', 'installments', 'instalment', 'instalmentbilling', 'inst', 'i', 'ib'],
            'one_time' => ['onetime', 'onetimebilling', 'onetimebill', 'onetimebills', 'onetimecharge', 'onetimecharges', 'one', 'o', 'ot', 'otb'],
            'share_capital' => ['fixedsharecapital', 'fixedsharecapitalbilling', 'sharecapital', 'sharecapitalbilling', 'fixedshare', 'share', 'capital', 'fsc', 'sc', 's'],
        ] as $k => $words) {
            foreach ($words as $w) {
                $map[$w] = $k;
            }
        }
    }
    $k = preg_replace('/[^a-z]/', '', strtolower($v));
    return $k === '' ? null : ($map[$k] ?? null);
}

/** The installment payment mode a cell names; null when it names none */
function bbu_mode_key(string $v): ?string
{
    $k = preg_replace('/[^a-z0-9]/', '', strtolower($v));
    $map = ['monthly' => 'monthly', 'month' => 'monthly', 'mo' => 'monthly', 'm' => 'monthly', '1' => 'monthly',
        'quarterly' => 'quarterly', 'quarter' => 'quarterly', 'q' => 'quarterly', '3' => 'quarterly',
        'semiannually' => 'semi_annually', 'semiannual' => 'semi_annually', 'semiannualy' => 'semi_annually', 'semi' => 'semi_annually',
        'biannually' => 'semi_annually', 'sa' => 'semi_annually', '6' => 'semi_annually',
        'annually' => 'annually', 'annual' => 'annually', 'yearly' => 'annually', 'year' => 'annually', 'a' => 'annually', 'y' => 'annually', '12' => 'annually'];
    return $k === '' ? null : ($map[$k] ?? null);
}

/** The bill codes a balance may use (every one but the water bill's), by their code name in capitals */
function bbu_codes(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT * FROM bill_codes ORDER BY code')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (is_water_billing_reserved_bill_code($pdo, (int)$c['code_id'])) {
            continue;
        }
        $out[strtoupper(trim((string)$c['code']))] = $c;
    }
    return $out;
}

/** What is wrong with the class chosen for the journal entries (null when it can be used); nothing is remembered in the session */
function bbu_class_problem($value): ?string
{
    $had = $_SESSION['last_class_id'] ?? null;
    try {
        water_required_class($value, 'the balances');
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage() . ' (Values for the blank cells)';
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($had === null) {
                unset($_SESSION['last_class_id']);
            } else {
                $_SESSION['last_class_id'] = $had;
            }
        }
    }
}

/** The clearing account the receivables are offset to ("39999 Legacy Water Balances Clearing"), for the page (created on the first upload when missing) */
function bbu_clearing_label(): string
{
    try {
        $id = WaterPosting::defaultAccount('water_legacy_clearing') ?: (int)(DB::value("SELECT id FROM accounts WHERE code = '39999'") ?: 0);
        $a = $id ? DB::row('SELECT code, name FROM accounts WHERE id = :a', ['a' => $id]) : null;
    } catch (Throwable $e) {
        $a = null;
    }
    return $a ? trim($a['code'] . ' ' . $a['name']) : '39999 Legacy Water Balances Clearing';
}

/** Days from one date to another (whole days) */
function bbu_days(string $from, string $to): int
{
    return (int)round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
}

/** "1,234.50" */
function bbu_money($v): string
{
    return number_format((float)$v, 2);
}

/** "01/31/2027" */
function bbu_date(?string $d): string
{
    return $d ? date('m/d/Y', strtotime($d)) : '';
}

/**
 * The rows of the file as lines of the review: a row of the one-row-per-member layout becomes one line for each balance
 * it holds (in the order Recurring, Installment, One-Time, Fixed Share Capital), a row of the other layout one line.
 * Each line carries _line (1, 2, 3 ... in the order of the file) and _ref ("Row 5": the row of the file). A row with
 * an account and no balance at all is kept as one line, which the check then shows in red.
 */
function bbu_expand(array $fileRows, array $codes): array
{
    $lines = [];
    $n = 0;
    $add = function (array $r, array $over) use (&$lines, &$n) {
        $line = ['_line' => ++$n, '_ref' => 'Row ' . (int)$r['_line']];
        foreach (BBU_COLUMNS as $k => $_) {
            $line[$k] = trim((string)($over[$k] ?? $r[$k] ?? ''));
        }
        $lines[] = $line;
    };
    foreach ($fileRows as $r) {
        $made = 0;
        if (trim((string)($r['billing_type'] ?? '')) !== '' || trim((string)($r['balance'] ?? '')) !== '') {
            $add($r, []);
            $made++;
        }
        foreach (BBU_WIDE as $type => [$balKey, $codeKey]) {
            $bal = trim((string)($r[$balKey] ?? ''));
            if ($bal === '') {
                continue;
            }
            $over = ['billing_type' => BBU_TYPES[$type][0], 'balance' => $bal, 'charge_code' => $codeKey ? trim((string)($r[$codeKey] ?? '')) : ''];
            if ($type !== 'installment') {
                $over += ['installments' => '', 'payment_mode' => ''];
            }
            if ($type !== 'share_capital') {
                $over['share_capital_account'] = '';
            }
            $add($r, $over);
            $made++;
        }
        if (!$made) {
            $add($r, []);
        }
    }
    // the words of the file in the form the review's lists show them: the billing type, the payment mode and the
    // charge category as Bill Codes spells it (a word not recognised stays as typed, and is shown in red)
    foreach ($lines as &$l) {
        if (($t = bbu_type_key($l['billing_type'])) !== null) {
            $l['billing_type'] = BBU_TYPES[$t][0];
        }
        if (($m = bbu_mode_key($l['payment_mode'])) !== null) {
            $l['payment_mode'] = BBU_MODES[$m][0];
        }
        if ($l['charge_code'] !== '' && isset($codes[strtoupper($l['charge_code'])])) {
            $l['charge_code'] = (string)$codes[strtoupper($l['charge_code'])]['code'];
        }
    }
    unset($l);
    return $lines;
}

/** Runs a query for a list of ids in chunks; returns every row */
function bbu_rows_in(PDO $pdo, string $sql, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        foreach ($pdo->query(str_replace('{ids}', implode(',', $chunk), $sql))->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = $r;
        }
    }
    return $out;
}

/** A list of dates as quoted SQL values */
function bbu_dates_sql(PDO $pdo, array $dates): string
{
    $dates = array_values(array_unique(array_filter($dates)));
    return $dates ? implode(',', array_map(fn($d) => $pdo->quote($d), $dates)) : "'1000-01-01'";
}

/**
 * Checks the lines: the account and its name, the billing type, the balance, the dates, and what each billing type
 * needs (the charge category; the member's recurring plan; the installments left and the payment mode; the member's
 * fixed share capital billing). Each line comes back with _errors, _notes, _info, _cells, _figure (the balance),
 * _group (the billing type) and _ok (what to record).
 * $opt (the consolidated import of Member Center, whose members and water-service accounts may not be on file yet):
 *   virtual     account no. => a water-service account the import makes (pkey 0)
 *   sc_plans    account no. => the fixed share capital billing the import makes for the member (plan_id 0, account_no,
 *               status), or null when there is none (sc_missing: account no. => what to say)
 *   memo        the bill codes read once a request
 */
function bbu_check(PDO $pdo, array $rows, array $common, array $opt = []): array
{
    static $memoCodes = null;
    $codes = !empty($opt['memo']) ? ($memoCodes ??= bbu_codes($pdo)) : bbu_codes($pdo);
    $byId = [];
    foreach ($codes as $c) {
        $byId[(int)$c['code_id']] = $c;
    }
    $reserved = water_billing_reserved_bill_code($pdo);
    $default = fn(string $k) => trim((string)($common[$k] ?? ''));
    $today = date('Y-m-d');
    $closing = [];
    $arProblem = [];

    // ------------------------------------------------------------ what the lines name, read once for all of them
    $nos = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)($r['account_no'] ?? '')), $rows), fn($v) => $v !== '')));
    $accounts = [];
    foreach (array_chunk($nos, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT pkey, account_number, lastname, firstname, middlename, status, party_id FROM water_accounts WHERE account_number IN ($in)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $accounts['no:' . $a['account_number']] = $a;
        }
        // an account ID is taken only for an account that has no account number (a number mistyped never lands on
        // another member's account by its ID)
        $ids = array_values(array_filter($chunk, fn($v) => ctype_digit($v) && !isset($accounts['no:' . $v])));
        if ($ids) {
            foreach ($pdo->query("SELECT pkey, account_number, lastname, firstname, middlename, status, party_id FROM water_accounts
                                   WHERE (account_number IS NULL OR account_number = '') AND pkey IN (" . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $accounts['id:' . $a['pkey']] = $a;
            }
        }
    }
    foreach ((array)($opt['virtual'] ?? []) as $vno => $va) {
        $accounts['no:' . $vno] = $va;
    }
    $pkeys = array_map(fn($a) => (int)$a['pkey'], $accounts);
    $types = array_map(fn($r) => bbu_type_key((string)($r['billing_type'] ?? '')), $rows);
    $dateOf = function (array $r, string $k) use ($default) {
        $v = trim((string)($r[$k] ?? '')) !== '' ? trim((string)$r[$k]) : $default($k);
        return $v === '' ? null : CsvReview::dateStrict($v);
    };
    $dates = array_values(array_filter(array_map(fn($r) => $dateOf($r, 'bill_date') ?? ($default('bill_date') === '' ? $today : null), $rows)));

    // recurring: the plans, the members' subscriptions and the recurring bills already on the dates of the lines
    $plans = $subs = $recBills = [];
    if (in_array('recurring', $types, true)) {
        foreach ($pdo->query('SELECT plan_id, bill_code_id, amount, frequency, status FROM recurring_plans ORDER BY plan_id')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $plans[(int)$p['bill_code_id']][] = $p;
        }
        foreach (bbu_rows_in($pdo, 'SELECT s.subscription_id, s.plan_id, s.member_id, s.status, p.bill_code_id, p.amount, p.frequency
                                       FROM recurring_subscriptions s JOIN recurring_plans p ON p.plan_id = s.plan_id
                                      WHERE s.member_id IN ({ids}) ORDER BY s.subscription_id DESC', $pkeys) as $s) {
            $subs[(int)$s['member_id']][] = $s;
        }
        foreach (bbu_rows_in($pdo, 'SELECT bill_id, subscription_id, plan_id, member_id, bill_date, status, bill_lifecycle_status FROM recurring_bills
                                      WHERE member_id IN ({ids}) AND bill_date IN (' . bbu_dates_sql($pdo, $dates) . ')', $pkeys) as $b) {
            $recBills['sub:' . $b['subscription_id'] . '|' . $b['bill_date']] = $b;
            if (!in_array($b['status'], ['cancelled', 'deleted'], true) && ($b['bill_lifecycle_status'] ?? 'active') !== 'deleted') {
                $recBills['plan:' . $b['member_id'] . '|' . $b['plan_id'] . '|' . $b['bill_date']] = $b;
            }
        }
    }

    // fixed share capital: the cooperative members of the accounts (the same party), their fixed billings and share
    // capital accounts, and the share capital bills already on the dates of the lines
    $scReady = class_exists('ShareCapitalBilling') && ShareCapitalBilling::ready() && Coop::ready();
    $scMembers = $scPlans = $scAccounts = $scBills = [];
    if ($scReady && in_array('share_capital', $types, true)) {
        foreach (bbu_rows_in($pdo, 'SELECT w.pkey, m.id, m.member_no, m.full_name, m.status FROM water_accounts w JOIN members m ON m.party_id = w.party_id
                                      WHERE w.party_id IS NOT NULL AND w.pkey IN ({ids}) ORDER BY m.id', $pkeys) as $m) {
            $scMembers[(int)$m['pkey']][(int)$m['id']] = $m;
        }
        $memberIds = [];
        foreach ($scMembers as $list) {
            $memberIds = array_merge($memberIds, array_keys($list));
        }
        foreach (bbu_rows_in($pdo, "SELECT pl.plan_id, pl.member_id, pl.sav_account_id, pl.status, pl.next_bill_date, pl.frequency, pl.amount, a.account_no, a.status AS account_status
                                      FROM share_capital_plans pl JOIN sav_accounts a ON a.id = pl.sav_account_id
                                     WHERE pl.status <> 'deleted' AND pl.member_id IN ({ids}) ORDER BY pl.plan_id", $memberIds) as $p) {
            $scPlans[(int)$p['member_id']][] = $p;
        }
        foreach (bbu_rows_in($pdo, "SELECT a.id, a.account_no, a.member_id, a.status FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id
                                     WHERE p.product_type = 'share_capital' AND a.member_id IN ({ids}) ORDER BY a.account_no", $memberIds) as $a) {
            $scAccounts[(int)$a['member_id']][] = $a;
        }
        $planIds = [];
        foreach ($scPlans as $list) {
            $planIds = array_merge($planIds, array_column($list, 'plan_id'));
        }
        foreach (bbu_rows_in($pdo, 'SELECT bill_id, plan_id, bill_date, status, amount FROM share_capital_bills WHERE plan_id IN ({ids})
                                      AND bill_date IN (' . bbu_dates_sql($pdo, $dates) . ')', $planIds) as $b) {
            $scBills[$b['plan_id'] . '|' . $b['bill_date']] = $b;
        }
    }

    // the balances uploaded before whose bills are still on file (a balance is not uploaded twice)
    $uploaded = [];
    if (bbu_ready($pdo) && $pkeys) {
        $sc = $scReady ? "LEFT JOIN share_capital_bills sb ON l.billing_type = 'share_capital' AND sb.bill_id = l.bill_id" : '';
        $scLive = $scReady ? "OR (l.billing_type = 'share_capital' AND sb.status <> 'cancelled')" : '';
        foreach (bbu_rows_in($pdo, "SELECT l.line_no, l.billing_type, l.water_account_id, l.bill_code_id, l.sav_account_id, l.amount, l.bill_date, u.upload_no
                                      FROM water_balance_upload_lines l JOIN water_balance_uploads u ON u.upload_id = l.upload_id
                                      LEFT JOIN recurring_bills rb ON l.billing_type = 'recurring' AND rb.bill_id = l.bill_id
                                      LEFT JOIN one_time_bills ob ON l.billing_type = 'one_time' AND ob.bill_id = l.bill_id
                                      LEFT JOIN installment_bills ib ON l.billing_type = 'installment' AND ib.installment_id = l.bill_id
                                      $sc
                                     WHERE l.water_account_id IN ({ids})
                                       AND ((l.billing_type = 'recurring' AND rb.status NOT IN ('cancelled', 'deleted') AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted')
                                         OR (l.billing_type = 'one_time' AND ob.status NOT IN ('cancelled', 'deleted') AND COALESCE(ob.bill_lifecycle_status, 'active') <> 'deleted')
                                         OR (l.billing_type = 'installment' AND ib.status NOT IN ('cancelled', 'deleted') AND COALESCE(ib.bill_lifecycle_status, 'active') <> 'deleted')
                                         $scLive)
                                     ORDER BY l.line_id", $pkeys) as $u) {
            $uploaded[$u['billing_type'] . '|' . $u['water_account_id'] . '|' . (int)$u['bill_code_id'] . '|' . (int)$u['sav_account_id'] . '|' . $u['bill_date']][] = $u;
        }
    }

    // ------------------------------------------------------------ the lines
    $out = [];
    $seen = [];          // the same balance twice in the file
    $unique = [];        // a recurring subscription or a share capital billing takes one bill a date
    foreach (array_values($rows) as $i => $r) {
        $line = (int)($r['_line'] ?? ($i + 1));
        $errs = [];
        $notes = [];
        $cells = [];
        $err = function (string $m, array $c) use (&$errs, &$cells) {
            $errs[] = $m;
            $cells[count($errs) - 1] = $c;
        };
        $info = '';
        $ok = null;

        // the account
        $no = trim((string)($r['account_no'] ?? ''));
        $a = $no === '' ? null : ($accounts['no:' . $no] ?? $accounts['id:' . $no] ?? null);
        $full = '';
        if ($no === '') {
            $err('Enter the water account no.', ['account_no']);
        } elseif (!$a) {
            $err('No water-service account ' . $no . '.', ['account_no']);
        } elseif (strtoupper((string)$a['status']) !== 'A') {
            $err('Water-service account ' . ($a['account_number'] ?: $a['pkey']) . ' is not active.', ['account_no']);
        }
        if ($a) {
            if (!isset($accounts['no:' . $no])) {
                $notes[] = 'Account ID ' . $no . ': the account has no account number.';
            }
            $full = trim($a['lastname'] . ', ' . $a['firstname'] . ($a['middlename'] ? ' ' . $a['middlename'] : ''), ', ');
            $given = trim((string)($r['member_name'] ?? ''));
            if ($given !== '' && !CsvReview::nameMatches($given, (string)$a['lastname'], (string)$a['firstname'], $full)) {
                $err('Account ' . ($a['account_number'] ?: $a['pkey']) . ' is ' . $full . ', not ' . $given . '.', ['member_name', 'account_no']);
            }
            $info = h($full) . ' <span class="imp-dim">account ' . h($a['account_number'] ?: $a['pkey']) . '</span>';
        }
        $pkey = $a ? (int)$a['pkey'] : 0;

        // the billing type
        $typeRaw = trim((string)($r['billing_type'] ?? ''));
        $type = bbu_type_key($typeRaw);
        if ($typeRaw === '') {
            $err('Choose the billing type: Recurring, Installment, One-Time or Fixed Share Capital.', ['billing_type']);
        } elseif ($type === null) {
            $err('"' . $typeRaw . '" is not a billing type: Recurring, Installment, One-Time or Fixed Share Capital.', ['billing_type']);
        }

        // the balance
        $balRaw = trim((string)($r['balance'] ?? ''));
        $balance = $balRaw === '' ? null : CsvReview::amount($balRaw);
        if ($balRaw === '') {
            $err('Enter the balance.', ['balance']);
        } elseif ($balance === null) {
            $err('The balance "' . $balRaw . '" is not an amount.', ['balance']);
        } elseif ((float)$balance <= 0) {
            $err('The balance must be more than zero (a paid bill has no balance to upload).', ['balance']);
        } elseif ((float)$balance > BBU_MAX_AMOUNT) {
            $err('The balance ' . $balRaw . ' is more than ' . bbu_money(BBU_MAX_AMOUNT) . '.', ['balance']);
        }

        // the dates
        $dateRaw = trim((string)($r['bill_date'] ?? '')) !== '' ? trim((string)$r['bill_date']) : $default('bill_date');
        $billDate = $dateRaw === '' ? $today : CsvReview::dateStrict($dateRaw);
        if ($billDate === null) {
            $err('The bill date "' . $dateRaw . '" is not a date (YYYY-MM-DD or MM/DD/YYYY).', ['bill_date']);
        } elseif ($billDate > $today) {
            $err('The bill date ' . bbu_date($billDate) . ' is after today: a balance is of a bill already made.', ['bill_date']);
        } elseif ($type !== null && $type !== 'share_capital') {
            // the invoice is dated the bill date: a closed period refuses it
            if (!array_key_exists($billDate, $closing)) {
                try {
                    Txn::assertPeriod($billDate, null);
                    $closing[$billDate] = null;
                } catch (UserError $e) {
                    $closing[$billDate] = $e->getMessage();
                }
            }
            if ($closing[$billDate] !== null) {
                $err($closing[$billDate], ['bill_date']);
            }
        }
        $dueRaw = trim((string)($r['due_date'] ?? '')) !== '' ? trim((string)$r['due_date']) : $default('due_date');
        $dueDate = $dueRaw === '' ? $billDate : CsvReview::dateStrict($dueRaw);
        if ($dueRaw !== '' && $dueDate === null) {
            $err('The due date "' . $dueRaw . '" is not a date (YYYY-MM-DD or MM/DD/YYYY).', ['due_date']);
        } elseif ($billDate !== null && $dueDate !== null && $dueDate < $billDate && $type !== 'installment') {
            $err('The due date ' . bbu_date($dueDate) . ' is before the bill date ' . bbu_date($billDate) . '.', ['due_date', 'bill_date']);
        }
        if ($type !== null && $type !== 'installment' && (trim((string)($r['installments'] ?? '')) !== '' || trim((string)($r['payment_mode'] ?? '')) !== '')) {
            $notes[] = 'Installments left and Payment mode are for an installment balance: not used here.';
        }
        if ($type !== null && $type !== 'share_capital' && trim((string)($r['share_capital_account'] ?? '')) !== '') {
            $notes[] = 'Share capital account is for a fixed share capital balance: not used here.';
        }

        // the charge category of a recurring, installment or one-time balance
        $bc = null;
        if (in_array($type, ['recurring', 'installment', 'one_time'], true)) {
            $codeRaw = trim((string)($r['charge_code'] ?? '')) !== '' ? trim((string)$r['charge_code']) : $default($type . '_code');
            if ($codeRaw === '') {
                $err('Enter the charge category of the ' . strtolower(BBU_TYPES[$type][0]) . ' balance (the code name of a bill code), or choose one for the blank cells.', ['charge_code']);
            } else {
                $bc = $codes[strtoupper($codeRaw)] ?? (ctype_digit($codeRaw) ? ($byId[(int)$codeRaw] ?? null) : null);
                if (!$bc) {
                    $err($reserved && strcasecmp(trim((string)$reserved['code']), $codeRaw) === 0
                        ? 'The bill code ' . $codeRaw . ' is the water bill\'s: its balances are water bills (Post Billing), not ' . strtolower(BBU_TYPES[$type][0]) . ' bills.'
                        : 'No charge category ' . $codeRaw . ' in Bill Codes.', ['charge_code']);
                } elseif (empty($bc['ar_account']) || empty($bc['income_account'])) {
                    $err('Bill code ' . $bc['code'] . ' has no A/R or income account (Water Billing > Bill Codes).', ['charge_code']);
                    $bc = null;
                } else {
                    // the A/R account its invoice debits, checked as the posting checks it (once a bill code)
                    $id = (int)$bc['code_id'];
                    if (!array_key_exists($id, $arProblem)) {
                        try {
                            WaterPosting::arAccount((int)$bc['ar_account']);
                            $arProblem[$id] = null;
                        } catch (UserError $e) {
                            $arProblem[$id] = $e->getMessage();
                        }
                    }
                    if ($arProblem[$id] !== null) {
                        $err($arProblem[$id], ['charge_code']);
                        $bc = null;
                    }
                }
                if ($bc && trim((string)($r['charge_code'] ?? '')) === '') {
                    $notes[] = 'Charge category: ' . $bc['code'] . ' (the value for the blank cells).';
                }
            }
        } elseif ($type === 'share_capital' && trim((string)($r['charge_code'] ?? '')) !== '') {
            $notes[] = 'A fixed share capital balance has no charge category: it is a share capital bill.';
        }

        $base = !$errs && $a && $type !== null && $balance !== null && $billDate !== null && $dueDate !== null;
        $amount = $balance !== null ? number_format((float)$balance, 2, '.', '') : null;
        $dupKey = null;

        // ---------------------------------------------------- what each billing type needs
        if ($type === 'recurring' && $bc && $a && $billDate !== null) {
            $codeId = (int)$bc['code_id'];
            $mine = array_values(array_filter($subs[$pkey] ?? [], fn($s) => (int)$s['bill_code_id'] === $codeId));
            $live = array_values(array_filter($mine, fn($s) => in_array($s['status'], ['active', 'paused'], true)));
            $sub = null;
            $plan = null;
            if (count($live) > 1) {
                $err('The account is enrolled in ' . count($live) . ' recurring plans of ' . $bc['code'] . ': end the one not used on Recurring Billing, then Check again.', ['charge_code']);
            } elseif ($live) {
                $sub = $live[0];
                $notes[] = 'On the member\'s subscription to ' . $bc['code'] . ' (' . bbu_money($sub['amount']) . ' ' . strtolower((string)$sub['frequency']) . ', ' . $sub['status'] . ').';
            } elseif ($ended = array_values(array_filter($mine, fn($s) => $s['status'] === 'ended'))) {
                $sub = $ended[0];
                $notes[] = 'On the member\'s ended subscription to ' . $bc['code'] . ': the balance only, nothing more is billed.';
            } else {
                $cands = $plans[$codeId] ?? [];
                $active = array_values(array_filter($cands, fn($p) => $p['status'] === 'active'));
                $pick = $active ?: $cands;
                if (!$pick) {
                    $err('No recurring plan bills ' . $bc['code'] . ': create the plan on Recurring Billing first (Create Plan), then Check again.', ['charge_code']);
                } elseif (count($pick) > 1) {
                    $err(count($pick) . ' recurring plans bill ' . $bc['code'] . ' (' . implode(', ', array_map(fn($p) => bbu_money($p['amount']) . ' ' . strtolower((string)$p['frequency']), $pick))
                        . '): enroll the member in the right one on Recurring Billing (Enroll Member), then Check again.', ['charge_code']);
                } else {
                    $plan = $pick[0];
                    $notes[] = 'Not enrolled in the ' . $bc['code'] . ' plan: the balance is kept on an ended subscription to it, so nothing more is billed. To bill the member from now on, enroll the member on Recurring Billing.';
                }
            }
            $planId = $sub ? (int)$sub['plan_id'] : ($plan ? (int)$plan['plan_id'] : 0);
            if ($sub && isset($recBills['sub:' . $sub['subscription_id'] . '|' . $billDate])) {
                $b = $recBills['sub:' . $sub['subscription_id'] . '|' . $billDate];
                $err('The subscription already has a recurring bill dated ' . bbu_date($billDate) . ' (bill #' . $b['bill_id'] . ', ' . $b['status'] . '): one bill a date. Change the bill date, or remove the line.', ['bill_date']);
            } elseif ($planId && isset($recBills['plan:' . $pkey . '|' . $planId . '|' . $billDate])) {
                $b = $recBills['plan:' . $pkey . '|' . $planId . '|' . $billDate];
                $err('The account already has a ' . $bc['code'] . ' recurring bill dated ' . bbu_date($billDate) . ' (bill #' . $b['bill_id'] . '). Change the bill date, or remove the line.', ['bill_date']);
            }
            if ($planId) {
                $uk = 'rec|' . $pkey . '|' . $planId . '|' . $billDate;
                if (isset($unique[$uk])) {
                    $err('Line ' . $unique[$uk] . ' has a ' . $bc['code'] . ' recurring balance of this account on the same date: one bill a date. Add them up in one line, or change a date.', ['bill_date']);
                } else {
                    $unique[$uk] = $line;
                }
            }
            if (!$errs && $base && $planId) {
                $ok = ['subscription_id' => $sub ? (int)$sub['subscription_id'] : null, 'plan_id' => $planId];
                $info .= ' <span class="imp-dim">Recurring, ' . h($bc['code']) . '</span>';
                $notes[] = 'Recurring bill of ' . bbu_money($amount) . ', dated ' . bbu_date($billDate) . ', due ' . bbu_date($dueDate) . '.';
            }
            $dupKey = 'recurring|' . $pkey . '|' . $codeId . '|0|' . $billDate;
        } elseif ($type === 'installment' && $bc && $a && $billDate !== null && $dueDate !== null && $balance !== null) {
            $nRaw = trim((string)($r['installments'] ?? '')) !== '' ? trim((string)$r['installments']) : $default('installments');
            $modeRaw = trim((string)($r['payment_mode'] ?? '')) !== '' ? trim((string)$r['payment_mode']) : $default('payment_mode');
            $mode = $modeRaw === '' ? 'monthly' : bbu_mode_key($modeRaw);
            $n = $nRaw === '' ? 1 : (preg_match('/^\d+$/', $nRaw) ? (int)$nRaw : 0);
            if ($mode === null) {
                $err('"' . $modeRaw . '" is not a payment mode: Monthly, Quarterly, Semi-annually or Annually.', ['payment_mode']);
            }
            if ($n < 1) {
                $err('Installments left must be a whole number, 1 or more.', ['installments']);
            } elseif ($mode !== null && $n * BBU_MODES[$mode][1] > 120) {
                $err('At most ' . intdiv(120, BBU_MODES[$mode][1]) . ' ' . strtolower(BBU_MODES[$mode][0]) . ' installments: an installment contract runs at most 10 years.', ['installments']);
            } elseif ($mode !== null && (float)$balance < $n * 0.01) {
                $err('A balance of ' . bbu_money($balance) . ' cannot be split in ' . $n . ' installments.', ['installments', 'balance']);
            }
            if (!$errs && $base && $mode !== null) {
                try {
                    $schedule = bc_installment_dates($dueDate, $n * BBU_MODES[$mode][1], $mode);
                    $parts = bc_split_cents((float)$balance, $n);
                    $ok = ['installments' => $n, 'mode' => $mode, 'dates' => $schedule, 'amounts' => $parts];
                    $info .= ' <span class="imp-dim">Installment, ' . h($bc['code']) . '</span>';
                    $past = count(array_filter($schedule, fn($d) => $d < $billDate));
                    $notes[] = $n === 1
                        ? 'One installment of ' . bbu_money($parts[0]) . ', due ' . bbu_date($schedule[0]) . '.'
                        : $n . ' ' . strtolower(BBU_MODES[$mode][0]) . ' installments from ' . bbu_date($schedule[0]) . ' to ' . bbu_date(end($schedule)) . ': '
                            . (count(array_unique($parts)) === 1 ? bbu_money($parts[0]) . ' each.' : bbu_money($parts[0]) . ', the last ' . bbu_money(end($parts)) . '.')
                            . ($past ? ' ' . $past . ' of them fell due before the bill date.' : '');
                } catch (Throwable $e) {
                    $err($e->getMessage(), ['due_date', 'installments']);
                }
            }
            $dupKey = 'installment|' . $pkey . '|' . (int)$bc['code_id'] . '|0|' . $billDate;
        } elseif ($type === 'one_time' && $bc && $a && $billDate !== null) {
            if (!$errs && $base) {
                $ok = [];
                $info .= ' <span class="imp-dim">One-Time, ' . h($bc['code']) . '</span>';
                $notes[] = 'One-time bill of ' . bbu_money($amount) . ', dated ' . bbu_date($billDate) . ', due ' . bbu_date($dueDate) . '.';
            }
            $dupKey = 'one_time|' . $pkey . '|' . (int)$bc['code_id'] . '|0|' . $billDate;
        } elseif ($type === 'share_capital' && $a) {
            $plan = null;
            if (array_key_exists($no, (array)($opt['sc_plans'] ?? []))) {
                // the member and the fixed billing the consolidated import makes
                $plan = $opt['sc_plans'][$no];
                if ($plan === null) {
                    $err((string)($opt['sc_missing'][$no] ?? 'The member is not on Fixed Share Capital Billing.'), ['billing_type']);
                }
            } elseif (!$scReady) {
                $err('Fixed Share Capital Billing is not installed on this database.', ['billing_type']);
            } else {
                $members = array_values(array_filter($scMembers[$pkey] ?? [], fn($m) => in_array($m['status'], ['active', 'applicant'], true)));
                $all = $scMembers[$pkey] ?? [];
                $who = $members ? implode(' / ', array_map(fn($m) => $m['full_name'], $members)) : $full;
                if (!$all) {
                    $err('The holder of account ' . ($a['account_number'] ?: $a['pkey']) . ' is not a cooperative member: a fixed share capital balance is a member\'s.', ['account_no', 'billing_type']);
                } elseif (!$members) {
                    $m = reset($all);
                    $err('Member ' . $m['full_name'] . ' is ' . $m['status'] . ': only an active member\'s share capital is billed.', ['account_no']);
                } else {
                    $plansOf = [];
                    foreach ($members as $m) {
                        foreach ($scPlans[(int)$m['id']] ?? [] as $p) {
                            $plansOf[] = $p;
                        }
                    }
                    $acctRaw = trim((string)($r['share_capital_account'] ?? ''));
                    if ($acctRaw !== '') {
                        $match = array_values(array_filter($plansOf, fn($p) => strcasecmp(trim((string)$p['account_no']), $acctRaw) === 0));
                        if ($match) {
                            $plan = $match[0];
                        } else {
                            $own = [];
                            foreach ($members as $m) {
                                foreach ($scAccounts[(int)$m['id']] ?? [] as $acc) {
                                    $own[strtoupper(trim((string)$acc['account_no']))] = $acc;
                                }
                            }
                            $err(isset($own[strtoupper($acctRaw)])
                                ? 'Share capital account ' . $acctRaw . ' is not on Fixed Share Capital Billing: put it on fixed billing first (Fixed Share Capital Billing > Add member, or Import), then Check again.'
                                : $who . ' has no share capital account ' . $acctRaw . ($own ? ' (the accounts: ' . implode(', ', array_map(fn($x) => $x['account_no'], $own)) . ').' : '.'), ['share_capital_account']);
                        }
                    } elseif (count($plansOf) === 1) {
                        $plan = $plansOf[0];
                    } elseif (!$plansOf) {
                        $err($who . ' is not on Fixed Share Capital Billing: put the member on fixed billing first (Fixed Share Capital Billing > Add member, or Import), then Check again.', ['account_no', 'billing_type']);
                    } else {
                        $err($who . ' has ' . count($plansOf) . ' share capital accounts on fixed billing (' . implode(', ', array_map(fn($p) => $p['account_no'], $plansOf)) . '): enter the one in Share capital account.', ['share_capital_account']);
                    }
                    if ($plan && !in_array($plan['account_status'], ['active', 'dormant'], true)) {
                        $err('Share capital account ' . $plan['account_no'] . ' is ' . $plan['account_status'] . ': its bills are not collected.', ['share_capital_account']);
                        $plan = null;
                    }
                }
            }
            if ($plan && $billDate !== null) {
                if (isset($scBills[$plan['plan_id'] . '|' . $billDate])) {
                    $b = $scBills[$plan['plan_id'] . '|' . $billDate];
                    $err('The fixed billing of share capital account ' . $plan['account_no'] . ' already has a bill dated ' . bbu_date($billDate) . ' (' . bbu_money($b['amount']) . ', ' . $b['status'] . '): one bill a date. Change the bill date, or remove the line.', ['bill_date']);
                }
                $uk = 'sc|' . $plan['plan_id'] . '|' . $billDate;
                if (isset($unique[$uk])) {
                    $err('Line ' . $unique[$uk] . ' has a share capital balance of account ' . $plan['account_no'] . ' on the same date: one bill a date. Add them up in one line, or change a date.', ['bill_date']);
                } else {
                    $unique[$uk] = $line;
                }
                if ($plan['status'] === 'active' && $plan['next_bill_date'] && substr((string)$plan['next_bill_date'], 0, 7) === substr($billDate, 0, 7)) {
                    $notes[] = 'The fixed billing will not post its bill of ' . date('F Y', strtotime($billDate)) . ' (due to post ' . bbu_date($plan['next_bill_date']) . '): that month has this balance.';
                }
            }
            if (!$errs && $base && $plan) {
                $ok = ['plan_id' => (int)$plan['plan_id'], 'coop_member_id' => (int)$plan['member_id'], 'sav_account_id' => (int)$plan['sav_account_id'], 'sav_account_no' => (string)$plan['account_no']];
                $info .= ' <span class="imp-dim">Fixed Share Capital, account ' . h($plan['account_no']) . '</span>';
                $notes[] = 'Share capital bill of ' . bbu_money($amount) . ' on the fixed billing of account ' . $plan['account_no'] . ' (' . ($plan['status'] === 'on_hold' ? 'on hold' : $plan['status'])
                    . '), dated ' . bbu_date($billDate) . ', due ' . bbu_date($dueDate) . '. No journal entry: the collection is the deposit.';
            }
            $dupKey = $plan ? 'share_capital|' . $pkey . '|0|' . (int)$plan['sav_account_id'] . '|' . $billDate : null;
        }

        // the same balance uploaded before, or twice in this file
        if ($dupKey !== null && $amount !== null) {
            foreach ($uploaded[$dupKey] ?? [] as $u) {
                if (abs((float)$u['amount'] - (float)$amount) < 0.005) {
                    $err('Uploaded already: ' . $u['upload_no'] . ', line ' . $u['line_no'] . ' (the same account, billing type, charge category, date and balance). Remove this line.', ['balance', 'bill_date']);
                    break;
                }
            }
            if (!array_filter($errs, fn($e) => str_starts_with($e, 'Uploaded already')) && !empty($uploaded[$dupKey])) {
                $u = $uploaded[$dupKey][0];
                $notes[] = 'Upload ' . $u['upload_no'] . ' (line ' . $u['line_no'] . ') has a balance of this account, billing type and charge category on the same date: ' . bbu_money($u['amount']) . '. Make sure it is not the same bill.';
            }
            $sk = $dupKey . '|' . $amount;
            if (isset($seen[$sk]) && in_array($type, ['installment', 'one_time'], true)) {
                $notes[] = 'Line ' . $seen[$sk] . ' has the same account, billing type, charge category, date and balance: two bills will be made.';
            }
            $seen[$sk] = $seen[$sk] ?? $line;
        }

        if ($errs) {
            $ok = null;
        } elseif ($ok !== null) {
            $ok += ['type' => $type, 'member_id' => $pkey, 'account' => (string)($a['account_number'] ?: $a['pkey']), 'name' => $full,
                'amount' => $amount, 'bill_date' => $billDate, 'due_date' => $dueDate,
                'bill_code_id' => $bc ? (int)$bc['code_id'] : null, 'code' => $bc ? (string)$bc['code'] : null, 'ar_account' => $bc ? (int)$bc['ar_account'] : null];
        }
        $out[] = array_filter($r, fn($k) => !is_string($k) || $k === '' || $k[0] !== '_', ARRAY_FILTER_USE_KEY) + [
            '_line' => $line, '_ref' => (string)($r['_ref'] ?? ''), '_errors' => $errs, '_notes' => $notes, '_cells' => $cells, '_info' => $info,
            '_figure' => $amount !== null ? (float)$amount : 0.0, '_group' => $type ?? '', '_off' => $type !== null ? BBU_UNUSED[$type] : [], '_ok' => $ok,
        ];
    }
    return $out;
}

/** The rows of the review as sent back (ir_rows with the row of the file each line came from) */
function bbu_rows(array $body): array
{
    $rows = ir_rows(BBU_COLUMNS, $body);
    $refs = [];
    foreach ((array)($body['rows'] ?? []) as $r) {
        if (is_array($r)) {
            $refs[(int)($r['_line'] ?? 0)] = mb_substr(trim((string)($r['_ref'] ?? '')), 0, 20);
        }
    }
    foreach ($rows as &$r) {
        $r['_ref'] = $refs[$r['_line']] ?? '';
    }
    unset($r);
    return $rows;
}

/** An unused global billing reference (a number a ledger document already carries is never reused) */
function bbu_global_ref(PDO $pdo): int
{
    $used = $pdo->prepare('SELECT 1 FROM water_postings WHERE doc_no = ? LIMIT 1');
    for ($i = 0; $i < 100; $i++) {
        $ref = (int)generateGlobalRef($pdo);
        if ($ref <= 0) {
            continue;
        }
        $used->execute([(string)$ref]);
        if (!$used->fetchColumn()) {
            return $ref;
        }
    }
    throw new RuntimeException('Unable to allocate an unused global billing reference: synchronize global_billing_sequence with the existing documents.');
}

/**
 * Records the lines, every one or none: the bills of each billing type with their invoices (an opening balance against
 * the clearing account), the share capital bills, and the upload with its lines. Returns
 * ['upload_id', 'upload_no', 'count', 'total', 'by_type' => [type => [count, total]]].
 */
function bbu_post(PDO $pdo, array $rows, array $common, $classValue, int $userId, string $fileName): array
{
    if (!bbu_ready($pdo)) {
        fail('Run the database update database/migrations/2027_04_18_bill_balance_upload.sql first.');
    }
    if (!$rows) {
        fail('There is no balance to upload: every line was removed.');
    }
    if (count($rows) > BBU_MAX_LINES) {
        fail('Upload at most ' . number_format(BBU_MAX_LINES) . ' balances at a time: split the file.');
    }
    if ($userId <= 0) {
        fail('Your session is missing a valid operator identifier. Sign in again.');
    }
    $checked = bbu_check($pdo, $rows, $common);
    $bad = array_values(array_filter($checked, fn($r) => $r['_errors'] || !$r['_ok']));
    if ($bad) {
        fail(count($bad) === 1 ? 'Line ' . $bad[0]['_line'] . ': ' . ($bad[0]['_errors'][0] ?? 'it has a problem.') : count($bad) . ' lines have a problem (line ' . $bad[0]['_line'] . ': ' . ($bad[0]['_errors'][0] ?? '') . ')');
    }
    $journals = array_filter($checked, fn($r) => $r['_ok']['type'] !== 'share_capital');
    $classId = $journals ? water_required_class($classValue, 'the balances') : null;

    $insOneTime = $pdo->prepare("INSERT INTO one_time_bills
        (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost, bill_date, due_date, term_days,
         total_amount, gross_amount, discount_amount, discount_id, discount_rule_id, amount_paid, status, source_type, source_id,
         branch_id, segment_id, division_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, 1.00, 0.00, 0.00, ?, ?, ?, ?, ?, 0.00, NULL, NULL, 0.00, 'unpaid', 'balance_upload', ?, 0, 0, 0, ?, NOW(), NOW())");
    $insRecurring = $pdo->prepare("INSERT INTO recurring_bills
        (global_ref_number, journal_voucher_id, subscription_id, plan_id, member_id, bill_code_id, billing_period_start, billing_period_end,
         bill_date, due_date, term_days, total_amount, gross_amount, discount_amount, discount_id, discount_rule_id, amount_paid, balance,
         status, created_by, created_at, updated_at)
        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, NULL, NULL, 0.00, ?, 'unpaid', ?, NOW(), NOW())");
    $insSub = $pdo->prepare("INSERT INTO recurring_subscriptions
        (plan_id, member_id, subscription_start_date, next_billing_date, last_billed_date, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'ended', ?, NOW(), NOW())");
    $insInstallment = $pdo->prepare("INSERT INTO installment_bills
        (global_ref_number, member_id, bill_code_id, quantity, inventory_unit_cost, inventory_total_cost, payment_mode, total_amount,
         gross_amount, discount_amount, discount_id, discount_rule_id, term, amortization_type, start_date, status, branch_id, segment_id,
         division_id, created_at, updated_at)
        VALUES (?, ?, ?, 1.00, 0.00, 0.00, ?, ?, ?, 0.00, NULL, NULL, ?, 'fixed', ?, 'active', 0, 0, 0, NOW(), NOW())");
    $insSchedule = $pdo->prepare("INSERT INTO installment_schedules (installment_id, due_date, amount, status) VALUES (?, ?, ?, 'pending')");
    $insLine = $pdo->prepare("INSERT INTO water_balance_upload_lines
        (upload_id, line_no, file_row, billing_type, water_account_id, bill_code_id, sav_account_id, bill_id, amount, bill_date, due_date, installments)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // inside the transaction of the caller when there is one (the consolidated import of Member Center)
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        // the accounts, the bill codes and the share capital accounts, each in one order, locked before anything is
        // written: a bill or an upload made at the same time waits
        $members = array_values(array_unique(array_map(fn($r) => (int)$r['_ok']['member_id'], $checked)));
        sort($members);
        foreach ($members as $m) {
            bc_lock_active_member($pdo, $m);
        }
        $codeIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['_ok']['bill_code_id'] ?? 0), $checked))));
        sort($codeIds);
        foreach ($codeIds as $c) {
            bc_lock_bill_code($pdo, $c);
        }
        $savIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['_ok']['sav_account_id'] ?? 0), $checked))));
        sort($savIds);
        foreach ($savIds as $s) {
            DB::value('SELECT id FROM sav_accounts WHERE id = :a FOR UPDATE', ['a' => $s]);
        }
        $clearing = $journals ? WaterPosting::legacyClearingAccount() : null;

        $now = date('Y-m-d H:i:s');
        $uploadId = (int)DB::insert('water_balance_uploads', ['file_name' => $fileName !== '' ? $fileName : null, 'line_count' => 0, 'total_amount' => '0.00',
            'class_id' => $classId, 'created_by' => $userId, 'created_at' => $now]);
        $uploadNo = 'BAL-' . str_pad((string)$uploadId, 5, '0', STR_PAD_LEFT);
        DB::update('water_balance_uploads', ['upload_no' => $uploadNo], 'upload_id = :u', ['u' => $uploadId]);

        $byType = array_fill_keys(array_keys(BBU_TYPES), [0, 0.0]);
        $total = 0.0;
        $endedSubs = [];     // member|plan => the ended subscription made by this upload
        $n = 0;
        foreach ($checked as $r) {
            $ok = $r['_ok'];
            $n++;
            $label = 'Line ' . $r['_line'] . ' (' . $ok['account'] . ', ' . BBU_TYPES[$ok['type']][0] . '): ';
            $fileRow = preg_match('/(\d+)/', (string)($r['_ref'] ?? ''), $m) ? (int)$m[1] : null;
            $term = max(0, bbu_days($ok['bill_date'], $ok['due_date']));
            $desc = 'Balance uploaded ' . $uploadNo . ', line ' . $r['_line'];
            try {
                $billId = 0;
                $post = function (string $source, int $id, string $docNo, string $what) use ($ok, $clearing, $classId, $uploadNo, $desc) {
                    return WaterPosting::withClass($classId, fn() => WaterPosting::postBill($source, $id, [
                        'water_account_id' => $ok['member_id'], 'txn_date' => $ok['bill_date'], 'due_date' => $ok['due_date'], 'doc_no' => $docNo,
                        'ar_account_id' => $ok['ar_account'],
                        'lines' => [['account_id' => $clearing, 'amount' => (float)$ok['amount'], 'description' => mb_substr($desc . ': ' . $what, 0, 255)]], 'opening' => true,
                        'memo' => mb_substr($what . ' for account ' . $ok['account'] . ': open balance uploaded (' . $uploadNo . ')', 0, 255),
                        'reason' => 'Bill balance uploaded (' . $uploadNo . ')',
                    ]));
                };
                if ($ok['type'] === 'one_time') {
                    $ref = bbu_global_ref($pdo);
                    $insOneTime->execute([$ref, $ok['member_id'], $ok['bill_code_id'], $ok['bill_date'], $ok['due_date'], $term, $ok['amount'], $ok['amount'], $uploadId, $userId]);
                    $billId = (int)$pdo->lastInsertId();
                    $post('one_time', $billId, (string)$ref, 'One-time bill #' . $billId . ' (' . $ok['code'] . ')');
                    logAudit($pdo, $userId, 'UPLOAD_BILL_BALANCE', 'one_time_bills', (string)$billId, json_encode(['upload' => $uploadNo, 'line' => $r['_line'],
                        'global_ref_number' => $ref, 'member_id' => $ok['member_id'], 'bill_code_id' => $ok['bill_code_id'], 'amount' => $ok['amount'],
                        'bill_date' => $ok['bill_date'], 'due_date' => $ok['due_date']], JSON_THROW_ON_ERROR));
                } elseif ($ok['type'] === 'recurring') {
                    $subId = $ok['subscription_id'];
                    if (!$subId) {
                        $k = $ok['member_id'] . '|' . $ok['plan_id'];
                        if (!isset($endedSubs[$k])) {
                            // a member not enrolled: an ended subscription holds the balance, so nothing more is billed
                            $insSub->execute([$ok['plan_id'], $ok['member_id'], $ok['bill_date'], $ok['bill_date'], $ok['bill_date'], $userId]);
                            $endedSubs[$k] = (int)$pdo->lastInsertId();
                            logAudit($pdo, $userId, 'UPLOAD_BILL_BALANCE_SUBSCRIPTION', 'recurring_subscriptions', (string)$endedSubs[$k], json_encode(['upload' => $uploadNo,
                                'plan_id' => $ok['plan_id'], 'member_id' => $ok['member_id'], 'status' => 'ended'], JSON_THROW_ON_ERROR));
                        }
                        $subId = $endedSubs[$k];
                    }
                    $ref = bbu_global_ref($pdo);
                    $insRecurring->execute([$ref, $subId, $ok['plan_id'], $ok['member_id'], $ok['bill_code_id'], $ok['bill_date'], $ok['bill_date'],
                        $ok['bill_date'], $ok['due_date'], $term, $ok['amount'], $ok['amount'], $ok['amount'], $userId]);
                    $billId = (int)$pdo->lastInsertId();
                    $posted = $post('recurring', $billId, (string)$ref, 'Recurring bill #' . $billId . ' (' . $ok['code'] . ')');
                    $pdo->prepare('UPDATE recurring_bills SET journal_voucher_id = ? WHERE bill_id = ?')->execute([$posted['journal_head_id'] ?? null, $billId]);
                    logAudit($pdo, $userId, 'UPLOAD_BILL_BALANCE', 'recurring_bills', (string)$billId, json_encode(['upload' => $uploadNo, 'line' => $r['_line'],
                        'global_ref_number' => $ref, 'subscription_id' => $subId, 'plan_id' => $ok['plan_id'], 'member_id' => $ok['member_id'], 'amount' => $ok['amount'],
                        'bill_date' => $ok['bill_date'], 'due_date' => $ok['due_date']], JSON_THROW_ON_ERROR));
                } elseif ($ok['type'] === 'installment') {
                    $ref = bbu_global_ref($pdo);
                    // the contract is dated the bill date (the date of the balance: the statements list it from then),
                    // its installments fall due from the due date given
                    $insInstallment->execute([$ref, $ok['member_id'], $ok['bill_code_id'], $ok['mode'], $ok['amount'], $ok['amount'],
                        $ok['installments'] * BBU_MODES[$ok['mode']][1], $ok['bill_date']]);
                    $billId = (int)$pdo->lastInsertId();
                    foreach ($ok['dates'] as $j => $d) {
                        $insSchedule->execute([$billId, $d, number_format((float)$ok['amounts'][$j], 2, '.', '')]);
                    }
                    $post('installment', $billId, (string)$ref, 'Installment contract #' . $billId . ' (' . $ok['code'] . ')');
                    logAudit($pdo, $userId, 'UPLOAD_BILL_BALANCE', 'installment_bills', (string)$billId, json_encode(['upload' => $uploadNo, 'line' => $r['_line'],
                        'global_ref_number' => $ref, 'member_id' => $ok['member_id'], 'bill_code_id' => $ok['bill_code_id'], 'amount' => $ok['amount'],
                        'installments' => $ok['installments'], 'payment_mode' => $ok['mode'], 'first_due' => $ok['dates'][0], 'bill_date' => $ok['bill_date']], JSON_THROW_ON_ERROR));
                } else {
                    $billId = (int)DB::insert('share_capital_bills', ['plan_id' => $ok['plan_id'], 'member_id' => $ok['coop_member_id'], 'sav_account_id' => $ok['sav_account_id'],
                        'bill_date' => $ok['bill_date'], 'due_date' => $ok['due_date'], 'term_days' => $term, 'amount' => $ok['amount'], 'amount_paid' => '0.00',
                        'status' => 'unpaid', 'created_at' => $now]);
                    Audit::log('create', 'water', 'share_capital_bill', $billId, 'Share capital ' . $ok['sav_account_no'] . ' ' . bbu_money($ok['amount']) . ' dated ' . $ok['bill_date']
                        . ': balance uploaded (' . $uploadNo . ', line ' . $r['_line'] . ')', ['page_key' => 'water.bill_balance_upload']);
                }
                $insLine->execute([$uploadId, $r['_line'], $fileRow, $ok['type'], $ok['member_id'], $ok['bill_code_id'], $ok['sav_account_id'] ?? null, $billId,
                    $ok['amount'], $ok['bill_date'], $ok['due_date'], $ok['type'] === 'installment' ? $ok['installments'] : null]);
            } catch (UserError $e) {
                throw new UserError($label . $e->getMessage() . ' Nothing was uploaded.');
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new UserError($label . 'a bill of the same date was recorded since the check. Nothing was uploaded: check the lines again.');
                }
                throw $e;
            } catch (Throwable $e) {
                throw new UserError($label . $e->getMessage() . ' Nothing was uploaded.');
            }
            $byType[$ok['type']][0]++;
            $byType[$ok['type']][1] = round($byType[$ok['type']][1] + (float)$ok['amount'], 2);
            $total = round($total + (float)$ok['amount'], 2);
        }
        DB::update('water_balance_uploads', ['line_count' => $n, 'total_amount' => number_format($total, 2, '.', '')], 'upload_id = :u', ['u' => $uploadId]);
        Audit::log('create', 'water', 'bill_balance_upload', $uploadId, $uploadNo . ($fileName !== '' ? ' ' . $fileName : '') . ': ' . $n . ' balance(s), ' . bbu_money($total)
            . ' (' . implode(', ', array_map(fn($t) => BBU_TYPES[$t][0] . ' ' . $byType[$t][0], array_keys(array_filter($byType, fn($v) => $v[0] > 0)))) . ')',
            ['page_key' => 'water.bill_balance_upload', 'amount' => $total]);
        if ($own) {
            $pdo->commit();
        }
        return ['upload_id' => $uploadId, 'upload_no' => $uploadNo, 'count' => $n, 'total' => $total, 'by_type' => $byType];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** The uploads, newest first, each with its count and total by billing type */
function bbu_uploads(PDO $pdo, int $limit = 25): array
{
    if (!bbu_ready($pdo)) {
        return [];
    }
    $rows = $pdo->query('SELECT u.*, o.username, o.full_name FROM water_balance_uploads u LEFT JOIN users o ON o.id = u.created_by
                          ORDER BY u.upload_id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }
    $ids = implode(',', array_map(fn($r) => (int)$r['upload_id'], $rows));
    $by = [];
    foreach ($pdo->query("SELECT upload_id, billing_type, COUNT(*) AS n, SUM(amount) AS amt FROM water_balance_upload_lines WHERE upload_id IN ($ids) GROUP BY upload_id, billing_type")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $by[(int)$b['upload_id']][$b['billing_type']] = [(int)$b['n'], (float)$b['amt']];
    }
    foreach ($rows as &$r) {
        $r['by_type'] = $by[(int)$r['upload_id']] ?? [];
    }
    unset($r);
    return $rows;
}

/** One upload with its lines (the account, the bill made and what is still unpaid on it) */
function bbu_upload(PDO $pdo, int $uploadId): ?array
{
    if (!bbu_ready($pdo) || $uploadId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT u.*, o.username, o.full_name FROM water_balance_uploads u LEFT JOIN users o ON o.id = u.created_by WHERE u.upload_id = ?');
    $st->execute([$uploadId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return null;
    }
    $sc = class_exists('ShareCapitalBilling') && ShareCapitalBilling::ready();
    $st = $pdo->prepare("SELECT l.*, w.account_number, w.lastname, w.firstname, w.middlename, c.code,
                                CASE l.billing_type
                                  WHEN 'recurring' THEN (SELECT CONCAT(rb.status, '|', GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)), '|', COALESCE(rb.global_ref_number, '')) FROM recurring_bills rb WHERE rb.bill_id = l.bill_id)
                                  WHEN 'one_time' THEN (SELECT CONCAT(ob.status, '|', GREATEST(0, ob.total_amount - COALESCE(ob.amount_paid, 0)), '|', COALESCE(ob.global_ref_number, '')) FROM one_time_bills ob WHERE ob.bill_id = l.bill_id)
                                  WHEN 'installment' THEN (SELECT CONCAT(ib.status, '|', (SELECT COALESCE(SUM(GREATEST(0, s.amount - COALESCE(s.amount_paid, 0))), 0) FROM installment_schedules s WHERE s.installment_id = ib.installment_id), '|', COALESCE(ib.global_ref_number, '')) FROM installment_bills ib WHERE ib.installment_id = l.bill_id)
                                  " . ($sc ? "WHEN 'share_capital' THEN (SELECT CONCAT(sb.status, '|', GREATEST(0, sb.amount - sb.amount_paid), '|', a.account_no) FROM share_capital_bills sb JOIN sav_accounts a ON a.id = sb.sav_account_id WHERE sb.bill_id = l.bill_id)" : '') . "
                                END AS bill_state
                           FROM water_balance_upload_lines l
                           LEFT JOIN water_accounts w ON w.pkey = l.water_account_id
                           LEFT JOIN bill_codes c ON c.code_id = l.bill_code_id
                          WHERE l.upload_id = ? ORDER BY l.line_no");
    $st->execute([$uploadId]);
    $u['lines'] = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        [$status, $open, $ref] = array_pad(explode('|', (string)$l['bill_state']), 3, '');
        $l['bill_status'] = $status;
        $l['open'] = (float)$open;
        $l['ref'] = $ref;
        $u['lines'][] = $l;
    }
    return $u;
}

/** Example rows of a template, in either layout: real accounts and charge categories of this database when it has them */
function bbu_samples(PDO $pdo, string $layout): array
{
    $accts = $pdo->query("SELECT pkey, account_number, lastname, firstname, party_id FROM water_accounts WHERE status = 'A' ORDER BY pkey LIMIT 4")->fetchAll(PDO::FETCH_ASSOC)
        ?: [['pkey' => 1, 'account_number' => '50001', 'lastname' => 'Bond', 'firstname' => 'James', 'party_id' => null]];
    $codes = bbu_codes($pdo);
    $recurring = '';
    try {
        $recurring = (string)($pdo->query("SELECT c.code FROM recurring_plans p JOIN bill_codes c ON c.code_id = p.bill_code_id WHERE p.status = 'active' ORDER BY p.plan_id LIMIT 1")->fetchColumn() ?: '');
    } catch (Throwable $e) {
    }
    $plain = array_values(array_filter($codes, fn($c) => !bc_is_inventory($c)));
    $any = array_values($codes);
    $recurring = $recurring ?: (string)($plain[0]['code'] ?? 'GARBAGE FEE');
    $installment = (string)(($any[1] ?? $any[0] ?? null)['code'] ?? 'CONNECTION FEE');
    $oneTime = (string)(($plain[1] ?? $plain[0] ?? null)['code'] ?? 'RECONNECTION FEE');
    $date = date('Y-m-t', strtotime(date('Y-m-01') . ' -1 month'));
    $due = date('Y-m-15');
    $name = fn($a) => trim($a['lastname'] . ', ' . $a['firstname'], ', ');
    $no = fn($a) => (string)($a['account_number'] ?: $a['pkey']);
    if ($layout === 'wide') {
        // one row per member: a member with 1, 2, 3 or 4 of the balances
        $sets = [
            ['recurring_balance' => '450.00', 'one_time_balance' => '1500.00'],
            ['recurring_balance' => '300.00', 'installment_balance' => '6000.00', 'installments' => '6', 'payment_mode' => 'Monthly', 'share_capital_balance' => '200.00'],
            ['share_capital_balance' => '500.00'],
            ['recurring_balance' => '150.00', 'installment_balance' => '2400.00', 'installments' => '4', 'payment_mode' => 'Quarterly', 'one_time_balance' => '350.00', 'share_capital_balance' => '100.00'],
        ];
        $out = [];
        foreach ($accts as $i => $a) {
            $s = $sets[$i % count($sets)];
            $out[] = $s + ['account_no' => $no($a), 'member_name' => $name($a),
                'recurring_code' => isset($s['recurring_balance']) ? $recurring : '', 'installment_code' => isset($s['installment_balance']) ? $installment : '',
                'one_time_code' => isset($s['one_time_balance']) ? $oneTime : '', 'bill_date' => $date, 'due_date' => $due];
        }
        return $out;
    }
    $a = $accts[0];
    $b = $accts[1] ?? $accts[0];
    return [
        ['account_no' => $no($a), 'member_name' => $name($a), 'billing_type' => 'Recurring', 'charge_code' => $recurring, 'balance' => '450.00', 'bill_date' => $date, 'due_date' => $due],
        ['account_no' => $no($a), 'member_name' => $name($a), 'billing_type' => 'One-Time', 'charge_code' => $oneTime, 'balance' => '1500.00', 'bill_date' => $date, 'due_date' => $due],
        ['account_no' => $no($b), 'member_name' => $name($b), 'billing_type' => 'Installment', 'charge_code' => $installment, 'balance' => '6000.00', 'bill_date' => $date, 'due_date' => $due,
            'installments' => '6', 'payment_mode' => 'Monthly'],
        ['account_no' => $no($b), 'member_name' => $name($b), 'billing_type' => 'Fixed Share Capital', 'balance' => '200.00', 'bill_date' => $date, 'due_date' => $due],
    ];
}
