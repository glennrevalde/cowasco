<?php
/**
 * common/billLifecycleHelpers.php
 *
 * Centralized bill editing + soft-delete support for:
 * - Water Billing / bill_readings
 * - One-Time Billing / one_time_bills
 * - Installment Billing / installment_bills
 * - Recurring Billing / recurring_bills
 *
 * This version includes accounting correction:
 * - Edit amount -> voids the original journal voucher and posts a replacement.
 * - Soft delete -> creates reversal journal for billing side.
 *
 * EDIT RULE (authoritative):
 * A bill may only be edited while it has NOT been collected. The moment any
 * active collection allocation exists, the bill is frozen and the operator must
 * use the reversal/refund or debit/credit workflow instead.
 *
 * When an edit is allowed:
 *   1. The original journal voucher is VOIDED (Status 'I'). Its lines are kept
 *      but are excluded from every accounting total.
 *   2. A brand new voucher is posted for the corrected amount under the same
 *      transaction reference, and is linked back to the voided one.
 */

require_once __DIR__ . '/billAccountingCorrectionHelpers.php';
require_once __DIR__ . '/billCreationHelpers.php';
require_once __DIR__ . '/journalVoidHelpers.php';
require_once __DIR__ . '/waterBillingSnapshotHelpers.php';
require_once __DIR__ . '/../process/collectionAccountingHelpers.php';
require_once __DIR__ . '/../process/oneTimeBillingInventoryHelpers.php';
require_once __DIR__ . '/../process/installmentBillingInventoryHelpers.php';

if (!function_exists('bl_table_exists')) {
    function bl_table_exists(PDO $pdo, string $table): bool
    {
        return bl_ac_table_exists($pdo, $table);
    }
}

if (!function_exists('bl_column_exists')) {
    function bl_column_exists(PDO $pdo, string $table, string $column): bool
    {
        return bl_ac_column_exists($pdo, $table, $column);
    }
}

if (!function_exists('bl_money')) {
    function bl_money($amount): string
    {
        return '₱ ' . number_format((float)($amount ?? 0), 2);
    }
}

if (!function_exists('bl_date')) {
    function bl_date($date): string
    {
        if (!$date || $date === '0000-00-00') {
            return '—';
        }
        $time = strtotime((string)$date);
        return $time ? date('M j, Y', $time) : (string)$date;
    }
}

if (!function_exists('bl_valid_date_or_null')) {
    function bl_valid_date_or_null($date): ?string
    {
        $date = trim((string)($date ?? ''));
        if ($date === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Invalid date format. Use YYYY-MM-DD.');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            throw new Exception('Invalid date value.');
        }
        return $date;
    }
}

if (!function_exists('bl_float')) {
    function bl_float($value, float $default = 0.00): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new Exception('Amount/number fields must be numeric.');
        }
        return round((float)$value, 2);
    }
}

if (!function_exists('bl_int')) {
    function bl_int($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new Exception('Number fields must be numeric.');
        }
        return (int)round((float)$value);
    }
}

if (!function_exists('bl_status_class')) {
    function bl_status_class(string $status): string
    {
        $status = strtolower($status);
        if ($status === 'deleted') return 'deleted';
        if (in_array($status, ['paid', 'completed'], true)) return 'paid';
        if (in_array($status, ['cancelled', 'canceled'], true)) return 'cancelled';
        if (in_array($status, ['overdue'], true)) return 'overdue';
        return 'unpaid';
    }
}

if (!function_exists('bl_is_deleted')) {
    function bl_is_deleted(array $row): bool
    {
        return strtolower((string)($row['bill_lifecycle_status'] ?? '')) === 'deleted'
            || strtolower((string)($row['status'] ?? '')) === 'deleted'
            || !empty($row['deleted_at']);
    }
}

if (!function_exists('bl_audit')) {
    function bl_audit(PDO $pdo, int $userId, string $action, string $table, string $id, array $payload = []): void
    {
        if (!function_exists('logAudit')) {
            $helpers = __DIR__ . '/../common/billingHelpers.php';
            if (file_exists($helpers)) {
                require_once $helpers;
            }
        }

        if (function_exists('logAudit')) {
            try {
                logAudit($pdo, $userId, $action, $table, $id, json_encode($payload));
            } catch (Throwable $e) {}
        }
    }
}

if (!function_exists('bl_bill_cost_total')) {
    function bl_bill_cost_total(string $billType, array $row): float
    {
        if ($billType === 'one_time' || $billType === 'installment') {
            if (isset($row['inventory_total_cost'])) {
                return round((float)$row['inventory_total_cost'], 2);
            }

            $qty = round((float)($row['quantity'] ?? 1), 2);
            $cost = round((float)($row['cost_price'] ?? 0), 2);
            return round($qty * $cost, 2);
        }

        if ($billType === 'recurring') {
            $type = (string)($row['type'] ?? '');
            if ($type === 'Inventory') {
                return round((float)($row['cost_price'] ?? 0), 2);
            }
        }

        return 0.00;
    }
}

