<?php
/**
 * process/searchMembers.php
 * AJAX — autocomplete search for members (Individual Reading tab).
 *
 * Method : GET
 * Params : q (string, one letter or more: incremental search, version 46), period_id (int)
 * Returns: JSON array of member objects
 *
 * CHRONOLOGICAL GUARD (added):
 *   Members who already have a reading in ANY billing period whose
 *   start_date is strictly AFTER the selected period are silently
 *   excluded from results.  Showing them would let a cashier create
 *   a backwards-in-time entry, which corrupts the prev_reading chain.
 */

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
$__processGuardPdo = getDB();
// Read-only dependency shared by several business pages (audit F07): the caller
// names its page through ?context=..., limited to this allowlist.
business_require_read_access($__processGuardPdo, ['member_list', 'reading_entry', 'generate_soa'], 'member_list');
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

requireLogin();
require_once '../common/readingHelpers.php';

header('Content-Type: application/json');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$q        = trim($_GET['q']         ?? '');
$periodId = (int)($_GET['period_id'] ?? 0);

if (mb_strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

try {
    // ── Resolve the selected period's start date ──────────────────────────
    // We need this as the chronological boundary.  Without it we cannot
    // know which direction is "the future" relative to the chosen period.
    $currStart = null;
    if ($periodId > 0) {
        $stmtP = $pdo->prepare("SELECT start_date FROM bill_periods WHERE period_id = ?");
        $stmtP->execute([$periodId]);
        $period    = $stmtP->fetch(PDO::FETCH_ASSOC);
        $currStart = $period ? $period['start_date'] : null;
    }

    // ── Search for matching members ───────────────────────────────────────
    $members = searchMembers($pdo, $q, 12);

    // ── CHRONOLOGICAL GUARD ───────────────────────────────────────────────
    // Drop any member who already has a reading recorded in a billing period
    // whose start_date is strictly later than the period being worked on.
    // Allowing such a member through would create a gap in the reading chain
    // (the newer reading would become an orphaned, incorrect "previous").
    //
    // Single batch query keeps this O(1) regardless of how many rows
    // searchMembers() returns (max 12).
    if ($currStart !== null && !empty($members)) {
        $pkeys        = array_column($members, 'pkey');
        $placeholders = implode(',', array_fill(0, count($pkeys), '?'));

        $stmtFuture = $pdo->prepare("
            SELECT DISTINCT br.member_id
            FROM   bill_readings br
            JOIN   bill_periods  bp ON br.period_id = bp.period_id
            WHERE  br.member_id IN ($placeholders)
              AND  bp.start_date > ?
        ");
        // Spread pkeys first, then append the date boundary as the last param.
        $stmtFuture->execute([...$pkeys, $currStart]);

        $futureSet = array_flip($stmtFuture->fetchAll(PDO::FETCH_COLUMN));

        // array_values() re-indexes so json_encode produces a JSON array,
        // not a JSON object with non-sequential numeric keys.
        $members = array_values(
            array_filter($members, fn($m) => !isset($futureSet[$m['pkey']]))
        );
    }

    // ── Build result payload ──────────────────────────────────────────────
    $results = [];
    foreach ($members as $m) {
        // Check if this member already has a reading for the CURRENT period.
        $existing = ($periodId > 0) ? getCurrentReading($pdo, $m['pkey'], $periodId) : null;

        $results[] = [
            'pkey'         => $m['pkey'],
            'account_number' => trim((string)($m['account_number'] ?? '')),
            'full_name'    => trim($m['full_name']),
            'street'       => trim($m['street']    ?? ''),
            'housebldg'    => trim($m['housebldg'] ?? ''),
            'initials'     => initials(trim($m['full_name'])),
            'has_discount' => !empty($m['dm_id']),
            'reading_done' => !empty($existing),
            'is_billed'    => !empty($existing['is_billed']),
        ];
    }

    echo json_encode($results);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}