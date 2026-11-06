<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'dist.surplus';
$period = gint('id') ? DB::row('SELECT * FROM dist_periods WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($period, $pageKey) {
    $action = (string)p('action', 'save');
    if ($action === 'save') {
        Access::require($pageKey, $period ? 'edit' : 'create');
        $d = ['fiscal_year' => (int)p('fiscal_year'), 'period_from' => (string)p('period_from'), 'period_to' => (string)p('period_to'), 'net_surplus' => Coop::amount('net_surplus', true),
            'reserve_pct' => Dec::of(p('reserve_pct')), 'educ_pct' => Dec::of(p('educ_pct')), 'educ_apex_pct' => Dec::of(p('educ_apex_pct')), 'cdf_pct' => Dec::of(p('cdf_pct')),
            'optional_pct' => Dec::of(p('optional_pct')), 'isc_amount' => Coop::amount('isc_amount'), 'notes' => trim((string)p('notes'))];
        if ($d['fiscal_year'] < 1990 || $d['fiscal_year'] > 2100) {
            fail('Enter the fiscal year.');
        }
        $id = Distribution::savePeriod($d, $period ? (int)$period['id'] : null, $pageKey);
        flash('success', 'Allocation saved as a draft. The General Assembly approves it before it is posted.');
        redirect(url($pageKey, ['id' => $id]));
    }
    if (!$period) {
        fail('Allocation not found.');
    }
    coop_once();
    switch ($action) {
        case 'approve':
            Distribution::approvePeriod((int)$period['id'], trim((string)p('resolution_no')), (string)p('resolution_date'), trim((string)p('approving_officers')), $pageKey);
            flash('success', 'Allocation approved and locked.');
            break;
        case 'post':
            Access::require($pageKey, 'create');
            $txnId = Distribution::postPeriod((int)$period['id'], (string)p('post_date'), $pageKey);
            flash('success', 'Allocation posted (voucher ' . Txn::load($txnId)['txn_no'] . '). The ISC and PR batches of the year can now be computed.');
            break;
        case 'reverse':
            $txnId = Distribution::reversePeriod((int)$period['id'], (string)p('rev_date'), trim((string)p('reason')), $pageKey);
            flash('success', 'Allocation reversed by voucher ' . Txn::load($txnId)['txn_no'] . '. It is back to approved.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect(url($pageKey, ['id' => $period['id']]));
});

$periods = DB::all('SELECT * FROM dist_periods ORDER BY fiscal_year DESC');
$fyEnd = Coop::setting('fiscal_year_end', '12-31');
$editing = !$period || (g('a') === 'edit' && $period['status'] === 'draft');
$lastYear = (int)date('Y') - 1;
$defFrom = date('Y-m-d', strtotime(($lastYear - 1) . '-' . $fyEnd . ' +1 day'));
$defTo = $lastYear . '-' . $fyEnd;
$rec = $period ?: ['fiscal_year' => $lastYear, 'period_from' => $defFrom, 'period_to' => $defTo, 'reserve_pct' => Coop::setting('reserve_pct', '10'), 'educ_pct' => Coop::setting('educ_pct', '10'),
    'educ_apex_pct' => Coop::setting('educ_apex_pct', '50'), 'cdf_pct' => Coop::setting('cdf_pct', '3'), 'optional_pct' => Coop::setting('optional_pct', '7'), 'isc_amount' => '0'];
// the ledger's net surplus for the period, as a suggestion (the audited figure may differ)
$from = (string)fv('period_from', $rec);
$to = (string)fv('period_to', $rec);
$ledgerNs = valid_date($from) && valid_date($to) ? Distribution::netSurplus($from, $to) : '0';
if (!$period && !is_post()) {
    $rec['net_surplus'] = $ledgerNs;
}
$preview = null;
try {
    $nsv = is_post() ? Dec::of(p('net_surplus')) : Dec::of($rec['net_surplus'] ?? '0');
    $preview = Distribution::allocate($nsv, ['reserve' => (string)fv('reserve_pct', $rec), 'educ' => (string)fv('educ_pct', $rec), 'cdf' => (string)fv('cdf_pct', $rec), 'optional' => (string)fv('optional_pct', $rec)],
        is_post() ? Dec::of(p('isc_amount')) : Dec::of($rec['isc_amount'] ?? '0'));
} catch (UserError $e) {
    $preview = null;
}
$actions = [];
if ($period && $period['status'] === 'draft' && !$editing && Access::can($pageKey, 'edit')) {
    $actions[] = btn_link(url($pageKey, ['id' => $period['id'], 'a' => 'edit']), 'Edit', 'btn-outline-primary', 'pencil');
}
if ($period) {
    $actions[] = btn_link(url($pageKey), 'New year', 'btn-outline-secondary', 'plus-lg');
}
coop_layout($period ? 'Net surplus allocation ' . $period['fiscal_year'] : 'Net Surplus Allocation', ['actions' => implode(' ', $actions)]);
?>
<div class="row g-3">
  <div class="col-xl-3 col-lg-4">
    <div class="card"><div class="card-header">Fiscal years</div><div class="list-group list-group-flush">
      <?php foreach ($periods as $pp): ?><a class="list-group-item list-group-item-action d-flex justify-content-between<?= $period && (int)$period['id'] === (int)$pp['id'] ? ' active' : '' ?>" href="<?= h(url($pageKey, ['id' => $pp['id']])) ?>">
        <span><?= (int)$pp['fiscal_year'] ?><span class="d-block small <?= $period && (int)$period['id'] === (int)$pp['id'] ? '' : 'text-muted' ?>"><?= cm($pp['net_surplus']) ?></span></span><span class="small"><?= h(ucfirst($pp['status'])) ?></span></a><?php endforeach; ?>
      <?php if (!$periods): ?><div class="list-group-item text-muted small">None yet.</div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-xl-9 col-lg-8">
  <?php if ($editing): ?>
    <form method="post" class="card mb-3" data-once><div class="card-header"><?= $period ? 'Edit the draft' : 'New allocation' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <?= input_field('fiscal_year', 'Fiscal year', fv('fiscal_year', $rec), ['class' => 'col-4 col-md-2', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('period_from', 'From', $from, ['class' => 'col-4 col-md-3', 'type' => 'date']) ?>
      <?= input_field('period_to', 'To', $to, ['class' => 'col-4 col-md-3', 'type' => 'date']) ?>
      <?= coop_money('net_surplus', 'Net surplus', fv('net_surplus', $rec), ['class' => 'col-md-4']) ?>
      <div class="col-12 small text-muted">Net surplus in the ledger for the period: <strong><?= cm($ledgerNs) ?></strong> (income less expenses). Use the audited figure when it differs.</div>
      <?= input_field('reserve_pct', 'Reserve fund %', Dec::fix(fv('reserve_pct', $rec)), ['class' => 'col-6 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('educ_pct', 'Education fund %', Dec::fix(fv('educ_pct', $rec)), ['class' => 'col-6 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('educ_apex_pct', 'of which to the federation %', Dec::fix(fv('educ_apex_pct', $rec)), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('cdf_pct', 'Community development %', Dec::fix(fv('cdf_pct', $rec)), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= input_field('optional_pct', 'Optional fund %', Dec::fix(fv('optional_pct', $rec)), ['class' => 'col-6 col-md-2', 'extra' => 'inputmode="decimal"']) ?>
      <?= coop_money('isc_amount', 'Interest on share capital', fv('isc_amount', $rec, '0'), ['class' => 'col-md-4']) ?>
      <div class="col-md-8 small text-muted pt-md-4">The patronage refund is what remains after the statutory funds and the interest on share capital.</div>
      <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"><?= h((string)fv('notes', $rec)) ?></textarea></div>
      <div class="col-12"><?= save_buttons('Save the draft', $period ? url($pageKey, ['id' => $period['id']]) : null, false) ?></div>
    </div></form>
  <?php endif; ?>
  <?php if ($preview || $period):
      $a = $period && !$editing ? ['reserve' => $period['reserve_amt'], 'educ' => $period['educ_amt'], 'cdf' => $period['cdf_amt'], 'optional' => $period['optional_amt'], 'isc' => $period['isc_amount'], 'pr' => $period['pr_amount'],
          'statutory' => Dec::sum([$period['reserve_amt'], $period['educ_amt'], $period['cdf_amt'], $period['optional_amt']])] : $preview;
      $src = $period && !$editing ? $period : ['reserve_pct' => fv('reserve_pct', $rec), 'educ_pct' => fv('educ_pct', $rec), 'educ_apex_pct' => fv('educ_apex_pct', $rec), 'cdf_pct' => fv('cdf_pct', $rec), 'optional_pct' => fv('optional_pct', $rec),
          'net_surplus' => is_post() ? Dec::of(p('net_surplus')) : ($rec['net_surplus'] ?? '0')]; ?>
    <?php if ($a): $apex = Dec::pct($a['educ'], (string)$src['educ_apex_pct']); ?>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Allocation<?= $period ? ' ' . (int)$period['fiscal_year'] : '' ?></span><?= $period ? coop_status($period['status']) : '' ?></div>
      <table class="table table-sm mb-0"><tbody>
        <tr class="fw-semibold"><td>Net surplus</td><td></td><td class="num"><?= cm($src['net_surplus']) ?></td></tr>
        <tr><td>Reserve fund</td><td class="num small text-muted"><?= h(Dec::fix($src['reserve_pct'])) ?> %</td><td class="num"><?= cm($a['reserve']) ?></td></tr>
        <tr><td>Education and training fund</td><td class="num small text-muted"><?= h(Dec::fix($src['educ_pct'])) ?> %</td><td class="num"><?= cm($a['educ']) ?></td></tr>
        <tr class="small text-muted"><td class="ps-4">retained by the cooperative / due to the federation (<?= h(Dec::fix($src['educ_apex_pct'])) ?> %)</td><td></td><td class="num"><?= cm(Dec::sub($a['educ'], $apex, 2)) ?> / <?= cm($apex) ?></td></tr>
        <tr><td>Community development fund</td><td class="num small text-muted"><?= h(Dec::fix($src['cdf_pct'])) ?> %</td><td class="num"><?= cm($a['cdf']) ?></td></tr>
        <tr><td>Optional fund</td><td class="num small text-muted"><?= h(Dec::fix($src['optional_pct'])) ?> %</td><td class="num"><?= cm($a['optional']) ?></td></tr>
        <tr class="fw-semibold"><td>Statutory funds</td><td></td><td class="num"><?= cm($a['statutory']) ?></td></tr>
        <tr><td>Interest on share capital</td><td></td><td class="num"><?= cm($a['isc']) ?></td></tr>
        <tr><td>Patronage refund</td><td></td><td class="num"><?= cm($a['pr']) ?></td></tr>
      </tbody></table></div>
    <?php else: ?><div class="alert alert-warning">The interest on share capital is more than what is left after the statutory funds.</div><?php endif; ?>
  <?php endif; ?>
  <?php if ($period && !$editing):
      $batches = DB::all('SELECT * FROM dist_batches WHERE period_id = :p ORDER BY id', ['p' => $period['id']]);
      $txn = $period['allocation_txn_id'] ? Txn::load((int)$period['allocation_txn_id']) : null; ?>
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card mb-3"><div class="card-body"><?= coop_kv(['Period' => h(fdate($period['period_from']) . ' to ' . fdate($period['period_to'])), 'Resolution' => $period['resolution_no'] ? h($period['resolution_no'] . ' of ' . fdate((string)$period['resolution_date'])) : '',
            'Approving officers' => h((string)$period['approving_officers']), 'Approved' => $period['approved_at'] ? h(fdatetime($period['approved_at'])) . ' by ' . h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $period['approved_by']])) : '',
            'Allocation voucher' => $txn ? '<a href="' . h(Txn::url($txn)) . '">' . h($txn['txn_no']) . '</a> ' . h(fdate($txn['txn_date'])) : '', 'Notes' => nl2br(h((string)$period['notes']))]) ?></div></div>
        <?php if ($period['status'] === 'draft' && Access::can($pageKey, 'approve')): ?>
        <form method="post" class="card mb-3" data-once data-confirm="Approve and lock the allocation of <?= (int)$period['fiscal_year'] ?>?" data-confirm-ok="Approve"><div class="card-header">Approve (General Assembly resolution)</div><div class="card-body row g-2">
          <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="approve">
          <?= input_field('resolution_no', 'Resolution no.', '', ['class' => 'col-6', 'required' => true]) ?>
          <?= input_field('resolution_date', 'Date', today(), ['class' => 'col-6', 'type' => 'date', 'required' => true]) ?>
          <?= input_field('approving_officers', 'Approving officers', '', ['class' => 'col-12']) ?>
          <div class="col-12"><button class="btn btn-success btn-sm"><i class="bi bi-check2-circle"></i> Approve</button></div></div></form>
        <?php endif; ?>
        <?php if ($period['status'] === 'approved' && Access::can($pageKey, 'create')): ?>
        <form method="post" class="card mb-3" data-once data-confirm="Post the allocation? Retained earnings are debited and the funds and payables credited, once." data-confirm-ok="Post the allocation"><div class="card-header">Post the allocation</div><div class="card-body row g-2">
          <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="post">
          <?= input_field('post_date', 'Posting date', today(), ['class' => 'col-6', 'type' => 'date', 'required' => true]) ?>
          <div class="col-12 small text-muted">Dr Retained earnings; Cr Reserve fund, Education and training fund (and the part due to the federation), Community development fund, Optional fund, ISC payable, PR payable.</div>
          <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-journal-check"></i> Post</button></div></div></form>
        <?php endif; ?>
        <?php if ($period['status'] === 'posted' && Access::can($pageKey, 'approve')): ?>
        <form method="post" class="card mb-3" data-once data-confirm="Reverse the posted allocation with an adjusting entry?" data-confirm-ok="Reverse"><div class="card-header">Reverse</div><div class="card-body row g-2">
          <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="reverse">
          <?= input_field('rev_date', 'Date', today(), ['class' => 'col-6', 'type' => 'date']) ?>
          <?= input_field('reason', 'Reason', '', ['class' => 'col-12', 'required' => true]) ?>
          <div class="col-12 small text-muted">Allowed only while no ISC or PR batch of the year is posted. The original voucher stays; an adjusting entry reverses it.</div>
          <div class="col-12"><button class="btn btn-outline-danger btn-sm">Reverse the allocation</button></div></div></form>
        <?php endif; ?>
      </div>
      <div class="col-lg-6"><div class="card"><div class="card-header d-flex justify-content-between"><span>Member batches</span>
        <span><?= page_link('dist.isc', ['period' => $period['id']], 'ISC batch', 'btn-outline-primary', 'plus-lg', 'create') ?> <?= page_link('dist.pr', ['period' => $period['id']], 'PR batch', 'btn-outline-primary', 'plus-lg', 'create') ?></span></div>
        <table class="table table-sm mb-0"><tbody>
        <?php $used = ['isc' => '0', 'pr' => '0']; foreach ($batches as $b): if (in_array($b['status'], ['approved', 'posted'], true)) { $used[$b['kind']] = Dec::add($used[$b['kind']], $b['total_amount'], 2); } ?>
          <tr><td><?= safe_link($b['kind'] === 'isc' ? 'dist.isc' : 'dist.pr', ['id' => $b['id']], $b['batch_no']) ?> <span class="small text-muted"><?= strtoupper(h($b['kind'])) ?></span></td><td class="num"><?= cm($b['total_amount']) ?></td><td><?= coop_status($b['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?><tr><td class="text-muted">No batch yet.</td></tr><?php endif; ?>
        <tr class="small text-muted"><td>ISC used / set aside</td><td class="num" colspan="2"><?= cm($used['isc']) ?> / <?= cm($period['isc_amount']) ?></td></tr>
        <tr class="small text-muted"><td>PR used / set aside</td><td class="num" colspan="2"><?= cm($used['pr']) ?> / <?= cm($period['pr_amount']) ?></td></tr>
        </tbody></table></div></div>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php
layout_end();
