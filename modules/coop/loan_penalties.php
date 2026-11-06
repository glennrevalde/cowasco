<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.penalties';
$through = gdate('through', today());
$accThrough = gdate('acc_through', date('Y-m-t', strtotime('first day of last month')));
$accPost = gdate('acc_post', $accThrough);

handle_post(function () use ($pageKey) {
    Access::require($pageKey, 'create');
    coop_once();
    switch ((string)p('action')) {
        case 'penalties':
            $d = (string)p('through');
            if (!valid_date($d) || $d > today()) {
                fail('Enter a date up to today.');
            }
            $classId = Classes::required('class_id', 'the penalty run');
            $r = Loans::postPenalties($d, $pageKey, $classId);
            flash('success', 'Penalties through ' . fdate($d) . ' posted on ' . $r['count'] . ' loan(s): ' . Dec::money($r['amount']) . '. They are income when collected.');
            redirect(url($pageKey, ['through' => $d]));
        case 'accrue':
            $classId = Classes::required('class_id', 'the interest accrual');
            $r = CoopLedger::withClass($classId, fn() => Loans::accrueInterest((string)p('acc_through'), (string)p('acc_post'), $pageKey));
            if (!$r['count']) {
                flash('info', 'No interest to accrue: every loan is already accrued through that date.');
            } else {
                flash('success', 'Interest accrued on ' . $r['count'] . ' loan(s): ' . Dec::money($r['amount']) . ' (voucher ' . Txn::load((int)$r['txn_id'])['txn_no'] . ').');
            }
            redirect(url($pageKey, ['acc_through' => p('acc_through'), 'acc_post' => p('acc_post')]));
        case 'statuses':
            $before = DB::pairs("SELECT status, COUNT(*) FROM loan_accounts WHERE status IN ('current','past_due') GROUP BY status");
            Loans::refreshStatuses();
            $after = DB::pairs("SELECT status, COUNT(*) FROM loan_accounts WHERE status IN ('current','past_due') GROUP BY status");
            Audit::log('refresh', 'loan', 'statuses', null, 'Loan statuses refreshed', ['page_key' => $pageKey, 'reason' => 'past due ' . (int)($before['past_due'] ?? 0) . ' -> ' . (int)($after['past_due'] ?? 0)]);
            flash('success', 'Statuses refreshed: ' . (int)($after['current'] ?? 0) . ' current, ' . (int)($after['past_due'] ?? 0) . ' past due.');
            redirect(url($pageKey));
        default:
            fail('Unknown action.');
    }
});

