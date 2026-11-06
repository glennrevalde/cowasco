# Delivery report: integrated Multipurpose Cooperative + Water Billing system

One new application built from `cooperative_system (3).zip` (the G-SMaRT Integrated Cooperative System) and
`WaterBillingSystemImport (2).zip` (the stand-alone water-billing program). `accounting-system 2.zip` was not used.
The two original projects are unchanged; this is a separate project and a separate database. This report says what
was implemented and what was executed; every claim of a working feature points at a test that ran.

## 1. Definition of done

| Item | Status | How it is met | Evidence |
|---|---|---|---|
| One application | Done | the cooperative system is the host; the water program runs inside it as the Water Billing module (`water/`), sharing bootstrap, database connection, session, login, menu and permissions | `docs/WATER_INTEGRATION_DESIGN.md` |
| One Party Master | Done | every water-service account belongs to a party; suppliers are cooperative vendors with parties; nothing is merged by name (Identity Review decides) | E2E 7.2b, harness 43, 53 |
| One authoritative inventory ledger | Done | the cooperative item master and stock; water purchases, receipts and adjustments are retired in favour of the cooperative pages; field issuance and meter sales move the same stock | E2E 7.3, harness 54 |
| One general ledger | Done | every water event posts once through `app/WaterPosting.php` -> `Txn::save` / `Posting`; the former water journal is kept read-only and never re-posted | E2E 7.1, `docs/POSTING_EXAMPLES.md` |
| One access and navigation model | Done | one login, one side menu (no second Accounting, Inventory or Membership section), roles and page access for every page | `docs/MENU_MAP.md`, E2E 7.4b |
| Water-billing operations fully retained | Done | readings, tariffs, periods, bills, SOA, one-time / installment / recurring bills, discounts, collections, corrections, disconnection, reports | `docs/RETAINED_REPLACED_MATRIX.md`; tariff baseline 0 differences |
| Historical balances reconciled | Done for the supplied data; methodology tested with legacy transactions | the supplied water database has no bills, receipts, journal lines or stock yet; the opening-balance method is proven on a fixture of legacy bills and journals | `docs/RECONCILIATION_REPORT.md`, `tests/water/RESULTS_LEGACY_LEDGER_FIXTURE.md` |
| No duplicate posting, no lost records | Done | event keys make every posting idempotent; the migration verifies every copied table row for row and a second run writes nothing | E2E duplicate/concurrency checks; rehearsal |
| What cannot be migrated safely is kept read-only | Done | former accounting, inventory, A/P, access and savings tables as `legacy_*`; the former membership register read-only unless its import is chosen | `docs/MAPPING_CROSSWALK.md` |

## 2. What was built, by section of the prompt

**0 Inputs.** Both codebases were read in full before the integration (entry points, routing, schemas, posting,
party/member master, inventory, permissions, prints, reports, settings, tests). The result is plain PHP 8.1+, PDO,
MySQL 5.5+/MariaDB, Apache/XAMPP; all new SQL is written for MySQL 5.5 and was checked against its limits.

**1 Replacement authority.** Accounting, A/R, A/P, treasury and statements: the cooperative ledger (water GL pages
retired after every water event was mapped to cooperative postings). Inventory and purchasing: the cooperative
item master, stock, vendors and purchasing (water inventory pages retired; issuance and meter sales kept as
cooperative stock transactions). Membership and people: the cooperative Party Master and membership (water member
forms replaced by water-service accounts linked to parties). Water operations: the water engine kept as it was.

**2 Identity.** `members` of the water database became `water_accounts` (the cooperative owns `members`), one party
each, with the crosswalk `party_crosswalk`. One party can hold several water accounts; the Open Account form searches
the parties on file and stops on a probable duplicate. The Party / Member Center has a Water tab; confidential member
details stay behind their permission. A water customer is never made a member.

**3 One posting engine.** `app/WaterPosting.php` is the only place water events reach the ledger. Accounts come from
the bill codes, the collection policy, the cashier's user account and the Water group of Module Default Accounts;
nothing is assumed, and a bill code whose A/R is not a receivable or whose credit is not income/liability is refused
with the reason (`WaterPosting::mappingProblems()`). A posted bill is an invoice against the party's customer; a
receipt is one payment applied to the bills it settles; penalties are finance charges; corrections void and replace
with a link (`reversal_of_id`); issuances are inventory adjustments. Every document carries its event key, source,
branch class, the Water Utility line of business, the user and the audit trail. Documents of water events can be
changed only by the water module (the cooperative payment, credit memo and POS screens do not offer them). The
Water Utility line of business gives the water statements and keeps the consolidated ones from counting twice.

