<?php
/**
 * navbar.php - the integrated shell around every water-billing page.
 *
 * Renders the same top bar and side menu as the accounting and cooperative pages (views/layout.php): one menu
 * built from the one registry and the user's effective page access (build_menu()), the company name, search,
 * notifications and the user menu. The page that includes it then opens <div class="main-content">.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/systemDataHelpers.php';
require_once dirname(__DIR__, 2) . '/views/layout.php';

if (!isLoggedIn()) {
    header('Location: ' . water_login_url());
    exit;
}

if (!function_exists('nav_e')) {
    function nav_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$nav_user      = Auth::user();
$nav_full_name = nav_e($_SESSION['full_name'] ?? $nav_user['username'] ?? 'User');
$nav_username  = nav_e($_SESSION['username'] ?? '');
$nav_role      = nav_e(Tenant::isOwner() ? 'Company Owner' : (implode(', ', Access::roleNames()) ?: 'No role'));
$nav_words     = array_filter(explode(' ', (string)($_SESSION['full_name'] ?? 'U')));
$nav_initials  = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice($nav_words, 0, 2)))) ?: 'U';

$current_page = basename((string)$_SERVER['PHP_SELF'], '.php');
$nav_is_soa_report = $current_page === 'generate_soa'
    && (strtolower(trim((string)($_GET['view'] ?? ''))) === 'soa' || strtolower(trim((string)($_GET['tab'] ?? ''))) === 'print');
$nav_is_official_receipt_report = $current_page === 'generate_official_receipt';
$nav_active_page = ($nav_is_soa_report || $nav_is_official_receipt_report) ? 'reports_management' : $current_page;
$nav_breadcrumb_label = $nav_is_soa_report ? 'Generate Statement of Account'
    : ($nav_is_official_receipt_report ? 'Generate Official Receipt' : (WaterPages::PAGES[$current_page][0] ?? ucfirst(str_replace('_', ' ', $current_page))));

$nav_access_pdo = getDB();
requirePageAccess($nav_access_pdo, $nav_is_soa_report ? 'reports_management' : $current_page);
$GLOBALS['CURRENT_PAGE'] = 'water.' . $nav_active_page;
$nav_can_search = userCanAccessPage($nav_access_pdo, 'search');
$nav_can_notifications = userCanAccessPage($nav_access_pdo, 'notifications');
$nav_can_help_center = userCanAccessPage($nav_access_pdo, 'help_center');
$nav_unread_notifications = $nav_can_notifications ? sys_unread_notification_count($nav_access_pdo) : 0;
$nav_visibility_payload = access_visibility_payload($nav_access_pdo);
$nav_menu = build_menu();
$nav_company = Settings::get('company_name');
$nav_app = app_name();
$nav_base = base_url();
// messages for this page: a water message no page section has shown yet (flash(), any of the shapes the water code
// stores) and the accounting messages queued with flash($type, $msg) on the way here (take_flash())
$nav_messages = [];
$nav_water_flash = flash();
if (is_array($nav_water_flash) && $nav_water_flash) {
    if (isset($nav_water_flash['msg'])) {
        $nav_messages[] = [(string)($nav_water_flash['type'] ?? 'info'), (string)$nav_water_flash['msg']];
    } elseif (isset($nav_water_flash['message'])) {
        $nav_messages[] = [(string)($nav_water_flash['type'] ?? 'info'), (string)$nav_water_flash['message']];
    } else {
        foreach (['success', 'error', 'warning', 'info'] as $nav_t) {
            if (!empty($nav_water_flash[$nav_t]) && is_string($nav_water_flash[$nav_t])) {
                $nav_messages[] = [$nav_t, $nav_water_flash[$nav_t]];
            }
        }
    }
}
foreach (take_flash() as [$nav_t, $nav_m]) {
    $nav_messages[] = [$nav_t === 'danger' ? 'error' : (string)$nav_t, (string)$nav_m];
}
?>
<link rel="stylesheet" href="<?= nav_e(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= nav_e($nav_base) ?>/water/common/shell.css?v=<?= (int)@filemtime(__DIR__ . '/shell.css') ?>">
<link rel="stylesheet" href="<?= nav_e($nav_base) ?>/water/common/theme.css?v=<?= (int)@filemtime(__DIR__ . '/theme.css') ?>">
<script src="<?= nav_e(asset('js/dialog.js')) ?>"></script>
<script src="<?= nav_e(asset('js/datefield.js')) ?>"></script>
<script src="<?= nav_e($nav_base) ?>/water/common/theme.js?v=<?= (int)@filemtime(__DIR__ . '/theme.js') ?>" defer></script>
<script>document.body && document.body.classList.add('wsh-body');</script>

<header class="wsh-bar no-print" id="wshBar">
  <div class="wsh-left">
    <button type="button" class="wsh-burger" id="wshBurger" aria-label="Menu"><i class="bi bi-list"></i></button>
    <a class="wsh-brand" href="<?= nav_e(url('home.dashboard')) ?>"><span class="wsh-tile"><i class="bi bi-calculator"></i></span><span class="wsh-brand-text"><?= nav_e($nav_app) ?></span></a>
    <span class="wsh-company"><?= nav_e($nav_company) ?></span>
  </div>
  <div class="wsh-right">
    <?php if ($nav_can_search): ?>
    <form class="wsh-search" method="get" action="search.php"><i class="bi bi-search"></i><input type="search" name="q" placeholder="Search water accounts, bills and receipts" aria-label="Search"></form>
    <?php endif; ?>
    <?php if ($nav_can_help_center): ?><a class="wsh-iconbtn" href="help_center.php" title="Help Center" aria-label="Help Center"><i class="bi bi-question-circle"></i></a><?php endif; ?>
    <?php if ($nav_can_notifications): ?><a class="wsh-iconbtn" href="notifications.php" title="Notifications" aria-label="Notifications"><i class="bi bi-bell<?= $nav_unread_notifications > 0 ? '-fill' : '' ?>"></i><?php if ($nav_unread_notifications > 0): ?><span class="wsh-dot"></span><?php endif; ?></a><?php endif; ?>
    <div class="wsh-user">
      <button type="button" class="wsh-iconbtn wsh-user-btn" id="wshUserBtn" aria-haspopup="true" aria-expanded="false"><i class="bi bi-person-circle"></i><span class="wsh-user-name"><?= $nav_full_name ?></span></button>
      <div class="wsh-menu" id="wshUserMenu">
        <span class="wsh-menu-who"><?= $nav_full_name ?></span>
        <span class="wsh-menu-note"><?= $nav_role ?></span>
        <a href="<?= nav_e(url('auth.change_password')) ?>"><i class="bi bi-key"></i> Change password</a>
        <?php if (Manual::available()): ?><a href="<?= nav_e(Manual::url()) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a><?php endif; ?>
        <a href="user_manual.php"><i class="bi bi-droplet"></i> Water billing manual</a>
        <hr>
        <a href="<?= nav_e(url('auth.logout')) ?>"><i class="bi bi-box-arrow-right"></i> Log out</a>
      </div>
    </div>
  </div>
</header>

<aside class="wsh-sidebar no-print" id="wshSidebar" aria-label="Menu">
  <div class="wsh-sidebar-head"><span>Menu</span><button type="button" id="wshClose" aria-label="Close menu">&times;</button></div>
  <?php foreach ($nav_menu as $nav_code => $nav_mod): $nav_open = $nav_code === 'water'; ?>
  <div class="wsh-mod<?= $nav_open ? ' open' : '' ?>" data-module="<?= nav_e($nav_code) ?>">
    <button type="button" class="wsh-mod-btn"><span class="wsh-ico"><i class="bi bi-<?= nav_e($nav_mod['icon']) ?>"></i></span><?= nav_e($nav_mod['name']) ?></button>
    <div class="wsh-mod-body">
    <?php foreach ($nav_mod['groups'] as $nav_gname => $nav_items): ?>
      <?php if ($nav_gname === 'Reports'): ?>
        <details class="wsh-reports"<?= ($nav_open && $nav_active_page === 'reports_management') ? ' open' : '' ?>>
          <summary>Reports (<?= count($nav_items) ?>)</summary>
          <?php $nav_last_sub = null; foreach ($nav_items as $nav_it): ?>
            <?php if ($nav_it['sub'] !== 'Reports' && $nav_it['sub'] !== $nav_last_sub): $nav_last_sub = $nav_it['sub']; ?><div class="wsh-sub"><?= nav_e($nav_it['sub']) ?></div><?php endif; ?>
            <a class="wsh-link" href="<?= nav_e(url($nav_it['key'])) ?>"><?= nav_e($nav_it['name']) ?></a>
          <?php endforeach; ?>
        </details>
      <?php else: ?>
        <?php if (count($nav_mod['groups']) > 1 && $nav_gname !== 'Home'): ?><div class="wsh-group"><?= nav_e($nav_gname) ?></div><?php endif; ?>
        <?php foreach ($nav_items as $nav_it): $nav_is_active = $nav_it['key'] === 'water.' . $nav_active_page; ?>
          <a class="wsh-link<?= $nav_is_active ? ' active' : '' ?>" href="<?= nav_e(url($nav_it['key'])) ?>"<?= $nav_is_active ? ' aria-current="page"' : '' ?>><?= nav_e($nav_it['name']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</aside>
<div class="wsh-overlay" id="wshOverlay"></div>
<?php if ($nav_messages): ?>
<div class="wsh-toasts no-print" role="status" aria-live="polite">
  <?php foreach ($nav_messages as [$nav_t, $nav_m]): ?>
  <div class="wsh-flash <?= nav_e(in_array($nav_t, ['success', 'error', 'danger', 'warning', 'info'], true) ? $nav_t : 'info') ?>"><span><?= nav_e($nav_m) ?></span><button type="button" class="wsh-flash-x" aria-label="Dismiss">&times;</button></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script>
(function () {
    var sidebar = document.getElementById('wshSidebar'), overlay = document.getElementById('wshOverlay');
    function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('open'); }
    function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); }
    window.openSidebar = openSidebar; window.closeSidebar = closeSidebar;
    document.getElementById('wshBurger').addEventListener('click', openSidebar);
    document.getElementById('wshClose').addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('.wsh-mod-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mod = btn.parentElement, wasOpen = mod.classList.contains('open');
            sidebar.querySelectorAll('.wsh-mod.open').forEach(function (m) { m.classList.remove('open'); });
            if (!wasOpen) mod.classList.add('open');
        });
    });
    var active = sidebar.querySelector('.wsh-link.active');
    if (active) { try { active.scrollIntoView({ block: 'center' }); } catch (e) {} }
    var userBtn = document.getElementById('wshUserBtn'), userMenu = document.getElementById('wshUserMenu');
    userBtn.addEventListener('click', function (e) { e.stopPropagation(); var o = userMenu.classList.toggle('open'); userBtn.setAttribute('aria-expanded', o ? 'true' : 'false'); });
    document.addEventListener('click', function (e) { if (!userMenu.contains(e.target)) { userMenu.classList.remove('open'); userBtn.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeSidebar(); userMenu.classList.remove('open'); } });
    document.querySelectorAll('.wsh-flash').forEach(function (flash) {
        function hide() { flash.style.transition = 'opacity .35s, transform .35s'; flash.style.opacity = '0'; flash.style.transform = 'translateY(-6px)'; setTimeout(function () { flash.remove(); }, 380); }
        var x = flash.querySelector('.wsh-flash-x'); if (x) x.addEventListener('click', hide);
        if (!flash.classList.contains('error') && !flash.classList.contains('danger')) setTimeout(hide, 7000);
    });
})();

/**
 * Presentation-only permission filter: links to water pages the user may not open are hidden.
 * requirePageAccess()/requireProcessAccess() on the server remain the security boundary.
 */
window.WSH_ACCESS = <?= json_encode($nav_visibility_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
<?= file_get_contents(__DIR__ . '/nav_visibility.js') ?>
</script>
<?php require_once __DIR__ . '/navbar_components.php'; ?>
