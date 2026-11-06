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

require_once '../database/config.php';
require_once '../common/displayHelpers.php';
$pdo = getDB();


if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('rm_money')) {
    function rm_money($value): string { return '₱ ' . number_format((float)($value ?? 0), 2); }
}

if (!function_exists('rm_date')) {
    function rm_date($value): string {
        if (empty($value)) return '—';
        $time = strtotime((string)$value);
        return $time ? date('Y-m-d h:i A', $time) : '—';
    }
}

if (!function_exists('rm_sql_date')) {
    function rm_sql_date($value, string $fallback): string {
        $value = trim((string)($value ?? ''));
        if ($value === '') return $fallback;
        $time = strtotime($value);
        return $time ? date('Y-m-d', $time) : $fallback;
    }
}

// ==========================================
// DYNAMIC REPORT GENERATOR (AJAX ENDPOINT)
// ==========================================
if (isset($_GET['ajax_report'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/json; charset=utf-8');

    $type = trim((string)($_GET['report_type'] ?? ''));
    $dateFrom = rm_sql_date($_GET['date_from'] ?? null, date('Y-m-01'));
    $dateTo = rm_sql_date($_GET['date_to'] ?? null, date('Y-m-t'));
    $periodId = $_GET['period_id'] ?? 'all';
    $zoneId = $_GET['zone_id'] ?? 'all';

    $statsHtml = '';
    $theadHtml = '';
    $tbodyHtml = '';

    try {
        // 1. MEMBER MASTERLIST
        if ($type === 'members') {
            $zoneQ = ($zoneId !== 'all' && $zoneId !== '') ? "AND m.zone = " . (int)$zoneId : "";

            $stmt = $pdo->query("
                SELECT
                    m.pkey,
                    m.account_number,
                    CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                    m.zone,
                    m.status,
                    r.rc_name,
                    ps.size_label AS pipe_size_label,
                    ps.size_code AS pipe_size_code,
                    TRIM(CONCAT_WS(' ',
                        NULLIF(a.housebldg, ''),
                        NULLIF(a.street, ''),
                        NULLIF(a.other_address_detail, ''),
                        NULLIF(c.city, ''),
                        NULLIF(c.province, '')
                    )) AS address
                FROM water_accounts m
                LEFT JOIN rate_code r ON m.rc_id = r.rc_id
                LEFT JOIN water_pipe_sizes ps ON m.pipe_size_id = ps.pipe_size_id
                LEFT JOIN memberaddress ma ON m.pkey = ma.member_key AND ma.status = 'A'
                LEFT JOIN addresses a ON ma.address_key = a.pkey AND a.status = 'A'
                LEFT JOIN cities c ON c.pkey = a.city_id AND c.status = 'A'
                WHERE 1=1 {$zoneQ}
                GROUP BY m.pkey
                ORDER BY m.lastname ASC, m.firstname ASC
            ");
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total = count($members);
            $active = count(array_filter($members, fn($m) => ($m['status'] ?? '') === 'A'));
            $inactive = $total - $active;

            $statsHtml = "
                <div class='stat-card'><div class='stat-title'>Total Members</div><div class='stat-value'>{$total}</div></div>
                <div class='stat-card'><div class='stat-title'>Active Members</div><div class='stat-value' style='color:var(--success)'>{$active}</div></div>
                <div class='stat-card'><div class='stat-title'>Inactive / Disconnected</div><div class='stat-value' style='color:var(--danger)'>{$inactive}</div></div>
            ";

            $theadHtml = "<tr><th>Account No.</th><th>Member Name</th><th>Address</th><th>Zone</th><th>Rate Code</th><th>Pipe Size</th><th>Status</th></tr>";

            if (empty($members)) {
                $tbodyHtml = "<tr><td colspan='7' style='text-align:center; padding:30px; color:var(--text-muted);'>No members found matching your filters.</td></tr>";
            } else {
                foreach ($members as $m) {
                    $statusBadge = ($m['status'] ?? '') === 'A'
                        ? "<span class='badge badge-success'>Active</span>"
                        : "<span class='badge badge-danger'>Inactive</span>";

                    $zone = !empty($m['zone']) ? "Zone " . e($m['zone']) : "—";
                    $address = trim((string)($m['address'] ?? ''));
                    $address = $address !== '' ? e($address) : '—';
                    $rc = e($m['rc_name'] ?? 'Regular');
                    $pipeSize = trim((string)($m['pipe_size_label'] ?? $m['pipe_size_code'] ?? ''));
                    $pipeSize = $pipeSize !== '' ? e($pipeSize) : '—';
                    $acct = e($m['account_number'] ?: $m['pkey']);

                    $tbodyHtml .= "<tr>
                        <td class='mono' style='color:var(--text-secondary);'>{$acct}</td>
                        <td><strong style='font-weight:600;'>" . e($m['full_name']) . "</strong></td>
                        <td style='color:var(--text-secondary); font-size:.75rem;'>{$address}</td>
                        <td class='mono'>{$zone}</td>
                        <td><span class='badge' style='background:#f3f4f6; color:#4b5563; border:1px solid var(--border)'>{$rc}</span></td>
                        <td class='mono'>{$pipeSize}</td>
                        <td>{$statusBadge}</td>
                    </tr>";
                }
            }
        }

        // 2. DELINQUENT / UNPAID ACCOUNTS
        elseif ($type === 'delinquent') {
            $zoneQ = ($zoneId !== 'all' && $zoneId !== '') ? "AND m.zone = " . (int)$zoneId : "";
            $periodQ = ($periodId !== 'all' && $periodId !== '') ? "AND br.period_id = " . (int)$periodId : "";

            $sql = "
                SELECT * FROM (
                    SELECT
                        br.reading_id AS ref,
                        m.pkey,
                        m.account_number,
                        CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                        m.zone,
                        br.amount_due AS amount,
                        br.due_date,
                        DATEDIFF(CURDATE(), br.due_date) AS days_late,
                        'Water Bill' AS source
                    FROM bill_readings br
                    JOIN water_accounts m ON m.pkey = br.member_id
                    WHERE br.is_billed = 1
                      AND br.is_paid = 0
                      AND br.due_date < CURDATE()
                      {$zoneQ}
                      {$periodQ}

                    UNION ALL

                    SELECT
                        b.bill_id AS ref,
                        m.pkey,
                        m.account_number,
                        CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                        m.zone,
                        (b.total_amount - b.amount_paid) AS amount,
                        b.due_date,
                        DATEDIFF(CURDATE(), b.due_date) AS days_late,
                        'One-Time Bill' AS source
                    FROM one_time_bills b
                    JOIN water_accounts m ON m.pkey = b.member_id
                    WHERE b.status = 'unpaid'
                      AND b.due_date < CURDATE()
                      AND (b.total_amount - b.amount_paid) > 0
                      {$zoneQ}

                    UNION ALL

                    SELECT
                        s.schedule_id AS ref,
                        m.pkey,
                        m.account_number,
                        CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                        m.zone,
                        s.amount AS amount,
                        s.due_date,
                        DATEDIFF(CURDATE(), s.due_date) AS days_late,
                        'Installment' AS source
                    FROM installment_schedules s
                    JOIN installment_bills ib ON ib.installment_id = s.installment_id
                    JOIN water_accounts m ON m.pkey = ib.member_id
                    WHERE s.status = 'pending'
                      AND s.due_date < CURDATE()
                      {$zoneQ}

                    UNION ALL

                    SELECT
                        rb.bill_id AS ref,
                        m.pkey,
                        m.account_number,
                        CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                        m.zone,
                        rb.balance AS amount,
                        rb.due_date,
                        DATEDIFF(CURDATE(), rb.due_date) AS days_late,
                        'Recurring Bill' AS source
                    FROM recurring_bills rb
                    JOIN water_accounts m ON m.pkey = rb.member_id
                    WHERE rb.status IN ('unpaid','overdue')
                      AND rb.balance > 0
                      AND rb.due_date < CURDATE()
                      {$zoneQ}
                ) x
                ORDER BY due_date ASC
            ";

            $unpaid = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            $totalAccounts = count($unpaid);
            $totalAmount = array_sum(array_column($unpaid, 'amount'));

            $statsHtml = "
                <div class='stat-card'><div class='stat-title'>Total Delinquent Bills</div><div class='stat-value' style='color:var(--danger)'>{$totalAccounts}</div></div>
                <div class='stat-card'><div class='stat-title'>Total Outstanding Balance</div><div class='stat-value' style='color:var(--danger)'>" . rm_money($totalAmount) . "</div></div>
            ";

            $theadHtml = "<tr><th>Account No.</th><th>Member Name</th><th>Zone</th><th>Source</th><th>Days Late</th><th style='text-align:right;'>Outstanding</th><th>Status</th></tr>";

            if (empty($unpaid)) {
                $tbodyHtml = "<tr><td colspan='7' style='text-align:center; padding:30px; color:var(--text-muted);'>No delinquent accounts found.</td></tr>";
            } else {
                foreach ($unpaid as $u) {
                    $zone = !empty($u['zone']) ? "Zone " . e($u['zone']) : "—";
                    $statusBadge = (int)$u['days_late'] > 30
                        ? "<span class='badge badge-danger'>Overdue &gt;30d</span>"
                        : "<span class='badge badge-warning'>Overdue</span>";

                    $tbodyHtml .= "<tr>
                        <td class='mono'>" . e($u['account_number'] ?: $u['pkey']) . "</td>
                        <td><strong style='font-weight:600;'>" . e($u['full_name']) . "</strong></td>
                        <td class='mono'>{$zone}</td>
                        <td><span class='badge badge-info'>" . e($u['source']) . "</span></td>
                        <td class='mono'>" . e($u['days_late']) . " days</td>
                        <td class='mono' style='text-align:right; font-weight:700; color:var(--danger)'>" . rm_money($u['amount']) . "</td>
                        <td>{$statusBadge}</td>
                    </tr>";
                }
            }
        }

        // 3. COLLECTION & PAYMENTS
        elseif ($type === 'collection') {
            $dFrom = date('Y-m-d 00:00:00', strtotime($dateFrom));
            $dTo = date('Y-m-d 23:59:59', strtotime($dateTo));

            $stmt = $pdo->prepare("
                SELECT
                    pt.receipt_no AS ref,
                    COALESCE(pt.business_date, DATE(pt.payment_date)) AS paid_date,
                    m.pkey,
                    m.account_number,
                    CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                    pt.payment_type AS source,
                    pt.base_amount,
                    " . (ShareCapitalBilling::ready() ? 'pt.share_capital_amount' : '0.00') . " AS share_capital_amount,
                    pt.penalty_amount,
                    COALESCE(pt.compromise_amount,0) AS compromise_amount,
                    pt.total_paid AS total_amount
                FROM payment_transactions pt
                JOIN water_accounts m ON pt.member_id = m.pkey
                WHERE COALESCE(pt.business_date, DATE(pt.payment_date)) >= DATE(?)
                  AND COALESCE(pt.business_date, DATE(pt.payment_date)) <= DATE(?)
                  AND COALESCE(pt.transaction_status, 'active') = 'active'
                ORDER BY COALESCE(pt.business_date, DATE(pt.payment_date)) DESC, pt.payment_date DESC
            ");
            $stmt->execute([$dFrom, $dTo]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalTx = count($payments);
            $totalAmount = array_sum(array_column($payments, 'total_amount'));
            $totalPenalty = array_sum(array_column($payments, 'penalty_amount'));
            $totalCompromise = array_sum(array_column($payments, 'compromise_amount'));
            $totalShareCapital = array_sum(array_column($payments, 'share_capital_amount'));

            $statsHtml = "
                <div class='stat-card'><div class='stat-title'>Total Collected</div><div class='stat-value' style='color:var(--success)'>" . rm_money($totalAmount) . "</div></div>
                <div class='stat-card'><div class='stat-title'>Transactions</div><div class='stat-value'>{$totalTx}</div></div>
                <div class='stat-card'><div class='stat-title'>Penalty Collected</div><div class='stat-value' style='color:var(--warning)'>" . rm_money($totalPenalty) . "</div></div>
                <div class='stat-card'><div class='stat-title'>Compromise Collected</div><div class='stat-value' style='color:var(--warning)'>" . rm_money($totalCompromise) . "</div></div>
                <div class='stat-card'><div class='stat-title'>Share Capital Collected</div><div class='stat-value'>" . rm_money($totalShareCapital) . "</div></div>
            ";

            $theadHtml = "<tr><th>Date</th><th>Receipt No.</th><th>Account</th><th>Member Name</th><th>Source</th><th style='text-align:right;'>Base</th><th style='text-align:right;'>Share Capital</th><th style='text-align:right;'>Penalty</th><th style='text-align:right;'>Compromise</th><th style='text-align:right;'>Total Paid</th></tr>";

            if (empty($payments)) {
                $tbodyHtml = "<tr><td colspan='10' style='text-align:center; padding:40px; color:var(--text-muted);'>No payments found for this date range.</td></tr>";
            } else {
                foreach ($payments as $p) {
                    $source = ucwords(str_replace(['_', '-'], ' ', (string)$p['source']));
                    if ($source === 'Onetime') $source = 'One-Time';
                    if ($source === 'Sharecap') $source = 'Share Capital';
                    $tbodyHtml .= "<tr>
                        <td class='mono'>" . e(rm_date($p['paid_date'])) . "</td>
                        <td class='mono'><span class='screen-ref' data-full-ref='" . e($p['ref']) . "' title='" . e($p['ref']) . "'>" . e(ui_compact_reference($p['ref'], 20)) . "</span></td>
                        <td class='mono'>" . e($p['account_number'] ?: $p['pkey']) . "</td>
                        <td><strong style='font-weight:600;'>" . e($p['full_name']) . "</strong></td>
                        <td><span class='badge badge-info'>" . e($source) . "</span></td>
                        <td class='mono' style='text-align:right;'>" . rm_money($p['base_amount']) . "</td>
                        <td class='mono' style='text-align:right;'>" . rm_money($p['share_capital_amount']) . "</td>
                        <td class='mono' style='text-align:right; color:var(--warning)'>" . rm_money($p['penalty_amount']) . "</td>
                        <td class='mono' style='text-align:right; color:var(--warning)'>" . rm_money($p['compromise_amount']) . "</td>
                        <td class='mono' style='text-align:right; font-weight:700; color:var(--success)'>" . rm_money($p['total_amount']) . "</td>
                    </tr>";
                }
            }
        }

        // 4. BILLING SUMMARY
        elseif ($type === 'billing_summary') {
            $periodQ = ($periodId !== 'all' && $periodId !== '') ? "AND br.period_id = " . (int)$periodId : "";

            $stmt = $pdo->query("
                SELECT
                    COALESCE(m.zone, 'Unassigned') AS zone,
                    COUNT(br.reading_id) AS bills_count,
                    COALESCE(SUM(br.amount_due), 0) AS total_billed,
                    SUM(CASE WHEN br.is_paid = 1 THEN 1 ELSE 0 END) AS paid_count,
                    SUM(CASE WHEN br.is_paid = 0 THEN 1 ELSE 0 END) AS unpaid_count
                FROM bill_readings br
                JOIN water_accounts m ON br.member_id = m.pkey
                WHERE br.is_billed = 1
                  {$periodQ}
                GROUP BY COALESCE(m.zone, 'Unassigned')
                ORDER BY COALESCE(m.zone, 'Unassigned') ASC
            ");
            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalBills = array_sum(array_column($summary, 'bills_count'));
            $totalAmount = array_sum(array_column($summary, 'total_billed'));
            $paidCount = array_sum(array_column($summary, 'paid_count'));
            $unpaidCount = array_sum(array_column($summary, 'unpaid_count'));

            $statsHtml = "
                <div class='stat-card'><div class='stat-title'>Total Amount Billed</div><div class='stat-value' style='color:var(--info)'>" . rm_money($totalAmount) . "</div></div>
                <div class='stat-card'><div class='stat-title'>Bills Generated</div><div class='stat-value'>{$totalBills}</div></div>
                <div class='stat-card'><div class='stat-title'>Paid / Unpaid</div><div class='stat-value'>{$paidCount} / {$unpaidCount}</div></div>
            ";

            $theadHtml = "<tr><th>Zone</th><th>Bills Generated</th><th>Paid</th><th>Unpaid</th><th style='text-align:right;'>Net Billed Amount</th></tr>";

            if (empty($summary)) {
                $tbodyHtml = "<tr><td colspan='5' style='text-align:center; padding:30px; color:var(--text-muted);'>No posted billing data found for this configuration.</td></tr>";
            } else {
                foreach ($summary as $s) {
                    $zone = is_numeric($s['zone']) ? "Zone " . e($s['zone']) : e($s['zone']);
                    $tbodyHtml .= "<tr>
                        <td><strong style='font-weight:600;'>{$zone}</strong></td>
                        <td class='mono'>" . e($s['bills_count']) . "</td>
                        <td class='mono' style='color:var(--success)'>" . e($s['paid_count']) . "</td>
                        <td class='mono' style='color:var(--warning)'>" . e($s['unpaid_count']) . "</td>
                        <td class='mono' style='text-align:right; font-weight:700; color:var(--accent)'>" . rm_money($s['total_billed']) . "</td>
                    </tr>";
                }
            }
        }

        else {
            throw new Exception('Invalid report type selected.');
        }

        echo json_encode([
            'success' => true,
            'stats' => $statsHtml,
            'thead' => $theadHtml,
            'tbody' => $tbodyHtml
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
}

// ==========================================
// REGULAR PAGE RENDER 
// ==========================================

$periods = [];
$zones = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT period_id, bp_code, start_date, end_date FROM bill_periods ORDER BY start_date DESC");
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtZ = $pdo->query("SELECT DISTINCT zone FROM water_accounts WHERE zone IS NOT NULL ORDER BY zone ASC");
        $zones = $stmtZ->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { }
}

/*
 * Central report/document launchers. Existing pages remain the single source
 * of truth for generation, filtering, printing, and transaction details.
 */
$reportActions = [
    [
        'code' => 'SOA',
        'category' => 'Billing Document',
        'title' => 'Generate SOA',
        'description' => 'Open the Statement of Account workspace, select a billing period and member, then preview and print the SOA.',
        'file' => 'generate_soa.php?view=soa',
        'page_key' => 'reports_management',
        'button' => 'Generate SOA',
    ],
    [
        'code' => 'GL',
        'category' => 'Accounting Report',
        'title' => 'Generate Company Ledger',
        'description' => 'View the company General Ledger by date range, account, or journal reference and print the filtered result.',
        'file' => 'general_ledger.php',
        'page_key' => 'general_ledger',
        'button' => 'Generate Company Ledger',
    ],
    [
        'code' => 'DN',
        'category' => 'Collection Document',
        'title' => 'Generate Disconnection Notice',
        'description' => 'Generate a printable notice register containing all unpaid water, one-time, installment, and recurring bills, with month/period plus manual Penalty and Compromise columns.',
        'file' => 'generate_disconnection_notice.php',
        'page_key' => 'reports_management',
        'button' => 'Generate Notice',
    ],
    [
        'code' => 'OR',
        'category' => 'Collection Document',
        'title' => 'Generate Official Receipt',
        'description' => 'Select a completed payment and generate the printable official receipt. Paid services are listed using their actual bill codes.',
        'file' => 'generate_official_receipt.php',
        'page_key' => 'reports_management',
        'button' => 'Generate Official Receipt',
    ],
    [
        'code' => 'WT',
        'category' => 'Rate Report',
        'title' => 'Generate Water Tariff',
        'description' => 'View and print the complete water-rate schedule, including progressive tiers, charge type, amount, and continuity status.',
        'file' => 'water_rates.php',
        'page_key' => 'water_rates',
        'button' => 'Generate Water Tariff',
    ],
    [
        'code' => 'ML',
        'category' => 'Member Report',
        'title' => 'Generate Member Ledger',
        'description' => 'Select a member, billing-period range, and bill-code columns, then print the detailed water-service ledger. Water Bill is always required and unchecked bill codes are grouped under Other Fees.',
        'file' => 'customer_ledger.php',
        'page_key' => 'reports_management',
        'button' => 'Generate Member Ledger',
    ],
];

foreach ($reportActions as &$reportAction) {
    $physicalFile = (string)(parse_url($reportAction['file'], PHP_URL_PATH) ?: '');
    $reportAction['exists'] = $physicalFile !== '' && is_file(__DIR__ . '/' . $physicalFile);
    $reportAction['allowed'] = $reportAction['exists']
        && userCanAccessPage($pdo, (string)$reportAction['page_key']);
}
unset($reportAction);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Reports Management - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --white:          #ffffff;
            --off-white:      #f8f9fb;
            --border:         #e8eaed;
            --border-focus:   #1a1a2e;
            --text-primary:   #0f0f1a;
            --text-secondary: #6b7280;
            --text-muted:     #9ca3af;
            --accent:         #1a1a2e;
            --accent-light:   #e8f0fe;
            --success:        #059669; --success-bg: #ecfdf5;
            --warning:        #d97706; --warning-bg: #fffbeb;
            --danger:         #dc2626; --danger-bg: #fef2f2;
            --info:           #2563eb; --info-bg: #eff6ff;
            --radius-sm:      8px;
            --radius:         14px;
            --radius-lg:      20px;
            --shadow-sm:      0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --transition:     0.2s cubic-bezier(0.4,0,0.2,1);
        }

        html { scroll-behavior: smooth; height: 100%; font-size: 16px; }
        body {
            font-family: 'Sora', sans-serif;
            background: var(--off-white);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .page { padding: 32px 32px 60px; animation: fadeIn .4s ease both; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        .page-header {
            display: flex; align-items: flex-end; justify-content: space-between;
            margin-bottom: 32px; flex-wrap: wrap; gap: 16px;
        }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; color: var(--text-primary); }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; font-weight: 400; }
        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--text-secondary); text-decoration: none;
            font-size: .8rem; font-weight: 600; margin-bottom: 4px;
        }
        .back-link:hover { color: var(--text-primary); }

        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 24px; box-shadow: var(--shadow-sm); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
        .card-title { font-size: 1.1rem; font-weight: 600; }

        .filter-grid { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; background: var(--white); padding: 20px; border-radius: var(--radius-sm); border: 1.5px solid var(--border); margin-bottom: 24px;}
        .form-group { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 200px; }
        label { font-size: .75rem; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .05em; }

        .form-input { padding: 9px 13px; font-family: 'Sora', sans-serif; font-size: .875rem; border: 1.5px solid var(--border); border-radius: var(--radius-sm); outline: none; background: var(--white); color: var(--text-primary); transition: border-color var(--transition); }
        .form-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px var(--accent-light); }
        select.form-input { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px; appearance: none; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 20px; border-radius: var(--radius-sm); border: none; font-family: inherit; font-size: .8125rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: transform var(--transition), box-shadow var(--transition), background var(--transition); white-space: nowrap; }
        .btn:active { transform: scale(0.97); }
        .btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .btn-primary { background: var(--accent); color: var(--white); box-shadow: 0 2px 10px rgba(26,26,46,.25); }
        .btn-primary:hover { background: #0f0f1a; box-shadow: 0 6px 20px rgba(26,26,46,.3); }
        .btn-success { background: var(--success); color: var(--white); }
        .btn-success:hover { background: #047857; }

        /* Printable reports — action-card grid, matching Billing Hub */
        .report-section { margin-bottom: 30px; }
        .report-section-heading { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; margin-bottom: 14px; }
        .report-section-title { font-size: 1.05rem; font-weight: 700; letter-spacing: -.02em; }
        .report-section-copy { margin-top: 4px; color: var(--text-secondary); font-size: .8125rem; line-height: 1.4; }
        .report-count { flex: 0 0 auto; padding: 5px 9px; border: 1px solid var(--border); border-radius: 999px; background: var(--white); color: var(--text-secondary); font: 600 .7rem 'JetBrains Mono', monospace; }

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
        .action-card.is-disabled { background: #fafafa; opacity: .72; cursor: default; }
        .action-card.is-disabled:hover { transform: none; box-shadow: none; border-color: var(--border); }
        .ac-body { flex: 1; min-width: 0; }
        .ac-title { font-weight: 700; font-size: 1rem; margin-bottom: 6px; letter-spacing: -.01em; }
        .ac-desc { font-size: .8125rem; color: var(--text-secondary); line-height: 1.4; margin-bottom: 14px; }
        .ac-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 14px; border-top: 1px solid var(--border); }
        .ac-status { color: var(--success); font-size: .68rem; font-weight: 700; }
        .ac-status.is-denied { color: var(--danger); }
        .ac-link { padding: 8px 12px; font-size: .74rem; }
        .ac-link.is-disabled { pointer-events: none; background: #e5e7eb; color: #9ca3af; box-shadow: none; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; display: none; }
        .stat-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px 20px; box-shadow: var(--shadow-sm); }
        .stat-title { font-size: .7rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: .05em; margin-bottom: 8px; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); font-family: 'JetBrains Mono', monospace; }

        .card-table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .8125rem; white-space: nowrap; }
        thead th { background: var(--off-white); padding: 13px 20px; text-align: left; font-weight: 700; font-size: .69rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .06em; border-bottom: 1.5px solid var(--border); position: sticky; top: 0; }
        tbody td { padding: 14px 20px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafbfc; }

        .mono { font-family: 'JetBrains Mono', monospace; font-size: .8rem; font-weight: 500; }
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; font-size: .65rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .badge-success { background: var(--success-bg); color: var(--success); }
        .badge-danger { background: var(--danger-bg); color: var(--danger); }
        .badge-warning { background: var(--warning-bg); color: var(--warning); }
        .badge-info { background: var(--info-bg); color: var(--info); }

        .empty-state { padding: 60px 20px; text-align: center; color: var(--text-muted); display: flex; flex-direction: column; align-items: center; gap: 12px; }
        .empty-state svg { width: 48px; height: 48px; color: var(--border); }
        .empty-state-title { font-size: 1rem; font-weight: 600; color: var(--text-primary); }

        .spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,.3); border-radius: 50%; border-top-color: #fff; animation: spin .6s linear infinite; }
        .spinner.dark { border: 3px solid var(--border); border-top-color: var(--accent); }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 768px) {
            .page { padding: 20px 16px; }
            .action-grid { grid-template-columns: 1fr; }
            .report-section-heading { align-items: flex-start; flex-direction: column; }
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

    .card, .filter-grid, .stat-card, .card-table-wrap {
        border-radius: 6px !important;
        box-shadow: none !important;
    }
    .filter-grid { padding: 16px !important; margin-bottom: 16px !important; gap: 12px !important; }
    .stats-grid { gap: 8px !important; margin-bottom: 16px !important; }
    .stat-card { padding: 13px 15px !important; }
    .btn, .form-input, .badge { border-radius: 5px !important; }
    .btn { box-shadow: none !important; transition: background-color .15s ease, border-color .15s ease !important; }
    .btn:hover { transform: none !important; box-shadow: none !important; }
    .empty-state { padding: 26px 18px !important; }

    .report-section { margin-bottom: 24px !important; }
    .report-section-title { font-size: .98rem !important; }
    .report-section-copy { line-height: 1.5 !important; }

    /* Printable reports grid rebuilt as a bordered 2-col table, same as Billing Hub */
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
        align-items: center !important;
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
    .action-card.is-disabled:hover { background: #fafafa !important; }
    .ac-title { margin-bottom: 4px !important; font-size: .94rem !important; }
    .ac-desc { line-height: 1.5 !important; margin-bottom: 0 !important; }
    .ac-footer { margin-top: 10px !important; padding-top: 10px !important; }
    .ac-link { padding: 7px 11px !important; }

    @media (max-width: 760px) {
        .page { padding: 22px 16px 40px !important; }
        .action-grid { grid-template-columns: 1fr !important; }
        .action-card { border-right: 0 !important; }
    }
}
@media (prefers-reduced-motion: reduce) {
    .page, .action-card { animation: none !important; transition: none !important; }
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
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                        Back to Dashboard
                    </a>
                    <h1 class="page-title">Reports Management</h1>
                    <p class="page-subtitle">Central workspace for billing documents, collection outputs, ledgers, financial statements, and operational reports.</p>
                </div>
            </div>

            <section class="report-section" aria-labelledby="printableReportsHeading">
                <div class="report-section-heading">
                    <div>
                        <h2 class="report-section-title" id="printableReportsHeading">Printable Reports and Documents</h2>
                        <p class="report-section-copy">Open the system's existing report pages from one location. Each option continues to use its original database queries, filters, permissions, and print layout.</p>
                    </div>
                    <span class="report-count"><?= count($reportActions) ?> documents</span>
                </div>

                <div class="action-grid">
                    <?php foreach ($reportActions as $action): ?>
                        <?php
                            $isAvailable = !empty($action['exists']);
                            $isAllowed = !empty($action['allowed']);
                            $isDisabled = !$isAvailable || !$isAllowed;
                            $statusText = !$isAvailable ? 'Page unavailable' : 'Access not assigned';
                            $cardTag = $isDisabled ? 'div' : 'a';
                        ?>
                        <<?= $cardTag ?> <?= $isDisabled ? '' : 'href="' . e($action['file']) . '"' ?> class="action-card<?= $isDisabled ? ' is-disabled' : '' ?>">
                            <div class="ac-body">
                                <div class="ac-title"><?= e($action['title']) ?></div>
                                <div class="ac-desc"><?= e($action['description']) ?></div>
                                <div class="ac-footer">
                                    <?php if ($isDisabled): ?>
                                        <span class="ac-status is-denied"><?= e($statusText) ?></span>
                                    <?php endif; ?>
                                    <?php if (!$isDisabled): ?>
                                        <span class="btn btn-primary ac-link">
                                            <?= e($action['button']) ?>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="13 6 19 12 13 18"></polyline></svg>
                                        </span>
                                    <?php else: ?>
                                        <span class="btn ac-link is-disabled" aria-disabled="true"><?= e($action['button']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </<?= $cardTag ?>>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="report-section" aria-labelledby="operationalReportsHeading">
                <div class="report-section-heading">
                    <div>
                        <h2 class="report-section-title" id="operationalReportsHeading">Operational Data Reports</h2>
                        <p class="report-section-copy">Generate on-screen summaries and export the results to CSV using the filters below.</p>
                    </div>
                </div>

            <div class="filter-grid" id="reportFilters">
                <div class="form-group">
                    <label>Report Type</label>
                    <select id="reportType" class="form-input" onchange="toggleFilterFields()">
                        <option value="collection">Collection Report</option>
                        <option value="billing_summary">Billing Summary (Per Period)</option>
                        <option value="delinquent">Delinquent / Unpaid Bills</option>
                        <option value="members">Member Masterlist</option>
                    </select>
                </div>

                <div class="form-group filter-date">
                    <label>Date From</label>
                    <input type="date" id="dateFrom" class="form-input" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="form-group filter-date">
                    <label>Date To</label>
                    <input type="date" id="dateTo" class="form-input" value="<?= date('Y-m-t') ?>">
                </div>

                <div class="form-group filter-period" style="display: none;">
                    <label>Billing Period</label>
                    <select id="periodId" class="form-input">
                        <option value="all">All Periods</option>
                        <?php foreach($periods as $p): ?>
                            <option value="<?= $p['period_id'] ?>"><?= e($p['bp_code']) ?> (<?= date('M j', strtotime($p['start_date'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group filter-zone" style="display: none;">
                    <label>Zone Filter</label>
                    <select id="zoneId" class="form-input">
                        <option value="all">All Zones</option>
                        <?php foreach($zones as $z): ?>
                            <option value="<?= e($z) ?>">Zone <?= e($z) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 0 0 auto;">
                    <button class="btn btn-primary" onclick="generateReport()" id="generateBtn" style="height: 41px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Generate Report
                    </button>
                </div>
            </div>

            <div class="stats-grid" id="statsGrid"></div>

            <div class="card" style="padding: 0;">
                <div class="card-header" style="margin: 20px 24px 16px;">
                    <div>
                        <h2 class="card-title" id="tableTitle">Report Results</h2>
                        <div style="font-size: .8rem; color: var(--text-secondary); margin-top: 4px;" id="tableSubtitle">Configure filters and generate a report to view data.</div>
                    </div>
                    <button class="btn btn-success" id="exportBtn" onclick="exportToCSV()" style="display: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                        Export CSV
                    </button>
                </div>

                <div class="card-table-wrap" style="border: none; border-radius: 0 0 var(--radius) var(--radius); max-height: 60vh;">
                    <table id="reportTable">
                        <thead id="tableHead"></thead>
                        <tbody id="tableBody">
                            <tr>
                                <td colspan="100%">
                                    <div class="empty-state">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                                        <div>
                                            <div class="empty-state-title">No report generated</div>
                                            <p style="font-size:.85rem; margin-top:4px;">Select your parameters and click Generate Report.</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            </section>

        </main>
    </div>

    <script>
        // Toggles visibility of filter fields based on report type
        function toggleFilterFields() {
            const type = document.getElementById('reportType').value;

            document.querySelectorAll('.filter-date').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.filter-period').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.filter-zone').forEach(el => el.style.display = 'none');

            if (type === 'collection') {
                document.querySelectorAll('.filter-date').forEach(el => el.style.display = 'flex');
            } else if (type === 'billing_summary') {
                document.querySelectorAll('.filter-period').forEach(el => el.style.display = 'flex');
            } else if (type === 'delinquent') {
                document.querySelectorAll('.filter-period').forEach(el => el.style.display = 'flex');
                document.querySelectorAll('.filter-zone').forEach(el => el.style.display = 'flex');
            } else if (type === 'members') {
                document.querySelectorAll('.filter-zone').forEach(el => el.style.display = 'flex');
            }
        }

        // Generate Report via Real API Call
        async function generateReport() {
            const type = document.getElementById('reportType').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const periodId = document.getElementById('periodId').value;
            const zoneId = document.getElementById('zoneId').value;

            const btn = document.getElementById('generateBtn');
            const tbody = document.getElementById('tableBody');
            const thead = document.getElementById('tableHead');
            const stats = document.getElementById('statsGrid');

            // Loading State
            btn.innerHTML = `<span class="spinner"></span> Generating...`;
            btn.disabled = true;
            tbody.innerHTML = `<tr><td colspan="100%"><div class="empty-state"><span class="spinner dark"></span>Generating Report Data...</div></td></tr>`;
            stats.style.display = 'none';
            document.getElementById('exportBtn').style.display = 'none';

            // Construct Query String
            const qs = new URLSearchParams({
                ajax_report: 1,
                report_type: type,
                date_from: dateFrom,
                date_to: dateTo,
                period_id: periodId,
                zone_id: zoneId
            });

            try {
                const reportUrl = `${window.location.pathname}?${qs.toString()}`;
                const response = await fetch(reportUrl, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });

                const rawText = await response.text();
                let data;

                try {
                    data = JSON.parse(rawText);
                } catch (jsonError) {
                    throw new Error(`The report endpoint did not return JSON. HTTP ${response.status}. Response: ${rawText.substring(0, 300)}`);
                }

                if (!response.ok) {
                    throw new Error(data.error || `HTTP ${response.status}`);
                }

                if (data.success) {
                    stats.innerHTML = data.stats;
                    thead.innerHTML = data.thead;
                    tbody.innerHTML = data.tbody;

                    stats.style.display = 'grid';
                    document.getElementById('exportBtn').style.display = 'inline-flex';

                    // Update Headers
                    if (type === 'collection') {
                        document.getElementById('tableTitle').textContent = 'Collection Report';
                        document.getElementById('tableSubtitle').textContent = `From ${dateFrom} to ${dateTo}`;
                    } else if (type === 'billing_summary') {
                        document.getElementById('tableTitle').textContent = 'Billing Summary';
                        document.getElementById('tableSubtitle').textContent = `Data based on selected period filters`;
                    } else if (type === 'delinquent') {
                        document.getElementById('tableTitle').textContent = 'Delinquent Accounts';
                        document.getElementById('tableSubtitle').textContent = `Accounts with unpaid balances`;
                    } else if (type === 'members') {
                        document.getElementById('tableTitle').textContent = 'Member Masterlist';
                        document.getElementById('tableSubtitle').textContent = `Summary of registered cooperative members`;
                    }
                } else {
                    tbody.innerHTML = `<tr><td colspan="100%"><div class="empty-state" style="color:var(--danger)">Database Error: ${data.error}</div></td></tr>`;
                }
            } catch (error) {
                tbody.innerHTML = `<tr><td colspan="100%"><div class="empty-state" style="color:var(--danger)">Network Error: Could not connect to the server.</div></td></tr>`;
            } finally {
                btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Generate Report`;
                btn.disabled = false;
            }
        }

        // HTML Table to CSV Downloader
        function exportToCSV() {
            let csv = [];
            const rows = document.querySelectorAll("table#reportTable tr");

            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");

                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/₱/g, '').replace(/,/g, '').trim();
                    data = data.replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                csv.push(row.join(","));
            }

            const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
            const downloadLink = document.createElement("a");
            downloadLink.download = `Water_Report_${document.getElementById('reportType').value}_${new Date().toISOString().slice(0,10)}.csv`;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }

        // Init
        toggleFilterFields();
    </script>
</body>
</html>

