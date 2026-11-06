<?php
declare(strict_types=1);

/** Runs a POST handler; user-facing errors are shown on the re-rendered form. */
function handle_post(callable $fn): void
{
    if (!is_post()) {
        return;
    }
    // "Memorize" on a form: the transaction is put on the Memorized Transactions list instead of being saved
    Memorized::$capture = p('memorize') === '1';
    try {
        $fn();
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        Approval::release();
        $GLOBALS['FORM_ERROR'] = $e->getMessage();
        $GLOBALS['FORM_STOCK_QUESTION'] = $e instanceof StockQuestion ? $e->short : null;
        $GLOBALS['FORM_NOTICE'] = $e instanceof FormNotice;
        if ($e instanceof StockQuestion) {
            Approval::keepForAnswer();
        }
    } finally {
        Memorized::$capture = false;
    }
}

function form_error(): string
{
    $e = $GLOBALS['FORM_ERROR'] ?? null;
    if (!$e) {
        return '';
    }
    $short = $GLOBALS['FORM_STOCK_QUESTION'] ?? null;
    if ($short) {
        // the question asked by app.js (Yes saves, No goes back to the form); the same words stay on the page
        $rows = array_map(fn($s) => ['code' => $s[0], 'site' => $s[1], 'before' => qty($s[2]), 'after' => qty($s[3])], $short);
        $li = implode('', array_map(fn($r) => '<li>' . h($r['code']) . ' at ' . h($r['site']) . ': ' . h($r['before']) . ' on hand, would be left with <span class="neg">' . h($r['after']) . '</span></li>', $rows));
        return '<div class="alert alert-warning d-flex gap-2 neg-stock-alert" data-negative-stock="' . h(json_encode($rows, JSON_UNESCAPED_UNICODE)) . '">'
            . '<i class="bi bi-exclamation-triangle-fill"></i><div><strong>Saving the transaction will lead the inventory to a negative balance.</strong> Nothing has been saved yet.'
            . '<ul class="mb-1 mt-1 small">' . $li . '</ul><span class="small">Save again and answer Yes to continue, or change the quantities.</span></div></div>';
    }
    if (!empty($GLOBALS['FORM_NOTICE'])) {
        return '<div class="alert alert-success d-flex gap-2 form-notice"><i class="bi bi-bookmark-check-fill"></i><div>' . h($e) . '</div></div>';
    }
    return '<div class="alert alert-danger d-flex gap-2"><i class="bi bi-exclamation-triangle-fill"></i><div>' . h($e) . '</div></div>';
}

/** Value for a form field: posted value first, then the record's value. */
function fv(string $key, $record = null, $default = '')
{
    if (is_post() && array_key_exists($key, $_POST)) {
        return is_string($_POST[$key]) ? $_POST[$key] : $_POST[$key];
    }
    if (is_array($record) && array_key_exists($key, $record)) {
        return $record[$key] ?? $default;
    }
    return $default;
}

function build_menu(): array
{
    $defs = Access::allPageDefs();
    $eff = Access::effective();
    $menu = [];
    foreach (Access::modules() as $code => [$name, $icon, $sort]) {
        $groups = [];
        $keys = array_keys(array_filter($defs, fn($d) => $d['module'] === $code));
        $keys = array_merge($keys, Access::menuAliases()[$code] ?? []);
        foreach ($keys as $k) {
            $d = $defs[$k] ?? null;
            if (!$d || !$d['menu'] || empty($eff[$k]['allowed'])) {
                continue;
            }
            // the one-time set-up page leaves the menu once several companies are running
            if ($k === 'admin.multi_company' && Tenant::isMulti()) {
                continue;
            }
            if ($k === 'company.join_requests' && Schema::ready() && ($waiting = (int)DB::value("SELECT COUNT(*) FROM users WHERE approval_status = 'pending' AND is_platform = 0"))) {
                $d['name'] .= ' (' . $waiting . ')';
            }
            $group = $d['type'] === 'report' ? 'Reports' : $d['group'];
            if (in_array($k, Access::menuAliases()[$code] ?? [], true)) {
                $group = 'Transactions';
            }
            $groups[$group][] = ['key' => $k, 'name' => $d['name'], 'sub' => $d['group']];
        }
        if ($groups) {
            $menu[$code] = ['name' => $name, 'icon' => $icon, 'groups' => $groups];
        }
    }
    return $menu;
}

