<?php
/**
 * Shared read-only accounting report renderer.
 * The wrapper must set $accountingReportType before including this file.
 * This does not create, post, edit, or reverse accounting entries.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/systemDataHelpers.php';
require_once __DIR__ . '/displayHelpers.php';
$pdo = getDB();
$pageKey = basename($_SERVER['PHP_SELF'] ?? '', '.php');
requirePageAccess($pdo, $pageKey);

if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('arp_money_cents')) {
    function arp_money_cents($value): int
    {
        $raw = trim((string)($value ?? '0'));
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $raw, $parts)) {
            throw new InvalidArgumentException('A journal report contains an invalid monetary value.');
        }
        $fraction = str_pad((string)($parts[3] ?? ''), 3, '0');
        $whole = (int)$parts[2];
        if ($whole > intdiv(PHP_INT_MAX - 99, 100)) {
            throw new OverflowException('A journal report amount is outside the supported range.');
        }
        $cents = ($whole * 100) + (int)substr($fraction, 0, 2);
        if ((int)$fraction[2] >= 5) {
            $cents++;
        }
        return ($parts[1] ?? '') === '-' ? -$cents : $cents;
    }
}
if (!function_exists('arp_format_cents')) {
    function arp_format_cents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        return $sign . number_format(intdiv($absolute, 100), 0, '.', ',')
            . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}


if (!function_exists('arp_sl_label')) {
    function arp_sl_label($type, $code): string
    {
        $type = trim((string)($type ?? ''));
        $code = trim((string)($code ?? ''));
        $parts = [];
        if ($type !== '' && $type !== '0') $parts[] = 'T' . $type;
        if ($code !== '' && $code !== '0') $parts[] = $code;
        return $parts ? implode(' / ', $parts) : '—';
    }
}

if (!function_exists('arp_setting_int')) {
    function arp_setting_int(PDO $pdo, string $key, int $default): int
    {
        if (!sys_table_exists($pdo, 'water_billing_settings')) {
            return $default;
        }
        $stmt = $pdo->prepare('SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false || !preg_match('/^\d+$/', trim((string)$value))) {
            return $default;
        }
        return (int)$value;
    }
}

$type = $accountingReportType ?? 'general_ledger';
$allowed = ['chart_of_accounts','general_ledger','trial_balance','balance_sheet','income_statement'];
if (!in_array($type, $allowed, true)) { http_response_code(404); exit('Unknown accounting report.'); }

$today = date('Y-m-d');
$from = trim((string)($_GET['from'] ?? date('Y-m-01')));
$to = trim((string)($_GET['to'] ?? $today));
$dateOk = static function(string $date): bool { $d=DateTime::createFromFormat('Y-m-d',$date); return $d && $d->format('Y-m-d')===$date; };
$error = '';
if (!$dateOk($from) || !$dateOk($to) || $from > $to) {
    $error = 'Enter a valid date range. The starting date cannot be later than the ending date.';
    $from = date('Y-m-01'); $to = $today;
}
$search = trim((string)($_GET['q'] ?? ''));
$accountId = (int)($_GET['account_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 200;
$totalRows = 0;
$totalPages = 1;
$rows = [];
$summary = [];
$title = '';
$subtitle = '';

try {
    if (!sys_table_exists($pdo, 'chartofaccntstbl')) throw new RuntimeException('The chartofaccntstbl table is unavailable.');

    if ($type === 'chart_of_accounts') {
        $title='Chart of Accounts'; $subtitle='Read-only account master list. Account maintenance remains in the established accounting configuration.';
        $sql="SELECT AccntID,AccntName,BaseAccnt,ParentAccnt,SummaryAccnt,Type,Description,WithSL,CashFlowType,Status FROM chartofaccntstbl WHERE (?='' OR AccntName LIKE ? OR CAST(AccntID AS CHAR) LIKE ?) ORDER BY Type,AccntID";
        $stmt=$pdo->prepare($sql); $like="%$search%"; $stmt->execute([$search,$like,$like]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'general_ledger') {
        $title='General Ledger'; $subtitle='Posted, active journal lines by account and transaction date. Totals cover the complete filtered result; the table is paginated for readability.';
        $like="%$search%";
        $where="je.Status='A' AND je.IsPosted=1 AND DATE(COALESCE(je.TransactionDate,CURDATE())) BETWEEN ? AND ? AND (?=0 OR je.AccountID=?) AND (?='' OR ca.AccntName LIKE ? OR je.TransRefID LIKE ? OR je.JournalHeadID LIKE ?)";
        $params=[$from,$to,$accountId,$accountId,$search,$like,$like,$like];

        $count=$pdo->prepare("SELECT COUNT(*) FROM journalentries_copy1 je LEFT JOIN chartofaccntstbl ca ON ca.AccntID=je.AccountID WHERE {$where}");
        $count->execute($params);
        $totalRows=(int)$count->fetchColumn();
        $totalPages=max(1,(int)ceil($totalRows/$pageSize));
        if($page>$totalPages){$page=$totalPages;}
        $offset=($page-1)*$pageSize;

        $totals=$pdo->prepare("SELECT COALESCE(SUM(je.Debit),0) AS Debit,COALESCE(SUM(je.Credit),0) AS Credit FROM journalentries_copy1 je LEFT JOIN chartofaccntstbl ca ON ca.AccntID=je.AccountID WHERE {$where}");
        $totals->execute($params);
        $totalRow=$totals->fetch(PDO::FETCH_ASSOC)?:['Debit'=>0,'Credit'=>0];
        $summary['debit_cents']=arp_money_cents($totalRow['Debit']);
        $summary['credit_cents']=arp_money_cents($totalRow['Credit']);

        $sql="SELECT je.JournalID,je.JournalHeadID,je.TransRefID,je.TransactionDate,je.AccountID,ca.AccntName,je.Description,je.Debit,je.Credit,je.IsPosted,je.Status,je.SLType,je.SLCode,je.BranchID,je.SegmentID,je.DivisionID FROM journalentries_copy1 je LEFT JOIN chartofaccntstbl ca ON ca.AccntID=je.AccountID WHERE {$where} ORDER BY je.TransactionDate DESC,je.JournalID DESC LIMIT ? OFFSET ?";
        $stmt=$pdo->prepare($sql);
        foreach($params as $index=>$value){$stmt->bindValue($index+1,$value);}
        $stmt->bindValue(count($params)+1,$pageSize,PDO::PARAM_INT);
        $stmt->bindValue(count($params)+2,$offset,PDO::PARAM_INT);
        $stmt->execute();
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($type === 'trial_balance') {
        $title='Trial Balance'; $subtitle='Posted, active debit and credit totals by account for the selected date range.';
        $sql="SELECT ca.AccntID,ca.AccntName,ca.Type,ca.Status,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 THEN je.Debit ELSE 0 END),0) Debit,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 THEN je.Credit ELSE 0 END),0) Credit,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 THEN je.Debit-je.Credit ELSE 0 END),0) Balance FROM chartofaccntstbl ca LEFT JOIN journalentries_copy1 je ON je.AccountID=ca.AccntID AND DATE(COALESCE(je.TransactionDate,CURDATE())) BETWEEN ? AND ? WHERE (?='' OR ca.AccntName LIKE ? OR CAST(ca.AccntID AS CHAR) LIKE ?) GROUP BY ca.AccntID,ca.AccntName,ca.Type,ca.Status HAVING Debit<>0 OR Credit<>0 ORDER BY ca.AccntID";
        $stmt=$pdo->prepare($sql);$like="%$search%";$stmt->execute([$from,$to,$search,$like,$like]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r){$summary['debit_cents']=($summary['debit_cents']??0)+arp_money_cents($r['Debit']);$summary['credit_cents']=($summary['credit_cents']??0)+arp_money_cents($r['Credit']);}
    } elseif ($type === 'balance_sheet') {
        $title='Balance Sheet'; $subtitle='Posted, active balances grouped by the accounting type mapping stored in System Settings.';
        $assetType=arp_setting_int($pdo,'accounting_asset_type',1);$liabilityType=arp_setting_int($pdo,'accounting_liability_type',2);$equityType=arp_setting_int($pdo,'accounting_equity_type',3);
        $sql="SELECT ca.AccntID,ca.AccntName,ca.Type,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 AND DATE(COALESCE(je.TransactionDate,CURDATE()))<=? THEN je.Debit-je.Credit ELSE 0 END),0) Balance FROM chartofaccntstbl ca LEFT JOIN journalentries_copy1 je ON je.AccountID=ca.AccntID WHERE ca.Type IN (?,?,?) GROUP BY ca.AccntID,ca.AccntName,ca.Type HAVING Balance<>0 ORDER BY ca.Type,ca.AccntID";
        $stmt=$pdo->prepare($sql);$stmt->execute([$to,$assetType,$liabilityType,$equityType]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary=['asset_type'=>$assetType,'liability_type'=>$liabilityType,'equity_type'=>$equityType];
        foreach($rows as $r){$key='type_'.$r['Type'].'_cents';$summary[$key]=($summary[$key]??0)+arp_money_cents($r['Balance']);}
    } else {
        $title='Income Statement'; $subtitle='Posted, active activity grouped by the accounting type mapping stored in System Settings.';
        $incomeType=arp_setting_int($pdo,'accounting_income_type',4);$expenseType=arp_setting_int($pdo,'accounting_expense_type',5);
        $sql="SELECT ca.AccntID,ca.AccntName,ca.Type,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 THEN je.Debit ELSE 0 END),0) Debit,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 THEN je.Credit ELSE 0 END),0) Credit,COALESCE(SUM(CASE WHEN je.Status='A' AND je.IsPosted=1 THEN je.Credit-je.Debit ELSE 0 END),0) NetCredit FROM chartofaccntstbl ca LEFT JOIN journalentries_copy1 je ON je.AccountID=ca.AccntID AND DATE(COALESCE(je.TransactionDate,CURDATE())) BETWEEN ? AND ? WHERE ca.Type IN (?,?) GROUP BY ca.AccntID,ca.AccntName,ca.Type HAVING Debit<>0 OR Credit<>0 ORDER BY ca.Type,ca.AccntID";
        $stmt=$pdo->prepare($sql);$stmt->execute([$from,$to,$incomeType,$expenseType]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary=['income_type'=>$incomeType,'expense_type'=>$expenseType,'income_cents'=>0,'expense_cents'=>0];
        foreach($rows as $r){if((int)$r['Type']===$incomeType)$summary['income_cents']+=arp_money_cents($r['NetCredit']);else $summary['expense_cents']-=arp_money_cents($r['NetCredit']);}
    }
} catch (Throwable $e) { $error = 'Report unavailable: ' . $e->getMessage(); }

$accounts=[];
if ($type==='general_ledger') {
    try {
        $accounts=$pdo->query("SELECT AccntID,AccntName FROM chartofaccntstbl WHERE Status='A' ORDER BY AccntName")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {
        error_log('General-ledger account filter load failed: '.$e->getMessage());
        if ($error === '') {
            $error='The active account list could not be loaded. Please verify the database and try again.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> - <?=e(app_name())?></title>
<link rel="stylesheet" href="../common/fonts.css">
<style>*{box-sizing:border-box}body{margin:0;background:#f8f9fb;color:#111827;font-family:Sora,sans-serif}.page{padding:30px;max-width:1500px;margin:auto}.head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:20px}h1{margin:0;font-size:1.55rem}.sub{color:#6b7280;font-size:.84rem;max-width:900px;line-height:1.55;margin-top:5px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:18px}.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.field{display:flex;flex-direction:column;gap:5px}.field label{font-size:.68rem;font-weight:800;text-transform:uppercase;color:#6b7280}.field input,.field select{height:40px;border:1px solid #d1d5db;border-radius:8px;padding:0 10px;font:inherit;min-width:150px}.btn{height:40px;border:0;border-radius:8px;padding:0 15px;background:#111827;color:#fff;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}.btn.light{background:#fff;color:#111827;border:1px solid #d1d5db}.alert{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:12px;border-radius:10px;margin-bottom:16px}.summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}.metric{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:13px 16px;min-width:180px}.metric small{display:block;color:#6b7280;font-weight:700;margin-bottom:5px}.metric strong{font-family:'JetBrains Mono';font-size:1.05rem}.table-wrap{overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px}table{width:100%;border-collapse:collapse;min-width:850px}th,td{padding:10px 12px;border-bottom:1px solid #eef0f2;text-align:left;font-size:.78rem;white-space:nowrap}th{background:#f8fafc;color:#4b5563;text-transform:uppercase;font-size:.66rem;letter-spacing:.04em;position:sticky;top:0}.money{text-align:right;font-family:'JetBrains Mono'}.empty{text-align:center!important;padding:35px!important;color:#6b7280}.badge{padding:4px 7px;border-radius:999px;background:#f3f4f6;font-size:.68rem;font-weight:800}.pager{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px;flex-wrap:wrap}.pager-links{display:flex;gap:8px}.pager a{padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;text-decoration:none;color:#111827;background:#fff;font-size:.75rem;font-weight:700}.pager a.disabled{pointer-events:none;opacity:.45}.pager-meta{font-size:.75rem;color:#6b7280}.screen-ref{display:inline}@media print{.sidebar,.topbar,.filters,.no-print{display:none!important}.main-content{margin:0!important}.page{padding:8mm;max-width:none}.table-wrap{overflow:visible;border:0}table{min-width:0}th,td{font-size:8pt;padding:5px}.card{border:0;padding:0}.screen-ref{font-size:0!important}.screen-ref::after{content:attr(data-full-ref);font-size:8pt!important}}@media(max-width:700px){.page{padding:18px 14px}}</style></head><body>
<?php require __DIR__ . '/navbar.php'; ?><div class="main-content"><main class="page">
<div class="head"><div><h1><?=e($title)?></h1><div class="sub"><?=e($subtitle)?></div></div><div class="no-print"><a class="btn light" href="accounting_management.php">Accounting Hub</a> <button class="btn" onclick="window.print()">Print</button></div></div>
<?php if($error):?><div class="alert"><?=e($error)?></div><?php endif;?>
<form class="card filters no-print" method="get">
<?php if($type!=='chart_of_accounts' && $type!=='balance_sheet'):?><div class="field"><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><?php endif;?>
<?php if($type!=='chart_of_accounts'):?><div class="field"><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><?php endif;?>
<?php if(in_array($type,['chart_of_accounts','general_ledger','trial_balance'],true)):?><div class="field"><label>Search</label><input name="q" value="<?=e($search)?>" placeholder="Account or reference"></div><?php endif;?>
<?php if($type==='general_ledger'):?><div class="field"><label>Account</label><select name="account_id"><option value="0">All accounts</option><?php foreach($accounts as $a):?><option value="<?=e($a['AccntID'])?>" <?=$accountId===(int)$a['AccntID']?'selected':''?>><?=e($a['AccntID'].' — '.$a['AccntName'])?></option><?php endforeach;?></select></div><?php endif;?>
<button class="btn">Apply</button></form>
<?php if(isset($summary['debit_cents'])):?><div class="summary"><div class="metric"><small>Total Debit</small><strong>₱<?=arp_format_cents($summary['debit_cents'])?></strong></div><div class="metric"><small>Total Credit</small><strong>₱<?=arp_format_cents($summary['credit_cents'])?></strong></div><div class="metric"><small>Difference</small><strong>₱<?=arp_format_cents($summary['debit_cents']-$summary['credit_cents'])?></strong></div></div><?php endif;?>
<?php if($type==='balance_sheet'):
    $assetCents=(int)($summary['type_'.($summary['asset_type']??1).'_cents']??0);
    $liabilityCents=-(int)($summary['type_'.($summary['liability_type']??2).'_cents']??0);
    $equityCents=-(int)($summary['type_'.($summary['equity_type']??3).'_cents']??0);
?><div class="summary"><div class="metric"><small>Assets</small><strong>₱<?=arp_format_cents($assetCents)?></strong></div><div class="metric"><small>Liabilities</small><strong>₱<?=arp_format_cents($liabilityCents)?></strong></div><div class="metric"><small>Equity</small><strong>₱<?=arp_format_cents($equityCents)?></strong></div><div class="metric"><small>Accounting Difference</small><strong>₱<?=arp_format_cents($assetCents-$liabilityCents-$equityCents)?></strong></div></div><?php endif;?>
<?php if($type==='income_statement'):?><div class="summary"><div class="metric"><small>Income</small><strong>₱<?=arp_format_cents((int)($summary['income_cents']??0))?></strong></div><div class="metric"><small>Expense</small><strong>₱<?=arp_format_cents((int)($summary['expense_cents']??0))?></strong></div><div class="metric"><small>Net</small><strong>₱<?=arp_format_cents((int)($summary['income_cents']??0)-(int)($summary['expense_cents']??0))?></strong></div></div><?php endif;?>
<div class="table-wrap"><table><thead><tr>
<?php if($type==='chart_of_accounts'):?><th>ID</th><th>Account</th><th>Type</th><th>Base</th><th>Parent</th><th>Summary</th><th>With SL</th><th>Status</th><th>Description</th>
<?php elseif($type==='general_ledger'):?><th>Date</th><th>Voucher / Reference</th><th>Account</th><th>Description</th><th>Subsidiary</th><th class="money">Debit</th><th class="money">Credit</th>
<?php elseif($type==='trial_balance'):?><th>Account ID</th><th>Account</th><th>Type</th><th class="money">Debit</th><th class="money">Credit</th><th class="money">Balance</th>
<?php elseif($type==='balance_sheet'):?><th>Account ID</th><th>Account</th><th>Configured Type</th><th class="money">Debit Balance</th><th class="money">Credit Balance</th>
<?php else:?><th>Account ID</th><th>Account</th><th>Configured Type</th><th class="money">Debit</th><th class="money">Credit</th><th class="money">Net Credit</th><?php endif;?></tr></thead><tbody>
<?php if(!$rows): $emptyColspan = $type==='chart_of_accounts' ? 9 : ($type==='general_ledger' ? 7 : ($type==='trial_balance' ? 6 : ($type==='balance_sheet' ? 5 : 6))); ?><tr><td class="empty" colspan="<?=$emptyColspan?>">No records matched the selected filters.</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr>
<?php if($type==='chart_of_accounts'):?><td><?=e($r['AccntID'])?></td><td><?=e($r['AccntName'])?></td><td><span class="badge"><?=e($r['Type'])?></span></td><td><?=e($r['BaseAccnt'])?></td><td><?=e($r['ParentAccnt'])?></td><td><?=e($r['SummaryAccnt'])?></td><td><?=!empty($r['WithSL'])?'Yes':'No'?></td><td><?=e($r['Status'])?></td><td><?=e($r['Description'])?></td>
<?php elseif($type==='general_ledger'):?><td><?=e($r['TransactionDate'])?></td><td><span class="mono screen-ref" data-full-ref="<?=e($r['JournalHeadID'])?>" title="<?=e($r['JournalHeadID'])?>"><?=e(ui_compact_reference($r['JournalHeadID'],18))?></span><?php if(trim((string)($r['TransRefID']??''))!==''):?><br><span class="screen-ref" data-full-ref="Ref: <?=e($r['TransRefID'])?>" style="color:#6b7280;font-size:.68rem" title="<?=e($r['TransRefID'])?>">Ref: <?=e(ui_compact_reference($r['TransRefID'],18))?></span><?php endif;?></td><td><?=e($r['AccountID'].' — '.$r['AccntName'])?></td><td><?=e($r['Description'])?></td><td><?=e(arp_sl_label($r['SLType']??'', $r['SLCode']??''))?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Debit']))?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Credit']))?></td>
<?php elseif($type==='trial_balance'):?><td><?=e($r['AccntID'])?></td><td><?=e($r['AccntName'])?></td><td><?=e($r['Type'])?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Debit']))?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Credit']))?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Balance']))?></td>
<?php elseif($type==='balance_sheet'): $balCents=arp_money_cents($r['Balance']);?><td><?=e($r['AccntID'])?></td><td><?=e($r['AccntName'])?></td><td><?=e($r['Type'])?></td><td class="money"><?=arp_format_cents(max(0,$balCents))?></td><td class="money"><?=arp_format_cents(max(0,-$balCents))?></td>
<?php else:?><td><?=e($r['AccntID'])?></td><td><?=e($r['AccntName'])?></td><td><?=e($r['Type'])?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Debit']))?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['Credit']))?></td><td class="money"><?=arp_format_cents(arp_money_cents($r['NetCredit']))?></td><?php endif;?>
</tr><?php endforeach;?></tbody></table></div>
<?php if($type==='general_ledger'): $queryBase=$_GET; ?>
<div class="pager no-print">
    <div class="pager-meta">Showing <?=number_format(count($rows))?> of <?=number_format($totalRows)?> filtered journal line(s), Page <?=$page?> of <?=$totalPages?></div>
    <div class="pager-links">
        <?php $queryBase['page']=max(1,$page-1); ?><a class="<?=$page<=1?'disabled':''?>" href="?<?=e(http_build_query($queryBase))?>">Previous</a>
        <?php $queryBase['page']=min($totalPages,$page+1); ?><a class="<?=$page>=$totalPages?'disabled':''?>" href="?<?=e(http_build_query($queryBase))?>">Next</a>
    </div>
</div>
<?php endif;?>
</main></div></body></html>
