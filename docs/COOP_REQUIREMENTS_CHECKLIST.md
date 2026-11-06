# G-SMaRT Cooperative Management System: Requirements Checklist

This checklist maps each requirement from the three references (the PDF feature specification, the annotated
workbook `ADDITIONAL_MODULE_FOR_COOP_SYSTEMS_v1.xlsx` and the development brief) to the code that implements it.
The **Status** column is the state at delivery:

- **Done**: built, reachable from the menu, and exercised by the automated tests or by hand on the demo data.
- **Partial**: the core of it is built; the note says exactly what is missing.
- **Not done**: not built.

Where to look: service classes are in `app/` (`Coop.php`, `Members.php`, `Savings.php`, `TimeDeposits.php`,
`Passbook.php`, `SavBatch.php`, `Teller.php`, `Loans.php`, `Distribution.php`, `CoopLedger.php`, `CoopDocs.php`,
`CoopImport.php`, `CoopRecon.php`, `CoopMetrics.php`, `CoopAlerts.php`, `Dec.php`). Pages are in `modules/coop/`
and are opened as `index.php?p=<page key>`. Report definitions are in `app/reports/coop.php`,
`coop_savings.php` and `coop_loans.php`. The schema change is `database/migrations/2026_11_01_cooperative.sql`.
Tests are in `tests/coop/` and their latest results in `tests/coop/RESULTS.md`.

## 0. Platform decisions

| # | Decision | Reason |
|---|----------|--------|
| D1 | Plain PHP 8.1+, SQL that runs on MySQL 5.5.3+ and MariaDB, no framework. The module uses the existing page registry, access control, audit trail, report engine, layout and number series. | Keeps one application; the cooperative is a set of new modules, not a second program. |
| D2 | **One general ledger.** Every cooperative money event is a voucher in `transactions` / `journal_lines`, posted through `CoopLedger::post()`, which calls the existing `Txn::save()`. Four transaction types were added: `coop_or` (official receipt), `coop_cv` (member disbursement), `coop_jv` (cooperative journal voucher) and `loan_release`. Trial Balance, General Ledger, the financial statements, class filters, the closing date, void with reason and backups apply to them unchanged. | Brief: "do not create a second independent accounting database or ledger". |
| D3 | Every member is also an **Employees & Other Names** row (`other_names.member_id`); member lines in the GL carry that name, so the existing Subsidiary Ledger report lists members under each control account. | Reuses the subsidiary ledger machinery. |
| D4 | The operational ledgers (`sav_txns`, `loan_txns`) carry the `txn_id` of the voucher that posted them. `CoopRecon::slVsGl()` proves subsidiary totals = GL control balances (report `rpt.coop_sl_gl`). | SL vs GL reconciliation. |
| D5 | Posted entries are never edited or deleted. A cooperative voucher is corrected by **void with a reason** (where the voucher allows it; `CoopLedger::voidBlockers()` lists why not) or by a **reversing / adjusting entry** (`CoopLedger::reverse()`). `CoopLedger::onVoid()` undoes the subsidiary ledger in the same database transaction. | No silent edits or deletes. |
| D6 | Money uses `Dec` (exact decimal strings; bcmath when installed, an exact pure-PHP engine otherwise). Columns are DECIMAL(15,2); rates DECIMAL(9,4). Rounding is half away from zero, to the centavo, at the points the documents show. | No floating point for balances. |
| D7 | Duplicate posting is prevented by CSRF, a one-time form token (`Coop::onceField()` / `coop_once()`), number series taken under `SELECT ... FOR UPDATE` (`Seq::next`), unique keys (receipt numbers, interest runs, TD accruals, distribution periods and batches), and `FOR UPDATE` on the account row in every balance change. | Concurrency safety. |
| D8 | Branches are rows of `coop_branches`, each tied to a `classes` row, so every cooperative voucher carries the branch as its class and the existing statements **by Class** become branch statements. A user can be limited to one branch (`users.coop_branch_id`); the limit is applied on the server (`Coop::requireBranch()`, `Coop::branchFilter()` in SQL). | Branch isolation. |
| D9 | Rates, charges, penalties, allocation percentages and document wording are data with effective dates (`sav_product_rates`, `loan_product_rates`, `loan_charges`, `document_templates`, the settings on Cooperative Profile & Policies). A released loan or placed deposit keeps its own copy of its terms. | Configurable, effective-dated rules. |
| D10 | Legal figures (RA 9520 Art. 86 allocations, CDA report fields) are **defaults the cooperative confirms**, labelled "confirm against your bylaws" on the screen, and editable; none is hard-coded in a computation. | PH compliance configurable. |

