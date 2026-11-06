<?php
declare(strict_types=1);

/**
 * Teller operations: the cash drawer (shift) with its opening cash, cash count and over/short, and the
 * official receipt that collects any mix of member items at once (savings deposits, share capital, loan
 * payments, fees), posted as ONE balanced voucher with ONE receipt number.
 */
final class Teller
{
    public const DENOMINATIONS = ['1000', '500', '200', '100', '50', '20', '10', '5', '1', '0.25', '0.10', '0.05', '0.01'];

    // ------------------------------------------------------------------ shifts

    public static function openShift(?int $userId = null): ?array
    {
        $userId ??= Auth::id();
        return DB::row("SELECT s.*, a.code AS cash_code, a.name AS cash_name FROM teller_shifts s JOIN accounts a ON a.id = s.cash_account_id WHERE s.user_id = :u AND s.status = 'open' ORDER BY s.id DESC LIMIT 1", ['u' => $userId]);
    }

    public static function shift(int $id): ?array
    {
        return DB::row('SELECT s.*, a.code AS cash_code, a.name AS cash_name, u.full_name AS teller_name, u.username FROM teller_shifts s JOIN accounts a ON a.id = s.cash_account_id JOIN users u ON u.id = s.user_id WHERE s.id = :id', ['id' => $id]);
    }

    /**
     * The cash account the user tells with: their own (User Accounts), else the cash account of their profile
     * (User Role Configuration), else the module default. A transaction code whose opposite account is the "User
     * Account" posts to it.
     */
    public static function userCashAccount(?int $userId = null): ?int
    {
        $userId ??= Auth::id();
        $v = DB::value('SELECT coop_cash_account_id FROM users WHERE id = :id', ['id' => $userId]);
        if ($v) {
            return (int)$v;
        }
        if ($r = self::roleCashAccount($userId)) {
            return $r;
        }
        try {
            return Accts::get('coop_cash');
        } catch (UserError $e) {
            return null;
        }
    }

    /** True once a profile (role) can carry a cash account (upgrade 2026_12_27) */
    public static function roleCashReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(coop_cash_account_id) FROM roles WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** The cash account of the user's profile (role), the first one of their roles that has one */
    public static function roleCashAccount(int $userId): ?int
    {
        try {
            $v = DB::value('SELECT r.coop_cash_account_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                             WHERE ur.user_id = :u AND r.coop_cash_account_id IS NOT NULL ORDER BY r.id LIMIT 1', ['u' => $userId]);
        } catch (Throwable $e) {
            $v = null;      // before the upgrade 2026_12_27
        }
        return $v ? (int)$v : null;
    }

    /** The cash account a teller transaction uses now: the open shift's drawer, else the user's (or profile's) cash account */
    public static function currentCashAccount(?int $userId = null): ?int
    {
        $s = self::openShift($userId);
        return $s ? (int)$s['cash_account_id'] : self::userCashAccount($userId);
    }

    public static function open(string $businessDate, string $openingCash, ?int $cashAccountId, string $pageKey): int
    {
        return DB::tx(function () use ($businessDate, $openingCash, $cashAccountId, $pageKey) {
            if (self::openShift()) {
                fail('You already have an open shift. Close it before opening another.');
            }
            if (!valid_date($businessDate)) {
                fail('Enter the business date.');
            }
            $cash = $cashAccountId ?: self::userCashAccount();
            if (!$cash) {
                fail('No cash account is assigned to you (System Administration > User Accounts > Cooperative cash account).');
            }
            if (!DB::value("SELECT id FROM accounts WHERE id = :a AND account_type = 'Bank' AND is_active = 1", ['a' => $cash])) {
                fail('The drawer must be an active cash account (an account of type Bank in the Chart of Accounts).');
            }
            $assigned = DB::value('SELECT coop_cash_account_id FROM users WHERE id = :id', ['id' => Auth::id()]) ?: self::roleCashAccount((int)Auth::id());
            if ($assigned && (int)$assigned !== $cash && !Access::can($pageKey, 'approve')) {
                fail('You may only open a shift on your assigned cash account.');
            }
            $other = DB::row("SELECT s.*, u.full_name FROM teller_shifts s JOIN users u ON u.id = s.user_id WHERE s.cash_account_id = :a AND s.status = 'open'", ['a' => $cash]);
            if ($other) {
                fail('Cash account ' . Coop::accountLabel($cash) . ' is in use by the open shift of ' . $other['full_name'] . '.');
            }
            $id = DB::insert('teller_shifts', ['user_id' => Auth::id(), 'branch_id' => Coop::defaultBranchId(), 'cash_account_id' => $cash, 'business_date' => $businessDate,
                'opening_cash' => Dec::fix(Dec::of($openingCash)), 'status' => 'open']);
            Audit::log('open_shift', 'teller', 'teller_shift', $id, 'Shift ' . $id, ['page_key' => $pageKey, 'amount' => (float)Dec::of($openingCash)]);
            return $id;
        });
    }

    /** Receipt kinds that bring cash into the drawer (official receipts, savings deposit slips) and that take it out */
    public const KINDS_IN = ['or', 'sd'];
    public const KINDS_OUT = ['cv', 'sw'];
    public const KIND_LABELS = ['or' => 'Official receipt', 'cv' => 'Disbursement', 'sd' => 'Savings deposit / receipt', 'sw' => 'Savings withdrawal / payment'];

