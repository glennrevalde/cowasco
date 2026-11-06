<?php
declare(strict_types=1);

/**
 * The posting bridge between the water-billing module and the one general ledger.
 *
 * Every water event that has an accounting effect goes through here, and nowhere else:
 *
 *   water bill posted (metered reading, one-time, installment, recurring)  -> an INVOICE of the account's customer:
 *        Dr A/R (the bill's A/R account)  Cr the income account(s) of the bill code (gross) and Dr the discount
 *        account for a granted discount when one is mapped (otherwise the net amount, as the former program posted)
 *        A one-time or installment bill that sells a material (bill code with an item) carries the item on the line,
 *        so the cooperative inventory moves the stock and posts the cost (one inventory ledger, one costing).
 *   bill correction (edit)        -> the invoice is VOIDED (only while nothing was collected, as the water rules
 *                                    require) and a replacement invoice "1024-R1" is posted; both are linked here.
 *   bill adjustment (debit/credit)-> a supplemental invoice "1024-ADJ1" or a CREDIT MEMO applied to the bill's invoice
 *   collection (official receipt) -> a PAYMENT of the customer: Dr the cashier's cash account, Cr A/R, applied to the
 *                                    invoice(s) of every bill paid (partial and multiple-bill settlement kept);
 *                                    penalty and compromise collected on the spot are FINANCE CHARGES posted first
 *                                    (Dr A/R, Cr penalty / compromise income) and settled by the same payment
 *   collection reversal / refund  -> the payment and its finance charges are VOIDED (the ledger lines are removed,
 *                                    the documents stay, the audit trail keeps a copy), linked to the original
 *   material issuance             -> an INVENTORY ADJUSTMENT: Dr the expense account, Cr inventory, stock out
 *
 * Idempotency: every event has a unique key (water_postings.event_key, e.g. WBILL-123, COL-<receipt>). The row is
 * written inside the same database transaction as the ledger document and the water record, so a retry, a second
 * cashier or an import replay finds the key and gets the existing document back instead of posting again.
 *
 * Document numbers: the invoice number is the water bill's own number (global reference number or WB-/OTB-/INST-/RB-
 * + id), the payment number is the official receipt number. They are never mixed with the ledger's own sequences.
 */
final class WaterPosting
{
    /** source => [event prefix, label, legacy document prefix] */
    public const SOURCES = [
        'water'       => ['WBILL', 'Water Bill', 'WB-'],
        'one_time'    => ['OTB', 'One-Time Bill', 'OTB-'],
        'installment' => ['INST', 'Installment Bill', 'INST-'],
        'recurring'   => ['RBILL', 'Recurring Bill', 'RB-'],
    ];

    // ------------------------------------------------------------------ the class of the form that posts

    /** The class chosen on the water form that is posting (withClass()): it goes on every document it posts */
    private static ?int $classId = null;

    /**
     * Posts what $fn posts under the class chosen on the water form (Chart of Accounts > Class List, the same list
     * as the accounting books): the bills, adjustments, collections and finance charges it writes carry that class.
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

    public static function currentClass(): ?int
    {
        return self::$classId;
    }

    /** The class a document posts under: the form's, else the one the code passes, else the branch's (older records) */
    private static function classFor(array $opt): ?int
    {
        if (self::$classId) {
            return self::$classId;
        }
        if (!empty($opt['class_id'])) {
            return (int)$opt['class_id'];
        }
        return self::classIdForBranch((int)($opt['branch_id'] ?? 0));
    }

