# G-SMaRT Cooperative Management System: Implementation Notes

These notes cover installing and upgrading, setting the cooperative up, the daily and period routines, how the
module keeps one general ledger, the formulas it uses, the tests, and what is not done. The requirement-by-requirement
status is in `docs/COOP_REQUIREMENTS_CHECKLIST.md`.

## 1. What is in the module

Six new menu modules sit next to the existing accounting modules, inside the same program and database:

| Menu | Pages |
|---|---|
| **Cooperative** | Member Center, Member Information, Member Statement of Account (with ID card, share certificate and certificate of balances), Cooperative Vouchers, Opening Balances Import; set-up: Cooperative Profile & Policies, Branches, Member Types, Clusters & Other Lists, Passbook Types, Document Numbering, Document Templates; 10 reports |
| **Savings & Deposits** | Savings Account Center, Savings Deposit / Withdrawal, Transfer Between Accounts, Post Savings Interest (and dormancy), Passbook Printing, Time Deposits, Batch Deductions (Payroll / CBU), Check Clearing; set-up: Savings & Deposit Products, Savings Transaction Codes; 10 reports |
| **Loans** | Loan Application Center, Loan Application Form, Credit Investigation, Loan Release, Loan Collection, Loan Accounts Center, Loan Adjustments, Interest / Penalty Waiving, Restructure / Renew / Write Off, Loan Documents, Post Loan Penalties & Interest Accrual; set-up: Loan Products, Loan Charges, Loan Approval Matrix; 17 reports |
| **Teller** | Teller Shift & Cash Count, Official Receipt (Member Collection), Member Disbursement (Cash Voucher), Official Receipt List; 5 reports |
| **Year-end Distribution** | Net Surplus Allocation, Interest on Share Capital, Patronage Refund, Distribution Batches; 3 reports |
| **CDA Compliance** | Officers, Committees & Trainings, Compliance Tracking, Regulatory Calendar, CAPR Data Preparation & Export, Report Field Mappings; 2 reports |

The Home dashboard gains cooperative tiles and a "needs attention" list, and the header gains an alert count. The
built-in User's Manual has a new chapter 13, Cooperative Management, with a section and screenshots for every page and
report; each page's Help button opens it (the Word edition of the manual is not updated). The
cooperative vouchers appear in the existing General Ledger, Trial Balance, Journal, financial statements and Audit
Trail like any other transaction.

## 2. Requirements

Same as the accounting system: PHP 8.1 or newer with `pdo_mysql`, `mbstring` and `dom` (all on by default in XAMPP);
MySQL 5.5.3+ or MariaDB 5.5+. The `bcmath` extension is used when present; without it the module uses its own exact
decimal arithmetic (both engines pass the same tests). The web server must be able to write to `storage/` (member
photos, signatures, KYC documents and compliance files are kept under `storage/coop/`, or under the company's own
storage folder with several companies).

## 3. Installing

### New installation

Follow README sections 2 or 3. Both database files and `php tools/install.php` include the cooperative module:

- `database/accounting_system.sql` has the original sample company plus a sample cooperative: two branches, 12
  members with share capital and savings (26 accounts), two time deposits, seven loans (two of them behind on their
  payments, two paid off), last year's savings interest, a check deposit waiting to clear and a loan application
  waiting for the Credit Committee. There is no year-end distribution in it yet.
- `database/accounting_system_clean.sql` has the cooperative set-up lists only (member types, transaction codes,
  passbook types, CDA field mappings, regulatory calendar, roles and page rights), no branch, product or member.
- `php tools/install.php` runs the cooperative migration after the master data; `--demo` adds the sample cooperative.

Sample cooperative users (full sample database only), password `Demo@12345`:

| Username | Role | Branch |
|---|---|---|
| manager | General Manager | all |
| bookkeeper | Bookkeeper | all |
| branchmgr | Branch Manager | Branch 2 |
| loanofficer | Loan Officer | Head Office |
| creditcom | Credit Committee | all |
| membership | Membership Officer | Head Office |
| teller1 | Teller | Head Office |
| teller2 | Teller | Branch 2 |

### Upgrading a database already in use

1. Back up the database (System Administration > Backup & Restore, or a mysqldump).
2. Copy the program files over the installation, keeping the folder structure. `config/config.php` is not changed.
3. In phpMyAdmin or Navicat, select the database and import `database/migrations/2026_11_01_cooperative.sql`.
   It creates the cooperative tables, adds three empty columns (`users.coop_branch_id`, `users.coop_cash_account_id`,
   `other_names.member_id`), adds the cooperative accounts **only where their codes are free**, the default account
   mapping, roles, pages, page rights, number series, transaction codes and policy defaults. It deletes nothing and
   changes no existing amount. It is written for MySQL 5.5 and is safe to run again.
