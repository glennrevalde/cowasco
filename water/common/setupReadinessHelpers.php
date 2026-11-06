<?php
/**
 * common/setupReadinessHelpers.php
 *
 * ONE readiness evaluator for the FORCE CONFIGURATION tutorial and for the
 * business mutation guard. Both the UI (common/setupTutorial.php,
 * process/getSetupReadiness.php) and every guarded process endpoint consume
 * the same rules, so a stale screen can never let a blocked action through:
 * the guard re-evaluates immediately before the transaction.
 *
 * Readiness is DERIVED from the current configuration every time. Nothing in
 * setup_tutorial_progress counts as completion; that table only stores which
 * tutorial screens an operator has already seen.
 *
 * Requirement keys are stable identifiers. Each requirement returns:
 *   key, title, group ('technical'|'institution'|'operator'|'module'),
 *   optional (bool), status ('ready'|'blocked'|'unknown'), message,
 *   destination (relative business page URL, or '' when no UI exists),
 *   destination_page_key, focus (CSS selector to spotlight), depends_on [],
 *   details [] (human-readable evidence), can_configure (bool for the
 *   current operator), admin_only (bool: no UI exists / read-only prerequisite)
 *
 * Nothing here creates accounts, mappings, fees, discounts or profiles.
 */

require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/userProfileHelpers.php';

if (!function_exists('sr_table_exists')) {
    function sr_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return $cache[$table] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cache[$table] = false;
        }
    }
}

if (!function_exists('sr_column_exists')) {
    function sr_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) return $cache[$key];
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return $cache[$key] = (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('sr_scalar')) {
    function sr_scalar(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}

if (!function_exists('sr_setting')) {
    function sr_setting(PDO $pdo, string $key): ?string
    {
        if (!sr_table_exists($pdo, 'water_billing_settings')) return null;
        $value = sr_scalar($pdo, 'SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1', [$key]);
        return $value === false || $value === null ? null : (string)$value;
    }
}

if (!function_exists('sr_active_account')) {
    function sr_active_account(PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0) return false;
        return (int)sr_scalar($pdo, 'SELECT COUNT(*) FROM accounts WHERE id = ? AND is_active = 1', [$accountId]) > 0;
    }
}

/* -------------------------------------------------------------------------
 * Requirement catalogue: order = tutorial order.
 * ---------------------------------------------------------------------- */
if (!function_exists('sr_requirement_catalog')) {
    function sr_requirement_catalog(): array
    {
        return [
            'technical' => [
                'title' => 'Technical prerequisites',
                'group' => 'technical',
                'optional' => false,
                'destination' => 'import_management.php',
                'destination_page_key' => 'import_management',
                'focus' => '.page-header',
                'depends_on' => [],
                'task' => 'Every business transaction',
                'admin_only' => true,
            ],
            'administrator' => [
                'title' => 'Authorized administrator available',
                'group' => 'technical',
                'optional' => false,
                'destination' => '',
                'destination_page_key' => '',
                'focus' => '',
                'depends_on' => ['technical'],
                'task' => 'Completing the remaining setup steps',
                'admin_only' => true,
            ],
            'institution' => [
                'title' => 'Institution and report identity',
                'group' => 'institution',
                'optional' => false,
                'destination' => 'institution_configuration.php',
                'destination_page_key' => 'institution_configuration',
                'focus' => 'form[action$="saveInstitutionConfiguration.php"]',
                'depends_on' => ['technical', 'administrator'],
                'task' => 'Printing bills, receipts, statements and notices',
                'admin_only' => false,
            ],
            'organization' => [
                'title' => 'Organizational scope',
                'group' => 'institution',
                'optional' => false,
                'destination' => 'organization_units.php',
                'destination_page_key' => 'organization_units',
                'focus' => '#branchesSection',
                'depends_on' => ['technical', 'administrator'],
                'task' => 'Posting bills, collections and journals',
                'admin_only' => false,
            ],
            'accounts' => [
                'title' => 'Chart of accounts and water account mapping',
                'group' => 'institution',
                'optional' => false,
                'destination' => 'chart_of_accounts.php',
                'destination_page_key' => 'chart_of_accounts',
                'focus' => '.page-header, h1',
                'depends_on' => ['technical', 'administrator'],
                'task' => 'Ledger posting for every water transaction',
                'admin_only' => false,
            ],
            'bill_codes' => [
                'title' => 'Bill codes and accounting',
                'group' => 'institution',
                'optional' => false,
                'destination' => 'bill_codes.php',
                'destination_page_key' => 'bill_codes',
                'focus' => '#billCodeForm',
                'depends_on' => ['accounts'],
                'task' => 'Water billing, one-time and installment billing',
                'admin_only' => false,
            ],
            'tariff' => [
                'title' => 'Water tariff setup',
                'group' => 'institution',
                'optional' => false,
                'destination' => 'water_meter.php?tab=rates',
                'destination_page_key' => 'water_meter',
                'focus' => '#createGroupForm',
                'depends_on' => ['bill_codes'],
                'task' => 'Reading entry and water bill computation',
                'admin_only' => false,
            ],
            'cash_collection' => [
                'title' => 'Cash collection setup (your cash account)',
                'group' => 'operator',
                'optional' => true,
                'destination' => 'profile_cash_gl_configuration.php',
                'destination_page_key' => 'profile_cash_gl_configuration',
                'focus' => 'form[action$="saveUserConfiguration.php"]',
                'depends_on' => ['accounts', 'bill_codes'],
                'task' => 'Collections and receipts',
                'admin_only' => false,
            ],
            'billing' => [
                'title' => 'Billing readiness',
                'group' => 'institution',
                'optional' => false,
                'destination' => 'bill_period.php',
                'destination_page_key' => 'bill_period',
                'focus' => 'form[action$="addBillPeriod.php"]',
                'depends_on' => ['tariff'],
                'task' => 'Recording readings and generating water bills',
                'admin_only' => false,
            ],
            'inventory' => [
                'title' => 'Inventory bill codes linked to the Item List',
                'group' => 'module',
                'optional' => true,
                'destination' => 'bill_codes.php',
                'destination_page_key' => 'bill_codes',
                'focus' => '#billCodeForm',
                'depends_on' => ['accounts'],
                'task' => 'Material issuance and inventory bill codes (one-time and installment sales of materials)',
                'admin_only' => false,
            ],
            'import' => [
                'title' => 'Import readiness',
                'group' => 'module',
                'optional' => true,
                'destination' => 'import_management.php',
                'destination_page_key' => 'import_management',
                'focus' => '#mappingDecisionsPanel',
                'depends_on' => ['bill_codes', 'tariff'],
                'task' => 'Importing Account Master and Billing Master history',
                'admin_only' => false,
            ],
        ];
    }
}

