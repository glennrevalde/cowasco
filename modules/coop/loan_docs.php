<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.docs';
$loan = gint('id') ? Loans::loan((int)gint('id')) : null;
if (!$loan) {
    flash('warning', 'Open the documents from a loan account.');
    redirect(url('loan.accounts'));
}
if (!Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
    Access::denyPage($pageKey, 'print documents of a loan of another branch on');
}
$m = Coop::member((int)$loan['member_id']);
$app = $loan['application_id'] ? DB::row('SELECT * FROM loan_applications WHERE id = :a', ['a' => $loan['application_id']]) : null;
$comakers = $app ? DB::all('SELECT c.*, m.member_no, m.full_name AS member_name, m.res_house, m.res_street, m.res_barangay, m.res_city, m.res_province FROM loan_comakers c LEFT JOIN members m ON m.id = c.comaker_member_id WHERE c.application_id = :a ORDER BY c.id', ['a' => $app['id']]) : [];
$schedule = Loans::scheduleRows((int)$loan['id']);
$charges = DB::all('SELECT * FROM loan_charges_applied WHERE loan_id = :l ORDER BY id', ['l' => $loan['id']]);
$coopAddress = (string)(Settings::all()['company_address'] ?? '');
$amort = $schedule[0]['total'] ?? '0';
$open = in_array($loan['status'], Loans::OPEN_STATUSES, true);

const LOAN_DOCS = [
    'promissory_note' => ['Promissory note', 'Signed by the borrower and the co-makers at release.'],
    'disclosure'      => ['Disclosure statement', 'Truth in Lending: charges, net proceeds, finance charges and the effective interest rate.'],
    'release'         => ['Loan release statement', 'The loan released, the deductions, the net loan proceeds and the amortization due each year.'],
    'schedule'        => ['Amortization schedule', 'Due dates, principal, interest and balance, with what is already paid.'],
    'comaker'         => ['Co-maker statement', 'One page per co-maker.'],
    'soa'             => ['Statement of account', 'Dues, payoff and the loan ledger as of a date.'],
    'member_notice'   => ['Payment reminder', 'Recorded as a collection notice on the loan.'],
    'demand_letter'   => ['Demand letter', 'Recorded as a collection notice on the loan.'],
];

$comakerNames = implode(', ', array_map(fn($c) => (string)($c['member_name'] ?: $c['name']), $comakers)) ?: 'none';
$vars = [
    'coop' => Coop::coopName(), 'coop_address' => $coopAddress, 'borrower' => $m['full_name'], 'borrower_address' => Members::address($m), 'member' => $m['full_name'], 'member_address' => Members::address($m),
    'member_no' => $m['member_no'], 'loan_no' => $loan['loan_no'], 'pn_no' => (string)$loan['pn_no'], 'product' => $loan['product_name'], 'amount' => Dec::money($loan['principal']),
    'amount_words' => amount_in_words((float)$loan['principal']), 'rate' => Dec::fix($loan['rate'], 2), 'term' => Dec::fix($loan['term'], 0) . ' ' . $loan['term_unit'],
    'mode' => mb_strtolower(Coop::modeLabel($loan['mode_of_payment'])), 'amortization' => Dec::money($amort), 'installments' => (string)count($schedule),
    'first_due' => fdate($loan['start_date']), 'maturity' => fdate($loan['due_date']), 'penalty_rate' => Dec::fix($loan['penalty_rate'], 2), 'grace_days' => (string)(int)$loan['penalty_grace_days'],
    'release_date' => fdate($loan['release_date']), 'comakers' => $comakerNames, 'today' => fdate(today()),
    'total_charges' => Dec::money($loan['total_charges']), 'net_proceeds' => Dec::money($loan['net_proceeds']), 'total_interest' => Dec::money($loan['total_interest']),
];