function layout_start(string $title, array $opt = []): void
{
    $GLOBALS['LAYOUT_TITLE'] = $title;
    $GLOBALS['LAYOUT_BARE'] = !empty($opt['bare']);
    // extra scripts this page needs, loaded after app.js by layout_end(): ['js' => ['js/btprint.js']]
    $GLOBALS['LAYOUT_JS'] = array_values(array_filter((array)($opt['js'] ?? [])));
    $user = Auth::user();
    $current = $GLOBALS['CURRENT_PAGE'] ?? '';
    $currentModule = Access::pageDef($current)['module'] ?? '';
    if (isset($opt['module'])) {
        $currentModule = $opt['module'];
    }
    $company = Settings::get('company_name');
    $app = app_name();
    $menu = $user ? build_menu() : [];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - <?= h($app) ?></title>
<link rel="stylesheet" href="<?= h(vendor_asset('bootstrap.css')) ?>">
<link rel="stylesheet" href="<?= h(vendor_asset('icons.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
<script src="<?= h(asset('js/dialog.js')) ?>"></script>
<script src="<?= h(asset('js/datefield.js')) ?>"></script>
<script src="<?= h(asset('js/fmt.js')) ?>"></script>
<script src="<?= h(asset('js/wht.js')) ?>"></script>
</head>
<body class="<?= h(trim((!empty($opt['bare']) ? 'bare ' : '') . (string)($opt['body_class'] ?? ''))) ?>">
<?php if (empty($opt['bare'])): ?>
<nav class="navbar navbar-dark bg-dark app-bar sticky-top px-3 no-print">
  <div class="d-flex align-items-center gap-2">
    <button class="btn btn-sm btn-user d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
    <?php [$brandA, $brandB] = array_pad(explode(' ', $app, 2), 2, ''); ?>
    <a class="navbar-brand fw-semibold" href="<?= h(url('home.dashboard')) ?>" title="<?= h($app) ?>"><span class="brand-tile"><i class="bi bi-people-fill"></i></span><span class="brand-text"><span class="brand-a"><?= h($brandA) ?></span><?php if ($brandB !== ''): ?><span class="brand-b"> <?= h($brandB) ?></span><?php endif; ?></span></a>
    <?php $switchTo = $user ? CompanyAccess::switchable() : []; ?>
    <?php if (count($switchTo) > 1): ?>
    <div class="dropdown company-switch">
      <button class="btn btn-sm company-switch-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" title="Switch company" aria-label="Switch company (now: <?= h($company) ?>)">
        <i class="bi bi-buildings"></i><span class="company-switch-name"><?= h($company) ?></span></button>
      <div class="dropdown-menu company-switch-menu">
        <div class="company-switch-head">Your companies</div>
        <?php [$homeCo] = CompanyAccess::home(); ?>
        <?php foreach ($switchTo as $cid => $cname): $isNow = (int)$cid === (int)Tenant::id(); ?>
        <form method="post" action="<?= h(url('auth.switch_company')) ?>"><?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)$cid ?>">
          <button class="dropdown-item company-switch-item<?= $isNow ? ' is-now' : '' ?>" <?= $isNow ? 'disabled aria-current="true"' : '' ?>>
            <i class="bi <?= $isNow ? 'bi-check2' : 'bi-building' ?>"></i><span><?= h($cname) ?><small><?= (int)$cid === (int)$homeCo ? 'Your own company' : 'Access given to you' ?><?= $isNow ? ', open now' : '' ?></small></span></button></form>
        <?php endforeach; ?>
      </div>
    </div>
    <?php else: ?>
    <span class="company d-none d-md-inline"><?= h($company) ?></span>
    <?php endif; ?>
  </div>
  <?php if ($user): ?>
  <div class="d-flex align-items-center gap-2">
    <?php if (Access::page('home.search')): ?>
    <form class="d-none d-md-flex app-search" method="get" action="<?= h(base_url() . '/index.php') ?>">
      <input type="hidden" name="p" value="home.search">
      <i class="bi bi-search"></i>
      <input class="form-control form-control-sm" type="search" name="q" placeholder="Search customers, vendors, items, documents">
    </form>
    <?php endif; ?>
    <?php if (Access::page('home.find')): ?>
    <a class="bell-btn find-btn" href="<?= h(url('home.find')) ?>" title="Find transactions: any module, any combination of filters" aria-label="Find"><i class="bi bi-funnel"></i><span class="find-btn-text">Find</span></a>
    <?php endif; ?>
    <?php
      // cooperative alerts (loans past due, maturities, approvals waiting, regulatory deadlines): counted every few minutes
      $coopN = Schema::ready() && Coop::ready() && (Access::page('coop.members') || Access::page('loan.accounts') || Access::page('sav.accounts') || Access::page('teller.or')) ? CoopAlerts::count() : null;
      if ($coopN !== null): ?>
      <a class="bell-btn <?= $coopN ? '' : 'is-quiet' ?>" href="<?= h(url('home.dashboard')) ?>#coop" title="<?= $coopN ? $coopN . ' cooperative item(s) need attention: open the dashboard' : 'Nothing in the cooperative needs attention' ?>" aria-label="Cooperative alerts">
        <i class="bi bi-exclamation-diamond<?= $coopN ? '-fill' : '' ?>"></i><?php if ($coopN): ?><span class="bell-count"><?= $coopN > 99 ? '99+' : $coopN ?></span><?php endif; ?></a>
    <?php endif; ?>
    <?php
      // preventive maintenance reminders: they stay here until the tasks are acknowledged as complete
      $pmDue = Schema::ready() && AssetMaint::remindsUser() ? AssetMaint::reminders() : null;
      $GLOBALS['PM_REMINDERS'] = $pmDue;
      if ($pmDue !== null): ?>
      <a class="bell-btn <?= $pmDue ? '' : 'is-quiet' ?>" href="<?= h(url('fa.pm', ['tab' => 'due'])) ?>" title="<?= $pmDue ? count($pmDue) . ' maintenance task(s) due: open Preventive Maintenance' : 'No maintenance task is due' ?>">
        <i class="bi bi-bell<?= $pmDue ? '-fill' : '' ?>"></i><?php if ($pmDue): ?><span class="bell-count"><?= count($pmDue) > 99 ? '99+' : count($pmDue) ?></span><?php endif; ?></a>
    <?php endif; ?>
    <div class="dropdown">
      <button class="btn btn-sm btn-user dropdown-toggle" data-bs-toggle="dropdown" aria-label="<?= h($user['full_name']) ?>"><i class="bi bi-person-circle"></i><span class="user-name"> <?= h($user['full_name']) ?></span></button>
      <ul class="dropdown-menu dropdown-menu-end">
        <?php if (Tenant::platformSession()): ?>
        <li><span class="dropdown-item-text small text-muted">Super Admin, managing this company</span></li>
        <li><a class="dropdown-item" href="<?= h(purl('companies', ['a' => 'leave'])) ?>"><i class="bi bi-box-arrow-left"></i> Back to the platform</a></li>
        <?php else: ?>
        <li><span class="dropdown-item-text small text-muted"><?= h(Tenant::isOwner() ? 'Company Owner' . (Access::roleNames() ? ', ' . implode(', ', Access::roleNames()) : '') : (implode(', ', Access::roleNames()) ?: 'No role')) ?></span></li>
        <?php if (CompanyAccess::away()): ?>
        <li><span class="dropdown-item-text small text-muted">Your own company: <?= h(CompanyAccess::homeName()) ?>. Change your password there.</span></li>
        <?php else: ?>
        <li><a class="dropdown-item" href="<?= h(url('auth.change_password')) ?>"><i class="bi bi-key"></i> Change password</a></li>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (Manual::available()): ?>
        <li><a class="dropdown-item" href="<?= h(Manual::url()) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> User's Manual</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= h(url('auth.logout')) ?>"><i class="bi bi-box-arrow-right"></i> Log out</a></li>
      </ul>
    </div>
  </div>
  <?php endif; ?>
