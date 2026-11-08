<?php
declare(strict_types=1);

/**
 * Water Billing > Billing > Bill Accounts Center (version 57): every bill of the members and customers, the open ones
 * first (the bills still to be paid, by default) or the closed (fully paid) ones too, one line per invoice: Account No.,
 * Name, Description, Bill Date, Invoice No., Due Date, Amount, Paid, Balance and Status. Filters: the bill status, the
 * bill code, the bill type, the zone, the address sub-group, members or customers, the bill dates; search by name,
 * account no., invoice no. or member no. Exported to Excel or CSV, printed or saved as PDF. A line's toolbar and its
 * right-click menu open the cashier, the account's Statement of Account, its ledger and its water-service account,
 * as the Loan Accounts Center does for a loan. See app/BillAccounts.php for what a bill is.
 */
require_once APP_ROOT . '/modules/coop/_coop.php';
$pageKey = BillAccounts::PAGE;

if (!BillAccounts::ready()) {
    coop_layout('Bill Accounts Center');
    echo '<div class="alert alert-warning">Water Billing is not installed on this database: there are no bills to show.</div>';
    layout_end();
    exit;
}

$f = BillAccounts::filters($_GET);
// a water page the user may open, by its own right or the right of the page it belongs to (the ledger, the receipts)
$waterCan = static function (string $key): bool {
    if (Access::page('water.' . $key)) {
        return true;
    }
    foreach (WaterPages::ALIASES[$key] ?? [] as $parent) {
        if (Access::page('water.' . $parent)) {
            return true;
        }
    }
    return false;
};

