<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/discounts.php');
/* BUSINESS_MUTATION_GUARD_END */

require_once '../common/billingHelpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB();
    $user_id = (int)($_SESSION['user_id'] ?? 1);
    $discount_id = filter_input(INPUT_POST, 'discount_id', FILTER_VALIDATE_INT);

    if ($discount_id) {
        try {
            $pdo->beginTransaction();

            // 1. Check if assigned to any members
            $check = $pdo->prepare("SELECT COUNT(*) FROM discounted_members WHERE discount_id = ?");
            $check->execute([$discount_id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("Cannot delete this discount because it is currently assigned to active members.");
            }

            // 2. Perform deletion
            $stmt = $pdo->prepare("DELETE FROM discounts WHERE discount_id = :id");
            $stmt->execute([':id' => $discount_id]);

            // Audit Log
            logAudit($pdo, $user_id, 'DELETE_DISCOUNT', 'discounts', (string)$discount_id, 'Deleted discount ID: ' . $discount_id);

            $pdo->commit();
            $_SESSION['success_msg'] = "Discount successfully deleted.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error_msg'] = $e->getMessage() ?: "Failed to delete discount due to database error.";
            error_log("Delete Discount Error: " . $e->getMessage());
        }
    } else {
        $_SESSION['error_msg'] = "Invalid Discount ID.";
    }

    header("Location: ../views/discounts.php");
    exit();
}