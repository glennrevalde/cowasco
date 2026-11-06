<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/generalDiscountFormHelpers.php';
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../views/discounts.php');
    exit;
}

$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 1);

try {
    $data = gd_parse_discount_configuration($_POST);

    $sql = "INSERT INTO discounts (
                discount_type, is_active,
                water_discount_active, water_discount_method, water_discount_value,
                water_max_discount_amount, water_max_discount_cubic,
                one_time_discount_active, one_time_discount_method, one_time_discount_value, one_time_max_discount_amount,
                installment_discount_active, installment_discount_method, installment_discount_value, installment_max_discount_amount,
                recurring_discount_active, recurring_discount_method, recurring_discount_value, recurring_max_discount_amount,
                created_at, updated_at
            ) VALUES (
                :discount_type, :is_active,
                :water_discount_active, :water_discount_method, :water_discount_value,
                :water_max_discount_amount, :water_max_discount_cubic,
                :one_time_discount_active, :one_time_discount_method, :one_time_discount_value, :one_time_max_discount_amount,
                :installment_discount_active, :installment_discount_method, :installment_discount_value, :installment_max_discount_amount,
                :recurring_discount_active, :recurring_discount_method, :recurring_discount_value, :recurring_max_discount_amount,
                NOW(), NOW()
            )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_combine(
        array_map(static fn($key) => ':' . $key, array_keys($data)),
        array_values($data)
    ));

    $discountId = (int)$pdo->lastInsertId();
    logAudit($pdo, $userId, 'CREATE_DISCOUNT', 'discounts', (string)$discountId, json_encode($data));
    $_SESSION['success_msg'] = "General discount '{$data['discount_type']}' added successfully.";
} catch (Throwable $e) {
    $_SESSION['error_msg'] = $e instanceof PDOException && (string)$e->getCode() === '23000'
        ? 'That discount classification already exists.'
        : $e->getMessage();
    error_log('Add Discount Error: ' . $e->getMessage());
}

header('Location: ../views/discounts.php');
exit;
