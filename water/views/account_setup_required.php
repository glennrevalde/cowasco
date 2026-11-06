<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once '../process/accessControlHelpers.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$operatorProfileId = (int)($_SESSION['operator_profile_id'] ?? $_SESSION['active_operator_profile_id'] ?? 0);

if (access_is_superadmin() || ($operatorProfileId > 0 && access_operator_profile_has_any_assigned_access($pdo, $operatorProfileId))) {
    header('Location: ' . access_default_landing_page($pdo));
    exit;
}

if (!function_exists('e')) {
    function e($value = ''): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errorMsg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['error_msg']);
$fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Operator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Account Setup Required - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white:#fff; --off-white:#f8f9fb; --border:#e8eaed; --text:#0f172a;
            --muted:#64748b; --accent:#1a1a2e; --warning:#b45309; --warning-bg:#fffbeb;
            --success:#047857; --success-bg:#ecfdf5; --radius:20px;
        }
        body {
            font-family:'Sora',sans-serif; min-height:100vh; display:grid; place-items:center;
            padding:24px; background:var(--off-white); color:var(--text);
        }
        body::before {
            content:''; position:fixed; inset:0; pointer-events:none; opacity:.45;
            background-image:linear-gradient(var(--border) 1px, transparent 1px),linear-gradient(90deg,var(--border) 1px,transparent 1px);
            background-size:40px 40px;
        }
        .card {
            position:relative; width:min(680px,100%); background:var(--white); border:1px solid var(--border);
            border-radius:var(--radius); box-shadow:0 24px 70px rgba(15,23,42,.10); padding:42px;
        }
        .mark { width:58px; height:58px; border-radius:16px; background:var(--accent); color:#fff; display:grid; place-items:center; margin-bottom:20px; }
        h1 { font-size:1.75rem; letter-spacing:-.03em; margin-bottom:10px; }
        p { color:var(--muted); line-height:1.7; font-size:.95rem; }
        .hello { color:var(--text); font-weight:700; margin-bottom:8px; }
        .notice { margin:24px 0; padding:16px 18px; border:1px solid #fde68a; background:var(--warning-bg); color:var(--warning); border-radius:14px; line-height:1.65; }
        .steps { display:grid; gap:12px; margin:24px 0; }
        .step { border:1px solid var(--border); border-radius:14px; padding:14px 16px; display:flex; gap:12px; align-items:flex-start; }
        .num { width:26px; height:26px; border-radius:999px; background:var(--accent); color:#fff; display:grid; place-items:center; flex:0 0 auto; font-size:.8rem; font-weight:700; }
        .step strong { display:block; margin-bottom:3px; }
        .actions { display:flex; flex-wrap:wrap; gap:12px; margin-top:28px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; padding:0 18px; border-radius:12px; text-decoration:none; font-weight:700; font-size:.9rem; }
        .btn-primary { background:var(--accent); color:#fff; }
        .btn-light { background:#fff; color:var(--accent); border:1px solid var(--border); }
        .flash { margin-bottom:18px; padding:12px 14px; border-radius:12px; background:var(--success-bg); color:var(--success); border:1px solid #bbf7d0; }
        .error { margin-bottom:18px; padding:12px 14px; border-radius:12px; background:var(--warning-bg); color:var(--warning); border:1px solid #fde68a; }
        code { font-family:'JetBrains Mono',monospace; font-size:.88em; background:#f1f5f9; padding:2px 5px; border-radius:6px; }
    </style>

<style id="frontend-ui-refinement">
@media screen {
    :root { --radius: 6px !important; }
    body { display: block !important; padding: 42px 24px !important; background: #f3f4f6 !important; }
    body::before { display: none !important; }
    .card { width: min(760px, 100%) !important; margin: 0 auto !important; border-radius: 6px !important; box-shadow: none !important; padding: 28px !important; }
    .mark { width: 42px !important; height: 42px !important; border-radius: 5px !important; margin-bottom: 16px !important; }
    .mark svg { width: 23px !important; height: 23px !important; }
    h1 { font-size: 1.45rem !important; }
    p { line-height: 1.6 !important; }
    .notice, .step, .btn, .flash, .error { border-radius: 5px !important; }
    .notice { margin: 20px 0 !important; padding: 13px 15px !important; }
    .steps { gap: 0 !important; margin: 20px 0 !important; border: 1px solid var(--border) !important; border-radius: 6px !important; overflow: hidden !important; }
    .step { border: 0 !important; border-bottom: 1px solid var(--border) !important; border-radius: 0 !important; padding: 13px 15px !important; }
    .step:last-child { border-bottom: 0 !important; }
    .num { border-radius: 4px !important; }
    .actions { margin-top: 22px !important; }
    @media (max-width: 560px) { body { padding: 20px 16px !important; } .card { padding: 22px !important; } }
}
</style>
<link rel="stylesheet" href="../common/ui-consistency.css">
<script src="../common/ui-consistency.js" defer></script>
</head>
<body>
    <main class="card">
        <div class="mark">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
        </div>

        <?php if ($flash): ?>
            <div class="flash"><?= e($flash['msg'] ?? '') ?></div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
            <div class="error"><?= e($errorMsg) ?></div>
        <?php endif; ?>

        <p class="hello">Hello, <?= e($fullName) ?>.</p>
        <h1>Ask admin for account setup</h1>
        <p>Your account is active, but no system pages have been assigned to you yet. For security, newly registered <code>Operator</code> accounts cannot open the dashboard, billing, collection, reports, settings, or any module until an administrator gives page access.</p>

        <div class="notice">
            Please contact a Super Admin or Admin and ask them to configure your account in <strong>Access Control</strong>.
        </div>

        <div class="steps">
            <div class="step"><span class="num">1</span><div><strong>Admin goes to Access Control</strong><p>Select your operator/profile from the list.</p></div></div>
            <div class="step"><span class="num">2</span><div><strong>Admin checks your allowed pages</strong><p>Example: Dashboard, Collection Management, Water Bills Collection, or any pages needed for your job.</p></div></div>
            <div class="step"><span class="num">3</span><div><strong>Admin saves access</strong><p>After saving, log in again or refresh this page and you will be sent to your allowed page.</p></div></div>
        </div>

        <div class="actions">
            <a class="btn btn-primary" href="account_setup_required.php">Refresh Access</a>
            <a class="btn btn-light" href="../process/logout.php">Log Out</a>
        </div>
    </main>
</body>
</html>
