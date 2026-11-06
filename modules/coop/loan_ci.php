<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.ci';
$app = gint('app') ? Loans::application((int)gint('app')) : null;
if (!$app) {
    flash('warning', 'Open the credit investigation from a loan application.');
    redirect(url('loan.applications'));
}
if (!Coop::branchAllowed($app['branch_id'] ? (int)$app['branch_id'] : null)) {
    Access::denyPage($pageKey, 'investigate an application of another branch on');
}
$ci = $app['ci_id'] ? DB::row('SELECT * FROM loan_ci WHERE id = :c', ['c' => $app['ci_id']]) : DB::row('SELECT * FROM loan_ci WHERE application_id = :a ORDER BY id DESC LIMIT 1', ['a' => $app['id']]);
$m = Coop::member((int)$app['member_id']);
const CI_KINDS = [
    'child'     => ['Children', ['name' => 'Name', 'detail1' => 'Level / grade', 'detail2' => 'School', 'years' => 'Age']],
    'income'    => ['Sources of income', ['name' => 'Source', 'detail1' => 'Business / employer', 'detail2' => 'Type', 'detail3' => 'Position', 'years' => 'Years', 'amount' => 'Net monthly income']],
    'asset'     => ['Assets', ['name' => 'Description', 'detail1' => 'Type', 'amount' => 'Estimated value']],
    'reference' => ['Character references', ['name' => 'Name', 'detail1' => 'Occupation', 'detail2' => 'Contact']],
];

handle_post(function () use ($app, $ci, $pageKey) {
    Access::require($pageKey, $ci ? 'edit' : 'create');
    if (in_array($app['status'], ['approved', 'released', 'cancelled', 'disapproved'], true)) {
        fail('The application is ' . mb_strtolower(Loans::APP_STATUSES[$app['status']]) . '; the investigation can no longer change.');
    }
    $num = fn(string $k) => trim((string)p($k)) === '' ? null : Dec::fix(Dec::of(p($k)));
    $d = ['application_id' => $app['id'], 'member_id' => $app['member_id'], 'date_reported' => (string)p('date_reported'), 'last_updated' => today(),
        'spouse_name' => mb_substr(trim((string)p('spouse_name')), 0, 120) ?: null, 'current_address' => mb_substr(trim((string)p('current_address')), 0, 255) ?: null,
        'previous_address' => mb_substr(trim((string)p('previous_address')), 0, 255) ?: null];
    foreach (['residence_type', 'house_type', 'building_type', 'structure_type'] as $k) {
        $d[$k] = mb_substr(trim((string)p($k)), 0, 40) ?: null;
    }
    $d += ['storeys' => trim((string)p('storeys')) === '' ? null : (int)p('storeys'), 'rooms' => trim((string)p('rooms')) === '' ? null : (int)p('rooms'),
        'lot_area' => $num('lot_area'), 'floor_area' => $num('floor_area'), 'estimated_value' => $num('estimated_value'),
        'remarks' => trim((string)p('remarks')) ?: null, 'informants' => mb_substr(trim((string)p('informants')), 0, 255) ?: null,
        'conducted_by' => mb_substr(trim((string)p('conducted_by')), 0, 120) ?: null,
        'recommendation' => in_array(p('recommendation'), ['recommended', 'with_conditions', 'not_recommended'], true) ? p('recommendation') : null, 'score' => $num('score')];
    if (!valid_date($d['date_reported'])) {
        fail('Enter the date of the investigation.');
    }
    if (!$d['recommendation']) {
        fail('Choose the recommendation of the investigator.');
    }
    $grids = [];
    foreach (array_keys(CI_KINDS) as $kind) {
        $grids[$kind] = coop_grid_posted('ci_' . $kind);
    }
    $lines = Loans::ciLines($grids);
    DB::tx(function () use ($d, $lines, $ci, $app, $pageKey) {
        if ($ci) {
            DB::update('loan_ci', $d, 'id = :id', ['id' => $ci['id']]);
            $id = (int)$ci['id'];
        } else {
            $d['created_by'] = Auth::id();
            $id = DB::insert('loan_ci', $d);
        }
        DB::run('DELETE FROM loan_ci_lines WHERE ci_id = :c', ['c' => $id]);
        foreach ($lines as $l) {
            DB::insert('loan_ci_lines', ['ci_id' => $id] + $l);
        }
        DB::update('loan_applications', ['ci_id' => $id, 'updated_by' => Auth::id()], 'id = :a', ['a' => $app['id']]);
        Audit::log($ci ? 'edit' : 'create', 'loan', 'credit_investigation', $id, $app['application_no'], ['page_key' => $pageKey, 'before' => $ci ?: [], 'after' => $d, 'changes' => $ci ? null : []]);
    });
    flash('success', 'Credit investigation saved.' . ($app['status'] === 'for_ci' ? ' Submit the application for approval next.' : ''));
    redirect(url('loan.application', ['id' => $app['id']]));
});

