<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

/**
 * views/reading_entry.php
 * Bill Reading Wizard — 3-step UI: Period → Mode → Entry.
 * Individual: full member profile + live bill preview.
 * Batch: street/zone bulk entry table.
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/memberAddressHelpers.php';
$pdo = getDB();

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$dataLoadErrors = [];
try {
    $stmt = $pdo->query("SELECT * FROM bill_periods WHERE status = 'open' ORDER BY start_date DESC");
    $openPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    $openPeriods = [];
    $dataLoadErrors[] = 'Open billing periods could not be loaded.';
    error_log('Reading-entry periods: ' . $ex->getMessage());
}

$streetList = []; $zoneList = [];
try {
    $primaryAddressTypeId = member_primary_address_type_id($pdo);
    $stmt = $pdo->prepare("
        SELECT DISTINCT a.street
        FROM   addresses      a
        JOIN   memberaddress  ma ON ma.address_key      = a.pkey
                                AND ma.address_type_key = ?
                                AND ma.status           = 'A'
        JOIN   water_accounts        m  ON m.pkey = ma.member_key
                                AND m.status = 'A'
        WHERE  a.street IS NOT NULL AND a.street <> ''
        ORDER  BY a.street
    ");
    $stmt->execute([$primaryAddressTypeId]);
    $streetList = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->query("SELECT DISTINCT zone FROM water_accounts WHERE status='A' AND zone IS NOT NULL ORDER BY zone");
    $zoneList = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $ex) {
    $dataLoadErrors[] = 'Street and zone filters could not be loaded.';
    error_log('Reading-entry filters: ' . $ex->getMessage());
}

$flash = flash();
// Edit / Delete of the unposted readings on Recorded Readings (version 46): a reading can be changed by whoever may
// enter readings, deleted by whoever may enter readings and has the Delete right of Reading Entry (the server asks for
// both: water/process/deleteReading.php); a posted reading is never changed here
$draftCanEnter = Access::can('water.reading_entry', 'create') || Access::can('water.reading_entry', 'edit');
$draftRights = ['edit' => $draftCanEnter, 'delete' => $draftCanEnter && Access::can('water.reading_entry', 'delete')];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
<title>Bill Readings — Water Billing</title>
<style>
/* ─── RESET & TOKENS ───────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --white: #ffffff;
    --off-white: #f6f7f9;
    --border: #e5e7eb;
    --border-focus: #1a1a2e;
    --text-primary: #0f0f1a;
    --text-secondary: #6b7280;
    --text-muted: #9ca3af;
    --accent: #1a1a2e;
    --accent-hover: #0f0f1a;
    --accent-light: #eef0f8;
    --success: #059669;
    --success-bg: #ecfdf5;
    --success-border: #a7f3d0;
    --error: #dc2626;
    --error-bg: #fef2f2;
    --error-border: #fecaca;
    --warning: #d97706;
    --warning-bg: #fffbeb;
    --warning-border: #fde68a;
    --info: #2563eb;
    --info-bg: #eff6ff;
    --info-border: #bfdbfe;
    --radius-sm: 8px;
    --radius: 14px;
    --radius-lg: 18px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow: 0 4px 16px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
    --shadow-lg: 0 10px 40px rgba(0,0,0,.12), 0 2px 8px rgba(0,0,0,.06);
}

html { height: 100%; font-size: 16px; }
body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); min-height: 100vh; -webkit-font-smoothing: antialiased; }
.page { padding: 32px 36px; max-width: 1360px; margin: 0 auto; }

@keyframes fadeUp   { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
@keyframes fadeIn   { from { opacity: 0; } to { opacity: 1; } }
@keyframes scaleIn  { from { opacity: 0; transform: scale(.95); } to { opacity: 1; transform: scale(1); } }

/* ─── PAGE HEADER ──────────────────────────────────────────────────── */
.page-header { margin-bottom: 28px; }
.page-title   { font-size: 1.5rem; font-weight: 700; letter-spacing: -.035em; display: flex; align-items: center; gap: 10px; }
.page-subtitle { font-size: .8375rem; color: var(--text-secondary); margin-top: 5px; font-weight: 400; }

/* ─── ALERT ────────────────────────────────────────────────────────── */
.alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: var(--radius-sm); font-size: .8125rem; margin-bottom: 20px; border: 1px solid transparent; animation: fadeUp .3s ease; }
.alert-success { background: var(--success-bg); color: var(--success); border-color: var(--success-border); }
.alert-error   { background: var(--error-bg);   color: var(--error);   border-color: var(--error-border); }
.alert-warning { background: var(--warning-bg); color: var(--warning); border-color: var(--warning-border); }
.alert-info    { background: var(--info-bg);    color: var(--info);    border-color: var(--info-border); }

/* ─── STEPPER ──────────────────────────────────────────────────────── */
.stepper { display: flex; align-items: center; margin-bottom: 32px; }
.step { display: flex; align-items: center; gap: 10px; flex: 1; }
.step:last-child { flex: 0; }
.step-circle {
    width: 34px; height: 34px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 700;
    background: var(--off-white); border: 2px solid var(--border);
    color: var(--text-muted); transition: all .25s; flex-shrink: 0;
}
.step-circle.active   { background: var(--accent); border-color: var(--accent); color: #fff; }
.step-circle.done     { background: var(--success); border-color: var(--success); color: #fff; }
.step-label { font-size: .78rem; font-weight: 600; color: var(--text-muted); transition: color .25s; white-space: nowrap; }
.step.active .step-label { color: var(--text-primary); }
.step.done   .step-label { color: var(--success); }
.step-line { flex: 1; height: 2px; background: var(--border); margin: 0 12px; border-radius: 2px; transition: background .3s; }
.step-line.done { background: var(--success); }

/* ─── STEP PANELS ──────────────────────────────────────────────────── */
.step-panel { display: none; animation: fadeUp .35s ease; }
.step-panel.active { display: block; }

/* ─── CARDS ────────────────────────────────────────────────────────── */
.card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 20px; }
.card.no-mb { margin-bottom: 0; }
.card-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid var(--border); gap: 12px; border-radius: var(--radius) var(--radius) 0 0; }
.card-head-left { display: flex; align-items: center; gap: 12px; }
.card-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.card-icon svg { width: 17px; height: 17px; }
.card-icon.blue   { background: var(--info-bg);    color: var(--info); }
.card-icon.green  { background: var(--success-bg); color: var(--success); }
.card-icon.amber  { background: var(--warning-bg); color: var(--warning); }
.card-icon.purple { background: #f3e8ff; color: #7c3aed; }
.card-icon.slate  { background: var(--off-white);  color: var(--text-secondary); }
.card-title { font-size: .9375rem; font-weight: 700; }
.card-sub   { font-size: .75rem; color: var(--text-muted); margin-top: 2px; }
.card-body  { padding: 22px; }

/* ─── FORM ELEMENTS ────────────────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 5px; }
label.field-label { font-size: .75rem; font-weight: 600; color: var(--text-secondary); }
.form-control {
    width: 100%; padding: 9px 12px;
    font-family: 'Sora', sans-serif; font-size: .8375rem;
    border: 1.5px solid var(--border); border-radius: var(--radius-sm);
    outline: none; transition: border-color .2s; background: var(--white); color: var(--text-primary);
}
.form-control:focus   { border-color: var(--border-focus); }
.form-control:disabled, .form-control[readonly] { background: var(--off-white); cursor: not-allowed; color: var(--text-secondary); }
.input-wrap { position: relative; display: flex; align-items: center; }
.input-suffix { position: absolute; right: 12px; font-size: .78rem; font-weight: 600; color: var(--text-muted); pointer-events: none; font-family: 'JetBrains Mono', monospace; }
.input-wrap .form-control { padding-right: 38px; }
select.form-control { cursor: pointer; }

/* ─── BUTTONS ──────────────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 18px; font-family: 'Sora', sans-serif; font-size: .8125rem; font-weight: 600; border-radius: var(--radius-sm); border: none; cursor: pointer; transition: all .15s; }
.btn svg { width: 15px; height: 15px; flex-shrink: 0; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(26,26,46,.22); }
.btn-primary:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
.btn-ghost { background: var(--white); color: var(--text-secondary); border: 1.5px solid var(--border); }
.btn-ghost:hover { border-color: var(--border-focus); color: var(--text-primary); }
.btn-success { background: var(--success); color: #fff; }
.btn-success:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(5,150,105,.25); }
.btn-sm { padding: 7px 14px; font-size: .78rem; }
.btn-lg { padding: 12px 28px; font-size: .875rem; }

/* ─── TOGGLE ───────────────────────────────────────────────────────── */
.toggle-wrap { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.toggle { width: 36px; height: 20px; border-radius: 20px; background: var(--border); border: none; cursor: pointer; position: relative; transition: background .2s; appearance: none; flex-shrink: 0; }
.toggle::after { content: ''; position: absolute; top: 3px; left: 3px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.toggle:checked { background: var(--accent); }
.toggle:checked::after { transform: translateX(16px); }
.toggle-label { font-size: .8rem; font-weight: 500; color: var(--text-secondary); }

/* ─── BADGES ───────────────────────────────────────────────────────── */
.badge { display: inline-flex; align-items: center; gap: 4px; font-size: .67rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; padding: 2px 8px; border-radius: 4px; white-space: nowrap; }
.badge-done    { background: var(--success-bg); color: var(--success); }
.badge-billed  { background: var(--info-bg);    color: var(--info); }
.badge-pending { background: var(--warning-bg); color: var(--warning); }
.badge-gray    { background: var(--off-white);  color: var(--text-muted); border: 1px solid var(--border); }
.badge-disc    { background: #fef3c7; color: #b45309; }
.badge-new     { background: #f3e8ff; color: #7c3aed; }

/* ─── STEP 1 — PERIOD ──────────────────────────────────────────────── */
.period-card-wrap { max-width: 600px; margin: 0 auto; }
.period-card { background: var(--white); border: 1.5px solid var(--border); border-radius: var(--radius-lg); padding: 32px; box-shadow: var(--shadow); text-align: center; }
.period-card-icon { width: 56px; height: 56px; background: var(--accent-light); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; }
.period-card-icon svg { width: 26px; height: 26px; color: var(--accent); }
.period-card-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 6px; }
.period-card-sub { font-size: .82rem; color: var(--text-secondary); margin-bottom: 24px; }
.period-select-row { display: flex; gap: 10px; align-items: stretch; }
.period-select-row .form-control { flex: 1; text-align: left; }
.period-info-box { display: none; margin-top: 16px; padding: 14px; background: var(--success-bg); border: 1px solid var(--success-border); border-radius: var(--radius-sm); font-size: .82rem; color: var(--success); text-align: left; }
.period-info-box.show { display: flex; align-items: center; gap: 8px; }
.period-print-actions { display:none; margin-top:12px; gap:10px; justify-content:center; flex-wrap:wrap; }
.period-print-actions.show { display:flex; }
.period-print-note { font-size:.72rem; color:var(--text-secondary); line-height:1.45; text-align:center; margin-top:6px; }

/* ─── STEP 2 — MODE ────────────────────────────────────────────────── */
.mode-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 720px; margin: 0 auto; }
.mode-card {
    background: var(--white); border: 2px solid var(--border);
    border-radius: var(--radius-lg); padding: 32px 24px;
    cursor: pointer; text-align: center; transition: all .2s;
    position: relative;
}
.mode-card:hover { border-color: var(--accent); box-shadow: var(--shadow); transform: translateY(-2px); }
.mode-card.selected { border-color: var(--accent); background: var(--accent-light); box-shadow: var(--shadow); }
.mode-card-icon { width: 64px; height: 64px; border-radius: 18px; display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; }
.mode-card-icon svg { width: 28px; height: 28px; }
.mode-card-icon.indiv { background: var(--info-bg); color: var(--info); }
.mode-card-icon.batch { background: var(--warning-bg); color: var(--warning); }
.mode-card-title { font-size: 1.0625rem; font-weight: 700; margin-bottom: 8px; }
.mode-card-desc  { font-size: .8125rem; color: var(--text-secondary); line-height: 1.55; }
.mode-card-check { position: absolute; top: 14px; right: 14px; width: 22px; height: 22px; border-radius: 50%; background: var(--success); display: none; align-items: center; justify-content: center; }
.mode-card-check svg { width: 12px; height: 12px; color: #fff; }
.mode-card.selected .mode-card-check { display: flex; }

/* ─── INDIVIDUAL — LAYOUT ──────────────────────────────────────────── */
.ind-layout { display: grid; grid-template-columns: 1fr 360px; gap: 22px; align-items: start; }

/* ─── MEMBER SEARCH ────────────────────────────────────────────────── */
.search-wrap { position: relative; }
.search-wrap .form-control { padding-right: 40px; }
.search-spinner { display: none; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 1; }
.search-dropdown {
    position: fixed;
    background: var(--white);
    border: 1.5px solid var(--border-focus);
    border-radius: var(--radius-sm);
    box-shadow: var(--shadow-lg);
    z-index: 9999;
    max-height: 320px; overflow-y: auto;
    display: none;
    min-width: 280px;
}
.search-dropdown.open { display: block; animation: fadeIn .15s ease; }
.search-result { display: flex; align-items: center; gap: 12px; padding: 11px 15px; cursor: pointer; border-bottom: 1px solid var(--border); transition: background .1s; }
.search-result:last-child { border-bottom: none; }
.search-result:hover { background: var(--off-white); }
.search-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; flex-shrink: 0; }
.search-name { font-size: .8375rem; font-weight: 600; }
.search-meta { font-size: .73rem; color: var(--text-muted); margin-top: 2px; }
.search-badges { display: flex; gap: 4px; margin-top: 4px; }
.search-empty { padding: 20px; text-align: center; font-size: .82rem; color: var(--text-muted); }

/* ─── MEMBER PROFILE CARD ──────────────────────────────────────────── */
.member-profile { display: none; animation: scaleIn .25s ease; }
.member-profile-header {
    display: flex; align-items: center; gap: 16px;
    padding: 18px 22px; background: var(--accent);
    border-radius: var(--radius) var(--radius) 0 0; color: #fff;
    position: relative;
}
.member-profile-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    background: rgba(255,255,255,.15); border: 2px solid rgba(255,255,255,.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; font-weight: 700; flex-shrink: 0;
}
.member-profile-name { font-size: 1rem; font-weight: 700; letter-spacing: -.02em; }
.member-profile-acct { font-size: .78rem; color: rgba(255,255,255,.65); margin-top: 3px; font-family: 'JetBrains Mono', monospace; }
.member-profile-close {
    position: absolute; top: 14px; right: 14px;
    width: 28px; height: 28px; border-radius: 50%;
    background: rgba(255,255,255,.12); border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center; color: #fff;
    transition: background .15s;
}
.member-profile-close:hover { background: rgba(255,255,255,.25); }
.member-profile-close svg { width: 14px; height: 14px; }
.member-profile-body { padding: 18px 22px; border: 1px solid var(--border); border-top: none; border-radius: 0 0 var(--radius) var(--radius); background: var(--white); }

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.info-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.info-cell { display: flex; flex-direction: column; gap: 2px; background: var(--off-white); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 13px; }
.info-cell.full-width { grid-column: 1 / -1; }
.info-cell-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); }
.info-cell-value { font-size: .8375rem; font-weight: 600; color: var(--text-primary); }
.info-cell-value.mono { font-family: 'JetBrains Mono', monospace; }
.info-cell-value.success { color: var(--success); }
.info-cell-value.warning { color: var(--warning); }

.divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }

.reading-status-row { display: flex; align-items: center; justify-content: space-between; background: var(--off-white); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 14px; }
.reading-status-label { font-size: .75rem; font-weight: 600; color: var(--text-secondary); }

/* ─── READING TRIO ─────────────────────────────────────────────────── */
.reading-trio { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 18px; }
.reading-block { padding: 14px 16px; border-radius: var(--radius-sm); border: 1.5px solid var(--border); background: var(--white); text-align: center; }
.reading-block.active-block  { border-color: var(--border-focus); background: var(--accent-light); }
.reading-block.cons-block    { border-color: var(--info-border);  background: var(--info-bg); }
.rb-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 5px; }
.rb-value { font-size: 1.375rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
.reading-block.active-block .rb-value { color: var(--accent); }
.reading-block.cons-block    .rb-value { color: var(--info); }
.rb-unit { font-size: .68rem; color: var(--text-muted); margin-top: 2px; }

/* ─── BILL PREVIEW ─────────────────────────────────────────────────── */
.preview-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid var(--border); font-size: .8125rem; }
.preview-row:last-of-type { border-bottom: none; }
.preview-label { color: var(--text-secondary); font-weight: 500; }
.preview-value { font-weight: 600; font-family: 'JetBrains Mono', monospace; }
.preview-total { display: flex; justify-content: space-between; align-items: center; padding: 14px 0 0; border-top: 2px solid var(--border); margin-top: 6px; }
.preview-total-label { font-size: .875rem; font-weight: 700; }
.preview-total-value { font-size: 1.25rem; font-weight: 700; color: var(--accent); font-family: 'JetBrains Mono', monospace; }
.preview-empty { text-align: center; padding: 36px 20px; color: var(--text-muted); font-size: .82rem; }
.preview-empty svg { width: 36px; height: 36px; color: var(--border); display: block; margin: 0 auto 10px; }

/* ─── FORM ACTIONS ─────────────────────────────────────────────────── */
.form-actions { display: flex; justify-content: space-between; align-items: center; padding: 16px 22px; background: var(--off-white); border-top: 1px solid var(--border); border-radius: 0 0 var(--radius) var(--radius); }
.batch-save-floating {
    position: fixed;
    right: 28px;
    bottom: 24px;
    z-index: 1200;
    min-height: 46px;
    padding: 12px 18px;
    border-radius: 10px;
    box-shadow: 0 10px 28px rgba(17, 24, 39, .24);
}
.batch-save-floating:hover { transform: translateY(-1px); }
@media (max-width: 720px) {
    .batch-save-floating { right: 14px; bottom: 14px; max-width: calc(100vw - 28px); }
}
.step-nav { display: flex; justify-content: flex-end; margin-top: 24px; gap: 10px; }
.step-nav.spaced { justify-content: space-between; }

/* ─── SUCCESS STATE ────────────────────────────────────────────────── */
.success-banner { display: none; background: var(--success-bg); border: 1.5px solid var(--success-border); border-radius: var(--radius); padding: 20px 22px; margin-bottom: 20px; animation: fadeUp .3s ease; }
.success-banner.show { display: flex; align-items: flex-start; gap: 14px; }
.success-banner-icon { width: 40px; height: 40px; background: var(--success); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.success-banner-icon svg { width: 20px; height: 20px; color: #fff; }
.success-banner-title { font-size: .9rem; font-weight: 700; color: var(--success); }
.success-banner-sub   { font-size: .82rem; color: var(--text-secondary); margin-top: 3px; }
.success-summary      { margin-top: 12px; display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; }
.success-stat { background: var(--white); border: 1px solid var(--success-border); border-radius: var(--radius-sm); padding: 10px 14px; }
.success-stat-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); }
.success-stat-value { font-size: 1rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; color: var(--accent); margin-top: 3px; }

/* ─── OVERRIDE WARNING ─────────────────────────────────────────────── */
.override-box { background: var(--warning-bg); border: 1px solid var(--warning-border); border-radius: var(--radius-sm); padding: 12px 16px; font-size: .8rem; color: var(--warning); margin-top: 14px; }
.override-box strong { font-weight: 700; }

