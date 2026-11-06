<?php
/**
 * Water-bill posting for one exact open billing period.
 *
 * Posting consumes the immutable reading snapshot. It never recalculates a
 * snapshotted draft from current tariffs or discounts.
 */

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
// GET ?action=summary is a read-only preview; every state-changing call is a JSON POST.
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    business_require_mutation(getDB(), '../views/generate_soa.php', ['action' => 'billing', 'json' => true]);
}
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/billingHelpers.php';
require_once '../common/billingPeriodHelpers.php';
require_once '../common/waterRateBillingHelpers.php';
require_once '../common/waterBillingSnapshotHelpers.php';
require_once 'collectionAccountingHelpers.php';
require_once __DIR__ . '/organizationUnitHelpers.php';

require_once __DIR__ . '/../database/config.php';
header('Content-Type: application/json');

$pdo = getDB();
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? '')));
$periodId = (int)($_GET['period_id'] ?? $input['period_id'] ?? 0);
$filter = strtolower(trim((string)($_GET['filter'] ?? $input['filter'] ?? 'all')));

function posting_json_error(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function posting_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed instanceof DateTimeImmutable
        && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
        && $parsed->format('Y-m-d') === $date;
}

function posting_org_unit_exists(PDO $pdo, string $table, int $id): bool
{
    if ($id <= 0) return false;
    if ($table === 'branches') {
        $stmt = $pdo->prepare('SELECT pkey FROM branches WHERE pkey = ? AND Deleted = 0 FOR UPDATE');
    } elseif ($table === 'ic_segments') {
        $stmt = $pdo->prepare("SELECT pkey FROM ic_segments WHERE pkey = ? AND status = 'A' FOR UPDATE");
    } elseif ($table === 'ic_divisions') {
        $stmt = $pdo->prepare("SELECT pkey FROM ic_divisions WHERE pkey = ? AND status = 'A' FOR UPDATE");
    } else {
        return false;
    }
    $stmt->execute([$id]);
    return $stmt->fetchColumn() !== false;
}

function posting_validate_org_units(PDO $pdo, int $branchId, int $segmentId, int $divisionId): void
{
    $errors = [];
    if (!posting_org_unit_exists($pdo, 'branches', $branchId)) $errors[] = 'Select a valid active Branch.';
    if (!posting_org_unit_exists($pdo, 'ic_segments', $segmentId)) $errors[] = 'Select a valid active Segment.';
    if (!posting_org_unit_exists($pdo, 'ic_divisions', $divisionId)) $errors[] = 'Select a valid active Division.';
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
}

function posting_snapshot_columns(): string
{
    return "snapshot_version, rate_code_id_snapshot, rate_code_snapshot, tariff_snapshot,
            discount_snapshot, calculation_snapshot, base_charge_snapshot,
            discount_amount_snapshot, snapshot_hash, snapshot_created_at";
}

if ($periodId <= 0) {
    posting_json_error('No valid billing period was provided.', 400);
}

