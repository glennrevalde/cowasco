<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.passbook';
$ledger = g('ledger') === 'loan' || p('ledger') === 'loan' ? 'loan' : 'savings';
$no = trim((string)(g('account') ?: p('account')));
$pb = $no !== '' ? Passbook::open($ledger, $no) : null;
if ($pb && !Coop::branchAllowed($pb['account']['branch_id'] ? (int)$pb['account']['branch_id'] : null)) {
    $pb = null;
    flash('warning', 'That account belongs to another branch.');
}

handle_post(function () use ($pageKey, $pb, $ledger, $no) {
    if (!$pb || !$pb['type']) {
        fail('Choose an account with a passbook type.');
    }
    $action = (string)p('action');
    if ($action === 'commit') {
        Access::require($pageKey, 'print');
        // the pages of the run that printed right (every page, or the first ones)
        [$n, $pages] = Passbook::commitRun($pb, Passbook::postedRun(), (int)p('upto'), $pageKey);
        flash('success', $n . ' line(s) marked as printed on ' . coop_pb_pages_words($pages) . '.');
    } elseif ($action === 'replace') {
        Access::require($pageKey, 'edit');
        Passbook::replace($pb, (string)p('passbook_no'), trim((string)p('reason')), $pageKey);
        flash('success', 'New passbook: printing starts again on its first page.');
    } elseif ($action === 'reprint') {
        Access::require($pageKey, 'print');
        $n = Passbook::unmark($pb, (int)pint('from_row'), trim((string)p('reason')), $pageKey);
        flash('success', $n . ' line(s) will be printed again.');
    } elseif ($action === 'reset') {
        Access::require($pageKey, 'edit');
        $n = Passbook::resetLine($pb, max(1, (int)p('pb_page')), max(1, (int)p('pb_line')), pint('from_row') ?: null, trim((string)p('reason')), $pageKey);
        flash('success', 'The next line prints on page ' . max(1, (int)p('pb_page')) . ', line ' . max(1, (int)p('pb_line')) . '.' . ($n ? ' ' . $n . ' line(s) will be printed again.' : ''));
    }
    redirect(url($pageKey, ['account' => $no, 'ledger' => $ledger]));
});

// the footer of the current page (a passbook with a footer line)
if ($pb && $pb['type'] && g('a') === 'footer') {
    Access::require($pageKey, 'print');
    DB::insert('passbook_prints', ['account_id' => $pb['account']['id'], 'ledger' => $pb['ledger'], 'print_type' => 'footer', 'line_count' => 0, 'page_no' => (int)$pb['account']['pb_page'], 'printed_by' => Auth::id()]);
    Audit::log('passbook_print', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$pb['account']['id'], $pb['account']['account_no'], ['page_key' => $pageKey, 'reason' => 'Footer']);
    coop_sheet_start($pb['account']['account_no'], 'Put page ' . max(1, (int)$pb['account']['pb_page']) . ' of the passbook of ' . h($pb['account']['account_no']) . ' in the passbook printer, then print the footer.');
    $bal = $pb['ledger'] === 'loan' ? (string)$pb['account']['principal_balance'] : (string)$pb['account']['balance'];
    echo Passbook::renderFooter($pb, $bal);
    coop_sheet_end(true, ['close' => true, 'back' => url($pageKey, ['account' => $pb['account']['account_no'], 'ledger' => $pb['ledger']])]);
    exit;
}

// the header of the current page alone (Loan Type, Term and Due Date of a loan passbook; the account name and number of
// a savings passbook), for a page whose header was not printed with its first line
if ($pb && $pb['type'] && g('a') === 'header') {
    Access::require($pageKey, 'print');
    $hpage = max(1, (int)(gint('page') ?: Passbook::next($pb)[0]));
    DB::insert('passbook_prints', ['account_id' => $pb['account']['id'], 'ledger' => $pb['ledger'], 'print_type' => 'header', 'line_count' => 0, 'page_no' => $hpage, 'printed_by' => Auth::id()]);
    Audit::log('passbook_print', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$pb['account']['id'], $pb['account']['account_no'], ['page_key' => $pageKey, 'reason' => 'Header of page ' . $hpage]);
    coop_sheet_start($pb['account']['account_no'], 'Put page ' . $hpage . ' of the ' . ($pb['ledger'] === 'loan' ? 'loan ' : '') . 'passbook of ' . h($pb['account']['account_no']) . ' in the passbook printer, then print its header.');
    echo Passbook::renderHeader($pb, $hpage);
    coop_sheet_end(true, ['close' => true, 'back' => url($pageKey, ['account' => $pb['account']['account_no'], 'ledger' => $pb['ledger']])]);
    exit;
}

