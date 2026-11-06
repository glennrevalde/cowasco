# Water Billing data migration (COWASCO Waters -> G-SMaRT integrated database)

`tools/migrate_water.php` copies the former stand-alone water-billing database into the integrated cooperative +
water database: every water record keeps its key, the former chart of accounts becomes part of the one chart of
accounts, the operators become users with the same effective page access, every water-service account gets a
party, and the receivables that were open at cutover are posted to the one general ledger with an opening-balance
journal that reproduces the legacy trial balance.

`tools/rollback_water.php` undoes a run (for a failed cutover rehearsal only, see the end of this document).

The migration takes **both** former databases into a **fresh target**: `tools/create_target.php` first copies the
cooperative database in use into a new database (verified table by table: row counts and checksums) and runs the
upgrade scripts it lacks, the water-billing one included; `tools/migrate_water.php` then brings the water database
into that target. Neither former database is written to.

```
php tools/create_target.php --coop-source=<cooperative database> --target=<new database> [--drop] [--skip-migrations]
php tools/migrate_water.php --source=<former water database> [--dry-run] [--phase=NAME] [--cutover=YYYY-MM-DD] [--company=N] [--allow-existing]
                            [--coa-map=<reviewed coa_crosswalk.csv>] [--phase=membership --import-membership --savings-product=CODE]
php tools/rollback_water.php [--run=RUN_ID] [--source=DB] [--company=N] [--dry-run]
php tools/dev/water_legacy_fixture.php            (developer rehearsal of the ledger side with sample legacy transactions)
```

* The **target** is the database of `config/config.php` (or the `DB_NAME` environment variable), as for every tool;
  on a multi-company install `--company=N` picks the company. The target must already have the cooperative schema
  and `database/migrations/2026_12_01_water_billing.sql` applied (empty water tables).
* The **source** is the former water database on the same MySQL server. It is opened read-only and never written to.
* `--cutover` is the opening-balance date (default: today). Legacy journal lines dated after it are not part of the
  opening balance.
* `--dry-run` runs everything in one transaction, rolls it back and prints the same counts and checks; the
  `water_migration_log` rows of a dry run carry the status `dry-run`.
* `--phase=NAME` runs one phase; without it every phase runs in order.
* Every phase runs in its own transaction and is **idempotent**: rows already present (same primary key), accounts
  already in `water_coa_map`, operators already in `water_user_map`, parties already in `party_crosswalk` and
  invoices already in `water_postings` (event key) are skipped, so a second run writes nothing new and a run that
  stopped half-way is simply started again.
* MySQL 5.5 and MariaDB compatible SQL only; strict SQL mode stays on (every NOT NULL column is supplied; a legacy zero
  date `0000-00-00` becomes NULL where the column allows it, else the row's own creation date or today, and is counted
  in the log notes).

## Phases and their order

The order is fixed by what each phase needs from the ones before it:

