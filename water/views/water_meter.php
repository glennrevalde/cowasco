<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once __DIR__ . '/../common/waterRateBillingHelpers.php';
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';
require_once __DIR__ . '/../common/waterRateGroupHelpers.php';

$pdo = getDB();
if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$rateCodes = [];
$pipeSizes = [];
$activePipeSizes = [];
$rates = [];
$groups = [];
$memberCounts = [];
$loadErrors = [];
$globalBillCode = null;
$globalBillCodeId = null;
$globalBillCodeError = null;
$billCodeProvisioningNotice = null;
$csrf = csrfToken();
$tokens = [
    'createGroup' => operationToken('create_water_rate_group'),
    'updateGroup' => operationToken('update_water_rate_group'),
    'deleteGroup' => operationToken('delete_water_rate_group'),
    'addPipeSize' => operationToken('add_water_pipe_size'),
    'editPipeSize' => operationToken('edit_water_pipe_size'),
    'deletePipeSize' => operationToken('delete_water_pipe_size'),
];

try {
    $rateCodes = $pdo->query('SELECT rc_id, rc_code, rc_name FROM rate_code ORDER BY rc_code ASC, rc_name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('[water_meter rate codes] ' . $ex->getMessage());
    $loadErrors[] = 'Rate codes could not be loaded.';
}

try {
    $pipeSizes = $pdo->query("\n        SELECT ps.*,\n               (SELECT COUNT(*) FROM water_accounts m WHERE m.pipe_size_id = ps.pipe_size_id) AS member_count,\n               (SELECT COUNT(*) FROM water_meter_members w WHERE w.pipe_size_id = ps.pipe_size_id) AS tier_count\n        FROM water_pipe_sizes ps\n        ORDER BY CASE WHEN ps.diameter_inches IS NULL THEN 1 ELSE 0 END,\n                 ps.diameter_inches ASC, ps.size_label ASC, ps.pipe_size_id ASC\n    ")->fetchAll(PDO::FETCH_ASSOC);
    $activePipeSizes = array_values(array_filter($pipeSizes, static function (array $row): bool {
        return (int)($row['is_active'] ?? 0) === 1;
    }));
} catch (Throwable $ex) {
    error_log('[water_meter pipe sizes] ' . $ex->getMessage());
    $loadErrors[] = 'Pipe sizes could not be loaded. Run the pipe-size database migration first.';
}

try {
    $provisioning = water_bill_code_ensure(
        $pdo,
        (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0)
    );
    $globalBillCode = water_billing_get_global_bill_code($pdo);
    $globalBillCodeId = (int)$globalBillCode['code_id'];
    if (!empty($provisioning['created'])) {
        $billCodeProvisioningNotice = 'WATER BILL was not found, so the system created and configured it automatically.';
    } elseif (!empty($provisioning['updated'])) {
        $billCodeProvisioningNotice = 'The WATER BILL setup was completed automatically.';
    }
} catch (Throwable $ex) {
    error_log('[water_meter water bill provisioning] ' . $ex->getMessage());
    $globalBillCodeError = $ex->getMessage();
}

try {
    $rates = $pdo->query("\n        SELECT w.wmm_id, w.rc_id, w.pipe_size_id, w.from_cb, w.to_cb, w.amount, w.bill_type,\n               w.created_by, w.created_at, w.updated_at,\n               r.rc_code, r.rc_name, r.billing_method,\n               ps.size_code AS pipe_size_code, ps.size_label AS pipe_size_label,\n               ps.diameter_inches, ps.is_active AS pipe_size_is_active\n        FROM water_meter_members w\n        INNER JOIN rate_code r ON r.rc_id = w.rc_id\n        INNER JOIN water_pipe_sizes ps ON ps.pipe_size_id = w.pipe_size_id\n        ORDER BY r.rc_code ASC, r.rc_name ASC,\n                 CASE WHEN ps.diameter_inches IS NULL THEN 1 ELSE 0 END,\n                 ps.diameter_inches ASC, ps.size_label ASC, w.from_cb ASC, w.wmm_id ASC\n    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rates as $tier) {
        $key = water_rate_group_key((int)$tier['rc_id'], (int)$tier['pipe_size_id']);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'rc_id' => (int)$tier['rc_id'],
                'rc_code' => (string)$tier['rc_code'],
                'rc_name' => (string)$tier['rc_name'],
                'billing_method' => water_rate_billing_method($tier['billing_method'] ?? 'straight'),
                'pipe_size_id' => (int)$tier['pipe_size_id'],
                'pipe_size_code' => (string)$tier['pipe_size_code'],
                'pipe_size_label' => (string)$tier['pipe_size_label'],
                'diameter_inches' => $tier['diameter_inches'],
                'pipe_size_is_active' => (int)$tier['pipe_size_is_active'],
                'tiers' => [],
            ];
        }
        $groups[$key]['tiers'][] = [
            'wmm_id' => (int)$tier['wmm_id'],
            'from_cb' => (int)$tier['from_cb'],
            'to_cb' => $tier['to_cb'] === null ? null : (int)$tier['to_cb'],
            'amount' => (string)$tier['amount'],
            'bill_type' => (string)$tier['bill_type'],
        ];
    }

    $countStmt = $pdo->query("\n        SELECT rc_id, pipe_size_id, COUNT(*) AS member_count\n        FROM water_accounts\n        GROUP BY rc_id, pipe_size_id\n    ");
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $memberCounts[water_rate_group_key((int)$row['rc_id'], (int)$row['pipe_size_id'])] = (int)$row['member_count'];
    }

    foreach ($groups as $key => &$group) {
        $group['status'] = water_rate_get_configuration_status($group['tiers']);
        $group['member_count'] = $memberCounts[$key] ?? 0;
    }
    unset($group);
} catch (Throwable $ex) {
    error_log('[water_meter tariff groups] ' . $ex->getMessage());
    $loadErrors[] = 'Water-rate tariff groups could not be loaded.';
}

