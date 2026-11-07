<?php
declare(strict_types=1);

/** Cached option lists for forms. */
final class Lookup
{
    private static array $c = [];

    public static function customers(bool $withJobs = true): array
    {
        return self::$c['cust' . (int)$withJobs] ??= DB::pairs(
            "SELECT c.id, CASE WHEN p.id IS NULL THEN c.name ELSE CONCAT(p.name, ':', c.name) END AS label
               FROM customers c LEFT JOIN customers p ON p.id = c.parent_id
              WHERE c.is_active = 1" . ($withJobs ? '' : ' AND c.parent_id IS NULL') . '
              ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name'
        );
    }

    /**
     * Customers for a customer box on a form (Customer:Job, the POS, payments, statements): cooperative members are
     * on the list as customers and are described by their member type, "Santos, Maria (Regular Member)", so the
     * cooperative recognises its members on every sale and expense line.
     */
    public static function customerOptions(bool $withJobs = true): array
    {
        $key = 'custopt' . (int)$withJobs;
        if (!isset(self::$c[$key])) {
            try {
                Members::ensureCustomerRoles();
            } catch (Throwable $e) {
                // the cooperative or party tables are not installed: plain customers
            }
            $kinds = class_exists('Members') ? (Members::nameKinds()['c'] ?? []) : [];
            $out = [];
            foreach (self::customers($withJobs) as $id => $label) {
                $out[$id] = isset($kinds[$id]) ? $label . ' (' . $kinds[$id] . ')' : $label;
            }
            self::$c[$key] = $out;
        }
        return self::$c[$key];
    }

    /** Forgets the cached lists (after a customer, supplier or name was added in the same request) */
    public static function reset(): void
    {
        self::$c = [];
    }

    /**
     * Suppliers for a vendor box (bills, purchase orders, Pay Bills ...): cooperative members are on the list on their
     * own supplier record and are described by their member type, "Santos, Maria (Regular Member)".
     */
    public static function vendors(): array
    {
        if (!isset(self::$c['vend'])) {
            try {
                Members::ensureVendorRoles();
            } catch (Throwable $e) {
                // the cooperative or party tables are not installed: plain suppliers
            }
            $kinds = class_exists('Members') ? (Members::nameKinds()['v'] ?? []) : [];
            $out = [];
            foreach (self::vendorNames() as $id => $name) {
                $out[$id] = isset($kinds[$id]) ? $name . ' (' . $kinds[$id] . ')' : $name;
            }
            self::$c['vend'] = $out;
        }
        return self::$c['vend'];
    }

    /** Active suppliers by name only */
    public static function vendorNames(): array
    {
        return self::$c['vendn'] ??= DB::pairs('SELECT id, name FROM vendors WHERE is_active = 1 ORDER BY name');
    }

    public static function sites(bool $restrict = true): array
    {
        $key = 'sites' . (int)$restrict;
        if (!isset(self::$c[$key])) {
            $rows = DB::pairs('SELECT id, name FROM sites WHERE is_active = 1 ORDER BY id');
            if ($restrict) {
                $ids = Access::siteIds();
                if ($ids !== null) {
                    $rows = array_filter($rows, fn($k) => in_array((int)$k, $ids, true), ARRAY_FILTER_USE_KEY);
                }
            }
            self::$c[$key] = $rows;
        }
        return self::$c[$key];
    }

    public static function terms(): array
    {
        return self::$c['terms'] ??= DB::pairs('SELECT id, name FROM terms WHERE is_active = 1 ORDER BY net_days, name');
    }

    public static function reps(): array
    {
        return self::$c['reps'] ??= DB::pairs('SELECT id, name FROM sales_reps WHERE is_active = 1 ORDER BY name');
    }

    public static function taxCodes(): array
    {
        return self::$c['tax'] ??= DB::pairs("SELECT id, CONCAT(code, ' (', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM rate)), '%)') FROM tax_codes WHERE is_active = 1 ORDER BY id");
    }

    public static function taxRates(): array
    {
        return self::$c['taxr'] ??= array_map('floatval', DB::pairs('SELECT id, rate FROM tax_codes'));
    }

    public static function paymentMethods(): array
    {
        return self::$c['pm'] ??= DB::pairs('SELECT id, name FROM payment_methods WHERE is_active = 1 ORDER BY id');
    }

    public static function priceLevels(): array
    {
        return self::$c['pl'] ??= DB::pairs('SELECT id, name FROM price_levels WHERE is_active = 1 ORDER BY name');
    }

    public static function customerTypes(): array
    {
        return self::$c['ct'] ??= DB::pairs('SELECT id, name FROM customer_types ORDER BY name');
    }

    public static function categories(): array
    {
        return self::$c['cat'] ??= DB::pairs('SELECT id, name FROM item_categories ORDER BY name');
    }

    public static function users(): array
    {
        return self::$c['users'] ??= DB::pairs("SELECT id, CONCAT(full_name, ' (', username, ')') FROM users ORDER BY full_name");
    }

    public static function roles(): array
    {
        return self::$c['roles'] ??= DB::pairs('SELECT id, name FROM roles ORDER BY name');
    }

    /**
     * Items for a drop-down, labelled the way the "Searching for items" setting asks for
     * (item name/code first, or display name first). Typing any part of either one finds the item.
     */
    public static function itemPairs(string $where = 'is_active = 1', array $params = []): array
    {
        $rows = DB::all('SELECT id, code, name FROM items WHERE ' . $where . ' ORDER BY code', $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = item_label($r['code'], $r['name']);
        }
        return self::sortLabels($out);
    }

