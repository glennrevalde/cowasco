<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, basename(__FILE__, '.php'));
/* AUTH_GUARD_END */

/**
 * Water Billing > Service & Materials > Address Sub-groups (version 46).
 *
 * The address sub-groups (purok, sitio, zone) the member form and the water-service accounts choose from: add one,
 * rename it (the new name is carried to every member and account that has it), set its order, switch it off (no longer
 * offered, kept on the records that have it) or delete it when nothing uses it. The same list is on Members & Others >
 * Member Types, Clusters & Other Lists > Address sub-groups, and opens from the Manage button beside every Address
 * sub-group box. The work is done by water/process/addressSubgroups.php (assets/js/subgroup_manager.js).
 */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$ready = Members::waterSubgroupReady();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Address Sub-groups - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root { --white: #fff; --off-white: #f8f9fb; --border: #e8eaed; --text-primary: #0f0f1a; --text-secondary: #6b7280; --accent: #1a1a2e; --radius: 7px; }
        body { margin: 0; min-height: 100vh; background: var(--off-white); color: var(--text-primary); font-family: 'Sora', sans-serif; }
        .page { max-width: 1080px; margin: 0 auto; padding: 28px 28px 48px; }
        .page-header { margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 7px; color: var(--text-secondary); font-size: .8rem; font-weight: 600; text-decoration: none; }
        .back-link:hover { color: var(--text-primary); }
        .page-title { margin: 0; font-size: 1.42rem; font-weight: 700; letter-spacing: -.02em; }
        .page-subtitle { max-width: 820px; margin: 5px 0 0; color: var(--text-secondary); font-size: .86rem; line-height: 1.55; }
        .notice { margin-bottom: 16px; padding: 12px 14px; border: 1px solid #fde68a; border-radius: var(--radius); background: #fffbeb; color: #92400e; font-size: .84rem; line-height: 1.5; }
        .links { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 14px; font-size: .8rem; }
        .links a { color: #1d4ed8; font-weight: 600; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
        @media (max-width: 640px) { .page { padding: 18px 14px 36px; } .page-title { font-size: 1.22rem; } }
    </style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
    <main class="page">
        <header class="page-header">
            <a href="member_list.php" class="back-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Water Service Accounts
            </a>
            <h1 class="page-title">Address Sub-groups</h1>
            <p class="page-subtitle">The purok, sitio or zone a member and a water-service account belong to. Add, rename, set the order, switch off or delete them here; the member form and the water-service accounts offer the active ones.</p>
        </header>
        <?php if (!$ready): ?>
            <div class="notice">The water-service accounts have no address sub-group yet: run the upgrade scripts first. The list can still be kept for the members.</div>
        <?php endif; ?>
        <section data-subgroup-manager data-endpoint="../process/addressSubgroups.php" data-page-key="water.address_subgroups" data-csrf="<?= e(csrfToken()) ?>" aria-label="Address sub-groups"></section>
        <div class="links">
            <a href="member_list.php">Water Service Accounts</a>
            <?php if (Access::page('coop.lists')): ?><a href="<?= e(url('coop.lists', ['tab' => 'address_subgroup'])) ?>">Member Types, Clusters &amp; Other Lists</a><?php endif; ?>
        </div>
    </main>
</div>
<script src="<?= e(asset('js/subgroup_manager.js')) ?>"></script>
</body>
</html>
