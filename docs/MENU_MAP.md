# Screen and menu map

One side menu for the whole system, the same on the cooperative/accounting pages and on the water pages. Every page is
listed with its page-access key (the key Page Access Configuration and User Role Configuration work on). A user sees
only the pages given to his roles. Generated from the page registry of `cowasco_sample` on 2026-11-07.

There is one Accounting (Financial, Banking, reports), one Inventory and one Membership (Members & Others, Parties)
section. The Water Billing menu holds only water operations; the former water accounting, inventory and user pages
are retired (table at the end) and open the page that replaces them.

## Home

- **Home** (5): Home Dashboard `home.dashboard`; Report Center `home.reports`; Memorized Reports `home.memorized`; Saved Report Layouts `home.report_layouts`; Find `home.find`

## Financial

- **Lists** (5): Chart of Accounts `coa.accounts`; Account Types `coa.account_types`; Class List `coa.classes`; Employees & Other Names `coa.names`; Memorized Transactions `coa.memorized_txns`
- **Setup** (3): Set Up Budgets `coa.budgets`; Module Default Accounts `coa.default_accounts`; Terms, Tax Codes & Other Lists `coa.lists`
- **Transactions** (2): Make Journal Entries `coa.journal`; Journal Entry Changes `coa.je_edits`
- **Reports** (17): Trial Balance `rpt.trial_balance`; Balance Sheet `rpt.balance_sheet`; Balance Sheet Summary `rpt.balance_sheet_summary`; Balance Sheet by Class `rpt.balance_sheet_class`; Trial Balance by Date Range `rpt.trial_balance_range`; Trial Balance of Totals `rpt.trial_balance_totals`; Balance Sheet by Date Range `rpt.balance_sheet_range`; Income Statement vs Budget `rpt.budget_income`; Balance Sheet vs Budget `rpt.budget_balance`; Income Statement `rpt.income_statement`; Income Statement Summary `rpt.income_statement_summary`; Income Statement by Class `rpt.income_statement_class`; Statement of Cash Flows `rpt.cash_flow`; General Ledger `rpt.general_ledger`; Subsidiary Ledger `rpt.subsidiary_ledger`; Schedule of Accounts `rpt.schedule_of_accounts`; Journal `rpt.journal`

## Members & Others

- **Transactions** (3): Member Center `coop.members`; Member Statement of Account `coop.member_statement`; Opening Balances Import `coop.import`
- **Setup** (7): Cooperative Profile & Policies `coop.profile`; Branches `coop.branches`; Member Types, Clusters & Other Lists `coop.lists`; Passbook Types `coop.passbooks`; Document Numbering `coop.numbering`; Document Templates `coop.templates`; Lines of Business `coop.business_units`
- **Reports** (11): Member Master List `rpt.coop_members`; Membership Movement `rpt.coop_member_movement`; Share Capital Balances `rpt.coop_share_capital`; Capital Build-up `rpt.coop_cbu`; Statement of Financial Condition `rpt.coop_sfc`; Statement of Operations `rpt.coop_sop`; Statement of Changes in Equity `rpt.coop_sce`; Statutory Funds `rpt.coop_statutory_funds`; Subsidiary vs General Ledger Reconciliation `rpt.coop_sl_gl`; Document Print History `rpt.coop_document_prints`; Statement of Operations by Line of Business `rpt.sop_business_unit`

## Teller

- **Transactions** (5): Teller Shift & Cash Count `teller.shift`; Savings Transaction Center `sav.center`; Loan Collection `loan.collection`; Official Receipt (Member Collection) `teller.or`; Official Receipt List `teller.receipts`
- **Reports** (5): Teller Transaction Journal `rpt.teller_journal`; Daily Collection & Disbursement Summary `rpt.teller_daily`; Teller Over / Short `rpt.teller_over_short`; Official Receipt Register `rpt.teller_or_list`; Cash Position `rpt.teller_cash_position`

## Savings & Deposits

- **Transactions** (8): Savings Account Center `sav.accounts`; Savings Account Transaction `sav.transact`; Transfer Between Accounts `sav.transfer`; Post Savings Interest `sav.interest`; Passbook Printing `sav.passbook`; Time Deposit Center `sav.td`; Batch Deductions (Payroll / CBU) `sav.batch`; Check Clearing `sav.clearing`
- **Setup** (2): Savings & Deposit Products `sav.products`; Savings Transaction Codes `sav.txn_codes`
- **Reports** (9): Savings List `rpt.sav_list`; Savings Journal `rpt.sav_journal`; Savings Account Balances `rpt.sav_balances`; Schedule of Savings Accounts `rpt.sav_schedule`; Dormant Accounts `rpt.sav_dormant`; Interest Posting Register `rpt.sav_interest`; Time Deposits by Product `rpt.td_list`; Time Deposit Maturity Schedule `rpt.td_maturity`; Time Deposit Accrued Interest `rpt.td_accrued`

