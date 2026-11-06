<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.charges';
$edit = gint('id') ? DB::row('SELECT * FROM loan_charges WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['name' => mb_substr(trim((string)p('name')), 0, 80), 'charge_type' => array_key_exists(p('charge_type'), Loans::CHARGE_TYPES) ? p('charge_type') : 'financial',
        'account_id' => pint('account_id'), 'sav_product_id' => pint('sav_product_id'), 'charge_by' => array_key_exists(p('charge_by'), Loans::CHARGE_BY) ? p('charge_by') : 'fixed_rate',
        'value' => Dec::fix(Dec::of(p('value')), 4), 'effective_date' => p('effective_date') ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if (!$edit) {
        $d['code'] = mb_substr(strtoupper(trim((string)p('code'))), 0, 10);
        if ($d['code'] === '' || DB::value('SELECT id FROM loan_charges WHERE code = :c', ['c' => $d['code']])) {
            fail('Enter a code that is not used yet.');
        }
    }
    if ($d['name'] === '') {
        fail('Enter the name.');
    }
    if ($d['charge_type'] === 'savings' && !$d['sav_product_id']) {
        fail('Choose the savings or share capital product the charge is credited to.');
    }
    if ($d['charge_type'] !== 'savings' && !$d['account_id']) {
        fail('Choose the account the charge is credited to (income for financial charges, a payable for non-financial ones).');
    }
    $ranges = [];
    if (str_contains($d['charge_by'], '_by_')) {
        foreach (coop_grid_posted('ranges') as $r) {
            if (trim((string)($r['value'] ?? '')) === '') {
                continue;
            }
            $ranges[] = ['range_from' => Dec::fix(Dec::of($r['range_from'] ?? '0'), 4), 'range_to' => Dec::fix(Dec::of($r['range_to'] ?? '0'), 4), 'value' => Dec::fix(Dec::of($r['value']), 4)];
        }
        if (!$ranges) {
            fail('Enter the ranges of the charge.');
        }
        usort($ranges, fn($a, $b) => Dec::cmp($a['range_from'], $b['range_from']));
        for ($i = 1; $i < count($ranges); $i++) {
            if (Dec::cmp($ranges[$i]['range_from'], $ranges[$i - 1]['range_to']) <= 0) {
                fail('The ranges overlap (' . Dec::fix($ranges[$i - 1]['range_to']) . ' and ' . Dec::fix($ranges[$i]['range_from']) . ').');
            }
        }
    }
    DB::tx(function () use ($d, $edit, $ranges, $pageKey) {
        if ($edit) {
            DB::update('loan_charges', $d, 'id = :id', ['id' => $edit['id']]);
            $id = (int)$edit['id'];
        } else {
            $id = DB::insert('loan_charges', $d);
        }
        $old = DB::all('SELECT range_from, range_to, value FROM loan_charge_ranges WHERE charge_id = :c ORDER BY range_from', ['c' => $id]);
        DB::run('DELETE FROM loan_charge_ranges WHERE charge_id = :c', ['c' => $id]);
        foreach ($ranges as $r) {
            DB::insert('loan_charge_ranges', ['charge_id' => $id] + $r);
        }
        Audit::log($edit ? 'edit' : 'create', 'loan', 'loan_charge', $id, $d['name'], ['page_key' => $pageKey, 'before' => $edit ? $edit + ['ranges' => json_encode($old)] : [], 'after' => $d + ['ranges' => json_encode($ranges)], 'changes' => $edit ? null : []]);
    });
    flash('success', 'Charge saved. Loans already released keep the charges they were released with.');
    redirect(url($pageKey));
});

