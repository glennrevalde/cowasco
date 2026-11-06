<?php
require_once __DIR__ . '/waterPipeSizeHelpers.php';
/**
 * Server-authoritative meter-reading workflow.
 *
 * Previous readings, record ownership, period state, and bill immutability are
 * enforced here under database locks. Browser-supplied previous readings and
 * override flags are never trusted.
 */

require_once __DIR__ . '/billingHelpers.php';
require_once __DIR__ . '/billingPeriodHelpers.php';
require_once __DIR__ . '/waterRateBillingHelpers.php';
require_once __DIR__ . '/waterBillingSnapshotHelpers.php';
require_once __DIR__ . '/memberAddressHelpers.php';

if (!function_exists('wr_parse_whole_reading')) {
    function wr_parse_whole_reading($value, string $label = 'Present reading'): int
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }
        $raw = trim((string)$value);
        if (!preg_match('/^\d+$/', $raw)) {
            throw new InvalidArgumentException("{$label} must be a non-negative whole number.");
        }
        if (strlen($raw) > 10 || (float)$raw > 2147483647) {
            throw new InvalidArgumentException("{$label} is too large.");
        }
        return (int)$raw;
    }
}

if (!function_exists('wr_lock_open_period')) {
    function wr_lock_open_period(PDO $pdo, int $periodId): array
    {
        if ($periodId <= 0) {
            throw new InvalidArgumentException('A valid billing period is required.');
        }
        $period = bp_fetch_for_update($pdo, $periodId);
        bp_assert_open($period, 'accept meter readings');
        return $period;
    }
}

if (!function_exists('wr_lock_active_member')) {
    function wr_lock_active_member(PDO $pdo, int $memberId): array
    {
        if ($memberId <= 0) {
            throw new InvalidArgumentException('A valid member is required.');
        }
        $stmt = $pdo->prepare('SELECT pkey, account_number, rc_id, pipe_size_id, status, zone FROM water_accounts WHERE pkey = ? FOR UPDATE');
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || strtoupper((string)($member['status'] ?? '')) !== 'A') {
            throw new RuntimeException("Member #{$memberId} was not found or is inactive.");
        }
        if ((int)($member['rc_id'] ?? 0) <= 0) {
            throw new RuntimeException("Member #{$memberId} has no assigned water rate code.");
        }
        if ((int)($member['pipe_size_id'] ?? 0) <= 0) {
            throw new RuntimeException("Member #{$memberId} has no assigned pipe size.");
        }
        water_pipe_size_require_active($pdo, (int)$member['pipe_size_id'], true);
        return $member;
    }
}

if (!function_exists('wr_member_matches_filter')) {
    function wr_member_matches_filter(PDO $pdo, int $memberId, ?array $filter): bool
    {
        if (!$filter) {
            return true;
        }
        $type = strtolower(trim((string)($filter['type'] ?? '')));
        $value = trim((string)($filter['value'] ?? ''));
        if ($value === '' || !in_array($type, ['street', 'zone'], true)) {
            return false;
        }

        if ($type === 'zone') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM water_accounts WHERE pkey = ? AND status = 'A' AND TRIM(CAST(zone AS CHAR)) = ?");
            $stmt->execute([$memberId, $value]);
            return (int)$stmt->fetchColumn() === 1;
        }

        $primaryAddressTypeId = member_primary_address_type_id($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM memberaddress ma
            JOIN addresses a ON a.pkey = ma.address_key
            WHERE ma.member_key = ?
              AND ma.address_type_key = ?
              AND ma.status = 'A'
              AND LOWER(TRIM(a.street)) = LOWER(TRIM(?))
        ");
        $stmt->execute([$memberId, $primaryAddressTypeId, $value]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('wr_assert_no_future_reading')) {
    function wr_assert_no_future_reading(PDO $pdo, int $memberId, array $period): void
    {
        $stmt = $pdo->prepare("
            SELECT br.reading_id, bp.bp_code, bp.start_date
            FROM bill_readings br
            JOIN bill_periods bp ON bp.period_id = br.period_id
            WHERE br.member_id = ?
              AND bp.start_date > ?
              AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
            ORDER BY bp.start_date ASC, bp.period_id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$memberId, $period['start_date']]);
        $future = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($future) {
            throw new RuntimeException(
                "A later reading already exists in period {$future['bp_code']} ({$future['start_date']}). " .
                'Earlier readings cannot be inserted or changed after the chain has advanced.'
            );
        }
    }
}

if (!function_exists('wr_derive_previous_reading')) {
    function wr_derive_previous_reading(PDO $pdo, int $memberId, array $period): array
    {
        $stmt = $pdo->prepare("
            SELECT br.reading_id, br.period_id, br.pres_reading, bp.bp_code, bp.start_date, bp.end_date
            FROM bill_readings br
            JOIN bill_periods bp ON bp.period_id = br.period_id
            WHERE br.member_id = ?
              AND bp.start_date < ?
              AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
            ORDER BY bp.start_date DESC, bp.end_date DESC, bp.period_id DESC, br.reading_id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$memberId, $period['start_date']]);
        $previous = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'reading' => $previous ? (int)$previous['pres_reading'] : 0,
            'source' => $previous ?: null,
        ];
    }
}

