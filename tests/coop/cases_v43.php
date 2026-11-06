<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v14.xlsx (version 43) at the level of the program's rules:
 *  - Term (days) of Fixed Share Capital Billing: the due date of each bill, none (open) when blank;
 *  - Batch Share Capital Billing: several members with the same amount, frequency, start date, posting day and term,
 *    every one or none;
 *  - the import of Batch Share Capital Billing: the lines checked (the account, the name, the values of the form for
 *    the blank cells, a leading zero lost in Excel), then posted, every line or none;
 *  - the import of One-Time Billing charges: each line its own charge category and amount, the member discount, the
 *    water bill's code refused, then one bill and invoice per line, every line or none;
 *  - the Excel reader of the imports; the migration 2027_03_21.
 * The pages (the batch form, the review of a file, the cashier) are checked over HTTP (tests/water/e2e_http.php) and
 * in the browser (docs/TEST_EVIDENCE.md).
 */
require_once APP_ROOT . '/water/common/oneTimeBillImportHelpers.php';
require_once APP_ROOT . '/water/common/importReviewHelpers.php';

test('Term (days) of Fixed Share Capital Billing: the due date is the posting date plus the term; blank, the bill has no due date (open)', function () {
    eq([ShareCapitalBilling::term(''), ShareCapitalBilling::term(null), ShareCapitalBilling::term('0'), ShareCapitalBilling::term(' 15 ')], [null, null, 0, 15], 'blank is open; 0 is due on the posting date');
    refuses(fn() => ShareCapitalBilling::term('7.5'), 'whole number', 'not a whole number of days');
    refuses(fn() => ShareCapitalBilling::term('3651'), '3650', 'more than ten years');
    eq([ShareCapitalBilling::dueDate('2027-01-31', 7), ShareCapitalBilling::dueDate('2027-02-28', 1), ShareCapitalBilling::dueDate('2027-01-31', null)], ['2027-02-07', '2027-03-01', null], 'the due dates');
    $y = (int)date('Y') + 2;
    $a = v42Member('Term', 'Teresa', 943001);
    $id = ShareCapitalBilling::save(['member_id' => $a['member'], 'sav_account_id' => $a['sc'], 'frequency' => 'monthly', 'start_date' => "$y-01-31", 'posting_day' => 31,
        'amount' => '50', 'term_days' => '10'], null, 'water.share_capital_billing');
    eq((string)ShareCapitalBilling::plan($id)['term_days'], '10', 'the term of the billing');
    ShareCapitalBilling::postDue(ShareCapitalBilling::dayIn("$y-02-01", 31), [$id]);
    eq(array_map(fn($b) => [$b['bill_date'], $b['due_date'], (string)$b['term_days']], DB::all('SELECT bill_date, due_date, term_days FROM share_capital_bills WHERE plan_id = :p ORDER BY bill_date', ['p' => $id])),
        [["$y-01-31", date('Y-m-d', strtotime("$y-01-31 +10 days")), '10'], [ShareCapitalBilling::dayIn("$y-02-01", 31), date('Y-m-d', strtotime(ShareCapitalBilling::dayIn("$y-02-01", 31) . ' +10 days')), '10']],
        'each bill due ten days after its posting date');
    // the term made blank: the bills posted from then on have no due date; those posted keep theirs
    ShareCapitalBilling::save(['member_id' => $a['member'], 'sav_account_id' => $a['sc'], 'frequency' => 'monthly', 'start_date' => "$y-01-31", 'posting_day' => 31,
        'amount' => '50', 'term_days' => ''], $id, 'water.share_capital_billing');
    ShareCapitalBilling::postDue("$y-03-31", [$id]);
    eq(DB::row('SELECT due_date, term_days FROM share_capital_bills WHERE plan_id = :p AND bill_date = :d', ['p' => $id, 'd' => "$y-03-31"]), ['due_date' => null, 'term_days' => null], 'open from then on');
    check(DB::value('SELECT due_date FROM share_capital_bills WHERE plan_id = :p AND bill_date = :d', ['p' => $id, 'd' => "$y-01-31"]) !== null, 'the bill posted before keeps its due date');
    ShareCapitalBilling::delete($id, 'Test done', 'water.share_capital_billing');
});

