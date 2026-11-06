<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.products';
$edit = gint('id') ? DB::row('SELECT * FROM sav_products WHERE id = :id', ['id' => gint('id')]) : null;
$isNew = g('a') === 'new';

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $type = array_key_exists(p('product_type'), Savings::TYPES) ? p('product_type') : 'ordinary';
    $money = fn(string $k) => Dec::fix(Dec::of(p($k)));
    $d = [
        'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 10), 'name' => mb_substr(trim((string)p('name')), 0, 80), 'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
        'product_type' => $type, 'passbook_type_id' => pint('passbook_type_id'), 'liability_account_id' => pint('liability_account_id'), 'expense_account_id' => pint('expense_account_id'),
        'accrued_account_id' => pint('accrued_account_id'), 'opening_amount' => $money('opening_amount'), 'maintaining_balance' => $money('maintaining_balance'), 'min_balance' => $money('min_balance'),
        'max_balance' => $money('max_balance'), 'min_deposit' => $money('min_deposit'), 'min_withdrawal' => $money('min_withdrawal'),
        'maturity_value' => max(0, (int)p('maturity_value')), 'maturity_unit' => in_array(p('maturity_unit'), ['days', 'months', 'years'], true) ? p('maturity_unit') : 'days',
        'lockin_months' => max(0, (int)p('lockin_months')), 'preterm_rate' => trim((string)p('preterm_rate')) === '' ? null : Dec::fix(Dec::of(p('preterm_rate')), 4),
        'preterm_fee' => $money('preterm_fee'), 'preterm_fee_account_id' => pint('preterm_fee_account_id'), 'dormancy_months' => max(0, (int)p('dormancy_months')), 'dormancy_fee' => $money('dormancy_fee'),
        'dormancy_posting' => in_array(p('dormancy_posting'), ['monthly', 'quarterly', 'annual'], true) ? p('dormancy_posting') : 'quarterly', 'dormancy_fee_account_id' => pint('dormancy_fee_account_id'),
        'rate_after_maturity' => in_array(p('rate_after_maturity'), ['same', 'zero', 'fixed'], true) ? p('rate_after_maturity') : 'same', 'rate_after_value' => Dec::fix(Dec::of(p('rate_after_value')), 4),
        'interest_basis' => array_key_exists(p('interest_basis'), Savings::BASIS) ? p('interest_basis') : 'adb', 'interest_method' => p('interest_method') === 'compound' ? 'compound' : 'simple',
        'calendar_days' => p('calendar_days') === '360' ? 360 : 365, 'tax_rate' => Dec::fix(Dec::of(p('tax_rate')), 4), 'tax_account_id' => pint('tax_account_id'),
        'eligible_types' => implode(',', array_map('intval', (array)($_POST['eligible_types'] ?? []))) ?: null, 'allow_withdrawal' => p('allow_withdrawal') === '1' ? 1 : 0,
        'withdrawal_approval' => p('withdrawal_approval') === '1' ? 1 : 0, 'par_value' => $money('par_value'), 'share_class' => in_array(p('share_class'), ['common', 'preferred'], true) ? p('share_class') : null,
        'isc_eligible' => p('isc_eligible') === '1' ? 1 : 0, 'sort_order' => (int)p('sort_order'), 'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    // a savings product prints with a savings passbook, a loan product with a loan passbook (Passbook Types)
    if ($d['passbook_type_id'] && DB::value('SELECT ledger FROM passbook_types WHERE id = :t', ['t' => $d['passbook_type_id']]) !== 'savings') {
        fail('Choose a savings passbook for the passbook type (Members & Others > Passbook Types).');
    }
    if ($d['code'] === '' || $d['name'] === '') {
        fail('Enter the product code and name.');
    }
    if (DB::value('SELECT id FROM sav_products WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $edit['id'] ?? 0])) {
        fail('Product code ' . $d['code'] . ' is already used.');
    }
    if ($type === 'time_deposit' && $d['maturity_value'] <= 0) {
        fail('Enter the standard term of the time deposit.');
    }
    if (Dec::isPos($d['max_balance']) && Dec::cmp($d['min_balance'], $d['max_balance']) > 0) {
        fail('The minimum balance is above the maximum.');
    }
    if ($edit && $edit['product_type'] !== $type && DB::value('SELECT id FROM sav_accounts WHERE product_id = :p LIMIT 1', ['p' => $edit['id']])) {
        fail('The product already has accounts; its type cannot change.');
    }
    if ($edit && (int)$edit['liability_account_id'] !== (int)$d['liability_account_id'] && DB::value('SELECT id FROM sav_accounts WHERE product_id = :p AND balance <> 0 LIMIT 1', ['p' => $edit['id']])) {
        fail('Accounts of this product hold balances in the current liability account. Move the balances with a journal voucher first, or keep the account.');
    }
    // rate tiers: rows already in effect are history and stay as they are; future rows are replaced by the form
    $rates = [];
    foreach (coop_grid_posted('rates') as $r) {
        if (trim((string)($r['rate'] ?? '')) === '') {
            continue;
        }
        if (!valid_date($r['effective_date'] ?? '')) {
            fail('Each rate needs its effective date.');
        }
        if ($r['effective_date'] < today() && empty($r['keep'])) {
            fail('A new rate takes effect today or later (rates already in effect are kept as history). Rate ' . $r['rate'] . ' % dated ' . fdate($r['effective_date']) . '.');
        }
        $rates[] = ['amount_from' => Dec::fix(Dec::of($r['amount_from'] ?? '0')), 'amount_to' => trim((string)($r['amount_to'] ?? '')) === '' ? '999999999999.99' : Dec::fix(Dec::of($r['amount_to'])),
            'term_from' => max(0, (int)($r['term_from'] ?? 0)), 'term_to' => max(0, (int)($r['term_to'] ?? 0)), 'effective_date' => $r['effective_date'], 'rate' => Dec::fix(Dec::of($r['rate']), 4),
            'posting_method' => array_key_exists($r['posting_method'] ?? '', Savings::POSTING) ? $r['posting_method'] : 'quarterly', 'keep' => !empty($r['keep'])];
    }
    $id = DB::tx(function () use ($d, $edit, $rates, $pageKey) {
        if ($edit) {
            $d['updated_at'] = date('Y-m-d H:i:s');
            DB::update('sav_products', $d, 'id = :id', ['id' => $edit['id']]);
            $id = (int)$edit['id'];
        } else {
            $id = DB::insert('sav_products', $d);
        }
        $old = DB::all('SELECT * FROM sav_product_rates WHERE product_id = :p ORDER BY effective_date, amount_from', ['p' => $id]);
        DB::run('DELETE FROM sav_product_rates WHERE product_id = :p AND effective_date >= :t', ['p' => $id, 't' => today()]);
        foreach ($rates as $r) {
            if ($r['keep']) {
                continue;
            }
            unset($r['keep']);
            DB::insert('sav_product_rates', ['product_id' => $id] + $r);
        }
        $new = DB::all('SELECT * FROM sav_product_rates WHERE product_id = :p ORDER BY effective_date, amount_from', ['p' => $id]);
        Audit::log($edit ? 'edit' : 'create', 'sav', 'sav_product', $id, $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey, 'before' => $edit ? $edit + ['rates' => json_encode($old)] : [],
            'after' => $d + ['rates' => json_encode($new)], 'changes' => $edit ? null : []]);
        return $id;
    });
    Savings::resetProducts();
    flash('success', 'Product saved.');
    redirect(url($pageKey, ['id' => $id]));
});

