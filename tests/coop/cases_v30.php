<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v2.xlsx, at the level of the program's rules:
 * the class every cooperative and water transaction requires, changes to journal entries approved by a second user,
 * the member grouping lists, members described by their type and listed as customers.
 * Runs after cases_water.php (the water helpers are loaded there).
 */

test('Class required: refused without one or with an inactive one; the voucher and every ledger line carry the class chosen', function () {
    global $ids;
    $_POST = ['class_id' => ''];
    refuses(fn() => Classes::required('class_id', 'the deposit'), 'class', 'a deposit without a class');
    $off = (int)DB::insert('classes', ['name' => 'Closed Office', 'description' => 'switched off', 'sort_order' => 90, 'is_active' => 0, 'created_by' => Auth::id()]);
    Classes::reset();
    $_POST = ['class_id' => (string)$off];
    refuses(fn() => Classes::required('class_id', 'the deposit'), 'no longer active', 'an inactive class');
    $_POST = ['class_id' => (string)$ids['class.BR2']];
    eq(Classes::required('class_id', 'the deposit'), (int)$ids['class.BR2'], 'an active class is taken');
    $_POST = [];

    // a head-office teller receives a deposit for a class of their choosing: the voucher and its lines carry it
    asUser('teller1');
    shift(today());
    $m = newMember('Villareal', 'Cora');
    $rs = openAccount($m, 'RS', today());
    $r = CoopLedger::withClass((int)$ids['class.BR2'], fn() => receipt($m, today(), [['kind' => 'savings', 'account_id' => $rs, 'amount' => '750.00']]));
    $t = Txn::load((int)$r['txn_id']);
    eq((int)$t['class_id'], (int)$ids['class.BR2'], 'the official receipt carries the class chosen, not the teller\'s branch class');
    eq((int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE txn_id = :t AND COALESCE(class_id, 0) <> :c', ['t' => $t['id'], 'c' => $ids['class.BR2']]), 0, 'every ledger line carries it');
    eq(CoopLedger::currentClass(), null, 'the class applies only inside the transaction');
    // without a chosen class the branch class is used as before (vouchers made by the program itself)
    $r2 = receipt($m, today(), [['kind' => 'savings', 'account_id' => $rs, 'amount' => '50.00']]);
    eq((int)Txn::load((int)$r2['txn_id'])['class_id'], (int)$ids['class.HO'], 'a voucher made without a chosen class keeps the branch class');
    asUser('gm');
    assertLedgerBalanced('after the class receipts');
});

test('Journal entry changes: an explanation, a second user with the Approve right, the entry saved with the change; refused when the entry changed meanwhile', function () {
    global $ids;
    $cash = (int)DB::value("SELECT id FROM accounts WHERE account_type IN ('Bank', 'Cash') AND is_active = 1 ORDER BY code LIMIT 1");
    $exp = (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Expense' AND is_active = 1 ORDER BY code LIMIT 1");
    asUser('book');
    check(Access::can('coa.journal', 'edit') && !JournalEdits::canDecide(), 'the Bookkeeper edits journal entries but does not approve changes');
    $jid = DB::tx(fn() => Txn::save('journal', ['txn_date' => today(), 'memo' => 'Office supplies', 'status' => 'open', 'class_id' => $ids['class.HO']],
        [['line_kind' => 'journal', 'account_id' => $exp, 'amount' => 500, 'description' => 'Supplies', 'class_id' => $ids['class.HO']],
         ['line_kind' => 'journal', 'account_id' => $cash, 'amount' => -500, 'description' => 'Paid', 'class_id' => $ids['class.HO']]]));
    $t = Txn::load($jid);
    $h = ['txn_date' => $t['txn_date'], 'memo' => 'Office supplies', 'status' => 'open', 'class_id' => $ids['class.HO']];
    $lines = [['line_kind' => 'journal', 'account_id' => $exp, 'amount' => 650, 'description' => 'Supplies', 'class_id' => $ids['class.HO']],
              ['line_kind' => 'journal', 'account_id' => $cash, 'amount' => -650, 'description' => 'Paid', 'class_id' => $ids['class.HO']]];
    refuses(fn() => JournalEdits::request($t, 'journal', $h, $lines, 'typo'), 'Explain why', 'a change without a real explanation');
    $unchanged = [['line_kind' => 'journal', 'account_id' => $exp, 'amount' => 500, 'description' => 'Supplies', 'class_id' => $ids['class.HO']],
                  ['line_kind' => 'journal', 'account_id' => $cash, 'amount' => -500, 'description' => 'Paid', 'class_id' => $ids['class.HO']]];
    refuses(fn() => JournalEdits::request($t, 'journal', $h, $unchanged, 'Nothing really changes here'), 'Nothing was changed', 'a request that changes nothing');
    $req = JournalEdits::request($t, 'journal', $h, $lines, 'Supplies were 650, not 500 (receipt 1187)');
    eqm(Txn::load($jid)['total'], '500.00', 'the entry stays as it is while the change waits');
    eq((string)DB::value('SELECT status FROM journal_edit_requests WHERE id = :i', ['i' => $req]), 'pending', 'the change waits for approval');
    refuses(fn() => JournalEdits::approve($req), 'someone other', 'the requester cannot approve their own change');
    asUser('teller1');
    refuses(fn() => JournalEdits::approve($req), 'Approve right', 'a user without the Approve right cannot approve');
    asUser('gm');
    check(JournalEdits::canDecide(), 'the General Manager approves changes');
    JournalEdits::approve($req, 'Checked against the receipt');
    eqm(Txn::load($jid)['total'], '650.00', 'approved: the entry is saved with the change');
    eqm(DB::value('SELECT SUM(debit) FROM journal_lines WHERE txn_id = :t', ['t' => $jid]), '650.00', '...in the ledger too');
    eq((string)DB::value('SELECT status FROM journal_edit_requests WHERE id = :i', ['i' => $req]), 'approved', 'the request is approved');
    check((bool)DB::value("SELECT COUNT(*) FROM audit_trail WHERE record_type = 'journal_edit' AND record_id = :i AND action = 'approve'", ['i' => $req]), 'the approval is in the audit trail');

    // a change asked on the entry as it was, approved after the entry changed: refused
    asUser('book');
    $t = Txn::load($jid);
    $lines700 = $lines;
    $lines700[0]['amount'] = 700;
    $lines700[1]['amount'] = -700;
    $old = JournalEdits::request($t, 'journal', $h, $lines700, 'Supplies were 700 after the second receipt');
    $newer = JournalEdits::request($t, 'journal', $h, $lines700, 'Supplies were 700 after the second receipt (corrected)');
    eq((string)DB::value('SELECT status FROM journal_edit_requests WHERE id = :i', ['i' => $old]), 'superseded', 'a newer request replaces the one waiting');
    asUser('gm');
    $l680 = $lines;
    $l680[0]['amount'] = 680;
    $l680[1]['amount'] = -680;
    DB::tx(fn() => Txn::save('journal', $h, $l680, $jid, ['reason' => 'test: changed meanwhile', 'ledger_change_ok' => true]));
    refuses(fn() => JournalEdits::approve($newer), 'changed since', 'the entry changed after the request: the approval is refused');
    refuses(fn() => JournalEdits::reject($newer, ''), 'why the change is rejected', 'a rejection needs its reason');
    JournalEdits::reject($newer, 'Ask again on the entry as it is now');
    eq((string)DB::value('SELECT status FROM journal_edit_requests WHERE id = :i', ['i' => $newer]), 'rejected', 'rejected with its reason');
    eqm(Txn::load($jid)['total'], '680.00', 'a rejected change leaves the entry as it is');

    // withdrawn by the requester
    asUser('book');
    $w = JournalEdits::request(Txn::load($jid), 'journal', $h, $lines, 'Back to 650 as on the receipt');
    JournalEdits::withdraw($w);
    eq((string)DB::value('SELECT status FROM journal_edit_requests WHERE id = :i', ['i' => $w]), 'withdrawn', 'the requester withdraws a change');
    asUser('gm');
    assertLedgerBalanced('after the journal entry changes');
});

test('Member grouping lists: offered on the member form, renamed on the members that have them, not deleted while in use', function () {
    foreach (['savings_group' => 'Payroll Savers', 'address_subgroup' => 'Purok 3', 'account_group' => 'Teachers', 'account_class' => 'Class A'] as $k => $name) {
        DB::run('INSERT IGNORE INTO coop_lists (list_key, name, sort_order, is_active) VALUES (:k, :n, 1, 1)', ['k' => $k, 'n' => $name]);
        check(in_array($name, Members::listChoices($k), true), "$k: offered on the member form");
    }
    $m = newMember('Manalo', 'Ines');
    DB::run("UPDATE members SET address_subgroup = 'Purok 3', savings_group = 'Payroll Savers' WHERE id = :m", ['m' => $m]);
    eq(Members::listUsage('address_subgroup', 'Purok 3'), ['1 member(s)'], 'an entry in use reports its users (the page refuses to delete it)');
    eq(Members::listUsage('account_group', 'Teachers'), [], 'an entry nobody uses can be deleted');
    $n = Members::renameListEntry('address_subgroup', 'Purok 3', 'Purok 3-A');
    check($n >= 1, 'renaming moves the members to the new name');
    eq((string)DB::value('SELECT address_subgroup FROM members WHERE id = :m', ['m' => $m]), 'Purok 3-A', 'the member has the renamed entry');
    $choices = Members::listChoices('account_class', 'Old Class B');
    eq($choices['Old Class B'] ?? null, 'Old Class B (not on the list)', 'a value no longer on the list stays offered on the member\'s own form');
});

test('Members on the name lists by their type, and on the customer lists', function () {
    $m = newMember('Quiambao', 'Lito');
    $party = (int)DB::value('SELECT party_id FROM members WHERE id = :m', ['m' => $m]);
    $cust = (int)DB::value('SELECT id FROM customers WHERE party_id = :p AND parent_id IS NULL', ['p' => $party]);
    check($cust > 0, 'a new member is a customer of the cooperative at once, on the member\'s own party');
    eq(Members::ensureCustomerRoles($m), 0, 'made once: running it again makes nothing');
    $type = (string)DB::value('SELECT t.name FROM members m JOIN member_types t ON t.id = m.member_type_id WHERE m.id = :m', ['m' => $m]);
    $kind = Members::kindLabel($type);
    check(str_ends_with($kind, 'Member'), 'the member type reads as "... Member" (' . $kind . ')');
    eq(Members::kindLabel('Associate - Joint'), 'Associate - Joint Member', 'Associate - Joint Member');
    eq(Members::kindLabel('Aflatoun (youth)'), 'Aflatoun (youth) Member', 'Aflatoun (youth) Member');
    eq(Members::kindLabel('Regular', 'terminated'), 'Regular Member, ' . mb_strtolower((string)(Coop::MEMBER_STATUSES['terminated'] ?? 'terminated')), 'the status is added when not active');
    Lookup::reset();
    $opts = Lookup::customerOptions(false);
    check(isset($opts[$cust]) && str_contains($opts[$cust], '(' . $kind . ')'), 'Customer:Job and the POS list the member with the type: ' . ($opts[$cust] ?? '-'));
    // v3: the member is listed once, by the member (m code), and described by its type
    $label = (string)(Names::options()['m' . $m] ?? '');
    check($label !== '' && str_contains($label, $kind), 'the journal entry Name, Write Checks pay to and Cash Voucher paid to show the member type: ' . $label);
});

test('Water: a posting with a class carries it on the document and every line; a water form without a class is refused', function () {
    check(function_exists('water_required_class'), 'the water class helpers are loaded');
    $threw = '';
    try {
        water_required_class('', 'the collection');
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    check(str_contains($threw, 'class'), 'a water transaction without a class is refused: ' . $threw);
    global $ids;
    eq(WaterPosting::withClass((int)$ids['class.BR2'], fn() => WaterPosting::currentClass()), (int)$ids['class.BR2'], 'the class applies inside the posting');
    eq(WaterPosting::currentClass(), null, '...and only there');
});
