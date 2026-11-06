<?php
declare(strict_types=1);

/**
 * Loan Collection Form, in the layout of the cooperative's former program:
 *   OR Entry          the official receipt number, the member paying (Receive from), the payment mode (Cash, Check, or
 *                     Bank: deposited to the cooperative's bank, the member shows the deposit slip), the cash account,
 *                     the cheques, the transaction date, the amount and Back Entry
 *   Open Loans        the member's loans that can be paid; View Amortization and View Subsidiary open the schedule and
 *                     the member's loan ledger
 *   Amortization Due Details & Payments
 *                     for the principal, the interest and the penalty: the balance, what is due as of the date, the
 *                     number of amortizations, and the payment applied (penalties, then interest, then principal unless
 *                     Override Payment); the last payment date
 *   Memo, Print to Passbook, Show Print Preview after saving, Error Correct (an erroneous payslip), Update Passbook,
 *   Clear, Save
 *   Loan Collections  the collections of a date range (This Day, This Week, This Month, Custom Date) with Delete, Print
 *                     and the class of the entry
 */
require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.collection';
$canCreate = Access::can($pageKey, 'create');

// the former list of collections is the list under the form now
if (g('a') === 'list') {
    redirect(url($pageKey, array_filter(['range' => 'custom', 'from' => gdate('from', date('Y-m-01')), 'to' => gdate('to', today())])) . '#lcCollections');
}

/** A loan the user may see: the row, or null */
$loanFor = function (?int $id): ?array {
    $l = $id ? Loans::loan($id) : null;
    return $l && Coop::branchAllowed($l['branch_id'] ? (int)$l['branch_id'] : null) ? $l : null;
};
/** An amount as the passbook shows it: an error correction negative */
$signed = fn($v): string => (Dec::isNeg($v) ? '-' : '') . cm(Dec::abs($v));

// ------------------------------------------------------------------ the figures of the form (as the teller types)
if (g('a') === 'figures') {
    $l = $loanFor(gint('loan'));
    if (!$l) {
        json_out(['error' => 'Loan not found.'], 404);
    }
    try {
        $date = gdate('date', today());
        $det = LoanTxn::dueDetails($l, $date);
        $dues = Loans::dues($l, $date);
        $alloc = null;
        if (g('payoff') === '1') {
            $int = $l['interest_method'] === 'diminishing' ? Dec::add($dues['interest_due'], $dues['interest_to_date'], 2) : $dues['interest_balance'];
            $alloc = ['penalty' => Dec::fix($dues['penalty_due']), 'interest' => Dec::fix($int), 'principal' => Dec::fix($dues['principal_balance']), 'excess' => '0.00'];
        } else {
            $amount = trim(str_replace(',', '', (string)g('amount')));
            if ($amount !== '' && is_numeric($amount) && Dec::isPos(Dec::of($amount))) {
                [$pen, $int, $prn, $excess] = Loans::allocate($dues, Dec::of($amount), null);
                $alloc = ['penalty' => Dec::fix($pen), 'interest' => Dec::fix($int), 'principal' => Dec::fix($prn), 'excess' => Dec::fix($excess)];
            }
        }
        json_out(['loan' => ['id' => (int)$l['id'], 'no' => $l['loan_no'], 'name' => $l['product_name']], 'details' => $det, 'allocation' => $alloc, 'payoff' => Dec::fix($dues['payoff']),
            'last_payment' => $det['last_payment'] ? fdate((string)$det['last_payment']) : '', 'next_due' => $det['next_due'] ? fdate((string)$det['next_due']) : '']);
    } catch (UserError $e) {
        json_out(['error' => $e->getMessage()], 422);
    }
}

// ------------------------------------------------------------------ View Amortization / View Subsidiary (the dialog's contents)
if (in_array(g('a'), ['schedule', 'ledger'], true)) {
    $l = $loanFor(gint('loan'));
    // the contents of the dialog; opened as a page of its own, it is the form on that loan
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
        redirect(url($pageKey, $l ? ['loan' => $l['id']] : []));
    }
    if (!$l) {
        http_response_code(404);
        echo '<p class="text-muted mb-0">Loan not found.</p>';
        exit;
    }
    $asOf = gdate('date', today());
    echo '<div class="lc-view-head"><div><strong>' . h($l['product_name']) . '</strong> ' . h($l['loan_no']) . ($l['pn_no'] ? ', PN ' . h($l['pn_no']) : '') . '</div>'
        . '<div class="small text-muted">' . h($l['member_name']) . ' (' . h($l['member_no']) . '), principal ' . cm($l['principal']) . ', released ' . h(fdate($l['release_date'])) . ', due ' . h(fdate($l['due_date'])) . '</div></div>';
    if (g('a') === 'schedule') {
        $rows = Loans::scheduleRows((int)$l['id']);
        $tot = ['p' => '0', 'i' => '0', 't' => '0', 'pp' => '0', 'ip' => '0'];
        echo '<div class="table-responsive lc-view-table"><table class="table table-sm sv-grid mb-0"><thead><tr><th>#</th><th>Due date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amortization</th><th class="num">Balance after</th><th class="num">Principal paid</th><th class="num">Interest paid</th><th>Paid</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            foreach (['p' => 'principal', 'i' => 'interest', 't' => 'total', 'pp' => 'principal_paid', 'ip' => 'interest_paid'] as $k => $f) {
                $tot[$k] = Dec::add($tot[$k], $r[$f], 2);
            }
            $late = !(int)$r['is_paid'] && $r['due_date'] < $asOf;
            echo '<tr class="' . ($late ? 'is-late' : '') . '"><td>' . (int)$r['inst_no'] . '</td><td>' . h(fdate($r['due_date'])) . '</td><td class="num">' . cm($r['principal']) . '</td><td class="num">' . cm($r['interest']) . '</td>'
                . '<td class="num">' . cm($r['total']) . '</td><td class="num">' . cm($r['balance_after']) . '</td><td class="num">' . cm($r['principal_paid'], true) . '</td><td class="num">' . cm($r['interest_paid'], true) . '</td>'
                . '<td>' . ((int)$r['is_paid'] ? '<span class="text-success">Paid</span>' : ($late ? '<span class="text-danger">Overdue</span>' : '')) . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="9" class="text-muted">The loan has no amortization schedule.</td></tr>';
        }
        echo '</tbody><tfoot><tr class="fw-semibold"><td colspan="2">Total</td><td class="num">' . cm($tot['p']) . '</td><td class="num">' . cm($tot['i']) . '</td><td class="num">' . cm($tot['t']) . '</td><td></td><td class="num">' . cm($tot['pp']) . '</td><td class="num">' . cm($tot['ip']) . '</td><td></td></tr></tfoot></table></div>';
    } else {
        $rows = DB::all('SELECT x.*, t.txn_no FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id WHERE x.loan_id = :l ORDER BY x.txn_date, x.id', ['l' => $l['id']]);
        $kinds = ['release' => 'Release', 'payment' => 'Payment', 'correction' => 'Error correct', 'penalty' => 'Penalty', 'adjustment' => 'Adjustment', 'waiver' => 'Waiver', 'accrual' => 'Interest accrual',
            'writeoff' => 'Write-off', 'recovery' => 'Recovery', 'restructure' => 'Renewal / restructure', 'pastdue' => 'Past due interest'];
        echo '<div class="table-responsive lc-view-table"><table class="table table-sm sv-grid mb-0"><thead><tr><th>Date</th><th>Entry</th><th>Reference</th><th class="num">Principal paid</th><th class="num">Interest paid</th><th class="num">Penalty paid</th><th class="num">Charged</th><th class="num">Principal balance</th><th class="num">Interest balance</th><th class="num">Penalty balance</th><th>Teller</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $memoOnly = $r['kind'] === 'accrual';
            $charged = Dec::add(Dec::add($r['principal_dr'], $r['interest_dr'], 2), $r['penalty_dr'], 2);
            $cell = fn($v) => Dec::isZero($v) ? '' : '<span class="' . (Dec::isNeg($v) ? 'neg' : '') . '">' . $signed($v) . '</span>';
            $kindLabel = $kinds[$r['kind']] ?? ucfirst((string)$r['kind']);
            echo '<tr class="' . ((int)$r['is_voided'] ? 'is-void' : '') . '"><td>' . h(fdate($r['txn_date'])) . '</td><td class="wrap">' . h($kindLabel)
                . ($r['memo'] && strcasecmp(trim((string)$r['memo']), $kindLabel) !== 0 ? '<div class="small text-muted">' . h((string)$r['memo']) . '</div>' : '') . ((int)$r['is_voided'] ? '<div class="small">deleted</div>' : '') . (!empty($r['corrected_by_id']) ? '<div class="small text-muted">corrected</div>' : '') . '</td>'
                . '<td>' . h((string)($r['or_no'] ?: $r['txn_no'])) . '</td>'
                . ($memoOnly ? '<td colspan="4" class="small text-muted">' . cm($r['interest_dr']) . ' income recognized; the member\'s balance does not change</td>'
                    : '<td class="num">' . $cell($r['principal_cr']) . '</td><td class="num">' . $cell($r['interest_cr']) . '</td><td class="num">' . $cell($r['penalty_cr']) . '</td><td class="num">' . $cell($charged) . '</td>')
                . '<td class="num">' . cm($r['principal_bal']) . '</td><td class="num">' . cm($r['interest_bal']) . '</td><td class="num">' . cm($r['penalty_bal']) . '</td>'
                . '<td>' . (($tid = (int)($r['teller_id'] ?: $r['created_by'])) ? $tid : '') . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="11" class="text-muted">No entry yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    exit;
}

