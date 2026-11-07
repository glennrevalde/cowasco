# Water module end-to-end acceptance tests (HTTP)

- **Run:** 2026-11-07 23:40:14 (15.1 s)
- **Server:** http://127.0.0.1:8098
- **Database:** coop_e2e_m8 (fresh install + the former water database migrated with tools/migrate_water.php)
- **Environment:** PHP 8.3.6, 8.0.46-0ubuntu0.24.04.4
- **Result:** 438 passed, 0 failed, 438 checks


## 0. Set-up: one login, one session, mapping checks before posting

| Check | Expected | Actual | Result |
|---|---|---|---|
| admin signs in on the one login page (and sets a new password) | yes | yes | PASS |
| a water page opens in the same session (no second login) | 200 | 200 | PASS |
| the water page shows the unified menu (Water Billing, Chart of Accounts, Customers) | yes | yes | PASS |
| no PHP error on the water dashboard | yes | yes | PASS |
| collection policy saved through its page (penalty income account) | 181 | 181 | PASS |
| report signatories saved through Institution Configuration | 2 | 2 | PASS |
| six active water-service accounts with a working tariff found | 6 | 6 | PASS |
| an open billing period that has ended exists (080126_083126) | yes | yes | PASS |

## 7.1 Meter reading, rate computation, bill, A/R, collection, official receipt, GL, party statement, reports

