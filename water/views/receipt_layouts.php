<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, basename(__FILE__, '.php'));
/* AUTH_GUARD_END */

/**
 * Water Billing > Setup > Receipt Layout Setup (version 46).
 *
 * The pre-printed forms of the water bill / collection receipt and of the official receipt, set up as a passbook is on
 * Passbook Types: the paper, the top, right, bottom and left margins, the copies across the paper (the water bill has
 * two), and for each part of the receipt (Header, Lines, Totals) its origin and the entries it prints, each placed from
 * that origin (Left, Top) with its width, alignment, format and font size. The entries are dragged on the drawing of the
 * form or typed in the table; a test page prints the saved layout with sample entries. Every measurement is in tups
 * (1,440 tups = 1 inch, 567 = 1 cm). Printed or Pre-Printed is chosen on Company, Security & Closing Date > Water
 * Billing. The layouts are kept with the company settings (app/WaterReceiptLayout.php).
 */
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
$pageKey = 'water.receipt_layouts';
$kind = isset(WaterReceiptLayout::KINDS[(string)($_GET['kind'] ?? $_POST['kind'] ?? '')]) ? (string)($_GET['kind'] ?? $_POST['kind']) : 'collection';
$canEdit = Access::can($pageKey, 'edit');

// ------------------------------------------------------------------ a test page: the saved layout with sample entries
if (($_GET['a'] ?? '') === 'test') {
    $layout = WaterReceiptLayout::get($kind);
    $sample = WaterReceiptLayout::sample($kind);
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Test page: <?= e(WaterReceiptLayout::KINDS[$kind]) ?></title>
<style>
body{margin:0;background:#eef1f5;font-family:Arial,Helvetica,sans-serif}
.bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 16px;background:#111827;color:#fff;font-size:13px}
.bar button,.bar a{border:0;border-radius:6px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none}
.bar button{background:#fff;color:#111827}.bar a{background:#374151;color:#fff}
.stage{padding:24px;overflow:auto}
<?= WaterReceiptLayout::css($layout) ?>
@media print{body{background:#fff}.bar{display:none}.stage{padding:0}.wrl-page{box-shadow:none;outline:0}.wrl-area{outline:0}}
</style></head><body>
<div class="bar"><button type="button" onclick="window.print()">Print the test page</button><a href="receipt_layouts.php?kind=<?= e($kind) ?>">Back to Receipt Layout Setup</a>
<span>Put a <?= e(mb_strtolower(WaterReceiptLayout::KINDS[$kind])) ?> form (or plain paper) in the printer and print at Actual Size / 100%: every sample entry should fall on its place on the form.</span></div>
<div class="stage"><?= WaterReceiptLayout::render($kind, $layout, $sample['values'], $sample['lines']) ?></div>
</body></html><?php
    exit;
}

// ------------------------------------------------------------------ save / back to the default
$flashOk = (string)($_SESSION['success_msg'] ?? '');
$flashBad = (string)($_SESSION['error_msg'] ?? '');
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
$posted = null;
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your form session expired. Reload the page and try again.');
        }
        if (!$canEdit) {
            throw new RuntimeException('Your role may not change the receipt layouts (the Edit right on Receipt Layout Setup).');
        }
        if (($_POST['action'] ?? '') === 'reset') {
            WaterReceiptLayout::reset($kind, $pageKey);
            $_SESSION['success_msg'] = 'The ' . mb_strtolower(WaterReceiptLayout::KINDS[$kind]) . ' is back to the default layout.';
        } else {
            $raw = ['setup' => (array)($_POST['setup'] ?? [])];
            foreach (array_keys(WaterReceiptLayout::SECTIONS) as $sec) {
                $raw[$sec] = array_values(array_filter((array)($_POST['L'][$sec] ?? []), 'is_array'));
            }
            $posted = WaterReceiptLayout::clean($kind, $raw);
            WaterReceiptLayout::save($kind, $posted, $pageKey, trim((string)($_POST['reason'] ?? '')));
            $warn = WaterReceiptLayout::warnings($kind, $posted);
            $_SESSION['success_msg'] = 'The layout of the ' . mb_strtolower(WaterReceiptLayout::KINDS[$kind]) . ' was saved.' . ($warn ? ' Note: ' . implode(' ', $warn) : '');
        }
        header('Location: receipt_layouts.php?kind=' . urlencode($kind));
        exit;
    } catch (Throwable $ex) {
        $flashBad = $ex instanceof UserError || $ex instanceof RuntimeException ? $ex->getMessage() : 'The layout could not be saved.';
        if (!($ex instanceof UserError || $ex instanceof RuntimeException)) {
            error_log('[receipt_layouts] ' . $ex->getMessage());
        }
    }
}

$layout = $posted ?? WaterReceiptLayout::get($kind);
$default = WaterReceiptLayout::defaultLayout($kind);
$setup = $layout['setup'];
$catalog = [];
foreach (WaterReceiptLayout::FIELDS[$kind] as $sec => $fields) {
    foreach ($fields as $k => [$label, $fk]) {
        $catalog[$sec][$k] = ['label' => $label, 'kind' => $fk];
    }
}
$sample = WaterReceiptLayout::sample($kind);
$warnings = WaterReceiptLayout::warnings($kind, $layout);
$types = ['collection' => WaterReceiptLayout::type('collection'), 'official' => WaterReceiptLayout::type('official')];
$opt = function (array $list, $value): string {
    $h = '';
    foreach ($list as $k => $label) {
        $h .= '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
};
$mkRow = function (string $sec, $i, array $it) use ($catalog, $opt): string {
    $kd = $catalog[$sec][$it['f']]['kind'] ?? 'text';
    $n = fn(string $k) => 'L[' . $sec . '][' . $i . '][' . $k . ']';
    $fields = [];
    foreach ($catalog[$sec] as $k => $c) {
        $fields[$k] = $c['label'];
    }
    $fmts = WaterReceiptLayout::FORMATS[$kd] ?? [];
    return '<tr data-item data-kind="' . e($kd) . '">'
        . '<td data-label="Entry"><select class="rl-in" name="' . $n('f') . '" data-k="f" aria-label="Entry" data-no-search="true">' . $opt($fields, $it['f']) . '</select>'
        . '<input class="rl-in rl-text" name="' . $n('t') . '" data-k="t" value="' . e($it['t']) . '" placeholder="Text, e.g. Paid by {name}" aria-label="Text"' . ($it['f'] === 'text' ? '' : ' hidden') . '></td>'
        . '<td data-label="Left"><input class="rl-in rl-num" type="number" step="1" name="' . $n('x') . '" data-k="x" value="' . (int)$it['x'] . '" aria-label="Left"></td>'
        . '<td data-label="Top"><input class="rl-in rl-num" type="number" step="1" name="' . $n('y') . '" data-k="y" value="' . (int)$it['y'] . '" aria-label="Top"></td>'
        . '<td data-label="Width"><input class="rl-in rl-num" type="number" step="1" min="0" name="' . $n('w') . '" data-k="w" value="' . (int)$it['w'] . '" aria-label="Width"></td>'
        . '<td data-label="Align"><select class="rl-in" name="' . $n('a') . '" data-k="a" aria-label="Align" data-no-search="true">' . $opt(WaterReceiptLayout::ALIGN, $it['a']) . '</select></td>'
        . '<td data-label="Format"><select class="rl-in" name="' . $n('fmt') . '" data-k="fmt" aria-label="Format" data-no-search="true"' . ($fmts ? '' : ' hidden') . '>' . $opt($fmts, $it['fmt']) . '</select>'
        . '<select class="rl-in" name="' . $n('z') . '" data-k="z" aria-label="A zero amount prints" title="A zero amount prints" data-no-search="true"' . ($kd === 'money' ? '' : ' hidden') . '>' . $opt(WaterReceiptLayout::ZERO, $it['z']) . '</select></td>'
        . '<td data-label="Size (pt)"><input class="rl-in rl-num" type="number" step="0.1" min="0" max="20" name="' . $n('fs') . '" data-k="fs" value="' . ((float)$it['fs'] > 0 ? e((string)$it['fs']) : '') . '" placeholder="auto" aria-label="Font size"></td>'
        . '<td class="rl-acts"><button type="button" class="rl-x" data-del-item title="Remove the entry" aria-label="Remove the entry">&times;</button></td></tr>';
};
$secHelp = [
    'header' => 'The account, the name, the dates and the readings: printed once on each copy. Left and Top count from Header Left / Top.',
    'body' => 'One line for each charge on the receipt: line 1 at Lines Top, each next line one Interval lower, at most Max Lines (more charges are added together on the last line). Left counts from Lines Left; Top moves an entry up or down within its line.',
    'footer' => 'The totals, the cash, the change and the amount in words. Left and Top count from Totals Left / Top. A text may hold {amount_due}, {name} or any other entry of its part.',
];
if ($kind === 'official') {
    $secHelp['header'] = 'The date and the customer name. Left and Top count from Header Left / Top.';
    $secHelp['footer'] = 'The totals on the right of the form. Left and Top count from Totals Left / Top.';
}
$measureBox = function (string $k, string $label, string $hint = '') use ($setup): string {
    [$lab, $lo, $hi] = WaterReceiptLayout::MEASURES[$k];
    return '<div class="rl-field"><label for="rl_' . e($k) . '">' . e($label) . '</label><input class="rl-in rl-num" type="number" step="1" min="' . $lo . '" max="' . $hi . '" id="rl_' . e($k) . '" name="setup[' . e($k) . ']" value="' . (int)$setup[$k] . '" data-m="' . e($k) . '"' . ($hint !== '' ? ' title="' . e($hint) . '"' : '') . '></div>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Receipt Layout Setup - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root { --white: #fff; --off-white: #f8f9fb; --border: #e8eaed; --text-primary: #0f0f1a; --text-secondary: #6b7280; --text-muted: #9ca3af; --accent: #1a1a2e; --radius: 8px;
                --ok: #047857; --ok-bg: #ecfdf5; --bad: #b91c1c; --bad-bg: #fef2f2; --warn: #92400e; --warn-bg: #fffbeb; --blue: #1d4ed8; }
        body { margin: 0; min-height: 100vh; background: var(--off-white); color: var(--text-primary); font-family: 'Sora', sans-serif; }
        .page { max-width: 1320px; margin: 0 auto; padding: 26px 26px 120px; }
        .page-header { margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--border); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 7px; color: var(--text-secondary); font-size: .8rem; font-weight: 600; text-decoration: none; }
        .back-link:hover { color: var(--text-primary); }
        .page-title { margin: 0; font-size: 1.42rem; font-weight: 700; letter-spacing: -.02em; }
        .page-subtitle { max-width: 900px; margin: 5px 0 0; color: var(--text-secondary); font-size: .85rem; line-height: 1.55; }
        .notice { margin-bottom: 14px; padding: 11px 14px; border: 1px solid; border-radius: var(--radius); font-size: .82rem; font-weight: 600; line-height: 1.5; }
        .notice.ok { border-color: #a7f3d0; background: var(--ok-bg); color: var(--ok); }
        .notice.bad { border-color: #fecaca; background: var(--bad-bg); color: var(--bad); }
        .notice.warn { border-color: #fde68a; background: var(--warn-bg); color: var(--warn); }
        .rl-kinds { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; border-bottom: 1px solid var(--border); }
        .rl-kinds a { padding: 10px 14px; color: var(--text-secondary); font-size: .84rem; font-weight: 700; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
        .rl-kinds a.is-on { color: var(--text-primary); border-bottom-color: var(--accent); }
        .rl-kinds .rl-state { margin-left: 6px; font-weight: 600; font-size: .74rem; color: var(--text-muted); }
        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 14px; }
        .card-head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; justify-content: space-between; padding: 13px 16px; border-bottom: 1px solid var(--border); background: #fafafa; border-radius: var(--radius) var(--radius) 0 0; }
        .card-title { font-size: .92rem; font-weight: 700; margin: 0; }
        .card-sub { color: var(--text-secondary); font-size: .76rem; }
        .card-body { padding: 14px 16px; }
        .rl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px 12px; }
        .rl-group { border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 12px 12px; }
        .rl-group h3 { margin: 0 0 8px; font-size: .74rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text-secondary); }
        .rl-groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
        .rl-group .rl-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .rl-field label { display: block; font-size: .7rem; font-weight: 700; color: #374151; margin-bottom: 4px; }
        .rl-in { width: 100%; padding: 7px 9px; border: 1px solid #d1d5db; border-radius: 6px; font: 500 .8rem 'Sora', sans-serif; background: #fff; color: var(--text-primary); }
        .rl-in:focus { outline: 2px solid rgba(29, 78, 216, .3); border-color: var(--blue); }
        .rl-num { text-align: right; font-family: 'JetBrains Mono', monospace; }
        .rl-text { margin-top: 5px; }
        .rl-note { margin-top: 10px; color: var(--text-secondary); font-size: .74rem; line-height: 1.5; }
        .rl-secs { display: flex; gap: 4px; flex-wrap: wrap; }
        .rl-sec { border: 1px solid var(--border); background: #fff; border-radius: 6px; padding: 7px 12px; font: 700 .78rem 'Sora', sans-serif; color: var(--text-secondary); cursor: pointer; }
        .rl-sec.is-on { background: var(--accent); border-color: var(--accent); color: #fff; }
        .rl-sec .rl-count { font-weight: 600; opacity: .75; margin-left: 4px; }
        .rl-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-size: .74rem; color: var(--text-secondary); }
        .rl-zoom { display: inline-flex; align-items: center; gap: 6px; }
        .rl-stage { background: #e9edf2; border-radius: var(--radius); padding: 18px; overflow: auto; outline: none; }
        .rl-stage:focus-visible { box-shadow: 0 0 0 3px rgba(29, 78, 216, .3); }
        .rl-paper { position: relative; background: #fff; box-shadow: 0 8px 22px rgba(15, 23, 42, .16); margin: 0 auto; font-family: "Courier New", Courier, monospace; color: #111; }
        .rl-paper.is-sans { font-family: Arial, Helvetica, sans-serif; }
        .rl-margin { position: absolute; border: 1px dashed #94a3b8; pointer-events: none; }
        .rl-copy2 { position: absolute; border: 1px dashed #cbd5e1; pointer-events: none; }
        .rl-item { position: absolute; white-space: nowrap; line-height: 1.15; cursor: default; }
        .rl-item.is-live { cursor: move; outline: 1px dotted rgba(29, 78, 216, .45); }
        .rl-item.is-sel { outline: 2px solid var(--blue); background: rgba(29, 78, 216, .07); }
        .rl-item.is-empty { color: #9ca3af; font-style: italic; }
        .rl-item.is-other { opacity: .45; }
        .rl-origin { position: absolute; width: 14px; height: 14px; margin: -7px 0 0 -7px; cursor: move; }
        .rl-origin::before, .rl-origin::after { content: ''; position: absolute; background: var(--blue); }
        .rl-origin::before { left: 6px; top: 0; width: 2px; height: 14px; }
        .rl-origin::after { top: 6px; left: 0; height: 2px; width: 14px; }
        .rl-line { position: absolute; left: 0; right: 0; border-top: 1px dotted #cbd5e1; pointer-events: none; }
        table.rl-items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .rl-items th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--text-secondary); padding: 7px 6px; border-bottom: 1px solid var(--border); background: #fafafa; }
        .rl-items td { padding: 6px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .rl-items tr.is-sel td { background: #eef2ff; }
        .rl-items td:nth-child(2), .rl-items td:nth-child(3), .rl-items td:nth-child(4), .rl-items td:nth-child(7) { width: 92px; }
        .rl-x { border: 0; background: transparent; color: var(--bad); font-size: 1.2rem; line-height: 1; cursor: pointer; padding: 4px 8px; border-radius: 6px; }
        .rl-x:hover { background: var(--bad-bg); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 36px; padding: 8px 14px; border: 1px solid transparent; border-radius: 6px; font: 650 .8rem 'Sora', sans-serif; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-light { background: #fff; border-color: var(--border); color: var(--text-primary); }
        .btn-danger { background: #fff; border-color: #fecaca; color: var(--bad); }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .rl-actions { position: sticky; bottom: 0; z-index: 20; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px 16px; background: rgba(255, 255, 255, .96); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: 0 -6px 18px rgba(15, 23, 42, .06); }
        .rl-actions .rl-reason { flex: 1 1 260px; max-width: 420px; }
        .rl-actions .rl-right { margin-left: auto; }
        .rl-type { display: flex; flex-wrap: wrap; gap: 6px 18px; align-items: center; font-size: .82rem; }
        .rl-type strong { font-weight: 700; }
        @media (max-width: 760px) {
            .page { padding: 16px 12px 140px; }
            .page-title { font-size: 1.2rem; }
            .rl-items thead { display: none; }
            .rl-items, .rl-items tbody, .rl-items tr, .rl-items td { display: block; width: 100% !important; }
            .rl-items tr { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; padding: 10px 0; border-bottom: 1px solid var(--border); }
            .rl-items td { border: 0; padding: 0; }
            .rl-items td[data-label="Entry"] { grid-column: 1 / -1; }
            .rl-items td[data-label]::before { content: attr(data-label); display: block; font-size: .66rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; margin-bottom: 2px; }
            .rl-items td.rl-acts { grid-column: 3; text-align: right; }
            .rl-actions .rl-right { margin-left: 0; }
            .rl-actions .btn { flex: 1 1 auto; }
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
            Water Settings
        </a>
        <h1 class="page-title">Receipt Layout Setup</h1>
        <p class="page-subtitle">The pre-printed forms of the receipts: only the entries are printed, each where the form has room for it. Set the paper and its margins, then place every entry by dragging it on the drawing or by typing its Left and Top. All measurements are in tups: 1 inch = 1,440 tups, 1 cm = 567.</p>
    </header>

    <?php if ($flashOk !== ''): ?><div class="notice ok" role="status"><?= e($flashOk) ?></div><?php endif; ?>
    <?php if ($flashBad !== ''): ?><div class="notice bad" role="alert"><?= e($flashBad) ?></div><?php endif; ?>
    <?php foreach ($warnings as $w): ?><div class="notice warn"><?= e($w) ?></div><?php endforeach; ?>

    <div class="card"><div class="card-body rl-type">
        <span>Collection receipt prints: <strong><?= e(WaterReceiptLayout::TYPES[$types['collection']]) ?></strong></span>
        <span>Official receipt prints: <strong><?= e(WaterReceiptLayout::TYPES[$types['official']]) ?></strong></span>
        <?php if (Access::page('admin.settings')): ?><a href="<?= e(url('admin.settings')) ?>#water-billing" style="color:var(--blue);font-weight:600;text-decoration:none">Change on Company, Security &amp; Closing Date</a><?php else: ?><span class="card-sub">Printed or Pre-Printed is chosen on Company, Security &amp; Closing Date.</span><?php endif; ?>
    </div></div>

    <nav class="rl-kinds" aria-label="Receipt">
        <?php foreach (WaterReceiptLayout::KINDS as $k => $label): ?>
            <a href="receipt_layouts.php?kind=<?= e($k) ?>" class="<?= $k === $kind ? 'is-on' : '' ?>"<?= $k === $kind ? ' aria-current="page"' : '' ?>><?= e($label) ?><span class="rl-state"><?= WaterReceiptLayout::isCustom($k) ? 'own layout' : 'default layout' ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form method="post" id="rlForm" action="receipt_layouts.php?kind=<?= e($kind) ?>"
          data-catalog='<?= e(json_encode($catalog)) ?>' data-formats='<?= e(json_encode(WaterReceiptLayout::FORMATS)) ?>'
          data-sample='<?= e(json_encode($sample)) ?>' data-kind="<?= e($kind) ?>" data-editable="<?= $canEdit ? '1' : '0' ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="kind" value="<?= e($kind) ?>">
        <input type="hidden" name="action" value="save">
        <fieldset style="border:0;margin:0;padding:0;min-width:0"<?= $canEdit ? '' : ' disabled' ?>>
        <section class="card">
            <div class="card-head"><h2 class="card-title">Paper, margins and printing</h2><span class="card-sub">Nothing is printed in the margins.</span></div>
            <div class="card-body">
                <div class="rl-groups">
                    <div class="rl-group"><h3>Paper</h3>
                        <div class="rl-field" style="margin-bottom:10px"><label for="rlSize">Size</label><select class="rl-in" id="rlSize" data-no-search="true"><option value="">Custom</option><?= $opt(WaterReceiptLayout::PAGE_SIZES, $setup['page_width'] . 'x' . $setup['page_height']) ?></select></div>
                        <div class="rl-grid"><?= $measureBox('page_width', 'Width') ?><?= $measureBox('page_height', 'Height') ?></div>
                    </div>
                    <div class="rl-group"><h3>Margins</h3>
                        <div class="rl-grid"><?= $measureBox('margin_top', 'Top') ?><?= $measureBox('margin_bottom', 'Bottom') ?><?= $measureBox('margin_left', 'Left') ?><?= $measureBox('margin_right', 'Right') ?></div>
                    </div>
                    <div class="rl-group"><h3>Font</h3>
                        <div class="rl-grid">
                            <div class="rl-field"><label for="rlFont">Font</label><select class="rl-in" id="rlFont" name="setup[font]" data-m="font" data-no-search="true"><?= $opt(WaterReceiptLayout::FONTS, $setup['font']) ?></select></div>
                            <div class="rl-field"><label for="rlFs">Size (pt)</label><input class="rl-in rl-num" type="number" min="4" max="20" step="0.1" id="rlFs" name="setup[font_size_pt]" value="<?= e($setup['font_size_pt']) ?>" data-m="font_size_pt"></div>
                            <div class="rl-field" style="grid-column:1/-1"><label for="rlCpi">Characters per inch</label><select class="rl-in" id="rlCpi" name="setup[cpi]" data-m="cpi" data-no-search="true"><?= $opt(WaterReceiptLayout::CPI, $setup['cpi']) ?></select></div>
                        </div>
                    </div>
                    <?php if ($kind === 'collection'): ?>
                    <div class="rl-group"><h3>Copies</h3>
                        <div class="rl-grid">
                            <div class="rl-field" style="grid-column:1/-1"><label for="rl_copies">Copies across the paper</label><select class="rl-in" id="rl_copies" name="setup[copies]" data-m="copies" data-no-search="true"><?= $opt([1 => 'One copy', 2 => "Two: Cooperative's and Consumer's"], $setup['copies']) ?></select></div>
                            <?= $measureBox('copy_left', 'Second copy: Left', 'How far right of the first copy the second one prints') ?><?= $measureBox('copy_top', 'Second copy: Top', 'How far below the first copy the second one prints') ?>
                        </div>
                    </div>
                    <?php else: ?><input type="hidden" name="setup[copies]" value="1"><?php endif; ?>
                </div>
                <div class="rl-groups" style="margin-top:12px">
                    <div class="rl-group"><h3>Header</h3><div class="rl-grid"><?= $measureBox('header_top', 'Top') ?><?= $measureBox('header_left', 'Left') ?></div></div>
                    <div class="rl-group"><h3>Lines of charges</h3><div class="rl-grid"><?= $measureBox('body_top', 'Top') ?><?= $measureBox('body_left', 'Left') ?><?= $measureBox('body_interval', 'Interval') ?><?= $measureBox('max_lines', 'Max Lines') ?></div></div>
                    <div class="rl-group"><h3>Totals</h3><div class="rl-grid"><?= $measureBox('footer_top', 'Top') ?><?= $measureBox('footer_left', 'Left') ?></div></div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">Where each entry prints</h2>
                <div class="rl-secs" role="tablist" aria-label="Part of the receipt">
                    <?php foreach (WaterReceiptLayout::SECTIONS as $sec => $title): ?><button type="button" role="tab" class="rl-sec<?= $sec === 'header' ? ' is-on' : '' ?>" data-sec="<?= e($sec) ?>" aria-selected="<?= $sec === 'header' ? 'true' : 'false' ?>"><?= e($title) ?><span class="rl-count"><?= count($layout[$sec]) ?></span></button><?php endforeach; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="rl-tools"><span data-stage-help>Drag an entry to move it (arrow keys: 10 tups, with Shift: 100); drag the blue cross to move the whole part. The dashed frame is the margin.</span>
                    <span class="rl-zoom"><label for="rlZoom">Zoom</label><input type="range" id="rlZoom" min="40" max="200" value="100" step="5"></span></div>
                <div class="rl-stage" data-stage tabindex="0" aria-label="Drawing of the receipt form"></div>
                <?php foreach (WaterReceiptLayout::SECTIONS as $sec => $title): ?>
                <div data-sec-pane="<?= e($sec) ?>"<?= $sec === 'header' ? '' : ' hidden' ?>>
                    <p class="rl-note"><?= e($secHelp[$sec]) ?></p>
                    <div style="overflow-x:auto"><table class="rl-items"><thead><tr><th>Entry</th><th>Left</th><th>Top</th><th>Width</th><th>Align</th><th>Format</th><th>Size (pt)</th><th></th></tr></thead>
                        <tbody data-items="<?= e($sec) ?>"><?php foreach ($layout[$sec] as $i => $it): ?><?= $mkRow($sec, $i, $it) ?><?php endforeach; ?></tbody></table></div>
                    <template data-tpl="<?= e($sec) ?>"><?= $mkRow($sec, '__i__', ['f' => (string)array_key_first($catalog[$sec]), 'x' => 0, 'y' => 0, 'w' => 0, 'a' => 'l', 'fmt' => '', 'z' => '', 't' => '', 'fs' => 0]) ?></template>
                    <?php if ($canEdit): ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
                        <button type="button" class="btn btn-light" data-add-item="<?= e($sec) ?>">+ Add an entry</button>
                        <button type="button" class="btn btn-light" data-reset-sec="<?= e($sec) ?>" data-default='<?= e(json_encode($default[$sec])) ?>'>Use the default <?= e(mb_strtolower($title)) ?></button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        </fieldset>

        <div class="rl-actions">
            <?php if ($canEdit): ?>
                <input class="rl-in rl-reason" name="reason" maxlength="200" placeholder="Reason for the change (kept in the audit trail)" aria-label="Reason for the change">
                <button class="btn btn-primary" type="submit">Save layout</button>
            <?php endif; ?>
            <a class="btn btn-light" href="receipt_layouts.php?kind=<?= e($kind) ?>&amp;a=test" target="_blank" rel="noopener" data-test>Print a test page</a>
            <?php if ($canEdit && WaterReceiptLayout::isCustom($kind)): ?>
                <button class="btn btn-danger rl-right" type="submit" name="action" value="reset" formnovalidate data-confirm="Put the <?= e(mb_strtolower(WaterReceiptLayout::KINDS[$kind])) ?> back to the default layout? Your layout is replaced." data-confirm-ok="Use the default">Back to the default layout</button>
            <?php endif; ?>
        </div>
    </form>
</main>
</div>
<script src="../common/receipt-designer.js"></script>
</body>
</html>