**4 Inventory.** Inventory bill codes are linked to items of the one item master (the migration makes the items);
opening stock from the former stock ledger is brought in at cut-over; Material Issuance issues and returns at
average cost; a meter sold on a bill takes the stock out with its cost of goods sold.

**5 Cooperative modules.** All of them remain and pass their 54-case suite (members, shares, savings, time deposits,
loans with approvals, charges, waivers, teller, passbooks, patronage refund, ISC, CDA, A/R, A/P, POS, assets,
banking). The Consolidated Party Statement shows the water bills, collections and adjustments in their own section,
without netting them against other obligations or equity.

**6 Migration and safeguards.** `tools/create_target.php` copies the cooperative database into a new target and
verifies it; `tools/migrate_water.php` brings in the water database in nine restartable, idempotent phases with a dry
run, a pre-flight, crosswalks, an exceptions list, a verification table and the opening-balance method that never
re-posts history; `tools/rollback_water.php` undoes a failed rehearsal. Users and roles keep the former operators'
page access. Server-side page and endpoint authorization, CSRF tokens, parameterized SQL, row locks, idempotency keys
and the immutable audit trail apply to both domains. Messages: the water pages keep their confirmation modals and
show notices in the shared style; refusals say what to do.

**7 Acceptance tests.** See `docs/TEST_EVIDENCE.md` (all PASS, with the problems found and fixed).

**8 Delivery.** The items below.

## 3. Deliverables

| Required | Where |
|---|---|
| Complete source codebase | the delivered ZIP (this folder) |
| Production-safe SQL schema and ordered migrations | `database/schema.sql`, `database/triggers.sql`, `database/migrations/*.sql` (oldest first; the water one is `2026_12_01_water_billing.sql`) |
| Importable databases | `database/accounting_system_clean.sql` (every table and set-up list, the water billing set-up included, no transactions) and `database/accounting_system.sql` (the same with sample data: the sample company, the sample cooperative and a sample water utility made through the water pages); written by `tools/export_sql.php`, rebuilt with `tools/install.php --water-starter` / `--water-demo` |
| Mapping / crosswalk documentation | `docs/MAPPING_CROSSWALK.md`, `docs/WATER_MIGRATION.md` (field by field); the former program's export files: `docs/WATER_IMPORT_FILES.md` |
| Configuration template | `config/config.example.php` (no secrets; the delivered `config/config.php` holds XAMPP defaults and no password) |
| Seeded roles and default accounts | `tools/install.php` / the migrations: 17 roles with page rights, the chart and module default accounts including the Water group (`docs/DEPLOYMENT_XAMPP.md` §2); `tools/water_starter.php` points the Water group at water accounts of the chart for a new installation |
| Install / run commands for a new XAMPP machine | `docs/DEPLOYMENT_XAMPP.md` §2 |
| Deployment / migration instructions | `docs/DEPLOYMENT_XAMPP.md` §3-4, `docs/WATER_MIGRATION.md` |
| File-change inventory | `docs/FILE_CHANGE_INVENTORY.md` |
| Features retained / replaced matrix | `docs/RETAINED_REPLACED_MATRIX.md` |
| Screen / menu map | `docs/MENU_MAP.md` |
| Sample posting examples | `docs/POSTING_EXAMPLES.md` (read from the ledger after the end-to-end test) |
| Accounting reconciliation report | `docs/RECONCILIATION_REPORT.md` |
| Migration exceptions list | `docs/MIGRATION_EXCEPTIONS.md` |
| Test evidence | `docs/TEST_EVIDENCE.md`, `tests/coop/RESULTS*.md`, `tests/water/RESULTS_*.md`, `tests/water/evidence/` |
| Disclosed limitations | section 4 below |

## 4. Remaining limitations (disclosed)

