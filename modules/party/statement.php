<?php
declare(strict_types=1);

/**
 * Consolidated Party Statement (Party 360): everything one person or organization has with the cooperative, by
 * role, each with its own opening balance, activity and closing balance and every line leading to its document.
 * Printable (and saved as PDF from the print dialog) and exported to Excel or CSV.
 */
require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'party.statement';
if (!Party::ready()) {
    layout_start('Consolidated Party Statement');
    echo '<div class="alert alert-warning">The Party Master needs a database update. Import <code>database/migrations/2026_11_15_multipurpose_party.sql</code>, then reload this page.</div>';
    layout_end();
    return;
}
$partyId = gint('party');
$from = gdate('from', date('Y-01-01'));
$to = gdate('to', today());
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$filters = ['business_unit_id' => BusinessUnits::ready() ? gint('unit') : null, 'class_id' => Classes::ready() ? gint('class') : null];
$detail = g('detail', '1') === '1';
$party = $partyId ? Party::get($partyId) : null;
$st = $party ? PartyStatement::build((int)$party['id'], $from, $to, $filters) : null;
$export = (string)g('export');
$print = g('print') === '1';

if ($st && ($export === 'xlsx' || $export === 'csv')) {
    Access::require($pageKey, 'print');
    Audit::log('export', 'party', 'party_statement', (int)$party['id'], $party['party_no'] . ' ' . $party['legal_name'], ['page_key' => $pageKey, 'reason' => fdate($from) . ' - ' . fdate($to) . ($export === 'xlsx' ? ' (Excel)' : ' (CSV)')]);
    [$rows, $bold] = PartyStatement::sheetRows($st);
    $name = 'party_statement_' . $party['party_no'];
    if ($export === 'xlsx') {
        (new Xlsx())->sheet('Party statement', $rows, $bold, [3, 4, 5])->download($name);
    }
    $head = array_shift($rows);
    csv_download($name . '.csv', $head, $rows);
}
if ($st && $print) {
    Access::require($pageKey, 'print');
    Audit::log('print', 'party', 'party_statement', (int)$party['id'], $party['party_no'] . ' ' . $party['legal_name'], ['page_key' => $pageKey, 'reason' => fdate($from) . ' - ' . fdate($to)]);
}

$sideNote = [
    'owes' => 'Receivables: what the party is to pay the cooperative' . (Party::waterReady() ? ' (accounts receivable, loans and water service)' : '') . '.',
    'owed' => 'Payables, deposits and distributions due: what the cooperative is to pay or return to the party.',
    'equity' => 'Paid-up share capital: the member\'s equity in the cooperative, not a debt.',
];
$filterText = trim(($filters['business_unit_id'] ? 'Line of business ' . BusinessUnits::name($filters['business_unit_id']) . '. ' : '')
    . ($filters['class_id'] ? 'Class ' . Classes::name($filters['class_id']) . '. ' : ''));

