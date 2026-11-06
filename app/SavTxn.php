<?php
declare(strict_types=1);

/**
 * The Savings Transaction Center: every teller transaction on a savings, share capital or time deposit account is
 * posted by its transaction code (Savings Transaction Codes) and numbered in ONE series (sequence sav_trans).
 *
 *  - The code decides the journal entry. Its Savings Entry Side says whether the member's account is credited
 *    (a deposit) or debited (a withdrawal); its Opposite Account is either a GL account of its own (a direct bank
 *    deposit, a closing fee, the clearing account of a check deposit) or the "User Account": the cash account of
 *    the teller (the open shift's drawer, else the user's own cash account, else the cash account of the user's
 *    profile). A memo code takes the GL account chosen on the memo.
 *  - An error-correct code (Is Error Correct, Related Trans Code) negates a transaction of its related code that
 *    was entered by mistake: the member's ledger shows the same amount negative in the same column (so the
 *    passbook prints "-8,033.31" under withdrawals and the balance goes back), and the journal entry is the
 *    reverse of the original one. The original is marked corrected, so it cannot be negated twice.
 *  - Cash transactions are part of the teller's shift (a savings deposit slip SD or withdrawal slip SW carries
 *    the transaction number), so the cash count of the shift includes them.
 *
 * Every call runs inside DB::tx and locks the account row.
 */
final class SavTxn
{
    /** The transaction number the next transaction will get (nothing is taken) */
    public static function peek(): string
    {
        return Seq::peek('sav_trans') ?: '0000001';
    }