    /** Receipts and disbursements of a shift, from the vouchers that carry it */
    public static function refreshShift(int $shiftId): void
    {
        $t = DB::row("SELECT COALESCE(SUM(CASE WHEN r.receipt_kind IN ('or','sd') AND r.payment_mode = 'cash' THEN r.total ELSE 0 END),0) AS rc,
                             COALESCE(SUM(CASE WHEN r.receipt_kind IN ('cv','sw') AND r.payment_mode = 'cash' THEN r.total ELSE 0 END),0) AS dc
                        FROM coop_receipts r JOIN transactions t ON t.id = r.txn_id WHERE r.shift_id = :s AND t.is_voided = 0", ['s' => $shiftId]);
        DB::update('teller_shifts', ['receipts_total' => Dec::fix($t['rc']), 'disbursements_total' => Dec::fix($t['dc'])], 'id = :id', ['id' => $shiftId]);
    }

    public static function expectedCash(array $shift): string
    {
        return Dec::sub(Dec::add($shift['opening_cash'], $shift['receipts_total'], 2), $shift['disbursements_total'], 2);
    }

    /**
     * Closes the shift with the cash count. A difference is posted as Cash Over and Short (the POS module's default
     * account) against the drawer's cash account, so the ledger agrees with the cash counted.
     */
    public static function close(int $shiftId, array $denoms, string $notes, string $pageKey): array
    {
        return DB::tx(function () use ($shiftId, $denoms, $notes, $pageKey) {
            $s = DB::row('SELECT * FROM teller_shifts WHERE id = :id FOR UPDATE', ['id' => $shiftId]);
            if (!$s || $s['status'] !== 'open') {
                fail('The shift is not open.');
            }
            if ((int)$s['user_id'] !== Auth::id() && !Access::can($pageKey, 'approve')) {
                fail('Only the teller or a supervisor can close this shift.');
            }
            self::refreshShift($shiftId);
            $s = DB::row('SELECT * FROM teller_shifts WHERE id = :id', ['id' => $shiftId]);
            $counted = '0';
            $json = [];
            foreach (self::DENOMINATIONS as $d) {
                $n = (int)($denoms[$d] ?? 0);
                if ($n < 0) {
                    fail('Denomination counts cannot be negative.');
                }
                $json[$d] = $n;
                $counted = Dec::add($counted, Dec::mul($d, (string)$n, 2), 2);
            }
            if (isset($denoms['_total']) && trim((string)$denoms['_total']) !== '') {
                $counted = Dec::round(Dec::of($denoms['_total']), 2);     // a total typed instead of a count
            }
            $expected = self::expectedCash($s);
            $diff = Dec::sub($counted, $expected, 2);
            $txnId = null;
            if (!Dec::isZero($diff)) {
                if (!Access::can($pageKey, 'approve') && Dec::cmp(Dec::abs($diff), Coop::setting('over_short_tolerance', '0')) > 0) {
                    $approver = Approval::check($pageKey, true, 'cash over/short of ' . Dec::money($diff) . ' on closing the shift');
                } else {
                    $approver = null;
                }
                $osAcct = Accts::get('cash_over_short');
                $memo = 'Teller shift ' . $shiftId . ' cash ' . (Dec::isPos($diff) ? 'over' : 'short') . ' ' . fdate($s['business_date']);
                $gl = Dec::isPos($diff)
                    ? [['account_id' => (int)$s['cash_account_id'], 'debit' => $diff, 'memo' => $memo], ['account_id' => $osAcct, 'credit' => $diff, 'memo' => $memo]]
                    : [['account_id' => $osAcct, 'debit' => Dec::abs($diff), 'memo' => $memo], ['account_id' => (int)$s['cash_account_id'], 'credit' => Dec::abs($diff), 'memo' => $memo]];
                $txnId = CoopLedger::post('coop_jv', ['txn_date' => $s['business_date'], 'memo' => $memo, 'branch_id' => $s['branch_id'], 'approved_by' => $approver['id'] ?? null], $gl, ['approved_by' => $approver['username'] ?? null]);
            }
            DB::update('teller_shifts', ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'), 'closed_by' => Auth::id(), 'expected_cash' => Dec::fix($expected), 'counted_cash' => Dec::fix($counted),
                'over_short' => Dec::fix($diff), 'over_short_txn_id' => $txnId, 'denominations_json' => json_encode($json), 'notes' => mb_substr($notes, 0, 255) ?: null], 'id = :id', ['id' => $shiftId]);
            Audit::log('close_shift', 'teller', 'teller_shift', $shiftId, 'Shift ' . $shiftId, ['page_key' => $pageKey, 'amount' => (float)$counted, 'reason' => 'Expected ' . Dec::money($expected) . ', counted ' . Dec::money($counted) . ', over/short ' . Dec::money($diff)]);
            return ['expected' => $expected, 'counted' => $counted, 'over_short' => $diff, 'txn_id' => $txnId];
        });
    }

    /** The shift a cash transaction needs (policy coop_or_require_shift): the user's open shift */
    public static function requireShift(): ?array
    {
        $s = self::openShift();
        if (!$s && Coop::setting('or_require_shift', '1') === '1') {
            fail('Open your teller shift first (Teller > Teller Shift & Cash Count).');
        }
        return $s;
    }

