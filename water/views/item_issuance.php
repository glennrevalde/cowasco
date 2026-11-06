<?php
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../process/accessControlHelpers.php';
require_once __DIR__ . '/../common/systemDataHelpers.php';
require_once __DIR__ . '/../common/displayHelpers.php';
require_once __DIR__ . '/../process/inventoryIssuanceAccountingHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';

$pdo = getDB();
requirePageAccess($pdo, 'item_issuance');

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$issuable_items = [];   // the materials (navbar.php uses $items for the menu, so the page keeps its own name)
$accounts = [];
$issuances = [];
$error = '';

try {
    if (!sys_table_exists($pdo, 'inventory_issuances') || !sys_table_exists($pdo, 'inventory_issuance_items')) {
        throw new RuntimeException('The material issuance tables are not installed. Run the water billing database migration first.');
    }

    // the materials: inventory bill codes linked to an item of the Item List, with the cooperative stock on hand
    $issuable_items = issuance_pickable_items($pdo, true);
    $unlinked = (int)$pdo->query("SELECT COUNT(*) FROM bill_codes WHERE type = 'Inventory' AND (item_id IS NULL OR item_id = 0)")->fetchColumn();

    // the accounts an issuance may be charged to: active Expense accounts of the chart of accounts
    $accounts = issuance_expense_accounts($pdo);

    $issuances = $pdo->query("
        SELECT
            h.*,
            expense.name AS expense_account_name,
            expense.code AS expense_account_code,
            t.txn_no AS ledger_txn_no,
            t.is_voided AS ledger_voided,
            COUNT(i.issuance_item_id) AS item_count,
            COALESCE(SUM(i.line_cost), 0) AS total_cost
        FROM inventory_issuances h
        LEFT JOIN inventory_issuance_items i
          ON i.issuance_id = h.issuance_id
        LEFT JOIN accounts expense
          ON expense.id = h.expense_account_id
        LEFT JOIN transactions t
          ON t.id = h.txn_id
        GROUP BY h.issuance_id, expense.name, expense.code, t.txn_no, t.is_voided
        ORDER BY h.issuance_id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$flash = $_SESSION['issuance_flash'] ?? null;
unset($_SESSION['issuance_flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Material Issuance</title>
    <link rel="stylesheet" href="../common/fonts.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8f9fb; color: #111827; font-family: Sora, sans-serif; }
        .page { max-width: 1500px; margin: auto; padding: 30px; }
        .head { display: flex; justify-content: space-between; align-items: end; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; }
        h1 { margin: 0; font-size: 1.5rem; }
        h2 { font-size: 1.05rem; }
        .sub { color: #6b7280; font-size: .84rem; margin-top: 5px; }
        .btn { border: 0; border-radius: 8px; background: #111827; color: #fff; min-height: 39px; padding: 0 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn.light { background: #fff; color: #111827; border: 1px solid #d1d5db; }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 18px; margin-bottom: 18px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 12px; }
        .field { display: flex; flex-direction: column; gap: 5px; }
        .field.full { grid-column: 1 / -1; }
        .field label { font-size: .68rem; font-weight: 800; text-transform: uppercase; color: #6b7280; }
        .field input, .field textarea, .field select { border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; font: inherit; background: #fff; }
        .field small { color: #6b7280; line-height: 1.45; }
        .line { display: grid; grid-template-columns: 1fr 160px 42px; gap: 8px; margin: 8px 0; }
        .remove { border: 0; background: #fef2f2; color: #b91c1c; border-radius: 8px; font-size: 1.1rem; cursor: pointer; }
        .actions { display: flex; gap: 9px; justify-content: flex-end; margin-top: 14px; }
        .table { overflow: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; }
        table { width: 100%; border-collapse: collapse; min-width: 1320px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #eef0f2; text-align: left; font-size: .76rem; vertical-align: top; }
        th { background: #f8fafc; text-transform: uppercase; font-size: .65rem; color: #6b7280; }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .status { font-size: .65rem; font-weight: 800; border-radius: 999px; padding: 5px 8px; background: #ecfdf5; color: #047857; white-space: nowrap; }
        .status.rev { background: #f3f4f6; color: #6b7280; }
        .reverse { display: flex; gap: 5px; min-width: 265px; }
        .reverse input { height: 32px; border: 1px solid #d1d5db; border-radius: 7px; padding: 0 8px; min-width: 175px; }
        .reverse button { border: 0; background: #b91c1c; color: #fff; border-radius: 7px; font-weight: 700; cursor: pointer; }
        .alert { padding: 12px; border-radius: 10px; margin-bottom: 15px; }
        .success { background: #ecfdf5; color: #047857; }
        .error { background: #fef2f2; color: #b91c1c; }
        .journal { display: grid; gap: 3px; }
        .journal small { color: #6b7280; }
        @media (max-width: 750px) {
            .page { padding: 20px 14px; }
            .grid { grid-template-columns: 1fr; }
            .line { grid-template-columns: 1fr 110px 38px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../common/navbar.php'; ?>
<div class="main-content">
    <main class="page">
        <div class="head">
            <div>
                <h1>Material Issuance</h1>
                <div class="sub">Issue materials from the cooperative inventory to field work. Each issuance takes the stock out at its average cost and posts one inventory adjustment to the ledger.</div>
            </div>
            <a class="btn light" href="<?= e(url('rpt.stock_status_item')) ?>">Stock Status by Item</a>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($flash): ?>
            <div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
        <?php endif; ?>
        <?php if ($error === '' && !$issuable_items): ?>
            <div class="alert error">No material can be issued yet: no inventory bill code linked to an item of the Item List has stock on hand.<?= !empty($unlinked) ? ' ' . (int)$unlinked . ' inventory bill code(s) are not linked to an item yet (Water Billing > Bill Codes).' : '' ?></div>
        <?php endif; ?>

        <form class="card" method="post" action="../process/saveItemIssuance.php" id="issuanceForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="grid">
                <div class="field">
                    <label>Issuance Date</label>
                    <input type="date" name="issuance_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label>Recipient</label>
                    <input name="recipient" maxlength="150" required placeholder="Employee, team, or contractor">
                </div>
                <div class="field">
                    <label>Department</label>
                    <input name="department" maxlength="120" placeholder="Optional department or project">
                </div>
                <div class="field">
                    <label>Purpose</label>
                    <input name="purpose" maxlength="255" required placeholder="Operational purpose">
                </div>
                <div class="field full">
                    <label>Expense or Usage Account</label>
                    <select name="expense_account_id" required>
                        <option value="">Select the account to debit</option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= e($account['id']) ?>">
                                <?= e($account['code'] . ' - ' . $account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>The issuance debits this expense account and credits the inventory asset account of each item at the item's average cost, as one posted inventory adjustment of the cooperative ledger.</small>
                </div>
                <div class="field full">
                    <?= render_water_class_field(null, true, 'issuanceClassId') ?>
                </div>
                <div class="field full">
                    <label>Items</label>
                    <div id="lines"></div>
                    <button class="btn light" type="button" onclick="addLine()">Add Item</button>
                </div>
            </div>
            <div class="actions">
                <button class="btn" <?= (!$issuable_items || !$accounts || $error !== '') ? 'disabled' : '' ?>>Post Issuance</button>
            </div>
        </form>

        <h2>Recent Issuances</h2>
        <div class="table">
            <table>
                <thead>
                <tr>
                    <th>Issuance No.</th>
                    <th>Date</th>
                    <th>Recipient</th>
                    <th>Department</th>
                    <th>Purpose</th>
                    <th>Expense Account</th>
                    <th>Items</th>
                    <th class="mono">Cost</th>
                    <th>Journal</th>
                    <th>Status</th>
                    <th>Controlled Reversal</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($issuances as $issuance): ?>
                    <tr>
                        <td class="mono"><?= e($issuance['issuance_no']) ?></td>
                        <td><?= e($issuance['issuance_date']) ?></td>
                        <td><?= e($issuance['recipient']) ?></td>
                        <td><?= e($issuance['department']) ?></td>
                        <td><?= e($issuance['purpose']) ?></td>
                        <td><?= e(($issuance['expense_account_code'] ?? $issuance['expense_account_id'] ?? '') . ' - ' . ($issuance['expense_account_name'] ?? 'Unknown')) ?></td>
                        <td><?= e($issuance['item_count']) ?></td>
                        <td class="mono">₱<?= number_format((float)$issuance['total_cost'], 2) ?></td>
                        <td>
                            <div class="journal mono">
                                <?php $ledgerNo = $issuance['ledger_txn_no'] ?: ($issuance['journal_entry_id'] ?: 'Missing'); ?>
                                <?php if (!empty($issuance['txn_id'])): ?>
                                    <a href="<?= e(url('inv.adjust', ['id' => (int)$issuance['txn_id']])) ?>" title="<?= e($ledgerNo) ?>"><?= e(ui_compact_reference($ledgerNo, 18)) ?></a>
                                <?php else: ?>
                                    <span title="<?= e($ledgerNo) ?>"><?= e(ui_compact_reference($ledgerNo, 18)) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($issuance['ledger_voided'])): ?>
                                    <small>Adjustment voided</small>
                                <?php endif; ?>
                                <?php if (!empty($issuance['reversal_journal_entry_id'])): ?>
                                    <small title="<?= e($issuance['reversal_journal_entry_id']) ?>">Reversal: <?= e(ui_compact_reference($issuance['reversal_journal_entry_id'], 16)) ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="status <?= $issuance['status'] === 'REVERSED' ? 'rev' : '' ?>">
                                <?= e($issuance['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($issuance['status'] === 'POSTED'): ?>
                                <form class="reverse" action="../process/reverseItemIssuance.php" method="post" data-confirm="Reverse this issuance? The stock goes back into inventory and its inventory adjustment is voided.">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="issuance_id" value="<?= e($issuance['issuance_id']) ?>">
                                    <input name="reason" minlength="5" maxlength="255" required placeholder="Reversal reason">
                                    <button type="submit">Reverse</button>
                                </form>
                            <?php else: ?>
                                <small><?= e($issuance['reversal_reason']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$issuances): ?>
                    <tr>
                        <td colspan="11" style="text-align:center;padding:30px;color:#6b7280">No issuance records yet.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<template id="lineTemplate">
    <div class="line">
        <select name="code_id[]" required>
            <option value="">Select material</option>
            <?php foreach ($issuable_items as $item): ?>
                <option value="<?= e($item['code_id']) ?>" data-stock="<?= e(number_format((float)$item['current_qty'], 2, '.', '')) ?>" data-cost="<?= e(number_format((float)$item['average_unit_cost'], 2, '.', '')) ?>">
                    <?= e($item['code'] . ' - ' . ($item['description'] ?: $item['item_name']) . ' (' . $item['item_code'] . ') on hand ' . number_format((float)$item['current_qty'], 2) . ($item['unit'] ? ' ' . $item['unit'] : '') . ', avg cost ' . number_format((float)$item['average_unit_cost'], 2)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="number" name="quantity[]" min="0.01" step="0.01" required placeholder="Quantity" oninput="checkLineStock(this)">
        <button class="remove" type="button" onclick="this.parentElement.remove()" aria-label="Remove item row">×</button>
    </div>
</template>
<script>
function addLine() {
    const template = document.getElementById('lineTemplate');
    const lines = document.getElementById('lines');
    if (template && lines) {
        lines.append(template.content.cloneNode(true));
    }
}
function checkLineStock(input) {
    const line = input.closest('.line');
    const select = line ? line.querySelector('select') : null;
    if (!select || !select.value) { input.setCustomValidity(''); return; }
    const opt = select.options[select.selectedIndex];
    const stock = parseFloat(opt.getAttribute('data-stock')) || 0;
    const qty = parseFloat(input.value) || 0;
    input.setCustomValidity(qty > stock ? 'Only ' + stock.toFixed(2) + ' on hand.' : '');
}
document.getElementById('lines').addEventListener('change', function (ev) {
    if (ev.target && ev.target.tagName === 'SELECT') {
        const qty = ev.target.parentElement.querySelector('input[name="quantity[]"]');
        if (qty) checkLineStock(qty);
    }
});
addLine();
</script>
</body>
</html>