// a reprint of the lines of a date range, each on the page and line it was printed on (Reprint passbook)
if ($pb && $pb['type'] && g('a') === 'reprint') {
    Access::require($pageKey, 'print');
    $from = gdate('from', date('Y-m-01'));
    $to = gdate('to', today());
    $pages = Passbook::printedBetween($pb, $from, $to);
    $count = array_sum(array_map('count', $pages));
    DB::insert('passbook_prints', ['account_id' => $pb['account']['id'], 'ledger' => $pb['ledger'], 'print_type' => 'reprint', 'line_count' => $count, 'page_no' => $pages ? (int)array_key_first($pages) : null,
        'reason' => mb_substr('Reprint of ' . fdate($from) . ' to ' . fdate($to), 0, 255), 'printed_by' => Auth::id()]);
    Audit::log('passbook_reprint', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$pb['account']['id'], $pb['account']['account_no'], ['page_key' => $pageKey, 'reason' => 'Reprint of ' . fdate($from) . ' to ' . fdate($to) . ' (' . $count . ' line(s))']);
    $hint = $pages
        ? 'Reprint of ' . h($pb['account']['account_no']) . ' from ' . h(fdate($from)) . ' to ' . h(fdate($to)) . ': ' . $count . ' line(s) on page(s) ' . h(implode(', ', array_keys($pages))) . '. Put each page in the passbook printer in turn; every page prints on its own sheet.'
        : 'No printed transaction of ' . h($pb['account']['account_no']) . ' from ' . h(fdate($from)) . ' to ' . h(fdate($to)) . '. Transactions not printed yet are printed with Update Passbook.';
    coop_sheet_start($pb['account']['account_no'], $hint);
    foreach ($pages as $page => $lines) {
        // a page reprinted from its first line gets its header again
        echo Passbook::render($pb, (int)$page, $lines, ($pb['type']['layout']['opt']['header'] ?? 'new_page') !== 'never' && (int)$lines[0]['line'] === (Passbook::pageLines($pb['type'])[0] ?? 1));
    }
    coop_sheet_end((bool)$pages, ['close' => true, 'back' => url($pageKey, ['account' => $pb['account']['account_no'], 'ledger' => $pb['ledger']])]);
    exit;
}

