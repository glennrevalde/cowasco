<?php
declare(strict_types=1);

/**
 * Loans > Loan Release Vouchers: the monitoring of every voucher of a loan release, apart from the other Banking
 * transactions. A loan released in cash is a Cash Voucher (Banking > Cash Voucher), one released by cheque a check
 * (Banking > Write Checks); the releases made before version 33 are loan release vouchers. Each row: the date, the
 * voucher ID, the kind of voucher and its number, the member paid, the loan, the cash or bank account, the loan, the
 * deductions and the amount paid out, the class, who prepared it and its status. Print a voucher, open it in Banking,
 * open the loan or its Loan Release Form; print or export the list.
 */
require_once __DIR__ . '/_coop.php';
require_once __DIR__ . '/_loan_voucher.php';
$pageKey = 'loan.vouchers';

// a voucher of the list, printed in the format of the former program; or opened where it was posted (Banking)
if (in_array(g('a'), ['print', 'open'], true) && gint('id')) {
    $t = Txn::load((int)gint('id'));
    $loanOf = $t ? LoanVouchers::loanOf((int)$t['id']) : null;
    if (!$t || !$loanOf) {
        flash('warning', 'Voucher not found.');
        redirect(url($pageKey));
    }
    if (g('a') === 'open') {
        redirect(LoanVouchers::url($t));
    }
    loan_voucher_page($t, $pageKey);
    exit;
}

$presets = ['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter',
    'this_year' => 'This fiscal year', 'all' => 'All dates', 'custom' => 'Custom'];
$range = array_key_exists((string)g('range'), $presets) ? (string)g('range') : 'this_month';
[$pf, $pt] = Report::preset($range === 'custom' ? 'this_month' : $range);
$from = $range === 'custom' ? gdate('from', $pf) : $pf;
$to = $range === 'custom' ? gdate('to', $pt) : $pt;
$kind = in_array(g('kind'), ['cash_voucher', 'check', 'loan_release'], true) ? (string)g('kind') : '';
$acct = gint('account');
$cls = gint('class');
$status = in_array(g('status'), ['posted', 'void'], true) ? (string)g('status') : '';
$q = trim((string)g('q'));

