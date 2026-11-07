<?php
require_once __DIR__ . '/waterPipeSizeHelpers.php';
require_once __DIR__ . '/waterBillCodeProvisioningHelpers.php';
/**
 * Canonical water billing engine.
 *
 * All authoritative water-charge calculations must pass through this file.
 * Calculations use scaled integers for rates, percentages, and currency so
 * financial results do not depend on binary floating-point arithmetic.
 */

if (!function_exists('water_rate_pow10')) {
    function water_rate_pow10(int $scale): int
    {
        if ($scale < 0 || $scale > 8) {
            throw new InvalidArgumentException('Unsupported decimal scale.');
        }
        return 10 ** $scale;
    }
}

if (!function_exists('water_rate_decimal_to_scaled_int')) {
    function water_rate_decimal_to_scaled_int($value, int $scale = 2): int
    {
        $factor = water_rate_pow10($scale);
        if (is_int($value)) {
            return $value * $factor;
        }

        $raw = trim((string)($value ?? '0'));
        if ($raw === '') {
            $raw = '0';
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $raw, $matches)) {
            if (!is_numeric($value)) {
                throw new InvalidArgumentException('Invalid decimal value.');
            }
            $raw = number_format((float)$value, $scale + 1, '.', '');
            preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $raw, $matches);
        }

        $negative = ($matches[1] ?? '') === '-';
        $whole = (int)($matches[2] ?? '0');
        $fraction = (string)($matches[3] ?? '');
        $kept = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $scaled = ($whole * $factor) + (int)($kept === '' ? 0 : $kept);

        if (strlen($fraction) > $scale && (int)$fraction[$scale] >= 5) {
            $scaled++;
        }

        return $negative ? -$scaled : $scaled;
    }
}

if (!function_exists('water_rate_scaled_to_float')) {
    function water_rate_scaled_to_float(int $value, int $scale = 2): float
    {
        return $value / water_rate_pow10($scale);
    }
}

if (!function_exists('water_rate_scaled_to_decimal')) {
    function water_rate_scaled_to_decimal(int $value, int $scale = 2): string
    {
        $negative = $value < 0;
        $absolute = abs($value);
        $factor = water_rate_pow10($scale);
        $whole = intdiv($absolute, $factor);
        $fraction = $absolute % $factor;
        $formatted = $scale > 0
            ? $whole . '.' . str_pad((string)$fraction, $scale, '0', STR_PAD_LEFT)
            : (string)$whole;
        return ($negative ? '-' : '') . $formatted;
    }
}

if (!function_exists('water_rate_round_divide')) {
    function water_rate_round_divide(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The divisor must be positive.');
        }
        if ($numerator < 0) {
            return -water_rate_round_divide(abs($numerator), $denominator);
        }
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}

if (!function_exists('water_rate_money')) {
    function water_rate_money($value): float
    {
        return water_rate_scaled_to_float(water_rate_decimal_to_scaled_int($value, 2), 2);
    }
}

if (!function_exists('water_rate_normalize_consumption')) {
    function water_rate_normalize_consumption($value): int
    {
        return max(0, (int)round((float)($value ?? 0)));
    }
}

if (!function_exists('water_rate_decimal_input')) {
    function water_rate_decimal_input($value, string $label, int $scale, bool $allowZero = true): string
    {
        $raw = trim((string)$value);
        if ($raw === '' || !preg_match('/^\d+(?:\.\d{1,' . $scale . '})?$/', $raw)) {
            throw new InvalidArgumentException("{$label} must be a non-negative number with at most {$scale} decimal places.");
        }
        $scaled = water_rate_decimal_to_scaled_int($raw, $scale);
        if ($scaled < 0 || (!$allowZero && $scaled === 0)) {
            throw new InvalidArgumentException($allowZero ? "{$label} cannot be negative." : "{$label} must be greater than zero.");
        }
        return water_rate_scaled_to_decimal($scaled, $scale);
    }
}

if (!function_exists('water_billing_settings_table_exists')) {
    function water_billing_settings_table_exists(PDO $pdo): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'water_billing_settings'");
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('water_billing_get_setting')) {
    function water_billing_get_setting(PDO $pdo, string $key): ?string
    {
        if (!water_billing_settings_table_exists($pdo)) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null || $value === '') ? null : (string)$value;
    }
}