if ($print) {
    layout_start('Consolidated Party Statement', ['bare' => true, 'no_title' => true]);
    echo '<div class="no-print mb-2 text-center"><button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print or save as PDF</button> '
        . '<a class="btn btn-outline-secondary btn-sm" href="' . h(current_url(['print' => null])) . '">Back</a></div>';
} else {
    $actions = '';
    if ($st && Access::can($pageKey, 'print')) {
        $actions = btn_link(current_url(['print' => '1']), 'Print / PDF', 'btn-outline-secondary', 'printer') . ' '
            . btn_link(current_url(['export' => 'xlsx']), 'Excel', 'btn-outline-secondary', 'file-earmark-excel') . ' '
            . btn_link(current_url(['export' => 'csv']), 'CSV', 'btn-outline-secondary', 'file-earmark-spreadsheet');
    }
    if ($party && Access::page('party.center')) {
        $actions .= ' ' . btn_link(url('party.center', ['id' => $party['id']]), 'Party Center', 'btn-outline-secondary', 'person-vcard');
    }
    layout_start('Consolidated Party Statement', ['actions' => $actions]);
    ?>
    <form class="row g-2 align-items-end filters mb-3 no-print" method="get">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <?= select_field('party', 'Party', Party::options($partyId, true), $partyId ?: '', ['class' => 'col-md-5', 'blank' => 'Choose a party', 'extra' => 'data-combo data-autosubmit']) ?>
      <?= input_field('from', 'From', $from, ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?= input_field('to', 'To', $to, ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?php if (BusinessUnits::ready() && BusinessUnits::options()): ?><?= select_field('unit', 'Line of business', BusinessUnits::options(false), $filters['business_unit_id'] ?: '', ['class' => 'col-6 col-md-3', 'blank' => 'All', 'extra' => 'data-no-combo']) ?><?php endif; ?>
      <?php if (Classes::ready() && Classes::inUse()): ?><?= select_field('class', 'Branch / class', Classes::options(), $filters['class_id'] ?: '', ['class' => 'col-6 col-md-3', 'blank' => 'All']) ?><?php endif; ?>
      <?= select_field('detail', 'Show', ['1' => 'Balances and activity', '0' => 'Balances only'], $detail ? '1' : '0', ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
      <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
    </form>
    <?php
}

if (!$st) {
    echo '<div class="card"><div class="card-body text-muted">Choose a party. Its customer, supplier' . (Party::waterReady() ? ', water-service' : '') . ' and member records are put together here, each with its own history.</div></div>';
    layout_end();
    return;
}
$view = Party::visible($party);
$memberRoles = array_values(array_filter($st['roles'], fn($r) => $r['role'] === 'member'));
?>
<div class="doc party-statement">
  <div class="d-flex flex-wrap justify-content-between gap-3">
    <?php if ($print): ?><?= print_company_block('doc.party_statement') ?><?php endif; ?>
    <div class="<?= $print ? 'text-end' : '' ?>">
      <h2 class="h5 mb-0">Consolidated Party Statement</h2>
      <div class="small">For the period <?= h(fdate($from)) ?> to <?= h(fdate($to)) ?>; prepared <?= h(fdatetime(date('Y-m-d H:i:s'))) ?></div>
      <?php if ($filterText !== ''): ?><div class="small"><?= h($filterText) ?></div><?php endif; ?>
    </div>
  </div>
  <hr>
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="fw-semibold fs-6"><?= h($party['legal_name']) ?></div>
      <div class="small">Party ID <?= h($party['party_no']) ?><?= $party['display_name'] !== $party['legal_name'] ? ', listed as ' . h($party['display_name']) : '' ?></div>
      <?php if ($view['address']): ?><div class="small"><?= h($view['address']) ?></div><?php endif; ?>
      <?php if ($view['tin']): ?><div class="small">TIN <?= h($view['tin']) ?></div><?php endif; ?>
    </div>
    <div class="col-md-6">
      <table class="table table-sm table-borderless small mb-0 party-roles-print">
        <?php foreach ($st['roles'] as $r): ?>
          <tr><td class="ps-0"><?= h($r['label']) ?></td><td><?= h($r['ref_no']) ?></td><td><?= h($r['name']) ?></td><td class="<?= $r['active'] ? '' : 'text-muted' ?>"><?= h($r['status']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$st['roles']): ?><tr><td class="ps-0 text-muted">No role.</td></tr><?php endif; ?>
      </table>
    </div>
  </div>

  <div class="coop-tiles party-summary mb-3">
    <?php foreach (PartyStatement::SIDES as $k => $label):
        if ($k === 'equity' && !$memberRoles && Dec::isZero($st['totals']['equity'])) {
            continue;
        } ?>
      <div class="coop-tile <?= ['owes' => 'is-warn', 'owed' => '', 'equity' => 'is-ok'][$k] ?>"><div class="t-label"><?= h($label) ?></div><div class="t-value"><?= Dec::money($st['totals'][$k]) ?></div><div class="t-sub"><?= h($sideNote[$k]) ?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="small text-muted">Net position for information only: <?= Dec::money($st['totals']['net']) ?> (<?= Dec::isNeg($st['totals']['net']) ? 'the cooperative owes the party more than the party owes it' : (Dec::isZero($st['totals']['net']) ? 'even' : 'the party owes the cooperative more than it is owed') ?>).
    Nothing is offset: each balance is settled through its own documents, and share capital is equity, not part of this figure.</p>

  <?php foreach (PartyStatement::SIDES as $side => $sideLabel):
      $secs = array_filter($st['sections'], fn($s) => $s['side'] === $side);
      if (!$secs) {
          continue;
      } ?>
    <h3 class="h6 mt-4 mb-2 party-side-title"><?= h($sideLabel) ?></h3>
    <?php foreach ($secs as $s): ?>
      <div class="card mb-3 party-section">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2"><span class="fw-semibold"><?= h($s['title']) ?></span>
          <span class="small">Opening <?= Dec::money($s['opening']) ?>, increase <?= Dec::money($s['increase']) ?>, decrease <?= Dec::money($s['decrease']) ?>, closing <strong><?= Dec::money($s['closing']) ?></strong></span></div>
        <div class="table-responsive"><table class="table table-sm mb-0 stack-sm">
          <?php if ($detail): ?><thead><tr><th>Date</th><th>Document</th><th>Description</th><th>Unit</th><th class="num">Increase</th><th class="num">Decrease</th><th class="num">Balance</th></tr></thead><?php endif; ?>
          <tbody>
          <?php foreach ($s['groups'] as $g): ?>
            <tr class="party-group-row"><td colspan="<?= $detail ? 6 : 1 ?>" class="fw-semibold"><?= h($g['title']) ?><?= !empty($g['status']) ? ' <span class="small text-muted">(' . h(strtolower(str_replace('_', ' ', (string)$g['status']))) . ')</span>' : '' ?></td>
              <?php if (!$detail): ?><td data-label="Opening" class="num"><?= Dec::money($g['opening']) ?></td><td data-label="Closing" class="num"><strong><?= Dec::money($g['closing']) ?></strong></td><?php else: ?><td data-label="Opening" class="num"><?= Dec::money($g['opening']) ?></td><?php endif; ?></tr>
            <?php if ($detail): ?>
              <?php foreach ($g['rows'] as $r): ?>
                <tr><td data-label="Date"><?= h(fdate($r['date'])) ?></td>
                  <td data-label="Document"><?= $r['txn_id'] && $r['txn_type'] && !$print ? Txn::link(['id' => $r['txn_id'], 'txn_type' => $r['txn_type'], 'txn_no' => null], $r['doc']) : h($r['doc']) ?></td>
                  <td data-label="Description" class="small"><?= h($r['memo']) ?></td><td data-label="Unit" class="small"><?= h($r['unit']) ?></td>
                  <td data-label="Increase" class="num"><?= Dec::money($r['increase'], true) ?></td><td data-label="Decrease" class="num"><?= Dec::money($r['decrease'], true) ?></td><td data-label="Balance" class="num"><?= Dec::money($r['balance']) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$g['rows']): ?><tr><td colspan="7" class="small text-muted">No activity in the period.</td></tr><?php endif; ?>
              <tr class="subtotal-row"><td colspan="4">Closing balance<?= isset($g['charges']) && !Dec::isZero($g['charges']) ? ' <span class="small fw-normal">(principal ' . Dec::money($g['principal']) . ', interest and penalties ' . Dec::money($g['charges']) . ')</span>' : '' ?></td>
                <td data-label="Increase" class="num"><?= Dec::money($g['increase']) ?></td><td data-label="Decrease" class="num"><?= Dec::money($g['decrease']) ?></td><td data-label="Balance" class="num"><?= Dec::money($g['closing']) ?></td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <?php if (!$st['sections']): ?><div class="text-muted small">No balances or activity for this party<?= $filterText !== '' ? ' with these filters' : '' ?>.</div><?php endif; ?>

  <?php if ($st['distributions']): ?>
    <h3 class="h6 mt-4 mb-2">Interest on share capital and patronage refund allocated in the period</h3>
    <div class="table-responsive"><table class="table table-sm stack-sm">
      <thead><tr><th>Posted</th><th>Batch</th><th>Kind</th><th>Period</th><th class="num">Basis</th><th class="num">Amount</th><th>Settled</th></tr></thead>
      <tbody>
      <?php foreach ($st['distributions'] as $d): ?>
        <tr><td data-label="Posted"><?= h(fdate($d['posted_on'])) ?></td><td data-label="Batch"><?= h($d['batch_no']) ?></td><td data-label="Kind"><?= h($d['kind_label']) ?></td>
          <td data-label="Period" class="small"><?= h(fdate($d['date_from']) . ' - ' . fdate($d['date_to'])) ?></td><td data-label="Basis" class="num"><?= Dec::money($d['basis_amount']) ?></td>
          <td data-label="Amount" class="num"><?= Dec::money($d['net_amount']) ?></td><td data-label="Settled" class="small"><?= h($d['settled']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  <p class="small text-muted mt-3">Each balance comes from the subsidiary ledger of its role (customer and supplier documents, the savings and loan ledgers<?= Party::waterReady() ? ', the water records of each water-service account' : '' ?>, the distribution batches), which agree with the general ledger control accounts.<?= Party::waterReady() ? ' The water bills and collections are shown once, under Water service; the ledger documents they posted are left out of Accounts receivable.' : '' ?> Amounts are in Philippine pesos.</p>
</div>
<?php
layout_end();
