<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.products';
$edit = gint('id') ? DB::row('SELECT * FROM loan_products WHERE id = :id', ['id' => gint('id')]) : null;
$isNew = g('a') === 'new';

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $num = fn(string $k, int $scale = 2) => Dec::fix(Dec::of(p($k)), $scale);
    $opt = fn(string $k) => trim((string)p($k)) === '' ? null : Dec::fix(Dec::of(p($k)), 4);
    $d = [
        'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 10), 'name' => mb_substr(trim((string)p('name')), 0, 80), 'loan_type' => mb_substr(trim((string)p('loan_type')), 0, 40) ?: null,
        'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null, 'receivable_account_id' => pint('receivable_account_id'),
        'passbook_type_id' => pint('passbook_type_id'), 'mode_of_payment' => array_key_exists(p('mode_of_payment'), Coop::MODES) ? p('mode_of_payment') : 'monthly',
        'max_loans' => max(1, (int)p('max_loans', '1')), 'term_unit' => p('term_unit') === 'days' ? 'days' : 'months', 'term_min' => $num('term_min'), 'term_max' => $num('term_max'),
        'amount_min' => $num('amount_min'), 'amount_max' => $num('amount_max'), 'charge_method' => array_key_exists(p('charge_method'), Loans::CHARGE_METHODS) ? p('charge_method') : 'deducted',
        'interest_method' => array_key_exists(p('interest_method'), Loans::INTEREST_METHODS) ? p('interest_method') : 'diminishing',
        'amort_type' => array_key_exists(p('amort_type'), Loans::AMORT_TYPES) ? p('amort_type') : 'equal_amortization', 'diminishing_by' => p('diminishing_by') === 'daily' ? 'daily' : 'same',
        'rate_default' => $num('rate_default', 4), 'rate_opt1' => $opt('rate_opt1'), 'rate_opt2' => $opt('rate_opt2'),
        'rate_opt3' => $opt('rate_opt3'), 'calendar_days' => p('calendar_days') === '360' ? 360 : 365, 'interest_account_id' => pint('interest_account_id'),
        'interest_posting' => p('interest_posting') === 'none' ? 'none' : 'monthly', 'accrued_int_account_id' => pint('accrued_int_account_id'),
        'penalty_grace_days' => max(0, (int)p('penalty_grace_days')), 'penalty_rate' => $num('penalty_rate', 4), 'penalty_base' => array_key_exists(p('penalty_base'), Loans::PENALTY_BASES) ? p('penalty_base') : 'arrears',
        'penalty_account_id' => pint('penalty_account_id'), 'req_member_months' => max(0, (int)p('req_member_months')), 'req_comakers' => max(0, (int)p('req_comakers')), 'req_ndi' => $num('req_ndi'),
        'req_segment_id' => pint('req_segment_id'), 'req_capital_mode' => p('req_capital_mode') === 'pct' ? 'pct' : 'min', 'req_capital_value' => $num('req_capital_value'),
        'req_savings_mode' => p('req_savings_mode') === 'pct' ? 'pct' : 'min', 'req_savings_value' => $num('req_savings_value'), 'req_age_min' => max(0, (int)p('req_age_min')), 'req_age_max' => max(0, (int)p('req_age_max')),
        'needs_ci' => p('needs_ci') === '1' ? 1 : 0, 'needs_collateral' => p('needs_collateral') === '1' ? 1 : 0,
        'sort_order' => (int)p('sort_order'), 'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    // the additional interest of a past-due loan (Internal Ledger), once the column is there (upgrade 2027_01_10)
    if (Loans::addlInterestReady()) {
        $d['addl_interest'] = array_key_exists((string)p('addl_interest'), Loans::ADDL_INTEREST) ? (string)p('addl_interest') : 'auto';
    }
    $reqs = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)p('requirements')))));
    $d['requirements_json'] = $reqs ? json_encode($reqs, JSON_UNESCAPED_UNICODE) : null;
    // a savings product prints with a savings passbook, a loan product with a loan passbook (Passbook Types)
    if ($d['passbook_type_id'] && DB::value('SELECT ledger FROM passbook_types WHERE id = :t', ['t' => $d['passbook_type_id']]) !== 'loan') {
        fail('Choose a loan passbook for the passbook type (Members & Others > Passbook Types).');
    }
    if ($d['code'] === '' || $d['name'] === '') {
        fail('Enter the code and name.');
    }
    if (DB::value('SELECT id FROM loan_products WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $edit['id'] ?? 0])) {
        fail('Product code ' . $d['code'] . ' is used.');
    }
    if (Dec::cmp($d['term_min'], $d['term_max']) > 0 || !Dec::isPos($d['term_max'])) {
        fail('Check the term range.');
    }
    if (Dec::isPos($d['amount_max']) && Dec::cmp($d['amount_min'], $d['amount_max']) > 0) {
        fail('Check the amount range.');
    }
    if (!Dec::isPos($d['rate_default']) && $d['interest_method'] !== 'straight') {
        // a zero-interest product is allowed but deliberate
        if (p('zero_rate_ok') !== '1') {
            fail('The default rate is zero. Tick "zero-interest product" if that is intended.');
        }
    }
    if ($edit && (int)$edit['receivable_account_id'] !== (int)$d['receivable_account_id'] && DB::value("SELECT id FROM loan_accounts WHERE product_id = :p AND status IN ('current','past_due','restructured') LIMIT 1", ['p' => $edit['id']])) {
        fail('Open loans of this product are in the current receivable account; they keep it. Make a new product for a different account.');
    }
    $charges = array_values(array_unique(array_map('intval', (array)($_POST['charges'] ?? []))));
    $rates = [];
    foreach (coop_grid_posted('rates') as $r) {
        if (trim((string)($r['rate'] ?? '')) === '') {
            continue;
        }
        if (!valid_date($r['effective_date'] ?? '')) {
            fail('Each term / amount rate needs its effective date.');
        }
        $rates[] = ['term_from' => Dec::fix(Dec::of($r['term_from'] ?? '0')), 'term_to' => Dec::fix(Dec::of($r['term_to'] ?? '0')), 'amount_from' => Dec::fix(Dec::of($r['amount_from'] ?? '0')),
            'amount_to' => Dec::fix(Dec::of($r['amount_to'] ?? '0')), 'effective_date' => $r['effective_date'], 'rate' => Dec::fix(Dec::of($r['rate']), 4), 'keep' => !empty($r['keep'])];
    }
    $id = DB::tx(function () use ($d, $edit, $charges, $rates, $pageKey) {
        if ($edit) {
            $d['updated_at'] = date('Y-m-d H:i:s');
            DB::update('loan_products', $d, 'id = :id', ['id' => $edit['id']]);
            $id = (int)$edit['id'];
        } else {
            $id = DB::insert('loan_products', $d);
        }
        $oldCharges = DB::column('SELECT charge_id FROM loan_product_charges WHERE product_id = :p ORDER BY sort_order', ['p' => $id]);
        DB::run('DELETE FROM loan_product_charges WHERE product_id = :p', ['p' => $id]);
        foreach ($charges as $i => $c) {
            DB::insert('loan_product_charges', ['product_id' => $id, 'charge_id' => $c, 'sort_order' => $i + 1]);
        }
        DB::run('DELETE FROM loan_product_rates WHERE product_id = :p AND effective_date >= :t', ['p' => $id, 't' => today()]);
        foreach ($rates as $r) {
            if ($r['keep']) {
                continue;
            }
            if ($r['effective_date'] < today()) {
                fail('A new rate takes effect today or later; rates already in effect are kept as history.');
            }
            unset($r['keep']);
            DB::insert('loan_product_rates', ['product_id' => $id] + $r);
        }
        Audit::log($edit ? 'edit' : 'create', 'loan', 'loan_product', $id, $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey, 'before' => $edit ? $edit + ['charges' => implode(',', $oldCharges)] : [],
            'after' => $d + ['charges' => implode(',', $charges), 'rates' => json_encode($rates)], 'changes' => $edit ? null : []]);
        return $id;
    });
    Loans::resetProducts();
    flash('success', 'Loan product saved. Loans already released keep their own terms.');
    redirect(url($pageKey, ['id' => $id]));
});

