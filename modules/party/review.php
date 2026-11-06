<?php
declare(strict_types=1);

/**
 * Identity Review: possible duplicate parties (same TIN, e-mail, mobile or phone number, name and date of birth, or name)
 * are proposed here and decided by a person. Linking two parties needs the link-or-move permission, a reason and the
 * page's approve right (or a supervisor's approval); it moves every role record and document of one party to the
 * other and keeps the old Party ID in the crosswalk. Nothing is ever linked by the system on its own.
 */
$pageKey = 'party.review';
if (!Party::ready()) {
    layout_start('Identity Review');
    echo '<div class="alert alert-warning">The Party Master needs a database update. Import <code>database/migrations/2026_11_15_multipurpose_party.sql</code>, then reload this page.</div>';
    layout_end();
    return;
}

handle_post(function () use ($pageKey) {
    $action = (string)p('action');
    $back = current_url();
    if ($action === 'scan') {
        Access::require($pageKey, 'create');
        $n = Party::findMatches();
        Audit::log('scan', 'party', 'party_match', null, 'Identity review scan', ['page_key' => $pageKey, 'reason' => $n . ' new possible duplicate(s)']);
        flash('success', $n ? $n . ' new possible duplicate' . ($n > 1 ? 's' : '') . ' found.' : 'No new possible duplicates.');
        redirect($back);
    }
    if ($action === 'scan_party') {
        // the possible duplicates of one party (from the Party Center): only suggestions are recorded
        $focusId = (int)pint('party_id');
        $n = Party::proposeFor($focusId);
        if ($n) {
            Audit::log('scan', 'party', 'party_match', $focusId, 'Identity review of one party', ['page_key' => $pageKey, 'reason' => $n . ' new possible duplicate(s)']);
        }
        redirect(url($pageKey, ['party' => $focusId]));
    }
    if ($action === 'distinct') {
        Access::require($pageKey, 'edit');
        Party::markDistinct((int)pint('review_id'), trim((string)p('reason')), $pageKey);
        flash('success', 'Recorded as two different parties.');
        redirect($back);
    }
    if ($action === 'merge') {
        Access::require($pageKey, 'edit');
        if (!Access::special('party_merge')) {
            fail('You are not allowed to link parties (special permission: link or move parties).');
        }
        $keep = (int)pint('keep_id');
        $drop = (int)pint('drop_id');
        if (!$keep || !$drop) {
            fail('Choose the two parties.');
        }
        $approver = Approval::check($pageKey, true, 'link two parties into one');
        Party::merge($keep, $drop, trim((string)p('reason')), $approver ? (int)$approver['id'] : null, $pageKey);
        flash('success', 'The parties were linked. The party kept has every role and document now.');
        redirect(url('party.center', ['id' => $keep]));
    }
    fail('Unknown action.');
});

$status = in_array(g('status'), ['open', 'merged', 'distinct', 'all'], true) ? (string)g('status') : 'open';
$focus = gint('party');
$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 'r.status = :st';
    $params['st'] = $status;
}
if ($focus) {
    $where[] = '(r.party_a = :f1 OR r.party_b = :f2)';
    $params += ['f1' => $focus, 'f2' => $focus];
}
$pairs = DB::all('SELECT r.*, u.username AS decided_by_name FROM party_match_reviews r LEFT JOIN users u ON u.id = r.decided_by'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.status = \'open\' DESC, r.score DESC, r.id LIMIT 200', $params);
$ids = [];
foreach ($pairs as $r) {
    $ids[] = (int)$r['party_a'];
    $ids[] = (int)$r['party_b'];
}
$parties = [];
if ($ids) {
    [$in, $ip] = DB::in('pi', array_values(array_unique($ids)));
    foreach (DB::all("SELECT * FROM parties WHERE id IN $in", $ip) as $p) {
        $parties[(int)$p['id']] = Party::visible($p);
    }
}
$roles = Party::roleNamesAll(array_values(array_unique($ids)));
$counts = DB::pairs('SELECT status, COUNT(*) FROM party_match_reviews GROUP BY status');
$canMerge = Access::can($pageKey, 'edit') && Access::special('party_merge');
$canDecide = Access::can($pageKey, 'edit');