test('Batch Share Capital Billing: the members ticked get the same amount, frequency, start date, posting day and term, every one or none', function () {
    $y = (int)date('Y') + 2;
    $m1 = v42Member('Bond', 'Jim', 943002);
    $m2 = v42Member('James', 'Leo', 943003);
    $m3 = v42Member('Jordan', 'Mike', 0);
    $common = ['amount' => '50', 'frequency' => 'monthly', 'start_date' => "$y-01-01", 'posting_day' => '1', 'term_days' => '7'];
    // one account already on fixed billing: nothing is saved
    $taken = ShareCapitalBilling::save(['member_id' => $m3['member'], 'sav_account_id' => $m3['sc'], 'frequency' => 'monthly', 'start_date' => "$y-01-01", 'posting_day' => 1, 'amount' => '20'], null, 'water.share_capital_billing');
    $before = (int)DB::value('SELECT COUNT(*) FROM share_capital_plans');
    refuses(fn() => ShareCapitalBilling::saveBatch([['sav_account_id' => $m1['sc'], '_line' => 1], ['sav_account_id' => $m3['sc'], '_line' => 2]], $common, 'batch', 'water.share_capital_billing'),
        'already on fixed billing', 'a batch with an account already on fixed billing');
    eq((int)DB::value('SELECT COUNT(*) FROM share_capital_plans'), $before, 'nothing saved (every one or none)');
    refuses(fn() => ShareCapitalBilling::saveBatch([], $common, 'batch', 'water.share_capital_billing'), 'Tick at least one member', 'nobody ticked');
    refuses(fn() => ShareCapitalBilling::saveBatch([['sav_account_id' => $m1['sc'], '_line' => 1]], ['amount' => ''] + $common, 'batch', 'water.share_capital_billing'), 'Enter the amount', 'no amount');
    $r = ShareCapitalBilling::saveBatch([['sav_account_id' => $m1['sc'], '_line' => 1], ['sav_account_id' => $m2['sc'], '_line' => 2]], $common, 'batch', 'water.share_capital_billing');
    eq(count($r['ids']), 2, 'two members on fixed billing');
    check(str_starts_with($r['batch_no'], 'SCB-'), 'a batch number (' . $r['batch_no'] . ')');
    eq($r['amount'], '100.00', 'a posting: 2 x 50.00');
    foreach ($r['ids'] as $id) {
        $p = ShareCapitalBilling::plan($id);
        eq([$p['amount'], $p['frequency'], $p['start_date'], (int)$p['posting_day'], (string)$p['term_days'], $p['batch_no']], ['50.00', 'monthly', "$y-01-01", 1, '7', $r['batch_no']], 'the same billing for each');
    }
    $GLOBALS['v43'] = ['taken' => $taken, 'batch' => $r['ids']];
});

