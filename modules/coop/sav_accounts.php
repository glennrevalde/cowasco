<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.accounts';
$a = (string)g('a');
$acct = gint('id') ? Savings::account((int)gint('id')) : null;
if ($acct && !Coop::branchAllowed($acct['branch_id'] ? (int)$acct['branch_id'] : null)) {
    Access::denyPage($pageKey, 'open an account of another branch on');
}

// ------------------------------------------------------------------ the holds of an account, for the Unhold window
if ($a === 'holds' && $acct) {
    Access::require($pageKey, 'view');
    $list = DB::all('SELECT h.*, l.loan_no, u.full_name AS by_name FROM sav_holds h LEFT JOIN loan_accounts l ON l.id = h.loan_account_id LEFT JOIN users u ON u.id = h.placed_by
                      WHERE h.account_id = :a AND h.released_at IS NULL ORDER BY h.id', ['a' => $acct['id']]);
    if (!$list) {
        echo '<p class="small text-muted mb-0">No amount of this account is on hold.</p>';
        exit;
    }
    echo '<div class="sv-holds" role="radiogroup" aria-label="The holds of the account">';
    foreach ($list as $i => $hh) {
        echo '<label class="sv-hold"><input class="form-check-input" type="radio" name="hold_id" value="' . (int)$hh['id'] . '"' . ($i === 0 ? ' checked' : '') . ' required>'
            . '<span class="sv-hold-amt">' . cm($hh['amount']) . '</span><span class="sv-hold-why">' . h((string)$hh['reason']) . ($hh['loan_no'] ? ' (loan ' . h($hh['loan_no']) . ')' : '')
            . '<span>' . h(fdatetime($hh['placed_at'])) . ($hh['by_name'] ? ', ' . h($hh['by_name']) : '') . '</span></span></label>';
    }
    echo '</div>';
    if (count($list) > 1) {
        echo '<div class="form-check mt-2"><input class="form-check-input" type="radio" name="hold_id" value="all" id="unholdAll"><label class="form-check-label" for="unholdAll">Every hold of the account (' . cm(Dec::sum(array_column($list, 'amount'))) . ')</label></div>';
    }
    exit;
}

// ------------------------------------------------------------------ Import Savings: a CSV file of savings accounts and balances, reviewed, then uploaded
if ($a === 'import') {
    Access::require($pageKey, 'create');
    $codes = CsvImports::importCodes();
    $defCode = isset($codes['INI']) ? 'INI' : (string)(array_key_first($codes) ?? '');
    $opt = fn() => ['savings_txn_code' => strtoupper(trim((string)p('savings_txn_code', $defCode))), 'cut_off_date' => (string)(p('cut_off_date') ?: today())];
    $o = is_post() ? $opt() : ['savings_txn_code' => $defCode, 'cut_off_date' => today()];
    $cfg = [
        'cols' => CsvImports::cols('savings'), 'aliases' => CsvImports::aliases('savings'), 'noun' => ['account', 'accounts'], 'total' => 'amount',
        'template_name' => 'import_savings_template.csv', 'samples' => CsvImports::samples('savings'), 'opt' => $opt,
        'check' => fn(array $rows, array $o) => CsvImports::check('savings', $rows, $o),
        'post' => function (array $rows, array $o, string $file) use ($pageKey) {
            $r = CsvImports::post('savings', $rows, $o, $file, $pageKey);
            return $r['count'] . ' savings account(s) imported, Active, with ' . Dec::money($r['total']) . ' in balances.';
        },
        'can_post' => Access::can($pageKey, 'approve'), 'no_post' => 'You may read and check the file; uploading it (accounts made Active with their balances) needs the Approve right on the Savings Account Center.',
        'done' => url($pageKey), 'confirm' => 'Upload the savings accounts? Each one is opened Active and its balance is entered.',
        'fields' => select_field('savings_txn_code', 'Savings transaction code (for the journal entry)', $codes, $o['savings_txn_code'], ['class' => 'col-md-7', 'blank' => $codes ? '' : 'No deposit code with a GL account: add one on Savings Transaction Codes', 'extra' => 'data-no-combo'])
            . input_field('cut_off_date', 'Savings cut-off date', $o['cut_off_date'], ['class' => 'col-md-5', 'type' => 'date'])
            . '<div class="col-12 form-text mt-0">Both fill the cells left blank in the file. The code\'s GL account takes the other side of the journal entry (for a migration, normally Opening Balance Equity).</div>',
        'intro' => 'The savings and share capital accounts of the former system, one row per account: each account is opened <strong>Active</strong> and its balance is entered on the cut-off date with the savings transaction code chosen. Import the members first.',
        'help' => 'Amounts as 12500.00 or 12,500.00. One voucher per cut-off date and code.',
    ];
    $state = CsvReview::handle($cfg);
    coop_layout('Import savings', ['actions' => btn_link(url($pageKey), 'Savings Account Center', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/csv_review.js']]);
    echo CsvReview::render($cfg, $state);
    layout_end();
    exit;
}

handle_post(function () use ($pageKey, $acct) {
    $action = (string)p('action');
    if ($action === 'open') {
        Access::require($pageKey, 'create');
        $memberId = (int)pint('member_id');
        if (!$memberId) {
            fail('Choose the member.');
        }
        $id = Savings::open($memberId, (int)pint('product_id'), pint('branch_id'), (string)p('opened_on'), trim((string)p('passbook_no')) ?: null, $pageKey, pint('passbook_type_id'));
        $new = Savings::account($id);
        flash('success', 'Account ' . $new['account_no'] . ' opened' . ($new['status'] === 'pending' ? ' and waiting for approval' : '') . '. Make the opening deposit on Savings Account Transaction.');
        redirect(Access::can('sav.transact', 'create') && p('then') === 'deposit' ? url('sav.transact', ['account' => $new['account_no']]) : url($pageKey, ['id' => $id]));
    }
    // the actions of the list (toolbar and right-click menu) name the account in the form
    $back = p('back') !== '' && str_starts_with((string)p('back'), url($pageKey)) ? (string)p('back') : null;
    if (!$acct && pint('account_id')) {
        $acct = Savings::account((int)pint('account_id'));
        if ($acct && !Coop::branchAllowed($acct['branch_id'] ? (int)$acct['branch_id'] : null)) {
            $acct = null;
        }
    }
    if (!$acct) {
        fail('Account not found.');
    }
    $reason = trim((string)p('reason'));
    switch ($action) {
        case 'approve':
            Access::require($pageKey, 'approve');
            Savings::approve((int)$acct['id'], $pageKey);
            flash('success', 'Account approved.');
            break;
        case 'restrict':
        case 'unrestrict':
            Access::require($pageKey, 'edit');
            Savings::restrict((int)$acct['id'], $action === 'restrict', $reason, $pageKey);
            flash('success', $action === 'restrict' ? 'Withdrawals are restricted.' : 'The restriction was lifted.');
            break;
        case 'close':
            // a balance left on the account is paid out first by the closing transaction code, in the Savings
            // Transaction Center, then the account is closed
            Access::require($pageKey, 'approve');
            coop_once();
            $classId = Dec::isPos($acct['balance']) || Dec::isPos(Dec::of(p('closing_fee') ?: '0')) ? Classes::required('class_id', 'the closing transaction') : null;
            $r = CoopLedger::withClass($classId, fn() => SavTxn::closeAccount((int)$acct['id'], $reason, (string)Coop::amount('closing_fee', false), 'sav.transact'));
            flash('success', 'Account ' . $acct['account_no'] . ' closed.' . ($r ? ' The balance of ' . Dec::money($r['amount']) . ' was paid out as transaction ' . $r['trans_no'] . '.' : ''));
            break;
        case 'delete_account':
            Access::require($pageKey, 'delete');
            SavTxn::deleteAccount((int)$acct['id'], $reason, $pageKey);
            flash('success', 'Account ' . $acct['account_no'] . ' was deleted.');
            redirect($back ?? url($pageKey));
        case 'notes':
            Access::require($pageKey, 'edit');
            $notes = mb_substr(trim((string)p('notes')), 0, 255);
            DB::update('sav_accounts', ['notes' => $notes !== '' ? $notes : null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $acct['id']]);
            Audit::log('edit', 'sav', 'sav_account', (int)$acct['id'], $acct['account_no'], ['page_key' => $pageKey, 'changes' => [['field' => 'notes', 'old' => (string)$acct['notes'], 'new' => $notes]]]);
            flash('success', 'Notes of ' . $acct['account_no'] . ' saved.');
            break;
        case 'pb_reset':
            // a misprint, or a new passbook given to the member: where the next line prints, and what prints again
            Access::require('sav.passbook', 'edit');
            $pb = Passbook::open('savings', $acct['account_no']);
            if (!$pb || !$pb['type']) {
                fail('Account ' . $acct['account_no'] . ' has no passbook type.');
            }
            $fromRow = null;
            if (p('again_from') !== '' && valid_date((string)p('again_from'))) {
                $fromRow = (int)DB::value('SELECT id FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_date >= :d ORDER BY txn_date, id LIMIT 1', ['a' => $acct['id'], 'd' => p('again_from')]) ?: null;
                if (!$fromRow) {
                    fail('No transaction of the account on or after ' . fdate((string)p('again_from')) . '.');
                }
            }
            $newBook = p('pb_mode') === 'new';
            if ($newBook && trim((string)p('new_passbook_no')) !== '') {
                DB::update('sav_accounts', ['passbook_no' => mb_substr(trim((string)p('new_passbook_no')), 0, 20), 'pb_issued_count' => (int)$acct['pb_issued_count'] + 1], 'id = :id', ['id' => $acct['id']]);
            }
            $page = $newBook ? 1 : max(1, (int)p('pb_page'));
            $line = $newBook ? 1 : max(1, (int)p('pb_line'));
            $n = Passbook::resetLine($pb, $page, $line, $fromRow, $reason, 'sav.passbook');
            flash('success', 'Passbook of ' . $acct['account_no'] . ': the next line prints on page ' . $page . ', line ' . $line . '.' . ($n ? ' ' . $n . ' transaction(s) will be printed again.' : ''));
            break;
        case 'reopen':
            Access::require($pageKey, 'approve');
            Savings::reopen((int)$acct['id'], $reason, $pageKey);
            flash('success', 'Account reopened.');
            break;
        case 'hold':
            Access::require($pageKey, 'approve');
            $amt = Coop::amount('hold_amount', true);
            Savings::placeHold((int)$acct['id'], $amt, $reason ?: 'Hold', null, $pageKey);
            $acct = Savings::account((int)$acct['id']);
            flash('success', Dec::money($amt) . ' of ' . $acct['account_no'] . ' is on hold: it cannot be withdrawn until it is released (Unhold). Withdrawable now ' . Dec::money(Savings::available($acct)) . '.');
            break;
        case 'release_hold':
            Access::require($pageKey, 'approve');
            if (p('hold_id') === 'all') {
                $ids = array_map('intval', DB::column('SELECT id FROM sav_holds WHERE account_id = :a AND released_at IS NULL', ['a' => $acct['id']]));
            } else {
                $ids = array_map('intval', DB::column('SELECT id FROM sav_holds WHERE id = :h AND account_id = :a AND released_at IS NULL', ['h' => pint('hold_id'), 'a' => $acct['id']]));
            }
            if (!$ids) {
                fail('Choose the hold to release.');
            }
            $amt = Dec::of(DB::value('SELECT COALESCE(SUM(amount),0) FROM sav_holds WHERE id IN (' . implode(',', $ids) . ')'));
            DB::tx(function () use ($ids, $reason, $pageKey) {
                foreach ($ids as $hid) {
                    Savings::releaseHold($hid, $reason, $pageKey);
                }
            });
            $acct = Savings::account((int)$acct['id']);
            flash('success', 'Unhold: ' . Dec::money($amt) . ' of ' . $acct['account_no'] . ' released. Withdrawable now ' . Dec::money(Savings::available($acct)) . '.');
            break;
        case 'passbook':
            Access::require($pageKey, 'edit');
            DB::update('sav_accounts', ['passbook_no' => mb_substr(trim((string)p('passbook_no')), 0, 20) ?: null, 'passbook_type_id' => pint('passbook_type_id')], 'id = :id', ['id' => $acct['id']]);
            Audit::log('edit', 'sav', 'sav_account', (int)$acct['id'], $acct['account_no'], ['page_key' => $pageKey, 'changes' => [['field' => 'passbook_no', 'old' => $acct['passbook_no'], 'new' => p('passbook_no')]]]);
            flash('success', 'Passbook details saved.');
            break;
        default:
            fail('Unknown action.');
    }
    redirect($back ?? url($pageKey, ['id' => $acct['id']]));
});

// ------------------------------------------------------------------------------- open an account
if ($a === 'open') {
    Access::require($pageKey, 'create');
    $m = coop_member_param();
    coop_layout('Open a savings / share capital account', ['actions' => btn_link(url($pageKey), 'Savings Account Center', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" class="card" style="max-width:860px" data-once><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="open">
      <?= member_pick('member_id', $m ? (int)$m['id'] : (pint('member_id') ?: null), ['class' => 'col-md-7', 'required' => true, 'autofocus' => !$m]) ?>
      <?= select_field('product_id', 'Product', Savings::productOptions(['ordinary', 'special', 'share_capital']), fv('product_id', null), ['class' => 'col-md-5', 'required' => true, 'blank' => '']) ?>
      <?= select_field('branch_id', 'Branch', Coop::branchOptions(), fv('branch_id', null, $m['branch_id'] ?? Coop::defaultBranchId()), ['class' => 'col-md-4']) ?>
      <?= input_field('opened_on', 'Opening date', fv('opened_on', null, today()), ['class' => 'col-6 col-md-3', 'type' => 'date', 'required' => true]) ?>
      <?= input_field('passbook_no', 'Passbook no.', fv('passbook_no', null, $m['passbook_no'] ?? ''), ['class' => 'col-6 col-md-2']) ?>
      <?= select_field('passbook_type_id', 'Passbook type', DB::pairs("SELECT id, name FROM passbook_types WHERE is_active = 1 AND ledger = 'savings' ORDER BY name"), fv('passbook_type_id', null), ['class' => 'col-md-3', 'blank' => 'As the product']) ?>
      <div class="col-12 small text-muted">The account number is made from the format on Cooperative Profile &amp; Policies. Time deposits are opened by placing them on the Time Deposit Center.
        <?= Access::can($pageKey, 'approve') ? '' : ' The account waits for the approval of a supervisor (right-click it on the Savings Account Center: Approve Account) before withdrawals; deposits are accepted at once.' ?></div>
      <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary btn-sm" name="then" value="view"><i class="bi bi-check-lg"></i> Open account</button>
        <?php if (Access::can('sav.transact', 'create')): ?><button class="btn btn-outline-primary btn-sm" name="then" value="deposit"><i class="bi bi-wallet2"></i> Open and make the opening deposit</button><?php endif; ?></div>
    </div></form>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------------------- one account
if ($acct) {
    $from = gdate('from', '');
    $to = gdate('to', today());
    $txns = DB::all('SELECT t.*, u.username FROM sav_txns t LEFT JOIN users u ON u.id = t.teller_id WHERE t.account_id = :a' . ($from ? ' AND t.txn_date >= :f' : '') . ' AND t.txn_date <= :t ORDER BY t.txn_date, t.id',
        ['a' => $acct['id'], 't' => $to] + ($from ? ['f' => $from] : []));
    $holds = DB::all('SELECT h.*, l.loan_no FROM sav_holds h LEFT JOIN loan_accounts l ON l.id = h.loan_account_id WHERE h.account_id = :a ORDER BY h.released_at IS NULL DESC, h.id DESC', ['a' => $acct['id']]);
    $m = Coop::member((int)$acct['member_id']);
    $ytdInt = Dec::of(DB::value("SELECT COALESCE(SUM(credit),0) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_code = 'INT' AND txn_date >= :y", ['a' => $acct['id'], 'y' => date('Y-01-01')]));
    $td = $acct['product_type'] === 'time_deposit' ? DB::all('SELECT * FROM td_placements WHERE account_id = :a ORDER BY id', ['a' => $acct['id']]) : [];
    $btns = [];
    if ($acct['status'] !== 'closed' && $acct['product_type'] !== 'time_deposit' && Access::can('sav.transact', 'create')) {
        $btns[] = btn_link(url('sav.transact', ['account' => $acct['account_no']]), 'Savings transaction', 'btn-primary', 'wallet2');
    }
    if (Access::page('sav.passbook') && $acct['passbook_type_id']) {
        $btns[] = btn_link(url('sav.passbook', ['account' => $acct['account_no']]), 'Passbook', 'btn-outline-secondary', 'journal');
    }
    $btns[] = btn_link(current_url(['print' => 1]), 'Print ledger', 'btn-outline-secondary', 'printer');
    $btns[] = btn_link(url($pageKey), 'Account Center', 'btn-outline-secondary', 'arrow-left');
    $print = g('print') === '1';
    if ($print) {
        layout_start('Ledger ' . $acct['account_no'], ['bare' => true]);
        echo '<div class="doc coop-doc">' . coop_print_head('ACCOUNT LEDGER', $acct['account_no'], today()) . '<div class="my-2"><strong>' . h($acct['member_name']) . '</strong>, ' . h($acct['member_no']) . ', ' . h($acct['product_name']) . '</div>';
    } else {
        coop_layout($acct['account_no'] . ' ' . $acct['product_name'], ['actions' => implode(' ', $btns)]);
        echo '<div class="mb-3">' . member_card($m) . '</div>';
    }
    $avail = Savings::available($acct);
    ?>
    <?php if (!$print): ?>
    <div class="coop-tiles">
      <div class="coop-tile"><div class="t-label">Ledger balance</div><div class="t-value"><?= cm($acct['balance']) ?></div><div class="t-sub"><?= coop_status($acct['status']) ?><?= (int)$acct['is_restricted'] ? ', <span class="text-danger">restricted</span>' : '' ?></div></div>
      <div class="coop-tile is-ok"><div class="t-label">Available</div><div class="t-value"><?= cm($avail) ?></div><div class="t-sub">after holds and checks clearing</div></div>
      <div class="coop-tile is-warn"><div class="t-label">Checks clearing</div><div class="t-value"><?= cm($acct['uncleared']) ?></div></div>
      <div class="coop-tile is-warn"><div class="t-label">On hold</div><div class="t-value"><?= cm($acct['hold_amount']) ?></div></div>
      <div class="coop-tile"><div class="t-label">Interest this year</div><div class="t-value"><?= cm($ytdInt) ?></div><div class="t-sub">since opening <?= cm($acct['interest_earned']) ?></div></div>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-lg-8"><div class="card"><div class="card-body"><?= coop_kv(['Account no.' => h($acct['account_no']), 'Product' => h($acct['product_name']), 'Branch' => h(Coop::branchName($acct['branch_id'] ? (int)$acct['branch_id'] : null)),
          'Opened' => h(fdate($acct['opened_on'])), 'Approved' => $acct['approved_at'] ? h(fdatetime($acct['approved_at'])) : 'not yet', 'Last transaction' => h(fdate($acct['last_txn_date'])),
          'Dormant since' => $acct['dormant_since'] ? h(fdate($acct['dormant_since'])) : '', 'Closed' => $acct['closed_on'] ? h(fdate($acct['closed_on'])) . ': ' . h((string)$acct['close_reason']) : '',
          'Restriction' => (int)$acct['is_restricted'] ? h((string)$acct['restrict_reason']) : '', 'Passbook' => h(trim(implode(', ', array_filter([(string)($acct['passbook_no'] ?? ''), $acct['pb_page'] && (int)$acct['pb_last_line'] > 0 ? 'printed to page ' . $acct['pb_page'] . ', line ' . $acct['pb_last_line'] : ''])))) ?: '<span class="text-muted">none yet</span>']) ?></div></div></div>
      <div class="col-lg-4"><div class="card"><div class="card-header">Actions</div><div class="card-body d-flex flex-wrap gap-2">
        <?php if ($acct['status'] === 'pending' && Access::can($pageKey, 'approve')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><button class="btn btn-sm btn-success"><i class="bi bi-check2-circle"></i> Approve the account</button></form><?php endif; ?>
        <?php if ($acct['status'] !== 'closed' && Access::can($pageKey, 'edit')): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mRestrict"><?= (int)$acct['is_restricted'] ? 'Lift the restriction' : 'Restrict withdrawals' ?></button><?php endif; ?>
        <?php if ($acct['status'] !== 'closed' && Access::can($pageKey, 'approve')): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mHold"><i class="bi bi-lock"></i> Hold</button>
          <?php if ($acct['product_type'] !== 'time_deposit'): ?><button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mClose">Close</button><?php endif; ?><?php endif; ?>
        <?php if ($acct['status'] === 'closed' && Access::can($pageKey, 'approve')): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mReopen">Reopen</button><?php endif; ?>
        <?php if (Access::can($pageKey, 'edit')): ?><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mPassbook">Passbook details</button><?php endif; ?>
      </div></div></div>
    </div>
    <?php if ($holds): ?><div class="card mb-3"><div class="card-header">Holds (Hold / Unhold)</div><table class="table table-sm"><tbody>
      <?php foreach ($holds as $hh): ?><tr class="<?= $hh['released_at'] ? 'text-muted' : '' ?>"><td><?= h(fdatetime($hh['placed_at'])) ?></td><td><?= h((string)$hh['reason']) ?><?= $hh['loan_no'] ? ' (loan ' . h($hh['loan_no']) . ')' : '' ?></td><td class="num"><?= cm($hh['amount']) ?></td>
        <td><?= $hh['released_at'] ? 'released ' . h(fdate($hh['released_at'])) : (Access::can($pageKey, 'approve') ? '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="action" value="release_hold"><input type="hidden" name="hold_id" value="' . (int)$hh['id'] . '"><button class="btn btn-link btn-sm p-0" data-confirm="Release the hold of ' . h(Dec::money($hh['amount'])) . '? It can be withdrawn again." data-confirm-ok="Unhold">Unhold</button></form>' : 'active') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <?php foreach ($td as $p): ?><div class="alert alert-light">Time deposit certificate <strong><?= h($p['certificate_no']) ?></strong>: <?= cm($p['principal']) ?> at <?= h(Dec::fix($p['rate'], 2)) ?> %, <?= h(fdate($p['placement_date'])) ?> to <?= h(fdate($p['maturity_date'])) ?>, <?= coop_status($p['status']) ?>. <?= safe_link('sav.td', ['id' => $p['id']], 'Open the placement') ?></div><?php endforeach; ?>
    <form class="row g-2 align-items-end filters mb-2 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="id" value="<?= (int)$acct['id'] ?>">
      <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?><?= input_field('to', 'To', $to, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Show</button></div></form>
    <?php endif; ?>
    <div class="card"><table class="table table-sm table-hover"><thead><tr><th>Date</th><th>Code</th><th>Particulars</th><th>Voucher / OR</th><th class="num">Withdrawal</th><th class="num">Deposit</th><th class="num">Balance</th><th>By</th></tr></thead><tbody>
    <?php foreach ($txns as $t): $v = (int)$t['is_voided'] === 1; ?>
      <tr class="<?= $v ? 'text-muted text-decoration-line-through' : '' ?>"><td><?= h(fdate($t['txn_date'])) ?></td><td><?= h($t['txn_code']) ?></td>
        <td><?= h((string)$t['description']) ?><?= (int)$t['is_cleared'] === 0 && !$v ? ' <span class="small text-warning">clears ' . h(fdate($t['clearing_date'])) . '</span>' : '' ?><?= $v ? ' (void)' : '' ?></td>
        <td class="small"><?= $t['txn_id'] ? Txn::link((int)$t['txn_id'], $t['or_no'] ?: null) : '' ?></td><td class="num"><?= cm($t['debit'], true) ?></td><td class="num"><?= cm($t['credit'], true) ?></td>
        <td class="num"><?= $v ? '' : cm($t['balance_after']) ?></td><td class="small"><?= h((string)$t['username']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$txns): ?><tr><td colspan="8" class="text-muted">No transaction in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php
    if ($print) {
        echo '</div><script>window.addEventListener("load", function () { window.print(); });</script>';
        layout_end();
        exit;
    }
    $modal = function (string $id, string $title, string $action, string $body, bool $reasonRequired = true) {
        return '<div class="modal fade" id="' . $id . '" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-once>' . csrf_field()
            . '<input type="hidden" name="action" value="' . h($action) . '"><div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
            . '<div class="modal-body">' . $body . '<label class="form-label mt-2">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2"' . ($reasonRequired ? ' required' : '') . '></textarea></div>'
            . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">' . h($title) . '</button></div></form></div></div>';
    };
    echo $modal('mRestrict', (int)$acct['is_restricted'] ? 'Lift the restriction' : 'Restrict withdrawals', (int)$acct['is_restricted'] ? 'unrestrict' : 'restrict', '', !(int)$acct['is_restricted']);
    echo $modal('mHold', 'Hold', 'hold', '<p class="small text-muted mb-2">An amount of the balance earmarked: it cannot be withdrawn until it is released (Unhold). The rest of the balance stays withdrawable. Withdrawable now <strong>' . cm(Savings::available($acct)) . '</strong>.</p><label class="form-label">Amount to hold</label><input class="form-control form-control-sm text-end" name="hold_amount" inputmode="decimal" data-money required>');
    echo str_replace('<input type="hidden" name="action" value="close">', '<input type="hidden" name="action" value="close">' . Coop::onceField(), $modal('mClose', 'Close the account', 'close',
        '<p class="small mb-2">' . (Dec::isPos($acct['balance']) ? 'The balance of <strong>' . cm($acct['balance']) . '</strong> is paid out first with the closing code ' . h(Coop::setting('sav_closing_code', 'CSW')) . ' (a transaction of the Savings Transaction Center, from your teller drawer). ' : '')
        . 'The account keeps its ledger and can be reopened later.</p><div class="row g-2">' . coop_money('closing_fee', 'Closing fee (optional)', '', ['class' => 'col-6']) . Classes::requiredField(fv('class_id', null), 'col-6') . '</div>' . Approval::fields()));
    echo $modal('mReopen', 'Reopen the account', 'reopen', '');
    echo '<div class="modal fade" id="mPassbook" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">' . csrf_field() . '<input type="hidden" name="action" value="passbook">'
        . '<div class="modal-header"><h5 class="modal-title">Passbook details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body row g-2">'
        . input_field('passbook_no', 'Passbook no.', (string)$acct['passbook_no'], ['class' => 'col-6'])
        . select_field('passbook_type_id', 'Passbook type', DB::pairs("SELECT id, name FROM passbook_types WHERE is_active = 1 AND ledger = 'savings' ORDER BY name"), $acct['passbook_type_id'], ['class' => 'col-6', 'blank' => 'None'])
        . '</div><div class="modal-footer"><button class="btn btn-primary">Save</button></div></form></div></div>';
    layout_end();
    exit;
}

// ------------------------------------------------------------------------------- the list (Savings Account Center)
$q = trim((string)g('q'));
$product = gint('product');
$branch = gint('branch');
$status = in_array(g('status'), ['pending', 'active', 'dormant', 'closed', 'all'], true) ? (string)g('status') : '';
[$bf, $bp] = Coop::branchFilter('a.branch_id');
$where = "p.product_type <> 'time_deposit'" . $bf;
$p = $bp;
if ($q !== '') {
    // every word of the member's name, or the start of the account no., or the member / passbook no.
    $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', $q) ?: [], fn($w) => $w !== '')), 0, 5);
    $cond = [];
    foreach ($words as $i => $w) {
        $cond[] = "m.full_name LIKE :w$i";
        $p["w$i"] = '%' . $w . '%';
    }
    $where .= ' AND ((' . implode(' AND ', $cond) . ') OR a.account_no LIKE :q1 OR m.member_no = :q3 OR a.passbook_no = :q4)';
    $p += ['q1' => $q . '%', 'q3' => $q, 'q4' => $q];
}
if ($product) {
    $where .= ' AND a.product_id = :pr';
    $p['pr'] = $product;
}
if ($branch) {
    $where .= ' AND a.branch_id = :br';
    $p['br'] = $branch;
}
if ($status === 'all') {
    // every status
} elseif ($status !== '') {
    $where .= ' AND a.status = :st';
    $p['st'] = $status;
} else {
    $where .= " AND a.status <> 'closed'";
}
$limit = 1000;
$rows = DB::all("SELECT a.*, p.code AS product_code, p.name AS product_name, p.product_type, p.passbook_type_id AS product_pb_type, m.full_name, m.member_no,
                        (SELECT COUNT(*) FROM sav_txns x WHERE x.account_id = a.id) AS txns,
                        (SELECT COUNT(*) FROM sav_txns y WHERE y.account_id = a.id AND y.pb_printed = 0 AND y.is_voided = 0) AS unprinted
                   FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                  WHERE $where ORDER BY m.full_name, a.account_no LIMIT $limit", $p);
// the line the next entry of each passbook prints on (the rules of its passbook type: line 1, the fold, Max Lines)
$pbTypeRows = [];
foreach ($rows as $i => $r) {
    $tid = (int)($r['passbook_type_id'] ?: $r['product_pb_type']);
    if ($tid && !array_key_exists($tid, $pbTypeRows)) {
        $pbTypeRows[$tid] = Passbook::type($tid);
    }
    $rows[$i]['pb_next_line'] = $tid && $pbTypeRows[$tid] ? Passbook::next(['account' => $r, 'type' => $pbTypeRows[$tid], 'ledger' => 'savings'])[1] : 1;
}
$tot = DB::row("SELECT COUNT(*) n, COALESCE(SUM(a.balance),0) b FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id WHERE $where", $p);
$words = ['pending' => 'FOR APPROVAL', 'active' => 'ACTIVE', 'dormant' => 'DORMANT', 'closed' => 'CLOSED'];

$grid = function () use ($rows, $tot, $limit, $words) { ?>
  <div class="sv-grid-wrap"><table class="table sv-grid" data-select-grid="#saBar" data-context-menu="#saMenu" aria-label="Savings accounts"><thead><tr>
    <th>Account no</th><th class="d-none d-md-table-cell">Passbook no</th><th>Member name</th><th class="d-none d-md-table-cell">Savings Code</th><th class="d-none d-sm-table-cell">Savings Name</th><th class="num">Current Balance</th><th class="d-none d-sm-table-cell">Status</th><th class="text-end" aria-label="Actions"></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $pbType = (int)($r['passbook_type_id'] ?: $r['product_pb_type']); ?>
    <tr data-row tabindex="0" data-id="<?= (int)$r['id'] ?>" data-no="<?= h($r['account_no']) ?>" data-status="<?= h($r['status']) ?>" data-txns="<?= (int)$r['txns'] > 0 ? 'yes' : 'no' ?>"
        data-pb="<?= $pbType ? 'yes' : 'no' ?>" data-unprinted="<?= (int)$r['unprinted'] > 0 ? 'yes' : 'no' ?>" data-restricted="<?= (int)$r['is_restricted'] ? 'yes' : 'no' ?>"
        data-notes="<?= h((string)$r['notes']) ?>" data-balance="<?= h(Dec::money($r['balance'])) ?>" data-held="<?= Dec::isPos($r['hold_amount']) ? 'yes' : 'no' ?>" data-hold="<?= h(Dec::money($r['hold_amount'])) ?>" data-avail="<?= h(Dec::money(Savings::available($r))) ?>" data-page="<?= max(1, (int)$r['pb_page']) ?>" data-line="<?= max(1, (int)($r['pb_next_line'] ?? 1)) ?>"
        data-title="<?= h($r['account_no'] . '  ' . $r['full_name'] . '  ' . $r['product_name']) ?>">
      <td class="fw-semibold"><?= h($r['account_no']) ?><div class="d-sm-none"><span class="sv-status is-<?= h($r['status']) ?>"><?= h($words[$r['status']] ?? strtoupper($r['status'])) ?></span><?= (int)$r['is_restricted'] ? ' <span class="small text-danger fw-normal">restricted</span>' : '' ?></div></td><td class="d-none d-md-table-cell"><?= h((string)$r['passbook_no']) ?></td><td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted d-sm-none"><?= h($r['product_name']) ?></div></td>
      <td class="d-none d-md-table-cell"><?= h($r['product_code']) ?></td><td class="d-none d-sm-table-cell"><?= h($r['product_name']) ?></td><td class="num"><?= cm($r['balance']) ?><?= Dec::isPos($r['hold_amount']) ? '<div class="sv-held"><i class="bi bi-lock-fill" aria-hidden="true"></i> ' . cm($r['hold_amount']) . ' on hold</div>' : '' ?></td>
      <td class="d-none d-sm-table-cell"><span class="sv-status is-<?= h($r['status']) ?>"><?= h($words[$r['status']] ?? strtoupper($r['status'])) ?></span><?= (int)$r['is_restricted'] ? ' <span class="small text-danger">restricted</span>' : '' ?></td>
      <td class="text-end"><button type="button" class="sv-more" data-row-menu aria-label="Actions for <?= h($r['account_no']) ?>" title="Actions (or right-click the row)"><i class="bi bi-three-dots-vertical"></i></button></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-5">No account matches.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="sv-grid-foot"><span><?= number_format((int)$tot['n']) ?> account(s)<?= (int)$tot['n'] > $limit ? ', the first ' . number_format($limit) . ' shown: type more of the name' : '' ?></span><span>Total balance <strong><?= cm($tot['b']) ?></strong></span></div>
<?php };

if (g('partial') === '1') {
    $grid();
    exit;
}
$pending = (int)DB::value("SELECT COUNT(*) FROM sav_accounts a WHERE a.status = 'pending'" . $bf, $bp);
$filterOn = $product || $branch || $status !== '';
$back = current_url();
$canPb = Access::can('sav.passbook', 'print');
coop_layout('Savings Account Center', ['js' => ['js/savings.js']]);
?>
<?php if ($pending): ?><div class="alert alert-warning py-2 d-flex flex-wrap justify-content-between gap-2"><span><?= $pending ?> account(s) waiting for approval.</span><a href="<?= h(url($pageKey, ['status' => 'pending'])) ?>">Show them</a></div><?php endif; ?>
<form class="sv-toolbar" id="saBar" role="search" data-live-search data-live-target="#saRows" action="<?= h(url($pageKey)) ?>">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="sv-tools" role="toolbar" aria-label="Savings accounts">
    <?php if (Access::can($pageKey, 'create')): ?><a class="sv-tool is-primary" href="<?= h(url($pageKey, ['a' => 'open'])) ?>"><i class="bi bi-plus-lg"></i> New</a><?php endif; ?>
    <button type="button" class="sv-tool" data-need-row data-grid-default data-href="<?= h(url($pageKey)) ?>&id={id}" title="The account, its ledger and its details"><i class="bi bi-pencil-square"></i> Edit</button>
    <?php if (Access::can($pageKey, 'approve')): ?><button type="button" class="sv-tool" data-need-row data-when="status:active,dormant,pending" data-modal="#mCloseRow" title="Close the account (the balance is paid out first)"><i class="bi bi-folder-x"></i> Close</button><?php endif; ?>
    <?php if (Access::can($pageKey, 'edit')): ?><button type="button" class="sv-tool" data-need-row data-when="status:active,dormant,pending" data-modal="#mRestrictRow" title="Restrict or allow withdrawals"><i class="bi bi-x-octagon"></i> Restrict</button><?php endif; ?>
    <?php if (Access::can($pageKey, 'approve')): ?><button type="button" class="sv-tool" data-need-row data-when="status:active,dormant,pending" data-modal="#mHoldRow" title="Earmark an amount of the balance: it cannot be withdrawn"><i class="bi bi-lock"></i> Hold</button>
      <button type="button" class="sv-tool" data-need-row data-when="held:yes" data-modal="#mUnholdRow" title="Release an amount on hold"><i class="bi bi-unlock"></i> Unhold</button><?php endif; ?>
    <?php if (Access::can($pageKey, 'edit')): ?>
      <button type="button" class="sv-tool" data-need-row data-modal="#mNotesRow" title="Notes of the account"><i class="bi bi-card-text"></i> Notes</button><?php endif; ?>
    <button type="button" class="sv-tool <?= $filterOn ? 'is-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#saFilter" aria-expanded="<?= $filterOn ? 'true' : 'false' ?>" aria-controls="saFilter"><i class="bi bi-funnel"></i> Filter</button>
    <a class="sv-tool" href="<?= h(current_url()) ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
    <?php if (Access::can($pageKey, 'create')): ?><a class="sv-tool" href="<?= h(url($pageKey, ['a' => 'import'])) ?>" title="Open savings and share capital accounts with their balances from a CSV file (migration)"><i class="bi bi-filetype-csv"></i> Import Savings</a><?php endif; ?>
  </div>
  <div class="sv-tool-end">
    <div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Search: name, account no., member or passbook no." autocomplete="off" autofocus aria-label="Search"></div>
  </div>
  <div class="collapse w-100 <?= $filterOn ? 'show' : '' ?>" id="saFilter"><div class="row g-2 pt-2 px-1">
    <?= select_field('product', 'Savings product', Savings::productOptions(['ordinary', 'special', 'share_capital'], false), $product, ['class' => 'col-6 col-md-4', 'blank' => 'All products', 'extra' => 'data-no-combo']) ?>
    <?= select_field('status', 'Status', ['pending' => 'For approval', 'active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed', 'all' => 'Every status'], $status, ['class' => 'col-6 col-md-4', 'blank' => 'Open accounts', 'extra' => 'data-no-combo']) ?>
    <?= select_field('branch', 'Branch', Coop::branchOptions(), $branch, ['class' => 'col-12 col-md-4', 'blank' => 'All branches', 'extra' => 'data-no-combo']) ?>
  </div></div>
</form>
<div id="saRows"><?php $grid(); ?></div>
<p class="small text-muted mt-2 mb-0">Click an account and use the toolbar, double-click it to open it, or right-click it (on a phone: the <i class="bi bi-three-dots-vertical"></i> button or a long press) for the passbook and account actions.</p>

<?php $canApprove = Access::can($pageKey, 'approve'); $canPbEdit = Access::can('sav.passbook', 'edit'); ?>
<div class="sv-menu" id="saMenu" role="menu" hidden>
  <div class="sv-menu-title" data-menu-title></div>
  <?php if ($canApprove): ?>
  <button type="button" role="menuitem" data-action data-when="status:pending" data-modal="#mApproveRow"><i class="bi bi-check2-circle"></i> Approve Account</button>
  <button type="button" role="menuitem" data-action data-when="status:closed" data-modal="#mReopenRow"><i class="bi bi-folder-symlink"></i> Re-Open Account</button>
  <hr>
  <?php endif; ?>
  <?php if ($canPbEdit): ?><button type="button" role="menuitem" data-action data-when="pb:yes;!status:closed" data-modal="#mResetRow"><i class="bi bi-arrow-counterclockwise"></i> Reset Passbook Print Line</button><?php endif; ?>
  <?php if ($canPb): ?>
  <button type="button" role="menuitem" data-action data-when="pb:yes" data-target="_blank" data-href="<?= h(url('sav.passbook', ['a' => 'print', 'cover' => 1])) ?>&account={no}"><i class="bi bi-person-badge"></i> Print Passbook Cover</button>
  <button type="button" role="menuitem" data-action data-when="pb:yes;txns:yes" data-modal="#mReprintRow"><i class="bi bi-printer"></i> Reprint Passbook</button>
  <button type="button" role="menuitem" data-action data-when="pb:yes" data-target="_blank" data-href="<?= h(url('sav.passbook', ['a' => 'footer'])) ?>&account={no}"><i class="bi bi-layout-text-window-reverse"></i> Print Passbook Footer</button>
  <button type="button" role="menuitem" data-action data-when="pb:yes;unprinted:yes" data-href="<?= h(url('sav.passbook')) ?>&account={no}"><i class="bi bi-journal-arrow-down"></i> Update Passbook</button>
  <?php endif; ?>
  <?php if ($canApprove || Access::can($pageKey, 'delete')): ?><hr><?php endif; ?>
  <?php if ($canApprove): ?><button type="button" role="menuitem" data-action data-when="status:active,dormant,pending" data-modal="#mHoldRow"><i class="bi bi-lock"></i> Hold</button>
  <button type="button" role="menuitem" data-action data-when="held:yes" data-modal="#mUnholdRow"><i class="bi bi-unlock"></i> Unhold</button>
  <button type="button" role="menuitem" data-action data-when="status:active,dormant,pending" data-modal="#mCloseRow"><i class="bi bi-folder-x"></i> Close Account</button><?php endif; ?>
  <?php if (Access::can($pageKey, 'delete')): ?><button type="button" role="menuitem" class="is-danger" data-action data-when="txns:no" data-modal="#mDeleteRow"><i class="bi bi-trash3"></i> Delete Account</button><?php endif; ?>
</div>

<?php
// the dialogs of the toolbar and the menu: each gets the account of the selected row
$dlg = function (string $id, string $title, string $action, string $body, string $btn, string $btnClass = 'btn-primary', bool $once = false) use ($pageKey, $back) {
    return '<div class="modal fade" id="' . h($id) . '" tabindex="-1" aria-labelledby="' . h($id) . 'T"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" data-once action="' . h(url($pageKey)) . '">'
        . csrf_field() . ($once ? Coop::onceField() : '') . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="account_id" data-row-field="id"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<div class="modal-header"><h5 class="modal-title" id="' . h($id) . 'T">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body"><div class="fw-semibold mb-2" data-row-field="title"></div>' . $body . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn ' . h($btnClass) . ' btn-sm">' . h($btn) . '</button></div></form></div></div>';
};
$reason = fn(bool $req = true, string $ph = '') => '<label class="form-label">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2"' . ($req ? ' required' : '') . ($ph ? ' placeholder="' . h($ph) . '"' : '') . '></textarea>';
if (Access::can($pageKey, 'approve')) {
    echo $dlg('mApproveRow', 'Approve the account', 'approve', '<p class="small mb-0">The newly opened account becomes active: withdrawals are allowed from now on.</p>', 'Approve', 'btn-success');
    echo $dlg('mReopenRow', 'Re-open the account', 'reopen', $reason(), 'Re-open');
    echo $dlg('mHoldRow', 'Hold an amount', 'hold', '<div class="sv-hold-tiles"><div><span>Balance</span><strong data-row-field="balance"></strong></div><div><span>On hold</span><strong data-row-field="hold"></strong></div><div><span>Withdrawable</span><strong data-row-field="avail"></strong></div></div>'
        . '<p class="small text-muted">The amount held is earmarked: it cannot be withdrawn until it is released with Unhold. Unlike Restrict, the rest of the balance stays withdrawable.</p>'
        . '<label class="form-label" for="holdAmt">Amount to hold</label><input class="form-control form-control-sm text-end mb-2" id="holdAmt" name="hold_amount" inputmode="decimal" data-money required>'
        . $reason(true, 'Collateral of a loan, court order, member\'s request ...'), 'Hold', 'btn-primary');
    echo $dlg('mUnholdRow', 'Unhold', 'release_hold', '<p class="small text-muted mb-2">Release an amount on hold: it can be withdrawn again.</p><div data-row-load="' . h(url($pageKey, ['a' => 'holds'])) . '&id={id}"></div>'
        . '<div class="mt-2">' . $reason(false, 'Loan paid, order lifted ...') . '</div>', 'Unhold', 'btn-primary');
    echo $dlg('mCloseRow', 'Close the account', 'close', '<p class="small">Balance <strong data-row-field="balance"></strong>. A balance left is paid out first with the closing code ' . h(Coop::setting('sav_closing_code', 'CSW'))
        . ' (a transaction of the Savings Transaction Center, from your teller drawer); then the account is closed. Checks still clearing and holds are settled first.</p><div class="row g-2">'
        . coop_money('closing_fee', 'Closing fee (optional, code ' . h(Coop::setting('sav_closing_fee_code', 'CF')) . ')', '', ['class' => 'col-sm-6']) . Classes::requiredField(null, 'col-sm-6') . '</div>' . $reason(true, 'Member\'s request, resignation ...') . Approval::fields(), 'Close the account', 'btn-danger', true);
}
if (Access::can($pageKey, 'edit')) {
    echo $dlg('mRestrictRow', 'Restrict withdrawals', 'restrict', '<div class="form-check mb-2"><input class="form-check-input" type="radio" name="action" value="restrict" id="rsOn" checked><label class="form-check-label" for="rsOn">Restrict withdrawals (deposits are still accepted)</label></div>'
        . '<div class="form-check mb-2"><input class="form-check-input" type="radio" name="action" value="unrestrict" id="rsOff"><label class="form-check-label" for="rsOff">Lift the restriction</label></div>' . $reason(false), 'Save');
    echo $dlg('mNotesRow', 'Notes of the account', 'notes', '<textarea class="form-control form-control-sm" name="notes" rows="4" maxlength="255" data-row-field="notes"></textarea><div class="small text-muted mt-1">Shown on the Savings Account Transaction form (Savings Notes).</div>', 'Save notes');
}
if (Access::can($pageKey, 'delete')) {
    echo $dlg('mDeleteRow', 'Delete the account', 'delete_account', '<p class="small">Only an account opened by mistake, with no transaction at all, is deleted. The deletion is kept in the audit trail.</p>' . $reason(), 'Delete', 'btn-danger');
}
if (Access::can('sav.passbook', 'edit')) {
    echo $dlg('mResetRow', 'Reset passbook print line', 'pb_reset',
        '<p class="small text-muted">After a passbook printing error, or when the member gets a new passbook. The next line prints where you say; the transactions from the date chosen are printed again.</p>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="pb_mode" value="continue" id="pbCont" checked><label class="form-check-label" for="pbCont">Continue in this passbook at</label></div>'
        . '<div class="row g-2 ms-3 mb-2"><div class="col-5 col-sm-4"><label class="form-label">Page</label><input class="form-control form-control-sm" type="number" min="1" step="1" name="pb_page" data-row-field="page"></div>'
        . '<div class="col-5 col-sm-4"><label class="form-label">Line</label><input class="form-control form-control-sm" type="number" min="1" step="1" name="pb_line" data-row-field="line"></div></div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="pb_mode" value="new" id="pbNew"><label class="form-check-label" for="pbNew">A new passbook (page 1, first line)</label></div>'
        . '<div class="row g-2 ms-3 mb-2"><div class="col-sm-8"><label class="form-label">New passbook no. (optional)</label><input class="form-control form-control-sm" name="new_passbook_no"></div></div>'
        . '<div class="row g-2"><div class="col-sm-8"><label class="form-label">Print again the transactions from (optional)</label><input class="form-control form-control-sm" type="date" name="again_from"></div></div>'
        . $reason(true, 'Paper jam on line 5, new passbook ...'), 'Reset the print line');
}
if ($canPb) { ?>
<div class="modal fade" id="mReprintRow" tabindex="-1" aria-labelledby="mReprintRowT"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="get" action="index.php" target="_blank">
  <input type="hidden" name="p" value="sav.passbook"><input type="hidden" name="a" value="reprint"><input type="hidden" name="account" data-row-field="no">
  <div class="modal-header"><h5 class="modal-title" id="mReprintRowT">Reprint passbook</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><div class="fw-semibold mb-2" data-row-field="title"></div>
    <p class="small text-muted">The transactions of the dates chosen print again on the page and line they were printed on (one page at a time). Nothing is marked; a reprint is logged.</p>
    <div class="row g-2"><?= input_field('from', 'From', date('Y-m-01'), ['type' => 'date', 'class' => 'col-6']) ?><?= input_field('to', 'To', today(), ['type' => 'date', 'class' => 'col-6']) ?></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm"><i class="bi bi-printer"></i> Reprint</button></div>
</form></div></div>
<?php }
layout_end();
