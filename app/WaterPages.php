<?php
declare(strict_types=1);

/**
 * The water-billing pages as the integrated system knows them.
 *
 * Every page of the former COWASCO Waters program keeps its file under water/views and its key; here each one gets
 * its place in the one menu (module "water"), its page-access key "water.<key>" (Page Access Configuration and
 * User Role Configuration work on it like on any other page) and, for the pages the cooperative system replaces,
 * the page that now does the job.
 */
final class WaterPages
{
    public const MODULE = 'water';

    /**
     * key => [name, group, show_in_menu, description]
     * Groups are the sections of the Water Billing menu.
     */
    public const PAGES = [
        'dashboard'                 => ['Water Dashboard', 'Overview', 1, 'Operational overview of the water utility.'],
        // billing
        'billing_management'        => ['Billing Management', 'Billing', 1, 'Billing dashboard.'],
        'bill_period'               => ['Bill Period Management', 'Billing', 1, 'Create and manage billing periods.'],
        'bill_codes'                => ['Bill Codes', 'Billing', 1, 'Bill and charge codes and the accounts they post to.'],
        'reading_entry'             => ['Reading Entry', 'Billing', 1, 'Enter individual and batch meter readings.'],
        'meter_reading_list_print'  => ['Meter Reading List Print', 'Billing', 0, 'Print meter reading lists.'],
        'reading_history'           => ['Reading History', 'Billing', 1, 'View meter reading history.'],
        'billing_history'           => ['Billing History', 'Billing', 1, 'View posted and historical bill records.'],
        'generate_soa'              => ['Generate SOA / Post Billing', 'Billing', 1, 'Generate statements and post water bills.'],
        'billing_list'              => ['Billing List', 'Billing', 1, 'Unified register of receivables.'],
        'update_billing'            => ['Update Billing', 'Billing', 1, 'Review supported billing updates.'],
        'billing_adjustments'       => ['Billing Adjustments', 'Billing', 1, 'Controlled debit, credit, reversal and refund workflows.'],
        'recurring_bill'            => ['Recurring Billing', 'Billing', 1, 'Manage recurring bills and subscriptions.'],
        'installment_bill'          => ['Installment Billing', 'Billing', 1, 'Manage installment bills and schedules.'],
        'one_time_billing'          => ['One-Time Billing', 'Billing', 1, 'Manage one-time bills.'],
        'share_capital_billing'     => ['Fixed Share Capital Billing', 'Billing', 1, 'Fixed share capital contributions of members, posted on their day and collected by the water cashier.'],
        // water-service accounts and the materials issued to the field (cooperative stock)
        'member_list'               => ['Water Service Accounts', 'Service & Materials', 1, 'Water-service account holders (consumers), each linked to a party.'],
        'address_subgroups'         => ['Address Sub-groups', 'Service & Materials', 1, 'Add, rename, sort, switch off or delete the address sub-groups of the members and the water-service accounts.'],
        // collection
        'collection_management'     => ['Collection Management', 'Collection', 1, 'Collection dashboard.'],
        'water_bills_collection'    => ['Collections (Cashier)', 'Collection', 1, 'Collect water bills and other receivables.'],
        'other_payments_collection' => ['Other Payments Collection', 'Collection', 0, 'Collect non-water receivables.'],
        'other_payments'            => ['Other Payments', 'Collection', 0, 'View and manage supported other payments.'],
        'payment_history'           => ['Payment History', 'Collection', 1, 'View receipts and payment history.'],
        'disconnection_management'  => ['Disconnection Management', 'Collection', 1, 'Manage disconnection and reconnection actions.'],
        'collection_receipt'        => ['Collection Receipt Print', 'Collection', 0, 'Print collection receipts.'],
        'collection_corrections'    => ['Collection Corrections', 'Collection', 0, 'Reverse or refund a collection.'],
        'customer_ledger'           => ['Customer Ledger', 'Collection', 0, 'Water-service account ledger.'],
        // rates and discounts
        'rates_management'          => ['Rates Management', 'Rates & Discounts', 1, 'Rates dashboard.'],
        'water_rates'               => ['Water Rate Schedule', 'Rates & Discounts', 1, 'View the validated tariff schedule.'],
        'water_meter'               => ['Water Meter Rates', 'Rates & Discounts', 1, 'Manage water meter rate tiers.'],
        'rate_codes'                => ['Rate Codes', 'Rates & Discounts', 1, 'Manage rate codes.'],
        'discount_management'       => ['Discount Management', 'Rates & Discounts', 1, 'Discount dashboard.'],
        'discounts'                 => ['General Discounts', 'Rates & Discounts', 1, 'Discount classifications and billing privilege values.'],
        'discounted_members'        => ['Discounted Accounts', 'Rates & Discounts', 1, 'Discount assignments of water-service accounts.'],
        // reports and documents
        'reports_management'        => ['Water Reports', 'Reports', 1, 'Operational water reports and printed documents.'],
        'generate_official_receipt' => ['Generate Official Receipt', 'Reports', 0, 'Print an official receipt.'],
        'generate_disconnection_notice' => ['Generate Disconnection Notice', 'Reports', 0, 'Print disconnection notices.'],
        // settings and tools
        'settings'                  => ['Water Settings', 'Setup', 1, 'Water utility settings hub.'],
        'receipt_layouts'           => ['Receipt Layout Setup', 'Setup', 1, 'The pre-printed water bill / collection receipt and official receipt: paper, margins and where each entry prints.'],
        'institution_configuration' => ['Institution Configuration', 'Setup', 0, 'Institution profile shown on water documents.'],
        'collection_policy_configuration' => ['Collection Policy', 'Setup', 0, 'Penalty, compromise and collection accounts.'],
        // branch, segment and division are no longer asked on the water forms (the class is): the page stays for the
        // records already on file but leaves the menu
        'organization_units'        => ['Organization Units', 'Setup', 0, 'Branches, divisions and segments of the utility (former program).'],
        'branches'                  => ['Branches', 'Setup', 0, 'Manage branch records.'],
        'divisions'                 => ['Divisions', 'Setup', 0, 'Manage division records.'],
        'segments'                  => ['Segments', 'Setup', 0, 'Manage segment records.'],
        'item_issuance'             => ['Material Issuance', 'Service & Materials', 1, 'Issue meters, pipes and supplies from cooperative stock to field work.'],
        'search'                    => ['Water Search', 'Setup', 0, 'Search water-service accounts and documents.'],
        'notifications'             => ['Water Notifications', 'Setup', 0, 'Operational alerts and reminders.'],
        'help_center'               => ['Water Help Center', 'Setup', 0, 'Searchable in-system guidance.'],
        'user_manual'               => ['Water User Manual', 'Setup', 0, 'Full water-billing user manual.'],
        'coming_soon'               => ['Coming Soon', 'Setup', 0, 'Placeholder page.'],
    ];