$requestedTab = strtolower(trim((string)($_GET['tab'] ?? 'rates')));
if (!in_array($requestedTab, ['rates', 'pipe-sizes'], true)) {
    $requestedTab = 'rates';
}

$completeGroupCount = 0;
foreach ($groups as $group) {
    if (($group['status']['complete'] ?? false) === true) { $completeGroupCount++; }
}
$groupPayload = array_values(array_map(static function (array $group): array {
    return [
        'key' => $group['key'],
        'rc_id' => $group['rc_id'],
        'rc_code' => $group['rc_code'],
        'rc_name' => $group['rc_name'],
        'pipe_size_id' => $group['pipe_size_id'],
        'pipe_size_code' => $group['pipe_size_code'],
        'pipe_size_label' => $group['pipe_size_label'],
        'member_count' => $group['member_count'],
        'tiers' => $group['tiers'],
    ];
}, $groups));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Water Rate Tariff Groups - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="../common/fonts.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}:root{--white:#fff;--off-white:#f8f9fb;--border:#e5e7eb;--border-strong:#d1d5db;--text:#111827;--muted:#6b7280;--light:#9ca3af;--accent:#1a1a2e;--accent-soft:#f1f2f7;--success:#047857;--success-bg:#ecfdf5;--warning:#b45309;--warning-bg:#fffbeb;--danger:#b91c1c;--danger-bg:#fef2f2;--info:#1d4ed8;--info-bg:#eff6ff;--radius:12px;--radius-sm:8px}body{font-family:'Sora',sans-serif;background:var(--off-white);color:var(--text);min-height:100vh}.page{max-width:1380px;margin:0 auto;padding:28px}.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}.page-title{font-size:1.55rem;font-weight:700;margin:7px 0 5px}.page-subtitle{font-size:.86rem;color:var(--muted);line-height:1.6;max-width:900px}.back-link{color:var(--muted);text-decoration:none;font-size:.8rem;font-weight:600}.tabs{display:flex;gap:20px;border-bottom:2px solid var(--border);margin-bottom:24px;overflow:auto}.tab-btn{background:none;border:0;padding:14px 3px;font:600 .86rem 'Sora',sans-serif;color:var(--light);cursor:pointer;position:relative;white-space:nowrap}.tab-btn.active{color:var(--accent)}.tab-btn.active::after{content:'';position:absolute;left:0;right:0;bottom:-2px;height:2px;background:var(--accent)}.tab-panel{display:none}.tab-panel.active{display:block}.card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:22px;box-shadow:0 1px 3px rgba(0,0,0,.035)}.card-title{font-size:1.05rem;font-weight:700;margin-bottom:7px}.card-subtitle{font-size:.8rem;color:var(--muted);line-height:1.55;margin-bottom:18px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.form-group.full{grid-column:1/-1}.form-label{display:block;font-size:.71rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--muted);margin-bottom:6px}.form-input{width:100%;border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;font:inherit;font-size:.84rem;background:#fff;outline:none}.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(26,26,46,.06)}.form-input[readonly]{background:#f9fafb;color:var(--muted)}.mono{font-family:'JetBrains Mono',monospace}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 16px;border-radius:var(--radius-sm);border:1px solid transparent;font:700 .8rem 'Sora',sans-serif;text-decoration:none;cursor:pointer}.btn:disabled{opacity:.48;cursor:not-allowed}.btn-primary{background:var(--accent);color:#fff}.btn-secondary{background:#fff;color:var(--accent);border-color:var(--border-strong)}.btn-danger{background:#fff;color:var(--danger);border-color:#fecaca}.btn-small{padding:8px 11px;font-size:.74rem}.alert,.live-status{padding:12px 14px;border-radius:var(--radius-sm);font-size:.81rem;line-height:1.55;margin-bottom:16px}.alert-success,.live-status.ok{background:var(--success-bg);border:1px solid #a7f3d0;color:var(--success)}.alert-warning,.live-status.warning{background:var(--warning-bg);border:1px solid #fde68a;color:#92400e}.alert-danger,.live-status.error{background:var(--danger-bg);border:1px solid #fecaca;color:var(--danger)}.info-note{background:var(--info-bg);border:1px solid #bfdbfe;color:#1e40af;padding:12px 14px;border-radius:var(--radius-sm);font-size:.8rem;line-height:1.55;margin-bottom:16px}.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:22px}.summary-item{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px}.summary-item strong{display:block;font-size:1.18rem}.summary-item span{font-size:.72rem;color:var(--muted)}.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:10px}table{width:100%;border-collapse:collapse;font-size:.8rem}th{background:#f9fafb;color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:10px 11px;border-bottom:1px solid var(--border);white-space:nowrap}td{padding:10px 11px;border-bottom:1px solid var(--border);vertical-align:middle}tr:last-child td{border-bottom:0}.tier-editor-table input,.tier-editor-table select{min-width:100px}.tier-editor-table .range-from{font-weight:700}.tier-to-cell{min-width:190px}.tier-to-control{display:flex;align-items:center;gap:9px}.tier-infinity-wrap{display:none;align-items:center;gap:5px;white-space:nowrap;font-size:.72rem;font-weight:700;color:var(--muted)}.tier-infinity-wrap.visible{display:inline-flex}.tier-infinity-wrap input{min-width:auto}.tier-to:disabled{background:#f3f4f6;color:var(--muted)}.tier-editor-row.invalid-row td{background:#fffaf0}.tier-editor-table .remove-tier{width:32px;height:32px;border-radius:7px;border:1px solid #fecaca;background:#fff;color:var(--danger);cursor:pointer}.tier-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:12px 0 14px;flex-wrap:wrap}.tier-actions + .live-status{margin-top:0;margin-bottom:22px}.group-list{display:grid;gap:16px}.group-card{background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}.group-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:17px 18px;background:#fbfbfc;border-bottom:1px solid var(--border)}.group-identity{display:flex;align-items:flex-start;gap:13px}.group-number{width:42px;height:42px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font:700 .77rem 'JetBrains Mono',monospace;flex:0 0 auto}.group-title{font-size:.98rem;font-weight:700;margin-bottom:5px}.group-meta{display:flex;gap:7px;flex-wrap:wrap}.badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:.68rem;font-weight:700}.badge-code{background:#ede9fe;color:#5b21b6}.badge-pipe{background:#e0f2fe;color:#075985}.badge-complete{background:var(--success-bg);color:var(--success)}.badge-incomplete{background:var(--warning-bg);color:var(--warning)}.badge-inactive{background:var(--danger-bg);color:var(--danger)}.group-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.group-body{padding:0}.group-footer{display:flex;justify-content:space-between;gap:15px;padding:11px 18px;background:#fbfbfc;border-top:1px solid var(--border);font-size:.72rem;color:var(--muted);flex-wrap:wrap}.empty-state{text-align:center;padding:42px 20px;color:var(--muted)}.account-preview{background:#f9fafb;border:1px solid var(--border);border-radius:8px;padding:11px 12px;font-size:.8rem;color:var(--muted)}.modal-overlay{display:none;position:fixed;inset:0;background:rgba(17,24,39,.55);z-index:1200;align-items:center;justify-content:center;padding:18px}.modal-overlay.active{display:flex}.modal-content{background:#fff;border-radius:14px;width:min(1040px,100%);max-height:92vh;overflow:auto;padding:22px;box-shadow:0 24px 70px rgba(0,0,0,.22)}.modal-content.narrow{width:min(680px,100%)}.modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:18px}.modal-header h2{font-size:1.1rem}.modal-header p{font-size:.77rem;color:var(--muted);margin-top:5px}.close-modal{border:0;background:none;font-size:1.15rem;color:var(--muted);cursor:pointer}.check-row{display:flex;gap:8px;align-items:center;font-size:.82rem;color:var(--muted)}.pipe-table-actions{display:flex;gap:7px;justify-content:flex-end}.icon-btn{width:32px;height:32px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--accent);cursor:pointer;display:inline-flex;align-items:center;justify-content:center}.group-change-warning{margin-top:14px}.footer-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:16px}@media(max-width:850px){.page{padding:20px}.page-header{display:block}.summary-grid{grid-template-columns:repeat(2,1fr)}.form-grid{grid-template-columns:1fr}.form-group.full{grid-column:auto}.group-head{display:block}.group-actions{margin-top:12px;justify-content:flex-start}}@media(max-width:520px){.summary-grid{grid-template-columns:1fr}.page{padding:14px}}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../common/navbar.php'; ?>
<div class="main-content"><main class="page">
<div class="page-header">
    <div>
        <a class="back-link" href="rates_management.php">← Back to Rates Management</a>
        <h1 class="page-title">Water Rate Tariff Groups</h1>
        <p class="page-subtitle">A tariff group belongs to one Rate Code and one Pipe Size. All tiers in the group are saved and changed together, so moving the group to another pipe size moves every tier at once.</p>
    </div>
