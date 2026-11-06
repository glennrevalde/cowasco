<?php
declare(strict_types=1);

/**
 * Savings Account Transaction: the teller's form for one savings, special savings or share capital account, as in
 * the cooperative's former program. The transaction code decides the entry (see app/SavTxn.php); Error Correct
 * negates an erroneous transaction; Back Entry records it on an earlier date; after Save the machine validation is
 * shown for the deposit / withdrawal slip (Show Preview) and the passbook is printed (Print to Passbook).
 */
require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.transact';
$mode = g('mode') === 'memo' || p('mode') === 'memo' ? 'memo' : 'teller';
$no = trim((string)(g('account') ?: p('account')));
$acct = null;
if ($no !== '') {
    $acct = Savings::byNo($no);
    if ($acct && !Coop::branchAllowed($acct['branch_id'] ? (int)$acct['branch_id'] : null)) {
        $acct = null;
        flash('warning', 'That account belongs to another branch.');
    }
    if ($acct && $acct['product_type'] === 'time_deposit') {
        flash('info', 'Account ' . $acct['account_no'] . ' is a time deposit: its transactions are made on the Time Deposit Center.');
        redirect(url('sav.td'));
    }
}
$self = fn(array $extra = []) => url($pageKey, array_filter(['account' => $acct['account_no'] ?? null, 'mode' => $mode === 'memo' ? 'memo' : null] + $extra, fn($v) => $v !== null && $v !== ''));

// ------------------------------------------------------------------ the machine validation, printed on the slip
if (g('a') === 'validate') {
    Access::require($pageKey, 'view');
    $r = SavTxn::row((int)gint('id'));
    if (!$r || empty($r['trans_no']) || !Coop::branchAllowed($r['branch_id'] ? (int)$r['branch_id'] : null)) {
        flash('warning', 'Transaction not found.');
        redirect(url($pageKey));
    }
    $copy = coop_print_gate('sav_validation', 'sav_txn', (int)$r['id'], (string)$r['trans_no'], $pageKey, 'machine validation');
    if ($copy === null) {
        exit;
    }
    // once printed, the window closes: the teller is back on Savings Account Transaction (told which one was printed)
    coop_slip_validation(SavTxn::validation($r), (string)$r['trans_no'], 'Insert the ' . (Dec::isPos($r['debit']) ? 'withdrawal' : 'deposit') . ' slip of transaction ' . h((string)$r['trans_no']) . ' in the printer, then print.', $copy,
        url($pageKey, array_filter(['mode' => g('mode') === 'memo' ? 'memo' : null, 'prev' => (string)$r['account_no'], 'printed' => (int)$r['id']])), ['id' => (int)$r['id']]);
    exit;
}

