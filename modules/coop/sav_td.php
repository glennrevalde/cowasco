<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.td';
$td = gint('id') ? TimeDeposits::placement((int)gint('id')) : null;
if ($td && !Coop::branchAllowed($td['branch_id'] ? (int)$td['branch_id'] : null)) {
    Access::denyPage($pageKey, 'open a time deposit of another branch on');
}
$tdProducts = Savings::productOptions(['time_deposit']);

// ------------------------------------------------------------------ the certificate
if ($td && g('a') === 'certificate') {
    $copy = coop_print_gate('td_certificate', 'td', (int)$td['id'], $td['certificate_no'], $pageKey, 'time deposit certificate');
    if ($copy === null) {
        exit;
    }
    DB::run('UPDATE td_placements SET reprint_count = :n WHERE id = :id', ['n' => $copy - 1, 'id' => $td['id']]);
    $m = Coop::member((int)$td['member_id']);
    layout_start('Certificate ' . $td['certificate_no'], ['bare' => true]);
    echo '<div class="doc coop-doc"><div class="cert-frame">' . coop_copy_mark($copy) . coop_print_head('TIME DEPOSIT CERTIFICATE', $td['certificate_no'], $td['placement_date'])
        . ((int)DB::value("SELECT COUNT(*) FROM td_placements WHERE id = :id AND status = 'active'", ['id' => $td['id']]) ? '' : '<div class="void-mark" style="font-size:3rem">' . h(strtoupper(str_replace('_', ' ', $td['status']))) . '</div>')
        . '<div class="doc-clause mt-4">' . CoopDocs::render('td_certificate', $td['placement_date'], ['coop' => Coop::coopName(), 'member' => $td['member_name'], 'member_no' => $td['member_no'],
            'certificate_no' => $td['certificate_no'], 'principal' => Dec::money($td['principal']), 'principal_words' => amount_in_words((float)$td['principal']), 'rate' => Dec::fix($td['rate'], 2),
            'term' => $td['term_label'] ?: $td['term_days'] . ' days', 'placement_date' => fdate($td['placement_date']), 'maturity_date' => fdate($td['maturity_date']), 'maturity_value' => Dec::money($td['maturity_value']),
            'maturity_action' => TimeDeposits::ACTIONS[$td['maturity_action']] ?? $td['maturity_action']]) . '</div>'
        . '<div class="row text-center my-3"><div class="col"><div class="small text-muted">Principal</div><div class="cert-amount">' . cm($td['principal']) . '</div></div>'
        . '<div class="col"><div class="small text-muted">Maturity value on ' . h(fdate($td['maturity_date'])) . '</div><div class="cert-amount">' . cm($td['maturity_value']) . '</div></div></div>'
        . coop_signatures(['Treasurer' => Coop::setting('signatory_treasurer'), 'General Manager' => Coop::setting('signatory_gm')]) . '</div></div>';
    echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}