</div>

<?php if (isset($_SESSION['flash']['success'])): ?>
<div class="alert alert-success"><?= e($_SESSION['flash']['success']); unset($_SESSION['flash']['success']); ?></div>
<?php endif; ?>
<?php if (isset($_SESSION['flash']['error'])): ?>
<div class="alert alert-danger"><?= e($_SESSION['flash']['error']); unset($_SESSION['flash']['error']); ?></div>
<?php endif; ?>
<?php if ($loadErrors): ?>
<div class="alert alert-danger"><?= e(implode(' ', array_unique($loadErrors))) ?></div>
<?php endif; ?>
<?php if ($billCodeProvisioningNotice): ?>
<div class="alert alert-success"><?= e($billCodeProvisioningNotice) ?></div>
<?php endif; ?>

<div class="tabs">
    <button class="tab-btn <?= $requestedTab === 'rates' ? 'active' : '' ?>" type="button" data-tab="rates">Tariff Groups</button>
    <button class="tab-btn <?= $requestedTab === 'pipe-sizes' ? 'active' : '' ?>" type="button" data-tab="pipe-sizes">Pipe Size Master List</button>
</div>

<section id="tab-rates" class="tab-panel <?= $requestedTab === 'rates' ? 'active' : '' ?>">
    <div class="summary-grid">
        <div class="summary-item"><strong><?= count($groups) ?></strong><span>Tariff groups</span></div>
        <div class="summary-item"><strong><?= $completeGroupCount ?></strong><span>Complete groups</span></div>
        <div class="summary-item"><strong><?= count($rates) ?></strong><span>Total tiers</span></div>
        <div class="summary-item"><strong><?= array_sum($memberCounts) ?></strong><span>Assigned members</span></div>
    </div>

    <div class="card">
        <h2 class="card-title">Water Billing Bill Code</h2>
        <p class="card-subtitle">The system automatically maintains the required WATER BILL entry in Bill Code Management.</p>
        <?php if ($globalBillCode): ?>
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Bill Code</label>
                <input class="form-input" type="text" value="<?= e($globalBillCode['code']) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Sales Price</label>
                <input class="form-input mono" type="text" value="₱ <?= e(number_format((float)$globalBillCode['sales_price'], 2)) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Default Debit</label>
                <input class="form-input" type="text" value="<?= e($globalBillCode['ar_account'] . ' — ' . $globalBillCode['ar_name']) ?>" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">Default Credit</label>
                <input class="form-input" type="text" value="<?= e($globalBillCode['income_account'] . ' — ' . $globalBillCode['income_name']) ?>" readonly>
            </div>
        </div>
        <div class="account-preview" style="margin-top:12px"><strong>Member Discount:</strong> Enabled automatically</div>
        <?php else: ?>
        <div class="alert alert-danger"><?= e($globalBillCodeError ?: 'The WATER BILL entry could not be configured.') ?></div>
        <?php endif; ?>
        <div class="footer-actions" style="justify-content:flex-start">
            <a class="btn btn-secondary" href="bill_codes.php">Open Bill Code Management</a>
        </div>
    </div>

    <div class="card">
        <h2 class="card-title">Create Tariff Group</h2>
        <p class="card-subtitle">Build the tiers based on the company’s actual rates. The form starts with one row only. Use Add Tier to add more ranges. Create remains disabled until the first tier is 0–N, the final tier is N–Infinity, every tier has an amount, and the ranges are continuous.</p>
        <?php if (!$rateCodes || !$activePipeSizes): ?>
        <div class="alert alert-danger">At least one Rate Code and one active Pipe Size are required before a tariff group can be created.</div>
        <?php endif; ?>
        <form id="createGroupForm" action="../process/saveWaterRateGroup.php" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="operation_token" value="<?= e($tokens['createGroup']) ?>">
            <div class="form-grid" style="margin-bottom:16px">
                <div class="form-group">
                    <label class="form-label">Rate Code</label>
                    <select name="rc_id" class="form-input group-rate-code" required>
                        <option value="">Select rate code...</option>
                        <?php foreach ($rateCodes as $rateCode): ?>
                        <option value="<?= e($rateCode['rc_id']) ?>"><?= e($rateCode['rc_code']) ?> — <?= e($rateCode['rc_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Pipe Size</label>
                    <select name="pipe_size_id" class="form-input group-pipe-size" required>
                        <option value="">Select pipe size...</option>
                        <?php foreach ($activePipeSizes as $pipeSize): ?>
                        <option value="<?= e($pipeSize['pipe_size_id']) ?>"><?= e(water_pipe_size_format($pipeSize)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-wrap">
                <table class="tier-editor-table">
                    <thead><tr><th>Tier</th><th>From m³</th><th>To m³</th><th>Amount</th><th>Rate Type</th><th></th></tr></thead>
                    <tbody class="tier-editor-body"></tbody>
                </table>
            </div>
            <div class="tier-actions">
                <button class="btn btn-secondary btn-small add-tier" type="button">+ Add Tier</button>
                <span style="font-size:.73rem;color:var(--muted)">Mark only the final row as Infinity.</span>
            </div>
            <div class="live-status warning" aria-live="polite">Add at least two tiers: the first must be 0–N and the final tier must be N–Infinity.</div>
            <button class="btn btn-primary group-submit" type="submit" disabled>Create Tariff Group</button>
        </form>
    </div>

    <div class="group-list">
        <?php if (!$groups): ?>
        <div class="card empty-state"><strong>No tariff groups configured.</strong><br><span>Create the first complete Rate Code and Pipe Size group above.</span></div>
        <?php endif; ?>
        <?php $groupNumber = 0; foreach ($groups as $group): $groupNumber++; $complete = ($group['status']['complete'] ?? false) === true; ?>
        <article class="group-card">
            <div class="group-head">
                <div class="group-identity">
                    <div class="group-number"><?= e($group['rc_code']) ?></div>
                    <div>
                        <div class="group-title"><?= e($group['rc_name']) ?></div>
                        <div class="group-meta">
                            <span class="badge badge-code">Rate Code <?= e($group['rc_code']) ?></span>
                            <span class="badge badge-pipe">Pipe <?= e($group['pipe_size_label']) ?></span>
                            <span class="badge badge-code" title="Set on Water Billing &gt; Rate Codes"><?= e(water_rate_billing_methods()[$group['billing_method']]) ?></span>
                            <span class="badge <?= $complete ? 'badge-complete' : 'badge-incomplete' ?>"><?= $complete ? 'COMPLETE' : 'NEEDS FIXING' ?></span>
                            <?php if ((int)$group['pipe_size_is_active'] !== 1): ?><span class="badge badge-inactive">PIPE SIZE INACTIVE</span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="group-actions">
                    <button class="btn btn-secondary btn-small edit-group" type="button" data-group-key="<?= e($group['key']) ?>">Edit Entire Group</button>
                    <form action="../process/deleteWaterRateGroup.php" method="POST" data-confirm="Delete this complete tariff group? This cannot be undone.">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="operation_token" value="<?= e($tokens['deleteGroup']) ?>">
                        <input type="hidden" name="rc_id" value="<?= e($group['rc_id']) ?>">
                        <input type="hidden" name="pipe_size_id" value="<?= e($group['pipe_size_id']) ?>">
                        <button class="btn btn-danger btn-small" type="submit">Delete Group</button>
                    </form>
                </div>
            </div>
            <div class="group-body table-wrap" style="border:0;border-radius:0">
                <table>
                    <thead><tr><th>Tier</th><th>Coverage</th><th>Amount</th><th>Rate Type</th></tr></thead>
                    <tbody>
                    <?php foreach ($group['tiers'] as $tierIndex => $tier): ?>
                    <tr>
                        <td class="mono">Tier <?= $tierIndex + 1 ?></td>
                        <td class="mono"><strong><?= e($tier['from_cb']) ?> – <?= $tier['to_cb'] === null ? '∞' : e($tier['to_cb']) ?> m³</strong></td>
                        <td class="mono">₱ <?= number_format((float)$tier['amount'], 4) ?></td>
                        <td><span class="badge <?= $tier['bill_type'] === 'FIXED' ? 'badge-incomplete' : 'badge-pipe' ?>"><?= e($tier['bill_type']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="group-footer">
                <span><?= count($group['tiers']) ?> tier(s), <?= (int)$group['member_count'] ?> assigned member(s)</span>
                <span><?= e($group['status']['message'] ?? '') ?></span>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section id="tab-pipe-sizes" class="tab-panel <?= $requestedTab === 'pipe-sizes' ? 'active' : '' ?>">
    <div class="summary-grid">
        <div class="summary-item"><strong><?= count($pipeSizes) ?></strong><span>Total pipe sizes</span></div>
        <div class="summary-item"><strong><?= count($activePipeSizes) ?></strong><span>Active pipe sizes</span></div>
        <div class="summary-item"><strong><?= array_sum(array_map(static function (array $row): int { return (int)($row['member_count'] ?? 0); }, $pipeSizes)) ?></strong><span>Member assignments</span></div>
        <div class="summary-item"><strong><?= count($rates) ?></strong><span>Configured tiers</span></div>
    </div>

    <div class="card">
        <h2 class="card-title">Add Pipe Size</h2>
        <p class="card-subtitle">Pipe sizes are reusable. One Rate Code may have separate tariff groups for several different pipe sizes.</p>
        <form action="../process/addWaterPipeSize.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="operation_token" value="<?= e($tokens['addPipeSize']) ?>">
            <div class="form-grid">
                <div class="form-group"><label class="form-label">Code</label><input name="size_code" class="form-input mono" maxlength="30" placeholder="Example: 1/2" required></div>
                <div class="form-group"><label class="form-label">Display Label</label><input name="size_label" class="form-input" maxlength="80" placeholder="Example: 1/2 inch" required></div>
                <div class="form-group"><label class="form-label">Diameter in Inches</label><input name="diameter_inches" class="form-input mono" type="number" min="0.0001" step="0.0001" placeholder="0.5000"></div>
            </div>
            <div class="footer-actions" style="justify-content:flex-start"><button class="btn btn-primary" type="submit">Add Pipe Size</button></div>
        </form>
    </div>

    <div class="card" style="padding:0">
        <div style="padding:20px 20px 12px"><h2 class="card-title">Pipe Size Master List</h2></div>
        <div class="table-wrap" style="border:0;border-radius:0">
            <table>
                <thead><tr><th>Code</th><th>Label</th><th>Diameter</th><th>Status</th><th>Members</th><th>Tariff Tiers</th><th style="text-align:right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($pipeSizes as $pipeSize): ?>
                <tr>
                    <td class="mono"><strong><?= e($pipeSize['size_code']) ?></strong></td>
                    <td><?= e($pipeSize['size_label']) ?></td>
                    <td class="mono"><?= $pipeSize['diameter_inches'] === null ? '—' : e(rtrim(rtrim(number_format((float)$pipeSize['diameter_inches'], 4, '.', ''), '0'), '.')) . ' in' ?></td>
                    <td><span class="badge <?= (int)$pipeSize['is_active'] === 1 ? 'badge-complete' : 'badge-inactive' ?>"><?= (int)$pipeSize['is_active'] === 1 ? 'ACTIVE' : 'INACTIVE' ?></span></td>
                    <td class="mono"><?= e($pipeSize['member_count']) ?></td>
                    <td class="mono"><?= e($pipeSize['tier_count']) ?></td>
                    <td><div class="pipe-table-actions">
                        <button class="icon-btn edit-pipe-size" type="button"
                            data-id="<?= e($pipeSize['pipe_size_id']) ?>"
                            data-code="<?= e($pipeSize['size_code']) ?>"
                            data-label="<?= e($pipeSize['size_label']) ?>"
                            data-diameter="<?= e($pipeSize['diameter_inches'] ?? '') ?>"
                            data-active="<?= e($pipeSize['is_active']) ?>">✎</button>
                        <form action="../process/deleteWaterPipeSize.php" method="POST" data-confirm="Delete this pipe size? It must not be assigned to members or tariff groups.">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="operation_token" value="<?= e($tokens['deletePipeSize']) ?>">
                            <input type="hidden" name="pipe_size_id" value="<?= e($pipeSize['pipe_size_id']) ?>">
                            <button class="icon-btn" style="color:var(--danger);border-color:#fecaca" type="submit">✕</button>
                        </form>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$pipeSizes): ?><tr><td colspan="7" class="empty-state">No pipe sizes configured.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
</main></div>

<div class="modal-overlay" id="editGroupModal">
    <div class="modal-content">
        <div class="modal-header">
            <div><h2>Edit Entire Tariff Group</h2><p>Changing the Rate Code or Pipe Size here changes it for every tier in this group.</p></div>
            <button class="close-modal" type="button" data-close="editGroupModal">✕</button>
        </div>
        <form id="editGroupForm" action="../process/updateWaterRateGroup.php" method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="operation_token" value="<?= e($tokens['updateGroup']) ?>">
            <input type="hidden" name="old_rc_id" id="editOldRateCodeId">
            <input type="hidden" name="old_pipe_size_id" id="editOldPipeSizeId">
            <div class="form-grid" style="margin-bottom:16px">
                <div class="form-group">
                    <label class="form-label">Rate Code for All Tiers</label>
                    <select name="rc_id" class="form-input group-rate-code" id="editRateCodeId" required>
                        <?php foreach ($rateCodes as $rateCode): ?><option value="<?= e($rateCode['rc_id']) ?>"><?= e($rateCode['rc_code']) ?> — <?= e($rateCode['rc_name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Pipe Size for All Tiers</label>
                    <select name="pipe_size_id" class="form-input group-pipe-size" id="editPipeSizeId" required>
                        <?php foreach ($activePipeSizes as $pipeSize): ?><option value="<?= e($pipeSize['pipe_size_id']) ?>"><?= e(water_pipe_size_format($pipeSize)) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="info-note group-change-warning">This moves the complete tariff group only. Existing member Rate Code and Pipe Size assignments are not changed automatically.</div>
            <div class="table-wrap">
                <table class="tier-editor-table">
                    <thead><tr><th>Tier</th><th>From m³</th><th>To m³</th><th>Amount</th><th>Rate Type</th><th></th></tr></thead>
                    <tbody class="tier-editor-body"></tbody>
                </table>
            </div>
            <div class="tier-actions">
                <button class="btn btn-secondary btn-small add-tier" type="button">+ Add Tier</button>
                <span style="font-size:.73rem;color:var(--muted)">The entire group is saved as one transaction.</span>
            </div>
            <div class="live-status warning" aria-live="polite"></div>
            <div class="footer-actions">
                <button class="btn btn-secondary" type="button" data-close="editGroupModal">Cancel</button>
                <button class="btn btn-primary group-submit" type="submit" disabled>Update Entire Group</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="editPipeModal">
    <div class="modal-content narrow">
        <div class="modal-header"><div><h2>Edit Pipe Size</h2></div><button class="close-modal" type="button" data-close="editPipeModal">✕</button></div>
        <form action="../process/editWaterPipeSize.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="operation_token" value="<?= e($tokens['editPipeSize']) ?>">
            <input type="hidden" name="pipe_size_id" id="editPipeId">
            <div class="form-grid">
                <div class="form-group"><label class="form-label">Code</label><input name="size_code" id="editPipeCode" class="form-input mono" maxlength="30" required></div>
                <div class="form-group"><label class="form-label">Display Label</label><input name="size_label" id="editPipeLabel" class="form-input" maxlength="80" required></div>
                <div class="form-group"><label class="form-label">Diameter in Inches</label><input name="diameter_inches" id="editPipeDiameter" class="form-input mono" type="number" min="0.0001" step="0.0001"></div>
                <div class="form-group"><label class="form-label">Availability</label><label class="check-row"><input type="checkbox" name="is_active" id="editPipeActive" value="1"> Active for member assignments and tariffs</label></div>
            </div>
            <div class="footer-actions"><button class="btn btn-secondary" type="button" data-close="editPipeModal">Cancel</button><button class="btn btn-primary" type="submit">Update Pipe Size</button></div>
        </form>
    </div>
</div>

<script>
const tariffGroups = <?= json_encode($groupPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const groupMap = new Map(tariffGroups.map(group => [group.key, group]));
const existingKeys = new Set(tariffGroups.map(group => group.key));
const initialTier = {from_cb:0,to_cb:'',amount:'',bill_type:'VARIABLE'};

function openModal(id){document.getElementById(id)?.classList.add('active')}
function closeModal(id){document.getElementById(id)?.classList.remove('active')}
document.querySelectorAll('[data-close]').forEach(button=>button.addEventListener('click',()=>closeModal(button.dataset.close)));
document.querySelectorAll('.modal-overlay').forEach(modal=>modal.addEventListener('click',event=>{if(event.target===modal)closeModal(modal.id)}));

document.querySelectorAll('.tab-btn').forEach(button=>button.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(item=>item.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(item=>item.classList.remove('active'));
    button.classList.add('active');
    document.getElementById('tab-'+button.dataset.tab)?.classList.add('active');
    history.replaceState(null,'','?tab='+encodeURIComponent(button.dataset.tab));
}));

function tierRow(tier={}){
    const row=document.createElement('tr');
    row.className='tier-editor-row';
    const hasExplicitTo=Object.prototype.hasOwnProperty.call(tier,'to_cb');
    const isInfinity=hasExplicitTo && tier.to_cb===null;
    row.innerHTML=`
        <td class="mono tier-number"></td>
        <td><span class="mono range-from">0</span><input type="hidden" name="tier_from[]" value="0"></td>
        <td class="tier-to-cell">
            <div class="tier-to-control">
                <input class="form-input mono tier-to" type="number" min="0" step="1" value="${isInfinity?'':(tier.to_cb ?? '')}" inputmode="numeric">
                <input class="tier-to-submitted" type="hidden" name="tier_to[]" value="${isInfinity?'':(tier.to_cb ?? '')}">
                <label class="tier-infinity-wrap"><input class="tier-infinity" type="checkbox" ${isInfinity?'checked':''}> Infinity</label>
            </div>
        </td>
        <td><input class="form-input mono tier-amount" type="number" min="0.0001" step="0.0001" name="tier_amount[]" value="${tier.amount ?? ''}" required></td>
        <td><select class="form-input tier-type" name="tier_bill_type[]"><option value="FIXED">FIXED</option><option value="VARIABLE">VARIABLE</option></select></td>
        <td><button class="remove-tier" type="button" title="Remove tier">✕</button></td>`;
    row.querySelector('.tier-type').value=(tier.bill_type||'VARIABLE').toUpperCase();
    return row;
}

function syncToValue(row){
    const toInput=row.querySelector('.tier-to');
    const submitted=row.querySelector('.tier-to-submitted');
    const infinity=row.querySelector('.tier-infinity');
    if(!toInput||!submitted||!infinity)return;
    toInput.disabled=infinity.checked;
    submitted.value=infinity.checked?'':toInput.value.trim();
}

function refreshTerminalControl(form){
    const rows=[...form.querySelectorAll('.tier-editor-row')];
    rows.forEach((row,index)=>{
        const isLast=index===rows.length-1;
        const wrap=row.querySelector('.tier-infinity-wrap');
        const infinity=row.querySelector('.tier-infinity');
        if(wrap)wrap.classList.toggle('visible',isLast);
        if(!isLast && infinity?.checked){infinity.checked=false;}
        syncToValue(row);
    });
}

function loadTierEditor(form,tiers){
    const body=form.querySelector('.tier-editor-body');
    body.innerHTML='';
    const list=(tiers&&tiers.length?tiers:[initialTier]).map(tier=>({...tier}));
    list.forEach(tier=>body.appendChild(tierRow(tier)));
    refreshTerminalControl(form);
    recalculateEditor(form);
}

function addTier(form){
    const body=form.querySelector('.tier-editor-body');
    const rows=[...body.querySelectorAll('.tier-editor-row')];
    const terminal=rows.find(row=>row.querySelector('.tier-infinity')?.checked===true) || null;
    const newRow=tierRow({to_cb:'',amount:'',bill_type:'VARIABLE'});
    if(terminal){body.insertBefore(newRow,terminal);}else{body.appendChild(newRow);}
    refreshTerminalControl(form);
    recalculateEditor(form);
    newRow.querySelector('.tier-to')?.focus();
}

function recalculateEditor(form){
    refreshTerminalControl(form);
    const rows=[...form.querySelectorAll('.tier-editor-row')];
    let nextFrom=0;
    rows.forEach((row,index)=>{
        row.querySelector('.tier-number').textContent='Tier '+(index+1);
        const fromSpan=row.querySelector('.range-from');
        const fromInput=row.querySelector('input[name="tier_from[]"]');
        if(nextFrom===null){
            fromSpan.textContent='Waiting';
            fromInput.value='';
        }else{
            fromSpan.textContent=String(nextFrom);
            fromInput.value=String(nextFrom);
        }
        syncToValue(row);
        const infinity=row.querySelector('.tier-infinity')?.checked===true;
        const toValue=row.querySelector('.tier-to')?.value.trim()??'';
        if(infinity){
            nextFrom=null;
        }else if(toValue!=='' && /^\d+$/.test(toValue) && nextFrom!==null && Number(toValue)>=nextFrom){
            nextFrom=Number(toValue)+1;
        }else{
            nextFrom=null;
        }
    });
    validateEditor(form);
}

function validateEditor(form){
    const errors=[];
    const rateCode=form.querySelector('.group-rate-code')?.value||'';
    const pipeSize=form.querySelector('.group-pipe-size')?.value||'';
    const currentKey=form.dataset.currentKey||'';
    const targetKey=rateCode&&pipeSize?rateCode+':'+pipeSize:'';
    const rows=[...form.querySelectorAll('.tier-editor-row')];

    rows.forEach(row=>row.classList.remove('invalid-row'));
    if(!rateCode)errors.push('Select a Rate Code.');
    if(!pipeSize)errors.push('Select a Pipe Size.');
    if(targetKey && existingKeys.has(targetKey) && targetKey!==currentKey){
        errors.push('This Rate Code and Pipe Size already has a tariff group.');
    }
    if(rows.length<2){errors.push('Add at least two tiers: a starting 0–N tier and a final N–Infinity tier.');}

    let expectedFrom=0;
    rows.forEach((row,index)=>{
        const number=index+1;
        const isLast=index===rows.length-1;
        const fromRaw=row.querySelector('input[name="tier_from[]"]')?.value.trim()??'';
        const amountRaw=row.querySelector('.tier-amount')?.value.trim()??'';
        const toRaw=row.querySelector('.tier-to')?.value.trim()??'';
        const infinity=row.querySelector('.tier-infinity')?.checked===true;
        let rowInvalid=false;

        if(fromRaw===''||!Number.isFinite(expectedFrom)||Number(fromRaw)!==expectedFrom){
            errors.push(`Tier ${number} cannot start until the previous tier has a valid To value.`);
            rowInvalid=true;
        }
        if(amountRaw===''||!Number.isFinite(Number(amountRaw))||Number(amountRaw)<=0){
            errors.push(`Enter an amount greater than zero for Tier ${number}.`);
            rowInvalid=true;
        }
        if(!isLast && infinity){errors.push('Only the final tier may be marked Infinity.');rowInvalid=true;}
        if(isLast && !infinity){errors.push('Mark the final tier as Infinity.');rowInvalid=true;}
        if(!infinity){
            if(toRaw===''||!/^\d+$/.test(toRaw)){
                errors.push(`Enter a whole-number To value for Tier ${number}.`);
                expectedFrom=NaN;
                rowInvalid=true;
            }else{
                const to=Number(toRaw);
                if(!Number.isFinite(expectedFrom)||to<expectedFrom){
                    errors.push(`Tier ${number} must end at or above ${Number.isFinite(expectedFrom)?expectedFrom:'its From value'} m³.`);
                    rowInvalid=true;
                }
                expectedFrom=to+1;
            }
        }else{expectedFrom=NaN;}
        if(rowInvalid)row.classList.add('invalid-row');
    });

    if(rows.length>=1){
        const firstTo=rows[0].querySelector('.tier-to')?.value.trim()??'';
        const firstInfinity=rows[0].querySelector('.tier-infinity')?.checked===true;
        if(firstInfinity||firstTo===''||!/^\d+$/.test(firstTo)){
            errors.push('The first tier must be a finite 0–N range.');
            rows[0].classList.add('invalid-row');
        }
    }

    const status=form.querySelector('.live-status');
    const submit=form.querySelector('.group-submit');
    const unique=[...new Set(errors)];
    if(unique.length){
        status.className='live-status warning';
        status.textContent=unique[0]+(unique.length>1?` ${unique.length-1} more item(s) need attention.`:'');
        submit.disabled=true;
        return false;
    }
    status.className='live-status ok';
    status.textContent=`Tariff group ready: ${rows.length} continuous tier(s), starting at 0 m³ and ending at Infinity.`;
    submit.disabled=false;
    return true;
}

function bindEditor(form){
    form.querySelector('.add-tier')?.addEventListener('click',()=>addTier(form));
    form.addEventListener('input',event=>{
        if(event.target.matches('.tier-to,.tier-amount,.group-rate-code,.group-pipe-size'))recalculateEditor(form);
    });
    form.addEventListener('change',event=>{
        if(event.target.matches('.tier-infinity,.tier-type,.group-rate-code,.group-pipe-size'))recalculateEditor(form);
        else validateEditor(form);
    });
    form.addEventListener('click',event=>{
        const remove=event.target.closest('.remove-tier');
        if(!remove)return;
        const rows=form.querySelectorAll('.tier-editor-row');
        if(rows.length<=1)return;
        remove.closest('tr').remove();
        refreshTerminalControl(form);
        recalculateEditor(form);
    });
    form.addEventListener('submit',event=>{
        recalculateEditor(form);
        if(!validateEditor(form))event.preventDefault();
    });
}

const createForm=document.getElementById('createGroupForm');
loadTierEditor(createForm,[initialTier]);
bindEditor(createForm);

document.querySelectorAll('.edit-group').forEach(button=>button.addEventListener('click',()=>{
    const group=groupMap.get(button.dataset.groupKey);
    if(!group)return;
    const form=document.getElementById('editGroupForm');
    form.dataset.currentKey=group.key;
    document.getElementById('editOldRateCodeId').value=group.rc_id;
    document.getElementById('editOldPipeSizeId').value=group.pipe_size_id;
    document.getElementById('editRateCodeId').value=group.rc_id;
    document.getElementById('editPipeSizeId').value=group.pipe_size_id;
    loadTierEditor(form,group.tiers);
    openModal('editGroupModal');
}));

const editForm=document.getElementById('editGroupForm');
bindEditor(editForm);

document.querySelectorAll('.edit-pipe-size').forEach(button=>button.addEventListener('click',()=>{
    document.getElementById('editPipeId').value=button.dataset.id;
    document.getElementById('editPipeCode').value=button.dataset.code;
    document.getElementById('editPipeLabel').value=button.dataset.label;
    document.getElementById('editPipeDiameter').value=button.dataset.diameter;
    document.getElementById('editPipeActive').checked=button.dataset.active==='1';
    openModal('editPipeModal');
}));
</script>
</body>
</html>
