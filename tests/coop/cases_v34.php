<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v6.xlsx at the level of the program's rules:
 *  - Capacity to Pay and the Credit Investigation of a loan application are the starting data of the borrower's next
 *    loan (updated data shows, otherwise it stays);
 *  - passbook printing as the former program: printing starts on line 1, Start Line Skip / No. of Line Skip leave the
 *    centre fold blank, after Max Lines the next entry goes to line 1 of the next page after the header;
 *  - the loan passbook has its own design (header: Loan Type, Term, Due Date; lines: Date, Reference No., Release,
 *    Loan Payment, Loan Balance, Interest Payment, Fines, Teller ID), separate from the savings passbook;
 *  - the design of a passbook (cover, header, body, footer) set up on Passbook Types, in tups from the Top / Left of
 *    each part; the settings of before carried over; the line numbers printed beside the dates.
 */

/** A passbook type of the kind with the former program's set-up and the default design, changed by $set */
function v34Type(string $name, string $ledger, array $set = [], ?array $layout = null): int
{
    return DB::insert('passbook_types', array_merge(Passbook::defaultSetup($ledger), ['name' => $name, 'ledger' => $ledger, 'skip_lines' => null,
        'layout_json' => json_encode($layout ?? Passbook::defaultLayout($ledger))], $set));
}

/** The text of every printed field of a page: [field => [values]] */
function v34Fields(string $html): array
{
    preg_match_all('/<div class="pb-line" data-f="([a-z_]+)" style="([^"]*)">([^<]*)<\/div>/', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $out[$x[1]][] = html_entity_decode($x[3], ENT_QUOTES);
    }
    return $out;
}

test('Capacity to Pay: the entries saved on a loan application are where the borrower\'s next application starts; updated entries replace them, others stay', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Tajanlangit', 'Louis');
    DB::insert('member_finances', ['member_id' => $m, 'kind' => 'income', 'name' => 'Salary', 'member_amount' => '42000', 'spouse_amount' => '18000']);
    DB::insert('member_finances', ['member_id' => $m, 'kind' => 'expense', 'name' => 'Household', 'member_amount' => '20000', 'spouse_amount' => '0']);
    $c = Loans::capacityStart($m);
    eq($c['source'], 'member', 'a first loan starts from the Financial Information tab');
    eq([$c['data']['monthly_income'], $c['data']['spouse_income'], $c['data']['ndi']], ['42000.00', '18000.00', '40000.00'], 'monthly income, spouse income and NDI of the member');
    $cap = ['monthly_income' => '50000', 'spouse_income' => '25000', 'ndi' => '30000', 'dependents' => 3, 'employer_name' => 'G-Smart Technologies Inc.', 'business_name' => 'Software', 'spouse_name' => 'Sharon Stone'];
    $a1 = loanApp($m, 'REG', '50000', '12', today(), $cap);
    $c = Loans::capacityStart($m);
    eq([$c['source'], $c['app_id']], ['application', $a1], 'the next application starts from the one saved');
    eq($c['data'], ['monthly_income' => '50000.00', 'spouse_income' => '25000.00', 'ndi' => '30000.00', 'dependents' => '3', 'employer_name' => 'G-Smart Technologies Inc.',
        'business_name' => 'Software', 'spouse_name' => 'Sharon Stone'], 'every entry of Capacity to pay is carried: incomes, NDI, dependents, employer, business, spouse');
    check(str_contains(coop_capacity_note($c), Loans::application($a1)['application_no']), 'the form says which application the data came from');
    // the next loan: the income is updated, the rest is left as it was
    $a2 = loanApp($m, 'EMR', '5000', '6', today(), ['monthly_income' => '60000'] + $cap);
    $c = Loans::capacityStart($m);
    eq([$c['app_id'], $c['data']['monthly_income'], $c['data']['dependents'], $c['data']['spouse_name']], [$a2, '60000.00', '3', 'Sharon Stone'], 'the updated income shows; what was not updated stays');
    // the first application edited later (one more dependent): it is now the latest data of the borrower
    $old = Loans::application($a1);
    Loans::saveApplication(['member_id' => $m, 'product_id' => $old['product_id'], 'application_date' => $old['application_date'], 'term' => '12', 'term_unit' => 'months',
        'mode_of_payment' => 'monthly', 'applied_amount' => '50000', 'amort_type' => 'equal_amortization', 'dependents' => 4] + array_diff_key($cap, ['dependents' => 1]), [], [], $a1, 'loan.application');
    DB::run('UPDATE loan_applications SET updated_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = :a', ['a' => $a1]);
    $c = Loans::capacityStart($m);
    eq([$c['app_id'], $c['data']['dependents'], $c['data']['monthly_income']], [$a1, '4', '50000.00'], 'the entries saved last are the start of the next loan');
    check(Loans::application($a1)['updated_at'] !== null, 'saving an application records when its entries were saved');
    eq(Loans::capacityStart($m, $a1)['app_id'], $a2, 'an application being edited is not its own starting point');
});

