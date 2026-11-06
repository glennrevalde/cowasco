<?php
declare(strict_types=1);

/**
 * Perpetual inventory with weighted-average costing.
 * Quantities are tracked per site; the average cost is per item (all sites) and always equals
 * the value on hand divided by the quantity on hand.
 */
final class Stock
{
    public const TRACKED = ['inventory', 'assembly'];

    public static function isTracked(?string $itemType): bool
    {
        return in_array($itemType, self::TRACKED, true);
    }

    /**
     * What an item still holds: the quantity on hand (all sites), the sites where it is not zero, and its book
     * value. An inventory part or assembly with any of them can not be made inactive.
     * @return array{qty: float, value: float, sites: string[], any: bool}
     */
    public static function balance(int $itemId): array
    {
        $sites = DB::column('SELECT s.name FROM item_site_stock x JOIN sites s ON s.id = x.site_id
                              WHERE x.item_id = :i AND ABS(x.qty_on_hand) >= 0.00005 ORDER BY s.id', ['i' => $itemId]);
        $qty = self::onHand($itemId);
        $value = r2(self::valueOnHand($itemId));
        return ['qty' => $qty, 'value' => $value, 'sites' => $sites, 'any' => $sites !== [] || abs($qty) >= 0.00005 || abs($value) >= 0.005];
    }

    /** The reason an item can not be made inactive, or null when it can */
    public static function inactiveRefusal(array $item): ?string
    {
        if (!self::isTracked((string)$item['item_type'])) {
            return null;
        }
        $b = self::balance((int)$item['id']);
        if (!$b['any']) {
            return null;
        }
        $parts = [];
        if (abs($b['qty']) >= 0.00005 || $b['sites']) {
            $parts[] = qty($b['qty']) . ' on hand' . ($b['sites'] ? ' (' . implode(', ', $b['sites']) . ')' : '');
        }
        if (abs($b['value']) >= 0.005 && Access::viewCosts()) {
            $parts[] = 'a value of ' . money($b['value']);
        }
        return $item['code'] . ' still has ' . (implode(' and ', $parts) ?: 'a balance')
            . '. Only an inventory item with no balance can be made inactive: sell, transfer or adjust it to zero first.';
    }
    /**
     * The balances after each movement of an item, in the order of the transaction dates: a transaction recorded later
     * with an earlier date (back-dated) takes its place by its date, and every balance after it includes it. The
     * quantity is the item's at all sites (or the site's when one is given); the average cost is the value on hand over
     * the quantity on hand, as on the Inventory Valuation Detail report. The figures stored with a movement
     * (qty_after, avg_after) are those of the moment it was recorded, and are wrong for a back-dated one.
     *
     * @return array<int, array{qty: float, avg: float}> by movement id
     */
    public static function balancesAfter(int $itemId, ?int $siteId = null, float $startAvg = 0.0): array
    {
        $qty = 0.0;
        $val = 0.0;
        $siteQty = 0.0;
        $avg = $startAvg;
        $out = [];
        // each line's quantity runs leg by leg (the line before plus its quantity); the legs of one transaction (the two
        // sides of a transfer) share the average after the whole transaction, so a transfer between sites does not show
        // the average moving and coming back
        $group = [];
        $flush = function () use (&$group, &$out, &$qty, &$val, &$avg) {
            if (abs($qty) > 0.00001) {
                $avg = $val / $qty;
            }
            foreach ($group as $id => $q) {
                $out[$id] = ['qty' => round($q, 4), 'avg' => round($avg, 4)];
            }
            $group = [];
        };
        $prevKey = null;
        foreach (DB::all('SELECT id, txn_id, txn_date, site_id, qty, total_cost FROM inventory_movements WHERE item_id = :i ORDER BY txn_date, id', ['i' => $itemId]) as $m) {
            $key = $m['txn_date'] . '|' . $m['txn_id'];
            if ($prevKey !== null && $key !== $prevKey) {
                $flush();
            }
            $prevKey = $key;
            $qty += (float)$m['qty'];
            $val += (float)$m['total_cost'];
            if ($siteId && (int)$m['site_id'] === $siteId) {
                $siteQty += (float)$m['qty'];
            }
            $group[(int)$m['id']] = $siteId ? $siteQty : $qty;
        }
        $flush();
        return $out;
    }


    public static function onHand(int $itemId, ?int $siteId = null): float
    {
        if ($siteId) {
            return (float)DB::value('SELECT qty_on_hand FROM item_site_stock WHERE item_id = :i AND site_id = :s', ['i' => $itemId, 's' => $siteId]);
        }
        return (float)DB::value('SELECT COALESCE(SUM(qty_on_hand),0) FROM item_site_stock WHERE item_id = :i', ['i' => $itemId]);
    }

    /** Book value of an item: what its movements have put into and taken out of inventory (all sites) */
    public static function valueOnHand(int $itemId): float
    {
        return (float)DB::value('SELECT COALESCE(SUM(total_cost),0) FROM inventory_movements WHERE item_id = :i', ['i' => $itemId]);
    }

    /**
     * Records a movement and returns its signed value (positive = inventory value increased).
     *  - qty > 0 : receipt at $unitCost (defaults to current average)
     *  - qty < 0 : issue at $unitCost (defaults to current average)
     *  - qty = 0 : value-only change of $valueChange
     *
     * Moving weighted average, kept on the book value of the item:
     *  - the average is always value on hand / quantity on hand, so rounding the average to
     *    four decimals never builds up a difference between the two;
     *  - an issue that brings the quantity to zero takes out ALL the value that is left, so the
     *    cost of goods sold of that last sale equals the remaining cost and nothing stays behind
     *    on an item with no stock;
     *  - an issue that goes below zero takes out what is left plus the shortfall at the last average.
     */
    public static function move(int $txnId, ?int $lineId, string $date, int $itemId, int $siteId, float $qty, ?float $unitCost = null, float $valueChange = 0.0, ?float $exactTotal = null): float
    {
        if ($exactTotal !== null && abs($qty) > 0.000001) {
            $unitCost = abs($exactTotal / $qty);
        }
        $item = DB::row('SELECT id, avg_cost, item_type FROM items WHERE id = :i FOR UPDATE', ['i' => $itemId]);
        if (!$item || !self::isTracked($item['item_type'])) {
            return 0.0;
        }
        $totalQty = self::onHand($itemId);
        $value = r2(self::valueOnHand($itemId));
        $avg = (float)$item['avg_cost'];
        // the average the books really carry; the stored one is only used when there is nothing to divide
        $bookAvg = ($totalQty > 0.000001 && $value > 0.004) ? $value / $totalQty : $avg;
        $newQty = $totalQty + $qty;

        if (abs($qty) < 0.000001) {
            $total = r2($valueChange);
            $cost = 0.0;
            $newAvg = $totalQty > 0.000001 ? max(0.0, ($value + $total) / $totalQty) : $avg;
        } elseif ($qty > 0) {
            [$total, $cost] = self::movementValue($qty, $totalQty, $value, $bookAvg, $unitCost, $exactTotal);
            if ($newQty > 0.000001) {
                // value on hand (negative when stock was oversold) plus the value received, over the new quantity
                $newAvg = ($value + $total) / $newQty;
                if ($newAvg <= 0) {
                    $newAvg = $cost;
                }
            } else {
                $newAvg = $cost;                                   // still short after the receipt
            }
        } else {
            [$total, $cost] = self::movementValue($qty, $totalQty, $value, $bookAvg, $unitCost, $exactTotal);
            if ($newQty > 0.000001) {
                $left = $value + $total;
                $newAvg = $left > 0.004 ? $left / $newQty : $bookAvg;
            } else {
                $newAvg = $bookAvg;                                // nothing left: keep the last average for reference
            }
        }

        DB::run(
            'INSERT INTO item_site_stock (item_id, site_id, qty_on_hand) VALUES (:i, :s, :q)
             ON DUPLICATE KEY UPDATE qty_on_hand = qty_on_hand + VALUES(qty_on_hand)',
            ['i' => $itemId, 's' => $siteId, 'q' => $qty]
        );
        DB::run('UPDATE items SET avg_cost = :a WHERE id = :i', ['a' => round($newAvg, 4), 'i' => $itemId]);
        DB::insert('inventory_movements', [
            'txn_id'      => $txnId,
            'txn_line_id' => $lineId,
            'txn_date'    => $date,
            'item_id'     => $itemId,
            'site_id'     => $siteId,
            'qty'         => $qty,
            'unit_cost'   => round($cost, 4),
            'total_cost'  => $total,
            'qty_after'   => $newQty,
            'avg_after'   => round($newAvg, 4),
        ]);
        return $total;
    }

    /** Undo every movement of a transaction (used before re-posting, voiding or deleting). */
    public static function reverseTxn(int $txnId): void
    {
        $moves = DB::all('SELECT * FROM inventory_movements WHERE txn_id = :t ORDER BY id DESC', ['t' => $txnId]);
        foreach ($moves as $m) {
            $item = DB::row('SELECT avg_cost FROM items WHERE id = :i FOR UPDATE', ['i' => $m['item_id']]);
            DB::run(
                'UPDATE item_site_stock SET qty_on_hand = qty_on_hand - :q WHERE item_id = :i AND site_id = :s',
                ['q' => (float)$m['qty'], 'i' => $m['item_id'], 's' => $m['site_id']]
            );
            DB::run('DELETE FROM inventory_movements WHERE id = :id', ['id' => $m['id']]);
            // the average follows what is left on the books; with nothing left the last average stays
            $qtyLeft = self::onHand((int)$m['item_id']);
            $valueLeft = self::valueOnHand((int)$m['item_id']);
            $newAvg = ($qtyLeft > 0.000001 && $valueLeft > 0.004) ? $valueLeft / $qtyLeft : (float)$item['avg_cost'];
            DB::run('UPDATE items SET avg_cost = :a WHERE id = :i', ['a' => round($newAvg, 4), 'i' => $m['item_id']]);
        }
    }

    /**
     * The value a receipt (qty > 0) or an issue (qty < 0) puts into or takes out of inventory, and its unit cost:
     * [signed total, unit cost]. The one rule used by move() and by valueAfter().
     */
    private static function movementValue(float $qty, float $totalQty, float $value, float $bookAvg, ?float $unitCost, ?float $exactTotal): array
    {
        $cost = $unitCost ?? $bookAvg;
        if ($qty > 0) {
            return [$exactTotal !== null ? r2(abs($exactTotal)) : r2($qty * $cost), $cost];
        }
        $newQty = $totalQty + $qty;
        if ($exactTotal !== null) {
            $total = -r2(abs($exactTotal));
        } elseif ($unitCost !== null) {
            $total = r2($qty * $cost);
        } elseif ($totalQty > 0.000001 && $value > 0.004 && $newQty < 0.00005) {
            // the last of the stock (and perhaps more): all the remaining value goes out with it
            $short = abs($newQty) < 0.00005 ? 0.0 : -$newQty;
            $total = -r2($value + $short * $bookAvg);
            $cost = abs($total / $qty);
        } else {
            $total = r2($qty * $cost);
            if ($totalQty > 0.000001 && $value > 0.004 && -$total > $value) {
                $total = -$value;                              // never take out more than the books hold
            }
        }
        return [$total, $cost];
    }

    /**
     * The book value of an item (all sites) after a quantity change at its current average, without recording
     * anything: what "Quantity and total value" adjustments measure the new value against.
     */
    public static function valueAfter(int $itemId, float $qty): float
    {
        $value = r2(self::valueOnHand($itemId));
        if (abs($qty) < 0.000001) {
            return $value;
        }
        $totalQty = self::onHand($itemId);
        $avg = (float)DB::value('SELECT avg_cost FROM items WHERE id = :i', ['i' => $itemId]);
        $bookAvg = ($totalQty > 0.000001 && $value > 0.004) ? $value / $totalQty : $avg;
        return r2($value + self::movementValue($qty, $totalQty, $value, $bookAvg, null, null)[0]);
    }

    /** Quantity committed on open sales orders */
    public static function onSalesOrder(int $itemId, ?int $siteId = null): float
    {
        $sql = "SELECT COALESCE(SUM(GREATEST(l.qty - l.qty_fulfilled, 0)),0)
                  FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                 WHERE t.txn_type = 'sales_order' AND t.status IN ('open','partial') AND t.is_voided = 0
                   AND l.is_closed = 0 AND l.item_id = :i";
        $p = ['i' => $itemId];
        if ($siteId) {
            $sql .= ' AND COALESCE(l.site_id, t.site_id) = :s';
            $p['s'] = $siteId;
        }
        return (float)DB::value($sql, $p);
    }

    public static function onPurchaseOrder(int $itemId, ?int $siteId = null): float
    {
        $sql = "SELECT COALESCE(SUM(GREATEST(l.qty - l.qty_fulfilled, 0)),0)
                  FROM transaction_lines l JOIN transactions t ON t.id = l.txn_id
                 WHERE t.txn_type = 'purchase_order' AND t.status IN ('open','partial') AND t.is_voided = 0
                   AND l.is_closed = 0 AND l.item_id = :i";
        $p = ['i' => $itemId];
        if ($siteId) {
            $sql .= ' AND COALESCE(l.site_id, t.site_id) = :s';
            $p['s'] = $siteId;
        }
        return (float)DB::value($sql, $p);
    }
}