// the pages to put in the printer (opened in its own window): every line not yet printed, page by page. Between two
// pages the window asks the teller to turn the passbook to the next page and prints on; ?pg= prints one page of the run
// again. Once printed, the window closes and the teller confirms on the page it came from.
if ($pb && $pb['type'] && g('a') === 'print') {
    Access::require($pageKey, 'print');
    $what = ($pb['ledger'] === 'loan' ? 'loan ' : '') . 'passbook of ' . h($pb['account']['account_no']);
    $back = url($pageKey, ['account' => $pb['account']['account_no'], 'ledger' => $pb['ledger']]);
    if (g('cover') === '1') {
        DB::insert('passbook_prints', ['account_id' => $pb['account']['id'], 'ledger' => $pb['ledger'], 'print_type' => 'cover', 'line_count' => 0, 'page_no' => 0, 'printed_by' => Auth::id()]);
        Audit::log('passbook_print', $pb['ledger'] === 'loan' ? 'loan' : 'sav', $pb['ledger'] . '_account', (int)$pb['account']['id'], $pb['account']['account_no'], ['page_key' => $pageKey, 'reason' => 'Cover']);
        coop_sheet_start($pb['account']['account_no'], 'Put the inside cover of the ' . $what . ' in the passbook printer, then print.');
        echo Passbook::renderCover($pb);
        coop_sheet_end(true, ['close' => true, 'back' => $back]);
        exit;
    }
    $run = Passbook::run($pb);
    $only = gint('pg');
    $pages = $only ? array_values(array_filter($run['pages'], fn($p) => (int)$p['page'] === (int)$only)) : $run['pages'];
    if (!$pages) {
        coop_sheet_start($pb['account']['account_no'], 'Nothing to print: every ' . ($pb['ledger'] === 'loan' ? 'entry' : 'transaction') . ' of ' . h($pb['account']['account_no']) . ' is printed in the passbook.');
        coop_sheet_end(false);
        exit;
    }
    $first = $pages[0];
    $withHeader = $first['header'] || g('header') === '1';
    $range = fn(array $p) => $p['first'] === $p['last'] ? 'line ' . $p['first'] : 'lines ' . $p['first'] . ' to ' . $p['last'];
    $hint = 'Put the ' . $what . ' in the passbook printer, open at <strong>page ' . (int)$first['page'] . '</strong>, then print. '
        . ($withHeader ? 'The header prints first, then line ' : 'Printing starts on line ') . (int)$first['first'] . '.';
    if (count($pages) > 1) {
        $hint .= ' <strong>' . (int)$run['count'] . ' lines on ' . count($pages) . ' pages:</strong> ' . h(Passbook::runWords(['pages' => $pages])) . '. When page ' . (int)$first['page']
            . ' is printed, you are asked to turn the passbook to page ' . (int)$pages[1]['page'] . ' and the printing goes on there.';
    } elseif ($only) {
        $hint .= ' Page ' . (int)$only . ' of the run is printed again.';
    }
    coop_sheet_start($pb['account']['account_no'], $hint, ['js/passbook_run.js']);
    echo '<div class="pb-run" data-pb-run data-account="' . h($pb['account']['account_no']) . '" data-ledger="' . h($pb['ledger']) . '" data-back="' . h($back) . '">';
    foreach ($pages as $i => $p) {
        echo '<section class="pb-run-page" data-page="' . (int)$p['page'] . '" data-range="' . h($range($p)) . '" data-count="' . count($p['lines']) . '" data-header="' . ($p['header'] ? 1 : 0) . '" data-first="' . (int)$p['first'] . '">';
        if (count($pages) > 1) {
            echo '<div class="pb-run-cap no-print"><span class="pb-run-num">' . ($i + 1) . '</span><strong>Page ' . (int)$p['page'] . '</strong><span>' . h($range($p)) . ', ' . count($p['lines']) . ' line' . (count($p['lines']) === 1 ? '' : 's')
                . ($p['header'] ? ', header first' : '') . '</span><span class="pb-run-state" data-state></span></div>';
        }
        echo Passbook::render($pb, (int)$p['page'], $p['lines'], $i === 0 ? $withHeader : $p['header'], $p['forward']);
        echo '</section>';
    }
    echo '</div>';
    // between two pages: turn the passbook
    echo '<div class="pb-turn no-print" data-pb-turn hidden><div class="pb-turn-card" role="dialog" aria-modal="true" aria-labelledby="pbTurnT" aria-describedby="pbTurnX">'
        . '<div class="pb-turn-icon" aria-hidden="true"><i class="bi bi-book"></i><i class="bi bi-arrow-return-right"></i></div>'
        . '<h1 class="pb-turn-title" id="pbTurnT" data-turn-title></h1><p class="pb-turn-text" id="pbTurnX" data-turn-text></p><div class="pb-turn-step" data-turn-step></div>'
        . '<div class="pb-turn-acts"><button type="button" class="btn btn-primary" data-turn-next><i class="bi bi-printer"></i> <span></span></button>'
        . '<button type="button" class="btn btn-outline-secondary" data-turn-again><i class="bi bi-arrow-counterclockwise"></i> <span></span></button></div>'
        . '<div class="pb-turn-foot small text-muted">The lines are marked printed only when you confirm on the page you came from.</div></div></div>';
    coop_sheet_end(false, ['back' => $back]);
    exit;
}

coop_layout('Passbook Printing', ['actions' => Access::page('coop.passbooks') ? btn_link(url('coop.passbooks', $pb && $pb['type'] ? ['id' => $pb['type']['id']] : []), 'Passbook Types', 'btn-outline-secondary', 'sliders') : '', 'js' => ['js/savings.js']]);
// the columns of the list of lines waiting to print, by the kind of passbook
$listCols = $ledger === 'loan'
    ? ['ref' => ['Reference No.', false], 'release' => ['Release', true], 'prin' => ['Principal', true], 'bal' => ['Balance', true], 'int' => ['Interest', true], 'fines' => ['Fines', true], 'teller' => ['Teller', false]]
    : ['code' => ['Code', false], 'credit' => ['Deposit', true], 'debit' => ['Withdrawal', true], 'balance' => ['Balance', true], 'teller' => ['Teller', false]];
