<?php
/**
 * Address sub-groups: the list, add, change (name, order, active) and delete, as JSON.
 *
 * Used by Water Billing > Address Sub-groups and by the Manage button beside every Address sub-group box (the member
 * form's Water Defaults and grouping tabs, the water-service accounts). The list is the one of Member Types, Clusters &
 * Other Lists (Address sub-groups): a right on either page lets the user work on it.
 *
 *   GET  ?action=list                                         -> {success, rows, can}
 *   POST {action: save, id?, name, sort_order, is_active}     -> {success, message, rows, can}
 *   POST {action: delete, id}                                 -> {success, message, rows, can}
 * A POST carries the session's security token (csrf_token in the body or the X-CSRF-Token header).
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function asg_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    asg_out(['success' => false, 'error' => 'Your session has ended. Sign in again.'], 401);
}
if (Schema::pending()) {
    asg_out(['success' => false, 'error' => 'The database needs an update before work can continue: open the home page of the program for the file to run.'], 503);
}
$can = static function (string $action): bool {
    return Access::can('water.address_subgroups', $action) || Access::can('coop.lists', $action);
};
$rights = ['view' => $can('view'), 'create' => $can('create'), 'edit' => $can('edit'), 'delete' => $can('delete')];
if (!$rights['view']) {
    asg_out(['success' => false, 'error' => 'Access denied. Your role may not open the address sub-groups.'], 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
try {
    if ($method === 'GET') {
        asg_out(['success' => true, 'rows' => Members::subgroupRows(), 'can' => $rights]);
    }
    if ($method !== 'POST') {
        asg_out(['success' => false, 'error' => 'Method not allowed.'], 405);
    }
    $raw = file_get_contents('php://input');
    $in = json_decode((string)$raw, true);
    if (!is_array($in)) {
        $in = $_POST;
    }
    $token = (string)($in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!verifyCsrf($token)) {
        asg_out(['success' => false, 'error' => 'Your form session expired. Reload the page and try again.'], 403);
    }
    $action = (string)($in['action'] ?? '');
    $id = (int)($in['id'] ?? 0);
    $pageKey = (string)($in['page_key'] ?? 'water.address_subgroups');
    $pageKey = in_array($pageKey, ['water.address_subgroups', 'water.member_list', 'coop.member', 'coop.lists'], true) ? $pageKey : 'water.address_subgroups';
    if ($action === 'save') {
        if (!$rights[$id > 0 ? 'edit' : 'create']) {
            asg_out(['success' => false, 'error' => $id > 0 ? 'Your role may not change the address sub-groups.' : 'Your role may not add address sub-groups.'], 403);
        }
        $message = Members::saveSubgroup($id > 0 ? $id : null, (string)($in['name'] ?? ''), (int)($in['sort_order'] ?? 0),
            !array_key_exists('is_active', $in) || filter_var($in['is_active'], FILTER_VALIDATE_BOOLEAN), $pageKey);
    } elseif ($action === 'delete') {
        if (!$rights['delete']) {
            asg_out(['success' => false, 'error' => 'Your role may not delete address sub-groups.'], 403);
        }
        if ($id <= 0) {
            asg_out(['success' => false, 'error' => 'Choose the address sub-group to delete.'], 422);
        }
        $message = Members::deleteSubgroup($id, $pageKey);
    } else {
        asg_out(['success' => false, 'error' => 'Unknown action.'], 422);
    }
    asg_out(['success' => true, 'message' => $message, 'rows' => Members::subgroupRows(), 'can' => $rights]);
} catch (UserError $e) {
    asg_out(['success' => false, 'error' => $e->getMessage(), 'rows' => Members::subgroupRows(), 'can' => $rights], 422);
} catch (Throwable $e) {
    error_log('[addressSubgroups] ' . $e->getMessage());
    asg_out(['success' => false, 'error' => 'The address sub-groups could not be saved. Try again.'], 500);
}
