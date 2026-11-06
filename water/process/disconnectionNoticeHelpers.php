<?php
require_once __DIR__ . '/billingReceiptHelpers.php';
require_once __DIR__ . '/../common/memberAddressHelpers.php';

if (!function_exists('dnh_member_name')) {
    function dnh_member_name(array $row): string
    {
        $last = trim((string)($row['lastname'] ?? ''));
        $first = trim((string)($row['firstname'] ?? ''));
        $middle = trim((string)($row['middlename'] ?? ''));
        $name = trim($last . ($last !== '' && $first !== '' ? ', ' : '') . $first . ($middle !== '' ? ' ' . $middle : ''));
        return $name !== '' ? $name : 'Member #' . (int)($row['member_id'] ?? 0);
    }
}

if (!function_exists('dnh_fetch_bill_codes')) {
    /**
     * Returns every configured bill code. A synthetic Water Bill entry is added
     * only when the water-billing setting does not point to an existing code.
     */
    function dnh_fetch_bill_codes(PDO $pdo): array
    {
        $codes = [];
        if (wbr_table_exists($pdo, 'bill_codes')) {
            $codes = $pdo->query(
                "SELECT code_id, code, description, type
                 FROM bill_codes
                 ORDER BY code, code_id"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $waterCodeId = 0;
        if (wbr_table_exists($pdo, 'water_billing_settings')) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT setting_value
                     FROM water_billing_settings
                     WHERE setting_key = 'water_billing_bill_code_id'
                     LIMIT 1"
                );
                $stmt->execute();
                $waterCodeId = max(0, (int)$stmt->fetchColumn());
            } catch (Throwable $ignored) {
                $waterCodeId = 0;
            }
        }

        $foundWaterCode = false;
        foreach ($codes as &$code) {
            $code['code_id'] = (int)($code['code_id'] ?? 0);
            $haystack = strtoupper(trim((string)($code['code'] ?? '') . ' ' . (string)($code['description'] ?? '') . ' ' . (string)($code['type'] ?? '')));
            $code['is_water'] = ($waterCodeId > 0 && $code['code_id'] === $waterCodeId)
                || str_contains($haystack, 'WATER BILL');
            if ($code['is_water']) {
                $foundWaterCode = true;
            }
        }
        unset($code);

        if (!$foundWaterCode) {
            array_unshift($codes, [
                'code_id' => 0,
                'code' => 'WATER BILL',
                'description' => 'Water Billing',
                'type' => 'water',
                'is_water' => true,
            ]);
        }

        return $codes;
    }
}

if (!function_exists('dnh_default_selected_bill_code_ids')) {
    /**
     * The reference batch form highlights Water, Meter and Fittings. Select the
     * closest matching configured bill codes by default, then fill any remaining
     * slots from the available list.
     */
    function dnh_default_selected_bill_code_ids(array $billCodes, int $limit = 3): array
    {
        $selected = [];
        $patterns = ['WATER BILL', 'METER', 'FITTING'];

        foreach ($patterns as $pattern) {
            foreach ($billCodes as $code) {
                $id = (int)($code['code_id'] ?? 0);
                $haystack = strtoupper(trim((string)($code['code'] ?? '') . ' ' . (string)($code['description'] ?? '') . ' ' . (string)($code['type'] ?? '')));
                if (str_contains($haystack, $pattern) && !in_array($id, $selected, true)) {
                    $selected[] = $id;
                    break;
                }
            }
        }

        foreach ($billCodes as $code) {
            if (count($selected) >= $limit) {
                break;
            }
            $id = (int)($code['code_id'] ?? 0);
            if (!in_array($id, $selected, true)) {
                $selected[] = $id;
            }
        }

        return array_slice($selected, 0, max(0, $limit));
    }
}

