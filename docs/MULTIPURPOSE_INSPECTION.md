# Multipurpose cooperative: codebase inspection before the changes

This is what was in the system (version 26) before the multipurpose changes of version 27, what is reused, and
what conflicted. It was written before the code was changed and kept as the record of the starting point.

## 1. The masters that held people and organizations

| Master | Table | Key and numbering | Used by | Notes |
|---|---|---|---|---|
| Members | `members` | `id`, `member_no` (sequence `member`) | savings, share capital, time deposits, loans, teller, distribution, CDA | Full KYC profile; status workflow; branch |
| Member's ledger name | `other_names` (`name_type = 'member'`, `member_id`) | `id` | every cooperative GL line (`journal_lines.other_name_id`) | Created by `Members::save()`; name, TIN and member no. copied from the member |
| Customers (and jobs) | `customers` | `id`; name unique per parent | invoices, sales receipts, payments, credit memos, statements, POS, A/R reports | Terms, credit limit, price level, tax code, withholding |
| Vendors (suppliers) | `vendors` | `id`; name unique | purchase orders, item receipts, bills, bill payments, checks, debit memos, advances, A/P reports | Terms, expense account, withholding |
| Employees and other names | `other_names` (`employee`, `other`) | `id`; name unique per type | journal entries, cash vouchers, checks to others | |
| Walk-in names | `transactions.payee_name` | none | POS, checks, cash vouchers | Free text |

**Duplicate masters.** A member who also buys from the store had to be entered again as a customer, and again as a
vendor to sell produce to the cooperative. Nothing tied the three records together: the same person had three
unrelated IDs, three copies of the name, TIN and address, and the patronage refund (which looked only at ledger
lines carrying the member's other-name) did not see that person's store purchases at all, because those lines carry
the customer ID.

**Conflicting IDs.** `customers.id`, `vendors.id`, `other_names.id` and `members.id` are separate sequences, so the
same number means different people in different tables. Reports that grouped by `customer_id` or `vendor_id`
cannot be joined to members by ID; only by name, which is unsafe.

**Missing links.** No link from a customer or vendor to a member; no history of a person's roles; no place to
record that two records are, or are not, the same person.

## 2. Transactions and the general ledger

- One ledger already: every document (`transactions` + `transaction_lines`) is posted by `Posting::post()` into
  `journal_lines`, and the cooperative vouchers (`coop_or`, `coop_cv`, `loan_release`, `coop_jv`) go through the same
  `Txn::save()`. Journal lines carry `customer_id`, `vendor_id`, `other_name_id`, `class_id`, `item_id`, `site_id`.
- **Reused as is:** `Txn::save`, `Posting`, `Balances` (open balances, customer and vendor balances from the ledger),
  `Stock` (moving-average inventory), the A/R and A/P reports, the report engine, the audit trail, `Seq` numbering,
  approvals (`Approval::check`) and the closing date.
- **Dimensions:** branch was already a class (`coop_branches.class_id`), so a separate dimension was needed for the
  lines of business. There was no business unit.
- **Documents printed the live master name.** `entity_name()` and the print bodies read `customers.name`,
  `vendors.name`, `other_names.name` at print time, so editing a master silently changed every old document
  reprinted afterwards. Cooperative vouchers printed the member's current name the same way.
- **Delete.** Trading documents could be deleted (with a reason, a copy kept in the audit trail). Cooperative
  vouchers could only be voided. Void removes the document's ledger lines (the document stays, marked VOID).

## 3. Reports and their assumptions

- Financial statements (Trial Balance, Balance Sheet, Income Statement, Cash Flows, General Ledger, Journal) filter
  by class on `journal_lines.class_id` (`Classes::sqlFilter`). A business-unit filter fits the same way on a ledger
  column.
- Customer and vendor balances are ledger balances by name (`Balances::customerBalance`, `vendorBalance`); open
  invoices and bills are document balances. The cooperative SL vs GL reconciliation covered savings, share capital,
  time deposits, loans and accrued interest, not A/R, A/P or the ISC / PR payables.
- The patronage refund basis was income lines carrying the member's other-name only.
- No report showed one person's whole relationship with the cooperative.

## 4. What the changes reuse and what they add

| Need | Reused | Added |
|---|---|---|
| One party, many roles | the role tables keep their IDs, numbers, terms, limits, statuses and history | `parties`, `party_id` on `customers`, `vendors`, `members`, `other_names`; `party_crosswalk` (old IDs); `party_role_log`; `party_match_reviews` |
| Names on documents | print bodies, `entity_name()` | snapshot columns on `transactions` (`party_id`, `cp_role`, `cp_name`, `cp_address`, `cp_tin`, `cp_member_no`, `cp_issued_at`, `cp_reissue_no`) filled by `Txn::save()` |
| Lines of business | modules, page access | `business_units`; `business_unit_id` on `transactions` and `journal_lines`; module switches |
| Member pricing and patronage | price levels, `Lookup::priceFor` | member / nonmember price levels by business unit, item and unit patronage flags, new PR bases |
| Consolidated reports | the report engine, class filter | business-unit filter, inter-unit accounts (`accounts.is_interunit`), Statement of Operations by Business Unit |
| Party 360 | `Balances`, savings and loan ledgers, distribution lines | Consolidated Party Statement |
| Subledger checks | `CoopRecon` | A/R, A/P and ISC / PR payable rows |

## 5. Safety rules followed by the migration

- Nothing is merged by name. The backfill gives every existing customer, vendor and other name its own party; a
  member's ledger name joins the member's party (they were already one person by construction).
- Every old ID is recorded in `party_crosswalk` with the party it went to.
- Possible duplicates (same TIN, same e-mail, same mobile, same name and birth date, same name) are only proposed on
  the Identity Review page; linking them needs the Approve right and a reason, and is logged.
- Posted journals, document numbers and history are not changed. Existing documents get a name snapshot taken from
  their current master at the upgrade (the best record available), marked as taken at the upgrade.
- Written for MySQL 5.5 (no JSON columns, one `CURRENT_TIMESTAMP` per table, index prefixes within 767 bytes,
  columns added through `information_schema` checks so the file can run twice).
