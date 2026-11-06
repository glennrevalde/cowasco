<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'inv.center';
$viewCosts = Access::viewCosts();
$q = g('q');
$siteId = gint('site_id');
$params = [];
$sql = "SELECT i.*, COALESCE(SUM(s.qty_on_hand),0) AS on_hand FROM items i
          LEFT JOIN item_site_stock s ON s.item_id = i.id" . ($siteId ? ' AND s.site_id = :s' : '') . "
         WHERE i.item_type IN ('inventory','assembly') AND i.is_active = 1";
if ($siteId) {
    $params['s'] = $siteId;
}
if ($q !== '') {
    $sql .= ' AND (i.code LIKE :q OR i.name LIKE :q OR i.barcode = :b)';
    $params['q'] = "%$q%";
    $params['b'] = $q;
}
$items = DB::all($sql . ' GROUP BY i.id ORDER BY i.code', $params);
$selId = gint('id') ?? ($items[0]['id'] ?? null);
$sel = $selId ? DB::row('SELECT i.*, v.name AS vendor FROM items i LEFT JOIN vendors v ON v.id = i.preferred_vendor_id WHERE i.id = :id', ['id' => $selId]) : null;
$siteName = $siteId ? (string)DB::value('SELECT name FROM sites WHERE id = :id', ['id' => $siteId]) : '';

layout_start('Inventory Center', ['actions' => page_link('inv.items', ['a' => 'new', 'type' => 'inventory'], 'New inventory item', 'btn-primary', 'plus-lg', 'create')
    . ' ' . page_link('inv.items', ['a' => 'new', 'type' => 'assembly'], 'New assembly', 'btn-outline-primary', 'plus-lg', 'create')]);