if (!function_exists('water_billing_get_global_bill_code')) {
    /** $provision = false only reads (a preview): the Water Bill code is then not created or repaired */
    function water_billing_get_global_bill_code(PDO $pdo, bool $provision = true): array
    {
        if (!water_billing_settings_table_exists($pdo)) {
            throw new Exception('The water billing settings table is missing from the database schema.');
        }

        if ($provision) {
            // Self-heal the required Water Bill dictionary entry. This creates it
            // only when missing, assigns default A/R and Income accounts, sets the
            // sales price to zero, and enables member discounts.
            $provisioned = water_bill_code_ensure($pdo);
            $billCodeId = (int)($provisioned['bill_code']['code_id'] ?? 0);
        } else {
            $billCodeId = (int)$pdo->query("SELECT code_id FROM bill_codes WHERE UPPER(TRIM(code)) = 'WATER BILL' ORDER BY code_id ASC LIMIT 1")->fetchColumn();
        }
        if ($billCodeId <= 0) {
            throw new Exception('The Water Bill bill code could not be configured.');
        }

        $stmt = $pdo->prepare("
            SELECT bc.code_id, bc.code, bc.description, bc.type,
                   bc.ar_account, bc.income_account, bc.sales_price, bc.allow_member_discount,
                   ar.AccntName AS ar_name, inc.AccntName AS income_name
            FROM bill_codes bc
            LEFT JOIN chartofaccntstbl ar ON ar.AccntID = bc.ar_account
            LEFT JOIN chartofaccntstbl inc ON inc.AccntID = bc.income_account
            WHERE bc.code_id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$billCodeId]);
        $billCode = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$billCode) {
            throw new Exception('The configured Water Billing Accounting Bill Code does not exist.');
        }

        if (empty($billCode['ar_account']) || empty($billCode['income_account'])) {
            throw new Exception('The configured Water Billing Bill Code is missing its A/R or Income account mapping.');
        }

        return [
            'code_id' => (int)$billCode['code_id'],
            'code' => (string)$billCode['code'],
            'description' => (string)($billCode['description'] ?? ''),
            'type' => (string)$billCode['type'],
            'ar_account' => (int)$billCode['ar_account'],
            'income_account' => (int)$billCode['income_account'],
            'sales_price' => (float)($billCode['sales_price'] ?? 0),
            'allow_member_discount' => (int)($billCode['allow_member_discount'] ?? 0),
            'ar_name' => (string)($billCode['ar_name'] ?? ''),
            'income_name' => (string)($billCode['income_name'] ?? ''),
        ];
    }
}

if (!function_exists('water_rate_sort_tiers')) {
    function water_rate_sort_tiers(array $rates): array
    {
        usort($rates, static function (array $a, array $b): int {
            $fromCompare = ((int)($a['from_cb'] ?? 0)) <=> ((int)($b['from_cb'] ?? 0));
            return $fromCompare !== 0
                ? $fromCompare
                : (((int)($a['wmm_id'] ?? 0)) <=> ((int)($b['wmm_id'] ?? 0)));
        });
        return $rates;
    }
}

if (!function_exists('water_rate_validate_tiers')) {
    /**
     * Validate tier continuity.
     *
     * The first tier may be entered as 0 or 1. A configured range 0-4
     * represents the first four metered units (1-4); subsequent ranges must
     * start at the previous finite upper bound plus one. A complete active
     * tariff must end with one open-ended tier.
     */
    function water_rate_validate_tiers(array $rates, bool $requireTerminal = true, bool $allowEmpty = false): array
    {
        $rates = water_rate_sort_tiers($rates);
        if (empty($rates)) {
            if ($allowEmpty) {
                return [];
            }
            throw new InvalidArgumentException('No water meter rate tiers are configured for this rate code.');
        }

        $errors = [];
        $normalized = [];
        $expectedStart = 1;
        $terminalSeen = false;
        $lastIndex = count($rates) - 1;

        foreach ($rates as $index => $rate) {
            $from = (int)($rate['from_cb'] ?? 0);
            $to = ($rate['to_cb'] ?? null) === null || $rate['to_cb'] === '' ? null : (int)$rate['to_cb'];
            $type = strtoupper(trim((string)($rate['bill_type'] ?? '')));
            $amountScaled = water_rate_decimal_to_scaled_int($rate['amount'] ?? 0, 4);
            $coverageStart = max(1, $from);
            $label = 'Tier ' . ($index + 1);

            if ($from < 0) {
                $errors[] = "{$label} has a negative start.";
            }
            if ($index === 0 && !in_array($from, [0, 1], true)) {
                $errors[] = 'The first tier must start at 0 or 1 m³.';
            }
            if (!in_array($type, ['FIXED', 'VARIABLE'], true)) {
                $errors[] = "{$label} has an invalid rate type.";
            }
            if ($amountScaled <= 0) {
                $errors[] = "{$label} amount must be greater than zero.";
            }
            if ($terminalSeen) {
                $errors[] = 'No tier may appear after the open-ended terminal tier.';
            }
            if ($coverageStart > $expectedStart) {
                $errors[] = "Gap detected before {$label}: expected the tier to start at {$expectedStart} m³.";
            } elseif ($coverageStart < $expectedStart) {
                $errors[] = "Overlap detected at {$label}: {$coverageStart} m³ is already covered.";
            }

            if ($to === null) {
                $terminalSeen = true;
                if ($index !== $lastIndex) {
                    $errors[] = 'The open-ended tier must be the final tier.';
                }
            } else {
                if ($to < $coverageStart) {
                    $errors[] = "{$label} ends before its first billable unit.";
                }
                $expectedStart = $to + 1;
            }

            $rate['from_cb'] = $from;
            $rate['to_cb'] = $to;
            $rate['bill_type'] = $type;
            $rate['amount'] = water_rate_scaled_to_decimal($amountScaled, 4);
            $normalized[] = $rate;
        }

        if ($requireTerminal && !$terminalSeen) {
            $errors[] = 'The final tier must be open-ended. Leave its “To m³” field blank.';
        }

        if ($errors) {
            throw new InvalidArgumentException('Invalid water rate configuration: ' . implode(' ', array_values(array_unique($errors))));
        }

        return $normalized;
    }
}