// ------------------------------------------------------------------ the machine validation printed on the payslip
if (g('a') === 'validate') {
    $v = LoanTxn::validation((int)gint('id'));
    if (!$v || !Coop::branchAllowed($v['row']['branch_id'] ? (int)$v['row']['branch_id'] : null)) {
        flash('warning', 'Collection not found.');
        redirect(url($pageKey));
    }
    $ref = LoanTxn::ref($v['row']);
    $copy = coop_print_gate('loan_validation', 'transaction', (int)$v['row']['txn_id'], $ref, $pageKey, 'payslip validation');
    if ($copy === null) {
        exit;
    }
    // once printed, the window closes: the teller is back on the Loan Collection Form
    coop_slip_validation($v['lines'], $ref, 'Insert the loan payslip of ' . h($ref) . ' (' . h((string)$v['row']['full_name']) . ') in the printer, then print.', $copy,
        url($pageKey, ['member' => (int)DB::value('SELECT member_id FROM loan_accounts WHERE id = :l', ['l' => $v['row']['loan_id']]), 'loan' => (int)$v['row']['loan_id']]), ['id' => (int)$v['row']['txn_id']]);
    exit;
}

// ------------------------------------------------------------------ the date range of the list
$range = in_array(g('range'), ['today', 'week', 'month', 'custom'], true) ? (string)g('range') : (gdate('from') || gdate('to') ? 'custom' : 'today');
$t0 = today();
switch ($range) {
    case 'today':
        $lFrom = $lTo = $t0;
        break;
    case 'week':
        $lFrom = date('Y-m-d', strtotime('monday this week', strtotime($t0)));
        $lTo = date('Y-m-d', strtotime($lFrom . ' +6 days'));
        break;
    case 'month':
        $lFrom = date('Y-m-01', strtotime($t0));
        $lTo = date('Y-m-t', strtotime($t0));
        break;
    default:
        $lFrom = gdate('from', $t0);
        $lTo = gdate('to', $t0);
}
if ($lFrom > $lTo) {
    [$lFrom, $lTo] = [$lTo, $lFrom];
}
$lq = mb_substr(trim((string)g('q')), 0, 60);