/* Which requirements each transactional action group needs. */
if (!function_exists('sr_action_requirements')) {
    function sr_action_requirements(): array
    {
        $core = ['technical', 'administrator', 'institution', 'organization', 'accounts', 'bill_codes'];
        return [
            'billing' => array_merge($core, ['tariff', 'billing']),
            'collection' => array_merge($core, ['tariff', 'cash_collection']),
            'inventory' => array_merge($core, ['inventory']),
            'accounting' => $core,
            'import' => array_merge($core, ['tariff', 'import']),
            'import_members' => ['technical', 'administrator', 'import'],
        ];
    }
}

/* Business pages that belong to an optional module (module tutorial starts there). */
if (!function_exists('sr_page_module')) {
    function sr_page_module(string $pageKey): ?string
    {
        $map = [
            'cash_collection' => ['water_bills_collection', 'other_payments_collection', 'other_payments', 'collection_management', 'payment_history', 'collection_corrections', 'collection_receipt', 'generate_official_receipt'],
            'inventory' => ['item_issuance'],
            'import' => ['import_management'],
        ];
        foreach ($map as $module => $pages) {
            if (in_array($pageKey, $pages, true)) return $module;
        }
        return null;
    }
}

/* Setup / recovery routes: never covered by the blocking overlay. */
if (!function_exists('sr_setup_route_allowlist')) {
    function sr_setup_route_allowlist(): array
    {
        return [
            'settings', 'institution_configuration', 'organization_units', 'branches', 'divisions', 'segments',
            'chart_of_accounts', 'book_types', 'bill_codes', 'water_meter', 'water_rates', 'rate_codes', 'rates_management',
            'bill_period', 'profile_cash_gl_configuration', 'user_configuration', 'collection_policy_configuration',
            'access_control', 'help_center', 'user_manual', 'profile', 'notifications', 'account_setup_required',
            'import_management', 'discounts', 'discount_management', 'discounted_members', 'discount_privilege_rules',
            'water_rate_discounts', 'member_list', 'recurring_bill',
        ];
    }
}

/* -------------------------------------------------------------------------
 * Individual checks. Each returns [status, message, details[]].
 * ---------------------------------------------------------------------- */
