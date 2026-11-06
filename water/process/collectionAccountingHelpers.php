<?php
/**
 * Collection + billing accounting helpers.
 *
 * These are the functions the billing and collection code always called. They now post through the one ledger
 * (app/WaterPosting.php): a posted water bill is an invoice of the account's customer, a collection is a payment
 * applied to the invoices of the bills paid, penalty and compromise are finance charges settled by that payment.
 * The legacy voucher tables (legacy_journalvoucher, legacy_journalentries) are never written.
 *
 * IMPORTANT WATER BILLING RULE (unchanged):
 * - Water-rate tier amount calculates the sales price.
 * - The single global Water Billing Bill Code supplies the accounting accounts.
 * - bill_codes.sales_price is ignored for water billing.
 */
require_once __DIR__ . '/journal_helpers.php';
require_once __DIR__ . '/../common/userProfileHelpers.php';

if (!function_exists('wc_journal_exists')) {
    /** A live (posted, not voided) accounting document exists for this event key */
    function wc_journal_exists(PDO $pdo, string $transRefId): bool
    {
        $w = WaterPosting::byEvent($transRefId);
        return $w !== null && $w['status'] === 'posted';
    }
}

if (!function_exists('wc_water_billing_journal_description')) {
    function wc_water_billing_journal_description(int $readingId, int $memberId, ?array $correction = null): string
    {
        if ($correction === null) {
            return "Posted water bill #{$readingId} for account #{$memberId}";
        }
        $revision = max(1, (int)($correction['revision'] ?? 1));
        $invoiceNo = trim((string)($correction['invoice_no'] ?? ''));
        $document = $invoiceNo !== '' ? 'Invoice No. ' . $invoiceNo . '-R' . $revision : 'revision ' . $revision;
        $description = "Corrected water bill #{$readingId} ({$document}) for account #{$memberId}";
        $reason = trim((string)($correction['reason'] ?? ''));
        if ($reason !== '') {
            $description .= ' - ' . $reason;
        }
        return $description;
    }
}

if (!function_exists('wc_water_bill_doc_no')) {
    /** The invoice number of a water reading: its global reference number, else WB-<reading id> */
    function wc_water_bill_doc_no(PDO $pdo, int $readingId): string
    {
        $ref = $pdo->prepare('SELECT global_ref_number FROM bill_readings WHERE reading_id = ?');
        $ref->execute([$readingId]);
        $no = trim((string)$ref->fetchColumn());
        return ($no !== '' && $no !== '0') ? $no : 'WB-' . $readingId;
    }
}

