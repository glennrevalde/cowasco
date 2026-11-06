<?php
declare(strict_types=1);

/**
 * JSON for the cooperative screens (member search, a member's accounts and loan dues, amortization preview).
 * Logged-in users only (index.php), and only users who open at least one cooperative page; branch scope applies.
 */
if (!Coop::ready()) {
    json_out(['error' => 'The cooperative module is not installed.'], 503);
}
require_once APP_ROOT . '/modules/coop/_coop.php';
$coopPages = ['coop.members', 'coop.member', 'sav.accounts', 'sav.center', 'sav.transact', 'teller.or', 'teller.disburse', 'loan.applications', 'loan.application', 'loan.collection',
    'loan.release', 'loan.accounts', 'sav.td', 'sav.transfer', 'coop.member_statement', 'dist.batches', 'rpt.loan_soa', 'loan.adjustments'];
$allowed = false;
foreach ($coopPages as $pk) {
    if (Access::page($pk)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    json_out(['error' => 'Not allowed.'], 403);
}

$a = (string)g('a');
try {
    switch ($a) {
        case 'members':
            $rows = Coop::searchMembers((string)g('q'), 15, g('status') ?: null);
            $types = Members::types();
            json_out(['members' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'no' => $r['member_no'], 'name' => $r['full_name'], 'status' => Coop::MEMBER_STATUSES[$r['status']] ?? $r['status'],
                'type' => $types[(int)$r['member_type_id']]['name'] ?? '', 'city' => (string)$r['res_city'], 'hold' => (int)$r['hold_flag'] === 1, 'alert' => (string)$r['alert_note'],
            ], $rows)]);

        case 'member':
            $m = Coop::member((int)g('id'));
            if (!$m || !Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
                json_out(['error' => 'Member not found.'], 404);
            }
            $date = gdate('date', today());
            $accounts = DB::all("SELECT a.id, a.account_no, a.status, a.balance, a.uncleared, a.hold_amount, a.product_id, p.code, p.name, p.product_type, p.min_deposit, p.allow_withdrawal
                                   FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND a.status <> 'closed' ORDER BY p.sort_order, a.account_no", ['m' => $m['id']]);
            $loans = [];
            foreach (DB::all("SELECT id FROM loan_accounts WHERE member_id = :m AND status IN ('current','past_due','restructured') ORDER BY release_date", ['m' => $m['id']]) as $l) {
                $loan = Loans::loan((int)$l['id']);
                $d = Loans::dues($loan, $date);
                $loans[] = ['id' => (int)$loan['id'], 'loan_no' => $loan['loan_no'], 'product' => $loan['product_name'], 'status' => $loan['status'], 'method' => $loan['interest_method'],
                    'principal_balance' => Dec::fix($loan['principal_balance']), 'interest_balance' => Dec::fix($loan['interest_balance']),
                    'penalty_due' => Dec::fix($d['penalty_due']), 'interest_due' => Dec::fix($d['interest_due']), 'principal_due' => Dec::fix($d['principal_due']),
                    'total_due' => Dec::fix($d['total_due']), 'payoff' => Dec::fix($d['payoff']), 'interest_to_date' => Dec::fix($d['interest_to_date']),
                    'count_due' => $d['count_due'], 'next_due' => $d['next_due'], 'amortization' => Dec::fix((string)DB::value('SELECT total FROM loan_schedule WHERE loan_id = :l ORDER BY inst_no LIMIT 1', ['l' => $loan['id']]))];
            }
            $s = Coop::memberSummary((int)$m['id']);
            json_out([
                'member' => ['id' => (int)$m['id'], 'no' => $m['member_no'], 'name' => $m['full_name'], 'status' => $m['status'], 'hold' => (int)$m['hold_flag'] === 1,
                    'hold_reason' => (string)$m['hold_reason'], 'alert' => (string)$m['alert_note'], 'subscribed' => Dec::fix($m['subscribed_capital'])],
                'summary' => array_map(fn($v) => is_string($v) ? Dec::fix($v) : $v, $s),
                'accounts' => array_map(fn($r) => ['id' => (int)$r['id'], 'no' => $r['account_no'], 'product' => $r['name'], 'type' => $r['product_type'], 'status' => $r['status'],
                    'balance' => Dec::fix($r['balance']), 'available' => Dec::fix(Savings::available($r)), 'min_deposit' => Dec::fix($r['min_deposit']), 'withdrawable' => (int)$r['allow_withdrawal'] === 1], $accounts),
                'loans' => $loans,
                'card' => member_card($m),
            ]);

        case 'accounts':
            // savings, special savings and share capital accounts by member name (every word typed), member no.,
            // account no. or passbook no., for the search-as-you-type box of Savings Deposit / Withdrawal
            $q = trim((string)g('q'));
            if ($q === '') {
                json_out(['accounts' => []]);
            }
            [$bf, $bp] = Coop::branchFilter('a.branch_id', 'ab');
            $where = "a.status <> 'closed' AND p.product_type <> 'time_deposit'" . $bf;
            $prm = $bp;
            $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', $q) ?: [], fn($w) => $w !== '')), 0, 5);
            $nameCond = [];
            foreach ($words as $i => $w) {
                $nameCond[] = "m.full_name LIKE :w$i";
                $prm["w$i"] = '%' . $w . '%';
            }
            $where .= ' AND ((' . ($nameCond ? implode(' AND ', $nameCond) : '1 = 0') . ') OR a.account_no LIKE :an OR m.member_no LIKE :mn OR a.passbook_no = :pb1 OR m.passbook_no = :pb2)';
            $prm += ['an' => $q . '%', 'mn' => $q . '%', 'pb1' => $q, 'pb2' => $q];
            $rows = DB::all("SELECT a.account_no, a.status, a.balance, a.uncleared, a.hold_amount, p.name AS product, p.product_type, m.full_name, m.member_no, m.status AS member_status,
                                    CASE WHEN m.full_name LIKE :first THEN 0 ELSE 1 END AS starts
                               FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                              WHERE $where ORDER BY starts, m.full_name, p.sort_order, a.account_no LIMIT 30", $prm + ['first' => ($words[0] ?? $q) . '%']);
            json_out(['accounts' => array_map(fn($r) => ['no' => $r['account_no'], 'name' => $r['full_name'], 'member_no' => $r['member_no'], 'product' => $r['product'],
                'status' => ['active' => 'Active', 'pending' => 'Waiting for approval', 'dormant' => 'Dormant'][$r['status']] ?? ucfirst((string)$r['status']), 'balance' => Dec::money($r['balance']),
                'available' => Dec::money(Savings::available($r))], $rows)]);

        case 'loans':
            // the borrowers with outstanding loans (name, loan product, loan no.), for the search box of the Loan
            // Statement of Account: by every word of a name, a loan no., a member no. or a loan product
            if (!Access::page('rpt.loan_soa') && !Access::page('loan.accounts') && !Access::page('loan.adjustments')) {
                json_out(['error' => 'Not allowed.'], 403);
            }
            $r = Loans::searchOutstanding((string)g('q'), 50);
            json_out(['loans' => $r['rows'], 'total' => $r['total']]);

        case 'account':
            $acct = Savings::byNo((string)g('no'));
            if (!$acct || !Coop::branchAllowed($acct['branch_id'] ? (int)$acct['branch_id'] : null)) {
                json_out(['error' => 'No such account.']);
            }
            json_out(['account' => ['id' => (int)$acct['id'], 'no' => $acct['account_no'], 'member' => $acct['member_name'], 'member_no' => $acct['member_no'], 'product' => $acct['product_name'],
                'status' => $acct['status'], 'balance' => Dec::fix($acct['balance']), 'available' => Dec::fix(Savings::available($acct))]]);

        case 'schedule':
            $principal = Dec::of(g('principal'));
            if (!Dec::isPos($principal)) {
                json_out(['rows' => [], 'error' => 'Enter the amount.']);
            }
            $product = gint('product_id') ? Loans::product((int)gint('product_id')) : null;
            $term = (string)(g('term') ?: '12');
            $unit = g('term_unit') === 'days' ? 'days' : 'months';
            $mode = isset(Coop::MODES[g('mode')]) ? (string)g('mode') : ($product['mode_of_payment'] ?? 'monthly');
            $date = gdate('date', today());
            $rate = g('rate') !== '' ? Dec::of(g('rate')) : ($product ? Loans::rateFor($product, Loans::termMonths($term, $unit), $principal, $date) : '0');
            $method = $product['interest_method'] ?? (g('method') === 'straight' ? 'straight' : 'diminishing');
            $amort = isset(Loans::AMORT_TYPES[g('amort')]) ? (string)g('amort') : ($product['amort_type'] ?? 'equal_amortization');
            $start = gdate('start', Coop::nthDue($date, $mode, 1));
            $charges = [];
            $totalCharges = '0';
            $loanAmount = $principal;
            if ($product) {
                foreach (Loans::charges($product, $principal, Loans::termMonths($term, $unit), $date) as $c) {
                    $charges[] = ['name' => $c['charge']['name'], 'basis' => $c['basis'], 'amount' => Dec::fix($c['amount'])];
                    $totalCharges = Dec::add($totalCharges, $c['amount'], 2);
                }
                if ($product['charge_method'] === 'capitalized') {
                    $loanAmount = Dec::add($principal, $totalCharges, 2);
                }
            }
            $s = Loans::schedule($loanAmount, $rate, $term, $unit, $mode, $method, $amort, $start, (int)($product['calendar_days'] ?? 365) ?: 365, $product['diminishing_by'] ?? 'same');
            $deducted = ($product['charge_method'] ?? 'deducted') === 'deducted' ? $totalCharges : '0';
            // renewal / restructuring: the open loan paid off from the proceeds
            $payoff = '0';
            if (gint('prev_loan_id')) {
                $prev = Loans::loan((int)gint('prev_loan_id'));
                if ($prev && in_array($prev['status'], Loans::OPEN_STATUSES, true) && Coop::branchAllowed($prev['branch_id'] ? (int)$prev['branch_id'] : null)) {
                    $payoff = Loans::dues($prev, gdate('release', $date))['payoff'];
                }
            }
            json_out(['rate' => Dec::fix($rate, 4), 'mode' => Coop::modeLabel($mode), 'installments' => $s['installments'], 'total_interest' => Dec::fix($s['total_interest']),
                'due_date' => fdate($s['due_date']), 'charges' => $charges, 'total_charges' => Dec::fix($totalCharges), 'payoff' => Dec::fix($payoff),
                'net_proceeds' => Dec::fix(Dec::sub(Dec::sub($principal, $deducted, 2), $payoff, 2)),
                'principal' => Dec::fix($loanAmount), 'charge_method' => $product['charge_method'] ?? 'deducted',
                'rows' => array_map(fn($r) => ['n' => $r['inst_no'], 'date' => fdate($r['due_date']), 'principal' => Dec::fix($r['principal']), 'interest' => Dec::fix($r['interest']),
                    'total' => Dec::fix($r['total']), 'balance' => Dec::fix($r['balance_after'])], $s['rows'])]);

        case 'release_plan':
            // the Loan Release Form while it is filled in: the deductions, the net proceeds, the due date
            if (!Access::page('loan.release')) {
                json_out(['error' => 'Not allowed.'], 403);
            }
            require_once APP_ROOT . '/modules/coop/_loan_release.php';
            $app = Loans::application((int)g('app'));
            if (!$app || !Coop::branchAllowed($app['branch_id'] ? (int)$app['branch_id'] : null)) {
                json_out(['error' => 'Application not found.'], 404);
            }
            $grid = g('charges');
            $in = ['term' => g('term'), 'term_unit' => g('term_unit'), 'mode' => g('mode'), 'rate' => g('rate'), 'amort' => g('amort'), 'release' => g('release'),
                'start' => g('start'), 'prev_loan_id' => gint('prev_loan_id'), 'charges' => is_string($grid) && $grid !== '' ? json_decode($grid, true) : null];
            if ($in['charges'] !== null && !is_array($in['charges'])) {
                json_out(['error' => 'The deductions could not be read.'], 422);
            }
            json_out(lr_plan($app, $in) + ['choices' => lr_charge_choices($app, is_numeric(g('term')) ? (string)g('term') : null, (string)g('term_unit') ?: null)]);

        case 'capacity':
            // a new loan application: Capacity to pay starts from the borrower's last loan (or the Financial Information)
            if (!Access::can('loan.application', 'create') && !Access::can('loan.application', 'edit')) {
                json_out(['error' => 'Not allowed.'], 403);
            }
            $m = Coop::member((int)g('member'));
            if (!$m || !Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
                json_out(['error' => 'Member not found.'], 404);
            }
            $c = Loans::capacityStart((int)$m['id'], gint('except') ?: null);
            json_out($c + ['note' => coop_capacity_note($c)]);

        case 'dues':
            $loan = Loans::loan((int)g('loan_id'));
            if (!$loan || !Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
                json_out(['error' => 'Loan not found.'], 404);
            }
            $d = Loans::dues($loan, gdate('date', today()));
            $amount = g('amount') !== '' ? Dec::of(g('amount')) : null;
            $alloc = null;
            if ($amount !== null && Dec::isPos($amount)) {
                [$pen, $int, $prn, $excess] = Loans::allocate($d, $amount, null);
                $alloc = ['penalty' => Dec::fix($pen), 'interest' => Dec::fix($int), 'principal' => Dec::fix($prn), 'excess' => Dec::fix($excess)];
            }
            unset($d['future']);
            json_out(['dues' => array_map(fn($v) => is_string($v) && is_numeric($v) ? Dec::fix($v) : $v, $d), 'allocation' => $alloc]);

        default:
            json_out(['error' => 'Unknown request.'], 400);
    }
} catch (UserError $e) {
    json_out(['error' => $e->getMessage()], 422);
}
