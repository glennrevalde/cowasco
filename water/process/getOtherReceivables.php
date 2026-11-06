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

$member_id = (int)($_GET['member_id'] ?? 0);
if (!$member_id) { echo json_encode([]); exit; }

try {
    $results = [];

    // STRICT: status = 'pending'
    $stmtInst = $pdo->prepare("
        SELECT s.schedule_id as db_id, s.amount, s.due_date, c.code as description 
        FROM installment_schedules s 
        JOIN installment_bills b ON s.installment_id = b.installment_id 
        JOIN bill_codes c ON b.bill_code_id = c.code_id
        WHERE b.member_id = ? AND s.status = 'pending' AND b.status <> 'deleted' AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
    ");
    $stmtInst->execute([$member_id]);
    foreach ($stmtInst->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = ['db_id' => $row['db_id'], 'db_type' => 'installment', 'description' => $row['description'], 'amount' => (float)$row['amount'], 'due_date' => $row['due_date']];
    }

    // STRICT: status = 'unpaid'
    $stmtOne = $pdo->prepare("
        SELECT b.bill_id as db_id, (b.total_amount - b.amount_paid) as amount, b.due_date, c.code as description
        FROM one_time_bills b JOIN bill_codes c ON b.bill_code_id = c.code_id 
        WHERE b.member_id = ? AND b.status = 'unpaid' AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
    ");
    $stmtOne->execute([$member_id]);
    foreach ($stmtOne->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((float)$row['amount'] > 0) {
            $results[] = ['db_id' => $row['db_id'], 'db_type' => 'onetime', 'description' => $row['description'], 'amount' => (float)$row['amount'], 'due_date' => $row['due_date']];
        }
    }

    // STRICT: status = 'active' AND next_billing_date <= today
    $stmtRecur = $pdo->prepare("
        SELECT s.subscription_id as db_id, p.amount, s.next_billing_date as due_date, c.code as description
        FROM recurring_subscriptions s JOIN recurring_plans p ON s.plan_id = p.plan_id JOIN bill_codes c ON p.bill_code_id = c.code_id
        WHERE s.member_id = ? AND s.status = 'active' AND s.next_billing_date <= CURDATE()
    ");
    $stmtRecur->execute([$member_id]);
    foreach ($stmtRecur->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = ['db_id' => $row['db_id'], 'db_type' => 'recurring', 'description' => $row['description'], 'amount' => (float)$row['amount'], 'due_date' => $row['due_date']];
    }

    usort($results, static fn($a, $b) => strcmp((string)($a['due_date'] ?: '9999-12-31'), (string)($b['due_date'] ?: '9999-12-31')));
    echo json_encode($results);

} catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }