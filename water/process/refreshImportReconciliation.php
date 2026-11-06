<?php
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
    @set_time_limit(120);
    if (!di_batch($pdo, $batchId)) throw new RuntimeException('Import batch was not found.');
    di_batch_reconciliation($pdo, $batchId, true);
    $_SESSION['flash']['success'] = 'Reconciliation totals recomputed from the staged rows.';
} catch (Throwable $e) {
    $_SESSION['flash']['error'] = $e instanceof PDOException ? 'Totals could not be recomputed because of a database error.' : $e->getMessage();
}
header('Location: ../views/import_management.php?batch_id=' . $batchId);
exit;
