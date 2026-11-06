<?php
/* AUTH_GUARD_START */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
$__authGuardPdo = getDB();
requirePageAccess($__authGuardPdo, basename(__FILE__, '.php'));
unset($__authGuardPdo);
/* AUTH_GUARD_END */

/**
 * views/meter_reading_list_print.php
 *
 * Printable Meter Reading List
 *
 * Best placement:
 * - Reading Entry: print before encoding readings
 * - Bill Period Management: print per period
 *
 * This is a print-only operational form. It does not save readings.
 */

require_once __DIR__ . '/../database/config.php';

require_once '../database/config.php';
require_once '../common/memberAddressHelpers.php';

$pdo = getDB();
require_once __DIR__ . '/../common/institutionConfigurationHelpers.php';
$mrlIssuer = institution_issuer($pdo);

if (!function_exists('mrl_e')) {
    function mrl_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('mrl_date_input')) {
    function mrl_date_input($value, string $fallback): string
    {
        $value = trim((string)($value ?? ''));

        if ($value === '') {
            return $fallback;
        }

        $time = strtotime($value);

        return $time ? date('Y-m-d', $time) : $fallback;
    }
}

if (!function_exists('mrl_date_display')) {
    function mrl_date_display($value): string
    {
        if (!$value || $value === '0000-00-00') {
            return '—';
        }

        $time = strtotime((string)$value);

        return $time ? date('m/d/Y', $time) : (string)$value;
    }
}

if (!function_exists('mrl_long_date')) {
    function mrl_long_date($value): string
    {
        if (!$value) {
            return date('F j, Y');
        }

        $time = strtotime((string)$value);

        return $time ? date('F j, Y', $time) : date('F j, Y');
    }
}

if (!function_exists('mrl_add_days')) {
    function mrl_add_days(string $date, int $days): string
    {
        $d = new DateTime($date);
        $d->modify(($days >= 0 ? '+' : '') . $days . ' days');

        return $d->format('Y-m-d');
    }
}