$actions = '';
if (Access::can($pageKey, 'create')) {
    $actions = '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="scan"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Look for duplicates now</button></form>';
}
$actions .= ' ' . btn_link(url('party.center'), 'Party Center', 'btn-outline-secondary', 'arrow-left');
layout_start('Identity Review', ['actions' => $actions]);
$fact = function (array $p, string $k, string $label): string {
    $v = $p[$k] ?? null;
    if ($v === null || $v === '') {
        return '';
    }
    if ($k === 'birth_date' && empty($p['_masked'])) {
        $v = fdate($v);
    }
    return '<div><dt>' . h($label) . '</dt><dd>' . h((string)$v) . '</dd></div>';
};
?>
<p class="text-muted small">Records are never joined by name alone. Each pair below shares something (a TIN, an e-mail, a mobile or phone number, a name and date of birth, or a name).
  Decide whether they are the same person or organization: linking keeps one party and moves every role and document of the other to it; the other party's number keeps pointing to it.</p>
<ul class="nav nav-tabs mb-3">
  <?php foreach (['open' => 'To review', 'merged' => 'Linked', 'distinct' => 'Different', 'all' => 'All'] as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link <?= $status === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['status' => $k, 'party' => $focus])) ?>"><?= h($lbl) ?><?= $k !== 'all' ? ' (' . (int)($counts[$k] ?? 0) . ')' : '' ?></a></li>
  <?php endforeach; ?>
</ul>
<?php if ($focus): ?><div class="small mb-2">Showing pairs with party <?= h((string)DB::value('SELECT CONCAT(party_no, \' \', display_name) FROM parties WHERE id = :p', ['p' => $focus])) ?>. <a href="<?= h(url($pageKey, ['status' => $status])) ?>">Show every pair</a></div><?php endif; ?>
<?php if (!$pairs): ?>
  <div class="card"><div class="card-body text-muted">Nothing here.<?= $status === 'open' ? ' Use "Look for duplicates now" after adding or importing records.' : '' ?></div></div>
<?php endif; ?>
<div class="review-pairs">
<?php foreach ($pairs as $r):
    $pa = $parties[(int)$r['party_a']] ?? null;
    $pb = $parties[(int)$r['party_b']] ?? null;
    if (!$pa || !$pb) {
        continue;
    }
?>
  <div class="card mb-3 review-pair">
    <div class="card-header d-flex flex-wrap justify-content-between gap-2">
      <span class="fw-semibold"><?= h($r['basis']) ?></span>
      <span class="small text-muted"><?= $r['status'] === 'open' ? 'Score ' . (int)$r['score'] : h(($r['status'] === 'merged' ? 'Linked' : 'Different') . ', ' . fdatetime($r['decided_at']) . ($r['decided_by_name'] ? ', ' . $r['decided_by_name'] : '') . ($r['reason'] ? '. ' . $r['reason'] : '')) ?></span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <?php foreach ([$pa, $pb] as $p): ?>
        <div class="col-md-6">
          <div class="review-side">
            <div class="fw-semibold"><a href="<?= h(url('party.center', ['id' => $p['id']])) ?>"><?= h($p['display_name']) ?></a></div>
            <div class="small text-muted"><?= h($p['party_no']) ?>, <?= h(strtolower(Party::TYPES[$p['party_type']] ?? '')) ?><?= $p['status'] !== 'active' ? ', ' . h(strtolower(Party::STATUSES[$p['status']] ?? $p['status'])) : '' ?></div>
            <div class="small mt-1"><?= h(implode(', ', $roles[(int)$p['id']] ?? ['No role'])) ?></div>
            <dl class="kv party-facts small mt-2 mb-0">
              <?= $fact($p, 'legal_name', 'Legal name') ?><?= $fact($p, 'tin', 'TIN') ?><?= $fact($p, 'birth_date', 'Date of birth') ?>
              <?= $fact($p, 'mobile', 'Mobile') ?><?= (string)($p['phone'] ?? '') !== (string)($p['mobile'] ?? '') ? $fact($p, 'phone', 'Phone') : '' ?><?= $fact($p, 'email', 'E-mail') ?><?= $fact($p, 'address', 'Address') ?>
            </dl>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if ($r['status'] === 'open' && $pa['status'] !== 'merged' && $pb['status'] !== 'merged' && ($canMerge || $canDecide)): ?>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if ($canMerge): ?>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mergeModal" data-keep="<?= (int)$pa['id'] ?>" data-drop="<?= (int)$pb['id'] ?>"
                  data-what="<?= h('Keep ' . $pa['display_name'] . ' (' . $pa['party_no'] . ') and move ' . $pb['party_no'] . ' into it') ?>">Same: keep <?= h($pa['party_no']) ?></button>
          <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mergeModal" data-keep="<?= (int)$pb['id'] ?>" data-drop="<?= (int)$pa['id'] ?>"
                  data-what="<?= h('Keep ' . $pb['display_name'] . ' (' . $pb['party_no'] . ') and move ' . $pa['party_no'] . ' into it') ?>">Same: keep <?= h($pb['party_no']) ?></button>
        <?php endif; ?>
        <?php if ($canDecide): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#distinctModal" data-review="<?= (int)$r['id'] ?>">Different</button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php if ($canMerge): ?>
