<?php
/**
 * Accounting helpers for controlled internal inventory issuances (Material Issuance).
 *
 * An issuance takes materials out of the cooperative inventory and charges them to an expense account. The
 * operational record stays in inventory_issuances / inventory_issuance_items (issuance no., date, recipient,
 * department, purpose, lines at the item's average cost); the stock movement and the ledger entry are ONE
 * inventory adjustment of the cooperative (Dr expense, Cr the item's inventory asset account, stock out at
 * average cost) made by WaterPosting::postIssuance(). A reversal voids that adjustment
 * (WaterPosting::reverseIssuance()), which puts the stock back.
 *
 * The pickable materials are the bill codes of type Inventory that are linked to an item of the Item List
 * (bill_codes.item_id); the quantity on hand and the average cost come from the item.
 */

require_once __DIR__ . '/inventoryCostingHelpers.php';

if (!function_exists('issuance_assert_detail_account')) {
    /**
     * An account of the chart of accounts that exists (and is active unless $requireActive is false). Returns
     * the row with both the cooperative names (id, name, ...) and the legacy names (AccntID, AccntName, Status)
     * the older callers read.
     */
    function issuance_assert_detail_account(PDO $pdo, int $accountId, string $label, bool $requireActive = true): array
    {
        if ($accountId <= 0) {
            throw new RuntimeException($label . ' is required.');
        }
        $stmt = $pdo->prepare('SELECT id, code, name, category, account_type, is_active FROM accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new RuntimeException($label . ' does not exist.');
        }
        if ($requireActive && (int)$account['is_active'] !== 1) {
            throw new RuntimeException($label . ' is inactive.');
        }
        $account['AccntID'] = (int)$account['id'];
        $account['AccntName'] = (string)$account['name'];
        $account['SummaryAccnt'] = '';
        $account['Status'] = (int)$account['is_active'] === 1 ? 'A' : 'I';
        return $account;
    }
}

if (!function_exists('issuance_expense_accounts')) {
    /** The accounts an issuance may be charged to: active Expense accounts (code, name) */
    function issuance_expense_accounts(PDO $pdo): array
    {
        $rows = $pdo->query("
            SELECT id, code, name, account_type
            FROM accounts
            WHERE category = 'Expense' AND is_active = 1
            ORDER BY code, name
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['AccntID'] = (int)$r['id'];
            $r['AccntName'] = (string)$r['name'];
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('issuance_pickable_items')) {
    /**
     * The materials that can be issued: inventory bill codes linked to an active item of the Item List, with the
     * item's on-hand quantity (all sites) and average cost. $onlyInStock keeps the ones with stock.
     * Keys: code_id, code, description, item_id, item_code, item_name, unit, current_qty, average_unit_cost, asset_account_id
     */
    function issuance_pickable_items(PDO $pdo, bool $onlyInStock = true): array
    {
        $sql = "
            SELECT bc.code_id, bc.code, bc.description, bc.item_id,
                   i.code AS item_code, i.name AS item_name, i.unit, i.avg_cost AS average_unit_cost, i.asset_account_id,
                   COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id), 0) AS current_qty
            FROM bill_codes bc
            JOIN items i ON i.id = bc.item_id AND i.is_active = 1
            WHERE bc.type = 'Inventory'
        ";
        if ($onlyInStock) {
            $sql = 'SELECT * FROM (' . $sql . ') x WHERE x.current_qty > 0 ORDER BY x.code';
        } else {
            $sql .= ' ORDER BY bc.code';
        }
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('issuance_item_site_id')) {
    /** The inventory site the materials move from (the Water Utility unit's site, else the first active site) */
    function issuance_item_site_id(): int
    {
        return WaterPosting::siteId();
    }
}

if (!function_exists('issuance_create_journal')) {
    /**
     * Posts an issuance: one inventory adjustment of the cooperative (Dr expense, Cr inventory, stock out at
     * average cost) through WaterPosting::postIssuance(). $lines: [[item_id, qty, description]].
     * Returns journal_head_id (= transactions.id as text), journal_entry_id (= the issuance no.), txn_id,
     * total (the cost taken out of inventory) and movements (item_id => [qty, unit_cost, total_cost]).
     */
    function issuance_create_journal(PDO $pdo, int $issuanceId, string $issuanceNo, string $issuanceDate, int $expenseAccountId, array $lines, array $opt = []): array
    {
        issuance_assert_detail_account($pdo, $expenseAccountId, 'Issuance expense account');
        $clean = [];
        foreach ($lines as $l) {
            $qty = round((float)($l['qty'] ?? 0), 4);
            if ((int)($l['item_id'] ?? 0) <= 0 || $qty <= 0) {
                continue;
            }
            $clean[] = ['item_id' => (int)$l['item_id'], 'qty' => $qty, 'description' => (string)($l['description'] ?? '')];
        }
        if (!$clean) {
            throw new RuntimeException('The issuance has no items.');
        }
        $r = WaterPosting::postIssuance($issuanceId, [
            'txn_date' => $issuanceDate,
            'doc_no' => $issuanceNo,
            'expense_account_id' => $expenseAccountId,
            'lines' => $clean,
            'memo' => (string)($opt['memo'] ?? ('Material issuance ' . $issuanceNo)),
            'branch_id' => (int)($opt['branch_id'] ?? 0),
            'site_id' => (int)($opt['site_id'] ?? 0) ?: null,
        ]);
        $txnId = (int)$r['txn_id'];
        $movements = [];
        $total = 0.0;
        $stmt = $pdo->prepare('SELECT item_id, qty, unit_cost, total_cost FROM inventory_movements WHERE txn_id = ? ORDER BY id');
        $stmt->execute([$txnId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $itemId = (int)$m['item_id'];
            $movements[$itemId] = [
                'qty' => round(abs((float)$m['qty']), 4) + (float)($movements[$itemId]['qty'] ?? 0),
                'unit_cost' => round((float)$m['unit_cost'], 4),
                'total_cost' => round(abs((float)$m['total_cost']), 2) + (float)($movements[$itemId]['total_cost'] ?? 0),
            ];
            $total += abs((float)$m['total_cost']);
        }
        return [
            'journal_head_id' => (string)$r['journal_head_id'],
            'journal_entry_id' => (string)$r['journal_entry_id'],
            'txn_id' => $txnId,
            'posting_id' => (int)$r['posting_id'],
            'total' => round($total, 2),
            'movements' => $movements,
        ];
    }
}

if (!function_exists('issuance_create_reversal_journal')) {
    /**
     * Reverses an issuance: the inventory adjustment is voided (ledger lines removed, stock put back, the document
     * and the audit trail kept) through WaterPosting::reverseIssuance().
     * Returns journal_head_id (the voided transaction id), journal_entry_id (ISSREV-<id>) and total.
     */
    function issuance_create_reversal_journal(PDO $pdo, int $issuanceId, string $issuanceNo, string $reason, float $total = 0.0): array
    {
        $r = WaterPosting::reverseIssuance($issuanceId, 'Reverse material issuance ' . $issuanceNo . ': ' . $reason);
        return [
            'journal_head_id' => (string)$r['journal_head_id'],
            'journal_entry_id' => (string)$r['journal_entry_id'],
            'total' => round($total, 2),
        ];
    }
}
