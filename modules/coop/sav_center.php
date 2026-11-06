<?php
declare(strict_types=1);

/**
 * Savings Transaction Center: every savings and time deposit transaction the tellers make, in ONE transaction number
 * series: deposits, withdrawals, error corrections, memos, returned checks, time deposit placements, withdrawals
 * and accrued interest withdrawals. The toolbar opens each kind of transaction, as in the cooperative's former program.
 */
require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.center';

// the voucher of a transaction (View, Print, and the void with its reason)
if (coop_voucher_actions($pageKey, ['coop_sv'])) {
    return;
}

$show = g('show') === 'custom' ? 'custom' : 'today';
$from = $show === 'custom' ? gdate('from', today()) : today();
$to = $show === 'custom' ? gdate('to', today()) : today();
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$q = trim((string)g('q'));
$code = strtoupper(trim((string)g('code')));
$tellerId = gint('teller');
$productId = gint('product');
$withVoid = g('void') === '1';

// ------------------------------------------------------------------ R Check: a deposited check dishonored by the bank
if (g('a') === 'rcheck') {
    handle_post(function () use ($pageKey) {
        Access::require($pageKey, 'create');
        coop_once();
        $bank = pint('bank_account_id');
        $classId = Classes::required('class_id', 'the returned check');
        $r = CoopLedger::withClass($classId, fn() => SavTxn::returnCheck((int)pint('sav_txn_id'), (string)p('return_date', today()), trim((string)p('reason')), $bank ?: null, (string)p('code', 'RTC'), $pageKey));
        flash('success', 'Returned check recorded as transaction ' . $r['trans_no'] . '; the member\'s account was debited.');
        redirect(url($pageKey, ['a' => 'rcheck']));
    });
    $days = in_array((int)g('days'), [30, 60, 90, 180, 365], true) ? (int)g('days') : 90;
    $rows = SavTxn::returnable($days, $q);
    $debitCodes = DB::pairs("SELECT code, CONCAT(code, ' - ', description) FROM sav_txn_codes WHERE entry_side = 'debit' AND is_active = 1 AND is_error_correct = 0 AND (code = 'RTC' OR (is_system = 0 AND COALESCE(is_memo, 0) = 0)) ORDER BY code = 'RTC' DESC, code");
    $banks = Accts::ofType(['Bank']);
    coop_layout('Returned Checks (R Check)', ['actions' => btn_link(url($pageKey), 'Savings Transaction Center', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/savings.js']]);
    ?>
    <form class="sv-toolbar" role="search" data-live-search data-live-target="#rcRows" action="<?= h(url($pageKey)) ?>">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="a" value="rcheck">
      <div class="sv-tool-end ms-0 w-100">
        <div class="sv-search flex-grow-1"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Member name, account no., cheque no. or transaction no." autocomplete="off"></div>
        <div class="d-flex align-items-center gap-1"><label class="form-label" for="rcDays">Deposited within</label>
          <select class="form-select form-select-sm" name="days" id="rcDays" data-no-combo><?php foreach ([30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '180 days', 365 => 'a year'] as $k => $v): ?><option value="<?= $k ?>" <?= $k === $days ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
      </div>
    </form>
    <div id="rcRows">
    <?php
    $render = function () use ($rows, $pageKey) { ?>
      <div class="sv-grid-wrap"><table class="table sv-grid"><thead><tr><th>Deposited</th><th class="d-none d-sm-table-cell">Transaction no</th><th>Code</th><th class="d-none d-md-table-cell">Account no</th><th>Member Name</th><th class="d-none d-md-table-cell">Cheques</th><th class="num">Amount</th><th class="d-none d-sm-table-cell">Status</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r): $chq = json_decode((string)($r['cheque_json'] ?? ''), true) ?: []; ?>
        <tr><td><?= h(fdate($r['txn_date'])) ?></td><td class="d-none d-sm-table-cell"><?= h((string)$r['trans_no']) ?></td><td><?= h($r['txn_code']) ?></td><td class="d-none d-md-table-cell"><?= h($r['account_no']) ?></td>
          <td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted d-md-none"><?= h($r['account_no']) ?><?= (int)$r['is_cleared'] ? '' : ', clearing' ?></div></td>
          <td class="wrap small d-none d-md-table-cell"><?= h(implode(', ', array_map(fn($c) => trim(($c['cheque_no'] ?? '') . ' ' . ($c['bank'] ?? '')), $chq))) ?: '<span class="text-muted">no details</span>' ?></td>
          <td class="num"><?= cm($r['credit']) ?></td><td class="d-none d-sm-table-cell"><?= (int)$r['is_cleared'] ? 'Cleared' : 'Clearing until ' . h(fdate($r['clearing_date'])) ?></td>
          <td class="text-end"><?php if (Access::can($pageKey, 'create')): ?><button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rcModal"
              data-rc='<?= h(json_encode(['id' => (int)$r['id'], 'title' => $r['account_no'] . ' ' . $r['full_name'] . ' ' . Dec::money($r['credit']), 'cleared' => (int)$r['is_cleared']])) ?>' title="Record the returned check"><i class="bi bi-envelope-x"></i><span class="d-none d-sm-inline"> Return</span></button><?php endif; ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="text-muted py-4 text-center">No check deposit in that period<?= g('q') ? ' matches the search' : '' ?>.</td></tr><?php endif; ?>
      </tbody></table></div>
    <?php };
    if (g('partial') === '1') {
        $render();
        exit;
    }
    $render();
    ?>
    </div>
    <?php if (Access::can($pageKey, 'create')): ?>
    <div class="modal fade" id="rcModal" tabindex="-1" aria-labelledby="rcTitle"><div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content" data-once action="<?= h(url($pageKey, ['a' => 'rcheck'])) ?>"
         data-confirm="Record the returned check? The member's account is debited." data-confirm-ok="Record">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="sav_txn_id" id="rcId">
      <div class="modal-header"><h5 class="modal-title" id="rcTitle">Returned check</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body row g-2">
        <div class="col-12 fw-semibold" id="rcWhat"></div>
        <?= input_field('return_date', 'Date returned', today(), ['class' => 'col-6', 'type' => 'date']) ?>
        <?= select_field('code', 'Transaction code', $debitCodes ?: ['RTC' => 'RTC - Returned Check'], 'RTC', ['class' => 'col-6', 'extra' => 'data-no-combo']) ?>
        <div class="col-12" id="rcBank" hidden><?= str_replace('col-md-4', '', select_field('bank_account_id', 'Bank account that charged it back (the check was already cleared)', $banks, '', ['class' => '', 'blank' => ''])) ?></div>
        <div class="col-12"><label class="form-label">Reason (from the bank's advice)</label><input class="form-control form-control-sm" name="reason" required placeholder="Drawn against insufficient funds"></div>
        <?= Classes::requiredField(fv('class_id', null), 'col-12') ?>
        <div class="col-12"><?= Approval::fields() ?></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm">Record the returned check</button></div>
    </form></div></div>
    <script>
    document.addEventListener('show.bs.modal', (e) => {
      if (e.target.id !== 'rcModal' || !e.relatedTarget) return;
      const d = JSON.parse(e.relatedTarget.dataset.rc || '{}');
      document.getElementById('rcId').value = d.id || '';
      document.getElementById('rcWhat').textContent = d.title || '';
      const bank = document.getElementById('rcBank');
      bank.hidden = !d.cleared;
      const sel = bank.querySelector('select'); if (sel) sel.required = !!d.cleared;
    });
    </script>
    <?php endif;
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
[$bf, $bp] = Coop::branchFilter('a.branch_id');
$sql = "SELECT s.*, a.account_no, a.member_id, p.name AS product_name, p.product_type, m.full_name, m.member_no, u.username, t.is_voided AS voucher_void,
               (SELECT tp.term_days FROM td_placements tp WHERE tp.account_id = s.account_id ORDER BY tp.id DESC LIMIT 1) AS td_term
          FROM sav_txns s JOIN sav_accounts a ON a.id = s.account_id JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
          LEFT JOIN users u ON u.id = s.teller_id LEFT JOIN transactions t ON t.id = s.txn_id
         WHERE s.trans_no IS NOT NULL AND s.txn_date BETWEEN :f AND :t" . $bf;
$prm = ['f' => $from, 't' => $to] + $bp;
if (!$withVoid) {
    $sql .= ' AND s.is_voided = 0';
}
if ($q !== '') {
    $words = array_slice(array_values(array_filter(preg_split('/[\s,]+/u', $q) ?: [], fn($w) => $w !== '')), 0, 4);
    $cond = [];
    foreach ($words as $i => $w) {
        $cond[] = "m.full_name LIKE :w$i";
        $prm["w$i"] = '%' . $w . '%';
    }
    $sql .= ' AND ((' . implode(' AND ', $cond) . ') OR s.trans_no LIKE :qn OR a.account_no LIKE :qa OR m.member_no = :qm OR s.description LIKE :qd)';
    $prm += ['qn' => $q . '%', 'qa' => $q . '%', 'qm' => $q, 'qd' => '%' . $q . '%'];
}
if ($code !== '') {
    $sql .= ' AND s.txn_code = :code';
    $prm['code'] = $code;
}
if ($tellerId) {
    $sql .= ' AND s.teller_id = :tel';
    $prm['tel'] = $tellerId;
}
if ($productId) {
    $sql .= ' AND a.product_id = :prod';
    $prm['prod'] = $productId;
}
$rows = DB::all($sql . ' ORDER BY s.trans_no DESC, s.id DESC LIMIT 1500', $prm);
$dep = '0';
$wd = '0';
foreach ($rows as $r) {
    if ((int)$r['is_voided'] === 0) {
        $dep = Dec::add($dep, $r['credit'], 2);
        $wd = Dec::add($wd, $r['debit'], 2);
    }
}

$grid = function () use ($rows, $dep, $wd, $from, $to) { ?>
  <div class="sv-grid-wrap"><table class="table sv-grid" data-select-grid="#svBar" aria-label="Savings transactions"><thead><tr>
    <th class="d-none d-sm-table-cell">Transact Date</th><th>Transaction no</th><th>Trans Code</th><th class="d-none d-md-table-cell">Account no</th><th class="d-none d-lg-table-cell">Savings Name</th><th>Member Name</th><th class="num">Amount</th><th class="d-none d-md-table-cell">Memo</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $amt = SavTxn::amount($r); $void = (int)$r['is_voided'] === 1; ?>
    <tr data-row tabindex="0" class="<?= $void ? 'is-void' : '' ?>" data-id="<?= (int)$r['id'] ?>" data-txn="<?= (int)$r['txn_id'] ?>" data-account="<?= (int)$r['account_id'] ?>" data-accountno="<?= h($r['account_no']) ?>"
        data-member="<?= (int)$r['member_id'] ?>" data-void="<?= $void ? '1' : '0' ?>" data-title="<?= h($r['trans_no'] . ' ' . $r['txn_code'] . ' ' . $r['full_name']) ?>">
      <td class="d-none d-sm-table-cell"><?= h(fdate($r['txn_date'])) ?> <span class="text-muted small"><?= h(substr((string)$r['created_at'], 11, 5)) ?></span></td>
      <td class="fw-semibold"><?= h($r['trans_no']) ?><div class="small text-muted fw-normal d-sm-none"><?= h(fdate($r['txn_date'])) ?></div></td><td><?= h($r['txn_code']) ?></td><td class="d-none d-md-table-cell"><?= h($r['account_no']) ?></td>
      <td class="d-none d-lg-table-cell"><?= h($r['product_type'] === 'time_deposit' && $r['td_term'] ? 'TD ' . (int)$r['td_term'] : $r['product_name']) ?></td>
      <td class="wrap"><?= h($r['full_name']) ?><div class="small text-muted d-md-none"><?= h($r['account_no']) ?></div></td>
      <td class="num <?= Dec::isNeg($amt) ? 'neg' : '' ?>"><?= (Dec::isNeg($amt) ? '-' : '') . cm(Dec::abs($amt)) ?></td>
      <td class="wrap small d-none d-md-table-cell"><?= h((string)$r['description']) ?><?= $void ? ' <span class="text-danger">(deleted)</span>' : '' ?><?= (int)$r['is_back_entry'] ? ' <span class="text-muted">(back entry)</span>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted text-center py-5">No transaction <?= $from === $to ? 'on ' . h(fdate($from)) : 'from ' . h(fdate($from)) . ' to ' . h(fdate($to)) ?>.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="sv-grid-foot"><span><?= count($rows) ?> transaction(s)<?= count($rows) >= 1500 ? ' (the first 1,500; narrow the filter)' : '' ?></span>
    <span class="sv-totals"><span>Deposits <strong><?= cm($dep) ?></strong></span><span>Withdrawals <strong><?= cm($wd) ?></strong></span><span>Net <strong><?= cm(Dec::sub($dep, $wd, 2)) ?></strong></span></span></div>
<?php };

if (g('partial') === '1') {
    $grid();
    exit;
}
if (g('a') === 'print') {
    layout_start('Savings Transaction Center', ['bare' => true]);
    echo '<div class="doc">' . coop_print_head('SAVINGS TRANSACTIONS', '', '', [($from === $to ? fdate($from) : fdate($from) . ' to ' . fdate($to))]);
    $grid();
    echo '</div><script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}

$tellers = DB::pairs('SELECT DISTINCT u.id, u.full_name FROM sav_txns s JOIN users u ON u.id = s.teller_id WHERE s.trans_no IS NOT NULL ORDER BY u.full_name');
$codeOpts = DB::pairs("SELECT code, CONCAT(code, ' - ', description) FROM sav_txn_codes ORDER BY code");
$filterOn = $code !== '' || $tellerId || $productId || $withVoid;
$can = fn(string $k, string $a = 'view') => Access::can($k, $a);
$params = array_filter(['show' => $show === 'custom' ? 'custom' : null, 'from' => $show === 'custom' ? $from : null, 'to' => $show === 'custom' ? $to : null, 'q' => $q ?: null,
    'code' => $code ?: null, 'teller' => $tellerId ?: null, 'product' => $productId ?: null, 'void' => $withVoid ? '1' : null]);
coop_layout('Savings Transaction Center' . ($show === 'custom' ? ' - Filtered: ' . fdate($from) . ' to ' . fdate($to) : ''), ['js' => ['js/savings.js']]);
?>
<form class="sv-toolbar" id="svBar" role="search" data-live-search data-live-target="#svRows" action="<?= h(url($pageKey)) ?>">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <div class="sv-tools" role="toolbar" aria-label="Savings transactions">
    <?php if ($can('sav.transact', 'create')): ?><a class="sv-tool is-primary" href="<?= h(url('sav.transact')) ?>" title="Savings deposits and withdrawals by transaction code"><i class="bi bi-wallet2"></i> Savings</a><?php endif; ?>
    <?php if ($can('sav.td')): ?><a class="sv-tool" href="<?= h(url('sav.td')) ?>" title="Time Deposit Center: placements, withdrawals, rollovers, accrued interest"><i class="bi bi-hourglass-split"></i> Time Deposit</a><?php endif; ?>
    <?php if ($can('sav.transact', 'create')): ?><a class="sv-tool" href="<?= h(url('sav.transact', ['mode' => 'memo'])) ?>" title="Debit and credit memos"><i class="bi bi-journal-plus"></i> Memo</a><?php endif; ?>
    <?php if ($can('dist.pr')): ?><a class="sv-tool" href="<?= h(url('dist.pr')) ?>" title="Patronage refund computed and credited to the members' savings"><i class="bi bi-arrow-repeat"></i> Refund</a><?php endif; ?>
    <?php if ($can('sav.interest')): ?><a class="sv-tool" href="<?= h(url('sav.interest')) ?>" title="Interest computation and posting on the savings products"><i class="bi bi-percent"></i> Interest</a><?php endif; ?>
    <?php if ($can('dist.isc')): ?><a class="sv-tool" href="<?= h(url('dist.isc')) ?>" title="Interest on share capital by average daily balance, credited to share capital"><i class="bi bi-pie-chart"></i> Dividend</a><?php endif; ?>
    <?php if ($can($pageKey, 'create')): ?><a class="sv-tool" href="<?= h(url($pageKey, ['a' => 'rcheck'])) ?>" title="A deposited check dishonored by the bank"><i class="bi bi-envelope-x"></i> R Check</a><?php endif; ?>
    <span class="sv-tool-sep" aria-hidden="true"></span>
    <button type="button" class="sv-tool" data-need-row data-grid-default data-href="<?= h(url($pageKey, ['a' => 'view'])) ?>&id={txn}" title="The transaction and its journal entry"><i class="bi bi-eye"></i> View</button>
    <button type="button" class="sv-tool" data-need-row data-href="<?= h(url('sav.accounts')) ?>&id={account}" title="The ledger of the account"><i class="bi bi-journal-text"></i> Ledger</button>
    <button type="button" class="sv-tool <?= $filterOn ? 'is-primary' : '' ?>" data-bs-toggle="collapse" data-bs-target="#svFilter" aria-expanded="<?= $filterOn ? 'true' : 'false' ?>" aria-controls="svFilter"><i class="bi bi-funnel"></i> Filter</button>
    <a class="sv-tool" href="<?= h(url($pageKey, ['a' => 'print'] + $params)) ?>" target="_blank" rel="noopener" data-print-link><i class="bi bi-printer"></i> Print</a>
  </div>
  <div class="sv-tool-end">
    <div class="d-flex align-items-center gap-1"><label class="form-label" for="svShow">Show Transactions</label>
      <select class="form-select form-select-sm" name="show" id="svShow" data-no-combo data-show-range="#svRange" style="width:auto"><option value="today" <?= $show === 'today' ? 'selected' : '' ?>>Today</option><option value="custom" <?= $show === 'custom' ? 'selected' : '' ?>>Custom</option></select></div>
    <div class="sv-range" id="svRange"><input class="form-control form-control-sm" type="date" name="from" value="<?= h($from) ?>" aria-label="From" style="width:auto"><span class="small">to</span><input class="form-control form-control-sm" type="date" name="to" value="<?= h($to) ?>" aria-label="To" style="width:auto"></div>
    <div class="sv-search"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Name, account, transaction no." autocomplete="off" aria-label="Search"></div>
  </div>
  <div class="collapse w-100 <?= $filterOn ? 'show' : '' ?>" id="svFilter"><div class="row g-2 pt-2 px-1">
    <?= select_field('code', 'Trans Code', $codeOpts, $code, ['class' => 'col-6 col-md-3', 'blank' => 'All codes', 'extra' => 'data-no-combo']) ?>
    <?= select_field('product', 'Savings product', Savings::productOptions(null, false), $productId, ['class' => 'col-6 col-md-3', 'blank' => 'All products', 'extra' => 'data-no-combo']) ?>
    <?= select_field('teller', 'Teller', $tellers, $tellerId, ['class' => 'col-6 col-md-3', 'blank' => 'All tellers', 'extra' => 'data-no-combo']) ?>
    <div class="col-6 col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="void" value="1" id="svVoid" <?= $withVoid ? 'checked' : '' ?>><label class="form-check-label" for="svVoid">Show deleted transactions</label></div></div>
  </div></div>
</form>
<div id="svRows"><?php $grid(); ?></div>
<p class="small text-muted mt-2 mb-0">Click a transaction and use View or Ledger, or double-click it. Deposits and withdrawals are made with <strong>Savings</strong>; time deposits, rollovers and accrued interest withdrawals on <strong>Time Deposit</strong>.</p>
<?php
layout_end();
