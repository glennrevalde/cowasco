<?php
declare(strict_types=1);

/**
 * Sample cooperative records for a demonstration database (php tools/install.php --demo, or on its own:
 * php tools/coop_demo.php). Everything is recorded through the same service classes the screens use, so the
 * general ledger, the member ledgers and the audit trail are complete and agree with each other.
 */
require_once __DIR__ . '/coop_fixtures.php';

function load_coop_demo(): void
{
    $admin = (int)DB::value("SELECT id FROM users WHERE username = 'admin'");
    Auth::actAs($admin);
    $_SESSION = $_SESSION ?? [];
    if (DB::value('SELECT id FROM members LIMIT 1')) {
        out('  cooperative sample data already present; skipped');
        return;
    }
    $ids = DB::tx(fn() => coop_fx_setup());
    // the administrator's own drawer for the back-dated sample entries
    $cash = coop_fx_new_account('1009', 'Cash on Hand - Accounting Office', 'Bank', 'Asset', 'Debit');
    DB::update('users', ['coop_cash_account_id' => $cash], 'id = :id', ['id' => $admin]);
    // the sample savings passbook prints the line numbers and the account name and number on every page (a database
    // installed before version 34; tools/install.php gives the passbooks their designs)
    if (Savings::centerReady() && !DB::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'passbook_types' AND column_name = 'layout_json'")) {
        DB::run("UPDATE passbook_types SET columns_json = :c, header_json = :h WHERE name = 'Regular Passbook' AND header_json IS NULL", [
            'c' => json_encode(['line' => 0, 'date' => 500, 'code' => 1600, 'credit' => 2700, 'debit' => 4500, 'balance' => 6300, 'teller' => 8100]),
            'h' => json_encode(['name' => [700, 500], 'number' => [700, 6300]]),
        ]);
    }
    $y = (int)date('Y');
    $d = fn(int $month, int $day, int $year = 0) => sprintf('%04d-%02d-%02d', $year ?: $y, $month, $day);
    $people = [
        ['Santos', 'Maria', 'Teacher', 'F', 'HO'], ['Reyes', 'Jose', 'Farmer', 'M', 'HO'], ['Cruz', 'Ana', 'Nurse', 'F', 'HO'], ['Bautista', 'Pedro', 'Tricycle driver', 'M', 'HO'],
        ['Garcia', 'Liza', 'Sari-sari store owner', 'F', 'HO'], ['Mendoza', 'Ramon', 'Carpenter', 'M', 'BR2'], ['Villanueva', 'Grace', 'Government employee', 'F', 'BR2'],
        ['Torres', 'Edgar', 'Fisherman', 'M', 'BR2'], ['Aquino', 'Rosa', 'Vendor', 'F', 'HO'], ['Ramos', 'Danilo', 'Security guard', 'M', 'HO'], ['Flores', 'Cecilia', 'Dressmaker', 'F', 'BR2'],
        ['Domingo', 'Arnel', 'Mechanic', 'M', 'HO'],
    ];
    $members = [];
    foreach ($people as $i => [$last, $first, $job, $sex, $br]) {
        $members[] = coop_fx_member($ids, $last, $first, ['occupation' => $job, 'gender' => $sex, 'branch' => $br, 'membership_date' => sprintf('%04d-%02d-15', $y - 2, 1 + ($i % 12)),
            'pmes_date' => sprintf('%04d-%02d-08', $y - 2, 1 + ($i % 12)), 'mobile' => '0917' . str_pad((string)(5550100 + $i * 37), 7, '0', STR_PAD_LEFT), 'birth_date' => (1970 + $i * 2) . '-0' . (1 + $i % 9) . '-1' . ($i % 9),
            'tabs' => ['finances' => [['kind' => 'income', 'name' => 'Salary / business', 'member_amount' => (string)(18000 + $i * 1500), 'spouse_amount' => (string)(8000 + $i * 500)],
                ['kind' => 'expense', 'name' => 'Household', 'member_amount' => (string)(12000 + $i * 800), 'spouse_amount' => '0']]]]);
    }
    $open = function (int $m, string $key, string $date) use ($ids) {
        return Savings::open($m, $ids['sav.' . $key], null, $date, null, 'sav.accounts');
    };
    $shift = function (string $date) use ($ids) {
        if (!Teller::openShift()) {
            Teller::open($date, '20000', null, 'teller.shift');
        }
    };
    $or = fn(int $m, string $date, array $items) => Teller::receipt($m, $date, $items, ['mode' => 'cash'], [], 'teller.or');
    $start = $d(1, 5, $y - 1);
    $shift($start);
    $acct = [];
    foreach ($members as $i => $m) {
        $acct[$m] = ['sc' => $open($m, 'SC', $start), 'rs' => $open($m, 'RS', $start)];
        $or($m, $start, [['kind' => 'savings', 'account_id' => $acct[$m]['sc'], 'amount' => (string)(3000 + 500 * $i)], ['kind' => 'savings', 'account_id' => $acct[$m]['rs'], 'amount' => (string)(2000 + 750 * $i)],
            ['kind' => 'charge', 'account_id' => Accts::get('membership_fee_income'), 'amount' => '300', 'description' => 'Membership fee']]);
    }
    // monthly share capital build-up and savings through last year
    for ($mo = 2; $mo <= 12; $mo++) {
        foreach ($members as $i => $m) {
            if (($i + $mo) % 3 === 0) {
                continue;
            }
            $or($m, $d($mo, 10 + ($i % 15), $y - 1), [['kind' => 'savings', 'account_id' => $acct[$m]['sc'], 'amount' => '500'], ['kind' => 'savings', 'account_id' => $acct[$m]['rs'], 'amount' => (string)(300 + 100 * ($i % 5))]]);
        }
    }
    // loans released last year and this year
    $loan = function (int $m, string $product, string $amount, string $term, string $date, string $start) use ($ids) {
        $p = Loans::product($ids['loan.' . $product]);
        $app = Loans::saveApplication(['member_id' => $m, 'product_id' => $p['id'], 'application_date' => $date, 'purpose' => 'Business capital', 'term' => $term, 'term_unit' => 'months',
            'mode_of_payment' => $p['mode_of_payment'], 'applied_amount' => $amount], $product === 'PRD' ? [['collateral_type' => 'Vehicle (OR / CR)', 'description' => 'Motorcycle 2021', 'fmv' => '65000', 'loan_value' => '45000']] : [],
            $product === 'PRD' ? [['comaker_member_id' => null, 'name' => 'Spouse of the borrower']] : [], null, 'loan.application');
        if ($product === 'PRD') {
            foreach (json_decode((string)$p['requirements_json'], true) as $req) {
                DB::insert('loan_application_docs', ['application_id' => $app, 'requirement' => $req, 'is_complete' => 1]);
            }
        }
        $v = Loans::validateApplication($app, 'Sample data', 'loan.applications');
        Loans::submitForApproval($app, 'loan.applications');
        Loans::act($app, 'approved', null, 'Approved by the Credit Committee', 'loan.applications');
        if (Loans::application($app)['status'] !== 'approved') {
            Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = 'manager'"));
            Loans::act($app, 'approved', null, 'Approved by the General Manager', 'loan.applications');
            Auth::actAs((int)DB::value("SELECT id FROM users WHERE username = 'admin'"));
        }
        return Loans::release($app, ['release_date' => $date, 'start_date' => $start, 'release_mode' => 'cash', 'cash_account_id' => (int)DB::value('SELECT coop_cash_account_id FROM users WHERE id = :u', ['u' => Auth::id()])], 'loan.release');
    };
    $loans = [];
    $loans[] = [$members[0], $loan($members[0], 'REG', '50000', '12', $d(3, 1, $y - 1), $d(4, 1, $y - 1))];
    $loans[] = [$members[1], $loan($members[1], 'REG', '30000', '24', $d(6, 15, $y - 1), $d(7, 15, $y - 1))];
    $loans[] = [$members[2], $loan($members[2], 'EMR', '10000', '6', $d(9, 1, $y - 1), $d(9, 16, $y - 1))];
    $loans[] = [$members[4], $loan($members[4], 'PRD', '120000', '24', $d(2, 1), $d(3, 1))];
    $loans[] = [$members[5], $loan($members[5], 'REG', '20000', '12', $d(1, 15), $d(2, 15))];
    $loans[] = [$members[7], $loan($members[7], 'REG', '40000', '12', $d(3, 10), $d(4, 10))];
    $loans[] = [$members[9], $loan($members[9], 'REG', '25000', '12', $d(5, 5), $d(6, 5))];
    // payments: most borrowers pay on time, two fall behind
    foreach ($loans as $k => [$m, $r]) {
        $rows = Loans::scheduleRows($r['loan_id']);
        foreach ($rows as $s) {
            if ($s['due_date'] >= today()) {
                break;
            }
            if (($k === 5 && $s['inst_no'] > 2) || ($k === 6 && $s['inst_no'] > 1)) {
                break;        // past due from here
            }
            $l = Loans::loan($r['loan_id']);
            if (!in_array($l['status'], ['current', 'past_due', 'restructured'], true)) {
                break;
            }
            $or($m, Coop::addDays($s['due_date'], $k % 2), [['kind' => 'loan', 'loan_id' => $r['loan_id'], 'amount' => $s['total']]]);
        }
    }
    // a time deposit, interest on savings for last year, and a check deposit waiting to clear
    TimeDeposits::place($members[3], $ids['sav.TD'], '100000', 180, $d(7, 1), ['kind' => 'cash', 'cash_account_id' => $cash], ['maturity_action' => 'credit_savings', 'credit_account_id' => $acct[$members[3]]['rs']], 'sav.td');
    TimeDeposits::place($members[8], $ids['sav.TD'], '50000', 365, $d(1, 20), ['kind' => 'cash', 'cash_account_id' => $cash], [], 'sav.td');
    Savings::postInterest($ids['sav.RS'], $d(1, 1, $y - 1), $d(12, 31, $y - 1), $d(12, 31, $y - 1), true, 'sav.interest');
    $or($members[6], Coop::addDays(today(), -1), [['kind' => 'savings', 'account_id' => $acct[$members[6]]['rs'], 'amount' => '15000']]);
    Teller::receipt($members[10], Coop::addDays(today(), -1), [['kind' => 'savings', 'account_id' => $acct[$members[10]]['rs'], 'amount' => '8000']],
        ['mode' => 'check', 'cheques' => [['cheque_no' => '0004512', 'bank' => 'Land Bank', 'amount' => '8000']]], [], 'teller.or');
    // the Savings Transaction Center today: cash deposits and a withdrawal, a check deposit still clearing, and a
    // deposit entered twice by mistake, negated in full by its error correction
    if (class_exists('SavTxn') && Savings::centerReady()) {
        $sv = fn(int $m, string $code, string $amount, array $more = []) => SavTxn::post(['code' => $code, 'account_id' => $acct[$m]['rs'], 'amount' => $amount, 'date' => today()] + $more, 'sav.transact');
        $sv($members[0], 'CSD', '2500');
        $sv($members[1], 'CSD', '1200');
        $sv($members[2], 'CSW', '1500');
        $sv($members[5], 'CK3', '5000', ['cheques' => [['cheque_no' => '1180245', 'bank' => 'BDO', 'date' => today(), 'amount' => '5000']]]);
        $sv($members[4], 'CSD', '700');
        $twice = $sv($members[4], 'CSD', '700');
        $sv($members[4], 'ECSD', '700', ['corrects_id' => $twice['sav_txn_id'], 'memo' => 'Deposit entered twice']);
    }
    // Loan Collection today: a check payment on a past-due loan, and a cash payment entered on the wrong loan, negated
    // in full by its error correction (EC on the loan ledger and in the loan passbook)
    if (class_exists('LoanTxn') && LoanTxn::ready()) {
        [$m5, $r5] = $loans[5];
        Teller::receipt($m5, today(), [['kind' => 'loan', 'loan_id' => $r5['loan_id'], 'amount' => '3000']],
            ['mode' => 'check', 'cheques' => [['cheque_no' => '2207731', 'bank' => 'Metrobank', 'date' => today(), 'amount' => '3000']]], [], 'loan.collection');
        [$m6, $r6] = $loans[6];
        $wrong = Teller::receipt($m6, today(), [['kind' => 'loan', 'loan_id' => $r6['loan_id'], 'amount' => '2000']], ['mode' => 'cash'], [], 'loan.collection');
        LoanTxn::errorCorrect((int)DB::value("SELECT id FROM loan_txns WHERE txn_id = :t AND kind = 'payment'", ['t' => $wrong['txn_id']]), today(), 'Payslip of another member', 'loan.collection');
        Teller::receipt($m6, today(), [['kind' => 'loan', 'loan_id' => $r6['loan_id'], 'amount' => '1500']], ['mode' => 'cash'], [], 'loan.collection');
    }
    // a pending application for the Credit Committee
    Loans::saveApplication(['member_id' => $members[11], 'product_id' => $ids['loan.REG'], 'application_date' => today(), 'purpose' => 'House repair / construction', 'term' => '18', 'term_unit' => 'months',
        'mode_of_payment' => 'monthly', 'applied_amount' => '60000'], [], [], null, 'loan.application');
    $s = Teller::openShift();
    if ($s) {
        $exp = Teller::expectedCash($s);
        Teller::close((int)$s['id'], ['_total' => $exp], 'Sample data', 'teller.shift');
    }
    coop_demo_account_types();
    coop_demo_isc_review();
    coop_demo_joint_members();
    out('  ' . count($members) . ' members, ' . count($loans) . ' loans, ' . DB::value("SELECT COUNT(*) FROM transactions WHERE txn_type IN ('coop_or','coop_cv','loan_release','coop_jv')") . ' cooperative vouchers');
}