if (!$edit && !$isNew && !is_post()) {
    $rows = DB::all("SELECT p.*, (SELECT COUNT(*) FROM sav_accounts a WHERE a.product_id = p.id AND a.status <> 'closed') AS accounts,
                            (SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a WHERE a.product_id = p.id) AS balance,
                            (SELECT r.rate FROM sav_product_rates r WHERE r.product_id = p.id AND r.effective_date <= CURDATE() ORDER BY r.effective_date DESC, r.amount_from LIMIT 1) AS base_rate
                       FROM sav_products p ORDER BY p.sort_order, p.code");
    coop_layout('Savings & Deposit Products', ['actions' => Access::can($pageKey, 'create') ? btn_link(url($pageKey, ['a' => 'new']), 'New product', 'btn-primary', 'plus-lg') : '']);
    ?>
    <div class="card"><table class="table table-sm table-hover"><thead><tr><th>Code</th><th>Product</th><th>Type</th><th>GL account</th><th class="num">Rate from</th><th class="num">Accounts</th><th class="num">Balance</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['code']) ?></td><td><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>"><?= h($r['name']) ?></a></td>
      <td><?= h(Savings::TYPES[$r['product_type']] ?? $r['product_type']) ?></td><td class="small"><?= h(Coop::accountLabel(Savings::liabilityAccount($r))) ?></td>
      <td class="num"><?= $r['base_rate'] !== null ? h(Dec::fix($r['base_rate'], 2)) . ' %' : '' ?></td><td class="num"><?= (int)$r['accounts'] ?></td><td class="num"><?= cm($r['balance']) ?></td><td><?= (int)$r['is_active'] ? '' : 'inactive' ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">No product yet. Add Regular Savings, Paid-up Share Capital and Time Deposit (codes as in your passbooks).</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php
    layout_end();
    exit;
}