$amt = fn($v) => Dec::isZero($v ?? '0') ? '' : (Dec::isNeg($v) ? '<span class="text-danger">-' . cm(Dec::abs($v)) . '</span>' : cm($v));
?>
<form class="pbp-open mb-3 no-print">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="pbp-kind" role="radiogroup" aria-label="Passbook of">
    <?php foreach (['savings' => ['Savings / share capital', 'piggy-bank'], 'loan' => ['Loan', 'cash-coin']] as $k => [$label, $icon]): ?>
      <input class="btn-check" type="radio" name="ledger" id="pbpK<?= h($k) ?>" value="<?= h($k) ?>" <?= $ledger === $k ? 'checked' : '' ?>><label class="btn btn-sm btn-outline-secondary" for="pbpK<?= h($k) ?>"><i class="bi bi-<?= h($icon) ?>"></i> <?= h($label) ?></label>
    <?php endforeach; ?>
  </div>
  <div class="pbp-acct"><label class="form-label" for="pbpNo"><?= $ledger === 'loan' ? 'Loan no.' : 'Account no.' ?></label><input class="form-control form-control-sm" id="pbpNo" name="account" value="<?= h($no) ?>" autofocus autocomplete="off" placeholder="<?= $ledger === 'loan' ? '01-6-0000002' : '01100001011' ?>"></div>
  <div class="pbp-go"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Open</button></div>
</form>
<script>
(function () {
  // the box asks for a loan no. or an account no. by the kind chosen
  const f = document.querySelector('.pbp-open'); if (!f) return;
  const lab = f.querySelector('label[for="pbpNo"]'), inp = f.querySelector('#pbpNo');
  f.addEventListener('change', (e) => { if (e.target.name !== 'ledger') return; const loan = e.target.value === 'loan';
    lab.textContent = loan ? 'Loan no.' : 'Account no.'; inp.placeholder = loan ? '01-6-0000002' : '01100001011'; inp.focus(); });
})();
</script>
<?php if ($no !== '' && !$pb): ?><div class="alert alert-warning">No such <?= $ledger === 'loan' ? 'loan' : 'account' ?>.</div><?php endif; ?>
<?php if ($pb && !$pb['type']): ?><div class="alert alert-warning">The product of <?= h($no) ?> has no passbook type. Set one on the <?= $ledger === 'loan' ? 'loan product' : 'product or on the account' ?>.</div><?php endif; ?>
<?php if ($pb && $pb['type'] && $pb['type']['ledger'] !== $ledger): ?><div class="alert alert-warning">The passbook type <?= h($pb['type']['name']) ?> is a <?= h(mb_strtolower(Passbook::LEDGERS[$pb['type']['ledger']])) ?>; the product of <?= h($no) ?> should use a <?= h(mb_strtolower(Passbook::LEDGERS[$ledger])) ?> type (Passbook Types).</div><?php endif; ?>
<?php if ($pb && $pb['type']):
    $a = $pb['account'];
    $t = $pb['type'];
    $run = Passbook::run($pb);
    [$np, $nl] = Passbook::next($pb);
    $skip = array_keys(Passbook::skipped($t));
    $printed = $ledger === 'loan'
        ? DB::all("SELECT t.id, t.txn_date, t.kind, t.or_no, t.pb_page, t.pb_line FROM loan_txns t WHERE t.loan_id = :a AND t.pb_printed = 1 AND t.is_voided = 0 ORDER BY t.pb_page DESC, t.pb_line DESC LIMIT 15", ['a' => $a['id']])
        : DB::all('SELECT id, txn_date, txn_code AS code, pb_page, pb_line FROM sav_txns WHERE account_id = :a AND pb_printed = 1 AND is_voided = 0 ORDER BY pb_page DESC, pb_line DESC LIMIT 15', ['a' => $a['id']]);
    $acctUrl = fn(array $q) => url($pageKey, $q + ['account' => $no, 'ledger' => $ledger]);