</nav>
<?php if ($user && CompanyAccess::away()): ?>
<div class="company-strip no-print"><i class="bi bi-buildings"></i><span>You are working in <strong><?= h($company) ?></strong> with access given to you. Your own company is <?= h(CompanyAccess::homeName()) ?>.</span>
  <form method="post" action="<?= h(url('auth.switch_company')) ?>"><?= csrf_field() ?><input type="hidden" name="company_id" value="<?= (int)CompanyAccess::home()[0] ?>"><button class="btn btn-link btn-sm p-0">Back to <?= h(CompanyAccess::homeName()) ?></button></form></div>
<?php endif; ?>
<?php if (Tenant::platformSession()): ?>
<div class="platform-strip no-print"><i class="bi bi-shield-lock"></i><span>Super Admin: you are working inside <strong><?= h(Tenant::name()) ?></strong><?= (Tenant::company()['status'] ?? '') === 'inactive' ? ' (inactive: its users cannot log in)' : '' ?>. Everything you do here is recorded in its audit trail.</span><a href="<?= h(purl('companies', ['a' => 'leave'])) ?>">Back to the platform</a></div>
<?php endif; ?>
<div class="d-flex app-shell">
  <aside class="offcanvas-lg offcanvas-start sidebar bg-body-tertiary border-end no-print" id="sidebar" tabindex="-1">
    <div class="offcanvas-header d-lg-none"><h5 class="offcanvas-title">Menu</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button></div>
    <div class="offcanvas-body d-block p-0">
    <div class="accordion accordion-flush" id="menuAcc">
    <?php foreach ($menu as $code => $m): $open = $code === $currentModule; ?>
      <div class="accordion-item bg-transparent">
        <h2 class="accordion-header">
          <button class="accordion-button py-2 <?= $open ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#m-<?= h($code) ?>">
            <span class="menu-ico"><i class="bi bi-<?= h($m['icon']) ?>"></i></span><?= h($m['name']) ?>
          </button>
        </h2>
        <div id="m-<?= h($code) ?>" class="accordion-collapse collapse <?= $open ? 'show' : '' ?>" data-bs-parent="#menuAcc">
          <div class="accordion-body p-0 pb-2">
          <?php foreach ($m['groups'] as $gname => $items): ?>
            <?php if ($gname === 'Reports'): ?>
              <details class="menu-reports" <?= ($open && str_starts_with($current, 'rpt.')) ? 'open' : '' ?>>
                <summary class="menu-group">Reports (<?= count($items) ?>)</summary>
                <?php $lastSub = null; foreach ($items as $it): ?>
                  <?php if ($it['sub'] !== 'Reports' && $it['sub'] !== $lastSub): $lastSub = $it['sub']; ?><div class="menu-sub"><?= h($it['sub']) ?></div><?php endif; ?>
                  <a class="menu-link <?= $it['key'] === $current ? 'active' : '' ?>" href="<?= h(url($it['key'])) ?>"><?= h($it['name']) ?></a>
                <?php endforeach; ?>
              </details>
            <?php else: ?>
              <?php if (count($m['groups']) > 1 && $gname !== 'Home'): ?><div class="menu-group"><?= h($gname) ?></div><?php endif; ?>
              <?php foreach ($items as $it): ?>
                <a class="menu-link <?= $it['key'] === $current ? 'active' : '' ?>" href="<?= h(url($it['key'])) ?>"><?= h($it['name']) ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    </div>
  </aside>
  <main class="flex-grow-1 p-3 p-lg-4 main-area">
