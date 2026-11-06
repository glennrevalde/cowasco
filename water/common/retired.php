<?php
/**
 * A page of the former water-billing program that the cooperative system now provides. The file stays so that
 * saved links and the user's habit still land somewhere useful: a short notice and the way to the new page.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once dirname(__DIR__, 2) . '/views/layout.php';

$retiredKey = basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php');
if (!isLoggedIn()) {
    header('Location: ' . water_login_url());
    exit;
}
Auth::requireLogin('water.' . $retiredKey);
[$target, $note] = WaterPages::RETIRED[$retiredKey] ?? ['', 'This page is no longer part of the system.'];
$targetUrl = $target !== '' && (Access::page($target) || !Access::pageDef($target)) ? url($target) : '';
$targetName = $target !== '' ? (Access::pageDef($target)['name'] ?? $target) : '';
if ($targetUrl !== '' && isset($_GET['go'])) {
    redirect($targetUrl);
}
$GLOBALS['CURRENT_PAGE'] = 'water.dashboard';
layout_start('This page has moved', ['module' => 'water']);
?>
<div class="card" style="max-width:720px"><div class="card-body">
  <p class="mb-2"><strong><?= h(ucwords(str_replace('_', ' ', $retiredKey))) ?></strong> is no longer a separate page of the water-billing module.</p>
  <p class="mb-3"><?= h($note) ?></p>
  <?php if ($targetUrl !== ''): ?>
    <a class="btn btn-primary btn-sm" href="<?= h($targetUrl) ?>"><i class="bi bi-arrow-right"></i> Open <?= h($targetName) ?></a>
  <?php elseif ($target !== ''): ?>
    <p class="small text-muted mb-3">The page that replaces it, <?= h($targetName) ?>, is not in your page access. Ask your administrator if you need it.</p>
  <?php endif; ?>
  <a class="btn btn-outline-secondary btn-sm" href="<?= h(url('water.dashboard')) ?>">Water Dashboard</a>
</div></div>
<?php
layout_end();
