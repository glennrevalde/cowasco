<?php
require_once __DIR__ . '/../database/config.php';

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';

$pdo = getDB();
requireProcessAccess($pdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/member_list.php');
/* BUSINESS_MUTATION_GUARD_END */

function member_sequence_back(string $message, string $type = 'error'): void
{
    $_SESSION[$type === 'success' ? 'success_msg' : 'error_msg'] = $message;

    $returnUrl = trim((string)($_POST['return_url'] ?? '../views/member_list.php'));

    if (
        $returnUrl === ''
        || preg_match('/[\r\n]/', $returnUrl)
        || stripos($returnUrl, 'member_list.php') === false
        || preg_match('#^(?:https?:)?//#i', $returnUrl)
    ) {
        $returnUrl = '../views/member_list.php';
    }

    header('Location: ' . $returnUrl);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        member_sequence_back('Invalid request method.');
    }

    $memberId = (int)($_POST['member_id'] ?? 0);
    $sequence = trim((string)($_POST['meter_sequence'] ?? ''));
    $sequence = $sequence !== '' ? $sequence : null;
    $modifiedBy = (string)($_SESSION['username'] ?? 'system');

    if ($memberId <= 0) {
        throw new Exception('Invalid member.');
    }

    if ($sequence !== null) {
        if (strlen($sequence) > 50) {
            throw new Exception('Sequence must not exceed 50 characters.');
        }

        if (!preg_match('~^[A-Za-z0-9 ._/-]+$~', $sequence)) {
            throw new Exception('Sequence may contain only letters, numbers, spaces, periods, underscores, slashes, and hyphens.');
        }
    }

    $memberStmt = $pdo->prepare("SELECT pkey, firstname, lastname FROM water_accounts WHERE pkey = ? LIMIT 1");
    $memberStmt->execute([$memberId]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        throw new Exception('Member not found.');
    }

    $stmt = $pdo->prepare("
        UPDATE water_accounts
        SET meter_sequence = ?,
            datelastmodified = NOW(),
            modifiedby = ?
        WHERE pkey = ?
    ");
    $stmt->execute([$sequence, $modifiedBy, $memberId]);

    $memberName = trim((string)($member['lastname'] ?? '') . ', ' . (string)($member['firstname'] ?? ''), ', ');
    $message = $sequence === null
        ? 'Sequence cleared for ' . ($memberName !== '' ? $memberName : ('member #' . $memberId)) . '.'
        : 'Sequence updated to ' . $sequence . ' for ' . ($memberName !== '' ? $memberName : ('member #' . $memberId)) . '.';

    member_sequence_back($message, 'success');
} catch (Throwable $e) {
    member_sequence_back('Update sequence failed: ' . $e->getMessage());
}