## Loans

- **Transactions** (8): Loan Application Center `loan.applications`; Loan Release `loan.release`; Loan Release Vouchers `loan.vouchers`; Loan Accounts Center `loan.accounts`; Loan Adjustments `loan.adjustments`; Interest / Penalty Waiving `loan.waivers`; Restructure / Renew / Write Off `loan.restructure`; Post Loan Penalties & Interest Accrual `loan.penalties`
- **Setup** (3): Loan Products `loan.products`; Loan Charges `loan.charges`; Loan Approval Matrix `loan.approval_matrix`
- **Reports** (17): Loan Product List `rpt.loan_products`; Schedule of Loans Receivable `rpt.loan_schedule_recv`; Loan Releases `rpt.loan_releases`; Loan Due vs. Collection `rpt.loan_due_vs_coll`; Loans Due for Collection `rpt.loan_due`; Loan Collection List `rpt.loan_collections`; Loans With No Payments `rpt.loan_no_payments`; Loan Statement of Account `rpt.loan_soa`; Aging of Loans by Maturity `rpt.loan_aging_maturity`; Aging of Loans at PAR `rpt.loan_aging_par`; Aging of Loans by Amortization `rpt.loan_aging_amort`; Loan Portfolio Analysis `rpt.loan_portfolio`; Co-maker / Guarantor Exposure `rpt.loan_comaker`; Collateral Register `rpt.loan_collateral`; Schedule of Loans vs GL `rpt.loan_gl_compare`; Waivers and Adjustments `rpt.loan_waivers`; Written-off Loans and Recoveries `rpt.loan_writeoffs`

## Water Billing

- **Reports** (8): Collection Summary `rpt.water_collection_summary`; Revenue by Billing Period `rpt.water_revenue_period`; Receivables by Source `rpt.water_receivables_source`; Payment History (Ledger) `rpt.water_payment_history`; Water Service Account Statement `rpt.water_account_statement`; Water Posting Log & Exceptions `rpt.water_posting_log`; Account Mapping Validation `rpt.water_mapping_check`; Water Reports `water.reports_management`
- **Overview** (1): Water Dashboard `water.dashboard`
- **Billing** (14): Billing Management `water.billing_management`; Bill Period Management `water.bill_period`; Bill Codes `water.bill_codes`; Reading Entry `water.reading_entry`; Reading History `water.reading_history`; Billing History `water.billing_history`; Generate SOA / Post Billing `water.generate_soa`; Billing List `water.billing_list`; Update Billing `water.update_billing`; Billing Adjustments `water.billing_adjustments`; Recurring Billing `water.recurring_bill`; Installment Billing `water.installment_bill`; One-Time Billing `water.one_time_billing`; Fixed Share Capital Billing `water.share_capital_billing`
- **Service & Materials** (3): Water Service Accounts `water.member_list`; Address Sub-groups `water.address_subgroups`; Material Issuance `water.item_issuance`
- **Collection** (4): Collection Management `water.collection_management`; Collections (Cashier) `water.water_bills_collection`; Payment History `water.payment_history`; Disconnection Management `water.disconnection_management`
- **Rates & Discounts** (7): Rates Management `water.rates_management`; Water Rate Schedule `water.water_rates`; Water Meter Rates `water.water_meter`; Rate Codes `water.rate_codes`; Discount Management `water.discount_management`; General Discounts `water.discounts`; Discounted Accounts `water.discounted_members`
- **Setup** (2): Water Settings `water.settings`; Receipt Layout Setup `water.receipt_layouts`

## Banking

- **Transactions** (6): Write Checks `bank.write_checks`; Cash Voucher `bank.cash_voucher`; Use Register `bank.register`; Make Deposits `bank.deposits`; Transfer Funds `bank.transfers`; Reconcile `bank.reconcile`
- **Reports** (4): Reconciliation Summary `rpt.recon_summary`; Reconciliation Detail `rpt.recon_detail`; Reconciliation Discrepancy `rpt.recon_discrepancy`; Check Register `rpt.check_register`

## Company

- **Company** (3): Company Profile & Code `company.profile`; Employees & Users `company.users`; Join Requests `company.join_requests`

