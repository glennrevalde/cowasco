<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'teller.shift';

handle_post(function () use ($pageKey) {
    $action = (string)p('action');
    if ($action === 'open') {
        Access::require($pageKey, 'create');
        coop_once();
        $id = Teller::open((string)p('business_date'), Coop::amount('opening_cash'), pint('cash_account_id'), $pageKey);
        flash('success', 'Shift ' . $id . ' is open.');
        redirect(url($pageKey));
    }
    if ($action === 'close') {
        coop_once();
        $denoms = (array)($_POST['denoms'] ?? []);
        $r = Teller::close((int)pint('shift_id'), $denoms, trim((string)p('notes')), $pageKey);
        flash(Dec::isZero($r['over_short']) ? 'success' : 'warning', 'Shift closed. Expected ' . Dec::money($r['expected']) . ', counted ' . Dec::money($r['counted'])
            . (Dec::isZero($r['over_short']) ? ', balanced.' : ', ' . (Dec::isPos($r['over_short']) ? 'over ' : 'short ') . Dec::money(Dec::abs($r['over_short'])) . ' posted to Cash Over and Short.'));
        redirect(url($pageKey, ['id' => pint('shift_id')]));
    }
    fail('Unknown action.');
});

$denomLabel = fn(string $dn): string => Dec::cmp($dn, '1') >= 0 ? 'PHP ' . number_format((float)$dn) . (Dec::cmp($dn, '20') >= 0 ? ' bill' : ' coin') : number_format((float)$dn * 100) . ' centavos';

