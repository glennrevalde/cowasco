<?php
/**
 * Inventory Costing Helpers
 *
 * The balance comes from the cooperative inventory (items / item_site_stock / inventory_movements, the only
 * stock ledger); the weighted-average and outgoing-cost functions are pure arithmetic kept for the callers.
 */

if (!function_exists('weighted_average_cost')) {
    function weighted_average_cost(float $balanceQty, float $balanceCost, float $newQty, float $newCost): float
    {
        $qty = $balanceQty + $newQty;

        if ($qty <= 0) {
            return 0.00;
        }

        return round(($balanceCost + $newCost) / $qty, 2);
    }
}

if (!function_exists('inv_weighted_average_after_purchase')) {
    function inv_weighted_average_after_purchase(float $balanceQty, float $balanceCost, float $newQty, float $newPurchaseBalance): float
    {
        $totalQty = $balanceQty + $newQty;
        $totalCost = $balanceCost + $newPurchaseBalance;

        if ($totalQty <= 0) {
            return 0.00;
        }

        return round($totalCost / $totalQty, 2);
    }
}

if (!function_exists('inventory_outgoing_cost')) {
    function inventory_outgoing_cost(float $balanceQty, float $balanceCost, float $outQty): array
    {
        if ($outQty <= 0) {
            return [
                'issued_cost' => 0.00,
                'remaining_cost' => round($balanceCost, 2),
                'average_cost' => $balanceQty > 0 ? round($balanceCost / $balanceQty, 2) : 0.00,
            ];
        }

        if ($outQty >= $balanceQty) {
            return [
                'issued_cost' => round($balanceCost, 2),
                'remaining_cost' => 0.00,
                'average_cost' => $balanceQty > 0 ? round($balanceCost / $balanceQty, 2) : 0.00,
            ];
        }

        $avg = $balanceQty > 0 ? round($balanceCost / $balanceQty, 2) : 0.00;
        $issued = round($avg * $outQty, 2);

        if ($issued > $balanceCost) {
            $issued = round($balanceCost, 2);
        }

        return [
            'issued_cost' => $issued,
            'remaining_cost' => round($balanceCost - $issued, 2),
            'average_cost' => $avg,
        ];
    }
}

if (!function_exists('inv_balance_info')) {
    /**
     * The stock balance of the cooperative item a bill code maps to (bill_codes.item_id):
     * quantity on hand across every site (item_site_stock), the book value of that stock (the sum of its
     * inventory_movements, the value the moving average is kept on) and the average cost (items.avg_cost).
     * A bill code without an item has no stock. The keys are the ones the callers always read.
     */
    function inv_balance_info(PDO $pdo, int $codeId): array
    {
        $qty = 0.00;
        $totalCost = 0.00;
        $avg = 0.00;

        try {
            $stmt = $pdo->prepare("
                SELECT i.id, i.avg_cost, i.cost,
                       COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id), 0) AS qty_on_hand,
                       COALESCE((SELECT SUM(m.total_cost) FROM inventory_movements m WHERE m.item_id = i.id), 0) AS book_value
                FROM bill_codes bc
                JOIN items i ON i.id = bc.item_id
                WHERE bc.code_id = ?
                LIMIT 1
            ");
            $stmt->execute([$codeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $qty = round((float)$row['qty_on_hand'], 4);
                $totalCost = round((float)$row['book_value'], 2);
                $avg = round((float)$row['avg_cost'], 4);
                if ($avg <= 0 && $qty > 0 && $totalCost > 0) {
                    $avg = round($totalCost / $qty, 4);
                }
                if ($totalCost <= 0 && $qty > 0 && $avg > 0) {
                    $totalCost = round($qty * $avg, 2);
                }
            }
        } catch (Throwable $e) {
            $qty = 0.00;
            $totalCost = 0.00;
            $avg = 0.00;
        }

        /*
         * Never show a negative balance (an oversold item is a problem of the cooperative inventory pages).
         */
        if ($qty < 0) {
            $qty = 0.00;
        }
        if ($totalCost < 0) {
            $totalCost = 0.00;
        }
        $avg = round($avg, 2);

        return [
            'qty' => $qty,
            'total_cost' => $totalCost,
            'avg' => $avg,
            'average_cost' => $avg,
            'current_qty' => $qty,
            'current_value' => $totalCost,
        ];
    }
}

if (!function_exists('inv_cost_for_outgoing')) {
    function inv_cost_for_outgoing(float $outQty, float $balanceQty, float $balanceCost): array
    {
        $outQty = round($outQty, 2);
        $balanceQty = round($balanceQty, 2);
        $balanceCost = round($balanceCost, 2);

        if ($outQty <= 0 || $balanceQty <= 0 || $balanceCost <= 0) {
            return [
                'total_cost' => 0.00,
                'unit_cost' => 0.00,
                'remaining_cost' => round(max(0, $balanceCost), 2),
            ];
        }

        if ($outQty >= $balanceQty) {
            return [
                'total_cost' => $balanceCost,
                'unit_cost' => round($balanceCost / max(1, $outQty), 2),
                'remaining_cost' => 0.00,
            ];
        }

        /*
         * Rounding-error solution:
         * Example:
         * Balance qty = 2
         * Balance cost = 555.55
         * Average = 277.78
         * If qty out = 1, issued = 277.78, remaining = 277.77.
         * We keep the real remaining balance, not avg * remaining qty.
         */
        $avg = round($balanceCost / $balanceQty, 2);
        $issuedCost = round($avg * $outQty, 2);

        if ($issuedCost > $balanceCost) {
            $issuedCost = $balanceCost;
        }

        $remainingCost = round($balanceCost - $issuedCost, 2);

        return [
            'total_cost' => $issuedCost,
            'unit_cost' => $outQty > 0 ? round($issuedCost / $outQty, 2) : 0.00,
            'remaining_cost' => $remainingCost,
        ];
    }
}