| Check | Expected | Actual | Result |
|---|---|---|---|
| reading saved for account 114842 (23 m3) | yes | yes | PASS |
| account 114842: amount computed by the page = the tariff (same formula as before) | 1,030.40 | 1,030.40 | PASS |
| reading saved for account 114843 (8 m3) | yes | yes | PASS |
| account 114843: amount computed by the page = the tariff (same formula as before) | 246.40 | 246.40 | PASS |
| reading saved for account 114849 (41 m3) | yes | yes | PASS |
| account 114849: amount computed by the page = the tariff (same formula as before) | 2,230.40 | 2,230.40 | PASS |
| reading saved for account 114850 (15 m3) | yes | yes | PASS |
| account 114850: amount computed by the page = the tariff (same formula as before) | 499.50 | 499.50 | PASS |
| reading saved for account 114851 (30 m3) | yes | yes | PASS |
| account 114851: amount computed by the page = the tariff (same formula as before) | 1,314.00 | 1,314.00 | PASS |
| reading saved for account 114852 (12 m3) | yes | yes | PASS |
| account 114852: amount computed by the page = the tariff (same formula as before) | 411.60 | 411.60 | PASS |
| draft readings post nothing to the ledger | 0 | 0 | PASS |
| Post Billing without a class is refused (the class is required) | yes | yes | PASS |
| ...and posts nothing | 0 | 0 | PASS |
| Post Billing posts the period | yes | yes | PASS |
| every ledger line of the water bills carries the class chosen on the posting | 0 | 0 | PASS |
| the posted bills keep their class | 0 | 0 | PASS |
| bills posted | 6 | 6 | PASS |
| bill of account 114842 has its accounting document (event WBILL-1) | yes | yes | PASS |
| account 114842: invoice total = water bill amount | 1,030.40 | 1,030.40 | PASS |
| account 114842: invoice number = the bill's own reference number | 1 | 1 | PASS |
| account 114842: invoice is A/R of the account holder's customer record | 10 | 10 | PASS |
| account 114842: customer record belongs to the account's party | 31 | 31 | PASS |
| account 114842: invoice balanced (debits = credits) | yes | yes | PASS |
| account 114842: invoice carries the Water Utility line of business | 3 | 3 | PASS |
| account 114842: A/R debit on the water A/R account | Accounts Receivable | Accounts Receivable | PASS |
| bill of account 114843 has its accounting document (event WBILL-2) | yes | yes | PASS |
| account 114843: invoice total = water bill amount | 246.40 | 246.40 | PASS |
| account 114843: invoice number = the bill's own reference number | 2 | 2 | PASS |
| account 114843: invoice is A/R of the account holder's customer record | 11 | 11 | PASS |
| account 114843: customer record belongs to the account's party | 32 | 32 | PASS |
| account 114843: invoice balanced (debits = credits) | yes | yes | PASS |
| account 114843: invoice carries the Water Utility line of business | 3 | 3 | PASS |
| account 114843: A/R debit on the water A/R account | Accounts Receivable | Accounts Receivable | PASS |
| bill of account 114849 has its accounting document (event WBILL-3) | yes | yes | PASS |
| account 114849: invoice total = water bill amount | 2,230.40 | 2,230.40 | PASS |
| account 114849: invoice number = the bill's own reference number | 3 | 3 | PASS |
| account 114849: invoice is A/R of the account holder's customer record | 12 | 12 | PASS |
| account 114849: customer record belongs to the account's party | 38 | 38 | PASS |
| account 114849: invoice balanced (debits = credits) | yes | yes | PASS |
| account 114849: invoice carries the Water Utility line of business | 3 | 3 | PASS |
| account 114849: A/R debit on the water A/R account | Accounts Receivable | Accounts Receivable | PASS |
| bill of account 114850 has its accounting document (event WBILL-4) | yes | yes | PASS |
| account 114850: invoice total = water bill amount | 499.50 | 499.50 | PASS |
| account 114850: invoice number = the bill's own reference number | 4 | 4 | PASS |
| account 114850: invoice is A/R of the account holder's customer record | 13 | 13 | PASS |
| account 114850: customer record belongs to the account's party | 39 | 39 | PASS |
| account 114850: invoice balanced (debits = credits) | yes | yes | PASS |
| account 114850: invoice carries the Water Utility line of business | 3 | 3 | PASS |
| account 114850: A/R debit on the water A/R account | Accounts Receivable | Accounts Receivable | PASS |
| bill of account 114851 has its accounting document (event WBILL-5) | yes | yes | PASS |
| account 114851: invoice total = water bill amount | 1,314.00 | 1,314.00 | PASS |
| account 114851: invoice number = the bill's own reference number | 5 | 5 | PASS |
| account 114851: invoice is A/R of the account holder's customer record | 14 | 14 | PASS |
| account 114851: customer record belongs to the account's party | 40 | 40 | PASS |
| account 114851: invoice balanced (debits = credits) | yes | yes | PASS |
| account 114851: invoice carries the Water Utility line of business | 3 | 3 | PASS |
| account 114851: A/R debit on the water A/R account | Accounts Receivable | Accounts Receivable | PASS |
| bill of account 114852 has its accounting document (event WBILL-6) | yes | yes | PASS |
| account 114852: invoice total = water bill amount | 411.60 | 411.60 | PASS |
| account 114852: invoice number = the bill's own reference number | 6 | 6 | PASS |
| account 114852: invoice is A/R of the account holder's customer record | 15 | 15 | PASS |
| account 114852: customer record belongs to the account's party | 41 | 41 | PASS |
| account 114852: invoice balanced (debits = credits) | yes | yes | PASS |
| account 114852: invoice carries the Water Utility line of business | 3 | 3 | PASS |
| account 114852: A/R debit on the water A/R account | Accounts Receivable | Accounts Receivable | PASS |
| posting the same period again is refused | yes | yes | PASS |
| ...and creates no second invoice | 6 | 6 | PASS |
| a collection without a class is refused | yes | yes | PASS |
| the receipt and its journal entry carry the class | [1,1] | [1,1] | PASS |
| partial collection of account A accepted, receipt issued | yes | yes | PASS |
| the receipt has one payment document in the ledger | yes | yes | PASS |
| payment number = official receipt number | COL-20261107-234001-2B15 | COL-20261107-234001-2B15 | PASS |
| payment amount | 515.20 | 515.20 | PASS |
| payment debits the cashier's cash account | 230 | 230 | PASS |
| invoice A open balance after the partial payment | 515.20 | 515.20 | PASS |
| water bill A amount_paid | 515.20 | 515.20 | PASS |
| receipt document balanced | yes | yes | PASS |
| retry of the same request returns the same receipt | COL-20261107-234001-2B15 | COL-20261107-234001-2B15 | PASS |
| retry is reported as a duplicate | yes | yes | PASS |
| retry posts no second payment | 1 | 1 | PASS |
| two simultaneous collections of the same balance: exactly one accepted | 1 | 1 | PASS |
| invoice A fully settled, not over-applied | 0.00 | 0.00 | PASS |
| water bill A is paid in full | 1,030.40 | 1,030.40 | PASS |
| collection of account B with a 25.00 penalty accepted | yes | yes | PASS |
| the penalty is a finance charge of the account, settled by the same receipt | yes | yes | PASS |
| penalty credited to the penalty income account | 181 | 181 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |
| account 114842: water open balance = ledger open balance | 0.00 | 0.00 | PASS |
| account 114843: water open balance = ledger open balance | 0.00 | 0.00 | PASS |
| account 114849: water open balance = ledger open balance | 2,230.40 | 2,230.40 | PASS |
| Consolidated Party Statement of account A opens | 200 | 200 | PASS |
| ...lists the water invoice number | yes | yes | PASS |
| ...lists the official receipt number | yes | yes | PASS |
| the printable official receipt opens with its number | yes | yes | PASS |
| ...issued in the cooperative's own name (company settings), not a hard-coded one | yes | yes | PASS |
| Party Center shows the water-service account of the party | yes | yes | PASS |
| report Trial Balance opens without an error | yes | yes | PASS |
| report General Ledger opens without an error | yes | yes | PASS |
| report Balance Sheet opens without an error | yes | yes | PASS |
| report Income Statement opens without an error | yes | yes | PASS |
| report Cash Flows opens without an error | yes | yes | PASS |
| report A/R Aging opens without an error | yes | yes | PASS |
| report Collection Summary opens without an error | yes | yes | PASS |
| report Revenue by Billing Period opens without an error | yes | yes | PASS |
| report Receivables by Source opens without an error | yes | yes | PASS |
| report Payment History opens without an error | yes | yes | PASS |
| report Water Service Account Statement opens without an error | yes | yes | PASS |
| report Water Posting Log opens without an error | yes | yes | PASS |
| report Account Mapping Validation opens without an error | yes | yes | PASS |
| report A/P Aging opens without an error | yes | yes | PASS |
| report Audit Trail opens without an error | yes | yes | PASS |
| Collection Summary shows today's receipt total | yes | yes | PASS |

