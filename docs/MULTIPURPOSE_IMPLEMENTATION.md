# Multipurpose cooperative: what version 27 implements

This note answers section 8 of *Multipurpose Cooperative Accounting Improvement Prompt*: the verified features, the backfill mapping, the assumptions made, and the issues that are still open. The inspection that came before the changes (section 1 of the prompt) is in `docs/MULTIPURPOSE_INSPECTION.md`. How the staff use the new pages is in chapter 14 of the built-in User's Manual, *Parties and Lines of Business*.

The program is renamed **G-SMaRT Integrated Cooperative System**. A company whose program name in `config/config.php` is still "Accounting System" (or "G-SMaRT Accounting") shows the new name.

## 1. What was added and changed

| Area | Files |
|---|---|
| Database | `database/migrations/2026_11_15_multipurpose_party.sql` (existing databases), `database/schema.sql` (new installs), `tools/install.php` |
| Party Master | `app/Party.php`, `modules/party/center.php`, `modules/party/review.php` |
| Consolidated Party Statement | `app/PartyStatement.php`, `modules/party/statement.php`, `app/Xlsx.php` (Excel export) |
| Lines of business | `app/BusinessUnits.php`, `modules/coop/business_units.php` |
| Documents | `app/Txn.php` (snapshot, posted lock, reissue, unit rules), `app/Posting.php` (unit on every ledger line), `modules/txn/_common.php` (views and print-outs), the transaction pages that show the Line of business field |
| Reports | `app/Report.php` (Line of business filter, Excel), `app/reports/finance.php`, `app/reports/coop.php`, `app/reports/budgets.php`, `app/reports/party.php` (four new reports), `app/CoopRecon.php` (receivables and payables in the reconciliation) |
| Pricing and patronage | `app/Lookup.php`, `app/Distribution.php`, `modules/coop/dist_batch.php`, `modules/coa/lists.php`, `modules/inventory/items.php`, `modules/pos/register.php` |
| Forms that pick a party | `modules/customers/center.php`, `modules/vendors/center.php`, `modules/coa/names.php`, `modules/coop/member.php`, `views/layout.php` |
| Interface | `assets/css/app.css` (phone layout of the bar, lists, report tables and the new pages), `assets/js/app.js` |
| Tests | `tests/coop/cases_party.php` (10 cases), `tests/coop/run.php` |
| Manual | `views/manual/chapters/14-parties.html` (new), chapters 1, 3, 5, 6 and 13 (updated), `views/manual/manual.json`, `views/manual/search.json`, 14 screenshots in `assets/manual/img` |

## 2. Verified features

Every line below is checked by the automated tests (`php tests/coop/run.php`, results in `tests/coop/RESULTS.md` and, with the decimal engine in plain PHP, `RESULTS_pure_php.md`), unless it says "checked on the pages", which means it was checked by opening the pages in a browser on the sample database.

| Prompt | What the program does | Verified by |
|---|---|---|
| 2 | One party (Party ID P-000001 ...) for a person or organization; the member, customer, supplier, employee and other-name records each keep their own number, terms, limits and status and point at the party | Test 43 |
| 2 | A new customer, supplier, member or party that shares a TIN, e-mail, mobile or phone number, or name and date of birth with an existing party is stopped with the possible matches; the user picks the existing party or ticks that it is a different one. Nothing is ever linked by name alone | Tests 43, 49 |
| 2 | Identity Review: pairs are found and reviewed; linking needs the "link or move parties" permission and a reason, moves every record and document, keeps the old Party ID as a pointer (crosswalk); a wrong link is moved back; two members are never linked | Test 49 |
| 2 | Roles are given, taken away and restored without deleting anything; role history; a party is made inactive only when no role is active | Test 49 |
| 3 | Lines of business (savings and credit, consumer store, marketing, services, others): each document and ledger line carries its unit; default unit per kind of document; modules switched off by the lines the cooperative runs | Tests 46, 50 |
| 3 | Nonmembers buy and sell where the unit allows: "members only", "nonmembers with approval" (on a new document and on an edit that changes the customer, supplier or unit) | Test 45 |
| 3 | Member and nonmember price levels per unit, price levels with valid-from and valid-to dates; walk-in sales at the nonmember price | Test 45 |
| 3, 7 | Patronage refund on income, purchases or deliveries; only items marked as counting, only units whose member transactions count, nonmember sales never count | Tests 44, 45 |
| 4 | Every document keeps the counterparty name, address, TIN and member number taken from the party when it was issued (invoices, sales receipts, bills, purchase orders, checks, payments, vouchers, official receipts, POS receipts, statements); the label follows the role (Bill to, Supplier, Pay to, Received from); the member number is a secondary line | Tests 44, 48; print-outs checked on the pages |
| 4 | Editing a master does not change issued documents; "Correct printed name" reissues a document with a reason, the permission, and the Approve right or a supervisor's approval; the print-out shows the reissue number; Reissued Documents report | Test 48; approval checked on the pages |
| 5 | One general ledger; each document posts once, balanced; the unit and the branch (class) on every line | Tests 29, 42, 52 |
| 5 | Trial Balance, Balance Sheet, Income Statement, Cash Flows, Statement of Financial Condition and Statement of Operations for the whole cooperative or one line of business; inter-unit due to / due from and inter-unit income / expense eliminated for the whole cooperative | Test 46 |
| 5 | Subsidiary ledgers agree with their control accounts: receivables and payables (open documents), share capital, savings, time deposits, loans | Tests 29, 42, 52 |
| 6 | Consolidated Party Statement: every role, opening, activity, closing, drill-down to each document; "Party owes the cooperative", "Cooperative owes the party", "Member equity interest"; net position shown for information only, nothing offset; by date, unit and branch; print (PDF), Excel and CSV | Tests 44, 46; exports checked on the pages |
| 7 | Posted documents locked: changing amounts, accounts, names, dates or lines needs the "edit posted documents" permission and a reason; deleting a posted document is refused; voiding keeps the document | Test 47 |
| 7 | Confidential member details (date of birth, TIN, contact numbers, addresses) masked on the party pages, Member Information, the Party List and the Member Master List for users without the permission; their saves keep the details | Test 50; Member Information checked on the pages |
| 7 | Excel export of every report and of the party statement | Checked on the pages |
| 8 | The acceptance scenario of the prompt: one member buys on credit, pays, delivers goods as a supplier, is paid by check, saves, borrows, repays and gets a patronage refund; every document shows the member's name in the right field; one Party ID; the party statement agrees with the subsidiary ledgers | Test 44 |
| 8 | Legacy data: one party per record, never merged by name, crosswalk, snapshots, no posted amount changes, migration safe to run twice | Test 51 |