if (!function_exists('wr_fetch_current_for_update')) {
    function wr_fetch_current_for_update(PDO $pdo, int $memberId, int $periodId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM bill_readings WHERE member_id = ? AND period_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$memberId, $periodId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('wr_assert_draft_mutable')) {
    function wr_assert_draft_mutable(array $reading): void
    {
        if ((int)($reading['is_billed'] ?? 0) === 1) {
            throw new RuntimeException('This reading is already billed and is immutable. Use the controlled billing-adjustment workflow instead.');
        }
        if ((int)($reading['is_paid'] ?? 0) === 1) {
            throw new RuntimeException('This reading is already paid and cannot be edited.');
        }
        if (strtolower((string)($reading['bill_lifecycle_status'] ?? 'active')) !== 'active' || !empty($reading['deleted_at'])) {
            throw new RuntimeException('This reading is not active and cannot be edited.');
        }
    }
}

if (!function_exists('wr_delete_draft_reading')) {
    /**
     * Deletes an unposted reading (a draft) inside an already-open caller transaction, the period locked open by the
     * caller (version 46: Reading Entry > Recorded Readings > Delete). A posted (billed) or paid reading stays: it is
     * corrected through Billing Adjustments. A reading followed by a later reading of the same member stays too: the
     * later one's previous reading was taken from it. Returns the row as it was.
     */
    function wr_delete_draft_reading(PDO $pdo, array $period, int $readingId, string $reason, int $userId): array
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5) {
            throw new InvalidArgumentException('Give a reason of at least 5 characters for deleting the reading.');
        }
        if ($readingId <= 0) {
            throw new InvalidArgumentException('Choose the reading to delete.');
        }
        $stmt = $pdo->prepare('SELECT * FROM bill_readings WHERE reading_id = ? AND period_id = ? LIMIT 1');
        $stmt->execute([$readingId, (int)$period['period_id']]);
        $probe = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$probe) {
            throw new RuntimeException('The reading is no longer on file for this period. Refresh the list.');
        }
        $memberId = (int)$probe['member_id'];
        // the member first, then the reading: the order the save of a reading locks them in
        $pdo->prepare('SELECT pkey FROM water_accounts WHERE pkey = ? FOR UPDATE')->execute([$memberId]);
        $stmt = $pdo->prepare('SELECT * FROM bill_readings WHERE reading_id = ? AND period_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$readingId, (int)$period['period_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['member_id'] !== $memberId) {
            throw new RuntimeException('The reading changed before it could be deleted. Refresh the list.');
        }
        wr_assert_draft_mutable($row);
        wr_assert_no_future_reading($pdo, $memberId, $period);

        $del = $pdo->prepare("DELETE FROM bill_readings
                               WHERE reading_id = ? AND period_id = ? AND is_billed = 0 AND is_paid = 0
                                 AND COALESCE(bill_lifecycle_status, 'active') = 'active'");
        $del->execute([$readingId, (int)$period['period_id']]);
        if ($del->rowCount() !== 1) {
            throw new RuntimeException('The reading was posted or changed before it could be deleted. Refresh the list.');
        }
        logAudit($pdo, $userId, 'DELETE_METER_READING', 'bill_readings', (string)$readingId, water_snapshot_json([
            'reason' => $reason,
            'period_id' => (int)$period['period_id'],
            'period_code' => (string)($period['bp_code'] ?? ''),
            'member_id' => $memberId,
            'global_ref_number' => (int)($row['global_ref_number'] ?? 0),
            'previous_reading' => (int)$row['prev_reading'],
            'present_reading' => (int)$row['pres_reading'],
            'consumption' => (int)$row['consumption'],
            'amount_due' => (float)($row['amount_due'] ?? 0),
            'entry_mode' => (string)($row['entry_mode'] ?? ''),
            'encoded_by' => (string)($row['encoded_by'] ?? ''),
            'snapshot_hash' => (string)($row['snapshot_hash'] ?? ''),
        ]));
        return $row;
    }
}

if (!function_exists('wr_existing_snapshot_details')) {
    function wr_existing_snapshot_details(array $reading): ?array
    {
        if ((int)($reading['snapshot_version'] ?? 0) !== 1 || empty($reading['snapshot_hash'])) {
            return null;
        }
        return water_snapshot_details(water_snapshot_from_reading($reading));
    }
}

