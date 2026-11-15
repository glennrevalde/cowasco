<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'pos.receipts';
$a = g('a', 'list');

if ($a === 'print') {
    // POS documents print as till receipts
    redirect(url('pos.register', ['a' => 'receipt', 'id' => (int)g('id'), 'reprint' => 1]));
}
if (txn_common_actions($pageKey, ['pos_sale', 'pos_return'])) {
    return;
}

if ($a === 'return') {
    Access::require($pageKey, 'create');
    $sale = Txn::load((int)g('id'));
    if (!$sale || $sale['txn_type'] !== 'pos_sale' || (int)$sale['is_voided'] === 1 || $sale['status'] !== 'completed') {
        flash('warning', 'Choose a completed sale to return.');
        redirect(url($pageKey));
    }
    $terminal = Pos::terminal();
    $shift = $terminal ? Pos::openShift((int)$terminal['id']) : null;
    $lines = Txn::lines((int)$sale['id']);
    foreach ($lines as &$l) {
        $l['returned'] = (float)DB::value(
            "SELECT COALESCE(SUM(rl.qty),0) FROM transaction_lines rl JOIN transactions r ON r.id = rl.txn_id
              WHERE rl.source_line_id = :l AND r.txn_type = 'pos_return' AND r.is_voided = 0", ['l' => $l['id']]);
    }
    unset($l);
    // item groups are returned as groups: their items and their price variance in the same proportion
    $display = ItemGroups::ready() ? ItemGroups::collapse($lines) : $lines;
    foreach ($display as &$d) {
        if (!empty($d['_group'])) {
            $done = null;
            foreach ($d['_members'] as $m) {
                $per = $d['group_qty'] > 0 ? (float)$m['qty'] / $d['group_qty'] : 0;
                $back = $per > 0 ? (float)$m['returned'] / $per : 0;
                $done = $done === null ? $back : min($done, $back);
            }
            $d['returned'] = round((float)$done, 4);
        }
    }
    unset($d);
    handle_post(function () use ($sale, $lines, $display, $terminal, $shift, $pageKey) {
        if (!$terminal || !$shift) {
            fail('Open a shift on your terminal before processing returns.');
        }
        $rows = [];
        foreach ($display as $g) {
            if (empty($g['_group'])) {
                continue;
            }
            $q = to_num($_POST['retg'][$g['group_ref']] ?? 0);
            if ($q <= 0) {
                continue;
            }
            if ($q > (float)$g['group_qty'] - (float)$g['returned'] + 0.00001) {
                fail('You cannot return more than was sold of ' . $g['item_code'] . '.');
            }
            $share = (float)$g['group_qty'] > 0 ? $q / (float)$g['group_qty'] : 0;
            $tags = ['group_item_id' => $g['group_item_id'], 'group_ref' => $g['group_ref'], 'group_qty' => $q, 'group_rate' => $g['group_rate']];
            foreach (array_merge($g['_members'], $g['_variance'] ? [$g['_variance']] : []) as $m) {
                $isVar = ($m['group_role'] ?? '') === 'variance';
                $mq = $isVar ? 0.0 : round((float)$m['qty'] * $share, 4);
                $amount = $isVar ? r2((float)$m['amount'] * $share) : r2($mq * (float)$m['rate']);
                $rows[] = $tags + [
                    'group_role' => $isVar ? 'variance' : 'member',
                    'line_kind' => 'item', 'item_id' => $m['item_id'], 'account_id' => $m['account_id'], 'description' => $m['description'], 'qty' => $mq, 'rate' => $m['rate'],
                    'discount_pct' => 0, 'amount' => $amount, 'tax_code_id' => $m['tax_code_id'],
                    'tax_amount' => r2($amount * tax_rate($m['tax_code_id'] ? (int)$m['tax_code_id'] : null) / 100),
                    'site_id' => $terminal['site_id'], 'source_line_id' => $isVar ? null : $m['id'],
                ];
            }
        }
        foreach ($lines as $l) {
            if (!empty($l['group_ref']) && ItemGroups::ready()) {
                continue;
            }
            $q = to_num($_POST['ret'][$l['id']] ?? 0);
            if ($q <= 0) {
                continue;
            }
            if ($q > (float)$l['qty'] - $l['returned'] + 0.00001) {
                fail('You cannot return more than was sold of ' . $l['item_code'] . '.');
            }
            $amount = r2($q * (float)$l['rate'] * (1 - (float)$l['discount_pct'] / 100));
            $rows[] = [
                'line_kind' => 'item', 'item_id' => $l['item_id'], 'description' => $l['description'], 'qty' => $q, 'rate' => $l['rate'],
                'discount_pct' => $l['discount_pct'], 'amount' => $amount, 'tax_code_id' => $l['tax_code_id'],
                'tax_amount' => r2($amount * tax_rate($l['tax_code_id'] ? (int)$l['tax_code_id'] : null) / 100),
                'site_id' => $terminal['site_id'], 'source_line_id' => $l['id'],
            ];
        }
        if (!$rows) {
            fail('Enter the quantities being returned.');
        }
        if (trim((string)p('reason')) === '') {
            fail('Enter the reason for the return.');
        }
        $approver = Approval::check($pageKey, true, 'refund / return');
        $total = r2(array_sum(array_map(fn($r) => $r['amount'] + $r['tax_amount'], $rows)));
        $resaleable = p('resaleable') === '1';
        $damaged = DB::value("SELECT id FROM sites WHERE site_type = 'damaged' AND is_active = 1 ORDER BY id LIMIT 1");
        $methodId = pint('refund_method_id') ?: (int)DB::value("SELECT id FROM payment_methods WHERE method_type = 'cash' ORDER BY id LIMIT 1");
        $id = DB::tx(function () use ($sale, $rows, $terminal, $shift, $approver, $total, $resaleable, $damaged, $methodId) {
            $rid = Txn::save('pos_return', [
                'txn_date' => today(), 'site_id' => $terminal['site_id'], 'site2_id' => $resaleable ? null : ($damaged ?: null),
                'restock' => ($resaleable || $damaged) ? 1 : 0, 'terminal_id' => $terminal['id'], 'pos_shift_id' => $shift['id'],
                'customer_id' => $sale['customer_id'], 'payee_name' => $sale['payee_name'], 'class_id' => $sale['class_id'] ?? null,
                'source_txn_id' => $sale['id'], 'status' => 'suspended',
                'receipt_no' => Pos::nextReceipt((int)$terminal['id']), 'memo' => 'Return of ' . $sale['receipt_no'] . ': ' . p('reason'),
                'approved_by' => $approver['id'] ?? null,
            ], $rows, null, ['reason' => p('reason'), 'approved_by' => $approver['username'] ?? null, 'audit_action' => 'start']);
            DB::insert('pos_tenders', ['txn_id' => $rid, 'payment_method_id' => $methodId, 'amount' => $total, 'reference' => 'Refund']);
            $saved = Txn::load($rid);
            return Txn::save('pos_return', ['txn_date' => $saved['txn_date'], 'status' => 'completed'], array_map(fn($l) => array_intersect_key($l, array_flip([
                'id', 'line_kind', 'item_id', 'account_id', 'description', 'qty', 'rate', 'discount_pct', 'discount_amount', 'amount', 'tax_code_id', 'tax_amount', 'site_id', 'source_line_id',
                'group_item_id', 'group_ref', 'group_qty', 'group_rate', 'group_role',
            ])), Txn::lines($rid)), $rid, ['reason' => p('reason'), 'approved_by' => $approver['username'] ?? null, 'audit_action' => 'refund']);
        });
        flash('success', 'Return processed. Refund: ' . money($total));
        redirect(url('pos.register', ['a' => 'receipt', 'id' => $id]));
    });
    layout_start('Return items from ' . ($sale['receipt_no'] ?: $sale['txn_no']));
    ?>
    <form method="post" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <p class="small">Sold <?= h(fdatetime($sale['created_at'])) ?>, total <?= money($sale['total']) ?>. For account customers you can also issue a credit memo from the Customers module.</p>
      <table class="table table-sm"><thead><tr><th>Item</th><th class="num">Sold</th><th class="num">Already returned</th><th class="num">Price</th><th class="num">Return qty</th></tr></thead>
      <?php foreach ($display as $l): if (empty($l['item_id'])) { continue; } ?>
        <?php if (!empty($l['_group'])): ?>
        <tr><td><?= h($l['item_code'] . ' - ' . $l['description']) ?><div class="small text-muted">Item group: <?= h(implode(', ', array_map(fn($m) => qty($m['qty']) . ' ' . $m['description'], $l['_members']))) ?></div></td>
          <td class="num"><?= qty($l['group_qty']) ?></td><td class="num"><?= qty($l['returned']) ?></td><td class="num"><?= money($l['group_rate']) ?></td>
          <td><input class="form-control form-control-sm text-end ms-auto" style="width:90px" name="retg[<?= (int)$l['group_ref'] ?>]" value="<?= h($_POST['retg'][$l['group_ref']] ?? '') ?>" aria-label="Groups returned"></td></tr>
        <?php else: ?>
        <tr><td><?= h($l['item_code'] . ' - ' . $l['description']) ?></td><td class="num"><?= qty($l['qty']) ?></td><td class="num"><?= qty($l['returned']) ?></td><td class="num"><?= money($l['rate']) ?></td>
          <td><input class="form-control form-control-sm text-end ms-auto" style="width:90px" name="ret[<?= (int)$l['id'] ?>]" value="<?= h($_POST['ret'][$l['id']] ?? '') ?>"></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </table>
      <div class="row g-2">
        <?= input_field('reason', 'Reason', fv('reason'), ['class' => 'col-md-6', 'required' => true]) ?>
        <?= select_field('refund_method_id', 'Refund method', Lookup::paymentMethods(), fv('refund_method_id'), ['class' => 'col-md-3']) ?>
        <?= check_field('resaleable', 'Goods can be resold (back to store stock)', fv('resaleable', null, 1), 'col-md-3') ?>
      </div>
      <p class="small text-muted mt-2">Items that cannot be resold go to the damaged-goods site. Returns always need a supervisor.</p>
      <?= Access::can($pageKey, 'approve') ? '' : Approval::fields(true) ?>
      <button class="btn btn-warning mt-2">Process return and refund</button>
    </div></form>
    <?php
    layout_end();
    return;
}

// list
// v38: the period is a date and time (From / To), with a Cashier filter; choosing a cashier sets the period to the
// shifts the cashier worked in on those days (or to the moments of the cashier's sales), so only those receipts show
$dtIn = function (string $k, string $dflt): string {
    $v = trim((string)g($k));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && valid_date($v)) {
        return $v . ($k === 'from' ? 'T00:00' : 'T23:59');          // an older link with dates only
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $v, $m) && valid_date($m[1])) {
        return $m[1] . 'T' . $m[2];
    }
    return $dflt;
};
$from = $dtIn('from', today() . 'T00:00');
$to = $dtIn('to', today() . 'T23:59');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$fromDt = str_replace('T', ' ', $from) . ':00';
$toDt = str_replace('T', ' ', $to) . ':59';
$termId = gint('terminal_id');
$cashierId = gint('cashier_id');
$cashiers = DB::pairs("SELECT u.id, COALESCE(NULLIF(u.full_name, ''), u.username) FROM users u
                        WHERE EXISTS (SELECT 1 FROM pos_shifts s WHERE s.cashier_id = u.id)
                           OR EXISTS (SELECT 1 FROM transactions t WHERE t.created_by = u.id AND t.txn_type IN ('pos_sale','pos_return'))
                        ORDER BY 2");

// the period a cashier worked in: their shifts that touch the days chosen (opened to closed, or to now while open),
// else the first and last of their POS receipts on those days, else their latest shift
if (g('a') === 'span') {
    Access::requirePage($pageKey);
    $cid = gint('cashier_id');
    $d1 = substr($from, 0, 10) . ' 00:00:00';
    $d2 = substr($to, 0, 10) . ' 23:59:59';
    $out = ['ok' => false];
    if ($cid) {
        $tp = $termId ? ' AND s.terminal_id = :term' : '';
        $now = date('Y-m-d H:i:s');
        $sp = ['c' => $cid, 'a' => $d1, 'b' => $d2] + ($termId ? ['term' => $termId] : []);
        $r = DB::row("SELECT MIN(s.opened_at) AS f, MAX(COALESCE(s.closed_at, :n1)) AS t, COUNT(*) AS n FROM pos_shifts s
                       WHERE s.cashier_id = :c AND s.opened_at <= :b AND COALESCE(s.closed_at, :n2) >= :a$tp", $sp + ['n1' => $now, 'n2' => $now]);
        $how = 'shift';
        if (!$r || !(int)$r['n']) {
            $r = DB::row("SELECT MIN(t.created_at) AS f, MAX(t.created_at) AS t, COUNT(*) AS n FROM transactions t
                           WHERE t.created_by = :c AND t.txn_type IN ('pos_sale','pos_return') AND t.status <> 'suspended' AND t.created_at BETWEEN :a AND :b"
                . ($termId ? ' AND t.terminal_id = :term' : ''), $sp);
            $how = 'receipts';
        }
        if (!$r || !(int)$r['n']) {
            $r = DB::row('SELECT s.opened_at AS f, COALESCE(s.closed_at, :n) AS t, 1 AS n FROM pos_shifts s WHERE s.cashier_id = :c' . $tp . ' ORDER BY s.opened_at DESC LIMIT 1',
                ['c' => $cid, 'n' => $now] + ($termId ? ['term' => $termId] : []));
            $how = 'latest shift';
        }
        if ($r && (int)$r['n'] && $r['f']) {
            $out = ['ok' => true, 'from' => substr(str_replace(' ', 'T', (string)$r['f']), 0, 16), 'to' => substr(str_replace(' ', 'T', (string)$r['t']), 0, 16), 'how' => $how];
        }
    }
    json_out($out);
}

$params = ['f' => $fromDt, 't' => $toDt];
$where = " WHERE t.txn_type IN ('pos_sale','pos_return') AND t.status <> 'suspended' AND t.created_at BETWEEN :f AND :t";
if ($termId) {
    $where .= ' AND t.terminal_id = :term';
    $params['term'] = $termId;
}
if ($cashierId) {
    $where .= ' AND t.created_by = :cu';
    $params['cu'] = $cashierId;
}
[$sf, $sp] = Access::siteFilter('t.site_id');
$rows = DB::all("SELECT t.*, pt.name AS terminal, u.username, COALESCE(NULLIF(u.full_name, ''), u.username) AS cashier, c.name AS customer FROM transactions t
                   LEFT JOIN pos_terminals pt ON pt.id = t.terminal_id LEFT JOIN users u ON u.id = t.created_by LEFT JOIN customers c ON c.id = t.customer_id"
    . $where . $sf . ' ORDER BY t.id DESC LIMIT 500', $params + $sp);
// the totals of every receipt of the period (not only the 500 shown), voided ones left out
$tot = DB::row("SELECT SUM(CASE WHEN t.is_voided = 0 AND t.txn_type = 'pos_sale' THEN 1 ELSE 0 END) AS sales_n,
                       SUM(CASE WHEN t.is_voided = 0 AND t.txn_type = 'pos_sale' THEN t.total ELSE 0 END) AS sales,
                       SUM(CASE WHEN t.is_voided = 0 AND t.txn_type = 'pos_return' THEN 1 ELSE 0 END) AS ret_n,
                       SUM(CASE WHEN t.is_voided = 0 AND t.txn_type = 'pos_return' THEN t.total ELSE 0 END) AS ret,
                       SUM(CASE WHEN t.is_voided = 1 THEN 1 ELSE 0 END) AS void_n, COUNT(*) AS n
                  FROM transactions t" . $where . $sf, $params + $sp);
$net = r2((float)$tot['sales'] - (float)$tot['ret']);
layout_start('POS Receipts, Returns & Voids', ['actions' => page_link('pos.register', [], 'Register', 'btn-success', 'cart')]);
?>
<form class="card card-body mb-3 filters rcpt-filters" method="get" id="rcptFilters" data-span-url="<?= h(url($pageKey, ['a' => 'span'])) ?>"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-sm-6 col-lg-auto"><label class="form-label" for="rcFrom">From date and time</label><input class="form-control form-control-sm" type="datetime-local" id="rcFrom" name="from" value="<?= h($from) ?>"></div>
    <div class="col-12 col-sm-6 col-lg-auto"><label class="form-label" for="rcTo">To date and time</label><input class="form-control form-control-sm" type="datetime-local" id="rcTo" name="to" value="<?= h($to) ?>"></div>
    <div class="col-6 col-lg-auto"><label class="form-label" for="rcTerm">Terminal</label><select class="form-select form-select-sm" name="terminal_id" id="rcTerm" data-no-combo><?= options(DB::pairs('SELECT id, name FROM pos_terminals ORDER BY code'), $termId, 'All') ?></select></div>
    <div class="col-6 col-lg-auto"><label class="form-label" for="rcCashier">Cashier</label><select class="form-select form-select-sm" name="cashier_id" id="rcCashier" style="min-width:170px"><?= options($cashiers, $cashierId, 'All cashiers') ?></select></div>
    <div class="col-12 col-lg-auto d-flex gap-2"><button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($cashierId || $termId || g('from') !== '' || g('to') !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey)) ?>">Today</a><?php endif; ?></div>
  </div>
  <div class="small text-muted mt-2" id="rcSpanNote"><?= $cashierId ? 'Cashier ' . h($cashiers[$cashierId] ?? '') . ', ' : '' ?><?= h(fdatetime($fromDt)) ?> to <?= h(fdatetime($toDt)) ?>. Choosing a cashier sets the dates and times to the shifts the cashier worked in on these days.</div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 rcpt-table">
  <thead><tr><th>Time</th><th>Receipt</th><th>Type</th><th>Terminal</th><th>Cashier</th><th>Customer</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr class="<?= (int)$r['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>">
      <td class="text-nowrap rc-time"><?= h(fdatetime($r['created_at'])) ?></td><td class="rc-no"><a href="<?= h(url($pageKey, ['a' => 'view', 'id' => $r['id']])) ?>"><?= h($r['receipt_no'] ?: $r['txn_no']) ?></a></td>
      <td class="rc-type"><?= h(Txn::label($r['txn_type'])) ?></td><td class="rc-term"><?= h($r['terminal']) ?></td><td class="rc-cash"><?= h($r['cashier']) ?></td><td class="rc-cust"><?= h($r['customer']) ?></td>
      <td class="num rc-tot"><?= money($r['txn_type'] === 'pos_return' ? -$r['total'] : $r['total']) ?></td><td class="rc-st"><?= status_badge((int)$r['is_voided'] ? 'void' : $r['status']) ?></td>
      <td class="text-nowrap rc-act"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url('pos.register', ['a' => 'receipt', 'id' => $r['id'], 'reprint' => 1])) ?>">Reprint</a>
        <?php if ($r['txn_type'] === 'pos_sale' && !(int)$r['is_voided'] && Access::can($pageKey, 'create')): ?><a class="btn btn-sm btn-outline-warning" href="<?= h(url($pageKey, ['a' => 'return', 'id' => $r['id']])) ?>">Return</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted">No POS transactions for this period.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($rows): ?>
  <tfoot class="rcpt-foot">
    <?php if ((int)$tot['ret_n']): ?>
    <tr><td colspan="6">Sales<span class="text-muted fw-normal"> (<?= (int)$tot['sales_n'] ?> receipt<?= (int)$tot['sales_n'] === 1 ? '' : 's' ?>)</span></td><td class="num"><?= money($tot['sales']) ?></td><td colspan="2"></td></tr>
    <tr><td colspan="6">Returns<span class="text-muted fw-normal"> (<?= (int)$tot['ret_n'] ?>)</span></td><td class="num"><?= money(-(float)$tot['ret']) ?></td><td colspan="2"></td></tr>
    <?php endif; ?>
    <tr class="rcpt-total"><td colspan="6">Total<span class="text-muted fw-normal"> (<?= (int)$tot['ret_n'] ? 'sales less returns' : (int)$tot['sales_n'] . ' receipt' . ((int)$tot['sales_n'] === 1 ? '' : 's') ?><?= (int)$tot['void_n'] ? ', ' . (int)$tot['void_n'] . ' voided left out' : '' ?>)</span></td><td class="num"><?= money($net) ?></td><td colspan="2"></td></tr>
  </tfoot>
  <?php endif; ?>
</table></div></div>
<p class="small text-muted mt-2"><?= (int)$tot['n'] > count($rows) ? 'The latest ' . count($rows) . ' of ' . (int)$tot['n'] . ' are listed; the totals are of all of them. ' : '' ?>To void a sale, open it and choose Void: a supervisor must approve, and the void is recorded in the void log.</p>
<script>
(function () {
  var f = document.getElementById('rcptFilters'), sel = document.getElementById('rcCashier');
  if (!f || !sel) { return; }
  // choosing a cashier: the dates and times become the shifts the cashier worked in, then the list is shown
  sel.addEventListener('change', function () {
    if (!sel.value) { f.submit(); return; }
    var q = '&cashier_id=' + encodeURIComponent(sel.value) + '&terminal_id=' + encodeURIComponent(f.terminal_id.value)
      + '&from=' + encodeURIComponent(f.from.value) + '&to=' + encodeURIComponent(f.to.value);
    var note = document.getElementById('rcSpanNote');
    if (note) { note.textContent = 'Finding the shifts of the cashier...'; }
    fetch(f.dataset.spanUrl + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok) { f.from.value = d.from; f.to.value = d.to; }
      f.submit();
    }).catch(function () { f.submit(); });
  });
})();
</script>
<?php
layout_end();
