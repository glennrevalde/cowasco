<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.clearing';

handle_post(function () use ($pageKey) {
    Access::require($pageKey, 'create');
    coop_once();
    $date = (string)p('clear_date', today());
    if (p('action') === 'clear_or') {
        // checks received on official receipts for loan payments and fees
        $rids = array_map('intval', (array)($_POST['rids'] ?? []));
        if (!$rids) {
            fail('Tick the checks.');
        }
        $bank = (int)pint('or_bank_account_id');
        if (!$bank) {
            fail('Choose the bank account the checks were deposited to.');
        }
        DB::tx(function () use ($rids, $bank, $date) {
            foreach ($rids as $rid) {
                Teller::clearReceiptCheck($rid, $bank, $date);
            }
        });
        flash('success', count($rids) . ' check(s) cleared to the bank.');
        redirect(url($pageKey));
    }
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    if (!$ids) {
        fail('Tick the checks.');
    }
    if (p('action') === 'return') {
        $reason = trim((string)p('reason'));
        if ($reason === '') {
            fail('Enter the reason the check was returned (e.g. DAIF, account closed).');
        }
        DB::tx(function () use ($ids, $date, $reason, $pageKey) {
            foreach ($ids as $id) {
                // a transaction of the Savings Transaction Center (code RTC) with its own transaction number
                Savings::centerReady() ? SavTxn::returnCheck($id, $date, $reason, null, 'RTC', $pageKey) : Savings::returnCheck($id, $date, $reason);
            }
        });
        flash('warning', count($ids) . ' check(s) recorded as returned; the deposits were taken back out of the accounts.');
    } else {
        $bank = (int)pint('bank_account_id');
        if (!$bank) {
            fail('Choose the bank account the checks were deposited to.');
        }
        DB::tx(function () use ($ids, $bank, $date) {
            foreach ($ids as $id) {
                Savings::clearCheck($id, $bank, $date);
            }
        });
        flash('success', count($ids) . ' check(s) cleared; the amounts are now available to the members.');
    }
    redirect(url($pageKey));
});

