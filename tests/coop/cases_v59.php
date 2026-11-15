<?php
declare(strict_types=1);

/**
 * The request of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v24.xlsx (version 59), Sheet1 Q22, with an arrow onto the
 * Name of Financial > Account Types > Fixed Asset ("A built-in type keeps its name and what it is"):
 *  "Claude, please make a facility to edit the Name of built in Account Types".
 * The name of a built-in type is changed on its page; what it is (its base type, which the modules and
 * accounts.account_type go by) does not change; the new name is shown wherever the type is; its original name still
 * means it in a file to import and is taken by no other type.
 * The pages are checked over HTTP (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

/** The built-in types back to their original names (the tests after these see the program as it was) */
function v59Restore(): void
{
    foreach (DB::all('SELECT id, name, base_type FROM account_types WHERE is_system = 1') as $t) {
        if ($t['name'] !== $t['base_type']) {
            DB::update('account_types', ['name' => $t['base_type']], 'id = :id', ['id' => $t['id']]);
        }
    }
    AccountTypes::reset();
    if (function_exists('fin_accounts')) {
        fin_accounts(true);
    }
}

/** A journal entry of today: debit $dr, credit $cr */
function v59Entry(string $dr, string $cr, float $amount, string $memo): int
{
    return Txn::save('journal', ['txn_date' => today(), 'memo' => $memo], [
        ['line_kind' => 'journal', 'account_id' => acct($dr), 'amount' => $amount, 'description' => $memo],
        ['line_kind' => 'journal', 'account_id' => acct($cr), 'amount' => -$amount, 'description' => $memo],
    ]);
}

