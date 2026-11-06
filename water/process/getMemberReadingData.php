<?php
/**
 * process/getMemberReadingData.php
 * AJAX — fetches a single member's full profile + reading state for a period.
 *
 * Method : GET
 * Params : member_id (int), period_id (int)
 * Returns: JSON member object  OR  JSON { error: '...' }
 *
 * CHRONOLOGICAL GUARDS (already present, no change needed):
 *
 *  1. FUTURE-PERIOD BLOCK: If the member already has a reading in any period
 *     whose start_date > the selected period's start_date, the endpoint
 *     returns an error immediately.  The UI shows this message and prevents
 *     the entry form from opening.
 *
 *  2. STRICT prev_reading: The query that populates prev_reading uses
 *     bp.start_date < $currStart (strictly older), so the displayed
 *     "previous" reading is always the chronologically correct predecessor
 *     for the selected period — never a same-period or future reading.
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

$member_id = (int)($_GET['member_id'] ?? 0);
$period_id = (int)($_GET['period_id'] ?? 0);

if (!$member_id || !$period_id) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

try {
    // ── Resolve the current period's start date ───────────────────────────
    $stmtP = $pdo->prepare("SELECT period_id, bp_code, start_date, end_date, status FROM bill_periods WHERE period_id = ?");
    $stmtP->execute([$period_id]);
    $currPeriod = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$currPeriod) {
        echo json_encode(['error' => 'Billing period not found']);
        exit;
    }
    $currStart = $currPeriod['start_date'];
    if (strtolower((string)($currPeriod['status'] ?? '')) !== 'open') {
        echo json_encode(['error' => 'This billing period is closed and no longer accepts meter readings.']);
        exit;
    }

    // ── CHRONOLOGICAL GUARD — block future-period conflicts ───────────────
    // If a reading exists for this member in ANY period that comes AFTER the
    // selected period, entering one now would create a gap in the reading
    // chain.  The newer reading would incorrectly become the "previous"
    // reading for whatever comes next.
    $stmtFuture = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM   bill_readings br
        JOIN   bill_periods  bp ON br.period_id = bp.period_id
        WHERE  br.member_id  = ?
          AND  bp.start_date > ?
          AND  COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
    ");
    $stmtFuture->execute([$member_id, $currStart]);
    $futureCheck = $stmtFuture->fetch(PDO::FETCH_ASSOC);

    if ((int)$futureCheck['cnt'] > 0) {
        echo json_encode([
            'error' => 'A reading already exists for a later billing period. ' .
                       'You cannot add a reading for an earlier period.',
        ]);
        exit;
    }

    // ── Fetch member + primary address ───────────────────────────────────
    $primaryAddressTypeId = member_primary_address_type_id($pdo);
    $stmtMem = $pdo->prepare("
        SELECT
            m.*,
            rc.rc_code, rc.rc_name,
            ps.size_code AS pipe_size_code, ps.size_label AS pipe_size_label,
            ps.diameter_inches, ps.is_active AS pipe_size_is_active,
            CONCAT_WS(', ',
                NULLIF(TRIM(a.housebldg), ''),
                NULLIF(TRIM(a.street),   '')
            ) AS full_address
        FROM   water_accounts       m
        LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
        LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
        LEFT JOIN memberaddress ma ON m.pkey = ma.member_key
                                   AND ma.address_type_key = ?
        LEFT JOIN addresses   a   ON ma.address_key = a.pkey
        WHERE  m.pkey = ?
    ");
    $stmtMem->execute([$primaryAddressTypeId, $member_id]);
    $member = $stmtMem->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['error' => 'Member not found']);
        exit;
    }
    if (strtoupper((string)($member['status'] ?? '')) !== 'A') {
        echo json_encode(['error' => 'This member is inactive and cannot receive a new meter reading.']);
        exit;
    }
    if ((int)($member['rc_id'] ?? 0) <= 0) {
        echo json_encode(['error' => 'This member has no assigned water rate code.']);
        exit;
    }
    if ((int)($member['pipe_size_id'] ?? 0) <= 0) {
        echo json_encode(['error' => 'This member has no assigned pipe size.']);
        exit;
    }
    if ((int)($member['pipe_size_is_active'] ?? 0) !== 1) {
        echo json_encode(['error' => 'This member is assigned to an inactive pipe size.']);
        exit;
    }
    water_rate_validate_tiers(
        water_rate_get_rates_for_configuration($pdo, (int)$member['rc_id'], (int)$member['pipe_size_id']),
        true,
        false
    );

    // ── Previous reading (strictly older periods only) ────────────────────
    // Using start_date < $currStart ensures we fetch the most recent reading
    // that came BEFORE this period — never the same period or any future one.
    $stmtPrev = $pdo->prepare("
        SELECT br.pres_reading
        FROM   bill_readings br
        JOIN   bill_periods  bp ON br.period_id = bp.period_id
        WHERE  br.member_id  = ?
          AND  bp.start_date < ?
          AND  COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
        ORDER  BY bp.start_date DESC, bp.end_date DESC, bp.period_id DESC, br.reading_id DESC
        LIMIT  1
    ");
    $stmtPrev->execute([$member_id, $currStart]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    $member['prev_reading'] = $prev ? (int)round((float)$prev['pres_reading']) : 0;

    // ── Current reading for this period (if already saved) ───────────────
    $stmtCurr = $pdo->prepare("
        SELECT reading_id, pres_reading, is_billed, is_paid, bill_lifecycle_status
        FROM   bill_readings
        WHERE  member_id = ? AND period_id = ?
    ");
    $stmtCurr->execute([$member_id, $period_id]);
    $curr = $stmtCurr->fetch(PDO::FETCH_ASSOC);

    $member['reading_id']   = $curr ? $curr['reading_id']          : null;
    $member['pres_reading'] = $curr ? (int)round((float)$curr['pres_reading']) : null;
    $member['is_billed']    = $curr ? (int)$curr['is_billed']      : 0;
    $member['is_paid']      = $curr ? (int)$curr['is_paid']        : 0;
    $member['period_status'] = strtolower((string)$currPeriod['status']);
    $member['editable'] = !$curr || ((int)$curr['is_billed'] === 0 && (int)$curr['is_paid'] === 0 && strtolower((string)($curr['bill_lifecycle_status'] ?? 'active')) === 'active');

    // ── Water billing discount (exactly one deterministic assignment) ─────
    $member['discount'] = water_rate_get_member_discount($pdo, $member_id);

    echo json_encode($member);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
