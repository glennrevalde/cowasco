<?php
require_once __DIR__ . '/journalNumberingHelpers.php';
require_once __DIR__ . '/../common/billAccountingCorrectionHelpers.php';
/**
 * Shared helper for installment billing inventory and accounting.
 *
 * Since the integration with the cooperative system the water program has no inventory ledger of its own:
 * the item master (items), the stock per site (item_site_stock) and the movements (inventory_movements) of
 * the cooperative are the only stock records, and the general ledger is the only journal.
 *
 *   - the stock an installment contract sells is the on-hand quantity of the item the bill code maps to
 *     (bill_codes.item_id -> items.id);
 *   - the unit cost stored on the contract is the item's moving average cost (items.avg_cost);
 *   - the contract posts as one INVOICE through WaterPosting::postBill(); when the bill code is an inventory
 *     item the invoice line carries the item, so the cooperative posting moves the stock out and books the
 *     cost of goods sold at the item's own accounts. Nothing here writes stock movements or journal rows.
 */

if (!function_exists('ib_uuid_v4')) {
    function ib_uuid_v4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('ib_next_jv_entry_id')) {
    /** Retired: documents are numbered by the ledger (WaterPosting). Kept so an old include does not break. */
    function ib_next_jv_entry_id(PDO $pdo)
    {
        return jv_allocate_entry_id($pdo);
    }
}

if (!function_exists('ib_create_voucher')) {
    /** Retired: the water program no longer writes journal vouchers of its own. */
    function ib_create_voucher(PDO $pdo, $transDate, int $branchId = 0, int $segmentId = 0, int $divisionId = 0)
    {
        throw new RuntimeException('Legacy journal vouchers are retired: installment bills post through WaterPosting::postBill().');
    }
}

if (!function_exists('ib_insert_journal_line')) {
    /** Retired: the water program no longer writes journal lines of its own. */
    function ib_insert_journal_line(PDO $pdo, $jvId, $transRef, $accountId, $debit, $credit, $description, $slType, $slCode, $isJournalHead, int $branchId = 0, int $segmentId = 0, int $divisionId = 0)
    {
        throw new RuntimeException('Legacy journal lines are retired: installment bills post through WaterPosting::postBill().');
    }
}

if (!function_exists('ib_is_inventory_code')) {
    function ib_is_inventory_code(array $bc): bool
    {
        return strcasecmp(trim((string)($bc['type'] ?? '')), 'Inventory') === 0;
    }
}

