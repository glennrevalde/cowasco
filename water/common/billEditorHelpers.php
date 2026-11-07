<?php
/**
 * common/billEditorHelpers.php
 *
 * The bill editor of Billing Adjustments (Edit): one bill shown as the bill itself (who it is billed to, its dates,
 * its line and its totals; a water bill with its readings and the tariff lines; an installment bill with its
 * schedule), and the preview of a correction before it is saved. The correction itself is saved by
 * bl_update_bill() (billLifecycleHelpers.php); the preview computes with the same functions and writes nothing.
 */

require_once __DIR__ . '/billLifecycleHelpers.php';
require_once __DIR__ . '/accountProfileHelpers.php';
require_once __DIR__ . '/waterRateBillingHelpers.php';
require_once __DIR__ . '/waterBillingSnapshotHelpers.php';

const BE_TYPES = [
    'water' => 'Water Bill',
    'one_time' => 'One-Time Bill',
    'installment' => 'Installment Bill',
    'recurring' => 'Recurring Bill',
];

if (!function_exists('be_parse_key')) {
    /** "one_time-8" (the editor's address) -> ['one_time', 8]; null when it is not a bill key */
    function be_parse_key(string $key): ?array
    {
        if (!preg_match('/^(water|one_time|installment|recurring)-([1-9]\d{0,9})$/', trim($key), $m)) {
            return null;
        }
        return [$m[1], (int)$m[2]];
    }
}

if (!function_exists('be_tier_lines')) {
    /** The tariff lines of a water calculation, as the bill prints them */
    function be_tier_lines(array $details): array
    {
        $out = [];
        foreach (($details['gross_lines'] ?? []) as $line) {
            $from = (int)($line['from_cb'] ?? 0);
            $to = $line['to_cb'] ?? null;
            $range = $to === null ? $from . ' m³ and up' : $from . ' to ' . (int)$to . ' m³';
            $fixed = strtoupper((string)($line['rate_bill_type'] ?? '')) === 'FIXED';
            $out[] = [
                'range' => $range,
                'basis' => $fixed ? 'Minimum charge' : number_format((float)($line['rate_amount'] ?? 0), 2) . ' per m³',
                'm3' => (int)($line['tier_consumption'] ?? 0),
                'amount' => round((float)($line['gross_amount'] ?? 0), 2),
            ];
        }
        return $out;
    }
}

if (!function_exists('be_water_figures')) {
    /** The water figures of a calculation (stored or recalculated) the editor shows */
    function be_water_figures(array $details): array
    {
        $rate = $details['rate_code'] ?? [];
        $pipe = $details['pipe_size'] ?? [];
        return [
            'consumption' => (int)($details['consumption'] ?? 0),
            'free_m3' => (int)($details['free_m3'] ?? 0),
            'billable_m3' => (int)($details['billable_m3'] ?? ($details['consumption'] ?? 0)),
            'base_charge' => round((float)($details['base_charge'] ?? 0), 2),
            'free_discount' => round((float)($details['free_cubic_discount_amount'] ?? 0), 2),
            'other_discount' => round((float)($details['monetary_discount_amount'] ?? 0), 2),
            'discount' => round((float)($details['discount_amount'] ?? 0), 2),
            'amount' => round((float)($details['amount_due'] ?? 0), 2),
            'rate_code' => wa_join([$rate['rc_code'] ?? '', $rate['rc_name'] ?? ''], ' - '),
            'pipe' => (string)($pipe['size_label'] ?? ''),
            'method' => (string)($details['billing_method_label'] ?? ''),
            'discount_status' => (string)($details['discount_status'] ?? ''),
            'tiers' => be_tier_lines($details),
            'warning' => (string)($details['snapshot_warning'] ?? ''),
        ];
    }
}