$all = g('all') === '1';
$acctNo = trim((string)g('account'));
$rows = Savings::uncleared(today(), !$all && $acctNo === '');
if ($acctNo !== '') {
    // Clear Cheque on the Savings Account Transaction form: the checks of that account
    $rows = array_values(array_filter($rows, fn($r) => $r['account_no'] === $acctNo));
}
$waiting = count(Savings::uncleared(today(), false));
coop_layout('Check Clearing');
?>
<form method="post" data-once>
  <?= csrf_field() ?><?= Coop::onceField() ?>
  <div class="d-flex flex-wrap gap-2 mb-2 align-items-center no-print">
    <a class="btn btn-sm <?= $all ? 'btn-outline-secondary' : 'btn-primary' ?>" href="<?= h(url($pageKey)) ?>">Due to clear</a>
    <a class="btn btn-sm <?= $all ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= h(url($pageKey, ['all' => 1])) ?>">All uncleared (<?= $waiting ?>)</a>
    <?php if ($acctNo !== ''): ?><span class="small">Account <strong><?= h($acctNo) ?></strong>, every check still clearing. <a href="<?= h(url('sav.transact', ['account' => $acctNo])) ?>">Back to the transaction</a></span><?php endif; ?>
  </div>
  <div class="card mb-3"><table class="table table-sm table-hover"><thead><tr><th><input class="form-check-input" type="checkbox" data-check-all=".chk" aria-label="Tick all"></th><th>Deposited</th><th>Clears</th><th>Account</th><th>Member</th><th>Particulars</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><input class="form-check-input chk" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td><td><?= h(fdate($r['txn_date'])) ?></td>
    <td class="<?= $r['clearing_date'] <= today() ? 'fw-semibold' : '' ?>"><?= h(fdate($r['clearing_date'])) ?></td><td><?= safe_link('sav.accounts', ['id' => $r['account_id']], $r['account_no']) ?></td><td><?= h($r['full_name']) ?></td>
    <td class="small"><?= h($r['txn_code'] . ' ' . $r['description']) ?><?= !empty($r['trans_no']) ? ', transaction ' . h($r['trans_no']) : ($r['or_no'] ? ', OR ' . h($r['or_no']) : '') ?></td><td class="num"><?= cm($r['credit']) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No check <?= $all ? 'is waiting to clear' : 'is due to clear today' ?>.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($rows && Access::can($pageKey, 'create')): ?>
  <div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header">Cleared by the bank</div><div class="card-body row g-2 align-items-end">
      <?= select_field('bank_account_id', 'Deposited to', Accts::banks(), '', ['class' => 'col-md-6', 'blank' => '']) ?>
      <?= input_field('clear_date', 'Date cleared', today(), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
      <div class="col-6 col-md-3"><button class="btn btn-primary btn-sm w-100" name="action" value="clear" data-confirm="Mark the ticked checks as cleared?">Clear</button></div>
      <div class="col-12 small text-muted">Dr the bank, Cr checks and other cash items; the deposits become available.</div>
    </div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header">Returned by the bank</div><div class="card-body row g-2 align-items-end">
      <?= input_field('reason', 'Reason', '', ['class' => 'col-md-9', 'extra' => 'placeholder="Drawn against insufficient funds"']) ?>
      <div class="col-md-3"><button class="btn btn-outline-danger btn-sm w-100" name="action" value="return" data-confirm="Record the ticked checks as returned? The deposits are taken out of the members' accounts.">Returned</button></div>
    </div></div></div>
  </div>
  <?php endif; ?>
</form>
<?php $orChecks = Teller::checksToClear(); ?>
<form method="post" data-once class="mt-4">
  <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="clear_or">
  <div class="card mb-3"><div class="card-header">Checks received for loan payments and fees</div>
    <table class="table table-sm table-hover"><thead><tr><th><input class="form-check-input" type="checkbox" data-check-all=".orchk" aria-label="Tick all"></th><th>Received</th><th>OR no.</th><th>Member</th><th>Check</th><th class="num">Waiting to clear</th></tr></thead><tbody>
    <?php foreach ($orChecks as $r): $ch = (array)json_decode((string)$r['cheque_json'], true); ?><tr><td><input class="form-check-input orchk" type="checkbox" name="rids[]" value="<?= (int)$r['id'] ?>"></td><td><?= h(fdate($r['txn_date'])) ?></td>
      <td><a href="<?= h(url('teller.or', ['a' => 'view', 'id' => $r['txn_id']])) ?>"><?= h($r['receipt_no']) ?></a></td><td><?= h((string)$r['full_name']) ?></td>
      <td class="small"><?= h(implode('; ', array_map(fn($c) => trim(($c['cheque_no'] ?? '') . ' ' . ($c['bank'] ?? '')), $ch))) ?></td><td class="num"><?= cm($r['check_pending']) ?></td></tr><?php endforeach; ?>
    <?php if (!$orChecks): ?><tr><td colspan="6" class="text-muted">No check for loan payments or fees is waiting.</td></tr><?php endif; ?>
    </tbody></table>
    <?php if ($orChecks && Access::can($pageKey, 'create')): ?>
    <div class="card-body border-top row g-2 align-items-end">
      <?= select_field('or_bank_account_id', 'Deposited to', Accts::banks(), '', ['class' => 'col-md-5', 'blank' => '']) ?>
      <?= input_field('clear_date', 'Date cleared', today(), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
      <div class="col-6 col-md-2"><button class="btn btn-primary btn-sm w-100" data-confirm="Mark the ticked checks as cleared?">Clear</button></div>
      <div class="col-12 small text-muted">Dr the bank, Cr checks and other cash items. The loan payments were applied when the receipt was issued; a check the bank returns is handled by voiding its official receipt with the reason (the payment comes off the loan).</div>
    </div>
    <?php endif; ?>
  </div>
</form>
<?php
layout_end();