$lines = $ci ? DB::all('SELECT * FROM loan_ci_lines WHERE ci_id = :c ORDER BY id', ['c' => $ci['id']]) : [];
$byKind = [];
foreach ($lines as $l) {
    $byKind[$l['kind']][] = $l;
}
$recs = ['recommended' => 'Recommended', 'with_conditions' => 'Recommended with conditions', 'not_recommended' => 'Not recommended'];

if (g('a') === 'print' && $ci) {
    Access::require($pageKey, 'print');
    layout_start('Credit investigation ' . $app['application_no'], ['bare' => true]);
    ?>
    <div class="doc coop-doc">
      <?= coop_print_head('CREDIT INVESTIGATION REPORT', $app['application_no'], $ci['date_reported']) ?>
      <div class="row my-3 small"><div class="col-7">Applicant: <strong><?= h($m['full_name']) ?></strong>, member no. <?= h($m['member_no']) ?><br>Loan applied for: <?= h($app['product_name']) ?>, <?= cm($app['applied_amount']) ?>, <?= h(Dec::fix($app['term'], 0) . ' ' . $app['term_unit']) ?></div>
        <div class="col-5 text-end">Spouse: <?= h((string)$ci['spouse_name']) ?></div></div>
      <table class="table table-sm doc-table"><tbody>
        <tr><th style="width:30%">Present address</th><td><?= h((string)$ci['current_address']) ?></td></tr>
        <tr><th>Previous address</th><td><?= h((string)$ci['previous_address']) ?></td></tr>
        <tr><th>Residence</th><td><?= h(implode(', ', array_filter([$ci['residence_type'], $ci['house_type'], $ci['building_type'], $ci['structure_type']]))) ?><?= $ci['storeys'] ? ', ' . (int)$ci['storeys'] . ' storey(s)' : '' ?><?= $ci['rooms'] ? ', ' . (int)$ci['rooms'] . ' room(s)' : '' ?></td></tr>
        <tr><th>Lot / floor area</th><td><?= $ci['lot_area'] !== null ? h(Dec::fix($ci['lot_area'])) . ' sq m' : '' ?> / <?= $ci['floor_area'] !== null ? h(Dec::fix($ci['floor_area'])) . ' sq m' : '' ?></td></tr>
        <tr><th>Estimated value</th><td><?= $ci['estimated_value'] !== null ? cm($ci['estimated_value']) : '' ?></td></tr>
      </tbody></table>
      <?php foreach (CI_KINDS as $kind => [$title, $cols]): if (empty($byKind[$kind])) { continue; } ?>
        <div class="fw-semibold small mt-2"><?= h($title) ?></div>
        <table class="table table-sm doc-table"><thead><tr><?php foreach ($cols as $label): ?><th><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($byKind[$kind] as $l): ?><tr><?php foreach (array_keys($cols) as $f): ?><td class="<?= $f === 'amount' ? 'num' : '' ?>"><?= $f === 'amount' && $l[$f] !== null ? cm($l[$f]) : h((string)$l[$f]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table>
      <?php endforeach; ?>
      <div class="small mt-2"><strong>Findings and remarks.</strong> <?= nl2br(h((string)$ci['remarks'])) ?></div>
      <div class="small mt-2">Informants: <?= h((string)$ci['informants']) ?></div>
      <div class="small mt-2">Recommendation: <strong><?= h($recs[$ci['recommendation']] ?? '') ?></strong><?= $ci['score'] !== null ? ', score ' . h(Dec::fix($ci['score'])) : '' ?></div>
      <?= coop_signatures(['Investigated by' => (string)$ci['conducted_by'], 'Noted by (Credit Committee)' => Coop::setting('signatory_loans')]) ?>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

// A new investigation starts from the borrower's last one (another loan); once saved, it shows its own entries
$start = Loans::ciStart($app, $ci);
$rec = $start['rec'];
$prev = $start['prev'];
if (!$ci) {
    $byKind = $start['lines'];
}
$readonly = in_array($app['status'], ['approved', 'released', 'cancelled', 'disapproved'], true) || !Access::can($pageKey, $ci ? 'edit' : 'create');
$actions = [];
if ($ci && Access::can($pageKey, 'print')) {
    $actions[] = btn_link(url($pageKey, ['app' => $app['id'], 'a' => 'print']), 'Print', 'btn-outline-secondary', 'printer');
}
$actions[] = btn_link(url('loan.application', ['id' => $app['id']]), 'Application', 'btn-outline-secondary', 'arrow-left');
coop_layout('Credit investigation ' . $app['application_no'], ['actions' => implode(' ', $actions)]);
$opts = fn(string $key) => array_combine(Members::listNames($key), Members::listNames($key)) ?: [];
?>
<div class="mb-3"><?= member_card($m) ?></div>
<?php
$ciGrid = function (string $kind) use ($byKind): string {
    [$title, $cols] = CI_KINDS[$kind];
    $gridCols = [];
    foreach ($cols as $f => $label) {
        $gridCols[$f] = [$label, $f === 'amount' ? 'money' : ($f === 'years' ? 'number' : 'text')];
    }
    return '<div class="card mb-3 ci-grid"><div class="card-header">' . h($title) . '</div><div class="card-body">'
        . coop_grid('g_' . $kind, 'ci_' . $kind, $gridCols, is_post() ? coop_grid_posted('ci_' . $kind) : ($byKind[$kind] ?? []), 0, 'Add') . '</div></div>';
};
?>
<?php if ($prev): ?>
<div class="alert alert-info ci-carry"><i class="bi bi-arrow-repeat"></i><div>Filled from the borrower's last credit investigation, <strong><?= h($prev['application_no']) ?></strong> of <?= h(fdate($prev['last_updated'] ?: $prev['date_reported'])) ?>.
  Change what is no longer true and save: this investigation is then where the borrower's next one starts.
  <div class="small mt-1">Its findings: <?= h($recs[$prev['recommendation']] ?? 'no recommendation') ?><?= $prev['score'] !== null ? ', score ' . h(Dec::fix($prev['score'])) : '' ?><?= trim((string)$prev['remarks']) !== '' ? '. ' . h(mb_strimwidth((string)$prev['remarks'], 0, 220, '...')) : '' ?></div></div></div>
<?php elseif ($ci): ?>
<div class="small text-muted mb-2">Saved <?= h(fdate($ci['last_updated'] ?: $ci['date_reported'])) ?>. What is saved here is where the borrower's next credit investigation starts.</div>
<?php endif; ?>
<form method="post" data-once>
  <?= csrf_field() ?>
  <fieldset<?= $readonly ? ' disabled' : '' ?>>
  <div class="row g-3">
    <div class="col-xl-6">
      <div class="card mb-3"><div class="card-header">Residence</div><div class="card-body row g-2" data-no-combo>
        <?= input_field('date_reported', 'Date of the investigation', fv('date_reported', $rec), ['class' => 'col-md-4', 'type' => 'date', 'required' => true]) ?>
        <?= input_field('spouse_name', 'Spouse', fv('spouse_name', $rec), ['class' => 'col-md-8']) ?>
        <?= input_field('current_address', 'Present address', fv('current_address', $rec), ['class' => 'col-12']) ?>
        <?= input_field('previous_address', 'Previous address', fv('previous_address', $rec), ['class' => 'col-12']) ?>
        <?= select_field('residence_type', 'Residence', $opts('residence_type') ?: ['Owned' => 'Owned', 'Rented' => 'Rented', 'Living with parents / relatives' => 'Living with parents / relatives', 'Free use' => 'Free use'], fv('residence_type', $rec), ['class' => 'col-md-6', 'blank' => '']) ?>
        <?= select_field('house_type', 'House', ['Single detached' => 'Single detached', 'Duplex' => 'Duplex', 'Row house / apartment' => 'Row house / apartment', 'Condominium' => 'Condominium'], fv('house_type', $rec), ['class' => 'col-md-6', 'blank' => '']) ?>
        <?= select_field('building_type', 'Building', ['Concrete' => 'Concrete', 'Semi-concrete' => 'Semi-concrete', 'Wood' => 'Wood', 'Light materials' => 'Light materials'], fv('building_type', $rec), ['class' => 'col-md-6', 'blank' => '']) ?>
        <?= select_field('structure_type', 'Structure', ['Residential' => 'Residential', 'Residential and commercial' => 'Residential and commercial', 'Commercial' => 'Commercial'], fv('structure_type', $rec), ['class' => 'col-md-6', 'blank' => '']) ?>
        <?= input_field('storeys', 'Storeys', fv('storeys', $rec), ['class' => 'col-4 col-md-2', 'type' => 'number', 'step' => '1']) ?>
        <?= input_field('rooms', 'Rooms', fv('rooms', $rec), ['class' => 'col-4 col-md-2', 'type' => 'number', 'step' => '1']) ?>
        <?= input_field('lot_area', 'Lot area (sq m)', fv('lot_area', $rec), ['class' => 'col-4 col-md-4', 'extra' => 'inputmode="decimal"']) ?>
        <?= input_field('floor_area', 'Floor area (sq m)', fv('floor_area', $rec), ['class' => 'col-6 col-md-4', 'extra' => 'inputmode="decimal"']) ?>
        <?= coop_money('estimated_value', 'Estimated value', fv('estimated_value', $rec), ['class' => 'col-6 col-md-4']) ?>
      </div></div>
    </div>
    <div class="col-xl-6">
      <?= $ciGrid('child') ?>
      <?= $ciGrid('asset') ?>
    </div>
    <div class="col-12"><?= $ciGrid('income') ?></div>
    <div class="col-xl-6"><?= $ciGrid('reference') ?></div>
    <div class="col-xl-6">
      <div class="card mb-3"><div class="card-header">Findings</div><div class="card-body row g-2" data-no-combo>
        <div class="col-12"><label class="form-label">Remarks</label><textarea class="form-control form-control-sm" name="remarks" rows="4"><?= h((string)fv('remarks', $rec)) ?></textarea></div>
        <?= input_field('informants', 'Informants (neighbours, barangay officials, employer)', fv('informants', $rec), ['class' => 'col-12']) ?>
        <?= input_field('conducted_by', 'Investigated by', fv('conducted_by', $rec), ['class' => 'col-md-6']) ?>
        <?= input_field('score', 'Score', fv('score', $rec), ['class' => 'col-6 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
        <?= select_field('recommendation', 'Recommendation', $recs, fv('recommendation', $rec), ['class' => 'col-6 col-md-4', 'blank' => '', 'required' => true]) ?>
      </div></div>
    </div>
  </div>
  <?php if (!$readonly): ?><div class="sticky-actions mt-1"><?= save_buttons('Save the investigation', url('loan.application', ['id' => $app['id']]), false) ?></div><?php endif; ?>
  </fieldset>
</form>
<?php
layout_end();
