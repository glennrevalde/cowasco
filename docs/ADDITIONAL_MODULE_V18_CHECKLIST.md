# Additional module for the Water + Coop systems (v18): requirements checklist

This maps the request of `ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v18.xlsx` (version 52) to the code that implements it
and the tests that exercised it. Its only sheet, **Sheet9**, holds one note (M29) and two screenshots joined to it by
two arrows:

| Image | Where | What it shows |
|---|---|---|
| 1 (A2) | `modules/customers/center.php` (`cust.center&a=edit&id=1`) | the customer form "Edit customer: ABC Trading Corp." with its **Price level** box (an arrow from it to the note) |
| 2 (A27) | `modules/coop/member.php` (`coop.member&id=9`, Aquino, Rosa) | the member's **Profile > Customer Defaults** tab: Payment terms, Credit limit, Tax code, Preferred payment method, Customer type, Sales representative, Billing group, Taxes this customer withholds, and an empty place after them (an arrow from the note to it) |

Status: **Done** means built, reachable from the menu, and run in the tests and in a browser on the sample database.

Version 52 has no migration (`UPGRADE_v52.txt`): the price level is the column the customer form already uses
(`customers.price_level_id`) on the member's own customer record.

## 1. Request

| # | Request | What was done | Where | Evidence | Status |
|---|---|---|---|---|---|
| 1 | "Claude, please put price level on the member center, to determine what price the member will be charge upon transacting with inventories" (M29; images 1 and 2) | Member Center > a member > **Profile > Customer Defaults** has a **Price level**, in the empty place after Taxes this customer withholds, with the same levels as the customer form (Inventory > Price levels, the active ones). It is kept on the member's own customer record (`customers.price_level_id`), the record the member buys on, so the prices follow it wherever the cooperative sells items to the member: invoices, sales receipts, sales orders, estimates and statement charges (the item's price asked when the member is chosen, `Lookup::priceFor`, and checked again on saving), batch invoices (with each customer's price level), and the POS (the member as the sale's customer: "Price level: ..." under the customer and the items repriced). A level with a price of its own for an item gives that price; otherwise its percentage applies to the item's sales price. Left blank, the member pays the members' price level of the line of business or of the cooperative when one is set (the blank choice then reads "Members' price level"), else "Standard". A new choice must be an active level; a level the member already has, made inactive since, is shown "(inactive)" and kept when the member is saved again. The change is in the audit trail with the other Customer Defaults. The tab's text says the price level sets the member's prices of the items; the manual (Member Information > One name: Water Defaults, Customer Defaults and Vendor Defaults) explains the field. | `modules/coop/member.php`, `app/MemberDefaults.php` (`CUSTOMER_IDS`, `saveCustomer`, `priceLevelOptions`, `memberPriceLabel`), `views/manual/chapters/13-cooperative.html`, `views/manual/search.json` | harness "Version 52: a member's price level on Customer Defaults decides the price of the items the member buys" (19 checks: saved on the member's customer record and audited, 10% off, an item's own price, the member's level before the members' level of the line of business, cleared back to it, standard without any, an inactive level kept but not chosen anew, a level that does not exist refused, saved with the credit limit); HTTP e2e section v52 (the field on the member's page with the levels, the whole member form saved as a browser sends it, the price an invoice asks for 15% off, shown chosen when opened again, cleared); browser at 1366 and 390 px: Santos, Maria given "Members 10% off" and saved, then at the POS the laptop 39,990.00 sold to her at 35,991.00 with "Price level: Members 10% off" | Done |
| — | "Make sure to make the UI of ALL PAGES very modern and professional AND MOBILE RESPONSIVE" | The field is one of the tab's boxes (the program's searchable list), three to a row on a computer and one per line on a phone. | as above | browser at 1366 and 390 px (no sideways scroll, no console error) | Done |

## 2. Decisions

| # | Decision | Reason |
|---|---|---|
| D1 | The price level is kept on the member's customer record, not on the member. | The member buys on that record (one name, version 31): every sales document and the POS already price by the customer's level, so the member is charged the same way as any customer, with no second rule to keep. The customer form of that record shows the same level. |
| D2 | Water bills are not repriced by it: a material on a one-time, installment or recurring bill keeps the price of its bill code. | Water bills have their own tariff, discounts and privileges per bill code; asked ("Should the member's price level also change what they're charged on those water material bills?"), no preference was given, so the cooperative's sales and the POS take it and the water bills stay as they were. |
| D3 | Blank means the members' price level of the line of business (or of the cooperative), else the standard prices, and the blank choice says which. | That is what a member without a level of its own already pays (`BusinessUnits::priceLevelFor`); the field only adds the member's own level in front of it. |
| D4 | No migration. | The column exists (`customers.price_level_id`); MySQL 5.5 needs nothing. |