    /** One savings row with its account, member and code, for the forms and the center */
    public static function row(int $id): ?array
    {
        return DB::row('SELECT s.*, a.account_no, a.member_id, a.branch_id, a.product_id, p.code AS product_code, p.name AS product_name, p.product_type,
                               m.full_name, m.member_no, c.description AS code_description, c.entry_side, c.is_error_correct, c.related_code, c.is_memo,
                               u.username, u.full_name AS teller_name
                          FROM sav_txns s JOIN sav_accounts a ON a.id = s.account_id JOIN sav_products p ON p.id = a.product_id
                          JOIN members m ON m.id = a.member_id LEFT JOIN sav_txn_codes c ON c.code = s.txn_code LEFT JOIN users u ON u.id = s.teller_id
                         WHERE s.id = :id', ['id' => $id]);
    }

    /** The amount of a row as the center shows it: what moved, negative for an error correction */
    public static function amount(array $r): string
    {
        return Dec::isZero($r['credit']) ? Dec::of($r['debit']) : Dec::of($r['credit']);
    }

    /** The codes a teller may choose on the Savings Account Transaction form, by kind */
    public static function codes(string $kind = 'teller'): array
    {
        $rows = DB::all('SELECT * FROM sav_txn_codes WHERE is_active = 1 ORDER BY code');
        $out = [];
        foreach ($rows as $c) {
            $memo = (int)($c['is_memo'] ?? 0) === 1;
            $ec = (int)$c['is_error_correct'] === 1;
            // the codes the system posts by itself (interest, transfers, batch deductions, maturity ...) are not
            // offered; cash deposit and withdrawal are
            if ((int)$c['is_system'] === 1 && !in_array($c['code'], ['CSD', 'CSW'], true)) {
                continue;
            }
            if ($kind === 'memo' && !$memo) {
                continue;
            }
            if ($kind === 'teller' && $memo) {
                continue;
            }
            if ($kind === 'ec' && !$ec) {
                continue;
            }
            if ($kind !== 'ec' && $ec) {
                continue;
            }
            $out[$c['code']] = $c;
        }
        return $out;
    }

    /** The error-correct code of a code (ECSD for CSD), or null when none is set up */
    public static function ecCodeFor(string $code): ?array
    {
        return DB::row('SELECT * FROM sav_txn_codes WHERE is_error_correct = 1 AND is_active = 1 AND related_code = :c ORDER BY code LIMIT 1', ['c' => $code]) ?: null;
    }

    /**
     * Posts one transaction of the Savings Account Transaction form.
     * $f: account_id, code, amount, date, memo, back_entry, cheques [[cheque_no, bank, date, amount]], gl_account_id
     *     (memo codes), corrects_id (error correct: the row negated), closing (a closing withdrawal), ref_type, ref_id
     * Returns ['txn_id', 'sav_txn_id', 'trans_no', 'amount'].
     */
    public static function post(array $f, string $pageKey): array
    {
        return DB::tx(function () use ($f, $pageKey) {
            $c = Savings::code((string)($f['code'] ?? ''));
            if ((int)$c['is_active'] !== 1) {
                fail('Transaction code ' . $c['code'] . ' is not active.');
            }
            $date = (string)($f['date'] ?? today());
            if (!valid_date($date)) {
                fail('Enter the date.');
            }
            $back = !empty($f['back_entry']);
            if (!$back && $date !== today()) {
                fail('The transaction is dated today. Tick Back Entry to record it on an earlier date.');
            }
            if ($back && $date >= today()) {
                fail('A back entry is dated before today.');
            }
            // how far back: the policy "back-dated member transactions" (days allowed, or the special permission)
            Coop::checkDate($date);
            $amount = Dec::round((string)($f['amount'] ?? '0'), 2);
            if (!Dec::isPos($amount)) {
                fail('Enter the amount.');
            }
            $ec = (int)$c['is_error_correct'] === 1;
            $memoCode = (int)($c['is_memo'] ?? 0) === 1;
            $side = $c['entry_side'] === 'debit' ? 'debit' : 'credit';
            $orig = null;
            $needs = '';
            if ($ec) {
                $orig = DB::row('SELECT * FROM sav_txns WHERE id = :id FOR UPDATE', ['id' => (int)($f['corrects_id'] ?? 0)]);
                if (!$orig || (int)$orig['account_id'] !== (int)$f['account_id']) {
                    fail('Choose the transaction to correct (the erroneous one) from the list of the account.');
                }
                if ((int)$orig['is_voided'] === 1) {
                    fail('That transaction was voided; there is nothing to correct.');
                }
                if (!empty($orig['corrected_by_id'])) {
                    fail('That transaction was already corrected.');
                }
                if (in_array((string)$orig['ref_type'], ['sc_bill'], true)) {
                    fail('Transaction ' . ($orig['trans_no'] ?: '#' . $orig['id']) . ' is the share capital collected on water receipt ' . $orig['or_no']
                        . ' (Fixed Share Capital Billing). Correct the receipt on Water Billing > Collection Corrections instead.');
                }
                if (!empty($orig['corrects_id'])) {
                    fail('An error correction is not corrected again; enter the transaction again instead.');
                }
                if ($orig['txn_code'] !== $c['related_code']) {
                    fail('Code ' . $c['code'] . ' corrects ' . $c['related_code'] . ' transactions, and the transaction chosen is ' . $orig['txn_code'] . '.');
                }
                $origAmount = Dec::isZero($orig['credit']) ? Dec::of($orig['debit']) : Dec::of($orig['credit']);
                if (Dec::cmp($origAmount, $amount) !== 0) {
                    fail('An error correction negates the whole transaction: the amount is ' . Dec::money($origAmount) . '.');
                }
                if (Dec::isNeg($origAmount)) {
                    fail('That transaction is itself a correction.');
                }
                // the balance moves the other way of the code's side
                $effect = $side === 'credit' ? 'debit' : 'credit';
                // negating a check deposit that is still clearing takes out money that was never available: only the
                // ledger balance has to cover it
                $floating = (int)$orig['is_cleared'] === 0;
                $a = Savings::validate((int)$f['account_id'], $effect, $amount, $date, ['is_system' => true, 'closing' => true, 'allow_pending' => true, 'skip_available' => $floating]);
                if ($floating && Dec::cmp(Dec::sub($a['balance'], $a['hold_amount'], 2), $amount) < 0) {
                    fail('The balance of ' . $a['account_no'] . ' (' . Dec::money($a['balance']) . ') does not cover the correction.');
                }
                if ((int)$orig['created_by'] !== Auth::id() || substr((string)$orig['created_at'], 0, 10) !== today()) {
                    $needs = 'error correction of ' . $orig['txn_code'] . ' ' . ($orig['trans_no'] ?: '#' . $orig['id']) . ' (' . Dec::money($amount) . ')';
                }
            } else {
                $effect = $side;
                $a = $side === 'credit'
                    ? Savings::validate((int)$f['account_id'], 'credit', $amount, $date, ['allow_pending' => true])
                    : Savings::validate((int)$f['account_id'], 'debit', $amount, $date, ['closing' => !empty($f['closing'])]);
            }
            $m = Coop::member((int)$a['member_id']);
            Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);

            // the account on the other side of the entry
            $isCheck = !$ec && (int)$c['is_check_deposit'] === 1;
            $cash = false;
            if ((int)$c['is_check_deposit'] === 1) {
                $opp = (int)($c['opposite_account_id'] ?: Accts::get('coop_coci'));
            } elseif ($memoCode) {
                $opp = (int)($f['gl_account_id'] ?? 0) ?: ($orig['opposite_account_id'] ?? 0);
                if (!$opp) {
                    fail('Choose the GL account of the memo (the other side of the entry).');
                }
            } elseif ($c['opposite_kind'] === 'gl') {
                // a code "set by the process" (no account of its own) takes the account the process gives
                $opp = (int)$c['opposite_account_id'] ?: (int)($f['process_gl_account_id'] ?? 0);
                if (!$opp) {
                    fail('Code ' . $c['code'] . ' has no GL account. Set its Opposite Account on Savings Transaction Codes.');
                }
            } elseif ($ec && $orig && (int)$c['is_check_deposit'] === 0 && !empty($orig['opposite_account_id']) && !self::isCashAccount((int)$orig['opposite_account_id'])) {
                $opp = (int)$orig['opposite_account_id'];
            } else {
                Teller::requireShift();
                $opp = Teller::currentCashAccount();
                if (!$opp) {
                    fail('No cash account is assigned to you or your profile (System Administration > User Accounts or User Role Configuration > Cash account).');
                }
                $cash = true;
            }
            if (!DB::value('SELECT id FROM accounts WHERE id = :a', ['a' => $opp])) {
                fail('The GL account of code ' . $c['code'] . ' is not on the chart of accounts.');
            }

            // the cheques of a check deposit
            $cheques = [];
            if ($isCheck) {
                $sum = '0';
                foreach ((array)($f['cheques'] ?? []) as $ch) {
                    $amt = Dec::round((string)($ch['amount'] ?? '0'), 2);
                    if (!Dec::isPos($amt) && trim((string)($ch['cheque_no'] ?? '')) === '') {
                        continue;
                    }
                    if (trim((string)($ch['cheque_no'] ?? '')) === '') {
                        fail('Enter the cheque number of every cheque.');
                    }
                    if (!Dec::isPos($amt)) {
                        fail('Enter the amount of cheque ' . trim((string)$ch['cheque_no']) . '.');
                    }
                    $cheques[] = ['cheque_no' => mb_substr(trim((string)$ch['cheque_no']), 0, 40), 'bank' => mb_substr(trim((string)($ch['bank'] ?? '')), 0, 60),
                        'date' => valid_date((string)($ch['date'] ?? '')) ? $ch['date'] : $date, 'amount' => Dec::fix($amt)];
                    $sum = Dec::add($sum, $amt, 2);
                }
                if (!$cheques) {
                    fail('Enter the cheque details (cheque no., bank, date and amount) of the check deposit.');
                }
                if (Dec::cmp($sum, $amount) !== 0) {
                    fail('The cheques (' . Dec::money($sum) . ') must add up to the amount (' . Dec::money($amount) . ').');
                }
            }

            // approvals: the same rules as the member disbursement for money going out
            if (!$ec && $effect === 'debit') {
                if ($a['status'] === 'dormant') {
                    $needs = $needs ?: 'withdrawal from dormant account ' . $a['account_no'];
                }
                if ((int)$a['withdrawal_approval'] === 1) {
                    $needs = $needs ?: 'withdrawal from ' . $a['product_name'] . ' (the product requires approval)';
                }
                if ($a['product_type'] === 'share_capital') {
                    $needs = $needs ?: 'share capital withdrawal of an active member';
                }
                if (Approval::exceeds(Access::limit('coop_max_withdrawal'), (float)$amount)) {
                    $needs = $needs ?: 'withdrawal above your limit (' . Dec::money($amount) . ')';
                }
                $above = Dec::of(Coop::setting('withdrawal_approval_above', '0'));
                if (Dec::isPos($above) && Dec::cmp($amount, $above) > 0) {
                    $needs = $needs ?: 'withdrawal above the policy threshold of ' . Dec::money($above);
                }
            }
            $large = Dec::of(Coop::setting('large_txn_amount', '0'));
            if (!$ec && $effect === 'credit' && Dec::isPos($large) && Dec::cmp($amount, $large) > 0) {
                $needs = $needs ?: 'transaction above the large transaction threshold (' . Dec::money($amount) . ')';
            }
            if ($memoCode && !$ec) {
                $needs = $needs ?: ($side === 'credit' ? 'credit' : 'debit') . ' memo of ' . Dec::money($amount) . ' on ' . $a['account_no'];
            }
            $approver = Approval::check($pageKey, $needs !== '', $needs);

            $transNo = Seq::next('sav_trans');
            $liab = Savings::ledgerAccount($a);
            $memoText = trim((string)($f['memo'] ?? ''));
            $desc = $memoText !== '' ? mb_substr($memoText, 0, 160) : ($ec ? 'Error correct ' . $orig['txn_code'] . ' ' . ($orig['trans_no'] ?: '') : $c['description']);
            $line = $c['code'] . ' ' . $c['description'] . ' ' . $a['account_no'];
            $gl = $effect === 'credit'
                ? [['account_id' => $opp, 'debit' => $amount, 'memo' => $line], ['account_id' => $liab, 'credit' => $amount, 'memo' => $line, 'member_id' => $a['member_id']]]
                : [['account_id' => $liab, 'debit' => $amount, 'memo' => $line, 'member_id' => $a['member_id']], ['account_id' => $opp, 'credit' => $amount, 'memo' => $line]];
            $txnId = CoopLedger::post('coop_sv', [
                'txn_date' => $date, 'memo' => $transNo . ' ' . $c['code'] . ' ' . $m['full_name'] . ' ' . $a['account_no'] . ($memoText !== '' ? ': ' . $memoText : ''),
                'branch_id' => $a['branch_id'], 'member_id' => $a['member_id'], 'account_id' => $cash ? $opp : null, 'txn_no' => $transNo,
                'ref_no' => $cheques ? mb_substr(implode(', ', array_column($cheques, 'cheque_no')), 0, 60) : null,
                'approved_by' => $approver['id'] ?? null, 'reason' => $back ? 'Back entry' : ($ec ? 'Error correction' : null),
            ], $gl, ['approved_by' => $approver['username'] ?? null]);
            $shift = Teller::openShift();
            $savId = Savings::move($a, $c['code'], $side, $amount, $date, [
                'txn_id' => $txnId, 'trans_no' => $transNo, 'description' => $desc, 'shift_id' => $shift['id'] ?? null,
                'clearing_days' => $isCheck ? (int)$c['clearing_days'] : 0, 'negate' => $ec, 'corrects_id' => $orig['id'] ?? null,
                'opposite_account_id' => $opp, 'cheques' => $cheques, 'is_back_entry' => $back, 'is_system' => $ec,
                'ref_type' => $f['ref_type'] ?? ($ec ? 'error_correct' : null), 'ref_id' => $f['ref_id'] ?? ($orig['id'] ?? null),
            ]);
            if ($orig) {
                DB::update('sav_txns', ['corrected_by_id' => $savId], 'id = :id', ['id' => $orig['id']]);
                // a check deposit still clearing that is negated stops floating
                if ((int)$orig['is_cleared'] === 0) {
                    DB::update('sav_txns', ['is_cleared' => 1, 'cleared_txn_id' => $txnId], 'id = :id', ['id' => $orig['id']]);
                    $acc = Savings::account((int)$a['id'], true);
                    DB::update('sav_accounts', ['uncleared' => Dec::fix(Dec::max('0', Dec::sub($acc['uncleared'], $orig['credit'])))], 'id = :id', ['id' => $a['id']]);
                }
            }
            if ($cash || $isCheck) {
                Teller::recordReceipt($txnId, $effect === 'credit' ? 'sd' : 'sw', $transNo, (int)$a['member_id'], $a['branch_id'] ? (int)$a['branch_id'] : null, $shift['id'] ?? null,
                    $isCheck ? 'check' : 'cash', $cash ? $opp : null, $amount,
                    [['kind' => 'savings', 'ref_id' => $a['id'], 'account_id' => $liab, 'description' => $c['code'] . ' ' . $a['product_name'] . ' ' . $a['account_no'], 'amount' => $amount]],
                    $cheques, null, $back);
            }
            Audit::log($ec ? 'error_correct' : 'post', 'sav', 'sav_txn', $savId, $transNo . ' ' . $c['code'] . ' ' . $a['account_no'], [
                'page_key' => $pageKey, 'amount' => (float)$amount, 'reason' => $ec ? 'Negates ' . $orig['txn_code'] . ' ' . ($orig['trans_no'] ?: '#' . $orig['id']) : ($back ? 'Back entry' : null),
                'approved_by' => $approver['username'] ?? null,
            ]);
            return ['txn_id' => $txnId, 'sav_txn_id' => $savId, 'trans_no' => $transNo, 'amount' => $amount];
        });
    }

