<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'teller.or';
if (coop_voucher_actions($pageKey, ['coop_or'])) {
    exit;
}
if (g('a') === 'list') {
    coop_voucher_list($pageKey, ['coop_or'], 'Official Receipts', ['actions' => page_link($pageKey, [], 'New official receipt', 'btn-primary', 'plus-lg', 'create')]);
    exit;
}
Access::require($pageKey, 'create');

handle_post(function () use ($pageKey) {
    coop_once();
    $memberId = (int)pint('member_id');
    if (!$memberId) {
        fail('Choose the member.');
    }
    // savings, share capital and loan payments are received in their own modules (Savings Account Transaction,
    // Loan Collection); the official receipt takes the fees and other items
    $items = [];
    foreach (coop_grid_posted('fees') as $f) {
        if (trim((string)($f['amount'] ?? '')) === '') {
            continue;
        }
        $items[] = ['kind' => 'charge', 'account_id' => (int)($f['account_id'] ?? 0), 'amount' => Dec::of($f['amount']), 'description' => trim((string)($f['description'] ?? ''))];
    }
    $mode = in_array(p('mode'), ['cash', 'check', 'bank', 'savings'], true) ? p('mode') : 'cash';
    $pay = ['mode' => $mode, 'cash_received' => p('cash_received'), 'cash_account_id' => $mode === 'bank' ? pint('bank_account_id') : null,
        'cheques' => coop_grid_posted('cheques'), 'from_account_id' => pint('from_account_id')];
    $date = (string)p('txn_date', today());
    $classId = Classes::required('class_id', 'the official receipt');
    $r = CoopLedger::withClass($classId, fn() => Teller::receipt($memberId, $date, $items, $pay, ['receipt_no' => trim((string)p('receipt_no')), 'memo' => trim((string)p('memo')), 'back_entry' => $date < today()], $pageKey));
    flash('success', 'Official receipt ' . $r['receipt_no'] . ' posted: ' . Dec::money($r['total']) . (Dec::isPos($r['change']) ? '. Change: ' . Dec::money($r['change']) : '') . '.');
    redirect(url($pageKey, ['a' => 'view', 'id' => $r['txn_id']]));
});

$m = coop_member_param() ?: (pint('member_id') ? Coop::member((int)pint('member_id')) : null);
$shift = Teller::openShift();
// every GL account but those kept by a subsidiary ledger (savings, time deposits, loans receivable, sales receivables, inventory)
$feeAccounts = Teller::receiptAccounts();
$fees = is_post() ? coop_grid_posted('fees') : [];
if (!is_post() && $m && $m['status'] === 'applicant' && Dec::isPos(Coop::setting('membership_fee', '0'))
    && !DB::value("SELECT r.id FROM coop_receipt_lines r JOIN coop_receipts c ON c.id = r.receipt_id JOIN transactions t ON t.id = c.txn_id WHERE c.member_id = :m AND r.account_id = :a AND t.is_voided = 0", ['m' => $m['id'], 'a' => Accts::get('membership_fee_income')])) {
    $fees[] = ['account_id' => Accts::get('membership_fee_income'), 'description' => 'Membership fee', 'amount' => Coop::setting('membership_fee')];
}
coop_layout('Official Receipt', ['actions' => btn_link(url($pageKey, ['a' => 'list']), 'Receipts', 'btn-outline-secondary', 'list-ul') . ' ' . page_link('teller.shift', [], 'Teller shift', 'btn-outline-secondary', 'safe2')]);
if (!$shift && Coop::setting('or_require_shift', '1') === '1'): ?>
  <div class="alert alert-warning">Your teller shift is not open. Open it on <?= safe_link('teller.shift', [], 'Teller Shift & Cash Count') ?> with your beginning cash before receiving money.</div>
<?php elseif ($shift): ?>
  <div class="small text-muted mb-2">Shift <?= (int)$shift['id'] ?>, <?= h(fdate($shift['business_date'])) ?>, drawer <?= h($shift['cash_code'] . ' ' . $shift['cash_name']) ?>. Received so far: <?= cm($shift['receipts_total']) ?>.</div>
