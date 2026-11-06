<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v7.xlsx (version 36) at the level of the program's rules:
 *  - account types of the user's own (Cash, Loans Receivable ...), each behaving as a built-in type and linked to a
 *    section of the Statement of Cash Flows, so the statement keeps its integrity;
 *  - every account type a classification of its own on the Balance Sheet and the Income Statement;
 *  - a main account with the total of its sub-accounts;
 *  - the Balance Sheet Summary and the Income Statement Summary: the main accounts only.
 * The pages (Account Types, the Chart of Accounts, the account form) are checked in the browser (docs/TEST_EVIDENCE.md).
 */

/** The rows of a report as name => amount (the first amount column), and the names in order */
function v36Run(string $key, array $f): array
{
    $def = Report::get($key);
    fin_accounts(true);
    $data = $def['run']($f + ['class_id' => null, 'columns' => '']);
    $by = [];
    $names = [];
    foreach ($data['rows'] as $r) {
        $name = (string)($r['name'] ?? $r['account'] ?? '');
        $names[] = $name;
        $amt = $r['amount'] ?? $r['total'] ?? $r['change'] ?? null;
        if (!isset($by[$name]) || $amt !== null) {
            $by[$name] = $amt;
        }
    }
    return ['by' => $by, 'names' => $names, 'rows' => $data['rows'], 'notes' => (string)($data['notes'] ?? '')];
}

/** The net debit balance of accounts as of a date, as the statements take it (the balances between the lines of
    business eliminated: fin_balances()) */
function v36Net(array $ids, string $asof): string
{
    $bal = fin_balances(null, $asof);
    $t = 0.0;
    foreach ($ids as $id) {
        $t += $bal[(int)$id] ?? 0.0;
    }
    return r2s((string)round($t, 2));
}

function v36Id(string $code): int
{
    return (int)DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
}

test('Account types: the fifteen built-in ones, types of your own behaving as one and linked to the Statement of Cash Flows; unique names; a built-in type keeps its name and what it is', function () {
    Auth::actAs($GLOBALS['adminId']);
    AccountTypes::reset();
    $sys = array_filter(AccountTypes::all(), fn($t) => (int)$t['is_system'] === 1);
    eq(count($sys), 15, 'the fifteen built-in types');
    eq([AccountTypes::system('Bank')['cash_flow'], AccountTypes::system('Other Current Asset')['cash_flow'], AccountTypes::system('Fixed Asset')['cash_flow'],
        AccountTypes::system('Long Term Liability')['cash_flow'], AccountTypes::system('Income')['cash_flow']], ['cash', 'operating', 'investing', 'financing', ''],
        'by default Bank is cash, the current items operating, fixed and other assets investing, long-term debt and equity financing; income and expense are in the net income');
    $acc = DB::row("SELECT * FROM accounts WHERE code = '1000'");
    eq([$acc['type_id'], AccountTypes::nameOf($acc)], [null, 'Bank'], 'an account of before is of the built-in type of its account type');
    $cash = AccountTypes::save(['name' => 'Cash', 'base_type' => 'Bank', 'cash_flow' => 'cash', 'sort_order' => 5, 'description' => 'Cash on hand and in banks', 'is_active' => 1], null, 'coa.account_types');
    $t = AccountTypes::get($cash);
    eq([$t['name'], $t['base_type'], $t['category'], $t['group'], $t['cash_flow'], (int)$t['is_system']], ['Cash', 'Bank', 'Asset', 'Current Assets', 'cash', 0], 'Cash behaves as Bank: a current asset, cash and cash equivalents');
    refuses(fn() => AccountTypes::save(['name' => 'cash', 'base_type' => 'Bank', 'cash_flow' => 'cash'], null, 'coa.account_types'), 'already an account type', 'a name is used once (any case)');
    refuses(fn() => AccountTypes::save(['name' => 'Notes Receivable', 'base_type' => 'Other Current Asset', 'cash_flow' => ''], null, 'coa.account_types'), 'section of the Statement of Cash Flows', 'a balance sheet type needs its cash flow section');
    refuses(fn() => AccountTypes::save(['name' => 'Odd', 'base_type' => 'Nothing', 'cash_flow' => 'cash'], null, 'coa.account_types'), 'built-in type', 'it behaves as one of the built-in types');
    $int = AccountTypes::save(['name' => 'Interest Income', 'base_type' => 'Income', 'cash_flow' => 'investing'], null, 'coa.account_types');
    eq(AccountTypes::get($int)['cash_flow'], '', 'an income type is part of the net income (no section of its own)');
    $loans = AccountTypes::save(['name' => 'Loans Receivable', 'base_type' => 'Other Current Asset', 'cash_flow' => 'operating', 'sort_order' => 5], null, 'coa.account_types');
    eq(array_slice(array_values(array_map(fn($x) => $x['name'], array_filter(AccountTypes::all(), fn($x) => $x['category'] === 'Asset'))), 0, 4),
        ['Cash', 'Bank', 'Accounts Receivable', 'Loans Receivable'], 'the order of the statements: by built-in type, then the order given');
    // a built-in type: its cash flow section and order change, its name and base type stay
    $bank = (int)AccountTypes::system('Bank')['id'];
    AccountTypes::save(['name' => 'Banks', 'base_type' => 'Expense', 'cash_flow' => 'operating', 'sort_order' => 10, 'is_active' => 0], $bank, 'coa.account_types');
    $b = AccountTypes::get($bank);
    eq([$b['name'], $b['base_type'], $b['cash_flow'], (int)$b['is_active']], ['Bank', 'Bank', 'operating', 1], 'a built-in type keeps its name, what it is and stays active');
    AccountTypes::save(['cash_flow' => 'cash', 'sort_order' => 10], $bank, 'coa.account_types');
    refuses(fn() => AccountTypes::delete($bank, 'coa.account_types'), 'built-in', 'a built-in type cannot be deleted');
    $tmp = AccountTypes::save(['name' => 'Spare', 'base_type' => 'Other Asset', 'cash_flow' => 'investing'], null, 'coa.account_types');
    AccountTypes::delete($tmp, 'coa.account_types');
    eq(AccountTypes::get($tmp), null, 'a type no account uses is deleted');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE record_type = 'account_type' AND action = 'delete' AND record_id = :t", ['t' => $tmp]), 'in the audit trail');
    eq(AccountTypes::byName('loans receivable')['id'] ?? null, $loans, 'found by its name (the CSV import)');
    $GLOBALS['v36'] = ['cash' => $cash, 'loans' => $loans, 'int' => $int];
});

