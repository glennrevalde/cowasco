<?php
declare(strict_types=1);

/**
 * Shared by tools/migrate_water.php and tools/rollback_water.php: the table map of the former water-billing
 * database, the column meta-data helpers and the small reporting helpers. Everything here reads the source
 * database and never writes to it.
 *
 * MySQL 5.5 compatible SQL only (information_schema, plain INSERT / DELETE, no CTEs, no window functions).
 */

/** Legacy page keys whose job moved to a cooperative page (the water key is retired, the right is carried over) */
const WM_MOVED_PAGES = [
    'journal_entry'                 => 'rpt.journal',
    'chart_of_accounts'             => 'coa.accounts',
    'general_ledger'                => 'rpt.general_ledger',
    'trial_balance'                 => 'rpt.trial_balance',
    'balance_sheet'                 => 'rpt.balance_sheet',
    'income_statement'              => 'rpt.income_statement',
    'accounts_payable'              => 'vend.pay_bills',
    'inventory_purchase'            => 'vend.receive_bill',
    'inventory_adjustment'          => 'inv.adjust',
    'inventory_items'               => 'inv.items',
    'inventory_history'             => 'rpt.inv_movement_detail',
    'stock_monitoring'              => 'rpt.stock_status_item',
    'access_control'                => 'admin.page_access',
    'user_configuration'            => 'admin.users',
    'profile_cash_gl_configuration' => 'admin.users',
    'subsidiary_ledger_report'      => 'rpt.subsidiary_ledger',
    'book_types'                    => 'coa.lists',
    'accounting_management'         => 'home.reports',
    'inventory_management'          => 'inv.center',
];

/**
 * The tables copied row for row, in copy order, grouped by phase.
 * phase => [source table => [target table, options]]
 *   users    : integer columns holding a legacy operator id, remapped through water_user_map
 *   accounts : integer columns holding a legacy chart-of-accounts id, remapped through water_coa_map
 * Tables not listed are not migrated (users, user_profiles, user_sessions, session, password_resets,
 * operator_password_reset_requests, the views and the *_backup_* tables): nothing in them is used by the integrated
 * system (logins and sessions are the cooperative's; the views are recomputed from the copied tables).
 */
