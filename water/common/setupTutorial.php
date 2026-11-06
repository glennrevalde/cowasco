<?php
/**
 * common/setupTutorial.php — FORCE CONFIGURATION guided setup.
 *
 * Included by common/navbar.php on authenticated business pages only. It is
 * never part of the operator login, registration, password or profile
 * selection screens: it appears only after the existing login has completed
 * normally, on the first authenticated business page and whenever a required
 * business configuration becomes invalid again.
 *
 * Rendering is server-side so the required dialog exists even with JavaScript
 * disabled; common/setup-tutorial.js only adds focus management, the spotlight
 * on setup pages and the dynamic dialog for configuration_required API
 * answers. Completion is derived from saved configuration by
 * common/setupReadinessHelpers.php; opening a page or clicking a button never
 * counts as completion, and the same rules gate every business mutation.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/setupReadinessHelpers.php';

if (!function_exists('st_e')) {
    function st_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$st_pdo = getDB();
$st_page = basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php');
try {
    $st_summary = sr_summary($st_pdo, $st_page, false);
} catch (Throwable $e) {
    $st_summary = ['complete' => true, 'steps' => [], 'current' => null, 'total_steps' => 0, 'completed_steps' => 0, 'is_setup_route' => true];
}
$st_current = $st_summary['current'] ?? null;
$st_complete = !empty($st_summary['complete']);
$st_is_setup_route = !empty($st_summary['is_setup_route']);
$st_destination_page = $st_current ? (string)($st_current['destination_page_key'] ?? '') : '';
$st_on_destination = $st_current && $st_destination_page !== '' && $st_destination_page === $st_page;
$st_mode = $st_complete ? 'none' : ($st_is_setup_route ? 'banner' : 'modal');
$st_operator_can = $st_current ? !empty($st_current['can_configure']) : false;
$st_destination_url = ($st_current && $st_operator_can && (string)$st_current['destination'] !== '') ? (string)$st_current['destination'] : '';
$st_payload = [
    'mode' => $st_mode,
    'page' => $st_page,
    'onDestination' => $st_on_destination,
    'current' => $st_current ? [
        'key' => $st_current['key'], 'title' => $st_current['title'], 'message' => $st_current['message'],
        'position' => $st_current['position'], 'focus' => $st_current['focus'], 'destination' => $st_destination_url,
        'canConfigure' => $st_operator_can, 'task' => $st_current['task'], 'status' => $st_current['status'],
    ] : null,
    'totalSteps' => (int)($st_summary['total_steps'] ?? 0),
    'completedSteps' => (int)($st_summary['completed_steps'] ?? 0),
    'readinessUrl' => '../process/getSetupReadiness.php?page=' . rawurlencode($st_page),
    'progressUrl' => '../process/saveSetupTutorialProgress.php',
];
?>
<link rel="stylesheet" href="../common/setup-tutorial.css">
<script>window.SetupTutorialConfig = <?= json_encode($st_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<?php if ($st_mode === 'modal' && $st_current): ?>
<div class="setup-tutorial-overlay is-blocking" id="setupTutorialOverlay" role="dialog" aria-modal="true" aria-labelledby="setupTutorialTitle" aria-describedby="setupTutorialDescription" tabindex="-1">
    <div class="setup-tutorial-dialog">
        <div class="setup-tutorial-progress" aria-live="polite">
            Step <?= (int)$st_current['position'] ?> of <?= (int)$st_summary['total_steps'] ?>
            <span class="setup-tutorial-progress-done"><?= (int)$st_summary['completed_steps'] ?> completed</span>
        </div>
        <div class="setup-tutorial-track" aria-hidden="true"><div class="setup-tutorial-fill" style="width: <?= $st_summary['total_steps'] > 0 ? (int)round(100 * $st_summary['completed_steps'] / max(1, $st_summary['total_steps'])) : 0 ?>%"></div></div>
        <h2 class="setup-tutorial-title" id="setupTutorialTitle">
            <?= $st_operator_can ? 'Configure this first: ' : 'An administrator must complete: ' ?><?= st_e($st_current['title']) ?>
        </h2>
        <div class="setup-tutorial-body" id="setupTutorialDescription">
            <p class="setup-tutorial-why"><strong>Why this step is required.</strong> <?= st_e($st_current['message']) ?></p>
            <p class="setup-tutorial-blocked"><strong>Blocked until then:</strong> <?= st_e($st_current['task']) ?>. Configuration pages, help, your profile and sign-out stay available.</p>
            <?php if (!$st_operator_can): ?>
                <p class="setup-tutorial-admin">Your operator profile is not allowed to change this setting. Ask an existing authorized administrator to complete it; no new permission is granted by this tutorial.</p>
            <?php elseif ($st_destination_url === ''): ?>
                <p class="setup-tutorial-admin">This prerequisite has no editing screen inside the application. See the setup instructions in Help Center.</p>
            <?php endif; ?>
            <details class="setup-tutorial-steps">
                <summary>All required steps</summary>
                <ol>
                    <?php foreach (($st_summary['steps'] ?? []) as $st_step): ?>
                        <li class="is-<?= st_e($st_step['status']) ?><?= $st_step['key'] === $st_current['key'] ? ' is-current' : '' ?>">
                            <span class="setup-tutorial-step-name"><?= st_e($st_step['title']) ?></span>
                            <span class="setup-tutorial-step-state"><?= $st_step['status'] === 'ready' ? 'Done' : ($st_step['key'] === $st_current['key'] ? 'Current' : 'Pending') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </details>
        </div>
        <div class="setup-tutorial-actions">
            <?php if ($st_destination_url !== ''): ?>
                <a class="setup-tutorial-btn is-primary" id="setupTutorialConfigure" href="<?= st_e($st_destination_url) ?>#setup-step-<?= st_e($st_current['key']) ?>">Configure now</a>
            <?php endif; ?>
            <a class="setup-tutorial-btn" href="help_center.php">Help Center</a>
            <a class="setup-tutorial-btn" href="../process/logout.php">Sign out</a>
        </div>
    </div>
</div>
<?php elseif ($st_mode === 'banner' && $st_current): ?>
<div class="setup-tutorial-banner<?= $st_on_destination ? ' is-here' : '' ?>" id="setupTutorialBanner" role="region" aria-label="Guided setup" tabindex="-1">
    <div class="setup-tutorial-banner-text">
        <span class="setup-tutorial-banner-step">Step <?= (int)$st_current['position'] ?> of <?= (int)$st_summary['total_steps'] ?></span>
        <strong><?= $st_operator_can ? 'Configure this first: ' : 'An administrator must complete: ' ?><?= st_e($st_current['title']) ?>.</strong>
        <span><?= st_e($st_current['message']) ?></span>
        <?php if ($st_on_destination): ?><span class="setup-tutorial-banner-hint">The highlighted form below is the one to complete. Progress advances only after the server confirms the saved configuration.</span><?php endif; ?>
    </div>
    <div class="setup-tutorial-banner-actions">
        <?php if ($st_on_destination): ?>
            <button type="button" class="setup-tutorial-btn is-primary" id="setupTutorialSpotlight">Show me where</button>
        <?php elseif ($st_destination_url !== ''): ?>
            <a class="setup-tutorial-btn is-primary" href="<?= st_e($st_destination_url) ?>#setup-step-<?= st_e($st_current['key']) ?>">Configure now</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<script src="../common/setup-tutorial.js" defer></script>