/** The rows of the Loan Collections list */
$listTable = function (array $rows) use ($pageKey, $canCreate, $signed): string {
    $total = '0';
    ob_start(); ?>
    <table class="table table-sm sv-grid lc-coll mb-0" id="lcRows" data-select-grid="#lcListBar" aria-label="List of loan collections">
      <thead><tr><th>Transaction Date</th><th class="d-none d-md-table-cell">Trans. RefNo</th><th>Member name</th><th class="d-none d-md-table-cell">Loan Name</th><th class="num">Amount Paid</th></tr></thead><tbody>
      <?php foreach ($rows as $r):
          $ec = $r['kind'] === 'correction';
          $total = Dec::add($total, $r['amount'], 2);
          $ref = LoanTxn::ref($r);
          $del = $canCreate && (int)$r['pb_printed'] === 0 && empty($r['corrected_by_id']) ? '1' : '0'; ?>
        <tr data-row tabindex="0" aria-selected="false" data-id="<?= (int)$r['id'] ?>" data-ref="<?= h($ref) ?>" data-deletable="<?= $del ?>"
            data-name="<?= h($r['full_name']) ?>" data-amount="<?= h($signed($r['amount'])) ?>"
            data-or-url="<?= h(Txn::url(['id' => $r['txn_id'], 'txn_type' => (string)$r['txn_type']], 'print')) ?>" data-val-url="<?= h(url($pageKey, ['a' => 'validate', 'id' => $r['txn_id']])) ?>">
          <td><?= h(fdate($r['txn_date'])) ?><div class="small text-muted d-md-none"><?= h($ref) ?><?= $ec ? ', error correct' : '' ?></div></td>
          <td class="d-none d-md-table-cell"><?= h($ref) ?><?= $ec ? '<div class="small text-muted">Error correct</div>' : (!empty($r['corrected_by_id']) ? '<div class="small text-muted">corrected</div>' : '') ?><?= (int)$r['pb_printed'] ? '<div class="small text-muted">in passbook</div>' : '' ?></td>
          <td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted"><?= h($r['member_no']) ?><span class="d-md-none">, <?= h($r['product_name']) ?></span></div></td>
          <td class="wrap d-none d-md-table-cell"><?= h($r['product_name']) ?><div class="small text-muted"><?= h($r['loan_no']) ?></div></td>
          <td class="num <?= Dec::isNeg($r['amount']) ? 'neg' : '' ?>"><?= $signed($r['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No loan collection in this period.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="sv-grid-foot lc-coll-foot"><span><?= count($rows) ?> collection(s)</span><span>Total <strong class="<?= Dec::isNeg($total) ? 'text-danger' : '' ?>"><?= $signed($total) ?></strong></span></div>
    <?php
    return (string)ob_get_clean();
};

if (g('a') === 'print_list') {
    Access::require($pageKey, 'print');
    $rows = LoanTxn::collections($lFrom, $lTo, $lq);
    layout_start('Loan Collections', ['bare' => true]);
    echo coop_print_head('Loan Collections', '', fdate($lFrom) . ($lTo !== $lFrom ? ' to ' . fdate($lTo) : ''));
    $total = '0';
    echo '<table class="table table-sm print-table"><thead><tr><th>Transaction Date</th><th>Trans. RefNo</th><th>Member name</th><th>Loan Name</th><th>Loan no.</th><th class="num">Amount Paid</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $total = Dec::add($total, $r['amount'], 2);
        echo '<tr><td>' . h(fdate($r['txn_date'])) . '</td><td>' . h(LoanTxn::ref($r)) . ($r['kind'] === 'correction' ? ' (error correct)' : '') . '</td><td>' . h($r['full_name']) . '</td><td>' . h($r['product_name']) . '</td><td>' . h($r['loan_no']) . '</td><td class="num">' . $signed($r['amount']) . '</td></tr>';
    }
    echo '</tbody><tfoot><tr class="fw-semibold"><td colspan="5">' . count($rows) . ' collection(s)' . ($lq !== '' ? ', search "' . h($lq) . '"' : '') . '</td><td class="num">' . $signed($total) . '</td></tr></tfoot></table>';
    echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}

if (g('partial') === '1') {
    echo $listTable(LoanTxn::collections($lFrom, $lTo, $lq));
    exit;
}

// ------------------------------------------------------------------ posting
$back = function (array $extra = []) use ($pageKey): string {
    return url($pageKey, array_filter($extra, fn($v) => $v !== null && $v !== '' && $v !== 0));
};

handle_post(function () use ($pageKey, $loanFor, $back) {
    $action = (string)p('action', 'save');
    if ($action === 'pb_commit') {
        Access::require('sav.passbook', 'print');
        $l = $loanFor(pint('loan_id'));
        $pb = $l ? Passbook::open('loan', (string)$l['loan_no']) : null;
        if (!$pb || !$pb['type']) {
            fail('The loan product has no passbook type (Loan Products).');
        }
        // every page of the run that printed right (all of them, or the first ones)
        [$n, $pages] = Passbook::commitRun($pb, Passbook::postedRun(), (int)p('upto'), 'sav.passbook');
        $left = count(Passbook::pending(Passbook::open('loan', (string)$l['loan_no'])));
        flash('success', $n . ' line(s) of loan ' . $l['loan_no'] . ' marked as printed in the passbook (' . coop_pb_pages_words($pages) . ').' . ($left ? ' ' . $left . ' line(s) still to print: Update Passbook prints them.' : ''));
        redirect($back(['member' => (int)$l['member_id'], 'loan' => (int)$l['id']]));
    }
    Access::require($pageKey, 'create');
    if ($action === 'delete') {
        $x = DB::row('SELECT x.*, l.member_id FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id WHERE x.id = :id', ['id' => (int)pint('loan_txn_id')]);
        if (!$x) {
            fail('Select the collection to delete in the list.');
        }
        LoanTxn::delete((int)$x['id'], trim((string)p('reason')), $pageKey);
        flash('success', 'Collection ' . ($x['or_no'] ?: '#' . $x['id']) . ' was deleted (voided). The loan and the books were reversed with it.');
        redirect($back(['member' => pint('member') ?: null, 'range' => p('range'), 'from' => p('from'), 'to' => p('to')]) . '#lcCollections');
    }
    if ($action === 'error_correct') {
        coop_once();
        $x = DB::row('SELECT x.*, l.member_id FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id WHERE x.id = :id', ['id' => (int)pint('ec_id')]);
        if (!$x) {
            fail('Choose the erroneous payment to correct.');
        }
        if (trim((string)p('ec_memo')) === '') {
            fail('Enter the reason for the error correction.');
        }
        $classId = Classes::required('class_id', 'the error correction');
        $r = CoopLedger::withClass($classId, fn() => LoanTxn::errorCorrect((int)$x['id'], (string)p('ec_date', today()), (string)p('ec_memo'), $pageKey));
        redirect($back(['member' => (int)$x['member_id'], 'loan' => $r['loan_id'], 'done' => $r['txn_id'], 'pv' => p('ec_preview') === '1' ? 1 : 0, 'pp' => p('ec_print_pb') === '1' ? 1 : 0]));
    }
    // save: the official receipt of the payment
    coop_once();
    $m = pint('member') ? Coop::member((int)pint('member')) : null;
    if (!$m) {
        fail('Choose the member in Receive from.');
    }
    $l = $loanFor(pint('loan_id'));
    if (!$l || (int)$l['member_id'] !== (int)$m['id']) {
        fail('Select the loan to be paid in Open Loans.');
    }
    $leaving = in_array($m['status'], ['resigned', 'terminated', 'deceased'], true);
    $mode = (string)p('mode');
    if (!in_array($mode, $leaving ? ['cash', 'check', 'bank', 'savings'] : ['cash', 'check', 'bank'], true)) {
        fail('Choose the payment mode: Cash, Check or Bank.');
    }
    $date = (string)p('txn_date', today());
    if (p('back_entry') !== '1' && $date !== today()) {
        fail('Tick Back Entry to record the payment on an earlier date.');
    }
    $payoff = p('payoff') === '1';
    $item = ['kind' => 'loan', 'loan_id' => (int)$l['id'], 'amount' => $payoff ? '0' : Coop::amount('amount', true), 'payoff' => $payoff];
    if (!$payoff && p('use_override') === '1') {
        $ov = (array)($_POST['override'] ?? []);
        $num = fn($v) => Dec::of(trim(str_replace(',', '', (string)$v)) === '' ? '0' : str_replace(',', '', (string)$v));
        $item['override'] = ['penalty' => $num($ov['penalty'] ?? '0'), 'interest' => $num($ov['interest'] ?? '0'), 'principal' => $num($ov['principal'] ?? '0')];
    }
    $cheques = [];
    foreach ((array)($_POST['chq'] ?? []) as $c) {
        if (is_array($c) && (trim((string)($c['no'] ?? '')) !== '' || to_num($c['amount'] ?? 0))) {
            $cheques[] = ['cheque_no' => (string)($c['no'] ?? ''), 'bank' => (string)($c['bank'] ?? ''), 'date' => (string)($c['date'] ?? ''), 'amount' => (string)to_num($c['amount'] ?? 0)];
        }
    }
    if ($mode === 'check' && !$cheques) {
        fail('Enter the cheque in Cheque Payment Details.');
    }
    $pay = ['mode' => $mode, 'cash_account_id' => $mode === 'bank' ? pint('bank_account_id') : null, 'cheques' => $mode === 'check' ? $cheques : [], 'from_account_id' => pint('from_account_id')];
    $classId = Classes::required('class_id', 'the loan payment');
    $r = CoopLedger::withClass($classId, fn() => Teller::receipt((int)$m['id'], $date, [$item], $pay,
        ['receipt_no' => trim((string)p('receipt_no')), 'memo' => trim((string)p('memo')), 'back_entry' => $date < today()], $pageKey));
    // the page says what was saved (and offers the validation and the receipt) from the done= of the address
    redirect($back(['member' => (int)$m['id'], 'loan' => (int)$l['id'], 'done' => $r['txn_id'], 'pv' => p('show_preview') === '1' ? 1 : 0, 'pp' => p('print_pb') === '1' ? 1 : 0]));
});

// ------------------------------------------------------------------ the page
$post = is_post();
$action = $post ? (string)p('action', 'save') : '';
$failed = $post && !empty($GLOBALS['FORM_ERROR']);
// a value of the form: what was posted, or what the address carries (kept when the member is chosen)
$val = function (string $k, string $def = '') use ($post, $action) {
    if ($post && $action === 'save') {
        return is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : $def;
    }
    return is_string($_GET[$k] ?? null) && trim($_GET[$k]) !== '' ? trim($_GET[$k]) : $def;
};
$m = null;
if ($post && pint('member')) {
    $m = Coop::member((int)pint('member'));
} elseif (gint('member')) {
    $m = Coop::member((int)gint('member'));
}
$urlLoan = $loanFor(gint('loan'));
if (!$m && $urlLoan) {
    $m = Coop::member((int)$urlLoan['member_id']);
}
if ($m && !Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
    flash('warning', 'That member belongs to another branch.');
    $m = null;
}
$loans = $m ? LoanTxn::openLoans((int)$m['id']) : [];
$selLoan = (int)(($post && $action === 'save' ? pint('loan_id') : null) ?: ($urlLoan && $m && (int)$urlLoan['member_id'] === (int)$m['id'] ? $urlLoan['id'] : 0));
// only a loan of the list can be selected (a loan paid off or closed is not)
if ($selLoan && !in_array($selLoan, array_map('intval', array_column($loans, 'id')), true)) {
    $selLoan = 0;
}
if (!$selLoan && count($loans) === 1) {
    $selLoan = (int)$loans[0]['id'];
}
$leaving = $m && in_array($m['status'], ['resigned', 'terminated', 'deceased'], true);
$modes = ['cash' => 'Cash', 'check' => 'Check', 'bank' => 'Bank'] + ($leaving ? ['savings' => 'Savings (settlement of a member who has left)'] : []);
$mode = $val('mode', 'cash');
$mode = isset($modes[$mode]) ? $mode : 'cash';
$shift = Teller::openShift();
$cashAcct = $shift ? (int)$shift['cash_account_id'] : Teller::currentCashAccount();
$coci = (int)(Savings::code('CK4')['opposite_account_id'] ?? 0) ?: (int)Accts::get('coop_coci');
$banks = Accts::banks();
$fromOpts = [];
if ($leaving) {
    foreach (DB::all("SELECT a.id, a.account_no, a.balance, p.name FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND a.status IN ('active','dormant') AND p.product_type IN ('ordinary','special') ORDER BY p.sort_order, a.account_no", ['m' => $m['id']]) as $s) {
        $fromOpts[$s['id']] = $s['account_no'] . ' ' . $s['name'] . ' (' . Dec::money($s['balance']) . ')';
    }
}
$canOverride = (bool)Access::special('coop_override_allocation');
$useOv = $post && $action === 'save' && p('use_override') === '1';
$ovPost = $useOv ? (array)($_POST['override'] ?? []) : [];
$chqRows = $post && $action === 'save' ? array_values(array_filter((array)($_POST['chq'] ?? []), 'is_array')) : [];
if (!$chqRows) {
    $chqRows = [[]];
}
$date = $val('txn_date', today());
$backOn = $val('back_entry') === '1';
$order = array_map(fn($x) => mb_strtolower(trim($x)), explode(',', (string)Coop::setting('allocation_order', 'penalty,interest,principal')));
$orderWords = ['penalty' => 'Due Penalties', 'interest' => 'Due Interest', 'principal' => 'Due Principal'];

// after saving: the validation of the payslip and the passbook
$done = gint('done') ? LoanTxn::validation((int)gint('done')) : null;
if ($done && !Coop::branchAllowed($done['row']['branch_id'] ? (int)$done['row']['branch_id'] : null)) {
    $done = null;
}
$doneLoanId = $done ? (int)$done['row']['loan_id'] : 0;
// the passbook of each loan on the form (and of the loan just paid off)
$pbLoans = [];
foreach ($loans as $l) {
    $pbLoans[(int)$l['id']] = $l;
}
if ($doneLoanId && !isset($pbLoans[$doneLoanId]) && ($dl = $loanFor($doneLoanId))) {
    $pbLoans[$doneLoanId] = $dl;
}
$pbPlans = [];
foreach ($pbLoans as $id => $l) {
    $pb = Passbook::open('loan', (string)$l['loan_no']);
    // every line not yet printed, page by page (one printing run turns the passbook from page to page)
    $pbPlans[$id] = ['pb' => $pb, 'run' => $pb && $pb['type'] ? Passbook::run($pb) : null, 'loan' => $l];
}
$ecRows = $m ? LoanTxn::correctableForMember((int)$m['id']) : [];
$rows = LoanTxn::collections($lFrom, $lTo, $lq);
$teller = Auth::user();

// the class of the entry (the Class List of the accounting books), chosen at the bottom of the form
$classOpts = Classes::ready() ? Classes::options() : [];
$classVal = $post && $action === 'save' ? (int)pint('class_id') : (int)(Classes::lastUsed() ?? 0);
$classSelect = function (string $id, string $formAttr = '', $value = null) use ($classOpts) {
    if (!Classes::ready()) {
        return '';
    }
    if (!$classOpts) {
        return '<select class="form-select form-select-sm" id="' . h($id) . '" name="class_id" ' . $formAttr . ' required disabled><option value="">No class set up</option></select>'
            . '<div class="form-text text-danger">Add the classes on Financial &gt; Class List first.</div>';
    }
    return '<select class="form-select form-select-sm" id="' . h($id) . '" name="class_id" ' . $formAttr . ' required data-no-combo data-class-required title="Required: the class of this transaction">'
        . options($classOpts, $value ?: null, 'Choose a class') . '</select>';
};
// the supervisor approval of one dialog: a request that needed it opens it again on that dialog only
$approval = function (string $for) use ($post, $action) {
    if ($post && $action !== $for) {
        $keep = [$GLOBALS['NEEDS_APPROVAL'] ?? null, $GLOBALS['APPROVAL_CARRY'] ?? null];
        unset($GLOBALS['NEEDS_APPROVAL'], $GLOBALS['APPROVAL_CARRY']);
        $html = Approval::fields();
        [$GLOBALS['NEEDS_APPROVAL'], $GLOBALS['APPROVAL_CARRY']] = $keep;
        return $html;
    }
    return Approval::fields();
};

$actions = [];
if (Access::page('loan.accounts')) {
    $actions[] = btn_link(url('loan.accounts'), 'Loan Accounts', 'btn-outline-secondary', 'journal-text');
}
coop_layout('Loan Collection Form', ['actions' => implode(' ', $actions), 'js' => ['js/savings.js', 'js/loan_collection.js']]);
?>
<?php if (!$shift && Coop::setting('or_require_shift', '1') === '1'): ?>
  <div class="alert alert-warning no-print">Your teller shift is not open. Open it on <?= safe_link('teller.shift', [], 'Teller Shift & Cash Count') ?> before receiving payments.</div>
<?php endif; ?>
<?php if ($done && (g('pv') === '1' || g('pp') === '1')):
    $pp = g('pp') === '1' && !empty($pbPlans[$doneLoanId]['run']['count']); ?>
<div data-after-save <?= g('pv') === '1' ? 'data-validate="#lcValModal"' : '' ?> <?= $pp ? 'data-passbook="#lcPbModal"' : '' ?>></div>
<?php endif; ?>
<?php if ($failed && in_array($action, ['error_correct', 'delete'], true)): ?><div data-lc-reopen="<?= $action === 'delete' ? '#lcDelModal' : '#lcEcModal' ?>" hidden></div><?php endif; ?>
<?php if ($done): $dr = $done['row']; ?>
<div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 no-print">
  <span><?= $dr['kind'] === 'correction' ? 'Error correction' : 'Official receipt' ?> <strong><?= h(LoanTxn::ref($dr)) ?></strong> saved: <?= $signed($dr['amount']) ?> on loan <?= h($dr['loan_no']) ?>, <?= h($dr['full_name']) ?>.</span>
  <span class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#lcValModal"><i class="bi bi-receipt-cutoff"></i> Validation</button>
    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= h(Txn::url(['id' => $dr['txn_id'], 'txn_type' => (string)DB::value('SELECT txn_type FROM transactions WHERE id = :t', ['t' => $dr['txn_id']])], 'print')) ?>"><i class="bi bi-printer"></i> <?= $dr['kind'] === 'correction' ? 'Voucher' : 'Official receipt' ?></a>
  </span>
</div>
<?php endif; ?>

<form method="post" class="card lc-form mb-3" id="lcForm" data-once data-today="<?= h(today()) ?>" <?= $post && $action === 'save' ? 'data-from-post="1"' : '' ?>
      data-figures="<?= h(url($pageKey, ['a' => 'figures'])) ?>" data-view="<?= h(url($pageKey, [])) ?>" data-clear-url="<?= h(url($pageKey)) ?>"
      data-confirm="Save official receipt for {total} on loan {loan}?" data-confirm-title="Save the loan payment" data-confirm-ok="Save">
  <?= csrf_field() ?><?= Coop::onceField() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="loan_id" id="lcLoanId" value="<?= $selLoan ?: '' ?>">
  <input type="hidden" id="lcLoanConfirm" data-confirm-value="loan" value="">
  <input type="hidden" id="lcTotalConfirm" data-confirm-value="total" value="">
  <div class="card-body pb-2">
    <!-- ============================================================ OR Entry -->
    <fieldset class="sv-group lc-or">
      <legend>OR Entry</legend>
      <div class="row g-3">
        <div class="col-lg-7">
          <div class="lc-kv">
            <label for="lcReceipt">Official Receipt #</label>
            <input class="form-control form-control-sm lc-orno" name="receipt_no" id="lcReceipt" value="<?= h($val('receipt_no')) ?>" maxlength="30" autocomplete="off" placeholder="<?= h(Seq::peek('coop_or')) ?>" title="Leave it empty for the next number of the series">
            <label for="mp_member">Receive from</label>
            <div class="member-pick lc-pick" data-member-pick data-api="<?= h(url('api.coop', ['a' => 'members'])) ?>" id="lcPick">
              <i class="bi bi-search"></i>
              <input type="search" class="form-control form-control-sm" id="mp_member" value="<?= h($m ? Coop::memberLabel($m) : '') ?>" placeholder="Member name or member no." autocomplete="off" <?= $m ? '' : 'autofocus' ?>>
              <input type="hidden" name="member" value="<?= $m ? (int)$m['id'] : '' ?>">
              <div class="member-pick-list" hidden></div>
            </div>
            <label for="lcMode">Payment Mode</label>
            <select class="form-select form-select-sm" name="mode" id="lcMode" data-no-combo>
              <?= options($modes, $mode) ?>
            </select>
            <label for="lcCashAcct" id="lcCashLabel"><?= $mode === 'bank' ? 'Bank Account' : 'Cash Account' ?></label>
            <div class="lc-cash">
              <input class="form-control form-control-sm sv-ro" id="lcCashAcct" data-mode-show="cash" value="<?= h($cashAcct ? Coop::accountLabel($cashAcct) : 'No cash account (open your teller shift)') ?>" readonly tabindex="-1" <?= $mode === 'cash' ? '' : 'hidden' ?>>
              <select class="form-select form-select-sm" name="bank_account_id" id="lcBank" data-mode-show="bank" data-no-combo <?= $mode === 'bank' ? '' : 'hidden' ?>>
                <?= options($banks, $val('bank_account_id'), $banks ? 'Choose the cooperative\'s bank account' : 'No bank account in the chart of accounts') ?>
              </select>
              <input class="form-control form-control-sm sv-ro" data-mode-show="check" value="<?= h($coci ? Coop::accountLabel($coci) : 'Checks and other cash items') ?>" readonly tabindex="-1" title="The cheques wait here until they are cleared on Check Clearing" <?= $mode === 'check' ? '' : 'hidden' ?>>
              <?php if ($leaving): ?>
              <select class="form-select form-select-sm" name="from_account_id" data-mode-show="savings" data-no-combo <?= $mode === 'savings' ? '' : 'hidden' ?>>
                <?= options($fromOpts, $val('from_account_id'), $fromOpts ? 'Choose the savings account' : 'No savings account') ?>
              </select>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($m && ((int)$m['hold_flag'] === 1 || trim((string)$m['alert_note']) !== '' || $leaving)): ?>
            <div class="small mt-2 <?= (int)$m['hold_flag'] === 1 ? 'text-danger' : 'text-warning-emphasis' ?>"><i class="bi bi-exclamation-triangle"></i>
              <?= $leaving ? h($m['full_name']) . ' is ' . h(mb_strtolower(Coop::MEMBER_STATUSES[$m['status']] ?? $m['status'])) . ': only loan payments are received. ' : '' ?><?= (int)$m['hold_flag'] === 1 ? 'On hold: ' . h((string)$m['hold_reason']) : h((string)$m['alert_note']) ?></div>
          <?php endif; ?>
        </div>
        <div class="col-12 order-last">
          <fieldset class="sv-group sv-cheques lc-cheques mb-0 <?= $mode === 'check' ? '' : 'is-off' ?>" id="lcChequeBox" <?= $mode === 'check' ? '' : 'disabled' ?>>
            <legend>Cheque Payment Details</legend>
            <div class="table-responsive"><table class="table table-sm" id="lcCheques" data-rows="lcChqTpl">
              <thead><tr><th>Cheque No</th><th>Bank Name</th><th>Date</th><th class="text-end">Amount</th><th aria-label="Remove"></th></tr></thead><tbody>
              <?php foreach ($chqRows as $i => $c): ?><tr>
                <td><input class="form-control form-control-sm" name="chq[<?= $i ?>][no]" value="<?= h((string)($c['no'] ?? '')) ?>" aria-label="Cheque no"></td>
                <td><input class="form-control form-control-sm" name="chq[<?= $i ?>][bank]" value="<?= h((string)($c['bank'] ?? '')) ?>" aria-label="Bank name"></td>
                <td><input class="form-control form-control-sm" type="date" name="chq[<?= $i ?>][date]" value="<?= h((string)($c['date'] ?? today())) ?>" aria-label="Cheque date"></td>
                <td><input class="form-control form-control-sm text-end" name="chq[<?= $i ?>][amount]" value="<?= h((string)($c['amount'] ?? '')) ?>" inputmode="decimal" data-money data-chq-amount aria-label="Amount"></td>
                <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del title="Remove the cheque"><i class="bi bi-x-lg"></i></button></td></tr><?php endforeach; ?>
              </tbody></table></div>
            <template id="lcChqTpl"><tr>
              <td><input class="form-control form-control-sm" name="chq[__i__][no]" aria-label="Cheque no"></td><td><input class="form-control form-control-sm" name="chq[__i__][bank]" aria-label="Bank name"></td>
              <td><input class="form-control form-control-sm" type="date" name="chq[__i__][date]" value="<?= h(today()) ?>" aria-label="Cheque date"></td>
              <td><input class="form-control form-control-sm text-end" name="chq[__i__][amount]" inputmode="decimal" data-money data-chq-amount aria-label="Amount"></td>
              <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del title="Remove the cheque"><i class="bi bi-x-lg"></i></button></td></tr></template>
            <div class="d-flex justify-content-between align-items-center small mt-1"><button type="button" class="btn btn-link btn-sm p-0" data-add-rows="lcCheques"><i class="bi bi-plus-lg"></i> Add a cheque</button>
              <span class="text-muted">Cheques: <strong data-chq-total>0.00</strong></span></div>
          </fieldset>
        </div>
        <div class="col-lg-5">
          <div class="lc-kv lc-kv-right">
            <label for="lcDate">Transaction Date</label>
            <div class="sv-field <?= $backOn ? '' : 'is-locked' ?>"><input class="form-control form-control-sm" type="date" name="txn_date" id="lcDate" value="<?= h($date) ?>" max="<?= h(today()) ?>" <?= $backOn ? '' : 'readonly' ?> required></div>
            <label for="lcAmount">Cash Amount</label>
            <input class="form-control form-control-sm sv-money sv-big" name="amount" id="lcAmount" value="<?= h($val('amount')) ?>" inputmode="decimal" data-money autocomplete="off" title="The payment amount">
            <label for="lcTotal">Total Amount</label>
            <input class="form-control form-control-sm sv-money sv-ro sv-big" id="lcTotal" value="" readonly tabindex="-1" title="The payment applied to the loan">
            <span></span>
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="back_entry" value="1" id="lcBack" <?= $backOn ? 'checked' : '' ?>><label class="form-check-label" for="lcBack">Back Entry</label></div>
          </div>
        </div>
      </div>
    </fieldset>

    <!-- ============================================================ Open Loans -->
    <fieldset class="sv-group lc-loans-box">
      <legend>Open Loans</legend>
      <?php if (!$m): ?>
        <div class="lc-empty">Choose the member in Receive from: the member's open loans are listed here.</div>
      <?php else: ?>
      <div class="sv-grid-wrap lc-loans-wrap"><table class="table table-sm sv-grid lc-loans mb-0" id="lcLoans" aria-label="Open loans of <?= h($m['full_name']) ?>">
        <thead><tr><th>Loan Name</th><th class="d-none d-md-table-cell">Date Released</th><th class="d-none d-sm-table-cell">Date Due</th><th class="d-none d-md-table-cell">PN No.</th>
          <th class="text-center"><span class="d-none d-md-inline">Amortization Schedule</span><span class="d-md-none">Schedule</span></th><th class="text-center"><span class="d-none d-md-inline">Subsidiary</span><span class="d-md-none">Ledger</span></th></tr></thead><tbody>
        <?php foreach ($loans as $l): $on = (int)$l['id'] === $selLoan; ?>
          <tr data-loan="<?= (int)$l['id'] ?>" data-no="<?= h($l['loan_no']) ?>" data-name="<?= h($l['product_name']) ?>" tabindex="0" role="button" aria-pressed="<?= $on ? 'true' : 'false' ?>" class="<?= $on ? 'is-selected' : '' ?>">
            <td class="wrap"><span class="lc-radio" aria-hidden="true"></span><strong><?= h($l['product_name']) ?></strong><div class="small text-muted"><?= h($l['loan_no']) ?><span class="d-md-none"><?= $l['pn_no'] ? ', PN ' . h($l['pn_no']) : '' ?>, released <?= h(fdate($l['release_date'])) ?></span><span class="d-sm-none">, due <?= h(fdate($l['due_date'])) ?></span><?= $l['status'] === 'past_due' ? ', <span class="text-danger">past due</span>' : '' ?></div></td>
            <td class="d-none d-md-table-cell"><?= h(fdate($l['release_date'])) ?></td>
            <td class="d-none d-sm-table-cell"><?= h(fdate($l['due_date'])) ?></td>
            <td class="d-none d-md-table-cell"><?= h((string)$l['pn_no']) ?></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-secondary lc-view-btn" data-lc-view="schedule" data-loan-id="<?= (int)$l['id'] ?>" title="The amortization schedule of <?= h($l['loan_no']) ?>"><i class="bi bi-calendar3"></i><span> View Amortization</span></button></td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-outline-secondary lc-view-btn" data-lc-view="ledger" data-loan-id="<?= (int)$l['id'] ?>" title="The loan ledger of <?= h($l['loan_no']) ?>"><i class="bi bi-journal-text"></i><span> View Subsidiary</span></button></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$loans): ?><tr><td colspan="6" class="text-muted"><?= h($m['full_name']) ?> has no open loan.</td></tr><?php endif; ?>
        </tbody></table></div>
      <?php endif; ?>
    </fieldset>

    <!-- ============================================================ Amortization Due Details & Payments -->
    <fieldset class="sv-group lc-amort-box" id="lcAmortBox">
      <legend>Amortization Due Details &amp; Payments <span class="lc-caption" id="lcLoanCaption"></span></legend>
      <div class="lc-amort-layout">
        <div class="lc-amort-wrap">
          <table class="lc-amort" aria-describedby="lcOrderNote">
            <thead><tr><th scope="col"><span class="visually-hidden">Figure</span></th><th scope="col">Principal</th><th scope="col">Interest</th><th scope="col">Penalty</th></tr></thead>
            <tbody>
              <tr><th scope="row">Current Balance</th>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="pBal" readonly tabindex="-1" aria-label="Principal: current balance"></td>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="iBal" readonly tabindex="-1" aria-label="Interest: current balance"></td>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="nBal" readonly tabindex="-1" aria-label="Penalty: current balance"></td></tr>
              <tr><th scope="row">Amortization Due</th>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="pDue" readonly tabindex="-1" aria-label="Principal: amortization due as of the date"></td>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="iDue" readonly tabindex="-1" aria-label="Interest: due as of the date"></td>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="nDue" readonly tabindex="-1" aria-label="Penalty: due as of the date"></td></tr>
              <tr class="lc-count"><th scope="row">#of Amort.</th>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="pCnt" readonly tabindex="-1" aria-label="Principal: number of amortizations due"></td>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="iCnt" readonly tabindex="-1" aria-label="Interest: number of amortizations due"></td>
                <td><input class="form-control form-control-sm sv-money sv-ro" id="nCnt" readonly tabindex="-1" aria-label="Penalty: number of overdue amortizations"></td></tr>
              <tr class="lc-paid-row"><th scope="row">Amount Paid</th>
                <td><input class="form-control form-control-sm sv-money lc-paid" name="override[principal]" id="pPaid" value="<?= h((string)($ovPost['principal'] ?? '')) ?>" readonly inputmode="decimal" autocomplete="off" aria-label="Payment applied to the principal"></td>
                <td><input class="form-control form-control-sm sv-money lc-paid" name="override[interest]" id="iPaid" value="<?= h((string)($ovPost['interest'] ?? '')) ?>" readonly inputmode="decimal" autocomplete="off" aria-label="Payment applied to the interest"></td>
                <td><input class="form-control form-control-sm sv-money lc-paid" name="override[penalty]" id="nPaid" value="<?= h((string)($ovPost['penalty'] ?? '')) ?>" readonly inputmode="decimal" autocomplete="off" aria-label="Payment applied to the penalties"></td></tr>
            </tbody>
          </table>
          <div class="lc-order" id="lcOrderNote">Payment is applied to <?= h(implode(', then ', array_map(fn($k) => $orderWords[$k] ?? $k, $order))) ?>, oldest amortization first. What is left, or the whole payment when no penalty and no interest is due, goes to the Principal.</div>
        </div>
        <div class="lc-amort-side">
          <div class="lc-last"><label class="form-label" for="lcLastPay">Last Payment Date</label>
            <input class="form-control form-control-sm sv-ro" id="lcLastPay" readonly tabindex="-1"></div>
          <input type="hidden" name="use_override" id="lcUseOv" value="<?= $useOv ? '1' : '' ?>">
          <button type="button" class="btn btn-sm btn-outline-secondary lc-ov-btn" id="lcOverride" aria-pressed="<?= $useOv ? 'true' : 'false' ?>" <?= $canOverride ? '' : 'disabled title="Needs the permission to override the payment allocation"' ?>><i class="bi bi-sliders"></i> Override Payment</button>
          <div class="form-check lc-payoff mb-0"><input class="form-check-input" type="checkbox" name="payoff" value="1" id="lcPayoff" <?= $post && $action === 'save' && p('payoff') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="lcPayoff">Full payment (payoff <span id="lcPayoffAmt">0.00</span>)</label></div>
        </div>
      </div>
    </fieldset>

    <!-- ============================================================ memo, print choices, the buttons -->
    <div class="lc-actions">
      <div class="lc-memo"><label class="form-label" for="lcMemo">Memo</label><textarea class="form-control form-control-sm" name="memo" id="lcMemo" rows="3" maxlength="160"><?= h($val('memo')) ?></textarea></div>
      <div class="lc-checks">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="print_pb" value="1" id="lcPrintPb" <?= $post && $action === 'save' ? (p('print_pb') === '1' ? 'checked' : '') : 'checked' ?>><label class="form-check-label" for="lcPrintPb">Print to Passbook</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="show_preview" value="1" id="lcPreview" <?= $post && $action === 'save' ? (p('show_preview') === '1' ? 'checked' : '') : 'checked' ?>><label class="form-check-label" for="lcPreview">Show Print Preview after saving</label></div>
        <div class="small"><?= $approval('save') ?></div>
      </div>
      <div class="lc-side-btns">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="lcEcBtn" data-bs-toggle="modal" data-bs-target="#lcEcModal" <?= $ecRows && $canCreate ? '' : 'disabled' ?> title="Negate an erroneous payslip (loan payment) in full"><i class="bi bi-arrow-counterclockwise"></i> Error Correct</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="lcPbBtn" data-bs-toggle="modal" data-bs-target="#lcPbModal" <?= $pbPlans ? '' : 'disabled' ?>><i class="bi bi-journal-arrow-down"></i> Update Passbook</button>
      </div>
      <div class="lc-main-btns">
        <button type="button" class="btn btn-outline-secondary lc-big" id="lcClear" title="Empty the form for the next payment"><i class="bi bi-eraser"></i> Clear</button>
        <button class="btn btn-primary lc-big" id="lcSave" <?= $canCreate && $m && $loans ? '' : 'disabled' ?>><i class="bi bi-check-lg"></i> Save</button>
      </div>
    </div>
  </div>
