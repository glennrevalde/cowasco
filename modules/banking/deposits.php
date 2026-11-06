<?php
declare(strict_types=1);

/**
 * Make Deposits, and Edit Deposit (bank.deposits&a=edit&id=N, from the Edit button of a deposit).
 *
 * A deposit takes to the bank: customer payments and sales receipts waiting in Undeposited Funds, the cash of
 * closed POS shifts (counted cash less the opening float plus the cash drops), card and e-wallet settlements
 * (less the provider's fee), and other receipts. Editing a deposit shows the same page with what the deposit
 * holds already ticked: a payment or a shift can be taken off it (it waits to be deposited again) or added,
 * and the date, account, slip number, memo, class, other receipts and cash back can be changed. Saving posts the
 * deposit again through Txn::save, so the closed-period and bank-reconciliation protections and the audit trail
 * (before / after, with the reason) apply. A shift's line always takes the shift's figures as they are now, so a
 * deposit follows a correction of the shift's closing entries (see Pos::correctShift).
 */

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'bank.deposits';
if (txn_common_actions($pageKey, ['deposit'])) {
    return;
}
$a = g('a', 'new');
if ($a === 'list') {
    render_txn_list($pageKey, ['deposit'], 'Deposits', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'Make deposit']);
    return;
}

$dep = null;
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $dep = Txn::load((int)g('id'));
    if (!$dep || $dep['txn_type'] !== 'deposit') {
        flash('warning', 'Deposit not found.');
        redirect(url($pageKey, ['a' => 'list']));
    }
    if ((int)$dep['is_voided'] === 1) {
        flash('warning', 'A voided deposit cannot be edited.');
        redirect(Txn::url($dep));
    }
} else {
    Access::require($pageKey, 'create');
}
$depId = $dep ? (int)$dep['id'] : 0;

// what the deposit holds now: payments (by the payment), POS shifts (by the shift), and every other line
$inPay = [];
$inShift = [];
$otherLines = [];
if ($dep) {
    $shiftIds = array_map('intval', DB::column('SELECT id FROM pos_shifts WHERE deposit_txn_id = :d', ['d' => $depId]));
    foreach (DB::all('SELECT * FROM transaction_lines WHERE txn_id = :t ORDER BY line_no, id', ['t' => $depId]) as $l) {
        $sid = (int)($l['pos_shift_id'] ?? 0);
        if (!$sid) {
            // a line written before deposits knew their shifts: "POS cash <terminal> <date> (shift #n)"
            foreach ($shiftIds as $cand) {
                if (str_ends_with((string)$l['description'], '(shift #' . $cand . ')')) {
                    $sid = $cand;
                    break;
                }
            }
        }
        if ($l['ref_txn_id']) {
            $inPay[(int)$l['ref_txn_id']] = $l;
        } elseif ($sid) {
            $inShift[$sid] = $l;
        } else {
            $otherLines[] = $l;
        }
    }
}

$uf = Accts::get('undeposited_funds');
$pending = DB::all(
    "SELECT t.*, COALESCE(c.name, t.payee_name) AS party, pm.name AS method
       FROM transactions t
       LEFT JOIN customers c ON c.id = t.customer_id
       LEFT JOIN payment_methods pm ON pm.id = t.payment_method_id
      WHERE t.txn_type IN ('payment','sales_receipt') AND t.is_voided = 0 AND t.is_pending = 0 AND t.account_id = :uf
        AND NOT EXISTS (SELECT 1 FROM transaction_lines l JOIN transactions d ON d.id = l.txn_id AND d.is_voided = 0 AND d.id <> :dep WHERE l.ref_txn_id = t.id)
      ORDER BY t.txn_date, t.id",
    ['uf' => $uf, 'dep' => $depId]
);
$shifts = DB::all(
    "SELECT s.*, t.name AS terminal, t.cash_account_id, cu.full_name AS corrected_name,
            (SELECT COALESCE(SUM(amount),0) FROM pos_cash_drops d WHERE d.shift_id = s.id) AS drops
       FROM pos_shifts s JOIN pos_terminals t ON t.id = s.terminal_id LEFT JOIN users cu ON cu.id = s.corrected_by
      WHERE s.status = 'closed' AND (s.deposit_txn_id IS NULL OR s.deposit_txn_id = :dep) ORDER BY s.business_date, s.id",
    ['dep' => $depId ?: -1]
);
foreach ($shifts as &$s) {
    $s['to_deposit'] = Pos::toDeposit($s);
}
unset($s);
$shifts = array_values(array_filter($shifts, fn($s) => $s['to_deposit'] > 0.004 || isset($inShift[(int)$s['id']])));
$clearing = DB::all(
    "SELECT a.id, a.code, a.name, COALESCE(SUM(j.debit - j.credit),0) AS bal FROM accounts a
       JOIN payment_methods pm ON pm.clearing_account_id = a.id AND pm.method_type IN ('card','ewallet')
       LEFT JOIN journal_lines j ON j.account_id = a.id
      GROUP BY a.id, a.code, a.name HAVING COALESCE(SUM(j.debit - j.credit),0) > 0.004 ORDER BY a.code"
);

