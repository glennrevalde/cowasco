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

// views/rate_codes.php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';

$pdo = getDB();


if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

require_once __DIR__ . '/../common/waterRateBillingHelpers.php';
$methods = water_rate_billing_methods();
$methodHelp = [
    'straight' => 'The whole consumption at the rate of the tier it falls in (250 m³ × the 41-up rate).',
    'ladder' => 'Each tier\'s own cubic meters at that tier\'s rate, added up (the minimum charge, then 5 m³ × the 6-10 rate, 10 m³ × the 11-20 rate ...).',
];

/** What a consumption comes to on a pipe-size tariff by each computation (null when the tariff is not complete) */
function rc_example(array $tiers, int $m3): ?array
{
    try {
        $rates = water_rate_validate_tiers($tiers, true, false);
        return [
            'straight' => water_rate_compute_from_configuration($rates, $m3, null, 'straight')['amount_due'],
            'ladder' => water_rate_compute_from_configuration($rates, $m3, null, 'ladder')['amount_due'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

// Fetch all rate codes and their associated billing tiers (water_meter_members)
$rateCodes = [];
$ratesByRc = [];
$loadError = '';

if (isset($pdo)) {
    try {
        $stmt      = $pdo->query("SELECT * FROM rate_code ORDER BY rc_code ASC, rc_name ASC, rc_id ASC");
        $rateCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtRates = $pdo->query("
            SELECT w.*, ps.size_code AS pipe_size_code, ps.size_label AS pipe_size_label,
                   ps.diameter_inches
            FROM water_meter_members w
            JOIN water_pipe_sizes ps ON ps.pipe_size_id = w.pipe_size_id
            ORDER BY w.rc_id, ps.diameter_inches IS NULL, ps.diameter_inches, ps.size_label, w.from_cb
        ");
        $allRates = $stmtRates->fetchAll(PDO::FETCH_ASSOC);

        // Group the tiers by Rate Code, then by Pipe Size.
        foreach ($allRates as $r) {
            $ratesByRc[(int)$r['rc_id']][(int)$r['pipe_size_id']]['pipe_size'] = [
                'pipe_size_id' => (int)$r['pipe_size_id'],
                'size_code' => (string)$r['pipe_size_code'],
                'size_label' => (string)$r['pipe_size_label'],
                'diameter_inches' => $r['diameter_inches'],
            ];
            $ratesByRc[(int)$r['rc_id']][(int)$r['pipe_size_id']]['tiers'][] = $r;
        }
    } catch (PDOException $ex) {
        error_log("Fetch Error: " . $ex->getMessage());
        $loadError = 'Rate-code data could not be loaded. Please verify the database schema and connection.';
    }
}

// Flash message
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$csrf = csrfToken();
$createRateCodeToken = operationToken('create_rate_code');
$editRateCodeToken = operationToken('edit_rate_code');
$deleteRateCodeToken = operationToken('delete_rate_code');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Rate Code Management - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white: #ffffff;
            --off-white: #f8f9fb;
            --border: #e8eaed;
            --border-focus: #1a1a2e;
            --text-primary: #0f0f1a;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --accent: #1a1a2e;
            --accent-light: #e8f0fe;
            --success: #059669;
            --success-bg: #ecfdf5;
            --warning: #d97706;
            --danger: #dc2626;
            --danger-hover: #b91c1c;
            --danger-bg: #fef2f2;
            --radius-sm: 8px;
            --radius: 14px;
            --radius-lg: 20px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-lg: 0 20px 60px rgba(0,0,0,.18);
            --transition: 0.2s cubic-bezier(0.4,0,0.2,1);
        }
        
        html { height: 100%; font-size: 16px; scroll-behavior: smooth; }
        body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); min-height: 100vh; -webkit-font-smoothing: antialiased; }
        
        .page { padding: 32px 36px; max-width: 1400px; margin: 0 auto; animation: fadeIn .4s ease both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

        /* ---- Header ---- */
        .page-header { display: flex; flex-direction: column; align-items: flex-start; margin-bottom: 24px; gap: 12px; }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; }

        /* ---- Buttons ---- */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 18px; border-radius: var(--radius-sm); border: none; font-family: inherit; font-size: .8125rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: transform var(--transition), box-shadow var(--transition), background var(--transition), border-color var(--transition); white-space: nowrap; }
        .btn:active { transform: scale(0.97); }
        .btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .btn-primary { background: var(--accent); color: var(--white); box-shadow: 0 2px 10px rgba(26,26,46,.25); }
        .btn-primary:hover { background: #0f0f1a; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,26,46,.3); }
        .btn-secondary { background: var(--white); color: var(--text-primary); border: 1.5px solid var(--border); }
        .btn-secondary:hover { border-color: var(--border-focus); background: var(--off-white); }
        .btn-danger { background: var(--danger); color: var(--white); }

        /* ---- Card & Table ---- */
        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 0; margin-bottom: 24px; box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin: 20px 24px 16px; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
        
        .card-table-wrap { border-top: 1px solid var(--border); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        thead tr { background: var(--off-white); border-bottom: 1.5px solid var(--border); }
        th { padding: 13px 20px; text-align: left; font-weight: 700; font-size: .69rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
        td { padding: 16px 20px; border-bottom: 1px solid var(--border); vertical-align: top; color: var(--text-primary); }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafbfc; }

        /* ---- Badges & Formatting ---- */
        .mono { font-family: 'JetBrains Mono', monospace; font-size: .8rem; }
        .badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: .67rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; white-space: nowrap; }
        .badge-warning { background: var(--warning); color: var(--white); }

        /* ---- Action Buttons ---- */
        .action-btns { display: flex; gap: 8px; justify-content: flex-end; }
        .icon-btn { width: 32px; height: 32px; border-radius: 8px; border: 1.5px solid var(--border); background: var(--white); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-secondary); transition: all var(--transition); }
        .icon-btn:hover { border-color: var(--border-focus); color: var(--text-primary); background: var(--off-white); transform: translateY(-1px); }
        .icon-btn.danger:hover { border-color: var(--danger); color: var(--danger); background: var(--danger-bg); }
        .icon-btn svg { width: 14px; height: 14px; }
        .rc-letter { font-size: 1.05rem; font-weight: 800; color: var(--text-primary); }
        .rc-method { margin-top: 6px; font-size: .78rem; font-weight: 700; color: var(--text-secondary); }
        .rc-method-ladder { color: #0b5ed7; }
        .rc-example { margin-top: 3px; font-size: .74rem; color: var(--text-secondary); }
        .rc-example span { color: var(--text-muted); }
        .method-choice { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .method-card { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border: 1.5px solid var(--border); border-radius: 10px; background: var(--white);
                       cursor: pointer; box-shadow: 0 1px 2px rgba(15,23,42,.06), inset 0 1px 0 rgba(255,255,255,.8); transition: border-color .15s, box-shadow .15s; }
        .method-card:hover { border-color: var(--border-focus); }
        .method-card:has(input:checked) { border-color: #0b5ed7; box-shadow: 0 0 0 3px rgba(11,94,215,.12), 0 4px 12px rgba(15,23,42,.08); }
        .method-card input { margin-top: 3px; accent-color: #0b5ed7; }
        .method-card span { display: flex; flex-direction: column; gap: 3px; text-transform: none; letter-spacing: normal; }
        .method-card strong { font-size: .84rem; color: var(--text-primary); }
        .method-card small { font-size: .72rem; line-height: 1.4; color: var(--text-secondary); font-weight: 400; }
        @media (max-width: 560px) { .method-choice { grid-template-columns: 1fr; } }

        /* ---- Form Elements ---- */
        .form-grid { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: .75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .05em; }
        .form-control { padding: 9px 13px; font-family: 'Sora', sans-serif; font-size: .875rem; border: 1.5px solid var(--border); border-radius: var(--radius-sm); outline: none; background: var(--white); color: var(--text-primary); transition: border-color var(--transition), box-shadow var(--transition); width: 100%; }
        .form-control:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px var(--accent-light); }
        .form-control[readonly] { background: var(--off-white); cursor: not-allowed; color: var(--text-secondary); }

        /* ---- Modal Overlay ---- */
        .modal-overlay { position: fixed; inset: 0; background: rgba(10,10,20,0.55); z-index: 1060; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity .3s ease; backdrop-filter: blur(4px); padding: 16px; }
        .modal-overlay.active { display: flex; opacity: 1; }
        .modal-box { background: var(--white); border-radius: var(--radius-lg); width: 100%; max-width: 500px; box-shadow: var(--shadow-lg); transform: translateY(24px) scale(0.97); opacity: 0; transition: transform 0.35s, opacity 0.35s; display: flex; flex-direction: column; }
        .modal-overlay.active .modal-box { transform: translateY(0) scale(1); opacity: 1; }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-title { font-size: 1.15rem; font-weight: 700; color: var(--text-primary); }
        .modal-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); line-height: 1; }
        .modal-close-btn:hover { color: var(--text-primary); }
        .modal-body { padding: 24px; }
        
        .alert { padding: 12px 16px; border-radius: var(--radius-sm); margin-bottom: 20px; font-size: .875rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success); color: var(--success); }
        .alert-danger { background: var(--danger-bg); border: 1px solid var(--danger); color: var(--danger); }
        
        @media (max-width: 768px) {
            .page { padding: 20px 16px; }
        }
    </style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">

    <div class="page-header">
        <a href="rates_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:600; margin-bottom: 4px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Back to Rate Management
        </a>
        <div style="width: 100%;">
            <h1 class="page-title">Rate Code Management</h1>
            <p class="page-subtitle">Manage billing classifications and their pipe-size tariffs. Rate-code letters may be reused; each classification remains a separate record.</p>
        </div>
    </div>

    <?php if (!empty($flash)): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : 'success') ?>">
            <?= e($flash['msg'] ?? '') ?>
        </div>
    <?php endif; ?>
    <?php if ($loadError !== ''): ?>
        <div class="alert alert-danger"><?= e($loadError) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Active Rate Classifications</h2>
            <button class="btn btn-primary" onclick="openModal('createModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Rate Code
            </button>
        </div>
        <div class="card-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width: 100px;">Code</th>
                        <th>Classification Name</th>
                        <th>Pipe-Size Tariffs</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rateCodes)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:30px; color:var(--text-muted);">
                                No rate codes found. Add one to get started.
                            </td>
                        </tr>
                    <?php else: foreach ($rateCodes as $rc): ?>
                    <tr>
                        <td>
                            <span class="rc-letter mono"><?= e($rc['rc_code']) ?></span>
                        </td>
                        <td>
                            <?php $method = water_rate_billing_method($rc['billing_method'] ?? 'straight');
                                  $firstConfig = !empty($ratesByRc[(int)$rc['rc_id']]) ? (array_values($ratesByRc[(int)$rc['rc_id']])[0] ?? null) : null;
                                  $example = $firstConfig ? rc_example($firstConfig['tiers'] ?? [], 30) : null; ?>
                            <div style="font-weight:700; color:var(--text-primary);"><?= e($rc['rc_name']) ?></div>
                            <div class="rc-method rc-method-<?= e($method) ?>"><?= e($methods[$method]) ?></div>
                            <?php if ($example): $other = $method === 'ladder' ? 'straight' : 'ladder'; ?>
                                <div class="rc-example">30 m³ on <?= e(water_pipe_size_format($firstConfig['pipe_size'])) ?>: ₱<?= e(number_format((float)$example[$method], 2)) ?>
                                    <span>(<?= e(strtolower($methods[$other])) ?>: ₱<?= e(number_format((float)$example[$other], 2)) ?>)</span></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $pipeConfigurations = $ratesByRc[(int)$rc['rc_id']] ?? []; ?>
                            <?php if (empty($pipeConfigurations)): ?>
                                <span style="color:var(--danger); font-size:.75rem; font-weight:600;">⚠ No pipe-size pricing tiers configured</span>
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; gap:8px; max-width:430px;">
                                    <?php foreach ($pipeConfigurations as $configuration):
                                        $pipe = $configuration['pipe_size'];
                                        $tiers = $configuration['tiers'] ?? [];
                                    ?>
                                        <div style="background:var(--off-white); padding:9px 12px; border-radius:7px; border:1px solid var(--border);">
                                            <div style="font-size:.72rem;font-weight:800;margin-bottom:6px;">
                                                <?= e(water_pipe_size_format($pipe)) ?>
                                            </div>
                                            <div style="display:flex;flex-direction:column;gap:5px;">
                                                <?php foreach ($tiers as $t):
                                                    $to = $t['to_cb'] !== null ? $t['to_cb'] : 'Up';
                                                    $amt = number_format((float)$t['amount'], 2);
                                                    $type = $t['bill_type'] === 'FIXED' ? 'Fixed Min' : 'per m³';
                                                ?>
                                                    <div class="mono" style="font-size:.72rem; display:grid; grid-template-columns:86px 86px 58px; gap:8px; align-items:center;">
                                                        <span style="color:var(--text-secondary);font-weight:600;"><?= e($t['from_cb']) ?>–<?= e($to) ?> m³</span>
                                                        <span style="font-weight:700;">₱<?= e($amt) ?></span>
                                                        <span style="color:var(--accent);font-size:.63rem;text-align:right;"><?= e($type) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="action-btns">
                                <button class="icon-btn" type="button" title="Edit Rate Code"
                                        data-action="edit-rate-code"
                                        data-rate-code-id="<?= e((int)$rc['rc_id']) ?>"
                                        data-rate-code="<?= e($rc['rc_code']) ?>"
                                        data-rate-name="<?= e($rc['rc_name']) ?>"
                                        data-method="<?= e(water_rate_billing_method($rc['billing_method'] ?? 'straight')) ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <form action="../process/deleteRateCode.php" method="POST" style="display:inline;"
                                      data-confirm="Delete rate code <?= e($rc['rc_code']) ?> — <?= e($rc['rc_name']) ?> (Record #<?= e((int)$rc['rc_id']) ?>)? This cannot be undone.">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                    <input type="hidden" name="operation_token" value="<?= e($deleteRateCodeToken) ?>">
                                    <input type="hidden" name="rc_id"      value="<?= $rc['rc_id'] ?>">
                                    <button type="submit" class="icon-btn danger" title="Delete">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div>

