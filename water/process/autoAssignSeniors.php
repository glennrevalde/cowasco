<?php
/**
 * autoAssignSeniors.php
 * Path: ../process/autoAssignSeniors.php
 * Process file — Batch assigns the Senior Citizen discount to eligible members.
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


// If you have a login check, uncomment it:
// requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/discount_management.php');
    exit;
}

$pdo = getDB();

try {
    $srStmt = $pdo->prepare("
        SELECT discount_id, discount_type
        FROM discounts
        WHERE LOWER(REPLACE(discount_type, ' ', '')) LIKE '%senior%'
        ORDER BY discount_id ASC
        LIMIT 1
    ");
    $srStmt->execute();
    $seniorDiscount = $srStmt->fetch(PDO::FETCH_ASSOC);

    if (!$seniorDiscount) {
        throw new RuntimeException('Senior Citizen discount classification not found. Create a classification with "Senior" in its name first.');
    }

    $discountId = (int)$seniorDiscount['discount_id'];
    $findStmt = $pdo->prepare("
        SELECT m.pkey
        FROM water_accounts m
        WHERE m.status = 'A'
          AND m.dateofbirth IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, m.dateofbirth, CURDATE()) >= 60
          AND NOT EXISTS (
              SELECT 1
              FROM discounted_members dm
              WHERE dm.member_id = m.pkey
                AND dm.discount_id = ?
          )
        ORDER BY m.pkey ASC
    ");
    $findStmt->execute([$discountId]);
    $eligibleMembers = array_map('intval', $findStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!$eligibleMembers) {
        $_SESSION['flash']['info'] = 'No new eligible Senior Citizens found. All active members aged 60+ already have the Senior Citizen classification.';
        header('Location: ../views/discount_management.php');
        exit;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO discounted_members (member_id, discount_id, created_at)
        VALUES (:mid, :did, NOW())
    ");

    $pdo->beginTransaction();
    $count = 0;
    $skipped = 0;
    foreach ($eligibleMembers as $memberId) {
        try {
            deh_assert_member_assignments($pdo, $memberId, [$discountId], null, true);
            $insertStmt->execute([':mid' => $memberId, ':did' => $discountId]);
            $count++;
        } catch (RuntimeException $e) {
            // Keep existing valid privileges intact rather than creating a
            // second active monetary/free-cubic privilege in the same slot.
            $skipped++;
        }
    }
    $pdo->commit();

    $message = "Auto-assigned the Senior Citizen classification to {$count} eligible member(s).";
    if ($skipped > 0) {
        $message .= " {$skipped} member(s) were skipped because the assignment would conflict with an existing active privilege.";
    }
    $_SESSION['flash']['success'] = $message;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Auto Assign Seniors Error: ' . $e->getMessage());
    $_SESSION['flash']['error'] = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Database error during auto-assignment. Please try again.';
}

header('Location: ../views/discount_management.php');
exit;