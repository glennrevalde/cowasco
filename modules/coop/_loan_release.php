<?php
declare(strict_types=1);

/**
 * The computation behind the Loan Release Form (Loans > Loan Release): the deductions as the form has them (the
 * product's own, or added, deleted and changed by the user), the net proceeds, the schedule and the due date. Used by
 * the page when it opens and by api.coop?a=release_plan while the user changes the form.
 */
require_once __DIR__ . '/_coop.php';

/** The account a deduction goes to, as the form shows it: the member's savings account no., or the GL account */
function lr_charge_account(array $c, int $memberId): string
{
    if ($c['charge_type'] === 'savings') {
        $no = DB::value("SELECT account_no FROM sav_accounts WHERE member_id = :m AND product_id = :p AND status IN ('active','pending') ORDER BY id LIMIT 1", ['m' => $memberId, 'p' => (int)$c['sav_product_id']]);
        return $no ? (string)$no : 'new ' . (string)DB::value('SELECT name FROM sav_products WHERE id = :p', ['p' => (int)$c['sav_product_id']]) . ' account';
    }
    try {
        $id = (int)($c['account_id'] ?: ($c['charge_type'] === 'financial' ? Accts::get('loan_service_fee') : Accts::get('coop_insurance_payable')));
    } catch (UserError $e) {
        return '';
    }
    return $id ? (string)DB::value('SELECT code FROM accounts WHERE id = :a', ['a' => $id]) : '';
}

/**
 * The plan of a release from the form's values. $in: term, term_unit, mode, rate, amort, release, start, prev_loan_id and
 * charges (rows charge_id / rate / amount; null = the product's own deductions).
 * Returns the figures for the form, with 'rows' (one per row of the grid, in its order) and 'error' when a row is wrong.
 */
