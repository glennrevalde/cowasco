<?php
declare(strict_types=1);

/**
 * Sample water-utility activity for the demonstration database (database/accounting_system.sql).
 *
 *   php tools/water_demo.php                                  serves this folder itself with PHP's built-in server
 *   php tools/water_demo.php --base=http://localhost/coop     or uses a web server already serving this folder
 *
 * The database must hold the cooperative sample data (`php tools/install.php --demo`) and the water starter set-up
 * (tools/water_starter.php), with no water-service account yet; `php tools/install.php --water-demo` does all three.
 *
 * Every water document is made the way an operator makes it: signed in as a demo user, through the water pages and
 * their endpoints (CSRF tokens, page rights, idempotency keys), so the billing engine, the tariff, the discounts and
 * the posting bridge to the ledger all run as they do in production. What it records, over the billing months that
 * follow the cooperative's closing date (dates are relative to the day it runs):
 *   - the water cashier user (wcashier), the report signatories, the supplier of meters and materials;
 *   - about 33 water-service accounts: cooperative members (one with a second, commercial connection), non-members,
 *     the supplier itself, senior citizens with the 5% discount, an employee with 10 free cubic metres;
 *   - stock bought on Enter Bills and paid on Pay Bills; materials issued to the field (one issuance returned);
 *   - new connections billed with connection fees, service deposits, a meter sold outright and meters on installment;
 *   - billing periods, meter readings and posted bills; a recurring maintenance fee for commercial connections;
 *   - collections at the cashier: on time, partial, late with a penalty, two months at once, never; a receipt
 *     reversed and collected again; a bill corrected (meter misread) before collection; earlier periods closed.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../tests/water/e2e_lib.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $_SESSION = [];
    Tenant::bootCli();
    $base = null;
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--base=')) {
            $base = substr($a, 7);
        }
    }
    try {
        foreach (load_water_demo($base) as $line) {
            echo $line, "\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'STOPPED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

/** @return string[] summary */
function load_water_demo(?string $base = null): array
{
    if ((int)DB::value('SELECT COUNT(*) FROM water_accounts') > 0) {
        throw new RuntimeException('The database already has water-service accounts: the sample water activity is for a new demonstration database.');
    }
    if (!(int)DB::value("SELECT COUNT(*) FROM bill_codes WHERE code = 'WATER BILL'") || !(int)DB::value('SELECT COUNT(*) FROM rate_code')) {
        throw new RuntimeException('Run tools/water_starter.php first (bill codes, rates and water accounts).');
    }
    foreach (['manager', 'apclerk'] as $u) {
        if (!DB::value("SELECT id FROM users WHERE username = :u AND status = 'active'", ['u' => $u])) {
            throw new RuntimeException("The demo user $u is missing: run `php tools/install.php --demo` first.");
        }
    }
    $server = null;
    if ($base === null) {
        [$base, $server] = wd_serve();
    }
    $backdate = DB::value("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'collection_max_backdate_days'");
    try {
        // the collections of the earlier months are entered today with their own dates: the cashier's backdating
        // window is widened while they are entered and set back afterwards
        DB::run("UPDATE water_billing_settings SET setting_value = '200' WHERE setting_key = 'collection_max_backdate_days'");
        return wd_run(rtrim($base, '/'));
    } finally {
        DB::run('UPDATE water_billing_settings SET setting_value = :v WHERE setting_key = :k', ['v' => (string)($backdate ?? '31'), 'k' => 'collection_max_backdate_days']);
        if ($server) {
            proc_terminate($server);
            proc_close($server);
        }
    }
}

/** PHP's built-in web server on a free local port, on the same database */
function wd_serve(): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) {
        throw new RuntimeException("No free local port: $errstr");
    }
    $port = (int)substr((string)strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $db = $GLOBALS['CONFIG']['db'];
    $env = array_merge(getenv(), ['DB_HOST' => (string)$db['host'], 'DB_PORT' => (string)$db['port'], 'DB_NAME' => (string)$db['name'],
        'DB_USER' => (string)$db['user'], 'DB_PASS' => (string)$db['pass']]);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'water_demo_server_' . getmypid() . '.log';
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', APP_ROOT], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, APP_ROOT, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start PHP\'s built-in web server.');
    }
    $base = "http://127.0.0.1:$port";
    for ($i = 0; $i < 50; $i++) {
        usleep(200000);
        $ch = curl_init($base . '/login.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) {
            return [$base, $proc];
        }
    }
    proc_terminate($proc);
    throw new RuntimeException("PHP's built-in web server did not answer (see $log).");
}

