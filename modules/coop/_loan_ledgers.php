<?php
declare(strict_types=1);

/**
 * The three forms the Loan Accounts Center opens on the selected loan, in the layout of the former program:
 *  - Loan Ledger Form (passbook format): Date, Reference No, Release, Prin. Payments, Prin. Balance, Int. Payments,
 *    Fines/Surc. and Totals; Passbook View or View All; printable;
 *  - Internal Ledger Form: Date, Memo; Release / Additional Charges, Payments and Balances, each by principal, interest
 *    and penalty; with the additional interest and the penalty the loan has earned to date by its product set-up;
 *  - the amortization schedule.
 * Each is shown in a dialog of the center (fetched) or printed on its own page (print=1).
 */

/** The heading of the forms: the account no., the member and loan name, the due date and the term */
function la_form_head(array $loan, string $right = ''): string
{
    $term = rtrim(rtrim(Dec::fix($loan['term'], 2), '0'), '.') . ' ' . ($loan['term_unit'] === 'days' ? 'day(s)' : 'month(s)');
    return '<div class="la-head"><div class="la-no">' . h($loan['loan_no']) . '</div><div class="la-facts">'
        . '<div class="la-f"><span>Member Name</span><b>' . h($loan['member_name']) . '</b></div>'
        . '<div class="la-f"><span>Due Date</span><b>' . h(fdate($loan['due_date'])) . '</b></div>'
        . '<div class="la-f"><span>Loan Name</span><b>' . h($loan['product_name']) . '</b></div>'
        . '<div class="la-f"><span>Loan Term</span><b>' . h($term) . '</b></div>'
        . $right . '</div></div>';
}

/** The heading of a printed form: the cooperative, the title, the loan */
function la_print_head(string $title, array $loan): string
{
    $s = Settings::all();
    return '<div class="la-print-head"><div class="la-print-coop">' . h(Coop::coopName()) . '</div><div>' . h((string)($s['company_address'] ?? '')) . '</div>'
        . (trim((string)($s['company_phone'] ?? '')) !== '' ? '<div>Tel. No. ' . h((string)$s['company_phone']) . '</div>' : '')
        . '<div class="la-print-title">' . h($title) . '</div></div>';
}

function la_amt(string $v, bool $blankZero = true): string
{
    return $blankZero && Dec::isZero($v) ? '' : cm($v);
}

/** The Loan Ledger Form */
function la_ledger(array $loan, bool $all, bool $print): void
{
    $led = Loans::ledgerForm($loan, $all);
    if ($print) {
        echo la_print_head('Loan Ledger' . ($all ? '' : ' (passbook view)'), $loan);
    }
    echo la_form_head($loan);
    ?>
    <div class="la-table-wrap"><table class="table table-sm la-table la-ledger">
      <thead><tr><th>Date</th><th>Reference No</th><th class="num">Release</th><th class="num">Prin. Payments</th><th class="num">Prin. Balance</th><th class="num">Int. Payments</th><th class="num">Fines/Surc.</th></tr></thead>
      <tbody>
      <?php foreach ($led['rows'] as $r): ?>
        <tr><td><?= h(fdate($r['date'])) ?></td><td><?= h($r['ref']) ?><?= $r['note'] !== '' ? '<div class="la-note">' . h($r['note']) . '</div>' : '' ?></td>
          <td class="num"><?= la_amt($r['release']) ?></td><td class="num<?= Dec::isNeg($r['prin']) ? ' neg' : '' ?>"><?= la_amt($r['prin']) ?></td><td class="num"><?= cm($r['bal']) ?></td>
          <td class="num<?= Dec::isNeg($r['int']) ? ' neg' : '' ?>"><?= la_amt($r['int']) ?></td><td class="num<?= Dec::isNeg($r['fines']) ? ' neg' : '' ?>"><?= la_amt($r['fines']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$led['rows']): ?><tr><td colspan="7" class="text-muted">No entry.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td></td><td class="la-tot">Totals</td><td class="num"><?= cm($led['totals']['release']) ?></td><td class="num"><?= cm($led['totals']['prin']) ?></td><td></td><td class="num"><?= cm($led['totals']['int']) ?></td><td class="num"><?= cm($led['totals']['fines']) ?></td></tr></tfoot>
    </table></div>
    <div class="la-foot-note"><?= $all ? 'View All: every entry of the loan ledger; charges (penalties, adjustments, waivers) are told under the reference.' : 'Passbook View: the release and the payments, as the loan passbook shows them (an error correction negative).' ?>
      Principal balance <strong><?= cm($loan['principal_balance']) ?></strong>, interest balance <strong><?= cm($loan['interest_balance']) ?></strong><?= Dec::isPos($loan['penalty_balance']) ? ', penalty <strong>' . cm($loan['penalty_balance']) . '</strong>' : '' ?>.</div>
    <?php
}