test('Giving accounts a type: the account keeps behaving as its built-in type for the modules; an account the modules post to keeps its base; a type in use keeps its base and is not deleted', function () {
    Auth::actAs($GLOBALS['adminId']);
    ['cash' => $cash, 'loans' => $loans] = $GLOBALS['v36'];
    $main = v36Id('1000');
    $subs = array_map('intval', DB::column('SELECT id FROM accounts WHERE parent_id = :p', ['p' => $main]));
    check(count($subs) >= 3, 'Cash 1000 has sub-accounts');
    $n = AccountTypes::assign($cash, array_merge([$main], $subs), 'coa.account_types');
    eq($n, count($subs) + 1, 'the main account and its sub-accounts take the type Cash');
    $a = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $subs[0]]);
    eq([(int)$a['type_id'], $a['account_type'], $a['category'], AccountTypes::nameOf($a)], [$cash, 'Bank', 'Asset', 'Cash'], 'its type is Cash, it still behaves as Bank');
    Accts::reset();
    check(isset(Accts::ofType(['Bank'])[$subs[0]]), 'the forms that offer bank and cash accounts still offer it');
    eq(AccountTypes::assign($cash, [$main], 'coa.account_types'), 0, 'an account already of the type is left as it is');
    $lr = v36Id('1210');
    AccountTypes::assign($loans, [$lr], 'coa.account_types');
    eq(AccountTypes::nameOf(DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $lr])), 'Loans Receivable', 'Loans Receivable 1210 takes its type');
    refuses(fn() => AccountTypes::assign($cash, [v36Id('1050')], 'coa.account_types'), 'modules post to', 'Undeposited Funds (a module account) keeps a type of its built-in type');
    $exp = (int)DB::value("SELECT a.id FROM accounts a JOIN journal_lines j ON j.account_id = a.id WHERE a.category = 'Expense' LIMIT 1");
    refuses(fn() => AccountTypes::assign($cash, [$exp], 'coa.account_types'), 'another category', 'an expense with entries does not become an asset');
    refuses(fn() => AccountTypes::save(['name' => 'Cash', 'base_type' => 'Other Current Asset', 'cash_flow' => 'operating'], $cash, 'coa.account_types'), 'Move them to another type first', 'the base of a type in use stays');
    refuses(fn() => AccountTypes::delete($cash, 'coa.account_types'), 'Move them to another type first', 'a type in use is not deleted');
    // a type that no longer fits the account's base (changed outside the program): the built-in type counts
    DB::update('accounts', ['type_id' => $loans], 'id = :id', ['id' => v36Id('1100')]);
    eq(AccountTypes::nameOf(DB::row("SELECT * FROM accounts WHERE code = '1100'")), 'Accounts Receivable', 'a type of another base is not used: the built-in one is');
    DB::update('accounts', ['type_id' => null], 'id = :id', ['id' => v36Id('1100')]);
    // moved back: an account given the built-in type again
    $other = AccountTypes::save(['name' => 'Cash Funds', 'base_type' => 'Bank', 'cash_flow' => 'cash'], null, 'coa.account_types');
    AccountTypes::assign($other, [v36Id('1005')], 'coa.account_types');
    eq(AccountTypes::nameOf(DB::row("SELECT * FROM accounts WHERE code = '1005'")), 'Cash Funds', 'moved to another type of the same base');
    AccountTypes::assign($cash, [v36Id('1005')], 'coa.account_types');
    AccountTypes::delete($other, 'coa.account_types');
    eq((int)(AccountTypes::usage()[$cash] ?? 0), count($subs) + 1, 'the accounts of each type are counted');
});

