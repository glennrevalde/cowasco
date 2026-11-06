# Importing the former water program's files (Account Master, Billing Master)

Water Billing > Setup > **Data Import Management** takes the two files the former water program exports:

| File | What it holds | Becomes |
|---|---|---|
| Account Master (`Account_Master_DB_*.csv`, 51 columns) | one row per water-service account: account number, zone, sequence, name, address, rate code, meter readings, flags, mobile number | water-service accounts, each with a party of its own; addresses, mobile numbers, the Senior Citizen discount |
| Billing Master (`BillingMaster_*.csv` or `.xlsx`, 38 columns) | one row per bill: readings, amounts, penalty, compromise, the other charges (share capital, Damayan, Funos, reconnection fee, meter installation) and the receipt that paid it | water bills, recurring charges, official receipts (history), and the amounts still owed as opening balances in the ledger |

It is the path for a cooperative that has the former program's export files rather than its database. With the
database itself, use `tools/migrate_water.php` instead (`docs/WATER_MIGRATION.md`).

## Before the first upload

Start from a new installation that has the water starter set-up: `database/accounting_system_clean.sql`, or
`php tools/install.php --water-starter`. It already holds what the importer needs:

* the importer's mapping decisions at their documented defaults (Mapping Decisions panel on the page);
* the rate codes and tariff the former program used, and the mapping profile **Former water program files**, which
  the upload form preselects: source rate code A = residential associate, B = residential regular, C = commercial
  associate, D = commercial regular (all 1/2 inch, as the former program had them), SeniorMaster = Senior Citizen
  discount (the Discount Flag is left for the cooperative to assign), and each charge column to its plan;
* the bill codes and recurring plans of the charges besides water: SHARE CAPITAL (credits 2136 Share Capital Collected
  with Water Bills), DAMAYAN (2137 Damayan Contributions Payable), FUNOS (2138 Funos Contributions Payable), METER
  INSTALLATION (4540) and RECONNECTION FEE (4510). Share capital, Damayan and Funos are held as liabilities until the
  cooperative credits them where they belong (a member's share capital account, the Damayan fund);
* the account 39999 Legacy Water Balances Clearing, which offsets the opening balances.

Also set once: Water Billing > Water Settings > Institution Configuration (report signatories); the page names
anything else still missing.

PHP must accept the file: the Billing Master is about 12 MB. XAMPP allows 40 MB; the delivered `.htaccess` (Apache
module) and `.user.ini` (PHP-FPM) raise PHP's own 2 MB / 8 MB defaults to 60 / 64 MB. The page says so when a file
is refused for its size. PHP's usual memory limit (128 MB) is enough: the importer works through the
70,000 staged rows in pages.

## Steps

1. **Account Master.** Upload it (the profile is preselected). The rows are validated in chunks; then press
   **Import all ready members**. Each account gets a party of its own (never merged by name); when the import ends,
   possible duplicates (same name and birth date, mobile number ...) are queued on Parties > Identity Review.
   Nobody is made a cooperative member.
2. **Billing Master.** Upload it the same way. Check the cut-over date (the date the opening balances are posted on;
   today by default) and press **Import entire Billing Master**: water bills, then recurring charges, then
   collections, then the opening balances, in chunks, under one progress screen. Every step can be run again: nothing
   is written twice.
3. Review what stayed in **Review** (filters and **Download issues CSV** on the page), decide, and **Revalidate
   batch**; rows that become Ready are imported by the same buttons.

## The general-ledger policy

Mapping Decisions > *General ledger policy for imported history*:

* **Opening balances at the cut-over** (default). The history (bills, charges, receipts) goes to the water records
  only. Then each imported bill or charge still open is posted to the ledger as an *opening invoice* of its account:
  Dr the bill code's A/R account, Cr 39999 Legacy Water Balances Clearing, dated on the cut-over date and due on the
  bill's own due date. The water receivables then equal the ledger, the cashier collects those bills like any other,
  the statements and the aging show them, and twelve years of the former program's revenue are not put into the
  cooperative's books a second time. The accountant closes the clearing account against the former program's equity
  once its last balance sheet is brought in.
* **Full historical posting.** Every bill, charge and receipt is posted on its own date (bills as invoices, receipts
  as payments, penalties and compromises as finance charges). For a company whose ledger starts with the water
  history; dates on or before the closing date are refused.

What counts as paid is the mapping decision *What proves a Billing Master row was settled*: by default Payment Flag
TRUE with a Receipt Number and a Date Paid. In the supplied file 3,176 bills (1,715,537.19, mostly 2020 to 2022 and
2026) have an Amt Paid and a Cash Change filled in but Payment Flag FALSE, no receipt number and no Date Paid. The
former program counted them unpaid, so they are imported unpaid and are part of the opening balances (the Amt Paid
is kept with the row and listed in the issues CSV as AMT_PAID_WITHOUT_EVIDENCE). Check a few of them against the
cash records before the cut-over; if they were in fact paid, change the decision before importing the Billing Master.

A bill whose row carries payment evidence that is still in Review (for example Payment Flag TRUE without a receipt
number or Date Paid, or a receipt Total that does not reconcile because it paid other months too) is **held out** of
the opening balances: most of these were paid in the former program. The page shows how many; once their review is
resolved the opening balances step can be run again. The box *Also post as unpaid* posts them anyway; use it only for
bills confirmed unpaid.

## Result with the supplied files (Account Master and Billing Master of 3 July 2026)

From `tests/water/import_files_http.php` on a new installation, through Apache with the PHP module
(`tests/water/RESULTS_IMPORT_FILES.md`), 26 minutes in all on the test machine:

| | |
|---|---|
| Account Master | 6,888 rows: 6,888 water-service accounts (5,643 active), 6,888 parties, 1,920 possible duplicate pairs for Identity Review, 1,956 mobile numbers, 37 senior citizens with the discount; 2 accounts without a rate code (billing them needs one) |
| Billing Master | 69,878 rows: 69,366 water bills (2012-04-02 to 2026-07-02), 45,317 rows of other charges (44,701 share capital, 4,592 Damayan, 358 reconnection fees, 346 Funos, 2 meter installations), 57,187 receipts (2025-01 to 2026-07) |
| Opening balances | 5,613 water bills (3,113,708.03) and 1,532 charges (105,610.00) posted on the cut-over date; trial balance 0.00; water receivables = ledger |
| Held out | 6,541 bills (5,760,492.60) whose payment evidence is in Review |
| Left in Review | bills: 510 (278 usage not equal to present minus previous, 99 negative readings, 90 account numbers not in the Account Master, 40 duplicate account and date); 2 rows with errors (a reading date in the year 1900, a negative bill amount); collections: 6,771 (6,140 totals that do not reconcile, 1,831 Payment Flag TRUE without a receipt or Date Paid, 158 repeated receipt numbers) |

After the import: an imported open bill is collected at the cashier and its opening invoice settled; the first reading
of a new period takes its previous reading from the last imported bill.
