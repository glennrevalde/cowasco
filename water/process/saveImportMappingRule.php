<?php
/**
 * process/saveImportMappingRule.php
 *
 * Confirms (or marks unresolved) one importer mapping decision. Changing a
 * rule never rewrites staged rows by itself: batches validated under an older
 * rule snapshot are blocked from importing until they are revalidated.
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
    $key = trim((string)($_POST['rule_key'] ?? ''));
    $value = trim((string)($_POST['rule_value'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    di_save_mapping_rule($pdo, $key, $value, $notes, (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0));
    $_SESSION['flash']['success'] = 'Mapping decision saved. Revalidate any staged batch so its rows are re-checked under the confirmed rules.';
} catch (Throwable $e) {
    if ($e instanceof PDOException) { error_log('[import rule] ' . $e->getMessage()); $_SESSION['flash']['error'] = 'The mapping decision could not be saved because of a database error.'; }
    else $_SESSION['flash']['error'] = $e->getMessage();
}
header('Location: ../views/import_management.php' . ($batchId ? '?batch_id=' . $batchId : '') . '#mappingDecisionsPanel');
exit;
