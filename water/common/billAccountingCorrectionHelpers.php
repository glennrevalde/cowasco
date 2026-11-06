<?php
/**
 * Bill correction accounting (edits, adjustments, soft deletes) on the one ledger.
 *
 * The former program wrote delta vouchers to its own journal tables. Every correction is now a document of the
 * general ledger made by app/WaterPosting.php and linked to the bill's original document in water_postings:
 *
 *   debit adjustment (bill grows)   -> a supplemental invoice "<bill no>-ADJn"
 *   credit adjustment (bill shrinks)-> a credit memo applied to the bill's invoice
 *   soft delete of an unpaid bill   -> the bill's invoice is voided (ledger lines removed, document and audit kept);
 *                                      stock sold by an inventory bill comes back through the cooperative inventory
 *   edit of an unpaid bill          -> the invoice is voided and a replacement "<bill no>-Rn" is posted
 *
 * Costs are never adjusted by hand any more: the cost of a material sold by a bill follows the item line of the
 * invoice (average cost of the cooperative inventory), so the $costDelta / $costTotal arguments are accepted for
 * compatibility and ignored.
 */

require_once __DIR__ . '/waterRateBillingHelpers.php';

if (!function_exists('bl_ac_table_exists')) {
    function bl_ac_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            $cache[$table] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$table];
    }
}

if (!function_exists('bl_ac_column_exists')) {
    function bl_ac_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$key];
    }
}

if (!function_exists('bl_ac_uuid_v4')) {
    function bl_ac_uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('bl_ac_bill_code_from_row')) {
    function bl_ac_bill_code_from_row(PDO $pdo, string $billType, array $row): array
    {
        if ($billType === 'water') {
            $bc = water_billing_get_global_bill_code($pdo);
            $bc['cost_price'] = 0;
            $bc['cogs_account'] = 0;
            $bc['asset_account'] = 0;
            $bc['item_id'] = null;
            return $bc;
        }
        $billCodeId = (int)($row['bill_code_id'] ?? 0);
        if ($billCodeId <= 0) {
            throw new Exception('Accounting correction failed: bill code is missing.');
        }
        $stmt = $pdo->prepare('SELECT code_id, code, description, type, ar_account, income_account, cogs_account, asset_account, cost_price, item_id FROM bill_codes WHERE code_id = ? LIMIT 1');
        $stmt->execute([$billCodeId]);
        $bc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bc) {
            throw new Exception('Accounting correction failed: bill code record was not found.');
        }
        return $bc;
    }
}

if (!function_exists('bl_ac_validate_bill_code_accounts')) {
    function bl_ac_validate_bill_code_accounts(array $bc, float $amountDelta, float $costDelta): void
    {
        if (abs($amountDelta) >= 0.0001 && (empty($bc['ar_account']) || empty($bc['income_account']))) {
            throw new Exception('Accounting correction failed: Bill Code is missing AR or Income account.');
        }
        if (strcasecmp(trim((string)($bc['type'] ?? '')), 'Inventory') === 0 && empty($bc['item_id'])) {
            throw new Exception('Accounting correction failed: the inventory bill code ' . ($bc['code'] ?? '') . ' is not linked to an item of the item master (Bill Codes).');
        }
    }
}

if (!function_exists('bl_ac_assert_active_account')) {
    function bl_ac_assert_active_account(PDO $pdo, int $accountId, string $label): void
    {
        if ($accountId <= 0) {
            throw new Exception('Accounting correction failed: ' . $label . ' is not configured.');
        }
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$accountId]);
        if (!(int)$stmt->fetchColumn()) {
            throw new Exception('Accounting correction failed: ' . $label . ' is missing or inactive.');
        }
    }
}

if (!function_exists('bl_ac_source_key')) {
    /** water | one_time | installment | recurring as WaterPosting names them */
    function bl_ac_source_key(string $billType): string
    {
        return $billType === 'onetime' ? 'one_time' : $billType;
    }
}

if (!function_exists('bl_ac_bill_trans_ref')) {
    /** The event key of the bill's original posting (WBILL-<id>, OTB-<id>, INST-<id>, RBILL-<id>) */
    function bl_ac_bill_trans_ref(string $billType, int $billId, array $row): string
    {
        return WaterPosting::eventKey(bl_ac_source_key($billType), $billId);
    }
}