## 1. Setup and configuration

| Requirement | Where | Status |
|---|---|---|
| Cooperative profile: registered name, CDA registration no., type, category, fiscal year end, signatories (GM, Chair, Treasurer, Credit Committee, Bookkeeper) | `coop.profile` (`modules/coop/profile.php`), settings `coop_*`; address, TIN and logo come from the company settings | Done |
| Branches / head office, branch cash accounts, consolidated and per-branch reporting | `coop.branches`, `coop_branches` (+ class); user's branch and cash drawer on System Administration > User Accounts | Done |
| Policies: minimum share capital, membership fee, back-dating window, large receipts and withdrawals needing approval, teller over/short tolerance, allocation order, penalty on collection, alert windows, year-end allocation defaults | `coop.profile` (policy groups); enforced in `Members::changeStatus`, `Coop::checkDate`, `Teller::receipt/disburse/close`, `Loans::allocate`, `Loans::dues`, `CoopAlerts` | Done |
| Approval limits per role | special permissions `coop_max_withdrawal`, `coop_max_release`, `coop_waive`, `coop_backdate`, `coop_override_allocation`, `coop_all_branches` (`app/Access.php`); loan approval levels in `loan.approval_matrix` | Done |
| Cooperative accounts in the chart and the default account mapping | migration adds the accounts whose codes are free; `Accts::KEYS` has a Cooperative group shown on Chart of Accounts > Default Accounts | Done |
| Document numbering (member, savings, TD certificate, share certificate, certificate of balances, loan, PN, OR, CV, JV, release, batch, passbook) | `coop.numbering` over `sequences` | Done |
| Member types (individual, joint, institutional), clusters, segments, other pick lists | `coop.lists` (`member_types`, `member_clusters`, `member_segments`, `coop_lists`) | Done |
| Passbook types with printer measurements and line tracking | `coop.passbooks` (`passbook_types`) | Done |
| Savings transaction codes (code, GL or user opposite account, side, check clearing days, error-correct link) | `sav.txn_codes` (`sav_txn_codes`) | Done |
| Roles: General Manager, Bookkeeper, Loan Officer, Credit Committee, Teller, Membership Officer, Collector, Branch Manager | migration (roles + page rights + special permissions); `tools/seed.php` for new installs | Done |
| Document wording with versions and effective dates | `coop.templates` (`CoopDocs`, `document_templates`) | Done |

## 2. Membership

| Requirement | Where | Status |
|---|---|---|
| Application, approval, activation; suspension, resignation, termination, death, each with a reason | `Members::changeStatus()`, `member_status_history`; `coop.member` | Done |
| Full profile (personal, contacts, dependents, relatives, employment and income, assets, membership) | `coop.member` tabs; `members`, `member_contacts`, `member_dependents`, `member_relatives`, `member_finances`, `member_assets` | Done |
| Joint and institutional members with authorized representatives | `members.member_kind`, `member_representatives` | Done |
| KYC documents, IDs, photo, specimen signature, with expiry dates | `member_documents`; files under `storage/coop/`, served by `file.member` (`modules/coop/member_file.php`) after a rights check | Done |
| Beneficiaries | `member_beneficiaries` | Done |
| Resigned, terminated and deceased members: settlement | loan payments still received; deposits applied to loans (payment by savings) and share capital and deposits paid out in full on `teller.disburse`, always with supervisor approval; a deceased member's claim is paid to the person named in Received by (`Teller::disburse`, `Savings::validate` settlement option) | Done |
| Membership history and timeline | `member_status_history` + audit trail on the member page | Done |
| Consolidated statement (share capital, deposits, TD, loans, ISC, PR) | `coop.member_statement` (screen and print) | Done |
| Search by name, member no., passbook no., mobile, savings account no., loan no. | `Coop::searchMembers()` (Member Center and the member picker) | Done |
| Notes, alerts to tellers, holds, restricted accounts | `member_notes`, `members.alert_note`, `members.hold_flag`, `sav_accounts.is_restricted` | Done |
| Member ID card | `coop.member_statement&a=id_card` (print, reprints counted) | Done |
| Share capital certificate | `coop.member_statement&a=certificate` (numbered, reprints need a reason) | Done |
| Certificate of balances (share capital, deposits, loans as of a date, with purpose) | `coop.member_statement`, "Certificate of balances" button; `Members::balancesAsOf()`; numbered per issue and kept in the print history | Done |
| Attendance at meetings / pre-membership seminar | `member_attendance` on the member page | Done |

