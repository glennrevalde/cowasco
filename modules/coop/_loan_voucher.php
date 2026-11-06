<?php
declare(strict_types=1);

/**
 * The voucher of a loan release, printed in the format of the cooperative's former program:
 *
 *        COOP NAME / address / Tel. No.
 *        Cash Voucher | Check Voucher          Class: ...
 *   Voucher ID ......        Date ......
 *   Paid to ......           Check no. ...... (a check) | Paid from ...... (a cash voucher)
 *   Account ID | Account Name | Description | SL Code | Debit | Credit      Totals
 *   Prepared by:   Paid by:   Received by:
 *   Approved by:   Checked by:                  print date
 *
 * Used by Banking (Write Checks, Cash Voucher), Loans > Loan Release and Loans > Loan Release Vouchers. Every print is
 * logged; a reprint is marked as a copy and needs a reason.
 */
require_once __DIR__ . '/_coop.php';

/** The lines of the voucher: debits first as posted, the cash / bank side last */
function loan_voucher_lines(array $t): array
{
    $rows = DB::all('SELECT jl.account_id, jl.debit, jl.credit, jl.memo, a.code, a.name, m.member_no
                       FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id
                       LEFT JOIN other_names o ON o.id = jl.other_name_id LEFT JOIN members m ON m.id = o.member_id
                      WHERE jl.txn_id = :t ORDER BY jl.id', ['t' => $t['id']]);
    $cashId = (int)($t['account_id'] ?? 0);
    $lines = [];
    $cash = [];
    foreach ($rows as $r) {
        $isCash = $cashId && (int)$r['account_id'] === $cashId && Dec::isPos($r['credit']);
        $line = ['code' => $r['code'], 'name' => $r['name'], 'memo' => $isCash ? '' : (string)$r['memo'], 'sl' => $isCash ? '' : (string)$r['member_no'],
            'debit' => Dec::of($r['debit']), 'credit' => Dec::of($r['credit'])];
        if ($isCash) {
            $cash[] = $line;
        } else {
            $lines[] = $line;
        }
    }
    return array_merge($lines, $cash);
}

/** The voucher itself (the page around it is the caller's) */
function loan_voucher_print(array $t, array $loan, int $copy = 1): void
{
    $s = Settings::all();
    $isCheck = $t['txn_type'] === 'check';
    $title = LoanVouchers::label($t['txn_type']);
    $lines = loan_voucher_lines($t);
    $dr = '0';
    $cr = '0';
    foreach ($lines as $l) {
        $dr = Dec::add($dr, $l['debit'], 2);
        $cr = Dec::add($cr, $l['credit'], 2);
    }
    // the member as the cooperative's records name them (Last, First), as on every loan document
    $paidTo = (string)$loan['member_name'];
    $class = !empty($t['class_id']) ? Classes::name($t['class_id']) : '';
    $branch = $loan['branch_id'] ? Coop::branchName((int)$loan['branch_id']) : '';
    $logo = Logo::img('doc.coop');
    ?>
    <div class="doc lv-doc">
      <?= coop_copy_mark($copy) ?>
      <div class="lv-head">
        <?= $logo !== '' ? '<div class="lv-logo">' . $logo . '</div>' : '' ?>
        <div class="lv-coop"><?= h(Coop::coopName()) ?></div>
        <div><?= h((string)($s['company_address'] ?? '')) ?></div>
        <?php if (trim((string)($s['company_phone'] ?? '')) !== ''): ?><div>Tel. No. <?= h((string)$s['company_phone']) ?></div><?php endif; ?>
        <div class="lv-title"><?= h($title) ?></div>
        <div class="lv-class"><?= $class !== '' ? 'Class: ' . h($class) : '' ?><?= $class !== '' && $branch !== '' ? '<span class="lv-gap"></span>' : '' ?><?= $branch !== '' ? 'Branch: ' . h($branch) : '' ?></div>
        <?php if ((int)$t['is_voided'] === 1): ?><div class="lv-void">VOID</div><?php endif; ?>
      </div>
      <div class="lv-fields">
        <div class="lv-f"><span>Voucher ID</span><b><?= h(LoanVouchers::voucherId($loan, $t)) ?></b></div>
        <div class="lv-f"><span>Date</span><b><?= h(fdate($t['txn_date'])) ?></b></div>
        <div class="lv-f"><span>Paid to</span><b><?= h($paidTo) ?></b></div>
        <?php if ($isCheck): ?>
          <div class="lv-f"><span>Check no.</span><b><?= h((string)$t['txn_no']) ?></b></div>
        <?php else: ?>
          <div class="lv-f"><span><?= $t['txn_type'] === 'cash_voucher' ? 'CAV no.' : 'Loan no.' ?></span><b><?= h($t['txn_type'] === 'cash_voucher' ? (string)$t['txn_no'] : (string)$loan['loan_no']) ?></b></div>
        <?php endif; ?>
      </div>
      <table class="lv-lines">
        <thead><tr><th class="lv-id">Account ID</th><th>Account Name</th><th class="lv-memo">Description</th><th class="lv-sl">SL Code</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td class="lv-id"><?= h($l['code']) ?></td><td><?= h($l['name']) ?></td><td class="lv-memo"><?= h($l['memo']) ?></td><td class="lv-sl"><?= h($l['sl']) ?></td>
            <td class="num"><?= Dec::isPos($l['debit']) ? cm($l['debit']) : '' ?></td><td class="num"><?= Dec::isPos($l['credit']) ? cm($l['credit']) : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$lines): ?><tr><td colspan="6" class="text-center">No ledger lines (a voided voucher keeps none).</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><td class="lv-id"></td><td class="lv-tot-label lv-tot-m">Totals</td><td class="lv-memo"></td><td class="lv-sl lv-tot-label">Totals</td><td class="num"><?= cm($dr) ?></td><td class="num"><?= cm($cr) ?></td></tr></tfoot>
      </table>
      <div class="lv-ref">Loan <?= h($loan['loan_no']) ?>, <?= h($loan['product_name']) ?>, released <?= h(fdate($loan['release_date'])) ?><?= !$isCheck && $t['account_id'] ? '; paid from ' . h(account_label((int)$t['account_id'])) : ($isCheck && $t['account_id'] ? '; drawn on ' . h(account_label((int)$t['account_id'])) : '') ?>.</div>
      <div class="lv-sigs">
        <div class="lv-sig"><span>Prepared by:</span><b><?= h(LoanVouchers::preparedBy($loan, $t)) ?></b></div>
        <div class="lv-sig"><span>Paid by:</span><b></b></div>
        <div class="lv-sig"><span>Received by:</span><b></b></div>
        <div class="lv-sig"><span>Approved by:</span><b><?= h(LoanVouchers::approvedBy($loan, $t)) ?></b></div>
        <div class="lv-sig"><span>Checked by:</span><b><?= h((string)Coop::setting('signatory_bookkeeper')) ?></b></div>
        <div class="lv-sig lv-date"><b><?= h(fdate(today())) ?></b><span>( print date )</span></div>
      </div>
    </div>
    <?php
}

