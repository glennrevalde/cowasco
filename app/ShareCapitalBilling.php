<?php
declare(strict_types=1);

/**
 * Fixed Share Capital Billing (Water Billing > Billing > Fixed Share Capital Billing).
 *
 * A member with an open share capital account is put on fixed billing: an amount, a frequency (monthly, quarterly,
 * semi-annually, annually), a start date and the day of the month the bill is posted (1 to 31; the last day of a
 * shorter month). On that day the system posts a bill for the amount: the first on the first posting day on or after
 * the start date, then every frequency afterwards.
 *
 * The facility monitors the members' consistent contribution. A bill makes NO journal entry: it only records the
 * contribution still unpaid. The books move only when the water cashier collects it (Collections (Cashier), with the
 * member's water-service account): the collection is a deposit to the member's share capital account by the savings
 * transaction code chosen on the page (normally CSD Cash Savings Deposit), whose journal entry it follows (Dr the
 * code's GL account, or the cashier's cash account for a "User Account" code; Cr the share capital account). The
 * deposit is a row of the Savings Account Transaction (numbered in the savings series), waiting for the passbook; the
 * receipt carries it with the bills paid, so it is part of the cashier's collections and of the Collection Summary.
 *
 * A plan can be edited, put on hold (no bill is posted until it is resumed; the days missed are not billed) and
 * deleted (its unpaid bills are cancelled; what was collected stays).
 */
final class ShareCapitalBilling
{
    /** frequency => [label, months] */
    public const FREQUENCIES = [
        'monthly' => ['Monthly', 1],
        'quarterly' => ['Quarterly', 3],
        'semi_annual' => ['Semi-annually', 6],
        'annual' => ['Annually', 12],
    ];

    public const STATUSES = ['active' => 'Active', 'on_hold' => 'On hold', 'deleted' => 'Deleted'];

    /** The largest fixed amount a posting (a contribution, not a loan): kept well inside the bills' DECIMAL(15,2) */
    public const MAX_AMOUNT = '999999999.99';

    /** The most members one batch or import puts on fixed billing */
    public const MAX_BATCH = 2000;

