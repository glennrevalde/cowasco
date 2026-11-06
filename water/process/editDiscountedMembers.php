<?php
/**
 * editDiscountedMembers.php
 * Process file — Update a member's discount assignment.
 */

require_once '../database/config.php';
require_once '../common/discountEnrollmentHelpers.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/discount_management.php');
/* BUSINESS_MUTATION_GUARD_END */


requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/discount_management.php');
    exit;
}

$pdo = getDB();

$dmId       = (int)($_POST['dm_id'] ?? 0);
$discountId = (int)($_POST['discount_id'] ?? 0);

if ($dmId <= 0 || $discountId <= 0) {
    flash('error', 'Invalid assignment or discount selection.');
    header('Location: ../views/discount_management.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $currStmt = $pdo->prepare("SELECT member_id FROM discounted_members WHERE dm_id = :dmid LIMIT 1 FOR UPDATE");
    $currStmt->execute([':dmid' => $dmId]);
    $current = $currStmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        throw new RuntimeException('Discount assignment not found.');
    }

    $memberId = (int)$current['member_id'];
    deh_assert_member_assignments($pdo, $memberId, [$discountId], $dmId, true);

    $updStmt = $pdo->prepare("UPDATE discounted_members SET discount_id = :did WHERE dm_id = :dmid");
    $updStmt->execute([
        ':did' => $discountId,
        ':dmid' => $dmId,
    ]);

    $pdo->commit();
    flash('success', 'Member discount assignment updated successfully.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Edit Discounted Member Error: ' . $e->getMessage());
    flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Database error while updating assignment.');
}

header('Location: ../views/discount_management.php');
exit;