1. **Billing history.** The supplied water database holds 6,888 accounts, rates and settings but no bills or
   receipts; its transfer of historical balances is proven on a fixture of legacy transactions
   (`tools/dev/water_legacy_fixture.php`, 37 checks PASS). The billing history comes in the former program's export
   files instead (Account Master and Billing Master of 3 July 2026), which Data Import Management imports end to end
   (`docs/WATER_IMPORT_FILES.md`, 48 checks PASS): 69,366 bills and 57,187 receipts as history, the open balances in
   the ledger. 6,541 bills whose payment evidence does not reconcile (receipts that also paid other months, Payment
   Flag without a receipt) stay in Review for the cooperative to decide; they are held out of the opening balances.
2. **Identity Review is manual.** The rehearsal queued 1,920 possible duplicate pairs (mostly one person with
   several connections recorded as separate "members" in the former program, e.g. "LEAH D. 1" / "LEAH 2"). They are
   decided one pair at a time on Parties > Identity Review; there is no bulk approval.
3. **The former membership register and savings** (361 members, 335 accounts, 28,713,513.24) are imported only on
   request (`--import-membership`), because the cooperative database may already hold them; the decision is the
   cooperative's. The import was tested twice on the rehearsal database (idempotent, rollback clean).
4. **Water receivable features the former program did not have are not added**: overpayments become change at the
   cashier (no credit balance on the water account), there are no customer deposits/prepayments applied to bills
   automatically, no VAT on water bills, and penalty waivers are the cashier's manual penalty/compromise amounts as
   before. Deposits can be billed with a bill code that credits a liability; their later application is a manual
   accounting entry.
5. **Meter replacement/transfer and owners/occupants**: the supplied water code and schema have no such records
   (no meter serials or occupant tables); they were not invented. Joint holders, addresses, contact channels,
   connection status and disconnection history are kept.
6. **The water pages keep their own page code and layouts**, styled to match the cooperative pages (shared shell,
   fonts, colours, raised surfaces, plain status labels) rather than rewritten into the cooperative page framework.
   They run the non-strict SQL mode they were written for; every ledger write they cause runs in strict mode.
7. **Water branches, segments and divisions** are no longer asked on the water forms (version 30): every water
   transaction takes the Class of the accounting books, chosen on the form, and the water business is the WU line of
   business. The branch, segment and division values already on file are kept, and Organization Units still opens
   for them, out of the menu.
8. **Tested environment**: Apache 2.4 + PHP 8.3 + MariaDB 10.11 on Linux. MySQL 5.5 itself and Windows XAMPP were not
   available to run; the SQL was checked against MySQL 5.5's rules (column types, defaults, one auto-timestamp per
   table, index lengths, no CTE/window/JSON), and the cooperative system's own MySQL 5.5 conventions were followed.
9. **Multi-company**: the water module installs per company database through the same upgrade script (the platform's
   System Updates runs it on every company), but the acceptance tests ran on a single company.
10. **Legacy water manuals**: the former PDF/DOCX manuals described retired pages; they were replaced by a manual
    generated from the updated in-app content (`tools/build_water_manual.py`), which is shorter than the former one.

## 5. Where to start

1. `docs/DEPLOYMENT_XAMPP.md`: install, or cut over from the two databases.
2. Log in as admin, finish the set-up the program asks for (Water Settings readiness, bill code accounts, cashier cash
   accounts), then decide the Identity Review pairs and the items of `docs/MIGRATION_EXCEPTIONS.md`.
3. `docs/MENU_MAP.md` for where everything is; the in-app User's Manual and the Water Help Center for procedures.

## 6. Version 30: the additional requests for the Water + Coop systems (v2)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v2.xlsx` is implemented in full; every request is mapped to
its code and its test in `docs/ADDITIONAL_MODULE_V2_CHECKLIST.md`. In short: dates typed as numbers; the member
grouping lists (savings group, address sub-group, account group, account class) with add, rename, switch off and
delete, the address sub-group shown and set in Water Billing; confirmation questions asked once, in the system's own
dialog; changes to saved journal entries with an explanation and a second user's approval (Chart of Accounts >
Journal Entry Changes); members described by their type on every name list and listed as customers; the
incremental member search on Savings Deposit / Withdrawal; the Class required on the cooperative teller, savings,
time deposit, batch, loan, waiver, restructure and penalty / accrual transactions and on every Water Billing form
that posts, in place of the branch, segment and division. The upgrade script is
`database/migrations/2026_12_20_member_lists_class_je_changes.sql`; `UPGRADE_v30.txt` says what to set up after it
(the classes, first of all).