<?php else: ?>
  <main class="p-3">
<?php endif; ?>
    <?php foreach (take_flash() as [$type, $msg]): ?>
      <div class="alert alert-<?= h($type) ?> alert-dismissible fade show no-print" role="alert"><?= h($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endforeach; ?>
    <?php if (empty($opt['no_title'])): ?>
    <div class="page-bar">
      <h1><?= h($title) ?></h1>
      <div class="page-actions no-print"><?= $opt['actions'] ?? '' ?><?= $user ? Manual::helpButton(Manual::forPage($current, is_string($_GET['a'] ?? null) ? $_GET['a'] : '')) : '' ?></div>
    </div>
    <?php endif; ?>
    <?= form_error() ?>
<?php
}

function layout_end(): void
{
    ?>
  </main>
<?php if (empty($GLOBALS['LAYOUT_BARE'])): ?>
</div>
<?php endif; ?>
<?php
    // Pop-up with the maintenance tasks that are due. Shown again whenever the list of due tasks changes or a new day
    // starts, and after every log-in; the bell keeps the count in between. Only completing a task removes it.
    $pmDue = empty($GLOBALS['LAYOUT_BARE']) && !is_post() ? ($GLOBALS['PM_REMINDERS'] ?? null) : null;
    $pmSig = $pmDue ? today() . ':' . implode(',', array_map(fn($r) => $r['id'], $pmDue)) : '';
    if ($pmDue && ($GLOBALS['CURRENT_PAGE'] ?? '') !== 'fa.pm' && ($_SESSION['pm_popup'] ?? '') !== $pmSig):
        $_SESSION['pm_popup'] = $pmSig; ?>
<div class="modal fade" id="pmReminderModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-header"><span class="class-confirm-ico me-2"><i class="bi bi-bell"></i></span><h5 class="modal-title">Maintenance due: <?= count($pmDue) ?> task<?= count($pmDue) === 1 ? '' : 's' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="reminder-list">
    <?php foreach (array_slice($pmDue, 0, 40) as $r): ?>
      <div class="reminder-item"><div><strong><?= h($r['task_name']) ?></strong><div class="small text-muted"><?= h($r['asset_no'] . ' - ' . $r['asset_description']) ?><?= $r['asset_location'] ? ', ' . h($r['asset_location']) : '' ?></div></div>
        <div class="text-end text-nowrap"><span class="pm-state <?= h($r['state']) ?>"><?= h($r['state_text']) ?></span><div class="small text-muted"><?= h(fdate($r['next_due_date'])) ?></div></div></div>
    <?php endforeach; ?>
  </div><p class="small text-muted mt-2 mb-0">These reminders stay until each task is acknowledged as complete in Preventive Maintenance.</p></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Later</button><a class="btn btn-primary" href="<?= h(url('fa.pm', ['tab' => 'due'])) ?>"><i class="bi bi-calendar-check"></i> Open Preventive Maintenance</a></div>
</div></div></div>
<?php endif; ?>
<script src="<?= h(vendor_asset('bootstrap.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
<?php foreach ((array)($GLOBALS['LAYOUT_JS'] ?? []) as $js): ?>
<script src="<?= h(asset($js)) ?>"></script>
<?php endforeach; ?>
<script>(function () { var m = document.getElementById('pmReminderModal'); if (m && window.bootstrap) new bootstrap.Modal(m).show(); })();</script>
</body>
</html>
<?php
}

