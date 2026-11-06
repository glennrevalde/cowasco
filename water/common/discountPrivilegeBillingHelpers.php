<?php
/**
 * common/discountPrivilegeBillingHelpers.php
 *
 * Detailed Discount Privilege System
 *
 * Rules:
 * - discounted_members = member eligibility / privilege tag.
 * - bill_codes.allow_member_discount = whether this charge may receive member discounts.
 * - discounts = classification plus all user-defined privilege values for every billing module.
 * - If a member has multiple privileges, the system uses the highest discount amount.
 * - Inventory bill codes are not discounted for safety.
 * - total_amount stored in bills remains NET amount for compatibility with collection.
 */

if (!function_exists('dp_decimal_parts')) {
    function dp_decimal_parts($value, int $scale, string $label, bool $allowNegative = false): array
    {
        if (is_int($value)) {
            $text = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException($label . ' must be a finite decimal value.');
            }
            $text = number_format($value, $scale + 4, '.', '');
            $text = rtrim(rtrim($text, '0'), '.');
        } else {
            $text = trim((string)$value);
        }

        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $matches)) {
            throw new InvalidArgumentException($label . ' must be a valid decimal value.');
        }

        $negative = ($matches[1] ?? '') === '-';
        if ($negative && !$allowNegative) {
            throw new InvalidArgumentException($label . ' cannot be negative.');
        }

        $whole = ltrim($matches[2], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $matches[3] ?? '';
        $kept = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $roundDigit = isset($fraction[$scale]) ? (int)$fraction[$scale] : 0;

        if (strlen($whole) > 12) {
            throw new InvalidArgumentException($label . ' is outside the supported range.');
        }

        $units = ((int)$whole * (10 ** $scale)) + (int)$kept;
        if ($roundDigit >= 5) {
            $units++;
        }

        return [$negative ? -$units : $units, $text];
    }
}

if (!function_exists('dp_money_cents')) {
    function dp_money_cents($value, string $label = 'Amount', bool $allowNegative = false): int
    {
        return dp_decimal_parts($value, 2, $label, $allowNegative)[0];
    }
}

if (!function_exists('dp_cents_amount')) {
    function dp_cents_amount(int $cents): float
    {
        return $cents / 100;
    }
}

if (!function_exists('dp_round_money')) {
    function dp_round_money($amount): float
    {
        return round((float)$amount, 2);
    }
}

if (!function_exists('dp_module_label')) {
    function dp_module_label(string $module): string
    {
        $labels = [
            'all' => 'All Non-Water Billing',
            'water' => 'Water Billing',
            'one_time' => 'One-Time Billing',
            'installment' => 'Installment Billing',
            'recurring' => 'Recurring Billing',
        ];

        return $labels[$module] ?? $module;
    }
}

