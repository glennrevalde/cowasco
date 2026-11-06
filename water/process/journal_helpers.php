<?php
/**
 * Former low-level journal writers of the water program.
 *
 * The legacy voucher tables (legacy_journalvoucher, legacy_journalentries) are read-only history now. Every water
 * event posts through app/WaterPosting.php (see collectionAccountingHelpers.php, billAccountingCorrectionHelpers.php,
 * oneTimeBillingInventoryHelpers.php, installmentBillingInventoryHelpers.php, recurringBillingProcess.php,
 * inventoryIssuanceAccountingHelpers.php). These two functions stop any code path that still tries to write a
 * legacy voucher, so nothing can be posted twice or outside the ledger.
 */

if (!function_exists('generate_uuid_v4')) {
    function generate_uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('get_next_jv_entry_id')) {
    function get_next_jv_entry_id(PDO $pdo): string
    {
        throw new RuntimeException('Legacy journal numbering is retired: documents are numbered by the ledger (WaterPosting).');
    }
}

if (!function_exists('create_journal_voucher')) {
    function create_journal_voucher(PDO $pdo, $transactionDate, int $branchId = 0, int $segmentId = 0, int $divisionId = 0): array
    {
        throw new RuntimeException('Legacy journal vouchers are retired: this event must post through WaterPosting.');
    }
}

if (!function_exists('insert_journal_entry_line')) {
    function insert_journal_entry_line(PDO $pdo, array $data): void
    {
        throw new RuntimeException('Legacy journal lines are retired: this event must post through WaterPosting.');
    }
}

if (!function_exists('create_purchase_journal_entries')) {
    function create_purchase_journal_entries(PDO $pdo, ...$args): array
    {
        throw new RuntimeException('Purchases are recorded on Vendors > Enter Bills / Receive Items (the cooperative inventory and payables).');
    }
}

if (!function_exists('create_void_purchase_journal_entries')) {
    function create_void_purchase_journal_entries(PDO $pdo, ...$args): array
    {
        throw new RuntimeException('Purchases are voided on the Vendors pages (the cooperative inventory and payables).');
    }
}