test('Version 59: the name of a built-in account type is changed on its page; what it is stays; the audit trail; the name is checked; the original name comes back', function () {
    Auth::actAs($GLOBALS['adminId']);
    v59Restore();
    $fa = AccountTypes::system('Fixed Asset');
    $id = (int)$fa['id'];
    check($id > 0 && (int)$fa['is_system'] === 1, 'Fixed Asset is a built-in type');
    $before = DB::all("SELECT id, account_type, type_id, category, normal_balance FROM accounts WHERE account_type = 'Fixed Asset' ORDER BY id");
    check(count($before) >= 8, 'the chart has its fixed asset accounts');

    // the page sends every field of the form: the name changes, the base type and "active" sent with it do not
    AccountTypes::save(['name' => '  Property   and Equipment ', 'base_type' => 'Expense', 'cash_flow' => 'investing', 'sort_order' => 40,
        'description' => 'Property and equipment and their accumulated depreciation', 'is_active' => 0], $id, 'coa.account_types');
    $t = AccountTypes::get($id);
    eq([$t['name'], $t['base_type'], (int)$t['is_system'], (int)$t['is_active'], $t['category'], $t['group'], $t['cash_flow']],
        ['Property and Equipment', 'Fixed Asset', 1, 1, 'Asset', 'Fixed Assets', 'investing'],
        'renamed Property and Equipment (spaces tidied); it still behaves as Fixed Asset, is built-in and active, under Fixed Assets, investing');
    eq([AccountTypes::label('Fixed Asset'), AccountTypes::renamed($t), AccountTypes::renamed(AccountTypes::system('Bank'))], ['Property and Equipment', true, false],
        'the built-in Fixed Asset is shown by its new name; Bank was not renamed');
    eq(DB::all("SELECT id, account_type, type_id, category, normal_balance FROM accounts WHERE account_type = 'Fixed Asset' ORDER BY id"), $before,
        'its accounts are untouched (account type, type, category, normal balance)');
    $land = DB::row("SELECT * FROM accounts WHERE code = '1500'");
    eq([AccountTypes::nameOf($land), (int)AccountTypes::forAccount($land)['id']], ['Property and Equipment', $id], '1500 Land: of the type Property and Equipment, the same type');
    $audit = DB::row("SELECT * FROM audit_trail WHERE record_type = 'account_type' AND record_id = :t AND action = 'edit' ORDER BY id DESC LIMIT 1", ['t' => $id]);
    check($audit && $audit['record_ref'] === 'Property and Equipment', 'in the audit trail under its new name');
    $changes = json_decode((string)$audit['changes'], true) ?: [];
    $named = array_values(array_filter($changes, fn($c) => ($c['field'] ?? '') === 'name'));
    eq([$named[0]['old'] ?? null, $named[0]['new'] ?? null], ['Fixed Asset', 'Property and Equipment'], 'the audit trail has the old name and the new one');

    // the name is checked
    refuses(fn() => AccountTypes::save(['name' => '   '], $id, 'coa.account_types'), 'Enter the name', 'a name is needed');
    refuses(fn() => AccountTypes::save(['name' => str_repeat('P', 61)], $id, 'coa.account_types'), 'at most 60', 'at most 60 characters');
    $own = AccountTypes::save(['name' => 'V59 Deposits Held', 'base_type' => 'Other Current Liability', 'cash_flow' => 'operating'], null, 'coa.account_types');
    refuses(fn() => AccountTypes::save(['name' => 'v59 deposits held'], (int)AccountTypes::system('Long Term Liability')['id'], 'coa.account_types'), 'already an account type',
        'a built-in type cannot take the name of another type (any case)');
    refuses(fn() => AccountTypes::save(['name' => 'Bank'], $id, 'coa.account_types'), 'already an account type named "Bank"', 'nor the name of another built-in type');
    // the original name stays the built-in type's own
    refuses(fn() => AccountTypes::save(['name' => 'Fixed Asset', 'base_type' => 'Fixed Asset', 'cash_flow' => 'investing'], null, 'coa.account_types'), 'original name of the built-in type now named Property and Equipment',
        'a type of your own cannot be named Fixed Asset');
    refuses(fn() => AccountTypes::save(['name' => 'fixed-asset'], $own, 'coa.account_types'), 'original name', '...nor fixed-asset (any case and spacing), also when renamed');
    refuses(fn() => AccountTypes::save(['name' => 'Fixed Asset'], (int)AccountTypes::system('Other Asset')['id'], 'coa.account_types'), 'original name', '...nor another built-in type');
    eq(AccountTypes::get($own)['name'], 'V59 Deposits Held', 'nothing was changed by the refusals');
    // a type of your own still takes any other name; a type saved again with its name as it is passes
    AccountTypes::save(['name' => 'Fixed Assets Held for Sale'], $own, 'coa.account_types');
    AccountTypes::save(['description' => 'Kept'], $id, 'coa.account_types');
    eq([AccountTypes::get($own)['name'], AccountTypes::get($id)['name'], AccountTypes::get($id)['description']], ['Fixed Assets Held for Sale', 'Property and Equipment', 'Kept'],
        'another name is taken; the renamed type saved again keeps its new name');
    AccountTypes::delete($own, 'coa.account_types');

    // a built-in type is still not deleted, renamed or not
    refuses(fn() => AccountTypes::delete($id, 'coa.account_types'), 'built-in', 'a renamed built-in type is not deleted');
    // back to its original name, in any case
    AccountTypes::save(['name' => 'fixed asset'], $id, 'coa.account_types');
    eq(AccountTypes::get($id)['name'], 'fixed asset', 'its original name in other letters is its own');
    AccountTypes::save(['name' => 'Fixed Asset'], $id, 'coa.account_types');
    eq([AccountTypes::get($id)['name'], AccountTypes::renamed(AccountTypes::get($id)), AccountTypes::label('Fixed Asset')], ['Fixed Asset', false, 'Fixed Asset'], 'back to Fixed Asset');
    v59Restore();
});