## 7. Version 31: the additional requests for the Water + Coop systems (v3)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v3.xlsx` is implemented in full; every request is mapped to
its code and its test in `docs/ADDITIONAL_MODULE_V3_CHECKLIST.md`. In short: the Bill Codes edit dialog fully
visible with its saved accounts; the General Ledger balance on each account's normal side; the member's Water
Defaults, Customer Defaults and Vendor Defaults on the membership form, and one name for the member in every line of
business; the Savings Account Center, the Savings Transaction Center, Savings Account Transaction and the Time
Deposit Center in the layout of the cooperative's former program (toolbars, right-click menu, one transaction number
series, the transaction code as the basis of the entry, error corrections, cheque details, back entries, machine
validation, accrued interest withdrawals with CAIW); the transaction code set-up with the opposite account (GL or
the teller's cash), and the cash account of the user's profile; passbook printing with line numbers, the account name
and number on every page, the inside cover, the fold left blank and error corrections printed negative, with a reset
of the print line; and the official receipt without savings, share capital and loan payments. The upgrade script is
`database/migrations/2026_12_27_member_defaults_savings_center.sql`; `UPGRADE_v31.txt` says what to set up after it
(the tellers' cash accounts, the transaction codes and the passbook positions).

## 8. Version 32: the additional requests for the Water + Coop systems (v4)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v4.xlsx` is implemented in full; every request is mapped to
its code and its test in `docs/ADDITIONAL_MODULE_V4_CHECKLIST.md`. In short: the machine validation of the deposit /
withdrawal slip and of the loan payslip prints only its two lines (no title, address or page number), with top,
right, bottom and left margins set on Passbook Types; the passbook pages, reprints, footer and cover print on one
sheet each with only the passbook lines; Clear empties every field of Savings Account Transaction and a check
deposit takes its amount from the cheques; and Loan Collection is the Loan Collection Form of the former program
(OR Entry with the payment modes Cash, Check and Bank, the open loans with the amortization schedule and the
member's loan ledger, the amortization due details and the payment applied to the penalties, interest and principal
with Override Payment, Print to Passbook, Show Print Preview, Error Correct of an erroneous payslip, Update Passbook,
and the Loan Collections list by date range with Delete, Print and the class of the entry). The upgrade script is
`database/migrations/2027_01_03_loan_collection_error_correct.sql`; `UPGRADE_v32.txt` says what to set up after it
(the slip margins and the browser's print settings).

## 9. Version 33: the additional requests for the Water + Coop systems (v5)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v5.xlsx` is implemented in full; every request is mapped to
its code and its test in `docs/ADDITIONAL_MODULE_V5_CHECKLIST.md`. In short: Loan Collection and the Savings
Transaction Center are under the Teller menu, and every transaction of the teller on them is on the Daily Collection
& Disbursement Summary (by source, error corrections taken back, net cash equal to the drawer); choosing a member on
Member Disbursement works again; Loan Release is the Loan Release Form of the former program, with the Release Type
Cash or Cheque, the cash or bank account chosen and the deductions added, deleted or changed; the release is a
Banking transaction (a Cash Voucher of Banking, or a check of Write Checks) with its class and voucher ID, printed in
the former Cash / Check Voucher format and listed on Loans > Loan Release Vouchers; the Loan Release Statement follows
the former format without the schedule (P.B. No., yearly amortization totals, prepared and approved by); and the Loan
Accounts Center is the former center with Collection, Ledger, Internal Ledger, View Schedule, Update Passbook,
Refresh, Filter, Print and Search, the printable Loan Ledger Form (passbook view or every entry) and the Internal
Ledger with the additional interest and penalty of a past-due loan by the product's set-up. The additional interest
of the Internal Ledger is computed and shown, not posted: it is collected by raising the interest with Loan
Adjustments and Override Payment on Loan Collection. The upgrade script is
`database/migrations/2027_01_10_teller_menu_loan_release_vouchers.sql`; `UPGRADE_v33.txt` says what to set up after it
(the cash and bank accounts, the classes, the voucher ID series, the product's additional interest and the rights on
Loan Release Vouchers).

## 10. Version 34: the additional requests for the Water + Coop systems (v6)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v6.xlsx` is implemented in full; every request is mapped to
its code and its test in `docs/ADDITIONAL_MODULE_V6_CHECKLIST.md`. In short: the Capacity to Pay entries and the Credit
Investigation of a loan application are where the borrower's next loan starts (the updated data shows, the rest stays;
an income line without a source name is now saved); passbook printing follows the former program's Passbook Type
Setup: printing starts on line 1, Start Line Skip and No. of Line Skip leave the centre fold blank, after Max Lines the
next entry goes to line 1 of the next page after the header, all in tups; the loan passbook has its own design (header:
Loan Type, Term, Due Date; lines: Date, Reference No., Release, Loan Payment, Loan Balance, Interest Payment, Fines,
Teller ID; the release on line 1), separate from the savings passbook; and Passbook Types has a design facility for the
cover, header, body and footer in place of the former Crystal Report templates (fields, places, widths, alignment,
formats, any passbook size, condensed print, a live drawing, test pages), with the line numbers printed beside the
dates. Sheet3 (a screenshot of the restructure page with no note) was checked; a renewal or restructuring now starts
from the borrower's last Capacity to pay and investigation. The upgrade script is
`database/migrations/2027_01_17_passbook_designer.sql`; `UPGRADE_v34.txt` says what to set up after it (each passbook
type checked and saved, a test page, the passbook type of each product).

## 11. Version 35: the second v6 workbook (Sheet3)

The second workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v6.xlsx` (six screenshots with notes on Sheet3) is
implemented in full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V6B_CHECKLIST.md`. In
short: the Loan Application Center has a Delete button for each application never released (with its reason in the
audit trail; a released application stays); the validation window closes by itself once printed and the teller is back
on Savings Account Transaction (Loan Collection too); Savings Account Transaction is cleared for the next transaction
once the transaction is printed; a savings or loan passbook whose unprinted lines go on to the next pages prints them
all in one run, the print window asking "Turn the passbook to page 2" (3 and so on) between pages, with one
confirmation at the end; and a loan payment pays the penalties due, then the interest due, then the principal, the
whole payment going to the principal when no penalty and no interest is due. There is no migration: `UPGRADE_v35.txt`.

## 12. Version 36: the additional requests for the Water + Coop systems (v7)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v7.xlsx` (two screenshots with notes on Sheet1) is implemented in
full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V7_CHECKLIST.md`. In short: the new
page Chart of Accounts > Account Types adds account types of your own, such as Cash and Loans Receivable, each behaving
as one of the fifteen built-in types (so every module treats its accounts as before) and linked to a section of the
Statement of Cash Flows (cash and cash equivalents, operating, investing or financing), which takes its cash and its
sections from the types and ties to the cash accounts; every account type is a classification of its own on the Balance
Sheet and the Income Statement; a main account shows the total of its sub-accounts on the Chart of Accounts and on the
statements; and the new reports Balance Sheet Summary and Income Statement Summary show the main accounts only, each with
the total of its sub-accounts. One migration: `database/migrations/2027_01_24_account_types.sql` (`UPGRADE_v36.txt`).

## 13. Version 37: the additional requests for the Water + Coop systems (v8)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v8.xlsx` (four screenshots with notes on Sheet2) is implemented
in full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V8_CHECKLIST.md`. In short: Loan
Adjustments has a Print to Passbook option box, and a loan adjustment is now a line of the loan passbook (code AJ),
printed as soon as it is recorded or later with Update Passbook; the search box of the Loan Statement of Account lists
the borrowers with outstanding loans (name, loan product, loan no.), narrowed by each letter of a name, a loan no. or a
loan product typed (Loan Adjustments finds its loan the same way); and Interest on Share Capital and Patronage Refund no
longer ask to post on Compute: the computation opens for review, with what each member owes the cooperative, members
are removed one by one or together with the reason, then the batch is reviewed, approved and posted. One migration:
`database/migrations/2027_01_31_adjustment_passbook.sql` (`UPGRADE_v37.txt`).

## 14. Version 38: the additional requests for the Water + Coop systems (v9)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v9.xlsx` (three screenshots with notes on Sheet1) is implemented
in full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V9_CHECKLIST.md`. In short: on an
Interest on Share Capital or Patronage Refund batch, pointing at a member's basis (or tapping it on a phone) shows where
it comes from: the share capital balance stretch by stretch, the balance-days and their average, or the documents that
earned the patronage, then how the member's amount follows from it; the list of members has its totals (Basis,
Computed, Adjustment, Net) with the members removed and, while it is searched, the members shown; and a computation never
posted (in review, reviewed or approved) can be deleted, from the batch or from Distribution Batches, with the reason
kept in the audit trail. No migration (`UPGRADE_v38.txt`).

## 15. Version 39: the additional requests for the Water + Coop systems (v10)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v10.xlsx` (ten screenshots with notes on Sheet1) is implemented
in full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V10_CHECKLIST.md`. In short: for the
move from the former system, members, savings and share capital accounts, and time deposits are imported from CSV files
(Import Member, Import Savings, Import Time Deposit), each with a downloadable template and a review grid where every
row is checked, corrected in place or removed before the upload, which saves all rows or none; the accounts are made
Active and their balances entered with the savings transaction code chosen (INI Initial Savings Import, against Opening
Balance Equity, is added). Batch Deposit and Batch Withdrawal post a CSV file of account numbers and amounts through the
Savings Transaction Center; Import items brings in items of every type; Hold and Unhold earmark and release an amount
of a savings account; the GL Account box of a new savings transaction code opens; the Official Receipt offers every GL
account except those kept by the savings, time deposit, loan, receivable and inventory ledgers; the menus are renamed
(Financial, Members & Others) and put in the cooperative's order, with Member Disbursement (Cash Voucher) and
Cooperative Vouchers out of the menu. One migration, `2027_02_21_menus_imports_codes.sql` (`UPGRADE_v39.txt`).

## 16. Version 40: the additional requests for the Water + Coop systems (v11)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v11.xlsx` (two screenshots with notes on Sheet2) and the request
for a checker that came with it are implemented in full; every request is mapped to its code and its test in
`docs/ADDITIONAL_MODULE_V11_CHECKLIST.md`. In short: **Import Loans**, on the title bar of the Loan Accounts Center,
brings the loans of the former system in from a CSV file with the workbook's columns, choosing the balancing GL account.
Each loan is rebuilt from its release (its amortization schedule, the totals paid laid on the amortizations as paid on
their due dates), the past due interest of a matured loan and the overdue penalties are worked out unless the file gives
them, and the loans are reviewed before an all-or-none upload that makes them Active with their ledger and one journal
voucher per cut-off date and balancing account. Import Member has a second template with every other detail of the
member. On every upload review a row with a problem is shown in red, is checked again as it is corrected, and the red
rows can be removed at once. No migration (`UPGRADE_v40.txt`).

## 17. Version 41: the additional requests for the Water + Coop systems (v12)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v12.xlsx` (nine screenshots of Water Billing pages with notes on
Sheet3) is implemented in full; every request is mapped to its code and its test in
`docs/ADDITIONAL_MODULE_V12_CHECKLIST.md`. In short: the member search of the individual Bill Readings and of the
Statement of Account works again (every search had failed on a quoted result limit) and finds a member by any part of the
name, the account number or the street; each rate code has a Computation, Ladder Rate Billing (each tier's cubic meters at
its rate, added up: 13,230.50 for the workbook's 250 m3) or Straight Rate Billing (as before: 13,850.00), applied to every
water bill and to the reading preview; Issue Individual Charge bills several charge categories at once, each with its own
amount; Update Bill Record changes the charge category and the date issued of an uncollected bill by issuing it again
(the invoice replaced by "-R1"); the paid bill-code amounts of the official receipt are changed only with a new special
permission; the Collection Summary runs over a period of dates and times; Disconnection Management no longer slides under
the menu. One migration, `2027_03_07_water_ladder_billing.sql` (`UPGRADE_v41.txt`).

## 18. Version 42: the additional requests for the Water + Coop systems (v13)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v13.xlsx` (Sheet4, five screenshots with notes) is implemented in
full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V13_CHECKLIST.md`. In short: the
transaction history of the Inventory Center shows the balances after each movement in the order of the transaction dates,
so a back-dated transaction takes its place and no longer throws the balances off; **Water Billing → Billing → Fixed Share
Capital Billing** keeps each member's fixed share capital contribution (members with share capital only: the open share
capital account, frequency, start date, posting day and amount; Edit, Hold/Resume and Delete), posts the bills on their
day with no journal entry, and the water cashier collects them with the water bills: each collection is a deposit to the
share capital account by the savings transaction code chosen on the page (Dr cash, Cr share capital), a row of the
Savings Account Transaction ready for the passbook, on the receipt as FIXED SHARE CAPITAL and in the collection reports;
the Collection Summary has a Report type, Summary (as before, with a Share capital column) or Detailed (a column for each
code name collected). One migration, `2027_03_14_share_capital_billing.sql` (`UPGRADE_v42.txt`).

## 19. Version 43: the additional requests for the Water + Coop systems (v14)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v14.xlsx` (Sheet5, two screenshots with notes) is implemented in
full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V14_CHECKLIST.md`. In short: **Fixed
Share Capital Billing** has a **Term (days)** (the due date of each bill is its posting date plus the term; blank, the
bill has no due date, open), shown at the cashier with a Late mark once past; **Batch billing** puts several members
with their share capital accounts on fixed billing with the same amount, frequency, start date, posting day and term,
every one or none; **Import** takes the members from a CSV or Excel file (templates on the page) and shows every line
for review, in red with its problem when it has one, to correct in place or remove, before Post billing; **One-Time
Billing → Import Charges** does the same for many members' charges, each line with its own charge category and amount,
then issues one bill and one invoice per line as Issue Individual Charge does. One migration,
`2027_03_21_share_capital_terms_batch.sql` (`UPGRADE_v43.txt`).

## 20. Version 44: the additional requests for the Water + Coop systems (v15)

The workbook `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v15.xlsx` (Sheet6, six screenshots with two notes) is implemented
in full; every request is mapped to its code and its test in `docs/ADDITIONAL_MODULE_V15_CHECKLIST.md`. In short: a
**joint account** and an **institution** have the member form of the former program, **Joint/Institution Details**
(the membership name, the authorized representatives with their position or relation, the residence and office
addresses, the contact list) and **Membership Information** (the passbook number with **Get passbook number**, the
membership and PMES dates, the type, the status, the cluster and segmentation), with the status and type in large
letters; **Family and beneficiaries**, **Financial** and **Loan and savings grouping** are not shown for them. No
migration (`UPGRADE_v44.txt`); the sample database has a joint account and an institution.

## 21. Version 45: a correction of version 44

A water-service account with no first name (an organization of the former water program, or the account of a joint
account or an institution opened from Water Defaults) showed no name on Reading Entry and on other water lists, because
the lists joined the last name and the first name and the database returns no value when one part is missing; the
reading lists also showed the account's internal ID as its account number. Every water list now shows the last name
alone when there is no first name, and the account number where it says Acct (`UPGRADE_v45.txt`).

## 22. Version 46: the additional requests for the Water + Coop systems (v16)

The seven requests of `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v16.xlsx` (`docs/ADDITIONAL_MODULE_V16_CHECKLIST.md`).
A joint account can be a **Regular - Joint** member, on the same form as Associate - Joint. The **address sub-groups**
are added, renamed, switched off and deleted beside every box that offers them and on Water Billing > Service &
Materials > Address Sub-groups. **Reading Entry** edits or deletes an unposted reading (with a reason in the audit log);
a posted reading stays locked. Every list box and search of **Water Billing** is **incremental** (from the first letter,
the names that start with it first, the keyboard). The **time deposit imbalance** of the workbook came from the
reconciliation: its subsidiary ledger of 2320 left out the accrued interest the members had withdrawn before maturity
(CAIW), which the journal entries had rightly debited to 2320; the reconciliation, the time deposit's Interest accruals
and the TD reports now deduct it. The water receipts print **Printed** or **Pre-Printed** (Company, Security & Closing
Date > Water Billing), on forms set up on **Receipt Layout Setup** as passbooks are (paper, margins, where each entry
prints, a test page). One migration (`UPGRADE_v46.txt`), for MySQL 5.5.
