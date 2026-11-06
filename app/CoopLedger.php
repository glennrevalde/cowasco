<?php
declare(strict_types=1);

/**
 * The bridge between the cooperative subsidiary ledgers (members' savings, loans, time deposits) and the
 * general ledger of the accounting system.
 *
 *  - post() records one balanced voucher (an official receipt, a member disbursement, a loan release or a
 *    cooperative journal voucher) through Txn::save(), so it is numbered, posted by the posting engine, kept
 *    in the audit trail, shown in every ledger report and protected by the closing date like any other
 *    transaction. The subsidiary rows (sav_txns, loan_txns ...) carry the id of that transaction.
 *  - onVoid() is called by Txn::void() for those transaction types: the subsidiary rows of the voided
 *    voucher are marked void and the member balances are rebuilt from what remains, in the same database
 *    transaction, so the member ledgers and the general ledger never disagree.
 */
final class CoopLedger
{
    /** The class chosen on the form that is posting (withClass()): it goes on the voucher and on every line */
    private static ?int $classId = null;

    /**
     * Posts what $fn posts under the class chosen on the form (Chart of Accounts > Class List): the voucher and every
     * one of its ledger lines carry that class, whatever branch the member or account belongs to.
     */
    public static function withClass(?int $classId, callable $fn)
    {
        $prev = self::$classId;
        self::$classId = $classId ?: null;
        try {
            return $fn();
        } finally {
            self::$classId = $prev;
        }
    }

    /** The class being posted under, if a form chose one */
    public static function currentClass(): ?int
    {
        return self::$classId;
    }

    /**
     * @param array $h     txn_date, memo, branch_id (gives the class), member_id (gives the name), account_id
     *                     (the cash / bank account for receipts, disbursements and releases), ref_no, txn_no, reason
     * @param array $lines each: account_id, debit | credit (decimal strings), memo, member_id (optional, for the SL)
     * @return int the transactions.id
     */
    public static function post(string $type, array $h, array $lines, array $opt = []): int
    {
        if (!in_array($type, Txn::COOP_TYPES, true)) {
            throw new RuntimeException('Not a cooperative transaction type: ' . $type);
        }
        $rows = [];
        $dr = '0';
        $cr = '0';
        $n = 0;
        foreach ($lines as $l) {
            $debit = Dec::round($l['debit'] ?? '0', 2);
            $credit = Dec::round($l['credit'] ?? '0', 2);
            if (Dec::isNeg($debit) || Dec::isNeg($credit)) {
                throw new RuntimeException('A ledger line cannot be negative; swap debit and credit instead.');
            }
            if (Dec::isZero($debit) && Dec::isZero($credit)) {
                continue;
            }
            if (!Dec::isZero($debit) && !Dec::isZero($credit)) {
                throw new RuntimeException('A ledger line is either a debit or a credit.');
            }
            if (empty($l['account_id'])) {
                fail('A ledger line has no account. Check Financial > Module Default Accounts (Cooperative) and the product accounts.');
            }
            $memberId = $l['member_id'] ?? ($h['member_id'] ?? null);
            $rows[] = [
                'line_kind'     => 'journal',
                'line_no'       => ++$n,
                'account_id'    => (int)$l['account_id'],
                'description'   => mb_substr((string)($l['memo'] ?? ($h['memo'] ?? '')), 0, 255) ?: null,
                'qty'           => 0,
                'rate'          => 0,
                'amount'        => Dec::isZero($debit) ? Dec::fix(Dec::neg($credit)) : Dec::fix($debit),
                'other_name_id' => $memberId ? self::nameOf((int)$memberId) : null,
                'class_id'      => self::$classId ?? ($l['class_id'] ?? Coop::branchClassId($h['branch_id'] ?? null)),
            ];
            $dr = Dec::add($dr, $debit);
            $cr = Dec::add($cr, $credit);
        }
        if (!$rows) {
            fail('Nothing to post: every amount is zero.');
        }
        if (Dec::cmp($dr, $cr) !== 0) {
            throw new RuntimeException('The entry does not balance (debits ' . Dec::money($dr) . ', credits ' . Dec::money($cr) . ').');
        }
        $header = [
            'txn_date'      => $h['txn_date'],
            'memo'          => mb_substr((string)($h['memo'] ?? ''), 0, 255) ?: null,
            'ref_no'        => isset($h['ref_no']) ? mb_substr((string)$h['ref_no'], 0, 60) : null,
            'account_id'    => $h['account_id'] ?? null,
            'other_name_id' => !empty($h['member_id']) ? self::nameOf((int)$h['member_id']) : null,
            'class_id'      => self::$classId ?? ($h['class_id'] ?? Coop::branchClassId($h['branch_id'] ?? null)),
            'subtotal'      => Dec::fix($dr),
            'total'         => Dec::fix($dr),
            'status'        => 'open',
            'payee_name'    => isset($h['payee_name']) ? mb_substr((string)$h['payee_name'], 0, 120) : null,
        ];
        if (!empty($h['txn_no'])) {
            $header['txn_no'] = mb_substr((string)$h['txn_no'], 0, 30);
        }
        if (!empty($h['approved_by'])) {
            $header['approved_by'] = (int)$h['approved_by'];
        }
        return Txn::save($type, $header, $rows, null, [
            'keep_totals' => true,
            'reason'      => $h['reason'] ?? null,
            'approved_by' => $opt['approved_by'] ?? null,
            'audit_action' => $opt['audit_action'] ?? null,
        ] + (isset($opt['audit_action']) ? [] : []));
    }

