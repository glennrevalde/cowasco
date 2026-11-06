<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.restructure';
$loan = gint('loan') ? Loans::loan((int)gint('loan')) : null;
if ($loan && !Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
    Access::denyPage($pageKey, 'work on a loan of another branch on');
}

handle_post(function () use ($loan, $pageKey) {
    if (!$loan) {
        fail('Choose the loan.');
    }
    switch ((string)p('action')) {
        case 'renew':
            Access::require($pageKey, 'create');
            Access::require('loan.application', 'create');
            coop_once();
            $restructure = p('kind') === 'restructure';
            // the class of the new loan: kept on the application and offered again on Loan Release
            $classId = Classes::required('class_id', $restructure ? 'the restructuring' : 'the renewal');
            // Capacity to pay of the new loan: the entries of the borrower's last loan application (v6 workbook)
            $cap = Loans::capacityStart((int)$loan['member_id'])['data'];
            $id = Loans::saveApplication([
                'member_id' => (int)$loan['member_id'], 'product_id' => (int)pint('product_id'), 'application_date' => (string)p('application_date', today()),
                'purpose' => $restructure ? 'Restructuring' : 'Renewal', 'purpose_detail' => mb_substr(($restructure ? 'Restructuring of ' : 'Renewal of ') . $loan['loan_no'] . (trim((string)p('reason')) !== '' ? ': ' . trim((string)p('reason')) : ''), 0, 255),
                'term' => (string)p('term'), 'term_unit' => (string)p('term_unit'), 'mode_of_payment' => (string)p('mode_of_payment'), 'applied_amount' => Coop::amount('applied_amount', true),
                'amort_type' => (string)p('amort_type'), 'monthly_income' => $cap['monthly_income'], 'spouse_income' => $cap['spouse_income'], 'ndi' => $cap['ndi'],
                'dependents' => (int)$cap['dependents'], 'employer_name' => $cap['employer_name'] ?: null, 'business_name' => $cap['business_name'] ?: null, 'spouse_name' => $cap['spouse_name'] ?: null,
                'officer_id' => $loan['officer_id'] ?: Auth::id(), 'remarks' => mb_substr(trim((string)p('reason')), 0, 255) ?: null,
                'previous_loan_id' => (int)$loan['id'], 'is_restructure' => $restructure ? 1 : 0,
            ], [], [], null, 'loan.application');
            if (Loans::classColumns()) {
                DB::update('loan_applications', ['class_id' => $classId], 'id = :id', ['id' => $id]);
            }
            flash('success', 'Application ' . Loans::application($id)['application_no'] . ' created. Validate, approve and release it as usual; the release pays off ' . $loan['loan_no'] . ' from the proceeds.');
            redirect(url('loan.application', ['id' => $id]));
        case 'writeoff':
            Access::require($pageKey, 'approve');
            coop_once();
            $classId = Classes::required('class_id', 'the write-off');
            $txnId = CoopLedger::withClass($classId, fn() => Loans::writeOff((int)$loan['id'], (string)p('wo_date', today()), trim((string)p('wo_reason')), p('use_allowance') === '1', $pageKey));
            flash('success', 'Loan ' . $loan['loan_no'] . ' written off' . ($txnId ? ' (voucher ' . Txn::load($txnId)['txn_no'] . ')' : '') . '. Recoveries are recorded on this page.');
            redirect(url('loan.accounts', ['id' => $loan['id']]));
        case 'recover':
            Access::require($pageKey, 'create');
            coop_once();
            $mode = p('rmode') === 'bank' ? 'bank' : 'cash';
            $shift = null;
            if ($mode === 'cash') {
                $shift = Teller::requireShift();
                $cash = $shift ? (int)$shift['cash_account_id'] : Teller::userCashAccount();
            } else {
                $cash = (int)pint('bank_account_id');
                if (!$cash) {
                    fail('Choose the bank account.');
                }
            }
            $date = (string)p('r_date', today());
            Coop::checkDate($date);
            $orNo = trim((string)p('or_no')) ?: Coop::nextReceiptNo('or', $loan['branch_id'] ? (int)$loan['branch_id'] : null);
            if (DB::value("SELECT id FROM coop_receipts WHERE receipt_kind = 'or' AND receipt_no = :n", ['n' => $orNo])) {
                fail('Official receipt number ' . $orNo . ' is already used.');
            }
            $classId = Classes::required('class_id', 'the recovery');
            $txnId = CoopLedger::withClass($classId, fn() => Loans::recover((int)$loan['id'], $date, Coop::amount('r_amount', true), (int)$cash, ['or_no' => $orNo, 'shift_id' => $shift['id'] ?? null, 'payment_mode' => $mode], $pageKey));
            flash('success', 'Recovery of ' . Dec::money(Coop::amount('r_amount')) . ' recorded on OR ' . $orNo . '.');
            redirect(url('teller.or', ['a' => 'view', 'id' => $txnId]));
        default:
            fail('Unknown action.');
    }
});

