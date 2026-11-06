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

function gdv_value(array $row, string $key, $default = '')
{
    return array_key_exists($key, $row) ? $row[$key] : $default;
}

function gdv_rule_summary(array $row, string $module): string
{
    if ((int)($row['is_active'] ?? 0) !== 1 || (int)($row[$module . '_discount_active'] ?? 0) !== 1) {
        return 'Not configured';
    }

    $method = (string)($row[$module . '_discount_method'] ?? 'none');
    $value = (float)($row[$module . '_discount_value'] ?? 0);
    if ($method === 'percent') {
        return number_format($value, 2) . '%, max ₱' . number_format((float)($row[$module . '_max_discount_amount'] ?? 0), 2);
    }
    if ($method === 'fixed') {
        return '₱' . number_format($value, 2) . ', max ₱' . number_format((float)($row[$module . '_max_discount_amount'] ?? 0), 2);
    }
    if ($method === 'free_cubic' && $module === 'water') {
        return number_format($value, 0) . ' m³ free';
    }
    return 'Not configured';
}

$discounts = [];
$editing = [];
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

try {
    $discounts = $pdo->query("SELECT * FROM discounts ORDER BY discount_type ASC")->fetchAll(PDO::FETCH_ASSOC);
    if ($editId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM discounts WHERE discount_id = ? LIMIT 1");
        $stmt->execute([$editId]);
        $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$editing) {
            $_SESSION['error_msg'] = 'The selected discount classification was not found.';
        }
    }
} catch (Throwable $e) {
    $_SESSION['error_msg'] = 'General Discount configuration is not ready. Run the included direct-privileges SQL migration first. Details: ' . $e->getMessage();
}

$isEditing = !empty($editing);
$formData = $isEditing ? $editing : [
    'is_active' => 1,
    'water_discount_active' => 0,
    'water_discount_method' => 'free_cubic',
    'water_discount_value' => '',
    'water_max_discount_amount' => '',
    'water_max_discount_cubic' => '',
    'one_time_discount_active' => 0,
    'one_time_discount_method' => 'percent',
    'one_time_discount_value' => '',
    'one_time_max_discount_amount' => '',
    'installment_discount_active' => 0,
    'installment_discount_method' => 'percent',
    'installment_discount_value' => '',
    'installment_max_discount_amount' => '',
    'recurring_discount_active' => 0,
    'recurring_discount_method' => 'percent',
    'recurring_discount_value' => '',
    'recurring_max_discount_amount' => '',
];