// ------------------------------------------------------------------ Batch Deposit / Batch Withdrawal: a CSV file of account nos. and amounts
if (g('a') === 'batch') {
    Access::require($pageKey, 'create');
    $kind = g('kind') === 'withdrawal' ? 'withdrawal' : 'deposit';
    $codes = CsvImports::batchCodes($kind);
    $defCode = $kind === 'deposit' ? (isset($codes['CSD']) ? 'CSD' : (string)(array_key_first($codes) ?? '')) : (isset($codes['CSW']) ? 'CSW' : (string)(array_key_first($codes) ?? ''));
    $opt = fn() => ['code' => strtoupper(trim((string)p('code', $defCode))), 'date' => (string)(p('txn_date') ?: today()), 'memo' => mb_substr(trim((string)p('memo')), 0, 160)];
    $o = is_post() ? $opt() : ['code' => $defCode, 'date' => today(), 'memo' => ''];
    $word = $kind === 'deposit' ? 'deposit' : 'withdrawal';
    $cfg = [
        'cols' => CsvImports::cols($kind), 'aliases' => CsvImports::aliases($kind), 'noun' => [$word, $word . 's'], 'total' => 'amount',
        'template_name' => 'batch_' . $word . '_template.csv', 'samples' => CsvImports::samples($kind), 'opt' => $opt,
        'check' => fn(array $rows, array $o) => CsvImports::check($kind, $rows, $o),
        'post' => function (array $rows, array $o, string $file) use ($pageKey, $kind, $word) {
            $classId = Classes::required('class_id', 'the batch ' . $word);
            $r = CoopLedger::withClass($classId, fn() => CsvImports::post($kind, $rows, $o, $file, $pageKey));
            return $r['count'] . ' ' . $word . '(s) posted for ' . Dec::money($r['total']) . ': transactions ' . $r['refs'][0] . (count($r['refs']) > 1 ? ' to ' . end($r['refs']) : '') . ' (Savings Transaction Center).';
        },
        'can_post' => true, 'post_label' => 'Post', 'post_icon' => 'journal-check', 'done' => Access::page('sav.center') ? url('sav.center') : url($pageKey),
        'confirm' => 'Post the ' . $word . 's? Each row becomes a transaction of the Savings Transaction Center.',
        'fields' => select_field('code', 'Transaction code', $codes, $o['code'], ['class' => 'col-md-5', 'extra' => 'data-no-combo'])
            . input_field('txn_date', 'Date', $o['date'], ['class' => 'col-6 col-md-3', 'type' => 'date'])
            . Classes::requiredField(fv('class_id', null), 'col-6 col-md-4')
            . input_field('memo', 'Memo of the batch', $o['memo'], ['class' => 'col-12', 'extra' => 'maxlength="160" placeholder="' . ($kind === 'deposit' ? 'e.g. Payroll deduction, October 2026' : 'e.g. Payroll release, October 2026') . '"'])
            . '<div class="col-12 form-text mt-0">A date before today records back entries (the policy on back-dated member transactions applies). A row with its own memo keeps it.</div>',
        'extra' => fn() => '<div class="card mt-3"><div class="card-body py-2">' . Approval::fields() . '</div></div>',
        'intro' => ($kind === 'deposit' ? 'Deposits' : 'Withdrawals') . ' to many accounts at once, one row per account: each row becomes a transaction of the Savings Transaction Center with the code, date and memo chosen here, as if it were entered on this form.',
        'help' => $kind === 'withdrawal' ? 'A withdrawal that needs a supervisor (dormant account, share capital, above your limit) is approved below with the supervisor\'s username and password; a one-time approval code covers one transaction only.' : '',
    ];
    $state = CsvReview::handle($cfg);
    coop_layout('Batch ' . $word, ['actions' => btn_link(url($pageKey), 'Savings Account Transaction', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/csv_review.js']]);
    echo CsvReview::render($cfg, $state);
    layout_end();
    exit;
}

handle_post(function () use ($pageKey, $acct, $mode, $self) {
    $action = (string)p('action', 'save');
    if (!$acct) {
        fail('Enter the account number.');
    }
    if ($action === 'notes') {
        Access::require('sav.accounts', 'edit');
        $notes = mb_substr(trim((string)p('notes')), 0, 255);
        DB::update('sav_accounts', ['notes' => $notes !== '' ? $notes : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $acct['id']]);
        Audit::log('edit', 'sav', 'sav_account', (int)$acct['id'], $acct['account_no'], ['page_key' => $pageKey, 'changes' => [['field' => 'notes', 'old' => (string)$acct['notes'], 'new' => $notes]]]);
        flash('success', 'Savings notes saved.');
        redirect($self());
    }
    if ($action === 'delete') {
        Access::require($pageKey, 'create');
        $r = SavTxn::row((int)pint('sav_txn_id'));
        if (!$r || (int)$r['account_id'] !== (int)$acct['id']) {
            fail('Choose the transaction to delete.');
        }
        SavTxn::delete((int)$r['id'], trim((string)p('reason')), $pageKey);
        flash('success', 'Transaction ' . $r['trans_no'] . ' was deleted (voided). The account and the books were reversed with it.');
        redirect($self());
    }
    if ($action === 'pb_commit') {
        Access::require('sav.passbook', 'print');
        $pb = Passbook::open('savings', $acct['account_no']);
        if (!$pb || !$pb['type']) {
            fail('The account has no passbook type.');
        }
        // every page of the run that printed right; then the form is cleared for the next transaction
        [$n, $pages] = Passbook::commitRun($pb, Passbook::postedRun(), (int)p('upto'), 'sav.passbook');
        $left = count(Passbook::pending(Passbook::open('savings', $acct['account_no'])));
        flash('success', $n . ' line(s) marked as printed in the passbook of ' . $acct['account_no'] . ' (' . coop_pb_pages_words($pages) . ').'
            . ($left ? ' ' . $left . ' line(s) still to print: Update Passbook prints them.' : ''));
        redirect(url($pageKey, array_filter(['mode' => $mode === 'memo' ? 'memo' : null, 'prev' => $acct['account_no']])));
    }
    // save
    Access::require($pageKey, 'create');
    coop_once();
    $ec = p('error_correct') === '1';
    $cheques = [];
    foreach ((array)($_POST['chq'] ?? []) as $c) {
        if (is_array($c)) {
            $cheques[] = ['cheque_no' => (string)($c['no'] ?? ''), 'bank' => (string)($c['bank'] ?? ''), 'date' => (string)($c['date'] ?? ''), 'amount' => to_num($c['amount'] ?? 0) ? (string)to_num($c['amount']) : ''];
        }
    }
    if (trim((string)p('code')) === '') {
        fail('Choose the transaction type (the transaction code).');
    }
    $classId = Classes::required('class_id', 'the savings transaction');
    $r = CoopLedger::withClass($classId, fn() => SavTxn::post([
        'account_id' => (int)$acct['id'], 'code' => strtoupper(trim((string)p('code'))), 'amount' => Coop::amount('amount', true), 'date' => (string)p('txn_date', today()),
        'memo' => trim((string)p('memo')), 'back_entry' => p('back_entry') === '1', 'cheques' => $cheques, 'gl_account_id' => pint('gl_account_id'),
        'corrects_id' => $ec ? pint('corrects_id') : null,
    ], $pageKey));
    // the page says what was saved (with the validation) from the done= of the address
    redirect($self(['done' => $r['sav_txn_id'], 'pv' => p('show_preview') === '1' ? 1 : 0, 'pp' => p('print_pb') === '1' ? 1 : 0]));
});

// ------------------------------------------------------------------ the page
$m = $acct ? Coop::member((int)$acct['member_id']) : null;
$types = Members::types();
$codeRows = SavTxn::codes($mode) + SavTxn::codes('ec');
if ($mode === 'memo') {
    $codeRows = array_filter($codeRows, fn($c) => (int)($c['is_memo'] ?? 0) === 1);
} else {
    $codeRows = array_filter($codeRows, fn($c) => (int)($c['is_memo'] ?? 0) === 0);
}
$cash = Teller::currentCashAccount();
$codeJs = [];
foreach ($codeRows as $code => $c) {
    $opp = (int)$c['is_check_deposit'] === 1 ? 'check clearing ' . Coop::accountLabel((int)($c['opposite_account_id'] ?: Accts::get('coop_coci')))
        : ((int)($c['is_memo'] ?? 0) === 1 ? 'against the GL account chosen'
        : ($c['opposite_kind'] === 'gl' ? ($c['opposite_account_id'] ? 'against ' . Coop::accountLabel((int)$c['opposite_account_id']) : '') : 'cash: your drawer' . ($cash ? ' ' . Coop::accountLabel($cash) : '')));
    $codeJs[$code] = ['side' => $c['entry_side'], 'check' => (int)$c['is_check_deposit'], 'memo' => (int)($c['is_memo'] ?? 0), 'ec' => (int)$c['is_error_correct'], 'related' => (string)$c['related_code'], 'opp' => $opp];
}
$done = gint('done') ? SavTxn::row((int)gint('done')) : null;
// an amount as the passbook shows it: an error correction negative
$sm = fn(array $r): string => (Dec::isNeg(SavTxn::amount($r)) ? '-' : '') . cm(Dec::abs(SavTxn::amount($r)));
if ($done && (!$acct || (int)$done['account_id'] !== (int)$acct['id'])) {
    $done = null;
}
$pb = $acct ? Passbook::open('savings', $acct['account_no']) : null;
// every line not yet printed, page by page (one printing run turns the passbook from page to page)
$pbRun = $pb && $pb['type'] ? Passbook::run($pb) : null;
// the account of the transaction just finished (the form is clear): offered to open again
$prev = !$acct && g('prev') ? Savings::byNo((string)g('prev')) : null;
if ($prev && !Coop::branchAllowed($prev['branch_id'] ? (int)$prev['branch_id'] : null)) {
    $prev = null;
}
$printedRow = $prev && gint('printed') ? SavTxn::row((int)gint('printed')) : null;
if ($printedRow && (int)$printedRow['account_id'] !== (int)$prev['id']) {
    $printedRow = null;
}
$correctable = $acct ? SavTxn::correctable((int)$acct['id']) : [];
$recent = $acct ? DB::all('SELECT s.*, u.username FROM sav_txns s LEFT JOIN users u ON u.id = s.teller_id WHERE s.account_id = :a ORDER BY s.txn_date DESC, s.id DESC LIMIT 12', ['a' => $acct['id']]) : [];
$deletable = array_values(array_filter($recent, fn($r) => !empty($r['trans_no']) && (int)$r['is_voided'] === 0 && (int)$r['pb_printed'] === 0 && empty($r['corrected_by_id'])));
$fromPost = is_post();
// the transaction type starts empty: the teller chooses the code of each transaction
$curCode = (string)fv('code', null, '');
$ecOn = $fromPost ? p('error_correct') === '1' : g('ec') === '1';
$teller = Auth::user();
$status = $acct ? (string)$acct['status'] : '';
$stateWord = ['active' => 'ACTIVE', 'pending' => 'FOR APPROVAL', 'dormant' => 'DORMANT', 'closed' => 'CLOSED'][$status] ?? strtoupper($status);
$glAccounts = Accts::all();

$actions = [];
if ($mode === 'teller' && Access::can($pageKey, 'create')) {
    $actions[] = btn_link(url($pageKey, ['a' => 'batch', 'kind' => 'deposit']), 'Batch Deposit', 'btn-outline-primary', 'box-arrow-in-down');
    $actions[] = btn_link(url($pageKey, ['a' => 'batch', 'kind' => 'withdrawal']), 'Batch Withdrawal', 'btn-outline-primary', 'box-arrow-up');
}
if (Access::page('sav.center')) {
    $actions[] = btn_link(url('sav.center'), 'Savings Transaction Center', 'btn-outline-secondary', 'list-columns-reverse');
}
if ($acct && Access::page('sav.accounts')) {
    $actions[] = btn_link(url('sav.accounts', ['id' => $acct['id']]), 'Ledger', 'btn-outline-secondary', 'journal-text');
}
coop_layout($mode === 'memo' ? 'Savings Memo (Debit / Credit Memo)' : 'Savings Account Transaction', ['actions' => implode(' ', $actions), 'js' => ['js/savings.js']]);
?>
<?php if ($done && (g('pv') === '1' || g('pp') === '1')): ?>
<div data-after-save <?= g('pv') === '1' ? 'data-validate="#validationModal"' : '' ?> <?= g('pp') === '1' && $pbRun && $pbRun['count'] ? 'data-passbook="#passbookModal"' : '' ?>></div>
<?php endif; ?>
<?php if ($done): ?>
<?php // once the validation is printed (and no passbook printing follows), the form is cleared for the next transaction ?>
<div data-clear-after-print="<?= h(url($pageKey, array_filter(['mode' => $mode === 'memo' ? 'memo' : null, 'prev' => $acct['account_no'], 'printed' => (int)$done['id']]))) ?>" hidden></div>
<?php endif; ?>
<?php if ($prev): ?>
<div class="sv-prev no-print">
  <i class="bi bi-check2-circle"></i>
  <div><?= $printedRow ? 'Transaction <strong>' . h((string)$printedRow['trans_no']) . '</strong> (' . h($printedRow['txn_code']) . ' ' . $sm($printedRow) . ') is printed. ' : '' ?>The form is clear for the next transaction.
    <div class="small text-muted">Last account: <?= h($prev['account_no']) ?>, <?= h((string)$prev['member_name']) ?></div></div>
  <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, array_filter(['account' => $prev['account_no'], 'mode' => $mode === 'memo' ? 'memo' : null]))) ?>"><i class="bi bi-arrow-counterclockwise"></i> Open <?= h($prev['account_no']) ?> again</a>
</div>
<?php endif; ?>
<?php if ($done): ?>
<div class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2 no-print">
  <span>Transaction <strong><?= h($done['trans_no']) ?></strong> saved: <?= h($done['txn_code']) ?> <?= $sm($done) ?> on <?= h($done['account_no']) ?>.</span>
  <span class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#validationModal"><i class="bi bi-receipt-cutoff"></i> Validation</button>
    <?php if ($done['txn_id']): ?><?= btn_link(url('sav.center', ['a' => 'view', 'id' => $done['txn_id']]), 'Journal entry', 'btn-outline-secondary', 'journal-check') ?><?php endif; ?>
  </span>
</div>
<?php endif; ?>

<form method="post" class="card mb-3" id="svForm" data-once data-today="<?= h(today()) ?>" data-codes="<?= h(json_encode($codeJs)) ?>" <?= $fromPost ? 'data-from-post="1"' : '' ?>
      data-confirm="Save {code} of {amount} on <?= h($acct['account_no'] ?? '') ?>?" data-confirm-title="Save the savings transaction" data-confirm-ok="Save">
  <?= csrf_field() ?><?= Coop::onceField() ?>
  <input type="hidden" name="action" value="save"><input type="hidden" name="mode" value="<?= h($mode) ?>">
  <div class="sv-head">
    <div class="sv-acct">
      <label for="acctFind">Account no</label>
      <div class="member-pick" data-account-pick data-api="<?= h(url('api.coop', ['a' => 'accounts'])) ?>" data-href="<?= h(url($pageKey, $mode === 'memo' ? ['mode' => 'memo'] : []) . '&account={no}') ?>">
        <i class="bi bi-search"></i>
        <input type="search" class="form-control" id="acctFind" name="account" value="<?= h($acct['account_no'] ?? $no) ?>" <?= $acct ? '' : 'autofocus' ?> autocomplete="off"
               placeholder="Account no., passbook, or the member's name" aria-autocomplete="list">
        <div class="member-pick-list" role="listbox" hidden></div>
      </div>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('sav.accounts')) ?>" title="Find the account on the Savings Account Center"><i class="bi bi-binoculars"></i></a>
    </div>
    <div class="sv-state <?= $acct ? 'is-' . h($status) : 'is-none' ?>"><?= $acct ? h($stateWord) : 'Type the account no. or the member\'s name' ?></div>
  </div>
  <?php if ($no !== '' && !$acct): ?><div class="alert alert-warning m-3 mb-0">No savings or share capital account <?= h($no) ?>.</div><?php endif; ?>
  <div class="card-body pb-1">
    <div class="row g-3">
      <div class="col-lg-6">
        <fieldset class="sv-group">
          <legend>Member Information</legend>
          <div class="row g-2">
            <div class="col-5 col-md-4 sv-field"><label class="form-label">Member ID</label><input class="form-control form-control-sm" value="<?= h($m['member_no'] ?? '') ?>" readonly tabindex="-1"></div>
            <div class="col-7 col-md-8 sv-field"><label class="form-label">Member Type</label><input class="form-control form-control-sm" value="<?= h($m ? mb_strtoupper((string)($types[(int)$m['member_type_id']]['name'] ?? '')) : '') ?>" readonly tabindex="-1"></div>
            <div class="col-12 sv-field"><label class="form-label">Member Name</label>
              <div class="input-group input-group-sm"><input class="form-control" value="<?= h($m['full_name'] ?? '') ?>" readonly tabindex="-1">
                <?php if ($m && Access::page('coop.member')): ?><a class="btn btn-outline-secondary" href="<?= h(url('coop.member', ['id' => $m['id']])) ?>" title="Member Information"><i class="bi bi-person-vcard"></i></a><?php endif; ?></div></div>
            <?php if ($m && ((int)$m['hold_flag'] === 1 || trim((string)$m['alert_note']) !== '')): ?>
              <div class="col-12 small <?= (int)$m['hold_flag'] === 1 ? 'text-danger' : 'text-warning-emphasis' ?>"><i class="bi bi-exclamation-triangle"></i> <?= (int)$m['hold_flag'] === 1 ? 'On hold: ' . h((string)$m['hold_reason']) : h((string)$m['alert_note']) ?></div>
            <?php endif; ?>
          </div>
        </fieldset>
        <fieldset class="sv-group">
          <legend>Savings Information</legend>
          <div class="row g-2">
            <div class="col-4 col-md-3 sv-field"><label class="form-label">Savings Code</label><input class="form-control form-control-sm" value="<?= h($acct['product_code'] ?? '') ?>" readonly tabindex="-1"></div>
            <div class="col-8 col-md-9 sv-field"><label class="form-label">Savings Name</label><input class="form-control form-control-sm" value="<?= h($acct['product_name'] ?? '') ?>" readonly tabindex="-1"></div>
            <div class="col-6 sv-field"><label class="form-label">Current Balance</label><input class="form-control form-control-sm sv-money" value="<?= $acct ? cm($acct['balance']) : '' ?>" readonly tabindex="-1"></div>
            <div class="col-6 sv-field"><label class="form-label">Withdrawable Balance</label><input class="form-control form-control-sm sv-money" value="<?= $acct ? cm(Dec::max('0', Dec::sub(Savings::available($acct), $acct['min_balance'], 2))) : '' ?>" readonly tabindex="-1"></div>
            <div class="col-6 sv-field"><label class="form-label">Floating Amount</label><input class="form-control form-control-sm sv-money" value="<?= $acct ? cm($acct['uncleared']) : '' ?>" readonly tabindex="-1" title="Check deposits still clearing"></div>
            <div class="col-6 sv-field"><label class="form-label">Restricted Amount</label><input class="form-control form-control-sm sv-money" value="<?= $acct ? cm(Dec::add($acct['hold_amount'], (int)$acct['is_restricted'] === 1 ? Savings::available($acct) : '0', 2)) : '' ?>" readonly tabindex="-1" title="Holds, or the whole available balance when withdrawals are restricted"></div>
            <div class="col-12 sv-field"><label class="form-label">Savings Notes</label>
              <textarea class="form-control form-control-sm" name="notes" rows="2" maxlength="255" form="svNotes" <?= $acct && Access::can('sav.accounts', 'edit') ? '' : 'readonly' ?>><?= h((string)($acct['notes'] ?? '')) ?></textarea></div>
            <?php if ($acct && (int)$acct['is_restricted'] === 1): ?><div class="col-12 small text-danger"><i class="bi bi-lock"></i> Withdrawals restricted: <?= h((string)$acct['restrict_reason']) ?></div><?php endif; ?>
            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
              <?php if ($acct && Access::can('sav.accounts', 'edit')): ?><button class="btn btn-sm btn-outline-secondary" form="svNotes"><i class="bi bi-save"></i> Save notes</button><?php endif; ?>
              <?php if ($acct && Access::page('sav.clearing')): ?><a class="btn btn-sm btn-outline-secondary<?= Dec::isPos($acct['uncleared']) ? '' : ' disabled' ?>" href="<?= h(url('sav.clearing', ['account' => $acct['account_no']])) ?>"><i class="bi bi-check2-square"></i> Clear Cheque</a><?php endif; ?>
            </div>
          </div>
        </fieldset>
      </div>
      <div class="col-lg-6">
        <fieldset class="sv-group" <?= $acct ? '' : 'disabled' ?>>
          <legend>Transaction Details</legend>
          <div class="row g-2 align-items-end">
            <div class="col-6 col-md-5 sv-field"><label class="form-label">Transaction no</label><input class="form-control form-control-sm sv-ro" value="" placeholder="<?= h(SavTxn::peek()) ?>" readonly tabindex="-1" title="Given when the transaction is saved"></div>
            <div class="col-6 col-md-7"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="error_correct" value="1" id="svEc" <?= $ecOn ? 'checked' : '' ?>>
              <label class="form-check-label" for="svEc">Error Correct</label></div></div>
            <div class="col-12 sv-field"><label class="form-label" for="svCode">Transaction type</label>
              <select class="form-select form-select-sm" name="code" id="svCode" data-no-combo data-confirm-value="code" required>
                <option value="">Choose the transaction type</option>
                <?php foreach ($codeRows as $code => $c): ?><option value="<?= h($code) ?>" <?= (int)$c['is_error_correct'] === 1 ? 'data-ec="1"' : '' ?> <?= $code === $curCode ? 'selected' : '' ?>><?= h($code . ' - ' . $c['description']) ?></option><?php endforeach; ?>
              </select>
              <div class="sv-side mt-1" id="svSide"></div></div>
            <div class="col-12 sv-field" id="svEcWrap" <?= $ecOn ? '' : 'hidden' ?>><label class="form-label" for="svCorrects">Transaction entered by mistake (negated in full)</label>
              <select class="form-select form-select-sm" name="corrects_id" id="svCorrects" data-no-combo>
                <option value="">Choose the erroneous transaction</option>
                <?php foreach ($correctable as $r): $amt = SavTxn::amount($r); ?>
                  <option value="<?= (int)$r['id'] ?>" data-code="<?= h($r['txn_code']) ?>" data-amount="<?= h(Dec::fix($amt)) ?>" <?= (string)fv('corrects_id', null) === (string)$r['id'] ? 'selected' : '' ?>>
                    <?= h(($r['trans_no'] ?: '#' . $r['id']) . '  ' . fdate($r['txn_date']) . '  ' . $r['txn_code'] . '  ' . Dec::money($amt)) ?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="col-6 sv-field"><label class="form-label" for="svAmount">Amount</label>
              <input class="form-control form-control-sm sv-money sv-big" name="amount" id="svAmount" value="<?= h((string)p('amount')) ?>" inputmode="decimal" data-money required data-confirm-value="amount" autocomplete="off"></div>
            <div class="col-6 sv-field"><label class="form-label" for="svDate">Date</label>
              <input class="form-control form-control-sm" type="date" name="txn_date" id="svDate" value="<?= h((string)fv('txn_date', null, today())) ?>" max="<?= h(today()) ?>"></div>
            <div class="col-12 sv-field" id="svGlWrap" hidden><label class="form-label">GL account (the other side of the memo)</label>
              <select class="form-select form-select-sm" name="gl_account_id" data-combo data-combo-placeholder="Type the account"><?= options($glAccounts, fv('gl_account_id', null), '') ?></select></div>
            <div class="col-12 sv-field"><label class="form-label" for="svMemo">Memo</label><textarea class="form-control form-control-sm" name="memo" id="svMemo" rows="2" maxlength="160"><?= h((string)p('memo')) ?></textarea></div>
            <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
          </div>
        </fieldset>
        <?php if ($mode !== 'memo'): ?>
        <fieldset class="sv-group sv-cheques" <?= $acct ? '' : 'disabled' ?>>
          <legend>Cheque Deposit Details</legend>
          <div class="table-responsive"><table class="table table-sm" id="svCheques" data-rows="svChqTpl">
            <thead><tr><th>Cheque No</th><th>Bank Name</th><th>Date</th><th class="text-end">Amount</th><th></th></tr></thead><tbody>
            <?php $chqRows = $fromPost ? array_values(array_filter((array)($_POST['chq'] ?? []), 'is_array')) : []; if (!$chqRows) { $chqRows = [[]]; } ?>
            <?php foreach ($chqRows as $i => $c): ?><tr>
              <td><input class="form-control form-control-sm" name="chq[<?= $i ?>][no]" value="<?= h((string)($c['no'] ?? '')) ?>" style="min-width:7rem"></td>
              <td><input class="form-control form-control-sm" name="chq[<?= $i ?>][bank]" value="<?= h((string)($c['bank'] ?? '')) ?>" style="min-width:8rem"></td>
              <td><input class="form-control form-control-sm" type="date" name="chq[<?= $i ?>][date]" value="<?= h((string)($c['date'] ?? today())) ?>"></td>
              <td><input class="form-control form-control-sm text-end" name="chq[<?= $i ?>][amount]" value="<?= h((string)($c['amount'] ?? '')) ?>" inputmode="decimal" data-money data-chq-amount style="min-width:7rem"></td>
              <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del title="Remove"><i class="bi bi-x-lg"></i></button></td></tr><?php endforeach; ?>
            </tbody></table></div>
          <template id="svChqTpl"><tr>
            <td><input class="form-control form-control-sm" name="chq[__i__][no]" style="min-width:7rem"></td><td><input class="form-control form-control-sm" name="chq[__i__][bank]" style="min-width:8rem"></td>
            <td><input class="form-control form-control-sm" type="date" name="chq[__i__][date]" value="<?= h(today()) ?>"></td>
            <td><input class="form-control form-control-sm text-end" name="chq[__i__][amount]" inputmode="decimal" data-money data-chq-amount style="min-width:7rem"></td>
            <td><button type="button" class="btn btn-sm btn-link text-danger p-0" data-del title="Remove"><i class="bi bi-x-lg"></i></button></td></tr></template>
          <div class="d-flex justify-content-between align-items-center small mt-1"><button type="button" class="btn btn-link btn-sm p-0" data-add-rows="svCheques"><i class="bi bi-plus-lg"></i> Add a cheque</button>
            <span class="text-muted">Cheques: <strong data-chq-total>0.00</strong></span></div>
        </fieldset>
        <?php endif; ?>
        <div class="sv-checks mb-2">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="print_pb" value="1" id="svPrintPb" <?= $fromPost ? (p('print_pb') === '1' ? 'checked' : '') : 'checked' ?>><label class="form-check-label" for="svPrintPb">Print to Passbook</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="show_preview" value="1" id="svPreview" <?= $fromPost ? (p('show_preview') === '1' ? 'checked' : '') : 'checked' ?>><label class="form-check-label" for="svPreview">Show Preview</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="back_entry" value="1" id="svBack" <?= p('back_entry') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="svBack">Back Entry</label></div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#passbookModal" <?= $pb ? '' : 'disabled' ?>><i class="bi bi-journal-arrow-down"></i> Update Passbook<?= $pbRun && $pbRun['count'] ? ' (' . (int)$pbRun['count'] . ')' : '' ?></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#reprintModal" <?= $recent ? '' : 'disabled' ?>><i class="bi bi-receipt"></i> Reprint Validation</button>
        </div>
        <div class="small"><?= Approval::fields() ?></div>
      </div>
    </div>
  </div>
  <div class="sv-foot">
    <div class="sv-teller"><span>Teller:</span><?= h((string)($teller['full_name'] ?? '')) ?> <span class="small fw-normal text-muted">(ID <?= (int)Auth::id() ?>)</span></div>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal" <?= $deletable ? '' : 'disabled' ?>><i class="bi bi-trash3"></i> Delete</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-sv-clear data-clear-url="<?= h(url($pageKey, $mode === 'memo' ? ['mode' => 'memo'] : [])) ?>" title="Empty the form for the next transaction"><i class="bi bi-eraser"></i> Clear</button>
      <button class="btn btn-primary btn-sm px-4" <?= $acct && in_array($status, ['active', 'dormant', 'pending'], true) ? '' : 'disabled' ?>><i class="bi bi-check-lg"></i> Save</button>
    </div>
  </div>
  <div class="sv-statusbar">
    <div><?= $acct ? ($status === 'closed' ? 'Account closed' : 'Account Ok') . ((int)$acct['is_restricted'] === 1 ? ', restricted' : '') : 'No account' ?></div><?= $cash ? '<div>Cash ' . h(Coop::accountLabel($cash)) . '</div>' : '' ?>
    <?php $shiftNow = Teller::openShift(); ?>
    <div>WorkStation: <?= h($shiftNow ? 'shift ' . (int)$shiftNow['id'] . ', ' . $shiftNow['cash_code'] : (string)($_SERVER['REMOTE_ADDR'] ?? '')) ?></div>
    <div>Date: <?= h(fdate(today())) ?></div>
    <div>Time: <span data-clock></span></div>
  </div>
