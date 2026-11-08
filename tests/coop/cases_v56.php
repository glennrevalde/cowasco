<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v21.xlsx (version 56) at the level of the program's rules:
 * Members & Others > Consolidated Import, Import Members, Import Savings, Import Loans and Upload Bill Balances in one
 * file:
 *  - no fixed format: the columns by their names (the names of the four imports, other names, numbered repeats for a
 *    second account), chosen by the user and remembered; a member with several savings, loans and bill balances on
 *    one row or on the rows under the member; a member with only savings, savings and loans, savings and bill balances;
 *  - the file read into the database (thousands of rows), each member, savings account, loan and bill balance a line
 *    of the review, checked with the checks of the four imports, the file as a whole (the same member, account no.,
 *    loan no. or water account twice) and the water-service account and fixed share capital billing of the member;
 *  - a line corrected (checked again at once) or removed (a member with its lines) and put back;
 *  - saved a few members at a time, each group in one transaction, every line checked again first; resumable;
 *  - the migration 2027_04_25.
 * The page is checked over HTTP (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */
require_once APP_ROOT . '/water/common/importReviewHelpers.php';
require_once APP_ROOT . '/water/common/billBalanceUploadHelpers.php';

/** Reads a CSV text as the page does: the file sent in pieces, then read into the database and made into lines */
function v56Read(string $text, string $name = 'v56.csv'): array
{
    $id = ConsolidatedImport::create($name, strlen($text));
    $b = ConsolidatedImport::batch($id);
    foreach (str_split($text, ConsolidatedImport::CHUNK_BYTES) as $i => $piece) {
        ConsolidatedImport::storeChunk($b, $i, $piece);
    }
    return ConsolidatedImport::parse(ConsolidatedImport::batch($id));
}

/** Checks every line, step by step as the page does; returns the summary */
function v56Check(int $id): array
{
    $n = 0;
    do {
        $r = ConsolidatedImport::checkStep(ConsolidatedImport::batch($id));
    } while (!$r['done'] && ++$n < 1000);
    return ConsolidatedImport::summary(ConsolidatedImport::batch($id));
}

/** Saves every member, step by step as the page does; returns the steps */
function v56Save(int $id): array
{
    $steps = [];
    do {
        $r = ConsolidatedImport::saveStep(ConsolidatedImport::batch($id));
        $steps[] = $r;
    } while (!$r['done'] && count($steps) < 1000);
    return $steps;
}

/** Every line of a kind (all pages), by line no. */
function v56Lines(int $id, string $kind, string $status = 'pending'): array
{
    $out = [];
    $page = 1;
    do {
        $r = ConsolidatedImport::lines(ConsolidatedImport::batch($id), $kind, ['status' => $status], $page);
        foreach ($r['lines'] as $l) {
            $out[$l['line']] = $l;
        }
    } while ($page++ < $r['pages']);
    return $out;
}

/** A CSV text from rows of name => value (the columns of all rows, in order) */
function v56Csv(array $rows): string
{
    $head = [];
    foreach ($rows as $r) {
        foreach (array_keys($r) as $k) {
            $head[$k] = true;
        }
    }
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, array_keys($head), ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($fh, array_map(fn($k) => (string)($r[$k] ?? ''), array_keys($head)), ',', '"', '');
    }
    rewind($fh);
    return (string)stream_get_contents($fh);
}

/** The member, water account and bill codes of the version 56 tests (made once) */
function v56Fixture(): array
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    Auth::actAs($GLOBALS['adminId']);
    $f55 = v55Fixture();
    $f = ['fee' => 'V55 CONNECTION FEE', 'pipe' => 'V55 PIPE', 'class' => $f55['class']];
    // a member on file (the file adds an account to it), a water-service account whose holder is not a member yet
    $f['onfile'] = newMember('Ventura', 'Carmelita');
    $f['onfile_no'] = (string)DB::value('SELECT member_no FROM members WHERE id = :id', ['id' => $f['onfile']]);
    $f['water'] = wt_account(956001, 'Dizon', 'Rogelio', null);
    $f['water2'] = wt_account(956002, 'Estrada', 'Lilia', null);
    $f['cut'] = date('Y-m-d', strtotime(today() . ' -5 days'));
    $f['release'] = Coop::addMonths(today(), -6);
    return $f;
}