if (!$edit && !$isNew && !is_post()) {
    $rows = DB::all("SELECT p.*, (SELECT COUNT(*) FROM loan_accounts l WHERE l.product_id = p.id AND l.status IN ('current','past_due','restructured')) AS open_loans,
                            (SELECT COALESCE(SUM(l.principal_balance),0) FROM loan_accounts l WHERE l.product_id = p.id AND l.status IN ('current','past_due','restructured')) AS balance
                       FROM loan_products p ORDER BY p.sort_order, p.code");
    coop_layout('Loan Products', ['actions' => Access::can($pageKey, 'create') ? btn_link(url($pageKey, ['a' => 'new']), 'New loan product', 'btn-primary', 'plus-lg') : '']);
    ?>
    <div class="card"><table class="table table-sm table-hover"><thead><tr><th>Code</th><th>Product</th><th>Interest</th><th class="num">Rate</th><th>Payment</th><th>Term</th><th class="num">Amount</th><th class="num">Open loans</th><th class="num">Balance</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['code']) ?></td><td><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>"><?= h($r['name']) ?></a><div class="small text-muted"><?= h((string)$r['loan_type']) ?></div></td>
      <td class="small"><?= h(Loans::INTEREST_METHODS[$r['interest_method']] ?? '') ?><?= $r['interest_method'] === 'diminishing' ? ', ' . h(mb_strtolower(Loans::AMORT_TYPES[$r['amort_type']] ?? '')) : '' ?></td>
      <td class="num"><?= h(Dec::fix($r['rate_default'], 2)) ?> %</td><td class="small"><?= h(Coop::modeLabel($r['mode_of_payment'])) ?></td><td class="small"><?= h(Dec::fix($r['term_min'], 0) . '-' . Dec::fix($r['term_max'], 0) . ' ' . $r['term_unit']) ?></td>
      <td class="num small"><?= cm($r['amount_min']) ?> - <?= Dec::isPos($r['amount_max']) ? cm($r['amount_max']) : 'no limit' ?></td><td class="num"><?= (int)$r['open_loans'] ?></td><td class="num"><?= cm($r['balance']) ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="9" class="text-muted">No loan product yet.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php
    layout_end();
    exit;
}

