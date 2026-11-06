# Legacy ledger rehearsal (tools/dev/water_legacy_fixture.php)

Run 2026-10-14 00:15: the real water database plus a fixture of legacy bills, receipts and their journal (cut-over 2026-08-31), migrated into a fresh integrated database twice.

```
PHASE 7 FIXTURE CHECKS  (mnssmc_fixture -> coop_water_fx, cutover 2026-08-31)
| Check                                                                   | Expected   | Actual     | Result |
|-------------------------------------------------------------------------|------------|------------|--------|
| migration exit code                                                     |          0 |          0 | PASS   |
| legacy accounts 11784 / 11118 / 40210 mapped                            | yes        | yes        | PASS   |
| open invoices posted (count)                                            |          3 |          3 | PASS   |
| SUM(open invoices) = legacy A/R balance (11784)                         |     700.00 |     700.00 | PASS   |
| SUM(open invoices) = expected 700.00                                    |     700.00 |     700.00 | PASS   |
| every open invoice credits the clearing account                         |          0 |          0 | PASS   |
| every open invoice debits A/R 11784                                     |          0 |          0 | PASS   |
| opening balance journal exists                                          | yes        | yes        | PASS   |
| opening journal: debits = credits                                       |   1,320.00 |   1,320.00 | PASS   |
| opening journal: total (debits) = 1,320.00                              |   1,320.00 |   1,320.00 | PASS   |
| opening journal: no A/R line (legacy A/R fully explained by open bills) |          0 |          0 | PASS   |
| opening journal: Dr cash 11118 = 620.00                                 |     620.00 |     620.00 | PASS   |
| opening journal: Cr income 40210 = 1,320.00                             |  -1,320.00 |  -1,320.00 | PASS   |
| opening journal: Dr clearing = 700.00                                   |     700.00 |     700.00 | PASS   |
| opening journal dated at the cutover                                    | 2026-08-31 | 2026-08-31 | PASS   |
| GL A/R 11784 = legacy A/R                                               |     700.00 |     700.00 | PASS   |
| GL cash 11118 = legacy 620.00                                           |     620.00 |     620.00 | PASS   |
| GL income 40210 = legacy -1,320.00                                      |  -1,320.00 |  -1,320.00 | PASS   |
| clearing account nets to 0.00                                           |       0.00 |       0.00 | PASS   |
| voided legacy voucher (Status V) ignored: GL A/R unaffected by 999.00   |     700.00 |     700.00 | PASS   |
| account 114842: water open balance = expected                           |     350.00 |     350.00 | PASS   |
| account 114842: WaterAccounts::ledgerBalance = water open balance       |     350.00 |     350.00 | PASS   |
| account 114843: water open balance = expected                           |     350.00 |     350.00 | PASS   |
| account 114843: WaterAccounts::ledgerBalance = water open balance       |     350.00 |     350.00 | PASS   |
| account 114844: water open balance = expected                           |       0.00 |       0.00 | PASS   |
| account 114844: WaterAccounts::ledgerBalance = water open balance       |       0.00 |       0.00 | PASS   |
| customers made only for accounts with open bills                        |          2 |          2 | PASS   |
| A/R subledger (customer balances) = GL A/R                              |     700.00 |     700.00 | PASS   |
| history rows: payment_transactions copied                               |          2 |          2 | PASS   |
| history rows: legacy_journalentries copied                              |         14 |         14 | PASS   |
| history rows: cashier_id remapped to the migrated user                  |         11 |         11 | PASS   |
| verify phase logged as done                                             | done       | done       | PASS   |
| second run exit code                                                    |          0 |          0 | PASS   |
| second run: history phase wrote 0 rows                                  |          0 |          0 | PASS   |
| second run: still 3 open invoices and 1 opening journal                 |          4 |          4 | PASS   |
| second run: GL A/R unchanged                                            |     700.00 |     700.00 | PASS   |

Result: PASS  (19.1s)
Dropped `mnssmc_fixture` (use --keep-source to keep it); `coop_water_fx` is kept for inspection.
```
