# The accounting update to G-SMaRT Accounting version 40 (version 60): checklist

The request (15 November 2026): "Update the accounting features of the watercoopsystem using this zip as the reference
of the update. Do not include in the update the integration of the members name to invoices, sales receipts, cash
voucher, check vouchers, journal entries, and other integration. Give me only the updated files preserving their
folders. Also don't touch the integration of the members to waterbilling. Just update the features that are purely
related to accounting system."

The reference, `accounting-system (2).zip`, is G-SMaRT Accounting version 40. Its versions 26 to 34 were merged in
version 53 (from `accounting-system (6).zip`, version 34, whose upgrade notes v28 to v34 are the same as the new
zip's); this update takes its versions 35 to 40. The reference has no member of a cooperative at all: what it changes is
accounting. The rule kept throughout: the program's member links stay exactly as they are, and no new feature adds a
member anywhere.

## How it was merged

- Each file the accounting changed from version 34 to 40 (46 program and manual files) was merged three ways: the
  version 34 file as the base, this program's file as ours, the version 40 file as theirs (`git merge-file`). 28 merged
  by themselves; the 18 with conflicts were joined by hand, keeping the cooperative and water changes (the party picker
  on the customer and supplier forms, the members by their member type on the customer lists, the line of business on
  the POS price checks, the approval flow of journal entry changes, the loan release on the voucher lists, the water
  receipt settings, the Excel export of the reports, the program's Balance column of the General Ledger and its account
  types).
- The 12 new program files of the accounting were added as they are, then fitted (below). The schema was merged the
  same way; the accounting's three migrations are one script, `database/migrations/2027_05_09_accounting_update_v40.sql`
  (safe to run again, MySQL 5.5), registered in the update check, the installer and the tests; the two bundled
  databases were upgraded with it and written again.
- The accounting's own upgrade notes are in `docs/accounting/ACCOUNTING_UPGRADE_v35.txt` ... `v40.txt`.

## 1. The accounting features

| Accounting version | Feature | What was done | Where | Evidence | Status |
|---|---|---|---|---|---|
| 35 | Disassemble / Process Items: outputs costed within 2% of the inputs and labor (a setting) | As in the accounting: refused on the page and in the posting engine, the difference and its % on the form, the setting under Inventory and POS | `app/Processing.php`, `app/Posting.php`, `modules/inventory/process.php`, `app/Settings.php`, `modules/admin/settings.php` | harness "Version 60: Disassemble / Process Items ..." (14 checks); e2e v60; browser | Done |
| 35, 36 | The later transactions costed again in date order after a back-dated, edited, voided or deleted processing order (setting: processing only, every inventory transaction, never; a limit) | As in the accounting. Checked on this program's own documents: in the test company every document of the other items dated from the processing order on (water bills, cooperative and sales documents among them) was posted again with the same entries; the only ones that changed were the two of an item costed out of date order, put right as Rebuild data does | `app/Recost.php`, `app/Posting.php`, `app/Maintenance.php` | harness "Version 60: a back-dated, edited ..." (18 checks: the three choices, the limit, the audit trail, the ledger) | Done |
| 35 | FB POS, FB POS Menu & Groups, the item's picture | As in the accounting; the screen uses this program's register (its customer list with the members by their member type, the member and walk-in price levels of the line of business, the line of business on the price checks) and its own confirmation window | `modules/pos/fb.php`, `_fb_screen.php`, `fb_menu.php`, `modules/pos/register.php`, `app/FbPos.php`, `modules/inventory/items.php`, `modules/files/item_image.php` | harness (groups, script); e2e (menu group, pages); browser: menu, pictures, a sale of 285.00 with change, phone bar | Done |
| 36 | A supplier on an expense line (Customer / Supplier), on its general ledger line | As in the accounting, with this program's lists: the customers as on Customer:Job before (a member by its member type); a member's own supplier record is **not** added to the list (no new member link) | `modules/txn/_grids.php`, `_common.php`, `app/Posting.php` | harness "Version 60: a supplier on an expense line ..." (11 checks); e2e (a check over HTTP) | Done |
| 37, 38 | "Add new" in the drop-downs: customers, suppliers, accounts, items; the window; Inventory Center's New item | As in the accounting; fitted to this program: the customer boxes that list members by their member type, the name boxes that hold members (m codes) get the lines; the account window offers the account types of this program that behave as the box's kinds (Bank ...). It adds a customer, supplier, account or item, **never a member** | `app/QuickAdd.php`, `views/layout.php`, `assets/js/app.js`, `modules/customers/center.php`, `modules/vendors/center.php`, `modules/coa/accounts.php`, `app/AccountTypes.php`, `modules/inventory/items.php`, `modules/inventory/center.php` and the transaction pages | harness "Version 60: "Add new" ..." (12 checks); e2e (the customer window saved and handed back, the account window narrowed, the item window); browser: a supplier added from Pay to the order of | Done |
| 38 | POS Receipts with dates and times, a Cashier filter and totals; gross sales with an item group's price variance; General Ledger Cashier filter; Cash Count Sheet and its report | As in the accounting; the General Ledger keeps this program's Balance column and note | `modules/pos/receipts.php`, `app/Pos.php`, `app/reports/finance.php`, `app/reports/pos.php`, `modules/pos/shifts.php`, `app/CashCount.php` | harness "Version 60: the Cash Count Sheet ..." (10 checks); e2e; browser: a register closed with the sheet, the report, its quarter page | Done |
| 39 | Print and Save as PDF; the Cash Count Sheet on a quarter page | As in the accounting, beside this program's Excel and CSV exports | `app/Report.php`, `app/ReportPdf.php`, `app/SimplePdf.php`, `app/ReportDesigner.php` | e2e (a PDF of the Trial Balance, the audit trail); browser: the Cash Count Sheet saved as PDF | Done |
| 40 | Attachments on journal entries, checks, cash vouchers, bills, purchase orders, sales orders and invoices | As in the accounting (the cooperative's own documents, such as the official receipts and the savings and loan vouchers, are not among them); a change to a saved journal entry that waits for approval attaches its files at once | `app/Attachments.php`, `modules/files/attachment.php`, `modules/coa/journal.php`, `modules/banking/cash_voucher.php`, `modules/txn/*`, `modules/vendors/*`, `app/Backup.php`, `app/helpers.php` | harness "Version 60: files attached ..." (14 checks: pieces, byte for byte, program files, backup in hex under 1 MB, deleted with the transaction); e2e (two files saved over HTTP, downloaded byte for byte, a program file refused, one removed) | Done |
| 35-40 | The user's manual | The accounting's chapters merged into this program's (Getting Started, Home and Reports, Chart of Accounts, Fixed Assets, Customers, Vendors, Inventory, Point of Sale, Banking, System Administration), with a note on the members; the contents and the search index; the picture of a bill's item lines that the manual named but never had (in the accounting too) taken | `views/manual/*`, `assets/manual/img/*` | the manual opened in the browser; crawl | Done |

## 2. Left as they are (the request)

| What | Why |
|---|---|
| The members on the customer, supplier and name lists of the documents (by their member type), the member's printed name, the member's customer, supplier and member name records, the party picker | "Do not include in the update the integration of the members name to invoices, sales receipts, cash voucher, check vouchers, journal entries, and other integration": nothing of it changes; the new features use it as it is and add no member anywhere |
| Water billing (its bills, collections, accounts and the members on them) | "Don't touch the integration of the members to waterbilling": no water file changed; the water documents that move stock are costed again with the others, their entries unchanged |
| The README's list of features | It describes this program; the accounting's own notes are in `docs/accounting/` |

## 3. Decisions

| # | Decision | Reason |
|---|---|---|
| D1 | The expense line's supplier list leaves out a member's own supplier record (every member has one). | The update must not bring members onto the documents; the customers of the line stay those of Customer:Job before, members included, as they were. |
| D2 | "Add new" adds customers, suppliers, accounts and items only. | Members are entered on the Member Center; the customer and supplier forms in the window keep this program's rules for a person already on file. |
| D3 | The FB POS uses this program's register (its customer list and price levels). | It is the POS Register with another screen; the cooperative's prices for members and walk-in customers apply as at the register. |
| D4 | Files attached on a journal entry change that waits for approval are attached at once. | A file changes no amount; the accounting attaches files to a saved document without an edit too. |