## Customers

- **Transactions** (12): Customer Center `cust.center`; Create Estimates `cust.estimates`; Create Sales Orders `cust.sales_orders`; Create Invoices `cust.invoices`; Create Batch Invoices `cust.batch_invoices`; Enter Sales Receipts `cust.sales_receipts`; Enter Statement Charges `cust.statement_charges`; Create Statements `cust.statements`; Assess Finance Charges `cust.finance_charges`; Set Up Forecast `cust.forecast`; Receive Payments `cust.payments`; Create Credit Memos/Refunds `cust.credit_memos`
- **Reports** (26): A/R Aging Summary `rpt.ar_aging_summary`; A/R Aging Detail `rpt.ar_aging_detail`; Customer Balance Summary `rpt.customer_balance_summary`; Customer Balance Detail `rpt.customer_balance_detail`; Open Invoices `rpt.open_invoices`; Collections Report `rpt.collections`; Average Days to Pay Summary `rpt.avg_days_summary`; Average Days to Pay `rpt.avg_days_detail`; Accounts Receivable Graph `rpt.ar_graph`; Unbilled Cost by Job `rpt.unbilled_costs`; Transaction List by Customer `rpt.txn_list_customer`; Item Price List `rpt.item_price_list`; Sales by Customer Summary `rpt.sales_customer_summary`; Forecast vs Actual by Customer `rpt.forecast_vs_actual`; Sales by Customer Detail `rpt.sales_customer_detail`; Income by Customer Summary `rpt.income_customer_summary`; Income by Customer Detail `rpt.income_customer_detail`; Sales by Item Summary `rpt.sales_item_summary`; Sales by Item Detail `rpt.sales_item_detail`; Sales by Rep Summary `rpt.sales_rep_summary`; Sales by Rep Detail `rpt.sales_rep_detail`; Inventory Sales by Site `rpt.inventory_sales_site`; Sales Report Through Inventory Movement `rpt.sales_inv_movement`; Pending Sales `rpt.pending_sales`; Open Sales Orders by Customer `rpt.open_so_customer`; Open Sales Orders by Item `rpt.open_so_item`

## Point of Sale

- **Transactions** (4): POS Register `pos.register`; Shifts, Cash Drops & Readings `pos.shifts`; POS Receipts, Returns & Voids `pos.receipts`; Approval Codes `pos.approval_codes`
- **Setup** (1): POS Terminals `pos.terminals`
- **Reports** (9): POS Sales by Transaction No. (detail) `rpt.pos_sales_txn`; POS Sales by Item `rpt.pos_sales_item`; POS Sales by Category `rpt.pos_sales_category`; POS Sales by Cashier & Payment Method `rpt.pos_sales_cashier`; POS Sales by Hour `rpt.pos_sales_hour`; POS Daily Sales Summary `rpt.pos_daily_summary`; X- and Z-Reading Summary `rpt.pos_readings`; POS Discount Log `rpt.pos_discount_log`; POS Void and Refund Log `rpt.pos_void_log`

## Vendors

- **Transactions** (11): Vendor Center `vend.center`; Bill Tracker `vend.bill_tracker`; Enter Bills `vend.bills`; Supplier Debit Memos `vend.debit_memos`; Pay Bills `vend.pay_bills`; Receipt Management `vend.receipts`; Create Purchase Orders `vend.purchase_orders`; Receive Items and Enter Bills `vend.receive_bill`; Receive Items `vend.receive_items`; Enter Bill for Received Items `vend.bill_received`; Advances to Suppliers `vend.advances`
- **Reports** (10): A/P Aging Summary `rpt.ap_aging_summary`; A/P Aging Detail `rpt.ap_aging_detail`; Vendor Balance Summary `rpt.vendor_balance_summary`; Vendor Balance Detail `rpt.vendor_balance_detail`; Unpaid Bills Detail `rpt.unpaid_bills`; Transaction List by Vendor `rpt.txn_list_vendor`; Vendor Contact List `rpt.vendor_contacts`; Expenses by Vendor Summary `rpt.expenses_vendor_summary`; Expenses by Vendor Detail `rpt.expenses_vendor_detail`; Advances to Suppliers `rpt.vendor_advances`

## Inventory

