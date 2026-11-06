<?php
declare(strict_types=1);

/**
 * Account types (Chart of Accounts > Account Types).
 *
 * The program has fifteen built-in account types (Bank, Accounts Receivable, ... Other Expense): what an account is
 * for the modules (a Bank account is offered where cash is paid or received, an Accounts Receivable account holds the
 * customers' invoices, and so on). The user adds types of his own, such as Cash or Loans Receivable: each one
 * "behaves as" one built-in type (its base) and is linked to a section of the Statement of Cash Flows.
 *
 *  - accounts.account_type stays the base type, so every module works as before with an account of a new type;
 *  - accounts.type_id is the type the user chose (NULL: the built-in type of account_type);
 *  - on the Balance Sheet and the Income Statement every type is a classification of its own (a heading with its
 *    accounts and a total), under the group of its base (Current Assets, Fixed Assets, ... Expenses);
 *  - on the Statement of Cash Flows a type is cash and cash equivalents, or an operating, investing or financing
 *    activity, so every balance sheet account is counted once and the statement ties to the cash accounts.
 *
 * A sub-account is shown under its main account, whose type decides where both are shown.
 */
final class AccountTypes
{
    /** base type => [category, normal balance, statement group, cash flow section by default] */
    public const BASE = [
        'Bank'                    => ['Asset', 'Debit', 'Current Assets', 'cash'],
        'Accounts Receivable'     => ['Asset', 'Debit', 'Current Assets', 'operating'],
        'Other Current Asset'     => ['Asset', 'Debit', 'Current Assets', 'operating'],
        'Fixed Asset'             => ['Asset', 'Debit', 'Fixed Assets', 'investing'],
        'Other Asset'             => ['Asset', 'Debit', 'Other Assets', 'investing'],
        'Accounts Payable'        => ['Liability', 'Credit', 'Current Liabilities', 'operating'],
        'Credit Card'             => ['Liability', 'Credit', 'Current Liabilities', 'operating'],
        'Other Current Liability' => ['Liability', 'Credit', 'Current Liabilities', 'operating'],
        'Long Term Liability'     => ['Liability', 'Credit', 'Long-term Liabilities', 'financing'],
        'Equity'                  => ['Equity', 'Credit', 'Equity', 'financing'],
        'Income'                  => ['Income', 'Credit', 'Income', ''],
        'Other Income'            => ['Income', 'Credit', 'Other Income', ''],
        'Cost of Goods Sold'      => ['Expense', 'Debit', 'Cost of Goods Sold', ''],
        'Expense'                 => ['Expense', 'Debit', 'Expenses', ''],
        'Other Expense'           => ['Expense', 'Debit', 'Other Expenses', ''],
    ];

    /** What the built-in types are for, shown where a type is chosen */
    public const BASE_NOTE = [
        'Bank'                    => 'cash on hand and in banks: offered wherever money is received or paid (deposits, checks, teller cash, the bank registers and reconciliation)',
        'Accounts Receivable'     => 'what customers owe on invoices: aging, statements and receive payments',
        'Other Current Asset'     => 'other assets turned into cash within a year: loans and advances, prepaid expenses, inventory, clearing accounts',
        'Fixed Asset'             => 'property and equipment, and their accumulated depreciation (a credit balance)',
        'Other Asset'             => 'long-term assets that are not property: investments, deposits, intangibles',
        'Accounts Payable'        => 'what is owed to suppliers on bills: aging and pay bills',
        'Credit Card'             => 'credit card balances: charges and payments',
        'Other Current Liability' => 'other debts due within a year: deposits of members, taxes, accruals, payables to others',
        'Long Term Liability'     => 'debts due after a year: loans payable, long-term deposits',
        'Equity'                  => 'capital, share capital, reserves, retained earnings',
        'Income'                  => 'income from the main business: sales, interest on loans, service fees',
        'Other Income'            => 'income outside the main business: gains, miscellaneous income',
        'Cost of Goods Sold'      => 'the cost of what was sold',
        'Expense'                 => 'the costs of running the business',
        'Other Expense'           => 'expenses outside the operations: interest on borrowings, losses',
    ];

    /** The sections of the Statement of Cash Flows a balance sheet type is linked to */
    public const CASH_FLOW = [
        'cash'      => 'Cash and cash equivalents',
        'operating' => 'Operating activities',
        'investing' => 'Investing activities',
        'financing' => 'Financing activities',
    ];