    // ------------------------------------------------------------------ receipts

    /** Writes the receipt face for a posted voucher (called by every cash-in / cash-out operation) */
    public static function recordReceipt(int $txnId, string $kind, ?string $no, ?int $memberId, ?int $branchId, ?int $shiftId, string $mode, ?int $cashAccountId, string $total, array $lines, array $cheques = [], ?string $payorName = null, bool $backEntry = false): int
    {
        $no = $no ?: Coop::nextReceiptNo($kind, $branchId);
        if (DB::value('SELECT id FROM coop_receipts WHERE receipt_kind = :k AND receipt_no = :n', ['k' => $kind, 'n' => $no])) {
            fail(($kind === 'or' ? 'Official receipt' : (in_array($kind, ['sd', 'sw'], true) ? 'Savings transaction' : 'Voucher')) . ' number ' . $no . ' is already used.');
        }
        $id = DB::insert('coop_receipts', ['txn_id' => $txnId, 'receipt_kind' => $kind, 'receipt_no' => $no, 'member_id' => $memberId, 'payor_name' => $payorName, 'branch_id' => $branchId, 'shift_id' => $shiftId,
            'payment_mode' => $mode, 'cash_account_id' => $cashAccountId, 'cheque_json' => $cheques ? json_encode($cheques, JSON_UNESCAPED_UNICODE) : null, 'total' => Dec::fix($total),
            'is_back_entry' => $backEntry ? 1 : 0, 'created_by' => Auth::id()]);
        $n = 0;
        foreach ($lines as $l) {
            DB::insert('coop_receipt_lines', ['receipt_id' => $id, 'line_no' => ++$n, 'kind' => $l['kind'], 'ref_id' => $l['ref_id'] ?? null, 'account_id' => $l['account_id'] ?? null,
                'description' => mb_substr((string)($l['description'] ?? ''), 0, 160), 'amount' => Dec::fix($l['amount'])]);
        }
        if ($shiftId) {
            self::refreshShift($shiftId);
        }
        return $id;
    }

    /**
     * The GL accounts an official receipt may take as a fee or other item: every active account except those whose
     * balance is kept by a subsidiary ledger of its own, which an official receipt would put out of agreement with it:
     * the accounts of the savings, share capital and time deposit products (and the interest payable on deposits),
     * the loans receivable (and the interest receivable on loans), and the receivables of the sales of goods (the
     * Accounts Receivable accounts, by customer and bill) with the inventory accounts of the items.
     * Returns [id => "code - name"]; reason() says why an account is left out.
     */
    public static function receiptAccounts(): array
    {
        $out = Accts::all();
        foreach (array_keys(self::receiptExcluded()) as $id) {
            unset($out[$id]);
        }
        return $out;
    }

    /** account id => why an official receipt does not take it */
    public static function receiptExcluded(): array
    {
        $out = [];
        $put = function ($id, string $why) use (&$out) {
            if ($id && !isset($out[(int)$id])) {
                $out[(int)$id] = $why;
            }
        };
        $def = DB::pairs('SELECT setting_key, account_id FROM default_accounts');
        foreach (DB::all('SELECT product_type, liability_account_id, accrued_account_id FROM sav_products') as $p) {
            $why = $p['product_type'] === 'time_deposit' ? 'time deposits (Time Deposit Center)' : ($p['product_type'] === 'share_capital' ? 'share capital (Savings Account Transaction)' : 'savings deposits (Savings Account Transaction)');
            $put($p['liability_account_id'], $why);
            $put($p['accrued_account_id'], 'time deposits (Time Deposit Center)');
        }
        $put($def['savings_deposits'] ?? null, 'savings deposits (Savings Account Transaction)');
        $put($def['share_capital'] ?? null, 'share capital (Savings Account Transaction)');
        $put($def['time_deposits'] ?? null, 'time deposits (Time Deposit Center)');
        $put($def['deposit_interest_payable'] ?? null, 'time deposits (Time Deposit Center)');
        foreach (DB::all('SELECT receivable_account_id, past_due_account_id, accrued_int_account_id FROM loan_products') as $p) {
            $put($p['receivable_account_id'], 'loans receivable (Loan Collection)');
            $put($p['past_due_account_id'], 'loans receivable (Loan Collection)');
            $put($p['accrued_int_account_id'], 'loans receivable (Loan Collection)');
        }
        foreach (['loans_receivable', 'loans_past_due', 'loans_restructured', 'loan_interest_receivable'] as $k) {
            $put($def[$k] ?? null, 'loans receivable (Loan Collection)');
        }
        foreach (DB::column("SELECT id FROM accounts WHERE account_type = 'Accounts Receivable'") as $id) {
            $put($id, 'receivables of customers (Receive Payments, the water collection)');
        }
        $put($def['ar'] ?? null, 'receivables of customers (Receive Payments, the water collection)');
        foreach (DB::column('SELECT DISTINCT asset_account_id FROM items WHERE asset_account_id IS NOT NULL') as $id) {
            $put($id, 'inventory (the stock of the items)');
        }
        $put($def['inventory_asset'] ?? null, 'inventory (the stock of the items)');
        return $out;
    }