const WM_TABLES = [
    'reference' => [
        'cities'                   => ['cities', []],
        'addresstype'              => ['addresstype', []],
        'addresses'                => ['addresses', []],
        'memberaddress'            => ['memberaddress', []],
        'contactchannels'          => ['contactchannels', []],
        'membercontactchannels'    => ['membercontactchannels', []],
        'bussinessprofile'         => ['bussinessprofile', []],
        'companyofficers'          => ['companyofficers', []],
        'branches'                 => ['branches', []],
        'ic_divisions'             => ['ic_divisions', []],
        'ic_segments'              => ['ic_segments', []],
        'rate_code'                => ['rate_code', []],
        'water_pipe_sizes'         => ['water_pipe_sizes', []],
        'water_meter_discounts'    => ['water_meter_discounts', []],
        'discounts'                => ['discounts', []],
        'discount_privilege_rules' => ['discount_privilege_rules', []],
        'water_rates'              => ['water_rates', []],
        'water_meter_members'      => ['water_meter_members', []],
        'syssequence'              => ['syssequence', []],
        'global_billing_sequence'  => ['global_billing_sequence', []],
        'jointmember'              => ['jointmember', []],
        'membershipinfo'           => ['membershipinfo', []],
    ],
    'bill_codes' => [
        'bill_codes'                   => ['bill_codes', ['users' => ['modified_by'], 'accounts' => ['ar_account', 'income_account', 'cogs_account', 'asset_account']]],
        'water_billing_settings'       => ['water_billing_settings', []],       // *_account_id values remapped by the phase itself
        'bill_periods'                 => ['bill_periods', ['users' => ['opened_by', 'closed_by']]],
        'data_import_batches'          => ['data_import_batches', ['users' => ['created_by']]],
        'data_import_mapping_profiles' => ['data_import_mapping_profiles', ['users' => ['created_by']]],
        'data_import_mapping_rules'    => ['data_import_mapping_rules', ['users' => ['confirmed_by']]],
        'data_import_rows'             => ['data_import_rows', []],
        'setup_tutorial_progress'      => ['setup_tutorial_progress', ['users' => ['operator_id']]],
        'operator_notification_reads'  => ['operator_notification_reads', ['users' => ['operator_id']]],
    ],
    'accounts' => [
        'members'                        => ['water_accounts', ['users' => ['disconnected_by', 'reconnected_by']]],
        'discounted_members'             => ['discounted_members', []],
        'water_meter_discounted_members' => ['water_meter_discounted_members', []],
        'manual_disconnection_logs'      => ['manual_disconnection_logs', ['users' => ['action_by']]],
    ],
    // the legacy cooperative savings register the former database carries: read-only history (the balances reach the
    // cooperative savings module only through --phase=membership --import-membership)
    'membership' => [
        'savingstranscode'      => ['legacy_savingstranscode', []],
        'savingsaccounts'       => ['legacy_savingsaccounts', []],
        'savaccnttransaction'   => ['legacy_savaccnttransaction', []],
        'savingsinterest'       => ['legacy_savingsinterest', []],
        'savingsaccount_param'  => ['legacy_savingsaccount_param', []],
        'savingsrestrictions'   => ['legacy_savingsrestrictions', []],
        'savrestrictiontype'    => ['legacy_savrestrictiontype', []],
    ],
    'history' => [
        'bill_readings'             => ['bill_readings', ['users' => ['deleted_by', 'last_bill_edited_by']]],
        'one_time_bills'            => ['one_time_bills', ['users' => ['created_by', 'deleted_by', 'last_bill_edited_by']]],
        'installment_bills'         => ['installment_bills', ['users' => ['deleted_by', 'last_bill_edited_by']]],
        'installment_schedules'     => ['installment_schedules', ['users' => ['marked_by']]],
        'recurring_plans'           => ['recurring_plans', ['users' => ['created_by']]],
        'recurring_subscriptions'   => ['recurring_subscriptions', ['users' => ['created_by']]],
        'recurring_bills'           => ['recurring_bills', ['users' => ['created_by', 'deleted_by', 'last_bill_edited_by']]],
        'recurring_bill_payments'   => ['recurring_bill_payments', ['users' => ['created_by']]],
        'payment_transactions'      => ['payment_transactions', ['users' => ['cashier_id', 'reversed_by']]],
        'payment_transaction_items' => ['payment_transaction_items', []],
        'payment_adjustments'       => ['payment_adjustments', ['users' => ['created_by']]],
        'bill_adjustments'          => ['bill_adjustments', ['users' => ['created_by']]],
        'inventory_issuances'       => ['inventory_issuances', ['users' => ['created_by', 'reversed_by'], 'accounts' => ['expense_account_id']]],
        'inventory_issuance_items'  => ['inventory_issuance_items', ['accounts' => ['asset_account']]],
        // the former accounting, inventory, supplier and access tables: read-only history
        'journalentries_copy1'      => ['legacy_journalentries', []],
        'journalvoucher_copy1'      => ['legacy_journalvoucher', []],
        'journal_voucher_voids'     => ['legacy_journal_voucher_voids', ['users' => ['voided_by']]],
        'subsidiaryledger'          => ['legacy_subsidiaryledger', []],
        'postingmethod'             => ['legacy_postingmethod', []],
        'book_types'                => ['legacy_book_types', []],
        'accounts_payable'          => ['legacy_accounts_payable', ['users' => ['created_by']]],
        'accounts_payable_payments' => ['legacy_accounts_payable_payments', ['users' => ['created_by']]],
        'inventory_purchases'       => ['legacy_inventory_purchases', ['users' => ['created_by']]],
        'inventory_purchase_items'  => ['legacy_inventory_purchase_items', []],
        'inventory_stock_movements' => ['legacy_inventory_stock_movements', ['users' => ['created_by']]],
        'inventory_adjustments'     => ['legacy_inventory_adjustments', ['users' => ['created_by']]],
        'inventory_reorder_levels'  => ['legacy_inventory_reorder_levels', ['users' => ['updated_by']]],
        'suppliers'                 => ['legacy_suppliers', []],
        'operator'                  => ['legacy_operator', []],
        'profile'                   => ['legacy_profile', []],
        'operator_profile'          => ['legacy_operator_profile', []],
        'operator_profile_page_permissions' => ['legacy_operator_profile_page_permissions', []],
        'access_control_pages'      => ['legacy_access_control_pages', []],
        'access_control_presets'    => ['legacy_access_control_presets', []],
        'access_control_preset_pages' => ['legacy_access_control_preset_pages', []],
        'chartofaccntstbl'          => ['legacy_chartofaccntstbl', []],
        'audit_logs'                => ['water_audit_logs', ['users' => ['user_id']]],
    ],
];

/** Every source => target pair of WM_TABLES, in copy order */
function wm_all_tables(): array
{
    $out = [];
    foreach (WM_TABLES as $phase => $tables) {
        foreach ($tables as $src => [$dst]) {
            $out[$src] = $dst;
        }
    }
    return $out;
}

