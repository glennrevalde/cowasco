<?php
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

header('Content-Type: application/json');
$pdo = getDB();

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode([]); exit; }

$likeQ = '%' . $q . '%';

try {
    // Search members who have at least one BILLED, UNPAID water bill reading.
    // is_billed = 1 excludes draft readings.
    // is_paid   = 0 excludes already-settled readings.
    $stmt = $pdo->prepare("
        SELECT
            m.pkey,
            CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
            COUNT(br.reading_id) AS unpaid_count
        FROM water_accounts m
        JOIN bill_readings br ON m.pkey = br.member_id
        WHERE br.is_billed = 1
          AND br.is_paid   = 0
          AND (
              CAST(m.pkey AS CHAR) LIKE :q1
              OR m.lastname         LIKE :q2
              OR m.firstname        LIKE :q3
          )
        GROUP BY m.pkey, m.lastname, m.firstname
        ORDER BY m.lastname
        LIMIT 10
    ");

    $stmt->execute([
        ':q1' => $likeQ,
        ':q2' => $likeQ,
        ':q3' => $likeQ,
    ]);

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}