    /**
     * The official receipt: any mix of items for one payor, one voucher, one OR number.
     * $items: each ['kind' => savings|loan|charge|other, ...]:
     *   savings : account_id, amount, code (CSD by default), description
     *   loan    : loan_id, amount, override (penalty/interest/principal) or null, payoff (bool)
     *   charge  : account_id (any account of receiptAccounts()), amount, description (fees, membership fee ...)
     * $pay: mode (cash|check|bank|savings), cash_account_id, cheques [[cheque_no, bank, date, amount]], from_account_id (savings transfer)
     */
    public static function receipt(int $memberId, string $date, array $items, array $pay, array $opt, string $pageKey): array
    {
        return DB::tx(function () use ($memberId, $date, $items, $pay, $opt, $pageKey) {
            Coop::checkDate($date);
            $m = Coop::member($memberId);
            if (!$m) {
                fail('Choose the member.');
            }
            // a member who has left may still pay what they owe (loans), and nothing else; a deceased member's loans
            // are settled from their deposits (payment by savings, with approval) or by the heirs
            $leaving = in_array($m['status'], ['resigned', 'terminated', 'deceased'], true);
            if ($leaving) {
                foreach ($items as $it) {
                    if (($it['kind'] ?? '') !== 'loan' && Dec::isPos(Dec::round($it['amount'] ?? '0', 2))) {
                        fail('Member ' . $m['full_name'] . ' is ' . (Coop::MEMBER_STATUSES[$m['status']] ?? $m['status']) . ': only loan payments are received.');
                    }
                }
                if ((int)$m['hold_flag'] === 1 && $m['status'] !== 'deceased') {
                    fail('Member ' . $m['full_name'] . ' is on hold: ' . ($m['hold_reason'] ?: 'no transactions until released') . '.');
                }
            } else {
                Coop::assertTransactable($m, true);
            }
            Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
            $shift = self::requireShift();
            $mode = $pay['mode'] ?? 'cash';
            if ($mode === 'bank' && !(int)($pay['cash_account_id'] ?? 0)) {
                fail('Choose the bank account the payment went into.');
            }
            $cashAcct = (int)($pay['cash_account_id'] ?? 0) ?: ($shift ? (int)$shift['cash_account_id'] : self::userCashAccount());
            if (!$cashAcct && $mode !== 'savings') {
                fail('Choose the cash account.');
            }
            if ($shift && $cashAcct !== (int)$shift['cash_account_id'] && $mode === 'cash') {
                fail('Cash receipts go to the cash account of your open shift (' . $shift['cash_code'] . ').');
            }
            $gl = [];
            $rlines = [];
            $total = '0';
            $after = [];       // subsidiary postings to make once the voucher exists
            $checkDr = [];     // check deposits to savings by clearing account
            $branchId = $m['branch_id'] ? (int)$m['branch_id'] : Coop::defaultBranchId();
            foreach ($items as $it) {
                $amount = Dec::round($it['amount'] ?? '0', 2);
                // a payoff line works its amount out itself
                if (!Dec::isPos($amount) && !(($it['kind'] ?? '') === 'loan' && !empty($it['payoff']))) {
                    continue;
                }
                switch ($it['kind']) {
                    case 'savings':
                        $code = $it['code'] ?? ($mode === 'check' ? 'CK4' : 'CSD');
                        $c = Savings::code($code);
                        if ($mode === 'check' && (int)$c['is_check_deposit'] === 1 && (int)$c['opposite_account_id']) {
                            // the check waits in the clearing account of its own code, which Check Clearing credits later
                            $checkDr[(int)$c['opposite_account_id']] = Dec::add($checkDr[(int)$c['opposite_account_id']] ?? '0', $amount, 2);
                        }
                        $a = Savings::validate((int)$it['account_id'], 'credit', $amount, $date, ['allow_pending' => true]);
                        if ((int)$a['member_id'] !== $memberId && empty($it['allow_other_member'])) {
                            fail('Account ' . $a['account_no'] . ' belongs to another member.');
                        }
                        $gl[] = ['account_id' => Savings::ledgerAccount($a), 'credit' => $amount, 'memo' => ($it['description'] ?? $c['description']) . ' ' . $a['account_no'], 'member_id' => $a['member_id']];
                        $rlines[] = ['kind' => 'savings', 'ref_id' => $a['id'], 'account_id' => Savings::ledgerAccount($a), 'description' => $a['product_name'] . ' ' . $a['account_no'], 'amount' => $amount];
                        $after[] = fn(int $txnId, string $no) => Savings::move(Savings::account((int)$a['id'], true), $code, 'credit', $amount, $date,
                            ['txn_id' => $txnId, 'or_no' => $no, 'shift_id' => $shift['id'] ?? null, 'clearing_days' => $mode === 'check' ? (int)$c['clearing_days'] : 0, 'description' => $it['description'] ?? $c['description']]);
                        $total = Dec::add($total, $amount, 2);
                        break;
                    case 'loan':
                        $loan = Loans::loan((int)$it['loan_id'], true);
                        if (!$loan) {
                            fail('Loan not found.');
                        }
                        if ((int)$loan['member_id'] !== $memberId && empty($it['allow_other_member'])) {
                            fail('Loan ' . $loan['loan_no'] . ' belongs to another member.');
                        }
                        if (!in_array($loan['status'], ['current', 'past_due', 'restructured'], true)) {
                            fail('Loan ' . $loan['loan_no'] . ' is ' . $loan['status'] . '.');
                        }
                        // the penalties through today go on first, so the allocation and the receipt agree
                        if (Coop::setting('penalty_on_collection', '1') === '1') {
                            Loans::chargePenalty($loan, $date);
                            $loan = Loans::loan((int)$loan['id'], true);
                        }
                        $dues = Loans::dues($loan, $date);
                        $override = null;
                        if (!empty($it['payoff'])) {
                            $override = ['penalty' => $dues['penalty_due'], 'principal' => $dues['principal_balance'],
                                'interest' => $loan['interest_method'] === 'diminishing' ? Dec::add($dues['interest_due'], $dues['interest_to_date'], 2) : $dues['interest_balance']];
                            $amount = $dues['payoff'];
                        } elseif (!empty($it['override']) && is_array($it['override'])) {
                            if (!Access::special('coop_override_allocation')) {
                                fail('Overriding the payment allocation needs the "can override the payment allocation" permission.');
                            }
                            $override = $it['override'];
                        }
                        [$pen, $int, $prn, $excess] = Loans::allocate($dues, $amount, $override);
                        if (Dec::isPos($excess)) {
                            fail('The payment on loan ' . $loan['loan_no'] . ' is ' . Dec::money($excess) . ' more than the loan can take (payoff ' . Dec::money($dues['payoff'])
                                . '). Lower the amount, or tick Full payment; receive the rest as a savings deposit on Savings Account Transaction.');
                        }
                        $memo = 'Loan payment ' . $loan['loan_no'];
                        foreach (Loans::paymentLines($loan, $pen, $int, $prn, $memo) as $g) {
                            $gl[] = $g;
                        }
                        foreach ([['loan_penalty', $pen, 'Penalty'], ['loan_interest', $int, 'Interest'], ['loan_principal', $prn, 'Principal']] as [$k, $v, $lbl]) {
                            if (Dec::isPos($v)) {
                                $rlines[] = ['kind' => $k, 'ref_id' => $loan['id'], 'account_id' => $k === 'loan_principal' ? $loan['receivable_account_id'] : ($k === 'loan_interest' ? $loan['interest_account_id'] : $loan['penalty_account_id']),
                                    'description' => $lbl . ' ' . $loan['product_name'] . ' ' . $loan['loan_no'], 'amount' => $v];
                            }
                        }
                        $ovr = $override;
                        $amt = $amount;
                        $after[] = fn(int $txnId, string $no) => Loans::applyPayment($loan, $dues, $date, $amt, $ovr === null ? null : ['penalty' => $pen, 'interest' => $int, 'principal' => $prn], $txnId,
                            ['or_no' => $no, 'shift_id' => $shift['id'] ?? null, 'memo' => 'Payment OR ' . $no]);
                        if (empty($it['payoff']) && $override !== null) {
                            // the application of payment overridden by the user: kept in the audit trail with the receipt
                            $after[] = fn(int $txnId, string $no) => Audit::log('override', 'loan', 'loan_account', (int)$loan['id'], (string)$loan['loan_no'], ['page_key' => $pageKey, 'amount' => (float)$amt,
                                'reason' => 'Payment allocation overridden on OR ' . $no . ': penalty ' . Dec::money($pen) . ', interest ' . Dec::money($int) . ', principal ' . Dec::money($prn)]);
                        }
                        $total = Dec::add($total, Dec::add(Dec::add($pen, $int, 2), $prn, 2), 2);
                        break;
                    case 'charge':
                    case 'other':
                        $acct = (int)($it['account_id'] ?? 0);
                        if (!$acct) {
                            fail('Choose the account of the fee / other item.');
                        }
                        if ($why = self::receiptExcluded()[$acct] ?? null) {
                            fail(Coop::accountLabel($acct) . ' is kept by the ledger of ' . $why . ': an official receipt does not take it as a fee or other item.');
                        }
                        $desc = trim((string)($it['description'] ?? '')) ?: Coop::accountLabel($acct);
                        $gl[] = ['account_id' => $acct, 'credit' => $amount, 'memo' => $desc, 'member_id' => $memberId];
                        $rlines[] = ['kind' => 'charge', 'ref_id' => null, 'account_id' => $acct, 'description' => $desc, 'amount' => $amount];
                        $total = Dec::add($total, $amount, 2);
                        break;
                    default:
                        fail('Unknown receipt item.');
                }
            }
            if (!$rlines) {
                fail('Enter at least one item with an amount.');
            }
            // the money side
            $cheques = [];
            if ($mode === 'check') {
                $sum = '0';
                foreach ((array)($pay['cheques'] ?? []) as $ch) {
                    $amt = Dec::round($ch['amount'] ?? '0', 2);
                    if (!Dec::isPos($amt)) {
                        continue;
                    }
                    if (trim((string)($ch['cheque_no'] ?? '')) === '') {
                        fail('Enter the cheque number.');
                    }
                    $cheques[] = ['cheque_no' => mb_substr(trim((string)$ch['cheque_no']), 0, 40), 'bank' => mb_substr(trim((string)($ch['bank'] ?? '')), 0, 60), 'date' => valid_date($ch['date'] ?? '') ? $ch['date'] : $date, 'amount' => Dec::fix($amt)];
                    $sum = Dec::add($sum, $amt, 2);
                }
                if (Dec::cmp($sum, $total) !== 0) {
                    fail('The cheques (' . Dec::money($sum) . ') must add up to the receipt total (' . Dec::money($total) . ').');
                }
                $drAcct = (int)(Savings::code('CK4')['opposite_account_id'] ?: Accts::get('coop_coci'));
            } elseif ($mode === 'bank') {
                $drAcct = $cashAcct;
            } elseif ($mode === 'savings') {
                $from = Savings::validate((int)($pay['from_account_id'] ?? 0), 'debit', $total, $date, ['settlement' => $leaving]);
                if ((int)$from['member_id'] !== $memberId) {
                    fail('Payments by savings transfer come from the member\'s own account.');
                }
                $drAcct = Savings::ledgerAccount($from);
                $after[] = fn(int $txnId, string $no) => Savings::move(Savings::account((int)$from['id'], true), 'LPY', 'debit', $total, $date, ['txn_id' => $txnId, 'or_no' => $no, 'shift_id' => $shift['id'] ?? null, 'description' => 'Payment per OR ' . $no]);
            } else {
                $cashReceived = isset($pay['cash_received']) && trim((string)$pay['cash_received']) !== '' ? Dec::round($pay['cash_received'], 2) : $total;
                if (Dec::cmp($cashReceived, $total) < 0) {
                    fail('Cash received (' . Dec::money($cashReceived) . ') is less than the total (' . Dec::money($total) . ').');
                }
                $drAcct = $cashAcct;
            }
            // the part of a check that is not a savings deposit with its own clearing (loan payments, fees): it waits in
            // the default clearing account until it is cleared on Check Clearing
            $checkPending = $mode === 'check' ? Dec::sub($total, Dec::sum(array_values($checkDr)), 2) : '0';
            if ($mode === 'check' && $checkDr) {
                // each check deposit to savings in the clearing account of its code; the rest (loan payments, fees) in the default one
                $rest = Dec::sub($total, Dec::sum(array_values($checkDr)), 2);
                if (Dec::isPos($rest)) {
                    $checkDr[$drAcct] = Dec::add($checkDr[$drAcct] ?? '0', $rest, 2);
                }
                foreach (array_reverse($checkDr, true) as $acct => $amt) {
                    array_unshift($gl, ['account_id' => (int)$acct, 'debit' => $amt, 'memo' => 'Official receipt ' . $m['full_name'] . ' (check)', 'member_id' => $memberId]);
                }
            } else {
                array_unshift($gl, ['account_id' => $drAcct, 'debit' => $total, 'memo' => 'Official receipt ' . $m['full_name'], 'member_id' => $memberId]);
            }
            $large = Dec::of(Coop::setting('large_txn_amount', '0'));
            $approver = null;
            if ($leaving && $mode === 'savings') {
                $approver = Approval::check($pageKey, true, 'loan paid from the deposits of a member who has left (' . Dec::money($total) . ')');
            } elseif (Dec::isPos($large) && Dec::cmp($total, $large) > 0) {
                $approver = Approval::check($pageKey, true, 'receipt above the large transaction threshold (' . Dec::money($total) . ')');
            }
            $no = !empty($opt['receipt_no']) ? mb_substr(trim((string)$opt['receipt_no']), 0, 30) : Coop::nextReceiptNo('or', $branchId);
            if (DB::value("SELECT id FROM coop_receipts WHERE receipt_kind = 'or' AND receipt_no = :n", ['n' => $no])) {
                fail('Official receipt number ' . $no . ' is already used.');
            }
            $txnId = CoopLedger::post('coop_or', ['txn_date' => $date, 'memo' => 'OR ' . $no . ' ' . $m['full_name'] . (!empty($opt['memo']) ? ': ' . $opt['memo'] : ''), 'branch_id' => $branchId, 'member_id' => $memberId,
                'account_id' => $mode === 'savings' ? null : $drAcct, 'txn_no' => $no, 'approved_by' => $approver['id'] ?? null, 'reason' => !empty($opt['back_entry']) ? 'Back entry' : null], $gl, ['approved_by' => $approver['username'] ?? null]);
            foreach ($after as $fn) {
                $fn($txnId, $no);
            }
            $rid = self::recordReceipt($txnId, 'or', $no, $memberId, $branchId, $shift['id'] ?? null, $mode, $mode === 'savings' ? null : $drAcct, $total, $rlines, $cheques, null, !empty($opt['back_entry']));
            if (Dec::isPos($checkPending)) {
                DB::update('coop_receipts', ['check_pending' => Dec::fix($checkPending)], 'id = :id', ['id' => $rid]);
            }
            return ['txn_id' => $txnId, 'receipt_id' => $rid, 'receipt_no' => $no, 'total' => $total, 'change' => isset($cashReceived) ? Dec::sub($cashReceived, $total, 2) : '0'];
        });
    }

