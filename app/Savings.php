<?php
declare(strict_types=1);

/**
 * Savings, share capital and the deposit side of time deposits: products and their rate tiers, accounts,
 * the subsidiary ledger (sav_txns) and the general ledger entries behind every movement, interest posting,
 * holds, dormancy and check clearing.
 *
 * Every balance-changing call runs inside DB::tx and locks the account row (SELECT ... FOR UPDATE), so two
 * tellers posting to one account at the same time are serialised and the running balance is always right.
 */
final class Savings
{
    public const TYPES = ['ordinary' => 'Ordinary savings', 'special' => 'Special savings', 'time_deposit' => 'Time deposit', 'share_capital' => 'Share capital (fixed savings)'];
    public const POSTING = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-annually', 'annual' => 'Annually', 'maturity' => 'At maturity'];
    public const BASIS = ['adb' => 'Average daily balance', 'lowest' => 'Lowest balance of the period', 'ending' => 'Ending balance'];

    private static array $products = [];

    /**
     * The savings transaction number (Savings Transaction Center) of the transaction being posted: every row move()
     * writes while it is set carries it, so a placement, a settlement or a deposit keeps one number on all its rows.
     */
    private static ?string $transNo = null;

    /** Runs $fn with every savings row it writes numbered $transNo */
    public static function withTransNo(string $transNo, callable $fn)
    {
        $prev = self::$transNo;
        self::$transNo = $transNo;
        try {
            return $fn();
        } finally {
            self::$transNo = $prev;
        }
    }

    public static function currentTransNo(): ?string
    {
        return self::$transNo;
    }

    // ------------------------------------------------------------------ products

    public static function product(int $id): ?array
    {
        return self::$products[$id] ??= DB::row('SELECT * FROM sav_products WHERE id = :id', ['id' => $id]);
    }

    public static function resetProducts(): void
    {
        self::$products = [];
    }

    /** id => "code - name", optionally one product type only */
    public static function productOptions(?array $types = null, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, code, name, product_type FROM sav_products WHERE 1 = 1' . ($activeOnly ? ' AND is_active = 1' : '');
        $p = [];
        if ($types) {
            [$in, $p] = DB::in('pt', $types);
            $sql .= " AND product_type IN $in";
        }
        $out = [];
        foreach (DB::all($sql . ' ORDER BY sort_order, code', $p) as $r) {
            $out[(int)$r['id']] = $r['code'] . ' - ' . $r['name'];
        }
        return $out;
    }

    /** The tier that applies: amount within the range, term within the range (time deposits), latest effective date on or before $date */
    public static function rateFor(int $productId, string $amount, string $date, int $termDays = 0): ?array
    {
        // newest effective date first; within a date the tier with the highest matching amount_from wins
        $rows = DB::all('SELECT * FROM sav_product_rates WHERE product_id = :p AND effective_date <= :d ORDER BY effective_date DESC, amount_from DESC, term_from DESC, id DESC', ['p' => $productId, 'd' => $date]);
        foreach ($rows as $r) {
            if (Dec::cmp($amount, $r['amount_from']) < 0 || Dec::cmp($amount, $r['amount_to']) > 0) {
                continue;
            }
            if ((int)$r['term_to'] > 0 && ($termDays < (int)$r['term_from'] || $termDays > (int)$r['term_to'])) {
                continue;
            }
            if ((int)$r['term_to'] === 0 && (int)$r['term_from'] > 0 && $termDays < (int)$r['term_from']) {
                continue;
            }
            return $r;
        }
        return null;
    }

    public static function liabilityAccount(array $product): int
    {
        if ($product['liability_account_id']) {
            return (int)$product['liability_account_id'];
        }
        return Accts::get(match ($product['product_type']) {
            'share_capital' => 'share_capital',
            'time_deposit' => 'time_deposits',
            default => 'savings_deposits',
        });
    }

    public static function expenseAccount(array $product): int
    {
        if ($product['expense_account_id']) {
            return (int)$product['expense_account_id'];
        }
        return Accts::get($product['product_type'] === 'time_deposit' ? 'td_interest_expense' : 'savings_interest_expense');
    }

    // ------------------------------------------------------------------ accounts

