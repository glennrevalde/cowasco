<?php
declare(strict_types=1);

/**
 * Financial > Chart of Accounts > Import (version 57): the accounts of a chart of accounts from a CSV file or an Excel
 * workbook, shown first in a review form (CsvReview), where a line with a problem is in red with its reason, any cell
 * can be corrected and any line removed; saved only when no line is in red, every line or none.
 *
 * One line per account: its code, name and type are needed; the main account it is under (Sub-account of), the normal
 * balance, With SL, Active, the description and the bank account no. are optional. The columns may be in any order and
 * named as on the Export of the chart (Code, Name, Type, Normal balance, Parent, With SL, Active) or in other usual
 * words (Account Code, Account Title, Account Type, Parent Code ...). An account whose code is already on the chart is
 * updated from its line (the blank cells keep what is saved), or left as it is, as the user chooses. The rules are
 * those of the account form: the code unique, a type of Account Types that is switched on, a sub-account under a
 * main account of its category (one level), an account the modules post to keeps its base type and stays active, an
 * account with transactions or sub-accounts keeps its category.
 */
final class CoaImport
{
    public const PAGE = 'coa.accounts';
    public const EXISTING = [
        'update' => 'Update it from the file (the blank cells keep what is saved)',
        'skip' => 'Leave it as it is (the line is not saved)',
    ];

    /** Other usual names of a type ("current asset", "revenue") => the built-in type */
    private const TYPE_WORDS = [
        'cash' => 'Bank', 'cashinbank' => 'Bank', 'cashandcashequivalents' => 'Bank', 'bankaccount' => 'Bank',
        'receivable' => 'Accounts Receivable', 'receivables' => 'Accounts Receivable', 'accountreceivable' => 'Accounts Receivable', 'ar' => 'Accounts Receivable',
        'currentasset' => 'Other Current Asset', 'currentassets' => 'Other Current Asset', 'othercurrentassets' => 'Other Current Asset',
        'fixedassets' => 'Fixed Asset', 'propertyplantandequipment' => 'Fixed Asset', 'ppe' => 'Fixed Asset',
        'noncurrentasset' => 'Other Asset', 'noncurrentassets' => 'Other Asset', 'otherassets' => 'Other Asset',
        'payable' => 'Accounts Payable', 'payables' => 'Accounts Payable', 'accountpayable' => 'Accounts Payable', 'ap' => 'Accounts Payable',
        'currentliability' => 'Other Current Liability', 'currentliabilities' => 'Other Current Liability', 'othercurrentliabilities' => 'Other Current Liability',
        'longtermliabilities' => 'Long Term Liability', 'noncurrentliability' => 'Long Term Liability', 'noncurrentliabilities' => 'Long Term Liability',
        'capital' => 'Equity', 'ownersequity' => 'Equity', 'membersequity' => 'Equity', 'networth' => 'Equity',
        'revenue' => 'Income', 'revenues' => 'Income', 'sales' => 'Income', 'otherrevenue' => 'Other Income', 'otherrevenues' => 'Other Income',
        'costofsales' => 'Cost of Goods Sold', 'cogs' => 'Cost of Goods Sold', 'costofgoodssold' => 'Cost of Goods Sold',
        'expenses' => 'Expense', 'operatingexpense' => 'Expense', 'operatingexpenses' => 'Expense', 'otherexpenses' => 'Other Expense',
    ];

