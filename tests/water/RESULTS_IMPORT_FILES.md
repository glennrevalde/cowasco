# Data Import Management with the former program's files (HTTP)

- **Run:** 2026-10-19 01:35:09 (1426.8 s)
- **Server:** http://127.0.0.1:8133 (PHP built-in server, memory_limit 128M, upload limits as in the delivered `.htaccess`); every bill, charge, collection and opening balance posted under the class chosen for the batch (Water Utility): 7,146 postings, 57,188 receipts, all with the class
- **Database:** coop_imp_test (new installation with the water starter set-up)
- **Ledger policy:** opening_balance
- **Files:** Account_Master_DB_july_3_2026_2.csv, BillingMaster_july_3_2026.csv
- **Environment:** PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1
- **Result:** 48 passed, 0 failed, 48 checks


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

## 2. Billing Master (ledger policy: opening_balance)

| Check | Expected | Actual | Result |
|---|---|---|---|
| the Billing Master is accepted (batch created) | yes | yes | PASS |
| staged in chunks without an error | yes | yes | PASS |
| detected as the Billing Master | billing_master | billing_master | PASS |
| every row staged | 69878 | 69878 | PASS |
| no configuration blocker (water bill accounts, tariffs, charge plans, collection accounts) | [] | [] | PASS |
| the batch page offers Import entire Billing Master with the cut-over date | yes | yes | PASS |
| Import water bills runs to the end | yes | yes | PASS |
| Import recurring charges runs to the end | yes | yes | PASS |
| Import collections runs to the end | yes | yes | PASS |
| Import opening balances runs to the end | yes | yes | PASS |
| every ready water bill imported | 69366 | 69366 | PASS |
| no row failed on import | 0 | 0 | PASS |
| water bills in the water records | 69366 | 69366 | PASS |
| receipts in the water records | 57187 | 57187 | PASS |
| imported history is not posted (the policy is opening balances) | 0 | 0 | PASS |

## 3. Opening balances in the ledger

| Check | Expected | Actual | Result |
|---|---|---|---|
| opening invoices = everything still open in the water records (bills with payment evidence in Review held out) | 3,219,318.03 | 3,219,318.03 | PASS |
| every opening invoice is dated on the cut-over date | 0 | 0 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |
| the offset is on the legacy water balances clearing account | -3,219,318.03 | -3,219,318.03 | PASS |
| water open balance = ledger balance (400 accounts with open bills) | 0 | 0 | PASS |
| running the opening balances again posts nothing | 3,219,318.03 | 3,219,318.03 | PASS |

## 4. After the cut-over: collection and the next reading

| Check | Expected | Actual | Result |
|---|---|---|---|
| an imported open bill is collected at the cashier | yes | yes | PASS |
| ...its opening invoice is settled | 0.00 | 0.00 | PASS |
| trial balance after the collection | 0.00 | 0.00 | PASS |
| the party statement of that account opens | yes | yes | PASS |
| a new reading is saved in a new period | yes | yes | PASS |
| ...its previous reading is the last imported present reading | 3370 | 3370 | PASS |
| ...18 cubic metres | 18 | 18 | PASS |
