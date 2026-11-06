<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/dataImportHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo, 'import_management');
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
// Member-only imports need no tariff/billing setup; financial modes do.
business_require_mutation($pdo, '../views/import_management.php', ['action' => ((string)($_POST['mode'] ?? '') === 'members' ? 'import_members' : 'import'), 'json' => true]);
/* BUSINESS_MUTATION_GUARD_END */

$batchId = max(0, (int)($_POST['batch_id'] ?? 0));
$isAjax = (string)($_POST['ajax'] ?? '') === '1'
    || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Invalid or expired request. Refresh the page and try again.');
    }

    $mode = (string)($_POST['mode'] ?? '');
    // the class chosen beside the import buttons becomes the batch's class: every bill, charge, collection and
    // opening balance the import posts carries it (the run refuses to start without one)
    if ($mode !== 'members' && isset($_POST['class_id']) && (int)$_POST['class_id'] > 0) {
        require_once __DIR__ . '/organizationUnitHelpers.php';
        $chosenClass = water_required_class($_POST['class_id'], 'the import');
        $batchRow = di_batch($pdo, $batchId);
        if ($batchRow) {
            $batchOptions = di_json_decode((string)$batchRow['options_json']);
            if ((int)($batchOptions['default_class_id'] ?? 0) !== $chosenClass) {
                $batchOptions['default_class_id'] = $chosenClass;
                $pdo->prepare('UPDATE data_import_batches SET options_json = ? WHERE batch_id = ?')->execute([di_json_encode($batchOptions), $batchId]);
            }
        }
    }
    $result = di_import_chunk(
        $pdo,
        $batchId,
        $mode,
        250,
        (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0),
        ['cutover_date' => (string)($_POST['cutover_date'] ?? ''), 'include_held' => (string)($_POST['include_held'] ?? '') === '1']
    );

    if ($isAjax) {
        echo json_encode([
            'ok' => true,
            'batch_id' => $batchId,
            'mode' => $mode,
            'processed' => (int)($result['processed'] ?? 0),
            'failed' => (int)($result['failed'] ?? 0),
            'remaining' => (int)($result['remaining'] ?? 0),
            'batch_status' => (string)($result['batch_status'] ?? 'in_progress'),
            'counts' => is_array($result['counts'] ?? null) ? $result['counts'] : [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash']['success'] = 'Processed ' . (int)$result['processed'] . ' rows; ' . (int)$result['failed'] . ' failed in this chunk.';
} catch (Throwable $e) {
    if ($isAjax) {
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'batch_id' => $batchId,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['flash']['error'] = $e->getMessage();
}

header('Location: ../views/import_management.php?batch_id=' . $batchId);
exit;
