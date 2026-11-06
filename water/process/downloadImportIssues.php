<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/dataImportHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo, 'import_management');
$batchId = max(0, (int)($_GET['batch_id'] ?? 0));

// Optional filters (same vocabulary as the on-screen filter).
$status = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['status'] ?? '')));
$scope = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['scope'] ?? '')));
$severity = preg_replace('/[^a-z]/', '', strtolower((string)($_GET['severity'] ?? '')));
$code = preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)($_GET['code'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="import_issues_' . $batchId . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // BOM so spreadsheets read accented names correctly
fputcsv($out, ['Row', 'Account', 'Source key', 'Primary Status', 'Recurring Status', 'Payment Status', 'Scope', 'Severity', 'Code', 'Message', 'Source value(s)', 'Existing value(s)']);

$where = 'batch_id=?';
$params = [$batchId];
if ($status !== '') { $where .= ' AND (import_status=? OR payment_status=? OR recurring_status=?)'; array_push($params, $status, $status, $status); }
if ($code !== '') { $where .= ' AND issues_json LIKE ?'; $params[] = '%"code":"' . $code . '"%'; }
if ($q !== '') { $where .= ' AND (external_account_no LIKE ? OR source_key LIKE ? OR raw_json LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
// pages of 1,000 rows, so a large batch (a Billing Master holds about 70,000) never has to fit in PHP's memory at once
$stmt = $pdo->prepare('SELECT `row_number`,external_account_no,source_key,import_status,recurring_status,payment_status,issues_json,normalized_json FROM data_import_rows WHERE ' . $where . ' AND `row_number` > ? ORDER BY `row_number` LIMIT 1000');
$after = -1;
do {
    $stmt->execute(array_merge($params, [$after]));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    foreach ($rows as $row) {
        $after = (int)$row['row_number'];
        $normalized = di_json_decode($row['normalized_json'] ?? '{}');
        $sourceValues = '';
        $existingValues = '';
        if (!empty($normalized['existing']['comparison'])) {
            $src = []; $ex = [];
            foreach ($normalized['existing']['comparison'] as $entry) {
                if (empty($entry['differs'])) continue;
                $src[] = $entry['field'] . ': ' . $entry['source'];
                $ex[] = $entry['field'] . ': ' . $entry['existing'];
            }
            $sourceValues = implode(' | ', $src);
            $existingValues = implode(' | ', $ex);
        }
        foreach (di_json_decode($row['issues_json'] ?? '[]') as $issue) {
            if ($scope !== '' && ($issue['scope'] ?? '') !== $scope) continue;
            if ($severity !== '' && ($issue['severity'] ?? '') !== $severity) continue;
            if ($code !== '' && ($issue['code'] ?? '') !== $code) continue;
            fputcsv($out, array_map('di_csv_safe_cell', [
                $row['row_number'], $row['external_account_no'], $row['source_key'], $row['import_status'], $row['recurring_status'], $row['payment_status'],
                $issue['scope'] ?? '', $issue['severity'] ?? '', $issue['code'] ?? '', $issue['message'] ?? '', $sourceValues, $existingValues,
            ]));
        }
    }
} while (count($rows) === 1000);
fclose($out);
