<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/waterRateGroupHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo);

function water_rate_group_redirect_delete(): void
{
    header('Location: ../views/water_meter.php?tab=rates');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    water_rate_group_redirect_delete();
}

try {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    requireOperationToken('delete_water_rate_group', (string)($_POST['operation_token'] ?? ''));

    $rateCodeId = (int)($_POST['rc_id'] ?? 0);
    $pipeSizeId = (int)($_POST['pipe_size_id'] ?? 0);
    if ($rateCodeId <= 0 || $pipeSizeId <= 0) {
        throw new InvalidArgumentException('Invalid tariff group.');
    }

    $pdo->beginTransaction();
    $rateCode = water_rate_group_validate_rate_code($pdo, $rateCodeId, true);
    $pipeSize = water_pipe_size_get($pdo, $pipeSizeId, true);
    $tiers = water_rate_group_lock($pdo, $rateCodeId, $pipeSizeId);
    if (!$tiers) {
        throw new InvalidArgumentException('The tariff group no longer exists.');
    }

    $assignedMembers = water_rate_group_member_count($pdo, $rateCodeId, $pipeSizeId);
    if ($assignedMembers > 0) {
        throw new InvalidArgumentException("This tariff group is assigned to {$assignedMembers} member(s). Reassign those members before deleting the group.");
    }

    $stmt = $pdo->prepare('DELETE FROM water_meter_members WHERE rc_id = ? AND pipe_size_id = ?');
    $stmt->execute([$rateCodeId, $pipeSizeId]);

    $pdo->commit();
    consumeOperationToken('delete_water_rate_group', (string)$_POST['operation_token']);
    $_SESSION['flash']['success'] = sprintf(
        'Deleted the complete tariff group for Rate Code %s and %s.',
        (string)$rateCode['rc_code'],
        water_pipe_size_format($pipeSize)
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['flash']['error'] = $e->getMessage();
}

water_rate_group_redirect_delete();