Actual result of this version (2026-10-12, MariaDB 10.11, PHP 8.3): **52 tests passed, 0 failed, 786 checks**, with the bcmath decimal engine and again with the plain-PHP engine. Tests 1 to 42 are the cooperative cases of version 26, run again unchanged; tests 43 to 52 are the new multipurpose cases. Besides the tests, both database files were imported into an empty database, and a version 26 database upgraded with the migration (twice) was compared with a new install: the same columns, indexes, pages and page rights; the subsidiary ledgers of the sample database agree with their control accounts.

## 3. Backfill mapping (the migration)

| Before | After |
|---|---|
| Each member | Its own party. Legal name in natural order (First M. Last Suffix), the list name as the member list shows it; an institutional member is an organization |
| The member's ledger name (Employees & Other Names) | The member's party (not a separate role) |
| Each customer | Its own party (a company name makes it an organization); a job joins its customer's party |
| Each vendor | Its own party |
| Each employee or other name | Its own party |
| Old IDs | `party_crosswalk`: source table, old ID, old name, party, "created" |
| Role history | One "Granted, held at the upgrade" line per record |
| Every document | `party_id` and the name snapshot from its master at the upgrade (`cp_source = 'upgrade'`) |
| Units | SC Savings and Credit (cooperative vouchers) and TR Consumer Store (sales, purchasing, POS, inventory); banking, journals and depreciation stay without a unit until assigned on Lines of Business |

Nothing is merged. Possible duplicates are found afterwards with "Look for duplicates now" on Identity Review and decided by a person.

## 4. Assumptions

1. One currency (Philippine peso); there is no currency filter because no document carries another currency.
2. Branches are the existing Class List; the branch filter of reports and the party statement is the class.
3. Voiding an ordinary accounting document keeps the document and its number and removes its ledger lines, with a full copy in the audit trail, as the program did before. A dated reversal entry instead would leave the receivables aging and the cooperative subsidiary ledgers out of step with the voided document. Cooperative vouchers, distribution batches and loan events keep using reversal vouchers. Posted documents can no longer be deleted.
4. Items count for patronage refund only when marked (the default is "does not count"); after the upgrade, mark them on Lines of Business or the Item List before a purchases or deliveries batch. The income basis keeps counting lines without an item (loan interest, fees).
5. The interest on share capital and patronage refund payable are reconciled as one control account each (their allocation pools share the account), not as a separate subsidiary ledger per member.
6. Tax treatment and rates are not hard-coded: the existing tax codes and withholding table apply as set up by the cooperative.
7. The POS sells through the unit set as the default for point of sale.
8. Screenshots in the older manual chapters were taken before this version: they show the earlier program name in the top bar, and the transaction forms do not show the new Line of business box. Chapter 14 and the customer form of chapter 5 have new screenshots; the field tables of the customer, supplier and other-name forms and the Member Information section describe the new party choice.

## 5. Unresolved issues

1. The masking of confidential details does not cover the Customer and Vendor Centers: a customer or supplier record holds its own billing and contact details, which A/R and A/P staff need. Give those pages only to the roles that may see them.
2. A party picked on a customer, supplier or other-name form must be active; an inactive party is made active again on the Party Center first (or by giving it the role there).
3. Inter-unit eliminations net the inter-unit accounts as a group. They do not pair each due-from with the matching due-to by unit; a difference that does not net is shown on the first inter-unit account so the statements still balance.
4. The by-class statements (Balance Sheet by Class, Income Statement by Class) are not eliminated; their Total column shows inter-unit balances as booked.