</form>

<!-- ============================================================ Loan Collections -->
<section class="card lc-list mb-3" id="lcCollections" aria-labelledby="lcCollTitle">
  <div class="card-body pb-2">
    <fieldset class="sv-group mb-0">
      <legend id="lcCollTitle">Loan Collections</legend>
      <form method="get" class="lc-filter" id="lcFilter" action="index.php" data-live-search data-live-target="#lcRowsWrap">
        <input type="hidden" name="p" value="<?= h($pageKey) ?>">
        <?php if ($m): ?><input type="hidden" name="member" value="<?= (int)$m['id'] ?>"><?php endif; ?>
        <?php if ($selLoan): ?><input type="hidden" name="loan" value="<?= $selLoan ?>"><?php endif; ?>
        <div class="lc-f-range"><label class="form-label" for="lcRange">Date range</label>
          <select class="form-select form-select-sm" name="range" id="lcRange" data-no-combo data-week-start="<?= h(date('Y-m-d', strtotime('monday this week', strtotime($t0)))) ?>">
            <?= options(['custom' => 'Custom Date', 'today' => 'This Day', 'week' => 'This Week', 'month' => 'This Month'], $range) ?>
          </select></div>
        <div class="lc-f-dates">
          <div><label class="form-label" for="lcFrom">From</label><input class="form-control form-control-sm" type="date" name="from" id="lcFrom" value="<?= h($lFrom) ?>"></div>
          <div><label class="form-label" for="lcTo">To</label><input class="form-control form-control-sm" type="date" name="to" id="lcTo" value="<?= h($lTo) ?>"></div>
        </div>
        <div class="lc-f-q"><label class="form-label" for="lcQ">Search</label>
          <div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" id="lcQ" value="<?= h($lq) ?>" placeholder="Name, member, loan or OR no." autocomplete="off"></div></div>
        <div class="lc-f-go"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Query</button></div>
      </form>
      <div class="sv-grid-wrap lc-coll-wrap" id="lcRowsWrap"><?= $listTable($rows) ?></div>
      <div class="lc-list-bar" id="lcListBar">
        <div class="lc-class"><label class="form-label mb-0" for="lcClass">Class</label><?= $classSelect('lcClass', 'form="lcForm"', $classVal) ?></div>
        <div class="lc-list-btns">
          <button type="button" class="btn btn-sm btn-outline-danger" data-need-row data-when="deletable:1" data-modal="#lcDelModal" disabled title="Delete the selected collection (not yet printed in the passbook)"><i class="bi bi-trash3"></i> Delete</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#lcPrintModal"><i class="bi bi-printer"></i> Print</button>
        </div>
      </div>
    </fieldset>
  </div>
  <div class="sv-statusbar">
    <div id="lcStatus" role="status" aria-live="polite"><?= $m ? ($loans ? 'Select the loan to be paid in Open Loans.' : 'The member has no open loan.') : 'Choose the member in Receive from.' ?></div>
    <div>Teller: <?= h((string)($teller['full_name'] ?? '')) ?> (ID <?= (int)Auth::id() ?>)</div>
    <div>WorkStation: <?= h($shift ? 'shift ' . (int)$shift['id'] . ', ' . $shift['cash_code'] : (string)($_SERVER['REMOTE_ADDR'] ?? '')) ?></div>
    <div>Date: <?= h(fdate(today())) ?></div>
    <div>Time: <span data-clock></span></div>
  </div>