if (!function_exists('ib_bill_code_item')) {
    /**
     * The cooperative item a bill code maps to (bill_codes.item_id), with its on-hand quantity across sites and
     * its average cost. Null when the bill code is not linked to an item.
     */
    function ib_bill_code_item(PDO $pdo, $codeId): ?array
    {
        $codeId = (int)$codeId;
        if ($codeId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT i.id AS item_id, i.code, i.name, i.item_type, i.is_active, i.avg_cost, i.cost,
                   i.asset_account_id, i.cogs_account_id, i.income_account_id,
                   COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = i.id), 0) AS qty_on_hand
            FROM bill_codes bc
            JOIN items i ON i.id = bc.item_id
            WHERE bc.code_id = ?
            LIMIT 1
        ");
        $stmt->execute([$codeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('ib_assert_inventory_item')) {
    /** An inventory bill code must be linked to an item of the Item List before it can sell anything. */
    function ib_assert_inventory_item(array $bc): void
    {
        if (ib_is_inventory_code($bc) && empty($bc['item_id'])) {
            throw new Exception('Bill code ' . (string)($bc['code'] ?? '') . ' is an inventory item but is not linked to an item of the Item List (Water Billing > Bill Codes).');
        }
    }
}

if (!function_exists('ib_available_stock')) {
    /**
     * The on-hand quantity (all sites) of the item the bill code maps to. A bill code without an item has no
     * stock: 0 is returned and the caller reports "not linked to an item".
     */
    function ib_available_stock(PDO $pdo, $codeId)
    {
        $item = ib_bill_code_item($pdo, $codeId);
        return $item ? round((float)$item['qty_on_hand'], 4) : 0.0;
    }
}

if (!function_exists('ib_item_unit_cost')) {
    /**
     * The unit cost stored on an installment contract: the moving average cost of the cooperative item the bill
     * code maps to (items.avg_cost; the standard cost when the item never moved). 0.00 without an item.
     */
    function ib_item_unit_cost(PDO $pdo, $codeId)
    {
        $item = ib_bill_code_item($pdo, $codeId);
        if (!$item) {
            return 0.00;
        }
        $avg = (float)$item['avg_cost'];
        if ($avg <= 0 && (float)$item['qty_on_hand'] <= 0) {
            $avg = (float)$item['cost'];
        }
        return round($avg, 2);
    }
}

if (!function_exists('ib_add_stock_movement')) {
    /**
     * NO-OP. The water program kept its own stock movements (inventory_stock_movements); that table is gone.
     * Stock now moves through the item line of the invoice WaterPosting::postBill() creates for the contract
     * (and comes back when the invoice is voided or replaced), so there is nothing to record here. The
     * signature is kept for the callers; nothing is returned.
     */
    function ib_add_stock_movement(PDO $pdo, $codeId, $refType, $refId, $movementDate, $qtyIn, $qtyOut, $unitCost, $remarks, $createdBy)
    {
        return;
    }
}

if (!function_exists('ib_create_installment_journal')) {
    /**
     * Posts an installment contract as one invoice of the water-service account's customer for the whole contract
     * amount (Dr A/R, Cr income; stock out and COGS by the cooperative inventory when the bill code sells an
     * item). The invoice number is the contract's global reference number (else INST-<id>); the due date is the
     * first schedule's due date.
     * Returns the posting result (journal_voucher_id / journal_head_id = transactions.id, jv_entry_id /
     * journal_entry_id = the invoice number, plus jv_id for the oldest callers), or null for a zero amount.
     */
    function ib_create_installment_journal(PDO $pdo, $startDate, array $bc, $memberId, $installmentId, $totalAmount, $quantity, int $branchId = 0, int $segmentId = 0, int $divisionId = 0)
    {
        $amount = round((float)$totalAmount, 2);
        if (abs($amount) < 0.005) {
            return null;
        }
        ib_assert_inventory_item($bc);
        if (empty($bc['ar_account']) || empty($bc['income_account'])) {
            throw new Exception('Bill code ' . (string)($bc['code'] ?? '') . ' is missing its A/R or income account mapping.');
        }

        $installmentId = (int)$installmentId;
        $memberId = (int)$memberId;
        $docNo = '';
        $dueDate = null;
        try {
            $stmt = $pdo->prepare('SELECT global_ref_number, start_date FROM installment_bills WHERE installment_id = ? LIMIT 1');
            $stmt->execute([$installmentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $docNo = trim((string)($row['global_ref_number'] ?? ''));
            $stmt = $pdo->prepare('SELECT MIN(due_date) FROM installment_schedules WHERE installment_id = ?');
            $stmt->execute([$installmentId]);
            $first = $stmt->fetchColumn();
            if ($first && $first !== '0000-00-00') {
                $dueDate = substr((string)$first, 0, 10);
            }
        } catch (Throwable $e) {
            // the document number falls back to INST-<id>
        }
        if ($docNo === '0') {
            $docNo = '';
        }

        $desc = "Installment contract #{$installmentId} ({$bc['code']}) for account #{$memberId}";
        $line = ['account_id' => (int)$bc['income_account'], 'amount' => $amount, 'description' => $desc, 'qty' => 1];
        if (ib_is_inventory_code($bc) && !empty($bc['item_id'])) {
            $qty = round((float)$quantity, 4);
            $line['item_id'] = (int)$bc['item_id'];
            $line['qty'] = $qty > 0 ? $qty : 1;
            $line['rate'] = round($amount / $line['qty'], 4);
        }

        $r = WaterPosting::postBill('installment', $installmentId, [
            'water_account_id' => $memberId,
            'txn_date' => substr((string)$startDate, 0, 10),
            'due_date' => $dueDate,
            'doc_no' => $docNo !== '' ? $docNo : null,
            'ar_account_id' => (int)$bc['ar_account'],
            'lines' => [$line],
            'memo' => mb_substr($desc, 0, 255),
            'branch_id' => $branchId,
            'segment_id' => $segmentId,
            'division_id' => $divisionId,
        ]);
        if (!$r) {
            return null;
        }
        $r['jv_id'] = $r['journal_voucher_id'];
        return $r;
    }
}

if (!function_exists('ib_create_status_adjustment_journal')) {
    /**
     * A correction of a posted installment contract by a signed amount (bl_ac_create_bill_adjustment_journal:
     * a debit posts a supplemental invoice, a partial credit a credit memo, a full credit of an uncollected
     * contract voids the invoice, which also brings the material back into stock; a reactivation posts the
     * contract again). $costDelta is accepted and ignored: the cost of the material follows the item line of the
     * invoice (average cost of the cooperative inventory), it is never adjusted by hand.
     * Returns ['journal_voucher_id', 'jv_entry_id', 'jv_id'] or null when nothing posts.
     */
    function ib_create_status_adjustment_journal(PDO $pdo, $billDate, array $bc, $memberId, $installmentId, $amountDelta, $costDelta, $reason)
    {
        $amountDelta = round((float)$amountDelta, 2);
        if (abs($amountDelta) < 0.005) {
            return null;
        }
        $row = $bc;
        $row['member_id'] = (int)$memberId;
        if (empty($row['bill_code_id'])) {
            $row['bill_code_id'] = (int)($bc['code_id'] ?? 0);
        }
        if (empty($row['bill_code_id']) || !isset($row['quantity'])) {
            try {
                $stmt = $pdo->prepare('SELECT bill_code_id, global_ref_number, quantity, start_date, total_amount, branch_id, segment_id, division_id FROM installment_bills WHERE installment_id = ? LIMIT 1');
                $stmt->execute([(int)$installmentId]);
                $fresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $row = array_merge($fresh, $row);
                if (empty($row['bill_code_id'])) {
                    $row['bill_code_id'] = (int)($fresh['bill_code_id'] ?? 0);
                }
            } catch (Throwable $e) {
                // the correction helper reports the missing bill code
            }
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $r = bl_ac_create_bill_adjustment_journal($pdo, 'installment', (int)$installmentId, $row, $amountDelta, 0.0, (string)$reason, $userId, substr((string)$billDate, 0, 10));
        if (!$r) {
            return null;
        }
        $r['jv_id'] = $r['journal_voucher_id'];
        return $r;
    }
}