/** Bootstrap button link, shown only if allowed */
function btn_link(string $href, string $label, string $class = 'btn-outline-secondary', string $icon = ''): string
{
    return '<a class="btn btn-sm ' . h($class) . '" href="' . h($href) . '">' . ($icon ? '<i class="bi bi-' . h($icon) . '"></i> ' : '') . h($label) . '</a>';
}

function page_link(string $pageKey, array $params, string $label, string $class = 'btn-outline-secondary', string $icon = '', string $action = 'view'): string
{
    if (!Access::can($pageKey, $action)) {
        return '';
    }
    return btn_link(url($pageKey, $params), $label, $class, $icon);
}

/** A link that is plain text when the user may not open the target page */
function safe_link(string $pageKey, array $params, string $text): string
{
    if (!Access::page($pageKey)) {
        return h($text);
    }
    return '<a href="' . h(url($pageKey, $params)) . '">' . h($text) . '</a>';
}

function select_field(string $name, string $label, array $opts, $value, array $attr = []): string
{
    $blank = $attr['blank'] ?? null;
    $cls = $attr['class'] ?? 'col-md-4';
    $req = !empty($attr['required']) ? ' required' : '';
    $extra = $attr['extra'] ?? '';
    return '<div class="' . h($cls) . '"><label class="form-label">' . h($label) . '</label>'
        . '<select class="form-select form-select-sm" name="' . h($name) . '"' . $req . ' ' . $extra . '>' . options($opts, $value, $blank) . '</select></div>';
}