</form>
<form method="post" id="svNotes" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="account" value="<?= h($acct['account_no'] ?? '') ?>"><input type="hidden" name="mode" value="<?= h($mode) ?>"></form>

<?php if ($recent): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center"><span>Latest transactions of <?= h($acct['account_no']) ?></span>
  <?php if (Access::page('sav.accounts')): ?><a class="small" href="<?= h(url('sav.accounts', ['id' => $acct['id']])) ?>">Full ledger</a><?php endif; ?></div>
  <div class="table-responsive"><table class="table table-sm table-hover mb-0 sv-recent"><thead><tr><th>Date</th><th>Transaction no</th><th>Code</th><th class="num">Withdrawal</th><th class="num">Deposit</th><th class="num">Balance</th><th>Teller</th><th>Passbook</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?>
    <tr class="<?= (int)$r['is_voided'] ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(fdate($r['txn_date'])) ?></td><td><?= h((string)($r['trans_no'] ?: '')) ?></td><td><?= h($r['txn_code']) ?><?= $r['corrected_by_id'] ? ' <span class="small text-muted">(corrected)</span>' : '' ?></td>
      <td class="num <?= Dec::isNeg($r['debit']) ? 'text-danger' : '' ?>"><?= Dec::isZero($r['debit']) ? '' : (Dec::isNeg($r['debit']) ? '-' . cm(Dec::abs($r['debit'])) : cm($r['debit'])) ?></td>
      <td class="num <?= Dec::isNeg($r['credit']) ? 'text-danger' : '' ?>"><?= Dec::isZero($r['credit']) ? '' : (Dec::isNeg($r['credit']) ? '-' . cm(Dec::abs($r['credit'])) : cm($r['credit'])) ?></td>
      <td class="num"><?= (int)$r['is_voided'] ? '' : cm($r['balance_after']) ?></td><td><?= $r['teller_id'] ? (int)$r['teller_id'] : '' ?></td>
      <td class="small"><?= (int)$r['pb_printed'] ? 'p. ' . (int)$r['pb_page'] . ' l. ' . (int)$r['pb_line'] : ((int)$r['is_voided'] ? '' : 'not yet') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>