/** The members of the main file: only savings; savings and two loans (one on the row under it); savings and bill balances; a member on file; a member with a water account and fixed billing opened */
function v56MainRows(array $f): array
{
    $person = fn(string $no, string $last, string $first, string $birth) => ['Member No' => $no, 'Last Name' => $last, 'First Name' => $first, 'Middle Name' => 'Reyes',
        'Birth Date' => $birth, 'Gender' => 'F', 'Member Type' => 'REG', 'Membership Date' => '2020-02-01', 'Barangay' => 'San Roque', 'City' => 'San Isidro', 'Province' => 'Nueva Ecija'];
    $loan = fn(string $principal, string $paid) => ['Loan Name' => 'Regular Loan', 'Principal' => $principal, 'Term' => '12 months', 'Mode' => 'Monthly',
        'Amortization Type' => 'Diminishing, equal amortization', 'Interest Rate' => '18', 'Release Date' => $f['release'], 'Principal Paid' => $paid, 'Interest Paid' => '1000', 'Penalties Paid' => '0'];
    return [
        // 1. only savings: two accounts on the row (the second numbered)
        // (the savings code as a spreadsheet leaves it, without its leading zero)
        $person('V56-001', 'Santos', 'Maria', '1980-05-10') + ['Savings Code' => '1', 'Savings Balance' => '1,500.00', 'savings2_code' => '03', 'savings2_balance' => '500'],
        // 2. savings and a loan on the row, a second loan on the row under it
        $person('V56-002', 'Bautista', 'Elena', '1979-07-21') + ['Savings Code' => '01', 'Savings Balance' => '800'] + $loan('30000', '10000'),
        $loan('12000', '2000') + ['Loan No' => 'V56-LN-2'],
        // 3. savings and bill balances (the member becomes the same person as the holder of the water account)
        ['Member No' => 'V56-003', 'Name' => 'Dizon, Rogelio M.', 'Birth Date' => '1975-11-02', 'Gender' => 'M', 'Member Type' => 'REG', 'Membership Date' => '2019-03-15',
            'Barangay' => 'Poblacion', 'City' => 'San Isidro', 'Province' => 'Nueva Ecija', 'Water Account' => 'W956001', 'Savings Code' => '03', 'Savings Balance' => '1000',
            'One-Time Balance' => '1500', 'One-Time Code' => $f['fee'], 'Installment Balance' => '900', 'Installment Code' => $f['pipe'], 'Installments' => '3'],
        // 4. a member on file: only a savings account is added
        ['Member No' => $f['onfile_no'], 'Last Name' => 'Ventura', 'First Name' => 'Carmelita', 'Savings Code' => 'Special Savings', 'Savings Balance' => '6000'],
        // 5. a water-service account opened, on fixed share capital billing, with an unpaid share capital balance
        $person('V56-005', 'Navarro', 'Josefina', '1988-01-30') + ['Rate Code' => 'RESIDENTIAL REGULAR / INSTITUTIONAL', 'Pipe Size' => '1/2', 'Savings Code' => '03',
            'Savings Balance' => '300', 'Fixed Share Capital Amount' => '100', 'Share Capital Balance' => '200'],
    ];
}

test('Version 56: Consolidated Import knows the columns by their names (the four imports, other names, a second account numbered), and the user\'s choice is remembered', function () {
    $g = fn(string $h) => ConsolidatedImport::guess($h);
    eq([$g('Member No'), $g('member_code'), $g('Surname'), $g('Date of Birth')], [['member_no', 1], ['member_no', 1], ['last_name', 1], ['birth_date', 1]], 'the member columns, as Import Members names them and in other words');
    eq([$g('Savings Code'), $g('savings_code_2'), $g('savings2_balance'), $g('Savings Balance 3'), $g('sav2_account_no')], [['savings_code', 1], ['savings_code', 2], ['savings_balance', 2], ['savings_balance', 3], ['savings_account_no', 2]],
        'savings: the second and third accounts by their number');
    eq([$g('Principal'), $g('loan_2_principal'), $g('loan2_term'), $g('PN No')], [['loan_principal', 1], ['loan_principal', 2], ['loan_term', 2], ['loan_pn_no', 1]], 'loans, as Import Loans names them');
    eq([$g('Recurring Balance'), $g('Billing Type'), $g('Charge Category'), $g('bill2_balance')], [['recurring_balance', 1], ['bill_type', 1], ['bill_charge_code', 1], ['bill_balance', 2]], 'bill balances, as Upload Bill Balances names them');
    eq([$g('Water Account'), $g('Rate Code'), $g('Fixed Share Capital Amount'), $g('Name')], [['water_account_no', 1], ['water_rate_code', 1], ['fsc_amount', 1], ['member_name', 1]], 'the water-service account, the fixed billing, a whole name');
    eq([$g('Remarks of the former system'), $g('')], [null, null], 'a column not known is not imported');
    // the user says what a column holds: remembered for the next file with the same column name
    $b = v56Read(v56Csv([['Member No' => 'V56-X01', 'Apelyido' => 'Cruz', 'Pangalan' => 'Ana']]), 'v56_names.csv');
    eq(array_column($b['mapping'], 'field'), ['member_no', '', ''], 'the file read: two columns not known');
    $b = ConsolidatedImport::setMapping($b, [0 => ['field' => 'member_no'], 1 => ['field' => 'last_name'], 2 => ['field' => 'first_name']]);
    $m = v56Lines((int)$b['id'], 'member');
    eq([count($m), $m[1]['values']['last_name'] ?? null, $m[1]['values']['first_name'] ?? null], [1, 'Cruz', 'Ana'], 'the lines made again with the columns chosen');
    eq([$g('Apelyido'), $g('PANGALAN')], [['last_name', 1], ['first_name', 1]], 'remembered for the next file');
    refuses(fn() => ConsolidatedImport::setMapping($b, [0 => ['field' => 'member_no'], 1 => ['field' => 'last_name'], 2 => ['field' => 'last_name']]), 'both hold', 'two columns holding the same thing refused');
    ConsolidatedImport::cancel(ConsolidatedImport::batch((int)$b['id']));
    eq(ConsolidatedImport::batch((int)$b['id'])['status'], 'cancelled', 'the file closed');
    eq((int)DB::value('SELECT COUNT(*) FROM coop_import_raw WHERE batch_id = :b', ['b' => $b['id']]), 0, '...its rows gone');
});

