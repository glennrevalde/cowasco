# Upgrade scripts run twice

- **Run:** 2026-11-01 19:29:56 (1.5 s)
- **Database:** coop_twice43
- **Environment:** PHP 8.3.6, 10.11.14-MariaDB-0ubuntu0.24.04.1
- **Result:** 38 passed, 0 failed, 38 checks


## Before: coop_twice43

| Check | Expected | Actual | Result |
|---|---|---|---|
| every applied script is recognised as applied, so none is offered to run again |  |  | PASS |
| tables counted: 230, rows: 8,057 | yes | yes | PASS |

## The water-billing upgrade script run again: nothing changes

| Check | Expected | Actual | Result |
|---|---|---|---|
| 2026_12_01_water_billing.sql runs again without an error |  |  | PASS |
| no table gained or lost a row |  |  | PASS |
| the ledger is unchanged (lines/debits/credits) | 1208/11680212.54/11680212.54 | 1208/11680212.54/11680212.54 | PASS |
| the water views are there |  |  | PASS |
| ...and a third run changes nothing either |  |  | PASS |

## The earlier upgrade scripts run again (sample-data scripts excluded)

| Check | Expected | Actual | Result |
|---|---|---|---|
| 2026_09_19_classes_cash_voucher.sql: no error, no data row changed |  |  | PASS |
| 2026_09_21_asset_maintenance_debit_memo_logo.sql: no error, no data row changed |  |  | PASS |
| 2026_09_22_memorized_budgets_imports.sql: no error, no data row changed |  |  | PASS |
| 2026_09_23_item_search_per_transaction.sql: no error, no data row changed |  |  | PASS |
| 2026_09_24_subsidiary_ledger.sql: no error, no data row changed |  |  | PASS |
| 2026_09_25_bluetooth_receipt_printer.sql: no error, no data row changed |  |  | PASS |
| 2026_09_26_pos_default_site_class.sql: no error, no data row changed |  |  | PASS |
| 2026_09_27_find_report_design.sql: no error, no data row changed |  |  | PASS |
| 2026_09_28_multi_company.sql: no error, no data row changed |  |  | PASS |
| 2026_09_29_reconcile_saved_charges.sql: no error, no data row changed |  |  | PASS |
| 2026_10_01_price_levels_approval_codes.sql: no error, no data row changed |  |  | PASS |
| 2026_10_03_categories_reports_shift_corrections.sql: no error, no data row changed |  |  | PASS |
| 2026_10_08_custom_report_deposit_edit.sql: no error, no data row changed |  |  | PASS |
| 2026_10_15_withholding_bir_advances.sql: no error, no data row changed |  |  | PASS |
| 2026_10_22_inventory_movement_reports.sql: no error, no data row changed |  |  | PASS |
| 2026_11_01_cooperative.sql: no error, no data row changed |  |  | PASS |
| 2026_11_15_multipurpose_party.sql: no error, no data row changed |  |  | PASS |
| 2026_12_20_member_lists_class_je_changes.sql: no error, no data row changed |  |  | PASS |
| 2026_12_27_member_defaults_savings_center.sql: no error, no data row changed |  |  | PASS |
| 2027_01_03_loan_collection_error_correct.sql: no error, no data row changed |  |  | PASS |
| 2027_01_10_teller_menu_loan_release_vouchers.sql: no error, no data row changed |  |  | PASS |
| 2027_01_17_passbook_designer.sql: no error, no data row changed |  |  | PASS |
| 2027_01_24_account_types.sql: no error, no data row changed |  |  | PASS |
| 2027_01_31_adjustment_passbook.sql: no error, no data row changed |  |  | PASS |
| 2027_02_21_menus_imports_codes.sql: no error, no data row changed |  |  | PASS |
| 2027_03_07_water_ladder_billing.sql: no error, no data row changed |  |  | PASS |
| 2027_03_14_share_capital_billing.sql: no error, no data row changed |  |  | PASS |
| 2027_03_21_share_capital_terms_batch.sql: no error, no data row changed |  |  | PASS |

## After

| Check | Expected | Actual | Result |
|---|---|---|---|
| still nothing pending |  |  | PASS |
| the ledger is unchanged (lines/debits/credits) | 1208/11680212.54/11680212.54 | 1208/11680212.54/11680212.54 | PASS |
| page rights given again by the earlier scripts' rules (informational: the system never runs an applied script again, see the first check) | listed | none | PASS |