if (!function_exists('wc_create_water_billing_journal_from_breakdown')) {
    /**
     * Posts the invoice of a water bill from its immutable snapshot breakdown. Returns the document keys
     * (journal_head_id = transactions.id, journal_entry_id = invoice number) or null for a zero amount.
     */
    function wc_create_water_billing_journal_from_breakdown(PDO $pdo, int $readingId, int $memberId, array $billingDetails, string $billingDate, int $branchId = 0, int $segmentId = 0, int $divisionId = 0, ?array $correction = null): ?array
    {
        $amount = round((float)($billingDetails['amount_due'] ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }
        $arLines = $billingDetails['ar_lines'] ?? [];
        $incomeLines = $billingDetails['income_lines'] ?? [];
        if (empty($arLines) || empty($incomeLines)) {
            throw new Exception('Water billing cannot be posted because the global Water Billing Bill Code accounting breakdown is empty.');
        }
        $debitTotal = 0.0;
        $arAccounts = [];
        foreach ($arLines as $line) {
            $debitTotal += (float)($line['amount'] ?? 0);
            $arAccounts[(int)$line['account_id']] = true;
        }
        $creditTotal = 0.0;
        foreach ($incomeLines as $line) {
            $creditTotal += (float)($line['amount'] ?? 0);
        }
        if (round($debitTotal, 2) !== round($creditTotal, 2)) {
            throw new Exception('Water billing journal is not balanced. Debit: ' . number_format($debitTotal, 2) . ' Credit: ' . number_format($creditTotal, 2));
        }
        if (count($arAccounts) !== 1) {
            throw new Exception('A water bill posts to one Accounts Receivable account; the bill code breakdown names ' . count($arAccounts) . '.');
        }

        $revision = $correction !== null ? max(1, (int)($correction['revision'] ?? 1)) : 0;
        $baseDesc = wc_water_billing_journal_description($readingId, $memberId, $correction);
        $lines = [];
        foreach ($incomeLines as $line) {
            $lineAmount = round((float)($line['amount'] ?? 0), 2);
            if ($lineAmount <= 0) {
                continue;
            }
            $codes = !empty($line['bill_codes']) ? implode(', ', $line['bill_codes']) : 'Water Bill';
            $lines[] = ['account_id' => (int)$line['account_id'], 'amount' => $lineAmount, 'description' => mb_substr($baseDesc . ' - Revenue (' . $codes . ')', 0, 255)];
        }
        $opt = [
            'water_account_id' => $memberId,
            'txn_date' => date('Y-m-d', strtotime($billingDate)),
            'doc_no' => wc_water_bill_doc_no($pdo, $readingId),
            'ar_account_id' => (int)array_key_first($arAccounts),
            'lines' => $lines,
            'memo' => mb_substr($baseDesc, 0, 255),
            'branch_id' => $branchId, 'segment_id' => $segmentId, 'division_id' => $divisionId,
            'revision' => $revision,
            'reason' => $correction['reason'] ?? null,
        ];
        $dueDate = $pdo->prepare('SELECT due_date FROM bill_readings WHERE reading_id = ?');
        $dueDate->execute([$readingId]);
        if ($d = $dueDate->fetchColumn()) {
            $opt['due_date'] = substr((string)$d, 0, 10);
        }
        if ($revision > 0) {
            // the voided original (or the previous revision) this one replaces
            $prev = $revision === 1 ? WaterPosting::eventKey('water', $readingId) : WaterPosting::eventKey('water', $readingId, $revision - 1);
            $opt['replaces'] = $prev;
        }
        // a granted discount is shown on its own line when a discount account is mapped; otherwise the net amount
        // posts to income exactly as the former program did
        $discount = round((float)($billingDetails['discount_amount'] ?? $billingDetails['total_discount'] ?? 0), 2);
        $discountAccount = WaterPosting::defaultAccount('water_discount');
        if ($discount > 0 && $discountAccount && isset($billingDetails['gross_amount']) && round((float)$billingDetails['gross_amount'] - $discount, 2) === round($amount, 2)) {
            $opt['lines'] = [['account_id' => (int)$lines[0]['account_id'], 'amount' => round((float)$billingDetails['gross_amount'], 2), 'description' => $lines[0]['description']]];
            $opt['discount'] = ['account_id' => $discountAccount, 'amount' => $discount, 'description' => 'Discount granted on water bill #' . $readingId];
        }
        $r = WaterPosting::postBill('water', $readingId, $opt);
        return $r ? ['journal_head_id' => $r['journal_head_id'], 'journal_entry_id' => $r['journal_entry_id'], 'txn_id' => $r['txn_id'], 'event_key' => $r['event_key']] : null;
    }
}

if (!function_exists('wc_create_water_billing_journal')) {
    /** Backward-compatible fallback: a bill without a breakdown posts to the default water accounts. */
    function wc_create_water_billing_journal(PDO $pdo, int $readingId, int $memberId, float $amount, string $billingDate, int $branchId = 0, int $segmentId = 0, int $divisionId = 0): ?array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }
        $income = WaterPosting::defaultAccount('water_income');
        if (!$income) {
            throw new Exception('The default water income account is not mapped (Module Default Accounts > Water).');
        }
        $r = WaterPosting::postBill('water', $readingId, [
            'water_account_id' => $memberId, 'txn_date' => date('Y-m-d', strtotime($billingDate)), 'doc_no' => wc_water_bill_doc_no($pdo, $readingId),
            'ar_account_id' => WaterPosting::defaultAccount('water_ar'),
            'lines' => [['account_id' => $income, 'amount' => $amount, 'description' => "Posted water bill #{$readingId} for account #{$memberId} - Revenue"]],
            'memo' => "Posted water bill #{$readingId} for account #{$memberId}", 'branch_id' => $branchId, 'segment_id' => $segmentId, 'division_id' => $divisionId,
        ]);
        return $r ? ['journal_head_id' => $r['journal_head_id'], 'journal_entry_id' => $r['journal_entry_id']] : null;
    }
}

if (!function_exists('wc_assert_active_account')) {
    function wc_assert_active_account(PDO $pdo, int $accountId, string $label): int
    {
        if ($accountId <= 0) {
            throw new Exception($label . ' is not configured.');
        }
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$accountId]);
        if (!(int)$stmt->fetchColumn()) {
            throw new Exception($label . ' is missing or inactive in the Chart of Accounts.');
        }
        return $accountId;
    }
}

if (!function_exists('wc_collection_setting')) {
    function wc_collection_setting(PDO $pdo, string $key, ?string $default = null): ?string
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    }
}