## 3. Share capital

| Requirement | Where | Status |
|---|---|---|
| Subscribed vs paid-up, share classes | `members.subscribed_capital`; share-capital products are savings products of type `share_capital` (common / preferred as separate products) | Done |
| Collection by OR, payroll / CBU batch, transfer, loan deduction (capital build-up charge) | `teller.or`; `sav.batch`; `sav.transfer`; loan charge of type `savings` | Done |
| Minimum / maximum, minimum to activate a member | product rules; `coop_min_share_capital` and per member type | Done |
| Share capital ledger with running balance | `sav_txns`; Savings Account Center ledger; `rpt.sav_statement` | Done |
| Withdrawal / transfer out with approval | product flag `withdrawal_approval`; transfers out of share capital always need approval (`sav.transfer`) | Done |
| Year-end basis (average daily balance or ending balance) | `Savings::balances()` (opening, ADB, ending) used by `Distribution::compute()` | Done |
| Declared ISC credited to savings, share capital or a payable | distribution batch credit mode (`dist_batches.credit_mode`) | Done |

## 4. Savings

| Requirement | Where | Status |
|---|---|---|
| Unlimited products: ordinary, special, time deposit, share capital | `sav.products` (`sav_products`) | Done |
| Product rules: eligible member types, opening deposit, minimum / maintaining balance, maximum, minimum deposit / withdrawal, lock-in, dormancy period and fee, allowed withdrawals, approval flag | `sav_products` columns, enforced in `Savings::validate()` | Done |
| Rate tiers by balance with effective dates; basis (ADB, lowest, ending); posting period; withholding on interest | `sav_product_rates`; `Savings::rateFor()`, `Savings::postInterest()` | Done |
| Deposit, withdrawal, transfer, hold, restrict, dormant / reactivate, close, reopen | `Teller::receipt` / `Teller::disburse` (cash), `sav.transact`, `sav.transfer`, `Savings::placeHold/releaseHold/restrict/close/reopen` | Done |
| Corrections | void with reason (`CoopLedger::onVoid`), error-correct transaction codes, or a reversing entry | Done |
| Interest posting run, once per product and period | `sav.interest` (`sav_interest_runs` unique on product, period, status) | Done |
| Back-dated transactions need permission and are audited | `Coop::checkDate()` with `coop_backdate_days` and the `coop_backdate` permission | Done |
| Check deposits with clearing days; returned checks; checks received for loan payments and fees | `sav.clearing`; `Savings::clearCheck()` / `returnCheck()` (each check in the clearing account of its code); `Teller::checksToClear()` / `clearReceiptCheck()`; available balance excludes uncleared; a clearing is voided before the receipt it cleared | Done |
| Account statement and passbook printing (first free line, page by page confirmation, reprint, new passbook, cover, balance forwarded at the foot of a full page) | `rpt.sav_statement`; `sav.passbook` (`Passbook::plan/render/renderCover/commit/replace/unmark`, `passbook_prints`) | Done |
| Deposit and withdrawal documents | the deposit prints as an official receipt, the withdrawal as a disbursement voucher with the member's "Received by" line | Done (no separate pre-printed slip form) |
| Savings Account Center: status, holds, notes, approval of new accounts | `sav.accounts` | Done |
| Dormancy run (flags dormant accounts, charges the fee once per period) | `Savings::updateDormancy()` from `sav.interest` | Done (run by a user; not scheduled) |

## 5. Time deposits