    public static function account(int $id, bool $lock = false): ?array
    {
        return DB::row('SELECT a.*, p.code AS product_code, p.name AS product_name, p.product_type, p.liability_account_id, p.allow_withdrawal, p.withdrawal_approval,
                               p.min_balance, p.maintaining_balance, p.max_balance, p.min_deposit, p.min_withdrawal, p.lockin_months, p.dormancy_months, p.par_value,
                               m.full_name AS member_name, m.member_no, m.status AS member_status, m.hold_flag, m.hold_reason, m.alert_note
                          FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id WHERE a.id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    public static function byNo(string $no): ?array
    {
        $id = DB::value('SELECT id FROM sav_accounts WHERE account_no = :n', ['n' => trim($no)]);
        return $id ? self::account((int)$id) : null;
    }

    public static function available(array $a): string
    {
        return Dec::sub(Dec::sub($a['balance'], $a['uncleared'], 2), $a['hold_amount'], 2);
    }

    /** Opens an account; it waits for approval unless the user may approve on the Savings Account Center */
    public static function open(int $memberId, int $productId, ?int $branchId, string $openedOn, ?string $passbookNo, string $pageKey, ?int $passbookTypeId = null): int
    {
        return DB::tx(function () use ($memberId, $productId, $branchId, $openedOn, $passbookNo, $pageKey, $passbookTypeId) {
            $m = Coop::member($memberId);
            if (!$m) {
                fail('Member not found.');
            }
            Coop::requireBranch($m['branch_id'] ? (int)$m['branch_id'] : null);
            if ((int)$m['hold_flag'] === 1) {
                fail('The member is on hold: ' . $m['hold_reason']);
            }
            if (!in_array($m['status'], ['active', 'applicant'], true)) {
                fail('Only active members (or applicants opening their share capital) can open accounts.');
            }
            $p = self::product($productId);
            if (!$p || (int)$p['is_active'] !== 1) {
                fail('Choose an active product.');
            }
            if ($p['eligible_types'] && !in_array((string)(int)$m['member_type_id'], array_map('trim', explode(',', $p['eligible_types'])), true)) {
                fail('Product ' . $p['name'] . ' is not open to this member type.');
            }
            if (!valid_date($openedOn)) {
                fail('Enter the opening date.');
            }
            $branchId = $branchId ?: ($m['branch_id'] ? (int)$m['branch_id'] : Coop::defaultBranchId());
            $no = Coop::savAccountNo($m, $p, $branchId);
            $auto = Access::can($pageKey, 'approve');
            $id = DB::insert('sav_accounts', [
                'account_no' => $no, 'member_id' => $memberId, 'product_id' => $productId, 'branch_id' => $branchId,
                'passbook_no' => $passbookNo !== null && $passbookNo !== '' ? mb_substr($passbookNo, 0, 20) : ($m['passbook_no'] ?: null),
                'passbook_type_id' => $passbookTypeId ?: ($p['passbook_type_id'] ?: null),
                'status' => $auto ? 'active' : 'pending', 'opened_on' => $openedOn,
                'approved_by' => $auto ? Auth::id() : null, 'approved_at' => $auto ? date('Y-m-d H:i:s') : null, 'created_by' => Auth::id(),
            ]);
            Audit::log('create', 'sav', 'sav_account', $id, $no . ' ' . $m['full_name'], ['page_key' => $pageKey, 'snapshot' => ['account_no' => $no, 'product' => $p['name'], 'status' => $auto ? 'active' : 'pending']]);
            return $id;
        });
    }

    public static function approve(int $accountId, string $pageKey): void
    {
        DB::tx(function () use ($accountId, $pageKey) {
            $a = self::account($accountId, true);
            if (!$a || $a['status'] !== 'pending') {
                fail('The account is not waiting for approval.');
            }
            Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
            DB::update('sav_accounts', ['status' => 'active', 'approved_by' => Auth::id(), 'approved_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $accountId]);
            Audit::log('approve', 'sav', 'sav_account', $accountId, $a['account_no'], ['page_key' => $pageKey, 'changes' => [['field' => 'status', 'old' => 'pending', 'new' => 'active']]]);
        });
    }

    public static function close(int $accountId, string $reason, string $pageKey): void
    {
        DB::tx(function () use ($accountId, $reason, $pageKey) {
            $a = self::account($accountId, true);
            if (!$a) {
                fail('Account not found.');
            }
            Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
            if ($a['status'] === 'closed') {
                fail('The account is already closed.');
            }
            if (!Dec::isZero($a['balance'])) {
                fail('The balance must be zero before the account is closed (balance ' . Dec::money($a['balance']) . '). Withdraw or transfer it first.');
            }
            if (Dec::isPos($a['hold_amount'])) {
                fail('Release the holds on the account first.');
            }
            if ($a['product_type'] === 'time_deposit' && DB::value("SELECT id FROM td_placements WHERE account_id = :a AND status = 'active'", ['a' => $accountId])) {
                fail('The time deposit is still active.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason for closing.');
            }
            DB::update('sav_accounts', ['status' => 'closed', 'closed_on' => today(), 'close_reason' => mb_substr($reason, 0, 255)], 'id = :id', ['id' => $accountId]);
            Audit::log('close', 'sav', 'sav_account', $accountId, $a['account_no'], ['page_key' => $pageKey, 'reason' => $reason]);
        });
    }

    public static function reopen(int $accountId, string $reason, string $pageKey): void
    {
        DB::tx(function () use ($accountId, $reason, $pageKey) {
            $a = self::account($accountId, true);
            if (!$a || $a['status'] !== 'closed') {
                fail('The account is not closed.');
            }
            Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
            if (trim($reason) === '') {
                fail('Enter the reason for reopening.');
            }
            DB::update('sav_accounts', ['status' => 'active', 'closed_on' => null, 'close_reason' => null], 'id = :id', ['id' => $accountId]);
            Audit::log('reopen', 'sav', 'sav_account', $accountId, $a['account_no'], ['page_key' => $pageKey, 'reason' => $reason]);
        });
    }

    public static function restrict(int $accountId, bool $on, string $reason, string $pageKey): void
    {
        $a = self::account($accountId);
        if (!$a) {
            fail('Account not found.');
        }
        Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
        if ($on && trim($reason) === '') {
            fail('Enter the reason for the restriction.');
        }
        DB::update('sav_accounts', ['is_restricted' => $on ? 1 : 0, 'restrict_reason' => $on ? mb_substr($reason, 0, 255) : null], 'id = :id', ['id' => $accountId]);
        Audit::log($on ? 'restrict' : 'unrestrict', 'sav', 'sav_account', $accountId, $a['account_no'], ['page_key' => $pageKey, 'reason' => $reason]);
    }

    // ------------------------------------------------------------------ movements

    public static function code(string $code): array
    {
        $c = DB::row('SELECT * FROM sav_txn_codes WHERE code = :c', ['c' => $code]);
        if (!$c) {
            fail('Savings transaction code ' . $code . ' is not set up (Savings & Deposits > Savings Transaction Codes).');
        }
        return $c;
    }

    /**
     * Checks a movement before anything is posted. Returns the account (locked). $side credit = into the account.
     * $opt: allow_pending (opening deposits), skip_available (interest, system), is_system (no member status check)
     */
    public static function validate(int $accountId, string $side, string $amount, string $date, array $opt = []): array
    {
        $a = self::account($accountId, true);
        if (!$a) {
            fail('Savings account not found.');
        }
        self::checkMove($a, $side, $amount, $date, $opt);
        return $a;
    }

    /**
     * The rules of a movement on an account row as given (validate() reads it locked from the database; the review of
     * a batch file passes the row with the balance left by the rows above it). Fails with the reason.
     */
    public static function checkMove(array $a, string $side, string $amount, string $date, array $opt = []): void
    {
        Coop::requireBranch($a['branch_id'] ? (int)$a['branch_id'] : null);
        if (Dec::cmp($amount, '0') <= 0) {
            fail('The amount must be more than zero.');
        }
        if ($a['status'] === 'closed') {
            fail('Account ' . $a['account_no'] . ' is closed.');
        }
        if ($a['status'] === 'pending' && empty($opt['allow_pending'])) {
            fail('Account ' . $a['account_no'] . ' is still waiting for approval.');
        }
        if (empty($opt['is_system'])) {
            // the settlement of a member who has left: their deposits and share capital may be paid out or applied
            // to what they owe (the caller asks for supervisor approval); the hold put on a deceased member is for this
            $settling = !empty($opt['settlement']) && $side === 'debit' && in_array($a['member_status'], ['resigned', 'terminated', 'deceased'], true);
            if ((int)$a['hold_flag'] === 1 && !($settling && $a['member_status'] === 'deceased')) {
                fail('Member ' . $a['member_name'] . ' is on hold: ' . $a['hold_reason']);
            }
            if (!in_array($a['member_status'], ['active', 'applicant'], true) && !$settling) {
                fail('Member ' . $a['member_name'] . ' is ' . $a['member_status'] . '.' . (in_array($a['member_status'], ['resigned', 'terminated', 'deceased'], true) && $side === 'debit'
                    ? ' Pay out the balance with Settle the accounts on the member\'s page (settlement, with supervisor approval).' : ''));
            }
            if ((int)$a['is_restricted'] === 1 && $side === 'debit') {
                fail('Account ' . $a['account_no'] . ' is restricted: ' . $a['restrict_reason']);
            }
        }
        if ($side === 'credit') {
            // skip_min_deposit: a contribution billed by Fixed Share Capital Billing (its amount is set there)
            if (empty($opt['is_system']) && empty($opt['skip_min_deposit']) && Dec::isPos($a['min_deposit']) && Dec::cmp($amount, $a['min_deposit']) < 0) {
                fail('The minimum deposit for ' . $a['product_name'] . ' is ' . Dec::money($a['min_deposit']) . '.');
            }
            if (Dec::isPos($a['max_balance']) && Dec::cmp(Dec::add($a['balance'], $amount), $a['max_balance']) > 0) {
                fail('The deposit would take the account above the maximum balance of ' . Dec::money($a['max_balance']) . ' for ' . $a['product_name'] . '.');
            }
        } else {
            if (empty($opt['is_system']) && (int)$a['allow_withdrawal'] !== 1) {
                fail('Withdrawals are not allowed on ' . $a['product_name'] . '.');
            }
            if (empty($opt['skip_available'])) {
                $avail = self::available($a);
                $left = Dec::sub($avail, $amount);
                $floor = empty($opt['closing']) ? Dec::of($a['min_balance']) : '0';
                if (Dec::cmp($left, $floor) < 0) {
                    fail('Insufficient available balance on ' . $a['account_no'] . ': available ' . Dec::money($avail)
                        . (Dec::isPos($a['uncleared']) ? ' (' . Dec::money($a['uncleared']) . ' still clearing)' : '')
                        . (Dec::isPos($a['hold_amount']) ? ' (' . Dec::money($a['hold_amount']) . ' on hold)' : '')
                        . (Dec::isPos($floor) ? ', and ' . Dec::money($floor) . ' must stay as the minimum balance' : '') . '.');
                }
                if (empty($opt['is_system']) && Dec::isPos($a['min_withdrawal']) && Dec::cmp($amount, $a['min_withdrawal']) < 0) {
                    fail('The minimum withdrawal for ' . $a['product_name'] . ' is ' . Dec::money($a['min_withdrawal']) . '.');
                }
            }
            if (empty($opt['is_system']) && empty($opt['settlement']) && (int)$a['lockin_months'] > 0 && $a['opened_on'] && Coop::addMonths($a['opened_on'], (int)$a['lockin_months']) > $date) {
                fail('Account ' . $a['account_no'] . ' is within its lock-in period until ' . fdate(Coop::addMonths($a['opened_on'], (int)$a['lockin_months'])) . '.');
            }
        }
    }

    /**
     * Writes one subsidiary ledger row and updates the account. Call after validate() inside the same DB::tx.
     * $opt: description, txn_id, or_no, teller_id, shift_id, clearing_days, ref_type, ref_id, value_date,
     *       trans_no (else the number of withTransNo()), opposite_account_id, cheque_json, is_back_entry,
     *       negate (an error correction: the amount is written negative in the column of its code, so the passbook
     *       shows -8,033.31 under withdrawals and the balance goes back up), corrects_id (the row it negates)
     */
    public static function move(array $a, string $code, string $side, string $amount, string $date, array $opt = []): int
    {
        $amount = Dec::round($amount, 2);
        if (!empty($opt['negate'])) {
            $amount = Dec::neg($amount);
        }
        $credit = $side === 'credit' ? $amount : '0';
        $debit = $side === 'debit' ? $amount : '0';
        $newBal = Dec::add(Dec::sub($a['balance'], $debit), $credit, 2);
        $clearingDays = empty($opt['negate']) ? (int)($opt['clearing_days'] ?? 0) : 0;
        $cleared = $clearingDays > 0 && $side === 'credit' ? 0 : 1;
        // a back-dated movement: the running balance of the later rows is rebuilt afterwards
        $later = (int)DB::value('SELECT COUNT(*) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND txn_date > :d', ['a' => $a['id'], 'd' => $date]);
        $id = DB::insert('sav_txns', [
            'account_id' => $a['id'], 'txn_date' => $date, 'value_date' => $opt['value_date'] ?? $date, 'txn_code' => $code,
            'description' => mb_substr((string)($opt['description'] ?? ''), 0, 160) ?: null,
            'debit' => Dec::fix($debit), 'credit' => Dec::fix($credit), 'balance_after' => Dec::fix($newBal),
            'txn_id' => $opt['txn_id'] ?? null, 'or_no' => $opt['or_no'] ?? null, 'teller_id' => $opt['teller_id'] ?? Auth::id(), 'shift_id' => $opt['shift_id'] ?? null,
            'clearing_date' => $cleared ? null : Coop::addDays($date, $clearingDays), 'is_cleared' => $cleared,
            'ref_type' => $opt['ref_type'] ?? null, 'ref_id' => $opt['ref_id'] ?? null, 'created_by' => Auth::id(),
        ] + self::extraColumns($opt));
        $upd = ['balance' => Dec::fix($newBal), 'last_txn_date' => $a['last_txn_date'] && $a['last_txn_date'] > $date ? $a['last_txn_date'] : $date];
        if (!$cleared) {
            $upd['uncleared'] = Dec::fix(Dec::add($a['uncleared'], $credit));
        }
        if ($code === 'INT') {
            $upd['interest_earned'] = Dec::fix(Dec::add($a['interest_earned'], $credit));
            $upd['last_interest_date'] = $date;
        }
        if ($a['status'] === 'dormant' && empty($opt['is_system'])) {
            $upd['status'] = 'active';
            $upd['dormant_since'] = null;
            Audit::log('reactivate', 'sav', 'sav_account', (int)$a['id'], $a['account_no'], ['reason' => 'Transaction on a dormant account']);
        }
        DB::update('sav_accounts', $upd, 'id = :id', ['id' => $a['id']]);
        if ($later > 0) {
            self::rebuild((int)$a['id']);
        }
        return $id;
    }

    /** The columns of the savings transaction center (upgrade 2026_12_27), when the database has them */
    private static function extraColumns(array $opt): array
    {
        if (!self::centerReady()) {
            return [];
        }
        $no = array_key_exists('trans_no', $opt) ? $opt['trans_no'] : self::$transNo;
        return [
            'trans_no' => $no !== null && $no !== '' ? mb_substr((string)$no, 0, 20) : null,
            'opposite_account_id' => !empty($opt['opposite_account_id']) ? (int)$opt['opposite_account_id'] : null,
            'cheque_json' => !empty($opt['cheques']) ? json_encode(array_values($opt['cheques']), JSON_UNESCAPED_UNICODE) : null,
            'is_back_entry' => !empty($opt['is_back_entry']) ? 1 : 0,
            'corrects_id' => !empty($opt['corrects_id']) ? (int)$opt['corrects_id'] : null,
        ];
    }

    /** True once sav_txns carries the transaction number (upgrade 2026_12_27) */
    public static function centerReady(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(trans_no) FROM sav_txns WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** Rebuilds the running balance, the ledger balance, the uncleared amount and the interest total from the rows */
    public static function rebuild(int $accountId): void
    {
        $bal = '0';
        $unc = '0';
        $int = '0';
        $last = null;
        foreach (DB::all('SELECT id, txn_date, txn_code, debit, credit, is_voided, is_cleared FROM sav_txns WHERE account_id = :a ORDER BY txn_date, id', ['a' => $accountId]) as $r) {
            if ((int)$r['is_voided'] === 1) {
                continue;
            }
            $bal = Dec::add(Dec::sub($bal, $r['debit']), $r['credit'], 2);
            DB::run('UPDATE sav_txns SET balance_after = :b WHERE id = :id', ['b' => Dec::fix($bal), 'id' => $r['id']]);
            if ((int)$r['is_cleared'] === 0) {
                $unc = Dec::add($unc, $r['credit'], 2);
            }
            if ($r['txn_code'] === 'INT') {
                $int = Dec::add($int, $r['credit'], 2);
            }
            $last = $r['txn_date'];
        }
        if (Dec::isNeg($bal)) {
            fail('The account would be overdrawn (' . Dec::money($bal) . ').');
        }
        DB::update('sav_accounts', ['balance' => Dec::fix($bal), 'uncleared' => Dec::fix($unc), 'interest_earned' => Dec::fix($int), 'last_txn_date' => $last], 'id = :id', ['id' => $accountId]);
    }

    /** Replays the account without the rows of $txnId: the lowest balance reached when it goes below zero, else null */
    public static function simulateVoid(int $accountId, int $txnId): ?string
    {
        $bal = '0';
        $min = '0';
        foreach (DB::all('SELECT debit, credit, is_voided, txn_id FROM sav_txns WHERE account_id = :a ORDER BY txn_date, id', ['a' => $accountId]) as $r) {
            if ((int)$r['is_voided'] === 1 || (int)$r['txn_id'] === $txnId) {
                continue;
            }
            $bal = Dec::add(Dec::sub($bal, $r['debit']), $r['credit'], 2);
            $min = Dec::min($min, $bal);
        }
        return Dec::isNeg($min) ? $min : null;
    }

    /** The GL side of one movement: [account_id, side] of the member's deposit liability (share capital: equity) */
    public static function ledgerAccount(array $a): int
    {
        $p = self::product((int)$a['product_id']);
        return self::liabilityAccount($p);
    }

    // ------------------------------------------------------------------ simple deposit / withdrawal (one account, one voucher)

    /**
     * A cash (or check) deposit posted on its own: Dr cash / COCI, Cr member liability.
     * Returns [txn_id, sav_txn_id].
     */
    public static function deposit(int $accountId, string $code, string $amount, string $date, int $cashAccountId, array $opt = []): array
    {
        return DB::tx(function () use ($accountId, $code, $amount, $date, $cashAccountId, $opt) {
            $c = self::code($code);
            if ($c['entry_side'] !== 'credit') {
                fail('Code ' . $code . ' is not a deposit code.');
            }
            $a = self::validate($accountId, 'credit', $amount, $date, ['allow_pending' => !empty($opt['allow_pending'])]);
            $drAcct = (int)$c['is_check_deposit'] === 1 ? (int)($c['opposite_account_id'] ?: Accts::get('coop_coci')) : ($c['opposite_kind'] === 'gl' && $c['opposite_account_id'] ? (int)$c['opposite_account_id'] : $cashAccountId);
            $memo = ($opt['description'] ?? $c['description']) . ' ' . $a['account_no'];
            $txnId = CoopLedger::post($opt['txn_type'] ?? 'coop_or', [
                'txn_date' => $date, 'memo' => $memo, 'branch_id' => $a['branch_id'], 'member_id' => $a['member_id'], 'account_id' => $drAcct,
                'txn_no' => $opt['txn_no'] ?? null, 'ref_no' => $opt['ref_no'] ?? null,
            ], [
                ['account_id' => $drAcct, 'debit' => $amount, 'memo' => $memo],
                ['account_id' => self::ledgerAccount($a), 'credit' => $amount, 'memo' => $memo],
            ]);
            $savId = self::move($a, $code, 'credit', $amount, $date, $opt + ['txn_id' => $txnId, 'clearing_days' => (int)$c['clearing_days'], 'description' => $opt['description'] ?? $c['description']]);
            return [$txnId, $savId];
        });
    }

    /** A withdrawal paid in cash: Dr member liability, Cr cash. Returns [txn_id, sav_txn_id]. */
    public static function withdraw(int $accountId, string $code, string $amount, string $date, int $cashAccountId, array $opt = []): array
    {
        return DB::tx(function () use ($accountId, $code, $amount, $date, $cashAccountId, $opt) {
            $c = self::code($code);
            if ($c['entry_side'] !== 'debit') {
                fail('Code ' . $code . ' is not a withdrawal code.');
            }
            $a = self::validate($accountId, 'debit', $amount, $date, ['closing' => !empty($opt['closing'])]);
            $memo = ($opt['description'] ?? $c['description']) . ' ' . $a['account_no'];
            $txnId = CoopLedger::post($opt['txn_type'] ?? 'coop_cv', [
                'txn_date' => $date, 'memo' => $memo, 'branch_id' => $a['branch_id'], 'member_id' => $a['member_id'], 'account_id' => $cashAccountId,
                'txn_no' => $opt['txn_no'] ?? null, 'ref_no' => $opt['ref_no'] ?? null, 'approved_by' => $opt['approved_by_id'] ?? null,
            ], [
                ['account_id' => self::ledgerAccount($a), 'debit' => $amount, 'memo' => $memo],
                ['account_id' => $cashAccountId, 'credit' => $amount, 'memo' => $memo],
            ], ['approved_by' => $opt['approved_by'] ?? null]);
            $savId = self::move($a, $code, 'debit', $amount, $date, $opt + ['txn_id' => $txnId, 'description' => $opt['description'] ?? $c['description']]);
            return [$txnId, $savId];
        });
    }

    /** Book transfer between two member accounts (same or different members): Dr liability of A, Cr liability of B */
    public static function transfer(int $fromId, int $toId, string $amount, string $date, string $memo, array $opt = []): array
    {
        return DB::tx(function () use ($fromId, $toId, $amount, $date, $memo, $opt) {
            if ($fromId === $toId) {
                fail('Choose two different accounts.');
            }
            $from = self::validate($fromId, 'debit', $amount, $date);
            $to = self::validate($toId, 'credit', $amount, $date, ['allow_pending' => true, 'is_system' => true]);
            $txnId = CoopLedger::post('coop_jv', [
                'txn_date' => $date, 'memo' => 'Transfer ' . $from['account_no'] . ' to ' . $to['account_no'] . ($memo ? ': ' . $memo : ''),
                'branch_id' => $from['branch_id'], 'member_id' => $from['member_id'], 'ref_no' => $opt['ref_no'] ?? null,
            ], [
                ['account_id' => self::ledgerAccount($from), 'debit' => $amount, 'memo' => 'Transfer out ' . $from['account_no'], 'member_id' => $from['member_id']],
                ['account_id' => self::ledgerAccount($to), 'credit' => $amount, 'memo' => 'Transfer in ' . $to['account_no'], 'member_id' => $to['member_id']],
            ]);
            $a = self::move($from, 'TRO', 'debit', $amount, $date, ['txn_id' => $txnId, 'description' => 'Transfer to ' . $to['account_no'] . ($memo ? ' ' . $memo : ''), 'ref_type' => 'transfer', 'ref_id' => $toId]);
            $b = self::move($to, 'TRI', 'credit', $amount, $date, ['txn_id' => $txnId, 'description' => 'Transfer from ' . $from['account_no'] . ($memo ? ' ' . $memo : ''), 'ref_type' => 'transfer', 'ref_id' => $fromId, 'is_system' => true]);
            return [$txnId, $a, $b];
        });
    }

    // ------------------------------------------------------------------ holds

    /**
     * Hold: an amount of the balance earmarked, so it cannot be withdrawn until it is released (Unhold). Unlike a
     * restriction (no withdrawal at all), the rest of the balance stays withdrawable. A hold placed by hand is at most
     * what is withdrawable now; a loan's hold (collateral) is placed by the loan.
     */
    public static function placeHold(int $accountId, string $amount, string $reason, ?int $loanId = null, ?string $pageKey = null): int
    {
        return DB::tx(function () use ($accountId, $amount, $reason, $loanId, $pageKey) {
            $a = self::account($accountId, true);
            if (!$a) {
                fail('Account not found.');
            }
            if ($a['status'] === 'closed') {
                fail('Account ' . $a['account_no'] . ' is closed.');
            }
            $amount = Dec::round($amount, 2);
            if (Dec::cmp($amount, '0') <= 0) {
                fail('Enter the amount to hold.');
            }
            if ($loanId === null && Dec::cmp($amount, self::available($a)) > 0) {
                fail('Only ' . Dec::money(Dec::max('0', self::available($a))) . ' of ' . $a['account_no'] . ' can be held: the balance is ' . Dec::money($a['balance'])
                    . (Dec::isPos($a['hold_amount']) ? ', ' . Dec::money($a['hold_amount']) . ' already on hold' : '') . (Dec::isPos($a['uncleared']) ? ', ' . Dec::money($a['uncleared']) . ' in checks clearing' : '') . '.');
            }
            if (trim($reason) === '') {
                fail('Enter the reason of the hold.');
            }
            $id = DB::insert('sav_holds', ['account_id' => $accountId, 'amount' => Dec::fix($amount), 'reason' => mb_substr(trim($reason), 0, 255), 'loan_account_id' => $loanId, 'placed_by' => Auth::id()]);
            self::refreshHoldAmount($accountId);
            Audit::log('hold', 'sav', 'sav_account', $accountId, $a['account_no'], ['amount' => (float)$amount, 'reason' => $reason, 'page_key' => $pageKey]);
            return $id;
        });
    }

    /** Unhold: the amount held can be withdrawn again */
    public static function releaseHold(int $holdId, string $reason = '', ?string $pageKey = null): void
    {
        DB::tx(function () use ($holdId, $reason, $pageKey) {
            $h = DB::row('SELECT * FROM sav_holds WHERE id = :id FOR UPDATE', ['id' => $holdId]);
            if (!$h || $h['released_at']) {
                fail('Hold not found or already released.');
            }
            DB::update('sav_holds', ['released_at' => date('Y-m-d H:i:s'), 'released_by' => Auth::id()], 'id = :id', ['id' => $holdId]);
            self::refreshHoldAmount((int)$h['account_id']);
            Audit::log('release_hold', 'sav', 'sav_account', (int)$h['account_id'], 'Hold ' . Dec::money($h['amount']), ['reason' => $reason, 'amount' => (float)$h['amount'], 'page_key' => $pageKey]);
        });
    }

    /** Releases every hold a loan placed (the loan was paid, written off or its release voided) */
    public static function refreshHolds(int $loanId): void
    {
        foreach (DB::column('SELECT DISTINCT account_id FROM sav_holds WHERE loan_account_id = :l', ['l' => $loanId]) as $aid) {
            self::refreshHoldAmount((int)$aid);
        }
    }

    public static function refreshHoldAmount(int $accountId): void
    {
        DB::run('UPDATE sav_accounts SET hold_amount = (SELECT COALESCE(SUM(amount),0) FROM sav_holds WHERE account_id = :a1 AND released_at IS NULL) WHERE id = :a2', ['a1' => $accountId, 'a2' => $accountId]);
    }

    // ------------------------------------------------------------------ check clearing

    /** Check deposits past their clearing date (or all uncleared) */
    public static function uncleared(?string $asOf = null, bool $dueOnly = true): array
    {
        [$bf, $bp] = Coop::branchFilter('a.branch_id');
        return DB::all('SELECT t.*, a.account_no, a.branch_id, m.full_name, p.name AS product_name FROM sav_txns t JOIN sav_accounts a ON a.id = t.account_id JOIN members m ON m.id = a.member_id JOIN sav_products p ON p.id = a.product_id
                         WHERE t.is_cleared = 0 AND t.is_voided = 0' . ($dueOnly ? ' AND t.clearing_date <= :d' : '') . $bf . ' ORDER BY t.clearing_date, t.id', ($dueOnly ? ['d' => $asOf ?? today()] : []) + $bp);
    }

    /** The check cleared: the money is in the bank. Dr bank, Cr COCI; the deposit becomes available. */
    public static function clearCheck(int $savTxnId, int $bankAccountId, string $date): int
    {
        return DB::tx(function () use ($savTxnId, $bankAccountId, $date) {
            $t = DB::row('SELECT * FROM sav_txns WHERE id = :id FOR UPDATE', ['id' => $savTxnId]);
            if (!$t || (int)$t['is_cleared'] === 1 || (int)$t['is_voided'] === 1) {
                fail('That check deposit is not waiting for clearing.');
            }
            $a = self::account((int)$t['account_id'], true);
            $c = self::code($t['txn_code']);
            $coci = (int)($c['opposite_account_id'] ?: Accts::get('coop_coci'));
            $memo = 'Check cleared: ' . ($t['description'] ?: $c['description']) . ' ' . $a['account_no'];
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => $memo, 'branch_id' => $a['branch_id'], 'member_id' => $a['member_id']], [
                ['account_id' => $bankAccountId, 'debit' => $t['credit'], 'memo' => $memo],
                ['account_id' => $coci, 'credit' => $t['credit'], 'memo' => $memo],
            ]);
            DB::update('sav_txns', ['is_cleared' => 1, 'cleared_txn_id' => $txnId], 'id = :id', ['id' => $savTxnId]);
            DB::update('sav_accounts', ['uncleared' => Dec::fix(Dec::max('0', Dec::sub($a['uncleared'], $t['credit'])))], 'id = :id', ['id' => $a['id']]);
            Audit::log('clear_check', 'sav', 'sav_account', (int)$a['id'], $a['account_no'], ['amount' => (float)$t['credit'], 'reason' => $memo]);
            return $txnId;
        });
    }

    /** The check bounced: the deposit is taken out again (RTC), Dr member liability, Cr COCI */
    public static function returnCheck(int $savTxnId, string $date, string $reason): int
    {
        return DB::tx(function () use ($savTxnId, $date, $reason) {
            $t = DB::row('SELECT * FROM sav_txns WHERE id = :id FOR UPDATE', ['id' => $savTxnId]);
            if (!$t || (int)$t['is_cleared'] === 1 || (int)$t['is_voided'] === 1) {
                fail('That check deposit is not waiting for clearing.');
            }
            $a = self::account((int)$t['account_id'], true);
            $c = self::code($t['txn_code']);
            $coci = (int)($c['opposite_account_id'] ?: Accts::get('coop_coci'));
            $memo = 'Returned check: ' . $reason . ' ' . $a['account_no'];
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $date, 'memo' => $memo, 'branch_id' => $a['branch_id'], 'member_id' => $a['member_id'], 'reason' => $reason], [
                ['account_id' => self::ledgerAccount($a), 'debit' => $t['credit'], 'memo' => $memo],
                ['account_id' => $coci, 'credit' => $t['credit'], 'memo' => $memo],
            ]);
            DB::update('sav_txns', ['is_cleared' => 1, 'cleared_txn_id' => $txnId], 'id = :id', ['id' => $savTxnId]);
            $a['uncleared'] = Dec::fix(Dec::max('0', Dec::sub($a['uncleared'], $t['credit'])));
            DB::update('sav_accounts', ['uncleared' => $a['uncleared']], 'id = :id', ['id' => $a['id']]);
            self::move($a, 'RTC', 'debit', $t['credit'], $date, ['txn_id' => $txnId, 'description' => 'Returned check ' . $reason, 'is_system' => true, 'ref_type' => 'returned', 'ref_id' => $savTxnId]);
            return $txnId;
        });
    }

    // ------------------------------------------------------------------ interest

    /** Day-weighted balances of an account over a period, from the rows: [adb, lowest, ending, days] */
    public static function balances(int $accountId, string $from, string $to, bool $useValueDate = true): array
    {
        $col = $useValueDate ? 'COALESCE(value_date, txn_date)' : 'txn_date';
        $opening = Dec::of(DB::value("SELECT COALESCE(SUM(credit - debit),0) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND $col < :f", ['a' => $accountId, 'f' => $from]));
        $moves = DB::all("SELECT $col AS d, SUM(credit - debit) AS delta FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND $col BETWEEN :f AND :t GROUP BY $col ORDER BY $col", ['a' => $accountId, 'f' => $from, 't' => $to]);
        $days = Coop::daysBetween($from, $to) + 1;
        $bal = $opening;
        $lowest = null;          // the lowest end-of-day balance of the period
        $sum = '0';
        $cursor = $from;
        foreach ($moves as $mv) {
            $n = Coop::daysBetween($cursor, $mv['d']);
            if ($n > 0) {
                // $bal stood for $n whole days
                $sum = Dec::add($sum, Dec::mul($bal, (string)$n, 2), 2);
                $lowest = $lowest === null ? $bal : Dec::min($lowest, $bal);
            }
            $bal = Dec::add($bal, $mv['delta'], 2);
            $cursor = $mv['d'];
        }
        $n = Coop::daysBetween($cursor, $to) + 1;
        $sum = Dec::add($sum, Dec::mul($bal, (string)$n, 2), 2);
        $lowest = $lowest === null ? $bal : Dec::min($lowest, $bal);
        return ['adb' => Dec::div($sum, (string)$days, 2), 'lowest' => $lowest, 'ending' => $bal, 'days' => $days, 'opening' => $opening];
    }

    /**
     * How balances() works out the average daily balance, step by step (the computation shown on a distribution batch):
     * the balance at the start of the period, then each stretch of days the balance stood the same: [from, to, days,
     * balance, balance x days], the sum of the balance-days, the days of the period, the average daily balance and the
     * ending balance. The same arithmetic as balances(), so the figures agree to the centavo.
     */
    public static function balanceSegments(int $accountId, string $from, string $to, bool $useValueDate = true): array
    {
        $col = $useValueDate ? 'COALESCE(value_date, txn_date)' : 'txn_date';
        $opening = Dec::of(DB::value("SELECT COALESCE(SUM(credit - debit),0) FROM sav_txns WHERE account_id = :a AND is_voided = 0 AND $col < :f", ['a' => $accountId, 'f' => $from]));
        $moves = DB::all("SELECT $col AS d, SUM(credit - debit) AS delta, SUM(credit) AS cr, SUM(debit) AS dr, COUNT(*) AS n FROM sav_txns
                           WHERE account_id = :a AND is_voided = 0 AND $col BETWEEN :f AND :t GROUP BY $col ORDER BY $col", ['a' => $accountId, 'f' => $from, 't' => $to]);
        $days = Coop::daysBetween($from, $to) + 1;
        $bal = $opening;
        $sum = '0';
        $cursor = $from;
        $segments = [];
        $add = function (string $start, int $n, string $bal) use (&$segments, &$sum) {
            $prod = Dec::mul($bal, (string)$n, 2);
            $sum = Dec::add($sum, $prod, 2);
            $segments[] = ['from' => $start, 'to' => date('Y-m-d', strtotime($start . ' +' . ($n - 1) . ' days')), 'days' => $n, 'balance' => $bal, 'product' => $prod];
        };
        foreach ($moves as $mv) {
            $n = Coop::daysBetween($cursor, $mv['d']);
            if ($n > 0) {
                $add($cursor, $n, $bal);
            }
            $bal = Dec::add($bal, $mv['delta'], 2);
            $cursor = $mv['d'];
        }
        $add($cursor, Coop::daysBetween($cursor, $to) + 1, $bal);
        // each change of the balance: the day, what came in and went out
        $changes = array_map(fn($m) => ['date' => $m['d'], 'in' => Dec::of($m['cr']), 'out' => Dec::of($m['dr']), 'count' => (int)$m['n']], $moves);
        return ['opening' => $opening, 'segments' => $segments, 'changes' => $changes, 'sum' => $sum, 'days' => $days, 'adb' => Dec::div($sum, (string)$days, 2), 'ending' => $bal];
    }

    /**
     * Computes (preview) or posts the interest of one product for a period. One voucher for the product:
     * Dr interest expense (gross), Cr member liability (net), Cr withholding tax account (tax).
     */
    public static function postInterest(int $productId, string $from, string $to, string $postingDate, bool $post, string $pageKey): array
    {
        $p = self::product($productId);
        if (!$p) {
            fail('Product not found.');
        }
        if ($p['product_type'] === 'time_deposit') {
            fail('Time deposit interest is handled by the time deposit maturity and accrual processes.');
        }
        if (!valid_date($from) || !valid_date($to) || $from > $to) {
            fail('Enter the period.');
        }
        if ($post) {
            Coop::checkDate($postingDate);
            if (DB::value("SELECT id FROM sav_interest_runs WHERE product_id = :p AND period_to = :t AND status = 'posted'", ['p' => $productId, 't' => $to])) {
                fail('Interest for ' . $p['name'] . ' through ' . fdate($to) . ' has already been posted.');
            }
            $overlap = DB::value("SELECT id FROM sav_interest_runs WHERE product_id = :p AND status = 'posted' AND period_to >= :f", ['p' => $productId, 'f' => $from]);
            if ($overlap) {
                fail('A posted interest run for ' . $p['name'] . ' already covers part of this period. Void that run first, or start this period after its end.');
            }
        }
        [$bf, $bp] = Coop::branchFilter('a.branch_id');
        $accounts = DB::all("SELECT a.* FROM sav_accounts a WHERE a.product_id = :p AND a.status IN ('active','dormant') AND (a.opened_on IS NULL OR a.opened_on <= :t)" . $bf . ' ORDER BY a.account_no', ['p' => $productId, 't' => $to] + $bp);
        $lines = [];
        $totInt = '0';
        $totTax = '0';
        $calendar = (string)((int)$p['calendar_days'] ?: 365);
        foreach ($accounts as $a) {
            // an account opened during the period earns from its opening day only (and is measured from then on)
            $aFrom = $a['opened_on'] && $a['opened_on'] > $from ? $a['opened_on'] : $from;
            $b = self::balances((int)$a['id'], $aFrom, $to);
            $basis = $b[$p['interest_basis']] ?? $b['adb'];
            if (Dec::cmp($basis, '0') <= 0) {
                continue;
            }
            $tier = self::rateFor($productId, $basis, $to);
            if (!$tier || Dec::isZero($tier['rate'])) {
                continue;
            }
            if (Dec::isPos($p['maintaining_balance']) && Dec::cmp($b['lowest'], $p['maintaining_balance']) < 0 && $p['interest_basis'] !== 'ending') {
                continue;       // fell below the maintaining balance during the period: no interest
            }
            // interest = basis x rate / 100 x days / calendar days
            $interest = Dec::round(Dec::div(Dec::mul(Dec::mul($basis, $tier['rate'], 10), (string)$b['days'], 10), Dec::mul('100', $calendar, 4), 10), 2);
            if (Dec::cmp($interest, '0.01') < 0) {
                continue;
            }
            $tax = Dec::isPos($p['tax_rate']) ? Dec::pct($interest, $p['tax_rate']) : '0';
            $net = Dec::sub($interest, $tax, 2);
            $lines[] = ['account' => $a, 'basis' => $basis, 'days' => $b['days'], 'rate' => $tier['rate'], 'interest' => $interest, 'tax' => $tax, 'net' => $net];
            $totInt = Dec::add($totInt, $interest, 2);
            $totTax = Dec::add($totTax, $tax, 2);
        }
        $result = ['product' => $p, 'lines' => $lines, 'total_interest' => $totInt, 'total_tax' => $totTax, 'count' => count($lines), 'run_id' => null, 'txn_id' => null];
        if (!$post) {
            return $result;
        }
        if (!$lines) {
            fail('No account earns interest for this period.');
        }
        return DB::tx(function () use ($p, $productId, $from, $to, $postingDate, $lines, $totInt, $totTax, $result, $pageKey) {
            $liab = self::liabilityAccount($p);
            $exp = self::expenseAccount($p);
            $taxAcct = Dec::isPos($totTax) ? (int)($p['tax_account_id'] ?: Accts::get('wht_payable')) : null;
            $gl = [['account_id' => $exp, 'debit' => $totInt, 'memo' => 'Interest on ' . $p['name'] . ' ' . fdate($from) . ' - ' . fdate($to)]];
            // one liability line per member, so the subsidiary ledger of the control account shows each member's interest
            foreach ($lines as $l) {
                $gl[] = ['account_id' => $liab, 'credit' => $l['net'], 'memo' => 'Interest ' . $l['account']['account_no'], 'member_id' => $l['account']['member_id'], 'class_id' => Coop::branchClassId($l['account']['branch_id'] ? (int)$l['account']['branch_id'] : null)];
            }
            if ($taxAcct) {
                $gl[] = ['account_id' => $taxAcct, 'credit' => $totTax, 'memo' => 'Withholding tax on interest ' . $p['name']];
            }
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $postingDate, 'memo' => 'Interest posting: ' . $p['name'] . ' ' . fdate($from) . ' to ' . fdate($to), 'branch_id' => Coop::userBranchId()], $gl);
            $runId = DB::insert('sav_interest_runs', ['product_id' => $productId, 'period_from' => $from, 'period_to' => $to, 'posting_date' => $postingDate, 'txn_id' => $txnId,
                'accounts_count' => count($lines), 'total_interest' => Dec::fix($totInt), 'total_tax' => Dec::fix($totTax), 'status' => 'posted', 'run_by' => Auth::id()]);
            foreach ($lines as $l) {
                $a = self::account((int)$l['account']['id'], true);
                $sid = self::move($a, 'INT', 'credit', $l['interest'], $postingDate, ['txn_id' => $txnId, 'description' => 'Interest ' . fdate($from) . '-' . fdate($to), 'ref_type' => 'interest_run', 'ref_id' => $runId, 'is_system' => true]);
                if (Dec::isPos($l['tax'])) {
                    $a = self::account((int)$l['account']['id'], true);
                    self::move($a, 'WTX', 'debit', $l['tax'], $postingDate, ['txn_id' => $txnId, 'description' => 'Withholding tax on interest', 'ref_type' => 'interest_run', 'ref_id' => $runId, 'is_system' => true]);
                }
                DB::insert('sav_interest_lines', ['run_id' => $runId, 'account_id' => $l['account']['id'], 'basis_amount' => Dec::fix($l['basis']), 'days' => $l['days'], 'rate' => $l['rate'],
                    'interest' => Dec::fix($l['interest']), 'tax' => Dec::fix($l['tax']), 'net' => Dec::fix($l['net']), 'sav_txn_id' => $sid]);
            }
            Audit::log('post', 'sav', 'interest_run', $runId, $p['name'] . ' ' . fdate($from) . ' - ' . fdate($to), ['page_key' => $pageKey, 'amount' => (float)$totInt, 'snapshot' => ['accounts' => count($lines), 'interest' => $totInt, 'tax' => $totTax, 'txn_id' => $txnId]]);
            $result['run_id'] = $runId;
            $result['txn_id'] = $txnId;
            return $result;
        });
    }

    // ------------------------------------------------------------------ dormancy

    /** Marks accounts dormant (no member transaction for the product's dormancy months) and charges the fee where set */
    public static function updateDormancy(string $asOf, bool $chargeFees, string $pageKey): array
    {
        if (!valid_date($asOf)) {
            fail('Enter the as-of date.');
        }
        if ($chargeFees) {
            Coop::checkDate($asOf);
        }
        return DB::tx(fn() => self::runDormancy($asOf, $chargeFees, $pageKey));
    }

    private static function runDormancy(string $asOf, bool $chargeFees, string $pageKey): array
    {
        $out = ['dormant' => 0, 'fees' => '0', 'txn_id' => null];
        $products = DB::all("SELECT * FROM sav_products WHERE dormancy_months > 0 AND is_active = 1 AND product_type IN ('ordinary','special')");
        $gl = [];
        $moves = [];
        foreach ($products as $p) {
            $cut = Coop::addMonths($asOf, -(int)$p['dormancy_months']);
            $rows = DB::all("SELECT a.* FROM sav_accounts a WHERE a.product_id = :p AND a.status = 'active' AND a.balance > 0
                               AND NOT EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_date > :c AND t.txn_code NOT IN ('INT','WTX','DF'))",
                ['p' => $p['id'], 'c' => $cut]);
            foreach ($rows as $a) {
                DB::update('sav_accounts', ['status' => 'dormant', 'dormant_since' => $asOf], 'id = :id', ['id' => $a['id']]);
                Audit::log('dormant', 'sav', 'sav_account', (int)$a['id'], $a['account_no'], ['page_key' => $pageKey, 'reason' => 'No transaction since ' . fdate($cut)]);
                $out['dormant']++;
            }
            if ($chargeFees && Dec::isPos($p['dormancy_fee'])) {
                // the fee is charged once per month (dormancy_posting 'monthly') or once per year ('annual');
                // running the process again in the same period does not charge it twice
                $since = match ($p['dormancy_posting'] ?? 'quarterly') {
                    'annual'  => substr($asOf, 0, 4) . '-01-01',
                    'monthly' => substr($asOf, 0, 7) . '-01',
                    default   => substr($asOf, 0, 4) . '-' . str_pad((string)(intdiv((int)substr($asOf, 5, 2) - 1, 3) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01',
                };
                $dormant = DB::all("SELECT a.* FROM sav_accounts a WHERE a.product_id = :p AND a.status = 'dormant' AND a.balance > 0
                                      AND NOT EXISTS (SELECT 1 FROM sav_txns t WHERE t.account_id = a.id AND t.is_voided = 0 AND t.txn_code = 'DF' AND t.txn_date >= :s)",
                    ['p' => $p['id'], 's' => $since]);
                foreach ($dormant as $a) {
                    $fee = Dec::min($p['dormancy_fee'], $a['balance']);
                    if (!Dec::isPos($fee)) {
                        continue;
                    }
                    $acct = (int)($p['dormancy_fee_account_id'] ?: Accts::get('dormancy_fee_income'));
                    $gl[] = ['account_id' => self::liabilityAccount($p), 'debit' => $fee, 'memo' => 'Dormancy fee ' . $a['account_no'], 'member_id' => $a['member_id']];
                    $gl[] = ['account_id' => $acct, 'credit' => $fee, 'memo' => 'Dormancy fee ' . $a['account_no']];
                    $moves[] = [$a, $fee];
                    $out['fees'] = Dec::add($out['fees'], $fee, 2);
                }
            }
        }
        if ($moves) {
            $txnId = CoopLedger::post('coop_jv', ['txn_date' => $asOf, 'memo' => 'Dormancy fees as of ' . fdate($asOf)], $gl);
            foreach ($moves as [$a, $fee]) {
                $locked = self::account((int)$a['id'], true);
                self::move($locked, 'DF', 'debit', $fee, $asOf, ['txn_id' => $txnId, 'description' => 'Dormancy fee', 'is_system' => true, 'skip_available' => true]);
            }
            $out['txn_id'] = $txnId;
        }
        return $out;
    }
}
