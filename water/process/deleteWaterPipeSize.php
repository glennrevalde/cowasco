<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo, 'water_meter');
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';

function pipe_size_delete_redirect(): void { header('Location: ../views/water_meter.php?tab=pipe-sizes'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pipe_size_delete_redirect(); }
try {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    requireOperationToken('delete_water_pipe_size', (string)($_POST['operation_token'] ?? ''));
    $id = (int)($_POST['pipe_size_id'] ?? 0);
    if ($id <= 0) { throw new InvalidArgumentException('Invalid pipe size.'); }

    $pdo->beginTransaction();
    $pipe = water_pipe_size_get($pdo, $id, true);
    if (strcasecmp((string)$pipe['size_code'], 'UNSPECIFIED') === 0) {
        throw new RuntimeException('The Unspecified / Existing pipe size cannot be deleted.');
    }
    $usage = $pdo->prepare('SELECT (SELECT COUNT(*) FROM water_accounts WHERE pipe_size_id = ?) AS member_count, (SELECT COUNT(*) FROM water_meter_members WHERE pipe_size_id = ?) AS tier_count');
    $usage->execute([$id, $id]);
    $counts = $usage->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($counts['member_count'] ?? 0) > 0 || (int)($counts['tier_count'] ?? 0) > 0) {
        throw new RuntimeException('This pipe size is in use and cannot be deleted. Reassign its members and remove its tariff tiers first.');
    }
    $pdo->prepare('DELETE FROM water_pipe_sizes WHERE pipe_size_id = ?')->execute([$id]);
    $pdo->commit();
    consumeOperationToken('delete_water_pipe_size', (string)$_POST['operation_token']);
    $_SESSION['flash']['success'] = 'Pipe size deleted.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['flash']['error'] = $e->getMessage();
}
pipe_size_delete_redirect();
