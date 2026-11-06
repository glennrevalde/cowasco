<?php
/**
 * process/disconnectionHelpers.php
 * Manual disconnection workflow helpers.
 *
 * Important: this helper NEVER automatically disconnects a customer.
 * It only finds unpaid bills whose disconnection date has arrived,
 * and provides functions for an explicit user action.
 */

require_once __DIR__ . '/billingReceiptHelpers.php';

if (!function_exists('mdh_pdo')) {
    function mdh_pdo(): PDO
    {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) return $pdo;
        if (function_exists('getDB')) return getDB();
        throw new RuntimeException('Database connection was not found.');
    }
}

if (!function_exists('mdh_user_id')) {
    function mdh_user_id(): int
    {
        return (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    }
}

if (!function_exists('mdh_member_name')) {
    function mdh_member_name(array $row): string
    {
        return function_exists('wbr_member_name') ? wbr_member_name($row) : trim(($row['lastname'] ?? '') . ', ' . ($row['firstname'] ?? ''), ', ');
    }
}

if (!function_exists('mdh_money')) {
    function mdh_money($amount): string
    {
        return function_exists('wbr_money') ? wbr_money($amount) : number_format((float)$amount, 2);
    }
}

if (!function_exists('mdh_date')) {
    function mdh_date($date): string
    {
        return function_exists('wbr_date') ? wbr_date($date) : ($date ? date('d-M-y', strtotime($date)) : '—');
    }
}

if (!function_exists('mdh_filter_rows')) {
    function mdh_filter_rows(array $rows, string $search): array
    {
        $search = trim(function_exists('mb_strtolower') ? mb_strtolower($search) : strtolower($search));
        if ($search === '') return $rows;

        return array_values(array_filter($rows, function (array $row) use ($search): bool {
            $haystack = function_exists('mb_strtolower') ? mb_strtolower(implode(' ', [
                $row['account_number'] ?? '',
                $row['member_name'] ?? '',
                $row['bill_type'] ?? '',
                $row['bill_label'] ?? '',
                $row['bill_code'] ?? '',
                $row['rc_code'] ?? '',
                $row['rc_name'] ?? '',
            ])) : strtolower(implode(' ', [
                $row['account_number'] ?? '',
                $row['member_name'] ?? '',
                $row['bill_type'] ?? '',
                $row['bill_label'] ?? '',
                $row['bill_code'] ?? '',
                $row['rc_code'] ?? '',
                $row['rc_name'] ?? '',
            ]));
            return strpos($haystack, $search) !== false;
        }));
    }
}

if (!function_exists('mdh_fetch_bill_codes')) {
    function mdh_fetch_bill_codes(PDO $pdo): array
    {
        if (!wbr_table_exists($pdo, 'bill_codes')) return [];
        return $pdo->query("SELECT code_id, code, description, type FROM bill_codes ORDER BY code, code_id")
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('mdh_fetch_for_disconnection')) {
    function mdh_fetch_for_disconnection(PDO $pdo, string $search = '', int $billCodeId = 0): array
    {
        $unions = [];

        if (wbr_table_exists($pdo, 'bill_readings') && wbr_column_exists($pdo, 'bill_readings', 'disconnection_date')) {
            $unions[] = "
                SELECT 'water' AS source_type, br.reading_id AS source_id, br.member_id,
                       'Water Billing' AS bill_type, CONCAT('Water Bill #', br.reading_id) AS bill_label,
                       COALESCE(bc.code_id,0) AS bill_code_id, COALESCE(bc.code,'WATER BILL') AS bill_code,
                       br.billing_date AS bill_date, br.due_date, br.disconnection_date,
                       br.amount_due AS total_amount, COALESCE(br.amount_paid,0) AS amount_paid,
                       GREATEST(0, br.amount_due-COALESCE(br.amount_paid,0)) AS balance
                FROM bill_readings br
                LEFT JOIN water_billing_settings wbs ON wbs.setting_key='water_billing_bill_code_id'
                LEFT JOIN bill_codes bc ON bc.code_id=CAST(wbs.setting_value AS UNSIGNED)
                WHERE br.is_billed=1
                  AND COALESCE(br.bill_lifecycle_status,'active')<>'deleted'
                  AND GREATEST(0, br.amount_due-COALESCE(br.amount_paid,0))>0
                  AND br.disconnection_date IS NOT NULL AND br.disconnection_date<=CURDATE()
            ";
        }
        if (wbr_table_exists($pdo, 'recurring_bills') && wbr_column_exists($pdo, 'recurring_bills', 'disconnection_date')) {
            $unions[] = "
                SELECT 'recurring' AS source_type, rb.bill_id AS source_id, rb.member_id,
                       'Recurring Bill' AS bill_type, COALESCE(bc.code,CONCAT('Recurring Bill #',rb.bill_id)) AS bill_label,
                       COALESCE(bc.code_id,0) AS bill_code_id, COALESCE(bc.code,'RECURRING') AS bill_code,
                       rb.bill_date, rb.due_date, rb.disconnection_date, rb.total_amount, COALESCE(rb.amount_paid,0) AS amount_paid,
                       GREATEST(0,rb.total_amount-COALESCE(rb.amount_paid,0)) AS balance
                FROM recurring_bills rb LEFT JOIN bill_codes bc ON bc.code_id=rb.bill_code_id
                WHERE rb.status NOT IN ('paid','cancelled','deleted')
                  AND COALESCE(rb.bill_lifecycle_status,'active')<>'deleted'
                  AND GREATEST(0,rb.total_amount-COALESCE(rb.amount_paid,0))>0
                  AND rb.disconnection_date IS NOT NULL AND rb.disconnection_date<=CURDATE()
            ";
        }
        if (wbr_table_exists($pdo, 'one_time_bills') && wbr_column_exists($pdo, 'one_time_bills', 'disconnection_date')) {
            $unions[] = "
                SELECT 'onetime' AS source_type, otb.bill_id AS source_id, otb.member_id,
                       'One-Time Bill' AS bill_type, COALESCE(bc.code,CONCAT('One-Time Bill #',otb.bill_id)) AS bill_label,
                       COALESCE(bc.code_id,0) AS bill_code_id, COALESCE(bc.code,'ONE-TIME') AS bill_code,
                       otb.bill_date, otb.due_date, otb.disconnection_date, otb.total_amount, COALESCE(otb.amount_paid,0) AS amount_paid,
                       GREATEST(0,otb.total_amount-COALESCE(otb.amount_paid,0)) AS balance
                FROM one_time_bills otb LEFT JOIN bill_codes bc ON bc.code_id=otb.bill_code_id
                WHERE otb.status NOT IN ('paid','cancelled','deleted')
                  AND COALESCE(otb.bill_lifecycle_status,'active')<>'deleted'
                  AND GREATEST(0,otb.total_amount-COALESCE(otb.amount_paid,0))>0
                  AND otb.disconnection_date IS NOT NULL AND otb.disconnection_date<=CURDATE()
            ";
        }
        if (wbr_table_exists($pdo, 'installment_schedules') && wbr_column_exists($pdo, 'installment_schedules', 'disconnection_date') && wbr_table_exists($pdo, 'installment_bills')) {
            $unions[] = "
                SELECT 'installment' AS source_type, s.schedule_id AS source_id, ib.member_id,
                       'Installment Bill' AS bill_type, CONCAT(COALESCE(bc.code,'Installment'),' Schedule #',s.schedule_id) AS bill_label,
                       COALESCE(bc.code_id,0) AS bill_code_id, COALESCE(bc.code,'INSTALLMENT') AS bill_code,
                       ib.start_date AS bill_date, s.due_date, s.disconnection_date, s.amount AS total_amount,
                       COALESCE(s.amount_paid,0) AS amount_paid, GREATEST(0,s.amount-COALESCE(s.amount_paid,0)) AS balance
                FROM installment_schedules s
                INNER JOIN installment_bills ib ON ib.installment_id=s.installment_id
                LEFT JOIN bill_codes bc ON bc.code_id=ib.bill_code_id
                WHERE s.status IN ('pending','partial')
                  AND ib.status NOT IN ('cancelled','deleted')
                  AND COALESCE(ib.bill_lifecycle_status,'active')<>'deleted'
                  AND GREATEST(0,s.amount-COALESCE(s.amount_paid,0))>0
                  AND s.disconnection_date IS NOT NULL AND s.disconnection_date<=CURDATE()
            ";
        }
        if (!$unions) return [];

        $sql = "SELECT x.*,m.pkey AS member_pkey,m.account_number,m.lastname,m.firstname,m.middlename,
                       COALESCE(m.connection_status,'active') AS connection_status,m.disconnected_at,m.disconnection_reason,
                       rc.rc_code,rc.rc_name
                FROM (".implode(' UNION ALL ',$unions).") x
                INNER JOIN water_accounts m ON m.pkey=x.member_id
                LEFT JOIN rate_code rc ON rc.rc_id=m.rc_id";
        $params=[];
        if ($billCodeId>0) { $sql.=' WHERE x.bill_code_id=?'; $params[]=$billCodeId; }
        $sql.=' ORDER BY x.disconnection_date,x.due_date,m.lastname,m.firstname';
        $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['member_name']=mdh_member_name($row);
            $row['days_after_disconnection_date']=0;
            if (!empty($row['disconnection_date'])) {
                $d1=new DateTime((string)$row['disconnection_date']); $d2=new DateTime(date('Y-m-d'));
                $row['days_after_disconnection_date']=max(0,(int)$d1->diff($d2)->format('%r%a'));
            }
        }
        unset($row);
        return mdh_filter_rows($rows,$search);
    }
}

if (!function_exists('mdh_fetch_disconnected_members')) {
    function mdh_fetch_disconnected_members(PDO $pdo, string $search = ''): array
    {
        if (!wbr_table_exists($pdo, 'water_accounts') || !wbr_column_exists($pdo, 'water_accounts', 'connection_status')) {
            return [];
        }

        $sql = "
            SELECT m.pkey AS member_id,
                   m.account_number,
                   m.lastname,
                   m.firstname,
                   m.middlename,
                   m.connection_status,
                   m.disconnected_at,
                   m.disconnected_by,
                   m.disconnection_reason,
                   rc.rc_code,
                   rc.rc_name,
                   COALESCE(NULLIF(u.description, ''), u.name) AS disconnected_by_name,
                   u.name AS disconnected_by_username
            FROM water_accounts m
            LEFT JOIN rate_code rc ON rc.rc_id = m.rc_id
            LEFT JOIN operator u ON u.pkey = m.disconnected_by
            WHERE m.connection_status = 'disconnected'
            ORDER BY m.disconnected_at DESC, m.lastname ASC, m.firstname ASC
        ";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['member_name'] = mdh_member_name($row);
        }
        unset($row);

        return mdh_filter_rows($rows, $search);
    }
}