/**
 * Two account types of the cooperative's own (Chart of Accounts > Account Types), each a classification of the
 * financial statements: Cash (behaves as Bank, cash and cash equivalents) for 1000 Cash, its sub-accounts and the
 * accounting office's cash, and Loans Receivable (behaves as Other Current Asset, operating activities) for the
 * loans receivable and their allowance. Safe to run again.
 */
function coop_demo_account_types(): void
{
    if (!AccountTypes::ready()) {
        return;
    }
    $make = function (string $name, string $base, string $flow, string $desc): int {
        $t = AccountTypes::byName($name);
        return $t ? (int)$t['id'] : AccountTypes::save(['name' => $name, 'base_type' => $base, 'cash_flow' => $flow, 'sort_order' => 5, 'description' => $desc, 'is_active' => 1], null, 'coa.account_types');
    };
    $cash = $make('Cash', 'Bank', 'cash', 'Cash on hand, cash in banks and the cash funds');
    $loans = $make('Loans Receivable', 'Other Current Asset', 'operating', 'Loans to members and their allowance for probable losses');
    $main = (int)DB::value("SELECT id FROM accounts WHERE code = '1000'");
    $ids = array_map('intval', DB::column("SELECT id FROM accounts WHERE code IN ('1000', '1009') OR parent_id = :m", ['m' => $main]));
    AccountTypes::assign($cash, $ids, 'coa.account_types');
    AccountTypes::assign($loans, array_map('intval', DB::column("SELECT id FROM accounts WHERE code IN ('1210', '1211', '1212', '1213', '1215') AND account_type = 'Other Current Asset'")), 'coa.account_types');
}