| Requirement | Where | Status |
|---|---|---|
| Products with term, minimum / maximum placement, rate tiers by term and amount | `sav_products` (type `time_deposit`), `sav_product_rates` | Done |
| Simple or compounded interest; maturity date and value | `TimeDeposits::compute()` | Done |
| Interest paid at maturity | `TimeDeposits::settle()` | Done |
| Interest paid at intervals (monthly / quarterly) | `td_placements.payout_mode` exists but only `maturity` is offered and computed | Not done |
| Maturity instruction: pay out, renew principal, renew principal + interest, credit to savings | `td_placements.maturity_action`; settled on `sav.td` | Done (settled by a user on the maturity list; not automatic) |
| Pre-termination at the reduced rate with a fee and approval | `TimeDeposits::settle()` (pre-termination branch) | Done |
| Monthly accrual (interest expense / accrued interest payable), once per period | `TimeDeposits::accrue()` (`td_accruals` unique per placement and period) | Done |
| Certificate with number, reprint control | `sav.td&a=certificate` via `coop_print_gate()` | Done |
| Maturity list and alerts | `rpt.td_maturity`; dashboard alert | Done |

## 6-10. Loans

| Requirement | Where | Status |
|---|---|---|
| Unlimited products: amount and term limits, mode of payment, number of loans, eligibility (tenure, co-makers, net disposable income, share capital and savings minimum or % of loan, member segment) | `loan.products` (`loan_products`) | Done |
| Interest: diminishing (equal amortization or equal principal) or straight add-on; daily to annual modes of payment and lump sum; 365 / 360 days; rates by term or amount with effective dates | `loan_products`, `loan_product_rates`, `Loans::schedule()`, `Loans::rateFor()` | Done |
| Charges: financial (income), non-financial (payable, e.g. insurance), savings (CBU); fixed rate, fixed amount, by term or amount range; effective from a date; deducted, collected separately or capitalized | `loan.charges` (`loan_charges`, `loan_charge_ranges`, `loan_product_charges`); `Loans::productCharges()` by release date; `Loans::CHARGE_METHODS` | Done ("collected separately" charges are collected as fee lines on an official receipt) |
| Penalty: grace days, rate, base (arrears, principal in arrears, balance) | `Loans::penaltyThrough()` (incremental), `Loans::chargePenalty()` | Done |
| Collateral and co-makers with exposure check | `loan_collaterals`, `loan_comakers`; `rpt.loan_comaker`, `rpt.loan_collateral` | Done |
| Application with purpose, terms, applicant data, documents checklist | `loan.application` (`loan_applications`, `loan_application_docs`) | Done |
| Credit investigation (residence, income, assets, references, recommendation) | `loan.ci` (`loan_ci`, `loan_ci_lines`), printable | Done |
| Automatic eligibility check; override with reason by a user with Approve | `Loans::eligibility()`, `Loans::validateApplication()` | Done |
| Multi-level approval by amount; approve, disapprove, return; approved amount may differ | `loan.approval_matrix`, `Loans::act()`, `loan_approvals` | Done |
| Release: charges, net proceeds, cash / cheque / credit to savings, PN number, schedule; one release only | `loan.release`, `Loans::releasePlan()`, `Loans::release()` (status guard + `FOR UPDATE`) | Done |
| Documents: promissory note, disclosure statement with effective interest rate, schedule, release statement, co-maker statement, statement of account, payment reminder, demand letter; reprints logged | `loan.docs` (`modules/coop/loan_docs.php`), `Loans::eir()`, `document_prints` | Done |
| Collection: dues (principal, interest, daily interest to date for a payoff, penalties), allocation penalty, interest, principal (order configurable), manual override with permission, partial / advance / full payment, excess to savings | `loan.collection`, `Loans::dues()`, `Loans::allocate()`, `Loans::applyPayment()`, `Teller::receipt()` | Done |
| Loan Accounts Center: summary, schedule, ledger, penalty computation, collateral, history | `loan.accounts` | Done |
| Interest accrual and penalty batch, once per date; products set to "income when collected" are not accrued | `loan.penalties`, `Loans::accrueInterest()`, `Loans::postPenalties()` | Done (run by a user; not scheduled) |
| Adjustments with approval | `loan.adjustments`, `Loans::adjust()` | Done |
| Interest / penalty waiver: requested by one user, approved by another | `loan.waivers`, `Loans::requestWaiver()` / `approveWaiver()` | Done |
| Renewal and restructuring through a new application that pays off the old loan (the old loan is left out of the limits) | `loan.restructure` → application with `previous_loan_id`; payoff on release | Done |
| Write-off and recovery | `loan.restructure`, `Loans::writeOff()`, `Loans::recover()` | Done |
| Aging by maturity, at PAR, by amortization; PAR monitoring; arrears and due lists | `rpt.loan_aging_maturity`, `rpt.loan_aging_par`, `rpt.loan_aging_amort`, `rpt.loan_portfolio`, `rpt.loan_due`, `rpt.loan_no_payments` (`Loans::aging()`, `Loans::BUCKETS`) | Done |
| Loan loss provisioning computed from the aging; reclassification of past-due loans | write-off can use an allowance account; no automatic provision computation; past-due loans stay in the product's receivable account (the aging and PAR reports show them) | Partial |

