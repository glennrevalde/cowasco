<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__.'/../database/config.php';require_once __DIR__.'/accessControlHelpers.php';require_once __DIR__.'/../common/systemDataHelpers.php';
$pdo=getDB();requireProcessAccess($pdo,'notifications');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('POST required.');}
if(!verifyCsrf((string)($_POST['csrf_token']??''))){http_response_code(403);exit('Your form session expired. Reload the page and try again.');}
$operatorId=sys_operator_id();if($operatorId<=0){http_response_code(403);exit('Operator session required.');}
if(!sys_table_exists($pdo,'operator_notification_reads')){$_SESSION['notification_flash']='Run the broken-pages database migration before using read status.';header('Location: ../views/notifications.php');exit;}
$items=sys_notification_items($pdo,$operatorId);$keys=array_column($items,'notification_key');
try{
 $pdo->beginTransaction();$stmt=$pdo->prepare('INSERT INTO operator_notification_reads(operator_id,notification_key,read_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE read_at=VALUES(read_at)');
 if(($_POST['action']??'')==='all'){foreach($keys as $key)$stmt->execute([$operatorId,$key]);}else{$key=trim((string)($_POST['notification_key']??''));if(!in_array($key,$keys,true))throw new RuntimeException('Notification is no longer active.');$stmt->execute([$operatorId,$key]);}
 $pdo->commit();$_SESSION['notification_flash']='Notification status updated.';
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['notification_flash']='Unable to update notification: '.$e->getMessage();}
header('Location: ../views/notifications.php');exit;
