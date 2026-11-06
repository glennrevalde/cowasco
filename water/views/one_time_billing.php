<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
// a new charge category or date issued issues the bill again: the Edit right on the page
$__otbKey = access_resolve_key('one_time_billing');
$otbCanReissue = $__otbKey === null || $__otbKey === '' || Access::can($__otbKey, 'edit');
unset($__authGuardPdo);
/* AUTH_GUARD_END */

/**
 * One-Time Billing — Fixed Filters, Batch Edit & Batch Delete
 */

require_once '../database/config.php';
require_once '../common/discountPrivilegeBillingHelpers.php';
require_once '../common/waterBillCodeExclusionHelpers.php';
require_once '../process/organizationUnitHelpers.php';

require_once __DIR__ . '/../database/config.php';

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$members        = [];
$bill_codes     = [];
$one_time_bills = [];
$cities         = [];
$streetsByCity  = [];
$allStreets     = [];

$pdo = getDB();

if (isset($pdo)) {
    // 1. Fetch Members with all their discounts 
    try {
        $mem_rows = $pdo->query("
            SELECT
                m.pkey,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                d.discount_id,
                d.discount_type
            FROM water_accounts m
            LEFT JOIN discounted_members dm ON m.pkey        = dm.member_id
            LEFT JOIN discounts d           ON dm.discount_id = d.discount_id
            WHERE m.status = 'A'
            ORDER BY m.lastname ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $membersMap = [];
        foreach ($mem_rows as $row) {
            $pk = $row['pkey'];
            if (!isset($membersMap[$pk])) {
                $membersMap[$pk] = [
                    'pkey'      => $pk,
                    'full_name' => $row['full_name'],
                    'discounts' => [],
                ];
            }
            if ($row['discount_id']) {
                $membersMap[$pk]['discounts'][] = [
                    'id'   => (int)$row['discount_id'],
                    'type' => $row['discount_type'],
                    'rate' => 0, 
                ];
            }
        }
        $members = array_values($membersMap);
    } catch (PDOException $e) {
        error_log("Members Fetch Error: " . $e->getMessage());
    }

    // 2. Fetch Bill Codes (UPDATED: Using sales_price instead of default_amount)
    try {
        $excludeWaterBillingBillCode = water_billing_excluded_bill_code_where($pdo, 'bc');

        $code_stmt = $pdo->query("
            SELECT
                bc.code_id,
                bc.code,
                bc.description,
                bc.type,
                CASE WHEN bc.type = 'Inventory' AND it.id IS NOT NULL THEN it.avg_cost ELSE bc.cost_price END AS cost_price,
                bc.sales_price,
                bc.ar_account,
                bc.income_account,
                bc.cogs_account,
                bc.asset_account,
                bc.item_id,
                it.code AS item_code,
                COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = it.id), 0) AS current_qty
            FROM bill_codes bc
            LEFT JOIN items it ON it.id = bc.item_id
            WHERE 1 = 1 {$excludeWaterBillingBillCode}
            ORDER BY bc.code ASC
        ");
        $bill_codes = $code_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Bill Codes Fetch Error: " . $e->getMessage());
    }

    // 3. Fetch Cities with active-member counts
    try {
        $city_stmt = $pdo->query("
            SELECT TRIM(UPPER(c.city)) AS city_name, COUNT(DISTINCT m.pkey) AS member_count
            FROM cities c
            JOIN addresses a ON c.pkey = a.city_id AND a.status = 'A'
            JOIN memberaddress ma ON a.pkey = ma.address_key AND ma.status = 'A'
            JOIN water_accounts m ON ma.member_key = m.pkey AND m.status = 'A'
            WHERE c.status = 'A' AND c.city IS NOT NULL AND TRIM(c.city) != ''
            GROUP BY TRIM(UPPER(c.city)) ORDER BY city_name ASC
        ");
        $cities = $city_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Cities Fetch Error: " . $e->getMessage());
    }

    // 4. Fetch streets grouped by city for dependent Batch Issue filters.
    try {
        $street_stmt = $pdo->query("
            SELECT
                LOWER(TRIM(c.city)) AS city_key,
                TRIM(UPPER(c.city)) AS city_name,
                LOWER(TRIM(a.street)) AS street_key,
                TRIM(UPPER(a.street)) AS street_name,
                COUNT(DISTINCT m.pkey) AS member_count
            FROM cities c
            JOIN addresses a ON c.pkey = a.city_id AND a.status = 'A'
            JOIN memberaddress ma ON a.pkey = ma.address_key AND ma.status = 'A'
            JOIN water_accounts m ON ma.member_key = m.pkey AND m.status = 'A'
            WHERE c.status = 'A'
              AND c.city IS NOT NULL AND TRIM(c.city) <> ''
              AND a.street IS NOT NULL AND TRIM(a.street) <> ''
            GROUP BY LOWER(TRIM(c.city)), TRIM(UPPER(c.city)), LOWER(TRIM(a.street)), TRIM(UPPER(a.street))
            ORDER BY city_name ASC, street_name ASC
        ");
        foreach ($street_stmt->fetchAll(PDO::FETCH_ASSOC) as $streetRow) {
            $cityKey = (string)$streetRow['city_key'];
            $street = [
                'value' => (string)$streetRow['street_key'],
                'label' => ucwords(strtolower((string)$streetRow['street_name'])),
                'member_count' => (int)$streetRow['member_count'],
            ];
            $streetsByCity[$cityKey][] = $street;

            $streetKey = $street['value'];
            if (!isset($allStreets[$streetKey])) {
                $allStreets[$streetKey] = $street;
            } else {
                $allStreets[$streetKey]['member_count'] += $street['member_count'];
            }
        }
        $allStreets = array_values($allStreets);
        usort($allStreets, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    } catch (PDOException $e) {
        error_log("Street Fetch Error: " . $e->getMessage());
    }

    // 5. Fetch Custom Bills Ledger
    try {
        $bill_stmt = $pdo->query("
            SELECT
                b.*,
                m.pkey AS accnt_no,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                c.code,
                c.description AS code_desc,
                c.type AS bill_code_type,
                c.sales_price,
                CASE WHEN c.type = 'Inventory' AND it.id IS NOT NULL THEN it.avg_cost ELSE c.cost_price END AS cost_price,
                COALESCE((SELECT SUM(s.qty_on_hand) FROM item_site_stock s WHERE s.item_id = it.id), 0) AS current_qty
            FROM one_time_bills b
            JOIN water_accounts m ON b.member_id = m.pkey
            JOIN bill_codes c ON b.bill_code_id = c.code_id
            LEFT JOIN items it ON it.id = c.item_id
            ORDER BY b.created_at DESC
        ");
        $one_time_bills = $bill_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Bills Fetch Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
<title>One-Time Billing</title>
<style>
        body, button, input, select, textarea { font-family: 'Sora', sans-serif; }
        .mono, code, pre { font-family: 'JetBrains Mono', monospace; }

/* ── Reset & Tokens ────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --white:       #ffffff; --surface:     #f5f7fa; --surface-2:   #eef1f6;
    --border:      #e2e6ed; --border-2:    #c9d0dc; --text-1:      #111827;
    --text-2:      #4b5563; --text-3:      #9ca3af; --brand:       #1e3a5f;
    --brand-mid:   #2d5282; --brand-light: #ebf2ff;
    --green:       #059669; --green-bg:    #ecfdf5; --green-border:#a7f3d0;
    --red:         #dc2626; --red-bg:      #fef2f2; --red-border:  #fca5a5;
    --amber:       #b45309; --amber-bg:    #fffbeb; --amber-border:#fcd34d;
    --slate:       #6b7280; --slate-bg:    #f3f4f6;
    --radius-xs:   6px; --radius-sm:   10px; --radius:      14px; --radius-lg:   20px;
    --shadow-sm:  0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
    --shadow-xl:  0 24px 64px rgba(0,0,0,.18), 0 4px 16px rgba(0,0,0,.08);
    --font:        'Plus Jakarta Sans', sans-serif;
    --font-mono:   'JetBrains Mono', monospace;
    --ease:        cubic-bezier(.4,0,.2,1);
}

body { font-family: var(--font); background: var(--surface); color: var(--text-1); min-height: 100vh; -webkit-font-smoothing: antialiased; }
.page { padding: 28px 28px 72px; max-width: 1380px; margin: 0 auto; animation: slideUp .45s var(--ease) both; }
@keyframes slideUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 28px; }
.page-title { font-size: 1.65rem; font-weight: 800; line-height: 1.2; letter-spacing: -.02em; }
.page-sub   { font-size: .85rem; color: var(--text-2); margin-top: 5px; }

.header-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.batch-import-link { display:block; margin-top:12px; font-size:.78rem; font-weight:700; color:var(--brand); text-decoration:none; line-height:1.45; }
.batch-import-link:hover { text-decoration:underline; }

.alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius-sm); font-size: .875rem; font-weight: 600; margin-bottom: 20px; }
.alert-success { background:var(--green-bg);  border:1px solid var(--green-border); color:var(--green); }
.alert-danger  { background:var(--red-bg);    border:1px solid var(--red-border);   color:var(--red); }

.card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 24px; }
.card-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
.card-title { font-size: 1rem; font-weight: 700; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; height: 38px; padding: 0 18px; border: none; border-radius: var(--radius-sm); font-family: var(--font); font-size: .82rem; font-weight: 700; cursor: pointer; white-space: nowrap; text-decoration: none; transition: background .18s var(--ease), box-shadow .18s, transform .1s; flex-shrink: 0; }
.btn:active { transform: scale(.97); }
.btn svg { width:14px; height:14px; stroke-width:2.5; flex-shrink:0; }
.btn-primary { background:var(--brand); color:#fff; }
.btn-primary:hover { background:var(--brand-mid); }
.btn-success { background:var(--green); color:#fff; }
.btn-success:hover { background:#047857; }
.btn-danger  { background:var(--red);   color:#fff; }
.btn-danger:hover  { background:#b91c1c; }
.btn-ghost { background:var(--white); color:var(--text-1); border:1.5px solid var(--border); }
.btn-ghost:hover { border-color:var(--brand); color:var(--brand); background:var(--brand-light); }
.btn-ghost-red { background:var(--white); color:var(--red); border:1.5px solid var(--red-border); }
.btn-ghost-red:hover { background:var(--red-bg); border-color:var(--red); }

.icon-btn { width:34px; height:34px; border-radius:var(--radius-xs); border:1.5px solid var(--border); background:var(--white); display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--text-2); transition: all .18s var(--ease); flex-shrink:0; }
.icon-btn:hover { border-color:var(--text-1); color:var(--text-1); background:var(--surface-2); transform:translateY(-1px); }
.icon-btn svg  { width:14px; height:14px; }
.icon-btn.green { color:var(--green);  border-color:var(--green-border); }
.icon-btn.green:hover { background:var(--green-bg); border-color:var(--green); }
.icon-btn.red   { color:var(--red);    border-color:var(--red-border); }
.icon-btn.red:hover { background:var(--red-bg); border-color:var(--red); }

.form-group  { display:flex; flex-direction:column; gap:6px; }
.form-grid   { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px,1fr)); gap:16px; }
.form-grid .span-2 { grid-column: span 2; }
.form-grid .span-full { grid-column: 1 / -1; }

label { font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: var(--text-2); }
.field { padding: 9px 13px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: var(--font); font-size: .875rem; color: var(--text-1); background: var(--white); transition: border-color .18s, box-shadow .18s; width: 100%; outline: none; }
.field:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30,58,95,.1); }
.field[readonly] { background:var(--surface); color:var(--text-2); cursor:not-allowed; }
.field.mono { font-family: var(--font-mono); font-size:.82rem; }
select.field { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 13px center; padding-right: 36px; }
.field-hint { font-size:.7rem; color:var(--text-3); margin-top:2px; }
.edit-reissue-note { font-size:.74rem; line-height:1.45; color:var(--text-2); padding:9px 12px; border-radius:10px; background:var(--surface); border:1px solid var(--border); }
.edit-reissue-note.is-changed { color:#1e3a8a; background:#eff6ff; border-color:#bfdbfe; }
.edit-reissue-note.is-locked { color:#92400e; background:#fffbeb; border-color:#fde68a; }
#editModal .form-group { min-width:0; }
select.field:disabled, input.field[readonly] { background:var(--surface-2); color:var(--text-2); cursor:not-allowed; }
/* Issue Individual Charge: the charges to tick */
.charge-picker { border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--white); overflow:hidden; box-shadow:inset 0 1px 0 rgba(255,255,255,.8), 0 1px 2px rgba(15,23,42,.05); }
.charge-picker-head { padding:10px; border-bottom:1px solid var(--border); background:var(--surface); display:flex; flex-direction:column; gap:6px; }
.charge-list { max-height:300px; overflow-y:auto; }
.charge-row { display:flex; align-items:center; gap:12px; padding:9px 12px; border-bottom:1px solid var(--border); transition:background .12s; }
.charge-row:last-of-type { border-bottom:0; }
.charge-row.is-on { background:var(--brand-light); box-shadow:inset 3px 0 0 var(--brand-mid); }
.charge-row.is-unavailable { opacity:.6; }
.charge-check { display:flex; align-items:flex-start; gap:10px; flex:1; min-width:0; cursor:pointer; margin:0; }
.charge-check input { margin-top:3px; width:16px; height:16px; accent-color:var(--brand-mid); flex-shrink:0; }
.charge-name { display:flex; flex-direction:column; gap:1px; min-width:0; text-transform:none; letter-spacing:normal; }
.charge-name strong { font-size:.82rem; color:var(--text-1); }
.charge-name small { font-size:.72rem; color:var(--text-2); font-weight:400; }
.charge-name .charge-stock { color:var(--text-3); }
.charge-fields { display:flex; gap:8px; align-items:center; flex-shrink:0; }
.charge-fields label { display:flex; align-items:center; gap:5px; margin:0; font-size:.72rem; color:var(--text-3); text-transform:none; letter-spacing:normal; }
.charge-qty input { width:76px; padding:6px 8px; }
.charge-amt input { width:118px; padding:6px 8px; text-align:right; }
.charge-row:not(.is-on) .charge-fields { opacity:.45; }
.charge-empty { padding:14px; text-align:center; color:var(--text-3); font-size:.8rem; }
.charge-summary { display:flex; justify-content:space-between; gap:12px; align-items:center; padding:10px 12px; border-top:1px solid var(--border); background:var(--surface); font-size:.8rem; color:var(--text-2); }
.charge-summary strong { font-family:var(--font-mono); font-size:.95rem; color:var(--text-1); white-space:nowrap; }
.charge-discount-note { font-size:.78rem; color:var(--text-2); background:var(--amber-bg); border:1px solid var(--amber-border); border-radius:var(--radius-xs); padding:8px 10px; }
@media (max-width: 560px) {
    .charge-row { flex-wrap:wrap; }
    .charge-fields { width:100%; padding-left:26px; }
    .charge-amt { flex:1; } .charge-amt input { width:100%; }
}
.no-due-option { display:flex; align-items:flex-start; gap:9px; padding:11px 13px; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--surface); cursor:pointer; }
.no-due-option input { margin-top:2px; accent-color:var(--brand); }
.no-due-option strong { display:block; font-size:.8rem; color:var(--text-1); }
.no-due-option span { display:block; margin-top:2px; font-size:.7rem; color:var(--text-3); font-weight:500; line-height:1.35; }
.field:disabled { background:var(--surface-2); color:var(--text-3); cursor:not-allowed; }

.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px; border-radius:20px; font-size:.65rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.badge-green  { background:var(--green-bg);  color:var(--green);  border:1px solid var(--green-border); }
.badge-red    { background:var(--red-bg);    color:var(--red);    border:1px solid var(--red-border); }
.badge-amber  { background:var(--amber-bg);  color:var(--amber);  border:1px solid var(--amber-border); }
.badge-slate  { background:var(--slate-bg);  color:var(--slate);  border:1px solid var(--border); }

.table-wrap { overflow-x: auto; }
table { width:100%; border-collapse:collapse; font-size:.84rem; white-space:nowrap; }
thead th { text-align:left; padding:12px 18px; font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--text-2); border-bottom:1.5px solid var(--border); background: var(--surface); position: sticky; top:0; z-index:2; }
tbody td { padding:14px 18px; border-bottom:1px solid var(--border); vertical-align:middle; }
tbody tr:last-child td { border-bottom:none; }
tbody tr:hover { background: #fafbfd; }
tbody tr.row-selected { background: var(--brand-light) !important; }
.mono { font-family:var(--font-mono); font-size:.78rem; }
.member-cell .name  { font-weight:700; }
.member-cell .accnt { font-size:.7rem; color:var(--text-3); margin-top:2px; }
.code-cell   .code  { font-weight:700; font-family:var(--font-mono); font-size:.8rem; }
.code-cell   .desc  { font-size:.74rem; color:var(--text-2); margin-top:2px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.actions-cell { display:flex; gap:7px; justify-content:flex-end; align-items:center; }
.empty-state { padding:56px 24px; text-align:center; color:var(--text-3); }
.empty-state svg { width:40px; height:40px; margin-bottom:12px; opacity:.4; }

.batch-toolbar { display: none; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 10px 22px; background: var(--brand-light); border-bottom: 1.5px solid #bfdbfe; font-size: .84rem; }
.batch-toolbar.visible { display: flex; }
.batch-toolbar-info { display:flex; align-items:center; gap:8px; color:var(--brand); font-weight:700; }
.batch-toolbar-info svg { width:16px; height:16px; }
.batch-toolbar-actions { display:flex; gap:8px; flex-wrap:wrap; }

.search-wrap { position:relative; }
.search-wrap input { padding-left:36px; }
.search-wrap .search-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--text-3); pointer-events:none; width:15px; height:15px; }

.overlay { position:fixed; inset:0; background:rgba(10,18,36,.55); backdrop-filter:blur(5px); z-index:1060; display:none; align-items:center; justify-content:center; padding:16px; opacity:0; transition:opacity .3s var(--ease); }
.overlay.open { display:flex; opacity:1; }
.modal { background:var(--white); border-radius:var(--radius-lg); width:100%; max-width:580px; box-shadow:var(--shadow-xl); transform:translateY(28px) scale(.97); opacity:0; transition:transform .35s var(--ease), opacity .35s var(--ease); display:flex; flex-direction:column; max-height:92vh; }
.modal.wide { max-width:1050px; }
.overlay.open .modal { transform:none; opacity:1; }
.modal-head { display:flex; align-items:center; justify-content:space-between; padding:20px 24px; border-bottom:1px solid var(--border); flex-shrink:0; }
.modal-title { font-size:1.1rem; font-weight:800; letter-spacing:-.01em; }
.modal-close { width:32px; height:32px; border-radius:var(--radius-xs); border:1.5px solid var(--border); background:var(--white); display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--text-2); transition:all .15s; flex-shrink:0; }
.modal-close:hover { background:var(--red-bg); border-color:var(--red-border); color:var(--red); }
.modal-close svg { width:16px; height:16px; }
.modal-body { padding:24px; overflow-y:auto; flex:1; }

.batch-layout { display:grid; grid-template-columns:300px minmax(0, 1fr); gap:24px; }
.batch-layout > * { min-width:0; }
.batch-sidebar { border-right:1px solid var(--border); padding-right:24px; display:flex; flex-direction:column; gap:14px; }
.batch-sidebar .execute-btn { margin-top:auto; }
.batch-panel { display:flex; flex-direction:column; gap:14px; }
.batch-filters { display:flex; gap:10px; flex-wrap:wrap; }
.batch-filters .field { flex:1; min-width:130px; }
.batch-table-wrap { border:1px solid var(--border); border-radius:var(--radius-sm); overflow:auto; max-height:340px; flex:1; }
.batch-table-wrap table { font-size:.8rem; }
.batch-table-wrap thead th { padding:10px 14px; font-size:.65rem; position:sticky; top:0; z-index:5; background:var(--surface); }
.batch-table-wrap tbody td { padding:11px 14px; }
.batch-count { font-size:.8rem; color:var(--text-2); display:flex; align-items:center; gap:6px; }
.batch-count strong { color:var(--brand); }
.discount-tag { font-size:.68rem; background:var(--amber-bg); color:var(--amber); padding:2px 7px; border-radius:4px; border:1px solid var(--amber-border); font-weight:700; margin-left:6px; vertical-align:middle; }
.discount-auto-pill { display:flex; align-items:center; gap:8px; padding:10px 13px; background:var(--amber-bg); border:1.5px solid var(--amber-border); border-radius:var(--radius-sm); }
.discount-auto-pill .d-type { font-weight:700; color:var(--amber); font-size:.85rem; }
.discount-auto-pill .d-auto { font-size:.72rem; color:var(--text-3); margin-left:auto; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
.discount-multi-note { font-size:.72rem; color:var(--brand-mid); margin-top:5px; font-weight:600; }
.divider { border:none; border-top:1px solid var(--border); margin:20px 0; }
.form-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; flex-wrap:wrap; }
.info-box { background:var(--brand-light); border:1px solid #bfdbfe; border-radius:var(--radius-sm); padding:10px 14px; font-size:.8rem; color:var(--brand-mid); margin-bottom:16px; }
.info-box strong { font-weight:800; }

@media (max-width: 768px) {
    .page { padding:16px 16px 64px; }
    .page-header { gap:12px; }
    .header-actions { width:100%; }
    .header-actions .btn { flex:1; justify-content:center; }
    .batch-layout { grid-template-columns:minmax(0, 1fr); gap:0; }
    .batch-sidebar { border-right:none; border-bottom:1px solid var(--border); padding-right:0; padding-bottom:20px; margin-bottom:20px; }
    .batch-filters { flex-direction:column; }
    .batch-table-wrap { max-height:250px; }
    .modal { border-radius:var(--radius); }
    .modal.wide { max-width:100%; }
    .form-grid .span-2 { grid-column:1/-1; }
    .batch-toolbar-actions .btn { font-size:.75rem; padding:0 12px; }
}
.discount-info{margin:10px 0 16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;font-size:.82rem;line-height:1.5;font-weight:700;}
tr.deleted-row td{ text-decoration:line-through; color:#9ca3af; background:#f9fafb; } tr.deleted-row .actions-cell, tr.deleted-row .badge{ text-decoration:none; }
</style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">
<div class="discount-info">Discount privileges are now applied automatically when the member is eligible, the Bill Code allows member discount, and a rule exists in Discount Privilege Rules.</div>

    <div class="page-header">
        <div style="flex-basis: 100%; margin-bottom: 8px;">
            <a href="billing_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--slate); text-decoration:none; font-size:.82rem; font-weight:700;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Billing Hub
            </a>
        </div>
        
        <div>
            <h1 class="page-title">One-Time Billing</h1>
            <p class="page-sub">Issue custom charges for penalties, materials, or services</p>
        </div>
        <div class="header-actions">
            <a class="btn btn-ghost" href="one_time_billing_import.php" title="Many members' charges, each with its own amount, from a CSV or Excel file">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Import Charges
            </a>
            <button class="btn btn-ghost" onclick="openAddModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Individual Billing
            </button>
            <button class="btn btn-primary" onclick="openBatchModal()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Batch Billing
            </button>
        </div>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert alert-success">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Custom Charges Ledger</h2>
            <div class="search-wrap">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input class="field" type="text" placeholder="Search accounts…" id="ledgerSearch" oninput="filterLedger(this.value)">
            </div>
        </div>

        <div class="batch-toolbar" id="ledgerBatchToolbar">
            <div class="batch-toolbar-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <span id="ledgerSelCount">0</span> bill(s) selected
            </div>
            <div class="batch-toolbar-actions">
                <button type="button" class="btn btn-primary" onclick="openBatchEditModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit Selected
                </button>
                <button type="button" class="btn btn-ghost-red" onclick="confirmBatchDelete()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    Delete Selected
                </button>
                <button type="button" class="btn btn-ghost" onclick="clearLedgerSelection()">Clear</button>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:40px; padding-left:22px;">
                            <input type="checkbox" id="ledgerSelectAll" onchange="toggleLedgerAll(this)" title="Select all visible">
                        </th>
                        <th>Date Issued</th>
                        <th>Account</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Term</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                <?php if (empty($one_time_bills)): ?>
                    <tr><td colspan="9">
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            <p>No one-time bills have been issued yet.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($one_time_bills as $bill):
                        $sc   = match($bill['status']) {
                            'paid'      => 'badge-green',
                            'cancelled' => 'badge-slate',
                            'deleted'   => 'badge-slate',
                            default     => 'badge-red',
                        };
                        $sdot = match($bill['status']) {
                            'paid'      => '●',
                            'cancelled' => '○',
                            'deleted'   => '✕',
                            default     => '●',
                        };
                    ?>
                    <tr class="ledger-row <?= ($bill['status'] ?? '') === 'deleted' ? 'deleted-row' : '' ?>"
                        data-name="<?= strtolower(e($bill['member_name'])) ?>"
                        data-accnt="<?= e($bill['accnt_no']) ?>"
                        data-id="<?= (int)$bill['bill_id'] ?>">
                        <td style="padding-left:22px;">
                            <input type="checkbox" class="ledger-cb" value="<?= (int)$bill['bill_id'] ?>" onchange="updateLedgerSelection()">
                        </td>
                        <td class="mono"><?= date('m/d/Y', strtotime($bill['bill_date'])) ?></td>
                        <td>
                            <div class="member-cell">
                                <div class="name"><?= e($bill['member_name']) ?></div>
                                <div class="accnt mono">#<?= e($bill['accnt_no']) ?></div>
                            </div>
                        </td>
                        <td>
                            <div class="code-cell">
                                <div class="code"><?= e($bill['code']) ?></div>
                                <div class="desc"><?= e($bill['code_desc']) ?></div>
                            </div>
                        </td>
                        <td class="mono">
                            <?php if (($bill['bill_code_type'] ?? '') === 'Inventory'): ?>
                                <?= number_format((float)($bill['quantity'] ?? 1), 2) ?>
                            <?php else: ?>
                                <span style="color:var(--text-3);">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="mono" style="color:var(--text-2);">
                            <?php if (empty($bill['due_date'])): ?>
                                <span class="badge badge-slate">No Due Date</span>
                            <?php else: ?>
                                <?= e($bill['term_days']) ?>d
                            <?php endif; ?>
                        </td>
                        <td class="mono" style="font-weight:700;">₱ <?= number_format($bill['total_amount'], 2) ?></td>
                        <td><span class="badge <?= $sc ?>"><?= $sdot ?> <?= ucfirst(e($bill['status'])) ?></span></td>
                        <td>
                            <div class="actions-cell">
                                <button class="icon-btn" title="Edit"
                                    onclick="openEditModal(
                                        <?= (int)$bill['bill_id'] ?>,
                                        '<?= e(addslashes((string)($bill['member_name'] ?? ''))) ?>',
                                        '<?= e(addslashes((string)($bill['code'] ?? ''))) ?>',
                                        '<?= e((float)($bill['gross_amount'] ?? 0) > 0 ? $bill['gross_amount'] : $bill['total_amount']) ?>',
                                        '<?= e($bill['bill_date']) ?>',
                                        '<?= e($bill['term_days']) ?>',
                                        '<?= e($bill['due_date']) ?>',
                                        '<?= e($bill['status']) ?>',
                                        '<?= e($bill['bill_code_type'] ?? '') ?>',
                                        '<?= e($bill['quantity'] ?? 1) ?>',
                                        '<?= e($bill['sales_price'] ?? 0) ?>',
                                        '<?= e($bill['current_qty'] ?? 0) ?>',
                                        '<?= (int)($bill['class_id'] ?? 0) ?>',
                                        <?= (int)$bill['bill_code_id'] ?>,
                                        '<?= e((float)($bill['amount_paid'] ?? 0)) ?>',
                                        '<?= e((float)($bill['discount_amount'] ?? 0)) ?>'
                                    )">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button class="icon-btn red" title="Delete" onclick="confirmDelete(<?= (int)$bill['bill_id'] ?>)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>

<form id="deleteForm"   action="../process/deleteOneTimeBill.php"  method="POST" style="display:none;"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

    <input type="hidden" name="bill_id" id="delete_bill_id">
</form>

<form id="batchDeleteForm" action="../process/deleteBatchOneTimeBill.php" method="POST" style="display:none;"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <div id="batchDeleteIds"></div>
</form>
<form id="batchEditFormHidden" action="../process/editBatchOneTimeBill.php" method="POST" style="display:none;"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <div id="batchEditIds"></div>
    <input type="hidden" name="status"    id="be_status_hidden">
    <input type="hidden" name="due_date"  id="be_due_hidden">
    <input type="hidden" name="term_days" id="be_term_hidden">
    <input type="hidden" name="clear_due_date" id="be_clear_due_hidden" value="0">
    <input type="hidden" name="class_id" id="be_class_hidden">
</form>

<div class="overlay" id="addModal" onclick="handleOverlayClick(event,'addModal')">
<div class="modal">
    <div class="modal-head">
        <span class="modal-title">Issue Individual Charge</span>
        <button class="modal-close" type="button" onclick="closeAddModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="modal-body">
        <form action="../process/addOneTimeBill.php" method="POST" id="addForm" onsubmit="return validateCharges();"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-grid">
                <div class="form-group span-full">
                    <label>Consumer Account</label>
                    <select name="member_id" id="ind_member_id" class="field" required onchange="calcIndDiscount()">
                        <option value="" disabled selected>Search &amp; select member…</option>
                        <?php foreach ($members as $mem):
                            $discJson  = e(json_encode($mem['discounts']));
                            $discCount = count($mem['discounts']);
                            $discLabel = '';
                            if ($discCount === 1) {
                                $discLabel = " [−".(float)$mem['discounts'][0]['rate']."%]";
                            } elseif ($discCount > 1) {
                                $discLabel = " [".$discCount." discounts]";
                            }
                        ?>
                        <option value="<?= e($mem['pkey']) ?>" data-discounts="<?= $discJson ?>">
                            <?= e($mem['pkey']) ?> — <?= e($mem['full_name']) ?><?= $discLabel ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group span-full" id="ind_discount_section" style="display:none;">
                    <div class="charge-discount-note" id="ind_discount_display"></div>
                </div>
                <div class="form-group span-full">
                    <label for="chargeFind">Charges</label>
                    <div class="charge-picker" id="chargePicker">
                        <div class="charge-picker-head">
                            <input type="search" id="chargeFind" class="field" placeholder="Find a charge by code or description" autocomplete="off">
                            <span class="field-hint">Tick each charge to bill and type its amount; a material is billed by its quantity at its sales price.</span>
                        </div>
                        <div class="charge-list" id="chargeList">
                            <?php foreach ($bill_codes as $code):
                                $isInv = ($code['type'] ?? '') === 'Inventory';
                                $cid = (int)$code['code_id'];
                                $linked = !$isInv || !empty($code['item_id']);
                                $price = number_format((float)($code['sales_price'] ?? 0), 2, '.', '');
                            ?>
                            <div class="charge-row<?= $linked ? '' : ' is-unavailable' ?>" data-code-id="<?= $cid ?>" data-inventory="<?= $isInv ? '1' : '0' ?>"
                                 data-price="<?= e($price) ?>" data-stock="<?= e((float)($code['current_qty'] ?? 0)) ?>"
                                 data-search="<?= e(mb_strtolower(($code['code'] ?? '') . ' ' . ($code['description'] ?? ''))) ?>">
                                <label class="charge-check">
                                    <input type="checkbox" name="charges[<?= $cid ?>][on]" value="1" class="charge-on"<?= $linked ? '' : ' disabled' ?>>
                                    <span class="charge-name">
                                        <strong><?= e($code['code']) ?></strong>
                                        <small><?= e($code['description']) ?></small>
                                        <?php if ($isInv): ?>
                                            <small class="charge-stock"><?= $linked ? 'In stock: ' . e(number_format((float)($code['current_qty'] ?? 0), 2)) . ' at ₱' . e(number_format((float)$price, 2)) . ' each' : 'Not linked to an item of the Item List (Bill Codes): it cannot be billed until it is.' ?></small>
                                        <?php endif; ?>
                                    </span>
                                </label>
                                <div class="charge-fields">
                                    <?php if ($isInv): ?>
                                        <label class="charge-qty"><span>Qty</span>
                                            <input type="number" name="charges[<?= $cid ?>][quantity]" class="field mono charge-qty-input" step="0.01" min="0.01" value="1" disabled></label>
                                    <?php endif; ?>
                                    <label class="charge-amt"><span>₱</span>
                                        <input type="number" name="charges[<?= $cid ?>][amount]" class="field mono charge-amt-input" step="0.01" min="0.01" value="<?= e($price) ?>" disabled<?= $isInv ? ' readonly title="A material is billed at its sales price times the quantity."' : '' ?>></label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="charge-empty" id="chargeEmpty" hidden>No charge matches.</div>
                        </div>
                        <div class="charge-summary" id="chargeSummary">No charge ticked yet.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Date Issued</label>
                    <input type="date" name="bill_date" id="add_date" class="field" required>
                </div>
                <div class="form-group span-full">
                    <label class="no-due-option" for="add_no_due">
                        <input type="checkbox" name="no_due_date" id="add_no_due" value="1">
                        <span><strong>No Due Date</strong><span>This charge remains collectible but will not become overdue or qualify for date-based disconnection.</span></span>
                    </label>
                </div>
                <div class="form-group">
                    <label>Term (Days)</label>
                    <input type="number" name="term_days" id="add_term" class="field mono" min="0" required>
                </div>
                <div class="form-group span-full">
                    <label>Due Date</label>
                    <input type="date" name="due_date" id="add_due" class="field" required>
                </div>
                <div class="form-group span-full">
                    <?= render_water_class_field(null, true, 'addClassId', 'class_id', 'field') ?>
                </div>
            </div>
            <div class="form-footer">
                <button type="button" class="btn btn-ghost" onclick="closeAddModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="postChargesBtn">Post Charge</button>
            </div>
        </form>
    </div>
</div>
</div>

<div class="overlay" id="batchModal" onclick="handleOverlayClick(event,'batchModal')">
<div class="modal wide">
    <div class="modal-head">
        <span class="modal-title">Batch Issue Charges</span>
        <button class="modal-close" type="button" onclick="closeBatchModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="modal-body">
        <form action="../process/addBatchOneTimeBill.php" method="POST" id="batchForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="batch-layout">
                <div class="batch-sidebar">
                    <div class="form-group">
                        <label>Charge Category</label>
                        <select name="bill_code_id" id="batch_bill_code_id" class="field" required onchange="autoFillBatchAmount()">
                            <option value="" disabled selected>Select bill code…</option>
                            <?php foreach ($bill_codes as $code):
                                // UPDATED: Use sales_price instead of default_amount
                                $salesPrice = $code['sales_price'] ?? 0;
                            ?>
                            <option
                                value="<?= e($code['code_id']) ?>"
                                data-amount="<?= e($salesPrice) ?>"
                                data-type="<?= e($code['type'] ?? '') ?>"
                                data-cost="<?= e($code['cost_price'] ?? 0) ?>"
                                data-stock="<?= e($code['current_qty'] ?? 0) ?>"
                                data-item="<?= e($code['item_id'] ?? '') ?>"
                            >
                                <?= e($code['code']) ?> — <?= e($code['description']) ?>
                                <?php if (($code['type'] ?? '') === 'Inventory'): ?>
                                    <?= !empty($code['item_id']) ? '(Available: ' . number_format((float)($code['current_qty'] ?? 0), 2) . ')' : '(not linked to an item)' ?>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Base Amount per Member (₱)</label>
                        <input type="number" step="0.01" name="base_amount" id="batch_base_amount"
                               class="field mono" placeholder="0.00" required oninput="updateBatchPrices()">
                    </div>
                    <div class="form-group">
                        <label>Date Issued</label>
                        <input type="date" name="bill_date" id="b_date" class="field" required>
                    </div>
                    <div class="form-group">
                        <label class="no-due-option" for="b_no_due">
                            <input type="checkbox" name="no_due_date" id="b_no_due" value="1">
                            <span><strong>No Due Date</strong><span>Apply no due date to every charge in this batch.</span></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label>Term (Days)</label>
                        <input type="number" name="term_days" id="b_term" class="field mono" value="7" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date</label>
                        <input type="date" name="due_date" id="b_due" class="field" required>
                    </div>
                    <div class="form-group">
                        <?= render_water_class_field(null, true, 'batchClassId', 'class_id', 'field') ?>
                    </div>
                    <hr class="divider">
                    <button type="submit" class="btn btn-success execute-btn" style="width:100%; justify-content:center;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><polyline points="20 6 9 17 4 12"/></svg>
                        Execute Batch Billing
                    </button>
                    <a href="one_time_billing_import.php" class="batch-import-link">Different amounts or charges for each member? Import them from a file</a>
                </div>

                <div class="batch-panel">
                    <div class="batch-filters">
                        <select id="f_city" class="field" onchange="onCityChange()" style="flex:1;">
                            <option value="" disabled selected>-- Select a City --</option>
                            <option value="all" style="font-weight:700; color:var(--brand);">Select All (All Cities)</option>
                            <?php foreach ($cities as $c):
                                $cityProper = ucwords(strtolower($c['city_name']));
                            ?>
                            <option value="<?= e(strtolower((string)$c['city_name'])) ?>">
                                <?= e($cityProper) ?> (<?= (int)$c['member_count'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <select id="f_street" class="field" onchange="onStreetChange()" style="flex:1;" disabled>
                            <option value="" selected>-- Select a City First --</option>
                        </select>

                        <div class="search-wrap" style="flex:1;">
                            <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" id="f_search" class="field" placeholder="Filter fetched list…" oninput="filterFetchedMembers()">
                        </div>
                    </div>

                    <div class="batch-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th>
                                    <th>Acct #</th>
                                    <th>Name</th>
                                    <th>Discount</th>
                                    <th style="text-align:right;">Final Price</th>
                                </tr>
                            </thead>
                            <tbody id="batchBody">
                                <tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-3);">Select a city, then optionally choose a street to load members.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="batch-count">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        Selected: <strong id="batchCount">0</strong> members
                    </div>
                </div>

            </div>
        </form>
    </div>
</div>
</div>

<div class="overlay" id="editModal" onclick="handleOverlayClick(event,'editModal')">
<div class="modal">
    <div class="modal-head">
        <span class="modal-title">Update Bill Record</span>
        <button class="modal-close" type="button" onclick="closeEditModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="modal-body">
        <form action="../process/editOneTimeBill.php" method="POST" onsubmit="return validateEditBill()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="bill_id" id="edit_id">
            <div class="form-grid">
                <div class="form-group span-full">
                    <label>Consumer Account</label>
                    <input type="text" id="edit_name" class="field" readonly>
                </div>
                <div class="form-group span-full">
                    <label for="edit_code">Charge Category</label>
                    <select name="bill_code_id" id="edit_code" class="field" required>
                        <?php foreach ($bill_codes as $code):
                            $isInv = ($code['type'] ?? '') === 'Inventory';
                            $linked = !$isInv || !empty($code['item_id']);
                        ?>
                        <option value="<?= (int)$code['code_id'] ?>" data-inventory="<?= $isInv ? '1' : '0' ?>"
                                data-price="<?= e(number_format((float)($code['sales_price'] ?? 0), 2, '.', '')) ?>"
                                data-stock="<?= e((float)($code['current_qty'] ?? 0)) ?>"<?= $linked ? '' : ' disabled' ?>>
                            <?= e($code['code']) ?><?= ($code['description'] ?? '') !== '' ? ' (' . e($code['description']) . ')' : '' ?><?= $isInv ? ', material' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group span-full">
                    <div class="edit-reissue-note" id="edit_reissue_note">A new charge category or date issued issues the bill again: its invoice is voided and the corrected one is posted on the date issued, while nothing is collected on the bill.</div>
                </div>
                <div class="form-group span-full" id="edit_inventory_qty_group" style="display:none;">
                    <label>Inventory Quantity to Deduct</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0.01"
                        name="quantity"
                        id="edit_quantity"
                        class="field mono"
                        value="1"
                    >
                    <span class="field-hint" id="edit_inventory_stock_hint">Quantity changes will adjust inventory balance.</span>
                </div>
                <div class="form-group span-2">
                    <label>Total Amount (₱)</label>
                    <input type="number" name="total_amount" id="edit_amount" class="field mono" step="0.01" min="0" required>
                    <span class="field-hint" id="edit_amount_hint" hidden></span>
                </div>
                <div class="form-group">
                    <label>Date Issued</label>
                    <input type="date" name="bill_date" id="edit_date" class="field" required>
                </div>
                <div class="form-group span-full">
                    <label class="no-due-option" for="edit_no_due">
                        <input type="checkbox" name="no_due_date" id="edit_no_due" value="1">
                        <span><strong>No Due Date</strong><span>Remove the due date and prevent date-based overdue or disconnection handling.</span></span>
                    </label>
                </div>
                <div class="form-group">
                    <label>Term (Days)</label>
                    <input type="number" name="term_days" id="edit_term" class="field mono" min="0" required>
                </div>
                <div class="form-group span-2">
                    <label>Due Date</label>
                    <input type="date" name="due_date" id="edit_due" class="field" required>
                </div>
                <div class="form-group span-full">
                    <label>Payment Status</label>
                    <select name="status" id="edit_status" class="field" required>
                        <option value="unpaid">Unpaid</option>
                        <option value="paid" hidden disabled>Paid</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="deleted">Deleted</option>
                    </select>
                </div>
                <div class="form-group span-full">
                    <?= render_water_class_field(null, true, 'editClassId', 'class_id', 'field', 'Class of the change') ?>
                </div>
            </div>
            <div class="form-footer">
                <button type="button" class="btn btn-ghost" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
</div>

<div class="overlay" id="batchEditModal" onclick="handleOverlayClick(event,'batchEditModal')">
<div class="modal">
    <div class="modal-head">
        <span class="modal-title">Batch Edit Bills</span>
        <button class="modal-close" type="button" onclick="closeBatchEditModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="modal-body">
        <div class="info-box">
            Editing <strong id="beSelectedCount">0</strong> selected bill(s). Changes apply to all selected records.
        </div>
        <div class="form-grid">
            <div class="form-group span-full">
                <label>New Payment Status <span style="color:var(--red);">*</span></label>
                <select id="be_status" class="field" required>
                    <option value="unpaid">Unpaid</option>
                    <option value="cancelled">Cancelled</option>
                        <option value="deleted">Deleted</option>
                </select>
            </div>
            <div class="form-group span-full">
                <label class="no-due-option" for="be_clear_due">
                    <input type="checkbox" id="be_clear_due" value="1">
                    <span><strong>Remove Due Date</strong><span>Selected bills will keep their balances but will have no due date, no overdue status, and no date-based disconnection date.</span></span>
                </label>
            </div>
            <div class="form-group">
                <label>New Due Date <span style="color:var(--text-3); font-weight:400; text-transform:none;">(optional)</span></label>
                <input type="date" id="be_due" class="field">
                <span class="field-hint">Leave blank to keep each bill's existing due date.</span>
            </div>
            <div class="form-group">
                <label>New Term (Days)</label>
                <input type="number" id="be_term" class="field mono" readonly>
                <span class="field-hint">Calculated separately from each bill's issue date when applied.</span>
            </div>
            <div class="form-group span-full">
                <?= render_water_class_field(null, true, 'be_class', 'be_class_ui', 'field', 'Class of the change') ?>
            </div>
        </div>
        <div class="form-footer">
            <button type="button" class="btn btn-ghost" onclick="closeBatchEditModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitBatchEdit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Apply to Selected
            </button>
        </div>
    </div>
</div>
</div>

<script>
function isoDateToUtc(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
    const [year, month, day] = value.split('-').map(Number);
    const stamp = Date.UTC(year, month - 1, day);
    const date = new Date(stamp);
    return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
        ? date
        : null;
}

function utcDateToIso(date) {
    return date.toISOString().slice(0, 10);
}

function dateSync(dateId, termId, dueId, noDueId) {
    const issueEl = document.getElementById(dateId);
    const termEl = document.getElementById(termId);
    const dueEl = document.getElementById(dueId);
    const noDueEl = document.getElementById(noDueId);

    function push() {
        if (noDueEl.checked) return;
        const issue = isoDateToUtc(issueEl.value);
        const days = Number.parseInt(termEl.value, 10);
        if (!issue || !Number.isInteger(days) || days < 0) return;
        issue.setUTCDate(issue.getUTCDate() + days);
        dueEl.value = utcDateToIso(issue);
    }

    function pull() {
        if (noDueEl.checked) return;
        const issue = isoDateToUtc(issueEl.value);
        const due = isoDateToUtc(dueEl.value);
        if (!issue || !due) return;
        const days = Math.round((due.getTime() - issue.getTime()) / 86400000);
        termEl.value = days >= 0 ? String(days) : '';
    }

    function toggleNoDueDate() {
        const disabled = noDueEl.checked;
        termEl.disabled = disabled;
        dueEl.disabled = disabled;
        termEl.required = !disabled;
        dueEl.required = !disabled;
        if (disabled) {
            termEl.value = '';
            dueEl.value = '';
        } else {
            if (termEl.value === '') termEl.value = '7';
            push();
        }
    }

    issueEl.addEventListener('change', push);
    termEl.addEventListener('input', push);
    dueEl.addEventListener('change', pull);
    noDueEl.addEventListener('change', toggleNoDueDate);
    toggleNoDueDate();
}
dateSync('add_date',  'add_term',  'add_due',  'add_no_due');
dateSync('b_date',    'b_term',    'b_due',    'b_no_due');
dateSync('edit_date', 'edit_term', 'edit_due', 'edit_no_due');

/* ── Issue Individual Charge: the charges ticked, each with its amount ─────────────────── */
const chargeRows = Array.from(document.querySelectorAll('#chargeList .charge-row'));
const pesoFmt = (n) => '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function chargeRowAmount(row) {
    const amt = row.querySelector('.charge-amt-input');
    if (row.dataset.inventory === '1') {
        const qty = parseFloat(row.querySelector('.charge-qty-input').value) || 0;
        amt.value = (Math.round((parseFloat(row.dataset.price) || 0) * qty * 100) / 100).toFixed(2);
    }
    return parseFloat(amt.value) || 0;
}

function setChargeOn(row, on) {
    row.classList.toggle('is-on', on);
    row.querySelectorAll('.charge-fields input').forEach(i => { i.disabled = !on; i.required = on; });
    if (on) {
        const first = row.querySelector(row.dataset.inventory === '1' ? '.charge-qty-input' : '.charge-amt-input');
        if (first) { first.focus(); first.select(); }
    }
    updateChargeSummary();
}

function updateChargeSummary() {
    const on = chargeRows.filter(r => r.querySelector('.charge-on').checked);
    let total = 0;
    on.forEach(r => { total += chargeRowAmount(r); });
    const summary = document.getElementById('chargeSummary');
    const btn = document.getElementById('postChargesBtn');
    if (!on.length) {
        summary.innerHTML = 'No charge ticked yet.';
        btn.textContent = 'Post Charge';
    } else {
        const names = on.map(r => _esc(r.querySelector('.charge-name strong').textContent)).join(', ');
        summary.innerHTML = `<span>${on.length} ${on.length === 1 ? 'charge' : 'charges'}: ${names}</span><strong>${pesoFmt(total)}</strong>`;
        btn.textContent = on.length === 1 ? 'Post Charge' : `Post ${on.length} Charges`;
    }
}

function filterCharges() {
    const q = (document.getElementById('chargeFind').value || '').trim().toLowerCase();
    let shown = 0;
    chargeRows.forEach(r => {
        // a ticked charge stays in view
        const show = !q || r.dataset.search.includes(q) || r.querySelector('.charge-on').checked;
        r.hidden = !show;
        if (show) shown++;
    });
    document.getElementById('chargeEmpty').hidden = shown > 0;
}

chargeRows.forEach(row => {
    row.querySelector('.charge-on').addEventListener('change', e => setChargeOn(row, e.target.checked));
    row.querySelectorAll('.charge-fields input').forEach(i => i.addEventListener('input', updateChargeSummary));
});
document.getElementById('chargeFind').addEventListener('input', filterCharges);
document.getElementById('chargeFind').addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });

function validateCharges() {
    const on = chargeRows.filter(r => r.querySelector('.charge-on').checked);
    if (!on.length) {
        alert('Tick at least one charge and enter its amount.');
        document.getElementById('chargeFind').focus();
        return false;
    }
    for (const r of on) {
        const name = r.querySelector('.charge-name strong').textContent;
        if (r.dataset.inventory === '1') {
            const qtyInput = r.querySelector('.charge-qty-input');
            const qty = parseFloat(qtyInput.value) || 0;
            const stock = parseFloat(r.dataset.stock) || 0;
            if (qty <= 0) { alert(`${name}: the quantity must be greater than zero.`); qtyInput.focus(); return false; }
            if (qty > stock) { alert(`${name}: not enough inventory quantity balance. Available: ${stock.toFixed(2)}`); qtyInput.focus(); return false; }
        }
        const amt = r.querySelector('.charge-amt-input');
        if (chargeRowAmount(r) <= 0) { alert(`${name}: enter an amount greater than zero.`); amt.focus(); return false; }
    }
    return true;
}

/* the member's privileges: the discount rules of each bill code decide them when the charges are posted */
function calcIndDiscount() {
    const memberSel = document.getElementById('ind_member_id');
    const section   = document.getElementById('ind_discount_section');
    const display   = document.getElementById('ind_discount_display');
    let discounts = [];
    if (memberSel.value) {
        try { discounts = JSON.parse(memberSel.options[memberSel.selectedIndex].dataset.discounts || '[]'); } catch (e) { discounts = []; }
    }
    if (!discounts.length) {
        section.style.display = 'none';
        display.innerHTML = '';
        return;
    }
    section.style.display = '';
    display.innerHTML = `<strong>${discounts.map(d => _esc(d.type)).join(', ')}</strong> applies to each charge whose bill code allows member discounts, by the Discount Privilege Rules; the posted bill shows the amount.`;
}

function _esc(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}

function openAddModal() {
    document.getElementById('addForm').reset();
    chargeRows.forEach(r => { r.hidden = false; r.querySelector('.charge-amt-input').value = r.dataset.price; setChargeOn(r, false); });
    document.getElementById('chargeEmpty').hidden = true;
    const today = new Date();
    document.getElementById('add_date').valueAsDate = today;
    document.getElementById('add_no_due').checked = false;
    document.getElementById('add_no_due').dispatchEvent(new Event('change'));
    document.getElementById('add_term').value = 7;
    document.getElementById('add_term').dispatchEvent(new Event('input'));
    calcIndDiscount();
    updateChargeSummary();
    document.getElementById('addModal').classList.add('open');
}
function closeAddModal() { document.getElementById('addModal').classList.remove('open'); }

function autoFillBatchAmount() {
    const sel = document.getElementById('batch_bill_code_id');
    if (!sel.value) return;
    const opt = sel.options[sel.selectedIndex];
    const amt = parseFloat(opt.getAttribute('data-amount')) || 0;
    const type = opt.getAttribute('data-type') || '';

    if (type === 'Inventory') {
        alert('Inventory bill codes deduct stock. For accurate quantity deduction, use Individual Billing or update the batch process to include quantity per member.');
    }

    document.getElementById('batch_base_amount').value = amt.toFixed(2);
    updateBatchPrices();
}

function updateBatchPrices() {
    const base = parseFloat(document.getElementById('batch_base_amount').value) || 0;
    document.querySelectorAll('#batchBody .b-row').forEach(row => {
        const rate  = parseFloat(row.getAttribute('data-discount')) || 0;
        const final = base - (base * (rate / 100));
        row.querySelector('.final-price').textContent = '₱ ' + final.toFixed(2);
    });
}

const batchStreetsByCity = <?= json_encode($streetsByCity, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const batchAllStreets = <?= json_encode($allStreets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function populateBatchStreetOptions(cityValue) {
    const streetSelect = document.getElementById('f_street');
    streetSelect.innerHTML = '';

    if (!cityValue) {
        streetSelect.appendChild(new Option('-- Select a City First --', ''));
        streetSelect.disabled = true;
        return;
    }

    const streets = cityValue === 'all'
        ? batchAllStreets
        : (batchStreetsByCity[String(cityValue).toLowerCase()] || []);

    streetSelect.disabled = false;
    streetSelect.appendChild(new Option(
        cityValue === 'all' ? 'All Streets' : 'All Streets in Selected City',
        'all'
    ));

    streets.forEach(street => {
        const count = Number(street.member_count || 0);
        streetSelect.appendChild(new Option(`${street.label} (${count})`, street.value));
    });

    streetSelect.value = 'all';
}

function onCityChange() {
    const city = document.getElementById('f_city').value;
    populateBatchStreetOptions(city);
    fetchBatchMembers();
}

function onStreetChange() {
    fetchBatchMembers();
}

async function fetchBatchMembers() {
    const city   = document.getElementById('f_city').value;
    const street = document.getElementById('f_street').value;
    const tbody  = document.getElementById('batchBody');
    const q      = document.getElementById('f_search').value.toLowerCase().trim();

    if (!city && !street) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-3);">Select a city, then optionally choose a street to load members.</td></tr>';
        updateCount();
        return;
    }

    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">Loading members…</td></tr>';

    try {
        const fetchCity   = city   === 'all' ? 'all' : encodeURIComponent(city);
        const fetchStreet = street === 'all' ? 'all' : encodeURIComponent(street);

        const response = await fetch(`../process/getMembersByStreet.php?context=one_time_billing&city=${fetchCity}&street=${fetchStreet}`);
        const members  = await response.json();

        if (members.error) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:red; padding:20px;">DB Error<br>${members.error}</td></tr>`;
            return;
        }

        tbody.innerHTML = '';
        let count = 0;

        members.forEach(mem => {
            if (q && !mem.full_name.toLowerCase().includes(q)) return;
            count++;

            const discounts   = Array.isArray(mem.discounts) ? mem.discounts : [];
            const legacyRate  = parseFloat(mem.discount_rate) || 0;
            const bestRate    = discounts.length > 0
                ? Math.max(...discounts.map(d => parseFloat(d.rate) || 0))
                : legacyRate;

            const tr = document.createElement('tr');
            tr.className = 'b-row';
            tr.setAttribute('data-name',     mem.full_name.toLowerCase());
            tr.setAttribute('data-discount',  bestRate);

            let discountHtml;
            if (discounts.length === 0 && legacyRate === 0) {
                discountHtml = `<span style="color:var(--text-3); font-size:.75rem;">None</span>`;
            } else if (discounts.length === 1) {
                discountHtml = `<span class="badge badge-amber" title="${discounts[0].type}">−${discounts[0].rate}%</span>`;
            } else if (discounts.length > 1) {
                const badges = discounts.map(d =>
                    `<span class="badge badge-amber" title="${d.type}" style="margin-right:3px;">−${d.rate}%</span>`
                ).join('');
                discountHtml = `<div style="display:flex; flex-wrap:wrap; gap:3px;">${badges}
                    <span style="font-size:.68rem; color:var(--text-3); align-self:center; margin-left:2px;">best applied</span>
                    </div>`;
            } else {
                discountHtml = `<span class="badge badge-amber">−${legacyRate}%</span>`;
            }

            tr.innerHTML = `
                <td><input type="checkbox" name="member_ids[]" value="${mem.pkey}" class="b-cb" onchange="updateCount()"></td>
                <td class="mono">${mem.pkey}</td>
                <td style="font-weight:600; white-space:normal; min-width:140px;">${mem.full_name}</td>
                <td>${discountHtml}</td>
                <td class="final-price mono" style="font-weight:700; text-align:right;">₱ 0.00</td>
            `;
            tbody.appendChild(tr);
        });

        if (count === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">No members found matching your search.</td></tr>';
        }

        document.getElementById('selectAll').checked = false;
        updateCount();
        updateBatchPrices();

    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:red;">Failed to fetch members. Check console.</td></tr>';
        console.error(err);
    }
}

function filterFetchedMembers() {
    const q = document.getElementById('f_search').value.toLowerCase().trim();
    document.querySelectorAll('.b-row').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
    document.getElementById('selectAll').checked = false;
    updateCount();
}

function toggleAll(src) {
    document.querySelectorAll('.b-row').forEach(row => {
        if (row.style.display !== 'none') {
            row.querySelector('.b-cb').checked = src.checked;
        }
    });
    updateCount();
}

function updateCount() {
    const count = [...document.querySelectorAll('.b-cb:checked')]
        .filter(cb => cb.closest('tr').style.display !== 'none').length;
    document.getElementById('batchCount').textContent = count;
}

function openBatchModal() {
    document.getElementById('batchForm').reset();
    document.getElementById('f_search').value = '';
    document.getElementById('f_city').selectedIndex = 0;
    populateBatchStreetOptions('');
    document.getElementById('selectAll').checked = false;
    document.getElementById('batchBody').innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:var(--text-3);">Select a city, then optionally choose a street to load members.</td></tr>';
    const today = new Date();
    document.getElementById('b_date').valueAsDate = today;
    document.getElementById('b_no_due').checked = false;
    document.getElementById('b_no_due').dispatchEvent(new Event('change'));
    document.getElementById('b_term').value = 7;
    document.getElementById('b_term').dispatchEvent(new Event('input'));
    updateCount();
    document.getElementById('batchModal').classList.add('open');
}
function closeBatchModal() { document.getElementById('batchModal').classList.remove('open'); }

// the bill as it was opened: a new charge category or date issued issues it again (refused once anything is collected)
let editBillOriginal = null;
const OTB_CAN_REISSUE = <?= $otbCanReissue ? 'true' : 'false' ?>;

function editSelectedCode() {
    const sel = document.getElementById('edit_code');
    return sel.options[sel.selectedIndex] || null;
}

function syncEditCategory(refill) {
    const opt = editSelectedCode();
    const isInv = !!opt && opt.dataset.inventory === '1';
    const price = opt ? (parseFloat(opt.dataset.price) || 0) : 0;
    const qtyGroup = document.getElementById('edit_inventory_qty_group');
    const qtyInput = document.getElementById('edit_quantity');
    const amount = document.getElementById('edit_amount');
    const hint = document.getElementById('edit_inventory_stock_hint');
    const changed = !!editBillOriginal && opt && Number(opt.value) !== editBillOriginal.codeId;
    qtyGroup.style.display = isInv ? '' : 'none';
    qtyInput.required = isInv;
    if (isInv) {
        if (changed && refill) qtyInput.value = '1.00';
        const stock = opt ? (parseFloat(opt.dataset.stock) || 0) : 0;
        const back = editBillOriginal && !changed && editBillOriginal.status !== 'cancelled' ? editBillOriginal.qty : 0;
        hint.textContent = changed
            ? `In stock: ${stock.toFixed(2)} at ₱${price.toFixed(2)} each. The amount is the quantity times the sales price.`
            : `Available balance for adjustment: ${(stock + back).toFixed(2)}. Increasing quantity will deduct more stock. Decreasing/cancelling will return stock.`;
    } else {
        qtyInput.value = '1';
    }
    if (refill && changed) {
        if (isInv) amount.value = (price * (parseFloat(qtyInput.value) || 1)).toFixed(2);
        else if (price > 0) amount.value = price.toFixed(2);
    } else if (refill && editBillOriginal) {
        // back on the bill's own category: its own amount and quantity again
        amount.value = editBillOriginal.amount;
        if (isInv) qtyInput.value = editBillOriginal.qtyText;
    }
    updateReissueNote();
}

function updateReissueNote() {
    const note = document.getElementById('edit_reissue_note');
    if (!editBillOriginal) return;
    const opt = editSelectedCode();
    const codeChanged = !!opt && Number(opt.value) !== editBillOriginal.codeId;
    const dateChanged = document.getElementById('edit_date').value !== editBillOriginal.date;
    if (editBillOriginal.locked) {
        note.className = 'edit-reissue-note is-locked';
        note.textContent = editBillOriginal.lockReason;
    } else if (codeChanged || dateChanged) {
        note.className = 'edit-reissue-note is-changed';
        note.textContent = 'Saving issues the bill again with the '
            + (codeChanged && dateChanged ? 'new charge category and date issued' : (codeChanged ? 'new charge category' : 'new date issued'))
            + ': its invoice is voided and the corrected one is posted.';
    } else {
        note.className = 'edit-reissue-note';
        note.textContent = 'A new charge category or date issued issues the bill again: its invoice is voided and the corrected one is posted on the date issued, while nothing is collected on the bill.';
    }
}

function validateEditBill() {
    if (!editBillOriginal) return true;
    const opt = editSelectedCode();
    const changed = (!!opt && Number(opt.value) !== editBillOriginal.codeId) || document.getElementById('edit_date').value !== editBillOriginal.date;
    if (changed && document.getElementById('edit_status').value !== 'unpaid') {
        alertBox('Keep the status Unpaid while the charge category or the date issued changes. Cancel the bill afterwards if needed.');
        return false;
    }
    return true;
}

function alertBox(msg) {
    const note = document.getElementById('edit_reissue_note');
    note.className = 'edit-reissue-note is-locked';
    note.textContent = msg;
    note.scrollIntoView({ block: 'nearest' });
}

function openEditModal(id, name, code, amount, date, term, due, status, billType = '', quantity = 1, salesPrice = 0, currentQty = 0, classId = 0, billCodeId = 0, amountPaid = 0, discountAmount = 0) {
    document.getElementById('edit_id').value     = id;
    // the class starts on the bill's own class (the last class used when the bill has none)
    const editClass = document.getElementById('editClassId');
    if (editClass && Number(classId) > 0 && editClass.querySelector('option[value="' + Number(classId) + '"]')) {
        editClass.value = String(Number(classId));
        editClass.dispatchEvent(new Event('change'));
    }
    document.getElementById('edit_name').value   = name;
    const codeSel = document.getElementById('edit_code');
    codeSel.value = String(Number(billCodeId));
    document.getElementById('edit_amount').value = amount;
    document.getElementById('edit_date').value   = (date || '').substring(0, 10);
    const paid = parseFloat(amountPaid) || 0;
    const locked = paid > 0.004 || status !== 'unpaid' || !OTB_CAN_REISSUE;
    editBillOriginal = {
        codeId: Number(billCodeId), date: (date || '').substring(0, 10), status: status, qty: parseFloat(quantity) || 0, locked: locked,
        amount: String(amount), qtyText: parseFloat(quantity || 1).toFixed(2),
        lockReason: !OTB_CAN_REISSUE
            ? 'Changing the charge category or the date issued needs the Edit right on One-Time Billing.'
            : (paid > 0.004
                ? 'Payments are recorded on this bill: its charge category and date issued stay as they are.'
                : 'The charge category and the date issued change on an unpaid bill only.'),
    };
    codeSel.disabled = locked;
    document.getElementById('edit_date').readOnly = locked;
    const noDue = !due;
    document.getElementById('edit_no_due').checked = noDue;
    document.getElementById('edit_no_due').dispatchEvent(new Event('change'));
    document.getElementById('edit_term').value   = noDue ? '' : term;
    document.getElementById('edit_due').value    = noDue ? '' : due;

    document.getElementById('edit_quantity').value = parseFloat(quantity || 1).toFixed(2);
    // the amount is before the member discount: the discount is worked out again on saving
    const amountHint = document.getElementById('edit_amount_hint');
    const disc = parseFloat(discountAmount) || 0;
    amountHint.hidden = disc <= 0.004;
    amountHint.textContent = disc > 0.004 ? `Before the member discount (₱${disc.toFixed(2)} off on this bill); the discount is applied again on saving.` : '';
    syncEditCategory(false);

    const sel = document.getElementById('edit_status');
    // a paid bill shows Paid (it is never chosen by hand: a bill is paid through Collections)
    const paidOpt = sel.querySelector('option[value="paid"]');
    paidOpt.hidden = paidOpt.disabled = status !== 'paid';
    for (let i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = (sel.options[i].value === status);
    }
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() { document.getElementById('editModal').classList.remove('open'); }
document.getElementById('edit_code').addEventListener('change', () => syncEditCategory(true));
document.getElementById('edit_date').addEventListener('change', updateReissueNote);
document.getElementById('edit_quantity').addEventListener('input', () => {
    const opt = editSelectedCode();
    if (opt && opt.dataset.inventory === '1' && editBillOriginal && Number(opt.value) !== editBillOriginal.codeId) {
        document.getElementById('edit_amount').value = ((parseFloat(opt.dataset.price) || 0) * (parseFloat(document.getElementById('edit_quantity').value) || 0)).toFixed(2);
    }
});

function toggleLedgerAll(src) {
    document.querySelectorAll('.ledger-cb').forEach(cb => {
        const row = cb.closest('tr');
        if (row.style.display !== 'none') cb.checked = src.checked;
    });
    updateLedgerSelection();
}

function updateLedgerSelection() {
    const checked = [...document.querySelectorAll('.ledger-cb:checked')];
    const count   = checked.length;

    document.getElementById('ledgerSelCount').textContent = count;

    const toolbar = document.getElementById('ledgerBatchToolbar');
    if (count > 0) {
        toolbar.classList.add('visible');
    } else {
        toolbar.classList.remove('visible');
        document.getElementById('ledgerSelectAll').checked = false;
    }

    document.querySelectorAll('.ledger-row').forEach(row => {
        const cb = row.querySelector('.ledger-cb');
        row.classList.toggle('row-selected', cb && cb.checked);
    });
}

function clearLedgerSelection() {
    document.querySelectorAll('.ledger-cb').forEach(cb => cb.checked = false);
    document.getElementById('ledgerSelectAll').checked = false;
    updateLedgerSelection();
}

function openBatchEditModal() {
    const count = document.querySelectorAll('.ledger-cb:checked').length;
    if (count === 0) { alert('Please select at least one bill to edit.'); return; }
    document.getElementById('beSelectedCount').textContent = count;
    document.getElementById('be_status').value = 'unpaid';
    document.getElementById('be_due').value    = '';
    document.getElementById('be_term').value   = '';
    document.getElementById('be_clear_due').checked = false;
    document.getElementById('be_clear_due').dispatchEvent(new Event('change'));
    document.getElementById('batchEditModal').classList.add('open');
}
function closeBatchEditModal() { document.getElementById('batchEditModal').classList.remove('open'); }

function syncBatchEditTerm() { }
document.addEventListener('DOMContentLoaded', () => {
    const beDue = document.getElementById('be_due');
    const beTerm = document.getElementById('be_term');
    const clearDue = document.getElementById('be_clear_due');

    function syncBatchDueControls() {
        const clearing = clearDue.checked;
        beDue.disabled = clearing;
        if (clearing) {
            beDue.value = '';
            beTerm.value = '';
        }
    }

    beDue.addEventListener('change', () => {
        beTerm.value = '';
    });
    clearDue.addEventListener('change', syncBatchDueControls);
    syncBatchDueControls();
});

function submitBatchEdit() {
    const status = document.getElementById('be_status').value;
    if (!status) { alert('Please select a status.'); return; }

    const selectedIds = [...document.querySelectorAll('.ledger-cb:checked')].map(cb => cb.value);
    if (selectedIds.length === 0) { alert('No bills selected.'); return; }

    const beClass = document.getElementById('be_class');
    if (beClass && !beClass.value) { AppDialog.alert('Choose the class of the change: it is required, and the journal entries carry it.', { title: 'Class required' }); beClass.focus(); return; }
    document.getElementById('be_class_hidden').value = beClass ? beClass.value : '';

    const due = document.getElementById('be_due').value;
    const clearDue = document.getElementById('be_clear_due').checked;
    const term = '';

    const idsContainer = document.getElementById('batchEditIds');
    idsContainer.innerHTML = '';
    selectedIds.forEach(id => {
        const inp  = document.createElement('input');
        inp.type   = 'hidden';
        inp.name   = 'bill_ids[]';
        inp.value  = id;
        idsContainer.appendChild(inp);
    });
    document.getElementById('be_status_hidden').value = status;
    document.getElementById('be_due_hidden').value = due;
    document.getElementById('be_term_hidden').value = term;
    document.getElementById('be_clear_due_hidden').value = clearDue ? '1' : '0';

    document.getElementById('batchEditFormHidden').submit();
}

function confirmBatchDelete() {
    const selectedIds = [...document.querySelectorAll('.ledger-cb:checked')].map(cb => cb.value);
    if (selectedIds.length === 0) { alert('Please select at least one bill to delete.'); return; }

    const msg = `Permanently delete ${selectedIds.length} selected bill(s)? This cannot be undone.`;
    AppDialog.confirm(msg, { title: 'Delete bills', ok: 'Delete', tone: 'danger' }).then((yes) => { if (yes) sendBatchDelete(selectedIds); });
}

function sendBatchDelete(selectedIds) {
    const container = document.getElementById('batchDeleteIds');
    container.innerHTML = '';
    selectedIds.forEach(id => {
        const inp  = document.createElement('input');
        inp.type   = 'hidden';
        inp.name   = 'bill_ids[]';
        inp.value  = id;
        container.appendChild(inp);
    });
    document.getElementById('batchDeleteForm').submit();
}

function confirmDelete(id) {
    AppDialog.confirm('Delete this bill? This cannot be undone.', { title: 'Delete bill', ok: 'Delete', tone: 'danger' }).then((yes) => {
        if (!yes) return;
        document.getElementById('delete_bill_id').value = id;
        document.getElementById('deleteForm').submit();
    });
}

function filterLedger(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.ledger-row').forEach(r => {
        r.style.display = (r.dataset.name.includes(q) || r.dataset.accnt.includes(q)) ? '' : 'none';
    });
}

function handleOverlayClick(e, id) {
    if (e.target === document.getElementById(id)) {
        document.getElementById(id).classList.remove('open');
    }
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ['addModal','batchModal','editModal','batchEditModal'].forEach(id =>
            document.getElementById(id).classList.remove('open')
        );
    }
});
</script>
</body>
</html>