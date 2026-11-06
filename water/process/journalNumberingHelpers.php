<?php
/**
 * Former journal-entry numbering of the water program. Documents are numbered by the ledger now: a bill's invoice
 * carries the bill's own number, a collection carries its official receipt number (app/WaterPosting.php).
 * The sequence row is kept only so the setup-readiness repair of older installations stays harmless.
 */

if (!function_exists('jv_ensure_sequence_row')) {
    function jv_ensure_sequence_row(PDO $pdo): void
    {
        // nothing to prepare: the ledger numbers its documents
    }
}

if (!function_exists('jv_highest_existing_entry_id')) {
    function jv_highest_existing_entry_id(PDO $pdo): int
    {
        return 0;
    }
}

if (!function_exists('jv_allocate_entry_id')) {
    function jv_allocate_entry_id(PDO $pdo): string
    {
        throw new RuntimeException('Legacy journal numbering is retired: documents are numbered by the ledger (WaterPosting).');
    }
}
