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


if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$branches = [];
$divisions = [];
$segments = [];

try {
    $branches = $pdo->query("
        SELECT
            pkey,
            name,
            description,
            Deleted,
            createdby,
            date_created,
            modifiedby,
            date_modified
        FROM branches
        ORDER BY Deleted ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $divisions = $pdo->query("
        SELECT
            pkey,
            name,
            datecreated,
            datelastmodified,
            status
        FROM ic_divisions
        ORDER BY status ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $segments = $pdo->query("
        SELECT
            pkey,
            name,
            datecreated,
            datelastmodified,
            status
        FROM ic_segments
        ORDER BY status ASC, name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $_SESSION['error_msg'] = 'Fetch error: ' . $e->getMessage();
}

function wb_unit_status_badge(string $status): string
{
    $isActive = $status === 'A' || $status === '0' || strtolower($status) === 'active';

    return $isActive
        ? '<span class="badge active">Active</span>'
        : '<span class="badge inactive">Inactive</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Branch, Division & Segment Setup</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../common/fonts.css">
<style>
        body, button, input, select, textarea { font-family: 'Sora', sans-serif; }
        .mono, code, pre { font-family: 'JetBrains Mono', monospace; }

    :root {
        --white:#fff;
        --off:#f8f9fb;
        --border:#e5e7eb;
        --text:#111827;
        --muted:#6b7280;
        --accent:#111827;
        --success:#047857;
        --danger:#b91c1c;
        --radius:14px;
    }

    * {
        box-sizing:border-box;
    }

    body {
        margin:0;
        background:var(--off);
        color:var(--text);
        font-family:Arial, sans-serif;
    }

    .page {
        max-width:1500px;
        margin:0 auto;
        padding:32px;
    }

    .page-header {
        display:flex;
        justify-content:space-between;
        align-items:flex-end;
        gap:16px;
        flex-wrap:wrap;
        margin-bottom:22px;
    }

    h1 {
        margin:0;
        font-size:26px;
    }

    h2 {
        margin:0 0 14px;
        font-size:20px;
    }

    .subtitle {
        margin-top:6px;
        color:var(--muted);
        font-size:14px;
    }

    .card {
        background:#fff;
        border:1px solid var(--border);
        border-radius:var(--radius);
        padding:20px;
        margin-bottom:18px;
        box-shadow:0 1px 3px rgba(0,0,0,.05);
    }

    .card-header {
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:14px;
    }

    .btn {
        background:var(--accent);
        color:#fff;
        border:0;
        border-radius:8px;
        padding:10px 14px;
        font-weight:800;
        cursor:pointer;
        text-decoration:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:7px;
    }

    .btn.secondary {
        background:#fff;
        color:var(--text);
        border:1px solid var(--border);
    }

    .btn.danger {
        background:#dc2626;
    }

    .btn.success {
        background:#047857;
    }

    .table-wrap {
        overflow-x:auto;
        border:1px solid var(--border);
        border-radius:12px;
    }

    table {
        width:100%;
        border-collapse:collapse;
        font-size:14px;
        white-space:nowrap;
    }

    th, td {
        padding:11px 12px;
        border-bottom:1px solid var(--border);
        text-align:left;
        vertical-align:top;
    }

    th {
        background:#f9fafb;
        color:var(--muted);
        text-transform:uppercase;
        font-size:12px;
        letter-spacing:.04em;
    }

    tr:last-child td {
        border-bottom:0;
    }

    .right {
        text-align:right;
    }

    .mono {
        font-family:Consolas, monospace;
    }

    .badge {
        display:inline-flex;
        padding:3px 8px;
        border-radius:999px;
        font-size:12px;
        font-weight:800;
        background:#f3f4f6;
        color:#374151;
    }

    .badge.active {
        background:#ecfdf5;
        color:#047857;
    }

    .badge.inactive {
        background:#fef2f2;
        color:#b91c1c;
    }

    .alert {
        padding:12px 14px;
        border-radius:10px;
        margin-bottom:16px;
        font-weight:700;
    }

    .alert.success {
        background:#ecfdf5;
        color:#047857;
        border:1px solid #10b981;
    }

    .alert.danger {
        background:#fef2f2;
        color:#b91c1c;
        border:1px solid #ef4444;
    }

    .modal {
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.45);
        z-index:1060;
        align-items:center;
        justify-content:center;
        padding:18px;
    }

    .modal.open {
        display:flex;
    }

    .modal-box {
        width:100%;
        max-width:620px;
        background:#fff;
        border-radius:16px;
        padding:22px;
        max-height:90vh;
        overflow:auto;
    }

    .form-grid {
        display:grid;
        grid-template-columns:repeat(2,1fr);
        gap:12px;
    }

    .full {
        grid-column:1/-1;
    }

    label {
        display:block;
        font-size:12px;
        color:var(--muted);
        text-transform:uppercase;
        font-weight:800;
        margin-bottom:5px;
        letter-spacing:.04em;
    }

    input, select, textarea {
        width:100%;
        padding:10px 12px;
        border:1px solid var(--border);
        border-radius:8px;
        background:#fff;
        font:inherit;
    }

    textarea {
        min-height:80px;
        resize:vertical;
    }

    .tabs {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-bottom:16px;
    }

    .tab-btn {
        background:#fff;
        border:1px solid var(--border);
        border-radius:999px;
        padding:8px 12px;
        cursor:pointer;
        font-weight:800;
    }

    .tab-btn.active {
        background:#111827;
        color:#fff;
    }

    .section {
        display:none;
    }

    .section.active {
        display:block;
    }

    @media(max-width:800px) {
        .page {
            padding:18px;
        }

        .form-grid {
            grid-template-columns:1fr;
        }
    }
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>

<div class="main-content">
<main class="page">
    <div class="page-header">
        <div>
            <h1>Branch, Division & Segment Setup</h1>
            <div class="subtitle">
                Uses your existing tables: branches, ic_divisions, and ic_segments.
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['success_msg'])): ?>
        <div class="alert success"><?= h($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert danger"><?= h($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="showSection('branchesSection', this)">Branches</button>
        <button class="tab-btn" onclick="showSection('divisionsSection', this)">Divisions</button>
        <button class="tab-btn" onclick="showSection('segmentsSection', this)">Segments</button>
    </div>

    <section class="section active" id="branchesSection">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Branches</h2>
                    <div class="subtitle">Saved in branches table. Use branches.pkey as BranchID.</div>
                </div>
                <button class="btn" onclick="openUnitModal('branch')">Add Branch</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Branch Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Modified</th>
                            <th class="right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): ?>
                            <tr>
                                <td class="mono"><?= h($branch['pkey']) ?></td>
                                <td><strong><?= h($branch['name']) ?></strong></td>
                                <td><?= h($branch['description']) ?></td>
                                <td><?= wb_unit_status_badge((string)$branch['Deleted']) ?></td>
                                <td><?= h($branch['date_created'] ?: '—') ?></td>
                                <td><?= h($branch['date_modified'] ?: '—') ?></td>
                                <td class="right">
                                    <button
                                        class="btn secondary"
                                        onclick='editUnit("branch", <?= json_encode($branch, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'
                                    >Edit</button>

                                    <?php if ((int)$branch['Deleted'] === 0): ?>
                                        <form action="../process/saveOrganizationUnit.php" method="POST" style="display:inline;" data-confirm="Delete this branch?"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="unit" value="branch">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= h($branch['pkey']) ?>">
                                            <button class="btn danger">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($branches)): ?>
                            <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px;">No branches found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="section" id="divisionsSection">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Divisions</h2>
                    <div class="subtitle">Saved in ic_divisions table. Use ic_divisions.pkey as DivisionID.</div>
                </div>
                <button class="btn" onclick="openUnitModal('division')">Add Division</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Division Name</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Modified</th>
                            <th class="right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($divisions as $division): ?>
                            <tr>
                                <td class="mono"><?= h($division['pkey']) ?></td>
                                <td><strong><?= h($division['name']) ?></strong></td>
                                <td><?= wb_unit_status_badge((string)$division['status']) ?></td>
                                <td><?= h($division['datecreated'] ?: '—') ?></td>
                                <td><?= h($division['datelastmodified'] ?: '—') ?></td>
                                <td class="right">
                                    <button
                                        class="btn secondary"
                                        onclick='editUnit("division", <?= json_encode($division, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'
                                    >Edit</button>

                                    <?php if ($division['status'] === 'A'): ?>
                                        <form action="../process/saveOrganizationUnit.php" method="POST" style="display:inline;" data-confirm="Deactivate this division?"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="unit" value="division">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= h($division['pkey']) ?>">
                                            <button class="btn danger">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($divisions)): ?>
                            <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No divisions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="section" id="segmentsSection">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Segments</h2>
                    <div class="subtitle">Saved in ic_segments table. Use ic_segments.pkey as SegmentID.</div>
                </div>
                <button class="btn" onclick="openUnitModal('segment')">Add Segment</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Segment Name</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Modified</th>
                            <th class="right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($segments as $segment): ?>
                            <tr>
                                <td class="mono"><?= h($segment['pkey']) ?></td>
                                <td><strong><?= h($segment['name']) ?></strong></td>
                                <td><?= wb_unit_status_badge((string)$segment['status']) ?></td>
                                <td><?= h($segment['datecreated'] ?: '—') ?></td>
                                <td><?= h($segment['datelastmodified'] ?: '—') ?></td>
                                <td class="right">
                                    <button
                                        class="btn secondary"
                                        onclick='editUnit("segment", <?= json_encode($segment, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'
                                    >Edit</button>

                                    <?php if ($segment['status'] === 'A'): ?>
                                        <form action="../process/saveOrganizationUnit.php" method="POST" style="display:inline;" data-confirm="Deactivate this segment?"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="unit" value="segment">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= h($segment['pkey']) ?>">
                                            <button class="btn danger">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($segments)): ?>
                            <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px;">No segments found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>