    /** The columns of the file (CsvReview) */
    public static function cols(): array
    {
        $types = [];
        foreach (AccountTypes::all() as $t) {
            if ((int)$t['is_active']) {
                $types[(string)$t['name']] = (string)$t['name'] . ((int)$t['is_system'] === 1 ? '' : ' (' . $t['base_type'] . ')');
            }
        }
        $yesNo = ['Yes' => 'Yes', 'No' => 'No'];
        return [
            'code' => ['label' => 'Code', 'req' => true, 'size' => 'sm', 'hint' => 'The account code, used by no other account (at most 20 characters): 1000, 1010-01.'],
            'name' => ['label' => 'Name', 'req' => true, 'size' => 'lg', 'hint' => 'The name of the account (at most 120 characters).'],
            'type' => ['label' => 'Type', 'req' => true, 'type' => 'select', 'options' => $types, 'size' => 'lg',
                'hint' => 'As Financial > Account Types lists it: Bank, Accounts Receivable, Other Current Asset, Fixed Asset, Accounts Payable, Equity, Income, Expense ... or a type of your own. Usual other names are read too (Current Asset, Revenue, Cost of Sales).'],
            'parent' => ['label' => 'Sub-account of', 'size' => 'sm', 'hint' => 'The code of the main account it is under (on the chart or in the file, of the same category); blank for a main account.'],
            'normal_balance' => ['label' => 'Normal balance', 'type' => 'select', 'options' => ['Debit' => 'Debit', 'Credit' => 'Credit'], 'size' => 'sm',
                'hint' => 'Debit or Credit; blank: the normal balance of the type (a contra account, such as accumulated depreciation, takes the other one).'],
            'with_sl' => ['label' => 'With SL', 'type' => 'select', 'options' => $yesNo, 'size' => 'xs', 'hint' => 'Yes when the account keeps a subsidiary ledger of names; blank: No.'],
            'is_active' => ['label' => 'Active', 'type' => 'select', 'options' => $yesNo, 'size' => 'xs', 'hint' => 'Yes or No; blank: Yes.'],
            'description' => ['label' => 'Description', 'more' => true, 'hint' => 'Optional (at most 255 characters).'],
            'bank_account_no' => ['label' => 'Bank account no.', 'more' => true, 'hint' => 'Optional, for a bank account (at most 40 characters).'],
        ];
    }

    /** Other names of the columns (CsvReview::headerKey of the heading => the column) */
    public static function aliases(): array
    {
        return [
            'account_code' => 'code', 'acct_code' => 'code', 'code_no' => 'code', 'account_no' => 'code', 'account_number' => 'code', 'gl_code' => 'code', 'gl_account' => 'code',
            'account_name' => 'name', 'account_title' => 'name', 'title' => 'name', 'acct_name' => 'name', 'gl_name' => 'name', 'account' => 'name',
            'account_type' => 'type', 'type_name' => 'type', 'acct_type' => 'type', 'classification' => 'type',
            'parent_code' => 'parent', 'parent_account' => 'parent', 'sub_account_of' => 'parent', 'subaccount_of' => 'parent', 'main_account' => 'parent', 'under' => 'parent',
            'normal' => 'normal_balance', 'nature' => 'normal_balance', 'debit_credit' => 'normal_balance', 'dr_cr' => 'normal_balance',
            'with_subsidiary_ledger' => 'with_sl', 'sl' => 'with_sl', 'subsidiary_ledger' => 'with_sl',
            'active' => 'is_active', 'status' => 'is_active',
            'bank_account' => 'bank_account_no', 'bank_account_number' => 'bank_account_no', 'bank_acct_no' => 'bank_account_no',
            'remarks' => 'description', 'notes' => 'description', 'details' => 'description',
        ];
    }