4. With several companies: Super Admin > Companies > Database updates > Update all companies (or
   `php tools/platform.php update-all`).
5. Press Ctrl+F5 once in the browser so it loads the new `assets/js/coop.js` and `assets/css/app.css`.

Until the migration is run, the program shows the page that names it after log-in.

**Check the default accounts after upgrading.** Where your chart already uses one of the codes the migration wanted
(1110, 1210-1230, 2300-2370, 3010-3340, 4400-4480, 7100-7120), the cooperative default points at your existing
account with that code. Review Chart of Accounts > Module Default Accounts, Cooperative group, before posting.

### Running locally

```bash
php -S 127.0.0.1:8080 -t /path/to/accounting-system
```

then open <http://127.0.0.1:8080/>. With XAMPP, put the folder in `htdocs` and open
<http://localhost/accounting-system/>.

### Deploying to a server

- Apache with the included `.htaccess` files, or nginx with the rules in README section 9. Both refuse `app/`,
  `config/`, `database/`, `modules/`, `storage/`, `tests/`, `tools/` and `views/`, and every `.sql` and `.md` file.
- Serve over HTTPS; the session cookie is then marked secure.
- Give the web server user write access to `storage/` only.
- Set `display_errors = Off` in production; errors go to the PHP error log.
- No cron job is needed by the cooperative module. Interest posting, dormancy, accruals and penalties are run by
  a user from their pages (see section 5). The existing `tools/send_scheduled_reports.php` cron line still applies to
  scheduled reports.
- Back up the database daily. Member files are under `storage/`; back that folder up with the database.

## 4. Setting up the cooperative (administrator)

Work through these pages in order on a new database. Every page has its own help text; each setting that the law or
the bylaws decide is labelled to be confirmed.

1. **Cooperative > Cooperative Profile & Policies.** Registered name, CDA registration number, type, category,
   fiscal year end, signatories; membership minimums and fee; teller rules (shift required, back-dating window,
   approval thresholds, over/short tolerance); account and loan number formats; payment allocation order; alert
   windows; the year-end allocation percentages (defaults follow RA 9520 Art. 86; confirm against the bylaws).