| # | Phase | What it writes | Needs |
|---|---|---|---|
| 1 | `reference` | the reference tables that reference neither operators nor accounts: cities, addresstype, addresses, memberaddress, contactchannels, membercontactchannels, bussinessprofile, companyofficers, branches (+ a `classes` row and a `coop_branches` row per water branch), ic_divisions, ic_segments, rate_code, water_pipe_sizes, water_meter_discounts, discounts, discount_privilege_rules, water_rates, water_meter_members, syssequence, global_billing_sequence, jointmember, membershipinfo | the 2026_12_01 migration |
| 2 | `coa` | chartofaccntstbl -> accounts, `water_coa_map`, the water default accounts, the clearing account 39999 | 1 |
| 3 | `users` | operator -> users, profile -> roles, page permissions -> role_page_access, assignments -> user_roles and user_page_overrides, `water_user_map` | 2 (the cashier's cash account comes from the profile's cashGL through the account map) |
| 4 | `bill_codes` | bill_codes and water_billing_settings (accounts remapped), bill_periods, data_import_*, setup_tutorial_progress, operator_notification_reads (operators remapped) | 2, 3 |
| 5 | `accounts` | members -> water_accounts (+ one party each, with the account's address and contact number), discounted_members, water_meter_discounted_members, manual_disconnection_logs; accounts that cannot be billed (no rate code or pipe size) are listed in the exceptions | 1, 3 |
| 6 | `suppliers` | suppliers -> parties (organization) + vendors | 1 |
| 7 | `membership` | the former savings register as read-only history (`legacy_savingsaccounts`, `legacy_savaccnttransaction`, `legacy_savingstranscode`, `legacy_savingsinterest`, ...); the membership register and the savings balances at cutover written to `<run>-legacy_members.csv` and `<run>-legacy_savings.csv`. Only with `--import-membership --savings-product=CODE`: cooperative members (one party each, never merged by name) and their savings accounts with opening balances against 39998 Legacy Savings Opening Balance Clearing | 1 |
| 8 | `history` | all transactional tables, the legacy_* tables, water_audit_logs; then the open bills as invoices, the opening stock of the water materials and the opening-balance journal; sets the `water_cutover_date` and `water_source_db` settings | 2 - 7 |
| 9 | `verify` | nothing (one log row); prints the verification table, queues Identity Review pairs (`Party::findMatches`) | 8 |

Every phase writes one `water_migration_log` row (`run_id`, `phase`, `status` done / failed / dry-run, rows read /
written / skipped, `amount_in` / `amount_out`, `notes` JSON with the per-table counts and the ids of what was
created, used by the rollback) and one `audit_trail` row (`module` water, `action` migrate).

### Pre-flight

Before anything is written the tool compares every target water table with the source: rows whose key is not in
the source at all, and rows with a source key but different content, are listed and the tool stops. Those rows did
not come from the source (a test, a manual entry) and would make the keys of the migrated records (rate codes, pipe
sizes, accounts) point at the wrong rows. Remove them, or pass `--allow-existing` to keep them (their keys are then
skipped and reported in the exceptions). A fresh install plus the 2026_12_01 migration passes the pre-flight.

## Table and field mapping

### Tables copied row for row (same primary key, same column names)

Every column of the source table that exists in the target table is copied as it is, except the columns listed
under *remapped*: integer operator ids go through `water_user_map` (a skipped operator becomes NULL, or the
migrating administrator where the column is NOT NULL, and is counted in the notes), legacy chart-of-accounts ids go
through `water_coa_map` (an unmapped one becomes NULL or 0 and is listed in the exceptions). `varchar` columns that
hold an operator *name* (`createdby`, `modifiedby`, `encoded_by`, `created_by` of the rate tables ...) are copied
verbatim: they are text, not keys.

| Phase | Source table | Target table | Key | Remapped columns |
|---|---|---|---|---|
| reference | cities | cities | pkey | |
| reference | addresstype | addresstype | pkey | |
| reference | addresses | addresses | pkey | |
| reference | memberaddress | memberaddress | member_key, address_key, dtaddressFrom | |
| reference | contactchannels | contactchannels | pkey | |
| reference | membercontactchannels | membercontactchannels | activefromdate, memberkey, conchannelkey | |
| reference | bussinessprofile | bussinessprofile | pkey | |
| reference | companyofficers | companyofficers | pkey | |
| reference | branches | branches | pkey | `class_id` set (see below) |
| reference | ic_divisions, ic_segments | same | pkey | |
| reference | rate_code | rate_code | rc_id | |
| reference | water_pipe_sizes | water_pipe_sizes | pipe_size_id | |
| reference | water_meter_discounts | water_meter_discounts | wmdiscount_id | |
| reference | discounts | discounts | discount_id | |
| reference | discount_privilege_rules | discount_privilege_rules | discount_rule_id | |
| reference | water_rates | water_rates | rate_id | |
| reference | water_meter_members | water_meter_members | wmm_id | |
| reference | syssequence | syssequence | pkey | |
| reference | global_billing_sequence | global_billing_sequence | ref_number | |
| reference | jointmember | jointmember | pkey | |
| reference | membershipinfo | membershipinfo | memberID | kept as read-only legacy information |
| bill_codes | bill_codes | bill_codes | code_id | users: modified_by; accounts: ar_account, income_account, cogs_account, asset_account; an **inventory** bill code (meters, pipes, fittings) gets its item in the one item master (made when no item has its code; income, COGS and inventory asset accounts from the bill code when they are of the right kind, else the cooperative defaults) and `item_id` is set |
| bill_codes | water_billing_settings | water_billing_settings | setting_key | every `*_account_id` value remapped through `water_coa_map` (0 stays 0) |
| bill_codes | bill_periods | bill_periods | period_id | users: opened_by, closed_by |
| bill_codes | data_import_batches / _mapping_profiles / _mapping_rules / _rows | same | batch_id / profile_id / rule_key / row_id | users: created_by, created_by, confirmed_by |
| bill_codes | setup_tutorial_progress | setup_tutorial_progress | operator_id, requirement_key | users: operator_id |
| bill_codes | operator_notification_reads | operator_notification_reads | operator_id, notification_key | users: operator_id |
| accounts | members | **water_accounts** | pkey | users: disconnected_by, reconnected_by; `legacy_member_id` = pkey; `party_id` and `customer_id` filled (below) |
| accounts | discounted_members | discounted_members | dm_id | |
| accounts | water_meter_discounted_members | water_meter_discounted_members | wmdm_id | |
| accounts | manual_disconnection_logs | manual_disconnection_logs | log_id | users: action_by |
| history | bill_readings | bill_readings | reading_id | users: deleted_by, last_bill_edited_by |
| history | one_time_bills | one_time_bills | bill_id | users: created_by, deleted_by, last_bill_edited_by |
| history | installment_bills | installment_bills | installment_id | users: deleted_by, last_bill_edited_by |
| history | installment_schedules | installment_schedules | schedule_id | users: marked_by |
| history | recurring_plans / recurring_subscriptions | same | plan_id / subscription_id | users: created_by |
| history | recurring_bills | recurring_bills | bill_id | users: created_by, deleted_by, last_bill_edited_by |
| history | recurring_bill_payments | recurring_bill_payments | payment_id | users: created_by |
| history | payment_transactions | payment_transactions | transaction_id | users: cashier_id, reversed_by |
| history | payment_transaction_items | payment_transaction_items | item_id | |
| history | payment_adjustments | payment_adjustments | adjustment_id | users: created_by |
| history | bill_adjustments | bill_adjustments | adjustment_id | users: created_by |
| history | inventory_issuances | inventory_issuances | issuance_id | users: created_by, reversed_by; accounts: expense_account_id |
| history | inventory_issuance_items | inventory_issuance_items | issuance_item_id | accounts: asset_account |
| history | journalentries_copy1 | **legacy_journalentries** | JournalID, JournalHeadID | (legacy AccntIDs kept: read-only history) |
| history | journalvoucher_copy1 | **legacy_journalvoucher** | JVID | |
| history | journal_voucher_voids | **legacy_journal_voucher_voids** | void_id | users: voided_by |
| history | subsidiaryledger | **legacy_subsidiaryledger** | pkey | |
| history | postingmethod, book_types | legacy_postingmethod, legacy_book_types | pkey / book_type_id | |
| history | accounts_payable, accounts_payable_payments | legacy_accounts_payable, legacy_accounts_payable_payments | ap_id / ap_payment_id | users: created_by |
| history | inventory_purchases / _purchase_items / _stock_movements / _adjustments / _reorder_levels | legacy_inventory_* | own key | users: created_by (purchases, movements, adjustments), updated_by (reorder levels) |
| history | suppliers | **legacy_suppliers** | supplier_id | (the live supplier is the vendor made in phase 6) |
| history | operator, profile, operator_profile, operator_profile_page_permissions | legacy_operator, legacy_profile, legacy_operator_profile, legacy_operator_profile_page_permissions | own key | copied verbatim with the legacy operator ids (evidence of the former access set-up) |
| history | access_control_pages / _presets / _preset_pages | legacy_access_control_* | own key | |
| history | chartofaccntstbl | **legacy_chartofaccntstbl** | AccntID | the former chart as it was (`chartofaccntstbl` itself is now a view over `accounts`) |
| history | audit_logs | **water_audit_logs** | log_id | users: user_id |

The former savings tables (`savingsaccounts`, `savaccnttransaction`, `savingstranscode`, `savingsinterest`,
`savingsaccount_param`, `savingsrestrictions`, `savrestrictiontype`) are copied to `legacy_sav*` by the membership
phase (read-only history).

Not migrated (nothing in the integrated system uses them): `users`, `user_profiles`, `user_profile_assignments`,
`user_sessions`, `session`, `password_resets`, `operator_password_reset_requests`, the views (`coop_members_v`,
`inventory_current_stock`, `inventory_item_masterlist`) and `*_backup_*` tables.

### branches -> classes and coop_branches

| Target | Value |
|---|---|
| classes.name | branches.name (made when no class of that name exists; `branches.class_id` set to it) |
| classes.description, sort_order | "Water utility branch (migrated)", 90 |
| coop_branches.code / name / address / class_id | `WB<pkey>` / branches.name / branches.description / the class; `is_head_office` = 1 for the first branch of an empty list. Made only when no cooperative branch has that name. |

### chartofaccntstbl -> accounts (phase coa)

The legacy chart has four levels: roots (Type 0: "DETAIL ACCOUNTS", "SUMMARY ACCOUNTS"), classes (Type 1: ASSET,
LIABILITIES, EQUITY, REVENUE, EXPENSES), groups (Type 2: CURRENT ASSETS, PROPERTY PLANT AND EQUIPMENT, ...),
summary accounts (Type 4) and detail accounts (Type 3, each pointing at its summary account through `SummaryAccnt`).

| accounts column | From |
|---|---|
| code | `AccntID` as text (e.g. `11784`). When the cooperative chart already uses that code the account gets the suffix `W` (e.g. `11784W`) and the exceptions say so. |
| name | `AccntName` (trimmed, 120 characters) |
| category | the leading digit of the AccntID: 1 Asset, 2 Liability, 3 Equity, 4 Income, 5 Expense. Codes outside 1-5 (the 7xxxx / 8xxxx expense groups) take the category of the Type-1 class they sit under. When the class the former program listed the account under disagrees with its code, the code wins and the account is listed in the exceptions. |
| account_type | by the rules below |
| normal_balance | Debit for Asset and Expense, Credit otherwise |
| parent_id | detail account (Type 3): the account made from its `SummaryAccnt` (Type 4) when it has one of the same category; summary accounts have no parent (the integrated chart has one level of sub-accounts) |
| description | `Imported from COWASCO chart of accounts (legacy AccntID n)` |
| with_sl | `WithSL` |
| is_active | `Status` = 'A' |
| is_system | 0 |

Rows of Type 0, 1 and 2 are not imported; they only serve to find the ancestors. Every legacy row gets a
`water_coa_map` row (`legacy_accnt_id`, `legacy_name`, `legacy_type`, `account_id`, `how` imported / skipped, `note`).

**Account type rules**

| Category | Rule (first match wins) |
|---|---|
| Asset | `Bank` when the name matches `cash`, `bank`, `petty`, `coci` or `vault`; else `Accounts Receivable` when the account is the A/R account of a bill code, the `water_billing_default_ar_account_id` setting, or its name contains "Receivable"; else `Fixed Asset` when an ancestor is "PROPERTY, PLANT AND EQUIPMENT"; else `Other Current Asset` |
| Liability | `Accounts Payable` when the name contains "Payable" and an ancestor contains "CURRENT" (not "NON-CURRENT"); else `Other Current Liability` when an ancestor contains "CURRENT"; else `Long Term Liability` |
| Equity | `Equity` |
| Income | `Income` |
| Expense | `Cost of Goods Sold` when the name contains "Cost of"; else `Expense` |

**Default accounts** (Chart of Accounts > Module Default Accounts > Water), filled only while unset:

| Key | From |
|---|---|
| water_ar | setting `water_billing_default_ar_account_id` |
| water_income | setting `water_billing_default_income_account_id` |
| water_penalty, water_compromise | settings `collection_penalty_account_id` / `collection_compromise_account_id` when > 0 |
| water_cash | the `cashGL` of profile 1 (Administrator), else the first profile with one |
| water_legacy_clearing | the Equity account `39999 Legacy Water Balances Clearing`, made when missing |
| water_discount | stays unset (the former program posted the net amount) |

### operator / profile / operator_profile -> users, roles, page access (phase users)

| Target | From |
|---|---|
| users.username | operator.name |
| users.full_name | operator.description (the username when empty) |
| users.password_hash | the legacy password when it already is a bcrypt hash; otherwise the plain-text legacy password is hashed with `Auth::hash()` and `must_change_password` = 1 (a plain text password is never stored; the user sets a new one at the first login) |
| users.status / approval_status / created_via | active / approved / `migration` |
| users.coop_cash_account_id | the `cashGL` of the operator's first profile that has one (its super-admin assignment first), through `water_coa_map` |
| users.email | NULL |
| water_user_map | legacy_operator_id, legacy_name, user_id, note (created / matched / skipped) |

* An operator with `isSystem` = 1 or `deleted` = 1 is skipped (no user; references to it become NULL, or the
  migrating administrator in NOT NULL columns, and are counted).
* An operator whose username already exists as a user is **matched** to that user (its password is not touched).
* **Roles**: one role `Water: <profile name>` per legacy profile that has at least one assignment of a migrated
  operator (made when missing, `is_admin` 0). Its `role_page_access` rows are the UNION of the page permissions
  (`can_access` = 1) of every `operator_profile_page_permissions` row of the profile's assignments, with allowed /
  create / edit / delete / print = 1 and approve = 0 (1 for a profile named "Administrator").
* **Page keys**: a legacy key becomes `water.<key>`; keys whose job moved to a cooperative page are translated
  (`journal_entry` -> `rpt.journal`, `chart_of_accounts` -> `coa.accounts`, `general_ledger` -> `rpt.general_ledger`,
  `trial_balance` -> `rpt.trial_balance`, `balance_sheet` -> `rpt.balance_sheet`, `income_statement` ->
  `rpt.income_statement`, `accounts_payable` -> `vend.pay_bills`, `inventory_purchase` -> `vend.receive_bill`,
  `inventory_adjustment` -> `inv.adjust`, `inventory_items` -> `inv.items`, `inventory_history` ->
  `rpt.inv_movement_detail`, `stock_monitoring` -> `rpt.stock_status_item`, `access_control` -> `admin.page_access`,
  `user_configuration` and `profile_cash_gl_configuration` -> `admin.users`, `subsidiary_ledger_report` ->
  `rpt.subsidiary_ledger`, `book_types` -> `coa.lists`, `accounting_management` -> `home.reports`,
  `inventory_management` -> `inv.center`); other retired keys (`WaterPages::RETIRED`: login, register, profile, the
  retired discount pages ...) and keys not registered in `pages` are dropped.
* **User roles**: the user gets `user_roles` for the role of each of its profiles. An assignment with
  `is_super_admin` = 1 also gives the user the seed Administrator role (`roles.is_admin` = 1).
* **Overrides** keep each operator's effective access exactly what it was: for a user (not a super administrator)
  the pages granted by its water roles that none of its own assignments had get a `user_page_overrides` row with
  `allowed` = 0 (reason "Water migration: the operator's own profile assignment did not have this page"). An
  assignment without any permission rows therefore grants nothing, as before.

### members -> water_accounts, parties (phase accounts)

| Target | From |
|---|---|
| water_accounts.* | every column of members, same pkey; `legacy_member_id` = pkey |
| parties (one per account) | party_no from the `party` sequence (a block is reserved once), party_type person, legal_name "First M. Last", display_name "Last, First M.", last / first / middle name, tin, birth_date (not a zero date), address = the account's current address (memberaddress + addresses + cities), status active, src_table `water_acct`, src_id = pkey, notes "Water-service account <account number>" |
| party_crosswalk | source `water_acct`, source_id pkey, source_ref "<account number> <display name>", how created |
| party_role_log | role water, ref_id pkey, action granted |
| water_accounts.party_id | the party |
| water_accounts.customer_id | left NULL: the customer record is made lazily by `WaterAccounts::ensureCustomer()` at the first posting (phase 7 makes it for every account with an open bill) |

An account whose names are all blank gets a party named after its account number (exception). The parties are
written with prepared statements (one audit row for the phase, not one per party); an account that already has a
crosswalk row or a party born from it is linked to it, never duplicated.

`membershipinfo` (the former cooperative membership register, member ids 100001 and up, which are **not** water
account keys) is copied as read-only legacy data and nothing is linked to the water accounts by id or by name. A
water customer is not made a cooperative member. The membership phase writes the register to CSV for review and
imports it only on request (`--import-membership`), because the cooperative database may already hold those members.

### suppliers -> parties + vendors (phase suppliers)

| Target | From |
|---|---|
| parties | party_type organization, legal_name = display_name = supplier_name, phone = contact_number, email, address, src_table `water_supp`, src_id = supplier_id |
| vendors | party_id, name = company_name = supplier_name, contact_person, phone = contact_number, email, address, is_active = (status = Active) |
| party_crosswalk | `water_supp` / supplier_id -> party (how created), `vendor` / vendor id -> party |

A supplier whose exact name already exists as a vendor is mapped to that vendor (`how` matched, nothing created)
and listed in the exceptions as a possible duplicate to review.

## The opening-balance methodology (phase history)

The legacy journals are **not re-posted**. The one general ledger gets exactly two things from the water history:

1. **One invoice per open bill**, so the A/R subsidiary ledger (customer balances, aging, statements, the water
   collection page) carries every balance the former program still had open at cutover. A bill is open when:
   * `bill_readings`: `is_billed` = 1, lifecycle active, `amount_due` > `amount_paid`;
   * `one_time_bills`: status unpaid, lifecycle active, `total_amount` > `amount_paid`;
   * `installment_bills`: status active, lifecycle active, with schedules pending / partial (one invoice per
     contract for the sum of the open schedules);
   * `recurring_bills`: status unpaid / overdue, lifecycle active, `total_amount` > `amount_paid`.

   The invoice is posted through `WaterPosting::postBill()` with the bill's own event key (`WBILL-<reading_id>`,
   `OTB-<bill_id>`, `INST-<installment_id>`, `RBILL-<bill_id>`), so the live collection page settles it exactly like
   a bill posted after cutover, and a second run finds it and posts nothing. Header: the bill's own date and due
   date, document number = the bill's global reference number (else `WB-` / `OTB-` / `INST-` / `RB-` + id), the
   account's customer (made now), the class of the bill's branch, the Water Utility line of business. Entry:
   **Dr A/R** (the A/R account of the bill: the debit line of its legacy journal voucher through `water_coa_map`,
   else the bill code's A/R account, else the `water_ar` default) / **Cr 39999 Legacy Water Balances Clearing** for
   the open balance. Income is not credited again: it was recognised in the legacy ledger.

2. **One opening-balance journal** (`Txn::save('opening_balance', ...)`, number `WOB-<cutover>`, dated the cutover,
   event key `LEGACY-OB` in `water_postings`) with one line per mapped account = the legacy trial balance at cutover:
   `SUM(Debit) - SUM(Credit)` of `legacy_journalentries` with `Status` = 'A' and `TransactionDate` <= cutover
   (voided vouchers, Status 'V', are ignored), as a signed amount (+ debit / - credit). Each A/R account that
   received open invoices is **reduced by the sum of those invoices**, and the difference needed to balance the
   journal is booked to the clearing account.

After both, every imported account stands at its legacy balance, the open invoices sum to the open bills, and the
clearing account nets to zero whenever the legacy ledger balanced (the invoices credit it, the journal debits it by
the same total). The reconciliation the run prints and keeps in the log notes:

```
legacy A/R per account   open bills per A/R account   difference
```

A difference means the former program carried receivables that no open bill explains (or the reverse). It stays in
the A/R account without a bill behind it (control <> subledger), is shown as a WARN row of the verification and in
the exceptions, and has to be settled in the former program before the cutover or with a journal entry afterwards.
With zero legacy transactions the phase posts nothing and reports zeros.

### Opening stock of the water materials (phase history)

For every inventory bill code with a quantity on hand in the former stock ledger at cutover
(`legacy_inventory_stock_movements`), one inventory adjustment `WSTK-<code_id>` (event key `LEGACY-STOCK-<code_id>`)
brings that quantity and value into the one cooperative stock ledger at the bill code's item, offset to the legacy
inventory asset account (else the clearing account). A bill code with stock but no item, or with a negative quantity,
is listed in the exceptions instead. The real water database of this project held no stock (0 movements).

## Verification (phase verify)

Prints a table that can be pasted into the reconciliation report, and exits 0 only when every row is PASS (WARN
rows are information):

* source rows vs target rows for every copied table (same key), and rows that differ from the source;
* chart of accounts: legacy detail + summary accounts mapped, every legacy row in `water_coa_map`;
* users: eligible operators with a user; roles: one per legacy profile in use;
* parties: every water account has a party and a crosswalk row; every legacy supplier is mapped;
* bill codes and defaults resolve (`WaterPosting::mappingProblems()`; an inventory bill code without an item and an
  optional collection-policy account the former program never had are WARN);
* open balance per source (water, one-time, installment, recurring): the water records vs the ledger invoices;
* the opening journal balances; the ledger equals the legacy trial balance for every mapped account; the clearing
  account is 0.00 when the legacy ledger balanced; A/R control vs subledger per A/R account (WARN when different).

Then `Party::findMatches()` queues the probable duplicate parties for Parties > Identity Review (the count is
printed). Nothing is merged by the migration.

## The exceptions list

Every run ends with the exceptions grouped by subject (the console shows the first 25 of each group; the full list is
written to `storage/water_migration/<run>-exceptions.txt`):

* **pre-existing rows** (only with `--allow-existing`): target rows not from the source, rows with a source key but different content;
* **chart of accounts**: codes that got a `W` suffix, accounts whose code category disagrees with the legacy class, accounts typed Accounts Receivable only because a bill code uses them as A/R, detail accounts imported without a parent;
* **default accounts**: water defaults that stay unset;
* **users**: skipped operators (system / deleted), matched users, users whose password was re-hashed and must be changed, operators whose cash account is unmapped, references to skipped operators;
* **page access**: roles without any page, legacy page keys not registered;
* **bill codes**: inventory bill codes without an item, A/R accounts that are not of type Accounts Receivable, missing accounts;
* **accounts / settings**: legacy account ids that could not be mapped;
* **water accounts**: accounts without a name; active accounts without a rate code or pipe size (they cannot be billed until one is assigned);
* **legacy membership / legacy savings**: membership status or type codes the former database does not define, members without a first name, savings accounts of unknown members, savings lines after the cutover; the summary of what was (not) imported;
* **items / opening stock**: items made for inventory bill codes, stock not brought in;
* **suppliers**: suppliers matched to an existing vendor by name (possible duplicates);
* **opening balance**: legacy balances on unmapped accounts, A/R accounts whose legacy balance differs from the open bills;
* **dates**: zero dates replaced; **mapping check**: the rows of `WaterPosting::mappingProblems()`; **ledger**: accounts whose ledger balance differs from the legacy trial balance.

## Cutover on the customer's server (Windows, XAMPP)

The step-by-step procedure (back up both databases, `tools/create_target.php` from the cooperative database,
dry run, run, optional membership import, verify, the reconciliation report, what to settle afterwards) is in
`docs/DEPLOYMENT_XAMPP.md`, section 3. A full rehearsal of it on the real water database and the cooperative sample
database is in `tests/water/evidence/cutover_both/`, and a rehearsal with rollback in `tests/water/evidence/rehearsal/`.

### Rollback (failed rehearsal only)

```
php tools\rollback_water.php --dry-run          (shows what would be removed)
php tools\rollback_water.php                    (undoes the last run that wrote something)
php tools\rollback_water.php --run=20261031-093000-ab12 --source=cowasco_water
```

The rollback is for a **cutover rehearsal that failed before go-live**: it removes what the run wrote (the invoices
and opening journal with their lines and applications, the copied water rows, the legacy_* rows, the parties /
customers / vendors / users / roles / page access / accounts / classes the run made, the crosswalk tables) inside
one transaction, and refuses to run when any `water_postings` row exists that the run did not create (bills or
collections recorded after the migration: the ledger is live and is never rolled back), when later documents are
applied to the migrated invoices, or when the source database is not reachable (it is the list of what was copied).
The audit trail keeps the record of both the migration and the rollback; the `party` number sequence is not
rewound. After go-live the only way back is the database backup of step 2.

## Developer rehearsal with legacy transactions

`php tools/dev/water_legacy_fixture.php` copies the dump to `mnssmc_fixture`, loads
`tools/dev/water_legacy_fixture.sql` (three posted water bills: partly paid, unpaid, fully paid; one unpaid one-time
bill; two official receipts with items; their balanced legacy journal, plus a voided voucher), builds a fresh
`coop_water_fx` exactly like `tests/coop/run.php`, runs the migration with cutover 2026-08-31 and checks: open
invoices = legacy A/R (700.00), the opening journal balances (Dr cash 620.00, Dr clearing 700.00, Cr income
1,320.00, no A/R line), the ledger equals the legacy trial balance per account, the clearing account nets to 0.00,
`WaterAccounts::ledgerBalance()` = the water open balance per account (350.00 / 350.00 / 0.00), and a second run
writes nothing. The second database is dropped afterwards unless `--keep-source` is given.
