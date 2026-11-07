<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.member';
$id = gint('id');
$m = $id ? Coop::member($id) : null;
if ($id && !$m) {
    flash('warning', 'Member not found.');
    redirect(url('coop.members'));
}
if ($m && !Coop::branchAllowed($m['branch_id'] ? (int)$m['branch_id'] : null)) {
    Access::denyPage($pageKey, 'open a member of another branch on');
}
$tab = in_array(g('tab'), ['profile', 'accounts', 'documents', 'history'], true) && $m ? (string)g('tab') : 'profile';

$textFields = ['salutation' => 10, 'last_name' => 60, 'first_name' => 60, 'middle_name' => 60, 'suffix' => 10, 'birth_place' => 120, 'gender' => 1, 'civil_status' => 12, 'nationality' => 40,
    'tin' => 20, 'email' => 120, 'mobile' => 40, 'res_house' => 60, 'res_street' => 120, 'res_barangay' => 80, 'res_city' => 80, 'res_province' => 80, 'res_postal' => 10,
    'prov_house' => 60, 'prov_street' => 120, 'prov_city' => 80, 'prov_province' => 80, 'prov_postal' => 10, 'off_house' => 60, 'off_street' => 120, 'off_city' => 80, 'off_province' => 80, 'off_postal' => 10,
    'education' => 60, 'year_completed' => 10, 'course' => 120, 'occupation' => 80, 'organization' => 120, 'employment_type' => 20, 'employer_name' => 120, 'employer_contact' => 60,
    'employer_address' => 255, 'account_class' => 40, 'account_group' => 60, 'address_subgroup' => 60, 'savings_group' => 60, 'institution_name' => 160, 'alert_note' => 255,
    'notes' => 2000, 'passbook_no' => 20];

// confidential details of an existing member (date of birth, TIN, contact numbers, home addresses) are masked for a
// user without the special permission "can see confidential member details": that user's save keeps them as they are
$confOk = !$m || !Party::ready() || Party::canSeeConfidential();
$confFields = ['birth_date', 'tin', 'mobile', 'email', 'res_house', 'res_street', 'res_barangay', 'res_city', 'res_province', 'res_postal',
    'prov_house', 'prov_street', 'prov_city', 'prov_province', 'prov_postal'];

/**
 * Which defaults tabs the user may change: the customer and supplier defaults with the Edit right on Customer Center /
 * Vendor Center, the water defaults with the Edit right on Water Service Accounts (and Create to open an account).
 */
function member_defaults_rights(?array $m): array
{
    $ready = MemberDefaults::ready() && Party::ready();
    return [
        'customer' => $ready && Access::can('cust.center', 'edit'),
        'vendor' => $ready && Access::can('vend.center', 'edit'),
        'water' => MemberDefaults::waterReady() && (Access::can('water.member_list', 'edit') || Access::can('water.member_list', 'create')),
        'water_open' => MemberDefaults::waterReady() && Access::can('water.member_list', 'create'),
    ];
}

handle_post(function () use ($m, $pageKey, $textFields, $confOk, $confFields) {
    $action = (string)p('action', 'save');
    if ($action === 'status') {
        Access::require('coop.members', 'approve');
        Members::changeStatus((int)$m['id'], (string)p('to'), (string)p('status_date'), trim((string)p('reason')), trim((string)p('reference')), 'coop.members');
        flash('success', 'Status changed to ' . (Coop::MEMBER_STATUSES[p('to')] ?? p('to')) . '.');
        redirect(url('coop.member', ['id' => $m['id']]));
    }
    if ($action === 'hold') {
        Access::require($pageKey, 'edit');
        Members::setHold((int)$m['id'], p('hold') === '1', trim((string)p('reason')), $pageKey);
        flash('success', p('hold') === '1' ? 'The member is on hold.' : 'The hold was released.');
        redirect(url('coop.member', ['id' => $m['id']]));
    }
    if ($action === 'note') {
        Access::require($pageKey, 'edit');
        $note = trim((string)p('note'));
        if ($note === '') {
            fail('Write the note.');
        }
        DB::insert('member_notes', ['member_id' => $m['id'], 'note' => mb_substr($note, 0, 2000), 'note_type' => in_array(p('note_type'), ['note', 'call', 'visit', 'complaint'], true) ? p('note_type') : 'note', 'created_by' => Auth::id()]);
        Audit::log('note', 'coop', 'member', (int)$m['id'], $m['full_name'], ['page_key' => $pageKey, 'reason' => mb_substr($note, 0, 200)]);
        flash('success', 'Note added.');
        redirect(url('coop.member', ['id' => $m['id'], 'tab' => 'history']));
    }
    if ($action === 'document') {
        Access::require($pageKey, 'edit');
        Members::addDocument((int)$m['id'], ['doc_type' => p('doc_type'), 'doc_no' => p('doc_no'), 'issued_on' => p('issued_on'), 'expires_on' => p('expires_on'), 'notes' => p('doc_notes')], $_FILES['file'] ?? null, $pageKey);
        flash('success', 'Document recorded.');
        redirect(url('coop.member', ['id' => $m['id'], 'tab' => 'documents']));
    }
    if ($action === 'remove_document') {
        Access::require($pageKey, 'delete');
        $doc = DB::row('SELECT * FROM member_documents WHERE id = :d AND member_id = :m', ['d' => pint('doc_id'), 'm' => $m['id']]);
        if (!$doc) {
            fail('Document not found.');
        }
        if (trim((string)p('reason')) === '') {
            fail('Enter the reason.');
        }
        // the file stays in storage; the record is kept in the audit trail
        DB::run('DELETE FROM member_documents WHERE id = :d', ['d' => $doc['id']]);
        Audit::log('delete', 'coop', 'member_document', (int)$doc['id'], $m['full_name'] . ': ' . $doc['doc_type'], ['page_key' => $pageKey, 'reason' => p('reason'), 'snapshot' => $doc]);
        flash('success', 'Document removed from the list.');
        redirect(url('coop.member', ['id' => $m['id'], 'tab' => 'documents']));
    }

    // the profile
    Access::require($pageKey, $m ? 'edit' : 'create');
    // the form as typed, shown again when the save is refused (postedForKind() changes $_POST for the save)
    $GLOBALS['MEMBER_FORM_TYPED'] = $_POST;
    Members::postedForKind(array_key_exists(p('member_kind'), Members::KINDS) ? (string)p('member_kind') : 'individual');
    $d = [];
    foreach ($textFields as $f => $len) {
        if (array_key_exists($f, $_POST)) {
            $d[$f] = mb_substr(trim((string)$_POST[$f]), 0, $len);
            if ($d[$f] === '') {
                $d[$f] = null;
            }
        }
    }
    $d['member_kind'] = array_key_exists(p('member_kind'), Members::KINDS) ? p('member_kind') : 'individual';
    $joint = $d['member_kind'] !== 'individual';
    if (!$joint || !$m) {
        $d['last_name'] = $d['last_name'] ?? '';
        $d['first_name'] = $d['first_name'] ?? '';
    }
    $d['branch_id'] = pint('branch_id') ?: Coop::defaultBranchId();
    $d['member_type_id'] = pint('member_type_id');
    $d['cluster_id'] = pint('cluster_id');
    $d['segment_id'] = pint('segment_id');
    $d['account_officer_id'] = pint('account_officer_id');
    // a joint account or an institution has no date of birth on its form: the record keeps what it has
    $d['birth_date'] = $joint ? ($m['birth_date'] ?? null) : (p('birth_date') ?: null);
    $d['membership_date'] = p('membership_date') ?: null;
    $d['pmes_date'] = p('pmes_date') ?: null;
    $d['subscribed_shares'] = max(0, (int)p('subscribed_shares'));
    $d['par_value'] = Dec::fix(Dec::of(p('par_value') ?: Coop::setting('default_par_value', '100')));
    if (!$d['member_type_id']) {
        fail('Choose the member type.');
    }
    if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        fail('The e-mail address is not valid.');
    }
    if ($d['pmes_date'] && !valid_date($d['pmes_date'])) {
        fail('Enter a valid PMES date.');
    }
    if (!$m) {
        $d['member_no'] = trim((string)p('member_no')) ?: null;
    }
    $pbPeek = trim((string)p('passbook_peek'));
    $pbSeries = p('passbook_series') === '1' && $pbPeek !== '' && ($d['passbook_no'] ?? null) === $pbPeek;
    $pbTyped = !$pbSeries && !empty($d['passbook_no']) && $d['passbook_no'] !== ($m['passbook_no'] ?? null);
    if ($m && !$confOk) {
        foreach ($confFields as $f) {
            $d[$f] = $m[$f] ?? null;
        }
    }
    // Water Defaults: member columns, applied to the member's water-service account after the save
    $defaults = member_defaults_rights($m);
    if ($defaults['water'] && array_key_exists('water_defaults_sent', $_POST)) {
        $d += MemberDefaults::postedWater();
    }
    $d['allow_duplicate'] = p('allow_duplicate') === '1';
    if (!$m && Party::ready()) {
        // a person or organization already on file (a store customer, a supplier) joins that party instead of a new one
        $d['link_party_id'] = pint('link_party_id');
        if (!$d['link_party_id'] && !$d['allow_duplicate']) {
            $individual = $d['member_kind'] === 'individual';
            $cands = Party::candidatesFor([
                'legal_name' => $individual ? Party::personName($d['first_name'], $d['middle_name'] ?? '', $d['last_name'], $d['suffix'] ?? '') : (string)($d['institution_name'] ?? ''),
                'display_name' => Members::fullName($d), 'tin' => $d['tin'] ?? null, 'email' => $d['email'] ?? null, 'mobile' => $d['mobile'] ?? null,
                'birth_date' => $individual ? $d['birth_date'] : null,
            ]);
            // parties that are members already are caught by the same-name-and-birth-date rule
            $cands = array_filter($cands, fn($c) => !Party::isMember((int)$c['party']['id']));
            if ($cands) {
                fail(Party::candidatesMessage($cands, 'person', 'This is a different person') . ' To use it, choose it under "Already on file as".');
            }
        }
    }
    $tabs = [];
    foreach (['contacts', 'dependents', 'relatives', 'finances', 'assets', 'beneficiaries', 'representatives', 'attendance'] as $k) {
        if (array_key_exists($k . '_sent', $_POST)) {
            $tabs[$k] = coop_grid_posted($k);
        }
    }
    if ($m && !$confOk) {
        unset($tabs['contacts']);
    }
    if ($joint && array_key_exists('contacts', $tabs)) {
        // a joint account or an institution has no Mobile box: its mobile is the first mobile of its contact list
        $mob = array_values(array_filter($tabs['contacts'], fn($r) => ($r['contact_type'] ?? 'mobile') === 'mobile' && trim((string)($r['number'] ?? '')) !== ''));
        $d['mobile'] = $mob ? mb_substr(trim((string)$mob[0]['number']), 0, 40) : null;
    }
    if ($joint) {
        $tk = Members::types()[(int)$d['member_type_id']]['member_kind'] ?? null;
        $kindHasTypes = (bool)array_filter(Members::types(), fn($t) => $t['member_kind'] === $d['member_kind'] && (int)$t['is_active'] === 1);
        if ($tk !== null && $tk !== $d['member_kind'] && $kindHasTypes && (!$m || (int)$m['member_type_id'] !== (int)$d['member_type_id'])) {
            fail('Choose a member type of ' . ($d['member_kind'] === 'joint' ? 'a joint account' : 'an institution') . ' (' . Members::types()[(int)$d['member_type_id']]['name'] . ' is for ' . (Members::KINDS[$tk] ?? $tk) . ').');
        }
    }
    // the finance rows come from three grids (income, expenses, other outlay) with their kind
    if (array_key_exists('finances_sent', $_POST)) {
        $tabs['finances'] = [];
        foreach (['income', 'expense', 'outlay'] as $kind) {
            foreach (coop_grid_posted('fin_' . $kind) as $r) {
                $tabs['finances'][] = $r + ['kind' => $kind];
            }
        }
    }
    $beneficiaryPct = '0';
    foreach ($tabs['beneficiaries'] ?? [] as $b) {
        if (trim((string)($b['name'] ?? '')) !== '') {
            $beneficiaryPct = Dec::add($beneficiaryPct, Dec::of($b['share_pct'] ?? '0'), 2);
        }
    }
    if (Dec::cmp($beneficiaryPct, '100') > 0) {
        fail('The beneficiaries\' shares add up to more than 100 % (' . Dec::fix($beneficiaryPct) . ' %).');
    }
    $pbGiven = null;
    $newId = DB::tx(function () use ($d, $tabs, $m, $pageKey, $defaults, $pbSeries, $pbTyped, &$pbGiven) {
        if ($pbSeries || $pbTyped) {
            // the passbook series is locked first: two members saved at once never get the same number
            DB::value("SELECT next_no FROM sequences WHERE seq_key = 'passbook' FOR UPDATE");
        }
        if ($pbSeries) {
            $d['passbook_no'] = $pbGiven = Members::nextPassbook();
        } elseif ($pbTyped) {
            $other = DB::row('SELECT member_no, full_name FROM members WHERE passbook_no = :p AND id <> :id LIMIT 1', ['p' => $d['passbook_no'], 'id' => $m['id'] ?? 0]);
            if ($other) {
                fail('Passbook number ' . $d['passbook_no'] . ' is already given to ' . $other['full_name'] . ' (member no. ' . $other['member_no'] . '). Click "Get passbook number" for the next free one.');
            }
        }
        $newId = Members::save($d, $tabs, $m ? (int)$m['id'] : null, $pageKey);
        Members::setPicture($newId, 'photo', $_FILES['photo'] ?? null, $pageKey);
        Members::setPicture($newId, 'signature', $_FILES['signature'] ?? null, $pageKey);
        // the member's defaults in the other lines of business, on the member's own records (one name)
        if ($defaults['customer'] && array_key_exists('customer_defaults_sent', $_POST)) {
            MemberDefaults::saveCustomer($newId, MemberDefaults::postedCustomer(), trim((string)p('cust_change_reason')), $pageKey);
        }
        if ($defaults['vendor'] && array_key_exists('vendor_defaults_sent', $_POST)) {
            MemberDefaults::saveVendor($newId, MemberDefaults::postedVendor(), $pageKey);
        }
        if ($defaults['water'] && array_key_exists('water_defaults_sent', $_POST)) {
            $before = count(MemberDefaults::waterAccounts(Coop::member($newId)));
            $acct = MemberDefaults::applyWater($newId, $pageKey, pint('water_account_pick'), $defaults['water_open']);
            if ($acct && $before === 0) {
                $GLOBALS['WATER_OPENED'] = (string)(WaterAccounts::get($acct)['account_number'] ?? $acct);
            }
        }
        return $newId;
    });
    $opened = !empty($GLOBALS['WATER_OPENED']) ? ' Water-service account ' . $GLOBALS['WATER_OPENED'] . ' opened for the member.' : '';
    if ($pbGiven !== null && $pbGiven !== $pbPeek) {
        $opened .= ' Passbook number ' . $pbGiven . ' was given (' . $pbPeek . ' was taken in the meantime).';
    }
    flash('success', ($m ? 'Member saved.' : 'Member added as an applicant. Activate the membership when the Board has approved it and the share capital is paid.') . $opened);
    redirect_after_save(url($pageKey, $joint ? ['kind' => $d['member_kind']] : []), url($pageKey, ['id' => $newId]));
});
// a refused save shows the form as it was typed, the joint boxes included
if (!empty($GLOBALS['FORM_ERROR']) && isset($GLOBALS['MEMBER_FORM_TYPED'])) {
    $_POST = $GLOBALS['MEMBER_FORM_TYPED'];
}