- **Transactions** (11): Inventory Center `inv.center`; Transfer Inventory `inv.transfers`; Adjust Quantity/Value on Hand `inv.adjust`; Build Assemblies `inv.build`; Disassemble / Process Items `inv.process`; Physical Inventory Count `inv.physical_count`; Change Item Prices `inv.change_prices`; Set Item Prices and Levels `inv.set_prices`; Create Purchase Orders `vend.purchase_orders` (its own menu: Vendors); Receive Items `vend.receive_items` (its own menu: Vendors); Material Issuance `water.item_issuance` (its own menu: Water Billing)
- **Lists** (5): Item List `inv.items`; Category List `inv.categories`; Inventory Site List `inv.sites`; U/M Set List `inv.uom`; Processing Formulas (Multi-Output BOM) `inv.formulas`
- **Reports** (18): Quantity on Hand by Site `rpt.qoh_by_site`; Inventory Valuation Summary `rpt.inv_valuation_summary`; Inventory Valuation Summary by Site `rpt.inv_valuation_site`; Inventory Valuation Detail `rpt.inv_valuation_detail`; Inventory Stock Status by Item `rpt.stock_status_item`; Inventory Stock Status by Vendor `rpt.stock_status_vendor`; Inventory Stock Status by Site `rpt.stock_status_site`; Physical Inventory Worksheet `rpt.physical_worksheet`; Pending Builds `rpt.pending_builds`; Pending Builds by Site `rpt.pending_builds_site`; Processing & Disassembly Register `rpt.processing_register`; Co-product & By-product Cost Allocation `rpt.coproduct_costs`; Processing Yield Analysis `rpt.process_yield`; Negative Inventory Listing `rpt.negative_inventory`; No. of Days Inventory Has No Movement `rpt.inv_no_movement`; Customized Inventory Report `rpt.inv_custom`; Inventory Movement Summary Report `rpt.inv_movement_summary`; Inventory Movement Detailed Report `rpt.inv_movement_detail`

## Fixed Assets

- **Transactions** (5): Fixed Asset Register `fa.register`; Run Depreciation `fa.depreciation`; Dispose of Assets `fa.disposal`; Asset Maintenance `fa.maintenance`; Preventive Maintenance `fa.pm`
- **Setup** (2): Asset Categories `fa.categories`; Asset Locations `fa.locations`
- **Reports** (10): Fixed Asset Register Report `rpt.fa_register`; Depreciation Schedule by Asset `rpt.fa_schedule`; Depreciation by Category `rpt.fa_by_category`; Additions and Disposals `rpt.fa_additions`; Maintenance History `rpt.fa_maint_history`; Maintenance Cost by Asset `rpt.fa_maint_cost`; Spare Parts Used `rpt.fa_maint_parts`; Maintenance Labor `rpt.fa_maint_labor`; Upcoming Preventive Maintenance `rpt.fa_pm_upcoming`; Completed Preventive Maintenance `rpt.fa_pm_log`

## Parties

- **Transactions** (2): Party Center `party.center`; Identity Review `party.review`
- **Reports** (4): Consolidated Party Statement `party.statement`; Party List `rpt.party_list`; Party Role History `rpt.party_role_history`; Reissued Documents `rpt.doc_reissues`

## BIR Reports

- **Transactions** (1): Print BIR Form 2307 `bir.form_2307`
- **Setup** (1): Withholding Tax Table `bir.wht_table`
- **Reports** (8): Summary of Taxes Withheld from Vendors/Suppliers `rpt.bir_wht_summary`; Expanded Withholding Tax Register (1% / 2%) `rpt.bir_ewt_register`; Withholding Tax Remittances (0619-E, 1601-EQ, 1600-VT) `rpt.bir_remittance`; VAT Withholding Register (1600-VT) `rpt.bir_vat_withholding`; Output VAT / Sales Register `rpt.bir_sales_register`; Input VAT / Purchases Register `rpt.bir_purchase_register`; Creditable VAT Withheld Schedule (2550Q Schedule 3) `rpt.bir_cvat_withheld`; Quarterly VAT Return Worksheet (2550Q) `rpt.bir_2550q`

## Year-end Distribution

- **Transactions** (4): Net Surplus Allocation `dist.surplus`; Interest on Share Capital `dist.isc`; Patronage Refund `dist.pr`; Distribution Batches `dist.batches`
- **Reports** (3): Distribution Register `rpt.dist_register`; Unclaimed Distributions `rpt.dist_unclaimed`; Net Surplus Allocation Summary `rpt.dist_summary`

## CDA Compliance

- **Setup** (2): Officers, Committees & Trainings `cda.officers`; Report Field Mappings `cda.mappings`
- **Transactions** (3): Compliance Tracking `cda.compliance`; Regulatory Calendar `cda.calendar`; CAPR Data Preparation & Export `cda.capr`
- **Reports** (2): List of Officers and Committees `rpt.cda_officers`; Compliance Status `rpt.cda_compliance`