/* ─── BATCH STYLES ─────────────────────────────────────────────────── */
.batch-toolbar { display: flex; align-items: flex-end; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.batch-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }
table.batch-table { width: 100%; border-collapse: collapse; font-size: .8125rem; min-width: 840px; }
table.batch-table th { text-align: left; padding: 10px 14px; font-size: .7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; border-bottom: 2px solid var(--border); background: var(--off-white); white-space: nowrap; letter-spacing: .05em; }
table.batch-table td { padding: 9px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
table.batch-table tbody tr:last-child td { border-bottom: none; }
table.batch-table tbody tr:hover td { background: var(--off-white); }
table.batch-table tr.row-billed td { background: #f0fdf4; }
table.batch-table tr.row-billed { border-left: 3px solid var(--success); }
.batch-reading-input { width: 110px; padding: 7px 10px; font-family: 'JetBrains Mono', monospace; font-size: .8125rem; text-align: right; border: 1.5px solid var(--border); border-radius: var(--radius-sm); outline: none; background: var(--white); }
.batch-reading-input:focus { border-color: var(--border-focus); }
.batch-reading-input[readonly] { background: var(--off-white); color: var(--text-muted); }
.batch-reading-input.changed { border-color: var(--info); background: var(--info-bg); color: var(--info); }
.batch-reading-input.error   { border-color: var(--error); background: var(--error-bg); }
.batch-empty { text-align: center; padding: 52px 24px; color: var(--text-muted); }
.batch-empty svg { width: 40px; height: 40px; color: var(--border); margin: 0 auto 10px; display: block; }
.batch-stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.batch-stat { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 14px; display: flex; align-items: center; gap: 8px; font-size: .78rem; }
.batch-stat-num { font-size: 1rem; font-weight: 700; font-family: 'JetBrains Mono', monospace; }
.member-name-cell strong { font-weight: 600; font-size: .82rem; }
.member-name-cell small { font-size: .7rem; color: var(--text-muted); display: block; margin-top: 1px; }

/* ─── BATCH DETAIL ROW (bill summary under each member) ─────────────── */
table.batch-table tr.batch-detail-row td {
    padding: 3px 14px 10px;
    background: var(--off-white);
    border-bottom: 2px solid var(--border);
}
table.batch-table tr.batch-detail-row.row-billed td {
    background: #f0fdf4;
}
table.batch-table tbody tr.batch-detail-row:hover td { background: var(--off-white); }
table.batch-table tbody tr.batch-detail-row.row-billed:hover td { background: #f0fdf4; }
.batch-bill-details {
    display: flex;
    gap: 24px;
    flex-wrap: wrap;
    padding: 6px 4px 2px;
}
.bd-item {
    display: flex;
    align-items: baseline;
    gap: 5px;
    font-size: .73rem;
}
.bd-label {
    color: var(--text-muted);
    font-weight: 600;
    white-space: nowrap;
}
.bd-value {
    font-family: 'JetBrains Mono', monospace;
    font-weight: 700;
    color: var(--text-primary);
}
.bd-value.empty { color: var(--text-muted); font-weight: 400; }
.bd-value.due   { color: var(--accent); font-size: .82rem; }
.bd-value.cons  { color: var(--info); }
.bd-value.bill  { color: var(--text-secondary); }

/* ─── RECORDED BILLS TABLE ─────────────────────────────────────────── */
.recorded-bills-section { margin-top: 36px; }
.recorded-bills-section .section-title {
    font-size: .95rem; font-weight: 700; margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px; color: var(--text-primary);
}
.recorded-bills-section .section-title svg { width: 18px; height: 18px; color: var(--info); }
table.bills-table { width: 100%; border-collapse: collapse; font-size: .8125rem; min-width: 1040px; }
table.bills-table th {
    text-align: left; padding: 10px 14px; font-size: .7rem; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em;
    border-bottom: 2px solid var(--border); background: var(--off-white); white-space: nowrap;
}
table.bills-table td { padding: 10px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
table.bills-table tbody tr:last-child td { border-bottom: none; }
table.bills-table tbody tr:hover td { background: #fafafa; }
table.bills-table tr.billed-row td { background: #f0fdf4; }
.bills-empty { text-align: center; padding: 40px; color: var(--text-muted); font-size: .82rem; }
.bills-empty svg { width: 36px; height: 36px; color: var(--border); display: block; margin: 0 auto 10px; }
#billsTableWrap, #billsBatchTableWrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.bills-header-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
.bills-summary-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.bills-chip { font-size: .73rem; font-weight: 600; padding: 4px 12px; border-radius: 20px; border: 1px solid var(--border); background: var(--white); display: flex; align-items: center; gap: 5px; }

/* ─── EDIT / DELETE OF AN UNPOSTED READING ─────────────────────────── */
.rd-acts { display: flex; gap: 6px; justify-content: flex-end; white-space: nowrap; }
.rd-act { padding: 5px 10px; font-size: .74rem; font-weight: 600; border-radius: var(--radius-sm); border: 1.5px solid var(--border); background: var(--white); color: var(--text-primary); cursor: pointer; font-family: 'Sora', sans-serif; }
.rd-act:hover { border-color: var(--border-focus); }
.rd-act.is-del { color: var(--error); border-color: var(--error-border); }
.rd-act.is-del:hover { background: var(--error-bg); }
.rd-lock { font-size: .72rem; color: var(--text-muted); white-space: nowrap; }
.rd-back { position: fixed; inset: 0; z-index: 1200; display: none; align-items: flex-start; justify-content: center; padding: 7vh 14px 14px; background: rgba(15,23,42,.55); overflow: auto; }
.rd-back.open { display: flex; }
.rd-modal { width: min(560px, 100%); background: var(--white); border-radius: 12px; box-shadow: 0 24px 60px rgba(15,23,42,.28); overflow: hidden; }
.rd-head { display: flex; align-items: center; gap: 10px; padding: 16px 20px; border-bottom: 1px solid var(--border); }
.rd-head h2 { flex: 1; margin: 0; font-size: 1rem; font-weight: 700; }
.rd-x { border: 0; background: transparent; font-size: 1.4rem; line-height: 1; color: var(--text-muted); cursor: pointer; padding: 2px 8px; border-radius: 6px; }
.rd-x:hover { background: var(--off-white); color: var(--text-primary); }
.rd-body { padding: 18px 20px; }
.rd-who { font-weight: 700; font-size: .92rem; }
.rd-meta { color: var(--text-secondary); font-size: .76rem; margin-top: 2px; }
.rd-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 16px; }
.rd-grid label { display: block; font-size: .7rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
.rd-grid .form-control[readonly] { background: var(--off-white); }
.rd-due { margin-top: 14px; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--off-white); display: flex; justify-content: space-between; align-items: center; gap: 10px; font-size: .84rem; }
.rd-due strong { font-family: 'JetBrains Mono', monospace; font-size: 1rem; white-space: nowrap; }
.rd-err { display: none; margin-top: 12px; padding: 10px 12px; border-radius: var(--radius-sm); background: var(--error-bg); color: var(--error); border: 1px solid var(--error-border); font-size: .8rem; font-weight: 600; }
.rd-note { margin-top: 12px; font-size: .76rem; color: var(--text-secondary); line-height: 1.5; }
.rd-reason { width: 100%; min-height: 76px; margin-top: 12px; resize: vertical; }
.rd-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--border); background: var(--off-white); }
.rd-modal [hidden] { display: none !important; }
.btn-danger { background: var(--error); color: #fff; }
.btn-danger:hover { filter: brightness(.92); }
.btn-danger:disabled { opacity: .5; cursor: not-allowed; }
@media (max-width: 560px) { .rd-grid { grid-template-columns: 1fr 1fr; } .rd-grid > div:last-child { grid-column: 1 / -1; } .rd-foot { flex-wrap: wrap; } .rd-foot .btn { flex: 1; } }

/* ─── SPINNER ──────────────────────────────────────────────────────── */
.spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid var(--border); border-top-color: var(--accent); border-radius: 50%; animation: spin .6s linear infinite; }
.spinner.lg { width: 28px; height: 28px; border-width: 3px; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ─── RESPONSIVE ───────────────────────────────────────────────────── */
@media (max-width: 1060px) { .ind-layout { grid-template-columns: 1fr; } }
@media (max-width: 768px)  {
    .page { padding: 16px; }
    .mode-grid { grid-template-columns: 1fr; }
    .reading-trio { grid-template-columns: 1fr; }
    .form-grid, .form-grid-3, .info-grid, .info-grid-3 { grid-template-columns: 1fr; }
    .success-summary { grid-template-columns: 1fr 1fr; }
    .batch-bill-details { gap: 14px; }
}
</style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<div class="page">

    <div class="page-header">
        <a href="billing_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:600; margin-bottom:4px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Billing Management
        </a>
        <div class="page-title">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/><polyline points="16.5 9.4 7.55 4.24"/><line x1="3.29" y1="7" x2="12" y2="12"/><line x1="12" y1="22" x2="12" y2="12"/><circle cx="18.5" cy="15.5" r="2.5"/><path d="M20.27 17.27 22 19"/></svg>
            Bill Readings
        </div>
        <div class="page-subtitle">Enter water meter readings for individual members or an entire street / zone at once.</div>
    </div>

    <div id="alertContainer"></div>
    <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($dataLoadErrors): ?>
    <div class="alert alert-error">
        <?= e(implode(' ', $dataLoadErrors)) ?> This is a database error, not an empty reading list.
    </div>
    <?php endif; ?>

    <div class="stepper" id="stepperBar">
        <div class="step active" id="stepDot1">
            <div class="step-circle active" id="stepCircle1">1</div>
            <div class="step-label">Reading Period</div>
        </div>
        <div class="step-line" id="stepLine12"></div>
        <div class="step active" id="stepDot2">
            <div class="step-circle" id="stepCircle2">2</div>
            <div class="step-label">Entry Mode</div>
        </div>
        <div class="step-line" id="stepLine23"></div>
        <div class="step" id="stepDot3">
            <div class="step-circle" id="stepCircle3">3</div>
            <div class="step-label">Read &amp; Save</div>
        </div>
    </div>

    <div class="step-panel active" id="panel1">
        <div class="period-card-wrap">
            <div class="period-card">
                <div class="period-card-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                </div>
                <div class="period-card-title">Select a Reading Period</div>
                <div class="period-card-sub">Only open periods are available for reading entry. Choose the period you are encoding for.</div>

                <?php if (empty($openPeriods)): ?>
                <div class="alert alert-warning" style="text-align:left; margin-bottom:16px;">
                    No open reading periods found. Please create or open a reading period first.
                </div>
                <?php else: ?>
                <div class="period-select-row">
                    <select id="periodSelect" class="form-control">
                        <option value="">— Select an open reading period —</option>
                        <?php foreach ($openPeriods as $p): ?>
                        <option value="<?= $p['period_id'] ?>"
                            data-code="<?= e($p['bp_code']) ?>"
                            data-start="<?= e($p['start_date']) ?>"
                            data-end="<?= e($p['end_date']) ?>">
                            <?= e($p['bp_code']) ?> &nbsp;(<?= date('M j', strtotime($p['start_date'])) ?> – <?= date('M j, Y', strtotime($p['end_date'])) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="period-info-box" id="periodInfoBox">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="periodInfoText">Period loaded.</span>
                </div>

                <div class="period-print-actions" id="periodPrintActions">
                    <button type="button" class="btn btn-ghost" id="btnPrintMeterList">
                        Print Meter Reading List
                    </button>
                </div>
                <div class="period-print-note" id="periodPrintNote" style="display:none;">
                    Use this for field readers before encoding meter readings.
                </div>
                <?php endif; ?>

                <div class="step-nav" style="margin-top:20px; justify-content:center;">
                    <button class="btn btn-primary btn-lg" id="btnStep1Next" <?= empty($openPeriods) ? 'disabled' : '' ?>>
                        Continue
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="step-panel" id="panel2">
        <div style="text-align:center; margin-bottom:24px;">
            <div style="font-size:1rem; font-weight:700; margin-bottom:6px;">How would you like to enter readings?</div>
            <div style="font-size:.8375rem; color:var(--text-secondary);">Choose the entry mode. You can switch between modes after this step.</div>
        </div>
        <div class="mode-grid">
            <div class="mode-card" id="modeCardInd" onclick="selectMode('individual')">
                <div class="mode-card-check"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div class="mode-card-icon indiv">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div class="mode-card-title">Individual Reading</div>
                <div class="mode-card-desc">Search and encode readings one member at a time. View full member profile, discount info, and live bill preview before saving.</div>
            </div>
            <div class="mode-card" id="modeCardBatch" onclick="selectMode('batch')">
                <div class="mode-card-check"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div class="mode-card-icon batch">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </div>
                <div class="mode-card-title">Batch Reading</div>
                <div class="mode-card-desc">Load all members from a street or zone into a table and encode all their readings at once — faster for field encoding.</div>
            </div>
        </div>
        <div class="step-nav spaced" style="max-width:720px; margin: 20px auto 0;">
            <button class="btn btn-ghost" id="btnStep2Back">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back
            </button>
            <button class="btn btn-primary" id="btnStep2Next" disabled>
                Continue
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </button>
        </div>
    </div>

    <div class="step-panel" id="panel3-individual">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
            <span style="font-size:.78rem; color:var(--text-secondary);">Period:</span>
            <span class="badge badge-done" id="periodChip3Ind">—</span>
            <span style="color:var(--border); font-size:.9rem;">|</span>
            <span style="font-size:.78rem; color:var(--text-secondary);">Mode:</span>
            <span class="badge badge-new">Individual</span>
            <button class="btn btn-ghost btn-sm" id="btnSwitchToBatch" style="margin-left:auto;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Switch to Batch
            </button>
            <button class="btn btn-ghost btn-sm" id="btnIndBack">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="13" height="13"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Change Period
            </button>
        </div>

        <div class="success-banner" id="indSuccessBanner">
            <div class="success-banner-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div style="flex:1;">
                <div class="success-banner-title">Reading Saved Successfully!</div>
                <div class="success-banner-sub" id="indSuccessSub">Reading has been recorded.</div>
                <div class="success-summary" id="indSuccessSummary"></div>
            </div>
        </div>

        <div class="ind-layout">
            <div>
                <div class="card">
                    <div class="card-head">
                        <div class="card-head-left">
                            <div class="card-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            </div>
                            <div>
                                <div class="card-title">Find Member</div>
                                <div class="card-sub">Search by name, account number, or address</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="search-wrap" id="searchWrap">
                            <input type="text" id="memberSearch" class="form-control" placeholder="Type member name or account number…" autocomplete="off" data-inc-list="#searchDropdown" data-inc-item=".search-result[data-action]">
                            <div class="search-spinner" id="searchSpinner">
                                <span class="spinner"></span>
                            </div>
                            <div class="search-dropdown" id="searchDropdown"></div>
                        </div>
                    </div>
                </div>

                <div class="member-profile" id="memberProfile">
                    <div class="member-profile-header">
                        <div class="member-profile-avatar" id="profileAvatar">?</div>
                        <div style="flex:1; min-width:0;">
                            <div class="member-profile-name" id="profileName">—</div>
                            <div class="member-profile-acct" id="profileAcct">Account # —</div>
                        </div>
                        <button class="member-profile-close" onclick="clearIndividualForm()" title="Clear selection">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="member-profile-body">
                        <div class="info-grid" style="margin-bottom:10px;">
                            <div class="info-cell full-width">
                                <span class="info-cell-label">Address</span>
                                <span class="info-cell-value" id="profileAddress">—</span>
                            </div>
                        </div>
                        <div class="info-grid-3" style="margin-bottom:10px;">
                            <div class="info-cell">
                                <span class="info-cell-label">Zone</span>
                                <span class="info-cell-value mono" id="profileZone">—</span>
                            </div>
                            <div class="info-cell">
                                <span class="info-cell-label">Rate Code / Pipe Size</span>
                                <span class="info-cell-value mono" id="profileRateCode">—</span>
                                <span class="info-cell-value" id="profilePipeSize" style="display:block;margin-top:3px;font-size:.72rem;color:var(--text-secondary);">—</span>
                            </div>
                            <div class="info-cell">
                                <span class="info-cell-label">Discount Type</span>
                                <span class="info-cell-value" id="profileDiscountName">None</span>
                            </div>
                        </div>
                        <div id="profileDiscountBox" style="display:none; margin-bottom:10px;">
                            <div class="info-grid-3">
                                <div class="info-cell">
                                    <span class="info-cell-label">Free Allowance</span>
                                    <span class="info-cell-value mono success" id="profileFreeM3">—</span>
                                </div>
                                <div class="info-cell">
                                    <span class="info-cell-label">Active Discount</span>
                                    <span class="info-cell-value" id="profileActiveDiscount">—</span>
                                </div>
                                <div class="info-cell">
                                    <span class="info-cell-label">Discount Rate</span>
                                    <span class="info-cell-value mono" id="profileDiscountRate">—</span>
                                </div>
                            </div>
                        </div>
                        <hr class="divider">
                        <div class="reading-status-row">
                            <span class="reading-status-label">Current Period Status</span>
                            <span id="profileReadingStatus"><span class="badge badge-gray">● Not Encoded</span></span>
                        </div>
                    </div>
                </div>

                <div class="card" id="readingEntryCard" style="display:none;">
                    <div class="card-head">
                        <div class="card-head-left">
                            <div class="card-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            </div>
                            <div>
                                <div class="card-title">Enter Meter Reading</div>
                                <div class="card-sub">Type in the present meter reading for this member</div>
                            </div>
                        </div>
                        <div id="billedBadge" style="display:none;"><span class="badge badge-billed">● Already Billed</span></div>
                    </div>
                    <div class="card-body">
                        <div class="reading-trio">
                            <div class="reading-block">
                                <div class="rb-label">Previous</div>
                                <div class="rb-value" id="dispPrev">—</div>
                                <div class="rb-unit">m³</div>
                            </div>
                            <div class="reading-block active-block">
                                <div class="rb-label">Present</div>
                                <div class="rb-value" id="dispPres">—</div>
                                <div class="rb-unit">m³</div>
                            </div>
                            <div class="reading-block cons-block">
                                <div class="rb-label">Consumed</div>
                                <div class="rb-value" id="dispCons">—</div>
                                <div class="rb-unit">m³</div>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="field-label">Previous Reading</label>
                                <div class="input-wrap">
                                    <input type="number" id="prevReading" class="form-control" readonly step="1">
                                    <span class="input-suffix">m³</span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="field-label">Present Reading <span style="color:var(--error)">*</span></label>
                                <div class="input-wrap">
                                    <input type="number" id="presReading" class="form-control" step="1" min="0" inputmode="numeric" placeholder="0">
                                    <span class="input-suffix">m³</span>
                                </div>
                            </div>
                        </div>

                        <div class="override-box" id="overrideBox" style="display:none;">
                            <strong id="existingReadingTitle">⚠ Draft Reading Already Recorded</strong>
                            <span id="existingReadingMessage"> — Enable draft editing to modify it before posting.</span>
                            <div style="margin-top:10px;" id="overrideToggleWrap">
                                <label class="toggle-wrap">
                                    <input type="checkbox" class="toggle" id="overrideToggle">
                                    <span class="toggle-label">Edit this unposted draft</span>
                                </label>
                            </div>
                        </div>
                        <div id="readingError" style="display:none; font-size:.78rem; color:var(--error); margin-top:10px; font-weight:500;"></div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-ghost btn-sm" onclick="clearIndividualForm()">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Clear
                        </button>
                        <button class="btn btn-primary" id="btnSaveInd">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><polyline points="20 6 9 17 4 12"/></svg>
                            Save Reading
                        </button>
                    </div>
                </div>
            </div>

            <div id="billPreviewCol">
                <div class="card" style="position:sticky; top:20px;">
                    <div class="card-head">
                        <div class="card-head-left">
                            <div class="card-icon purple">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            </div>
                            <div>
                                <div class="card-title">Bill Preview</div>
                                <div class="card-sub">Live computation</div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body" id="billPreviewBody">
                        <div class="preview-empty">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            Select a member and enter a present reading to see the bill preview.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="recorded-bills-section" id="recordedBillsInd">
            <div class="bills-header-row">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    Recorded Readings — <span id="billsIndPeriodLabel" style="color:var(--text-secondary); font-weight:500;">this period</span>
                </div>
                <button class="btn btn-ghost btn-sm" id="btnRefreshBillsInd">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Refresh
                </button>
            </div>
            <div class="bills-summary-chips" id="billsIndSummaryChips" style="margin-bottom:12px; display:none;"></div>
            <div id="billsTableWrap">
                <div class="bills-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Select a reading period above to view recorded readings.
                </div>
            </div>
        </div>
    </div>

    <div class="step-panel" id="panel3-batch">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
            <span style="font-size:.78rem; color:var(--text-secondary);">Period:</span>
            <span class="badge badge-done" id="periodChip3Batch">—</span>
            <span style="color:var(--border); font-size:.9rem;">|</span>
            <span style="font-size:.78rem; color:var(--text-secondary);">Mode:</span>
            <span class="badge badge-disc">Batch</span>
            <button class="btn btn-ghost btn-sm" id="btnSwitchToInd" style="margin-left:auto;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Switch to Individual
            </button>
            <button class="btn btn-ghost btn-sm" id="btnBatchBack">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="13" height="13"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Change Period
            </button>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-head-left">
                    <div class="card-icon amber">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    </div>
                    <div>
                        <div class="card-title">Batch Reading Entry</div>
                        <div class="card-sub">Filter members by street or zone, then fill in all readings</div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="batch-toolbar">
                    <div class="form-group" style="margin:0;">
                        <label class="field-label">Filter By</label>
                        <div style="display:flex; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--white); overflow:hidden;">
                            <label style="padding:8px 16px; cursor:pointer; font-size:.8rem; font-weight:600; display:flex; align-items:center; gap:6px;">
                                <input type="radio" name="batchFilter" value="street" id="filterStreet" checked> Street
                            </label>
                            <label style="padding:8px 16px; cursor:pointer; font-size:.8rem; font-weight:600; border-left:1.5px solid var(--border); display:flex; align-items:center; gap:6px;">
                                <input type="radio" name="batchFilter" value="zone" id="filterZone"> Zone
                            </label>
                        </div>
                    </div>
                    <div class="form-group" style="min-width:260px; margin:0;" id="streetWrap">
                        <label class="field-label">Street</label>
                        <select id="streetSelect" class="form-control">
                            <option value="">— Choose a street —</option>
                            <?php foreach ($streetList as $s): ?>
                            <option value="<?= e($s) ?>"><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="min-width:180px; margin:0; display:none;" id="zoneWrap">
                        <label class="field-label">Zone</label>
                        <select id="zoneSelect" class="form-control">
                            <option value="">— Choose a zone —</option>
                            <?php foreach ($zoneList as $z): ?>
                            <option value="<?= (int)$z ?>">Zone <?= (int)$z ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="min-width:210px; margin:0;">
                        <label class="field-label">Reading Status</label>
                        <select id="readingStatusFilter" class="form-control">
                            <option value="all">All Members</option>
                            <option value="pending">Not Yet Read</option>
                            <option value="done">Reading Done</option>
                        </select>
                    </div>
                    <div class="form-group" style="min-width:250px; margin:0;">
                        <label class="field-label">Search Loaded Members</label>
                        <input type="search" id="batchMemberSearch" class="form-control"
                               placeholder="Search member name, ID, or sequence…" disabled>
                    </div>
                    <div style="align-self:flex-end;">
                        <button class="btn btn-ghost" id="btnLoadBatch">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            Load Members
                        </button>
                    </div>
                </div>

                <div class="batch-stats" id="batchStats" style="display:none;">
                    <div class="batch-stat">
                        <span style="color:var(--text-muted); font-size:.75rem;">Total</span>
                        <span class="batch-stat-num" id="bstatTotal">0</span>
                    </div>
                    <div class="batch-stat">
                        <span style="color:var(--success); font-size:.75rem;">Encoded</span>
                        <span class="batch-stat-num" style="color:var(--success);" id="bstatEncoded">0</span>
                    </div>
                    <div class="batch-stat">
                        <span style="color:var(--warning); font-size:.75rem;">Pending</span>
                        <span class="batch-stat-num" style="color:var(--warning);" id="bstatPending">0</span>
                    </div>
                    <div class="batch-stat">
                        <span style="color:var(--info); font-size:.75rem;">Billed</span>
                        <span class="batch-stat-num" style="color:var(--info);" id="bstatBilled">0</span>
                    </div>
                    <div class="batch-stat">
                        <span style="color:var(--text-secondary); font-size:.75rem;">Showing</span>
                        <span class="batch-stat-num" id="bstatShowing">0</span>
                    </div>
                </div>

                <div class="batch-table-wrap" id="batchTableWrap">
                    <div class="batch-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        Choose a filter above and click <strong>Load Members</strong> to begin.
                    </div>
                </div>
            </div>
            <div class="form-actions" id="batchFormActions" style="display:none;">
                <button class="btn btn-ghost btn-sm" id="btnClearBatch">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Clear Inputs
                </button>
                <button class="btn btn-primary batch-save-floating" id="btnSaveBatch" aria-label="Save all loaded readings">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save All Readings
                </button>
            </div>
        </div>

        <div class="recorded-bills-section" id="recordedBillsBatch">
            <div class="bills-header-row">
                <div class="section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    Recorded Readings — <span id="billsBatchPeriodLabel" style="color:var(--text-secondary); font-weight:500;">this period</span>
                </div>
                <button class="btn btn-ghost btn-sm" id="btnRefreshBillsBatch">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="13" height="13"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Refresh
                </button>
            </div>
            <div class="bills-summary-chips" id="billsBatchSummaryChips" style="margin-bottom:12px; display:none;"></div>
            <div id="billsBatchTableWrap">
                <div class="bills-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Loading recorded readings…
                </div>
            </div>
        </div>
    </div>

</div></div>

<div class="rd-back" id="readingEditBack" aria-hidden="true">
    <div class="rd-modal" role="dialog" aria-modal="true" aria-labelledby="readingEditTitle">
        <div class="rd-head"><h2 id="readingEditTitle">Edit the reading</h2><button type="button" class="rd-x" data-rd-close aria-label="Close">&times;</button></div>
        <div class="rd-body">
            <div class="rd-who" id="rdWho">—</div>
            <div class="rd-meta" id="rdMeta">—</div>
            <div id="rdEditPane">
                <div class="rd-grid">
                    <div><label for="rdPrev">Previous (m³)</label><input class="form-control" id="rdPrev" type="number" readonly tabindex="-1"></div>
                    <div><label for="rdPres">Present (m³)</label><input class="form-control" id="rdPres" type="number" step="1" min="0" inputmode="numeric"></div>
                    <div><label for="rdCons">Consumed (m³)</label><input class="form-control" id="rdCons" type="text" readonly tabindex="-1"></div>
                </div>
                <div class="rd-due"><span>Amount due (computed again when saved)</span><strong id="rdDue">—</strong></div>
                <div class="rd-note">Only an unposted reading can be changed. The previous reading comes from the account's last reading before this period.</div>
            </div>
            <div id="rdDeletePane" hidden>
                <div class="rd-note" id="rdDeleteText">—</div>
                <label for="rdReason" style="display:block;margin-top:12px;font-size:.7rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;">Reason for deleting</label>
                <textarea class="form-control rd-reason" id="rdReason" maxlength="255" placeholder="e.g. Read the wrong meter; will be read again"></textarea>
            </div>
            <div class="rd-err" id="rdErr" role="alert"></div>
        </div>
        <div class="rd-foot">
            <button type="button" class="btn btn-ghost" data-rd-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="rdSave">Save changes</button>
            <button type="button" class="btn btn-danger" id="rdDelete" hidden>Delete reading</button>
        </div>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════════ */
let PERIOD_ID    = null;
let PERIOD_LABEL = '';
let ENTRY_MODE   = null;
let selectedMember = null;
let waterRates     = [];
let batchMembers   = [];
let searchTimer    = null;
let recordedReadings = new Map();
const READING_RIGHTS = <?= json_encode($draftRights) ?>;

const $ = id => document.getElementById(id);
const fmtPeso = n => '₱ ' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
const fmtM3   = n => Number(n).toFixed(2) + ' m³';

/* ─── Alerts ─────────────────────────────────────────────────────── */
function showAlert(type, msg, duration = 5000) {
    // Messages may originate from the server or from stored data: render as text.
    $('alertContainer').innerHTML = `<div class="alert alert-${escapeHtml(type)}">${escapeHtml(msg)}</div>`;
    if (duration > 0) setTimeout(() => { $('alertContainer').innerHTML = ''; }, duration);
}

/* ─── Load water rates once ──────────────────────────────────────── */
/* businessFetchJson (common/business-request.js) turns a login redirect or a
   non-JSON answer into a readable message with a link to the sign-in page
   instead of a JSON parse error (audit F08). */
businessFetchJson('../process/getWaterRates.php?context=reading_entry')
    .then(data => {
        if (!Array.isArray(data)) {
            throw new Error(data && data.error ? data.error : 'Water rates could not be loaded.');
        }
        waterRates = data;
    })
    .catch(error => showAlert('error', error.message || 'Water rates could not be loaded.', 0));

/* ═══════════════════════════════════════════════════════════════════
   STEPPER HELPERS
═══════════════════════════════════════════════════════════════════ */
function gotoStep(n) {
    ['panel1','panel2','panel3-individual','panel3-batch'].forEach(id => {
        const el = $(id);
        if (el) el.classList.remove('active');
    });

    if (n === 1) { $('panel1').classList.add('active'); }
    else if (n === 2) { $('panel2').classList.add('active'); }
    else if (n === 3 && ENTRY_MODE === 'individual') { $('panel3-individual').classList.add('active'); }
    else if (n === 3 && ENTRY_MODE === 'batch')      { $('panel3-batch').classList.add('active'); }

    for (let i = 1; i <= 3; i++) {
        const circle = $('stepCircle' + i);
        const dot    = $('stepDot'    + i);
        circle.classList.remove('active','done');
        dot.classList.remove('active','done');
        if (i < n)  { circle.classList.add('done'); dot.classList.add('done');
                      circle.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" width="12" height="12"><polyline points="20 6 9 17 4 12"/></svg>`; }
        else if (i === n) { circle.classList.add('active'); dot.classList.add('active'); circle.textContent = i; }
        else { circle.textContent = i; }
    }
    $('stepLine12').classList.toggle('done', n > 1);
    $('stepLine23').classList.toggle('done', n > 2);
}

/* ═══════════════════════════════════════════════════════════════════
   STEP 1 — PERIOD SELECTION
═══════════════════════════════════════════════════════════════════ */
$('periodSelect') && $('periodSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];

    if (!this.value) {
        $('periodInfoBox').classList.remove('show');
        if ($('periodPrintActions')) $('periodPrintActions').classList.remove('show');
        if ($('periodPrintNote')) $('periodPrintNote').style.display = 'none';
        return;
    }

    $('periodInfoText').textContent = `${opt.dataset.code} — ${fmt_date(opt.dataset.start)} to ${fmt_date(opt.dataset.end)}`;
    $('periodInfoBox').classList.add('show');

    if ($('periodPrintActions')) $('periodPrintActions').classList.add('show');
    if ($('periodPrintNote')) $('periodPrintNote').style.display = '';
});

$('btnPrintMeterList') && $('btnPrintMeterList').addEventListener('click', () => {
    const sel = $('periodSelect');

    if (!sel || !sel.value) {
        showAlert('warning', 'Please select a reading period first.');
        return;
    }

    window.open(`meter_reading_list_print.php?period_id=${encodeURIComponent(sel.value)}`, '_blank', 'noopener,noreferrer');
});

function fmt_date(value) {
    if (!value) return '';

    // bill_periods.start_date/end_date are MySQL DATETIME values
    // (for example: 2026-07-02 12:00:00).  Do not append another
    // time component to the full DATETIME string because that creates
    // an invalid value such as "2026-07-02 12:00:00T00:00:00".
    const raw = String(value).trim();
    const dateOnlyMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);

    let d;
    if (dateOnlyMatch) {
        const year  = Number(dateOnlyMatch[1]);
        const month = Number(dateOnlyMatch[2]);
        const day   = Number(dateOnlyMatch[3]);
        d = new Date(year, month - 1, day);
    } else {
        // Fallback for any future source format that the browser can parse.
        d = new Date(raw);
    }

    if (Number.isNaN(d.getTime())) {
        return raw;
    }

    return d.toLocaleDateString('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });
}

$('btnStep1Next').addEventListener('click', () => {
    const sel = $('periodSelect');
    if (!sel || !sel.value) { showAlert('warning', 'Please select a reading period to continue.'); return; }
    PERIOD_ID    = parseInt(sel.value);
    PERIOD_LABEL = sel.options[sel.selectedIndex].text.trim();
    gotoStep(2);
});

/* ═══════════════════════════════════════════════════════════════════
   STEP 2 — MODE SELECTION
═══════════════════════════════════════════════════════════════════ */
function selectMode(mode) {
    ENTRY_MODE = mode;
    $('modeCardInd').classList.toggle('selected', mode === 'individual');
    $('modeCardBatch').classList.toggle('selected', mode === 'batch');
    $('btnStep2Next').disabled = false;
}

$('btnStep2Back').addEventListener('click', () => { ENTRY_MODE = null; gotoStep(1); });

$('btnStep2Next').addEventListener('click', () => {
    if (!ENTRY_MODE) { showAlert('warning', 'Please choose an entry mode.'); return; }
    ['periodChip3Ind','periodChip3Batch'].forEach(id => { if($(id)) $(id).textContent = PERIOD_LABEL; });
    if ($('billsIndPeriodLabel'))    $('billsIndPeriodLabel').textContent    = PERIOD_LABEL;
    if ($('billsBatchPeriodLabel'))  $('billsBatchPeriodLabel').textContent  = PERIOD_LABEL;
    gotoStep(3);
    loadRecordedBills();
});

/* ═══════════════════════════════════════════════════════════════════
   STEP 3 — NAVIGATION HELPERS
═══════════════════════════════════════════════════════════════════ */
$('btnIndBack')   && $('btnIndBack').addEventListener('click', () => gotoStep(1));
$('btnBatchBack') && $('btnBatchBack').addEventListener('click', () => gotoStep(1));

$('btnSwitchToBatch') && $('btnSwitchToBatch').addEventListener('click', () => {
    ENTRY_MODE = 'batch'; gotoStep(3);
});
$('btnSwitchToInd') && $('btnSwitchToInd').addEventListener('click', () => {
    ENTRY_MODE = 'individual'; gotoStep(3);
});

/* ═══════════════════════════════════════════════════════════════════
   RECORDED BILLS TABLE (NOW INCLUDES TOTAL DUE)
═══════════════════════════════════════════════════════════════════ */
function loadRecordedBills() {
    if (!PERIOD_ID) return;

    const wrapIds = ['billsTableWrap', 'billsBatchTableWrap'];
    const chipIds = ['billsIndSummaryChips', 'billsBatchSummaryChips'];

    wrapIds.forEach(id => {
        if ($(id)) $(id).innerHTML = `<div class="bills-empty"><span class="spinner lg" style="margin:0 auto;"></span></div>`;
    });

    businessFetchJson(`../process/getPeriodReadings.php?period_id=${PERIOD_ID}`)
        .then(data => {
            const readings = data.readings || [];
            recordedReadings = new Map(readings.map(r => [String(r.reading_id), r]));
            const total    = readings.length;
            const billed   = readings.filter(r => r.is_billed == 1).length;
            const pending  = total - billed;

            const chipsHtml = `
                <div class="bills-chip" style="color:var(--text-primary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Total: <strong>${total}</strong>
                </div>
                <div class="bills-chip" style="color:var(--success); border-color:var(--success-border); background:var(--success-bg);">
                    Encoded: <strong>${total}</strong>
                </div>
                <div class="bills-chip" style="color:var(--info); border-color:var(--info-border); background:var(--info-bg);">
                    Billed: <strong>${billed}</strong>
                </div>
                <div class="bills-chip" style="color:var(--warning); border-color:var(--warning-border); background:var(--warning-bg);">
                    Pending Bill: <strong>${pending}</strong>
                </div>
            `;

            let tableHtml = '';
            if (!total) {
                tableHtml = `<div class="bills-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    No readings recorded yet for this reading period.
                </div>`;
            } else {
                const rows = readings.map(r => {
                    const statusBadge = r.is_billed == 1
                        ? `<span class="badge badge-billed">● Billed</span>`
                        : `<span class="badge badge-done">● Encoded</span>`;
                    const modeBadge = r.entry_mode === 'BATCH'
                        ? `<span class="badge badge-disc">Batch</span>`
                        : `<span class="badge badge-new">Individual</span>`;
                    const createdAt = r.created_at
                        ? new Date(r.created_at).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
                        : '—';
                    const rowCls = r.is_billed == 1 ? 'billed-row' : '';
                    
                    // Fallback to 0 if amount_due is missing from payload
                    const totalDue = r.amount_due ? parseFloat(r.amount_due) : 0;

                    return `
                    <tr class="${rowCls}">
                        <td>
                            <strong style="font-size:.82rem; white-space:nowrap;">${escapeHtml(r.full_name || '—')}</strong>
                            <small style="display:block; color:var(--text-muted); font-size:.7rem; margin-top:1px;">
                                ID: ${escapeHtml(r.member_id)}${r.account_number ? ', Acct# ' + escapeHtml(r.account_number) : ''}
                            </small>
                        </td>
                        <td style="font-family:'JetBrains Mono',monospace; text-align:right;">${Number(r.prev_reading).toFixed(2)}</td>
                        <td style="font-family:'JetBrains Mono',monospace; text-align:right;">${Number(r.pres_reading).toFixed(2)}</td>
                        <td style="font-family:'JetBrains Mono',monospace; text-align:right; color:var(--info); font-weight:600;">${Number(r.consumption).toFixed(2)}</td>
                        <td style="font-family:'JetBrains Mono',monospace; text-align:right; color:var(--accent); font-weight:700; background:#f8f9fb;">${fmtPeso(totalDue)}</td>
                        <td>${statusBadge}</td>
                        <td>${modeBadge}</td>
                        <td style="font-size:.75rem; color:var(--text-muted);">${escapeHtml(r.encoded_by || '—')}</td>
                        <td style="font-size:.75rem; color:var(--text-muted);">${createdAt}</td>
                        <td>${readingActionsHtml(r)}</td>
                    </tr>`;
                }).join('');

                tableHtml = `
                <table class="bills-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th style="text-align:right;">Prev (m³)</th>
                            <th style="text-align:right;">Present (m³)</th>
                            <th style="text-align:right;">Consumed (m³)</th>
                            <th style="text-align:right; color:var(--accent);">Total Due</th>
                            <th>Status</th>
                            <th>Mode</th>
                            <th>Encoded By</th>
                            <th>Date &amp; Time</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>`;
            }

            wrapIds.forEach(id => { if ($(id)) $(id).innerHTML = tableHtml; });
            chipIds.forEach(id => {
                if ($(id)) {
                    $(id).innerHTML = chipsHtml;
                    $(id).style.display = 'flex';
                }
            });
        })
        .catch(() => {
            const errHtml = `<div class="bills-empty" style="color:var(--error);">Failed to load readings. Please refresh.</div>`;
            wrapIds.forEach(id => { if ($(id)) $(id).innerHTML = errHtml; });
        });
}

$('btnRefreshBillsInd')   && $('btnRefreshBillsInd').addEventListener('click', loadRecordedBills);
$('btnRefreshBillsBatch') && $('btnRefreshBillsBatch').addEventListener('click', loadRecordedBills);

/* ═══════════════════════════════════════════════════════════════════
   INDIVIDUAL — MEMBER SEARCH
   ─────────────────────────────────────────────────────────────────
   CHRONOLOGICAL GUARD (frontend role):
   The search AJAX always passes &period_id=PERIOD_ID so that
   searchMembers.php can resolve the selected period's start_date
   and silently exclude members who already have a reading in any
   later reading period.  This prevents the time-travel data bug
   where entering an "older" reading would corrupt the prev_reading
   chain for that member.

   The same guard exists on the backend at two more layers:
     • getMemberReadingData.php  — blocks individual entry with an
       explicit error message if a future reading is detected.
     • getBatchMembers.php       — excludes future-reading members
       from the batch table entirely via NOT EXISTS in SQL.
═══════════════════════════════════════════════════════════════════ */
function positionDropdown() {
    const input    = $('memberSearch');
    const dropdown = $('searchDropdown');
    if (!input || !dropdown) return;
    const rect = input.getBoundingClientRect();
    dropdown.style.top   = (rect.bottom + window.scrollY) + 'px';
    dropdown.style.left  = rect.left + 'px';
    dropdown.style.width = rect.width + 'px';
}

let memberSearchSeq = 0;   // a late answer to an older query is dropped
$('memberSearch').addEventListener('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    const seq = ++memberSearchSeq;
    positionDropdown();

    if (q.length < 1) {
        $('searchDropdown').classList.remove('open');
        $('searchSpinner').style.display = 'none';
        return;
    }

    $('searchSpinner').style.display = 'block';

    searchTimer = setTimeout(() => {
        positionDropdown();
        businessFetchJson(`../process/searchMembers.php?context=reading_entry&q=${encodeURIComponent(q)}&period_id=${PERIOD_ID}`)
            .then(data => {
                if (!Array.isArray(data)) {
                    throw new Error((data && data.error) ? data.error : 'Member search failed.');
                }
                return data;
            })
            .then(data => {
                if (seq !== memberSearchSeq) return;
                $('searchSpinner').style.display = 'none';
                if (!data.length) {
                    $('searchDropdown').innerHTML = `<div class="search-empty">No members found for "<strong>${escapeHtml(q)}</strong>".</div>`;
                } else {
                    $('searchDropdown').innerHTML = data.map(m => {
                        let statusBadge = m.is_billed
                            ? `<span class="badge badge-billed">Billed</span>`
                            : m.reading_done
                                ? `<span class="badge badge-done">Encoded</span>`
                                : `<span class="badge badge-gray">Pending</span>`;
                        let discBadge = m.has_discount ? `<span class="badge badge-disc">Discount</span>` : '';
                        return `
                        <div class="search-result" data-action="select-member" data-member-id="${escapeHtml(m.pkey)}">
                            <div class="search-avatar">${escapeHtml(initials(m.full_name))}</div>
                            <div style="flex:1; min-width:0;">
                                <div class="search-name">${escapeHtml(m.full_name)}</div>
                                <div class="search-meta">${escapeHtml([m.account_number ? 'Account no. ' + m.account_number : '', ((m.housebldg ? m.housebldg + ' ' : '') + (m.street || '')).trim()].filter(Boolean).join(', '))}</div>
                                <div class="search-badges">${statusBadge}${discBadge}</div>
                            </div>
                        </div>`;
                    }).join('');
                }
                $('searchDropdown').classList.add('open');
                positionDropdown();
            }).catch(() => {
                if (seq !== memberSearchSeq) return;
                $('searchDropdown').innerHTML = '<div class="search-empty" style="color:var(--error)">Member search failed. Verify the database and try again.</div>';
                $('searchDropdown').classList.add('open');
                $('searchSpinner').style.display = 'none';
            });
    }, 160);
});

window.addEventListener('scroll', positionDropdown, { passive: true });
window.addEventListener('resize', positionDropdown, { passive: true });

document.addEventListener('click', e => {
    if (!$('memberSearch').contains(e.target) && !$('searchDropdown').contains(e.target)) {
        $('searchDropdown').classList.remove('open');
    }
});

$('searchDropdown').addEventListener('click', event => {
    const result = event.target.closest('[data-action="select-member"]');
    if (!result) return;
    selectMember(Number(result.dataset.memberId));
});

function initials(name) {
    if (!name) return '?';
    const parts = name.split(/[, ]+/).filter(Boolean);
    return parts.slice(0, 2).map(p => p[0]).join('').toUpperCase() || '?';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[character]));
}

/* ═══════════════════════════════════════════════════════════════════
   INDIVIDUAL — SELECT MEMBER
═══════════════════════════════════════════════════════════════════ */
function selectMember(id) {
    $('searchDropdown').classList.remove('open');
    $('memberSearch').value = '';
    $('indSuccessBanner').classList.remove('show');

    businessFetchJson(`../process/getMemberReadingData.php?member_id=${id}&period_id=${PERIOD_ID}`)
        .then(d => {
            if (d.error) { showAlert('error', d.error); return; }
            selectedMember = d;
            renderMemberProfile(d);
            renderReadingForm(d);
        }).catch(error => showAlert('error', (error && error.message) || 'Failed to load member data.'));
}

function renderMemberProfile(d) {
    const last  = d.lastname  || '';
    const first = d.firstname || '';
    const full  = last + (last && first ? ', ' : '') + first;

    $('profileAvatar').textContent = initials(full);
    $('profileName').textContent   = full || 'Unknown Member';
    $('profileAcct').textContent   = 'Account # ' + (d.account_number || d.pkey || '—');
    $('profileAddress').textContent = d.full_address || 'Address not specified';
    $('profileZone').textContent   = d.zone ? 'Zone ' + d.zone : '—';
    $('profileRateCode').textContent = d.rc_code ? `${d.rc_code} - ${d.rc_name || ''}` : (d.rc_id || '—');
    $('profilePipeSize').textContent = d.pipe_size_label
        ? `${d.pipe_size_label}${d.pipe_size_code ? ' (' + d.pipe_size_code + ')' : ''}`
        : 'No pipe size assigned';

    const memberFreeRule = d.discount && Array.isArray(d.discount.rules)
        ? d.discount.rules.find(rule => String(rule.discount_method || '').toLowerCase() === 'free_cubic')
        : null;
    const memberFreeM3 = memberFreeRule
        ? Number(memberFreeRule.discount_value || 0)
        : 0;
    const totalFreeM3 = memberFreeM3;

    if (d.discount || totalFreeM3 > 0) {
        const rules = d.discount && Array.isArray(d.discount.rules) ? d.discount.rules : [];
        const labels = [...new Set(rules.map(rule => rule.discount_type).filter(Boolean))];
        const methods = [...new Set(rules.map(rule => String(rule.discount_method || '').toLowerCase()).filter(Boolean))];
        $('profileDiscountName').textContent   = labels.length ? labels.join(', ') : 'None';
        $('profileDiscountName').style.color   = labels.length ? '#b45309' : '';
        $('profileFreeM3').textContent         = totalFreeM3 + ' m³';
        $('profileActiveDiscount').textContent = methods.length ? methods.map(ucfirst).join(', ') : 'None';
        const monetaryRule = rules.find(rule => ['percent', 'fixed'].includes(String(rule.discount_method || '').toLowerCase()));
        let rateStr = '—';
        if (monetaryRule && String(monetaryRule.discount_method).toLowerCase() === 'percent') rateStr = Number(monetaryRule.discount_value || 0) + '%';
        else if (monetaryRule && String(monetaryRule.discount_method).toLowerCase() === 'fixed') rateStr = fmtPeso(Number(monetaryRule.discount_value || 0));
        $('profileDiscountRate').textContent = rateStr;
        $('profileDiscountBox').style.display = '';
    } else {
        $('profileDiscountName').textContent   = 'None';
        $('profileDiscountName').style.color   = '';
        $('profileDiscountBox').style.display  = 'none';
    }

    let statusHtml;
    if (d.pres_reading !== null) {
        statusHtml = `<span class="badge badge-done">● Encoded (${fmtM3(d.pres_reading)})</span>`;
    } else {
        statusHtml = `<span class="badge badge-gray">● Not Encoded</span>`;
    }
    $('profileReadingStatus').innerHTML = statusHtml;
    $('memberProfile').style.display = 'block';
}

function renderReadingForm(d) {
    const prev = Number.parseInt(d.prev_reading, 10) || 0;
    $('prevReading').value = String(prev);
    $('dispPrev').textContent = String(prev);

    if (d.pres_reading !== null) {
        const existingPresent = Number.parseInt(d.pres_reading, 10);
        const editable = d.editable === true || d.editable === 1 || d.editable === '1';
        $('presReading').value = Number.isInteger(existingPresent) ? String(existingPresent) : '';
        $('presReading').disabled = true;
        $('billedBadge').style.display = 'block';
        $('overrideBox').style.display = 'block';
        $('overrideToggle').checked = false;
        $('overrideToggleWrap').style.display = editable ? 'block' : 'none';
        $('existingReadingTitle').textContent = editable
            ? '⚠ Draft Reading Already Recorded'
            : '🔒 Reading Is Billed, Paid, or Inactive';
        $('existingReadingMessage').textContent = editable
            ? ' — Enable draft editing to modify it before posting.'
            : ' — Historical readings are immutable and must be corrected through an approved adjustment workflow.';
    } else {
        $('presReading').value = '';
        $('presReading').disabled = false;
        $('billedBadge').style.display = 'none';
        $('overrideBox').style.display = 'none';
        $('overrideToggleWrap').style.display = 'block';
    }

    $('readingError').style.display = 'none';
    $('readingEntryCard').style.display = 'block';
    updateBillPreview();
}

function clearIndividualForm() {
    selectedMember = null;
    $('memberSearch').value  = '';
    $('memberProfile').style.display = 'none';
    $('readingEntryCard').style.display = 'none';
    $('dispPrev').textContent = '—';
    $('dispPres').textContent = '—';
    $('dispCons').textContent = '—';
    $('billPreviewBody').innerHTML = `<div class="preview-empty"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Select a member and enter a present reading to see the bill preview.</div>`;
    $('readingError').style.display = 'none';
}

$('overrideToggle').addEventListener('change', function () {
    const editable = selectedMember && (selectedMember.editable === true || selectedMember.editable === 1 || selectedMember.editable === '1');
    if (!editable) {
        this.checked = false;
        $('presReading').disabled = true;
        return;
    }
    $('presReading').disabled = !this.checked;
    if (this.checked) $('presReading').focus();
    updateBillPreview();
});

/* ═══════════════════════════════════════════════════════════════════
   WATER BILL PREVIEW
   The server-side canonical engine in waterRateBillingHelpers.php remains
   authoritative. This single client preview mirrors its progressive tier
   display for both individual and batch entry; saved amounts come from PHP.
═══════════════════════════════════════════════════════════════════ */
$('presReading').addEventListener('input', updateBillPreview);

function previewDecimalToScaled(value, scale) {
    const factor = 10 ** scale;
    const numeric = Number(value || 0);
    return Math.round((numeric + Number.EPSILON) * factor);
}

function previewRoundDivide(numerator, denominator) {
    if (denominator <= 0) throw new Error('Invalid preview divisor.');
    return Math.floor((numerator + Math.floor(denominator / 2)) / denominator);
}

function validatePreviewTiers(inputRates) {
    const rates = [...inputRates].sort((a, b) => Number(a.from_cb) - Number(b.from_cb));
    if (!rates.length) throw new Error('No rate tiers are configured for this member.');

    let expectedStart = 1;
    let terminalSeen = false;
    rates.forEach((rate, index) => {
        const from = Number(rate.from_cb);
        const to = rate.to_cb === null ? null : Number(rate.to_cb);
        const coverageStart = Math.max(1, from);
        if (index === 0 && from !== 0 && from !== 1) {
            throw new Error('The first tier must start at 0 or 1 m³.');
        }
        if (coverageStart > expectedStart) {
            throw new Error(`Rate gap detected before ${coverageStart} m³.`);
        }
        if (coverageStart < expectedStart) {
            throw new Error(`Rate overlap detected at ${coverageStart} m³.`);
        }
        if (previewDecimalToScaled(rate.amount, 4) <= 0) {
            throw new Error('Every rate tier amount must be greater than zero.');
        }
        const type = String(rate.bill_type || '').toUpperCase();
        if (type !== 'FIXED' && type !== 'VARIABLE') {
            throw new Error('A rate tier has an invalid type.');
        }
        if (terminalSeen) {
            throw new Error('No rate tier may follow the open-ended terminal tier.');
        }
        if (to === null) {
            terminalSeen = true;
            if (index !== rates.length - 1) {
                throw new Error('The open-ended tier must be the final tier.');
            }
        } else {
            if (to < coverageStart) {
                throw new Error('A rate tier ends before its first billable unit.');
            }
            expectedStart = to + 1;
        }
    });

    if (!terminalSeen) {
        throw new Error('The final rate tier must be open-ended.');
    }
    return rates;
}

function validatePreviewDiscount(discount) {
    if (!discount || !Array.isArray(discount.rules) || !discount.rules.length) return null;

    const eligibilityLimit = Number(discount.eligibility_limit_m3 ?? 30);
    if (!Number.isInteger(eligibilityLimit) || eligibilityLimit < 0) {
        throw new Error('The water-discount eligibility limit is invalid.');
    }

    const rules = discount.rules.map(rule => {
        const method = String(rule.discount_method || 'none').toLowerCase();
        if (!['percent', 'fixed', 'free_cubic'].includes(method)) {
            throw new Error('An assigned General Discount has an invalid water-billing method.');
        }

        const value = Number(rule.discount_value || 0);
        if (!Number.isFinite(value) || value <= 0) {
            throw new Error('An assigned General Discount must have a positive value.');
        }

        let maxAmount = null;
        if (method === 'free_cubic') {
            if (!Number.isInteger(value)) {
                throw new Error('Free Cubic Meter must be a positive whole number.');
            }
        } else {
            maxAmount = Number(rule.max_discount_amount || 0);
            if (!Number.isFinite(maxAmount) || maxAmount <= 0) {
                throw new Error('Percentage and Fixed Amount discounts require a positive maximum discount amount.');
            }
            if (method === 'percent' && value > 100) {
                throw new Error('Percentage discount cannot exceed 100%.');
            }
        }

        return {
            ...rule,
            discount_method: method,
            discount_value: value,
            max_discount_amount: maxAmount,
            max_discount_cubic: null,
        };
    });

    return {
        ...discount,
        eligibility_limit_m3: eligibilityLimit,
        rules,
    };
}

function computeWaterBillPreview(rcId, pipeSizeId, discount, prev, pres) {
    if (!Number.isFinite(pres) || pres < prev) return null;

    try {
        const totalCons = Math.max(0, Math.round(pres - prev));
        const normalizedDiscount = validatePreviewDiscount(discount);
        const myRates = validatePreviewTiers(
            waterRates.filter(rate => String(rate.rc_id) === String(rcId) && String(rate.pipe_size_id) === String(pipeSizeId))
        );

        // the rate code's computation (Water Billing > Rate Codes), as the server bills it
        const method = myRates.length && myRates[0].billing_method === 'ladder' ? 'ladder' : 'straight';
        const priceLadder = billable => {
            // Ladder Rate Billing: each tier prices its own cubic meters; a fixed tier is the minimum charge of its range
            if (!myRates.length) throw new Error(`${billable} m³ is not covered by the configured water-rate tiers.`);
            const breakdown = [];
            let amountCents = 0;
            for (let i = 0; i < myRates.length; i++) {
                const rate = myRates[i];
                const from = Math.max(1, Number(rate.from_cb));
                const to = rate.to_cb === null ? null : Number(rate.to_cb);
                if (i > 0 && billable < from) break;
                const units = Math.max(0, (to === null ? billable : Math.min(billable, to)) - from + 1);
                const type = String(rate.bill_type).toUpperCase();
                const rateUnits = previewDecimalToScaled(rate.amount, 4);
                const cents = type === 'FIXED' ? previewDecimalToScaled(rate.amount, 2) : previewRoundDivide(rateUnits * units, 100);
                if (cents > 0) {
                    amountCents += cents;
                    breakdown.push({ from: Number(rate.from_cb), to, type, quantity: units, rate: rateUnits / 10000, amount: cents / 100 });
                }
            }
            return { billable, amountCents, selectedRate: myRates[0], breakdown };
        };

        const priceBillable = billable => {
            if (method === 'ladder') return priceLadder(billable);
            let selectedRate = null;
            if (billable === 0) {
                selectedRate = myRates[0];
            } else {
                selectedRate = myRates.find(rate => {
                    const from = Math.max(1, Number(rate.from_cb));
                    const to = rate.to_cb === null ? null : Number(rate.to_cb);
                    return billable >= from && (to === null || billable <= to);
                }) || null;
            }

            if (!selectedRate) {
                throw new Error(`${billable} m³ is not covered by the configured water-rate tiers.`);
            }

            const from = Number(selectedRate.from_cb);
            const to = selectedRate.to_cb === null ? null : Number(selectedRate.to_cb);
            const type = String(selectedRate.bill_type).toUpperCase();
            const rateUnits = previewDecimalToScaled(selectedRate.amount, 4);
            const amountCents = type === 'FIXED'
                ? previewDecimalToScaled(selectedRate.amount, 2)
                : previewRoundDivide(rateUnits * billable, 100);

            return {
                billable,
                amountCents,
                selectedRate,
                breakdown: [{
                    from,
                    to,
                    type,
                    quantity: billable,
                    rate: rateUnits / 10000,
                    amount: amountCents / 100,
                }],
            };
        };

        const basePricing = priceBillable(totalCons);
        const baseCents = basePricing.amountCents;
        const eligibilityLimit = normalizedDiscount ? normalizedDiscount.eligibility_limit_m3 : 30;
        const rules = normalizedDiscount ? normalizedDiscount.rules : [];
        const freeRules = rules.filter(rule => rule.discount_method === 'free_cubic');
        const monetaryRules = rules.filter(rule => ['percent', 'fixed'].includes(rule.discount_method));

        if (freeRules.length > 1) {
            throw new Error('This member has more than one active Water Billing Free Cubic Meter privilege. Keep only one before billing.');
        }
        if (monetaryRules.length > 1) {
            throw new Error('This member has more than one active Water Billing Percentage/Fixed privilege. Keep only one before billing.');
        }

        const freeRule = freeRules[0] || null;
        const monetaryRule = monetaryRules[0] || null;
        let memberFreeM3 = 0;
        let postFreePricing = basePricing;
        let postFreeCents = baseCents;

        if (freeRule) {
            memberFreeM3 = Number(freeRule.discount_value || 0);
            postFreePricing = priceBillable(Math.max(0, totalCons - memberFreeM3));
            postFreeCents = Math.min(baseCents, Math.max(0, postFreePricing.amountCents));
        }

        const freeBenefitCents = Math.max(0, baseCents - postFreeCents);
        let monetaryBenefitCents = 0;
        let finalDueCents = postFreeCents;
        let appliedMonetaryRule = null;

        if (monetaryRule && totalCons <= eligibilityLimit) {
            let calculated = 0;
            if (monetaryRule.discount_method === 'percent') {
                const percentUnits = previewDecimalToScaled(monetaryRule.discount_value, 4);
                calculated = previewRoundDivide(postFreeCents * percentUnits, 1000000);
            } else {
                calculated = previewDecimalToScaled(monetaryRule.discount_value, 2);
            }
            const cap = previewDecimalToScaled(monetaryRule.max_discount_amount, 2);
            monetaryBenefitCents = Math.min(postFreeCents, Math.max(0, Math.min(calculated, cap)));
            finalDueCents = Math.max(0, postFreeCents - monetaryBenefitCents);
            appliedMonetaryRule = monetaryRule;
        }

        const breakdownHtml = postFreePricing.breakdown.map(line => {
            const range = `${line.from}–${line.to === null ? 'Up' : line.to}`;
            const detail = line.type === 'FIXED'
                ? 'Fixed charge'
                : `${line.quantity.toFixed(0)} m³ × ${fmtPeso(line.rate)}`;
            return `<div style="display:flex;justify-content:space-between;margin-bottom:2px;"><span>Tier ${range} (${detail})</span><span>${fmtPeso(line.amount)}</span></div>`;
        }).join('');

        const labels = [];
        if (freeRule) {
            labels.push(`${freeRule.discount_type || 'General Discount'} (${memberFreeM3} m³ free)`);
        }
        if (appliedMonetaryRule) {
            const name = appliedMonetaryRule.discount_type || 'General Discount';
            labels.push(appliedMonetaryRule.discount_method === 'percent'
                ? `${name} (${appliedMonetaryRule.discount_value}%)`
                : `${name} (Fixed)`);
        }
        if (monetaryRule && totalCons > eligibilityLimit) {
            labels.push(`Percentage/Fixed unavailable above ${eligibilityLimit} m³`);
        }

        return {
            totalCons,
            freeM3: memberFreeM3,
            memberFreeM3,
            billable: postFreePricing.billable,
            baseCharge: baseCents / 100,
            freeCubicDiscount: freeBenefitCents / 100,
            monetaryDiscount: monetaryBenefitCents / 100,
            discAmt: (baseCents - finalDueCents) / 100,
            discLabel: labels.join(' + '),
            amtDue: finalDueCents / 100,
            breakdownHtml,
            methodLabel: method === 'ladder' ? 'Ladder Rate Billing' : 'Straight Rate Billing',
            unpricedM3: 0,
        };
    } catch (error) {
        return { error: error.message || 'Invalid water rate configuration.' };
    }
}

function updateBillPreview() {
    if (!selectedMember) return;

    const prev = Number.parseInt($('prevReading').value, 10) || 0;
    const rawPresent = $('presReading').value.trim();
    const pres = rawPresent === '' ? NaN : Number(rawPresent);
    const errEl = $('readingError');

    if (!Number.isFinite(pres)) {
        $('dispPres').textContent = '—';
        $('dispCons').textContent = '—';
        errEl.style.display = 'none';
        $('billPreviewBody').innerHTML = `<div class="preview-empty"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>Enter a valid present reading above.</div>`;
        return;
    }

    if (!Number.isInteger(pres) || pres < 0) {
        errEl.textContent = '⚠ Present reading must be a non-negative whole number.';
        errEl.style.display = 'block';
        $('dispPres').textContent = String(pres);
        $('dispCons').textContent = '—';
        $('billPreviewBody').innerHTML = `<div class="preview-empty" style="color:var(--error);">Enter a whole-number meter reading.</div>`;
        return;
    }

    if (pres < prev) {
        errEl.textContent = `⚠ Present reading (${pres}) cannot be less than previous reading (${prev}).`;
        errEl.style.display = 'block';
        $('dispPres').textContent = String(pres);
        $('dispCons').textContent = '—';
        return;
    }

    const bill = computeWaterBillPreview(
        selectedMember.rc_id,
        selectedMember.pipe_size_id,
        selectedMember.discount,
        prev,
        pres
    );
    if (!bill) return;
    if (bill.error) {
        errEl.textContent = `⚠ ${bill.error}`;
        errEl.style.display = 'block';
        $('billPreviewBody').innerHTML = `<div class="preview-empty">Billing preview unavailable until the rate configuration is corrected.</div>`;
        return;
    }

    errEl.style.display = 'none';
    $('dispPres').textContent = String(pres);
    $('dispCons').textContent = String(bill.totalCons);

    let html = '';
    html += `<div class="preview-row"><span class="preview-label">Total Consumed</span><span class="preview-value">${fmtM3(bill.totalCons)}</span></div>`;
    if (bill.freeM3 > 0) {
        html += `<div class="preview-row"><span class="preview-label" style="color:var(--success)">General Discount: Free Cubic Meter</span><span class="preview-value" style="color:var(--success)">− ${fmtM3(bill.freeM3)}</span></div>`;
    }
    html += `<div class="preview-row" style="background:var(--off-white);padding:8px;border-radius:6px;margin:4px 0;"><span class="preview-label">Billable Volume</span><span class="preview-value">${fmtM3(bill.billable)}</span></div>`;
    html += `<div class="preview-row"><span class="preview-label">Base Water Charge</span><span class="preview-value">${fmtPeso(bill.baseCharge)}</span></div>`;
    if (bill.breakdownHtml) {
        html += `<div style="background:#fafafa;border-radius:4px;padding:6px 12px;margin-bottom:8px;border-left:2px solid var(--border);font-size:.75rem;color:var(--text-secondary);">${bill.methodLabel ? `<div style="font-weight:600;color:var(--text-primary);margin-bottom:2px;">${escapeHtml(bill.methodLabel)}</div>` : ''}${bill.breakdownHtml}</div>`;
    }
    if (bill.discAmt > 0) {
        html += `<div class="preview-row"><span class="preview-label" style="color:var(--success)">Discount: ${escapeHtml(bill.discLabel)}</span><span class="preview-value" style="color:var(--success)">− ${fmtPeso(bill.discAmt)}</span></div>`;
    }
    html += `<div class="preview-total"><span class="preview-total-label">Total Amount Due</span><span class="preview-total-value">${fmtPeso(bill.amtDue)}</span></div>`;
    $('billPreviewBody').innerHTML = html;
}

function computeBill(rcId, pipeSizeId, discount, prev, pres) {
    return computeWaterBillPreview(rcId, pipeSizeId, discount, prev, pres);
}

function batchBillHtml(bill) {
    if (bill && bill.error) {
        return `<div style="width:100%;color:var(--danger);font-size:.75rem;font-weight:600;">⚠ ${escapeHtml(bill.error)}</div>`;
    }
    if (!bill) return `
        <div class="bd-item">
            <span class="bd-label">Total Consumed:</span>
            <span class="bd-value empty">—</span>
        </div>
        <div class="bd-item">
            <span class="bd-label">Billable Volume:</span>
            <span class="bd-value empty">—</span>
        </div>
        <div class="bd-item">
            <span class="bd-label">Base Water Charge:</span>
            <span class="bd-value empty">—</span>
        </div>
        <div class="bd-item">
            <span class="bd-label">Total Amount Due:</span>
            <span class="bd-value due empty">—</span>
        </div>`;

    let html = `
        <div class="bd-item">
            <span class="bd-label">Total Consumed:</span>
            <span class="bd-value cons">${fmtM3(bill.totalCons)}</span>
        </div>
        <div class="bd-item">
            <span class="bd-label">Billable Volume:</span>
            <span class="bd-value bill">${fmtM3(bill.billable)}</span>
        </div>
        <div class="bd-item">
            <span class="bd-label">Base Water Charge:</span>
            <span class="bd-value bill">${fmtPeso(bill.baseCharge)}</span>
        </div>
        <div class="bd-item">
            <span class="bd-label">Total Amount Due:</span>
            <span class="bd-value due">${fmtPeso(bill.amtDue)}</span>
        </div>`;
        
    if (bill.breakdownHtml) {
        html += `<div style="width:100%; flex-basis: 100%; margin-top:8px; padding-top:8px; border-top:1px dashed var(--border); font-size:.7rem; color:var(--text-secondary);">
            <div style="font-weight:600; margin-bottom:4px; color:var(--text-primary);">Computation Breakdown${bill.methodLabel ? ' (' + bill.methodLabel + ')' : ''}:</div>
            ${bill.breakdownHtml}
        </div>`;
    }
        
    return html;
}

/* ═══════════════════════════════════════════════════════════════════
   INDIVIDUAL — SAVE
═══════════════════════════════════════════════════════════════════ */
$('btnSaveInd').addEventListener('click', () => {
    if (!selectedMember) return;
    const rawPresent = $('presReading').value.trim();
    const pres = rawPresent === '' ? NaN : Number(rawPresent);
    const prev = Number.parseInt($('prevReading').value, 10) || 0;
    if (!Number.isInteger(pres) || pres < 0) { showAlert('error', 'Present reading must be a non-negative whole number.'); return; }
    if (pres < prev) { showAlert('error', `Present reading (${pres}) cannot be less than previous (${prev}).`); return; }

    const btn = $('btnSaveInd');
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner" style="border-top-color:#fff;"></span> Saving…`;

    const payload = {
        period_id:    PERIOD_ID,
        member_id:    selectedMember.pkey,
        pres_reading: pres,
        reading_id:   selectedMember.reading_id ?? null
    };

    businessFetchJson('../process/saveIndividualReading.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><polyline points="20 6 9 17 4 12"/></svg> Save Reading`;

        if (res.success) {
            const cons = Number(res.consumption ?? (pres - prev));
            $('indSuccessSub').textContent = `Reading for ${[selectedMember.firstname, selectedMember.lastname].filter(Boolean).join(' ') || 'the account'} has been recorded.`;
            $('indSuccessSummary').innerHTML = `
                <div class="success-stat"><div class="success-stat-label">Consumption</div><div class="success-stat-value">${fmtM3(res.consumption ?? cons)}</div></div>
                <div class="success-stat"><div class="success-stat-label">Water Charge</div><div class="success-stat-value">${fmtPeso(res.water_charge ?? 0)}</div></div>
                <div class="success-stat"><div class="success-stat-label">Discount</div><div class="success-stat-value">${fmtPeso(res.discount_amount ?? 0)}</div></div>
                <div class="success-stat"><div class="success-stat-label">Amount Due</div><div class="success-stat-value">${fmtPeso(res.amount_due ?? 0)}</div></div>
            `;
            $('indSuccessBanner').classList.add('show');
            clearIndividualForm();
            loadRecordedBills();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            showAlert('error', res.message || 'Failed to save reading.');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><polyline points="20 6 9 17 4 12"/></svg> Save Reading`;
        showAlert('error', (error && error.message) || 'Network error. Please try again.', 0);
    });
});

/* ═══════════════════════════════════════════════════════════════════
   BATCH — FILTER TOGGLE
═══════════════════════════════════════════════════════════════════ */
document.querySelectorAll('input[name="batchFilter"]').forEach(r => {
    r.addEventListener('change', () => {
        const isStreet = $('filterStreet').checked;
        $('streetWrap').style.display = isStreet ? '' : 'none';
        $('zoneWrap').style.display   = isStreet ? 'none' : '';
    });
});

/* ═══════════════════════════════════════════════════════════════════
   BATCH — LOAD MEMBERS
═══════════════════════════════════════════════════════════════════ */
$('btnLoadBatch').addEventListener('click', loadBatchMembers);

function loadBatchMembers() {
    const isStreet = $('filterStreet').checked;
    const val = isStreet ? $('streetSelect').value : $('zoneSelect').value;
    if (!val) { showAlert('warning', 'Please select a ' + (isStreet ? 'street' : 'zone') + ' first.'); return; }

    const btn = $('btnLoadBatch');
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner"></span> Loading…`;

    batchMembers = [];
    $('batchMemberSearch').value = '';
    $('batchMemberSearch').disabled = true;
    $('batchTableWrap').innerHTML = `<div class="batch-empty"><span class="spinner lg" style="margin:0 auto;"></span></div>`;
    $('batchStats').style.display = 'none';
    $('batchFormActions').style.display = 'none';

    const param = isStreet ? `street=${encodeURIComponent(val)}` : `zone=${encodeURIComponent(val)}`;
    businessFetchJson(`../process/getBatchMembers.php?period_id=${PERIOD_ID}&${param}`)
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Load Members`;

            if (d.error) {
                $('batchTableWrap').innerHTML = `<div class="batch-empty">Error: ${escapeHtml(d.error)}</div>`;
                return;
            }

            batchMembers = d.members || [];

            if (!batchMembers.length) {
                $('batchTableWrap').innerHTML = `<div class="batch-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    No active members found for the selected ${isStreet ? 'street' : 'zone'}.
                </div>`;
                return;
            }

            batchMembers.forEach(member => {
                member._present_value = member.pres_reading !== null && member.pres_reading !== undefined
                    ? String(member.pres_reading)
                    : '';
            });
            $('batchMemberSearch').disabled = false;
            applyBatchMemberFilters();
            $('batchFormActions').style.display = 'flex';
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Load Members`;
            $('batchTableWrap').innerHTML = `<div class="batch-empty">Network error. Please try again.</div>`;
        });
}

/* ═══════════════════════════════════════════════════════════════════
   BATCH — RENDER TABLE (2 rows per member)
═══════════════════════════════════════════════════════════════════ */
function batchReadingIsDone(member) {
    return member.pres_reading !== null
        && member.pres_reading !== undefined
        && String(member.pres_reading).trim() !== '';
}

function batchReadingHasValue(member) {
    const value = member._present_value !== undefined ? member._present_value : member.pres_reading;
    return value !== null && value !== undefined && String(value).trim() !== '';
}

function syncVisibleBatchValues() {
    document.querySelectorAll('.pres-input').forEach(inp => {
        const member = batchMembers.find(m => String(m.pkey) === String(inp.dataset.id));
        if (member) member._present_value = inp.value;
    });
}

function getFilteredBatchMembers() {
    const mode = $('readingStatusFilter') ? $('readingStatusFilter').value : 'all';
    const query = $('batchMemberSearch')
        ? $('batchMemberSearch').value.trim().toLocaleLowerCase()
        : '';

    let filtered = batchMembers;
    if (mode === 'pending') filtered = filtered.filter(member => !batchReadingIsDone(member));
    if (mode === 'done') filtered = filtered.filter(member => batchReadingIsDone(member));

    if (query !== '') {
        filtered = filtered.filter(member => {
            const searchable = [
                member.full_name,
                member.pkey,
                member.account_number,
                member.meter_sequence,
                member.zone ? `zone ${member.zone}` : '',
            ].filter(value => value !== null && value !== undefined)
             .join(' ')
             .toLocaleLowerCase();
            return searchable.includes(query);
        });
    }

    return filtered;
}

function applyBatchMemberFilters() {
    syncVisibleBatchValues();
    const filtered = getFilteredBatchMembers();
    if (!filtered.length) {
        const hasSearch = $('batchMemberSearch') && $('batchMemberSearch').value.trim() !== '';
        let message = 'No members were found for the selected filters.';
        if (hasSearch) {
            message = 'No loaded members match your search.';
        } else if ($('readingStatusFilter').value === 'pending') {
            message = 'No members are waiting for a reading in this billing period.';
        } else if ($('readingStatusFilter').value === 'done') {
            message = 'No members have completed readings in this billing period.';
        }
        $('batchTableWrap').innerHTML = `<div class="batch-empty">${message}</div>`;
    } else {
        renderBatchTable(filtered);
    }
    updateBatchStats();
}

$('readingStatusFilter').addEventListener('change', applyBatchMemberFilters);
$('batchMemberSearch').addEventListener('input', applyBatchMemberFilters);

function renderBatchTable(members) {
    const rows = members.map(m => {
        const prev    = m.prev_reading || 0;
        const presVal = m._present_value !== undefined ? m._present_value : (m.pres_reading !== null ? m.pres_reading : '');
        const cons    = presVal !== '' ? String(Math.max(0, Number(presVal) - Number(prev))) : '—';
        const rowCls  = m.editable ? '' : 'row-billed';

        const discTag = m.discount_type
            ? `<span class="badge badge-disc" style="font-size:.65rem;">${escapeHtml(m.discount_type)}</span>`
            : `<span class="badge badge-gray" style="font-size:.65rem;">None</span>`;

        const statusTag = m.configuration_error
            ? `<span class="badge badge-billed" title="${escapeHtml(m.configuration_error)}">Tariff Error</span>`
            : (m.pres_reading !== null
                ? (!m.editable ? `<span class="badge badge-billed">Locked</span>` : `<span class="badge badge-done">Draft</span>`)
                : `<span class="badge badge-pending">Pending</span>`);

        const initBill = m.configuration_error
            ? {error: m.configuration_error}
            : (presVal !== ''
                ? computeBill(m.rc_id, m.pipe_size_id, m.discount, Number(prev), Number.parseInt(presVal, 10))
                : null);

        return `
        <tr class="${rowCls}" id="brow-${escapeHtml(m.pkey)}">
            <td style="font-family:'JetBrains Mono',monospace; font-weight:700; white-space:nowrap;">${escapeHtml(m.meter_sequence || '—')}</td>
            <td class="member-name-cell">
                <strong>${escapeHtml(m.full_name)}</strong>
                <small>
                    ID: ${escapeHtml(m.pkey)}${m.zone ? ', Zone ' + escapeHtml(m.zone) : ''}
                    , <span style="font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--text-secondary);">${escapeHtml(m.rc_code || String(m.rc_id || '—'))}</span>
                    , <span style="font-weight:600;color:var(--text-secondary);">${escapeHtml(m.pipe_size_label || m.pipe_size_code || 'No pipe size')}</span>
                </small>
            </td>
            <td>${discTag}</td>
            <td style="text-align:right;">
                <input class="batch-reading-input" type="number" value="${prev}" readonly data-prev="${prev}">
            </td>
            <td style="text-align:right;">
                <input class="batch-reading-input pres-input" type="number"
                    step="1" min="${prev}" inputmode="numeric"
                    value="${presVal}"
                    data-id="${m.pkey}"
                    data-rid="${m.reading_id ?? ''}"
                    ${!m.editable ? `readonly title="${escapeHtml(m.configuration_error || 'Billed, paid, or inactive readings are immutable')}"` : ''}
                    placeholder="0">
            </td>
            <td style="font-family:'JetBrains Mono',monospace; font-weight:600; color:var(--info); text-align:right;"
                id="bcons-${m.pkey}">${cons}</td>
            <td>${statusTag}</td>
        </tr>
        <tr class="batch-detail-row ${rowCls}" id="brow2-${m.pkey}">
            <td></td><td></td><td colspan="5">
                <div class="batch-bill-details" id="bbill-${m.pkey}">
                    ${batchBillHtml(initBill)}
                </div>
            </td>
        </tr>`;
    }).join('');

    $('batchTableWrap').innerHTML = `
    <table class="batch-table">
        <thead>
            <tr>
                <th>Sequence</th>
                <th>Member</th>
                <th>Discount</th>
                <th style="text-align:right;">Prev Reading</th>
                <th style="text-align:right;">Present Reading</th>
                <th style="text-align:right;">Consumed (m³)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>${rows}</tbody>
    </table>`;

    document.querySelectorAll('.pres-input').forEach(inp => {
        inp.addEventListener('input', function () {
            const memberId = this.dataset.id;
            const prev = Number.parseInt(this.closest('tr').querySelector('[data-prev]').dataset.prev, 10) || 0;
            const rawPresent = this.value.trim();
            const memberRecord = batchMembers.find(m => String(m.pkey) === String(memberId));
            if (memberRecord) memberRecord._present_value = rawPresent;
            const pres = rawPresent === '' ? NaN : Number(rawPresent);
            const consEl   = $('bcons-' + memberId);
            const billEl   = $('bbill-'  + memberId);

            if (Number.isInteger(pres) && pres >= prev) {
                consEl.textContent = String(pres - prev);
                this.classList.add('changed');
                this.classList.remove('error');

                const member = batchMembers.find(m => String(m.pkey) === String(memberId));
                const bill   = member ? computeBill(member.rc_id, member.pipe_size_id, member.discount, prev, pres) : null;
                if (billEl) billEl.innerHTML = batchBillHtml(bill);

            } else if (!isNaN(pres) && pres < prev) {
                consEl.textContent = '—';
                this.classList.add('error');
                this.classList.remove('changed');
                if (billEl) billEl.innerHTML = batchBillHtml(null);

            } else {
                consEl.textContent = '—';
                this.classList.remove('changed', 'error');
                if (billEl) billEl.innerHTML = batchBillHtml(null);
            }

            updateBatchStats();
        });
    });
}

function updateBatchStats() {
    let encoded = 0, billed = 0, pending = 0;
    batchMembers.forEach(member => {
        const saved = batchReadingIsDone(member);
        const hasValue = batchReadingHasValue(member);
        if (saved && !member.editable) billed++;
        else if (hasValue) encoded++;
        else pending++;
    });
    $('bstatTotal').textContent   = batchMembers.length;
    $('bstatEncoded').textContent = encoded;
    $('bstatPending').textContent = pending;
    $('bstatBilled').textContent  = billed;
    $('bstatShowing').textContent = getFilteredBatchMembers().length;
    $('batchStats').style.display = 'flex';
}

/* ═══════════════════════════════════════════════════════════════════
   BATCH — SAVE
═══════════════════════════════════════════════════════════════════ */
$('btnSaveBatch').addEventListener('click', () => {
    syncVisibleBatchValues();
    const readings = [];
    let hasError = false;

    batchMembers.forEach(member => {
        if (!member.editable) return;
        const rawValue = member._present_value;
        if (rawValue === '' || rawValue === null || rawValue === undefined) return;

        const pres = Number(rawValue);
        const prev = Number.parseInt(member.prev_reading, 10) || 0;
        const visibleInput = document.querySelector(`.pres-input[data-id="${CSS.escape(String(member.pkey))}"]`);

        if (!Number.isInteger(pres) || pres < prev || pres < 0) {
            if (visibleInput) visibleInput.classList.add('error');
            hasError = true;
            return;
        }
        if (visibleInput) visibleInput.classList.remove('error');
        readings.push({
            member_id: member.pkey,
            pres_reading: pres,
            reading_id: member.reading_id || null
        });
    });

    if (hasError) { showAlert('warning', 'Some readings are invalid. Present readings must be whole numbers and cannot be lower than the previous reading.'); return; }
    if (!readings.length) { showAlert('warning', 'No readings to save. Enter at least one present reading.'); return; }

    const isStreet  = $('filterStreet').checked;
    const filterVal = isStreet ? $('streetSelect').value : $('zoneSelect').value;

    const btn = $('btnSaveBatch');
    btn.disabled = true;
    btn.innerHTML = `<span class="spinner" style="border-top-color:#fff;"></span> Saving ${readings.length} reading(s)…`;

    businessFetchJson('../process/saveBatchReading.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            period_id: PERIOD_ID,
            filter_type: isStreet ? 'street' : 'zone',
            filter_value: filterVal,
            readings
        })
    })
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13"/><polyline points="7 3 7 8 15 8"/></svg> Save All Readings`;

        if (res.success) {
            let msg = `✔ ${res.saved} reading(s) saved`;
            if (res.skipped > 0) msg += `, ${res.skipped} skipped`;
            if (res.errors && res.errors.length) msg += `. Errors: ${res.errors.join('; ')}`;
            showAlert('success', msg);
            loadBatchMembers();
            loadRecordedBills();
        } else {
            showAlert('error', res.message || 'Failed to save batch readings.');
        }
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="15" height="15"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13"/><polyline points="7 3 7 8 15 8"/></svg> Save All Readings`;
        showAlert('error', (error && error.message) || 'Network error. Please try again.', 0);
    });
});

