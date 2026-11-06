<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$pdo = getDB();
requirePageAccess($pdo, basename(__FILE__, '.php'));
/* AUTH_GUARD_END */

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function cpc_setting(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

$accounts = [];
try {
    $accounts = $pdo->query("SELECT AccntID, AccntName FROM chartofaccntstbl WHERE Status='A' ORDER BY AccntName, AccntID")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $_SESSION['error_msg'] = 'Active chart accounts could not be loaded.';
}

$config = [
    'manual_penalty_enabled' => cpc_setting($pdo, 'manual_penalty_enabled', '0'),
    'collection_penalty_account_id' => cpc_setting($pdo, 'collection_penalty_account_id', '0'),
    'manual_compromise_enabled' => cpc_setting($pdo, 'manual_compromise_enabled', '0'),
    'collection_compromise_account_id' => cpc_setting($pdo, 'collection_compromise_account_id', '0'),
    'session_inactivity_hours' => cpc_setting($pdo, 'session_inactivity_hours', '24'),
];

$successMessage = (string)($_SESSION['success_msg'] ?? '');
$errorMessage = (string)($_SESSION['error_msg'] ?? '');
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../common/fonts.css">
<title>Other Fees & Session Configuration - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}:root{--white:#fff;--off:#f8f9fb;--border:#e8eaed;--text:#0f0f1a;--muted:#6b7280;--accent:#1a1a2e;--success:#047857;--success-bg:#ecfdf5;--danger:#b91c1c;--danger-bg:#fef2f2}body{min-height:100vh;background:var(--off);color:var(--text);font-family:Sora,sans-serif}.page{max-width:1120px;margin:0 auto;padding:28px 28px 48px}.page-header{margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--border)}.back{display:inline-flex;margin-bottom:7px;color:var(--muted);font-size:.8rem;font-weight:600;text-decoration:none}.title{font-size:1.42rem;font-weight:700}.subtitle{max-width:880px;margin-top:5px;color:var(--muted);font-size:.86rem;line-height:1.55}.alert{margin-bottom:16px;padding:12px 14px;border:1px solid;border-radius:7px;font-size:.82rem;font-weight:600}.success{border-color:#a7f3d0;background:var(--success-bg);color:var(--success)}.error{border-color:#fecaca;background:var(--danger-bg);color:var(--danger)}.card{overflow:hidden;border:1px solid var(--border);border-radius:7px;background:#fff;margin-bottom:18px}.card-head{padding:18px 20px;border-bottom:1px solid var(--border);background:#fafafa}.card-title{font-size:.96rem;font-weight:700}.card-copy{margin-top:4px;color:var(--muted);font-size:.78rem;line-height:1.5}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th{padding:11px 14px;border-bottom:1px solid var(--border);background:#f8fafc;color:#4b5563;font-size:.7rem;letter-spacing:.04em;text-align:left;text-transform:uppercase}td{padding:14px;border-bottom:1px solid var(--border);vertical-align:middle}tbody tr:last-child td{border-bottom:0}.name{font-size:.86rem;font-weight:700}.note{margin-top:3px;color:var(--muted);font-size:.72rem;line-height:1.45}.control{width:100%;min-width:220px;padding:10px 11px;border:1.5px solid #d7dae0;border-radius:5px;font:500 .84rem Sora,sans-serif;background:#fff;outline:none}.control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(26,26,46,.08)}.toggle-wrap{display:flex;align-items:center;gap:9px;font-size:.82rem;font-weight:600}.toggle-wrap input{width:18px;height:18px}.mono{font-family:'JetBrains Mono',monospace}.card-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 20px;border-top:1px solid var(--border);background:#fafafa}.foot{max-width:720px;color:var(--muted);font-size:.72rem;line-height:1.5}.actions{display:flex;gap:8px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:0;border-radius:5px;font:600 .76rem Sora,sans-serif;cursor:pointer;text-decoration:none}.primary{background:var(--accent);color:#fff}.secondary{border:1px solid var(--border);background:#fff;color:var(--text)}@media(max-width:760px){.page{padding:22px 16px 40px}.card-foot{align-items:stretch;flex-direction:column}.actions{justify-content:flex-end}}
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content"><main class="page">
<header class="page-header">
    <a class="back" href="settings.php">← Back to Settings</a>
    <h1 class="title">Other Fees & Session Configuration</h1>
    <p class="subtitle">Activate or deactivate the two cashier-controlled other fees: Penalty and Compromise. Their amounts are never stored as defaults and are never calculated by the server. The cashier must select the fee and type its amount for each payment.</p>
</header>
<?php if ($successMessage !== ''): ?><div class="alert success"><?= e($successMessage) ?></div><?php endif; ?>
<?php if ($errorMessage !== ''): ?><div class="alert error"><?= e($errorMessage) ?></div><?php endif; ?>

<form method="post" action="../process/saveCollectionPolicyConfiguration.php" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

<div class="card">
    <div class="card-head">
        <div class="card-title">Other Fees</div>
        <div class="card-copy">The generic Other Fees entry is replaced by these two specific manual charges. A checked setting only makes the fee available to the cashier; it does not add or compute any amount.</div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th style="width:34%">Fee</th><th style="width:23%">Availability</th><th>Accounting Income Account</th></tr></thead>
            <tbody>
                <tr>
                    <td><div class="name">Penalty</div><div class="note">Optional manual charge applied by the cashier.</div></td>
                    <td><label class="toggle-wrap"><input type="checkbox" name="manual_penalty_enabled" value="1" <?= $config['manual_penalty_enabled'] === '1' ? 'checked' : '' ?>> Activate Penalty</label></td>
                    <td>
                        <select class="control" name="collection_penalty_account_id">
                            <option value="0">Select income account</option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= e($account['AccntID']) ?>" <?= (int)$config['collection_penalty_account_id'] === (int)$account['AccntID'] ? 'selected' : '' ?>><?= e($account['AccntID']) ?> — <?= e($account['AccntName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><div class="name">Compromise</div><div class="note">Optional final manual charge after the penalty stage.</div></td>
                    <td><label class="toggle-wrap"><input type="checkbox" name="manual_compromise_enabled" value="1" <?= $config['manual_compromise_enabled'] === '1' ? 'checked' : '' ?>> Activate Compromise</label></td>
                    <td>
                        <select class="control" name="collection_compromise_account_id">
                            <option value="0">Select income account</option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="<?= e($account['AccntID']) ?>" <?= (int)$config['collection_compromise_account_id'] === (int)$account['AccntID'] ? 'selected' : '' ?>><?= e($account['AccntID']) ?> — <?= e($account['AccntName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div class="card-title">Operator Session</div>
        <div class="card-copy">The inactivity timer is refreshed only when the signed-in operator makes a request to the system.</div>
    </div>
    <div class="table-wrap">
        <table><tbody><tr>
            <td style="width:42%"><div class="name">Automatic logout after inactivity</div><div class="note">Allowed range: 1 to 720 hours.</div></td>
            <td><input class="control mono" type="number" name="session_inactivity_hours" min="1" max="720" step="1" value="<?= e($config['session_inactivity_hours']) ?>"></td>
        </tr></tbody></table>
    </div>
    <div class="card-foot">
        <p class="foot">Penalty and Compromise are not due-date rules and are not automatically charged. The selected accounting accounts are used only when the cashier manually applies the corresponding fee.</p>
        <div class="actions"><a class="btn secondary" href="settings.php">Cancel</a><button class="btn primary" type="submit">Save Configuration</button></div>
    </div>
</div>
</form>
</main></div>
</body>
</html>
