<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, basename(__FILE__, '.php'));
/* AUTH_GUARD_END */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$businessProfile = institution_business_profile($pdo);
$positions = institution_configuration_positions();
$defaults = institution_configuration_default_names();
$officers = institution_current_officers($pdo, (int)$businessProfile['pkey']);
$tableReady = institution_table_exists($pdo, 'companyofficers');
$successMessage = (string)($_SESSION['success_msg'] ?? '');
$errorMessage = (string)($_SESSION['error_msg'] ?? '');
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Institution Configuration - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white: #fff; --off-white: #f8f9fb; --border: #e8eaed;
            --text-primary: #0f0f1a; --text-secondary: #6b7280;
            --accent: #1a1a2e; --success: #047857; --success-bg: #ecfdf5;
            --danger: #b91c1c; --danger-bg: #fef2f2; --radius: 7px;
        }
        body { min-height: 100vh; background: var(--off-white); color: var(--text-primary); font-family: 'Sora', sans-serif; }
        .page { max-width: 1080px; margin: 0 auto; padding: 28px 28px 48px; }
        .page-header { margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 7px; color: var(--text-secondary); font-size: .8rem; font-weight: 600; text-decoration: none; }
        .back-link:hover { color: var(--text-primary); }
        .page-title { font-size: 1.42rem; font-weight: 700; letter-spacing: -.02em; }
        .page-subtitle { max-width: 820px; margin-top: 5px; color: var(--text-secondary); font-size: .86rem; line-height: 1.55; }
        .alert { margin-bottom: 16px; padding: 12px 14px; border: 1px solid; border-radius: var(--radius); font-size: .82rem; font-weight: 600; line-height: 1.5; }
        .alert.success { border-color: #a7f3d0; background: var(--success-bg); color: var(--success); }
        .alert.error { border-color: #fecaca; background: var(--danger-bg); color: var(--danger); }
        .card { overflow: hidden; border: 1px solid var(--border); border-radius: var(--radius); background: var(--white); }
        .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 18px 20px; border-bottom: 1px solid var(--border); background: #fafafa; }
        .card-title { font-size: .96rem; font-weight: 700; }
        .card-copy { margin-top: 4px; color: var(--text-secondary); font-size: .78rem; line-height: 1.5; }
        .profile-badge { flex: 0 0 auto; max-width: 330px; padding: 6px 9px; border: 1px solid var(--border); border-radius: 999px; color: var(--text-secondary); font: 600 .68rem 'JetBrains Mono', monospace; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 11px 14px; border-bottom: 1px solid var(--border); background: #f8fafc; color: #4b5563; font-size: .7rem; font-weight: 700; letter-spacing: .04em; text-align: left; text-transform: uppercase; }
        td { padding: 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: 0; }
        .position-name { font-size: .86rem; font-weight: 700; }
        .position-note { margin-top: 3px; color: var(--text-secondary); font-size: .72rem; line-height: 1.4; }
        .name-input { width: 100%; min-width: 260px; padding: 10px 11px; border: 1.5px solid #d7dae0; border-radius: 5px; color: var(--text-primary); font: 500 .84rem 'Sora', sans-serif; outline: none; }
        .name-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(26,26,46,.08); }
        .record-meta { min-width: 150px; color: var(--text-secondary); font-size: .7rem; line-height: 1.5; }
        .card-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 20px; border-top: 1px solid var(--border); background: #fafafa; }
        .foot-note { max-width: 650px; color: var(--text-secondary); font-size: .72rem; line-height: 1.5; }
        .actions { display: flex; gap: 8px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 9px 14px; border: 0; border-radius: 5px; font: 600 .76rem 'Sora', sans-serif; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--accent); color: var(--white); }
        .btn-secondary { border: 1px solid var(--border); background: var(--white); color: var(--text-primary); }
        .btn:disabled { cursor: not-allowed; opacity: .55; }
        @media (max-width: 760px) {
            .page { padding: 22px 16px 40px; }
            .card-head, .card-foot { align-items: stretch; flex-direction: column; }
            .profile-badge { max-width: 100%; }
            .actions { justify-content: flex-end; }
        }
    </style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
    <main class="page">
        <header class="page-header">
            <a href="settings.php" class="back-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Settings
            </a>
            <h1 class="page-title">Institution Configuration</h1>
            <p class="page-subtitle">Maintain the current institution officers used as report signatories. Officer changes are stored as new active records so previous names remain available in the database history.</p>
        </header>

        <?php if ($successMessage !== ''): ?><div class="alert success"><?= e($successMessage) ?></div><?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="alert error"><?= e($errorMessage) ?></div><?php endif; ?>
        <?php if (!$tableReady): ?><div class="alert error">The <code>companyofficers</code> table is missing from the current database. Import the supplied Water Billing System schema before saving this configuration.</div><?php endif; ?>

        <form method="post" action="../process/saveInstitutionConfiguration.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="card">
                <div class="card-head">
                    <div>
                        <div class="card-title">Institution Officers</div>
                        <div class="card-copy">Type the complete name that should appear below each position on generated reports.</div>
                    </div>
                    <div class="profile-badge" title="<?= e($businessProfile['name']) ?>"><?= e($businessProfile['name']) ?></div>
                </div>

                <div class="table-wrap">
                    <table aria-label="Institution officer configuration">
                        <thead>
                            <tr>
                                <th style="width:28%">Position</th>
                                <th>Officer Name</th>
                                <th style="width:20%">Current Record</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($positions as $positionKey => $positionLabel):
                            $officer = $officers[$positionKey] ?? [];
                            $hasSavedRecord = !empty($officer['found']);
                            $displayValue = $hasSavedRecord
                                ? (string)($officer['name'] ?? '')
                                : (string)($defaults[$positionKey] ?? '');
                            $updatedAt = (string)($officer['updated_at'] ?? $officer['created_at'] ?? '');
                            $updatedBy = (string)($officer['updated_by'] ?? '');
                        ?>
                            <tr>
                                <td>
                                    <div class="position-name"><?= e($positionLabel) ?></div>
                                    <div class="position-note"><?= $positionKey === 'general_manager' ? 'Used on Statement of Account printouts.' : 'Available for other institution reports and approvals.' ?></div>
                                </td>
                                <td>
                                    <input
                                        class="name-input"
                                        type="text"
                                        name="officers[<?= e($positionKey) ?>]"
                                        value="<?= e($displayValue) ?>"
                                        maxlength="100"
                                        placeholder="Enter the complete officer name"
                                        aria-label="<?= e($positionLabel) ?> name">
                                </td>
                                <td class="record-meta">
                                    <?php if ($hasSavedRecord): ?>
                                        Saved<?= $updatedAt !== '' ? '<br>' . e(date('M d, Y h:i A', strtotime($updatedAt))) : '' ?><?= $updatedBy !== '' ? '<br>by ' . e($updatedBy) : '' ?>
                                    <?php else: ?>
                                        Default value<br>Not yet saved
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-foot">
                    <p class="foot-note">Saving a changed name deactivates the previous active officer record and creates a new current record. This keeps old officer names in the database instead of overwriting them permanently.</p>
                    <div class="actions">
                        <a href="settings.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" <?= !$tableReady ? 'disabled' : '' ?>>Save Institution Configuration</button>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
</body>
</html>
