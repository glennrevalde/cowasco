<?php
declare(strict_types=1);

/**
 * v37 (G-SMaRT Accounting; version 60 of this system): "Add new" in the drop-downs of the transactions (a shortcut, as
 * QuickBooks' <Add New>). It adds customers, suppliers, accounts and items: a cooperative member is added on the Member
 * Center as before, never from a drop-down.
 *
 * A customer, supplier, account or item that is not on the list yet can be added from the drop-down itself: the last
 * lines of the list say "Add new customer" (or supplier, account, item), and choosing one opens the usual form of that
 * list in a window over the transaction. Once it is saved there, the window closes and the new name is put in the box
 * (and in the other boxes of the same kind on the page), so the transaction being entered is never left.
 *
 * Only the users who may create customers (Customer Center), suppliers (Vendor Center), accounts (Chart of Accounts,
 * with the "change the Chart of Accounts" permission) or items (Item List) see the shortcut for that kind; the form in
 * the window checks the same rights again when it is saved, exactly as on its own page.
 *
 * The window is the ordinary page of the list with ?popup=1: drawn without the menu and the top bar, without
 * "Save & New", and its Cancel closes the window. When it saves, QuickAdd::finish() answers with a short page that
 * hands the new record to the transaction (window.postMessage, same site only) instead of opening the record.
 */
final class QuickAdd
{
    /** The item types, in the order of the Item List's "New item" menu */
    public const ITEM_TYPES = [
        'service' => 'Service', 'inventory' => 'Inventory part', 'non_inventory' => 'Non-inventory part', 'assembly' => 'Inventory assembly',
        'other_charge' => 'Other charge', 'subtotal' => 'Subtotal', 'group' => 'Group', 'discount' => 'Discount', 'payment' => 'Payment',
    ];

    /** The item types that can be bought (the purchase lines of POs, item receipts, bills and checks) */
    public const PURCHASE_TYPES = ['inventory', 'non_inventory', 'service', 'other_charge', 'assembly'];

    /** Is this page open in the "Add new" window? */
    public static function popup(): bool
    {
        return ($_GET['popup'] ?? '') === '1';
    }

    /**
     * The kinds this user may add from a drop-down, with the address of the form: kind => url.
     * Nothing inside the window itself (no window over a window).
     */
    public static function allowed(): array
    {
        static $out = null;
        if ($out !== null) {
            return $out;
        }
        $out = [];
        if (self::popup() || !Auth::user()) {
            return $out;
        }
        if (Access::can('cust.center', 'create')) {
            $out['customer'] = url('cust.center', ['a' => 'new', 'popup' => 1]);
        }
        if (Access::can('vend.center', 'create')) {
            $out['vendor'] = url('vend.center', ['a' => 'new', 'popup' => 1]);
        }
        if (Access::can('coa.accounts', 'create') && Access::special('coa_changes')) {
            $out['account'] = url('coa.accounts', ['a' => 'new', 'popup' => 1]);
        }
        if (Access::can('inv.items', 'create')) {
            $out['item'] = url('inv.items', ['a' => 'new', 'popup' => 1]);
        }
        return $out;
    }

