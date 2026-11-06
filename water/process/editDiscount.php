<?php
require_once __DIR__ . '/../database/config.php';
require_once '../database/config.php';
require_once __DIR__ . '/../common/generalDiscountFormHelpers.php';
require_once __DIR__ . '/../common/discountEnrollmentHelpers.php';
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
$discountId = filter_input(INPUT_POST, 'discount_id', FILTER_VALIDATE_INT);

try {
    if (!$discountId) {
        throw new InvalidArgumentException('Invalid discount classification.');
    }
    $data = gd_parse_discount_configuration($_POST);
    $data['discount_id'] = (int)$discountId;

    $pdo->beginTransaction();
    deh_assert_discount_configuration_compatible($pdo, (int)$discountId, $data);

    $sql = "UPDATE discounts SET
                discount_type = :discount_type,
                is_active = :is_active,
                water_discount_active = :water_discount_active,
                water_discount_method = :water_discount_method,
                water_discount_value = :water_discount_value,
                water_max_discount_amount = :water_max_discount_amount,
                water_max_discount_cubic = :water_max_discount_cubic,
                one_time_discount_active = :one_time_discount_active,
                one_time_discount_method = :one_time_discount_method,
                one_time_discount_value = :one_time_discount_value,
                one_time_max_discount_amount = :one_time_max_discount_amount,
                installment_discount_active = :installment_discount_active,
                installment_discount_method = :installment_discount_method,
                installment_discount_value = :installment_discount_value,
                installment_max_discount_amount = :installment_max_discount_amount,
                recurring_discount_active = :recurring_discount_active,
                recurring_discount_method = :recurring_discount_method,
                recurring_discount_value = :recurring_discount_value,
                recurring_max_discount_amount = :recurring_max_discount_amount,
                updated_at = NOW()
            WHERE discount_id = :discount_id";
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($data as $key => $value) {
        $params[':' . $key] = $value;
    }
    $stmt->execute($params);

    logAudit($pdo, $userId, 'EDIT_DISCOUNT', 'discounts', (string)$discountId, json_encode($data));
    $pdo->commit();
    $_SESSION['success_msg'] = 'General discount updated successfully.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error_msg'] = $e instanceof PDOException && (string)$e->getCode() === '23000'
        ? 'That discount classification already exists.'
        : $e->getMessage();
    error_log('Edit Discount Error: ' . $e->getMessage());
}

header('Location: ../views/discounts.php');
exit;