## 11. Teller and cashiering

| Requirement | Where | Status |
|---|---|---|
| Shift with drawer (cash account), beginning cash, receipts, payments, expected cash, count by denomination, over / short posted with approval above the tolerance | `teller.shift` (`teller_shifts`), `Teller::open()` / `close()` | Done |
| Cash count sheet (printable) | `teller.shift&a=print&id=` | Done |
| Official receipt for any mix of savings, share capital, loan payments and fees; OR numbers per branch; typed pre-printed numbers checked for uniqueness | `teller.or` (`coop_or`), `coop_receipts` unique (kind, number) | Done |
| Member disbursement voucher (withdrawals, payouts) | `teller.disburse` (`coop_cv`) | Done |
| Limits, supervisor approval, void with reason | `Approval::check()` + permission limits; void through `coop_voucher_actions()` | Done |
| Teller journal, daily collection and disbursement summary, over / short, OR register, cash position | `rpt.teller_journal`, `rpt.teller_daily`, `rpt.teller_over_short`, `rpt.teller_or_list`, `rpt.teller_cash_position` | Done |

## 12. Interest on share capital and patronage refund

| Requirement | Where | Status |
|---|---|---|
| Net surplus allocation with statutory funds (reserve, education and training, community development, optional) and the ISC / PR pools; approve, post, reverse | `dist.surplus` (`dist_periods`), `Distribution::netSurplus/allocate/savePeriod/approvePeriod/postPeriod/reversePeriod` | Done |
| ISC on ADB or ending balance of the share capital products marked "Earns interest on share capital"; rate or pool; filters (branch, cluster, segment, member types, products, amount range); exclude delinquent members; exclude or adjust a member | `dist.isc` → `dist_batch.php`, `Distribution::candidates/compute/recalc/exclude` | Done |
| Patronage refund on income earned from each member (chosen income accounts) in the period; rate or pool; same filters | `dist.pr` → `dist_batch.php` | Done |
| Preview, validation, rounding difference, board resolution, approval by another user, lock after posting, reversal by adjusting entry | `Distribution::validate/approve/post/reverse` | Done |
| Credit to savings, share capital, or payable; payable paid later on a disbursement voucher | `dist_batches.credit_mode` | Done |
| Registers, unclaimed list, allocation summary, CSV | `rpt.dist_register`, `rpt.dist_unclaimed`, `rpt.dist_summary`; batch CSV and print | Done |
| Individual member notice of ISC / PR | shown on the member statement; no separate notice letter | Partial |

## 13-15. General ledger, regulatory reports, reports

| Requirement | Where | Status |
|---|---|---|
| Statement of Financial Condition, Statement of Operations (cooperative captions) | `rpt.coop_sfc`, `rpt.coop_sop` | Done |
| Statement of Cash Flows | the existing `rpt.cash_flow` (not re-captioned for cooperatives) | Partial |
| Statement of Changes in Members' Equity (share capital, statutory funds, undivided surplus) | `rpt.coop_sce` | Done |
| Statutory fund balances | `rpt.coop_statutory_funds` | Done |
| Schedules: loans receivable, savings, share capital, TD, aging, ISC / PR | `rpt.loan_schedule_recv`, `rpt.sav_schedule`, `rpt.coop_share_capital`, `rpt.td_list`, aging reports, distribution reports | Done |
| SL vs GL reconciliation with member-level differences | `rpt.coop_sl_gl` (`CoopRecon`) | Done |
| CAPR data preparation and CSV export with an editable field mapping | `cda.capr`, `cda.mappings` (`CoopMetrics`, `cda_report_mappings`) | Done (fields, not the official form layout) |
| Officers, committees, trainings; compliance tracking with files; regulatory calendar | `cda.officers`, `cda.compliance`, `cda.calendar`; `rpt.cda_officers`, `rpt.cda_compliance` | Done |
| Executive dashboard | Home dashboard: cooperative tiles and "needs attention" list (`CoopAlerts::kpis()`, `CoopAlerts::all()`) | Done |
| Every report with filters, sorting, print, CSV / Excel | existing report engine | Done |