test('Import of Batch Share Capital Billing: the lines checked (account, name, the form\'s values for blank cells, OPEN, a lost leading zero, duplicates), then posted every one or none', function () {
    $y = (int)date('Y') + 2;
    $a = v42Member('Curry', 'Stephen', 943004);
    $b = v42Member('Pippen', 'Scottie', 943005);
    $c = v42Member('Rodman', 'Dennis', 0);
    $noA = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :i', ['i' => $a['sc']]);
    $noB = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :i', ['i' => $b['sc']]);
    $noC = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :i', ['i' => $c['sc']]);
    check($noA[0] === '0', 'the account numbers start with a zero (' . $noA . ')');
    $common = ['amount' => '60', 'frequency' => 'monthly', 'start_date' => "$y-01-01", 'posting_day' => '1', 'term_days' => '7'];
    $rows = [
        ['_line' => 2, 'account_no' => ltrim($noA, '0'), 'member_name' => 'Stephen Curry', 'amount' => '75', 'frequency' => '', 'start_date' => '', 'posting_day' => '', 'term_days' => ''],
        ['_line' => 3, 'account_no' => $noB, 'member_name' => 'Pippen, Scottie', 'amount' => '', 'frequency' => 'Quarterly', 'start_date' => "01/15/$y", 'posting_day' => '15', 'term_days' => 'OPEN'],
        ['_line' => 4, 'account_no' => $noC, 'member_name' => 'Wrong Name', 'amount' => '50', 'frequency' => 'weekly', 'start_date' => '', 'posting_day' => '32', 'term_days' => 'x'],
        ['_line' => 5, 'account_no' => $noB, 'member_name' => '', 'amount' => '-5', 'frequency' => '', 'start_date' => '', 'posting_day' => '', 'term_days' => ''],
        ['_line' => 6, 'account_no' => '99999999999', 'member_name' => '', 'amount' => '', 'frequency' => '', 'start_date' => '', 'posting_day' => '', 'term_days' => ''],
        ['_line' => 7, 'account_no' => (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :i', ['i' => $GLOBALS['v43']['batch'][0] ? ShareCapitalBilling::plan($GLOBALS['v43']['batch'][0])['sav_account_id'] : 0]), 'member_name' => '', 'amount' => '', 'frequency' => '', 'start_date' => '', 'posting_day' => '', 'term_days' => ''],
    ];
    $checked = ShareCapitalBilling::checkRows($rows, $common);
    $by = array_column($checked, null, '_line');
    eq($by[2]['_errors'], [], 'line 2 good');
    eq([$by[2]['_ok']['sav_account_id'], $by[2]['_ok']['amount'], $by[2]['_ok']['frequency'], $by[2]['_ok']['term_days']], [$a['sc'], '75.00', 'monthly', '7'], 'its own amount, the form\'s frequency and term');
    check((bool)array_filter($by[2]['_notes'], fn($n) => str_contains($n, 'leading zero')), 'the account read with its leading zero');
    eq($by[3]['_errors'], [], 'line 3 good');
    eq([$by[3]['_ok']['amount'], $by[3]['_ok']['frequency'], $by[3]['_ok']['start_date'], $by[3]['_ok']['posting_day'], $by[3]['_ok']['term_days']], ['60.00', 'quarterly', "$y-01-15", 15, ''],
        'the form\'s amount, its own quarterly, date as MM/DD/YYYY, OPEN = no due date');
    $msg4 = implode(' | ', $by[4]['_errors']);
    foreach (['not Wrong Name', 'weekly', 'posting day "32"', 'Term (days)'] as $needle) {
        check(str_contains($msg4, $needle), 'line 4: ' . $needle . ' (' . $msg4 . ')');
    }
    check(str_contains(implode(' ', $by[5]['_errors']), 'also on line 3') && str_contains(implode(' ', $by[5]['_errors']), 'not more than zero'), 'line 5: the same account twice, an amount below zero');
    check(str_contains(implode(' ', $by[6]['_errors']), 'No account 99999999999'), 'line 6: no such account');
    check(str_contains(implode(' ', $by[7]['_errors']), 'already on fixed billing'), 'line 7: already on fixed billing');
    $before = (int)DB::value('SELECT COUNT(*) FROM share_capital_plans');
    refuses(fn() => ShareCapitalBilling::saveBatch($rows, $common, 'import', 'water.share_capital_billing'), 'rows have a problem', 'posting with lines in red');
    eq((int)DB::value('SELECT COUNT(*) FROM share_capital_plans'), $before, 'nothing saved');
    // the lines in red removed, the rest posted
    $r = ShareCapitalBilling::saveBatch([$rows[0], $rows[1]], $common, 'import', 'water.share_capital_billing');
    eq(count($r['ids']), 2, 'two members imported');
    check(str_starts_with($r['batch_no'], 'SCI-'), 'an import batch number');
    eq(ShareCapitalBilling::plan($r['ids'][1])['term_days'], null, 'OPEN saved as no due date');
    eq(ShareCapitalBilling::importSamples() !== [], true, 'the template has example lines');
});

test('The Excel reader of the imports: text kept as typed (leading zeros), numbers as stored, a date cell as YYYY-MM-DD, the first worksheet', function () {
    check(XlsxReader::available(), 'the zip extension is on');
    $path = tempnam(sys_get_temp_dir(), 'xlsxt');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Import" sheetId="1" r:id="rId7"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/data.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>account_no</t></si><si><t>amount</t></si><si><t>start_date</t></si><si><r><t>Bond, </t></r><r><t>James</t></r></si><si><t>01100001031</t></si></sst>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="mm/dd/yyyy"/></numFmts><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="2"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/data.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="E1" t="s"><v>3</v></c></row>'
        . '<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" s="2"><v>50.5</v></c><c r="C2" s="1"><v>46327</v></c></row>'
        . '<row r="4"><c r="A4"><v>10001000111</v></c><c r="B4" t="inlineStr"><is><t>12.30</t></is></c><c r="C4" t="str"><v>2026-12-15</v></c></row>'
        . '</sheetData></worksheet>');
    $zip->close();
    $rows = XlsxReader::rows($path);
    eq($rows, [['account_no', 'amount', 'start_date', '', 'Bond, James'], ['01100001031', '50.5', '2026-11-01'], ['10001000111', '12.30', '2026-12-15']],
        'the cells as text: the leading zero kept, a date serial as a date, a gap column empty, rich text joined, row 3 (empty) left out');
    $read = CsvReview::readText(XlsxReader::csvText($path), ShareCapitalBilling::IMPORT_COLUMNS, ShareCapitalBilling::IMPORT_ALIASES);
    eq([$read[0]['account_no'], $read[0]['amount'], $read[0]['start_date'], $read[1]['account_no']], ['01100001031', '50.5', '2026-11-01', '10001000111'], 'read as an import file');
    @unlink($path);
    file_put_contents($path, 'not a zip');
    refuses(fn() => XlsxReader::rows($path), 'not an Excel workbook', 'a file that is not a workbook');
    @unlink($path);
});

test('Import of One-Time Billing charges: each line its own charge category and amount, the price of the bill code for a blank amount, the water bill refused; one bill and invoice per line, every line or none', function () {
    $pdo = DB::pdo();
    $wt = $GLOBALS['wt'];
    $fee = DB::insert('bill_codes', ['code' => 'CONNECTION FEE', 'description' => 'New service connection', 'type' => 'Service', 'ar_account' => $wt['ar'], 'income_account' => $wt['sales'],
        'cost_price' => 0, 'sales_price' => '1500.00', 'allow_member_discount' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    DB::insert('bill_codes', ['code' => 'MAINTENANCE FEE', 'description' => 'Line maintenance', 'type' => 'Charges', 'ar_account' => $wt['ar'], 'income_account' => $wt['sales'],
        'cost_price' => 0, 'sales_price' => '0.00', 'allow_member_discount' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $w1 = wt_account(943101, 'Jordan', 'Michael', null);
    $w2 = wt_account(943102, 'Bryant', 'Kobe', null);
    $common = ['charge_code' => '', 'amount' => '', 'bill_date' => today(), 'term_days' => '15'];
    $rows = [
        ['_line' => 2, 'account_no' => 'W943101', 'member_name' => 'Michael Jordan', 'charge_code' => 'connection fee', 'amount' => '', 'quantity' => '', 'bill_date' => '', 'term_days' => ''],
        ['_line' => 3, 'account_no' => 'W943102', 'member_name' => 'Bryant, Kobe', 'charge_code' => 'MAINTENANCE FEE', 'amount' => '88.50', 'quantity' => '', 'bill_date' => '', 'term_days' => 'OPEN'],
        ['_line' => 4, 'account_no' => 'W943102', 'member_name' => '', 'charge_code' => 'WATER BILL', 'amount' => '100', 'quantity' => '', 'bill_date' => '', 'term_days' => ''],
        ['_line' => 5, 'account_no' => 'W943102', 'member_name' => '', 'charge_code' => 'MAINTENANCE FEE', 'amount' => '', 'quantity' => '', 'bill_date' => '', 'term_days' => ''],
        ['_line' => 6, 'account_no' => 'W000000', 'member_name' => 'Larry Bird', 'charge_code' => 'NOT A CODE', 'amount' => '10', 'quantity' => '', 'bill_date' => '13/45/2026', 'term_days' => ''],
    ];
    $by = array_column(otbi_check($pdo, $rows, $common), null, '_line');
    eq($by[2]['_errors'], [], 'line 2 good');
    eq([$by[2]['_ok']['bill_code_id'], $by[2]['_ok']['gross'], $by[2]['_ok']['term_days']], [$fee, 1500.0, 15], 'the code name in any case, the price of the bill code, the form\'s term');
    eq([$by[3]['_errors'], $by[3]['_ok']['gross'], $by[3]['_ok']['term_days']], [[], 88.5, null], 'line 3: its own amount, OPEN');
    check(str_contains(implode(' ', $by[4]['_errors']), 'water bill\'s'), 'line 4: the water bill\'s code refused');
    check(str_contains(implode(' ', $by[5]['_errors']), 'no price'), 'line 5: no amount and no price');
    $e6 = implode(' | ', $by[6]['_errors']);
    check(str_contains($e6, 'No water-service account') && str_contains($e6, 'NOT A CODE') && str_contains($e6, 'not a date'), 'line 6: the account, the code and the date (' . $e6 . ')');
    $before = (int)DB::value('SELECT COUNT(*) FROM one_time_bills');
    $classId = (int)DB::value('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1');
    refuses(fn() => otbi_post($pdo, $rows, $common, $classId, (int)$GLOBALS['adminId'], 'test.csv'), 'lines have a problem', 'issuing with lines in red');
    eq((int)DB::value('SELECT COUNT(*) FROM one_time_bills'), $before, 'nothing issued');
    try {
        otbi_post($pdo, [$rows[0]], $common, 0, (int)$GLOBALS['adminId'], 'test.csv');
        check(false, 'issued without a class');
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), 'class'), 'the class is required (' . $e->getMessage() . ')');
    }
    $r = otbi_post($pdo, [$rows[0], $rows[1]], $common, $classId, (int)$GLOBALS['adminId'], 'test.csv');
    eq([$r['count'], $r['total']], [2, 1588.5], 'two bills, 1,500.00 + 88.50');
    $bills = DB::all('SELECT * FROM one_time_bills ORDER BY bill_id DESC LIMIT 2');
    $b1 = $bills[1];
    $b2 = $bills[0];
    eq([(int)$b1['member_id'], (int)$b1['bill_code_id'], $b1['total_amount'], $b1['due_date'], (int)$b1['term_days']], [$w1, $fee, '1500.00', date('Y-m-d', strtotime(today() . ' +15 days')), 15], 'the connection fee, due in 15 days');
    eq([(int)$b2['member_id'], $b2['total_amount'], $b2['due_date']], [$w2, '88.50', null], 'the maintenance fee, no due date');
    foreach ([$b1, $b2] as $b) {
        $p = WaterPosting::byEvent('OTB-' . $b['bill_id']) ?: DB::row("SELECT * FROM water_postings WHERE source_type = 'one_time_bill' AND source_id = :s ORDER BY id LIMIT 1", ['s' => (string)$b['bill_id']]);
        check($p && $p['status'] === 'posted', 'bill ' . $b['bill_id'] . ' posted to the ledger as an invoice');
        eq((float)Txn::load((int)$p['txn_id'])['total'], (float)$b['total_amount'], 'its invoice for the amount');
    }
    assertLedgerBalanced('after the import of one-time charges');
    check(count(otbi_samples($pdo)) >= 1, 'the template has example lines');
});

test('The review of an import refuses what posting would refuse: a date not in a known form, an amount too large, an account number a lost zero makes ambiguous, an account ID for an account that has a number, a bill code whose accounts cannot post, a class not chosen', function () {
    // dates: the forms a person or a spreadsheet writes, nothing guessed
    eq(array_map(fn($v) => CsvReview::dateStrict($v), ['2027-01-31', '01/31/2027', '1/31/27', '2027/01/31', '01-31-2027', '31-Jan-2027', 'Jan 31, 2027', 'January 31 2027']),
        array_fill(0, 8, '2027-01-31'), 'the date forms understood');
    eq(array_map(fn($v) => CsvReview::dateStrict($v), ['x', '2027', 'Jan', '2027-02-30', '13/01/2027', 'next friday']), array_fill(0, 6, null), 'nothing else is taken for a date');
    // Batch Share Capital Billing
    $y = (int)date('Y') + 2;
    $a = v42Member('Ambiguous', 'One', 0);
    $b = v42Member('Ambiguous', 'Two', 0);
    $noA = (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :i', ['i' => $a['sc']]);
    DB::run('UPDATE sav_accounts SET account_no = :n WHERE id = :i', ['n' => '0' . $noA, 'i' => $b['sc']]);
    $common = ['amount' => '50', 'frequency' => 'monthly', 'start_date' => "$y-01-01", 'posting_day' => '1', 'term_days' => ''];
    $by = array_column(ShareCapitalBilling::checkRows([
        ['_line' => 2, 'account_no' => ltrim($noA, '0'), 'amount' => '', 'start_date' => ''],
        ['_line' => 3, 'account_no' => $noA, 'amount' => '99,999,999,999,999', 'start_date' => ''],
        ['_line' => 4, 'account_no' => '0' . $noA, 'amount' => '', 'start_date' => 'x'],
    ], $common), null, '_line');
    $e2 = implode(' ', $by[2]['_errors']);
    check(str_contains($e2, 'could be') && str_contains($e2, ' ' . $noA) && str_contains($e2, '0' . $noA), 'a lost zero fitting two accounts is not guessed (' . $e2 . ')');
    check(str_contains(implode(' ', $by[3]['_errors']), 'more than 999,999,999.99'), 'an amount above the largest refused at review (' . implode(' ', $by[3]['_errors']) . ')');
    check(str_contains(implode(' ', $by[4]['_errors']), 'start date "x" is not a date'), '"x" is not a start date');
    refuses(fn() => ShareCapitalBilling::save(['member_id' => $a['member'], 'sav_account_id' => $a['sc'], 'frequency' => 'monthly', 'start_date' => "$y-01-01", 'posting_day' => 1,
        'amount' => '1000000000'], null, 'water.share_capital_billing'), 'may not be more than', 'save() refuses it too');
    // One-Time Billing charges
    $pdo = DB::pdo();
    $wt = $GLOBALS['wt'];
    $bad = DB::insert('bill_codes', ['code' => 'MISMAPPED FEE', 'description' => 'Income set to the A/R', 'type' => 'Service', 'ar_account' => $wt['ar'], 'income_account' => $wt['ar'],
        'cost_price' => 0, 'sales_price' => '25.00', 'allow_member_discount' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $w = wt_account(943111, 'Numbered', 'Account', null);
    $n = wt_account(943112, 'Unnumbered', 'Account', null);
    DB::run('UPDATE water_accounts SET account_number = NULL WHERE pkey = :p', ['p' => $n]);
    $common = ['charge_code' => 'CONNECTION FEE', 'amount' => '', 'bill_date' => today(), 'term_days' => ''];
    $by = array_column(otbi_check($pdo, [
        ['_line' => 2, 'account_no' => (string)$w],
        ['_line' => 3, 'account_no' => (string)$n],
        ['_line' => 4, 'account_no' => 'W943111', 'amount' => '99,999,999,999,999'],
        ['_line' => 5, 'account_no' => 'W943111', 'charge_code' => 'MISMAPPED FEE'],
        ['_line' => 6, 'account_no' => 'W943111', 'bill_date' => 'Jan'],
    ], $common), null, '_line');
    check(str_contains(implode(' ', $by[2]['_errors']), 'No water-service account ' . $w), 'an account with a number is not taken by its ID');
    eq($by[3]['_errors'], [], 'an account without a number is taken by its ID');
    check((bool)array_filter($by[3]['_notes'], fn($x) => str_contains($x, 'has no account number')), '...and the line says so');
    check(str_contains(implode(' ', $by[4]['_errors']), 'more than 999,999,999.99'), 'an amount above the largest refused at review');
    check(str_contains(implode(' ', $by[5]['_errors']), 'MISMAPPED FEE'), 'a bill code whose income account is not income refused at review (' . implode(' ', $by[5]['_errors']) . ')');
    check(str_contains(implode(' ', $by[6]['_errors']), 'not a date'), '"Jan" is not a date issued');
    $classId = (int)DB::value('SELECT id FROM classes WHERE is_active = 1 ORDER BY id LIMIT 1');
    check(otbi_class_problem('') !== null && otbi_class_problem($classId) === null, 'the class is checked before posting: none chosen is shown above the lines');
    check(ir_too_many(2001, 2000) !== null && ir_too_many(2000, 2000) === null, 'more lines than one posting takes is shown above the lines');
    DB::run('DELETE FROM bill_codes WHERE code_id = :c', ['c' => $bad]);
});

test('The Excel reader: a worksheet written with a prefix (x:worksheet), rows of formatted empty cells left out, a cell far to the right not read, and "line N" of the review is row N of the worksheet', function () {
    $mk = function (string $sheet) {
        $path = tempnam(sys_get_temp_dir(), 'xlsxt');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="A" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        return $path;
    };
    $p = $mk('<?xml version="1.0"?><x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><x:sheetData><x:row r="1"><x:c r="A1" t="inlineStr"><x:is><x:t>account_no</x:t></x:is></x:c><x:c r="B1" t="inlineStr"><x:is><x:t>amount</x:t></x:is></x:c></x:row><x:row r="2"><x:c r="A2"><x:v>123</x:v></x:c><x:c r="B2"><x:v>5</x:v></x:c></x:row></x:sheetData></x:worksheet>');
    eq(XlsxReader::rows($p), [['account_no', 'amount'], ['123', '5']], 'a prefixed worksheet is read');
    @unlink($p);
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>account_no</t></is></c><c r="B1" t="inlineStr"></c><c r="ZZZZZZ1" t="inlineStr"><is><t>far</t></is></c></row>'
        . '<row r="2"><c r="A2"><v>111</v></c></row><row r="5"><c r="A5"><v>222</v></c></row>';
    for ($i = 6; $i <= 6000; $i++) {
        $rows .= '<row r="' . $i . '"><c r="A' . $i . '" s="1"/></row>';
    }
    $p = $mk('<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
    eq(XlsxReader::rows($p, 5050), [['account_no'], ['111'], ['222']], 'an empty inline cell, a far column and 5,995 formatted empty rows: none of them read (and no warning)');
    $read = CsvReview::readText(XlsxReader::csvText($p, 5050), ShareCapitalBilling::IMPORT_COLUMNS, ShareCapitalBilling::IMPORT_ALIASES);
    eq(array_column($read, '_line'), [2, 5], 'the lines are the rows of the worksheet');
    @unlink($p);
});

test('Migration 2027_03_21: the term and batch of a billing, the due date and term of a bill (run twice); every billing on file stays open', function () {
    foreach ([['share_capital_plans', 'term_days'], ['share_capital_plans', 'batch_no'], ['share_capital_bills', 'due_date'], ['share_capital_bills', 'term_days']] as [$t, $c]) {
        eq((int)DB::value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c', ['t' => $t, 'c' => $c]), 1, "$t.$c");
    }
    eq((int)DB::value("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'share_capital_bills' AND index_name = 'ix_scb_due'") > 0, true, 'the index of the due dates');
    $before = DB::value('SELECT COUNT(*) FROM share_capital_plans') . '/' . DB::value('SELECT COUNT(*) FROM share_capital_bills WHERE due_date IS NOT NULL');
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_03_21_share_capital_terms_batch.sql');
    eq(DB::value('SELECT COUNT(*) FROM share_capital_plans') . '/' . DB::value('SELECT COUNT(*) FROM share_capital_bills WHERE due_date IS NOT NULL'), $before, 'a second run changes nothing');
    check(in_array('database/migrations/2027_03_21_share_capital_terms_batch.sql', Schema::files(), true), 'the schema check knows it');
    Schema::flush();
    eq(Schema::pending(), [], 'no update pending');
    eq(WaterPages::processPage('shareCapitalImport'), 'share_capital_billing', 'the share capital import belongs to its page');
    eq(WaterPages::processPage('oneTimeBillImport'), 'one_time_billing', 'the one-time import belongs to One-Time Billing');
    eq([WaterPages::ALIASES['share_capital_import'], WaterPages::ALIASES['one_time_billing_import']], [['share_capital_billing'], ['one_time_billing']], 'the import pages carry the rights of their pages');
});