// ------------------------------------------------------------------ Import Time Deposit: a CSV file of placements, reviewed, then uploaded
if (g('a') === 'import') {
    Access::require($pageKey, 'create');
    $codes = CsvImports::importCodes();
    $defCode = isset($codes['INI']) ? 'INI' : (string)(array_key_first($codes) ?? '');
    $prodCodes = CsvImports::savProducts(['time_deposit']);
    $opt = fn() => ['savings_txn_code' => strtoupper(trim((string)p('savings_txn_code', $defCode))), 'product' => (string)p('product', (string)(array_key_first($prodCodes) ?? '')), 'post_date' => (string)(p('post_date') ?: today())];
    $o = is_post() ? $opt() : ['savings_txn_code' => $defCode, 'product' => (string)(array_key_first($prodCodes) ?? ''), 'post_date' => today()];
    $cfg = [
        'cols' => CsvImports::cols('time_deposits'), 'aliases' => CsvImports::aliases('time_deposits'), 'noun' => ['time deposit', 'time deposits'], 'total' => 'amount',
        'template_name' => 'import_time_deposits_template.csv', 'samples' => CsvImports::samples('time_deposits'), 'opt' => $opt,
        'check' => fn(array $rows, array $o) => CsvImports::check('time_deposits', $rows, $o),
        'post' => function (array $rows, array $o, string $file) use ($pageKey) {
            $r = CsvImports::post('time_deposits', $rows, $o, $file, $pageKey);
            return $r['count'] . ' time deposit(s) imported, Active, for ' . Dec::money($r['total']) . ': certificate ' . implode(', ', array_slice($r['refs'], 0, 6)) . (count($r['refs']) > 6 ? ' ...' : '') . '.';
        },
        'can_post' => Access::can($pageKey, 'approve'), 'no_post' => 'You may read and check the file; uploading it (time deposits made Active with their principal) needs the Approve right on the Time Deposit Center.',
        'done' => url($pageKey), 'confirm' => 'Upload the time deposits? Each one is placed Active and its principal is entered.',
        'fields' => select_field('savings_txn_code', 'Savings transaction code (for the journal entry)', $codes, $o['savings_txn_code'], ['class' => 'col-md-5', 'blank' => $codes ? '' : 'No deposit code with a GL account: add one on Savings Transaction Codes', 'extra' => 'data-no-combo'])
            . select_field('product', 'Time deposit product', $prodCodes, $o['product'], ['class' => 'col-md-4', 'extra' => 'data-no-combo'])
            . input_field('post_date', 'Posting date (cut-off)', $o['post_date'], ['class' => 'col-md-3', 'type' => 'date'])
            . '<div class="col-12 form-text mt-0">The code and the product fill the cells left blank in the file. Each time deposit keeps its own date opened and maturity date; its principal is entered on the posting date, against the code\'s GL account.</div>',
        'intro' => 'The time deposits of the former system, one row per placement: each is placed <strong>Active</strong> with its certificate, rate and maturity date, and its principal is entered with the savings transaction code chosen. Import the members first.',
        'help' => 'The term in days (180), months (6 months) or years (1 year). The rate comes from the product\'s rates for the amount and term, unless the rate column is filled.',
    ];
    $state = CsvReview::handle($cfg);
    coop_layout('Import time deposits', ['actions' => btn_link(url($pageKey), 'Time Deposit Center', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/csv_review.js']]);
    echo CsvReview::render($cfg, $state);
    layout_end();
    exit;
}

$accrualPreview = null;
handle_post(function () use ($pageKey, $td, &$accrualPreview) {
    $action = (string)p('action');
    if ($action === 'place') {
        Access::require($pageKey, 'create');
        coop_once();
        $memberId = (int)pint('member_id');
        if (!$memberId) {
            fail('Choose the member.');
        }
        $src = (string)p('source');
        $source = $src === 'savings' ? ['kind' => 'savings', 'from_account_id' => (int)pint('from_account_id')]
            : ['kind' => $src === 'check' ? 'check' : 'cash', 'cash_account_id' => (int)(Teller::requireShift()['cash_account_id'] ?? Teller::userCashAccount())];
        $opt = ['maturity_action' => array_key_exists(p('maturity_action'), TimeDeposits::ACTIONS) ? p('maturity_action') : 'payout', 'credit_account_id' => pint('credit_account_id'),
            'interest_method' => p('interest_method') === 'compound' ? 'compound' : 'simple', 'compounding' => in_array(p('compounding'), ['monthly', 'quarterly'], true) ? p('compounding') : 'maturity',
            'certificate_no' => trim((string)p('certificate_no')), 'shift_id' => Teller::openShift()['id'] ?? null, 'notes' => trim((string)p('notes')) ?: null,
            'term_label' => trim((string)p('term_label')) ?: null];
        if (trim((string)p('rate')) !== '') {
            // a rate other than the product tier is a special rate: it needs a supervisor
            Approval::check($pageKey, !Access::can($pageKey, 'approve'), 'special time deposit rate');
            $opt['rate'] = Dec::fix(Dec::of(p('rate')), 4);
        }
        if ($src === 'check') {
            $opt['cheque'] = [['cheque_no' => trim((string)p('cheque_no')), 'bank' => trim((string)p('bank')), 'date' => p('placement_date'), 'amount' => Dec::fix(Dec::of(p('principal')))]];
        }
        $classId = Classes::required('class_id', 'the time deposit');
        [$id] = CoopLedger::withClass($classId, fn() => TimeDeposits::place($memberId, (int)pint('product_id'), Coop::amount('principal', true), max(1, (int)p('term_days')), (string)p('placement_date'), $source, $opt, $pageKey));
        $no = (string)DB::value('SELECT s.trans_no FROM td_placements t JOIN sav_txns s ON s.account_id = t.account_id AND s.trans_no IS NOT NULL WHERE t.id = :p ORDER BY s.id LIMIT 1', ['p' => $id]);
        flash('success', 'Time deposit placed' . ($no !== '' ? ' (transaction ' . $no . ')' : '') . '. Print the certificate.');
        redirect(url($pageKey, ['id' => $id]));
    }
    if ($action === 'caiw') {
        // Withdraw Accrued: the accrued interest the member takes before maturity (code CAIW)
        Access::require($pageKey, 'create');
        coop_once();
        $pid = $td ? (int)$td['id'] : (int)pint('placement_id');
        $classId = Classes::required('class_id', 'the accrued interest withdrawal');
        $r = CoopLedger::withClass($classId, fn() => SavTxn::tdInterestWithdraw($pid, Coop::amount('withdraw_amount', true), today(), $pageKey));
        flash('success', 'Accrued interest withdrawn: transaction ' . $r['trans_no'] . ' (CAIW), shown on the Savings Transaction Center.');
        redirect($td ? url($pageKey, ['id' => $td['id']]) : url($pageKey));
    }
    if ($action === 'settle') {
        Access::require($pageKey, 'create');
        coop_once();
        if (!$td && pint('placement_id')) {
            $td = TimeDeposits::placement((int)pint('placement_id'));
            if (!$td || !Coop::branchAllowed($td['branch_id'] ? (int)$td['branch_id'] : null)) {
                fail('Time deposit not found.');
            }
        }
        if (!$td) {
            fail('Choose the time deposit.');
        }
        $how = (string)p('how');
        $cash = $how === 'credit_savings' || $how === 'renew_all' ? null : (Teller::openShift()['cash_account_id'] ?? Teller::userCashAccount());
        $classId = Classes::required('class_id', 'the settlement');
        [$txnId, $newId] = CoopLedger::withClass($classId, fn() => TimeDeposits::settle((int)$td['id'], (string)p('settle_date'), $how, ['cash_account_id' => $cash, 'credit_account_id' => pint('credit_account_id'), 'term_days' => (int)p('new_term') ?: null,
            'shift_id' => Teller::openShift()['id'] ?? null, 'reason' => trim((string)p('reason'))], $pageKey));
        $no = (string)DB::value('SELECT trans_no FROM sav_txns WHERE txn_id = :t AND trans_no IS NOT NULL LIMIT 1', ['t' => $txnId]);
        flash('success', ($newId ? 'Time deposit rolled over as a new placement.' : 'Time deposit closed.') . ($no !== '' ? ' Transaction ' . $no . ' on the Savings Transaction Center.' : ''));
        redirect(p('back') === 'list' ? url($pageKey) : url($pageKey, ['id' => $newId ?: $td['id']]));
    }
    if ($action === 'accrue' || $action === 'accrue_preview') {
        Access::require($pageKey, $action === 'accrue' ? 'create' : 'view');
        if ($action === 'accrue') {
            coop_once();
        }
        $classId = $action === 'accrue' ? Classes::required('class_id', 'the interest accrual') : null;
        $r = CoopLedger::withClass($classId, fn() => TimeDeposits::accrue((string)p('through'), (string)p('accrual_posting', p('through')), $pageKey, $action === 'accrue'));
        if ($action === 'accrue') {
            flash('success', $r['count'] ? 'Interest accrued on ' . $r['count'] . ' placement(s): ' . Dec::money($r['amount']) . '.' : 'Nothing to accrue: every placement is accrued through that date.');
            redirect(url($pageKey));
        }
        $accrualPreview = $r;
        return;
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ one placement
if ($td) {
    // the accrued interest payable of the deposit: every accrual adds to it, every withdrawal of accrued interest (CAIW)
    // takes out what it used of it (the part beyond the accrual is interest expense), by date, with the running balance
    $accEntries = [];
    foreach (DB::all('SELECT * FROM td_accruals WHERE placement_id = :p ORDER BY period_to, id', ['p' => $td['id']]) as $r) {
        $accEntries[] = ['date' => $r['accrual_date'], 'sort' => $r['accrual_date'] . ' 0 ' . sprintf('%010d', (int)$r['id']), 'what' => 'Accrual through ' . fdate($r['period_to']), 'add' => Dec::of($r['amount']), 'less' => '0', 'extra' => '0', 'txn_id' => (int)$r['txn_id']];
    }
    foreach (DB::all('SELECT * FROM td_interest_payouts WHERE placement_id = :p ORDER BY payout_date, id', ['p' => $td['id']]) as $r) {
        $accEntries[] = ['date' => $r['payout_date'], 'sort' => $r['payout_date'] . ' 1 ' . sprintf('%010d', (int)$r['id']), 'what' => 'Accrued interest withdrawn (CAIW)' . ($r['trans_no'] ? ' ' . $r['trans_no'] : ''),
            'add' => '0', 'less' => Dec::of($r['accrued_used']), 'extra' => Dec::of($r['expensed']), 'txn_id' => (int)$r['txn_id']];
    }
    usort($accEntries, fn($a, $b) => strcmp($a['sort'], $b['sort']));
    $accBal = '0';
    foreach ($accEntries as &$r) {
        $accBal = Dec::sub(Dec::add($accBal, $r['add'], 2), $r['less'], 2);
        $r['balance'] = $accBal;
    }
    unset($r);
    // a settled deposit: the settlement paid out what stood accrued
    if (!empty($td['settled_txn_id']) && !Dec::isZero($accBal)) {
        $accEntries[] = ['date' => $td['settled_on'], 'what' => 'Paid out by the settlement', 'add' => '0', 'less' => $accBal, 'extra' => '0', 'txn_id' => (int)$td['settled_txn_id'], 'balance' => '0.00'];
    }
    $accruedLeft = Dec::sub(Dec::of($td['accrued_interest']), Dec::of($td['accrued_paid'] ?? '0'), 2);
    $earned = TimeDeposits::earnedThrough($td, today());
    $m = Coop::member((int)$td['member_id']);
    $savOpts = DB::pairs("SELECT a.id, CONCAT(a.account_no, ' ', p.name) FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND a.status IN ('active','dormant') AND p.product_type IN ('ordinary','special')", ['m' => $td['member_id']]);
    $actions = btn_link(url($pageKey, ['id' => $td['id'], 'a' => 'certificate']), 'Certificate', 'btn-outline-secondary', 'award') . ' ' . btn_link(url($pageKey), 'Time Deposit Center', 'btn-outline-secondary', 'arrow-left');
    coop_layout('Time deposit ' . $td['certificate_no'], ['actions' => $actions]);
    $pre = $td['status'] === 'active' && today() < $td['maturity_date'];
    ?>
    <div class="mb-3"><?= member_card($m) ?></div>
    <div class="row g-3">
      <div class="col-xl-7">
        <div class="coop-tiles">
          <div class="coop-tile"><div class="t-label">Principal</div><div class="t-value"><?= cm($td['principal']) ?></div><div class="t-sub"><?= h(Dec::fix($td['rate'], 2)) ?> % a year, <?= (int)$td['term_days'] ?> days</div></div>
          <div class="coop-tile is-ok"><div class="t-label">Maturity value</div><div class="t-value"><?= cm($td['maturity_value']) ?></div><div class="t-sub">on <?= h(fdate($td['maturity_date'])) ?></div></div>
          <div class="coop-tile is-warn"><div class="t-label">Interest earned to date</div><div class="t-value"><?= cm($earned) ?></div><div class="t-sub">accrued in the books <?= cm($accruedLeft) ?><?= Dec::isPos(Dec::of($td['accrued_paid'] ?? '0')) ? ' (' . cm($td['accrued_interest']) . ' accrued, ' . cm($td['accrued_paid']) . ' withdrawn)' : '' ?></div></div>
        </div>
        <div class="card mb-3"><div class="card-body"><?= coop_kv(['Certificate' => h($td['certificate_no']), 'Account' => safe_link('sav.accounts', ['id' => $td['account_id']], $td['account_no']), 'Product' => h($td['product_name']),
            'Placed' => h(fdate($td['placement_date'])), 'Interest' => h(ucfirst($td['interest_method'])) . ($td['compounding'] !== 'maturity' ? ', compounded ' . h($td['compounding']) : ''), 'At maturity' => h(TimeDeposits::ACTIONS[$td['maturity_action']] ?? ''),
            'Status' => coop_status($td['status'] === 'renewed' ? 'renewed_td' : $td['status']), 'Settled' => $td['settled_on'] ? h(fdate($td['settled_on'])) . ' ' . ($td['settled_txn_id'] ? Txn::link((int)$td['settled_txn_id']) : '') : '',
            'Placement voucher' => $td['placement_txn_id'] ? Txn::link((int)$td['placement_txn_id']) : '', 'Renewed from' => $td['renewed_from_id'] ? safe_link($pageKey, ['id' => $td['renewed_from_id']], 'earlier placement') : '',
            'Renewed as' => $td['renewed_to_id'] ? safe_link($pageKey, ['id' => $td['renewed_to_id']], 'new placement') : '', 'Certificate reprints' => (int)$td['reprint_count'] ? (string)(int)$td['reprint_count'] : '', 'Notes' => h((string)$td['notes'])]) ?></div></div>
        <div class="card"><div class="card-header">Interest accruals</div><div class="table-responsive"><table class="table table-sm mb-0 td-accruals stack-sm"><thead><tr><th>Date</th><th>Entry</th><th class="num">Accrued</th><th class="num">Withdrawn</th><th class="num">Balance</th><th>Voucher</th></tr></thead><tbody>
          <?php foreach ($accEntries as $r): ?><tr>
            <td data-label="Date"><?= h(fdate($r['date'])) ?></td>
            <td data-label="Entry"><?= h($r['what']) ?><?= Dec::isPos($r['extra']) ? '<div class="small text-muted">and ' . cm($r['extra']) . ' more than was accrued, charged to interest expense</div>' : '' ?></td>
            <td class="num" data-label="Accrued"><?= Dec::isPos($r['add']) ? cm($r['add']) : '' ?></td>
            <td class="num" data-label="Withdrawn"><?= Dec::isPos($r['less']) ? cm($r['less']) : '' ?></td>
            <td class="num" data-label="Balance"><?= cm($r['balance']) ?></td>
            <td data-label="Voucher"><?= $r['txn_id'] ? Txn::link($r['txn_id']) : '' ?></td></tr><?php endforeach; ?>
          <?php if (!$accEntries): ?><tr><td colspan="6" class="text-muted">No accrual posted.</td></tr><?php endif; ?>
          </tbody><?php if ($accEntries): ?><tfoot><tr><td colspan="4" class="fw-semibold">Accrued interest payable</td><td class="num fw-semibold td-acc-left"><?= cm($accruedLeft) ?></td><td></td></tr></tfoot><?php endif; ?></table></div></div>
      </div>
      <div class="col-xl-5">
      <?php if ($td['status'] === 'active' && Access::can($pageKey, 'create')): ?>
        <form method="post" class="card" data-once data-confirm="Settle time deposit <?= h($td['certificate_no']) ?>?" data-confirm-ok="Settle"><div class="card-header"><?= $pre ? 'Pre-terminate' : 'Settle at maturity' ?></div><div class="card-body row g-2">
          <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="settle">
          <?= input_field('settle_date', 'Date', max(today(), $pre ? today() : $td['maturity_date']) === today() ? today() : $td['maturity_date'], ['class' => 'col-6', 'type' => 'date']) ?>
          <?= select_field('how', 'What to do', TimeDeposits::ACTIONS, $td['maturity_action'], ['class' => 'col-12', 'extra' => 'id="settleHow" data-no-combo']) ?>
          <div class="col-12" data-toggle-by="#settleHow" data-show-when="credit_savings"><?= str_replace('col-md-4', '', select_field('credit_account_id', 'Savings account to credit', $savOpts, $td['credit_account_id'], ['class' => '', 'blank' => ''])) ?></div>
          <div class="col-12" data-toggle-by="#settleHow" data-show-when="renew_principal,renew_all"><label class="form-label">New term (days)</label><input class="form-control form-control-sm" type="number" name="new_term" value="<?= (int)$td['term_days'] ?>"></div>
          <?php if ($pre): $p = Savings::product((int)$td['product_id']); ?><div class="col-12 small text-warning">Before maturity: interest at the pre-termination rate of <?= $p['preterm_rate'] !== null ? h(Dec::fix($p['preterm_rate'], 2)) . ' %' : 'zero (none set)' ?>, less the fee of <?= cm($p['preterm_fee']) ?>. A supervisor approves.</div><?php endif; ?>
          <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
          <?= input_field('reason', 'Remarks', '', ['class' => 'col-12']) ?>
          <div class="col-12"><?= Approval::fields($pre) ?></div>
          <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> <?= $pre ? 'Pre-terminate' : 'Settle' ?></button></div>
          <div class="col-12 small text-muted">Payouts are paid from your teller drawer; open your shift first.</div>
        </div></form>
        <?php $wd = SavTxn::tdWithdrawable($td); ?>
        <form method="post" class="card mt-3" data-once data-confirm="Withdraw {withdraw_amount} of accrued interest from <?= h($td['certificate_no']) ?>?" data-confirm-ok="Withdraw"><div class="card-header">Withdraw accrued interest (CAIW)</div><div class="card-body row g-2">
          <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="caiw">
          <div class="col-6"><div class="form-label">Accrued interest</div><div class="fs-5 fw-semibold"><?= cm($wd) ?></div><div class="small text-muted">earned to date, less <?= cm($td['interest_paid']) ?> already withdrawn</div></div>
          <?= coop_money('withdraw_amount', 'Withdraw amount', fv('withdraw_amount', null), ['class' => 'col-6', 'extra' => 'data-confirm-value="withdraw_amount"' . (Dec::isPos($wd) ? '' : ' disabled')]) ?>
          <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
          <div class="col-12"><button class="btn btn-outline-primary btn-sm" <?= Dec::isPos($wd) ? '' : 'disabled' ?>><i class="bi bi-cash-coin"></i> Withdraw</button></div>
          <div class="col-12 small text-muted">Paid from your teller drawer; the principal stays. The transaction is shown on the Savings Transaction Center.</div>
        </div></form>
      <?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ new placement (Time Deposit)
$m = coop_member_param();
$firstProduct = $tdProducts ? Savings::product((int)array_key_first($tdProducts)) : null;
if (g('a') === 'new') {
    Access::require($pageKey, 'create');
    coop_layout('New Time Deposit', ['actions' => btn_link(url($pageKey), 'Time Deposit Center', 'btn-outline-secondary', 'arrow-left')]);
    if (!$tdProducts): ?><div class="alert alert-light">Set up a time deposit product first (Savings &amp; Deposit Products).</div><?php else: ?>
    <form method="post" class="card" style="max-width:920px" data-once data-confirm="Place a time deposit of {principal}?" data-confirm-title="Place the time deposit" data-confirm-ok="Place"><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="place">
      <?= member_pick('member_id', $m ? (int)$m['id'] : (pint('member_id') ?: null), ['class' => 'col-md-7', 'required' => true, 'status' => 'active']) ?>
      <?= select_field('product_id', 'Product', $tdProducts, fv('product_id', null), ['class' => 'col-md-5', 'extra' => 'data-no-combo']) ?>
      <div class="col-6 col-md-3"><label class="form-label">Principal</label><input class="form-control form-control-sm text-end" name="principal" value="<?= h((string)p('principal')) ?>" inputmode="decimal" data-money required data-confirm-value="principal"></div>
      <?= input_field('term_days', 'Duration (days)', fv('term_days', null, $firstProduct ? (string)TimeDeposits::termDays($firstProduct) : '365'), ['class' => 'col-6 col-md-3', 'type' => 'number', 'step' => '1']) ?>
      <?= input_field('placement_date', 'Date opened', fv('placement_date', null, today()), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
      <?= input_field('rate', 'Special rate % (blank = product tier)', fv('rate', null), ['class' => 'col-6 col-md-3', 'extra' => 'inputmode="decimal"']) ?>
      <?= select_field('interest_method', 'Interest', ['simple' => 'Simple', 'compound' => 'Compounded'], fv('interest_method', null, 'simple'), ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('compounding', 'Compounded', ['maturity' => 'At maturity', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly'], fv('compounding', null, 'maturity'), ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
      <?= select_field('maturity_action', 'At maturity', TimeDeposits::ACTIONS, fv('maturity_action', null, 'payout'), ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
      <div class="col-md-6"><label class="form-label">Savings account to credit at maturity</label><select class="form-select form-select-sm" name="credit_account_id" id="tdCredit" data-no-combo><option value="">(choose the member first)</option></select></div>
      <?= select_field('source', 'Money from', ['cash' => 'Cash (teller drawer)', 'check' => 'Check', 'savings' => 'Transfer from the member\'s savings'], fv('source', null, 'cash'), ['class' => 'col-md-6', 'extra' => 'id="tdSource" data-no-combo']) ?>
      <div class="col-md-6 row g-2 m-0 p-0" data-toggle-by="#tdSource" data-show-when="check"><?= input_field('cheque_no', 'Check no.', p('cheque_no'), ['class' => 'col-6']) ?><?= input_field('bank', 'Bank', p('bank'), ['class' => 'col-6']) ?></div>
      <div class="col-md-6" data-toggle-by="#tdSource" data-show-when="savings"><label class="form-label">From savings account</label><select class="form-select form-select-sm" name="from_account_id" id="tdFrom" data-no-combo></select></div>
      <?= input_field('certificate_no', 'Certificate no. (blank = next)', fv('certificate_no', null), ['class' => 'col-6 col-md-3', 'extra' => 'placeholder="' . h(Seq::peek('td_certificate')) . '"']) ?>
      <?= Classes::requiredField(fv('class_id', null), 'col-6 col-md-3') ?>
      <div class="col-12"><?= Approval::fields() ?></div>
      <div class="col-12 d-flex flex-wrap align-items-center gap-3"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Place time deposit</button>
        <span class="small text-muted">The placement is a transaction of the Savings Transaction Center (transaction no. <?= h(SavTxn::peek()) ?> next).</span></div>
    </div></form>
    <script>
    document.addEventListener('member:picked', (e) => {
      if (!e.detail || !e.detail.id) return;
      fetch('<?= h(url('api.coop', ['a' => 'member'])) ?>&id=' + e.detail.id).then((r) => r.json()).then((d) => {
        const sav = (d.accounts || []).filter((a) => a.type === 'ordinary' || a.type === 'special');
        const opt = (a) => '<option value="' + a.id + '">' + a.no + ' ' + a.product + ' (' + window.CoopMoney.money(window.CoopMoney.cents(a.available)) + ')</option>';
        const c = document.getElementById('tdCredit'), f = document.getElementById('tdFrom');
        if (c) c.innerHTML = '<option value="">(none)</option>' + sav.map(opt).join('');
        if (f) f.innerHTML = sav.map(opt).join('');
      });
    });
    </script>
    <?php endif;
    layout_end();
    exit;
}

// ------------------------------------------------------------------ Time Deposit Center: the list
$status = in_array(g('status'), ['active', 'paid', 'renewed', 'preterminated', 'cancelled', 'all'], true) ? (string)g('status') : 'active';
$maturing = gint('maturing');
$q = trim((string)g('q'));
[$bf, $bp] = Coop::branchFilter('a.branch_id');
$sql = 'SELECT t.*, a.account_no, a.member_id, m.full_name, m.member_no FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id WHERE 1 = 1' . $bf;
$prm = $bp;
if ($status !== 'all') {
    $sql .= ' AND t.status = :st';
    $prm['st'] = $status;
}
if ($maturing) {
    $sql .= ' AND t.maturity_date <= :md';
    $prm['md'] = Coop::addDays(today(), $maturing);
}
if ($q !== '') {
    $sql .= ' AND (m.full_name LIKE :q1 OR t.certificate_no LIKE :q2 OR a.account_no LIKE :q3 OR m.member_no = :q4)';
    $prm += ['q1' => '%' . $q . '%', 'q2' => $q . '%', 'q3' => $q . '%', 'q4' => $q];
}
$rows = DB::all($sql . ' ORDER BY t.placement_date DESC, t.id DESC LIMIT 1000', $prm);
$totP = '0';
$totA = '0';
foreach ($rows as &$r) {
    $r['withdrawable'] = SavTxn::tdWithdrawable($r);
    $totP = Dec::add($totP, $r['principal'], 2);
    $totA = Dec::add($totA, $r['withdrawable'], 2);
}
unset($r);

$grid = function () use ($rows, $totP, $totA, $pageKey) { ?>
  <div class="sv-grid-wrap"><table class="table sv-grid" data-select-grid="#tdBar" aria-label="Time deposits"><thead><tr>
    <th class="d-none d-md-table-cell">Date Opened</th><th>Certificate No.</th><th>Member Name</th><th class="num d-none d-md-table-cell">Duration</th><th class="d-none d-sm-table-cell">Maturity Date</th><th class="num">Principal Amount</th><th class="num">Accrued Interest</th><th class="d-none d-lg-table-cell">Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $due = $r['status'] === 'active' && $r['maturity_date'] <= today(); ?>
    <tr data-row tabindex="0" data-id="<?= (int)$r['id'] ?>" data-account="<?= (int)$r['account_id'] ?>" data-member="<?= (int)$r['member_id'] ?>" data-status="<?= h($r['status']) ?>"
        data-cert="<?= h($r['certificate_no']) ?>" data-name="<?= h($r['full_name']) ?>" data-td="TD <?= (int)$r['term_days'] ?>" data-accrued="<?= h(Dec::money($r['withdrawable'])) ?>"
        data-principal="<?= h(Dec::money($r['principal'])) ?>" data-matured="<?= $r['maturity_date'] <= today() ? 'yes' : 'no' ?>" data-term="<?= (int)$r['term_days'] ?>" data-hasint="<?= Dec::isPos($r['withdrawable']) ? 'yes' : 'no' ?>"
        data-title="<?= h($r['certificate_no'] . '  ' . $r['full_name']) ?>">
      <td class="d-none d-md-table-cell"><?= h(fdate($r['placement_date'])) ?></td><td class="fw-semibold"><?= h($r['certificate_no']) ?></td>
      <td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted d-sm-none">TD <?= (int)$r['term_days'] ?>, matures <?= h(fdate($r['maturity_date'])) ?></div></td><td class="num d-none d-md-table-cell"><?= (int)$r['term_days'] ?></td>
      <td class="d-none d-sm-table-cell <?= $due ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($r['maturity_date'])) ?><?= $due ? ' (matured)' : '' ?></td><td class="num"><?= cm($r['principal']) ?></td><td class="num"><?= cm($r['withdrawable']) ?></td>
      <td class="d-none d-lg-table-cell"><?= coop_status($r['status'] === 'renewed' ? 'renewed_td' : $r['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-5">No time deposit matches.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="sv-grid-foot"><span><?= count($rows) ?> time deposit(s)</span><span class="sv-totals"><span>Principal <strong><?= cm($totP) ?></strong></span><span>Accrued interest <strong><?= cm($totA) ?></strong></span></span></div>
<?php };
if (g('partial') === '1') {
    $grid();
    exit;
}
if (g('a') === 'print') {
    layout_start('Time Deposit Center', ['bare' => true]);
    echo '<div class="doc">' . coop_print_head('TIME DEPOSITS', '', today());
    $grid();
    echo '</div><script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}
$filterOn = $status !== 'active' || $maturing;
$can = Access::can($pageKey, 'create');
coop_layout('Time Deposit Center', ['js' => ['js/savings.js']]);
?>
<form class="sv-toolbar" id="tdBar" role="search" data-live-search data-live-target="#tdRows" action="<?= h(url($pageKey)) ?>">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="sv-tools" role="toolbar" aria-label="Time deposits">
    <?php if ($can): ?><a class="sv-tool is-primary" href="<?= h(url($pageKey, ['a' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> Time Deposit</a>
    <button type="button" class="sv-tool" data-need-row data-when="status:active;matured:yes" data-modal="#mRollover" title="Renew at maturity"><i class="bi bi-arrow-repeat"></i> Rollover</button>
    <button type="button" class="sv-tool" data-need-row data-when="status:active" data-modal="#mTdClose" title="Pay out at maturity, credit to savings, or pre-terminate"><i class="bi bi-x-circle"></i> Close</button>
    <button type="button" class="sv-tool" data-need-row data-when="status:active;hasint:yes" data-modal="#mCaiw" title="Withdraw the accrued interest (CAIW)"><i class="bi bi-cash-coin"></i> Withdraw Accrued</button><?php endif; ?>
    <button type="button" class="sv-tool" data-need-row data-grid-default data-href="<?= h(url($pageKey)) ?>&id={id}" title="The certificate, its accruals and its ledger"><i class="bi bi-journal-text"></i> Ledger</button>
    <button type="button" class="sv-tool <?= $filterOn ? 'is-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#tdFilter" aria-expanded="<?= $filterOn ? 'true' : 'false' ?>" aria-controls="tdFilter"><i class="bi bi-funnel"></i> Filter</button>
    <a class="sv-tool" href="<?= h(url($pageKey, ['a' => 'print', 'status' => $status, 'maturing' => $maturing ?: null, 'q' => $q ?: null])) ?>" target="_blank" rel="noopener" data-print-link><i class="bi bi-printer"></i> Print</a>
    <?php if ($can): ?><button type="button" class="sv-tool" data-bs-toggle="collapse" data-bs-target="#tdAccrue" aria-expanded="false" aria-controls="tdAccrue" title="Monthly accrual of the interest expense"><i class="bi bi-calendar2-check"></i> Accrual</button>
    <a class="sv-tool" href="<?= h(url($pageKey, ['a' => 'import'])) ?>" title="Place the time deposits of the former system from a CSV file (migration)"><i class="bi bi-filetype-csv"></i> Import Time Deposit</a><?php endif; ?>
  </div>
  <div class="sv-tool-end"><div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Member, certificate or account no." autocomplete="off" aria-label="Search"></div></div>
  <div class="collapse w-100 <?= $filterOn ? 'show' : '' ?>" id="tdFilter"><div class="row g-2 pt-2 px-1">
    <?= select_field('status', 'Status', ['active' => 'Active', 'paid' => 'Paid', 'renewed' => 'Rolled over', 'preterminated' => 'Pre-terminated', 'cancelled' => 'Cancelled', 'all' => 'Every status'], $status, ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
    <?= select_field('maturing', 'Maturing', ['7' => 'within 7 days', '15' => 'within 15 days', '30' => 'within 30 days', '0' => 'any time'], (string)($maturing ?: '0'), ['class' => 'col-6 col-md-3', 'extra' => 'data-no-combo']) ?>
  </div></div>
</form>
<?php if ($can): ?>
<div class="collapse <?= $accrualPreview ? 'show' : '' ?>" id="tdAccrue">
  <form method="post" class="card mb-3" data-once><div class="card-header">Monthly interest accrual</div><div class="card-body row g-2 align-items-end">
    <?= csrf_field() ?><?= Coop::onceField() ?>
    <?= input_field('through', 'Accrue through', p('through') ?: date('Y-m-t', strtotime('last day of previous month')), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
    <?= input_field('accrual_posting', 'Posting date', p('accrual_posting') ?: date('Y-m-t', strtotime('last day of previous month')), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
    <?= Classes::requiredField(fv('class_id', null), 'col-md-6') ?>
    <div class="col-6 col-md-3"><button class="btn btn-outline-primary btn-sm w-100" name="action" value="accrue_preview" data-no-confirm formnovalidate>Preview</button></div>
    <div class="col-6 col-md-3"><button class="btn btn-primary btn-sm w-100" name="action" value="accrue" data-confirm="Post the accrual of time deposit interest through this date?">Post accrual</button></div>
    <div class="col-12 small text-muted">Dr interest expense, Cr accrued interest payable, for the interest earned since the last accrual. Running it again for the same date adds nothing.</div>
  </div>
  <?php if ($accrualPreview): ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Certificate</th><th class="num">Earned to date</th><th class="num">To accrue</th></tr></thead><tbody>
    <?php foreach ($accrualPreview['lines'] as $l): ?><tr><td><?= h($l['certificate_no']) ?></td><td class="num"><?= cm($l['earned']) ?></td><td class="num"><?= cm($l['amount']) ?></td></tr><?php endforeach; ?>
    <?php if (!$accrualPreview['lines']): ?><tr><td colspan="3" class="text-muted">Nothing to accrue.</td></tr><?php endif; ?>
  </tbody><tfoot><tr><td colspan="2" class="fw-semibold">Total</td><td class="num fw-semibold"><?= cm($accrualPreview['amount']) ?></td></tr></tfoot></table></div><?php endif; ?>
  </form>
</div>
<?php endif; ?>
<div id="tdRows"><?php $grid(); ?></div>
<p class="small text-muted mt-2 mb-0">Click a time deposit and use the toolbar, or double-click it to open its certificate and ledger. Withdraw Accrued pays the interest earned so far (the principal stays); the withdrawal is shown on the Savings Transaction Center with the code CAIW.</p>

<?php if ($can): ?>
<div class="modal fade" id="mCaiw" tabindex="-1" aria-labelledby="mCaiwT"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" data-once data-confirm="Withdraw {withdraw_amount} of accrued interest?" data-confirm-ok="Withdraw">
  <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="caiw"><input type="hidden" name="placement_id" data-row-field="id">
  <div class="modal-header"><h5 class="modal-title" id="mCaiwT">TD Accrued Withdrawal</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body row g-2">
    <div class="col-5"><label class="form-label">Certificate No.</label><input class="form-control form-control-sm" data-row-field="cert" readonly tabindex="-1"></div>
    <div class="col-7"><label class="form-label">Time Deposit</label><input class="form-control form-control-sm" data-row-field="td" readonly tabindex="-1"></div>
    <div class="col-12"><label class="form-label">Member Name</label><input class="form-control form-control-sm" data-row-field="name" readonly tabindex="-1"></div>
    <div class="col-6"><label class="form-label fw-semibold">Accrued Interest</label><input class="form-control sv-money sv-big" data-row-field="accrued" readonly tabindex="-1"></div>
    <div class="col-6"><label class="form-label fw-semibold" for="caiwAmt">Withdraw Amount</label><input class="form-control sv-money sv-big" id="caiwAmt" name="withdraw_amount" inputmode="decimal" data-money required data-confirm-value="withdraw_amount"></div>
    <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
    <div class="col-12 small text-muted">Code CAIW, paid from your teller drawer; the principal stays.</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm px-4">OK</button></div>
</form></div></div>

<?php foreach (['mRollover' => ['Rollover', ['renew_principal' => TimeDeposits::ACTIONS['renew_principal'], 'renew_all' => TimeDeposits::ACTIONS['renew_all']]],
                'mTdClose' => ['Close the time deposit', ['payout' => TimeDeposits::ACTIONS['payout'], 'credit_savings' => TimeDeposits::ACTIONS['credit_savings']]]] as $mid => [$mtitle, $hows]): ?>
<div class="modal fade" id="<?= $mid ?>" tabindex="-1" aria-labelledby="<?= $mid ?>T"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" data-once data-confirm="<?= h($mtitle) ?>?" data-confirm-ok="OK">
  <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="settle"><input type="hidden" name="back" value="list"><input type="hidden" name="placement_id" data-row-field="id">
  <div class="modal-header"><h5 class="modal-title" id="<?= $mid ?>T"><?= h($mtitle) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body row g-2">
    <div class="col-12 fw-semibold" data-row-field="title"></div>
    <div class="col-6"><div class="small text-muted">Principal</div><div class="fw-semibold" data-row-field="principal"></div></div>
    <div class="col-6"><div class="small text-muted">Accrued interest</div><div class="fw-semibold" data-row-field="accrued"></div></div>
    <?= input_field('settle_date', 'Date', today(), ['class' => 'col-6', 'type' => 'date']) ?>
    <?= select_field('how', 'What to do', $hows, array_key_first($hows), ['class' => 'col-12', 'extra' => 'data-no-combo data-td-how']) ?>
    <?php if ($mid === 'mRollover'): ?><div class="col-6"><label class="form-label">New duration (days)</label><input class="form-control form-control-sm" type="number" name="new_term" data-row-field="term"></div>
    <?php else: ?><div class="col-12" data-td-savings hidden><label class="form-label">Savings account to credit</label><select class="form-select form-select-sm" name="credit_account_id" data-no-combo data-td-accounts></select></div>
      <div class="col-12 small text-warning" data-td-pre hidden>Before maturity: pre-termination at the product's pre-termination rate, less its fee; a supervisor approves.</div><?php endif; ?>
    <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
    <?= input_field('reason', 'Remarks', '', ['class' => 'col-12']) ?>
    <div class="col-12"><?= Approval::fields($mid === 'mTdClose') ?></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm px-4">OK</button></div>
</form></div></div>
<?php endforeach; ?>
<script>
(function () {
  const m = document.getElementById('mTdClose');
  if (!m) return;
  m.addEventListener('shown.bs.modal', () => {
    const tr = document.querySelector('#tdRows tr[data-row].is-selected');
    if (!tr) return;
    m.querySelector('[data-td-pre]').hidden = tr.dataset.matured === 'yes';
    const sel = m.querySelector('[data-td-accounts]');
    sel.innerHTML = '';
    fetch('<?= h(url('api.coop', ['a' => 'member'])) ?>&id=' + tr.dataset.member).then((r) => r.json()).then((d) => {
      (d.accounts || []).filter((a) => a.type === 'ordinary' || a.type === 'special').forEach((a) => {
        const o = document.createElement('option'); o.value = a.id; o.textContent = a.no + ' ' + a.product; sel.appendChild(o);
      });
    }).catch(() => {});
  });
  m.querySelector('[data-td-how]').addEventListener('change', (e) => { m.querySelector('[data-td-savings]').hidden = e.target.value !== 'credit_savings'; });
})();
</script>
<?php endif;
layout_end();
