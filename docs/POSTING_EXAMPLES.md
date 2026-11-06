# Sample posting examples (from the end-to-end test database, run through Apache on the delivered package)

Every water event below was made through the water pages over HTTP by `tests/water/e2e_http.php` and posted by
`app/WaterPosting.php` through the cooperative `Txn::save` / `Posting` engine. The lines are read back from the
ledger of `coop_water_pkg` (2026-10-14 08:39). One row of `water_postings` per event key: a retry
or a second request with the same key finds the row and posts nothing.

| Event key | Source | Document | Party (printed name) | Line of business / class | Amount | Status |
|---|---|---|---|---|---:|---|
| `WBILL-1` | water_bill 1 | invoice 1 | CECELIA DELOS REYES | WU / Main Branch | 1,030.40 | posted |
| `WBILL-2` | water_bill 2 | invoice 2 | ISRAEL SARMAGO | WU / Main Branch | 246.40 | posted |
| `WBILL-3` | water_bill 3 | invoice 3 | A.C.E.S. | WU / Main Branch | 2,230.40 | posted |
| `WBILL-4` | water_bill 4 | invoice 4 (voided) | SOCORRO MARIETTA R. BRIONES | WU / Main Branch | 499.50 | replaced |
| `WBILL-5` | water_bill 5 | invoice 5 | DEODINDO DIEZ | WU / Main Branch | 1,314.00 | posted |
| `WBILL-6` | water_bill 6 | invoice 6 | LEAH D. 1 ALBARDONIDO | WU / Main Branch | 411.60 | posted |
| `COL-20261014-083638-E26E` | collection COL-20261014-083638-E26E | payment COL-20261014-083638-E26E (voided) | CECELIA DELOS REYES | WU / Main Branch | 515.20 | voided |
| `COL-20261014-083638-7BFB` | collection COL-20261014-083638-7BFB | payment COL-20261014-083638-7BFB | CECELIA DELOS REYES | WU / Main Branch | 515.20 | posted |
| `COL-20261014-083639-64D4#PEN` | collection_penalty COL-20261014-083639-64D4 | finance_charge COL-20261014-083639-64D4-PEN | ISRAEL SARMAGO | WU / Main Branch | 25.00 | posted |
| `COL-20261014-083639-64D4` | collection COL-20261014-083639-64D4 | payment COL-20261014-083639-64D4 | ISRAEL SARMAGO | WU / Main Branch | 271.40 | posted |
| `WBILL-4-R1` | water_bill 4 | invoice 4-R1 | SOCORRO MARIETTA R. BRIONES | WU / Main Branch | 632.70 | posted (replaces #4) |
| `REV-COL-20261014-083638-E26E` | collection_reversal COL-20261014-083638-E26E | payment COL-20261014-083638-E26E (voided) | CECELIA DELOS REYES | WU / Main Branch | 515.20 | posted (reverses #7) |
| `COL-20261014-083640-9C3B` | collection COL-20261014-083640-9C3B | payment COL-20261014-083640-9C3B | CECELIA DELOS REYES | WU / Main Branch | 515.20 | posted |
| `OTB-2` | one_time_bill 2 | invoice 8 | DEODINDO DIEZ | WU / Main Branch | 50.00 | posted |
| `INST-1` | installment_bill 1 | invoice 9 | DEODINDO DIEZ | WU / Main Branch | 600.00 | posted |
| `COL-20261014-083641-40DE` | collection COL-20261014-083641-40DE | payment COL-20261014-083641-40DE | DEODINDO DIEZ | WU / Main Branch | 200.00 | posted |
| `RBILL-1` | recurring_bill 1 | invoice 10 | LEAH D. 1 ALBARDONIDO | WU / Main Branch | 75.00 | posted |
| `ISS-1` | issuance 1 | inventory_adjustment ISS-20261014-EF00295E (voided) |  | WU / Main Branch | 1,500.00 | voided |
| `ISS-2` | issuance 2 | inventory_adjustment ISS-20261014-2542DABA |  | WU / Main Branch | 750.00 | posted |
| `OTB-3` | one_time_bill 3 | invoice 11 | A.C.E.S. | WU / Main Branch | 1,200.00 | posted |
| `COL-20261014-083645-5E86` | collection COL-20261014-083645-5E86 | payment COL-20261014-083645-5E86 | LEAH D. 1 ALBARDONIDO | WU / Main Branch | 411.60 | posted |

## Journal lines of each kind of event

### Metered water bill posted (Generate SOA / Post Billing): A/R against the party's customer record, water income

`WBILL-1`: invoice 1 dated 2026-10-14, CECELIA DELOS REYES (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 40210 Water Bill Revenue |  | 1,030.40 | Posted water bill #1 for account #114842 - Revenue (WATER BILL) |
| 11784 Accounts Receivable - Water | 1,030.40 |  | Posted water bill #1 for account #114842 |
| **Total** | **1,030.40** | **1,030.40** | |

### Collection (Collections (Cashier)): cash account of the cashier, applied to the open water invoices

`COL-20261014-083638-7BFB`: payment COL-20261014-083638-7BFB dated 2026-10-14, CECELIA DELOS REYES (payer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 11118 Cash in Vault | 515.20 |  | Collection receipt COL-20261014-083638-7BFB for account #114842 |
| 11784 Accounts Receivable - Water |  | 515.20 | Collection receipt COL-20261014-083638-7BFB for account #114842 |
| **Total** | **515.20** | **515.20** | |

### Penalty charged at the cashier: a finance charge to the penalty income account of the collection policy

`COL-20261014-083639-64D4#PEN`: finance_charge COL-20261014-083639-64D4-PEN dated 2026-10-14, ISRAEL SARMAGO (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 40140 Fines, Penalties, Surcharges |  | 25.00 | Penalty on receipt COL-20261014-083639-64D4 |
| 11784 Accounts Receivable - Water | 25.00 |  | Penalty collected with receipt COL-20261014-083639-64D4 |
| **Total** | **25.00** | **25.00** | |

### Bill edited before collection: the first invoice is voided (kept, status replaced) and the corrected bill posts as -R1

`WBILL-4-R1`: invoice 4-R1 dated 2026-10-14, SOCORRO MARIETTA R. BRIONES (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 40210 Water Bill Revenue |  | 632.70 | Corrected water bill #4 (Invoice No. 4-R1) for account #114850 - Meter misread (e2e test) - Revenue (WATER BILL) |
| 11784 Accounts Receivable - Water | 632.70 |  | Corrected water bill #4 (Invoice No. 4-R1) for account #114850 - Meter misread (e2e test) |
| **Total** | **632.70** | **632.70** | |

### Collection reversed (error correction): the payment is voided and the bills reopen; the reversal row links to the original

`REV-COL-20261014-083638-E26E`: payment COL-20261014-083638-E26E dated 2026-10-14, CECELIA DELOS REYES (payer), line of business WU. **The document is voided**: its ledger lines are removed (the bills it paid are open again); the document, its number and its original lines stay in the audit trail.

_No ledger lines left: the void removed them._

### One-time bill

`OTB-2`: invoice 8 dated 2026-10-14, DEODINDO DIEZ (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 40128 Service Fee |  | 50.00 | One-time bill #2 (MEMBERSHIP FEE) for account #114851 |
| 11784 Accounts Receivable - Water | 50.00 |  | One-time bill #2 (MEMBERSHIP FEE) for account #114851 |
| **Total** | **50.00** | **50.00** | |

### Installment contract

`INST-1`: invoice 9 dated 2026-10-14, DEODINDO DIEZ (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 40128 Service Fee |  | 600.00 | Installment contract #1 (MEMBERSHIP FEE) for account #114851 |
| 11784 Accounts Receivable - Water | 600.00 |  | Installment contract #1 (MEMBERSHIP FEE) for account #114851 |
| **Total** | **600.00** | **600.00** | |

### Recurring bill

`RBILL-1`: invoice 10 dated 2026-10-14, LEAH D. 1 ALBARDONIDO (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 40128 Service Fee |  | 75.00 | Recurring bill #1 (MEMBERSHIP FEE) for account #114852 |
| 11784 Accounts Receivable - Water | 75.00 |  | Recurring bill #1 (MEMBERSHIP FEE) for account #114852 |
| **Total** | **75.00** | **75.00** | |

### Material issuance to field work: expense debited, inventory asset credited at average cost (one inventory adjustment)

`ISS-2`: inventory_adjustment ISS-20261014-2542DABA dated 2026-10-14, line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 1200 Inventory |  | 750.00 | WATER METER - ISS-20261014-2542DABA to Field crew 1 [Water Meter] |
| 6030 Repairs and Maintenance | 750.00 |  | WATER METER - ISS-20261014-2542DABA to Field crew 1 [Water Meter] |
| **Total** | **750.00** | **750.00** | |

### A water meter sold through a one-time bill whose bill code is linked to an item: income, cost of goods sold and the stock leave together

`OTB-3`: invoice 11 dated 2026-10-14, A.C.E.S. (customer), line of business WU.

| Account | Debit | Credit | Memo / item |
|---|---:|---:|---|
| 4000 Sales |  | 1,200.00 | One-time bill #3 (WATER METER) for account #114849 [Water Meter] |
| 5000 Cost of Goods Sold | 750.00 |  | One-time bill #3 (WATER METER) for account #114849 [Water Meter] |
| 1200 Inventory |  | 750.00 | One-time bill #3 (WATER METER) for account #114849 [Water Meter] |
| 11784 Accounts Receivable - Water | 1,200.00 |  | One-time bill #3 (WATER METER) for account #114849 |
| **Total** | **1,950.00** | **1,950.00** | |

## Ledger totals at the end of the test

- Trial balance difference (debits - credits): 0.00
- Documents whose lines do not balance: 0
- Ledger documents claimed by two water events: 0