    public const CATEGORIES = ['Asset' => 'Assets', 'Liability' => 'Liabilities', 'Equity' => 'Equity', 'Income' => 'Income', 'Expense' => 'Expenses'];

    private static ?array $all = null;
    private static ?bool $ready = null;

    public static function reset(): void
    {
        self::$all = null;
    }

    /** True once the table exists (upgrade 2027_01_24_account_types.sql) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = (bool)DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'type_id'")
                && (bool)DB::value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'account_types'");
        }
        return self::$ready;
    }

    /** The section of the cash flow statement a base type starts with ('' for income and expense types) */
    public static function defaultCashFlow(string $base): string
    {
        return self::BASE[$base][3] ?? '';
    }

    /** True for a type shown on the balance sheet (and so linked to a cash flow section) */
    public static function isBalanceSheet(string $base): bool
    {
        return in_array(self::BASE[$base][0] ?? '', ['Asset', 'Liability', 'Equity'], true);
    }

    /**
     * Every type, id => row with its category, normal balance and statement group, in the order of the statements: by
     * base type, then the order set on the type, then the name. Before the upgrade: the built-in types (ids < 0).
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $rows = [];
        if (self::ready()) {
            $rows = DB::all('SELECT * FROM account_types');
        }
        if (!$rows) {
            $i = 0;
            foreach (self::BASE as $base => $info) {
                $i++;
                $rows[] = ['id' => -$i, 'name' => $base, 'base_type' => $base, 'cash_flow' => $info[3], 'sort_order' => $i * 10, 'description' => null, 'is_system' => 1, 'is_active' => 1];
            }
        }
        $order = array_flip(array_keys(self::BASE));
        usort($rows, fn($a, $b) => [$order[$a['base_type']] ?? 99, (int)$a['sort_order'], $a['name']] <=> [$order[$b['base_type']] ?? 99, (int)$b['sort_order'], $b['name']]);
        $out = [];
        foreach ($rows as $r) {
            $info = self::BASE[$r['base_type']] ?? ['Asset', 'Debit', 'Current Assets', 'operating'];
            $r['category'] = $info[0];
            $r['normal'] = $info[1];
            $r['group'] = $info[2];
            if (self::isBalanceSheet($r['base_type'])) {
                $r['cash_flow'] = isset(self::CASH_FLOW[$r['cash_flow'] ?? '']) ? $r['cash_flow'] : $info[3];
            } else {
                $r['cash_flow'] = '';
            }
            $out[(int)$r['id']] = $r;
        }
        return self::$all = $out;
    }

    public static function get(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** The built-in type of a base type */
    public static function system(string $base): array
    {
        foreach (self::all() as $t) {
            if ((int)$t['is_system'] === 1 && $t['base_type'] === $base) {
                return $t;
            }
        }
        $info = self::BASE[$base] ?? self::BASE['Other Current Asset'];
        return ['id' => 0, 'name' => $base, 'base_type' => $base, 'cash_flow' => $info[3], 'sort_order' => 0, 'description' => null, 'is_system' => 1, 'is_active' => 1,
            'category' => $info[0], 'normal' => $info[1], 'group' => $info[2]];
    }

    /**
     * The type of an account: the one chosen for it, or (none chosen, or one that no longer fits its base type) the
     * built-in type of its account_type
     */
    public static function forAccount(array $a): array
    {
        $t = !empty($a['type_id']) ? self::get((int)$a['type_id']) : null;
        return $t && $t['base_type'] === $a['account_type'] ? $t : self::system((string)$a['account_type']);
    }

    /** The name an account's type is listed by */
    public static function nameOf(array $a): string
    {
        return (string)self::forAccount($a)['name'];
    }

    /** id => name of the types an account can be given, by category: [category label => [id => name]] */
    public static function grouped(bool $activeOnly = true, ?int $keep = null): array
    {
        $out = [];
        foreach (self::all() as $id => $t) {
            if ($activeOnly && !(int)$t['is_active'] && $id !== $keep) {
                continue;
            }
            $out[self::CATEGORIES[$t['category']]][$id] = $t['name'];
        }
        return $out;
    }

