<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.approval_matrix';
$edit = gint('id') ? DB::row('SELECT * FROM loan_approval_matrix WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($edit, $pageKey) {
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['level_no' => max(1, (int)p('level_no', '1')), 'amount_from' => Dec::fix(Dec::of(p('amount_from'))), 'amount_to' => Dec::fix(Dec::of(p('amount_to'))), 'product_id' => pint('product_id'),
        'role_id' => pint('role_id'), 'position' => mb_substr(trim((string)p('position')), 0, 80) ?: null, 'is_active' => p('is_active', '1') === '1' ? 1 : 0];
    if (Dec::isPos($d['amount_to']) && Dec::cmp($d['amount_from'], $d['amount_to']) > 0) {
        fail('The amount range is reversed.');
    }
    if ($edit) {
        DB::update('loan_approval_matrix', $d, 'id = :id', ['id' => $edit['id']]);
        Audit::log('edit', 'loan', 'approval_matrix', (int)$edit['id'], 'Level ' . $d['level_no'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
    } else {
        $id = DB::insert('loan_approval_matrix', $d);
        Audit::log('create', 'loan', 'approval_matrix', $id, 'Level ' . $d['level_no'], ['page_key' => $pageKey, 'snapshot' => $d]);
    }
    flash('success', 'Approval level saved. Applications already approved are not affected.');
    redirect(url($pageKey));
});

$rows = DB::all('SELECT x.*, p.name AS product_name, r.name AS role_name FROM loan_approval_matrix x LEFT JOIN loan_products p ON p.id = x.product_id LEFT JOIN roles r ON r.id = x.role_id ORDER BY x.level_no, x.amount_from');
$roles = DB::pairs('SELECT id, name FROM roles ORDER BY name');
coop_layout('Loan Approval Matrix');
?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card"><table class="table table-sm"><thead><tr><th>Level</th><th>Amount</th><th>Product</th><th>Approved by</th><th>Position printed</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>"><td><?= (int)$r['level_no'] ?></td><td><?= cm($r['amount_from']) ?> to <?= Dec::isPos($r['amount_to']) ? cm($r['amount_to']) : 'any amount' ?></td>
    <td><?= h($r['product_name'] ?? 'Every product') ?></td><td><?= h($r['role_name'] ?? 'Anyone with Approve on the page') ?></td><td><?= h((string)$r['position']) ?></td>
    <td><?= Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['id' => $r['id']])) . '">Edit</a>' : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted">No level: one approval by anyone with the Approve right on the Loan Application Center approves a loan.</td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="small text-muted mt-2">A loan needs every level whose amount range covers the amount applied for, in order, each by a different person. Example: level 1 Credit Committee for every amount; level 2 General Manager above 100,000; level 3 Board above 500,000.</p></div>
  <div class="col-xl-5"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit level' : 'New level' ?></div><div class="card-body row g-2" data-no-combo>
      <?= csrf_field() ?>
      <?= input_field('level_no', 'Level', fv('level_no', $edit, '1'), ['class' => 'col-3', 'type' => 'number', 'step' => '1']) ?>
      <?= coop_money('amount_from', 'From amount', fv('amount_from', $edit, '0'), ['class' => 'col-4 col-md-4']) ?>
      <?= coop_money('amount_to', 'To (0 = any)', fv('amount_to', $edit, '0'), ['class' => 'col-5 col-md-5']) ?>
      <?= select_field('product_id', 'Product', Loans::productOptions(false), fv('product_id', $edit), ['class' => 'col-12', 'blank' => 'Every product']) ?>
      <?= select_field('role_id', 'Role that approves', $roles, fv('role_id', $edit), ['class' => 'col-12', 'blank' => 'Anyone with the Approve right']) ?>
      <?= input_field('position', 'Position printed on the approval', fv('position', $edit), ['class' => 'col-12']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save level', $edit ? url($pageKey) : null, false) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
