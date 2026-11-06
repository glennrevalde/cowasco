<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

/**
 * Import of Batch Share Capital Billing: a file (CSV or Excel) of share capital accounts and their fixed amounts is
 * read, every row shown for review (correct the amount or any cell, remove a member), then posted: every member on
 * fixed billing at once, or none. The rights are those of Fixed Share Capital Billing (Create to post).
 */
if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
$pageKey = 'water.share_capital_billing';
$ready = ShareCapitalBilling::ready();
$canCreate = Access::can($pageKey, 'create');
$token = operationToken('share_capital_import');
$cols = ShareCapitalBilling::IMPORT_COLUMNS;
$freq = ShareCapitalBilling::FREQUENCIES;
$colsJs = [];
foreach ($cols as $k => $c) {
    $colsJs[] = ['key' => $k, 'label' => $c['label'], 'type' => $c['type'] ?? 'text', 'size' => $c['size'] ?? 'md', 'req' => !empty($c['req'])];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../common/fonts.css">
<link rel="stylesheet" href="../common/import-review.css?v=<?= (int)@filemtime(__DIR__ . '/../common/import-review.css') ?>">
<title>Import Batch Share Capital Billing</title>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
<main class="page">
    <a class="back-link" href="share_capital_billing.php">&larr; Back to Fixed Share Capital Billing</a>
    <div class="page-header">
        <div>
            <h1 class="page-title">Import Batch Share Capital Billing</h1>
            <p class="page-sub">Put many members on fixed share capital billing from a file: one line for each share capital account, with its amount. Every line is shown for review first: correct an amount, remove a member, then post the billing. Nothing is saved until you post, and then every member or none.</p>
        </div>
    </div>

    <?php if (!$ready): ?>
        <div class="alert alert-error">Run the database updates of Fixed Share Capital Billing first (database/migrations/2027_03_14_share_capital_billing.sql and 2027_03_21_share_capital_terms_batch.sql).</div>
    <?php else: ?>
    <section class="card" aria-labelledby="defTitle">
        <div class="card-head"><div><h2 class="card-title" id="defTitle">Values for the blank cells</h2><p class="card-sub">A cell of the file left blank takes the value here; a cell filled in keeps its own. Changing a value here checks the lines again.</p></div></div>
        <div class="card-body">
            <form id="irDefaults" class="imp-defaults" onsubmit="return false">
                <div><label class="field-label" for="dAmount">Amount</label><input class="field" type="text" inputmode="decimal" name="amount" id="dAmount" placeholder="Each line's own"></div>
                <div><label class="field-label" for="dFreq">Frequency</label>
                    <select class="field" name="frequency" id="dFreq"><option value="">Each line's own</option><?php foreach ($freq as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $k === 'monthly' ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div><label class="field-label" for="dStart">Start date</label><input class="field" type="date" name="start_date" id="dStart" value="<?= e(date('Y-m-d')) ?>"></div>
                <div><label class="field-label" for="dDay">Posting day</label>
                    <select class="field" name="posting_day" id="dDay"><option value="">Each line's own</option><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>" <?= $d === 1 ? 'selected' : '' ?>><?= $d ?></option><?php endfor; ?></select></div>
                <div><label class="field-label" for="dTerm">Term (days)</label><input class="field" type="number" min="0" max="3650" step="1" name="term_days" id="dTerm" placeholder="Blank: no due date">
                    <div class="field-hint">The due date of a bill is its posting date plus the term; blank: no due date (open). A line may say OPEN.</div></div>
            </form>
        </div>
    </section>

    <div id="irStart" class="imp-start">
        <section class="card" aria-labelledby="fileTitle">
            <div class="card-head"><div><h2 class="card-title" id="fileTitle">The file</h2><p class="card-sub">CSV (Comma delimited) or Excel workbook (.xlsx); the first line holds the column names.</p></div></div>
            <div class="card-body">
                <form id="irReadForm" enctype="multipart/form-data">
                    <label class="imp-drop">
                        <input type="file" name="file" id="irFile" accept=".csv,.txt,.tsv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
                               onchange="document.getElementById('irFilePicked').textContent = this.files[0] ? this.files[0].name : ''">
                        <div class="imp-drop-title">Choose the file, or drop it here</div>
                        <div class="imp-drop-sub">One line per share capital account</div>
                        <div class="imp-drop-file" id="irFilePicked"></div>
                    </label>
                    <div class="imp-step-foot">
                        <button type="submit" class="btn btn-primary" id="irReadBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>Read the file for review</button>
                        <a class="btn btn-ghost" href="../process/shareCapitalImport.php?action=template&amp;format=xlsx">Excel template</a>
                        <a class="btn btn-ghost" href="../process/shareCapitalImport.php?action=template&amp;format=csv">CSV template</a>
                    </div>
                    <div class="alert alert-error" id="irReadMsg" hidden style="margin:14px 0 0"></div>
                    <p class="imp-note">Nothing is saved yet: every line is shown for review first, where it can be corrected or removed. Only members with an open share capital account are billed; an account already on fixed billing is changed on its page, not imported again.</p>
                </form>
            </div>
        </section>
        <section class="card" aria-labelledby="colsTitle">
            <div class="card-head"><div><h2 class="card-title" id="colsTitle">The columns</h2><p class="card-sub">Required in bold. Other names are understood too (Share Capital Account, Term ...).</p></div></div>
            <div class="card-body">
                <dl class="imp-cols">
                    <?php foreach ($cols as $k => $c): ?>
                        <div><dt><?= !empty($c['req']) ? '<strong>' . e($k) . '</strong>' : e($k) ?></dt><dd><?= e($c['label']) ?><?= !empty($c['hint']) ? '<span>' . e($c['hint']) . '</span>' : '' ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </div>
        </section>
    </div>

    <section class="card" id="irReview" hidden aria-labelledby="reviewTitle">
        <div class="card-head">
            <div class="ir-file">
                <span class="ir-file-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                <div><h2 class="card-title" id="reviewTitle"><span id="irFileName">The file</span></h2>
                    <p>Correct a cell by typing in it (the line is checked again as you type), remove a member with &times;. Post billing puts every line on fixed billing, or none while a line in red still has a problem.</p></div>
            </div>
            <button type="button" class="btn btn-ghost" id="irRestart">Start over</button>
        </div>
        <div class="ir-bar">
            <div class="ir-counts">
                <span><strong id="irN">0</strong> <span>members</span></span>
                <span class="is-bad" id="irBadWrap" hidden><strong id="irBad">0</strong> with a problem</span>
                <span>A posting <strong id="irTotal">&#8369;0.00</strong></span>
                <span id="irRemovedWrap" hidden><strong id="irRemovedN">0</strong> removed <button type="button" class="btn-link" id="irUndo">Put back the last</button></span>
                <span class="ir-checking" id="irChecking" hidden>Checking</span>
            </div>
            <div class="ir-tools">
                <input type="search" class="search" id="irFind" placeholder="Find in the lines" aria-label="Find in the lines">
                <label class="check-line"><input type="checkbox" id="irBadOnly"> Only lines with a problem</label>
                <button type="button" class="btn ir-danger" id="irRemoveBad" hidden>Remove the lines with a problem</button>
            </div>
        </div>
        <div class="alert alert-error ir-general" id="irGeneral" hidden></div>
        <div class="ir-scroll">
            <table class="ir-table" id="irTable"><thead></thead><tbody class="ir-none" id="irNone" hidden><tr><td colspan="20">No line matches.</td></tr></tbody></table>
        </div>
        <div class="ir-actions">
            <div id="irStatus"></div>
            <div class="ir-actions-btns">
                <button type="button" class="btn btn-ghost" id="irRecheck">Check again</button>
                <button type="button" class="btn btn-primary" id="irPost" disabled>Post billing for <span id="irPostN">0 members</span></button>
            </div>
        </div>
    </section>
    <?php endif; ?>
</main>
</div>
<?php if ($ready): ?>
<script>
window.IMPORT_REVIEW = {
    endpoint: '../process/shareCapitalImport.php',
    csrf: <?= json_encode(csrfToken()) ?>,
    token: <?= json_encode($token) ?>,
    cols: <?= json_encode($colsJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    noun: ['member', 'members'],
    total: 'amount',
    defaults: '#irDefaults',
    canPost: <?= $canCreate ? 'true' : 'false' ?>,
    confirm: 'Post the fixed share capital billing of {n}? Each is put on fixed billing with its amount; the posting days already past are posted at once.',
    confirmTitle: 'Post billing',
    confirmOk: 'Post billing'
};
</script>
<script src="../common/import-review.js?v=<?= (int)@filemtime(__DIR__ . '/../common/import-review.js') ?>"></script>
<?php endif; ?>
</body>
</html>