// penalty preview: what each open loan would get through the date
$pen = [];
$penTotal = '0';
if (valid_date($through)) {
    [$bf, $bp] = Coop::branchFilter('l.branch_id');
    foreach (DB::all("SELECT l.id FROM loan_accounts l WHERE l.status IN ('current','past_due','restructured') AND l.penalty_rate > 0" . $bf . ' ORDER BY l.loan_no', $bp) as $r) {
        $loan = Loans::loan((int)$r['id']);
        if ($loan['last_penalty_date'] && $loan['last_penalty_date'] >= $through) {
            continue;
        }
        $x = Loans::penaltyThrough($loan, $through);
        if (Dec::isPos($x['new'])) {
            $pen[] = ['loan' => $loan, 'new' => $x['new'], 'detail' => $x['detail']];
            $penTotal = Dec::add($penTotal, $x['new'], 2);
        }
    }
}
$acc = null;
if (g('preview') === 'accrual') {
    try {
        $acc = Loans::accrueInterest($accThrough, $accPost, $pageKey, false);
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
}
$history = DB::all("SELECT t.id, t.txn_no, t.txn_type, t.txn_date, t.total, t.memo, t.is_voided FROM transactions t WHERE t.txn_type = 'coop_jv' AND t.memo LIKE 'Loan interest accrual%' ORDER BY t.txn_date DESC, t.id DESC LIMIT 24");
$runs = DB::all("SELECT a.* FROM audit_trail a WHERE a.page_key = :p AND a.action IN ('post','refresh') ORDER BY a.id DESC LIMIT 12", ['p' => $pageKey]);
$canPost = Access::can($pageKey, 'create');
coop_layout('Post Loan Penalties & Interest Accrual');
?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="card-header">Penalties</div><div class="card-body">
      <form class="row g-2 align-items-end mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
        <?= input_field('through', 'Compute through', $through, ['type' => 'date', 'class' => 'col-6 col-md-4', 'extra' => 'max="' . today() . '"']) ?>
        <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Preview</button></div></form>
      <p class="small text-muted">Penalties are also computed at the teller window when a loan is paid (unless that is turned off in the policies). Posting them here puts them on the member ledgers in one run, for month-end statements and the aging reports; they reach income when collected.</p>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Loan</th><th>Member</th><th>From</th><th class="num">Amortizations</th><th class="num">Penalty</th></tr></thead><tbody>
        <?php foreach ($pen as $x): ?><tr><td><?= safe_link('loan.accounts', ['id' => $x['loan']['id'], 'tab' => 'penalty'], $x['loan']['loan_no']) ?></td><td><?= h($x['loan']['member_name']) ?></td>
          <td class="small"><?= h(fdate($x['detail'][0]['from'] ?? '')) ?></td><td class="num"><?= count($x['detail']) ?></td><td class="num"><?= cm($x['new']) ?></td></tr><?php endforeach; ?>
        <?php if (!$pen): ?><tr><td colspan="5" class="text-muted">No penalty to post through <?= h(fdate($through)) ?>.</td></tr><?php endif; ?>
      </tbody><tfoot><tr class="fw-semibold"><td colspan="4"><?= count($pen) ?> loan(s)</td><td class="num"><?= cm($penTotal) ?></td></tr></tfoot></table></div>
      <?php if ($pen && $canPost): ?><form method="post" class="mt-2" data-once data-confirm="Post <?= h(Dec::money($penTotal)) ?> of penalties on <?= count($pen) ?> loan(s) through <?= h(fdate($through)) ?>?" data-confirm-ok="Post penalties">
        <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="penalties"><input type="hidden" name="through" value="<?= h($through) ?>">
        <div class="row g-2 align-items-end"><?= Classes::requiredField(fv('class_id', null), 'col-md-6') ?>
        <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-check2-all"></i> Post penalties</button></div></div></form><?php endif; ?>
    </div></div>

    <div class="card mb-3"><div class="card-header">Interest accrual (diminishing-balance loans)</div><div class="card-body">
      <form class="row g-2 align-items-end mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="preview" value="accrual">
        <?= input_field('acc_through', 'Interest earned through', $accThrough, ['type' => 'date', 'class' => 'col-6 col-md-4']) ?>
        <?= input_field('acc_post', 'Posting date', $accPost, ['type' => 'date', 'class' => 'col-6 col-md-4']) ?>
        <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Preview</button></div></form>
      <p class="small text-muted">Dr Interest receivable, Cr Interest income for the interest earned and not yet collected (scheduled interest of the amortizations already due, plus the days since the last one). When the member pays, the receivable is settled first. A second run for the same date adds nothing.</p>
      <?php if ($acc !== null): ?>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Loan</th><th>Member</th><th class="num">To accrue</th></tr></thead><tbody>
        <?php $names = $acc['lines'] ? DB::pairs('SELECT id, full_name FROM members WHERE id IN (' . implode(',', array_map(fn($l) => (int)$l['member_id'], $acc['lines'])) . ')') : [];
        foreach ($acc['lines'] as $l): ?><tr><td><?= h($l['loan_no']) ?></td><td><?= h((string)($names[$l['member_id']] ?? '')) ?></td><td class="num"><?= cm($l['amount']) ?></td></tr><?php endforeach; ?>
        <?php if (!$acc['lines']): ?><tr><td colspan="3" class="text-muted">Nothing to accrue through <?= h(fdate($accThrough)) ?>.</td></tr><?php endif; ?>
      </tbody><tfoot><tr class="fw-semibold"><td colspan="2"><?= count($acc['lines']) ?> loan(s)</td><td class="num"><?= cm($acc['amount']) ?></td></tr></tfoot></table></div>
      <?php if ($acc['lines'] && $canPost): ?><form method="post" class="mt-2" data-once data-confirm="Post the interest accrual of <?= h(Dec::money($acc['amount'])) ?> dated <?= h(fdate($accPost)) ?>?" data-confirm-ok="Post accrual">
        <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="accrue"><input type="hidden" name="acc_through" value="<?= h($accThrough) ?>"><input type="hidden" name="acc_post" value="<?= h($accPost) ?>">
        <div class="row g-2 align-items-end"><?= Classes::requiredField(fv('class_id', null), 'col-md-6') ?>
        <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="bi bi-journal-check"></i> Post the accrual</button></div></div></form><?php endif; ?>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header">Loan statuses</div><div class="card-body">
      <p class="small text-muted mb-2">A loan becomes past due the day after an amortization is missed, and current again when it is caught up. The Loan Accounts Center refreshes this when it opens; this button does it now for every branch.</p>
      <?php if ($canPost): ?><form method="post" data-once><?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="statuses"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Refresh statuses</button></form><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header">Accrual vouchers</div><table class="table table-sm"><tbody>
      <?php foreach ($history as $t): ?><tr class="<?= (int)$t['is_voided'] ? 'text-muted' : '' ?>"><td><?= h(fdate($t['txn_date'])) ?></td><td><a href="<?= h(Txn::url($t)) ?>"><?= h($t['txn_no']) ?></a><div class="small text-muted"><?= h($t['memo']) ?></div></td><td class="num"><?= cm($t['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$history): ?><tr><td class="text-muted">None yet.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="card"><div class="card-header">Recent runs</div><table class="table table-sm"><tbody>
      <?php foreach ($runs as $r): ?><tr><td class="small"><?= h(fdatetime($r['created_at'])) ?></td><td class="small"><?= h((string)$r['record_ref']) ?><div class="text-muted"><?= h((string)$r['username']) ?><?= $r['reason'] ? ', ' . h((string)$r['reason']) : '' ?></div></td><td class="num small"><?= $r['amount'] !== null ? cm((string)$r['amount']) : '' ?></td></tr><?php endforeach; ?>
      <?php if (!$runs): ?><tr><td class="text-muted">None yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
</div>
<?php
layout_end();