if (!function_exists('water_rate_get_configuration_status')) {
    /**
     * Return a configuration-only status without treating an unfinished
     * terminal range as a fatal error.
     *
     * Structural problems such as gaps, overlaps, invalid amounts, and tiers
     * after an open-ended tier remain errors. A continuous tariff whose final
     * tier still has a finite upper bound is saved as an incomplete draft and
     * remains blocked from member assignment, reading calculation, and bill
     * posting by the authoritative billing validators.
     */
    function water_rate_get_configuration_status(array $rates): array
    {
        if (empty($rates)) {
            return [
                'valid' => false,
                'complete' => false,
                'level' => 'incomplete',
                'message' => 'No rate tiers configured yet. Add the first tier to begin.',
            ];
        }

        try {
            $normalized = water_rate_validate_tiers($rates, false, false);
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'complete' => false,
                'level' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        $lastTier = $normalized[count($normalized) - 1];
        $isComplete = ($lastTier['to_cb'] ?? null) === null;

        if (!$isComplete) {
            $nextStart = ((int)$lastTier['to_cb']) + 1;
            return [
                'valid' => true,
                'complete' => false,
                'level' => 'incomplete',
                'message' => "Configuration saved but not ready for billing. Add the next tier starting at {$nextStart} m³, and leave the final “To m³” field blank when all tiers are complete.",
            ];
        }

        return [
            'valid' => true,
            'complete' => true,
            'level' => 'complete',
            'message' => 'Ready for billing. The tariff is continuous and its final tier is open-ended.',
        ];
    }
}

if (!function_exists('water_rate_configuration_is_complete')) {
    function water_rate_configuration_is_complete(array $rates): bool
    {
        $status = water_rate_get_configuration_status($rates);
        return ($status['valid'] ?? false) === true
            && ($status['complete'] ?? false) === true;
    }
}

if (!function_exists('water_rate_allocate_net_amount')) {
    function water_rate_allocate_net_amount(array $grossLines, float $netAmount): array
    {
        $grossTotalCents = 0;
        foreach ($grossLines as $line) {
            $grossTotalCents += isset($line['gross_cents'])
                ? (int)$line['gross_cents']
                : water_rate_decimal_to_scaled_int($line['gross_amount'] ?? 0, 2);
        }
        $netCents = max(0, water_rate_decimal_to_scaled_int($netAmount, 2));

        if ($grossTotalCents <= 0 || empty($grossLines)) {
            return [];
        }

        $allocated = [];
        $runningCents = 0;
        $lastIndex = count($grossLines) - 1;

        foreach ($grossLines as $index => $line) {
            $grossCents = isset($line['gross_cents'])
                ? (int)$line['gross_cents']
                : water_rate_decimal_to_scaled_int($line['gross_amount'] ?? 0, 2);
            $lineNetCents = $index === $lastIndex
                ? max(0, $netCents - $runningCents)
                : min(max(0, $netCents - $runningCents), water_rate_round_divide($grossCents * $netCents, $grossTotalCents));
            $runningCents += $lineNetCents;
            $line['net_cents'] = $lineNetCents;
            $line['net_amount'] = water_rate_scaled_to_float($lineNetCents, 2);
            $allocated[] = $line;
        }

        return $allocated;
    }
}

if (!function_exists('water_member_discount_max_consumption_m3')) {
    function water_member_discount_max_consumption_m3(): int
    {
        return 30;
    }
}

if (!function_exists('water_rate_validate_discount_rule')) {
    function water_rate_validate_discount_rule(array $rule): array
    {
        $method = strtolower(trim((string)($rule['discount_method'] ?? 'none')));
        if (!in_array($method, ['none', 'percent', 'fixed', 'free_cubic'], true)) {
            throw new InvalidArgumentException('Invalid water discount method.');
        }

        $value = (float)($rule['discount_value'] ?? 0);
        if ($value < 0) {
            throw new InvalidArgumentException('Water discount value cannot be negative.');
        }
        if ($method === 'percent' && ($value <= 0 || $value > 100)) {
            throw new InvalidArgumentException('Water percentage discount must be greater than 0 and not more than 100%.');
        }
        if ($method === 'fixed' && $value <= 0) {
            throw new InvalidArgumentException('Water fixed discount must be greater than zero.');
        }
        if ($method === 'free_cubic' && (!is_finite($value) || $value <= 0 || floor($value) != $value)) {
            throw new InvalidArgumentException('Free cubic-meter discount must be a positive whole number.');
        }

        $maxAmount = null;
        if (($rule['max_discount_amount'] ?? null) !== null && $rule['max_discount_amount'] !== '') {
            $maxAmount = water_rate_money($rule['max_discount_amount']);
            if ($maxAmount <= 0) {
                throw new InvalidArgumentException('Maximum monetary discount must be greater than zero.');
            }
        }

        if (in_array($method, ['percent', 'fixed'], true) && $maxAmount === null) {
            throw new InvalidArgumentException('A maximum monetary discount is required for percentage and fixed water discounts.');
        }

        return [
            'discount_rule_id' => isset($rule['discount_rule_id']) ? (int)$rule['discount_rule_id'] : null,
            'discount_id' => isset($rule['discount_id']) ? (int)$rule['discount_id'] : null,
            'discount_type' => trim((string)($rule['discount_type'] ?? '')),
            'discount_method' => $method,
            'discount_value' => $method === 'free_cubic' ? (int)$value : water_rate_money($value),
            'max_discount_amount' => in_array($method, ['percent', 'fixed'], true) ? $maxAmount : null,
            'max_discount_cubic' => null,
            'module_context' => (string)($rule['module_context'] ?? 'water'),
            'bill_code_id' => isset($rule['bill_code_id']) ? (int)$rule['bill_code_id'] : null,
        ];
    }
}

if (!function_exists('water_rate_validate_discount_configuration')) {
    function water_rate_validate_discount_configuration(?array $discount): ?array
    {
        if (!$discount) {
            return null;
        }

        $rules = $discount['rules'] ?? null;
        if (!is_array($rules)) {
            // Compatibility for a single normalized rule.
            $rules = [$discount];
        }

        $normalized = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $normalizedRule = water_rate_validate_discount_rule($rule);
            if ($normalizedRule['discount_method'] !== 'none') {
                $normalized[] = $normalizedRule;
            }
        }

        if (!$normalized) {
            return null;
        }

        return [
            'source' => 'discounts',
            'eligibility_limit_m3' => water_member_discount_max_consumption_m3(),
            'rules' => $normalized,
        ];
    }
}

