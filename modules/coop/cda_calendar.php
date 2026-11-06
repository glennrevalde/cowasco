<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'cda.calendar';
const CAL_CATEGORIES = ['cda' => 'CDA', 'bir' => 'BIR', 'lgu' => 'Local government', 'internal' => 'Internal (General Assembly, Board)', 'other' => 'Other'];
const CAL_RECURRENCE = ['none' => 'Once', 'monthly' => 'Every month', 'quarterly' => 'Every quarter', 'yearly' => 'Every year'];
$edit = gint('id') ? DB::row('SELECT * FROM cda_calendar WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['title' => mb_substr(trim((string)p('title')), 0, 160), 'category' => array_key_exists(p('category'), CAL_CATEGORIES) ? p('category') : 'other',
        'due_date' => (string)p('due_date'), 'recurrence' => array_key_exists(p('recurrence'), CAL_RECURRENCE) ? p('recurrence') : 'yearly',
        'remind_days' => max(0, min(365, (int)p('remind_days', '30'))), 'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if ($d['title'] === '' || !valid_date($d['due_date'])) {
        fail('Enter the title and the due date.');
    }
    if (DB::value('SELECT id FROM cda_calendar WHERE title = :t AND id <> :id', ['t' => $d['title'], 'id' => $edit['id'] ?? 0])) {
        fail('There is already an item with that title.');
    }
    if ($edit) {
        DB::update('cda_calendar', $d, 'id = :id', ['id' => $edit['id']]);
        Audit::log('edit', 'cda', 'calendar', (int)$edit['id'], $d['title'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
    } else {
        $id = DB::insert('cda_calendar', $d);
        Audit::log('create', 'cda', 'calendar', $id, $d['title'], ['page_key' => $pageKey, 'snapshot' => $d]);
    }
    unset($_SESSION['coop_alerts']);
    flash('success', 'Saved.');
    redirect(url($pageKey));
});

$today = today();
$items = [];
foreach (DB::all('SELECT * FROM cda_calendar ORDER BY is_active DESC, title') as $c) {
    $c['next'] = CoopAlerts::calendarNext($c, $today);
    $c['days'] = Coop::daysBetween($today, $c['next']);
    $items[] = $c;
}
usort($items, fn($a, $b) => [(int)!$a['is_active'], $a['next']] <=> [(int)!$b['is_active'], $b['next']]);
coop_layout('Regulatory Calendar', ['actions' => page_link('cda.compliance', [], 'Compliance tracking', 'btn-outline-secondary', 'clipboard-check')]);
?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Deadline</th><th>For</th><th>Next due</th><th>Repeats</th><th></th></tr></thead><tbody>
  <?php foreach ($items as $c): $soon = (int)$c['is_active'] && $c['days'] <= (int)$c['remind_days']; $past = $c['days'] < 0; ?>
    <tr class="<?= (int)$c['is_active'] ? '' : 'text-muted' ?>"><td><?= h($c['title']) ?><?= $c['notes'] ? '<div class="small text-muted">' . h($c['notes']) . '</div>' : '' ?></td><td class="small"><?= h(CAL_CATEGORIES[$c['category']] ?? $c['category']) ?></td>
      <td class="<?= $past ? 'text-danger' : ($soon ? 'text-warning fw-semibold' : '') ?>"><?= h(fdate($c['next'])) ?><div class="small"><?= $past ? 'passed' : ($c['days'] === 0 ? 'today' : 'in ' . $c['days'] . ' day(s)') ?></div></td>
      <td class="small"><?= h(CAL_RECURRENCE[$c['recurrence']] ?? '') ?>; reminder <?= (int)$c['remind_days'] ?> days before</td>
      <td class="text-end"><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $c['id']])) . '">Edit</a>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="5" class="text-muted">No deadline yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <p class="small text-muted mt-2">Deadlines within their reminder period appear on the dashboard and in the alert count at the top of every page. The dates set here are your own: confirm them against the current CDA, BIR and local government issuances.</p></div>
  <div class="col-xl-4"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit' : 'New deadline' ?></div><div class="card-body row g-2" data-no-combo>
      <?= csrf_field() ?>
      <?= input_field('title', 'Title', fv('title', $edit), ['class' => 'col-12', 'required' => true]) ?>
      <?= select_field('category', 'For', CAL_CATEGORIES, fv('category', $edit, 'cda'), ['class' => 'col-6']) ?>
      <?= input_field('due_date', 'Due date', fv('due_date', $edit), ['class' => 'col-6', 'type' => 'date', 'required' => true]) ?>
      <?= select_field('recurrence', 'Repeats', CAL_RECURRENCE, fv('recurrence', $edit, 'yearly'), ['class' => 'col-6']) ?>
      <?= input_field('remind_days', 'Remind days before', (string)fv('remind_days', $edit, '30'), ['class' => 'col-6', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('notes', 'Notes', fv('notes', $edit), ['class' => 'col-12']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save', $edit ? url($pageKey) : null, false) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