test('Version 56: Consolidated Import makes a line for each member, savings account, loan and bill balance: on one row or on the rows under the member, a member with only savings, savings and loans, savings and bill balances', function () {
    $f = v56Fixture();
    $b = v56Read(v56Csv(v56MainRows($f)), 'v56_main.csv');
    eq([(int)$b['file_rows'], $b['status']], [6, 'review'], 'six rows read for review');
    $m = v56Lines((int)$b['id'], 'member');
    $s = v56Lines((int)$b['id'], 'savings');
    $l = v56Lines((int)$b['id'], 'loan');
    $bl = v56Lines((int)$b['id'], 'bill');
    eq([count($m), count($s), count($l), count($bl)], [5, 6, 2, 3], '5 members, 6 savings accounts, 2 loans, 3 bill balances');
    $of = fn(array $lines, int $member) => array_values(array_map(fn($x) => $x['line'], array_filter($lines, fn($x) => $x['member_line'] === $member)));
    $byNo = [];
    foreach ($m as $x) {
        $byNo[$x['values']['member_no']] = $x;
    }
    eq([count($of($s, $byNo['V56-001']['id'])), count($of($l, $byNo['V56-001']['id'])), count($of($bl, $byNo['V56-001']['id']))], [2, 0, 0], 'member 1: two savings accounts on its row, nothing else');
    eq([count($of($s, $byNo['V56-002']['id'])), count($of($l, $byNo['V56-002']['id']))], [1, 2], 'member 2: a savings account and two loans (the second on the row under it)');
    eq([count($of($s, $byNo['V56-003']['id'])), count($of($bl, $byNo['V56-003']['id']))], [1, 2], 'member 3: a savings account and two bill balances (no loan)');
    eq([$byNo['V56-003']['values']['last_name'], $byNo['V56-003']['values']['first_name'], $byNo['V56-003']['values']['middle_name']], ['Dizon', 'Rogelio', 'M.'], 'a whole name split into its parts');
    eq(array_values(array_map(fn($x) => [$x['values']['bill_type'], $x['values']['bill_balance']], array_filter($bl, fn($x) => $x['member_line'] === $byNo['V56-003']['id']))),
        [['Installment', '900'], ['One-Time', '1500']], '...a bill line for each balance column, with its billing type');
    eq(array_column(array_values($l), 'row'), [3, 4], 'each line with the row of the file it came from');

    $sum = v56Check((int)$b['id']);
    check(in_array('Choose the class of the balances: it is required, and its journal entry carries it. (Values for the blank cells)', $sum['general'], true), 'the class of the bill balances\' journal entries asked above the lines');
    ConsolidatedImport::setOptions(ConsolidatedImport::batch((int)$b['id']), ['class_id' => (string)$f['class'], 'cut_off_date' => $f['cut']]);
    $sum = v56Check((int)$b['id']);
    $bad = [];
    foreach (['member', 'savings', 'loan', 'bill'] as $k) {
        foreach (v56Lines((int)$b['id'], $k) as $x) {
            if ($x['errors']) {
                $bad[] = $k . ' ' . $x['line'] . ': ' . implode(' ', $x['errors']);
            }
        }
    }
    eq($bad, [], 'every line good');
    eq([$sum['bad'], $sum['unchecked'], $sum['general'], $sum['ready']], [0, 0, [], true], '...ready to save');
    eqm($sum['kinds']['savings']['total'], '10100.00', 'the savings balances added up');
    $m = v56Lines((int)$b['id'], 'member');
    $notes = [];
    foreach ($m as $x) {
        $notes[$x['values']['member_no']] = implode(' ', $x['notes']) . ' ' . strip_tags($x['info']);
    }
    check(str_contains($notes[$f['onfile_no']], 'is on file: the file adds the member\'s accounts'), 'the member on file: only its accounts are added');
    check(str_contains($notes['V56-003'], 'same person as the holder of water-service account W956001'), 'member 3 becomes the holder of the water account');
    check(str_contains($notes['V56-005'], 'A water-service account is opened') && str_contains($notes['V56-005'], 'fixed share capital billing of 100.00 monthly'), 'member 5: a water account opened, fixed billing set up');
    check(str_contains($notes['V56-003'], 'split into the last name'), 'the split name said on the line');
    $GLOBALS['v56main'] = (int)$b['id'];
});

