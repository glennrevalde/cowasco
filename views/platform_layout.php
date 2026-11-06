<?php
declare(strict_types=1);

/**
 * Page frame of the Super Admin (platform) pages: the same look as the company pages, with the platform's
 * own menu. No company database is open on these pages.
 */

const PLATFORM_MENU = [
    'Companies' => [
        'companies' => ['Companies', 'buildings'],
        'new'       => ['New company', 'plus-square'],
        'updates'   => ['Database updates', 'database-check'],
    ],
    'Platform' => [
        'admins'    => ['Super Admins', 'shield-lock'],
        'settings'  => ['Platform settings', 'sliders'],
        'audit'     => ['Platform audit log', 'journal-text'],
        'account'   => ['My account', 'person-circle'],
    ],
];

function platform_start(string $title, array $opt = []): void
{
    $sa = Platform::admin();
    $current = (string)($GLOBALS['PLATFORM_PAGE'] ?? '');
    $app = app_name();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - Platform - <?= h($app) ?></title>
<link rel="stylesheet" href="<?= h(vendor_asset('bootstrap.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
<script src="<?= h(asset('js/dialog.js')) ?>"></script>
<script src="<?= h(asset('js/datefield.js')) ?>"></script>
<script src="<?= h(asset('js/fmt.js')) ?>"></script>
</head>
<body class="platform">
<nav class="navbar navbar-dark bg-dark app-bar sticky-top px-3 no-print">
  <div class="d-flex align-items-center gap-2">
    <button class="btn btn-sm btn-user d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
    <a class="navbar-brand fw-semibold" href="<?= h(purl()) ?>"><span class="brand-tile"><i class="bi bi-people-fill"></i></span><?= h($app) ?></a>
    <span class="company d-none d-md-inline">Platform administration</span>
  </div>
  <?php if ($sa): ?>
  <div class="dropdown">
    <button class="btn btn-sm btn-user dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-shield-lock"></i> <?= h($sa['full_name']) ?></button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><span class="dropdown-item-text small text-muted">Super Admin</span></li>
      <li><a class="dropdown-item" href="<?= h(purl('account')) ?>"><i class="bi bi-key"></i> Change password</a></li>
      <?php if (Manual::available()): ?>
      <li><a class="dropdown-item" href="<?= h(Manual::url()) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a></li>
      <?php endif; ?>
      <li><hr class="dropdown-divider"></li>
      <li><form method="post" action="<?= h(purl('companies', ['a' => 'logout'])) ?>"><?= csrf_field() ?><button class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Log out</button></form></li>
    </ul>
  </div>
  <?php endif; ?>
</nav>
<div class="d-flex app-shell">
  <aside class="offcanvas-lg offcanvas-start sidebar bg-body-tertiary border-end no-print" id="sidebar" tabindex="-1">
    <div class="offcanvas-header d-lg-none"><h5 class="offcanvas-title">Platform</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button></div>
    <div class="offcanvas-body d-block p-0 pt-2">
      <?php foreach (PLATFORM_MENU as $group => $items): ?>
        <div class="menu-group"><?= h($group) ?></div>
        <?php foreach ($items as $k => [$name, $icon]): ?>
          <a class="menu-link platform-link <?= $k === $current ? 'active' : '' ?>" href="<?= h(purl($k)) ?>"><i class="bi bi-<?= h($icon) ?>"></i><?= h($name) ?></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </aside>
  <main class="flex-grow-1 p-3 p-lg-4 main-area">
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show no-print" role="alert"><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>
    <div class="page-bar">
      <h1><?= h($title) ?></h1>
      <div class="page-actions no-print"><?= $opt['actions'] ?? '' ?><?= $sa ? Manual::helpButton(Manual::forPlatform($current)) : '' ?></div>
    </div>
    <?= form_error() ?>
<?php
}

function platform_end(): void
{
    ?>
  </main>
</div>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/** Company status in words, with a colour cue (plain text, no badge) */
function platform_status(string $status): string
{
    $t = ['active' => 'Active', 'inactive' => 'Inactive', 'provisioning' => 'Being set up', 'failed' => 'Set-up failed'][$status] ?? ucfirst($status);
    return '<span class="co-state co-' . h($status) . '">' . h($t) . '</span>';
}