// a collection notice is recorded first, then printed (each notice is its own document; a second print of it is a reprint)
handle_post(function () use ($loan, $pageKey) {
    Access::require($pageKey, 'print');
    $type = p('notice_type') === 'demand_letter' ? 'demand_letter' : 'member_notice';
    if (!in_array($loan['status'], Loans::OPEN_STATUSES, true)) {
        fail('The loan is not open.');
    }
    $date = (string)p('notice_date', today());
    if (!valid_date($date)) {
        fail('Enter the date of the notice.');
    }
    $d = Loans::dues($loan, $date);
    // overdue: what is in arrears; current: the next amortization
    $amountDue = $d['total_due'];
    if (!Dec::isPos($amountDue)) {
        $amountDue = Dec::of(DB::value('SELECT total - principal_paid - interest_paid FROM loan_schedule WHERE loan_id = :l AND is_paid = 0 ORDER BY inst_no LIMIT 1', ['l' => $loan['id']]) ?? '0');
    }
    $id = DB::insert('loan_notices', ['loan_id' => $loan['id'], 'notice_type' => $type === 'demand_letter' ? 'demand' : 'reminder', 'notice_date' => $date,
        'amount_due' => Dec::fix($amountDue),
        'notes' => mb_substr(trim((string)p('notes')), 0, 255) ?: null, 'created_by' => Auth::id()]);
    Audit::log('create', 'loan', 'loan_notice', $id, $loan['loan_no'] . ' ' . $type, ['page_key' => $pageKey]);
    redirect(url($pageKey, ['id' => $loan['id'], 'doc' => $type, 'notice' => $id, 'deadline' => p('deadline')]));
});