if (!function_exists('be_bill_code')) {
    function be_bill_code(PDO $pdo, int $codeId): array
    {
        $stmt = $pdo->prepare('SELECT code_id, code, description, type, sales_price FROM bill_codes WHERE code_id = ?');
        $stmt->execute([$codeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['code_id' => $codeId, 'code' => '', 'description' => '', 'type' => '', 'sales_price' => null];
    }
}

if (!function_exists('be_bill_document')) {
    /**
     * One bill as the editor shows it. Throws when the bill does not exist.
     * Keys: type, id, type_label, invoice_no, status, posted, editable, locked_reason, account, bill_date, due_date,
     * term_days, period (code, start, end), class_name, edit_count, last_edited_at, revision_note, total, paid,
     * balance, disconnection_date, line (one-time, installment, recurring), water, schedule (installment),
     * installment (payment mode, term, start).
     */
    function be_bill_document(PDO $pdo, string $type, int $id): array
    {
        if (!isset(BE_TYPES[$type])) {
            throw new Exception('Unknown bill type.');
        }
        $row = bl_fetch_bill_for_update($pdo, $type, $id, false);
        [$editable, $why] = bl_bill_is_editable($pdo, $type, $id, $row);
        $paidRecorded = bl_bill_recorded_paid($pdo, $type, $id, $row);

        $doc = [
            'type' => $type,
            'id' => $id,
            'type_label' => BE_TYPES[$type],
            'invoice_no' => bl_bill_invoice_number($type, $id, $row),
            'posted' => true,
            'editable' => $editable,
            'locked_reason' => $why,
            'account' => wa_account_profile($pdo, (int)($row['member_id'] ?? 0)) ?? [
                'id' => (int)($row['member_id'] ?? 0), 'account_number' => (string)(int)($row['member_id'] ?? 0),
                'name' => 'Account ' . (int)($row['member_id'] ?? 0), 'kind' => 'Customer', 'member_no' => '',
                'address' => '', 'phone' => '', 'email' => '', 'zone' => '', 'status' => 'A',
            ],
            'bill_date' => null,
            'due_date' => $row['due_date'] ?? null,
            'term_days' => isset($row['term_days']) && $row['term_days'] !== null ? (int)$row['term_days'] : null,
            'period' => null,
            'class_name' => function_exists('water_class_name') ? water_class_name($row['class_id'] ?? 0) : '',
            'edit_count' => (int)($row['bill_edit_count'] ?? 0),
            'last_edited_at' => $row['last_bill_edited_at'] ?? null,
            'revision_note' => trim((string)($row['revision_note'] ?? '')),
            'disconnection_date' => $row['disconnection_date'] ?? null,
            'disconnection_days' => (int)($row['disconnection_days'] ?? 0),
            'line' => null,
            'water' => null,
            'schedule' => null,
            'installment' => null,
            'status' => '',
            'deleted' => bl_is_deleted($row),
        ];

        if ($type === 'water') {
            $doc['posted'] = (int)($row['is_billed'] ?? 0) === 1;
            $doc['bill_date'] = $row['billing_date'] ?? null;
            $stmt = $pdo->prepare('SELECT bp_code, start_date, end_date FROM bill_periods WHERE period_id = ?');
            $stmt->execute([(int)($row['period_id'] ?? 0)]);
            if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $doc['period'] = ['code' => (string)$p['bp_code'], 'start' => $p['start_date'], 'end' => $p['end_date']];
            }
            try {
                $details = water_snapshot_details_for_reading($row);
            } catch (Throwable $e) {
                $details = ['consumption' => (int)($row['consumption'] ?? 0), 'amount_due' => (float)($row['amount_due'] ?? 0),
                    'base_charge' => (float)($row['amount_due'] ?? 0), 'snapshot_warning' => $e->getMessage()];
            }
            $doc['water'] = be_water_figures($details) + [
                'previous' => (int)($row['prev_reading'] ?? 0),
                'present' => (int)($row['pres_reading'] ?? 0),
            ];
            $doc['water']['consumption'] = (int)($row['consumption'] ?? $doc['water']['consumption']);
            $total = round((float)($row['amount_due'] ?? 0), 2);
            $paid = min($total, round((float)($row['amount_paid'] ?? 0), 2));
            $doc['status'] = $doc['deleted'] ? 'Deleted' : (!$doc['posted'] ? 'Not posted (reading draft)'
                : ($paid >= $total && $total > 0 ? 'Paid' : ($paid > 0 ? 'Partly paid' : 'Unpaid')));
        } else {
            $code = be_bill_code($pdo, (int)($row['bill_code_id'] ?? 0));
            $total = round((float)($row['total_amount'] ?? 0), 2);
            $gross = round((float)($row['gross_amount'] ?? 0), 2);
            $discount = round((float)($row['discount_amount'] ?? 0), 2);
            if ($gross <= 0) {
                $gross = round($total + $discount, 2);
            }
            $quantityBased = bc_is_inventory($code) && in_array($type, ['one_time', 'installment'], true);
            $quantity = $quantityBased ? round((float)($row['quantity'] ?? 1), 2) : 1.0;
            $doc['line'] = [
                'code' => (string)($code['code'] ?? ''),
                'description' => trim((string)($code['description'] ?? '')),
                'code_type' => (string)($code['type'] ?? ''),
                'quantity_based' => $quantityBased,
                'quantity' => $quantity,
                'unit_price' => $quantity > 0 ? round($gross / $quantity, 2) : $gross,
                'list_price' => $code['sales_price'] !== null ? round((float)$code['sales_price'], 2) : null,
                'gross' => $gross,
                'discount' => $discount,
                'total' => $total,
            ];
            $status = strtolower((string)($row['status'] ?? ''));
            if ($type === 'installment') {
                $doc['bill_date'] = $row['start_date'] ?? null;
                $doc['due_date'] = null;
                $doc['schedule'] = bl_installment_schedule($pdo, $id);
                $paid = 0.0;
                foreach ($doc['schedule'] as $s) {
                    $paid += min((float)$s['amount'], (float)$s['amount_paid']);
                }
                $paid = round($paid, 2);
                $modes = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annually' => 'Semi-annually', 'annually' => 'Annually'];
                $doc['installment'] = [
                    'payment_mode' => $modes[(string)($row['payment_mode'] ?? '')] ?? ucfirst((string)($row['payment_mode'] ?? '')),
                    'term' => (int)($row['term'] ?? 0),
                    'amortization' => ucfirst((string)($row['amortization_type'] ?? 'fixed')),
                ];
                $labels = ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'deleted' => 'Deleted'];
            } else {
                $doc['bill_date'] = $row['bill_date'] ?? null;
                $paid = min($total, round((float)($row['amount_paid'] ?? 0), 2));
                if ($type === 'recurring') {
                    $doc['period'] = ['code' => '', 'start' => $row['billing_period_start'] ?? null, 'end' => $row['billing_period_end'] ?? null];
                }
                $labels = ['unpaid' => $paid > 0 ? 'Partly paid' : 'Unpaid', 'paid' => 'Paid', 'overdue' => 'Overdue',
                    'cancelled' => 'Cancelled', 'deleted' => 'Deleted'];
            }
            $doc['status'] = $doc['deleted'] ? 'Deleted' : ($labels[$status] ?? ucfirst($status));
        }

        $doc['total'] = $total;
        $doc['paid'] = max($paid, min($total, round($paidRecorded, 2)));
        $doc['balance'] = round(max(0, $total - $doc['paid']), 2);
        return $doc;
    }
}