/** column => [data_type, nullable(bool), column_default, extra] of a table in a database on this server */
function wm_columns(PDO $pdo, string $db, string $table): array
{
    $st = $pdo->prepare('SELECT column_name, data_type, is_nullable, column_default, extra FROM information_schema.columns
                          WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
    $st->execute([$db, $table]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c = array_change_key_case($c, CASE_LOWER);
        $out[$c['column_name']] = [strtolower((string)$c['data_type']), strtoupper((string)$c['is_nullable']) === 'YES', $c['column_default'], (string)$c['extra']];
    }
    return $out;
}

/** The primary key columns of a table, in order */
function wm_pk(PDO $pdo, string $db, string $table): array
{
    $st = $pdo->prepare("SELECT column_name FROM information_schema.key_column_usage
                          WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY' ORDER BY ordinal_position");
    $st->execute([$db, $table]);
    return array_map(fn($r) => array_change_key_case($r, CASE_LOWER)['column_name'], $st->fetchAll(PDO::FETCH_ASSOC));
}

function wm_table_exists(PDO $pdo, string $db, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $st->execute([$db, $table]);
    return (int)$st->fetchColumn() > 0;
}

/** "a|b|c" of the primary key values of a row */
function wm_pk_key(array $row, array $pk): string
{
    $parts = [];
    foreach ($pk as $c) {
        $parts[] = (string)($row[$c] ?? '');
    }
    return implode('|', $parts);
}

function wm_is_zero_date($v): bool
{
    return is_string($v) && (str_starts_with($v, '0000-00-00') || $v === '0000');
}

/** A fixed-width text table for the console (and pasteable into the reconciliation report as a code block) */
function wm_table(array $head, array $rows): string
{
    $w = array_map('mb_strlen', $head);
    foreach ($rows as $r) {
        foreach (array_values($r) as $i => $v) {
            $w[$i] = max($w[$i] ?? 0, mb_strlen((string)$v));
        }
    }
    $line = function (array $cells) use ($w): string {
        $out = [];
        foreach (array_values($cells) as $i => $v) {
            $v = (string)$v;
            $out[] = is_numeric($v) || preg_match('/^-?[\d,]+\.\d\d$/', $v) ? str_pad($v, $w[$i], ' ', STR_PAD_LEFT) : str_pad($v, $w[$i]);
        }
        return '| ' . implode(' | ', $out) . ' |';
    };
    $sep = '|' . implode('|', array_map(fn($n) => str_repeat('-', $n + 2), $w)) . '|';
    $s = $line($head) . "\n" . $sep . "\n";
    foreach ($rows as $r) {
        $s .= $line($r) . "\n";
    }
    return $s;
}

function wm_money($v): string
{
    return number_format((float)$v, 2, '.', ',');
}

function wm_out(string $msg = ''): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function wm_err(string $msg): void
{
    fwrite(STDERR, $msg . PHP_EOL);
}

/**
 * The chart-of-accounts crosswalk the accountant reviewed: a CSV of legacy_accnt_id,account_code[,note]. A line maps a
 * former account to an account the target chart already has (instead of importing it). Lines starting with # and
 * a header line are ignored. Returns [legacy id => target account code].
 */
function wm_read_coa_map(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException("The crosswalk file $file was not found.");
    }
    $out = [];
    $fh = fopen($file, 'r');
    // the crosswalk a run writes has a map_to_code column for the reviewer; a hand-made file is legacy_id,code
    $target = 1;
    while (($row = fgetcsv($fh)) !== false) {
        $lower = array_map(fn($c) => strtolower(trim((string)$c)), $row);
        if (in_array('map_to_code', $lower, true)) {
            $target = (int)array_search('map_to_code', $lower, true);
            continue;
        }
        $a = trim((string)($row[0] ?? ''));
        $b = trim((string)($row[$target] ?? ''));
        if ($a === '' || $a[0] === '#' || !ctype_digit($a)) {
            continue;          // blank, comment or header
        }
        if ($b === '') {
            continue;          // no target: the account is imported as usual
        }
        $out[(int)$a] = $b;
    }
    fclose($fh);
    return $out;
}

/** Writes rows as a CSV file (header first) and returns the path, or '' when the folder cannot be written */
function wm_write_csv(string $path, array $header, array $rows): string
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $fh = @fopen($path, 'w');
    if (!$fh) {
        return '';
    }
    fputcsv($fh, $header);
    foreach ($rows as $r) {
        fputcsv($fh, array_values($r));
    }
    fclose($fh);
    return $path;
}

/** Opens the former water database read-only (never written to) */
function wm_open_source(string $name): PDO
{
    if (!DB::validName($name)) {
        throw new RuntimeException('Invalid source database name.');
    }
    if (!wm_table_exists(DB::connect(null), $name, 'members')) {
        throw new RuntimeException("The source database `$name` was not found on this server or has no `members` table.");
    }
    $pdo = DB::connect($name);
    // the water program's own (non-strict) mode for reading; zero dates come back as text, not as errors
    $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    foreach (['SET SESSION TRANSACTION READ ONLY', 'SET SESSION tx_read_only = 1'] as $sql) {
        try {
            $pdo->exec($sql);
            break;
        } catch (Throwable $e) {
            // MySQL 5.5 has no read-only sessions; the tool never writes to the source anyway
        }
    }
    return $pdo;
}