$doc = (string)g('doc');
if ($doc === '') {
    // ------------------------------------------------------------------ the list of documents
    $prints = DB::pairs("SELECT doc_key, COUNT(*) FROM document_prints WHERE ref_type = 'loan' AND ref_id = :l GROUP BY doc_key", ['l' => $loan['id']]);
    coop_layout('Documents of loan ' . $loan['loan_no'], ['actions' => btn_link(url('loan.accounts', ['id' => $loan['id']]), 'Loan', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="mb-3"><?= member_card($m) ?></div>
    <div class="row g-3">
      <div class="col-xl-7"><div class="card"><table class="table table-sm align-middle mb-0"><tbody>
      <?php foreach (LOAN_DOCS as $key => [$label, $note]): if (in_array($key, ['member_notice', 'demand_letter'], true)) { continue; } ?>
        <tr><td><strong><?= h($label) ?></strong><div class="small text-muted"><?= h($note) ?><?= !empty($prints['loan.' . $key]) ? ' Printed ' . (int)$prints['loan.' . $key] . ' time(s).' : '' ?></div></td>
          <td class="text-end text-nowrap">
          <?php if ($key === 'comaker'): ?>
            <?php foreach ($comakers as $c): ?><a class="btn btn-sm btn-outline-secondary mb-1" target="_blank" href="<?= h(url($pageKey, ['id' => $loan['id'], 'doc' => 'comaker', 'c' => $c['id']])) ?>"><i class="bi bi-printer"></i> <?= h((string)($c['member_name'] ?: $c['name'])) ?></a> <?php endforeach; ?>
            <?php if (!$comakers): ?><span class="small text-muted">no co-maker</span><?php endif; ?>
          <?php elseif ($key === 'soa'): ?>
            <form class="d-inline-flex gap-1" target="_blank"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><input type="hidden" name="id" value="<?= (int)$loan['id'] ?>"><input type="hidden" name="doc" value="soa">
              <input class="form-control form-control-sm" type="date" name="asof" value="<?= h(today()) ?>" aria-label="As of"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer"></i> Print</button></form>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= h(url($pageKey, ['id' => $loan['id'], 'doc' => $key])) ?>"><i class="bi bi-printer"></i> Print</a>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <p class="small text-muted mt-2">The first print of a document is the original; later prints are marked as copies and need a reason, recorded in the audit trail. The wording of the promissory note, disclosure notes, co-maker statement and letters is kept in Document Templates, by effective date.</p></div>
      <div class="col-xl-5">
        <?php if ($open): $d = Loans::dues($loan, today()); ?>
        <form method="post" class="card" target="_blank"><div class="card-header">Collection notice</div><div class="card-body row g-2">
          <?= csrf_field() ?>
          <?= select_field('notice_type', 'Notice', ['member_notice' => 'Payment reminder', 'demand_letter' => 'Demand letter'], $d['count_due'] ? 'demand_letter' : 'member_notice', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
          <?= input_field('notice_date', 'Date', today(), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
          <?= input_field('deadline', 'Settle by (demand)', Coop::addDays(today(), 10), ['class' => 'col-6 col-md-3', 'type' => 'date']) ?>
          <?= input_field('notes', 'Notes (delivery, received by)', '', ['class' => 'col-12']) ?>
          <div class="col-12 small text-muted">Due today: <?= cm($d['total_due']) ?> (<?= (int)$d['count_due'] ?> amortization(s)); next due <?= h(fdate((string)$d['next_due'])) ?>.</div>
          <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-envelope-paper"></i> Record and print</button></div>
        </div></form>
        <?php endif; ?>
      </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ---------------------------------------------------------------------- printing
if (!isset(LOAN_DOCS[$doc])) {
    flash('warning', 'Unknown document.');
    redirect(url($pageKey, ['id' => $loan['id']]));
}
$label = LOAN_DOCS[$doc][0];
$refType = 'loan';
$refId = (int)$loan['id'];
$docNo = $loan['loan_no'];
$comaker = null;
$notice = null;
if ($doc === 'comaker') {
    $comaker = null;
    foreach ($comakers as $c) {
        if ((int)$c['id'] === gint('c')) {
            $comaker = $c;
        }
    }
    if (!$comaker) {
        flash('warning', 'Choose the co-maker.');
        redirect(url($pageKey, ['id' => $loan['id']]));
    }
    $refType = 'loan_comaker';
    $refId = (int)$comaker['id'];
} elseif (in_array($doc, ['member_notice', 'demand_letter'], true)) {
    $notice = DB::row('SELECT * FROM loan_notices WHERE id = :n AND loan_id = :l', ['n' => gint('notice'), 'l' => $loan['id']]);
    if (!$notice) {
        flash('warning', 'Record the notice first.');
        redirect(url($pageKey, ['id' => $loan['id']]));
    }
    $refType = 'loan_notice';
    $refId = (int)$notice['id'];
    $docNo = $loan['loan_no'] . '-N' . $notice['id'];
} elseif ($doc === 'soa') {
    $asOf = gdate('asof', today());
    $docNo = $loan['loan_no'] . ' ' . $asOf;
}
// statements of account are not controlled documents: they are printed as often as needed
if ($doc === 'soa' || $doc === 'schedule') {
    Access::require($pageKey, 'print');
    $copy = 1;
    DB::insert('document_prints', ['doc_key' => 'loan.' . $doc, 'ref_type' => 'loan', 'ref_id' => $loan['id'], 'doc_no' => mb_substr($docNo, 0, 40), 'copy_no' => 1, 'printed_by' => Auth::id()]);
} else {
    $copy = coop_print_gate('loan.' . $doc, $refType, $refId, $docNo, $pageKey, mb_strtolower($label));
    if ($copy === null) {
        exit;
    }
}
layout_start($label . ' ' . $loan['loan_no'], ['bare' => true]);
$sig = fn(string $k) => Coop::setting($k);
$schedTable = function (bool $withPaid) use ($schedule) {
    $t = ['p' => '0', 'i' => '0', 'a' => '0'];
    echo '<table class="table table-sm doc-table"><thead><tr><th>#</th><th>Due date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amortization</th><th class="num">Balance</th>' . ($withPaid ? '<th>Paid</th>' : '') . '</tr></thead><tbody>';
    foreach ($schedule as $r) {
        $t['p'] = Dec::add($t['p'], $r['principal'], 2);
        $t['i'] = Dec::add($t['i'], $r['interest'], 2);
        $t['a'] = Dec::add($t['a'], $r['total'], 2);
        echo '<tr><td>' . (int)$r['inst_no'] . '</td><td>' . h(fdate($r['due_date'])) . '</td><td class="num">' . cm($r['principal']) . '</td><td class="num">' . cm($r['interest']) . '</td><td class="num">' . cm($r['total']) . '</td><td class="num">' . cm($r['balance_after']) . '</td>'
            . ($withPaid ? '<td>' . ((int)$r['is_paid'] ? h(fdate((string)$r['paid_on'])) : (Dec::isPos($r['principal_paid']) || Dec::isPos($r['interest_paid']) ? 'partial' : '')) . '</td>' : '') . '</tr>';
    }
    echo '</tbody><tfoot><tr class="fw-bold"><td colspan="2">Total</td><td class="num">' . cm($t['p']) . '</td><td class="num">' . cm($t['i']) . '</td><td class="num">' . cm($t['a']) . '</td><td></td>' . ($withPaid ? '<td></td>' : '') . '</tr></tfoot></table>';
};
$loanFacts = '<table class="table table-sm doc-table"><tbody>'
    . '<tr><th style="width:28%">Borrower</th><td>' . h($m['full_name']) . ', member no. ' . h($m['member_no']) . '</td><th style="width:18%">Loan no.</th><td>' . h($loan['loan_no']) . '</td></tr>'
    . '<tr><th>Address</th><td>' . h(Members::address($m)) . '</td><th>PN no.</th><td>' . h((string)$loan['pn_no']) . '</td></tr>'
    . '<tr><th>Loan product</th><td>' . h($loan['product_name']) . '</td><th>Released</th><td>' . h(fdate($loan['release_date'])) . '</td></tr>'
    . '<tr><th>Principal</th><td>' . cm($loan['principal']) . '</td><th>Maturity</th><td>' . h(fdate($loan['due_date'])) . '</td></tr>'
    . '<tr><th>Interest</th><td>' . h(Dec::fix($loan['rate'], 2)) . ' % a year, ' . h(mb_strtolower(Loans::INTEREST_METHODS[$loan['interest_method']] ?? '')) . '</td><th>Term</th><td>' . h($vars['term']) . ', ' . h($vars['mode']) . '</td></tr>'
    . '</tbody></table>';
?>
<div class="doc coop-doc">
<?= coop_copy_mark($copy) ?>
<?php switch ($doc):
case 'promissory_note': ?>
  <?= coop_print_head('PROMISSORY NOTE', (string)$loan['pn_no'], $loan['release_date'], ['Loan no.' => $loan['loan_no']]) ?>
  <div class="doc-clause mt-3"><?= CoopDocs::render('promissory_note', $loan['release_date'], $vars) ?></div>
  <?= coop_signatures(array_merge(['Borrower' => $m['full_name']], array_combine(array_map(fn($i) => 'Co-maker ' . ($i + 1), array_keys($comakers)), array_map(fn($c) => (string)($c['member_name'] ?: $c['name']), $comakers)) ?: [], ['Witness' => ''])) ?>
<?php break;
case 'disclosure':
    $payoff = Dec::of($loan['previous_loan_payoff']);
    $finCharges = '0';
    $otherCharges = '0';
    foreach ($charges as $c) {
        if ($c['charge_type'] === 'financial') {
            $finCharges = Dec::add($finCharges, $c['amount'], 2);
        } else {
            $otherCharges = Dec::add($otherCharges, $c['amount'], 2);
        }
    }
    $totalFinance = Dec::add($loan['total_interest'], $finCharges, 2);
    // the amount financed for the EIR: what the borrower actually gets the use of (net of every deduction except a payoff of their own older loan)
    $financed = Dec::add($loan['net_proceeds'], $payoff, 2);
    $eir = Loans::eir($financed, array_column($schedule, 'total'), Loans::periodsPerYear($loan['mode_of_payment'], $loan['term'], $loan['term_unit']));
    $vars['eir'] = $eir ? $eir['annual'] . '%' : 'n/a'; ?>
  <?= coop_print_head('DISCLOSURE STATEMENT ON LOAN / CREDIT TRANSACTION', $loan['loan_no'], $loan['release_date'], ['Under' => 'R.A. 3765, Truth in Lending Act']) ?>
  <?= $loanFacts ?>
  <table class="table table-sm doc-table"><tbody>
    <tr><th>1. Loan granted (amount to be financed)</th><td class="num"><?= cm($loan['principal']) ?></td></tr>
    <?php foreach ($charges as $c): ?><tr><td class="ps-4">Less: <?= h($c['name']) ?> (<?= h((string)$c['basis_text']) ?><?= $c['charge_type'] === 'savings' ? ', credited to your savings / share capital' : ($c['charge_type'] === 'non_financial' ? ', collected for a third party' : '') ?>)<?= $c['how'] !== 'deducted' ? ' <em>' . h(mb_strtolower(Loans::CHARGE_METHODS[$c['how']] ?? '')) . '</em>' : '' ?></td><td class="num"><?= cm($c['amount']) ?></td></tr><?php endforeach; ?>
    <?php if (Dec::isPos($payoff)): ?><tr><td class="ps-4">Less: payoff of previous loan</td><td class="num"><?= cm($payoff) ?></td></tr><?php endif; ?>
    <tr><th>2. Net proceeds of the loan</th><td class="num fw-bold"><?= cm($loan['net_proceeds']) ?></td></tr>
    <tr><th>3. Finance charges</th><td></td></tr>
    <tr><td class="ps-4">Interest (<?= h(Dec::fix($loan['rate'], 2)) ?> % a year, <?= h(mb_strtolower(Loans::INTEREST_METHODS[$loan['interest_method']] ?? '')) ?>)</td><td class="num"><?= cm($loan['total_interest']) ?></td></tr>
    <tr><td class="ps-4">Service and other financial charges</td><td class="num"><?= cm($finCharges) ?></td></tr>
    <tr><th class="ps-4">Total finance charges</th><td class="num fw-bold"><?= cm($totalFinance) ?></td></tr>
    <tr><th>4. Non-finance charges (insurance, capital build-up, others)</th><td class="num"><?= cm($otherCharges) ?></td></tr>
    <tr><th>5. Percentage of finance charges to the amount financed</th><td class="num"><?= Dec::isPos($financed) ? h(Dec::fix(Dec::div(Dec::mul($totalFinance, '100', 6), $financed, 6), 2)) . ' %' : '' ?></td></tr>
    <tr><th>6. Effective interest rate</th><td class="num fw-bold"><?= $eir ? h($eir['period']) . ' % a ' . h(['daily' => 'day', 'weekly' => 'week', 'semi_monthly' => 'half-month', 'monthly' => 'month', 'quarterly' => 'quarter', 'semi_annual' => 'half-year', 'annual' => 'year'][$loan['mode_of_payment']] ?? 'term') . ', ' . h($eir['annual']) . ' % a year' : 'n/a' ?></td></tr>
    <?php $tots = array_column($schedule, 'total'); usort($tots, fn($a, $b) => Dec::cmp($a, $b)); ?>
    <tr><th>7. Payments</th><td class="num"><?= count($schedule) ?> <?= h($vars['mode']) ?> amortizations <?= $tots && Dec::cmp($tots[0], end($tots)) !== 0 ? 'from ' . cm(end($tots)) . ' down to ' . cm($tots[0]) : 'of ' . cm($amort) ?>, total <?= cm(Dec::sum($tots)) ?></td></tr>
    <tr><th>8. Penalty on late payment</th><td class="num"><?= Dec::isPos($loan['penalty_rate']) ? h(Dec::fix($loan['penalty_rate'], 2)) . ' % a year on ' . h(mb_strtolower(Loans::PENALTY_BASES[$loan['penalty_base']] ?? '')) . ' after ' . (int)$loan['penalty_grace_days'] . ' day(s)' : 'none' ?></td></tr>
  </tbody></table>
  <div class="doc-clause"><?= CoopDocs::render('disclosure', $loan['release_date'], $vars) ?></div>
  <?= coop_signatures(['Prepared by' => (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $loan['officer_id']]), 'Conforme (borrower)' => $m['full_name'], 'Date received' => '']) ?>
<?php break;
case 'release':
    // the format of the cooperative's former program: no amortization schedule, the amortization due each year instead
    $t = $loan['release_txn_id'] ? Txn::load((int)$loan['release_txn_id']) : null;
    $s = Settings::all();
    $years = [];
    foreach ($schedule as $r) {
        $y = substr((string)$r['due_date'], 0, 4);
        $years[$y] ??= ['p' => '0', 'i' => '0'];
        $years[$y]['p'] = Dec::add($years[$y]['p'], $r['principal'], 2);
        $years[$y]['i'] = Dec::add($years[$y]['i'], $r['interest'], 2);
    }
    ksort($years);
    $less = array_values(array_filter($charges, fn($c) => $c['how'] !== 'separate'));
    $separate = array_values(array_filter($charges, fn($c) => $c['how'] === 'separate'));
    $peso = fn($v) => '&#8369;' . cm($v);
    $months = Loans::termMonths((string)$loan['term'], (string)$loan['term_unit']);
    $rateText = rtrim(rtrim(Dec::fix($loan['rate'], 4), '0'), '.') . '%'; ?>
  <div class="lrs">
    <div class="lrs-head">
      <?= Logo::img('doc.coop') !== '' ? '<div class="lv-logo">' . Logo::img('doc.coop') . '</div>' : '' ?>
      <div class="lrs-coop"><?= h(Coop::coopName()) ?></div>
      <div><?= h((string)($s['company_address'] ?? '')) ?></div>
      <?php if (trim((string)($s['company_phone'] ?? '')) !== ''): ?><div>Tel. No. <?= h((string)$s['company_phone']) ?></div><?php endif; ?>
      <div class="lrs-title">Loan Release Statement</div>
    </div>
    <div class="lrs-info">
      <div class="lrs-col">
        <div><span>Name of Member:</span><b><?= h($m['full_name']) ?></b></div>
        <div><span>Address:</span><b><?= h(Members::address($m)) ?></b></div>
        <div><span>Loan Type:</span><b><?= h($loan['product_name']) ?></b></div>
        <div><span>Mode of Payment:</span><b><?= h(Coop::modeLabel($loan['mode_of_payment'])) ?></b></div>
        <div><span>Date Release:</span><b><?= h(fdate($loan['release_date'])) ?></b></div>
      </div>
      <div class="lrs-col lrs-right">
        <div><span>P.B. No.:</span><b><?= h((string)($m['passbook_no'] ?? '')) ?></b></div>
        <div><span>Terms (mos)</span><b><?= h(Dec::fix($months, 2)) ?></b></div>
        <div><span>Interest Rate:</span><b><?= h($rateText) ?></b></div>
        <div><span>Total Interest:</span><b><?= $peso($loan['total_interest']) ?></b></div>
        <div><span>Date Due:</span><b><?= h(fdate($loan['due_date'])) ?></b></div>
      </div>
    </div>
    <div class="lrs-box">
      <table class="lrs-amounts">
        <thead><tr><th></th><th class="num">Amount</th></tr></thead>
        <tbody>
          <tr class="lrs-strong"><td>Loan Release:</td><td class="num"><?= $peso($loan['principal']) ?></td></tr>
          <?php if ($less || Dec::isPos($loan['previous_loan_payoff'])): ?><tr><td class="lrs-less">Less:</td><td></td></tr><?php endif; ?>
          <?php foreach ($less as $c): ?><tr><td class="lrs-item"><?= h($c['name']) ?></td><td class="num"><?= cm($c['amount']) ?></td></tr><?php endforeach; ?>
          <?php if (Dec::isPos($loan['previous_loan_payoff'])): ?><tr><td class="lrs-item">Payoff of the previous loan<?= $loan['restructured_from_id'] ? ' ' . h((string)DB::value('SELECT loan_no FROM loan_accounts WHERE id = :l', ['l' => $loan['restructured_from_id']])) : '' ?></td><td class="num"><?= cm($loan['previous_loan_payoff']) ?></td></tr><?php endif; ?>
          <tr class="lrs-strong lrs-net"><td>Net Loan Proceeds:</td><td class="num"><span><?= $peso($loan['net_proceeds']) ?></span></td></tr>
        </tbody>
      </table>
      <?php if ($separate): ?><div class="lrs-sep">Collected separately (not deducted): <?= h(implode(', ', array_map(fn($c) => $c['name'] . ' ' . Dec::money($c['amount']), $separate))) ?>.</div><?php endif; ?>
      <div class="lrs-amort">
        <table>
          <thead><tr><th>Amortization Details</th><?php foreach (array_keys($years) as $y): ?><th class="num"><span><?= h((string)$y) ?></span></th><?php endforeach; ?></tr></thead>
          <tbody>
            <tr><th>Principal</th><?php foreach ($years as $v): ?><td class="num"><?= cm($v['p']) ?></td><?php endforeach; ?></tr>
            <tr><th>Interest</th><?php foreach ($years as $v): ?><td class="num"><?= cm($v['i']) ?></td><?php endforeach; ?></tr>
            <tr class="lrs-yt"><th>Total</th><?php foreach ($years as $v): ?><td class="num"><span><?= cm(Dec::add($v['p'], $v['i'], 2)) ?></span></td><?php endforeach; ?></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="lrs-sigs">
      <div class="lrs-sig"><span>Prepared By:</span><b><?= h(LoanVouchers::preparedBy($loan, $t)) ?></b></div>
      <div class="lrs-sig"><span>Approved By:</span><b><?= h(LoanVouchers::approvedBy($loan, $t)) ?></b></div>
      <div class="lrs-sig lrs-received"><span>Loan Proceeds Received By:</span><b></b><em>Signature Over Printed Name</em></div>
    </div>
    <div class="lrs-ref">Loan <?= h($loan['loan_no']) ?><?= $loan['pn_no'] ? ', P.N. No. ' . h((string)$loan['pn_no']) : '' ?><?= $t ? ', ' . h(LoanVouchers::label($t['txn_type'])) . ' ' . h(LoanVouchers::voucherId($loan, $t)) : '' ?></div>
  </div>
<?php break;
case 'schedule': ?>
  <?= coop_print_head('AMORTIZATION SCHEDULE', $loan['loan_no'], today()) ?>
  <?= $loanFacts ?>
  <?php $schedTable(true); ?>
<?php break;
case 'comaker':
    $v = $vars + ['comaker' => (string)($comaker['member_name'] ?: $comaker['name'])];
    $v['amount'] = Dec::money($comaker['amount_guaranteed'] ?: $loan['principal']);
    $v['amount_words'] = amount_in_words((float)($comaker['amount_guaranteed'] ?: $loan['principal'])); ?>
  <?= coop_print_head('CO-MAKER\'S STATEMENT', $loan['loan_no'], $loan['release_date']) ?>
  <div class="doc-clause mt-3"><?= CoopDocs::render('comaker', $loan['release_date'], $v) ?></div>
  <div class="small mt-2">Co-maker: <?= h($v['comaker']) ?><?= $comaker['member_no'] ? ', member no. ' . h($comaker['member_no']) : ' (non-member)' ?>. Address: <?= h($comaker['comaker_member_id'] ? Members::address($comaker) : (string)$comaker['address']) ?></div>
  <?= coop_signatures(['Co-maker' => $v['comaker'], 'Principal borrower' => $m['full_name'], 'Witness' => '']) ?>
<?php break;
case 'soa':
    $d = $open ? Loans::dues($loan, $asOf) : null;
    $led = DB::all("SELECT x.*, t.txn_no FROM loan_txns x LEFT JOIN transactions t ON t.id = x.txn_id WHERE x.loan_id = :l AND x.is_voided = 0 AND x.kind <> 'accrual' AND x.txn_date <= :d ORDER BY x.txn_date, x.id", ['l' => $loan['id'], 'd' => $asOf]); ?>
  <?= coop_print_head('STATEMENT OF ACCOUNT', $loan['loan_no'], $asOf) ?>
  <?= $loanFacts ?>
  <?php if ($d): ?>
  <table class="table table-sm doc-table"><tbody>
    <tr><th>Amortizations past due</th><td class="num"><?= (int)$d['count_due'] ?></td><th>Principal balance</th><td class="num"><?= cm($loan['principal_balance']) ?></td></tr>
    <tr><th>Principal due</th><td class="num"><?= cm($d['principal_due']) ?></td><th>Interest due</th><td class="num"><?= cm($d['interest_due']) ?></td></tr>
    <tr><th>Penalties</th><td class="num"><?= cm($d['penalty_due']) ?></td><th>Total amount due</th><td class="num fw-bold"><?= cm($d['total_due']) ?></td></tr>
    <tr><th>Next due date</th><td class="num"><?= h(fdate((string)$d['next_due'])) ?></td><th>Amount to pay off the loan</th><td class="num fw-bold"><?= cm($d['payoff']) ?></td></tr>
  </tbody></table>
  <?php else: ?><p class="small">Status: <?= h(Loans::LOAN_STATUSES[$loan['status']] ?? $loan['status']) ?><?= $loan['closed_on'] ? ' on ' . h(fdate($loan['closed_on'])) : '' ?>.</p><?php endif; ?>
  <table class="table table-sm doc-table"><thead><tr><th>Date</th><th>Particulars</th><th>Reference</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th class="num">Balance</th></tr></thead><tbody>
  <?php foreach ($led as $r): ?><tr><td><?= h(fdate($r['txn_date'])) ?></td><td><?= h(Loans::ledgerKind($r)) ?><?= $r['memo'] && $r['kind'] !== 'release' ? ': ' . h((string)$r['memo']) : '' ?></td><td><?= h((string)($r['or_no'] ?: $r['txn_no'])) ?></td>
    <td class="num"><?= cm(Dec::sub($r['principal_dr'], $r['principal_cr'], 2), true) ?></td><td class="num"><?= cm(Dec::sub($r['interest_dr'], $r['interest_cr'], 2), true) ?></td><td class="num"><?= cm(Dec::sub($r['penalty_dr'], $r['penalty_cr'], 2), true) ?></td><td class="num"><?= cm($r['principal_bal']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="small">Charges are shown positive and payments negative. Please report any discrepancy to the cooperative within fifteen days.</p>
  <?= coop_signatures(['Prepared by' => Auth::user()['full_name'] ?? '', 'Noted by' => $sig('signatory_loans')]) ?>
<?php break;
case 'member_notice':
case 'demand_letter':
    $d = Loans::dues($loan, $notice['notice_date']);
    $aging = Loans::aging($loan, $notice['notice_date'], 'par');
    $v = $vars + ['amount_due' => Dec::money($notice['amount_due']), 'due_date' => fdate((string)($d['count_due'] ? DB::value('SELECT MIN(due_date) FROM loan_schedule WHERE loan_id = :l AND is_paid = 0', ['l' => $loan['id']]) : $d['next_due'])),
        'penalty' => Dec::money($d['penalty_due']), 'balance' => Dec::money($loan['principal_balance']), 'days_past_due' => (string)$aging['days'],
        'deadline' => fdate(valid_date((string)g('deadline')) ? (string)g('deadline') : Coop::addDays($notice['notice_date'], 10)), 'today' => fdate($notice['notice_date'])]; ?>
  <?= coop_print_head($doc === 'demand_letter' ? 'DEMAND LETTER' : 'PAYMENT REMINDER', $docNo, $notice['notice_date']) ?>
  <div class="doc-clause mt-3"><?= CoopDocs::render($doc, $notice['notice_date'], $v) ?></div>
  <?= coop_signatures(['Very truly yours' => $sig($doc === 'demand_letter' ? 'signatory_gm' : 'signatory_loans'), 'Received by / date' => '']) ?>
<?php break;
endswitch; ?>
</div>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php
layout_end();
