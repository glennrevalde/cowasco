<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo, 'water_meter');
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';

function pipe_size_redirect(): void { header('Location: ../views/water_meter.php?tab=pipe-sizes'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pipe_size_redirect(); }
try {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    requireOperationToken('add_water_pipe_size', (string)($_POST['operation_token'] ?? ''));
    $code = water_pipe_size_normalize_code($_POST['size_code'] ?? '');
    $label = water_pipe_size_normalize_label($_POST['size_label'] ?? '');
    $diameter = water_pipe_size_normalize_diameter($_POST['diameter_inches'] ?? null);
    $createdBy = (string)($_SESSION['username'] ?? 'admin');

    $pdo->beginTransaction();
    $dupe = $pdo->prepare('SELECT pipe_size_id FROM water_pipe_sizes WHERE UPPER(size_code) = UPPER(?) OR UPPER(size_label) = UPPER(?) LIMIT 1 FOR UPDATE');
    $dupe->execute([$code, $label]);
    if ($dupe->fetchColumn()) {
        throw new InvalidArgumentException('A pipe size with the same code or label already exists.');
    }
    $stmt = $pdo->prepare('INSERT INTO water_pipe_sizes (size_code, size_label, diameter_inches, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, 1, ?, NOW(), NOW())');
    $stmt->execute([$code, $label, $diameter, $createdBy]);
    $pdo->commit();
    consumeOperationToken('add_water_pipe_size', (string)$_POST['operation_token']);
    $_SESSION['flash']['success'] = "Pipe size {$label} was added. You may now configure tariffs and assign it to members.";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['flash']['error'] = $e->getMessage();
}
pipe_size_redirect();