    /**
     * Pages of the former program that the cooperative system now provides. The old file answers with a notice
     * and a link to the new page (nothing is silently dropped), and the old key is not offered in page access.
     * key => [replacement page key, what to tell the user]
     */
    public const RETIRED = [
        'login'                     => ['', 'Sign in on the one login page of the integrated system.'],
        'register'                  => ['', 'Accounts are created by an administrator (System Administration > User Accounts) or with the Company Code.'],
        'forgot_password'           => ['auth.change_password', 'Passwords are reset by an administrator on User Accounts, or changed from the user menu.'],
        'profile'                   => ['auth.change_password', 'Your account and password are managed from the user menu.'],
        'access_control'            => ['admin.page_access', 'Page rights are set on Page Access Configuration and User Role Configuration.'],
        'user_configuration'        => ['admin.users', 'Users, roles and password resets are managed on User Accounts.'],
        'profile_cash_gl_configuration' => ['admin.users', 'The cash account a cashier collects into is set on the user account (User Accounts).'],
        'book_types'                => ['coa.lists', 'Books are no longer separate: every document is one transaction type of the general ledger.'],
        'accounting_management'     => ['home.reports', 'Accounting is the cooperative general ledger. Open the Report Center or the Financial menu.'],
        'journal_entry'             => ['rpt.journal', 'Journal entries are made on Make Journal Entries; the register is the Journal report.'],
        'chart_of_accounts'         => ['coa.accounts', 'The chart of accounts is maintained on Chart of Accounts.'],
        'general_ledger'            => ['rpt.general_ledger', 'The General Ledger report replaces this page.'],
        'trial_balance'             => ['rpt.trial_balance', 'The Trial Balance report replaces this page.'],
        'balance_sheet'             => ['rpt.balance_sheet', 'The Balance Sheet report replaces this page.'],
        'income_statement'          => ['rpt.income_statement', 'The Income Statement report replaces this page.'],
        'subsidiary_ledger_report'  => ['rpt.subsidiary_ledger', 'The Subsidiary Ledger report replaces this page.'],
        'accounts_payable'          => ['vend.pay_bills', 'Supplier bills are paid on Pay Bills; balances are on the A/P Aging reports.'],
        'inventory_management'      => ['inv.center', 'Inventory is the cooperative Inventory Center.'],
        'inventory_items'           => ['inv.items', 'Items are maintained on the Item List.'],
        'inventory_purchase'        => ['vend.receive_bill', 'Purchases are recorded on Receive Items and Enter Bills (or Enter Bills).'],
        'inventory_adjustment'      => ['inv.adjust', 'Stock is adjusted on Adjust Quantity/Value on Hand.'],
        'inventory_history'         => ['rpt.inv_movement_detail', 'Movements are on the Inventory Movement Detailed Report.'],
        'stock_monitoring'          => ['rpt.stock_status_item', 'Stock levels and reorder points are on Inventory Stock Status by Item.'],
        'discount_privilege_rules'  => ['water.discounts', 'Configure all values directly in General Discounts.'],
        'water_rate_discounts'      => ['water.discounts', 'Configure all values directly in General Discounts.'],
        'print_meter_reading_list'  => ['water.meter_reading_list_print', 'Use Meter Reading List Print.'],
    ];

