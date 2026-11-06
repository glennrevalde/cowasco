<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo=getDB(); requireProcessAccess($pdo,'water_bills_collection');
    // incremental search (version 46): from the first letter; every word typed must be found in the ID, the account
    // number or the name, in any order ("bond james", "Bond, James", "50031"); the names that start with it come first
    $q=trim((string)($_GET['q'] ?? '')); if (mb_strlen($q)<1){echo json_encode([]);exit;}
    $words=array_slice(preg_split('/[\s,]+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[],0,5);
    if(!$words){echo json_encode([]);exit;}
    $cond=[];$params=[];
    foreach($words as $i=>$w){$like='%'.addcslashes($w,'%_\\').'%';$cond[]="(CAST(m.pkey AS CHAR) LIKE :w{$i}a OR m.account_number LIKE :w{$i}b OR m.lastname LIKE :w{$i}c OR m.firstname LIKE :w{$i}d)";
        $params[":w{$i}a"]=$like;$params[":w{$i}b"]=$like;$params[":w{$i}c"]=$like;$params[":w{$i}d"]=$like;}
    $starts=addcslashes($words[0],'%_\\').'%';$params[':s1']=$starts;$params[':s2']=$starts;$params[':s3']=$starts;
    // the fixed share capital bills of the account's member (same party) are open items too: the ones due are posted first
    $shareCapital = '';
    if (ShareCapitalBilling::ready()) {
        try { ShareCapitalBilling::postDue(); } catch (Throwable $e) { error_log('Share capital posting: ' . $e->getMessage()); }
        $shareCapital = "+(SELECT COUNT(*) FROM share_capital_bills scb JOIN members cm ON cm.id=scb.member_id JOIN sav_accounts sa ON sa.id=scb.sav_account_id
            WHERE m.party_id IS NOT NULL AND cm.party_id=m.party_id AND scb.status IN ('unpaid','partial') AND sa.status IN ('active','dormant') AND cm.status IN ('active','applicant'))";
    }
    $stmt=$pdo->prepare("SELECT m.pkey, CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) full_name,
      ((SELECT COUNT(*) FROM bill_readings br WHERE br.member_id=m.pkey AND br.is_billed=1 AND br.amount_due>COALESCE(br.amount_paid,0) AND COALESCE(br.bill_lifecycle_status,'active')<>'deleted')+
       (SELECT COUNT(*) FROM installment_schedules s JOIN installment_bills b ON b.installment_id=s.installment_id WHERE b.member_id=m.pkey AND s.amount>COALESCE(s.amount_paid,0) AND b.status NOT IN ('cancelled','deleted') AND COALESCE(b.bill_lifecycle_status,'active')<>'deleted')+
       (SELECT COUNT(*) FROM one_time_bills o WHERE o.member_id=m.pkey AND o.total_amount>COALESCE(o.amount_paid,0) AND o.status NOT IN ('cancelled','deleted') AND COALESCE(o.bill_lifecycle_status,'active')<>'deleted')+
       (SELECT COUNT(*) FROM recurring_bills r WHERE r.member_id=m.pkey AND r.total_amount>COALESCE(r.amount_paid,0) AND r.status NOT IN ('cancelled','deleted') AND COALESCE(r.bill_lifecycle_status,'active')<>'deleted')".$shareCapital.") unpaid_count
      FROM water_accounts m WHERE m.status='A' AND ".implode(' AND ',$cond)."
      HAVING unpaid_count>0 ORDER BY (CASE WHEN m.lastname LIKE :s1 OR m.account_number LIKE :s2 OR CAST(m.pkey AS CHAR) LIKE :s3 THEN 0 ELSE 1 END),m.lastname,m.firstname LIMIT 20");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){http_response_code(500);error_log('Receivable search error: '.$e->getMessage());echo json_encode(['error'=>'Unable to search receivables.']);}