if (!function_exists('sr_check_technical')) {
    function sr_check_technical(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $e) { $version = ''; }
        $details[] = 'Database server version: ' . ($version !== '' ? $version : 'unknown');
        if ($version !== '' && version_compare(preg_replace('/[^0-9.].*$/', '', $version), '5.5.0', '<')) {
            $problems[] = 'MySQL 5.5 or newer is required (found ' . $version . ').';
        }
        foreach (['pdo_mysql', 'mbstring', 'json', 'session'] as $ext) {
            if (!extension_loaded($ext)) $problems[] = 'PHP extension ' . $ext . ' is not loaded.';
        }
        // the water tables plus the cooperative tables the water pages post to (one ledger, one inventory, one login)
        $requiredTables = ['water_accounts', 'bill_readings', 'bill_periods', 'bill_codes', 'payment_transactions', 'payment_transaction_items',
            'water_meter_members', 'water_pipe_sizes', 'rate_code', 'water_billing_settings', 'water_postings',
            'accounts', 'transactions', 'journal_lines', 'items', 'item_site_stock', 'inventory_movements', 'sites',
            'users', 'roles', 'user_roles', 'default_accounts', 'business_units'];
        $missing = [];
        foreach ($requiredTables as $table) if (!sr_table_exists($pdo, $table)) $missing[] = $table;
        if ($missing) $problems[] = 'Missing database tables: ' . implode(', ', $missing) . '. Apply database/schema.sql and the migration files (database/migrations/2026_12_01_water_billing.sql).';
        try {
            $engines = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME IN ('bill_readings','payment_transactions','transactions','journal_lines','inventory_movements','water_postings') AND ENGINE <> 'InnoDB'")->fetchAll(PDO::FETCH_COLUMN);
            if ($engines) $problems[] = 'Transactional tables must use InnoDB: ' . implode(', ', $engines) . '.';
        } catch (Throwable $e) {}
        $storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'imports';
        if (!is_dir($storage) || !is_writable($storage)) {
            $problems[] = 'storage/imports is missing or not writable by PHP (needed only for source imports).';
        } else {
            $details[] = 'storage/imports is writable.';
        }
        if (class_exists('WaterPosting')) {
            if (!WaterPosting::ready()) $problems[] = 'The water posting bridge table (water_postings) is not installed.';
            else $details[] = 'Water documents are numbered and posted by the ledger (WaterPosting).';
        }
        $installIssues = array_filter($problems, static fn($p) => strpos($p, 'storage/imports') === false);
        if ($problems) {
            return ['blocked', ($installIssues ? 'Installation problem (not a business setting): ' : 'Server configuration problem: ') . implode(' ', $problems), $details];
        }
        return ['ready', 'Database schema, PHP extensions, transaction support and protected storage are in place.', $details];
    }
}

if (!function_exists('sr_check_administrator')) {
    function sr_check_administrator(PDO $pdo): array
    {
        try {
            // an active user holding an administrator role (roles.is_admin) through user_roles
            $roleAdmins = (int)sr_scalar($pdo, "SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id AND r.is_admin = 1 WHERE u.status = 'active'");
            $sessionAdmin = 0;
            try {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                if ($uid > 0 && class_exists('Access') && Access::isAdmin($uid)) $sessionAdmin = 1;
            } catch (Throwable $e) { $sessionAdmin = 0; }
            $details = ['Active users with an administrator role: ' . $roleAdmins, 'The signed-in user has administrator authority: ' . ($sessionAdmin ? 'yes' : 'no')];
            if ($roleAdmins + $sessionAdmin <= 0) {
                return ['blocked', 'No active user holds an administrator role. This prerequisite is BLOCKED: this task does not create administrators, roles or logins. Assign an administrator role on System Administration > User Accounts with an already authorized account.', $details];
            }
            return ['ready', 'An active administrator exists.', $details];
        } catch (Throwable $e) {
            return ['unknown', 'Administrator availability could not be verified.', []];
        }
    }
}

if (!function_exists('sr_check_institution')) {
    function sr_check_institution(PDO $pdo): array
    {
        $helper = __DIR__ . '/institutionConfigurationHelpers.php';
        if (is_file($helper)) require_once $helper;
        $details = [];
        $problems = [];
        $profileName = '';
        if (sr_table_exists($pdo, 'bussinessprofile')) {
            $profileName = trim((string)sr_scalar($pdo, "SELECT name FROM bussinessprofile WHERE COALESCE(isdeleted,0) = 0 ORDER BY COALESCE(IsBranch,0) ASC, pkey ASC LIMIT 1"));
        }
        if ($profileName === '') $problems[] = 'No saved institution (business profile) name exists.';
        else $details[] = 'Institution: ' . $profileName;

        if (function_exists('institution_current_officers')) {
            $officers = institution_current_officers($pdo);
            foreach ($officers as $key => $officer) {
                if (empty($officer['found']) || trim((string)$officer['name']) === '') {
                    $problems[] = 'Report signatory "' . (string)$officer['position'] . '" has not been saved (fallback sample names never count as configured).';
                } else {
                    $details[] = (string)$officer['position'] . ': ' . (string)$officer['name'];
                }
            }
        } elseif (!sr_table_exists($pdo, 'companyofficers')) {
            $problems[] = 'The companyofficers table is missing.';
        }
        if ($problems) return ['blocked', implode(' ', $problems), $details];
        return ['ready', 'Institution identity and report signatories are saved.', $details];
    }
}

