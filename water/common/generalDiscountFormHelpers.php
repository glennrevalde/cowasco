<?php
/**
 * Validation and persistence helpers for the General Discounts table.
 * All privilege values are stored directly in `discounts`.
 */

if (!function_exists('gd_module_definitions')) {
    function gd_module_definitions(): array
    {
        return [
            'water' => ['label' => 'Water Billing', 'allows_free_cubic' => true],
            'one_time' => ['label' => 'One-Time Billing', 'allows_free_cubic' => false],
            'installment' => ['label' => 'Installment Billing', 'allows_free_cubic' => false],
            'recurring' => ['label' => 'Recurring Billing', 'allows_free_cubic' => false],
        ];
    }
}

if (!function_exists('gd_parse_positive_decimal')) {
    function gd_parse_positive_decimal($value, string $label): float
    {
        $raw = trim((string)$value);
        if ($raw === '' || !is_numeric($raw)) {
            throw new InvalidArgumentException($label . ' must be numeric.');
        }
        $number = round((float)$raw, 2);
        if (!is_finite($number) || $number <= 0) {
            throw new InvalidArgumentException($label . ' must be greater than zero.');
        }
        return $number;
    }
}

if (!function_exists('gd_parse_positive_integer')) {
    function gd_parse_positive_integer($value, string $label): int
    {
        $raw = trim((string)$value);
        if (!preg_match('/^\d+$/', $raw) || (int)$raw <= 0) {
            throw new InvalidArgumentException($label . ' must be a positive whole number.');
        }
        return (int)$raw;
    }
}

if (!function_exists('gd_parse_module_configuration')) {
    function gd_parse_module_configuration(array $input, string $module): array
    {
        $definitions = gd_module_definitions();
        if (!isset($definitions[$module])) {
            throw new InvalidArgumentException('Invalid discount billing module.');
        }

        $active = isset($input[$module . '_discount_active'])
            && (string)$input[$module . '_discount_active'] === '1';
        $method = strtolower(trim((string)($input[$module . '_discount_method'] ?? 'none')));

        $allowedMethods = $definitions[$module]['allows_free_cubic']
            ? ['none', 'percent', 'fixed', 'free_cubic']
            : ['none', 'percent', 'fixed'];

        if (!in_array($method, $allowedMethods, true)) {
            throw new InvalidArgumentException($definitions[$module]['label'] . ' has an invalid discount method.');
        }

        if (!$active || $method === 'none') {
            return [
                $module . '_discount_active' => 0,
                $module . '_discount_method' => 'none',
                $module . '_discount_value' => 0.00,
                $module . '_max_discount_amount' => null,
                $module . '_max_discount_cubic' => null,
            ];
        }

        $valueKey = $module . '_discount_value';
        $maxAmountKey = $module . '_max_discount_amount';
        $value = 0.00;
        $maxAmount = null;
        $maxCubic = null;

        if ($method === 'percent') {
            $value = gd_parse_positive_decimal($input[$valueKey] ?? '', $definitions[$module]['label'] . ' percentage');
            if ($value > 100) {
                throw new InvalidArgumentException($definitions[$module]['label'] . ' percentage cannot exceed 100%.');
            }
            $maxAmount = gd_parse_positive_decimal($input[$maxAmountKey] ?? '', $definitions[$module]['label'] . ' maximum discount amount');
        } elseif ($method === 'fixed') {
            $value = gd_parse_positive_decimal($input[$valueKey] ?? '', $definitions[$module]['label'] . ' fixed discount');
            $maxAmount = gd_parse_positive_decimal($input[$maxAmountKey] ?? '', $definitions[$module]['label'] . ' maximum discount amount');
        } elseif ($method === 'free_cubic') {
            $value = (float)gd_parse_positive_integer($input[$valueKey] ?? '', 'Free cubic-meter value');
            // Free Cubic Meter has no separate maximum. The entered value is the full free allowance.
            $maxCubic = null;
        }

        return [
            $module . '_discount_active' => 1,
            $module . '_discount_method' => $method,
            $module . '_discount_value' => $value,
            $module . '_max_discount_amount' => $maxAmount,
            $module . '_max_discount_cubic' => $maxCubic,
        ];
    }
}

if (!function_exists('gd_parse_discount_configuration')) {
    function gd_parse_discount_configuration(array $input): array
    {
        $discountType = trim(strip_tags((string)($input['discount_type'] ?? '')));
        if ($discountType === '') {
            throw new InvalidArgumentException('Discount classification cannot be empty.');
        }
        if (strlen($discountType) > 191) {
            throw new InvalidArgumentException('Discount classification cannot exceed 191 characters.');
        }

        $payload = [
            'discount_type' => $discountType,
            'is_active' => isset($input['is_active']) && (string)$input['is_active'] === '1' ? 1 : 0,
        ];

        foreach (array_keys(gd_module_definitions()) as $module) {
            $modulePayload = gd_parse_module_configuration($input, $module);
            foreach ($modulePayload as $key => $value) {
                // Non-water modules do not have a database max-cubic column.
                if ($module !== 'water' && substr($key, -19) === '_max_discount_cubic') {
                    continue;
                }
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