    /** Official receipts paid by check whose loan-payment / fee part is still in the clearing account */
    public static function checksToClear(): array
    {
        [$bf, $bp] = Coop::branchFilter('r.branch_id', 'ckb');
        return DB::all("SELECT r.*, t.txn_date, t.txn_no, m.full_name, m.member_no FROM coop_receipts r JOIN transactions t ON t.id = r.txn_id LEFT JOIN members m ON m.id = r.member_id
                         WHERE r.receipt_kind = 'or' AND r.check_pending > 0 AND r.check_cleared_txn_id IS NULL AND t.is_voided = 0" . $bf . ' ORDER BY t.txn_date, r.id', $bp);
    }

    /** The check of a receipt cleared: Dr the bank, Cr the clearing account, for the part that waited there */
    public static function clearReceiptCheck(int $receiptId, int $bankAccountId, string $date): int
    {
        return DB::tx(function () use ($receiptId, $bankAccountId, $date) {
            $r = DB::row('SELECT * FROM coop_receipts WHERE id = :id FOR UPDATE', ['id' => $receiptId]);
            if (!$r || !Dec::isPos($r['check_pending']) || $r['check_cleared_txn_id'] || DB::value('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $r['txn_id']])) {
                fail('That check is not waiting for clearing.');
            }
            if ($r['branch_id'] && !Coop::branchAllowed((int)$r['branch_id'])) {
                fail('That receipt belongs to another branch.');
            }
            Coop::checkDate($date);
            $t = DB::row('SELECT txn_date FROM transactions WHERE id = :t', ['t' => $r['txn_id']]);
            if ($date < $t['txn_date']) {
                fail('A check cannot clear before the date of its receipt (' . fdate($t['txn_date']) . ').');
            }
            $coci = (int)(Savings::code('CK4')['opposite_account_id'] ?? 0) ?: Accts::get('coop_coci');
            $memo = 'Check cleared: OR ' . $r['receipt_no'];
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => $memo, 'branch_id' => $r['branch_id'], 'member_id' => $r['member_id']], [
                ['account_id' => $bankAccountId, 'debit' => $r['check_pending'], 'memo' => $memo],
                ['account_id' => $coci, 'credit' => $r['check_pending'], 'memo' => $memo],
            ]);
            DB::update('coop_receipts', ['check_cleared_txn_id' => $txnId], 'id = :id', ['id' => $receiptId]);
            Audit::log('clear_check', 'teller', 'receipt', $receiptId, 'OR ' . $r['receipt_no'], ['amount' => (float)$r['check_pending'], 'reason' => $memo]);
            return $txnId;
        });
    }

