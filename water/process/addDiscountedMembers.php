<?php
/**
 * Assign one or more discount classifications to a member.
 *
 * A selected classification is always preserved. For members aged 60 or
 * older, the Senior Citizen classification is also ensured as an additional
 * assignment. Billing rules decide the actual benefit for each module.
 */

require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/discounted_members.php');
/* BUSINESS_MUTATION_GUARD_END */
unset($__processGuardPdo);
require_once '../common/billingHelpers.php';
require_once '../common/discountEnrollmentHelpers.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/discounted_members.php');
    exit;
}

$pdo = getDB();
$memberId = (int)($_POST['member_id'] ?? 0);
$selectedDiscountId = (int)($_POST['discount_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 1);

if ($memberId <= 0 || $selectedDiscountId <= 0) {
    flash('error', 'Please select a valid member and discount classification.');
    header('Location: ../views/discounted_members.php');
    exit;
}

try {
    $memberStmt = $pdo->prepare(
        "SELECT pkey, lastname, firstname, dateofbirth
         FROM water_accounts
         WHERE pkey = ? AND status = 'A'
         LIMIT 1"
    );
    $memberStmt->execute([$memberId]);
    $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        throw new RuntimeException('The selected active member was not found.');
    }

    $discountStmt = $pdo->prepare(
        "SELECT discount_id, discount_type
         FROM discounts
         WHERE discount_id = ?
         LIMIT 1"
    );
    $discountStmt->execute([$selectedDiscountId]);
    $selectedDiscount = $discountStmt->fetch(PDO::FETCH_ASSOC);
    if (!$selectedDiscount) {
        throw new RuntimeException('The selected discount classification was not found.');
    }

    $assignmentIds = [$selectedDiscountId];
    $seniorDiscount = null;
    $age = null;

    if (!empty($member['dateofbirth'])) {
        $birthDate = new DateTimeImmutable((string)$member['dateofbirth']);
        $today = new DateTimeImmutable('today');
        $age = (int)$birthDate->diff($today)->y;

        if ($age >= 60) {
            $seniorStmt = $pdo->query(
                "SELECT discount_id, discount_type
                 FROM discounts
                 WHERE LOWER(discount_type) LIKE '%senior%'
                 ORDER BY discount_id ASC
                 LIMIT 1"
            );
            $seniorDiscount = $seniorStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($seniorDiscount) {
                $assignmentIds[] = (int)$seniorDiscount['discount_id'];
            }
        }
    }

    $assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));

    $pdo->beginTransaction();
    deh_assert_member_assignments($pdo, $memberId, $assignmentIds, null, true);

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO discounted_members (member_id, discount_id, created_at)
         VALUES (?, ?, NOW())"
    );

    $insertedIds = [];
    foreach ($assignmentIds as $discountId) {
        $insertStmt->execute([$memberId, $discountId]);
        if ($insertStmt->rowCount() === 1) {
            $insertedIds[] = $discountId;
        }
    }

    if (!$insertedIds) {
        $pdo->rollBack();
        flash('error', 'The member already has the selected discount classification.');
        header('Location: ../views/discounted_members.php');
        exit;
    }

    logAudit($pdo, $userId, 'ASSIGN_DISCOUNT', 'discounted_members', (string)$memberId, json_encode([
        'member_id' => $memberId,
        'selected_discount_id' => $selectedDiscountId,
        'inserted_discount_ids' => $insertedIds,
        'senior_classification_ensured' => $seniorDiscount !== null && in_array((int)$seniorDiscount['discount_id'], $assignmentIds, true),
    ]));

    $pdo->commit();

    $memberName = trim((string)$member['lastname'] . ', ' . (string)$member['firstname'], ', ');
    $selectedInserted = in_array($selectedDiscountId, $insertedIds, true);
    $seniorInserted = $seniorDiscount
        && (int)$seniorDiscount['discount_id'] !== $selectedDiscountId
        && in_array((int)$seniorDiscount['discount_id'], $insertedIds, true);

    $message = $selectedInserted
        ? '“' . $selectedDiscount['discount_type'] . '” was assigned to ' . $memberName . '.'
        : $memberName . ' already had the selected classification.';
    if ($seniorInserted) {
        $message .= ' The Senior Citizen classification was also assigned automatically.';
    }
    flash('success', $message);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Add Discounted Member Error: ' . $e->getMessage());
    flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Failed to assign the discount classification.');
}

header('Location: ../views/discounted_members.php');
exit;