    /**
     * A loan release paid out through Banking (LoanVouchers): a Cash Voucher (cash_voucher) or a check (check). $lines
     * are every line of the entry except the cash / bank side, which the posting engine credits for $h['amount'] to
     * $h['account_id'] (the cash account of the voucher, the bank account of the check). The voucher and every line
     * carry the class of the form (withClass()) and the member as the name of the line (the SL of the voucher).
     * @param array $h txn_date, memo, branch_id, member_id, account_id, amount (the cash paid out), ref_no, txn_no (the
     *                 cheque no. of a check; a cash voucher takes the next CAV number), approved_by, reason
     */
    public static function postVoucher(string $type, array $h, array $lines, array $opt = []): int
    {
        if (!in_array($type, LoanVouchers::TYPES, true)) {
            throw new RuntimeException('Not a Banking voucher type: ' . $type);
        }
        if (empty($h['account_id'])) {
            fail($type === 'check' ? 'Choose the bank account the cheque is drawn on.' : 'Choose the cash account the loan is released from.');
        }
        $amount = Dec::round($h['amount'] ?? '0', 2);
        if (!Dec::isPos($amount)) {
            throw new RuntimeException('A voucher pays out more than zero.');
        }
        $rows = [];
        $net = '0';
        $n = 0;
        foreach ($lines as $l) {
            $debit = Dec::round($l['debit'] ?? '0', 2);
            $credit = Dec::round($l['credit'] ?? '0', 2);
            if (Dec::isNeg($debit) || Dec::isNeg($credit)) {
                throw new RuntimeException('A ledger line cannot be negative; swap debit and credit instead.');
            }
            if (Dec::isZero($debit) && Dec::isZero($credit)) {
                continue;
            }
            if (empty($l['account_id'])) {
                fail('A ledger line has no account. Check Financial > Module Default Accounts (Cooperative) and the product accounts.');
            }
            if ((int)$l['account_id'] === (int)$h['account_id']) {
                fail('The ' . ($type === 'check' ? 'bank' : 'cash') . ' account of the voucher cannot also be a line of it.');
            }
            $memberId = $l['member_id'] ?? ($h['member_id'] ?? null);
            $amt = Dec::sub($debit, $credit, 2);
            $rows[] = [
                'line_kind'     => $type === 'check' ? 'expense' : 'journal',
                'line_no'       => ++$n,
                'account_id'    => (int)$l['account_id'],
                'description'   => mb_substr((string)($l['memo'] ?? ($h['memo'] ?? '')), 0, 255) ?: null,
                'qty'           => 0,
                'rate'          => 0,
                'amount'        => Dec::fix($amt),
                'other_name_id' => $memberId ? self::nameOf((int)$memberId) : null,
                'class_id'      => self::$classId ?? ($l['class_id'] ?? Coop::branchClassId($h['branch_id'] ?? null)),
            ];
            $net = Dec::add($net, $amt, 2);
        }
        if (Dec::cmp($net, $amount) !== 0) {
            throw new RuntimeException('The voucher does not balance (lines ' . Dec::money($net) . ', paid out ' . Dec::money($amount) . ').');
        }
        $header = [
            'txn_date'      => $h['txn_date'],
            'memo'          => mb_substr((string)($h['memo'] ?? ''), 0, 255) ?: null,
            'ref_no'        => isset($h['ref_no']) ? mb_substr((string)$h['ref_no'], 0, 60) : null,
            'account_id'    => (int)$h['account_id'],
            'other_name_id' => !empty($h['member_id']) ? self::nameOf((int)$h['member_id']) : null,
            'class_id'      => self::$classId ?? ($h['class_id'] ?? Coop::branchClassId($h['branch_id'] ?? null)),
            'subtotal'      => Dec::fix($amount),
            'total'         => Dec::fix($amount),
            'status'        => 'open',
        ];
        if (!empty($h['txn_no'])) {
            $header['txn_no'] = mb_substr((string)$h['txn_no'], 0, 30);
        }
        if (!empty($h['approved_by'])) {
            $header['approved_by'] = (int)$h['approved_by'];
        }
        $save = ['keep_totals' => true, 'reason' => $h['reason'] ?? null, 'approved_by' => $opt['approved_by'] ?? null];
        // a loan release stays in the line of business of the cooperative's vouchers (savings and credit)
        if (BusinessUnits::ready()) {
            $save['business_unit_id'] = BusinessUnits::defaultFor('loan_release');
        }
        return Txn::save($type, $header, $rows, null, $save);
    }