if (!function_exists('wr_save_draft_reading')) {
    /**
     * Save one reading inside an already-open caller transaction.
     */
    function wr_save_draft_reading(
        PDO $pdo,
        array $period,
        int $memberId,
        $presentInput,
        string $entryMode,
        string $encodedBy,
        int $userId,
        ?int $expectedReadingId = null,
        ?array $batchFilter = null
    ): array {
        $entryMode = strtoupper($entryMode);
        if (!in_array($entryMode, ['INDIVIDUAL', 'BATCH'], true)) {
            throw new InvalidArgumentException('Invalid meter-reading entry mode.');
        }

        wr_lock_active_member($pdo, $memberId);
        if (!wr_member_matches_filter($pdo, $memberId, $batchFilter)) {
            throw new RuntimeException("Member #{$memberId} does not belong to the selected batch filter.");
        }
        wr_assert_no_future_reading($pdo, $memberId, $period);

        $existing = wr_fetch_current_for_update($pdo, $memberId, (int)$period['period_id']);
        if ($expectedReadingId !== null) {
            if (!$existing || (int)$existing['reading_id'] !== $expectedReadingId) {
                throw new RuntimeException("Reading ownership check failed for member #{$memberId}.");
            }
        }
        if ($existing) {
            wr_assert_draft_mutable($existing);
        }

        $previousInfo = wr_derive_previous_reading($pdo, $memberId, $period);
        $previous = (int)$previousInfo['reading'];
        $present = wr_parse_whole_reading($presentInput);
        if ($present < $previous) {
            throw new InvalidArgumentException(
                "Present reading {$present} cannot be lower than the server-derived previous reading {$previous}."
            );
        }
        $consumption = $present - $previous;
        $changed = !$existing
            || (int)$existing['prev_reading'] !== $previous
            || (int)$existing['pres_reading'] !== $present
            || (int)$existing['consumption'] !== $consumption;

        $globalRef = $existing ? (int)($existing['global_ref_number'] ?? 0) : 0;
        if ($globalRef <= 0) {
            $globalRef = generateGlobalRef($pdo);
        }

        if ($existing) {
            $stmt = $pdo->prepare("
                UPDATE bill_readings
                SET prev_reading = ?,
                    pres_reading = ?,
                    consumption = ?,
                    global_ref_number = ?,
                    is_edited_override = ?,
                    entry_mode = ?,
                    encoded_by = ?,
                    updated_at = NOW()
                WHERE reading_id = ?
                  AND period_id = ?
                  AND member_id = ?
                  AND is_billed = 0
                  AND is_paid = 0
                  AND COALESCE(bill_lifecycle_status, 'active') = 'active'
            ");
            $stmt->execute([
                $previous,
                $present,
                $consumption,
                $globalRef,
                $changed ? 1 : (int)($existing['is_edited_override'] ?? 0),
                $entryMode,
                $encodedBy,
                (int)$existing['reading_id'],
                (int)$period['period_id'],
                $memberId,
            ]);
            if ($stmt->rowCount() !== 1 && $changed) {
                throw new RuntimeException('The reading changed state before it could be updated.');
            }
            $readingId = (int)$existing['reading_id'];
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO bill_readings
                    (global_ref_number, period_id, member_id, prev_reading, pres_reading, consumption,
                     is_billed, is_paid, amount_due, is_edited_override, entry_mode, encoded_by, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, 0, 0, 0.00, 0, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $globalRef,
                (int)$period['period_id'],
                $memberId,
                $previous,
                $present,
                $consumption,
                $entryMode,
                $encodedBy,
            ]);
            $readingId = (int)$pdo->lastInsertId();
        }

        $details = null;
        if (!$changed && $existing) {
            $details = wr_existing_snapshot_details($existing);
        }
        if (!$details) {
            $details = compute_water_billing_details($pdo, $memberId, $consumption, true);
            $snapshot = water_snapshot_build($details);
            water_snapshot_persist($pdo, $readingId, $snapshot);
        } else {
            $snapshot = water_snapshot_from_reading($existing);
        }

        $action = $existing ? 'UPDATE_METER_READING' : 'CREATE_METER_READING';
        logAudit($pdo, $userId, $action, 'bill_readings', (string)$readingId, water_snapshot_json([
            'global_ref_number' => $globalRef,
            'period_id' => (int)$period['period_id'],
            'member_id' => $memberId,
            'previous_reading_server_derived' => $previous,
            'previous_source' => $previousInfo['source'],
            'present_reading' => $present,
            'consumption' => $consumption,
            'base_charge' => (float)($details['base_charge'] ?? 0),
            'discount_amount' => (float)($details['discount_amount'] ?? 0),
            'amount_due' => (float)($details['amount_due'] ?? 0),
            'snapshot_hash' => (string)($snapshot['snapshot_hash'] ?? ''),
            'entry_mode' => $entryMode,
            'encoded_by' => $encodedBy,
        ]));

        return [
            'reading_id' => $readingId,
            'global_ref_number' => $globalRef,
            'member_id' => $memberId,
            'prev_reading' => $previous,
            'pres_reading' => $present,
            'consumption' => $consumption,
            'base_charge' => (float)($details['base_charge'] ?? 0),
            'discount_amount' => (float)($details['discount_amount'] ?? 0),
            'amount_due' => (float)($details['amount_due'] ?? 0),
            'snapshot_hash' => (string)($snapshot['snapshot_hash'] ?? ''),
            'created' => !$existing,
            'changed' => $changed,
        ];
    }
}