function wd_run(string $base): array
{
    $say = [];
    $q = fn(string $sql, array $p = []) => DB::value($sql, $p);
    $money = fn($v) => round((float)$v, 2);
    $PW = 'Demo@12345';
    $today = new DateTimeImmutable('today');
    $todayS = $today->format('Y-m-d');
    $clamp = fn(DateTimeImmutable $d) => ($d > $today ? $today : $d)->format('Y-m-d');

    // ---------------------------------------------------------------------------------------------- calendar
    // the billing months: whole months after the cooperative's closing date, before the current month (at most
    // three); the current month is opened as the period now being read
    $anchor = $today->modify('first day of this month');
    if ((int)$today->format('j') < 3) {
        $anchor = $anchor->modify('-1 month');
    }
    $closing = (string)Settings::get('closing_date', '');
    $first = $anchor->modify('-3 months');
    if ($closing !== '' && valid_date($closing)) {
        $afterClosing = (new DateTimeImmutable($closing))->modify('+1 day');
        $afterClosing = (int)$afterClosing->format('j') === 1 ? $afterClosing : $afterClosing->modify('first day of next month');
        if ($afterClosing > $first) {
            $first = $afterClosing;
        }
    }
    $months = [];
    for ($m = $first; $m < $anchor; $m = $m->modify('+1 month')) {
        $months[] = $m;
    }
    if (!$months) {
        throw new RuntimeException('No whole billing month lies between the closing date (' . $closing . ') and this month.');
    }
    $last = count($months) - 1;
    $live = $months[0];                                                 // the day the water module went live

    // ---------------------------------------------------------------------------------------------- set-up (master data)
    $cashAcct = (int)$q("SELECT id FROM accounts WHERE code = '1002'");
    $cashierRole = (int)$q("SELECT id FROM roles WHERE name = 'Cashier'");
    $wc = (int)$q("SELECT id FROM users WHERE username = 'wcashier'");
    if (!$wc) {
        $wc = DB::insert('users', ['username' => 'wcashier', 'password_hash' => password_hash($PW, PASSWORD_DEFAULT), 'full_name' => 'Lea Marquez',
            'email' => 'wcashier@bayanihan-coop.test', 'employee_no' => 'EMP-018', 'status' => 'active', 'must_change_password' => 0,
            'password_changed_at' => date('Y-m-d H:i:s'), 'coop_cash_account_id' => $cashAcct ?: null]);
        DB::insert('user_roles', ['user_id' => $wc, 'role_id' => $cashierRole]);
        Audit::log('create', 'admin', 'user', $wc, 'wcashier', ['reason' => 'Water cashier (sample data)']);
    }
    $say[] = 'user wcashier (Lea Marquez, Cashier): collects water payments into 1002 Cash on Hand - Water Collections';

    $terms = (int)($q("SELECT id FROM terms WHERE name = 'Net 15'") ?: 0) ?: null;
    $vendor = (int)DB::insert('vendors', ['name' => 'Rodel Ocampo', 'company_name' => '', 'contact_person' => 'Rodel Ocampo', 'phone' => '0917 555 0188',
        'email' => 'ocampo.supply@example.test', 'address' => '14 Kalayaan Street, Makati City', 'terms_id' => $terms,
        'notes' => 'Ocampo Plumbing and Meter Supply: water meters and service-line materials', 'is_active' => 1]);
    $vendorParty = (int)Party::ensureFor('vendor', $vendor);
    $say[] = 'supplier Rodel Ocampo (Ocampo Plumbing and Meter Supply), party ' . $q('SELECT party_no FROM parties WHERE id = :p', ['p' => $vendorParty]);

    // ---------------------------------------------------------------------------------------------- operators
    $mgr = new Browser($base, 'wd_manager');
    $cas = new Browser($base, 'wd_cashier');
    $ap = new Browser($base, 'wd_apclerk');
    foreach ([[$mgr, 'manager'], [$cas, 'wcashier'], [$ap, 'apclerk']] as [$b, $u]) {
        if (!$b->login($u, $PW)) {
            throw new RuntimeException("$u could not sign in at $base (" . $b->url . ')');
        }
    }
    $fail = function (Browser $b, string $what): never {
        $msg = $b->json['error'] ?? $b->json['message'] ?? null;
        if ($msg === null) {
            $msg = preg_match('/class="[^"]*(?:alert-danger|alert-error|flash-error|error)[^"]*"[^>]*>(.*?)<\/div>/si', $b->body, $m) ? trim(strip_tags($m[1])) : substr(trim(preg_replace('/\s+/', ' ', strip_tags($b->body))), 0, 300);
        }
        throw new RuntimeException("$what: $msg (HTTP {$b->status}, {$b->url})");
    };
    $json = function (Browser $b, string $path, array $data, string $what) use ($fail): array {
        $b->postJson($path, $data);
        if (($b->json['success'] ?? false) !== true) {
            $fail($b, $what);
        }
        return $b->json;
    };
    $form = fn(Browser $b, string $path, array $data) => $b->post($path, ['csrf_token' => $b->csrf()] + $data, ['X-CSRF-Token: ' . $b->csrf()]);
    // every water transaction carries a class of the Class List (the forms require it): the class of the water
    // branch, else the first class on the list, else a class made for the water utility
    $classId = (int)($q('SELECT b.class_id FROM branches b JOIN classes c ON c.id = b.class_id AND c.is_active = 1 WHERE b.Deleted = 0 ORDER BY b.pkey LIMIT 1')
        ?: $q('SELECT id FROM classes WHERE is_active = 1 ORDER BY sort_order, name LIMIT 1'));
    if ($classId <= 0) {
        $classId = (int)DB::insert('classes', ['name' => 'Water Utility', 'description' => 'Water service', 'sort_order' => 10, 'created_by' => (int)$q("SELECT id FROM users WHERE username = 'admin'")]);
        Classes::reset();
    }
    $org = ['class_id' => $classId];

    // the report signatories (Water Billing > Institution Configuration)
    $form($mgr, 'water/process/saveInstitutionConfiguration.php', ['officers' => ['manager' => 'Carlo Reyes', 'general_manager' => 'Rosario Dizon']]);
    if ((int)$q("SELECT COUNT(*) FROM companyofficers WHERE COALESCE(isdeleted, 0) = 0 AND name IN ('Carlo Reyes', 'Rosario Dizon')") !== 2) {
        $fail($mgr, 'Institution Configuration');
    }
    $say[] = 'signatories: Carlo Reyes (Manager), Rosario Dizon (General Manager)';

    // ---------------------------------------------------------------------------------------------- billing periods
    // (the billing pages refuse to bill until a period is open)
    $periods = [];
    foreach (array_merge($months, [$anchor]) as $i => $m) {
        $from = $m->format('Y-m-d') . ' 12:00';
        $to = $m->modify('+1 month')->format('Y-m-d') . ' 11:59';
        $form($mgr, 'water/process/addBillPeriod.php', ['date_from' => $from, 'date_to' => $to, 'duration' => 1]);
        $pid = (int)$q('SELECT period_id FROM bill_periods WHERE start_date = :s', ['s' => $from . ':00']);
        if (!$pid) {
            $fail($mgr, "Billing period $from");
        }
        $periods[$i] = $pid;
    }
    $say[] = 'billing periods: ' . implode(', ', DB::column('SELECT bp_code FROM bill_periods ORDER BY start_date'));

    // ---------------------------------------------------------------------------------------------- stock
    $code = fn(string $c) => (int)$q('SELECT code_id FROM bill_codes WHERE code = :c', ['c' => $c]);
    $itemOf = fn(int $codeId) => (int)$q('SELECT item_id FROM bill_codes WHERE code_id = :c', ['c' => $codeId]);
    $METER = $code('WATER METER');
    $PIPE = $code('PVC PIPE');
    $FIT = $code('FITTINGS SET');
    $site = (int)WaterPosting::siteId();
    $buyDate = $live->modify('+2 days')->format('Y-m-d');
    $lines = [];
    foreach ([[$METER, 20, 750], [$PIPE, 60, 85], [$FIT, 20, 260]] as [$c, $qty, $rate]) {
        $lines[] = ['item_id' => $itemOf($c), 'description' => (string)$q('SELECT name FROM items WHERE id = :i', ['i' => $itemOf($c)]), 'qty' => $qty, 'rate' => $rate, 'amount' => $qty * $rate, 'site_id' => $site];
    }
    $ap->post('index.php?p=vend.bills&a=new', ['_csrf' => $ap->csrf(), 'save' => 1, 'txn_date' => $buyDate, 'vendor_id' => $vendor, 'site_id' => $site,
        'due_date' => $live->modify('+17 days')->format('Y-m-d'), 'ref_no' => 'OPS-SI-' . $live->format('ym') . '-0142', 'memo' => 'Water meters and service-line materials', 'lines' => $lines]);
    $billId = (int)$q("SELECT id FROM transactions WHERE txn_type = 'bill' AND vendor_id = :v ORDER BY id DESC LIMIT 1", ['v' => $vendor]);
    if (!$billId) {
        $fail($ap, 'Enter Bills (water materials)');
    }
    $bank = (int)$q("SELECT id FROM accounts WHERE code = '1010'") ?: (int)$q("SELECT id FROM accounts WHERE account_type = 'Bank' AND is_active = 1 AND name LIKE '%Bank%' ORDER BY code LIMIT 1");
    $billTotal = $money($q('SELECT total FROM transactions WHERE id = :t', ['t' => $billId]));
    $ap->post('index.php?p=vend.pay_bills', ['_csrf' => $ap->csrf(), 'txn_date' => $clamp($live->modify('+16 days')), 'account_id' => $bank,
        'payment_method_id' => (int)$q("SELECT id FROM payment_methods WHERE name = 'Check'"), 'pay' => [$billId => ['selected' => 1, 'amount' => $billTotal]]]);
    if (Balances::open(Txn::load($billId)) > 0.004) {
        $fail($ap, 'Pay Bills (water materials)');
    }
    $say[] = sprintf('stock bought on Enter Bills (%s, %s) and paid by check on Pay Bills: 20 meters, 60 PVC pipes, 20 fittings sets', $q('SELECT txn_no FROM transactions WHERE id = :t', ['t' => $billId]), number_format($billTotal, 2));

    // ---------------------------------------------------------------------------------------------- accounts
    $streets = [1 => 'Mabini Street', 2 => 'Sampaguita Street', 3 => 'Kamagong Street', 4 => 'Narra Street'];
    $city = (int)($q("SELECT pkey FROM cities WHERE city = 'Makati' AND status = 'A'") ?: $q("SELECT pkey FROM cities WHERE status = 'A' ORDER BY pkey LIMIT 1"));
    $RES_REG = 1; $RES_ASSOC = 2; $COM_REG = 5; $COM_ASSOC = 6;     // rate codes of database/seeds/water_reference.sql
    $HALF = 2; $THREE_Q = 3;                                          // pipe sizes 1/2 and 3/4 inch
    // key => [first, middle, last, birth date, gender, civil status, rate code, pipe, zone, house no, phone, link, discount, opened, use, profile]
    $people = [
        'M1' => ['member' => '100001', 'rc' => $RES_REG, 'zone' => 1, 'house' => '12', 'use' => 'res'],
        'M2' => ['member' => '100002', 'rc' => $RES_REG, 'zone' => 1, 'house' => '27', 'use' => 'res'],
        'M2S' => ['same' => 'M2', 'first' => 'Jose', 'last' => 'Reyes', 'rc' => $COM_REG, 'pipe' => $THREE_Q, 'zone' => 1, 'house' => '29', 'use' => 'shop', 'note' => 'Reyes Sari-Sari Store'],
        'M3' => ['member' => '100003', 'rc' => $RES_REG, 'zone' => 2, 'house' => '8', 'use' => 'res'],
        'M4' => ['member' => '100005', 'rc' => $RES_REG, 'zone' => 2, 'house' => '41', 'use' => 'res'],
        'M5' => ['member' => '100006', 'rc' => $RES_REG, 'zone' => 3, 'house' => '5', 'use' => 'res', 'profile' => 'skip'],
        'M6' => ['member' => '100008', 'rc' => $RES_REG, 'zone' => 3, 'house' => '19', 'use' => 'res'],
        'M7' => ['member' => '100009', 'rc' => $RES_REG, 'zone' => 4, 'house' => '33', 'use' => 'res', 'profile' => 'late'],
        'M8' => ['member' => '100011', 'rc' => $RES_REG, 'zone' => 4, 'house' => '2', 'use' => 'res'],
        'V1' => ['vendor' => true, 'first' => 'Rodel', 'last' => 'Ocampo', 'rc' => $COM_ASSOC, 'zone' => 1, 'house' => '14', 'phone' => '09175550188', 'use' => 'shop', 'street' => 'Kalayaan Street'],
        'N1' => ['first' => 'Teresita', 'middle' => 'Lopez', 'last' => 'Navarro', 'dob' => '1951-03-08', 'g' => 'Female', 'cs' => 'Widow', 'zone' => 1, 'house' => '3', 'disc' => 'senior', 'use' => 'senior'],
        'N2' => ['first' => 'Benjamin', 'middle' => 'Cortez', 'last' => 'Pascual', 'dob' => '1979-07-14', 'g' => 'Male', 'cs' => 'Married', 'zone' => 1, 'house' => '18', 'use' => 'res', 'profile' => 'reversal'],
        'N3' => ['first' => 'Imelda', 'middle' => 'Ramos', 'last' => 'Salazar', 'dob' => '1957-11-21', 'g' => 'Female', 'cs' => 'Married', 'zone' => 2, 'house' => '22', 'disc' => 'senior', 'use' => 'senior'],
        'N4' => ['first' => 'Wilfredo', 'middle' => 'Santos', 'last' => 'Ilagan', 'dob' => '1983-02-02', 'g' => 'Male', 'cs' => 'Married', 'zone' => 2, 'house' => '9', 'use' => 'res'],
        'N5' => ['first' => 'Jennifer', 'middle' => 'Uy', 'last' => 'Manalo', 'dob' => '1991-05-30', 'g' => 'Female', 'cs' => 'Single', 'zone' => 2, 'house' => '15', 'use' => 'res'],
        'N6' => ['first' => 'Victor', 'middle' => 'Go', 'last' => 'Castillo', 'dob' => '1975-09-09', 'g' => 'Male', 'cs' => 'Married', 'rc' => $COM_ASSOC, 'zone' => 3, 'house' => '40', 'use' => 'shop', 'note' => 'Castillo Bakery'],
        'N7' => ['first' => 'Henry', 'middle' => 'Tan', 'last' => 'Lim', 'dob' => '1968-12-01', 'g' => 'Male', 'cs' => 'Married', 'rc' => $COM_ASSOC, 'pipe' => $THREE_Q, 'zone' => 3, 'house' => '1', 'use' => 'big', 'note' => 'Lim Laundry Services'],
        'N8' => ['first' => 'Marites', 'middle' => 'Diaz', 'last' => 'Gonzales', 'dob' => '1985-04-18', 'g' => 'Female', 'cs' => 'Married', 'zone' => 3, 'house' => '26', 'use' => 'res'],
        'N9' => ['first' => 'Alfredo', 'middle' => 'Cruz', 'last' => 'Rivera', 'dob' => '1948-06-02', 'g' => 'Male', 'cs' => 'Widower', 'zone' => 4, 'house' => '11', 'disc' => 'senior', 'use' => 'senior'],
        'N10' => ['first' => 'Kristine', 'middle' => 'Lim', 'last' => 'Soriano', 'dob' => '1994-10-25', 'g' => 'Female', 'cs' => 'Single', 'zone' => 4, 'house' => '20', 'use' => 'res'],
        'N11' => ['first' => 'Mark Anthony', 'middle' => 'Reyes', 'last' => 'Tolentino', 'dob' => '1989-01-17', 'g' => 'Male', 'cs' => 'Married', 'zone' => 4, 'house' => '7', 'use' => 'res'],
        'N12' => ['first' => 'Rowena', 'middle' => 'Garcia', 'last' => 'De Leon', 'dob' => '1981-08-08', 'g' => 'Female', 'cs' => 'Married', 'zone' => 1, 'house' => '31', 'use' => 'res'],
        'N13' => ['first' => 'Paolo', 'middle' => 'Mendez', 'last' => 'Enriquez', 'dob' => '1990-03-03', 'g' => 'Male', 'cs' => 'Single', 'zone' => 2, 'house' => '4', 'use' => 'res'],
        'N14' => ['first' => 'Lorna', 'middle' => 'Pineda', 'last' => 'Valdez', 'dob' => '1972-12-12', 'g' => 'Female', 'cs' => 'Separated', 'zone' => 3, 'house' => '13', 'use' => 'res', 'profile' => 'partial'],
        'N15' => ['first' => 'Ferdinand', 'middle' => 'Luna', 'last' => 'Santiago', 'dob' => '1966-06-16', 'g' => 'Male', 'cs' => 'Married', 'zone' => 3, 'house' => '35', 'use' => 'res'],
        'N16' => ['first' => 'Liezel', 'middle' => 'Torres', 'last' => 'Mercado', 'dob' => '1993-09-29', 'g' => 'Female', 'cs' => 'Single', 'zone' => 4, 'house' => '24', 'use' => 'res'],
        'N17' => ['first' => 'Edwin', 'middle' => 'Bautista', 'last' => 'Robles', 'dob' => '1977-02-20', 'g' => 'Male', 'cs' => 'Married', 'zone' => 4, 'house' => '16', 'use' => 'res', 'profile' => 'never'],
        'N18' => ['first' => 'Susan', 'middle' => 'Aquino', 'last' => 'Vergara', 'dob' => '1980-11-05', 'g' => 'Female', 'cs' => 'Married', 'zone' => 1, 'house' => '6', 'use' => 'res', 'profile' => 'late'],
        'N19' => ['first' => 'Joy', 'middle' => 'Mallari', 'last' => 'Fernandez', 'dob' => '1987-07-07', 'g' => 'Female', 'cs' => 'Married', 'zone' => 2, 'house' => '30', 'disc' => 'employee', 'use' => 'res'],
        'N20' => ['first' => 'Analyn', 'middle' => 'Castro', 'last' => 'Medina', 'dob' => '1995-01-28', 'g' => 'Female', 'cs' => 'Single', 'zone' => 2, 'house' => '37', 'use' => 'res', 'profile' => 'skip'],
        'N21' => ['first' => 'Nestor', 'middle' => 'Valencia', 'last' => 'Aguilar', 'dob' => '1974-04-04', 'g' => 'Male', 'cs' => 'Married', 'zone' => 3, 'house' => '44', 'use' => 'res', 'opened' => [0, 9], 'conn' => 'installment'],
        'N22' => ['first' => 'Cherry', 'middle' => 'Ramos', 'last' => 'Padilla', 'dob' => '1992-12-24', 'g' => 'Female', 'cs' => 'Married', 'zone' => 4, 'house' => '39', 'use' => 'res', 'opened' => [0, 17], 'conn' => 'cash'],
        'N23' => ['first' => 'Ronaldo', 'middle' => 'Sison', 'last' => 'Buenaventura', 'dob' => '1986-10-10', 'g' => 'Male', 'cs' => 'Married', 'zone' => 1, 'house' => '42', 'use' => 'res', 'opened' => [1, 8], 'conn' => 'installment'],
    ];
    $acct = [];
    $openedOn = [];
    foreach ($people as $key => $p) {
        $o = $p['opened'] ?? null;
        if ($o !== null && (!isset($months[$o[0]]) || $months[$o[0]]->modify('+' . $o[1] . ' days') > $today)) {
            continue;                                                   // a connection after today's date
        }
        $connDate = $o ? $months[$o[0]]->modify('+' . $o[1] . ' days')->format('Y-m-d') : sprintf('%d-%02d-%02d', 2012 + crc32($key) % 12, 1 + crc32($key . 'm') % 12, 1 + crc32($key . 'd') % 28);
        $f = ['gender' => $p['g'] ?? '', 'civilstatus' => $p['cs'] ?? '', 'zone' => $p['zone'], 'meter_sequence' => sprintf('Z%d-%03d', $p['zone'], 10 + count($acct) * 3),
            'rc_id' => $p['rc'] ?? $RES_ASSOC, 'pipe_size_id' => $p['pipe'] ?? $HALF, 'connection_date' => $connDate, 'housebldg' => $p['house'],
            'street' => $p['street'] ?? $streets[$p['zone']], 'other_address_detail' => $p['note'] ?? '', 'city_id' => $city, 'zippostal_code' => 1200];
        if (isset($p['member'])) {
            $m = DB::row('SELECT * FROM members WHERE member_no = :n', ['n' => $p['member']]);
            $f += ['firstname' => $m['first_name'], 'middlename' => (string)$m['middle_name'], 'lastname' => $m['last_name'], 'dateofbirth' => (string)$m['birth_date'],
                'contact_number' => (string)$m['mobile'], 'link_party_id' => (int)$m['party_id']];
            $f['gender'] = $f['gender'] ?: (in_array($m['first_name'], ['Maria', 'Ana', 'Liza', 'Grace', 'Rosa', 'Cecilia'], true) ? 'Female' : 'Male');
            $f['civilstatus'] = $f['civilstatus'] ?: 'Married';
        } elseif (isset($p['same'])) {
            $holder = DB::row('SELECT * FROM water_accounts WHERE pkey = :k', ['k' => $acct[$p['same']]]);
            $f += ['firstname' => $holder['firstname'], 'middlename' => (string)$holder['middlename'], 'lastname' => $holder['lastname'], 'dateofbirth' => (string)$holder['dateofbirth'],
                'link_party_id' => (int)$holder['party_id'], 'gender' => $holder['gender'], 'civilstatus' => $holder['civilstatus']];
        } elseif (!empty($p['vendor'])) {
            $f += ['firstname' => $p['first'], 'middlename' => '', 'lastname' => $p['last'], 'contact_number' => $p['phone'], 'link_party_id' => $vendorParty, 'gender' => 'Male', 'civilstatus' => 'Married'];
        } else {
            $f += ['firstname' => $p['first'], 'middlename' => $p['middle'], 'lastname' => $p['last'], 'dateofbirth' => $p['dob'],
                'contact_number' => sprintf('0918555%04d', 200 + count($acct) * 7)];
        }
        $before = (int)$q('SELECT COUNT(*) FROM water_accounts');
        $form($mgr, 'water/process/addMember.php', $f);
        if ((int)$q('SELECT COUNT(*) FROM water_accounts') !== $before + 1) {
            $fail($mgr, "Open water-service account for {$f['firstname']} {$f['lastname']}");
        }
        $id = (int)$q('SELECT MAX(pkey) FROM water_accounts');
        $acct[$key] = $id;
        $openedOn[$key] = $connDate;
        // the account was opened on its connection date (the former program's records carried that date too)
        DB::run('UPDATE water_accounts SET datecreated = :d WHERE pkey = :k', ['d' => $connDate . ' 09:00:00', 'k' => $id]);
        if (isset($p['disc'])) {
            $discountId = (int)$q('SELECT discount_id FROM discounts WHERE discount_type = :t', ['t' => $p['disc'] === 'senior' ? 'Senior Citizen' : 'Employees']);
            $form($mgr, 'water/process/addDiscountedMembers.php', ['member_id' => $id, 'discount_id' => $discountId]);
        }
    }
    $memberAccts = array_filter($acct, fn($k) => $k[0] === 'M', ARRAY_FILTER_USE_KEY);
    $say[] = sprintf('%d water-service accounts on %d parties: %d accounts of %d cooperative members (one with a second, commercial connection), one of the supplier, %d of non-members; %d with a discount',
        count($acct), (int)$q('SELECT COUNT(DISTINCT party_id) FROM water_accounts'), count($memberAccts),
        (int)$q('SELECT COUNT(DISTINCT party_id) FROM water_accounts WHERE pkey IN (' . implode(',', $memberAccts) . ')'), count($acct) - count($memberAccts) - 1,
        (int)$q('SELECT COUNT(DISTINCT member_id) FROM discounted_members'));

    // ---------------------------------------------------------------------------------------------- new connections
    $expense = (int)($q("SELECT id FROM accounts WHERE code = '6030'") ?: $q("SELECT id FROM accounts WHERE category = 'Expense' AND is_active = 1 ORDER BY code LIMIT 1"));
    $issue = function (string $date, string $who, string $purpose, array $lines) use ($mgr, $form, $expense, $q, $fail, $org): int {
        $before = (int)$q('SELECT COALESCE(MAX(issuance_id), 0) FROM inventory_issuances');
        $form($mgr, 'water/process/saveItemIssuance.php', ['issuance_date' => $date, 'recipient' => $who, 'department' => 'Water Operations', 'purpose' => $purpose,
            'expense_account_id' => $expense, 'code_id' => array_keys($lines), 'quantity' => array_values($lines)] + $org);
        $id = (int)$q('SELECT COALESCE(MAX(issuance_id), 0) FROM inventory_issuances');
        if ($id === $before) {
            $fail($mgr, "Material Issuance ($purpose)");
        }
        return $id;
    };
    $oneTime = function (int $member, int $codeId, float $amount, string $date, float $qty = 1) use ($mgr, $form, $q, $fail, $org): int {
        $before = (int)$q('SELECT COALESCE(MAX(bill_id), 0) FROM one_time_bills');
        $form($mgr, 'water/process/addOneTimeBill.php', ['member_id' => $member, 'bill_code_id' => $codeId, 'quantity' => $qty, 'total_amount' => number_format($amount, 2, '.', ''),
            'bill_date' => $date, 'due_date' => $date] + $org);
        $id = (int)$q('SELECT COALESCE(MAX(bill_id), 0) FROM one_time_bills');
        if ($id === $before) {
            $fail($mgr, 'One-time bill');
        }
        return $id;
    };
    $receipts = [];
    $pay = function (string $key, string $date, array $opt = []) use (&$acct, $cas, $json, $q, $org, &$receipts): ?string {
        $member = $acct[$key];
        $items = [];
        $total = 0.0;
        foreach ($opt['water'] ?? [] as $rid => $amt) {
            $open = round((float)$q('SELECT amount_due - COALESCE(amount_paid, 0) FROM bill_readings WHERE reading_id = :r', ['r' => $rid]), 2);
            if ($open <= 0) {
                continue;
            }
            $a = $amt === null ? $open : min($open, (float)$amt);
            $items[] = ['type' => 'water', 'id' => $rid, 'payment_amount' => number_format($a, 2, '.', '')];
            $total += $a;
        }
        $others = $opt['others'] ?? false;                              // true, or 'onetime' for the one-time bills only
        if ($others) {
            foreach (DB::all("SELECT bill_id, total_amount - COALESCE(amount_paid, 0) AS open FROM one_time_bills WHERE member_id = :m AND bill_date <= :d AND status NOT IN ('paid', 'cancelled', 'deleted') AND total_amount - COALESCE(amount_paid, 0) > 0", ['m' => $member, 'd' => $date]) as $b) {
                $items[] = ['type' => 'onetime', 'id' => (int)$b['bill_id']];
                $total += (float)$b['open'];
            }
        }
        if ($others === true) {
            foreach (DB::all("SELECT bill_id, total_amount - COALESCE(amount_paid, 0) AS open FROM recurring_bills WHERE member_id = :m AND bill_date <= :d AND status NOT IN ('paid', 'cancelled', 'deleted') AND total_amount - COALESCE(amount_paid, 0) > 0", ['m' => $member, 'd' => $date]) as $b) {
                $items[] = ['type' => 'recurring', 'id' => (int)$b['bill_id']];
                $total += (float)$b['open'];
            }
            $s = DB::row("SELECT s.schedule_id, s.amount - COALESCE(s.amount_paid, 0) AS open FROM installment_schedules s JOIN installment_bills b ON b.installment_id = s.installment_id
                           WHERE b.member_id = :m AND b.start_date <= :d AND s.status IN ('pending', 'partial') ORDER BY s.due_date, s.schedule_id LIMIT 1", ['m' => $member, 'd' => $date]);
            if ($s) {
                $items[] = ['type' => 'installment', 'id' => (int)$s['schedule_id']];
                $total += (float)$s['open'];
            }
        }
        if (!$items) {
            return null;
        }
        $penalty = (float)($opt['penalty'] ?? 0);
        $due = round($total + $penalty, 2);
        $tendered = ($opt['exact'] ?? false) ? $due : ceil($due / 100) * 100;
        $req = ['member_id' => $member, 'items' => $items, 'tendered' => number_format($tendered, 2, '.', ''), 'transaction_date' => $date,
            'idempotency_key' => 'demo' . bin2hex(random_bytes(12))] + $org;
        if ($penalty > 0) {
            $req += ['apply_penalty' => 1, 'penalty_amount' => number_format($penalty, 2, '.', '')];
        }
        $r = $json($cas, 'water/process/processUnifiedPayment.php', $req, "Collection of account $key on $date");
        $receipts[] = (string)$r['receipt_no'];
        return (string)$r['receipt_no'];
    };

    foreach ($people as $key => $p) {
        if (!isset($p['conn'], $acct[$key])) {
            continue;
        }
        $d = $openedOn[$key];
        $oneTime($acct[$key], $code('CONNECTION FEE'), 1500, $d);
        $oneTime($acct[$key], $code('SERVICE DEPOSIT'), 500, $d);
        if ($p['conn'] === 'cash') {
            $oneTime($acct[$key], $METER, 1200, $d);
        } else {
            $before = (int)$q('SELECT COALESCE(MAX(installment_id), 0) FROM installment_bills');
            $form($mgr, 'water/process/addInstallmentBill.php', ['member_id' => $acct[$key], 'bill_code_id' => $METER, 'quantity' => 1, 'total_amount' => '1200.00', 'term' => 3,
                'payment_mode' => 'monthly', 'amortization_type' => 'fixed', 'start_date' => $d] + $org);
            if ((int)$q('SELECT COALESCE(MAX(installment_id), 0) FROM installment_bills') === $before) {
                $fail($mgr, "Meter on installment for $key");
            }
        }
        $issue($d, 'Field crew, Zone ' . $p['zone'], 'New service connection of ' . $p['first'] . ' ' . $p['last'], [$PIPE => 4, $FIT => 1]);
        // the connection fee and the deposit (and a meter bought outright) are paid when the connection is applied for;
        // the schedules of a meter on installment are collected with the water bills
        $pay($key, $d, ['others' => 'onetime', 'exact' => true]);
    }
    $say[] = 'new connections: connection fee 1,500.00 and service deposit 500.00 each; one meter sold outright, the others on 3-month installment; materials issued to the field';

    // a line repair: issued, found charged to the wrong job and returned, then issued again
    $repairDate = $clamp($live->modify('+23 days'));
    $wrong = $issue($repairDate, 'Field crew, Zone 2', 'Main line repair, Sampaguita Street', [$PIPE => 6]);
    $form($mgr, 'water/process/reverseItemIssuance.php', ['issuance_id' => $wrong, 'reason' => 'Six lengths recorded; four were used. Returned to the stockroom and issued again.']);
    if (strtolower((string)$q('SELECT status FROM inventory_issuances WHERE issuance_id = :i', ['i' => $wrong])) === 'posted') {
        $fail($mgr, 'Reverse Material Issuance');
    }
    $issue($repairDate, 'Field crew, Zone 2', 'Main line repair, Sampaguita Street', [$PIPE => 4]);
    $say[] = 'line repair: an issuance of 6 pipes returned (voided, stock back) and issued again with 4';

    // ---------------------------------------------------------------------------------------------- recurring maintenance fee
    $mgr->get('water/views/recurring_bill.php');
    $token = function (string $action) use ($mgr): string {
        $mgr->get('water/views/recurring_bill.php');
        foreach (preg_split('#</form>#i', $mgr->body) as $f) {
            if (preg_match('/name="action"\s+value="' . preg_quote($action, '/') . '"/', $f) && preg_match('/name="operation_token"\s+value="([0-9a-f]+)"/', $f, $m)) {
                return $m[1];
            }
        }
        throw new RuntimeException("No form token for $action on the Recurring Billing page.");
    };
    $form($mgr, 'water/views/recurring_bill.php', ['action' => 'create_plan', 'operation_token' => $token('create_plan'), 'bill_code_id' => $code('MAINTENANCE FEE'), 'amount' => '50.00',
        'frequency' => 'Monthly', 'first_billing_date' => $live->format('Y-m-d'), 'term_days' => 15]);
    $plan = (int)$q('SELECT MAX(plan_id) FROM recurring_plans');
    if (!$plan) {
        $fail($mgr, 'Create recurring plan');
    }
    foreach (['M2S', 'V1', 'N6', 'N7'] as $k) {
        $form($mgr, 'water/views/recurring_bill.php', ['action' => 'enroll_member', 'operation_token' => $token('enroll_member'), 'plan_id' => $plan, 'member_id' => $acct[$k],
            'subscription_start_date' => $live->format('Y-m-d'), 'catch_up_mode' => 'all_cycles'] + $org);
    }
    $form($mgr, 'water/views/recurring_bill.php', ['action' => 'generate_due_bills', 'operation_token' => $token('generate_due_bills')] + $org);
    $say[] = sprintf('recurring plan MAINTENANCE FEE 50.00 a month for the 4 commercial connections: %d bills generated', (int)$q('SELECT COUNT(*) FROM recurring_bills WHERE plan_id = :p', ['p' => $plan]));

    // ---------------------------------------------------------------------------------------------- readings, bills, collections
    $meter = [];
    $bills = [];                                                        // key => [period index => reading id]
    $use = ['res' => [11, 27], 'senior' => [7, 16], 'shop' => [24, 46], 'big' => [52, 88]];
    $misread = null;
    foreach ($months as $i => $m) {
        $pid = $periods[$i];
        $end = $m->modify('+1 month');
        foreach ($people as $key => $p) {
            if (!isset($acct[$key]) || $openedOn[$key] >= $end->format('Y-m-d')) {
                continue;
            }
            [$lo, $hi] = $use[$p['use']];
            $cons = $lo + crc32($key . '#' . $m->format('Ym')) % ($hi - $lo + 1);
            if (isset($p['opened']) && $p['opened'][0] === $i) {
                $cons = max(3, (int)round($cons * (1 - $p['opened'][1] / 31)));   // a part of the month
            }
            $prev = $meter[$key] ?? 0;
            $reading = $prev + $cons;
            if ($key === 'N8' && $i === $last) {
                $misread = ['actual' => $reading, 'cons' => $cons];
                $reading = $prev + (int)strrev(str_pad((string)$cons, 2, '0', STR_PAD_LEFT));   // the digits read the wrong way round
                if ($reading === $prev + $cons) {
                    $reading += 9;
                }
            }
            $r = $json($mgr, 'water/process/saveIndividualReading.php', ['period_id' => $pid, 'member_id' => $acct[$key], 'pres_reading' => $reading], "Reading of $key in " . $m->format('M Y'));
            $bills[$key][$i] = (int)$r['reading_id'];
            $meter[$key] = $key === 'N8' && $i === $last ? $misread['actual'] : $reading;
        }
        $json($mgr, 'water/process/postBilling.php', ['action' => 'post', 'period_id' => $pid, 'filter' => 'water', 'billing_date' => $end->format('Y-m-d'), 'term_days' => 15] + $org, 'Post Billing ' . $m->format('M Y'));
        $say[] = sprintf('%s: %d readings, bills posted %s, due %s, total %s', $m->format('F Y'), count(array_filter($bills, fn($b) => isset($b[$i]))), $end->format('Y-m-d'),
            $end->modify('+15 days')->format('Y-m-d'), number_format((float)$q('SELECT SUM(amount_due) FROM bill_readings WHERE period_id = :p', ['p' => $pid]), 2));

        // the misread meter of N8 is corrected on Billing Management before anyone pays the bill (void and replace).
        // Only an administrator may correct a posted bill: admin signs in for it (its first-login password change is
        // set aside for this one sign-in and restored, so the delivered admin / Admin@12345 still asks for a new password)
        if ($misread && $i === $last) {
            $adminRow = DB::row("SELECT id, must_change_password, last_login_at, last_login_ip FROM users WHERE username = 'admin'");
            try {
                DB::run('UPDATE users SET must_change_password = 0 WHERE id = :u', ['u' => $adminRow['id']]);
                $adm = new Browser($base, 'wd_admin');
                if (!$adm->login('admin', 'Admin@12345')) {
                    throw new RuntimeException('admin could not sign in with the delivered password to correct a bill.');
                }
                $form($adm, 'water/process/billLifecycleProcess.php', ['action' => 'edit', 'bill_type' => 'water', 'bill_id' => $bills['N8'][$i], 'pres_reading' => $misread['actual'],
                    'reason' => 'Meter misread: the reader keyed the consumption digits the wrong way round. Re-read on site.'] + $org);
                $adm->get('index.php?p=auth.logout');
            } finally {
                DB::run('UPDATE users SET must_change_password = :m, last_login_at = :a, last_login_ip = :i WHERE id = :u',
                    ['m' => $adminRow['must_change_password'], 'a' => $adminRow['last_login_at'], 'i' => $adminRow['last_login_ip'], 'u' => $adminRow['id']]);
            }
            if (!WaterPosting::byEvent('WBILL-' . $bills['N8'][$i] . '-R1')) {
                $fail($adm, 'Bill correction of N8');
            }
            $say[] = sprintf('bill of Marites Gonzales (account %s) corrected after a meter misread: invoice %s voided and replaced by %s-R1',
                $q('SELECT account_number FROM water_accounts WHERE pkey = :k', ['k' => $acct['N8']]), $q('SELECT global_ref_number FROM bill_readings WHERE reading_id = :r', ['r' => $bills['N8'][$i]]),
                $q('SELECT global_ref_number FROM bill_readings WHERE reading_id = :r', ['r' => $bills['N8'][$i]]));
        }

        // collections of this month's bills, dated in the following month
        $billDay = $end;
        $due = $end->modify('+15 days');
        $n = 0;
        foreach ($people as $key => $p) {
            if (!isset($bills[$key][$i])) {
                continue;
            }
            $n++;
            $profile = $p['profile'] ?? 'ontime';
            $rid = $bills[$key][$i];
            $onTime = $billDay->modify('+' . (2 + crc32($key . $i) % 11) . ' days');
            if ($i === $last) {
                // the current month: the bills are not due yet; about two in three are paid so far
                if ($profile === 'never' || ($profile === 'ontime' && crc32($key) % 3 === 0)) {
                    continue;
                }
                $date = $onTime > $today ? $today : $onTime;
                if ($date < $billDay) {
                    continue;
                }
                $older = [];
                foreach ($bills[$key] as $j => $r) {
                    if ($j < $i) {
                        $older[$r] = null;
                    }
                }
                $overdue = false;
                foreach (array_keys($older) as $r) {
                    $overdue = $overdue || ((float)$q('SELECT amount_due - COALESCE(amount_paid, 0) FROM bill_readings WHERE reading_id = :r', ['r' => $r]) > 0 && (string)$q('SELECT due_date FROM bill_readings WHERE reading_id = :r', ['r' => $r]) < $date->format('Y-m-d'));
                }
                $pay($key, $date->format('Y-m-d'), ['water' => $older + [$rid => null], 'others' => true, 'penalty' => $overdue ? 25 : 0]);
                continue;
            }
            switch ($profile) {
                case 'never':
                case 'skip':
                    break;                                              // paid with the next bill, or never
                case 'late':
                    $date = $due->modify('+' . (4 + crc32($key) % 6) . ' days');
                    if ($date <= $today) {
                        $pay($key, $date->format('Y-m-d'), ['water' => [$rid => null], 'others' => true, 'penalty' => 25]);
                    }
                    break;
                case 'partial':
                    $amt = round((float)$q('SELECT amount_due FROM bill_readings WHERE reading_id = :r', ['r' => $rid]) * 0.6, 0);
                    $pay($key, $clamp($onTime), ['water' => [$rid => $amt], 'others' => true]);
                    break;
                case 'reversal':
                    if ($i === 0) {
                        // the cashier keys 500.00 instead of the bill amount: the receipt is reversed the same day and
                        // the bill collected again with a new receipt
                        $d = $clamp($onTime);
                        $wrongReceipt = $pay($key, $d, ['water' => [$rid => 500], 'exact' => true]);
                        $form($mgr, 'water/process/collectionCorrectionProcess.php', ['receipt_no' => $wrongReceipt, 'action' => 'error_correction', 'business_date' => $d,
                            'reason' => 'Amount keyed wrongly (500.00 instead of the bill amount). Customer paid the full bill; collected again.'] + $org);
                        if ((string)$q('SELECT status FROM water_postings WHERE event_key = :k', ['k' => WaterPosting::collectionKey($wrongReceipt)]) !== 'voided') {
                            $fail($mgr, 'Collection correction');
                        }
                        $pay($key, $d, ['water' => [$rid => null], 'others' => true]);
                        break;
                    }
                    // no break
                default:
                    $pay($key, $clamp($onTime), ['water' => [$rid => null], 'others' => true]);
            }
        }
    }
    $say[] = sprintf('%d official receipts (on time, partial, late with a 25.00 penalty, two months at once); one receipt reversed and collected again', count(array_filter($receipts)));

    // the earlier billing periods are closed; the last billed one stays open for late corrections, and the current
    // month is the period now being read
    foreach ($months as $i => $m) {
        if ($i < $last) {
            $form($mgr, 'water/process/editBillPeriod.php', ['period_id' => $periods[$i], 'action' => 'close', 'status' => 'closed']);
        }
    }
    $say[] = 'closed periods: ' . (implode(', ', DB::column("SELECT bp_code FROM bill_periods WHERE status = 'closed' ORDER BY start_date")) ?: 'none');

    // ---------------------------------------------------------------------------------------------- checks
    $tb = round((float)$q('SELECT COALESCE(SUM(debit - credit), 0) FROM journal_lines'), 2);
    if (abs($tb) >= 0.005) {
        throw new RuntimeException("The trial balance is off by $tb after the sample water activity.");
    }
    $diff = [];
    foreach ($acct as $k => $id) {
        $ob = round((float)WaterAccounts::openBalance($id)['total'], 2);
        $lb = round((float)WaterAccounts::ledgerBalance($id), 2);
        if (abs($ob - $lb) >= 0.005) {
            $diff[] = "$k ($ob vs $lb)";
        }
    }
    if ($diff) {
        throw new RuntimeException('Water records and ledger differ for: ' . implode(', ', $diff));
    }
    $problems = WaterPosting::mappingProblems();
    if ($problems) {
        throw new RuntimeException('Account mapping problems: ' . json_encode($problems));
    }
    $say[] = sprintf('checks: trial balance 0.00; water open balances = ledger for all %d accounts (A/R open %s); account mapping complete',
        count($acct), number_format(array_sum(array_map(fn($id) => (float)WaterAccounts::openBalance($id)['total'], $acct)), 2));
    return $say;
}