</section>

<?php // ---------------------------------------------------------------- dialogs ?>
<div class="modal fade" id="lcViewModal" tabindex="-1" aria-labelledby="lcViewTitle"><div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="lcViewTitle">Amortization Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body" id="lcViewBody"></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>

<?php if ($done): $isEc = $done['row']['kind'] === 'correction'; ?>
<div class="modal fade" id="lcValModal" tabindex="-1" aria-labelledby="lcValTitle"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="lcValTitle">Machine Validation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="small text-muted mb-2">The validation printed on the loan payslip</div>
    <div class="sv-slip"><?php foreach ($done['lines'] as $parts): ?><div class="sv-slip-line"><?php foreach ($parts as $part): ?><span><?= h($part) ?></span><?php endforeach; ?></div><?php endforeach; ?></div>
  </div>
  <div class="modal-footer justify-content-between"><span class="small">Please make sure you have inserted the payslip.</span>
    <span class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-primary btn-sm" data-print-then data-url="<?= h(url($pageKey, ['a' => 'validate', 'id' => $done['row']['txn_id']])) ?>" data-bs-dismiss="modal"><i class="bi bi-printer"></i> Print</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button></span></div>
</div></div></div>
<?php endif; ?>

<div class="modal fade" id="lcPbModal" tabindex="-1" aria-labelledby="lcPbTitle" data-default-loan="<?= $doneLoanId ?: $selLoan ?>"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="lcPbTitle">Update Passbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <p class="text-muted mb-0" data-pb-none>Select the loan in Open Loans first.</p>
    <?php foreach ($pbPlans as $id => $x): $pb = $x['pb']; $run = $x['run']; $l = $x['loan']; ?>
    <div data-pb-loan="<?= (int)$id ?>" hidden>
      <div class="fw-semibold mb-2"><?= h($l['product_name']) ?> <?= h($l['loan_no']) ?></div>
      <?php if (!$pb || !$pb['type']): ?>
        <p class="mb-0">The product of this loan has no passbook type (Loan Products).</p>
      <?php elseif ($run['count']): ?>
        <?= coop_pb_run_box($pb, $run, 'lcPbConfirm' . (int)$id, '<input type="hidden" name="action" value="pb_commit"><input type="hidden" name="loan_id" value="' . (int)$id . '">',
            '<strong>' . h((string)($l['member_name'] ?? ($m['full_name'] ?? ''))) . '</strong>', Access::can('sav.passbook', 'print')) ?>
      <?php else: ?>
        <p class="mb-0">Every entry of loan <?= h($l['loan_no']) ?> is printed in the passbook.</p>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>