test('Version 56: Consolidated Import checks every line with the checks of the four imports and the file as a whole; a value corrected in the review is checked again at once', function () {
    $f = v56Fixture();
    $rows = [
        ['Member No' => 'V56-101', 'Last Name' => 'Ramos', 'First Name' => 'Pedro', 'Middle Name' => '-', 'Birth Date' => '1990-13-40', 'Member Type' => 'REG', 'Membership Date' => '2021-01-01',
            'Barangay' => 'A', 'City' => 'B', 'Province' => 'C', 'Savings Code' => 'ZZ', 'Savings Balance' => '100', 'Savings Account No' => 'V56-SA-1'],
        ['Member No' => 'V56-102', 'Last Name' => 'Ramos', 'First Name' => 'Pablo', 'Middle Name' => '-', 'Birth Date' => '1991-02-01', 'Member Type' => 'REG', 'Membership Date' => '2021-01-01',
            'Barangay' => 'A', 'City' => 'B', 'Province' => 'C', 'Savings Code' => '01', 'Savings Balance' => 'abc', 'Savings Account No' => 'V56-SA-1',
            'Loan Name' => 'Regular Loan', 'Principal' => '5000', 'Term' => '', 'Mode' => 'Monthly', 'Amortization Type' => 'Diminishing, equal amortization', 'Interest Rate' => '18',
            'Release Date' => $f['release'], 'Principal Paid' => '0', 'Interest Paid' => '0', 'Penalties Paid' => '0'],
        // the same person as line 1 under another number
        ['Member No' => 'V56-103', 'Last Name' => 'Ramos', 'First Name' => 'Pablo', 'Middle Name' => '-', 'Birth Date' => '1991-02-01', 'Member Type' => 'REG', 'Membership Date' => '2021-01-01',
            'Barangay' => 'A', 'City' => 'B', 'Province' => 'C', 'Savings Code' => '01', 'Savings Balance' => '10'],
        // bill balances with no water-service account; another member's water account
        ['Member No' => 'V56-104', 'Last Name' => 'Lim', 'First Name' => 'Rosa', 'Middle Name' => '-', 'Birth Date' => '1985-02-01', 'Member Type' => 'REG', 'Membership Date' => '2021-01-01',
            'Barangay' => 'A', 'City' => 'B', 'Province' => 'C', 'One-Time Balance' => '50', 'One-Time Code' => $f['fee']],
        ['Member No' => 'V56-105', 'Last Name' => 'Go', 'First Name' => 'Tomas', 'Middle Name' => '-', 'Birth Date' => '1985-02-01', 'Member Type' => 'REG', 'Membership Date' => '2021-01-01',
            'Barangay' => 'A', 'City' => 'B', 'Province' => 'C', 'Water Account' => 'W999999', 'Rate Code' => 'A', 'Pipe Size' => '1/2'],
        // a fixed share capital balance with no fixed billing
        ['Member No' => 'V56-106', 'Last Name' => 'Estrada', 'First Name' => 'Lilia', 'Middle Name' => '-', 'Birth Date' => '1985-02-01', 'Member Type' => 'REG', 'Membership Date' => '2021-01-01',
            'Barangay' => 'A', 'City' => 'B', 'Province' => 'C', 'Water Account' => 'W956002', 'Savings Code' => '03', 'Savings Balance' => '100', 'Share Capital Balance' => '200'],
        // accounts with no member above
    ];
    $b = v56Read(v56Csv(array_merge([['Savings Code' => '01', 'Savings Balance' => '5']], $rows)), 'v56_bad.csv');
    v56Check((int)$b['id']);
    $m = v56Lines((int)$b['id'], 'member');
    $by = [];
    foreach ($m as $x) {
        $by[$x['values']['member_no'] ?? '(none)'] = $x;
    }
    $err = fn(string $no) => implode(' ', $by[$no]['errors']);
    check(str_contains($err('(none)'), 'Enter the last name'), 'accounts with no member above: their member line in red, so they are not lost');
    $first = array_values(array_filter(v56Lines((int)$b['id'], 'savings'), fn($x) => $x['member_line'] === $by['(none)']['id']))[0];
    eq($first['errors'], ['Correct the member\'s line (line 1) first.'], '...its account: the member\'s problem said once, on the member\'s line');
    check(str_contains($err('V56-101'), 'date of birth "1990-13-40" is not a date') && in_array('birth_date', $by['V56-101']['cells'], true), 'line of member 101: the date of birth in red (Import Members\' check)');
    check(str_contains($err('V56-103'), 'The same person (name and date of birth) is also on line') && in_array('last_name', $by['V56-103']['cells'], true), 'the same person twice under two member nos.');
    check(str_contains($err('V56-105'), 'not both'), 'a water account and a rate code to open one: not both');
    $s = v56Lines((int)$b['id'], 'savings');
    $sv = [];
    foreach ($s as $x) {
        $sv[$x['values']['savings_account_no'] ?? $x['line']] = $x;
    }
    $sa1 = array_values(array_filter($s, fn($x) => ($x['values']['savings_account_no'] ?? '') === 'V56-SA-1'));
    check(str_contains(implode(' ', $sa1[0]['errors']), 'ZZ') && in_array('savings_code', $sa1[0]['cells'], true), 'an unknown savings code in red (Import Savings\' check)');
    check(str_contains(implode(' ', $sa1[1]['errors']), '"abc" is not a number') && in_array('savings_balance', $sa1[1]['cells'], true), 'a balance that is not a number in red');
    check(str_contains(implode(' ', $sa1[1]['errors']), 'Account no. V56-SA-1 is also on line ' . $sa1[0]['line']), 'the same account no. on two members');
    $l = array_values(v56Lines((int)$b['id'], 'loan'));
    check($l[0]['errors'] && in_array('loan_term', $l[0]['cells'], true), 'a loan with no term in red (Import Loans\' check)');
    $bl = v56Lines((int)$b['id'], 'bill');
    $bills = [];
    foreach ($bl as $x) {
        $bills[$x['values']['bill_type']] = $x;
    }
    check(str_contains(implode(' ', $bills['One-Time']['errors']), 'no water-service account'), 'a bill balance of a member with no water-service account');
    check(str_contains(implode(' ', $bills['Fixed Share Capital']['errors']), 'not on Fixed Share Capital Billing: give the fixed share capital amount'), 'a share capital balance with no fixed billing: the fixed share capital amount asked');
    $sum = ConsolidatedImport::summary(ConsolidatedImport::batch((int)$b['id']));
    check($sum['bad'] >= 9 && !$sum['ready'], 'not ready while lines are in red');
    refuses(fn() => ConsolidatedImport::saveStep(ConsolidatedImport::batch((int)$b['id'])), 'have a problem', 'saving refused while a line is in red');

    // corrected in the review: checked again at once
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch((int)$b['id']), $by['V56-101']['id'], 'birth_date', '1990-12-04');
    $now = array_values(array_filter($r['lines'], fn($x) => $x['id'] === $by['V56-101']['id']))[0];
    eq([$now['errors'], $now['checked']], [[], true], 'the date of birth corrected: the member line good');
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch((int)$b['id']), $sa1[1]['id'], 'savings_account_no', 'V56-SA-2');
    $after = array_values(array_filter($r['lines'], fn($x) => $x['id'] === $sa1[1]['id']))[0];
    check(!str_contains(implode(' ', $after['errors']), 'also on line'), 'the account no. corrected: no longer twice');
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch((int)$b['id']), $bills['Fixed Share Capital']['member_line'], 'fsc_amount', '150');
    $sc = array_values(array_filter($r['lines'], fn($x) => $x['id'] === $bills['Fixed Share Capital']['id']))[0];
    eq($sc['errors'], [], 'the fixed share capital amount given: the share capital balance good (the fixed billing is set up with the member)');
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch((int)$b['id']), $by['V56-105']['id'], 'water_account_no', '');
    $w = array_values(array_filter($r['lines'], fn($x) => $x['id'] === $by['V56-105']['id']))[0];
    check(str_contains(implode(' ', $w['errors']), 'Rate code "A" is 2 rate codes') && in_array('water_rate_code', $w['cells'], true), 'a rate code of two classifications: its name asked');
    $r = ConsolidatedImport::edit(ConsolidatedImport::batch((int)$b['id']), $by['V56-105']['id'], 'water_rate_code', '1');
    $w = array_values(array_filter($r['lines'], fn($x) => $x['id'] === $by['V56-105']['id']))[0];
    eq($w['errors'], [], 'the rate code chosen from the list: good');
    refuses(fn() => ConsolidatedImport::edit(ConsolidatedImport::batch((int)$b['id']), $by['V56-105']['id'], 'savings_code', '01'), 'not a column of the line', 'a column of another kind of line refused');

    // removed with its lines, put back
    $r = ConsolidatedImport::remove(ConsolidatedImport::batch((int)$b['id']), $by['V56-102']['id']);
    eq(count($r['removed']), 3, 'a member removed with its savings account and loan');
    $sa = array_values(array_filter(v56Lines((int)$b['id'], 'savings'), fn($x) => ($x['values']['savings_account_no'] ?? '') === 'V56-SA-1'));
    eq([count($sa), $sa[0]['errors'] && !str_contains(implode(' ', $sa[0]['errors']), 'also on line')], [1, true], '...the other line with its account no. checked again');
    $p = ConsolidatedImport::lines(ConsolidatedImport::batch((int)$b['id']), 'member', ['status' => 'removed'], 1);
    eq([$p['total'], $p['lines'][0]['id']], [1, $by['V56-102']['id']], 'the removed lines listed apart');
    $r = ConsolidatedImport::restore(ConsolidatedImport::batch((int)$b['id']), $r['removed']);
    eq(count($r['restored']), 3, 'put back');
    check(str_contains(implode(' ', array_values(array_filter(v56Lines((int)$b['id'], 'member'), fn($x) => ($x['values']['member_no'] ?? '') === 'V56-103'))[0]['errors']), 'same person'),
        '...and the member after it is the same person again');
    // the values for the blank cells
    $b2 = ConsolidatedImport::setOptions(ConsolidatedImport::batch((int)$b['id']), ['cut_off_date' => date('Y-m-d', strtotime(today() . ' +3 days'))]);
    $sum = ConsolidatedImport::summary($b2);
    eq([$sum['unchecked'] > 0, in_array('The cut-off date cannot be after today.', $sum['general'], true)], [true, true], 'a cut-off date after today said above the lines; every line to be checked again');
    ConsolidatedImport::cancel(ConsolidatedImport::batch((int)$b['id']));
});

