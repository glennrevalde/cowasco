<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.adjustments';
$loan = gint('loan') ? Loans::loan((int)gint('loan')) : null;
if ($loan && !Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
    Access::denyPage($pageKey, 'adjust a loan of another branch on');
}

if (!$loan && trim((string)g('loan_no')) !== '') {
    // a loan no., or a name / loan product that finds one outstanding loan, opens it
    $found = Loans::byNo((string)g('loan_no'));
    if (!$found) {
        $one = Loans::searchOutstanding((string)g('loan_no'), 2);
        $found = $one['total'] === 1 ? ['id' => $one['rows'][0]['id']] : null;
    }
    if ($found) {
        redirect(url($pageKey, ['loan' => $found['id']]));
    }
}

handle_post(function () use ($loan, $pageKey) {
    if (!$loan) {
        fail('Choose the loan.');
    }
    if (p('action') === 'pb_commit') {
        // the passbook lines printed after the adjustment (or by Update Passbook): marked page by page
        Access::require('sav.passbook', 'print');
        $pb = Passbook::open('loan', (string)$loan['loan_no']);
        if (!$pb || !$pb['type']) {
            fail('The loan product has no passbook type (Loan Products).');
        }
        [$n, $pages] = Passbook::commitRun($pb, Passbook::postedRun(), (int)p('upto'), 'sav.passbook');
        $left = count(Passbook::pending(Passbook::open('loan', (string)$loan['loan_no'])));
        flash('success', $n . ' line(s) of loan ' . $loan['loan_no'] . ' marked as printed in the passbook (' . coop_pb_pages_words($pages) . ').' . ($left ? ' ' . $left . ' line(s) still to print: Update Passbook prints them.' : ''));
        redirect(url($pageKey, ['loan' => $loan['id']]));
    }
    Access::require($pageKey, 'create');
    coop_once();
    $amounts = [];
    foreach (['principal', 'interest', 'penalty'] as $k) {
        $amounts[$k] = ['amount' => Coop::amount($k . '_amt'), 'type' => p($k . '_type') === 'increase' ? 'increase' : 'decrease'];
    }
    $adjAcct = (int)pint('adj_account_id');
    if (Dec::isPos($amounts['principal']['amount']) && !$adjAcct) {
        fail('Choose the account the principal adjustment is charged against.');
    }
    $approver = Approval::check($pageKey, !Access::can($pageKey, 'approve'), 'loan balance adjustment');
    $classId = Classes::required('class_id', 'the loan adjustment');
    $id = CoopLedger::withClass($classId, fn() => Loans::adjust((int)$loan['id'], (string)p('adj_date', today()), $adjAcct ?: (int)$loan['receivable_account_id'], $amounts, trim((string)p('memo')), $pageKey));
    if ($approver) {
        Audit::log('approve', 'loan', 'loan_adjustment', $id, $loan['loan_no'], ['page_key' => $pageKey, 'approved_by' => $approver['username'] ?? null]);
    }
    // the page opens again with the adjustment; with Print to Passbook ticked, its passbook printing comes up
    redirect(url($pageKey, ['loan' => $loan['id'], 'done' => $id, 'pp' => p('print_pb') === '1' ? 1 : null]));
});

// after recording: the adjustment, and the passbook lines of the loan not yet printed (the adjustment among them)
$done = $loan && gint('done') ? DB::row('SELECT * FROM loan_adjustments WHERE id = :id AND loan_id = :l', ['id' => (int)gint('done'), 'l' => $loan['id']]) : null;
$pb = $loan ? Passbook::open('loan', (string)$loan['loan_no']) : null;
$pbRun = $pb && $pb['type'] ? Passbook::run($pb) : null;
$canPbPrint = Access::can('sav.passbook', 'print');

