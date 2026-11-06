<?php
declare(strict_types=1);

/**
 * Savings Transaction Code Setup: the transaction code (mnemonic) is the basis of the journal entry and of the
 * savings behavior. Opposite Account: a GL account of its own, or the User Account (the cash account of the teller
 * or of the teller's profile). Savings Entry Side: credit (into the member's account) or debit (out of it). A check
 * deposit floats for its clearing days; an error-correct code negates a transaction of its related code.
 */
require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.txn_codes';
$edit = gint('id') ? DB::row('SELECT * FROM sav_txn_codes WHERE id = :id', ['id' => gint('id')]) : null;
$hasMemo = Savings::centerReady();

handle_post(function () use ($edit, $pageKey, $hasMemo) {
    // the codes Close Account posts with (Savings Account Center): the closing fee, then the closing withdrawal
    if (p('action') === 'closing') {
        Access::require($pageKey, 'edit');
        $changes = [];
        foreach (['sav_closing_code' => ['CSW', 'closing withdrawal'], 'sav_closing_fee_code' => ['CF', 'closing fee']] as $k => [$def, $what]) {
            $code = strtoupper(trim((string)p($k)));
            $c = $code !== '' ? DB::row('SELECT * FROM sav_txn_codes WHERE code = :c', ['c' => $code]) : null;
            if (!$c || (int)$c['is_active'] !== 1) {
                fail('Choose an active code for the ' . $what . '.');
            }
            if ($c['entry_side'] !== 'debit' || (int)$c['is_error_correct'] === 1 || (int)$c['is_check_deposit'] === 1 || (int)($c['is_memo'] ?? 0) === 1) {
                fail('The ' . $what . ' code takes money out of the account: choose a debit code that is not an error-correct, check or memo code (' . $code . ' is not).');
            }
            if ($k === 'sav_closing_code' && $c['opposite_kind'] === 'gl' && !$c['opposite_account_id']) {
                fail('The closing withdrawal pays the balance out: choose a code with User Account (the teller\'s cash) or a GL account of its own.');
            }
            if ($k === 'sav_closing_fee_code' && $c['opposite_kind'] !== 'gl') {
                fail('The closing fee is income of the cooperative: choose a code with a GL account (blank: Other Cooperative Income).');
            }
            $old = Coop::setting($k, $def);
            if ($old !== $code) {
                Settings::set('coop_' . $k, $code);
                $changes[] = ['field' => $k, 'old' => $old, 'new' => $code];
            }
        }
        Audit::log('settings', 'sav', 'setting', null, 'Account closing codes', ['page_key' => $pageKey, 'changes' => $changes]);
        flash('success', 'The account closing codes were saved.');
        redirect(url($pageKey, $edit ? ['id' => $edit['id']] : []));
    }
    if (p('action') === 'delete') {
        Access::require($pageKey, 'delete');
        if (!$edit) {
            fail('Choose the code to delete.');
        }
        if ((int)$edit['is_system'] === 1) {
            fail('Code ' . $edit['code'] . ' is posted by the program itself and stays; switch it off instead.');
        }
        if ((int)DB::value('SELECT COUNT(*) FROM sav_txns WHERE txn_code = :c', ['c' => $edit['code']]) > 0) {
            fail('Code ' . $edit['code'] . ' is used by transactions, so it stays. Untick Active to stop using it.');
        }
        if (DB::value('SELECT code FROM sav_txn_codes WHERE related_code = :c AND id <> :id LIMIT 1', ['c' => $edit['code'], 'id' => $edit['id']])) {
            fail('Code ' . $edit['code'] . ' is the related code of an error-correct code. Change that code first.');
        }
        DB::run('DELETE FROM sav_txn_codes WHERE id = :id', ['id' => $edit['id']]);
        Audit::log('delete', 'sav', 'sav_txn_code', (int)$edit['id'], $edit['code'], ['page_key' => $pageKey, 'snapshot' => $edit]);
        flash('success', 'Code ' . $edit['code'] . ' deleted.');
        redirect(url($pageKey));
    }
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['description' => mb_substr(trim((string)p('description')), 0, 120), 'opposite_kind' => p('opposite_kind') === 'gl' ? 'gl' : 'user', 'opposite_account_id' => pint('opposite_account_id'),
        'entry_side' => p('entry_side') === 'debit' ? 'debit' : 'credit', 'is_check_deposit' => p('is_check_deposit') === '1' ? 1 : 0, 'clearing_days' => max(0, (int)p('clearing_days')),
        'is_error_correct' => p('is_error_correct') === '1' ? 1 : 0, 'related_code' => mb_substr(strtoupper(trim((string)p('related_code'))), 0, 10) ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if ($hasMemo) {
        $d['is_memo'] = p('is_memo') === '1' ? 1 : 0;
    }
    if (!$edit) {
        $d['code'] = mb_substr(strtoupper(trim((string)p('code'))), 0, 10);
        if (!preg_match('/^[A-Z0-9]{1,10}$/', $d['code'])) {
            fail('The transaction code is 1 to 10 letters or numbers.');
        }
        if (DB::value('SELECT id FROM sav_txn_codes WHERE code = :c', ['c' => $d['code']])) {
            fail('Code ' . $d['code'] . ' exists.');
        }
    }
    if ($d['description'] === '') {
        fail('Enter the description.');
    }
    if (!empty($d['is_memo'])) {
        // a memo takes the GL account chosen on each memo
        $d['opposite_kind'] = 'gl';
        $d['opposite_account_id'] = null;
    } elseif ($d['opposite_kind'] === 'gl' && !$d['opposite_account_id'] && !($edit && (int)$edit['is_system'] === 1)) {
        fail('Choose the GL account of the opposite side, or choose User Account.');
    }
    if ($d['opposite_kind'] === 'user') {
        $d['opposite_account_id'] = null;
    }
    if ($d['is_check_deposit'] && $d['clearing_days'] < 1) {
        fail('A check deposit code needs its clearing days.');
    }
    if (!$d['is_check_deposit']) {
        $d['clearing_days'] = 0;
    }
    if ($d['is_error_correct']) {
        if (!$d['related_code']) {
            fail('An error-correct code needs the related transaction code (the code it negates).');
        }
        $rel = DB::row('SELECT * FROM sav_txn_codes WHERE code = :c', ['c' => $d['related_code']]);
        if (!$rel) {
            fail('Related code ' . $d['related_code'] . ' is not set up.');
        }
        if ((int)$rel['is_error_correct'] === 1) {
            fail('The related code must be an ordinary code, not another error-correct code.');
        }
        if ($rel['entry_side'] !== $d['entry_side']) {
            fail('An error-correct code has the same Savings Entry Side as the code it negates (' . strtoupper($rel['entry_side']) . ' for ' . $rel['code'] . '): it is printed negative in the same column.');
        }
        $d['is_check_deposit'] = 0;
        $d['clearing_days'] = 0;
    } else {
        $d['related_code'] = null;
    }
    if ($edit && (int)$edit['is_system'] === 1) {
        // the codes the program posts itself keep their side and kind
        unset($d['entry_side'], $d['opposite_kind'], $d['is_check_deposit'], $d['is_error_correct'], $d['related_code']);
        if ($hasMemo) {
            unset($d['is_memo']);
        }
    }
    if ($edit) {
        DB::update('sav_txn_codes', $d, 'id = :id', ['id' => $edit['id']]);
        Audit::log('edit', 'sav', 'sav_txn_code', (int)$edit['id'], $edit['code'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
        $id = (int)$edit['id'];
    } else {
        $id = DB::insert('sav_txn_codes', $d);
        Audit::log('create', 'sav', 'sav_txn_code', $id, $d['code'], ['page_key' => $pageKey, 'snapshot' => $d]);
    }
    flash('success', 'Transaction code saved.');
    redirect(url($pageKey, ['id' => $id]));
});

$rows = DB::all('SELECT c.*, a.code AS acode, a.name AS aname, (SELECT COUNT(*) FROM sav_txns t WHERE t.txn_code = c.code) AS used FROM sav_txn_codes c LEFT JOIN accounts a ON a.id = c.opposite_account_id ORDER BY c.code');
$cur = $edit ?: ['code' => '', 'description' => '', 'opposite_kind' => 'user', 'opposite_account_id' => null, 'entry_side' => 'credit', 'is_check_deposit' => 0, 'clearing_days' => 0,
    'is_error_correct' => 0, 'related_code' => '', 'is_active' => 1, 'is_system' => 0, 'is_memo' => 0];
$sys = $edit && (int)$edit['is_system'] === 1;
$canSave = Access::can($pageKey, $edit ? 'edit' : 'create');
$v = fn(string $k) => fv($k, $cur);
coop_layout('Savings Transaction Code Setup', ['actions' => btn_link(url($pageKey), 'New code', 'btn-outline-primary', 'plus-lg')]);
?>
<div class="row g-3">
  <div class="col-xl-5">
    <form method="post" class="card" id="codeForm"><div class="card-body">
      <?= csrf_field() ?>
      <fieldset <?= $canSave ? '' : 'disabled' ?>>
      <div class="row g-2">
        <div class="col-5"><label class="form-label" for="cCode">Transaction Code</label>
          <input class="form-control form-control-sm fw-semibold" id="cCode" name="code" value="<?= h((string)$v('code')) ?>" <?= $edit ? 'readonly' : 'required autofocus' ?> maxlength="10" style="text-transform:uppercase"></div>
        <div class="col-7 d-flex align-items-end justify-content-end"><?= $sys ? '<span class="small text-muted">Posted by the program itself: its side and kind stay.</span>' : '' ?></div>
        <div class="col-12"><label class="form-label" for="cDesc">Description</label><input class="form-control form-control-sm" id="cDesc" name="description" value="<?= h((string)$v('description')) ?>" maxlength="120" required></div>
      </div>
      <fieldset class="sv-group mt-3" <?= $sys ? 'disabled' : '' ?>><legend>Opposite Account</legend>
        <div class="d-flex flex-wrap gap-4 mb-2">
          <div class="form-check"><input class="form-check-input" type="radio" name="opposite_kind" value="gl" id="oppGl" <?= $v('opposite_kind') === 'gl' ? 'checked' : '' ?>><label class="form-check-label" for="oppGl">GL Account</label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="opposite_kind" value="user" id="oppUser" <?= $v('opposite_kind') !== 'gl' ? 'checked' : '' ?>><label class="form-check-label" for="oppUser">User Account</label></div>
        </div>
        <select class="form-select form-select-sm" name="opposite_account_id" id="oppAcct" data-combo data-combo-placeholder="Type the GL account"><?= options(Accts::all(), $v('opposite_account_id'), '') ?></select>
        <div class="small text-muted mt-1" id="oppHelp">User Account: the cash account of the teller who transacts (User Accounts), else of the teller's profile (User Role Configuration).</div>
        <?php if ($hasMemo): ?><div class="form-check mt-2"><input type="hidden" name="is_memo" value="0"><input class="form-check-input" type="checkbox" name="is_memo" value="1" id="cMemo" <?= (int)$v('is_memo') ? 'checked' : '' ?>><label class="form-check-label" for="cMemo">Memo: the GL account is chosen on each debit / credit memo</label></div><?php endif; ?>
      </fieldset>
      <fieldset class="sv-group" <?= $sys ? 'disabled' : '' ?>><legend>Savings Entry Side</legend>
        <div class="d-flex flex-wrap gap-4">
          <div class="form-check"><input class="form-check-input" type="radio" name="entry_side" value="debit" id="sideD" <?= $v('entry_side') === 'debit' ? 'checked' : '' ?>><label class="form-check-label" for="sideD">Debit <span class="text-muted small">(withdrawal: out of the account)</span></label></div>
          <div class="form-check"><input class="form-check-input" type="radio" name="entry_side" value="credit" id="sideC" <?= $v('entry_side') !== 'debit' ? 'checked' : '' ?>><label class="form-check-label" for="sideC">Credit <span class="text-muted small">(deposit: into the account)</span></label></div>
        </div>
      </fieldset>
      <fieldset class="sv-group" <?= $sys ? 'disabled' : '' ?>>
        <div class="row g-2 align-items-center">
          <div class="col-6"><div class="form-check"><input type="hidden" name="is_check_deposit" value="0"><input class="form-check-input" type="checkbox" name="is_check_deposit" value="1" id="cChk" <?= (int)$v('is_check_deposit') ? 'checked' : '' ?>><label class="form-check-label" for="cChk">Check Deposit</label></div></div>
          <div class="col-6 d-flex align-items-center gap-2"><label class="form-label m-0 text-nowrap" for="cDays">Clearing Days</label><input class="form-control form-control-sm" type="number" min="0" step="1" id="cDays" name="clearing_days" value="<?= h((string)$v('clearing_days')) ?>"></div>
        </div>
      </fieldset>
      <fieldset class="sv-group" <?= $sys ? 'disabled' : '' ?>>
        <div class="row g-2 align-items-center">
          <div class="col-6"><div class="form-check"><input type="hidden" name="is_error_correct" value="0"><input class="form-check-input" type="checkbox" name="is_error_correct" value="1" id="cEc" <?= (int)$v('is_error_correct') ? 'checked' : '' ?>><label class="form-check-label" for="cEc">Is Error Correct</label></div></div>
          <div class="col-6 d-flex align-items-center gap-2"><label class="form-label m-0 text-nowrap" for="cRel">Related Trans Code</label><input class="form-control form-control-sm" id="cRel" name="related_code" value="<?= h((string)$v('related_code')) ?>" maxlength="10" style="text-transform:uppercase"></div>
          <div class="col-12 small text-muted">An error-correct code negates a transaction of its related code entered by mistake: the passbook prints it negative in the same column (same entry side).</div>
        </div>
      </fieldset>
      <div class="form-check mb-3"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="cAct" <?= (int)$v('is_active') ? 'checked' : '' ?>><label class="form-check-label" for="cAct">Active</label></div>
      </fieldset>
      <div class="d-flex flex-wrap gap-2 justify-content-between">
        <?php if ($edit && !$sys && Access::can($pageKey, 'delete') && !(int)DB::value('SELECT COUNT(*) FROM sav_txns WHERE txn_code = :c', ['c' => $edit['code']])): ?><button class="btn btn-outline-danger btn-sm" name="action" value="delete" formnovalidate data-confirm="Delete code <?= h($edit['code']) ?>? A code used by transactions stays."><i class="bi bi-trash3"></i> Delete</button><?php else: ?><span></span><?php endif; ?>
        <span class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey)) ?>"><i class="bi bi-eraser"></i> Clear</a>
          <?php if ($canSave): ?><button class="btn btn-primary btn-sm px-4" name="action" value="save"><i class="bi bi-check-lg"></i> Save</button><?php endif; ?></span>
      </div>
    </div></form>
    <?php $debitCodes = DB::pairs("SELECT code, CONCAT(code, ' - ', description) FROM sav_txn_codes WHERE entry_side = 'debit' AND is_active = 1 AND is_error_correct = 0 AND is_check_deposit = 0" . ($hasMemo ? ' AND is_memo = 0' : '') . ' ORDER BY code');
          $canSet = Access::can($pageKey, 'edit'); ?>
    <form method="post" class="card mt-3" id="closingForm"><div class="card-header">Account closing codes</div><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="closing">
      <p class="small text-muted mb-2">Close Account on the Savings Account Center charges the closing fee, then pays out the balance left, as transactions of the Savings Transaction Center with these codes.</p>
      <fieldset class="row g-2" <?= $canSet ? '' : 'disabled' ?>>
        <?= select_field('sav_closing_code', 'Closing withdrawal', $debitCodes, Coop::setting('sav_closing_code', 'CSW'), ['class' => 'col-sm-6', 'extra' => 'data-no-combo']) ?>
        <?= select_field('sav_closing_fee_code', 'Closing fee', $debitCodes, Coop::setting('sav_closing_fee_code', 'CF'), ['class' => 'col-sm-6', 'extra' => 'data-no-combo']) ?>
        <?php if ($canSet): ?><div class="col-12 text-end"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-check-lg"></i> Save the closing codes</button></div><?php endif; ?>
      </fieldset>
    </div></form>
  </div>
  <div class="col-xl-7">
    <div class="sv-grid-wrap" style="max-height:calc(100vh - 11rem)"><table class="table sv-grid"><thead><tr><th>Code</th><th>Description</th><th>Savings Entry</th><th class="d-none d-sm-table-cell">Opposite Account</th><th class="num d-none d-sm-table-cell">Used</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $sel = $edit && (int)$edit['id'] === (int)$r['id']; ?>
      <tr data-row class="<?= $sel ? 'is-selected' : '' ?><?= (int)$r['is_active'] ? '' : ' text-muted' ?>" tabindex="0" onclick="location.href='<?= h(url($pageKey, ['id' => $r['id']])) ?>'" onkeydown="if(event.key==='Enter'){location.href='<?= h(url($pageKey, ['id' => $r['id']])) ?>'}" style="cursor:pointer">
        <td class="fw-semibold"><?= h($r['code']) ?></td>
        <td class="wrap"><?= h($r['description']) ?><?= (int)$r['is_active'] ? '' : ' <span class="small">(inactive)</span>' ?><?= (int)$r['is_error_correct'] && $r['related_code'] ? ' <span class="small text-muted">negates ' . h($r['related_code']) . '</span>' : '' ?><?= (int)$r['is_check_deposit'] ? ' <span class="small text-muted">' . (int)$r['clearing_days'] . ' days clearing</span>' : '' ?></td>
        <td><?= $r['entry_side'] === 'credit' ? 'CREDIT' : 'DEBIT' ?></td>
        <td class="small d-none d-sm-table-cell"><?= (int)($r['is_memo'] ?? 0) ? 'chosen on the memo' : ($r['opposite_kind'] === 'gl' ? ($r['acode'] ? h($r['acode'] . ' ' . $r['aname']) : '<span class="text-muted">set by the process</span>') : '<span class="text-muted">0</span> User Account') ?></td>
        <td class="num d-none d-sm-table-cell"><?= (int)$r['used'] ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<script>
(function () {
  const f = document.getElementById('codeForm');
  if (!f) return;
  const gl = document.getElementById('oppGl'), user = document.getElementById('oppUser'), acct = document.getElementById('oppAcct');
  const memo = document.getElementById('cMemo'), chk = document.getElementById('cChk'), days = document.getElementById('cDays'), ec = document.getElementById('cEc'), rel = document.getElementById('cRel');
  const sync = () => {
    const isMemo = memo && memo.checked;
    if (isMemo) gl.checked = true;
    acct.disabled = !gl.checked || isMemo;
    // the GL Account box is the type-to-search box over the select: it follows the select (enabled with GL Account)
    if (typeof acct.comboSync === 'function') acct.comboSync();
    days.disabled = !chk.checked;
    rel.disabled = !ec.checked;
    if (ec.checked) { chk.checked = false; days.disabled = true; }
    chk.disabled = ec.checked || (f.querySelector('fieldset[disabled]') !== null && chk.closest('fieldset').disabled);
  };
  [gl, user, memo, chk, ec].forEach((x) => x && x.addEventListener('change', sync));
  sync();
  // the type-to-search box is built when the page has loaded: set it once it is there
  window.addEventListener('load', sync);
  // choosing GL Account puts the cursor in the GL account box
  gl.addEventListener('change', () => {
    const b = acct.previousElementSibling;
    if (gl.checked && b && b.tagName === 'INPUT' && !b.disabled) setTimeout(() => b.focus(), 0);
  });
})();
</script>
<?php
layout_end();