test('Version 56: Consolidated Import saves the members with their savings, loans, water-service accounts, fixed share capital billings and bill balances, a few members at a time', function () {
    $f = v56Fixture();
    $id = (int)$GLOBALS['v56main'];
    $b = ConsolidatedImport::batch($id);
    ConsolidatedImport::setOptions($b, ['cut_off_date' => $f['cut'], 'class_id' => (string)$f['class'], 'savings_txn_code' => 'INI', 'balancing_account' => '3900']);
    $sum = v56Check($id);
    eq([$sum['bad'], $sum['general'], $sum['ready']], [0, [], true], 'checked again with the values of the page: ready');
    $voucher = (int)DB::value('SELECT COALESCE(MAX(id), 0) FROM transactions');
    asUser('loans');
    $miss = ConsolidatedImport::missingRights(ConsolidatedImport::batch($id));
    Auth::actAs($GLOBALS['adminId']);
    check((bool)$miss, 'a user without the rights of the four imports may not save: ' . implode('; ', $miss));
    $steps = v56Save($id);
    $last = end($steps);
    eq([$last['done'], $last['saved'], $last['members']], [true, 5, 5], 'every member saved');
    $b = ConsolidatedImport::batch($id);
    eq($b['status'], 'saved', 'the file saved');
    $mem = fn(string $no) => DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $no]);
    foreach (['V56-001', 'V56-002', 'V56-003', 'V56-005'] as $no) {
        check(($mem($no)['status'] ?? '') === 'active', "member $no made Active");
    }
    $acc = fn(string $no) => DB::all('SELECT a.account_no, a.status, a.balance, p.code FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                                       WHERE m.member_no = :n ORDER BY p.code', ['n' => $no]);
    eq(array_map(fn($a) => [$a['code'], $a['status'], Dec::fix($a['balance'])], $acc('V56-001')), [['01', 'active', '1500.00'], ['03', 'active', '500.00']], 'member 1: two accounts with their balances (the savings code "1" of a spreadsheet read as 01)');
    eq(array_map(fn($a) => [$a['code'], Dec::fix($a['balance'])], $acc($f['onfile_no'])), [['02', '6000.00']], 'the member on file: its account added (the product by its name)');
    eq((string)DB::value('SELECT full_name FROM members WHERE id = :id', ['id' => $f['onfile']]), 'Ventura, Carmelita', '...the member itself not changed');
    $loans = DB::all('SELECT l.loan_no, l.status, l.principal_balance FROM loan_accounts l JOIN members m ON m.id = l.member_id WHERE m.member_no = :n ORDER BY l.principal_balance DESC', ['n' => 'V56-002']);
    eq(array_map(fn($x) => [in_array($x['status'], ['current', 'past_due'], true), Dec::fix($x['principal_balance'])], $loans), [[true, '20000.00'], [true, '10000.00']], 'member 2: two loans with what is left');
    eq($loans[1]['loan_no'], 'V56-LN-2', '...the loan no. of the file kept');
    // member 3: the same person as the water account's holder, the bill balances on it
    $m3 = $mem('V56-003');
    eq([(int)$m3['party_id'], (int)$m3['water_account_id']], [(int)DB::value('SELECT party_id FROM water_accounts WHERE pkey = 956001'), 956001], 'member 3: the same person as the holder of W956001');
    eqm(DB::value("SELECT SUM(total_amount) FROM one_time_bills WHERE member_id = 956001 AND status = 'unpaid'"), '1500.00', '...its one-time balance');
    eq((int)DB::value("SELECT COUNT(*) FROM installment_schedules s JOIN installment_bills b ON b.installment_id = s.installment_id WHERE b.member_id = 956001"), 3, '...its installment balance in 3 installments');
    // member 5: a water-service account opened, fixed share capital billing, the share capital balance on it
    $m5 = $mem('V56-005');
    $w5 = DB::row('SELECT * FROM water_accounts WHERE pkey = :p', ['p' => $m5['water_account_id']]);
    eq([(int)$w5['party_id'], (int)$w5['rc_id'], (int)$w5['pipe_size_id'], $w5['status']], [(int)$m5['party_id'], 1, 2, 'A'], 'member 5: a water-service account opened (rate code, pipe size)');
    $plan = DB::row('SELECT pl.*, a.account_no FROM share_capital_plans pl JOIN sav_accounts a ON a.id = pl.sav_account_id WHERE pl.member_id = :m', ['m' => $m5['id']]);
    eq([Dec::fix($plan['amount']), $plan['frequency'], $plan['start_date'], $plan['batch_no']], ['100.00', 'monthly', date('Y-m-d', strtotime($f['cut'] . ' +1 day')), $b['batch_no']], '...on fixed share capital billing from the day after the cut-off');
    $scBill = DB::row('SELECT * FROM share_capital_bills WHERE plan_id = :p AND bill_date = :d', ['p' => $plan['plan_id'], 'd' => $f['cut']]);
    eqm($scBill['amount'] ?? null, '200.00', '...the unpaid share capital a bill of the cut-off date');
    // the lines say what they became; the ledger balanced
    $lines = v56Lines($id, 'savings', 'saved');
    check(count($lines) === 6 && !array_filter($lines, fn($x) => !str_starts_with($x['saved_ref'], 'Account ')), 'each savings line with its account');
    $bills = v56Lines($id, 'bill', 'saved');
    check(count($bills) === 3 && !array_filter($bills, fn($x) => !str_starts_with($x['saved_ref'], 'BAL-')), 'each bill line with its upload');
    eqm(DB::value('SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM journal_lines jl JOIN transactions t ON t.id = jl.txn_id WHERE t.id > :v', ['v' => $voucher]), '0', 'the vouchers balanced');
    check((int)DB::value('SELECT COUNT(*) FROM transactions WHERE id > :v', ['v' => $voucher]) >= 3, '...a voucher for the savings, one for the loans, the invoices of the bill balances');
    check((bool)DB::value("SELECT id FROM audit_trail WHERE page_key = 'coop.consolidated_import' AND record_ref LIKE :r", ['r' => $b['batch_no'] . '%saved']), 'the audit trail says what was saved');
    refuses(fn() => ConsolidatedImport::saveStep(ConsolidatedImport::batch($id)), 'saved already', 'a file is saved once');
    refuses(fn() => ConsolidatedImport::edit(ConsolidatedImport::batch($id), (int)array_key_first($lines), 'savings_balance', '1'), 'cannot be changed', 'its lines are not changed afterwards');
    // the same file again: every member on file now, every account in red
    $again = v56Read(v56Csv(v56MainRows($f)), 'v56_main_again.csv');
    $sum = v56Check((int)$again['id']);
    check($sum['bad'] > 0, 'the same file read again: lines in red (the accounts on file)');
    ConsolidatedImport::cancel(ConsolidatedImport::batch((int)$again['id']));
});

