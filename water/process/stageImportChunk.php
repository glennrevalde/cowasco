<?php
/**
 * process/stageImportChunk.php
 *
 * Resumable staging / revalidation worker. Each call validates one bounded
 * chunk of source rows inside its own transaction and returns progress, so
 * an interrupted upload or revalidation resumes from the stored cursor.
 * Concurrent calls for the same batch serialize on the batch row lock; the
 * second one simply continues from wherever the first one stopped.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
require_once __DIR__ . '/../common/dataImportHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo, 'import_management');
business_require_mutation($pdo, '../views/import_management.php', ['json' => true]);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$batchId = max(0, (int)($_POST['batch_id'] ?? 0));
$action = (string)($_POST['action'] ?? 'stage');
try {
    @set_time_limit(120);
    $batch = di_batch($pdo, $batchId);
    if (!$batch) throw new RuntimeException('Import batch was not found.');
    $path = di_storage_dir() . DIRECTORY_SEPARATOR . basename((string)$batch['stored_file_name']);
    $estimate = is_file($path) ? di_source_row_estimate($path) : 0;
    if ($action === 'revalidate') {
        if ((string)$batch['status'] !== 'revalidating') {
            // Revalidation is started explicitly (Revalidate batch / mapping
            // editor); a late or duplicate chunk request never restarts it.
            echo json_encode(['ok' => true, 'action' => 'revalidate', 'batch_id' => $batchId, 'processed' => 0, 'remaining' => 0, 'locked' => 0, 'done' => true, 'total' => (int)($batch['row_count'] ?? 0), 'batch_status' => (string)$batch['status']]);
            exit;
        }
        $result = di_revalidate_chunk($pdo, $batchId, 2000);
        $done = !empty($result['done']);
        echo json_encode(['ok' => true, 'action' => 'revalidate', 'batch_id' => $batchId, 'processed' => (int)$result['processed'], 'remaining' => (int)($result['remaining'] ?? 0), 'locked' => (int)($result['locked'] ?? 0), 'done' => $done, 'total' => (int)($batch['row_count'] ?? 0), 'batch_status' => (string)($result['batch_status'] ?? 'revalidating')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((string)$batch['status'] !== 'staging') {
        echo json_encode(['ok' => true, 'action' => 'stage', 'batch_id' => $batchId, 'done' => true, 'staged_rows' => (int)$batch['row_count'], 'total' => $estimate, 'batch_status' => (string)$batch['status']]);
        exit;
    }
    $result = di_stage_chunk($pdo, $batchId, 2000);
    echo json_encode(['ok' => true, 'action' => 'stage', 'batch_id' => $batchId, 'processed' => (int)$result['processed'], 'staged_rows' => (int)$result['staged_rows'], 'total' => $estimate, 'done' => !empty($result['done']), 'batch_status' => !empty($result['done']) ? 'staged' : 'staging'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    if ($e instanceof PDOException) error_log('[import chunk] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'batch_id' => $batchId, 'message' => $e instanceof PDOException ? 'A database error interrupted this chunk. Rows already committed are kept; press Resume to continue.' : $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
