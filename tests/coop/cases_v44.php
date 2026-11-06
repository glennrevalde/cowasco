<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v15.xlsx (version 44) at the level of the program's rules:
 *  - the joint account and institution form in the format of the former program (Joint/Institution Details,
 *    Membership Information): its boxes (j_*) are read in place of the individual's, and what only an individual has
 *    (family and beneficiaries, financial, loan and savings grouping) is not read for it;
 *  - Get passbook number: the next number of the Passbook series that no member has.
 * The page itself (the tabs of each kind, the switch of the kind, the save) is checked over HTTP
 * (tests/water/e2e_http.php) and in the browser (docs/TEST_EVIDENCE.md).
 */

test('The joint account / institution form: its boxes are read in place of the individual\'s; the individual\'s own lists and the grouping are not read for it', function () {
    $keepPost = $_POST;
    $keepFiles = $_FILES;
    // a joint account: the j_ boxes win, the individual's boxes and lists are dropped
    $_POST = ['member_kind' => 'joint', 'institution_name' => 'Santos, Ana or Ramon', 'representatives_sent' => '1', 'representatives' => [['name' => 'Ana Santos', 'position' => 'Wife']],
        'last_name' => 'Stale', 'first_name' => 'Value', 'birth_date' => '1990-01-01', 'res_street' => 'Individual street', 'j_res_street' => 'Joint street',
        'branch_id' => '1', 'j_branch_id' => '2', 'passbook_no' => 'I-1', 'j_passbook_no' => 'J-1', 'contacts_sent' => '1', 'contacts' => [['number' => '1']],
        'j_contacts_sent' => '1', 'j_contacts' => [['number' => '0917']], 'dependents_sent' => '1', 'dependents' => [['first_name' => 'X']], 'finances_sent' => '1',
        'fin_income' => [['name' => 'Salary']], 'beneficiaries_sent' => '1', 'attendance_sent' => '1', 'account_class' => 'Regular', 'alert_note' => 'individual', 'j_alert_note' => 'joint'];
    $_FILES = ['photo' => ['name' => 'p.png'], 'signature' => ['name' => 'one.png'], 'j_signature' => ['name' => 'reps.png']];
    Members::postedForKind('joint');
    eq([$_POST['res_street'], $_POST['branch_id'], $_POST['passbook_no'], $_POST['alert_note'], $_POST['contacts'][0]['number'], $_POST['contacts_sent']],
        ['Joint street', '2', 'J-1', 'joint', '0917', '1'], 'the joint boxes take the place of the shared ones');
    eq(array_values(array_filter(['last_name', 'first_name', 'birth_date', 'dependents_sent', 'dependents', 'finances_sent', 'fin_income', 'beneficiaries_sent', 'attendance_sent', 'account_class', 'j_res_street'],
        fn($k) => array_key_exists($k, $_POST))), [], 'the individual\'s name, birth, family, financial, attendance and grouping are not read');
    eq([$_POST['institution_name'], $_POST['representatives'][0]['name']], ['Santos, Ana or Ramon', 'Ana Santos'], 'the membership name and the representatives are kept');
    eq([array_key_exists('photo', $_FILES), $_FILES['signature']['name'] ?? null], [false, 'reps.png'], 'the representatives\' signatures are the member\'s specimen signature');
    // an individual: the joint boxes and the representatives are dropped
    $_POST = ['member_kind' => 'individual', 'last_name' => 'Cruz', 'first_name' => 'Ana', 'res_street' => 'Mabini', 'j_res_street' => 'Joint street', 'institution_name' => 'X',
        'representatives_sent' => '1', 'representatives' => [['name' => 'Y']], 'dependents_sent' => '1'];
    $_FILES = ['signature' => ['name' => 'one.png'], 'j_signature' => ['name' => 'reps.png']];
    Members::postedForKind('individual');
    eq(array_keys($_POST), ['member_kind', 'last_name', 'first_name', 'res_street', 'dependents_sent'], 'an individual\'s form reads its own boxes only');
    eq(array_keys($_FILES), ['signature'], '...and its own signature');
    $_POST = $keepPost;
    $_FILES = $keepFiles;
});

