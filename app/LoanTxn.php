<?php
declare(strict_types=1);

/**
 * Loan Collection Form (the cooperative's former program): the figures of the form (balances, what is due as of the
 * date and how many amortizations it makes), the error correction of an erroneous payslip, the deletion of a
 * collection entered by mistake, and the machine validation of the payslip.
 *
 * An error correction is a transaction of its own: it negates the payment in full on the loan ledger (the amounts
 * negative in the columns of the payment, so the passbook keeps both lines), reverses the receipt's journal entry line
 * for line, and puts the schedule, the balances and the status of the loan back as they were before the payment.
 */
final class LoanTxn
{
    private static ?bool $ready = null;

    /** True once loan_txns can link a correction to the payment it negates (upgrade 2027_01_03) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (bool)DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'loan_txns' AND column_name = 'corrects_id'");
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function reset(): void
    {
        self::$ready = null;
    }

    // ------------------------------------------------------------------ the figures of the form

    /** The member's loans that can be paid, oldest release first */
    public static function openLoans(int $memberId): array
    {
        [$bf, $bp] = Coop::branchFilter('l.branch_id');
        return DB::all("SELECT l.*, p.name AS product_name, p.code AS product_code FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id
                         WHERE l.member_id = :m AND l.status IN ('current','past_due','restructured')" . $bf . ' ORDER BY l.release_date, l.id', ['m' => $memberId] + $bp);
    }

    /**
     * What the form shows for a loan as of a date: for the principal, the interest and the penalty, the balance, what
     * is due, and the number of amortizations that makes; the last payment date and the payoff.
     */
    public static function dueDetails(array $loan, string $date): array
    {
        $d = Loans::dues($loan, $date);
        // one amortization: the scheduled principal and interest of the first instalment not yet paid
        $inst = DB::row('SELECT principal, interest FROM loan_schedule WHERE loan_id = :l AND is_paid = 0 ORDER BY inst_no LIMIT 1', ['l' => $loan['id']])
            ?: DB::row('SELECT principal, interest FROM loan_schedule WHERE loan_id = :l ORDER BY inst_no LIMIT 1', ['l' => $loan['id']]);
        $count = function (string $due, $per): string {
            $per = Dec::of($per ?? '0');
            return Dec::isPos($per) && Dec::isPos($due) ? Dec::fix(Dec::div($due, $per, 6), 2) : '0.00';
        };
        $overdue = (int)DB::value('SELECT COUNT(*) FROM loan_schedule WHERE loan_id = :l AND is_paid = 0 AND due_date < :d', ['l' => $loan['id'], 'd' => $date]);
        return [
            'principal' => ['balance' => Dec::fix($loan['principal_balance']), 'due' => Dec::fix($d['principal_due']), 'count' => $count($d['principal_due'], $inst['principal'] ?? '0')],
            'interest' => ['balance' => Dec::fix($loan['interest_balance']), 'due' => Dec::fix($d['interest_due']), 'count' => $count($d['interest_due'], $inst['interest'] ?? '0')],
            'penalty' => ['balance' => Dec::fix(Dec::add($loan['penalty_balance'], $d['penalty_new'], 2)), 'due' => Dec::fix($d['penalty_due']), 'count' => number_format($overdue, 2, '.', '')],
            'total_due' => Dec::fix($d['total_due']), 'payoff' => Dec::fix($d['payoff']), 'interest_to_date' => Dec::fix($d['interest_to_date']),
            'count_due' => (int)$d['count_due'], 'next_due' => $d['next_due'], 'last_payment' => $loan['last_payment_date'],
            'amortization' => Dec::fix(Dec::add($inst['principal'] ?? '0', $inst['interest'] ?? '0', 2)),
        ];
    }

    // ------------------------------------------------------------------ the collections of a period

    /** The payments and corrections posted between two dates (the list under the form) */
    /** The collections of a period (the payments of the former system brought in by Import Loans have no voucher and are not collections here) */
    public static function collections(string $from, string $to, string $q = ''): array
    {
        [$bf, $bp] = Coop::branchFilter('l.branch_id');
        $ec = self::ready() ? ", x.corrects_id, x.corrected_by_id" : ", NULL AS corrects_id, NULL AS corrected_by_id";
        $sql = "SELECT x.id, x.loan_id, x.txn_id, x.txn_date, x.kind, x.memo, x.or_no, x.pb_printed, x.created_by, x.created_at, x.teller_id $ec,
                       (x.principal_cr + x.interest_cr + x.penalty_cr) AS amount, l.loan_no, l.member_id, p.name AS product_name, m.full_name, m.member_no,
                       t.txn_no, t.txn_type, r.receipt_no, r.payment_mode
                  FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id JOIN loan_products p ON p.id = l.product_id JOIN members m ON m.id = l.member_id
                  LEFT JOIN transactions t ON t.id = x.txn_id LEFT JOIN coop_receipts r ON r.txn_id = x.txn_id
                 WHERE x.kind IN ('payment','correction') AND x.is_voided = 0 AND x.txn_id IS NOT NULL AND x.txn_date BETWEEN :f AND :t" . $bf;
        $p = ['f' => $from, 't' => $to] + $bp;
        if ($q !== '') {
            $sql .= ' AND (m.full_name LIKE :q1 OR m.member_no = :q2 OR l.loan_no LIKE :q3 OR r.receipt_no LIKE :q4 OR t.txn_no LIKE :q5)';
            $p += ['q1' => '%' . $q . '%', 'q2' => $q, 'q3' => $q . '%', 'q4' => $q . '%', 'q5' => $q . '%'];
        }
        return DB::all($sql . ' ORDER BY x.txn_date DESC, x.id DESC LIMIT 1000', $p);
    }

    /** The reference of a collection row: the OR number, or the voucher number of a correction */
    public static function ref(array $r): string
    {
        return (string)($r['receipt_no'] ?: ($r['or_no'] ?: ($r['txn_no'] ?? '')));
    }

    /** The payments of a loan an error correction may negate (not voided, not corrected, not a correction) */
    public static function correctable(int $loanId): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all("SELECT x.*, (x.principal_cr + x.interest_cr + x.penalty_cr) AS amount, r.receipt_no, r.payment_mode, r.check_cleared_txn_id
                          FROM loan_txns x LEFT JOIN coop_receipts r ON r.txn_id = x.txn_id
                         WHERE x.loan_id = :l AND x.kind = 'payment' AND x.is_voided = 0 AND x.corrected_by_id IS NULL AND x.corrects_id IS NULL AND x.txn_id IS NOT NULL
                         ORDER BY x.txn_date DESC, x.id DESC LIMIT 30", ['l' => $loanId]);
    }

    /**
     * The payments of all the member's loans an error correction may negate, the latest first. A loan the payment paid
     * off is included: the correction opens it again.
     */
    public static function correctableForMember(int $memberId): array
    {
        if (!self::ready()) {
            return [];
        }
        [$bf, $bp] = Coop::branchFilter('l.branch_id');
        return DB::all("SELECT x.*, (x.principal_cr + x.interest_cr + x.penalty_cr) AS amount, r.receipt_no, r.payment_mode, r.check_cleared_txn_id, l.loan_no, p.name AS product_name
                          FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id JOIN loan_products p ON p.id = l.product_id LEFT JOIN coop_receipts r ON r.txn_id = x.txn_id
                         WHERE l.member_id = :m AND l.status IN ('current','past_due','restructured','paid') AND x.kind = 'payment' AND x.is_voided = 0
                           AND x.corrected_by_id IS NULL AND x.corrects_id IS NULL AND x.txn_id IS NOT NULL" . $bf . '
                         ORDER BY x.txn_date DESC, x.id DESC LIMIT 40', ['m' => $memberId] + $bp);
    }

    // ------------------------------------------------------------------ error correct

    /**
     * Negates an erroneous loan payment in full. $loanTxnId is the payment row of the loan ledger. Returns
     * ['txn_id', 'loan_txn_id', 'ref', 'amount'].
     */
    public static function errorCorrect(int $loanTxnId, string $date, string $memo, string $pageKey): array
    {
        if (!self::ready()) {
            fail('Run the upgrade 2027_01_03_loan_collection_error_correct.sql first (System Administration > System Updates).');
        }
        return DB::tx(function () use ($loanTxnId, $date, $memo, $pageKey) {
            $p = DB::row('SELECT * FROM loan_txns WHERE id = :id FOR UPDATE', ['id' => $loanTxnId]);
            if (!$p || $p['kind'] !== 'payment') {
                fail('Choose the erroneous payment of the loan.');
            }
            if ((int)$p['is_voided'] === 1) {
                fail('That payment was deleted (voided); there is nothing to correct.');
            }
            if (!empty($p['corrected_by_id'])) {
                fail('That payment was already corrected.');
            }
            $loan = Loans::loan((int)$p['loan_id'], true);
            Coop::requireBranch($loan['branch_id'] ? (int)$loan['branch_id'] : null);
            $t = $p['txn_id'] ? Txn::load((int)$p['txn_id']) : null;
            if (!$t || (int)$t['is_voided'] === 1) {
                fail('The receipt of that payment is not on file or was voided.');
            }
            $rc = Teller::receiptByTxn((int)$t['id']);
            if (!$rc) {
                fail('That payment has no official receipt; correct it with a loan adjustment.');
            }
            $others = (int)DB::value("SELECT COUNT(*) FROM coop_receipt_lines WHERE receipt_id = :r AND NOT (kind IN ('loan_principal','loan_interest','loan_penalty') AND ref_id = :l)",
                ['r' => $rc['id'], 'l' => $loan['id']]);
            if ($others > 0) {
                fail('Official receipt ' . $rc['receipt_no'] . ' also holds other items. Void the receipt with the reason instead (Official Receipt List), then enter it again.');
            }
            if ($rc['payment_mode'] === 'check' && !empty($rc['check_cleared_txn_id'])) {
                fail('The check of official receipt ' . $rc['receipt_no'] . ' was already cleared to the bank. Record a dishonored check as a returned check, or correct the loan with a loan adjustment.');
            }
            if ($rc['payment_mode'] === 'savings') {
                fail('Official receipt ' . $rc['receipt_no'] . ' was paid from savings. Void the receipt with the reason instead.');
            }
            Coop::checkDate($date);
            if ($date < $p['txn_date']) {
                fail('The correction cannot be dated before the payment (' . fdate($p['txn_date']) . ').');
            }
            // correcting another teller's payment, or one of an earlier day, needs a supervisor
            $needs = ((int)$p['created_by'] !== Auth::id() || substr((string)$p['created_at'], 0, 10) !== today())
                ? 'error correction of official receipt ' . $rc['receipt_no'] . ' on loan ' . $loan['loan_no'] : '';
            $approver = Approval::check($pageKey, $needs !== '', $needs);
            $cash = $rc['payment_mode'] === 'cash';
            $shift = null;
            if ($cash) {
                $shift = Teller::requireShift();
            }
            // the journal entry: the receipt's entry reversed line for line
            $gl = [];
            foreach (CoopLedger::lines((int)$t['id']) as $l) {
                $lm = mb_substr('Error correct: ' . (string)($l['memo'] ?? ''), 0, 255);
                if (Dec::isPos($l['debit'])) {
                    $gl[] = ['account_id' => (int)$l['account_id'], 'credit' => Dec::fix($l['debit']), 'memo' => $lm];
                } elseif (Dec::isPos($l['credit'])) {
                    $gl[] = ['account_id' => (int)$l['account_id'], 'debit' => Dec::fix($l['credit']), 'memo' => $lm];
                }
            }
            $total = Dec::of($rc['total']);
            $memoText = trim($memo);
            $no = $cash ? Coop::nextReceiptNo('cv', $loan['branch_id'] ? (int)$loan['branch_id'] : null) : null;
            $txnId = CoopLedger::post($cash ? 'coop_cv' : 'coop_jv', [
                'txn_date' => $date, 'memo' => 'Error correct OR ' . $rc['receipt_no'] . ' ' . $loan['loan_no'] . ' ' . $loan['member_name'] . ($memoText !== '' ? ': ' . $memoText : ''),
                'branch_id' => $loan['branch_id'], 'member_id' => $loan['member_id'], 'account_id' => $cash ? (int)$rc['cash_account_id'] : null, 'txn_no' => $no,
                'ref_no' => 'OR ' . $rc['receipt_no'], 'reason' => 'Error correction', 'approved_by' => $approver['id'] ?? null,
            ], $gl, ['approved_by' => $approver['username'] ?? null]);
            $ref = $no ?? (string)Txn::load($txnId)['txn_no'];
            // the loan ledger: each row the receipt made on the loan (the payment, and the rebate of a payoff) negated
            $ecId = 0;
            foreach (DB::all('SELECT * FROM loan_txns WHERE txn_id = :t AND loan_id = :l AND is_voided = 0 AND corrects_id IS NULL ORDER BY id', ['t' => $t['id'], 'l' => $loan['id']]) as $r) {
                $id = DB::insert('loan_txns', ['loan_id' => $loan['id'], 'txn_date' => $date, 'kind' => 'correction',
                    'memo' => mb_substr('Error correct OR ' . $rc['receipt_no'] . ($memoText !== '' ? ': ' . $memoText : ''), 0, 160),
                    'principal_dr' => Dec::fix(Dec::neg($r['principal_dr'])), 'interest_dr' => Dec::fix(Dec::neg($r['interest_dr'])), 'penalty_dr' => Dec::fix(Dec::neg($r['penalty_dr'])),
                    'principal_cr' => Dec::fix(Dec::neg($r['principal_cr'])), 'interest_cr' => Dec::fix(Dec::neg($r['interest_cr'])), 'penalty_cr' => Dec::fix(Dec::neg($r['penalty_cr'])),
                    'txn_id' => $txnId, 'or_no' => $ref, 'teller_id' => Auth::id(), 'shift_id' => $shift['id'] ?? null, 'created_by' => Auth::id(), 'corrects_id' => $r['id']]);
                foreach (DB::all('SELECT * FROM loan_payment_alloc WHERE loan_txn_id = :t', ['t' => $r['id']]) as $al) {
                    DB::insert('loan_payment_alloc', ['loan_txn_id' => $id, 'schedule_id' => $al['schedule_id'], 'principal' => Dec::fix(Dec::neg($al['principal'])), 'interest' => Dec::fix(Dec::neg($al['interest']))]);
                }
                DB::update('loan_txns', ['corrected_by_id' => $id], 'id = :id', ['id' => $r['id']]);
                if ((int)$r['id'] === (int)$p['id']) {
                    $ecId = $id;
                }
            }
            // the schedule, the balances and the status follow the ledger again
            Loans::rebuild((int)$loan['id']);
            // a payoff corrected: the loan is open again, and so are the holds on the member's deposits its closing released
            if ($loan['status'] === 'paid' && !empty($loan['closed_on']) && in_array(Loans::loan((int)$loan['id'])['status'], Loans::OPEN_STATUSES, true)) {
                DB::run('UPDATE sav_holds SET released_at = NULL, released_by = NULL WHERE loan_account_id = :l AND released_at IS NOT NULL AND released_at >= :d',
                    ['l' => $loan['id'], 'd' => $loan['closed_on'] . ' 00:00:00']);
                Savings::refreshHolds((int)$loan['id']);
            }
            if ($rc['payment_mode'] === 'check') {
                // the checks no longer wait in the clearing account
                DB::update('coop_receipts', ['check_pending' => '0.00'], 'id = :id', ['id' => $rc['id']]);
            }
            if ($cash) {
                Teller::recordReceipt($txnId, 'cv', $ref, (int)$loan['member_id'], $loan['branch_id'] ? (int)$loan['branch_id'] : null, $shift['id'] ?? null, 'cash', (int)$rc['cash_account_id'], Dec::fix($total),
                    [['kind' => 'loan_principal', 'ref_id' => $loan['id'], 'account_id' => (int)$loan['receivable_account_id'], 'description' => 'Error correct OR ' . $rc['receipt_no'] . ' ' . $loan['loan_no'], 'amount' => Dec::fix($total)]]);
            }
            Audit::log('error_correct', 'loan', 'loan_txn', $ecId, 'OR ' . $rc['receipt_no'] . ' ' . $loan['loan_no'], [
                'page_key' => $pageKey, 'amount' => (float)$total, 'reason' => 'Negates OR ' . $rc['receipt_no'] . ($memoText !== '' ? ': ' . $memoText : ''), 'approved_by' => $approver['username'] ?? null,
            ]);
            return ['txn_id' => $txnId, 'loan_txn_id' => $ecId, 'ref' => $ref, 'amount' => Dec::fix(Dec::neg($total)), 'loan_id' => (int)$loan['id']];
        });
    }

    // ------------------------------------------------------------------ delete

    /**
     * Deletes (voids) a collection entered by mistake that is not yet printed in the passbook. The teller's own
     * collection of the day goes at once; another teller's, or an earlier day's, needs a supervisor.
     */
    public static function delete(int $loanTxnId, string $reason, string $pageKey): void
    {
        $r = DB::row('SELECT * FROM loan_txns WHERE id = :id', ['id' => $loanTxnId]);
        if (!$r || !in_array($r['kind'], ['payment', 'correction'], true) || !$r['txn_id']) {
            fail('Choose the collection to delete.');
        }
        $ref = (string)($r['or_no'] ?: Txn::load((int)$r['txn_id'])['txn_no']);
        if ((int)$r['is_voided'] === 1) {
            fail('Collection ' . $ref . ' is already deleted.');
        }
        if ((int)$r['pb_printed'] === 1) {
            fail('Collection ' . $ref . ' is printed in the passbook. Correct it with Error Correct instead (the passbook keeps both lines).');
        }
        if (self::ready() && !empty($r['corrected_by_id'])) {
            fail('Collection ' . $ref . ' was corrected by an error correction. Delete the correction first.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for deleting the collection.');
        }
        $loan = Loans::loan((int)$r['loan_id']);
        Coop::requireBranch($loan['branch_id'] ? (int)$loan['branch_id'] : null);
        $own = (int)$r['created_by'] === Auth::id() && substr((string)$r['created_at'], 0, 10) === today();
        $approver = Approval::check($pageKey, !$own, 'deletion of loan collection ' . $ref);
        Txn::void((int)$r['txn_id'], 'Loan collection ' . $ref . ' deleted: ' . trim($reason), $approver);
    }

    // ------------------------------------------------------------------ the machine validation of the payslip

    /** The two lines of the payslip validation of a collection (a payment or its correction) */
    public static function validation(int $txnId): ?array
    {
        $r = DB::row("SELECT x.*, (x.principal_cr + x.interest_cr + x.penalty_cr) AS amount, l.loan_no, l.branch_id, m.full_name, r.receipt_no, t.txn_no
                        FROM loan_txns x JOIN loan_accounts l ON l.id = x.loan_id JOIN members m ON m.id = l.member_id
                        LEFT JOIN coop_receipts r ON r.txn_id = x.txn_id LEFT JOIN transactions t ON t.id = x.txn_id
                       WHERE x.txn_id = :t AND x.kind IN ('payment','correction') ORDER BY x.id LIMIT 1", ['t' => $txnId]);
        if (!$r) {
            return null;
        }
        $amount = Dec::of($r['amount']);
        $time = $r['created_at'] ? date('H:i', strtotime((string)$r['created_at'])) : '';
        return ['row' => $r, 'lines' => [
            [self::ref($r), (string)$r['loan_no'], date('m/d/Y', strtotime((string)$r['txn_date'])) . '  ' . $time],
            [(string)$r['full_name'], $r['kind'] === 'correction' ? 'ECLP' : 'LP', (Dec::isNeg($amount) ? '-' : '') . Dec::money(Dec::abs($amount)), (string)(int)($r['teller_id'] ?: $r['created_by'])],
        ]];
    }
}