    /** True once water_postings and the water bills keep their class (upgrade 2026_12_20) */
    public static function classColumns(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(class_id) FROM water_postings WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    // ------------------------------------------------------------------ set-up

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM water_postings WHERE 1 = 0');
                $ok = true;
            } catch (Throwable $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** The Water Utility line of business */
    public static function unitId(): ?int
    {
        $id = (int)Settings::get('water_business_unit_id', '0');
        if ($id <= 0 && BusinessUnits::ready()) {
            $id = (int)(DB::value("SELECT id FROM business_units WHERE code = 'WU'") ?: 0);
        }
        return $id > 0 ? $id : null;
    }

    /**
     * The class (branch) a water branch posts under. A document that carries no branch (a recurring bill generated
     * from a plan, a material issuance) posts under the utility's only branch when it has exactly one; with several
     * branches and none on the document it stays unclassified (shown as such in the reports by class).
     */
    public static function classIdForBranch(int $branchId): ?int
    {
        if ($branchId <= 0) {
            try {
                $only = DB::column('SELECT pkey FROM branches WHERE COALESCE(Deleted, 0) = 0 LIMIT 2');
            } catch (Throwable $e) {
                $only = [];
            }
            if (count($only) !== 1) {
                return null;
            }
            $branchId = (int)$only[0];
        }
        try {
            $r = DB::row('SELECT class_id, name FROM branches WHERE pkey = :id', ['id' => $branchId]);
        } catch (Throwable $e) {
            return null;
        }
        if (!$r) {
            return null;
        }
        if (!empty($r['class_id'])) {
            return (int)$r['class_id'];
        }
        // a branch without a class yet: the class of the same name, made when missing (Classes are the branches of the ledger)
        $cid = DB::value('SELECT id FROM classes WHERE name = :n', ['n' => $r['name']]);
        if (!$cid) {
            $cid = DB::insert('classes', ['name' => mb_substr((string)$r['name'], 0, 80), 'description' => 'Water utility branch', 'is_active' => 1, 'sort_order' => 90, 'created_by' => Auth::id()]);
        }
        DB::run('UPDATE branches SET class_id = :c WHERE pkey = :id', ['c' => $cid, 'id' => $branchId]);
        return (int)$cid;
    }

    /** How many posting methods of this class are running (the ledger guard in Txn lets them change water documents) */
    private static int $active = 0;

    /**
     * Marks the water module as the one changing ledger documents until the returned object goes out of scope.
     * Txn refuses to edit, void, delete or settle a document of a water event from anywhere else (a cooperative
     * payment, credit memo or invoice page): the water records and the ledger would part.
     */
    public static function owning(): object
    {
        self::$active++;
        return new class {
            public function __destruct()
            {
                WaterPosting::release();
            }
        };
    }

    /** @internal called when the object of owning() is released */
    public static function release(): void
    {
        self::$active = max(0, self::$active - 1);
    }

    public static function isActive(): bool
    {
        return self::$active > 0;
    }

    /** The water event a ledger document belongs to (its event key), or null when it is not a water document */
    public static function ownerOf(int $txnId): ?string
    {
        if ($txnId <= 0 || !self::ready()) {
            return null;
        }
        $k = DB::value("SELECT event_key FROM water_postings WHERE txn_id = :t AND status <> 'failed' ORDER BY id LIMIT 1", ['t' => $txnId]);
        return $k === null ? null : (string)$k;
    }

    /**
     * The refusal for a change to a water document made outside the water module, or null when it may go ahead.
     * $what: 'edit', 'void', 'delete' or 'apply'.
     */
    public static function guard(int $txnId, string $what): ?string
    {
        if (self::isActive()) {
            return null;
        }
        $key = self::ownerOf($txnId);
        if ($key === null) {
            return null;
        }
        $doc = (string)(DB::value('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $txnId]) ?? $txnId);
        if (str_starts_with($key, 'LEGACY-')) {
            return "Document $doc is an opening balance brought in by the water migration (event $key); it is not changed here. A failed cut-over rehearsal is undone with tools/rollback_water.php.";
        }
        if ($what === 'apply') {
            return "Document $doc is a water bill (water event $key). It is collected on Water Billing > Collections (Cashier), so the water records and the ledger are settled together.";
        }
        return "Document $doc belongs to the water module (water event $key) and is not " . ($what === 'edit' ? 'edited' : ($what === 'void' ? 'voided' : 'deleted'))
            . ' here. Correct it on Water Billing > Billing Adjustments (bills) or Collection Corrections (receipts); the correction voids and replaces it with a link to the original.';
    }

    /** A water default account (Module Default Accounts > Water), null when not set */
    public static function defaultAccount(string $key): ?int
    {
        $id = DB::value('SELECT account_id FROM default_accounts WHERE setting_key = :k', ['k' => $key]);
        return $id ? (int)$id : null;
    }

    /**
     * The account that offsets water balances brought in from a former program (open bills at the cut-over): Module
     * Default Accounts > Water > Legacy balances clearing. When it is not set, the equity account 39999 Legacy Water
     * Balances Clearing is made (or the existing one taken) and set as the default, as tools/migrate_water.php does.
     */
    public static function legacyClearingAccount(): int
    {
        $id = self::defaultAccount('water_legacy_clearing');
        if ($id) {
            return self::assertAccount($id, 'The legacy water balances clearing account');
        }
        $id = (int)(DB::value("SELECT id FROM accounts WHERE code = '39999'") ?: 0);
        if (!$id) {
            $id = DB::insert('accounts', ['code' => '39999', 'name' => 'Legacy Water Balances Clearing', 'account_type' => 'Equity', 'category' => 'Equity',
                'normal_balance' => 'Credit', 'description' => 'Offset of the water receivables brought in from the former water program at the cut-over',
                'with_sl' => 0, 'is_active' => 1, 'is_system' => 0]);
        }
        if (DB::value("SELECT setting_key FROM default_accounts WHERE setting_key = 'water_legacy_clearing'")) {
            DB::run("UPDATE default_accounts SET account_id = :a WHERE setting_key = 'water_legacy_clearing'", ['a' => $id]);
        } else {
            DB::insert('default_accounts', ['setting_key' => 'water_legacy_clearing', 'label' => 'Legacy water balances clearing', 'module' => 'water', 'account_id' => $id]);
        }
        return $id;
    }

    /** An account id that exists and is active, else a clear error naming what it was for */
    public static function assertAccount(?int $accountId, string $label): int
    {
        if (!$accountId) {
            fail($label . ' is not mapped. Set it on the bill code, the collection policy or Financial > Module Default Accounts (Water).');
        }
        $a = DB::row('SELECT id, is_active, name FROM accounts WHERE id = :id', ['id' => $accountId]);
        if (!$a) {
            fail($label . ' points to account #' . $accountId . ', which is not in the chart of accounts.');
        }
        if ((int)$a['is_active'] !== 1) {
            fail($label . ' (' . $a['name'] . ') is inactive in the chart of accounts.');
        }
        return (int)$a['id'];
    }

    /**
     * The A/R account of a bill (the one it names, else the water default, else the A/R default). It must be an
     * Accounts Receivable account: a bill posted anywhere else would not be in the receivables, the aging or the
     * party statement, so a misconfigured bill code stops here with the reason instead of posting.
     */
    public static function arAccount(?int $preferred = null): int
    {
        $id = self::assertAccount($preferred ?: (self::defaultAccount('water_ar') ?: (int)DB::value("SELECT account_id FROM default_accounts WHERE setting_key = 'ar'")), 'The water Accounts Receivable account');
        $a = DB::row('SELECT code, name, account_type FROM accounts WHERE id = :id', ['id' => $id]);
        if (($a['account_type'] ?? '') !== 'Accounts Receivable') {
            fail('The A/R account of this bill, ' . $a['code'] . ' ' . $a['name'] . ', is a ' . $a['account_type'] . ' account, not Accounts Receivable. Correct the bill code on Water Billing > Bill Codes (or the water A/R default) before posting.');
        }
        return $id;
    }

    /**
     * The account a bill line credits: income (water sales, fees, penalties), or a liability / equity account for a bill
     * code that collects a deposit, a prepayment or a share subscription. Never an asset or expense account: a bill that
     * credits cash or an expense is a bill code set up wrong, and it stops here with the reason.
     */
    public static function creditAccount(int $accountId, string $label): int
    {
        $id = self::assertAccount($accountId, $label);
        $a = DB::row('SELECT code, name, category, account_type FROM accounts WHERE id = :id', ['id' => $id]);
        if (!in_array($a['category'] ?? '', ['Income', 'Liability', 'Equity'], true)) {
            fail($label . ', ' . $a['code'] . ' ' . $a['name'] . ', is a ' . $a['account_type'] . ' account; a bill credits income (or a deposit / prepayment liability). Correct the bill code on Water Billing > Bill Codes.');
        }
        return $id;
    }

    public static function eventKey(string $source, int $id, int $revision = 0): string
    {
        $prefix = self::SOURCES[$source][0] ?? strtoupper($source);
        return $prefix . '-' . $id . ($revision > 0 ? '-R' . $revision : '');
    }

    public static function docNo(string $source, int $id, $globalRef = null, int $revision = 0): string
    {
        $no = trim((string)$globalRef);
        if ($no === '' || $no === '0') {
            $no = (self::SOURCES[$source][2] ?? 'BILL-') . $id;
        }
        return mb_substr($no . ($revision > 0 ? '-R' . $revision : ''), 0, 30);
    }

    // ------------------------------------------------------------------ look-ups

    public static function byEvent(string $eventKey): ?array
    {
        return DB::row('SELECT * FROM water_postings WHERE event_key = :k', ['k' => $eventKey]);
    }

    /** The posted (live) invoice postings of a bill, oldest first: the original or its replacement, plus debit adjustments */
    public static function billPostings(string $source, int $id): array
    {
        return DB::all("SELECT w.*, t.total, t.txn_no, t.txn_date, t.account_id AS ar_account_id, t.is_voided
                          FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                         WHERE w.source_type = :s AND w.source_id = :i AND w.status = 'posted' AND t.is_voided = 0
                           AND t.txn_type = 'invoice' ORDER BY w.id", ['s' => self::sourceType($source), 'i' => (string)$id]);
    }

    public static function billTxnId(string $source, int $id): ?int
    {
        $rows = self::billPostings($source, $id);
        return $rows ? (int)$rows[0]['txn_id'] : null;
    }

    /** The A/R account a bill was posted to (its live invoice), null when it was never posted */
    public static function billArAccount(string $source, int $id): ?int
    {
        $rows = self::billPostings($source, $id);
        return $rows ? (int)$rows[0]['ar_account_id'] : null;
    }

    public static function sourceType(string $source): string
    {
        return match ($source) {
            'water', 'water_bill' => 'water_bill',
            'one_time', 'onetime', 'one_time_bill' => 'one_time_bill',
            'installment', 'installment_bill' => 'installment_bill',
            'recurring', 'recurring_bill' => 'recurring_bill',
            default => $source,
        };
    }

    private static function sourceKey(string $source): string
    {
        return match ($source) {
            'water', 'water_bill' => 'water',
            'one_time', 'onetime', 'one_time_bill' => 'one_time',
            'installment', 'installment_bill' => 'installment',
            'recurring', 'recurring_bill' => 'recurring',
            default => $source,
        };
    }

    private static function record(array $row): int
    {
        $row += ['status' => 'posted', 'created_by' => Auth::id(), 'amount' => 0];
        if (self::classColumns() && !array_key_exists('class_id', $row) && !empty($row['txn_id'])) {
            $row['class_id'] = DB::value('SELECT class_id FROM transactions WHERE id = :t', ['t' => $row['txn_id']]) ?: null;
        }
        if (!self::classColumns()) {
            unset($row['class_id']);
        }
        return DB::insert('water_postings', $row);
    }

    private static function header(array $opt, string $type, int $customerId): array
    {
        return [
            'txn_date'         => (string)$opt['txn_date'],
            'customer_id'      => $customerId,
            'class_id'         => self::classFor($opt),
            'business_unit_id' => self::unitId(),
            'memo'             => mb_substr((string)($opt['memo'] ?? ''), 0, 255) ?: null,
            'ref_no'           => mb_substr((string)($opt['ref_no'] ?? ''), 0, 60) ?: null,
        ];
    }

    // ------------------------------------------------------------------ bills

    /**
     * Posts a water bill as an invoice (idempotent on its event key). Nothing is posted for a zero amount.
     *
     * $opt: water_account_id, txn_date, due_date, doc_no, ar_account_id, lines [[account_id, amount, description,
     *       item_id, qty, cost?]], discount [account_id, amount] (optional), memo, branch_id, segment_id, division_id,
     *       revision (int, for a replacement), replaces (event key of the voided original), reason
     * Returns [posting_id, txn_id, txn_no, journal_head_id (=txn_id as text), journal_entry_id (=txn_no)], or null
     * when the amount is zero.
     */
    public static function postBill(string $source, int $sourceId, array $opt): ?array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $source = self::sourceKey($source);
        $revision = (int)($opt['revision'] ?? 0);
        $eventKey = $opt['event_key'] ?? self::eventKey($source, $sourceId, $revision);
        return DB::tx(function () use ($source, $sourceId, $opt, $revision, $eventKey) {
            if ($existing = self::byEvent($eventKey)) {
                if ($existing['status'] === 'posted') {
                    return self::result($existing);
                }
                fail('The accounting document of ' . $eventKey . ' was voided; a new posting needs a new revision number.');
            }
            $lines = [];
            $total = 0.0;
            foreach ((array)($opt['lines'] ?? []) as $l) {
                $amt = r2((float)($l['amount'] ?? 0));
                if (abs($amt) < 0.005) {
                    continue;
                }
                $line = [
                    'line_kind'   => 'item',
                    'item_id'     => !empty($l['item_id']) ? (int)$l['item_id'] : null,
                    // a legacy open balance brought in at cutover credits the clearing account (equity) like any opening entry
                    'account_id'  => !empty($l['account_id']) ? (!empty($opt['opening']) ? self::assertAccount((int)$l['account_id'], 'The offset account of ' . ($l['description'] ?? 'the bill'))
                                        : self::creditAccount((int)$l['account_id'], 'The income account of ' . ($l['description'] ?? 'the bill'))) : null,
                    'description' => mb_substr((string)($l['description'] ?? ''), 0, 255),
                    'qty'         => (float)($l['qty'] ?? 1),
                    'rate'        => isset($l['rate']) ? (float)$l['rate'] : ((float)($l['qty'] ?? 1) != 0.0 ? r2($amt / (float)($l['qty'] ?? 1)) : $amt),
                    'amount'      => $amt,
                    'tax_amount'  => 0,
                ];
                if ($line['item_id'] === null && $line['account_id'] === null) {
                    fail('Every bill line needs an income account.');
                }
                $lines[] = $line;
                $total += $amt;
            }
            if (!empty($opt['discount']['amount']) && r2((float)$opt['discount']['amount']) > 0 && !empty($opt['discount']['account_id'])) {
                $disc = r2((float)$opt['discount']['amount']);
                $lines[] = ['line_kind' => 'item', 'item_id' => null, 'account_id' => self::assertAccount((int)$opt['discount']['account_id'], 'The water discount account'),
                    'description' => mb_substr((string)($opt['discount']['description'] ?? 'Discount granted'), 0, 255), 'qty' => 1, 'rate' => -$disc, 'amount' => -$disc, 'tax_amount' => 0];
                $total -= $disc;
            }
            $total = r2($total);
            if ($total <= 0 && !$lines) {
                return null;
            }
            if ($total < 0) {
                fail('A bill cannot post a negative total (' . money($total) . ').');
            }
            $accountId = (int)($opt['water_account_id'] ?? 0);
            if ($accountId <= 0) {
                fail('The bill has no water-service account.');
            }
            $customerId = WaterAccounts::ensureCustomer($accountId);
            $h = self::header($opt, 'invoice', $customerId) + [
                'txn_no'     => self::docNo($source, $sourceId, $opt['doc_no'] ?? null, $revision),
                'due_date'   => !empty($opt['due_date']) ? (string)$opt['due_date'] : (string)$opt['txn_date'],
                'account_id' => self::arAccount(!empty($opt['ar_account_id']) ? (int)$opt['ar_account_id'] : null),
            ];
            if (!empty($opt['site_id'])) {
                $h['site_id'] = (int)$opt['site_id'];
            } elseif (array_filter($lines, fn($l) => $l['item_id'] !== null)) {
                $h['site_id'] = self::siteId();
            }
            $txnId = Txn::save('invoice', $h, $lines, null, ['skip_policy' => true, 'reason' => $opt['reason'] ?? null]);
            $t = Txn::load($txnId);
            $id = self::record([
                'event_key' => $eventKey, 'source_type' => self::sourceType($source), 'source_id' => (string)$sourceId,
                'doc_no' => $h['txn_no'], 'water_account_id' => $accountId, 'party_id' => $t['party_id'] ?? null,
                'txn_id' => $txnId, 'txn_type' => 'invoice', 'amount' => $total,
                'reversal_of_id' => !empty($opt['replaces']) ? (self::byEvent((string)$opt['replaces'])['id'] ?? null) : null,
                'reason' => mb_substr((string)($opt['reason'] ?? ''), 0, 255) ?: null,
                'branch_id' => (int)($opt['branch_id'] ?? 0) ?: null, 'segment_id' => (int)($opt['segment_id'] ?? 0) ?: null, 'division_id' => (int)($opt['division_id'] ?? 0) ?: null,
                'class_id' => $h['class_id'] ?? null,
            ]);
            // the bill keeps the class it was posted under (shown on the water lists, offered again on its adjustments)
            self::stampSourceClass($source, $sourceId, $h['class_id'] ?? null);
            return self::result(self::byEvent($eventKey));
        });
    }

    /** The water bill table and key of a source */
    private const SOURCE_TABLES = [
        'water' => ['bill_readings', 'reading_id'], 'one_time' => ['one_time_bills', 'bill_id'],
        'installment' => ['installment_bills', 'installment_id'], 'recurring' => ['recurring_bills', 'bill_id'],
    ];

    /** Writes the class a bill was posted under on the bill itself */
    public static function stampSourceClass(string $source, int $sourceId, ?int $classId): void
    {
        $source = self::sourceKey($source);
        if (!$classId || !self::classColumns() || !isset(self::SOURCE_TABLES[$source])) {
            return;
        }
        [$table, $key] = self::SOURCE_TABLES[$source];
        DB::run("UPDATE `$table` SET class_id = :c WHERE `$key` = :id", ['c' => $classId, 'id' => $sourceId]);
    }

    /** The class a bill was posted under (null when it has none) */
    public static function sourceClass(string $source, int $sourceId): ?int
    {
        $source = self::sourceKey($source);
        if (!self::classColumns() || !isset(self::SOURCE_TABLES[$source])) {
            return null;
        }
        [$table, $key] = self::SOURCE_TABLES[$source];
        $c = DB::value("SELECT class_id FROM `$table` WHERE `$key` = :id", ['id' => $sourceId]);
        return $c ? (int)$c : null;
    }

    /** The inventory site water materials move from: the Water Utility unit's default site, else the first active site */
    public static function siteId(): int
    {
        $unit = self::unitId();
        $site = $unit ? BusinessUnits::siteFor($unit) : null;
        if (!$site) {
            $site = (int)(DB::value('SELECT id FROM sites WHERE is_active = 1 ORDER BY id LIMIT 1') ?: 0);
        }
        if (!$site) {
            fail('Set up at least one inventory site (Inventory > Inventory Site List) before billing or issuing materials.');
        }
        return (int)$site;
    }

    private static function result(array $w): array
    {
        return [
            'posting_id' => (int)$w['id'],
            'txn_id' => (int)$w['txn_id'],
            'txn_no' => (string)$w['doc_no'],
            'event_key' => (string)$w['event_key'],
            // the names the water code stored its legacy voucher ids under
            'journal_head_id' => (string)$w['txn_id'],
            'journal_entry_id' => (string)$w['doc_no'],
            'journal_voucher_id' => (string)$w['txn_id'],
            'jv_entry_id' => (string)$w['doc_no'],
        ];
    }

    /** The credit memos of a bill still in force (its own adjustments down), by transaction id */
    public static function billCreditMemoIds(string $source, int $sourceId): array
    {
        return array_map('intval', DB::column("SELECT w.txn_id FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                                                 WHERE w.source_type = :s AND w.source_id = :i AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'credit_memo'",
            ['s' => self::sourceType(self::sourceKey($source)), 'i' => (string)$sourceId]));
    }

    /**
     * What was collected on a bill: the payments and other credits applied to its live invoices, leaving out the
     * bill's own credit memos (an amount lowered on the bill is not a collection).
     */
    public static function billCollected(string $source, int $sourceId): float
    {
        $own = self::billCreditMemoIds($source, $sourceId);
        $sum = 0.0;
        foreach (self::billPostings($source, $sourceId) as $w) {
            $sum += self::appliedExcept((int)$w['txn_id'], $own);
        }
        return round($sum, 2);
    }

    /** The amount applied to an invoice by documents other than those listed */
    private static function appliedExcept(int $txnId, array $exceptIds): float
    {
        $sql = 'SELECT COALESCE(SUM(pa.amount + pa.discount + pa.withholding), 0) FROM payment_applications pa
                  JOIN transactions p ON p.id = pa.payment_txn_id AND p.is_voided = 0
                 WHERE pa.target_txn_id = :t';
        if ($exceptIds) {
            $sql .= ' AND pa.payment_txn_id NOT IN (' . implode(',', array_map('intval', $exceptIds)) . ')';
        }
        return (float)DB::value($sql, ['t' => $txnId]);
    }

    /**
     * Voids the live invoice(s) of a bill (an edit before collection, a soft delete), and the bill's own credit memos
     * with them. Refused when anything was collected on them: the water rules only allow editing a bill nobody has
     * paid. Returns the voided postings, the invoices first.
     */
    public static function voidBill(string $source, int $sourceId, string $reason, string $newStatus = 'voided'): array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $source = self::sourceKey($source);
        return DB::tx(function () use ($source, $sourceId, $reason, $newStatus) {
            $invoices = self::billPostings($source, $sourceId);
            $memoIds = self::billCreditMemoIds($source, $sourceId);
            foreach ($invoices as $w) {
                if (self::appliedExcept((int)$w['txn_id'], $memoIds) > 0.004) {
                    fail('Invoice ' . $w['txn_no'] . ' has collections applied to it. Reverse the collection first.');
                }
            }
            // the credit memos of the bill go first: an invoice cannot be voided while a credit is applied to it
            $memos = [];
            foreach (DB::all("SELECT w.*, t.txn_no FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                                WHERE w.source_type = :s AND w.source_id = :i AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'credit_memo'",
                             ['s' => self::sourceType($source), 'i' => (string)$sourceId]) as $w) {
                DB::run('DELETE FROM payment_applications WHERE payment_txn_id = :p', ['p' => $w['txn_id']]);
                Txn::void((int)$w['txn_id'], mb_substr($reason, 0, 255));
                DB::update('water_postings', ['status' => $newStatus, 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $w['id']]);
                $memos[] = ['journal_voucher_id' => (string)$w['txn_id'], 'jv_entry_id' => (string)$w['txn_no'], 'event_key' => $w['event_key'], 'amount' => (float)$w['amount']];
            }
            $out = [];
            foreach ($invoices as $w) {
                Txn::void((int)$w['txn_id'], mb_substr($reason, 0, 255));
                DB::update('water_postings', ['status' => $newStatus, 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $w['id']]);
                $out[] = ['journal_voucher_id' => (string)$w['txn_id'], 'jv_entry_id' => (string)$w['doc_no'], 'event_key' => $w['event_key'], 'amount' => (float)$w['total']];
            }
            return array_merge($out, $memos);
        });
    }

    /** The next revision number of a bill (how many postings it had, voided ones included) */
    public static function nextRevision(string $source, int $sourceId): int
    {
        $n = (int)DB::value("SELECT COUNT(*) FROM water_postings WHERE source_type = :s AND source_id = :i AND txn_type = 'invoice' AND event_key NOT LIKE '%-ADJ%'",
            ['s' => self::sourceType($source), 'i' => (string)$sourceId]);
        return max(1, $n);
    }

    /**
     * A bill adjustment after posting: a debit (increase) posts a supplemental invoice "<no>-ADJn"; a credit (decrease)
     * posts a credit memo applied to the bill's invoice(s). $delta is signed (+ debit, - credit).
     */
    public static function postBillAdjustment(string $source, int $sourceId, float $delta, array $opt): ?array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $source = self::sourceKey($source);
        $delta = r2($delta);
        if (abs($delta) < 0.005) {
            return null;
        }
        return DB::tx(function () use ($source, $sourceId, $delta, $opt) {
            $live = self::billPostings($source, $sourceId);
            if (!$live) {
                fail('The bill has no posted accounting document to adjust.');
            }
            $n = (int)DB::value("SELECT COUNT(*) FROM water_postings WHERE source_type = :s AND source_id = :i AND event_key LIKE '%-ADJ%'",
                ['s' => self::sourceType($source), 'i' => (string)$sourceId]) + 1;
            $eventKey = $opt['event_key'] ?? (self::eventKey($source, $sourceId) . '-ADJ' . $n);
            if ($existing = self::byEvent($eventKey)) {
                return self::result($existing);
            }
            $accountId = (int)($opt['water_account_id'] ?? $live[0]['water_account_id']);
            $customerId = WaterAccounts::ensureCustomer($accountId);
            $incomeAccount = self::assertAccount((int)($opt['income_account_id'] ?? 0) ?: null, 'The income account of the adjustment');
            $arAccount = (int)$live[0]['ar_account_id'];
            $docNo = mb_substr((string)$live[0]['txn_no'], 0, 24) . '-ADJ' . $n;
            $h = self::header($opt, 'invoice', $customerId) + ['txn_no' => $docNo, 'account_id' => $arAccount, 'due_date' => $opt['due_date'] ?? $opt['txn_date']];
            $desc = mb_substr((string)($opt['description'] ?? 'Bill adjustment'), 0, 255);
            if ($delta > 0) {
                $txnId = Txn::save('invoice', $h, [['line_kind' => 'item', 'account_id' => $incomeAccount, 'description' => $desc, 'qty' => 1, 'rate' => $delta, 'amount' => $delta, 'tax_amount' => 0]], null, ['skip_policy' => true]);
                $type = 'invoice';
            } else {
                $credit = -$delta;
                // applied to the bill's invoices, newest open balance first is not needed: oldest first
                $apps = [];
                $left = $credit;
                foreach ($live as $w) {
                    $open = Balances::open(Txn::load((int)$w['txn_id']));
                    $take = min($open, $left);
                    if ($take > 0.004) {
                        $apps[] = ['target_txn_id' => (int)$w['txn_id'], 'amount' => $take];
                        $left = r2($left - $take);
                    }
                }
                if ($left > 0.004) {
                    fail('The credit (' . money($credit) . ') is larger than the open balance of the bill in the ledger.');
                }
                $txnId = Txn::save('credit_memo', $h, [['line_kind' => 'item', 'account_id' => $incomeAccount, 'description' => $desc, 'qty' => 1, 'rate' => $credit, 'amount' => $credit, 'tax_amount' => 0]], null, ['skip_policy' => true, 'applications' => $apps]);
                $type = 'credit_memo';
            }
            $t = Txn::load($txnId);
            self::record([
                'event_key' => $eventKey, 'source_type' => self::sourceType($source), 'source_id' => (string)$sourceId, 'doc_no' => $docNo,
                'water_account_id' => $accountId, 'party_id' => $t['party_id'] ?? null, 'txn_id' => $txnId, 'txn_type' => $type, 'amount' => abs($delta),
                'reason' => mb_substr((string)($opt['reason'] ?? ''), 0, 255) ?: null, 'reversal_of_id' => (int)$live[0]['id'],
                'branch_id' => (int)($opt['branch_id'] ?? 0) ?: null, 'segment_id' => (int)($opt['segment_id'] ?? 0) ?: null, 'division_id' => (int)($opt['division_id'] ?? 0) ?: null,
            ]);
            return self::result(self::byEvent($eventKey));
        });
    }

    // ------------------------------------------------------------------ collections

    /**
     * Posts an official receipt: finance charges for penalty / compromise, then one payment per A/R account with
     * the applications to the bills' invoices. Idempotent on COL-<receipt>.
     *
     * $opt: water_account_id, txn_date, cash_account_id, allocations [[source, source_id, amount, description]],
     *       penalty, compromise, penalty_account_id, compromise_account_id, memo, branch_id, segment_id, division_id,
     *       payment_method_id
     * Returns [journal_head_id, journal_entry_id, txn_ids[], finance_charge_ids[]]
     */
    /** The event key of a receipt: the receipt number itself when it already carries the COL- prefix */
    public static function collectionKey(string $receiptNo): string
    {
        return mb_substr(str_starts_with($receiptNo, 'COL-') ? $receiptNo : 'COL-' . $receiptNo, 0, 60);
    }

    public static function postCollection(string $receiptNo, array $opt): array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $eventKey = self::collectionKey($receiptNo);
        return DB::tx(function () use ($receiptNo, $opt, $eventKey) {
            if ($existing = self::byEvent($eventKey)) {
                if ($existing['status'] !== 'posted') {
                    fail('Receipt ' . $receiptNo . ' was already reversed.');
                }
                return self::result($existing) + ['duplicate' => true];
            }
            $accountId = (int)($opt['water_account_id'] ?? 0);
            $customerId = WaterAccounts::ensureCustomer($accountId);
            $cash = self::assertAccount((int)($opt['cash_account_id'] ?? 0) ?: (self::defaultAccount('water_cash') ?: null), "The cashier's cash account");
            $date = (string)$opt['txn_date'];
            $classId = self::classFor($opt);

            // the invoices each allocation settles, by A/R account
            $groups = [];
            $oldestArAccount = null;
            foreach ((array)($opt['allocations'] ?? []) as $a) {
                $amount = r2((float)($a['amount'] ?? 0));
                if ($amount <= 0) {
                    continue;
                }
                $src = self::sourceKey((string)$a['source']);
                $live = self::billPostings($src, (int)$a['source_id']);
                if (!$live) {
                    fail(($a['description'] ?? ($src . ' #' . $a['source_id'])) . ' has no posted accounting document; it cannot be collected until it is posted.');
                }
                $left = $amount;
                foreach ($live as $w) {
                    $open = Balances::open(Txn::load((int)$w['txn_id']));
                    $take = r2(min($open, $left));
                    if ($take > 0.004) {
                        $ar = (int)$w['ar_account_id'];
                        $groups[$ar][] = ['target_txn_id' => (int)$w['txn_id'], 'amount' => $take];
                        $oldestArAccount ??= $ar;
                        $left = r2($left - $take);
                    }
                    if ($left <= 0.004) {
                        break;
                    }
                }
                if ($left > 0.004) {
                    fail(($a['description'] ?? 'A bill') . ': the ledger shows an open balance smaller than the amount collected (' . money($left) . ' more than open). Reconcile the bill before collecting.');
                }
            }
            if (!$groups) {
                fail('Nothing to collect: no allocation has a posted bill.');
            }

            // penalty and compromise: finance charges of the account, settled by this receipt
            $charges = [];
            foreach (['penalty' => 'water_penalty', 'compromise' => 'water_compromise'] as $kind => $defaultKey) {
                $amt = r2((float)($opt[$kind] ?? 0));
                if ($amt <= 0) {
                    continue;
                }
                $income = self::assertAccount((int)($opt[$kind . '_account_id'] ?? 0) ?: (self::defaultAccount($defaultKey) ?: null), ucfirst($kind) . ' income account');
                $ar = (int)$oldestArAccount;
                $chargeNo = mb_substr($receiptNo, 0, 24) . ($kind === 'penalty' ? '-PEN' : '-COMP');
                $fcId = Txn::save('finance_charge', [
                    'txn_date' => $date, 'due_date' => $date, 'customer_id' => $customerId, 'account_id' => $ar, 'txn_no' => $chargeNo,
                    'class_id' => $classId, 'business_unit_id' => self::unitId(),
                    'memo' => mb_substr(ucfirst($kind) . ' collected with receipt ' . $receiptNo, 0, 255), 'ref_no' => mb_substr($receiptNo, 0, 60),
                ], [['line_kind' => 'item', 'account_id' => $income, 'description' => ucfirst($kind) . ' on receipt ' . $receiptNo, 'qty' => 1, 'rate' => $amt, 'amount' => $amt, 'tax_amount' => 0]], null, ['skip_policy' => true]);
                $groups[$ar][] = ['target_txn_id' => $fcId, 'amount' => $amt];
                $charges[] = $fcId;
                self::record([
                    'event_key' => $eventKey . ($kind === 'penalty' ? '#PEN' : '#COMP'), 'source_type' => 'collection_' . $kind, 'source_id' => $receiptNo, 'doc_no' => $chargeNo,
                    'water_account_id' => $accountId, 'party_id' => Txn::load($fcId)['party_id'] ?? null, 'txn_id' => $fcId, 'txn_type' => 'finance_charge', 'amount' => $amt,
                    'branch_id' => (int)($opt['branch_id'] ?? 0) ?: null, 'segment_id' => (int)($opt['segment_id'] ?? 0) ?: null, 'division_id' => (int)($opt['division_id'] ?? 0) ?: null,
                ]);
            }

            // one payment per A/R account (the receipt keeps its number; a second group gets "#2")
            $txnIds = [];
            $n = 0;
            $firstPostingId = null;
            foreach ($groups as $ar => $apps) {
                $n++;
                $total = r2(array_sum(array_column($apps, 'amount')));
                $txnNo = mb_substr($receiptNo, 0, 27) . ($n > 1 ? '#' . $n : '');
                $pid = Txn::save('payment', [
                    'txn_date' => $date, 'customer_id' => $customerId, 'txn_no' => $txnNo, 'ref_no' => mb_substr($receiptNo, 0, 60),
                    'account_id' => $cash, 'account2_id' => $ar, 'total' => $total, 'discount_amount' => 0, 'withholding_amount' => 0,
                    'payment_method_id' => !empty($opt['payment_method_id']) ? (int)$opt['payment_method_id'] : null,
                    'class_id' => $classId, 'business_unit_id' => self::unitId(),
                    'memo' => mb_substr((string)($opt['memo'] ?? ('Official receipt ' . $receiptNo)), 0, 255),
                ], [], null, ['keep_totals' => true, 'applications' => $apps, 'skip_policy' => true]);
                $txnIds[] = $pid;
                $postingId = self::record([
                    'event_key' => $eventKey . ($n > 1 ? '#' . $n : ''), 'source_type' => 'collection', 'source_id' => $receiptNo, 'doc_no' => $txnNo,
                    'water_account_id' => $accountId, 'party_id' => Txn::load($pid)['party_id'] ?? null, 'txn_id' => $pid, 'txn_type' => 'payment', 'amount' => $total,
                    'branch_id' => (int)($opt['branch_id'] ?? 0) ?: null, 'segment_id' => (int)($opt['segment_id'] ?? 0) ?: null, 'division_id' => (int)($opt['division_id'] ?? 0) ?: null,
                ]);
                $firstPostingId ??= $postingId;
            }
            return self::result(self::byEvent($eventKey)) + ['txn_ids' => $txnIds, 'finance_charge_ids' => $charges, 'duplicate' => false];
        });
    }