test('Statements by account type: each type a classification (heading, accounts, total); a main account with its sub-accounts and their total; the summaries show main accounts only; the totals do not change', function () {
    Auth::actAs($GLOBALS['adminId']);
    ['cash' => $cash, 'loans' => $loans, 'int' => $int] = $GLOBALS['v36'];
    $asof = today();
    $fy = Report::fiscalYearStart($asof);
    $bs = v36Run('balance_sheet', ['asof' => $asof]);
    $assets = array_map('intval', DB::column("SELECT id FROM accounts WHERE category = 'Asset'"));
    eqm($bs['by']['TOTAL ASSETS'], v36Net($assets, $asof), 'total assets = every asset account');
    eqm($bs['by']['TOTAL ASSETS'], $bs['by']['TOTAL LIABILITIES AND EQUITY'], 'the balance sheet balances');
    $cashIds = array_map('intval', DB::column('SELECT id FROM accounts WHERE type_id = :t', ['t' => $cash]));
    $bankIds = array_map('intval', DB::column("SELECT id FROM accounts WHERE account_type = 'Bank' AND (type_id IS NULL OR type_id <> :t)", ['t' => $cash]));
    check(in_array('Cash', $bs['names'], true) && in_array('Loans Receivable', $bs['names'], true), 'the types Cash and Loans Receivable are classifications of their own');
    eqm($bs['by']['Total Cash'], v36Net($cashIds, $asof), 'Total Cash = the accounts of the type Cash');
    $i = array_search('Cash', $bs['names'], true);
    eq($bs['names'][$i + 1], '1000 - Cash', 'under the type: the main account 1000 Cash');
    $main = v36Id('1000');
    $subIds = array_map('intval', DB::column('SELECT id FROM accounts WHERE parent_id = :p', ['p' => $main]));
    eq($bs['by']['1000 - Cash'] ?? null, null, 'a main account with sub-accounts is a heading');
    eqm($bs['by']['Total 1000 - Cash'], v36Net(array_merge([$main], $subIds), $asof), 'Total 1000 - Cash = the main account and its sub-accounts');
    $sub = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $subIds[0]]);
    $withSub = array_values(array_filter($subIds, fn($sid) => abs((float)v36Net([$sid], $asof)) >= 0.005));
    if ($withSub) {
        $s = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $withSub[0]]);
        eqm($bs['by'][$s['code'] . ' - ' . $s['name']], v36Net([$s['id']], $asof), 'each sub-account with its balance');
    }
    eqm($bs['by']['Total Loans Receivable'], v36Net(array_map('intval', DB::column('SELECT id FROM accounts WHERE type_id = :t', ['t' => $loans])), $asof), 'Total Loans Receivable');
    check(!in_array('Bank', $bs['names'], true) || $bankIds, 'Bank shows only when it has accounts left');
    // the summary: the main accounts only, each with the total of its sub-accounts
    $sum = v36Run('balance_sheet_summary', ['asof' => $asof]);
    eqm($sum['by']['1000 - Cash'], $bs['by']['Total 1000 - Cash'], 'Balance Sheet Summary: 1000 Cash with the total of its sub-accounts');
    check(!in_array($sub['code'] . ' - ' . $sub['name'], $sum['names'], true), 'no sub-account is listed');
    check(!in_array('Total 1000 - Cash', $sum['names'], true), 'and no total of a main account');
    foreach (['TOTAL ASSETS', 'TOTAL LIABILITIES', 'TOTAL EQUITY', 'TOTAL LIABILITIES AND EQUITY', 'Total Cash', 'Total Current Assets'] as $k) {
        eqm($sum['by'][$k], $bs['by'][$k], 'the summary has the same ' . strtolower($k));
    }
    // by class: the same totals
    $bc = v36Run('balance_sheet_class', ['asof' => $asof]);
    check(in_array('Total Cash', $bc['names'], true), 'Balance Sheet by Class: by type too');

    // the income statement: a type of its own, and a main account with a sub-account
    $ii = v36Id('4400');
    $sf = v36Id('4410');
    AccountTypes::assign($int, [$ii, $sf], 'coa.account_types');
    DB::update('accounts', ['parent_id' => $ii], 'id = :id', ['id' => $sf]);
    $is = v36Run('income_statement', ['from' => $fy, 'to' => $asof]);
    $net = fn(array $ids) => r2s((string)DB::value('SELECT COALESCE(SUM(credit - debit),0) FROM journal_lines WHERE account_id IN (' . implode(',', $ids) . ') AND txn_date BETWEEN :f AND :t', ['f' => $fy, 't' => $asof]));
    check(in_array('Interest Income', $is['names'], true) && in_array('Total Interest Income', $is['names'], true), 'Income Statement: the type Interest Income with its total');
    $iiRow = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $ii]);
    $sfRow = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $sf]);
    if (abs((float)$net([$sf])) >= 0.005) {
        eqm($is['by']['Total ' . $iiRow['code'] . ' - ' . $iiRow['name']], $net([$ii, $sf]), 'the main account with its sub-account and their total');
        eqm($is['by'][$sfRow['code'] . ' - ' . $sfRow['name']], $net([$sf]), 'the sub-account under it');
    }
    $iss = v36Run('income_statement_summary', ['from' => $fy, 'to' => $asof]);
    eqm($iss['by'][$iiRow['code'] . ' - ' . $iiRow['name']], $net([$ii, $sf]), 'Income Statement Summary: the main account with its sub-account in it');
    check(!in_array($sfRow['code'] . ' - ' . $sfRow['name'], $iss['names'], true), 'the sub-account is not listed');
    eqm($iss['by']['NET INCOME'], $is['by']['NET INCOME'], 'the same net income');
    $incomeIds = array_map('intval', DB::column("SELECT id FROM accounts WHERE category IN ('Income', 'Expense')"));
    eqm($is['by']['NET INCOME'], $net($incomeIds), 'net income = every income less every expense');
    $isc = v36Run('income_statement_class', ['from' => $fy, 'to' => $asof]);
    eqm($isc['by']['NET INCOME'], $is['by']['NET INCOME'], 'Income Statement by Class: the same net income');
    DB::update('accounts', ['parent_id' => null], 'id = :id', ['id' => $sf]);
    $bsr = v36Run('balance_sheet_range', ['from' => $fy, 'to' => $asof]);
    check(in_array('Cash', $bsr['names'], true), 'Balance Sheet by Date Range: by type too');
});

