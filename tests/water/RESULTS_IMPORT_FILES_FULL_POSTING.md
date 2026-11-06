# Data Import Management with the former program's files (HTTP)

- **Run:** 2026-10-14 21:50:06 (139.8 s)
- **Server:** http://127.0.0.1:8135
- **Database:** coop_imp_fullp (new installation with the water starter set-up)
- **Ledger policy:** full_posting
- **Files:** Account_Master_DB_july_3_2026_2.csv, BillingMaster_first3000.csv
- **Environment:** PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1
- **Result:** 40 passed, 0 failed, 40 checks


## 0. Set-up

| Check | Expected | Actual | Result |
|---|---|---|---|
| admin signs in | yes | yes | PASS |
| Data Import Management says the database is compatible | yes | yes | PASS |
| the profile of the former program's files is offered and preselected | yes | yes | PASS |

## 1. Account Master

| Check | Expected | Actual | Result |
|---|---|---|---|
| the Account Master is accepted (batch created) | yes | yes | PASS |
| staged in chunks without an error | yes | yes | PASS |
| detected as the Account Master | account_master | account_master | PASS |
| every row staged | 6888 | 6888 | PASS |
| Import all ready members runs to the end | yes | yes | PASS |
| every ready row imported (none failed) | 6888 | 6888 | PASS |
| water-service accounts made | 6888 | 6888 | PASS |
| each imported account belongs to a party | 0 | 0 | PASS |
| ...one party each (never merged by name) | 6888 | 6888 | PASS |
| nobody is made a cooperative member | 0 | 0 | PASS |
| possible duplicate parties are queued for Identity Review | yes | yes | PASS |
| rate code A (2627 accounts) -> residential associate, 1/2 inch, as the former program did | 2627 | 2627 | PASS |
| senior citizens (SeniorMaster) enrolled in the Senior Citizen discount | 37 | 37 | PASS |
| mobile numbers (cellno) on file as contact channels | 1956 | 1956 | PASS |
| account 11002 imported with its name split "SARMAGO, ISRAEL" | SARMAGO\|ISRAEL | SARMAGO\|ISRAEL | PASS |
| the account opens on Water Service Accounts | yes | yes | PASS |
| importing the Account Master again writes nothing | 6888 | 6888 | PASS |

## 2. Billing Master (ledger policy: full_posting)

| Check | Expected | Actual | Result |
|---|---|---|---|
| ledger policy set on Mapping Decisions | full_posting | full_posting | PASS |
| the Billing Master is accepted (batch created) | yes | yes | PASS |
| staged in chunks without an error | yes | yes | PASS |
| detected as the Billing Master | billing_master | billing_master | PASS |
| every row staged | 3000 | 3000 | PASS |
| no configuration blocker (water bill accounts, tariffs, charge plans, collection accounts) | [] | [] | PASS |
| the batch page offers Import entire Billing Master | yes | yes | PASS |
| Import water bills runs to the end | yes | yes | PASS |
| Import recurring charges runs to the end | yes | yes | PASS |
| Import collections runs to the end | yes | yes | PASS |
| every ready water bill imported | 2909 | 2909 | PASS |
| no row failed on import | 0 | 0 | PASS |
| water bills in the water records | 2909 | 2909 | PASS |
| receipts in the water records | 280 | 280 | PASS |

## 3. Full historical posting in the ledger

| Check | Expected | Actual | Result |
|---|---|---|---|
| every imported bill with an amount is an invoice (WBILL-) | 2907 | 2907 | PASS |
| every imported recurring charge is an invoice (RBILL-) | 1030 | 1030 | PASS |
| every imported receipt is a payment (COL-) | 280 | 280 | PASS |
| invoices are dated on their own billing dates | 0 | 0 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |
| water open balance = ledger balance (300 accounts) | 0 | 0 | PASS |
