<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.vouchers';
if (coop_voucher_actions($pageKey, ['coop_jv', 'coop_or', 'coop_cv', 'loan_release'])) {
    exit;
}

// a cooperative journal voucher: adjustments between member accounts and the general ledger, with the member on each line
if (g('a') === 'new') {
    Access::require($pageKey, 'create');
    handle_post(function () use ($pageKey) {
        coop_once();
        $date = (string)p('txn_date');
        Coop::checkDate($date);
        $memo = trim((string)p('memo'));
        if ($memo === '') {
            fail('Enter the explanation of the voucher.');
        }
        $lines = [];
        $sav = [];
        foreach (coop_grid_posted('lines') as $l) {
            $dr = Dec::round(Dec::of($l['debit'] ?? '0'), 2);
            $cr = Dec::round(Dec::of($l['credit'] ?? '0'), 2);
            if (Dec::isZero($dr) && Dec::isZero($cr)) {
                continue;
            }
            $savId = (int)($l['sav_account_id'] ?? 0);
            $memberId = (int)($l['member_id'] ?? 0) ?: null;
            $acct = (int)($l['account_id'] ?? 0);
            if ($savId) {
                // a line on a member's savings / share capital account: its GL account is the product's
                $a = Savings::validate($savId, Dec::isPos($cr) ? 'credit' : 'debit', Dec::isPos($cr) ? $cr : $dr, $date, ['is_system' => true, 'allow_pending' => true, 'closing' => true]);
                $acct = Savings::ledgerAccount($a);
                $memberId = (int)$a['member_id'];
                $sav[] = [$savId, Dec::isPos($cr) ? 'CM' : 'DM', Dec::isPos($cr) ? 'credit' : 'debit', Dec::isPos($cr) ? $cr : $dr, mb_substr(trim((string)($l['memo'] ?? '')) ?: $memo, 0, 160)];
            }
            if (!$acct) {
                fail('Choose the account of each line.');
            }
            if (Accts::isWithSl($acct) && !$memberId) {
                fail('Account ' . Coop::accountLabel($acct) . ' keeps a member ledger: choose the member on its line.');
            }
            if ($memberId && !Coop::branchAllowed(($mb = Coop::member($memberId)) && $mb['branch_id'] ? (int)$mb['branch_id'] : null)) {
                fail('A member on the voucher belongs to another branch.');
            }
            if (in_array($acct, [Accts::get('loans_receivable')], true) || DB::value('SELECT id FROM loan_products WHERE receivable_account_id = :a LIMIT 1', ['a' => $acct])) {
                fail('Loan balances are changed on Loans > Loan Adjustments, so the loan ledger and the schedule follow.');
            }
            $lines[] = ['account_id' => $acct, 'debit' => $dr, 'credit' => $cr, 'memo' => trim((string)($l['memo'] ?? '')) ?: $memo, 'member_id' => $memberId];
        }
        if (count($lines) < 2) {
            fail('A voucher has at least two lines.');
        }
        foreach ($lines as $l) {
            if (Dec::isPos($l['debit']) && Dec::isPos($l['credit'])) {
                fail('A line is either a debit or a credit, not both.');
            }
            if (Dec::isNeg($l['debit']) || Dec::isNeg($l['credit'])) {
                fail('Amounts cannot be negative; put the amount on the other side instead.');
            }
        }
        $sumDr = Dec::sum(array_column($lines, 'debit'));
        $sumCr = Dec::sum(array_column($lines, 'credit'));
        if (Dec::cmp($sumDr, $sumCr) !== 0) {
            fail('The voucher does not balance: debits ' . Dec::money($sumDr) . ', credits ' . Dec::money($sumCr) . ', difference ' . Dec::money(Dec::abs(Dec::sub($sumDr, $sumCr, 2))) . '.');
        }
        $approver = Approval::check($pageKey, !Access::can($pageKey, 'approve'), 'cooperative journal voucher');
        $id = DB::tx(function () use ($date, $memo, $lines, $sav, $approver) {
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => $memo, 'ref_no' => trim((string)p('ref_no')) ?: null, 'branch_id' => pint('branch_id') ?: Coop::defaultBranchId(),
                'approved_by' => $approver['id'] ?? null], $lines, ['approved_by' => $approver['username'] ?? null]);
            foreach ($sav as [$aid, $code, $side, $amt, $desc]) {
                $a = Savings::account($aid, true);
                Savings::move($a, $code, $side, $amt, $date, ['txn_id' => $txnId, 'description' => $desc, 'is_system' => true, 'ref_type' => 'jv']);
                if ($side === 'debit') {
                    Savings::rebuild($aid);       // refuses an overdraft
                }
            }
            return $txnId;
        });
        flash('success', 'Voucher posted.');
        redirect(url($pageKey, ['a' => 'view', 'id' => $id]));
    });
    $accounts = Accts::all();
    $sacc = [];
    [$bf, $bp] = Coop::branchFilter('a.branch_id');
    foreach (DB::all("SELECT a.id, a.account_no, p.name, m.full_name FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id WHERE a.status <> 'closed'" . $bf . ' ORDER BY m.full_name LIMIT 2000', $bp) as $r) {
        $sacc[$r['id']] = $r['account_no'] . ' ' . $r['full_name'] . ' (' . $r['name'] . ')';
    }
    coop_layout('New cooperative journal voucher', ['actions' => btn_link(url($pageKey), 'Vouchers', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" data-once data-confirm="Post this journal voucher?" data-confirm-ok="Post voucher">
      <?= csrf_field() ?><?= Coop::onceField() ?>
      <div class="card mb-3"><div class="card-body row g-2">
        <?= input_field('txn_date', 'Date', fv('txn_date', null, today()), ['class' => 'col-6 col-md-2', 'type' => 'date', 'required' => true]) ?>
        <?= input_field('ref_no', 'Reference', fv('ref_no', null), ['class' => 'col-6 col-md-2']) ?>
        <?= select_field('branch_id', 'Branch', Coop::branchOptions(), fv('branch_id', null, Coop::defaultBranchId()), ['class' => 'col-md-3']) ?>
        <?= input_field('memo', 'Explanation', fv('memo', null), ['class' => 'col-md-5', 'required' => true]) ?>
      </div></div>
      <div class="card mb-3"><div class="card-body">
        <?= coop_grid('gLines', 'lines', ['sav_account_id' => ['Member savings / share capital account', 'select', $sacc], 'account_id' => ['or GL account', 'select', $accounts],
            'member_id' => ['Member (for a member ledger account)', 'select', Coop::memberOptions()], 'memo' => ['Memo', 'text'], 'debit' => ['Debit', 'money', [], 'data-jv-dr'], 'credit' => ['Credit', 'money', [], 'data-jv-cr']],
            coop_grid_posted('lines'), 2, 'Add a line') ?>
        <div class="d-flex justify-content-end gap-4 mt-2"><div>Debits <strong data-total-of="[data-jv-dr]">0.00</strong></div><div>Credits <strong data-total-of="[data-jv-cr]">0.00</strong></div></div>
        <div class="small text-muted mt-2">A line on a member's savings or share capital account updates that account's ledger as a credit memo (CM) or debit memo (DM). Loan balances are adjusted on Loans &gt; Loan Adjustments.</div>
      </div></div>
      <?= Approval::fields() ?>
      <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-check-lg"></i> Post voucher</button>
    </form>
    <?php
    layout_end();
    exit;
}
coop_voucher_list($pageKey, ['coop_jv', 'loan_release'], 'Cooperative Vouchers', ['actions' => page_link($pageKey, ['a' => 'new'], 'New journal voucher', 'btn-primary', 'plus-lg', 'create')]);
