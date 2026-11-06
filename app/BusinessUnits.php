<?php
declare(strict_types=1);

/**
 * Lines of business (business units) of a multipurpose cooperative: savings and credit, the consumer store,
 * wholesale / member purchasing, marketing of members' produce, production, services.
 *
 * A document carries one business unit (transactions.business_unit_id) and its ledger lines carry it too
 * (journal_lines.business_unit_id), so the one general ledger can be reported for the whole cooperative or one
 * unit at a time. A unit sets who may transact (members only, nonmembers with approval, or everybody), the price
 * levels of members and nonmembers, and whether its member transactions count for patronage refund.
 */
final class BusinessUnits
{
    public const KINDS = [
        'savings_credit' => 'Savings and credit', 'retail' => 'Consumer store / retail', 'wholesale' => 'Wholesale / member purchasing',
        'marketing' => 'Marketing of members\' produce', 'production' => 'Production / processing', 'service' => 'Services', 'other' => 'Other',
    ];
    public const POLICIES = ['allow' => 'Members and nonmembers', 'approval' => 'Nonmembers need approval', 'members' => 'Members only'];

    /** the modules whose documents take a default unit, and their documents */
    public const MODULES = [
        'coop' => ['Cooperative vouchers (receipts, disbursements, loan releases, journal vouchers, savings transactions)', ['coop_or', 'coop_cv', 'loan_release', 'coop_jv', 'coop_sv']],
        'sales' => ['Sales (estimates, orders, invoices, sales receipts, payments, credit memos, refunds)',
            ['estimate', 'sales_order', 'invoice', 'sales_receipt', 'statement_charge', 'finance_charge', 'payment', 'credit_memo', 'refund']],
        'purchases' => ['Purchasing (purchase orders, receipts, bills, bill payments, vendor credits, advances)',
            ['purchase_order', 'item_receipt', 'bill', 'vendor_credit', 'bill_payment', 'vendor_advance']],
        'pos' => ['Point of sale (sales, returns, over and short)', ['pos_sale', 'pos_return', 'pos_over_short']],
        'inventory' => ['Inventory (adjustments, transfers, builds, processing)', ['inventory_adjustment', 'inventory_transfer', 'build_assembly', 'item_processing']],
        'banking' => ['Banking (checks, deposits, transfers, cash vouchers, bank charges and interest)', ['check', 'deposit', 'transfer', 'cash_voucher', 'bank_charge', 'bank_interest']],
        'general' => ['General journal, depreciation, asset disposals and opening balances', ['journal', 'depreciation', 'asset_disposal', 'opening_balance']],
    ];

    /** modules that can be switched off when the cooperative does not run that line (their reports stay) */
    public const SWITCHABLE = ['pos' => 'Point of Sale', 'inv' => 'Inventory', 'sav' => 'Savings & Deposits', 'loan' => 'Loans', 'teller' => 'Teller', 'fa' => 'Fixed Assets'];

    /** sales documents: the unit's nonmember policy applies to whom they are made out to */
    public const SALES_DOCS = ['invoice', 'sales_receipt', 'sales_order', 'statement_charge', 'pos_sale'];
    /** purchases from producers: the policy of a marketing unit applies to the supplier */
    public const PRODUCE_DOCS = ['item_receipt', 'bill', 'purchase_order'];

    private static ?array $all = null;
    private static ?bool $ready = null;
    private static ?array $interunit = null;

