<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'cda.officers';
const COOP_BODIES = ['board' => 'Board of Directors', 'audit' => 'Audit Committee', 'election' => 'Election Committee', 'credit' => 'Credit Committee',
    'education' => 'Education and Training Committee', 'ethics' => 'Ethics Committee', 'mediation' => 'Mediation and Conciliation Committee',
    'gad' => 'Gender and Development Committee', 'management' => 'Management (General Manager, Treasurer, Secretary, Bookkeeper)', 'other' => 'Other committees'];
$edit = gint('id') ? DB::row('SELECT * FROM coop_officers WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $memberId = pint('member_id') ?: null;
    $name = trim((string)p('name'));
    if ($memberId) {
        $m = Coop::member($memberId);
        if (!$m) {
            fail('Member not found.');
        }
        $name = $m['full_name'];
    }
    $d = ['member_id' => $memberId, 'name' => mb_substr($name, 0, 160), 'body' => array_key_exists(p('body'), COOP_BODIES) ? p('body') : 'other',
        'position' => mb_substr(trim((string)p('position')), 0, 80), 'term_start' => valid_date((string)p('term_start')) ? p('term_start') : null,
        'term_end' => valid_date((string)p('term_end')) ? p('term_end') : null, 'trainings' => trim((string)p('trainings')) ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if ($d['name'] === '' || $d['position'] === '') {
        fail('Enter the officer (a member, or the name) and the position.');
    }
    if ($d['term_start'] && $d['term_end'] && $d['term_start'] > $d['term_end']) {
        fail('The term ends before it starts.');
    }
    if ($edit) {
        DB::update('coop_officers', $d, 'id = :id', ['id' => $edit['id']]);
        Audit::log('edit', 'cda', 'coop_officer', (int)$edit['id'], $d['name'] . ', ' . $d['position'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
    } else {
        $id = DB::insert('coop_officers', $d);
        Audit::log('create', 'cda', 'coop_officer', $id, $d['name'] . ', ' . $d['position'], ['page_key' => $pageKey, 'snapshot' => $d]);
    }
    flash('success', 'Saved.');
    redirect(url($pageKey));
});

$showAll = g('all') === '1';
$rows = DB::all('SELECT o.*, m.member_no FROM coop_officers o LEFT JOIN members m ON m.id = o.member_id' . ($showAll ? '' : ' WHERE o.is_active = 1') . ' ORDER BY FIELD(o.body, ' . implode(',', array_map(fn($k) => "'" . $k . "'", array_keys(COOP_BODIES))) . '), o.position, o.name');
$byBody = [];
foreach ($rows as $r) {
    $byBody[$r['body']][] = $r;
}

if (g('a') === 'print') {
    $copy = coop_print_gate('cda.officers', 'officers', (int)date('Ymd'), date('Y-m-d'), $pageKey, 'list of officers');
    if ($copy === null) {
        exit;
    }
    layout_start('List of Officers', ['bare' => true]);
    ?>
    <div class="doc coop-doc"><?= coop_copy_mark($copy) ?>
      <?= coop_print_head('LIST OF OFFICERS AND COMMITTEE MEMBERS', '', today()) ?>
      <?php foreach (COOP_BODIES as $k => $lbl): if (empty($byBody[$k])) { continue; } ?>
        <div class="fw-semibold mt-3"><?= h($lbl) ?></div>
        <table class="table table-sm doc-table"><thead><tr><th>Name</th><th>Position</th><th>Term</th><th>Trainings attended</th></tr></thead><tbody>
        <?php foreach ($byBody[$k] as $o): ?><tr><td><?= h($o['name']) ?></td><td><?= h($o['position']) ?></td><td><?= h(fdate((string)$o['term_start'])) ?> to <?= h(fdate((string)$o['term_end'])) ?></td><td class="small"><?= nl2br(h((string)$o['trainings'])) ?></td></tr><?php endforeach; ?>
        </tbody></table>
      <?php endforeach; ?>
      <?= coop_signatures(['Certified correct (Secretary)' => '', 'Chairperson' => Coop::setting('signatory_chair')]) ?>
    </div><script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

$soon = Coop::addDays(today(), 60);
coop_layout('Officers, Committees & Trainings', ['actions' => page_link($pageKey, ['a' => 'print'], 'Print the list', 'btn-outline-secondary', 'printer', 'print') . ' ' . btn_link(url($pageKey, $showAll ? [] : ['all' => 1]), $showAll ? 'Active only' : 'Include past officers', 'btn-outline-secondary')]);
?>
<div class="row g-3">
  <div class="col-xl-8">
    <?php foreach (COOP_BODIES as $k => $lbl): if (empty($byBody[$k])) { continue; } ?>
      <div class="card mb-3"><div class="card-header"><?= h($lbl) ?></div><table class="table table-sm mb-0"><tbody>
      <?php foreach ($byBody[$k] as $o): $ending = $o['term_end'] && $o['term_end'] <= $soon && $o['term_end'] >= today(); $ended = $o['term_end'] && $o['term_end'] < today(); ?>
        <tr class="<?= (int)$o['is_active'] ? '' : 'text-muted' ?>"><td><?= $o['member_id'] ? safe_link('coop.member', ['id' => $o['member_id']], $o['name']) : h($o['name']) ?><div class="small text-muted"><?= h((string)$o['member_no']) ?></div></td>
          <td><?= h($o['position']) ?></td><td class="small <?= $ended ? 'text-danger' : ($ending ? 'text-warning' : '') ?>"><?= h(fdate((string)$o['term_start'])) ?> to <?= h(fdate((string)$o['term_end'])) ?><?= $ended ? '<div>term ended</div>' : ($ending ? '<div>term ends soon</div>' : '') ?></td>
          <td class="small"><?= $o['trainings'] ? count(array_filter(explode("\n", (string)$o['trainings']), 'trim')) . ' training(s)' : '<span class="text-warning">no training recorded</span>' ?></td>
          <td class="text-end"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $o['id']])) . '">Edit</a>' : '' ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="card"><div class="card-body text-muted">No officer recorded yet.</div></div><?php endif; ?>
  </div>
  <div class="col-xl-4"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit ' . h($edit['name']) : 'New officer' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= member_pick('member_id', $edit && $edit['member_id'] ? (int)$edit['member_id'] : null, ['class' => 'col-12', 'label' => 'Member']) ?>
      <?= input_field('name', 'or name (not a member)', $edit && !$edit['member_id'] ? $edit['name'] : p('name'), ['class' => 'col-12']) ?>
      <?= select_field('body', 'Board / committee', COOP_BODIES, fv('body', $edit, 'board'), ['class' => 'col-12', 'extra' => 'data-no-combo']) ?>
      <?= input_field('position', 'Position', fv('position', $edit), ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="Chairperson, Vice-Chairperson, Member ..."']) ?>
      <?= input_field('term_start', 'Term from', fv('term_start', $edit), ['class' => 'col-6', 'type' => 'date']) ?>
      <?= input_field('term_end', 'to', fv('term_end', $edit), ['class' => 'col-6', 'type' => 'date']) ?>
      <div class="col-12"><label class="form-label">Trainings attended (one a line: title, date, provider)</label><textarea class="form-control form-control-sm" name="trainings" rows="4"><?= h((string)fv('trainings', $edit)) ?></textarea></div>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save', $edit ? url($pageKey) : null, false) ?></div>
    </div></form>
    <p class="small text-muted mt-2">CDA rules require officers to complete the mandatory trainings for their position; the list printed here goes with the annual reports.</p>
  <?php endif; ?></div>
</div>
<?php
layout_end();
