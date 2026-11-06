<?php
/**
 * Water Service Accounts: sets (or clears) the address sub-group of the checked accounts.
 *
 * The sub-groups are the list kept on Cooperative > Member Types, Clusters & Other Lists (Address sub-groups), the
 * same one the member form uses, so a member and the member's water connection can be grouped alike.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/member_list.php');
/* BUSINESS_MUTATION_GUARD_END */

function member_subgroup_back(string $message, string $type = 'error'): void
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;
    $returnUrl = trim((string)($_POST['return_url'] ?? '../views/member_list.php'));
    if ($returnUrl === '' || preg_match('/[\r\n]/', $returnUrl) || stripos($returnUrl, 'member_list.php') === false || preg_match('#^(?:https?:)?//#i', $returnUrl)) {
        $returnUrl = '../views/member_list.php';
    }
    header('Location: ' . $returnUrl);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        member_subgroup_back('Invalid request method.');
    }
    if (!verifyCsrf((string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('The form session expired. Reload the page and try again.');
    }
    if (!Members::waterSubgroupReady()) {
        throw new RuntimeException('Run the upgrade script first: the water-service accounts have no address sub-group yet.');
    }
    $ids = $_POST['member_ids'] ?? [];
    $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0))) : [];
    if (!$ids) {
        throw new RuntimeException('Check at least one account.');
    }
    $value = trim((string)($_POST['bulk_address_subgroup'] ?? ''));
    if ($value === '') {
        throw new RuntimeException('Choose the address sub-group to set.');
    }
    if ($value === '__none__') {
        $value = null;
    } elseif (!in_array($value, Members::listNames('address_subgroup'), true)) {
        throw new RuntimeException('"' . $value . '" is not an active address sub-group. Add it on Member Types, Clusters & Other Lists first.');
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE water_accounts SET address_subgroup = ?, datelastmodified = NOW(), modifiedby = ? WHERE pkey IN ($place)");
    $stmt->execute(array_merge([$value, (string)($_SESSION['username'] ?? 'system')], $ids));
    Audit::log('edit', 'water', 'water_accounts', null, 'Address sub-group', ['page_key' => 'water.member_list',
        'reason' => ($value === null ? 'Cleared the address sub-group' : 'Set the address sub-group "' . $value . '"') . ' on ' . count($ids) . ' account(s)', 'after' => ['ids' => $ids, 'address_subgroup' => $value]]);
    member_subgroup_back(($value === null ? 'Address sub-group cleared on ' : 'Address sub-group "' . $value . '" set on ') . $stmt->rowCount() . ' account(s).', 'success');
} catch (Throwable $e) {
    member_subgroup_back('Address sub-group not changed: ' . $e->getMessage());
}
