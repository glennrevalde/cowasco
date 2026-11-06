<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo, 'water_meter');
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';

function pipe_size_edit_redirect(): void { header('Location: ../views/water_meter.php?tab=pipe-sizes'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pipe_size_edit_redirect(); }
try {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    requireOperationToken('edit_water_pipe_size', (string)($_POST['operation_token'] ?? ''));
    $id = (int)($_POST['pipe_size_id'] ?? 0);
    $code = water_pipe_size_normalize_code($_POST['size_code'] ?? '');
    $label = water_pipe_size_normalize_label($_POST['size_label'] ?? '');
    $diameter = water_pipe_size_normalize_diameter($_POST['diameter_inches'] ?? null);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($id <= 0) { throw new InvalidArgumentException('Invalid pipe size.'); }

    $pdo->beginTransaction();
    $current = water_pipe_size_get($pdo, $id, true);
    $dupe = $pdo->prepare('SELECT pipe_size_id FROM water_pipe_sizes WHERE pipe_size_id <> ? AND (UPPER(size_code) = UPPER(?) OR UPPER(size_label) = UPPER(?)) LIMIT 1 FOR UPDATE');
    $dupe->execute([$id, $code, $label]);
    if ($dupe->fetchColumn()) {
        throw new InvalidArgumentException('A different pipe size already uses the same code or label.');
    }
    if ($isActive === 0) {
        $usage = $pdo->prepare('SELECT (SELECT COUNT(*) FROM water_accounts WHERE pipe_size_id = ?) AS member_count, (SELECT COUNT(*) FROM water_meter_members WHERE pipe_size_id = ?) AS tier_count');
        $usage->execute([$id, $id]);
        $counts = $usage->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($counts['member_count'] ?? 0) > 0 || (int)($counts['tier_count'] ?? 0) > 0) {
            throw new RuntimeException('This pipe size is still assigned to members or water-rate tiers. Reassign or remove those records before deactivating it.');
        }
    }
    if (strcasecmp((string)$current['size_code'], 'UNSPECIFIED') === 0 && $isActive === 0) {
        throw new RuntimeException('The Unspecified / Existing pipe size must remain active for migrated records.');
    }
    $stmt = $pdo->prepare('UPDATE water_pipe_sizes SET size_code = ?, size_label = ?, diameter_inches = ?, is_active = ?, updated_at = NOW() WHERE pipe_size_id = ?');
    $stmt->execute([$code, $label, $diameter, $isActive, $id]);
    $pdo->commit();
    consumeOperationToken('edit_water_pipe_size', (string)$_POST['operation_token']);
    $_SESSION['flash']['success'] = "Pipe size {$label} was updated.";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['flash']['error'] = $e->getMessage();
}
pipe_size_edit_redirect();
