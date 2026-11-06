<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.interest';
$products = Savings::productOptions(['ordinary', 'special', 'share_capital']);
$productId = (int)(gint('product') ?: pint('product_id') ?: (int)array_key_first($products));
$q = (int)ceil((int)date('n') / 3) - 1;
$defFrom = $q === 0 ? (date('Y') - 1) . '-10-01' : date('Y') . '-' . str_pad((string)(($q - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01';
$defTo = date('Y-m-d', strtotime(($q === 0 ? (date('Y') - 1) . '-12-01' : date('Y') . '-' . str_pad((string)($q * 3), 2, '0', STR_PAD_LEFT) . '-01') . ' +1 month -1 day'));
$from = (string)(p('period_from') ?: gdate('from', $defFrom));
$to = (string)(p('period_to') ?: gdate('to', $defTo));
$postDate = (string)(p('posting_date') ?: $to);
$preview = null;

handle_post(function () use ($pageKey, $productId, $from, $to, $postDate, &$preview) {
    $action = (string)p('action');
    if ($action === 'preview') {
        Access::require($pageKey, 'view');
        $preview = Savings::postInterest($productId, $from, $to, $postDate, false, $pageKey);
        return;
    }
    if ($action === 'post') {
        Access::require($pageKey, 'create');
        coop_once();
        $classId = Classes::required('class_id', 'the interest posting');
        $r = CoopLedger::withClass($classId, fn() => Savings::postInterest($productId, $from, $to, $postDate, true, $pageKey));
        flash('success', 'Interest posted to ' . $r['count'] . ' account(s): ' . Dec::money($r['total_interest']) . ($r['total_tax'] !== '0' && Dec::isPos($r['total_tax']) ? ', tax withheld ' . Dec::money($r['total_tax']) : '') . '.');
        redirect(url($pageKey, ['product' => $productId]));
    }
    if ($action === 'dormancy') {
        Access::require($pageKey, 'create');
        coop_once();
        $r = Savings::updateDormancy((string)p('as_of', today()), p('charge') === '1', $pageKey);
        flash('success', $r['dormant'] . ' account(s) marked dormant' . (Dec::isPos($r['fees']) ? ', dormancy fees ' . Dec::money($r['fees']) : '') . '.');
        redirect(url($pageKey));
    }
    fail('Unknown action.');
});

$runs = DB::all('SELECT r.*, p.name AS product_name, u.full_name FROM sav_interest_runs r JOIN sav_products p ON p.id = r.product_id LEFT JOIN users u ON u.id = r.run_by ORDER BY r.id DESC LIMIT 30');
coop_layout('Post Savings Interest');
?>
<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" class="card mb-3" data-once><div class="card-header">Interest for a period</div><div class="card-body row g-2 align-items-end">
      <?= csrf_field() ?><?= Coop::onceField() ?>
      <?= select_field('product_id', 'Product', $products, $productId, ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
      <?= input_field('period_from', 'From', $from, ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?= input_field('period_to', 'To', $to, ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?= input_field('posting_date', 'Posting date', $postDate, ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <div class="col-6 col-md-2"><button class="btn btn-outline-primary btn-sm w-100" name="action" value="preview" data-no-confirm formnovalidate>Preview</button></div>
      <?= Classes::requiredField(fv('class_id', null), 'col-md-4') ?>
      <div class="col-12 small text-muted">Each account earns on the product's basis (average daily balance, lowest or ending balance) from its opening day, at the rate tier of that balance in effect at the end of the period, for the days in the period over <?= h('365 / 360') ?>. Accounts that fell below the maintaining balance earn nothing. A period is posted once per product.</div>
    </div>
    <?php if ($preview): ?>
      <table class="table table-sm"><thead><tr><th>Account</th><th>Member</th><th class="num">Basis</th><th class="num">Days</th><th class="num">Rate</th><th class="num">Interest</th><th class="num">Tax</th><th class="num">Net</th></tr></thead><tbody>
      <?php foreach ($preview['lines'] as $l): ?><tr><td><?= h($l['account']['account_no']) ?></td><td class="small"><?= h((string)DB::value('SELECT full_name FROM members WHERE id = :m', ['m' => $l['account']['member_id']])) ?></td>
        <td class="num"><?= cm($l['basis']) ?></td><td class="num"><?= (int)$l['days'] ?></td><td class="num"><?= h(Dec::fix($l['rate'], 2)) ?> %</td><td class="num"><?= cm($l['interest']) ?></td><td class="num"><?= cm($l['tax'], true) ?></td><td class="num"><?= cm($l['net']) ?></td></tr><?php endforeach; ?>
      <?php if (!$preview['lines']): ?><tr><td colspan="8" class="text-muted">No account earns interest for this period.</td></tr><?php endif; ?>
      </tbody><tfoot><tr><td colspan="5" class="fw-semibold"><?= (int)$preview['count'] ?> account(s)</td><td class="num fw-semibold"><?= cm($preview['total_interest']) ?></td><td class="num"><?= cm($preview['total_tax'], true) ?></td><td class="num fw-semibold"><?= cm(Dec::sub($preview['total_interest'], $preview['total_tax'], 2)) ?></td></tr></tfoot></table>
      <?php if ($preview['lines'] && Access::can($pageKey, 'create')): ?><div class="card-body pt-2"><button class="btn btn-primary btn-sm" name="action" value="post" data-confirm="Post <?= h(Dec::money($preview['total_interest'])) ?> of interest to <?= (int)$preview['count'] ?> account(s)? A voided run is the only way back.">
        <i class="bi bi-check2-all"></i> Post the interest</button></div><?php endif; ?>
    <?php endif; ?>
    </form>
    <form method="post" class="card" data-once><div class="card-header">Dormant accounts and dormancy fees</div><div class="card-body row g-2 align-items-end">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="dormancy">
      <?= input_field('as_of', 'As of', today(), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
      <?= check_field('charge', 'Also charge the dormancy fees (once per fee period)', 0, 'col-md-6') ?>
      <div class="col-md-3"><button class="btn btn-outline-primary btn-sm w-100" data-confirm="Mark dormant the accounts with no member transaction for their product's dormancy period?">Run</button></div>
      <div class="col-12 small text-muted">An account becomes dormant after the product's months without a deposit or withdrawal (interest and fees do not count). Its next member transaction makes it active again; withdrawals from a dormant account need a supervisor.</div>
    </div></form>
  </div>
  <div class="col-xl-5"><div class="card"><div class="card-header">Interest runs</div><table class="table table-sm"><thead><tr><th>Product</th><th>Period</th><th class="num">Accounts</th><th class="num">Interest</th><th>Voucher</th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?><tr class="<?= $r['status'] === 'voided' ? 'text-muted text-decoration-line-through' : '' ?>"><td class="small"><?= h($r['product_name']) ?></td><td class="small"><?= h(fdate($r['period_from'])) ?> - <?= h(fdate($r['period_to'])) ?></td>
      <td class="num"><?= (int)$r['accounts_count'] ?></td><td class="num"><?= cm($r['total_interest']) ?></td><td class="small"><?= $r['txn_id'] ? Txn::link((int)$r['txn_id']) : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$runs): ?><tr><td colspan="5" class="text-muted">No run yet.</td></tr><?php endif; ?>
  </tbody></table><div class="card-body small text-muted pt-2">To undo a run, open its voucher and void it: the interest leaves the member ledgers and the general ledger together.</div></div></div>
</div>
<?php
layout_end();