if (!function_exists('water_rate_normalize_discount')) {
    function water_rate_normalize_discount(?array $discount): ?array
    {
        return water_rate_validate_discount_configuration($discount);
    }
}

if (!function_exists('water_rate_billing_methods')) {
    /** The computations a rate code can use (rate_code.billing_method) */
    function water_rate_billing_methods(): array
    {
        return ['straight' => 'Straight Rate Billing', 'ladder' => 'Ladder Rate Billing'];
    }
}

if (!function_exists('water_rate_billing_method')) {
    /** A rate code's computation, read safely: anything but 'ladder' is Straight Rate Billing */
    function water_rate_billing_method($value): string
    {
        return strtolower(trim((string)$value)) === 'ladder' ? 'ladder' : 'straight';
    }
}

if (!function_exists('water_rate_tier_line')) {
    /** One priced tier of a bill */
    function water_rate_tier_line(array $rate, int $units, int $cents): array
    {
        return [
            'wmm_id' => isset($rate['wmm_id']) ? (int)$rate['wmm_id'] : null,
            'rc_id' => isset($rate['rc_id']) ? (int)$rate['rc_id'] : null,
            'pipe_size_id' => isset($rate['pipe_size_id']) ? (int)$rate['pipe_size_id'] : null,
            'pipe_size_code' => (string)($rate['pipe_size_code'] ?? ''),
            'pipe_size_label' => (string)($rate['pipe_size_label'] ?? ''),
            'from_cb' => (int)$rate['from_cb'],
            'to_cb' => $rate['to_cb'] !== null ? (int)$rate['to_cb'] : null,
            'rate_amount' => water_rate_scaled_to_float(water_rate_decimal_to_scaled_int($rate['amount'], 4), 4),
            'rate_bill_type' => strtoupper((string)$rate['bill_type']),
            'tier_consumption' => $units,
            'gross_cents' => $cents,
            'gross_amount' => water_rate_scaled_to_float($cents, 2),
        ];
    }
}

if (!function_exists('water_rate_price_ladder')) {
    /**
     * Ladder Rate Billing: each tier prices only its own cubic meters, and the tiers are added up. A FIXED tier is a
     * minimum charge for its whole range (the first tier is charged on every bill, even at 0 m3; a later fixed tier
     * once the consumption reaches it). 250 m3 on 0-5 fixed 145.50, 6-10 31.80, 11-20 34.30, 21-30 44.80, 31-40
     * 50.10, 41-up 55.40 = 145.50 + 5 x 31.80 + 10 x 34.30 + 10 x 44.80 + 10 x 50.10 + 210 x 55.40 = 13,230.50.
     * Each tier is rounded to the centavo.
     */
    function water_rate_price_ladder(array $rates, int $billable): array
    {
        $lines = [];
        $total = 0;
        $last = null;
        foreach (array_values($rates) as $i => $rate) {
            $start = max(1, (int)$rate['from_cb']);
            $end = $rate['to_cb'] === null ? null : (int)$rate['to_cb'];
            if ($i > 0 && $billable < $start) {
                break;
            }
            $units = max(0, ($end === null ? $billable : min($billable, $end)) - $start + 1);
            $type = strtoupper((string)$rate['bill_type']);
            $cents = $type === 'FIXED'
                ? water_rate_decimal_to_scaled_int($rate['amount'], 2)
                : water_rate_round_divide(water_rate_decimal_to_scaled_int($rate['amount'], 4) * $units, 100);
            $last = $rate;
            if ($cents > 0) {
                $lines[] = water_rate_tier_line($rate, $units, $cents);
                $total += $cents;
            }
        }
        if ($last === null) {
            throw new RuntimeException("{$billable} m³ is not covered by the configured water-rate tiers.");
        }
        $type = strtoupper((string)$last['bill_type']);
        return [
            'billable_m3' => $billable,
            'selected_rate' => $last,
            'selected_type' => $type,
            'amount_cents' => $total,
            'minimum_charge_applied' => count($lines) === 1 && $type === 'FIXED',
            'line' => $lines[0] ?? null,
            'lines' => $lines,
        ];
    }
}

