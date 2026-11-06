<?php
declare(strict_types=1);

/**
 * Sample transactions covering every module, recorded through the same posting
 * engine the screens use (so the ledger, inventory and audit trail are consistent).
 * Dates are relative to "today" so the aging reports look realistic.
 */
require_once APP_ROOT . '/views/layout.php';
require_once APP_ROOT . '/modules/txn/_common.php';

function demo_id(string $table, string $col, string $val): int
{
    $id = DB::value("SELECT id FROM `$table` WHERE `$col` = :v", ['v' => $val]);
    if (!$id) {
        throw new RuntimeException("Demo data: $table.$col = $val not found");
    }
    return (int)$id;
}

function load_demo_data(): void
{
    $d = fn(int $daysAgo) => date('Y-m-d', strtotime("-$daysAgo days"));
    $item = fn(string $c) => demo_id('items', 'code', $c);
    $cust = fn(string $n) => demo_id('customers', 'name', $n);
    $vend = fn(string $n) => demo_id('vendors', 'name', $n);
    $acct = fn(string $c) => demo_id('accounts', 'code', $c);
    $site = fn(string $c) => demo_id('sites', 'code', $c);
    $user = fn(string $u) => demo_id('users', 'username', $u);
    $vat = demo_id('tax_codes', 'code', 'VAT12');
    $nt = demo_id('tax_codes', 'code', 'NT');
    $pm = fn(string $n) => demo_id('payment_methods', 'name', $n);
    $admin = $user('admin');
    Auth::actAs($admin);

    // ---------------------------------------------------------------- classes (sample names; a company names its own)
    $class = [];
    foreach ([['Head Office', 'Wholesale, projects and administration', 1], ['Makati Store', 'Retail counter in Makati', 2], ['Cebu Store', 'Retail counter in Cebu', 3]] as [$cn, $cd, $co]) {
        $class[$cn] = DB::insert('classes', ['name' => $cn, 'description' => $cd, 'sort_order' => $co, 'created_by' => $admin]);
    }
    Classes::reset();
    DB::run("UPDATE pos_terminals SET class_id = :c WHERE code = 'POS-01'", ['c' => $class['Makati Store']]);
    DB::run("UPDATE pos_terminals SET class_id = :c WHERE code = 'POS-02'", ['c' => $class['Cebu Store']]);
    DB::insert('other_names', ['name_type' => 'employee', 'name' => 'Ella Ramos', 'employee_no' => 'EMP-007', 'position' => 'Cashier']);
    DB::insert('other_names', ['name_type' => 'employee', 'name' => 'Dan Villanueva', 'employee_no' => 'EMP-005', 'position' => 'Inventory clerk']);
    DB::insert('other_names', ['name_type' => 'other', 'name' => 'Barangay Treasurer', 'position' => 'Permits and clearances']);

    $sales = fn(array $rows, ?int $c = null) => parse_item_lines($rows, 'sales', ['customer_id' => $c]);
    $buy = fn(array $rows) => parse_item_lines($rows, 'purchase');
    $line = fn(string $code, float $q, ?float $rate = null, float $disc = 0, ?int $siteId = null) => [
        'item_id' => $item($code), 'qty' => $q, 'rate' => $rate ?? (float)DB::value('SELECT sales_price FROM items WHERE code = :c', ['c' => $code]),
        'discount_pct' => $disc, 'tax_code_id' => DB::value('SELECT tax_code_id FROM items WHERE code = :c', ['c' => $code]), 'site_id' => $siteId,
    ];
    $pline = fn(string $code, float $q, ?float $cost = null, ?int $siteId = null, ?int $job = null) => [
        'item_id' => $item($code), 'qty' => $q, 'rate' => $cost ?? (float)DB::value('SELECT cost FROM items WHERE code = :c', ['c' => $code]),
        'tax_code_id' => $GLOBALS['__vat'], 'site_id' => $siteId, 'customer_id' => $job, 'is_billable' => $job ? 1 : 0,
    ];
    $GLOBALS['__vat'] = $vat;
    $exp = fn(string $code, float $amt, string $memo, ?int $tax = null, ?int $job = null) => [
        'account_id' => $acct($code), 'amount' => $amt, 'description' => $memo, 'tax_code_id' => $tax, 'customer_id' => $job, 'is_billable' => $job ? 1 : 0,
    ];
    $pay = function (int $customerId, string $date, float $amount, array $apps, array $extra = []) use ($acct) {
        $disc = array_sum(array_column($apps, 'discount'));
        $wht = array_sum(array_column($apps, 'withholding'));
        return Txn::save('payment', array_merge([
            'txn_date' => $date, 'customer_id' => $customerId, 'account_id' => $acct('1050'), 'account2_id' => Accts::get('ar'),
            'total' => $amount, 'discount_amount' => $disc, 'withholding_amount' => $wht,
        ], $extra), [], null, ['keep_totals' => true, 'applications' => $apps]);
    };
    $invoice = function (string $customer, string $date, array $rows, array $extra = []) use ($cust, $sales, $site, $class) {
        $cid = $cust($customer);
        $c = DB::row('SELECT * FROM customers WHERE id = :id', ['id' => $cid]);
        return Txn::save('invoice', array_merge([
            'txn_date' => $date, 'customer_id' => $cid, 'account_id' => Accts::get('ar'), 'terms_id' => $c['terms_id'],
            'due_date' => Txn::dueDate($date, $c['terms_id'] ? (int)$c['terms_id'] : null), 'sales_rep_id' => $c['sales_rep_id'],
            'site_id' => $site('WH'), 'billing_address' => $c['billing_address'], 'customer_message' => 'Thank you for your business.',
            'class_id' => $class['Head Office'],
        ], $extra), $sales($rows, $cid));
    };

    // ---------------------------------------------------------------- opening balances
    Txn::save('opening_balance', ['txn_date' => $d(80), 'memo' => 'Opening balances', 'txn_no' => 'OB-0001'], [
        ['line_kind' => 'journal', 'account_id' => $acct('1010'), 'amount' => 2500000, 'description' => 'BDO checking opening balance'],
        ['line_kind' => 'journal', 'account_id' => $acct('1020'), 'amount' => 800000, 'description' => 'BPI savings opening balance'],
        ['line_kind' => 'journal', 'account_id' => $acct('1005'), 'amount' => 10000, 'description' => 'Petty cash fund'],
        ['line_kind' => 'journal', 'account_id' => $acct('1001'), 'amount' => 10000, 'description' => 'Register cash funds'],
        ['line_kind' => 'journal', 'account_id' => $acct('3000'), 'amount' => -3320000, 'description' => "Owner's capital"],
    ]);

    // ---------------------------------------------------------------- fixed assets
    $mkAsset = function (array $a) use ($acct) {
        $cat = DB::row('SELECT * FROM asset_categories WHERE name = :n', ['n' => $a['category']]);
        $no = Seq::next('asset');
        $lineA = ['line_kind' => 'expense', 'account_id' => (int)$cat['asset_account_id'], 'amount' => $a['cost'], 'description' => $a['description']];
        if ($a['funding'] === 'check') {
            $txn = Txn::save('check', ['txn_date' => $a['date'], 'account_id' => $acct('1010'), 'vendor_id' => $a['vendor'], 'memo' => 'Purchase of ' . $a['description']], [$lineA]);
        } else {
            $txn = Txn::save('bill', ['txn_date' => $a['date'], 'vendor_id' => $a['vendor'], 'account_id' => Accts::get('ap'), 'terms_id' => 3,
                'due_date' => date('Y-m-d', strtotime($a['date'] . ' +30 days')), 'ref_no' => $a['ref'] ?? null, 'memo' => 'Purchase of ' . $a['description']], [$lineA]);
        }
        // the Location list (Fixed Assets > Asset Locations)
        $locId = DB::value('SELECT id FROM asset_locations WHERE name = :n', ['n' => $a['location']]) ?: DB::insert('asset_locations', ['name' => $a['location']]);
        $id = DB::insert('fixed_assets', [
            'asset_no' => $no, 'description' => $a['description'], 'category_id' => $cat['id'], 'location' => $a['location'], 'location_id' => (int)$locId, 'vendor_id' => $a['vendor'],
            'acquisition_date' => $a['date'], 'cost' => $a['cost'], 'salvage_value' => $a['salvage'], 'useful_life_years' => $a['life'], 'method' => $a['method'],
            'total_units' => $a['units'] ?? null, 'unit_label' => $a['unit_label'] ?? null, 'acquisition_txn_id' => $txn, 'serial_no' => $a['serial'] ?? null,
        ]);
        Audit::log('create', 'fa', 'asset', $id, $no . ' ' . $a['description'], ['amount' => $a['cost'], 'page_key' => 'fa.register']);
        return $id;
    };
    $start = date('Y-m-01', strtotime('-2 months'));
    $imc = $vend('Industrial Machines Corp.');
    $td = $vend('TechDistributors Inc.');
    $mkAsset(['description' => 'Packaging Machine PM-500', 'category' => 'Machinery and Equipment', 'location' => 'Main Warehouse', 'vendor' => $imc, 'date' => $start,
        'cost' => 500000, 'salvage' => 50000, 'life' => 5, 'method' => 'straight_line', 'funding' => 'check', 'serial' => 'PM500-2231']);
    $mkAsset(['description' => 'Delivery Van (L300)', 'category' => 'Transportation Equipment', 'location' => 'Main Warehouse', 'vendor' => $imc, 'date' => $start,
        'cost' => 1200000, 'salvage' => 200000, 'life' => 5, 'method' => 'declining_balance', 'funding' => 'bill', 'ref' => 'IMC-5521', 'serial' => 'NAB-1234']);
    $mkAsset(['description' => 'POS Terminal Set (Makati)', 'category' => 'Computer and POS Equipment', 'location' => 'Makati Store', 'vendor' => $td, 'date' => $start,
        'cost' => 60000, 'salvage' => 0, 'life' => 3, 'method' => 'syd', 'funding' => 'check']);
    $printer = $mkAsset(['description' => 'Printing Machine PR-300', 'category' => 'Production Machinery (usage-based)', 'location' => 'Main Warehouse', 'vendor' => $imc, 'date' => $start,
        'cost' => 500000, 'salvage' => 50000, 'life' => 5, 'method' => 'units_of_production', 'units' => 300000, 'unit_label' => 'units', 'funding' => 'bill', 'ref' => 'IMC-5530']);

    // preventive maintenance: a few tasks so the reminders can be seen straight away, and one repair on record
    $van = (int)DB::value("SELECT id FROM fixed_assets WHERE description = 'Delivery Van (L300)'");
    $pos = (int)DB::value("SELECT id FROM fixed_assets WHERE description = 'POS Terminal Set (Makati)'");
    DB::update('fixed_assets', ['current_meter' => 45200, 'meter_unit' => 'km'], 'id = :id', ['id' => $van]);
    foreach ([
        [$van, 'Vehicle registration renewal (LTO)', 'registration', 1, 'years', null, date('Y-m-d', strtotime('+20 days')), null, 30, 'Bring the OR/CR and the emission test result'],
        [$van, 'Change oil and oil filter', 'change_oil', 3, 'months', 5000, date('Y-m-d', strtotime('-3 days')), 50000, 7, null],
        [$pos, 'Clean the computers and POS units', 'cleaning', 6, 'months', null, date('Y-m-d', strtotime('+75 days')), null, 14, null],
    ] as [$aid, $task, $kind, $iv, $unit, $miv, $due, $dueMeter, $remind, $note]) {
        DB::insert('asset_pm_schedules', ['asset_id' => $aid, 'task_name' => $task, 'task_type' => $kind, 'interval_value' => $iv, 'interval_unit' => $unit, 'meter_interval' => $miv,
            'next_due_date' => $due, 'next_due_meter' => $dueMeter, 'remind_days' => $remind, 'notes' => $note, 'created_by' => Auth::id()]);
    }
    $repAcct = AssetMaint::defaultExpenseAccount();
    $mDate = date('Y-m-d', strtotime('-20 days'));
    $mRow = ['maint_no' => Seq::next('maintenance'), 'asset_id' => $van, 'maint_date' => $mDate, 'maint_type' => 'repair', 'status' => 'completed', 'problem' => 'Engine overheating on long trips',
        'description' => 'Replaced the radiator assembly and the water pump, flushed the cooling system.', 'vendor_id' => $imc, 'performed_by' => 'IMC Service Center', 'ref_no' => 'IMC-SV-0192',
        'meter_reading' => 45200, 'downtime_days' => 2, 'parts_total' => 11000, 'labor_total' => 1500, 'tax_total' => 0, 'total' => 12500, 'with_vat' => 0, 'payment_mode' => 'bill',
        'parts_account_id' => $repAcct, 'labor_account_id' => $repAcct, 'created_by' => Auth::id()];
    $mId = DB::insert('asset_maintenance', $mRow);
    DB::insert('asset_maintenance_parts', ['maintenance_id' => $mId, 'line_no' => 1, 'part_no' => 'RAD-L300', 'description' => 'Radiator assembly', 'brand' => 'Denso', 'serial_no' => 'DN-778812', 'model_no' => '4D56',
        'size_spec' => '26 mm, 2-row', 'qty' => 1, 'unit' => 'pc', 'unit_cost' => 8500, 'amount' => 8500, 'warranty_until' => date('Y-m-d', strtotime($mDate . ' +1 year'))]);
    DB::insert('asset_maintenance_parts', ['maintenance_id' => $mId, 'line_no' => 2, 'part_no' => 'WP-4D56', 'description' => 'Water pump', 'brand' => 'GMB', 'model_no' => '4D56', 'qty' => 1, 'unit' => 'pc', 'unit_cost' => 2500, 'amount' => 2500]);
    DB::insert('asset_maintenance_labor', ['maintenance_id' => $mId, 'line_no' => 1, 'description' => 'Remove and install radiator and water pump', 'worker' => 'IMC mechanic', 'charge_type' => 'hourly', 'hours' => 6, 'rate' => 150, 'amount' => 900]);
    DB::insert('asset_maintenance_labor', ['maintenance_id' => $mId, 'line_no' => 2, 'description' => 'Cooling system flush', 'charge_type' => 'bulk', 'hours' => 0, 'rate' => 0, 'amount' => 600]);
    DB::update('asset_maintenance', ['txn_id' => AssetMaint::syncTransaction($mRow + ['id' => $mId], null)], 'id = :id', ['id' => $mId]);

    // ---------------------------------------------------------------- purchasing and inventory
    $wh = $site('WH');
    $po1 = Txn::save('purchase_order', ['txn_date' => $d(75), 'vendor_id' => $td, 'site_id' => $wh, 'due_date' => $d(70), 'memo' => 'Initial stock'], $buy([
        $pline('LAP-001', 20), $pline('MON-024', 40), $pline('KEY-001', 60), $pline('MOU-001', 80), $pline('USB-032', 100),
        $pline('BAG-001', 30), $pline('CAB-HDMI', 80), $pline('RAM-8GB', 20), $pline('SSD-512', 20),
    ]));
    $poLines = Txn::lines($po1);
    // receive most items with the bill
    $recvRows = [];
    foreach ($poLines as $l) {
        if (in_array($l['item_code'], ['RAM-8GB', 'SSD-512'], true)) {
            continue;
        }
        $recvRows[] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'tax_code_id' => $vat, 'site_id' => $wh, 'source_line_id' => $l['id']];
    }
    Txn::save('bill', ['txn_date' => $d(72), 'vendor_id' => $td, 'site_id' => $wh, 'ref_no' => 'TD-INV-10231', 'terms_id' => 3, 'due_date' => $d(42), 'account_id' => Accts::get('ap'),
        'memo' => 'Received against ' . Txn::ref(Txn::load($po1))], $buy($recvRows));
    // the rest arrives before the bill
    $irRows = [];
    foreach ($poLines as $l) {
        if (in_array($l['item_code'], ['RAM-8GB', 'SSD-512'], true)) {
            $irRows[] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'site_id' => $wh, 'source_line_id' => $l['id']];
        }
    }
    $ir = Txn::save('item_receipt', ['txn_date' => $d(70), 'vendor_id' => $td, 'site_id' => $wh, 'ref_no' => 'DS-7781', 'account_id' => Accts::get('ap')], $buy($irRows));
    // bill arrives with a slightly different cost on SSDs
    $conv = [];
    foreach (Txn::lines($ir) as $l) {
        $conv[] = ['id' => $l['id'], 'item_id' => $l['item_id'], 'qty' => $l['qty'], 'rate' => $l['item_code'] === 'SSD-512' ? 2250 : $l['rate'], 'tax_code_id' => $vat,
            'site_id' => $l['site_id'], 'source_line_id' => $l['source_line_id']];
    }
    Txn::save('bill', ['txn_date' => $d(66), 'txn_no' => Seq::next('bill'), 'vendor_id' => $td, 'ref_no' => 'TD-INV-10288', 'terms_id' => 3, 'due_date' => $d(36),
        'account_id' => Accts::get('ap'), 'memo' => 'Bill for received items'], $buy($conv), $ir, ['audit_action' => 'convert', 'ledger_change_ok' => true]);
    // open PO for more laptops
    Txn::save('purchase_order', ['txn_date' => $d(5), 'vendor_id' => $td, 'site_id' => $wh, 'due_date' => $d(-7), 'memo' => 'Restock laptops and RAM'], $buy([
        $pline('LAP-001', 10), $pline('RAM-8GB', 15),
    ]));
    // toner (non-inventory) for a customer job
    Txn::save('bill', ['txn_date' => $d(40), 'vendor_id' => $vend('Office Depot PH'), 'ref_no' => 'OD-99812', 'terms_id' => 2, 'due_date' => $d(25), 'account_id' => Accts::get('ap')], $buy([
        $pline('NI-TONER', 4, 2500, null, null),
    ]));

    // bought by the box: 5 boxes of 24 keyboards at 14,400.00 a box, stored as 120 pieces at 600.00
    $boxUnit = (int)DB::value("SELECT u.id FROM uom_units u JOIN uom_sets s ON s.id = u.set_id WHERE s.base_unit = 'pc' AND u.code = 'box' LIMIT 1");
    if ($boxUnit) {
        Txn::save('bill', ['txn_date' => $d(38), 'vendor_id' => $td, 'site_id' => $wh, 'ref_no' => 'TD-INV-10344', 'terms_id' => 3, 'due_date' => $d(8),
            'account_id' => Accts::get('ap'), 'memo' => 'Keyboards bought by the box'], $buy([
                ['item_id' => $item('KEY-001'), 'qty' => 5, 'rate' => 14400, 'uom_id' => $boxUnit, 'site_id' => $wh,
                    'tax_code_id' => $vat, 'description' => 'Keyboard USB (box of 24)'],
            ]));
    }

    // transfers to the stores
    $st1 = $site('ST1');
    $st2 = $site('ST2');
    foreach ([[$st1, 64], [$st2, 63]] as [$to, $ago]) {
        $rows = [];
        foreach (['LAP-001' => 4, 'MON-024' => 6, 'KEY-001' => 10, 'MOU-001' => 20, 'USB-032' => 30, 'BAG-001' => 8, 'CAB-HDMI' => 15] as $code => $q) {
            $rows[] = ['line_kind' => 'item', 'item_id' => $item($code), 'qty' => $q, 'description' => 'Store stock', 'site_id' => $wh];
        }
        Txn::save('inventory_transfer', ['txn_date' => $d($ago), 'site_id' => $wh, 'site2_id' => $to, 'status' => 'open', 'memo' => 'Stock for store'], $rows);
    }

    // assembly builds
    $bom = DB::all('SELECT component_item_id, qty FROM item_components WHERE parent_item_id = :p', ['p' => $item('SET-OFFICE')]);
    $build = function (float $q, string $date, bool $pending) use ($bom, $item, $wh) {
        $lines = [['line_kind' => 'item', 'item_id' => $item('SET-OFFICE'), 'qty' => $q, 'description' => 'Office Monitor Set', 'site_id' => $wh]];
        foreach ($bom as $b) {
            $lines[] = ['line_kind' => 'component', 'item_id' => (int)$b['component_item_id'], 'qty' => (float)$b['qty'] * $q, 'description' => 'Component', 'site_id' => $wh];
        }
        return Txn::save('build_assembly', ['txn_date' => $date, 'site_id' => $wh, 'is_pending' => $pending ? 1 : 0, 'status' => $pending ? 'pending' : 'completed',
            'memo' => $pending ? 'Waiting for monitors' : 'Weekly build'], $lines);
    };
    $build(5, $d(60), false);
    Txn::save('inventory_transfer', ['txn_date' => $d(59), 'site_id' => $wh, 'site2_id' => $st1, 'status' => 'open', 'memo' => 'Display set for Makati store'],
        [['line_kind' => 'item', 'item_id' => $item('SET-OFFICE'), 'qty' => 2, 'description' => 'Office Monitor Set', 'site_id' => $wh]]);
    $build(30, $d(3), true);

    // ---------------------------------------------------------------- disassembly, break-bulk and co-product processing
    Txn::save('bill', ['txn_date' => $d(46), 'vendor_id' => $td, 'site_id' => $wh, 'ref_no' => 'TD-INV-10390', 'terms_id' => 3, 'due_date' => $d(16),
        'account_id' => Accts::get('ap'), 'memo' => 'Bulk USB boxes and trade-in PCs'], $buy([$pline('USB-032-BX50', 3, null, $wh), $pline('TRD-PC', 6, null, $wh)]));
    $process = function (string $source, float $batches, string $date, int $siteId, array $opt = []) {
        if (str_starts_with($source, 'bom:')) {
            [$in, $out, $cs] = Processing::expandBom((int)substr($source, 4), $batches);
            $f = null;
            $type = 'disassembly';
            $method = $opt['method'] ?? 'standard_cost';
        } else {
            $f = Processing::formula(demo_id('process_formulas', 'code', $source));
            [$in, $out, $cs] = Processing::expandFormula((int)$f['id'], $batches);
            $type = $f['process_type'];
            $method = $opt['method'] ?? $f['alloc_method'];
        }
        // actual yield can differ from the plan
        foreach ($out as &$o) {
            $code = (string)DB::value('SELECT code FROM items WHERE id = :id', ['id' => $o['item_id']]);
            if (isset($opt['actual'][$code])) {
                $o['qty'] = $opt['actual'][$code];
            }
        }
        unset($o);
        $pending = !empty($opt['pending']);
        [$lines, $est] = Processing::orderLines($in, $out, $cs, $method);
        return Txn::save(Processing::TXN_TYPE, [
            'txn_date' => $date, 'site_id' => $siteId, 'process_type' => $type, 'alloc_method' => $method,
            'process_formula_id' => $f['id'] ?? null, 'process_batches' => $batches, 'memo' => $opt['memo'] ?? null,
            'account_id' => Processing::isValueMethod($method) ? Accts::get('processing_variance') : null,
            'is_pending' => $pending ? 1 : 0, 'status' => $pending ? 'pending' : 'completed',
            'subtotal' => $est['materials'], 'total' => $est['pool'],
        ], $lines);
    };
    $process('BRK-USB50', 2, $d(40), $wh, ['memo' => 'Opened two boxes for single-unit sales']);
    $process('TD-PC', 3, $d(35), $wh, ['memo' => 'Teardown of trade-ins; one RAM stick failed testing', 'actual' => ['RAM-8GB' => 5, 'SCRAP-KG' => 13]]);
    $process('RFB-PC', 2, $d(25), $wh, ['memo' => 'Refurbished units for the Makati store']);
    $process('bom:' . $item('SET-OFFICE'), 1, $d(15), $st1, ['method' => 'average_cost', 'memo' => 'Display set taken apart; parts back to stock']);
    $process('TD-PC', 2, $d(2), $wh, ['pending' => true, 'memo' => 'Waiting for more trade-in units']);

    // ---------------------------------------------------------------- sales cycle
    $abc = $cust('ABC Trading Corp.');
    $job = $cust('Office Renovation Project');
    $est = Txn::save('estimate', ['txn_date' => $d(58), 'customer_id' => $abc, 'status' => 'pending', 'due_date' => $d(28), 'sales_rep_id' => 1,
        'memo' => 'Office equipment for 5 workstations'], $sales([
        $line('LAP-001', 5), $line('SET-OFFICE', 5), $line('SRV-INSTALL', 5), $line('DEL-FEE', 1),
    ], $abc));
    // estimate -> sales order
    $soRows = [];
    foreach (Txn::lines($est) as $l) {
        $soRows[] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'rate' => $l['rate'], 'tax_code_id' => $l['tax_code_id'], 'source_line_id' => $l['id'], 'description' => $l['description']];
    }
    $so = Txn::save('sales_order', ['txn_date' => $d(56), 'customer_id' => $abc, 'site_id' => $wh, 'sales_rep_id' => 1, 'ship_date' => $d(50), 'ref_no' => 'ABC-PO-7781',
        'billing_address' => '45 Buendia Ave., Makati City'], $sales($soRows, $abc));
    // first delivery: 3 laptops + 3 sets + install
    $inv1Rows = [];
    foreach (Txn::lines($so) as $l) {
        $q = in_array($l['item_code'], ['LAP-001', 'SET-OFFICE', 'SRV-INSTALL'], true) ? 3 : 1;
        $inv1Rows[] = ['item_id' => $l['item_id'], 'qty' => $q, 'rate' => $l['rate'], 'tax_code_id' => $l['tax_code_id'], 'source_line_id' => $l['id'], 'description' => $l['description']];
    }
    $inv1 = $invoice('ABC Trading Corp.', $d(50), $inv1Rows, ['ref_no' => 'ABC-PO-7781']);
    // job invoice with billable freight later
    $freight = Txn::save('bill', ['txn_date' => $d(45), 'vendor_id' => $vend('JRS Express'), 'ref_no' => 'JRS-55012', 'terms_id' => 2, 'due_date' => $d(30), 'account_id' => Accts::get('ap')], [
        ['line_kind' => 'expense', 'account_id' => $acct('6100'), 'amount' => 1800, 'description' => 'Freight - Office Renovation Project', 'tax_code_id' => $vat, 'tax_amount' => 216, 'customer_id' => $job, 'is_billable' => 1],
    ]);
    Txn::save('check', ['txn_date' => $d(44), 'account_id' => $acct('1005'), 'payee_name' => 'Hardware Depot', 'memo' => 'Cables and conduits for job'], [
        ['line_kind' => 'expense', 'account_id' => $acct('6900'), 'amount' => 950, 'description' => 'Conduits - Office Renovation Project', 'customer_id' => $job, 'is_billable' => 1],
    ]);
    $invoice('Office Renovation Project', $d(20), [$line('SRV-INSTALL', 2), $line('SRV-REPAIR', 4)]);
    $inv2Rows = [];
    foreach (Txn::lines($so) as $l) {
        $rem = (float)$l['qty'] - (float)$l['qty_fulfilled'];
        if ($rem > 0) {
            $inv2Rows[] = ['item_id' => $l['item_id'], 'qty' => $rem, 'rate' => $l['rate'], 'tax_code_id' => $l['tax_code_id'], 'source_line_id' => $l['id'], 'description' => $l['description']];
        }
    }
    // second part stays on the sales order (backordered) - invoice only laptops now
    $inv2Rows = array_values(array_filter($inv2Rows, fn($r) => $r['item_id'] === $item('LAP-001')));
    $inv2 = $invoice('ABC Trading Corp.', $d(12), $inv2Rows, ['ref_no' => 'ABC-PO-7781']);

    $metro = $cust('Metro School Supplies');
    $invM = $invoice('Metro School Supplies', $d(48), [$line('USB-032', 40, 332.5), $line('KEY-001', 15, 949.05), $line('MOU-001', 15, 569.05), $line('SUBTOTAL', 0), $line('DISC-10', 0)]);
    $invM2 = $invoice('Metro School Supplies', $d(35), [$line('BAG-001', 10, 854.05), $line('CAB-HDMI', 20, 189.05)]);
    $cebu = $cust('Cebu Tech Hub');
    $invC = $invoice('Cebu Tech Hub', $d(30), [$line('LAP-001', 2, 36500), $line('RAM-8GB', 4, 1521), $line('SSD-512', 2, 2961), $line('SRV-INSTALL', 1, 1350)]);
    $green = $cust('Green Valley Clinic');
    $invG = $invoice('Green Valley Clinic', $d(75), [$line('SRV-MAINT', 1), $line('NI-TONER', 2)]);
    $bright = $cust('Bright Minds Academy');
    foreach ([62, 31] as $ago) {
        $batch = 'B' . date('Ymd', strtotime("-$ago days")) . '0900';
        foreach (['Bright Minds Academy', 'Green Valley Clinic'] as $cn) {
            $invoice($cn, $d($ago), [$line('SRV-MAINT', 1)], ['batch_ref' => $batch, 'memo' => 'Monthly IT maintenance']);
        }
    }
    // pending sale
    $invoice('Cebu Tech Hub', $d(2), [$line('MON-024', 4, 8091)], ['is_pending' => 1, 'memo' => 'Awaiting PO approval']);
    // cash sale from the back office
    Txn::save('sales_receipt', ['txn_date' => $d(40), 'customer_id' => $cust('Juan Dela Cruz'), 'account_id' => $acct('1050'), 'payment_method_id' => $pm('Cash'),
        'site_id' => $wh, 'memo' => 'Phone order'], $sales([$line('LAP-001', 1), $line('BAG-001', 1), $line('MOU-001', 1)], $cust('Juan Dela Cruz')));

    // sold by the dozen, with a flat amount off instead of a percentage
    $dzUnit = (int)DB::value("SELECT u.id FROM uom_units u JOIN uom_sets s ON s.id = u.set_id WHERE s.base_unit = 'pc' AND u.code = 'dz' LIMIT 1");
    if ($dzUnit) {
        $invDz = $invoice('Metro School Supplies', $d(20), [[
            'item_id' => $item('KEY-001'), 'qty' => 3, 'rate' => 11000, 'uom_id' => $dzUnit, 'site_id' => $wh,
            'tax_code_id' => $vat, 'description' => 'Keyboard USB (sold by the dozen)',
            'discount_kind' => 'amt', 'discount_val' => 1500,
        ]], ['memo' => '3 dozen keyboards, 1,500.00 off']);
    }

    // payments
    $p1 = $pay($abc, $d(25), 100000, [['target_txn_id' => $inv1, 'amount' => 100000, 'discount' => 0, 'withholding' => 1500]],
        ['payment_method_id' => $pm('Check'), 'ref_no' => 'BDO-004412']);
    $cOpen = Balances::open(Txn::load($invC));
    $pay($cebu, $d(22), r2($cOpen - r2((float)Txn::load($invC)['total'] * 0.02)), [['target_txn_id' => $invC, 'amount' => r2($cOpen - r2((float)Txn::load($invC)['total'] * 0.02)), 'discount' => r2((float)Txn::load($invC)['total'] * 0.02), 'withholding' => 0]],
        ['payment_method_id' => $pm('Bank Transfer'), 'ref_no' => 'IB-88123', 'account_id' => $acct('1010')]);
    $mOpen = Balances::open(Txn::load($invM));
    $pay($metro, $d(28), $mOpen + 500, [['target_txn_id' => $invM, 'amount' => $mOpen, 'discount' => 0, 'withholding' => 0]], ['payment_method_id' => $pm('Check'), 'ref_no' => 'MBTC-1120']);
    $gOld = Balances::open(Txn::load($invG));
    $pay($green, $d(60), round($gOld / 2, 2), [['target_txn_id' => $invG, 'amount' => round($gOld / 2, 2), 'discount' => 0, 'withholding' => 0]], ['payment_method_id' => $pm('GCash')]);

    // a government buyer (the guide's example D): 5% VAT and 1% income tax withheld on the VAT-exclusive amount
    $wtRow = function (string $code, float $base, string $side) {
        $w = DB::row('SELECT * FROM withholding_taxes WHERE code = :c', ['c' => $code]);
        return ['wht_id' => (int)$w['id'], 'tax_kind' => $w['tax_kind'], 'atc' => $w['atc'], 'rate' => (float)$w['rate'], 'base' => r2($base),
            'amount' => r2($base * (float)$w['rate'] / 100), 'account_id' => Withholding::account($w, $side)];
    };
    $gov = $cust('City Government of Makati');
    $invGov = $invoice('City Government of Makati', $d(34), [$line('MON-024', 10)], ['memo' => 'Monitors for the city hall offices']);
    $govInv = Txn::load($invGov);
    $govW = [$wtRow('WT-VAT', (float)$govInv['subtotal'], 'sales'), $wtRow('WT-GOODS', (float)$govInv['subtotal'], 'sales')];
    $govWht = r2(array_sum(array_column($govW, 'amount')));
    Txn::save('payment', ['txn_date' => $d(12), 'customer_id' => $gov, 'account_id' => $acct('1050'), 'account2_id' => Accts::get('ar'), 'payment_method_id' => $pm('Check'),
        'ref_no' => 'LBP-205511', 'total' => r2((float)$govInv['total'] - $govWht), 'withholding_amount' => $govWht, 'memo' => '5% VAT and 1% withheld; BIR Form 2307 to follow'],
        [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $invGov, 'amount' => r2((float)$govInv['total'] - $govWht), 'withholding' => $govWht]],
            'withholdings' => [$invGov => $govW]]);

    // credit memo (return) - keep as credit
    Txn::save('credit_memo', ['txn_date' => $d(27), 'customer_id' => $metro, 'account_id' => Accts::get('ar'), 'restock' => 1, 'site_id' => $wh,
        'memo' => 'Returned 2 defective keyboards', 'credit_disposition' => 'keep'], $sales([$line('KEY-001', 2, 949.05)], $metro));

    // statement charge and finance charge
    Txn::save('statement_charge', ['txn_date' => $d(15), 'due_date' => $d(15), 'customer_id' => $green, 'account_id' => Accts::get('ar'), 'memo' => 'Late payment fee'],
        $sales([$line('LATE-FEE', 1)], $green));
    Txn::save('finance_charge', ['txn_date' => $d(1), 'due_date' => $d(1), 'customer_id' => $green, 'account_id' => Accts::get('ar'), 'memo' => 'Finance charge on overdue balance'], [
        ['line_kind' => 'item', 'account_id' => Accts::get('finance_charge_income'), 'description' => 'Finance charge (12% p.a.)', 'qty' => 0, 'rate' => 0, 'amount' => 120.50, 'tax_amount' => 0],
    ]);

    // ---------------------------------------------------------------- expenses and payables
    foreach ([74, 44, 14] as $ago) {
        Txn::save('check', ['txn_date' => $d($ago), 'account_id' => $acct('1010'), 'vendor_id' => $vend('Metro Property Leasing'), 'memo' => 'Office and warehouse rent'], [
            $exp('6010', 85000, 'Monthly rent', $vat),
        ] + [0 => array_merge($exp('6010', 85000, 'Monthly rent', $vat), ['line_kind' => 'expense', 'tax_amount' => 10200])]);
    }
    $meralco = $vend('Meralco');
    $elec = [];
    foreach ([[60, 18450.75], [30, 21230.40]] as [$ago, $amt]) {
        $elec[] = Txn::save('bill', ['txn_date' => $d($ago), 'vendor_id' => $meralco, 'ref_no' => 'MER-' . date('Ym', strtotime("-$ago days")), 'terms_id' => 1, 'due_date' => $d($ago - 10),
            'account_id' => Accts::get('ap')], [array_merge($exp('6020', $amt, 'Electricity', $vat), ['line_kind' => 'expense', 'tax_amount' => r2($amt * 0.12)])]);
    }
    $prof = Txn::save('bill', ['txn_date' => $d(55), 'vendor_id' => $vend('Accounting Partners & Co.'), 'ref_no' => 'APC-2231', 'terms_id' => 3, 'due_date' => $d(25),
        'account_id' => Accts::get('ap'), 'memo' => 'Quarterly bookkeeping review'], [array_merge($exp('6070', 45000, 'Professional fees', $vat), ['line_kind' => 'expense', 'tax_amount' => 5400])]);
    Txn::save('check', ['txn_date' => $d(33), 'account_id' => $acct('1005'), 'vendor_id' => $vend('Petron Service Station'), 'memo' => 'Fuel for delivery van'], [
        array_merge($exp('6050', 3500, 'Diesel', $vat), ['line_kind' => 'expense', 'tax_amount' => 420]),
    ]);
    // vendor credit for a damaged mouse
    Txn::save('vendor_credit', ['txn_date' => $d(46), 'vendor_id' => $td, 'ref_no' => 'TD-CM-331', 'account_id' => Accts::get('ap'), 'site_id' => $wh, 'memo' => 'Returned damaged mice'],
        $buy([$pline('MOU-001', 2)]));
    // pay bills
    $tdBill = (int)DB::value("SELECT id FROM transactions WHERE txn_type = 'bill' AND ref_no = 'TD-INV-10231'");
    $tdOpen = Balances::open(Txn::load($tdBill));
    $vc = Balances::vendorCredits($td);
    foreach ($vc as $c) {
        DB::insert('payment_applications', ['payment_txn_id' => $c['id'], 'target_txn_id' => $tdBill, 'amount' => $c['remaining']]);
        Balances::refreshApplications((int)$c['id']);
        $tdOpen -= $c['remaining'];
    }
    Txn::save('bill_payment', ['txn_date' => $d(40), 'vendor_id' => $td, 'account_id' => $acct('1010'), 'account2_id' => Accts::get('ap'), 'payment_method_id' => $pm('Check'),
        'total' => r2($tdOpen), 'memo' => 'Payment of TD-INV-10231'], [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $tdBill, 'amount' => r2($tdOpen)]]]);
    $eOpen = Balances::open(Txn::load($elec[0]));
    Txn::save('bill_payment', ['txn_date' => $d(52), 'vendor_id' => $meralco, 'account_id' => $acct('1010'), 'account2_id' => Accts::get('ap'), 'payment_method_id' => $pm('Bank Transfer'),
        'ref_no' => 'IB-77100', 'total' => $eOpen, 'memo' => 'Electricity'], [], null, ['keep_totals' => true, 'applications' => [['target_txn_id' => $elec[0], 'amount' => $eOpen]]]);
    // the bookkeeping fee paid less 2% withheld on the fee net of VAT (BIR Form 2307 for Accounting Partners)
    $profBill = Txn::load($prof);
    $profW = [$wtRow('WT-SERVICES', (float)$profBill['subtotal'], 'purchases')];
    $profWht = r2(array_sum(array_column($profW, 'amount')));
    Txn::save('bill_payment', ['txn_date' => $d(20), 'vendor_id' => $profBill['vendor_id'], 'account_id' => $acct('1010'), 'account2_id' => Accts::get('ap'), 'payment_method_id' => $pm('Check'),
        'total' => r2((float)$profBill['total'] - $profWht), 'withholding_amount' => $profWht, 'memo' => 'Payment of APC-2231, 2% withheld'], [], null,
        ['keep_totals' => true, 'applications' => [['target_txn_id' => $prof, 'amount' => r2((float)$profBill['total'] - $profWht), 'withholding' => $profWht]], 'withholdings' => [$prof => $profW]]);
    // an advance paid to a supplier before the goods come (Vendors > Advances to Suppliers)
    Txn::save('vendor_advance', ['txn_date' => $d(14), 'vendor_id' => $imc, 'account_id' => $acct('1010'), 'account2_id' => Accts::get('vendor_advances'),
        'payment_method_id' => $pm('Check'), 'ref_no' => '1188', 'due_date' => $d(-20), 'total' => 150000, 'subtotal' => 150000, 'status' => 'open',
        'memo' => '30% down payment on the packaging machine'], [], null, ['keep_totals' => true]);
    // voided check (audit example)
    $bad = Txn::save('check', ['txn_date' => $d(10), 'account_id' => $acct('1010'), 'payee_name' => 'Office Snacks Co.', 'memo' => 'Pantry supplies'], [
        array_merge($exp('6900', 2500, 'Pantry supplies'), ['line_kind' => 'expense', 'tax_amount' => 0]),
    ]);
    Txn::void($bad, 'Check printed with wrong payee');
    // deleted estimate (audit example)
    $tmpEst = Txn::save('estimate', ['txn_date' => $d(9), 'customer_id' => $cebu, 'status' => 'pending', 'memo' => 'Duplicate quote'], $sales([$line('MON-024', 10)], $cebu));
    Txn::delete($tmpEst, 'Duplicate of an existing quotation');

    // ---------------------------------------------------------------- inventory adjustments
    Txn::save('inventory_adjustment', ['txn_date' => $d(24), 'site_id' => $wh, 'account_id' => Accts::get('inventory_adjustment'), 'memo' => 'Damage - water leak', 'status' => 'open'], [
        ['line_kind' => 'item', 'item_id' => $item('BAG-001'), 'qty' => -2, 'description' => 'Damage', 'site_id' => $wh],
    ], null, ['reason' => 'Damage']);
    Txn::save('inventory_adjustment', ['txn_date' => $d(8), 'site_id' => $st1, 'account_id' => Accts::get('inventory_adjustment'), 'memo' => 'Physical count by D. Villanueva', 'status' => 'open'], [
        ['line_kind' => 'item', 'item_id' => $item('USB-032'), 'qty' => -1, 'description' => 'Count difference (counted 29, system 30)', 'site_id' => $st1],
        ['line_kind' => 'item', 'item_id' => $item('CAB-HDMI'), 'qty' => 1, 'description' => 'Count difference (counted 16, system 15)', 'site_id' => $st1],
    ], null, ['reason' => 'Physical inventory count']);

    // ---------------------------------------------------------------- banking
    Txn::save('transfer', ['txn_date' => $d(45), 'account_id' => $acct('1010'), 'account2_id' => $acct('1005'), 'total' => 10000, 'memo' => 'Replenish petty cash', 'status' => 'open'], [], null, ['keep_totals' => true]);
    // cash vouchers: petty cash and revolving fund payments (the cash account is credited for the total)
    $emp = demo_id('other_names', 'name', 'Dan Villanueva');
    Txn::save('cash_voucher', ['txn_date' => $d(30), 'account_id' => $acct('1005'), 'other_name_id' => $emp, 'memo' => 'Packing tape and markers for the warehouse',
        'class_id' => $class['Head Office'], 'subtotal' => 1250, 'total' => 1250, 'status' => 'open'], [
        ['line_kind' => 'journal', 'account_id' => $acct('6040'), 'amount' => 1250, 'description' => 'Packing supplies', 'other_name_id' => $emp],
    ], null, ['keep_totals' => true]);
    Txn::save('cash_voucher', ['txn_date' => $d(12), 'account_id' => $acct('1005'), 'payee_name' => 'Jeepney and tricycle fares', 'memo' => 'Delivery fares, Makati store',
        'class_id' => $class['Makati Store'], 'subtotal' => 480, 'total' => 480, 'status' => 'open'], [
        ['line_kind' => 'journal', 'account_id' => $acct('6100'), 'amount' => 480, 'description' => 'Fares for small deliveries'],
    ], null, ['keep_totals' => true]);
    $uf = Accts::get('undeposited_funds');
    $toDeposit = DB::all("SELECT * FROM transactions WHERE txn_type IN ('payment','sales_receipt') AND account_id = :uf AND is_voided = 0 AND txn_date <= :d", ['uf' => $uf, 'd' => $d(24)]);
    $depLines = array_map(fn($t) => ['line_kind' => 'deposit', 'account_id' => $uf, 'amount' => (float)$t['total'], 'ref_txn_id' => $t['id'], 'customer_id' => $t['customer_id'],
        'description' => Txn::ref($t)], $toDeposit);
    $depLines[] = ['line_kind' => 'deposit', 'account_id' => $acct('4200'), 'amount' => 1250.00, 'description' => 'Interest on time deposit'];
    Txn::save('deposit', ['txn_date' => $d(24), 'account_id' => $acct('1010'), 'ref_no' => 'DS-1001', 'memo' => 'Deposit', 'status' => 'open'], $depLines);

    // ---------------------------------------------------------------- depreciation (two months)
    for ($i = 2; $i >= 1; $i--) {
        $y = (int)date('Y', strtotime("first day of -$i month"));
        $m = (int)date('n', strtotime("first day of -$i month"));
        DB::insert('asset_meter_readings', ['asset_id' => $printer, 'period_year' => $y, 'period_month' => $m, 'units' => $i === 2 ? 4200 : 5100, 'entered_by' => $admin]);
        Depreciation::run($y, $m);
    }

    // ---------------------------------------------------------------- bank reconciliation (first month)
    $bdo = $acct('1010');
    $recEnd = date('Y-m-t', strtotime('first day of -2 month'));
    $items = DB::all("SELECT j.txn_id, SUM(j.debit - j.credit) AS amt FROM journal_lines j WHERE j.account_id = :a AND j.txn_date <= :d GROUP BY j.txn_id", ['a' => $bdo, 'd' => $recEnd]);
    $cleared = 0.0;
    $rid = DB::insert('bank_reconciliations', ['account_id' => $bdo, 'statement_date' => $recEnd, 'beginning_balance' => 0, 'created_by' => $admin, 'status' => 'in_progress']);
    foreach ($items as $it) {
        $t = Txn::load((int)$it['txn_id']);
        $isCleared = !($t['txn_type'] === 'check' && $t['txn_date'] > date('Y-m-d', strtotime("$recEnd -5 days")));
        DB::insert('reconciliation_items', ['reconciliation_id' => $rid, 'txn_id' => $it['txn_id'], 'amount' => r2($it['amt']), 'cleared' => $isCleared ? 1 : 0]);
        if ($isCleared) {
            DB::run('UPDATE journal_lines SET cleared = 1, reconciliation_id = :r WHERE txn_id = :t AND account_id = :a', ['r' => $rid, 't' => $it['txn_id'], 'a' => $bdo]);
            $cleared += (float)$it['amt'];
        }
    }
    $svc = Txn::save('bank_charge', ['txn_date' => $recEnd, 'account_id' => $bdo, 'memo' => 'Bank service charge'], [
        ['line_kind' => 'journal', 'account_id' => $acct('6080'), 'amount' => 150, 'description' => 'Service charge'],
        ['line_kind' => 'journal', 'account_id' => $bdo, 'amount' => -150, 'description' => 'Service charge'],
    ]);
    DB::run('UPDATE journal_lines SET cleared = 1, reconciliation_id = :r WHERE txn_id = :t AND account_id = :a', ['r' => $rid, 't' => $svc, 'a' => $bdo]);
    DB::insert('reconciliation_items', ['reconciliation_id' => $rid, 'txn_id' => $svc, 'amount' => -150, 'cleared' => 1]);
    $cleared -= 150;
    DB::update('bank_reconciliations', ['ending_balance' => r2($cleared), 'cleared_balance' => r2($cleared), 'difference' => 0, 'status' => 'completed',
        'service_charge_txn_id' => $svc, 'completed_by' => $admin, 'completed_at' => date('Y-m-d H:i:s', strtotime("$recEnd +5 days"))], 'id = :id', ['id' => $rid]);
    Audit::log('reconcile', 'bank', 'reconciliation', $rid, 'BDO Checking, ' . fdate($recEnd), ['amount' => $cleared, 'page_key' => 'bank.reconcile']);

    // ---------------------------------------------------------------- point of sale
    $term = (int)DB::value("SELECT id FROM pos_terminals WHERE code = 'POS-01'");
    $cashier = $user('cashier1');
    $super = $user('supervisor');
    $posSale = function (int $shiftId, string $date, array $rows, array $tenders, ?int $customer = null, float $tendered = 0) use ($term, $st1, $sales, $class) {
        $lines = $sales($rows, $customer);
        foreach ($lines as &$l) {
            $l['site_id'] = $st1;
        }
        unset($l);
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l['amount'] + $l['tax_amount'];
        }
        $total = r2($total);
        $base = ['txn_date' => $date, 'site_id' => $st1, 'terminal_id' => $term, 'pos_shift_id' => $shiftId, 'customer_id' => $customer, 'class_id' => $class['Makati Store']];
        $receipt = Pos::nextReceipt($term);
        $sid = Txn::save('pos_sale', $base + ['status' => 'suspended', 'receipt_no' => $receipt], $lines, null, ['audit_action' => 'start']);
        $left = $total;
        $cashPm = null;
        foreach ($tenders as [$pmId, $amt]) {
            if ($amt === 'cash') {
                $cashPm = $pmId;
                continue;
            }
            if ($amt === 'full') {
                $amt = r2($left);
            }
            DB::insert('pos_tenders', ['txn_id' => $sid, 'payment_method_id' => $pmId, 'amount' => $amt, 'reference' => 'APR' . random_int(1000, 9999)]);
            $left -= $amt;
        }
        if ($cashPm && $left > 0) {
            DB::insert('pos_tenders', ['txn_id' => $sid, 'payment_method_id' => $cashPm, 'amount' => r2($left)]);
        }
        $tend = $tendered ?: $total;
        return Txn::save('pos_sale', $base + ['status' => 'completed', 'receipt_no' => $receipt, 'amount_tendered' => $tend, 'change_given' => r2(max(0, $tend - $total))],
            $lines, $sid, ['audit_action' => 'sale']);
    };
    // yesterday's closed shift
    Auth::actAs($cashier);
    $_SESSION['pos_terminal_id'] = $term;
    $yday = $d(1);
    $shift1 = DB::insert('pos_shifts', ['terminal_id' => $term, 'cashier_id' => $cashier, 'business_date' => $yday, 'opening_float' => 2000, 'opened_at' => $yday . ' 08:55:00']);
    Audit::log('shift_open', 'pos', 'shift', $shift1, 'Makati Counter 1', ['amount' => 2000]);
    $cash = $pm('Cash');
    $s1 = $posSale($shift1, $yday, [$line('MOU-001', 2), $line('USB-032', 3)], [[$cash, 'cash']], null, 3000);
    $posSale($shift1, $yday, [$line('LAP-001', 1, null, 5), $line('BAG-001', 1)], [[$pm('Credit/Debit Card'), 'full']]);
    $posSale($shift1, $yday, [$line('KEY-001', 1), $line('CAB-HDMI', 2)], [[$pm('GCash'), 'full']]);
    $s4 = $posSale($shift1, $yday, [$line('MON-024', 1)], [[$cash, 'cash']], null, 10100);
    $posSale($shift1, $yday, [$line('USB-032', 2)], [[$cash, 'cash']], null, 1000);
    DB::insert('pos_cash_drops', ['shift_id' => $shift1, 'amount' => 2000, 'reason' => 'Excess cash to safe', 'created_by' => $cashier]);
    Audit::log('cash_drop', 'pos', 'shift', $shift1, 'Cash drop', ['amount' => 2000, 'terminal_id' => $term]);
    // supervisor voids a sale and processes a return
    Auth::actAs($super);
    Txn::void($s4, 'Customer changed mind before leaving the counter', DB::row('SELECT * FROM users WHERE id = :id', ['id' => $super]));
    $saleLines = Txn::lines($s1);
    $ret = Txn::save('pos_return', ['txn_date' => $yday, 'site_id' => $st1, 'restock' => 1, 'terminal_id' => $term, 'pos_shift_id' => $shift1, 'source_txn_id' => $s1,
        'status' => 'suspended', 'receipt_no' => Pos::nextReceipt($term), 'memo' => 'Return: defective mouse', 'approved_by' => $super], [[
        'line_kind' => 'item', 'item_id' => $saleLines[0]['item_id'], 'description' => $saleLines[0]['description'], 'qty' => 1, 'rate' => $saleLines[0]['rate'],
        'amount' => (float)$saleLines[0]['rate'], 'tax_code_id' => $vat, 'tax_amount' => r2((float)$saleLines[0]['rate'] * 0.12), 'site_id' => $st1, 'source_line_id' => $saleLines[0]['id'],
    ]], null, ['audit_action' => 'start', 'reason' => 'Defective mouse']);
    DB::insert('pos_tenders', ['txn_id' => $ret, 'payment_method_id' => $cash, 'amount' => r2((float)$saleLines[0]['rate'] * 1.12), 'reference' => 'Refund']);
    $rl = array_map(fn($l) => array_intersect_key($l, array_flip(['id', 'line_kind', 'item_id', 'description', 'qty', 'rate', 'discount_pct', 'discount_amount', 'amount', 'tax_code_id', 'tax_amount', 'site_id', 'source_line_id'])), Txn::lines($ret));
    Txn::save('pos_return', ['txn_date' => $yday, 'status' => 'completed'], $rl, $ret, ['audit_action' => 'refund', 'reason' => 'Defective mouse', 'approved_by' => 'supervisor']);
    Pos::saveReading('X', $shift1);
    // close with a small shortage
    $sum = Pos::summary($shift1);
    $counted = r2($sum['expected_cash'] - 20);
    $os = Txn::save('pos_over_short', ['txn_date' => $yday, 'terminal_id' => $term, 'pos_shift_id' => $shift1, 'site_id' => $st1, 'memo' => 'Cash short - Makati Counter 1'], [
        ['line_kind' => 'journal', 'account_id' => $acct('1001'), 'amount' => -20, 'description' => 'Drawer count difference'],
        ['line_kind' => 'journal', 'account_id' => Accts::get('cash_over_short'), 'amount' => 20, 'description' => 'Cash over and short'],
    ]);
    Pos::saveReading('Z', $shift1, $counted);
    DB::update('pos_shifts', ['status' => 'closed', 'closed_at' => $yday . ' 21:05:00', 'closed_by' => $super, 'expected_cash' => $sum['expected_cash'],
        'counted_cash' => $counted, 'over_short' => -20, 'over_short_txn_id' => $os], 'id = :id', ['id' => $shift1]);
    Audit::log('shift_close', 'pos', 'shift', $shift1, 'Z-reading', ['amount' => $counted, 'approved_by' => 'supervisor', 'reason' => 'Short by 20.00']);
    // today's shift stays open
    Auth::actAs($cashier);
    $shift2 = DB::insert('pos_shifts', ['terminal_id' => $term, 'cashier_id' => $cashier, 'business_date' => today(), 'opening_float' => 2000]);
    Audit::log('shift_open', 'pos', 'shift', $shift2, 'Makati Counter 1', ['amount' => 2000]);
    $posSale($shift2, today(), [$line('USB-032', 1), $line('CAB-HDMI', 1)], [[$cash, 'cash']], null, 1000);
    $posSale($shift2, today(), [$line('SET-OFFICE', 1)], [[$pm('Maya'), 'full']]);
    // attempts that the audit trail records
    Access::reset();
    Audit::log('access_denied', 'security', 'page', null, 'User Role Configuration (open)', ['page_key' => 'admin.roles']);
    unset($_SESSION['pos_terminal_id']);

    // ---------------------------------------------------------------- security examples
    Auth::actAs($admin);
    $salesrep = $user('salesrep');
    $arclerk = $user('arclerk');
    DB::insert('user_page_overrides', ['user_id' => $salesrep, 'page_id' => demo_id('pages', 'page_key', 'rpt.customer_balance_summary'), 'allowed' => 1, 'can_print' => 1,
        'reason' => 'Needs balances for account visits', 'created_by' => $admin]);
    DB::insert('user_page_overrides', ['user_id' => $arclerk, 'page_id' => demo_id('pages', 'page_key', 'cust.finance_charges'), 'allowed' => 0,
        'reason' => 'Finance charges are assessed by the accountant', 'created_by' => $admin]);
    Audit::log('page_access_override', 'admin', 'user', $salesrep, 'User: salesrep', ['changes' => [['field' => 'Customer Balance Summary', 'old' => 'Inherit from role', 'new' => 'Override: allowed [P]']], 'reason' => 'Needs balances for account visits']);
    Audit::log('page_access_override', 'admin', 'user', $arclerk, 'User: arclerk', ['changes' => [['field' => 'Assess Finance Charges', 'old' => 'Inherit from role', 'new' => 'Override: denied']], 'reason' => 'Finance charges are assessed by the accountant']);
    foreach (['wrongpass1', 'wrongpass2'] as $bad) {
        Auth::attempt('apclerk', $bad);
    }
    DB::insert('entity_notes', ['entity_type' => 'customer', 'entity_id' => $green, 'note' => 'Call Dr. Tan about the overdue balance', 'is_todo' => 1, 'due_date' => $d(-2), 'created_by' => $admin]);
    DB::insert('entity_notes', ['entity_type' => 'vendor', 'entity_id' => $td, 'note' => 'Negotiate volume discount on laptops', 'is_todo' => 1, 'due_date' => $d(-10), 'created_by' => $admin]);
    DB::insert('receipt_documents', ['file_path' => 'sample_petron.txt', 'original_name' => 'Petron_' . $d(3) . '_2150.00.txt', 'mime_type' => 'text/plain', 'source' => 'email',
        'vendor_id' => $vend('Petron Service Station'), 'receipt_date' => $d(3), 'amount' => 2150.00, 'account_id' => $acct('6050'), 'uploaded_by' => $admin]);

    // books closed through the opening balance date
    Settings::set('closing_date', $d(80));
    Audit::log('closing_date_change', 'admin', 'settings', null, 'Closing date', ['changes' => [['field' => 'closing_date', 'old' => '', 'new' => $d(80)]], 'reason' => 'Opening balances finalized']);
}