/** The Internal Ledger Form */
function la_internal(array $loan, string $asOf, bool $print): void
{
    $il = Loans::internalLedger($loan, $asOf);
    $p = Loans::product((int)$loan['product_id']);
    $mode = Loans::addlInterestReady() ? (string)($p['addl_interest'] ?? 'auto') : 'none';
    if ($print) {
        echo la_print_head('Internal Ledger', $loan);
    }
    echo la_form_head($loan, $print ? '<div class="la-f"><span>Computed to</span><b>' . h(fdate($asOf)) . '</b></div>' : '');
    $t = $il['totals'];
    $b = $il['balance'];
    ?>
    <div class="la-table-wrap"><table class="table table-sm la-table la-internal">
      <thead>
        <tr class="la-groups"><th colspan="2"></th><th colspan="3">Release/Additional Charges</th><th colspan="3">Payments</th><th colspan="3">Balances</th></tr>
        <tr><th>Date</th><th>Memo</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th></tr>
      </thead>
      <tbody>
      <?php foreach ($il['rows'] as $r): ?>
        <tr class="<?= $r['computed'] ? 'is-computed' : '' ?>"><td><?= h(fdate($r['date'])) ?></td><td class="la-memo"><?= h($r['memo']) ?><?= $r['ref'] !== '' ? '<div class="la-note">' . h($r['ref']) . '</div>' : '' ?></td>
          <td class="num"><?= la_amt($r['add_p']) ?></td><td class="num"><?= la_amt($r['add_i']) ?></td><td class="num"><?= la_amt($r['add_pen']) ?></td>
          <td class="num<?= Dec::isNeg($r['pay_p']) ? ' neg' : '' ?>"><?= la_amt($r['pay_p']) ?></td><td class="num<?= Dec::isNeg($r['pay_i']) ? ' neg' : '' ?>"><?= la_amt($r['pay_i']) ?></td><td class="num<?= Dec::isNeg($r['pay_pen']) ? ' neg' : '' ?>"><?= la_amt($r['pay_pen']) ?></td>
          <td class="num"><?= cm($r['bal_p']) ?></td><td class="num"><?= cm($r['bal_i']) ?></td><td class="num"><?= cm($r['bal_pen']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$il['rows']): ?><tr><td colspan="11" class="text-muted">No entry.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td></td><td class="la-tot">Totals</td><td class="num"><?= cm($t['add_p']) ?></td><td class="num"><?= cm($t['add_i']) ?></td><td class="num"><?= cm($t['add_pen']) ?></td>
        <td class="num"><?= cm($t['pay_p']) ?></td><td class="num"><?= cm($t['pay_i']) ?></td><td class="num"><?= cm($t['pay_pen']) ?></td><td class="num"><?= cm($b['p']) ?></td><td class="num"><?= cm($b['i']) ?></td><td class="num"><?= cm($b['pen']) ?></td></tr></tfoot>
    </table></div>
    <div class="la-sum">
      <div><span>On the loan ledger</span><b><?= cm(Dec::add(Dec::add($il['posted']['p'], $il['posted']['i'], 2), $il['posted']['pen'], 2)) ?></b></div>
      <div><span>Additional interest to <?= h(fdate($asOf)) ?></span><b><?= cm($il['additional']['interest']) ?></b></div>
      <div><span>Penalty to <?= h(fdate($asOf)) ?>, not yet charged</span><b><?= cm($il['additional']['penalty']) ?></b></div>
      <div class="la-sum-total"><span>Balance with the additional charges</span><b><?= cm(Dec::add(Dec::add($b['p'], $b['i'], 2), $b['pen'], 2)) ?></b></div>
    </div>
    <div class="la-foot-note">The rows in italics are computed to <?= h(fdate($asOf)) ?> by the set-up of the loan product (<?= h($p['name']) ?>) and are not yet on the loan ledger:
      additional interest <?= h(mb_strtolower(Loans::ADDL_INTEREST[$mode] ?? 'none')) ?>, at <?= h(Dec::fix($loan['rate'], 2)) ?> % a year on <?= (int)$loan['calendar_days'] ?: 365 ?> days;
      penalty <?= Dec::isPos($loan['penalty_rate']) ? h(Dec::fix($loan['penalty_rate'], 2)) . ' % a year on ' . h(mb_strtolower(Loans::PENALTY_BASES[$loan['penalty_base']] ?? '')) . ' after ' . (int)$loan['penalty_grace_days'] . ' grace day(s), charged when the loan is collected' : 'none'; ?>.</div>
    <?php
}