test('Version 59: a renamed built-in type on the statements (the part that holds only it takes its name; with other types, a heading of its own), the totals the same', function () {
    Auth::actAs($GLOBALS['adminId']);
    v59Restore();
    v59Entry('1540', '1010', 12000, 'V59 Office tables');
    v59Entry('6010', '1010', 1500, 'V59 Rent of the month');
    $asof = today();
    $fy = Report::fiscalYearStart($asof);
    $bs0 = v36Run('balance_sheet', ['asof' => $asof]);
    $is0 = v36Run('income_statement', ['from' => $fy, 'to' => $asof]);
    check(in_array('Fixed Assets', $bs0['names'], true) && !in_array('Property and Equipment', $bs0['names'], true), 'before: the part Fixed Assets');
    $userExp = array_filter(AccountTypes::all(), fn($t) => $t['base_type'] === 'Expense' && (int)$t['is_system'] !== 1);
    $userFa = array_filter(AccountTypes::all(), fn($t) => $t['base_type'] === 'Fixed Asset' && (int)$t['is_system'] !== 1);
    eq([count($userExp), count($userFa)], [0, 0], 'no type of your own behaves as Expense or Fixed Asset');

    AccountTypes::save(['name' => 'Property and Equipment'], (int)AccountTypes::system('Fixed Asset')['id'], 'coa.account_types');
    AccountTypes::save(['name' => 'Operating Expenses'], (int)AccountTypes::system('Expense')['id'], 'coa.account_types');
    AccountTypes::save(['name' => 'Cash in Banks'], (int)AccountTypes::system('Bank')['id'], 'coa.account_types');
    fin_accounts(true);
    $bs = v36Run('balance_sheet', ['asof' => $asof]);
    $faIds = array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type = 'Fixed Asset'"));
    $i = array_search('Property and Equipment', $bs['names'], true);
    check($i !== false, 'the Balance Sheet: the part is headed Property and Equipment');
    eq([in_array('Fixed Assets', $bs['names'], true), in_array('Total Fixed Assets', $bs['names'], true), count(array_keys($bs['names'], 'Property and Equipment', true))], [false, false, 1],
        'in place of Fixed Assets, once (no heading of the type under it)');
    eq($bs['rows'][$i]['_class'], 'indent-1 fw-semibold', 'as the heading of the part');
    eqm($bs['by']['Total Property and Equipment'], v36Net($faIds, $asof), 'Total Property and Equipment = every fixed asset account');
    foreach (['TOTAL ASSETS', 'TOTAL LIABILITIES', 'TOTAL EQUITY', 'TOTAL LIABILITIES AND EQUITY'] as $k) {
        eqm($bs['by'][$k], $bs0['by'][$k], 'the same ' . strtolower($k));
    }
    $sum = v36Run('balance_sheet_summary', ['asof' => $asof]);
    eqm($sum['by']['Total Property and Equipment'], $bs['by']['Total Property and Equipment'], 'the Balance Sheet Summary too');
    // the income statement: Expenses is Operating Expenses
    $is = v36Run('income_statement', ['from' => $fy, 'to' => $asof]);
    $j = array_search('Operating Expenses', $is['names'], true);
    check($j !== false && $is['rows'][$j]['_class'] === 'group-row' && !in_array('Expenses', $is['names'], true), 'the Income Statement: the part Operating Expenses in place of Expenses');
    eqm($is['by']['Total Operating Expenses'], $is0['by']['Total Expenses'], 'Total Operating Expenses = the Total Expenses of before');
    eqm($is['by']['NET INCOME'], $is0['by']['NET INCOME'], 'the same net income');
    $iss = v36Run('income_statement_summary', ['from' => $fy, 'to' => $asof]);
    check(in_array('Total Operating Expenses', $iss['names'], true), 'the Income Statement Summary too');
    // the part Current Assets holds several types: Cash in Banks is a heading of its own when it has accounts
    $bankIds = array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type = 'Bank' AND type_id IS NULL"));
    check(in_array('Current Assets', $bs['names'], true), 'Current Assets keeps its name (several built-in types)');
    if ($bankIds && abs((float)v36Net($bankIds, $asof)) >= 0.005) {
        check(in_array('Cash in Banks', $bs['names'], true) && in_array('Total Cash in Banks', $bs['names'], true), 'Bank is shown as Cash in Banks under Current Assets');
    }
    // the Statement of Cash Flows: the cash types named by their names
    check(str_contains(fin_cash_type_names(), 'Cash in Banks'), 'the Statement of Cash Flows counts Cash in Banks as cash and cash equivalents, by its new name');

    // where the pages say it is (the account form, Account Types): as the statements show it
    $faT = AccountTypes::system('Fixed Asset');
    eq([AccountTypes::alone($faT), AccountTypes::partName($faT), AccountTypes::partName(AccountTypes::system('Bank')), AccountTypes::partName(AccountTypes::system('Other Asset'))],
        [true, 'Property and Equipment', 'Current Assets', 'Other Assets'], 'Property and Equipment is its own part; Bank renamed is still under Current Assets; Other Asset (not renamed) under Other Assets');
    eq([AccountTypes::placement($faT), AccountTypes::placement(AccountTypes::system('Expense'))],
        ['Balance Sheet: Assets > Property and Equipment; Statement of Cash Flows: Investing activities', 'Income Statement: Operating Expenses; Statement of Cash Flows: part of the net income'],
        'the account form says where: Assets > Property and Equipment, Income Statement: Operating Expenses');
    // a type of your own under Fixed Asset: the part is Fixed Assets again, each type with its heading and total
    $veh = AccountTypes::save(['name' => 'V59 Vehicles', 'base_type' => 'Fixed Asset', 'cash_flow' => 'investing'], null, 'coa.account_types');
    AccountTypes::assign($veh, [acct('1530')], 'coa.account_types');
    v59Entry('1530', '1010', 5000, 'V59 Motorcycle');
    fin_accounts(true);
    $bs2 = v36Run('balance_sheet', ['asof' => $asof]);
    $k = array_search('Fixed Assets', $bs2['names'], true);
    check($k !== false && in_array('Total Fixed Assets', $bs2['names'], true), 'with two types the part is Fixed Assets');
    check(in_array('Property and Equipment', $bs2['names'], true) && in_array('Total Property and Equipment', $bs2['names'], true)
        && in_array('V59 Vehicles', $bs2['names'], true) && in_array('Total V59 Vehicles', $bs2['names'], true), 'with Property and Equipment and V59 Vehicles under it, each with its total');
    eqm($bs2['by']['Total Fixed Assets'], v36Net($faIds, $asof), 'Total Fixed Assets = every fixed asset account');
    eq([AccountTypes::alone(AccountTypes::system('Fixed Asset')), AccountTypes::placement(AccountTypes::system('Fixed Asset'))], [false, 'Balance Sheet: Assets > Fixed Assets; Statement of Cash Flows: Investing activities'],
        '...and the pages say Fixed Assets again');
    // the rows of a part: the rule (fin_part_name, fin_block_rows)
    $blocks = [['type' => AccountTypes::system('Fixed Asset'), 'mains' => [], 'total' => ['amount' => 0.0]]];
    $mk = fn(string $n, ?array $v, string $c, ?string $u) => ['name' => $n, '_class' => $c];
    eq([fin_part_name('Fixed Assets', ['Fixed Asset'], $blocks), fin_part_name('Current Assets', ['Bank', 'Accounts Receivable'], $blocks)], ['Property and Equipment', 'Current Assets'],
        'a part of one built-in type takes its new name; a part of several keeps its own');
    [$own] = fin_block_rows($blocks, ['amount'], $mk, 2, false, fn($a) => null, null, null, true);
    [$alone] = fin_block_rows($blocks, ['amount'], $mk, 2, false, fn($a) => null);
    eq([array_column($own, 'name'), array_column($alone, 'name')], [[], ['Property and Equipment', 'Total Property and Equipment']],
        'alone in a part named after it: no heading of its own; alone in a part of several types: its heading and total');
    // back as it was
    AccountTypes::assign((int)AccountTypes::system('Fixed Asset')['id'], [acct('1530')], 'coa.account_types');
    AccountTypes::delete($veh, 'coa.account_types');
    v59Restore();
    $bs3 = v36Run('balance_sheet', ['asof' => $asof]);
    check(in_array('Fixed Assets', $bs3['names'], true) && !in_array('Property and Equipment', $bs3['names'], true), 'the original names: Fixed Assets as before');
    assertLedgerBalanced('the ledger balances');
});