2. **Chart of Accounts > Module Default Accounts**, Cooperative group: check each mapping (see 3, "Check the default
   accounts").
3. **Cooperative > Branches.** One row per branch (or only the Head Office); each branch gets a class, so statements
   by class become branch statements, and its own official receipt prefix.
4. **System Administration > User Accounts.** For tellers and branch staff, set the branch (limits what they see and
   touch) and the cash drawer account. Give users their roles; adjust rights on User Role Configuration. The
   special permissions (max withdrawal and release without approval, waive, back-date, override allocation, see all
   branches) are on the role.
5. **Cooperative > Document Numbering.** Starting numbers and prefixes of every series.
6. **Cooperative > Member Types, Clusters & Other Lists.** Member types (the six standard ones are there), clusters,
   segments, and the pick lists (ID types, relationships, collateral types, and so on).
7. **Cooperative > Passbook Types.** Paper measurements and lines per page for each passbook stock.
8. **Savings & Deposits > Savings & Deposit Products.** At least one share capital product, the savings products and
   the time deposit products, each with its GL accounts, rules and rate tiers (effective-dated).
9. **Savings & Deposits > Savings Transaction Codes.** The system codes are there; add your own (for example check
   deposits with their clearing days).
10. **Loans > Loan Charges**, then **Loans > Loan Products** (limits, interest method, amortization type, rates,
    penalty, eligibility rules, charges, document checklist), then **Loans > Loan Approval Matrix** (levels by
    amount; with no row, one user with the Approve right approves).
11. **Cooperative > Document Templates.** The standard wording of the promissory note, disclosure, co-maker
    statement, certificates and notices is used until you save your own version with an effective date.
12. **CDA Compliance > Report Field Mappings, Regulatory Calendar, Officers.** Check the CAPR field list against the
    current CDA form; enter the deadlines and the officers.
13. **Cooperative > Opening Balances Import** (when moving from another system): import members, then savings and
    share capital balances, time deposits and loans, each as of the cut-off date. Each file is checked first; nothing
    is saved until every row passes, and then all rows are saved in one voucher against the offset account
    (normally Opening Balance Equity, cleared when the opening trial balance is entered). Sample CSV files are on the
    page.
14. **Cooperative > Reports > Subsidiary vs General Ledger Reconciliation.** It must show no difference before going
    live.

## 5. Routines

| When | What | Where |
|---|---|---|
| Every business day | Each teller opens a shift (beginning cash), posts receipts and disbursements, closes the shift with the cash count; a difference is posted to Cash Over and Short (approval above the tolerance); print the cash count sheet | Teller > Teller Shift & Cash Count |
| Every business day | Clear the checks the bank cleared: savings deposits whose clearing date has come, and checks received for loan payments and fees | Savings & Deposits > Check Clearing |
| Monthly (or the product's period) | Post savings interest per product; run dormancy (flags dormant accounts and charges the fee once per period) | Savings & Deposits > Post Savings Interest |
| Month end | Accrue time deposit interest; settle matured placements | Savings & Deposits > Time Deposits |
| Month end | Post loan penalties, accrue loan interest, refresh loan statuses | Loans > Post Loan Penalties & Interest Accrual |
| Month end | Check the SL vs GL reconciliation | Cooperative > Reports |
| After the fiscal year | Net surplus allocation (approve with the GA resolution, post), then the ISC and PR batches (compute, validate, approve by another user, post) | Year-end Distribution |
| As scheduled | CAPR export, compliance submissions, calendar items | CDA Compliance |

Each run is idempotent for its date or period: running it twice adds nothing.

## 6. How the module keeps one ledger

### Vouchers

Every money event posts one balanced voucher through `CoopLedger::post()` (which calls the existing `Txn::save()`):

| Type | Number series | Used for |
|---|---|---|
| `coop_or` | per-branch OR series | official receipts: deposits, share capital, loan payments, fees, recoveries |
| `coop_cv` | `MCV-` | member disbursements: withdrawals, TD payouts, distribution payouts |
| `loan_release` | `LR-` | loan releases |
| `coop_jv` | `CJV-` | transfers, interest posting, accruals, charges, adjustments, waivers, write-offs, allocation, ISC / PR posting, over/short, opening balances |

The member's subsidiary ledger row (`sav_txns` or `loan_txns`) is written in the same database transaction and
carries the voucher's `txn_id`. Member GL lines carry the member's Other Names id, so the existing Subsidiary Ledger
report works per member.

### Main entries

| Event | Debit | Credit |
|---|---|---|
| Deposit / share capital payment (OR) | Cash (teller drawer) or COCI for checks | Savings / Time Deposit / Share Capital control account of the product |
| Withdrawal (CV) | Product control account | Cash |
| Transfer between accounts | Source product account | Destination product account |
| Savings interest posting | Interest expense on savings | Product account (net), withholding tax payable (tax) |
| Dormancy fee | Product account | Dormancy fee income |
| TD placement | Cash or source savings | Time Deposits |
| TD accrual | Interest expense on time deposits | Accrued interest payable |
| TD settlement | Time Deposits, accrued interest payable, interest expense (unaccrued part) | Cash / savings / renewal placement; pre-termination fee income |
| Loan release | Loans Receivable (product account) | Cash / bank (cheque) / member savings (net proceeds); charge accounts (service fee income, insurance payable, CBU product account); previous loan paid off |
| Loan collection (OR) | Cash | Penalty income; interest receivable (accrued part) then interest income; Loans Receivable |
| Loan interest accrual | Interest receivable on loans | Interest income on loans |
| Penalty batch | (subsidiary ledger only; income is recognized when collected) | |
| Waiver of accrued interest | Interest income | Interest receivable |
| Write-off | Allowance (or provision expense) | Loans Receivable; accrued interest reversed |
| Recovery after write-off (OR) | Cash | Recovery of loans written off |
| Check cleared (savings deposit, or a receipt's loan payments and fees) | Bank | Checks and other cash items (the clearing account of the check's code) |
| Teller over / short | Cash or Cash Over and Short | Cash Over and Short or Cash |
| Net surplus allocation | Retained earnings | Reserve, education (kept), CETF due to federation, community development, optional funds; ISC payable; PR payable |
| ISC / PR batch | ISC or PR payable | Member savings / share capital accounts, or stays payable for payout |
| Opening balances import | Control accounts (loans) / offset | Control accounts (deposits, share capital) / offset |

The loan ledger takes up the whole scheduled interest at release (as the reference system shows it); the GL takes
interest up only when it is accrued or collected. A diminishing loan paid in full early drops the unearned interest
from the ledger.

### Corrections

- Posted vouchers are never edited. A cooperative voucher is voided with a reason when nothing depends on it
  (`CoopLedger::voidBlockers()` names the reason otherwise, for example a later payment on the same loan, a closed
  interest run, an imported opening balance); `CoopLedger::onVoid()` undoes the subsidiary ledger and rebuilds the
  loan schedule in the same database transaction.
- Allocations, ISC / PR batches and opening imports are corrected with a reversing entry (`CoopLedger::reverse()`),
  never voided.
- The audit trail table is protected by triggers that refuse UPDATE and DELETE.

### Concurrency and duplicates

- Every form that posts carries a one-time token; a second submission (double click, refresh, back button) is
  refused. `coop.js` also disables the button after the first click.
- Balance-changing operations lock the account row (`SELECT ... FOR UPDATE`) inside the transaction and re-check the
  balance after the lock, so two withdrawals at the same moment cannot both pass on one balance (a test runs two
  processes against one account).
- Numbers come from `Seq::next()` under a row lock; receipt numbers, interest runs, TD accruals and distribution
  periods and batches have unique keys.

### Access and branches

- Every page checks its view right on the server; actions check create / edit / delete / print / approve.
- Users limited to a branch see and touch only that branch's members, accounts, loans and vouchers; the filter is in
  the SQL and every service method re-checks it (`Coop::requireBranch()`).
- Maker-checker: loan approval by level, waivers and distribution batches approved by a different user, withdrawals
  and releases above the user's limit need a supervisor's username and password on the form.
- Members who have left (resigned, terminated, deceased) can still pay their loans; their deposits and share capital
  are paid out, or applied to their loans, only as a settlement with a supervisor's approval; a deceased member's
  claim is paid to the person named in Received by.

## 7. Formulas

Money is exact decimal throughout; amounts are rounded half away from zero to the centavo where a document shows
them. Rates are per cent per annum unless stated.

- **Savings interest** = basis x rate / 100 x days / calendar days (365 or 360 by product); basis = average daily
  balance, lowest or ending balance of the period by product; no interest when the lowest balance falls below the
  maintaining balance (ADB and lowest bases); withholding = interest x tax rate.
- **Average daily balance** = sum of each day's end-of-day balance / days in the period (`Savings::balances()`).
- **Time deposit, simple** = principal x rate / 100 x term days / calendar days. **Compounded**: the value is
  compounded every 30 or 90 days at rate x period days / calendar days, the remaining days simple.
- **Loan, equal amortization** (annuity): payment = P x i / (1 - (1 + i)^-n), i = annual rate x months between
  payments / 12 (or x days / calendar days for daily, weekly and semi-monthly modes, or by the actual days when the
  product says "diminishing by daily"). Interest each period = balance x i; the last payment absorbs rounding.
  Reference check: 20,000 at 18 % for 12 months = 1,833.60 a month.
- **Loan, equal principal**: principal / n each period plus balance x i.
- **Loan, straight add-on**: total interest = P x rate x term in years, split evenly; lump sum pays everything at
  maturity.
- **Interest to date** (payoff of a diminishing loan) = principal balance x rate x days since the last due date /
  (100 x calendar days), capped at the unpaid scheduled interest.
- **Penalty** = base x penalty rate x days / (100 x calendar days), counted from due date + grace days, incrementally
  from the last penalty date; base = unpaid amortization (principal + interest), unpaid principal only, or the whole
  principal balance, by product.
- **Allocation of a payment**: penalty, interest, principal (order set on the profile), oldest amortization first;
  an authorized user may type the split; anything above the payoff can go to savings.
- **Effective interest rate** (disclosure statement): the period rate r that makes the present value of the
  amortizations equal to the net proceeds, solved by bisection; annual EIR = r x periods per year.
- **Aging**: by maturity (whole balance after maturity), at PAR (the whole balance once any amortization is unpaid,
  aged from the oldest unpaid due date), by amortization (only the unpaid amortizations). Buckets: current, 1-30
  days, 31-90, 91-180, 181-365, over 365.
- **Net surplus allocation**: each fund = net surplus x its percentage; the CETF part of the education fund = the
  education fund x the federation percentage; ISC + PR = what is left, split by the amounts the GA approved.
- **ISC** = basis (ADB or ending share capital balance in the period) x rate, or the pool x member basis / total basis.
  **PR** = member's income in the chosen income accounts in the period x rate, or pool share. In pool mode the
  rounding difference goes to the member with the largest basis so the pool is distributed exactly.

## 8. Tests

```bash
php tests/coop/run.php              # builds a throw-away database coop_test, runs every test, writes tests/coop/RESULTS.md
php tests/coop/run.php --pure-php   # the same with the pure-PHP decimal engine (writes RESULTS_pure_php.md)
php tests/coop/run.php --filter=loan --keep
```

The runner refuses to run on the `accounting_system` database. Set `DB_NAME` for another throw-away name. The
latest results are in `tests/coop/RESULTS.md` (bcmath) and `tests/coop/RESULTS_pure_php.md`. The tests cover the
decimal engine, schedules (against the reference workbook figures), members, receipts, withdrawals and approval,
shift close, check clearing, transfers, savings interest, dormancy, time deposits, loan application, release,
collection, void, accrual and penalties, waiver, write-off, renewal, restructuring, allocation, ISC, PR, branch
isolation, duplicate submission, concurrency, immutability, closed periods, batch deductions, passbook, opening
import, the disclosure EIR, CAPR figures, reconciliation, alerts, the certificate of balances, the statement of
changes in equity, the settlement of members who have left, check clearing by code and for loan payments, charge
effective dates and the accrual setting of a product, and finish by proving every voucher balances and every
subsidiary ledger equals its control account.

## 9. Assumptions and limitations

Assumptions are listed in the checklist. Limitations at delivery:

- **No outside channels.** Alerts are in the program only (header count, dashboard, lists). SMS, e-mail alerts,
  payment gateways, biometrics and payroll-system interfaces are not connected. Batch deductions take a CSV from the
  payroll office.
- **No unattended runs.** Interest posting, dormancy, TD accrual, loan penalties, interest accrual and status
  refresh are run by a user from their pages (each is safe to run again for the same date).
- **Printing** is browser printing of measured pages (passbook lines by passbook type). Raw dot-matrix printing is
  not driven directly.
- **No two-factor log-in.** Password policy, lockout and session timeout are the existing ones.
- **Time deposits** pay interest at maturity only; payout at intervals is not offered. Matured placements are settled
  by a user.
- **Loan loss provisioning** is not computed automatically from the aging; the write-off can use the allowance.
- **Statement of Cash Flows** is the existing general report, not re-captioned in the cooperative format.
- **CAPR** is prepared as data (screen, print, CSV) from an editable field mapping, not on the official CDA form
  layout. Check the mapping against the current form each year.
- **Past-due loans are not reclassified.** A past-due loan stays in its product's receivable account; the aging and
  PAR reports show it. No entry moves it to a past-due receivable account.
- **Individual ISC / PR notices** are not printed as letters; the member statement shows each member's amounts.
- Legal percentages and rules shipped as defaults (RA 9520 allocation, CDA fields, calendar) must be confirmed by the
  cooperative; they are not legal advice.

## 10. Where the code is

| Path | What |
|---|---|
| `app/Coop.php` | settings, branches, number formats, dates, one-time tokens, member lookup |
| `app/Members.php` | member records, status workflow, uploads, statement, balances as of a date |
| `app/Savings.php`, `app/TimeDeposits.php`, `app/Passbook.php`, `app/SavBatch.php` | savings, time deposits, passbook printing, batch deductions |
| `app/Teller.php` | shifts, official receipts, disbursements |
| `app/Loans.php` | products, schedules, applications, approval, release, dues, penalties, collection, adjustments, waivers, write-off, accrual, aging, EIR |
| `app/Distribution.php` | net surplus allocation, ISC and PR batches |
| `app/CoopLedger.php` | posting, void hooks, reversing entries |
| `app/CoopDocs.php` | document wording and placeholders |
| `app/CoopImport.php` | opening balances import |
| `app/CoopRecon.php`, `app/CoopMetrics.php`, `app/CoopAlerts.php` | SL vs GL, regulatory figures, alerts and dashboard figures |
| `app/Dec.php` | exact decimal arithmetic |
| `app/reports/coop*.php` | report definitions |
| `modules/coop/*.php` | the pages |
| `assets/js/coop.js` | member picker, money inputs, live schedule and allocation previews, once-only forms |
| `database/migrations/2026_11_01_cooperative.sql` | the schema and set-up data |
| `tools/coop_fixtures.php`, `tools/coop_demo.php` | sample cooperative used by the tests and `--demo` |
| `tests/coop/` | the automated tests and their results |
