<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/collectionReceiptHelpers.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo=getDB(); requireProcessAccess($pdo,'payment_history');
    $receiptNo=trim((string)($_GET['receipt_no'] ?? $_POST['receipt_no'] ?? ''));
    $receipt=cr_fetch_receipt($pdo,$receiptNo);
    if(!$receipt){http_response_code(404);throw new Exception('Receipt was not found.');}
    echo json_encode(['success'=>true,'receipt'=>$receipt],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){if(http_response_code()<400)http_response_code(422);error_log('Receipt lookup error: '.$e->getMessage());echo json_encode(['success'=>false,'error'=>$e->getMessage()]);}