if (!function_exists('water_rate_price_billable_volume')) {
    /**
     * The water charge of a billable volume by the rate code's computation: Straight Rate Billing (the default)
     * prices the whole volume at the rate of the tier it falls in; Ladder Rate Billing adds up the tiers.
     */
    function water_rate_price_billable_volume(array $rates, int $billable, string $method = 'straight'): array
    {
        if (water_rate_billing_method($method) === 'ladder') {
            return water_rate_price_ladder($rates, $billable);
        }
        $selectedRate = null;
        if ($billable === 0) {
            $selectedRate = $rates[0];
        } else {
            foreach ($rates as $rate) {
                $coverageStart = max(1, (int)$rate['from_cb']);
                $coverageEnd = $rate['to_cb'] === null ? null : (int)$rate['to_cb'];
                if ($billable >= $coverageStart && ($coverageEnd === null || $billable <= $coverageEnd)) {
                    $selectedRate = $rate;
                    break;
                }
            }
        }

        if ($selectedRate === null) {
            throw new RuntimeException("{$billable} m³ is not covered by the configured water-rate tiers.");
        }

        $type = strtoupper((string)$selectedRate['bill_type']);
        if ($type === 'FIXED') {
            $amountCents = water_rate_decimal_to_scaled_int($selectedRate['amount'], 2);
        } else {
            $rateUnits = water_rate_decimal_to_scaled_int($selectedRate['amount'], 4);
            $amountCents = water_rate_round_divide($rateUnits * $billable, 100);
        }

        $line = null;
        if ($amountCents > 0) {
            $line = [
                'wmm_id' => isset($selectedRate['wmm_id']) ? (int)$selectedRate['wmm_id'] : null,
                'rc_id' => isset($selectedRate['rc_id']) ? (int)$selectedRate['rc_id'] : null,
                'pipe_size_id' => isset($selectedRate['pipe_size_id']) ? (int)$selectedRate['pipe_size_id'] : null,
                'pipe_size_code' => (string)($selectedRate['pipe_size_code'] ?? ''),
                'pipe_size_label' => (string)($selectedRate['pipe_size_label'] ?? ''),
                'from_cb' => (int)$selectedRate['from_cb'],
                'to_cb' => $selectedRate['to_cb'] !== null ? (int)$selectedRate['to_cb'] : null,
                'rate_amount' => water_rate_scaled_to_float(water_rate_decimal_to_scaled_int($selectedRate['amount'], 4), 4),
                'rate_bill_type' => $type,
                'tier_consumption' => $billable,
                'gross_cents' => $amountCents,
                'gross_amount' => water_rate_scaled_to_float($amountCents, 2),
            ];
        }

        return [
            'billable_m3' => $billable,
            'selected_rate' => $selectedRate,
            'selected_type' => $type,
            'amount_cents' => $amountCents,
            'minimum_charge_applied' => $type === 'FIXED' && $amountCents > 0,
            'line' => $line,
            'lines' => $line ? [$line] : [],
        ];
    }
}