if (!function_exists('mdh_fetch_bill_by_source')) {
    function mdh_fetch_bill_by_source(PDO $pdo, string $sourceType, int $sourceId): array
    {
        $sourceType = strtolower(trim($sourceType));

        if ($sourceType === 'water') {
            $stmt = $pdo->prepare("
                SELECT 'water' AS source_type,
                       br.reading_id AS source_id,
                       br.member_id,
                       'Water Billing' AS bill_type,
                       CONCAT('Water Bill #', br.reading_id) AS bill_label,
                       br.billing_date AS bill_date,
                       br.due_date,
                       br.disconnection_date,
                       br.amount_due AS total_amount,
                       COALESCE(br.amount_paid,0) AS amount_paid,
                       GREATEST(0, br.amount_due - COALESCE(br.amount_paid,0)) AS balance,
                       IF(COALESCE(br.amount_paid,0) >= br.amount_due, 1, 0) AS paid_flag
                FROM bill_readings br
                WHERE br.reading_id = ?
                  AND br.is_billed = 1
                  AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1
            ");
            $stmt->execute([$sourceId]);
        } elseif ($sourceType === 'recurring') {
            $stmt = $pdo->prepare("
                SELECT 'recurring' AS source_type,
                       rb.bill_id AS source_id,
                       rb.member_id,
                       'Recurring Bill' AS bill_type,
                       COALESCE(bc.code, CONCAT('Recurring Bill #', rb.bill_id)) AS bill_label,
                       rb.bill_date,
                       rb.due_date,
                       rb.disconnection_date,
                       rb.total_amount,
                       COALESCE(rb.amount_paid,0) AS amount_paid,
                       GREATEST(0, rb.total_amount - COALESCE(rb.amount_paid,0)) AS balance,
                       IF(COALESCE(rb.amount_paid,0) >= rb.total_amount OR rb.status = 'paid', 1, 0) AS paid_flag
                FROM recurring_bills rb
                LEFT JOIN bill_codes bc ON bc.code_id = rb.bill_code_id
                WHERE rb.bill_id = ?
                  AND rb.status NOT IN ('paid','cancelled','deleted')
                  AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1
            ");
            $stmt->execute([$sourceId]);
        } elseif ($sourceType === 'onetime') {
            $stmt = $pdo->prepare("
                SELECT 'onetime' AS source_type,
                       otb.bill_id AS source_id,
                       otb.member_id,
                       'One-Time Bill' AS bill_type,
                       COALESCE(bc.code, CONCAT('One-Time Bill #', otb.bill_id)) AS bill_label,
                       otb.bill_date,
                       otb.due_date,
                       otb.disconnection_date,
                       otb.total_amount,
                       COALESCE(otb.amount_paid,0) AS amount_paid,
                       GREATEST(0, otb.total_amount - COALESCE(otb.amount_paid,0)) AS balance,
                       IF(COALESCE(otb.amount_paid,0) >= otb.total_amount OR otb.status = 'paid', 1, 0) AS paid_flag
                FROM one_time_bills otb
                LEFT JOIN bill_codes bc ON bc.code_id = otb.bill_code_id
                WHERE otb.bill_id = ?
                  AND otb.status NOT IN ('paid','cancelled','deleted')
                  AND COALESCE(otb.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1
            ");
            $stmt->execute([$sourceId]);
        } elseif ($sourceType === 'installment') {
            $stmt = $pdo->prepare("
                SELECT 'installment' AS source_type,
                       s.schedule_id AS source_id,
                       ib.member_id,
                       'Installment Bill' AS bill_type,
                       CONCAT(COALESCE(bc.code, 'Installment'), ' Schedule #', s.schedule_id) AS bill_label,
                       ib.start_date AS bill_date,
                       s.due_date,
                       s.disconnection_date,
                       s.amount AS total_amount,
                       COALESCE(s.amount_paid,0) AS amount_paid,
                       GREATEST(0, s.amount - COALESCE(s.amount_paid,0)) AS balance,
                       IF(COALESCE(s.amount_paid,0) >= s.amount, 1, 0) AS paid_flag
                FROM installment_schedules s
                INNER JOIN installment_bills ib ON ib.installment_id = s.installment_id
                LEFT JOIN bill_codes bc ON bc.code_id = ib.bill_code_id
                WHERE s.schedule_id = ?
                  AND s.status IN ('pending','partial')
                  AND ib.status NOT IN ('cancelled','deleted')
                  AND COALESCE(ib.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1
            ");
            $stmt->execute([$sourceId]);
        } else {
            throw new InvalidArgumentException('Invalid bill source type.');
        }

        $bill = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bill) throw new RuntimeException('Bill source was not found.');

        $memberStmt = $pdo->prepare("SELECT * FROM water_accounts WHERE pkey = ? LIMIT 1");
        $memberStmt->execute([(int)$bill['member_id']]);
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) throw new RuntimeException('Member was not found.');

        $bill['connection_status'] = $member['connection_status'] ?? 'active';
        $bill['member_name'] = mdh_member_name($member);
        $bill['account_number'] = $member['account_number'] ?? '';
        return $bill;
    }
}

if (!function_exists('mdh_manual_disconnect')) {
    function mdh_manual_disconnect(PDO $pdo, string $sourceType, int $sourceId, string $reason, int $userId): void
    {
        $bill = mdh_fetch_bill_by_source($pdo, $sourceType, $sourceId);

        if ((int)($bill['paid_flag'] ?? 0) === 1 || (float)($bill['balance'] ?? 0) <= 0) {
            throw new RuntimeException('This bill is already paid, so the customer should not be disconnected for it.');
        }

        if (empty($bill['disconnection_date'])) {
            throw new RuntimeException('This bill has no disconnection date.');
        }

        if (strtotime((string)$bill['disconnection_date']) > strtotime(date('Y-m-d'))) {
            throw new RuntimeException('The disconnection date has not arrived yet.');
        }

        $memberId = (int)$bill['member_id'];
        $previousStatus = (string)($bill['connection_status'] ?? 'active');

        if ($previousStatus === 'disconnected') {
            throw new RuntimeException('This customer is already marked as disconnected.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Unpaid bill reached disconnection date. Manual disconnection by authorized user.';
        }

        $update = $pdo->prepare("
            UPDATE water_accounts
            SET connection_status = 'disconnected',
                disconnected_at = NOW(),
                disconnected_by = :user_id,
                disconnection_reason = :reason,
                reconnected_at = NULL,
                reconnected_by = NULL,
                reconnection_reason = NULL
            WHERE pkey = :member_id
        ");
        $update->execute([
            ':user_id' => $userId ?: null,
            ':reason' => $reason,
            ':member_id' => $memberId,
        ]);

        $log = $pdo->prepare("
            INSERT INTO manual_disconnection_logs
                (member_id, action, bill_source_type, bill_source_id, amount_due, due_date, disconnection_date, previous_status, new_status, reason, action_by, action_at)
            VALUES
                (:member_id, 'DISCONNECT', :source_type, :source_id, :amount_due, :due_date, :disconnection_date, :previous_status, 'disconnected', :reason, :action_by, NOW())
        ");
        $log->execute([
            ':member_id' => $memberId,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':amount_due' => (float)$bill['balance'],
            ':due_date' => $bill['due_date'] ?? null,
            ':disconnection_date' => $bill['disconnection_date'] ?? null,
            ':previous_status' => $previousStatus,
            ':reason' => $reason,
            ':action_by' => $userId ?: null,
        ]);
    }
}

if (!function_exists('mdh_manual_disconnect_bill_code')) {
    function mdh_manual_disconnect_bill_code(PDO $pdo, int $billCodeId, string $reason, int $userId): array
    {
        if ($billCodeId <= 0) throw new RuntimeException('Select a specific bill code first.');
        $rows = mdh_fetch_for_disconnection($pdo, '', $billCodeId);
        $seenMembers = [];
        $count = 0;
        foreach ($rows as $row) {
            $memberId = (int)($row['member_id'] ?? 0);
            if ($memberId <= 0 || isset($seenMembers[$memberId]) || ($row['connection_status'] ?? 'active') === 'disconnected') continue;
            $seenMembers[$memberId] = true;
            $rowReason = trim($reason);
            if ($rowReason === '') $rowReason = 'Unpaid ' . ($row['bill_code'] ?? 'bill code') . ' reached its disconnection date. Manual bill-code disconnection.';
            mdh_manual_disconnect($pdo, (string)$row['source_type'], (int)$row['source_id'], $rowReason, $userId);
            $count++;
        }
        return ['disconnected_count' => $count, 'eligible_bill_count' => count($rows)];
    }
}

if (!function_exists('mdh_manual_reconnect')) {
    function mdh_manual_reconnect(PDO $pdo, int $memberId, string $reason, int $userId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM water_accounts WHERE pkey = ? LIMIT 1");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member) throw new RuntimeException('Member was not found.');

        $previousStatus = (string)($member['connection_status'] ?? 'active');
        if ($previousStatus !== 'disconnected') {
            throw new RuntimeException('This customer is not currently disconnected.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Manual reconnection by authorized user.';
        }

        $update = $pdo->prepare("
            UPDATE water_accounts
            SET connection_status = 'active',
                reconnected_at = NOW(),
                reconnected_by = :user_id,
                reconnection_reason = :reason
            WHERE pkey = :member_id
        ");
        $update->execute([
            ':user_id' => $userId ?: null,
            ':reason' => $reason,
            ':member_id' => $memberId,
        ]);

        $log = $pdo->prepare("
            INSERT INTO manual_disconnection_logs
                (member_id, action, bill_source_type, bill_source_id, amount_due, due_date, disconnection_date, previous_status, new_status, reason, action_by, action_at)
            VALUES
                (:member_id, 'RECONNECT', NULL, NULL, 0.00, NULL, NULL, :previous_status, 'active', :reason, :action_by, NOW())
        ");
        $log->execute([
            ':member_id' => $memberId,
            ':previous_status' => $previousStatus,
            ':reason' => $reason,
            ':action_by' => $userId ?: null,
        ]);
    }
}