$rows = DB::all('SELECT c.*, a.code AS acode, a.name AS aname, sp.name AS sav_name, (SELECT COUNT(*) FROM loan_product_charges pc WHERE pc.charge_id = c.id) AS products FROM loan_charges c
                   LEFT JOIN accounts a ON a.id = c.account_id LEFT JOIN sav_products sp ON sp.id = c.sav_product_id ORDER BY c.name');
$ranges = $edit ? DB::all('SELECT * FROM loan_charge_ranges WHERE charge_id = :c ORDER BY range_from', ['c' => $edit['id']]) : [];
coop_layout('Loan Charges');
?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card"><table class="table table-sm table-hover"><thead><tr><th>Code</th><th>Charge</th><th>Type</th><th>Computed</th><th>Credited to</th><th class="num">Products</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['code']) ?></td><td><?= h($r['name']) ?></td><td class="small"><?= h(ucfirst(str_replace('_', '-', $r['charge_type']))) ?></td>
    <td class="small"><?= h(Loans::CHARGE_BY[$r['charge_by']] ?? '') ?><?= $r['charge_by'] === 'fixed_rate' ? ': ' . h(Dec::fix($r['value'], 2)) . ' %' : ($r['charge_by'] === 'fixed_amount' ? ': ' . cm($r['value']) : '') ?></td>
    <td class="small"><?= $r['charge_type'] === 'savings' ? h((string)$r['sav_name']) : h(trim($r['acode'] . ' ' . $r['aname'])) ?></td><td class="num"><?= (int)$r['products'] ?></td>
    <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $r['id']])) . '">Edit</a>' : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No charge yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="small text-muted mt-2">Financial charges are income of the cooperative (service fee, filing fee). Non-financial charges are collected for someone else (insurance premium, notarial fee) and credited to a payable. Savings charges are credited to the member's own savings or share capital account (capital build-up retention). The product decides whether charges are deducted from the proceeds, collected separately or added to the loan.</p></div>
  <div class="col-xl-5"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit ' . h($edit['code']) : 'New charge' ?></div><div class="card-body row g-2" data-no-combo>
      <?= csrf_field() ?>
      <?php if (!$edit): ?><?= input_field('code', 'Code', p('code'), ['class' => 'col-4', 'required' => true]) ?><?php endif; ?>
      <?= input_field('name', 'Name', fv('name', $edit), ['class' => $edit ? 'col-12' : 'col-8', 'required' => true]) ?>
      <?= select_field('charge_type', 'Type', Loans::CHARGE_TYPES, fv('charge_type', $edit, 'financial'), ['class' => 'col-12', 'extra' => 'id="cType"']) ?>
      <div class="col-12" data-toggle-by="#cType" data-show-when="financial,non_financial"><?= str_replace('col-md-4', '', select_field('account_id', 'Credited to (account)', Accts::all(), fv('account_id', $edit), ['class' => '', 'blank' => ''])) ?></div>
      <div class="col-12" data-toggle-by="#cType" data-show-when="savings"><?= str_replace('col-md-4', '', select_field('sav_product_id', 'Credited to the member\'s', Savings::productOptions(['ordinary', 'special', 'share_capital']), fv('sav_product_id', $edit), ['class' => '', 'blank' => ''])) ?></div>
      <?= select_field('charge_by', 'Computed as', Loans::CHARGE_BY, fv('charge_by', $edit, 'fixed_rate'), ['class' => 'col-8', 'extra' => 'id="cBy"']) ?>
      <?= input_field('value', 'Rate % or amount', fv('value', $edit, '0'), ['class' => 'col-4', 'extra' => 'inputmode="decimal"']) ?>
      <div class="col-12" data-toggle-by="#cBy" data-show-when="rate_by_term,rate_by_amount,amount_by_term,amount_by_amount">
        <?= coop_grid('gRanges', 'ranges', ['range_from' => ['From (term or amount)', 'text'], 'range_to' => ['To', 'text'], 'value' => ['Rate % or amount', 'text']], is_post() ? coop_grid_posted('ranges') : $ranges, 1, 'Add a range') ?>
        <div class="small text-muted">By term: the term in months. By amount: the loan amount.</div>
      </div>
      <?= input_field('effective_date', 'Effective from', fv('effective_date', $edit), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-6') ?>
      <div class="col-12"><?= save_buttons('Save charge', $edit ? url($pageKey) : null, false) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