    /** Detail and print pages that inherit the rights of the page they belong to (as the former program did) */
    public const ALIASES = [
        'collection_receipt'        => ['payment_history', 'water_bills_collection'],
        // the imports of a billing page carry its rights (posting takes its Create right)
        'share_capital_import'      => ['share_capital_billing'],
        'one_time_billing_import'   => ['one_time_billing'],
        'collection_corrections'    => ['collection_management', 'payment_history', 'billing_adjustments'],
        'generate_official_receipt' => ['reports_management', 'payment_history'],
        'generate_disconnection_notice' => ['reports_management', 'disconnection_management'],
        'customer_ledger'           => ['payment_history', 'billing_history'],
        'meter_reading_list_print'  => ['reading_entry', 'reading_history', 'bill_period'],
        'institution_configuration' => ['settings'],
        'collection_policy_configuration' => ['settings'],
        'organization_units'        => ['branches', 'divisions', 'segments'],
        'branches'                  => ['organization_units'],
        'divisions'                 => ['organization_units'],
        'segments'                  => ['organization_units'],
        'other_payments'            => ['water_bills_collection', 'collection_management'],
        'other_payments_collection' => ['water_bills_collection', 'collection_management'],
        'search'                    => ['dashboard'],
        'notifications'             => ['dashboard'],
        'help_center'               => ['dashboard'],
        'user_manual'               => ['dashboard'],
        'coming_soon'               => ['dashboard'],
    ];

    /** Pages every signed-in user may open */
    public const OPEN = ['help_center', 'user_manual', 'notifications', 'coming_soon'];

    /** Registry rows for app/registry.php: 'water.<key>' => [module, name, type, group, file, params, menu] */
    public static function registry(): array
    {
        $out = [];
        foreach (self::PAGES as $key => [$name, $group, $menu]) {
            $type = $group === 'Reports' ? 'center' : 'transaction';
            $out['water.' . $key] = ['water', $name, $type, $group, 'water/page.php', ['page' => $key], $menu];
        }
        return $out;
    }

    public static function isRetired(string $key): bool
    {
        return isset(self::RETIRED[$key]);
    }

    public static function accessKey(string $key): string
    {
        return 'water.' . $key;
    }