<?php if ($m && $canCreate): ?>
<div class="modal fade" id="lcEcModal" tabindex="-1" aria-labelledby="lcEcTitle"><div class="modal-dialog modal-dialog-centered modal-lg"><form method="post" class="modal-content" data-once
     data-confirm="Negate payment {ec_id} in full? The loan's balances and schedule go back as they were before it." data-confirm-title="Error Correct" data-confirm-ok="Post the error correction">
  <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="error_correct"><input type="hidden" name="member" value="<?= (int)$m['id'] ?>">
  <div class="modal-header"><h5 class="modal-title" id="lcEcTitle">Error Correct of an Erroneous Payslip</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="row g-2">
      <div class="col-12"><label class="form-label" for="lcEcId">Payment entered by mistake (negated in full)</label>
        <select class="form-select form-select-sm" name="ec_id" id="lcEcId" data-no-combo required>
          <option value="">Choose the erroneous payment</option>
          <?php foreach ($ecRows as $r): ?>
            <option value="<?= (int)$r['id'] ?>" data-loan="<?= (int)$r['loan_id'] ?>" <?= (string)p('ec_id') === (string)$r['id'] ? 'selected' : '' ?>>
              <?= h(($r['receipt_no'] ? 'OR ' . $r['receipt_no'] : ($r['or_no'] ?: '#' . $r['id'])) . ' of ' . fdate($r['txn_date']) . ': ' . $r['product_name'] . ' ' . $r['loan_no'] . ', ' . Dec::money($r['amount'])
                  . ([ 'cash' => ' cash', 'check' => ' by check', 'bank' => ' bank deposit', 'savings' => ' from savings'][(string)$r['payment_mode']] ?? '')) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-4"><label class="form-label" for="lcEcDate">Date</label><input class="form-control form-control-sm" type="date" name="ec_date" id="lcEcDate" value="<?= h((string)p('ec_date', today())) ?>" max="<?= h(today()) ?>" required></div>
      <div class="col-6 col-md-8"><label class="form-label" for="lcEcClass">Class</label><?= $classSelect('lcEcClass', '', $action === 'error_correct' ? pint('class_id') : $classVal) ?></div>
      <div class="col-12"><label class="form-label" for="lcEcMemo">Reason (kept in the audit trail and on the voucher)</label><textarea class="form-control form-control-sm" name="ec_memo" id="lcEcMemo" rows="2" maxlength="120" required><?= h((string)p('ec_memo')) ?></textarea></div>
      <div class="col-12 sv-checks">
        <div class="form-check"><input class="form-check-input" type="checkbox" name="ec_print_pb" value="1" id="lcEcPb" <?= $action === 'error_correct' ? (p('ec_print_pb') === '1' ? 'checked' : '') : 'checked' ?>><label class="form-check-label" for="lcEcPb">Print to Passbook</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="ec_preview" value="1" id="lcEcPv" <?= $action === 'error_correct' ? (p('ec_preview') === '1' ? 'checked' : '') : 'checked' ?>><label class="form-check-label" for="lcEcPv">Show Print Preview after saving</label></div>
      </div>
    </div>
    <div class="small text-muted mt-2">The correction is a line of its own on the loan ledger and in the passbook (EC, the amounts negative); the receipt's journal entry is reversed line for line. A cash payment is paid back from your drawer by a cash voucher. Another teller's payment, or one of an earlier day, needs a supervisor. A cheque already cleared, or a receipt with other items, is corrected another way (the message says how).</div>
    <?= $approval('error_correct') ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Error Correct</button></div>
