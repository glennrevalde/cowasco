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
$pdo = getDB();

if (!function_exists('e')) {
    function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

try {
    $stmt = $pdo->query("SELECT period_id, bp_code FROM bill_periods ORDER BY start_date DESC");
    $allPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) { $allPeriods = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
    <title>Reading History Log - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --white: #ffffff; --off-white: #f8f9fb; --border: #e8eaed; 
            --text-primary: #0f0f1a; --text-secondary: #6b7280; --text-muted: #9ca3af;
            --info: #2563eb; --info-bg: #eff6ff; 
            --success: #059669; --success-bg: #ecfdf5; 
            --warning: #d97706; --warning-bg: #fffbeb;
            --error: #dc2626; --error-bg: #fef2f2;
            --accent: #1a1a2e;
            --radius-sm: 8px; --radius: 14px; --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
        }
        html { height: 100%; font-size: 16px; }
        body { font-family: 'Sora', sans-serif; background: var(--off-white); color: var(--text-primary); min-height: 100vh; }
        
        .page { padding: 32px 36px; max-width: 1400px; margin: 0 auto; animation: fadeIn .4s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .page-header { margin-bottom: 24px; }
        .page-title { font-size: 1.5rem; font-weight: 700; letter-spacing: -.03em; }
        .page-subtitle { font-size: .875rem; color: var(--text-secondary); margin-top: 4px; }

        .card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 24px; }
        .card-head { padding: 18px 22px; border-bottom: 1px solid var(--border); background: #fafafa; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        
        .filters { display: flex; gap: 12px; align-items: center; flex: 1; justify-content: flex-end; }
        .form-control { width: 100%; max-width: 250px; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: 'Sora', sans-serif; font-size: .85rem; outline: none; transition: .2s; }
        .form-control:focus { border-color: var(--info); box-shadow: 0 0 0 3px var(--info-bg); }

        .table-wrap { overflow-x: auto; max-height: 70vh; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: .85rem; }
        thead th { background: var(--off-white); padding: 12px 16px; border-bottom: 2px solid var(--border); color: var(--text-secondary); font-weight: 700; text-transform: uppercase; font-size: .75rem; letter-spacing: .05em; position: sticky; top: 0; z-index: 10;}
        tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); }
        tbody tr:hover { background: #fafbfc; }
        
        .mono { font-family: 'JetBrains Mono', monospace; }
        
        .badge { font-size: .65rem; padding: 4px 8px; border-radius: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .badge-done   { background: var(--success-bg); color: var(--success); }
        .badge-billed { background: var(--info-bg); color: var(--info); }
        .badge-disc   { background: #fef3c7; color: #b45309; }
        .badge-new    { background: #f3e8ff; color: #7c3aed; }

        .ledger-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #ffffff;
            color: var(--info);
            font-size: .74rem;
            font-weight: 800;
            text-decoration: none;
            white-space: nowrap;
            transition: border-color .2s, background .2s, transform .2s;
        }
        .ledger-btn:hover {
            border-color: var(--info);
            background: var(--info-bg);
            transform: translateY(-1px);
        }

        .empty-state { padding: 40px; text-align: center; color: var(--text-muted); }
        .spinner { display: inline-block; width: 28px; height: 28px; border: 3px solid var(--border); border-top-color: var(--info); border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
    <main class="page">
        <div class="page-header">
            <a href="billing_management.php" style="display:inline-flex; align-items:center; gap:6px; color:var(--text-secondary); text-decoration:none; font-size:.8rem; font-weight:600; margin-bottom:8px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                Back to Billing Management
            </a>
            <h1 class="page-title">Billing History</h1>
            <p class="page-subtitle">View meter reading records and open a  printable Customer Ledger for each member.</p>
        </div>

        <div class="card">
            <div class="card-head">
                <div style="font-weight:700; font-size: 1rem; color: var(--text-primary); display:flex; align-items:center; gap:8px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--info);"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    Billing Records
                </div>
                <div class="filters">
                    <select id="periodFilter" class="form-control" onchange="fetchHistory()">
                        <option value="">All Billing Periods</option>
                        <?php foreach ($allPeriods as $p): ?>
                            <option value="<?= $p['period_id'] ?>"><?= e($p['bp_code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="searchFilter" class="form-control" placeholder="Search Member Name or Acct..." oninput="debounceFetch()">
                </div>
            </div>
            
            <div class="table-wrap">
                <table id="historyTable">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Member</th>
                            <th style="text-align: right;">Prev (m³)</th>
                            <th style="text-align: right;">Present (m³)</th>
                            <th style="text-align: right;">Consumed</th>
                            <th style="text-align: right; color:var(--accent);">Total Due</th>
                            <th>Status</th>
                            <th>Mode</th>
                            <th>Encoded Info</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">
                                    <div class="spinner"></div>
                                    <div style="margin-top: 10px;">Loading historical readings...</div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
    let timer;
    const fmtPeso = n => '₱ ' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fmtReading = n => Math.round(Number(n) || 0).toLocaleString('en-US');
    const e = str => String(str).replace(/[&<>'"]/g, match => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[match]));

    function debounceFetch() {
        clearTimeout(timer);
        timer = setTimeout(fetchHistory, 300);
    }

    function fetchHistory() {
        const tbody = document.getElementById('historyBody');
        const periodId = document.getElementById('periodFilter').value;
        const query = document.getElementById('searchFilter').value.trim();

        tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state"><div class="spinner"></div><div style="margin-top: 10px;">Fetching records...</div></div></td></tr>`;

        fetch(`../process/getReadingHistory.php?context=billing_history&period_id=${periodId}&q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state" style="color:var(--error);">Error: ${e(data.error)}</div></td></tr>`;
                    return;
                }

                const readings = data.readings || [];

                if (readings.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state">No readings found matching your filters.</div></td></tr>`;
                    return;
                }

                tbody.innerHTML = readings.map(r => {
                    const statusBadge = r.is_billed == 1
                        ? `<span class="badge badge-billed">Billed</span>`
                        : `<span class="badge badge-done">Encoded</span>`;
                    const modeBadge = r.entry_mode === 'BATCH'
                        ? `<span class="badge badge-disc">Batch</span>`
                        : `<span class="badge badge-new">Individual</span>`;
                    const dateStr = r.updated_at 
                        ? new Date(r.updated_at).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' }) 
                        : '—';
                    
                    const totalDue = r.amount_due ? parseFloat(r.amount_due) : 0;

                    return `
                    <tr>
                        <td>
                            <strong style="color:var(--text-primary); font-size: .8rem;">${e(r.bp_code)}</strong>
                        </td>
                        <td>
                            <strong style="color:var(--text-primary); font-size: .85rem;">${e(r.full_name)}</strong>
                            <div class="mono" style="font-size: .7rem; color: var(--text-secondary); margin-top: 2px;">Acct: ${e(r.account_number)}</div>
                        </td>
                        <td class="mono" style="text-align: right; color: var(--text-secondary);">${fmtReading(r.prev_reading)}</td>
                        <td class="mono" style="text-align: right; color: var(--text-primary); font-weight: 600;">${fmtReading(r.pres_reading)}</td>
                        <td class="mono" style="text-align: right; color: var(--info); font-weight: 700;">${fmtReading(r.consumption)}</td>
                        <td class="mono" style="text-align: right; font-weight: 700; color: var(--accent); background:#f8f9fb;">${fmtPeso(totalDue)}</td>
                        <td>${statusBadge}</td>
                        <td>${modeBadge}</td>
                        <td style="font-size: .75rem; color: var(--text-secondary);">
                            <span style="display:block;">By: ${e(r.encoded_by || 'system')}</span>
                            <span style="display:block; margin-top:2px;">${dateStr}</span>
                        </td>
                        <td>
                            <a class="ledger-btn" href="customer_ledger.php?member_id=${encodeURIComponent(r.member_id || r.account_number)}" target="_blank" title="Open printable customer ledger"> Ledger</a>
                        </td>
                    </tr>`;
                }).join('');
            })
            .catch(() => {
                tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state" style="color:var(--error);">Failed to connect to the server.</div></td></tr>`;
            });
    }

    // Load data on initial page load
    document.addEventListener('DOMContentLoaded', fetchHistory);
</script>
</body>
</html>