[$bf, $bp] = Coop::branchFilter('l.branch_id');
$where = "l.release_txn_id IS NOT NULL AND l.release_mode <> 'opening' AND t.txn_date BETWEEN :f AND :t" . $bf;
$p = ['f' => $from, 't' => $to] + $bp;
if ($kind !== '') {
    $where .= ' AND t.txn_type = :k';
    $p['k'] = $kind;
}
if ($acct) {
    $where .= ' AND t.account_id = :ac';
    $p['ac'] = $acct;
}
if ($cls) {
    [$csql, $cp] = Classes::sqlFilter($cls, 't', 'lvc');
    $where .= $csql;
    $p += $cp;
}
if ($status !== '') {
    $where .= ' AND t.is_voided = ' . ($status === 'void' ? 1 : 0);
}
if ($q !== '') {
    $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', $q) ?: [], fn($w) => $w !== '')), 0, 5);
    $cond = [];
    foreach ($words as $i => $w) {
        $cond[] = "m.full_name LIKE :w$i";
        $p["w$i"] = '%' . $w . '%';
    }
    $where .= ' AND ((' . implode(' AND ', $cond) . ') OR l.loan_no LIKE :q1 OR t.txn_no LIKE :q2 OR m.member_no = :q3' . (LoanVouchers::ready() ? ' OR l.voucher_no LIKE :q4' : '') . ')';
    $p += ['q1' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => $q] + (LoanVouchers::ready() ? ['q4' => '%' . $q . '%'] : []);
}
$limit = 1000;
$vno = LoanVouchers::ready() ? 'l.voucher_no' : 'NULL';
$rows = DB::all("SELECT t.id, t.txn_type, t.txn_no, t.txn_date, t.total, t.is_voided, t.account_id, t.class_id, t.created_by, t.approved_by, $vno AS voucher_no,
                        l.id AS loan_id, l.loan_no, l.principal, l.total_charges, l.previous_loan_payoff, l.net_proceeds, l.release_mode, l.status AS loan_status, l.application_id, l.created_by AS loan_created_by,
                        m.full_name, m.member_no, pr.name AS product_name, a.code AS acct_code, a.name AS acct_name, u.full_name AS prepared_by
                   FROM loan_accounts l JOIN transactions t ON t.id = l.release_txn_id JOIN members m ON m.id = l.member_id JOIN loan_products pr ON pr.id = l.product_id
                   LEFT JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = t.created_by
                  WHERE $where ORDER BY t.txn_date DESC, t.id DESC LIMIT $limit", $p);
$tot = ['n' => 0, 'p' => '0', 'd' => '0', 'net' => '0'];
foreach ($rows as $r) {
    if ((int)$r['is_voided'] === 1) {
        continue;
    }
    $tot['n']++;
    $tot['p'] = Dec::add($tot['p'], $r['principal'], 2);
    $tot['d'] = Dec::add($tot['d'], Dec::add($r['total_charges'], $r['previous_loan_payoff'], 2), 2);
    $tot['net'] = Dec::add($tot['net'], $r['net_proceeds'], 2);
}
$deduct = fn(array $r) => Dec::add($r['total_charges'], $r['previous_loan_payoff'], 2);

if (g('export') === 'csv' && Access::can($pageKey, 'print')) {
    Audit::log('export', 'loan', 'list', null, 'Loan release vouchers', ['page_key' => $pageKey]);
    csv_download('loan_release_vouchers.csv', ['Date', 'Voucher ID', 'Voucher', 'Voucher no.', 'Member no.', 'Paid to', 'Loan no.', 'Loan', 'Cash / bank account', 'Loan amount', 'Deductions', 'Paid out', 'Class', 'Prepared by', 'Status'],
        array_map(fn($r) => [$r['txn_date'], (string)($r['voucher_no'] ?: $r['txn_no']), LoanVouchers::label($r['txn_type']), $r['txn_no'], $r['member_no'], $r['full_name'], $r['loan_no'], $r['product_name'],
            $r['acct_code'] ? $r['acct_code'] . ' - ' . $r['acct_name'] : ($r['release_mode'] === 'savings' ? 'credited to savings' : ''), Dec::fix($r['principal']), Dec::fix($deduct($r)), Dec::fix($r['net_proceeds']),
            Classes::name($r['class_id']), (string)$r['prepared_by'], (int)$r['is_voided'] ? 'void' : 'posted'], $rows));
}

$grid = function () use ($rows, $tot, $limit, $deduct, $pageKey) { ?>
  <div class="sv-grid-wrap"><table class="table sv-grid" data-select-grid="#lvBar" data-context-menu="#lvMenu" aria-label="Loan release vouchers"><thead><tr>
    <th>Date</th><th>Voucher ID</th><th class="d-none d-md-table-cell">Voucher</th><th>Paid to</th><th class="d-none d-md-table-cell">Loan</th>
    <th class="d-none d-xxl-table-cell">Cash / bank account</th><th class="num d-none d-xxl-table-cell">Loan amount</th><th class="num d-none d-lg-table-cell">Deductions</th><th class="num">Paid out</th>
    <th class="d-none d-xl-table-cell">Class</th><th class="d-none d-xxl-table-cell">Prepared by</th><th class="text-end" aria-label="Actions"></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $void = (int)$r['is_voided'] === 1; $vid = (string)($r['voucher_no'] ?: $r['txn_no']); $bank = $r['txn_type'] !== 'loan_release'; ?>
    <tr data-row tabindex="0" class="<?= $void ? 'is-void' : '' ?>" data-id="<?= (int)$r['id'] ?>" data-loan="<?= (int)$r['loan_id'] ?>" data-bank="<?= $bank ? 'yes' : 'no' ?>" data-void="<?= $void ? 'yes' : 'no' ?>"
        data-title="<?= h($vid . '  ' . LoanVouchers::label($r['txn_type']) . '  ' . $r['full_name']) ?>">
      <td><?= h(fdate($r['txn_date'])) ?></td>
      <td class="fw-semibold"><?= h($vid) ?><div class="small text-muted fw-normal d-md-none"><?= h(LoanVouchers::label($r['txn_type'])) ?></div></td>
      <td class="d-none d-md-table-cell"><?= h(LoanVouchers::label($r['txn_type'])) ?><?= $void ? ' <span class="small text-danger">void</span>' : '' ?><div class="small text-muted">No. <?= h((string)$r['txn_no']) ?></div><div class="small text-muted d-xxl-none"><?= $r['acct_code'] ? h($r['acct_code'] . ' - ' . $r['acct_name']) : '' ?></div></td>
      <td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted"><?= h($r['member_no']) ?></div></td>
      <td class="d-none d-md-table-cell"><?= h($r['loan_no']) ?><div class="small text-muted"><?= h($r['product_name']) ?></div></td>
      <td class="d-none d-xxl-table-cell small wrap"><?= $r['acct_code'] ? h($r['acct_code'] . ' - ' . $r['acct_name']) : ($r['release_mode'] === 'savings' ? 'credited to savings' : '') ?></td>
      <td class="num d-none d-xxl-table-cell"><?= cm($r['principal']) ?></td><td class="num d-none d-lg-table-cell"><?= cm($deduct($r), true) ?></td><td class="num fw-semibold"><?= cm($r['net_proceeds']) ?></td>
      <td class="d-none d-xl-table-cell small"><?= h(Classes::name($r['class_id'])) ?></td><td class="d-none d-xxl-table-cell small"><?= h((string)$r['prepared_by']) ?></td>
      <td class="text-end"><button type="button" class="sv-more" data-row-menu aria-label="Actions for <?= h($vid) ?>" title="Actions (or right-click the row)"><i class="bi bi-three-dots-vertical"></i></button></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="12" class="text-muted text-center py-5">No voucher of a loan release in this period.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="sv-grid-foot"><span><?= number_format(count($rows)) ?> voucher(s)<?= count($rows) >= $limit ? ', the first ' . number_format($limit) . ' shown' : '' ?>; voids are left out of the totals</span>
    <span class="sv-totals"><span>Loans <strong><?= cm($tot['p']) ?></strong></span><span>Deductions <strong><?= cm($tot['d']) ?></strong></span><span>Paid out <strong><?= cm($tot['net']) ?></strong></span></span></div>
<?php };

if (g('partial') === '1') {
    $grid();
    exit;
}

// the list printed as it is shown
if (g('a') === 'print') {
    Access::require($pageKey, 'print');
    Audit::log('print', 'loan', 'list', null, 'Loan release vouchers', ['page_key' => $pageKey]);
    layout_start('Loan Release Vouchers', ['bare' => true]);
    echo '<div class="doc coop-doc lv-list-doc">' . coop_print_head('LOAN RELEASE VOUCHERS', '', '', ['Period' => fdate($from) . ' to ' . fdate($to)]);
    $grid();
    echo '</div><script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}

$filterOn = $kind !== '' || $acct || $cls || $status !== '';
$accounts = LoanVouchers::cashAccounts() + LoanVouchers::bankAccounts();
ksort($accounts);
coop_layout('Loan Release Vouchers', ['js' => ['js/savings.js'], 'actions' => page_link('loan.release', [], 'Loan Release', 'btn-outline-secondary', 'cash-coin')]);
?>
<form class="sv-toolbar" id="lvBar" role="search" data-live-search data-live-target="#lvRows" action="<?= h(url($pageKey)) ?>">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="sv-tools" role="toolbar" aria-label="Loan release vouchers">
    <?php if (Access::can($pageKey, 'print')): ?><button type="button" class="sv-tool is-primary" data-need-row data-grid-default data-target="_blank" data-href="<?= h(url($pageKey, ['a' => 'print'])) ?>&id={id}" title="Print the Cash / Check Voucher"><i class="bi bi-printer"></i> Print voucher</button><?php endif; ?>
    <button type="button" class="sv-tool" data-need-row data-href="<?= h(url($pageKey, ['a' => 'open'])) ?>&id={id}" title="The voucher in Banking (Write Checks, Cash Voucher)"><i class="bi bi-bank"></i> Open</button>
    <?php if (Access::page('loan.accounts')): ?><button type="button" class="sv-tool" data-need-row data-href="<?= h(url('loan.accounts')) ?>&id={loan}" title="The loan account"><i class="bi bi-cash-coin"></i> Loan</button><?php endif; ?>
    <button type="button" class="sv-tool" data-need-row data-href="<?= h(url('loan.release')) ?>&loan={loan}" title="The Loan Release Form of the loan"><i class="bi bi-file-earmark-text"></i> Release form</button>
    <span class="sv-tool-sep" aria-hidden="true"></span>
    <button type="button" class="sv-tool <?= $filterOn ? 'is-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#lvFilter" aria-expanded="<?= $filterOn ? 'true' : 'false' ?>" aria-controls="lvFilter"><i class="bi bi-funnel"></i> Filter</button>
    <a class="sv-tool" href="<?= h(current_url()) ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
    <?php if (Access::can($pageKey, 'print')): ?>
      <a class="sv-tool" data-print-link target="_blank" rel="noopener" href="<?= h(current_url(['a' => 'print'])) ?>"><i class="bi bi-printer"></i> Print list</a>
      <a class="sv-tool" href="<?= h(current_url(['export' => 'csv'])) ?>"><i class="bi bi-download"></i> Export</a>
    <?php endif; ?>
  </div>
  <div class="sv-tool-end">
    <select class="form-select form-select-sm" name="range" data-no-combo data-show-range="#lvRange" style="width:auto" aria-label="Date range"><?= options($presets, $range) ?></select>
    <div class="sv-range" id="lvRange"><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>" aria-label="From" style="width:auto"><span class="small">to</span><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>" aria-label="To" style="width:auto"></div>
    <div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Search: member, loan no., voucher ID or no." autocomplete="off" aria-label="Search"></div>
  </div>
  <div class="collapse w-100 <?= $filterOn ? 'show' : '' ?>" id="lvFilter"><div class="row g-2 pt-2 px-1">
    <?= select_field('kind', 'Voucher', ['cash_voucher' => 'Cash Vouchers (released in cash)', 'check' => 'Check Vouchers (released by cheque)', 'loan_release' => 'Loan release vouchers (before version 33)'], $kind, ['class' => 'col-6 col-md-3', 'blank' => 'Every voucher', 'extra' => 'data-no-combo']) ?>
    <?= select_field('account', 'Cash / bank account', $accounts, $acct, ['class' => 'col-6 col-md-3', 'blank' => 'Every account', 'extra' => 'data-no-combo']) ?>
    <?php if (Classes::inUse()): ?><?= select_field('class', 'Class', Classes::filterOptions(), $cls, ['class' => 'col-6 col-md-3', 'blank' => 'Every class', 'extra' => 'data-no-combo']) ?><?php endif; ?>
    <?= select_field('status', 'Status', ['posted' => 'Posted', 'void' => 'Void'], $status, ['class' => 'col-6 col-md-3', 'blank' => 'Posted and void', 'extra' => 'data-no-combo']) ?>
  </div></div>
</form>
<div id="lvRows"><?php $grid(); ?></div>
<p class="small text-muted mt-2 mb-0">Every voucher of a loan release: a release in cash is a Cash Voucher of Banking, a release by cheque a check of Write Checks. Click a voucher and use the toolbar, double-click it to print it, or right-click it (on a phone: the <i class="bi bi-three-dots-vertical"></i> button) for its actions. A voucher is corrected by voiding it in Banking, which cancels the loan while it has no payment.</p>

<div class="sv-menu" id="lvMenu" role="menu" hidden>
  <div class="sv-menu-title" data-menu-title></div>
  <?php if (Access::can($pageKey, 'print')): ?><button type="button" role="menuitem" data-action data-target="_blank" data-href="<?= h(url($pageKey, ['a' => 'print'])) ?>&id={id}"><i class="bi bi-printer"></i> Print voucher</button><?php endif; ?>
  <button type="button" role="menuitem" data-action data-href="<?= h(url($pageKey, ['a' => 'open'])) ?>&id={id}"><i class="bi bi-bank"></i> Open the voucher</button>
  <?php if (Access::page('loan.accounts')): ?><button type="button" role="menuitem" data-action data-href="<?= h(url('loan.accounts')) ?>&id={loan}"><i class="bi bi-cash-coin"></i> Open the loan</button><?php endif; ?>
  <button type="button" role="menuitem" data-action data-href="<?= h(url('loan.release')) ?>&loan={loan}"><i class="bi bi-file-earmark-text"></i> Loan Release Form</button>
  <?php if (Access::page('loan.docs')): ?><button type="button" role="menuitem" data-action data-target="_blank" data-href="<?= h(url('loan.docs', ['doc' => 'release'])) ?>&id={loan}"><i class="bi bi-file-earmark-check"></i> Loan Release Statement</button><?php endif; ?>
</div>
<?php
layout_end();
