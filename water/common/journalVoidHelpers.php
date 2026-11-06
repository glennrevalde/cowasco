<?php
/**
 * Void-and-replace of a bill's accounting document, on the one ledger.
 *
 * The former program voided its own vouchers (Status 'I') and linked them to the replacement in a side table.
 * Now the bill's invoice is voided through the ledger (Txn::void: ledger lines removed, the document stays with
 * its number, the audit trail keeps a full copy) and the replacement invoice "1024-R1" names it through
 * water_postings.reversal_of_id. The function names the billing code calls are kept.
 */

if (!function_exists('jv_void_table_exists')) {
    function jv_void_table_exists(PDO $pdo): bool
    {
        return WaterPosting::ready();
    }
}

if (!function_exists('jv_void_fetch_voucher')) {
    /** One ledger document by id (the former voucher id), or null */
    function jv_void_fetch_voucher(PDO $pdo, string $jvId, bool $forUpdate = true): ?array
    {
        $jvId = trim($jvId);
        if ($jvId === '' || !ctype_digit($jvId)) {
            return null;
        }
        $t = Txn::load((int)$jvId);
        if (!$t) {
            return null;
        }
        return ['JVID' => (string)$t['id'], 'JVEntryID' => (string)$t['txn_no'], 'JVTransDate' => (string)$t['txn_date'], 'Status' => (int)$t['is_voided'] === 1 ? 'I' : 'A'];
    }
}

if (!function_exists('jv_void_active_vouchers_for_reference')) {
    /** The live ledger documents (transactions.id as text) of a bill's event key family (WBILL-12, WBILL-12-R1 ...) */
    function jv_void_active_vouchers_for_reference(PDO $pdo, string $transRefId): array
    {
        $transRefId = trim($transRefId);
        if ($transRefId === '') {
            return [];
        }
        if (preg_match('/^(WBILL|OTB|INST|RBILL)-(\d+)$/', $transRefId, $m)) {
            $source = ['WBILL' => 'water', 'OTB' => 'one_time', 'INST' => 'installment', 'RBILL' => 'recurring'][$m[1]];
            return array_map(fn($w) => (string)$w['txn_id'], WaterPosting::billPostings($source, (int)$m[2]));
        }
        // the plain global reference number of a one-time/installment/recurring bill (former convention)
        $stmt = $pdo->prepare("SELECT w.txn_id FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                                WHERE w.doc_no = ? AND w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice'");
        $stmt->execute([$transRefId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('jv_void_voucher_totals')) {
    function jv_void_voucher_totals(PDO $pdo, string $jvId): array
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit, COUNT(*) AS line_count FROM journal_lines WHERE txn_id = ?');
        $stmt->execute([(int)$jvId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $w = $pdo->prepare('SELECT event_key FROM water_postings WHERE txn_id = ? LIMIT 1');
        $w->execute([(int)$jvId]);
        return [
            'total_debit' => round((float)($row['total_debit'] ?? 0), 2),
            'total_credit' => round((float)($row['total_credit'] ?? 0), 2),
            'line_count' => (int)($row['line_count'] ?? 0),
            'trans_ref_id' => (string)($w->fetchColumn() ?: ''),
        ];
    }
}

if (!function_exists('jv_void_voucher')) {
    /**
     * Void one ledger document of a bill and record why. Returns null when it is missing or already void, so a
     * repeated void is a no-op. Refused when collections are applied to it.
     */
    function jv_void_voucher(PDO $pdo, string $jvId, int $userId, string $reason, string $sourceType = '', string $sourceId = ''): ?array
    {
        $jvId = trim($jvId);
        if ($jvId === '' || !ctype_digit($jvId)) {
            return null;
        }
        $t = Txn::load((int)$jvId);
        if (!$t || (int)$t['is_voided'] === 1) {
            return null;
        }
        $w = $pdo->prepare('SELECT * FROM water_postings WHERE txn_id = ? LIMIT 1');
        $w->execute([(int)$jvId]);
        $posting = $w->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$posting) {
            throw new Exception('Document #' . $jvId . ' is not a water posting and cannot be voided from the water module.');
        }
        if ($t['txn_type'] === 'invoice' && Balances::applied((int)$t['id']) > 0.004) {
            throw new Exception('Invoice ' . $t['txn_no'] . ' has collections applied to it. Reverse the collection first.');
        }
        $totals = jv_void_voucher_totals($pdo, $jvId);
        $strict = DB::strictScope();
        $own = WaterPosting::owning();        // this is the water module voiding its own document
        Txn::void((int)$t['id'], mb_substr($reason, 0, 255));
        $pdo->prepare("UPDATE water_postings SET status = 'voided', reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?")
            ->execute([mb_substr($reason, 0, 255), $userId ?: null, $posting['id']]);
        return [
            'journal_voucher_id' => (string)$t['id'],
            'jv_entry_id' => (string)$t['txn_no'],
            'trans_ref_id' => (string)$posting['event_key'],
            'voided_amount' => $totals['total_debit'],
            'voided_line_count' => $totals['line_count'],
            'posting_id' => (int)$posting['id'],
        ];
    }
}

if (!function_exists('jv_void_link_replacement')) {
    /** The replacement already names the document it replaces (water_postings.reversal_of_id); kept for the callers. */
    function jv_void_link_replacement(PDO $pdo, string $originalJvId, ?string $replacementJvId, ?string $replacementEntryId = null): void
    {
        if ($originalJvId === '' || !$replacementJvId || !ctype_digit($originalJvId) || !ctype_digit((string)$replacementJvId)) {
            return;
        }
        $pdo->prepare("UPDATE water_postings SET status = 'replaced' WHERE txn_id = ? AND status = 'voided'")->execute([(int)$originalJvId]);
        $pdo->prepare('UPDATE water_postings SET reversal_of_id = COALESCE(reversal_of_id, (SELECT id FROM (SELECT id FROM water_postings WHERE txn_id = ? LIMIT 1) x)) WHERE txn_id = ?')
            ->execute([(int)$originalJvId, (int)$replacementJvId]);
    }
}

if (!function_exists('jv_void_reference_set')) {
    /**
     * Void every live document behind a bill: the ids the bill row remembers plus every live posting of its
     * event key. Returns the voided documents in the order processed.
     */
    function jv_void_reference_set(PDO $pdo, array $knownVoucherIds, string $transRefId, int $userId, string $reason, string $sourceType, string $sourceId): array
    {
        $candidates = [];
        foreach ($knownVoucherIds as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '' && ctype_digit($candidate)) {
                $candidates[$candidate] = true;
            }
        }
        foreach (jv_void_active_vouchers_for_reference($pdo, $transRefId) as $candidate) {
            $candidates[$candidate] = true;
        }
        $voided = [];
        foreach (array_keys($candidates) as $jvId) {
            $result = jv_void_voucher($pdo, (string)$jvId, $userId, $reason, $sourceType, $sourceId);
            if ($result !== null) {
                $voided[] = $result;
            }
        }
        return $voided;
    }
}

if (!function_exists('jv_document_no_with_revision')) {
    function jv_document_no_with_revision(string $baseNumber, int $revision): string
    {
        return $revision > 0 ? $baseNumber . '-R' . $revision : $baseNumber;
    }
}