    /** The settings the page script needs, on <body data-quick-add="..."> ('' when the user may add nothing) */
    public static function bodyAttr(): string
    {
        $kinds = self::allowed();
        if (!$kinds) {
            return '';
        }
        $from = (string)($GLOBALS['CURRENT_PAGE'] ?? '');
        // v38: the type of page: on a transaction page a box in a form that only chooses (the Vendor of Receive Items
        // and Enter Bills, which reloads the page for that vendor) gets the "Add new" lines too
        $cfg = ['kinds' => $kinds, 'types' => self::ITEM_TYPES, 'from' => $from, 'ptype' => (string)(Access::pageDef($from)['type'] ?? '')];
        return ' data-quick-add="' . h(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
    }

    /**
     * The attributes that give a drop-down its "Add new" lines: kind customer, vendor, name (customers and suppliers
     * by code: c12, v5), account or item; $types narrows an account box (Bank, Credit Card ...) or an item box
     * (inventory, assembly ...) to what it may hold.
     */
    public static function attr(string $kind, array $types = []): string
    {
        $allowed = self::allowed();
        $ok = $kind === 'name' ? (isset($allowed['customer']) || isset($allowed['vendor'])) : isset($allowed[$kind]);
        if (!$ok) {
            return '';
        }
        return ' data-add="' . h($kind) . '"' . ($types ? ' data-add-types="' . h(implode(',', $types)) . '"' : '');
    }

    /**
     * select_field() asks this for every drop-down it draws: a full list of customers or suppliers, a list of names by
     * code, or a list of accounts gets its "Add new" lines. Other lists (sites, terms, a parent account ...) do not.
     */
    public static function infer(string $name, array $opts): string
    {
        if (!$opts || !self::allowed()) {
            return '';
        }
        $base = preg_match('/\[([^\[\]]+)\]$/', $name, $m) ? $m[1] : $name;
        // the customer boxes of this system list the customers as Lookup::customerOptions() does (a member by its
        // member type); either list gets the lines
        if (in_array($base, ['customer_id', 'pay_customer_id'], true)
            && ($opts === Lookup::customers() || $opts === Lookup::customers(false) || $opts === Lookup::customerOptions() || $opts === Lookup::customerOptions(false))) {
            return self::attr('customer');
        }
        if (in_array($base, ['vendor_id', 'preferred_vendor_id'], true) && $opts === Lookup::vendors()) {
            return self::attr('vendor');
        }
        $keys = array_keys($opts);
        // a name box: customers, suppliers and other names by code (and, in this system, the members as they are already
        // listed, m3); "Add new" adds a customer or a supplier, never a member
        if (in_array($base, ['payee', 'name', 'paid_to'], true) && is_string($keys[0])) {
            foreach ($keys as $k) {
                if (!preg_match('/^[cvom]\d+$/', (string)$k)) {
                    return '';
                }
            }
            return self::attr('name');
        }
        if (preg_match('/account\d?_id$/', $base)) {
            $types = self::accountTypes($keys);
            return $types === null ? '' : self::attr('account', $types);
        }
        return '';
    }

    /**
     * The account types a list of accounts holds: [] when it holds every kind (any new account belongs in it),
     * the types when it is narrowed (a bank account box), null when the keys are not accounts.
     */
    public static function accountTypes(array $ids): ?array
    {
        static $typeOf = null;
        $typeOf ??= DB::pairs('SELECT id, account_type FROM accounts');
        $types = [];
        foreach ($ids as $id) {
            if (!isset($typeOf[(int)$id]) || (string)(int)$id !== (string)$id) {
                return null;
            }
            $types[$typeOf[(int)$id]] = true;
        }
        $all = array_unique(array_values($typeOf));
        return count($types) >= count($all) || count($types) >= 8 ? [] : array_keys($types);
    }

    /**
     * Called by the form of the list once the new record is saved. In the window it answers with the record for the
     * transaction and ends the request; on the list's own page it does nothing.
     */
    public static function finish(string $kind, int $id): void
    {
        if (!self::popup()) {
            return;
        }
        take_flash();   // "Customer saved." is not shown on the transaction
        $rec = self::record($kind, $id);
        $json = json_encode(['gsQuickAdd' => $rec], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Saved</title></head><body style="font:14px system-ui,sans-serif;padding:24px;color:#334155">'
            . '<p>' . h(($rec['label'] ?? '') !== '' ? $rec['label'] . ' was saved.' : 'Saved.') . '</p>'
            . '<script>(function () { var d = ' . $json . '; if (window.parent && window.parent !== window) { window.parent.postMessage(d, window.location.origin); } })();</script>'
            . '</body></html>';
        exit;
    }

    /** What the transaction needs to know about a new record */
    public static function record(string $kind, int $id): array
    {
        switch ($kind) {
            case 'customer':
                $r = DB::row('SELECT c.id, c.name, p.name AS parent FROM customers c LEFT JOIN customers p ON p.id = c.parent_id WHERE c.id = :id', ['id' => $id]);
                $label = $r ? ($r['parent'] ? $r['parent'] . ':' . $r['name'] : $r['name']) : '';
                return ['kind' => 'customer', 'id' => $id, 'label' => $label, 'name' => $label];
            case 'vendor':
                $name = (string)DB::value('SELECT name FROM vendors WHERE id = :id', ['id' => $id]);
                return ['kind' => 'vendor', 'id' => $id, 'label' => $name, 'name' => $name];
            case 'account':
                $r = DB::row('SELECT code, name, account_type FROM accounts WHERE id = :id', ['id' => $id]) ?: ['code' => '', 'name' => '', 'account_type' => ''];
                return ['kind' => 'account', 'id' => $id, 'label' => $r['code'] . ' - ' . $r['name'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['account_type']];
            case 'item':
                $r = DB::row('SELECT * FROM items WHERE id = :id', ['id' => $id]);
                if (!$r) {
                    return ['kind' => 'item', 'id' => $id, 'label' => ''];
                }
                $from = (string)($_GET['from'] ?? '');
                $from = $from !== '' && Access::pageDef($from) ? $from : null;
                $price = (float)$r['sales_price'];
                $tax = $r['tax_code_id'] ? (int)$r['tax_code_id'] : null;
                if ($r['item_type'] === 'group' && ItemGroups::ready()) {
                    $price = ItemGroups::price($id, null);
                    $tax = ItemGroups::taxCode(['id' => $id, 'tax_code_id' => $r['tax_code_id']]);
                }
                $grid = ['id' => $id, 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['item_type'], 'unit' => $r['unit'],
                    'price' => $price, 'cost' => (float)$r['cost'], 'pct' => (float)$r['discount_pct'], 'tax' => $tax ? (int)$tax : null];
                return ['kind' => 'item', 'id' => $id, 'label' => item_label($r['code'], $r['name'], $from), 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['item_type'],
                    'grid' => [
                        'sales' => $grid + ['desc' => $r['description'] ?: $r['name']],
                        'purchase' => $grid + ['desc' => ($r['purchase_description'] ?? '') ?: ($r['description'] ?: $r['name'])],
                    ]];
        }
        return ['kind' => $kind, 'id' => $id, 'label' => ''];
    }

    /** The name typed in the drop-down before "Add new" was chosen, to start the form with */
    public static function prefill(): string
    {
        return self::popup() ? mb_substr(trim((string)($_GET['prefill'] ?? '')), 0, 120) : '';
    }

    /** The account types the window may offer (the box it was opened from may hold only some) */
    public static function accountTypesWanted(array $all): array
    {
        if (!self::popup()) {
            return $all;
        }
        $want = array_values(array_intersect($all, array_map('trim', explode(',', (string)($_GET['types'] ?? '')))));
        return $want ?: $all;
    }
}
