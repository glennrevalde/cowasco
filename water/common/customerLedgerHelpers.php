<?php
/**
 * Customer Ledger Helpers
 *
 * A customer ledger summarizes every bill connected to one customer:
 * - Water bill periods / readings
 * - One-time bills
 * - Installment contracts + schedules
 * - Recurring generated bills
 *
 * The ledger is display/print only. It does not post accounting or modify bills.
 */

if (!function_exists('cl_table_exists')) {
    function cl_table_exists(PDO $pdo, string $table): bool
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

if (!function_exists('cl_column_exists')) {
    function cl_column_exists(PDO $pdo, string $table, string $column): bool
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

if (!function_exists('cl_money_value')) {
    function cl_money_value($value): float
    {
        return round((float)($value ?? 0), 2);
    }
}

if (!function_exists('cl_money')) {
    function cl_money($value): string
    {
        return '₱ ' . number_format((float)($value ?? 0), 2);
    }
}

if (!function_exists('cl_date')) {
    function cl_date($date): string
    {
        if (!$date || $date === '0000-00-00') {
            return '—';
        }

        $time = strtotime((string)$date);
        return $time ? date('M j, Y', $time) : (string)$date;
    }
}

if (!function_exists('cl_datetime')) {
    function cl_datetime($date): string
    {
        if (!$date || $date === '0000-00-00 00:00:00') {
            return '—';
        }

        $time = strtotime((string)$date);
        return $time ? date('M j, Y h:i A', $time) : (string)$date;
    }
}

if (!function_exists('cl_status_badge_class')) {
    function cl_status_badge_class(string $status): string
    {
        $s = strtolower($status);

        if (in_array($s, ['paid', 'settled', 'closed'], true)) {
            return 'paid';
        }

        if (in_array($s, ['unpaid', 'pending', 'overdue', 'active'], true)) {
            return 'unpaid';
        }

        if (in_array($s, ['cancelled', 'canceled', 'void'], true)) {
            return 'cancelled';
        }

        return 'neutral';
    }
}

if (!function_exists('cl_fetch_member')) {
    function cl_fetch_member(PDO $pdo, int $memberId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                m.*,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS full_name,
                r.rc_code,
                r.rc_name,
                ps.size_code AS pipe_size_code,
                ps.size_label AS pipe_size_label,
                ps.diameter_inches
            FROM water_accounts m
            LEFT JOIN rate_code r ON r.rc_id = m.rc_id
            LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
            WHERE m.pkey = ?
            LIMIT 1
        ");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return null;
        }

        $member['display_name'] = trim((string)($member['full_name'] ?? ''));
        if ($member['display_name'] === ',') {
            $member['display_name'] = trim(($member['firstname'] ?? '') . ' ' . ($member['lastname'] ?? ''));
        }

        $member['contact_numbers'] = '';
        if (cl_table_exists($pdo, 'membercontactchannels')) {
            try {
                $stmt = $pdo->prepare("
                    SELECT GROUP_CONCAT(DISTINCT contactdata ORDER BY activefromdate DESC SEPARATOR ' / ')
                    FROM membercontactchannels
                    WHERE memberkey = ?
                      AND status = 'A'
                      AND (activetodate IS NULL OR activetodate >= CURDATE())
                ");
                $stmt->execute([$memberId]);
                $member['contact_numbers'] = (string)($stmt->fetchColumn() ?: '');
            } catch (Throwable $e) {}
        }

        $member['address'] = '';
        if (cl_table_exists($pdo, 'memberaddress') && cl_table_exists($pdo, 'addresses')) {
            try {
                $stmt = $pdo->prepare("
                    SELECT
                        GROUP_CONCAT(
                            DISTINCT TRIM(CONCAT_WS(' ',
                                NULLIF(a.housebldg, ''),
                                NULLIF(a.street, ''),
                                NULLIF(a.other_address_detail, ''),
                                NULLIF(c.city, ''),
                                NULLIF(c.province, '')
                            ))
                            SEPARATOR ' | '
                        ) AS full_address
                    FROM memberaddress ma
                    LEFT JOIN addresses a ON a.pkey = ma.address_key AND a.status = 'A'
                    LEFT JOIN cities c ON c.pkey = a.city_id AND c.status = 'A'
                    WHERE ma.member_key = ?
                      AND ma.status = 'A'
                      AND (ma.dtaddressto IS NULL OR ma.dtaddressto = '0001-01-01' OR ma.dtaddressto >= CURDATE())
                ");
                $stmt->execute([$memberId]);
                $member['address'] = (string)($stmt->fetchColumn() ?: '');
            } catch (Throwable $e) {}
        }

        return $member;
    }
}

if (!function_exists('cl_add_record')) {
    function cl_add_record(array &$records, array $record): void
    {
        $record['bill_date'] = $record['bill_date'] ?? null;
        $record['due_date'] = $record['due_date'] ?? null;
        $record['period_label'] = $record['period_label'] ?? '—';
        $record['source_type'] = $record['source_type'] ?? 'Other';
        $record['source_id'] = (int)($record['source_id'] ?? 0);
        $record['description'] = $record['description'] ?? '';
        $record['details'] = $record['details'] ?? [];
        $record['gross_amount'] = cl_money_value($record['gross_amount'] ?? $record['amount'] ?? 0);
        $record['discount_amount'] = cl_money_value($record['discount_amount'] ?? 0);
        $record['amount'] = cl_money_value($record['amount'] ?? 0);
        $record['paid_amount'] = cl_money_value($record['paid_amount'] ?? 0);
        $record['compromise_penalty'] = cl_money_value($record['compromise_penalty'] ?? 0);
        $record['balance'] = cl_money_value($record['balance'] ?? max(0, $record['amount'] - $record['paid_amount']));
        $record['status'] = $record['status'] ?? ($record['balance'] <= 0 ? 'Paid' : 'Unpaid');
        $record['sort_date'] = $record['bill_date'] ?: ($record['due_date'] ?: '1900-01-01');

        $records[] = $record;
    }
}

if (!function_exists('cl_fetch_water_records')) {
    function cl_fetch_water_records(PDO $pdo, int $memberId): array
    {
        if (!cl_table_exists($pdo, 'bill_readings')) {
            return [];
        }

        $records = [];

        try {
            $stmt = $pdo->prepare("
                SELECT
                    br.*,
                    bp.bp_code,
                    bp.start_date,
                    bp.end_date
                FROM bill_readings br
                LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
                WHERE br.member_id = ?
                ORDER BY COALESCE(bp.start_date, br.created_at, br.updated_at) ASC, br.reading_id ASC
            ");
            $stmt->execute([$memberId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isBilled = (int)($row['is_billed'] ?? 0) === 1;
                $amount = cl_money_value($row['amount_due'] ?? 0);
                $paidAmount = min($amount, cl_money_value($row['amount_paid'] ?? 0));
                $balance = max(0, $amount - $paidAmount);
                $isPaid = $isBilled && $balance <= 0.00001;

                if (!$isBilled) {
                    $status = 'Reading Encoded';
                } elseif ($isPaid) {
                    $status = 'Paid';
                } elseif ($paidAmount > 0) {
                    $status = 'Partially Paid';
                } else {
                    $status = (!empty($row['due_date']) && date('Y-m-d') > $row['due_date']) ? 'Overdue' : 'Unpaid';
                }

                cl_add_record($records, [
                    'source_type' => 'Water Bill',
                    'source_id' => (int)$row['reading_id'],
                    'period_label' => $row['bp_code'] ?: ('Period #' . ($row['period_id'] ?? '')),
                    'bill_date' => $row['billing_date'] ?? null,
                    'due_date' => $row['due_date'] ?: ($row['end_date'] ?? null),
                    'description' => 'Water consumption billing',
                    'gross_amount' => $amount,
                    'discount_amount' => 0,
                    'amount' => $amount,
                    'paid_amount' => $isBilled ? $paidAmount : 0,
                    'balance' => $isBilled ? $balance : 0,
                    'status' => $status,
                    'details' => [
                        'Reading Period: ' . cl_date($row['start_date'] ?? null) . ' to ' . cl_date($row['end_date'] ?? null),
                        'Previous Reading: ' . number_format((int)round((float)($row['prev_reading'] ?? 0))),
                        'Present Reading: ' . number_format((int)round((float)($row['pres_reading'] ?? 0))),
                        'Consumption: ' . number_format((int)round((float)($row['consumption'] ?? 0))) . ' m³',
                        'Entry Mode: ' . ($row['entry_mode'] ?? '—'),
                        'Encoded By: ' . ($row['encoded_by'] ?? '—'),
                        'Billing JV: ' . ($row['billing_journal_voucher_id'] ?? '—'),
                    ],
                ]);
            }
        } catch (Throwable $e) {
            cl_add_record($records, [
                'source_type' => 'Water Bill',
                'source_id' => 0,
                'description' => 'Unable to load water bills: ' . $e->getMessage(),
                'status' => 'Error',
            ]);
        }

        return $records;
    }
}

if (!function_exists('cl_fetch_one_time_records')) {
    function cl_fetch_one_time_records(PDO $pdo, int $memberId): array
    {
        if (!cl_table_exists($pdo, 'one_time_bills')) {
            return [];
        }

        $records = [];
        $hasGross = cl_column_exists($pdo, 'one_time_bills', 'gross_amount');
        $hasDiscount = cl_column_exists($pdo, 'one_time_bills', 'discount_amount');
        $hasGlobalRef = cl_column_exists($pdo, 'one_time_bills', 'global_ref_number');
        $hasBillDate = cl_column_exists($pdo, 'one_time_bills', 'bill_date');

        $grossSelect = $hasGross ? "b.gross_amount" : "b.total_amount AS gross_amount";
        $discountSelect = $hasDiscount ? "b.discount_amount" : "0.00 AS discount_amount";
        $globalRefSelect = $hasGlobalRef ? "b.global_ref_number" : "NULL AS global_ref_number";
        $billDateSelect = $hasBillDate ? "b.bill_date" : "b.created_at AS bill_date";

        try {
            $stmt = $pdo->prepare("
                SELECT
                    b.bill_id,
                    {$globalRefSelect},
                    {$billDateSelect},
                    b.due_date,
                    b.total_amount,
                    b.amount_paid,
                    {$grossSelect},
                    {$discountSelect},
                    b.status,
                    b.quantity,
                    b.inventory_unit_cost,
                    b.inventory_total_cost,
                    b.created_at,
                    bc.code,
                    bc.description,
                    bc.type AS bill_code_type
                FROM one_time_bills b
                LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
                WHERE b.member_id = ?
                ORDER BY COALESCE({$billDateSelect}, b.created_at) ASC, b.bill_id ASC
            ");
            $stmt->execute([$memberId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $amount = cl_money_value($row['total_amount']);
                $paid = cl_money_value($row['amount_paid'] ?? 0);
                $balance = max(0, $amount - $paid);
                $status = strtolower((string)($row['status'] ?? ''));

                if ($status === 'paid' || $balance <= 0) {
                    $displayStatus = 'Paid';
                    $balance = 0;
                } elseif ($status === 'cancelled' || $status === 'canceled') {
                    $displayStatus = 'Cancelled';
                } else {
                    $displayStatus = (!empty($row['due_date']) && date('Y-m-d') > $row['due_date']) ? 'Overdue' : 'Unpaid';
                }

                cl_add_record($records, [
                    'source_type' => 'One-Time Bill',
                    'source_id' => (int)$row['bill_id'],
                    'period_label' => '—',
                    'bill_date' => $row['bill_date'] ?? $row['created_at'],
                    'due_date' => $row['due_date'] ?? null,
                    'description' => trim(($row['code'] ?? 'One-Time Bill') . ' — ' . ($row['description'] ?? '')),
                    'gross_amount' => $row['gross_amount'],
                    'discount_amount' => $row['discount_amount'],
                    'amount' => $amount,
                    'paid_amount' => $paid,
                    'balance' => $balance,
                    'status' => $displayStatus,
                    'details' => [
                        'Global Ref: ' . ($row['global_ref_number'] ?? '—'),
                        'Bill Code Type: ' . ($row['bill_code_type'] ?? '—'),
                        'Quantity: ' . number_format((float)($row['quantity'] ?? 1), 2),
                        'Inventory Cost: ' . cl_money($row['inventory_total_cost'] ?? 0),
                    ],
                ]);
            }
        } catch (Throwable $e) {
            cl_add_record($records, [
                'source_type' => 'One-Time Bill',
                'source_id' => 0,
                'description' => 'Unable to load one-time bills: ' . $e->getMessage(),
                'status' => 'Error',
            ]);
        }

        return $records;
    }
}

if (!function_exists('cl_fetch_installment_records')) {
    function cl_fetch_installment_records(PDO $pdo, int $memberId): array
    {
        if (!cl_table_exists($pdo, 'installment_bills')) {
            return [];
        }

        $records = [];
        $hasGross = cl_column_exists($pdo, 'installment_bills', 'gross_amount');
        $hasDiscount = cl_column_exists($pdo, 'installment_bills', 'discount_amount');
        $hasGlobalRef = cl_column_exists($pdo, 'installment_bills', 'global_ref_number');

        $grossSelect = $hasGross ? "i.gross_amount" : "i.total_amount AS gross_amount";
        $discountSelect = $hasDiscount ? "i.discount_amount" : "0.00 AS discount_amount";
        $globalRefSelect = $hasGlobalRef ? "i.global_ref_number" : "NULL AS global_ref_number";

        try {
            $stmt = $pdo->prepare("
                SELECT
                    i.installment_id,
                    {$globalRefSelect},
                    i.payment_mode,
                    i.total_amount,
                    {$grossSelect},
                    {$discountSelect},
                    i.term,
                    i.amortization_type,
                    i.start_date,
                    i.status,
                    i.quantity,
                    i.inventory_total_cost,
                    i.created_at,
                    bc.code,
                    bc.description,
                    bc.type AS bill_code_type
                FROM installment_bills i
                LEFT JOIN bill_codes bc ON bc.code_id = i.bill_code_id
                WHERE i.member_id = ?
                ORDER BY COALESCE(i.start_date, i.created_at) ASC, i.installment_id ASC
            ");
            $stmt->execute([$memberId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $schStmt = $pdo->prepare("
                    SELECT schedule_id, due_date, amount, COALESCE(amount_paid,0) AS amount_paid, status, paid_at, payment_reference
                    FROM installment_schedules
                    WHERE installment_id = ?
                    ORDER BY due_date ASC, schedule_id ASC
                ");
                $schStmt->execute([(int)$row['installment_id']]);
                $schedules = $schStmt->fetchAll(PDO::FETCH_ASSOC);

                $paidAmount = 0.00;
                $pendingAmount = 0.00;
                $scheduleDetails = [];

                foreach ($schedules as $s) {
                    $amt = cl_money_value($s['amount']);
                    $schedulePaid = min($amt, cl_money_value($s['amount_paid'] ?? 0));
                    $scheduleBalance = max(0, $amt - $schedulePaid);
                    $paidAmount += $schedulePaid;
                    $pendingAmount += $scheduleBalance;

                    $scheduleState = $scheduleBalance <= 0.00001
                        ? 'Paid'
                        : ($schedulePaid > 0 ? 'Partially Paid' : ucfirst((string)($s['status'] ?? 'pending')));
                    $scheduleDetails[] = '#' . $s['schedule_id'] . ' — Due ' . cl_date($s['due_date']) . ' — ' . cl_money($amt)
                        . ' — Paid ' . cl_money($schedulePaid) . ' — Balance ' . cl_money($scheduleBalance) . ' — ' . $scheduleState;
                }

                $amount = cl_money_value($row['total_amount']);
                $paidAmount = min($amount, $paidAmount);
                $pendingAmount = max(0, $amount - $paidAmount);
                $parentState = strtolower((string)$row['status']);
                $status = $parentState === 'cancelled'
                    ? 'Cancelled'
                    : ($pendingAmount <= 0.00001 && count($schedules) > 0
                        ? 'Paid'
                        : ($paidAmount > 0 ? 'Partially Paid' : 'Unpaid'));

                cl_add_record($records, [
                    'source_type' => 'Installment',
                    'source_id' => (int)$row['installment_id'],
                    'period_label' => 'Installment Plan',
                    'bill_date' => $row['start_date'] ?? $row['created_at'],
                    'due_date' => $schedules[0]['due_date'] ?? ($row['start_date'] ?? null),
                    'description' => trim(($row['code'] ?? 'Installment') . ' — ' . ($row['description'] ?? '')),
                    'gross_amount' => $row['gross_amount'],
                    'discount_amount' => $row['discount_amount'],
                    'amount' => $amount,
                    'paid_amount' => $paidAmount,
                    'balance' => max(0, $pendingAmount),
                    'status' => $status,
                    'details' => array_merge([
                        'Global Ref: ' . ($row['global_ref_number'] ?? '—'),
                        'Payment Mode: ' . ($row['payment_mode'] ?? '—'),
                        'Term: ' . ($row['term'] ?? '—'),
                        'Amortization: ' . ($row['amortization_type'] ?? '—'),
                        'Bill Code Type: ' . ($row['bill_code_type'] ?? '—'),
                    ], $scheduleDetails),
                ]);
            }
        } catch (Throwable $e) {
            cl_add_record($records, [
                'source_type' => 'Installment',
                'source_id' => 0,
                'description' => 'Unable to load installment bills: ' . $e->getMessage(),
                'status' => 'Error',
            ]);
        }

        return $records;
    }
}

if (!function_exists('cl_fetch_recurring_records')) {
    function cl_fetch_recurring_records(PDO $pdo, int $memberId): array
    {
        if (!cl_table_exists($pdo, 'recurring_bills')) {
            return [];
        }

        $records = [];
        $hasGross = cl_column_exists($pdo, 'recurring_bills', 'gross_amount');
        $hasDiscount = cl_column_exists($pdo, 'recurring_bills', 'discount_amount');
        $hasGlobalRef = cl_column_exists($pdo, 'recurring_bills', 'global_ref_number');
        $hasPlanId = cl_column_exists($pdo, 'recurring_bills', 'plan_id');

        $grossSelect = $hasGross ? "rb.gross_amount" : "rb.total_amount AS gross_amount";
        $discountSelect = $hasDiscount ? "rb.discount_amount" : "0.00 AS discount_amount";
        $globalRefSelect = $hasGlobalRef ? "rb.global_ref_number" : "NULL AS global_ref_number";

        /*
         * Important:
         * Your recurring_plans table does NOT have plan_name.
         * It stores plan details through bill_code_id, amount, frequency, first_billing_date, and term_days.
         * So the ledger must not query rp.plan_name.
         */
        $planJoin = $hasPlanId
            ? "LEFT JOIN recurring_plans rp ON rp.plan_id = rb.plan_id"
            : "LEFT JOIN recurring_subscriptions rs ON rs.subscription_id = rb.subscription_id
               LEFT JOIN recurring_plans rp ON rp.plan_id = rs.plan_id";

        try {
            $stmt = $pdo->prepare("
                SELECT
                    rb.bill_id,
                    {$globalRefSelect},
                    rb.subscription_id,
                    rb.bill_code_id,
                    rb.billing_period_start,
                    rb.billing_period_end,
                    rb.bill_date,
                    rb.due_date,
                    rb.term_days,
                    rb.total_amount,
                    {$grossSelect},
                    {$discountSelect},
                    rb.amount_paid,
                    rb.balance,
                    rb.status,
                    rb.created_at,
                    bc.code,
                    bc.description,
                    rp.frequency,
                    rp.amount AS plan_amount
                FROM recurring_bills rb
                LEFT JOIN bill_codes bc ON bc.code_id = rb.bill_code_id
                {$planJoin}
                WHERE rb.member_id = ?
                ORDER BY COALESCE(rb.bill_date, rb.billing_period_start, rb.created_at) ASC, rb.bill_id ASC
            ");
            $stmt->execute([$memberId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $amount = cl_money_value($row['total_amount']);
                $paid = cl_money_value($row['amount_paid'] ?? 0);
                $balance = cl_money_value($row['balance'] ?? max(0, $amount - $paid));
                $rawStatus = strtolower((string)($row['status'] ?? ''));

                if ($rawStatus === 'paid' || $balance <= 0) {
                    $status = 'Paid';
                    $balance = 0;
                } elseif ($rawStatus === 'overdue' || (!empty($row['due_date']) && date('Y-m-d') > $row['due_date'])) {
                    $status = 'Overdue';
                } else {
                    $status = 'Unpaid';
                }

                $billCodeName = trim((string)($row['code'] ?? 'Recurring'));
                $billCodeDesc = trim((string)($row['description'] ?? ''));
                $frequency = trim((string)($row['frequency'] ?? ''));

                $descParts = array_filter([
                    $billCodeName,
                    $billCodeDesc,
                    $frequency ? $frequency . ' Recurring Charge' : 'Recurring Charge',
                ], fn($v) => trim((string)$v) !== '');

                cl_add_record($records, [
                    'source_type' => 'Recurring',
                    'source_id' => (int)$row['bill_id'],
                    'period_label' => cl_date($row['billing_period_start']) . ' - ' . cl_date($row['billing_period_end']),
                    'bill_date' => $row['bill_date'] ?? $row['created_at'],
                    'due_date' => $row['due_date'] ?? null,
                    'description' => implode(' — ', $descParts),
                    'gross_amount' => $row['gross_amount'],
                    'discount_amount' => $row['discount_amount'],
                    'amount' => $amount,
                    'paid_amount' => $paid,
                    'balance' => $balance,
                    'status' => $status,
                    'details' => [
                        'Global Ref: ' . ($row['global_ref_number'] ?? '—'),
                        'Subscription ID: ' . ($row['subscription_id'] ?? '—'),
                        'Frequency: ' . ($row['frequency'] ?? '—'),
                        'Plan Gross Amount: ' . cl_money($row['plan_amount'] ?? 0),
                        'Term Days: ' . ($row['term_days'] ?? '—'),
                    ],
                ]);
            }
        } catch (Throwable $e) {
            /*
             * Do not throw a full-page fatal error. Show a readable row instead,
             * so the rest of the ledger can still render.
             */
            cl_add_record($records, [
                'source_type' => 'Recurring',
                'source_id' => 0,
                'description' => 'Unable to load recurring bills: ' . $e->getMessage(),
                'status' => 'Error',
            ]);
        }

        return $records;
    }
}


if (!function_exists('cl_fetch_customer_ledger')) {
    function cl_fetch_customer_ledger(PDO $pdo, int $memberId): array
    {
        $member = cl_fetch_member($pdo, $memberId);

        if (!$member) {
            return [
                'member' => null,
                'records' => [],
                'summary' => [
                    'total_billed' => 0,
                    'total_paid' => 0,
                    'total_balance' => 0,
                    'total_compromise_penalty' => 0,
                    'paid_count' => 0,
                    'unpaid_count' => 0,
                    'record_count' => 0,
                ],
            ];
        }

        $records = array_merge(
            cl_fetch_water_records($pdo, $memberId),
            cl_fetch_one_time_records($pdo, $memberId),
            cl_fetch_installment_records($pdo, $memberId),
            cl_fetch_recurring_records($pdo, $memberId)
        );

        usort($records, function ($a, $b) {
            $dateCompare = strcmp((string)$a['sort_date'], (string)$b['sort_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string)$a['source_type'], (string)$b['source_type']);
        });

        $summary = [
            'total_billed' => 0,
            'total_paid' => 0,
            'total_balance' => 0,
            'total_compromise_penalty' => 0,
            'paid_count' => 0,
            'unpaid_count' => 0,
            'record_count' => count($records),
        ];

        foreach ($records as $record) {
            $summary['total_billed'] += cl_money_value($record['amount']);
            $summary['total_paid'] += cl_money_value($record['paid_amount']);
            $summary['total_balance'] += cl_money_value($record['balance']);
            $summary['total_compromise_penalty'] += cl_money_value($record['compromise_penalty'] ?? 0);

            $class = cl_status_badge_class((string)$record['status']);
            if ($class === 'paid') {
                $summary['paid_count']++;
            } elseif ($class === 'unpaid') {
                $summary['unpaid_count']++;
            }
        }

        if (cl_table_exists($pdo, 'payment_transactions')) {
            try {
                $penStmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(penalty_amount,0) + COALESCE(compromise_amount,0)), 0) FROM payment_transactions WHERE member_id = ? AND COALESCE(transaction_status,'active')='active'");
                $penStmt->execute([$memberId]);
                $summary['total_compromise_penalty'] = cl_money_value((float)$summary['total_compromise_penalty'] + (float)$penStmt->fetchColumn());
            } catch (Throwable $e) {}
        }

        $summary['total_billed'] = cl_money_value($summary['total_billed']);
        $summary['total_paid'] = cl_money_value($summary['total_paid']);
        $summary['total_balance'] = cl_money_value($summary['total_balance']);
        $summary['total_compromise_penalty'] = cl_money_value($summary['total_compromise_penalty'] ?? 0);

        return [
            'member' => $member,
            'records' => $records,
            'summary' => $summary,
        ];
    }
}

/*
 * Printable Member Ledger report helpers.
 *
 * This report is period-based and follows the cooperative's physical ledger:
 * one row per billing period, water-reading details, optional bill-code columns,
 * manual Penalty/Compromise, receipt references, and a complete row total.
 */
if (!function_exists('cl_report_water_bill_code_id')) {
    function cl_report_water_bill_code_id(PDO $pdo): int
    {
        if (cl_table_exists($pdo, 'water_billing_settings')) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_billing_bill_code_id' LIMIT 1");
                $stmt->execute();
                $id = (int)$stmt->fetchColumn();
                if ($id > 0) {
                    return $id;
                }
            } catch (Throwable $e) {
                // Fall through to bill-code-name detection.
            }
        }

        if (cl_table_exists($pdo, 'bill_codes')) {
            try {
                $stmt = $pdo->query("
                    SELECT code_id
                    FROM bill_codes
                    WHERE UPPER(TRIM(code)) IN ('WATER BILL', 'WATER FEE')
                       OR UPPER(TRIM(description)) IN ('WATER BILL', 'WATER FEE')
                    ORDER BY CASE WHEN UPPER(TRIM(code)) = 'WATER BILL' THEN 0 ELSE 1 END, code_id
                    LIMIT 1
                ");
                return (int)($stmt->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                return 0;
            }
        }

        return 0;
    }
}

if (!function_exists('cl_report_bill_codes')) {
    function cl_report_bill_codes(PDO $pdo): array
    {
        $waterCodeId = cl_report_water_bill_code_id($pdo);
        $codes = [];

        if (cl_table_exists($pdo, 'bill_codes')) {
            try {
                $stmt = $pdo->query("
                    SELECT code_id, code, description, type
                    FROM bill_codes
                    ORDER BY UPPER(TRIM(code)), code_id
                ");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $id = (int)$row['code_id'];
                    $code = trim((string)($row['code'] ?? ''));
                    $description = trim((string)($row['description'] ?? ''));
                    $codes[$id] = [
                        'code_id' => $id,
                        'code' => $code !== '' ? $code : ('Bill Code #' . $id),
                        'description' => $description,
                        'type' => trim((string)($row['type'] ?? '')),
                        'is_water' => $id === $waterCodeId,
                    ];
                }
            } catch (Throwable $e) {
                $codes = [];
            }
        }

        if ($waterCodeId > 0 && isset($codes[$waterCodeId])) {
            $codes[$waterCodeId]['code'] = 'WATER BILL';
            $codes[$waterCodeId]['is_water'] = true;
        }

        return [
            'water_bill_code_id' => $waterCodeId,
            'codes' => $codes,
        ];
    }
}

if (!function_exists('cl_report_periods')) {
    function cl_report_periods(PDO $pdo): array
    {
        if (!cl_table_exists($pdo, 'bill_periods')) {
            return [];
        }

        try {
            $stmt = $pdo->query("
                SELECT period_id, bp_code, start_date, end_date, status
                FROM bill_periods
                ORDER BY start_date ASC, period_id ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cl_report_members')) {
    function cl_report_members(PDO $pdo): array
    {
        if (!cl_table_exists($pdo, 'water_accounts')) {
            return [];
        }

        try {
            $stmt = $pdo->query("
                SELECT
                    pkey,
                    account_number,
                    lastname,
                    firstname,
                    middlename,
                    zone,
                    meter_sequence,
                    status
                FROM water_accounts
                WHERE COALESCE(status, 'A') = 'A'
                ORDER BY lastname ASC, firstname ASC, pkey ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('cl_report_select_periods')) {
    function cl_report_select_periods(array $periods, int $fromPeriodId, int $toPeriodId): array
    {
        if (!$periods) {
            return [];
        }

        $positions = [];
        foreach ($periods as $index => $period) {
            $positions[(int)$period['period_id']] = $index;
        }

        $fromIndex = $fromPeriodId > 0 && isset($positions[$fromPeriodId]) ? $positions[$fromPeriodId] : 0;
        $toIndex = $toPeriodId > 0 && isset($positions[$toPeriodId]) ? $positions[$toPeriodId] : count($periods) - 1;

        if ($fromIndex > $toIndex) {
            [$fromIndex, $toIndex] = [$toIndex, $fromIndex];
        }

        return array_values(array_slice($periods, $fromIndex, ($toIndex - $fromIndex) + 1));
    }
}

if (!function_exists('cl_report_period_for_date')) {
    function cl_report_period_for_date(array $selectedPeriods, ?string $dateValue): ?int
    {
        if (!$dateValue) {
            return null;
        }

        $time = strtotime($dateValue);
        if (!$time) {
            return null;
        }
        $date = date('Y-m-d', $time);

        foreach ($selectedPeriods as $period) {
            $start = !empty($period['start_date']) ? date('Y-m-d', strtotime((string)$period['start_date'])) : '';
            $end = !empty($period['end_date']) ? date('Y-m-d', strtotime((string)$period['end_date'])) : '';
            if ($start !== '' && $end !== '' && $date >= $start && $date <= $end) {
                return (int)$period['period_id'];
            }
        }

        return null;
    }
}

if (!function_exists('cl_report_new_period_row')) {
    function cl_report_new_period_row(array $period): array
    {
        return [
            'period_id' => (int)$period['period_id'],
            'period_code' => (string)($period['bp_code'] ?? ''),
            'period_start' => (string)($period['start_date'] ?? ''),
            'period_end' => (string)($period['end_date'] ?? ''),
            'period_sort' => (string)($period['start_date'] ?? ''),
            'present_reading_date' => (string)($period['end_date'] ?? ''),
            'previous_reading_date' => (string)($period['start_date'] ?? ''),
            'present_reading' => null,
            'previous_reading' => null,
            'usage' => null,
            'charges' => [],
            'penalty' => 0.00,
            'compromise' => 0.00,
            'receipt_numbers' => [],
            'payment_dates' => [],
            'has_activity' => false,
        ];
    }
}

if (!function_exists('cl_report_add_charge')) {
    function cl_report_add_charge(array &$row, int $billCodeId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $row['charges'][$billCodeId] = cl_money_value(($row['charges'][$billCodeId] ?? 0) + $amount);
        $row['has_activity'] = true;
    }
}

if (!function_exists('cl_report_fetch_member_ledger')) {
    function cl_report_fetch_member_ledger(
        PDO $pdo,
        int $memberId,
        int $fromPeriodId = 0,
        int $toPeriodId = 0,
        array $requestedBillCodeIds = []
    ): array {
        $member = cl_fetch_member($pdo, $memberId);
        $codeInfo = cl_report_bill_codes($pdo);
        $waterCodeId = (int)$codeInfo['water_bill_code_id'];
        $billCodes = $codeInfo['codes'];
        $allPeriods = cl_report_periods($pdo);
        $selectedPeriods = cl_report_select_periods($allPeriods, $fromPeriodId, $toPeriodId);

        if (!$member) {
            return [
                'member' => null,
                'bill_codes' => $billCodes,
                'water_bill_code_id' => $waterCodeId,
                'selected_bill_code_ids' => [],
                'periods' => $allPeriods,
                'selected_periods' => $selectedPeriods,
                'rows' => [],
                'totals' => [],
            ];
        }

        $validCodeIds = array_map('intval', array_keys($billCodes));
        $requested = array_values(array_unique(array_filter(array_map('intval', $requestedBillCodeIds), static fn(int $id): bool => $id > 0)));
        $selectedCodeIds = array_values(array_intersect($requested, $validCodeIds));
        if ($waterCodeId > 0 && !in_array($waterCodeId, $selectedCodeIds, true)) {
            array_unshift($selectedCodeIds, $waterCodeId);
        }
        if (!$selectedCodeIds) {
            $selectedCodeIds = $validCodeIds;
            if ($waterCodeId > 0 && !in_array($waterCodeId, $selectedCodeIds, true)) {
                array_unshift($selectedCodeIds, $waterCodeId);
            }
        }

        $rows = [];
        foreach ($selectedPeriods as $period) {
            $rows[(int)$period['period_id']] = cl_report_new_period_row($period);
        }

        $sourcePeriodMap = [];
        $periodIds = array_keys($rows);
        $periodSql = $periodIds ? implode(',', array_fill(0, count($periodIds), '?')) : '';

        if ($periodIds && cl_table_exists($pdo, 'bill_readings')) {
            try {
                $sql = "
                    SELECT
                        br.reading_id,
                        br.period_id,
                        br.prev_reading,
                        br.pres_reading,
                        br.consumption,
                        br.amount_due,
                        br.is_billed,
                        br.bill_lifecycle_status
                    FROM bill_readings br
                    WHERE br.member_id = ?
                      AND br.period_id IN ({$periodSql})
                      AND br.is_billed = 1
                      AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
                    ORDER BY br.period_id ASC, br.reading_id ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$memberId], $periodIds));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bill) {
                    $periodId = (int)$bill['period_id'];
                    if (!isset($rows[$periodId])) {
                        continue;
                    }
                    $rows[$periodId]['present_reading'] = (int)$bill['pres_reading'];
                    $rows[$periodId]['previous_reading'] = (int)$bill['prev_reading'];
                    $rows[$periodId]['usage'] = (int)$bill['consumption'];
                    cl_report_add_charge($rows[$periodId], $waterCodeId, (float)$bill['amount_due']);
                    $sourcePeriodMap['water:' . (int)$bill['reading_id']] = $periodId;
                }
            } catch (Throwable $e) {
                // The report remains available even when one source table is unavailable.
            }
        }

        if (cl_table_exists($pdo, 'one_time_bills')) {
            try {
                $hasBillDate = cl_column_exists($pdo, 'one_time_bills', 'bill_date');
                $billDateExpr = $hasBillDate ? 'b.bill_date' : 'b.created_at';
                $stmt = $pdo->prepare("
                    SELECT b.bill_id, b.bill_code_id, {$billDateExpr} AS activity_date, b.total_amount
                    FROM one_time_bills b
                    WHERE b.member_id = ?
                      AND LOWER(COALESCE(b.status, 'unpaid')) NOT IN ('cancelled','canceled','deleted')
                      AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                    ORDER BY activity_date ASC, b.bill_id ASC
                ");
                $stmt->execute([$memberId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bill) {
                    $periodId = cl_report_period_for_date($selectedPeriods, $bill['activity_date'] ?? null);
                    if ($periodId === null || !isset($rows[$periodId])) {
                        continue;
                    }
                    $codeId = (int)$bill['bill_code_id'];
                    cl_report_add_charge($rows[$periodId], $codeId, (float)$bill['total_amount']);
                    $sourcePeriodMap['onetime:' . (int)$bill['bill_id']] = $periodId;
                }
            } catch (Throwable $e) {
                // Continue with the remaining sources.
            }
        }

        if (cl_table_exists($pdo, 'installment_schedules') && cl_table_exists($pdo, 'installment_bills')) {
            try {
                $stmt = $pdo->prepare("
                    SELECT s.schedule_id, s.due_date AS activity_date, s.amount, b.bill_code_id
                    FROM installment_schedules s
                    INNER JOIN installment_bills b ON b.installment_id = s.installment_id
                    WHERE b.member_id = ?
                      AND LOWER(COALESCE(b.status, 'active')) NOT IN ('cancelled','canceled','deleted')
                      AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                    ORDER BY s.due_date ASC, s.schedule_id ASC
                ");
                $stmt->execute([$memberId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $schedule) {
                    $periodId = cl_report_period_for_date($selectedPeriods, $schedule['activity_date'] ?? null);
                    if ($periodId === null || !isset($rows[$periodId])) {
                        continue;
                    }
                    $codeId = (int)$schedule['bill_code_id'];
                    cl_report_add_charge($rows[$periodId], $codeId, (float)$schedule['amount']);
                    $sourcePeriodMap['installment:' . (int)$schedule['schedule_id']] = $periodId;
                }
            } catch (Throwable $e) {
                // Continue with the remaining sources.
            }
        }

        if (cl_table_exists($pdo, 'recurring_bills')) {
            try {
                $stmt = $pdo->prepare("
                    SELECT rb.bill_id, rb.bill_code_id,
                           COALESCE(rb.bill_date, rb.billing_period_end, rb.created_at) AS activity_date,
                           rb.total_amount
                    FROM recurring_bills rb
                    WHERE rb.member_id = ?
                      AND LOWER(COALESCE(rb.status, 'unpaid')) NOT IN ('cancelled','canceled','deleted')
                      AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted'
                    ORDER BY activity_date ASC, rb.bill_id ASC
                ");
                $stmt->execute([$memberId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $bill) {
                    $periodId = cl_report_period_for_date($selectedPeriods, $bill['activity_date'] ?? null);
                    if ($periodId === null || !isset($rows[$periodId])) {
                        continue;
                    }
                    $codeId = (int)$bill['bill_code_id'];
                    cl_report_add_charge($rows[$periodId], $codeId, (float)$bill['total_amount']);
                    $sourcePeriodMap['recurring:' . (int)$bill['bill_id']] = $periodId;
                }
            } catch (Throwable $e) {
                // Continue with payment references.
            }
        }

        if (cl_table_exists($pdo, 'payment_transactions') && cl_table_exists($pdo, 'payment_transaction_items')) {
            try {
                $stmt = $pdo->prepare("
                    SELECT
                        pt.transaction_id,
                        pt.receipt_no,
                        COALESCE(pt.business_date, DATE(pt.payment_date)) AS paid_date,
                        pt.penalty_amount,
                        pt.compromise_amount,
                        pti.source_type,
                        pti.source_id
                    FROM payment_transactions pt
                    LEFT JOIN payment_transaction_items pti
                      ON pti.transaction_id = pt.transaction_id
                     AND COALESCE(pti.allocation_status, 'active') = 'active'
                     AND pti.source_type <> 'sharecap'
                    WHERE pt.member_id = ?
                      AND COALESCE(pt.transaction_status, 'active') = 'active'
                      AND pt.payment_type <> 'sharecap'
                    ORDER BY COALESCE(pt.business_date, DATE(pt.payment_date)) ASC,
                             pt.transaction_id ASC,
                             pti.item_id ASC
                ");
                $stmt->execute([$memberId]);
                $transactions = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
                    $transactionId = (int)$payment['transaction_id'];
                    if (!isset($transactions[$transactionId])) {
                        $transactions[$transactionId] = [
                            'receipt_no' => trim((string)$payment['receipt_no']),
                            'paid_date' => (string)$payment['paid_date'],
                            'penalty' => (float)$payment['penalty_amount'],
                            'compromise' => (float)$payment['compromise_amount'],
                            'period_ids' => [],
                        ];
                    }
                    $sourceType = trim((string)($payment['source_type'] ?? ''));
                    $sourceId = (int)($payment['source_id'] ?? 0);
                    if ($sourceType !== '' && $sourceId > 0) {
                        $key = $sourceType . ':' . $sourceId;
                        if (isset($sourcePeriodMap[$key])) {
                            $transactions[$transactionId]['period_ids'][] = (int)$sourcePeriodMap[$key];
                        }
                    }
                }

                foreach ($transactions as $payment) {
                    $periodList = array_values(array_unique(array_filter(
                        array_map('intval', $payment['period_ids']),
                        static fn(int $periodId): bool => isset($rows[$periodId])
                    )));

                    if (!$periodList) {
                        $fallbackPeriod = cl_report_period_for_date($selectedPeriods, $payment['paid_date'] ?? null);
                        if ($fallbackPeriod !== null && isset($rows[$fallbackPeriod])) {
                            $periodList = [$fallbackPeriod];
                        }
                    }
                    if (!$periodList) {
                        continue;
                    }

                    foreach ($periodList as $periodId) {
                        if ($payment['receipt_no'] !== '') {
                            $rows[$periodId]['receipt_numbers'][$payment['receipt_no']] = $payment['receipt_no'];
                        }
                        if (!empty($payment['paid_date'])) {
                            $rows[$periodId]['payment_dates'][$payment['paid_date']] = $payment['paid_date'];
                        }
                        $rows[$periodId]['has_activity'] = true;
                    }

                    usort($periodList, static function (int $a, int $b) use ($rows): int {
                        return strcmp((string)$rows[$a]['period_sort'], (string)$rows[$b]['period_sort']);
                    });
                    $chargePeriodId = (int)end($periodList);
                    $rows[$chargePeriodId]['penalty'] = cl_money_value($rows[$chargePeriodId]['penalty'] + (float)$payment['penalty']);
                    $rows[$chargePeriodId]['compromise'] = cl_money_value($rows[$chargePeriodId]['compromise'] + (float)$payment['compromise']);
                    if ((float)$payment['penalty'] > 0 || (float)$payment['compromise'] > 0) {
                        $rows[$chargePeriodId]['has_activity'] = true;
                    }
                }
            } catch (Throwable $e) {
                // Receipt references are supplementary; billing rows remain printable.
            }
        }

        $displayRows = [];
        $totals = [
            'charges' => [],
            'penalty' => 0.00,
            'compromise' => 0.00,
            'other_fees' => 0.00,
            'total_amount' => 0.00,
        ];

        foreach ($rows as $row) {
            if (!$row['has_activity']) {
                continue;
            }

            ksort($row['receipt_numbers']);
            ksort($row['payment_dates']);
            $row['receipt_number'] = implode(' / ', array_values($row['receipt_numbers']));
            $row['date_paid'] = implode(' / ', array_values($row['payment_dates']));

            $otherFees = 0.00;
            $chargeTotal = 0.00;
            foreach ($row['charges'] as $codeId => $amount) {
                $codeId = (int)$codeId;
                $amount = cl_money_value($amount);
                $chargeTotal += $amount;
                $totals['charges'][$codeId] = cl_money_value(($totals['charges'][$codeId] ?? 0) + $amount);
                if ($codeId !== $waterCodeId && !in_array($codeId, $selectedCodeIds, true)) {
                    $otherFees += $amount;
                }
            }

            $row['other_fees'] = cl_money_value($otherFees);
            $row['total_amount'] = cl_money_value($chargeTotal + $row['penalty'] + $row['compromise']);
            $totals['penalty'] = cl_money_value($totals['penalty'] + $row['penalty']);
            $totals['compromise'] = cl_money_value($totals['compromise'] + $row['compromise']);
            $totals['other_fees'] = cl_money_value($totals['other_fees'] + $row['other_fees']);
            $totals['total_amount'] = cl_money_value($totals['total_amount'] + $row['total_amount']);
            $displayRows[] = $row;
        }

        usort($displayRows, static fn(array $a, array $b): int => strcmp((string)$a['period_sort'], (string)$b['period_sort']));

        return [
            'member' => $member,
            'bill_codes' => $billCodes,
            'water_bill_code_id' => $waterCodeId,
            'selected_bill_code_ids' => $selectedCodeIds,
            'periods' => $allPeriods,
            'selected_periods' => $selectedPeriods,
            'rows' => $displayRows,
            'totals' => $totals,
        ];
    }
}
