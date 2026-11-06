<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
require_once __DIR__ . '/_loan_ledgers.php';
$pageKey = 'loan.accounts';
$loan = gint('id') ? Loans::loan((int)gint('id')) : null;
if ($loan && !Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
    Access::denyPage($pageKey, 'open a loan of another branch on');
}

// ------------------------------------------------------------------ Import Loans: a CSV file of the loans of the former system, reviewed, then made Active
if (g('a') === 'import') {
    Access::require($pageKey, 'create');
    $accounts = LoanImport::accounts();
    $defAcct = (string)(DB::value('SELECT a.code FROM accounts a WHERE a.id = :id', ['id' => Accts::get('opening_equity')]) ?: '');
    $opt = fn() => ['cut_off_date' => (string)(p('cut_off_date') ?: today()), 'balancing_account' => trim((string)p('balancing_account', $defAcct))];
    $o = is_post() ? $opt() : ['cut_off_date' => today(), 'balancing_account' => $defAcct];
    $cfg = [
        'cols' => LoanImport::cols(), 'aliases' => LoanImport::aliases(), 'noun' => ['loan', 'loans'], 'total' => 'principal', 'total_label' => 'Principal',
        'template_name' => 'import_loans_template.csv', 'samples' => LoanImport::samples(), 'opt' => $opt,
        'check' => fn(array $rows, array $o) => LoanImport::check($rows, $o),
        'post' => function (array $rows, array $o, string $file) use ($pageKey) {
            $r = LoanImport::post($rows, $o, $file, $pageKey);
            return $r['count'] . ' loan(s) imported and Active, ' . Dec::money($r['total']) . ' in principal balances: ' . implode(', ', array_slice($r['refs'], 0, 8)) . (count($r['refs']) > 8 ? ' ...' : '') . '.';
        },
        'can_post' => Access::can($pageKey, 'approve'), 'no_post' => 'You may read and check the file; uploading it (loans made Active with their balances) needs the Approve right on the Loan Accounts Center.',
        'done' => url($pageKey), 'confirm' => 'Upload the loans? Each one is made Active with its schedule, what was paid on it, and its balances at the cut-off date.',
        'fields' => select_field('balancing_account', 'Balancing GL account (the other side of the journal entry)', $accounts, $o['balancing_account'], ['class' => 'col-md-7', 'extra' => 'data-no-combo'])
            . input_field('cut_off_date', 'Cut-off date', $o['cut_off_date'], ['class' => 'col-md-5', 'type' => 'date'])
            . '<div class="col-12 form-text mt-0">Both fill the cells left blank in the file. Each loan\'s principal balance is debited to the loan receivable of its product and credited to this account (for a migration, normally Opening Balance Equity).</div>',
        'intro' => 'The loans of the former system, one row per loan, as it was released (loan product, principal, terms, mode of payment, amortization type, rate, release date) with what was paid on it up to the cut-off date. '
            . 'Each loan is made <strong>Active</strong> with its schedule; the totals paid are taken as paid on the due dates, in order. The past due interest of a matured loan and the overdue penalties are worked out unless the file gives them. Import the members first.',
        'help' => 'Amounts as 50000.00 or 50,000.00. Terms as 12 months or 180 days. The mode of payment and the amortization type as their names. Past due interest and overdue penalties are optional: blank, the program works them out.',
        'extra' => '',
    ];
    $state = CsvReview::handle($cfg);
    coop_layout('Import loans', ['actions' => btn_link(url($pageKey), 'Loan Accounts Center', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/csv_review.js']]);
    echo CsvReview::render($cfg, $state);
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the Loan Ledger Form, the Internal Ledger and the schedule
// (the toolbar of the center opens them in a dialog; print=1 prints them)
if (in_array(g('a'), ['ledger', 'internal', 'schedule'], true)) {
    $isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    if (!$loan) {
        if ($isFetch) {
            echo '<div class="text-muted p-3">Loan not found.</div>';
            exit;
        }
        redirect(url($pageKey));
    }
    $print = g('print') === '1';
    if (!$print && !$isFetch) {
        // the dialog's content is a part of a page: opened on its own, it is the loan
        redirect(url($pageKey, ['id' => $loan['id'], 'tab' => g('a') === 'schedule' ? 'schedule' : 'ledger']));
    }
    if ($print) {
        Access::require($pageKey, 'print');
        Audit::log('print', 'loan', 'loan_account', (int)$loan['id'], $loan['loan_no'] . ' ' . g('a'), ['page_key' => $pageKey]);
        layout_start(['ledger' => 'Loan Ledger', 'internal' => 'Internal Ledger', 'schedule' => 'Amortization Schedule'][g('a')] . ' ' . $loan['loan_no'], ['bare' => true]);
        echo '<div class="doc coop-doc la-print">';
    }
    switch (g('a')) {
        case 'ledger':
            la_ledger((array)$loan, g('view') === 'all', $print);
            break;
        case 'internal':
            la_internal((array)$loan, gdate('asof', today()), $print);
            break;
        default:
            la_schedule((array)$loan, $print);
    }
    if ($print) {
        echo '</div><script>window.addEventListener("load", function () { window.print(); });</script>';
        layout_end();
    }
    exit;
}

handle_post(function () use ($loan, $pageKey) {
    if (!$loan) {
        fail('Loan not found.');
    }
    switch ((string)p('action')) {
        case 'note':
            Access::require($pageKey, 'edit');
            $note = mb_substr(trim((string)p('notes')), 0, 255);
            DB::update('loan_accounts', ['notes' => $note ?: null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $loan['id']]);
            Audit::log('edit', 'loan', 'loan_account', (int)$loan['id'], $loan['loan_no'], ['page_key' => $pageKey, 'changes' => [['field' => 'notes', 'old' => $loan['notes'], 'new' => $note]]]);
            flash('success', 'Note saved.');
            break;
        case 'collateral':
            Access::require($pageKey, 'edit');
            $c = DB::row('SELECT * FROM loan_collaterals WHERE id = :c AND application_id = :a', ['c' => pint('collateral_id'), 'a' => $loan['application_id']]);
            if (!$c || $c['status'] === 'released') {
                fail('Collateral not found or already released.');
            }
            if (in_array($loan['status'], Loans::OPEN_STATUSES, true) && !Access::can($pageKey, 'approve')) {
                fail('The loan is still open. Releasing its collateral early needs the Approve right on this page.');
            }
            $date = (string)p('released_on', today());
            DB::update('loan_collaterals', ['status' => 'released', 'released_on' => valid_date($date) ? $date : today()], 'id = :id', ['id' => $c['id']]);
            Audit::log('release', 'loan', 'loan_collateral', (int)$c['id'], $loan['loan_no'] . ': ' . $c['collateral_type'] . ' ' . $c['description'], ['page_key' => $pageKey, 'reason' => trim((string)p('reason')) ?: null]);
            flash('success', 'Collateral marked released.');
            break;
        case 'refresh':
            Access::require($pageKey, 'edit');
            Loans::rebuild((int)$loan['id']);
            flash('success', 'Balances and status recomputed from the loan ledger.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect(url($pageKey, ['id' => $loan['id'], 'tab' => g('tab') ?: 'summary']));
});

// --------------------------------------------------------------------------------------- the center
// The Loan Accounts Center of the former program: Collection, Ledger, Internal Ledger, View Schedule, Update Passbook,
// Refresh, Filter, Print and Search over the grid Account No., Member name, Loan Name, Release Date, Due Date, Principal
// Loan, Principal Balance, Interest Balance and Current Balance (principal + interest balance).
if (!$loan) {
    Loans::refreshStatuses();
    $status = (string)g('status', 'open');
    $q = trim((string)g('q'));
    $product = gint('product');
    $officer = gint('officer');
    $branch = gint('branch');
    [$bf, $bp] = Coop::branchFilter('l.branch_id');
    $where = '1 = 1' . $bf;
    $p = $bp;
    if ($status === 'open') {
        $where .= " AND l.status IN ('current','past_due','restructured')";
    } elseif ($status === 'due_week') {
        $where .= " AND l.status IN ('current','past_due','restructured') AND EXISTS (SELECT 1 FROM loan_schedule s WHERE s.loan_id = l.id AND s.is_paid = 0 AND s.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))";
    } elseif ($status === 'matured') {
        $where .= " AND l.status IN ('current','past_due','restructured') AND l.due_date < CURDATE()";
    } elseif (isset(Loans::LOAN_STATUSES[$status])) {
        $where .= ' AND l.status = :st';
        $p['st'] = $status;
    }
    if ($q !== '') {
        // every word of the member's name, or the loan no., the member no. or the PN no.
        $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', $q) ?: [], fn($w) => $w !== '')), 0, 5);
        $cond = [];
        foreach ($words as $i => $w) {
            $cond[] = "m.full_name LIKE :w$i";
            $p["w$i"] = '%' . $w . '%';
        }
        $where .= ' AND ((' . implode(' AND ', $cond) . ') OR l.loan_no LIKE :q1 OR m.member_no = :q3 OR l.pn_no = :q4)';
        $p += ['q1' => '%' . $q . '%', 'q3' => $q, 'q4' => $q];
    }
    if ($product) {
        $where .= ' AND l.product_id = :pr';
        $p['pr'] = $product;
    }
    if ($officer) {
        $where .= ' AND l.officer_id = :of';
        $p['of'] = $officer;
    }
    if ($branch) {
        $where .= ' AND l.branch_id = :br';
        $p['br'] = $branch;
    }
    $limit = 1000;
    $rows = DB::all("SELECT l.id, l.loan_no, l.pn_no, l.status, l.release_date, l.due_date, l.principal, l.principal_balance, l.interest_balance, l.penalty_balance, l.release_mode,
                            m.full_name, m.member_no, pr.name AS product_name, pr.passbook_type_id,
                            (SELECT COUNT(*) FROM loan_txns x WHERE x.loan_id = l.id AND x.pb_printed = 0 AND x.is_voided = 0 AND x.kind IN ('" . implode("','", Passbook::LOAN_KINDS) . "')) AS unprinted
                       FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products pr ON pr.id = l.product_id
                      WHERE $where ORDER BY pr.sort_order, pr.name, l.loan_no LIMIT $limit", $p);
    $tot = DB::row("SELECT COUNT(*) AS n, COALESCE(SUM(l.principal),0) AS p, COALESCE(SUM(l.principal_balance),0) AS pb, COALESCE(SUM(l.interest_balance),0) AS ib,
                           COALESCE(SUM(CASE WHEN l.status = 'past_due' THEN 1 ELSE 0 END),0) AS pd
                      FROM loan_accounts l JOIN members m ON m.id = l.member_id JOIN loan_products pr ON pr.id = l.product_id WHERE $where", $p);
    $today = today();
    $grid = function () use ($rows, $tot, $limit, $today) { ?>
      <div class="sv-grid-wrap"><table class="table sv-grid la-grid" data-select-grid="#laBar" data-context-menu="#laMenu" aria-label="Loan accounts"><thead><tr>
        <th>Account No.</th><th>Member name</th><th class="d-none d-md-table-cell">Loan Name</th><th class="d-none d-lg-table-cell">Release Date</th><th class="d-none d-sm-table-cell">Due Date</th>
        <th class="num d-none d-lg-table-cell">Principal Loan</th><th class="num d-none d-md-table-cell">Principal Balance</th><th class="num d-none d-md-table-cell">Interest Balance</th><th class="num">Current Balance</th>
        <th class="text-end" aria-label="Actions"></th></tr></thead><tbody>
      <?php foreach ($rows as $r): $open = in_array($r['status'], Loans::OPEN_STATUSES, true); $late = $open && $r['due_date'] < $today; ?>
        <tr data-row tabindex="0" class="<?= $open ? '' : 'is-closed' ?>" data-id="<?= (int)$r['id'] ?>" data-no="<?= h($r['loan_no']) ?>" data-open="<?= $open ? 'yes' : 'no' ?>"
            data-pb="<?= (int)$r['passbook_type_id'] ? 'yes' : 'no' ?>" data-unprinted="<?= (int)$r['unprinted'] > 0 ? 'yes' : 'no' ?>" data-title="<?= h($r['loan_no'] . '  ' . $r['full_name'] . '  ' . $r['product_name']) ?>">
          <td class="fw-semibold"><?= h($r['loan_no']) ?><div class="small fw-normal d-md-none"><?= h($r['product_name']) ?></div><?= $r['status'] !== 'current' ? '<div class="small fw-normal">' . coop_status($r['status']) . '</div>' : '' ?></td>
          <td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted"><?= h($r['member_no']) ?></div></td>
          <td class="d-none d-md-table-cell"><?= h($r['product_name']) ?></td>
          <td class="d-none d-lg-table-cell"><?= h(fdate($r['release_date'])) ?></td>
          <td class="d-none d-sm-table-cell<?= $late ? ' text-danger fw-semibold' : '' ?>"<?= $late ? ' title="Matured and not yet paid"' : '' ?>><?= h(fdate($r['due_date'])) ?></td>
          <td class="num d-none d-lg-table-cell"><?= cm($r['principal']) ?></td><td class="num d-none d-md-table-cell"><?= cm($r['principal_balance']) ?></td><td class="num d-none d-md-table-cell"><?= cm($r['interest_balance']) ?></td>
          <td class="num fw-semibold"><?= cm(Dec::add($r['principal_balance'], $r['interest_balance'], 2)) ?></td>
          <td class="text-end"><button type="button" class="sv-more" data-row-menu aria-label="Actions for <?= h($r['loan_no']) ?>" title="Actions (or right-click the row)"><i class="bi bi-three-dots-vertical"></i></button></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="text-muted text-center py-5">No loan matches.</td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="sv-grid-foot"><span><?= number_format((int)$tot['n']) ?> loan(s)<?= (int)$tot['pd'] ? ', ' . (int)$tot['pd'] . ' past due' : '' ?><?= (int)$tot['n'] > $limit ? '; the first ' . number_format($limit) . ' shown: type more of the name' : '' ?></span>
        <span class="sv-totals"><span>Principal loans <strong><?= cm($tot['p']) ?></strong></span><span>Principal balance <strong><?= cm($tot['pb']) ?></strong></span><span>Interest balance <strong><?= cm($tot['ib']) ?></strong></span><span>Current balance <strong><?= cm(Dec::add($tot['pb'], $tot['ib'], 2)) ?></strong></span></span></div>
    <?php };
    if (g('partial') === '1') {
        $grid();
        exit;
    }
    if (g('a') === 'print') {
        Access::require($pageKey, 'print');
        Audit::log('print', 'loan', 'list', null, 'Loan Accounts Center', ['page_key' => $pageKey]);
        layout_start('Loan Accounts', ['bare' => true]);
        echo '<div class="doc coop-doc la-print">' . coop_print_head('LOAN ACCOUNTS', '', today(), ['Status' => ['open' => 'Open loans', 'due_week' => 'Due in 7 days', 'matured' => 'Matured, unpaid', 'all' => 'Every loan'][$status] ?? (Loans::LOAN_STATUSES[$status] ?? $status)]);
        $grid();
        echo '</div><script>window.addEventListener("load", function () { window.print(); });</script>';
        layout_end();
        exit;
    }
    $officers = DB::pairs('SELECT DISTINCT u.id, u.full_name FROM loan_accounts l JOIN users u ON u.id = l.officer_id ORDER BY u.full_name');
    $filterOn = $status !== 'open' || $product || $officer || $branch;
    $canCollect = Access::can('loan.collection', 'create');
    $canPb = Access::can('sav.passbook', 'print');
    coop_layout('Loan Accounts Center', ['js' => ['js/savings.js'], 'actions' => (Access::can($pageKey, 'create') ? btn_link(url($pageKey, ['a' => 'import']), 'Import Loans', 'btn-outline-primary', 'filetype-csv') . ' ' : '')
        . page_link('loan.applications', [], 'Applications', 'btn-outline-secondary', 'inbox') . ' ' . page_link('loan.release', [], 'Release', 'btn-outline-secondary', 'cash-coin')]);
    ?>
    <form class="sv-toolbar" id="laBar" role="search" data-live-search data-live-target="#laRows" action="<?= h(url($pageKey)) ?>">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <div class="sv-tools" role="toolbar" aria-label="Loan accounts">
        <?php if ($canCollect): ?><button type="button" class="sv-tool" data-need-row data-when="open:yes" data-href="<?= h(url('loan.collection')) ?>&loan={id}" title="Collect a payment on the loan (Loan Collection)"><i class="bi bi-cash-stack"></i> Collection</button><?php endif; ?>
        <button type="button" class="sv-tool" data-need-row data-modal="#laLedger" title="The Loan Ledger Form (passbook format), printable"><i class="bi bi-journal-text"></i> Ledger</button>
        <button type="button" class="sv-tool" data-need-row data-modal="#laInternal" title="The ledger with the additional interest and penalty computed to date"><i class="bi bi-info-square"></i> Internal Ledger</button>
        <button type="button" class="sv-tool" data-need-row data-modal="#laSched" title="The amortization schedule"><i class="bi bi-calendar3"></i> View Schedule</button>
        <?php if ($canPb): ?><button type="button" class="sv-tool" data-need-row data-when="pb:yes" data-href="<?= h(url('sav.passbook', ['ledger' => 'loan'])) ?>&account={no}" title="Print the lines not yet in the loan passbook"><i class="bi bi-journal-arrow-down"></i> Update Passbook</button><?php endif; ?>
        <a class="sv-tool" href="<?= h(current_url()) ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
        <button type="button" class="sv-tool <?= $filterOn ? 'is-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#laFilter" aria-expanded="<?= $filterOn ? 'true' : 'false' ?>" aria-controls="laFilter"><i class="bi bi-funnel"></i> Filter</button>
        <?php if (Access::can($pageKey, 'print')): ?><a class="sv-tool" data-print-link target="_blank" rel="noopener" href="<?= h(current_url(['a' => 'print'])) ?>"><i class="bi bi-printer"></i> Print</a><?php endif; ?>
        <button type="button" class="sv-tool d-none" data-need-row data-grid-default data-href="<?= h(url($pageKey)) ?>&id={id}" aria-hidden="true" tabindex="-1">Open</button>
      </div>
      <div class="sv-tool-end">
        <div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Search: member name, account no., member or PN no." autocomplete="off" aria-label="Search"></div>
      </div>
      <div class="collapse w-100 <?= $filterOn ? 'show' : '' ?>" id="laFilter"><div class="row g-2 pt-2 px-1">
        <?= select_field('status', 'Status', ['open' => 'Open (current, past due, restructured)', 'due_week' => 'Due in 7 days', 'matured' => 'Matured, unpaid'] + Loans::LOAN_STATUSES + ['all' => 'Every loan'], $status, ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
        <?= select_field('product', 'Loan', Loans::productOptions(false), $product, ['class' => 'col-6 col-md-3', 'blank' => 'All loans', 'extra' => 'data-no-combo']) ?>
        <?= select_field('officer', 'Officer', $officers, $officer, ['class' => 'col-6 col-md-3', 'blank' => 'All officers', 'extra' => 'data-no-combo']) ?>
        <?= select_field('branch', 'Branch', Coop::branchOptions(), $branch, ['class' => 'col-6 col-md-3', 'blank' => 'All branches', 'extra' => 'data-no-combo']) ?>
      </div></div>
    </form>
    <div id="laRows"><?php $grid(); ?></div>
    <p class="small text-muted mt-2 mb-0">Click a loan and use the toolbar, double-click it to open the loan, or right-click it (on a phone: the <i class="bi bi-three-dots-vertical"></i> button or a long press) for its actions. Current Balance is the principal balance plus the interest balance.</p>

    <div class="sv-menu" id="laMenu" role="menu" hidden>
      <div class="sv-menu-title" data-menu-title></div>
      <button type="button" role="menuitem" data-action data-href="<?= h(url($pageKey)) ?>&id={id}"><i class="bi bi-box-arrow-up-right"></i> Open the loan</button>
      <?php if ($canCollect): ?><button type="button" role="menuitem" data-action data-when="open:yes" data-href="<?= h(url('loan.collection')) ?>&loan={id}"><i class="bi bi-cash-stack"></i> Collection</button><?php endif; ?>
      <hr>
      <button type="button" role="menuitem" data-action data-modal="#laLedger"><i class="bi bi-journal-text"></i> Ledger</button>
      <button type="button" role="menuitem" data-action data-modal="#laInternal"><i class="bi bi-info-square"></i> Internal Ledger</button>
      <button type="button" role="menuitem" data-action data-modal="#laSched"><i class="bi bi-calendar3"></i> View Schedule</button>
      <?php if ($canPb): ?><button type="button" role="menuitem" data-action data-when="pb:yes" data-href="<?= h(url('sav.passbook', ['ledger' => 'loan'])) ?>&account={no}"><i class="bi bi-journal-arrow-down"></i> Update Passbook</button><?php endif; ?>
      <hr>
      <?php if (Access::page('loan.docs')): ?><button type="button" role="menuitem" data-action data-href="<?= h(url('loan.docs')) ?>&id={id}"><i class="bi bi-file-earmark-text"></i> Documents</button><?php endif; ?>
      <?php if (Access::page('loan.release')): ?><button type="button" role="menuitem" data-action data-href="<?= h(url('loan.release')) ?>&loan={id}"><i class="bi bi-file-earmark-check"></i> Loan Release Form</button><?php endif; ?>
    </div>

    <?php
    // the three dialogs: their content is the loan of the selected row, fetched when they open
    $dialog = function (string $id, string $title, string $load, string $foot) {
        return '<div class="modal fade la-modal" id="' . h($id) . '" tabindex="-1" aria-labelledby="' . h($id) . 'T"><div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down"><div class="modal-content">'
            . '<div class="modal-header"><h5 class="modal-title" id="' . h($id) . 'T">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            . '<div class="modal-body" data-row-load="' . h($load) . '"><div class="la-loading">Loading...</div></div>'
            . '<div class="modal-footer">' . $foot . '</div></div></div></div>';
    };
    $printBtn = fn(string $a) => Access::can($pageKey, 'print') ? '<a class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener" data-load-print="' . h($a) . '" href="#"><i class="bi bi-printer"></i> Print</a>' : '';
    echo $dialog('laLedger', 'Loan Ledger Form', url($pageKey, ['a' => 'ledger']) . '&id={id}',
        '<div class="la-views me-auto" role="radiogroup" aria-label="View"><div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="la_view" id="laViewPb" value="passbook" checked data-load-param="view"><label class="form-check-label" for="laViewPb">Passbook View</label></div>'
        . '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="la_view" id="laViewAll" value="all" data-load-param="view"><label class="form-check-label" for="laViewAll">View All</label></div></div>'
        . $printBtn('ledger') . '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>');
    echo $dialog('laInternal', 'Internal Ledger Form', url($pageKey, ['a' => 'internal']) . '&id={id}',
        '<div class="la-views me-auto"><label class="form-label mb-0 me-2" for="laAsOf">Computed to</label><input class="form-control form-control-sm d-inline-block w-auto" type="date" id="laAsOf" value="' . h(today()) . '" data-load-param="asof"></div>'
        . $printBtn('internal') . '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>');
    echo $dialog('laSched', 'Amortization Schedule', url($pageKey, ['a' => 'schedule']) . '&id={id}',
        $printBtn('schedule') . '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>');
    layout_end();
    exit;
}

// --------------------------------------------------------------------------------------- one loan
$asOf = gdate('asof', today());
$open = in_array($loan['status'], Loans::OPEN_STATUSES, true);
$d = $open ? Loans::dues($loan, $asOf) : null;
$tab = in_array(g('tab'), ['summary', 'schedule', 'ledger', 'penalty', 'security', 'history'], true) ? (string)g('tab') : 'summary';
$m = Coop::member((int)$loan['member_id']);
$app = $loan['application_id'] ? DB::row('SELECT * FROM loan_applications WHERE id = :a', ['a' => $loan['application_id']]) : null;
$rel = $loan['release_txn_id'] ? Txn::load((int)$loan['release_txn_id']) : null;

$actions = [];
if ($open && Access::can('loan.collection', 'create')) {
    $actions[] = btn_link(url('loan.collection', ['loan' => $loan['id']]), 'Collect', 'btn-primary', 'cash-stack');
}
if (Access::page('loan.docs')) {
    $actions[] = btn_link(url('loan.docs', ['id' => $loan['id']]), 'Documents', 'btn-outline-secondary', 'file-earmark-text');
}
$more = [];
if ($open && Access::page('loan.waivers')) {
    $more[] = '<li><a class="dropdown-item" href="' . h(url('loan.waivers', ['loan' => $loan['id']])) . '">Waive interest or penalty</a></li>';
}
if ($open && Access::page('loan.adjustments')) {
    $more[] = '<li><a class="dropdown-item" href="' . h(url('loan.adjustments', ['loan' => $loan['id']])) . '">Adjust balances</a></li>';
}
if (Access::page('loan.restructure')) {
    $more[] = '<li><a class="dropdown-item" href="' . h(url('loan.restructure', ['loan' => $loan['id']])) . '">' . ($loan['status'] === 'written_off' ? 'Record a recovery' : 'Renew, restructure or write off') . '</a></li>';
}
if ($rel) {
    $more[] = '<li><a class="dropdown-item" href="' . h(url('loan.release', ['loan' => $loan['id']])) . '">Loan Release Form</a></li>';
    if (Access::can('loan.release', 'print')) {
        $more[] = '<li><a class="dropdown-item" target="_blank" rel="noopener" href="' . h(url('loan.release', ['a' => 'voucher', 'id' => $rel['id']])) . '">' . h(LoanVouchers::label($rel['txn_type'])) . ' ' . h(LoanVouchers::voucherId($loan, $rel)) . '</a></li>';
    }
}
$more[] = '<li><hr class="dropdown-divider"></li>';
$more[] = '<li><a class="dropdown-item" target="_blank" rel="noopener" href="' . h(url($pageKey, ['a' => 'ledger', 'id' => $loan['id'], 'print' => 1])) . '">Print the Loan Ledger (passbook view)</a></li>';
$more[] = '<li><a class="dropdown-item" target="_blank" rel="noopener" href="' . h(url($pageKey, ['a' => 'internal', 'id' => $loan['id'], 'print' => 1])) . '">Print the Internal Ledger</a></li>';
if ($app) {
    $more[] = '<li><a class="dropdown-item" href="' . h(url('loan.application', ['id' => $app['id']])) . '">Application ' . h($app['application_no']) . '</a></li>';
}
if ($more) {
    $actions[] = '<div class="btn-group"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">More</button><ul class="dropdown-menu dropdown-menu-end">' . implode('', $more) . '</ul></div>';
}
$actions[] = btn_link(url($pageKey), 'Loans', 'btn-outline-secondary', 'arrow-left');
coop_layout('Loan ' . $loan['loan_no'], ['actions' => implode(' ', $actions)]);
$aging = $open ? Loans::aging($loan, $asOf, 'par') : null;
?>
<?php if ($loan['status'] === 'written_off'): ?><div class="alert alert-secondary">Written off <?= h(fdate($loan['written_off_on'])) ?>. <?= h((string)$loan['notes']) ?> Recovered so far: <?= cm($loan['recovered_amount']) ?>.</div><?php endif; ?>
<?php if ($loan['restructured_to_id']): $to = Loans::loan((int)$loan['restructured_to_id']); ?><div class="alert alert-light small"><i class="bi bi-arrow-right-circle"></i> Paid off <?= h(fdate($loan['closed_on'])) ?> by loan <?= safe_link($pageKey, ['id' => $to['id']], $to['loan_no']) ?>.</div><?php endif; ?>
<?php if ($loan['restructured_from_id']): $from = Loans::loan((int)$loan['restructured_from_id']); ?><div class="alert alert-light small"><i class="bi bi-arrow-left-circle"></i> Released to pay off loan <?= safe_link($pageKey, ['id' => $from['id']], $from['loan_no']) ?> (<?= cm($loan['previous_loan_payoff']) ?>).</div><?php endif; ?>
<div class="mb-3"><?= member_card($m) ?></div>
<?php if ($open): ?>
<div class="coop-tiles">
  <div class="coop-tile"><div class="t-label">Principal balance</div><div class="t-value"><?= cm($loan['principal_balance']) ?></div><div class="t-sub">of <?= cm($loan['principal']) ?></div></div>
  <div class="coop-tile <?= $d['count_due'] ? 'is-bad' : 'is-ok' ?>"><div class="t-label">Due as of <?= h(fdate($asOf)) ?></div><div class="t-value"><?= cm($d['total_due']) ?></div><div class="t-sub"><?= (int)$d['count_due'] ?> amortization(s) unpaid</div></div>
  <div class="coop-tile <?= Dec::isPos($d['penalty_due']) ? 'is-warn' : '' ?>"><div class="t-label">Penalty</div><div class="t-value"><?= cm($d['penalty_due']) ?></div><div class="t-sub"><?= Dec::isPos($d['penalty_new']) ? cm($d['penalty_new']) . ' not yet posted' : 'all posted' ?></div></div>
  <div class="coop-tile"><div class="t-label">Payoff today</div><div class="t-value"><?= cm($d['payoff']) ?></div><div class="t-sub"><?= $loan['interest_method'] === 'diminishing' ? 'interest to date ' . cm($d['interest_to_date']) : 'all scheduled interest' ?></div></div>
  <div class="coop-tile <?= $aging['days'] > 0 ? 'is-bad' : '' ?>"><div class="t-label">Days past due</div><div class="t-value"><?= (int)$aging['days'] ?></div><div class="t-sub"><?= h(Loans::BUCKETS[Loans::bucket((int)$aging['days'])]) ?>; next due <?= h(fdate((string)$d['next_due'])) ?></div></div>
</div>
<?php endif; ?>
<?= coop_tabs(['summary' => 'Summary', 'schedule' => 'Amortization schedule', 'ledger' => 'Loan ledger', 'penalty' => 'Penalty computation', 'security' => 'Co-makers and collateral', 'history' => 'Notices and prints'], $tab, $pageKey, ['id' => $loan['id']]) ?>
<?php if ($tab === 'summary'):
    $charges = DB::all('SELECT * FROM loan_charges_applied WHERE loan_id = :l', ['l' => $loan['id']]); ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-body"><?= coop_kv([
      'Loan no.' => '<strong>' . h($loan['loan_no']) . '</strong>', 'Promissory note' => h((string)$loan['pn_no']), 'Product' => h($loan['product_code'] . ' ' . $loan['product_name']),
      'Status' => coop_status($loan['status']), 'Principal' => cm($loan['principal']), 'Rate' => h(Dec::fix($loan['rate'], 2)) . ' % a year, ' . h(mb_strtolower(Loans::INTEREST_METHODS[$loan['interest_method']] ?? '')),
      'Term' => h(Dec::fix($loan['term'], 0) . ' ' . $loan['term_unit']) . ', ' . h(mb_strtolower(Coop::modeLabel($loan['mode_of_payment']))), 'Amortization' => h(Loans::AMORT_TYPES[$loan['amort_type']] ?? ''),
      'Released' => $loan['release_mode'] === 'opening' ? (($cut = Loans::importedThrough($loan)) !== $loan['release_date'] ? h(fdate($loan['release_date'])) . '; brought over from the previous system with its balances as of ' . h(fdate($cut))
          : 'Opening balance as of ' . h(fdate($loan['release_date'])) . ' (brought over from the previous system)') : h(fdate($loan['release_date'])) . ', ' . h($loan['release_mode'] === 'savings' ? 'credited to savings' : $loan['release_mode']) . ($loan['cheque_no'] ? ' ' . h($loan['cheque_no']) : ''),
      'First amortization' => h(fdate($loan['start_date'])), 'Maturity' => h(fdate($loan['due_date'])), 'Scheduled interest' => cm($loan['total_interest']),
      'Charges' => cm($loan['total_charges']), 'Net proceeds' => cm($loan['net_proceeds']), 'Previous loan paid off' => Dec::isPos($loan['previous_loan_payoff']) ? cm($loan['previous_loan_payoff']) : '',
      'Interest balance' => cm($loan['interest_balance']), 'Interest accrued (in income)' => Dec::isPos($loan['interest_accrued']) ? cm($loan['interest_accrued']) : '',
      'Penalty balance' => cm($loan['penalty_balance']), 'Waived' => Dec::isPos(Dec::add($loan['interest_waived'], $loan['penalty_waived'])) ? 'interest ' . cm($loan['interest_waived']) . ', penalty ' . cm($loan['penalty_waived']) : '',
      'Penalty terms' => Dec::isPos($loan['penalty_rate']) ? h(Dec::fix($loan['penalty_rate'], 2)) . ' % a year on ' . h(mb_strtolower(Loans::PENALTY_BASES[$loan['penalty_base']] ?? '')) . ', ' . (int)$loan['penalty_grace_days'] . ' grace days' : 'none',
      'Last payment' => h(fdate((string)$loan['last_payment_date'])), 'Penalty computed through' => h(fdate((string)$loan['last_penalty_date'])),
      'Officer' => h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $loan['officer_id']])), 'Branch' => h(Coop::branchName($loan['branch_id'] ? (int)$loan['branch_id'] : null)),
      'Closed' => h(fdate((string)$loan['closed_on'])),
  ]) ?></div></div></div>
  <div class="col-lg-5">
    <?php if ($open): ?>
    <div class="card mb-3"><div class="card-header">Dues as of</div><div class="card-body">
      <form class="d-flex gap-2 mb-2 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="id" value="<?= (int)$loan['id'] ?>"><input class="form-control form-control-sm" type="date" name="asof" value="<?= h($asOf) ?>"><button class="btn btn-sm btn-outline-primary">Compute</button></form>
      <table class="table table-sm mb-0"><tbody>
        <tr><td>Penalty</td><td class="num"><?= cm($d['penalty_due']) ?></td></tr>
        <tr><td>Interest due</td><td class="num"><?= cm($d['interest_due']) ?></td></tr>
        <tr><td>Principal due</td><td class="num"><?= cm($d['principal_due']) ?></td></tr>
        <tr class="fw-semibold"><td>Total due</td><td class="num"><?= cm($d['total_due']) ?></td></tr>
        <tr><td class="small text-muted">Interest from <?= h(fdate($d['last_due'])) ?> (<?= (int)$d['days_since'] ?> days)</td><td class="num small text-muted"><?= cm($d['interest_to_date']) ?></td></tr>
        <tr class="fw-semibold"><td>Payoff amount</td><td class="num"><?= cm($d['payoff']) ?></td></tr>
      </tbody></table>
    </div></div>
    <?php endif; ?>
    <?php if ($charges): ?><div class="card mb-3"><div class="card-header">Charges on release</div><table class="table table-sm"><tbody>
      <?php foreach ($charges as $c): ?><tr><td><?= h($c['name']) ?><div class="small text-muted"><?= h((string)$c['basis_text']) ?>, <?= h(Loans::CHARGE_METHODS[$c['how']] ?? $c['how']) ?></div></td><td class="num"><?= cm($c['amount']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <form method="post" class="card"><div class="card-header">Note</div><div class="card-body">
      <?= csrf_field() ?><input type="hidden" name="action" value="note">
      <textarea class="form-control form-control-sm" name="notes" rows="2" maxlength="255"<?= Access::can($pageKey, 'edit') ? '' : ' readonly' ?>><?= h((string)$loan['notes']) ?></textarea>
      <?php if (Access::can($pageKey, 'edit')): ?><div class="d-flex justify-content-between mt-2"><button class="btn btn-sm btn-outline-primary">Save note</button>
        <button class="btn btn-sm btn-link" name="action" value="refresh" data-confirm="Recompute the balances and the status of this loan from its ledger?">Recompute balances</button></div><?php endif; ?>
    </div></form>
  </div>
</div>
<?php elseif ($tab === 'schedule'):
    $rows = Loans::scheduleRows((int)$loan['id']);
    $tot = ['p' => '0', 'i' => '0', 't' => '0', 'pp' => '0', 'ip' => '0']; ?>
<div class="card"><div class="table-responsive"><table class="table table-sm sched-table"><thead><tr><th>#</th><th>Due</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amortization</th><th class="num">Balance after</th><th class="num">Principal paid</th><th class="num">Interest paid</th><th>Paid</th></tr></thead><tbody>
  <?php foreach ($rows as $r): foreach (['p' => 'principal', 'i' => 'interest', 't' => 'total', 'pp' => 'principal_paid', 'ip' => 'interest_paid'] as $k => $f) { $tot[$k] = Dec::add($tot[$k], $r[$f], 2); }
      $late = !(int)$r['is_paid'] && $r['due_date'] < $asOf; ?>
    <tr class="<?= $late ? 'table-danger' : '' ?>"><td><?= (int)$r['inst_no'] ?></td><td><?= h(fdate($r['due_date'])) ?></td><td class="num"><?= cm($r['principal']) ?></td><td class="num"><?= cm($r['interest']) ?></td>
      <td class="num"><?= cm($r['total']) ?></td><td class="num"><?= cm($r['balance_after']) ?></td><td class="num"><?= cm($r['principal_paid'], true) ?></td><td class="num"><?= cm($r['interest_paid'], true) ?></td>
      <td class="small"><?= (int)$r['is_paid'] ? '<span class="text-success">' . h(fdate((string)$r['paid_on'])) . '</span>' : ($late ? '<span class="text-danger">overdue</span>' : '') ?></td></tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr class="fw-semibold"><td colspan="2">Total</td><td class="num"><?= cm($tot['p']) ?></td><td class="num"><?= cm($tot['i']) ?></td><td class="num"><?= cm($tot['t']) ?></td><td></td><td class="num"><?= cm($tot['pp']) ?></td><td class="num"><?= cm($tot['ip']) ?></td><td></td></tr></tfoot></table></div></div>
<?php elseif ($tab === 'ledger'):
    $rows = DB::all('SELECT x.*, t.txn_no, t.txn_type, t.is_voided AS txn_voided FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id WHERE x.loan_id = :l ORDER BY x.txn_date, x.id', ['l' => $loan['id']]); ?>
<div class="card"><div class="table-responsive"><table class="table table-sm"><thead>
  <tr><th rowspan="2">Date</th><th rowspan="2">Entry</th><th rowspan="2">Voucher</th><th colspan="2" class="text-center">Principal</th><th colspan="2" class="text-center">Interest</th><th colspan="2" class="text-center">Penalty</th><th colspan="3" class="text-center">Balances</th></tr>
  <tr><th class="num">Charged</th><th class="num">Paid</th><th class="num">Charged</th><th class="num">Paid</th><th class="num">Charged</th><th class="num">Paid</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $memoOnly = $r['kind'] === 'accrual'; ?>
    <tr class="<?= (int)$r['is_voided'] ? 'text-muted text-decoration-line-through' : ($memoOnly ? 'text-muted' : '') ?>"><td class="small"><?= h(fdate($r['txn_date'])) ?></td>
      <td><?= h(Loans::ledgerKind($r)) ?><div class="small text-muted"><?= h((string)$r['memo']) ?><?= $memoOnly ? ' (income recognized; the member balance does not change)' : '' ?></div></td>
      <td class="small"><?= $r['txn_id'] ? '<a href="' . h($r['kind'] === 'release' ? url('loan.release', ['loan' => $loan['id']]) : Txn::url(['id' => $r['txn_id'], 'txn_type' => $r['txn_type']])) . '">' . h((string)($r['or_no'] ?: $r['txn_no'])) . '</a>' : '' ?></td>
      <?php if ($memoOnly): ?><td colspan="2"></td><td class="num" colspan="2"><?= cm($r['interest_dr']) ?></td><td colspan="2"></td>
      <?php else: ?><td class="num"><?= cm($r['principal_dr'], true) ?></td><td class="num"><?= cm($r['principal_cr'], true) ?></td><td class="num"><?= cm($r['interest_dr'], true) ?></td><td class="num"><?= cm($r['interest_cr'], true) ?></td>
      <td class="num"><?= cm($r['penalty_dr'], true) ?></td><td class="num"><?= cm($r['penalty_cr'], true) ?></td><?php endif; ?>
      <td class="num"><?= cm($r['principal_bal']) ?></td><td class="num"><?= cm($r['interest_bal']) ?></td><td class="num"><?= cm($r['penalty_bal']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="12" class="text-muted">No entry.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php elseif ($tab === 'penalty'):
    $pen = $open ? Loans::penaltyThrough($loan, $asOf) : null;
    $posted = DB::all("SELECT * FROM loan_txns WHERE loan_id = :l AND kind = 'penalty' ORDER BY txn_date, id", ['l' => $loan['id']]); ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header">Penalty still to post as of <?= h(fdate($asOf)) ?></div>
    <?php if ($pen && $pen['detail']): ?><table class="table table-sm"><thead><tr><th>Amortization</th><th>Due</th><th>Counted from</th><th class="num">Base</th><th class="num">Days</th><th class="num">Penalty</th></tr></thead><tbody>
      <?php foreach ($pen['detail'] as $x): ?><tr><td><?= $x['inst_no'] ? '#' . (int)$x['inst_no'] : 'Balance' ?></td><td><?= h(fdate($x['due_date'])) ?></td><td><?= h(fdate($x['from'])) ?></td><td class="num"><?= cm($x['base']) ?></td><td class="num"><?= (int)$x['days'] ?></td><td class="num"><?= cm($x['penalty']) ?></td></tr><?php endforeach; ?>
      </tbody><tfoot><tr class="fw-semibold"><td colspan="5">Not yet posted</td><td class="num"><?= cm($pen['new']) ?></td></tr></tfoot></table>
    <?php else: ?><div class="card-body text-muted small">Nothing to add<?= $open ? '' : ' (the loan is closed)' ?>.</div><?php endif; ?>
    <div class="card-body small text-muted pt-0">Penalty = base &times; <?= h(Dec::fix($loan['penalty_rate'], 2)) ?> % &divide; 100 &times; days &divide; <?= (int)$loan['calendar_days'] ?>, counted from the end of the <?= (int)$loan['penalty_grace_days'] ?>-day grace period or from the last computation (<?= h(fdate((string)$loan['last_penalty_date']) ?: 'none') ?>), on <?= h(mb_strtolower(Loans::PENALTY_BASES[$loan['penalty_base']] ?? '')) ?>.</div>
  </div></div>
  <div class="col-lg-5"><div class="card"><div class="card-header">Penalties posted</div><table class="table table-sm"><tbody>
    <?php foreach ($posted as $x): ?><tr class="<?= (int)$x['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(fdate($x['txn_date'])) ?></td><td class="small"><?= h((string)$x['memo']) ?></td><td class="num"><?= cm($x['penalty_dr']) ?></td></tr><?php endforeach; ?>
    <?php if (!$posted): ?><tr><td class="text-muted">None.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php elseif ($tab === 'security'):
    $com = $app ? DB::all('SELECT c.*, m.member_no, m.full_name AS member_name FROM loan_comakers c LEFT JOIN members m ON m.id = c.comaker_member_id WHERE c.application_id = :a', ['a' => $app['id']]) : [];
    $coll = $app ? DB::all('SELECT * FROM loan_collaterals WHERE application_id = :a', ['a' => $app['id']]) : [];
    $holds = DB::all('SELECT h.*, a.account_no FROM sav_holds h JOIN sav_accounts a ON a.id = h.account_id WHERE h.loan_account_id = :l ORDER BY h.id', ['l' => $loan['id']]); ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card mb-3"><div class="card-header">Co-makers</div><table class="table table-sm"><tbody>
    <?php foreach ($com as $c): ?><tr><td><?= $c['comaker_member_id'] ? safe_link('coop.member', ['id' => $c['comaker_member_id']], (string)$c['member_name']) . ' <span class="small text-muted">' . h((string)$c['member_no']) . '</span>' : h((string)$c['name']) . ' <span class="small text-muted">non-member</span>' ?>
      <div class="small text-muted"><?= h((string)$c['address']) ?></div></td><td class="num"><?= cm($c['amount_guaranteed']) ?></td></tr><?php endforeach; ?>
    <?php if (!$com): ?><tr><td class="text-muted">None.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card"><div class="card-header">Savings held for this loan</div><table class="table table-sm"><tbody>
    <?php foreach ($holds as $hd): ?><tr><td><?= safe_link('sav.accounts', ['id' => $hd['account_id']], $hd['account_no']) ?><div class="small text-muted"><?= h((string)$hd['reason']) ?></div></td><td class="num"><?= cm($hd['amount']) ?></td><td class="small"><?= $hd['released_at'] ? 'released ' . h(fdate(substr($hd['released_at'], 0, 10))) : 'held' ?></td></tr><?php endforeach; ?>
    <?php if (!$holds): ?><tr><td class="text-muted">None.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header">Collateral</div><table class="table table-sm"><tbody>
    <?php foreach ($coll as $c): $exp = $c['expiry_date'] && $c['expiry_date'] < today(); $iexp = $c['insurance_expiry'] && $c['insurance_expiry'] < today(); ?>
      <tr><td><strong><?= h($c['collateral_type']) ?></strong> <?= h((string)$c['description']) ?><div class="small text-muted"><?= h((string)$c['ref_no']) ?>; market value <?= cm($c['fmv']) ?>, loan value <?= cm($c['loan_value']) ?></div>
        <div class="small <?= $exp || $iexp ? 'text-danger' : 'text-muted' ?>"><?= $c['expiry_date'] ? 'Document expires ' . h(fdate($c['expiry_date'])) : '' ?><?= $c['insurance_expiry'] ? ($c['expiry_date'] ? ', insurance ' : 'Insurance expires ') . h(fdate($c['insurance_expiry'])) : '' ?></div></td>
        <td class="small text-nowrap"><?= $c['status'] === 'released' ? 'Released ' . h(fdate((string)$c['released_on'])) : 'Held' ?>
        <?php if ($c['status'] !== 'released' && Access::can($pageKey, 'edit')): ?><form method="post" class="mt-1"><?= csrf_field() ?><input type="hidden" name="action" value="collateral"><input type="hidden" name="collateral_id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary" data-confirm="Mark this collateral returned to the member?"<?= $open && !Access::can($pageKey, 'approve') ? ' disabled title="The loan is still open"' : '' ?>>Release</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$coll): ?><tr><td class="text-muted">None.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php else:
    $notices = DB::all('SELECT n.*, u.full_name FROM loan_notices n LEFT JOIN users u ON u.id = n.created_by WHERE n.loan_id = :l ORDER BY n.notice_date DESC, n.id DESC', ['l' => $loan['id']]);
    $prints = DB::all("SELECT d.*, u.full_name FROM document_prints d LEFT JOIN users u ON u.id = d.printed_by WHERE d.ref_type = 'loan' AND d.ref_id = :l ORDER BY d.id DESC", ['l' => $loan['id']]); ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card"><div class="card-header">Collection notices</div><table class="table table-sm"><tbody>
    <?php foreach ($notices as $n): ?><tr><td><?= h(fdate($n['notice_date'])) ?></td><td><?= h(ucwords(str_replace('_', ' ', $n['notice_type']))) ?><div class="small text-muted"><?= h((string)$n['notes']) ?></div></td><td class="num"><?= cm($n['amount_due']) ?></td><td class="small"><?= h((string)$n['full_name']) ?></td></tr><?php endforeach; ?>
    <?php if (!$notices): ?><tr><td class="text-muted">No notice sent. Reminder and demand letters are printed from Documents.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header">Documents printed</div><table class="table table-sm"><tbody>
    <?php foreach ($prints as $x): ?><tr><td class="small"><?= h(fdatetime($x['printed_at'])) ?></td><td><?= h(CoopDocs::KEYS[$x['doc_key']] ?? ucwords(str_replace(['loan.', '_'], ['', ' '], $x['doc_key']))) ?><div class="small text-muted"><?= (int)$x['copy_no'] > 1 ? 'Copy ' . ((int)$x['copy_no'] - 1) . ': ' . h((string)$x['reason']) : 'Original' ?></div></td><td class="small"><?= h((string)$x['full_name']) ?></td></tr><?php endforeach; ?>
    <?php if (!$prints): ?><tr><td class="text-muted">Nothing printed yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php endif;
layout_end();
