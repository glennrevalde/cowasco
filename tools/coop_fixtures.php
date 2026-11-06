<?php
declare(strict_types=1);

/**
 * A standard cooperative set-up, used by the sample data (tools/coop_demo.php) and by the automated tests
 * (tests/coop/run.php): two branches, the savings, share capital and time deposit products of the reference
 * system, four loan charges, three loan products and a two-level approval matrix.
 *
 * Every call goes through the same tables the set-up screens write; nothing here bypasses a rule the screens apply.
 */

function coop_fx_acct(string $code): int
{
    $id = DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
    if (!$id) {
        throw new RuntimeException("Account $code is missing.");
    }
    return (int)$id;
}

function coop_fx_role(string $name): int
{
    return (int)DB::value('SELECT id FROM roles WHERE name = :n', ['n' => $name]);
}

/** A user with one role, a branch and a teller cash account */
function coop_fx_user(string $username, string $fullName, string $role, ?int $branchId, ?int $cashAccountId, string $password = 'Demo@12345'): int
{
    $id = DB::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
    if ($id) {
        return (int)$id;
    }
    $id = DB::insert('users', ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'full_name' => $fullName,
        'email' => $username . '@samplecoop.test', 'status' => 'active', 'must_change_password' => 0, 'password_changed_at' => date('Y-m-d H:i:s'),
        'coop_branch_id' => $branchId, 'coop_cash_account_id' => $cashAccountId]);
    DB::insert('user_roles', ['user_id' => $id, 'role_id' => coop_fx_role($role)]);
    return $id;
}

function coop_fx_class(string $name, string $description): int
{
    $id = DB::value('SELECT id FROM classes WHERE name = :n', ['n' => $name]);
    if ($id) {
        return (int)$id;
    }
    $id = DB::insert('classes', ['name' => $name, 'description' => $description, 'sort_order' => 10 + (int)DB::value('SELECT COUNT(*) FROM classes'), 'created_by' => Auth::id()]);
    Classes::reset();
    return $id;
}

/** Inserts an account when the code is free; returns its id */
function coop_fx_new_account(string $code, string $name, string $type, string $category, string $normal, ?int $parentId = null): int
{
    $id = DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
    if ($id) {
        return (int)$id;
    }
    return DB::insert('accounts', ['code' => $code, 'name' => $name, 'account_type' => $type, 'category' => $category, 'normal_balance' => $normal, 'parent_id' => $parentId]);
}

/**
 * Creates the standard set-up once and returns the ids:
 *   branch.HO, branch.BR2, class.*, sav.RS (regular savings), sav.SS (special), sav.SC (share capital), sav.TD,
 *   charge.SF / CBU / INS / FF, loan.REG / EMR / PRD, cash.HO, cash.BR2, user.*
 */