/**
 * Prints the voucher of a loan release on its own page (logged; a reprint asks for its reason). Returns false when the
 * transaction is not the voucher of a loan release.
 */
function loan_voucher_page(array $t, string $pageKey): bool
{
    $loan = $t['txn_type'] === 'loan_release' ? LoanVouchers::loanOf((int)$t['id']) : (LoanVouchers::isLoanVoucher($t) ? LoanVouchers::loanOf((int)$t['id']) : null);
    if (!$loan) {
        return false;
    }
    if (!Coop::branchAllowed($loan['branch_id'] ? (int)$loan['branch_id'] : null)) {
        Access::denyPage($pageKey, 'print a voucher of another branch on');
    }
    $title = LoanVouchers::label($t['txn_type']);
    $copy = coop_print_gate('voucher.loan_release', 'transaction', (int)$t['id'], LoanVouchers::voucherId($loan, $t), $pageKey, mb_strtolower($title));
    if ($copy === null) {
        return true;
    }
    if (in_array($t['txn_type'], ['check'], true)) {
        DB::update('transactions', ['is_printed' => 1, 'to_be_printed' => 0], 'id = :id', ['id' => $t['id']]);
    }
    layout_start($title . ' ' . LoanVouchers::voucherId($loan, $t), ['bare' => true]);
    loan_voucher_print($t, $loan, $copy);
    echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    return true;
}
