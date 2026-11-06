<?php
/**
 * process/updateImportBatchOptions.php
 *
 * Mapping editor for an EXISTING batch (audit I05): tariff, discount,
 * recurring-plan and organization mappings can be corrected in place and the
 * batch is revalidated, so a configuration fix never strands a partially
 * imported upload. Imported rows keep their identities; only unresolved rows
 * are re-checked. The previous options are kept as a versioned snapshot.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
require_once __DIR__ . '/../common/dataImportHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo, 'import_management');
business_require_mutation($pdo, '../views/import_management.php');
$batchId = max(0, (int)($_POST['batch_id'] ?? 0));
try {
    $userId = (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0);
    $batch = di_batch($pdo, $batchId);
    if (!$batch) throw new RuntimeException('Import batch was not found.');
    if (in_array((string)$batch['status'], ['staging', 'importing', 'revalidating'], true)) {
        throw new RuntimeException('Wait for the current staging/import/revalidation step to finish before changing mappings.');
    }
    $previous = di_json_decode((string)$batch['options_json']);
    $options = di_upload_options($_POST);
    $options['stage_payments'] = !empty($previous['stage_payments']) || !empty($options['stage_payments']);
    $history = is_array($previous['option_history'] ?? null) ? $previous['option_history'] : [];
    unset($previous['option_history']);
    $history[] = ['replaced_at' => date('c'), 'by' => $userId, 'options' => $previous];
    $options['option_history'] = array_slice($history, -10);
    $options['mapping_profile_id'] = (int)($_POST['mapping_profile_id'] ?? ($previous['mapping_profile_id'] ?? 0));
    $pdo->prepare('UPDATE data_import_batches SET options_json=? WHERE batch_id=?')->execute([di_json_encode($options), $batchId]);
    di_audit($pdo, $userId, 'IMPORT_BATCH_OPTIONS', 'data_import_batches', (string)$batchId, ['version' => count($options['option_history'])]);

    $profileName = trim((string)($_POST['save_profile_name'] ?? ''));
    if ($profileName !== '') {
        di_save_mapping_profile($pdo, $profileName, (string)$batch['import_type'], $options, $userId);
    }
    di_revalidate_begin($pdo, $batchId);
    $_SESSION['flash']['success'] = 'Batch mappings updated (version ' . count($options['option_history']) . '). Revalidation of the unresolved rows is running; imported rows keep their identities.';
} catch (Throwable $e) {
    if ($e instanceof PDOException) { error_log('[import options] ' . $e->getMessage()); $_SESSION['flash']['error'] = 'The batch mappings could not be updated because of a database error.'; }
    else $_SESSION['flash']['error'] = $e->getMessage();
}
header('Location: ../views/import_management.php?batch_id=' . $batchId);
exit;