// ------------------------------------------------------------------ cash count sheet of a closed shift
if (g('a') === 'print' && gint('id')) {
    Access::require($pageKey, 'print');
    $s = Teller::shift((int)gint('id'));
    if (!$s || $s['status'] !== 'closed') {
        flash('warning', 'The cash count sheet is printed after the shift is closed.');
        redirect(url($pageKey, gint('id') ? ['id' => gint('id')] : []));
    }
    if ((int)$s['user_id'] !== Auth::id() && !Access::can($pageKey, 'approve')) {
        Access::denyPage($pageKey, 'print the shift of another teller on');
    }
    Audit::log('print', 'coop', 'teller_shift', (int)$s['id'], 'Cash count sheet, shift ' . $s['id'], ['page_key' => $pageKey]);
    $byKind = DB::all("SELECT r.receipt_kind, r.payment_mode, COUNT(*) n, COALESCE(SUM(r.total),0) t FROM coop_receipts r JOIN transactions x ON x.id = r.txn_id WHERE r.shift_id = :s AND x.is_voided = 0 GROUP BY r.receipt_kind, r.payment_mode ORDER BY r.receipt_kind DESC, r.payment_mode", ['s' => $s['id']]);
    $dj = $s['denominations_json'] ? (array)json_decode($s['denominations_json'], true) : [];
    $closer = $s['closed_by'] ? (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $s['closed_by']]) : '';
    layout_start('Cash count ' . $s['id'], ['bare' => true]);
    ?>
    <div class="no-print mb-2"><a href="<?= h(url($pageKey, ['id' => $s['id']])) ?>">Back to the shift</a></div>
    <div class="doc coop-doc">
      <?= coop_print_head('CASH COUNT SHEET', (string)$s['id'], $s['business_date'], ['Teller' => $s['teller_name'], 'Drawer' => $s['cash_code'] . ' ' . $s['cash_name'], 'Branch' => Coop::branchName($s['branch_id'] ? (int)$s['branch_id'] : null)]) ?>
      <div class="row g-4 mt-2">
        <div class="col-6">
          <table class="table table-sm"><thead><tr><th>Denomination</th><th class="num">Pieces</th><th class="num">Amount</th></tr></thead><tbody>
          <?php $pieces = 0; foreach ($dj as $dn => $n): if (!(int)$n) { continue; } $pieces++; ?>
            <tr><td><?= h($denomLabel((string)$dn)) ?></td><td class="num"><?= (int)$n ?></td><td class="num"><?= cm(Dec::mul((string)$dn, (string)$n, 2)) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$pieces): ?><tr><td colspan="3" class="text-muted">Counted as a total, not by piece.</td></tr><?php endif; ?>
          </tbody><tfoot><tr><td colspan="2" class="fw-semibold">Cash counted</td><td class="num fw-semibold"><?= cm($s['counted_cash']) ?></td></tr></tfoot></table>
        </div>
        <div class="col-6">
          <table class="table table-sm"><tbody>
            <tr><td>Beginning cash</td><td class="num"><?= cm($s['opening_cash']) ?></td></tr>
            <tr><td>Add: cash received</td><td class="num"><?= cm($s['receipts_total']) ?></td></tr>
            <tr><td>Less: cash paid out</td><td class="num"><?= cm($s['disbursements_total']) ?></td></tr>
            <tr><td class="fw-semibold">Expected in the drawer</td><td class="num fw-semibold"><?= cm($s['expected_cash']) ?></td></tr>
            <tr><td>Cash counted</td><td class="num"><?= cm($s['counted_cash']) ?></td></tr>
            <tr><td class="fw-semibold"><?= Dec::isZero($s['over_short']) ? 'Difference' : (Dec::isPos($s['over_short']) ? 'Over' : 'Short') ?></td><td class="num fw-semibold"><?= cm(Dec::abs((string)$s['over_short'])) ?></td></tr>
          </tbody></table>
          <?php if ($byKind): ?><table class="table table-sm mt-3"><thead><tr><th>Documents</th><th>Paid by</th><th class="num">Count</th><th class="num">Amount</th></tr></thead><tbody>
            <?php foreach ($byKind as $k): ?><tr><td><?= h(Teller::KIND_LABELS[$k['receipt_kind']] ?? $k['receipt_kind']) ?></td><td><?= h(ucfirst($k['payment_mode'])) ?></td><td class="num"><?= (int)$k['n'] ?></td><td class="num"><?= cm($k['t']) ?></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div>
      </div>
      <p class="small mt-2">Opened <?= h(fdatetime($s['opened_at'])) ?>, closed <?= h(fdatetime($s['closed_at'])) ?>.<?= $s['over_short_txn_id'] ? ' Over/short voucher ' . h((string)Txn::load((int)$s['over_short_txn_id'])['txn_no']) . '.' : '' ?><?= $s['notes'] ? ' ' . h($s['notes']) : '' ?></p>
      <?= coop_signatures(['Counted by (teller)' => $s['teller_name'], 'Verified by' => $closer !== $s['teller_name'] ? $closer : '', 'Received by (treasurer)' => Coop::setting('signatory_treasurer')]) ?>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

$view = gint('id') ? Teller::shift((int)gint('id')) : null;
if ($view && (int)$view['user_id'] !== Auth::id() && !Access::can($pageKey, 'approve')) {
    Access::denyPage($pageKey, 'open the shift of another teller on');
}
$mine = Teller::openShift();
$s = $view ?: ($mine ? Teller::shift((int)$mine['id']) : null);
if ($s) {
    Teller::refreshShift((int)$s['id']);
    $s = Teller::shift((int)$s['id']);
}
$canSeeAll = Access::can($pageKey, 'approve');
$recent = DB::all('SELECT s.*, u.full_name, a.code AS cash_code FROM teller_shifts s JOIN users u ON u.id = s.user_id JOIN accounts a ON a.id = s.cash_account_id'
    . ($canSeeAll ? '' : ' WHERE s.user_id = ' . (int)Auth::id()) . ' ORDER BY s.status = \'open\' DESC, s.id DESC LIMIT 25');
coop_layout('Teller Shift & Cash Count', ['actions' => ($s && $s['status'] === 'closed' ? page_link($pageKey, ['a' => 'print', 'id' => $s['id']], 'Cash count sheet', 'btn-outline-secondary', 'printer', 'print') . ' ' : '')
    . page_link('teller.or', [], 'Official receipt', 'btn-outline-primary', 'receipt', 'create')]);
