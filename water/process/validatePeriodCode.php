<?php
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */

$pdo = getDB();

$code = trim($_GET['code'] ?? '');
if(!$code) { echo json_encode(['success' => false, 'message' => 'Empty code']); exit; }

$stmt = $pdo->prepare("SELECT period_id, start_date, end_date, status FROM bill_periods WHERE bp_code = :code LIMIT 1");
$stmt->execute([':code' => $code]);
$period = $stmt->fetch(PDO::FETCH_ASSOC);

if($period) {
    if($period['status'] === 'closed') {
        echo json_encode(['success' => false, 'message' => 'This period is closed.']);
    } else {
        echo json_encode([
            'success' => true, 
            'period_id' => $period['period_id'],
            'dates' => date('M j', strtotime($period['start_date'])) . ' - ' . date('M j, Y', strtotime($period['end_date']))
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Period Code.']);
}