    /** The line of business a report is run for (null = the whole cooperative); set by Report::filters() */
    public static ?int $reportFilter = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(*) FROM business_units WHERE 1 = 0');
                DB::value('SELECT COUNT(business_unit_id) FROM transactions WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function reset(): void
    {
        self::$all = null;
        self::$ready = null;
        self::$interunit = null;
    }

    /** id => row, every unit */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            if (self::ready()) {
                foreach (DB::all('SELECT * FROM business_units ORDER BY sort_order, code') as $r) {
                    self::$all[(int)$r['id']] = $r;
                }
            }
        }
        return self::$all;
    }

    public static function get(?int $id): ?array
    {
        return $id ? (self::all()[$id] ?? null) : null;
    }

    public static function name(?int $id): string
    {
        $u = self::get($id);
        return $u ? $u['code'] . ' ' . $u['name'] : '';
    }

    /** id => "CODE Name" (active units, plus $keep) */
    public static function options(bool $activeOnly = true, ?int $keep = null): array
    {
        $out = [];
        foreach (self::all() as $id => $u) {
            if (!$activeOnly || (int)$u['is_active'] === 1 || $id === $keep) {
                $out[$id] = $u['code'] . ' ' . $u['name'];
            }
        }
        return $out;
    }

    /** Two or more active units: the forms show the unit to choose */
    public static function inUse(): bool
    {
        return count(self::options()) >= 2;
    }

    public static function moduleOf(string $txnType): string
    {
        foreach (self::MODULES as $m => [, $types]) {
            if (in_array($txnType, $types, true)) {
                return $m;
            }
        }
        return 'general';
    }

    /** The unit a new document of this type takes when nothing chooses one */
    public static function defaultFor(string $txnType): ?int
    {
        if (!self::ready()) {
            return null;
        }
        $id = (int)Settings::get('bu_default_' . self::moduleOf($txnType), '');
        if ($id && isset(self::all()[$id]) && (int)self::all()[$id]['is_active'] === 1) {
            return $id;
        }
        // one active unit: everything is that unit
        $active = array_keys(self::options());
        return count($active) === 1 ? (int)$active[0] : null;
    }

    public static function valid($id): ?int
    {
        $id = is_scalar($id) ? (int)$id : 0;
        if ($id <= 0) {
            return null;
        }
        if (!isset(self::all()[$id])) {
            fail('The line of business chosen on this form no longer exists. Choose another.');
        }
        return $id;
    }

    /**
     * The unit drop-down for a document form: the posted value, else the document's own unit, else the default of
     * its type. Nothing is shown while the cooperative has a single active unit.
     */
    public static function field(?array $rec, string $txnType, string $cls = 'col-md-2', string $label = 'Line of business'): string
    {
        if (!self::ready() || !self::inUse()) {
            return '';
        }
        if (is_post() && array_key_exists('business_unit_id', $_POST)) {
            $value = (int)$_POST['business_unit_id'] ?: null;
        } elseif ($rec && !empty($rec['id']) && array_key_exists('business_unit_id', $rec)) {
            $value = $rec['business_unit_id'] ? (int)$rec['business_unit_id'] : null;
        } else {
            $value = self::defaultFor($txnType);
        }
        // the usual site of each unit: picking a unit on a form with an inventory site moves the site to it
        $sites = [];
        foreach (self::all() as $id => $u) {
            if ($s = self::siteFor((int)$id)) {
                $sites[$id] = $s;
            }
        }
        $extra = 'data-no-combo' . ($sites ? " data-unit-sites='" . h(json_encode($sites)) . "'" : '');
        return select_field('business_unit_id', $label, self::options(true, $value), $value, ['blank' => '', 'class' => $cls, 'extra' => $extra]);
    }

    /** The usual inventory site of a unit (when it has one and the site is active), else null */
    public static function siteFor(?int $unitId): ?int
    {
        $u = self::get($unitId);
        $site = $u ? (int)($u['default_site_id'] ?? 0) : 0;
        return $site && isset(Lookup::sites()[$site]) ? $site : null;
    }

    /** The unit of a document being saved: the code's choice, the header's, the form's, the old document's, or the default */
    public static function forSave(string $type, array $h, ?array $old, array $opt): ?int
    {
        if (array_key_exists('business_unit_id', $opt)) {
            return self::valid($opt['business_unit_id']);
        }
        if (array_key_exists('business_unit_id', $h)) {
            return self::valid($h['business_unit_id']);
        }
        if (is_post() && array_key_exists('business_unit_id', $_POST)) {
            return self::valid($_POST['business_unit_id']);
        }
        if ($old) {
            return $old['business_unit_id'] ? (int)$old['business_unit_id'] : null;
        }
        return self::defaultFor($type);
    }

    /**
     * Who may transact in the unit: a members-only unit refuses a sale to a nonmember (or a walk-in) and a unit that
     * needs approval for nonmembers asks for it. A marketing unit applies the same rule to the suppliers of produce.
     */
    public static function checkPolicy(string $type, ?int $unitId, ?int $partyId, string $pageKey): ?array
    {
        $u = self::get($unitId);
        if (!$u || $u['nonmember_policy'] === 'allow') {
            return null;
        }
        $sales = in_array($type, self::SALES_DOCS, true);
        $produce = $u['kind'] === 'marketing' && in_array($type, self::PRODUCE_DOCS, true);
        if (!$sales && !$produce) {
            return null;
        }
        if (Party::isActiveMember($partyId)) {
            return null;
        }
        $who = $partyId ? 'a nonmember' : 'a walk-in customer';
        if ($u['nonmember_policy'] === 'members') {
            fail($u['name'] . ' serves members only, and this ' . strtolower(Txn::label($type)) . ' is made out to ' . $who . '. Choose a member, or record it under another line of business.');
        }
        return Approval::check($pageKey, true, ($sales ? 'sell to ' : 'buy from ') . $who . ' in ' . $u['name']);
    }

    // ------------------------------------------------------------------ reports

    /** SQL condition on a ledger or document alias for the line of business a report is run for */
    public static function sqlFilter(string $alias, string $param): array
    {
        if (!self::$reportFilter || !self::ready()) {
            return ['', []];
        }
        $col = ($alias !== '' ? $alias . '.' : '') . 'business_unit_id';
        return [" AND $col = :$param", [$param => self::$reportFilter]];
    }

    /** Accounts between lines of business (due to / due from, inter-unit sales and charges) */
    public static function interunitIds(): array
    {
        if (self::$interunit === null) {
            try {
                self::$interunit = array_map('intval', DB::column('SELECT id FROM accounts WHERE is_interunit = 1 ORDER BY code'));
            } catch (Throwable $e) {
                self::$interunit = [];
            }
        }
        return self::$interunit;
    }

    /** The whole cooperative is reported and inter-unit accounts exist: their balances are eliminated */
    public static function eliminating(): bool
    {
        return self::ready() && self::$reportFilter === null && self::interunitIds() !== [];
    }

    /**
     * The inter-unit accounts in two groups that are eliminated separately: 'bs' (due to / due from: asset, liability
     * and equity accounts) and 'pl' (inter-unit sales, charges and their cost: income and expense accounts).
     */
    public static function interunitGroups(): array
    {
        $out = ['bs' => [], 'pl' => []];
        $ids = self::interunitIds();
        if (!$ids) {
            return $out;
        }
        [$in, $p] = DB::in('iu', $ids);
        foreach (DB::all("SELECT id, category FROM accounts WHERE id IN $in ORDER BY code", $p) as $a) {
            $out[in_array($a['category'], ['Income', 'Expense'], true) ? 'pl' : 'bs'][] = (int)$a['id'];
        }
        return $out;
    }

    /**
     * Eliminates inter-unit balances from account balances of the whole cooperative (account_id => amount): what the
     * units owe each other nets out, and what they sold or charged each other nets out against its cost. Each group
     * is netted on its own; only a difference that does not net (an entry booked on one side only) stays, on the
     * group's first account, so the statements still balance and show it.
     */
    public static function eliminate(array $bal): array
    {
        if (!self::eliminating()) {
            return $bal;
        }
        foreach (self::interunitGroups() as $ids) {
            if (!$ids) {
                continue;
            }
            $sum = 0.0;
            $first = null;
            foreach ($ids as $id) {
                if (array_key_exists($id, $bal)) {
                    $first ??= $id;
                    $sum += (float)$bal[$id];
                    unset($bal[$id]);
                }
            }
            if ($first !== null && abs($sum) >= 0.005) {
                $bal[$first] = round($sum, 2);
            }
        }
        return $bal;
    }

    /** The same elimination for a table of account_id => [column => amount] (statements with period columns) */
    public static function eliminateColumns(array $by): array
    {
        if (!self::eliminating() || !$by) {
            return $by;
        }
        $keys = [];
        foreach ($by as $cols) {
            $keys += array_flip(array_keys($cols));
        }
        foreach (array_keys($keys) as $k) {
            $col = [];
            foreach ($by as $acct => $cols) {
                if (array_key_exists($k, $cols)) {
                    $col[$acct] = $cols[$k];
                }
            }
            $after = self::eliminate($col);
            foreach ($col as $acct => $_) {
                if (array_key_exists($acct, $after)) {
                    $by[$acct][$k] = $after[$acct];
                } else {
                    unset($by[$acct][$k]);
                }
            }
            foreach ($after as $acct => $v) {
                if (!array_key_exists($acct, $col)) {
                    $by[$acct][$k] = $v;
                }
            }
        }
        return array_filter($by, fn($cols) => $cols !== []);
    }

    /**
     * The line under a report title: the line of business reported, or (whole cooperative) that the balances between
     * lines are eliminated. $eliminates is false on listings that show every entry as booked (ledgers, journals).
     */
    public static function reportNote(bool $eliminates = true): string
    {
        if (self::$reportFilter) {
            return 'Line of business: <strong>' . h(self::name(self::$reportFilter)) . '</strong> only. ';
        }
        if (!self::eliminating()) {
            return '';
        }
        return $eliminates ? 'Whole cooperative; balances and charges between lines of business are eliminated. '
            : 'Whole cooperative; entries between lines of business are listed as booked (they are eliminated only on the financial statements). ';
    }

    /** Settings: the default unit of each module */
    public static function defaults(): array
    {
        $out = [];
        foreach (array_keys(self::MODULES) as $m) {
            $out[$m] = (int)Settings::get('bu_default_' . $m, '') ?: null;
        }
        return $out;
    }

    /** Modules switched off on Lines of Business */
    public static function disabledModules(): array
    {
        try {
            $v = Settings::get('disabled_modules', '');
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_intersect(array_filter(array_map('trim', explode(',', $v))), array_keys(self::SWITCHABLE)));
    }

    /** The price level of a customer in a unit: the customer's own, else the unit's member or nonmember level, else the general one */
    public static function priceLevelFor(?int $customerId, ?int $unitId = null): ?int
    {
        if (!$customerId) {
            $u = self::get($unitId);
            $lvl = $u['nonmember_price_level_id'] ?? null;
            return $lvl ? (int)$lvl : ((int)Settings::get('nonmember_price_level_id', '') ?: null);
        }
        $c = DB::row('SELECT c.price_level_id, COALESCE(c.party_id, p.party_id) AS party_id FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id', ['id' => $customerId]);
        if (!$c) {
            return null;
        }
        if ($c['price_level_id']) {
            return (int)$c['price_level_id'];
        }
        $member = Party::ready() && Party::isActiveMember($c['party_id'] ? (int)$c['party_id'] : null);
        $u = self::get($unitId);
        $lvl = $u ? ($member ? $u['member_price_level_id'] : $u['nonmember_price_level_id']) : null;
        if ($lvl) {
            return (int)$lvl;
        }
        $g = (int)Settings::get($member ? 'member_price_level_id' : 'nonmember_price_level_id', '');
        return $g ?: null;
    }
}
