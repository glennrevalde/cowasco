<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getDB();
    requireProcessAccess($pdo, 'water_bills_collection');
    $memberId = (int)($_GET['member_id'] ?? 0);
    if ($memberId <= 0) { echo json_encode([]); exit; }
    $today = date('Y-m-d');
    $results = [];

    $queries = [
        ['water', "SELECT br.reading_id db_id, GREATEST(0, br.amount_due-COALESCE(br.amount_paid,0)) amount, br.due_date, br.billing_date, br.consumption, bp.bp_code description FROM bill_readings br JOIN bill_periods bp ON bp.period_id=br.period_id WHERE br.member_id=? AND br.is_billed=1 AND GREATEST(0,br.amount_due-COALESCE(br.amount_paid,0))>0 AND COALESCE(br.bill_lifecycle_status,'active')<>'deleted'"],
        ['installment', "SELECT s.schedule_id db_id, GREATEST(0,s.amount-COALESCE(s.amount_paid,0)) amount, s.due_date, NULL billing_date, NULL consumption, c.code description FROM installment_schedules s JOIN installment_bills b ON b.installment_id=s.installment_id JOIN bill_codes c ON c.code_id=b.bill_code_id WHERE b.member_id=? AND GREATEST(0,s.amount-COALESCE(s.amount_paid,0))>0 AND b.status NOT IN ('cancelled','deleted') AND COALESCE(b.bill_lifecycle_status,'active')<>'deleted'"],
        ['onetime', "SELECT b.bill_id db_id, GREATEST(0,b.total_amount-COALESCE(b.amount_paid,0)) amount, b.due_date, b.bill_date billing_date, NULL consumption, c.code description FROM one_time_bills b JOIN bill_codes c ON c.code_id=b.bill_code_id WHERE b.member_id=? AND GREATEST(0,b.total_amount-COALESCE(b.amount_paid,0))>0 AND b.status NOT IN ('cancelled','deleted') AND COALESCE(b.bill_lifecycle_status,'active')<>'deleted'"],
        ['recurring', "SELECT rb.bill_id db_id, GREATEST(0,rb.total_amount-COALESCE(rb.amount_paid,0)) amount, rb.due_date, rb.bill_date billing_date, NULL consumption, c.code description FROM recurring_bills rb JOIN bill_codes c ON c.code_id=rb.bill_code_id WHERE rb.member_id=? AND GREATEST(0,rb.total_amount-COALESCE(rb.amount_paid,0))>0 AND rb.status NOT IN ('cancelled','deleted') AND COALESCE(rb.bill_lifecycle_status,'active')<>'deleted'"],
    ];
    foreach ($queries as [$type,$sql]) {
        $stmt=$pdo->prepare($sql); $stmt->execute([$memberId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = (string)$row['description'];
            if ($type==='water') $label='Water Bill ('.$label.') - '.number_format((int)$row['consumption']).'m³';
            else $label .= ' (' . ucfirst($type==='onetime'?'One-Time':$type) . ')';
            $results[]=[
                'db_id'=>(int)$row['db_id'],'db_type'=>$type,'description'=>$label,
                'amount'=>round((float)$row['amount'],2),'balance'=>round((float)$row['amount'],2),
                'due_date'=>$row['due_date'],'billing_date'=>$row['billing_date'] ?? null,
                'is_late'=>!empty($row['due_date']) && $today>$row['due_date'],
                'is_upcoming'=>!empty($row['due_date']) && $row['due_date']>$today,
            ];
        }
    }
    // Fixed Share Capital Billing: the bills of the member(s) of this water-service account (the same party). A bill
    // whose posting day has come is posted first, so the cashier sees it on the day.
    if (ShareCapitalBilling::ready()) {
        ShareCapitalBilling::postDueForWaterAccount($memberId);
        foreach (ShareCapitalBilling::openBillsForWaterAccount($memberId) as $b) {
            $balance = round((float)$b['amount'] - (float)$b['amount_paid'], 2);
            $results[] = [
                'db_id' => (int)$b['bill_id'], 'db_type' => 'sharecap', 'description' => ShareCapitalBilling::billDescription($b),
                'member_name' => (string)$b['full_name'], 'amount' => $balance, 'balance' => $balance,
                // listed by its posting date; its own due date (Term (days)) or none (open)
                'due_date' => $b['bill_date'], 'billing_date' => $b['bill_date'], 'sc_due_date' => (string)($b['due_date'] ?? ''),
                'is_late' => !empty($b['due_date']) && $b['due_date'] < date('Y-m-d'), 'is_upcoming' => false,
            ];
        }
    }
    usort($results, static fn($a,$b)=>strcmp($a['due_date'] ?: '9999-12-31',$b['due_date'] ?: '9999-12-31'));
    echo json_encode($results, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500); error_log('Receivables error: '.$e->getMessage());
    echo json_encode(['error'=>'Unable to load receivables.']);
}
