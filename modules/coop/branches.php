<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.branches';
$edit = gint('id') ? DB::row('SELECT * FROM coop_branches WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = [
        'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 10),
        'name' => mb_substr(trim((string)p('name')), 0, 120),
        'address' => mb_substr(trim((string)p('address')), 0, 255) ?: null,
        'phone' => mb_substr(trim((string)p('phone')), 0, 60) ?: null,
        'email' => mb_substr(trim((string)p('email')), 0, 120) ?: null,
        'or_prefix' => mb_substr(strtoupper(trim((string)p('or_prefix'))), 0, 10),
        'is_head_office' => p('is_head_office') === '1' ? 1 : 0,
        'is_active' => p('is_active', '1') === '1' ? 1 : 0,
    ];
    if ($d['code'] === '' || $d['name'] === '') {
        fail('Enter the branch code and name.');
    }
    if (!preg_match('/^[A-Z0-9-]+$/', $d['code'])) {
        fail('The branch code uses letters, numbers and dashes only (it is part of the account numbers).');
    }
    if (DB::value('SELECT id FROM coop_branches WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $edit['id'] ?? 0])) {
        fail('Branch code ' . $d['code'] . ' is already used.');
    }
    if ($d['or_prefix'] !== '' && DB::value('SELECT id FROM coop_branches WHERE or_prefix = :o AND id <> :id', ['o' => $d['or_prefix'], 'id' => $edit['id'] ?? 0])) {
        fail('Another branch already uses the official receipt prefix ' . $d['or_prefix'] . '.');
    }
    $classId = pint('class_id');
    DB::tx(function () use ($d, $edit, $classId, $pageKey) {
        // every branch posts under a class, so the statements by class are the branch statements
        if (!$classId) {
            $classId = (int)DB::value('SELECT id FROM classes WHERE name = :n', ['n' => $d['name']]) ?: DB::insert('classes', ['name' => $d['name'], 'description' => 'Cooperative branch ' . $d['code'], 'created_by' => Auth::id()]);
            Classes::reset();
        }
        $d['class_id'] = $classId;
        if ($d['is_head_office']) {
            DB::run('UPDATE coop_branches SET is_head_office = 0 WHERE id <> :id', ['id' => $edit['id'] ?? 0]);
        }
        if ($edit) {
            DB::update('coop_branches', $d, 'id = :id', ['id' => $edit['id']]);
            Audit::log('edit', 'coop', 'branch', (int)$edit['id'], $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
            $id = (int)$edit['id'];
        } else {
            $id = DB::insert('coop_branches', $d);
            Audit::log('create', 'coop', 'branch', $id, $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey, 'snapshot' => $d]);
        }
        $GLOBALS['saved_id'] = $id;
    });
    Coop::resetBranches();
    flash('success', 'Branch saved.');
    redirect_after_save(url($pageKey), url($pageKey, ['id' => $GLOBALS['saved_id']]));
});

$rows = DB::all('SELECT b.*, c.name AS class_name, (SELECT COUNT(*) FROM members m WHERE m.branch_id = b.id) AS members,
                        (SELECT COUNT(*) FROM users u WHERE u.coop_branch_id = b.id) AS users
                   FROM coop_branches b LEFT JOIN classes c ON c.id = b.class_id ORDER BY b.is_head_office DESC, b.code');
coop_layout('Branches');
?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card"><table class="table table-sm table-hover">
    <thead><tr><th>Code</th><th>Branch</th><th>Class (reports)</th><th>OR prefix</th><th class="num">Members</th><th class="num">Users</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td><?= h($r['code']) ?></td>
        <td><?= h($r['name']) ?><?= (int)$r['is_head_office'] ? ' <span class="small text-primary">head office</span>' : '' ?><div class="small text-muted"><?= h((string)$r['address']) ?></div></td>
        <td><?= h((string)$r['class_name']) ?></td><td><?= h($r['or_prefix'] ?: '(none)') ?></td><td class="num"><?= (int)$r['members'] ?></td><td class="num"><?= (int)$r['users'] ?></td>
        <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $r['id']])) . '">Edit</a>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No branch yet. Add the head office first.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="small text-muted mt-2">Users are assigned to a branch and a teller cash account on System Administration &gt; User Accounts. A user with a branch sees only the members of that branch unless a role gives "can work in all branches".</p>
  </div>
  <div class="col-xl-5"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit branch ' . h($edit['code']) : 'New branch' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('code', 'Code', fv('code', $edit), ['class' => 'col-4', 'required' => true, 'extra' => 'maxlength="10" placeholder="01"']) ?>
      <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-8', 'required' => true]) ?>
      <?= input_field('address', 'Address', fv('address', $edit), ['class' => 'col-12']) ?>
      <?= input_field('phone', 'Phone', fv('phone', $edit), ['class' => 'col-6']) ?>
      <?= input_field('email', 'Email', fv('email', $edit), ['class' => 'col-6', 'type' => 'email']) ?>
      <?= select_field('class_id', 'Class used on its postings', Classes::options($edit ? (int)$edit['class_id'] : null), fv('class_id', $edit), ['class' => 'col-7', 'blank' => 'Make a class with the branch name']) ?>
      <?= input_field('or_prefix', 'Official receipt prefix', fv('or_prefix', $edit), ['class' => 'col-5', 'extra' => 'maxlength="10" placeholder="e.g. P"']) ?>
      <?= check_field('is_head_office', 'Head office', fv('is_head_office', $edit, 0), 'col-6') ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-6') ?>
      <div class="col-12 small text-muted">The prefix starts every official receipt number of the branch (P000123), so each branch keeps its own series.</div>
      <div class="col-12"><?= save_buttons('Save branch', $edit ? url($pageKey) : null, Access::can($pageKey, 'create')) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