if (!function_exists('water_rate_compute_from_configuration')) {
    /**
     * Calculate a water bill using selected-bracket pricing.
     *
     * Discount order is authoritative:
     * 1. Calculate the charge from total consumption.
     * 2. Apply one Water Billing Free Cubic Meter privilege, when assigned.
     * 3. Re-price the reduced billable cubic meters.
     * 4. If original total consumption is not above 30 m³, apply one allowed
     *    Percentage/Fixed privilege to that reduced water charge.
     *
     * Free Cubic Meter is never blocked by the 30 m³ monetary-discount limit.
     */
    function water_rate_compute_from_configuration(
        array $rates,
        float $consumption,
        ?array $discount = null,
        string $method = 'straight'
    ): array
    {
        // the computation of the rate code (its own name: $method below is the method of a discount rule)
        $billingMethod = water_rate_billing_method($method);
        $consumption = water_rate_normalize_consumption($consumption);
        $discountBundle = water_rate_normalize_discount($discount);
        $rates = water_rate_validate_tiers($rates, true, false);

        $baseBillable = max(0, $consumption);
        $basePricing = water_rate_price_billable_volume($rates, $baseBillable, $billingMethod);
        $baseCents = (int)$basePricing['amount_cents'];
        $limit = water_member_discount_max_consumption_m3();
        $withinLimit = $consumption <= $limit;

        $freeRules = [];
        $monetaryRules = [];
        foreach (($discountBundle['rules'] ?? []) as $rule) {
            $method = (string)$rule['discount_method'];
            if ($method === 'free_cubic') {
                $freeRules[] = $rule;
            } elseif (in_array($method, ['percent', 'fixed'], true)) {
                $monetaryRules[] = $rule;
            }
        }

        if (count($freeRules) > 1) {
            throw new RuntimeException('This member has more than one active Water Billing Free Cubic Meter privilege. Keep only one before billing.');
        }
        if (count($monetaryRules) > 1) {
            throw new RuntimeException('This member has more than one active Water Billing Percentage/Fixed privilege. Keep only one before billing.');
        }

        $freeRule = $freeRules[0] ?? null;
        $monetaryRule = $monetaryRules[0] ?? null;
        $memberFreeM3 = 0;
        $postFreePricing = $basePricing;
        $postFreeCents = $baseCents;

        if ($freeRule) {
            $memberFreeM3 = max(0, (int)$freeRule['discount_value']);
            $billableAfterFree = max(0, $consumption - $memberFreeM3);
            $postFreePricing = water_rate_price_billable_volume($rates, $billableAfterFree, $billingMethod);
            $postFreeCents = min($baseCents, max(0, (int)$postFreePricing['amount_cents']));
        }

        $freeBenefitCents = max(0, $baseCents - $postFreeCents);
        $monetaryBenefitCents = 0;
        $finalDueCents = $postFreeCents;
        $appliedMonetaryRule = null;

        if ($monetaryRule && $withinLimit) {
            $method = (string)$monetaryRule['discount_method'];
            if ($method === 'percent') {
                $percentUnits = water_rate_decimal_to_scaled_int($monetaryRule['discount_value'], 4);
                $calculatedCents = water_rate_round_divide($postFreeCents * $percentUnits, 100 * water_rate_pow10(4));
            } else {
                $calculatedCents = water_rate_decimal_to_scaled_int($monetaryRule['discount_value'], 2);
            }

            $capCents = water_rate_decimal_to_scaled_int($monetaryRule['max_discount_amount'], 2);
            $monetaryBenefitCents = min($postFreeCents, max(0, min($calculatedCents, $capCents)));
            $finalDueCents = max(0, $postFreeCents - $monetaryBenefitCents);
            $appliedMonetaryRule = $monetaryRule;
        }

        $grossLines = $basePricing['lines'];
        $postFreeLines = $postFreePricing['lines'];
        $netLines = water_rate_allocate_net_amount(
            $postFreeLines,
            water_rate_scaled_to_float($finalDueCents, 2)
        );

        $appliedRules = [];
        if ($freeRule) $appliedRules[] = $freeRule;
        if ($appliedMonetaryRule) $appliedRules[] = $appliedMonetaryRule;

        $appliedDiscount = $appliedRules ? [
            'source' => 'discounts',
            'eligibility_limit_m3' => $limit,
            'stacking_order' => 'free_cubic_then_monetary',
            'rules' => $appliedRules,
        ] : null;

        $totalBenefitCents = max(0, $baseCents - $finalDueCents);
        $selectedRate = $postFreePricing['selected_rate'];
        $selectedType = $postFreePricing['selected_type'];
        $basisParts = array_map(
            static fn(array $rule): string => (string)$rule['discount_method'],
            $appliedRules
        );
        $discountBasis = $basisParts ? implode('+', $basisParts) : 'none';

        if ($appliedRules) {
            $discountStatus = ($freeRule && $monetaryRule && !$withinLimit)
                ? "Free Cubic Meter applied. Percentage and fixed discounts are unavailable above {$limit} m³."
                : 'Member privilege discount applied.';
        } elseif ($monetaryRule && !$withinLimit) {
            $discountStatus = "Percentage and fixed discounts are unavailable above {$limit} m³.";
        } else {
            $discountStatus = 'No applicable member privilege discount.';
        }

        return [
            'consumption' => $consumption,
            'free_m3' => $memberFreeM3,
            'member_discount_free_m3' => $memberFreeM3,
            'billable_m3' => (int)$postFreePricing['billable_m3'],
            'minimum_charge_applied' => (bool)$postFreePricing['minimum_charge_applied'],
            'pricing_method' => $billingMethod === 'ladder' ? 'ladder_tiers_added_up' : 'selected_tier_full_billable_volume',
            'billing_method' => $billingMethod,
            'billing_method_label' => water_rate_billing_methods()[$billingMethod],
            'selected_tier' => [
                'wmm_id' => isset($selectedRate['wmm_id']) ? (int)$selectedRate['wmm_id'] : null,
                'rc_id' => isset($selectedRate['rc_id']) ? (int)$selectedRate['rc_id'] : null,
                'pipe_size_id' => isset($selectedRate['pipe_size_id']) ? (int)$selectedRate['pipe_size_id'] : null,
                'pipe_size_code' => (string)($selectedRate['pipe_size_code'] ?? ''),
                'pipe_size_label' => (string)($selectedRate['pipe_size_label'] ?? ''),
                'from_cb' => (int)$selectedRate['from_cb'],
                'to_cb' => $selectedRate['to_cb'] !== null ? (int)$selectedRate['to_cb'] : null,
                'bill_type' => $selectedType,
                'amount' => water_rate_scaled_to_float(water_rate_decimal_to_scaled_int($selectedRate['amount'], 4), 4),
            ],
            'base_charge' => water_rate_scaled_to_float($baseCents, 2),
            'free_cubic_discount_amount' => water_rate_scaled_to_float($freeBenefitCents, 2),
            'monetary_discount_amount' => water_rate_scaled_to_float($monetaryBenefitCents, 2),
            'discount_amount' => water_rate_scaled_to_float($totalBenefitCents, 2),
            'amount_due' => water_rate_scaled_to_float($finalDueCents, 2),
            'discount' => $appliedDiscount,
            'applied_discount_rules' => $appliedRules,
            'available_discount_rules' => $discountBundle['rules'] ?? [],
            'discount_eligible' => !empty($appliedRules),
            'discount_consumption_limit_m3' => $limit,
            'discount_status' => $discountStatus,
            'discount_basis' => $discountBasis,
            'discount_order' => [
                'calculate_total_consumption_charge',
                'apply_one_free_cubic_privilege_regardless_of_total_consumption',
                'reprice_reduced_billable_cubic_meters',
                'reject_percentage_and_fixed_discounts_when_original_consumption_exceeds_30_m3',
                'apply_one_percentage_or_fixed_privilege_to_the_reduced_water_charge',
                'cap_percentage_or_fixed_discount_by_maximum_amount',
            ],
            'gross_lines' => $grossLines,
            'net_lines' => $netLines,
            'unpriced_m3' => 0,
            'calculation_precision' => [
                'currency_scale' => 2,
                'rate_scale' => 4,
                'percentage_scale' => 4,
                'rounding' => 'half_up',
            ],
        ];
    }
}

