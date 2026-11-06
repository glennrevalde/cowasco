<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'teller.disburse';
if (coop_voucher_actions($pageKey, ['coop_cv'])) {
    exit;
}
if (g('a') === 'list') {
    coop_voucher_list($pageKey, ['coop_cv'], 'Member Disbursements', ['actions' => page_link($pageKey, [], 'New disbursement', 'btn-primary', 'plus-lg', 'create')]);
    exit;
}
Access::require($pageKey, 'create');

handle_post(function () use ($pageKey) {
    coop_once();
    $memberId = (int)pint('member_id');
    if (!$memberId) {
        fail('Choose the member.');
    }
    $items = [];
    foreach ((array)($_POST['items'] ?? []) as $it) {
        if (is_array($it) && ($it['kind'] ?? '') === 'savings' && trim((string)($it['amount'] ?? '')) !== '') {
            $items[] = ['kind' => 'savings', 'account_id' => (int)$it['account_id'], 'amount' => Dec::of($it['amount']), 'description' => 'Withdrawal', 'closing' => p('closing') === '1'];
        }
    }
    foreach ((array)($_POST['dist'] ?? []) as $lineId) {
        $l = DB::row("SELECT bl.*, b.kind, b.batch_no, b.credit_mode, b.status FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id WHERE bl.id = :id AND bl.member_id = :m", ['id' => (int)$lineId, 'm' => $memberId]);
        if (!$l || $l['status'] !== 'posted' || $l['credit_mode'] !== 'payable' || ($l['paid_txn_id'] && $l['paid_txn_id'] !== DB::value('SELECT txn_id FROM dist_batches WHERE id = :b', ['b' => $l['batch_id']]))) {
            fail('A distribution line is not payable any more. Refresh the page.');
        }
        $items[] = ['kind' => 'payable', 'account_id' => Accts::get($l['kind'] === 'isc' ? 'isc_payable' : 'pr_payable'), 'amount' => $l['net_amount'],
            'description' => ($l['kind'] === 'isc' ? 'Interest on share capital ' : 'Patronage refund ') . $l['batch_no'], 'batch_line_id' => (int)$l['id']];
    }
    foreach (coop_grid_posted('payables') as $f) {
        if (trim((string)($f['amount'] ?? '')) !== '') {
            $items[] = ['kind' => 'payable', 'account_id' => (int)($f['account_id'] ?? 0), 'amount' => Dec::of($f['amount']), 'description' => trim((string)($f['description'] ?? '')) ?: 'Payment to member'];
        }
    }
    $mode = p('mode') === 'cheque' ? 'cheque' : 'cash';
    $classId = Classes::required('class_id', 'the disbursement');
    $r = CoopLedger::withClass($classId, fn() => Teller::disburse($memberId, (string)p('txn_date', today()), $items, ['mode' => $mode, 'cash_account_id' => $mode === 'cheque' ? pint('bank_account_id') : null, 'cheque_no' => trim((string)p('cheque_no')), 'bank' => ''],
        ['voucher_no' => trim((string)p('voucher_no')), 'memo' => trim((string)p('memo')), 'received_by' => trim((string)p('received_by'))], $pageKey));
    flash('success', 'Disbursement ' . $r['receipt_no'] . ' posted: ' . Dec::money($r['total']) . '.');
    redirect(url($pageKey, ['a' => 'view', 'id' => $r['txn_id']]));
});

