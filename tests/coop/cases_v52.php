<?php
declare(strict_types=1);

/**
 * The request of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v18.xlsx (version 52): "please put price level on the member
 * center, to determine what price the member will be charge upon transacting with inventories". Member Center >
 * member > Profile > Customer Defaults has a Price level, kept on the member's own customer record as on the customer
 * form; the items' prices of invoices, sales receipts, sales orders, batch invoices and the POS follow it (the
 * customer's own level first, else the members' level of the line of business). The page is checked over HTTP
 * (tests/water/e2e_http.php, section v52).
 */

test('Version 52: a member\'s price level on Customer Defaults decides the price of the items the member buys', function () {
    $mid = newMember('Villanueva', 'Corazon', ['mobile' => '09179876543']);
    $m = Coop::member($mid);
    $cid = (int)MemberDefaults::customerOf($m)['id'];
    check(in_array('price_level_id', MemberDefaults::CUSTOMER_IDS, true), 'Customer Defaults keeps the price level');

    // an item and two price levels: 10% off, and one with a price of its own for the item
    $item = (int)DB::value("SELECT id FROM items WHERE item_type = 'inventory' AND is_active = 1 AND sales_price > 0 ORDER BY id LIMIT 1");
    if (!$item) {
        $item = DB::insert('items', ['item_type' => 'inventory', 'code' => 'HOSE-V52', 'name' => 'Garden hose 10 m', 'description' => 'Garden hose 10 m', 'unit' => 'pc',
            'sales_price' => 500, 'cost' => 300, 'is_active' => 1]);
    }
    $std = (float)DB::value('SELECT sales_price FROM items WHERE id = :i', ['i' => $item]);
    $less10 = DB::insert('price_levels', ['name' => 'Members less 10% (v52)', 'adjust_pct' => -10, 'is_active' => 1]);
    $special = DB::insert('price_levels', ['name' => 'Members special (v52)', 'adjust_pct' => 0, 'is_active' => 1]);
    DB::insert('price_level_items', ['price_level_id' => $special, 'item_id' => $item, 'custom_price' => 123.45]);
    $old = DB::insert('price_levels', ['name' => 'Old level (v52)', 'adjust_pct' => -5, 'is_active' => 0]);
    Lookup::reset();

    $units = BusinessUnits::ready() ? DB::all('SELECT id, member_price_level_id FROM business_units') : [];
    $general = Settings::get('member_price_level_id', '');
    try {
        if ($units) {
            DB::run('UPDATE business_units SET member_price_level_id = NULL');
            BusinessUnits::reset();
        }
        Settings::set('member_price_level_id', '');
        eqm(Lookup::priceFor($item, $cid), (string)$std, 'no level anywhere: the member pays the standard price');
        eq(MemberDefaults::memberPriceLabel(), 'Standard', '...and the blank choice of the field says so');

        // the field
        DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => $less10], '', 'coop.member'));
        eq((int)DB::value('SELECT price_level_id FROM customers WHERE id = :c', ['c' => $cid]), $less10, 'saved on the member\'s customer record');
        check((bool)DB::value("SELECT 1 FROM audit_trail WHERE record_type = 'customer' AND record_id = :c AND action = 'edit' AND changes LIKE '%price_level_id%' LIMIT 1", ['c' => $cid]), 'the change is in the audit trail');
        eqm(Lookup::priceFor($item, $cid), (string)round($std * 0.9, 2), 'invoices, receipts and orders: the member\'s level (10% off)');
        eq(Lookup::priceLevelFor($cid), $less10, 'the level that applies is the member\'s');
        DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => $special], '', 'coop.member'));
        eqm(Lookup::priceFor($item, $cid), '123.45', 'a level with a price of its own for the item: that price');

        // the members' level of the line of business when the member has none, and the member's own level first
        if ($units) {
            DB::run('UPDATE business_units SET member_price_level_id = :p', ['p' => $less10]);
            BusinessUnits::reset();
            eq(MemberDefaults::memberPriceLabel(), "Members' price level", 'with a members\' level set, the blank choice names it');
            eqm(Lookup::priceFor($item, $cid), '123.45', 'the member\'s own level comes before the members\' level of the line of business');
            DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => null], '', 'coop.member'));
            check(DB::value('SELECT price_level_id FROM customers WHERE id = :c', ['c' => $cid]) === null, 'cleared');
            eqm(Lookup::priceFor($item, $cid), (string)round($std * 0.9, 2), 'cleared: the members\' level of the line of business');
        }

        // a level made inactive: kept when the tab is saved again, not chosen anew
        DB::run('UPDATE customers SET price_level_id = :p WHERE id = :c', ['p' => $old, 'c' => $cid]);
        $opts = MemberDefaults::priceLevelOptions($old);
        eq($opts[$old] ?? null, 'Old level (v52) (inactive)', 'the member\'s inactive level is offered, marked inactive');
        check(!isset(MemberDefaults::priceLevelOptions()[$old]), 'and not offered to anyone else');
        DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => $old], '', 'coop.member'));
        eq((int)DB::value('SELECT price_level_id FROM customers WHERE id = :c', ['c' => $cid]), $old, 'saving the tab keeps it');
        eqm(Lookup::priceFor($item, $cid), (string)$std, 'an inactive level gives the standard price, as on invoices');
        DB::run('UPDATE customers SET price_level_id = NULL WHERE id = :c', ['c' => $cid]);
        refuses(fn() => DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => $old], '', 'coop.member')),
            'active price level', 'an inactive level is not chosen anew');
        refuses(fn() => DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => 999999], '', 'coop.member')),
            'active price level', 'nor a level that does not exist');

        // the other defaults are untouched by the price level
        DB::tx(fn() => MemberDefaults::saveCustomer($mid, ['price_level_id' => $less10, 'credit_limit' => 2500], 'Board approved', 'coop.member'));
        $c = DB::row('SELECT price_level_id, credit_limit FROM customers WHERE id = :c', ['c' => $cid]);
        eq([(int)$c['price_level_id'], number_format((float)$c['credit_limit'], 2, '.', '')], [$less10, '2500.00'], 'price level and credit limit saved together');
    } finally {
        foreach ($units as $u) {
            DB::run('UPDATE business_units SET member_price_level_id = :p WHERE id = :id', ['p' => $u['member_price_level_id'], 'id' => $u['id']]);
        }
        Settings::set('member_price_level_id', $general);
        BusinessUnits::reset();
        Lookup::reset();
    }
});