if (!function_exists('water_rate_get_member_rate_code_details')) {
    function water_rate_get_member_rate_code_details(PDO $pdo, int $memberId): array
    {
        $stmt = $pdo->prepare("
            SELECT rc.rc_id, rc.rc_code, rc.rc_name, rc.billing_method,
                   ps.pipe_size_id, ps.size_code AS pipe_size_code,
                   ps.size_label AS pipe_size_label, ps.diameter_inches,
                   ps.is_active AS pipe_size_is_active
            FROM water_accounts m
            LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
            LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
            WHERE m.pkey = ?
            LIMIT 1
        ");
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Member was not found for water billing computation.');
        }
        if ((int)($row['rc_id'] ?? 0) <= 0) {
            throw new Exception('This member has no assigned water rate code.');
        }
        if ((int)($row['pipe_size_id'] ?? 0) <= 0) {
            throw new Exception('This member has no assigned pipe size. Assign a pipe size before entering or posting a water bill.');
        }
        if ((int)($row['pipe_size_is_active'] ?? 0) !== 1) {
            throw new Exception('This member is assigned to an inactive pipe size. Reactivate it or assign another pipe size before billing.');
        }
        return [
            'rc_id' => (int)$row['rc_id'],
            'rc_code' => (string)($row['rc_code'] ?? ''),
            'rc_name' => (string)($row['rc_name'] ?? ''),
            'billing_method' => water_rate_billing_method($row['billing_method'] ?? 'straight'),
            'pipe_size_id' => (int)$row['pipe_size_id'],
            'pipe_size_code' => (string)($row['pipe_size_code'] ?? ''),
            'pipe_size_label' => (string)($row['pipe_size_label'] ?? ''),
            'diameter_inches' => $row['diameter_inches'] !== null ? (float)$row['diameter_inches'] : null,
        ];
    }
}

if (!function_exists('water_rate_get_member_rate_code')) {
    function water_rate_get_member_rate_code(PDO $pdo, int $memberId): int
    {
        return (int)water_rate_get_member_rate_code_details($pdo, $memberId)['rc_id'];
    }
}