function coop_fx_setup(): array
{
    $ids = [];
    $effective = '2020-01-01';

    // ---------------------------------------------------------------- profile and policies
    foreach (['coop_registered_name' => 'Bayanihan Multi-Purpose Cooperative', 'coop_cda_reg_no' => '9520-1000000000012345', 'coop_type' => 'Multi-Purpose',
        'coop_category' => 'Primary', 'coop_signatory_gm' => 'Rosario M. Dizon', 'coop_signatory_chair' => 'Eduardo P. Santos', 'coop_signatory_treasurer' => 'Liza T. Ramos',
        'coop_signatory_loans' => 'Marvin C. Uy', 'coop_signatory_bookkeeper' => 'Joy A. Fernandez', 'coop_min_share_capital' => '1000', 'coop_default_par_value' => '100',
        'coop_membership_fee' => '300'] as $k => $v) {
        DB::run('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2', ['k' => $k, 'v' => $v, 'v2' => $v]);
    }
    Settings::flush();

    // ---------------------------------------------------------------- branches (each with its class, so statements by class are branch statements)
    $ids['class.HO'] = coop_fx_class('Head Office', 'Cooperative head office');
    $ids['class.BR2'] = coop_fx_class('Poblacion Branch', 'Cooperative branch office');
    foreach ([['01', 'Head Office', 'HO', $ids['class.HO'], 1, ''], ['02', 'Poblacion Branch', 'BR2', $ids['class.BR2'], 0, 'P']] as [$code, $name, $key, $class, $ho, $orp]) {
        $bid = DB::value('SELECT id FROM coop_branches WHERE code = :c', ['c' => $code]);
        if (!$bid) {
            $bid = DB::insert('coop_branches', ['code' => $code, 'name' => $name, 'address' => $ho ? 'Rizal Street, San Isidro' : 'Poblacion, San Isidro', 'class_id' => $class,
                'is_head_office' => $ho, 'or_prefix' => $orp, 'is_active' => 1]);
        }
        $ids['branch.' . $key] = (int)$bid;
    }
    Coop::resetBranches();
    $parentCash = DB::value("SELECT id FROM accounts WHERE code = '1000'");
    $ids['cash.HO'] = coop_fx_new_account('1007', 'Cash on Hand - Cooperative Teller (Head Office)', 'Bank', 'Asset', 'Debit', $parentCash ? (int)$parentCash : null);
    $ids['cash.BR2'] = coop_fx_new_account('1008', 'Cash on Hand - Cooperative Teller (Poblacion)', 'Bank', 'Asset', 'Debit', $parentCash ? (int)$parentCash : null);
    $ids['bank'] = (int)(DB::value("SELECT id FROM accounts WHERE code = '1010'") ?: coop_fx_new_account('1011', 'Cash in Bank - Cooperative', 'Bank', 'Asset', 'Debit'));
    DB::run("UPDATE default_accounts SET account_id = :a WHERE setting_key = 'coop_cash'", ['a' => $ids['cash.HO']]);
    Accts::reset();

    // ---------------------------------------------------------------- savings, share capital and time deposit products (the reference codes)
    $sc = coop_fx_acct('3010');
    $pbSav = (int)DB::value("SELECT id FROM passbook_types WHERE name = 'Regular Passbook'") ?: null;
    $pbLoan = (int)DB::value("SELECT id FROM passbook_types WHERE name = 'Loan Passbook'") ?: null;
    $products = [
        'RS' => ['01', 'Regular Savings', 'ordinary', ['liability_account_id' => coop_fx_acct('2300'), 'opening_amount' => '100', 'maintaining_balance' => '500', 'min_balance' => '100',
            'min_deposit' => '20', 'dormancy_months' => 24, 'dormancy_fee' => '25', 'dormancy_posting' => 'quarterly', 'interest_basis' => 'adb', 'sort_order' => 1, 'passbook_type_id' => $pbSav]],
        'SS' => ['02', 'Special Savings', 'special', ['liability_account_id' => coop_fx_acct('2305'), 'opening_amount' => '5000', 'maintaining_balance' => '5000', 'min_balance' => '0',
            'lockin_months' => 6, 'interest_basis' => 'lowest', 'sort_order' => 2, 'passbook_type_id' => $pbSav]],
        'SC' => ['03', 'Paid Up Share Capital - Common', 'share_capital', ['liability_account_id' => $sc, 'par_value' => '100', 'share_class' => 'common', 'isc_eligible' => 1,
            'allow_withdrawal' => 1, 'withdrawal_approval' => 1, 'min_deposit' => '100', 'sort_order' => 3, 'passbook_type_id' => $pbSav]],
        'TD' => ['04', 'Time Deposit', 'time_deposit', ['liability_account_id' => coop_fx_acct('2310'), 'accrued_account_id' => coop_fx_acct('2320'), 'opening_amount' => '10000',
            'maturity_value' => 180, 'maturity_unit' => 'days', 'preterm_rate' => '1.0000', 'preterm_fee' => '100', 'rate_after_maturity' => 'same', 'sort_order' => 4]],
    ];
    foreach ($products as $key => [$code, $name, $type, $extra]) {
        $pid = DB::value('SELECT id FROM sav_products WHERE code = :c', ['c' => $code]);
        if (!$pid) {
            $pid = DB::insert('sav_products', ['code' => $code, 'name' => $name, 'product_type' => $type, 'is_active' => 1, 'calendar_days' => 365] + $extra);
        }
        $ids['sav.' . $key] = (int)$pid;
    }
    Savings::resetProducts();
    if (!DB::value('SELECT id FROM sav_product_rates WHERE product_id = :p', ['p' => $ids['sav.RS']])) {
        DB::insert('sav_product_rates', ['product_id' => $ids['sav.RS'], 'amount_from' => '0', 'amount_to' => '9999.99', 'effective_date' => $effective, 'rate' => '1.0000', 'posting_method' => 'quarterly']);
        DB::insert('sav_product_rates', ['product_id' => $ids['sav.RS'], 'amount_from' => '10000', 'amount_to' => '999999999999.99', 'effective_date' => $effective, 'rate' => '1.5000', 'posting_method' => 'quarterly']);
        DB::insert('sav_product_rates', ['product_id' => $ids['sav.SS'], 'amount_from' => '0', 'amount_to' => '999999999999.99', 'effective_date' => $effective, 'rate' => '2.5000', 'posting_method' => 'quarterly']);
        foreach ([[30, 89, '3.0000'], [90, 179, '3.5000'], [180, 364, '4.0000'], [365, 0, '4.5000']] as [$f, $t, $r]) {
            DB::insert('sav_product_rates', ['product_id' => $ids['sav.TD'], 'amount_from' => '0', 'amount_to' => '999999999999.99', 'term_from' => $f, 'term_to' => $t, 'effective_date' => $effective, 'rate' => $r, 'posting_method' => 'maturity']);
        }
    }

    // ---------------------------------------------------------------- loan charges (financial, savings, non-financial)
    $charges = [
        'SF'  => ['SF', 'Service Fee', 'financial', coop_fx_acct('4410'), null, 'fixed_rate', '3'],
        'CBU' => ['CBU', 'Share Capital Build-up', 'savings', null, $ids['sav.SC'], 'fixed_rate', '5'],
        'INS' => ['INS', 'Loan Protection Insurance', 'non_financial', coop_fx_acct('2360'), null, 'rate_by_term', '0'],
        'FF'  => ['FF', 'Filing Fee', 'financial', coop_fx_acct('4420'), null, 'fixed_amount', '100'],
    ];
    foreach ($charges as $key => [$code, $name, $type, $acct, $savProduct, $by, $value]) {
        $cid = DB::value('SELECT id FROM loan_charges WHERE code = :c', ['c' => $code]);
        if (!$cid) {
            $cid = DB::insert('loan_charges', ['code' => $code, 'name' => $name, 'charge_type' => $type, 'account_id' => $acct, 'sav_product_id' => $savProduct,
                'charge_by' => $by, 'value' => $value, 'effective_date' => $effective, 'is_active' => 1]);
            if ($key === 'INS') {
                DB::insert('loan_charge_ranges', ['charge_id' => $cid, 'range_from' => '1', 'range_to' => '12', 'value' => '1.0000']);
                DB::insert('loan_charge_ranges', ['charge_id' => $cid, 'range_from' => '12.01', 'range_to' => '36', 'value' => '1.5000']);
            }
        }
        $ids['charge.' . $key] = (int)$cid;
    }

    // ---------------------------------------------------------------- loan products
    $recv = coop_fx_acct('1210');
    $loanProducts = [
        'REG' => ['6', 'Regular Loan', 'Provident', ['mode_of_payment' => 'monthly', 'max_loans' => 1, 'term_unit' => 'months', 'term_min' => '1', 'term_max' => '36',
            'amount_min' => '1000', 'amount_max' => '500000', 'charge_method' => 'deducted', 'interest_method' => 'diminishing', 'amort_type' => 'equal_amortization',
            'rate_default' => '18.0000', 'rate_opt1' => '16.0000', 'penalty_grace_days' => 3, 'penalty_rate' => '36.0000', 'penalty_base' => 'arrears',
            'req_capital_mode' => 'min', 'req_capital_value' => '1000', 'sort_order' => 1], ['SF', 'CBU']],
        'EMR' => ['2', 'Emergency Loan', 'Emergency', ['mode_of_payment' => 'semi_monthly', 'max_loans' => 1, 'term_unit' => 'months', 'term_min' => '1', 'term_max' => '6',
            'amount_min' => '1000', 'amount_max' => '20000', 'charge_method' => 'deducted', 'interest_method' => 'straight', 'amort_type' => 'equal_amortization',
            'rate_default' => '12.0000', 'penalty_grace_days' => 0, 'penalty_rate' => '24.0000', 'penalty_base' => 'principal_arrears', 'sort_order' => 2], ['FF']],
        'PRD' => ['3', 'Productive Loan', 'Productive', ['mode_of_payment' => 'monthly', 'max_loans' => 1, 'term_unit' => 'months', 'term_min' => '6', 'term_max' => '36',
            'amount_min' => '20000', 'amount_max' => '1000000', 'charge_method' => 'deducted', 'interest_method' => 'diminishing', 'amort_type' => 'equal_principal',
            'rate_default' => '15.0000', 'penalty_grace_days' => 5, 'penalty_rate' => '24.0000', 'penalty_base' => 'arrears', 'req_comakers' => 1, 'needs_collateral' => 1,
            'requirements_json' => json_encode(['Signed promissory note', 'Valid government ID', 'Business permit or proof of income']), 'sort_order' => 3], ['SF', 'INS']],
    ];
    foreach ($loanProducts as $key => [$code, $name, $type, $extra, $chargeKeys]) {
        $pid = DB::value('SELECT id FROM loan_products WHERE code = :c', ['c' => $code]);
        if (!$pid) {
            $pid = DB::insert('loan_products', ['code' => $code, 'name' => $name, 'loan_type' => $type, 'receivable_account_id' => $recv,
                'interest_account_id' => coop_fx_acct('4400'), 'penalty_account_id' => coop_fx_acct('4430'), 'calendar_days' => 365, 'is_active' => 1, 'passbook_type_id' => $pbLoan] + $extra);
            $n = 0;
            foreach ($chargeKeys as $ck) {
                DB::insert('loan_product_charges', ['product_id' => $pid, 'charge_id' => $ids['charge.' . $ck], 'sort_order' => ++$n]);
            }
        }
        $ids['loan.' . $key] = (int)$pid;
    }
    Loans::resetProducts();
    // a lower rate for long Regular Loans effective this year (rates are effective-dated, released loans keep theirs)
    if (!DB::value('SELECT id FROM loan_product_rates WHERE product_id = :p', ['p' => $ids['loan.REG']])) {
        DB::insert('loan_product_rates', ['product_id' => $ids['loan.REG'], 'term_from' => '25', 'term_to' => '36', 'amount_from' => '0', 'amount_to' => '0', 'effective_date' => $effective, 'rate' => '16.5000']);
    }

    // ---------------------------------------------------------------- approval matrix: the Credit Committee for every loan, the General Manager above 100,000
    if (!DB::value('SELECT id FROM loan_approval_matrix LIMIT 1')) {
        DB::insert('loan_approval_matrix', ['level_no' => 1, 'amount_from' => '0', 'amount_to' => '0', 'product_id' => null, 'role_id' => null, 'position' => 'Credit Committee', 'is_active' => 1]);
        DB::insert('loan_approval_matrix', ['level_no' => 2, 'amount_from' => '100000.01', 'amount_to' => '0', 'product_id' => null, 'role_id' => null, 'position' => 'General Manager', 'is_active' => 1]);
    }

    // ---------------------------------------------------------------- users
    $ids['user.teller1'] = coop_fx_user('teller1', 'Teresa Villanueva', 'Teller', $ids['branch.HO'], $ids['cash.HO']);
    $ids['user.teller2'] = coop_fx_user('teller2', 'Paolo Mercado', 'Teller', $ids['branch.BR2'], $ids['cash.BR2']);
    $ids['user.loans'] = coop_fx_user('loanofficer', 'Marvin Uy', 'Loan Officer', $ids['branch.HO'], null);
    $ids['user.ccom'] = coop_fx_user('creditcom', 'Eduardo Santos', 'Credit Committee', null, null);
    $ids['user.gm'] = coop_fx_user('manager', 'Rosario Dizon', 'General Manager', null, $ids['cash.HO']);
    $ids['user.book'] = coop_fx_user('bookkeeper', 'Joy Fernandez', 'Bookkeeper', null, null);
    $ids['user.member_off'] = coop_fx_user('membership', 'Nina Bautista', 'Membership Officer', $ids['branch.HO'], null);
    $ids['user.brmgr'] = coop_fx_user('branchmgr', 'Carlo Reyes', 'Branch Manager', $ids['branch.BR2'], $ids['cash.BR2']);
    Access::reset();
    return $ids;
}