/**
 * An Interest on Share Capital batch computed for review, not posted (Year-end Distribution > Interest on Share
 * Capital): the average daily balances of the share capital from January 1 to today, a pool of 10,000.00 shared
 * among them and credited to Regular Savings; the members with a past-due loan are removed from the list with the
 * reason. Safe to run again.
 */
function coop_demo_isc_review(): void
{
    $desc = 'Interest on Share Capital ' . date('Y') . ' (sample, in review)';
    if (DB::value('SELECT id FROM dist_batches WHERE description = :d', ['d' => $desc])) {
        return;
    }
    $savings = (int)DB::value("SELECT id FROM sav_products WHERE product_type = 'ordinary' ORDER BY id LIMIT 1");
    if (!$savings || !DB::value("SELECT id FROM sav_products WHERE product_type = 'share_capital' AND isc_eligible = 1 LIMIT 1")) {
        return;
    }
    $id = Distribution::compute('isc', ['period_id' => null, 'description' => $desc, 'date_from' => date('Y-01-01'), 'date_to' => today(), 'method' => 'pool', 'rate' => '0',
        'pool' => '10000', 'basis' => 'adb', 'credit_mode' => 'savings', 'credit_product_id' => $savings, 'memo' => 'Sample: review the list before it is posted'], [], null, 'dist.isc');
    $lines = DB::all('SELECT id, member_id FROM dist_batch_lines WHERE batch_id = :b AND is_excluded = 0', ['b' => $id]);
    $obl = Distribution::obligations(array_column($lines, 'member_id'), today());
    $late = array_values(array_filter($lines, fn($l) => Dec::isPos($obl[(int)$l['member_id']]['past_due'] ?? '0')));
    if ($late) {
        Distribution::removeLines($id, array_map(fn($l) => (int)$l['id'], $late), Distribution::removeReason('delinquent', ''), 'dist.isc');
    }
}