test('Statement of Cash Flows: each account type in its section; cash = the types counted as cash and cash equivalents; the statement ties to them whatever the sections', function () {
    Auth::actAs($GLOBALS['adminId']);
    ['cash' => $cash, 'loans' => $loans] = $GLOBALS['v36'];
    $from = Report::fiscalYearStart(today());
    $to = today();
    $endOf = function () use ($to): string {
        $ids = [];
        foreach (DB::all('SELECT * FROM accounts') as $a) {
            $byId[(int)$a['id']] = $a;
        }
        foreach ($byId as $a) {
            if (AccountTypes::forAccount(AccountTypes::mainOf($a, $byId))['cash_flow'] === 'cash' && in_array($a['category'], ['Asset', 'Liability', 'Equity'], true)) {
                $ids[] = (int)$a['id'];
            }
        }
        return v36Net($ids, $to);
    };
    $cf = v36Run('cash_flow', ['from' => $from, 'to' => $to]);
    check(!str_contains($cf['notes'], 'Does not tie'), 'the statement ties to the cash accounts');
    check(str_contains($cf['notes'], 'Bank') && str_contains($cf['notes'], 'Cash'), 'cash = the accounts of Bank and Cash');
    eqm($cf['by']['CASH AT END OF PERIOD'], $endOf(), 'cash at the end = the cash accounts');
    $op = array_search('Net cash provided by operating activities', $cf['names'], true);
    $loanAt = array_search('Loans Receivable', $cf['names'], true);
    check($loanAt !== false && $loanAt < $op, 'Loans Receivable is an operating activity');
    // the type linked to investing activities: its change moves there, the statement still ties
    AccountTypes::save(['cash_flow' => 'investing', 'sort_order' => 5], $loans, 'coa.account_types');
    $cf2 = v36Run('cash_flow', ['from' => $from, 'to' => $to]);
    $inv = array_search('INVESTING ACTIVITIES', $cf2['names'], true);
    $loan2 = array_search('Loans Receivable', $cf2['names'], true);
    check($loan2 > $inv, 'linked to investing: it is an investing activity');
    check(!str_contains($cf2['notes'], 'Does not tie'), 'and the statement still ties');
    eqm($cf2['by']['NET CASH INCREASE (DECREASE) FOR THE PERIOD'], $cf['by']['NET CASH INCREASE (DECREASE) FOR THE PERIOD'], 'the change in cash is the same');
    // a type counted as cash: its accounts make the cash, not an activity
    $items = AccountTypes::save(['name' => 'Cash Items', 'base_type' => 'Other Current Asset', 'cash_flow' => 'cash'], null, 'coa.account_types');
    AccountTypes::assign($items, [v36Id('1110')], 'coa.account_types');
    $cf3 = v36Run('cash_flow', ['from' => $from, 'to' => $to]);
    check(!str_contains($cf3['notes'], 'Does not tie'), 'with Checks and Other Cash Items counted as cash, the statement ties');
    eqm($cf3['by']['CASH AT END OF PERIOD'], $endOf(), 'and its cash at the end includes them');
    AccountTypes::save(['cash_flow' => 'operating', 'sort_order' => 5], $loans, 'coa.account_types');
    AccountTypes::assign((int)AccountTypes::system('Other Current Asset')['id'], [v36Id('1110')], 'coa.account_types');
    AccountTypes::delete($items, 'coa.account_types');
    // every balance sheet account is counted once: the sections and the cash add up to the change of the balance sheet
    $cf4 = v36Run('cash_flow', ['from' => $from, 'to' => $to]);
    eqm(Dec::add($cf4['by']['Cash at beginning of period'], $cf4['by']['NET CASH INCREASE (DECREASE) FOR THE PERIOD'], 2), $cf4['by']['CASH AT END OF PERIOD'], 'beginning + change = end');
});