## 16-20. Alerts, security, audit, documents, migration

| Requirement | Where | Status |
|---|---|---|
| Alerts: amortizations due, past due, matured loans, TD maturities, accounts turning dormant, documents / collateral / insurance expiring, approvals waiting (per user's rights), regulatory deadlines, overdue compliance, teller shifts left open, SL vs GL difference | `CoopAlerts::all()`; header bell and dashboard | Done (in-app only) |
| Alerts by SMS / e-mail | not connected | Not done |
| Large-transaction control | receipts above `coop_large_txn_amount` and withdrawals above `coop_withdrawal_approval_above` need supervisor approval | Partial: approval, no separate alert list |
| Roles, page and action rights, special permissions, maker-checker, branch scope, password policy, lockout, session timeout | existing access control + the cooperative rights | Done |
| Two-factor authentication | not built | Not done |
| Immutable audit trail including prints, reprints, overrides, rate and product changes | existing `audit_trail` (triggers block UPDATE / DELETE); `document_prints` | Done |
| Document templates with versions; reprint reason and history | `coop.templates`, `rpt.coop_document_prints` | Done |
| Opening balances import (members, savings / share capital, time deposits, loans): check first, all or nothing, one voucher against an offset account, cannot be voided | `coop.import` (`CoopImport::check/run`, `coop_imports`) | Done |
| User documentation | built-in User's Manual, chapter 13 Cooperative Management (`views/manual/chapters/13-cooperative.html`), a section with screenshots for every page and report, opened by each page's Help button | Done (the Word edition of the manual is not updated) |

## Assumptions

1. **Members as Other Names.** A member's identity in the GL is an `other_names` row created with the member, so GL reports list members among the Other Names.
2. **Share capital** is a savings product of type `share_capital`, as in the reference system (its "Paid Up Share Capital - Common" is a fixed-savings product). Its control account is an equity account.
3. **Savings interest** uses the product's basis (average daily balance by default) and calendar (365 or 360). Withholding tax applies only when the product has a tax rate.
4. **"Equal Loan"** in the reference is equal amortization (annuity) and **"Fixed Principal"** is equal principal. The reference sample (20,000 at 18 % for 12 months gives 1,833.60 a month) matches the annuity formula and is a test case.
5. **Daily interest to date** for a payoff on a diminishing loan = principal balance x annual rate / calendar days x days since the last amortization date, capped at the unpaid scheduled interest.
6. **Penalty** = base x penalty rate / calendar days x days past the grace period, counted incrementally so a partial payment lowers the base from the day it is made.
7. **Statutory allocation defaults** follow RA 9520 Art. 86 (reserve at least 10 %, education and training up to 10 % with part due to the federation, community development at least 3 %, optional up to 7 %). They are settings and per-period entries, labelled to be checked against the bylaws.
8. **Patronage refund basis** is each member's share of the chosen income accounts (credits less debits carrying the member's name in the period), like the reference's income-code picker.
9. **Official receipt numbers** are one series per branch (`coop_or` + branch prefix); pre-printed numbers may be typed and must be unique.
10. **CDA forms change**, so the CAPR export writes the fields listed in `cda_report_mappings` (editable) rather than a fixed government layout.
11. **The loan ledger carries the whole scheduled interest at release** on the subsidiary ledger, as the reference system shows it, while the GL takes interest up only when it is accrued or collected. The certificate of balances therefore shows loan principal; amounts past due are shown only for today's date.

## Limitations

See `docs/COOP_IMPLEMENTATION_NOTES.md`, section 9, for the full list. In short: no SMS / e-mail / payment gateway /
biometric / payroll-system integration; no scheduled (cron) runs for interest, dormancy, accruals or penalties, which
are run from their pages; browser printing (no raw dot-matrix driver); no two-factor login; no TD interest payout
at intervals; no automatic loan loss provisioning or past-due reclassification; the cash flow statement is the general
one, not re-captioned; the Word edition of the user's manual does not have the cooperative chapter.
