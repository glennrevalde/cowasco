<?php
declare(strict_types=1);

/**
 * Water billing starter set-up for a NEW installation (one that does not bring in a former water database).
 *
 *   php tools/water_starter.php                 (the database of config/config.php, or DB_NAME)
 *
 * Also run by `php tools/install.php --water-starter` and `--demo`, and part of database/accounting_system_clean.sql
 * and database/accounting_system.sql. It writes:
 *   - water accounts in the chart, where their codes are free: 1120 Accounts Receivable - Water, 2135 Water Service
 *     Deposits, 4500 Water Sales, 4510 Water Connection and Service Fees, 4520 Water Penalties and Surcharges,
 *     4530 Water Compromise Income, 4540 Sales - Water Meters and Materials, 4550 Discounts on Water Sales and
 *     1002 Cash on Hand - Water Collections (under 1000 Cash);
 *   - Module Default Accounts > Water pointed at them (A/R, sales, penalty, compromise, discounts, collections cash);
 *   - the reference lists of database/seeds/water_reference.sql (rate codes, pipe sizes, the tariff tiers, discount
 *     classes and privilege rules, address types, contact channels, cities, the default branch/division/segment);
 *   - the stockroom of the Water Utility line of business and the materials (water meter, PVC pipe, fittings set);
 *   - eight bill codes: WATER BILL, CONNECTION FEE, RECONNECTION FEE, SERVICE DEPOSIT, MAINTENANCE FEE and the three
 *     materials (WATER METER, PVC PIPE, FITTINGS SET: inventory codes linked to their items, used for sales on bills
 *     and for Material Issuance); the water billing settings (collection policy accounts, manual penalty and compromise);
 *   - the institution profile from the company settings (the report signatories are left to the administrator).
 *
 * NOT for a database that will receive a former water database with tools/migrate_water.php: that tool brings the
 * former lists in itself, and its pre-flight stops when these tables already hold rows.
 */
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    require __DIR__ . '/../app/bootstrap.php';
    require_once __DIR__ . '/lib.php';
    $_SESSION = [];
    Tenant::bootCli();
    try {
        $r = seed_water_starter();
        foreach ($r as $line) {
            echo $line, "\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'STOPPED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

/** @return string[] what was done */
function seed_water_starter(): array
{
    $done = [];
    foreach (['rate_code', 'water_pipe_sizes', 'water_meter_members', 'bill_codes', 'water_accounts', 'cities'] as $t) {
        if ((int)DB::value("SELECT COUNT(*) FROM `$t`") > 0) {
            throw new RuntimeException("The water table $t already holds rows: the starter set-up is for a new installation only.");
        }
    }
    $adminId = (int)(DB::value("SELECT id FROM users WHERE username = 'admin'") ?: 0);
    $now = date('Y-m-d H:i:s');

    return DB::tx(function () use (&$done, $adminId, $now) {
        // ---- 1. accounts (made only where the code is free; an existing account with that code is used as it is)
        $acct = function (string $code, string $name, string $category, string $type, string $description, ?string $normal = null, ?int $parent = null) use (&$done): int {
            $id = DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
            if ($id) {
                return (int)$id;
            }
            $id = DB::insert('accounts', ['code' => $code, 'name' => $name, 'account_type' => $type, 'category' => $category,
                'normal_balance' => $normal ?? (in_array($category, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit'), 'parent_id' => $parent,
                'description' => $description, 'with_sl' => 0, 'is_active' => 1, 'is_system' => 0]);
            $done[] = "account $code $name";
            return $id;
        };
        $ar = $acct('1120', 'Accounts Receivable - Water', 'Asset', 'Accounts Receivable', 'Water bills and other charges billed to water-service accounts');
        $deposits = $acct('2135', 'Water Service Deposits', 'Liability', 'Other Current Liability', 'Service (meter) deposits billed to water-service accounts, refundable');
        $sales = $acct('4500', 'Water Sales', 'Income', 'Income', 'Metered water sales (Water Utility)');
        $fees = $acct('4510', 'Water Connection and Service Fees', 'Income', 'Income', 'Connection, reconnection and maintenance fees');
        $penalty = $acct('4520', 'Water Penalties and Surcharges', 'Income', 'Income', 'Penalties charged on late water payments');
        $compromise = $acct('4530', 'Water Compromise Income', 'Income', 'Income', 'Compromise settlements of water accounts');
        $materials = $acct('4540', 'Sales - Water Meters and Materials', 'Income', 'Income', 'Water meters and materials sold to consumers');
        $discounts = $acct('4550', 'Discounts on Water Sales', 'Income', 'Income', 'Senior citizen and other discounts granted on water bills', 'Debit');
        $cashParent = DB::value("SELECT id FROM accounts WHERE code = '1000' AND account_type = 'Bank'");
        $cash = $acct('1002', 'Cash on Hand - Water Collections', 'Asset', 'Bank', 'Water collections of the cashiers, before deposit', 'Debit', $cashParent ? (int)$cashParent : null);
        $default = fn(string $key) => (int)(DB::value('SELECT account_id FROM default_accounts WHERE setting_key = :k', ['k' => $key]) ?: 0);
        $cogs = $default('cogs') ?: (int)DB::value("SELECT id FROM accounts WHERE account_type = 'Cost of Goods Sold' ORDER BY code LIMIT 1");
        $asset = $default('inventory_asset') ?: (int)DB::value("SELECT id FROM accounts WHERE code = '1200'");

        // ---- 2. Module Default Accounts > Water (only those still unset)
        foreach (['water_ar' => $ar, 'water_income' => $sales, 'water_penalty' => $penalty, 'water_compromise' => $compromise, 'water_cash' => $cash, 'water_discount' => $discounts] as $key => $id) {
            DB::run('UPDATE default_accounts SET account_id = :a WHERE setting_key = :k AND account_id IS NULL', ['a' => $id, 'k' => $key]);
        }
        $done[] = 'Module Default Accounts > Water';

        // ---- 3. reference lists
        $sqlFile = APP_ROOT . '/database/seeds/water_reference.sql';
        foreach (Migrator::split((string)file_get_contents($sqlFile)) as $stmt) {
            Sql::drain(DB::pdo()->query($stmt));
        }
        $done[] = 'rate codes, pipe sizes, tariff tiers, discounts, address lists, cities (database/seeds/water_reference.sql)';

        // ---- 4. the Water Utility stockroom and the materials
        $unit = (int)(DB::value("SELECT id FROM business_units WHERE code = 'WU'") ?: 0);
        $site = (int)(DB::value("SELECT id FROM sites WHERE code = 'WTR'") ?: 0);
        if (!$site) {
            $site = DB::insert('sites', ['code' => 'WTR', 'name' => 'Water Utility Stockroom', 'site_type' => 'stockroom', 'is_active' => 1]);
            $done[] = 'site WTR Water Utility Stockroom';
        }
        if ($unit) {
            DB::run('UPDATE business_units SET default_site_id = COALESCE(default_site_id, :s) WHERE id = :u', ['s' => $site, 'u' => $unit]);
        }
        $cat = (int)(DB::value("SELECT id FROM item_categories WHERE name = 'Water Materials'") ?: DB::insert('item_categories', ['name' => 'Water Materials']));
        $item = function (string $code, string $name, string $unitName, float $cost, float $price, int $reorder) use ($cat, $materials, $cogs, $asset, &$done): int {
            $id = DB::value('SELECT id FROM items WHERE code = :c', ['c' => $code]);
            if ($id) {
                return (int)$id;
            }
            $id = DB::insert('items', ['item_type' => 'inventory', 'code' => $code, 'name' => $name, 'description' => $name, 'purchase_description' => $name,
                'category_id' => $cat, 'unit' => $unitName, 'cost' => $cost, 'avg_cost' => 0, 'sales_price' => $price, 'discount_pct' => 0,
                'income_account_id' => $materials, 'cogs_account_id' => $cogs, 'asset_account_id' => $asset, 'reorder_point' => $reorder, 'is_active' => 1, 'patronage_eligible' => 0]);
            $done[] = "item $code $name";
            return $id;
        };
        $meter = $item('WM-050', 'Water Meter 1/2 inch', 'pc', 750, 1200, 10);
        $pipe = $item('PVC-050', 'PVC Pipe 1/2 inch x 3 m', 'length', 85, 120, 30);
        $fittings = $item('WFS-STD', 'Service Connection Fittings Set', 'set', 260, 380, 10);

        // ---- 5. bill codes
        $codes = [
            [1, 'WATER BILL', 'Monthly metered water consumption', 'Service', $ar, $sales, null, null, null, 0, 0, 1],
            [2, 'CONNECTION FEE', 'New service connection fee', 'Service', $ar, $fees, null, null, null, 0, 1500, 0],
            [3, 'RECONNECTION FEE', 'Reconnection after a disconnection', 'Service', $ar, $fees, null, null, null, 0, 300, 0],
            [4, 'SERVICE DEPOSIT', 'Refundable service (meter) deposit', 'Charges', $ar, $deposits, null, null, null, 0, 500, 0],
            [5, 'WATER METER', 'Water meter 1/2 inch sold to the consumer', 'Inventory', $ar, $materials, $cogs, $asset, $meter, 750, 1200, 0],
            [6, 'MAINTENANCE FEE', 'Monthly line maintenance fee (commercial connections)', 'Charges', $ar, $fees, null, null, null, 0, 50, 0],
            [7, 'PVC PIPE', 'PVC pipe 1/2 inch x 3 m (service line materials)', 'Inventory', $ar, $materials, $cogs, $asset, $pipe, 85, 120, 0],
            [8, 'FITTINGS SET', 'Service connection fittings set', 'Inventory', $ar, $materials, $cogs, $asset, $fittings, 260, 380, 0],
        ];
        foreach ($codes as [$id, $code, $desc, $type, $arA, $inc, $cg, $as, $it, $cost, $price, $disc]) {
            DB::insert('bill_codes', ['code_id' => $id, 'code' => $code, 'description' => $desc, 'type' => $type, 'ar_account' => $arA, 'income_account' => $inc,
                'cogs_account' => $cg, 'asset_account' => $as, 'item_id' => $it, 'cost_price' => $cost, 'sales_price' => $price, 'allow_member_discount' => $disc,
                'modified_by' => $adminId ?: null, 'created_at' => $now, 'updated_at' => $now]);
        }
        $done[] = 'bill codes: ' . implode(', ', array_column($codes, 1));

        // ---- 6. water billing settings (the keys the water pages read, with the accounts above)
        $settings = [
            'water_billing_bill_code_id' => ['1', 'Bill code used by Water Billing'],
            'water_billing_default_ar_account_id' => [(string)$ar, 'Default water A/R account'],
            'water_billing_default_income_account_id' => [(string)$sales, 'Default water income account'],
            'collection_penalty_account_id' => [(string)$penalty, 'Penalty income account'],
            'collection_compromise_account_id' => [(string)$compromise, 'Compromise income account'],
            'manual_penalty_enabled' => ['1', 'Cashier may enter a penalty'],
            'manual_penalty_amount' => ['0.00', 'Default penalty amount'],
            'manual_compromise_enabled' => ['1', 'Cashier may enter a compromise'],
            'manual_compromise_amount' => ['0.00', 'Default compromise amount'],
            'collection_penalty_mode' => ['none', 'Automatic penalty mode'],
            'collection_penalty_value' => ['0', 'Automatic penalty value'],
            'collection_penalty_grace_days' => ['0', 'Grace days before a penalty'],
            'collection_max_backdate_days' => ['31', 'How far back a collection may be dated'],
            'session_inactivity_hours' => ['24', 'Session inactivity limit of the water pages'],
            'recurring_scheduler_mode' => ['cli', 'Recurring billing scheduler'],
            'recurring_scheduler_command' => ['php process/runRecurringBilling.php --operator=OPERATOR_ID', 'Recurring billing command'],
            'accounting_asset_type' => ['1', 'Legacy account type: asset'],
            'accounting_liability_type' => ['2', 'Legacy account type: liability'],
            'accounting_equity_type' => ['3', 'Legacy account type: equity'],
            'accounting_income_type' => ['4', 'Legacy account type: income'],
            'accounting_expense_type' => ['5', 'Legacy account type: expense'],
        ];
        foreach ($settings as $k => [$v, $d]) {
            DB::run('INSERT INTO water_billing_settings (setting_key, setting_value, description, created_at, updated_at) VALUES (:k, :v, :d, :n1, :n2)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)', ['k' => $k, 'v' => $v, 'd' => $d, 'n1' => $now, 'n2' => $now]);
        }
        $done[] = 'water billing settings and collection policy';

        // ---- 7. the institution profile (the name on the water documents) from the company settings
        if (!(int)DB::value('SELECT COUNT(*) FROM bussinessprofile')) {
            $addr = trim(str_replace(["\r\n", "\n"], ', ', (string)Settings::get('company_address', '')));
            DB::insert('bussinessprofile', ['pkey' => 1, 'name' => mb_substr(Coop::coopName(), 0, 100), 'description' => 'Water Utility',
                'address1' => mb_substr($addr, 0, 100), 'address2' => '', 'telephoneNo' => mb_substr((string)Settings::get('company_phone', ''), 0, 20),
                'tinNo' => mb_substr((string)Settings::get('company_tin', ''), 0, 20), 'email' => mb_substr((string)Settings::get('company_email', ''), 0, 50),
                'IsBranch' => 0, 'dateCreated' => $now, 'createdBy' => 'system', 'isdeleted' => 0]);
            $done[] = 'institution profile: ' . Coop::coopName();
        }

        // ---- 8. Data Import Management, ready for the former program's two files (Account Master, Billing Master)
        $done = array_merge($done, seed_water_import_setup($ar, $fees, $materials, $adminId, $now));
        return $done;
    });
}

/**
 * The set-up Data Import Management needs for the former water program's Account Master and Billing Master files:
 *   - the importer's mapping decisions at their documented defaults (the general-ledger policy: opening balances at the
 *     cut-over), so nothing has to be decided before the first upload; each can be changed on the page;
 *   - the bill codes and recurring plans of the charges the Billing Master carries besides water (Share Capital,
 *     Damayan, Funos, meter installation; the reconnection fee uses RECONNECTION FEE), each crediting an account of its
 *     own: share capital, Damayan and Funos are held as liabilities until they are credited where they belong;
 *   - the legacy water balances clearing account that offsets the opening balances;
 *   - the mapping profile "Former water program files": the former program's rate codes A, B, C and D mapped to the same
 *     tariffs it used (A residential associate, B residential regular, C commercial associate, D commercial regular, all
 *     1/2 inch), SeniorMaster to the Senior Citizen discount, the charge columns to the plans above.
 * @return string[]
 */
function seed_water_import_setup(int $ar, int $fees, int $materials, int $adminId, string $now): array
{
    $done = [];
    require_once APP_ROOT . '/water/common/dataImportMappingHelpers.php';
    if ((int)DB::value('SELECT COUNT(*) FROM data_import_mapping_rules') === 0) {
        foreach (di_rule_catalog() as $key => $rule) {
            DB::insert('data_import_mapping_rules', ['rule_key' => $key, 'rule_value' => $rule['default'], 'status' => $rule['default_status'],
                'notes' => 'Default of the starter set-up', 'confirmed_by' => $rule['default_status'] === 'confirmed' ? ($adminId ?: null) : null,
                'confirmed_at' => $rule['default_status'] === 'confirmed' ? $now : null, 'updated_at' => $now, 'version' => 1]);
        }
        $done[] = 'Data Import Management: ' . count(di_rule_catalog()) . ' mapping decisions at their defaults (ledger policy: opening balances at the cut-over)';
    }
    WaterPosting::legacyClearingAccount();
    $done[] = 'account 39999 Legacy Water Balances Clearing (Module Default Accounts > Water > legacy balances clearing)';

    $liability = function (string $code, string $name, string $description) use (&$done): int {
        $id = DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => $code]);
        if ($id) {
            return (int)$id;
        }
        $done[] = "account $code $name";
        return DB::insert('accounts', ['code' => $code, 'name' => $name, 'account_type' => 'Other Current Liability', 'category' => 'Liability',
            'normal_balance' => 'Credit', 'description' => $description, 'with_sl' => 0, 'is_active' => 1, 'is_system' => 0]);
    };
    $share = $liability('2136', 'Share Capital Collected with Water Bills', 'Share capital collected from water consumers with their bills, held until it is credited to the member\'s share capital account');
    $damayan = $liability('2137', 'Damayan Contributions Payable', 'Damayan (mutual aid) contributions collected with water bills');
    $funos = $liability('2138', 'Funos Contributions Payable', 'Funos collected with water bills (a charge of the former water program), held until its treatment is decided');

    $planIds = [];
    $billCode = function (string $code, string $description, string $type, int $credit, float $price) use ($ar, $adminId, $now): int {
        $id = DB::value('SELECT code_id FROM bill_codes WHERE code = :c', ['c' => $code]);
        if ($id) {
            return (int)$id;
        }
        return DB::insert('bill_codes', ['code' => $code, 'description' => $description, 'type' => $type, 'ar_account' => $ar, 'income_account' => $credit,
            'cogs_account' => null, 'asset_account' => null, 'item_id' => null, 'cost_price' => 0, 'sales_price' => $price, 'allow_member_discount' => 0,
            'modified_by' => $adminId ?: null, 'created_at' => $now, 'updated_at' => $now]);
    };
    $plan = function (int $codeId, float $amount) use ($adminId, $now): int {
        $id = DB::value("SELECT plan_id FROM recurring_plans WHERE bill_code_id = :c AND status = 'active' ORDER BY plan_id LIMIT 1", ['c' => $codeId]);
        if ($id) {
            return (int)$id;
        }
        return DB::insert('recurring_plans', ['bill_code_id' => $codeId, 'amount' => $amount, 'frequency' => 'Monthly', 'first_billing_date' => date('Y-m-d'),
            'term_days' => 15, 'status' => 'active', 'created_by' => $adminId ?: 1, 'created_at' => $now, 'updated_at' => $now, 'disconnection_days' => 0]);
    };
    $charges = [
        'Share Capital' => ['SHARE CAPITAL', 'Share capital contribution collected with the water bill', 'Charges', $share, 50],
        'DamayanContribution' => ['DAMAYAN', 'Damayan (mutual aid) contribution collected with the water bill', 'Charges', $damayan, 50],
        'Funos' => ['FUNOS', 'Funos collected with the water bill (former program charge)', 'Charges', $funos, 70],
        'Meter Instal Amt' => ['METER INSTALLATION', 'Meter installation amount billed with the water bill', 'Service', $materials, 400],
        'Recon Fee' => ['RECONNECTION FEE', 'Reconnection after a disconnection', 'Service', $fees, 300],
    ];
    foreach ($charges as $column => [$code, $description, $type, $credit, $amount]) {
        $planIds[$column] = $plan($billCode($code, $description, $type, $credit, $amount), $amount);
    }
    $done[] = 'bill codes and recurring plans for the Billing Master charges: ' . implode(', ', array_column($charges, 0));

    if (!(int)DB::value("SELECT COUNT(*) FROM data_import_mapping_profiles WHERE profile_name = 'Former water program files'")) {
        $options = [
            'rate_map' => ['A' => 2, 'B' => 1, 'C' => 6, 'D' => 5],
            'pipe_map' => ['A' => 2, 'B' => 2, 'C' => 2, 'D' => 2],
            'default_pipe_size_id' => 2, 'address_mode' => 'source', 'default_city_id' => 0, 'default_zip' => 0,
            'source_discount_id' => 0,
            'senior_discount_id' => (int)(DB::value("SELECT discount_id FROM discounts WHERE discount_type = 'Senior Citizen'") ?: 0),
            // the class of the imported bills and collections: the first active class (changeable on the import page)
            'default_class_id' => (int)(Classes::ready() ? (DB::value('SELECT id FROM classes WHERE is_active = 1 ORDER BY sort_order, name LIMIT 1') ?: 0) : 0),
            'stage_payments' => true, 'recurring_map' => $planIds, 'existing_account_mode' => 'skip',
        ];
        DB::insert('data_import_mapping_profiles', ['profile_name' => 'Former water program files', 'import_type' => 'any', 'version' => 1,
            'options_json' => json_encode($options, JSON_UNESCAPED_SLASHES), 'created_by' => $adminId ?: null, 'created_at' => $now]);
        $done[] = 'mapping profile "Former water program files" (rate codes A-D, senior discount, charge plans)';
    }
    return $done;
}