    /** A cash account (type Bank of the drawers) rather than a fixed GL account */
    private static function isCashAccount(int $accountId): bool
    {
        return (bool)DB::value("SELECT COUNT(*) FROM teller_shifts WHERE cash_account_id = :a", ['a' => $accountId])
            || (bool)DB::value('SELECT COUNT(*) FROM users WHERE coop_cash_account_id = :a', ['a' => $accountId]);
    }

    /** The transactions of an account an error correction may negate (not voided, not corrected, not a correction) */
    public static function correctable(int $accountId, int $limit = 30): array
    {
        return DB::all('SELECT s.*, u.username FROM sav_txns s LEFT JOIN users u ON u.id = s.teller_id
                         WHERE s.account_id = :a AND s.is_voided = 0 AND s.corrected_by_id IS NULL AND s.corrects_id IS NULL AND (s.credit > 0 OR s.debit > 0)
                           AND (s.ref_type IS NULL OR s.ref_type <> \'sc_bill\')
                           AND s.txn_code IN (SELECT related_code FROM sav_txn_codes WHERE is_error_correct = 1 AND is_active = 1 AND related_code IS NOT NULL)
                         ORDER BY s.txn_date DESC, s.id DESC LIMIT ' . max(1, $limit), ['a' => $accountId]);
    }

    /**
     * Deletes (voids) a transaction entered by mistake before it is printed in the passbook. A transaction already
     * in the passbook is corrected with an error-correct code instead, so the passbook keeps both lines.
     */
    public static function delete(int $savTxnId, string $reason, string $pageKey): void
    {
        $r = self::row($savTxnId);
        if (!$r || empty($r['trans_no'])) {
            fail('Choose a transaction of the Savings Transaction Center.');
        }
        if ((int)$r['is_voided'] === 1) {
            fail('Transaction ' . $r['trans_no'] . ' is already deleted.');
        }
        if ((int)$r['pb_printed'] === 1) {
            fail('Transaction ' . $r['trans_no'] . ' is printed in the passbook. Correct it with Error Correct instead (the passbook keeps both lines).');
        }
        if (!empty($r['corrected_by_id'])) {
            fail('Transaction ' . $r['trans_no'] . ' was corrected by an error correction. Delete the correction first.');
        }
        if (trim($reason) === '') {
            fail('Enter the reason for deleting the transaction.');
        }
        // the teller's own transaction of the day is deleted at once; any other needs a supervisor
        $own = (int)$r['created_by'] === Auth::id() && substr((string)$r['created_at'], 0, 10) === today();
        $approver = Approval::check($pageKey, !$own, 'deletion of savings transaction ' . $r['trans_no']);
        Txn::void((int)$r['txn_id'], 'Savings transaction ' . $r['trans_no'] . ' deleted: ' . trim($reason), $approver);
    }

    // ------------------------------------------------------------------ machine validation

    /** The two lines printed on the deposit or withdrawal slip (machine validation) */
    public static function validation(array $r): array
    {
        $time = $r['created_at'] ? date('H:i', strtotime((string)$r['created_at'])) : '';
        $amount = self::amount($r);
        return [
            [(string)$r['trans_no'], (string)$r['account_no'], date('m/d/Y', strtotime((string)$r['txn_date'])) . '  ' . $time],
            [(string)$r['full_name'], (string)$r['txn_code'], (Dec::isNeg($amount) ? '-' : '') . Dec::money(Dec::abs($amount)), (string)(int)$r['teller_id']],
        ];
    }

    // ------------------------------------------------------------------ returned checks (R Check)

    /** Check deposits of the last $days days that may be returned: still clearing, or cleared but not returned yet */
    public static function returnable(int $days = 90, string $q = ''): array
    {
        [$bf, $bp] = Coop::branchFilter('a.branch_id');
        $sql = "SELECT s.*, a.account_no, m.full_name, m.member_no, p.name AS product_name, c.description AS code_description
                  FROM sav_txns s JOIN sav_accounts a ON a.id = s.account_id JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id
                  JOIN sav_txn_codes c ON c.code = s.txn_code
                 WHERE c.is_check_deposit = 1 AND s.is_voided = 0 AND s.credit > 0 AND s.corrected_by_id IS NULL AND s.txn_date >= :d
                   AND NOT EXISTS (SELECT 1 FROM sav_txns r WHERE r.ref_type = 'returned' AND r.ref_id = s.id AND r.is_voided = 0)" . $bf;
        $p = ['d' => Coop::addDays(today(), -max(1, $days))] + $bp;
        if ($q !== '') {
            $sql .= ' AND (m.full_name LIKE :q1 OR a.account_no LIKE :q2 OR s.cheque_json LIKE :q3 OR s.trans_no = :q4)';
            $p += ['q1' => '%' . $q . '%', 'q2' => $q . '%', 'q3' => '%' . $q . '%', 'q4' => $q];
        }
        return DB::all($sql . ' ORDER BY s.txn_date DESC, s.id DESC LIMIT 300', $p);
    }

    /**
     * A deposited check dishonored by the bank: the member's account is debited for it (code RTC, or the code
     * chosen). A check still clearing comes out of the clearing account; a check already cleared was charged back by
     * the bank, so the bank account is credited.
     */
    public static function returnCheck(int $savTxnId, string $date, string $reason, ?int $bankAccountId, string $code, string $pageKey): array
    {
        return DB::tx(function () use ($savTxnId, $date, $reason, $bankAccountId, $code, $pageKey) {
            $t = DB::row('SELECT * FROM sav_txns WHERE id = :id FOR UPDATE', ['id' => $savTxnId]);
            if (!$t || (int)$t['is_voided'] === 1 || !Dec::isPos($t['credit'])) {
                fail('Choose a check deposit.');
            }
            $dep = Savings::code($t['txn_code']);
            if ((int)$dep['is_check_deposit'] !== 1) {
                fail('Transaction ' . ($t['trans_no'] ?: '#' . $t['id']) . ' is not a check deposit.');
            }
            if (DB::value("SELECT id FROM sav_txns WHERE ref_type = 'returned' AND ref_id = :r AND is_voided = 0", ['r' => $savTxnId])) {
                fail('That check was already returned.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason the bank returned the check.');
            }
            if (!valid_date($date)) {
                fail('Enter the date.');
            }
            Coop::checkDate($date);
            $c = Savings::code($code ?: 'RTC');
            if ($c['entry_side'] !== 'debit') {
                fail('Code ' . $c['code'] . ' is not a debit code.');
            }
            $a = Savings::account((int)$t['account_id'], true);
            Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
            $clearing = (int)$t['is_cleared'] === 0;
            if ($clearing) {
                $credit = (int)($dep['opposite_account_id'] ?: Accts::get('coop_coci'));
            } else {
                $credit = (int)($bankAccountId ?: ($c['opposite_kind'] === 'gl' && $c['opposite_account_id'] && (int)$c['opposite_account_id'] !== (int)$dep['opposite_account_id'] ? $c['opposite_account_id'] : 0));
                if (!$credit) {
                    fail('The check was already cleared: choose the bank account the bank charged it back to.');
                }
            }
            $amount = Dec::of($t['credit']);
            // a returned check may overdraw the account (the money was never received): a supervisor allows it
            $short = Dec::cmp($amount, Dec::sub($a['balance'], $a['hold_amount'], 2)) > 0;
            $approver = Approval::check($pageKey, $short, 'returned check larger than the balance of ' . $a['account_no']);
            if ($short && Dec::cmp($amount, $a['balance']) > 0) {
                fail('The returned check (' . Dec::money($amount) . ') is more than the balance of ' . $a['account_no'] . ' (' . Dec::money($a['balance']) . '). Record the shortfall as a receivable from the member.');
            }
            $transNo = Seq::next('sav_trans');
            $liab = Savings::ledgerAccount($a);
            $cheques = json_decode((string)($t['cheque_json'] ?? ''), true) ?: [];
            $memo = 'Returned check ' . (implode(', ', array_column($cheques, 'cheque_no')) ?: '') . ': ' . $reason . ' ' . $a['account_no'];
            $txnId = CoopLedger::post('coop_sv', ['txn_date' => $date, 'memo' => $transNo . ' ' . $c['code'] . ' ' . $a['member_name'] . ' ' . $memo, 'branch_id' => $a['branch_id'],
                'member_id' => $a['member_id'], 'txn_no' => $transNo, 'reason' => $reason, 'approved_by' => $approver['id'] ?? null], [
                ['account_id' => $liab, 'debit' => $amount, 'memo' => $memo, 'member_id' => $a['member_id']],
                ['account_id' => $credit, 'credit' => $amount, 'memo' => $memo],
            ], ['approved_by' => $approver['username'] ?? null]);
            if ($clearing) {
                DB::update('sav_txns', ['is_cleared' => 1, 'cleared_txn_id' => $txnId], 'id = :id', ['id' => $savTxnId]);
                DB::update('sav_accounts', ['uncleared' => Dec::fix(Dec::max('0', Dec::sub($a['uncleared'], $amount)))], 'id = :id', ['id' => $a['id']]);
                $a = Savings::account((int)$a['id'], true);
            }
            $id = Savings::move($a, $c['code'], 'debit', $amount, $date, ['txn_id' => $txnId, 'trans_no' => $transNo, 'description' => mb_substr('Returned check ' . $reason, 0, 160),
                'is_system' => true, 'ref_type' => 'returned', 'ref_id' => $savTxnId, 'opposite_account_id' => $credit, 'cheques' => $cheques]);
            Audit::log('return_check', 'sav', 'sav_txn', $id, $transNo . ' ' . $a['account_no'], ['page_key' => $pageKey, 'amount' => (float)$amount, 'reason' => $reason, 'approved_by' => $approver['username'] ?? null]);
            return ['txn_id' => $txnId, 'sav_txn_id' => $id, 'trans_no' => $transNo];
        });
    }

    // ------------------------------------------------------------------ accounts: close and delete

    /**
     * Closes an account. A balance left on it is paid out first, in the Savings Transaction Center, with the closing
     * code (the setting "Closing transaction code", CSW unless changed) and the closing fee code when a fee is
     * charged; then the account is closed.
     */
    public static function closeAccount(int $accountId, string $reason, string $fee, string $pageKey): ?array
    {
        return DB::tx(function () use ($accountId, $reason, $fee, $pageKey) {
            $a = Savings::account($accountId, true);
            if (!$a) {
                fail('Account not found.');
            }
            if ($a['status'] === 'closed') {
                fail('The account is already closed.');
            }
            if ($a['product_type'] === 'time_deposit') {
                fail('A time deposit is closed on the Time Deposit Center (Close).');
            }
            if (trim($reason) === '') {
                fail('Enter the reason for closing.');
            }
            if (Dec::isPos($a['uncleared'])) {
                fail('Checks of ' . Dec::money($a['uncleared']) . ' are still clearing on the account. Clear or return them first.');
            }
            if (Dec::isPos($a['hold_amount'])) {
                fail('Release the holds on the account first.');
            }
            $out = null;
            $fee = Dec::round($fee !== '' ? $fee : '0', 2);
            if (Dec::isPos($fee)) {
                if (Dec::cmp($fee, $a['balance']) > 0) {
                    fail('The closing fee is more than the balance.');
                }
                self::post(['account_id' => $accountId, 'code' => Coop::setting('sav_closing_fee_code', 'CF'), 'amount' => $fee, 'date' => today(), 'memo' => 'Closing fee', 'closing' => true,
                    'process_gl_account_id' => Accts::get('coop_other_income')], $pageKey);
                $a = Savings::account($accountId, true);
            }
            if (Dec::isPos($a['balance'])) {
                $out = self::post(['account_id' => $accountId, 'code' => Coop::setting('sav_closing_code', 'CSW'), 'amount' => $a['balance'], 'date' => today(),
                    'memo' => 'Closing withdrawal: ' . mb_substr($reason, 0, 120), 'closing' => true], $pageKey);
            }
            Savings::close($accountId, $reason, $pageKey);
            return $out;
        });
    }

    /** Deletes an account opened by mistake: only while nothing was ever posted to it */
    public static function deleteAccount(int $accountId, string $reason, string $pageKey): void
    {
        DB::tx(function () use ($accountId, $reason, $pageKey) {
            $a = Savings::account($accountId, true);
            if (!$a) {
                fail('Account not found.');
            }
            Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
            if ((int)DB::value('SELECT COUNT(*) FROM sav_txns WHERE account_id = :a', ['a' => $accountId]) > 0) {
                fail('Account ' . $a['account_no'] . ' has transactions (voided ones included), so it is kept. Close it instead.');
            }
            foreach (['td_placements' => 'account_id', 'sav_holds' => 'account_id', 'loan_accounts' => 'savings_account_id'] as $tbl => $col) {
                try {
                    if ((int)DB::value("SELECT COUNT(*) FROM $tbl WHERE $col = :a", ['a' => $accountId]) > 0) {
                        fail('Account ' . $a['account_no'] . ' is used by a time deposit, a hold or a loan, so it is kept. Close it instead.');
                    }
                } catch (PDOException $e) {
                    // that table or column is not there
                }
            }
            if (trim($reason) === '') {
                fail('Enter the reason for deleting the account.');
            }
            $snap = DB::row('SELECT * FROM sav_accounts WHERE id = :id', ['id' => $accountId]);
            DB::run('DELETE FROM sav_accounts WHERE id = :id', ['id' => $accountId]);
            Audit::log('delete', 'sav', 'sav_account', $accountId, $a['account_no'] . ' ' . $a['member_name'], ['page_key' => $pageKey, 'reason' => $reason, 'snapshot' => $snap]);
        });
    }

    // ------------------------------------------------------------------ time deposit accrued interest withdrawal (CAIW)

    /** The interest of a time deposit the member may withdraw now: earned to date less what was paid out */
    public static function tdWithdrawable(array $td, ?string $asOf = null): string
    {
        if ($td['status'] !== 'active') {
            return '0';
        }
        $earned = TimeDeposits::earnedThrough($td, $asOf ?? today());
        return Dec::max('0', Dec::sub($earned, $td['interest_paid'], 2));
    }

    /**
     * Withdraws part of the accrued interest of an active time deposit (code CAIW, in the Savings Transaction
     * Center). The interest is credited to the time deposit (TDI) and withdrawn (CAIW), so the principal stays.
     * Journal: Dr accrued interest payable (what was accrued), Dr interest expense (the rest), Cr the teller's cash.
     */
    public static function tdInterestWithdraw(int $placementId, string $amount, string $date, string $pageKey): array
    {
        return DB::tx(function () use ($placementId, $amount, $date, $pageKey) {
            $td = TimeDeposits::placement($placementId, true);
            if (!$td || $td['status'] !== 'active') {
                fail('The time deposit is not active.');
            }
            Coop::requireBranch($td['branch_id'] ? (int)$td['branch_id'] : null);
            if (!valid_date($date)) {
                fail('Enter the date.');
            }
            Coop::checkDate($date);
            $amount = Dec::round($amount, 2);
            if (!Dec::isPos($amount)) {
                fail('Enter the amount to withdraw.');
            }
            $avail = self::tdWithdrawable($td, $date);
            if (Dec::cmp($amount, $avail) > 0) {
                fail('The accrued interest that can be withdrawn is ' . Dec::money($avail) . '.');
            }
            $m = Coop::member((int)$td['member_id']);
            Coop::assertTransactable($m);
            $c = Savings::code('CAIW');
            Teller::requireShift();
            $cash = Teller::currentCashAccount();
            if (!$cash) {
                fail('No cash account is assigned to you or your profile.');
            }
            $p = Savings::product((int)$td['product_id']);
            $accAcct = (int)($p['accrued_account_id'] ?: Accts::get('deposit_interest_payable'));
            $accruedLeft = Dec::max('0', Dec::sub($td['accrued_interest'], $td['accrued_paid'] ?? '0', 2));
            $used = Dec::min($amount, $accruedLeft);
            $expensed = Dec::sub($amount, $used, 2);
            $transNo = Seq::next('sav_trans');
            $memo = 'TD Accrued Interest withdrawal ' . $td['certificate_no'] . ' ' . $td['account_no'];
            $gl = [];
            if (Dec::isPos($used)) {
                $gl[] = ['account_id' => $accAcct, 'debit' => $used, 'memo' => $memo, 'member_id' => $td['member_id']];
            }
            if (Dec::isPos($expensed)) {
                $gl[] = ['account_id' => Savings::expenseAccount($p), 'debit' => $expensed, 'memo' => $memo, 'member_id' => $td['member_id']];
            }
            $gl[] = ['account_id' => $cash, 'credit' => $amount, 'memo' => $memo];
            $txnId = CoopLedger::post('coop_sv', ['txn_date' => $date, 'memo' => $transNo . ' CAIW ' . $td['member_name'] . ' ' . $memo, 'branch_id' => $td['branch_id'],
                'member_id' => $td['member_id'], 'account_id' => $cash, 'txn_no' => $transNo], $gl);
            $shift = Teller::openShift();
            $a = Savings::account((int)$td['account_id'], true);
            Savings::move($a, 'TDI', 'credit', $amount, $date, ['txn_id' => $txnId, 'trans_no' => null, 'description' => 'Interest ' . $td['certificate_no'], 'is_system' => true, 'ref_type' => 'td', 'ref_id' => $placementId]);
            $a = Savings::account((int)$td['account_id'], true);
            $id = Savings::move($a, $c['code'], 'debit', $amount, $date, ['txn_id' => $txnId, 'trans_no' => $transNo, 'description' => 'TD Accrued Interest withdrawal', 'is_system' => true,
                'ref_type' => 'td', 'ref_id' => $placementId, 'shift_id' => $shift['id'] ?? null, 'opposite_account_id' => $cash]);
            DB::update('td_placements', ['interest_paid' => Dec::fix(Dec::add($td['interest_paid'], $amount)), 'accrued_paid' => Dec::fix(Dec::add($td['accrued_paid'] ?? '0', $used))], 'id = :id', ['id' => $placementId]);
            DB::insert('td_interest_payouts', ['placement_id' => $placementId, 'payout_date' => $date, 'amount' => Dec::fix($amount), 'accrued_used' => Dec::fix($used), 'expensed' => Dec::fix($expensed),
                'txn_id' => $txnId, 'trans_no' => $transNo, 'created_by' => Auth::id()]);
            Teller::recordReceipt($txnId, 'sw', $transNo, (int)$td['member_id'], $td['branch_id'] ? (int)$td['branch_id'] : null, $shift['id'] ?? null, 'cash', $cash, $amount,
                [['kind' => 'td', 'ref_id' => $td['account_id'], 'account_id' => $accAcct, 'description' => $memo, 'amount' => $amount]]);
            Audit::log('td_interest_withdrawal', 'sav', 'td_placement', $placementId, $td['certificate_no'], ['page_key' => $pageKey, 'amount' => (float)$amount,
                'snapshot' => ['trans_no' => $transNo, 'accrued_used' => $used, 'expensed' => $expensed, 'txn_id' => $txnId]]);
            return ['txn_id' => $txnId, 'sav_txn_id' => $id, 'trans_no' => $transNo];
        });
    }
}
