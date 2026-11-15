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
 *
 * The name of a built-in type can be changed (Fixed Asset to Property and Equipment, Income to Revenue ...): only the
 * name shown changes. What the type is stays its base_type, which the modules and accounts.account_type go by, and its
 * original name stays its own: a file to import that says "Fixed Asset" still means it (label(), byName()).
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
    private static ?array $labels = null;

    public static function reset(): void
    {
        self::$all = null;
        self::$labels = null;
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
     * The name a built-in type is shown by: its name on Account Types, which can be changed (the base type itself, as
     * "Fixed Asset", when it was not, or before the upgrade). For every place that shows what an account behaves as.
     */
    public static function label(string $base): string
    {
        if (self::$labels === null) {
            self::$labels = [];
            foreach (self::all() as $t) {
                if ((int)$t['is_system'] === 1 && !isset(self::$labels[$t['base_type']])) {
                    self::$labels[$t['base_type']] = (string)$t['name'];
                }
            }
        }
        return self::$labels[$base] ?? $base;
    }

    /** True for a built-in type whose name was changed (its name is no longer that of its base type) */
    public static function renamed(array $t): bool
    {
        return (int)($t['is_system'] ?? 0) === 1 && (string)$t['name'] !== (string)$t['base_type'];
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

    /**
     * The <option>s of a type picker, grouped by category; each option knows its base type and category. $bases: only
     * the types that behave as these built-in types (the "Add new" window of a bank account box, v60)
     */
    public static function optionsHtml(?int $selected, bool $activeOnly = true, ?array $bases = null): string
    {
        $all = self::all();
        $html = '';
        foreach (self::grouped($activeOnly, $selected) as $label => $types) {
            if ($bases !== null) {
                $types = array_filter($types, fn($n, $id) => in_array($all[$id]['base_type'], $bases, true), ARRAY_FILTER_USE_BOTH);
                if (!$types) {
                    continue;
                }
            }
            $html .= '<optgroup label="' . h($label) . '">';
            foreach ($types as $id => $name) {
                $t = $all[$id];
                $html .= '<option value="' . (int)$id . '" data-base="' . h($t['base_type']) . '" data-category="' . h($t['category']) . '"'
                    . ((int)$id === (int)$selected ? ' selected' : '') . '>' . h($name) . ((int)$t['is_system'] === 1 ? '' : ' (' . h(self::label($t['base_type'])) . ')') . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html;
    }

    /** A name compared loosely: any case, without spaces, underscores and dashes */
    private static function loose(string $x): string
    {
        return strtolower((string)preg_replace('/[\s_\-]+/', '', trim($x)));
    }

    /**
     * A type by its name (any case and spacing), for the CSV import; also a built-in type by its original name ("Fixed
     * Asset" after it was renamed Property and Equipment), so the files of before still read
     */
    public static function byName(string $name): ?array
    {
        $want = self::loose($name);
        if ($want === '') {
            return null;
        }
        foreach (self::all() as $t) {
            if (self::loose((string)$t['name']) === $want) {
                return $t;
            }
        }
        foreach (array_keys(self::BASE) as $base) {
            if (self::loose($base) === $want) {
                $t = self::system($base);
                return isset(self::all()[(int)$t['id']]) ? $t : null;
            }
        }
        return null;
    }

    /** Every type's name, and the original name of a built-in type that was renamed (the CSV import accepts these) */
    public static function names(): array
    {
        $out = array_values(array_map(fn($t) => (string)$t['name'], self::all()));
        foreach (self::all() as $t) {
            if (self::renamed($t) && !in_array($t['base_type'], $out, true)) {
                $out[] = (string)$t['base_type'];
            }
        }
        return $out;
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
        $group = self::partName($t);
        if (self::isBalanceSheet($t['base_type'])) {
            return 'Balance Sheet: ' . $cat . ($group !== $cat ? ' > ' . $group : '') . '; Statement of Cash Flows: '
                . ($t['cash_flow'] === 'cash' ? 'cash and cash equivalents' : self::CASH_FLOW[$t['cash_flow']] ?? '');
        }
        return 'Income Statement: ' . $group . '; Statement of Cash Flows: part of the net income';
    }

    /**
     * True when a type is the only one of its part of the statements: the only built-in type of its group (Fixed
     * Assets, Equity, Income, Expenses ... not Current Assets or Current Liabilities) and no other type behaves as it
     */
    public static function alone(array $t): bool
    {
        $group = self::BASE[$t['base_type']][2] ?? '';
        if (count(array_filter(self::BASE, fn($b) => $b[2] === $group)) !== 1) {
            return false;
        }
        foreach (self::all() as $o) {
            if ((int)$o['id'] !== (int)$t['id'] && $o['base_type'] === $t['base_type']) {
                return false;
            }
        }
        return true;
    }

    /**
     * The heading of the part of the statements a type is shown in: its group (Current Assets, Fixed Assets ...), or
     * the new name of a renamed built-in type alone in its part (Property and Equipment in place of Fixed Assets), as
     * the statements show it (fin_part_name())
     */
    public static function partName(array $t): string
    {
        return self::renamed($t) && self::alone($t) ? (string)$t['name'] : (string)$t['group'];
    }

    // ------------------------------------------------------------------ changes

    /**
     * Saves a type. $d: name, base_type, cash_flow, sort_order, description, is_active. The base type of a type that
     * accounts use cannot change (move the accounts first); a built-in type keeps its base type and stays active, its
     * name can be changed. The original name of a built-in type stays its own: no other type takes it.
     */
    public static function save(array $d, ?int $id, string $pageKey): int
    {
        $old = $id ? DB::row('SELECT * FROM account_types WHERE id = :id', ['id' => $id]) : null;
        if ($id && !$old) {
            fail('Account type not found.');
        }
        $isSystem = $old && (int)$old['is_system'] === 1;
        // what is not given stays as it is (a new type is active)
        $d += $old ? array_intersect_key($old, array_flip(['name', 'base_type', 'cash_flow', 'sort_order', 'description', 'is_active'])) : ['is_active' => 1];
        $name = trim(preg_replace('/\s+/', ' ', (string)($d['name'] ?? '')));
        $base = (string)($d['base_type'] ?? '');
        if ($isSystem) {
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
        if (!$old || self::loose($name) !== self::loose((string)$old['name'])) {
            foreach (array_keys(self::BASE) as $b) {
                if (self::loose($b) === self::loose($name) && !($isSystem && $b === $base)) {
                    fail(self::label($b) === $b ? 'There is already an account type named "' . $b . '".'
                        : '"' . $name . '" is the original name of the built-in type now named ' . self::label($b) . ', and still means it in the files to import. Choose another name.');
                }
            }
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
        if ($isSystem) {
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
            return 'The account ' . $a['code'] . ' is one the modules post to: it can only take a type that behaves as ' . self::label((string)$a['account_type']) . '.';
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