// ------------------------------------------------------------------ Excel, CSV and the printout (all the lines found)
$export = (string)g('export');
if (in_array($export, ['xlsx', 'csv'], true) || g('a') === 'print') {
    Access::require($pageKey, 'print');
    $tot = BillAccounts::totals($f);
    if ($tot['n'] > BillAccounts::EXPORT_MAX) {
        flash('warning', 'The filters find ' . number_format($tot['n']) . ' bills; an export or a printout holds at most ' . number_format(BillAccounts::EXPORT_MAX) . '. Narrow the filters (the status, a zone, the bill dates) and try again.');
        redirect(current_url(['export' => null, 'a' => null]));
    }
    // many bills: read a few thousand at a time, written out as they come
    @ini_set('memory_limit', '1024M');
    @set_time_limit(600);
    $title = 'Bill Accounts';
    if ($export !== '') {
        Audit::log('export', 'water', 'list', null, 'Bill Accounts Center', ['page_key' => $pageKey, 'reason' => BillAccounts::describe($f) . ($export === 'xlsx' ? ' (Excel)' : ' (CSV)')]);
        $name = 'bill_accounts_' . date('Ymd');
        if ($export === 'xlsx' && Xlsx::available()) {
            $lines = (function () use ($f, $tot) {
                foreach (BillAccounts::chunks($f, $tot['n']) as $chunk) {
                    yield from $chunk;
                }
            })();
            [$rows, $bold, $money] = BillAccounts::sheet($f, $lines, $tot, $title);
            (new Xlsx())->sheet($title, $rows, $bold, $money)->download($name);
        }
        // the CSV file (also the Excel export on a server without the zip extension): the heading, the lines, the total
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, BillAccounts::COLUMNS, ',', '"', '');
        foreach (BillAccounts::chunks($f, $tot['n']) as $chunk) {
            foreach ($chunk as $l) {
                fputcsv($out, BillAccounts::sheetRow($l), ',', '"', '');
            }
        }
        fputcsv($out, BillAccounts::totalRow($tot), ',', '"', '');
        fclose($out);
        exit;
    }
    Audit::log('print', 'water', 'list', null, 'Bill Accounts Center', ['page_key' => $pageKey, 'reason' => BillAccounts::describe($f)]);
    layout_start('Bill Accounts', ['bare' => true, 'no_title' => true]);
    // ten columns: the printout is landscape
    echo '<style>@page { size: landscape; margin: 10mm; }</style>';
    echo '<div class="no-print ba-print-bar"><button class="btn btn-primary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print or save as PDF</button>'
        . '<a class="btn btn-outline-secondary btn-sm" href="' . h(current_url(['a' => null])) . '">Back to the center</a>'
        . '<span class="small text-muted">In the print window choose Save as PDF for a PDF file.</span></div>';
    echo '<div class="doc coop-doc ba-print">' . coop_print_head('BILL ACCOUNTS', '', today(), ['Showing' => BillAccounts::describe($f)]);
    echo '<table class="table table-sm ba-print-table"><thead><tr>';
    foreach (BillAccounts::COLUMNS as $i => $c) {
        echo '<th' . ($i >= 6 && $i <= 8 ? ' class="num"' : '') . '>' . h($c) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach (BillAccounts::chunks($f, $tot['n']) as $chunk) {
        foreach ($chunk as $l) {
            echo '<tr><td>' . h($l['account_no']) . '</td><td>' . h($l['name']) . '</td><td>' . h($l['full_description']) . '</td><td>' . h(fdate($l['bill_date'])) . '</td><td>' . h($l['invoice_no']) . '</td>'
                . '<td>' . h(fdate($l['due_date'])) . '</td><td class="num">' . cm($l['amount']) . '</td><td class="num">' . cm($l['paid']) . '</td><td class="num">' . cm($l['balance']) . '</td><td>' . h($l['status']) . '</td></tr>';
        }
    }
    if (!$tot['n']) {
        echo '<tr><td colspan="10" class="text-center text-muted py-4">No bill matches.</td></tr>';
    }
    echo '</tbody><tfoot><tr><th colspan="6">' . number_format($tot['n']) . ' bill' . ($tot['n'] === 1 ? '' : 's') . ' of ' . number_format($tot['accounts']) . ' account' . ($tot['accounts'] === 1 ? '' : 's') . '</th>'
        . '<th class="num">' . cm($tot['amount']) . '</th><th class="num">' . cm($tot['paid']) . '</th><th class="num">' . cm($tot['balance']) . '</th><th></th></tr></tfoot></table></div>';
    echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the grid (also the answer to the search as you type)
$tot = BillAccounts::totals($f);
$pages = max(1, (int)ceil($tot['n'] / BillAccounts::PAGE_SIZE));
$pg = min($f['pg'], $pages);
$lines = BillAccounts::rows($f, ($pg - 1) * BillAccounts::PAGE_SIZE, BillAccounts::PAGE_SIZE);
$grid = function () use ($lines, $tot, $pg, $pages, $f) { ?>
  <div class="sv-grid-wrap"><table class="table sv-grid ba-grid" data-select-grid="#baBar" data-context-menu="#baMenu" aria-label="Bills"><thead><tr>
    <th>Account No.</th><th>Name</th><th class="d-none d-lg-table-cell">Description</th><th class="d-none d-md-table-cell">Bill Date</th><th class="d-none d-xl-table-cell">Invoice No.</th>
    <th class="d-none d-sm-table-cell">Due Date</th><th class="num d-none d-lg-table-cell">Amount</th><th class="num d-none d-lg-table-cell">Paid</th><th class="num">Balance</th><th class="d-none d-sm-table-cell">Status</th>
    <th class="text-end" aria-label="Actions"></th></tr></thead><tbody>
  <?php foreach ($lines as $l):
      $stateCls = ['overdue' => 'is-overdue', 'paid' => 'is-paid', 'cancelled' => 'is-cancelled', 'due' => 'is-due'][$l['state']] ?? 'is-open'; ?>
    <tr data-row tabindex="0" class="<?= $l['open'] ? '' : 'is-closed' ?>" data-id="<?= h($l['type'] . ':' . $l['id']) ?>" data-acct="<?= (int)$l['acct_id'] ?>" data-no="<?= h($l['account_no']) ?>"
        data-water="<?= $l['acct_id'] ? 'yes' : 'no' ?>" data-open="<?= $l['open'] ? 'yes' : 'no' ?>" data-title="<?= h($l['invoice_no'] . '  ' . $l['account_no'] . '  ' . $l['name']) ?>">
      <td class="fw-semibold"><?= h($l['account_no']) ?><div class="small fw-normal text-muted d-xl-none"><?= h($l['invoice_no']) ?></div></td>
      <td class="wrap ba-name"><?= h($l['name']) ?><div class="small text-muted"><?= h($l['kind']) ?></div><div class="small d-lg-none ba-desc-sm"><?= h($l['description']) ?></div></td>
      <td class="wrap d-none d-lg-table-cell ba-desc"><?= h($l['description']) ?><div class="small text-muted"><?= h($l['type_label'] . ($l['detail'] !== '' ? ', ' . $l['detail'] : '')) ?></div></td>
      <td class="d-none d-md-table-cell"><?= h(fdate($l['bill_date'])) ?></td>
      <td class="d-none d-xl-table-cell"><?= h($l['invoice_no']) ?></td>
      <td class="d-none d-sm-table-cell<?= $l['state'] === 'overdue' ? ' text-danger fw-semibold' : '' ?>"<?= $l['state'] === 'overdue' ? ' title="Overdue ' . (int)$l['days_overdue'] . ' day' . ($l['days_overdue'] === 1 ? '' : 's') . '"' : '' ?>><?= h(fdate($l['due_date'])) ?></td>
      <td class="num d-none d-lg-table-cell"><?= cm($l['amount']) ?></td><td class="num d-none d-lg-table-cell"><?= cm($l['paid']) ?></td>
      <td class="num fw-semibold"><?= cm($l['balance']) ?><div class="small ba-status d-sm-none <?= $stateCls ?>"><?= h($l['status']) ?></div></td>
      <td class="ba-status d-none d-sm-table-cell <?= $stateCls ?>"><?= h($l['status']) ?></td>
      <td class="text-end"><button type="button" class="sv-more" data-row-menu aria-label="Actions for <?= h($l['invoice_no']) ?>" title="Actions (or right-click the line)"><i class="bi bi-three-dots-vertical"></i></button></td></tr>
  <?php endforeach; ?>
  <?php if (!$lines): ?><tr><td colspan="11" class="text-muted text-center py-5"><?= $f['status'] === 'open' && !BillAccounts::filtered($f) && $f['q'] === '' ? 'No open bill: every bill is paid.' : 'No bill matches.' ?></td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="sv-grid-foot">
    <span><?= number_format($tot['n']) ?> bill<?= $tot['n'] === 1 ? '' : 's' ?> of <?= number_format($tot['accounts']) ?> account<?= $tot['accounts'] === 1 ? '' : 's' ?><?= $tot['overdue'] ? ', <span class="text-danger">' . number_format($tot['overdue']) . ' overdue (' . cm($tot['overdue_balance']) . ')</span>' : '' ?></span>
    <span class="sv-totals"><span>Amount <strong><?= cm($tot['amount']) ?></strong></span><span>Paid <strong><?= cm($tot['paid']) ?></strong></span><span>Balance <strong><?= cm($tot['balance']) ?></strong></span></span>
  </div>
  <?php if ($pages > 1): $first = ($pg - 1) * BillAccounts::PAGE_SIZE + 1; ?>
  <nav class="ba-pager" aria-label="Pages of bills">
    <span class="ba-pager-info">Lines <?= number_format($first) ?> to <?= number_format(min($tot['n'], $first + BillAccounts::PAGE_SIZE - 1)) ?> of <?= number_format($tot['n']) ?></span>
    <span class="ba-pager-btns">
      <a class="btn btn-sm btn-outline-secondary<?= $pg <= 1 ? ' disabled' : '' ?>" href="<?= h(current_url(['pg' => 1, 'partial' => null])) ?>" aria-label="First page"><i class="bi bi-chevron-double-left"></i></a>
      <a class="btn btn-sm btn-outline-secondary<?= $pg <= 1 ? ' disabled' : '' ?>" href="<?= h(current_url(['pg' => max(1, $pg - 1), 'partial' => null])) ?>"><i class="bi bi-chevron-left"></i> Previous</a>
      <span class="ba-pager-page">Page <?= $pg ?> of <?= number_format($pages) ?></span>
      <a class="btn btn-sm btn-outline-secondary<?= $pg >= $pages ? ' disabled' : '' ?>" href="<?= h(current_url(['pg' => min($pages, $pg + 1), 'partial' => null])) ?>">Next <i class="bi bi-chevron-right"></i></a>
      <a class="btn btn-sm btn-outline-secondary<?= $pg >= $pages ? ' disabled' : '' ?>" href="<?= h(current_url(['pg' => $pages, 'partial' => null])) ?>" aria-label="Last page"><i class="bi bi-chevron-double-right"></i></a>
    </span>
  </nav>
  <?php endif; ?>
<?php };
if (g('partial') === '1') {
    $grid();
    exit;
}

$opt = BillAccounts::options();
$filterOn = BillAccounts::filtered($f);
$canCollect = $waterCan('water_bills_collection');
$canSoa = $waterCan('generate_soa');
$canLedger = $waterCan('customer_ledger');
$canAccount = $waterCan('member_list');
$canPrint = Access::can($pageKey, 'print');
$collectUrl = url('water.water_bills_collection') . '?member_id={acct}';
$soaUrl = url('water.generate_soa') . '?tab=individual&account={no}';
$ledgerUrl = url('water.customer_ledger') . '?member_id={acct}';
$accountUrl = url('water.member_list') . '?search={no}';
$exportUrl = fn(string $fmt) => current_url(['export' => $fmt, 'pg' => null, 'partial' => null]);
coop_layout('Bill Accounts Center', ['js' => ['js/savings.js', 'js/bill_accounts.js'], 'actions' =>
    (Access::can('water.bill_balance_upload', 'create') ? btn_link(url('water.bill_balance_upload'), 'Upload Bill Balances', 'btn-outline-primary', 'cloud-arrow-up') . ' ' : '')
    . ($canCollect ? btn_link(url('water.water_bills_collection'), 'Collections', 'btn-outline-secondary', 'cash-coin') . ' ' : '')
    . ($canSoa ? btn_link(url('water.generate_soa') . '?tab=individual', 'Statement of Account', 'btn-outline-secondary', 'file-earmark-text') : '')]);
?>
<form class="sv-toolbar" id="baBar" role="search" data-live-search data-live-target="#baRows" action="<?= h(url($pageKey)) ?>">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="sv-tools" role="toolbar" aria-label="Bills">
    <?php if ($canCollect): ?><button type="button" class="sv-tool" data-need-row data-when="open:yes;water:yes" data-href="<?= h($collectUrl) ?>" title="Collect the account's bills (Collections)"><i class="bi bi-cash-stack"></i> Collection</button><?php endif; ?>
    <?php if ($canSoa): ?><button type="button" class="sv-tool" data-need-row data-when="water:yes" data-href="<?= h($soaUrl) ?>" title="The Statement of Account of the member or customer, its unpaid invoices"><i class="bi bi-file-earmark-text"></i> Statement</button><?php endif; ?>
    <?php if ($canLedger): ?><button type="button" class="sv-tool" data-need-row data-when="water:yes" data-href="<?= h($ledgerUrl) ?>" title="The ledger of the water-service account: its bills and payments"><i class="bi bi-journal-text"></i> Ledger</button><?php endif; ?>
    <?php if ($canAccount): ?><button type="button" class="sv-tool" data-need-row data-when="water:yes" data-href="<?= h($accountUrl) ?>" title="The water-service account (Water Service Accounts)"><i class="bi bi-person-vcard"></i> Account</button><?php endif; ?>
    <a class="sv-tool" href="<?= h(current_url(['partial' => null])) ?>" data-ba-refresh><i class="bi bi-arrow-clockwise"></i> Refresh</a>
    <button type="button" class="sv-tool <?= $filterOn ? 'is-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#baFilter" aria-expanded="<?= $filterOn ? 'true' : 'false' ?>" aria-controls="baFilter"><i class="bi bi-funnel"></i> Filter</button>
    <?php if ($canPrint): ?>
      <div class="dropdown ba-export">
        <button type="button" class="sv-tool dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-box-arrow-up-right"></i> Export</button>
        <ul class="dropdown-menu">
          <li><a class="dropdown-item" data-ba-export="xlsx" href="<?= h($exportUrl('xlsx')) ?>"><i class="bi bi-file-earmark-excel"></i> Excel workbook (.xlsx)</a></li>
          <li><a class="dropdown-item" data-ba-export="csv" href="<?= h($exportUrl('csv')) ?>"><i class="bi bi-filetype-csv"></i> CSV file (.csv)</a></li>
        </ul>
      </div>
      <a class="sv-tool" data-print-link target="_blank" rel="noopener" href="<?= h(current_url(['a' => 'print', 'pg' => null])) ?>" title="Print the bills found, or save them as a PDF file"><i class="bi bi-printer"></i> Print / PDF</a>
    <?php endif; ?>
    <?php if ($canSoa || $canLedger): ?><button type="button" class="sv-tool d-none" data-need-row data-when="water:yes" data-grid-default data-href="<?= h($canSoa ? $soaUrl : $ledgerUrl) ?>" aria-hidden="true" tabindex="-1">Open</button><?php endif; ?>
  </div>
  <div class="sv-tool-end">
    <div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($f['q']) ?>" placeholder="Search name, account or invoice no." autocomplete="off" aria-label="Search"></div>
  </div>
  <div class="collapse w-100 <?= $filterOn ? 'show' : '' ?>" id="baFilter"><div class="row g-2 pt-2 px-1">
    <?= select_field('status', 'Bill status', BillAccounts::STATUSES, $f['status'], ['class' => 'col-6 col-md-4 col-xl-3', 'extra' => 'data-no-combo']) ?>
    <?= select_field('code', 'Bill code', $opt['codes'], $f['code'], ['class' => 'col-6 col-md-4 col-xl-3', 'blank' => 'All bill codes', 'extra' => 'data-no-combo']) ?>
    <?= select_field('zone', 'Zone', $opt['zones'], $f['zone'], ['class' => 'col-6 col-md-4 col-xl-2', 'blank' => 'All zones', 'extra' => 'data-no-combo']) ?>
    <?= select_field('subgroup', 'Address sub-group', $opt['subgroups'], $f['subgroup'], ['class' => 'col-6 col-md-4 col-xl-2', 'blank' => 'All sub-groups', 'extra' => 'data-no-combo']) ?>
    <?= select_field('holder', 'Members or customers', BillAccounts::HOLDERS, $f['holder'], ['class' => 'col-6 col-md-4 col-xl-2', 'blank' => 'Both', 'extra' => 'data-no-combo']) ?>
    <?= select_field('type', 'Bill type', BillAccounts::TYPES, $f['type'], ['class' => 'col-6 col-md-4 col-xl-3', 'blank' => 'All bill types', 'extra' => 'data-no-combo']) ?>
    <?= input_field('from', 'Bill date from', $f['from'], ['class' => 'col-6 col-md-4 col-xl-2', 'type' => 'date']) ?>
    <?= input_field('to', 'Bill date to', $f['to'], ['class' => 'col-6 col-md-4 col-xl-2', 'type' => 'date']) ?>
    <?= select_field('sort', 'Sort by', BillAccounts::SORTS, $f['sort'], ['class' => 'col-6 col-md-4 col-xl-3', 'extra' => 'data-no-combo']) ?>
    <div class="col-6 col-md-4 col-xl-2 d-flex align-items-end"><a class="btn btn-sm btn-link px-0" href="<?= h(url($pageKey)) ?>"><i class="bi bi-x-circle"></i> Clear the filters</a></div>
  </div></div>
</form>
<div id="baRows"><?php $grid(); ?></div>
<p class="small text-muted mt-2 mb-0">The center opens on the open bills (not fully paid); under Filter, the bill status shows the closed (fully paid) bills, or both. Click a bill and use the toolbar, double-click it for the Statement of Account, or right-click it (on a phone: the <i class="bi bi-three-dots-vertical"></i> button or a long press) for its actions. An installment shows each amortization as a bill, dated on its due date.</p>

<div class="sv-menu" id="baMenu" role="menu" hidden>
  <div class="sv-menu-title" data-menu-title></div>
  <?php if ($canCollect): ?><button type="button" role="menuitem" data-action data-when="open:yes;water:yes" data-href="<?= h($collectUrl) ?>"><i class="bi bi-cash-stack"></i> Collection</button><?php endif; ?>
  <?php if ($canSoa): ?><button type="button" role="menuitem" data-action data-when="water:yes" data-href="<?= h($soaUrl) ?>"><i class="bi bi-file-earmark-text"></i> Statement of Account</button><?php endif; ?>
  <?php if ($canLedger): ?><button type="button" role="menuitem" data-action data-when="water:yes" data-href="<?= h($ledgerUrl) ?>"><i class="bi bi-journal-text"></i> Ledger</button><?php endif; ?>
  <?php if ($canAccount): ?><button type="button" role="menuitem" data-action data-when="water:yes" data-href="<?= h($accountUrl) ?>"><i class="bi bi-person-vcard"></i> Water-service account</button><?php endif; ?>
  <?php if (!$canCollect && !$canSoa && !$canLedger && !$canAccount): ?><button type="button" role="menuitem" data-action disabled><i class="bi bi-lock"></i> No action open to your role</button><?php endif; ?>
</div>
<?php
layout_end();
