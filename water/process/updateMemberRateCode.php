<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';
require_once __DIR__ . '/accessControlHelpers.php';
$pdo = getDB();
requireProcessAccess($pdo);
require_once __DIR__ . '/../common/waterPipeSizeHelpers.php';
require_once __DIR__ . '/../common/waterRateBillingHelpers.php';


function parse_rate_pipe_assignment(string $value): array
{
    $value = trim($value);
    if (!preg_match('/^(\d+):(\d+)$/', $value, $matches)) {
        throw new InvalidArgumentException('Please choose a valid Rate Code + Pipe Size.');
    }

    $rcId = (int)$matches[1];
    $pipeSizeId = (int)$matches[2];
    if ($rcId <= 0 || $pipeSizeId <= 0) {
        throw new InvalidArgumentException('Please choose a valid Rate Code + Pipe Size.');
    }

    return [$rcId, $pipeSizeId];
}

function go_back_rate(string $message, string $type = 'error'): void
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    $returnUrl = (string)($_POST['return_url'] ?? '../views/member_list.php');
    if (stripos($returnUrl, 'member_list.php') === false) { $returnUrl = '../views/member_list.php'; }
    header('Location: ' . $returnUrl); exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { go_back_rate('Invalid request method.'); }
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) { throw new RuntimeException('The form session expired. Reload the page and try again.'); }
    $memberId = (int)($_POST['member_id'] ?? 0);
    $assignment = trim((string)($_POST['rate_pipe_assignment'] ?? ''));
    if ($assignment !== '') {
        [$rcId, $pipeSizeId] = parse_rate_pipe_assignment($assignment);
    } else {
        // Backward compatibility for an older open page or bookmarked form.
        $rcId = (int)($_POST['rc_id'] ?? 0);
        $pipeSizeId = (int)($_POST['pipe_size_id'] ?? 0);
    }
    $modifiedBy = $_SESSION['username'] ?? 'system';
    if ($memberId <= 0 || $rcId <= 0 || $pipeSizeId <= 0) { throw new Exception('Please select a valid member, rate code, and pipe size.'); }

    $pdo->beginTransaction();
    $rate = $pdo->prepare('SELECT rc_code, rc_name FROM rate_code WHERE rc_id = ? FOR UPDATE');
    $rate->execute([$rcId]);
    $rateRow = $rate->fetch(PDO::FETCH_ASSOC);
    if (!$rateRow) { throw new Exception('Selected rate code does not exist.'); }
    $pipe = water_pipe_size_require_active($pdo, $pipeSizeId, true);
    $member = $pdo->prepare('SELECT pkey FROM water_accounts WHERE pkey = ? FOR UPDATE');
    $member->execute([$memberId]);
    if (!$member->fetchColumn()) { throw new Exception('Member not found.'); }
    water_rate_validate_tiers(water_rate_get_rates_for_configuration($pdo, $rcId, $pipeSizeId, true), true, false);

    $stmt = $pdo->prepare('UPDATE water_accounts SET rc_id = ?, pipe_size_id = ?, datelastmodified = NOW(), modifiedby = ? WHERE pkey = ?');
    $stmt->execute([$rcId, $pipeSizeId, $modifiedBy, $memberId]);
    $pdo->commit();
    go_back_rate('Water classification updated to ' . $rateRow['rc_code'] . ' - ' . $rateRow['rc_name'] . ' / ' . water_pipe_size_format($pipe) . '.', 'success');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    go_back_rate('Update water classification failed: ' . $e->getMessage());
}