<?php // ---------------------------------------------------------------- dialogs
if ($done):
    $val = SavTxn::validation($done); ?>
<div class="modal fade" id="validationModal" tabindex="-1" aria-labelledby="valTitle"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="valTitle">Machine Validation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div class="small text-muted mb-2">Main report</div>
    <div class="sv-slip"><?php foreach ($val as $parts): ?><div class="sv-slip-line"><?php foreach ($parts as $part): ?><span><?= h($part) ?></span><?php endforeach; ?></div><?php endforeach; ?></div>
  </div>
  <div class="modal-footer justify-content-between"><span class="small">Please make sure you have inserted the deposit/withdrawal slip.</span>
    <span class="d-flex gap-2"><button type="button" class="btn btn-primary btn-sm" data-print-then data-url="<?= h(url($pageKey, ['a' => 'validate', 'id' => $done['id']])) ?>" data-bs-dismiss="modal"><i class="bi bi-printer"></i> Print</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button></span></div>
</div></div></div>
<?php endif; ?>

<?php if ($pb && $pbRun): ?>
<div class="modal fade" id="passbookModal" tabindex="-1" aria-labelledby="pbTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="pbTitle">Print to Passbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <?php if ($pbRun['count']): ?>
      <?= coop_pb_run_box($pb, $pbRun, 'pbConfirm', '<input type="hidden" name="action" value="pb_commit"><input type="hidden" name="account" value="' . h($acct['account_no']) . '"><input type="hidden" name="mode" value="' . h($mode) . '">',
          '<strong>' . h($acct['account_no']) . '</strong> (' . h($acct['member_name']) . ')', Access::can('sav.passbook', 'print')) ?>
    <?php else: ?>
      <p class="mb-0">Every transaction of <?= h($acct['account_no']) ?> is printed in the passbook.</p>
    <?php endif; ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>
