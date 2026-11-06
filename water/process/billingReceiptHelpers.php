<?php
/**
 * process/billingReceiptHelpers.php
 * Shared helpers for disconnection dates and printable collection receipts.
 */

require_once __DIR__ . '/../common/memberAddressHelpers.php';

if (!function_exists('wbr_e')) {
    function wbr_e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wbr_money')) {
    function wbr_money($amount): string
    {
        return number_format((float)($amount ?? 0), 2);
    }
}

if (!function_exists('wbr_date')) {
    function wbr_date($date): string
    {
        if (!$date || $date === '0000-00-00') return '—';
        return date('d-M-y', strtotime((string)$date));
    }
}

if (!function_exists('wbr_datetime')) {
    function wbr_datetime($date): string
    {
        if (!$date || $date === '0000-00-00 00:00:00') return date('m/d/Y h:i:s A');
        return date('m/d/Y h:i:s A', strtotime((string)$date));
    }
}

if (!function_exists('wbr_due_date')) {
    function wbr_due_date(string $billDate, int $termDays): string
    {
        $date = new DateTime($billDate);
        $date->modify('+' . max($termDays, 0) . ' days');
        return $date->format('Y-m-d');
    }
}

if (!function_exists('wbr_disconnection_date')) {
    function wbr_disconnection_date(?string $dueDate, int $disconnectionDays): ?string
    {
        if (!$dueDate || $dueDate === '0000-00-00') return null;
        $date = new DateTime($dueDate);
        $date->modify('+' . max($disconnectionDays, 0) . ' days');
        return $date->format('Y-m-d');
    }
}

if (!function_exists('wbr_column_exists')) {
    function wbr_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('wbr_table_exists')) {
    function wbr_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('wbr_number_words_under_1000')) {
    function wbr_number_words_under_1000(int $number): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $words = [];
        if ($number >= 100) {
            $words[] = $ones[intdiv($number, 100)] . ' Hundred';
            $number %= 100;
        }
        if ($number >= 20) {
            $words[] = $tens[intdiv($number, 10)];
            $number %= 10;
        }
        if ($number > 0) {
            $words[] = $ones[$number];
        }
        return implode(' ', $words);
    }
}

if (!function_exists('wbr_amount_in_words')) {
    function wbr_amount_in_words($amount): string
    {
        $amount = round((float)$amount, 2);
        $pesos = (int)floor($amount);
        $centavos = (int)round(($amount - $pesos) * 100);

        if ($pesos === 0) {
            $words = 'Zero';
        } else {
            $parts = [];
            $billions = intdiv($pesos, 1000000000);
            if ($billions) {
                $parts[] = wbr_number_words_under_1000($billions) . ' Billion';
                $pesos %= 1000000000;
            }
            $millions = intdiv($pesos, 1000000);
            if ($millions) {
                $parts[] = wbr_number_words_under_1000($millions) . ' Million';
                $pesos %= 1000000;
            }
            $thousands = intdiv($pesos, 1000);
            if ($thousands) {
                $parts[] = wbr_number_words_under_1000($thousands) . ' Thousand';
                $pesos %= 1000;
            }
            if ($pesos) {
                $parts[] = wbr_number_words_under_1000($pesos);
            }
            $words = implode(' ', $parts);
        }

        $centWords = $centavos > 0 ? wbr_number_words_under_1000($centavos) . ' Centavos' : 'No Centavos';
        return $words . ' Pesos And ' . $centWords;
    }
}

if (!function_exists('wbr_member_name')) {
    function wbr_member_name(array $row): string
    {
        $last = trim((string)($row['lastname'] ?? ''));
        $first = trim((string)($row['firstname'] ?? ''));
        $middle = trim((string)($row['middlename'] ?? ''));
        $mi = $middle !== '' ? strtoupper(substr($middle, 0, 1)) . '.' : '';
        // an organization, a joint account or an institution has no first name: its name is the last name alone
        $name = trim($last . ($first !== '' ? ', ' . $first . ($mi !== '' ? ' ' . $mi : '') : ''));
        return $name !== '' ? $name : '—';
    }
}

