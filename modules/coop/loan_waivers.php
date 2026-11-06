<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.waivers';
$loan = gint('loan') ? Loans::loan((int)gint('loan')) : null;
if ($loan && !Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
    Access::denyPage($pageKey, 'waive on a loan of another branch on');
}

handle_post(function () use ($loan, $pageKey) {
    $action = (string)p('action');
    if ($action === 'request') {
        Access::require($pageKey, 'create');
        coop_once();
        if (!$loan) {
            fail('Choose the loan.');
        }
        $date = (string)p('waive_date', today());
        Coop::checkDate($date);
        $classId = Classes::required('class_id', 'the waiver');
        $id = Loans::requestWaiver((int)$loan['id'], $date, Coop::amount('interest'), Coop::amount('penalty'), trim((string)p('memo')), $pageKey, $classId);
        // an officer who may approve and holds the waiver permission may post a waiver another user requested; their own needs a second person
        flash('success', 'Waiver request ' . $id . ' recorded. It takes effect when an approver posts it.');
        redirect(url($pageKey, ['loan' => $loan['id']]));
    }
    if ($action === 'post_penalty') {
        Access::require($pageKey, 'create');
        if (!$loan) {
            fail('Choose the loan.');
        }
        $n = DB::tx(function () use ($loan) {
            $l = Loans::loan((int)$loan['id'], true);
            $new = Loans::chargePenalty($l, today());
            Loans::refreshBalances((int)$l['id'], today());
            return $new;
        });
        Audit::log('post', 'loan', 'loan_account', (int)$loan['id'], $loan['loan_no'] . ': penalty through ' . fdate(today()), ['page_key' => $pageKey, 'amount' => (float)$n]);
        flash('success', 'Penalty through today posted: ' . Dec::money($n) . '.');
        redirect(url($pageKey, ['loan' => $loan['id']]));
    }
    if ($action === 'decide') {
        $approve = p('decision') === 'approve';
        if (!$approve && trim((string)p('remarks')) === '') {
            fail('Enter the reason for rejecting the waiver.');
        }
        $classId = $approve ? Classes::required('class_id', 'the waiver') : null;
        CoopLedger::withClass($classId, fn() => Loans::approveWaiver((int)pint('waiver_id'), $approve, trim((string)p('remarks')), $pageKey));
        flash('success', $approve ? 'Waiver posted to the loan.' : 'Waiver rejected.');
        redirect(url($pageKey, $loan ? ['loan' => $loan['id']] : []));
    }
    fail('Unknown action.');
});