// ----------------------------------------------------------------------------------- the page
$rec = $m ?? ['member_kind' => g('kind') === 'joint' ? 'joint' : (g('kind') === 'institutional' ? 'institutional' : 'individual'), 'branch_id' => Coop::defaultBranchId(),
    'nationality' => 'Filipino', 'par_value' => Coop::setting('default_par_value', '100'), 'subscribed_shares' => 0, 'membership_date' => null,
    'member_type_id' => (int)DB::value("SELECT id FROM member_types WHERE is_active = 1 ORDER BY sort_order, id LIMIT 1")];
// enrolling a party already on file (Party Center > Enroll as member): its details fill the form
$fromParty = !$m && Party::ready() && gint('party') ? Party::get((int)gint('party')) : null;
if ($fromParty && in_array($fromParty['status'], ['active', 'inactive'], true) && !Party::isMember((int)$fromParty['id'])) {
    if ($fromParty['party_type'] === 'organization') {
        $rec['member_kind'] = 'institutional';
        $rec['institution_name'] = $fromParty['legal_name'];
    } else {
        $rec += ['last_name' => $fromParty['last_name'], 'first_name' => $fromParty['first_name'], 'middle_name' => $fromParty['middle_name'], 'suffix' => $fromParty['suffix']];
        if (!$fromParty['last_name'] && !$fromParty['first_name']) {
            // a name kept in one piece: "Juan Dela Cruz" or "Dela Cruz, Juan"
            $nm = trim((string)$fromParty['legal_name']);
            if (str_contains($nm, ',')) {
                [$ln, $fn] = array_map('trim', explode(',', $nm, 2));
            } else {
                $parts = preg_split('/\s+/', $nm);
                $fn = (string)array_shift($parts);
                $ln = implode(' ', $parts);
            }
            $rec['last_name'] = $ln;
            $rec['first_name'] = $fn;
        }
    }
    $rec += ['tin' => $fromParty['tin'], 'email' => $fromParty['email'], 'mobile' => $fromParty['mobile'], 'birth_date' => $fromParty['birth_date']];
    if ($fromParty['home_branch_id']) {
        $rec['branch_id'] = (int)$fromParty['home_branch_id'];
    }
} else {
    $fromParty = null;
}
$kind = (string)fv('member_kind', $rec, 'individual');
$load = fn(string $table, string $order = 'id') => $m ? DB::all("SELECT * FROM $table WHERE member_id = :m ORDER BY $order", ['m' => $m['id']]) : [];
$grid = fn(string $k, array $dbRows) => is_post() && array_key_exists($k . '_sent', $_POST) ? coop_grid_posted($k) : $dbRows;
// a confidential box: the input, or for a user without the permission the masked value (not sent with the form)
$confIn = function (string $name, string $label, array $opt) use ($rec, $confOk): string {
    if ($confOk) {
        return input_field($name, $label, fv($name, $rec), $opt);
    }
    $v = (string)($rec[$name] ?? '');
    if ($name === 'birth_date' && $v !== '') {
        $v = fdate($v);
    }
    return '<div class="' . h($opt['class'] ?? 'col-md-3') . '"><label class="form-label">' . h($label) . '</label>'
        . '<input class="form-control" value="' . h($v === '' ? '' : Party::mask($v)) . '" disabled title="Confidential member detail"></div>';
};
$users = DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND COALESCE(is_platform,0) = 0 ORDER BY full_name");

$actions = [];
if ($m) {
    if (Access::page('coop.member_statement')) {
        $actions[] = btn_link(url('coop.member_statement', ['member' => $m['id']]), 'Statement', 'btn-outline-secondary', 'file-earmark-text');
    }
    if (Access::can('teller.or', 'create')) {
        $actions[] = btn_link(url('teller.or', ['member' => $m['id']]), 'Receive payment', 'btn-outline-primary', 'receipt');
    }
    if (Access::can('loan.application', 'create')) {
        $actions[] = btn_link(url('loan.application', ['member' => $m['id']]), 'Loan application', 'btn-outline-secondary', 'cash-coin');
    }
    if (Access::can('sav.accounts', 'create')) {
        $actions[] = btn_link(url('sav.accounts', ['a' => 'open', 'member' => $m['id']]), 'Open account', 'btn-outline-secondary', 'plus-lg');
    }
    if (Access::can($pageKey, 'print')) {
        $actions[] = btn_link(url('coop.member_statement', ['a' => 'id_card', 'member' => $m['id']]), 'ID card', 'btn-outline-secondary', 'person-vcard');
    }
    // a member who has left: their deposits and share capital are paid out in the settlement (Member Disbursement is
    // no longer in the menu; it opens from here)
    if (in_array($m['status'], ['resigned', 'terminated', 'deceased'], true) && Access::can('teller.disburse', 'create')
        && DB::value("SELECT COUNT(*) FROM sav_accounts WHERE member_id = :m AND status <> 'closed' AND balance > 0", ['m' => $m['id']])) {
        $actions[] = btn_link(url('teller.disburse', ['member' => $m['id']]), 'Settle the accounts', 'btn-outline-danger', 'cash-stack');
    }
}
$actions[] = btn_link(url('coop.members'), 'Member Center', 'btn-outline-secondary', 'arrow-left');
// the address sub-groups are kept from the form itself (Manage beside the box): Address Sub-groups or the member lists
$sgCan = Access::page('water.address_subgroups') || Access::page('coop.lists');
$sgBox = function (string $html) use ($sgCan): string {
    if (!$sgCan) {
        return $html;
    }
    $btn = '<button type="button" class="sgm-manage" data-subgroup-manage data-endpoint="' . h(base_url() . '/water/process/addressSubgroups.php') . '" data-page-key="coop.member" data-csrf="' . h(csrf_token()) . '">'
        . '<i class="bi bi-plus-lg" aria-hidden="true"></i> Add, edit or delete sub-groups</button>';
    return substr($html, 0, -6) . $btn . '</div>';
};
coop_layout($m ? $m['full_name'] : 'New member', ['actions' => implode(' ', $actions), 'js' => $sgCan ? ['js/subgroup_manager.js'] : []]);

if ($m) {
    echo '<div class="mb-3">' . member_card($m, false) . '</div>';
    echo coop_tabs(['profile' => 'Profile', 'accounts' => 'Accounts and loans', 'documents' => 'Documents', 'history' => 'History and notes'], $tab, $pageKey, ['id' => $m['id']]);
}

if ($tab === 'profile'):
    $canSave = Access::can($pageKey, $m ? 'edit' : 'create');
    $rights = member_defaults_rights($m);
    $showWater = MemberDefaults::waterReady();
    $showDefaults = MemberDefaults::ready() && Party::ready();
    $types = Members::typeOptions(false);
    $listBlank = fn(array $names) => array_combine($names, $names);