    /** The rows of the template */
    public static function samples(): array
    {
        $row = fn(string $code, string $name, string $type, string $parent = '', string $normal = '', string $sl = 'No', string $desc = '', string $bank = '') =>
            ['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent, 'normal_balance' => $normal, 'with_sl' => $sl, 'is_active' => 'Yes', 'description' => $desc, 'bank_account_no' => $bank];
        return [
            $row('1000', 'Cash and Cash Equivalents', 'Bank', '', '', 'No', 'Main account of the cash accounts'),
            $row('1001', 'Cash on Hand', 'Bank', '1000'),
            $row('1010', 'Cash in Bank - Land Bank', 'Bank', '1000', '', 'No', '', '0012-3456-78'),
            $row('1150', 'Allowance for Doubtful Accounts', 'Other Current Asset', '', 'Credit', 'No', 'A contra account: its normal balance is Credit'),
            $row('2100', 'Accounts Payable - Trade', 'Accounts Payable', '', '', 'Yes'),
            $row('4100', 'Water Sales', 'Income'),
            $row('6100', 'Salaries and Wages', 'Expense'),
        ];
    }

    // ================================================================================== reading the cells

    private static function loose(string $x): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $x));
    }

    /** The type a cell names (a type of Account Types, or another usual name of a built-in type), or null */
    public static function typeOf(string $v): ?array
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $t = AccountTypes::byName($v);
        if ($t) {
            return $t;
        }
        $base = self::TYPE_WORDS[self::loose($v)] ?? null;
        return $base ? AccountTypes::system($base) : null;
    }

    /** "Yes"/"No" of a cell (yes, y, true, 1, x, active / no, n, false, 0, inactive), '' when blank, null when neither */
    private static function yesNo(string $v): ?string
    {
        $l = strtolower(trim($v));
        if ($l === '') {
            return '';
        }
        if (in_array($l, ['yes', 'y', 'true', '1', 'x', '✓', 'active', 'on'], true)) {
            return 'Yes';
        }
        if (in_array($l, ['no', 'n', 'false', '0', 'inactive', 'off'], true)) {
            return 'No';
        }
        return null;
    }

    /** "Debit"/"Credit" of a cell (debit, dr, d / credit, cr, c), '' when blank, null when neither */
    private static function normalOf(string $v): ?string
    {
        $l = strtolower(trim($v, " \t."));
        if ($l === '') {
            return '';
        }
        if (in_array($l, ['debit', 'dr', 'd', 'debit balance'], true)) {
            return 'Debit';
        }
        if (in_array($l, ['credit', 'cr', 'c', 'credit balance'], true)) {
            return 'Credit';
        }
        return null;
    }

    // ================================================================================== the check

    /**
     * Checks the rows as the review shows them: [rows (with _errors, _notes, _info, _cells and the cells written as
     * the program writes them), summary (rows, bad, new, update, same, skip), general problems].
     */
    public static function check(array $rows, array $opt): array
    {
        $mode = isset(self::EXISTING[$opt['existing'] ?? '']) ? (string)$opt['existing'] : 'update';
        $general = [];
        $onFile = [];
        foreach (DB::all('SELECT a.*, (SELECT COUNT(*) FROM accounts s WHERE s.parent_id = a.id) AS subs FROM accounts a') as $a) {
            $onFile[mb_strtolower((string)$a['code'])] = $a;
        }
        $byIdCode = [];
        foreach ($onFile as $a) {
            $byIdCode[(int)$a['id']] = (string)$a['code'];
        }
        $nameOnFile = [];
        foreach ($onFile as $a) {
            $nameOnFile[mb_strtolower(trim((string)$a['name']))][] = (string)$a['code'];
        }
        $usedCache = [];
        $used = function (int $id) use (&$usedCache): bool {
            return $usedCache[$id] ??= (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $id]) > 0;
        };

        // 1. each line on its own: the cells read, written as the program writes them
        $codeLines = [];
        $nameLines = [];
        foreach ($rows as $i => &$r) {
            $r['_errors'] = [];
            $r['_notes'] = [];
            $r['_cells'] = [];
            $r['_info'] = '';
            $err = function (string $msg, array $cells) use (&$r): void {
                $r['_errors'][] = $msg;
                $r['_cells'][count($r['_errors']) - 1] = $cells;
            };
            foreach (['code', 'name', 'type', 'parent', 'normal_balance', 'with_sl', 'is_active', 'description', 'bank_account_no'] as $k) {
                $r[$k] = trim((string)preg_replace('/\s+/u', ' ', (string)($r[$k] ?? '')));
            }
            if ($r['code'] === '') {
                $err('Enter the account code.', ['code']);
            } elseif (mb_strlen($r['code']) > 20) {
                $err('The code is longer than 20 characters.', ['code']);
            } else {
                $codeLines[mb_strtolower($r['code'])][] = $i;
            }
            if ($r['name'] === '') {
                $err('Enter the account name.', ['name']);
            } elseif (mb_strlen($r['name']) > 120) {
                $err('The name is longer than 120 characters.', ['name']);
            } else {
                $nameLines[mb_strtolower($r['name'])][] = $i;
            }
            $t = null;
            if ($r['type'] === '' && trim((string)($r['behaves_as'] ?? '')) !== '') {
                // the Export of the chart: a type of another company's Account Types, behaving as a built-in one
                $r['type'] = trim((string)$r['behaves_as']);
            }
            if ($r['type'] === '') {
                $err('Choose the account type.', ['type']);
            } else {
                $t = self::typeOf($r['type']);
                if (!$t) {
                    $err('"' . $r['type'] . '" is not an account type. Choose one of Account Types (Bank, Accounts Receivable, Other Current Asset, Fixed Asset, Expense ...).', ['type']);
                } else {
                    if ($t['name'] !== $r['type']) {
                        $r['_notes'][] = 'Type "' . $r['type'] . '" read as ' . $t['name'] . '.';
                    }
                    $r['type'] = (string)$t['name'];
                }
            }
            $r['_type'] = $t;
            $nb = self::normalOf($r['normal_balance']);
            if ($nb === null) {
                $err('The normal balance is Debit or Credit (or blank for the normal balance of the type).', ['normal_balance']);
            } else {
                $r['normal_balance'] = $nb;
            }
            foreach (['with_sl' => 'With SL', 'is_active' => 'Active'] as $k => $label) {
                $yn = self::yesNo($r[$k]);
                if ($yn === null) {
                    $err($label . ' is Yes or No.', [$k]);
                } else {
                    $r[$k] = $yn;
                }
            }
            if (mb_strlen($r['description']) > 255) {
                $err('The description is longer than 255 characters.', ['description']);
            }
            if (mb_strlen($r['bank_account_no']) > 40) {
                $err('The bank account no. is longer than 40 characters.', ['bank_account_no']);
            }
        }
        unset($r);

        // 2. the same code twice in the file
        foreach ($codeLines as $code => $idx) {
            if (count($idx) > 1) {
                $lines = array_map(fn($i) => (int)$rows[$i]['_line'], $idx);
                foreach ($idx as $i) {
                    $rows[$i]['_errors'][] = 'The code ' . $rows[$i]['code'] . ' is on ' . count($idx) . ' lines (' . implode(', ', $lines) . '): correct or remove all but one.';
                    $rows[$i]['_cells'][count($rows[$i]['_errors']) - 1] = ['code'];
                }
            }
        }

        // 3. each account as it will be: the line, or the chart for an account the file does not change
        $final = [];      // lower code => ['line' => index|null, 'category', 'parent' => lower code|'', 'subs' => bool]
        foreach ($onFile as $lc => $a) {
            $final[$lc] = ['line' => null, 'category' => (string)$a['category'], 'parent' => $a['parent_id'] ? mb_strtolower($byIdCode[(int)$a['parent_id']] ?? '') : '', 'code' => (string)$a['code']];
        }
        $fileCodes = [];
        foreach ($rows as $i => $r) {
            $lc = mb_strtolower($r['code']);
            if ($r['code'] === '' || count($codeLines[$lc] ?? []) > 1) {
                continue;
            }
            $fileCodes[$lc] = $i;
            $acc = $onFile[$lc] ?? null;
            if ($acc && $mode === 'skip') {
                continue;   // the chart keeps it as it is
            }
            $final[$lc] = ['line' => $i, 'category' => $r['_type'] ? (string)$r['_type']['category'] : ($acc['category'] ?? ''), 'parent' => $final[$lc]['parent'] ?? '', 'code' => $r['code']];
        }
        // the main account a line names: a code on the chart or in the file, else a name (only one account with it)
        $resolve = function (string $v) use ($final, $nameOnFile, $nameLines, $rows, $codeLines): array {
            $lc = mb_strtolower($v);
            if (isset($final[$lc])) {
                return [$lc, null];
            }
            if (count($codeLines[$lc] ?? []) > 1) {
                return [null, 'The code ' . $v . ' is on more than one line of the file: correct those lines first.'];
            }
            $codes = array_merge($nameOnFile[$lc] ?? [], array_map(fn($i) => $rows[$i]['code'], $nameLines[$lc] ?? []));
            $codes = array_values(array_unique(array_map('mb_strtolower', $codes)));
            if (count($codes) === 1 && isset($final[$codes[0]])) {
                return [$codes[0], null];
            }
            return [null, count($codes) > 1 ? 'More than one account is named "' . $v . '": write the code of the main account.' : 'No account on the chart or in the file has the code "' . $v . '".'];
        };
        foreach ($rows as $i => $r) {
            $lc = mb_strtolower($r['code']);
            if (($final[$lc]['line'] ?? null) !== $i) {
                continue;
            }
            if ($r['parent'] === '') {
                // blank: a main account, or (an account on the chart that is updated) the main account it has
                if (!isset($onFile[$lc])) {
                    $final[$lc]['parent'] = '';
                }
                continue;
            }
            [$p, $why] = $resolve($r['parent']);
            if ($p === null) {
                $rows[$i]['_errors'][] = $why;
                $rows[$i]['_cells'][count($rows[$i]['_errors']) - 1] = ['parent'];
                $final[$lc]['parent'] = '?';
                continue;
            }
            $final[$lc]['parent'] = $p;
            $rows[$i]['parent'] = $final[$p]['code'];
        }
        $hasSubs = [];
        foreach ($final as $lc => $x) {
            if ($x['parent'] !== '' && $x['parent'] !== '?') {
                $hasSubs[$x['parent']] = true;
            }
        }

        // 4. each line against the chart and the other lines
        $sum = ['rows' => count($rows), 'bad' => 0, 'new' => 0, 'update' => 0, 'same' => 0, 'skip' => 0];
        foreach ($rows as $i => &$r) {
            $err = function (string $msg, array $cells) use (&$r): void {
                $r['_errors'][] = $msg;
                $r['_cells'][count($r['_errors']) - 1] = $cells;
            };
            $lc = mb_strtolower($r['code']);
            $acc = $r['code'] !== '' ? ($onFile[$lc] ?? null) : null;
            $t = $r['_type'];
            if ($acc && $mode === 'skip') {
                $r['_info'] = 'On the chart: <strong>' . h($acc['code'] . ' ' . $acc['name']) . '</strong>. Left as it is (this line is not saved).';
            }
            $mine = ($final[$lc]['line'] ?? null) === $i;
            if ($mine && $t) {
                if (!(int)$t['is_active'] && !($acc && (int)AccountTypes::forAccount($acc)['id'] === (int)$t['id'])) {
                    $err('The account type ' . $t['name'] . ' is switched off (Financial > Account Types).', ['type']);
                }
                $p = $final[$lc]['parent'];
                if ($p !== '' && $p !== '?') {
                    if ($p === $lc) {
                        $err('An account cannot be a sub-account of itself.', ['parent']);
                    } elseif ($final[$p]['parent'] === '?') {
                        // the main account's own line names a main account that cannot be found: said on that line
                    } elseif ($final[$p]['parent'] !== '') {
                        $err('The account ' . $final[$p]['code'] . ' is itself a sub-account: a sub-account is under a main account.', ['parent']);
                    } elseif ($final[$p]['category'] !== '' && $final[$p]['category'] !== $t['category']) {
                        $err('A sub-account is under a main account of its category: ' . $final[$p]['code'] . ' is ' . $final[$p]['category'] . ', this account ' . $t['category'] . '.', ['parent', 'type']);
                    } elseif (isset($hasSubs[$lc])) {
                        $err('The account ' . $r['code'] . ' has sub-accounts of its own: it cannot be a sub-account too.', ['parent']);
                    }
                }
                if ($acc) {
                    // the rules of the account form for an account on the chart
                    if ((int)$acc['is_system'] === 1 && $acc['account_type'] !== $t['base_type']) {
                        $err('The account ' . $acc['code'] . ' is one the modules post to: it keeps a type that behaves as ' . $acc['account_type'] . '.', ['type']);
                    } elseif ($acc['category'] !== $t['category']) {
                        if ($used((int)$acc['id'])) {
                            $err('The account ' . $acc['code'] . ' has transactions: it cannot be moved to another category (' . $acc['category'] . ' to ' . $t['category'] . ').', ['type']);
                        } elseif ((int)$acc['subs'] > 0) {
                            $err('The account ' . $acc['code'] . ' has sub-accounts of its category: it cannot be moved to another category.', ['type']);
                        }
                    }
                    if ((int)$acc['is_system'] === 1 && $r['is_active'] === 'No') {
                        $err('The account ' . $acc['code'] . ' is one the modules post to: it stays active.', ['is_active']);
                    }
                }
            }
            if (!empty($r['_errors'])) {
                $sum['bad']++;
                continue;
            }
            if ($acc && $mode === 'skip') {
                $sum['skip']++;
                continue;
            }
            $d = self::record($r, $acc, $final, $onFile);
            if ($acc) {
                $label = ['account_type' => 'type', 'type_id' => 'type', 'name' => 'name', 'normal_balance' => 'normal balance', 'parent_id' => 'main account',
                    'description' => 'description', 'bank_account_no' => 'bank account no.', 'with_sl' => 'With SL', 'is_active' => 'active'];
                $changes = [];
                foreach (self::changes($acc, $d) as $c) {
                    if (isset($label[$c['field']])) {
                        $changes[$label[$c['field']]] = $label[$c['field']];
                    }
                }
                if ($changes) {
                    $sum['update']++;
                    $r['_counted_update'] = 1;
                    $r['_info'] = 'On the chart as <strong>' . h($acc['code'] . ' ' . $acc['name']) . '</strong>: its ' . h(implode(', ', $changes)) . ' will be updated.';
                } else {
                    $sum['same']++;
                    $r['_counted_same'] = 1;
                    $r['_info'] = 'On the chart as <strong>' . h($acc['code'] . ' ' . $acc['name']) . '</strong>: the same, nothing to change.';
                }
            } else {
                $sum['new']++;
                $r['_counted_new'] = 1;
            }
            if (isset($nameLines[mb_strtolower($r['name'])]) && count($nameLines[mb_strtolower($r['name'])]) > 1) {
                $r['_notes'][] = 'Another line of the file has the name ' . $r['name'] . '.';
            } elseif (!$acc && isset($nameOnFile[mb_strtolower($r['name'])])) {
                $r['_notes'][] = 'The chart has an account of this name: ' . implode(', ', $nameOnFile[mb_strtolower($r['name'])]) . '.';
            }
        }
        unset($r);
        // a sub-account whose main account is a line of the file with a problem waits for it
        foreach ($rows as $i => &$r) {
            $lc = mb_strtolower($r['code']);
            $p = ($final[$lc]['line'] ?? null) === $i ? $final[$lc]['parent'] : '';
            if ($p === '' || $p === '?' || !isset($fileCodes[$p]) || ($final[$p]['line'] ?? null) === null || empty($rows[$fileCodes[$p]]['_errors']) || !empty($r['_errors'])) {
                continue;
            }
            $r['_errors'][] = 'The main account ' . $final[$p]['code'] . ' (line ' . (int)$rows[$fileCodes[$p]]['_line'] . ') has a problem: correct it first.';
            $r['_cells'][count($r['_errors']) - 1] = ['parent'];
            $r['_info'] = '';
            $sum['bad']++;
            foreach (['new', 'update', 'same'] as $k) {
                if (!empty($r['_counted_' . $k])) {
                    $sum[$k]--;
                }
            }
        }
        unset($r);
        foreach ($rows as &$r) {
            unset($r['_type'], $r['_counted_new'], $r['_counted_update'], $r['_counted_same']);
        }
        unset($r);
        if (!AccountTypes::all()) {
            $general[] = 'The account types are not set up (Financial > Account Types).';
        }
        return [$rows, $sum, $general];
    }

    /**
     * What a line changes on an account of the chart: [['field', 'old', 'new'], ...]. The type is compared as the
     * program shows it: an account saved before Account Types (no type_id) has the built-in type of its account_type.
     */
    private static function changes(array $acc, array $d): array
    {
        $out = [];
        $sameType = $acc['account_type'] === $d['account_type']
            && (int)AccountTypes::forAccount($acc)['id'] === (int)($d['type_id'] ?? AccountTypes::system((string)$d['account_type'])['id']);
        foreach ($d as $k => $v) {
            if (($k === 'type_id' || $k === 'account_type' || $k === 'category') && $sameType) {
                continue;
            }
            if ((string)($acc[$k] ?? '') !== (string)($v ?? '')) {
                $out[] = ['field' => $k, 'old' => $acc[$k] ?? null, 'new' => $v];
            }
        }
        return $out;
    }

    /** The columns of the accounts table a checked line writes ($acc: the account on the chart it updates) */
    private static function record(array $r, ?array $acc, array $final, array $onFile): array
    {
        $t = $r['_type'];
        $lc = mb_strtolower($r['code']);
        $p = $final[$lc]['parent'] ?? '';
        $parentId = $p !== '' && $p !== '?' && isset($onFile[$p]) ? (int)$onFile[$p]['id'] : null;
        $d = [
            'code' => $acc ? (string)$acc['code'] : mb_substr($r['code'], 0, 20),
            'name' => mb_substr($r['name'], 0, 120),
            'account_type' => (string)$t['base_type'],
            'category' => (string)$t['category'],
            'normal_balance' => $r['normal_balance'] !== '' ? $r['normal_balance']
                : ($acc && $acc['account_type'] === $t['base_type'] ? (string)$acc['normal_balance'] : (string)$t['normal']),
            // a main account in the file not saved yet: set when it is (post())
            'parent_id' => $p === '' ? null : $parentId,
            'description' => $r['description'] !== '' ? $r['description'] : ($acc['description'] ?? null),
            'bank_account_no' => $r['bank_account_no'] !== '' ? $r['bank_account_no'] : ($acc['bank_account_no'] ?? null),
            'with_sl' => $r['with_sl'] !== '' ? ($r['with_sl'] === 'Yes' ? 1 : 0) : (int)($acc['with_sl'] ?? 0),
            'is_active' => $r['is_active'] !== '' ? ($r['is_active'] === 'Yes' ? 1 : 0) : (int)($acc['is_active'] ?? 1),
        ];
        if (AccountTypes::ready()) {
            $d['type_id'] = (int)$t['id'] > 0 ? (int)$t['id'] : null;
        }
        return $d;
    }

    // ================================================================================== saving

    /**
     * Saves the checked lines, every one or none: the new accounts added (the main accounts first, then their
     * sub-accounts), the accounts on the chart updated or left as they are. Returns [added, updated, same, skipped, codes].
     */
    public static function post(array $rows, array $opt, string $fileName, string $pageKey): array
    {
        return DB::tx(function () use ($rows, $opt, $fileName, $pageKey) {
            [$checked, $sum, $general] = self::check($rows, $opt);
            if ($general) {
                fail(implode(' ', $general));
            }
            if (!$checked) {
                fail('There is no account to save.');
            }
            if ($sum['bad']) {
                fail($sum['bad'] . ' line(s) have a problem: correct them or remove them, then save.');
            }
            $mode = isset(self::EXISTING[$opt['existing'] ?? '']) ? (string)$opt['existing'] : 'update';
            if ($sum['update'] && !Access::can($pageKey, 'edit')) {
                fail('Updating the accounts already on the chart needs the Edit right on Chart of Accounts: choose "Leave it as it is", or remove those lines.');
            }
            // the lines again, with their types and parents, in the order they are saved: main accounts first
            $types = [];
            foreach ($checked as $r) {
                $types[mb_strtolower($r['code'])] = self::typeOf($r['type']);
            }
            $codesOf = static fn(): array => array_change_key_case(DB::pairs('SELECT code, id FROM accounts'), CASE_LOWER);
            $ids = $codesOf();
            $order = [];
            foreach ($checked as $i => $r) {
                $order[$r['parent'] === '' ? 0 : 1][] = $i;
            }
            $added = $updated = $same = $skipped = 0;
            $codes = [];
            foreach ([0, 1] as $pass) {
                foreach ($order[$pass] ?? [] as $i) {
                    $r = $checked[$i];
                    $lc = mb_strtolower($r['code']);
                    $acc = isset($ids[$lc]) ? DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $ids[$lc]]) : null;
                    if ($acc && $mode === 'skip') {
                        $skipped++;
                        continue;
                    }
                    $r['_type'] = $types[$lc];
                    $parentLc = $r['parent'] !== '' ? mb_strtolower($r['parent']) : '';
                    $final = [$lc => ['parent' => $parentLc !== '' ? $parentLc : ($acc && $acc['parent_id'] ? mb_strtolower((string)DB::value('SELECT code FROM accounts WHERE id = :p', ['p' => $acc['parent_id']])) : '')]];
                    $onFile = [];
                    if ($final[$lc]['parent'] !== '' && isset($ids[$final[$lc]['parent']])) {
                        $onFile[$final[$lc]['parent']] = ['id' => $ids[$final[$lc]['parent']]];
                    } elseif ($final[$lc]['parent'] !== '') {
                        fail('The main account ' . $r['parent'] . ' of line ' . (int)$r['_line'] . ' was not saved.');
                    }
                    $d = self::record($r, $acc, $final, $onFile);
                    if ($acc) {
                        $changed = self::changes($acc, $d);
                        if (!$changed) {
                            $same++;
                            continue;
                        }
                        DB::update('accounts', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $acc['id']]);
                        Audit::log('import_update', 'coa', 'account', (int)$acc['id'], $d['code'] . ' ' . $d['name'],
                            ['page_key' => $pageKey, 'changes' => $changed, 'reason' => 'Chart of Accounts import' . ($fileName !== '' ? ': ' . $fileName : '')]);
                        $updated++;
                    } else {
                        $id = DB::insert('accounts', $d);
                        $ids[$lc] = $id;
                        Audit::log('import_create', 'coa', 'account', $id, $d['code'] . ' ' . $d['name'],
                            ['page_key' => $pageKey, 'snapshot' => $d, 'reason' => 'Chart of Accounts import' . ($fileName !== '' ? ': ' . $fileName : '')]);
                        $added++;
                    }
                    $codes[] = $d['code'];
                }
            }
            Accts::reset();
            return ['added' => $added, 'updated' => $updated, 'same' => $same, 'skipped' => $skipped, 'codes' => $codes];
        });
    }
}