/**
 * A joint account and an institution on the form of the former program (Joint/Institution Details, Membership
 * Information): their authorized representatives, addresses and contact list, a passbook number from the series,
 * the member type of their kind; activated by the Board. Safe to run again.
 */
function coop_demo_joint_members(): void
{
    $branch = (int)DB::value('SELECT id FROM coop_branches ORDER BY is_head_office DESC, id LIMIT 1');
    $types = DB::pairs("SELECT member_kind, MIN(id) FROM member_types WHERE member_kind IN ('joint', 'institutional') AND is_active = 1 GROUP BY member_kind");
    if (!$branch || count($types) < 2) {
        return;
    }
    $y = (int)date('Y');
    $samples = [
        ['joint', 'Lim, Teresa or Victor or Paolo', [['Teresa Lim', 'Wife'], ['Victor Lim', 'Husband'], ['Paolo Lim', 'Son']],
            ['res_house' => '18', 'res_street' => 'Burgos Street', 'res_barangay' => 'San Roque', 'res_city' => 'San Isidro', 'res_province' => 'Nueva Ecija', 'res_postal' => '3106',
             'email' => 'lim.family@example.com'], '09175551201'],
        ['institutional', 'San Isidro Community Hospital', [['Ramon Villareal', 'President'], ['Teresa Mallari', 'Vice President'], ['Danilo Ocampo', 'Treasurer'], ['Liza Navarro', 'Secretary']],
            ['res_house' => 'Bldg. 2', 'res_street' => 'Maharlika Highway', 'res_barangay' => 'Poblacion', 'res_city' => 'San Isidro', 'res_province' => 'Nueva Ecija', 'res_postal' => '3106',
             'off_house' => 'Bldg. 2', 'off_street' => 'Maharlika Highway', 'off_city' => 'San Isidro', 'off_province' => 'Nueva Ecija', 'off_postal' => '3106',
             'email' => 'admin@sicommunityhospital.example', 'tin' => '004-551-203-000'], '940-1122'],
    ];
    foreach ($samples as $i => [$kind, $name, $reps, $more, $phone]) {
        if (DB::value('SELECT id FROM members WHERE institution_name = :n', ['n' => $name])) {
            continue;
        }
        $joined = sprintf('%04d-%02d-10', $y - 1, 3 + $i * 4);
        $id = DB::tx(fn() => Members::save(['member_kind' => $kind, 'institution_name' => $name, 'last_name' => '', 'first_name' => '', 'birth_date' => null,
            'branch_id' => $branch, 'member_type_id' => (int)$types[$kind], 'membership_date' => $joined, 'pmes_date' => date('Y-m-d', strtotime($joined . ' -7 days')),
            'subscribed_shares' => $kind === 'joint' ? 50 : 200, 'par_value' => '100', 'passbook_no' => Members::nextPassbook(), 'allow_duplicate' => 1] + $more,
            ['representatives' => array_map(fn($r) => ['name' => $r[0], 'position' => $r[1]], $reps),
             'contacts' => [['contact_type' => $kind === 'joint' ? 'mobile' : 'landline', 'country_code' => '63', 'area_code' => $kind === 'joint' ? '' : '44', 'number' => $phone, 'is_primary' => 1]]],
            null, 'coop.member'));
        $_POST['waive_capital'] = '1';
        Members::changeStatus($id, 'active', $joined, 'Approved by the Board (membership committee)', 'BR-' . substr($joined, 0, 4) . '-0' . (3 + $i), 'coop.members');
        unset($_POST['waive_capital']);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__ . '/../app/bootstrap.php';
    require __DIR__ . '/lib.php';
    $_SESSION = [];
    load_coop_demo();
}