?>
<?php
    // a joint account or an institution has the form of the former program (Joint/Institution Details, Membership
    // Information): its own boxes, named j_* and read in place of the individual's when the member is saved
    $isJoint = $kind !== 'individual';
    $jrec = [];
    foreach ($rec as $rk => $rv) {
        $jrec['j_' . $rk] = $rv;
    }
    // a new joint account or institution starts with the first member type of its kind (Associate - Joint, Associate -
    // Institutional on a new installation); its Type list shows the types of its kind
    $kindType = ['joint' => 0, 'institutional' => 0];
    foreach (Members::types() as $tid => $t) {
        if ((int)$t['is_active'] === 1 && isset($kindType[$t['member_kind']]) && !$kindType[$t['member_kind']]) {
            $kindType[$t['member_kind']] = (int)$tid;
        }
    }
    if (!$m && $isJoint && !is_post() && !empty($kindType[$kind])) {
        $jrec['j_member_type_id'] = $kindType[$kind];
    }
    $jv = fn(string $f, $default = '') => fv('j_' . $f, $jrec, $default);
    $confInJ = function (string $name, string $label, array $opt) use ($rec, $confOk, $jrec): string {
        if ($confOk) {
            return input_field('j_' . $name, $label, fv('j_' . $name, $jrec), $opt);
        }
        $v = (string)($rec[$name] ?? '');
        return '<div class="' . h($opt['class'] ?? 'col-md-3') . '"><label class="form-label">' . h($label) . '</label>'
            . '<input class="form-control" value="' . h($v === '' ? '' : Party::mask($v)) . '" disabled title="Confidential member detail"></div>';
    };
    $pbNext = Members::peekPassbook();
    // the passbook number and its "Get passbook number" button (the next number of Document Numbering > Passbook)
    $passbookBox = function (string $prefix, array $source, string $cls) use ($pbNext): string {
        $v = (string)fv($prefix . 'passbook_no', $source);
        return '<div class="' . h($cls) . '"><label class="form-label" for="' . h($prefix) . 'pbNo">Passbook number</label><div class="input-group input-group-sm pb-get">'
            . '<input class="form-control" id="' . h($prefix) . 'pbNo" name="' . h($prefix) . 'passbook_no" maxlength="20" value="' . h($v) . '" autocomplete="off">'
            . ($pbNext !== '' ? '<button type="button" class="btn btn-outline-primary" data-pb-get="' . h($pbNext) . '" data-pb-for="' . h($prefix) . '"><i class="bi bi-hash"></i> Get passbook number</button>' : '')
            . '</div><input type="hidden" name="' . h($prefix) . 'passbook_series" value="' . h((string)fv($prefix . 'passbook_series', null)) . '">'
            . '<input type="hidden" name="' . h($prefix) . 'passbook_peek" value="' . h((string)fv($prefix . 'passbook_peek', null)) . '"></div>';
    };
    $relOpts = Coop::memberOptions();
    $typeNames = Members::typeOptions(false);
    $plaqueType = mb_strtoupper((string)($typeNames[(int)$jv('member_type_id')] ?? ''));