    /** The <option>s of a type picker, grouped by category; each option knows its base type and category */
    public static function optionsHtml(?int $selected, bool $activeOnly = true): string
    {
        $all = self::all();
        $html = '';
        foreach (self::grouped($activeOnly, $selected) as $label => $types) {
            $html .= '<optgroup label="' . h($label) . '">';
            foreach ($types as $id => $name) {
                $t = $all[$id];
                $html .= '<option value="' . (int)$id . '" data-base="' . h($t['base_type']) . '" data-category="' . h($t['category']) . '"'
                    . ((int)$id === (int)$selected ? ' selected' : '') . '>' . h($name) . ((int)$t['is_system'] === 1 ? '' : ' (' . h($t['base_type']) . ')') . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }

    /** A type by its name (any case and spacing), for the CSV import */
    public static function byName(string $name): ?array
    {
        $loose = fn(string $x): string => strtolower(preg_replace('/[\s_\-]+/', '', trim($x)));
        foreach (self::all() as $t) {
            if ($loose($t['name']) === $loose($name)) {
                return $t;
            }
        }
        return null;
    }

    /** Every type's name (the CSV import accepts these) */
    public static function names(): array
    {
        return array_values(array_map(fn($t) => (string)$t['name'], self::all()));
    }

    /** How many accounts use each type: id => count (an account without a type counts for the built-in one) */
    public static function usage(): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM accounts') as $a) {
            $t = self::forAccount($a);
            $out[(int)$t['id']] = ($out[(int)$t['id']] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * The main account an account is shown under on the statements: its parent (a parent of the same category), or
     * itself. $byId: every account by id.
     */
    public static function mainOf(array $a, array $byId): array
    {
        $p = !empty($a['parent_id']) ? ($byId[(int)$a['parent_id']] ?? null) : null;
        return $p && $p['category'] === $a['category'] ? $p : $a;
    }

    /** Where a type is shown, in words: "Balance Sheet: Assets > Current Assets; Statement of Cash Flows: Operating activities" */
    public static function placement(array $t): string
    {
        $cat = self::CATEGORIES[$t['category']];
        if (self::isBalanceSheet($t['base_type'])) {
            return 'Balance Sheet: ' . $cat . ($t['group'] !== $cat ? ' > ' . $t['group'] : '') . '; Statement of Cash Flows: '
                . ($t['cash_flow'] === 'cash' ? 'cash and cash equivalents' : self::CASH_FLOW[$t['cash_flow']] ?? '');
        }
        return 'Income Statement: ' . $t['group'] . '; Statement of Cash Flows: part of the net income';
    }

    // ------------------------------------------------------------------ changes

    /**
     * Saves a type. $d: name, base_type, cash_flow, sort_order, description, is_active. The base type of a type that
     * accounts use cannot change (move the accounts first); a built-in type keeps its name and base type.
     */
    public static function save(array $d, ?int $id, string $pageKey): int
    {
        $old = $id ? DB::row('SELECT * FROM account_types WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            fail('Account type not found.');
        }
        // what is not given stays as it is (a new type is active)
        $d += $old ? array_intersect_key($old, array_flip(['name', 'base_type', 'cash_flow', 'sort_order', 'description', 'is_active'])) : ['is_active' => 1];
        $name = trim(preg_replace('/\s+/', ' ', (string)($d['name'] ?? '')));
        $base = (string)($d['base_type'] ?? '');
        if ($old && (int)$old['is_system'] === 1) {
            $name = (string)$old['name'];
            $base = (string)$old['base_type'];
        }
        if ($name === '') {
            fail('Enter the name of the account type.');
        }
        if (mb_strlen($name) > 60) {
            fail('The name of an account type is at most 60 characters.');
        }
        if (!isset(self::BASE[$base])) {
            fail('Choose the built-in type it behaves as.');
        }
        if (DB::value('SELECT id FROM account_types WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $id ?? 0])) {
            fail('There is already an account type named "' . $name . '".');
        }
        $flow = (string)($d['cash_flow'] ?? '');
        if (self::isBalanceSheet($base)) {
            if (!isset(self::CASH_FLOW[$flow])) {
                fail('Choose the section of the Statement of Cash Flows the accounts of this type belong to.');
            }
        } else {
            $flow = '';
        }
        $active = !empty($d['is_active']) ? 1 : 0;
        if ($old && (int)$old['is_system'] === 1) {
            $active = 1;
        }
        if ($old && $old['base_type'] !== $base) {
            $n = (int)DB::value('SELECT COUNT(*) FROM accounts WHERE type_id = :t', ['t' => $id]);
            if ($n) {
                fail($n . ' account(s) are of this type. Move them to another type first, or keep the type it behaves as (' . $old['base_type'] . ').');
            }
        }
        $row = ['name' => $name, 'base_type' => $base, 'cash_flow' => $flow, 'sort_order' => max(0, min(9999, (int)($d['sort_order'] ?? 0))),
            'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 255) ?: null, 'is_active' => $active];
        if ($old) {
            DB::update('account_types', $row + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Audit::log('edit', 'coa', 'account_type', $id, $name, ['before' => $old, 'after' => $row, 'page_key' => $pageKey]);
        } else {
            $id = DB::insert('account_types', $row + ['is_system' => 0]);
            Audit::log('create', 'coa', 'account_type', $id, $name, ['snapshot' => $row, 'page_key' => $pageKey]);
        }
        self::reset();
        return (int)$id;
    }

    /** Deletes a type of the user's that no account uses */
    public static function delete(int $id, string $pageKey): void
    {
        $t = DB::row('SELECT * FROM account_types WHERE id = :id', ['id' => $id]);
        if (!$t) {
            fail('Account type not found.');
        }
        if ((int)$t['is_system'] === 1) {
            fail('A built-in account type cannot be deleted.');
        }
        $n = (int)DB::value('SELECT COUNT(*) FROM accounts WHERE type_id = :t', ['t' => $id]);
        if ($n) {
            fail($n . ' account(s) are of this type. Move them to another type first.');
        }
        DB::run('DELETE FROM account_types WHERE id = :id', ['id' => $id]);
        Audit::log('delete', 'coa', 'account_type', $id, $t['name'], ['snapshot' => $t, 'page_key' => $pageKey]);
        self::reset();
    }

    /**
     * Why an account cannot take a type, or null. The category stays (a type of another category is refused when the
     * account has transactions, as on the account form); an account the modules post to keeps its built-in base type.
     */
    public static function refusal(array $a, array $t): ?string
    {
        if ($t['category'] !== $a['category']) {
            if ((int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $a['id']]) > 0) {
                return 'The account ' . $a['code'] . ' has transactions; it cannot be moved to another category (' . $a['category'] . ' to ' . $t['category'] . ').';
            }
            if ((int)DB::value('SELECT COUNT(*) FROM accounts WHERE parent_id = :a', ['a' => $a['id']]) > 0) {
                return 'The account ' . $a['code'] . ' has sub-accounts of its category; it cannot be moved to another category.';
            }
            if (!empty($a['parent_id'])) {
                return 'The account ' . $a['code'] . ' is a sub-account; a sub-account keeps the category of its main account.';
            }
        }
        if ((int)$a['is_system'] === 1 && $t['base_type'] !== $a['account_type']) {
            return 'The account ' . $a['code'] . ' is one the modules post to: it can only take a type that behaves as ' . $a['account_type'] . '.';
        }
        return null;
    }

    /**
     * Gives accounts a type (Account Types > the accounts of a type): the type, its base type and category. Returns
     * the number of accounts changed.
     */
    public static function assign(int $typeId, array $accountIds, string $pageKey): int
    {
        $t = self::get($typeId);
        if (!$t || $typeId <= 0) {
            fail('Account type not found.');
        }
        $n = 0;
        DB::tx(function () use ($t, $typeId, $accountIds, $pageKey, &$n) {
            foreach (array_unique(array_map('intval', $accountIds)) as $aid) {
                $a = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $aid]);
                if (!$a) {
                    continue;
                }
                $why = self::refusal($a, $t);
                if ($why !== null) {
                    fail($why);
                }
                if ((int)($a['type_id'] ?? 0) === $typeId && $a['account_type'] === $t['base_type']) {
                    continue;
                }
                $upd = ['type_id' => $typeId, 'account_type' => $t['base_type'], 'category' => $t['category'], 'updated_at' => date('Y-m-d H:i:s')];
                if ($a['category'] !== $t['category']) {
                    $upd['normal_balance'] = $t['normal'];
                }
                DB::update('accounts', $upd, 'id = :id', ['id' => $aid]);
                Audit::log('edit', 'coa', 'account', $aid, $a['code'] . ' ' . $a['name'], ['page_key' => $pageKey,
                    'changes' => [['field' => 'account type', 'old' => self::nameOf($a), 'new' => $t['name']]]]);
                $n++;
            }
        });
        Accts::reset();
        return $n;
    }
}
