<?php
require_once __DIR__ . '/../database/config.php';

// Corrected: Bring in the database configuration
require_once '../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
/* BUSINESS_MUTATION_GUARD_START */
require_once __DIR__ . '/../common/businessRequestGuard.php';
business_require_mutation(getDB(), '../views/bill_codes.php');
/* BUSINESS_MUTATION_GUARD_END */


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Corrected: Initialize the database connection!
    $pdo = getDB();
    
    $code_id = filter_input(INPUT_POST, 'code_id', FILTER_VALIDATE_INT);
    
    if ($code_id) {
        try {
            $lookup = $pdo->prepare('SELECT code FROM bill_codes WHERE code_id = ? LIMIT 1');
            $lookup->execute([$code_id]);
            $codeName = strtoupper(trim((string)$lookup->fetchColumn()));
            if ($codeName === 'WATER BILL') {
                throw new RuntimeException('WATER BILL is required by Water Billing and cannot be deleted.');
            }

            $stmt = $pdo->prepare("DELETE FROM bill_codes WHERE code_id = :code_id");
            $stmt->execute([':code_id' => $code_id]);
            $_SESSION['success_msg'] = "Bill code successfully deleted.";
            
        } catch (PDOException $e) {
            // PDOException extends RuntimeException, so the database branch must
            // come FIRST: otherwise every database failure lands in the generic
            // handler and its raw driver message reaches the operator.
            // Error 1451 (SQLSTATE 23000) means the code is referenced by billing
            // records or bill-code configuration (foreign key restriction).
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            if ($driverCode === 1451 || (string)$e->getCode() === '23000') {
                $_SESSION['error_msg'] = 'This bill code cannot be deleted because it is still linked to billing records, recurring plans, or accounting configuration. Deactivate or reassign those records first.';
            } else {
                $_SESSION['error_msg'] = 'The bill code could not be deleted because of a database error. Nothing was changed.';
            }
            error_log('Delete Bill Code Error [' . $driverCode . ']: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            // Business rule violations raised above carry a safe, stable message.
            $_SESSION['error_msg'] = $e->getMessage();
        } catch (Throwable $e) {
            $_SESSION['error_msg'] = 'The bill code could not be deleted because of an unexpected error. Nothing was changed.';
            error_log('Delete Bill Code Error: ' . $e->getMessage());
        }
    } else {
        $_SESSION['error_msg'] = "Invalid Bill Code ID.";
    }
    
    header("Location: ../views/bill_codes.php");
    exit();
}