if (!function_exists('dnh_fetch_unpaid_bills')) {
    /**
     * Returns every active unpaid balance used by both the single-customer and
     * batch disconnection notices. Penalty and compromise are intentionally not
     * calculated or saved here; they remain manual print-only values.
     */
    function dnh_fetch_unpaid_bills(PDO $pdo, array $filters = []): array
    {
        $unions = [];

        if (wbr_table_exists($pdo, 'bill_readings')) {
            $unions[] = "
                SELECT 'water' AS source_type,
                       br.reading_id AS source_id,
                       br.member_id,
                       'Water Billing' AS bill_type,
                       COALESCE(bc.code_id, 0) AS bill_code_id,
                       COALESCE(NULLIF(bc.code, ''), 'WATER BILL') AS bill_code,
                       COALESCE(NULLIF(bc.description, ''), 'Water Billing') AS bill_code_description,
                       br.billing_date AS bill_date,
                       br.due_date,
                       br.disconnection_date,
                       CONCAT(
                           COALESCE(NULLIF(bp.bp_code, ''), 'Water Period'),
                           ' (', DATE_FORMAT(COALESCE(bp.start_date, br.billing_date), '%d-%b-%Y'),
                           ' to ', DATE_FORMAT(COALESCE(bp.end_date, br.billing_date), '%d-%b-%Y'), ')'
                       ) AS period_label,
                       DATE_FORMAT(COALESCE(bp.start_date, br.billing_date), '%Y-%m') AS period_month,
                       GREATEST(0, br.amount_due - COALESCE(br.amount_paid, 0)) AS principal_balance
                FROM bill_readings br
                LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
                LEFT JOIN water_billing_settings wbs ON wbs.setting_key = 'water_billing_bill_code_id'
                LEFT JOIN bill_codes bc ON bc.code_id = CAST(wbs.setting_value AS UNSIGNED)
                WHERE br.is_billed = 1
                  AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
                  AND GREATEST(0, br.amount_due - COALESCE(br.amount_paid, 0)) > 0
            ";
        }

        if (wbr_table_exists($pdo, 'one_time_bills')) {
            $unions[] = "
                SELECT 'onetime' AS source_type,
                       b.bill_id AS source_id,
                       b.member_id,
                       'One-Time Bill' AS bill_type,
                       COALESCE(bc.code_id, 0) AS bill_code_id,
                       COALESCE(NULLIF(bc.code, ''), CONCAT('ONE-TIME #', b.bill_id)) AS bill_code,
                       COALESCE(NULLIF(bc.description, ''), 'One-Time Bill') AS bill_code_description,
                       b.bill_date,
                       b.due_date,
                       b.disconnection_date,
                       DATE_FORMAT(b.bill_date, '%M %Y') AS period_label,
                       DATE_FORMAT(b.bill_date, '%Y-%m') AS period_month,
                       GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) AS principal_balance
                FROM one_time_bills b
                LEFT JOIN bill_codes bc ON bc.code_id = b.bill_code_id
                WHERE b.status NOT IN ('paid', 'cancelled', 'deleted')
                  AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                  AND GREATEST(0, b.total_amount - COALESCE(b.amount_paid, 0)) > 0
            ";
        }

        if (wbr_table_exists($pdo, 'installment_schedules') && wbr_table_exists($pdo, 'installment_bills')) {
            $unions[] = "
                SELECT 'installment' AS source_type,
                       s.schedule_id AS source_id,
                       ib.member_id,
                       'Installment Bill' AS bill_type,
                       COALESCE(bc.code_id, 0) AS bill_code_id,
                       COALESCE(NULLIF(bc.code, ''), CONCAT('INSTALLMENT #', ib.installment_id)) AS bill_code,
                       COALESCE(NULLIF(bc.description, ''), 'Installment Bill') AS bill_code_description,
                       ib.start_date AS bill_date,
                       s.due_date,
                       s.disconnection_date,
                       CONCAT(DATE_FORMAT(s.due_date, '%M %Y'), ' - Schedule #', s.schedule_id) AS period_label,
                       DATE_FORMAT(s.due_date, '%Y-%m') AS period_month,
                       GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) AS principal_balance
                FROM installment_schedules s
                INNER JOIN installment_bills ib ON ib.installment_id = s.installment_id
                LEFT JOIN bill_codes bc ON bc.code_id = ib.bill_code_id
                WHERE s.status IN ('pending', 'partial')
                  AND ib.status NOT IN ('completed', 'cancelled', 'deleted')
                  AND COALESCE(ib.bill_lifecycle_status, 'active') <> 'deleted'
                  AND GREATEST(0, s.amount - COALESCE(s.amount_paid, 0)) > 0
            ";
        }

        if (wbr_table_exists($pdo, 'recurring_bills')) {
            $unions[] = "
                SELECT 'recurring' AS source_type,
                       rb.bill_id AS source_id,
                       rb.member_id,
                       'Recurring Bill' AS bill_type,
                       COALESCE(bc.code_id, 0) AS bill_code_id,
                       COALESCE(NULLIF(bc.code, ''), CONCAT('RECURRING #', rb.bill_id)) AS bill_code,
                       COALESCE(NULLIF(bc.description, ''), 'Recurring Bill') AS bill_code_description,
                       rb.bill_date,
                       rb.due_date,
                       rb.disconnection_date,
                       CONCAT(
                           DATE_FORMAT(rb.billing_period_start, '%d-%b-%Y'),
                           ' to ', DATE_FORMAT(rb.billing_period_end, '%d-%b-%Y')
                       ) AS period_label,
                       DATE_FORMAT(rb.billing_period_start, '%Y-%m') AS period_month,
                       GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)) AS principal_balance
                FROM recurring_bills rb
                LEFT JOIN bill_codes bc ON bc.code_id = rb.bill_code_id
                WHERE rb.status IN ('unpaid', 'overdue')
                  AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted'
                  AND GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid, 0)) > 0
            ";
        }

        if (!$unions) {
            return [];
        }

        $sql = "
            SELECT x.*,
                   m.account_number,
                   m.lastname,
                   m.firstname,
                   m.middlename,
                   m.zone,
                   m.meter_sequence,
                   COALESCE(m.connection_status, 'active') AS connection_status,
                   m.disconnected_at,
                   m.disconnection_reason,
                   rc.rc_code,
                   rc.rc_name,
                   CASE
                       WHEN x.due_date IS NULL THEN 'UNPAID'
                       WHEN x.due_date < CURDATE() THEN 'OVERDUE'
                       ELSE 'NOT YET DUE'
                   END AS due_status,
                   CASE
                       WHEN x.due_date IS NULL OR x.due_date >= CURDATE() THEN 0
                       ELSE DATEDIFF(CURDATE(), x.due_date)
                   END AS days_late
            FROM (" . implode(' UNION ALL ', $unions) . ") x
            INNER JOIN water_accounts m ON m.pkey = x.member_id
            LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
            WHERE 1 = 1
        ";

        $params = [];
        $month = trim((string)($filters['month'] ?? ''));
        $memberId = max(0, (int)($filters['member_id'] ?? 0));
        $zone = max(0, (int)($filters['zone'] ?? 0));
        $search = trim((string)($filters['search'] ?? ''));

        if ($month !== '') {
            $sql .= ' AND x.period_month = ?';
            $params[] = $month;
        }
        if ($memberId > 0) {
            $sql .= ' AND x.member_id = ?';
            $params[] = $memberId;
        }
        if ($zone > 0) {
            $sql .= ' AND m.zone = ?';
            $params[] = $zone;
        }
        if ($search !== '') {
            $sql .= " AND (
                m.account_number LIKE ? OR CAST(m.pkey AS CHAR) LIKE ? OR
                CONCAT_WS(' ', m.firstname, m.middlename, m.lastname) LIKE ? OR
                x.bill_code LIKE ? OR x.bill_code_description LIKE ? OR x.period_label LIKE ?
            )";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY x.period_month DESC, m.zone, m.meter_sequence, m.lastname, m.firstname, x.due_date, x.bill_code, x.source_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['member_id'] = (int)($row['member_id'] ?? 0);
            $row['bill_code_id'] = (int)($row['bill_code_id'] ?? 0);
            $row['principal_balance'] = max(0, (float)($row['principal_balance'] ?? 0));
            $row['member_name'] = dnh_member_name($row);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('dnh_fetch_member_address')) {
    function dnh_fetch_member_address(PDO $pdo, int $memberId): string
    {
        if ($memberId <= 0 || !wbr_table_exists($pdo, 'memberaddress') || !wbr_table_exists($pdo, 'addresses')) {
            return '';
        }

        try {
            $cityJoin = wbr_table_exists($pdo, 'cities')
                ? 'LEFT JOIN cities c ON c.pkey = a.city_id'
                : '';
            $cityColumns = wbr_table_exists($pdo, 'cities')
                ? ', c.city, c.province, c.country'
                : ", '' AS city, '' AS province, '' AS country";

            $primaryAddressTypeId = member_primary_address_type_id($pdo);
            $stmt = $pdo->prepare(
                "SELECT a.housebldg, a.street, a.other_address_detail, a.zippostal_code {$cityColumns}
                 FROM memberaddress ma
                 INNER JOIN addresses a ON a.pkey = ma.address_key
                 {$cityJoin}
                 WHERE ma.member_key = ?
                   AND COALESCE(ma.status, 'A') = 'A'
                   AND COALESCE(a.status, 'A') = 'A'
                 ORDER BY CASE WHEN ma.address_type_key = ? THEN 0 ELSE 1 END,
                          ma.dtaddressFrom DESC,
                          ma.address_key DESC
                 LIMIT 1"
            );
            $stmt->execute([$memberId, $primaryAddressTypeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return '';
            }

            $local = trim(implode(' ', array_filter([
                trim((string)($row['housebldg'] ?? '')),
                trim((string)($row['street'] ?? '')),
                trim((string)($row['other_address_detail'] ?? '')),
            ], static fn($value): bool => $value !== '')));

            $city = trim((string)($row['city'] ?? ''));
            $province = trim((string)($row['province'] ?? ''));
            $country = trim((string)($row['country'] ?? ''));
            $postal = trim((string)($row['zippostal_code'] ?? ''));
            if ($postal === '0') {
                $postal = '';
            }

            $area = implode(', ', array_filter([$city, $province, $country], static fn($value): bool => $value !== ''));
            return trim(implode(' ', array_filter([$local, $area, $postal], static fn($value): bool => $value !== '')));
        } catch (Throwable $error) {
            error_log('Disconnection notice address lookup failed: ' . $error->getMessage());
            return '';
        }
    }
}

if (!function_exists('dnh_fetch_member_details')) {
    function dnh_fetch_member_details(PDO $pdo, int $memberId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT m.pkey AS member_id,
                    m.account_number,
                    m.lastname,
                    m.firstname,
                    m.middlename,
                    m.zone,
                    m.meter_sequence,
                    m.tinno,
                    COALESCE(m.connection_status, 'active') AS connection_status,
                    m.disconnected_at,
                    m.disconnection_reason,
                    rc.rc_code,
                    rc.rc_name,
                    ps.size_code AS pipe_size_code,
                    ps.size_label AS pipe_size_label,
                    ps.diameter_inches
             FROM water_accounts m
             LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
             LEFT JOIN water_pipe_sizes ps ON ps.pipe_size_id = m.pipe_size_id
             WHERE m.pkey = ?
             LIMIT 1"
        );
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['member_id'] = (int)$row['member_id'];
        $row['member_name'] = dnh_member_name($row);
        $row['address'] = dnh_fetch_member_address($pdo, $memberId);
        return $row;
    }
}