test('Credit Investigation: the borrower\'s last investigation is where the next one starts (residence, children, income, assets, references); an income without a source name is saved', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Quimpo', 'Rhea');
    $a1 = loanApp($m, 'REG', '40000', '12', today(), ['spouse_name' => 'Mark Quimpo']);
    $first = Loans::ciStart(Loans::application($a1), null);
    eq([$first['prev'], $first['rec']['spouse_name'], $first['lines']], [null, 'Mark Quimpo', []], 'a first investigation starts from the application and the member');
    // the grids as posted: the income has no Source, only the employer and the net income (the screenshot of the workbook)
    $lines = Loans::ciLines(['child' => [['name' => 'Ana', 'detail1' => 'Grade 4', 'detail2' => 'Cogon ES', 'years' => '9'], ['name' => '', 'years' => '0']],
        'income' => [['name' => '', 'detail1' => 'G-Smart Technologies Inc.', 'detail2' => '', 'detail3' => '', 'years' => '', 'amount' => '50000']],
        'asset' => [['name' => 'Toyota Rav4', 'detail1' => 'vehicle', 'amount' => '1800000']], 'reference' => [['name' => '', 'detail1' => '', 'detail2' => '']]]);
    eq(array_column($lines, 'kind'), ['child', 'income', 'asset'], 'the income without a source is kept; empty rows are left out');
    eq([$lines[1]['name'], $lines[1]['detail1'], $lines[1]['amount']], [null, 'G-Smart Technologies Inc.', '50000.00'], 'its employer and net income are saved');
    $ci1 = DB::insert('loan_ci', ['application_id' => $a1, 'member_id' => $m, 'date_reported' => date('Y-m-d', strtotime(today() . ' -10 days')), 'last_updated' => date('Y-m-d', strtotime(today() . ' -10 days')),
        'spouse_name' => 'Mark Quimpo', 'current_address' => 'Purok Caimito, Cogon, Cordova, Cebu', 'previous_address' => 'NA', 'residence_type' => 'Owned', 'house_type' => 'Row house / apartment',
        'building_type' => 'Semi-concrete', 'structure_type' => 'Residential', 'storeys' => 1, 'rooms' => 4, 'lot_area' => '150.00', 'floor_area' => '120.00', 'estimated_value' => '2300000.00',
        'informants' => 'Barangay captain', 'remarks' => 'Good standing in the community', 'conducted_by' => 'CI Officer', 'recommendation' => 'recommended', 'score' => '88']);
    foreach ($lines as $l) {
        DB::insert('loan_ci_lines', ['ci_id' => $ci1] + $l);
    }
    DB::update('loan_applications', ['ci_id' => $ci1], 'id = :a', ['a' => $a1]);
    // the next loan of the borrower
    $a2 = loanApp($m, 'EMR', '5000', '6', today());
    $s = Loans::ciStart(Loans::application($a2), null);
    eq((int)$s['prev']['id'], $ci1, 'the next investigation starts from the last one');
    foreach (['current_address', 'residence_type', 'house_type', 'building_type', 'structure_type', 'storeys', 'rooms', 'lot_area', 'floor_area', 'estimated_value', 'informants', 'spouse_name'] as $k) {
        if ((string)$s['rec'][$k] !== (string)DB::value("SELECT $k FROM loan_ci WHERE id = :c", ['c' => $ci1])) {
            throw new AssertionFailed("$k not carried");
        }
    }
    check(true, 'the residence, the address, the areas and values, the spouse and the informants are carried');
    eq([$s['rec']['date_reported'], isset($s['rec']['recommendation']), isset($s['rec']['remarks'])], [today(), false, false], 'dated today; the findings and the recommendation are the new investigation\'s own');
    eq([count($s['lines']['child']), $s['lines']['income'][0]['detail1'], $s['lines']['asset'][0]['amount']], [1, 'G-Smart Technologies Inc.', '1800000.00'], 'the children, the sources of income and the assets are carried');
    // once saved, the investigation shows its own entries (updated: a second car); the next loan then starts from it
    $ci2 = DB::insert('loan_ci', ['application_id' => $a2, 'member_id' => $m, 'date_reported' => today(), 'last_updated' => today(), 'current_address' => 'Lapu-Lapu City', 'residence_type' => 'Rented',
        'conducted_by' => 'CI Officer', 'recommendation' => 'with_conditions']);
    DB::insert('loan_ci_lines', ['ci_id' => $ci2, 'kind' => 'asset', 'name' => 'Honda Click', 'detail1' => 'motorcycle', 'amount' => '95000.00']);
    $own = Loans::ciStart(Loans::application($a2), DB::row('SELECT * FROM loan_ci WHERE id = :c', ['c' => $ci2]));
    eq([$own['prev'], $own['rec']['current_address'], $own['lines']['asset'][0]['name']], [null, 'Lapu-Lapu City', 'Honda Click'], 'a saved investigation shows its own (updated) entries');
    $a3 = loanApp($m, 'EMR', '3000', '6', today());
    $next = Loans::ciStart(Loans::application($a3), null);
    eq([(int)$next['prev']['id'], $next['rec']['current_address'], $next['rec']['residence_type']], [$ci2, 'Lapu-Lapu City', 'Rented'], 'the updated data is where the following loan starts');
});

