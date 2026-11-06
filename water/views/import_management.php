<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/dataImportHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';

$pdo = getDB();
requirePageAccess($pdo, 'import_management');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('di_option_label')) {
    /**
     * Shorten a dropdown label to $limit characters for display.
     *
     * $tail is the part that must survive the cut. Tariff options repeat the
     * same rate code across several pipe sizes, and cities repeat a city name
     * across provinces, so trimming from the end would leave rows that read
     * identically and cannot be told apart. The middle is cut instead:
     *
     *   A — RESIDENTIAL REGULAR / INSTITUTIONAL / 1 1/2 inch
     *   A — RESIDENTIAL REG... / 1 1/2 inch
     *
     * The full text is kept in the option's title attribute by the caller.
     */
    function di_option_label(string $head, string $tail = '', string $separator = ' / ', int $limit = 35): string
    {
        $head = trim($head);
        $tail = trim($tail);
        $full = $tail !== '' ? $head . $separator . $tail : $head;

        if (mb_strlen($full) <= $limit) {
            return $full;
        }

        // Nothing to protect, or the tail alone already fills the budget:
        // fall back to a plain cut from the end.
        if ($tail === '' || mb_strlen($tail . $separator) + 4 > $limit) {
            return rtrim(mb_substr($full, 0, $limit - 3)) . '...';
        }

        $budget = $limit - mb_strlen($separator) - mb_strlen($tail) - 3;

        return rtrim(mb_substr($head, 0, max(1, $budget))) . '...' . $separator . $tail;
    }
}

$schemaReady = di_schema_ready($pdo);
$compatibilityIssues = $schemaReady ? di_compatibility_issues($pdo) : [];
$importReady = $schemaReady && !$compatibilityIssues;
$lookups = $importReady ? di_lookup_data($pdo) : [];
$batches = $schemaReady ? di_batches($pdo, 20) : [];
$batchId = max(0, (int)($_GET['batch_id'] ?? 0));
$batch = $batchId > 0 && $schemaReady ? di_batch($pdo, $batchId) : null;

// Preview filters (searchable / filterable row-level warnings).
$previewFilters = [
    'status' => preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['f_status'] ?? ''))),
    'scope' => preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['f_scope'] ?? ''))),
    'severity' => preg_replace('/[^a-z]/', '', strtolower((string)($_GET['f_severity'] ?? ''))),
    'code' => preg_replace('/[^A-Z0-9_]/', '', strtoupper((string)($_GET['f_code'] ?? ''))),
    'q' => trim((string)($_GET['f_q'] ?? '')),
];
$previewLimit = max(20, min(200, (int)($_GET['f_limit'] ?? 60)));
$preview = [];
$previewTotal = 0;
if ($batch) {
    $where = 'batch_id=?';
    $params = [$batchId];
    if ($previewFilters['status'] !== '') { $where .= ' AND (import_status=? OR payment_status=? OR recurring_status=?)'; array_push($params, $previewFilters['status'], $previewFilters['status'], $previewFilters['status']); }
    if ($previewFilters['code'] !== '') { $where .= ' AND issues_json LIKE ?'; $params[] = '%"code":"' . $previewFilters['code'] . '"%'; }
    if ($previewFilters['scope'] !== '') { $where .= ' AND issues_json LIKE ?'; $params[] = '%"scope":"' . $previewFilters['scope'] . '"%'; }
    if ($previewFilters['severity'] !== '') { $where .= ' AND issues_json LIKE ?'; $params[] = '%"severity":"' . $previewFilters['severity'] . '"%'; }
    if ($previewFilters['q'] !== '') { $where .= ' AND (external_account_no LIKE ? OR source_key LIKE ? OR raw_json LIKE ?)'; $like = '%' . $previewFilters['q'] . '%'; array_push($params, $like, $like, $like); }
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM data_import_rows WHERE ' . $where);
    $countStmt->execute($params);
    $previewTotal = (int)$countStmt->fetchColumn();
    $rowsStmt = $pdo->prepare(
        'SELECT row_id,`row_number`,external_account_no,source_key,import_status,payment_status,recurring_status,issues_json,normalized_json,target_member_id,target_bill_id,target_payment_id '
        . 'FROM data_import_rows WHERE ' . $where
        . " ORDER BY CASE WHEN import_status IN ('review','error') OR payment_status IN ('review','error') OR recurring_status IN ('review','error') THEN 0 ELSE 1 END, row_id LIMIT " . $previewLimit
    );
    $rowsStmt->execute($params);
    $preview = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($preview as &$previewRow) {
        $previewRow['issues'] = di_json_decode($previewRow['issues_json'] ?? '[]');
        $previewRow['normalized'] = di_json_decode($previewRow['normalized_json'] ?? '{}');
    }
    unset($previewRow);
}
$summary = $batch ? di_batch_summary($pdo, $batchId, $batch) : [];
$mappingRules = $schemaReady ? di_mapping_rules($pdo, true) : [];
$rulesTableReady = $schemaReady && di_rules_table_ready($pdo);
$mappingProfiles = $schemaReady ? di_mapping_profiles($pdo) : [];
$reconciliation = [];
if ($batch) {
    $reconciliation = di_json_decode((string)($batch['reconciliation_json'] ?? ''));
    if (!$reconciliation && !in_array((string)$batch['status'], ['staging', 'revalidating'], true) && (int)$batch['row_count'] <= 5000) {
        try { $reconciliation = di_batch_reconciliation($pdo, $batchId, true); } catch (Throwable $ignored) { $reconciliation = []; }
    }
}
$batchSnapshot = $batch ? di_json_decode((string)($batch['mapping_json'] ?? '')) : [];
$currentSnapshot = $schemaReady ? di_rules_snapshot($pdo) : ['hash' => ''];
$batchRulesStale = $batch && $batchSnapshot && (string)($batchSnapshot['hash'] ?? '') !== (string)$currentSnapshot['hash'];
$batchInProgress = $batch && in_array((string)$batch['status'], ['staging', 'revalidating'], true);
$sourceRowEstimate = 0;
if ($batchInProgress) {
    $sourcePath = di_storage_dir() . DIRECTORY_SEPARATOR . basename((string)$batch['stored_file_name']);
    $sourceRowEstimate = is_file($sourcePath) ? di_source_row_estimate($sourcePath) : 0;
}
$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$plans = [];
$waterBillRequirement = ['ready' => false, 'message' => 'Water Bill accounting configuration is unavailable.'];
$collectionRequirement = ['ready' => false, 'message' => 'Collection accounting configuration is unavailable.'];
if ($importReady) {
    try { $plans = di_current_recurring_plans($pdo); } catch (Throwable $ignored) { $plans = []; }
    try { $waterBillRequirement = di_water_bill_readiness($pdo); } catch (Throwable $ignored) {}
    try { $collectionRequirement = di_collection_readiness($pdo, (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0)); } catch (Throwable $ignored) {}
}
$plansById = [];
foreach ($plans as $plan) $plansById[(int)$plan['plan_id']] = $plan;

$batchOptions = $batch ? di_json_decode($batch['options_json'] ?? '{}') : [];
$batchRecurringRequirement = ['ready' => true, 'message' => 'Mapped recurring plans are ready.'];
if ($batch && $batch['import_type'] === 'billing_master') {
    $problems = [];
    foreach (($batchOptions['recurring_map'] ?? []) as $column => $planId) {
        $planId = (int)$planId;
        if ($planId <= 0) continue;
        if (empty($plansById[$planId])) {
            $problems[] = $column . ': mapped plan no longer exists';
            continue;
        }
        $readiness = di_recurring_plan_readiness($plansById[$planId], (string)$column);
        if (!$readiness['ready']) $problems[] = $column . ': ' . $readiness['message'];
    }
    if ($problems) {
        $batchRecurringRequirement = ['ready' => false, 'message' => implode(' | ', array_slice($problems, 0, 5))];
    }
}

$batchConfigurationBlockers = [];
$batchNeedsRecurring = false;
$batchNeedsCollections = false;
$billingImportReady = true;
$billingImportRequirementMessage = 'Billing Master configuration is ready.';
if ($batch && $batch['import_type'] === 'billing_master') {
    try { $batchConfigurationBlockers = di_batch_configuration_blockers($pdo, (int)$batchId); } catch (Throwable $ignored) { $batchConfigurationBlockers = []; }
    $batchNeedsRecurring = ((int)($batch['recurring_ready_count'] ?? 0) + (int)($batch['recurring_review_count'] ?? 0) + (int)($batch['recurring_error_count'] ?? 0) + (int)($batch['recurring_imported_count'] ?? 0)) > 0;
    $batchNeedsCollections = ((int)($batch['payment_ready_count'] ?? 0) + (int)($batch['payment_review_count'] ?? 0) + (int)($batch['payment_error_count'] ?? 0) + (int)($batch['payment_imported_count'] ?? 0)) > 0;
    $billingImportReady = empty($batchConfigurationBlockers)
        && !$batchRulesStale
        && !empty($waterBillRequirement['ready'])
        && (!$batchNeedsRecurring || !empty($batchRecurringRequirement['ready']))
        && (!$batchNeedsCollections || !empty($collectionRequirement['ready']));
    if (!$billingImportReady) {
        $parts = [];
        if ($batchRulesStale) $parts[] = 'Mapping decisions changed after validation; click Revalidate batch.';
        foreach (array_slice($batchConfigurationBlockers, 0, 5) as $blocker) $parts[] = $blocker['code'] . ': ' . $blocker['message'];
        if (empty($waterBillRequirement['ready'])) $parts[] = (string)($waterBillRequirement['message'] ?? 'Water Bill accounting is incomplete.');
        if ($batchNeedsRecurring && empty($batchRecurringRequirement['ready'])) $parts[] = (string)($batchRecurringRequirement['message'] ?? 'Recurring mappings are incomplete.');
        if ($batchNeedsCollections && empty($collectionRequirement['ready'])) $parts[] = (string)($collectionRequirement['message'] ?? 'Collection accounting is incomplete.');
        $billingImportRequirementMessage = implode(' | ', array_values(array_unique($parts)));
    }
}