/** A member, saved through Members::save() and made active (membership date as given) */
function coop_fx_member(array $ids, string $last, string $first, array $extra = []): int
{
    $branch = $extra['branch'] ?? 'HO';
    unset($extra['branch']);
    $d = ['member_kind' => 'individual', 'last_name' => $last, 'first_name' => $first, 'middle_name' => $extra['middle_name'] ?? '', 'suffix' => '',
        'branch_id' => $ids['branch.' . $branch], 'member_type_id' => (int)DB::value("SELECT id FROM member_types WHERE code = 'REG'"),
        'birth_date' => $extra['birth_date'] ?? '1985-03-14', 'gender' => $extra['gender'] ?? 'F', 'civil_status' => 'married', 'nationality' => 'Filipino',
        'mobile' => $extra['mobile'] ?? null, 'res_street' => $extra['street'] ?? 'Mabini Street', 'res_barangay' => $extra['barangay'] ?? 'San Roque', 'res_city' => 'San Isidro', 'res_province' => 'Nueva Ecija',
        'occupation' => $extra['occupation'] ?? 'Teacher', 'employment_type' => 'employed', 'membership_date' => $extra['membership_date'] ?? '2022-01-15', 'pmes_date' => $extra['pmes_date'] ?? '2022-01-08',
        'subscribed_shares' => $extra['subscribed_shares'] ?? 100, 'par_value' => '100', 'allow_duplicate' => 1];
    unset($extra['middle_name'], $extra['birth_date'], $extra['gender'], $extra['mobile'], $extra['street'], $extra['barangay'], $extra['occupation'], $extra['membership_date'], $extra['pmes_date'], $extra['subscribed_shares']);
    $tabs = $extra['tabs'] ?? [];
    unset($extra['tabs']);
    $id = Members::save($d + $extra, $tabs, null, 'coop.member');
    $_POST['waive_capital'] = '1';      // the opening share capital is collected after activation in the fixtures
    Members::changeStatus($id, 'active', $d['membership_date'], 'Approved by the Board (membership committee)', 'BR-' . substr($d['membership_date'], 0, 4) . '-01', 'coop.members');
    unset($_POST['waive_capital']);
    return $id;
}