function input_field(string $name, string $label, $value, array $attr = []): string
{
    $type = $attr['type'] ?? 'text';
    $cls = $attr['class'] ?? 'col-md-4';
    $req = !empty($attr['required']) ? ' required' : '';
    $extra = $attr['extra'] ?? '';
    $step = $type === 'number' ? ' step="' . h($attr['step'] ?? '0.01') . '"' : '';
    return '<div class="' . h($cls) . '"><label class="form-label">' . h($label) . '</label>'
        . '<input class="form-control form-control-sm" type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"' . $req . $step . ' ' . $extra . '></div>';
}

function check_field(string $name, string $label, $checked, string $cls = 'col-md-4'): string
{
    return '<div class="' . h($cls) . ' d-flex align-items-end"><div class="form-check">'
        . '<input type="hidden" name="' . h($name) . '" value="0">'
        . '<input class="form-check-input" type="checkbox" id="f_' . h($name) . '" name="' . h($name) . '" value="1"' . checked($checked) . '>'
        . '<label class="form-check-label" for="f_' . h($name) . '">' . h($label) . '</label></div></div>';
}

/**
 * A drop-down list of check boxes, for picking any number of choices at once (sent as name[]; nothing ticked means all).
 *   'all'    - the text shown when nothing is ticked ('All categories')
 *   'noun'   - what the choices are, for the summary ('categories')
 *   (an empty name: the ticks only steer the page's own script and are not sent with the form)
 *   'submit' - a GET filter: the form is sent when the list is closed after a change (and by its Show button)
 */
function multi_check_field(string $name, string $label, array $opts, array $values, array $attr = []): string
{
    $cls = $attr['class'] ?? 'col-md-4';
    $all = (string)($attr['all'] ?? 'All');
    $noun = (string)($attr['noun'] ?? 'selected');
    $id = (string)($attr['id'] ?? ('mc_' . preg_replace('/\W+/', '', $name)));
    $vals = array_map('strval', $values);
    // the choices may come in groups: [group heading => [value => label]]
    $flat = [];
    foreach ($opts as $k => $v) {
        if (is_array($v)) {
            $flat += $v;
        } else {
            $flat[$k] = $v;
        }
    }
    $picked = array_values(array_filter($flat, fn($k) => in_array((string)$k, $vals, true), ARRAY_FILTER_USE_KEY));
    $summary = multi_check_summary($picked, $all, $noun);
    $bare = !empty($attr['nolabel']);   // no label above it (it sits in a toolbar); the button carries the label instead
    $html = '<div class="' . h($cls) . '">' . ($bare ? '' : '<label class="form-label" id="' . h($id) . 'L">' . h($label) . '</label>')
        . '<div class="dropdown multi-check" id="' . h($id) . '" data-multi-check data-all="' . h($all) . '" data-noun="' . h($noun) . '"' . (!empty($attr['submit']) ? ' data-multi-submit' : '') . '>'
        . '<button type="button" class="form-select form-select-sm multi-check-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" '
        . ($bare ? 'title="' . h($label) . '" aria-label="' . h($label) . '"' : 'aria-labelledby="' . h($id) . 'L ' . h($id) . 'S"') . '>'
        . '<span class="multi-check-text" id="' . h($id) . 'S">' . h($summary) . '</span></button>'
        . '<div class="dropdown-menu multi-check-menu">'
        . '<div class="multi-check-tools">'
        . (count($flat) > 8 ? '<input type="search" class="form-control form-control-sm" placeholder="Type to find" autocomplete="off" aria-label="Find in ' . h($label) . '" data-mc-find>' : '')
        . '<div class="multi-check-links"><button type="button" class="btn btn-link btn-sm p-0" data-mc-all>Tick all</button><button type="button" class="btn btn-link btn-sm p-0" data-mc-none>Clear</button></div></div>'
        . '<div class="multi-check-list">';
    $item = fn($k, $v) => '<label class="multi-check-item"><input class="form-check-input" type="checkbox"' . ($name !== '' ? ' name="' . h($name) . '[]"' : '') . ' value="' . h((string)$k) . '"'
        . (in_array((string)$k, $vals, true) ? ' checked' : '') . '><span>' . h((string)$v) . '</span></label>';
    foreach ($opts as $k => $v) {
        if (is_array($v)) {
            $html .= '<div class="multi-check-group" data-mc-group>' . h((string)$k) . '</div>';
            foreach ($v as $kk => $vv) {
                $html .= $item($kk, $vv);
            }
        } else {
            $html .= $item($k, $v);
        }
    }
    if (!$opts) {
        $html .= '<div class="small text-muted px-3 py-2">Nothing to choose from yet.</div>';
    }
    $html .= '</div>';
    if (!empty($attr['submit'])) {
        $html .= '<div class="multi-check-foot"><span class="small text-muted">Nothing ticked: ' . h(lcfirst($all)) . '</span><button type="submit" class="btn btn-primary btn-sm">Show</button></div>';
    }
    return $html . '</div></div></div>';
}

/** "Hardware", "Hardware, Paint", "Hardware, Paint and 3 more categories" */
function multi_check_summary(array $names, string $all, string $noun): string
{
    $n = count($names);
    if ($n === 0) {
        return $all;
    }
    if ($n <= 2) {
        return implode(', ', $names);
    }
    return $names[0] . ', ' . $names[1] . ' and ' . ($n - 2) . ' more ' . $noun;
}

/** The ids ticked in a multi_check_field of a GET form (an old single "<name>" id is still understood) */
function gints(string $name, string $single = ''): array
{
    $v = $_GET[$name] ?? [];
    $ids = is_array($v) ? $v : explode(',', (string)$v);
    if ($single !== '' && ($_GET[$single] ?? '') !== '') {
        $ids[] = $_GET[$single];
    }
    $out = [];
    foreach ($ids as $id) {
        if (is_scalar($id) && (int)$id > 0) {
            $out[(int)$id] = (int)$id;
        }
    }
    return array_values($out);
}

/**
 * Save / Save & New / Cancel buttons for a form.
 * "Save & New" posts save_new=1; redirect_after_save() then re-opens an empty form,
 * so the next record can be entered without going back to the list.
 */
function save_buttons(string $label = 'Save', ?string $cancelUrl = null, bool $showNew = true, string $extra = ''): string
{
    $html = '<button class="btn btn-primary btn-sm" name="save" value="1"><i class="bi bi-check-lg"></i> ' . h($label) . '</button>';
    if ($showNew) {
        $html .= ' <button class="btn btn-outline-primary btn-sm" name="save_new" value="1" title="Save this one and start another with empty fields">'
            . '<i class="bi bi-plus-lg"></i> ' . h($label) . ' &amp; New</button>';
    }
    if ($cancelUrl !== null) {
        $html .= ' <a class="btn btn-outline-secondary btn-sm" href="' . h($cancelUrl) . '">Cancel</a>';
    }
    return '<div class="d-flex gap-1 flex-wrap align-items-center">' . $html . ($extra ? ' ' . $extra : '') . '</div>';
}

