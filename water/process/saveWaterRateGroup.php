<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/waterRateGroupHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo);

function water_rate_group_redirect_create(): void
{
    header('Location: ../views/water_meter.php?tab=rates');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    water_rate_group_redirect_create();
}

try {
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    requireOperationToken('create_water_rate_group', (string)($_POST['operation_token'] ?? ''));

    $rateCodeId = (int)($_POST['rc_id'] ?? 0);
    $pipeSizeId = (int)($_POST['pipe_size_id'] ?? 0);
    $tiers = water_rate_group_parse_tiers($_POST);
    $user = (string)($_SESSION['username'] ?? 'admin');

    $pdo->beginTransaction();
    $rateCode = water_rate_group_validate_rate_code($pdo, $rateCodeId, true);
    $pipeSize = water_pipe_size_require_active($pdo, $pipeSizeId, true);

    // Lock the target pair before checking it so two users cannot create the same group.
    water_rate_group_lock($pdo, $rateCodeId, $pipeSizeId);
    if (water_rate_group_exists($pdo, $rateCodeId, $pipeSizeId, true)) {
        throw new InvalidArgumentException('A tariff group already exists for this Rate Code and Pipe Size. Edit the existing group instead.');
    }

    water_rate_group_insert_tiers($pdo, $rateCodeId, $pipeSizeId, $tiers, $user);
    $saved = water_rate_group_lock($pdo, $rateCodeId, $pipeSizeId);
    water_rate_validate_tiers($saved, true, false);

    $pdo->commit();
    consumeOperationToken('create_water_rate_group', (string)$_POST['operation_token']);

    $_SESSION['flash']['success'] = sprintf(
        'Tariff group created for Rate Code %s and %s with %d complete tier(s).',
        (string)$rateCode['rc_code'],
        water_pipe_size_format($pipeSize),
        count($tiers)
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $_SESSION['flash']['error'] = $e->getMessage();
}

water_rate_group_redirect_create();