?>
<div class="row g-3">
  <div class="col-xl-7">
  <?php if (!$s): ?>
    <?php if (Access::can($pageKey, 'create')): $cash = Teller::userCashAccount(); ?>
    <form method="post" class="card" data-once><div class="card-header">Open your shift</div><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="open">
      <?= input_field('business_date', 'Business date', today(), ['class' => 'col-md-4', 'type' => 'date', 'required' => true]) ?>
      <?= coop_money('opening_cash', 'Beginning cash in the drawer', '0', ['class' => 'col-md-4']) ?>
      <?= select_field('cash_account_id', 'Cash account (drawer)', Accts::ofType(['Bank']), $cash, ['class' => 'col-md-4', 'extra' => Access::can($pageKey, 'approve') ? '' : 'disabled']) ?>
      <div class="col-12 small text-muted">Every cash receipt and payment you post goes to this drawer. At the end of the day count the cash by denomination; a difference is posted to Cash Over and Short<?= Dec::isPos(Coop::setting('over_short_tolerance', '0')) ? ' (above ' . cm(Coop::setting('over_short_tolerance')) . ' it needs a supervisor)' : ' with a supervisor\'s approval' ?>.</div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-unlock"></i> Open shift</button></div>
    </div></form>
    <?php else: ?><div class="alert alert-light">You have no open shift.</div><?php endif; ?>
  <?php else:
      $expected = Teller::expectedCash($s);
      $open = $s['status'] === 'open';
      $byKind = DB::all("SELECT r.receipt_kind, r.payment_mode, COUNT(*) n, COALESCE(SUM(r.total),0) t FROM coop_receipts r JOIN transactions x ON x.id = r.txn_id WHERE r.shift_id = :s AND x.is_voided = 0 GROUP BY r.receipt_kind, r.payment_mode", ['s' => $s['id']]);
  ?>
    <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span>Shift <?= (int)$s['id'] ?>, <?= h($s['teller_name']) ?></span><span><?= coop_status($s['status']) ?></span></div><div class="card-body">
      <?= coop_kv(['Business date' => h(fdate($s['business_date'])), 'Drawer' => h($s['cash_code'] . ' ' . $s['cash_name']), 'Opened' => h(fdatetime($s['opened_at'])), 'Closed' => $s['closed_at'] ? h(fdatetime($s['closed_at'])) : '',
          'Branch' => h(Coop::branchName($s['branch_id'] ? (int)$s['branch_id'] : null))]) ?>
      <div class="coop-tiles mt-3 mb-0">
        <div class="coop-tile"><div class="t-label">Beginning cash</div><div class="t-value"><?= cm($s['opening_cash']) ?></div></div>
        <div class="coop-tile is-ok"><div class="t-label">Cash received</div><div class="t-value"><?= cm($s['receipts_total']) ?></div></div>
        <div class="coop-tile is-warn"><div class="t-label">Cash paid out</div><div class="t-value"><?= cm($s['disbursements_total']) ?></div></div>
        <div class="coop-tile"><div class="t-label">Expected in the drawer</div><div class="t-value"><?= cm($expected) ?></div></div>
        <?php if (!$open): ?><div class="coop-tile <?= Dec::isZero($s['over_short']) ? 'is-ok' : 'is-bad' ?>"><div class="t-label">Counted</div><div class="t-value"><?= cm($s['counted_cash']) ?></div><div class="t-sub"><?= Dec::isZero($s['over_short']) ? 'balanced' : (Dec::isPos($s['over_short']) ? 'over ' : 'short ') . cm(Dec::abs($s['over_short'])) ?></div></div><?php endif; ?>
      </div>
      <?php if ($byKind): ?><table class="table table-sm mt-3 mb-0"><thead><tr><th>Kind</th><th>Paid by</th><th class="num">Count</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($byKind as $k): ?><tr><td><?= h(Teller::KIND_LABELS[$k['receipt_kind']] ?? $k['receipt_kind']) ?></td><td><?= h(ucfirst($k['payment_mode'])) ?></td><td class="num"><?= (int)$k['n'] ?></td><td class="num"><?= cm($k['t']) ?></td></tr><?php endforeach; ?>
      </tbody></table><div class="small text-muted mt-1">Only cash counts toward the drawer; checks and bank payments are listed for the deposit slip.</div><?php endif; ?>
    </div></div>
    <?php if ($open && ((int)$s['user_id'] === Auth::id() || Access::can($pageKey, 'approve'))): ?>
    <form method="post" class="card" data-once data-confirm="Close the shift with the cash counted? A difference is posted to Cash Over and Short." data-confirm-title="Close the shift" data-confirm-ok="Close shift">
      <div class="card-header">Cash count by denomination</div><div class="card-body" data-denoms data-expected="<?= h(Dec::fix($expected)) ?>">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="close"><input type="hidden" name="shift_id" value="<?= (int)$s['id'] ?>">
      <div class="row g-3"><div class="col-md-7"><table class="table table-sm denoms"><thead><tr><th>Denomination</th><th class="num">Pieces</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach (Teller::DENOMINATIONS as $dn): ?><tr><td><?= Dec::cmp($dn, '1') >= 0 ? 'PHP ' . number_format((float)$dn) . (Dec::cmp($dn, '20') >= 0 ? ' bill' : ' coin') : number_format((float)$dn * 100) . ' centavos' ?></td>
          <td><input class="form-control form-control-sm" type="number" min="0" step="1" name="denoms[<?= h($dn) ?>]" data-value="<?= h($dn) ?>" inputmode="numeric"></td><td class="num" data-line></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="col-md-5">
        <div class="or-total" style="grid-template-columns:1fr"><div><span>Counted</span><strong data-denoms-total>0.00</strong></div><div><span>Expected</span><strong><?= cm($expected) ?></strong></div><div><span>Difference</span><strong data-denoms-diff></strong></div></div>
        <label class="form-label mt-3">Or type the total counted</label><input class="form-control form-control-sm text-end" name="denoms[_total]" inputmode="decimal" placeholder="only when not counting by piece">
        <label class="form-label mt-2">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="2"></textarea>
        <?= Approval::fields() ?>
        <button class="btn btn-primary w-100 mt-3"><i class="bi bi-lock"></i> Close the shift</button>
      </div></div>
    </div></form>
    <?php elseif (!$open && $s['denominations_json']): $dj = (array)json_decode($s['denominations_json'], true); ?>
    <div class="card"><div class="card-header">Cash count</div><table class="table table-sm"><tbody>
      <?php foreach ($dj as $dn => $n): if (!(int)$n) { continue; } ?><tr><td><?= h($denomLabel((string)$dn)) ?></td><td class="num"><?= (int)$n ?></td><td class="num"><?= cm(Dec::mul($dn, (string)$n, 2)) ?></td></tr><?php endforeach; ?>
    </tbody></table><?= $s['notes'] ? '<div class="card-body small">' . h($s['notes']) . '</div>' : '' ?><?= $s['over_short_txn_id'] ? '<div class="card-body small pt-0">Over/short voucher: ' . Txn::link((int)$s['over_short_txn_id']) . '</div>' : '' ?></div>
    <?php endif; ?>
  <?php endif; ?>
  </div>
  <div class="col-xl-5"><div class="card"><div class="card-header"><?= $canSeeAll ? 'Shifts' : 'Your shifts' ?></div><table class="table table-sm table-hover"><thead><tr><th>Shift</th><th>Teller</th><th>Date</th><th class="num">Expected</th><th class="num">Over / short</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?><tr><td><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>"><?= (int)$r['id'] ?></a> <?= $r['status'] === 'open' ? '<span class="small text-primary">open</span>' : '' ?></td><td class="small"><?= h($r['full_name']) ?></td><td><?= h(fdate($r['business_date'])) ?></td>
      <td class="num"><?= $r['status'] === 'open' ? cm(Teller::expectedCash($r)) : cm($r['expected_cash']) ?></td><td class="num <?= Dec::isNeg($r['over_short']) ? 'neg' : '' ?>"><?= $r['status'] === 'open' ? '' : cm($r['over_short'], true) ?></td></tr><?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="5" class="text-muted">No shift yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php
layout_end();