if (!function_exists('bl_ac_bill_lines')) {
    /** The invoice lines of a bill from its bill code: the income account, and the item when it sells a material */
    function bl_ac_bill_lines(array $bc, float $amount, array $row, string $description): array
    {
        $line = ['account_id' => (int)$bc['income_account'], 'amount' => round($amount, 2), 'description' => $description, 'qty' => 1];
        if (strcasecmp(trim((string)($bc['type'] ?? '')), 'Inventory') === 0 && !empty($bc['item_id'])) {
            $qty = round((float)($row['quantity'] ?? 1), 4);
            $line['item_id'] = (int)$bc['item_id'];
            $line['qty'] = $qty > 0 ? $qty : 1;
            $line['rate'] = $line['qty'] > 0 ? round($amount / $line['qty'], 4) : $amount;
        }
        return [$line];
    }
}

if (!function_exists('bl_ac_create_bill_adjustment_journal')) {
    /**
     * A correction of a posted bill by a signed amount. A full credit of an unpaid bill (soft delete) voids its
     * invoice; a partial credit posts a credit memo; a debit posts a supplemental invoice.
     * Returns ['journal_voucher_id' => transactions.id, 'jv_entry_id' => document no] or null when nothing posts.
     */
    function bl_ac_create_bill_adjustment_journal(
        PDO $pdo,
        string $billType,
        int $billId,
        array $oldRow,
        float $amountDelta,
        float $costDelta,
        string $reason,
        int $userId,
        ?string $transDate = null
    ): ?array {
        $amountDelta = round($amountDelta, 2);
        if (abs($amountDelta) < 0.005) {
            return null;
        }
        $source = bl_ac_source_key($billType);
        $bc = bl_ac_bill_code_from_row($pdo, $billType, $oldRow);
        bl_ac_validate_bill_code_accounts($bc, $amountDelta, 0.0);
        bl_ac_assert_active_account($pdo, (int)$bc['income_account'], 'Bill Code income account');

        $memberId = (int)($oldRow['member_id'] ?? 0);
        $branchId = (int)($oldRow['branch_id'] ?? 0);
        $segmentId = (int)($oldRow['segment_id'] ?? 0);
        $divisionId = (int)($oldRow['division_id'] ?? 0);
        $transDate = $transDate ?: date('Y-m-d');
        $typeLabel = ucwords(str_replace('_', ' ', $billType));
        $code = $bc['code'] ?? 'Bill';
        $desc = "{$reason}: {$typeLabel} bill #{$billId} ({$code}) for account #{$memberId}";

        $live = WaterPosting::billPostings($source, $billId);
        if (!$live) {
            // a bill that was never posted (zero amount, or recorded before the integration without a document)
            if ($amountDelta > 0) {
                // the revision follows the postings the bill already had (0 = first posting; a reactivated
                // bill whose invoice was voided gets "-R<n>" and is linked to the voided one)
                $priorPostings = (int)DB::value("SELECT COUNT(*) FROM water_postings WHERE source_type = :s AND source_id = :i AND txn_type = 'invoice' AND event_key NOT LIKE '%-ADJ%'",
                    ['s' => WaterPosting::sourceType($source), 'i' => (string)$billId]);
                $r = WaterPosting::postBill($source, $billId, [
                    'water_account_id' => $memberId, 'txn_date' => $transDate, 'doc_no' => $oldRow['global_ref_number'] ?? null,
                    'ar_account_id' => (int)$bc['ar_account'], 'lines' => bl_ac_bill_lines($bc, $amountDelta, $oldRow, $desc),
                    'memo' => mb_substr($desc, 0, 255), 'branch_id' => $branchId, 'segment_id' => $segmentId, 'division_id' => $divisionId, 'reason' => $reason,
                    'class_id' => WaterPosting::sourceClass($source, $billId),
                    'revision' => $priorPostings,
                    'replaces' => $priorPostings > 0 ? ($priorPostings === 1 ? WaterPosting::eventKey($source, $billId) : WaterPosting::eventKey($source, $billId, $priorPostings - 1)) : null,
                ]);
                return $r ? ['journal_voucher_id' => $r['journal_voucher_id'], 'jv_entry_id' => $r['jv_entry_id']] : null;
            }
            return null;
        }
        $liveTotal = round(array_sum(array_map(fn($w) => (float)$w['total'], $live)), 2);
        $applied = 0.0;
        foreach ($live as $w) {
            $applied += Balances::applied((int)$w['txn_id']);
        }
        if ($amountDelta < 0 && round(-$amountDelta, 2) >= $liveTotal - 0.004 && $applied < 0.005) {
            // the whole bill is taken back: its invoice is voided (a deleted or cancelled bill)
            $voided = WaterPosting::voidBill($source, $billId, $reason);
            $first = $voided[0] ?? null;
            return $first ? ['journal_voucher_id' => $first['journal_voucher_id'], 'jv_entry_id' => $first['jv_entry_id'] . ' (voided)', 'voided' => $voided] : null;
        }
        $r = WaterPosting::postBillAdjustment($source, $billId, $amountDelta, [
            'water_account_id' => $memberId, 'txn_date' => $transDate, 'income_account_id' => (int)$bc['income_account'],
            'description' => mb_substr($desc, 0, 255), 'memo' => mb_substr($desc, 0, 255), 'reason' => $reason,
            'branch_id' => $branchId, 'segment_id' => $segmentId, 'division_id' => $divisionId,
            // the class of the form (WaterPosting::withClass), else the class the bill was posted under
            'class_id' => WaterPosting::sourceClass($source, $billId),
        ]);
        return $r ? ['journal_voucher_id' => $r['journal_voucher_id'], 'jv_entry_id' => $r['jv_entry_id']] : null;
    }
}

