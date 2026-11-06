<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v16.xlsx (version 46) at the level of the program's rules:
 *  - Regular - Joint: a member type of the joint account form, regular, beside Associate - Joint;
 *  - the subsidiary ledger of the accrued interest on time deposits less what the members withdrew of it before
 *    maturity (CAIW): SL = GL again (the 500.00 difference of the workbook), on the reconciliation, the TD reports;
 *  - the address sub-groups: add, rename (carried to the members and the water-service accounts), switch off,
 *    delete (refused while in use);
 *  - Reading Entry: an unposted reading deleted with its reason; a posted one, or one followed by a later reading,
 *    refused;
 *  - incremental searching of the water-service accounts (the ones whose name starts with what is typed first);
 *  - the pre-printed receipts: the layout (paper, margins, entries) kept clean, saved, put back to the default;
 *    the entries formatted and placed; the lines that do not fit added together on the last one.
 * The pages themselves (the sub-group window, the reading Edit / Delete, the incremental pickers, the live search,
 * the receipt designer, the receipt type of Company, Security & Closing Date) are checked over HTTP
 * (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

require_once APP_ROOT . '/water/common/readingHelpers.php';

/** the error message of a refused call ('' when it was not refused) */
function v46Refused(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

test('Regular - Joint: a regular member type of the joint account form, beside Associate - Joint; the upgrade adds it once', function () {
    $t = DB::row("SELECT * FROM member_types WHERE code = 'RGJ'");
    check($t !== null, 'the type Regular - Joint is there');
    eq([$t['name'], $t['member_kind'], (int)$t['is_regular'], (int)$t['is_active']], ['Regular - Joint', 'joint', 1, 1], 'a joint account type, regular, active');
    $asj = DB::row("SELECT * FROM member_types WHERE code = 'ASJ'");
    eq((int)$t['sort_order'], (int)$asj['sort_order'], 'listed with Associate - Joint');
    eqm($t['min_share_capital'], DB::value("SELECT min_share_capital FROM member_types WHERE code = 'REG'"), 'the minimum share capital of a regular member');
    $joint = array_values(array_map(fn($x) => $x['name'], array_filter(Members::types(), fn($x) => $x['member_kind'] === 'joint' && (int)$x['is_active'] === 1)));
    check(in_array('Regular - Joint', $joint, true) && in_array('Associate - Joint', $joint, true), 'the Type list of the joint account form offers both');
    // the upgrade run again (and on a database where the name was already entered by hand) adds nothing
    run_sql_file(DB::pdo(), APP_ROOT . '/database/migrations/2027_03_28_regular_joint_subgroups_receipts.sql');
    eq((int)DB::value("SELECT COUNT(*) FROM member_types WHERE code = 'RGJ' OR name = 'Regular - Joint'"), 1, 'the upgrade run twice keeps one Regular - Joint');
    eq((int)DB::value("SELECT COUNT(*) FROM pages WHERE page_key IN ('water.address_subgroups','water.receipt_layouts')"), 2, 'the two new Water Billing pages are registered once');
    // a joint account with the type
    $m = newMember('Bautista', 'Joint RGJ');
    DB::update('members', ['member_kind' => 'joint', 'member_type_id' => (int)$t['id'], 'institution_name' => 'Bautista, Pedro or Rosa'], 'id = :id', ['id' => $m]);
    eq((string)DB::value('SELECT t.code FROM members m JOIN member_types t ON t.id = m.member_type_id WHERE m.id = :m', ['m' => $m]), 'RGJ', 'a joint account of type Regular - Joint');
});

test('Time deposit: the accrued interest a member withdrew before maturity (CAIW) is deducted from the accrued interest; SL = GL on the reconciliation and the reports', function () {
    global $ids;
    asUser('teller1');
    shift(today());
    $m = newMember('Bautista', 'Pedro');
    [$pid] = CoopLedger::withClass((int)$ids['class.HO'], fn() => TimeDeposits::place($m, (int)$ids['sav.TD'], '100000', 365, today(), ['kind' => 'cash', 'cash_account_id' => (int)$ids['cash.HO']],
        ['shift_id' => Teller::openShift()['id'], 'rate' => '4.0000'], 'sav.td'));
    DB::update('td_placements', ['placement_date' => Coop::addDays(today(), -120), 'maturity_date' => Coop::addDays(today(), 245)], 'id = :id', ['id' => $pid]);
    asUser('gm');
    TimeDeposits::accrue(Coop::addDays(today(), -10), Coop::addDays(today(), -10), 'sav.td');
    $td = TimeDeposits::placement($pid);
    $accrued = (string)$td['accrued_interest'];
    check(Dec::cmp($accrued, '500') > 0, 'interest accrued: ' . $accrued);
    $accAcct = (int)(DB::value('SELECT accrued_account_id FROM sav_products WHERE id = :p', ['p' => $ids['sav.TD']]) ?: Accts::get('deposit_interest_payable'));
    $tdRow = function () use ($accAcct): ?array {
        foreach (CoopRecon::slVsGl(today()) as $r) {
            if ($r['ledger'] === 'Accrued interest on time deposits' && (int)$r['account_id'] === $accAcct) {
                return $r;
            }
        }
        return null;
    };
    $before = $tdRow();
    check($before !== null, 'the reconciliation lists the accrued interest account of the product');
    eqm($before['difference'], '0', 'balanced after the accrual');

    // the partial withdrawal of the accrued interest (CAIW 500.00, the case of the workbook)
    asUser('teller1');
    SavTxn::tdInterestWithdraw($pid, '500.00', today(), 'sav.td');
    $td = TimeDeposits::placement($pid);
    eqm($td['accrued_paid'], '500', 'the withdrawal is taken from the accrued interest');
    $left = Dec::sub($accrued, '500', 2);
    $after = $tdRow();
    eqm($after['sl'], Dec::add($before['sl'], '-500', 2), 'SL: the accrued interest less the 500.00 withdrawn');
    eqm($after['gl'], Dec::add($before['gl'], '-500', 2), 'GL: the 500.00 debit of the CAIW');
    eqm($after['difference'], '0', 'SL = GL: the 500.00 difference of the workbook is gone');
    // the former formula (the accrued interest alone) is what showed the difference
    $old = Dec::sub((string)DB::value("SELECT COALESCE(SUM(t.accrued_interest),0) FROM td_placements t JOIN sav_accounts a ON a.id = t.account_id JOIN sav_products p ON p.id = a.product_id
                                       WHERE t.status IN ('active','matured') AND COALESCE(p.accrued_account_id, 0) IN (0, :a)", ['a' => $accAcct]), $after['gl'], 2);
    eqm($old, '500', 'the former subsidiary ledger was 500.00 over the GL');
    eq(array_values(array_filter(CoopRecon::memberDiffs($after, today()), fn($d) => Dec::cmp((string)($d['difference'] ?? '0'), '0') !== 0)), [], 'no member is out of balance on the account');

    // the reports: the TD register and the accrued interest report show what is still payable
    $f = ['q' => '', 'search' => '', 'product' => '', 'status' => '', 'asof' => today(), 'user_id' => null, 'branch' => '', 'view' => '', 'kind' => ''];
    $list = (Report::get('td_list')['run'])($f);
    $row = array_values(array_filter($list['rows'], fn($r) => (int)($r['member_id'] ?? 0) === $m || str_contains((string)($r['member'] ?? $r['name'] ?? ''), 'Bautista, Pedro')));
    check($row !== [], 'the TD register lists the deposit');
    eqm($row[0]['accrued'], $left, 'TD register: accrued interest payable ' . $left);
    $acc = (Report::get('td_accrued')['run'])($f);
    $row = array_values(array_filter($acc['rows'], fn($r) => str_contains((string)($r['member'] ?? $r['name'] ?? ''), 'Bautista, Pedro')));
    check($row !== [], 'the accrued interest report lists the deposit');
    eq([Dec::fix($row[0]['booked']), Dec::fix($row[0]['withdrawn']), Dec::fix($row[0]['payable'])], [Dec::fix($accrued), '500.00', Dec::fix($left)], 'accrued, withdrawn and payable');

    // a void of the withdrawal puts it back, on both ledgers
    asUser('gm');
    $caiwTxn = (int)DB::value("SELECT txn_id FROM td_interest_payouts WHERE placement_id = :p ORDER BY id DESC LIMIT 1", ['p' => $pid]);
    Txn::void($caiwTxn, 'Wrong certificate', Auth::user());
    eqm(TimeDeposits::placement($pid)['accrued_paid'], '0', 'the void puts the accrued interest back');
    eqm($tdRow()['difference'], '0', 'SL = GL after the void');
    svCloseShift();
    assertLedgerBalanced('after the CAIW and its void');
});

test('Address sub-groups: added, renamed on every member and water-service account that has it, switched off, deleted only when nothing has it', function () {
    $page = 'water.address_subgroups';
    eq(Members::saveSubgroup(null, '  Purok   Uno ', 1, true, $page), '"Purok Uno" was added to the address sub-groups.', 'added (the spaces tidied)');
    Members::saveSubgroup(null, 'Sitio Tres', 3, true, $page);
    check(str_contains(v46Refused(fn() => Members::saveSubgroup(null, 'purok uno', 9, true, $page)), 'is already an address sub-group'), 'the same name again is refused (in any case)');
    check(str_contains(v46Refused(fn() => Members::saveSubgroup(null, '   ', 9, true, $page)), 'Enter the name'), 'a name is required');
    $rows = array_column(Members::subgroupRows(), null, 'name');
    $uno = (int)$rows['Purok Uno']['id'];
    // a member and a water-service account have it
    $m = newMember('Subgroup', 'Member');
    DB::update('members', ['address_subgroup' => 'Purok Uno'], 'id = :id', ['id' => $m]);
    wt_account(4610, 'Subgroup', 'Account', null);
    DB::update('water_accounts', ['address_subgroup' => 'Purok Uno'], 'pkey = :k', ['k' => 4610]);
    $rows = array_column(Members::subgroupRows(), null, 'name');
    eq([$rows['Purok Uno']['members'], $rows['Purok Uno']['accounts']], [1, 1], 'how many members and accounts have it');
    // renamed: carried to both
    $msg = Members::saveSubgroup($uno, 'Purok 1', 1, true, $page);
    check(str_starts_with($msg, '"Purok Uno" is now "Purok 1"'), 'renamed: ' . $msg);
    eq([DB::value('SELECT address_subgroup FROM members WHERE id = :m', ['m' => $m]), DB::value('SELECT address_subgroup FROM water_accounts WHERE pkey = 4610')], ['Purok 1', 'Purok 1'], 'the new name is on the member and the account');
    // in use: not deleted, but it can be switched off
    check(str_contains(v46Refused(fn() => Members::deleteSubgroup($uno, $page)), 'cannot be deleted: it is used by'), 'deleting one in use is refused');
    check(str_contains(Members::saveSubgroup($uno, 'Purok 1', 1, false, $page), 'no longer offered'), 'switched off instead');
    eq((int)DB::value('SELECT is_active FROM coop_lists WHERE id = :id', ['id' => $uno]), 0, 'it is no longer offered');
    // not in use: deleted
    $tres = (int)array_column(Members::subgroupRows(), null, 'name')['Sitio Tres']['id'];
    eq(Members::deleteSubgroup($tres, $page), '"Sitio Tres" was deleted from the address sub-groups.', 'deleted');
    eq((int)DB::value('SELECT COUNT(*) FROM coop_lists WHERE id = :id', ['id' => $tres]), 0, 'gone from the list');
    check(str_contains(v46Refused(fn() => Members::deleteSubgroup($tres, $page)), 'no longer on the list'), 'deleting it twice says so');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'coop_lists' AND record_id IN (:a, :b)", ['a' => $uno, 'b' => $tres]) >= 4, true, 'every change is in the audit trail');
});

test('Reading Entry: an unposted reading is deleted with its reason (and can be read again); a posted reading, or one followed by a later reading, is refused', function () {
    $pdo = DB::pdo();
    $uid = (int)$GLOBALS['adminId'];
    $period = DB::row('SELECT * FROM bill_periods WHERE period_id = 1');
    wt_account(4620, 'Draft', 'Reading', null);
    $r = DB::tx(fn() => wr_save_draft_reading($pdo, $period, 4620, 25, 'INDIVIDUAL', 'test', $uid));
    $rid = (int)$r['reading_id'];
    check(str_contains(v46Refused(fn() => DB::tx(fn() => wr_delete_draft_reading($pdo, $period, $rid, 'oops', $uid))), 'at least 5 characters'), 'a reason of at least 5 characters is required');
    $row = DB::tx(fn() => wr_delete_draft_reading($pdo, $period, $rid, 'Read the wrong meter', $uid));
    eq((int)$row['pres_reading'], 25, 'the deleted reading is returned as it was');
    eq((int)DB::value('SELECT COUNT(*) FROM bill_readings WHERE reading_id = :r', ['r' => $rid]), 0, 'the reading is gone');
    $log = DB::row("SELECT * FROM water_audit_logs WHERE action = 'DELETE_METER_READING' AND record_id = :r", ['r' => (string)$rid]);
    check($log !== null && str_contains((string)$log['details'], 'Read the wrong meter'), 'the deletion and its reason are in the audit log');
    $again = DB::tx(fn() => wr_save_draft_reading($pdo, $period, 4620, 30, 'INDIVIDUAL', 'test', $uid));
    eq((int)DB::value('SELECT pres_reading FROM bill_readings WHERE reading_id = :r', ['r' => $again['reading_id']]), 30, 'the account is read again for the period');
    check(str_contains(v46Refused(fn() => DB::tx(fn() => wr_delete_draft_reading($pdo, $period, 999999, 'Not on file', $uid))), 'no longer on file'), 'a reading that is not there');

    // a posted (billed) reading stays: it is corrected through Billing Adjustments
    wt_account(4621, 'Posted', 'Reading', null);
    [$posted] = wt_bill(4621, 12);
    check(str_contains(v46Refused(fn() => DB::tx(fn() => wr_delete_draft_reading($pdo, $period, $posted, 'Trying to delete', $uid))), 'already billed'), 'a posted reading cannot be deleted');
    eq((int)DB::value('SELECT COUNT(*) FROM bill_readings WHERE reading_id = :r', ['r' => $posted]), 1, 'it stays');

    // a reading followed by a later reading of the account stays (the later one's previous reading came from it)
    DB::insert('bill_periods', ['period_id' => 46, 'bp_code' => '100126_103126', 'start_date' => '2026-10-01 00:00:00', 'end_date' => '2026-10-31 23:59:00', 'status' => 'open',
        'opened_by' => $uid, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $next = DB::row('SELECT * FROM bill_periods WHERE period_id = 46');
    $later = DB::tx(fn() => wr_save_draft_reading($pdo, $next, 4620, 41, 'INDIVIDUAL', 'test', $uid));
    eq((int)DB::value('SELECT prev_reading FROM bill_readings WHERE reading_id = :r', ['r' => $later['reading_id']]), 30, 'the later reading starts from it');
    check(v46Refused(fn() => DB::tx(fn() => wr_delete_draft_reading($pdo, $period, (int)$again['reading_id'], 'Delete the earlier one', $uid))) !== '', 'the earlier reading cannot be deleted while a later one exists');
    DB::tx(fn() => wr_delete_draft_reading($pdo, $next, (int)$later['reading_id'], 'Later reading first', $uid));
    DB::tx(fn() => wr_delete_draft_reading($pdo, $period, (int)$again['reading_id'], 'Then the earlier one', $uid));
    eq((int)DB::value('SELECT COUNT(*) FROM bill_readings WHERE member_id = 4620'), 0, 'the later one first, then the earlier one');
    DB::run('DELETE FROM bill_periods WHERE period_id = 46');
});

test('Incremental searching of the water-service accounts: every word typed is found; the names that start with what is typed come first', function () {
    wt_account(4630, 'Moreno', 'Jose', null);
    wt_account(4631, 'Andres', 'Rexanne', null);
    wt_account(4632, 'Reyesa', 'Ana', null);
    $keys = fn(array $rows) => array_values(array_filter(array_map(fn($r) => (int)$r['pkey'], $rows), fn($k) => $k >= 4630 && $k <= 4632));
    eq($keys(searchMembers(DB::pdo(), 're', 100)), [4632, 4631, 4630], 'last name starting with it, then first name, then the rest');
    eq($keys(searchMembers(DB::pdo(), 'reyesa an', 100)), [4632], 'every word must be found');
    eq($keys(searchMembers(DB::pdo(), 'r', 100)), [4632, 4631, 4630], 'one letter is enough');
});

test('Pre-printed receipts: the layout is kept clean, saved and put back to the default; the entries are formatted and placed; the lines that do not fit are added together', function () {
    $page = 'water.receipt_layouts';
    // the receipt type of Company, Security & Closing Date (Printed by default)
    eq([WaterReceiptLayout::type('collection'), WaterReceiptLayout::type('official')], ['printed', 'printed'], 'Printed until Pre-Printed is chosen');
    Settings::set('water_collection_receipt_type', 'preprinted');
    eq(WaterReceiptLayout::type('collection'), 'preprinted', 'Pre-Printed once chosen');
    Settings::set('water_collection_receipt_type', 'other');
    eq(WaterReceiptLayout::type('collection'), 'printed', 'anything else prints the whole receipt');
    Settings::set('water_collection_receipt_type', 'printed');

    // the default layouts: the forms of the cooperative, two copies of the water bill side by side
    $col = WaterReceiptLayout::defaultLayout('collection');
    eq([$col['setup']['page_width'], $col['setup']['page_height'], $col['setup']['copies'], $col['setup']['copy_left']], [12474, 5670, 2, 6322], 'the water bill: 22 x 10 cm, two copies');
    eq(WaterReceiptLayout::warnings('collection', $col), [], 'the default layout has nothing to warn about');
    $or = WaterReceiptLayout::defaultLayout('official');
    eq([$or['setup']['page_width'], $or['setup']['page_height'], $or['setup']['max_lines']], [12960, 7920, 11], 'the official receipt: 9 x 5.5 in, 11 lines');

    // clean(): measurements kept within their limits, unknown entries dropped, formats checked
    $dirty = ['setup' => ['page_width' => 999999, 'margin_left' => -50, 'font' => 'comic', 'cpi' => 13, 'font_size_pt' => '99'],
        'header' => [['f' => 'name', 'x' => 1458, 'y' => 1452, 'w' => 3260, 'a' => 'q', 'fs' => 2], ['f' => 'nonsense', 'x' => 1], 'not an entry'],
        'body' => [['f' => 'amount', 'x' => 5087, 'y' => 0, 'w' => 794, 'a' => 'r', 'fmt' => 'roman', 'z' => '-']], 'footer' => []];
    $c = WaterReceiptLayout::clean('collection', $dirty);
    $mw = WaterReceiptLayout::MEASURES['page_width'];
    eq([$c['setup']['page_width'], $c['setup']['margin_left'] >= WaterReceiptLayout::MEASURES['margin_left'][1]], [$mw[2], true], 'the paper and the margins within their limits');
    eq([$c['setup']['font'], $c['setup']['cpi'], $c['setup']['font_size_pt']], [$col['setup']['font'], (int)$col['setup']['cpi'], $col['setup']['font_size_pt']], 'an unknown font, pitch or size falls back to the default');
    eq([count($c['header']), $c['header'][0]['a'], $c['header'][0]['fs']], [1, 'l', 0], 'an unknown entry is dropped; the alignment and size checked');
    eq([$c['body'][0]['fmt'], $c['body'][0]['z']], [array_key_first(WaterReceiptLayout::FORMATS['money']), '-'], 'an unknown money format falls back; the zero kept');
    eq(WaterReceiptLayout::warnings('collection', $c), [], 'nothing to warn about once cleaned');
    $many = WaterReceiptLayout::clean('collection', ['setup' => [], 'header' => array_fill(0, 500, ['f' => 'name', 'x' => 1, 'y' => 1]), 'body' => [], 'footer' => []]);
    eq(count($many['header']), WaterReceiptLayout::MAX_ITEMS, 'a part keeps at most ' . WaterReceiptLayout::MAX_ITEMS . ' entries');
    $bad = ['setup' => ['copy_left' => 0, 'copy_top' => 0, 'margin_left' => 7000, 'margin_right' => 6000] + $col['setup'], 'header' => [], 'body' => [], 'footer' => []];
    eq(count(WaterReceiptLayout::warnings('collection', $bad)), 3, 'warned: no room inside the margins, no lines, the second copy over the first');

    // the entries as printed
    $it = fn(string $f, string $fmt = '', string $z = '') => ['f' => $f, 'fmt' => $fmt, 'z' => $z];
    eq(WaterReceiptLayout::format('collection', 'header', $it('water_fee', 'comma'), ['water_fee' => '1599']), '1,599.00', 'money with thousands');
    eq(WaterReceiptLayout::format('collection', 'header', $it('water_fee', 'plain'), ['water_fee' => '1599']), '1599.00', 'money without the comma');
    eq(WaterReceiptLayout::format('collection', 'header', $it('water_fee', 'whole'), ['water_fee' => '1599.40']), '1,599', 'whole pesos');
    eq([WaterReceiptLayout::format('collection', 'header', $it('arrears', '', ''), ['arrears' => '0']),
        WaterReceiptLayout::format('collection', 'header', $it('arrears', '', '-'), ['arrears' => '0']),
        WaterReceiptLayout::format('collection', 'header', $it('arrears', '', '0'), ['arrears' => '0'])], ['', '-', '0.00'], 'a zero: blank, a dash or 0.00');
    eq(WaterReceiptLayout::format('official', 'header', $it('date', 'm/d/Y'), ['date' => '2026-10-12']), '10/12/2026', 'a date in its format');
    eq(WaterReceiptLayout::format('official', 'header', ['f' => 'text', 't' => 'Paid by {name}'], ['name' => 'OCAMPO, RODEL']), 'Paid by OCAMPO, RODEL', 'a text entry with the values in it');

    // the lines: more than Max Lines leaves the last one with the rest of them together
    $lines = [['description' => 'WATER BILL', 'amount' => '1937.60'], ['description' => 'MAINTENANCE FEE', 'amount' => '50'], ['description' => 'PENALTY', 'amount' => '25.10']];
    $fit = WaterReceiptLayout::fitLines($lines, 2, 'OTHER CHARGES');
    eq(array_map(fn($l) => [$l['description'], Dec::fix($l['amount']), $l['_src']], $fit), [['WATER BILL', '1937.60', [0]], ['OTHER CHARGES', '75.10', [1, 2]]], 'two lines: the first, then the rest added together');
    eq(count(WaterReceiptLayout::fitLines($lines, 11, 'X')), 3, 'all of them when they fit');

    // printed: every entry of the layout at its place, once per copy; nothing else (no frames, no titles)
    $s = WaterReceiptLayout::sample('collection');
    $html = WaterReceiptLayout::render('collection', $col, $s['values'], $s['lines']);
    eq(substr_count($html, 'data-f="account"'), 2, 'the account number on both copies');
    check(str_contains($html, '1,599.00') && str_contains($html, 'SANTOS, MARIA'), 'the values are printed');
    check(!str_contains($html, 'Concessionaire') && !str_contains($html, '<table'), 'only the entries: no labels, no frames');
    $css = WaterReceiptLayout::css($col);
    check(str_contains($css, '@page') && (str_contains($css, 'margin:0') || str_contains($css, 'margin: 0')), 'printed at the size of the form, with no margins of the browser');

    // saved, read back, put back to the default (audit trail)
    $mine = $col;
    $mine['header'][0]['x'] = 600;
    $mine['setup']['margin_top'] = 144;
    WaterReceiptLayout::save('collection', $mine, $page, 'Fit our form');
    check(WaterReceiptLayout::isCustom('collection'), 'the layout of the cooperative is kept');
    eq([WaterReceiptLayout::get('collection')['header'][0]['x'], WaterReceiptLayout::get('collection')['setup']['margin_top']], [600, 144], 'read back as saved');
    WaterReceiptLayout::reset('collection', $page);
    check(!WaterReceiptLayout::isCustom('collection'), 'back to the default');
    eq(WaterReceiptLayout::get('collection'), WaterReceiptLayout::defaultLayout('collection'), 'the default layout again');
    eq((int)DB::value("SELECT COUNT(*) FROM audit_trail WHERE page_key = :p", ['p' => $page]) >= 2, true, 'the save and the reset are in the audit trail');
});