function lr_plan(array $app, array $in): array
{
    $p = Loans::product((int)$app['product_id']);
    $approved = Dec::round($app['approved_amount'] ?: $app['applied_amount'], 2);
    $term = trim((string)($in['term'] ?? '')) !== '' && is_numeric($in['term']) ? Dec::of((string)$in['term']) : Dec::of($app['term']);
    $unit = ($in['term_unit'] ?? $app['term_unit']) === 'days' ? 'days' : 'months';
    $mode = isset(Coop::MODES[$in['mode'] ?? '']) ? (string)$in['mode'] : $app['mode_of_payment'];
    $release = valid_date((string)($in['release'] ?? '')) ? (string)$in['release'] : today();
    $start = valid_date((string)($in['start'] ?? '')) ? (string)$in['start'] : Coop::nthDue($release, $mode, 1);
    $rate = trim((string)($in['rate'] ?? '')) !== '' && is_numeric($in['rate']) ? Dec::of((string)$in['rate']) : Dec::of($app['rate']);
    $amort = isset(Loans::AMORT_TYPES[$in['amort'] ?? '']) ? (string)$in['amort'] : ($app['amort_type'] ?: $p['amort_type']);
    $months = Loans::termMonths(Dec::fix($term, 2), $unit);
    $out = ['approved' => Dec::fix($approved), 'charge_method' => $p['charge_method'], 'error' => null, 'rows' => []];
    if (!Dec::isPos($term)) {
        return $out + ['error' => 'Enter the term.'];
    }
    // renewal / restructuring: the open loan paid off from the proceeds on the release date
    $payoff = '0';
    $prevId = (int)($in['prev_loan_id'] ?? 0);
    if ($prevId) {
        $prev = Loans::loan($prevId);
        if ($prev && in_array($prev['status'], Loans::OPEN_STATUSES, true) && (int)$prev['member_id'] === (int)$app['member_id']) {
            $payoff = Loans::dues($prev, $release)['payoff'];
        }
    }
    $terms = ['term' => Dec::fix($term, 2), 'term_unit' => $unit, 'mode_of_payment' => $mode, 'rate' => Dec::fix($rate, 4), 'amort_type' => $amort,
        'start_date' => $start, 'release_date' => $release, 'previous_loan_payoff' => $payoff];
    $grid = $in['charges'] ?? null;
    if (is_array($grid)) {
        // each row of the grid on its own, so the form gets back the amount of every row in its place
        $ids = [];
        foreach (array_values($grid) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $res = null;
            $err = null;
            try {
                $res = Loans::customCharges($p, $approved, $months, [$row], $release)[0] ?? null;
            } catch (UserError $e) {
                $err = $e->getMessage();
            }
            $cid = (int)($row['charge_id'] ?? 0);
            if ($cid && isset($ids[$cid])) {
                $err = ($res['charge']['name'] ?? 'A charge') . ' is listed twice in the deductions.';
            }
            if ($cid) {
                $ids[$cid] = true;
            }
            if (!$res && $cid && $err === null && ($c = DB::row('SELECT * FROM loan_charges WHERE id = :c', ['c' => $cid]))) {
                // a deduction brought down to zero: it stays on the form, and is left out of the release
                $out['rows'][] = ['charge_id' => $cid, 'name' => $c['name'], 'account' => lr_charge_account($c, (int)$app['member_id']), 'rate' => trim((string)($row['rate'] ?? '')) ?: null,
                    'amount' => '0.00', 'basis' => '', 'is_rate' => Loans::isRateCharge($c), 'error' => null];
                continue;
            }
            $out['rows'][] = $res ? ['charge_id' => (int)$res['charge']['id'], 'name' => $res['charge']['name'], 'account' => lr_charge_account($res['charge'], (int)$app['member_id']),
                'rate' => $res['rate'], 'amount' => Dec::fix($res['amount']), 'basis' => $res['basis'], 'is_rate' => Loans::isRateCharge($res['charge']), 'error' => $err]
                : ['charge_id' => $cid ?: null, 'name' => '', 'account' => '', 'rate' => null, 'amount' => null, 'basis' => '', 'is_rate' => false, 'error' => $err];
            $out['error'] ??= $err;
        }
        $terms['charges'] = $grid;
    }
    try {
        $plan = Loans::releasePlan($app, $terms);
    } catch (UserError $e) {
        unset($terms['charges']);
        $plan = Loans::releasePlan($app, $terms);
        $out['error'] ??= $e->getMessage();
    }
    if (!is_array($grid)) {
        foreach ($plan['charges'] as $ch) {
            $out['rows'][] = ['charge_id' => (int)$ch['charge']['id'], 'name' => $ch['charge']['name'], 'account' => lr_charge_account($ch['charge'], (int)$app['member_id']),
                'rate' => $ch['rate'] ?? null, 'amount' => Dec::fix($ch['amount']), 'basis' => $ch['basis'], 'is_rate' => Loans::isRateCharge($ch['charge']), 'error' => null];
        }
    }
    $s = $plan['schedule'];
    if (Dec::isNeg($plan['net_proceeds'])) {
        $out['error'] ??= 'The deductions and the previous loan payoff (' . Dec::money(Dec::add($plan['deducted'], $plan['payoff'])) . ') exceed the approved amount.';
    }
    return $out + [
        'principal' => Dec::fix($plan['principal']), 'total_charges' => Dec::fix($plan['total_charges']), 'deducted' => Dec::fix($plan['deducted']), 'payoff' => Dec::fix($plan['payoff']),
        'net_proceeds' => Dec::fix($plan['net_proceeds']), 'due_date' => $s['due_date'], 'due_date_text' => fdate($s['due_date']), 'start' => $start,
        'installments' => $s['installments'], 'total_interest' => Dec::fix($s['total_interest']), 'amortization' => Dec::fix($s['rows'][0]['total'] ?? '0'),
        'rate' => Dec::fix($rate, 4), 'mode_text' => Coop::modeLabel($mode),
    ];
}

/** The deductions the form can add: every active charge with its standard rate / amount for this loan */
function lr_charge_choices(array $app, ?string $term = null, ?string $unit = null): array
{
    $p = Loans::product((int)$app['product_id']);
    $approved = Dec::round($app['approved_amount'] ?: $app['applied_amount'], 2);
    $months = Loans::termMonths(Dec::fix($term ?? $app['term'], 2), ($unit ?? $app['term_unit']) === 'days' ? 'days' : 'months');
    $out = [];
    foreach (Loans::chargeChoices((int)$p['id']) as $c) {
        [$amt, $basis, $rate] = Loans::chargeAmount($c, $approved, $months);
        $out[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'type' => $c['charge_type'], 'is_rate' => Loans::isRateCharge($c), 'rate' => $rate, 'amount' => Dec::fix($amt),
            'basis' => $basis, 'account' => lr_charge_account($c, (int)$app['member_id']), 'product' => $c['product_sort'] !== null];
    }
    return $out;
}
