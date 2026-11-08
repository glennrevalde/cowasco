# Cooperative module: automated test results

- Run: 2026-11-08 23:59:54 (Asia/Manila)
- Database: 10.11.14-MariaDB-0ubuntu0.24.04.1 (test database `coop_t57`, rebuilt from database/schema.sql + triggers + seed + the 2026_11_01, 2026_11_15, 2026_12_01 (water), 2026_12_20, 2026_12_27, 2027_01_03, 2027_01_10, 2027_01_17, 2027_01_24, 2027_01_31, 2027_02_21, 2027_03_07, 2027_03_14, 2027_03_21, 2027_03_28, 2027_04_04, 2027_04_11, 2027_04_18, 2027_04_25 and 2027_05_02 migrations)
- PHP 8.3.6, decimal engine: pure PHP
- Command: `php tests/coop/run.php --pure-php`
- Result: **186 passed, 0 failed** (2913 individual checks, 29.9s)

| # | Test | Result | Checks | ms |
|---|------|--------|--------|----|
| 1 | Decimal engine: exact sums, half-away-from-zero rounding, the reference ISC / PR figures | PASS | 17 | 32 |
| 2 | Amortization: equal amortization 20,000 at 18 % for 12 months gives 1,833.60 (reference workbook) | PASS | 12 | 0 |
| 3 | Amortization: fixed principal, straight add-on, lump sum and daily modes | PASS | 14 | 1 |
| 4 | Members: profile, duplicate check, status workflow, history and audit trail | PASS | 16 | 30 |
| 5 | Official receipt: share capital and savings in one receipt, one balanced voucher, shift totals | PASS | 19 | 46 |
| 6 | Savings withdrawal: available balance, minimum balance, supervisor approval for share capital | PASS | 18 | 212 |
| 7 | Teller shift close: cash count by denomination, shortage posted with supervisor approval | PASS | 8 | 110 |
| 8 | Check deposit: held until cleared, cleared to the bank, a returned check reverses it | PASS | 17 | 43 |
| 9 | Transfer between two members: one journal voucher, both subsidiary ledgers | PASS | 7 | 13 |
| 10 | Savings interest: average daily balance, rate tier, posting once, void restores the ledger | PASS | 20 | 60 |
| 11 | Dormancy: no member transaction for the product period, fee charged once per quarter, reactivated by a deposit | PASS | 6 | 32 |
| 12 | Time deposit: placement, rate by term, monthly accrual (once), maturity credited to savings | PASS | 22 | 38 |
| 13 | Time deposit: pre-termination at the reduced rate with the fee; renewal and its void | PASS | 15 | 55 |
| 14 | Loan application: eligibility checks, override with a reason, two-level approval by amount | PASS | 15 | 59 |
| 15 | Loan release: 100,000 less 3 % service fee and 5 % share capital = 92,000 net; one release only | PASS | 23 | 90 |
| 16 | Loan collection: penalty after the grace period, allocation penalty-interest-principal, the rest to the principal (version 35) | PASS | 21 | 79 |
| 17 | Loan payment void: schedule and balances rebuilt; payoff cancels the unearned interest | PASS | 17 | 33 |
| 18 | Loan interest accrual and penalty batch: once per date, cleared by the next collection | PASS | 14 | 141 |
| 19 | Waiver: requested by one user, approved by another; write-off and recovery | PASS | 13 | 25 |
| 20 | Loan renewal: the new loan pays off the old one, which is closed as renewed | PASS | 16 | 28 |
| 21 | Net surplus allocation: statutory funds, ISC and PR pools, posted once after the year end | PASS | 16 | 13 |
| 22 | Interest on share capital: average daily balance x rate, maker-checker, posted once, reversed by adjusting entry | PASS | 15 | 85 |
| 23 | Patronage refund: income earned from each member in the period, kept as payable, paid on a disbursement voucher | PASS | 6 | 26 |
| 24 | Branch isolation: a branch teller sees and touches only the members of the branch | PASS | 7 | 26 |
| 25 | Duplicate submission: one-time form token, unique numbers | PASS | 3 | 1 |
| 26 | Concurrency: two withdrawals at the same moment on one account, only the one the balance covers goes through | PASS | 4 | 1385 |
| 27 | Immutability: posted entries are voided with a reason, never deleted; the audit trail cannot be changed | PASS | 6 | 26 |
| 28 | Closed period: no member transaction on or before the closing date without the permission | PASS | 2 | 2 |
| 29 | Ledger integrity at the end: every voucher balances, subsidiary ledgers = control accounts | PASS | 12 | 2 |
| 30 | Batch deductions (payroll): savings and loan lines checked, nothing posted while a line has an error, one voucher | PASS | 18 | 79 |
| 31 | Passbook: printing starts on line 1, Start Line Skip leaves the fold blank, confirmed page by page, reprint and new passbook logged | PASS | 10 | 65 |
| 32 | Opening balances import: members, savings, time deposits and loans checked first, posted all or nothing, ledgers agree | PASS | 34 | 63 |
| 33 | Restructuring through an application: the loan paid off is left out of the limits; the new loan is flagged restructured | PASS | 15 | 100 |
| 34 | Disclosure statement: the effective interest rate discounts the amortizations back to the net proceeds | PASS | 5 | 0 |
| 35 | Regulatory figures: CAPR lines from the ledgers; the reconciliation catches a control-account entry made outside the member pages | PASS | 11 | 56 |
| 36 | Dashboard alerts: what needs attention is counted for the user who can act on it | PASS | 4 | 81 |
| 37 | Certificate of balances: each account on a past date from the ledgers; later postings are left out | PASS | 9 | 36 |
| 38 | Statement of Changes in Equity: the allocation moves the surplus to the funds; the ending total is the equity on the Statement of Financial Condition | PASS | 6 | 34 |
| 39 | Members who have left: paid out only as an approved settlement; only loan payments received; a deceased member is paid to the person named | PASS | 10 | 185 |
| 40 | Check deposits wait in the clearing account of their own code, and Check Clearing empties it | PASS | 12 | 134 |
| 41 | Loan set-up rules in effect: a charge applies from its effective date; products set to income when collected are not accrued | PASS | 4 | 21 |
| 42 | Ledger integrity after the additional cases | PASS | 9 | 3 |
| 43 | Party Master: a member, a customer and a supplier record of one person share one Party ID; nothing is linked by name alone | PASS | 24 | 22 |
| 44 | Acceptance scenario: the member buys on credit, pays, delivers goods, is paid by check, saves, borrows, repays and gets a patronage refund | PASS | 92 | 142 |
| 45 | Nonmembers: a nonmember customer and supplier, member and nonmember prices, members-only and approval rules, nothing in patronage | PASS | 24 | 96 |
| 46 | Two branches and lines of business: the party statement by branch, unit statements, inter-unit eliminations | PASS | 21 | 129 |
| 47 | Posted documents: locked against changes and deletion except by the permission with a reason; voids keep the document | PASS | 16 | 47 |
| 48 | Names on issued documents: an edited master does not change old documents; an authorized reissue corrects them with a reason | PASS | 14 | 20 |
| 49 | Roles and identity: revoke and restore keep history; linking duplicates needs the permission and moves records and documents; a wrong link is moved back | PASS | 28 | 52 |
| 50 | Permissions: confidential member details, page access of the new pages, lines of business switched off | PASS | 9 | 14 |
| 51 | Upgrade of data recorded before the Party Master: one party per record, never merged by name, crosswalk, snapshots, nothing posted changes, safe to run twice | PASS | 14 | 105 |
| 52 | Ledger integrity after the multipurpose cases: every document balances, every subsidiary ledger agrees with its control account | PASS | 21 | 14 |
| 53 | Water: one member is also a water customer (two service accounts) and a supplier; a nonmember neighbour has water only | PASS | 62 | 218 |
| 54 | Water: corrections keep their documents; a reversed receipt reopens the bill; a material issuance moves the one stock ledger | PASS | 32 | 47 |
| 55 | Class required: refused without one or with an inactive one; the voucher and every ledger line carry the class chosen | PASS | 9 | 33 |
| 56 | Journal entry changes: an explanation, a second user with the Approve right, the entry saved with the change; refused when the entry changed meanwhile | PASS | 20 | 45 |
| 57 | Member grouping lists: offered on the member form, renamed on the members that have them, not deleted while in use | PASS | 9 | 12 |
| 58 | Members on the name lists by their type, and on the customer lists | PASS | 8 | 9 |
| 59 | Water: a posting with a class carries it on the document and every line; a water form without a class is refused | PASS | 4 | 0 |
| 60 | Member defaults: every member has its own customer and supplier record, which follow the member and carry the Customer and Vendor Defaults | PASS | 20 | 37 |
| 61 | One name: the member is listed once on the name pickers; the entry is recorded on the record of the role the member plays | PASS | 30 | 30 |
| 62 | Member Water Defaults: the member form opens and keeps the member's water-service account; a disconnection date disconnects it | PASS | 29 | 78 |
| 63 | Savings Transaction Center: one transaction number series; the code decides the entry (User Account = the teller's cash, a GL account of its own); cash in the teller's shift | PASS | 25 | 38 |
| 64 | User profile cash account: a teller without a cash account of their own transacts with the cash account of their profile | PASS | 3 | 96 |
| 65 | Error correct: the erroneous transaction is negated in full (negative in its column), the entry reversed, never twice; a voided correction frees it again | PASS | 27 | 119 |
| 66 | Check deposits, memos, back entries and deletion: cheque details required; a memo takes the GL account chosen; a back entry needs a supervisor; a printed transaction is corrected, not deleted | PASS | 23 | 95 |
| 67 | R Check, close and delete account: a returned check is debited (clearing account, or the bank once cleared); closing pays the balance out with the closing code; only an unused account is deleted | PASS | 28 | 145 |
| 68 | Time deposit in the center and its accrued interest withdrawal (CAIW): numbered transactions; the interest paid comes out of the accrual, the principal stays; the settlement pays the rest; a void puts it back | PASS | 22 | 166 |
| 69 | Passbook: line numbers, the account name and number on every page, the fold left blank, a dash in the empty column, error corrections negative, and the print line reset | PASS | 11 | 128 |
| 70 | Loan Collection Form: the figures of the form (balances, dues as of the date, number of amortizations, last payment, payoff) and the open loans | PASS | 14 | 43 |
| 71 | Loan Collection Form: a check payment and its error correction (EC row negative, journal reversed, balances, schedule, status and last payment back, the cheques off the clearing account); the passbook, the validation and the list show both | PASS | 58 | 118 |
| 72 | Loan Collection Form: Error Correct of a cash payment pays it back from the drawer by a cash voucher; a payoff corrected opens the loan again (rebate undone, holds back); a receipt with other items and a cleared check are corrected another way; a bank payment by a journal voucher | PASS | 26 | 170 |
| 73 | Loan Collection Form: Override Payment applies the amount as entered (permission, must add up, within the balances); the list of collections by date range and search | PASS | 15 | 64 |
| 74 | Slip validation: margins (top, right, bottom, left), line spacing and font from Passbook Types; the former twips positions until they are set | PASS | 4 | 6 |
| 75 | Teller menu: Loan Collection and the Savings Transaction Center are under Teller (the page keys and the access stay); a switched-off line still closes them | PASS | 13 | 23 |
| 76 | Daily Collection & Disbursement Summary: every Loan Collection and Savings Transaction Center transaction of the teller, by source, error corrections taken back, net cash = the drawer | PASS | 21 | 200 |
| 77 | Loan Release Form: Cash release is a Cash Voucher of Banking from the cash account chosen, with the voucher ID, the class on every line and the deductions changed by the user | PASS | 33 | 59 |
| 78 | Cheque release: a check of Write Checks numbered with the cheque no., drawn on the bank account chosen; never edited or deleted, voided with the loan; a cheque no. used once | PASS | 23 | 96 |
| 79 | Loan release vouchers: the monitoring list has every voucher of a release (Banking and before), the approver and the preparer; the teller's cash comes from the drawer of the shift only | PASS | 13 | 90 |
| 80 | Internal Ledger: the additional interest and the penalty by the product set-up (missed amortizations, past maturity), the balances; the Loan Ledger Form in passbook view or every entry | PASS | 27 | 80 |
| 81 | Capacity to Pay: the entries saved on a loan application are where the borrower's next application starts; updated entries replace them, others stay | PASS | 9 | 25 |
| 82 | Credit Investigation: the borrower's last investigation is where the next one starts (residence, children, income, assets, references); an income without a source name is saved | PASS | 9 | 25 |
| 83 | Passbook lines as the former program: printing starts on line 1, Start Line Skip and No. of Line Skip leave the fold blank, after Max Lines line 1 of the next page with the header | PASS | 14 | 116 |
| 84 | Loan passbook: its own design, the header (Loan Type, Term, Due Date), the release on line 1, Release, Loan Payment, Loan Balance, Interest, Fines and Teller ID, an error correction negative | PASS | 16 | 101 |
| 85 | Passbook design: fields placed in tups from the Top / Left of their part, formats, condensed print; what is not of its kind is left out; the settings of before are carried; test pages | PASS | 18 | 10 |
| 86 | Delete a loan application: one never released goes with its co-makers, collateral, approvals, requirements and credit investigation; a released one stays | PASS | 16 | 61 |
| 87 | Payment allocation: nothing due, the whole payment goes to the principal; with dues, 1. penalties, 2. interest, 3. principal | PASS | 23 | 102 |
| 88 | Passbook run: unprinted lines on several pages print in one run, page by page (turn the passbook to page 2, 3 ...), and are marked printed together | PASS | 22 | 206 |
| 89 | Passbook run: the header modes of the design on the pages that follow (on a new page, with every printing, never) and a loan passbook over two pages | PASS | 7 | 249 |
| 90 | Account types: the fifteen built-in ones, types of your own behaving as one and linked to the Statement of Cash Flows; unique names; a built-in type keeps its name and what it is | PASS | 14 | 11 |
| 91 | Giving accounts a type: the account keeps behaving as its built-in type for the modules; an account the modules post to keeps its base; a type in use keeps its base and is not deleted | PASS | 13 | 16 |
| 92 | Statements by account type: each type a classification (heading, accounts, total); a main account with its sub-accounts and their total; the summaries show main accounts only; the totals do not change | PASS | 29 | 37 |
| 93 | Statement of Cash Flows: each account type in its section; cash = the types counted as cash and cash equivalents; the statement ties to them whatever the sections | PASS | 10 | 30 |
| 94 | Main accounts on the statements: a sub-account of another built-in type follows its main account; the main account's own entries; a group of one built-in type has no type heading; depreciation stays an operating adjustment | PASS | 13 | 24 |
| 95 | Loan adjustment in the loan passbook: Print to Passbook prints it (code AJ, a decrease as a payment, an increase negative); the adjustments of before are passed over once | PASS | 14 | 107 |
| 96 | Loan Statement of Account: the search box lists the borrowers with outstanding loans (name, loan product, loan no.), narrowed by a name, a loan no. or a product | PASS | 17 | 121 |
| 97 | Interest on share capital and patronage refund: computed for review (nothing posted), the members who owe the cooperative stand out and are removed with the reason, then posted | PASS | 24 | 276 |
| 98 | Interest on share capital: the average daily balance shown day by day equals the basis of the batch, to the centavo | PASS | 17 | 118 |
| 99 | Patronage refund: the income earned shown document by document equals the basis of the batch | PASS | 6 | 40 |
| 100 | Delete a computation never posted: in review, reviewed or approved, with the reason and the rights; a posted batch is reversed instead | PASS | 20 | 815 |
| 101 | Import Member: every required field checked, the member type and dates as written in the file, the same member twice refused; uploaded all or none, Active | PASS | 14 | 76 |
| 102 | Import Savings: each account opened Active, its balance entered on the cut-off date with the savings transaction code chosen (one voucher per date and code against the code's GL account) | PASS | 16 | 29 |
| 103 | Import Time Deposit: each placement Active with its term, rate and maturity, its principal entered with the code chosen; the term in days, months or years | PASS | 10 | 32 |
| 104 | Batch Deposit and Batch Withdrawal: one transaction of the Savings Transaction Center per row (account no. and amount); the withdrawable balance, holds and restrictions checked; all or none | PASS | 14 | 56 |
| 105 | Hold and Unhold: an amount of the balance earmarked (at most what is withdrawable), the rest withdrawable; released one hold or every hold | PASS | 8 | 8 |
| 106 | Official receipt: every GL account as a fee or other item except those of the savings, time deposits, loans receivable and the receivables and inventory of sales | PASS | 18 | 42 |
| 107 | Menus: Financial and Members & Others in the cooperative's order; Member Disbursement and Cooperative Vouchers out of the menu (their vouchers still open) | PASS | 9 | 7 |
| 108 | Import Loans: the terms as a former system writes them (months or days, the mode, the amortization type, a rate a year or a month) | PASS | 4 | 3 |
| 109 | Import Loans: a diminishing loan rebuilt from its release (schedule, the totals paid on the due dates in order, the arrears), Active, one voucher against the balancing account | PASS | 15 | 87 |
| 110 | Import Loans: the overdue penalties worked out by the product's terms on the past-due amortizations, less the penalties paid; or as given | PASS | 6 | 24 |
| 111 | Import Loans: the past due interest of a matured loan, worked out on the principal unpaid from the maturity date at the loan's rate, less the interest paid beyond the schedule; or as given | PASS | 12 | 40 |
| 112 | Import Loans: what is refused (product, mode, type, rate, dates, amounts, the same loan twice, a fully paid loan, too much paid, the member, the closing date) | PASS | 14 | 34 |
| 113 | Import Member, the full template: every other detail of the member as a column the file may have; saved on the member and its lists | PASS | 14 | 20 |
| 114 | The checker of the reviews: a row's problems name its cells (by the column's name in the message, or as the check says), the answer to the page by line | PASS | 3 | 11 |
| 115 | Import Loans, after the review: the past due interest of a loan not yet matured in date order and collected first, the loan no. of the former system never drawn again, the accrued interest, a lump sum, the voucher by class and by cut-off date | PASS | 18 | 154 |
| 116 | Import Member, after the review: a column a problem names is shown so it can be filled in; the employer contact up to 40 characters | PASS | 2 | 3 |
| 117 | Ladder Rate Billing: the workbook's 250 m3 on RESIDENTIAL ASSOCIATE adds up each tier (13,230.50); Straight Rate Billing keeps 250 x 55.40 (13,850.00) | PASS | 5 | 0 |
| 118 | Ladder Rate Billing at the tier boundaries: the minimum charge, the first cubic meter of a tier, the last one | PASS | 8 | 0 |
| 119 | Ladder Rate Billing with the member discounts: the free cubic meters come off the top of the ladder, a percentage after them | PASS | 5 | 0 |
| 120 | Ladder Rate Billing set on a rate code: the bills of its accounts follow it (and go back when it is set back) | PASS | 3 | 34 |
| 121 | Bill Readings and the Statement of Account: the member search answers (each word in the name, the account number or the address) | PASS | 8 | 8 |
| 122 | Collection Summary over a period of dates and times: From and To carry a time; with times a receipt counts at the moment it was recorded, whole days by business date | PASS | 15 | 11 |
| 123 | Official receipt: the paid bill-code amounts are changed only by a user whose role has the permission (administrators always) | PASS | 4 | 3 |
| 124 | Migration 2027_03_07: the computation of the rate codes, Straight Rate Billing for those on file, safe to run again | PASS | 4 | 6 |
| 125 | Inventory Center history: a back-dated movement takes its place by date, and the balances after each movement follow the dates | PASS | 5 | 33 |
| 126 | Fixed Share Capital Billing: the posting dates (the posting day on or after the start date, every frequency after, the last day of a shorter month) | PASS | 9 | 0 |
| 127 | Fixed Share Capital Billing: only a member's open share capital account; one billing per account; the amount, frequency and day checked | PASS | 12 | 45 |
| 128 | Fixed Share Capital Billing: the bills are posted on their posting day with NO journal entry, once only, and the past days of a new billing at once | PASS | 9 | 45 |
| 129 | Fixed Share Capital Billing: hold stops the bills (the days on hold are not billed), resume starts again, an edit never bills a month twice, delete cancels the unpaid bills | PASS | 12 | 13 |
| 130 | Fixed Share Capital Billing at the water cashier: the bill is a receivable of the member's water account; the collection deposits to share capital by the code of the page (CSD), journal and passbook | PASS | 30 | 136 |
| 131 | Fixed Share Capital Billing: a receipt reversed (error correction) takes the deposit back with ECSD and its journal entry, and the bill is open again | PASS | 18 | 21 |
| 132 | Fixed Share Capital Billing guards: an edit never back-bills, a billing added again never bills a month twice, a closed account is not billed, the import codes are not offered | PASS | 9 | 29 |
| 133 | Fixed Share Capital Billing: the deposit of a water receipt is corrected only through the receipt; a pledged balance does not block it | PASS | 8 | 120 |
| 134 | Collection Summary: Summary shows the share capital beside the bills paid; Detailed a column for each code name collected, never put together | PASS | 14 | 7 |
| 135 | Migration 2027_03_14: the three tables, the receipt column and types, the page after One-Time Billing with its rights (run twice) | PASS | 13 | 21 |
| 136 | Term (days) of Fixed Share Capital Billing: the due date is the posting date plus the term; blank, the bill has no due date (open) | PASS | 8 | 30 |
| 137 | Batch Share Capital Billing: the members ticked get the same amount, frequency, start date, posting day and term, every one or none | PASS | 9 | 37 |
| 138 | Import of Batch Share Capital Billing: the lines checked (account, name, the form's values for blank cells, OPEN, a lost leading zero, duplicates), then posted every one or none | PASS | 19 | 54 |
| 139 | The Excel reader of the imports: text kept as typed (leading zeros), numbers as stored, a date cell as YYYY-MM-DD, the first worksheet | PASS | 4 | 2 |
| 140 | Import of One-Time Billing charges: each line its own charge category and amount, the price of the bill code for a blank amount, the water bill refused; one bill and invoice per line, every line or none | PASS | 19 | 35 |
| 141 | The review of an import refuses what posting would refuse: a date not in a known form, an amount too large, an account number a lost zero makes ambiguous, an account ID for an account that has a number, a bill code whose accounts cannot post, a class not chosen | PASS | 14 | 33 |
| 142 | The Excel reader: a worksheet written with a prefix (x:worksheet), rows of formatted empty cells left out, a cell far to the right not read, and "line N" of the review is row N of the worksheet | PASS | 3 | 55 |
| 143 | Migration 2027_03_21: the term and batch of a billing, the due date and term of a bill (run twice); every billing on file stays open | PASS | 11 | 13 |
| 144 | The joint account / institution form: its boxes are read in place of the individual's; the individual's own lists and the grouping are not read for it | PASS | 6 | 0 |
| 145 | A joint account and an institution saved with their representatives, addresses and contact list; saved again from the joint form, the details it has no box for are kept | PASS | 6 | 34 |
| 146 | Get passbook number: the next number of the Passbook series that no member has (a number given by hand is skipped) | PASS | 6 | 4 |
| 147 | Regular - Joint: a regular member type of the joint account form, beside Associate - Joint; the upgrade adds it once | PASS | 8 | 14 |
| 148 | Time deposit: the accrued interest a member withdrew before maturity (CAIW) is deducted from the accrued interest; SL = GL on the reconciliation and the reports | PASS | 17 | 132 |
| 149 | Address sub-groups: added, renamed on every member and water-service account that has it, switched off, deleted only when nothing has it | PASS | 13 | 36 |
| 150 | Reading Entry: an unposted reading is deleted with its reason (and can be read again); a posted reading, or one followed by a later reading, is refused | PASS | 11 | 72 |
| 151 | Incremental searching of the water-service accounts: every word typed is found; the names that start with what is typed come first | PASS | 3 | 8 |
| 152 | Pre-printed receipts: the layout is kept clean, saved and put back to the default; the entries are formatted and placed; the lines that do not fit are added together | PASS | 30 | 8 |
| 153 | Billing Adjustments > Edit: the bill itself; the preview works out what the save writes; a due date before the bill date is refused; the term and the disconnection date follow the due date | PASS | 17 | 172 |
| 154 | Individual Statement of Account: every unpaid invoice of the account whatever its billing period, dated on or before the statement date; partly paid by its balance, paid or deleted left out; found by name or account number | PASS | 19 | 169 |
| 155 | Version 49: the set-up guide has no Technical prerequisites or Import readiness step, and nothing depends on them | PASS | 64 | 17 |
| 156 | Version 49: Data Import Management is gone from the program; a leftover copy of its page or endpoints is refused | PASS | 27 | 1 |
| 157 | Version 50: no Billing readiness step; a reading or a posting still needs an open billing period | PASS | 15 | 109 |
| 158 | Version 51: no query compares a date with a filter that can be empty (MySQL 8 error 1525) | PASS | 2 | 75 |
| 159 | Version 52: a member's price level on Customer Defaults decides the price of the items the member buys | PASS | 19 | 90 |
| 160 | Version 53: the accounting update is installed; its migration runs twice without a change; the program asks for no other update | PASS | 24 | 21 |
| 161 | Version 53: an item group sold to a member: the member's price level on the group price and on its items, the variance to Item Group Price Variance, the group sales recorded | PASS | 26 | 34 |
| 162 | Version 53: an inventory part with no unit cost is not sold from a sales form; the bills the water module posts from its own records keep their rules | PASS | 6 | 23 |
| 163 | Version 53: the statements by class add up to the plain statements; the cash flow statements follow the account types and the comparative income statement the inter-unit elimination | PASS | 69 | 42 |
| 164 | Version 53: the report dates: a time after each date on the accounting reports, the date-time boxes of the water collection report unchanged | PASS | 10 | 9 |
| 165 | Version 53: the Inventory Center history: on one date the stock received before the stock sold; the balance and the average after each line in that order | PASS | 11 | 25 |
| 166 | Version 54: the Member type filter of Sales by Customer Detail: all members, one member type, the non-members (with the walk-in sales) | PASS | 23 | 51 |
| 167 | Version 54: Sales by Customer Detail Group by Customer/Member Type: members by member type, the others by customer type, a subtotal per customer and per type | PASS | 14 | 10 |
| 168 | Version 54: the loans due of a water-service account's member: principal, interest and penalty due by loan, as the Loan Collection form has them | PASS | 22 | 82 |
| 169 | Version 55: Upload Bill Balances reads either layout: one row per member with a column for each billing type (1, 2, 3 or 4 of them), or one row per balance | PASS | 12 | 79 |
| 170 | Version 55: Upload Bill Balances checks every line before anything is recorded (account, name, billing type, balance, dates, charge category, plans, installments, fixed billing) | PASS | 27 | 87 |
| 171 | Version 55: Upload Bill Balances records every line or none: a recurring bill, an installment contract, a one-time bill (each with its invoice, Dr A/R Cr clearing) and a share capital bill (no journal entry) | PASS | 38 | 150 |
| 172 | Version 55: Upload Bill Balances does not take the same balance twice: an earlier upload, the same bill date on a subscription or a fixed billing; a cancelled bill may be uploaded again | PASS | 12 | 16 |
| 173 | Version 55: migration 2027_04_18 adds the uploads, their lines and the page after Fixed Share Capital Billing with the rights of One-Time Billing (run twice) | PASS | 7 | 7 |
| 174 | Version 56: Consolidated Import knows the columns by their names (the four imports, other names, a second account numbered), and the user's choice is remembered | PASS | 12 | 42 |
| 175 | Version 56: Consolidated Import makes a line for each member, savings account, loan and bill balance: on one row or on the rows under the member, a member with only savings, savings and loans, savings and bill balances | PASS | 16 | 120 |
| 176 | Version 56: Consolidated Import checks every line with the checks of the four imports and the file as a whole; a value corrected in the review is checked again at once | PASS | 25 | 144 |
| 177 | Version 56: Consolidated Import saves the members with their savings, loans, water-service accounts, fixed share capital billings and bill balances, a few members at a time | PASS | 27 | 315 |
| 178 | Version 56: Consolidated Import saves thousands of rows: read in pieces, checked and saved in steps, each group of members in one transaction (a problem at saving keeps the members before it) | PASS | 10 | 14318 |
| 179 | Version 56: the migration 2027_04_25 adds the page (with the rights of Member Center) and the tables, and may be run twice | PASS | 5 | 4 |
| 180 | Version 57: the Bill Accounts Center shows every bill of the members and customers, the open ones by default, with Amount, Paid, Balance and the status of each | PASS | 17 | 122 |
| 181 | Version 57: the Bill Accounts Center filters by bill status, bill code, zone, address sub-group, members or customers, bill type, bill dates and the words searched | PASS | 19 | 39 |
| 182 | Version 57: the Bill Accounts Center is exported to Excel or CSV and printed with the lines found; it is a page of Water Billing > Billing given to the roles of the billing pages | PASS | 11 | 11 |
| 183 | Version 57: Chart of Accounts > Import reads the accounts of a file in its own words and shows the problems of each line before anything is saved | PASS | 13 | 3 |
| 184 | Version 57: Chart of Accounts > Import saves every account or none (main accounts first); an account on the chart is updated from its line or left as it is; the rules of the account form | PASS | 14 | 16 |
| 185 | Version 57: Chart of Accounts > Import reads the chart's own Export back unchanged, and an Excel workbook as a CSV file | PASS | 3 | 9 |
| 186 | Version 57: the water bill / collection receipt prints on Letter bond paper, portrait, two copies one above the other, black and bold, with no header or footer of the browser | PASS | 11 | 4 |
