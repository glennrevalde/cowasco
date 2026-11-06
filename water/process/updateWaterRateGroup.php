<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/waterRateGroupHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo);

function water_rate_group_redirect_update(): void
{
    header('Location: ../views/water_meter.php?tab=rates');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    water_rate_group_redirect_update();
}

try {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    requireOperationToken('update_water_rate_group', (string)($_POST['operation_token'] ?? ''));

    $oldRateCodeId = (int)($_POST['old_rc_id'] ?? 0);
    $oldPipeSizeId = (int)($_POST['old_pipe_size_id'] ?? 0);
    $newRateCodeId = (int)($_POST['rc_id'] ?? 0);
    $newPipeSizeId = (int)($_POST['pipe_size_id'] ?? 0);
    if ($oldRateCodeId <= 0 || $oldPipeSizeId <= 0) {
        throw new InvalidArgumentException('The tariff group to update is invalid.');
    }

    $tiers = water_rate_group_parse_tiers($_POST);
    $user = (string)($_SESSION['username'] ?? 'admin');

    $pdo->beginTransaction();

    // Lock pairs in deterministic order to reduce concurrent-edit deadlocks.
    $pairs = [
        water_rate_group_key($oldRateCodeId, $oldPipeSizeId) => [$oldRateCodeId, $oldPipeSizeId],
        water_rate_group_key($newRateCodeId, $newPipeSizeId) => [$newRateCodeId, $newPipeSizeId],
    ];
    ksort($pairs, SORT_STRING);
    foreach ($pairs as [$rcId, $pipeId]) {
        water_rate_group_validate_rate_code($pdo, $rcId, true);
        water_pipe_size_get($pdo, $pipeId, true);
        water_rate_group_lock($pdo, $rcId, $pipeId);
    }

    $oldGroup = water_rate_group_lock($pdo, $oldRateCodeId, $oldPipeSizeId);
    if (!$oldGroup) {
        throw new InvalidArgumentException('The tariff group no longer exists. Reload the page and try again.');
    }

    $newRateCode = water_rate_group_validate_rate_code($pdo, $newRateCodeId, true);
    $newPipeSize = water_pipe_size_require_active($pdo, $newPipeSizeId, true);

    $targetChanged = $oldRateCodeId !== $newRateCodeId || $oldPipeSizeId !== $newPipeSizeId;
    if ($targetChanged && water_rate_group_exists($pdo, $newRateCodeId, $newPipeSizeId, true)) {
        throw new InvalidArgumentException('Another tariff group already uses the selected Rate Code and Pipe Size. Choose a different combination.');
    }

    // Group-level replacement guarantees that a Rate Code/Pipe Size change affects every tier.
    $delete = $pdo->prepare('DELETE FROM water_meter_members WHERE rc_id = ? AND pipe_size_id = ?');
    $delete->execute([$oldRateCodeId, $oldPipeSizeId]);
    water_rate_group_insert_tiers($pdo, $newRateCodeId, $newPipeSizeId, $tiers, $user);

    $saved = water_rate_group_lock($pdo, $newRateCodeId, $newPipeSizeId);
    water_rate_validate_tiers($saved, true, false);

    $pdo->commit();
    consumeOperationToken('update_water_rate_group', (string)$_POST['operation_token']);

    $_SESSION['flash']['success'] = sprintf(
        'Tariff group updated. All %d tier(s) now belong to Rate Code %s and %s.',
        count($tiers),
        (string)$newRateCode['rc_code'],
        water_pipe_size_format($newPipeSize)
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['flash']['error'] = $e->getMessage();
}

water_rate_group_redirect_update();
