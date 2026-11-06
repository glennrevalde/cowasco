<?php
declare(strict_types=1);

/**
 * Journal Entry Changes: the changes to saved journal entries that wait for approval, and the ones decided.
 * An approver compares the entry as it is with the entry as proposed, then approves (the entry is saved with the
 * change) or rejects with the reason. The requester may withdraw a change that is still waiting.
 */
require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'coa.je_edits';
if (!JournalEdits::ready()) {
    layout_start('Journal Entry Changes');
    echo '<div class="alert alert-warning">Run the upgrade script (database/migrations/2026_12_20_member_lists_class_je_changes.sql) first.</div>';
    layout_end();
    return;
}
$id = gint('id');
$r = $id ? JournalEdits::get((int)$id) : null;

handle_post(function () use ($r, $pageKey) {
    if (!$r) {
        fail('Request not found.');
    }
    $action = (string)p('action');
    if ($action === 'approve') {
        if (!JournalEdits::canDecide()) {
            fail('Approving changes to journal entries needs the Approve right on Make Journal Entries.');
        }
        JournalEdits::approve((int)$r['id'], trim((string)p('remarks')));
        flash('success', 'The change was approved and journal entry ' . $r['txn_no'] . ' was saved with it.');
        redirect(url('coa.journal', ['a' => 'view', 'id' => $r['txn_id']]));
    }
    if ($action === 'reject') {
        JournalEdits::reject((int)$r['id'], (string)p('remarks'));
        flash('success', 'The change to ' . $r['txn_no'] . ' was rejected. The entry is unchanged.');
        redirect(url($pageKey));
    }
    if ($action === 'withdraw') {
        JournalEdits::withdraw((int)$r['id']);
        flash('success', 'Your change to ' . $r['txn_no'] . ' was withdrawn.');
        redirect(url($pageKey));
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ one request
if ($r) {
    $canDecide = JournalEdits::canDecide() && (int)$r['requested_by'] !== Auth::id();
    layout_start('Change to journal entry ' . $r['txn_no'], ['actions' => btn_link(url('coa.journal', ['a' => 'view', 'id' => $r['txn_id']]), 'Open the entry', 'btn-outline-secondary', 'journal-text')
        . ' ' . btn_link(url($pageKey), 'All changes', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="row g-3">
      <div class="col-xl-4">
        <div class="card mb-3"><div class="card-body">
          <div class="je-req-status is-<?= h($r['status']) ?>"><?= h(JournalEdits::STATUSES[$r['status']] ?? $r['status']) ?></div>
          <dl class="kv mt-2">
            <div><dt>Entry</dt><dd><a href="<?= h(url('coa.journal', ['a' => 'view', 'id' => $r['txn_id']])) ?>"><?= h(Txn::label((string)$r['txn_type']) . ' ' . $r['txn_no']) ?></a>, <?= h(fdate($r['txn_date'])) ?></dd></div>
            <div><dt>Requested by</dt><dd><?= h((string)$r['requester']) ?>, <?= h(fdatetime($r['requested_at'])) ?></dd></div>
            <div><dt>Why</dt><dd><?= nl2br(h($r['explanation'])) ?></dd></div>
            <?php if ($r['decided_by'] && $r['status'] !== 'superseded'): ?><div><dt><?= $r['status'] === 'withdrawn' ? 'Withdrawn by' : 'Decided by' ?></dt><dd><?= h((string)$r['decider']) ?>, <?= h(fdatetime((string)$r['decided_at'])) ?></dd></div><?php endif; ?>
            <?php if ($r['decision_remarks']): ?><div><dt>Remarks</dt><dd><?= h($r['decision_remarks']) ?></dd></div><?php endif; ?>
          </dl>
        </div></div>
        <?php if ($r['status'] === 'pending'): ?>
          <?php if ($canDecide): ?>
          <form method="post" class="card mb-3"><div class="card-header">Decision</div><div class="card-body row g-2">
            <?= csrf_field() ?>
            <div class="col-12"><label class="form-label" for="decRemarks">Remarks (required to reject)</label>
              <textarea class="form-control form-control-sm" id="decRemarks" name="remarks" rows="2" maxlength="255"></textarea></div>
            <div class="col-12 d-flex flex-wrap gap-2">
              <button class="btn btn-success btn-sm" name="action" value="approve" data-confirm="Approve the change? Journal entry <?= h($r['txn_no']) ?> is saved with it at once." data-confirm-title="Approve the change" data-confirm-ok="Approve"><i class="bi bi-check2-circle"></i> Approve and apply</button>
              <button class="btn btn-outline-danger btn-sm" name="action" value="reject" data-confirm="Reject the change? The entry stays as it is." data-confirm-ok="Reject" data-confirm-tone="danger"><i class="bi bi-x-circle"></i> Reject</button>
            </div>
            <div class="col-12 small text-muted">Approving saves the entry with the proposed lines through the posting engine, under the closing date and the audit trail, with the explanation as the reason.</div>
          </div></form>
          <?php elseif ((int)$r['requested_by'] === Auth::id()): ?>
          <div class="card mb-3"><div class="card-body small">
            <p class="mb-2">Your change waits for a user with the Approve right on Make Journal Entries.</p>
            <form method="post"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm" name="action" value="withdraw" data-confirm="Withdraw your change? The entry stays as it is." data-confirm-ok="Withdraw"><i class="bi bi-arrow-counterclockwise"></i> Withdraw the request</button></form>
          </div></div>
          <?php else: ?>
          <div class="alert alert-light small">Approving needs the Approve right on Make Journal Entries.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="col-xl-8">
        <div class="card"><div class="card-header">Before and after</div><div class="card-body"><?= JournalEdits::comparison($r) ?></div></div>
      </div>
    </div>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
$status = array_key_exists((string)g('status'), JournalEdits::STATUSES) || g('status') === 'all' ? (string)g('status') : 'pending';
$where = $status === 'all' ? '1 = 1' : 'r.status = :st';
$params = $status === 'all' ? [] : ['st' => $status];
if (!JournalEdits::canDecide() && !Access::isAdmin()) {
    // a user who cannot approve sees their own requests
    $where .= ' AND r.requested_by = :me';
    $params['me'] = Auth::id();
}
$rows = DB::all("SELECT r.*, t.txn_no, t.txn_type, t.txn_date, t.total, ur.full_name AS requester, ud.full_name AS decider
                   FROM journal_edit_requests r JOIN transactions t ON t.id = r.txn_id
                   LEFT JOIN users ur ON ur.id = r.requested_by LEFT JOIN users ud ON ud.id = r.decided_by
                  WHERE $where ORDER BY r.id DESC LIMIT 300", $params);
$counts = DB::pairs('SELECT status, COUNT(*) FROM journal_edit_requests GROUP BY status');
layout_start('Journal Entry Changes', ['actions' => page_link('coa.journal', [], 'Journal list', 'btn-outline-secondary', 'list-ul')]);
?>
<p class="text-muted small">A change to a saved journal entry is made only when a second user approves it. Open a change to compare the entry as it is with the entry as proposed.</p>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['pending' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'all' => 'All'] as $k => $label): ?>
    <li class="nav-item"><a class="nav-link<?= $status === $k ? ' active' : '' ?>" href="<?= h(url($pageKey, ['status' => $k])) ?>"><?= h($label) ?><?= $k !== 'all' && !empty($counts[$k]) ? ' (' . (int)$counts[$k] . ')' : '' ?></a></li>
  <?php endforeach; ?>
</ul>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
  <thead><tr><th>Requested</th><th>Entry</th><th>Why</th><th>Status</th><th>Decided</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $x): ?>
    <tr><td class="small"><?= h((string)$x['requester']) ?><div class="text-muted"><?= h(fdatetime($x['requested_at'])) ?></div></td>
      <td><a href="<?= h(url('coa.journal', ['a' => 'view', 'id' => $x['txn_id']])) ?>"><?= h($x['txn_no']) ?></a><div class="small text-muted"><?= h(fdate($x['txn_date'])) ?>, <?= h(money((float)$x['total'])) ?></div></td>
      <td class="small"><?= h(mb_strimwidth((string)$x['explanation'], 0, 140, '...')) ?></td>
      <td class="small je-req-status is-<?= h($x['status']) ?>"><?= h(JournalEdits::STATUSES[$x['status']] ?? $x['status']) ?></td>
      <td class="small"><?= $x['decider'] && $x['status'] !== 'superseded' ? h((string)$x['decider']) . '<div class="text-muted">' . h(fdatetime((string)$x['decided_at'])) . '</div>' : '' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['id' => $x['id']])) ?>"><?= $x['status'] === 'pending' && JournalEdits::canDecide() && (int)$x['requested_by'] !== Auth::id() ? 'Review' : 'Open' ?></a></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-muted p-3"><?= $status === 'pending' ? 'No change is waiting for approval.' : 'None.' ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php
layout_end();
