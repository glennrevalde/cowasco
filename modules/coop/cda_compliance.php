<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'cda.compliance';
$year = (int)(g('year') ?: (int)date('Y') - 1);
const CDA_STANDARD_ITEMS = [
    'Cooperative Annual Progress Report (CAPR)',
    'Audited financial statements',
    'Social audit report',
    'Performance audit report',
    'List of officers and trainings attended',
    'Certificate of Compliance',
    'Annual tax incentives / benefits report (BIR)',
    'General Assembly minutes and resolutions',
];

if (g('a') === 'file') {
    Access::require($pageKey, 'view');
    $r = DB::row('SELECT * FROM cda_compliance WHERE id = :id', ['id' => gint('id')]);
    if (!$r || !$r['file_name']) {
        http_response_code(404);
        exit('Not found.');
    }
    $ext = strtolower(pathinfo((string)$r['file_name'], PATHINFO_EXTENSION));
    Members::sendFile($r['file_name'], Members::FILE_TYPES[$ext] ?? 'application/octet-stream', (string)($r['original_name'] ?: $r['file_name']));
}

handle_post(function () use ($pageKey, $year) {
    $action = (string)p('action');
    if ($action === 'standard') {
        Access::require($pageKey, 'create');
        $n = 0;
        // the usual due date: 120 days after the end of the fiscal year (editable on each item)
        $fyEnd = $year . '-' . Coop::setting('fiscal_year_end', '12-31');
        $due = valid_date($fyEnd) ? Coop::addDays($fyEnd, 120) : null;
        foreach (CDA_STANDARD_ITEMS as $item) {
            if (!DB::value('SELECT id FROM cda_compliance WHERE report_year = :y AND item = :i', ['y' => $year, 'i' => $item])) {
                DB::insert('cda_compliance', ['report_year' => $year, 'item' => $item, 'due_date' => $due, 'status' => 'pending', 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')]);
                $n++;
            }
        }
        Audit::log('create', 'cda', 'compliance', null, 'Compliance items ' . $year, ['page_key' => $pageKey, 'reason' => $n . ' item(s) added']);
        flash('success', $n . ' item(s) added for ' . $year . '. Check each due date against the current CDA issuances.');
        redirect(url($pageKey, ['year' => $year]));
    }
    if ($action === 'save') {
        $id = pint('id');
        $old = $id ? DB::row('SELECT * FROM cda_compliance WHERE id = :id', ['id' => $id]) : null;
        Access::require($pageKey, $old ? 'edit' : 'create');
        $d = ['report_year' => (int)p('report_year', (string)$year), 'item' => mb_substr(trim((string)p('item')), 0, 120), 'due_date' => valid_date((string)p('due_date')) ? p('due_date') : null,
            'submitted_on' => valid_date((string)p('submitted_on')) ? p('submitted_on') : null, 'reference_no' => mb_substr(trim((string)p('reference_no')), 0, 80) ?: null,
            'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null, 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')];
        if ($d['item'] === '') {
            fail('Enter the report or requirement.');
        }
        $st = (string)p('status');
        if ($st === 'not_applicable') {
            $d['status'] = 'not_applicable';
        } elseif ($d['submitted_on']) {
            $d['status'] = $st === 'accepted' ? 'accepted' : ($d['due_date'] && $d['submitted_on'] > $d['due_date'] ? 'late' : 'submitted');
        } else {
            $d['status'] = 'pending';
        }
        $f = Members::storeUpload($_FILES['file'] ?? null, 0, 'cda', false);
        if ($f) {
            $d['file_name'] = $f[0];
            $d['original_name'] = $f[1];
        }
        if ($old) {
            DB::update('cda_compliance', $d, 'id = :id', ['id' => $old['id']]);
        } else {
            $id = DB::insert('cda_compliance', $d);
        }
        Audit::log($old ? 'edit' : 'create', 'cda', 'compliance', $id, $d['report_year'] . ' ' . $d['item'], ['page_key' => $pageKey, 'before' => $old ?: [], 'after' => $d, 'changes' => $old ? null : []]);
        flash('success', 'Saved.');
        redirect(url($pageKey, ['year' => $d['report_year']]));
    }
    fail('Unknown action.');
});

$rows = DB::all('SELECT c.*, u.full_name FROM cda_compliance c LEFT JOIN users u ON u.id = c.updated_by WHERE c.report_year = :y ORDER BY c.due_date, c.item', ['y' => $year]);
$years = DB::column('SELECT DISTINCT report_year FROM cda_compliance ORDER BY report_year DESC');
$edit = gint('id') ? DB::row('SELECT * FROM cda_compliance WHERE id = :id', ['id' => gint('id')]) : null;
$done = count(array_filter($rows, fn($r) => in_array($r['status'], ['submitted', 'accepted', 'late', 'not_applicable'], true)));
$overdue = count(array_filter($rows, fn($r) => $r['status'] === 'pending' && $r['due_date'] && $r['due_date'] < today()));
coop_layout('Compliance Tracking', ['actions' => page_link('cda.calendar', [], 'Regulatory calendar', 'btn-outline-secondary', 'calendar-event') . ' ' . page_link('cda.capr', ['year' => $year], 'CAPR data', 'btn-outline-secondary', 'file-earmark-bar-graph')]);
?>
<form class="row g-2 align-items-end filters mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= input_field('year', 'Report year', (string)$year, ['class' => 'col-6 col-md-2', 'type' => 'number', 'step' => '1']) ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
  <?php if ($years): ?><div class="col-auto small text-muted">On file: <?= implode(', ', array_map(fn($y) => '<a href="' . h(url($pageKey, ['year' => $y])) . '">' . (int)$y . '</a>', $years)) ?></div><?php endif; ?>
</form>
<div class="coop-tiles">
  <div class="coop-tile"><div class="t-label">Items for <?= $year ?></div><div class="t-value"><?= count($rows) ?></div></div>
  <div class="coop-tile is-ok"><div class="t-label">Done</div><div class="t-value"><?= $done ?></div></div>
  <div class="coop-tile <?= $overdue ? 'is-bad' : '' ?>"><div class="t-label">Overdue</div><div class="t-value"><?= $overdue ?></div></div>
</div>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Report / requirement</th><th>Due</th><th>Submitted</th><th>Reference</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $late = $r['status'] === 'pending' && $r['due_date'] && $r['due_date'] < today(); ?>
    <tr><td><?= h($r['item']) ?><?= $r['notes'] ? '<div class="small text-muted">' . h($r['notes']) . '</div>' : '' ?><?= $r['file_name'] ? '<div class="small"><a href="' . h(url($pageKey, ['a' => 'file', 'id' => $r['id']])) . '" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i> ' . h((string)$r['original_name']) . '</a></div>' : '' ?></td>
      <td class="small <?= $late ? 'text-danger' : '' ?>"><?= h(fdate((string)$r['due_date'])) ?></td><td class="small"><?= h(fdate((string)$r['submitted_on'])) ?></td><td class="small"><?= h((string)$r['reference_no']) ?></td>
      <td><?= $late ? '<span class="text-danger fw-semibold">Overdue</span>' : ($r['status'] === 'pending' ? '<span class="text-muted">To submit</span>' : coop_status($r['status'])) ?></td>
      <td class="text-end"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['year' => $year, 'id' => $r['id']])) . '">Update</a>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">Nothing recorded for <?= $year ?>.<?php if (Access::can($pageKey, 'create')): ?>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="standard"><button class="btn btn-link btn-sm p-0">Add the standard annual requirements</button></form><?php endif; ?></td></tr><?php endif; ?>
  </tbody></table></div></div>
  <p class="small text-muted mt-2">The standard list and the 120-day due date are a starting point; follow the current CDA memorandum circulars for your cooperative's type and category.</p></div>
  <div class="col-xl-4"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" enctype="multipart/form-data" class="card"><div class="card-header"><?= $edit ? 'Update' : 'New requirement' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <?= input_field('report_year', 'Report year', (string)fv('report_year', $edit, (string)$year), ['class' => 'col-4', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('item', 'Report / requirement', fv('item', $edit), ['class' => 'col-8', 'required' => true]) ?>
      <?= input_field('due_date', 'Due', fv('due_date', $edit), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= input_field('submitted_on', 'Submitted on', fv('submitted_on', $edit), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= input_field('reference_no', 'Reference / receiving no.', fv('reference_no', $edit), ['class' => 'col-6']) ?>
      <?= select_field('status', 'Status', ['auto' => 'From the dates', 'accepted' => 'Accepted by CDA', 'not_applicable' => 'Not applicable'], in_array($edit['status'] ?? '', ['accepted', 'not_applicable'], true) ? $edit['status'] : 'auto', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
      <?= input_field('notes', 'Notes', fv('notes', $edit), ['class' => 'col-12']) ?>
      <div class="col-12"><label class="form-label">Copy of the submission (PDF or picture)</label><input class="form-control form-control-sm" type="file" name="file" accept="application/pdf,image/*"></div>
      <div class="col-12"><?= save_buttons('Save', $edit ? url($pageKey, ['year' => $year]) : null, false) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