</form></div></div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="modal fade" id="lcDelModal" tabindex="-1" aria-labelledby="lcDelTitle"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" data-once>
  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="loan_txn_id" data-row-field="id" value="<?= h((string)p('loan_txn_id')) ?>">
  <input type="hidden" name="member" value="<?= $m ? (int)$m['id'] : '' ?>"><input type="hidden" name="range" value="<?= h($range) ?>"><input type="hidden" name="from" value="<?= h($lFrom) ?>"><input type="hidden" name="to" value="<?= h($lTo) ?>">
  <div class="modal-header"><h5 class="modal-title" id="lcDelTitle">Delete a loan collection</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <p class="mb-2">Collection <strong data-row-field="ref"></strong> of <span data-row-field="name"></span>: <strong data-row-field="amount"></strong></p>
    <label class="form-label" for="lcDelReason">Reason (kept in the audit trail)</label><textarea class="form-control form-control-sm" name="reason" id="lcDelReason" rows="2" required><?= h((string)p('reason')) ?></textarea>
    <div class="small text-muted mt-2">The collection is voided: it stays on file marked void, and the loan and the books are reversed. A collection already printed in the passbook is corrected with Error Correct instead. Another teller's collection, or an earlier day's, needs a supervisor.</div>
    <?= $approval('delete') ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm"><i class="bi bi-trash3"></i> Delete</button></div>
