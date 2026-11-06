# Mapping and ID crosswalk

How every key of the two former databases can be found in the integrated database. The field-by-field mapping of
each table (which column goes where, what is remapped, what is kept read-only) is in `docs/WATER_MIGRATION.md`,
"Table and field mapping"; this page is the index of the crosswalks and of the name conflicts that were resolved.

## 1. The cooperative database

The target is a copy of the cooperative database (`tools/create_target.php`): every table keeps its name, every row
its key. Nothing needs a crosswalk. The copy is checked table by table (row count and `CHECKSUM TABLE`) before any
upgrade runs; the report is kept in `storage/migration/create_target_<target>_<time>.md`
(rehearsal: `tests/water/evidence/cutover_both/1_create_target_report.md`, 144 tables equal).

## 2. Name conflicts between the two schemas

| Former water object | In the integrated database | Why |
|---|---|---|
| `members` (water consumers) | `water_accounts` (same columns and keys, plus `legacy_member_id`, `party_id`, `customer_id`) | the cooperative owns `members` (cooperative members) |
| `chartofaccntstbl` (the water chart) | a read-only **view** over `accounts` with the former column names; the former rows are kept in `legacy_chartofaccntstbl` | one chart of accounts |
| `operator` (water users) | a read-only **view** over `users`; the former rows are kept in `legacy_operator` | one user list |
| `suppliers` | cooperative `vendors` (+ a party each); former rows in `legacy_suppliers` | one supplier list |
| `journalentries_copy1`, `journalvoucher_copy1`, `journal_voucher_voids`, `subsidiaryledger`, `book_types`, `postingmethod` | `legacy_journalentries`, `legacy_journalvoucher`, `legacy_journal_voucher_voids`, `legacy_subsidiaryledger`, `legacy_book_types`, `legacy_postingmethod` (read-only) | one general ledger; history kept, never re-posted |
| `accounts_payable*`, `inventory_purchases`, `inventory_purchase_items`, `inventory_stock_movements`, `inventory_adjustments`, `inventory_reorder_levels` | `legacy_*` (read-only) | one A/P and one stock ledger |
| `profile`, `operator_profile`, `operator_profile_page_permissions`, `access_control_*` | `legacy_*` (read-only) + roles `Water: <profile>` with the same pages | one access model |
| `savingsaccounts`, `savaccnttransaction`, ... | `legacy_sav*` (read-only); optionally imported into cooperative savings | one savings module |
| `audit_logs` | `water_audit_logs` | the cooperative owns `audit_trail` |
| `branches` | `branches` + one ledger **class** each (`branches.class_id`) | water documents post under the branch's class |

Every other water table (readings, bills, payments, rates, discounts, periods, addresses, ...) keeps its name and keys.

## 3. Crosswalk tables

| Crosswalk | Old key | New key | Look-up |
|---|---|---|---|
| `water_coa_map` | former `AccntID` | `accounts.id` (NULL with `how` = skipped and the reason for the roots and groups of the former tree) | `SELECT m.legacy_accnt_id, m.legacy_name, a.code, a.name, m.how, m.note FROM water_coa_map m LEFT JOIN accounts a ON a.id = m.account_id` |
| `<run>-coa_crosswalk.csv` | former `AccntID` | proposed code; `map_to_code` column for the accountant's own mapping, passed back with `--coa-map` | written by every run in `storage/water_migration/` |
| `water_user_map` | former operator id | `users.id` (`note` created / matched / skipped) | `SELECT * FROM water_user_map` |
| `party_crosswalk` source `water_acct` | water account key (`members.pkey`) | `parties.id` | `SELECT * FROM party_crosswalk WHERE source = 'water_acct' AND source_id = :pkey` |
| `party_crosswalk` source `water_supp` | former `supplier_id` | `parties.id` (and the vendor through `vendors.party_id`) | |
| `party_crosswalk` source `water_coopmb` | former `membershipinfo.memberID` (only after `--import-membership`) | `parties.id` (and the member through `members.party_id`) | |
| `party_crosswalk` sources `member`, `customer`, `vendor`, `other_name` | cooperative record id | `parties.id` | made by the cooperative's own party upgrade |
| `water_accounts.legacy_member_id` | former `members.pkey` | the same key (kept equal) | |
| `water_postings` | a water event (bill, receipt, correction, issuance, opening balance) by its **event key** | `transactions.id` of the ledger document (`txn_id`), `reversal_of_id` for a correction | `SELECT * FROM water_postings WHERE event_key = 'WBILL-123'` |
| `bill_codes.item_id` | an inventory bill code | `items.id` of the one item master | |
| `branches.class_id` | a water branch | `classes.id` | |

Event keys: `WBILL-<reading_id>` (water bill; a corrected bill `-R<n>`), `OTB-<bill_id>`, `INST-<installment_id>`,
`RBILL-<bill_id>`, `COL-<receipt no.>` (collection; `#PEN` penalty, `#COMP` compromise), `REV-COL-<receipt no.>`
(reversal or refund), `ISS-<issuance_id>` (material issuance), `LEGACY-OB` (opening balances), `LEGACY-STOCK-<code_id>`
(opening stock), `LEGACY-SAVOB` (opening savings, with `--import-membership`). An event key is unique: posting the
same event again finds the row and does nothing, which is what makes retries, double clicks, parallel cashiers and
re-runs of the migration safe.

## 4. Identity: never merged by name

Every water account gets a party of its own, every supplier one, every imported legacy member one. Probable
duplicates (the same TIN; the same mobile number; the same name and birth date; the same name) are queued on
Parties > Identity Review for a person to link or mark as different. Linking moves every role and document to the
kept party and leaves the other as merged (its number still finds the kept party). In the former water database one
person with several connections usually has one `members` row per connection (names such as "LEAH D. 1" and
"LEAH 2"); after review they become one party holding several water-service accounts. The rehearsal on the real
database queued 1,920 pairs (`docs/RECONCILIATION_REPORT.md`, section 2).