$rec = $edit ?: ['mode_of_payment' => 'monthly', 'max_loans' => 1, 'term_unit' => 'months', 'term_min' => 1, 'term_max' => 12, 'charge_method' => 'deducted', 'interest_method' => 'diminishing',
    'amort_type' => 'equal_amortization', 'diminishing_by' => 'same', 'calendar_days' => 365, 'penalty_base' => 'arrears', 'is_active' => 1, 'patronage_eligible' => 1, 'interest_posting' => 'monthly'];
$assets = Accts::ofType(['Other Current Asset', 'Other Asset', 'Accounts Receivable']);
$income = Accts::ofType(['Income', 'Other Income']);
$allCharges = DB::all('SELECT * FROM loan_charges ORDER BY name');
$selCharges = is_post() ? array_map('intval', (array)($_POST['charges'] ?? [])) : ($edit ? array_map('intval', DB::column('SELECT charge_id FROM loan_product_charges WHERE product_id = :p ORDER BY sort_order', ['p' => $edit['id']])) : []);
$rateRows = $edit ? DB::all('SELECT * FROM loan_product_rates WHERE product_id = :p ORDER BY effective_date, term_from, amount_from', ['p' => $edit['id']]) : [];
$reqText = implode("\n", (array)json_decode((string)fv('requirements_json', $rec, '[]'), true));
if (is_post()) {
    $reqText = (string)p('requirements');
}
coop_layout($edit ? $edit['code'] . ' ' . $edit['name'] : 'New loan product', ['actions' => btn_link(url($pageKey), 'All products', 'btn-outline-secondary', 'arrow-left')]);
?>
<form method="post" data-once>
  <?= csrf_field() ?>
  <fieldset <?= Access::can($pageKey, $edit ? 'edit' : 'create') ? '' : 'disabled' ?>>
  <div class="row g-3">
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Product</div><div class="card-body row g-2">
      <?= input_field('code', 'Code', fv('code', $rec), ['class' => 'col-4 col-md-2', 'required' => true]) ?>
      <?= input_field('name', 'Name', fv('name', $rec), ['class' => 'col-8 col-md-6', 'required' => true]) ?>
      <?= input_field('loan_type', 'Loan type / group', fv('loan_type', $rec), ['class' => 'col-md-4', 'extra' => 'placeholder="Provident, Productive ..."']) ?>
      <?= input_field('description', 'Description', fv('description', $rec), ['class' => 'col-12']) ?>
      <?= select_field('receivable_account_id', 'Loans receivable account', $assets, fv('receivable_account_id', $rec), ['class' => 'col-md-6', 'blank' => 'Module default']) ?>
      <?= select_field('interest_account_id', 'Interest income account', $income, fv('interest_account_id', $rec), ['class' => 'col-md-6', 'blank' => 'Module default']) ?>
      <?= select_field('penalty_account_id', 'Penalty income account', $income, fv('penalty_account_id', $rec), ['class' => 'col-md-6', 'blank' => 'Module default']) ?>
      <?= select_field('passbook_type_id', 'Loan passbook', DB::pairs("SELECT id, name FROM passbook_types WHERE (is_active = 1 AND ledger = 'loan') OR id = :k ORDER BY name", ['k' => (int)($rec['passbook_type_id'] ?? 0)]), fv('passbook_type_id', $rec), ['class' => 'col-md-6', 'blank' => 'None']) ?>
      <?= input_field('sort_order', 'Order', fv('sort_order', $rec, '0'), ['class' => 'col-6 col-md-2', 'type' => 'number', 'step' => '1']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $rec, 1), 'col-6 col-md-4') ?>
    </div></div></div>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Amount, term and payment</div><div class="card-body row g-2" data-no-combo>
      <?= coop_money('amount_min', 'Smallest loan', fv('amount_min', $rec, '0'), ['class' => 'col-6 col-md-4']) ?>
      <?= coop_money('amount_max', 'Largest loan (0 = no limit)', fv('amount_max', $rec, '0'), ['class' => 'col-6 col-md-4']) ?>
      <?= input_field('max_loans', 'Open loans per member', fv('max_loans', $rec), ['class' => 'col-6 col-md-4', 'type' => 'number', 'step' => '1']) ?>
      <?= select_field('term_unit', 'Term in', ['months' => 'Months', 'days' => 'Days'], fv('term_unit', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= input_field('term_min', 'Shortest term', fv('term_min', $rec), ['class' => 'col-6 col-md-4', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('term_max', 'Longest term', fv('term_max', $rec), ['class' => 'col-6 col-md-4', 'extra' => 'inputmode="decimal"']) ?>
      <?= select_field('mode_of_payment', 'Mode of payment', Coop::modeOptions(), fv('mode_of_payment', $rec), ['class' => 'col-md-6']) ?>
      <?= select_field('charge_method', 'Charges are', Loans::CHARGE_METHODS, fv('charge_method', $rec), ['class' => 'col-md-6']) ?>
    </div></div></div>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Interest and penalty</div><div class="card-body row g-2" data-no-combo>
      <?= select_field('interest_method', 'Interest method', Loans::INTEREST_METHODS, fv('interest_method', $rec), ['class' => 'col-md-6', 'extra' => 'id="iMethod"']) ?>
      <div class="col-md-6" data-toggle-by="#iMethod" data-show-when="diminishing"><?= str_replace('col-md-4', '', select_field('amort_type', 'Amortization', Loans::AMORT_TYPES, fv('amort_type', $rec), ['class' => ''])) ?></div>
      <?= input_field('rate_default', 'Default rate (% a year)', fv('rate_default', $rec), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('rate_opt1', 'Option 1', fv('rate_opt1', $rec), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('rate_opt2', 'Option 2', fv('rate_opt2', $rec), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('rate_opt3', 'Option 3', fv('rate_opt3', $rec), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= select_field('diminishing_by', 'Interest period', ['same' => 'Same as the mode of payment', 'daily' => 'Actual days between amortizations'], fv('diminishing_by', $rec), ['class' => 'col-md-6']) ?>
      <?= select_field('calendar_days', 'Days in a year', ['365' => '365', '360' => '360'], (string)fv('calendar_days', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= select_field('interest_posting', 'Accrue interest monthly', ['monthly' => 'Yes', 'none' => 'No (income when collected)'], fv('interest_posting', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= input_field('penalty_rate', 'Penalty (% a year)', fv('penalty_rate', $rec, '0'), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('penalty_grace_days', 'Grace days', fv('penalty_grace_days', $rec, '0'), ['class' => 'col-6 col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= select_field('penalty_base', 'Penalty on', Loans::PENALTY_BASES, fv('penalty_base', $rec), ['class' => 'col-md-6']) ?>
      <?php if (Loans::addlInterestReady()): ?>
      <?= select_field('addl_interest', 'Additional interest when past due (Internal Ledger)', Loans::ADDL_INTEREST, fv('addl_interest', $rec, 'auto'), ['class' => 'col-12']) ?>
      <div class="col-12 small text-muted">Worked out per day at the loan's interest rate (days in a year above) and shown, with the penalty, on the Internal Ledger of the loan (Loan Accounts Center).</div>
      <?php endif; ?>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="zero_rate_ok" value="1" id="zr" <?= checked(p('zero_rate_ok') === '1') ?>><label class="form-check-label small" for="zr">Zero-interest product (allowed with a zero default rate)</label></div></div>
    </div></div></div>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header">Who may borrow</div><div class="card-body row g-2" data-no-combo>
      <?= input_field('req_member_months', 'Member for at least (months)', fv('req_member_months', $rec, '0'), ['class' => 'col-6 col-md-4', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('req_comakers', 'Co-makers required', fv('req_comakers', $rec, '0'), ['class' => 'col-6 col-md-4', 'type' => 'number', 'step' => '1']) ?>
      <?= coop_money('req_ndi', 'Net disposable income at least', fv('req_ndi', $rec, '0'), ['class' => 'col-md-4']) ?>
      <?= select_field('req_capital_mode', 'Paid-up capital rule', ['min' => 'At least the amount', 'pct' => 'At least % of the loan'], fv('req_capital_mode', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= input_field('req_capital_value', 'Amount or %', fv('req_capital_value', $rec, '0'), ['class' => 'col-6 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= select_field('req_savings_mode', 'Savings rule', ['min' => 'At least the amount', 'pct' => 'At least % of the loan'], fv('req_savings_mode', $rec), ['class' => 'col-6 col-md-4']) ?>
      <?= input_field('req_savings_value', 'Amount or %', fv('req_savings_value', $rec, '0'), ['class' => 'col-6 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('req_age_min', 'Age from', fv('req_age_min', $rec, '0'), ['class' => 'col-6 col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('req_age_max', 'Age to (0 = none)', fv('req_age_max', $rec, '0'), ['class' => 'col-6 col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= select_field('req_segment_id', 'Only members of the segmentation', Members::segments(), fv('req_segment_id', $rec), ['class' => 'col-md-6', 'blank' => 'Any']) ?>
      <?= check_field('needs_ci', 'Credit investigation before approval', fv('needs_ci', $rec, 0), 'col-md-6') ?>
      <?= check_field('needs_collateral', 'Collateral required', fv('needs_collateral', $rec, 0), 'col-md-6') ?>
      <div class="col-12"><label class="form-label">Documents to complete before release (one per line)</label><textarea class="form-control form-control-sm" name="requirements" rows="3"><?= h($reqText) ?></textarea></div>
    </div></div></div>
  </div>
  <div class="row g-3 mt-0">
    <div class="col-xl-5"><div class="card h-100"><div class="card-header">Charges on release</div><div class="card-body">
      <?php foreach ($allCharges as $c): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="charges[]" value="<?= (int)$c['id'] ?>" id="c<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $selCharges, true) ? 'checked' : '' ?>>
        <label class="form-check-label" for="c<?= (int)$c['id'] ?>"><?= h($c['code'] . ' ' . $c['name']) ?> <span class="small text-muted"><?= h(Loans::CHARGE_BY[$c['charge_by']] ?? '') ?><?= in_array($c['charge_by'], ['fixed_rate', 'fixed_amount'], true) ? ' ' . h(Dec::fix($c['value'], 2)) : '' ?></span></label></div><?php endforeach; ?>
      <?php if (!$allCharges): ?><div class="text-muted small">No charge set up yet (Loans &gt; Loan Charges).</div><?php endif; ?>
    </div></div></div>
    <div class="col-xl-7"><div class="card h-100"><div class="card-header">Rates by term and amount, with effective dates</div><div class="card-body">
      <?php $gridRows = is_post() ? coop_grid_posted('rates') : array_map(fn($r) => $r + ['keep' => $r['effective_date'] < today() ? '1' : ''], $rateRows); ?>
      <div class="table-responsive"><table class="table table-sm" id="gLR" data-rows="gLRTpl"><thead><tr><th>Term from</th><th>Term to</th><th>Amount from</th><th>Amount to</th><th>Effective</th><th>Rate %</th><th></th></tr></thead><tbody>
      <?php foreach ($gridRows as $i => $r): $n = 'rates[e' . $i . ']'; if (!empty($r['keep'])): ?>
        <tr class="text-muted"><td><?= h(Dec::fix($r['term_from'], 0)) ?><input type="hidden" name="<?= $n ?>[keep]" value="1"><input type="hidden" name="<?= $n ?>[rate]" value="<?= h($r['rate']) ?>"><input type="hidden" name="<?= $n ?>[effective_date]" value="<?= h($r['effective_date']) ?>"></td>
          <td><?= Dec::isPos($r['term_to']) ? h(Dec::fix($r['term_to'], 0)) : 'any' ?></td><td><?= cm($r['amount_from']) ?></td><td><?= Dec::isPos($r['amount_to']) ? cm($r['amount_to']) : 'any' ?></td><td><?= h(fdate($r['effective_date'])) ?></td><td><?= h(Dec::fix($r['rate'], 4)) ?></td><td class="small">in effect</td></tr>
      <?php else: ?>
        <tr><?php foreach (['term_from', 'term_to', 'amount_from', 'amount_to'] as $f): ?><td><input class="form-control form-control-sm text-end" name="<?= $n ?>[<?= $f ?>]" value="<?= h((string)($r[$f] ?? '0')) ?>"></td><?php endforeach; ?>
          <td><input class="form-control form-control-sm" type="date" name="<?= $n ?>[effective_date]" value="<?= h((string)($r['effective_date'] ?? today())) ?>"></td><td><input class="form-control form-control-sm text-end" name="<?= $n ?>[rate]" value="<?= h((string)($r['rate'] ?? '')) ?>"></td>
          <td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr>
      <?php endif; endforeach; ?>
      </tbody></table></div>
      <template id="gLRTpl"><tr><?php foreach (['term_from', 'term_to', 'amount_from', 'amount_to'] as $f): ?><td><input class="form-control form-control-sm text-end" name="rates[__i__][<?= $f ?>]" value="0"></td><?php endforeach; ?>
        <td><input class="form-control form-control-sm" type="date" name="rates[__i__][effective_date]" value="<?= h(today()) ?>"></td><td><input class="form-control form-control-sm text-end" name="rates[__i__][rate]"></td>
        <td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del aria-label="Remove"><i class="bi bi-x-lg"></i></button></td></tr></template>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-add-rows="gLR"><i class="bi bi-plus-lg"></i> Add a rate</button>
      <div class="small text-muted mt-2">Term in the product's unit; 0 = any. A matching row overrides the default rate for applications dated on or after its effective date.</div>
    </div></div></div>
  </div>
  </fieldset>
  <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?><div class="sticky-actions mt-3"><?= save_buttons('Save loan product', url($pageKey), false) ?></div><?php endif; ?>
</form>
<?php
layout_end();