$moduleDefinitions = [
    'water' => ['label' => 'Water Billing', 'help' => 'Free Cubic Meter applies regardless of consumption. Percentage and Fixed Amount apply only at 30 m³ or below.', 'free' => true],
    'one_time' => ['label' => 'One-Time Billing', 'help' => 'Applies to discountable one-time bill codes.', 'free' => false],
    'installment' => ['label' => 'Installment Billing', 'help' => 'Applies to discountable installment bill codes.', 'free' => false],
    'recurring' => ['label' => 'Recurring Billing', 'help' => 'Applies to discountable recurring bill codes.', 'free' => false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../common/fonts.css">
<title>General Discounts - <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}:root{--white:#fff;--off:#f8f9fb;--border:#e5e7eb;--text:#111827;--muted:#6b7280;--accent:#111827;--blue:#2563eb;--green:#047857;--red:#b91c1c;--radius:10px}body{font-family:'Sora',sans-serif;background:var(--off);color:var(--text);min-height:100vh}.page{max-width:1450px;margin:0 auto;padding:30px 30px 60px}.header{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;flex-wrap:wrap;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)}h1{font-size:1.55rem;letter-spacing:-.03em}.subtitle{font-size:.86rem;color:var(--muted);margin-top:5px;line-height:1.55}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 15px;border-radius:8px;border:1px solid var(--border);font:600 .82rem 'Sora',sans-serif;text-decoration:none;cursor:pointer;background:#fff;color:var(--text)}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.danger{color:var(--red);border-color:#fecaca}.alert{padding:12px 14px;border-radius:8px;margin-bottom:16px;font-size:.85rem;font-weight:600}.alert.success{background:#ecfdf5;color:var(--green);border:1px solid #a7f3d0}.alert.error{background:#fef2f2;color:var(--red);border:1px solid #fecaca}.notice{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:9px;padding:13px 15px;font-size:.82rem;line-height:1.6;margin-bottom:18px}.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:22px;margin-bottom:20px}.card-title{font-size:1.05rem;margin-bottom:5px}.card-subtitle{font-size:.78rem;color:var(--muted);line-height:1.5}.top-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-top:18px}.field{display:flex;flex-direction:column;gap:6px}.field label,.module-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}input[type=text],input[type=number],select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:#fff;font:400 .84rem 'Sora',sans-serif;color:var(--text)}input:focus,select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(17,24,39,.08)}.switch-line{display:flex;align-items:center;gap:9px;min-height:41px}.switch-line input{width:17px;height:17px}.switch-line span{font-size:.84rem;font-weight:600}.modules-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:18px}.module-card{border:1px solid var(--border);border-radius:9px;padding:16px;background:#fafafa}.module-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:13px}.module-head h3{font-size:.92rem}.module-help{font-size:.72rem;color:var(--muted);line-height:1.45;margin-top:4px}.module-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.module-fields .field label{font-size:.66rem}.module-card.disabled{opacity:.66}.hidden{display:none!important}.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;padding-top:17px;border-top:1px solid var(--border)}.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:9px;margin-top:16px}table{width:100%;border-collapse:collapse;min-width:1120px;font-size:.8rem}th,td{padding:12px 13px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}th{background:#f9fafb;color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:.05em}tr:last-child td{border-bottom:0}.classification{font-weight:700}.rule{font-family:'JetBrains Mono',monospace;font-size:.72rem;line-height:1.45}.muted{color:var(--muted)}.badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:.66rem;font-weight:700}.badge.on{background:#ecfdf5;color:var(--green)}.badge.off{background:#f3f4f6;color:var(--muted)}.actions{display:flex;gap:7px;justify-content:flex-end}.actions form{margin:0}@media(max-width:950px){.modules-grid,.top-grid{grid-template-columns:1fr}.module-fields{grid-template-columns:1fr 1fr}}@media(max-width:620px){.page{padding:20px 14px 40px}.module-fields{grid-template-columns:1fr}.form-actions,.actions{flex-direction:column}.btn{width:100%}}
</style>
</head>
<body>
<?php require_once '../common/navbar.php'; ?>
<div class="main-content"><main class="page">
<div class="header"><div><a href="discount_management.php" style="font-size:.78rem;color:var(--muted);text-decoration:none">← Back to Discount Management</a><h1 style="margin-top:8px">General Discounts</h1><p class="subtitle">Create the classification and define all of its Water, One-Time, Installment, and Recurring privileges in the same discounts record.</p></div><a href="discounted_members.php" class="btn">Assign Discounted Members</a></div>

<?php if (isset($_SESSION['success_msg'])): ?><div class="alert success"><?= e($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div><?php endif; ?>
<?php if (isset($_SESSION['error_msg'])): ?><div class="alert error"><?= e($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div><?php endif; ?>

<div class="notice">Manage all member discounts here. Free Cubic Meter applies at any consumption. Percentage and Fixed Amount apply only at <strong>30 m³ or less</strong>.</div>

<section class="card" id="discount-form">
<h2 class="card-title"><?= $isEditing ? 'Edit General Discount' : 'Add General Discount' ?></h2>
<p class="card-subtitle">Activate only the billing modules that this classification should affect. Percentage and Fixed Amount require a maximum monetary amount. Free Cubic Meter uses only the entered free m³ value.</p>
<form action="<?= $isEditing ? '../process/editDiscount.php' : '../process/addDiscount.php' ?>" method="POST" id="generalDiscountForm">
<?php if ($isEditing): ?><input type="hidden" name="discount_id" value="<?= (int)$editing['discount_id'] ?>"><?php endif; ?>
<div class="top-grid">
<div class="field"><label>Discount Classification</label><input type="text" name="discount_type" maxlength="191" value="<?= e(gdv_value($formData, 'discount_type')) ?>" placeholder="e.g. Officer, Employee, Senior Citizen, PWD" required></div>
<div class="field"><label>Classification Status</label><label class="switch-line"><input type="checkbox" name="is_active" value="1" <?= (int)gdv_value($formData, 'is_active', 1) === 1 ? 'checked' : '' ?>><span>Active and available for billing</span></label></div>
</div>

<div class="modules-grid">
<?php foreach ($moduleDefinitions as $module => $definition):
    $activeKey = $module . '_discount_active';
    $methodKey = $module . '_discount_method';
    $valueKey = $module . '_discount_value';
    $maxAmountKey = $module . '_max_discount_amount';
    $active = (int)gdv_value($formData, $activeKey, 0) === 1;
    $method = (string)gdv_value($formData, $methodKey, $definition['free'] ? 'free_cubic' : 'percent');
?>
<div class="module-card" data-module="<?= e($module) ?>" data-allows-free="<?= $definition['free'] ? '1' : '0' ?>">
<div class="module-head"><div><h3><?= e($definition['label']) ?></h3><div class="module-help"><?= e($definition['help']) ?></div></div><label class="switch-line"><input class="module-active" type="checkbox" name="<?= e($activeKey) ?>" value="1" <?= $active ? 'checked' : '' ?>><span>Use</span></label></div>
<div class="module-fields">
<div class="field"><label>Method</label><select class="module-method" name="<?= e($methodKey) ?>"><option value="percent" <?= $method === 'percent' ? 'selected' : '' ?>>Percentage</option><option value="fixed" <?= $method === 'fixed' ? 'selected' : '' ?>>Fixed Amount</option><?php if ($definition['free']): ?><option value="free_cubic" <?= $method === 'free_cubic' ? 'selected' : '' ?>>Free Cubic Meter</option><?php endif; ?><option value="none" <?= $method === 'none' ? 'selected' : '' ?>>No Discount</option></select></div>
<div class="field value-field"><label class="value-label">Value</label><input class="module-value" type="number" name="<?= e($valueKey) ?>" min="0" step="0.01" value="<?= e(gdv_value($formData, $valueKey)) ?>"></div>
<div class="field max-amount-field"><label>Maximum Amount (₱)</label><input class="module-max-amount" type="number" name="<?= e($maxAmountKey) ?>" min="0" step="0.01" value="<?= e(gdv_value($formData, $maxAmountKey)) ?>"></div>
</div>
</div>
<?php endforeach; ?>
</div>
<div class="form-actions"><?php if ($isEditing): ?><a href="discounts.php" class="btn">Cancel Editing</a><?php endif; ?><button class="btn primary" type="submit"><?= $isEditing ? 'Save General Discount' : 'Add General Discount' ?></button></div>
</form>
</section>

<section class="card"><h2 class="card-title">Configured General Discounts</h2><p class="card-subtitle">These values are read directly from the <code>discounts</code> table during billing.</p><div class="table-wrap"><table><thead><tr><th>Classification</th><th>Water Billing</th><th>One-Time</th><th>Installment</th><th>Recurring</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody>
<?php if (!$discounts): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--muted)">No General Discounts have been configured.</td></tr><?php else: foreach ($discounts as $discount): ?>
<tr><td><div class="classification"><?= e($discount['discount_type']) ?></div><div class="muted" style="font-size:.68rem;margin-top:4px">ID <?= (int)$discount['discount_id'] ?></div></td><td class="rule"><?= e(gdv_rule_summary($discount, 'water')) ?></td><td class="rule"><?= e(gdv_rule_summary($discount, 'one_time')) ?></td><td class="rule"><?= e(gdv_rule_summary($discount, 'installment')) ?></td><td class="rule"><?= e(gdv_rule_summary($discount, 'recurring')) ?></td><td><span class="badge <?= (int)$discount['is_active'] === 1 ? 'on' : 'off' ?>"><?= (int)$discount['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td><div class="actions"><a class="btn" href="discounts.php?edit=<?= (int)$discount['discount_id'] ?>#discount-form">Edit</a><form action="../process/deleteDiscount.php" method="POST" data-confirm="Delete this General Discount? It cannot be deleted while assigned to members."><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="discount_id" value="<?= (int)$discount['discount_id'] ?>"><button class="btn danger" type="submit">Delete</button></form></div></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div></section>
</main></div>
<script>
document.querySelectorAll('.module-card').forEach(card => {
    const active = card.querySelector('.module-active');
    const method = card.querySelector('.module-method');
    const value = card.querySelector('.module-value');
    const maxAmountField = card.querySelector('.max-amount-field');
    const maxAmount = card.querySelector('.module-max-amount');
    const valueLabel = card.querySelector('.value-label');
    const allowsFree = card.dataset.allowsFree === '1';

    function refresh() {
        const enabled = active.checked;
        const selected = method.value;
        const isFree = allowsFree && selected === 'free_cubic';
        const isNone = selected === 'none';
        card.classList.toggle('disabled', !enabled);
        method.disabled = !enabled;
        value.disabled = !enabled || isNone;
        value.required = enabled && !isNone;
        value.step = isFree ? '1' : '0.01';
        value.min = isNone ? '0' : (isFree ? '1' : '0.01');
        valueLabel.textContent = isFree ? 'Free Cubic Meter Value' : (selected === 'percent' ? 'Percentage Value' : 'Fixed Discount Amount (₱)');
        maxAmountField.classList.toggle('hidden', !enabled || isFree || isNone);
        maxAmount.disabled = !enabled || isFree || isNone;
        maxAmount.required = enabled && !isFree && !isNone;
    }
    active.addEventListener('change', refresh);
    method.addEventListener('change', refresh);
    refresh();
});
</script>
</body>
</html>
