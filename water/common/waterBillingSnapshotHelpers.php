<?php
/**
 * Immutable water-billing snapshot helpers.
 *
 * A draft reading receives a complete snapshot of the member rate code, all
 * tariff tiers, the selected water discount, accounting bill-code mapping, and
 * the computed breakdown. Posting consumes this snapshot instead of reading
 * mutable setup tables again.
 */

if (!function_exists('water_snapshot_normalize')) {
    function water_snapshot_normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return array_map('water_snapshot_normalize', $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = water_snapshot_normalize($item);
        }
        return $value;
    }
}

if (!function_exists('water_snapshot_json')) {
    function water_snapshot_json($value): string
    {
        $json = json_encode(
            water_snapshot_normalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        return $json;
    }
}

if (!function_exists('water_snapshot_decode')) {
    function water_snapshot_decode(?string $json, string $label): array
    {
        if ($json === null || trim($json) === '') {
            throw new RuntimeException("Missing {$label} snapshot.");
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid {$label} snapshot.");
        }
        return $decoded;
    }
}

if (!function_exists('water_snapshot_build')) {
    function water_snapshot_build(array $details, ?string $capturedAt = null): array
    {
        $capturedAt = $capturedAt ?: date('Y-m-d H:i:s');
        $rateCode = $details['rate_code'] ?? [
            'rc_id' => (int)($details['rc_id'] ?? 0),
        ];
        $tariff = $details['rate_tiers'] ?? [];
        $discount = $details['discount'] ?? null;
        $calculation = [
            'consumption' => (int)($details['consumption'] ?? 0),
            'free_m3' => (int)($details['free_m3'] ?? 0),
            'member_discount_free_m3' => (int)($details['member_discount_free_m3'] ?? 0),
            'billable_m3' => (int)($details['billable_m3'] ?? 0),
            'discount_consumption_limit_m3' => (int)($details['discount_consumption_limit_m3'] ?? 30),
            'discount_status' => (string)($details['discount_status'] ?? 'not_applied'),
            'minimum_charge_applied' => (bool)($details['minimum_charge_applied'] ?? false),
            // how the bill was computed (the rate code's Ladder or Straight Rate Billing at the time of the bill)
            'billing_method' => (string)($details['billing_method'] ?? 'straight'),
            'billing_method_label' => (string)($details['billing_method_label'] ?? 'Straight Rate Billing'),
            'base_charge' => (float)($details['base_charge'] ?? 0),
            'free_cubic_discount_amount' => (float)($details['free_cubic_discount_amount'] ?? 0),
            'monetary_discount_amount' => (float)($details['monetary_discount_amount'] ?? 0),
            'discount_amount' => (float)($details['discount_amount'] ?? 0),
            'amount_due' => (float)($details['amount_due'] ?? 0),
            'discount_eligible' => (bool)($details['discount_eligible'] ?? false),
            'discount_basis' => (string)($details['discount_basis'] ?? 'none'),
            'discount_order' => $details['discount_order'] ?? [],
            'applied_discount_rules' => $details['applied_discount_rules'] ?? [],
            'gross_lines' => $details['gross_lines'] ?? [],
            'net_lines' => $details['net_lines'] ?? [],
            'unpriced_m3' => (int)($details['unpriced_m3'] ?? 0),
            'calculation_precision' => $details['calculation_precision'] ?? [],
            'global_bill_code' => $details['global_bill_code'] ?? null,
            'ar_lines' => $details['ar_lines'] ?? [],
            'income_lines' => $details['income_lines'] ?? [],
        ];

        $snapshot = [
            'snapshot_version' => 1,
            'captured_at' => $capturedAt,
            'member_id' => (int)($details['member_id'] ?? 0),
            'rate_code' => $rateCode,
            'tariff_tiers' => $tariff,
            'discount' => $discount,
            'calculation' => $calculation,
        ];

        $snapshot['snapshot_hash'] = hash('sha256', water_snapshot_json($snapshot));
        return $snapshot;
    }
}

if (!function_exists('water_snapshot_details')) {
    function water_snapshot_details(array $snapshot): array
    {
        $calculation = $snapshot['calculation'] ?? null;
        if (!is_array($calculation)) {
            throw new RuntimeException('The water calculation snapshot is incomplete.');
        }

        $details = $calculation;
        $details['member_id'] = (int)($snapshot['member_id'] ?? 0);
        $details['rc_id'] = (int)(($snapshot['rate_code']['rc_id'] ?? $snapshot['rate_code']['id'] ?? 0));
        $details['pipe_size_id'] = (int)(($snapshot['rate_code']['pipe_size_id'] ?? 0));
        $details['pipe_size'] = [
            'pipe_size_id' => (int)(($snapshot['rate_code']['pipe_size_id'] ?? 0)),
            'size_code' => (string)(($snapshot['rate_code']['pipe_size_code'] ?? '')),
            'size_label' => (string)(($snapshot['rate_code']['pipe_size_label'] ?? '')),
            'diameter_inches' => $snapshot['rate_code']['diameter_inches'] ?? null,
        ];
        $details['rate_code'] = $snapshot['rate_code'] ?? [];
        $details['rate_tiers'] = $snapshot['tariff_tiers'] ?? [];
        $details['discount'] = $snapshot['discount'] ?? null;
        return $details;
    }
}

if (!function_exists('water_snapshot_verify')) {
    function water_snapshot_verify(array $snapshot, string $storedHash): void
    {
        $claimed = (string)($snapshot['snapshot_hash'] ?? '');
        unset($snapshot['snapshot_hash']);
        $computed = hash('sha256', water_snapshot_json($snapshot));
        if ($storedHash === '' || !hash_equals($storedHash, $computed) || ($claimed !== '' && !hash_equals($claimed, $computed))) {
            throw new RuntimeException('The stored water-billing snapshot failed its integrity check.');
        }
    }
}

if (!function_exists('water_snapshot_persist')) {
    function water_snapshot_persist(PDO $pdo, int $readingId, array $snapshot): void
    {
        $rateCodeJson = water_snapshot_json($snapshot['rate_code'] ?? []);
        $tariffJson = water_snapshot_json($snapshot['tariff_tiers'] ?? []);
        $discountJson = water_snapshot_json($snapshot['discount'] ?? null);
        $calculationJson = water_snapshot_json($snapshot['calculation'] ?? []);
        $hash = (string)($snapshot['snapshot_hash'] ?? '');
        if ($hash === '') {
            throw new RuntimeException('Cannot store a water-billing snapshot without a hash.');
        }

        $stmt = $pdo->prepare("
            UPDATE bill_readings
            SET snapshot_version = ?,
                rate_code_id_snapshot = ?,
                pipe_size_id_snapshot = ?,
                pipe_size_snapshot = ?,
                rate_code_snapshot = ?,
                tariff_snapshot = ?,
                discount_snapshot = ?,
                calculation_snapshot = ?,
                base_charge_snapshot = ?,
                discount_amount_snapshot = ?,
                snapshot_hash = ?,
                snapshot_created_at = ?,
                amount_due = ?,
                updated_at = NOW()
            WHERE reading_id = ?
              AND is_billed = 0
              AND is_paid = 0
              AND COALESCE(bill_lifecycle_status, 'active') = 'active'
        ");
        $stmt->execute([
            (int)($snapshot['snapshot_version'] ?? 1),
            (int)(($snapshot['rate_code']['rc_id'] ?? $snapshot['rate_code']['id'] ?? 0)),
            (int)(($snapshot['rate_code']['pipe_size_id'] ?? 0)),
            trim((string)($snapshot['rate_code']['pipe_size_label'] ?? '')) !== ''
                ? (string)$snapshot['rate_code']['pipe_size_label']
                : (string)($snapshot['rate_code']['pipe_size_code'] ?? ''),
            $rateCodeJson,
            $tariffJson,
            $discountJson,
            $calculationJson,
            (float)($snapshot['calculation']['base_charge'] ?? 0),
            (float)($snapshot['calculation']['discount_amount'] ?? 0),
            $hash,
            (string)($snapshot['captured_at'] ?? date('Y-m-d H:i:s')),
            (float)($snapshot['calculation']['amount_due'] ?? 0),
            $readingId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The reading changed state before its billing snapshot could be saved.');
        }
    }
}

if (!function_exists('water_snapshot_from_reading')) {
    function water_snapshot_from_reading(array $reading): array
    {
        $snapshot = [
            'snapshot_version' => (int)($reading['snapshot_version'] ?? 0),
            'captured_at' => (string)($reading['snapshot_created_at'] ?? ''),
            'member_id' => (int)($reading['member_id'] ?? 0),
            'rate_code' => water_snapshot_decode($reading['rate_code_snapshot'] ?? null, 'rate-code'),
            'tariff_tiers' => water_snapshot_decode($reading['tariff_snapshot'] ?? null, 'tariff'),
            'discount' => json_decode((string)($reading['discount_snapshot'] ?? 'null'), true, 512, JSON_THROW_ON_ERROR),
            'calculation' => water_snapshot_decode($reading['calculation_snapshot'] ?? null, 'calculation'),
            'snapshot_hash' => (string)($reading['snapshot_hash'] ?? ''),
        ];
        if ($snapshot['snapshot_version'] !== 1) {
            throw new RuntimeException('Unsupported or missing water-billing snapshot version.');
        }
        water_snapshot_verify($snapshot, (string)$reading['snapshot_hash']);
        return $snapshot;
    }
}

if (!function_exists('water_snapshot_select_columns')) {
    function water_snapshot_select_columns(string $alias = 'br'): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return implode(', ', [
            "{$prefix}snapshot_version",
            "{$prefix}rate_code_id_snapshot",
            "{$prefix}pipe_size_id_snapshot",
            "{$prefix}pipe_size_snapshot",
            "{$prefix}rate_code_snapshot",
            "{$prefix}tariff_snapshot",
            "{$prefix}discount_snapshot",
            "{$prefix}calculation_snapshot",
            "{$prefix}base_charge_snapshot",
            "{$prefix}discount_amount_snapshot",
            "{$prefix}snapshot_hash",
            "{$prefix}snapshot_created_at",
        ]);
    }
}

if (!function_exists('water_snapshot_details_for_reading')) {
    /**
     * Return the immutable calculation for a reading. Posted readings are never
     * recomputed from mutable rate/discount setup. Pre-migration legacy rows use
     * their stored amount_due and consumption, clearly marked as legacy.
     */
    function water_snapshot_details_for_reading(array $reading): array
    {
        if ((int)($reading['snapshot_version'] ?? 0) === 1) {
            return water_snapshot_details(water_snapshot_from_reading($reading));
        }

        return [
            'member_id' => (int)($reading['member_id'] ?? 0),
            'consumption' => (int)round((float)($reading['consumption'] ?? 0)),
            'free_m3' => 0,
            'billable_m3' => (int)round((float)($reading['consumption'] ?? 0)),
            'minimum_charge_applied' => false,
            'base_charge' => round((float)($reading['amount_due'] ?? 0), 2),
            'discount_amount' => 0.0,
            'amount_due' => round((float)($reading['amount_due'] ?? 0), 2),
            'discount' => null,
            'gross_lines' => [],
            'net_lines' => [],
            'legacy_snapshot' => true,
            'snapshot_warning' => 'Legacy bill: preserved stored amount because no immutable calculation snapshot exists.',
        ];
    }
}