    /**
     * A member disbursement voucher: savings withdrawal, share capital refund, distribution payout, in cash or cheque.
     * $items: ['kind' => savings|payable, account_id (savings) / gl account (payable), amount, description, batch_line_id]
     */
    public static function disburse(int $memberId, string $date, array $items, array $pay, array $opt, string $pageKey): array
    {
        return DB::tx(function () use ($memberId, $date, $items, $pay, $opt, $pageKey) {
            Coop::checkDate($date);
            $m = Coop::member($memberId);
            if (!$m) {
                fail('Choose the member.');
            }
            Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
            $shift = self::requireShift();
            $mode = ($pay['mode'] ?? 'cash') === 'cheque' ? 'check' : 'cash';
            if ($mode === 'check' && !(int)($pay['cash_account_id'] ?? 0)) {
                fail('Choose the bank account the cheque is drawn on.');
            }
            $cashAcct = (int)($pay['cash_account_id'] ?? 0) ?: ($shift ? (int)$shift['cash_account_id'] : self::userCashAccount());
            if (!$cashAcct) {
                fail('Choose the cash or bank account.');
            }
            // the settlement of a member who has left (resigned, terminated or deceased): always with a supervisor's approval
            $leaving = in_array($m['status'], ['resigned', 'terminated', 'deceased'], true);
            if ($leaving && $m['status'] === 'deceased' && trim((string)($opt['received_by'] ?? '')) === '') {
                fail('Enter who receives the payment for the deceased member (the beneficiary or the estate).');
            }
            $gl = [];
            $rlines = [];
            $after = [];
            $total = '0';
            $needsApproval = '';
            $branchId = $m['branch_id'] ? (int)$m['branch_id'] : Coop::defaultBranchId();
            foreach ($items as $it) {
                $amount = Dec::round($it['amount'] ?? '0', 2);
                if (!Dec::isPos($amount)) {
                    continue;
                }
                if ($it['kind'] === 'savings') {
                    $a = Savings::validate((int)$it['account_id'], 'debit', $amount, $date, ['closing' => !empty($it['closing']) || $leaving, 'settlement' => $leaving]);
                    if ((int)$a['member_id'] !== $memberId) {
                        fail('Account ' . $a['account_no'] . ' belongs to another member.');
                    }
                    if ($a['status'] === 'dormant') {
                        $needsApproval = $needsApproval ?: 'withdrawal from dormant account ' . $a['account_no'];
                    }
                    if ((int)$a['withdrawal_approval'] === 1) {
                        $needsApproval = $needsApproval ?: 'withdrawal from ' . $a['product_name'] . ' (the product requires approval)';
                    }
                    if ($a['product_type'] === 'share_capital' && !in_array($m['status'], ['resigned', 'terminated', 'deceased'], true) && empty($it['allow_active'])) {
                        $needsApproval = $needsApproval ?: 'share capital withdrawal of an active member';
                    }
                    $gl[] = ['account_id' => Savings::ledgerAccount($a), 'debit' => $amount, 'memo' => ($it['description'] ?? 'Withdrawal') . ' ' . $a['account_no'], 'member_id' => $memberId];
                    $rlines[] = ['kind' => 'savings', 'ref_id' => $a['id'], 'account_id' => Savings::ledgerAccount($a), 'description' => $a['product_name'] . ' ' . $a['account_no'], 'amount' => $amount];
                    $after[] = fn(int $txnId, string $no) => Savings::move(Savings::account((int)$a['id'], true), $it['code'] ?? 'CSW', 'debit', $amount, $date, ['txn_id' => $txnId, 'or_no' => $no, 'shift_id' => $shift['id'] ?? null, 'description' => $it['description'] ?? 'Withdrawal']);
                } elseif ($it['kind'] === 'payable') {
                    $acct = (int)($it['account_id'] ?? 0);
                    if (!$acct) {
                        fail('Choose the payable account.');
                    }
                    $gl[] = ['account_id' => $acct, 'debit' => $amount, 'memo' => $it['description'] ?? 'Payment of payable', 'member_id' => $memberId];
                    $rlines[] = ['kind' => 'other', 'ref_id' => $it['batch_line_id'] ?? null, 'account_id' => $acct, 'description' => $it['description'] ?? Coop::accountLabel($acct), 'amount' => $amount];
                    if (!empty($it['batch_line_id'])) {
                        $blId = (int)$it['batch_line_id'];
                        $after[] = fn(int $txnId, string $no) => DB::update('dist_batch_lines', ['paid_txn_id' => $txnId, 'paid_on' => $date], 'id = :id', ['id' => $blId]);
                    }
                } else {
                    fail('Unknown disbursement item.');
                }
                $total = Dec::add($total, $amount, 2);
            }
            if (!$rlines) {
                fail('Enter at least one item with an amount.');
            }
            if ($leaving) {
                $needsApproval = 'settlement of a ' . mb_strtolower(Coop::MEMBER_STATUSES[$m['status']] ?? $m['status']) . ' member (' . Dec::money($total) . ')';
            }
            $limit = Access::limit('coop_max_withdrawal');
            if (Approval::exceeds($limit, (float)$total)) {
                $needsApproval = $needsApproval ?: 'withdrawal above your limit (' . Dec::money($total) . ')';
            }
            $above = Dec::of(Coop::setting('withdrawal_approval_above', '0'));
            if (Dec::isPos($above) && Dec::cmp($total, $above) > 0) {
                $needsApproval = $needsApproval ?: 'withdrawal above the policy threshold of ' . Dec::money($above);
            }
            $approver = Approval::check($pageKey, $needsApproval !== '', $needsApproval);
            $receivedBy = mb_substr(trim((string)($opt['received_by'] ?? '')), 0, 120);
            $gl[] = ['account_id' => $cashAcct, 'credit' => $total, 'memo' => 'Paid to ' . ($receivedBy !== '' ? $receivedBy . ' for ' : '') . $m['full_name'] . ($mode === 'check' ? ' cheque ' . ($pay['cheque_no'] ?? '') : '')];
            $no = !empty($opt['voucher_no']) ? mb_substr(trim((string)$opt['voucher_no']), 0, 30) : Coop::nextReceiptNo('cv', $branchId);
            $txnId = CoopLedger::post('coop_cv', ['txn_date' => $date, 'memo' => 'Disbursement ' . $no . ' ' . $m['full_name'] . ($receivedBy !== '' ? ', received by ' . $receivedBy : '') . (!empty($opt['memo']) ? ': ' . $opt['memo'] : ''),
                'branch_id' => $branchId, 'member_id' => $memberId, 'payee_name' => $receivedBy !== '' ? $receivedBy : null,
                'account_id' => $cashAcct, 'txn_no' => $no, 'approved_by' => $approver['id'] ?? null], $gl, ['approved_by' => $approver['username'] ?? null]);
            foreach ($after as $fn) {
                $fn($txnId, $no);
            }
            $cheques = $mode === 'check' ? [['cheque_no' => mb_substr((string)($pay['cheque_no'] ?? ''), 0, 40), 'bank' => mb_substr((string)($pay['bank'] ?? ''), 0, 60), 'date' => $date, 'amount' => Dec::fix($total)]] : [];
            $rid = self::recordReceipt($txnId, 'cv', $no, $memberId, $branchId, $shift['id'] ?? null, $mode, $cashAcct, $total, $rlines, $cheques);
            return ['txn_id' => $txnId, 'receipt_id' => $rid, 'receipt_no' => $no, 'total' => $total];
        });
    }

    public static function receiptByTxn(int $txnId): ?array
    {
        $r = DB::row('SELECT r.*, m.full_name, m.member_no, u.full_name AS teller_name FROM coop_receipts r LEFT JOIN members m ON m.id = r.member_id LEFT JOIN users u ON u.id = r.created_by WHERE r.txn_id = :t', ['t' => $txnId]);
        if ($r) {
            $r['lines'] = DB::all('SELECT l.*, a.code AS account_code, a.name AS account_name FROM coop_receipt_lines l LEFT JOIN accounts a ON a.id = l.account_id WHERE l.receipt_id = :r ORDER BY l.line_no', ['r' => $r['id']]);
        }
        return $r;
    }
}