## 7.4 Corrections: bill edit before collection, refusal after, collection reversal, re-collection

| Check | Expected | Actual | Result |
|---|---|---|---|
| the original invoice is voided (kept, marked void) | yes | yes | PASS |
| a revision invoice "-R1" is posted and linked to the original | yes | yes | PASS |
| revision total = the bill recomputed for 19 m3 | 632.70 | 632.70 | PASS |
| revision number shows the invoice number with -R1 | yes | yes | PASS |
| editing a collected bill is refused (no new revision) | yes | yes | PASS |
| reversed receipt: its payment document is voided, not deleted | yes | yes | PASS |
| the reversal is recorded with its reason and links to the receipt | yes | yes | PASS |
| invoice A open again by the reversed amount | 515.20 | 515.20 | PASS |
| water bill A amount_paid restored | 515.20 | 515.20 | PASS |
| reversing the same receipt twice changes nothing | 515.20 | 515.20 | PASS |
| the corrected collection is made with a new receipt number | yes | yes | PASS |
| invoice A settled again | 0.00 | 0.00 | PASS |
| trial balance after corrections: debits = credits | 0.00 | 0.00 | PASS |

## 7.1b One-time, installment and recurring bills; mapping exceptions block posting

| Check | Expected | Actual | Result |
|---|---|---|---|
| a bill code whose A/R account is not a receivable cannot post (legacy set-up exception) | 0 | 0 | PASS |
| ...and no one-time bill is left half-saved | 0 | 0 | PASS |
| bill code corrected on its page (A/R = Accounts Receivable - Water) | 274 | 274 | PASS |
| the one-time bill posts once the code is fixed | yes | yes | PASS |
| one-time bill invoice total | 50.00 | 50.00 | PASS |
| one-time bill credits the service fee income | 374 | 374 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |
| installment contract posted as one invoice | yes | yes | PASS |
| installment invoice = contract total | 600.00 | 600.00 | PASS |
| three schedules | 3 | 3 | PASS |
| first installment collected | yes | yes | PASS |
| installment invoice open = contract less the first schedule | 400.00 | 400.00 | PASS |
| recurring plan created on its page | yes | yes | PASS |
| account F enrolled | 1 | 1 | PASS |
| the current-cycle recurring bill is generated and posted | yes | yes | PASS |
| generating again does not bill the same cycle twice | 1 | 1 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |

## 7.3 Inventory: procurement, receipt, stock, issuance to the field, return, sale of a meter, valuation, A/P

| Check | Expected | Actual | Result |
|---|---|---|---|
| the inventory bill code WATER METER has its item in the one item master | yes | yes | PASS |
| a former water supplier is a supplier (vendor) of the cooperative with a party | yes | yes | PASS |
| purchase of 10 meters entered on Enter Bills | yes | yes | PASS |
| stock on hand +10 at the water site (one inventory ledger) | 10.00 | 10.00 | PASS |
| A/P: the supplier is owed 7,500.00 | 7,500.00 | 7,500.00 | PASS |
| 2 meters issued to the field: an inventory adjustment of the cooperative inventory | yes | yes | PASS |
| stock on hand after the issuance | 8.00 | 8.00 | PASS |
| issuance charged to the expense account at average cost (2 x 750) | 1,500.00 | 1,500.00 | PASS |
| issuance document balanced | yes | yes | PASS |
| reversal returns the stock | 10.00 | 10.00 | PASS |
| the issuance document is voided and kept | yes | yes | PASS |
| issued again with 1 meter | 9.00 | 9.00 | PASS |
| meter sold on a one-time bill and posted | yes | yes | PASS |
| stock on hand after the sale | 8.00 | 8.00 | PASS |
| cost of the meter sold (COGS 750.00) on the same invoice | 750.00 | 750.00 | PASS |
| stock valuation: 8 meters x 750.00 | 6,000.00 | 6,000.00 | PASS |
| inventory asset in the GL = stock valuation for the item | 6,000.00 | 6,000.00 | PASS |
| Inventory Valuation Summary opens and lists the meter | yes | yes | PASS |
| supplier paid on Pay Bills: the bill is settled | 0.00 | 0.00 | PASS |
| A/P owed to the supplier | 0.00 | 0.00 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |

## 7.2b Opening a water-service account: party on file, duplicate check, no automatic membership