    private static function nameOf(int $memberId): ?int
    {
        static $cache = [];
        if (!array_key_exists($memberId, $cache)) {
            $m = Coop::member($memberId);
            $cache[$memberId] = $m ? Coop::otherNameId($m) : null;
        }
        return $cache[$memberId];
    }

    /** The posted lines of a voucher (for printing and for building adjusting entries) */
    public static function lines(int $txnId): array
    {
        return DB::all(
            'SELECT jl.*, a.code AS account_code, a.name AS account_name, o.name AS other_name
               FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id LEFT JOIN other_names o ON o.id = jl.other_name_id
              WHERE jl.txn_id = :t ORDER BY jl.id',
            ['t' => $txnId]
        );
    }

    // ------------------------------------------------------------------ voiding

    /** Why a cooperative voucher cannot be voided right now (shown before the void is attempted) */
    public static function voidBlockers(array $t): array
    {
        $out = [];
        $id = (int)$t['id'];
        // a check on this voucher was cleared or returned since: that voucher is voided first
        $clearing = DB::value('SELECT x.txn_no FROM sav_txns s JOIN transactions x ON x.id = s.cleared_txn_id WHERE s.txn_id = :t AND s.is_voided = 0 AND x.is_voided = 0 LIMIT 1', ['t' => $id])
            ?: DB::value('SELECT x.txn_no FROM coop_receipts r JOIN transactions x ON x.id = r.check_cleared_txn_id WHERE r.txn_id = :t AND x.is_voided = 0 LIMIT 1', ['t' => $id]);
        if ($clearing) {
            $out[] = 'Its check was cleared or returned on voucher ' . $clearing . '. Void that voucher first.';
        }
        // a share capital deposit collected on a water receipt (Fixed Share Capital Billing), or its reversal: the
        // receipt is corrected on Collection Corrections, which takes the deposit back and opens the bill again
        if (class_exists('ShareCapitalBilling') && ($rcpt = ShareCapitalBilling::receiptOfLedgerTxn($id))) {
            $out[] = 'It is the share capital collected on water receipt ' . $rcpt . ' (Fixed Share Capital Billing). Correct the receipt on Water Billing > Collection Corrections instead.';
        }
        if (DB::value('SELECT id FROM coop_imports WHERE txn_id = :t', ['t' => $id])) {
            $out[] = 'It brought opening balances over from the previous system. Correct an opening balance with an adjusting entry (Loan Adjustments, a debit / credit memo), not by voiding the import.';
        }
        if (DB::value('SELECT id FROM dist_batches WHERE txn_id = :t', ['t' => $id])) {
            $out[] = 'It posted a year-end distribution batch. A distribution is corrected with an adjusting entry, not voided.';
        }
        if (DB::value('SELECT id FROM dist_periods WHERE allocation_txn_id = :t', ['t' => $id])) {
            $out[] = 'It posted the net surplus allocation of a fiscal year. Reverse it with an adjusting entry.';
        }
        // a loan payment negated by an error correction: the correction is voided first
        if (LoanTxn::ready()) {
            $corr = DB::value("SELECT t.txn_no FROM loan_txns o JOIN loan_txns e ON e.id = o.corrected_by_id AND e.is_voided = 0 JOIN transactions t ON t.id = e.txn_id
                                WHERE o.txn_id = :t AND o.is_voided = 0 LIMIT 1", ['t' => $id]);
            if ($corr) {
                $out[] = 'Its loan payment was negated by the error correction ' . $corr . '. Void the correction first.';
            }
        }
        // a loan release with payments on the loan
        $loan = DB::row("SELECT l.* FROM loan_accounts l WHERE l.release_txn_id = :t", ['t' => $id]);
        if ($loan) {
            $paid = (int)DB::value("SELECT COUNT(*) FROM loan_txns WHERE loan_id = :l AND is_voided = 0 AND kind <> 'release' AND txn_id <> :t", ['l' => $loan['id'], 't' => $id]);
            if ($paid > 0) {
                $out[] = 'Loan ' . $loan['loan_no'] . ' already has payments or adjustments. Void those first, or restructure the loan.';
            }
            if (in_array($loan['status'], ['paid', 'written_off', 'renewed'], true)) {
                $out[] = 'Loan ' . $loan['loan_no'] . ' is ' . $loan['status'] . ' and its release can no longer be voided.';
            }
        }
        // a savings movement whose reversal would overdraw the account
        foreach (DB::all('SELECT DISTINCT account_id FROM sav_txns WHERE txn_id = :t AND is_voided = 0', ['t' => $id]) as $r) {
            $short = Savings::simulateVoid((int)$r['account_id'], $id);
            if ($short !== null) {
                $acct = DB::row('SELECT account_no FROM sav_accounts WHERE id = :id', ['id' => $r['account_id']]);
                $out[] = 'Voiding it would overdraw savings account ' . ($acct['account_no'] ?? $r['account_id']) . ' (balance would fall to ' . Dec::money($short) . '). Void the later withdrawals first.';
            }
        }
        // a time deposit that has since matured or been paid out
        $td = DB::row('SELECT * FROM td_placements WHERE placement_txn_id = :t AND renewed_from_id IS NULL', ['t' => $id]);
        if ($td && $td['status'] !== 'active') {
            $out[] = 'Time deposit ' . $td['certificate_no'] . ' has already been ' . $td['status'] . '; its placement cannot be voided.';
        }
        if ($td && (int)DB::value('SELECT COUNT(*) FROM td_accruals WHERE placement_id = :p', ['p' => $td['id']]) > 0) {
            $out[] = 'Time deposit ' . $td['certificate_no'] . ' has interest accruals posted. Void the accrual vouchers first.';
        }
        // a settlement that renewed the deposit carries the new placement on the same voucher: both are undone together,
        // as long as the new placement has not moved on (accrued, matured or paid)
        foreach (DB::all('SELECT o.certificate_no, n.certificate_no AS new_cert, n.status AS new_status, n.placement_txn_id AS new_txn,
                                 (SELECT COUNT(*) FROM td_accruals x WHERE x.placement_id = n.id) AS new_accruals
                            FROM td_placements o JOIN td_placements n ON n.id = o.renewed_to_id WHERE o.settled_txn_id = :t', ['t' => $id]) as $r) {
            if ((int)$r['new_txn'] !== $id) {
                $out[] = 'Time deposit ' . $r['certificate_no'] . ' was renewed as ' . $r['new_cert'] . ' on another voucher; void that one first.';
            } elseif ($r['new_status'] !== 'active' || (int)$r['new_accruals'] > 0) {
                $out[] = 'The renewal ' . $r['new_cert'] . ' already has accruals or was settled; it can no longer be undone by a void.';
            }
        }
        // a receipt of a closed teller shift: only a user who may close shifts (approve on the shift page) may void it
        $rc = DB::row('SELECT r.*, s.status AS shift_status FROM coop_receipts r LEFT JOIN teller_shifts s ON s.id = r.shift_id WHERE r.txn_id = :t', ['t' => $id]);
        if ($rc && $rc['shift_status'] === 'closed' && !Access::can('teller.shift', 'approve')) {
            $out[] = 'The teller shift of this receipt is already closed. Only a user who may approve on Teller Shift & Cash Count can void it (or reverse it with an adjusting voucher).';
        }
        return $out;
    }

    /** Called by Txn::void() inside its transaction: reverses the subsidiary ledgers of the voided voucher */
    public static function onVoid(array $t, string $reason): void
    {
        $id = (int)$t['id'];
        if ($b = self::voidBlockers($t)) {
            fail(implode(' ', $b));
        }
        // the void of a check clearing (or of a returned check): the checks wait for clearing again
        $recleared = DB::column('SELECT DISTINCT account_id FROM sav_txns WHERE cleared_txn_id = :t', ['t' => $id]);
        DB::run('UPDATE sav_txns SET is_cleared = 0, cleared_txn_id = NULL WHERE cleared_txn_id = :t', ['t' => $id]);
        DB::run('UPDATE coop_receipts SET check_cleared_txn_id = NULL WHERE check_cleared_txn_id = :t', ['t' => $id]);
        // an error correction that is voided: the transaction it negated may be corrected again
        if (Savings::centerReady()) {
            DB::run('UPDATE sav_txns o JOIN sav_txns e ON e.id = o.corrected_by_id SET o.corrected_by_id = NULL WHERE e.txn_id = :t', ['t' => $id]);
        }
        // savings / share capital / time deposit movements
        $accounts = array_unique(array_merge(DB::column('SELECT DISTINCT account_id FROM sav_txns WHERE txn_id = :t AND is_voided = 0', ['t' => $id]), $recleared));
        DB::run('UPDATE sav_txns SET is_voided = 1 WHERE txn_id = :t', ['t' => $id]);
        foreach ($accounts as $aid) {
            Savings::rebuild((int)$aid);
        }
        // holds released by the void of a loan release are handled below; interest runs
        DB::run("UPDATE sav_interest_runs SET status = 'voided' WHERE txn_id = :t", ['t' => $id]);
        DB::run("UPDATE sav_batches SET status = 'voided' WHERE txn_id = :t", ['t' => $id]);
        // time deposits
        foreach (DB::all('SELECT * FROM td_placements WHERE placement_txn_id = :t', ['t' => $id]) as $td) {
            DB::update('td_placements', ['status' => 'cancelled', 'notes' => mb_substr('Placement voided: ' . $reason, 0, 255)], 'id = :id', ['id' => $td['id']]);
            DB::update('sav_accounts', ['status' => 'closed', 'closed_on' => $t['txn_date'], 'close_reason' => mb_substr('Placement voided: ' . $reason, 0, 255)], 'id = :id', ['id' => $td['account_id']]);
        }
        $payouts = self::tdPayoutsReady();
        foreach (DB::all('SELECT * FROM td_placements WHERE settled_txn_id = :t', ['t' => $id]) as $td) {
            // the interest paid before the settlement is what was withdrawn as accrued interest (CAIW); the accrual
            // payable the settlement cleared stands again as accrued
            $paid = $payouts ? DB::row('SELECT COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(accrued_used),0) AS used FROM td_interest_payouts WHERE placement_id = :p', ['p' => $td['id']]) : ['amount' => '0', 'used' => '0'];
            $upd = ['status' => 'active', 'settled_txn_id' => null, 'settled_on' => null, 'preterm_rate' => null, 'preterm_fee' => null, 'renewed_to_id' => null,
                'interest_paid' => Dec::fix($paid['amount']), 'accrued_interest' => Dec::fix(DB::value('SELECT COALESCE(SUM(amount),0) FROM td_accruals WHERE placement_id = :p', ['p' => $td['id']]))];
            if ($payouts) {
                $upd['accrued_paid'] = Dec::fix($paid['used']);
            }
            DB::update('td_placements', $upd, 'id = :id', ['id' => $td['id']]);
            DB::update('sav_accounts', ['status' => 'active', 'closed_on' => null, 'close_reason' => null], 'id = :id', ['id' => $td['account_id']]);
        }
        // accrued interest withdrawn before maturity (CAIW): the withdrawal goes, and the interest paid is summed again
        if ($payouts) {
            foreach (DB::column('SELECT DISTINCT placement_id FROM td_interest_payouts WHERE txn_id = :t', ['t' => $id]) as $pid) {
                DB::run('DELETE FROM td_interest_payouts WHERE txn_id = :t AND placement_id = :p', ['t' => $id, 'p' => $pid]);
                $paid = DB::row('SELECT COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(accrued_used),0) AS used FROM td_interest_payouts WHERE placement_id = :p', ['p' => $pid]);
                DB::update('td_placements', ['interest_paid' => Dec::fix($paid['amount']), 'accrued_paid' => Dec::fix($paid['used'])], 'id = :id', ['id' => $pid]);
            }
        }
        // interest accruals of time deposits: the rows go, and the accrued figure is summed again from what is left
        $accrued = DB::column('SELECT DISTINCT placement_id FROM td_accruals WHERE txn_id = :t', ['t' => $id]);
        DB::run('DELETE FROM td_accruals WHERE txn_id = :t', ['t' => $id]);
        foreach ($accrued as $pid) {
            DB::run('UPDATE td_placements SET accrued_interest = (SELECT COALESCE(SUM(amount),0) FROM td_accruals WHERE placement_id = :p1) WHERE id = :p2', ['p1' => $pid, 'p2' => $pid]);
        }
        // loans: an error correction voided gives the payment it negated back
        if (LoanTxn::ready()) {
            DB::run('UPDATE loan_txns o JOIN loan_txns e ON e.id = o.corrected_by_id SET o.corrected_by_id = NULL WHERE e.txn_id = :t', ['t' => $id]);
        }
        // loans
        $loans = DB::column('SELECT DISTINCT loan_id FROM loan_txns WHERE txn_id = :t AND is_voided = 0', ['t' => $id]);
        DB::run('UPDATE loan_txns SET is_voided = 1 WHERE txn_id = :t', ['t' => $id]);
        DB::run('DELETE FROM loan_payment_alloc WHERE loan_txn_id IN (SELECT id FROM (SELECT id FROM loan_txns WHERE txn_id = :t) x)', ['t' => $id]);
        foreach ($loans as $lid) {
            $loan = DB::row('SELECT * FROM loan_accounts WHERE id = :id', ['id' => $lid]);
            if ($loan && $loan['status'] === 'renewed' && $loan['restructured_to_id']
                && (int)DB::value('SELECT release_txn_id FROM loan_accounts WHERE id = :n', ['n' => $loan['restructured_to_id']]) === $id) {
                // the release that paid this loan off by renewal is voided: the loan is open again
                DB::update('loan_accounts', ['status' => 'current', 'restructured_to_id' => null, 'closed_on' => null], 'id = :id', ['id' => $lid]);
            }
            if ($loan && (int)$loan['release_txn_id'] === $id) {
                // the release itself: the loan is cancelled and the application goes back to approved
                DB::update('loan_accounts', ['status' => 'cancelled', 'principal_balance' => 0, 'interest_balance' => 0, 'penalty_balance' => 0,
                    'notes' => mb_substr('Release voided: ' . $reason, 0, 255)], 'id = :id', ['id' => $lid]);
                if ($loan['application_id']) {
                    DB::update('loan_applications', ['status' => 'approved', 'loan_id' => null], 'id = :id', ['id' => $loan['application_id']]);
                }
                DB::run('UPDATE sav_holds SET released_at = NOW(), released_by = :u WHERE loan_account_id = :l AND released_at IS NULL', ['u' => Auth::id(), 'l' => $lid]);
                Savings::refreshHolds((int)$lid);
                continue;
            }
            Loans::rebuild((int)$lid);
        }
        DB::run("UPDATE loan_waivers SET status = 'voided' WHERE txn_id = :t", ['t' => $id]);
        DB::run("UPDATE loan_adjustments SET memo = CONCAT(COALESCE(memo,''), ' [voided]') WHERE txn_id = :t", ['t' => $id]);
        // teller shift totals
        $shift = DB::value('SELECT shift_id FROM coop_receipts WHERE txn_id = :t', ['t' => $id]);
        if ($shift) {
            Teller::refreshShift((int)$shift);
        }
        $periodId = DB::value('SELECT id FROM dist_periods WHERE allocation_txn_id = :t', ['t' => $id]);
        if ($periodId) {
            DB::update('dist_periods', ['status' => 'approved', 'allocation_txn_id' => null], 'id = :id', ['id' => $periodId]);
        }
    }

    /** True once the accrued interest withdrawals of time deposits are kept (upgrade 2026_12_27) */
    private static function tdPayoutsReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM td_interest_payouts WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * An adjusting voucher that reverses a posted voucher line for line (used where a void is not allowed:
     * distributions, closed periods). Returns the new transaction id.
     */
    public static function reverse(int $txnId, string $date, string $memo): int
    {
        $t = Txn::load($txnId);
        if (!$t) {
            fail('Transaction not found.');
        }
        $lines = [];
        foreach (self::lines($txnId) as $l) {
            $lines[] = ['account_id' => (int)$l['account_id'], 'debit' => $l['credit'], 'credit' => $l['debit'], 'memo' => $l['memo'],
                'member_id' => null, 'class_id' => $l['class_id']];
            // keep the same name on the line
            $lines[count($lines) - 1]['other_name_id'] = $l['other_name_id'];
        }
        $rows = [];
        $n = 0;
        foreach ($lines as $l) {
            $amt = Dec::sub($l['debit'], $l['credit']);
            $rows[] = ['line_kind' => 'journal', 'line_no' => ++$n, 'account_id' => $l['account_id'], 'description' => $l['memo'], 'qty' => 0, 'rate' => 0,
                'amount' => Dec::fix($amt), 'other_name_id' => $l['other_name_id'], 'class_id' => $l['class_id']];
        }
        return Txn::save('coop_jv', ['txn_date' => $date, 'memo' => mb_substr('Reversal of ' . Txn::ref($t) . ': ' . $memo, 0, 255), 'class_id' => $t['class_id'],
            'other_name_id' => $t['other_name_id'], 'source_txn_id' => $txnId, 'subtotal' => $t['total'], 'total' => $t['total'], 'status' => 'open'], $rows, null, ['keep_totals' => true]);
    }
}
