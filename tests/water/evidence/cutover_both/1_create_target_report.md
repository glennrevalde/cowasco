# Target database created from the cooperative database

- **Source (unchanged):** `coop_src_orig`
- **Target:** `coop_water_cut`
- **Started:** 2026-10-14 08:20:16
- **Server:** 10.11.14-MariaDB-0ubuntu0.24.04.1

## 1. Copy of the cooperative database

| Table | Rows in source | Rows in target | Checksum equal |
|---|---:|---:|---|
| accounts | 110 | 110 | yes |
| approval_codes | 0 | 0 | yes |
| asset_categories | 6 | 6 | yes |
| asset_depreciation | 8 | 8 | yes |
| asset_locations | 2 | 2 | yes |
| asset_maintenance | 1 | 1 | yes |
| asset_maintenance_labor | 2 | 2 | yes |
| asset_maintenance_parts | 2 | 2 | yes |
| asset_maintenance_photos | 0 | 0 | yes |
| asset_meter_readings | 2 | 2 | yes |
| asset_pm_log | 0 | 0 | yes |
| asset_pm_schedules | 3 | 3 | yes |
| audit_trail | 385 | 385 | yes |
| backup_log | 0 | 0 | yes |
| bank_reconciliations | 1 | 1 | yes |
| billing_groups | 1 | 1 | yes |
| budgets | 0 | 0 | yes |
| budget_lines | 0 | 0 | yes |
| business_units | 2 | 2 | yes |
| cda_calendar | 4 | 4 | yes |
| cda_compliance | 0 | 0 | yes |
| cda_report_mappings | 32 | 32 | yes |
| classes | 4 | 4 | yes |
| coop_branches | 2 | 2 | yes |
| coop_imports | 0 | 0 | yes |
| coop_lists | 33 | 33 | yes |
| coop_officers | 0 | 0 | yes |
| coop_receipts | 160 | 160 | yes |
| coop_receipt_lines | 330 | 330 | yes |
| customers | 9 | 9 | yes |
| customer_types | 4 | 4 | yes |
| default_accounts | 63 | 63 | yes |
| dist_batches | 0 | 0 | yes |
| dist_batch_lines | 0 | 0 | yes |
| dist_periods | 0 | 0 | yes |
| document_prints | 0 | 0 | yes |
| document_reissues | 0 | 0 | yes |
| document_templates | 0 | 0 | yes |
| entity_notes | 2 | 2 | yes |
| fixed_assets | 4 | 4 | yes |
| import_batches | 0 | 0 | yes |
| inventory_movements | 95 | 95 | yes |
| items | 27 | 27 | yes |
| item_categories | 6 | 6 | yes |
| item_components | 8 | 8 | yes |
| item_site_stock | 29 | 29 | yes |
| journal_lines | 850 | 850 | yes |
| loan_accounts | 7 | 7 | yes |
| loan_adjustments | 0 | 0 | yes |
| loan_applications | 8 | 8 | yes |
| loan_application_docs | 3 | 3 | yes |
| loan_approvals | 8 | 8 | yes |
| loan_approval_matrix | 2 | 2 | yes |
| loan_charges | 4 | 4 | yes |
| loan_charges_applied | 13 | 13 | yes |
| loan_charge_ranges | 2 | 2 | yes |
| loan_ci | 0 | 0 | yes |
| loan_ci_lines | 0 | 0 | yes |
| loan_collaterals | 1 | 1 | yes |
| loan_comakers | 1 | 1 | yes |
| loan_notices | 0 | 0 | yes |
| loan_payment_alloc | 58 | 58 | yes |
| loan_products | 3 | 3 | yes |
| loan_product_charges | 5 | 5 | yes |
| loan_product_rates | 1 | 1 | yes |
| loan_schedule | 108 | 108 | yes |
| loan_txns | 65 | 65 | yes |
| loan_waivers | 0 | 0 | yes |
| members | 12 | 12 | yes |
| member_assets | 0 | 0 | yes |
| member_attendance | 0 | 0 | yes |
| member_beneficiaries | 0 | 0 | yes |
| member_clusters | 0 | 0 | yes |
| member_contacts | 0 | 0 | yes |
| member_dependents | 0 | 0 | yes |
| member_documents | 0 | 0 | yes |
| member_finances | 24 | 24 | yes |
| member_notes | 0 | 0 | yes |
| member_relatives | 0 | 0 | yes |
| member_representatives | 0 | 0 | yes |
| member_segments | 0 | 0 | yes |
| member_status_history | 24 | 24 | yes |
| member_types | 6 | 6 | yes |
| memorized_reports | 0 | 0 | yes |
| memorized_txns | 0 | 0 | yes |
| modules | 18 | 18 | yes |
| other_names | 15 | 15 | yes |
| pages | 295 | 295 | yes |
| parties | 31 | 31 | yes |
| party_crosswalk | 44 | 44 | yes |
| party_match_reviews | 0 | 0 | yes |
| party_role_log | 31 | 31 | yes |
| passbook_prints | 0 | 0 | yes |
| passbook_types | 2 | 2 | yes |
| payment_applications | 9 | 9 | yes |
| payment_methods | 9 | 9 | yes |
| payment_withholdings | 3 | 3 | yes |
| pos_cash_drops | 1 | 1 | yes |
| pos_readings | 2 | 2 | yes |
| pos_shifts | 2 | 2 | yes |
| pos_tenders | 8 | 8 | yes |
| pos_terminals | 2 | 2 | yes |
| price_levels | 10 | 10 | yes |
| price_level_items | 1 | 1 | yes |
| process_formulas | 3 | 3 | yes |
| process_formula_lines | 13 | 13 | yes |
| receipt_documents | 1 | 1 | yes |
| reconciliation_discrepancies | 0 | 0 | yes |
| reconciliation_items | 8 | 8 | yes |
| report_layouts | 0 | 0 | yes |
| roles | 17 | 17 | yes |
| role_page_access | 1103 | 1103 | yes |
| role_sites | 2 | 2 | yes |
| role_special_permissions | 75 | 75 | yes |
| sales_reps | 2 | 2 | yes |
| sav_accounts | 26 | 26 | yes |
| sav_batches | 0 | 0 | yes |
| sav_batch_lines | 0 | 0 | yes |
| sav_holds | 0 | 0 | yes |
| sav_interest_lines | 12 | 12 | yes |
| sav_interest_runs | 1 | 1 | yes |
| sav_products | 4 | 4 | yes |
| sav_product_rates | 7 | 7 | yes |
| sav_txns | 221 | 221 | yes |
| sav_txn_codes | 27 | 27 | yes |
| sequences | 42 | 42 | yes |
| settings | 95 | 95 | yes |
| sites | 5 | 5 | yes |
| tax_codes | 4 | 4 | yes |
| td_accruals | 0 | 0 | yes |
| td_placements | 2 | 2 | yes |
| teller_shifts | 1 | 1 | yes |
| terms | 5 | 5 | yes |
| transactions | 251 | 251 | yes |
| transaction_lines | 709 | 709 | yes |
| uom_sets | 5 | 5 | yes |
| uom_units | 16 | 16 | yes |
| users | 17 | 17 | yes |
| user_page_overrides | 2 | 2 | yes |
| user_roles | 17 | 17 | yes |
| user_sites | 2 | 2 | yes |
| user_terminals | 2 | 2 | yes |
| vendors | 8 | 8 | yes |
| withholding_taxes | 3 | 3 | yes |

- Views: 0 (none)
- Stored routines: 0; triggers: 2

## 2. Upgrade scripts run on the target

- ran `database/migrations/2026_12_01_water_billing.sql`

Every table copied from the source still has at least its rows after the upgrade.

## 3. Next step

Bring in the water-billing database (dry run first):

```
DB_NAME=coop_water_cut php tools/migrate_water.php --source=<water database> --cutover=YYYY-MM-DD --dry-run
DB_NAME=coop_water_cut php tools/migrate_water.php --source=<water database> --cutover=YYYY-MM-DD
```

Finished 2026-10-14 08:20:18 in 1.9 s.