test('Passbook lines as the former program: printing starts on line 1, Start Line Skip and No. of Line Skip leave the fold blank, after Max Lines line 1 of the next page with the header', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    // a small page: lines 1 to 9, 4 and 5 skipped (Start Line Skip 4, No. of Line Skip 2)
    $type = v34Type('Test passbook 9 lines', 'savings', ['start_line_skip' => 4, 'line_skip' => 2, 'max_lines' => 9]);
    eq(Passbook::pageLines(Passbook::type($type)), [1, 2, 3, 6, 7, 8, 9], 'a page prints lines 1 to 3 and 6 to 9');
    $m = newMember('Linea', 'Uno');
    $rs = openAccount($m, 'RS', '2026-02-01');
    DB::update('sav_accounts', ['passbook_type_id' => $type], 'id = :id', ['id' => $rs]);
    shift(today());
    for ($i = 1; $i <= 9; $i++) {
        receipt($m, today(), [['kind' => 'savings', 'account_id' => $rs, 'amount' => (string)(100 * $i)]]);
    }
    $no = Savings::account($rs)['account_no'];
    $pb = Passbook::open('savings', $no);
    $plan = Passbook::plan($pb);
    eq([$plan['page'], array_column($plan['lines'], 'line'), $plan['remaining'], $plan['new_page']], [1, [1, 2, 3, 6, 7, 8, 9], 2, true],
        'a new passbook prints from line 1 (not line 13 as before); after line 3 the next entry is on line 6; two wait for the next page');
    eq(Passbook::headerDue($pb, $plan), true, 'the header prints first on a new page');
    $html = Passbook::render($pb, 1, $plan['lines'], true);
    $f = v34Fields($html);
    eq([$f['name'][0] ?? '', $f['number'][0] ?? ''], [Savings::account($rs)['member_name'], $no], 'the header: the account name and number');
    eq($f['line'], ['1', '2', '3', '6', '7', '8', '9'], 'the line numbers print beside the dates');
    check(str_contains($html, 'top:' . rtrim(rtrim(number_format((1230 + 5 * 275) / 1440, 4, '.', ''), '0'), '.') . 'in'), 'line 6 prints at Body Top + 5 intervals (the fold lines keep their space)');
    // a page printed only in part: the rest of the page continues without a header
    Passbook::commit($pb, 1, array_column(array_slice($plan['lines'], 0, 4), 'line', 'id'), 'sav.passbook');
    $pb = Passbook::open('savings', $no);
    $plan = Passbook::plan($pb);
    eq([$plan['page'], array_column($plan['lines'], 'line'), $plan['new_page'], Passbook::headerDue($pb, $plan)], [1, [7, 8, 9], false, false], 'the same page goes on from line 7, without the header');
    Passbook::commit($pb, 1, array_column($plan['lines'], 'line', 'id'), 'sav.passbook');
    $pb = Passbook::open('savings', $no);
    $plan = Passbook::plan($pb);
    eq([$plan['page'], array_column($plan['lines'], 'line'), $plan['new_page'], Passbook::headerDue($pb, $plan)], [2, [1, 2], true, true], 'after Max Lines (9): line 1 of page 2, after the header');
    // header modes of the design: with every printing, never
    $lay = Passbook::defaultLayout('savings');
    $lay['opt']['header'] = 'never';
    DB::update('passbook_types', ['layout_json' => json_encode($lay)], 'id = :id', ['id' => $type]);
    eq(Passbook::headerDue(Passbook::open('savings', $no), $plan), false, 'a preprinted header is never printed');
    $lay['opt']['header'] = 'every';
    DB::update('passbook_types', ['layout_json' => json_encode($lay)], 'id = :id', ['id' => $type]);
    $pb = Passbook::open('savings', $no);
    eq(Passbook::headerDue($pb, ['new_page' => false, 'lines' => [1]]), true, 'or with every printing');
    // reset to line 6 and reprint: the line before it is 3 (the fold lines are passed)
    $sixth = (int)DB::value('SELECT id FROM sav_txns WHERE account_id = :a ORDER BY id LIMIT 1 OFFSET 3', ['a' => $rs]);
    Passbook::resetLine($pb, 1, 6, $sixth, 'Paper jam', 'sav.passbook');
    eq((int)Savings::account($rs)['pb_last_line'], 3, 'the line before line 6 is line 3');
    eq(Passbook::next(Passbook::open('savings', $no)), [1, 6], 'the next entry prints on line 6');
    refuses(fn() => Passbook::resetLine(Passbook::open('savings', $no), 1, 4, null, 'x', 'sav.passbook'), 'never printed on', 'a fold line cannot be the next line');
    // a new passbook starts on page 1, line 1
    Passbook::replace(Passbook::open('savings', $no), 'PB-9', 'Passbook full', 'sav.passbook');
    eq(Passbook::next(Passbook::open('savings', $no)), [1, 1], 'a new passbook starts on page 1, line 1');
    svCloseShift();
});

