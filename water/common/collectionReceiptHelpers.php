<?php
require_once __DIR__ . '/memberAddressHelpers.php';
/**
 * Collection Receipt Helpers
 *
 * Shared by:
 * - process/processUnifiedPayment.php
 * - process/getCollectionReceipt.php
 * - views/collection_receipt.php
 *
 * The printed receipt is the measured COWASSCO 22 cm × 10 cm continuous-form
 * layout. The helper keeps the detailed payment allocation data available to
 * the application while also preparing the exact fields required by the
 * physical receipt.
 */

if (!function_exists('cr_table_exists')) {
    function cr_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cr_column_exists')) {
    function cr_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('cr_money')) {
    function cr_money($value): string
    {
        return '₱ ' . number_format((float)($value ?? 0), 2);
    }
}

if (!function_exists('cr_receipt_money')) {
    function cr_receipt_money($value, bool $blankWhenZero = false): string
    {
        $amount = round((float)($value ?? 0), 2);
        if ($blankWhenZero && abs($amount) < 0.005) {
            return '';
        }
        return number_format($amount, 2, '.', ',');
    }
}

if (!function_exists('cr_type_label')) {
    function cr_type_label(string $type): string
    {
        $map = [
            'water' => 'Water Bill',
            'installment' => 'Installment',
            'onetime' => 'One-Time Bill',
            'recurring' => 'Recurring Bill',
            'sharecap' => 'Share Capital',
            'mixed' => 'Mixed Collection',
        ];

        return $map[$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}

if (!function_exists('cr_receipt_date')) {
    function cr_receipt_date(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '0000-00-00' || $value === '0001-01-01') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : strtoupper(date('d-M-y', $timestamp));
    }
}

if (!function_exists('cr_receipt_datetime')) {
    function cr_receipt_datetime(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('n/j/Y g:i:s A', $timestamp);
    }
}

if (!function_exists('cr_words_below_thousand')) {
    function cr_words_below_thousand(int $number): string
    {
        $ones = [
            0 => '', 1 => 'ONE', 2 => 'TWO', 3 => 'THREE', 4 => 'FOUR', 5 => 'FIVE',
            6 => 'SIX', 7 => 'SEVEN', 8 => 'EIGHT', 9 => 'NINE', 10 => 'TEN',
            11 => 'ELEVEN', 12 => 'TWELVE', 13 => 'THIRTEEN', 14 => 'FOURTEEN',
            15 => 'FIFTEEN', 16 => 'SIXTEEN', 17 => 'SEVENTEEN', 18 => 'EIGHTEEN',
            19 => 'NINETEEN',
        ];
        $tens = [
            20 => 'TWENTY', 30 => 'THIRTY', 40 => 'FORTY', 50 => 'FIFTY',
            60 => 'SIXTY', 70 => 'SEVENTY', 80 => 'EIGHTY', 90 => 'NINETY',
        ];

        $parts = [];
        if ($number >= 100) {
            $parts[] = $ones[intdiv($number, 100)] . ' HUNDRED';
            $number %= 100;
        }
        if ($number >= 20) {
            $base = intdiv($number, 10) * 10;
            $parts[] = $tens[$base];
            $number %= 10;
        }
        if ($number > 0) {
            $parts[] = $ones[$number];
        }

        return trim(implode(' ', $parts));
    }
}

if (!function_exists('cr_integer_to_words')) {
    function cr_integer_to_words(int $number): string
    {
        if ($number === 0) {
            return 'ZERO';
        }

        $scales = [
            1000000000000 => 'TRILLION',
            1000000000 => 'BILLION',
            1000000 => 'MILLION',
            1000 => 'THOUSAND',
        ];
        $parts = [];

        foreach ($scales as $value => $label) {
            if ($number >= $value) {
                $group = intdiv($number, $value);
                $parts[] = cr_integer_to_words($group) . ' ' . $label;
                $number %= $value;
            }
        }

        if ($number > 0) {
            $parts[] = cr_words_below_thousand($number);
        }

        return trim(implode(' ', array_filter($parts)));
    }
}

if (!function_exists('cr_amount_in_words')) {
    function cr_amount_in_words($value): string
    {
        $centsTotal = (int)round(max(0, (float)($value ?? 0)) * 100, 0, PHP_ROUND_HALF_UP);
        $pesos = intdiv($centsTotal, 100);
        $centavos = $centsTotal % 100;

        $pesoWords = cr_integer_to_words($pesos) . ($pesos === 1 ? ' PESO' : ' PESOS');
        if ($centavos === 0) {
            return $pesoWords . ' AND NO CENTAVOS';
        }

        return $pesoWords . ' AND ' . cr_integer_to_words($centavos) . ($centavos === 1 ? ' CENTAVO' : ' CENTAVOS');
    }
}

if (!function_exists('cr_fetch_member_address')) {
    function cr_fetch_member_address(PDO $pdo, int $memberId): string
    {
        if (!cr_table_exists($pdo, 'memberaddress') || !cr_table_exists($pdo, 'addresses')) {
            return '';
        }

        try {
            $cityJoin = cr_table_exists($pdo, 'cities')
                ? "LEFT JOIN cities c ON c.pkey = a.city_id"
                : "LEFT JOIN (SELECT NULL AS pkey, NULL AS city, NULL AS province) c ON 1 = 0";
            $primaryAddressTypeId = member_primary_address_type_id($pdo);
            $stmt = $pdo->prepare("
                SELECT TRIM(CONCAT_WS(', ',
                    NULLIF(TRIM(CONCAT_WS(' ',
                        NULLIF(a.housebldg, ''),
                        NULLIF(a.street, ''),
                        NULLIF(a.other_address_detail, '')
                    )), ''),
                    NULLIF(c.city, ''),
                    NULLIF(c.province, '')
                )) AS full_address
                FROM memberaddress ma
                JOIN addresses a ON a.pkey = ma.address_key
                {$cityJoin}
                WHERE ma.member_key = ?
                  AND ma.status = 'A'
                  AND (a.status = 'A' OR a.status IS NULL)
                  AND (ma.dtaddressto IS NULL OR ma.dtaddressto = '0001-01-01' OR ma.dtaddressto >= CURDATE())
                ORDER BY (ma.address_type_key = ?) DESC, ma.dtaddressFrom DESC, ma.address_key DESC
                LIMIT 1
            ");
            $stmt->execute([$memberId, $primaryAddressTypeId]);
            return trim((string)($stmt->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            error_log('Receipt address lookup failed: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('cr_collection_setting')) {
    function cr_collection_setting(PDO $pdo, string $key, string $default = ''): string
    {
        if (!cr_table_exists($pdo, 'water_billing_settings')) {
            return $default;
        }
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? $default : (string)$value;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('cr_fetch_water_receipt_context')) {
    /**
     * Build the exact water-reading section used by the measured receipt.
     *
     * When a payment includes several water bills, the newest water bill is
     * displayed as WATER FEE and older paid water allocations are accumulated
     * under ARREARS. Non-water allocations and penalties are accumulated under
     * OTHER CHARGES. This preserves the fixed receipt geometry without adding
     * rows or columns that are absent from the physical form.
     */
    function cr_fetch_water_receipt_context(PDO $pdo, int $memberId, array $items): array
    {
        $waterAllocations = [];
        $nonWaterPrincipal = 0.0;

        foreach ($items as $item) {
            $principal = round((float)($item['principal_amount'] ?? $item['amount'] ?? 0), 2);
            $penalty = round((float)($item['penalty_amount'] ?? 0), 2);
            if (($item['source_type'] ?? '') === 'water' && (int)($item['source_id'] ?? 0) > 0) {
                $waterAllocations[(int)$item['source_id']] = [
                    'principal' => $principal,
                    'penalty' => $penalty,
                ];
            } else {
                $nonWaterPrincipal += $principal;
            }
        }

        $result = [
            'rate_code' => '',
            'previous_reading_date' => '',
            'present_reading_date' => '',
            'due_date' => '',
            'disconnection_date' => '',
            'previous_reading' => '',
            'present_reading' => '',
            'usage' => '',
            'water_fee' => 0.0,
            'arrears' => 0.0,
            'other_charges' => round($nonWaterPrincipal, 2),
            'water_bill_count' => count($waterAllocations),
        ];

        if (!$waterAllocations || !cr_table_exists($pdo, 'bill_readings')) {
            return $result;
        }

        $ids = array_keys($waterAllocations);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $snapshotSelect = cr_column_exists($pdo, 'bill_readings', 'rate_code_snapshot')
            ? 'br.rate_code_snapshot'
            : 'NULL AS rate_code_snapshot';

        try {
            $stmt = $pdo->prepare("
                SELECT
                    br.reading_id,
                    br.member_id,
                    br.prev_reading,
                    br.pres_reading,
                    br.consumption,
                    br.billing_date,
                    br.due_date,
                    br.disconnection_date,
                    br.disconnection_days,
                    br.created_at,
                    bp.start_date AS period_start_date,
                    bp.end_date AS period_end_date,
                    (
                        SELECT bp2.end_date
                        FROM bill_readings previous_br
                        JOIN bill_periods bp2 ON bp2.period_id = previous_br.period_id
                        WHERE previous_br.member_id = br.member_id
                          AND bp2.end_date < bp.end_date
                          AND (previous_br.deleted_at IS NULL)
                        ORDER BY bp2.end_date DESC, previous_br.reading_id DESC
                        LIMIT 1
                    ) AS previous_reading_date,
                    rc.rc_code,
                    rc.rc_name,
                    {$snapshotSelect}
                FROM bill_readings br
                LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
                LEFT JOIN water_accounts m ON m.pkey = br.member_id
                LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
                WHERE br.reading_id IN ({$placeholders})
                  AND br.member_id = ?
                ORDER BY COALESCE(bp.end_date, br.billing_date, DATE(br.created_at)) DESC, br.reading_id DESC
            ");
            $params = array_merge($ids, [$memberId]);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Receipt water-reading lookup failed: ' . $e->getMessage());
            return $result;
        }

        if (!$rows) {
            return $result;
        }

        $primary = $rows[0];
        $primaryId = (int)$primary['reading_id'];
        $result['water_fee'] = round((float)($waterAllocations[$primaryId]['principal'] ?? 0), 2);

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue;
            }
            $result['arrears'] += (float)($waterAllocations[(int)$row['reading_id']]['principal'] ?? 0);
        }
        $result['arrears'] = round($result['arrears'], 2);

        $rateCode = trim((string)($primary['rc_code'] ?? ''));
        $snapshotJson = trim((string)($primary['rate_code_snapshot'] ?? ''));
        if ($snapshotJson !== '') {
            try {
                $snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($snapshot)) {
                    $snapshotCode = trim((string)($snapshot['rc_code'] ?? $snapshot['code'] ?? ''));
                    if ($snapshotCode !== '') {
                        $rateCode = $snapshotCode;
                    }
                }
            } catch (Throwable $e) {
                // Preserve the current rate-code fallback for legacy or malformed snapshots.
            }
        }

        $dueDate = trim((string)($primary['due_date'] ?? ''));
        $disconnectionDate = trim((string)($primary['disconnection_date'] ?? ''));
        if ($disconnectionDate === '' && $dueDate !== '' && (int)($primary['disconnection_days'] ?? 0) > 0) {
            $disconnectionDate = date('Y-m-d', strtotime($dueDate . ' +' . (int)$primary['disconnection_days'] . ' days'));
        }

        $result['rate_code'] = $rateCode;
        $result['previous_reading_date'] = (string)($primary['previous_reading_date'] ?: $primary['period_start_date'] ?: '');
        $result['present_reading_date'] = (string)($primary['period_end_date'] ?: $primary['billing_date'] ?: $primary['created_at'] ?: '');
        $result['due_date'] = $dueDate;
        $result['disconnection_date'] = $disconnectionDate;
        $result['previous_reading'] = (string)(int)($primary['prev_reading'] ?? 0);
        $result['present_reading'] = (string)(int)($primary['pres_reading'] ?? 0);
        $result['usage'] = (string)(int)($primary['consumption'] ?? 0);

        return $result;
    }
}

if (!function_exists('cr_build_other_fee_breakdown')) {
    /**
     * Build the receipt's Other Fees section using the actual paid bill-code
     * names. Water Bill / Water Fee remains in its dedicated receipt field and
     * is never repeated under Other Fees.
     */
    function cr_build_other_fee_breakdown(PDO $pdo, array $tx, array $items): array
    {
        // the fees and other bills of the receipt, and the share capital contributions (Fixed Share Capital Billing)
        $otherTypes = ['installment', 'onetime', 'recurring', 'sharecap'];
        $grouped = [];
        $order = [];

        foreach ($items as $item) {
            $type = strtolower(trim((string)($item['source_type'] ?? '')));
            if (!in_array($type, $otherTypes, true)) {
                continue;
            }
            if (strtolower((string)($item['allocation_status'] ?? 'active')) !== 'active') {
                continue;
            }

            $amount = round((float)($item['principal_amount'] ?? $item['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $label = trim(cr_official_receipt_bill_code($pdo, $item));
            if ($label === '' || in_array(strtoupper($label), ['WATER BILL', 'WATER FEE'], true)) {
                continue;
            }
            $key = strtoupper($label);
            if (!array_key_exists($key, $grouped)) {
                $grouped[$key] = 0.0;
                $order[] = $key;
            }
            $grouped[$key] = round($grouped[$key] + $amount, 2);
        }

        $rows = [];
        $total = 0.0;
        foreach ($order as $label) {
            $amount = round((float)$grouped[$label], 2);
            $rows[] = [
                'source_type' => 'bill_code',
                'source_label' => $label,
                'source_id' => 0,
                'description' => $label,
                'due_date' => null,
                'amount' => $amount,
                'principal_amount' => $amount,
            ];
            $total += $amount;
        }

        foreach (['penalty' => 'PENALTY', 'compromise' => 'COMPROMISE'] as $key => $label) {
            $amount = round((float)($tx[$key . '_amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }
            $rows[] = [
                'source_type' => $key,
                'source_label' => $label,
                'source_id' => 0,
                'description' => $label,
                'due_date' => null,
                'amount' => $amount,
                'principal_amount' => $amount,
            ];
            $total += $amount;
        }

        return [
            'items' => $rows,
            'total' => round($total, 2),
        ];
    }
}

if (!function_exists('cr_official_receipt_bill_code')) {
    /**
     * Resolve the actual bill-code label represented by a payment allocation.
     * Water allocations always use the WATER BILL service. The other source
     * types are resolved from their authoritative parent bill and bill_codes.
     */
    function cr_official_receipt_bill_code(PDO $pdo, array $item): string
    {
        $type = strtolower(trim((string)($item['source_type'] ?? '')));
        $sourceId = (int)($item['source_id'] ?? 0);

        if ($type === 'water') {
            return 'WATER BILL';
        }
        if ($type === 'sharecap') {
            // a contribution of Fixed Share Capital Billing: a deposit to the share capital account, not a bill code
            return ShareCapitalBilling::CODE_NAME;
        }

        if ($sourceId > 0) {
            try {
                if ($type === 'installment') {
                    $stmt = $pdo->prepare(
                        "SELECT bc.code
                         FROM installment_schedules schedule
                         JOIN installment_bills bill ON bill.installment_id = schedule.installment_id
                         JOIN bill_codes bc ON bc.code_id = bill.bill_code_id
                         WHERE schedule.schedule_id = ?
                         LIMIT 1"
                    );
                } elseif ($type === 'onetime') {
                    $stmt = $pdo->prepare(
                        "SELECT bc.code
                         FROM one_time_bills bill
                         JOIN bill_codes bc ON bc.code_id = bill.bill_code_id
                         WHERE bill.bill_id = ?
                         LIMIT 1"
                    );
                } elseif ($type === 'recurring') {
                    $stmt = $pdo->prepare(
                        "SELECT bc.code
                         FROM recurring_bills bill
                         JOIN bill_codes bc ON bc.code_id = bill.bill_code_id
                         WHERE bill.bill_id = ?
                         LIMIT 1"
                    );
                } else {
                    $stmt = null;
                }

                if ($stmt instanceof PDOStatement) {
                    $stmt->execute([$sourceId]);
                    $code = trim((string)($stmt->fetchColumn() ?: ''));
                    if ($code !== '') {
                        return strtoupper($code);
                    }
                }
            } catch (Throwable $error) {
                error_log('Official receipt bill-code lookup failed: ' . $error->getMessage());
            }
        }

        $description = trim((string)($item['description'] ?? ''));
        $description = preg_replace('/\s*\((?:INSTALLMENT|ONE[- ]?TIME|RECURRING)\)\s*$/i', '', $description) ?? $description;
        if ($description !== '') {
            return strtoupper($description);
        }

        return strtoupper(cr_type_label($type !== '' ? $type : 'mixed'));
    }
}

if (!function_exists('cr_build_official_receipt_services')) {
    /**
     * Build the official-receipt service lines. Every paid receivable is shown
     * using its bill code, while the cashier-applied Penalty and Compromise are
     * appended only when their recorded amounts are greater than zero.
     */
    function cr_build_official_receipt_services(PDO $pdo, array $tx, array $items): array
    {
        $grouped = [];
        $order = [];

        foreach ($items as $item) {
            if (strtolower((string)($item['allocation_status'] ?? 'active')) !== 'active') {
                continue;
            }

            $amount = round((float)($item['principal_amount'] ?? $item['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $label = cr_official_receipt_bill_code($pdo, $item);
            $key = strtoupper(trim($label));
            if ($key === '') {
                $key = 'OTHER';
            }

            if (!array_key_exists($key, $grouped)) {
                $grouped[$key] = 0.0;
                $order[] = $key;
            }
            $grouped[$key] += $amount;
        }

        $rows = [];
        foreach ($order as $key) {
            $rows[] = [
                'description' => $key,
                'amount' => round($grouped[$key], 2),
            ];
        }

        $penalty = round((float)($tx['penalty_amount'] ?? 0), 2);
        if ($penalty > 0) {
            $rows[] = ['description' => 'PENALTY', 'amount' => $penalty];
        }

        $compromise = round((float)($tx['compromise_amount'] ?? 0), 2);
        if ($compromise > 0) {
            $rows[] = ['description' => 'COMPROMISE', 'amount' => $compromise];
        }

        return $rows;
    }
}

if (!function_exists('cr_build_measured_layout')) {
    function cr_build_measured_layout(PDO $pdo, array $tx, array $items): array
    {
        $memberId = (int)($tx['member_id'] ?? 0);
        $water = cr_fetch_water_receipt_context($pdo, $memberId, $items);
        $otherFees = cr_build_other_fee_breakdown($pdo, $tx, $items);
        $water['other_charges'] = (float)$otherFees['total'];
        $totalPaid = round((float)($tx['total_paid'] ?? 0), 2);
        $tendered = array_key_exists('tendered_amount', $tx)
            ? round((float)$tx['tendered_amount'], 2)
            : $totalPaid;
        $change = array_key_exists('change_amount', $tx)
            ? round((float)$tx['change_amount'], 2)
            : max(0, round($tendered - $totalPaid, 2));

        return [
            'account_number' => (string)($tx['account_number'] ?: $memberId),
            'concessionaire_name' => strtoupper(trim((string)($tx['member_name'] ?? ''))),
            'receipt_number' => (string)($tx['receipt_no'] ?? ''),
            'address' => strtoupper(cr_fetch_member_address($pdo, $memberId)),
            'rate_code' => strtoupper((string)$water['rate_code']),
            'previous_reading_date' => cr_receipt_date((string)$water['previous_reading_date']),
            'present_reading_date' => cr_receipt_date((string)$water['present_reading_date']),
            'due_date' => cr_receipt_date((string)$water['due_date']),
            'charge_mode' => ((float)($tx['penalty_amount'] ?? 0) + (float)($tx['compromise_amount'] ?? 0)) > 0 ? 'MANUAL' : '',
            'disconnection_date' => cr_receipt_date((string)$water['disconnection_date']),
            'previous_reading' => (string)$water['previous_reading'],
            'present_reading' => (string)$water['present_reading'],
            'usage' => (string)$water['usage'],
            'water_fee' => cr_receipt_money($water['water_fee']),
            'arrears' => cr_receipt_money($water['arrears'], true),
            'other_charges' => cr_receipt_money($water['other_charges'], true),
            'other_fee_items' => array_map(static function (array $row): array {
                return [
                    'description' => strtoupper(trim((string)($row['description'] ?? ''))),
                    'amount' => cr_receipt_money((float)($row['amount'] ?? 0), true),
                ];
            }, $otherFees['items']),
            'date_time_of_payment' => cr_receipt_datetime((string)($tx['payment_date'] ?? '')),
            'received_by' => trim((string)($tx['cashier_name'] ?: ($tx['cashier_username'] ?? ''))),
            'amount_due' => cr_receipt_money($totalPaid),
            'cash' => cr_receipt_money($tendered),
            'change' => cr_receipt_money($change),
            'amount_in_words' => cr_amount_in_words($totalPaid),
            'water_bill_count' => (int)$water['water_bill_count'],
        ];
    }
}

if (!function_exists('cr_fetch_receipt')) {
    function cr_fetch_receipt(PDO $pdo, string $receiptNo): ?array
    {
        $receiptNo = trim($receiptNo);

        if ($receiptNo === '') {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                pt.*,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                m.account_number,
                m.tinno AS member_tin,
                COALESCE(NULLIF(o.description, ''), o.name) AS cashier_name,
                o.name AS cashier_username,
                b.name AS branch_name,
                s.name AS segment_name,
                d.name AS division_name
            FROM payment_transactions pt
            JOIN water_accounts m ON m.pkey = pt.member_id
            LEFT JOIN operator o ON o.pkey = pt.cashier_id
            LEFT JOIN branches b ON b.pkey = pt.branch_id
            LEFT JOIN ic_segments s ON s.pkey = pt.segment_id
            LEFT JOIN ic_divisions d ON d.pkey = pt.division_id
            WHERE pt.receipt_no = ?
            LIMIT 1
        ");
        $stmt->execute([$receiptNo]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            return null;
        }

        $items = [];
        if (cr_table_exists($pdo, 'payment_transaction_items')) {
            $hasPrincipal = cr_column_exists($pdo, 'payment_transaction_items', 'principal_amount');
            $hasPenalty = cr_column_exists($pdo, 'payment_transaction_items', 'penalty_amount');
            $hasBefore = cr_column_exists($pdo, 'payment_transaction_items', 'balance_before');
            $hasAfter = cr_column_exists($pdo, 'payment_transaction_items', 'balance_after');
            $hasStatus = cr_column_exists($pdo, 'payment_transaction_items', 'allocation_status');

            $detail = $pdo->prepare("
                SELECT source_type, source_id, description, due_date, amount,
                       " . ($hasPrincipal ? 'principal_amount' : 'amount AS principal_amount') . ",
                       " . ($hasPenalty ? 'penalty_amount' : '0 AS penalty_amount') . ",
                       " . ($hasBefore ? 'balance_before' : 'amount AS balance_before') . ",
                       " . ($hasAfter ? 'balance_after' : '0 AS balance_after') . ",
                       " . ($hasStatus ? 'allocation_status' : "'active' AS allocation_status") . "
                FROM payment_transaction_items
                WHERE transaction_id = ?
                ORDER BY item_id ASC
            ");
            $detail->execute([(int)$tx['transaction_id']]);
            foreach ($detail->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = [
                    'source_type' => (string)$row['source_type'],
                    'source_label' => cr_type_label((string)$row['source_type']),
                    'source_id' => (int)$row['source_id'],
                    'description' => $row['description'] ?: cr_type_label((string)$row['source_type']),
                    'due_date' => $row['due_date'] ?: null,
                    'amount' => (float)$row['amount'],
                    'principal_amount' => (float)$row['principal_amount'],
                    'penalty_amount' => (float)$row['penalty_amount'],
                    'balance_before' => (float)$row['balance_before'],
                    'balance_after' => (float)$row['balance_after'],
                    'allocation_status' => (string)$row['allocation_status'],
                ];
            }
        }

        if (empty($items)) {
            $items[] = [
                'source_type' => (string)($tx['payment_type'] ?? 'mixed'),
                'source_label' => cr_type_label((string)($tx['payment_type'] ?? 'mixed')),
                'source_id' => 0,
                'description' => cr_type_label((string)($tx['payment_type'] ?? 'mixed')) . ' payment',
                'due_date' => null,
                'amount' => (float)($tx['base_amount'] ?? 0),
                'principal_amount' => (float)($tx['base_amount'] ?? 0),
                'penalty_amount' => (float)($tx['penalty_amount'] ?? 0),
                'balance_before' => (float)($tx['base_amount'] ?? 0),
                'balance_after' => 0.00,
                'allocation_status' => (string)($tx['transaction_status'] ?? 'active'),
            ];
        }

        $totalPaid = (float)($tx['total_paid'] ?? 0);
        $tendered = array_key_exists('tendered_amount', $tx) ? (float)$tx['tendered_amount'] : $totalPaid;
        $change = array_key_exists('change_amount', $tx) ? (float)$tx['change_amount'] : max(0, $tendered - $totalPaid);
        $otherFees = cr_build_other_fee_breakdown($pdo, $tx, $items);
        $waterPrincipalTotal = 0.0;
        foreach ($items as $item) {
            if (strtolower((string)($item['source_type'] ?? '')) === 'water') {
                $waterPrincipalTotal += (float)($item['principal_amount'] ?? $item['amount'] ?? 0);
            }
        }
        $waterPrincipalTotal = round($waterPrincipalTotal, 2);
        $layout = cr_build_measured_layout($pdo, $tx, $items);
        $officialReceiptServices = cr_build_official_receipt_services($pdo, $tx, $items);

        return [
            'transaction_id' => (int)$tx['transaction_id'],
            'receipt_no' => (string)$tx['receipt_no'],
            'payment_type' => (string)($tx['payment_type'] ?? 'mixed'),
            'payment_type_label' => cr_type_label((string)($tx['payment_type'] ?? 'mixed')),
            'member_id' => (int)$tx['member_id'],
            'account_number' => (string)($tx['account_number'] ?? ''),
            'member_name' => trim((string)($tx['member_name'] ?? '')),
            'member_tin' => trim((string)($tx['member_tin'] ?? '')),
            'member_address' => trim((string)($layout['address'] ?? '')),
            'cashier_name' => (string)($tx['cashier_name'] ?: ($tx['cashier_username'] ?? 'System')),
            'payment_date' => (string)$tx['payment_date'],
            'payment_date_display' => date('M j, Y h:i A', strtotime((string)$tx['payment_date'])),
            'business_date' => (string)($tx['business_date'] ?? date('Y-m-d', strtotime((string)$tx['payment_date']))),
            'business_date_display' => date('M j, Y', strtotime((string)($tx['business_date'] ?? $tx['payment_date']))),
            'created_at' => (string)($tx['created_at'] ?? $tx['payment_date']),
            'transaction_status' => (string)($tx['transaction_status'] ?? 'active'),
            'journal_status' => (string)($tx['journal_status'] ?? 'draft'),
            'reversed_at' => $tx['reversed_at'] ?? null,
            'reversal_reason' => $tx['reversal_reason'] ?? null,
            'reversal_journal_voucher_id' => $tx['reversal_journal_voucher_id'] ?? null,
            'base_amount' => (float)($tx['base_amount'] ?? 0),
            'penalty_amount' => (float)($tx['penalty_amount'] ?? 0),
            'compromise_amount' => (float)($tx['compromise_amount'] ?? 0),
            'share_capital_amount' => (float)($tx['share_capital_amount'] ?? 0),
            'total_paid' => $totalPaid,
            'tendered_amount' => $tendered,
            'change_amount' => $change,
            'journal_voucher_id' => (string)($tx['journal_voucher_id'] ?? ''),
            'branch_name' => (string)($tx['branch_name'] ?? ''),
            'segment_name' => (string)($tx['segment_name'] ?? ''),
            'division_name' => (string)($tx['division_name'] ?? ''),
            'items' => $items,
            'water_principal_total' => $waterPrincipalTotal,
            'other_fee_items' => $otherFees['items'],
            'other_fees_total' => $otherFees['total'],
            'official_receipt_services' => $officialReceiptServices,
            'layout' => $layout,
        ];
    }
}

if (!function_exists('cr_register_print')) {
    function cr_register_print(PDO $pdo, string $receiptNo): void
    {
        if (!cr_column_exists($pdo, 'payment_transactions', 'receipt_print_count')) {
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE payment_transactions
            SET receipt_print_count = receipt_print_count + 1,
                last_printed_at = NOW()
            WHERE receipt_no = ?
        ");
        $stmt->execute([$receiptNo]);
    }
}