if (!function_exists('bl_ac_create_bill_replacement_journal')) {
    /**
     * The full corrected amount of an edited bill, posted as a new invoice "<bill no>-Rn" after the caller voided the
     * original (jv_void_reference_set). Returns ['journal_voucher_id', 'jv_entry_id'] or null for a zero amount.
     */
    function bl_ac_create_bill_replacement_journal(
        PDO $pdo,
        string $billType,
        int $billId,
        array $row,
        float $amount,
        float $costTotal,
        string $transRefId,
        string $reason,
        int $userId,
        ?string $transDate = null
    ): ?array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }
        $source = bl_ac_source_key($billType);
        $bc = bl_ac_bill_code_from_row($pdo, $billType, $row);
        bl_ac_validate_bill_code_accounts($bc, $amount, 0.0);
        bl_ac_assert_active_account($pdo, (int)$bc['ar_account'], 'Bill Code AR account');
        bl_ac_assert_active_account($pdo, (int)$bc['income_account'], 'Bill Code income account');

        $memberId = (int)($row['member_id'] ?? 0);
        $revision = WaterPosting::nextRevision($source, $billId);
        $typeLabel = ucwords(str_replace('_', ' ', $billType));
        $docNo = WaterPosting::docNo($source, $billId, $row['global_ref_number'] ?? null);
        $desc = "Corrected {$typeLabel} bill #{$billId} (Invoice No. {$docNo}-R{$revision}) for account #{$memberId} - {$reason}";
        $dueDate = $row['due_date'] ?? ($row['start_date'] ?? null);
        $r = WaterPosting::postBill($source, $billId, [
            'water_account_id' => $memberId,
            'txn_date' => $transDate ?: date('Y-m-d'),
            'due_date' => $dueDate ? substr((string)$dueDate, 0, 10) : null,
            'doc_no' => $row['global_ref_number'] ?? null,
            'ar_account_id' => (int)$bc['ar_account'],
            'lines' => bl_ac_bill_lines($bc, $amount, $row, mb_substr($desc, 0, 255)),
            'memo' => mb_substr($desc, 0, 255),
            'branch_id' => (int)($row['branch_id'] ?? 0), 'segment_id' => (int)($row['segment_id'] ?? 0), 'division_id' => (int)($row['division_id'] ?? 0),
            'class_id' => WaterPosting::sourceClass($source, $billId),
            'revision' => $revision,
            'replaces' => $revision === 1 ? WaterPosting::eventKey($source, $billId) : WaterPosting::eventKey($source, $billId, $revision - 1),
            'reason' => $reason,
        ]);
        return $r ? ['journal_voucher_id' => $r['journal_voucher_id'], 'jv_entry_id' => $r['jv_entry_id']] : null;
    }
}

if (!function_exists('bl_ac_store_adjustment_reference')) {
    function bl_ac_store_adjustment_reference(PDO $pdo, string $table, string $pkColumn, int $id, ?array $voucher): void
    {
        if (!$voucher) {
            return;
        }
        $sets = [];
        $params = [':id' => $id];
        if (bl_ac_column_exists($pdo, $table, 'last_adjustment_journal_voucher_id')) {
            $sets[] = 'last_adjustment_journal_voucher_id = :jv';
            $params[':jv'] = isset($voucher['journal_voucher_id']) ? mb_substr((string)$voucher['journal_voucher_id'], 0, 64) : null;
        }
        if (bl_ac_column_exists($pdo, $table, 'last_adjustment_jv_entry_id')) {
            $sets[] = 'last_adjustment_jv_entry_id = :entry';
            $params[':entry'] = isset($voucher['jv_entry_id']) ? mb_substr((string)$voucher['jv_entry_id'], 0, 64) : null;
        }
        if (bl_ac_column_exists($pdo, $table, 'last_accounting_adjusted_at')) {
            $sets[] = 'last_accounting_adjusted_at = NOW()';
        }
        if (!$sets) {
            return;
        }
        $stmt = $pdo->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pkColumn} = :id");
        $stmt->execute($params);
    }
}