$from = gdate('from', date('Y-01-01'));
$to = gdate('to', today());
[$bf, $bp] = Coop::branchFilter('l.branch_id');
$where = 'a.adj_date BETWEEN :f AND :t' . $bf;
$params = ['f' => $from, 't' => $to] + $bp;
if ($loan) {
    $where .= ' AND a.loan_id = :l';
    $params['l'] = $loan['id'];
}
$rows = DB::all("SELECT a.*, l.loan_no, m.full_name, t.txn_no, t.txn_type, t.is_voided, ac.code AS acode, ac.name AS aname, u.full_name AS by_name
                   FROM loan_adjustments a JOIN loan_accounts l ON l.id = a.loan_id JOIN members m ON m.id = l.member_id LEFT JOIN transactions t ON t.id = a.txn_id
                   LEFT JOIN accounts ac ON ac.id = a.adj_account_id LEFT JOIN users u ON u.id = a.created_by
                  WHERE $where ORDER BY a.adj_date DESC, a.id DESC LIMIT 300", $params);
$actions = [];
if ($loan && $pb && $pb['type'] && $canPbPrint) {
    $actions[] = '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#laPbModal"><i class="bi bi-journal-arrow-down"></i> Update Passbook'
        . (!empty($pbRun['count']) ? ' (' . (int)$pbRun['count'] . ')' : '') . '</button>';
}
if ($loan) {
    $actions[] = btn_link(url('loan.accounts', ['id' => $loan['id']]), 'Loan', 'btn-outline-secondary', 'arrow-left');
}
coop_layout($loan ? 'Adjust ' . $loan['loan_no'] : 'Loan Adjustments', ['actions' => implode(' ', $actions), 'js' => ['js/savings.js', 'js/loan_pick.js']]);
$typeSel = fn(string $name) => '<select class="form-select form-select-sm" name="' . $name . '" data-no-combo>' . options(['decrease' => 'Decrease', 'increase' => 'Increase'], (string)p($name, 'decrease'), '') . '</select>';
?>
<?php if ($done): $sg = fn($amt, $type) => Dec::isPos($amt) ? ($type === 'increase' ? 'plus ' : 'less ') . cm($amt) : null;
    $what = array_filter(['principal' => $sg($done['principal_amt'], $done['principal_type']), 'interest' => $sg($done['interest_amt'], $done['interest_type']), 'penalty' => $sg($done['penalty_amt'], $done['penalty_type'])]); ?>
  <?php if (g('pp') === '1' && !empty($pbRun['count']) && $canPbPrint): ?><div data-after-save data-passbook="#laPbModal" hidden></div><?php endif; ?>
  <div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 no-print">
    <span>Adjustment recorded on <strong><?= h($loan['loan_no']) ?></strong>: <?= h(implode(', ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($what), $what))) ?>.
      <?php if (g('pp') === '1' && $pb && !$pb['type']): ?> The product of this loan has no passbook type (Loan Products), so there is nothing to print.<?php endif; ?></span>
    <?php if ($pb && $pb['type'] && $canPbPrint && !empty($pbRun['count'])): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#laPbModal"><i class="bi bi-journal-arrow-down"></i> Print to Passbook</button><?php endif; ?>
  </div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-5">
    <?php if (!$loan): ?>
      <form class="card mb-3"><div class="card-body row g-2 align-items-end"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
        <?= Loans::pickerBox('loan_no', (string)g('loan_no'), ['label' => 'Borrower or loan no.', 'class' => 'col', 'submit' => true, 'id' => 'laLoan']) ?>
        <div class="col-auto"><button class="btn btn-sm btn-primary">Open</button></div>
        <?php if (g('loan_no')): ?><div class="col-12 small text-danger">No loan no. is "<?= h((string)g('loan_no')) ?>" and it does not find one outstanding loan. Click the box and choose the loan from the list of borrowers with outstanding loans.</div><?php endif; ?>
      </div></form>
    <?php else: ?>
      <div class="mb-3"><?= member_card(Coop::member((int)$loan['member_id'])) ?></div>
      <?php if (!in_array($loan['status'], Loans::OPEN_STATUSES, true)): ?><div class="alert alert-secondary">The loan is <?= h(mb_strtolower(Loans::LOAN_STATUSES[$loan['status']] ?? $loan['status'])) ?>; closed loans are not adjusted.</div>
      <?php elseif (!Access::special('coop_waive')): ?><div class="alert alert-warning">Adjusting loan balances needs the "can waive loan interest and penalties, and adjust loan balances" permission.</div>
      <?php elseif (Access::can($pageKey, 'create')): ?>
      <form method="post" class="card la-form" data-once data-confirm="Record this adjustment on <?= h($loan['loan_no']) ?>?" data-confirm-ok="Record the adjustment"><div class="card-header">Adjustment</div><div class="card-body row g-2">
        <?= csrf_field() ?><?= Coop::onceField() ?>
        <table class="table table-sm mb-1 la-grid"><thead><tr><th></th><th class="num">Balance</th><th>Change</th><th>Amount</th></tr></thead><tbody>
          <?php foreach (['principal' => 'Principal', 'interest' => 'Interest', 'penalty' => 'Penalty'] as $k => $lbl): ?>
          <tr><th scope="row"><?= h($lbl) ?></th><td class="num" data-label="Balance"><?= cm($loan[$k . '_balance']) ?></td><td data-label="Change"><?= $typeSel($k . '_type') ?></td>
            <td data-label="Amount"><input class="form-control form-control-sm text-end" name="<?= $k ?>_amt" value="<?= h((string)p($k . '_amt')) ?>" inputmode="decimal" data-money aria-label="<?= h($lbl) ?> amount"></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        <?= select_field('adj_account_id', 'Principal adjusted against (account)', Accts::all(), fv('adj_account_id', null), ['class' => 'col-12', 'blank' => '']) ?>
        <?= input_field('adj_date', 'Date', fv('adj_date', null, today()), ['class' => 'col-6', 'type' => 'date']) ?>
        <?= Classes::requiredField(fv('class_id', null), 'col-6') ?>
        <?= input_field('memo', 'Reason', fv('memo', null), ['class' => 'col-12', 'required' => true]) ?>
        <div class="col-12 small text-muted">A principal change posts Dr / Cr loans receivable against the account chosen. Interest and penalty changes adjust the member ledger only: they are income when collected. A decrease settles the oldest unpaid interest and the last principal amortizations.</div>
        <div class="col-12"><?= Approval::fields() ?></div>
        <div class="col-12 la-submit">
          <button class="btn btn-primary btn-sm"><i class="bi bi-sliders"></i> Record the adjustment</button>
          <?php if ($canPbPrint): ?><div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="print_pb" value="1" id="laPrintPb" <?= is_post() ? (p('print_pb') === '1' ? 'checked' : '') : 'checked' ?>>
            <label class="form-check-label" for="laPrintPb">Print to Passbook</label></div><?php endif; ?>
        </div>
        <?php if ($canPbPrint): ?><div class="col-12 small text-muted">With <strong>Print to Passbook</strong> ticked, the adjustment is printed in the loan passbook as soon as it is recorded (code AJ: a decrease as a payment, an increase negative), with any other line of the loan not yet printed.<?= $pb && !$pb['type'] ? ' The product of this loan has no passbook type yet (Loan Products).' : '' ?></div><?php endif; ?>
      </div></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="col-xl-7">
    <form class="row g-2 align-items-end filters mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><?php if ($loan): ?><input type="hidden" name="loan" value="<?= (int)$loan['id'] ?>"><?php endif; ?>
      <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => 'col-6 col-md-4']) ?>
      <?= input_field('to', 'To', $to, ['type' => 'date', 'class' => 'col-6 col-md-4']) ?>
      <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div></form>
    <div class="card"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Loan</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th>Reason</th><th>Voucher</th></tr></thead><tbody>
    <?php $sg = fn($amt, $type) => Dec::isPos($amt) ? ($type === 'increase' ? '+' : '-') . cm($amt) : ''; foreach ($rows as $a): ?>
      <tr class="<?= (int)$a['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>"><td class="small"><?= h(fdate($a['adj_date'])) ?></td><td><?= safe_link('loan.accounts', ['id' => $a['loan_id'], 'tab' => 'ledger'], $a['loan_no']) ?><div class="small text-muted"><?= h($a['full_name']) ?></div></td>
        <td class="num"><?= $sg($a['principal_amt'], $a['principal_type']) ?></td><td class="num"><?= $sg($a['interest_amt'], $a['interest_type']) ?></td><td class="num"><?= $sg($a['penalty_amt'], $a['penalty_type']) ?></td>
        <td class="small"><?= h((string)$a['memo']) ?><div class="text-muted"><?= h((string)$a['by_name']) ?><?= Dec::isPos($a['principal_amt']) ? ', against ' . h($a['acode'] . ' ' . $a['aname']) : '' ?></div></td>
        <td class="small"><?= $a['txn_id'] ? '<a href="' . h(Txn::url(['id' => $a['txn_id'], 'txn_type' => $a['txn_type']])) . '">' . h($a['txn_no']) . '</a>' : 'ledger only' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No adjustment in this period.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
</div>
<?php if ($loan && $pb && $pb['type']): ?>
<div class="modal fade" id="laPbModal" tabindex="-1" aria-labelledby="laPbTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="laPbTitle">Print to Passbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="fw-semibold mb-2"><?= h($loan['product_name']) ?> <?= h($loan['loan_no']) ?></div>
    <?php if (!empty($pbRun['count'])): ?>
      <?= coop_pb_run_box($pb, $pbRun, 'laPbConfirm', '<input type="hidden" name="action" value="pb_commit">', '<strong>' . h($loan['member_name']) . '</strong>', $canPbPrint) ?>
    <?php else: ?>
      <p class="mb-0">Every entry of loan <?= h($loan['loan_no']) ?> is printed in the passbook.</p>
    <?php endif; ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>
<?php endif; ?>
<?php
layout_end();
