<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

require_once __DIR__ . '/../database/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Feature Coming Soon - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        /* ─── RESET & TOKENS ─── */
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
            --accent-hover: #33334d;
            --info-bg: #eff6ff;
            --info: #2563eb;
            --radius-sm: 8px; 
            --radius: 14px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06); 
            --shadow-lg: 0 20px 60px rgba(0,0,0,.08);
        }
        
        html { height: 100%; font-size: 16px; }
        body { 
            font-family: 'Sora', sans-serif; 
            background: var(--off-white); 
            color: var(--text-primary); 
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ─── LAYOUT ─── */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }

        /* ─── COMING SOON CARD ─── */
        .coming-soon-wrapper {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            padding: 56px 48px;
            max-width: 540px;
            width: 100%;
            text-align: center;
            animation: fadeScaleUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes fadeScaleUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .icon-container {
            width: 80px;
            height: 80px;
            background: var(--info-bg);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px auto;
            color: var(--info);
            position: relative;
        }

        /* Subtle pulsing background effect for the icon */
        .icon-container::before {
            content: '';
            position: absolute;
            inset: -10px;
            background: var(--info-bg);
            border-radius: 24px;
            z-index: -1;
            animation: pulseBg 2s infinite ease-in-out;
        }

        @keyframes pulseBg {
            0% { transform: scale(0.95); opacity: 0.8; }
            50% { transform: scale(1.1); opacity: 0.2; }
            100% { transform: scale(0.95); opacity: 0.8; }
        }

        .icon-container svg { width: 36px; height: 36px; }

        .title {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            margin-bottom: 12px;
            color: var(--text-primary);
        }

        .description {
            font-size: .95rem;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 40px;
        }

        /* ─── PROGRESS BAR SIMULATION ─── */
        .progress-track {
            height: 6px;
            background: var(--off-white);
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 40px;
            position: relative;
        }
        
        .progress-fill {
            position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 45%; /* Change this percentage to simulate progress */
            background: var(--accent);
            border-radius: 10px;
            animation: loadProgress 2s ease-out forwards;
        }

        @keyframes loadProgress {
            from { width: 0%; }
        }

        /* ─── BUTTONS ─── */
        .btn-primary { 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            gap: 10px; 
            padding: 14px 32px; 
            font-family: 'Sora', sans-serif; 
            font-size: .9rem; 
            font-weight: 600; 
            border-radius: var(--radius-sm); 
            cursor: pointer; 
            transition: all .2s; 
            border: none;
            background: var(--accent); 
            color: var(--white); 
            text-decoration: none;
        }
        
        .btn-primary:hover { 
            background: var(--accent-hover); 
            transform: translateY(-2px); 
            box-shadow: 0 8px 20px rgba(26,26,46,.15);
        }

        .btn-primary svg { width: 18px; height: 18px; }

        @media (max-width: 600px) {
            .coming-soon-wrapper { padding: 40px 24px; }
            .title { font-size: 1.5rem; }
        }
    </style>

<style id="frontend-ui-refinement">
@media screen {
    .main-content { align-items: flex-start !important; padding: 42px 24px !important; }
    .coming-soon-wrapper {
        max-width: 720px !important;
        padding: 28px !important;
        text-align: left !important;
        border-radius: 6px !important;
        box-shadow: none !important;
        animation: none !important;
    }
    .icon-container {
        width: 42px !important;
        height: 42px !important;
        margin: 0 0 16px !important;
        border-radius: 5px !important;
    }
    .icon-container::before, .progress-track { display: none !important; }
    .icon-container svg { width: 21px !important; height: 21px !important; }
    .title { font-size: 1.45rem !important; margin-bottom: 8px !important; }
    .description { margin-bottom: 22px !important; max-width: 620px !important; }
    .btn-primary { padding: 10px 14px !important; border-radius: 5px !important; }
    .btn-primary:hover { transform: none !important; box-shadow: none !important; }
}
</style>
</head>
<body>

<?php 
// Assuming you have your standard navbar here
// require_once '../common/navbar.php'; 
?>

<div class="main-content">
    <div class="coming-soon-wrapper">
        
        <div class="icon-container">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 2v4"></path>
                <path d="M12 18v4"></path>
                <path d="M4.93 4.93l2.83 2.83"></path>
                <path d="M16.24 16.24l2.83 2.83"></path>
                <path d="M2 12h4"></path>
                <path d="M18 12h4"></path>
                <path d="M4.93 19.07l2.83-2.83"></path>
                <path d="M16.24 7.76l2.83-2.83"></path>
            </svg>
        </div>

        <h1 class="title">Module Not Available</h1>
        <p class="description">
            This Water Billing module is not available in the current release. Return to the previous page and continue using the available operational modules.
        </p>

        <div class="progress-track">
            <div class="progress-fill"></div>
        </div>

        <a href="javascript:history.back()" class="btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Return to Previous Page
        </a>

    </div>
</div>

</body>
</html>