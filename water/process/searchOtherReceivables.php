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
    // Search members who have at least one active/unpaid item across the 3 tables
    $stmt = $pdo->prepare("
        SELECT m.pkey, CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name
        FROM water_accounts m
        WHERE (CAST(m.pkey AS CHAR) LIKE :q1 OR m.lastname LIKE :q2 OR m.firstname LIKE :q3)
        AND m.status = 'A'
        AND (
            EXISTS (
                SELECT 1 FROM installment_schedules s 
                JOIN installment_bills b ON s.installment_id = b.installment_id 
                WHERE b.member_id = m.pkey AND s.status = 'pending'
            )
            OR EXISTS (
                SELECT 1 FROM one_time_bills o 
                WHERE o.member_id = m.pkey AND o.status = 'unpaid'
            )
            OR EXISTS (
                SELECT 1 FROM recurring_subscriptions rs 
                WHERE rs.member_id = m.pkey AND rs.status = 'active'
            )
        )
        ORDER BY m.lastname
        LIMIT 10
    ");
    
    $stmt->execute([':q1' => $likeQ, ':q2' => $likeQ, ':q3' => $likeQ]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}