<?php
/** AJAX — Returns water-rate tiers grouped by exact rate code + pipe size. */
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
$__processGuardPdo = getDB();
// Read-only dependency shared by several business pages (audit F07): the caller
// names its page through ?context=..., limited to this allowlist.
business_require_read_access($__processGuardPdo, ['water_meter', 'reading_entry', 'generate_soa', 'billing_adjustments'], 'water_meter');
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
require_once '../common/waterRateBillingHelpers.php';
header('Content-Type: application/json');
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

try {
    $globalBillCode = null; $globalError = null;
    try { $globalBillCode = water_billing_get_global_bill_code($pdo); } catch (Throwable $e) { $globalError = $e->getMessage(); }
    $rates = $pdo->query("
        SELECT w.wmm_id, w.rc_id, w.pipe_size_id, w.from_cb, w.to_cb, w.amount, w.bill_type,
               ps.size_code AS pipe_size_code, ps.size_label AS pipe_size_label, ps.diameter_inches, rc.billing_method
        FROM water_meter_members w
        JOIN water_pipe_sizes ps ON ps.pipe_size_id = w.pipe_size_id
        LEFT JOIN rate_code rc ON rc.rc_id = w.rc_id
        ORDER BY w.rc_id, w.pipe_size_id, w.from_cb, w.wmm_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $groups=[];
    foreach($rates as $rate){$groups[(int)$rate['rc_id'].':'.(int)$rate['pipe_size_id']][]=$rate;}
    $statuses=[];
    foreach($groups as $key=>$tiers){$statuses[$key]=water_rate_get_configuration_status($tiers);}
    $out=array_map(static function(array $rate) use($globalBillCode,$globalError,$statuses):array{
        $key=(int)$rate['rc_id'].':'.(int)$rate['pipe_size_id'];
        $status=$statuses[$key]??['valid'=>false,'complete'=>false,'message'=>'No tariff configuration found.'];
        return [
            'wmm_id'=>(int)$rate['wmm_id'],'rc_id'=>(int)$rate['rc_id'],'pipe_size_id'=>(int)$rate['pipe_size_id'],
            'pipe_size_code'=>(string)$rate['pipe_size_code'],'pipe_size_label'=>(string)$rate['pipe_size_label'],
            'diameter_inches'=>$rate['diameter_inches']!==null?(float)$rate['diameter_inches']:null,
            'bill_code_id'=>$globalBillCode['code_id']??null,'bill_code'=>$globalBillCode['code']??null,
            'bill_code_type'=>$globalBillCode['type']??null,'ar_account'=>$globalBillCode['ar_account']??null,
            'income_account'=>$globalBillCode['income_account']??null,'accounting_setup_error'=>$globalError,
            'from_cb'=>(int)$rate['from_cb'],'to_cb'=>$rate['to_cb']!==null?(int)$rate['to_cb']:null,
            'amount'=>water_rate_scaled_to_decimal(water_rate_decimal_to_scaled_int($rate['amount'],4),4),
            'bill_type'=>strtoupper((string)$rate['bill_type']),
            // the computation of the rate code: 'straight' or 'ladder' (Water Billing > Rate Codes)
            'billing_method'=>water_rate_billing_method($rate['billing_method']??'straight'),
            'configuration_valid'=>(bool)($status['valid']??false),
            'configuration_complete'=>(bool)($status['complete']??false),
            'configuration_error'=>($status['valid']??false)?null:(string)($status['message']??'Invalid tariff.'),
        ];
    },$rates);
    echo json_encode($out);
} catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