$('btnClearBatch').addEventListener('click', () => {
    syncVisibleBatchValues();
    batchMembers.forEach(member => {
        if (member.editable) member._present_value = '';
    });
    applyBatchMemberFilters();
});

/* ═══════════════════════════════════════════════════════════════════
   RECORDED READINGS — EDIT / DELETE AN UNPOSTED READING (version 46)
   ─────────────────────────────────────────────────────────────────
   Only a reading not posted, not paid, of an open period and not
   followed by a later reading of the account: the server checks all of
   it again (saveIndividualReading.php with the reading's id, and
   deleteReading.php). A posted reading is corrected through Billing
   Adjustments.
═══════════════════════════════════════════════════════════════════ */
function readingActionsHtml(r) {
    if (r.draft_editable) {
        const parts = [];
        if (READING_RIGHTS.edit) parts.push(`<button type="button" class="rd-act" data-reading-act="edit" data-reading-id="${escapeHtml(r.reading_id)}" aria-label="Edit the reading of ${escapeHtml(r.full_name || '')}">Edit</button>`);
        if (READING_RIGHTS.delete) parts.push(`<button type="button" class="rd-act is-del" data-reading-act="delete" data-reading-id="${escapeHtml(r.reading_id)}" aria-label="Delete the reading of ${escapeHtml(r.full_name || '')}">Delete</button>`);
        return parts.length ? `<div class="rd-acts">${parts.join('')}</div>` : '<div class="rd-acts"><span class="rd-lock">Unposted</span></div>';
    }
    return `<div class="rd-acts"><span class="rd-lock" title="${escapeHtml(r.lock_reason || '')}">${r.is_billed == 1 || r.is_paid == 1 ? 'Posted, locked' : 'Locked'}</span></div>`;
}

