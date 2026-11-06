<?php
/**
 * deleteDiscountedMembers.php
 * Process file — Remove a discount assignment from a member.
 * No HTML output. Redirects back to discounted_members.php on completion.
 */

require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/discounted_members.php');
/* BUSINESS_MUTATION_GUARD_END */


requireLogin();

// Accept POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/discounted_members.php');
    exit;
}

$pdo = getDB();

$dmId = (int)($_POST['dm_id'] ?? 0);

// --- Validation ---
if ($dmId <= 0) {
    flash('error', 'Invalid discount assignment record.');
    header('Location: ../views/discounted_members.php');
    exit;
}

// --- Verify the record exists and fetch labels for the success message ---
try {
    $existStmt = $pdo->prepare(
        "SELECT dm.dm_id,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                d.discount_type
         FROM discounted_members dm
         JOIN water_accounts   m ON m.pkey        = dm.member_id
         JOIN discounts d ON d.discount_id = dm.discount_id
         WHERE dm.dm_id = :id
         LIMIT 1"
    );
    $existStmt->bindValue(':id', $dmId, PDO::PARAM_INT);
    $existStmt->execute();
    $record = $existStmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        flash('error', 'Discount assignment record not found.');
header('Location: ../views/discounted_members.php');
        exit;
    }
} catch (PDOException $e) {
    flash('error', 'Database error. Please try again.');
header('Location: ../views/discounted_members.php');
    exit;
}

// --- Delete ---
try {
    $delStmt = $pdo->prepare("DELETE FROM discounted_members WHERE dm_id = :id");
    $delStmt->bindValue(':id', $dmId, PDO::PARAM_INT);
    $delStmt->execute();

    $memberName  = htmlspecialchars($record['member_name']);
    $discountLbl = htmlspecialchars($record['discount_type']);

    flash('success', '"' . $discountLbl . '" discount removed from ' . $memberName . '.');
} catch (PDOException $e) {
    flash('error', 'Failed to remove discount assignment. Please try again.');
}

header('Location: ../views/discounted_members.php');
exit;