    /** Fixed assets for a drop-down: the description first, the asset number at the end. */
    public static function assets(bool $activeOnly = false): array
    {
        $key = 'fa' . (int)$activeOnly;
        if (!isset(self::$c[$key])) {
            $rows = DB::all('SELECT id, asset_no, description FROM fixed_assets' . ($activeOnly ? " WHERE status <> 'disposed'" : '') . ' ORDER BY asset_no');
            $out = [];
            foreach ($rows as $r) {
                $out[(int)$r['id']] = $r['description'] . ' (' . $r['asset_no'] . ')';
            }
            self::$c[$key] = self::sortLabels($out);
        }
        return self::$c[$key];
    }

    /** Alphabetical by label, so typing a name walks straight down the list */
    public static function sortLabels(array $pairs): array
    {
        uasort($pairs, fn($a, $b) => strcasecmp((string)$a, (string)$b));
        return $pairs;
    }

    /** Items for line grids (JSON-ready). $mode = sales | purchase | all */
    public static function itemsForGrid(string $mode = 'sales', ?int $priceLevelId = null): array
    {
        $types = $mode === 'purchase'
            ? "('inventory','non_inventory','service','other_charge','assembly')"
            : "('service','inventory','non_inventory','assembly','other_charge','discount','subtotal','group','payment')";
        $rows = DB::all(
            "SELECT i.id, i.code, i.name, i.item_type AS type, i.description, i.purchase_description, i.unit,
                    i.sales_price, i.cost, i.discount_pct, i.tax_code_id, i.expense_account_id, i.preferred_vendor_id
               FROM items i WHERE i.is_active = 1 AND i.item_type IN $types ORDER BY i.code"
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'group' && $mode !== 'purchase') {
                // an item group is sold at its group price (or its consolidated price), with the tax its items mostly have
                $r['sales_price'] = ItemGroups::price((int)$r['id'], null);
                $r['tax_code_id'] = ItemGroups::taxCode(['id' => (int)$r['id'], 'tax_code_id' => $r['tax_code_id']]);
            }
            $out[(int)$r['id']] = [
                'id'    => (int)$r['id'],
                'code'  => $r['code'],
                'name'  => $r['name'],
                'type'  => $r['type'],
                'desc'  => $mode === 'purchase' ? ($r['purchase_description'] ?: $r['description'] ?: $r['name']) : ($r['description'] ?: $r['name']),
                'unit'  => $r['unit'],
                'price' => (float)$r['sales_price'],
                'cost'  => (float)$r['cost'],
                'pct'   => (float)$r['discount_pct'],
                'tax'   => $r['tax_code_id'] ? (int)$r['tax_code_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * The price of an item for a customer: the price level that applies (the customer's own, else the member or
     * nonmember level of the line of business, else the general member / nonmember level), when the level is active
     * and the document date is within its dates. $unitId: the line of business (default: the form's, then sales).
     * An item group's price is its group price (or its items' prices) with the same level: ItemGroups::price.
     */
    public static function priceFor(int $itemId, ?int $customerId, ?int $unitId = null, ?string $date = null): float
    {
        $item = DB::row('SELECT sales_price, item_type FROM items WHERE id = :i', ['i' => $itemId]);
        if (($item['item_type'] ?? '') === 'group') {
            return ItemGroups::price($itemId, $customerId);
        }
        return self::levelPrice($itemId, (float)($item['sales_price'] ?? 0), $customerId, $unitId, $date);
    }

    /** A price as the price level that applies makes it: the level's custom price for the item, or its percentage */
    public static function levelPrice(int $itemId, float $price, ?int $customerId, ?int $unitId = null, ?string $date = null): float
    {
        $pl = self::priceLevelFor($customerId, $unitId, $date);
        if (!$pl) {
            return $price;
        }
        $custom = DB::value('SELECT custom_price FROM price_level_items WHERE price_level_id = :p AND item_id = :i', ['p' => $pl, 'i' => $itemId]);
        if ($custom !== null) {
            return (float)$custom;
        }
        $adj = (float)DB::value('SELECT adjust_pct FROM price_levels WHERE id = :p', ['p' => $pl]);
        return round($price * (1 + $adj / 100), 2);
    }

    /** The price level that applies, or null (standard prices) */
    public static function priceLevelFor(?int $customerId, ?int $unitId = null, ?string $date = null): ?int
    {
        if (BusinessUnits::ready()) {
            if ($unitId === null && is_post() && !empty($_POST['business_unit_id'])) {
                $unitId = (int)$_POST['business_unit_id'];
            }
            $unitId ??= BusinessUnits::defaultFor('invoice');
            $pl = BusinessUnits::priceLevelFor($customerId, $unitId);
        } else {
            $pl = $customerId ? (int)DB::value('SELECT price_level_id FROM customers WHERE id = :c', ['c' => $customerId]) ?: null : null;
        }
        if (!$pl) {
            return null;
        }
        $date ??= (is_post() && valid_date((string)($_POST['txn_date'] ?? ''))) ? (string)$_POST['txn_date'] : today();
        return self::levelApplies((int)$pl, $date) ? (int)$pl : null;
    }

    /** An active level whose dates (when it has them) include the date */
    public static function levelApplies(int $levelId, string $date): bool
    {
        try {
            $l = DB::row('SELECT is_active, valid_from, valid_to FROM price_levels WHERE id = :p', ['p' => $levelId]);
        } catch (Throwable $e) {
            $l = DB::row('SELECT is_active, NULL AS valid_from, NULL AS valid_to FROM price_levels WHERE id = :p', ['p' => $levelId]);
        }
        return $l && (int)$l['is_active'] === 1 && (!$l['valid_from'] || $l['valid_from'] <= $date) && (!$l['valid_to'] || $l['valid_to'] >= $date);
    }
}