<?php endif; ?>
<form method="post" id="orForm" data-once data-confirm="Post official receipt for {total}? The general ledger and the member ledgers are updated at once." data-confirm-title="Post the official receipt" data-confirm-ok="Post receipt">
  <?= csrf_field() ?><?= Coop::onceField() ?>
  <div class="row g-3">
    <div class="col-xl-8">
      <div class="card mb-3"><div class="card-body row g-2">
        <?= member_pick('member_id', $m ? (int)$m['id'] : null, ['class' => 'col-md-7', 'required' => true, 'autofocus' => !$m]) ?>
        <?= input_field('txn_date', 'Date', fv('txn_date', null, today()), ['class' => 'col-6 col-md-2', 'type' => 'date', 'required' => true, 'extra' => (Access::special('coop_backdate') ? '' : 'max="' . today() . '" min="' . h(Coop::addDays(today(), -(int)Coop::setting('backdate_days', '0'))) . '"')]) ?>
        <?= input_field('receipt_no', 'OR no.', fv('receipt_no', null), ['class' => 'col-6 col-md-3', 'extra' => 'placeholder="next: ' . h(Seq::peek('coop_or')) . '" autocomplete="off"']) ?>
      </div></div>
      <div id="memberCard" class="mb-3"></div>
      <div id="memberItems" data-items-api="<?= h(url('api.coop', ['a' => 'member'])) ?>" data-mode="card"></div>
      <div class="alert alert-light border small d-flex gap-2 align-items-start mb-3"><i class="bi bi-info-circle mt-1"></i>
        <span>Savings deposits and share capital are received on <?= safe_link('sav.transact', [], 'Savings Account Transaction') ?>, loan payments on <?= safe_link('loan.collection', [], 'Loan Collection') ?>. The official receipt takes the fees and other items.</span></div>
      <div class="card mb-3"><div class="card-header">Fees and other items</div><div class="card-body">
        <?= coop_grid('gFees', 'fees', ['account_id' => ['Account', 'select', $feeAccounts], 'description' => ['Description', 'text'], 'amount' => ['Amount', 'money', [], 'data-fee-amount']], $fees, 0, 'Add a fee or other item') ?>
      </div></div>
    </div>
    <div class="col-xl-4">
      <div class="card mb-3"><div class="card-header">Payment</div><div class="card-body row g-2">
        <?= select_field('mode', 'Paid by', ['cash' => 'Cash', 'check' => 'Check', 'bank' => 'Bank deposit / transfer', 'savings' => 'Debit to the member\'s savings'], fv('mode', null, 'cash'), ['class' => 'col-12', 'extra' => 'id="payMode" data-no-combo']) ?>
        <div class="col-12" data-toggle-by="#payMode" data-show-when="check">
          <?= coop_grid('gCheques', 'cheques', ['cheque_no' => ['Check no.', 'text'], 'bank' => ['Bank', 'text'], 'date' => ['Date', 'date'], 'amount' => ['Amount', 'money']], coop_grid_posted('cheques'), 1, 'Add a check') ?>
          <div class="small text-muted mt-1">Checks wait in the checks clearing account until they are cleared on Check Clearing.</div>
        </div>
        <div class="col-12" data-toggle-by="#payMode" data-show-when="bank"><?= str_replace('col-md-4', '', select_field('bank_account_id', 'Bank account', Accts::banks(), fv('bank_account_id', null), ['class' => '', 'blank' => ''])) ?></div>
        <div class="col-12" data-toggle-by="#payMode" data-show-when="savings"><label class="form-label">From savings account (account no.)</label>
          <select class="form-select form-select-sm" name="from_account_id" id="fromAccount" data-no-combo><?php if ($m): foreach (DB::all("SELECT a.id, a.account_no, p.name, a.balance FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND a.status IN ('active','dormant') AND p.product_type IN ('ordinary','special')", ['m' => $m['id']]) as $sa): ?>
            <option value="<?= (int)$sa['id'] ?>"><?= h($sa['account_no'] . ' ' . $sa['name'] . ' (' . Dec::money($sa['balance']) . ')') ?></option><?php endforeach; endif; ?></select></div>
        <div class="col-12" data-toggle-by="#payMode" data-show-when="cash"><label class="form-label">Cash received</label><input class="form-control form-control-sm text-end" name="cash_received" id="cashReceived" value="<?= h((string)p('cash_received')) ?>" inputmode="decimal" data-money autocomplete="off"></div>
        <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
        <?= input_field('memo', 'Memo', fv('memo', null), ['class' => 'col-12']) ?>
      </div></div>
      <div class="or-total mb-3" style="grid-template-columns:1fr 1fr">
        <div><span>Total</span><strong data-total-of="[data-or-amount], [data-fee-amount]" id="orTotal">0.00</strong><input type="hidden" data-confirm-value="total" id="orTotalCopy"></div>
        <div><span>Change</span><strong data-change-of="#orTotal" data-received="#cashReceived"></strong></div>
      </div>
      <button class="btn btn-primary w-100"><i class="bi bi-receipt"></i> Post official receipt</button>
      <?= Approval::fields() ?>
    </div>
  </div>
</form>
<script>
(function () {
  // the confirmation shows the total and the member; the savings accounts for "debit to savings" follow the member
  const form = document.getElementById('orForm');
  form.addEventListener('submit', () => { document.getElementById('orTotalCopy').value = document.getElementById('orTotal').textContent + ' for ' + (form.querySelector('[data-member-pick] input[type=search]').value || 'the member'); }, true);
  document.addEventListener('member:picked', (e) => {
    if (!e.detail || e.detail.preset) return;
    fetch('<?= h(url('api.coop', ['a' => 'member'])) ?>&id=' + e.detail.id).then((r) => r.json()).then((d) => {
      const sel = document.getElementById('fromAccount');
      sel.innerHTML = (d.accounts || []).filter((a) => (a.type === 'ordinary' || a.type === 'special') && a.status !== 'closed')
        .map((a) => '<option value="' + a.id + '">' + a.no + ' ' + a.product + ' (' + window.CoopMoney.money(window.CoopMoney.cents(a.available)) + ' available)</option>').join('');
    });
  });
})();
</script>
<?php
layout_end();
