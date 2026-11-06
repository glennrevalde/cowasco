<?php
/**
 * process/sysSequenceHelpers.php
 *
 * Generic helpers for your uncle's accounting-system sequence table:
 *
 * syssequence
 * - SeqName
 * - SeqDesc
 * - SeqType
 * - SeqPrefixCode
 * - SeqCurValue
 * - SeqStep
 * - SeqStatus
 *
 * Important rule:
 * For numeric primary keys such as members.pkey, use the numeric part only.
 */

if (!function_exists('sysseq_table_exists')) {
    function sysseq_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('sysseq_column_exists')) {
    function sysseq_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('sysseq_numeric_part')) {
    function sysseq_numeric_part($value): int
    {
        $value = trim((string)($value ?? ''));

        if ($value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        $digits = preg_replace('/[^0-9]/', '', $value);

        return $digits === '' ? 0 : (int)$digits;
    }
}

if (!function_exists('sysseq_next_numeric')) {
    function sysseq_next_numeric(PDO $pdo, string $seqName, int $minimumExistingValue = 0): int
    {
        if (!sysseq_table_exists($pdo, 'syssequence')) {
            throw new Exception('syssequence table was not found. Cannot generate official sequence number.');
        }

        /*
         * SELECT ... FOR UPDATE is important.
         * It locks the SysMemberIDSeq row while the new member is being created,
         * preventing two operators from issuing the same member ID.
         */
        $stmt = $pdo->prepare("
            SELECT *
            FROM syssequence
            WHERE SeqName = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$seqName]);
        $seq = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$seq) {
            // A new installation (no former water database migrated in) has no sequence rows yet: the first number
            // creates its row, starting after the highest key already in use. A migrated database brings its own.
            $known = [
                'SysMemberIDSeq' => 'Official numeric water-service account identifier',
            ];
            if (!isset($known[$seqName])) {
                throw new Exception("Sequence '{$seqName}' was not found in syssequence.");
            }
            $pdo->prepare("INSERT INTO syssequence (SeqName, SeqDesc, SeqType, SeqPrefixCode, SeqCurValue, SeqStep, SeqStatus)
                           VALUES (?, ?, 1, '', ?, 1, 'A')")
                ->execute([$seqName, $known[$seqName], (string)max(0, $minimumExistingValue)]);
            $stmt->execute([$seqName]);
            $seq = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$seq) {
                throw new Exception("Sequence '{$seqName}' could not be created in syssequence.");
            }
        }

        $status = strtoupper(trim((string)($seq['SeqStatus'] ?? 'A')));
        if ($status !== '' && $status !== 'A' && $status !== '1') {
            throw new Exception("Sequence '{$seqName}' is inactive.");
        }

        $currentValue = sysseq_numeric_part($seq['SeqCurValue'] ?? 0);
        $step = sysseq_numeric_part($seq['SeqStep'] ?? 1);
        if ($step <= 0) {
            $step = 1;
        }

        /*
         * If syssequence is behind the actual table, self-correct by starting
         * from the larger value. This prevents duplicate member IDs.
         */
        $baseValue = max($currentValue, $minimumExistingValue);
        $nextValue = $baseValue + $step;

        $pkey = $seq['pkey'] ?? null;

        if ($pkey !== null && $pkey !== '') {
            $update = $pdo->prepare("
                UPDATE syssequence
                SET SeqCurValue = ?
                WHERE pkey = ?
            ");
            $update->execute([$nextValue, $pkey]);
        } else {
            $update = $pdo->prepare("
                UPDATE syssequence
                SET SeqCurValue = ?
                WHERE SeqName = ?
            ");
            $update->execute([$nextValue, $seqName]);
        }

        return $nextValue;
    }
}