// the paid line of a payable batch carries the batch voucher itself until it is paid out
$m = coop_member_param() ?: (pint('member_id') ? Coop::member((int)pint('member_id')) : null);
$dist = $m ? DB::all("SELECT bl.*, b.kind, b.batch_no, b.date_to FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id
                       WHERE bl.member_id = :m AND b.status = 'posted' AND b.credit_mode = 'payable' AND bl.is_excluded = 0 AND bl.net_amount > 0 AND (bl.paid_txn_id IS NULL OR bl.paid_txn_id = b.txn_id)", ['m' => $m['id']]) : [];
$shift = Teller::openShift();
$posted = [];
foreach ((array)($_POST['items'] ?? []) as $i => $it) {
    if (is_array($it) && isset($it['amount'])) {
        $posted['items.' . $i . '.amount'] = (string)$it['amount'];
    }
}
coop_layout('Member Disbursement', ['actions' => btn_link(url($pageKey, ['a' => 'list']), 'Disbursements', 'btn-outline-secondary', 'list-ul')]);
if (!$shift && Coop::setting('or_require_shift', '1') === '1'): ?>
  <div class="alert alert-warning">Your teller shift is not open. Open it on <?= safe_link('teller.shift', [], 'Teller Shift & Cash Count') ?> first.</div>
<?php endif; ?>
<?php if ($m && in_array($m['status'], ['resigned', 'terminated', 'deceased'], true)): ?>
  <div class="alert alert-warning"><?= h($m['full_name']) ?> is <?= h(mb_strtolower(Coop::MEMBER_STATUSES[$m['status']])) ?>. This is the settlement of the member's accounts: it is paid in full (the minimum balance too) and needs a supervisor's approval<?= $m['status'] === 'deceased' ? '; enter the beneficiary or the estate in Received by' : '' ?>. Apply the deposits to open loans first on <?= safe_link('loan.collection', [], 'Loan Collection') ?> (paid by the member's savings).</div>
<?php endif; ?>
<form method="post" id="cvForm" data-once data-confirm="Pay out {total}? The withdrawal is posted to the general ledger and the member ledgers at once." data-confirm-title="Post the disbursement" data-confirm-ok="Post disbursement">
  <?= csrf_field() ?><?= Coop::onceField() ?>
  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card mb-3"><div class="card-body row g-2">
        <?= member_pick('member_id', $m ? (int)$m['id'] : null, ['class' => 'col-md-7', 'required' => true, 'href' => url($pageKey) . '&member={id}']) ?>
        <?= input_field('txn_date', 'Date', fv('txn_date', null, today()), ['class' => 'col-6 col-md-2', 'type' => 'date', 'required' => true]) ?>
        <?= input_field('voucher_no', 'Voucher no.', fv('voucher_no', null), ['class' => 'col-6 col-md-3', 'extra' => 'placeholder="next: ' . h(Seq::peek('coop_cv')) . '"']) ?>
      </div></div>
      <div id="memberCard" class="mb-3"></div>
      <div class="card mb-3"><div class="card-header">Withdrawals</div>
        <div id="memberItems" data-items-api="<?= h(url('api.coop', ['a' => 'member'])) ?>" data-mode="cv" data-posted="<?= h(json_encode($posted)) ?>"></div>
        <div class="card-body pt-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="closing" value="1" id="closing"><label class="form-check-label small" for="closing">Closing withdrawal (the minimum balance may be taken out)</label></div></div>
      </div>
      <?php if ($dist): ?>
      <div class="card mb-3"><div class="card-header">Interest on share capital and patronage refund to pay</div><table class="table table-sm"><tbody>
        <?php foreach ($dist as $dl): ?><tr><td><label><input class="form-check-input" type="checkbox" name="dist[]" value="<?= (int)$dl['id'] ?>" data-fill="#d<?= (int)$dl['id'] ?>" data-amount="<?= h($dl['net_amount']) ?>"> <?= $dl['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund' ?> <?= h($dl['batch_no']) ?></label></td>
          <td><?= h(substr($dl['date_to'], 0, 4)) ?></td><td class="num"><?= cm($dl['net_amount']) ?><input type="hidden" id="d<?= (int)$dl['id'] ?>" data-or-amount></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
      <div class="card mb-3"><div class="card-header">Other payables to the member</div><div class="card-body">
        <?= coop_grid('gPayables', 'payables', ['account_id' => ['Account', 'select', Accts::ofType(['Other Current Liability', 'Accounts Payable'])], 'description' => ['Description', 'text'], 'amount' => ['Amount', 'money', [], 'data-fee-amount']], coop_grid_posted('payables'), 0, 'Add a payable') ?>
      </div></div>
    </div>
    <div class="col-xl-4">
      <div class="card mb-3"><div class="card-header">Payment</div><div class="card-body row g-2">
        <?= select_field('mode', 'Paid by', ['cash' => 'Cash from the drawer', 'cheque' => 'Cheque'], fv('mode', null, 'cash'), ['class' => 'col-12', 'extra' => 'id="cvMode" data-no-combo']) ?>
        <div class="col-12 row g-2 m-0 p-0" data-toggle-by="#cvMode" data-show-when="cheque">
          <?= select_field('bank_account_id', 'Bank account', Accts::banks(), fv('bank_account_id', null), ['class' => 'col-12', 'blank' => '']) ?>
          <?= input_field('cheque_no', 'Cheque no.', fv('cheque_no', null), ['class' => 'col-12']) ?>
        </div>
        <?= input_field('received_by', 'Received by (when not the member)', fv('received_by', null), ['class' => 'col-12', 'extra' => 'maxlength="120" placeholder="e.g. the beneficiary of a deceased member"']) ?>
        <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
        <?= input_field('memo', 'Memo', fv('memo', null), ['class' => 'col-12']) ?>
      </div></div>
      <div class="or-total mb-3" style="grid-template-columns:1fr"><div><span>Total to pay</span><strong data-total-of="[data-or-amount], [data-fee-amount]" id="cvTotal">0.00</strong><input type="hidden" data-confirm-value="total" id="cvTotalCopy"></div></div>
      <?= Approval::fields() ?>
      <button class="btn btn-primary w-100 mt-2"><i class="bi bi-cash"></i> Post disbursement</button>
      <div class="small text-muted mt-2">Withdrawals above your limit, from dormant accounts, from products that need approval and share capital of an active member need a supervisor. So does every payment to a member who has resigned, been terminated or died (the settlement of their accounts; a deceased member's is paid to the person named in Received by).</div>
    </div>
  </div>
</form>
<script>
(function () {
  const form = document.getElementById('cvForm');
  form.addEventListener('submit', () => { document.getElementById('cvTotalCopy').value = document.getElementById('cvTotal').textContent + ' to ' + (form.querySelector('[data-member-pick] input[type=search]').value || 'the member'); }, true);
  form.querySelectorAll('input[name="dist[]"]').forEach((cb) => cb.addEventListener('change', () => {
    const t = document.querySelector(cb.dataset.fill); if (t) { t.value = cb.checked ? cb.dataset.amount : ''; window.CoopTotals && window.CoopTotals(); }
  }));
})();
</script>
<?php
layout_end();