</div>

<div class="modal" id="unitModal">
    <div class="modal-box">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
            <h2 id="unitModalTitle">Add</h2>
            <button type="button" class="btn secondary" onclick="closeUnitModal()">Close</button>
        </div>

        <form action="../process/saveOrganizationUnit.php" method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="unit" id="unitType">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="unitId">

            <div class="form-grid">
                <div class="full">
                    <label>Name</label>
                    <input type="text" name="name" id="unitName" required>
                </div>

                <div class="full" id="descriptionGroup">
                    <label>Description</label>
                    <textarea name="description" id="unitDescription"></textarea>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status" id="unitStatus">
                        <option value="A">Active</option>
                        <option value="I">Inactive</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:16px;text-align:right;">
                <button type="button" class="btn secondary" onclick="closeUnitModal()">Cancel</button>
                <button class="btn">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function showSectionById(sectionId) {
    const buttonMap = {
        branchesSection: 0,
        divisionsSection: 1,
        segmentsSection: 2,
    };
    if (!(sectionId in buttonMap)) return;
    const buttons = Array.from(document.querySelectorAll('.tab-btn'));
    const button = buttons[buttonMap[sectionId]] || null;
    if (button) showSection(sectionId, button);
}

function showSection(sectionId, button) {
    document.querySelectorAll('.section').forEach(section => section.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

    document.getElementById(sectionId).classList.add('active');
    button.classList.add('active');
}

function openUnitModal(unit) {
    const titleMap = {
        branch: 'Add Branch',
        division: 'Add Division',
        segment: 'Add Segment'
    };

    document.getElementById('unitModalTitle').textContent = titleMap[unit] || 'Add';
    document.getElementById('unitType').value = unit;
    document.getElementById('unitId').value = '';
    document.getElementById('unitName').value = '';
    document.getElementById('unitDescription').value = '';
    document.getElementById('unitStatus').value = 'A';

    document.getElementById('descriptionGroup').style.display = unit === 'branch' ? '' : 'none';

    document.getElementById('unitModal').classList.add('open');
}

function editUnit(unit, row) {
    const titleMap = {
        branch: 'Edit Branch',
        division: 'Edit Division',
        segment: 'Edit Segment'
    };

    document.getElementById('unitModalTitle').textContent = titleMap[unit] || 'Edit';
    document.getElementById('unitType').value = unit;
    document.getElementById('unitId').value = row.pkey || '';
    document.getElementById('unitName').value = row.name || '';
    document.getElementById('unitDescription').value = row.description || '';

    if (unit === 'branch') {
        document.getElementById('unitStatus').value = parseInt(row.Deleted || 0) === 0 ? 'A' : 'I';
        document.getElementById('descriptionGroup').style.display = '';
    } else {
        document.getElementById('unitStatus').value = row.status === 'A' ? 'A' : 'I';
        document.getElementById('descriptionGroup').style.display = 'none';
    }

    document.getElementById('unitModal').classList.add('open');
}

function closeUnitModal() {
    document.getElementById('unitModal').classList.remove('open');
}

document.addEventListener('DOMContentLoaded', () => {
    const sectionId = String(window.location.hash || '').replace(/^#/, '');
    if (sectionId) showSectionById(sectionId);
});
</script>
</body>
</html>