if (!function_exists('mrl_table_exists')) {
    function mrl_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('mrl_column_exists')) {
    function mrl_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

$periods = [];
$zones = [];
$streets = [];
$error = '';
$selectedPeriod = null;
$rows = [];
$primaryAddressTypeId = 0;

try {
    $primaryAddressTypeId = member_primary_address_type_id($pdo);
    $periods = $pdo->query("
        SELECT period_id, bp_code, start_date, end_date, status
        FROM bill_periods
        ORDER BY start_date DESC, period_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $zones = $pdo->query("
        SELECT DISTINCT zone
        FROM water_accounts
        WHERE status = 'A'
          AND zone IS NOT NULL
          AND zone <> ''
        ORDER BY CAST(zone AS UNSIGNED), zone
    ")->fetchAll(PDO::FETCH_COLUMN);

    $streetStmt = $pdo->prepare("
        SELECT DISTINCT a.street
        FROM addresses a
        INNER JOIN memberaddress ma
            ON ma.address_key = a.pkey
           AND ma.address_type_key = ?
           AND ma.status = 'A'
           AND (ma.dtaddressto IS NULL OR ma.dtaddressto = '0001-01-01' OR ma.dtaddressto >= CURDATE())
        INNER JOIN water_accounts m
            ON m.pkey = ma.member_key
           AND m.status = 'A'
        WHERE a.street IS NOT NULL
          AND a.street <> ''
          AND a.status = 'A'
        ORDER BY a.street ASC
    ");
    $streetStmt->execute([$primaryAddressTypeId]);
    $streets = $streetStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$periodId = (int)($_GET['period_id'] ?? ($periods[0]['period_id'] ?? 0));
$zoneFilter = trim((string)($_GET['zone'] ?? 'all'));
$streetFilter = trim((string)($_GET['street'] ?? 'all'));
$paper = trim((string)($_GET['paper'] ?? 'short_landscape'));

if (!in_array($paper, ['short_portrait', 'short_landscape', 'long_portrait', 'long_landscape', 'a4_portrait', 'a4_landscape'], true)) {
    $paper = 'short_landscape';
}

try {
    if ($periodId > 0) {
        $stmt = $pdo->prepare("
            SELECT period_id, bp_code, start_date, end_date, status
            FROM bill_periods
            WHERE period_id = ?
            LIMIT 1
        ");
        $stmt->execute([$periodId]);
        $selectedPeriod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$selectedPeriod) {
            throw new Exception('Selected reading period was not found.');
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$defaultReadingDate = $selectedPeriod ? $selectedPeriod['start_date'] : date('Y-m-d');
$defaultDueDate = $selectedPeriod ? mrl_add_days($selectedPeriod['end_date'], 10) : mrl_add_days(date('Y-m-d'), 10);
$defaultDisconnectionDate = mrl_add_days($defaultDueDate, 1);

$readingDate = mrl_date_input($_GET['reading_date'] ?? null, $defaultReadingDate);
$dueDate = mrl_date_input($_GET['due_date'] ?? null, $defaultDueDate);
$disconnectionDate = mrl_date_input($_GET['disconnection_date'] ?? null, $defaultDisconnectionDate);

try {
    if ($selectedPeriod) {
        $currentStart = $selectedPeriod['start_date'];

        $where = ["m.status = 'A'"];
        $params = [
            ':current_start_1' => $currentStart,
            ':current_start_2' => $currentStart,
            ':period_id' => $periodId,
        ];

        if ($zoneFilter !== '' && $zoneFilter !== 'all') {
            $where[] = "m.zone = :zone";
            $params[':zone'] = $zoneFilter;
        }

        if ($streetFilter !== '' && $streetFilter !== 'all') {
            $where[] = "m.pkey IN (
                SELECT ma.member_key
                FROM memberaddress ma
                INNER JOIN addresses a
                    ON a.pkey = ma.address_key
                WHERE ma.address_type_key = :primary_address_type
                  AND ma.status = 'A'
                  AND (ma.dtaddressto IS NULL OR ma.dtaddressto = '0001-01-01' OR ma.dtaddressto >= CURDATE())
                  AND a.status = 'A'
                  AND a.street = :street
            )";
            $params[':street'] = $streetFilter;
            $params[':primary_address_type'] = $primaryAddressTypeId;
        }

        $sql = "
            SELECT
                m.pkey AS member_id,
                m.account_number,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS account_name,
                m.zone,
                m.meter_sequence,
                COALESCE(rc.rc_code, '') AS rc_code,
                COALESCE(rc.rc_name, '') AS rc_name,
                COALESCE(ps.size_code, '') AS pipe_size_code,
                COALESCE(ps.size_label, '') AS pipe_size_label,
                curr.reading_id,
                curr.prev_reading AS current_prev,
                curr.pres_reading AS current_present,
                curr.consumption AS current_usage,
                curr.is_billed,
                (
                    SELECT br_prev.pres_reading
                    FROM bill_readings br_prev
                    INNER JOIN bill_periods bp_prev
                        ON bp_prev.period_id = br_prev.period_id
                    WHERE br_prev.member_id = m.pkey
                      AND bp_prev.start_date < :current_start_1
                    ORDER BY bp_prev.start_date DESC, br_prev.reading_id DESC
                    LIMIT 1
                ) AS previous_reading,
                (
                    SELECT bp_prev.bp_code
                    FROM bill_readings br_prev
                    INNER JOIN bill_periods bp_prev
                        ON bp_prev.period_id = br_prev.period_id
                    WHERE br_prev.member_id = m.pkey
                      AND bp_prev.start_date < :current_start_2
                    ORDER BY bp_prev.start_date DESC, br_prev.reading_id DESC
                    LIMIT 1
                ) AS previous_period
            FROM water_accounts m
            LEFT JOIN rate_code rc
                ON rc.rc_id = m.rc_id
            LEFT JOIN water_pipe_sizes ps
                ON ps.pipe_size_id = m.pipe_size_id
            LEFT JOIN bill_readings curr
                ON curr.member_id = m.pkey
               AND curr.period_id = :period_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE WHEN NULLIF(TRIM(m.meter_sequence), '') IS NULL THEN 1 ELSE 0 END ASC,
                CASE WHEN m.meter_sequence REGEXP '^[0-9]+$' THEN 0 ELSE 1 END ASC,
                CASE WHEN m.meter_sequence REGEXP '^[0-9]+$' THEN CAST(m.meter_sequence AS UNSIGNED) END ASC,
                m.meter_sequence ASC,
                CAST(COALESCE(m.zone, 999999) AS UNSIGNED),
                COALESCE(m.zone, ''),
                m.lastname ASC,
                m.firstname ASC,
                m.pkey ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$totalMembers = count($rows);
$totalEncoded = count(array_filter($rows, fn($r) => !empty($r['reading_id'])));
$totalPending = max(0, $totalMembers - $totalEncoded);
$printDate = date('F j, Y');
$paperClass = $paper;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Meter Reading List <?= $selectedPeriod ? '— ' . mrl_e($selectedPeriod['bp_code']) : '' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../common/fonts.css">
<style>
    *, *::before, *::after { box-sizing: border-box; }

    :root {
        --paper: #fff;
        --bg: #f3f4f6;
        --ink: #111827;
        --muted: #6b7280;
        --line: #1f2937;
        --soft: #d1d5db;
        --accent: #111827;
        --blue: #1d4ed8;
        --success: #047857;
        --warning: #b45309;
    }

    body {
        margin: 0;
        background: var(--bg);
        color: var(--ink);
        font-family: 'Sora', sans-serif;
    }

    .screen-toolbar {
        max-width: 1280px;
        margin: 22px auto 14px;
        padding: 0 16px;
    }

    .toolbar-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 16px;
        box-shadow: 0 1px 3px rgba(15, 23, 42, .06);
    }

    .toolbar-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .title {
        font-size: 1.1rem;
        font-weight: 900;
        letter-spacing: -.03em;
    }

    .subtitle {
        color: var(--muted);
        font-size: .82rem;
        margin-top: 4px;
        line-height: 1.5;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(145px, 1fr));
        gap: 10px;
        align-items: end;
    }

    label {
        display: block;
        color: var(--muted);
        font-size: .68rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: .06em;
        margin-bottom: 5px;
    }

    input, select {
        width: 100%;
        height: 38px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        padding: 8px 10px;
        font-family: inherit;
        font-size: .82rem;
        outline: none;
        background: #fff;
    }

    input:focus, select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(17, 24, 39, .08);
    }

    .btn-row {
        display: flex;
        gap: 9px;
        flex-wrap: wrap;
    }

    .btn {
        min-height: 38px;
        border: 0;
        border-radius: 9px;
        padding: 9px 13px;
        background: var(--accent);
        color: #fff;
        font-family: inherit;
        font-size: .82rem;
        font-weight: 900;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }

    .btn.light {
        background: #fff;
        color: var(--accent);
        border: 1px solid #e5e7eb;
    }

    .stats {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 12px;
    }

    .chip {
        border: 1px solid #e5e7eb;
        background: #f9fafb;
        color: #374151;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: .72rem;
        font-weight: 800;
    }

    .paper-shell {
        margin: 0 auto 28px;
        background: var(--paper);
        box-shadow: 0 18px 50px rgba(15, 23, 42, .18);
        padding: .32in;
    }

    .paper-shell.short_landscape {
        width: 11in;
        min-height: 8.5in;
    }

    .paper-shell.short_portrait {
        width: 8.5in;
        min-height: 11in;
    }

    .paper-shell.long_landscape {
        width: 13in;
        min-height: 8.5in;
    }

    .paper-shell.long_portrait {
        width: 8.5in;
        min-height: 13in;
    }

    .paper-shell.a4_landscape {
        width: 11.69in;
        min-height: 8.27in;
    }

    .paper-shell.a4_portrait {
        width: 8.27in;
        min-height: 11.69in;
    }

    .company-header {
        text-align: center;
        line-height: 1.35;
        margin-bottom: 8px;
    }

    .company-name {
        font-size: 17px;
        font-weight: 900;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .company-address {
        font-size: 12px;
        color: #374151;
        margin-top: 1px;
    }

    .report-title {
        font-size: 18px;
        font-weight: 900;
        text-transform: uppercase;
        margin-top: 4px;
    }

    .date-line {
        display: grid;
        grid-template-columns: repeat(3, auto);
        justify-content: center;
        gap: 18px;
        font-size: 12px;
        margin: 7px 0 8px;
        white-space: nowrap;
    }

    .date-line strong {
        font-weight: 900;
    }

    .meta-line {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        color: #374151;
        font-size: 11px;
        margin-bottom: 6px;
        border-top: 1px solid #111827;
        padding-top: 4px;
    }

    .mrl-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 12px;
    }

    .mrl-table th,
    .mrl-table td {
        border: 1px solid #111827;
        padding: 4px 4px;
        vertical-align: middle;
        overflow-wrap: anywhere;
    }

    .mrl-table th {
        font-size: 11px;
        text-align: center;
        font-weight: 900;
        text-transform: none;
        line-height: 1.15;
        background: #fff;
    }

    .mrl-table td {
        height: 31px;
    }

    .col-zone { width: 6%; text-align: center; }
    .col-seq { width: 7%; text-align: center; }
    .col-acct { width: 10%; text-align: center; overflow-wrap:anywhere; }
    .col-name { width: 23%; white-space:normal; overflow-wrap:anywhere; }
    .col-rate { width: 7%; text-align: center; }
    .col-present { width: 10%; text-align: center; }
    .col-prev { width: 10%; text-align: center; }
    .col-usage { width: 8%; text-align: center; }
    .col-sign { width: 19%; }

    .mono {
        font-family: 'JetBrains Mono', monospace;
    }

    .muted {
        color: #6b7280;
    }

    .manual-cell {
        display: block;
        min-height: 16px;
        color: transparent;
        font-size: 0;
        text-align: center;
    }

    .signature-space {
        min-height: 18px;
    }

    .footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 10.5px;
        color: #374151;
        margin-top: 7px;
        border-top: 1px solid #111827;
        padding-top: 4px;
    }

    .error-box {
        max-width: 920px;
        margin: 30px auto;
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
        border-radius: 12px;
        padding: 16px;
        font-weight: 700;
    }

    @media (max-width: 1200px) {
        .main-content { overflow-x:auto; }
        .paper-shell { transform:none; margin-bottom:28px; }

        .filter-grid {
            grid-template-columns: repeat(3, minmax(145px, 1fr));
        }
    }

    @media (max-width: 760px) {
        .filter-grid {
            grid-template-columns: 1fr;
        }

        .btn-row,
        .btn {
            width: 100%;
        }

        .main-content { overflow-x:auto; }
        .paper-shell { transform:none; margin-bottom:28px; }
    }

    @page {
        size: Letter landscape;
        margin: 0.25in;
    }

    <?php if ($paper === 'short_portrait'): ?>
    @page { size: Letter portrait; margin: 0.25in; }
    <?php elseif ($paper === 'long_landscape'): ?>
    @page { size: Legal landscape; margin: 0.25in; }
    <?php elseif ($paper === 'long_portrait'): ?>
    @page { size: Legal portrait; margin: 0.25in; }
    <?php elseif ($paper === 'a4_landscape'): ?>
    @page { size: A4 landscape; margin: 7mm; }
    <?php elseif ($paper === 'a4_portrait'): ?>
    @page { size: A4 portrait; margin: 7mm; }
    <?php else: ?>
    @page { size: Letter landscape; margin: 0.25in; }
    <?php endif; ?>

    @media print {
        body {
            background: #fff !important;
        }

        .screen-toolbar,
        .sidebar,
        .topbar,
        .sidebar-overlay {
            display: none !important;
        }

        .main-content {
            margin: 0 !important;
            padding: 0 !important;
        }

        .paper-shell {
            width: 100% !important;
            min-height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            transform: none !important;
            box-shadow: none !important;
        }

        thead {
            display: table-header-group;
        }

        tfoot {
            display: table-footer-group;
        }

        tr {
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .mrl-table th,
        .mrl-table td {
            padding: 3px 4px;
        }

        .footer { position: static; background:#fff; break-inside:avoid; }
    }

    /* Readable field-list preview and printer-safe sizing. */
    .paper-shell { font-family: Arial, Helvetica, sans-serif; overflow: visible; }
    .mrl-table td { line-height:1.25; white-space:normal; }
    @media print {
        .company-name { font-size:14pt; }
        .report-title { font-size:15pt; }
        .company-address,.date-line { font-size:9.5pt; }
        .meta-line,.footer { font-size:9pt; }
        .mrl-table { font-size:9.5pt; table-layout:fixed; }
        .mrl-table th { font-size:9pt; line-height:1.2; }
        .mrl-table th,.mrl-table td { padding:4px 5px; overflow:visible; }
        .mrl-table td { height:27px; }
    }
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
    <?php if ($error): ?>
        <div class="error-box"><?= mrl_e($error) ?></div>
    <?php endif; ?>

    <div class="screen-toolbar">
        <div class="toolbar-card">
            <div class="toolbar-top">
                <div>
                    <div class="title">Meter Reading List</div>
                    <div class="subtitle">
                        Print this before field reading. Accounts are grouped and sorted by the member's optional Sequence in ascending order. Present reading, usage, amount, and signature can be filled manually by the reader.
                    </div>
                </div>
                <div class="btn-row">
                    <a href="reading_entry.php" class="btn light">Back to Reading Entry</a>
                    <button type="button" class="btn" onclick="window.print()">Print</button>
                </div>
            </div>

            <form method="GET">
                <div class="filter-grid">
                    <div>
                        <label for="mrl_period_id">Reading Period</label>
                        <select id="mrl_period_id" name="period_id" required>
                            <?php foreach ($periods as $p): ?>
                                <option value="<?= mrl_e($p['period_id']) ?>" <?= (int)$p['period_id'] === $periodId ? 'selected' : '' ?>>
                                    <?= mrl_e($p['bp_code']) ?> (<?= mrl_e(mrl_date_display($p['start_date'])) ?> - <?= mrl_e(mrl_date_display($p['end_date'])) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="mrl_zone">Zone</label>
                        <select id="mrl_zone" name="zone">
                            <option value="all">All Zones</option>
                            <?php foreach ($zones as $z): ?>
                                <option value="<?= mrl_e($z) ?>" <?= (string)$zoneFilter === (string)$z ? 'selected' : '' ?>>
                                    Zone <?= mrl_e($z) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="mrl_street">Street</label>
                        <select id="mrl_street" name="street">
                            <option value="all">All Streets</option>
                            <?php foreach ($streets as $street): ?>
                                <option value="<?= mrl_e($street) ?>" <?= (string)$streetFilter === (string)$street ? 'selected' : '' ?>>
                                    <?= mrl_e($street) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="mrl_paper">Paper</label>
                        <select id="mrl_paper" name="paper">
                            <option value="short_landscape" <?= $paper === 'short_landscape' ? 'selected' : '' ?>>Short Bondpaper Landscape</option>
                            <option value="short_portrait" <?= $paper === 'short_portrait' ? 'selected' : '' ?>>Short Bondpaper Portrait</option>
                            <option value="long_landscape" <?= $paper === 'long_landscape' ? 'selected' : '' ?>>Long Bondpaper Landscape</option>
                            <option value="long_portrait" <?= $paper === 'long_portrait' ? 'selected' : '' ?>>Long Bondpaper Portrait</option>
                            <option value="a4_landscape" <?= $paper === 'a4_landscape' ? 'selected' : '' ?>>A4 Landscape</option>
                            <option value="a4_portrait" <?= $paper === 'a4_portrait' ? 'selected' : '' ?>>A4 Portrait</option>
                        </select>
                    </div>

                    <div>
                        <label for="mrl_reading_date">Reading Date</label>
                        <input id="mrl_reading_date" type="date" name="reading_date" value="<?= mrl_e($readingDate) ?>">
                    </div>

                    <div>
                        <label for="mrl_due_date">Due Date</label>
                        <input id="mrl_due_date" type="date" name="due_date" value="<?= mrl_e($dueDate) ?>">
                    </div>

                    <div>
                        <label for="mrl_disconnection_date">Disconnection Date</label>
                        <input id="mrl_disconnection_date" type="date" name="disconnection_date" value="<?= mrl_e($disconnectionDate) ?>">
                    </div>

                    <div class="btn-row">
                        <button class="btn" type="submit">Generate List</button>
                        <button class="btn light" type="button" onclick="window.print()">Print</button>
                    </div>
                </div>
            </form>

            <div class="stats">
                <span class="chip">Period: <strong><?= mrl_e($selectedPeriod['bp_code'] ?? '—') ?></strong></span>
                <span class="chip">Members: <strong><?= mrl_e($totalMembers) ?></strong></span>
                <span class="chip">Encoded: <strong><?= mrl_e($totalEncoded) ?></strong></span>
                <span class="chip">Pending: <strong><?= mrl_e($totalPending) ?></strong></span>
                <?php if ($zoneFilter !== 'all' && $zoneFilter !== ''): ?>
                    <span class="chip">Zone <?= mrl_e($zoneFilter) ?></span>
                <?php endif; ?>
                <?php if ($streetFilter !== 'all' && $streetFilter !== ''): ?>
                    <span class="chip">Street: <?= mrl_e($streetFilter) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <main class="paper-shell <?= mrl_e($paperClass) ?>">
        <div class="company-header">
            <div class="company-name"><?= mrl_e(mb_strtoupper($mrlIssuer['name'], 'UTF-8')) ?></div>
            <div class="company-address"><?= mrl_e($mrlIssuer['address']) ?></div>
            <div class="report-title">METER READING LIST</div>
        </div>

        <div class="date-line">
            <span><strong>Reading :</strong> <?= mrl_e(mrl_date_display($readingDate)) ?></span>
            <span><strong>Due :</strong> <?= mrl_e(mrl_date_display($dueDate)) ?></span>
            <span><strong>Disconnection :</strong> <?= mrl_e(mrl_date_display($disconnectionDate)) ?></span>
        </div>

        <div class="meta-line">
            <span>Period: <strong><?= mrl_e($selectedPeriod['bp_code'] ?? '—') ?></strong></span>
            <span>Filter: <strong><?= $zoneFilter !== 'all' ? 'Zone ' . mrl_e($zoneFilter) : 'All Zones' ?></strong><?= $streetFilter !== 'all' ? ' / Street: ' . mrl_e($streetFilter) : '' ?></span>
            <span>Sort: <strong>Sequence Ascending</strong></span>
            <span>Total Accounts: <strong><?= mrl_e($totalMembers) ?></strong></span>
        </div>

        <table class="mrl-table">
            <thead>
                <tr>
                    <th class="col-zone">Zone</th>
                    <th class="col-seq">Seq</th>
                    <th class="col-acct">Accnt.</th>
                    <th class="col-name">Account Name</th>
                    <th class="col-rate">Rate / Pipe</th>
                    <th class="col-present">Present</th>
                    <th class="col-prev">Previous</th>
                    <th class="col-usage">Usage</th>
                    <th class="col-sign">Amount / Signature</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:22px;color:#6b7280;font-weight:700;">
                            No active members found for this filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $prevReading = $row['current_prev'] !== null && $row['current_prev'] !== ''
                                ? (int)round((float)$row['current_prev'])
                                : (int)round((float)($row['previous_reading'] ?? 0));

                            $presentReading = $row['current_present'] !== null && $row['current_present'] !== ''
                                ? (int)round((float)$row['current_present'])
                                : '';

                            $usage = $row['current_usage'] !== null && $row['current_usage'] !== ''
                                ? (int)round((float)$row['current_usage'])
                                : '';

                            $rate = trim((string)($row['rc_code'] ?? ''));
                            if ($rate === '') {
                                $rate = trim((string)($row['rc_name'] ?? ''));
                            }
                            $pipeSize = trim((string)($row['pipe_size_code'] ?? ''));
                            if ($pipeSize === '' || strtoupper($pipeSize) === 'UNSPECIFIED') {
                                $pipeSize = trim((string)($row['pipe_size_label'] ?? ''));
                            }
                            if ($pipeSize !== '') {
                                $rate = ($rate !== '' ? $rate . ' / ' : '') . $pipeSize;
                            }

                            $acct = trim((string)($row['account_number'] ?? ''));
                            if ($acct === '') {
                                $acct = (string)$row['member_id'];
                            }
                        ?>
                        <tr>
                            <td class="col-zone mono"><?= mrl_e($row['zone'] ?: '—') ?></td>
                            <td class="col-seq mono"><?= mrl_e(trim((string)($row['meter_sequence'] ?? '')) !== '' ? $row['meter_sequence'] : '—') ?></td>
                            <td class="col-acct mono"><?= mrl_e($acct) ?></td>
                            <td class="col-name"><?= mrl_e($row['account_name']) ?></td>
                            <td class="col-rate mono"><?= mrl_e($rate ?: '—') ?></td>
                            <td class="col-present mono"><?= $presentReading !== '' ? mrl_e($presentReading) : '<span class="manual-cell">&nbsp;</span>' ?></td>
                            <td class="col-prev mono"><?= mrl_e($prevReading) ?></td>
                            <td class="col-usage mono"><?= $usage !== '' ? mrl_e($usage) : '<span class="manual-cell">&nbsp;</span>' ?></td>
                            <td class="col-sign"><div class="signature-space">&nbsp;</div></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">
            <span>Print Date: <?= mrl_e($printDate) ?></span>
            <span>Prepared for field meter reading</span>
        </div>
    </main>
</div>
</body>
</html>