test('Loan passbook: its own design, the header (Loan Type, Term, Due Date), the release on line 1, Release, Loan Payment, Loan Balance, Interest, Fines and Teller ID, an error correction negative', function () {
    global $ids;
    $t = Passbook::type((int)DB::value("SELECT id FROM passbook_types WHERE name = 'Loan Passbook'"));
    eq([$t['ledger'], (int)$t['start_line_skip'], (int)$t['line_skip'], (int)$t['max_lines'], (int)$t['body_left']], ['loan', 13, 2, 29, 1], 'the installed Loan Passbook has the former program\'s set-up: 13, 2, 29, Left 1');
    eq(array_column($t['layout']['header'], 'f'), ['loan_type', 'term', 'due'], 'its header: Loan Type, Term, Due Date');
    eq(array_column($t['layout']['body'], 'f'), ['line', 'date', 'ref', 'release', 'prin', 'bal', 'int', 'fines', 'teller'], 'its columns: line no., Date, Reference No., Release, Loan Payment, Loan Balance, Interest, Fines, Teller ID');
    check(array_column(Passbook::type((int)DB::value("SELECT id FROM passbook_types WHERE name = 'Regular Passbook'"))['layout']['body'], 'f') !== array_column($t['layout']['body'], 'f'), 'not the design of the savings passbook');
    $x = lcLoan('Pasbuk', 'Loan', '24000');
    $loan = Loans::loan($x['loan']);
    DB::update('loan_accounts', ['pn_no' => '48229'], 'id = :id', ['id' => $loan['id']]);
    lcPenalties($loan['id']);
    asUser('teller1');
    shift(today());
    $p1 = lcPay($x['member'], $loan['id'], '2500');
    $p2 = lcPay($x['member'], $loan['id'], '3000');
    $ec = lcEc((int)lcPayRow((int)$p2['txn_id'])['id']);
    $pb = Passbook::open('loan', (string)$loan['loan_no']);
    $plan = Passbook::plan($pb);
    $kinds = DB::pairs("SELECT id, kind FROM loan_txns WHERE loan_id = :l AND is_voided = 0", ['l' => $loan['id']]);
    check(in_array('penalty', $kinds, true), 'the loan has a penalty charge on its ledger');
    eq(array_column($plan['lines'], 'code'), ['LR', 'LP', 'LP', 'EC'], 'the passbook prints the release, the payments and the correction (the penalty charge is not a passbook line)');
    eq([$plan['page'], $plan['lines'][0]['line'], Passbook::headerDue($pb, $plan)], [1, 1, true], 'the release is printed on line 1, after the header');
    $rel = $plan['lines'][0];
    eq([$rel['ref'], $rel['release'], $rel['bal']], ['48229', Dec::of($loan['principal']), Dec::of($loan['principal'])], 'the release: the P.N. no. as Reference No., the Release and the Loan Balance');
    $pay = $plan['lines'][1];
    $row = lcPayRow((int)$p1['txn_id']);
    eq([$pay['ref'], $pay['prin'], $pay['int'], $pay['fines'], $pay['bal'], $pay['teller']], [(string)$row['or_no'], Dec::of($row['principal_cr']), Dec::of($row['interest_cr']), Dec::of($row['penalty_cr']), Dec::of($row['principal_bal']), (string)$ids['user.teller1']],
        'a payment: OR no., principal, interest and fines paid, the loan balance and the teller ID');
    eqm(Dec::add(Dec::add($pay['prin'], $pay['int'], 2), $pay['fines'], 2), '2500', 'the three columns add up to the payment');
    $e = $plan['lines'][3];
    eq([substr($e['ref'], 0, 3), Dec::isNeg($e['total'])], ['EC ', true], 'the error correction: EC and negative');
    eqm($e['total'], '-3000', 'the whole payment negated');
    $html = Passbook::render($pb, 1, $plan['lines'], true);
    $f = v34Fields($html);
    eq([$f['loan_type'][0], $f['term'][0], $f['due'][0]], [$loan['product_name'], '12mos', date('n/j/y', strtotime($loan['due_date']))], 'the header prints Loan Type, Term (12mos) and Due Date');
    eq([$f['ref'][0], $f['release'][0], $f['line']], ['48229', Dec::money($loan['principal']), ['1', '2', '3', '4']], 'line 1: the reference and the release; every line its number');
    $ecRow = DB::row('SELECT * FROM loan_txns WHERE id = :e', ['e' => $ec['loan_txn_id']]);
    $neg = [];
    foreach (['prin' => 'principal_cr', 'int' => 'interest_cr', 'fines' => 'penalty_cr'] as $col => $db) {
        if (!Dec::isZero($ecRow[$db])) {
            $neg[] = in_array('-' . Dec::money(Dec::abs($ecRow[$db])), $f[$col] ?? [], true);
        }
    }
    check($neg && !in_array(false, $neg, true), 'the correction prints negative in the columns of the payment it negates');
    check(str_contains($html, 'scaleX('), 'condensed print (characters per inch of the design)');
    svCloseShift();
    asUser('gm');
});

