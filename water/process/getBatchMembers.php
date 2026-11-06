<?php
/**
 * process/getBatchMembers.php
 * AJAX — loads all members for a street or zone into the Batch Reading table.
 *
 * Method : GET
 * Params : period_id (int), street (string) OR zone (int)
 * Returns: JSON { members: [...] }
 *
 * CHRONOLOGICAL GUARDS (already present, no change needed):
 *
 *  1. prev_reading subquery uses  bp_prev.start_date < ?  (strictly older)
 *     so the displayed "previous" reading is always the most recent one
 *     that came BEFORE the selected period — never a future reading.
 *
 *  2. NOT EXISTS clause in the WHERE filters out any member who already
 *     has a reading in a period whose start_date > $currStart.  Such
 *     members are simply hidden from the batch table so a cashier cannot
 *     accidentally enter a backwards-in-time reading for them.
 */

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

require_once '../common/waterRateBillingHelpers.php';
require_once '../common/billingPeriodHelpers.php';
require_once '../common/memberAddressHelpers.php';

header('Content-Type: application/json');
$pdo = getDB();

$period_id = (int)($_GET['period_id'] ?? 0);
$street    = $_GET['street'] ?? '';
$zone      = $_GET['zone']   ?? '';

if (!$period_id)          { echo json_encode(['error' => 'Missing period ID']);       exit; }
if (!$street && !$zone)   { echo json_encode(['error' => 'Missing street or zone']);  exit; }

try {
    // ── Resolve the current period's start date ───────────────────────────
    // Used as the chronological boundary in both the prev_reading subquery
    // and the NOT EXISTS future-period guard below.
    $stmtP = $pdo->prepare("SELECT period_id, bp_code, start_date, end_date, status FROM bill_periods WHERE period_id = ?");
    $stmtP->execute([$period_id]);
    $currPeriod = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$currPeriod) { echo json_encode(['error' => 'Invalid period']); exit; }
    $currStart = $currPeriod['start_date'];
    if (strtolower((string)($currPeriod['status'] ?? '')) !== 'open') {
        echo json_encode(['error' => 'This billing period is closed and no longer accepts batch readings.']);
        exit;
    }

    $sql = "
        SELECT
            m.pkey,
            CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
            m.zone,
            m.meter_sequence,
            m.rc_id,
            m.pipe_size_id,
            rc.rc_code,
            rc.rc_name,
            ps.size_code AS pipe_size_code,
            ps.size_label AS pipe_size_label,
            ps.diameter_inches,
            ps.is_active AS pipe_size_is_active,

            /* ── prev_reading: strictly the most recent reading whose
                  billing period started BEFORE the selected period.
                  Using start_date < ? (not <=) ensures we never pick up
                  a reading from the same or a future period. ── */
            (
                SELECT br_prev.pres_reading
                FROM   bill_readings br_prev
                JOIN   bill_periods  bp_prev ON br_prev.period_id = bp_prev.period_id
                WHERE  br_prev.member_id = m.pkey
                  AND  bp_prev.start_date < ?
                  AND  COALESCE(br_prev.bill_lifecycle_status, 'active') <> 'deleted'
                ORDER  BY bp_prev.start_date DESC, bp_prev.end_date DESC, bp_prev.period_id DESC, br_prev.reading_id DESC
                LIMIT  1
            ) AS prev_reading,

            curr.reading_id,
            curr.pres_reading,
            curr.is_billed,
            curr.is_paid,
            curr.bill_lifecycle_status

        FROM water_accounts m
        LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
        LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
        LEFT JOIN bill_readings curr
               ON m.pkey = curr.member_id AND curr.period_id = ?
    ";

    // ── CHRONOLOGICAL GUARD ───────────────────────────────────────────────
    // Exclude members who have a reading in ANY period that starts AFTER the
    // selected period.  Allowing them would create a backwards-in-time entry
    // and make the future reading appear as the next prev_reading — data
    // corruption.  Hidden here, they stay safe in the batch table.
    $sql .= "
        WHERE m.status = 'A'
          AND NOT EXISTS (
                SELECT 1
                FROM   bill_readings br2
                JOIN   bill_periods  bp2 ON br2.period_id = bp2.period_id
                WHERE  br2.member_id   = m.pkey
                  AND  bp2.start_date  > ?
                  AND  COALESCE(br2.bill_lifecycle_status, 'active') <> 'deleted'
              )
    ";

    $params = [$currStart, $period_id, $currStart];

    if ($street) {
        $primaryAddressTypeId = member_primary_address_type_id($pdo);
        $sql .= " AND m.pkey IN (
                    SELECT ma.member_key
                    FROM   memberaddress ma
                    JOIN   addresses     a ON ma.address_key = a.pkey
                    WHERE  a.street = ? AND ma.address_type_key = ?
                  )";
        $params[] = $street;
        $params[] = $primaryAddressTypeId;
    } elseif ($zone) {
        $sql .= " AND m.zone = ?";
        $params[] = $zone;
    }

    $sql .= " ORDER BY
        CASE WHEN NULLIF(TRIM(m.meter_sequence), '') IS NULL THEN 1 ELSE 0 END ASC,
        CASE WHEN m.meter_sequence REGEXP '^[0-9]+$' THEN 0 ELSE 1 END ASC,
        CASE WHEN m.meter_sequence REGEXP '^[0-9]+$' THEN CAST(m.meter_sequence AS UNSIGNED) END ASC,
        m.meter_sequence ASC,
        m.lastname ASC,
        m.firstname ASC,
        m.pkey ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Attach the canonical single water discount assignment ─────────────
    foreach ($members as &$m) {
        $m['prev_reading'] = $m['prev_reading'] !== null
            ? (int)round((float)$m['prev_reading'])
            : 0;
        $m['pres_reading'] = $m['pres_reading'] !== null
            ? (int)round((float)$m['pres_reading'])
            : null;
        $m['is_billed'] = (int)($m['is_billed'] ?? 0);
        $m['is_paid'] = (int)($m['is_paid'] ?? 0);
        $m['editable'] = $m['reading_id'] === null || (
            $m['is_billed'] === 0
            && $m['is_paid'] === 0
            && strtolower((string)($m['bill_lifecycle_status'] ?? 'active')) === 'active'
        );
        $m['discount'] = water_rate_get_member_discount($pdo, (int)$m['pkey']);
        $m['configuration_error'] = null;
        try {
            if ((int)($m['rc_id'] ?? 0) <= 0) {
                throw new RuntimeException('No rate code assigned.');
            }
            if ((int)($m['pipe_size_id'] ?? 0) <= 0) {
                throw new RuntimeException('No pipe size assigned.');
            }
            if ((int)($m['pipe_size_is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Assigned pipe size is inactive.');
            }
            water_rate_validate_tiers(
                water_rate_get_rates_for_configuration($pdo, (int)$m['rc_id'], (int)$m['pipe_size_id']),
                true,
                false
            );
        } catch (Throwable $configurationError) {
            $m['editable'] = false;
            $m['configuration_error'] = $configurationError->getMessage();
        }
    }
    unset($m);

    echo json_encode(['members' => $members]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