test('Version 59: the new name wherever the type is shown (the type pickers, what a type behaves as, the refusals, the reports); the files to import read the new name and the original one', function () {
    Auth::actAs($GLOBALS['adminId']);
    v59Restore();
    $oca = (int)AccountTypes::system('Other Current Asset')['id'];
    AccountTypes::save(['name' => 'Other Current Assets'], $oca, 'coa.account_types');
    AccountTypes::save(['name' => 'Property and Equipment'], (int)AccountTypes::system('Fixed Asset')['id'], 'coa.account_types');
    $loans = AccountTypes::byName('Loans Receivable') ?? AccountTypes::get(AccountTypes::save(['name' => 'Loans Receivable', 'base_type' => 'Other Current Asset', 'cash_flow' => 'operating'], null, 'coa.account_types'));
    // the account form: the picker
    $opts = AccountTypes::optionsHtml(null);
    check(str_contains($opts, '>Other Current Assets</option>') && str_contains($opts, '>Loans Receivable (Other Current Assets)</option>') && str_contains($opts, '>Property and Equipment</option>'),
        'the type picker of an account: the built-in types by their new names, a type of your own with the new name of what it behaves as');
    check(!str_contains($opts, '>Fixed Asset<') && !str_contains($opts, '(Other Current Asset)'), '...not by their original names');
    $grouped = AccountTypes::grouped();
    check(in_array('Property and Equipment', $grouped['Assets'], true), 'grouped under Assets');
    // a refusal says what an account behaves as by the new name
    $land = DB::row("SELECT * FROM accounts WHERE code = '1500'");
    $sysAcct = DB::row("SELECT * FROM accounts WHERE is_system = 1 AND account_type = 'Other Current Asset' ORDER BY id LIMIT 1");
    if ($sysAcct) {
        eq(AccountTypes::refusal($sysAcct, AccountTypes::system('Fixed Asset')), 'The account ' . $sysAcct['code'] . ' is one the modules post to: it can only take a type that behaves as Other Current Assets.',
            'the refusal names the type by its new name');
    }
    // the reports: the Trial Balance by date range lists each account with its type
    eq(AccountTypes::label((string)$land['account_type']), 'Property and Equipment', 'what 1500 Land is shown as (reports, budgets, the Chart of Accounts export)');
    $fy = Report::fiscalYearStart(today());
    $tb = v36Run('trial_balance_range', ['from' => $fy, 'to' => today()]);
    $furn = array_values(array_filter($tb['rows'], fn($r) => str_starts_with((string)($r['account'] ?? ''), '1540 - ')));
    eq($furn[0]['type'] ?? null, 'Property and Equipment', 'Trial Balance by Date Range: 1540 Furniture and Fixtures is a Property and Equipment account');

    // the files to import: the Chart of Accounts import reads the new name and the original one
    $csv = "code,name,type\n9591,V59 Generator,Fixed Asset\n9592,V59 Solar Panels,Property and Equipment\n9593,V59 Tools,ppe\n9594,V59 Advances,Other Current Asset\n";
    [$rows, $sum] = CoaImport::check(v57Coa($csv), ['existing' => 'update']);
    eq(array_column($rows, 'type'), ['Property and Equipment', 'Property and Equipment', 'Property and Equipment', 'Other Current Assets'], 'every line read as the renamed built-in type');
    eq($rows[0]['_notes'], ['Type "Fixed Asset" read as Property and Equipment.'], 'the original name read as the new one, with a note');
    eq([$sum['bad'], $sum['new']], [0, 4], 'every line ready');
    $r = CoaImport::post(v57Coa($csv), ['existing' => 'update'], 'v59.csv', 'coa.accounts');
    eq($r['added'], 4, 'saved');
    eq(DB::pairs("SELECT code, account_type FROM accounts WHERE code IN ('9591','9592','9593','9594') ORDER BY code"),
        ['9591' => 'Fixed Asset', '9592' => 'Fixed Asset', '9593' => 'Fixed Asset', '9594' => 'Other Current Asset'], 'saved as Fixed Asset and Other Current Asset accounts');
    $hint = CoaImport::cols()['type']['hint'];
    check(str_contains($hint, 'Property and Equipment') && str_contains($hint, 'Other Current Assets') && str_contains($hint, 'original names'), 'the hint of the Type column names them by their new names');
    check(isset(CoaImport::cols()['type']['options']['Property and Equipment']) && !isset(CoaImport::cols()['type']['options']['Fixed Asset']), 'the Type cell offers the new name');
    // System Administration > Import from CSV: the account types of the file, new name or original name
    require_once APP_ROOT . '/modules/admin/_import_lib.php';
    eq([in_array('Property and Equipment', AccountTypes::names(), true), in_array('Fixed Asset', AccountTypes::names(), true), in_array('Bank', AccountTypes::names(), true)], [true, true, true],
        'the names the import accepts: the new name, the original name, the others');
    $res = import_run('accounts', [
        ['_line' => 2, 'code' => '9595', 'name' => 'V59 Aircon', 'account_type' => 'Fixed Asset', 'normal_balance' => '', 'parent_code' => '', 'description' => '', 'bank_account_no' => '', 'with_sl' => '', 'is_active' => ''],
        ['_line' => 3, 'code' => '9596', 'name' => 'V59 Fence', 'account_type' => 'property and equipment', 'normal_balance' => '', 'parent_code' => '', 'description' => '', 'bank_account_no' => '', 'with_sl' => '', 'is_active' => ''],
    ], false, 'v59.csv', 'admin.import');
    eq([$res['errors'], $res['ok']], [[], 2], 'Import from CSV: both lines saved');
    eq(DB::pairs("SELECT code, account_type FROM accounts WHERE code IN ('9595','9596') ORDER BY code"), ['9595' => 'Fixed Asset', '9596' => 'Fixed Asset'], 'as Fixed Asset accounts');
    eq((int)DB::value("SELECT type_id FROM accounts WHERE code = '9595'"), (int)AccountTypes::system('Fixed Asset')['id'], 'of the built-in type (renamed)');
    // the lookups
    eq([AccountTypes::byName('FIXED ASSET')['id'] ?? null, AccountTypes::byName('property and equipment')['id'] ?? null, AccountTypes::byName('Nothing Like It')],
        [(int)AccountTypes::system('Fixed Asset')['id'], (int)AccountTypes::system('Fixed Asset')['id'], null], 'found by the new name and by the original one');
    eq(CoaImport::typeOf('PPE')['name'] ?? null, 'Property and Equipment', 'and by a usual other name');
    // the sample database's own types of the user's: Cash behaves as Bank, shown by Bank's name
    eq((string)AccountTypes::get((int)$loans['id'])['base_type'], 'Other Current Asset', 'Loans Receivable still behaves as Other Current Asset');
    DB::run("DELETE FROM accounts WHERE code IN ('9591','9592','9593','9594','9595','9596')");
    v59Restore();
    eq(AccountTypes::label('Other Current Asset'), 'Other Current Asset', 'back to the original names');
});
