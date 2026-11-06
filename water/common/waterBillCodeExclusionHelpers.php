<?php
/**
 * common/waterBillCodeExclusionHelpers.php
 *
 * Purpose:
 * The single configured Water Billing Accounting Bill Code is reserved ONLY for:
 * - Water Meter Configurations
 * - Generate SOA / Post Billing for water readings
 *
 * It must NOT be selectable/usable in:
 * - Installment Billing
 * - Recurring Billing
 * - One-Time Billing
 * - Other non-water bill modules
 */

require_once __DIR__ . '/waterRateBillingHelpers.php';

if (!function_exists('water_billing_reserved_bill_code_id')) {
    function water_billing_reserved_bill_code_id(PDO $pdo): int
    {
        try {
            $id = water_billing_get_setting($pdo, 'water_billing_bill_code_id');
            return $id ? (int)$id : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('water_billing_reserved_bill_code')) {
    function water_billing_reserved_bill_code(PDO $pdo): ?array
    {
        $id = water_billing_reserved_bill_code_id($pdo);

        if ($id <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT code_id, code, description, type
                FROM bill_codes
                WHERE code_id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('is_water_billing_reserved_bill_code')) {
    function is_water_billing_reserved_bill_code(PDO $pdo, int $billCodeId): bool
    {
        $reservedId = water_billing_reserved_bill_code_id($pdo);
        return $reservedId > 0 && $billCodeId === $reservedId;
    }
}

if (!function_exists('assert_not_water_billing_reserved_bill_code')) {
    function assert_not_water_billing_reserved_bill_code(PDO $pdo, int $billCodeId, string $moduleName = 'this module'): void
    {
        if (!is_water_billing_reserved_bill_code($pdo, $billCodeId)) {
            return;
        }

        $reserved = water_billing_reserved_bill_code($pdo);
        $codeName = $reserved && !empty($reserved['code'])
            ? $reserved['code']
            : 'the configured Water Billing bill code';

        throw new Exception(
            "The bill code '{$codeName}' is reserved for Water Billing / Post Billing only and cannot be used in {$moduleName}."
        );
    }
}

if (!function_exists('water_billing_excluded_bill_code_where')) {
    function water_billing_excluded_bill_code_where(PDO $pdo, string $alias = 'bc'): string
    {
        $reservedId = water_billing_reserved_bill_code_id($pdo);

        if ($reservedId <= 0) {
            return '';
        }

        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        if ($alias === '') {
            $alias = 'bc';
        }

        return " AND {$alias}.code_id <> " . (int)$reservedId . " ";
    }
}

if (!function_exists('water_billing_reserved_notice')) {
    function water_billing_reserved_notice(PDO $pdo): string
    {
        $reserved = water_billing_reserved_bill_code($pdo);

        if (!$reserved) {
            return '';
        }

        return "Water Billing bill code '{$reserved['code']}' is reserved and hidden from this module.";
    }
}