$status = in_array(g('status'), ['pending', 'posted', 'rejected', 'all'], true) ? (string)g('status') : 'pending';
[$bf, $bp] = Coop::branchFilter('l.branch_id');
$where = '1 = 1' . $bf;
$params = $bp;
if ($status !== 'all') {
    $where .= ' AND w.status = :st';
    $params['st'] = $status;
}
if ($loan) {
    $where .= ' AND w.loan_id = :l';
    $params['l'] = $loan['id'];
}
$rows = DB::all("SELECT w.*, l.loan_no, l.interest_balance, l.penalty_balance, m.full_name, m.member_no, ur.full_name AS requester, ua.full_name AS approver
                   FROM loan_waivers w JOIN loan_accounts l ON l.id = w.loan_id JOIN members m ON m.id = l.member_id
                   LEFT JOIN users ur ON ur.id = w.requested_by LEFT JOIN users ua ON ua.id = w.approved_by
                  WHERE $where ORDER BY w.id DESC LIMIT 300", $params);
$canDecide = Access::can($pageKey, 'approve') && Access::special('coop_waive');
coop_layout($loan ? 'Waive interest or penalty: ' . $loan['loan_no'] : 'Interest / Penalty Waiving', ['actions' => $loan ? btn_link(url('loan.accounts', ['id' => $loan['id']]), 'Loan', 'btn-outline-secondary', 'arrow-left') : '']);
?>
<div class="row g-3">
  <?php if ($loan): $d = Loans::dues($loan, today()); ?>
  <div class="col-xl-5">
    <div class="mb-3"><?= member_card(Coop::member((int)$loan['member_id'])) ?></div>
    <div class="card mb-3"><div class="card-header">Balances of <?= h($loan['loan_no']) ?></div><table class="table table-sm mb-0"><tbody>
      <tr><td>Interest balance (scheduled, unpaid)</td><td class="num"><?= cm($loan['interest_balance']) ?></td></tr>
      <tr><td>Of which already due</td><td class="num"><?= cm($d['interest_due']) ?></td></tr>
      <tr><td>Penalty posted</td><td class="num"><?= cm($loan['penalty_balance']) ?></td></tr>
      <tr><td>Penalty earned, not yet posted</td><td class="num"><?= cm($d['penalty_new']) ?></td></tr>
      <tr><td>Interest in income already (accrued)</td><td class="num"><?= cm($loan['interest_accrued']) ?></td></tr>
    </tbody></table>
    <?php if (Dec::isPos($d['penalty_new']) && Access::can($pageKey, 'create')): ?><form method="post" class="card-body pt-2"><?= csrf_field() ?><input type="hidden" name="action" value="post_penalty">
      <button class="btn btn-sm btn-outline-secondary" data-confirm="Post <?= h(Dec::money($d['penalty_new'])) ?> of penalty through today, so it can be waived?">Post the penalty through today first</button></form><?php endif; ?>
    </div>
    <?php if (Access::can($pageKey, 'create') && in_array($loan['status'], Loans::OPEN_STATUSES, true)): ?>
    <form method="post" class="card" data-once data-confirm="Request the waiver? An approver posts it." data-confirm-ok="Request the waiver"><div class="card-header">Request a waiver</div><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="request">
      <?= coop_money('interest', 'Interest to waive', p('interest', '0'), ['class' => 'col-6']) ?>
      <?= coop_money('penalty', 'Penalty to waive', p('penalty', '0'), ['class' => 'col-6']) ?>
      <?= input_field('waive_date', 'Date', fv('waive_date', null, today()), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= Classes::requiredField(fv('class_id', null), 'col-6') ?>
      <?= input_field('memo', 'Reason / board or committee resolution', fv('memo', null), ['class' => 'col-12', 'required' => true]) ?>
      <div class="col-12 small text-muted">Waived interest that was already accrued into income is taken out of income when the waiver is posted. The waiver is posted by someone other than the requester, with the Approve right and the waiver permission.</div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Request the waiver</button></div>
    </div></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="<?= $loan ? 'col-xl-7' : 'col-12' ?>">
    <form class="row g-2 align-items-end filters mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><?php if ($loan): ?><input type="hidden" name="loan" value="<?= (int)$loan['id'] ?>"><?php endif; ?>
      <?= select_field('status', 'Show', ['pending' => 'Waiting for approval', 'posted' => 'Posted', 'rejected' => 'Rejected', 'all' => 'All'], $status, ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo data-autosubmit']) ?>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Loan</th><th class="num">Interest</th><th class="num">Penalty</th><th>Reason</th><th>Requested / decided</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $w): ?>
      <tr><td class="small"><?= h(fdate($w['waive_date'])) ?></td><td><?= safe_link('loan.accounts', ['id' => $w['loan_id']], $w['loan_no']) ?><div class="small text-muted"><?= h($w['full_name']) ?></div></td>
        <td class="num"><?= cm($w['interest_amount'], true) ?></td><td class="num"><?= cm($w['penalty_amount'], true) ?></td><td class="small"><?= h((string)$w['memo']) ?></td>
        <td class="small"><?= h((string)$w['requester']) ?><?= $w['approver'] ? '<div class="text-muted">' . h($w['approver']) . ', ' . h(fdatetime($w['approved_at'])) . '</div>' : '' ?></td>
        <td><?= coop_status($w['status']) ?>
        <?php if ($w['status'] === 'pending' && $canDecide && ((int)$w['requested_by'] !== Auth::id() || Access::isAdmin())): ?>
          <form method="post" class="mt-1 d-flex gap-1 flex-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="waiver_id" value="<?= (int)$w['id'] ?>">
            <select class="form-select form-select-sm" name="class_id" required data-no-combo aria-label="Class of the waiver" style="min-width:140px"><?= options(Classes::options(), $w['class_id'] ?? Classes::lastUsed(), 'Class') ?></select>
            <input class="form-control form-control-sm" name="remarks" placeholder="Remarks (required to reject)" style="min-width:160px">
            <button class="btn btn-sm btn-success" name="decision" value="approve" data-confirm="Post this waiver to the loan?">Approve</button>
            <button class="btn btn-sm btn-outline-danger" name="decision" value="reject" formnovalidate>Reject</button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No waiver.</td></tr><?php endif; ?>
    </tbody></table></div></div>
  </div>
</div>
<?php
layout_end();