// --------------------------------------------------------------------------------------- candidates
if (!$loan) {
    [$bf, $bp] = Coop::branchFilter('l.branch_id');
    $pastDue = DB::all("SELECT l.*, m.full_name, m.member_no, p.name AS product_name, (SELECT MIN(s.due_date) FROM loan_schedule s WHERE s.loan_id = l.id AND s.is_paid = 0) AS oldest
                          FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id
                         WHERE l.status IN ('past_due','restructured')" . $bf . ' ORDER BY oldest LIMIT 300', $bp);
    $written = DB::all("SELECT l.*, m.full_name, m.member_no FROM loan_accounts l JOIN members m ON m.id = l.member_id WHERE l.status = 'written_off'" . $bf . ' ORDER BY l.written_off_on DESC LIMIT 300', $bp);
    coop_layout('Restructure / Renew / Write Off');
    ?>
    <p class="text-muted small">Renewals of current loans start from the loan account (More, then Renew). Below: loans in arrears, which may be restructured or written off, and written-off loans, on which recoveries are recorded.</p>
    <div class="card mb-3"><div class="card-header">Loans in arrears</div><div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Loan</th><th>Member</th><th>Product</th><th>Oldest unpaid</th><th class="num">Days</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($pastDue as $r): $days = $r['oldest'] ? max(0, Coop::daysBetween($r['oldest'], today())) : 0; ?><tr><td><?= safe_link('loan.accounts', ['id' => $r['id']], $r['loan_no']) ?></td><td><?= h($r['full_name']) ?><div class="small text-muted"><?= h($r['member_no']) ?></div></td>
        <td class="small"><?= h($r['product_name']) ?></td><td class="small"><?= h(fdate((string)$r['oldest'])) ?></td><td class="num"><?= $days ?></td><td class="num"><?= cm($r['principal_balance']) ?></td><td><?= coop_status($r['status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['loan' => $r['id']])) ?>">Open</a></td></tr><?php endforeach; ?>
      <?php if (!$pastDue): ?><tr><td colspan="8" class="text-muted">No loan in arrears.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="card"><div class="card-header">Written off</div><div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Loan</th><th>Member</th><th>Written off</th><th class="num">Principal</th><th class="num">Recovered</th><th>Reason</th><th></th></tr></thead><tbody>
      <?php foreach ($written as $r): ?><tr><td><?= safe_link('loan.accounts', ['id' => $r['id']], $r['loan_no']) ?></td><td><?= h($r['full_name']) ?></td><td class="small"><?= h(fdate((string)$r['written_off_on'])) ?></td>
        <td class="num"><?= cm($r['principal']) ?></td><td class="num"><?= cm($r['recovered_amount']) ?></td><td class="small"><?= h((string)$r['notes']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['loan' => $r['id']])) ?>">Recovery</a></td></tr><?php endforeach; ?>
      <?php if (!$written): ?><tr><td colspan="7" class="text-muted">None.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php
    layout_end();
    exit;
}

// --------------------------------------------------------------------------------------- one loan
$open = in_array($loan['status'], Loans::OPEN_STATUSES, true);
$d = $open ? Loans::dues($loan, today()) : null;
$aging = $open ? Loans::aging($loan, today(), 'par') : null;
$p = Loans::product((int)$loan['product_id']);
$pending = DB::row("SELECT id, application_no, status FROM loan_applications WHERE previous_loan_id = :l AND status IN ('new','validated','for_ci','for_approval','approved','returned')", ['l' => $loan['id']]);
$shift = Teller::openShift();
$suggest = '0';
if ($d) {
    // the smallest whole-peso amount whose proceeds, after the charges deducted, still cover the payoff
    $ceil = function (string $v): string {
        $r = Dec::round($v, 0);
        return Dec::cmp($r, $v) < 0 ? Dec::add($r, '1', 0) : $r;
    };
    $suggest = $ceil($d['payoff']);
    for ($i = 0; $i < 25; $i++) {
        $ch = $p['charge_method'] === 'deducted' ? Dec::sum(array_column(Loans::charges($p, $suggest, Loans::termMonths($loan['term'], $loan['term_unit'])), 'amount')) : '0';
        $need = $ceil(Dec::add($d['payoff'], $ch, 2));
        if (Dec::cmp($need, $suggest) <= 0) {
            break;
        }
        $suggest = $need;
    }
    $suggest = Dec::fix($suggest);
}
coop_layout(($loan['status'] === 'written_off' ? 'Recovery on ' : 'Restructure, renew or write off ') . $loan['loan_no'], ['actions' => btn_link(url('loan.accounts', ['id' => $loan['id']]), 'Loan', 'btn-outline-secondary', 'arrow-left')]);
?>
<div class="mb-3"><?= member_card(Coop::member((int)$loan['member_id'])) ?></div>
<?php if ($open): ?>
<div class="coop-tiles">
  <div class="coop-tile"><div class="t-label">Principal balance</div><div class="t-value"><?= cm($loan['principal_balance']) ?></div></div>
  <div class="coop-tile <?= $d['count_due'] ? 'is-bad' : '' ?>"><div class="t-label">In arrears</div><div class="t-value"><?= cm($d['total_due']) ?></div><div class="t-sub"><?= (int)$d['count_due'] ?> amortization(s), <?= (int)$aging['days'] ?> days</div></div>
  <div class="coop-tile"><div class="t-label">Payoff today</div><div class="t-value"><?= cm($d['payoff']) ?></div></div>
  <div class="coop-tile"><div class="t-label">Accrued interest in income</div><div class="t-value"><?= cm($loan['interest_accrued']) ?></div></div>
</div>
<div class="row g-3">
  <div class="col-xl-7">
    <?php if ($pending): ?><div class="alert alert-info">Application <?= safe_link('loan.application', ['id' => $pending['id']], $pending['application_no']) ?> (<?= h(mb_strtolower(Loans::APP_STATUSES[$pending['status']])) ?>) already renews or restructures this loan.</div><?php endif; ?>
    <?php if (Access::can($pageKey, 'create') && Access::can('loan.application', 'create') && !$pending): ?>
    <form method="post" class="card" data-once data-confirm="Create the application? It goes through validation and approval like any loan." data-confirm-ok="Create the application"><div class="card-header">Renew or restructure</div><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="renew">
      <?= select_field('kind', 'This is a', ['renew' => 'Renewal (a new loan that pays this one off)', 'restructure' => 'Restructuring (new terms for a loan in arrears)'], fv('kind', null, $loan['status'] === 'past_due' ? 'restructure' : 'renew'), ['class' => 'col-md-7', 'extra' => 'data-no-combo']) ?>
      <?= input_field('application_date', 'Date', fv('application_date', null, today()), ['class' => 'col-md-5', 'type' => 'date']) ?>
      <?= select_field('product_id', 'Product', Loans::productOptions(), fv('product_id', null, $loan['product_id']), ['class' => 'col-md-7', 'extra' => 'data-no-combo']) ?>
      <?= coop_money('applied_amount', 'Amount', p('applied_amount', $suggest), ['class' => 'col-md-5']) ?>
      <?= input_field('term', 'Term', fv('term', null, Dec::fix($loan['term'], 0)), ['class' => 'col-4 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= select_field('term_unit', 'in', ['months' => 'months', 'days' => 'days'], fv('term_unit', null, $loan['term_unit']), ['class' => 'col-4 col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('mode_of_payment', 'Mode', Coop::modeOptions(), fv('mode_of_payment', null, $loan['mode_of_payment']), ['class' => 'col-4 col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('amort_type', 'Amortization', Loans::AMORT_TYPES, fv('amort_type', null, $loan['amort_type']), ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
      <?= Classes::requiredField(fv('class_id', null), 'col-md-4') ?>
      <?= input_field('reason', 'Reason / committee action', fv('reason', null), ['class' => 'col-md-8']) ?>
      <div class="col-12 small text-muted">The amount must at least cover the payoff on the release date (<?= cm($d['payoff']) ?> today) plus the charges deducted; the amount suggested does, with this product's charges. A restructured loan stays flagged as restructured in the portfolio reports. The new application starts with the Capacity to pay and the credit investigation of the borrower's last loan.</div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Create the application</button></div>
    </div></form>
    <?php endif; ?>
  </div>
  <div class="col-xl-5">
    <?php if (Access::can($pageKey, 'approve')): ?>
    <form method="post" class="card border-danger-subtle" data-once data-confirm="Write off loan <?= h($loan['loan_no']) ?>? The principal balance of <?= h(Dec::money($loan['principal_balance'])) ?> leaves loans receivable; the interest and penalty balances are dropped from the member ledger." data-confirm-title="Write off the loan" data-confirm-ok="Write off">
      <div class="card-header">Write off</div><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="writeoff">
      <?= input_field('wo_date', 'Date', fv('wo_date', null, today()), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= check_field('use_allowance', 'Against the allowance for probable losses', fv('use_allowance', null, 1), 'col-6 pt-4') ?>
      <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
      <?= input_field('wo_reason', 'Board resolution no. / reason', fv('wo_reason', null), ['class' => 'col-12', 'required' => true]) ?>
      <div class="col-12 small text-muted">Dr allowance for probable losses (or provision expense), Cr loans receivable. Accrued interest already in income is reversed. Collections later are recorded here as recoveries.</div>
      <div class="col-12"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-octagon"></i> Write off</button></div>
    </div></form>
    <?php else: ?><div class="card"><div class="card-body small text-muted">Writing off a loan needs the Approve right on this page.</div></div><?php endif; ?>
  </div>
</div>
<?php elseif ($loan['status'] === 'written_off'):
    $recs = DB::all("SELECT x.*, t.txn_no, t.txn_type FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id WHERE x.loan_id = :l AND x.kind = 'recovery' AND x.is_voided = 0 ORDER BY x.txn_date", ['l' => $loan['id']]); ?>
<div class="row g-3">
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-body"><?= coop_kv(['Written off' => h(fdate((string)$loan['written_off_on'])), 'Reason' => h((string)$loan['notes']), 'Principal written off' => cm(DB::value("SELECT principal_cr FROM loan_txns WHERE loan_id = :l AND kind = 'writeoff' ORDER BY id DESC LIMIT 1", ['l' => $loan['id']]) ?? '0'), 'Recovered so far' => cm($loan['recovered_amount'])]) ?></div></div>
    <?php if (Access::can($pageKey, 'create')): ?>
    <form method="post" class="card" data-once data-confirm="Record a recovery of {amount}?" data-confirm-ok="Record the recovery"><div class="card-header">Record a recovery</div><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="recover">
      <?= coop_money('r_amount', 'Amount', p('r_amount'), ['class' => 'col-6', 'extra' => 'data-confirm-value="amount"']) ?>
      <?= input_field('r_date', 'Date', fv('r_date', null, today()), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= select_field('rmode', 'Received in', ['cash' => 'Cash (teller drawer)', 'bank' => 'Bank deposit'], fv('rmode', null, 'cash'), ['class' => 'col-6', 'extra' => 'id="rMode" data-no-combo']) ?>
      <?= input_field('or_no', 'OR no.', fv('or_no', null), ['class' => 'col-6', 'extra' => 'placeholder="next number"']) ?>
      <div class="col-12" data-toggle-by="#rMode" data-show-when="bank"><?= str_replace('col-md-4', '', select_field('bank_account_id', 'Bank account', Accts::banks(), fv('bank_account_id', null), ['class' => '', 'blank' => ''])) ?></div>
      <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
      <div class="col-12 small text-muted" data-toggle-by="#rMode" data-show-when="cash"><?= $shift ? 'Drawer ' . h($shift['cash_code'] . ' ' . $shift['cash_name']) . ', shift ' . (int)$shift['id'] : '<span class="text-danger">Your teller shift is not open.</span>' ?></div>
      <div class="col-12 small text-muted">Dr cash, Cr recovery income on written-off loans.</div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-cash-coin"></i> Record the recovery</button></div>
    </div></form>
    <?php endif; ?>
  </div>
  <div class="col-xl-7"><div class="card"><div class="card-header">Recoveries</div><table class="table table-sm"><tbody>
    <?php foreach ($recs as $r): ?><tr><td><?= h(fdate($r['txn_date'])) ?></td><td><?= $r['txn_id'] ? '<a href="' . h(Txn::url(['id' => $r['txn_id'], 'txn_type' => $r['txn_type']])) . '">' . h((string)($r['or_no'] ?: $r['txn_no'])) . '</a>' : '' ?></td>
      <td class="num"><?= cm(Txn::load((int)$r['txn_id'])['total'] ?? '0') ?></td></tr><?php endforeach; ?>
    <?php if (!$recs): ?><tr><td class="text-muted">None yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php else: ?>
  <div class="alert alert-secondary">Loan <?= h($loan['loan_no']) ?> is <?= h(mb_strtolower(Loans::LOAN_STATUSES[$loan['status']] ?? $loan['status'])) ?>; there is nothing to restructure.</div>
<?php endif;
layout_end();
