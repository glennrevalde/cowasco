# Backup and restore test

- **Run:** 2026-10-14 08:33:50 (7.7 s)
- **Original:** coop_water_pkg_copy
- **Restored into:** coop_water_pkg_copy_restored
- **Environment:** PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1
- **Result:** 17 passed, 0 failed, 17 checks


## Backup of coop_water_pkg_copy

| Check | Expected | Actual | Result |
|---|---|---|---|
| the backup file is written (acceptance_coop_water_pkg_copy_20261014_083342.sql, 22,768,466 bytes) | yes | yes | PASS |
| every table is in the backup | 227 | 227 | PASS |

## Restore into the empty database coop_water_pkg_copy_restored

| Check | Expected | Actual | Result |
|---|---|---|---|
| the restore ran without a failed statement |  |  | PASS |
| statements run: 953 | yes | yes | PASS |
| the same tables (227) | accounts, addresses, addresstype, approval_codes, asset_categories, asset_depreciation, asset_locations, asset_maintenan | accounts, addresses, addresstype, approval_codes, asset_categories, asset_depreciation, asset_locations, asset_maintenance, asset_maintenance_labor, asset_maint | PASS |
| row counts equal in every table (79,721 rows) |  |  | PASS |
| table checksums equal |  |  | PASS |
| the water compatibility views are back |  |  | PASS |
| the audit-trail triggers are back | 2 | 2 | PASS |
| no upgrade script is reported pending on the restored database |  |  | PASS |
| the ledger balances (debits = credits) | 0.00 | 0.00 | PASS |
| the water postings came back with their documents | 21 | 21 | PASS |

## A backup imported by hand (tables only): the system asks for the water migration, which is safe to run again

| Check | Expected | Actual | Result |
|---|---|---|---|
| without its views the water migration is reported pending | database/migrations/2026_12_01_water_billing.sql | database/migrations/2026_12_01_water_billing.sql | PASS |
| running it again succeeds |  |  | PASS |
| the views are made |  |  | PASS |
| ...and no data changes (accounts, bills, receipts, documents, parties, page rights) |  |  | PASS |
| the ledger still balances | 0.00 | 0.00 | PASS |