if (!function_exists('water_rate_get_rates_for_configuration')) {
    function water_rate_get_rates_for_configuration(PDO $pdo, int $rcId, int $pipeSizeId, bool $forUpdate = false): array
    {
        if ($rcId <= 0 || $pipeSizeId <= 0) {
            throw new InvalidArgumentException('A valid rate code and pipe size are required.');
        }
        $sql = "
            SELECT w.wmm_id, w.rc_id, w.pipe_size_id, w.from_cb, w.to_cb, w.amount, w.bill_type,
                   ps.size_code AS pipe_size_code, ps.size_label AS pipe_size_label, ps.diameter_inches
            FROM water_meter_members w
            JOIN water_pipe_sizes ps ON ps.pipe_size_id = w.pipe_size_id
            WHERE w.rc_id = ? AND w.pipe_size_id = ?
            ORDER BY w.from_cb ASC, w.wmm_id ASC
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rcId, $pipeSizeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('water_rate_get_rates_for_code')) {
    /**
     * Returns every pipe-size tariff under one rate code. Use
     * water_rate_get_rates_for_configuration() for billing or continuity checks.
     */
    function water_rate_get_rates_for_code(PDO $pdo, int $rcId, bool $forUpdate = false): array
    {
        $sql = "
            SELECT w.wmm_id, w.rc_id, w.pipe_size_id, w.from_cb, w.to_cb, w.amount, w.bill_type,
                   ps.size_code AS pipe_size_code, ps.size_label AS pipe_size_label, ps.diameter_inches
            FROM water_meter_members w
            JOIN water_pipe_sizes ps ON ps.pipe_size_id = w.pipe_size_id
            WHERE w.rc_id = ?
            ORDER BY w.pipe_size_id ASC, w.from_cb ASC, w.wmm_id ASC
        ";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rcId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('water_rate_get_member_discount')) {
    function water_rate_get_member_discount(PDO $pdo, int $memberId, bool $provision = true): ?array
    {
        if ($memberId <= 0) {
            return null;
        }

        $globalBillCode = water_billing_get_global_bill_code($pdo, $provision);
        if ((int)($globalBillCode['allow_member_discount'] ?? 0) !== 1) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                d.discount_id AS discount_rule_id,
                d.discount_id,
                :bill_code_id AS bill_code_id,
                'water' AS module_context,
                d.water_discount_method AS discount_method,
                d.water_discount_value AS discount_value,
                d.water_max_discount_amount AS max_discount_amount,
                d.water_max_discount_cubic AS max_discount_cubic,
                d.discount_type
            FROM discounted_members dm
            INNER JOIN discounts d ON d.discount_id = dm.discount_id
            WHERE dm.member_id = :member_id
              AND d.is_active = 1
              AND d.water_discount_active = 1
              AND d.water_discount_method IN ('percent', 'fixed', 'free_cubic')
            ORDER BY d.discount_id ASC
        ");
        $stmt->execute([
            ':member_id' => $memberId,
            ':bill_code_id' => (int)$globalBillCode['code_id'],
        ]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rules) {
            return null;
        }

        return water_rate_validate_discount_configuration([
            'source' => 'discounts',
            'eligibility_limit_m3' => water_member_discount_max_consumption_m3(),
            'rules' => $rules,
        ]);
    }
}

if (!function_exists('water_rate_attach_accounting')) {
    function water_rate_attach_accounting(array $details, array $globalBillCode): array
    {
        foreach (['gross_lines', 'net_lines'] as $key) {
            foreach ($details[$key] as &$line) {
                $line['bill_code_id'] = (int)$globalBillCode['code_id'];
                $line['bill_code'] = (string)$globalBillCode['code'];
                $line['bill_code_type'] = (string)$globalBillCode['type'];
                $line['ar_account'] = (int)$globalBillCode['ar_account'];
                $line['income_account'] = (int)$globalBillCode['income_account'];
            }
            unset($line);
        }

        $arLines = [];
        $incomeLines = [];
        foreach ($details['net_lines'] as $line) {
            $amountCents = isset($line['net_cents'])
                ? (int)$line['net_cents']
                : water_rate_decimal_to_scaled_int($line['net_amount'] ?? 0, 2);
            if ($amountCents <= 0) {
                continue;
            }

            $arAccount = (int)$globalBillCode['ar_account'];
            $incomeAccount = (int)$globalBillCode['income_account'];
            if (!isset($arLines[$arAccount])) {
                $arLines[$arAccount] = ['account_id' => $arAccount, 'amount_cents' => 0, 'bill_codes' => []];
            }
            $arLines[$arAccount]['amount_cents'] += $amountCents;
            $arLines[$arAccount]['bill_codes'][(string)$globalBillCode['code']] = true;

            if (!isset($incomeLines[$incomeAccount])) {
                $incomeLines[$incomeAccount] = ['account_id' => $incomeAccount, 'amount_cents' => 0, 'bill_codes' => [], 'tier_lines' => []];
            }
            $incomeLines[$incomeAccount]['amount_cents'] += $amountCents;
            $incomeLines[$incomeAccount]['bill_codes'][(string)$globalBillCode['code']] = true;
            $incomeLines[$incomeAccount]['tier_lines'][] = $line;
        }

        foreach ($arLines as &$line) {
            $line['amount'] = water_rate_scaled_to_float((int)$line['amount_cents'], 2);
            $line['bill_codes'] = array_keys($line['bill_codes']);
        }
        unset($line);
        foreach ($incomeLines as &$line) {
            $line['amount'] = water_rate_scaled_to_float((int)$line['amount_cents'], 2);
            $line['bill_codes'] = array_keys($line['bill_codes']);
        }
        unset($line);

        $details['global_bill_code'] = $globalBillCode;
        $details['ar_lines'] = array_values($arLines);
        $details['income_lines'] = array_values($incomeLines);
        return $details;
    }
}

if (!function_exists('compute_water_billing_details')) {
    /** $provision = false only reads (a preview: the Water Bill code is not created or repaired on the way) */
    function compute_water_billing_details(PDO $pdo, int $memberId, float $consumption, bool $requireAccounting = true, bool $provision = true): array
    {
        $rateCode = water_rate_get_member_rate_code_details($pdo, $memberId);
        $rcId = (int)$rateCode['rc_id'];
        $pipeSizeId = (int)$rateCode['pipe_size_id'];
        $rates = water_rate_validate_tiers(
            water_rate_get_rates_for_configuration($pdo, $rcId, $pipeSizeId),
            true,
            false
        );
        $discount = water_rate_get_member_discount($pdo, $memberId, $provision);
        $details = water_rate_compute_from_configuration(
            $rates,
            $consumption,
            $discount,
            (string)$rateCode['billing_method']
        );
        $details['member_id'] = $memberId;
        $details['rc_id'] = $rcId;
        $details['pipe_size_id'] = $pipeSizeId;
        $details['pipe_size'] = [
            'pipe_size_id' => $pipeSizeId,
            'size_code' => (string)$rateCode['pipe_size_code'],
            'size_label' => (string)$rateCode['pipe_size_label'],
            'diameter_inches' => $rateCode['diameter_inches'],
        ];
        $details['rate_code'] = $rateCode;
        $details['rate_tiers'] = $rates;

        if ($requireAccounting) {
            return water_rate_attach_accounting($details, water_billing_get_global_bill_code($pdo));
        }

        $details['global_bill_code'] = null;
        $details['ar_lines'] = [];
        $details['income_lines'] = [];
        return $details;
    }
}

if (!function_exists('compute_water_amount_from_rates')) {
    function compute_water_amount_from_rates(PDO $pdo, int $memberId, float $consumption): float
    {
        return (float)compute_water_billing_details($pdo, $memberId, $consumption, false)['amount_due'];
    }
}