## System Administration

- **Setup** (7): User Accounts `admin.users`; User Role Configuration `admin.roles`; Page Access Configuration `admin.page_access`; Effective Access (View as User) `admin.effective_access`; Company, Security & Closing Date `admin.settings`; Utilities: Verify & Rebuild Data `admin.utilities`; Multi-Company Setup `admin.multi_company`
- **Utilities** (2): Import from CSV `admin.import`; Backup & Restore `admin.backup`
- **Reports** (7): Audit Trail `admin.audit_trail`; Transaction History `admin.txn_history`; Voided and Deleted Transactions Summary `rpt.voided_deleted_summary`; Voided and Deleted Transactions Detail `rpt.voided_deleted_detail`; User Activity `rpt.user_activity`; Login History `rpt.login_history`; User Access and Permission Changes `rpt.user_access`

## Retired pages of the former water program

The file still answers (old bookmarks keep working): it shows why the page is retired and links to the page that replaces it.

| Former water page | Replaced by | Why |
|---|---|---|
| `login.php` | Login page | Sign in on the one login page of the integrated system. |
| `register.php` | Login page | Accounts are created by an administrator (System Administration > User Accounts) or with the Company Code. |
| `forgot_password.php` | auth.change_password `auth.change_password` | Passwords are reset by an administrator on User Accounts, or changed from the user menu. |
| `profile.php` | auth.change_password `auth.change_password` | Your account and password are managed from the user menu. |
| `access_control.php` | Page Access Configuration `admin.page_access` | Page rights are set on Page Access Configuration and User Role Configuration. |
| `user_configuration.php` | User Accounts `admin.users` | Users, roles and password resets are managed on User Accounts. |
| `profile_cash_gl_configuration.php` | User Accounts `admin.users` | The cash account a cashier collects into is set on the user account (User Accounts). |
| `book_types.php` | Terms, Tax Codes & Other Lists `coa.lists` | Books are no longer separate: every document is one transaction type of the general ledger. |
| `accounting_management.php` | Report Center `home.reports` | Accounting is the cooperative general ledger. Open the Report Center or the Financial menu. |
| `journal_entry.php` | Journal `rpt.journal` | Journal entries are made on Make Journal Entries; the register is the Journal report. |
| `chart_of_accounts.php` | Chart of Accounts `coa.accounts` | The chart of accounts is maintained on Chart of Accounts. |
| `general_ledger.php` | General Ledger `rpt.general_ledger` | The General Ledger report replaces this page. |
| `trial_balance.php` | Trial Balance `rpt.trial_balance` | The Trial Balance report replaces this page. |
| `balance_sheet.php` | Balance Sheet `rpt.balance_sheet` | The Balance Sheet report replaces this page. |
| `income_statement.php` | Income Statement `rpt.income_statement` | The Income Statement report replaces this page. |
| `subsidiary_ledger_report.php` | Subsidiary Ledger `rpt.subsidiary_ledger` | The Subsidiary Ledger report replaces this page. |
| `accounts_payable.php` | Pay Bills `vend.pay_bills` | Supplier bills are paid on Pay Bills; balances are on the A/P Aging reports. |
| `inventory_management.php` | Inventory Center `inv.center` | Inventory is the cooperative Inventory Center. |
| `inventory_items.php` | Item List `inv.items` | Items are maintained on the Item List. |
| `inventory_purchase.php` | Receive Items and Enter Bills `vend.receive_bill` | Purchases are recorded on Receive Items and Enter Bills (or Enter Bills). |
| `inventory_adjustment.php` | Adjust Quantity/Value on Hand `inv.adjust` | Stock is adjusted on Adjust Quantity/Value on Hand. |
| `inventory_history.php` | Inventory Movement Detailed Report `rpt.inv_movement_detail` | Movements are on the Inventory Movement Detailed Report. |
| `stock_monitoring.php` | Inventory Stock Status by Item `rpt.stock_status_item` | Stock levels and reorder points are on Inventory Stock Status by Item. |
| `discount_privilege_rules.php` | General Discounts `water.discounts` | Configure all values directly in General Discounts. |
| `water_rate_discounts.php` | General Discounts `water.discounts` | Configure all values directly in General Discounts. |
| `print_meter_reading_list.php` | Meter Reading List Print `water.meter_reading_list_print` | Use Meter Reading List Print. |

19 menus, 332 menu entries.