<?php elseif ($pb && !$pb['type']): ?>
<div class="modal fade" id="passbookModal" tabindex="-1" aria-labelledby="pbTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="pbTitle">Print to Passbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p class="mb-0">The product of this account has no passbook type (Savings &amp; Deposit Products).</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>
<?php endif; ?>

<?php if ($recent): ?>
<div class="modal fade" id="reprintModal" tabindex="-1" aria-labelledby="rpTitle"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="rpTitle">Reprint Validation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body p-0"><div class="list-group list-group-flush">
    <?php foreach (array_filter($recent, fn($r) => !empty($r['trans_no']) && (int)$r['is_voided'] === 0) as $r): ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" target="_blank" href="<?= h(url($pageKey, ['a' => 'validate', 'id' => $r['id']])) ?>">
        <span><strong><?= h($r['trans_no']) ?></strong> <?= h($r['txn_code']) ?> <span class="text-muted small"><?= h(fdate($r['txn_date'])) ?></span></span><span class="fw-semibold"><?= $sm($r) ?></span></a>
    <?php endforeach; ?>
  </div></div>
  <div class="modal-footer"><span class="small text-muted me-auto">A reprint asks for the reason and is counted.</span><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button></div>
</div></div></div>
<?php endif; ?>

<?php if ($deletable): ?>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="delTitle"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" data-once>
  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="account" value="<?= h($acct['account_no']) ?>"><input type="hidden" name="mode" value="<?= h($mode) ?>">
  <div class="modal-header"><h5 class="modal-title" id="delTitle">Delete a transaction</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <label class="form-label">Transaction (not yet printed in the passbook)</label>
    <select class="form-select form-select-sm mb-2" name="sav_txn_id" data-no-combo required>
      <?php foreach ($deletable as $r): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['trans_no'] . '  ' . fdate($r['txn_date']) . '  ' . $r['txn_code'] . '  ' . strip_tags($sm($r))) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label">Reason (kept in the audit trail)</label><textarea class="form-control form-control-sm" name="reason" rows="2" required></textarea>
    <div class="small text-muted mt-2">The transaction is voided: it stays on file marked void and the account and the books are reversed. A transaction already printed in the passbook is corrected with Error Correct instead. Another teller's or an earlier day's transaction needs a supervisor.</div>
    <?= Approval::fields() ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm"><i class="bi bi-trash3"></i> Delete</button></div>
</form></div></div>
<?php endif;
layout_end();