handle_post(function () use ($pageKey, $pending, $shifts, $clearing, $uf, $dep, $depId, $inShift) {
    $bank = pint('account_id');
    $date = pdate('txn_date');
    if (!$bank || !$date) {
        fail('Choose the bank account and deposit date.');
    }
    $lines = [];
    $shiftIds = [];
    $pendingById = array_column($pending, null, 'id');
    foreach ((array)($_POST['pay'] ?? []) as $pid) {
        $t = $pendingById[(int)$pid] ?? null;
        if (!$t) {
            fail('A selected payment was already deposited or changed. Refresh and try again.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => $uf, 'amount' => (float)$t['total'], 'ref_txn_id' => $t['id'],
            'customer_id' => $t['customer_id'], 'description' => Txn::ref($t) . ($t['ref_no'] ? ' #' . $t['ref_no'] : '') . ' ' . ($t['method'] ?? '')];
    }
    $shiftById = array_column($shifts, null, 'id');
    foreach ((array)($_POST['shift'] ?? []) as $sid) {
        $s = $shiftById[(int)$sid] ?? null;
        if (!$s) {
            fail('A selected POS shift was already deposited.');
        }
        if ($s['to_deposit'] <= 0.004) {
            fail('The shift of ' . $s['terminal'] . ' on ' . fdate($s['business_date']) . ' has nothing left to deposit (counted cash less the float plus the drops). Take it off the deposit.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => (int)$s['cash_account_id'], 'amount' => $s['to_deposit'], 'pos_shift_id' => (int)$s['id'],
            'description' => 'POS cash ' . $s['terminal'] . ' ' . fdate($s['business_date']) . ' (shift #' . $s['id'] . ')'];
        $shiftIds[] = (int)$s['id'];
    }
    $clearById = array_column($clearing, null, 'id');
    foreach ((array)($_POST['settle'] ?? []) as $aid => $r) {
        $gross = r2(to_num($r['gross'] ?? 0));
        $fee = r2(to_num($r['fee'] ?? 0));
        if ($gross <= 0) {
            continue;
        }
        if (!isset($clearById[(int)$aid]) || $gross > (float)$clearById[(int)$aid]['bal'] + 0.004) {
            fail('Settlement for ' . ($clearById[(int)$aid]['name'] ?? 'a clearing account') . ' is more than its balance.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => (int)$aid, 'amount' => $gross, 'description' => 'Provider settlement ' . ($r['ref'] ?? '')];
        if ($fee > 0) {
            $lines[] = ['line_kind' => 'deposit', 'account_id' => Accts::get('merchant_fees'), 'amount' => -$fee, 'description' => 'Merchant fee deducted'];
        }
    }
    foreach ((array)($_POST['other'] ?? []) as $r) {
        $amt = r2(to_num($r['amount'] ?? 0));
        $acc = (int)($r['account_id'] ?? 0);
        if (abs($amt) < 0.005 && !$acc) {
            continue;
        }
        if (!$acc || abs($amt) < 0.005) {
            fail('Other receipts need both an account and an amount.');
        }
        $lines[] = ['line_kind' => 'deposit', 'account_id' => $acc, 'amount' => $amt, 'customer_id' => ((int)($r['customer_id'] ?? 0)) ?: null,
            'description' => trim(($r['from'] ?? '') . ' ' . ($r['memo'] ?? '')) ?: null];
    }
    if (!$lines) {
        fail($dep ? 'A deposit needs at least one line. To cancel the whole deposit, void it instead.' : 'Select the payments to deposit or enter other receipts.');
    }
    $cashBack = r2(pnum('cash_back_amount'));
    if ($cashBack < 0) {
        fail('The cash back cannot be less than zero.');
    }
    $sum = r2(array_sum(array_column($lines, 'amount')));
    if ($cashBack > 0 && $cashBack > $sum + 0.004) {
        fail('The cash back (' . money($cashBack) . ') is more than the items deposited (' . money($sum) . ').');
    }
    $h = [
        'txn_date' => $date, 'account_id' => $bank, 'memo' => p('memo') ?: null, 'ref_no' => p('ref_no') ?: null,
        'cash_back_amount' => $cashBack, 'account2_id' => $cashBack > 0 ? pint('cash_back_account_id') : null, 'status' => 'open',
    ];
    $id = DB::tx(function () use ($h, $lines, $depId, $shiftIds) {
        $id = Txn::save('deposit', $h, $lines, $depId ?: null, ['reason' => trim((string)p('reason')) ?: null]);
        // the shifts taken off the deposit wait to be deposited again; the ones on it point at it
        if ($depId) {
            [$in, $ip] = DB::in('ks', $shiftIds ?: [0]);
            DB::run("UPDATE pos_shifts SET deposit_txn_id = NULL WHERE deposit_txn_id = :d AND id NOT IN $in", ['d' => $depId] + $ip);
        }
        foreach ($shiftIds as $sid) {
            DB::update('pos_shifts', ['deposit_txn_id' => $id], 'id = :id', ['id' => $sid]);
        }
        return $id;
    });
    flash('success', $dep ? 'Deposit ' . $dep['txn_no'] . ' saved.' : 'Deposit recorded.');
    if ($dep) {
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    }
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

// what is ticked: what was posted, else what the deposit holds
$posted = is_post();
$payOn = $posted ? array_map('strval', (array)($_POST['pay'] ?? [])) : array_map('strval', array_keys($inPay));
$shiftOn = $posted ? array_map('strval', (array)($_POST['shift'] ?? [])) : array_map('strval', array_keys($inShift));
$others = $posted ? array_values((array)($_POST['other'] ?? []))
    : array_map(fn($l) => ['from' => '', 'memo' => (string)$l['description'], 'account_id' => $l['account_id'], 'amount' => number_format((float)$l['amount'], 2, '.', ''),
        'customer_id' => $l['customer_id']], $otherLines);
$otherRows = max(3, count($others) + 1);
$rec = $dep ?? [];

$title = $dep ? 'Edit Deposit ' . $dep['txn_no'] : 'Make Deposits';
layout_start($title, ['actions' => $dep ? btn_link(Txn::url($dep), 'Back to the deposit', 'btn-outline-secondary', 'arrow-left')
    : btn_link(url($pageKey, ['a' => 'list']), 'Deposits list', 'btn-outline-secondary', 'list-ul')]);
?>
<form method="post" class="dep-form"><?= csrf_field() ?>
<?php if ($dep): ?>
<div class="alert alert-info py-2 small d-flex gap-2"><i class="bi bi-info-circle"></i><div>What this deposit holds is ticked. Untick a payment or a shift to take it off the deposit (it waits to be deposited again), tick another to add it, or change the other receipts. A POS shift is always taken at its figures as they are now.</div></div>
<?php endif; ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= select_field('account_id', 'Deposit to', Accts::banks(), fv('account_id', $dep, DB::value("SELECT id FROM accounts WHERE code = '1010'")), ['class' => 'col-md-4']) ?>
  <?= input_field('txn_date', 'Date', fv('txn_date', $dep, today()), ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
  <?= input_field('ref_no', 'Deposit slip no.', fv('ref_no', $dep), ['class' => 'col-6 col-md-2']) ?>
  <?= input_field('memo', 'Memo', fv('memo', $dep, 'Deposit'), ['class' => Classes::ready() ? 'col-md-2' : 'col-md-4']) ?>
  <?= Classes::field(fv('class_id', $dep), 'col-md-2') ?>
  <?= BusinessUnits::field($dep, 'deposit', 'col-md-2') ?>
</div></div>
<div id="depSum" data-sum-into="#depTotal" data-sum-selector="input[data-sum]:not([disabled])">
<div class="card mb-3"><div class="card-header fw-semibold">Payments waiting to be deposited (Undeposited Funds)</div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table"><thead><tr><th><input type="checkbox" class="form-check-input" data-check-all=".dep-cb-pay" aria-label="Tick every payment"></th><th>Date</th><th>Type</th><th>No.</th><th>Received from</th><th>Method</th><th>Ref.</th><th class="num">Amount</th></tr></thead>
  <tbody>
  <?php foreach ($pending as $t): $chk = in_array((string)$t['id'], $payOn, true); ?>
    <tr class="<?= isset($inPay[(int)$t['id']]) ? 'dep-in' : '' ?>"><td><input type="checkbox" class="form-check-input dep-cb dep-cb-pay" name="pay[]" value="<?= (int)$t['id'] ?>" <?= $chk ? 'checked' : '' ?> aria-label="Deposit <?= h(Txn::ref($t)) ?>"></td>
      <td><?= h(fdate($t['txn_date'])) ?></td><td><?= h(Txn::label($t['txn_type'])) ?></td><td><?= Txn::link($t) ?><?= isset($inPay[(int)$t['id']]) ? '<div class="small text-muted">On this deposit</div>' : '' ?></td><td><?= h($t['party']) ?></td><td><?= h($t['method']) ?></td><td><?= h($t['ref_no']) ?></td>
      <td class="num"><?= money($t['total']) ?><input type="hidden" data-sum value="<?= h($t['total']) ?>" <?= $chk ? '' : 'disabled' ?>></td></tr>
  <?php endforeach; ?>
  <?php if (!$pending): ?><tr><td colspan="8" class="text-muted text-center">No payments waiting.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<?php if ($shifts): ?>
<div class="card mb-3"><div class="card-header dep-head"><span class="fw-semibold">POS cash from closed shifts</span>
  <span class="small text-muted">To deposit = cash counted less the opening float (it stays in the drawer) plus the cash drops.</span></div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table"><thead><tr><th></th><th>Business date</th><th>Terminal</th><th class="num">Counted</th><th class="num">Less float</th><th class="num">Plus drops</th><th class="num">To deposit</th></tr></thead>
  <tbody>
  <?php foreach ($shifts as $s): $chk = in_array((string)$s['id'], $shiftOn, true); $was = $inShift[(int)$s['id']] ?? null; ?>
    <tr class="<?= $was ? 'dep-in' : '' ?>"><td><input type="checkbox" class="form-check-input dep-cb" name="shift[]" value="<?= (int)$s['id'] ?>" <?= $chk ? 'checked' : '' ?> aria-label="Deposit the cash of shift <?= (int)$s['id'] ?>"></td>
      <td><a href="<?= h(url('pos.shifts', ['a' => 'shift', 'id' => $s['id']])) ?>"><?= h(fdate($s['business_date'])) ?></a></td><td><?= h($s['terminal']) ?>
        <?php if ($s['corrected_at']): ?><div class="small text-muted">Closing entries corrected <?= h(fdate($s['corrected_at'])) ?><?= $s['corrected_name'] ? ' by ' . h($s['corrected_name']) : '' ?></div><?php endif; ?></td>
      <td class="num"><?= money($s['counted_cash']) ?></td><td class="num"><?= money($s['opening_float']) ?></td><td class="num"><?= money($s['drops']) ?></td>
      <td class="num"><?= money($s['to_deposit']) ?>
        <?php if ($was && abs((float)$was['amount'] - $s['to_deposit']) > 0.004): ?><div class="small text-muted">was <?= money($was['amount']) ?></div><?php endif; ?>
        <input type="hidden" data-sum value="<?= h($s['to_deposit']) ?>" <?= $chk ? '' : 'disabled' ?>></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php if ($clearing): ?>
<div class="card mb-3"><div class="card-header fw-semibold">Card and e-wallet settlements</div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table"><thead><tr><th>Clearing account</th><th class="num">Unsettled</th><th class="num">Gross settled</th><th class="num">Fee deducted</th><th>Reference</th></tr></thead>
  <?php foreach ($clearing as $c): $sp = $_POST['settle'][$c['id']] ?? []; ?>
    <tr><td><?= h($c['code'] . ' - ' . $c['name']) ?></td><td class="num"><?= money($c['bal']) ?></td>
      <td><input class="form-control form-control-sm text-end ms-auto" style="width:120px" name="settle[<?= (int)$c['id'] ?>][gross]" value="<?= h($sp['gross'] ?? '') ?>" inputmode="decimal" data-sum aria-label="Gross settled"></td>
      <td><input class="form-control form-control-sm text-end ms-auto sub-fee" style="width:100px" name="settle[<?= (int)$c['id'] ?>][fee]" value="<?= h($sp['fee'] ?? '') ?>" inputmode="decimal" aria-label="Fee deducted"></td>
      <td><input class="form-control form-control-sm" name="settle[<?= (int)$c['id'] ?>][ref]" value="<?= h($sp['ref'] ?? '') ?>" aria-label="Reference"></td></tr>
  <?php endforeach; ?>
  </table></div>
  <div class="card-body small text-muted py-2">Merchant fees are recorded as Bank and Merchant Charges; the bank receives the gross less the fee.<?= $dep ? ' Settlements already on this deposit are listed under Other receipts.' : '' ?></div>
</div>
<?php endif; ?>
<div class="card mb-3"><div class="card-header fw-semibold">Other receipts (loan proceeds, owner contributions, interest…)</div>
  <div class="table-responsive"><table class="table table-sm mb-0 dep-table dep-other"><thead><tr><th>Received from</th><th>From account</th><th>Memo</th><th class="num">Amount</th></tr></thead>
  <?php for ($i = 0; $i < $otherRows; $i++): $o = $others[$i] ?? []; ?>
    <tr><td><input class="form-control form-control-sm" name="other[<?= $i ?>][from]" value="<?= h($o['from'] ?? '') ?>" aria-label="Received from">
        <input type="hidden" name="other[<?= $i ?>][customer_id]" value="<?= h((string)($o['customer_id'] ?? '')) ?>"></td>
      <td><select class="form-select form-select-sm" name="other[<?= $i ?>][account_id]" aria-label="From account"><?= options(Accts::all(), $o['account_id'] ?? '', '') ?></select></td>
      <td><input class="form-control form-control-sm" name="other[<?= $i ?>][memo]" value="<?= h($o['memo'] ?? '') ?>" aria-label="Memo"></td>
      <td><input class="form-control form-control-sm text-end ms-auto" style="width:120px" name="other[<?= $i ?>][amount]" data-sum inputmode="decimal" value="<?= h($o['amount'] ?? '') ?>" aria-label="Amount"></td></tr>
  <?php endfor; ?>
  </table></div>
</div>
</div>
<div class="card mb-3"><div class="card-body row g-2 align-items-end">
  <?= select_field('cash_back_account_id', 'Cash back goes to', Accts::banks(), fv('cash_back_account_id', null, ($dep['account2_id'] ?? null) ?: DB::value("SELECT id FROM accounts WHERE code = '1005'")), ['class' => 'col-md-4']) ?>
  <?= input_field('cash_back_amount', 'Cash back amount', fv('cash_back_amount', null, $dep && (float)$dep['cash_back_amount'] != 0.0 ? number_format((float)$dep['cash_back_amount'], 2, '.', '') : ''), ['class' => 'col-md-2', 'extra' => 'id="cashBack" inputmode="decimal"']) ?>
  <div class="col-md-6 text-md-end dep-totals">Items selected: <strong id="depTotal">0.00</strong><span class="dep-totals-sep"></span>Deposit total: <strong class="fs-5" id="depNet">0.00</strong></div>
  <?php if ($dep): ?><?= input_field('reason', 'Reason for the change', fv('reason'), ['class' => 'col-12', 'extra' => 'maxlength="255" placeholder="Recorded in the audit trail"']) ?><?php endif; ?>
</div></div>
<?php if ($dep): ?>
<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm" name="save" value="1"><i class="bi bi-check-lg"></i> Save changes</button>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h(Txn::url($dep)) ?>">Cancel</a></div>
<?php else: ?>
<?= posted_change_field($dep) ?>
<?= save_buttons('Save deposit', url($pageKey), Access::can($pageKey, 'create')) ?>
<?php endif; ?>
</form>
<script>
(function () {
  // a ticked payment or shift counts in the total; an unticked one does not
  document.querySelectorAll('.dep-form .dep-cb').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var h = cb.closest('tr').querySelector('input[data-sum]');
      if (h) { h.disabled = !cb.checked; }
      cb.closest('[data-sum-into]').dispatchEvent(new Event('change'));
    });
  });
  const upd = () => {
    let s = 0; document.querySelectorAll('#depSum input[data-sum]:not([disabled])').forEach(i => s += AppFmt.num(i.value));
    document.querySelectorAll('.sub-fee').forEach(i => s -= AppFmt.num(i.value));
    document.getElementById('depNet').textContent = AppFmt.fmt(s - AppFmt.num(document.getElementById('cashBack').value));
  };
  document.addEventListener('input', upd); document.addEventListener('change', upd); upd();
})();
</script>
<?php
layout_end();