| Check | Expected | Actual | Result |
|---|---|---|---|
| the party search of the Open Account form finds the holder of account B | yes | yes | PASS |
| the second account is opened | 6889 | 6889 | PASS |
| ...on the party chosen (one party, two water-service accounts) | 31 | 31 | PASS |
| the party now holds two water-service accounts | 2 | 2 | PASS |
| the Party / Member Center lists both accounts on the water tab | yes | yes | PASS |
| a consumer who looks like a party on file is not given a second party silently (nothing saved) | 6889 | 6889 | PASS |
| ...the page says why and offers the party on file | yes | yes | PASS |
| with "This is a different person" the account opens | 6890 | 6890 | PASS |
| ...with a party of its own | yes | yes | PASS |
| opening water accounts makes nobody a cooperative member (members) | 0 | 0 | PASS |
| ...and writes nothing to the former membership register | 361 | 361 | PASS |
| the new parties are not members | yes | yes | PASS |

## 7.4b Permissions: a cashier collects, cannot post billing or open the ledger set-up

| Check | Expected | Actual | Result |
|---|---|---|---|
| cashier signs in | yes | yes | PASS |
| cashier opens Collections (Cashier) | yes | yes | PASS |
| cashier cannot open Post Billing (access denied page, 403) | yes | yes | PASS |
| ...and the denied page says so | yes | yes | PASS |
| cashier posting billing directly to the endpoint is refused (403) | 403 | 403 | PASS |
| cashier cannot open Make Journal Entries (403) | 403 | 403 | PASS |
| cashier collects account F | yes | yes | PASS |
| the receipt records the cashier who collected it | 16 | 16 | PASS |
| the payment document is attributed to the cashier | 16 | 16 | PASS |
| a request without the CSRF token is refused (403, code csrf_invalid; 419 is not an Apache status) | 403 | 403 | PASS |
| ...and says the form session expired | yes | yes | PASS |

## v41 Member search, Ladder Rate Billing, several charges on one form, a bill issued again, receipt amounts, a collection period with times

| Check | Expected | Actual | Result |
|---|---|---|---|
| Bill Readings: the member search finds the account by its first and last name | yes | yes | PASS |
| ...and by "last name, first name" | yes | yes | PASS |
| Statement of Account: the member search finds the account by its account number, and shows it | yes | yes | PASS |
| a name on no account finds nothing (an empty list, not an error) | yes | yes | PASS |
| a rate code on file keeps Straight Rate Billing after the upgrade | straight | straight | PASS |
| Rate Codes: the computation is saved as Ladder Rate Billing on its page | ladder | ladder | PASS |
| 45 m3 on Ladder Rate Billing = each tier's cubic meters at its rate, added up | 1,873.50 | 1,873.50 | PASS |
| ...below the Straight Rate Billing of the same 45 m3 (all of it at the rate of the tier it falls in) | yes | yes | PASS |
| the bill says how it was computed | Ladder Rate Billing | Ladder Rate Billing | PASS |
| the live preview of Bill Readings gets the computation of the rate code | yes | yes | PASS |
| ...and back to Straight Rate Billing: the same bill as before | 2,493.00 | 2,493.00 | PASS |
| two charges ticked on one form: two one-time bills | 3 | 3 | PASS |
| ...the typed amount of the first charge | 75.50 | 75.50 | PASS |
| ...the material at its sales price times the quantity (not the amount sent) | 1,200.00 | 1,200.00 | PASS |
| ...each with its own invoice | 2 | 2 | PASS |
| ...the stock of the material goes down by its quantity | 7.00 | 7.00 | PASS |
| ...and both carry the class chosen | 0 | 0 | PASS |
| no charge ticked: nothing is issued | 3 | 3 | PASS |
| the charge category of the bill changed | 5 | 5 | PASS |
| ...and its date issued | 2026-11-06 | 2026-11-06 | PASS |
| ...its amount follows the new category (a material at its sales price) | 1,200.00 | 1,200.00 | PASS |
| the first invoice is voided as replaced, not deleted | replaced | replaced | PASS |
| the corrected invoice -R1 is posted on the new date issued | yes | yes | PASS |
| ...crediting the income account of the new category | 44 | 44 | PASS |
| ...and the material leaves the stock | 6.00 | 6.00 | PASS |
| the re-issue is in the audit trail with the old and new values | yes | yes | PASS |
| a bill lowered from 100.00 to 80.00 gets a credit memo | yes | yes | PASS |
| ...then given another date issued: issued again for 80.00, the invoice and its credit memo replaced | yes | yes | PASS |
| ...the A/R of the bill is the 80.00 once | 80.00 | 80.00 | PASS |
| a negative amount is refused | 80.00 | 80.00 | PASS |
| the one-time bill of account E is collected | yes | yes | PASS |
| changing the category of a collected bill is refused | 2 | 2 | PASS |
| ...with the reason on the page | yes | yes | PASS |
| the administrator can change the receipt amounts before printing | yes | yes | PASS |
| a cashier without the permission sees them read-only | yes | yes | PASS |
| ...and can change them once the role is given the permission | yes | yes | PASS |
| Collection Summary: the whole day (00:00 to 23:59) lists every receipt of the day | 6 | 6 | PASS |
| ...the From and To fields take a date and time | yes | yes | PASS |
| ...a period ending before the first receipt's time lists none | 0 | 0 | PASS |
| ...a period starting at its minute lists them all again | 6 | 6 | PASS |
| ...and the receipts show their time | yes | yes | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |

## 7.5 Fixed Share Capital Billing: the member's fixed contribution, posted on its day with no journal entry, collected by the water cashier

| Check | Expected | Actual | Result |
|---|---|---|---|
| the member is the same party as water account C | 38 | 38 | PASS |
| Water Billing > Billing > Fixed Share Capital Billing opens | yes | yes | PASS |
| ...in the menu after One-Time Billing | yes | yes | PASS |
| ...with the transaction code upon collection (CSD) and the class | yes | yes | PASS |
| ...offering the member (an open share capital account) | yes | yes | PASS |
| the collection settings saved: CSD, the class | CSD/1 | CSD/1 | PASS |
| James Bond on fixed billing: monthly, 50.00, posting day 1 | yes | yes | PASS |
| the bills of last month and this month are posted at once | 2 | 2 | PASS |
| ...with NO journal entry | 29 | 29 | PASS |
| a second billing of the same account is refused | 1 | 1 | PASS |
| a stale form is refused (operation token) | active | active | PASS |
| Hold: the billing stops | on_hold | on_hold | PASS |
| Resume: active again, the next bill on the next posting day | yes | yes | PASS |
| the page follows the rights of the roles (a cashier without One-Time Billing) | yes | yes | PASS |
| the cashier finds account C with its share capital bills among the open items | yes | yes | PASS |
| ...two share capital bills listed at the cashier | 2 | 2 | PASS |
| the cashier collects the first bill (50.00, under the product's minimum deposit) | yes | yes | PASS |
| ...a CSD deposit on the Savings Account Transaction, the receipt as OR, waiting for the passbook | yes | yes | PASS |
| ...its journal entry: Dr the cashier's cash 50.00, Cr share capital 50.00, in the class of the page | [["11118","50.00","0.00",1],["3010","0.00","50.00",1]] | [["11118","50.00","0.00",1],["3010","0.00","50.00",1]] | PASS |
| ...the bill paid | paid | paid | PASS |
| the receipt prints FIXED SHARE CAPITAL 50.00 | yes | yes | PASS |
| Collection Summary (Summary): a Share capital column beside Bills paid | yes | yes | PASS |
| ...and the Report type drop-down (Summary, Detailed) | yes | yes | PASS |
| Collection Summary (Detailed): a column for each code name collected (WATER BILL, MEMBERSHIP FEE, FIXED SHARE CAPITAL) | yes | yes | PASS |
| ...the same total collected as the Summary | 2013.40 | 2013.40 | PASS |
| ...each receipt: its code names, penalty and compromise add up to its total (7 receipts, 3 code names) | 0 | 0 | PASS |
| Collection Corrections reverses the receipt of share capital only | yes | yes | PASS |
| ...ECSD takes the deposit back | ECSD/-50.00 | ECSD/-50.00 | PASS |
| ...the bill unpaid again | unpaid | unpaid | PASS |
| Delete: the billing deleted, its two unpaid bills cancelled | deleted/2 | deleted/2 | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |

## 7.6 Fixed Share Capital Billing: Term (days), Batch Share Capital Billing and its import; the import of One-Time Billing charges

| Check | Expected | Actual | Result |
|---|---|---|---|
| Add member with Term (days) 10: the bill of the 1st is due on the 11th | yes | yes | PASS |
| the cashier shows its due date | 2026-11-11 | 2026-11-11 | PASS |
| ...and collects it | yes | yes | PASS |
| the page offers Batch billing and Import, and the Term (days) field | yes | yes | PASS |
| Batch billing: both members on fixed billing, 25.00 monthly, term 5, one batch number | yes | yes | PASS |
| ...their bills of the 1st posted, due on the 6th | 2 | 2 | PASS |
| Import Batch Share Capital Billing opens (the rights of Fixed Share Capital Billing) | yes | yes | PASS |
| ...its CSV template (the columns and example lines) | yes | yes | PASS |
| ...and its Excel template | yes | yes | PASS |
| the file read for review: 3 lines, 2 with a problem (already on fixed billing; no such account), the leading zero lost in a spreadsheet found again | yes | yes | PASS |
| ...the lines in red removed: checked again, none with a problem | 0 | 0 | PASS |
| ...posting with a stale form is refused | yes | yes | PASS |
| ...posted: Rodman on fixed billing, 30.00 from the file, monthly from the form, no due date (OPEN), an import batch number | yes | yes | PASS |
| One-Time Billing offers Import Charges | yes | yes | PASS |
| Import One-Time Charges opens | yes | yes | PASS |
| the charges read for review: 5 lines, the water bill's code and a quantity above the stock in red | yes | yes | PASS |
| ...a blank amount takes the price of the bill code (MEMBERSHIP FEE 50.00), a material its price times the quantity | [75.25,50,1200] | [75.25,50,1200] | PASS |
| Issue bills: the three good lines issued | yes | yes | PASS |
| ...three one-time bills | 8 | 8 | PASS |
| ...each with its amount and due date (15 days, OPEN, 30 days) | yes | yes | PASS |
| ...each with its invoice on the ledger (balanced, for its amount) | 3 | 3 | PASS |
| ...the water meter out of stock (1) | 5.00 | 5.00 | PASS |
| ...posting the same review again is refused (the form was used) | yes | yes | PASS |
| no class chosen: said above the lines while checking, before anything is posted | yes | yes | PASS |
| a file larger than the server takes (8M) is said to be too large | yes | yes | PASS |
| the import pages follow the rights of their page (a cashier without One-Time Billing) | yes | yes | PASS |
| trial balance: debits = credits | 0.00 | 0.00 | PASS |

## 7.7 Members: the joint account and institution form in the format of the former program

| Check | Expected | Actual | Result |
|---|---|---|---|
| New member > Joint account opens on Joint/Institution Details and Membership Information | yes | yes | PASS |
| ...Family and beneficiaries, Financial and Loan and savings grouping are not its tabs | yes | yes | PASS |
| ...NEW ASSOCIATE - JOINT, the membership name, the representatives, the addresses and the contact list | yes | yes | PASS |
| ...and Get passbook number (000001) | yes | yes | PASS |
| Add member: a joint account, an applicant, its name the membership name, Associate - Joint | yes | yes | PASS |
| ...its representatives with their relation | ["Lorna Garcia (Wife)","Ben Garcia (Husband)","Mia Garcia (Daughter)"] | ["Lorna Garcia (Wife)","Ben Garcia (Husband)","Mia Garcia (Daughter)"] | PASS |
| ...the joint boxes saved (the individual's ignored): address, office, contact list, passbook from the series, alert | ["","Luna Street","Market Road","09181112222","000001","Two representatives sign each withdrawal",0] | ["","Luna Street","Market Road","09181112222","000001","Two representatives sign each withdrawal",0] | PASS |
| ...opened again on its own form: Member ID, APPLICANT, ASSOCIATE - JOINT | yes | yes | PASS |
| ...a passbook number kept on a save; the representatives changed | ["000001",1] | ["000001",1] | PASS |
| a refused save (an e-mail that is not valid) shows the joint boxes as typed | yes | yes | PASS |
| an institution with a member type of individuals is refused | yes | yes | PASS |
| New member > Institution: NEW ASSOCIATE - INSTITUTIONAL | yes | yes | PASS |
| an individual keeps Personal, Family and beneficiaries, Financial, Membership and Loan and savings grouping | yes | yes | PASS |

## 7.8 Water-service accounts with no first name (organizations, joint accounts, institutions): their name and account number on every list

| Check | Expected | Actual | Result |
|---|---|---|---|
| the former water database has accounts with no first name (182), one with a working tariff: CIRCLE OF HOPE OUTREACH MINISTRY INC. | yes | yes | PASS |
| ...a reading saved for it | yes | yes | PASS |
| Reading Entry, Recorded Readings: the name and the account number (not "—" and the ID) | ["CIRCLE OF HOPE OUTREACH MINISTRY INC.","11038"] | ["CIRCLE OF HOPE OUTREACH MINISTRY INC.","11038"] | PASS |
| Reading History: found by its account number, with its name | yes | yes | PASS |
| Collections (Cashier) search: its name | yes | yes | PASS |

## v46 Reading Entry: an unposted reading edited or deleted; a posted one locked

| Check | Expected | Actual | Result |
|---|---|---|---|
| Recorded Readings: the unposted reading can be edited and deleted | yes | yes | PASS |
| ...a posted reading is locked, with the reason | yes | yes | PASS |
| Edit: the unposted reading is saved again with the new present reading | 15 | 15 | PASS |
| Delete without the security token is refused (403) | 403 | 403 | PASS |
| Delete needs a reason of at least 5 characters | yes | yes | PASS |
| a posted reading cannot be deleted | yes | yes | PASS |
| a cashier (no Delete right on Reading Entry) is refused (403) | 403 | 403 | PASS |
| Delete: the unposted reading is deleted | yes | yes | PASS |
| ...with its reason in the audit log | yes | yes | PASS |

## v46 Regular - Joint, address sub-groups, incremental searching, pre-printed receipts

| Check | Expected | Actual | Result |
|---|---|---|---|
| New member > Joint account: the Type list offers Regular - Joint beside Associate - Joint | yes | yes | PASS |
| water/views/address_subgroups.php opens | yes | yes | PASS |
| water/views/receipt_layouts.php opens | yes | yes | PASS |
| water/views/receipt_layouts.php?kind=official opens | yes | yes | PASS |
| Water Settings links the two new pages | yes | yes | PASS |
| Address sub-groups: the list | yes | yes | PASS |
| ...added | yes | yes | PASS |
| ...the same name again is refused | yes | yes | PASS |
| ...renamed, on the water-service account that has it | E2E Purok 3efef B | E2E Purok 3efef B | PASS |
| ...deleting it while an account has it is refused | yes | yes | PASS |
| ...deleted once nothing has it | yes | yes | PASS |
| ...a change without the security token is refused (403) | 403 | 403 | PASS |
| Member search: one letter is enough, the last names that start with it first | yes | yes | PASS |
| Receipt Layout Setup: the layout of the water bill is saved | yes | yes | PASS |
| ...its test page prints the layout with sample entries | yes | yes | PASS |
| the water bill / collection receipt on its pre-printed form: only the entries, two copies | yes | yes | PASS |
| the official receipt on its pre-printed form | yes | yes | PASS |
| ...put back to the default layout |  |  | PASS |

## v47 Billing Adjustments: Edit opens the bill itself; the Individual Statement of Account

| Check | Expected | Actual | Result |
|---|---|---|---|
| an unpaid one-time bill to correct (55.00, due in 5 days) | yes | yes | PASS |
| Billing Adjustments: Edit opens the bill (a link to the bill), the small Edit Uncollected Bill box is gone | yes | yes | PASS |
| the editor shows the bill itself: its kind and invoice number, billed to, the bill code, the amount, the totals | yes | yes | PASS |
| ...its correctable fields in the bill (the amount, the due date), the class and the reason beside it | yes | yes | PASS |
| the preview without the form token is refused (403) | yes | yes | PASS |
| preview: 65.50 due in 10 days, term 10 days, up by 10.50; the bill is not changed | yes | yes | PASS |
| preview: a due date before the bill date is refused, with the reason | yes | yes | PASS |
| preview of a collected water bill is refused (a debit or credit memo instead) | yes | yes | PASS |
| a refused correction (a reason too short) goes back to the bill with the message and the amount typed | yes | yes | PASS |
| ...and nothing is saved | 55.00 | 55.00 | PASS |
| the correction is saved from the bill: back on Billing Adjustments with the message | yes | yes | PASS |
| the bill: 65.50, due in 10 days, term (days) 10, corrected once | yes | yes | PASS |
| its invoice is replaced by the correction "-R1" for 65.50 | yes | yes | PASS |
| a water bill opens with its meter reading, consumption and the tariff lines | yes | yes | PASS |
| preview: 3 m3 more is priced at the tariff, as the save prices it | yes | yes | PASS |
| preview: a present reading below the previous one is refused | yes | yes | PASS |
| an installment bill opens with its payment schedule | yes | yes | PASS |
| preview: 601.00 spread again over the three due dates | 200.34, 200.33, 200.33 | 200.34, 200.33, 200.33 | PASS |
| a cashier cannot open the bill editor | yes | yes | PASS |
| ...nor ask for a corrected bill | yes | yes | PASS |
| trial balance after the corrections: debits = credits | 0.00 | 0.00 | PASS |
| a second billing period after the first (Bill Period Management) | yes | yes | PASS |
| the account has unpaid water bills in two billing periods | 2 | 2 | PASS |
| Generate SOA / Post Billing: the Individual Statement of Account tab | yes | yes | PASS |
| ...?tab=individual opens on it | yes | yes | PASS |
| ...and from Reports (Generate Statement of Account), beside the statement of one billing period | yes | yes | PASS |
| the member or customer is found by the account number, with the unpaid invoices counted | yes | yes | PASS |
| ...and by the last name | yes | yes | PASS |
| every unpaid water bill is offered, whatever its billing period | water:3,water:8 | water:3,water:8 | PASS |
| ...with the balances of the bills on file (water, one-time, installment, recurring) | 7,246.00 | 7,246.00 | PASS |
| account E: the corrected one-time bill (65.50) and every unpaid amortization of its installment contracts | yes | yes | PASS |
| a statement dated before the bills lists none of them | 0 | 0 | PASS |
| a statement date that is not a date is refused | yes | yes | PASS |
| the statement lists only the invoice chosen of the account; one not unpaid and another account's bill are left off and named | yes | yes | PASS |
| ...its total is that invoice's balance; the cooperative, the account and the General Manager are on it | yes | yes | PASS |
| a statement with no invoice chosen is refused | yes | yes | PASS |
| the statement of one billing period still lists that period's water bill | 1 | 1 | PASS |
| an installment amortization not yet due is listed but not ticked at first; the others are | yes | yes | PASS |
| a member or customer with two accounts is one name with both accounts | yes | yes | PASS |
| ...all accounts: the invoices of both, each with its account | yes | yes | PASS |
| ...no invoice twice (a share capital bill on the first account only) | 2 | 2 | PASS |
| ...one account: its invoices only | yes | yes | PASS |
| ...another person's account is refused | yes | yes | PASS |
| a recurring bill corrected with the same due date keeps its due date and term | yes | yes | PASS |
| ...a new due date: the term counts from the end of its service period | 20 | 20 | PASS |
| ...a due date before the end of the service period is refused | yes | yes | PASS |
| a cashier (no Post Billing) cannot read the statement data | yes | yes | PASS |

## v49 Data Import Management and the Technical prerequisites step removed

| Check | Expected | Actual | Result |
|---|---|---|---|
| the Data Import Management page is gone | 404 | 404 | PASS |
| ...and its endpoints | 404 | 404 | PASS |
| Water Settings: no Data Import Management card | yes | yes | PASS |
| Page Access does not offer Data Import Management (its row stays in the database) | yes | yes | PASS |
| the menu has no Data Import Management | yes | yes | PASS |
| the set-up guide: no Technical prerequisites step, the administrator first, 6 steps (no Billing readiness since v50) | yes | yes | PASS |
| ...and nothing in it about storage/imports | yes | yes | PASS |

## v50 An open billing period is not a step of the set-up guide

| Check | Expected | Actual | Result |
|---|---|---|---|
| the guide has no Billing readiness step | yes | yes | PASS |
| with no open period, the guide on Reading Entry is complete (nothing blocks the page) | yes | yes | PASS |
| Reading Entry says itself that no period is open | yes | yes | PASS |
| a reading for a period that is not open is refused | yes | yes | PASS |

## v51 Billing List with its date filters empty, set and not a date (MySQL 8 error 1525)

| Check | Expected | Actual | Result |
|---|---|---|---|
| date filters empty: the register loads | yes | yes | PASS |
| ...also sent empty by the Apply button | yes | yes | PASS |
| a date range with no bill: none listed, no error | yes | yes | PASS |
| a date range around every bill: all listed | yes | yes | PASS |
| a "date" that is not one is ignored, not an error | yes | yes | PASS |

## v52 Price level on the member's Customer Defaults (Member Center)

| Check | Expected | Actual | Result |
|---|---|---|---|
| the member's Customer Defaults tab has a Price level with the cooperative's levels | yes | yes | PASS |
| ...in the form the member is saved with | yes | yes | PASS |
| saved from the member form on the member's own customer record | 11 | 11 | PASS |
| ...with a line in the audit trail | yes | yes | PASS |
| an invoice, sales receipt or order for the member asks for the price at the member's level (15% off) | 33991.50 | 33991.50 | PASS |
| opened again: the level is shown chosen | yes | yes | PASS |
| cleared from the form: the member pays the members' level of the line of business, else the standard price | yes | yes | PASS |

## v53 The accounting update (G-SMaRT Accounting versions 26 to 34)

| Check | Expected | Actual | Result |
|---|---|---|---|
| the fourteen new reports open for the administrator | yes | yes | PASS |
| the Report Center: the search box, the Financial Reports card, and the cooperative and water cards still there | yes | yes | PASS |
| Statement of Cash Flows by Class: the sections follow the account types, and it ties | yes | yes | PASS |
| the water Collection Summary keeps its date-and-time boxes | yes | yes | PASS |
| Ending Inventory - POS Cashier: a date box and a time box each | yes | yes | PASS |
| Make Deposits: the card and e-wallet balances with their Details | yes | yes | PASS |
| ...the Details of Card Clearing open | yes | yes | PASS |
| Vendors: Receive Items List | yes | yes | PASS |
| Vendors: Receive Items and Enter Bills List | yes | yes | PASS |
| an item group sold to a member: the group price at the member's level (1,000.00 less 20%) | 800.00 | 800.00 | PASS |
| Company, Security & Closing Date: the item group and no-cost settings | yes | yes | PASS |

## v54 Loan Due on the water cashier page, the Member type filter, Sales by Customer Detail Group by Customer/Member Type

| Check | Expected | Actual | Result |
|---|---|---|---|
| the cashier page has the Loan Due panel (shown once an account is chosen) | yes | yes | PASS |
| the cashier gets the loans due of a member's water-service account | yes | yes | PASS |
| a date that is no date is today | 2026-11-07 | 2026-11-07 | PASS |
| a non-member's account: no member, no loans (the panel stays hidden) | yes | yes | PASS |
| not signed in: refused | yes | yes | PASS |
| Sales by Customer Detail: the Member type filter (All members, the member types, Non-members) | yes | yes | PASS |
| ...run for All members: named in the heading | yes | yes | PASS |
| Sales by Customer Detail Group by Customer/Member Type opens with its type groups | yes | yes | PASS |
| ...listed in the Report Center | yes | yes | PASS |

## 7.4c Period close

| Check | Expected | Actual | Result |
|---|---|---|---|
| the billing period is closed | closed | closed | PASS |
| a reading in a closed period is refused | yes | yes | PASS |

## Ledger integrity at the end

| Check | Expected | Actual | Result |
|---|---|---|---|
| trial balance: debits = credits | 0.00 | 0.00 | PASS |
| every document balances | 0 | 0 | PASS |
| no ledger document belongs to two water events | 0 | 0 | PASS |
| A/R control account = open water invoices (subledger) | 10,538.45 | 10,538.45 | PASS |
| water records open balance = A/R subledger for the accounts used | 10,538.45 | 10,538.45 | PASS |