$issueGuidance = [
    'WATER_BILL_ACCOUNTING_REQUIRED' => 'Open Bill Codes and configure the WATER BILL code. It must have a valid AR account for the debit side and an Income account for the credit side.',
    'RATE_MAPPING' => 'Map the legacy Rate Code to an existing destination Rate Code before revalidating the batch.',
    'TARIFF_CONFIGURATION' => 'Choose a Rate Code + Pipe Size combination that exists in the Water Meter tariff configuration, then revalidate.',
    'RECURRING_PLAN_REQUIRED' => 'Create the matching bill code first, configure its AR/debit and Income/credit accounts, then create an active recurring plan and map this source charge to it.',
    'RECURRING_PLAN_INACTIVE' => 'Activate the recurring plan or map the source charge to another active plan.',
    'BILL_CODE_ACCOUNTING_REQUIRED' => 'Open Bill Codes and complete the charge accounting. AR is the debit/receivable side; Income is the credit/revenue side.',
    'BILL_CODE_MISMATCH' => 'Use a bill code that identifies the same charge. Example: DamayanContribution should use a DAMAYAN bill code, not an unrelated fee code.',
    'COLLECTION_ACCOUNTING_REQUIRED' => 'Configure the active operator profile CashGL/debit account and make sure the bill receivable accounts are accounting-ready before importing collections.',
    'PENALTY_ACCOUNT_REQUIRED' => 'Configure an active Penalty Income/credit account in Collection Policy Configuration.',
    'COMPROMISE_ACCOUNT_REQUIRED' => 'Configure an active Compromise Income/credit account in Collection Policy Configuration.',
    'MEMBER_NOT_FOUND' => 'Import or create the missing Account Master member first. Billing links by Account No, not by customer name.',
    'RULE_UNRESOLVED' => 'Confirm the mapping decision in the Mapping Decisions panel, then revalidate the batch.',
    'CHARGE_SEMANTICS_UNRESOLVED' => 'Confirm what this charge column means (Mapping Decisions), then map it to the matching plan and revalidate.',
    'GL_POLICY_UNRESOLVED' => 'Confirm the general-ledger policy for imported history in Mapping Decisions, then revalidate.',
    'EXISTING_RECEIPT_CONFLICT' => 'A receipt with this number already exists with a different account, total or date. Decide whether receipt numbers are reused (receipt scope rule) or whether this row is a true duplicate.',
    'PARTIAL_PAYMENT' => 'Amt Paid covers only part of the validated total. The partial-payment rule decides whether it is recorded as partial or kept for review.',
    'TOTAL_MISMATCH' => 'The source Total does not equal Bill Amount + charges + penalty + compromise. Confirm what Total includes; the row is never adjusted silently.',
    'CHANGE_MISMATCH' => 'Cash Change does not equal Amt Paid minus the applied amount. Confirm the meaning of Amt Paid (Mapping Decisions).',
    'PAYMENT_EVIDENCE_INCOMPLETE' => 'Payment Flag is TRUE but the receipt number or Date Paid is missing, so settlement is not proven under the confirmed rule.',
    'MAPPING_REFERENCE_INVALID' => 'A rate code, pipe size, plan, discount or city selected for this batch no longer exists. Edit the batch mappings, then revalidate.',
    'DUPLICATE_RECEIPT' => 'The same receipt number appears more than once in the file (split allocation or duplicate). Confirm before importing.',
    'BLANK_OPTIONAL_FEE' => 'Blank optional fee cells are treated as unknown under the current rule; confirm the blank policy in Mapping Decisions.',
    'DATE_CHRONOLOGY' => 'Prev Rdg Date is after Pres Rdg Date; confirm the source dates.',
];

