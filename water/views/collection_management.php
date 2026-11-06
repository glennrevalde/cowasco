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

if (!function_exists('e')) {
    function e($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Collection Management - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --white:          #ffffff;
            --off-white:      #f8f9fb;
            --border:         #e8eaed;
            --border-focus:   #1a1a2e;
            --text-primary:   #0f0f1a;
            --text-secondary: #6b7280;
            --accent:         #1a1a2e;
            --radius-sm:      8px;
            --radius:         14px;
            --shadow-sm:      0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
        }

        html { height: 100%; font-size: 16px; }
        body {
            font-family: 'Sora', sans-serif;
            background: var(--off-white);
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* ============================
           PAGE HEADER & LAYOUT
        ============================ */
        .page { padding: 32px 32px 60px; animation: fadeIn .4s ease both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
        }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; color: var(--text-primary); }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; font-weight: 400; }

        /* ============================
           QUICK ACTIONS GRID
        ============================ */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .action-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            text-decoration: none;
            color: var(--text-primary);
            transition: transform .2s, box-shadow .2s, border-color .2s;
        }
        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-sm);
            border-color: var(--border-focus);
        }
        .ac-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background: var(--off-white); border: 1px solid var(--border);
            color: var(--text-secondary); transition: all .2s; flex-shrink: 0;
        }
        .ac-icon svg { width: 22px; height: 22px; }
        
        /* General hover state for icons */
        .action-card:hover .ac-icon {
            background: #2563eb; color: var(--white); border-color: #2563eb;
        }
        
        .ac-title { font-weight: 700; font-size: 1rem; margin-bottom: 6px; letter-spacing: -.01em; }
        .ac-desc { font-size: .8125rem; color: var(--text-secondary); line-height: 1.4; }

        @media (max-width: 768px) {
            .page { padding: 20px 16px; }
            .action-grid { grid-template-columns: 1fr; }
        }
    </style>

<style id="frontend-ui-refinement">
@media screen {
    :root {
        --radius-sm: 5px !important;
        --radius: 6px !important;
        --shadow-sm: none !important;
    }
    .page {
        max-width: 1180px !important;
        margin: 0 auto !important;
        padding: 28px 28px 48px !important;
        animation: none !important;
    }
    .page-header {
        align-items: center !important;
        margin-bottom: 18px !important;
        padding-bottom: 16px !important;
        border-bottom: 1px solid var(--border) !important;
    }
    .page-title { font-size: 1.42rem !important; letter-spacing: -.02em !important; }
    .page-subtitle { margin-top: 5px !important; line-height: 1.5 !important; }
    .action-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 0 !important;
        border: 1px solid var(--border) !important;
        border-radius: 6px !important;
        overflow: hidden !important;
        background: var(--white) !important;
    }
    .action-card {
        min-height: 104px !important;
        padding: 18px 20px !important;
        gap: 14px !important;
        border: 0 !important;
        border-right: 1px solid var(--border) !important;
        border-bottom: 1px solid var(--border) !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        transition: background-color .15s ease, color .15s ease !important;
    }
    .action-card:nth-child(2n) { border-right: 0 !important; }
    .action-card:hover,
    .action-card:focus-visible {
        transform: none !important;
        box-shadow: none !important;
        border-color: var(--border) !important;
        background: #f8fafc !important;
        outline: none !important;
    }
    .ac-icon {
        width: 38px !important;
        height: 38px !important;
        border-radius: 5px !important;
        background: #f3f4f6 !important;
        color: #374151 !important;
        transition: none !important;
    }
    .ac-icon svg { width: 18px !important; height: 18px !important; }
    .action-card:hover .ac-icon,
    .action-card:focus-visible .ac-icon {
        background: #e5e7eb !important;
        color: var(--accent) !important;
        border-color: #d1d5db !important;
    }
    .ac-title { margin-bottom: 4px !important; font-size: .94rem !important; }
    .ac-desc { line-height: 1.5 !important; }
    @media (max-width: 760px) {
        .page { padding: 22px 16px 40px !important; }
        .action-grid { grid-template-columns: 1fr !important; }
        .action-card { border-right: 0 !important; }
    }
}
@media (prefers-reduced-motion: reduce) {
    .page, .action-card, .ac-icon { animation: none !important; transition: none !important; }
}
</style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">

    <div class="page-header">
        <div>
            <h1 class="page-title">Collection Hub</h1>
            <p class="page-subtitle">Select a payment stream to process transactions and view history.</p>
        </div>
    </div>

    <div class="action-grid">
        
        <a href="water_bills_collection.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22a7 7 0 0 0 7-7c0-2-1-3.9-3-5.5s-3.5-4-4-6.5c-.5 2.5-2 4.9-4 6.5C6 11.1 5 13 5 15a7 7 0 0 0 7 7z"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">Unified Collections</div>
                <div class="ac-desc">Process water bills and receivables in one cashiering page, with optional cashier-entered Penalty and Compromise under Other Fees.</div>
            </div>
        </a>

        <a href="collection_corrections.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 12a9 9 0 1 0 3-6.7"></path><polyline points="3 3 3 9 9 9"></polyline><path d="M12 7v5l3 2"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">Refunds & Error Corrections</div>
                <div class="ac-desc">Reverse a cashier error or refund any collection transaction. The system restores all allocations and creates an opposite journal entry.</div>
            </div>
        </a>

        <a href="payment_history.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div>
                <div class="ac-title">Payment History Ledger</div>
                <div class="ac-desc">View a unified, auditable log of all cash collections, manually applied Penalty and Compromise fees, and generated receipts.</div>
            </div>
        </a>
        
    </div>

</main>
</div>

</body>
</html>