const readingDialog = {
    mode: null, row: null, member: null,
    open(mode, row) {
        this.mode = mode; this.row = row; this.member = null;
        $('readingEditTitle').textContent = mode === 'edit' ? 'Edit the unposted reading' : 'Delete the unposted reading';
        $('rdWho').textContent = row.full_name || 'Water-service account';
        $('rdMeta').textContent = `Account ${row.account_number || row.member_id}, period ${PERIOD_LABEL || ''}`;
        $('rdEditPane').hidden = mode !== 'edit';
        $('rdDeletePane').hidden = mode !== 'delete';
        $('rdSave').hidden = mode !== 'edit';
        $('rdDelete').hidden = mode !== 'delete';
        $('rdErr').style.display = 'none';
        $('rdSave').disabled = mode === 'edit';
        $('rdDelete').disabled = false;
        if (mode === 'edit') {
            $('rdPrev').value = String(Number.parseInt(row.prev_reading, 10) || 0);
            $('rdPres').value = String(Number.parseInt(row.pres_reading, 10) || 0);
            $('rdCons').value = String(Number.parseInt(row.consumption, 10) || 0);
            $('rdDue').textContent = fmtPeso(Number(row.amount_due || 0));
            // the account as the entry form sees it (its rate, pipe size and discount for the amount shown)
            businessFetchJson(`../process/getMemberReadingData.php?member_id=${encodeURIComponent(row.member_id)}&period_id=${PERIOD_ID}`)
                .then(d => {
                    if (d.error) throw new Error(d.error);
                    this.member = d;
                    if (d.prev_reading !== undefined && d.prev_reading !== null) $('rdPrev').value = String(Number.parseInt(d.prev_reading, 10) || 0);
                    $('rdSave').disabled = false;
                    this.preview();
                })
                .catch(err => this.fail((err && err.message) || 'The account could not be loaded.'));
        } else {
            $('rdDeleteText').textContent = `Delete the reading of ${row.full_name || 'this account'}: present ${Number(row.pres_reading)} m³, consumed ${Number(row.consumption)} m³, ${fmtPeso(Number(row.amount_due || 0))}? The account can then be read again for this period. The deletion and its reason are kept in the audit log.`;
            $('rdReason').value = '';
        }
        $('readingEditBack').classList.add('open');
        $('readingEditBack').setAttribute('aria-hidden', 'false');
        setTimeout(() => (mode === 'edit' ? $('rdPres') : $('rdReason')).focus(), 30);
    },
    close() {
        $('readingEditBack').classList.remove('open');
        $('readingEditBack').setAttribute('aria-hidden', 'true');
        this.mode = null; this.row = null; this.member = null;
    },
    fail(msg) { $('rdErr').textContent = msg; $('rdErr').style.display = 'block'; },
    preview() {
        const prev = Number.parseInt($('rdPrev').value, 10) || 0;
        const raw = $('rdPres').value.trim();
        const pres = raw === '' ? NaN : Number(raw);
        $('rdErr').style.display = 'none';
        if (!Number.isInteger(pres) || pres < 0) { $('rdCons').value = '—'; $('rdDue').textContent = '—'; return false; }
        if (pres < prev) { $('rdCons').value = '—'; $('rdDue').textContent = '—'; this.fail(`The present reading (${pres}) cannot be lower than the previous reading (${prev}).`); return false; }
        $('rdCons').value = String(pres - prev);
        if (this.member) {
            const bill = computeWaterBillPreview(this.member.rc_id, this.member.pipe_size_id, this.member.discount, prev, pres);
            $('rdDue').textContent = bill && !bill.error ? fmtPeso(bill.amtDue) : '—';
        }
        return true;
    },
    afterChange(message) {
        this.close();
        showAlert('success', message);
        loadRecordedBills();
        if (ENTRY_MODE === 'batch' && batchMembers.length) loadBatchMembers();
        if (selectedMember) selectMember(Number(selectedMember.pkey));
    },
    save() {
        const r = this.row;
        if (!r || !this.preview()) return;
        $('rdSave').disabled = true;
        businessFetchJson('../process/saveIndividualReading.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ period_id: PERIOD_ID, member_id: Number(r.member_id), pres_reading: Number($('rdPres').value), reading_id: Number(r.reading_id) })
        }).then(res => {
            if (!res.success) throw new Error(res.message || 'The reading was not saved.');
            this.afterChange(`Reading of ${r.full_name || 'the account'} changed: ${Number(res.consumption)} m³ consumed, ${fmtPeso(res.amount_due ?? 0)} due.`);
        }).catch(err => { $('rdSave').disabled = false; this.fail((err && err.message) || 'The reading was not saved.'); });
    },
    remove() {
        const r = this.row;
        const reason = $('rdReason').value.trim();
        if (!r) return;
        if (reason.length < 5) { this.fail('Give a reason of at least 5 characters for deleting the reading.'); $('rdReason').focus(); return; }
        $('rdDelete').disabled = true;
        businessFetchJson('../process/deleteReading.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ period_id: PERIOD_ID, reading_id: Number(r.reading_id), reason })
        }).then(res => {
            if (!res.success) throw new Error(res.message || 'The reading was not deleted.');
            this.afterChange(`Reading of ${r.full_name || 'the account'} deleted. ${res.message || ''}`.trim());
        }).catch(err => { $('rdDelete').disabled = false; this.fail((err && err.message) || 'The reading was not deleted.'); });
    }
};