test('A joint account and an institution saved with their representatives, addresses and contact list; saved again from the joint form, the details it has no box for are kept', function () {
    global $ids;
    $types = DB::pairs("SELECT member_kind, MIN(id) FROM member_types WHERE member_kind IN ('joint', 'institutional') GROUP BY member_kind");
    check(!empty($types['joint']) && !empty($types['institutional']), 'a member type for each kind (Associate - Joint, Associate - Institutional)');
    $base = ['branch_id' => $ids['branch.HO'], 'membership_date' => '2026-11-01', 'pmes_date' => '2026-10-25', 'subscribed_shares' => 10, 'par_value' => '100',
        'res_house' => '12', 'res_street' => 'Rizal Avenue', 'res_barangay' => 'Poblacion', 'res_city' => 'San Isidro', 'res_province' => 'Nueva Ecija', 'res_postal' => '3106',
        'off_street' => 'Maharlika Highway', 'off_city' => 'Cabanatuan', 'email' => 'office@example.com', 'last_name' => '', 'first_name' => '', 'birth_date' => null, 'allow_duplicate' => 1];
    $joint = DB::tx(fn() => Members::save(['member_kind' => 'joint', 'institution_name' => 'Reyes, Carmela or Antonio or Paolo', 'member_type_id' => (int)$types['joint']] + $base, [
        'representatives' => [['name' => 'Carmela Reyes', 'position' => 'Wife'], ['name' => 'Antonio Reyes', 'position' => 'Husband'], ['name' => 'Paolo Reyes', 'position' => 'Son'], ['name' => '', 'position' => '']],
        'contacts' => [['contact_type' => 'mobile', 'country_code' => '63', 'area_code' => '', 'number' => '09171234567', 'is_primary' => '1']],
    ], null, 'coop.member'));
    $inst = DB::tx(fn() => Members::save(['member_kind' => 'institutional', 'institution_name' => 'San Isidro Community Hospital', 'member_type_id' => (int)$types['institutional']] + $base, [
        'representatives' => [['name' => 'Ramon Villareal', 'position' => 'President'], ['name' => 'Teresa Mallari', 'position' => 'Vice President'],
            ['name' => 'Danilo Ocampo', 'position' => 'Treasurer'], ['name' => 'Liza Navarro', 'position' => 'Secretary']],
    ], null, 'coop.member'));
    $j = Coop::member($joint);
    eq([$j['full_name'], $j['member_kind'], $j['status'], $j['mobile'], $j['res_barangay'], $j['off_city']], ['Reyes, Carmela or Antonio or Paolo', 'joint', 'applicant', '09171234567', 'Poblacion', 'Cabanatuan'],
        'the joint account: its membership name is its name, an applicant, the mobile from its contact list, its addresses');
    eq(DB::column('SELECT CONCAT(name, \' (\', position, \')\') FROM member_representatives WHERE member_id = :m ORDER BY id', ['m' => $joint]),
        ['Carmela Reyes (Wife)', 'Antonio Reyes (Husband)', 'Paolo Reyes (Son)'], 'its authorized representatives with their relation (an empty row is left out)');
    eq(DB::column('SELECT CONCAT(name, \' (\', position, \')\') FROM member_representatives WHERE member_id = :m ORDER BY id', ['m' => $inst]),
        ['Ramon Villareal (President)', 'Teresa Mallari (Vice President)', 'Danilo Ocampo (Treasurer)', 'Liza Navarro (Secretary)'], 'the institution\'s officers');
    eq((string)DB::value('SELECT legal_name FROM parties WHERE id = :p', ['p' => (int)Coop::member($inst)['party_id']]), 'San Isidro Community Hospital', 'the institution is its party\'s name');
    // a joint record that has a person's name and lists from before: the joint form sends none of them, and they stay
    DB::run("UPDATE members SET last_name = 'Reyes', first_name = 'Carmela', birth_date = '1980-05-05' WHERE id = :m", ['m' => $joint]);
    DB::insert('member_beneficiaries', ['member_id' => $joint, 'name' => 'Paolo Reyes', 'relationship' => 'Son', 'share_pct' => '100.00', 'is_primary' => 1]);
    DB::tx(fn() => Members::save(['member_kind' => 'joint', 'institution_name' => 'Reyes, Carmela or Antonio or Paolo', 'member_type_id' => (int)$types['joint'], 'birth_date' => '1980-05-05',
        'res_street' => 'Burgos Street'] + array_diff_key($base, array_flip(['last_name', 'first_name', 'birth_date', 'res_street'])),
        ['representatives' => [['name' => 'Carmela Reyes', 'position' => 'Wife'], ['name' => 'Antonio Reyes', 'position' => 'Husband']]], $joint, 'coop.member'));
    $j = Coop::member($joint);
    eq([$j['last_name'], $j['first_name'], $j['birth_date'], $j['res_street'], (int)DB::value('SELECT COUNT(*) FROM member_beneficiaries WHERE member_id = :m', ['m' => $joint]),
        (int)DB::value('SELECT COUNT(*) FROM member_representatives WHERE member_id = :m', ['m' => $joint])], ['Reyes', 'Carmela', '1980-05-05', 'Burgos Street', 1, 2],
        'saved again: the address and the representatives changed, the name, birth date and beneficiaries it had kept');
    $GLOBALS['v44'] = ['joint' => $joint, 'inst' => $inst];
});

test('Get passbook number: the next number of the Passbook series that no member has (a number given by hand is skipped)', function () {
    $start = Seq::peek('passbook');
    check($start !== '', 'the Passbook series of Document Numbering (' . $start . ')');
    $a = Members::nextPassbook();
    eq($a, $start, 'the number shown is the one given');
    // a member was given the next number by hand: the number shown and the number given skip it
    $next = Seq::peek('passbook');
    DB::run('UPDATE members SET passbook_no = :p WHERE id = :m', ['p' => $next, 'm' => $GLOBALS['v44']['inst']]);
    $shown = Members::peekPassbook();
    check($shown > $next, 'Get passbook number shows the next free number (' . $next . ' taken, ' . $shown . ' shown)');
    eq(Seq::peek('passbook'), $next, '...and takes nothing from the series');
    $b = Members::nextPassbook();
    eq($b, $shown, 'the number shown is the number given when the member is saved');
    eq(Seq::peek('passbook') > $b, true, 'the series moves on');
});
