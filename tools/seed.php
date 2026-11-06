<?php
declare(strict_types=1);

/**
 * Master data: modules, pages, standard roles and their access, demo users,
 * chart of accounts, default accounts, lists, sites, items, customers, vendors,
 * asset categories, POS terminals and number sequences.
 */
function seed_master_data(): void
{
    $reg = require APP_ROOT . '/app/registry.php';

    // ---------------------------------------------------------------- settings
    $settings = Settings::DEFAULTS;
    $settings['company_name'] = 'Sample Trading Company';
    $settings['company_address'] = '123 Rizal Avenue, Makati City, Metro Manila';
    $settings['company_tin'] = '000-123-456-000';
    $settings['company_phone'] = '(02) 8123-4567';
    $settings['company_email'] = 'accounting@sampletrading.test';
    foreach ($settings as $k => $v) {
        DB::insert('settings', ['setting_key' => $k, 'setting_value' => $v]);
    }

    // ---------------------------------------------------------------- modules & pages
    $moduleIds = [];
    foreach ($reg['modules'] as $code => [$name, $icon, $sort]) {
        $moduleIds[$code] = DB::insert('modules', ['code' => $code, 'name' => $name, 'icon' => $icon, 'sort_order' => $sort]);
    }
    $pageIds = [];
    $sort = 0;
    foreach ($reg['pages'] as $key => [$module, $name, $type, $group, $file, $params, $menu]) {
        $pageIds[$key] = DB::insert('pages', [
            'page_key' => $key, 'module_id' => $moduleIds[$module], 'name' => $name, 'page_type' => $type,
            'menu_group' => $group, 'sort_order' => ++$sort * 10, 'show_in_menu' => $menu,
        ]);
    }

    // ---------------------------------------------------------------- chart of accounts
    $typeInfo = [
        'Bank' => ['Asset', 'Debit'], 'Accounts Receivable' => ['Asset', 'Debit'], 'Other Current Asset' => ['Asset', 'Debit'],
        'Fixed Asset' => ['Asset', 'Debit'], 'Other Asset' => ['Asset', 'Debit'], 'Accounts Payable' => ['Liability', 'Credit'],
        'Credit Card' => ['Liability', 'Credit'], 'Other Current Liability' => ['Liability', 'Credit'], 'Long Term Liability' => ['Liability', 'Credit'],
        'Equity' => ['Equity', 'Credit'], 'Income' => ['Income', 'Credit'], 'Other Income' => ['Income', 'Credit'],
        'Cost of Goods Sold' => ['Expense', 'Debit'], 'Expense' => ['Expense', 'Debit'], 'Other Expense' => ['Expense', 'Debit'],
    ];
    // code, name, type, normal balance override, parent code
    $coa = [
        ['1000', 'Cash', 'Bank', null, null],
        ['1001', 'Cash on Hand - Store Registers', 'Bank', null, '1000'],
        ['1005', 'Petty Cash Fund', 'Bank', null, '1000'],
        ['1006', 'Revolving Fund', 'Bank', null, '1000'],
        ['1010', 'Cash in Bank - BDO Checking', 'Bank', null, '1000'],
        ['1020', 'Cash in Bank - BPI Savings', 'Bank', null, '1000'],
        ['1050', 'Undeposited Funds', 'Other Current Asset', null, null],
        ['1060', 'Card Clearing', 'Other Current Asset', null, null],
        ['1065', 'E-wallet Clearing', 'Other Current Asset', null, null],
        ['1070', 'P.O. Card Clearing', 'Other Current Asset', null, null],
        ['1100', 'Accounts Receivable', 'Accounts Receivable', null, null],
        ['1150', 'Allowance for Doubtful Accounts', 'Other Current Asset', 'Credit', null],
        ['1200', 'Inventory', 'Other Current Asset', null, null],
        ['1300', 'Input Tax', 'Other Current Asset', null, null],
        ['1310', 'Creditable Withholding Tax', 'Other Current Asset', null, null],
        ['1315', 'Creditable VAT Withheld', 'Other Current Asset', null, null],
        ['1400', 'Prepaid Expenses', 'Other Current Asset', null, null],
        ['1410', 'Advances to Suppliers', 'Other Current Asset', null, null],
        ['1500', 'Land', 'Fixed Asset', null, null],
        ['1510', 'Building', 'Fixed Asset', null, null],
        ['1515', 'Accumulated Depreciation - Building', 'Fixed Asset', 'Credit', null],
        ['1520', 'Machinery and Equipment', 'Fixed Asset', null, null],
        ['1525', 'Accumulated Depreciation - Machinery', 'Fixed Asset', 'Credit', null],
        ['1530', 'Transportation Equipment', 'Fixed Asset', null, null],
        ['1535', 'Accumulated Depreciation - Transportation', 'Fixed Asset', 'Credit', null],
        ['1540', 'Furniture and Fixtures', 'Fixed Asset', null, null],
        ['1545', 'Accumulated Depreciation - Furniture', 'Fixed Asset', 'Credit', null],
        ['1550', 'Computer and POS Equipment', 'Fixed Asset', null, null],
        ['1555', 'Accumulated Depreciation - Computer and POS', 'Fixed Asset', 'Credit', null],
        ['1600', 'Security Deposits', 'Other Asset', null, null],
        ['2000', 'Accounts Payable', 'Accounts Payable', null, null],
        ['2050', 'Company Credit Card', 'Credit Card', null, null],
        ['2100', 'Output Tax', 'Other Current Liability', null, null],
        ['2110', 'Withholding Tax Payable', 'Other Current Liability', null, null],
        ['2115', 'VAT Withheld Payable', 'Other Current Liability', null, null],
        ['2120', 'Gift Vouchers Outstanding', 'Other Current Liability', null, null],
        ['2130', 'Customer Deposits', 'Other Current Liability', null, null],
        ['2200', 'Accrued Expenses', 'Other Current Liability', null, null],
        ['2500', 'Loans Payable', 'Long Term Liability', null, null],
        ['3000', "Owner's Capital", 'Equity', null, null],
        ['3100', "Owner's Drawings", 'Equity', 'Debit', null],
        ['3200', 'Retained Earnings', 'Equity', null, null],
        ['3900', 'Opening Balance Equity', 'Equity', null, null],
        ['4000', 'Sales', 'Income', null, null],
        ['4010', 'Service Income', 'Income', null, null],
        ['4020', 'Delivery Income', 'Income', null, null],
        ['4050', 'Sales Returns and Allowances', 'Income', 'Debit', null],
        ['4060', 'Sales Discounts', 'Income', 'Debit', null],
        ['4110', 'Reimbursable Expense Income', 'Income', null, null],
        ['4100', 'Finance Charge Income', 'Other Income', null, null],
        ['4200', 'Interest Income', 'Other Income', null, null],
        ['4300', 'Gain (Loss) on Disposal of Assets', 'Other Income', null, null],
        ['5000', 'Cost of Goods Sold', 'Cost of Goods Sold', null, null],
        ['5050', 'Purchase Discounts', 'Cost of Goods Sold', 'Credit', null],
        ['5100', 'Inventory Adjustments and Shrinkage', 'Cost of Goods Sold', null, null],
        ['5200', 'Direct Labor Absorbed', 'Cost of Goods Sold', 'Credit', null],
        ['6000', 'Salaries and Wages', 'Expense', null, null],
        ['6010', 'Rent Expense', 'Expense', null, null],
        ['6020', 'Utilities Expense', 'Expense', null, null],
        ['6030', 'Repairs and Maintenance', 'Expense', null, null],
        ['6040', 'Office Supplies Expense', 'Expense', null, null],
        ['6050', 'Fuel and Oil', 'Expense', null, null],
        ['6060', 'Meals and Representation', 'Expense', null, null],
        ['6070', 'Professional Fees', 'Expense', null, null],
        ['6080', 'Bank and Merchant Charges', 'Expense', null, null],
        ['6090', 'Depreciation Expense', 'Expense', null, null],
        ['6100', 'Freight and Delivery', 'Expense', null, null],
        ['6110', 'Purchases - Non-inventory', 'Expense', null, null],
        ['6200', 'Cash Over and Short', 'Expense', null, null],
        ['6900', 'Miscellaneous Expense', 'Expense', null, null],
        ['7000', 'Interest Expense', 'Other Expense', null, null],
    ];
    $acct = [];
    foreach ($coa as [$code, $name, $type, $nb, $parent]) {
        [$cat, $normal] = $typeInfo[$type];
        $acct[$code] = DB::insert('accounts', [
            'code' => $code, 'name' => $name, 'account_type' => $type, 'category' => $cat,
            'normal_balance' => $nb ?? $normal, 'parent_id' => $parent ? $acct[$parent] : null,
            'is_system' => in_array($code, ['1050', '1100', '1200', '2000', '2100', '1300', '3200', '3900'], true) ? 1 : 0,
            'bank_account_no' => $code === '1010' ? '0012-3456-7890' : ($code === '1020' ? '1234-5678-90' : null),
        ]);
    }

    $defaults = [
        'ar' => '1100', 'undeposited_funds' => '1050', 'sales_income' => '4000', 'sales_returns' => '4050',
        'sales_discounts' => '4060', 'finance_charge_income' => '4100', 'reimbursable_income' => '4110', 'cwt' => '1310',
        'output_tax' => '2100', 'ap' => '2000', 'input_tax' => '1300', 'purchase_discounts' => '5050',
        'default_expense' => '6110', 'inventory_asset' => '1200', 'cogs' => '5000', 'inventory_adjustment' => '5100',
        'cash_on_hand' => '1001', 'cash_over_short' => '6200', 'card_clearing' => '1060', 'ewallet_clearing' => '1065',
        'merchant_fees' => '6080', 'interest_income' => '4200', 'retained_earnings' => '3200', 'opening_equity' => '3900',
        'gain_loss_disposal' => '4300', 'processing_variance' => '5100', 'processing_absorbed' => '5200',
        'cvat_withheld' => '1315', 'wht_payable' => '2110', 'vat_withheld_payable' => '2115', 'vendor_advances' => '1410',
    ];
    foreach (Accts::KEYS as $key => [$label, $module]) {
        if ($module === 'coop') {
            continue;       // the cooperative accounts and their defaults come from the 2026_11_01 migration (run by install.php)
        }
        DB::insert('default_accounts', ['setting_key' => $key, 'label' => $label, 'module' => $module, 'account_id' => $acct[$defaults[$key]]]);
    }
    Accts::reset();

    // ---------------------------------------------------------------- lists
    $terms = [];
    foreach ([['Due on receipt', 0, 0, 0], ['Net 15', 15, 0, 0], ['Net 30', 30, 0, 0], ['2% 10 Net 30', 30, 2, 10], ['Net 60', 60, 0, 0]] as [$n, $d, $p, $dd]) {
        $terms[$n] = DB::insert('terms', ['name' => $n, 'net_days' => $d, 'discount_pct' => $p, 'discount_days' => $dd]);
    }
    $tax = [];
    $tax['VAT12'] = DB::insert('tax_codes', ['code' => 'VAT12', 'name' => 'VAT 12%', 'rate' => 12, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['ZR'] = DB::insert('tax_codes', ['code' => 'ZR', 'name' => 'Zero-rated', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['EX'] = DB::insert('tax_codes', ['code' => 'EX', 'name' => 'VAT-exempt', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);
    $tax['NT'] = DB::insert('tax_codes', ['code' => 'NT', 'name' => 'Non-taxable', 'rate' => 0, 'sales_account_id' => $acct['2100'], 'purchase_account_id' => $acct['1300']]);

    // the Withholding Tax Table (BIR Reports): 1% goods and 2% services income tax, 5% VAT, on the amount net of VAT
    $wht = [];
    foreach ([
        ['WT-GOODS', 'WC158', 'WT on Goods', 'income', 1, 'Goods bought from regular suppliers by government payors and top withholding agents (RR 11-2018)', 10],
        ['WT-SERVICES', 'WC160', 'WT on Services', 'income', 2, 'Services bought from regular suppliers by government payors and top withholding agents (RR 11-2018)', 20],
        ['WT-VAT', 'WV010', 'WT on VAT', 'vat', 5, 'Creditable VAT withheld by government buyers on VATable purchases (5% of the VAT-exclusive payment)', 30],
    ] as [$code, $atc, $name, $kind, $rate, $desc, $sortNo]) {
        $wht[$code] = DB::insert('withholding_taxes', ['code' => $code, 'atc' => $atc, 'name' => $name, 'tax_kind' => $kind, 'rate' => $rate,
            'base_type' => 'net_of_vat', 'applies_to' => 'both', 'description' => $desc, 'sort_order' => $sortNo]);
    }
    Withholding::reset();

    $pm = [];
    // name, type, POS clearing account, offered as a payment option on the POS register
    foreach ([
        ['Cash', 'cash', null, 1], ['Check', 'check', null, 0], ['Credit/Debit Card', 'card', '1060', 1], ['GCash', 'ewallet', '1065', 1],
        ['Maya', 'ewallet', '1065', 1], ['Bank Transfer', 'bank_transfer', null, 0], ['Gift Voucher', 'voucher', '2120', 1],
        ['P.O. Card', 'card', '1070', 1], ['Bank', 'bank_transfer', '1010', 1],
    ] as [$n, $t, $a, $pos]) {
        $pm[$n] = DB::insert('payment_methods', ['name' => $n, 'method_type' => $t, 'clearing_account_id' => $a ? $acct[$a] : null, 'show_in_pos' => $pos]);
    }
    $pl = [];
    foreach ([['Wholesale', -5], ['VIP', -10], ['Retail Plus', 5], ['Price Level 4', 0], ['Price Level 5', 0], ['Price Level 6', 0], ['Price Level 7', 0], ['Price Level 8', 0], ['Price Level 9', 0], ['Price Level 10', 0]] as [$n, $p]) {
        $pl[$n] = DB::insert('price_levels', ['name' => $n, 'adjust_pct' => $p]);
    }
    $ctype = [];
    foreach (['Corporate', 'Retail', 'School', 'Government'] as $n) {
        $ctype[$n] = DB::insert('customer_types', ['name' => $n]);
    }
    $bg = DB::insert('billing_groups', ['name' => 'Monthly Service Contracts']);

    // ---------------------------------------------------------------- units of measure
    // A set holds the base unit (stock and costs are kept in it) plus the larger or
    // smaller units a document may be entered in.
    $uomSets = [];
    foreach ([
        ['Piece', 'count', 'pc', [['pc', 'Piece', 1, 'S'], ['dz', 'Dozen (12 pieces)', 12, ''], ['box', 'Box of 24', 24, 'P'], ['case', 'Case of 144', 144, '']]],
        ['Weight (kg)', 'weight', 'kg', [['kg', 'Kilogram', 1, 'SP'], ['g', 'Gram', 0.001, ''], ['sack', 'Sack of 25 kg', 25, '']]],
        ['Volume (L)', 'volume', 'L', [['L', 'Liter', 1, 'SP'], ['mL', 'Milliliter', 0.001, ''], ['drum', 'Drum of 200 L', 200, '']]],
        ['Length (m)', 'length', 'm', [['m', 'Meter', 1, 'SP'], ['cm', 'Centimeter', 0.01, ''], ['roll', 'Roll of 100 m', 100, '']]],
        ['Time (hour)', 'time', 'hr', [['hr', 'Hour', 1, 'SP'], ['day', 'Day (8 hours)', 8, ''], ['wk', 'Week (40 hours)', 40, '']]],
    ] as [$sName, $kind, $baseUnit, $units]) {
        $sid = DB::insert('uom_sets', ['name' => $sName, 'uom_kind' => $kind, 'base_unit' => $baseUnit, 'is_active' => 1]);
        $uomSets[$baseUnit] = $sid;
        $sort = 0;
        foreach ($units as [$code, $uName, $factor, $flags]) {
            DB::insert('uom_units', ['set_id' => $sid, 'code' => $code, 'name' => $uName, 'factor' => $factor,
                'is_base' => $factor == 1 ? 1 : 0, 'is_sales_default' => str_contains($flags, 'S') ? 1 : 0,
                'is_purchase_default' => str_contains($flags, 'P') ? 1 : 0, 'sort_order' => $sort++]);
        }
    }

    $cat = [];
    foreach (['Computers', 'Accessories', 'Services', 'Bundles', 'Supplies', 'Charges'] as $n) {
        $cat[$n] = DB::insert('item_categories', ['name' => $n]);
    }

    // ---------------------------------------------------------------- sites & terminals
    $site = [];
    foreach ([['WH', 'Main Warehouse', 'warehouse'], ['ST1', 'Makati Store', 'store'], ['ST2', 'Cebu Store', 'store'], ['VAN1', 'Delivery Van 1', 'van'], ['DMG', 'Damaged Goods', 'damaged']] as [$c, $n, $t]) {
        $site[$c] = DB::insert('sites', ['code' => $c, 'name' => $n, 'site_type' => $t]);
    }
    $term = [];
    $term['POS-01'] = DB::insert('pos_terminals', ['code' => 'POS-01', 'name' => 'Makati Counter 1', 'site_id' => $site['ST1'], 'cash_account_id' => $acct['1001'], 'receipt_prefix' => 'MK1-']);
    $term['POS-02'] = DB::insert('pos_terminals', ['code' => 'POS-02', 'name' => 'Cebu Counter 1', 'site_id' => $site['ST2'], 'cash_account_id' => $acct['1001'], 'receipt_prefix' => 'CB1-']);

    // ---------------------------------------------------------------- roles
    $pageDefs = [];
    foreach ($reg['pages'] as $k => $d) {
        $pageDefs[$k] = ['module' => $d[0], 'type' => $d[2], 'group' => $d[3]];
    }
    $adminSetup = ['admin.users', 'admin.roles', 'admin.page_access', 'admin.effective_access', 'admin.settings',
        'admin.utilities', 'admin.import', 'admin.backup', 'admin.multi_company',
        'company.profile', 'company.users', 'company.join_requests'];
    $select = function (string $pattern) use ($pageDefs): array {
        // "module:*", "module:report", "module:!report", "group:Sales Reports", exact key
        if (isset($pageDefs[$pattern])) {
            return [$pattern];
        }
        [$a, $b] = explode(':', $pattern, 2) + [1 => '*'];
        $out = [];
        foreach ($pageDefs as $k => $d) {
            if ($a === 'group') {
                if ($d['group'] === $b) {
                    $out[] = $k;
                }
                continue;
            }
            if ($d['module'] !== $a) {
                continue;
            }
            if ($b === '*' || ($b === 'report' && $d['type'] === 'report') || ($b === '!report' && $d['type'] !== 'report')) {
                $out[] = $k;
            }
        }
        return $out;
    };

    $roleDefs = [
        'Administrator' => [
            'desc' => 'Everything in every module, including user accounts, roles, page access, the closing date, and the audit trail.',
            'admin' => 1, 'pages' => [], 'special' => [],
        ],
        'Accountant' => [
            'desc' => 'Chart of Accounts, Fixed Assets, Banking and reconciliation, the BIR Reports and Withholding Tax Table, all module reports, and the financial statements.',
            'pages' => [
                'home:*' => 'P', 'coa:*' => 'CEDP', 'fa:*' => 'CEDP', 'bank:*' => 'CEDP',
                'cust:report' => 'P', 'vend:report' => 'P', 'inv:report' => 'P', 'pos:report' => 'P',
                'cust.center' => 'P', 'cust.payments' => 'P', 'cust.invoices' => 'P', 'vend.center' => 'P',
                'vend.bill_tracker' => 'P', 'vend.bills' => 'P', 'vend.pay_bills' => 'P', 'vend.advances' => 'P', 'bir:*' => 'CEDP',
                'inv.center' => 'P', 'inv.items' => 'P', 'inv.categories' => 'P', 'inv.adjust' => 'P', 'inv.process' => 'P', 'inv.formulas' => 'P', 'inv.uom' => 'P',
                'admin.utilities' => 'P',
            ],
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1', 'coa_changes' => '1', 'max_amount' => '', 'max_discount_pct' => '0', 'max_price_override_pct' => '0', 'credit_limit_override' => '0', 'closed_period_changes' => '0', 'design_reports' => '1'],
        ],
        'A/R Clerk' => [
            'desc' => 'Customers module transactions and the Customer and Receivables reports.',
            'pages' => ['home:*' => 'P', 'cust:!report' => 'CEP', 'group:Customer and Receivables Reports' => 'P', 'inv.items' => ''],
            'special' => ['view_costs' => '0', 'view_bank_balances' => '0', 'max_discount_pct' => '10', 'max_price_override_pct' => '5', 'max_amount' => '50000', 'credit_limit_override' => '0'],
        ],
        'A/P Clerk' => [
            'desc' => 'Vendors module transactions, Pay Bills, advances to suppliers, the Vendors and Payables reports, and BIR Form 2307 for suppliers.',
            'pages' => ['home:*' => 'P', 'vend:!report' => 'CEP', 'vend.center' => 'P', 'vend:report' => 'P', 'inv.items' => '',
                'bir.form_2307' => 'P', 'bir.wht_table' => '', 'rpt.bir_wht_summary' => 'P', 'rpt.bir_ewt_register' => 'P', 'rpt.bir_vat_withholding' => 'P', 'rpt.bir_remittance' => 'P'],
            'special' => ['view_costs' => '1', 'view_bank_balances' => '0', 'max_amount' => '100000'],
        ],
        'Inventory Clerk' => [
            'desc' => 'Items, sites, receiving, adjustments, assembly builds, disassembly and processing orders, stock counts, and inventory quantity reports.',
            'pages' => [
                'home:*' => 'P', 'inv.center' => 'P', 'inv.items' => 'CEP', 'inv.categories' => 'CEDP', 'inv.sites' => 'CEP', 'inv.transfers' => 'CEP',
                'inv.adjust' => 'CEP', 'inv.build' => 'CEP', 'inv.physical_count' => 'P',
                'inv.process' => 'CEP', 'inv.formulas' => 'CEP', 'inv.uom' => 'CEP', 'rpt.processing_register' => 'P', 'rpt.process_yield' => 'P',
                'vend.receive_items' => 'CEP', 'vend.purchase_orders' => 'P',
                'rpt.qoh_by_site' => 'P', 'rpt.stock_status_item' => 'P', 'rpt.stock_status_vendor' => 'P', 'rpt.stock_status_site' => 'P',
                'rpt.physical_worksheet' => 'P', 'rpt.pending_builds' => 'P', 'rpt.pending_builds_site' => 'P', 'rpt.negative_inventory' => 'P', 'rpt.inv_no_movement' => 'P',
                'rpt.inv_movement_summary' => 'P', 'rpt.inv_movement_detail' => 'P', 'rpt.inv_movement_txns' => 'P',
            ],
            'special' => ['view_costs' => '0', 'max_amount' => '20000'],
        ],
        'Sales Representative' => [
            'desc' => 'Estimates, sales orders, and invoices, and the Sales reports.',
            'pages' => [
                'home:*' => 'P', 'cust.center' => 'P', 'cust.estimates' => 'CEP', 'cust.sales_orders' => 'CEP', 'cust.invoices' => 'CEP',
                'group:Sales Reports' => 'P', 'rpt.item_price_list' => 'P', 'rpt.inv_movement_txns' => 'P',
            ],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '5', 'max_price_override_pct' => '0'],
        ],
        'Cashier' => [
            'desc' => 'POS sales, returns, and cash drops at the assigned terminal only.',
            'pages' => ['home.dashboard' => '', 'pos.register' => 'CP', 'pos.shifts' => 'CP', 'pos.receipts' => 'CDP'],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '5', 'max_price_override_pct' => '0', 'max_amount' => '0'],
            'sites' => ['ST1', 'ST2'],
        ],
        'POS Supervisor' => [
            'desc' => 'Everything a cashier can do, plus approving voids, refunds, price overrides, and large discounts, and running X- and Z-readings.',
            'pages' => ['home.dashboard' => '', 'home.reports' => '', 'pos.register' => 'CEDPA', 'pos.shifts' => 'CEDPA', 'pos.receipts' => 'CEDPA', 'pos.approval_codes' => 'CEDPA', 'pos.terminals' => 'P', 'pos:report' => 'P'],
            'special' => ['view_costs' => '0', 'max_discount_pct' => '30', 'max_price_override_pct' => '20', 'max_amount' => '20000'],
        ],
        'Auditor' => [
            'desc' => 'View-only access to all transactions, reports, and the audit trail. Cannot create or change anything.',
            'pages' => ['*' => 'P'],
            // nor the teller's cash pages (as the cooperative set-up has it)
            'deny' => array_merge($adminSetup, ['pos.register', 'pos.shifts', 'teller.shift', 'teller.or', 'teller.disburse']),
            'special' => ['view_costs' => '1', 'view_bank_balances' => '1'],
        ],
    ];

    $roleIds = [];
    foreach ($roleDefs as $name => $def) {
        $rid = DB::insert('roles', [
            'name' => $name, 'description' => $def['desc'], 'is_admin' => $def['admin'] ?? 0, 'is_system' => 1,
            'all_sites' => isset($def['sites']) ? 0 : 1,
        ]);
        $roleIds[$name] = $rid;
        $grant = [];
        foreach ($def['pages'] as $pattern => $letters) {
            $keys = $pattern === '*' ? array_keys($pageDefs) : $select($pattern);
            foreach ($keys as $k) {
                $grant[$k] = ($grant[$k] ?? '') . $letters;
            }
        }
        foreach ($def['deny'] ?? [] as $k) {
            unset($grant[$k]);
        }
        foreach ($grant as $k => $letters) {
            DB::insert('role_page_access', [
                'role_id' => $rid, 'page_id' => $pageIds[$k], 'allowed' => 1,
                'can_create' => (int)str_contains($letters, 'C'), 'can_edit' => (int)str_contains($letters, 'E'),
                'can_delete' => (int)str_contains($letters, 'D'), 'can_print' => (int)str_contains($letters, 'P'),
                'can_approve' => (int)str_contains($letters, 'A'),
            ]);
        }
        foreach ($def['special'] as $k => $v) {
            DB::insert('role_special_permissions', ['role_id' => $rid, 'perm_key' => $k, 'perm_value' => $v]);
        }
        foreach ($def['sites'] ?? [] as $s) {
            DB::insert('role_sites', ['role_id' => $rid, 'site_id' => $site[$s]]);
        }
    }

    // ---------------------------------------------------------------- users
    $users = [
        ['admin', 'System Administrator', 'Administrator', 'Admin@12345', 1, null, null],
        ['accountant', 'Ana Cruz', 'Accountant', 'Demo@12345', 0, null, null],
        ['arclerk', 'Ben Garcia', 'A/R Clerk', 'Demo@12345', 0, null, null],
        ['apclerk', 'Carla Lim', 'A/P Clerk', 'Demo@12345', 0, null, null],
        ['invclerk', 'Dan Villanueva', 'Inventory Clerk', 'Demo@12345', 0, null, null],
        ['salesrep', 'Maria Santos', 'Sales Representative', 'Demo@12345', 0, null, null],
        ['cashier1', 'Ella Ramos', 'Cashier', 'Demo@12345', 0, 'ST1', 'POS-01'],
        ['supervisor', 'Fred Tan', 'POS Supervisor', 'Demo@12345', 0, 'ST1', 'POS-01'],
        ['auditor', 'Grace Uy', 'Auditor', 'Demo@12345', 0, null, null],
    ];
    $userIds = [];
    $n = 0;
    foreach ($users as [$u, $full, $role, $pw, $must, $s, $t]) {
        $uid = DB::insert('users', [
            'username' => $u, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => $full,
            'email' => $u . '@sampletrading.test', 'employee_no' => sprintf('EMP-%03d', ++$n), 'status' => 'active',
            'must_change_password' => $must, 'password_changed_at' => date('Y-m-d H:i:s'),
        ]);
        $userIds[$u] = $uid;
        DB::insert('user_roles', ['user_id' => $uid, 'role_id' => $roleIds[$role]]);
        if ($s) {
            DB::insert('user_sites', ['user_id' => $uid, 'site_id' => $site[$s]]);
        }
        if ($t) {
            DB::insert('user_terminals', ['user_id' => $uid, 'terminal_id' => $term[$t]]);
        }
    }

    // ---------------------------------------------------------------- sales reps
    $rep = [];
    $rep['MS'] = DB::insert('sales_reps', ['name' => 'Maria Santos', 'initials' => 'MS', 'user_id' => $userIds['salesrep'], 'commission_pct' => 3]);
    $rep['JR'] = DB::insert('sales_reps', ['name' => 'Jose Reyes', 'initials' => 'JR', 'commission_pct' => 2.5]);

    // ---------------------------------------------------------------- vendors
    $vend = [];
    foreach ([
        ['TechDistributors Inc.', 'Rico Lopez', '(02) 8555-0101', 'sales@techdist.test', 'Pasig City', 'Net 30', null, 'TD-88012'],
        ['Office Depot PH', 'Liza Go', '(02) 8555-0202', 'orders@officedepot.test', 'Quezon City', 'Net 15', '6040', 'OD-4410'],
        ['Meralco', 'Customer Service', '16211', 'billing@meralco.test', 'Pasig City', 'Due on receipt', '6020', '1234-5678-9012'],
        ['Metro Property Leasing', 'Tony Chua', '(02) 8555-0303', 'leasing@metroprop.test', 'Makati City', 'Due on receipt', '6010', 'L-2291'],
        ['Petron Service Station', null, '(02) 8555-0404', null, 'Makati City', 'Due on receipt', '6050', null],
        ['JRS Express', 'Operations', '(02) 8555-0505', 'ops@jrs.test', 'Manila', 'Net 15', '6100', 'JRS-7781'],
        ['Accounting Partners & Co.', 'Atty. Reyes', '(02) 8555-0606', 'hello@apco.test', 'Taguig City', 'Net 30', '6070', null],
        ['Industrial Machines Corp.', 'Sales', '(02) 8555-0707', 'sales@imc.test', 'Laguna', 'Net 30', null, 'IMC-100'],
    ] as [$name, $contact, $phone, $email, $addr, $t, $exp, $acctNo]) {
        $vend[$name] = DB::insert('vendors', [
            'name' => $name, 'company_name' => $name, 'contact_person' => $contact, 'phone' => $phone, 'email' => $email,
            'address' => $addr, 'tin' => sprintf('%03d-%03d-%03d-000', random_int(100, 999), random_int(100, 999), random_int(100, 999)),
            'terms_id' => $terms[$t], 'default_expense_account_id' => $exp ? $acct[$exp] : null, 'tax_code_id' => $tax['VAT12'],
            'account_no' => $acctNo,
            // the taxes withheld when the supplier is paid: 1% on goods suppliers, 2% on service providers
            'wht_ids' => ['TechDistributors Inc.' => $wht['WT-GOODS'], 'Industrial Machines Corp.' => $wht['WT-GOODS'],
                'Accounting Partners & Co.' => $wht['WT-SERVICES'], 'JRS Express' => $wht['WT-SERVICES']][$name] ?? null,
        ]);
    }

    // ---------------------------------------------------------------- items
    $item = [];
    $mk = function (array $d) use (&$item, $acct, $tax, $cat, $vend) {
        $row = array_merge([
            'income_account_id' => $acct['4000'], 'cogs_account_id' => $acct['5000'], 'asset_account_id' => null,
            'expense_account_id' => null, 'tax_code_id' => $tax['VAT12'], 'unit' => 'pc',
        ], $d);
        if (isset($row['cat'])) {
            $row['category_id'] = $cat[$row['cat']];
            unset($row['cat']);
        }
        if (isset($row['vendor'])) {
            $row['preferred_vendor_id'] = $vend[$row['vendor']];
            unset($row['vendor']);
        }
        if (in_array($row['item_type'], ['inventory', 'assembly'], true)) {
            $row['asset_account_id'] ??= $acct['1200'];
            $row['avg_cost'] = 0;
        }
        $item[$row['code']] = DB::insert('items', $row);
    };
    foreach ([
        ['LAP-001', 'Laptop 14-inch i5 16GB', 32000, 39990, 5, 20, '4800000000011'],
        ['MON-024', 'Monitor 24-inch IPS', 6500, 8990, 5, 30, '4800000000028'],
        ['KEY-001', 'Keyboard USB', 650, 999, 10, 50, '4800000000035'],
        ['MOU-001', 'Wireless Mouse', 350, 599, 15, 80, '4800000000042'],
        ['USB-032', 'USB Flash Drive 32GB', 180, 350, 20, 100, '4800000000059'],
        ['BAG-001', 'Laptop Backpack', 500, 899, 10, 40, '4800000000066'],
        ['CAB-HDMI', 'HDMI Cable 1.5m', 90, 199, 20, 100, '4800000000073'],
        ['RAM-8GB', 'RAM 8GB DDR4', 1100, 1690, 10, 40, '4800000000080'],
        ['SSD-512', 'SSD 512GB NVMe', 2200, 3290, 10, 40, '4800000000097'],
    ] as [$code, $name, $cost, $price, $reorder, $max, $barcode]) {
        $mk(['item_type' => 'inventory', 'code' => $code, 'barcode' => $barcode, 'name' => $name, 'description' => $name,
            'purchase_description' => $name, 'cost' => $cost, 'sales_price' => $price, 'reorder_point' => $reorder,
            'max_stock' => $max, 'cat' => in_array($code, ['LAP-001', 'MON-024'], true) ? 'Computers' : 'Accessories',
            'vendor' => 'TechDistributors Inc.']);
    }
    $mk(['item_type' => 'service', 'code' => 'LAB-ASM', 'name' => 'Assembly Labor (per set)', 'description' => 'Assembly labor', 'cost' => 150, 'sales_price' => 0,
        'income_account_id' => $acct['4010'], 'expense_account_id' => $acct['5200'], 'cogs_account_id' => $acct['5200'], 'tax_code_id' => $tax['NT'], 'unit' => 'set', 'cat' => 'Services']);
    $mk(['item_type' => 'assembly', 'code' => 'SET-OFFICE', 'barcode' => '4800000000103', 'name' => 'Office Monitor Set', 'description' => 'Monitor + keyboard + mouse + HDMI cable, assembled',
        'cost' => 0, 'sales_price' => 12990, 'build_point' => 3, 'reorder_point' => 3, 'max_stock' => 15, 'cat' => 'Bundles']);
    foreach ([['MON-024', 1], ['KEY-001', 1], ['MOU-001', 1], ['CAB-HDMI', 1], ['LAB-ASM', 1]] as [$c, $q]) {
        DB::insert('item_components', ['parent_item_id' => $item['SET-OFFICE'], 'component_item_id' => $item[$c], 'qty' => $q]);
    }
    $mk(['item_type' => 'service', 'code' => 'SRV-INSTALL', 'name' => 'Installation and Setup', 'description' => 'On-site installation and setup', 'sales_price' => 1500,
        'income_account_id' => $acct['4010'], 'unit' => 'job', 'cat' => 'Services']);
    $mk(['item_type' => 'service', 'code' => 'SRV-REPAIR', 'name' => 'Repair Service (per hour)', 'description' => 'Repair service', 'sales_price' => 800,
        'income_account_id' => $acct['4010'], 'unit' => 'hr', 'cat' => 'Services']);
    $mk(['item_type' => 'service', 'code' => 'SRV-MAINT', 'name' => 'Monthly IT Maintenance', 'description' => 'Monthly IT maintenance contract', 'sales_price' => 5000,
        'income_account_id' => $acct['4010'], 'unit' => 'month', 'cat' => 'Services']);
    $mk(['item_type' => 'non_inventory', 'code' => 'NI-TONER', 'name' => 'Toner Cartridge (special order)', 'description' => 'Toner cartridge - special order',
        'cost' => 2500, 'sales_price' => 3200, 'expense_account_id' => $acct['5000'], 'cat' => 'Supplies', 'vendor' => 'Office Depot PH']);
    $mk(['item_type' => 'other_charge', 'code' => 'DEL-FEE', 'name' => 'Delivery Fee', 'description' => 'Delivery fee', 'sales_price' => 250,
        'income_account_id' => $acct['4020'], 'expense_account_id' => $acct['6100'], 'cat' => 'Charges']);
    $mk(['item_type' => 'other_charge', 'code' => 'REIMB', 'name' => 'Reimbursable Expense', 'description' => 'Reimbursable expense', 'sales_price' => 0,
        'income_account_id' => $acct['4110'], 'expense_account_id' => $acct['6900'], 'tax_code_id' => $tax['NT'], 'cat' => 'Charges']);
    $mk(['item_type' => 'other_charge', 'code' => 'LATE-FEE', 'name' => 'Late Payment Fee', 'description' => 'Late payment fee', 'sales_price' => 200,
        'income_account_id' => $acct['4100'], 'tax_code_id' => $tax['NT'], 'cat' => 'Charges']);
    $mk(['item_type' => 'discount', 'code' => 'DISC-10', 'name' => '10% Discount', 'description' => '10% discount', 'discount_pct' => 10,
        'income_account_id' => $acct['4060'], 'cat' => 'Charges']);
    $mk(['item_type' => 'discount', 'code' => 'DISC-AMT', 'name' => 'Fixed Discount', 'description' => 'Discount', 'sales_price' => 100,
        'income_account_id' => $acct['4060'], 'cat' => 'Charges']);
    $mk(['item_type' => 'subtotal', 'code' => 'SUBTOTAL', 'name' => 'Subtotal', 'description' => 'Subtotal', 'tax_code_id' => null]);
    $mk(['item_type' => 'group', 'code' => 'GRP-WFH', 'name' => 'Work-from-Home Bundle', 'description' => 'Laptop + mouse + backpack', 'tax_code_id' => null, 'cat' => 'Bundles']);
    foreach ([['LAP-001', 1], ['MOU-001', 1], ['BAG-001', 1]] as [$c, $q]) {
        DB::insert('item_components', ['parent_item_id' => $item['GRP-WFH'], 'component_item_id' => $item[$c], 'qty' => $q]);
    }
    $mk(['item_type' => 'payment', 'code' => 'DOWNPMT', 'name' => 'Down Payment Received', 'description' => 'Less: down payment received', 'tax_code_id' => null]);
    DB::insert('price_level_items', ['price_level_id' => $pl['VIP'], 'item_id' => $item['LAP-001'], 'custom_price' => 36500]);

    // items used by the sample processing formulas
    $mk(['item_type' => 'inventory', 'code' => 'USB-032-BX50', 'barcode' => '4800000000110', 'name' => 'USB Flash Drive 32GB (Box of 50)', 'description' => 'USB Flash Drive 32GB, box of 50',
        'purchase_description' => 'USB Flash Drive 32GB, box of 50', 'cost' => 8500, 'sales_price' => 16500, 'unit' => 'box', 'reorder_point' => 1, 'max_stock' => 5,
        'cat' => 'Accessories', 'vendor' => 'TechDistributors Inc.']);
    $mk(['item_type' => 'inventory', 'code' => 'TRD-PC', 'name' => 'Trade-in Desktop PC (used)', 'description' => 'Used desktop PC taken in for parts / refurbishing',
        'purchase_description' => 'Trade-in desktop PC', 'cost' => 3000, 'sales_price' => 0, 'cat' => 'Computers']);
    $mk(['item_type' => 'inventory', 'code' => 'REF-PC', 'name' => 'Refurbished Desktop PC', 'description' => 'Refurbished desktop PC with new SSD, 90-day warranty',
        'cost' => 0, 'sales_price' => 14990, 'cat' => 'Computers']);
    $mk(['item_type' => 'inventory', 'code' => 'SCRAP-KG', 'name' => 'Scrap Metal and Plastic', 'description' => 'Scrap metal and plastic (sold by the kilo)',
        'cost' => 15, 'sales_price' => 25, 'unit' => 'kg', 'cat' => 'Supplies', 'tax_code_id' => $tax['EX']]);

    // give every item the U/M set whose base unit it is already stocked in
    foreach ($uomSets as $baseUnit => $sid) {
        DB::run('UPDATE items SET uom_set_id = :s WHERE unit = :u', ['s' => $sid, 'u' => $baseUnit]);
    }
    // service items are quoted by the hour
    DB::run('UPDATE items SET uom_set_id = :s, unit = :u WHERE item_type = :t', ['s' => $uomSets['hr'], 'u' => 'hr', 't' => 'service']);

    $mkFormula = function (string $code, string $name, string $type, string $method, string $desc, array $lines) use ($item, $acct) {
        $fid = DB::insert('process_formulas', ['code' => $code, 'name' => $name, 'process_type' => $type, 'alloc_method' => $method, 'description' => $desc]);
        $n = 0;
        foreach ($lines as $l) {
            [$kind, $ref, $qty] = $l;
            DB::insert('process_formula_lines', [
                'formula_id' => $fid, 'line_no' => ++$n, 'line_type' => $kind,
                'item_id' => $kind === 'cost' ? null : $item[$ref], 'account_id' => $kind === 'cost' ? $acct[$ref] : null,
                'qty' => $kind === 'cost' ? 0 : $qty, 'amount' => $kind === 'cost' ? $qty : 0,
                'output_role' => $kind === 'output' ? ($l[3] ?? 'co_product') : null, 'alloc_basis' => $l[4] ?? 0,
                'description' => $l[5] ?? null,
            ]);
        }
    };
    $mkFormula('BRK-USB50', 'Break bulk: USB 32GB box of 50', 'dekit', 'quantity', 'Open a box of 50 and sell the drives one by one.', [
        ['input', 'USB-032-BX50', 1],
        ['output', 'USB-032', 50, 'co_product'],
    ]);
    $mkFormula('TD-PC', 'Trade-in PC teardown', 'disassembly', 'sales_value', 'Strip a trade-in PC for resaleable parts; the chassis is sold as scrap by the kilo.', [
        ['input', 'TRD-PC', 1],
        ['cost', '5200', 150, null, 0, 'Technician labor (1 hour)'],
        ['output', 'RAM-8GB', 2, 'co_product'],
        ['output', 'SSD-512', 1, 'co_product'],
        ['output', 'SCRAP-KG', 4, 'by_product', 15],
    ]);
    $mkFormula('RFB-PC', 'Refurbish trade-in PC (new SSD)', 'co_product', 'percent', 'Trade-in PC plus a new SSD become a refurbished PC; the old RAM is recovered for resale.', [
        ['input', 'TRD-PC', 1],
        ['input', 'SSD-512', 1],
        ['cost', '5200', 400, null, 0, 'Refurbishing labor and testing'],
        ['output', 'REF-PC', 1, 'co_product', 85],
        ['output', 'RAM-8GB', 2, 'co_product', 15],
        ['output', 'SCRAP-KG', 1, 'scrap', 0],
    ]);

    // ---------------------------------------------------------------- customers
    $cust = [];
    $mkc = function (array $d) use (&$cust, $terms, $tax, $rep, $pl, $ctype, $pm) {
        $row = array_merge(['tax_code_id' => $tax['VAT12']], $d);
        foreach (['terms' => 'terms_id', 'rep' => 'sales_rep_id', 'pl' => 'price_level_id', 'type' => 'customer_type_id', 'pm' => 'payment_method_id'] as $k => $col) {
            if (isset($row[$k])) {
                $map = ['terms' => $terms, 'rep' => $rep, 'pl' => $pl, 'type' => $ctype, 'pm' => $pm][$k];
                $row[$col] = $map[$row[$k]];
                unset($row[$k]);
            }
        }
        $cust[$row['name']] = DB::insert('customers', $row);
    };
    $mkc(['name' => 'ABC Trading Corp.', 'company_name' => 'ABC Trading Corporation', 'contact_person' => 'Mr. Albert Co', 'phone' => '(02) 8777-1001', 'email' => 'ap@abctrading.test',
        'billing_address' => '45 Buendia Ave., Makati City', 'shipping_address' => '45 Buendia Ave., Makati City', 'tin' => '111-222-333-000',
        'terms' => 'Net 30', 'credit_limit' => 250000, 'rep' => 'MS', 'type' => 'Corporate', 'pm' => 'Check', 'allow_pos_charge' => 1]);
    $mkc(['parent_id' => $cust['ABC Trading Corp.'], 'name' => 'Office Renovation Project', 'billing_address' => '45 Buendia Ave., Makati City',
        'terms' => 'Net 30', 'rep' => 'MS', 'type' => 'Corporate']);
    $mkc(['name' => 'Metro School Supplies', 'company_name' => 'Metro School Supplies Inc.', 'contact_person' => 'Ms. Bea Lim', 'phone' => '(02) 8777-2002', 'email' => 'purchasing@metroschool.test',
        'billing_address' => '88 España Blvd., Manila', 'terms' => 'Net 15', 'credit_limit' => 100000, 'pl' => 'Wholesale', 'rep' => 'JR', 'type' => 'Retail']);
    $mkc(['name' => 'Cebu Tech Hub', 'company_name' => 'Cebu Tech Hub Co.', 'contact_person' => 'Mr. Carlo Sy', 'phone' => '(032) 255-3003', 'email' => 'finance@cebutech.test',
        'billing_address' => 'IT Park, Lahug, Cebu City', 'terms' => '2% 10 Net 30', 'credit_limit' => 150000, 'pl' => 'VIP', 'rep' => 'JR', 'type' => 'Corporate']);
    $mkc(['name' => 'Bright Minds Academy', 'company_name' => 'Bright Minds Academy', 'contact_person' => 'Dr. Dina Reyes', 'phone' => '(02) 8777-4004', 'email' => 'admin@brightminds.test',
        'billing_address' => '12 Katipunan Ave., Quezon City', 'terms' => 'Net 15', 'credit_limit' => 60000, 'rep' => 'MS', 'type' => 'School', 'billing_group_id' => $bg]);
    $mkc(['name' => 'Green Valley Clinic', 'company_name' => 'Green Valley Clinic', 'contact_person' => 'Dr. Eric Tan', 'phone' => '(02) 8777-5005',
        'billing_address' => '5 Shaw Blvd., Mandaluyong', 'terms' => 'Net 15', 'credit_limit' => 40000, 'rep' => 'MS', 'type' => 'Corporate', 'billing_group_id' => $bg]);
    // a government buyer: withholds 5% VAT and 1% income tax from what it pays (the guide's example D)
    $mkc(['name' => 'City Government of Makati', 'company_name' => 'City Government of Makati', 'contact_person' => 'Procurement Office', 'phone' => '(02) 8870-1000',
        'email' => 'procurement@makati.test', 'billing_address' => 'Makati City Hall, J.P. Rizal St., Makati City', 'tin' => '000-456-789-000',
        'terms' => 'Net 30', 'type' => 'Government', 'pm' => 'Check', 'wht_ids' => $wht['WT-VAT'] . ',' . $wht['WT-GOODS']]);
    $mkc(['name' => 'Juan Dela Cruz', 'contact_person' => 'Juan Dela Cruz', 'mobile' => '0917-555-0000', 'terms' => 'Due on receipt', 'type' => 'Retail', 'pm' => 'Cash']);
    $mkc(['name' => 'Walk-in Customer', 'terms' => 'Due on receipt', 'type' => 'Retail', 'pm' => 'Cash']);

    // ---------------------------------------------------------------- fixed asset categories
    foreach ([
        ['Buildings', '1510', '1515', 'straight_line', 25],
        ['Machinery and Equipment', '1520', '1525', 'straight_line', 5],
        ['Production Machinery (usage-based)', '1520', '1525', 'units_of_production', 5],
        ['Transportation Equipment', '1530', '1535', 'declining_balance', 5],
        ['Furniture and Fixtures', '1540', '1545', 'straight_line', 10],
        ['Computer and POS Equipment', '1550', '1555', 'declining_balance', 3],
    ] as [$n, $a, $ad, $m, $life]) {
        DB::insert('asset_categories', ['name' => $n, 'asset_account_id' => $acct[$a], 'accum_dep_account_id' => $acct[$ad],
            'dep_expense_account_id' => $acct['6090'], 'default_method' => $m, 'default_life_years' => $life]);
    }

    // ---------------------------------------------------------------- sequences
    foreach ([
        ['estimate', 'EST-', 1, 5], ['sales_order', 'SO-', 1, 5], ['invoice', 'INV-', 1, 5], ['sales_receipt', 'SR-', 1, 5],
        ['statement_charge', 'SC-', 1, 5], ['finance_charge', 'FC-', 1, 5], ['payment', 'PMT-', 1, 5], ['credit_memo', 'CM-', 1, 5],
        ['purchase_order', 'PO-', 1, 5], ['bill', 'BILL-', 1, 5], ['item_receipt', 'IR-', 1, 5], ['check', '', 1001, 4],
        ['deposit', 'DEP-', 1, 5], ['transfer', 'TRF-', 1, 5], ['journal', 'JE-', 1, 5], ['adjustment', 'ADJ-', 1, 5],
        ['inv_transfer', 'IT-', 1, 5], ['build', 'BLD-', 1, 5], ['asset', 'FA-', 1, 5], ['pos', 'POS-', 1, 6],
        ['processing', 'PRC-', 1, 5], ['cash_voucher', 'CAV-', 1, 5],
        ['maintenance', 'MNT-', 1, 5], ['debit_memo', 'DM-', 1, 5], ['vendor_advance', 'ADV-', 1, 5], ['advance_apply', 'AAP-', 1, 5],
    ] as [$k, $pfx, $next, $pad]) {
        DB::insert('sequences', ['seq_key' => $k, 'prefix' => $pfx, 'next_no' => $next, 'pad' => $pad]);
    }
}
