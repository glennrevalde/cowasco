<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

$pdo = getDB();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/*
 * Central settings launchers. The existing configuration pages remain the
 * single source of truth for saving, validation, permissions, and database
 * updates. This page only organizes those pages in one settings workspace.
 */
$settingsActions = [
    [
        'title' => 'Institution Configuration',
        'description' => 'Maintain the current Manager and General Manager names used as institution report signatories, including the Statement of Account.',
        'file' => 'institution_configuration.php',
        'page_key' => 'institution_configuration',
        'button' => 'Open Institution Configuration',
    ],
    [
        'title' => 'Other Fees & Session',
        'description' => 'Activate or deactivate the manual Penalty and Compromise fees, map their income accounts, and set automatic logout after a chosen number of inactive hours.',
        'file' => 'collection_policy_configuration.php',
        'page_key' => 'collection_policy_configuration',
        'button' => 'Open Other Fees & Session',
    ],
    [
        'title' => 'User Configurations',
        'description' => 'Manage operators, profile assignments, password-reset requests, profile status, and the CashGL account used during collection posting.',
        'file' => 'user_configuration.php',
        'page_key' => 'user_configuration',
        'button' => 'Open User Configurations',
    ],
    [
        'title' => 'Book Types',
        'description' => 'Configure the accounting book classifications used to organize journal entries and financial transactions.',
        'file' => 'book_types.php',
        'page_key' => 'book_types',
        'button' => 'Open Book Types',
    ],
    [
        'title' => 'Access Control',
        'description' => 'Assign page permissions to each operator and profile combination, manage access presets, and control which system modules are available.',
        'file' => 'access_control.php',
        'page_key' => 'access_control',
        'button' => 'Open Access Control',
    ],
    [
        'title' => 'Receipt Layout Setup',
        'description' => 'Design the pre-printed water bill / collection receipt and official receipt: the paper, the top, bottom, left and right margins, and where each entry prints. Printed or Pre-Printed is chosen on Company, Security & Closing Date.',
        'file' => 'receipt_layouts.php',
        'page_key' => 'receipt_layouts',
        'button' => 'Open Receipt Layout Setup',
    ],
    [
        'title' => 'Address Sub-groups',
        'description' => 'Add, rename, sort, switch off or delete the address sub-groups (purok, sitio, zone) chosen on the member form and on the water-service accounts.',
        'file' => 'address_subgroups.php',
        'page_key' => 'address_subgroups',
        'button' => 'Open Address Sub-groups',
    ],
];

foreach ($settingsActions as &$settingsAction) {
    $physicalFile = (string)(parse_url($settingsAction['file'], PHP_URL_PATH) ?: '');
    $settingsAction['exists'] = $physicalFile !== '' && is_file(__DIR__ . '/' . $physicalFile);
    $settingsAction['allowed'] = $settingsAction['exists']
        && userCanAccessPage($pdo, (string)$settingsAction['page_key']);
}
unset($settingsAction);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Settings - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --white:          #ffffff;
            --off-white:      #f8f9fb;
            --border:         #e8eaed;
            --text-primary:   #0f0f1a;
            --text-secondary: #6b7280;
            --accent:         #1a1a2e;
            --success:        #059669;
            --danger:         #dc2626;
            --radius-sm:      5px;
            --radius:         6px;
        }

        html { min-height: 100%; font-size: 16px; }
        body {
            min-height: 100vh;
            background: var(--off-white);
            color: var(--text-primary);
            font-family: 'Sora', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 28px 48px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
            color: var(--text-secondary);
            font-size: .8rem;
            font-weight: 600;
            text-decoration: none;
        }

        .back-link:hover { color: var(--text-primary); }
        .page-title { font-size: 1.42rem; font-weight: 700; letter-spacing: -.02em; }
        .page-subtitle {
            max-width: 820px;
            margin-top: 5px;
            color: var(--text-secondary);
            font-size: .875rem;
            line-height: 1.5;
        }

        .settings-section { margin-bottom: 24px; }
        .settings-section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }
        .settings-section-title { font-size: .98rem; font-weight: 700; letter-spacing: -.02em; }
        .settings-section-copy {
            margin-top: 4px;
            color: var(--text-secondary);
            font-size: .8125rem;
            line-height: 1.5;
        }
        .settings-count {
            flex: 0 0 auto;
            padding: 5px 9px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--white);
            color: var(--text-secondary);
            font: 600 .7rem 'JetBrains Mono', monospace;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--white);
        }

        .action-card {
            min-height: 148px;
            display: flex;
            align-items: center;
            padding: 18px 20px;
            border-right: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            color: var(--text-primary);
            text-decoration: none;
            transition: background-color .15s ease;
        }

        .action-card:nth-child(2n) { border-right: 0; }
        .action-card:nth-last-child(-n+2):nth-child(odd),
        .action-card:last-child { border-bottom: 0; }
        .action-card:last-child:nth-child(odd) { grid-column: 1 / -1; border-right: 0; }
        .action-card:hover,
        .action-card:focus-visible {
            background: #f8fafc;
            outline: none;
        }
        .action-card.is-disabled {
            background: #fafafa;
            cursor: default;
            opacity: .72;
        }
        .action-card.is-disabled:hover { background: #fafafa; }

        .ac-body { width: 100%; min-width: 0; }
        .ac-title { margin-bottom: 4px; font-size: .94rem; font-weight: 700; }
        .ac-desc {
            min-height: 58px;
            color: var(--text-secondary);
            font-size: .8125rem;
            line-height: 1.5;
        }
        .ac-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }
        .ac-status { color: var(--success); font-size: .68rem; font-weight: 700; }
        .ac-status.is-denied { color: var(--danger); }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 7px 11px;
            border: 0;
            border-radius: var(--radius-sm);
            background: var(--accent);
            color: var(--white);
            font: 600 .74rem 'Sora', sans-serif;
            white-space: nowrap;
        }
        .btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .btn.is-disabled {
            background: #e5e7eb;
            color: #9ca3af;
            pointer-events: none;
        }

        @media (max-width: 760px) {
            .page { padding: 22px 16px 40px; }
            .settings-section-heading { align-items: flex-start; flex-direction: column; }
            .action-grid { grid-template-columns: 1fr; }
            .action-card {
                min-height: 0;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }
            .action-card:nth-last-child(-n+2):nth-child(odd),
            .action-card:last-child { border-bottom: 1px solid var(--border); }
            .action-card:last-child { grid-column: auto; border-bottom: 0; }
            .ac-desc { min-height: 0; }
            .ac-footer { align-items: flex-start; flex-direction: column; }
        }

        @media (prefers-reduced-motion: reduce) {
            .action-card { transition: none; }
        }
    </style>