</form></div></div>
<?php endif; ?>

<div class="modal fade" id="lcPrintModal" tabindex="-1" aria-labelledby="lcPrintTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="lcPrintTitle">Print</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body p-0"><div class="list-group list-group-flush">
    <a class="list-group-item list-group-item-action" target="_blank" rel="noopener" data-lc-print="or" href="#"><i class="bi bi-receipt me-2"></i>Official receipt <span data-lc-ref></span><div class="small text-muted" data-lc-hint>Select a collection in the list first.</div></a>
    <a class="list-group-item list-group-item-action" target="_blank" rel="noopener" data-lc-print="val" href="#"><i class="bi bi-receipt-cutoff me-2"></i>Payslip validation <span data-lc-ref></span><div class="small text-muted" data-lc-hint>Select a collection in the list first.</div></a>
    <a class="list-group-item list-group-item-action" target="_blank" rel="noopener" data-lc-print="list" href="<?= h(url($pageKey, ['a' => 'print_list', 'range' => $range, 'from' => $lFrom, 'to' => $lTo, 'q' => $lq])) ?>" data-base="<?= h(url($pageKey, ['a' => 'print_list'])) ?>"><i class="bi bi-list-ul me-2"></i>The list of loan collections<div class="small text-muted">The dates and the search of the list</div></a>
  </div></div>
  <div class="modal-footer"><span class="small text-muted me-auto">A reprint asks for the reason and is counted.</span><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>
<?php
layout_end();
