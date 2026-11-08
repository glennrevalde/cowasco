<?php
declare(strict_types=1);

/**
 * Knows which upgrade scripts (database/migrations) the program files need and which of them
 * have not been run on this database yet. index.php stops with instructions while any is pending,
 * so nobody works on a half-upgraded system.
 */
final class Schema
{
    private const FLAG = 'schema_ok_2027_04_18';

    /**
     * file => one cheap probe.
     *   A string probe is pending when the query fails: "WHERE 1 = 0" reads no rows, but MySQL still
     *   checks that every table and column it names exists.
     *   An [sql, expected] probe is pending when the query fails OR does not give back what is expected;
     *   that is how a migration which only adds a row of data is recognised.
     */
    private const MIGRATIONS = [
        'database/migrations/2026_09_19_classes_cash_voucher.sql' =>
            'SELECT (SELECT COUNT(*) FROM classes WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM other_names WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transactions WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM transaction_lines WHERE 1 = 0)
                  + (SELECT COUNT(class_id) + COUNT(other_name_id) FROM journal_lines WHERE 1 = 0)
                  + (SELECT COUNT(show_in_pos) FROM payment_methods WHERE 1 = 0)
                  + (SELECT COUNT(class_id) FROM pos_terminals WHERE 1 = 0)',
        'database/migrations/2026_09_21_asset_maintenance_debit_memo_logo.sql' =>
            'SELECT (SELECT COUNT(*) FROM asset_locations WHERE 1 = 0)
                  + (SELECT COUNT(location_id) + COUNT(class_id) + COUNT(current_meter) FROM fixed_assets WHERE 1 = 0)
                  + (SELECT COUNT(txn_id) + COUNT(schedule_id) FROM asset_maintenance WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_parts WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_labor WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_maintenance_photos WHERE 1 = 0)
                  + (SELECT COUNT(next_due_meter) FROM asset_pm_schedules WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM asset_pm_log WHERE 1 = 0)',
        'database/migrations/2026_09_22_memorized_budgets_imports.sql' =>
            'SELECT (SELECT COUNT(*) FROM memorized_txns WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM budgets WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM budget_lines WHERE 1 = 0)
                  + (SELECT COUNT(options) FROM report_layouts WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM import_batches WHERE 1 = 0)
                  + (SELECT COUNT(*) FROM backup_log WHERE 1 = 0)',
        'database/migrations/2026_09_23_item_search_per_transaction.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key = \'item_search_by_page\'', 1],
        // the column has to exist (the query fails without it) and both report pages have to be there
        'database/migrations/2026_09_24_subsidiary_ledger.sql' =>
            ['SELECT 1 + (SELECT COUNT(with_sl) FROM accounts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.subsidiary_ledger\', \'rpt.schedule_of_accounts\'))', 3],
        // settings only: all six rows of the Bluetooth receipt printer have to be there
        'database/migrations/2026_09_25_bluetooth_receipt_printer.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key IN
                (\'bt_printer_enabled\', \'bt_printer_width\', \'bt_printer_name\', \'bt_printer_feed\', \'bt_printer_cut\', \'bt_printer_autoprint\')', 6],
        // the two columns have to be there (the query fails without them)
        'database/migrations/2026_09_26_pos_default_site_class.sql' =>
            'SELECT COUNT(pos_default_site_id) + COUNT(pos_default_class_id) FROM users WHERE 1 = 0',
        // data only: both Find pages have to be there
        'database/migrations/2026_09_27_find_report_design.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'home.find\', \'rpt.find\')', 2],
        // the approval columns of users have to be there, and the four company pages
        'database/migrations/2026_09_28_multi_company.sql' =>
            ['SELECT 4 + (SELECT COUNT(approval_status) + COUNT(created_via) + COUNT(approved_by) + COUNT(approved_at) + COUNT(rejected_reason) + COUNT(is_platform) FROM users WHERE 1 = 0)
                       - (SELECT COUNT(*) FROM pages WHERE page_key IN (\'company.profile\', \'company.users\', \'company.join_requests\', \'admin.multi_company\'))', 0],
        // the saved service charge and interest of a reconciliation (the query fails without the columns)
        'database/migrations/2026_09_29_reconcile_saved_charges.sql' =>
            'SELECT COUNT(service_charge) + COUNT(service_date) + COUNT(service_account_id) + COUNT(interest_earned)
                  + COUNT(interest_date) + COUNT(interest_account_id) + COUNT(class_id) FROM bank_reconciliations WHERE 1 = 0',
        // the approval_codes table (the query fails without it) and its page have to be there
        'database/migrations/2026_10_01_price_levels_approval_codes.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key = \'pos.approval_codes\') + (SELECT COUNT(*) FROM approval_codes WHERE 1 = 0)', 1],
        // the Category List and the five reports have to be there, and the two shift columns (the query fails without them)
        'database/migrations/2026_10_03_categories_reports_shift_corrections.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'inv.categories\', \'rpt.income_customer_summary\', \'rpt.income_customer_detail\', \'rpt.expenses_vendor_summary\', \'rpt.expenses_vendor_detail\', \'rpt.inv_no_movement\'))
                       + (SELECT COUNT(corrected_by) + COUNT(corrected_at) FROM pos_shifts WHERE 1 = 0)', 6],
        // the Customized Inventory Report has to be there, and the POS shift of a deposit line (the query fails without it)
        'database/migrations/2026_10_08_custom_report_deposit_edit.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key = \'rpt.inv_custom\') + (SELECT COUNT(pos_shift_id) FROM transaction_lines WHERE 1 = 0)', 1],
        // the BIR Reports module pages, the advances page, the withholding tables, the wht_ids columns and the default accounts
        'database/migrations/2026_10_15_withholding_bir_advances.sql' =>
            ['SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN (\'bir.wht_table\', \'bir.form_2307\', \'vend.advances\', \'rpt.bir_2550q\'))
                       + (SELECT COUNT(*) FROM default_accounts WHERE setting_key IN (\'wht_payable\', \'vat_withheld_payable\', \'cvat_withheld\', \'vendor_advances\') AND account_id IS NOT NULL)
                       + (SELECT COUNT(*) FROM withholding_taxes WHERE 1 = 0) + (SELECT COUNT(*) FROM payment_withholdings WHERE 1 = 0)
                       + (SELECT COUNT(v.wht_ids) + COUNT(c.wht_ids) FROM vendors v, customers c WHERE 1 = 0)', 8],
        // the inventory movement reports and their drill-down page
        'database/migrations/2026_10_22_inventory_movement_reports.sql' =>
            ['SELECT COUNT(*) FROM pages WHERE page_key IN (\'rpt.inv_movement_summary\', \'rpt.inv_movement_detail\', \'rpt.inv_movement_txns\', \'rpt.sales_inv_movement\')', 4],
        // the cooperative tables (the query fails without them), the teller columns on users, the member column on
        // other_names, the six module rows and the Cooperative default accounts
        'database/migrations/2026_11_01_cooperative.sql' =>
            ['SELECT (SELECT COUNT(*) FROM modules WHERE code IN (\'coop\', \'sav\', \'loan\', \'teller\', \'dist\', \'cda\'))
                       + (SELECT COUNT(*) FROM members WHERE 1 = 0) + (SELECT COUNT(*) FROM sav_accounts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM loan_accounts WHERE 1 = 0) + (SELECT COUNT(*) FROM teller_shifts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM dist_batches WHERE 1 = 0) + (SELECT COUNT(*) FROM cda_compliance WHERE 1 = 0)
                       + (SELECT COUNT(coop_branch_id) + COUNT(coop_cash_account_id) FROM users WHERE 1 = 0)
                       + (SELECT COUNT(member_id) FROM other_names WHERE 1 = 0)
                       + (SELECT COUNT(previous_loan_id) + COUNT(is_restructure) FROM loan_applications WHERE 1 = 0) + (SELECT COUNT(*) FROM coop_imports WHERE 1 = 0)
                       + (SELECT COUNT(cleared_txn_id) FROM sav_txns WHERE 1 = 0) + (SELECT COUNT(check_pending) + COUNT(check_cleared_txn_id) FROM coop_receipts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM default_accounts WHERE setting_key IN (\'loans_receivable\', \'share_capital\', \'isc_payable\', \'pr_payable\'))', 10],
        // the multipurpose cooperative: the Party Master and its links, business units, the counterparty snapshot on
        // documents, the business unit on ledger lines, and the Parties menu row
        'database/migrations/2026_11_15_multipurpose_party.sql' =>
            ['SELECT (SELECT COUNT(*) FROM modules WHERE code = \'party\')
                       + (SELECT COUNT(*) FROM parties WHERE 1 = 0) + (SELECT COUNT(*) FROM party_crosswalk WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM party_role_log WHERE 1 = 0) + (SELECT COUNT(*) FROM party_match_reviews WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM business_units WHERE 1 = 0) + (SELECT COUNT(*) FROM document_reissues WHERE 1 = 0)
                       + (SELECT COUNT(c.party_id) + COUNT(v.party_id) + COUNT(m.party_id) + COUNT(o.party_id) FROM customers c, vendors v, members m, other_names o WHERE 1 = 0)
                       + (SELECT COUNT(party_id) + COUNT(business_unit_id) + COUNT(cp_name) + COUNT(cp_reissue_no) FROM transactions WHERE 1 = 0)
                       + (SELECT COUNT(business_unit_id) FROM journal_lines WHERE 1 = 0)
                       + (SELECT COUNT(a.is_interunit) + COUNT(i.patronage_eligible) FROM accounts a, items i WHERE 1 = 0)', 1],
        // the water-billing module: its menu row, its tables (the query fails without them), the party and customer
        // links of a water-service account, the posting bridge and the two compatibility views (a backup imported by
        // hand brings back tables only; running this migration again makes the views)
        'database/migrations/2026_12_01_water_billing.sql' =>
            ['SELECT (SELECT COUNT(*) FROM modules WHERE code = \'water\')
                       + (SELECT COUNT(party_id) + COUNT(customer_id) FROM water_accounts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM water_postings WHERE 1 = 0) + (SELECT COUNT(*) FROM water_coa_map WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM bill_readings WHERE 1 = 0) + (SELECT COUNT(*) FROM payment_transactions WHERE 1 = 0)
                       + (SELECT COUNT(item_id) FROM bill_codes WHERE 1 = 0) + (SELECT COUNT(txn_id) FROM inventory_issuances WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM business_units WHERE code = \'WU\')
                       + (SELECT COUNT(*) FROM chartofaccntstbl WHERE 1 = 0) + (SELECT COUNT(*) FROM operator WHERE 1 = 0)', 2],
        // the member lists, the class on the cooperative and water transactions, and journal entry changes with
        // approval: the address sub-group of a water-service account, the class columns, the change requests table
        // and the Journal Entry Changes page
        'database/migrations/2026_12_20_member_lists_class_je_changes.sql' =>
            ['SELECT (SELECT COUNT(*) FROM journal_edit_requests WHERE 1 = 0)
                       + (SELECT COUNT(address_subgroup) FROM water_accounts WHERE 1 = 0)
                       + (SELECT COUNT(a.class_id) + COUNT(b.class_id) + COUNT(c.class_id) FROM sav_batches a, loan_waivers b, loan_applications c WHERE 1 = 0)
                       + (SELECT COUNT(a.class_id) + COUNT(b.class_id) + COUNT(c.class_id) + COUNT(d.class_id) + COUNT(e.class_id) + COUNT(f.class_id)
                            FROM bill_readings a, one_time_bills b, installment_bills c, recurring_bills d, payment_transactions e, water_postings f WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM pages WHERE page_key = \'coa.je_edits\')', 1],
        // the member's water, customer and supplier defaults, the savings transaction center (one transaction number,
        // transaction codes with error correction and memos), the cash account of a user profile, the passbook
        // layout, time deposit accrued interest withdrawals and the Savings Transaction Center page
        'database/migrations/2026_12_27_member_defaults_savings_center.sql' =>
            ['SELECT (SELECT COUNT(water_rc_id) + COUNT(water_connection_status) + COUNT(water_account_id) FROM members WHERE 1 = 0)
                       + (SELECT COUNT(trans_no) + COUNT(corrects_id) + COUNT(cheque_json) FROM sav_txns WHERE 1 = 0)
                       + (SELECT COUNT(is_memo) FROM sav_txn_codes WHERE 1 = 0) + (SELECT COUNT(coop_cash_account_id) FROM roles WHERE 1 = 0)
                       + (SELECT COUNT(header_json) + COUNT(skip_lines) FROM passbook_types WHERE 1 = 0)
                       + (SELECT COUNT(accrued_paid) FROM td_placements WHERE 1 = 0) + (SELECT COUNT(*) FROM td_interest_payouts WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM sequences WHERE seq_key = \'sav_trans\') + (SELECT COUNT(*) FROM pages WHERE page_key = \'sav.center\')', 2],
        // the Loan Collection Form: an error correction linked to the loan payment it negates
        'database/migrations/2027_01_03_loan_collection_error_correct.sql' =>
            'SELECT (SELECT COUNT(corrects_id) + COUNT(corrected_by_id) FROM loan_txns WHERE 1 = 0)',
        // the Teller menu (Loan Collection, Savings Transaction Center), the voucher ID of a loan release, the additional
        // interest of a loan product (Internal Ledger) and the Loan Release Vouchers page
        'database/migrations/2027_01_10_teller_menu_loan_release_vouchers.sql' =>
            ['SELECT (SELECT COUNT(voucher_no) FROM loan_accounts WHERE 1 = 0) + (SELECT COUNT(addl_interest) FROM loan_products WHERE 1 = 0)
                       + (SELECT COUNT(*) FROM pages WHERE page_key = \'loan.vouchers\')
                       + (SELECT COUNT(*) FROM pages p JOIN modules m ON m.id = p.module_id WHERE m.code = \'teller\' AND p.page_key IN (\'loan.collection\', \'sav.center\'))', 3],
        // the Passbook Type Setup of the former program: the kind of passbook (savings / loan) and its design
        'database/migrations/2027_01_17_passbook_designer.sql' =>
            'SELECT (SELECT COUNT(ledger) + COUNT(layout_json) FROM passbook_types WHERE 1 = 0)',
        // account types of the user's own (Chart of Accounts > Account Types), linked to the Statement of Cash Flows,
        // and the Balance Sheet Summary and Income Statement Summary
        'database/migrations/2027_01_24_account_types.sql' =>
            ['SELECT (SELECT COUNT(type_id) FROM accounts WHERE 1 = 0) + (SELECT COUNT(*) FROM account_types WHERE is_system = 1)
                       + (SELECT COUNT(*) FROM pages WHERE page_key IN (\'coa.account_types\', \'rpt.balance_sheet_summary\', \'rpt.income_statement_summary\'))', 18],
        // loan adjustments printed in the loan passbook: the adjustments of before marked as passed over, once
        'database/migrations/2027_01_31_adjustment_passbook.sql' =>
            ['SELECT COUNT(*) FROM settings WHERE setting_key = \'loan_pb_adjustments_since\'', 1],
        // the menus of version 39 (Financial, Members & Others, their order; Member Disbursement and Cooperative Vouchers
        // out of the menu) and the code INI of the imported savings balances
        'database/migrations/2027_02_21_menus_imports_codes.sql' =>
            ['SELECT (SELECT COUNT(*) FROM modules WHERE (code = \'coa\' AND name = \'Financial\') OR (code = \'coop\' AND name = \'Members & Others\') OR (code = \'admin\' AND sort_order = 19))
                       + (SELECT COUNT(*) FROM pages WHERE page_key IN (\'teller.disburse\', \'coop.vouchers\') AND show_in_menu = 0)', 5],
        // the computation of a water rate code: Ladder or Straight Rate Billing (the query fails without the column)
        'database/migrations/2027_03_07_water_ladder_billing.sql' =>
            'SELECT COUNT(billing_method) FROM rate_code WHERE 1 = 0',
        // the page is the last thing the script adds: a database without it still lacks the script's work
        'database/migrations/2027_03_14_share_capital_billing.sql' => [
            "SELECT (SELECT COUNT(*) FROM pages WHERE page_key = 'water.share_capital_billing')
                    + 0 * (SELECT COUNT(share_capital_amount) FROM payment_transactions WHERE 1 = 0)
                    + 0 * (SELECT COUNT(*) FROM share_capital_collections WHERE 1 = 0)", 1],
        'database/migrations/2027_03_21_share_capital_terms_batch.sql' => [
            "SELECT 1 + 0 * (SELECT COUNT(term_days) + COUNT(batch_no) FROM share_capital_plans WHERE 1 = 0)
                      + 0 * (SELECT COUNT(due_date) + COUNT(term_days) FROM share_capital_bills WHERE 1 = 0)", 1],
        // version 46: Regular - Joint, and the pages Address Sub-groups and Receipt Layout Setup (the pages are the
        // last thing the script adds)
        'database/migrations/2027_03_28_regular_joint_subgroups_receipts.sql' => [
            "SELECT COUNT(*) FROM pages WHERE page_key IN ('water.address_subgroups', 'water.receipt_layouts')", 2],
        // version 53, the accounting update: item groups, Receive Items lists, receipts ticked to settle, the average
        // cost on the books and fourteen reports (the pages are the last thing the script adds)
        'database/migrations/2027_04_04_accounting_update.sql' => [
            "SELECT (SELECT COUNT(*) FROM pages WHERE page_key IN ('rpt.pos_ending_inventory', 'rpt.sales_item_group', 'rpt.pos_sales_item_group',
                       'rpt.cmp_is_month', 'rpt.cmp_is_quarter', 'rpt.cmp_is_year', 'rpt.cmp_is_custom', 'rpt.cmp_bs_month', 'rpt.cmp_bs_quarter',
                       'rpt.cmp_bs_year', 'rpt.cmp_bs_custom', 'rpt.avg_cost_changes', 'rpt.trial_balance_class', 'rpt.cash_flow_class'))
                    + 0 * (SELECT COUNT(group_variance_account_id) FROM items WHERE 1 = 0)
                    + 0 * (SELECT COUNT(group_item_id) FROM transaction_lines WHERE 1 = 0)
                    + 0 * (SELECT COUNT(received_via) FROM transactions WHERE 1 = 0)
                    + 0 * (SELECT COUNT(*) FROM item_group_sales WHERE 1 = 0)
                    + 0 * (SELECT COUNT(*) FROM clearing_settlements WHERE 1 = 0)", 14],
        // version 54: Sales by Customer Detail Group by Customer/Member Type
        'database/migrations/2027_04_11_sales_by_member_type.sql' => [
            "SELECT COUNT(*) FROM pages WHERE page_key = 'rpt.sales_customer_type_detail'", 1],
        // version 55: Upload Bill Balances (the uploads and their lines; the page is the last thing the script adds)
        'database/migrations/2027_04_18_bill_balance_upload.sql' => [
            "SELECT (SELECT COUNT(*) FROM pages WHERE page_key = 'water.bill_balance_upload')
                    + 0 * (SELECT COUNT(*) FROM water_balance_uploads WHERE 1 = 0)
                    + 0 * (SELECT COUNT(*) FROM water_balance_upload_lines WHERE 1 = 0)", 1],
    ];

    private static ?array $pending = null;

    public static function flush(): void
    {
        self::$pending = null;
    }

    /** Upgrade scripts still to be run on this request's database, oldest first (empty = up to date) */
    public static function pending(): array
    {
        if (self::$pending !== null) {
            return self::$pending;
        }
        // remembered per database: a Super Admin moves between companies in one session
        $flag = self::FLAG . ':' . DB::databaseName();
        $inSession = session_status() === PHP_SESSION_ACTIVE;
        if ($inSession && !empty($_SESSION[$flag])) {
            return self::$pending = [];
        }
        $out = self::pendingWith(fn(string $sql) => DB::value($sql));
        if (!$out && $inSession) {
            $_SESSION[$flag] = 1;
        }
        return self::$pending = $out;
    }

    /** Upgrade scripts still to be run on another company's database (the platform's update page) */
    public static function pendingOn(Sql $db): array
    {
        return self::pendingWith(fn(string $sql) => $db->value($sql));
    }

    /** @return string[] every upgrade script the program knows, oldest first */
    public static function files(): array
    {
        return array_keys(self::MIGRATIONS);
    }

    private static function pendingWith(callable $value): array
    {
        $out = [];
        foreach (self::MIGRATIONS as $file => $probe) {
            [$sql, $want] = is_array($probe) ? $probe : [$probe, null];
            try {
                $got = $value($sql);
                if ($want !== null && (int)$got !== (int)$want) {
                    $out[] = $file;
                }
            } catch (Throwable $e) {
                $out[] = $file;
            }
        }
        return $out;
    }

    public static function ready(): bool
    {
        return !self::pending();
    }
}