<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 class="modal-title">New Rate Code</h2>
            <button class="modal-close-btn" onclick="closeModal('createModal')">×</button>
        </div>
        <div class="modal-body">
            <form action="../process/createRateCode.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="operation_token" value="<?= e($createRateCodeToken) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Rate Code Letter</label>
                        <input type="text" name="rc_code" class="form-control mono" maxlength="1"
                               placeholder="e.g., E" style="text-transform:uppercase;" required>
                        <small style="color:var(--text-muted); font-size:.7rem;">Single letter (A–Z). Duplicate letters are allowed.</small>
                    </div>

                    <div class="form-group">
                        <label>Classification Name</label>
                        <input type="text" name="rc_name" class="form-control"
                               placeholder="e.g., RESIDENTIAL AND REGULAR" style="text-transform:uppercase;" required>
                    </div>

                    <div class="form-group">
                        <label>Computation</label>
                        <div class="method-choice">
                            <?php foreach ($methods as $mk => $ml): ?>
                            <label class="method-card">
                                <input type="radio" name="billing_method" value="<?= e($mk) ?>" id="new_method_<?= e($mk) ?>"<?= $mk === 'straight' ? ' checked' : '' ?>>
                                <span><strong><?= e($ml) ?></strong><small><?= e($methodHelp[$mk]) ?></small></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:var(--text-muted); font-size:.72rem;">Applies to every water bill of this rate code computed from now on (readings, batch readings, corrections); posted bills keep their amounts.</small>
                    </div>

                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; margin-top:16px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:16px;height:16px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Create Rate Code
                </button>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2 class="modal-title">Edit Rate Code</h2>
            <button class="modal-close-btn" onclick="closeModal('editModal')">×</button>
        </div>
        <div class="modal-body">
            <form action="../process/editRateCode.php" method="POST">
                <input type="hidden" name="csrf_token" id="edit_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="operation_token" value="<?= e($editRateCodeToken) ?>">
                <input type="hidden" name="rc_id" id="edit_rc_id">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Rate Code Letter</label>
                        <input type="text" name="rc_code" id="edit_rc_code" class="form-control mono"
                               maxlength="1" style="text-transform:uppercase;" required>
                        <small style="color:var(--text-muted); font-size:.7rem;">Single letter (A–Z). Duplicate letters are allowed.</small>
                    </div>

                    <div class="form-group">
                        <label>Classification Name</label>
                        <input type="text" name="rc_name" id="edit_rc_name" class="form-control"
                               style="text-transform:uppercase;" required>
                    </div>

                    <div class="form-group">
                        <label>Computation</label>
                        <div class="method-choice">
                            <?php foreach ($methods as $mk => $ml): ?>
                            <label class="method-card">
                                <input type="radio" name="billing_method" value="<?= e($mk) ?>" id="edit_method_<?= e($mk) ?>"<?= $mk === 'straight' ? ' checked' : '' ?>>
                                <span><strong><?= e($ml) ?></strong><small><?= e($methodHelp[$mk]) ?></small></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:var(--text-muted); font-size:.72rem;">Applies to every water bill of this rate code computed from now on (readings, batch readings, corrections); posted bills keep their amounts.</small>
                    </div>

                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; margin-top:16px;">
                    Save Changes
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openEditModal(rcId, rcCode, rcName, method) {
        document.getElementById('edit_rc_id').value   = rcId;
        document.getElementById('edit_rc_code').value = rcCode;
        document.getElementById('edit_rc_name').value = rcName;
        const radio = document.getElementById('edit_method_' + (method === 'ladder' ? 'ladder' : 'straight'));
        if (radio) radio.checked = true;
        openModal('editModal');
    }

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-action="edit-rate-code"]');
        if (!button) return;
        openEditModal(
            Number(button.dataset.rateCodeId),
            button.dataset.rateCode,
            button.dataset.rateName,
            button.dataset.method
        );
    });

    // Close modal on overlay click
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('active');
        }
    });

    // Keep rate-code letters uppercase in both create and edit forms.
    document.querySelectorAll('input[name="rc_code"]').forEach(function(input) {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^a-z]/gi, '').slice(0, 1).toUpperCase();
        });
    });
</script>

</body>
</html>