if (!function_exists('sr_check_organization')) {
    function sr_check_organization(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $branches = sr_table_exists($pdo, 'branches') ? (int)sr_scalar($pdo, 'SELECT COUNT(*) FROM branches WHERE COALESCE(Deleted,0) = 0') : 0;
            $divisions = sr_table_exists($pdo, 'ic_divisions') ? (int)sr_scalar($pdo, "SELECT COUNT(*) FROM ic_divisions WHERE status = 'A'") : 0;
            $segments = sr_table_exists($pdo, 'ic_segments') ? (int)sr_scalar($pdo, "SELECT COUNT(*) FROM ic_segments WHERE status = 'A'") : 0;
            $details[] = 'Active branches: ' . $branches . ', divisions: ' . $divisions . ', segments: ' . $segments;
            if ($branches <= 0) $problems[] = 'At least one active branch is required.';
            if ($divisions <= 0) $problems[] = 'At least one active division is required.';
            if ($segments <= 0) $problems[] = 'At least one active segment is required.';
        } catch (Throwable $e) {
            return ['unknown', 'Organization units could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems) . ' No sample unit is inserted automatically.', $details];
        return ['ready', 'Branch, division and segment references exist.', $details];
    }
}

if (!function_exists('sr_mapping_problems')) {
    /**
     * WaterPosting::mappingProblems() split for the readiness steps: 'bill_codes' (one entry per bill code problem)
     * and 'other' (default A/R, collection policy accounts, cashiers' cash accounts, the Water Utility unit).
     */
    function sr_mapping_problems(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $out = ['bill_codes' => [], 'collection' => [], 'other' => []];
        try {
            if (class_exists('WaterPosting')) {
                foreach (WaterPosting::mappingProblems() as $p) {
                    $what = (string)($p['what'] ?? '');
                    $text = $what . ': ' . (string)($p['problem'] ?? '');
                    if (strpos($what, 'Bill code ') === 0) $out['bill_codes'][] = $text;
                    elseif (strpos($what, 'Collection policy') === 0) $out['collection'][] = $text;   // judged with the policy flags (cash_collection)
                    else $out['other'][] = $text;
                }
            }
        } catch (Throwable $e) {
            $out['other'][] = 'The water account mapping could not be checked: ' . $e->getMessage();
        }
        return $cache = $out;
    }
}

if (!function_exists('sr_check_accounts')) {
    function sr_check_accounts(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $active = (int)sr_scalar($pdo, 'SELECT COUNT(*) FROM accounts WHERE is_active = 1');
            $details[] = 'Active accounts in the chart of accounts: ' . $active;
            if ($active <= 0) $problems[] = 'The chart of accounts has no active account. Set it up on Chart of Accounts.';
            foreach (['Accounts Receivable' => 'receivable', 'Income' => 'income', 'Bank' => 'cash (bank)'] as $type => $label) {
                $n = (int)sr_scalar($pdo, 'SELECT COUNT(*) FROM accounts WHERE is_active = 1 AND account_type = ?', [$type]);
                if ($n <= 0) $problems[] = 'No active ' . $label . ' account exists in the chart of accounts.';
            }
            $mapping = sr_mapping_problems();
            foreach ($mapping['other'] as $m) $problems[] = $m . '.';
            if (!$mapping['other']) $details[] = 'Default water A/R, collection policy accounts, cashiers\' cash accounts and the Water Utility unit are mapped to active accounts.';
            $details[] = 'Documents are numbered by the ledger (bill numbers, official receipt numbers); no separate voucher sequence is needed.';
        } catch (Throwable $e) {
            return ['unknown', 'Accounts and mappings could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems), $details];
        return ['ready', 'Active posting accounts exist and the water account mapping points at active accounts.', $details];
    }
}

if (!function_exists('sr_bill_code_accounting_ok')) {
    function sr_bill_code_accounting_ok(PDO $pdo, array $code): array
    {
        $missing = [];
        if (!sr_active_account($pdo, (int)($code['ar_account'] ?? 0))) $missing[] = 'active AR/debit account';
        if (!sr_active_account($pdo, (int)($code['income_account'] ?? 0))) $missing[] = 'active Income/credit account';
        if (strcasecmp((string)($code['type'] ?? ''), 'Inventory') === 0) {
            // the stock, the cost and the inventory accounts come from the item of the Item List the code sells
            $itemId = (int)($code['item_id'] ?? 0);
            if ($itemId <= 0) {
                $missing[] = 'link to an item of the Item List';
            } else {
                $item = null;
                try {
                    $stmt = $pdo->prepare('SELECT id, code, item_type, is_active, asset_account_id, cogs_account_id FROM items WHERE id = ? LIMIT 1');
                    $stmt->execute([$itemId]);
                    $item = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) { $item = null; }
                if (!$item) $missing[] = 'item #' . $itemId . ' (missing from the Item List)';
                elseif ((int)$item['is_active'] !== 1) $missing[] = 'active item (' . $item['code'] . ' is inactive)';
                elseif (!in_array((string)$item['item_type'], ['inventory', 'assembly'], true)) $missing[] = 'stocked item (' . $item['code'] . ' is not an inventory item)';
                else {
                    if (!sr_active_account($pdo, (int)($item['asset_account_id'] ?? 0))) $missing[] = 'active inventory asset account on item ' . $item['code'];
                    if (!sr_active_account($pdo, (int)($item['cogs_account_id'] ?? 0))) $missing[] = 'active COGS account on item ' . $item['code'];
                }
            }
        }
        return $missing;
    }
}

if (!function_exists('sr_check_bill_codes')) {
    function sr_check_bill_codes(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $waterId = (int)(sr_setting($pdo, 'water_billing_bill_code_id') ?? 0);
            $water = null;
            if ($waterId > 0) {
                $stmt = $pdo->prepare('SELECT * FROM bill_codes WHERE code_id = ? LIMIT 1');
                $stmt->execute([$waterId]);
                $water = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$water) {
                $stmt = $pdo->query("SELECT * FROM bill_codes WHERE UPPER(TRIM(code)) = 'WATER BILL' ORDER BY code_id LIMIT 1");
                $water = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$water) {
                $problems[] = 'The WATER BILL bill code does not exist.';
            } else {
                $missing = sr_bill_code_accounting_ok($pdo, $water);
                if ($missing) $problems[] = 'WATER BILL is missing: ' . implode(', ', $missing) . '.';
                else $details[] = 'WATER BILL code #' . (int)$water['code_id'] . ' has active AR and Income accounts.';
                if ($waterId !== (int)$water['code_id']) $problems[] = 'water_billing_settings.water_billing_bill_code_id does not point at the WATER BILL code; save the Water Billing bill code selection.';
            }
            // the other bill codes are reported, not blocking: each one blocks only its own bills when used
            $others = sr_mapping_problems()['bill_codes'];
            if ($others) $details[] = 'Bill codes with mapping problems (fix on Bill Codes): ' . implode('; ', array_slice($others, 0, 6)) . (count($others) > 6 ? '; ...' : '');
        } catch (Throwable $e) {
            return ['unknown', 'Bill codes could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems), $details];
        return ['ready', 'Required WATER BILL configuration and its debit/credit accounts are valid.', $details];
    }
}

if (!function_exists('sr_check_tariff')) {
    function sr_check_tariff(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        $helper = __DIR__ . '/waterRateBillingHelpers.php';
        if (is_file($helper)) require_once $helper;
        try {
            $rateCodes = (int)sr_scalar($pdo, 'SELECT COUNT(*) FROM rate_code');
            if ($rateCodes <= 0) $problems[] = 'No rate code exists.';
            $groups = $pdo->query('SELECT rc_id, pipe_size_id FROM water_meter_members GROUP BY rc_id, pipe_size_id')->fetchAll(PDO::FETCH_ASSOC);
            $valid = 0; $invalid = [];
            foreach ($groups as $group) {
                $tiers = $pdo->prepare('SELECT * FROM water_meter_members WHERE rc_id = ? AND pipe_size_id = ? ORDER BY from_cb');
                $tiers->execute([(int)$group['rc_id'], (int)$group['pipe_size_id']]);
                $rows = $tiers->fetchAll(PDO::FETCH_ASSOC);
                $status = function_exists('water_rate_get_configuration_status') ? water_rate_get_configuration_status($rows) : ['valid' => count($rows) > 0, 'message' => ''];
                if (!empty($status['valid'])) $valid++;
                else $invalid[] = 'rate #' . (int)$group['rc_id'] . ' / pipe #' . (int)$group['pipe_size_id'] . ' (' . (string)($status['message'] ?? 'invalid') . ')';
            }
            $details[] = 'Valid tariff groups: ' . $valid . ' of ' . count($groups);
            if (!$groups) $problems[] = 'No rate code + pipe size tariff group has been configured.';
            // Groups actually assigned to active members must pass validation.
            $used = $pdo->query("SELECT DISTINCT m.rc_id, m.pipe_size_id FROM water_accounts m WHERE m.status = 'A' AND m.rc_id IS NOT NULL AND m.rc_id > 0")->fetchAll(PDO::FETCH_ASSOC);
            $usedInvalid = [];
            foreach ($used as $u) {
                $tiers = $pdo->prepare('SELECT * FROM water_meter_members WHERE rc_id = ? AND pipe_size_id = ? ORDER BY from_cb');
                $tiers->execute([(int)$u['rc_id'], (int)$u['pipe_size_id']]);
                $rows = $tiers->fetchAll(PDO::FETCH_ASSOC);
                $status = function_exists('water_rate_get_configuration_status') ? water_rate_get_configuration_status($rows) : ['valid' => count($rows) > 0];
                if (empty($status['valid'])) $usedInvalid[] = 'rate #' . (int)$u['rc_id'] . ' / pipe #' . (int)$u['pipe_size_id'];
            }
            if ($usedInvalid) $problems[] = 'Tariff groups used by active members are incomplete: ' . implode(', ', array_slice($usedInvalid, 0, 6)) . '.';
            elseif ($invalid) $details[] = 'Unused incomplete groups: ' . implode(', ', array_slice($invalid, 0, 4));
        } catch (Throwable $e) {
            return ['unknown', 'Tariff configuration could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems) . ' The water calculation itself is unchanged.', $details];
        return ['ready', 'Required rate-code / pipe-size tariff groups pass completeness and overlap validation.', $details];
    }
}

if (!function_exists('sr_check_cash_collection')) {
    function sr_check_cash_collection(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $operatorId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
            if ($operatorId <= 0 && class_exists('Auth')) {
                try { $operatorId = (int)(Auth::id() ?? 0); } catch (Throwable $e) { $operatorId = 0; }
            }
            if ($operatorId <= 0) {
                return ['blocked', 'No signed-in user is available for this session.', $details];
            }
            // the cashier's cash account: users.coop_cash_account_id (User Accounts), else the water default cash account
            $cash = 0;
            try { $cash = (int)(uph_current_cash_account_id($pdo, $operatorId) ?: 0); } catch (Throwable $e) { $cash = 0; }
            $own = (int)sr_scalar($pdo, 'SELECT COALESCE(coop_cash_account_id, 0) FROM users WHERE id = ?', [$operatorId]);
            if ($cash <= 0) $problems[] = 'Your user account has no cash account for collections (System Administration > User Accounts) and no default water cash account is mapped (Module Default Accounts > Water).';
            elseif (!sr_active_account($pdo, $cash)) $problems[] = 'Your collection cash account #' . $cash . ' is missing or inactive in the Chart of Accounts.';
            else $details[] = 'Collection cash account #' . $cash . ' is active' . ($own > 0 ? ' (set on your user account).' : ' (the default water cash account).');

            if ((string)(sr_setting($pdo, 'manual_penalty_enabled') ?? '0') === '1') {
                $acct = (int)(sr_setting($pdo, 'collection_penalty_account_id') ?? 0);
                if ($acct <= 0 && class_exists('WaterPosting')) $acct = (int)(WaterPosting::defaultAccount('water_penalty') ?: 0);
                if (!sr_active_account($pdo, $acct)) $problems[] = 'Manual Penalty is enabled but its Income account is missing or inactive (Collection Policy Configuration).';
                else $details[] = 'Penalty income account #' . $acct . ' is active.';
            } else {
                $details[] = 'Manual Penalty is disabled (no forced fee or automatic policy).';
            }
            if ((string)(sr_setting($pdo, 'manual_compromise_enabled') ?? '0') === '1') {
                $acct = (int)(sr_setting($pdo, 'collection_compromise_account_id') ?? 0);
                if ($acct <= 0 && class_exists('WaterPosting')) $acct = (int)(WaterPosting::defaultAccount('water_compromise') ?: 0);
                if (!sr_active_account($pdo, $acct)) $problems[] = 'Manual Compromise is enabled but its Income account is missing or inactive (Collection Policy Configuration).';
                else $details[] = 'Compromise income account #' . $acct . ' is active.';
            }
            $backdate = sr_setting($pdo, 'collection_max_backdate_days');
            if ($backdate !== null && !preg_match('/^\d+$/', $backdate)) $problems[] = 'collection_max_backdate_days is not a whole number.';
        } catch (Throwable $e) {
            return ['unknown', 'Cash collection configuration could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems), $details];
        return ['ready', 'Your collection cash account and the receivable mappings are valid; penalty/compromise settings are consistent.', $details];
    }
}

if (!function_exists('sr_check_billing')) {
    function sr_check_billing(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $open = (int)sr_scalar($pdo, "SELECT COUNT(*) FROM bill_periods WHERE status = 'open'");
            if ($open <= 0) $problems[] = 'No open billing period exists for new readings.';
            else $details[] = 'Open billing periods: ' . $open;
            $activeMembers = (int)sr_scalar($pdo, "SELECT COUNT(*) FROM water_accounts WHERE status = 'A'");
            $unmapped = (int)sr_scalar($pdo, "SELECT COUNT(*) FROM water_accounts WHERE status = 'A' AND (rc_id IS NULL OR rc_id <= 0)");
            $details[] = 'Active members: ' . $activeMembers . ' (without a rate code: ' . $unmapped . ')';
            if ($activeMembers > 0 && $unmapped === $activeMembers) $problems[] = 'No active member has a rate code / pipe size mapping yet (Water Service Accounts → rate code).';
        } catch (Throwable $e) {
            return ['unknown', 'Billing readiness could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems), $details];
        return ['ready', 'An open period exists and member tariff mappings are available.', $details];
    }
}

if (!function_exists('sr_check_inventory')) {
    function sr_check_inventory(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        try {
            $codes = $pdo->query("SELECT * FROM bill_codes WHERE type = 'Inventory'")->fetchAll(PDO::FETCH_ASSOC);
            if (!$codes) return ['blocked', 'No Inventory-type bill code exists. Create the bill code with its A/R and income accounts and link it to an item of the cooperative Item List (Inventory > Item List) before billing or issuing materials.', $details];
            foreach ($codes as $code) {
                $missing = sr_bill_code_accounting_ok($pdo, $code);
                if ($missing) $problems[] = (string)$code['code'] . ': ' . implode(', ', $missing);
            }
            $linked = 0;
            foreach ($codes as $code) if (!empty($code['item_id'])) $linked++;
            $details[] = 'Inventory bill codes: ' . count($codes) . ' (linked to an item: ' . $linked . ')';
            // the site the materials move from
            $sites = sr_table_exists($pdo, 'sites') ? (int)sr_scalar($pdo, 'SELECT COUNT(*) FROM sites WHERE is_active = 1') : 0;
            if ($sites <= 0) $problems[] = 'no active inventory site exists (Inventory > Inventory Site List)';
            else $details[] = 'Active inventory sites: ' . $sites;
        } catch (Throwable $e) {
            return ['unknown', 'Inventory bill codes could not be verified.', []];
        }
        if ($problems) return ['blocked', 'Inventory set-up is incomplete: ' . implode('; ', $problems) . '.', $details];
        return ['ready', 'Every inventory bill code is linked to an active item of the Item List with active inventory accounts, and an inventory site exists.', $details];
    }
}

if (!function_exists('sr_check_import')) {
    function sr_check_import(PDO $pdo): array
    {
        $details = [];
        $problems = [];
        $helper = __DIR__ . '/dataImportHelpers.php';
        try {
            if (is_file($helper)) require_once $helper;
            if (function_exists('di_schema_ready') && !di_schema_ready($pdo)) $problems[] = 'The importer tables are not installed.';
            elseif (function_exists('di_compatibility_issues')) {
                $issues = di_compatibility_issues($pdo);
                if ($issues) $problems[] = implode('; ', array_slice($issues, 0, 4));
            }
            if (function_exists('di_mapping_rules')) {
                $rules = di_mapping_rules($pdo);
                $unresolved = [];
                foreach ($rules as $key => $rule) if (($rule['status'] ?? '') === 'unresolved') $unresolved[] = (string)($rule['label'] ?? $key);
                $details[] = 'Mapping rules: ' . count($rules) . ' (unresolved: ' . count($unresolved) . ')';
                if ($unresolved) $details[] = 'Unresolved: ' . implode(', ', array_slice($unresolved, 0, 6));
            }
        } catch (Throwable $e) {
            return ['unknown', 'Import readiness could not be verified.', []];
        }
        if ($problems) return ['blocked', implode(' ', $problems), $details];
        return ['ready', 'Importer schema is compatible; unresolved financial rules keep their rows in review.', $details];
    }
}

/* -------------------------------------------------------------------------
 * Evaluation
 * ---------------------------------------------------------------------- */
if (!function_exists('sr_evaluate')) {
    /**
     * Evaluate every requirement (request-cached). $force bypasses the cache,
     * which the mutation guard uses immediately before a transaction.
     */
    function sr_evaluate(PDO $pdo, bool $force = false): array
    {
        static $cache = null;
        if ($cache !== null && !$force) return $cache;

        $catalog = sr_requirement_catalog();
        $checks = [
            'technical' => 'sr_check_technical',
            'administrator' => 'sr_check_administrator',
            'institution' => 'sr_check_institution',
            'organization' => 'sr_check_organization',
            'accounts' => 'sr_check_accounts',
            'bill_codes' => 'sr_check_bill_codes',
            'tariff' => 'sr_check_tariff',
            'cash_collection' => 'sr_check_cash_collection',
            'billing' => 'sr_check_billing',
            'inventory' => 'sr_check_inventory',
            'import' => 'sr_check_import',
        ];
        $result = [];
        $step = 0;
        foreach ($catalog as $key => $definition) {
            $step++;
            try {
                [$status, $message, $details] = call_user_func($checks[$key], $pdo);
            } catch (Throwable $e) {
                $status = 'unknown';
                $message = 'This requirement could not be evaluated.';
                $details = [];
            }
            $canConfigure = false;
            if ($definition['destination_page_key'] !== '') {
                try { $canConfigure = userCanAccessPage($pdo, (string)$definition['destination_page_key']); } catch (Throwable $e) { $canConfigure = false; }
            }
            $result[$key] = array_merge($definition, [
                'key' => $key,
                'step' => $step,
                'status' => $status,
                'message' => $message,
                'details' => $details,
                'can_configure' => $canConfigure,
            ]);
        }
        return $cache = $result;
    }
}

if (!function_exists('sr_core_keys')) {
    function sr_core_keys(): array
    {
        $keys = [];
        foreach (sr_requirement_catalog() as $key => $definition) if (empty($definition['optional'])) $keys[] = $key;
        return $keys;
    }
}

if (!function_exists('sr_summary')) {
    /**
     * Tutorial summary for a page: required steps (core + the page's module),
     * the first blocked step, and progress "Step X of N".
     */
    function sr_summary(PDO $pdo, string $pageKey = '', bool $force = false): array
    {
        $all = sr_evaluate($pdo, $force);
        $keys = sr_core_keys();
        $module = sr_page_module($pageKey);
        if ($module !== null && isset($all[$module])) $keys[] = $module;

        $steps = [];
        $completed = 0;
        $current = null;
        foreach ($keys as $index => $key) {
            $item = $all[$key];
            $item['position'] = $index + 1;
            $steps[] = $item;
            if ($item['status'] === 'ready') $completed++;
            elseif ($current === null) $current = $item;
        }
        $total = count($steps);
        return [
            'page_key' => $pageKey,
            'module' => $module,
            'total_steps' => $total,
            'completed_steps' => $completed,
            'current' => $current,
            'complete' => $current === null,
            'steps' => $steps,
            'is_setup_route' => in_array($pageKey, sr_setup_route_allowlist(), true),
            'evaluated_at' => date('c'),
        ];
    }
}

if (!function_exists('sr_block_for_action')) {
    /**
     * Return null when the action group may run, otherwise the structured
     * configuration_required payload of the first blocked requirement.
     */
    function sr_block_for_action(PDO $pdo, string $actionGroup): ?array
    {
        $map = sr_action_requirements();
        if (!isset($map[$actionGroup])) return null;
        $all = sr_evaluate($pdo, true);
        $keys = $map[$actionGroup];
        $total = count($keys);
        foreach ($keys as $index => $key) {
            $item = $all[$key] ?? null;
            if (!$item) continue;
            if ($item['status'] === 'ready') continue;
            $who = $item['can_configure'] ? 'Configure this first: ' : 'An administrator must complete ';
            return [
                'requirement_key' => $key,
                'title' => $item['title'],
                'status' => $item['status'],
                'step' => $index + 1,
                'total' => $total,
                'message' => $who . $item['title'] . '. ' . $item['message'] . ' This action (' . $item['task'] . ') is blocked until then.',
                'destination' => $item['can_configure'] && $item['destination'] !== '' ? '../views/' . $item['destination'] : '',
                'destination_page_key' => $item['destination_page_key'],
                'focus' => $item['focus'],
                'can_configure' => $item['can_configure'],
                'dependencies' => $item['depends_on'],
                'blocked_task' => $item['task'],
            ];
        }
        return null;
    }
}

/* -------------------------------------------------------------------------
 * Presentation progress (never counts as completion)
 * ---------------------------------------------------------------------- */
if (!function_exists('sr_progress_table_ready')) {
    function sr_progress_table_ready(PDO $pdo): bool
    {
        return sr_table_exists($pdo, 'setup_tutorial_progress');
    }
}

if (!function_exists('sr_progress_load')) {
    function sr_progress_load(PDO $pdo): array
    {
        if (!sr_progress_table_ready($pdo)) return [];
        $operatorId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
        if ($operatorId <= 0) return [];
        try {
            $stmt = $pdo->prepare('SELECT requirement_key, state, updated_at FROM setup_tutorial_progress WHERE operator_id = ?');
            $stmt->execute([$operatorId]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string)$row['requirement_key']] = $row;
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sr_progress_save')) {
    function sr_progress_save(PDO $pdo, string $requirementKey, string $state): bool
    {
        if (!sr_progress_table_ready($pdo)) return false;
        $operatorId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
        $profileId = (int)($_SESSION['operator_profile_id'] ?? $_SESSION['active_operator_profile_id'] ?? 0);
        if ($operatorId <= 0) return false;
        if (!in_array($state, ['intro_seen', 'step_seen', 'step_opened'], true)) return false;
        try {
            $stmt = $pdo->prepare('INSERT INTO setup_tutorial_progress (operator_id, operator_profile_id, requirement_key, state, first_seen_at, updated_at) VALUES (?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE state = VALUES(state), operator_profile_id = VALUES(operator_profile_id), updated_at = NOW()');
            $stmt->execute([$operatorId, $profileId, substr($requirementKey, 0, 40), $state]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