if (!function_exists('bl_fetch_records')) {
    function bl_fetch_records(PDO $pdo, array $filters = []): array
    {
        $type = $filters['type'] ?? 'all';
        $showDeleted = !empty($filters['show_deleted']);
        $memberSearch = trim((string)($filters['search'] ?? ''));
        $records = [];

        $memberLike = '%' . $memberSearch . '%';

        if (($type === 'all' || $type === 'water') && bl_table_exists($pdo, 'bill_readings')) {
            $where = [];
            $params = [];

            if (!$showDeleted && bl_column_exists($pdo, 'bill_readings', 'bill_lifecycle_status')) {
                $where[] = "COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'";
            }

            if ($memberSearch !== '') {
                $where[] = "(m.account_number LIKE ? OR m.lastname LIKE ? OR m.firstname LIKE ? OR CAST(m.pkey AS CHAR) LIKE ?)";
                array_push($params, $memberLike, $memberLike, $memberLike, $memberLike);
            }

            $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";
            $lifecycleSelect = bl_column_exists($pdo, 'bill_readings', 'bill_lifecycle_status') ? "br.bill_lifecycle_status" : "'active' AS bill_lifecycle_status";
            $editCountSelect = bl_column_exists($pdo, 'bill_readings', 'bill_edit_count') ? "br.bill_edit_count" : "0 AS bill_edit_count";
            $deletedAtSelect = bl_column_exists($pdo, 'bill_readings', 'deleted_at') ? "br.deleted_at" : "NULL AS deleted_at";

            $stmt = $pdo->prepare("
                SELECT
                    'water' AS bill_type,
                    br.reading_id AS bill_id,
                    br.member_id,
                    m.account_number,
                    CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                    bp.bp_code AS reference_label,
                    bp.start_date AS period_start,
                    bp.end_date AS period_end,
                    br.billing_date AS bill_date,
                    br.due_date,
                    br.amount_due AS total_amount,
                    LEAST(br.amount_due, COALESCE(br.amount_paid, 0)) AS amount_paid,
                    GREATEST(0, br.amount_due - COALESCE(br.amount_paid, 0)) AS balance,
                    CASE
                        WHEN {$lifecycleSelect} = 'deleted' THEN 'deleted'
                        WHEN br.is_billed = 1 AND COALESCE(br.amount_paid,0) >= br.amount_due THEN 'paid'
                        WHEN br.is_billed = 1 AND COALESCE(br.amount_paid,0) > 0 THEN 'partial'
                        WHEN br.is_billed = 1 THEN 'unpaid'
                        ELSE 'encoded'
                    END AS status,
                    br.prev_reading,
                    br.pres_reading,
                    br.consumption,
                    NULL AS quantity,
                    '' AS bill_code_type,
                    br.is_billed,
                    br.is_paid,
                    {$lifecycleSelect},
                    {$editCountSelect},
                    {$deletedAtSelect},
                    'Water Bill' AS source_name,
                    CONCAT('Period ', COALESCE(bp.bp_code, br.period_id)) AS description
                FROM bill_readings br
                JOIN water_accounts m ON m.pkey = br.member_id
                LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
                {$whereSql}
                ORDER BY COALESCE(br.billing_date, bp.start_date, br.created_at) DESC, br.reading_id DESC
                LIMIT 1000
            ");
            $stmt->execute($params);
            $records = array_merge($records, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if (($type === 'all' || $type === 'one_time') && bl_table_exists($pdo, 'one_time_bills')) {
            $where = [];
            $params = [];

            if (!$showDeleted) {
                $where[] = "(COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted' AND b.status <> 'deleted')";
            }

            if ($memberSearch !== '') {
                $where[] = "(m.account_number LIKE ? OR m.lastname LIKE ? OR m.firstname LIKE ? OR CAST(m.pkey AS CHAR) LIKE ?)";
                array_push($params, $memberLike, $memberLike, $memberLike, $memberLike);
            }

            $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

            $stmt = $pdo->prepare("
                SELECT
                    'one_time' AS bill_type,
                    b.bill_id,
                    b.member_id,
                    b.bill_code_id,
                    m.account_number,
                    CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                    COALESCE(b.global_ref_number, CONCAT('OTB-', b.bill_id)) AS reference_label,
                    NULL AS period_start,
                    NULL AS period_end,
                    b.bill_date,
                    b.due_date,
                    b.total_amount,
                    b.amount_paid,
                    GREATEST(0, b.total_amount - b.amount_paid) AS balance,
                    b.status,
                    NULL AS prev_reading,
                    NULL AS pres_reading,
                    NULL AS consumption,
                    b.quantity,
                    COALESCE(c.type, '') AS bill_code_type,
                    NULL AS is_billed,
                    CASE WHEN b.status = 'paid' THEN 1 ELSE 0 END AS is_paid,
                    COALESCE(b.bill_lifecycle_status, 'active') AS bill_lifecycle_status,
                    COALESCE(b.bill_edit_count, 0) AS bill_edit_count,
                    b.deleted_at,
                    'One-Time Bill' AS source_name,
                    CONCAT(COALESCE(c.code, 'One-Time'), ' - ', COALESCE(c.description, '')) AS description
                FROM one_time_bills b
                JOIN water_accounts m ON m.pkey = b.member_id
                LEFT JOIN bill_codes c ON c.code_id = b.bill_code_id
                {$whereSql}
                ORDER BY b.bill_date DESC, b.bill_id DESC
                LIMIT 1000
            ");
            $stmt->execute($params);
            $records = array_merge($records, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if (($type === 'all' || $type === 'installment') && bl_table_exists($pdo, 'installment_bills')) {
            $where = [];
            $params = [];

            if (!$showDeleted) {
                $where[] = "(COALESCE(i.bill_lifecycle_status, 'active') <> 'deleted' AND i.status <> 'deleted')";
            }

            if ($memberSearch !== '') {
                $where[] = "(m.account_number LIKE ? OR m.lastname LIKE ? OR m.firstname LIKE ? OR CAST(m.pkey AS CHAR) LIKE ?)";
                array_push($params, $memberLike, $memberLike, $memberLike, $memberLike);
            }

            $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

            $stmt = $pdo->prepare("
                SELECT
                    'installment' AS bill_type,
                    i.installment_id AS bill_id,
                    i.member_id,
                    i.bill_code_id,
                    m.account_number,
                    CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                    COALESCE(i.global_ref_number, CONCAT('INST-', i.installment_id)) AS reference_label,
                    i.start_date AS period_start,
                    NULL AS period_end,
                    i.start_date AS bill_date,
                    (SELECT MIN(s.due_date) FROM installment_schedules s WHERE s.installment_id = i.installment_id AND s.status = 'pending') AS due_date,
                    i.total_amount,
                    COALESCE((SELECT SUM(LEAST(s.amount, COALESCE(s.amount_paid,0))) FROM installment_schedules s WHERE s.installment_id = i.installment_id), 0) AS amount_paid,
                    GREATEST(0, i.total_amount - COALESCE((SELECT SUM(LEAST(s.amount, COALESCE(s.amount_paid,0))) FROM installment_schedules s WHERE s.installment_id = i.installment_id), 0)) AS balance,
                    i.status,
                    NULL AS prev_reading,
                    NULL AS pres_reading,
                    NULL AS consumption,
                    i.quantity,
                    COALESCE(c.type, '') AS bill_code_type,
                    NULL AS is_billed,
                    CASE WHEN i.status = 'completed' THEN 1 ELSE 0 END AS is_paid,
                    COALESCE(i.bill_lifecycle_status, 'active') AS bill_lifecycle_status,
                    COALESCE(i.bill_edit_count, 0) AS bill_edit_count,
                    i.deleted_at,
                    'Installment Bill' AS source_name,
                    CONCAT(COALESCE(c.code, 'Installment'), ' - ', COALESCE(c.description, ''), ' / ', i.term, ' payments') AS description
                FROM installment_bills i
                JOIN water_accounts m ON m.pkey = i.member_id
                LEFT JOIN bill_codes c ON c.code_id = i.bill_code_id
                {$whereSql}
                ORDER BY i.start_date DESC, i.installment_id DESC
                LIMIT 1000
            ");
            $stmt->execute($params);
            $records = array_merge($records, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if (($type === 'all' || $type === 'recurring') && bl_table_exists($pdo, 'recurring_bills')) {
            $where = [];
            $params = [];

            if (!$showDeleted) {
                $where[] = "(COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted' AND rb.status <> 'deleted')";
            }

            if ($memberSearch !== '') {
                $where[] = "(m.account_number LIKE ? OR m.lastname LIKE ? OR m.firstname LIKE ? OR CAST(m.pkey AS CHAR) LIKE ?)";
                array_push($params, $memberLike, $memberLike, $memberLike, $memberLike);
            }

            $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

            $stmt = $pdo->prepare("
                SELECT
                    'recurring' AS bill_type,
                    rb.bill_id,
                    rb.member_id,
                    rb.bill_code_id,
                    m.account_number,
                    CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name,
                    COALESCE(rb.global_ref_number, CONCAT('RB-', rb.bill_id)) AS reference_label,
                    rb.billing_period_start AS period_start,
                    rb.billing_period_end AS period_end,
                    rb.bill_date,
                    rb.due_date,
                    rb.total_amount,
                    rb.amount_paid,
                    rb.balance,
                    rb.status,
                    NULL AS prev_reading,
                    NULL AS pres_reading,
                    NULL AS consumption,
                    NULL AS quantity,
                    COALESCE(c.type, '') AS bill_code_type,
                    NULL AS is_billed,
                    CASE WHEN rb.status = 'paid' THEN 1 ELSE 0 END AS is_paid,
                    COALESCE(rb.bill_lifecycle_status, 'active') AS bill_lifecycle_status,
                    COALESCE(rb.bill_edit_count, 0) AS bill_edit_count,
                    rb.deleted_at,
                    'Recurring Bill' AS source_name,
                    CONCAT(COALESCE(c.code, 'Recurring'), ' - ', COALESCE(c.description, '')) AS description
                FROM recurring_bills rb
                JOIN water_accounts m ON m.pkey = rb.member_id
                LEFT JOIN bill_codes c ON c.code_id = rb.bill_code_id
                {$whereSql}
                ORDER BY rb.bill_date DESC, rb.bill_id DESC
                LIMIT 1000
            ");
            $stmt->execute($params);
            $records = array_merge($records, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        usort($records, function ($a, $b) {
            return strcmp((string)($b['bill_date'] ?? ''), (string)($a['bill_date'] ?? ''));
        });

        return $records;
    }
}

if (!function_exists('bl_fetch_bill_for_update')) {
    function bl_fetch_bill_for_update(PDO $pdo, string $billType, int $billId): array
    {
        if ($billType === 'water') {
            $stmt = $pdo->prepare("SELECT * FROM bill_readings WHERE reading_id = ? FOR UPDATE");
            $stmt->execute([$billId]);
        } elseif ($billType === 'one_time') {
            $stmt = $pdo->prepare("
                SELECT b.*, bc.type, bc.cost_price, bc.code
                FROM one_time_bills b
                LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
                WHERE b.bill_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$billId]);
        } elseif ($billType === 'installment') {
            $stmt = $pdo->prepare("
                SELECT i.*, bc.type, bc.cost_price, bc.code
                FROM installment_bills i
                LEFT JOIN bill_codes bc ON bc.code_id = i.bill_code_id
                WHERE i.installment_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$billId]);
        } elseif ($billType === 'recurring') {
            $stmt = $pdo->prepare("
                SELECT rb.*, bc.type, bc.cost_price, bc.code
                FROM recurring_bills rb
                LEFT JOIN bill_codes bc ON bc.code_id = rb.bill_code_id
                WHERE rb.bill_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$billId]);
        } else {
            throw new Exception('Unknown bill type.');
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Bill was not found.');
        }

        return $row;
    }
}

if (!function_exists('bl_payment_source_type')) {
    /** Map an internal bill type onto the payment allocation source_type enum. */
    function bl_payment_source_type(string $billType): string
    {
        $map = [
            'water' => 'water',
            'one_time' => 'onetime',
            'installment' => 'installment',
            'recurring' => 'recurring',
        ];

        if (!isset($map[$billType])) {
            throw new Exception('Unknown bill type.');
        }

        return $map[$billType];
    }
}

if (!function_exists('bl_bill_collected_amount')) {
    /**
     * Total still-standing collection recorded against a bill.
     *
     * Reversed and refunded allocations are excluded on purpose: once a payment
     * has been properly reversed, the bill is uncollected again and may be
     * corrected.
     */
    function bl_bill_collected_amount(PDO $pdo, string $billType, int $billId): float
    {
        if (!bl_table_exists($pdo, 'payment_transaction_items') || !bl_table_exists($pdo, 'payment_transactions')) {
            return 0.00;
        }

        $sourceType = bl_payment_source_type($billType);

        if ($billType === 'installment') {
            // Allocations point at individual schedules, not the contract.
            $sql = "
                SELECT COALESCE(SUM(pti.principal_amount), 0)
                FROM payment_transaction_items pti
                INNER JOIN payment_transactions pt ON pt.transaction_id = pti.transaction_id
                INNER JOIN installment_schedules s ON s.schedule_id = pti.source_id
                WHERE pti.source_type = 'installment'
                  AND s.installment_id = ?
                  AND COALESCE(pt.transaction_status, 'active') = 'active'
                  AND COALESCE(pti.allocation_status, 'active') = 'active'
            ";
        } else {
            $sql = "
                SELECT COALESCE(SUM(pti.principal_amount), 0)
                FROM payment_transaction_items pti
                INNER JOIN payment_transactions pt ON pt.transaction_id = pti.transaction_id
                WHERE pti.source_type = '{$sourceType}'
                  AND pti.source_id = ?
                  AND COALESCE(pt.transaction_status, 'active') = 'active'
                  AND COALESCE(pti.allocation_status, 'active') = 'active'
            ";
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$billId]);
            return round((float)$stmt->fetchColumn(), 2);
        } catch (Throwable $e) {
            // A missing allocation table must never be read as "uncollected".
            throw new Exception('Collection history could not be verified, so this bill cannot be edited right now.');
        }
    }
}

if (!function_exists('bl_bill_recorded_paid')) {
    /** Paid amount stored on the bill row itself, across all four bill types. */
    function bl_bill_recorded_paid(PDO $pdo, string $billType, int $billId, array $row): float
    {
        if ($billType === 'installment') {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(COALESCE(amount_paid, 0)), 0) FROM installment_schedules WHERE installment_id = ?");
            $stmt->execute([$billId]);
            return round((float)$stmt->fetchColumn(), 2);
        }

        return round((float)($row['amount_paid'] ?? 0), 2);
    }
}

if (!function_exists('bl_bill_is_editable')) {
    /**
     * A bill is editable only while it is active and nothing has been collected
     * against it. Returns [bool $editable, string $reasonWhenBlocked].
     */
    function bl_bill_is_editable(PDO $pdo, string $billType, int $billId, array $row): array
    {
        if (bl_is_deleted($row)) {
            return [false, 'This bill is deleted and can no longer be edited.'];
        }

        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled'], true)) {
            return [false, 'A cancelled bill cannot be edited.'];
        }

        if (bl_bill_recorded_paid($pdo, $billType, $billId, $row) > 0) {
            return [false, 'This bill already has a recorded payment. Reverse or refund the collection first, then edit it.'];
        }

        if (bl_bill_collected_amount($pdo, $billType, $billId) > 0) {
            return [false, 'This bill has been collected. Reverse or refund the official receipt first, then edit it.'];
        }

        return [true, ''];
    }
}

if (!function_exists('bl_collected_bill_map')) {
    /**
     * One lookup of every bill that currently carries a live collection, so a
     * long register can flag editability without a query per row.
     * Shape: ['water' => [12 => true], 'one_time' => [...], ...]
     */
    function bl_collected_bill_map(PDO $pdo): array
    {
        $map = ['water' => [], 'one_time' => [], 'installment' => [], 'recurring' => []];
        if (!bl_table_exists($pdo, 'payment_transaction_items') || !bl_table_exists($pdo, 'payment_transactions')) {
            return $map;
        }

        try {
            $stmt = $pdo->query("
                SELECT pti.source_type, pti.source_id
                FROM payment_transaction_items pti
                INNER JOIN payment_transactions pt ON pt.transaction_id = pti.transaction_id
                WHERE COALESCE(pt.transaction_status, 'active') = 'active'
                  AND COALESCE(pti.allocation_status, 'active') = 'active'
                  AND pti.source_type <> 'installment'
                GROUP BY pti.source_type, pti.source_id
            ");
            $types = ['water' => 'water', 'onetime' => 'one_time', 'recurring' => 'recurring'];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $types[(string)$row['source_type']] ?? null;
                if ($key !== null) {
                    $map[$key][(int)$row['source_id']] = true;
                }
            }

            $installments = $pdo->query("
                SELECT s.installment_id
                FROM payment_transaction_items pti
                INNER JOIN payment_transactions pt ON pt.transaction_id = pti.transaction_id
                INNER JOIN installment_schedules s ON s.schedule_id = pti.source_id
                WHERE pti.source_type = 'installment'
                  AND COALESCE(pt.transaction_status, 'active') = 'active'
                  AND COALESCE(pti.allocation_status, 'active') = 'active'
                GROUP BY s.installment_id
            ");
            foreach ($installments->fetchAll(PDO::FETCH_COLUMN) as $installmentId) {
                $map['installment'][(int)$installmentId] = true;
            }
        } catch (Throwable $e) {
            // On any failure every bill is treated as collected, which is the
            // safe direction: the Edit action simply stays hidden.
            return ['water' => null, 'one_time' => null, 'installment' => null, 'recurring' => null];
        }

        return $map;
    }
}

if (!function_exists('bl_row_looks_editable')) {
    /** Cheap per-row editability check driven by bl_collected_bill_map(). */
    function bl_row_looks_editable(array $row, array $collectedMap): bool
    {
        if (bl_is_deleted($row)) {
            return false;
        }
        if (in_array(strtolower((string)($row['status'] ?? '')), ['cancelled', 'canceled'], true)) {
            return false;
        }
        if (round((float)($row['amount_paid'] ?? 0), 2) > 0) {
            return false;
        }

        $billType = (string)($row['bill_type'] ?? '');
        if (!array_key_exists($billType, $collectedMap) || $collectedMap[$billType] === null) {
            return false;
        }

        return !isset($collectedMap[$billType][(int)($row['bill_id'] ?? 0)]);
    }
}

if (!function_exists('bl_assert_bill_not_collected')) {
    function bl_assert_bill_not_collected(PDO $pdo, string $billType, int $billId, array $row): void
    {
        [$editable, $why] = bl_bill_is_editable($pdo, $billType, $billId, $row);
        if (!$editable) {
            throw new Exception($why);
        }
    }
}

if (!function_exists('bl_bill_revision_number')) {
    /**
     * Which correction this edit is: 1 for the first, 2 for the next, and so on.
     * bill_edit_count is still the pre-edit value when this is called, so the
     * number matches the revision the Journal Entry register derives from the
     * void chain.
     */
    function bl_bill_revision_number(array $row): int
    {
        return max(1, (int)($row['bill_edit_count'] ?? 0) + 1);
    }
}

if (!function_exists('bl_bill_invoice_number')) {
    /**
     * The bill's invoice number, using the same fallbacks the Journal Entry
     * register uses, so a correction's description and its JV No. agree.
     */
    function bl_bill_invoice_number(string $billType, int $billId, array $row): string
    {
        $invoiceNo = trim((string)($row['global_ref_number'] ?? ''));
        if ($invoiceNo !== '' && $invoiceNo !== '0') {
            return $invoiceNo;
        }

        $prefixes = [
            'water' => 'WB-',
            'one_time' => 'OTB-',
            'installment' => 'INST-',
            'recurring' => 'RB-',
        ];

        return ($prefixes[$billType] ?? 'BILL-') . $billId;
    }
}

if (!function_exists('bl_bill_correction_document_no')) {
    /**
     * The number the corrected bill's journal entry shows in the register:
     * the invoice number plus this edit's revision suffix (e.g. 1024-R1).
     */
    function bl_bill_correction_document_no(string $billType, int $billId, array $row): string
    {
        return bl_bill_invoice_number($billType, $billId, $row) . '-R' . bl_bill_revision_number($row);
    }
}

if (!function_exists('bl_void_and_replace_bill_journal')) {
    /**
     * Void every live voucher behind a bill, then post one replacement voucher
     * carrying the corrected amount. The replacement reuses the original
     * transaction reference so collection and reporting lookups keep working.
     */
    function bl_void_and_replace_bill_journal(
        PDO $pdo,
        string $billType,
        int $billId,
        array $row,
        float $newAmount,
        float $newCostTotal,
        string $reason,
        int $userId,
        ?string $transDate = null,
        ?array $waterDetails = null
    ): array {
        $transRef = bl_ac_bill_trans_ref($billType, $billId, $row);
        $knownVouchers = [
            (string)($row['billing_journal_voucher_id'] ?? ''),
            (string)($row['journal_voucher_id'] ?? ''),
        ];

        $voided = jv_void_reference_set(
            $pdo,
            $knownVouchers,
            $transRef,
            $userId,
            $reason,
            $billType . '_bill_edit',
            (string)$billId
        );

        $replacement = null;
        if ($billType === 'water') {
            if ($waterDetails === null) {
                throw new Exception('The recalculated water billing breakdown is missing.');
            }
            if (round($newAmount, 2) > 0) {
                // The replacement is its own journal entry, so its lines say so
                // and name the revision the register will show (1024-R1).
                $replacement = wc_create_water_billing_journal_from_breakdown(
                    $pdo,
                    $billId,
                    (int)($row['member_id'] ?? 0),
                    $waterDetails,
                    $transDate ?: date('Y-m-d'),
                    (int)($row['branch_id'] ?? 0),
                    (int)($row['segment_id'] ?? 0),
                    (int)($row['division_id'] ?? 0),
                    [
                        'revision' => bl_bill_revision_number($row),
                        'invoice_no' => bl_bill_invoice_number('water', $billId, $row),
                        'reason' => $reason,
                    ]
                );
                if (!$replacement) {
                    throw new Exception('The replacement water billing journal could not be posted.');
                }
                $replacement = [
                    'journal_voucher_id' => (string)$replacement['journal_head_id'],
                    'jv_entry_id' => (string)$replacement['journal_entry_id'],
                ];
            }
        } else {
            $replacement = bl_ac_create_bill_replacement_journal(
                $pdo,
                $billType,
                $billId,
                $row,
                $newAmount,
                $newCostTotal,
                $transRef,
                $reason,
                $userId,
                $transDate
            );
        }

        if ($replacement) {
            foreach ($voided as $entry) {
                jv_void_link_replacement(
                    $pdo,
                    (string)$entry['journal_voucher_id'],
                    (string)$replacement['journal_voucher_id'],
                    (string)($replacement['jv_entry_id'] ?? '')
                );
            }
        }

        return [
            'trans_ref_id' => $transRef,
            'voided' => $voided,
            'replacement' => $replacement,
        ];
    }
}

if (!function_exists('bl_water_persist_edited_bill')) {
    /**
     * Write a corrected water reading together with its fresh immutable
     * snapshot, in either state.
     *
     * water_snapshot_persist() is deliberately not reused: it only accepts
     * drafts, and it does not carry the edit-audit columns this workflow needs.
     * The is_billed value is pinned in the WHERE clause so a reading that is
     * posted (or unposted) concurrently fails the write instead of silently
     * corrupting the other state.
     */
    function bl_water_persist_edited_bill(
        PDO $pdo,
        int $readingId,
        array $snapshot,
        int $presentReading,
        int $consumption,
        float $amountDue,
        ?string $replacementVoucherId,
        string $reason,
        int $userId,
        bool $isPosted = true
    ): void {
        $hash = (string)($snapshot['snapshot_hash'] ?? '');
        if ($hash === '') {
            throw new Exception('Cannot store a water-billing snapshot without a hash.');
        }

        $sets = [
            'pres_reading = :pres',
            'consumption = :consumption',
            'amount_due = :amount_due',
            'snapshot_version = :snapshot_version',
            'rate_code_id_snapshot = :rate_code_id',
            'rate_code_snapshot = :rate_code_json',
            'tariff_snapshot = :tariff_json',
            'discount_snapshot = :discount_json',
            'calculation_snapshot = :calculation_json',
            'base_charge_snapshot = :base_charge',
            'discount_amount_snapshot = :discount_amount',
            'snapshot_hash = :hash',
            'snapshot_created_at = :captured_at',
            'billing_journal_voucher_id = :voucher',
            'bill_edit_count = COALESCE(bill_edit_count, 0) + 1',
            'last_bill_edited_at = NOW()',
            'last_bill_edited_by = :user_id',
            'revision_note = :reason',
            'is_edited_override = 1',
            'updated_at = NOW()',
        ];

        $params = [
            ':pres' => $presentReading,
            ':consumption' => $consumption,
            ':amount_due' => $amountDue,
            ':snapshot_version' => (int)($snapshot['snapshot_version'] ?? 1),
            ':rate_code_id' => (int)(($snapshot['rate_code']['rc_id'] ?? $snapshot['rate_code']['id'] ?? 0)),
            ':rate_code_json' => water_snapshot_json($snapshot['rate_code'] ?? []),
            ':tariff_json' => water_snapshot_json($snapshot['tariff_tiers'] ?? []),
            ':discount_json' => water_snapshot_json($snapshot['discount'] ?? null),
            ':calculation_json' => water_snapshot_json($snapshot['calculation'] ?? []),
            ':base_charge' => (float)($snapshot['calculation']['base_charge'] ?? 0),
            ':discount_amount' => (float)($snapshot['calculation']['discount_amount'] ?? 0),
            ':hash' => $hash,
            ':captured_at' => (string)($snapshot['captured_at'] ?? date('Y-m-d H:i:s')),
            ':voucher' => $replacementVoucherId !== null && $replacementVoucherId !== '' ? $replacementVoucherId : null,
            ':user_id' => $userId > 0 ? $userId : null,
            ':reason' => mb_substr($reason, 0, 500),
            ':id' => $readingId,
        ];

        // These two columns arrived with the pipe-size patch and may be absent
        // on older installations.
        if (bl_column_exists($pdo, 'bill_readings', 'pipe_size_id_snapshot')) {
            $sets[] = 'pipe_size_id_snapshot = :pipe_size_id';
            $params[':pipe_size_id'] = (int)(($snapshot['rate_code']['pipe_size_id'] ?? 0));
        }
        if (bl_column_exists($pdo, 'bill_readings', 'pipe_size_snapshot')) {
            $label = trim((string)($snapshot['rate_code']['pipe_size_label'] ?? ''));
            $sets[] = 'pipe_size_snapshot = :pipe_size_label';
            $params[':pipe_size_label'] = $label !== '' ? $label : (string)($snapshot['rate_code']['pipe_size_code'] ?? '');
        }

        $params[':is_billed'] = $isPosted ? 1 : 0;

        $sql = 'UPDATE bill_readings SET ' . implode(', ', $sets) . "
                WHERE reading_id = :id
                  AND is_billed = :is_billed
                  AND is_paid = 0
                  AND COALESCE(bill_lifecycle_status, 'active') = 'active'";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() !== 1) {
            throw new Exception(
                $isPosted
                    ? 'The water bill changed state before the correction could be saved.'
                    : 'The reading was posted or changed state before the correction could be saved. Reload and try again.'
            );
        }
    }
}

if (!function_exists('bl_update_bill')) {
    /**
     * Edit an uncollected bill and replace its accounting entry.
     *
     * $data accepts, by bill type:
     *   water        pres_reading, reason
     *   one_time     total_amount OR quantity (inventory), due_date, reason
     *   installment  total_amount, reason
     *   recurring    total_amount, due_date, reason
     */
    function bl_update_bill(PDO $pdo, string $billType, int $billId, array $data, int $userId): array
    {
        if ($billId <= 0) {
            throw new Exception('Invalid bill selected.');
        }
        if (!in_array($billType, ['water', 'one_time', 'installment', 'recurring'], true)) {
            throw new Exception('Unknown bill type.');
        }

        $reason = trim((string)($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw new Exception('Give a reason of at least 5 characters for this correction.');
        }

        $old = bl_fetch_bill_for_update($pdo, $billType, $billId);
        bl_assert_bill_not_collected($pdo, $billType, $billId, $old);

        if ($billType === 'water') {
            return bl_update_water_bill($pdo, $billId, $old, $data, $reason, $userId);
        }

        return bl_update_other_bill($pdo, $billType, $billId, $old, $data, $reason, $userId);
    }
}

if (!function_exists('bl_update_water_bill')) {
    function bl_update_water_bill(PDO $pdo, int $billId, array $old, array $data, string $reason, int $userId): array
    {
        /*
         * Both states are editable here.
         *
         * Posted (is_billed = 1): the bill carries a journal voucher, so the
         * correction voids it and posts a replacement.
         *
         * Draft (is_billed = 0): no journal exists yet, so there is nothing to
         * void and nothing may be posted — creating one here would put a bill
         * into the ledger that was never billed. The draft is simply
         * recalculated and re-snapshotted.
         */
        $isPosted = (int)($old['is_billed'] ?? 0) === 1;

        $previous = (int)($old['prev_reading'] ?? 0);
        $presentRaw = $data['pres_reading'] ?? null;
        if ($presentRaw === null || trim((string)$presentRaw) === '') {
            throw new Exception('Enter the corrected present reading.');
        }
        $present = bl_int($presentRaw);
        if ($present < 0) {
            throw new Exception('Present reading cannot be negative.');
        }
        if ($present < $previous) {
            throw new Exception("Present reading {$present} cannot be lower than the previous reading {$previous}.");
        }

        $consumption = $present - $previous;
        $memberId = (int)($old['member_id'] ?? 0);
        $oldAmount = round((float)($old['amount_due'] ?? 0), 2);

        // The corrected bill is recalculated from the live tariff and discount
        // configuration, then resnapshotted so it stays self-verifying.
        $details = compute_water_billing_details($pdo, $memberId, (float)$consumption, true);
        $details['consumption'] = $consumption;
        $newAmount = round((float)($details['amount_due'] ?? 0), 2);
        $snapshot = water_snapshot_build($details);

        $billingDate = substr((string)($old['billing_date'] ?? ''), 0, 10) ?: date('Y-m-d');

        $journal = ['voided' => [], 'replacement' => null];
        $replacementId = null;

        if ($isPosted) {
            $journal = bl_void_and_replace_bill_journal(
                $pdo,
                'water',
                $billId,
                $old,
                $newAmount,
                0.00,
                $reason,
                $userId,
                $billingDate,
                $details
            );
            $replacementId = $journal['replacement']['journal_voucher_id'] ?? null;
        } else {
            // A draft must stay out of the ledger. Guard against a stray
            // voucher left behind by an earlier failed posting attempt.
            $strayVouchers = jv_void_active_vouchers_for_reference($pdo, 'WBILL-' . $billId);
            if ($strayVouchers) {
                $journal['voided'] = jv_void_reference_set(
                    $pdo,
                    [(string)($old['billing_journal_voucher_id'] ?? '')],
                    'WBILL-' . $billId,
                    $userId,
                    $reason . ' (orphaned voucher on an unposted reading)',
                    'water_draft_cleanup',
                    (string)$billId
                );
            }
        }

        bl_water_persist_edited_bill(
            $pdo,
            $billId,
            $snapshot,
            $present,
            $consumption,
            $newAmount,
            $replacementId,
            $reason,
            $userId,
            $isPosted
        );

        if ($isPosted) {
            bl_ac_store_adjustment_reference($pdo, 'bill_readings', 'reading_id', $billId, $journal['replacement'] ?: null);
        }

        bl_audit($pdo, $userId, $isPosted ? 'EDIT_WATER_BILL' : 'EDIT_WATER_READING_DRAFT', 'bill_readings', (string)$billId, [
            'posted' => $isPosted,
            'reason' => $reason,
            'prev_reading' => $previous,
            'old_present_reading' => (int)($old['pres_reading'] ?? 0),
            'new_present_reading' => $present,
            'old_consumption' => (int)($old['consumption'] ?? 0),
            'new_consumption' => $consumption,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'snapshot_hash' => (string)($snapshot['snapshot_hash'] ?? ''),
            'voided_vouchers' => array_column($journal['voided'], 'journal_voucher_id'),
            'replacement_voucher' => $replacementId,
        ]);

        return [
            'bill_type' => 'water',
            'bill_id' => $billId,
            'posted' => $isPosted,
            'old_amount' => $oldAmount,
            'new_amount' => $newAmount,
            'voided_count' => count($journal['voided']),
            'replacement_voucher_id' => $replacementId,
            'replacement_jv_entry_id' => $journal['replacement']['jv_entry_id'] ?? null,
            // The number the operator will actually see in the register.
            'replacement_document_no' => $replacementId ? bl_bill_correction_document_no('water', $billId, $old) : null,
        ];
    }
}

if (!function_exists('bl_update_other_bill')) {
    function bl_update_other_bill(PDO $pdo, string $billType, int $billId, array $old, array $data, string $reason, int $userId): array
    {
        $isInventory = strcasecmp(trim((string)($old['type'] ?? '')), 'Inventory') === 0;
        $oldTotal = round((float)($old['total_amount'] ?? 0), 2);
        $oldDiscount = round((float)($old['discount_amount'] ?? 0), 2);
        $oldQuantity = round((float)($old['quantity'] ?? 1), 2);
        $oldCostTotal = bl_bill_cost_total($billType, $old);

        $newQuantity = $oldQuantity;
        $newCostTotal = $oldCostTotal;

        if ($isInventory && in_array($billType, ['one_time', 'installment'], true)) {
            // Inventory pricing is always derived server side from the bill
            // code, so the operator corrects the quantity, never the price.
            if (!isset($data['quantity']) || trim((string)$data['quantity']) === '') {
                throw new Exception('Enter the corrected quantity for this inventory bill.');
            }
            $newQuantity = bc_positive_quantity($data['quantity'], 'Quantity');
            $billCode = bc_lock_bill_code($pdo, (int)($old['bill_code_id'] ?? 0));
            $newGross = bc_authoritative_gross($billCode, $newQuantity, null);
            $unitCost = round((float)($old['inventory_unit_cost'] ?? ($billCode['cost_price'] ?? 0)), 2);
            $newCostTotal = round($unitCost * $newQuantity, 2);
            $newTotal = round(max(0, $newGross - $oldDiscount), 2);
        } else {
            if (!isset($data['total_amount']) || trim((string)$data['total_amount']) === '') {
                throw new Exception('Enter the corrected total amount.');
            }
            $newTotal = bc_cents_amount(bc_money_cents($data['total_amount'], 'Total amount'));
            $newGross = round($newTotal + $oldDiscount, 2);
        }

        if ($newTotal <= 0) {
            throw new Exception('The corrected total must be greater than zero. Delete the bill instead if it should not exist.');
        }

        $newDueDate = bl_valid_date_or_null($data['due_date'] ?? null);

        // the replacement invoice carries the corrected quantity: the cooperative inventory moves the stock
        // and the cost from its item line
        $journalRow = $old;
        $journalRow['quantity'] = $newQuantity;
        $journal = bl_void_and_replace_bill_journal(
            $pdo,
            $billType,
            $billId,
            $journalRow,
            $newTotal,
            $newCostTotal,
            $reason,
            $userId,
            date('Y-m-d')
        );
        $replacement = $journal['replacement'];
        $replacementId = $replacement['journal_voucher_id'] ?? null;

        if ($billType === 'one_time') {
            if ($isInventory) {
                $quantityDelta = round($newQuantity - $oldQuantity, 2);
                if (abs($quantityDelta) >= 0.005) {
                    $unitCost = round((float)($old['inventory_unit_cost'] ?? 0), 2);
                    otb_add_stock_movement(
                        $pdo,
                        (int)$old['bill_code_id'],
                        'ADJUSTMENT',
                        $billId,
                        date('Y-m-d'),
                        $quantityDelta < 0 ? abs($quantityDelta) : 0,
                        $quantityDelta > 0 ? $quantityDelta : 0,
                        $unitCost,
                        "One-time bill #{$billId} edited; stock realigned",
                        $userId
                    );
                }
            }

            $sql = "
                UPDATE one_time_bills
                SET quantity = :quantity,
                    gross_amount = :gross,
                    total_amount = :total,
                    inventory_total_cost = :cost_total,
                    due_date = COALESCE(:due_date, due_date),
                    bill_edit_count = COALESCE(bill_edit_count, 0) + 1,
                    last_bill_edited_at = NOW(),
                    last_bill_edited_by = :user_id,
                    revision_note = :reason,
                    updated_at = NOW()
                WHERE bill_id = :id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':quantity' => $newQuantity,
                ':gross' => $newGross,
                ':total' => $newTotal,
                ':cost_total' => $newCostTotal,
                ':due_date' => $newDueDate,
                ':user_id' => $userId > 0 ? $userId : null,
                ':reason' => mb_substr($reason, 0, 500),
                ':id' => $billId,
            ]);

            bl_ac_store_adjustment_reference($pdo, 'one_time_bills', 'bill_id', $billId, $replacement ?: null);
        } elseif ($billType === 'installment') {
            if ($isInventory) {
                $quantityDelta = round($newQuantity - $oldQuantity, 2);
                if (abs($quantityDelta) >= 0.005) {
                    $unitCost = round((float)($old['inventory_unit_cost'] ?? 0), 2);
                    ib_add_stock_movement(
                        $pdo,
                        (int)$old['bill_code_id'],
                        'ADJUSTMENT',
                        $billId,
                        date('Y-m-d'),
                        $quantityDelta < 0 ? abs($quantityDelta) : 0,
                        $quantityDelta > 0 ? $quantityDelta : 0,
                        $unitCost,
                        "Installment #{$billId} edited; stock realigned",
                        $userId
                    );
                }
            }

            $stmt = $pdo->prepare("
                UPDATE installment_bills
                SET quantity = :quantity,
                    gross_amount = :gross,
                    total_amount = :total,
                    inventory_total_cost = :cost_total,
                    bill_edit_count = COALESCE(bill_edit_count, 0) + 1,
                    last_bill_edited_at = NOW(),
                    last_bill_edited_by = :user_id,
                    revision_note = :reason,
                    updated_at = NOW()
                WHERE installment_id = :id
            ");
            $stmt->execute([
                ':quantity' => $newQuantity,
                ':gross' => $newGross,
                ':total' => $newTotal,
                ':cost_total' => $newCostTotal,
                ':user_id' => $userId > 0 ? $userId : null,
                ':reason' => mb_substr($reason, 0, 500),
                ':id' => $billId,
            ]);

            bl_respread_installment_schedules($pdo, $billId, $newTotal);
            bl_ac_store_adjustment_reference($pdo, 'installment_bills', 'installment_id', $billId, $replacement ?: null);
        } else {
            $stmt = $pdo->prepare("
                UPDATE recurring_bills
                SET gross_amount = :gross,
                    total_amount = :total,
                    balance = GREATEST(0, :total2 - COALESCE(amount_paid, 0)),
                    due_date = COALESCE(:due_date, due_date),
                    journal_voucher_id = :voucher,
                    bill_edit_count = COALESCE(bill_edit_count, 0) + 1,
                    last_bill_edited_at = NOW(),
                    last_bill_edited_by = :user_id,
                    revision_note = :reason,
                    updated_at = NOW()
                WHERE bill_id = :id
            ");
            $stmt->execute([
                ':gross' => $newGross,
                ':total' => $newTotal,
                ':total2' => $newTotal,
                ':due_date' => $newDueDate,
                ':voucher' => $replacementId,
                ':user_id' => $userId > 0 ? $userId : null,
                ':reason' => mb_substr($reason, 0, 500),
                ':id' => $billId,
            ]);

            bl_ac_store_adjustment_reference($pdo, 'recurring_bills', 'bill_id', $billId, $replacement ?: null);
        }

        bl_audit($pdo, $userId, 'EDIT_' . strtoupper($billType) . '_BILL', $billType, (string)$billId, [
            'reason' => $reason,
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'old_cost_total' => $oldCostTotal,
            'new_cost_total' => $newCostTotal,
            'due_date' => $newDueDate,
            'voided_vouchers' => array_column($journal['voided'], 'journal_voucher_id'),
            'replacement_voucher' => $replacementId,
        ]);

        return [
            'bill_type' => $billType,
            'bill_id' => $billId,
            'old_amount' => $oldTotal,
            'new_amount' => $newTotal,
            'voided_count' => count($journal['voided']),
            'replacement_voucher_id' => $replacementId,
            'replacement_jv_entry_id' => $replacement['jv_entry_id'] ?? null,
            // The number the operator will actually see in the register.
            'replacement_document_no' => $replacementId ? bl_bill_correction_document_no($billType, $billId, $old) : null,
        ];
    }
}

if (!function_exists('bl_respread_installment_schedules')) {
    /**
     * Re-split a corrected installment total across its existing schedule rows.
     * Only reachable when no schedule has been paid, so every row is rewritten.
     */
    function bl_respread_installment_schedules(PDO $pdo, int $installmentId, float $newTotal): void
    {
        $stmt = $pdo->prepare("SELECT schedule_id FROM installment_schedules WHERE installment_id = ? ORDER BY due_date, schedule_id FOR UPDATE");
        $stmt->execute([$installmentId]);
        $scheduleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = count($scheduleIds);
        if ($count === 0) {
            throw new Exception('This installment contract has no schedule rows to correct.');
        }

        $amounts = bc_split_cents($newTotal, $count);
        bc_assert_schedule_total($amounts, $newTotal, 'Corrected installment schedule');

        $update = $pdo->prepare("
            UPDATE installment_schedules
            SET amount = ?
            WHERE schedule_id = ?
              AND COALESCE(amount_paid, 0) = 0
        ");

        foreach ($scheduleIds as $index => $scheduleId) {
            $update->execute([$amounts[$index], (int)$scheduleId]);
        }
    }
}

if (!function_exists('bl_soft_delete_bill')) {
    function bl_soft_delete_bill(PDO $pdo, string $billType, int $billId, int $userId, string $reason = ''): void
    {
        if ($billId <= 0) {
            throw new Exception('Invalid bill selected.');
        }

        $reason = trim($reason) ?: 'Soft delete bill';
        $old = bl_fetch_bill_for_update($pdo, $billType, $billId);

        if (bl_is_deleted($old)) {
            throw new Exception('This bill is already marked as deleted.');
        }

        if ($billType === 'water') {
            throw new Exception('Posted water bills are immutable and cannot be soft-deleted. Use an approved reversal workflow that preserves the original snapshot.');
        }

        if ($billType === 'one_time') {
            if (round((float)($old['amount_paid'] ?? 0), 2) > 0) {
                throw new Exception('A one-time bill with recorded payments cannot be deleted. Reverse the payment through Collections first.');
            }
            $isInventory = strcasecmp(trim((string)($old['type'] ?? '')), 'Inventory') === 0;
            if ($isInventory) {
                $qty = round((float)($old['quantity'] ?? 1), 2);
                $unitCost = round((float)($old['inventory_unit_cost'] ?? 0), 2);
                if ($qty > 0) {
                    otb_add_stock_movement($pdo, (int)$old['bill_code_id'], 'ADJUSTMENT', $billId, date('Y-m-d'), $qty, 0, $unitCost,
                        "One-time bill #{$billId} deleted; inventory restored", $userId);
                }
            }
            $amountDelta = -1 * round((float)($old['total_amount'] ?? 0), 2);
            $costDelta = -1 * bl_bill_cost_total('one_time', $old);
            $voucher = bl_ac_create_bill_adjustment_journal($pdo, 'one_time', $billId, $old, $amountDelta, $costDelta, 'Soft delete', $userId, date('Y-m-d'));

            $stmt = $pdo->prepare("
                UPDATE one_time_bills
                SET
                    status = 'deleted',
                    bill_lifecycle_status = 'deleted',
                    deleted_at = NOW(),
                    deleted_by = :deleted_by,
                    delete_reason = :reason,
                    bill_edit_count = COALESCE(bill_edit_count, 0) + 1,
                    last_bill_edited_at = NOW(),
                    last_bill_edited_by = :deleted_by2,
                    updated_at = NOW()
                WHERE bill_id = :id
            ");
            $stmt->execute([
                ':deleted_by' => $userId,
                ':reason' => $reason,
                ':deleted_by2' => $userId,
                ':id' => $billId,
            ]);

            bl_ac_store_adjustment_reference($pdo, 'one_time_bills', 'bill_id', $billId, $voucher);
            bl_audit($pdo, $userId, 'SOFT_DELETE_ONE_TIME_BILL', 'one_time_bills', (string)$billId, ['reason' => $reason, 'amount_delta' => $amountDelta, 'cost_delta' => $costDelta]);
            return;
        }

        if ($billType === 'installment') {
            $paidStmt = $pdo->prepare("SELECT schedule_id FROM installment_schedules WHERE installment_id = ? AND COALESCE(amount_paid,0) > 0 FOR UPDATE");
            $paidStmt->execute([$billId]);
            if (count($paidStmt->fetchAll(PDO::FETCH_COLUMN)) > 0) {
                throw new Exception('An installment with paid schedules cannot be deleted. Reverse its payments through Collections first.');
            }
            $isInventory = strcasecmp(trim((string)($old['type'] ?? '')), 'Inventory') === 0;
            if ($isInventory) {
                $qty = round((float)($old['quantity'] ?? 1), 2);
                $unitCost = round((float)($old['inventory_unit_cost'] ?? 0), 2);
                if ($qty > 0) {
                    ib_add_stock_movement($pdo, (int)$old['bill_code_id'], 'ADJUSTMENT', $billId, date('Y-m-d'), $qty, 0, $unitCost,
                        "Installment #{$billId} deleted; inventory restored", $userId);
                }
            }
            $amountDelta = -1 * round((float)($old['total_amount'] ?? 0), 2);
            $costDelta = -1 * bl_bill_cost_total('installment', $old);
            $voucher = bl_ac_create_bill_adjustment_journal($pdo, 'installment', $billId, $old, $amountDelta, $costDelta, 'Soft delete', $userId, date('Y-m-d'));

            $stmt = $pdo->prepare("
                UPDATE installment_bills
                SET
                    status = 'deleted',
                    bill_lifecycle_status = 'deleted',
                    deleted_at = NOW(),
                    deleted_by = :deleted_by,
                    delete_reason = :reason,
                    bill_edit_count = COALESCE(bill_edit_count, 0) + 1,
                    last_bill_edited_at = NOW(),
                    last_bill_edited_by = :deleted_by2,
                    updated_at = NOW()
                WHERE installment_id = :id
            ");
            $stmt->execute([
                ':deleted_by' => $userId,
                ':reason' => $reason,
                ':deleted_by2' => $userId,
                ':id' => $billId,
            ]);

            bl_ac_store_adjustment_reference($pdo, 'installment_bills', 'installment_id', $billId, $voucher);
            bl_audit($pdo, $userId, 'SOFT_DELETE_INSTALLMENT_BILL', 'installment_bills', (string)$billId, ['reason' => $reason, 'amount_delta' => $amountDelta, 'cost_delta' => $costDelta]);
            return;
        }

        if ($billType === 'recurring') {
            if (round((float)($old['amount_paid'] ?? 0), 2) > 0) {
                throw new Exception('A recurring bill with recorded payments cannot be deleted. Reverse or refund the payment through the controlled workflow first.');
            }
            $amountDelta = -1 * round((float)($old['total_amount'] ?? 0), 2);
            $costDelta = -1 * bl_bill_cost_total('recurring', $old);
            $voucher = bl_ac_create_bill_adjustment_journal($pdo, 'recurring', $billId, $old, $amountDelta, $costDelta, 'Soft delete', $userId, date('Y-m-d'));

            $stmt = $pdo->prepare("
                UPDATE recurring_bills
                SET
                    status = 'deleted',
                    balance = 0.00,
                    bill_lifecycle_status = 'deleted',
                    deleted_at = NOW(),
                    deleted_by = :deleted_by,
                    delete_reason = :reason,
                    bill_edit_count = COALESCE(bill_edit_count, 0) + 1,
                    last_bill_edited_at = NOW(),
                    last_bill_edited_by = :deleted_by2,
                    updated_at = NOW()
                WHERE bill_id = :id
            ");
            $stmt->execute([
                ':deleted_by' => $userId,
                ':reason' => $reason,
                ':deleted_by2' => $userId,
                ':id' => $billId,
            ]);

            bl_ac_store_adjustment_reference($pdo, 'recurring_bills', 'bill_id', $billId, $voucher);
            bl_audit($pdo, $userId, 'SOFT_DELETE_RECURRING_BILL', 'recurring_bills', (string)$billId, ['reason' => $reason, 'amount_delta' => $amountDelta, 'cost_delta' => $costDelta]);
            return;
        }

        throw new Exception('Unknown bill type.');
    }
}
