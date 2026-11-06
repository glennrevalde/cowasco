# Accounting reconciliation report

- **Integrated database:** `coop_water_cut`
- **Former water database (read only):** `mnssmc_src`
- **Cut-over date:** 2026-10-14
- **Made:** 2026-10-14 08:20 by `tools/dev/delivery_evidence.php reconciliation` (read-only queries)

## 1. Records: former database vs integrated database

| Record | Former | Integrated | Equal |
|---|---:|---:|---|
| Water-service accounts | 6,888 | 6,888 | yes |
| Addresses of accounts | 6,888 | 6,888 | yes |
| Contact numbers | 1,956 | 1,956 | yes |
| Rate codes | 4 | 4 | yes |
| Tariff tiers (water_meter_members) | 52 | 52 | yes |
| Pipe sizes | 7 | 7 | yes |
| Bill codes | 3 | 3 | yes |
| Billing periods | 1 | 1 | yes |
| Meter readings / water bills | 0 | 0 | yes |
| Payments (receipts) | 0 | 0 | yes |
| Chart of accounts (every former account mapped or skipped with a reason) | 361 | 361 | yes |
| ...of which mapped to an account of the one chart | 317 | 317 | yes |
| Operators (mapped to users or skipped with a reason) | 7 | 7 | yes |
| Suppliers (as vendors) | 4 | 4 | yes |
| Former membership register (kept read-only) | 361 | 361 | yes |

Former accounts not mapped: 32 - root / class / group of the legacy tree (Type 2); not an account (no legacy journal line uses them; the accounts under them are mapped).
Former accounts not mapped: 10 - root / class / group of the legacy tree (Type 1); not an account (no legacy journal line uses them; the accounts under them are mapped).
Former accounts not mapped: 2 - root / class / group of the legacy tree (Type 0); not an account (no legacy journal line uses them; the accounts under them are mapped).

## 2. Parties

- Water-service accounts with a party: 6,888 of 6,888
- Parties made for water accounts by the migration (one each, never merged by name): 6,888
- Pairs queued on Parties > Identity Review (same TIN / mobile / name and birth date, to be decided by a person): 1,920
- Water-service accounts whose party is a cooperative member: 0 (a water customer is not made a member by the migration)

## 3. Control accounts

The former water program's own journal (if any) is not posted again: the open bills at cut-over become one invoice each
(event key WBILL-/OTB-/INST-/RBILL-), and one opening-balance journal (LEGACY-OB) brings every other legacy balance in, with
the clearing account netting to zero. The tables below are read from the integrated ledger.

| Control | Ledger | Sub-ledger | Difference |
|---|---:|---:|---:|
| Accounts receivable (all A/R accounts) vs customer balances | 253,913.11 | 253,913.11 | 0.00 |
| Inventory asset accounts vs value on hand | 744,834.48 | 744,834.48 | 0.00 |
| Accounts payable vs supplier balances | 1,953,894.05 | 1,953,894.05 | 0.00 |
| Cash and bank accounts | 1,725,978.90 | (bank reconciliation) | |
| Trial balance: debits - credits | 0.00 | | |
| Migration clearing account 39999 | 0.00 | must be 0.00 | |

## 4. Water records vs the ledger, per water-service account

- Accounts with posted water documents: 0; open balance in the water records: 0.00; accounts whose water open balance differs from their invoices in the ledger: 0
- Water events posted: 0; failed postings waiting in the Water Posting Log: 0

## 5. Opening balance journal

No opening-balance journal: the former database had no ledger balances at the cut-over (no legacy journal lines up to 2026-10-14), so nothing was brought in and nothing needed reconciling.