    /** The water page a process endpoint belongs to (fixed by file name, never by the Referer) */
    public static function processPage(string $scriptName): string
    {
        static $map = [
            'addBatchInstallmentBill'=>'installment_bill','addInstallmentBill'=>'installment_bill','editInstallmentBill'=>'installment_bill','deleteInstallmentBill'=>'installment_bill','toggleScheduleStatus'=>'installment_bill',
            'addBatchOneTimeBill'=>'one_time_billing','addOneTimeBill'=>'one_time_billing','editBatchOneTimeBill'=>'one_time_billing','editOneTimeBill'=>'one_time_billing','deleteBatchOneTimeBill'=>'one_time_billing','deleteOneTimeBill'=>'one_time_billing',
            'saveShareCapitalBilling'=>'share_capital_billing','runShareCapitalBilling'=>'share_capital_billing','shareCapitalImport'=>'share_capital_billing','oneTimeBillImport'=>'one_time_billing',
            'addBillCode'=>'bill_codes','editBillCode'=>'bill_codes','deleteBillCode'=>'bill_codes','saveWaterBillingBillCode'=>'bill_codes',
            'addBillPeriod'=>'bill_period','editBillPeriod'=>'bill_period','deleteBillPeriod'=>'bill_period','validatePeriodCode'=>'bill_period',
            'addDiscount'=>'discounts','editDiscount'=>'discounts','deleteDiscount'=>'discounts',
            'addDiscountedMembers'=>'discounted_members','editDiscountedMembers'=>'discounted_members','deleteDiscountedMembers'=>'discounted_members','autoAssignSeniors'=>'discounted_members',
            'saveDiscountPrivilegeRule'=>'discounts','deleteDiscountPrivilegeRule'=>'discounts',
            'addWaterMeterDiscount'=>'discounts','editWaterMeterDiscount'=>'discounts','deleteWaterMeterDiscount'=>'discounts','manageMemberWRDiscount'=>'discounted_members',
            'addWaterMeterMember'=>'water_meter','editWaterMeterMember'=>'water_meter','deleteWaterMeterMember'=>'water_meter','saveWaterRateGroup'=>'water_meter','updateWaterRateGroup'=>'water_meter','deleteWaterRateGroup'=>'water_meter','addWaterPipeSize'=>'water_meter','editWaterPipeSize'=>'water_meter','deleteWaterPipeSize'=>'water_meter','getWaterRates'=>'water_meter','updateFreeCubic'=>'water_meter',
            'createRateCode'=>'rate_codes','editRateCode'=>'rate_codes','deleteRateCode'=>'rate_codes','applyDiscountToRateCode'=>'rate_codes','bulkUpdateMemberRateCode'=>'rate_codes','updateMemberRateCode'=>'rate_codes',
            'addMember'=>'member_list','searchParties'=>'member_list','searchMembers'=>'member_list','updateMemberSequence'=>'member_list','updateMemberSubgroup'=>'member_list','addressSubgroups'=>'address_subgroups',
            'getMemberReadingData'=>'reading_entry','saveIndividualReading'=>'reading_entry','deleteReading'=>'reading_entry','getReadingHistory'=>'reading_history',
            'getBatchMembers'=>'reading_entry','getMembersByStreet'=>'reading_entry','saveBatchReading'=>'reading_entry','getPeriodReadings'=>'reading_history',
            'postBilling'=>'generate_soa','generate_soa'=>'generate_soa','getSoaData'=>'generate_soa','getIndividualSoaData'=>'generate_soa','computeBillMaintenanceWaterAmount'=>'billing_adjustments','previewBillCorrection'=>'billing_adjustments',
            'generateRecurringBill'=>'recurring_bill','generateRecurringBills'=>'recurring_bill','runRecurringBilling'=>'recurring_bill',
            'getAllReceivables'=>'water_bills_collection','getOtherReceivables'=>'water_bills_collection','getWaterBillReceivables'=>'water_bills_collection','getPaymentQuote'=>'water_bills_collection','searchAllReceivables'=>'water_bills_collection','searchOtherReceivables'=>'water_bills_collection','searchWaterBillReceivables'=>'water_bills_collection','processUnifiedPayment'=>'water_bills_collection','processWaterPayment'=>'water_bills_collection','processOtherPayment'=>'water_bills_collection','markPaidOneTimeBill'=>'water_bills_collection',
            'getCollectionReceipt'=>'payment_history','registerReceiptPrint'=>'collection_receipt','getCustomerLedger'=>'payment_history','paymentAdjustmentProcess'=>'billing_adjustments','collectionCorrectionProcess'=>'collection_corrections','billLifecycleProcess'=>'billing_adjustments',
            'saveItemIssuance'=>'item_issuance','reverseItemIssuance'=>'item_issuance','getInventoryItem'=>'item_issuance',
            'saveBranch'=>'branches','saveDivision'=>'divisions','saveSegment'=>'segments','saveOrganizationUnit'=>'organization_units','saveInstitutionConfiguration'=>'institution_configuration','saveCollectionPolicyConfiguration'=>'collection_policy_configuration',
            'updateManualDisconnection'=>'disconnection_management','markNotificationRead'=>'notifications',
            'getSetupReadiness'=>'settings','repairSetupPrerequisite'=>'settings','saveSetupTutorialProgress'=>'settings','getLoginProfileOptions'=>'dashboard','downloadUserManual'=>'user_manual',
        ];
        return $map[$scriptName] ?? '';
    }
}
