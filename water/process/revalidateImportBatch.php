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
    // Start a resumable revalidation. The review page continues it in bounded
    // chunks; without JavaScript the whole batch is processed here instead.
    di_revalidate_begin($pdo, $batchId);
    if ((string)($_POST['sync'] ?? '') === '1') {
        @set_time_limit(0);
        do { $result = di_revalidate_chunk($pdo, $batchId, 2000); } while (empty($result['done']));
        $_SESSION['flash']['success'] = 'Batch revalidated against the current database and mapping decisions. Already imported portions kept their identities.';
    } else {
        $_SESSION['flash']['success'] = 'Revalidation started. Unresolved rows are being re-checked in bounded chunks; already imported portions keep their identities.';
    }
} catch (Throwable $e) {
    if ($e instanceof PDOException) { error_log('[import revalidate] ' . $e->getMessage()); $_SESSION['flash']['error'] = 'Revalidation could not start because of a database error.'; }
    else $_SESSION['flash']['error'] = $e->getMessage();
}
header('Location: ../views/import_management.php?batch_id=' . $batchId);
exit;
