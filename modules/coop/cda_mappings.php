<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'cda.mappings';
$report = preg_replace('/[^a-z0-9_]/', '', (string)(g('report') ?: 'capr')) ?: 'capr';
$edit = gint('id') ? DB::row('SELECT * FROM cda_report_mappings WHERE id = :id', ['id' => gint('id')]) : null;
if ($edit) {
    $report = $edit['report'];
}

handle_post(function () use ($edit, $pageKey, $report) {
    if (p('action') === 'delete') {
        Access::require($pageKey, 'delete');
        $row = DB::row('SELECT * FROM cda_report_mappings WHERE id = :id', ['id' => pint('id')]);
        if (!$row) {
            fail('Line not found.');
        }
        DB::run('DELETE FROM cda_report_mappings WHERE id = :id', ['id' => $row['id']]);
        Audit::log('delete', 'cda', 'report_mapping', (int)$row['id'], $row['report'] . ' ' . $row['field_code'], ['page_key' => $pageKey, 'snapshot' => $row]);
        flash('success', 'Line removed.');
        redirect(url($pageKey, ['report' => $row['report']]));
    }
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['report' => preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)p('report', $report)))) ?: 'capr', 'section' => mb_substr(trim((string)p('section')), 0, 60) ?: null,
        'field_code' => mb_substr(preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)p('field_code')), 0, 40), 'label' => mb_substr(trim((string)p('label')), 0, 160),
        'source' => mb_substr(trim((string)p('source')), 0, 255), 'sort_order' => (int)p('sort_order')];
    if ($d['field_code'] === '' || $d['label'] === '' || $d['source'] === '') {
        fail('Enter the field code, the label and the source.');
    }
    if (DB::value('SELECT id FROM cda_report_mappings WHERE report = :r AND field_code = :f AND id <> :id', ['r' => $d['report'], 'f' => $d['field_code'], 'id' => $edit['id'] ?? 0])) {
        fail('That field code is already used in this report.');
    }
    // the source must compute: tried on the last fiscal year
    try {
        CoopMetrics::value($d['source'], date('Y-01-01', strtotime('-1 year')), date('Y-12-31', strtotime('-1 year')));
    } catch (UserError $e) {
        fail('The source does not work: ' . $e->getMessage());
    }
    if ($edit) {
        DB::update('cda_report_mappings', $d, 'id = :id', ['id' => $edit['id']]);
        Audit::log('edit', 'cda', 'report_mapping', (int)$edit['id'], $d['report'] . ' ' . $d['field_code'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
    } else {
        $id = DB::insert('cda_report_mappings', $d);
        Audit::log('create', 'cda', 'report_mapping', $id, $d['report'] . ' ' . $d['field_code'], ['page_key' => $pageKey, 'snapshot' => $d]);
    }
    flash('success', 'Saved.');
    redirect(url($pageKey, ['report' => $d['report']]));
});

$rows = DB::all('SELECT * FROM cda_report_mappings WHERE report = :r ORDER BY sort_order, id', ['r' => $report]);
$reports = DB::column('SELECT DISTINCT report FROM cda_report_mappings ORDER BY report');
coop_layout('Report Field Mappings', ['actions' => page_link('cda.capr', ['report' => $report], 'Compute the report', 'btn-outline-secondary', 'file-earmark-bar-graph')]);
?>
<?php if (count($reports) > 1): ?><?= coop_tabs(array_combine($reports, array_map('strtoupper', $reports)), $report, $pageKey) ?><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Order</th><th>Section</th><th>Field</th><th>Source</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="small"><?= (int)$r['sort_order'] ?></td><td class="small"><?= h((string)$r['section']) ?></td><td><?= h($r['label']) ?><div class="small text-muted"><?= h($r['field_code']) ?></div></td><td class="small"><code><?= h($r['source']) ?></code></td>
    <td class="text-end text-nowrap"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $r['id']])) . '">Edit</a>' : '' ?>
      <?php if (Access::can($pageKey, 'delete')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0 ms-2" data-confirm="Remove this line from the report?">Remove</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="text-muted">No line yet.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
  <div class="col-xl-5">
    <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card mb-3"><div class="card-header"><?= $edit ? 'Edit line' : 'New line' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('report', 'Report', fv('report', $edit, $report), ['class' => 'col-4']) ?>
      <?= input_field('section', 'Section', fv('section', $edit), ['class' => 'col-8']) ?>
      <?= input_field('field_code', 'Field code', fv('field_code', $edit), ['class' => 'col-5', 'required' => true]) ?>
      <?= input_field('sort_order', 'Order', (string)fv('sort_order', $edit, (string)(count($rows) + 1)), ['class' => 'col-3', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('label', 'Label', fv('label', $edit), ['class' => 'col-12', 'required' => true]) ?>
      <?= input_field('source', 'Source', fv('source', $edit), ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="metric:loans_outstanding  or  account:1210,1211  or  text:..."']) ?>
      <div class="col-12"><?= save_buttons('Save', $edit ? url($pageKey, ['report' => $report]) : null, false) ?></div>
    </div></form>
    <?php endif; ?>
    <div class="card"><div class="card-header">Sources</div><div class="card-body small">
      <p><code>account:</code> account codes separated by commas; a code ending in <code>*</code> takes every code that starts with it (<code>account:4*</code>). Balance-sheet accounts give the balance at the end of the year, income and expense accounts the year's activity, in their normal direction.</p>
      <p><code>text:</code> a fixed text. <code>setting:</code> a cooperative setting, such as <code>setting:cda_reg_no</code>.</p>
      <p class="mb-1"><code>metric:</code> one of:</p>
      <table class="table table-sm mb-0"><tbody><?php foreach (CoopMetrics::METRICS as $k => $lbl): ?><tr><td><code><?= h($k) ?></code></td><td><?= h($lbl) ?></td></tr><?php endforeach; ?></tbody></table>
    </div></div>
  </div>
</div>
<?php
layout_end();