test('Version 56: Consolidated Import saves thousands of rows: read in pieces, checked and saved in steps, each group of members in one transaction (a problem at saving keeps the members before it)', function () {
    Auth::actAs($GLOBALS['adminId']);
    $n = 600;
    $rows = [];
    for ($i = 1; $i <= $n; $i++) {
        $no = sprintf('V56B-%04d', $i);
        $rows[] = ['member_no' => $no, 'last_name' => 'Bulk' . $i, 'first_name' => 'Member', 'middle_name' => '-', 'birth_date' => '1980-01-01', 'member_type' => 'REG', 'membership_date' => '2020-01-01',
            'barangay' => 'San Roque', 'city' => 'San Isidro', 'province' => 'Nueva Ecija', 'savings_code' => '01', 'savings_balance' => (string)(100 + $i)];
        if ($i % 3 === 0) {
            $rows[] = ['savings_code' => '03', 'savings_balance' => '200'];
        }
    }
    $text = v56Csv($rows);
    $t0 = microtime(true);
    $b = v56Read($text, 'v56_bulk.csv');
    $read = microtime(true) - $t0;
    eq([(int)$b['file_rows'], (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'savings'", ['b' => $b['id']])], [$n + intdiv($n, 3), $n + intdiv($n, 3)], 'every row read, a line for each account');
    $t0 = microtime(true);
    $steps = 0;
    do {
        $s0 = microtime(true);
        $r = ConsolidatedImport::checkStep(ConsolidatedImport::batch((int)$b['id']));
        check(microtime(true) - $s0 < ConsolidatedImport::STEP_SECONDS + 4, 'a check step stays short');
        $steps++;
    } while (!$r['done']);
    $sum = ConsolidatedImport::summary(ConsolidatedImport::batch((int)$b['id']));
    eq([$sum['bad'], $sum['ready'], $r['checked']], [0, true, $n], 'every line checked, in ' . $steps . ' step(s), ' . round(microtime(true) - $t0, 1) . 's');
    // a member no. taken by someone else meanwhile: that group stops, the members before it are saved
    $taken = sprintf('V56B-%04d', ConsolidatedImport::SAVE_MEMBERS + 5);
    $other = newMember('Takennumber', 'Vlsix');
    DB::run('UPDATE members SET member_no = :n WHERE id = :id', ['n' => $taken, 'id' => $other]);
    $saved = 0;
    try {
        do {
            $r = ConsolidatedImport::saveStep(ConsolidatedImport::batch((int)$b['id']));
        } while (!$r['done']);
        check(false, 'the saving should have stopped');
    } catch (UserError $e) {
        check(str_contains($e->getMessage(), 'Saving stopped') && str_contains($e->getMessage(), $taken), 'saving stopped on the member no. taken: ' . $e->getMessage());
    }
    $saved = (int)DB::value("SELECT COUNT(*) FROM coop_import_lines WHERE batch_id = :b AND kind = 'member' AND status = 'saved'", ['b' => $b['id']]);
    eq($saved, ConsolidatedImport::SAVE_MEMBERS, 'the members before that group saved, nothing of the group');
    eq((int)DB::value("SELECT COUNT(*) FROM members WHERE member_no LIKE 'V56B-%'"), ConsolidatedImport::SAVE_MEMBERS + 1, '...on file (and the other member of that number)');
    $line = array_values(array_filter(v56Lines((int)$b['id'], 'member'), fn($x) => ($x['values']['member_no'] ?? '') === $taken))[0];
    check(str_contains(implode(' ', $line['errors']), 'is on file as') || str_contains(implode(' ', $line['errors']), 'on file'), 'the line in red with the reason');
    ConsolidatedImport::remove(ConsolidatedImport::batch((int)$b['id']), $line['id']);
    $t0 = microtime(true);
    $steps = v56Save((int)$b['id']);
    $last = end($steps);
    eq([$last['done'], $last['saved']], [true, $n - 1], 'saving went on where it stopped: every member saved but the one removed');
    $expect = 0;
    for ($i = 1; $i <= $n; $i++) {
        $expect += $i === ConsolidatedImport::SAVE_MEMBERS + 5 ? 0 : 1 + ($i % 3 === 0 ? 1 : 0);
    }
    eq((int)DB::value("SELECT COUNT(*) FROM sav_accounts a JOIN members m ON m.id = a.member_id WHERE m.member_no LIKE 'V56B-%' AND m.id <> :o AND a.status = 'active'", ['o' => $other]), $expect, 'every account opened');
    check(true, 'read in ' . round($read, 1) . 's, saved in ' . count($steps) . ' step(s), ' . round(microtime(true) - $t0, 1) . 's');
});

test('Version 56: the migration 2027_04_25 adds the page (with the rights of Member Center) and the tables, and may be run twice', function () {
    $page = DB::row("SELECT p.*, o.sort_order AS import_sort FROM pages p JOIN pages o ON o.page_key = 'coop.import' WHERE p.page_key = 'coop.consolidated_import'");
    eq([$page['name'], (int)$page['sort_order'] > (int)$page['import_sort'], (int)$page['show_in_menu'], $page['menu_group']], ['Consolidated Import', true, 1, DB::value("SELECT menu_group FROM pages WHERE page_key = 'coop.import'")], 'the page after Opening Balances Import, in its menu group');
    eq(Access::pageDef('coop.consolidated_import')['file'], 'coop/consolidated_import.php', 'its file');
    $roles = fn(string $k) => DB::column("SELECT r.role_id FROM role_page_access r JOIN pages p ON p.id = r.page_id WHERE p.page_key = :k AND r.allowed = 1 ORDER BY r.role_id", ['k' => $k]);
    eq($roles('coop.consolidated_import'), $roles('coop.members'), 'given to the roles that have Member Center');
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_04_25_consolidated_import.sql');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key = 'coop.consolidated_import'"), 1, 'run twice: one page');
    check(Schema::pending() === [], 'nothing pending');
});
