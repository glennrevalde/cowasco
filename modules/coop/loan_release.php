<?php
declare(strict_types=1);

/**
 * Loans > Loan Release: the Loan Release Form of the cooperative's former program.
 *
 *  - the approved applications waiting for release, and the loans released in a period;
 *  - the form (?app=): Member Code, Member Name, Loan Code, P.N No, Loan Name; Payment Details (Installment Mode
 *    Fixed Principal / Equal Loan, Term, Mode of Payment, Start Payment Date, Due Date); Release Date; Net Proceeds
 *    (Approved Amount and the net amount); Release Type (Cash or Cheque, Cheque No., the Cash / bank Account the loan
 *    is released from); Co-Makers; Loan Charges (the deductions: added, deleted or changed by the user) and Total
 *    Charges; the class of the voucher; Release Loan;
 *  - after the release (?loan=) the same form, with Print To Passbook, Disbursement (the Cash Voucher or the Check
 *    Voucher), Promissory, Disclosure, Amortization, Co-Maker and Loan Release Stmt.
 * A release in cash is a Cash Voucher of Banking, a release by cheque a check of Write Checks (LoanVouchers).
 */
require_once __DIR__ . '/_coop.php';
require_once __DIR__ . '/_loan_release.php';
require_once __DIR__ . '/_loan_voucher.php';
$pageKey = 'loan.release';
if (coop_voucher_actions($pageKey, ['loan_release'])) {
    exit;
}

// the voucher of a release (Disbursement): the Cash Voucher, the Check Voucher or the loan release voucher
if (g('a') === 'voucher') {
    $t = Txn::load((int)gint('id'));
    if (!$t || !loan_voucher_page($t, $pageKey)) {
        flash('warning', 'Voucher not found.');
        redirect(url($pageKey));
    }
    exit;
}

$app = gint('app') ? Loans::application((int)gint('app')) : null;
if ($app && !Coop::branchAllowed($app['branch_id'] ? (int)$app['branch_id'] : null)) {
    Access::denyPage($pageKey, 'release a loan of another branch on');
}
$released = gint('loan') ? Loans::loan((int)gint('loan')) : null;
if ($released && !Coop::branchAllowed($released['branch_id'] ? (int)$released['branch_id'] : null)) {
    Access::denyPage($pageKey, 'open a loan of another branch on');
}

handle_post(function () use ($app, $pageKey) {
    Access::require($pageKey, 'create');
    if (!$app) {
        fail('Application not found.');
    }
    coop_once();
    $mode = p('release_mode') === 'cheque' ? 'cheque' : 'cash';
    $acct = (int)pint('cash_account_id');
    $list = $mode === 'cheque' ? LoanVouchers::bankAccounts() : LoanVouchers::cashAccounts();
    if (!$acct || !isset($list[$acct])) {
        fail($mode === 'cheque' ? 'Choose the bank account the cheque is drawn on (Cash/bank Account).' : 'Choose the cash account the loan is released from (Cash/bank Account).');
    }
    if ($mode === 'cheque' && trim((string)p('cheque_no')) === '') {
        fail('Enter the cheque no.');
    }
    // cash from the teller's own drawer: a cash-out of the open shift
    $shift = null;
    if ($mode === 'cash') {
        $open = Teller::openShift();
        if ($open && (int)$open['cash_account_id'] === $acct) {
            $shift = $open;
        } elseif (!$open && $acct === (int)Teller::userCashAccount()) {
            $shift = Teller::requireShift();
        }
    }
    $rows = [];
    foreach ((array)($_POST['ch'] ?? []) as $r) {
        if (is_array($r)) {
            $rows[] = ['charge_id' => (int)($r['charge_id'] ?? 0), 'rate' => trim((string)($r['rate'] ?? '')), 'amount' => trim(str_replace(',', '', (string)($r['amount'] ?? '')))];
        }
    }
    $classId = Classes::required('class_id', 'the loan release voucher');
    $r = CoopLedger::withClass($classId, fn() => Loans::release((int)$app['id'], [
        'release_date' => (string)p('release_date'), 'start_date' => (string)p('start_date'), 'term' => (string)p('term'), 'term_unit' => (string)p('term_unit'),
        'mode_of_payment' => (string)p('mode_of_payment'), 'rate' => (string)p('rate'), 'amort_type' => (string)p('amort_type'), 'release_mode' => $mode,
        'cash_account_id' => $acct, 'cheque_no' => trim((string)p('cheque_no')), 'pn_no' => trim((string)p('pn_no')),
        'previous_loan_id' => $app['previous_loan_id'] ? (int)$app['previous_loan_id'] : (pint('previous_loan_id') ?: null),
        'restructure' => $app['previous_loan_id'] ? (int)$app['is_restructure'] === 1 : p('restructure') === '1', 'shift_id' => $shift['id'] ?? null, 'officer_id' => pint('officer_id') ?: null,
        'charges' => $rows,
    ], $pageKey));
    $t = Txn::load((int)$r['txn_id']);
    flash('success', 'Loan ' . $r['loan_no'] . ' released: ' . LoanVouchers::label($t['txn_type']) . ' ' . $t['txn_no'] . ' (voucher ID ' . $r['voucher_no'] . '). Print the voucher and the documents below.');
    redirect(url($pageKey, ['loan' => $r['loan_id']]));
});

