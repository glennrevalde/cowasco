# Water Billing + Cooperative integration: design notes for developers

This project is the G-SMaRT Integrated Cooperative System (plain PHP 8.1+, PDO, MySQL 5.5+/MariaDB, Apache/XAMPP)
with the COWASCO Waters water-billing program merged in as the **Water Billing** module. One database, one login,
one session, one menu, one Party Master, one inventory ledger, one general ledger.

## Layout

| Path | What |
|---|---|
| `app/`, `modules/`, `views/layout.php`, `index.php`, `login.php` | the cooperative system (unchanged structure) |
| `app/WaterPages.php` | the water pages: keys, menu groups, retired pages and their replacements, endpoint→page map |
| `app/WaterAccounts.php` | water-service accounts (`water_accounts`, the former water `members`) and their party / customer links |
| `app/WaterPosting.php` | **the only** place water events post to the ledger (invoices, payments, finance charges, credit memos, inventory adjustments) |
| `app/reports/water.php` | the water reports run by the accounting report engine (`modules/reports/run.php`) |
| `water/views/*.php` | the water pages (each is a full HTML page; it includes `../common/navbar.php`, the unified shell) |
| `water/process/*.php` | the water AJAX/POST endpoints (called by the pages as `../process/x.php`) |
| `water/common/*.php` | water helpers; `water/database/config.php` is the compatibility bootstrap |
| `database/migrations/2026_12_01_water_billing.sql` | creates the water tables (empty), the posting bridge tables, the menu, settings, defaults |
| `tools/migrate_water.php` | copies the former water database into the target database (dry run, checkpoints, idempotent) |
| `app/WaterSchema.php` | the two compatibility views: re-created by a restore, detected as missing by the upgrade check |
| `tools/create_target.php`, `tools/upgrade.php` | the target database made from the cooperative database; pending upgrade scripts run from the command line |
| `tests/coop/cases_water.php` | the water cases of the cooperative test suite (`php tests/coop/run.php`; they build on its multipurpose cases) |
| `tests/water/*` | the HTTP end-to-end tests, backup/restore, upgrade scripts twice, tariff baseline comparison, migration rehearsal |

## The compatibility layer (what the water code sees)

`water/database/config.php` loads `app/bootstrap.php` and defines the names the water code always used:

* `getDB()` → `DB::pdo()` (the one connection; `DB::relaxSqlMode()` puts the session in the non-strict SQL mode the
  water code was written for — MySQL 5.5's default).
* `isLoggedIn()`, `requireLogin()`, `requireGuest()`, `e()`, `csrfToken()`, `verifyCsrf()`.
* `$_SESSION['user_id'|'operator_id'|'username'|'full_name'|'cash_account_id'|'is_super_admin'|'role'|...]` are filled
  from the logged-in cooperative user on every request (`water_sync_session()`); `cash_account_id` =
  `users.coop_cash_account_id` (the cashier's cash account, set on System Administration > User Accounts).
* `redirect($url, $message, $type)` and `flash()` (no arguments) are the water forms of the shared helpers
  (`app/helpers.php`); the message is kept in `$_SESSION['flash']` (the cooperative pages use `acct_flash`) and the
  shell shows it as a dismissible notice.
* `url('water.<key>')` gives the address of a water page (`water/views/<key>.php`); `base_url()` works from water pages.

`water/process/accessControlHelpers.php` answers `requirePageAccess()`, `requireProcessAccess()`,
`userCanAccessPage()`, `access_is_superadmin()` ... through `Access` with the page key `water.<key>`
(`WaterPages::ALIASES` lets detail/print pages inherit their owner's right; a POST needs Create or Edit).

`water/common/navbar.php` renders the unified shell (same look as `views/layout.php`, built from `build_menu()`),
then the page opens `<div class="main-content">`. It also loads `water/common/theme.css` and `theme.js`, which bring
the water pages' own styles in line with the rest of the system without changing their layouts or logic: the fonts
the pages ask for ("Sora", "JetBrains Mono") are mapped to the system fonts by `water/common/fonts.css` (nothing is
downloaded), cards and buttons get the same raised look, status capsules become plain coloured text, form labels are
in normal case, and the former near-black primary buttons take the system blue. The menu loop uses `$nav_*`
variables only, so it never overwrites a page's own variables.

## Tables

* Water tables keep their names, except `members` → `water_accounts` (+ `party_id`, `customer_id`, `legacy_member_id`),
  `audit_logs` → `water_audit_logs`, and the legacy accounting/inventory/access tables → `legacy_*` (read-only).
* `bill_codes.item_id` maps an inventory bill code to an item of the cooperative item master.
* `inventory_issuances.txn_id`, `site_id` and `inventory_issuance_items.item_id` link a material issuance to the
  inventory adjustment that moved the stock.
* `water_postings`: every ledger document a water event produced (event_key UNIQUE = idempotency, source, doc no,
  water account, party, txn, amount, status posted/voided/replaced, reversal_of_id, reason, branch/segment/division).
* `water_coa_map`, `water_user_map`, `water_migration_log`: the migration crosswalks and checkpoints.
* `chartofaccntstbl` is a VIEW over `accounts` (AccntID = accounts.id, Status A/I, Type 1..5 by category) so the
  remaining legacy SELECTs of the water pages read the one chart of accounts. Never write to it.

## Posting rules (WaterPosting)

| Water event | Ledger document | Entry |
|---|---|---|
| water bill posted (snapshot) | `invoice`, txn_no = bill's global ref no (or WB-id) | Dr A/R of the bill code, Cr income of the bill code (net; or gross + Dr discount account when `water_discount` is mapped) |
| one-time / installment / recurring bill | `invoice`, txn_no = OTB-/INST-/RB- + id | same; an inventory bill code puts the item on the line → stock out + COGS by the cooperative inventory |
| bill edit before collection | void the invoice, post `invoice` "no-R1" | linked through water_postings.reversal_of_id |
| bill adjustment debit / credit | `invoice` "no-ADJn" / `credit_memo` applied to the bill | |
| collection (OR) | `finance_charge` per penalty / compromise (Dr A/R, Cr income), then `payment` per A/R account (Dr cash, Cr A/R) applied to the invoices | txn_no = receipt no |
| collection reversal / refund | void payment + finance charges, REV-<receipt> row | |
| material issuance | `inventory_adjustment` (Dr expense, Cr inventory) | |

Legacy functions the water code calls (`wc_*`, `bl_ac_*`, `jv_*`, `otb_*`, `ib_*`, `rb_create_journal_for_bill`,
`issuance_*`) are kept as thin wrappers over `WaterPosting`; they return the keys the callers store
(`journal_head_id` = transactions.id, `journal_entry_id` = the document number).

### Where each account comes from (no universal debit/credit is assumed)

| Amount | Account | Set on |
|---|---|---|
| Receivable of a bill | the bill code's A/R account (must be of type Accounts Receivable, else the bill is refused with the reason) | Water Billing > Bill Codes; default Module Default Accounts > Water A/R |
| Metered water sales, minimum charge, fees | the bill code's income account (Income, Other Income or a liability for deposits) | Bill Codes |
| Member / senior / PWD discount | netted into income as the former program did, or a separate debit line when "Water Discounts Granted" is mapped | Module Default Accounts > Water |
| Penalty, compromise | the collection policy's penalty and compromise income accounts (a finance charge on the receipt) | Water Settings > Collection Policy |
| Cash of a collection | the cash account of the cashier's user account, else the Water default cash | System Administration > User Accounts |
| Material issued to the field | the expense account chosen on the issuance; the item's inventory asset account | Material Issuance; Item List |
| Meter or material sold on a bill | the bill code's item: income of the bill code, COGS and asset of the item | Bill Codes (item link); Item List |

`WaterPosting::mappingProblems()` lists every bill code or default that would be refused (Water Billing > Account
Mapping Validation report); the migration prints the same list.

### Ownership of water documents

A ledger document made for a water event belongs to the water module. `Txn::save` (edit, or applying a payment or
credit to it), `Txn::void`, `Txn::delete` and `Txn::blockers` ask `WaterPosting::guard()`, which refuses unless the
change comes from a `WaterPosting` method (`WaterPosting::owning()` marks them while they run). The cooperative
Receive Payments, credit memo and POS screens therefore do not offer water bills (`Balances::openReceivables()`
leaves them out): they are collected at Water Billing > Collections (Cashier), so a bill's status in the water
records and its invoice in the ledger are always settled together. Opening balances of the migration (`LEGACY-*`)
are refused with a pointer to `tools/rollback_water.php`.

## Business-unit and branch dimensions

Every water document carries `business_unit_id` = the Water Utility unit (WU) and `class_id` = the class of the
water branch (`branches.class_id`, made from the branch name when missing). A document without a branch (a recurring
bill generated from a plan, a material issuance) takes the utility's only branch when it has exactly one. Segment and division stay on the water
records and in `water_postings` (they are water reporting dimensions, not ledger dimensions).
