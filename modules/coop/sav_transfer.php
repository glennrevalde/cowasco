<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.transfer';
if (coop_voucher_actions($pageKey, ['coop_jv'])) {
    exit;
}
Access::require($pageKey, 'create');

handle_post(function () use ($pageKey) {
    coop_once();
    $from = Savings::byNo((string)p('from_no'));
    $to = Savings::byNo((string)p('to_no'));
    if (!$from || !$to) {
        fail('Enter both account numbers.');
    }
    $amount = Coop::amount('amount', true);
    $date = (string)p('txn_date', today());
    Coop::checkDate($date);
    $needs = '';
    if ((int)$from['member_id'] !== (int)$to['member_id']) {
        $needs = 'transfer to another member\'s account';
    }
    if ($from['product_type'] === 'share_capital') {
        $needs = $needs ?: 'transfer out of share capital';
    }
    if ((int)($from['withdrawal_approval'] ?? 0) === 1) {
        $needs = $needs ?: 'transfer out of ' . $from['product_name'] . ' (withdrawals need approval)';
    }
    if (Approval::exceeds(Access::limit('coop_max_withdrawal'), (float)$amount)) {
        $needs = $needs ?: 'transfer above your withdrawal limit';
    }
    $approver = Approval::check($pageKey, $needs !== '', $needs);
    $classId = Classes::required('class_id', 'the transfer');
    [$txnId] = CoopLedger::withClass($classId, fn() => Savings::transfer((int)$from['id'], (int)$to['id'], $amount, $date, trim((string)p('memo')), ['ref_no' => trim((string)p('ref_no')) ?: null]));
    if ($approver) {
        DB::update('transactions', ['approved_by' => $approver['id']], 'id = :id', ['id' => $txnId]);
    }
    flash('success', 'Transferred ' . Dec::money($amount) . ' from ' . $from['account_no'] . ' to ' . $to['account_no'] . '.');
    redirect(url($pageKey, ['a' => 'view', 'id' => $txnId]));
});

$recent = DB::all("SELECT t.* FROM transactions t WHERE t.txn_type = 'coop_jv' AND t.memo LIKE 'Transfer %' ORDER BY t.id DESC LIMIT 15");
coop_layout('Transfer Between Accounts');
?>
<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" class="card" data-once data-confirm="Transfer {amount} from {from_no} to {to_no}?" data-confirm-ok="Transfer"><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?>
      <div class="col-md-6"><label class="form-label">From account no.</label><input class="form-control form-control-sm" name="from_no" value="<?= h((string)(p('from_no') ?: g('from'))) ?>" required autocomplete="off" data-confirm-value="from_no" data-acct-lookup="#fromInfo"><div class="small text-muted mt-1" id="fromInfo"></div></div>
      <div class="col-md-6"><label class="form-label">To account no.</label><input class="form-control form-control-sm" name="to_no" value="<?= h((string)p('to_no')) ?>" required autocomplete="off" data-confirm-value="to_no" data-acct-lookup="#toInfo"><div class="small text-muted mt-1" id="toInfo"></div></div>
      <?= input_field('txn_date', 'Date', fv('txn_date', null, today()), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
      <div class="col-6 col-md-3"><label class="form-label">Amount</label><input class="form-control form-control-sm text-end" name="amount" value="<?= h((string)p('amount')) ?>" inputmode="decimal" data-money required data-confirm-value="amount"></div>
      <?= input_field('ref_no', 'Reference', fv('ref_no', null), ['class' => 'col-md-6']) ?>
      <?= Classes::requiredField(fv('class_id', null), 'col-md-6') ?>
      <?= input_field('memo', 'Memo', fv('memo', null), ['class' => 'col-md-6']) ?>
      <div class="col-12"><?= Approval::fields() ?></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Transfer</button></div>
      <div class="col-12 small text-muted">A transfer is one journal voucher: the general ledger moves between the two products' accounts under each member's name. Transfers to another member, out of share capital and above your limit need a supervisor.</div>
    </div></form>
  </div>
  <div class="col-xl-5"><div class="card"><div class="card-header">Recent transfers</div><table class="table table-sm"><tbody>
    <?php foreach ($recent as $t): ?><tr class="<?= (int)$t['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(fdate($t['txn_date'])) ?></td><td><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $t['id']])) ?>"><?= h($t['txn_no']) ?></a></td><td class="small"><?= h(mb_strimwidth((string)$t['memo'], 0, 60, '...')) ?></td><td class="num"><?= cm($t['total']) ?></td></tr><?php endforeach; ?>
    <?php if (!$recent): ?><tr><td class="text-muted">None yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php
layout_end();