if (!function_exists('be_preview')) {
    /**
     * The bill as the correction typed in the editor would make it (nothing is written). Throws with the message the
     * save would give when the correction would be refused.
     */
    function be_preview(PDO $pdo, string $type, int $id, array $data): array
    {
        if (!isset(BE_TYPES[$type])) {
            throw new Exception('Unknown bill type.');
        }
        $old = bl_fetch_bill_for_update($pdo, $type, $id, false);
        [$editable, $why] = bl_bill_is_editable($pdo, $type, $id, $old);
        if (!$editable) {
            throw new Exception($why);
        }

        if ($type === 'water') {
            $c = bl_water_correction($pdo, $old, $data['pres_reading'] ?? null, true);
            $oldAmount = round((float)($old['amount_due'] ?? 0), 2);
            return [
                'type' => 'water',
                'previous' => $c['previous'],
                'present' => $c['present'],
                'water' => be_water_figures($c['details']),
                'total' => $c['amount'],
                'old_total' => $oldAmount,
                'change' => round($c['amount'] - $oldAmount, 2),
            ];
        }

        $c = bl_other_correction($pdo, $type, $old, $data, true);
        $out = [
            'type' => $type,
            'quantity' => $c['new_quantity'],
            // a material is priced per unit from its bill code; any other bill is one unit at its amount
            'unit_price' => $c['quantity_based'] ? $c['unit_price'] : $c['new_gross'],
            'gross' => $c['new_gross'],
            'discount' => $c['old_discount'],
            'total' => $c['new_total'],
            'old_total' => $c['old_total'],
            'change' => round($c['new_total'] - $c['old_total'], 2),
            'due_date' => $c['new_due_date'],
            'term_days' => $c['new_term_days'],
            'disconnection_date' => $c['new_disconnection_date'],
        ];
        if ($type === 'installment') {
            $rows = bl_installment_schedule($pdo, $id);
            if (!$rows) {
                throw new Exception('This installment contract has no schedule rows to correct.');
            }
            $amounts = bc_split_cents($c['new_total'], count($rows));
            $out['schedule'] = [];
            foreach ($rows as $i => $r) {
                $out['schedule'][] = ['due_date' => $r['due_date'], 'amount' => $amounts[$i], 'old_amount' => round((float)$r['amount'], 2)];
            }
        }
        return $out;
    }
}