/** True when the form was submitted with "Save & New" */
function wants_new(): bool
{
    return is_post() && (string)p('save_new') === '1';
}

/** Goes to a blank form after "Save & New", otherwise to the saved record */
function redirect_after_save(string $newUrl, string $savedUrl): void
{
    redirect(wants_new() ? $newUrl : $savedUrl);
}

function reason_modal(string $id, string $title, string $action, string $buttonClass = 'btn-danger', string $extraHtml = ''): string
{
    return '<div class="modal fade" id="' . h($id) . '" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="' . h($action) . '">'
        . csrf_field()
        . '<div class="modal-header"><h5 class="modal-title">' . h($title) . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body"><label class="form-label">Reason (required, recorded in the audit trail)</label>'
        . '<textarea class="form-control" name="reason" required rows="2"></textarea>' . $extraHtml . Approval::fields() . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button class="btn ' . h($buttonClass) . '">' . h($title) . '</button></div></form></div></div>';
}

/**
 * The party a customer, supplier or other-name record belongs to, for its form and detail panel: the Party ID, the
 * legal name documents print, and the party's other roles.
 */
function party_note(?int $partyId, string $role = ''): string
{
    if (!$partyId || !Party::ready()) {
        return '';
    }
    $p = Party::get($partyId);
    if (!$p) {
        return '';
    }
    $roles = Party::roleNamesAll([$partyId])[$partyId] ?? [];
    $others = array_values(array_filter($roles, fn($r) => $role === '' || stripos($r, $role) !== 0));
    $no = Access::page('party.center') ? '<a href="' . h(url('party.center', ['id' => $partyId])) . '">' . h($p['party_no']) . '</a>' : h($p['party_no']);
    $member = Party::member($partyId);
    return '<div class="small text-muted party-note">Party ' . $no . ', printed on documents as <strong class="text-body">' . h($p['legal_name']) . '</strong>'
        . ($member ? ' (member no. ' . h($member['member_no']) . ')' : '')
        . ($others ? '. Also ' . h(strtolower(implode(', ', $others))) : '') . '.</div>';
}

/** Party choice on a new customer, supplier or other-name form: an existing party, or a new one made from the record */
function party_pick_field(string $what, ?int $value = null, string $class = 'col-md-8'): string
{
    if (!Party::ready()) {
        return '';
    }
    $html = select_field('link_party_id', 'Person or organization already on file', Party::options($value), fv('link_party_id', null, $value ?: ''),
        ['class' => $class, 'blank' => 'Not on file: a new party made from this ' . $what, 'extra' => 'data-combo']);
    if (!empty($GLOBALS['FORM_ERROR']) && str_contains((string)$GLOBALS['FORM_ERROR'], 'different person')) {
        $html .= '<div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="distinct_party" value="1" id="distinctParty">'
            . '<label class="form-check-label small" for="distinctParty">This is a different person or organization</label></div></div>';
    }
    return $html;
}

/**
 * The reason box on the form of a posted document while posted documents are locked: shown to users who may change
 * them, and needed only when the change touches amounts, accounts, names, dates or lines.
 */
function posted_change_field(?array $t): string
{
    if (!$t || empty($t['id']) || empty($t['txn_type']) || !Txn::postedLocked($t) || !Access::special('edit_posted_documents')) {
        return '';
    }
    return '<div class="card border-warning mb-2"><div class="card-body py-2"><label class="form-label" for="postedReason">Reason for changing this posted document</label>'
        . '<input class="form-control form-control-sm" id="postedReason" name="posted_change_reason" maxlength="255" value="' . h((string)fv('posted_change_reason')) . '">'
        . '<div class="small text-muted mt-1">Needed when amounts, accounts, names, dates or lines change. The document as it was stays in the audit trail.</div></div></div>';
}
