<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
require_once __DIR__ . '/../common/dataImportHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo, 'import_management');
business_require_mutation($pdo, '../views/import_management.php');
$redirect = static function (?int $batchId = null): void {
    header('Location: ../views/import_management.php' . ($batchId ? '?batch_id=' . $batchId : ''));
    exit;
};
try {
    $userId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
    $options = di_upload_options($_POST);
    $profileId = (int)($_POST['mapping_profile_id'] ?? 0);
    if ($profileId > 0) $options['mapping_profile_id'] = $profileId;

    $stored = di_store_uploaded_source($_FILES['csv_file'] ?? []);
    try {
        // Staging is resumable: the batch is created here and its rows are
        // validated in bounded chunks by process/stageImportChunk.php from the
        // review page, so a 70,000-row file never depends on one long request.
        $batchId = di_stage_begin($pdo, $stored['path'], $stored['original_name'], $stored['sha256'], $options, $userId);
    } catch (Throwable $e) {
        @unlink($stored['path']);
        @unlink(di_stage_sidecar_path(basename($stored['path'])));
        throw $e;
    }

    $profileName = trim((string)($_POST['save_profile_name'] ?? ''));
    if ($profileName !== '') {
        try {
            $type = (string)$pdo->query('SELECT import_type FROM data_import_batches WHERE batch_id=' . (int)$batchId)->fetchColumn();
            di_save_mapping_profile($pdo, $profileName, $type ?: 'any', $options, $userId);
        } catch (Throwable $profileError) {
            $_SESSION['flash']['error'] = 'The batch was staged, but the mapping profile could not be saved: ' . $profileError->getMessage();
        }
    }
    $_SESSION['flash']['success'] = 'Source file stored (SHA-256 ' . substr($stored['sha256'], 0, 12) . '…). Staging and validation continue in bounded chunks below; nothing operational is written until you confirm an import.';
    $redirect($batchId);
} catch (Throwable $e) {
    $_SESSION['flash']['error'] = $e instanceof PDOException ? 'The upload could not be staged because of a database error. Nothing was changed.' : $e->getMessage();
    if ($e instanceof PDOException) error_log('[import staging] ' . $e->getMessage());
    $redirect();
}