test('Passbook design: fields placed in tups from the Top / Left of their part, formats, condensed print; what is not of its kind is left out; the settings of before are carried; test pages', function () {
    // the design as saved: unknown fields and the fields of the other kind are dropped, values kept in range
    $c = Passbook::clean('loan', ['body' => [['f' => 'release', 'x' => 99999, 'y' => -99999, 'w' => -5, 'a' => 'x', 'fmt' => 'bad', 'z' => '-'], ['f' => 'credit'], ['f' => 'nope'], 'junk'],
        'header' => [['f' => 'term', 'fmt' => 'months', 'fs' => 3]], 'opt' => ['header' => 'weird', 'cpi' => 13, 'font' => 'sans']]);
    eq(array_column($c['body'], 'f'), ['release'], 'only the fields of a loan passbook body are kept');
    eq([$c['body'][0]['x'], $c['body'][0]['y'], $c['body'][0]['w'], $c['body'][0]['a'], $c['body'][0]['fmt'], $c['body'][0]['z']], [30000, -14400, 0, 'l', 'comma', '-'], 'positions, width, alignment and format kept in range');
    eq([$c['header'][0]['fmt'], $c['header'][0]['fs'], $c['opt']['header'], $c['opt']['cpi'], $c['opt']['font']], ['months', 0, 'new_page', 0, 'sans'], 'the options checked');
    // formats
    $v = ['date' => '2025-06-12', 'balance' => '0', 'term' => '36.00|months', 'rate' => '18.0000', 'amount' => '1234.5'];
    $fmt = fn(string $sec, array $it) => Passbook::format('loan', $sec, $it + ['z' => '', 't' => ''], $v);
    eq([$fmt('footer', ['f' => 'date', 'fmt' => 'n/j/y']), $fmt('footer', ['f' => 'date', 'fmt' => 'm/d/Y']), $fmt('footer', ['f' => 'date', 'fmt' => 'M j, Y'])], ['6/12/25', '06/12/2025', 'Jun 12, 2025'], 'dates');
    eq([$fmt('header', ['f' => 'term', 'fmt' => 'mos']), $fmt('header', ['f' => 'term', 'fmt' => 'months']), $fmt('header', ['f' => 'rate', 'fmt' => 'pct'])], ['36mos', '36 months', '18.00%'], 'the term and the rate');
    eq([$fmt('header', ['f' => 'amount', 'fmt' => 'comma']), $fmt('header', ['f' => 'amount', 'fmt' => 'plain']), $fmt('header', ['f' => 'amount', 'fmt' => 'whole'])], ['1,234.50', '1234.50', '1,235'], 'amounts');
    eq([$fmt('footer', ['f' => 'balance', 'fmt' => 'comma', 'z' => '-']), $fmt('footer', ['f' => 'balance', 'fmt' => 'comma', 'z' => '0']), $fmt('footer', ['f' => 'balance', 'fmt' => 'comma'])], ['-', '0.00', ''], 'a zero amount: a dash, 0.00 or blank');
    eq(Passbook::format('loan', 'footer', ['f' => 'text', 't' => 'Balance as of {date}: {balance} ({x})', 'fmt' => '', 'z' => ''], ['date' => '2026-10-21', 'balance' => '183075.58']), 'Balance as of 10/21/26: 183,075.58 ({x})', 'a text with the fields of its part');
    // a field at Left 600 / Top 120 of the header prints at Header Left + 600, Header Top + 120
    $lay = Passbook::defaultLayout('loan');
    $lay['header'] = [['f' => 'loan_type', 'x' => 600, 'y' => 120, 'w' => 0, 'a' => 'l', 'fmt' => '', 'z' => '', 't' => '', 'fs' => 0]];
    $lay['opt']['cpi'] = 0;
    $type = v34Type('Test loan design', 'loan', ['header_left' => 300, 'header_top' => 700], $lay);
    $html = Passbook::renderTest(Passbook::type($type), 'page');
    check(str_contains($html, 'data-f="loan_type" style="top:0.5694in;left:0.625in"'), 'the field prints at (700 + 120, 300 + 600) tups = (0.5694, 0.625) in');
    check(!str_contains($html, 'scaleX('), 'without condensed print, the plain width of the font');
    check(substr_count($html, 'data-f="line"') === 27, 'the test page fills every printable line (29 less the two of the fold)');
    check(str_contains(Passbook::renderTest(Passbook::type($type), 'lines'), '>13 ') === false && str_contains(Passbook::renderTest(Passbook::type($type), 'lines'), '>15 '), 'the line-number page leaves 13 and 14 blank');
    // condensed print: 9 pt Courier New is 13.33 characters to the inch; at 20 the characters are narrowed to 0.6667
    $lay['opt']['cpi'] = 20;
    DB::update('passbook_types', ['layout_json' => json_encode($lay)], 'id = :id', ['id' => $type]);
    eq(Passbook::squeeze(Passbook::type($type), 9.0), 0.6667, 'the horizontal scale for 20 characters per inch');
    // the settings of before (version 31 to 33): column positions, header and cover positions, footer text
    $old = DB::insert('passbook_types', ['name' => 'Test passbook of before', 'ledger' => 'savings', 'body_left' => 200, 'header_top' => 700, 'header_left' => 300, 'cover_top' => 900, 'cover_left' => 2400,
        'columns_json' => json_encode(['date' => 200, 'code' => 1400, 'credit' => 2600, 'debit' => 4400, 'balance' => 6200, 'teller' => 8000]), 'header_json' => json_encode(['name' => [700, 500], 'number' => [700, 6300]]),
        'cover_json' => json_encode(['name' => [1500, 3000]]), 'footer_text' => 'Bal {balance}', 'dash_empty' => 1]);
    $l = Passbook::type($old)['layout'];
    eq(array_column($l['body'], 'f'), ['line', 'date', 'code', 'credit', 'debit', 'balance', 'teller'], 'the columns carried, with the line number added beside the date');
    eq([$l['body'][1]['x'], $l['body'][2]['x'], $l['body'][3]['a'], $l['body'][3]['z']], [0, 1200, 'r', '-'], 'at their distance from the first column, amounts right-aligned with the dash');
    eq([$l['header'][0]['x'], $l['header'][1]['x'], $l['cover'][0]['x'], $l['cover'][0]['y'], $l['footer'][0]['t'], $l['opt']['cpi']], [200, 6000, 600, 600, 'Bal {balance}', 0], 'the header, the cover and the footer carried; printed as before (no condensed print)');
    // the migration: "Lines never printed on" 13,14 becomes Start Line Skip 13, No. of Line Skip 2
    $conv = DB::insert('passbook_types', ['name' => 'Test fold of before', 'skip_lines' => '13,14', 'start_line_skip' => 0, 'line_skip' => 1, 'max_lines' => 29]);
    run_sql_file($GLOBALS['pdo'], APP_ROOT . '/database/migrations/2027_01_17_passbook_designer.sql');
    $r = DB::row('SELECT start_line_skip, line_skip, skip_lines FROM passbook_types WHERE id = :id', ['id' => $conv]);
    eq([(int)$r['start_line_skip'], (int)$r['line_skip'], $r['skip_lines']], [13, 2, null], 'the fold of before carried as Start Line Skip 13, No. of Line Skip 2');
    eq(Passbook::ledgerOf(['name' => 'Payroll loan book']), 'loan', 'a type not saved yet: a loan passbook by its name');
});