if (!function_exists('dnh_group_batch_rows')) {
    /**
     * Produces one row per member. Checked bill codes become their own columns;
     * every unchecked bill-code balance is combined into Other Income.
     */
    function dnh_group_batch_rows(array $rows, array $selectedBillCodeIds): array
    {
        $selectedBillCodeIds = array_values(array_unique(array_map('intval', $selectedBillCodeIds)));
        $grouped = [];

        foreach ($rows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }

            if (!isset($grouped[$memberId])) {
                $grouped[$memberId] = [
                    'member_id' => $memberId,
                    'account_number' => (string)($row['account_number'] ?? ''),
                    'member_name' => (string)($row['member_name'] ?? ''),
                    'zone' => (int)($row['zone'] ?? 0),
                    'meter_sequence' => (string)($row['meter_sequence'] ?? ''),
                    'connection_status' => (string)($row['connection_status'] ?? 'active'),
                    'selected_amounts' => array_fill_keys($selectedBillCodeIds, 0.0),
                    'other_income' => 0.0,
                    'principal_total' => 0.0,
                    'bill_count' => 0,
                    'earliest_due_date' => null,
                    'earliest_disconnection_date' => null,
                ];
            }

            $amount = max(0, (float)($row['principal_balance'] ?? 0));
            $billCodeId = (int)($row['bill_code_id'] ?? 0);
            if (in_array($billCodeId, $selectedBillCodeIds, true)) {
                $grouped[$memberId]['selected_amounts'][$billCodeId] += $amount;
            } else {
                $grouped[$memberId]['other_income'] += $amount;
            }

            $grouped[$memberId]['principal_total'] += $amount;
            $grouped[$memberId]['bill_count']++;

            $dueDate = trim((string)($row['due_date'] ?? ''));
            if ($dueDate !== '' && (
                $grouped[$memberId]['earliest_due_date'] === null
                || $dueDate < $grouped[$memberId]['earliest_due_date']
            )) {
                $grouped[$memberId]['earliest_due_date'] = $dueDate;
            }

            $disconnectDate = trim((string)($row['disconnection_date'] ?? ''));
            if ($disconnectDate !== '' && (
                $grouped[$memberId]['earliest_disconnection_date'] === null
                || $disconnectDate < $grouped[$memberId]['earliest_disconnection_date']
            )) {
                $grouped[$memberId]['earliest_disconnection_date'] = $disconnectDate;
            }
        }

        $result = array_values($grouped);
        usort($result, static function (array $a, array $b): int {
            $zoneCompare = ((int)$a['zone']) <=> ((int)$b['zone']);
            if ($zoneCompare !== 0) {
                return $zoneCompare;
            }

            $aSequence = trim((string)$a['meter_sequence']);
            $bSequence = trim((string)$b['meter_sequence']);
            if ($aSequence !== $bSequence) {
                return strnatcasecmp($aSequence, $bSequence);
            }

            return strcasecmp((string)$a['member_name'], (string)$b['member_name']);
        });

        return $result;
    }
}