test('Main accounts on the statements: a sub-account of another built-in type follows its main account; the main account\'s own entries; a group of one built-in type has no type heading; depreciation stays an operating adjustment', function () {
    Auth::actAs($GLOBALS['adminId']);
    ['cash' => $cash, 'loans' => $loans] = $GLOBALS['v36'];
    $asof = today();
    $bal = fin_balances(null, $asof);
    // a main account with a balance of its own, and an account of another built-in type with a balance, made its sub-account
    $pick = function (array $not, ?string $notType) use ($bal, $cash, $loans): ?array {
        foreach (DB::all("SELECT * FROM accounts WHERE category = 'Asset' AND parent_id IS NULL AND account_type IN ('Accounts Receivable', 'Other Current Asset') ORDER BY code") as $a) {
            $hasSubs = (int)DB::value('SELECT COUNT(*) FROM accounts WHERE parent_id = :p', ['p' => $a['id']]) > 0;
            if (!$hasSubs && abs($bal[(int)$a['id']] ?? 0.0) >= 0.005 && !in_array((int)$a['id'], $not, true) && $a['account_type'] !== $notType
                && !in_array((int)($a['type_id'] ?? 0), [$cash, $loans], true)) {
                return $a;
            }
        }
        return null;
    };
    $m = $pick([], null);
    $s = $m ? $pick([(int)$m['id']], $m['account_type']) : null;
    check($m && $s, 'two asset accounts of different built-in types with balances');
    if (!$m || !$s) {
        return;
    }
    $mType = AccountTypes::nameOf($m);
    $sType = AccountTypes::nameOf($s);
    $label = fn(array $a) => $a['code'] . ' - ' . $a['name'];
    $before = v36Run('balance_sheet', ['asof' => $asof]);
    DB::update('accounts', ['parent_id' => $m['id']], 'id = :id', ['id' => $s['id']]);
    try {
        $bs = v36Run('balance_sheet', ['asof' => $asof]);
        $n = $bs['names'];
        $head = array_search($label($m), $n, true);
        check($head !== false && ($bs['by'][$label($m)] ?? null) === null, 'the main account is a heading');
        eq($n[$head + 1] ?? null, $label($s), 'its sub-account, of another built-in type, is listed under it');
        eqm($bs['by'][$label($s)], v36Net([$s['id']], $asof), 'with its own balance');
        eqm($bs['by'][$label($m) . ' (own entries)'], v36Net([$m['id']], $asof), 'the main account\'s own entries on a line of their own');
        eqm($bs['by']['Total ' . $label($m)], v36Net([$m['id'], $s['id']], $asof), 'Total of the main account = its own and its sub-account\'s');
        $typeHead = array_search($mType, $n, true);
        $typeTotal = array_search('Total ' . $mType, $n, true);
        check($typeHead !== false && $typeHead < $head && $typeTotal > $head, 'the sub-account is shown in the classification of the main account\'s type (' . $mType . ')');
        eqm($bs['by']['Total ' . $mType], Dec::add($before['by']['Total ' . $mType] ?? '0', v36Net([$s['id']], $asof), 2), 'that classification\'s total takes the sub-account in');
        eqm($bs['by']['TOTAL ASSETS'], $before['by']['TOTAL ASSETS'], 'total assets do not change');
        $sum = v36Run('balance_sheet_summary', ['asof' => $asof]);
        eqm($sum['by'][$label($m)], $bs['by']['Total ' . $label($m)], 'the summary: the main account with its sub-account in one line');
        check(!in_array($label($s), $sum['names'], true) && !in_array($label($m) . ' (own entries)', $sum['names'], true), 'and neither the sub-account nor the own entries');
        // the cash flow: the sub-account follows the main account's type (one line with the total in the section)
        $cf = v36Run('cash_flow', ['from' => Report::fiscalYearStart($asof), 'to' => $asof]);
        check(!in_array($label($s), $cf['names'], true) && in_array($label($m), $cf['names'], true), 'Statement of Cash Flows: the main account one line, with its sub-account in it');
        check(!str_contains($cf['notes'], 'Does not tie'), 'and the statement ties');
    } finally {
        DB::update('accounts', ['parent_id' => null], 'id = :id', ['id' => $s['id']]);
        fin_accounts(true);
    }
    // a group with one built-in type and none of the user's: no type heading (Fixed Assets lists its accounts)
    $bs = v36Run('balance_sheet', ['asof' => $asof]);
    if (in_array('Fixed Assets', $bs['names'], true)) {
        check(!in_array('Fixed Asset', $bs['names'], true) && !in_array('Total Fixed Asset', $bs['names'], true), 'Fixed Assets: no heading of the type Fixed Asset');
    }
    // depreciation (a credit Fixed Asset account) stays an operating adjustment
    $cf = v36Run('cash_flow', ['from' => Report::fiscalYearStart($asof), 'to' => $asof]);
    $op = array_search('Net cash provided by operating activities', $cf['names'], true);
    foreach ($cf['names'] as $i => $name) {
        if (str_ends_with($name, '(depreciation)')) {
            check($i < $op, 'depreciation in operating activities: ' . $name);
        }
    }
});
