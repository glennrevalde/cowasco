<?php
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';

$pdo = getDB();
requirePageAccess($pdo, 'water_rates');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$rows = [];
$error = '';

try {
    $rows = $pdo->query(
        "SELECT
            rc.rc_id,
            rc.rc_code,
            rc.rc_name AS rc_description,
            ps.pipe_size_id,
            ps.size_code AS pipe_size_code,
            ps.size_label AS pipe_size_label,
            ps.diameter_inches,
            w.wmm_id,
            w.from_cb,
            w.to_cb,
            w.amount,
            w.bill_type
         FROM rate_code rc
         LEFT JOIN water_meter_members w ON w.rc_id = rc.rc_id
         LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = w.pipe_size_id
         ORDER BY rc.rc_code,
                  CASE WHEN ps.diameter_inches IS NULL THEN 1 ELSE 0 END,
                  ps.diameter_inches, ps.size_label, w.from_cb, w.wmm_id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Water Rate Schedule - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../common/fonts.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8f9fb; font-family: Sora, sans-serif; color: #111827; }
        .page { max-width: 1200px; margin: auto; padding: 30px; }
        .head { display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; margin-bottom: 20px; flex-wrap: wrap; }
        .title { margin: 0; font-size: 1.5rem; letter-spacing: -.03em; }
        .subtitle { margin: 5px 0 0; color: #6b7280; font-size: .84rem; line-height: 1.55; }
        .actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 9px 14px; border: 1px solid #111827; border-radius: 6px; background: #111827; color: #fff; font: 700 .78rem Sora, sans-serif; text-decoration: none; cursor: pointer; }
        .btn-light { background: #fff; color: #111827; border-color: #d1d5db; }
        .report-meta { display: none; margin-bottom: 14px; color: #4b5563; font-size: 9pt; }
        .table { overflow: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 13px; border-bottom: 1px solid #eef0f2; text-align: left; font-size: .78rem; }
        th { background: #f8fafc; color: #6b7280; text-transform: uppercase; font-size: .65rem; letter-spacing: .04em; }
        tbody tr:last-child td { border-bottom: 0; }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .alert { margin-bottom: 16px; padding: 12px; border: 1px solid #fecaca; border-radius: 6px; background: #fef2f2; color: #b91c1c; }
        .empty { padding: 30px; color: #6b7280; text-align: center; }
        .status-valid { color: #047857; font-weight: 700; }
        .status-invalid { color: #b91c1c; font-weight: 700; }

        @media (max-width: 700px) {
            .page { padding: 20px 14px; }
            .actions { width: 100%; }
            .btn { flex: 1; }
        }

        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            body { background: #fff; font-family: Arial, Helvetica, sans-serif; }
            .sidebar, .topbar, .no-print { display: none !important; }
            .main-content { margin: 0 !important; }
            .page { max-width: none; padding: 0; }
            .head { margin-bottom: 12px; }
            .title { font-size: 18pt; }
            .subtitle { font-size: 9pt; }
            .report-meta { display: block; }
            .table { overflow: visible; border: 0; border-radius: 0; }
            table { table-layout: auto; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; page-break-inside: avoid; }
            th, td { padding: 6px 7px; font-size: 9pt; }
            th { font-size: 8.5pt; }
        }
    </style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content">
    <main class="page">
        <div class="head">
            <div>
                <h1 class="title">Water Rate Schedule</h1>
                <p class="subtitle">Official read-only tariff report grouped by Rate Code and Pipe Size.</p>
            </div>
            <div class="actions no-print">
                <a class="btn btn-light" href="reports_management.php">Reports Management</a>
                <a class="btn btn-light" href="water_meter.php">Manage Tiers</a>
                <button class="btn" type="button" onclick="window.print()">Print Water Tariff</button>
            </div>
        </div>

        <div class="report-meta">
            Generated on <?= e(date('F j, Y h:i A')) ?>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="table">
            <table>
                <thead>
                    <tr>
                        <th>Rate Code</th>
                        <th>Description</th>
                        <th>Pipe Size</th>
                        <th>Tier ID</th>
                        <th>From m³</th>
                        <th>To m³</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Continuity</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $lastConfigurationKey = null;
                $expectedFrom = null;
                $openEndedReached = false;
                ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $hasTier = $row['wmm_id'] !== null;
                    $valid = true;
                    $continuityNote = '';

                    if ($hasTier) {
                        $configurationKey = (int)$row['rc_id'] . ':' . (int)$row['pipe_size_id'];
                        if ($lastConfigurationKey !== $configurationKey) {
                            $expectedFrom = (int)$row['from_cb'];
                            $openEndedReached = false;
                            if (!in_array($expectedFrom, [0, 1], true)) {
                                $valid = false;
                                $continuityNote = 'First tier should start at 0 or 1';
                            }
                        } elseif ($openEndedReached) {
                            $valid = false;
                            $continuityNote = 'Tier exists after an open-ended tier';
                        } elseif ($expectedFrom !== null && (int)$row['from_cb'] !== $expectedFrom) {
                            $valid = false;
                            $continuityNote = 'Gap or overlap before this tier';
                        }

                        $openEndedReached = $row['to_cb'] === null;
                        $expectedFrom = $openEndedReached ? null : (int)$row['to_cb'] + 1;
                        $lastConfigurationKey = $configurationKey;
                    }
                    ?>
                    <tr>
                        <td class="mono"><?= e($row['rc_code']) ?></td>
                        <td><?= e($row['rc_description']) ?></td>
                        <td><?= $hasTier ? e(($row['pipe_size_label'] ?: $row['pipe_size_code']) . ($row['pipe_size_code'] ? ' (' . $row['pipe_size_code'] . ')' : '')) : '—' ?></td>
                        <td><?= e($hasTier ? $row['wmm_id'] : '—') ?></td>
                        <td><?= e($hasTier ? $row['from_cb'] : '—') ?></td>
                        <td><?= e($hasTier ? ($row['to_cb'] ?? 'Open-ended') : '—') ?></td>
                        <td><?= e($hasTier ? $row['bill_type'] : '—') ?></td>
                        <td class="mono"><?= $hasTier ? '₱' . number_format((float)$row['amount'], 4) : '—' ?></td>
                        <td class="<?= $hasTier && $valid ? 'status-valid' : 'status-invalid' ?>">
                            <?= $hasTier ? ($valid ? 'Continuous' : e($continuityNote)) : 'No configured tiers' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$rows): ?>
                    <tr><td class="empty" colspan="9">No rate codes or tariff tiers were found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