if (!function_exists('dp_get_bill_code')) {
    function dp_get_bill_code(PDO $pdo, int $billCodeId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM bill_codes WHERE code_id = ? LIMIT 1");
        $stmt->execute([$billCodeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('dp_module_discount_columns')) {
    function dp_module_discount_columns(string $module): array
    {
        $map = [
            'one_time' => ['one_time_discount_active', 'one_time_discount_method', 'one_time_discount_value', 'one_time_max_discount_amount'],
            'installment' => ['installment_discount_active', 'installment_discount_method', 'installment_discount_value', 'installment_max_discount_amount'],
            'recurring' => ['recurring_discount_active', 'recurring_discount_method', 'recurring_discount_value', 'recurring_max_discount_amount'],
        ];

        if (!isset($map[$module])) {
            throw new InvalidArgumentException('Invalid non-water discount module.');
        }

        return $map[$module];
    }
}

if (!function_exists('dp_get_member_discount_rules')) {
    function dp_get_member_discount_rules(PDO $pdo, int $memberId, int $billCodeId, string $module): array
    {
        [$activeColumn, $methodColumn, $valueColumn, $maxColumn] = dp_module_discount_columns($module);

        // Column names come only from the fixed map above and are never user supplied.
        $sql = "
            SELECT
                d.discount_id AS discount_rule_id,
                d.discount_id,
                :bill_code_id AS bill_code_id,
                :module_context AS module_context,
                d.{$methodColumn} AS discount_method,
                d.{$valueColumn} AS discount_value,
                d.{$maxColumn} AS max_discount_amount,
                NULL AS max_discount_cubic,
                d.discount_type
            FROM discounted_members dm
            INNER JOIN discounts d ON d.discount_id = dm.discount_id
            WHERE dm.member_id = :member_id
              AND d.is_active = 1
              AND d.{$activeColumn} = 1
              AND d.{$methodColumn} IN ('percent', 'fixed')
            ORDER BY d.discount_id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':member_id' => $memberId,
            ':bill_code_id' => $billCodeId,
            ':module_context' => $module,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('dp_compute_rule_discount')) {
    function dp_compute_rule_discount(array $rule, float $grossAmount): float
    {
        $grossAmount = dp_round_money(max(0, $grossAmount));
        $method = strtolower((string)($rule['discount_method'] ?? 'none'));
        $value = (float)($rule['discount_value'] ?? 0);

        if ($method === 'percent') {
            $discount = $grossAmount * ($value / 100);
        } elseif ($method === 'fixed') {
            $discount = $value;
        } else {
            $discount = 0;
        }

        $discount = max(0, $discount);

        if (isset($rule['max_discount_amount']) && $rule['max_discount_amount'] !== null && $rule['max_discount_amount'] !== '') {
            $max = (float)$rule['max_discount_amount'];
            if ($max > 0) {
                $discount = min($discount, $max);
            }
        }

        return dp_round_money(min($discount, $grossAmount));
    }
}

if (!function_exists('dp_compute_member_bill_discount')) {
    function dp_compute_member_bill_discount(PDO $pdo, int $memberId, int $billCodeId, string $module, float $grossAmount): array
    {
        $grossAmount = dp_round_money(max(0, $grossAmount));
        $billCode = dp_get_bill_code($pdo, $billCodeId);

        $base = [
            'gross_amount' => $grossAmount,
            'discount_amount' => 0.00,
            'net_amount' => $grossAmount,
            'discount_id' => null,
            'discount_rule_id' => null,
            'discount_type' => null,
            'discount_method' => null,
            'discount_value' => 0.00,
            'message' => 'No discount applied.',
        ];

        if (!$billCode) {
            return $base;
        }

        if ((int)($billCode['allow_member_discount'] ?? 0) !== 1) {
            $base['message'] = 'Bill code does not allow member discount.';
            return $base;
        }

        if (($billCode['type'] ?? '') === 'Inventory') {
            $base['message'] = 'Inventory bill codes are not discounted.';
            return $base;
        }

        if ($memberId <= 0 || $billCodeId <= 0 || $grossAmount <= 0) {
            return $base;
        }

        $rules = dp_get_member_discount_rules($pdo, $memberId, $billCodeId, $module);

        $best = null;
        $bestAmount = 0.00;

        foreach ($rules as $rule) {
            $amount = dp_compute_rule_discount($rule, $grossAmount);

            if ($amount > $bestAmount) {
                $bestAmount = $amount;
                $best = $rule;
            }
        }

        if (!$best || $bestAmount <= 0) {
            $base['message'] = 'Member has no applicable active General Discount for this billing module.';
            return $base;
        }

        $net = dp_round_money(max(0, $grossAmount - $bestAmount));

        return [
            'gross_amount' => $grossAmount,
            'discount_amount' => $bestAmount,
            'net_amount' => $net,
            'discount_id' => (int)$best['discount_id'],
            'discount_rule_id' => (int)$best['discount_id'],
            'discount_type' => (string)($best['discount_type'] ?? ''),
            'discount_method' => (string)($best['discount_method'] ?? ''),
            'discount_value' => (float)($best['discount_value'] ?? 0),
            'message' => 'Applied ' . ($best['discount_type'] ?? 'discount') . ' discount.',
        ];
    }
}

if (!function_exists('dp_split_net_amounts')) {
    function dp_split_net_amounts(array $grossScheduleAmounts, float $netTotal): array
    {
        $grossScheduleAmounts = array_map('floatval', $grossScheduleAmounts);
        $netTotal = dp_round_money(max(0, $netTotal));
        $grossTotal = dp_round_money(array_sum($grossScheduleAmounts));

        if (empty($grossScheduleAmounts)) {
            return [];
        }

        if ($grossTotal <= 0) {
            $count = count($grossScheduleAmounts);
            $each = dp_round_money($netTotal / max(1, $count));
            $out = array_fill(0, $count, $each);
            $out[$count - 1] = dp_round_money($netTotal - array_sum(array_slice($out, 0, -1)));
            return $out;
        }

        $out = [];
        $running = 0.00;
        $lastIndex = count($grossScheduleAmounts) - 1;

        foreach ($grossScheduleAmounts as $i => $grossAmount) {
            if ($i === $lastIndex) {
                $amt = dp_round_money($netTotal - $running);
            } else {
                $ratio = $grossAmount / $grossTotal;
                $amt = dp_round_money($netTotal * $ratio);
                $running += $amt;
            }

            $out[] = max(0, $amt);
        }

        return $out;
    }
}

if (!function_exists('dp_discount_audit_payload')) {
    function dp_discount_audit_payload(array $discount): array
    {
        return [
            'gross_amount' => $discount['gross_amount'] ?? 0,
            'discount_amount' => $discount['discount_amount'] ?? 0,
            'net_amount' => $discount['net_amount'] ?? 0,
            'discount_id' => $discount['discount_id'] ?? null,
            'discount_rule_id' => $discount['discount_rule_id'] ?? null,
            'discount_type' => $discount['discount_type'] ?? null,
            'message' => $discount['message'] ?? '',
        ];
    }
}