</head>
<body>
    <?php require_once '../common/navbar.php'; ?>

    <div class="main-content">
        <main class="page">
            <div class="page-header">
                <div>
                    <a href="dashboard.php" class="back-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Back to Dashboard
                    </a>
                    <h1 class="page-title">Settings</h1>
                    <p class="page-subtitle">Central workspace for institution officers, user configuration, accounting book types, access permissions, and controlled legacy-data migration planning. Water transactions carry the Class of the accounting books (Financial &gt; Class List).</p>
                </div>
            </div>

            <?php
            // FORCE CONFIGURATION status: derived live from saved configuration.
            require_once __DIR__ . '/../common/setupReadinessHelpers.php';
            $setupReadiness = [];
            try { $setupReadiness = sr_evaluate(getDB(), true); } catch (Throwable $ignored) { $setupReadiness = []; }
            $setupFlashSuccess = $_SESSION['success_msg'] ?? null; unset($_SESSION['success_msg']);
            $setupFlashError = $_SESSION['error_msg'] ?? null; unset($_SESSION['error_msg']);
            $setupSequenceMissing = false;
            try { $setupSequenceMissing = (int)getDB()->query("SELECT COUNT(*) FROM syssequence WHERE SeqName = 'SysJVIDSeq' AND SeqStatus = 'A'")->fetchColumn() <= 0; } catch (Throwable $ignored) {}
            ?>
            <?php if ($setupFlashSuccess): ?><div class="notice is-success" role="status" style="margin:0 0 16px;padding:12px 14px;border:1px solid #a7f3d0;border-radius:6px;background:#ecfdf5;color:#047857;font-size:.85rem;"><?= e($setupFlashSuccess) ?></div><?php endif; ?>
            <?php if ($setupFlashError): ?><div class="notice is-danger" role="alert" style="margin:0 0 16px;padding:12px 14px;border:1px solid #fecaca;border-radius:6px;background:#fef2f2;color:#b91c1c;font-size:.85rem;"><?= e($setupFlashError) ?></div><?php endif; ?>
            <?php if ($setupReadiness): ?>
            <section class="settings-section" aria-labelledby="setupStatusHeading" id="setupStatusSection">
                <div class="settings-section-heading">
                    <div>
                        <h2 class="settings-section-title" id="setupStatusHeading">Configuration Readiness</h2>
                        <p class="settings-section-copy">Every business transaction is checked against these requirements immediately before it runs. Status is derived from the saved configuration, never from a checklist. Optional module steps apply only when that module is used.</p>
                    </div>
                    <span class="settings-count"><?= count(array_filter($setupReadiness, static fn($r) => $r['status'] === 'ready')) ?> of <?= count($setupReadiness) ?> ready</span>
                </div>
                <div class="ui-table-scroll">
                    <table class="setup-status-table" style="width:100%;border-collapse:collapse;font-size:.83rem;">
                        <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border,#e8eaed);">Step</th><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border,#e8eaed);">Requirement</th><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border,#e8eaed);">Status</th><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border,#e8eaed);">Explanation</th><th style="text-align:left;padding:8px;border-bottom:1px solid var(--border,#e8eaed);">Where</th></tr></thead>
                        <tbody>
                        <?php foreach ($setupReadiness as $req): ?>
                            <tr>
                                <td style="padding:8px;border-bottom:1px solid var(--border,#e8eaed);white-space:nowrap;"><?= (int)$req['step'] ?><?= !empty($req['optional']) ? ' (optional)' : '' ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--border,#e8eaed);"><strong><?= e($req['title']) ?></strong><?php if (!empty($req['depends_on'])): ?><div style="color:var(--text-muted,#9ca3af);font-size:.72rem;">after: <?= e(implode(', ', $req['depends_on'])) ?></div><?php endif; ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--border,#e8eaed);white-space:nowrap;font-weight:700;color:<?= $req['status'] === 'ready' ? '#047857' : '#b91c1c' ?>;"><?= $req['status'] === 'ready' ? 'Ready' : ($req['status'] === 'unknown' ? 'Not verified' : 'Required') ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--border,#e8eaed);"><?= e($req['message']) ?><?php if (!empty($req['details'])): ?><div style="color:var(--text-muted,#9ca3af);font-size:.72rem;margin-top:3px;"><?= e(implode(' | ', $req['details'])) ?></div><?php endif; ?></td>
                                <td style="padding:8px;border-bottom:1px solid var(--border,#e8eaed);white-space:nowrap;">
                                    <?php if ($req['destination'] !== '' && !empty($req['can_configure'])): ?>
                                        <a href="<?= e($req['destination']) ?>#setup-step-<?= e($req['key']) ?>">Open</a>
                                    <?php elseif ($req['destination'] === ''): ?>
                                        <span style="color:var(--text-muted,#9ca3af);">No editing screen</span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted,#9ca3af);">Administrator</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($setupSequenceMissing && userCanAccessPage(getDB(), 'settings')): ?>
                    <form method="post" action="../process/repairSetupPrerequisite.php" style="margin-top:12px;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="item" value="jv_sequence">
                        <p style="font-size:.83rem;margin:0 0 8px;">The journal voucher numbering sequence has no editing screen. This permission-controlled repair only creates or re-activates the <code>SysJVIDSeq</code> sequence row, seeded from the highest voucher number already used.</p>
                        <button type="submit" class="btn">Create journal numbering sequence</button>
                    </form>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="settings-section" aria-labelledby="systemSettingsHeading">
                <div class="settings-section-heading">
                    <div>
                        <h2 class="settings-section-title" id="systemSettingsHeading">System Configuration Pages</h2>
                        <p class="settings-section-copy">Open the system's existing configuration pages from one location. Each option continues to use its original forms, validations, permissions, and database processes.</p>
                    </div>
                    <span class="settings-count"><?= count($settingsActions) ?> OPTIONS</span>
                </div>

                <div class="action-grid">
                    <?php foreach ($settingsActions as $action): ?>
                        <?php
                            $isAvailable = !empty($action['exists']);
                            $isAllowed = !empty($action['allowed']);
                            $isDisabled = !$isAvailable || !$isAllowed;
                            $statusText = !$isAvailable ? 'Page unavailable' : ($isAllowed ? 'Ready to configure' : 'Access not assigned');
                            $cardTag = $isDisabled ? 'div' : 'a';
                        ?>
                        <<?= $cardTag ?> <?= $isDisabled ? '' : 'href="' . e($action['file']) . '"' ?> class="action-card<?= $isDisabled ? ' is-disabled' : '' ?>">
                            <div class="ac-body">
                                <div class="ac-title"><?= e($action['title']) ?></div>
                                <div class="ac-desc"><?= e($action['description']) ?></div>
                                <div class="ac-footer">
                                    <span class="ac-status<?= $isDisabled ? ' is-denied' : '' ?>"><?= e($statusText) ?></span>
                                    <?php if (!$isDisabled): ?>
                                        <span class="btn">
                                            <?= e($action['button']) ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="13 6 19 12 13 18"></polyline></svg>
                                        </span>
                                    <?php else: ?>
                                        <span class="btn is-disabled" aria-disabled="true"><?= e($action['button']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </<?= $cardTag ?>>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