$rec = $edit ?: ['product_type' => g('type') ?: 'ordinary', 'calendar_days' => 365, 'interest_basis' => 'adb', 'interest_method' => 'simple', 'allow_withdrawal' => 1, 'is_active' => 1, 'maturity_unit' => 'days',
    'dormancy_posting' => 'quarterly', 'rate_after_maturity' => 'same'];
$rateRows = $edit ? DB::all('SELECT * FROM sav_product_rates WHERE product_id = :p ORDER BY effective_date, amount_from, term_from', ['p' => $edit['id']]) : [];
$liab = Accts::ofType(['Other Current Liability', 'Long Term Liability', 'Equity']);
$exp = Accts::ofType(['Expense', 'Other Expense']);
$inc = Accts::ofType(['Income', 'Other Income']);
$pbTypes = DB::pairs("SELECT id, name FROM passbook_types WHERE (is_active = 1 AND ledger = 'savings') OR id = :k ORDER BY name", ['k' => (int)($rec['passbook_type_id'] ?? 0)]);
$elig = array_filter(explode(',', (string)fv('eligible_types', $rec, '')));
coop_layout($edit ? $edit['code'] . ' ' . $edit['name'] : 'New savings product', ['actions' => btn_link(url($pageKey), 'All products', 'btn-outline-secondary', 'arrow-left')]);
?>
<form method="post" data-once>
  <?= csrf_field() ?>
  <fieldset <?= Access::can($pageKey, $edit ? 'edit' : 'create') ? '' : 'disabled' ?>>
  <div class="row g-3">
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Product</div><div class="card-body row g-2">
      <?= input_field('code', 'Code', fv('code', $rec), ['class' => 'col-4 col-md-2', 'required' => true]) ?>
      <?= input_field('name', 'Name', fv('name', $rec), ['class' => 'col-8 col-md-6', 'required' => true]) ?>
      <?= select_field('product_type', 'Type', Savings::TYPES, fv('product_type', $rec), ['class' => 'col-md-4', 'extra' => 'id="pType" data-no-combo']) ?>
      <?= input_field('description', 'Description', fv('description', $rec), ['class' => 'col-12']) ?>
      <?= select_field('liability_account_id', 'GL account (deposit liability; share capital: equity)', $liab, fv('liability_account_id', $rec), ['class' => 'col-md-6', 'blank' => 'Module default for the type']) ?>
      <?= select_field('expense_account_id', 'Interest expense account', $exp, fv('expense_account_id', $rec), ['class' => 'col-md-6', 'blank' => 'Module default']) ?>
      <?= select_field('passbook_type_id', 'Passbook type', $pbTypes, fv('passbook_type_id', $rec), ['class' => 'col-md-6', 'blank' => 'No passbook']) ?>
      <div class="col-md-6"><label class="form-label">Open to member types (none ticked: all)</label>
        <div class="d-flex flex-wrap gap-2 pt-1"><?php foreach (Members::typeOptions(false) as $tid => $tn): ?><label class="small"><input class="form-check-input" type="checkbox" name="eligible_types[]" value="<?= (int)$tid ?>" <?= in_array((string)$tid, $elig, true) ? 'checked' : '' ?>> <?= h($tn) ?></label><?php endforeach; ?></div></div>
      <?= input_field('sort_order', 'Order on lists', fv('sort_order', $rec, '0'), ['class' => 'col-4 col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= check_field('is_active', 'Active (new accounts may be opened)', fv('is_active', $rec, 1), 'col-md-9') ?>
    </div></div></div>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Balances and transactions</div><div class="card-body row g-2">
      <?= coop_money('opening_amount', 'Opening / minimum placement', fv('opening_amount', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= coop_money('maintaining_balance', 'Maintaining balance (to earn interest)', fv('maintaining_balance', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= coop_money('min_balance', 'Minimum balance after a withdrawal', fv('min_balance', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= coop_money('max_balance', 'Maximum balance (0 = none)', fv('max_balance', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= coop_money('min_deposit', 'Minimum deposit', fv('min_deposit', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= coop_money('min_withdrawal', 'Minimum withdrawal', fv('min_withdrawal', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= check_field('allow_withdrawal', 'Withdrawals allowed', fv('allow_withdrawal', $rec, 1), 'col-md-4') ?>
      <?= check_field('withdrawal_approval', 'Every withdrawal needs a supervisor', fv('withdrawal_approval', $rec, 0), 'col-md-8') ?>
      <?= input_field('lockin_months', 'Lock-in after opening (months)', fv('lockin_months', $rec, '0'), ['class' => 'col-6 col-md-4', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('dormancy_months', 'Dormant after (months without a transaction)', fv('dormancy_months', $rec, '0'), ['class' => 'col-6 col-md-4', 'type' => 'number', 'step' => '1']) ?>
      <?= coop_money('dormancy_fee', 'Dormancy fee', fv('dormancy_fee', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= select_field('dormancy_posting', 'Fee charged', ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Yearly'], fv('dormancy_posting', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= select_field('dormancy_fee_account_id', 'Dormancy fee income', $inc, fv('dormancy_fee_account_id', $rec), ['class' => 'col-md-8', 'blank' => 'Module default']) ?>
    </div></div></div>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Interest</div><div class="card-body row g-2">
      <?= select_field('interest_basis', 'Computed on', Savings::BASIS, fv('interest_basis', $rec), ['class' => 'col-md-4']) ?>
      <?= select_field('interest_method', 'Method', ['simple' => 'Simple', 'compound' => 'Compounded'], fv('interest_method', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= select_field('calendar_days', 'Days in a year', ['365' => '365 days', '360' => '360 days'], (string)fv('calendar_days', $rec, '365'), ['class' => 'col-6 col-md-4']) ?>
      <?= input_field('tax_rate', 'Withholding tax on interest (%)', fv('tax_rate', $rec, '0'), ['class' => 'col-6 col-md-4', 'extra' => 'inputmode="decimal"']) ?>
      <?= select_field('tax_account_id', 'Withholding tax account', Accts::ofType(['Other Current Liability']), fv('tax_account_id', $rec), ['class' => 'col-md-8', 'blank' => 'Module default']) ?>
      <div class="col-12 small text-muted">Member deposits of a cooperative may be exempt from withholding tax under its tax exemption; leave 0 unless your certificate of tax exemption says otherwise.</div>
    </div></div></div>
    <div class="col-xl-6" data-toggle-by="#pType" data-show-when="time_deposit"><div class="card h-100"><div class="card-header">Time deposit terms</div><div class="card-body row g-2">
      <?= input_field('maturity_value', 'Standard term', fv('maturity_value', $rec, '0'), ['class' => 'col-6 col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= select_field('maturity_unit', 'Unit', ['days' => 'Days', 'months' => 'Months', 'years' => 'Years'], fv('maturity_unit', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= select_field('accrued_account_id', 'Accrued interest payable', $liab, fv('accrued_account_id', $rec), ['class' => 'col-md-6', 'blank' => 'Module default']) ?>
      <?= input_field('preterm_rate', 'Pre-termination rate (% a year; blank = not allowed without approval)', fv('preterm_rate', $rec), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal"']) ?>
      <?= coop_money('preterm_fee', 'Pre-termination fee', fv('preterm_fee', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= select_field('preterm_fee_account_id', 'Fee income', $inc, fv('preterm_fee_account_id', $rec), ['class' => 'col-6 col-md-3', 'blank' => 'Default']) ?>
      <?= select_field('rate_after_maturity', 'Days held after maturity earn', ['same' => 'The placement rate', 'zero' => 'Nothing', 'fixed' => 'A fixed rate'], fv('rate_after_maturity', $rec), ['class' => 'col-md-6']) ?>
      <?= input_field('rate_after_value', 'Fixed rate after maturity (%)', fv('rate_after_value', $rec, '0'), ['class' => 'col-md-6', 'extra' => 'inputmode="decimal"']) ?>
    </div></div></div>
    <div class="col-xl-6" data-toggle-by="#pType" data-show-when="share_capital"><div class="card h-100"><div class="card-header">Share capital</div><div class="card-body row g-2">
      <?= coop_money('par_value', 'Par value per share', fv('par_value', $rec, Coop::setting('default_par_value', '100')), ['class' => 'col-md-4']) ?>
      <?= select_field('share_class', 'Class of shares', ['common' => 'Common', 'preferred' => 'Preferred'], fv('share_class', $rec, 'common'), ['class' => 'col-md-4']) ?>
      <?= check_field('isc_eligible', 'Earns interest on share capital', fv('isc_eligible', $rec, 1), 'col-md-4') ?>
    </div></div></div>
  </div>
  <div class="card mt-3"><div class="card-header">Interest rates by balance (and by term for time deposits), with the date each takes effect</div><div class="card-body">
    <?php
    $gridRows = is_post() ? coop_grid_posted('rates') : array_map(fn($r) => $r + ['keep' => $r['effective_date'] < today() ? '1' : '', 'amount_to' => Dec::cmp($r['amount_to'], '999999999999') >= 0 ? '' : $r['amount_to']], $rateRows);
    $posting = Savings::POSTING;
    ?>
    <div class="table-responsive"><table class="table table-sm coop-grid" id="gRates" data-rows="gRatesTpl"><thead><tr><th>Balance from</th><th>Balance to</th><th>Term from (days)</th><th>Term to (days)</th><th>Effective</th><th>Rate % a year</th><th>Posted</th><th></th></tr></thead><tbody>
    <?php foreach ($gridRows as $i => $r): $locked = !empty($r['keep']); $n = 'rates[e' . $i . ']'; ?>
      <tr class="<?= $locked ? 'text-muted' : '' ?>">
        <?php if ($locked): ?>
          <td class="num"><?= cm($r['amount_from']) ?><input type="hidden" name="<?= $n ?>[keep]" value="1"><input type="hidden" name="<?= $n ?>[rate]" value="<?= h($r['rate']) ?>"><input type="hidden" name="<?= $n ?>[effective_date]" value="<?= h($r['effective_date']) ?>"></td>
          <td class="num"><?= $r['amount_to'] === '' ? 'and over' : cm($r['amount_to']) ?></td><td class="num"><?= (int)$r['term_from'] ?: '' ?></td><td class="num"><?= (int)$r['term_to'] ?: '' ?></td>
          <td><?= h(fdate($r['effective_date'])) ?></td><td class="num"><?= h(Dec::fix($r['rate'], 4)) ?></td><td><?= h($posting[$r['posting_method']] ?? '') ?></td><td class="small">in effect</td>
        <?php else: ?>
          <td><input class="form-control form-control-sm text-end" name="<?= $n ?>[amount_from]" value="<?= h((string)($r['amount_from'] ?? '0')) ?>" data-money></td>
          <td><input class="form-control form-control-sm text-end" name="<?= $n ?>[amount_to]" value="<?= h((string)($r['amount_to'] ?? '')) ?>" placeholder="and over" data-money></td>
          <td><input class="form-control form-control-sm text-end" type="number" name="<?= $n ?>[term_from]" value="<?= h((string)($r['term_from'] ?? '0')) ?>"></td>
          <td><input class="form-control form-control-sm text-end" type="number" name="<?= $n ?>[term_to]" value="<?= h((string)($r['term_to'] ?? '0')) ?>"></td>
          <td><input class="form-control form-control-sm" type="date" name="<?= $n ?>[effective_date]" value="<?= h((string)($r['effective_date'] ?? today())) ?>"></td>
          <td><input class="form-control form-control-sm text-end" name="<?= $n ?>[rate]" value="<?= h((string)($r['rate'] ?? '')) ?>" inputmode="decimal"></td>
          <td><select class="form-select form-select-sm" name="<?= $n ?>[posting_method]"><?= options($posting, $r['posting_method'] ?? 'quarterly') ?></select></td>
          <td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <template id="gRatesTpl"><tr>
      <td><input class="form-control form-control-sm text-end" name="rates[__i__][amount_from]" value="0" data-money></td>
      <td><input class="form-control form-control-sm text-end" name="rates[__i__][amount_to]" placeholder="and over" data-money></td>
      <td><input class="form-control form-control-sm text-end" type="number" name="rates[__i__][term_from]" value="0"></td>
      <td><input class="form-control form-control-sm text-end" type="number" name="rates[__i__][term_to]" value="0"></td>
      <td><input class="form-control form-control-sm" type="date" name="rates[__i__][effective_date]" value="<?= h(today()) ?>"></td>
      <td><input class="form-control form-control-sm text-end" name="rates[__i__][rate]" inputmode="decimal"></td>
      <td><select class="form-select form-select-sm" name="rates[__i__][posting_method]"><?= options($posting, 'quarterly') ?></select></td>
      <td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr></template>
    <button type="button" class="btn btn-outline-secondary btn-sm" data-add-rows="gRates"><i class="bi bi-plus-lg"></i> Add a rate</button>
    <div class="small text-muted mt-2">The tier whose balance range holds the account's balance (and, for time deposits, whose term range holds the placement's term) applies; on a given date the latest effective rate wins. A rate already in effect stays on file as history: to change it, add a row with the new rate and the date it starts. Placed time deposits keep the rate they were placed at.</div>
  </div></div>
  </fieldset>
  <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?><div class="sticky-actions mt-3"><?= save_buttons('Save product', url($pageKey), false) ?></div><?php endif; ?>
</form>
<?php
layout_end();