?>
<?php if ($siteId): ?>
<div class="site-strip mb-3"><i class="bi bi-geo-alt"></i><span>Showing <strong><?= h($siteName) ?></strong> only: the quantities, open orders and transaction history below are this site's. </span><a href="<?= h(url($pageKey, ['id' => $selId, 'q' => $q])) ?>">Show all sites</a></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body pb-2"><form class="d-flex gap-1 align-items-center" method="get"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><?php if (gint('id')): ?><input type="hidden" name="id" value="<?= (int)gint('id') ?>"><?php endif; ?>
        <div class="live-search flex-grow-1"><i class="bi bi-search"></i>
          <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Type to find an item, or scan a barcode" autocomplete="off"
                 data-live-filter="#itemList tbody tr" data-live-count="#itemCount" data-live-empty="#itemNone" aria-label="Find an item"></div>
        <select class="form-select form-select-sm" name="site_id" data-autosubmit style="max-width:160px" aria-label="Site"><?= options(Lookup::sites(), $siteId, 'All sites') ?></select></form>
        <div class="d-flex justify-content-between mt-1"><span class="live-count"><span id="itemCount"><?= count($items) ?></span> item(s)<?= $siteId ? ', ' . h($siteName) : '' ?></span><span class="live-count d-none d-xl-inline">Type any part of the code or name; Enter opens the first match</span></div></div>
      <div class="table-responsive" style="max-height:72vh"><table class="table table-sm table-tight table-hover mb-0" id="itemList">
        <thead><tr><th>Item</th><th class="num">On hand</th><th class="num">On SO</th><th class="num">Avail.</th><th class="num">On PO</th><th class="num">Reorder</th></tr></thead>
        <tbody>
        <?php foreach ($items as $r):
            $so = Stock::onSalesOrder((int)$r['id'], $siteId);
            $po = Stock::onPurchaseOrder((int)$r['id'], $siteId);
            $low = (float)$r['reorder_point'] > 0 && (float)$r['on_hand'] <= (float)$r['reorder_point'];
        ?>
          <tr class="<?= (int)$r['id'] === (int)$selId ? 'table-primary' : '' ?>" data-filter-text="<?= h($r['code'] . ' ' . $r['name'] . ' ' . $r['barcode']) ?>">
            <td><a href="<?= h(url($pageKey, ['id' => $r['id'], 'site_id' => $siteId])) ?>"><?= h($r['code']) ?></a><div class="small text-muted"><?= h($r['name']) ?></div></td>
            <td class="num <?= (float)$r['on_hand'] < 0 ? 'neg' : '' ?>"><?= qty($r['on_hand']) ?></td><td class="num"><?= qty($so) ?></td>
            <td class="num"><?= qty((float)$r['on_hand'] - $so) ?></td><td class="num"><?= qty($po) ?></td>
            <td class="num"><?= $low ? '<span class="badge text-bg-warning">' . qty($r['reorder_point']) . '</span>' : qty($r['reorder_point']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table><div class="p-3 small text-muted" id="itemNone">No item matches. Press Enter to search the server, or scan again.</div></div>
    </div>
  </div>
  <div class="col-lg-7">
  <?php if ($sel):
      // with a site chosen, every figure of the detail is that site's: its quantity, its open orders, its movements
      $bySite = DB::all('SELECT s.name, s.id, COALESCE(x.qty_on_hand,0) AS qty FROM sites s LEFT JOIN item_site_stock x ON x.site_id = s.id AND x.item_id = :i WHERE s.is_active = 1' . ($siteId ? ' AND s.id = :sid' : '') . ' ORDER BY s.id', ['i' => $sel['id']] + ($siteId ? ['sid' => $siteId] : []));
      $onHandAll = Stock::onHand((int)$sel['id']);
      $onHand = $siteId ? Stock::onHand((int)$sel['id'], $siteId) : $onHandAll;
      $moves = DB::all(
          'SELECT m.*, t.txn_type, t.txn_no, t.id AS tid, s.name AS site FROM inventory_movements m JOIN transactions t ON t.id = m.txn_id JOIN sites s ON s.id = m.site_id
            WHERE m.item_id = :i' . ($siteId ? ' AND m.site_id = :sid' : '') . ' ORDER BY m.txn_date DESC, m.id DESC LIMIT 100', ['i' => $sel['id']] + ($siteId ? ['sid' => $siteId] : []));
      // the balances after each movement in the order of the transaction dates (a back-dated one in its place)
      $afterOf = Stock::balancesAfter((int)$sel['id'], $siteId, (float)$sel['avg_cost']);
      foreach ($moves as &$mv) {
          $a = $afterOf[(int)$mv['id']] ?? ['qty' => (float)$mv['qty_after'], 'avg' => (float)$mv['avg_after']];
          $mv['qty_after'] = $a['qty'];
          $mv['avg_after'] = $a['avg'];
      }
      unset($mv);
      $openDocs = DB::all(
          "SELECT t.*, l.qty, l.qty_fulfilled FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
            WHERE l.item_id = :i AND t.txn_type IN ('sales_order','purchase_order') AND t.status IN ('open','partial') AND t.is_voided = 0 AND l.is_closed = 0"
            . ($siteId ? ' AND COALESCE(l.site_id, t.site_id) = :sid' : '') . "
            ORDER BY t.txn_date", ['i' => $sel['id']] + ($siteId ? ['sid' => $siteId] : []));
      $comps = DB::all('SELECT ic.qty, i.code, i.name FROM item_components ic JOIN items i ON i.id = ic.component_item_id WHERE ic.parent_item_id = :p', ['p' => $sel['id']]);
  ?>
    <div class="card mb-3"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap">
        <div><h2 class="h5 mb-0"><?= h($sel['code']) ?> - <?= h($sel['name']) ?></h2>
          <div class="small text-muted"><?= h($sel['item_type'] === 'assembly' ? 'Inventory assembly' : 'Inventory part') ?>, barcode <?= h($sel['barcode']) ?>, unit <?= h($sel['unit']) ?>, preferred vendor <?= h($sel['vendor']) ?></div></div>
        <div class="text-end"><div class="small text-muted">On hand<?= $siteId ? ' at ' . h($siteName) : '' ?></div><div class="fs-4 fw-bold"><?= qty($onHand) ?></div>
          <?php if ($siteId): ?><div class="small text-muted"><?= qty($onHandAll) ?> at all sites</div><?php endif; ?></div>
      </div>
      <div class="row small mt-2">
        <div class="col-sm-3">Sales price: <strong><?= money($sel['sales_price']) ?></strong></div>
        <?php if ($viewCosts): ?><div class="col-sm-3">Average cost: <strong><?= money($sel['avg_cost']) ?></strong></div><div class="col-sm-3">Value<?= $siteId ? ' here' : '' ?>: <strong><?= money($onHand * (float)$sel['avg_cost']) ?></strong></div><?php endif; ?>
        <div class="col-sm-3">Reorder point: <strong><?= qty($sel['reorder_point']) ?></strong><?= $sel['item_type'] === 'assembly' ? ', build point ' . qty($sel['build_point']) : '' ?></div>
        <div class="col-sm-3">On sales order: <strong><?= qty(Stock::onSalesOrder((int)$sel['id'], $siteId)) ?></strong></div>
        <div class="col-sm-3">On purchase order: <strong><?= qty(Stock::onPurchaseOrder((int)$sel['id'], $siteId)) ?></strong></div>
      </div>
      <div class="d-flex flex-wrap gap-1 mt-3">
        <?= page_link('inv.items', ['a' => 'edit', 'id' => $sel['id']], 'Edit item', 'btn-outline-primary', 'pencil') ?>
        <?= page_link('vend.purchase_orders', ['a' => 'new', 'vendor_id' => $sel['preferred_vendor_id']], 'Purchase order', 'btn-outline-success', 'cart-plus', 'create') ?>
        <?= page_link('inv.adjust', ['item_id' => $sel['id']], 'Adjust', 'btn-outline-success', 'sliders', 'create') ?>
        <?= page_link('inv.transfers', ['a' => 'new', 'item_id' => $sel['id']], 'Transfer', 'btn-outline-success', 'arrow-left-right', 'create') ?>
        <?= $sel['item_type'] === 'assembly' ? page_link('inv.build', ['a' => 'new', 'item_id' => $sel['id']], 'Build', 'btn-outline-success', 'hammer', 'create') : '' ?>
        <?= Stock::isTracked($sel['item_type']) ? page_link('inv.process', ['a' => 'new', 'item_id' => $sel['id']], $sel['item_type'] === 'assembly' ? 'Disassemble' : 'Process / break down', 'btn-outline-success', 'diagram-3', 'create') : '' ?>
        <?= page_link('rpt.inv_valuation_detail', ['item_id' => $sel['id']], 'Valuation detail', 'btn-outline-secondary', 'bar-chart') ?>
      </div>
    </div></div>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><div class="card h-100"><div class="card-header d-flex justify-content-between"><span>Quantity by site</span><?= $siteId ? '<a class="small" href="' . h(url($pageKey, ['id' => $selId, 'q' => $q])) . '">All sites</a>' : '' ?></div><table class="table table-sm mb-0">
        <?php foreach ($bySite as $s): ?><tr><td><a href="<?= h(url($pageKey, ['id' => $selId, 'q' => $q, 'site_id' => $s['id']])) ?>" title="Show this site only"><?= h($s['name']) ?></a></td><td class="num <?= $s['qty'] < 0 ? 'neg' : '' ?>"><?= qty($s['qty']) ?></td></tr><?php endforeach; ?>
        <?php if (!$siteId && count($bySite) > 1): ?><tr class="fw-semibold"><td>All sites</td><td class="num"><?= qty($onHandAll) ?></td></tr><?php endif; ?></table></div></div>
      <div class="col-md-6"><div class="card h-100"><div class="card-header">Open orders<?= $siteId ? ' at ' . h($siteName) : '' ?></div><table class="table table-sm mb-0">
        <?php foreach ($openDocs as $d): ?><tr><td><?= Txn::link($d, Txn::ref($d)) ?></td><td class="num"><?= qty((float)$d['qty'] - (float)$d['qty_fulfilled']) ?></td><td><?= h(fdate($d['due_date'] ?: $d['ship_date'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$openDocs): ?><tr><td class="text-muted">None</td></tr><?php endif; ?></table></div></div>
    </div>
    <?php if ($comps): ?>
      <div class="card mb-3"><div class="card-header">Bill of materials</div><table class="table table-sm mb-0"><?php foreach ($comps as $c): ?><tr><td><?= h($c['code']) ?></td><td><?= h($c['name']) ?></td><td class="num"><?= qty($c['qty']) ?></td></tr><?php endforeach; ?></table></div>
    <?php endif; ?>
    <div class="card"><div class="card-header">Transaction history<?= $siteId ? ' at ' . h($siteName) : '' ?><span class="small text-muted fw-normal ms-2">latest 100</span></div><div class="table-responsive" style="max-height:380px"><table class="table table-sm table-tight mb-0">
      <thead><tr><th>Date</th><th>Transaction</th><?= $siteId ? '' : '<th>Site</th>' ?><th class="num">Qty</th><?= $viewCosts ? '<th class="num">Unit cost</th><th class="num">Value</th><th class="num">Avg after</th>' : '' ?><th class="num"<?= $siteId ? ' title="Quantity at ' . h($siteName) . ' after the transaction"' : '' ?>><?= $siteId ? h($siteName) . ' after' : 'On hand after' ?></th></tr></thead>
      <?php foreach ($moves as $m): $after = (float)$m['qty_after']; ?>
        <tr><td><?= h(fdate($m['txn_date'])) ?></td><td class="wrap-link"><?= Txn::link(['id' => $m['tid'], 'txn_type' => $m['txn_type'], 'txn_no' => $m['txn_no']], Txn::label($m['txn_type']) . ' ' . $m['txn_no']) ?></td><?= $siteId ? '' : '<td>' . h($m['site']) . '</td>' ?>
          <td class="num"><?= qty($m['qty']) ?></td><?php if ($viewCosts): ?><td class="num"><?= money($m['unit_cost']) ?></td><td class="num"><?= money($m['total_cost']) ?></td><td class="num"><?= money($m['avg_after']) ?></td><?php endif; ?><td class="num<?= $after < -0.00001 ? ' neg' : '' ?>"><?= qty($after) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$moves): ?><tr><td colspan="8" class="text-muted text-center py-3">No movements<?= $siteId ? ' at ' . h($siteName) : '' ?>.</td></tr><?php endif; ?>
    </table></div></div>
  <?php else: ?><p class="text-muted">No inventory items yet.</p><?php endif; ?>
  </div>
</div>
<?php
layout_end();
