<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.applications';
$formKey = 'loan.application';

// Delete (the button of each row): an application that was never released, with the reason in the audit trail
handle_post(function () use ($pageKey, $formKey) {
    if ((string)p('action') !== 'delete') {
        fail('Unknown action.');
    }
    Access::require($formKey, 'delete');
    $app = Loans::application((int)gint('id'));
    if (!$app || !Coop::branchAllowed($app['branch_id'] ? (int)$app['branch_id'] : null)) {
        fail('Application not found.');
    }
    Loans::deleteApplication((int)$app['id'], trim((string)p('reason')), $formKey);
    flash('success', 'Application ' . $app['application_no'] . ' of ' . $app['member_name'] . ' deleted.');
    redirect(url($pageKey, array_filter(['status' => g('status'), 'q' => g('q'), 'product' => g('product'), 'all' => g('all')], fn($v) => $v !== null && $v !== '')));
});
$status = array_key_exists(g('status'), Loans::APP_STATUSES) ? (string)g('status') : '';
$q = trim((string)g('q'));
$product = gint('product');
[$bf, $bp] = Coop::branchFilter('a.branch_id');
$where = '1 = 1' . $bf;
$p = $bp;
if ($status !== '') {
    $where .= ' AND a.status = :st';
    $p['st'] = $status;
} elseif (g('all') !== '1') {
    $where .= " AND a.status NOT IN ('released','cancelled','disapproved')";
}
if ($q !== '') {
    $where .= ' AND (a.application_no LIKE :q1 OR m.full_name LIKE :q2 OR m.member_no = :q3)';
    $p += ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => $q];
}
if ($product) {
    $where .= ' AND a.product_id = :pr';
    $p['pr'] = $product;
}
$rows = DB::all("SELECT a.*, m.full_name, m.member_no, pr.name AS product_name, u.full_name AS officer,
                        (SELECT COUNT(*) FROM loan_approvals x WHERE x.application_id = a.id AND x.action = 'approved') AS approvals
                   FROM loan_applications a JOIN members m ON m.id = a.member_id JOIN loan_products pr ON pr.id = a.product_id LEFT JOIN users u ON u.id = a.officer_id
                  WHERE $where ORDER BY FIELD(a.status, 'for_approval', 'approved', 'for_ci', 'validated', 'returned', 'new') DESC, a.application_date DESC, a.id DESC LIMIT 300", $p);
$counts = DB::pairs('SELECT a.status, COUNT(*) FROM loan_applications a WHERE 1 = 1' . $bf . ' GROUP BY a.status', $bp);
coop_layout('Loan Application Center', ['actions' => page_link('loan.application', [], 'New application', 'btn-primary', 'plus-lg', 'create')]);
$tile = fn(string $st, string $label, string $cls) => '<a class="coop-tile ' . $cls . '" href="' . h(url($pageKey, ['status' => $st])) . '"><div class="t-label">' . h($label) . '</div><div class="t-value">' . (int)($counts[$st] ?? 0) . '</div></a>';
?>
<div class="coop-tiles">
  <?= $tile('new', 'New', '') ?><?= $tile('for_ci', 'For credit investigation', 'is-warn') ?><?= $tile('validated', 'Validated', '') ?>
  <?= $tile('for_approval', 'Waiting for approval', 'is-warn') ?><?= $tile('approved', 'Approved, to release', 'is-ok') ?><?= $tile('returned', 'Returned', 'is-bad') ?>
</div>
<form class="row g-2 align-items-end filters mb-3 no-print">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= input_field('q', 'Find', $q, ['class' => 'col-md-3', 'extra' => 'placeholder="Application no., member"']) ?>
  <?= select_field('status', 'Status', Loans::APP_STATUSES, $status, ['class' => 'col-6 col-md-3', 'blank' => 'In process', 'extra' => 'data-no-combo']) ?>
  <?= select_field('product', 'Product', Loans::productOptions(false), $product, ['class' => 'col-6 col-md-3', 'blank' => 'All products', 'extra' => 'data-no-combo']) ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button> <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['all' => 1])) ?>">All, including closed</a></div>
</form>
<?php $canDelete = Access::can($formKey, 'delete');
$released = $canDelete && $rows ? array_flip(DB::column('SELECT DISTINCT application_id FROM loan_accounts WHERE application_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ')')) : [];
$back = array_filter(['status' => $status, 'q' => $q, 'product' => $product ?: null, 'all' => g('all')], fn($v) => $v !== null && $v !== ''); ?>
<div class="card"><table class="table table-sm table-hover stack-sm app-center-table"><thead><tr><th>Application</th><th>Date</th><th>Member</th><th>Product</th><th class="num">Applied</th><th class="num">Approved</th><th>Term</th><th>Officer</th><th>Status</th><?php if ($canDelete): ?><th class="text-end"><span class="visually-hidden">Delete</span></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td data-label="Application"><a href="<?= h(url('loan.application', ['id' => $r['id']])) ?>"><?= h($r['application_no']) ?></a></td><td data-label="Date"><?= h(fdate($r['application_date'])) ?></td>
  <td data-label="Member"><?= h($r['full_name']) ?><div class="small text-muted"><?= h($r['member_no']) ?></div></td><td class="small" data-label="Product"><?= h($r['product_name']) ?></td><td class="num" data-label="Applied"><?= cm($r['applied_amount']) ?></td>
  <td class="num" data-label="Approved"><?= $r['approved_amount'] ? cm($r['approved_amount']) : '' ?></td><td class="small" data-label="Term"><?= h(Dec::fix($r['term'], 0) . ' ' . $r['term_unit']) ?>, <?= h(mb_strtolower(Coop::modeLabel($r['mode_of_payment']))) ?></td>
  <td class="small" data-label="Officer"><?= h((string)$r['officer']) ?></td><td data-label="Status"><?= coop_status($r['status']) ?><?= $r['status'] === 'for_approval' && (int)$r['approvals'] ? '<div class="small text-muted">' . (int)$r['approvals'] . ' level(s) approved</div>' : '' ?></td>
  <?php if ($canDelete): ?><td class="text-end app-del-cell"><?php if ($r['status'] !== 'released' && !$r['loan_id'] && !isset($released[$r['id']])): ?><button type="button" class="btn btn-sm btn-outline-danger app-del-btn" data-bs-toggle="modal" data-bs-target="#deleteApp"
      data-del-action="<?= h(url($pageKey, ['id' => $r['id']] + $back)) ?>" data-del-no="<?= h($r['application_no']) ?>" data-del-member="<?= h($r['full_name'] . ' (' . $r['member_no'] . ')') ?>" title="Delete <?= h($r['application_no']) ?>"><i class="bi bi-trash3"></i><span> Delete</span></button><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= $canDelete ? 10 : 9 ?>" class="text-muted">No application.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canDelete): ?><?= coop_app_delete_modal('deleteApp', null) ?><?php endif; ?>
<?php
layout_end();