try {
    if ($action === 'summary') {
        $stmtPeriod = $pdo->prepare('SELECT period_id, bp_code, start_date, end_date, status FROM bill_periods WHERE period_id = ?');
        $stmtPeriod->execute([$periodId]);
        $period = $stmtPeriod->fetch(PDO::FETCH_ASSOC);
        if (!$period) posting_json_error('Billing period was not found.', 404);

        $stmtStats = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(CASE WHEN is_billed = 0 THEN 1 ELSE 0 END), 0) AS draft,
                   COALESCE(SUM(CASE WHEN is_billed = 1 THEN 1 ELSE 0 END), 0) AS billed
            FROM bill_readings
            WHERE period_id = ?
              AND COALESCE(bill_lifecycle_status, 'active') = 'active'
        ");
        $stmtStats->execute([$periodId]);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmtRows = $pdo->prepare("
            SELECT br.reading_id, br.member_id, br.consumption, br.amount_due,
                   br.snapshot_version, br.rate_code_snapshot, br.tariff_snapshot,
                   br.discount_snapshot, br.calculation_snapshot, br.snapshot_hash,
                   br.snapshot_created_at,
                   m.lastname, m.firstname, m.account_number,
                   'Water Bill' AS type,
                   CONCAT('Cons: ', br.consumption, ' m³') AS details,
                   br.due_date
            FROM bill_readings br
            JOIN water_accounts m ON m.pkey = br.member_id
            WHERE br.period_id = ?
              AND br.is_billed = 0
              AND br.is_paid = 0
              AND COALESCE(br.bill_lifecycle_status, 'active') = 'active'
            ORDER BY m.lastname, m.firstname, br.reading_id
        ");
        $stmtRows->execute([$periodId]);
        $waterList = [];
        $warnings = [];
        foreach ($stmtRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['due_date'] = substr((string)$period['end_date'], 0, 10);
            $row['amount'] = water_rate_scaled_to_decimal(
                water_rate_decimal_to_scaled_int($row['amount_due'], 2),
                2
            );
            $row['snapshot_status'] = 'legacy_missing';
            if ((int)$row['snapshot_version'] === 1 && !empty($row['snapshot_hash'])) {
                try {
                    $snapshot = water_snapshot_from_reading($row);
                    $details = water_snapshot_details($snapshot);
                    $row['amount'] = water_rate_scaled_to_decimal(
                        water_rate_decimal_to_scaled_int($details['amount_due'], 2),
                        2
                    );
                    $row['snapshot_status'] = 'verified';
                } catch (Throwable $snapshotError) {
                    $row['snapshot_status'] = 'invalid';
                    $row['setup_error'] = $snapshotError->getMessage();
                    $warnings[] = "Reading #{$row['reading_id']}: " . $snapshotError->getMessage();
                }
            } else {
                $warnings[] = "Reading #{$row['reading_id']} is a legacy draft without an immutable snapshot and cannot be posted until it is reviewed and backfilled.";
            }
            $waterList[] = $row;
        }

        // Other receivables are informational only. They are never attached to
        // or mutated by this water-period posting command.
        $otherCurrent = [];
        $otherUpcoming = [];
        $periodEndDateTime = (string)$period['end_date'];
        $periodEnd = substr($periodEndDateTime, 0, 10);
        $otherQueries = [
            "SELECT m.pkey AS member_id, m.lastname, m.firstname, m.account_number, 'Installment' AS type,
                    c.code AS details, GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) AS amount, s.due_date
             FROM installment_schedules s
             JOIN installment_bills b ON b.installment_id = s.installment_id
             JOIN water_accounts m ON m.pkey = b.member_id
             JOIN bill_codes c ON c.code_id = b.bill_code_id
             WHERE s.status IN ('pending', 'partial')
               AND GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) > 0
               AND b.status = 'active'
               AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'",
            "SELECT m.pkey AS member_id, m.lastname, m.firstname, m.account_number, 'One-Time' AS type,
                    c.code AS details, GREATEST(0, b.total_amount - b.amount_paid) AS amount, b.due_date
             FROM one_time_bills b
             JOIN water_accounts m ON m.pkey = b.member_id
             JOIN bill_codes c ON c.code_id = b.bill_code_id
             WHERE b.status = 'unpaid'
               AND GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) > 0
               AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'",
            "SELECT m.pkey AS member_id, m.lastname, m.firstname, m.account_number, 'Recurring' AS type,
                    c.code AS details,
                    GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)) AS amount,
                    rb.due_date
             FROM recurring_bills rb
             JOIN water_accounts m ON m.pkey = rb.member_id
             JOIN bill_codes c ON c.code_id = rb.bill_code_id
             WHERE rb.status IN ('unpaid', 'overdue')
               AND GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)) > 0
               AND COALESCE(rb.bill_lifecycle_status, 'active') = 'active'",
        ];
        foreach ($otherQueries as $sql) {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (water_rate_decimal_to_scaled_int($row['amount'] ?? 0, 2) <= 0) continue;
                if ((string)$row['due_date'] <= $periodEnd) $otherCurrent[] = $row;
                else $otherUpcoming[] = $row;
            }
        }

        $currentList = array_merge($waterList, $otherCurrent);
        usort($currentList, static fn($a, $b) => strcmp(($a['lastname'] ?? '') . ($a['firstname'] ?? ''), ($b['lastname'] ?? '') . ($b['firstname'] ?? '')));
        usort($otherUpcoming, static fn($a, $b) => strcmp(($a['lastname'] ?? '') . ($a['firstname'] ?? ''), ($b['lastname'] ?? '') . ($b['firstname'] ?? '')));

        echo json_encode([
            'success' => true,
            'period_status' => strtolower((string)$period['status']),
            'period_end' => $periodEnd,
            'period_end_datetime' => $periodEndDateTime,
            'water_draft' => (int)($stats['draft'] ?? 0),
            'water_billed' => (int)($stats['billed'] ?? 0),
            'draft_list' => $currentList,
            'upcoming_list' => $otherUpcoming,
            'setup_errors' => array_values(array_unique($warnings)),
        ]);
        exit;
    }

    if ($action !== 'post') {
        posting_json_error('Invalid posting action.', 400);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        posting_json_error('Posting requires POST.', 405);
    }
    if (!in_array($filter, ['all', 'water', 'other'], true)) {
        posting_json_error('Invalid posting filter.');
    }
    if ($filter === 'other') {
        echo json_encode([
            'success' => true,
            'posted_count' => 0,
            'message' => 'Other receivables remain in their own modules. This command posts water readings only.',
        ]);
        exit;
    }

    $billingDate = trim((string)($input['billing_date'] ?? ''));
    $termDays = (int)($input['term_days'] ?? 0);
    // the class of the posting (Chart of Accounts > Class List) replaces the former branch / segment / division
    $classId = water_required_class($input['class_id'] ?? null, 'the water bills');
    $branchId = 0;
    $segmentId = 0;
    $divisionId = 0;
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) throw new RuntimeException('Your session is missing a valid operator identifier.');
    if (!posting_valid_date($billingDate)) throw new InvalidArgumentException('Invalid billing date.');
    if ($termDays < 1 || $termDays > 3650) throw new InvalidArgumentException('Term days must be between 1 and 3650.');

    $pdo->beginTransaction();
    $period = bp_fetch_for_update($pdo, $periodId);
    bp_assert_open($period, 'post bills');

    $periodEndAt = new DateTimeImmutable((string)$period['end_date']);
    $now = new DateTimeImmutable('now');
    if ($now < $periodEndAt) {
        throw new InvalidArgumentException('This billing period has not ended yet. Water bills can be posted only after the configured period end date and time.');
    }

    $billingDay = DateTimeImmutable::createFromFormat('!Y-m-d', $billingDate);
    $today = new DateTimeImmutable('today');
    if (!$billingDay instanceof DateTimeImmutable) {
        throw new InvalidArgumentException('Invalid billing date.');
    }
    if ($billingDay > $today) {
        throw new InvalidArgumentException('Future billing dates are not allowed.');
    }
    if ($billingDate < $periodEndAt->format('Y-m-d')) {
        throw new InvalidArgumentException('Billing date cannot be earlier than the period end date.');
    }
    // Authoritative rule: Due Date = Billing Period End Date + Term Days.
    // Never derive it from the bill/posting date and never trust a client-edited date.
    $calculatedDueDate = (new DateTimeImmutable((string)$period['end_date']))
        ->modify('+' . $termDays . ' days')
        ->format('Y-m-d');

    // A historical/late posting may happen after the original due date.
    // Do not reject it: the authoritative due date remains Period End + Term,
    // and the resulting bill is immediately due/overdue under collection rules.
    $latePosting = $billingDate > $calculatedDueDate;

    // Ignore any legacy client-supplied due_date value. The server-calculated
    // date above is authoritative, which avoids browser-timezone mismatches.

    $stmtDrafts = $pdo->prepare("
        SELECT br.*, m.status AS member_status
        FROM bill_readings br
        JOIN water_accounts m ON m.pkey = br.member_id
        WHERE br.period_id = ?
          AND br.is_billed = 0
          AND br.is_paid = 0
          AND COALESCE(br.bill_lifecycle_status, 'active') = 'active'
        ORDER BY br.reading_id
        FOR UPDATE
    ");
    $stmtDrafts->execute([$periodId]);
    $drafts = $stmtDrafts->fetchAll(PDO::FETCH_ASSOC);
    if (!$drafts) {
        throw new RuntimeException('No pending water bills remain for this period. Already-posted bills are excluded and cannot be posted again.');
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE bill_readings
        SET is_billed = 1,
            billing_date = ?,
            due_date = ?,
            term_days = ?,
            amount_due = ?,
            billing_journal_voucher_id = ?,
            branch_id = ?, segment_id = ?, division_id = ?,
            updated_at = NOW()
        WHERE reading_id = ?
          AND period_id = ?
          AND is_billed = 0
          AND is_paid = 0
          AND snapshot_hash = ?
          AND COALESCE(bill_lifecycle_status, 'active') = 'active'
    ");

    $stampClass = water_class_columns() ? $pdo->prepare('UPDATE bill_readings SET class_id = ? WHERE reading_id = ?') : null;
    $postedCount = 0;
    $zeroAmountCount = 0;
    $postedCents = 0;
    foreach ($drafts as $draft) {
        if (strtoupper((string)$draft['member_status']) !== 'A') {
            throw new RuntimeException("Member #{$draft['member_id']} is inactive. Resolve the account before posting.");
        }

        if ((int)($draft['snapshot_version'] ?? 0) !== 1 || empty($draft['snapshot_hash'])) {
            throw new RuntimeException(
                "Reading #{$draft['reading_id']} is a legacy draft without an immutable snapshot. " .
                'Run the supplied snapshot backfill in maintenance mode or re-save and review the draft before posting.'
            );
        }

        $snapshot = water_snapshot_from_reading($draft);
        $details = water_snapshot_details($snapshot);
        if ((int)$details['consumption'] !== (int)$draft['consumption']) {
            throw new RuntimeException("Reading #{$draft['reading_id']} consumption no longer matches its immutable snapshot.");
        }
        $amountDueCents = water_rate_decimal_to_scaled_int($details['amount_due'], 2);
        $amountDue = water_rate_scaled_to_decimal($amountDueCents, 2);
        if (water_rate_decimal_to_scaled_int($draft['amount_due'], 2) !== $amountDueCents) {
            throw new RuntimeException("Reading #{$draft['reading_id']} amount does not match its immutable snapshot.");
        }

        $globalRef = (int)($draft['global_ref_number'] ?? 0);
        if ($globalRef <= 0) {
            $globalRef = generateGlobalRef($pdo);
            $assignRef = $pdo->prepare('UPDATE bill_readings SET global_ref_number = ? WHERE reading_id = ? AND (global_ref_number IS NULL OR global_ref_number = 0)');
            $assignRef->execute([$globalRef, (int)$draft['reading_id']]);
            if ($assignRef->rowCount() !== 1) throw new RuntimeException('Could not assign a stable global reference number.');
        }

        $journalVoucherId = null;
        if ($amountDueCents > 0) {
            if (wc_journal_exists($pdo, 'WBILL-' . (int)$draft['reading_id'])) {
                throw new RuntimeException("Reading #{$draft['reading_id']} already has a billing journal but is still marked draft.");
            }
            $journal = WaterPosting::withClass($classId, fn() => wc_create_water_billing_journal_from_breakdown(
                $pdo,
                (int)$draft['reading_id'],
                (int)$draft['member_id'],
                $details,
                $billingDate,
                $branchId,
                $segmentId,
                $divisionId
            ));
            $journalVoucherId = $journal['journal_head_id'] ?? null;
            if (!$journalVoucherId) throw new RuntimeException("Billing journal was not created for reading #{$draft['reading_id']}.");
        } else {
            $zeroAmountCount++;
        }

        $stmtUpdate->execute([
            $billingDate,
            $calculatedDueDate,
            $termDays,
            $amountDue,
            $journalVoucherId,
            $branchId,
            $segmentId,
            $divisionId,
            (int)$draft['reading_id'],
            $periodId,
            (string)$draft['snapshot_hash'],
        ]);
        if ($stmtUpdate->rowCount() !== 1) {
            throw new RuntimeException("Reading #{$draft['reading_id']} changed state before posting completed.");
        }
        if ($stampClass) {
            $stampClass->execute([$classId, (int)$draft['reading_id']]);
        }

        logAudit($pdo, $userId, 'POST_WATER_BILL', 'bill_readings', (string)$draft['reading_id'], json_encode([
            'global_ref_number' => $globalRef,
            'period_id' => $periodId,
            'billing_date' => $billingDate,
            'due_date' => $calculatedDueDate,
            'term_days' => $termDays,
            'amount_due' => $amountDue,
            'snapshot_hash' => $draft['snapshot_hash'],
            'zero_amount_bill' => $amountDueCents <= 0,
            'journal_voucher_id' => $journalVoucherId,
            'class_id' => $classId,
        ], JSON_UNESCAPED_SLASHES));

        $postedCount++;
        $postedCents += $amountDueCents;
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'posted_count' => $postedCount,
        'zero_amount_count' => $zeroAmountCount,
        'posted_amount' => water_rate_scaled_to_float($postedCents, 2),
        'billing_date' => $billingDate,
        'due_date' => $calculatedDueDate,
        'term_days' => $termDays,
        'late_posting' => $latePosting,
        'message' => "Posted {$postedCount} water bill(s) from period {$period['bp_code']}."
            . ($latePosting ? " The calculated due date {$calculatedDueDate} has already passed, so these bills are immediately due/overdue." : '')
            . ($zeroAmountCount ? " {$zeroAmountCount} zero-amount bill(s) were posted without a journal." : ''),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($e instanceof InvalidArgumentException ? 422 : 409);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