?>
<div class="pbp-head">
  <div class="pbp-who"><div class="pbp-no"><?= h($a['account_no']) ?></div><div><strong><?= h($a['member_name']) ?></strong><div class="small text-muted"><?= h($a['product_name']) ?><?= $ledger === 'savings' && $a['passbook_no'] ? ', passbook no. ' . h((string)$a['passbook_no']) : '' ?></div></div></div>
  <div class="pbp-figs">
    <div><span>Passbook type</span><b><?= h($t['name']) ?></b></div>
    <div><span>Next line</span><b>Page <?= (int)$np ?>, line <?= (int)$nl ?></b></div>
    <div><span>Waiting to print</span><b><?= (int)$run['count'] ?><?= count($run['pages']) > 1 ? ' on ' . count($run['pages']) . ' pages' : '' ?></b></div>
    <div><span>Lines of a page</span><b>1 to <?= (int)$t['max_lines'] ?><?= $skip ? ', ' . (count($skip) > 1 ? min($skip) . '-' . max($skip) : (string)$skip[0]) . ' skipped' : '' ?></b></div>
  </div>
</div>
<div class="row g-3">
  <div class="col-xl-8">
    <?php if ($run['count']): ?>
    <div class="card mb-3"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span>To print: <?= (int)$run['count'] ?> line<?= $run['count'] === 1 ? '' : 's' ?> on <?= h(coop_pb_pages_words(array_column($run['pages'], 'page'))) ?></span>
        <?php if (count($run['pages']) > 1): ?><span class="small text-muted fw-normal ms-auto"><i class="bi bi-journals"></i> One run: you are asked to turn the passbook between the pages</span><?php endif; ?></div>
      <?php foreach ($run['pages'] as $pi => $pg): ?>
      <div class="pbp-page-head"><strong>Page <?= (int)$pg['page'] ?></strong><span class="text-muted"><?= count($pg['lines']) ?> line<?= count($pg['lines']) === 1 ? '' : 's' ?><?= $pg['header'] ? ', the header prints first (a new page)' : '' ?><?= $pg['forward'] !== null ? ', continued on page ' . ((int)$pg['page'] + 1) : '' ?></span></div>
      <div class="table-responsive"><table class="table table-sm pbp-lines mb-0"><thead><tr><th>Line</th><th>Date</th><?php foreach ($listCols as [$label, $num]): ?><th class="<?= $num ? 'num' : '' ?>"><?= h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($pg['lines'] as $l): ?><tr><td><?= (int)$l['line'] ?></td><td><?= h(fdate($l['date'])) ?></td>
        <?php foreach ($listCols as $k => [$label, $num]): ?><td class="<?= $num ? 'num' : '' ?>"><?= $num ? ($k === 'bal' || $k === 'balance' ? cm($l[$k]) : $amt($l[$k])) : h((string)$l[$k]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endforeach; ?>
      <div class="card-body pbp-run">
        <?= coop_pb_run_box($pb, $run, 'pbpConfirm', '<input type="hidden" name="action" value="commit"><input type="hidden" name="account" value="' . h($no) . '"><input type="hidden" name="ledger" value="' . h($ledger) . '">',
            '<strong>' . h($a['account_no']) . '</strong> (' . h($a['member_name']) . ')', Access::can($pageKey, 'print')) ?>
      </div></div>
    <div class="small text-muted mb-1">Preview: <?= count($run['pages']) > 1 ? 'the pages' : 'the page' ?> as printed on the passbook (positions of the passbook type)</div>
    <div class="pbp-preview">
      <?php foreach ($run['pages'] as $pg): ?>
        <?php if (count($run['pages']) > 1): ?><div class="pbp-preview-cap">Page <?= (int)$pg['page'] ?></div><?php endif; ?>
        <?= Passbook::render($pb, (int)$pg['page'], $pg['lines'], $pg['header'], $pg['forward']) ?>
      <?php endforeach; ?>
    </div>
    <?php else: ?><div class="pbp-done"><i class="bi bi-check2-circle"></i> Everything is printed. The next entry prints on page <?= (int)$np ?>, line <?= (int)$nl ?>.</div><?php endif; ?>
  </div>
  <div class="col-xl-4">
    <?php if (Access::can($pageKey, 'print')): ?>
    <div class="card mb-3"><div class="card-header">Cover, header and footer</div><div class="card-body small">
      <p class="mb-2 text-muted">Each prints alone at its place on the passbook type; the print line does not move.</p>
      <div class="d-grid gap-2">
        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank" rel="noopener" href="<?= h($acctUrl(['a' => 'print', 'cover' => 1])) ?>"><i class="bi bi-person-badge"></i> Print the cover</a>
        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank" rel="noopener" href="<?= h($acctUrl(['a' => 'header', 'page' => $np])) ?>"><i class="bi bi-layout-text-sidebar"></i> Print the header of page <?= (int)$np ?></a>
        <a class="btn btn-outline-secondary btn-sm text-start" target="_blank" rel="noopener" href="<?= h($acctUrl(['a' => 'footer'])) ?>"><i class="bi bi-layout-text-window-reverse"></i> Print the footer</a>
      </div>
    </div></div>
    <?php endif; ?>
    <?php if (Access::can($pageKey, 'print') && $printed): ?>
    <form method="post" class="card mb-3" data-once><div class="card-header">Print lines again</div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="reprint"><input type="hidden" name="account" value="<?= h($no) ?>"><input type="hidden" name="ledger" value="<?= h($ledger) ?>">
      <div class="col-12"><label class="form-label">From the line</label><select class="form-select form-select-sm" name="from_row" data-no-combo><?php foreach ($printed as $r): ?><option value="<?= (int)$r['id'] ?>">Page <?= (int)$r['pb_page'] ?> line <?= (int)$r['pb_line'] ?>: <?= h(fdate($r['txn_date'])) ?> <?= h($ledger === 'loan' ? Loans::ledgerKind($r) . ($r['or_no'] ? ' ' . $r['or_no'] : '') : (string)$r['code']) ?></option><?php endforeach; ?></select></div>
      <?= input_field('reason', 'Reason', '', ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="Misprint, paper jam"']) ?>
      <div class="col-12"><button class="btn btn-outline-primary btn-sm">Print again from there</button></div>
    </div></form>
    <?php endif; ?>
    <?php if (Access::can($pageKey, 'edit')): $all = $ledger === 'loan'
        ? DB::all("SELECT id, txn_date, kind, or_no FROM loan_txns WHERE loan_id = :a AND is_voided = 0 AND kind IN ('" . implode("','", Passbook::LOAN_KINDS) . "') ORDER BY txn_date DESC, id DESC LIMIT 40", ['a' => $a['id']])
        : DB::all('SELECT id, txn_date, txn_code, trans_no FROM sav_txns WHERE account_id = :a AND is_voided = 0 ORDER BY txn_date DESC, id DESC LIMIT 40', ['a' => $a['id']]); ?>
    <form method="post" class="card mb-3" data-once><div class="card-header">Reset the print line</div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="account" value="<?= h($no) ?>"><input type="hidden" name="ledger" value="<?= h($ledger) ?>">
      <div class="col-12 small text-muted">After a printing error: where the next line prints, and from which transaction the lines print again.</div>
      <?= input_field('pb_page', 'Page', (string)$np, ['class' => 'col-6', 'type' => 'number', 'step' => '1', 'extra' => 'min="1"']) ?>
      <?= input_field('pb_line', 'Line', (string)$nl, ['class' => 'col-6', 'type' => 'number', 'step' => '1', 'extra' => 'min="1" max="' . (int)$t['max_lines'] . '"']) ?>
      <?php if ($all): ?><div class="col-12"><label class="form-label">Print again from (optional)</label><select class="form-select form-select-sm" name="from_row" data-no-combo><option value="">Nothing again</option>
        <?php foreach ($all as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h(fdate($r['txn_date']) . ' ' . ($ledger === 'loan' ? Loans::ledgerKind($r) . ($r['or_no'] ? ' ' . $r['or_no'] : '') : $r['txn_code'] . ($r['trans_no'] ? ' ' . $r['trans_no'] : ''))) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <?= input_field('reason', 'Reason', '', ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="Paper jam on line 7"']) ?>
      <div class="col-12"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset the print line</button></div>
    </div></form>
    <form method="post" class="card mb-3" data-once><div class="card-header">New passbook</div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="replace"><input type="hidden" name="account" value="<?= h($no) ?>"><input type="hidden" name="ledger" value="<?= h($ledger) ?>">
      <?php if ($ledger === 'savings'): ?><?= input_field('passbook_no', 'New passbook no.', '', ['class' => 'col-12']) ?><?php endif; ?>
      <?= input_field('reason', 'Reason', '', ['class' => 'col-12', 'required' => true, 'extra' => 'placeholder="Passbook full / lost / damaged"']) ?>
      <div class="col-12"><button class="btn btn-outline-danger btn-sm" data-confirm="Start a new passbook? Printing restarts on page 1, line 1.">Issue a new passbook</button></div>
      <div class="col-12 small text-muted">Printing starts again on page 1, line 1, with the header. The balance carried over prints with the next transaction.</div>
    </div></form>
    <?php endif; ?>
  </div>
</div>
<?php endif;
layout_end();