/** The amortization schedule, with what is paid */
function la_schedule(array $loan, bool $print): void
{
    $rows = Loans::scheduleRows((int)$loan['id']);
    if ($print) {
        echo la_print_head('Amortization Schedule', $loan);
    }
    echo la_form_head($loan);
    $tot = ['p' => '0', 'i' => '0', 't' => '0', 'pp' => '0', 'ip' => '0'];
    $today = today();
    ?>
    <div class="la-table-wrap"><table class="table table-sm la-table">
      <thead><tr><th>#</th><th>Due Date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Amortization</th><th class="num">Balance</th><th class="num">Principal Paid</th><th class="num">Interest Paid</th><th>Paid</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): foreach (['p' => 'principal', 'i' => 'interest', 't' => 'total', 'pp' => 'principal_paid', 'ip' => 'interest_paid'] as $k => $f) { $tot[$k] = Dec::add($tot[$k], $r[$f], 2); }
          $late = !(int)$r['is_paid'] && $r['due_date'] < $today && in_array($loan['status'], Loans::OPEN_STATUSES, true); ?>
        <tr class="<?= $late ? 'is-late' : '' ?>"><td><?= (int)$r['inst_no'] ?></td><td><?= h(fdate($r['due_date'])) ?></td><td class="num"><?= cm($r['principal']) ?></td><td class="num"><?= cm($r['interest']) ?></td>
          <td class="num"><?= cm($r['total']) ?></td><td class="num"><?= cm($r['balance_after']) ?></td><td class="num"><?= la_amt(Dec::of($r['principal_paid'])) ?></td><td class="num"><?= la_amt(Dec::of($r['interest_paid'])) ?></td>
          <td><?= (int)$r['is_paid'] ? h(fdate((string)$r['paid_on'])) : ($late ? '<span class="text-danger">overdue</span>' : (Dec::isPos($r['principal_paid']) || Dec::isPos($r['interest_paid']) ? 'partial' : '')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td></td><td class="la-tot">Totals</td><td class="num"><?= cm($tot['p']) ?></td><td class="num"><?= cm($tot['i']) ?></td><td class="num"><?= cm($tot['t']) ?></td><td></td><td class="num"><?= cm($tot['pp']) ?></td><td class="num"><?= cm($tot['ip']) ?></td><td></td></tr></tfoot>
    </table></div>
    <?php
}