<div class="card mt-2">
  <div class="card-header fw-semibold">Link two parties found another way</div>
  <form class="card-body row g-2" method="post" action="<?= h(current_url()) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="merge">
    <?php $opts = Party::options(); ?>
    <?= select_field('keep_id', 'Party to keep', $opts, fv('keep_id', null), ['class' => 'col-md-6', 'blank' => '', 'extra' => 'data-combo']) ?>
    <?= select_field('drop_id', 'Party to move into it', $opts, fv('drop_id', null), ['class' => 'col-md-6', 'blank' => '', 'extra' => 'data-combo']) ?>
    <div class="col-md-8"><label class="form-label">Reason (what shows they are the same)</label><input class="form-control form-control-sm" name="reason" required maxlength="255" value="<?= h((string)fv('reason', null)) ?>"></div>
    <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-primary">Link the parties</button></div>
    <div class="col-12"><?= Approval::fields() ?></div>
  </form>
</div>

<div class="modal fade" id="mergeModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= h(current_url()) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="merge"><input type="hidden" name="keep_id" value=""><input type="hidden" name="drop_id" value="">
  <div class="modal-header"><h5 class="modal-title">Link the parties</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p class="small" data-what></p>
    <label class="form-label">Reason (what shows they are the same)</label><textarea class="form-control" name="reason" rows="2" required></textarea>
    <div class="small text-muted mt-2">Every role record and document moves to the party kept. Names already printed on documents stay as issued.</div>
    <?= Approval::fields() ?></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Link</button></div>
</form></div></div>
<?php endif; ?>
<?php if ($canDecide): ?>
<div class="modal fade" id="distinctModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= h(current_url()) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="distinct"><input type="hidden" name="review_id" value="">
  <div class="modal-header"><h5 class="modal-title">Two different parties</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">Reason (what shows they are different)</label><textarea class="form-control" name="reason" rows="2" required></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Record</button></div>
</form></div></div>
<?php endif; ?>
<script>
(function () {
  const mm = document.getElementById('mergeModal');
  if (mm) mm.addEventListener('show.bs.modal', (e) => { const b = e.relatedTarget; if (!b) return; const f = mm.querySelector('form'); f.elements.keep_id.value = b.dataset.keep; f.elements.drop_id.value = b.dataset.drop; mm.querySelector('[data-what]').textContent = b.dataset.what; });
  const dm = document.getElementById('distinctModal');
  if (dm) dm.addEventListener('show.bs.modal', (e) => { const b = e.relatedTarget; if (b) dm.querySelector('form').elements.review_id.value = b.dataset.review; });
})();
</script>
<?php
layout_end();