if (!function_exists('wbr_find_member_address')) {
    function wbr_find_member_address(PDO $pdo, int $memberId, array $memberRow): string
    {
        /* Prefer the canonical schema, while retaining the legacy fallback. */
        $zone = trim((string)($memberRow['zone'] ?? ''));
        $fallback = $zone !== '' ? 'Zone ' . $zone : '—';

        try {
            if (wbr_table_exists($pdo, 'memberaddress')
                && wbr_table_exists($pdo, 'addresses')
                && wbr_table_exists($pdo, 'cities')) {
                $primaryAddressTypeId = member_primary_address_type_id($pdo);
                $stmt = $pdo->prepare("
                    SELECT a.housebldg, a.street, a.other_address_detail, a.zippostal_code,
                           c.city, c.province, c.country
                    FROM memberaddress ma
                    INNER JOIN addresses a ON a.pkey = ma.address_key AND a.status = 'A'
                    LEFT JOIN cities c ON c.pkey = a.city_id AND c.status = 'A'
                    WHERE ma.member_key = ?
                      AND ma.status = 'A'
                      AND (ma.dtaddressto IS NULL OR ma.dtaddressto = '0001-01-01' OR ma.dtaddressto >= CURDATE())
                    ORDER BY
                        CASE WHEN ma.address_type_key = ? THEN 0 ELSE 1 END,
                        ma.dtaddressFrom DESC,
                        ma.address_key DESC
                    LIMIT 1
                ");
                $stmt->execute([$memberId, $primaryAddressTypeId]);
                $addr = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($addr) {
                    $postal = trim((string)($addr['zippostal_code'] ?? ''));
                    $locality = trim(implode(', ', array_filter([
                        trim((string)($addr['city'] ?? '')),
                        trim((string)($addr['province'] ?? '')),
                        trim((string)($addr['country'] ?? '')),
                    ], static fn(string $part): bool => $part !== '')));
                    $formatted = trim(implode(', ', array_filter([
                        trim(implode(' ', array_filter([
                            trim((string)($addr['housebldg'] ?? '')),
                            trim((string)($addr['street'] ?? '')),
                        ], static fn(string $part): bool => $part !== ''))),
                        trim((string)($addr['other_address_detail'] ?? '')),
                        $locality,
                        $postal,
                    ], static fn(string $part): bool => $part !== '')));
                    if ($formatted !== '') {
                        return $formatted;
                    }
                }
            }

            if (wbr_table_exists($pdo, 'member_addresses')) {
                $stmt = $pdo->prepare("SELECT * FROM member_addresses WHERE member_id = ? OR memberID = ? LIMIT 1");
                $stmt->execute([$memberId, $memberId]);
                $addr = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($addr) {
                    return trim(implode(' ', array_filter([
                        $addr['housebldg'] ?? null,
                        $addr['street'] ?? null,
                        $addr['barangay'] ?? null,
                        $addr['city'] ?? null,
                        $addr['province'] ?? null,
                    ]))) ?: $fallback;
                }
            }
        } catch (Throwable $ignored) {}

        return $fallback;
    }
}

if (!function_exists('wbr_fetch_receipt')) {
    function wbr_fetch_receipt(PDO $pdo, string $receiptNo): array
    {
        $stmt = $pdo->prepare("\n            SELECT pt.*,\n                   m.pkey AS member_pkey, m.account_number, m.lastname, m.firstname, m.middlename, m.zone,\n                   rc.rc_code, rc.rc_name\n            FROM payment_transactions pt\n            INNER JOIN water_accounts m ON m.pkey = pt.member_id\n            LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id\n            WHERE pt.receipt_no = ?\n            LIMIT 1\n        ");
        $stmt->execute([$receiptNo]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) {
            throw new RuntimeException('Receipt not found.');
        }

        $tx['member_name'] = wbr_member_name($tx);
        $tx['member_address'] = wbr_find_member_address($pdo, (int)$tx['member_id'], $tx);
        if (array_key_exists('cash_tendered', $tx) && $tx['cash_tendered'] !== null && $tx['cash_tendered'] !== '') {
            $tx['cash_tendered'] = (float)$tx['cash_tendered'];
        } elseif (array_key_exists('tendered_amount', $tx) && $tx['tendered_amount'] !== null && $tx['tendered_amount'] !== '') {
            $tx['cash_tendered'] = (float)$tx['tendered_amount'];
        } else {
            $tx['cash_tendered'] = (float)$tx['total_paid'];
        }

        $tx['change_amount'] = array_key_exists('change_amount', $tx)
            ? (float)$tx['change_amount']
            : max(0, (float)$tx['cash_tendered'] - (float)$tx['total_paid']);

        $rawItems = [];
        if (wbr_table_exists($pdo, 'payment_transaction_items')) {
            $orderBy = 'transaction_id ASC';
            if (wbr_column_exists($pdo, 'payment_transaction_items', 'payment_transaction_item_id')) {
                $orderBy = 'payment_transaction_item_id ASC';
            } elseif (wbr_column_exists($pdo, 'payment_transaction_items', 'item_id')) {
                $orderBy = 'item_id ASC';
            } elseif (wbr_column_exists($pdo, 'payment_transaction_items', 'created_at')) {
                $orderBy = 'created_at ASC';
            }

            $itemStmt = $pdo->prepare("SELECT * FROM payment_transaction_items WHERE transaction_id = ? ORDER BY {$orderBy}");
            $itemStmt->execute([(int)$tx['transaction_id']]);
            $rawItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $items = [];
        foreach ($rawItems as $raw) {
            $items[] = wbr_fetch_receipt_item($pdo, $raw);
        }

        if (empty($items)) {
            $items[] = [
                'source_type' => (string)($tx['payment_type'] ?? 'mixed'),
                'source_id' => 0,
                'label' => strtoupper((string)($tx['payment_type'] ?? 'COLLECTION')),
                'bill_code' => '',
                'previous_reading_date' => null,
                'present_reading_date' => null,
                'previous_reading' => null,
                'present_reading' => null,
                'usage' => null,
                'bill_date' => $tx['payment_date'] ?? null,
                'due_date' => null,
                'disconnection_date' => null,
                'amount' => (float)($tx['base_amount'] ?? 0),
            ];
        }

        return ['transaction' => $tx, 'items' => $items];
    }
}

if (!function_exists('wbr_fetch_receipt_item')) {
    function wbr_fetch_receipt_item(PDO $pdo, array $raw): array
    {
        $sourceType = (string)$raw['source_type'];
        $sourceId = (int)$raw['source_id'];
        $item = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'label' => strtoupper($sourceType),
            'bill_code' => '',
            'previous_reading_date' => null,
            'present_reading_date' => null,
            'previous_reading' => null,
            'present_reading' => null,
            'usage' => null,
            'bill_date' => null,
            'due_date' => null,
            'disconnection_date' => null,
            'amount' => (float)($raw['amount'] ?? 0),
        ];

        try {
            if ($sourceType === 'sharecap') {
                // a contribution of Fixed Share Capital Billing (a deposit, not a bill)
                $item['label'] = ShareCapitalBilling::CODE_NAME;
            } elseif ($sourceType === 'water') {
                $stmt = $pdo->prepare("\n                    SELECT br.*, bp.start_date AS period_start, bp.end_date AS period_end\n                    FROM bill_readings br\n                    LEFT JOIN bill_periods bp ON bp.period_id = br.period_id\n                    WHERE br.reading_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$sourceId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $item['label'] = 'WATER FEE';
                    $item['previous_reading_date'] = $r['period_start'] ?? null;
                    $item['present_reading_date'] = $r['period_end'] ?? null;
                    $item['previous_reading'] = $r['prev_reading'] ?? null;
                    $item['present_reading'] = $r['pres_reading'] ?? null;
                    $item['usage'] = $r['consumption'] ?? null;
                    $item['bill_date'] = $r['billing_date'] ?? null;
                    $item['due_date'] = $r['due_date'] ?? null;
                    $item['disconnection_date'] = $r['disconnection_date'] ?? wbr_disconnection_date($r['due_date'] ?? null, (int)($r['disconnection_days'] ?? 0));
                    $item['amount'] = (float)($raw['amount'] ?? $r['amount_due'] ?? 0);
                }
            } elseif ($sourceType === 'onetime') {
                $stmt = $pdo->prepare("\n                    SELECT b.*, bc.code AS bill_code\n                    FROM one_time_bills b\n                    LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id\n                    WHERE b.bill_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$sourceId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $item['label'] = $r['bill_code'] ?: 'ONE-TIME BILL';
                    $item['bill_code'] = $r['bill_code'] ?? '';
                    $item['bill_date'] = $r['bill_date'] ?? null;
                    $item['due_date'] = $r['due_date'] ?? null;
                    $item['disconnection_date'] = $r['disconnection_date'] ?? wbr_disconnection_date($r['due_date'] ?? null, (int)($r['disconnection_days'] ?? 0));
                    $item['amount'] = (float)($raw['amount'] ?? $r['total_amount'] ?? 0);
                }
            } elseif ($sourceType === 'recurring') {
                $stmt = $pdo->prepare("\n                    SELECT b.*, bc.code AS bill_code\n                    FROM recurring_bills b\n                    LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id\n                    WHERE b.bill_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$sourceId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $item['label'] = $r['bill_code'] ?: 'RECURRING BILL';
                    $item['bill_code'] = $r['bill_code'] ?? '';
                    $item['bill_date'] = $r['bill_date'] ?? null;
                    $item['due_date'] = $r['due_date'] ?? null;
                    $item['disconnection_date'] = $r['disconnection_date'] ?? wbr_disconnection_date($r['due_date'] ?? null, (int)($r['disconnection_days'] ?? 0));
                    $item['amount'] = (float)($raw['amount'] ?? $r['total_amount'] ?? 0);
                }
            } elseif ($sourceType === 'installment') {
                $stmt = $pdo->prepare("\n                    SELECT s.*, ib.start_date, bc.code AS bill_code\n                    FROM installment_schedules s\n                    INNER JOIN installment_bills ib ON ib.installment_id = s.installment_id\n                    LEFT JOIN bill_codes bc ON bc.code_id = ib.bill_code_id\n                    WHERE s.schedule_id = ?\n                    LIMIT 1\n                ");
                $stmt->execute([$sourceId]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $item['label'] = ($r['bill_code'] ?: 'INSTALLMENT') . ' INSTALLMENT';
                    $item['bill_code'] = $r['bill_code'] ?? '';
                    $item['bill_date'] = $r['start_date'] ?? null;
                    $item['due_date'] = $r['due_date'] ?? null;
                    $item['disconnection_date'] = $r['disconnection_date'] ?? wbr_disconnection_date($r['due_date'] ?? null, (int)($r['disconnection_days'] ?? 0));
                    $item['amount'] = (float)($raw['amount'] ?? $r['amount'] ?? 0);
                }
            }
        } catch (Throwable $ignored) {
            /* Keep a printable receipt even if an old source row was deleted. */
        }

        return $item;
    }
}