['billsTableWrap', 'billsBatchTableWrap'].forEach(id => {
    const wrap = $(id);
    if (!wrap) return;
    wrap.addEventListener('click', event => {
        const btn = event.target.closest('[data-reading-act]');
        if (!btn) return;
        const row = recordedReadings.get(String(btn.dataset.readingId));
        if (!row) { showAlert('error', 'The reading is no longer in the list. Refresh it.'); return; }
        readingDialog.open(btn.dataset.readingAct, row);
    });
});
$('rdPres').addEventListener('input', () => readingDialog.preview());
$('rdPres').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); readingDialog.save(); } });
$('rdSave').addEventListener('click', () => readingDialog.save());
$('rdDelete').addEventListener('click', () => readingDialog.remove());
document.querySelectorAll('[data-rd-close]').forEach(b => b.addEventListener('click', () => readingDialog.close()));
$('readingEditBack').addEventListener('mousedown', e => { if (e.target === $('readingEditBack')) readingDialog.close(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && readingDialog.mode) readingDialog.close(); });

/* ═══════════════════════════════════════════════════════════════════
   UTILITY
═══════════════════════════════════════════════════════════════════ */
function ucfirst(str) {
    if (!str) return str;
    return str.charAt(0).toUpperCase() + str.slice(1);
}
</script>
</body>
</html>
