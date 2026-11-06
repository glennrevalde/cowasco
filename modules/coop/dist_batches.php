<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'dist.batches';
$kind = in_array(g('kind'), ['isc', 'pr'], true) ? (string)g('kind') : '';
$status = in_array(g('status'), ['draft', 'validated', 'approved', 'posted', 'cancelled'], true) ? (string)g('status') : '';
$where = '1 = 1';
$params = [];
if ($kind !== '') {
    $where .= ' AND b.kind = :k';
    $params['k'] = $kind;
}
if ($status !== '') {
    $where .= ' AND b.status = :s';
    $params['s'] = $status;
}
// Delete: an interest on share capital or patronage refund computation never posted (in review, reviewed or approved)
handle_post(function () {
    if (p('action') !== 'delete') {
        fail('Unknown action.');
    }
    $b = Distribution::batch((int)pint('batch_id'));
    if (!$b) {
        fail('The batch is no longer on file. It may have been deleted already.');
    }
    $pk = 'dist.' . $b['kind'];
    Access::require($pk, 'delete');
    Distribution::deleteBatch((int)$b['id'], (string)p('reason'), $pk);
    flash('success', 'Batch ' . $b['batch_no'] . ' deleted. Nothing had been posted; the batch as it was is kept in the audit trail.');
    redirect(url('dist.batches', array_filter(['kind' => g('kind'), 'status' => g('status')])));
});
$rows = DB::all("SELECT b.*, p.fiscal_year, u.full_name AS prepared_by FROM dist_batches b LEFT JOIN dist_periods p ON p.id = b.period_id LEFT JOIN users u ON u.id = b.created_by WHERE $where ORDER BY b.id DESC LIMIT 300", $params);
// payables not yet paid out (credit mode "payable"): the member lines still carrying the batch voucher
$unpaid = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(bl.net_amount),0) AS amt FROM dist_batch_lines bl JOIN dist_batches b ON b.id = bl.batch_id
                    WHERE b.status = 'posted' AND b.credit_mode = 'payable' AND bl.is_excluded = 0 AND bl.net_amount > 0 AND (bl.paid_txn_id IS NULL OR bl.paid_txn_id = b.txn_id)");
// who may delete which: the Delete right of the batch's page, and for an approved batch its Approve right too
$canDel = function (array $b): bool {
    $pk = 'dist.' . $b['kind'];
    return Distribution::deletable($b) && Access::can($pk, 'delete') && ($b['status'] !== 'approved' || Access::can($pk, 'approve'));
};
$anyDel = (bool)array_filter($rows, $canDel);
coop_layout('Distribution Batches', ['actions' => page_link('dist.surplus', [], 'Net surplus allocation', 'btn-outline-secondary', 'pie-chart') . ' ' . page_link('dist.isc', [], 'New ISC batch', 'btn-primary', 'plus-lg', 'create') . ' ' . page_link('dist.pr', [], 'New PR batch', 'btn-primary', 'plus-lg', 'create')]);
?>
<?php if ((int)$unpaid['n']): ?><div class="alert alert-light small"><i class="bi bi-info-circle"></i> <?= (int)$unpaid['n'] ?> member amount(s) totalling <?= cm($unpaid['amt']) ?> are payable and not yet paid out. They are paid from the report <?= page_link('rpt.dist_unclaimed', [], 'Unclaimed Distributions', 'btn-link btn-sm p-0 align-baseline') ?> (click the member).</div><?php endif; ?>
<form class="row g-2 align-items-end filters mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= select_field('kind', 'Kind', ['isc' => 'Interest on share capital', 'pr' => 'Patronage refund'], $kind, ['class' => 'col-6 col-md-3', 'blank' => 'Both', 'extra' => 'data-no-combo data-autosubmit']) ?>
  <?= select_field('status', 'Status', ['draft' => 'In review (not posted)', 'validated' => 'Reviewed (not posted)', 'approved' => 'Approved', 'posted' => 'Posted', 'cancelled' => 'Reversed'], $status, ['class' => 'col-6 col-md-3', 'blank' => 'Any', 'extra' => 'data-no-combo data-autosubmit']) ?>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover stack-sm dist-batches"><thead><tr><th>Batch</th><th>Kind</th><th>Year</th><th>Period</th><th class="num">Members</th><th class="num">Amount</th><th>Credit</th><th>Prepared by</th><th>Status</th><?php if ($anyDel): ?><th class="dist-del-cell"><span class="visually-hidden">Delete</span></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $b): ?>
  <tr><td data-label="Batch"><a href="<?= h(url('dist.' . $b['kind'], ['id' => $b['id']])) ?>"><?= h($b['batch_no']) ?></a><div class="small text-muted"><?= h((string)$b['description']) ?></div></td>
    <td data-label="Kind"><?= $b['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund' ?></td><td data-label="Year"><?= $b['fiscal_year'] ? (int)$b['fiscal_year'] : '' ?></td>
    <td data-label="Period" class="small"><?= h(fdate($b['date_from'])) ?> to <?= h(fdate($b['date_to'])) ?></td><td data-label="Members" class="num"><?= (int)$b['members_count'] ?></td><td data-label="Amount" class="num"><?= cm($b['total_amount']) ?></td>
    <td data-label="Credit" class="small"><?= h(Distribution::CREDIT_MODES[$b['credit_mode']] ?? '') ?></td><td data-label="Prepared by" class="small"><?= h((string)$b['prepared_by']) ?></td>
    <td data-label="Status"><?= match ($b['status']) {
        'draft' => badge('In review', 'primary') . '<div class="small text-muted">not posted</div>',
        'validated' => badge('Reviewed', 'info') . '<div class="small text-muted">not posted</div>',
        'cancelled' => coop_status('voided'),
        default => coop_status($b['status']),
    } ?></td>
    <?php if ($anyDel): ?><td class="dist-del-cell text-end"><?php if ($canDel($b)): ?><button type="button" class="btn btn-sm btn-link text-danger p-0 dist-del-btn" data-bs-toggle="modal" data-bs-target="#distDelModal"
        data-del-id="<?= (int)$b['id'] ?>" data-del-no="<?= h($b['batch_no']) ?>" data-del-what="<?= h(($b['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund') . ', ' . (int)$b['members_count'] . ' member(s), ' . Dec::money($b['total_amount']) . ', ' . (['draft' => 'in review', 'validated' => 'reviewed', 'approved' => 'approved'][$b['status']] ?? '') . ', not posted') ?>"
        title="Delete this computation (not posted)"><i class="bi bi-trash3"></i> Delete</button><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="text-muted">No batch.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php if ($anyDel): ?><p class="small text-muted mt-2 mb-0">A batch not yet posted (in review, reviewed or approved) can be deleted, with the reason; a posted batch is reversed on its page instead.</p><?= coop_dist_delete_modal('distDelModal', null) ?><?php endif; ?>
<?php
layout_end();