?>
<form method="post" enctype="multipart/form-data" data-once id="memberForm" class="member-form<?= $isJoint ? ' is-joint' : '' ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <fieldset <?= $canSave ? '' : 'disabled' ?>>
  <?php if (!$m && Party::ready()): ?>
  <div class="row g-2 mb-2">
    <?= select_field('link_party_id', 'Already on file as a customer or supplier (optional)', Party::options(isset($fromParty['id']) ? (int)$fromParty['id'] : (pint('link_party_id') ?: null), true, true), fv('link_party_id', null, $fromParty['id'] ?? ''), ['class' => 'col-md-8', 'blank' => 'Not on file: a new name', 'extra' => 'data-combo']) ?>
  </div>
  <?php endif; ?>
  <?php
    // the sub-tabs: [pane id, label, label of a joint account or institution, the kinds that have it (blank: every kind)]
    // ?dtab=water|customer|vendor (from the Customer / Vendor Center) opens that one, else Personal
    $subTabs = ['personal' => ['tPersonal', 'Personal', 'Joint/Institution Details', ''], 'family' => ['tAdditional', 'Family and beneficiaries', '', 'individual'],
        'financial' => ['tFinancial', 'Financial', '', 'individual'], 'membership' => ['tMembership', 'Membership', 'Membership Information', ''],
        'grouping' => ['tGrouping', 'Loan and savings grouping', '', 'individual']];
    if ($showWater) {
        $subTabs['water'] = ['tWater', 'Water Defaults', '', ''];
    }
    if ($showDefaults) {
        $subTabs['customer'] = ['tCustomer', 'Customer Defaults', '', ''];
        $subTabs['vendor'] = ['tVendor', 'Vendor Defaults', '', ''];
    }
    $openTab = isset($subTabs[(string)g('dtab')]) ? (string)g('dtab') : 'personal';
    if ($isJoint && $subTabs[$openTab][3] === 'individual') {
        $openTab = 'personal';
    }
    $pane = fn(string $key) => 'tab-pane fade' . ($key === $openTab ? ' show active' : '');
    $only = fn(string $key) => $subTabs[$key][3] !== '' ? ' data-kind-block="' . h($subTabs[$key][3]) . '"' . ($subTabs[$key][3] === 'individual' && $isJoint ? ' hidden' : '') : '';
  ?>
  <style>@media (min-width: 992px) { .member-subtabs .nav-link { padding-left: .62rem; padding-right: .62rem; } }</style>
  <ul class="nav nav-tabs member-subtabs" role="tablist">
    <?php foreach ($subTabs as $key => [$tid, $tlabel, $jlabel, $kinds]): ?>
    <li class="nav-item"<?= $kinds !== '' ? ' data-kinds="' . h($kinds) . '"' . ($isJoint && $kinds === 'individual' ? ' hidden' : '') : '' ?>><button type="button" class="nav-link<?= $key === $openTab ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#<?= $tid ?>"
      <?= $jlabel !== '' ? 'data-label-individual="' . h($tlabel) . '" data-label-joint="' . h($jlabel) . '"' : '' ?>><?= h($isJoint && $jlabel !== '' ? $jlabel : $tlabel) ?></button></li>
    <?php endforeach; ?>
  </ul>
  <script>
  // on a phone the sub-tabs scroll sideways: the one opened (?dtab=) is brought into view
  (function () {
    var bar = document.querySelector('.member-subtabs'), on = bar && bar.querySelector('.nav-link.active');
    if (on && bar.scrollWidth > bar.clientWidth) { bar.scrollLeft += on.getBoundingClientRect().left - bar.getBoundingClientRect().left - 12; }
  })();
  </script>
  <div class="card coop-tabbed"><div class="card-body tab-content">

  <div class="<?= $pane('personal') ?>" id="tPersonal" data-no-combo>
    <div class="row g-2 align-items-end member-head">
      <?= select_field('member_kind', 'Kind of member', Members::KINDS, $kind, ['class' => 'col-md-3', 'extra' => 'id="memberKind" autocomplete="off"']) ?>
      <?php if (!$m): ?>
      <div class="col-md-3"><label class="form-label" for="memberNo" data-label-individual="Member no. (blank = next number)" data-label-joint="Member ID (blank = next number)"><?= $isJoint ? 'Member ID (blank = next number)' : 'Member no. (blank = next number)' ?></label>
        <input class="form-control form-control-sm" type="text" id="memberNo" name="member_no" value="<?= h((string)fv('member_no', null)) ?>" placeholder="<?= h(Seq::peek('member')) ?>"></div>
      <?php endif; ?>
      <div class="col-md jplaque" data-kind-block="joint,institutional"<?= $isJoint ? '' : ' hidden' ?> aria-live="polite">
        <?php if ($m): ?><div class="jplaque-id">Member ID <strong><?= h($m['member_no']) ?></strong></div><?php endif; ?>
        <div class="jplaque-state"><?= h($m ? mb_strtoupper(Coop::MEMBER_STATUSES[$m['status']] ?? $m['status']) : 'NEW') ?></div>
        <div class="jplaque-type" id="jPlaqueType"><?= h($plaqueType) ?></div>
      </div>
    </div>

    <div data-kind-block="individual"<?= $isJoint ? ' hidden' : '' ?>>
    <input type="hidden" name="contacts_sent" value="1">
    <div class="row g-2 mt-1">
      <?= select_field('salutation', 'Salutation', ['Mr.' => 'Mr.', 'Ms.' => 'Ms.', 'Mrs.' => 'Mrs.', 'Dr.' => 'Dr.', 'Engr.' => 'Engr.', 'Atty.' => 'Atty.', 'Hon.' => 'Hon.'], fv('salutation', $rec), ['class' => 'col-4 col-md-1', 'blank' => '']) ?>
      <?= input_field('last_name', 'Last name', fv('last_name', $rec), ['class' => 'col-8 col-md-3']) ?>
      <?= input_field('first_name', 'First name', fv('first_name', $rec), ['class' => 'col-md-3']) ?>
      <?= input_field('middle_name', 'Middle name', fv('middle_name', $rec), ['class' => 'col-8 col-md-3']) ?>
      <?= input_field('suffix', 'Suffix', fv('suffix', $rec), ['class' => 'col-4 col-md-2', 'extra' => 'placeholder="Jr., III"']) ?>
      <?= $confIn('birth_date', 'Date of birth', ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?= input_field('birth_place', 'Place of birth', fv('birth_place', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= select_field('gender', 'Sex', ['F' => 'Female', 'M' => 'Male'], fv('gender', $rec), ['class' => 'col-6 col-md-2', 'blank' => '']) ?>
      <?= select_field('civil_status', 'Civil status', Members::CIVIL, fv('civil_status', $rec), ['class' => 'col-6 col-md-2', 'blank' => '']) ?>
      <?= input_field('nationality', 'Nationality', fv('nationality', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= $confIn('tin', 'TIN', ['class' => 'col-6 col-md-3']) ?>
      <?= $confIn('mobile', 'Mobile', ['class' => 'col-6 col-md-3', 'type' => 'tel']) ?>
      <?= $confIn('email', 'E-mail', ['class' => 'col-6 col-md-3', 'type' => 'email']) ?>
    </div>
    <div class="fw-semibold small mt-3 mb-1">Residence address</div>
    <div class="row g-2">
      <?= $confIn('res_house', 'House / unit no.', ['class' => 'col-6 col-md-2']) ?>
      <?= $confIn('res_street', 'Street', ['class' => 'col-6 col-md-3']) ?>
      <?= $confIn('res_barangay', 'Barangay', ['class' => 'col-6 col-md-2']) ?>
      <?= $confIn('res_city', 'City / municipality', ['class' => 'col-6 col-md-2']) ?>
      <?= $confIn('res_province', 'Province', ['class' => 'col-8 col-md-2']) ?>
      <?= $confIn('res_postal', 'ZIP', ['class' => 'col-4 col-md-1']) ?>
    </div>
    <details class="mt-2" <?= $m && ($m['prov_street'] || $m['off_street']) ? 'open' : '' ?>><summary class="small fw-semibold">Provincial and office addresses</summary>
      <div class="row g-2 mt-1">
        <?= $confIn('prov_house', 'Provincial: house no.', ['class' => 'col-6 col-md-2']) ?>
        <?= $confIn('prov_street', 'Street / barangay', ['class' => 'col-6 col-md-4']) ?>
        <?= $confIn('prov_city', 'City / municipality', ['class' => 'col-6 col-md-3']) ?>
        <?= $confIn('prov_province', 'Province', ['class' => 'col-4 col-md-2']) ?>
        <?= $confIn('prov_postal', 'ZIP', ['class' => 'col-2 col-md-1']) ?>
        <?= input_field('off_house', 'Office: building / no.', fv('off_house', $rec), ['class' => 'col-6 col-md-2']) ?>
        <?= input_field('off_street', 'Street / barangay', fv('off_street', $rec), ['class' => 'col-6 col-md-4']) ?>
        <?= input_field('off_city', 'City / municipality', fv('off_city', $rec), ['class' => 'col-6 col-md-3']) ?>
        <?= input_field('off_province', 'Province', fv('off_province', $rec), ['class' => 'col-4 col-md-2']) ?>
        <?= input_field('off_postal', 'ZIP', fv('off_postal', $rec), ['class' => 'col-2 col-md-1']) ?>
      </div>
    </details>
    <div class="fw-semibold small mt-3 mb-1">Contact numbers</div>
    <?php if (!$confOk): ?>
    <div class="small text-muted">Hidden: you need the special permission to see confidential member details. Saving this page keeps the member's numbers as they are.</div>
    <?php else: ?>
    <?= coop_grid('gContacts', 'contacts', ['contact_type' => ['Type', 'select', ['mobile' => 'Mobile', 'landline' => 'Landline', 'office' => 'Office', 'other' => 'Other']], 'country_code' => ['Country', 'text', [], 'style="max-width:80px" placeholder="63"'],
        'area_code' => ['Area', 'text', [], 'style="max-width:80px"'], 'number' => ['Number', 'text'], 'is_primary' => ['Primary', 'check']], $grid('contacts', $load('member_contacts', 'is_primary DESC, id')), 1, 'Add a number') ?>
    <?php endif; ?>
    <div class="fw-semibold small mt-3 mb-1">Education and work</div>
    <div class="row g-2">
      <?= select_field('education', 'Highest education', $listBlank(['Elementary', 'High school', 'Senior high school', 'Vocational', 'College', 'Post-graduate']), fv('education', $rec), ['class' => 'col-6 col-md-2', 'blank' => '']) ?>
      <?= input_field('year_completed', 'Year completed', fv('year_completed', $rec), ['class' => 'col-6 col-md-2']) ?>
      <?= input_field('course', 'Course', fv('course', $rec), ['class' => 'col-md-3']) ?>
      <?= input_field('occupation', 'Occupation', fv('occupation', $rec), ['class' => 'col-6 col-md-3']) ?>
      <?= select_field('employment_type', 'Employment', Members::EMPLOYMENT, fv('employment_type', $rec), ['class' => 'col-6 col-md-2', 'blank' => '']) ?>
      <?= input_field('employer_name', 'Employer / business name', fv('employer_name', $rec), ['class' => 'col-md-4']) ?>
      <?= input_field('employer_contact', 'Employer contact', fv('employer_contact', $rec), ['class' => 'col-md-3']) ?>
      <?= input_field('employer_address', 'Employer address', fv('employer_address', $rec), ['class' => 'col-md-5']) ?>
      <?= input_field('organization', 'Other organizations', fv('organization', $rec), ['class' => 'col-md-6']) ?>
    </div>
    <div class="row g-2 mt-2">
      <div class="col-md-4"><label class="form-label">Photo (JPG / PNG)</label><input class="form-control form-control-sm" type="file" name="photo" accept="image/*"></div>
      <div class="col-md-4"><label class="form-label">Specimen signature</label><input class="form-control form-control-sm" type="file" name="signature" accept="image/*"></div>
      <?php if ($m && $m['signature_file']): ?><div class="col-md-4"><div class="form-label">Signature on file</div><img src="<?= h(url('file.member', ['id' => $m['id'], 'f' => 'signature'])) ?>" alt="Specimen signature" style="max-height:60px"></div><?php endif; ?>
    </div>
    </div>

    <div class="jform mt-2" data-kind-block="joint,institutional"<?= $isJoint ? '' : ' hidden' ?>>
      <section class="jbox" aria-labelledby="jNameTitle">
        <div class="jbox-title" id="jNameTitle"><i class="bi bi-people" aria-hidden="true"></i> <span data-text-joint="Joint account" data-text-institutional="Institution"><?= $kind === 'institutional' ? 'Institution' : 'Joint account' ?></span></div>
        <div class="row g-2">
          <div class="col-12"><label class="form-label" for="jName">Membership name</label>
            <input class="form-control jname" id="jName" name="institution_name" maxlength="160" value="<?= h((string)fv('institution_name', $rec)) ?>" autocomplete="off"
              data-ph-joint="The holders, e.g. Santos, Ana or Ramon or Miguel" data-ph-institutional="The registered name, e.g. San Isidro Community Hospital"
              placeholder="<?= h($kind === 'institutional' ? 'The registered name, e.g. San Isidro Community Hospital' : 'The holders, e.g. Santos, Ana or Ramon or Miguel') ?>"></div>
        </div>
        <div class="jbox-sub mt-3">Authorized representatives</div>
        <input type="hidden" name="representatives_sent" value="1">
        <?= coop_grid('gReps', 'representatives', ['name' => ['Authorized representative name', 'text'], 'position' => ['Position / relation', 'text'], 'rep_member_id' => ['Member (if a member)', 'select', $relOpts]],
            $grid('representatives', $load('member_representatives')), 1, 'Add a representative') ?>
        <div class="jbox-hint" data-text-joint="The holders of the joint account and how they are related (wife, husband, son ...)." data-text-institutional="The officers who act for the institution (president, vice president, treasurer, secretary ...)."><?= $kind === 'institutional' ? 'The officers who act for the institution (president, vice president, treasurer, secretary ...).' : 'The holders of the joint account and how they are related (wife, husband, son ...).' ?></div>
      </section>
      <div class="row g-3">
        <div class="col-lg-6 d-grid gap-3">
          <section class="jbox" aria-labelledby="jResTitle">
            <div class="jbox-title" id="jResTitle"><i class="bi bi-house-door" aria-hidden="true"></i> Residence address</div>
            <div class="row g-2">
              <?= $confInJ('res_house', 'House / bldg no.', ['class' => 'col-8']) ?>
              <?= $confInJ('res_postal', 'Postal code', ['class' => 'col-4']) ?>
              <?= $confInJ('res_street', 'Street', ['class' => 'col-12']) ?>
              <?= $confInJ('res_barangay', 'Barangay', ['class' => 'col-md-4']) ?>
              <?= $confInJ('res_city', 'City / municipality', ['class' => 'col-6 col-md-4']) ?>
              <?= $confInJ('res_province', 'Province', ['class' => 'col-6 col-md-4']) ?>
            </div>
          </section>
          <section class="jbox" aria-labelledby="jOffTitle">
            <div class="jbox-title" id="jOffTitle"><i class="bi bi-building" aria-hidden="true"></i> Office / business address</div>
            <div class="row g-2">
              <?= input_field('j_off_house', 'House / bldg no.', $jv('off_house'), ['class' => 'col-8']) ?>
              <?= input_field('j_off_postal', 'Postal code', $jv('off_postal'), ['class' => 'col-4']) ?>
              <?= input_field('j_off_street', 'Street', $jv('off_street'), ['class' => 'col-12']) ?>
              <?= input_field('j_off_city', 'City / municipality', $jv('off_city'), ['class' => 'col-6']) ?>
              <?= input_field('j_off_province', 'Province', $jv('off_province'), ['class' => 'col-6']) ?>
            </div>
          </section>
        </div>
        <div class="col-lg-6">
          <section class="jbox h-100" aria-labelledby="jConTitle">
            <div class="jbox-title" id="jConTitle"><i class="bi bi-telephone" aria-hidden="true"></i> Contact details</div>
            <?php if (!$confOk): ?>
            <div class="small text-muted mb-2">Contact list hidden: you need the special permission to see confidential member details. Saving keeps the numbers as they are.</div>
            <?php else: ?>
            <?php
              // the contact list; a mobile kept on the record from before and not on the list is shown as its first row
              $jContacts = $grid('j_contacts', $load('member_contacts', 'is_primary DESC, id'));
              if ($m && !is_post() && trim((string)$m['mobile']) !== '' && !in_array(trim((string)$m['mobile']), array_map(fn($r) => trim((string)($r['number'] ?? '')), $jContacts), true)) {
                  array_unshift($jContacts, ['contact_type' => 'mobile', 'country_code' => '', 'area_code' => '', 'number' => $m['mobile'], 'is_primary' => $jContacts ? 0 : 1]);
              }
            ?>
            <input type="hidden" name="j_contacts_sent" value="1">
            <div class="jbox-sub">Contact list</div>
            <?= coop_grid('gJContacts', 'j_contacts', ['contact_type' => ['Type', 'select', ['mobile' => 'Mobile', 'landline' => 'Landline', 'office' => 'Office', 'other' => 'Other']], 'country_code' => ['Country', 'text', [], 'style="max-width:70px" placeholder="63"'],
                'area_code' => ['Area code', 'text', [], 'style="max-width:76px"'], 'number' => ['Number', 'text'], 'is_primary' => ['Primary', 'check']], $jContacts, 1, 'Add a number') ?>
            <?php endif; ?>
            <div class="row g-2 mt-1">
              <?= $confInJ('email', 'E-mail address', ['class' => 'col-md-7', 'type' => 'email']) ?>
              <?= $confInJ('tin', 'TIN (optional)', ['class' => 'col-md-5']) ?>
              <div class="col-12"><label class="form-label">Specimen signatures of the representatives (picture)</label><input class="form-control form-control-sm" type="file" name="j_signature" accept="image/*"></div>
              <?php if ($m && $m['signature_file']): ?><div class="col-12"><div class="form-label">Signatures on file</div><img src="<?= h(url('file.member', ['id' => $m['id'], 'f' => 'signature'])) ?>" alt="Specimen signatures" class="jsig"></div><?php endif; ?>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>

  <div class="<?= $pane('family') ?>" id="tAdditional"<?= $only('family') ?>>
    <?php foreach (['dependents', 'relatives', 'beneficiaries'] as $k): ?><input type="hidden" name="<?= $k ?>_sent" value="1"><?php endforeach; ?>
    <div class="fw-semibold small mb-1">Spouse, children and other dependents</div>
    <?= coop_grid('gDependents', 'dependents', ['kind' => ['Kind', 'select', ['spouse' => 'Spouse', 'child' => 'Child', 'dependent' => 'Other dependent']], 'last_name' => ['Last name', 'text'], 'first_name' => ['First name', 'text'],
        'middle_name' => ['Middle name', 'text'], 'relationship' => ['Relationship', 'text'], 'birth_date' => ['Birth date', 'date'], 'contact_no' => ['Contact', 'text'], 'is_dependent' => ['Dependent', 'check']],
        $grid('dependents', $load('member_dependents')), 0, 'Add a person') ?>
    <div class="fw-semibold small mt-3 mb-1">Beneficiaries</div>
    <?= coop_grid('gBeneficiaries', 'beneficiaries', ['name' => ['Name', 'text'], 'relationship' => ['Relationship', 'text'], 'birth_date' => ['Birth date', 'date'], 'contact_no' => ['Contact', 'text'],
        'address' => ['Address', 'text'], 'share_pct' => ['Share %', 'number', [], 'style="max-width:90px"'], 'is_primary' => ['Primary', 'check']], $grid('beneficiaries', $load('member_beneficiaries')), 0, 'Add a beneficiary') ?>
    <div class="fw-semibold small mt-3 mb-1">Relatives who are members</div>
    <?= coop_grid('gRelatives', 'relatives', ['relative_member_id' => ['Member', 'select', $relOpts], 'relationship' => ['Relationship', 'text']], $grid('relatives', $load('member_relatives')), 0, 'Add a relative') ?>
  </div>

  <div class="<?= $pane('financial') ?>" id="tFinancial"<?= $only('financial') ?>>
    <input type="hidden" name="finances_sent" value="1"><input type="hidden" name="assets_sent" value="1">
    <?php
    $fin = $m ? $load('member_finances') : [];
    if (is_post() && array_key_exists('finances_sent', $_POST)) {
        $fin = [];
        foreach (['income', 'expense', 'outlay'] as $kk) {
            foreach (coop_grid_posted('fin_' . $kk) as $r) {
                $fin[] = $r + ['kind' => $kk];
            }
        }
    }
    $byKind = fn(string $k) => array_values(array_filter($fin, fn($r) => ($r['kind'] ?? '') === $k));
    $finCols = ['name' => ['Item', 'text'], 'member_amount' => ['Member (monthly)', 'money'], 'spouse_amount' => ['Spouse (monthly)', 'money']];
    $ndi = $m ? Members::ndi((int)$m['id']) : null;
    ?>
    <div class="row g-3">
      <div class="col-lg-4"><div class="fw-semibold small mb-1">Income</div><?= coop_grid('gIncome', 'fin_income', $finCols, $byKind('income'), 1, 'Add income') ?></div>
      <div class="col-lg-4"><div class="fw-semibold small mb-1">Expenses</div><?= coop_grid('gExpense', 'fin_expense', $finCols, $byKind('expense'), 1, 'Add an expense') ?></div>
      <div class="col-lg-4"><div class="fw-semibold small mb-1">Other cash outlay (amortizations elsewhere)</div><?= coop_grid('gOutlay', 'fin_outlay', $finCols, $byKind('outlay'), 0, 'Add an outlay') ?></div>
    </div>
    <?php if ($ndi): ?><div class="coop-tiles mt-3">
      <div class="coop-tile"><div class="t-label">Monthly income</div><div class="t-value"><?= cm($ndi['income']) ?></div></div>
      <div class="coop-tile is-warn"><div class="t-label">Expenses and outlay</div><div class="t-value"><?= cm(Dec::add($ndi['expense'], $ndi['outlay'], 2)) ?></div></div>
      <div class="coop-tile is-ok"><div class="t-label">Net disposable income</div><div class="t-value"><?= cm($ndi['ndi']) ?></div><div class="t-sub">Checked against the loan products' NDI requirement</div></div>
    </div><?php endif; ?>
    <div class="fw-semibold small mt-3 mb-1">Assets and liabilities</div>
    <?= coop_grid('gAssets', 'assets', ['asset_type' => ['Kind', 'select', ['asset' => 'Asset', 'liability' => 'Liability']], 'description' => ['Description', 'text'], 'amount' => ['Amount', 'money']], $grid('assets', $load('member_assets')), 0, 'Add an item') ?>
  </div>

  <div class="<?= $pane('membership') ?>" id="tMembership" data-no-combo>
    <div data-kind-block="individual"<?= $isJoint ? ' hidden' : '' ?>>
    <input type="hidden" name="attendance_sent" value="1">
    <div class="row g-2">
      <?= select_field('branch_id', 'Branch', Coop::branchOptions(), fv('branch_id', $rec), ['class' => 'col-md-3', 'required' => true]) ?>
      <?= select_field('member_type_id', 'Member type', $types, fv('member_type_id', $rec), ['class' => 'col-md-3', 'required' => true]) ?>
      <?= input_field('pmes_date', 'PMES attended', fv('pmes_date', $rec), ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?= input_field('membership_date', 'Membership date', fv('membership_date', $rec), ['class' => 'col-6 col-md-2', 'type' => 'date']) ?>
      <?php if ($m): ?><div class="col-md-2"><div class="form-label">Status</div><div class="pt-1"><?= coop_status($m['status']) ?> <span class="small text-muted">since <?= h(fdate($m['status_date'])) ?></span></div></div><?php endif; ?>
      <?= input_field('subscribed_shares', 'Subscribed shares', fv('subscribed_shares', $rec, '0'), ['class' => 'col-6 col-md-2', 'type' => 'number', 'step' => '1', 'extra' => 'min="0"']) ?>
      <?= coop_money('par_value', 'Par value per share', fv('par_value', $rec), ['class' => 'col-6 col-md-2']) ?>
      <?php if ($m): $paid = Coop::memberSummary((int)$m['id'])['share_capital']; ?>
        <div class="col-md-4"><div class="form-label">Subscribed / paid-up capital</div><div class="pt-1"><?= cm($m['subscribed_capital']) ?> subscribed, <?= cm($paid) ?> paid-up
          <span class="small text-muted">(<?= Dec::isPos($m['subscribed_capital']) ? Dec::fix(Dec::div(Dec::mul($paid, '100', 6), $m['subscribed_capital'], 4), 1) : '0' ?> %)</span></div></div>
      <?php endif; ?>
      <?= select_field('cluster_id', 'Cluster', Members::clusters(), fv('cluster_id', $rec), ['class' => 'col-6 col-md-3', 'blank' => '']) ?>
      <?= select_field('segment_id', 'Segmentation', Members::segments(), fv('segment_id', $rec), ['class' => 'col-6 col-md-3', 'blank' => '']) ?>
      <?= select_field('account_officer_id', 'Account officer', $users, fv('account_officer_id', $rec), ['class' => 'col-md-3', 'blank' => '']) ?>
    </div>
    <div class="fw-semibold small mt-3 mb-1">Attendance (general assembly, PMES, seminars)</div>
    <?= coop_grid('gAttendance', 'attendance', ['meeting_type' => ['Meeting', 'select', ['general_assembly' => 'General assembly', 'pmes' => 'PMES', 'seminar' => 'Seminar / training', 'district' => 'District meeting']],
        'meeting_date' => ['Date', 'date'], 'attended' => ['Attended', 'check'], 'remarks' => ['Remarks', 'text']], $grid('attendance', $load('member_attendance', 'meeting_date DESC')), 0, 'Add a meeting') ?>
    </div>

    <div class="jform" data-kind-block="joint,institutional"<?= $isJoint ? '' : ' hidden' ?>>
      <section class="jbox" aria-labelledby="jMemTitle">
        <div class="jbox-title" id="jMemTitle"><i class="bi bi-card-checklist" aria-hidden="true"></i> Membership details</div>
        <div class="row g-2">
          <?= $passbookBox('j_', $jrec, 'col-md-6 col-lg-5') ?>
          <div class="w-100 d-none d-md-block"></div>
          <?= input_field('j_membership_date', 'Membership date', $jv('membership_date'), ['class' => 'col-6 col-md-4', 'type' => 'date']) ?>
          <div class="col-12 col-md-4"><label class="form-label" for="jType">Type</label>
            <select class="form-select form-select-sm" name="j_member_type_id" id="jType" required autocomplete="off" data-kind-types="<?= h(json_encode($kindType)) ?>">
              <?php $jt = (int)$jv('member_type_id'); foreach (Members::types() as $tid => $t): if ((int)$t['is_active'] !== 1 && (int)$tid !== $jt) { continue; } ?>
              <option value="<?= (int)$tid ?>" data-kind="<?= h($t['member_kind']) ?>"<?= (int)$tid === $jt ? ' selected' : '' ?>><?= h($t['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-4"><div class="form-label">Status</div>
            <div class="jstatus"><?= $m ? coop_status($m['status']) . ' <span class="small text-muted">since ' . h(fdate($m['status_date'])) . '</span>' : '<strong>New</strong> <span class="small text-muted">an applicant until the membership is activated</span>' ?></div></div>
          <?= input_field('j_pmes_date', 'PMES date', $jv('pmes_date'), ['class' => 'col-6 col-md-4', 'type' => 'date']) ?>
          <?= select_field('j_cluster_id', 'Cluster', Members::clusters(), $jv('cluster_id'), ['class' => 'col-6 col-md-4', 'blank' => '']) ?>
          <?= select_field('j_segment_id', 'Segmentation', Members::segments(), $jv('segment_id'), ['class' => 'col-md-4', 'blank' => '']) ?>
        </div>
      </section>
      <section class="jbox" aria-labelledby="jCapTitle">
        <div class="jbox-title" id="jCapTitle"><i class="bi bi-diagram-3" aria-hidden="true"></i> Branch, share capital and account officer</div>
        <div class="row g-2">
          <?= select_field('j_branch_id', 'Branch', Coop::branchOptions(), $jv('branch_id'), ['class' => 'col-md-4', 'required' => true]) ?>
          <?= input_field('j_subscribed_shares', 'Subscribed shares', $jv('subscribed_shares', '0'), ['class' => 'col-6 col-md-2', 'type' => 'number', 'step' => '1', 'extra' => 'min="0"']) ?>
          <?= coop_money('j_par_value', 'Par value per share', $jv('par_value'), ['class' => 'col-6 col-md-2']) ?>
          <?= select_field('j_account_officer_id', 'Account officer', $users, $jv('account_officer_id'), ['class' => 'col-md-4', 'blank' => '']) ?>
          <?php if ($m): $paid = Coop::memberSummary((int)$m['id'])['share_capital']; ?>
            <div class="col-12 small text-muted"><?= cm($m['subscribed_capital']) ?> subscribed, <?= cm($paid) ?> paid-up (<?= Dec::isPos($m['subscribed_capital']) ? Dec::fix(Dec::div(Dec::mul($paid, '100', 6), $m['subscribed_capital'], 4), 1) : '0' ?> %)</div>
          <?php endif; ?>
        </div>
      </section>
      <section class="jbox" aria-labelledby="jCounterTitle">
        <div class="jbox-title" id="jCounterTitle"><i class="bi bi-chat-left-text" aria-hidden="true"></i> At the counter</div>
        <div class="row g-2">
          <?= input_field('j_alert_note', 'Alert shown at the counter', $jv('alert_note'), ['class' => 'col-12', 'extra' => 'placeholder="e.g. Two of the representatives sign every withdrawal"']) ?>
          <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="j_notes" rows="3"><?= h((string)$jv('notes')) ?></textarea></div>
        </div>
      </section>
    </div>
  </div>

  <div class="<?= $pane('grouping') ?>" id="tGrouping" data-no-combo<?= $only('grouping') ?>>
    <div class="row g-2">
      <?php foreach (Members::GROUPING_LISTS as $gk => $gLen): if ($gk === 'address_subgroup' && $showWater) { continue; } ?>
        <?php $gHtml = select_field($gk, ucfirst(Members::LIST_SINGULAR[$gk]), Members::listChoices($gk, fv($gk, $rec)), fv($gk, $rec), ['class' => 'col-6 col-md-3', 'blank' => '']); ?>
        <?= $gk === 'address_subgroup' ? $sgBox($gHtml) : $gHtml ?>
      <?php endforeach; ?>
      <?php if ($showWater): ?><div class="col-6 col-md-3 small text-muted align-self-end pb-1">The address sub-group is on the Water Defaults tab.</div><?php endif; ?>
      <?php if (Access::can('coop.lists', 'view')): ?>
        <div class="col-12 small"><a href="<?= h(url('coop.lists', ['tab' => 'account_class'])) ?>" target="_blank" rel="noopener"><i class="bi bi-list-ul"></i> Add, edit or delete account classes, account groups, address sub-groups and savings groups</a></div>
      <?php endif; ?>
      <?= $passbookBox('', $rec, 'col-md-5') ?>
      <?= input_field('alert_note', 'Alert shown at the counter', fv('alert_note', $rec), ['class' => 'col-md-7', 'extra' => 'placeholder="e.g. Verify ID before withdrawals"']) ?>
      <div class="col-12"><label class="form-label">Notes</label><textarea class="form-control form-control-sm" name="notes" rows="3"><?= h((string)fv('notes', $rec)) ?></textarea></div>
    </div>
  </div>

  <?php if ($showWater):
      $wv = MemberDefaults::waterView($rec);
      $wopt = MemberDefaults::waterOptions();
      $wAccounts = $m ? MemberDefaults::waterAccounts($m) : [];
      $wEdit = $rights['water'];
  ?>
  <div class="<?= $pane('water') ?>" id="tWater" data-no-combo>
    <input type="hidden" name="water_defaults_sent" value="1" <?= $wEdit ? '' : 'disabled' ?>>
    <p class="small text-muted mb-2">The member's water service. Water Billing uses these details for the member's water-service account<?= $wAccounts ? '' : ': an account is opened when the member is saved with a rate code and a pipe size' ?>. The lists are the ones of Water Billing.</p>
    <div class="row g-2">
      <?php if (count($wAccounts) > 1): ?>
        <?= select_field('water_account_pick', 'Water-service account these defaults are for', array_combine(array_map(fn($w) => (int)$w['pkey'], $wAccounts), array_map(fn($w) => $w['account_number'] . ' ' . trim((string)$w['rc_code'] . ' ' . (string)$w['rc_name']), $wAccounts)),
            fv('water_account_pick', null, $wv['account']['pkey'] ?? ''), ['class' => 'col-md-6', 'extra' => $wEdit ? '' : 'disabled']) ?>
      <?php endif; ?>
      <div class="col-6 col-md-2"><label class="form-label" for="wZone">Zone</label>
        <input class="form-control form-control-sm" type="number" min="0" step="1" id="wZone" name="water_zone" list="wZones" value="<?= h((string)fv('water_zone', $wv)) ?>" <?= $wEdit ? '' : 'disabled' ?>>
        <datalist id="wZones"><?php foreach ($wopt['zones'] as $z): ?><option value="<?= (int)$z ?>">Zone <?= (int)$z ?></option><?php endforeach; ?></datalist></div>
      <?= $sgBox(select_field('address_subgroup', 'Address sub-group', Members::listChoices('address_subgroup', fv('address_subgroup', $wv)), fv('address_subgroup', $wv), ['class' => 'col-6 col-md-4', 'blank' => ''])) ?>
      <?= input_field('water_sequence', 'Sequence', fv('water_sequence', $wv), ['class' => 'col-6 col-md-2', 'extra' => 'maxlength="50" placeholder="1, 1A, GROUP-2"' . ($wEdit ? '' : ' disabled')]) ?>
      <?= select_field('water_rc_id', 'Rate code / classification', $wopt['rates'], fv('water_rc_id', $wv), ['class' => 'col-md-4', 'blank' => '', 'extra' => $wEdit ? '' : 'disabled']) ?>
      <?= select_field('water_pipe_size_id', 'Pipe size', $wopt['pipes'], fv('water_pipe_size_id', $wv), ['class' => 'col-6 col-md-3', 'blank' => '', 'extra' => $wEdit ? '' : 'disabled']) ?>
      <?= input_field('water_connection_date', 'Connection date', fv('water_connection_date', $wv), ['class' => 'col-6 col-md-3', 'type' => 'date', 'extra' => $wEdit ? '' : 'disabled']) ?>
      <?= input_field('water_disconnection_date', 'Disconnection date', fv('water_disconnection_date', $wv), ['class' => 'col-6 col-md-3', 'type' => 'date', 'extra' => 'id="wDisc"' . ($wEdit ? '' : ' disabled')]) ?>
      <?= select_field('water_connection_status', 'Connection status', MemberDefaults::CONNECTION, fv('water_connection_status', $wv, 'active'), ['class' => 'col-6 col-md-3', 'extra' => 'id="wStatus"' . ($wEdit ? '' : ' disabled')]) ?>
      <div class="col-12 small text-muted">A disconnection date makes the connection Inactive (the account is marked disconnected in Water Billing). Clear the date and choose Active to reconnect.</div>
      <?php if (!$wEdit): ?><div class="col-12 small text-warning">Shown only: changing the water service needs the Edit right on Water Billing &gt; Water Service Accounts.</div><?php endif; ?>
    </div>
    <?php if ($wAccounts): ?>
    <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0"><thead><tr><th>Water-service account</th><th>Rate code</th><th class="d-none d-md-table-cell">Zone</th><th>Connection</th><th class="d-none d-md-table-cell">Account</th></tr></thead><tbody>
      <?php foreach ($wAccounts as $w): ?><tr>
        <td><?= Access::page('water.member_list') ? '<a href="' . h(url('water.member_list', ['search' => $w['account_number']])) . '">' . h((string)$w['account_number']) . '</a>' : h((string)$w['account_number']) ?><?= (int)$w['pkey'] === (int)($wv['account']['pkey'] ?? 0) ? ' <span class="small text-muted">(these defaults)</span>' : '' ?></td>
        <td><?= h(trim((string)$w['rc_code'] . ' ' . (string)$w['rc_name'])) ?></td><td class="d-none d-md-table-cell"><?= $w['zone'] !== null ? 'Zone ' . (int)$w['zone'] : '' ?></td>
        <td><?= ($w['connection_status'] ?? 'active') === 'disconnected' ? '<span class="text-danger">Disconnected</span>' . ($w['disconnected_at'] ? ' <span class="small text-muted">since ' . h(fdate(substr((string)$w['disconnected_at'], 0, 10))) . '</span>' : '') : '<span class="text-success">Connected</span>' ?></td>
        <td class="d-none d-md-table-cell"><?= ($w['status'] ?? 'A') === 'A' ? 'Active' : 'Inactive' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <script>
    (function () {
      var d = document.getElementById('wDisc'), st = document.getElementById('wStatus');
      if (!d || !st) return;
      function sync() { if (d.value) { st.value = 'inactive'; st.dataset.locked = '1'; } else { delete st.dataset.locked; } }
      d.addEventListener('change', sync); d.addEventListener('input', sync);
      st.addEventListener('change', function () { if (st.dataset.locked && st.value === 'active') { st.value = 'inactive'; } });
      sync();
    })();
    </script>
  </div>
  <?php endif; ?>

  <?php if ($showDefaults):
      $custRow = $m ? MemberDefaults::customerOf($m) : null;
      $vendRow = $m ? MemberDefaults::vendorOf($m) : null;
      $cRec = [];
      foreach (array_merge(MemberDefaults::CUSTOMER_IDS, ['credit_limit']) as $k) {
          $cRec['cust_' . $k] = $custRow[$k] ?? ($k === 'credit_limit' ? '0' : '');
      }
      $vRec = [];
      foreach (array_merge(MemberDefaults::VENDOR_IDS, ['credit_limit', 'account_no']) as $k) {
          $vRec['vend_' . $k] = $vendRow[$k] ?? ($k === 'credit_limit' ? '0' : '');
      }
      $cEdit = $rights['customer'];
      $vEdit = $rights['vendor'];
      $dis = fn(bool $on) => $on ? '' : 'disabled';
  ?>
  <div class="<?= $pane('customer') ?>" id="tCustomer">
    <input type="hidden" name="customer_defaults_sent" value="1" <?= $dis($cEdit) ?>>
    <p class="small text-muted mb-2">Used when the member buys from the cooperative (invoices, sales receipts, the POS, statements). The price level sets the member's prices of the items. The member is the customer: no separate customer record is filled in.<?= $custRow ? ' Customer record: ' . (Access::page('cust.center') ? '<a href="' . h(url('cust.center', ['id' => $custRow['id']])) . '">' . h($custRow['name']) . '</a>' : h($custRow['name'])) . '.' : '' ?></p>
    <fieldset <?= $dis($cEdit) ?>><div class="row g-2">
      <?= select_field('cust_terms_id', 'Payment terms', Lookup::terms(), fv('cust_terms_id', $cRec), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?= input_field('cust_credit_limit', 'Credit limit (0 = none)', fv('cust_credit_limit', $cRec, '0'), ['class' => 'col-6 col-md-4', 'type' => 'number', 'extra' => 'min="0"']) ?>
      <?= select_field('cust_tax_code_id', 'Tax code', Lookup::taxCodes(), fv('cust_tax_code_id', $cRec), ['class' => 'col-6 col-md-4', 'blank' => '']) ?>
      <?= select_field('cust_payment_method_id', 'Preferred payment method', Lookup::paymentMethods(), fv('cust_payment_method_id', $cRec), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?= select_field('cust_customer_type_id', 'Customer type', Lookup::customerTypes(), fv('cust_customer_type_id', $cRec), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?= select_field('cust_sales_rep_id', 'Sales representative', Lookup::reps(), fv('cust_sales_rep_id', $cRec), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?= select_field('cust_billing_group_id', 'Billing group (batch invoicing)', DB::pairs('SELECT id, name FROM billing_groups ORDER BY name'), fv('cust_billing_group_id', $cRec), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?php if (Withholding::ready()):
          $whtOpts = [];
          foreach (Withholding::forSide('sales') + array_intersect_key(Withholding::all(), array_flip(Withholding::defaults($custRow['wht_ids'] ?? ''))) as $wid => $wt) {
              $whtOpts[$wid] = Withholding::label($wt) . ($wt['atc'] ? ' (' . $wt['atc'] . ')' : '');
          }
          $whtVals = is_post() ? array_map('intval', (array)($_POST['cust_wht_ids'] ?? [])) : Withholding::defaults($custRow['wht_ids'] ?? '');
      ?>
      <?= multi_check_field('cust_wht_ids', 'Taxes this customer withholds', $whtOpts, $whtVals, ['all' => 'None withheld', 'noun' => 'taxes', 'class' => 'col-md-4', 'id' => 'mCustWht']) ?>
      <?php endif; ?>
      <?= select_field('cust_price_level_id', 'Price level', MemberDefaults::priceLevelOptions(isset($custRow['price_level_id']) ? (int)$custRow['price_level_id'] : null), fv('cust_price_level_id', $cRec), ['class' => 'col-md-4', 'blank' => MemberDefaults::memberPriceLabel()]) ?>
      <?php if ($m && !Access::can('cust.center', 'approve') && !Access::isAdmin()): ?>
        <?= input_field('cust_change_reason', 'Reason for a credit limit change', fv('cust_change_reason'), ['class' => 'col-md-8']) ?>
      <?php endif; ?>
    </div></fieldset>
    <?php if (!$cEdit): ?><div class="small text-warning mt-2">Shown only: changing the customer details needs the Edit right on Customer Center.</div><?php endif; ?>
  </div>

  <div class="<?= $pane('vendor') ?>" id="tVendor">
    <input type="hidden" name="vendor_defaults_sent" value="1" <?= $dis($vEdit) ?>>
    <p class="small text-muted mb-2">Used when the cooperative buys from the member or pays the member (bills, purchase orders, Pay Bills, checks). The member is the supplier: no separate supplier record is filled in.<?= $vendRow ? ' Supplier record: ' . (Access::page('vend.center') ? '<a href="' . h(url('vend.center', ['id' => $vendRow['id']])) . '">' . h($vendRow['name']) . '</a>' : h($vendRow['name'])) . '.' : '' ?></p>
    <fieldset <?= $dis($vEdit) ?>><div class="row g-2">
      <?= select_field('vend_terms_id', 'Payment terms', Lookup::terms(), fv('vend_terms_id', $vRec), ['class' => 'col-md-4', 'blank' => '']) ?>
      <?= input_field('vend_credit_limit', 'Credit limit with vendor', fv('vend_credit_limit', $vRec, '0'), ['class' => 'col-6 col-md-4', 'type' => 'number', 'extra' => 'min="0"']) ?>
      <?= input_field('vend_account_no', 'Our account no. with this vendor', fv('vend_account_no', $vRec), ['class' => 'col-6 col-md-4']) ?>
      <?= select_field('vend_default_expense_account_id', 'Default expense account', Accts::all(), fv('vend_default_expense_account_id', $vRec), ['class' => 'col-md-4', 'blank' => '', 'extra' => 'data-combo']) ?>
      <?= select_field('vend_tax_code_id', 'Tax code', Lookup::taxCodes(), fv('vend_tax_code_id', $vRec), ['class' => 'col-6 col-md-4', 'blank' => '']) ?>
      <?php if (Withholding::ready()):
          $whtOpts = [];
          foreach (Withholding::forSide('purchases') + array_intersect_key(Withholding::all(), array_flip(Withholding::defaults($vendRow['wht_ids'] ?? ''))) as $wid => $wt) {
              $whtOpts[$wid] = Withholding::label($wt) . ($wt['atc'] ? ' (' . $wt['atc'] . ')' : '');
          }
          $whtVals = is_post() ? array_map('intval', (array)($_POST['vend_wht_ids'] ?? [])) : Withholding::defaults($vendRow['wht_ids'] ?? '');
      ?>
      <?= multi_check_field('vend_wht_ids', 'Withholding taxes', $whtOpts, $whtVals, ['all' => 'None withheld', 'noun' => 'taxes', 'class' => 'col-md-4', 'id' => 'mVendWht']) ?>
      <?php endif; ?>
    </div></fieldset>
    <?php if (Withholding::ready()): ?><p class="small text-muted mt-2 mb-0">Withholding taxes: the taxes usually withheld when the member is paid as a supplier; Pay Bills ticks them for the member's bills and they can still be changed on each bill.</p><?php endif; ?>
    <?php if (!$vEdit): ?><div class="small text-warning mt-2">Shown only: changing the supplier details needs the Edit right on Vendor Center.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  </div></div>
  </fieldset>
  <?php if ($canSave): ?>
  <div class="sticky-actions mt-3 d-flex flex-wrap gap-3 align-items-center">
    <?= save_buttons($m ? 'Save member' : 'Add member', $m ? url($pageKey, ['id' => $m['id']]) : url('coop.members'), !$m && Access::can($pageKey, 'create')) ?>
    <?php if (!empty($GLOBALS['FORM_ERROR']) && str_contains((string)$GLOBALS['FORM_ERROR'], 'different person')): ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="allow_duplicate" value="1" id="allowDup"><label class="form-check-label small" for="allowDup">This is a different person</label></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</form>
<script>
// the kind of member decides the form: an individual's boxes, or the joint account / institution's (the format of the
// former program, without Family and beneficiaries, Financial and Loan and savings grouping). The boxes of the other
// kind are hidden and switched off, so only one set is sent.
(function () {
  var form = document.getElementById('memberForm'), kindSel = document.getElementById('memberKind');
  if (!form || !kindSel) return;
  form.querySelectorAll('input, select, textarea, button').forEach(function (c) { if (c.disabled) c.dataset.keepOff = '1'; });
  var jType = document.getElementById('jType'), plaque = document.getElementById('jPlaqueType');
  var kindTypes = {};
  try { kindTypes = JSON.parse(jType ? jType.dataset.kindTypes || '{}' : '{}'); } catch (e) { kindTypes = {}; }
  var isNew = !!document.getElementById('memberNo');
  // the member types, kept to rebuild the Type list for the kind (a hidden option is still listed by Safari on iOS)
  var allTypes = jType ? Array.prototype.map.call(jType.options, function (o) { return {value: o.value, text: o.text, kind: o.dataset.kind}; }) : [];
  function typeList(kind, want) {
    var cur = want || jType.value, mine = allTypes.filter(function (t) { return t.kind === kind; });
    var list = (mine.length ? mine : allTypes).slice();
    if (cur && !list.some(function (t) { return t.value === cur; })) {
      list.unshift(allTypes.filter(function (t) { return t.value === cur; })[0]);
    }
    jType.innerHTML = '';
    list.forEach(function (t) { if (!t) return; var o = new Option(t.text, t.value); o.dataset.kind = t.kind; jType.add(o); });
    jType.value = cur;
  }
  function inHidden(el) { var b = el.closest('[data-kind-block]'); return b && b.hidden; }
  // the empty rows the grids add when the page opens: switched off in the boxes of the other kind
  form.addEventListener('rows:added', function (e) {
    if (inHidden(e.target)) e.target.querySelectorAll('input, select, textarea, button').forEach(function (c) { c.disabled = true; });
  });
  function syncPlaque() {
    if (!jType || !plaque) return;
    var o = jType.options[jType.selectedIndex];
    plaque.textContent = o && o.value ? o.text.toUpperCase() : '';
  }
  function apply(fromUser) {
    var kind = kindSel.value, joint = kind !== 'individual';
    form.classList.toggle('is-joint', joint);
    form.querySelectorAll('[data-kind-block]').forEach(function (b) {
      var on = b.dataset.kindBlock.split(',').indexOf(kind) >= 0;
      b.hidden = !on;
      b.querySelectorAll('input, select, textarea, button').forEach(function (c) { if (!c.dataset.keepOff) c.disabled = !on; });
    });
    form.querySelectorAll('.member-subtabs [data-kinds]').forEach(function (li) {
      li.hidden = li.dataset.kinds.split(',').indexOf(kind) < 0;
      var tb = li.querySelector('.nav-link');
      if (tb) tb.disabled = li.hidden;   // the arrow keys skip a tab the kind does not have
    });
    form.querySelectorAll('[data-label-joint]').forEach(function (el) { el.textContent = joint ? el.dataset.labelJoint : el.dataset.labelIndividual; });
    form.querySelectorAll('[data-text-joint]').forEach(function (el) { el.textContent = kind === 'institutional' ? el.dataset.textInstitutional : el.dataset.textJoint; });
    form.querySelectorAll('[data-ph-joint]').forEach(function (el) { el.placeholder = kind === 'institutional' ? el.dataset.phInstitutional : el.dataset.phJoint; });
    // a tab the kind does not have is left for the first one
    var active = form.querySelector('.member-subtabs .nav-link.active');
    if (active && active.closest('li').hidden) {
      var first = form.querySelector('.member-subtabs .nav-link');
      if (window.bootstrap && bootstrap.Tab) { bootstrap.Tab.getOrCreateInstance(first).show(); } else { first.click(); }
    }
    // the Type list shows the member types of the kind (and the one chosen, whatever its kind); a new joint account or
    // institution takes the first member type of its kind until one is chosen
    if (jType && joint) {
      typeList(kind, fromUser && isNew && !jType.dataset.touched && kindTypes[kind] ? String(kindTypes[kind]) : '');
    }
    syncPlaque();
  }
  kindSel.addEventListener('change', function () { apply(true); });
  if (jType) jType.addEventListener('change', function () { jType.dataset.touched = '1'; syncPlaque(); });
  apply(false);
  // back to the page from the browser's history: the form follows the kind shown
  window.addEventListener('pageshow', function (e) { if (e.persisted) apply(false); });

  // Get passbook number: the next number of Document Numbering > Passbook; it is taken from the series when the member
  // is saved (the next free one, if another member was given it in the meantime)
  form.querySelectorAll('[data-pb-get]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pre = btn.dataset.pbFor || '', box = form.querySelector('[name="' + pre + 'passbook_no"]');
      if (!box) return;
      box.value = btn.dataset.pbGet;
      form.querySelector('[name="' + pre + 'passbook_series"]').value = '1';
      form.querySelector('[name="' + pre + 'passbook_peek"]').value = btn.dataset.pbGet;
      box.focus();
    });
  });
})();
</script>
<?php
elseif ($tab === 'accounts'):
    $accts = DB::all('SELECT a.*, p.name AS product_name, p.product_type FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m ORDER BY a.status = \'closed\', p.sort_order, a.account_no', ['m' => $m['id']]);
    $loans = DB::all('SELECT l.*, p.name AS product_name FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id WHERE l.member_id = :m ORDER BY l.status IN (\'paid\',\'renewed\',\'cancelled\',\'written_off\'), l.release_date DESC', ['m' => $m['id']]);
    $apps = DB::all("SELECT a.*, p.name AS product_name FROM loan_applications a JOIN loan_products p ON p.id = a.product_id WHERE a.member_id = :m AND a.status NOT IN ('released','cancelled') ORDER BY a.id DESC", ['m' => $m['id']]);
    $guar = DB::all("SELECT l.loan_no, l.principal_balance, l.status, mb.full_name FROM loan_comakers c JOIN loan_applications a ON a.id = c.application_id JOIN loan_accounts l ON l.id = a.loan_id JOIN members mb ON mb.id = l.member_id
                       WHERE c.comaker_member_id = :m AND l.status IN ('current','past_due','restructured')", ['m' => $m['id']]);
?>
  <div class="card mb-3"><div class="card-header">Savings, share capital and time deposits</div><table class="table table-sm table-hover"><thead><tr><th>Account</th><th>Product</th><th>Opened</th><th class="num">Balance</th><th class="num">Available</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($accts as $a): ?><tr class="<?= $a['status'] === 'closed' ? 'text-muted' : '' ?>"><td><?= safe_link('sav.accounts', ['id' => $a['id']], $a['account_no']) ?></td><td><?= h($a['product_name']) ?></td><td><?= h(fdate($a['opened_on'])) ?></td>
    <td class="num"><?= cm($a['balance']) ?></td><td class="num"><?= cm(Savings::available($a)) ?></td><td><?= coop_status($a['status']) ?><?= (int)$a['is_restricted'] ? ' <span class="small text-danger">restricted</span>' : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$accts): ?><tr><td colspan="6" class="text-muted">No account yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card mb-3"><div class="card-header">Loans</div><table class="table table-sm table-hover"><thead><tr><th>Loan</th><th>Product</th><th>Released</th><th>Maturity</th><th class="num">Principal</th><th class="num">Balance</th><th class="num">Penalty</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($loans as $l): ?><tr><td><?= safe_link('loan.accounts', ['id' => $l['id']], $l['loan_no']) ?></td><td><?= h($l['product_name']) ?></td><td><?= h(fdate($l['release_date'])) ?></td><td><?= h(fdate($l['due_date'])) ?></td>
    <td class="num"><?= cm($l['principal']) ?></td><td class="num"><?= cm($l['principal_balance']) ?></td><td class="num"><?= cm($l['penalty_balance'], true) ?></td><td><?= coop_status($l['status']) ?></td></tr><?php endforeach; ?>
  <?php if (!$loans): ?><tr><td colspan="8" class="text-muted">No loan yet.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($apps): ?><div class="card mb-3"><div class="card-header">Loan applications in process</div><table class="table table-sm"><tbody>
    <?php foreach ($apps as $ap): ?><tr><td><?= safe_link('loan.application', ['id' => $ap['id']], $ap['application_no']) ?></td><td><?= h($ap['product_name']) ?></td><td class="num"><?= cm($ap['applied_amount']) ?></td><td><?= coop_status($ap['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <div class="card"><div class="card-header">Loans guaranteed as co-maker</div><table class="table table-sm"><tbody>
    <?php foreach ($guar as $g): ?><tr><td><?= h($g['loan_no']) ?></td><td><?= h($g['full_name']) ?></td><td class="num"><?= cm($g['principal_balance']) ?></td><td><?= coop_status($g['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$guar): ?><tr><td class="text-muted">None.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php
elseif ($tab === 'documents'):
    $docs = DB::all('SELECT d.*, u.full_name FROM member_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.member_id = :m ORDER BY d.created_at DESC', ['m' => $m['id']]);
    $docTypes = Members::listNames('doc_type');
?>
  <div class="row g-3">
    <div class="col-lg-8"><div class="card"><table class="table table-sm"><thead><tr><th>Document</th><th>No.</th><th>Issued</th><th>Expires</th><th>File</th><th></th></tr></thead><tbody>
    <?php foreach ($docs as $d): $expired = $d['expires_on'] && $d['expires_on'] < today(); ?>
      <tr><td><?= h($d['doc_type']) ?><div class="small text-muted"><?= h((string)$d['notes']) ?></div></td><td><?= h((string)$d['doc_no']) ?></td><td><?= h(fdate($d['issued_on'])) ?></td>
        <td class="<?= $expired ? 'text-danger fw-semibold' : '' ?>"><?= h(fdate($d['expires_on'])) ?><?= $expired ? ' (expired)' : '' ?></td>
        <td><?= $d['file_name'] ? '<a href="' . h(url('file.member', ['id' => $m['id'], 'f' => 'doc', 'doc' => $d['id']])) . '" target="_blank" rel="noopener">' . h((string)$d['original_name']) . '</a>' : '<span class="text-muted small">no file</span>' ?></td>
        <td><?php if (Access::can($pageKey, 'delete')): ?><button class="btn btn-link btn-sm p-0 text-danger" data-bs-toggle="modal" data-bs-target="#rmDoc<?= (int)$d['id'] ?>">Remove</button><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$docs): ?><tr><td colspan="6" class="text-muted">No document on file.</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <div class="col-lg-4"><?php if (Access::can($pageKey, 'edit')): ?>
      <form method="post" enctype="multipart/form-data" class="card" data-once><div class="card-header">Add a document</div><div class="card-body row g-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="document">
        <?= select_field('doc_type', 'Kind', array_combine($docTypes, $docTypes) ?: [], p('doc_type'), ['class' => 'col-12', 'required' => true, 'blank' => '']) ?>
        <?= input_field('doc_no', 'Document / ID no.', p('doc_no'), ['class' => 'col-12']) ?>
        <?= input_field('issued_on', 'Issued', p('issued_on'), ['class' => 'col-6', 'type' => 'date']) ?>
        <?= input_field('expires_on', 'Expires', p('expires_on'), ['class' => 'col-6', 'type' => 'date']) ?>
        <div class="col-12"><label class="form-label">File (picture or PDF, up to 8 MB)</label><input class="form-control form-control-sm" type="file" name="file" accept="image/*,application/pdf"></div>
        <?= input_field('doc_notes', 'Notes', p('doc_notes'), ['class' => 'col-12']) ?>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Save document</button></div>
      </div></form>
    <?php endif; ?></div>
  </div>
  <?php foreach ($docs as $d) {
      if (Access::can($pageKey, 'delete')) {
          echo str_replace('</form>', '<input type="hidden" name="action" value="remove_document"><input type="hidden" name="doc_id" value="' . (int)$d['id'] . '"></form>',
              reason_modal('rmDoc' . (int)$d['id'], 'Remove ' . $d['doc_type'], url($pageKey, ['id' => $m['id'], 'tab' => 'documents'])));
      }
  } ?>
<?php
else: // history
    $hist = DB::all('SELECT h.*, u.full_name FROM member_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.member_id = :m ORDER BY h.changed_on DESC, h.id DESC', ['m' => $m['id']]);
    $notes = DB::all('SELECT n.*, u.full_name FROM member_notes n LEFT JOIN users u ON u.id = n.created_by WHERE n.member_id = :m ORDER BY n.created_at DESC', ['m' => $m['id']]);
    $audit = DB::all("SELECT * FROM audit_trail WHERE module = 'coop' AND record_type = 'member' AND record_id = :m ORDER BY id DESC LIMIT 40", ['m' => $m['id']]);
    $next = Members::TRANSITIONS[$m['status']] ?? [];
?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">Membership status
        <?php if ($next && Access::can('coop.members', 'approve')): ?><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal">Change status</button><?php endif; ?></div>
        <div class="card-body"><ul class="timeline">
        <?php foreach ($hist as $h): ?><li><div><strong><?= h(Coop::MEMBER_STATUSES[$h['new_status']] ?? $h['new_status']) ?></strong><?= $h['old_status'] ? ' <span class="small text-muted">from ' . h(Coop::MEMBER_STATUSES[$h['old_status']] ?? $h['old_status']) . '</span>' : '' ?></div>
          <div class="small"><?= h((string)$h['reason']) ?><?= $h['reference'] ? ' (' . h($h['reference']) . ')' : '' ?></div><div class="when"><?= h(fdate($h['changed_on'])) ?>, <?= h((string)$h['full_name']) ?></div></li><?php endforeach; ?>
        </ul></div></div>
      <div class="card"><div class="card-header d-flex justify-content-between align-items-center">Hold
        <?php if (Access::can($pageKey, 'edit')): ?><button class="btn btn-sm <?= (int)$m['hold_flag'] ? 'btn-outline-success' : 'btn-outline-danger' ?>" data-bs-toggle="modal" data-bs-target="#holdModal"><?= (int)$m['hold_flag'] ? 'Release the hold' : 'Put on hold' ?></button><?php endif; ?></div>
        <div class="card-body small"><?= (int)$m['hold_flag'] ? 'On hold: ' . h((string)$m['hold_reason']) . '. No deposit, withdrawal, payment or loan can be recorded.' : 'Not on hold.' ?></div></div>
    </div>
    <div class="col-lg-7">
      <?php if (Access::can($pageKey, 'edit')): ?>
      <form method="post" class="card mb-3" data-once><div class="card-body row g-2 align-items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="note">
        <?= select_field('note_type', 'Kind', ['note' => 'Note', 'call' => 'Phone call', 'visit' => 'Visit', 'complaint' => 'Complaint'], 'note', ['class' => 'col-md-3']) ?>
        <div class="col-md-7"><label class="form-label">Note</label><input class="form-control form-control-sm" name="note" maxlength="2000" required></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Add</button></div>
      </div></form>
      <?php endif; ?>
      <div class="card mb-3"><div class="card-header">Notes</div><div class="card-body"><ul class="timeline">
        <?php foreach ($notes as $n): ?><li><div><?= h($n['note']) ?></div><div class="when"><?= h(ucfirst($n['note_type'])) ?>, <?= h(fdatetime($n['created_at'])) ?>, <?= h((string)$n['full_name']) ?></div></li><?php endforeach; ?>
        <?php if (!$notes): ?><li class="text-muted small">No note yet.</li><?php endif; ?>
      </ul></div></div>
      <div class="card"><div class="card-header">Audit trail of the member record</div><table class="table table-sm"><tbody>
        <?php foreach ($audit as $a): ?><tr><td class="small text-nowrap"><?= h(fdatetime($a['created_at'])) ?></td><td class="small"><?= h($a['username']) ?></td><td class="small"><?= h(str_replace('_', ' ', $a['action'])) ?><?= $a['reason'] ? ': ' . h(mb_strimwidth((string)$a['reason'], 0, 90, '...')) : '' ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
  <?php if ($next && Access::can('coop.members', 'approve')): ?>
  <div class="modal fade" id="statusModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-once>
    <?= csrf_field() ?><input type="hidden" name="action" value="status">
    <div class="modal-header"><h5 class="modal-title">Change the membership status</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
      <?= select_field('to', 'New status', array_intersect_key(Coop::MEMBER_STATUSES, array_flip($next)), '', ['class' => 'col-6', 'required' => true]) ?>
      <?= input_field('status_date', 'Effective date', today(), ['class' => 'col-6', 'type' => 'date', 'required' => true]) ?>
      <div class="col-12"><label class="form-label">Reason (kept in the member's history and the audit trail)</label><textarea class="form-control form-control-sm" name="reason" rows="2" required></textarea></div>
      <?= input_field('reference', 'Board resolution / reference', '', ['class' => 'col-12']) ?>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="waive_capital" value="1" id="wc"><label class="form-check-label small" for="wc">Activate even if the paid-up share capital is below the minimum</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="allow_with_loans" value="1" id="awl"><label class="form-check-label small" for="awl">Allow resignation / termination with open loans (to be offset)</label></div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Change status</button></div>
  </form></div></div>
  <?php endif; ?>
  <div class="modal fade" id="holdModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-once>
    <?= csrf_field() ?><input type="hidden" name="action" value="hold"><input type="hidden" name="hold" value="<?= (int)$m['hold_flag'] ? '0' : '1' ?>">
    <div class="modal-header"><h5 class="modal-title"><?= (int)$m['hold_flag'] ? 'Release the hold' : 'Put the member on hold' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><label class="form-label">Reason</label><textarea class="form-control form-control-sm" name="reason" rows="2" <?= (int)$m['hold_flag'] ? '' : 'required' ?>></textarea></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
  </form></div></div>
<?php
endif;
layout_end();
