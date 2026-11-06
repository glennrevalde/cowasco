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

require_once '../common/superAdminGuard.php';

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
    <title>Billing Management - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
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

        .page { padding: 32px 32px 60px; animation: fadeIn .4s ease both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
        }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; color: var(--text-primary); }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; font-weight: 400; }

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
        .action-card:hover .ac-icon {
            background: var(--accent); color: var(--white); border-color: var(--accent);
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
            <h1 class="page-title">Billing Hub</h1>
            <p class="page-subtitle">Select a module to manage billing operations</p>
        </div>
    </div>

    <div class="action-grid">
        
        <a href="bill_period.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
            </div>
            <div>
                <div class="ac-title">Bill Period</div>
                <div class="ac-desc">Open or close active billing months and set reading deadlines.</div>
            </div>
        </a>

        <a href="reading_entry.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
            </div>
            <div>
                <div class="ac-title">Reading Entry</div>
                <div class="ac-desc">Register a new meter, connection, or consumer account.</div>
            </div>
        </a>

        <a href="generate_soa.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <line x1="10" y1="9" x2="8" y2="9"></line>
                </svg>
            </div>
            <div>
                <div class="ac-title">Post Bills</div>
                <div class="ac-desc">Review and post pending water-reading drafts. Already-posted bills are excluded.</div>
            </div>
        </a>

        <a href="recurring_bill.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"></polyline>
                    <polyline points="1 20 1 14 7 14"></polyline>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">Recurring Bills</div>
                <div class="ac-desc">Manage automated periodic charges like share capital or maintenance fees.</div>
            </div>
        </a>

        <a href="installment_bill.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                    <line x1="7" y1="15" x2="7.01" y2="15"></line>
                    <line x1="11" y1="15" x2="15" y2="15"></line>
                </svg>
            </div>
            <div>
                <div class="ac-title">Installment Bills</div>
                <div class="ac-desc">Create amortization schedules and manage staggered payments.</div>
            </div>
        </a>

        <a href="one_time_billing.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">One-Time Billing</div>
                <div class="ac-desc">Issue immediate custom charges like penalties, repairs, or materials.</div>
            </div>
        </a>

        <a href="share_capital_billing.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <path d="M9 16l2 2 4-4"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">Fixed Share Capital Billing</div>
                <div class="ac-desc">Fixed share capital contributions of members, posted on their day and collected by the cashier.</div>
            </div>
        </a>

        <a href="bill_codes.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">Bill Codes Dictionary</div>
                <div class="ac-desc">Define standard categories, reference codes, and fee descriptions.</div>
            </div>
        </a>


<?php if (wb_is_super_admin()): ?>
        <a href="billing_adjustments.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 20h9"></path>
                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"></path>
                </svg>
            </div>
            <div>
                <div class="ac-title">Bill Maintenance</div>
                <div class="ac-desc">Edit posted/paid bills and soft-delete records without physically removing them.</div>
            </div>
        </a>

<?php endif; ?>

        <a href="billing_list.php" class="action-card">
            <div class="ac-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/><path d="M2 6h.01M2 12h.01M2 18h.01"/></svg></div>
            <div><div class="ac-title">Unified Billing List</div><div class="ac-desc">Search water, one-time, installment, and recurring receivables in one authoritative register.</div></div>
        </a>

        <a href="billing_history.php" class="action-card">
            <div class="ac-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="12" y1="18" x2="12" y2="12"></line>
                    <line x1="9" y1="15" x2="15" y2="15"></line>
                </svg>
            </div>
            <div>
                <div class="ac-title">Billing History</div>
                <div class="ac-desc">View, filter, and track all generated bills, statements, and historical charges.</div>
            </div>
        </a>
        
    </div>

</main>
</div>

</body>
</html>