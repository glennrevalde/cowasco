<?php
/**
 * Group-level helpers for water tariffs.
 *
 * A tariff group is identified by exactly one Rate Code + Pipe Size pair.
 * All tiers in that pair are created, edited, moved, and deleted together.
 */

require_once __DIR__ . '/waterRateBillingHelpers.php';
require_once __DIR__ . '/waterPipeSizeHelpers.php';

if (!function_exists('water_rate_group_key')) {
    function water_rate_group_key(int $rateCodeId, int $pipeSizeId): string
    {
        return $rateCodeId . ':' . $pipeSizeId;
    }
}

if (!function_exists('water_rate_group_validate_rate_code')) {
    function water_rate_group_validate_rate_code(PDO $pdo, int $rateCodeId, bool $forUpdate = false): array
    {
        if ($rateCodeId <= 0) {
            throw new InvalidArgumentException('Please select a valid rate code.');
        }

        $sql = 'SELECT rc_id, rc_code, rc_name FROM rate_code WHERE rc_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rateCodeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('The selected rate code does not exist.');
        }
        return $row;
    }
}

if (!function_exists('water_rate_group_parse_tiers')) {
    /**
     * Parse and fully validate a submitted tariff group.
     *
     * Required rules:
     * - The user defines the tier boundaries; no default tier pattern is imposed.
     * - The first row starts at exactly 0 m³ and ends at a finite user-entered N.
     * - Every following row starts at the prior finite upper bound + 1.
     * - Only the final row may have a blank To value.
     * - The final row must be open-ended (N-Infinity).
     * - Every amount is positive and every type is FIXED or VARIABLE.
     */
    function water_rate_group_parse_tiers(array $source): array
    {
        $fromValues = $source['tier_from'] ?? [];
        $toValues = $source['tier_to'] ?? [];
        $amountValues = $source['tier_amount'] ?? [];
        $typeValues = $source['tier_bill_type'] ?? [];

        if (!is_array($fromValues) || !is_array($toValues) || !is_array($amountValues) || !is_array($typeValues)) {
            throw new InvalidArgumentException('The submitted tariff tiers are invalid. Reload the page and try again.');
        }

        $count = count($fromValues);
        if ($count === 0 || $count !== count($toValues) || $count !== count($amountValues) || $count !== count($typeValues)) {
            throw new InvalidArgumentException('A complete tariff group must contain matching From, To, Amount, and Rate Type values.');
        }
        if ($count < 2) {
            throw new InvalidArgumentException('A tariff group must contain at least two tiers: a finite 0–N starting tier and an N–Infinity final tier.');
        }
        if ($count > 100) {
            throw new InvalidArgumentException('A tariff group cannot contain more than 100 tiers.');
        }

        $tiers = [];
        foreach ($fromValues as $index => $fromValue) {
            $tierNumber = $index + 1;
            $fromRaw = trim((string)$fromValue);
            $toRaw = trim((string)($toValues[$index] ?? ''));
            $type = strtoupper(trim((string)($typeValues[$index] ?? 'VARIABLE')));

            if (!preg_match('/^\d+$/', $fromRaw)) {
                throw new InvalidArgumentException("Tier {$tierNumber}: From m³ must be a non-negative whole number.");
            }
            if ($toRaw !== '' && !preg_match('/^\d+$/', $toRaw)) {
                throw new InvalidArgumentException("Tier {$tierNumber}: To m³ must be a non-negative whole number or blank only for the final tier.");
            }
            if (!in_array($type, ['FIXED', 'VARIABLE'], true)) {
                throw new InvalidArgumentException("Tier {$tierNumber}: invalid rate type.");
            }

            $tiers[] = [
                'from_cb' => (int)$fromRaw,
                'to_cb' => $toRaw === '' ? null : (int)$toRaw,
                'amount' => water_rate_decimal_input($amountValues[$index] ?? '', "Tier {$tierNumber} amount", 4, false),
                'bill_type' => $type,
            ];
        }

        if ((int)$tiers[0]['from_cb'] !== 0) {
            throw new InvalidArgumentException('The first tier of every tariff group must start at 0 m³.');
        }

        $lastIndex = count($tiers) - 1;
        foreach ($tiers as $index => $tier) {
            if ($index < $lastIndex && $tier['to_cb'] === null) {
                throw new InvalidArgumentException('Only the final tier may be open-ended. Enter a To m³ value for every earlier tier.');
            }
            if ($index === $lastIndex && $tier['to_cb'] !== null) {
                throw new InvalidArgumentException('The final tier must be N-Infinity. Leave the final To m³ field blank.');
            }
        }

        // The canonical validator checks gaps, overlaps, ranges, amounts, and terminal placement.
        return water_rate_validate_tiers($tiers, true, false);
    }
}

if (!function_exists('water_rate_group_lock')) {
    function water_rate_group_lock(PDO $pdo, int $rateCodeId, int $pipeSizeId): array
    {
        return water_rate_get_rates_for_configuration($pdo, $rateCodeId, $pipeSizeId, true);
    }
}

if (!function_exists('water_rate_group_exists')) {
    function water_rate_group_exists(PDO $pdo, int $rateCodeId, int $pipeSizeId, bool $forUpdate = false): bool
    {
        $sql = 'SELECT wmm_id FROM water_meter_members WHERE rc_id = ? AND pipe_size_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rateCodeId, $pipeSizeId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('water_rate_group_insert_tiers')) {
    function water_rate_group_insert_tiers(PDO $pdo, int $rateCodeId, int $pipeSizeId, array $tiers, string $user): void
    {
        $stmt = $pdo->prepare("\n            INSERT INTO water_meter_members\n                (rc_id, pipe_size_id, from_cb, to_cb, amount, bill_type, created_by, created_at, updated_at)\n            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())\n        ");
        foreach ($tiers as $tier) {
            $stmt->execute([
                $rateCodeId,
                $pipeSizeId,
                (int)$tier['from_cb'],
                $tier['to_cb'] === null ? null : (int)$tier['to_cb'],
                (string)$tier['amount'],
                (string)$tier['bill_type'],
                $user,
            ]);
        }
    }
}

if (!function_exists('water_rate_group_member_count')) {
    function water_rate_group_member_count(PDO $pdo, int $rateCodeId, int $pipeSizeId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM water_accounts WHERE rc_id = ? AND pipe_size_id = ?');
        $stmt->execute([$rateCodeId, $pipeSizeId]);
        return (int)$stmt->fetchColumn();
    }
}