$modeLabels = [
    'members' => 'Import all ready members',
    'bills' => 'Import all ready water bills',
    'recurring' => 'Import all ready recurring charges',
    'payments' => 'Import all ready collections',
    'opening' => 'Post opening balances to the ledger',
];
// under the opening-balance policy the bills still open after the history is imported are posted to the ledger as
// opening invoices on the cut-over date (the last step of a Billing Master)
$openingPolicy = function_exists('di_rule') && di_rule($pdo, 'gl_policy') === 'opening_balance';
$cutoverDefault = function_exists('di_cutover_date') ? (static function () { try { return di_cutover_date(); } catch (Throwable $e) { return date('Y-m-d'); } })() : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Data Import Management - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --white: #ffffff;
            --off-white: #f8f9fb;
            --border: #e8eaed;
            --border-strong: #d7dbe1;
            --text-primary: #0f0f1a;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --accent: #1a1a2e;
            --warning-bg: #fffbeb;
            --warning-border: #f3d78a;
            --warning-text: #8a5a00;
            --success-bg: #ecfdf5;
            --success-border: #a7f3d0;
            --success-text: #047857;
            --danger-bg: #fef2f2;
            --danger-border: #fecaca;
            --danger-text: #b91c1c;
            --info-bg: #eff6ff;
            --info-border: #bfdbfe;
            --info-text: #1e40af;
            --radius: 6px;
            --radius-sm: 5px;
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
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 5px;
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
            line-height: 1.55;
        }

        /* status as plain coloured text, not a capsule */
        .page-state {
            display: inline-block;
            color: #b45309;
            font: 600 .7rem 'JetBrains Mono', monospace;
            white-space: nowrap;
        }
        .page-state.is-ready { color: #047857; }

        .notice {
            margin-bottom: 22px;
            padding: 14px 16px;
            border: 1px solid var(--warning-border);
            border-radius: var(--radius);
            background: var(--warning-bg);
            color: var(--warning-text);
            font-size: .8125rem;
            line-height: 1.55;
        }
        .notice strong { display: block; margin-bottom: 3px; color: #6b4300; }
        .notice.is-success {
            border-color: var(--success-border);
            background: var(--success-bg);
            color: var(--success-text);
        }
        .notice.is-success strong { color: #065f46; }
        .notice.is-danger {
            border-color: var(--danger-border);
            background: var(--danger-bg);
            color: var(--danger-text);
        }
        .notice.is-danger strong { color: #991b1b; }
        .notice.is-info {
            border-color: var(--info-border);
            background: var(--info-bg);
            color: var(--info-text);
        }
        .notice.is-info strong { color: #1e3a8a; }

        .requirement-guide {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin: 0 0 20px;
        }
        .requirement-item {
            padding: 13px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--white);
        }
        .requirement-item.is-ready { border-color: var(--success-border); background: var(--success-bg); }
        .requirement-item.is-blocked { border-color: var(--danger-border); background: var(--danger-bg); }
        .requirement-title {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 5px;
            font-size: .76rem;
            font-weight: 700;
        }
        .requirement-copy { color: var(--text-secondary); font-size: .7rem; line-height: 1.48; }
        .requirement-status {
            display: inline-block;
            margin-top: 8px;
            font: 600 .64rem 'JetBrains Mono', monospace;
        }
        .help-tip {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 17px;
            height: 17px;
            border: 1px solid #cbd5e1;
            border-radius: 50%;
            background: #fff;
            color: #475569;
            font: 700 .65rem/1 'Sora', sans-serif;
            cursor: help;
            vertical-align: middle;
            outline: none;
        }
        .help-tip::after {
            content: attr(data-tooltip);
            position: absolute;
            z-index: 40;
            left: 50%;
            bottom: calc(100% + 8px);
            width: min(310px, 70vw);
            padding: 9px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #111827;
            color: #fff;
            font: 500 .68rem/1.45 'Sora', sans-serif;
            text-align: left;
            white-space: normal;
            box-shadow: 0 8px 22px rgba(15,23,42,.18);
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, 4px);
            transition: opacity .12s ease, transform .12s ease;
        }
        .help-tip:hover::after,
        .help-tip:focus::after { opacity: 1; transform: translate(-50%, 0); }
        .field-requirement {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .mapping-readiness {
            margin-top: 6px;
            color: var(--text-muted);
            font-size: .66rem;
            line-height: 1.45;
        }
        .mapping-readiness.is-blocked { color: var(--danger-text); }
        .btn[disabled] { cursor: not-allowed; opacity: .48; }

        .section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }
        .section-title { font-size: .98rem; font-weight: 700; letter-spacing: -.02em; }
        .section-copy {
            margin-top: 4px;
            color: var(--text-secondary);
            font-size: .8125rem;
            line-height: 1.5;
        }
        .section-count {
            flex: 0 0 auto;
            color: var(--text-secondary);
            font: 600 .7rem 'JetBrains Mono', monospace;
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--white);
        }
        .module-card {
            min-height: 258px;
            padding: 20px;
            border-right: 1px solid var(--border);
            background: var(--white);
        }
        .module-card:last-child { border-right: 0; }
        .module-number {
            margin-bottom: 18px;
            color: var(--text-muted);
            font: 600 .72rem 'JetBrains Mono', monospace;
        }
        .module-title { margin-bottom: 7px; font-size: .98rem; font-weight: 700; }
        .module-description {
            min-height: 96px;
            color: var(--text-secondary);
            font-size: .8125rem;
            line-height: 1.55;
        }
        .module-meta {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }
        .meta-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            font-size: .72rem;
            line-height: 1.4;
        }
        .meta-row:last-child { margin-bottom: 0; }
        .meta-label { color: var(--text-muted); }
        .meta-value { color: var(--text-secondary); text-align: right; font-weight: 600; }
        .disabled-action {
            width: 100%;
            margin-top: 16px;
            padding: 8px 11px;
            border: 0;
            border-radius: var(--radius-sm);
            background: #e5e7eb;
            color: #9ca3af;
            font: 600 .74rem 'Sora', sans-serif;
            cursor: not-allowed;
        }


        .import-progress-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 15, 26, .52);
            backdrop-filter: blur(3px);
        }
        .import-progress-overlay.is-visible { display: flex; }
        .import-progress-card {
            width: min(560px, 100%);
            padding: 26px;
            border: 1px solid var(--border-strong);
            border-radius: 12px;
            background: var(--white);
            box-shadow: 0 24px 60px rgba(15, 15, 26, .22);
        }
        .import-progress-eyebrow {
            color: var(--text-muted);
            font: 600 .7rem 'JetBrains Mono', monospace;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .import-progress-heading {
            margin-top: 8px;
            font-size: 1.12rem;
            font-weight: 700;
        }
        .import-progress-percent {
            margin-top: 20px;
            font: 700 2.35rem 'JetBrains Mono', monospace;
            letter-spacing: -.05em;
        }
        .import-progress-track {
            height: 12px;
            margin-top: 14px;
            overflow: hidden;
            border-radius: 999px;
            background: #e9ecf1;
        }
        .import-progress-fill {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: var(--accent);
            transition: width .28s ease;
        }
        .import-progress-status {
            margin-top: 14px;
            color: var(--text-secondary);
            font-size: .82rem;
            line-height: 1.55;
        }
        .import-progress-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 18px;
        }
        .import-progress-stat {
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--off-white);
        }
        .import-progress-stat strong {
            display: block;
            font: 700 .88rem 'JetBrains Mono', monospace;
        }
        .import-progress-stat span {
            display: block;
            margin-top: 3px;
            color: var(--text-muted);
            font-size: .68rem;
        }
        .import-progress-error {
            display: none;
            margin-top: 16px;
            padding: 11px 12px;
            border: 1px solid var(--danger-border);
            border-radius: var(--radius);
            background: var(--danger-bg);
            color: var(--danger-text);
            font-size: .78rem;
            line-height: 1.5;
        }
        .import-progress-error.is-visible { display: block; }
        .import-progress-close {
            display: none;
            width: 100%;
            margin-top: 14px;
        }
        .import-progress-close.is-visible { display: inline-flex; justify-content: center; }
        body.import-is-running { overflow: hidden; }

        .workflow {
            margin-top: 24px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--white);
            overflow: hidden;
        }
        .workflow-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
        }
        .workflow-title { font-size: .9rem; font-weight: 700; }
        .workflow-copy {
            margin-top: 4px;
            color: var(--text-secondary);
            font-size: .78rem;
            line-height: 1.5;
        }
        .workflow-list {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
        .workflow-step {
            position: relative;
            padding: 18px;
            border-right: 1px solid var(--border);
        }
        .workflow-step:nth-child(4n) { border-right: 0; }
        .workflow-step:nth-child(n+5) { border-top: 1px solid var(--border); }
        .workflow-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            margin-bottom: 9px;
            border-radius: 50%;
            background: var(--accent);
            color: var(--white);
            font: 600 .72rem 'JetBrains Mono', monospace;
        }
        .workflow-step-title { font-size: .8rem; font-weight: 700; }
        .workflow-step-copy {
            margin-top: 4px;
            color: var(--text-secondary);
            font-size: .73rem;
            line-height: 1.5;
        }

        .panel {
            margin-top: 24px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--white);
            overflow: hidden;
        }
        .panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
        }
        .panel-title { font-size: .95rem; font-weight: 700; }
        .panel-copy {
            max-width: 820px;
            margin-top: 4px;
            color: var(--text-secondary);
            font-size: .78rem;
            line-height: 1.5;
        }
        .panel-body { padding: 18px; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .form-grid.two-columns { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        /* A grid item defaults to min-width:auto, which lets a select as wide as
           its longest option push past its column and run over the next field.
           Pinning it to 0 keeps every field inside its own track. */
        .field { min-width: 0; }
        .field label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-primary);
            font-size: .73rem;
            font-weight: 700;
        }
        .field input,
        .field select {
            width: 100%;
            min-height: 39px;
            padding: 8px 10px;
            border: 1px solid var(--border-strong);
            border-radius: var(--radius-sm);
            background: var(--white);
            color: var(--text-primary);
            font: 400 .78rem 'Sora', sans-serif;
        }
        .field input[type="file"] { padding: 7px 9px; }
        .field input:focus,
        .field select:focus { outline: 2px solid #c7d2fe; border-color: #818cf8; }

        .mapping-section {
            margin-top: 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: #fbfcfd;
        }
        .mapping-section summary {
            padding: 12px 14px;
            cursor: pointer;
            font-size: .8rem;
            font-weight: 700;
        }
        .mapping-content {
            padding: 0 14px 14px;
            border-top: 1px solid var(--border);
        }
        .mapping-copy {
            margin: 11px 0 13px;
            color: var(--text-secondary);
            font-size: .73rem;
            line-height: 1.5;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .actions form { margin: 0; }
        .actions form.auto-import-form { display: inline-flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; }
        .actions form[data-mode="opening"] { flex-basis: 100%; padding-top: 12px; border-top: 1px solid var(--border); }
        .actions .run-class-field { flex-basis: 100%; max-width: 460px; }
        .actions .run-class-field .water-class-field, .import-class-field .water-class-field, .field .water-class-field { margin: 0; max-width: none; }
        .import-class-field { margin: 14px 0 6px; max-width: 520px; }
        body .main-content .actions .cutover-check { display: inline-flex; align-items: flex-start; gap: 8px; max-width: 520px; font-size: .72rem; color: var(--text-secondary); line-height: 1.45; }
        .cutover-check input { flex: 0 0 auto; width: 16px; height: 16px; margin: 2px 0 0; }
        body .main-content .actions .cutover-field { font-size: .72rem; }
        .cutover-field { display: inline-flex; flex-direction: column; gap: 3px; font-size: .68rem; color: var(--text-secondary); }
        .cutover-field input { min-height: 38px; padding: 7px 9px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--white); color: var(--text-primary); font: 400 .78rem 'Sora', sans-serif; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 9px 13px;
            border: 0;
            border-radius: var(--radius-sm);
            background: var(--accent);
            color: var(--white);
            font: 600 .74rem 'Sora', sans-serif;
            text-decoration: none;
            cursor: pointer;
        }
        .btn:hover { background: #292943; }
        .btn.is-secondary { background: #64748b; }
        .btn.is-secondary:hover { background: #475569; }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0 4px;
        }
        .summary-card {
            min-height: 78px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: #fbfcfd;
        }
        .summary-value { font: 700 1.08rem 'JetBrains Mono', monospace; }
        .summary-label { margin-top: 5px; color: var(--text-secondary); font-size: .68rem; line-height: 1.35; }
        .summary-note { margin-top: 10px; color: var(--text-muted); font-size: .69rem; line-height: 1.5; }

        .table-wrap { width: 100%; overflow-x: auto; }
        .data-table { width: 100%; min-width: 980px; border-collapse: collapse; }
        .data-table th,
        .data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
            font-size: .72rem;
            line-height: 1.45;
        }
        .data-table th {
            background: #f8fafc;
            color: var(--text-secondary);
            font-weight: 700;
            white-space: nowrap;
        }
        .data-table tr:last-child td { border-bottom: 0; }
        .data-table a { color: #4338ca; font-weight: 700; text-decoration: none; }
        .data-table a:hover { text-decoration: underline; }
        .counts { color: var(--text-secondary); font: 500 .68rem 'JetBrains Mono', monospace; white-space: nowrap; }
        .issue { margin-bottom: 5px; }
        .issue:last-child { margin-bottom: 0; }
        .empty-state { padding: 24px; color: var(--text-secondary); font-size: .8rem; text-align: center; }
        .status-key { color: var(--text-muted); font: 500 .66rem 'JetBrains Mono', monospace; }

        @media (max-width: 900px) {
            .module-grid, .workflow-list { grid-template-columns: 1fr; }
            .module-card, .workflow-step {
                min-height: 0;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }
            .module-card:last-child, .workflow-step:last-child { border-bottom: 0; }
            .module-description { min-height: 0; }
        }

        @media (max-width: 760px) {
            .requirement-guide { grid-template-columns: 1fr; }
            .page { padding: 22px 16px 40px; }
            .section-heading { align-items: flex-start; flex-direction: column; }
            .form-grid, .form-grid.two-columns, .summary-grid { grid-template-columns: 1fr; }
            .panel-header { flex-direction: column; }
        }

        /* Select/input consistency rules come from common/ui-consistency.css via navbar.php. */
        .rule-status { display: inline-block; font: 700 .68rem 'JetBrains Mono', monospace; letter-spacing: .04em; text-transform: uppercase; }
        .rule-status.is-confirmed { color: var(--success-text); }
        .rule-status.is-assumed { color: var(--warning-text); }
        .rule-status.is-unresolved { color: var(--danger-text); }
        .rule-evidence { max-width: 320px; font-size: .74rem; color: var(--text-secondary); line-height: 1.45; }
        .rule-form { display: grid; gap: 6px; min-width: 220px; max-width: 340px; position: relative; }
        .rule-form select, .rule-form input { width: 100%; max-width: 340px; }
        /* ui-consistency.css gives hosted tables width:max-content so wide data
           tables keep their columns; this decision table is prose, so it is
           told to fit the host and wrap instead (the host still scrolls on phones). */
        .table-wrap > .mapping-rules-table { width: 100% !important; min-width: 760px; table-layout: fixed; }
        .mapping-rules-table th, .mapping-rules-table td { white-space: normal; overflow-wrap: anywhere; }
        .mapping-rules-table th:nth-child(1) { width: 20%; }
        .mapping-rules-table th:nth-child(2) { width: 24%; }
        .mapping-rules-table th:nth-child(3) { width: 10%; }
        .mapping-rules-table th:nth-child(4) { width: 24%; }
        .mapping-rules-table th:nth-child(5) { width: 22%; }
        .rule-evidence { max-width: none; }
        .mapping-rules-table td, .column-catalog td { vertical-align: top; }
        .column-catalog td { font-size: .74rem; }
        .visually-hidden { position: absolute !important; left: 0; top: 0; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
        .muted { color: var(--text-muted); font-size: .72rem; }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .issue.is-error b { color: var(--danger-text); }
        .issue.is-review b { color: var(--warning-text); }
        .comparison { margin-top: 6px; }
        .comparison summary { cursor: pointer; font-size: .74rem; font-weight: 600; }
        .comparison-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: .74rem; }
        .comparison-table th, .comparison-table td { padding: 4px 6px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        .comparison-table tr.is-different td { background: var(--warning-bg); }
        .preview-filter .form-grid { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .preview-table td { vertical-align: top; }
    </style>
</head>
<body>
    <?php require_once '../common/navbar.php'; ?>

    <div class="main-content">
        <main class="page">
            <div class="page-header">
                <div>
                    <a href="settings.php" class="back-link">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Back to Settings
                    </a>
                    <h1 class="page-title">Data Import Management</h1>
                    <p class="page-subtitle">Import the approved Account Master CSV and Billing Master CSV/XLSX here. The system detects the file type from its exact column headers and stages every row for review before any live record is created.</p>
                </div>
                <span class="page-state<?= $importReady ? ' is-ready' : '' ?>"><?= $importReady ? 'IMPORT READY' : 'SETUP REQUIRED' ?></span>
            </div>

            <?php if (!empty($flash['success'])): ?>
                <div class="notice is-success" role="status"><strong>Action completed.</strong><?= e($flash['success']) ?></div>
            <?php endif; ?>
            <?php if (!empty($flash['error'])): ?>
                <div class="notice is-danger" role="alert"><strong>Action could not be completed.</strong><?= e($flash['error']) ?></div>
            <?php endif; ?>

            <?php if (!$schemaReady): ?>
                <div class="notice is-danger" role="alert">
                    <strong>The importer database tables are not installed.</strong>
                    Back up the database, then run <b>database/migrations/20260831_create_data_importer.sql</b> once. No migration runs automatically.
                </div>
            <?php elseif ($compatibilityIssues): ?>
                <div class="notice is-danger" role="alert">
                    <strong>The current database is not compatible with this importer.</strong>
                    <?= e(implode('; ', $compatibilityIssues)) ?>
                </div>
            <?php else: ?>
                <div class="notice is-success" role="status">
                    <strong>Use this page for both source files.</strong>
                    Upload the Account Master first and import its Ready rows. Then upload the Billing Master here as well. Do not enter these accounts or bills through Water Service Accounts or Billing Management.
                </div>
            <?php endif; ?>

            <section class="workflow" aria-labelledby="migrationOrderHeading">
                <div class="workflow-header">
                    <h2 class="workflow-title" id="migrationOrderHeading">Required Migration Order</h2>
                    <p class="workflow-copy">Finish the Ready count for each stage before continuing. Review and Error rows are never imported automatically.</p>
                </div>
                <div class="workflow-list">
                    <?php foreach ([
                        ['1', 'Upload', 'Accept the approved Account Master CSV or Billing Master CSV/XLSX.'],
                        ['2', 'Validate', 'Check headers, identifiers, dates, tariff mappings, and financial fields. The Account Master import requires Account No; missing name or tariff details are warnings.'],
                        ['3', 'Preview', 'Review totals, valid/invalid records, existing data, and rows needing attention.'],
                        ['4', 'Match accounts', 'Match Billing Master rows to the water-service accounts by normalized Account No only.'],
                        ['5', 'Detect duplicates', 'Flag duplicate account/date keys and reused receipt numbers before import.'],
                        ['6', 'Confirm', 'Import buttons require an explicit confirmation and process only Ready rows.'],
                        ['7', 'Import', 'Write in bounded transactions with row savepoints and idempotent operational lookups.'],
                        ['8', 'Summary', 'Recheck counts and download issue details after every chunk or re-import.'],
                    ] as $step): ?>
                        <div class="workflow-step">
                            <span class="workflow-step-number"><?= e($step[0]) ?></span>
                            <div class="workflow-step-title"><?= e($step[1]) ?></div>
                            <p class="workflow-step-copy"><?= e($step[2]) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php if ($importReady): ?>
                <?php
                $readyPlanCount = 0;
                foreach ($plans as $plan) if (!empty($plan['migration_ready'])) $readyPlanCount++;
                ?>
                <div class="requirement-guide" aria-label="Migration configuration requirements">
                    <div class="requirement-item <?= !empty($waterBillRequirement['ready']) ? 'is-ready' : 'is-blocked' ?>">
                        <div class="requirement-title">
                            Water Bill Accounting
                            <span class="help-tip" tabindex="0" data-tooltip="<?= e($waterBillRequirement['message'] ?? '') ?>" aria-label="Water Bill accounting help">?</span>
                        </div>
                        <div class="requirement-copy">Historical water bills require the WATER BILL bill code plus a valid AR/debit account and Income/credit account.</div>
                        <span class="requirement-status"><?= !empty($waterBillRequirement['ready']) ? 'READY' : 'BLOCKING IMPORT' ?></span>
                    </div>
                    <div class="requirement-item <?= $readyPlanCount > 0 ? 'is-ready' : 'is-blocked' ?>">
                        <div class="requirement-title">
                            Recurring Charge Setup
                            <span class="help-tip" tabindex="0" data-tooltip="Each positive source charge needs its own matching bill code and active recurring plan. Example: DamayanContribution → DAMAYAN bill code → AR/debit account + Income/credit account → active recurring plan." aria-label="Recurring charge setup help">?</span>
                        </div>
                        <div class="requirement-copy"><?= (int)$readyPlanCount ?> accounting-ready active recurring plan(s) found. The selected bill code must also identify the same source charge.</div>
                        <span class="requirement-status"><?= $readyPlanCount > 0 ? 'CHECK MAPPINGS' : 'SETUP REQUIRED' ?></span>
                    </div>
                    <div class="requirement-item <?= !empty($collectionRequirement['ready']) ? 'is-ready' : 'is-blocked' ?>">
                        <div class="requirement-title">
                            Collection Accounting
                            <span class="help-tip" tabindex="0" data-tooltip="<?= e($collectionRequirement['message'] ?? '') ?> Penalty/Compromise receipts additionally require their configured active Income/credit accounts." aria-label="Collection accounting help">?</span>
                        </div>
                        <div class="requirement-copy">Collection import requires an active operator-profile CashGL/debit account plus valid receivable accounting.</div>
                        <span class="requirement-status"><?= !empty($collectionRequirement['ready']) ? 'READY' : 'BLOCKING COLLECTIONS' ?></span>
                    </div>
                </div>
                <div class="notice is-info">
                    <strong>Staging is always safe; importing is configuration-gated.</strong>
                    You may upload and preview the Billing Master even while setup is incomplete. Rows with missing accounting, bill-code, recurring-plan, tariff, or account requirements stay in Review and cannot migrate. Fix the requirement, then click Revalidate batch.
                </div>

                <section class="panel" aria-labelledby="mappingDecisionsHeading" id="mappingDecisionsPanel">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="mappingDecisionsHeading">Mapping Decisions</h2>
                            <p class="panel-copy">Every financial interpretation the importer applies is listed here with its status. <b>Confirmed</b> rules were decided by the owner; <b>assumed</b> rules describe the current behaviour and are shown until confirmed; <b>unresolved</b> rules keep the rows that depend on them in Review. Changing a rule never rewrites staged rows silently: affected batches must be revalidated before they can import.</p>
                        </div>
                        <span class="section-count"><?= count(array_filter($mappingRules, static fn($r) => $r['status'] === 'confirmed')) ?> CONFIRMED / <?= count(array_filter($mappingRules, static fn($r) => $r['status'] === 'unresolved')) ?> UNRESOLVED</span>
                    </div>
                    <?php if (!$rulesTableReady): ?>
                        <div class="panel-body"><div class="notice is-danger"><strong>The mapping-rule table is not installed.</strong> Run <b>database/migrations/20260924_audit_fixes_mysql55.sql</b> once. Until then every rule is treated as unresolved and financial rows stay in Review.</div></div>
                    <?php endif; ?>
                    <div class="table-wrap">
                        <table class="data-table mapping-rules-table">
                            <thead><tr><th>Decision</th><th>Current rule</th><th>Status</th><th>Evidence from the supplied files</th><th>Change</th></tr></thead>
                            <tbody>
                            <?php foreach ($mappingRules as $ruleKey => $ruleRow): ?>
                                <tr>
                                    <td><strong><?= e($ruleRow['label']) ?></strong><div class="mono muted"><?= e($ruleKey) ?></div></td>
                                    <td><?= e($ruleRow['value_label']) ?><?php if ($ruleRow['notes'] !== ''): ?><div class="muted"><?= e($ruleRow['notes']) ?></div><?php endif; ?></td>
                                    <td><span class="rule-status is-<?= e($ruleRow['status']) ?>"><?= e(ucfirst($ruleRow['status'])) ?></span><?php if (!empty($ruleRow['confirmed_at']) && $ruleRow['status'] === 'confirmed'): ?><div class="muted"><?= e(substr((string)$ruleRow['confirmed_at'], 0, 16)) ?></div><?php endif; ?></td>
                                    <td class="rule-evidence"><?= e($ruleRow['evidence']) ?></td>
                                    <td>
                                        <?php if ($rulesTableReady): ?>
                                        <form method="post" action="../process/saveImportMappingRule.php" class="rule-form">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="rule_key" value="<?= e($ruleKey) ?>">
                                            <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                            <label class="visually-hidden" for="rule_<?= e(md5($ruleKey)) ?>">New value for <?= e($ruleRow['label']) ?></label>
                                            <select id="rule_<?= e(md5($ruleKey)) ?>" name="rule_value" data-no-search="true">
                                                <?php foreach ($ruleRow['options'] as $optionValue => $optionLabel): ?>
                                                    <option value="<?= e($optionValue) ?>" <?= $optionValue === $ruleRow['value'] ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="notes" maxlength="500" placeholder="Reason / reference (optional)" aria-label="Reason for <?= e($ruleRow['label']) ?>">
                                            <button type="submit" class="btn is-secondary">Confirm</button>
                                        </form>
                                        <?php else: ?><span class="muted">Migration required</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="panel-body">
                        <details class="mapping-section">
                            <summary>Billing Master column-by-column mapping (<?= count(di_billing_column_catalog()) ?> source columns)</summary>
                            <div class="table-wrap">
                                <table class="data-table column-catalog">
                                    <thead><tr><th>Source column</th><th>Type / format</th><th>Destination or preserved metadata</th><th>Transformation</th><th>Blank policy</th><th>Validation</th><th>Approval</th></tr></thead>
                                    <tbody>
                                    <?php foreach (di_billing_column_catalog() as $columnName => $meta): ?>
                                        <?php $ruleRef = strncmp((string)$meta['rule'], 'rule:', 5) === 0 ? substr((string)$meta['rule'], 5) : ''; $ruleState = $ruleRef !== '' ? ($mappingRules[$ruleRef]['status'] ?? 'unresolved') : 'confirmed'; ?>
                                        <tr>
                                            <td><strong><?= e($columnName) ?></strong></td>
                                            <td><?= e($meta['type']) ?></td>
                                            <td><?= e($meta['destination']) ?></td>
                                            <td><?= e($meta['transform']) ?></td>
                                            <td><?= e($meta['blank']) ?></td>
                                            <td><?= e($meta['validation']) ?></td>
                                            <td><span class="rule-status is-<?= e($ruleState) ?>"><?= e(ucfirst($ruleState)) ?></span><?php if ($ruleRef !== ''): ?><div class="muted mono"><?= e($ruleRef) ?></div><?php endif; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                        <details class="mapping-section">
                            <summary>Account Master column-by-column mapping (<?= count(di_account_column_catalog()) ?> source columns, all preserved)</summary>
                            <div class="table-wrap">
                                <table class="data-table column-catalog">
                                    <thead><tr><th>Source column</th><th>Type / format</th><th>Destination or preserved metadata</th><th>Transformation</th><th>Blank policy</th><th>Validation</th></tr></thead>
                                    <tbody>
                                    <?php foreach (di_account_column_catalog() as $columnName => $meta): ?>
                                        <tr>
                                            <td><strong><?= e($columnName) ?></strong></td>
                                            <td><?= e($meta['type']) ?></td>
                                            <td><?= e($meta['destination']) ?></td>
                                            <td><?= e($meta['transform']) ?></td>
                                            <td><?= e($meta['blank']) ?></td>
                                            <td><?= e($meta['validation']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>
                </section>

                <section class="panel" aria-labelledby="uploadHeading">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="uploadHeading">Upload Migration Source</h2>
                            <p class="panel-copy">The same uploader accepts the Account Master CSV and the Billing Master CSV/XLSX. XLSX is normalized to a protected CSV staging copy. Uploading validates only; operational records are created only after confirmation.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="post" enctype="multipart/form-data" action="../process/stageImportCsv.php">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="csv_file">Account Master CSV or Billing Master CSV/XLSX</label>
                                    <input id="csv_file" type="file" name="csv_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                                </div>
                                <div class="field">
                                    <label for="stage_payments">Prepare safe collections from Billing Master</label>
                                    <select id="stage_payments" name="stage_payments">
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="mapping_profile_id">Reuse a saved mapping profile</label>
                                    <select id="mapping_profile_id" name="mapping_profile_id" data-no-search="true">
                                        <option value="0">None (fill the mappings below)</option>
                                        <?php foreach ($mappingProfiles as $profileRow): ?>
                                            <option value="<?= (int)$profileRow['profile_id'] ?>" data-options="<?= e(json_encode($profileRow['options'])) ?>" <?= $profileRow['profile_name'] === 'Former water program files' ? 'selected' : '' ?>><?= e($profileRow['profile_name']) ?> — v<?= (int)$profileRow['version'] ?> (<?= e($profileRow['import_type']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mapping-readiness">Selecting a profile fills the mapping fields below; references that no longer exist are flagged during validation.</div>
                                </div>
                                <div class="field">
                                    <label for="save_profile_name">Save these mappings as a profile (optional)</label>
                                    <input id="save_profile_name" type="text" name="save_profile_name" maxlength="80" placeholder="e.g. Billing Master 2026 mappings">
                                </div>
                            </div>

                            <details class="mapping-section" open>
                                <summary>Account and historical billing tariff mappings</summary>
                                <div class="mapping-content">
                                    <p class="mapping-copy">Account No is the authoritative member key. Account Master rows are allowed even when firstname/lastname are not applicable (for companies, schools, offices, churches, associations, and other institutions) or when a legacy Rate Code is blank. If a valid tariff mapping is available it is assigned; otherwise the member is still imported with no rate assignment and the UNSPECIFIED/default pipe size so the tariff can be completed later. Billing Master still requires a valid historical rate-and-pipe mapping before a water bill can be imported. Existing member records are preserved.</p>
                                    <!-- Two columns, not three: four source codes sit 2x2 instead of
                                         3 + 1, and each tariff label gets roughly half again the width. -->
                                    <div class="form-grid two-columns">
                                        <?php foreach (['A', 'B', 'C', 'D'] as $sourceCode): ?>
                                            <div class="field">
                                                <label for="tariff_map_<?= e($sourceCode) ?>">Source rate code <?= e($sourceCode) ?></label>
                                                <select id="tariff_map_<?= e($sourceCode) ?>" name="tariff_map[<?= e($sourceCode) ?>]">
                                                    <option value="">Select a configured tariff group</option>
                                                    <?php foreach (($lookups['tariff_groups'] ?? []) as $row): ?>
                                                        <?php
                                                        // The pipe size is what separates one tariff row from the next,
                                                        // so it is held back from the cut.
                                                        $tariffHead = $row['rc_code'] . ' — ' . $row['rc_name'];
                                                        $tariffLabel = $tariffHead . ' / ' . $row['size_label'];
                                                        ?>
                                                        <option value="<?= (int)$row['rc_id'] ?>|<?= (int)$row['pipe_size_id'] ?>" title="<?= e($tariffLabel) ?>" data-short-label="<?= e(di_option_label($tariffHead, (string)$row['size_label'])) ?>"><?= e($tariffLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-grid" style="margin-top:14px">
                                        <div class="field">
                                            <label for="address_mode">Address handling</label>
                                            <select id="address_mode" name="address_mode">
                                                <option value="source" selected>Import Account Address exactly</option>
                                                <option value="default">Import with one default city and ZIP</option>
                                                <option value="skip">Preserve in staging only</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label for="default_city_id">Optional default city</label>
                                            <select id="default_city_id" name="default_city_id">
                                                <option value="0">Select a city</option>
                                                <?php foreach (($lookups['cities'] ?? []) as $row): ?>
                                                    <?php $cityLabel = $row['city'] . ' — ' . $row['province']; ?>
                                                    <option value="<?= (int)$row['pkey'] ?>" title="<?= e($cityLabel) ?>" data-short-label="<?= e(di_option_label((string)$row['city'], (string)$row['province'], ' — ')) ?>"><?= e($cityLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label for="default_zip">Optional default ZIP</label>
                                            <input id="default_zip" type="number" name="default_zip" min="0" max="32767" inputmode="numeric">
                                        </div>
                                        <div class="field">
                                            <label for="source_discount_id">Discount Flag mapping</label>
                                            <select id="source_discount_id" name="source_discount_id">
                                                <option value="0">Do not assign automatically</option>
                                                <?php foreach (($lookups['discounts'] ?? []) as $row): ?>
                                                    <option value="<?= (int)$row['discount_id'] ?>" title="<?= e($row['discount_type']) ?>" data-short-label="<?= e(di_option_label((string)$row['discount_type'])) ?>"><?= e($row['discount_type']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label for="senior_discount_id">SeniorMaster mapping</label>
                                            <select id="senior_discount_id" name="senior_discount_id">
                                                <option value="0">Do not assign automatically</option>
                                                <?php foreach (($lookups['discounts'] ?? []) as $row): ?>
                                                    <option value="<?= (int)$row['discount_id'] ?>" title="<?= e($row['discount_type']) ?>" data-short-label="<?= e(di_option_label((string)$row['discount_type'])) ?>"><?= e($row['discount_type']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </details>

                            <details class="mapping-section" open>
                                <summary>Billing Master recurring-charge mappings</summary>
                                <div class="mapping-content">
                                    <p class="mapping-copy">Damayan, Share Capital, FUNOS, and every other positive non-water column are recurring bills. Each populated charge must have a <b>matching bill code</b>, a valid <b>AR/debit account</b>, a valid <b>Income/credit account</b>, and an <b>active recurring plan</b>. The importer blocks incomplete or unrelated mappings.</p>
                                    <?php if (!$plans): ?>
                                        <div class="notice is-danger"><strong>No recurring plans are configured.</strong>Billing can still be uploaded for preview, but positive recurring-charge values cannot migrate. Create the bill code for each charge (for example DAMAYAN), configure its debit/credit accounting, create the recurring plan, then stage/revalidate.</div>
                                    <?php endif; ?>
                                    <div class="form-grid two-columns">
                                        <?php foreach (di_recurring_columns() as $column): ?>
                                            <div class="field">
                                                <div class="field-requirement">
                                                    <label for="recurring_<?= e(md5($column)) ?>"><?= e($column) ?></label>
                                                    <span class="help-tip" tabindex="0" data-tooltip="Required path: <?= e($column) ?> → matching Bill Code → AR/debit account + Income/credit account → active Recurring Plan. An unrelated or incomplete plan cannot be selected." aria-label="<?= e($column) ?> migration requirements">?</span>
                                                </div>
                                                <select id="recurring_<?= e(md5($column)) ?>" name="recurring_map[<?= e($column) ?>]">
                                                    <option value="0">Not configured — keep affected rows in Review</option>
                                                    <?php foreach ($plans as $plan): ?>
                                                        <?php $planForColumn = di_recurring_plan_readiness($plan, $column); ?>
                                                        <option value="<?= (int)$plan['plan_id'] ?>" <?= !$planForColumn['ready'] ? 'disabled' : '' ?>><?= e(($planForColumn['ready'] ? 'READY — ' : 'BLOCKED — ') . $plan['code'] . ' — ' . $plan['description'] . ' / ' . $plan['frequency'] . ' / ₱' . $plan['amount'] . ' (' . $plan['status'] . ')' . (!$planForColumn['ready'] ? ' — ' . $planForColumn['message'] : '')) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="mapping-readiness">Only accounting-complete plans whose bill code matches <?= e($column) ?> are selectable.</div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </details>

                            <div class="field import-class-field">
                                <?= render_water_class_field(null, true, 'default_class_id', 'default_class_id', '', 'Class of the imported bills and collections') ?>
                            </div>

                            <div class="actions"><button type="submit" class="btn">Upload, stage, and validate</button></div>
                        </form>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($schemaReady): ?>
                <section class="panel" aria-labelledby="batchesHeading">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="batchesHeading">Staged Batches</h2>
                            <p class="panel-copy">Open a batch to review validation results and import only rows marked Ready.</p>
                        </div>
                        <span class="section-count"><?= count($batches) ?> SHOWN</span>
                    </div>
                    <?php if (!$batches): ?>
                        <div class="empty-state">No migration-source batches have been staged yet.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>ID</th><th>File</th><th>Type</th><th>Status</th><th>Rows</th><th>Primary</th><th>Recurring</th><th>Collections</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($batches as $row): ?>
                                    <tr>
                                        <td><?= (int)$row['batch_id'] ?></td>
                                        <td><?= e($row['source_name']) ?></td>
                                        <td><?= e($row['import_type']) ?></td>
                                        <td><?= e($row['status']) ?></td>
                                        <td><?= (int)$row['row_count'] ?></td>
                                        <td class="counts">R <?= (int)$row['ready_count'] ?> / V <?= (int)$row['review_count'] ?> / E <?= (int)$row['error_count'] ?> / D <?= (int)$row['imported_count'] ?></td>
                                        <td class="counts">R <?= (int)$row['recurring_ready_count'] ?> / V <?= (int)$row['recurring_review_count'] ?> / E <?= (int)$row['recurring_error_count'] ?> / D <?= (int)$row['recurring_imported_count'] ?></td>
                                        <td class="counts">R <?= (int)$row['payment_ready_count'] ?> / V <?= (int)$row['payment_review_count'] ?> / E <?= (int)$row['payment_error_count'] ?> / D <?= (int)$row['payment_imported_count'] ?></td>
                                        <td><a href="?batch_id=<?= (int)$row['batch_id'] ?>">Review and import</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="panel-body status-key">R = Ready &nbsp; V = Review &nbsp; E = Error &nbsp; D = Imported/Done</div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($batch && $importReady && $batchInProgress): ?>
                <section class="panel" aria-labelledby="stagingHeading" id="stagingPanel"
                    data-batch-id="<?= (int)$batchId ?>"
                    data-action="<?= (string)$batch['status'] === 'staging' ? 'stage' : 'revalidate' ?>"
                    data-total="<?= (int)((string)$batch['status'] === 'staging' ? $sourceRowEstimate : $batch['row_count']) ?>"
                    data-staged="<?= (int)((string)$batch['status'] === 'staging' ? $batch['row_count'] : $batch['stage_next_row'] ?? 0) ?>">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="stagingHeading"><?= (string)$batch['status'] === 'staging' ? 'Staging batch' : 'Revalidating batch' ?> #<?= (int)$batchId ?> — <?= e($batch['source_name']) ?></h2>
                            <p class="panel-copy">Rows are validated in bounded chunks of 2,000, each committed on its own. Closing this page or losing the connection stops at a chunk boundary; committed rows stay staged and the step resumes from the stored cursor. Nothing operational is written during staging or revalidation.</p>
                        </div>
                        <span class="section-count" id="stagingCount">0 / <?= number_format((int)((string)$batch['status'] === 'staging' ? $sourceRowEstimate : $batch['row_count'])) ?></span>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($batch['last_error'])): ?>
                            <div class="notice is-danger" role="alert"><strong>The last chunk stopped.</strong> <?= e($batch['last_error']) ?> Rows committed so far are kept. Press Resume to continue.</div>
                        <?php endif; ?>
                        <div class="import-progress-track" aria-hidden="true"><div class="import-progress-fill" id="stagingFill" style="width:0%"></div></div>
                        <p class="import-progress-status" id="stagingStatus" role="status">Preparing…</p>
                        <div class="actions">
                            <button type="button" class="btn" id="stagingResume">Resume</button>
                            <button type="button" class="btn is-secondary" id="stagingPause">Pause after this chunk</button>
                            <noscript>
                                <form method="post" action="../process/revalidateImportBatch.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                    <input type="hidden" name="sync" value="1">
                                    <button type="submit" class="btn is-secondary">Process the whole batch now (no JavaScript)</button>
                                </form>
                            </noscript>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($batch && $importReady && !$batchInProgress): ?>
                <section class="panel" aria-labelledby="reviewHeading">
                    <div class="panel-header">
                        <div>
                            <h2 class="panel-title" id="reviewHeading">Review Batch #<?= (int)$batchId ?> — <?= e($batch['source_name']) ?></h2>
                            <p class="panel-copy">Required order: members → water bills → recurring charges → collections<?= $openingPolicy ? ' → opening balances' : '' ?>. For Billing Master, use Import entire Billing Master: one click automatically runs water bills → recurring charges → collections<?= $openingPolicy ? ' → opening balances (the bills still open, posted to the ledger on the cut-over date)' : '' ?> in safe database chunks under one progress screen. Source fingerprint SHA-256 <span class="mono"><?= e(substr((string)$batch['source_sha256'], 0, 16)) ?>…</span>, mapping snapshot v<?= (int)($batch['mapping_version'] ?? 0) ?>.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <?php if ($batchRulesStale): ?>
                            <div class="notice is-danger" role="alert">
                                <strong>Mapping decisions changed after this batch was validated.</strong>
                                The rows still reflect the earlier rule snapshot. Click <b>Revalidate batch</b> before importing; the import buttons stay locked until then.
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($batch['last_error']) && (string)$batch['status'] === 'failed'): ?>
                            <div class="notice is-danger" role="alert"><strong>The last import chunk stopped.</strong> <?= e($batch['last_error']) ?> Records committed in completed chunks were kept; they were not rolled back.</div>
                        <?php endif; ?>
                        <div class="notice" role="status">
                            <strong>Review rows stay untouched.</strong>
                            Download the issue list, correct mappings or source data as needed, and revalidate. Collections in Review can leave successfully imported bills outstanding until reconciled.
                        </div>
                        <?php if ($batch['import_type'] === 'billing_master' && !$billingImportReady): ?>
                            <div class="notice is-danger" role="alert">
                                <strong>Billing migration is locked by missing configuration.</strong>
                                <?= e($billingImportRequirementMessage) ?> Fix these requirements, then click <b>Revalidate batch</b>. No Billing Master operational import is allowed while this configuration gate is failing.
                            </div>
                        <?php endif; ?>
                        <?php if ($summary): ?>
                            <div class="summary-grid" aria-label="Import preview summary">
                                <?php foreach ([
                                    'Total records' => 'total_records',
                                    'Valid records' => 'valid_records',
                                    'Invalid records' => 'invalid_records',
                                    'New records' => 'new_records',
                                    'Existing records' => 'existing_records',
                                    'Duplicate records' => 'duplicate_records',
                                    'Updated records' => 'updated_records',
                                    'Unmatched accounts' => 'unmatched_accounts',
                                    'Requires attention' => 'attention_records',
                                    'Imported primary' => 'imported_primary_records',
                                    'Identical existing' => 'existing_identical',
                                    'Differing existing' => 'existing_with_differences',
                                    'Unresolved rules' => 'unresolved_rule_records',
                                    'Conflicting receipts' => 'conflicting_receipts',
                                    'Partial payments' => 'partial_payments',
                                    'Members without tariff' => 'billing_blocked_members',
                                ] as $label => $key): ?>
                                    <div class="summary-card">
                                        <div class="summary-value"><?= number_format((int)($summary[$key] ?? 0)) ?></div>
                                        <div class="summary-label"><?= e($label) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="summary-note">Updated records stay at 0 by design: this migration preserves existing operational member and bill records. A matching Account No is still used for Billing Master relationships even when Account Master descriptive fields differ. "Members without tariff" lists Account Master rows that can be imported now but cannot be billed until a rate code / pipe size is assigned.</p>
                        <?php endif; ?>

                        <?php if ($reconciliation && $batch['import_type'] === 'billing_master'): ?>
                            <details class="mapping-section" open>
                                <summary>Reconciliation: source totals versus destination by status</summary>
                                <div class="mapping-content">
                                    <p class="mapping-copy">Source file totals: <b><?= number_format((int)($reconciliation['source']['rows'] ?? 0)) ?></b> rows, Bill Amount ₱<?= number_format((float)($reconciliation['source']['bill_amount'] ?? 0), 2) ?>, Total ₱<?= number_format((float)($reconciliation['source']['total'] ?? 0), 2) ?>, Amt Paid ₱<?= number_format((float)($reconciliation['source']['amt_paid'] ?? 0), 2) ?>. Computed <?= e(substr((string)($reconciliation['computed_at'] ?? ''), 0, 16)) ?>. Every row is in exactly one bucket per group, so the buckets add up to the source; Review, Error and Conflict rows are the explained exclusions, Skipped rows already existed, and imported amounts equal what was written.</p>
                                    <div class="table-wrap">
                                        <table class="data-table">
                                            <thead><tr><th>Status</th><th>Water bills (count / ₱)</th><th>Recurring charges (count / ₱)</th><th>Collections applied (count / ₱)</th></tr></thead>
                                            <tbody>
                                            <?php foreach (['ready' => 'Ready', 'review' => 'Review (blocked, explained)', 'error' => 'Error', 'skipped' => 'Skipped (identical existing)', 'conflict' => 'Conflicting existing receipt', 'imported' => 'Imported', 'not_applicable' => 'Not applicable'] as $bucketKey => $bucketLabel): ?>
                                                <tr>
                                                    <td><?= e($bucketLabel) ?></td>
                                                    <?php foreach (['bills', 'charges', 'payments'] as $group): ?>
                                                        <?php $bucket = $reconciliation[$group][$bucketKey] ?? ['count' => 0, 'amount' => 0]; ?>
                                                        <td class="counts"><?= number_format((int)$bucket['count']) ?> / ₱<?= number_format((float)$bucket['amount'], 2) ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <form method="post" action="../process/refreshImportReconciliation.php" style="margin-top:10px">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                        <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                        <button type="submit" class="btn is-secondary">Recompute totals</button>
                                    </form>
                                </div>
                            </details>
                        <?php elseif ($reconciliation && $batch['import_type'] === 'account_master'): ?>
                            <p class="summary-note">Account Master reconciliation: <?= number_format((int)($reconciliation['source']['rows'] ?? 0)) ?> source rows = <?= number_format((int)($reconciliation['members']['ready']['count'] ?? 0)) ?> ready + <?= number_format((int)($reconciliation['members']['skipped']['count'] ?? 0)) ?> existing (preserved) + <?= number_format((int)($reconciliation['members']['review']['count'] ?? 0)) ?> review + <?= number_format((int)($reconciliation['members']['error']['count'] ?? 0)) ?> error + <?= number_format((int)($reconciliation['members']['imported']['count'] ?? 0)) ?> imported.</p>
                        <?php endif; ?>
                        <details class="mapping-section" id="batchMappingEditor">
                            <summary>Edit this batch's mappings (tariff, discounts, recurring plans, class) and revalidate</summary>
                            <div class="mapping-content">
                                <p class="mapping-copy">Mappings are versioned with the batch (<?= count($batchOptions['option_history'] ?? []) ?> earlier version<?= count($batchOptions['option_history'] ?? []) === 1 ? '' : 's' ?> kept). Saving revalidates only the rows that have not been imported; imported rows keep their identities. Invalid references (deleted plans, rate codes, discounts) are flagged on every affected row.</p>
                                <form method="post" action="../process/updateImportBatchOptions.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                    <input type="hidden" name="stage_payments" value="<?= !empty($batchOptions['stage_payments']) ? '1' : '0' ?>">
                                    <div class="form-grid two-columns">
                                        <?php foreach (['A', 'B', 'C', 'D'] as $sourceCode): ?>
                                            <?php $currentTariff = (int)($batchOptions['rate_map'][$sourceCode] ?? 0) . '|' . (int)($batchOptions['pipe_map'][$sourceCode] ?? 0); ?>
                                            <div class="field">
                                                <label for="batch_tariff_<?= e($sourceCode) ?>">Source rate code <?= e($sourceCode) ?></label>
                                                <select id="batch_tariff_<?= e($sourceCode) ?>" name="tariff_map[<?= e($sourceCode) ?>]">
                                                    <option value="">Not mapped</option>
                                                    <?php foreach (($lookups['tariff_groups'] ?? []) as $row): ?>
                                                        <?php $value = (int)$row['rc_id'] . '|' . (int)$row['pipe_size_id']; ?>
                                                        <option value="<?= e($value) ?>" <?= $value === $currentTariff ? 'selected' : '' ?>><?= e($row['rc_code'] . ' — ' . $row['rc_name'] . ' / ' . $row['size_label']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($batch['import_type'] === 'billing_master'): ?>
                                            <?php foreach (di_recurring_columns() as $column): ?>
                                                <div class="field">
                                                    <label for="batch_recurring_<?= e(md5($column)) ?>"><?= e($column) ?> → recurring plan</label>
                                                    <select id="batch_recurring_<?= e(md5($column)) ?>" name="recurring_map[<?= e($column) ?>]">
                                                        <option value="0">Not configured — keep affected rows in Review</option>
                                                        <?php foreach ($plans as $plan): ?>
                                                            <?php $planForColumn = di_recurring_plan_readiness($plan, $column); ?>
                                                            <option value="<?= (int)$plan['plan_id'] ?>" <?= (int)($batchOptions['recurring_map'][$column] ?? 0) === (int)$plan['plan_id'] ? 'selected' : '' ?> <?= !$planForColumn['ready'] ? 'disabled' : '' ?>><?= e(($planForColumn['ready'] ? 'READY — ' : 'BLOCKED — ') . $plan['code'] . ' — ' . $plan['description']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="field">
                                                <label for="batch_address_mode">Address handling</label>
                                                <select id="batch_address_mode" name="address_mode" data-no-search="true">
                                                    <?php foreach (['source' => 'Import Account Address exactly', 'default' => 'Import with one default city and ZIP', 'skip' => 'Preserve in staging only'] as $modeValue => $modeLabel): ?>
                                                        <option value="<?= e($modeValue) ?>" <?= ($batchOptions['address_mode'] ?? 'source') === $modeValue ? 'selected' : '' ?>><?= e($modeLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label for="batch_default_city_id">Default city</label>
                                                <select id="batch_default_city_id" name="default_city_id">
                                                    <option value="0">Select a city</option>
                                                    <?php foreach (($lookups['cities'] ?? []) as $row): ?>
                                                        <option value="<?= (int)$row['pkey'] ?>" <?= (int)($batchOptions['default_city_id'] ?? 0) === (int)$row['pkey'] ? 'selected' : '' ?>><?= e($row['city'] . ' — ' . $row['province']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label for="batch_default_zip">Default ZIP</label>
                                                <input id="batch_default_zip" type="number" name="default_zip" min="0" max="32767" value="<?= (int)($batchOptions['default_zip'] ?? 0) ?: '' ?>">
                                            </div>
                                            <div class="field">
                                                <label for="batch_source_discount_id">Discount Flag mapping</label>
                                                <select id="batch_source_discount_id" name="source_discount_id">
                                                    <option value="0">Do not assign automatically</option>
                                                    <?php foreach (($lookups['discounts'] ?? []) as $row): ?>
                                                        <option value="<?= (int)$row['discount_id'] ?>" <?= (int)($batchOptions['source_discount_id'] ?? 0) === (int)$row['discount_id'] ? 'selected' : '' ?>><?= e($row['discount_type']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field">
                                                <label for="batch_senior_discount_id">SeniorMaster mapping</label>
                                                <select id="batch_senior_discount_id" name="senior_discount_id">
                                                    <option value="0">Do not assign automatically</option>
                                                    <?php foreach (($lookups['discounts'] ?? []) as $row): ?>
                                                        <option value="<?= (int)$row['discount_id'] ?>" <?= (int)($batchOptions['senior_discount_id'] ?? 0) === (int)$row['discount_id'] ? 'selected' : '' ?>><?= e($row['discount_type']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php endif; ?>
                                        <div class="field">
                                            <?= render_water_class_field($batchOptions['default_class_id'] ?? null, true, 'batch_default_class_id', 'default_class_id', '', 'Class') ?>
                                        </div>
                                        <div class="field">
                                            <label for="batch_save_profile_name">Also save as mapping profile (optional)</label>
                                            <input id="batch_save_profile_name" type="text" name="save_profile_name" maxlength="80" placeholder="Profile name">
                                        </div>
                                    </div>
                                    <div class="actions"><button type="submit" class="btn">Save mappings and revalidate unresolved rows</button></div>
                                </form>
                            </div>
                        </details>

                        <div class="actions">
                            <form method="post" action="../process/revalidateImportBatch.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                <button type="submit" class="btn is-secondary">Revalidate batch</button>
                            </form>
                            <?php
                            $modes = $batch['import_type'] === 'account_master' ? ['members'] : ['bills', 'recurring', 'payments'];
                            if ($batch['import_type'] === 'billing_master' && $openingPolicy) $modes[] = 'opening';
                            $modeReadyCounts = [
                                'members' => di_import_eligible_count($pdo, (int)$batchId, 'members'),
                                'bills' => di_import_eligible_count($pdo, (int)$batchId, 'bills'),
                                'recurring' => di_import_eligible_count($pdo, (int)$batchId, 'recurring'),
                                'payments' => di_import_eligible_count($pdo, (int)$batchId, 'payments'),
                                'opening' => $batch['import_type'] === 'billing_master' && $openingPolicy ? di_import_eligible_count($pdo, (int)$batchId, 'opening') : 0,
                            ];
                            $billingQueuedTotal = (int)($batch['ready_count'] ?? 0) + (int)($batch['recurring_ready_count'] ?? 0) + (int)($batch['payment_ready_count'] ?? 0);
                            ?>

                            <?php if ($batch['import_type'] === 'billing_master'): ?>
                                <div class="run-class-field">
                                    <?= render_water_class_field($batchOptions['default_class_id'] ?? null, true, 'runClassId', 'run_class_id', '', 'Class of the postings') ?>
                                </div>
                                <form
                                    method="post"
                                    action="../process/runImportChunk.php"
                                    class="auto-import-form"
                                    data-mode="billing_all"
                                    data-mode-label="Import entire Billing Master"
                                    data-ready-count="<?= (int)$billingQueuedTotal ?>"
                                    data-phase-bills="<?= (int)($batch['ready_count'] ?? 0) ?>"
                                    data-phase-recurring="<?= (int)($batch['recurring_ready_count'] ?? 0) ?>"
                                    data-phase-payments="<?= (int)($batch['payment_ready_count'] ?? 0) ?>"
                                    data-phase-opening="<?= $openingPolicy ? '1' : '0' ?>"
                                    data-can-import="<?= $billingImportReady && $billingQueuedTotal > 0 ? '1' : '0' ?>"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                    <input type="hidden" name="mode" value="bills">
                                    <?php if ($openingPolicy): ?>
                                        <label class="cutover-field">Cut-over date of the opening balances
                                            <input type="date" name="cutover_date" value="<?= e($cutoverDefault) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                        </label>
                                    <?php endif; ?>
                                    <button type="submit" class="btn" <?= !$billingImportReady || $billingQueuedTotal <= 0 ? 'disabled' : '' ?>>Import entire Billing Master</button>
                                    <?php if (!$billingImportReady): ?>
                                        <span class="help-tip" tabindex="0" data-tooltip="<?= e($billingImportRequirementMessage) ?> Fix the requirements and click Revalidate batch; the import button will unlock only after the configuration gate passes." aria-label="Why Billing Master import is blocked">?</span>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>

                            <?php [$openingHeld, $openingHeldAmount] = in_array('opening', $modes, true) ? di_opening_held($pdo, (int)$batchId) : [0, 0.0]; ?>
                            <?php foreach ($modes as $mode): ?>
                                <?php
                                $canImportMode = (int)($modeReadyCounts[$mode] ?? 0) > 0 || ($mode === 'opening' && $openingHeld > 0);
                                if ($batch['import_type'] === 'billing_master') $canImportMode = $canImportMode && $billingImportReady;
                                ?>
                                <form
                                    method="post"
                                    action="../process/runImportChunk.php"
                                    class="auto-import-form phase-import-form"
                                    data-mode="<?= e($mode) ?>"
                                    data-mode-label="<?= e($modeLabels[$mode]) ?>"
                                    data-ready-count="<?= (int)($modeReadyCounts[$mode] ?? 0) + ($mode === 'opening' ? $openingHeld : 0) ?>"
                                    data-can-import="<?= $canImportMode ? '1' : '0' ?>"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                                    <input type="hidden" name="mode" value="<?= e($mode) ?>">
                                    <?php if ($mode === 'opening'): ?>
                                        <label class="cutover-field">Cut-over date
                                            <input type="date" name="cutover_date" value="<?= e($cutoverDefault) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                        </label>
                                        <?php if ($openingHeld > 0): ?>
                                            <label class="cutover-check"><input type="checkbox" name="include_held" value="1"> Also post as unpaid the <?= number_format($openingHeld) ?> bill<?= $openingHeld === 1 ? '' : 's' ?> (<?= e(number_format($openingHeldAmount, 2)) ?>) held out because their payment is in Review. Most are marked paid in the former program: tick only for bills you confirmed were never paid.</label>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <button type="submit" class="btn is-secondary" <?= !$canImportMode ? 'disabled' : '' ?>><?= e($modeLabels[$mode]) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <a class="btn is-secondary" href="../process/downloadImportIssues.php?batch_id=<?= (int)$batchId ?>">Download issues CSV</a>
                        </div>
                    </div>
                    <div class="panel-body">
                        <form method="get" class="preview-filter" aria-label="Filter staged rows">
                            <input type="hidden" name="batch_id" value="<?= (int)$batchId ?>">
                            <div class="form-grid">
                                <div class="field"><label for="f_q">Search account / source key / any source value</label><input id="f_q" type="search" name="f_q" value="<?= e($previewFilters['q']) ?>" placeholder="Account No, name, receipt…"></div>
                                <div class="field"><label for="f_status">Status</label><select id="f_status" name="f_status" data-no-search="true"><option value="">Any</option><?php foreach (['ready', 'review', 'error', 'skipped', 'imported'] as $statusOption): ?><option value="<?= e($statusOption) ?>" <?= $previewFilters['status'] === $statusOption ? 'selected' : '' ?>><?= e(ucfirst($statusOption)) ?></option><?php endforeach; ?></select></div>
                                <div class="field"><label for="f_scope">Scope</label><select id="f_scope" name="f_scope" data-no-search="true"><option value="">Any</option><?php foreach (['member', 'bill', 'recurring', 'payment'] as $scopeOption): ?><option value="<?= e($scopeOption) ?>" <?= $previewFilters['scope'] === $scopeOption ? 'selected' : '' ?>><?= e(ucfirst($scopeOption)) ?></option><?php endforeach; ?></select></div>
                                <div class="field"><label for="f_severity">Severity</label><select id="f_severity" name="f_severity" data-no-search="true"><option value="">Any</option><?php foreach (['error', 'review', 'warning'] as $severityOption): ?><option value="<?= e($severityOption) ?>" <?= $previewFilters['severity'] === $severityOption ? 'selected' : '' ?>><?= e(ucfirst($severityOption)) ?></option><?php endforeach; ?></select></div>
                                <div class="field"><label for="f_code">Issue code</label><input id="f_code" type="text" name="f_code" value="<?= e($previewFilters['code']) ?>" placeholder="e.g. TOTAL_MISMATCH"></div>
                                <div class="field"><label for="f_limit">Rows to show</label><select id="f_limit" name="f_limit" data-no-search="true"><?php foreach ([60, 100, 200] as $limitOption): ?><option value="<?= $limitOption ?>" <?= $previewLimit === $limitOption ? 'selected' : '' ?>><?= $limitOption ?></option><?php endforeach; ?></select></div>
                            </div>
                            <div class="actions">
                                <button type="submit" class="btn is-secondary">Apply filter</button>
                                <a class="btn is-secondary" href="?batch_id=<?= (int)$batchId ?>">Clear</a>
                                <a class="btn is-secondary" href="../process/downloadImportIssues.php?batch_id=<?= (int)$batchId ?>&status=<?= e($previewFilters['status']) ?>&scope=<?= e($previewFilters['scope']) ?>&severity=<?= e($previewFilters['severity']) ?>&code=<?= e($previewFilters['code']) ?>&q=<?= e(rawurlencode($previewFilters['q'])) ?>">Download filtered issues CSV</a>
                                <span class="section-count"><?= number_format($previewTotal) ?> MATCHING ROW<?= $previewTotal === 1 ? '' : 'S' ?></span>
                            </div>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table preview-table">
                            <thead><tr><th>Row</th><th>Account</th><th>Source key</th><th>Primary</th><th>Recurring</th><th>Collection</th><th>Issues and comparison</th></tr></thead>
                            <tbody>
                            <?php if (!$preview): ?>
                                <tr><td colspan="7" class="empty-state">No staged rows match the current filter.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($preview as $row): ?>
                                <?php $normalizedRow = $row['normalized'] ?? []; $comparison = $normalizedRow['existing']['comparison'] ?? []; ?>
                                <tr>
                                    <td><?= (int)$row['row_number'] ?></td>
                                    <td><?= e($row['external_account_no']) ?></td>
                                    <td><?= e($row['source_key']) ?></td>
                                    <td><?= e($row['import_status']) ?></td>
                                    <td><?= e($row['recurring_status']) ?></td>
                                    <td><?= e($row['payment_status']) ?><?php if (!empty($normalizedRow['applied_total'])): ?><div class="muted">applied ₱<?= e($normalizedRow['applied_total']) ?><?= !empty($normalizedRow['partial']) ? ' (partial)' : '' ?></div><?php endif; ?></td>
                                    <td>
                                        <?php foreach ($row['issues'] as $issue): ?>
                                            <?php $issueCode = (string)($issue['code'] ?? ''); $guidance = $issueGuidance[$issueCode] ?? ''; ?>
                                            <div class="issue is-<?= e((string)($issue['severity'] ?? '')) ?>">
                                                <b><?= e($issueCode) ?></b> <span class="muted">[<?= e((string)($issue['scope'] ?? '')) ?> / <?= e((string)($issue['severity'] ?? '')) ?>]</span>: <?= e($issue['message'] ?? '') ?>
                                                <?php if ($guidance !== ''): ?>
                                                    <span class="help-tip" tabindex="0" data-tooltip="<?= e($guidance) ?>" aria-label="How to fix <?= e($issueCode) ?>">?</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($comparison): ?>
                                            <details class="comparison">
                                                <summary>Existing member #<?= (int)($normalizedRow['existing']['pkey'] ?? 0) ?> versus source (<?= count($normalizedRow['existing']['differences'] ?? []) ?> difference<?= count($normalizedRow['existing']['differences'] ?? []) === 1 ? '' : 's' ?>; existing data is preserved)</summary>
                                                <table class="comparison-table">
                                                    <thead><tr><th>Field</th><th>Source file</th><th>Existing record</th></tr></thead>
                                                    <tbody>
                                                    <?php foreach ($comparison as $entry): ?>
                                                        <tr class="<?= !empty($entry['differs']) ? 'is-different' : '' ?>"><td><?= e($entry['field']) ?></td><td><?= e($entry['source']) ?></td><td><?= e($entry['existing']) ?></td></tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </details>
                                        <?php endif; ?>
                                        <?php if (!empty($normalizedRow['billing_blockers'])): ?>
                                            <div class="muted">Billing prerequisites still missing for this member: <?= e(implode('; ', $normalizedRow['billing_blockers'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <div class="import-progress-overlay" id="importProgressOverlay" role="dialog" aria-modal="true" aria-labelledby="importProgressHeading" aria-hidden="true">
        <div class="import-progress-card">
            <h2 class="import-progress-heading" id="importProgressHeading">Importing records</h2>
            <div class="import-progress-percent" id="importProgressPercent">0%</div>
            <div class="import-progress-track" aria-hidden="true">
                <div class="import-progress-fill" id="importProgressFill"></div>
            </div>
            <p class="import-progress-status" id="importProgressStatus">Preparing import…</p>
            <div class="import-progress-stats">
                <div class="import-progress-stat"><strong id="importProgressProcessed">0</strong><span>Processed</span></div>
                <div class="import-progress-stat"><strong id="importProgressRemaining">0</strong><span>Remaining</span></div>
                <div class="import-progress-stat"><strong id="importProgressFailed">0</strong><span>Failed</span></div>
            </div>
            <div class="import-progress-error" id="importProgressError"></div>
            <button type="button" class="btn is-secondary import-progress-close" id="importProgressClose">Close</button>
        </div>
    </div>

    <script>
    /* A truncated option is still readable on hover: mirror the chosen option's
       full text into the select's own tooltip, and keep it in step on change. */
    (() => {
        const selects = Array.from(document.querySelectorAll('.field select'));
        const syncTitle = (select) => {
            const option = select.options[select.selectedIndex];
            select.title = option ? option.textContent.trim() : '';
        };
        selects.forEach((select) => {
            syncTitle(select);
            select.addEventListener('change', () => syncTitle(select));
        });
    })();
    </script>

    <script>
    (() => {
        const forms = Array.from(document.querySelectorAll('.auto-import-form'));
        if (!forms.length) return;

        const overlay = document.getElementById('importProgressOverlay');
        const heading = document.getElementById('importProgressHeading');
        const percent = document.getElementById('importProgressPercent');
        const fill = document.getElementById('importProgressFill');
        const status = document.getElementById('importProgressStatus');
        const processedEl = document.getElementById('importProgressProcessed');
        const remainingEl = document.getElementById('importProgressRemaining');
        const failedEl = document.getElementById('importProgressFailed');
        const errorEl = document.getElementById('importProgressError');
        const closeBtn = document.getElementById('importProgressClose');
        let running = false;

        const formatNumber = (value) => new Intl.NumberFormat().format(Math.max(0, Number(value) || 0));

        const setProgress = (attempted, total, remaining, failed, message) => {
            const safeTotal = Math.max(1, Number(total) || 1);
            const safeAttempted = Math.min(safeTotal, Math.max(0, Number(attempted) || 0));
            const pct = Math.min(100, Math.max(0, Math.round((safeAttempted / safeTotal) * 100)));
            percent.textContent = pct + '%';
            fill.style.width = pct + '%';
            processedEl.textContent = formatNumber(safeAttempted);
            remainingEl.textContent = formatNumber(remaining);
            failedEl.textContent = formatNumber(failed);
            status.textContent = message;
        };

        const showOverlay = (title, total) => {
            heading.textContent = title;
            overlay.classList.add('is-visible');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('import-is-running');
            errorEl.classList.remove('is-visible');
            errorEl.textContent = '';
            closeBtn.classList.remove('is-visible');
            setProgress(0, total, total, 0, 'Starting import…');
        };

        const unlockPage = () => {
            running = false;
            document.body.classList.remove('import-is-running');
        };

        closeBtn.addEventListener('click', () => {
            overlay.classList.remove('is-visible');
            overlay.setAttribute('aria-hidden', 'true');
            unlockPage();
        });

        const runClass = document.getElementById('runClassId');
        const requestChunk = async (form, mode) => {
            const data = new FormData(form);
            data.set('ajax', '1');
            data.set('mode', mode);
            // the bills, charges and collections the import posts carry this class
            if (runClass && mode !== 'members') data.set('class_id', runClass.value || '');

            const response = await fetch(form.action, {
                method: 'POST',
                body: data,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            let payload = null;
            try {
                payload = await response.json();
            } catch (jsonError) {
                throw new Error('The server returned an invalid import response. No further chunks were started.');
            }
            if (!response.ok || !payload || payload.ok !== true) {
                throw new Error((payload && payload.message) ? payload.message : 'The import request failed.');
            }
            return payload;
        };

        const disableImportButtons = () => {
            forms.forEach((item) => {
                const button = item.querySelector('button[type="submit"]');
                if (button) button.disabled = true;
            });
        };

        const restoreImportButtons = () => {
            forms.forEach((item) => {
                const button = item.querySelector('button[type="submit"]');
                if (!button) return;
                button.disabled = item.dataset.canImport !== '1';
            });
        };

        const runSingleMode = async (form, mode, total) => {
            let remaining = total;
            let failed = 0;
            let attempted = 0;
            let chunkNumber = 0;

            while (remaining > 0) {
                chunkNumber++;
                status.textContent = 'Processing chunk ' + formatNumber(chunkNumber) + '… Please keep this page open.';
                const payload = await requestChunk(form, mode);
                const chunkProcessed = Math.max(0, Number(payload.processed || 0));
                const chunkFailed = Math.max(0, Number(payload.failed || 0));
                const nextRemaining = Math.max(0, Number(payload.remaining || 0));
                attempted = Math.max(attempted + chunkProcessed + chunkFailed, total - nextRemaining);
                failed += chunkFailed;
                remaining = nextRemaining;

                setProgress(
                    attempted,
                    total,
                    remaining,
                    failed,
                    remaining > 0
                        ? 'Imported ' + formatNumber(attempted - failed) + ' of ' + formatNumber(total) + ' queued records. Continuing automatically…'
                        : 'Finishing import and refreshing the summary…'
                );

                if (chunkProcessed === 0 && chunkFailed === 0 && remaining > 0) {
                    throw new Error('The importer made no progress while eligible Ready records remain. The automatic loop was stopped to prevent repeated requests.');
                }
            }
            return { attempted, failed };
        };

        const runBillingMaster = async (form, total) => {
            const phases = [
                ['bills', 'Water bills', Math.max(0, Number(form.dataset.phaseBills || 0))],
                ['recurring', 'Recurring charges', Math.max(0, Number(form.dataset.phaseRecurring || 0))],
                ['payments', 'Collections', Math.max(0, Number(form.dataset.phasePayments || 0))],
                // the opening balances are counted by the server once the history is in, so the phase always runs one
                // request when the policy is "opening balances at the cut-over"
                ['opening', 'Opening balances', Math.max(0, Number(form.dataset.phaseOpening || 0))]
            ];
            let attempted = 0;
            let failed = 0;
            let globalChunk = 0;

            for (const [mode, phaseLabel, queuedHint] of phases) {
                if (queuedHint <= 0) continue;
                let phaseRemaining = null;
                do {
                    globalChunk++;
                    status.textContent = phaseLabel + ': processing chunk ' + formatNumber(globalChunk) + '… Please keep this page open.';
                    const payload = await requestChunk(form, mode);
                    const chunkProcessed = Math.max(0, Number(payload.processed || 0));
                    const chunkFailed = Math.max(0, Number(payload.failed || 0));
                    phaseRemaining = Math.max(0, Number(payload.remaining || 0));
                    attempted += chunkProcessed + chunkFailed;
                    failed += chunkFailed;
                    const estimatedRemaining = Math.max(0, total - attempted);

                    setProgress(
                        attempted,
                        total,
                        estimatedRemaining,
                        failed,
                        phaseRemaining > 0
                            ? phaseLabel + ': ' + formatNumber(phaseRemaining) + ' eligible record(s) remain. Continuing automatically…'
                            : phaseLabel + ' complete. Moving to the next Billing Master phase…'
                    );

                    if (chunkProcessed === 0 && chunkFailed === 0 && phaseRemaining > 0) {
                        throw new Error(phaseLabel + ' made no progress while eligible Ready records remain. The automatic loop was stopped safely.');
                    }
                } while (phaseRemaining > 0);
            }

            return { attempted, failed };
        };

        forms.forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (running || form.dataset.canImport !== '1') return;

                const total = Math.max(0, Number(form.dataset.readyCount || 0));
                if (total <= 0) return;

                const mode = form.dataset.mode || 'records';
                const label = form.dataset.modeLabel || 'Import all ready records';
                const isBillingAll = mode === 'billing_all';
                if (runClass && mode !== 'members' && !runClass.value) {
                    await AppDialog.alert('Choose the class of the postings first: it is required, and every bill, charge and collection the import posts carries it.', { title: 'Class required' });
                    runClass.focus();
                    return;
                }
                const confirmed = await AppDialog.confirm(
                    isBillingAll
                        ? 'The system runs Water Bills, then Recurring Charges, then Collections' + (form.dataset.phaseOpening === '1' ? ', then the Opening balances (posted to the ledger on the cut-over date)' : '') + ', in that order and in safe database chunks. Review and Error rows stay staged.'
                        : 'The ' + formatNumber(total) + ' Ready record(s) are processed in safe chunks. Existing records are kept, and Review and Error rows stay staged.',
                    { title: isBillingAll ? 'Import the entire Billing Master?' : 'Import all Ready records?', ok: 'Import' }
                );
                if (!confirmed || running) return;

                running = true;
                disableImportButtons();
                showOverlay(label, total);

                try {
                    const result = isBillingAll
                        ? await runBillingMaster(form, total)
                        : await runSingleMode(form, mode, total);
                    const failed = Math.max(0, Number(result.failed || 0));

                    setProgress(total, total, 0, failed, failed > 0
                        ? 'Import finished. ' + formatNumber(failed) + ' record(s) failed and were left for review.'
                        : (isBillingAll
                            ? 'Billing Master import complete. Water bills, configured recurring charges, and eligible collections finished successfully.'
                            : 'Import complete. All eligible Ready records were processed successfully.')
                    );

                    window.setTimeout(() => {
                        window.location.reload();
                    }, 900);
                } catch (error) {
                    errorEl.textContent = error && error.message ? error.message : 'The automatic import stopped because of an unexpected error.';
                    errorEl.classList.add('is-visible');
                    status.textContent = 'Import stopped. Records already committed in completed chunks were preserved safely.';
                    closeBtn.classList.add('is-visible');
                    unlockPage();
                    restoreImportButtons();
                }
            });
        });
    })();
    </script>

<script>
/* Resumable staging / revalidation loop (audit I05/I07). Each request commits
   one bounded chunk; the loop stops at a chunk boundary on any error and the
   Resume button continues from the server-side cursor. */
(() => {
    const panel = document.getElementById('stagingPanel');
    if (!panel) return;
    const batchId = panel.dataset.batchId;
    const action = panel.dataset.action;
    const total = Math.max(0, Number(panel.dataset.total || 0));
    let staged = Math.max(0, Number(panel.dataset.staged || 0));
    const fill = document.getElementById('stagingFill');
    const status = document.getElementById('stagingStatus');
    const count = document.getElementById('stagingCount');
    const resume = document.getElementById('stagingResume');
    const pause = document.getElementById('stagingPause');
    let running = false;
    let pauseRequested = false;
    const fmt = (n) => new Intl.NumberFormat().format(Math.max(0, Number(n) || 0));
    const paint = (message) => {
        const pct = total > 0 ? Math.min(100, Math.round((staged / total) * 100)) : 0;
        fill.style.width = pct + '%';
        count.textContent = fmt(staged) + ' / ' + fmt(total);
        status.textContent = message;
    };
    const step = async () => {
        const body = new FormData();
        body.set('batch_id', batchId);
        body.set('action', action);
        body.set('ajax', '1');
        const payload = await businessFetchJson('../process/stageImportChunk.php', { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' });
        if (!payload || payload.ok !== true) throw new Error((payload && payload.message) || 'The chunk request failed.');
        return payload;
    };
    const run = async () => {
        if (running) return;
        running = true; pauseRequested = false;
        resume.disabled = true; pause.disabled = false;
        try {
            while (true) {
                paint((action === 'stage' ? 'Validating rows ' : 'Revalidating rows ') + fmt(staged + 1) + ' onward… keep this page open or come back later to resume.');
                const payload = await step();
                if (action === 'stage') staged = Math.max(staged, Number(payload.staged_rows || 0));
                else staged = Math.min(total, staged + Number(payload.processed || 0));
                if (payload.done) {
                    paint('Finished. Reloading the review…');
                    window.setTimeout(() => { window.location.href = '?batch_id=' + encodeURIComponent(batchId); }, 600);
                    return;
                }
                if (pauseRequested) { paint('Paused after a committed chunk. Press Resume to continue.'); break; }
            }
        } catch (error) {
            paint((error && error.message ? error.message : 'The chunk stopped.') + ' Rows already committed are kept; press Resume to continue.');
        } finally {
            running = false; resume.disabled = false; pause.disabled = true;
        }
    };
    resume.addEventListener('click', run);
    pause.addEventListener('click', () => { pauseRequested = true; pause.disabled = true; });
    run();
})();

/* Saved mapping profiles pre-fill the upload form; the server still validates
   every reference and flags the ones that no longer exist. */
(() => {
    const select = document.getElementById('mapping_profile_id');
    if (!select) return;
    const form = select.closest('form');
    select.addEventListener('change', () => {
        const option = select.options[select.selectedIndex];
        if (!option || !option.dataset.options) return;
        let options;
        try { options = JSON.parse(option.dataset.options); } catch (e) { return; }
        const setValue = (name, value) => {
            const field = form.querySelector('[name="' + name + '"]');
            if (!field) return;
            field.value = value === undefined || value === null ? '' : String(value);
            field.dispatchEvent(new Event('change', { bubbles: true }));
        };
        ['A', 'B', 'C', 'D'].forEach(code => {
            const rate = options.rate_map && options.rate_map[code] ? Number(options.rate_map[code]) : 0;
            const pipe = options.pipe_map && options.pipe_map[code] ? Number(options.pipe_map[code]) : 0;
            setValue('tariff_map[' + code + ']', rate > 0 && pipe > 0 ? rate + '|' + pipe : '');
        });
        Object.keys(options.recurring_map || {}).forEach(column => setValue('recurring_map[' + column + ']', options.recurring_map[column]));
        ['address_mode', 'default_city_id', 'default_zip', 'source_discount_id', 'senior_discount_id', 'default_class_id'].forEach(key => {
            if (options[key] !== undefined) setValue(key, options[key]);
        });
        if (options.stage_payments !== undefined) setValue('stage_payments', options.stage_payments ? '1' : '0');
    });
    // the profile of the former program's files (made by the starter set-up) is preselected: fill the form with it
    if (select.value !== '0') select.dispatchEvent(new Event('change'));
})();
</script>
<!-- Option-label truncation is provided by common/business-ui-consistency.js (loaded by navbar.php). The former inline copy was removed: two observers on one page re-triggered each other (audit F10). -->
</body>
</html>