    /**
     * Reverses (or refunds) a collection: the payment(s) and the finance charges of the receipt are voided and
     * linked to the reversal. The water records are restored by the caller (paymentService). Idempotent.
     */
    public static function reverseCollection(string $receiptNo, string $reason, string $action = 'reversal', ?string $businessDate = null): array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $eventKey = self::collectionKey($receiptNo);
        return DB::tx(function () use ($receiptNo, $reason, $action, $eventKey) {
            $rows = DB::all("SELECT w.*, t.is_voided, t.txn_type AS ttype FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                              WHERE w.source_type IN ('collection', 'collection_penalty', 'collection_compromise') AND w.source_id = :r ORDER BY FIELD(t.txn_type, 'payment', 'finance_charge'), w.id",
                            ['r' => $receiptNo]);
            if (!$rows) {
                fail('Receipt ' . $receiptNo . ' has no accounting document to reverse.');
            }
            $voided = [];
            foreach ($rows as $w) {
                if ($w['status'] !== 'posted' || (int)$w['is_voided'] === 1) {
                    continue;
                }
                Txn::void((int)$w['txn_id'], mb_substr(ucfirst($action) . ' of ' . $receiptNo . ': ' . $reason, 0, 255));
                DB::update('water_postings', ['status' => 'voided', 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $w['id']]);
                $voided[] = (int)$w['txn_id'];
            }
            $first = self::byEvent($eventKey);
            // the reversal itself is an event: it names what it reversed
            $revKey = mb_substr('REV-' . $eventKey, 0, 64);
            if (!self::byEvent($revKey)) {
                self::record(['event_key' => $revKey, 'source_type' => 'collection_' . $action, 'source_id' => $receiptNo, 'doc_no' => $first['doc_no'] ?? $receiptNo,
                    'water_account_id' => $first['water_account_id'] ?? null, 'party_id' => $first['party_id'] ?? null, 'txn_id' => $first['txn_id'] ?? null, 'txn_type' => 'void',
                    'amount' => (float)($first['amount'] ?? 0), 'status' => 'posted', 'reversal_of_id' => $first['id'] ?? null, 'reason' => mb_substr($reason, 0, 255)]);
            }
            return ['journal_head_id' => (string)($first['txn_id'] ?? ''), 'journal_entry_id' => $revKey, 'voided_txn_ids' => $voided];
        });
    }

    // ------------------------------------------------------------------ materials

    /**
     * A material issuance to field work: an inventory adjustment taking the items out of stock at cost, charged to
     * the expense account. $lines: [[item_id, qty, description]]. Idempotent on ISS-<id>.
     */
    public static function postIssuance(int $issuanceId, array $opt): array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $eventKey = 'ISS-' . $issuanceId;
        return DB::tx(function () use ($issuanceId, $opt, $eventKey) {
            if ($existing = self::byEvent($eventKey)) {
                return self::result($existing);
            }
            $expense = self::assertAccount((int)($opt['expense_account_id'] ?? 0) ?: null, 'The expense account of the issuance');
            $site = !empty($opt['site_id']) ? (int)$opt['site_id'] : self::siteId();
            $lines = [];
            foreach ((array)$opt['lines'] as $l) {
                $qty = (float)$l['qty'];
                if ($qty <= 0) {
                    continue;
                }
                $lines[] = ['line_kind' => 'item', 'item_id' => (int)$l['item_id'], 'qty' => -$qty, 'description' => mb_substr((string)($l['description'] ?? ''), 0, 255), 'site_id' => $site, 'rate' => 0, 'amount' => 0, 'tax_amount' => 0, 'value_change' => 0];
            }
            if (!$lines) {
                fail('The issuance has no items.');
            }
            $txnId = Txn::save('inventory_adjustment', [
                'txn_date' => (string)$opt['txn_date'], 'txn_no' => mb_substr((string)($opt['doc_no'] ?? ('ISS-' . $issuanceId)), 0, 30), 'account_id' => $expense, 'site_id' => $site,
                'class_id' => self::classFor($opt), 'business_unit_id' => self::unitId(),
                'memo' => mb_substr((string)($opt['memo'] ?? ('Material issuance ' . ($opt['doc_no'] ?? $issuanceId))), 0, 255), 'ref_no' => mb_substr((string)($opt['doc_no'] ?? ''), 0, 60) ?: null,
            ], $lines, null, ['skip_policy' => true]);
            self::record(['event_key' => $eventKey, 'source_type' => 'issuance', 'source_id' => (string)$issuanceId, 'doc_no' => (string)($opt['doc_no'] ?? $issuanceId),
                'txn_id' => $txnId, 'txn_type' => 'inventory_adjustment', 'amount' => (float)DB::value('SELECT COALESCE(SUM(debit),0) FROM journal_lines WHERE txn_id = :t', ['t' => $txnId]),
                'branch_id' => (int)($opt['branch_id'] ?? 0) ?: null]);
            return self::result(self::byEvent($eventKey));
        });
    }

    public static function reverseIssuance(int $issuanceId, string $reason): array
    {
        $strict = DB::strictScope();      // ledger documents are written under the strict SQL mode
        $own = self::owning();            // the water documents may be changed while this method runs
        $eventKey = 'ISS-' . $issuanceId;
        return DB::tx(function () use ($issuanceId, $reason, $eventKey) {
            $w = self::byEvent($eventKey);
            if (!$w) {
                fail('The issuance has no accounting document to reverse.');
            }
            if ($w['status'] === 'posted') {
                Txn::void((int)$w['txn_id'], mb_substr($reason, 0, 255));
                DB::update('water_postings', ['status' => 'voided', 'reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $w['id']]);
            }
            return ['journal_head_id' => (string)$w['txn_id'], 'journal_entry_id' => 'ISSREV-' . $issuanceId];
        });
    }

    // ------------------------------------------------------------------ checks

    /** Problems with the water account mapping, for the validation report and before posting */
    public static function mappingProblems(): array
    {
        $out = [];
        $check = function (?int $id, string $what) use (&$out) {
            if (!$id) {
                $out[] = ['what' => $what, 'problem' => 'not mapped'];
                return;
            }
            $a = DB::row('SELECT name, is_active FROM accounts WHERE id = :id', ['id' => $id]);
            if (!$a) {
                $out[] = ['what' => $what, 'problem' => 'account #' . $id . ' does not exist'];
            } elseif ((int)$a['is_active'] !== 1) {
                $out[] = ['what' => $what, 'problem' => 'account ' . $a['name'] . ' is inactive'];
            }
        };
        foreach (DB::all('SELECT b.code_id, b.code, b.type, b.ar_account, b.income_account, b.cogs_account, b.asset_account, b.item_id,
                                 ar.account_type AS ar_type, ar.code AS ar_code, ar.name AS ar_name, ia.category AS inc_cat, ia.account_type AS inc_type, ia.code AS inc_code, ia.name AS inc_name
                            FROM bill_codes b LEFT JOIN accounts ar ON ar.id = b.ar_account LEFT JOIN accounts ia ON ia.id = b.income_account ORDER BY b.code') as $bc) {
            $check($bc['ar_account'] ? (int)$bc['ar_account'] : null, 'Bill code ' . $bc['code'] . ': A/R account');
            $check($bc['income_account'] ? (int)$bc['income_account'] : null, 'Bill code ' . $bc['code'] . ': income account');
            if ($bc['ar_account'] && $bc['ar_type'] !== null && $bc['ar_type'] !== 'Accounts Receivable') {
                $out[] = ['what' => 'Bill code ' . $bc['code'] . ': A/R account', 'problem' => $bc['ar_code'] . ' ' . $bc['ar_name'] . ' is a ' . $bc['ar_type'] . ' account, not Accounts Receivable (bills of this code cannot post)'];
            }
            if ($bc['income_account'] && $bc['inc_cat'] !== null && !in_array($bc['inc_cat'], ['Income', 'Liability', 'Equity'], true)) {
                $out[] = ['what' => 'Bill code ' . $bc['code'] . ': income account', 'problem' => $bc['inc_code'] . ' ' . $bc['inc_name'] . ' is a ' . $bc['inc_type'] . ' account; a bill credits income or a deposit liability (bills of this code cannot post)'];
            }
            if (strtolower((string)$bc['type']) === 'inventory') {
                if (!$bc['item_id']) {
                    $out[] = ['what' => 'Bill code ' . $bc['code'], 'problem' => 'inventory bill code with no item of the item master'];
                } elseif (!DB::value('SELECT id FROM items WHERE id = :i AND is_active = 1', ['i' => $bc['item_id']])) {
                    $out[] = ['what' => 'Bill code ' . $bc['code'], 'problem' => 'its item #' . $bc['item_id'] . ' is missing or inactive'];
                }
            }
        }
        foreach (['collection_penalty_account_id' => 'Collection policy: penalty income', 'collection_compromise_account_id' => 'Collection policy: compromise income'] as $k => $label) {
            $v = (int)(DB::value('SELECT setting_value FROM water_billing_settings WHERE setting_key = :k', ['k' => $k]) ?: 0);
            $v = $v ?: self::defaultAccount($k === 'collection_penalty_account_id' ? 'water_penalty' : 'water_compromise');
            $check($v ?: null, $label);
        }
        $check(self::defaultAccount('water_ar') ?: (int)(DB::value("SELECT account_id FROM default_accounts WHERE setting_key = 'ar'") ?: 0), 'Default water A/R account');
        foreach (DB::all("SELECT id, username, full_name, coop_cash_account_id FROM users WHERE status = 'active' AND is_platform = 0") as $u) {
            if ($u['coop_cash_account_id']) {
                $check((int)$u['coop_cash_account_id'], 'Cash account of ' . $u['username']);
            }
        }
        if (!self::unitId()) {
            $out[] = ['what' => 'Water Utility line of business', 'problem' => 'not set up (run the 2026_12_01 migration)'];
        }
        return $out;
    }
}