// --------------------------------------------------------------------------------------- waiting list and released loans
if (!$app && !$released) {
    [$bf, $bp] = Coop::branchFilter('a.branch_id');
    $waiting = DB::all("SELECT a.*, m.full_name, m.member_no, p.name AS product_name, p.requirements_json,
                               (SELECT COUNT(*) FROM loan_application_docs d WHERE d.application_id = a.id AND d.is_complete = 0) AS open_docs
                          FROM loan_applications a JOIN members m ON m.id = a.member_id JOIN loan_products p ON p.id = a.product_id
                         WHERE a.status = 'approved'" . $bf . ' ORDER BY a.application_date, a.id', $bp);
    $from = gdate('from', date('Y-m-01'));
    $to = gdate('to', today());
    [$bf2, $bp2] = Coop::branchFilter('l.branch_id', 'rbr');
    $list = DB::all("SELECT l.*, m.full_name, m.member_no, p.name AS product_name, t.txn_no, t.txn_type, t.is_voided FROM loan_accounts l JOIN members m ON m.id = l.member_id
                       JOIN loan_products p ON p.id = l.product_id LEFT JOIN transactions t ON t.id = l.release_txn_id
                      WHERE l.release_date BETWEEN :f AND :t AND l.release_mode <> 'opening'" . $bf2 . ' ORDER BY l.release_date DESC, l.id DESC LIMIT 300', ['f' => $from, 't' => $to] + $bp2);
    coop_layout('Loan Release', ['actions' => page_link('loan.vouchers', [], 'Loan release vouchers', 'btn-outline-secondary', 'receipt') . ' ' . page_link('loan.applications', [], 'Application Center', 'btn-outline-secondary', 'inbox')]);
    ?>
    <div class="card mb-3"><div class="card-header">Approved, waiting for release</div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Application</th><th>Member</th><th class="d-none d-md-table-cell">Product</th><th class="num">Approved</th><th class="d-none d-sm-table-cell">Term</th><th class="d-none d-md-table-cell">Requirements</th><th></th></tr></thead><tbody>
      <?php foreach ($waiting as $w): ?><tr><td><?= safe_link('loan.application', ['id' => $w['id']], $w['application_no']) ?><div class="small text-muted"><?= h(fdate($w['application_date'])) ?></div></td>
        <td><?= h($w['full_name']) ?><div class="small text-muted"><?= h($w['member_no']) ?><span class="d-md-none">, <?= h($w['product_name']) ?></span></div></td><td class="small d-none d-md-table-cell"><?= h($w['product_name']) ?></td>
        <td class="num"><?= cm($w['approved_amount'] ?: $w['applied_amount']) ?></td><td class="small d-none d-sm-table-cell"><?= h(Dec::fix($w['term'], 0) . ' ' . $w['term_unit']) ?></td>
        <td class="small d-none d-md-table-cell"><?= (int)$w['open_docs'] ? '<span class="text-warning">' . (int)$w['open_docs'] . ' to complete</span>' : '<span class="text-success">complete</span>' ?></td>
        <td class="text-end"><?php if (Access::can($pageKey, 'create')): ?><a class="btn btn-sm btn-primary" href="<?= h(url($pageKey, ['app' => $w['id']])) ?>"><i class="bi bi-cash-coin"></i> Release</a><?php endif; ?></td></tr><?php endforeach; ?>
      <?php if (!$waiting): ?><tr><td colspan="7" class="text-muted">No approved application is waiting.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <form class="row g-2 align-items-end filters mb-2 no-print">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <?= input_field('from', 'Released from', $from, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <?= input_field('to', 'To', $to, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Show</button></div>
    </form>
    <div class="card"><div class="card-header">Released</div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>Date</th><th>Loan</th><th>Member</th><th class="d-none d-lg-table-cell">Product</th><th class="num d-none d-md-table-cell">Principal</th><th class="num d-none d-md-table-cell">Charges</th><th class="num">Net proceeds</th><th>Voucher</th></tr></thead><tbody>
      <?php $tot = ['p' => '0', 'c' => '0', 'n' => '0']; foreach ($list as $r):
          if (!(int)$r['is_voided']) { $tot['p'] = Dec::add($tot['p'], $r['principal'], 2); $tot['c'] = Dec::add($tot['c'], $r['total_charges'], 2); $tot['n'] = Dec::add($tot['n'], $r['net_proceeds'], 2); } ?>
        <tr class="<?= (int)$r['is_voided'] ? 'text-muted' : '' ?>"><td><?= h(fdate($r['release_date'])) ?></td><td><a href="<?= h(url($pageKey, ['loan' => $r['id']])) ?>"><?= h($r['loan_no']) ?></a></td>
        <td><?= h($r['full_name']) ?><div class="small text-muted"><?= h($r['member_no']) ?></div></td><td class="small d-none d-lg-table-cell"><?= h($r['product_name']) ?></td><td class="num d-none d-md-table-cell"><?= cm($r['principal']) ?></td>
        <td class="num d-none d-md-table-cell"><?= cm($r['total_charges']) ?></td><td class="num"><?= cm($r['net_proceeds']) ?></td>
        <td class="small"><?= $r['release_txn_id'] ? h(LoanVouchers::label((string)$r['txn_type'])) . '<div>' . h((string)($r['voucher_no'] ?? '') ?: (string)$r['txn_no']) . ((int)$r['is_voided'] ? ' ' . coop_status('void') : '') . '</div>' : '' ?></td></tr><?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="8" class="text-muted">No release in this period.</td></tr><?php endif; ?>
      </tbody><tfoot><tr><td colspan="4" class="fw-semibold d-none d-lg-table-cell"><?= count($list) ?> loan(s), voids excluded</td><td colspan="3" class="fw-semibold d-lg-none"><?= count($list) ?> loan(s)</td>
        <td class="num fw-semibold d-none d-md-table-cell"><?= cm($tot['p']) ?></td><td class="num fw-semibold d-none d-md-table-cell"><?= cm($tot['c']) ?></td><td class="num fw-semibold"><?= cm($tot['n']) ?></td><td></td></tr></tfoot></table></div></div>
    <?php
    layout_end();
    exit;
}

// --------------------------------------------------------------------------------------- the Loan Release Form
if ($app) {
    Access::require($pageKey, 'create');
    if ($app['status'] !== 'approved') {
        if ($app['status'] === 'released' && $app['loan_id']) {
            redirect(url($pageKey, ['loan' => $app['loan_id']]));
        }
        flash('warning', 'Application ' . $app['application_no'] . ' is ' . mb_strtolower(Loans::APP_STATUSES[$app['status']] ?? $app['status']) . ', not approved for release.');
        redirect(url('loan.application', ['id' => $app['id']]));
    }
} else {
    $app = $released['application_id'] ? Loans::application((int)$released['application_id']) : null;
}
$loan = $released;
$m = Coop::member((int)($loan['member_id'] ?? $app['member_id']));
$p = Loans::product((int)($loan['product_id'] ?? $app['product_id']));
$comakers = $app ? DB::all('SELECT c.*, m.full_name AS member_name, m.member_no FROM loan_comakers c LEFT JOIN members m ON m.id = c.comaker_member_id WHERE c.application_id = :a ORDER BY c.id', ['a' => $app['id']]) : [];
$canRelease = !$loan && Access::can($pageKey, 'create');
$posted = is_post();
$val = fn(string $k, $default) => $posted && array_key_exists($k, $_POST) ? (string)$_POST[$k] : (string)$default;

if ($loan) {
    // the release as it was made
    $t = $loan['release_txn_id'] ? Txn::load((int)$loan['release_txn_id']) : null;
    $relDate = $loan['release_date'];
    $mode = $loan['mode_of_payment'];
    $term = $loan['term'];
    $termUnit = $loan['term_unit'];
    $rate = $loan['rate'];
    $amort = $loan['amort_type'];
    $start = $loan['start_date'];
    $approved = $app ? ($app['approved_amount'] ?: $app['applied_amount']) : $loan['principal'];
    $relType = $loan['release_mode'] === 'cheque' ? 'cheque' : ($loan['release_mode'] === 'savings' ? 'savings' : 'cash');
    $acctId = (int)$loan['cash_account_id'];
    $chequeNo = (string)$loan['cheque_no'];
    $pnNo = (string)$loan['pn_no'];
    $classId = $t['class_id'] ?? null;
    $charges = DB::all('SELECT ca.*, c.charge_by FROM loan_charges_applied ca LEFT JOIN loan_charges c ON c.id = ca.charge_id WHERE ca.loan_id = :l ORDER BY ca.id', ['l' => $loan['id']]);
    $plan = ['net_proceeds' => $loan['net_proceeds'], 'total_charges' => $loan['total_charges'], 'payoff' => $loan['previous_loan_payoff'], 'due_date' => $loan['due_date'],
        'principal' => $loan['principal'], 'error' => null, 'rows' => array_map(fn($c) => ['charge_id' => (int)$c['charge_id'], 'name' => $c['name'],
            'account' => $c['sav_account_id'] ? (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $c['sav_account_id']]) : ($c['account_id'] ? (string)DB::value('SELECT code FROM accounts WHERE id = :a', ['a' => $c['account_id']]) : ''),
            'rate' => preg_match('/^([0-9.]+) %$/', (string)$c['basis_text'], $mm) ? $mm[1] : null, 'amount' => Dec::fix($c['amount']), 'basis' => (string)$c['basis_text']], $charges)];
    $choices = [];
} else {
    $relDate = $val('release_date', today());
    $mode = $val('mode_of_payment', $app['mode_of_payment']);
    $term = $val('term', Dec::fix($app['term'], 0));
    $termUnit = $val('term_unit', $app['term_unit']);
    $rate = $val('rate', Dec::fix($app['rate'], 4));
    $amort = $val('amort_type', $app['amort_type'] ?: $p['amort_type']);
    $start = $val('start_date', Coop::nthDue($relDate, $mode, 1));
    $approved = $app['approved_amount'] ?: $app['applied_amount'];
    $relType = $val('release_mode', 'cash') === 'cheque' ? 'cheque' : 'cash';
    $shiftNow = Teller::openShift();
    $acctId = (int)$val('cash_account_id', $relType === 'cash' ? ($shiftNow['cash_account_id'] ?? Teller::userCashAccount()) : '');
    $chequeNo = $val('cheque_no', '');
    $pnNo = $val('pn_no', '');
    $classId = $val('class_id', $app['class_id'] ?? '');
    $grid = null;
    if ($posted && isset($_POST['ch']) && is_array($_POST['ch'])) {
        $grid = array_values(array_map(fn($r) => ['charge_id' => (int)($r['charge_id'] ?? 0), 'rate' => (string)($r['rate'] ?? ''), 'amount' => (string)($r['amount'] ?? '')], array_filter($_POST['ch'], 'is_array')));
    }
    $plan = lr_plan($app, ['term' => $term, 'term_unit' => $termUnit, 'mode' => $mode, 'rate' => $rate, 'amort' => $amort, 'release' => $relDate, 'start' => $start,
        'prev_loan_id' => $app['previous_loan_id'] ?: (int)$val('previous_loan_id', 0), 'charges' => $grid]);
    $choices = lr_charge_choices($app, $term, $termUnit);
}
$cashList = LoanVouchers::cashAccounts();
$bankList = LoanVouchers::bankAccounts();
$openLoans = $loan ? [] : DB::all("SELECT l.id, l.loan_no, l.principal_balance, p.name AS product_name FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id
                                    WHERE l.member_id = :m AND l.status IN ('current','past_due','restructured') ORDER BY l.release_date", ['m' => $m['id']]);
$loanOpts = [];
foreach ($openLoans as $ol) {
    $loanOpts[$ol['id']] = $ol['loan_no'] . ' ' . $ol['product_name'] . ' (balance ' . Dec::money($ol['principal_balance']) . ')';
}
$openDocs = $app && !$loan ? DB::column('SELECT requirement FROM loan_application_docs WHERE application_id = :a AND is_complete = 0', ['a' => $app['id']]) : [];
$users = DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND COALESCE(is_platform,0) = 0 ORDER BY full_name");
$limit = Access::limit('coop_max_release');
$ro = $loan ? ' disabled' : '';
$docs = $loan && Access::page('loan.docs');
$docUrl = fn(string $doc, array $x = []) => url('loan.docs', ['id' => $loan['id'], 'doc' => $doc] + $x);
$pbOk = $loan && Access::can('sav.passbook', 'print') && (int)$p['passbook_type_id'] > 0;
$title = $loan ? 'Loan Release: ' . $loan['loan_no'] : 'Loan Release: ' . $app['application_no'];
$back = $loan ? btn_link(url('loan.accounts', ['id' => $loan['id']]), 'Loan account', 'btn-outline-secondary', 'cash-coin') . ' ' . btn_link(url($pageKey), 'Loan Release', 'btn-outline-secondary', 'arrow-left')
    : btn_link(url('loan.application', ['id' => $app['id']]), 'Application', 'btn-outline-secondary', 'arrow-left');
coop_layout($title, ['actions' => $back, 'js' => ['js/loan_release.js']]);
$net = $plan['net_proceeds'] ?? '0';
?>
<?php if ($loan && $t && (int)$t['is_voided'] === 1): ?><div class="alert alert-secondary">The release voucher was voided: <?= h((string)$t['void_reason']) ?>. The loan is cancelled.</div><?php endif; ?>
<?php if ($openDocs): ?><div class="alert alert-warning small">Requirements still to complete: <?= h(implode(', ', $openDocs)) ?>. The release is refused until they are marked complete on the application.</div><?php endif; ?>
<form method="post" id="lrForm" class="lr-form" autocomplete="off" <?= $loan ? '' : 'data-once data-confirm="Release the loan and pay out {net}? The loan account, its schedule and the voucher are created at once." data-confirm-title="Release the loan" data-confirm-ok="Release Loan"' ?>
      data-api="<?= h(url('api.coop', ['a' => 'release_plan', 'app' => $app['id'] ?? 0])) ?>" data-released="<?= $loan ? '1' : '0' ?>">
  <?= csrf_field() ?><?= $loan ? '' : Coop::onceField() ?>
  <input type="hidden" id="lrNetText" data-confirm-value="net" value="<?= h(Dec::money($net)) ?>">
  <div class="card lr-card">
    <div class="lr-title">Loan Release Form<?= $loan ? '<span class="lr-title-sub">' . h($loan['loan_no']) . ', ' . coop_status($loan['status']) . '</span>' : '' ?></div>
    <div class="lr-body">
      <!-- left: the borrower and the payment details -->
      <div class="lr-col">
        <div class="lr-kv">
          <label for="lrMemberNo">Member Code</label><input class="form-control form-control-sm sv-ro" id="lrMemberNo" value="<?= h($m['member_no']) ?>" readonly>
          <label for="lrMember">Member Name</label><input class="form-control form-control-sm sv-ro" id="lrMember" value="<?= h($m['full_name']) ?>" readonly>
          <label for="lrCode">Loan Code</label>
          <div class="lr-pair"><input class="form-control form-control-sm sv-ro" id="lrCode" value="<?= h($p['code']) ?>" readonly>
            <label for="lrPn">P.N No</label><input class="form-control form-control-sm" id="lrPn" name="pn_no" value="<?= h($pnNo) ?>" maxlength="30"<?= $ro ?> placeholder="<?= $loan ? '' : h(Seq::peek('pn')) ?>"></div>
          <label for="lrName">Loan Name</label><input class="form-control form-control-sm sv-ro" id="lrName" value="<?= h($p['name']) ?>" readonly>
        </div>
        <fieldset class="sv-group mt-3">
          <legend>Payment Details</legend>
          <div class="lr-inst" role="radiogroup" aria-label="Installment Mode">
            <span class="lr-sub">Installment Mode</span>
            <?php foreach (['equal_principal' => 'Fixed Principal', 'equal_amortization' => 'Equal Loan'] as $k => $lbl): ?>
              <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="amort_type" id="lrAm_<?= h($k) ?>" value="<?= h($k) ?>" <?= checked($amort === $k) ?><?= $ro ?> data-plan>
                <label class="form-check-label" for="lrAm_<?= h($k) ?>"><?= h($lbl) ?></label></div>
            <?php endforeach; ?>
          </div>
          <div class="lr-kv mt-2">
            <label for="lrTerm">Term</label>
            <div class="lr-pair lr-term"><input class="form-control form-control-sm text-end" id="lrTerm" name="term" value="<?= h(rtrim(rtrim(Dec::fix($term, 2), '0'), '.')) ?>" inputmode="decimal" required<?= $ro ?> data-plan>
              <select class="form-select form-select-sm" name="term_unit" aria-label="Term in" data-no-combo<?= $ro ?> data-plan><?= options(['months' => 'MONTH(S)', 'days' => 'DAY(S)'], $termUnit) ?></select></div>
            <label for="lrMode">Mode of Payment</label><select class="form-select form-select-sm" id="lrMode" name="mode_of_payment" data-no-combo<?= $ro ?> data-plan><?= options(Coop::modeOptions(), $mode) ?></select>
            <label for="lrRate">Interest Rate</label>
            <div class="input-group input-group-sm"><input class="form-control text-end" id="lrRate" name="rate" value="<?= h(Dec::fix($rate, 2)) ?>" inputmode="decimal"<?= $ro ?><?= !$loan && !Access::can('loan.applications', 'approve') ? ' readonly' : '' ?> data-plan><span class="input-group-text">% a year</span></div>
            <label for="lrStart">Start Payment Date</label><input class="form-control form-control-sm" type="date" id="lrStart" name="start_date" value="<?= h($start) ?>" required<?= $ro ?> data-plan>
            <label for="lrDue">Due Date</label><input class="form-control form-control-sm sv-ro" type="date" id="lrDue" value="<?= h((string)($plan['due_date'] ?? '')) ?>" readonly tabindex="-1">
          </div>
          <?php if (!$loan && !Access::can('loan.applications', 'approve')): ?><div class="lr-note">The rate is the approved rate; an officer who may approve loans can change it here.</div><?php endif; ?>
        </fieldset>
      </div>
      <!-- right: the date, the net proceeds, the release type and the co-makers -->
      <div class="lr-col">
        <div class="lr-kv">
          <label for="lrDate">Release Date</label><input class="form-control form-control-sm" type="date" id="lrDate" name="release_date" value="<?= h($relDate) ?>" required<?= $ro ?> data-plan>
        </div>
        <fieldset class="sv-group mt-3 lr-net">
          <legend>Net Proceeds</legend>
          <div class="lr-kv"><label>Approved Amount</label><input class="form-control form-control-sm sv-ro sv-money" value="<?= h(Dec::money($approved)) ?>" readonly tabindex="-1"></div>
          <output class="lr-net-big" id="lrNet" aria-live="polite"><?= cm($net) ?></output>
          <div class="lr-net-sub" id="lrNetSub"><?php if (Dec::isPos($plan['payoff'] ?? '0')): ?>after the payoff of the previous loan (<?= cm($plan['payoff']) ?>)<?php endif; ?></div>
        </fieldset>
        <fieldset class="sv-group lr-type">
          <legend>Release Type</legend>
          <?php if ($relType === 'savings'): ?>
            <div class="small">Credited to savings account <?= h((string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $loan['credit_account_id']])) ?>.</div>
          <?php else: ?>
          <div class="lr-type-row">
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="release_mode" id="lrCash" value="cash" <?= checked($relType === 'cash') ?><?= $ro ?>><label class="form-check-label" for="lrCash">Cash</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="release_mode" id="lrCheque" value="cheque" <?= checked($relType === 'cheque') ?><?= $ro ?>><label class="form-check-label" for="lrCheque">Cheque</label></div>
            <label for="lrChequeNo" class="lr-inline-label">Cheque No.</label>
            <input class="form-control form-control-sm lr-cheque" id="lrChequeNo" name="cheque_no" value="<?= h($chequeNo) ?>" maxlength="30"<?= $ro ?: ($relType === 'cheque' ? '' : ' disabled') ?>>
          </div>
          <div class="lr-kv mt-2">
            <label for="lrAcct">Cash/bank Account</label>
            <select class="form-select form-select-sm" id="lrAcct" name="cash_account_id" required data-no-combo<?= $ro ?>
                    data-cash='<?= h(json_encode(array_map(fn($k, $v) => [$k, $v], array_keys($cashList), $cashList))) ?>' data-bank='<?= h(json_encode(array_map(fn($k, $v) => [$k, $v], array_keys($bankList), $bankList))) ?>'>
              <option value=""></option>
              <?php foreach (($relType === 'cheque' ? $bankList : $cashList) + ($acctId && !isset(($relType === 'cheque' ? $bankList : $cashList)[$acctId]) ? [$acctId => account_label($acctId)] : []) as $id => $label): ?>
                <option value="<?= (int)$id ?>" <?= ((int)$id === $acctId) ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="lr-note" id="lrTypeNote"><?= $loan ? '' : ($relType === 'cheque' ? 'Posted as a check of Banking > Write Checks, numbered with the cheque no.' : 'Posted as a Cash Voucher of Banking > Cash Voucher.') ?></div>
          <?php endif; ?>
        </fieldset>
        <fieldset class="sv-group lr-comakers">
          <legend>Co-Makers</legend>
          <ul class="lr-list">
            <?php foreach ($comakers as $c): ?><li><?= h((string)($c['member_name'] ?: $c['name'])) ?><?= $c['member_no'] ? ' <span class="text-muted">' . h($c['member_no']) . '</span>' : '' ?></li><?php endforeach; ?>
            <?php if (!$comakers): ?><li class="text-muted">None.</li><?php endif; ?>
          </ul>
        </fieldset>
      </div>
    </div>

    <!-- Loan Charges: the deductions -->
    <div class="lr-charges">
      <div class="lr-charges-head"><span class="lr-sub">Loan Charges</span>
        <?php if (!$loan): ?><button type="button" class="btn btn-sm btn-outline-primary" id="lrAdd"><i class="bi bi-plus-lg"></i> Add deduction</button><?php endif; ?></div>
      <div class="table-responsive lr-grid-wrap">
        <table class="table table-sm lr-grid" id="lrGrid" aria-label="Loan charges">
          <thead><tr><th>Charge Name</th><th class="d-none d-sm-table-cell">Charge Account</th><th class="num">Rate/Amount</th><th class="num">Calculated Amount</th><?php if (!$loan): ?><th class="lr-x" aria-label="Delete"></th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach (array_values($plan['rows'] ?? []) as $i => $r): ?>
            <tr data-ch>
              <td><?php if ($loan): ?><?= h($r['name']) ?><?php else: ?>
                <select class="form-select form-select-sm" name="ch[<?= $i ?>][charge_id]" data-ch-pick data-no-combo aria-label="Charge name">
                  <option value=""></option>
                  <?php foreach ($choices as $c): ?><option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$r['charge_id']) ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
                </select><?php endif; ?>
                <div class="lr-basis" data-ch-basis><?= h((string)$r['basis']) ?></div></td>
              <td class="d-none d-sm-table-cell" data-ch-acct><?= h((string)$r['account']) ?></td>
              <td class="num"><?php if ($loan): ?><?= h($r['rate'] !== null ? Dec::fix($r['rate'], 2) . ' %' : '') ?><?php else: ?>
                <input class="form-control form-control-sm text-end lr-rate" name="ch[<?= $i ?>][rate]" value="<?= h($r['rate'] !== null ? Dec::fix($r['rate'], 2) : '') ?>" inputmode="decimal" data-ch-rate<?= empty($r['is_rate']) ? ' disabled placeholder="amount"' : '' ?> aria-label="Rate (%)"><?php endif; ?></td>
              <td class="num"><?php if ($loan): ?><?= cm($r['amount']) ?><?php else: ?>
                <input class="form-control form-control-sm text-end sv-money" name="ch[<?= $i ?>][amount]" value="<?= h($r['amount'] !== null ? Dec::fix($r['amount']) : '') ?>" inputmode="decimal" data-ch-amount aria-label="Calculated amount"><?php endif; ?></td>
              <?php if (!$loan): ?><td class="lr-x"><button type="button" class="btn btn-sm btn-link text-danger p-0" data-ch-del title="Delete this deduction" aria-label="Delete this deduction"><i class="bi bi-x-circle"></i></button></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if ($loan && !$plan['rows']): ?><tr><td colspan="4" class="text-muted">No deduction.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="lr-totals">
        <div class="lr-tot"><span>Total Charges</span><output id="lrTotal"><?= cm($plan['total_charges'] ?? '0') ?></output></div>
        <?php if (Dec::isPos($plan['payoff'] ?? '0') || ($app && $app['previous_loan_id'])): ?><div class="lr-tot"><span>Payoff of the previous loan</span><output id="lrPayoff"><?= cm($plan['payoff'] ?? '0') ?></output></div><?php endif; ?>
        <?php if ($p['charge_method'] !== 'deducted'): ?><div class="lr-note w-100"><?= $p['charge_method'] === 'capitalized' ? 'The charges of this product are added to the loan (principal <span id="lrPrincipal">' . cm($plan['principal'] ?? $approved) . '</span>), not deducted.' : 'The charges of this product are collected separately on an official receipt, not deducted from the proceeds.' ?></div><?php endif; ?>
      </div>
      <div class="alert alert-danger py-2 small mb-0 mt-2" id="lrError" <?= empty($plan['error']) ? 'hidden' : '' ?>><?= h((string)($plan['error'] ?? '')) ?></div>
    </div>

    <?php if (!$loan): ?>
    <div class="lr-more">
      <?php if ($app['previous_loan_id']): ?>
        <div class="lr-renew"><input type="hidden" name="previous_loan_id" value="<?= (int)$app['previous_loan_id'] ?>" data-plan>
          <span class="lr-sub"><?= (int)$app['is_restructure'] ? 'Restructuring of' : 'Renewal of' ?></span> <?= h($loanOpts[$app['previous_loan_id']] ?? 'a loan no longer open') ?>.
          <span class="text-muted">It is paid off in full on the release date from the proceeds.</span></div>
      <?php elseif ($loanOpts): ?>
        <div class="row g-2 align-items-end">
          <?= select_field('previous_loan_id', 'Pay off an open loan from the proceeds (renewal)', $loanOpts, $val('previous_loan_id', ''), ['class' => 'col-md-7', 'blank' => 'No, a new loan only', 'extra' => 'data-no-combo data-plan']) ?>
          <?= check_field('restructure', 'Restructuring of a loan in arrears', $val('restructure', '0'), 'col-md-5') ?>
        </div>
      <?php endif; ?>
      <div class="row g-2 mt-1">
        <?= Classes::requiredField($classId, 'col-md-6') ?>
        <?= select_field('officer_id', 'Loan officer', $users, $val('officer_id', $app['officer_id']), ['class' => 'col-md-6']) ?>
      </div>
      <?php if ($limit !== null): ?><div class="lr-note mt-1">Your release limit: <?= $limit <= 0 ? 'none, a supervisor approves every release' : cm(Dec::of((string)$limit)) ?>.</div><?php endif; ?>
      <?= Approval::fields() ?>
    </div>
    <?php else: ?>
    <div class="lr-more lr-facts">
      <?= coop_kv([
          'Voucher' => $t ? h(LoanVouchers::label($t['txn_type'])) . ' <strong>' . h((string)$t['txn_no']) . '</strong>' . ($t['txn_type'] !== 'loan_release' ? ', voucher ID ' . h(LoanVouchers::voucherId($loan, $t)) : '') : '',
          'Class' => $t && $t['class_id'] ? h(Classes::name($t['class_id'])) : '',
          'Prepared by' => h(LoanVouchers::preparedBy($loan, $t)),
          'Approved by' => h(LoanVouchers::approvedBy($loan, $t)),
          'Principal' => cm($loan['principal']) . ($loan['previous_loan_payoff'] > 0 ? ', previous loan paid off ' . cm($loan['previous_loan_payoff']) : ''),
      ]) ?>
    </div>
    <?php endif; ?>

    <!-- the documents of the release, and Release Loan -->
    <div class="lr-foot">
      <div class="lr-docs" role="group" aria-label="Documents of the release">
        <?php
        $btn = function (string $label, string $icon, ?string $href, bool $on, string $title = '') {
            if (!$on || $href === null) {
                return '<button type="button" class="btn btn-outline-secondary btn-sm" disabled title="' . h($title ?: 'After the release') . '"><i class="bi bi-' . h($icon) . '"></i> ' . h($label) . '</button>';
            }
            return '<a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="' . h($href) . '"><i class="bi bi-' . h($icon) . '"></i> ' . h($label) . '</a>';
        };
        $firstComaker = $comakers[0]['id'] ?? null;
        echo $btn('Print To Passbook', 'journal-arrow-down', $loan ? url('sav.passbook', ['account' => $loan['loan_no'], 'ledger' => 'loan']) : null, $pbOk, $loan ? 'The loan product has no passbook type' : '');
        echo $btn('Disbursement', 'receipt', $loan && $t ? url($pageKey, ['a' => 'voucher', 'id' => $t['id']]) : null, (bool)($loan && $t && Access::can($pageKey, 'print')));
        echo $btn('Promissory', 'file-earmark-text', $loan ? $docUrl('promissory_note') : null, $docs);
        echo $btn('Disclosure', 'file-earmark-ruled', $loan ? $docUrl('disclosure') : null, $docs);
        echo $btn('Amortization', 'calendar3', $loan ? $docUrl('schedule') : null, $docs);
        if ($loan && $docs && count($comakers) > 1): ?>
          <div class="btn-group"><button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-people"></i> Co-Maker</button>
            <ul class="dropdown-menu"><?php foreach ($comakers as $c): ?><li><a class="dropdown-item" target="_blank" rel="noopener" href="<?= h($docUrl('comaker', ['c' => $c['id']])) ?>"><?= h((string)($c['member_name'] ?: $c['name'])) ?></a></li><?php endforeach; ?></ul></div>
        <?php else:
            echo $btn('Co-Maker', 'people', $loan && $firstComaker ? $docUrl('comaker', ['c' => $firstComaker]) : null, $docs && $firstComaker !== null, $loan ? 'No co-maker' : '');
        endif;
        echo $btn('Loan Release Stmt', 'file-earmark-check', $loan ? $docUrl('release') : null, $docs);
        ?>
      </div>
      <?php if ($canRelease): ?><button class="btn btn-primary lr-release" id="lrRelease"><i class="bi bi-cash-coin"></i> Release Loan</button><?php endif; ?>
    </div>
  </div>
</form>
<?php if (!$loan): ?>
<template id="lrRowTpl">
  <tr data-ch>
    <td><select class="form-select form-select-sm" name="ch[__i__][charge_id]" data-ch-pick data-no-combo aria-label="Charge name"><option value=""></option>
      <?php foreach ($choices as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select><div class="lr-basis" data-ch-basis></div></td>
    <td class="d-none d-sm-table-cell" data-ch-acct></td>
    <td class="num"><input class="form-control form-control-sm text-end lr-rate" name="ch[__i__][rate]" inputmode="decimal" data-ch-rate aria-label="Rate (%)"></td>
    <td class="num"><input class="form-control form-control-sm text-end sv-money" name="ch[__i__][amount]" inputmode="decimal" data-ch-amount aria-label="Calculated amount"></td>
    <td class="lr-x"><button type="button" class="btn btn-sm btn-link text-danger p-0" data-ch-del title="Delete this deduction" aria-label="Delete this deduction"><i class="bi bi-x-circle"></i></button></td>
  </tr>
</template>
<script type="application/json" id="lrChoices"><?= json_encode($choices, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php
layout_end();