    /**
     * The code name of a collection on the receipts and the Detailed collection report. Not a bill code: kept apart
     * from a bill code named SHARE CAPITAL (a charge billed with the water bill, posted to its own account).
     */
    public const CODE_NAME = 'FIXED SHARE CAPITAL';

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM share_capital_plans WHERE 1 = 0');
                DB::value('SELECT COUNT(share_capital_amount) FROM payment_transactions WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    // ------------------------------------------------------------------ the page's settings

    private static function waterSetting(string $key): string
    {
        try {
            return (string)(DB::value('SELECT setting_value FROM water_billing_settings WHERE setting_key = :k', ['k' => $key]) ?? '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** ['code' => savings transaction code of a collection, 'class_id' => its class or null] */
    public static function settings(): array
    {
        $code = self::waterSetting('share_capital_txn_code');
        $class = (int)self::waterSetting('share_capital_class_id');
        return ['code' => $code !== '' ? $code : 'CSD', 'class_id' => $class > 0 ? $class : null];
    }

    /** The savings transaction codes a collection may post with: active deposit codes, not checks, memos or corrections */
    public static function codes(): array
    {
        $out = [];
        foreach (DB::all("SELECT * FROM sav_txn_codes WHERE is_active = 1 AND entry_side = 'credit' AND is_error_correct = 0
                            AND is_check_deposit = 0 AND COALESCE(is_memo, 0) = 0 ORDER BY code") as $c) {
            // the codes the system posts by itself (interest, transfers, refunds ...) are left out; CSD is a cash deposit
            if ((int)$c['is_system'] === 1 && $c['code'] !== 'CSD') {
                continue;
            }
            if ($c['opposite_kind'] === 'gl') {
                // the cash comes in at the cashier: a GL code must debit an asset (a cash, bank or clearing account),
                // never an equity or income account (the import codes INI and MIG debit Opening Balance Equity)
                $cat = $c['opposite_account_id'] ? (string)DB::value('SELECT category FROM accounts WHERE id = :a AND is_active = 1', ['a' => $c['opposite_account_id']]) : '';
                if ($cat !== 'Asset') {
                    continue;
                }
            }
            $out[$c['code']] = $c;
        }
        return $out;
    }

    public static function saveSettings(string $code, ?int $classId, string $pageKey): void
    {
        if (!isset(self::codes()[$code])) {
            fail('Choose the savings transaction code of the collections: a deposit code (Savings & Deposits > Savings Transaction Codes).');
        }
        if ($classId !== null && $classId > 0 && !isset(Classes::all()[$classId])) {
            fail('Choose a class of the Class List.');
        }
        $was = self::settings();
        DB::tx(function () use ($code, $classId) {
            foreach (['share_capital_txn_code' => $code, 'share_capital_class_id' => $classId ? (string)$classId : ''] as $k => $v) {
                DB::run('INSERT INTO water_billing_settings (setting_key, setting_value, description, created_at, updated_at)
                         VALUES (:k, :v, :d, NOW(), NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()',
                    ['k' => $k, 'v' => $v, 'd' => $k === 'share_capital_txn_code' ? 'Fixed Share Capital Billing: the savings transaction code of a collection' : 'Fixed Share Capital Billing: the class of a collection']);
            }
        });
        Audit::log('edit', 'water', 'share_capital_billing', null, 'Collection code ' . $code . ($classId ? ', class ' . Classes::name($classId) : ''), [
            'page_key' => $pageKey, 'reason' => 'Was ' . $was['code'] . ($was['class_id'] ? ', class ' . Classes::name($was['class_id']) : ''),
        ]);
    }

    // ------------------------------------------------------------------ members and their share capital accounts

    /** The open share capital accounts (active or dormant) of a member, or of every member */
    public static function openAccounts(?int $memberId = null): array
    {
        $sql = "SELECT a.id, a.account_no, a.member_id, a.status, a.balance, p.name AS product_name, p.code AS product_code
                  FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id
                 WHERE p.product_type = 'share_capital' AND a.status IN ('active', 'dormant')";
        $params = [];
        if ($memberId !== null) {
            $sql .= ' AND a.member_id = :m';
            $params['m'] = $memberId;
        }
        return DB::all($sql . ' ORDER BY a.member_id, a.account_no', $params);
    }

    /**
     * The members who may be put on fixed billing: active members with an open share capital account, each with its
     * accounts and the water-service accounts of its party (where the cashier collects the bills)
     */
    public static function eligibleMembers(): array
    {
        $members = [];
        foreach (DB::all("SELECT DISTINCT m.id, m.member_no, m.full_name, m.party_id FROM members m
                            JOIN sav_accounts a ON a.member_id = m.id JOIN sav_products p ON p.id = a.product_id
                           WHERE m.status = 'active' AND p.product_type = 'share_capital' AND a.status IN ('active', 'dormant')
                           ORDER BY m.full_name, m.member_no") as $m) {
            $m['accounts'] = [];
            $members[(int)$m['id']] = $m;
        }
        $water = self::waterAccountsByMember(array_keys($members));
        foreach ($members as $id => &$m) {
            $m['water_accounts'] = $water[$id] ?? [];
        }
        unset($m);
        foreach (self::openAccounts() as $a) {
            if (isset($members[(int)$a['member_id']])) {
                $members[(int)$a['member_id']]['accounts'][] = $a;
            }
        }
        return $members;
    }

    /** The water-service accounts of several members at once: member id => [[pkey, account_number], ...] */
    public static function waterAccountsByMember(array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        $out = [];
        if (!$ids) {
            return $out;
        }
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (DB::all("SELECT m.id AS member_id, w.pkey, w.account_number FROM water_accounts w JOIN members m ON m.party_id = w.party_id
                                   WHERE m.id IN (" . implode(',', $chunk) . ") AND w.status = 'A' ORDER BY w.pkey") as $r) {
                    $out[(int)$r['member_id']][] = ['pkey' => $r['pkey'], 'account_number' => $r['account_number']];
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** The water-service accounts of a member (the same party): the cashier collects the member's bills on them */
    public static function waterAccountsOf(int $memberId): array
    {
        try {
            return DB::all("SELECT w.pkey, w.account_number FROM water_accounts w JOIN members m ON m.party_id = w.party_id
                             WHERE m.id = :m AND m.party_id IS NOT NULL AND w.status = 'A' ORDER BY w.pkey", ['m' => $memberId]);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------ the posting dates

    /** The posting day in the month of $date (the last day when the month is shorter) */
    public static function dayIn(string $date, int $day): string
    {
        $d = new DateTime(substr($date, 0, 7) . '-01');
        return $d->format('Y-m-') . str_pad((string)min(max(1, $day), (int)$d->format('t')), 2, '0', STR_PAD_LEFT);
    }

    /** The first posting date on or after the start date */
    public static function firstDate(string $start, int $day): string
    {
        $d = self::dayIn($start, $day);
        return $d >= $start ? $d : self::dayIn(Coop::addMonths(substr($start, 0, 7) . '-01', 1), $day);
    }

    /** The posting date after $date: the frequency later, on the posting day */
    public static function nextDate(string $date, string $frequency, int $day): string
    {
        $months = self::FREQUENCIES[$frequency][1] ?? 1;
        return self::dayIn(Coop::addMonths(substr($date, 0, 7) . '-01', $months), $day);
    }

    /** The first posting date of the series that is on or after $date (and after $after, when given) */
    public static function seriesFrom(string $first, string $frequency, int $day, string $date, ?string $after = null): string
    {
        $d = $first;
        $guard = 0;
        while (($d < $date || ($after !== null && $d <= $after)) && $guard++ < 2000) {
            $d = self::nextDate($d, $frequency, $day);
        }
        return $d;
    }

    /** The last day of the month of a date (null for none): the next bill of a plan is after the month of its last bill */
    public static function monthEnd(?string $date): ?string
    {
        return $date ? (new DateTime(substr($date, 0, 7) . '-01'))->format('Y-m-t') : null;
    }

    // ------------------------------------------------------------------ plans

    public static function plan(int $planId, bool $lock = false): ?array
    {
        return DB::row('SELECT * FROM share_capital_plans WHERE plan_id = :p' . ($lock ? ' FOR UPDATE' : ''), ['p' => $planId]);
    }

    /** The plans with their member, account and bills (posted, unpaid, collected), not the deleted ones unless asked */
    public static function plans(bool $withDeleted = false): array
    {
        $rows = DB::all("SELECT pl.*, m.member_no, m.full_name, m.status AS member_status, a.account_no, a.status AS account_status,
                                COALESCE(b.n, 0) AS bills_posted, COALESCE(b.unpaid_n, 0) AS bills_unpaid,
                                COALESCE(b.unpaid_amount, 0) AS unpaid_amount, COALESCE(b.paid_amount, 0) AS paid_amount
                           FROM share_capital_plans pl
                           JOIN members m ON m.id = pl.member_id
                           JOIN sav_accounts a ON a.id = pl.sav_account_id
                           LEFT JOIN (SELECT plan_id, COUNT(*) AS n, SUM(status IN ('unpaid', 'partial')) AS unpaid_n,
                                             SUM(CASE WHEN status IN ('unpaid', 'partial') THEN amount - amount_paid ELSE 0 END) AS unpaid_amount,
                                             SUM(amount_paid) AS paid_amount
                                        FROM share_capital_bills WHERE status <> 'cancelled' GROUP BY plan_id) b ON b.plan_id = pl.plan_id
                          WHERE " . ($withDeleted ? '1 = 1' : "pl.status <> 'deleted'") . '
                          ORDER BY m.full_name, pl.plan_id');
        $water = self::waterAccountsByMember(array_column($rows, 'member_id'));
        foreach ($rows as &$r) {
            $r['water_accounts'] = $water[(int)$r['member_id']] ?? [];
        }
        unset($r);
        return $rows;
    }

    /**
     * The term of the bills, in days ('' or null = no due date, the bill is open): a whole number from 0 to 3650
     */
    public static function term($v): ?int
    {
        $v = trim((string)($v ?? ''));
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $v) || (int)$v > 3650) {
            fail('Term (days) must be a whole number of days from 0 to 3650, or blank for no due date (open).');
        }
        return (int)$v;
    }

    /** The due date of a bill posted on $billDate with a term of $termDays days (null = open) */
    public static function dueDate(string $billDate, ?int $termDays): ?string
    {
        return $termDays === null ? null : date('Y-m-d', strtotime($billDate . ' +' . $termDays . ' days'));
    }

    /**
     * Adds or changes a plan. $f: member_id, sav_account_id, frequency, start_date, posting_day, amount, term_days
     * (blank: the bills have no due date), batch_no (the batch or import that adds it).
     * A new plan's first bill is the first posting day on or after the start date (bills of past posting days are
     * posted at once, as the contribution expected since then). A changed schedule takes effect after the last bill
     * posted; a changed amount on the bills posted from then on.
     */
    public static function save(array $f, ?int $planId, string $pageKey): int
    {
        $memberId = (int)($f['member_id'] ?? 0);
        $accountId = (int)($f['sav_account_id'] ?? 0);
        $frequency = (string)($f['frequency'] ?? '');
        $start = trim((string)($f['start_date'] ?? ''));
        $day = (int)($f['posting_day'] ?? 0);
        $amount = Dec::round((string)($f['amount'] ?? '0'), 2);
        $term = self::term($f['term_days'] ?? null);
        $batchNo = mb_substr(trim((string)($f['batch_no'] ?? '')), 0, 30) ?: null;
        $m = $memberId ? Coop::member($memberId) : null;
        if (!$m) {
            fail('Choose the member.');
        }
        if ($m['status'] !== 'active') {
            fail('Member ' . $m['full_name'] . ' is ' . $m['status'] . ': only active members are billed.');
        }
        $acct = DB::row("SELECT a.*, p.product_type FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.id = :a", ['a' => $accountId]);
        if (!$acct || (int)$acct['member_id'] !== $memberId) {
            fail('Choose a share capital account of ' . $m['full_name'] . '.');
        }
        if ($acct['product_type'] !== 'share_capital') {
            fail('Account ' . $acct['account_no'] . ' is not a share capital account: only members with share capital are billed.');
        }
        if (!in_array($acct['status'], ['active', 'dormant'], true)) {
            fail('Share capital account ' . $acct['account_no'] . ' is ' . $acct['status'] . ': choose an open account.');
        }
        if (!isset(self::FREQUENCIES[$frequency])) {
            fail('Choose the frequency: monthly, quarterly, semi-annually or annually.');
        }
        if (!valid_date($start)) {
            fail('Enter the start date of the billing.');
        }
        if ($day < 1 || $day > 31) {
            fail('Choose the posting day: a day of the month from 1 to 31.');
        }
        if (!Dec::isPos($amount)) {
            fail('Enter the fixed amount to be collected.');
        }
        if (Dec::cmp($amount, self::MAX_AMOUNT) > 0) {
            fail('The fixed amount may not be more than ' . Dec::money(self::MAX_AMOUNT) . '.');
        }
        Coop::requireBranch($acct['branch_id'] ? (int)$acct['branch_id'] : null);

        return DB::tx(function () use ($planId, $memberId, $accountId, $frequency, $start, $day, $amount, $term, $batchNo, $m, $acct, $pageKey) {
            DB::value('SELECT id FROM sav_accounts WHERE id = :a FOR UPDATE', ['a' => $accountId]);
            $other = DB::value("SELECT plan_id FROM share_capital_plans WHERE sav_account_id = :a AND status <> 'deleted' AND plan_id <> :p",
                ['a' => $accountId, 'p' => (int)$planId]);
            if ($other) {
                fail('Share capital account ' . $acct['account_no'] . ' is already on fixed billing. Edit that billing instead.');
            }
            $first = self::firstDate($start, $day);
            $now = date('Y-m-d H:i:s');
            if ($planId) {
                $old = self::plan($planId, true);
                if (!$old || $old['status'] === 'deleted') {
                    fail('That fixed billing was deleted.');
                }
                $scheduleChanged = $old['frequency'] !== $frequency || $old['start_date'] !== $start || (int)$old['posting_day'] !== $day
                    || (int)$old['sav_account_id'] !== $accountId;
                $next = $old['next_bill_date'];
                if ($scheduleChanged) {
                    // the new series starts after the period the bills posted cover: after the month of the last bill and
                    // from the month the old schedule would have billed next (a quarterly bill covers three months), so
                    // nothing is billed twice and nothing past is billed again; on hold, from today (as on resuming)
                    $after = self::monthEnd($old['last_bill_date']);
                    if ($old['status'] === 'on_hold') {
                        $next = self::seriesFrom($first, $frequency, $day, max($first, today()), $after);
                    } elseif ($old['last_bill_date']) {
                        $from = $old['next_bill_date'] ? substr($old['next_bill_date'], 0, 7) . '-01' : today();
                        $next = self::seriesFrom($first, $frequency, $day, max($first, $from), $after);
                    } else {
                        $next = self::seriesFrom($first, $frequency, $day, $first);
                    }
                }
                DB::update('share_capital_plans', [
                    'member_id' => $memberId, 'sav_account_id' => $accountId, 'frequency' => $frequency, 'start_date' => $start, 'posting_day' => $day,
                    'amount' => Dec::fix($amount), 'term_days' => $term, 'next_bill_date' => $next, 'updated_by' => Auth::id(), 'updated_at' => $now,
                ], 'plan_id = :p', ['p' => $planId]);
                $changes = [];
                foreach (['frequency' => $frequency, 'start_date' => $start, 'posting_day' => (string)$day, 'amount' => Dec::fix($amount), 'sav_account_id' => (string)$accountId,
                          'term_days' => $term === null ? '' : (string)$term] as $k => $v) {
                    if ((string)$old[$k] !== (string)$v) {
                        $changes[] = $k . ' ' . $old[$k] . ' to ' . $v;
                    }
                }
                Audit::log('edit', 'water', 'share_capital_plan', $planId, $m['full_name'] . ' ' . $acct['account_no'], ['page_key' => $pageKey, 'reason' => $changes ? implode(', ', $changes) : 'No change']);
                $id = $planId;
            } else {
                $id = (int)DB::insert('share_capital_plans', [
                    'member_id' => $memberId, 'sav_account_id' => $accountId, 'frequency' => $frequency, 'start_date' => $start, 'posting_day' => $day,
                    'amount' => Dec::fix($amount), 'term_days' => $term, 'status' => 'active', 'batch_no' => $batchNo, 'next_bill_date' => $first,
                    'created_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
                ]);
                Audit::log('create', 'water', 'share_capital_plan', $id, $m['full_name'] . ' ' . $acct['account_no'] . ': ' . Dec::money($amount) . ' ' . strtolower(self::FREQUENCIES[$frequency][0]) . ' from ' . $first
                    . ($term === null ? ', no due date' : ', term ' . $term . ' days') . ($batchNo ? ' (' . $batchNo . ')' : ''), ['page_key' => $pageKey]);
            }
            self::postDue(today(), [$id]);
            return $id;
        });
    }

    /** Stops the billing of a plan until it is resumed: no bill is posted meanwhile */
    public static function hold(int $planId, string $reason, string $pageKey): void
    {
        DB::tx(function () use ($planId, $reason, $pageKey) {
            $p = self::plan($planId, true);
            if (!$p || $p['status'] === 'deleted') {
                fail('That fixed billing was deleted.');
            }
            if ($p['status'] === 'on_hold') {
                fail('That billing is already on hold.');
            }
            self::requireBranchOf($p);
            // whatever is due up to today is posted before the hold
            self::postDue(today(), [$planId]);
            DB::update('share_capital_plans', ['status' => 'on_hold', 'hold_reason' => mb_substr(trim($reason), 0, 255) ?: null, 'held_at' => date('Y-m-d H:i:s'),
                'held_by' => Auth::id(), 'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'plan_id = :p', ['p' => $planId]);
            Audit::log('hold', 'water', 'share_capital_plan', $planId, self::label($p), ['page_key' => $pageKey, 'reason' => $reason]);
        });
    }

    /** Starts the billing of a plan on hold again: the next posting day from today (the days on hold are not billed) */
    public static function resume(int $planId, string $pageKey): string
    {
        return DB::tx(function () use ($planId, $pageKey) {
            $p = self::plan($planId, true);
            if (!$p || $p['status'] !== 'on_hold') {
                fail('That billing is not on hold.');
            }
            self::requireBranchOf($p);
            $first = self::firstDate($p['start_date'], (int)$p['posting_day']);
            $next = self::seriesFrom($first, $p['frequency'], (int)$p['posting_day'], max($first, today()), self::monthEnd($p['last_bill_date']));
            DB::update('share_capital_plans', ['status' => 'active', 'next_bill_date' => $next, 'hold_reason' => null, 'held_at' => null, 'held_by' => null,
                'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], 'plan_id = :p', ['p' => $planId]);
            Audit::log('resume', 'water', 'share_capital_plan', $planId, self::label($p) . ': next bill ' . $next, ['page_key' => $pageKey]);
            self::postDue(today(), [$planId]);
            return $next;
        });
    }

    /** Deletes a plan: no more bills; its unpaid bills are cancelled (what was collected stays). Returns the bills cancelled. */
    public static function delete(int $planId, string $reason, string $pageKey): int
    {
        if (trim($reason) === '') {
            fail('Enter the reason for deleting the fixed billing.');
        }
        return DB::tx(function () use ($planId, $reason, $pageKey) {
            $p = self::plan($planId, true);
            if (!$p || $p['status'] === 'deleted') {
                fail('That fixed billing was already deleted.');
            }
            self::requireBranchOf($p);
            // the unpaid bills are cancelled, and the rest of a partly paid one (what was collected stays on the account)
            $partial = (int)DB::value("SELECT COUNT(*) FROM share_capital_bills WHERE plan_id = :p AND status = 'partial'", ['p' => $planId]);
            $n = DB::run("UPDATE share_capital_bills SET status = 'cancelled', cancelled_at = NOW() WHERE plan_id = :p AND status IN ('unpaid', 'partial')", ['p' => $planId])->rowCount();
            DB::update('share_capital_plans', ['status' => 'deleted', 'next_bill_date' => null, 'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => Auth::id(),
                'delete_reason' => mb_substr(trim($reason), 0, 255)], 'plan_id = :p', ['p' => $planId]);
            Audit::log('delete', 'water', 'share_capital_plan', $planId, self::label($p) . ': ' . $n . ' unpaid bill(s) cancelled' . ($partial ? ' (' . $partial . ' partly paid: what was collected stays)' : ''), ['page_key' => $pageKey, 'reason' => $reason]);
            return $n;
        });
    }

    /** The branch of a billing's share capital account: a user limited to other branches may not change it */
    private static function requireBranchOf(array $p): void
    {
        $branch = DB::value('SELECT branch_id FROM sav_accounts WHERE id = :a', ['a' => $p['sav_account_id']]);
        Coop::requireBranch($branch ? (int)$branch : null);
    }

    /** The water receipt of a share capital collection, or of its reversal, by its ledger document (null if none) */
    public static function receiptOfLedgerTxn(int $txnId): ?string
    {
        if (!self::ready()) {
            return null;
        }
        $r = DB::value('SELECT receipt_no FROM share_capital_collections WHERE ledger_txn_id = :t OR reversal_ledger_txn_id = :t2 LIMIT 1', ['t' => $txnId, 't2' => $txnId]);
        return $r ? (string)$r : null;
    }

    private static function label(array $p): string
    {
        $m = Coop::member((int)$p['member_id']);
        $no = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $p['sav_account_id']]);
        return ($m['full_name'] ?? 'Member #' . $p['member_id']) . ' ' . $no;
    }

    // ------------------------------------------------------------------ batch billing and its import

    /** The columns of the import file of Batch Share Capital Billing (CsvReview format); a blank cell takes the value of the form */
    public const IMPORT_COLUMNS = [
        'account_no' => ['label' => 'Share capital account no.', 'req' => true, 'size' => 'md', 'hint' => 'The member\'s open share capital account (Savings & Deposits)'],
        'member_name' => ['label' => 'Member name', 'size' => 'lg', 'hint' => 'Optional: checked against the member of the account'],
        'amount' => ['label' => 'Amount', 'type' => 'money', 'size' => 'sm', 'hint' => 'The fixed amount of each bill; blank: the amount of the form'],
        'frequency' => ['label' => 'Frequency', 'size' => 'sm', 'hint' => 'Monthly, Quarterly, Semi-annually or Annually; blank: the form\'s'],
        'start_date' => ['label' => 'Start date', 'type' => 'date', 'size' => 'md', 'hint' => 'YYYY-MM-DD or MM/DD/YYYY; blank: the form\'s'],
        'posting_day' => ['label' => 'Posting day', 'type' => 'num', 'size' => 'xs', 'hint' => '1 to 31; blank: the form\'s'],
        'term_days' => ['label' => 'Term (days)', 'type' => 'num', 'size' => 'xs', 'hint' => 'Days from the posting date to the due date; OPEN for no due date; blank: the form\'s'],
    ];

    /** Other names of the columns in a file ("Share Capital Account", "Account No", "Term") */
    public const IMPORT_ALIASES = [
        'share_capital_account' => 'account_no', 'share_capital_account_no' => 'account_no', 'account' => 'account_no', 'account_number' => 'account_no', 'sc_account' => 'account_no',
        'name' => 'member_name', 'member' => 'member_name', 'fixed_amount' => 'amount', 'posting_date' => 'posting_day', 'day' => 'posting_day',
        'term' => 'term_days', 'terms' => 'term_days', 'term_days_' => 'term_days', 'start' => 'start_date',
    ];

    /** A frequency as typed ("Semi-annually", "quarterly", "Q", "12") to its key, null when it is not one */
    public static function frequencyKey(string $v): ?string
    {
        $v = strtolower(preg_replace('/[^a-z0-9]+/i', '', $v));
        return match ($v) {
            'monthly', 'month', 'm', '1' => 'monthly',
            'quarterly', 'quarter', 'q', '3' => 'quarterly',
            'semiannually', 'semiannual', 'semi', 's', '6', 'biannual', 'biannually' => 'semi_annual',
            'annually', 'annual', 'yearly', 'year', 'a', 'y', '12' => 'annual',
            default => null,
        };
    }

    /**
     * Checks the rows of a batch or an import before they are posted. A row: sav_account_id (the batch) or account_no
     * (the import), member_name, amount, frequency, start_date, posting_day, term_days, _line; a blank value takes
     * $common's. Each row comes back with _errors, _notes, _info and _ok (the values to save).
     */
    public static function checkRows(array $rows, array $common): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['sav_account_id'] ?? 0), $rows))));
        $nos = array_values(array_unique(array_filter(array_map(fn($r) => preg_replace('/\s+/', '', (string)($r['account_no'] ?? '')), $rows))));
        $accounts = [];
        $sql = "SELECT a.id, a.account_no, a.member_id, a.status, a.branch_id, p.product_type, p.name AS product_name,
                       m.full_name, m.last_name, m.first_name, m.member_no, m.status AS member_status
                  FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id WHERE ";
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::all($sql . 'a.id IN (' . implode(',', array_map('intval', $chunk)) . ')') as $a) {
                $accounts['#' . $a['id']] = $a;
            }
        }
        foreach (array_chunk($nos, 500) as $chunk) {
            [$in, $p] = DB::in('n', $chunk);
            foreach (DB::all($sql . 'a.account_no IN ' . $in, $p) as $a) {
                $accounts['#' . $a['id']] = $a;
                $accounts['no:' . $a['account_no']] = $a;
            }
        }
        // a number whose leading zeros a spreadsheet dropped (01100001031 typed as 1100001031)
        $lost = array_values(array_filter($nos, fn($n) => !isset($accounts['no:' . $n]) && ctype_digit($n) && $n[0] !== '0'));
        $zeroFixed = [];
        $zeroMany = [];   // a number that fits more than one account once its zeros are put back: not guessed
        foreach (array_chunk($lost, 500) as $chunk) {
            [$in, $p] = DB::in('z', $chunk);
            $found = [];
            foreach (DB::all($sql . "TRIM(LEADING '0' FROM a.account_no) IN " . $in . " AND a.account_no LIKE '0%' ORDER BY a.account_no", $p) as $a) {
                $found[ltrim((string)$a['account_no'], '0')][] = $a;
            }
            foreach ($found as $key => $list) {
                if (isset($accounts['no:' . $key])) {
                    continue;
                }
                if (count($list) > 1) {
                    $zeroMany[$key] = array_column($list, 'account_no');
                    continue;
                }
                $a = $list[0];
                $accounts['#' . $a['id']] = $a;
                $accounts['no:' . $key] = $a;
                $zeroFixed[$key] = $a['account_no'];
            }
        }
        $acctIds = array_values(array_unique(array_map(fn($a) => (int)$a['id'], $accounts)));
        $onBilling = $acctIds ? DB::pairs("SELECT sav_account_id, plan_id FROM share_capital_plans WHERE status <> 'deleted' AND sav_account_id IN (" . implode(',', $acctIds) . ')') : [];
        $water = self::waterAccountsByMember(array_map(fn($a) => (int)$a['member_id'], $accounts));
        $default = fn(string $k) => trim((string)($common[$k] ?? ''));
        $pick = fn(array $r, string $k) => trim((string)($r[$k] ?? '')) !== '' ? trim((string)$r[$k]) : $default($k);
        $seen = [];
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $line = (int)($r['_line'] ?? ($i + 1));
            $errs = [];
            $notes = [];
            $cells = [];
            $info = '';
            $a = null;
            if (!empty($r['sav_account_id'])) {
                $a = $accounts['#' . (int)$r['sav_account_id']] ?? null;
                if (!$a) {
                    $errs[] = 'The share capital account was not found.';
                    $cells[count($errs) - 1] = ['account_no'];
                }
            } else {
                $no = preg_replace('/\s+/', '', (string)($r['account_no'] ?? ''));
                if ($no === '') {
                    $errs[] = 'Enter the share capital account no.';
                    $cells[count($errs) - 1] = ['account_no'];
                } else {
                    $a = $accounts['no:' . $no] ?? null;
                    if (!$a && isset($zeroMany[$no])) {
                        $errs[] = 'Account ' . $no . ' could be ' . implode(' or ', $zeroMany[$no]) . ' (the spreadsheet dropped the leading zeros): type the number in full.';
                        $cells[count($errs) - 1] = ['account_no'];
                    } elseif (!$a) {
                        $errs[] = 'No account ' . $no . ' in Savings & Deposits.';
                        $cells[count($errs) - 1] = ['account_no'];
                    } elseif (isset($zeroFixed[$no])) {
                        $notes[] = 'Account ' . $no . ' read as ' . $zeroFixed[$no] . ' (the spreadsheet dropped its leading zero).';
                    }
                }
            }
            if ($a) {
                if ($a['product_type'] !== 'share_capital') {
                    $errs[] = 'Account ' . $a['account_no'] . ' is ' . $a['product_name'] . ', not share capital: only members with share capital are billed.';
                    $cells[count($errs) - 1] = ['account_no'];
                } elseif (!in_array($a['status'], ['active', 'dormant'], true)) {
                    $errs[] = 'Share capital account ' . $a['account_no'] . ' is ' . $a['status'] . ': only an open account is billed.';
                    $cells[count($errs) - 1] = ['account_no'];
                } elseif ($a['member_status'] !== 'active') {
                    $errs[] = 'Member ' . $a['full_name'] . ' is ' . $a['member_status'] . ': only active members are billed.';
                    $cells[count($errs) - 1] = ['account_no'];
                } elseif (isset($onBilling[(int)$a['id']])) {
                    $errs[] = 'Account ' . $a['account_no'] . ' (' . $a['full_name'] . ') is already on fixed billing: change it with Edit on the page.';
                    $cells[count($errs) - 1] = ['account_no'];
                } elseif (isset($seen[(int)$a['id']])) {
                    $errs[] = 'Account ' . $a['account_no'] . ' is also on line ' . $seen[(int)$a['id']] . ': one billing per account.';
                    $cells[count($errs) - 1] = ['account_no'];
                } elseif (!Coop::branchAllowed($a['branch_id'] ? (int)$a['branch_id'] : null)) {
                    $errs[] = 'Account ' . $a['account_no'] . ' belongs to another branch. You are limited to ' . Coop::branchName(Coop::userBranchId()) . '.';
                    $cells[count($errs) - 1] = ['account_no'];
                }
                $seen[(int)$a['id']] = $seen[(int)$a['id']] ?? $line;
                $given = trim((string)($r['member_name'] ?? ''));
                if ($given !== '' && !CsvReview::nameMatches($given, (string)$a['last_name'], (string)$a['first_name'], (string)$a['full_name'])) {
                    $errs[] = 'The member of account ' . $a['account_no'] . ' is ' . $a['full_name'] . ', not ' . $given . '.';
                    $cells[count($errs) - 1] = ['member_name', 'account_no'];
                }
                $w = $water[(int)$a['member_id']] ?? [];
                $info = h($a['full_name']) . ' <span class="imp-dim">member no. ' . h($a['member_no']) . ', ' . h($a['product_name']) . '</span>';
                if (!$w) {
                    $notes[] = 'No water-service account: the cashier collects the bills on the member\'s water-service account (open one under the member\'s party).';
                } else {
                    $info .= ' <span class="imp-dim">water account ' . h(implode(', ', array_map(fn($x) => $x['account_number'] ?: $x['pkey'], $w))) . '</span>';
                }
            }
            $amountRaw = $pick($r, 'amount');
            $amount = $amountRaw === '' ? null : CsvReview::amount($amountRaw);
            if ($amount === null || !Dec::isPos($amount)) {
                $errs[] = $amountRaw === '' ? 'Enter the amount.' : 'The amount "' . $amountRaw . '" is not more than zero.';
                $cells[count($errs) - 1] = ['amount'];
            } elseif (Dec::cmp($amount, self::MAX_AMOUNT) > 0) {
                $errs[] = 'The amount ' . $amountRaw . ' is more than ' . Dec::money(self::MAX_AMOUNT) . '.';
                $cells[count($errs) - 1] = ['amount'];
                $amount = null;
            }
            $freqRaw = $pick($r, 'frequency');
            $freq = self::frequencyKey($freqRaw);
            if ($freq === null) {
                $errs[] = $freqRaw === '' ? 'Choose the frequency.' : 'The frequency "' . $freqRaw . '" is not Monthly, Quarterly, Semi-annually or Annually.';
                $cells[count($errs) - 1] = ['frequency'];
            }
            $startRaw = $pick($r, 'start_date');
            $start = CsvReview::dateStrict($startRaw);
            if ($start === null) {
                $errs[] = $startRaw === '' ? 'Enter the start date.' : 'The start date "' . $startRaw . '" is not a date (YYYY-MM-DD or MM/DD/YYYY).';
                $cells[count($errs) - 1] = ['start_date'];
            }
            $dayRaw = $pick($r, 'posting_day');
            $day = preg_match('/^\d{1,2}$/', $dayRaw) ? (int)$dayRaw : 0;
            if ($day < 1 || $day > 31) {
                $errs[] = $dayRaw === '' ? 'Enter the posting day (1 to 31).' : 'The posting day "' . $dayRaw . '" is not a day from 1 to 31.';
                $cells[count($errs) - 1] = ['posting_day'];
            }
            $termRaw = trim((string)($r['term_days'] ?? '')) !== '' ? trim((string)$r['term_days']) : $default('term_days');
            $term = null;
            if ($termRaw !== '' && !in_array(strtolower($termRaw), ['open', 'none', '-'], true)) {
                try {
                    $term = self::term($termRaw);
                } catch (UserError $e) {
                    $errs[] = $e->getMessage();
                    $cells[count($errs) - 1] = ['term_days'];
                }
            }
            if (!$errs && $start && $day) {
                $first = self::firstDate($start, $day);
                $notes[] = 'First bill ' . date('m/d/Y', strtotime($first)) . ($term === null ? ', no due date' : ', due ' . date('m/d/Y', strtotime(self::dueDate($first, $term))))
                    . ($first < today() ? ' (the posting days already past are posted at once)' : '') . '.';
            }
            $out[] = array_filter($r, fn($k) => !is_string($k) || $k === '' || $k[0] !== '_', ARRAY_FILTER_USE_KEY) + ['_line' => $line, '_errors' => $errs, '_notes' => $notes, '_cells' => $cells, '_info' => $info,
                '_figure' => $amount !== null && Dec::isPos($amount) ? (float)$amount : 0.0,
                '_ok' => $errs ? null : ['member_id' => (int)$a['member_id'], 'sav_account_id' => (int)$a['id'], 'account_no' => $a['account_no'], 'full_name' => $a['full_name'],
                    'amount' => Dec::fix($amount), 'frequency' => $freq, 'start_date' => $start, 'posting_day' => $day, 'term_days' => $term === null ? '' : (string)$term]];
        }
        return $out;
    }

    /**
     * Puts the accounts of a batch or an import on fixed billing: every row or none ($kind 'batch' or 'import').
     * Returns ['ids' => plan ids, 'batch_no' => .., 'bills' => bills posted at once, 'amount' => the amounts added up].
     */
    public static function saveBatch(array $rows, array $common, string $kind, string $pageKey): array
    {
        if (!$rows) {
            fail($kind === 'import' ? 'There is no member to bill: every row was removed.' : 'Tick at least one member.');
        }
        if (count($rows) > self::MAX_BATCH) {
            fail('Bill at most ' . number_format(self::MAX_BATCH) . ' members at a time: split the list.');
        }
        $checked = self::checkRows($rows, $common);
        $bad = array_values(array_filter($checked, fn($r) => $r['_errors']));
        if ($bad) {
            fail(count($bad) === 1 ? 'Line ' . $bad[0]['_line'] . ': ' . $bad[0]['_errors'][0] : count($bad) . ' rows have a problem (line ' . $bad[0]['_line'] . ': ' . $bad[0]['_errors'][0] . ')');
        }
        $batchNo = ($kind === 'import' ? 'SCI-' : 'SCB-') . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 3);
        return DB::tx(function () use ($checked, $batchNo, $kind, $pageKey) {
            // the accounts are locked first, in one order, before anything is read in the transaction: a batch or a
            // member added at the same time waits, and what it saved is seen here (no account billed twice)
            $accountIds = array_map(fn($r) => (int)$r['_ok']['sav_account_id'], $checked);
            sort($accountIds);
            foreach (array_chunk($accountIds, 500) as $chunk) {
                DB::column('SELECT id FROM sav_accounts WHERE id IN (' . implode(',', $chunk) . ') ORDER BY id FOR UPDATE');
            }
            $ids = [];
            $total = '0';
            $before = (int)DB::value('SELECT COUNT(*) FROM share_capital_bills');
            foreach ($checked as $r) {
                $ok = $r['_ok'];
                try {
                    $ids[] = self::save($ok + ['batch_no' => $batchNo], null, $pageKey);
                } catch (UserError $e) {
                    fail('Line ' . $r['_line'] . ' (' . $ok['account_no'] . ' ' . $ok['full_name'] . '): ' . $e->getMessage() . ' Nothing was saved.');
                }
                $total = Dec::add($total, $ok['amount'], 2);
            }
            $bills = (int)DB::value('SELECT COUNT(*) FROM share_capital_bills') - $before;
            Audit::log('create', 'water', 'share_capital_batch', null, $batchNo . ': ' . count($ids) . ' member(s) on fixed billing, ' . Dec::money($total) . ' a posting',
                ['page_key' => $pageKey, 'reason' => $kind === 'import' ? 'Import of Batch Share Capital Billing' : 'Batch Share Capital Billing']);
            return ['ids' => $ids, 'batch_no' => $batchNo, 'bills' => $bills, 'amount' => Dec::fix($total)];
        });
    }

    /** Example rows of the import template */
    public static function importSamples(): array
    {
        $accounts = DB::all("SELECT a.account_no, m.full_name FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                              WHERE p.product_type = 'share_capital' AND a.status IN ('active', 'dormant') AND m.status = 'active'
                                AND NOT EXISTS (SELECT 1 FROM share_capital_plans pl WHERE pl.sav_account_id = a.id AND pl.status <> 'deleted')
                              ORDER BY m.full_name LIMIT 3");
        if (!$accounts) {
            $accounts = [['account_no' => '01100001031', 'full_name' => 'Bond, James'], ['account_no' => '01100002031', 'full_name' => 'James, Lebron']];
        }
        $start = date('Y-m-01', strtotime(date('Y-m-01') . ' +1 month'));
        $out = [];
        foreach (array_values($accounts) as $i => $a) {
            $out[] = ['account_no' => $a['account_no'], 'member_name' => $a['full_name'], 'amount' => ['50.00', '200.00', '300.00'][$i % 3],
                'frequency' => ['Monthly', 'Quarterly', 'Semi-annually'][$i % 3], 'start_date' => $start, 'posting_day' => (string)[1, 2, 15][$i % 3], 'term_days' => ['7', '', 'OPEN'][$i % 3]];
        }
        return $out;
    }

    // ------------------------------------------------------------------ automatic posting of the bills

    /**
     * Posts the bills whose posting day has come (up to $asOf), for every active plan or the plans given. No journal
     * entry: a bill only records the contribution expected. Safe to run any number of times (one bill per plan and
     * posting date). Returns the number of bills posted.
     */
    public static function postDue(?string $asOf = null, ?array $planIds = null): int
    {
        if (!self::ready()) {
            return 0;
        }
        $asOf = $asOf ?: today();
        $sql = "SELECT plan_id FROM share_capital_plans WHERE status = 'active' AND next_bill_date IS NOT NULL AND next_bill_date <= :d";
        $params = ['d' => $asOf];
        if ($planIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $planIds)));
            if (!$ids) {
                return 0;
            }
            $sql .= ' AND plan_id IN (' . implode(',', $ids) . ')';
        }
        $posted = 0;
        foreach (DB::column($sql, $params) as $planId) {
            $acct = (int)DB::value('SELECT sav_account_id FROM share_capital_plans WHERE plan_id = :p', ['p' => $planId]);
            $posted += DB::tx(function () use ($planId, $asOf, $acct) {
                // the account first (as save() locks it), then the plan, before anything is read: the bills of the
                // account posted by another billing at the same time are seen, so no month is billed twice
                DB::value('SELECT id FROM sav_accounts WHERE id = :a FOR UPDATE', ['a' => $acct]);
                $p = self::plan((int)$planId, true);
                if ($p && (int)$p['sav_account_id'] !== $acct) {
                    return 0;   // its account was changed in the meantime: the next run posts it
                }
                if (!$p || $p['status'] !== 'active' || !$p['next_bill_date'] || $p['next_bill_date'] > $asOf) {
                    return 0;
                }
                $n = 0;
                $next = $p['next_bill_date'];
                $last = $p['last_bill_date'];
                $guard = 0;
                // only an open share capital account of an active member is billed; otherwise the days pass unbilled
                $open = (bool)DB::value("SELECT COUNT(*) FROM sav_accounts a JOIN members m ON m.id = a.member_id
                                          WHERE a.id = :a AND a.status IN ('active', 'dormant') AND m.status = 'active'", ['a' => $p['sav_account_id']]);
                while ($next <= $asOf && $guard++ < 600) {
                    $done = !$open || DB::value('SELECT bill_id FROM share_capital_bills WHERE plan_id = :p AND bill_date = :d', ['p' => $p['plan_id'], 'd' => $next])
                        // a month the account was already billed for (a billing deleted and added again)
                        || DB::value("SELECT bill_id FROM share_capital_bills WHERE sav_account_id = :a AND status <> 'cancelled' AND bill_date BETWEEN :f AND :t",
                            ['a' => $p['sav_account_id'], 'f' => substr($next, 0, 7) . '-01', 't' => self::monthEnd($next)]);
                    if (!$done) {
                        $term = $p['term_days'] === null || $p['term_days'] === '' ? null : (int)$p['term_days'];
                        DB::insert('share_capital_bills', ['plan_id' => $p['plan_id'], 'member_id' => $p['member_id'], 'sav_account_id' => $p['sav_account_id'],
                            'bill_date' => $next, 'due_date' => self::dueDate($next, $term), 'term_days' => $term,
                            'amount' => $p['amount'], 'amount_paid' => '0.00', 'status' => 'unpaid', 'created_at' => date('Y-m-d H:i:s')]);
                        $n++;
                    }
                    $last = $next;
                    $next = self::nextDate($next, $p['frequency'], (int)$p['posting_day']);
                }
                DB::update('share_capital_plans', ['next_bill_date' => $next, 'last_bill_date' => $last], 'plan_id = :p', ['p' => $p['plan_id']]);
                return $n;
            });
        }
        return $posted;
    }

    /** postDue() for the plans of the members of a water-service account (the cashier opening the account) */
    public static function postDueForWaterAccount(int $waterAccountId, ?string $asOf = null): int
    {
        if (!self::ready()) {
            return 0;
        }
        $ids = DB::column("SELECT pl.plan_id FROM share_capital_plans pl JOIN members m ON m.id = pl.member_id
                             JOIN water_accounts w ON w.party_id = m.party_id
                            WHERE w.pkey = :w AND pl.status = 'active'", ['w' => $waterAccountId]);
        return $ids ? self::postDue($asOf, $ids) : 0;
    }

    // ------------------------------------------------------------------ bills at the cashier

    public static function periodLabel(array $b): string
    {
        return date('M Y', strtotime($b['bill_date']));
    }

    /** The unpaid bills of the members of a water-service account, oldest first */
    public static function openBillsForWaterAccount(int $waterAccountId): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all("SELECT b.*, a.account_no, m.full_name, m.member_no, pl.frequency
                          FROM share_capital_bills b
                          JOIN share_capital_plans pl ON pl.plan_id = b.plan_id
                          JOIN members m ON m.id = b.member_id
                          JOIN sav_accounts a ON a.id = b.sav_account_id
                          JOIN water_accounts w ON w.party_id = m.party_id
                         WHERE w.pkey = :w AND b.status IN ('unpaid', 'partial') AND b.amount > b.amount_paid
                           AND a.status IN ('active', 'dormant') AND m.status IN ('active', 'applicant')
                         ORDER BY b.bill_date, b.bill_id", ['w' => $waterAccountId]);
    }

    /** One bill as the cashier collects it (checked to belong to the water-service account's member) */
    public static function billForWaterAccount(int $billId, int $waterAccountId, bool $lock = false): ?array
    {
        return DB::row("SELECT b.*, a.account_no, m.full_name, m.member_no, pl.frequency
                          FROM share_capital_bills b
                          JOIN share_capital_plans pl ON pl.plan_id = b.plan_id
                          JOIN members m ON m.id = b.member_id
                          JOIN sav_accounts a ON a.id = b.sav_account_id
                          JOIN water_accounts w ON w.party_id = m.party_id AND w.pkey = :w
                         WHERE b.bill_id = :b" . ($lock ? ' FOR UPDATE' : ''), ['b' => $billId, 'w' => $waterAccountId]) ?: null;
    }

    public static function billDescription(array $b): string
    {
        return 'SHARE CAPITAL ' . $b['account_no'] . ' (' . self::periodLabel($b) . ')';
    }

    /**
     * The collection of a bill on a water receipt: a deposit to the share capital account by the savings transaction
     * code of the page (its journal entry: Dr the code's GL account, or the cashier's cash account for a "User
     * Account" code; Cr the share capital account), numbered in the savings series and waiting for the passbook.
     * Call inside the receipt's transaction. Returns ['sav_txn_id', 'txn_id', 'trans_no'].
     */
    public static function collect(int $billId, string $amount, string $date, int $cashAccountId, array $ctx): array
    {
        $amount = Dec::round($amount, 2);
        $b = DB::row('SELECT * FROM share_capital_bills WHERE bill_id = :b FOR UPDATE', ['b' => $billId]);
        if (!$b || !in_array($b['status'], ['unpaid', 'partial'], true)) {
            fail('The share capital bill is not open for collection.');
        }
        $balance = Dec::sub($b['amount'], $b['amount_paid'], 2);
        if (!Dec::isPos($amount) || Dec::cmp($amount, $balance) > 0) {
            fail('The share capital collection must be from 0.01 to ' . Dec::money($balance) . '.');
        }
        $s = self::settings();
        $c = Savings::code($s['code']);
        if (!isset(self::codes()[$s['code']]) || (int)$c['is_active'] !== 1 || $c['entry_side'] !== 'credit') {
            fail('Savings transaction code ' . $c['code'] . ' of Fixed Share Capital Billing is not an active deposit code.');
        }
        if ($date > today()) {
            fail('A share capital collection cannot be dated in the future.');
        }
        $closing = Settings::closingDate();
        if ($closing && $date <= $closing && !Access::special('closed_period_changes')) {
            fail('The books are closed through ' . fdate($closing) . '.');
        }
        // the amount is the contribution billed here: the product's minimum deposit (a teller rule) does not apply
        $a = Savings::validate((int)$b['sav_account_id'], 'credit', $amount, $date, ['allow_pending' => true, 'skip_min_deposit' => true]);
        $opp = $c['opposite_kind'] === 'gl' && (int)$c['opposite_account_id'] ? (int)$c['opposite_account_id'] : $cashAccountId;
        if ($opp <= 0 || !DB::value('SELECT id FROM accounts WHERE id = :a', ['a' => $opp])) {
            fail('No cash account for the share capital collection: assign one to the cashier (User Accounts > cash account).');
        }
        $m = Coop::member((int)$a['member_id']);
        $receiptNo = (string)($ctx['receipt_no'] ?? '');
        $transNo = Seq::next('sav_trans');
        $liab = Savings::ledgerAccount($a);
        $line = $c['code'] . ' ' . $c['description'] . ' ' . $a['account_no'] . ', receipt ' . $receiptNo;
        $classId = $s['class_id'] ?: (isset($ctx['class_id']) ? (int)$ctx['class_id'] : null);
        $txnId = CoopLedger::withClass($classId, fn() => CoopLedger::post('coop_sv', [
            'txn_date' => $date, 'memo' => $transNo . ' ' . $c['code'] . ' ' . $m['full_name'] . ' ' . $a['account_no'] . ': share capital fixed billing, receipt ' . $receiptNo,
            'branch_id' => $a['branch_id'], 'member_id' => $a['member_id'], 'account_id' => $opp === $cashAccountId ? $opp : null, 'txn_no' => $transNo,
            'ref_no' => mb_substr($receiptNo, 0, 60) ?: null, 'reason' => $date < today() ? 'Back entry' : null,
        ], [
            ['account_id' => $opp, 'debit' => $amount, 'memo' => $line],
            ['account_id' => $liab, 'credit' => $amount, 'memo' => $line, 'member_id' => $a['member_id']],
        ]));
        $savId = Savings::move($a, $c['code'], 'credit', $amount, $date, [
            'txn_id' => $txnId, 'trans_no' => $transNo, 'or_no' => mb_substr($receiptNo, 0, 30) ?: null,
            'description' => 'Share capital fixed billing ' . self::periodLabel($b) . ', receipt ' . $receiptNo,
            'opposite_account_id' => $opp, 'is_back_entry' => $date < today(), 'ref_type' => 'sc_bill', 'ref_id' => $billId,
        ]);
        $paid = Dec::add($b['amount_paid'], $amount, 2);
        $settled = Dec::cmp($paid, $b['amount']) >= 0;
        DB::update('share_capital_bills', ['amount_paid' => Dec::fix($paid), 'status' => $settled ? 'paid' : 'partial', 'paid_at' => $settled ? date('Y-m-d H:i:s') : null],
            'bill_id = :b', ['b' => $billId]);
        DB::insert('share_capital_collections', ['bill_id' => $billId, 'transaction_id' => (int)($ctx['transaction_id'] ?? 0), 'receipt_no' => $receiptNo,
            'amount' => Dec::fix($amount), 'sav_txn_id' => $savId, 'ledger_txn_id' => $txnId, 'trans_no' => $transNo, 'status' => 'active',
            'created_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s')]);
        Audit::log('post', 'sav', 'sav_txn', $savId, $transNo . ' ' . $c['code'] . ' ' . $a['account_no'], [
            'page_key' => 'water.water_bills_collection', 'amount' => (float)$amount, 'reason' => 'Fixed share capital billing ' . self::periodLabel($b) . ', receipt ' . $receiptNo,
        ]);
        return ['sav_txn_id' => $savId, 'txn_id' => $txnId, 'trans_no' => $transNo];
    }

    /** Links the collections of a receipt to its row once the receipt is saved */
    public static function linkReceipt(string $receiptNo, int $transactionId): void
    {
        DB::run('UPDATE share_capital_collections SET transaction_id = :t WHERE receipt_no = :r AND transaction_id = 0', ['t' => $transactionId, 'r' => $receiptNo]);
    }

    /**
     * Takes back the share capital collected on a receipt (an error correction or refund of the receipt): each deposit
     * is negated on the member's ledger by the error-correct code of its code (ECSD for CSD; the code itself, negated,
     * when none is set up), with the reverse journal entry, and the bill is open again by the amount.
     */
    public static function reverseReceipt(int $transactionId, string $reason, string $date): void
    {
        foreach (DB::all("SELECT * FROM share_capital_collections WHERE transaction_id = :t AND status = 'active' ORDER BY collection_id FOR UPDATE", ['t' => $transactionId]) as $col) {
            $orig = DB::row('SELECT * FROM sav_txns WHERE id = :id FOR UPDATE', ['id' => $col['sav_txn_id']]);
            $amount = Dec::of($col['amount']);
            $revSav = null;
            $revTxn = null;
            if ($orig && (int)$orig['is_voided'] === 0 && empty($orig['corrected_by_id'])) {
                $ec = SavTxn::ecCodeFor((string)$orig['txn_code']);
                $code = $ec ? (string)$ec['code'] : (string)$orig['txn_code'];
                // the deposit of the receipt is taken back whatever is pledged on the account, never below zero
                $a = Savings::validate((int)$orig['account_id'], 'debit', $amount, $date, ['is_system' => true, 'closing' => true, 'allow_pending' => true, 'skip_available' => true]);
                if (Dec::cmp($a['balance'], $amount) < 0) {
                    fail('Share capital account ' . $a['account_no'] . ' has ' . Dec::money($a['balance']) . ' left, less than the ' . Dec::money($amount)
                        . ' collected on receipt ' . $col['receipt_no'] . ': the receipt cannot be reversed.');
                }
                $liab = Savings::ledgerAccount($a);
                $opp = (int)($orig['opposite_account_id'] ?? 0) ?: (int)DB::value("SELECT account_id FROM transaction_lines WHERE txn_id = :t AND amount > 0 ORDER BY line_no LIMIT 1", ['t' => $orig['txn_id']]);
                $transNo = Seq::next('sav_trans');
                $line = $code . ' ' . $a['account_no'] . ': reverses ' . ($orig['trans_no'] ?: '#' . $orig['id']) . ', receipt ' . $col['receipt_no'];
                $classId = DB::value('SELECT class_id FROM transaction_lines WHERE txn_id = :t ORDER BY line_no LIMIT 1', ['t' => $orig['txn_id']]);
                $revTxn = CoopLedger::withClass($classId ? (int)$classId : null, fn() => CoopLedger::post('coop_sv', [
                    'txn_date' => $date, 'memo' => $transNo . ' ' . $line . ' (' . mb_substr($reason, 0, 120) . ')',
                    'branch_id' => $a['branch_id'], 'member_id' => $a['member_id'], 'txn_no' => $transNo, 'ref_no' => mb_substr((string)$col['receipt_no'], 0, 60),
                    'reason' => 'Error correction',
                ], [
                    ['account_id' => $liab, 'debit' => $amount, 'memo' => $line, 'member_id' => $a['member_id']],
                    ['account_id' => $opp, 'credit' => $amount, 'memo' => $line],
                ]));
                $revSav = Savings::move($a, $code, 'credit', $amount, $date, [
                    'txn_id' => $revTxn, 'trans_no' => $transNo, 'negate' => true, 'corrects_id' => (int)$orig['id'], 'opposite_account_id' => $opp,
                    'description' => 'Error correct ' . ($orig['trans_no'] ?: '') . ': receipt ' . $col['receipt_no'] . ' reversed', 'is_system' => true,
                    'ref_type' => 'error_correct', 'ref_id' => (int)$orig['id'],
                ]);
                DB::update('sav_txns', ['corrected_by_id' => $revSav], 'id = :id', ['id' => $orig['id']]);
            }
            $b = DB::row('SELECT * FROM share_capital_bills WHERE bill_id = :b FOR UPDATE', ['b' => $col['bill_id']]);
            if ($b) {
                $paid = Dec::max('0', Dec::sub($b['amount_paid'], $amount, 2));
                // the bill is open again; a bill of a deleted billing left with nothing paid is cancelled, as on deleting
                $planDeleted = DB::value('SELECT status FROM share_capital_plans WHERE plan_id = :p', ['p' => $b['plan_id']]) === 'deleted';
                $status = $b['status'] === 'cancelled' || ($planDeleted && Dec::isZero($paid)) ? 'cancelled' : (Dec::isZero($paid) ? 'unpaid' : 'partial');
                DB::update('share_capital_bills', ['amount_paid' => Dec::fix($paid), 'status' => $status, 'paid_at' => null,
                    'cancelled_at' => $status === 'cancelled' ? ($b['cancelled_at'] ?: date('Y-m-d H:i:s')) : null], 'bill_id = :b', ['b' => $b['bill_id']]);
            }
            DB::update('share_capital_collections', ['status' => 'reversed', 'reversal_sav_txn_id' => $revSav, 'reversal_ledger_txn_id' => $revTxn, 'reversed_at' => date('Y-m-d H:i:s')],
                'collection_id = :c', ['c' => $col['collection_id']]);
        }
    }

    /** The bills of several plans at once, newest first, at most $limit each: plan id => rows */
    public static function billsOfPlans(array $planIds, int $limit = 36): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $planIds))));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::all("SELECT b.*, (SELECT GROUP_CONCAT(c.receipt_no ORDER BY c.collection_id SEPARATOR ', ') FROM share_capital_collections c
                                            WHERE c.bill_id = b.bill_id AND c.status = 'active') AS receipts
                                FROM share_capital_bills b WHERE b.plan_id IN (" . implode(',', $chunk) . ')
                               ORDER BY b.plan_id, b.bill_date DESC, b.bill_id DESC') as $b) {
                $pid = (int)$b['plan_id'];
                if (count($out[$pid] ?? []) < $limit) {
                    $out[$pid][] = $b;
                }
            }
        }
        return $out;
    }

    /** The bills of a plan, newest first */
    public static function bills(int $planId, int $limit = 60): array
    {
        return DB::all('SELECT b.*, (SELECT GROUP_CONCAT(c.receipt_no ORDER BY c.collection_id SEPARATOR \', \') FROM share_capital_collections c
                                    WHERE c.bill_id = b.bill_id AND c.status = \'active\') AS receipts
                          FROM share_capital_bills b WHERE b.plan_id = :p ORDER BY b.bill_date DESC, b.bill_id DESC LIMIT ' . max(1, min(500, $limit)), ['p' => $planId]);
    }
}