if (!function_exists('wc_create_collection_journal')) {
    /**
     * Posts an official receipt. $creditLines carry one entry per receivable allocation:
     *   ['account_id' => A/R account, 'amount' => principal, 'description' => ..., 'source' => water|installment|onetime|recurring, 'source_id' => id]
     * (source/source_id are what the ledger applies the payment to; the account is checked against the bill's invoice).
     */
    function wc_create_collection_journal(PDO $pdo, string $receiptNo, int $memberId, float $baseAmount, float $penaltyAmount, float $compromiseAmount, string $paymentDate, int $branchId = 0, int $segmentId = 0, int $divisionId = 0, array $creditLines = []): ?array
    {
        $baseAmount = round($baseAmount, 2);
        $penaltyAmount = round($penaltyAmount, 2);
        $compromiseAmount = round($compromiseAmount, 2);
        $total = round($baseAmount + $penaltyAmount + $compromiseAmount, 2);
        if ($total <= 0) {
            return null;
        }
        if (wc_journal_exists($pdo, WaterPosting::collectionKey($receiptNo))) {
            throw new Exception('A collection journal already exists for this receipt.');
        }
        $operatorId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
        $cashAccountId = wc_assert_active_account(
            $pdo,
            (int)(uph_current_cash_account_id($pdo, $operatorId) ?: (WaterPosting::defaultAccount('water_cash') ?: 0)),
            'Collection cash/debit account of the signed-in cashier (User Accounts > cash account)'
        );
        if ($baseAmount > 0 && empty($creditLines)) {
            throw new Exception('Collection cannot be posted without receivable-account allocation lines.');
        }
        $allocations = [];
        $creditTotal = 0.0;
        foreach ($creditLines as $line) {
            $lineAmount = round((float)($line['amount'] ?? 0), 2);
            if ($lineAmount <= 0) {
                continue;
            }
            wc_assert_active_account($pdo, (int)($line['account_id'] ?? 0), 'Receivable account');
            if (empty($line['source']) || empty($line['source_id'])) {
                throw new Exception('A collection allocation does not name the bill it settles.');
            }
            $allocations[] = ['source' => (string)$line['source'], 'source_id' => (int)$line['source_id'], 'amount' => $lineAmount, 'description' => (string)($line['description'] ?? '')];
            $creditTotal = round($creditTotal + $lineAmount, 2);
        }
        if ($creditTotal !== $baseAmount) {
            throw new Exception('Collection accounting is not balanced: receivable credits do not equal the principal collected.');
        }
        $opt = [
            'water_account_id' => $memberId, 'txn_date' => date('Y-m-d', strtotime($paymentDate)), 'cash_account_id' => $cashAccountId,
            'allocations' => $allocations, 'penalty' => $penaltyAmount, 'compromise' => $compromiseAmount,
            'memo' => "Collection receipt {$receiptNo} for account #{$memberId}",
            'branch_id' => $branchId, 'segment_id' => $segmentId, 'division_id' => $divisionId,
        ];
        if ($penaltyAmount > 0) {
            $opt['penalty_account_id'] = wc_assert_active_account($pdo, (int)(wc_collection_setting($pdo, 'collection_penalty_account_id', '0') ?: (WaterPosting::defaultAccount('water_penalty') ?: 0)), 'Penalty income account');
        }
        if ($compromiseAmount > 0) {
            $opt['compromise_account_id'] = wc_assert_active_account($pdo, (int)(wc_collection_setting($pdo, 'collection_compromise_account_id', '0') ?: (WaterPosting::defaultAccount('water_compromise') ?: 0)), 'Compromise income account');
        }
        $r = WaterPosting::postCollection($receiptNo, $opt);
        return ['journal_head_id' => $r['journal_head_id'], 'journal_entry_id' => $r['journal_entry_id'], 'txn_ids' => $r['txn_ids'] ?? [], 'event_key' => $r['event_key']];
    }
}

if (!function_exists('wc_create_collection_reversal_journal')) {
    /** Reverses (or refunds) the accounting of a receipt: its payment and finance charges are voided and linked. */
    function wc_create_collection_reversal_journal(PDO $pdo, string $originalJournalHeadId, string $receiptNo, string $businessDate, string $reason, string $action): ?array
    {
        $r = WaterPosting::reverseCollection($receiptNo, $reason, $action, $businessDate);
        return ['journal_head_id' => $r['journal_head_id'], 'journal_entry_id' => $r['journal_entry_id'], 'voided_txn_ids' => $r['voided_txn_ids']];
    }
}
