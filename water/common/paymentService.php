<?php
/**
 * Canonical collection/payment service.
 *
 * All collections, partial payments, payment reversals/refunds, and controlled
 * bill debit/credit adjustments must pass through this file. Client-submitted
 * balances, penalties, paid flags, and bill statuses are never trusted.
 */

require_once __DIR__ . '/billCreationHelpers.php';
require_once __DIR__ . '/billLifecycleHelpers.php';
require_once __DIR__ . '/collectionReceiptHelpers.php';
require_once __DIR__ . '/../process/collectionAccountingHelpers.php';
require_once __DIR__ . '/../process/organizationUnitHelpers.php';

if (!function_exists('ps_money_to_cents')) {
    /** Parse a non-negative DECIMAL(14,2) value into exact integer cents. */
    function ps_money_to_cents($value, string $label = 'Amount'): int
    {
        if ($value === null || $value === '') return 0;
        if (is_int($value)) {
            $raw = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) throw new Exception($label . ' is invalid.');
            $raw = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        } else {
            $raw = trim((string)$value);
        }
        if (!preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $raw, $match)) {
            throw new Exception($label . ' must be a non-negative decimal number.');
        }
        $whole = ltrim($match[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) > 12 || (strlen($whole) === 12 && strcmp($whole, '999999999999') > 0)) {
            throw new Exception($label . ' exceeds DECIMAL(14,2).');
        }
        $fraction = str_pad($match[2] ?? '', 3, '0');
        $cents = ((int)$whole * 100) + (int)substr($fraction, 0, 2);
        if ((int)$fraction[2] >= 5) $cents++;
        if ($cents > 99999999999999) throw new Exception($label . ' exceeds DECIMAL(14,2) after rounding.');
        return $cents;
    }
}

if (!function_exists('ps_percent_to_units')) {
    /** Parse a non-negative percentage into exact 1/10,000-percent units. */
    function ps_percent_to_units($value, string $label = 'Percentage'): int
    {
        if ($value === null || $value === '') return 0;
        $raw = trim((string)$value);
        if (!preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $raw, $match)) {
            throw new Exception($label . ' must be a non-negative decimal number.');
        }
        $whole = (int)$match[1];
        $fraction = str_pad($match[2] ?? '', 5, '0');
        $units = ($whole * 10000) + (int)substr($fraction, 0, 4);
        if ((int)$fraction[4] >= 5) $units++;
        if ($units > 1000000) {
            throw new Exception($label . ' cannot exceed 100%.');
        }
        return $units;
    }
}

if (!function_exists('ps_cents_to_money')) {
    function ps_cents_to_money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}

if (!function_exists('ps_setting')) {
    function ps_setting(PDO $pdo, string $key, ?string $default = null): ?string
    {
        $stmt = $pdo->prepare("SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : (string)$value;
    }
}

if (!function_exists('ps_validate_business_date')) {
    function ps_validate_business_date(PDO $pdo, string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception('Invalid payment date. Use YYYY-MM-DD.');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) {
            throw new Exception('Invalid payment date.');
        }

        $today = new DateTimeImmutable('today');
        $businessDate = new DateTimeImmutable($date);
        if ($businessDate > $today) {
            throw new Exception('Future-dated payments are not allowed.');
        }

        $maxBackdateDays = max(0, (int)(ps_setting($pdo, 'collection_max_backdate_days', '31') ?? 31));
        if ($maxBackdateDays > 0) {
            $earliest = $today->modify('-' . $maxBackdateDays . ' days');
            if ($businessDate < $earliest) {
                throw new Exception("Payment date is older than the allowed {$maxBackdateDays}-day backdating window.");
            }
        }
        return $businessDate->format('Y-m-d');
    }
}


if (!function_exists('ps_validate_org_unit')) {
    function ps_validate_org_unit(PDO $pdo, string $table, string $pk, int $id, string $activeClause, string $label): int
    {
        if ($id <= 0) return 0;
        $allowed = [
            'branches' => ['pkey', 'Deleted = 0'],
            'ic_segments' => ['pkey', "status = 'A'"],
            'ic_divisions' => ['pkey', "status = 'A'"],
        ];
        if (!isset($allowed[$table]) || $allowed[$table][0] !== $pk || $allowed[$table][1] !== $activeClause) {
            throw new Exception('Invalid organization-unit validation request.');
        }
        $stmt = $pdo->prepare("SELECT {$pk} FROM {$table} WHERE {$pk} = ? AND {$activeClause} LIMIT 1");
        $stmt->execute([$id]);
        if (!(int)$stmt->fetchColumn()) throw new Exception($label . ' is missing or inactive.');
        return $id;
    }
}

if (!function_exists('ps_assert_required_schema')) {
    function ps_assert_required_schema(PDO $pdo): void
    {
        $required = [
            ['payment_transactions', 'business_date'],
            ['payment_transactions', 'transaction_status'],
            ['payment_transactions', 'idempotency_key'],
            ['payment_transactions', 'compromise_amount'],
            ['payment_transaction_items', 'principal_amount'],
            ['payment_transaction_items', 'penalty_amount'],
            ['payment_transaction_items', 'balance_before'],
            ['payment_transaction_items', 'balance_after'],
            ['bill_readings', 'amount_paid'],
            ['installment_schedules', 'amount_paid'],
        ];
        foreach ($required as [$table, $column]) {
            if (!cr_column_exists($pdo, $table, $column)) {
                throw new Exception("Payment database migration is incomplete: {$table}.{$column} is missing.");
            }
        }
        if (!cr_table_exists($pdo, 'payment_transaction_items')) {
            throw new Exception('Payment allocation table is missing. Run the supplied payment migration first.');
        }
    }
}

if (!function_exists('ps_assert_member')) {
    function ps_assert_member(PDO $pdo, int $memberId, bool $forUpdate = false): array
    {
        if ($memberId <= 0) {
            throw new Exception('Invalid member selected.');
        }
        $sql = "SELECT pkey, account_number, firstname, lastname, status FROM water_accounts WHERE pkey = ? LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtoupper((string)($row['status'] ?? '')) !== 'A') {
            throw new Exception('The selected member is missing or inactive.');
        }
        return $row;
    }
}

if (!function_exists('ps_fetch_receivable')) {
    function ps_fetch_receivable(PDO $pdo, string $type, int $id, int $memberId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        if ($id <= 0 || !in_array($type, ['water', 'installment', 'onetime', 'recurring', 'sharecap'], true)) {
            throw new Exception('Invalid receivable selected.');
        }

        if ($type === 'sharecap') {
            // a Fixed Share Capital Billing bill of the member of this water-service account (no journal entry until
            // collected: the collection is a deposit to the share capital account by the code of the billing page)
            $b = ShareCapitalBilling::billForWaterAccount($id, $memberId, $forUpdate);
            if (!$b || !in_array($b['status'], ['unpaid', 'partial'], true)) {
                throw new Exception('Share capital bill was not found or is not collectible.');
            }
            return [
                'type' => $type,
                'source_id' => $id,
                'member_id' => $memberId,
                'total_cents' => ps_money_to_cents($b['amount'], 'Share capital bill amount'),
                'paid_cents' => ps_money_to_cents($b['amount_paid'], 'Share capital amount paid'),
                'issue_date' => $b['bill_date'],
                // the due date of its term (Fixed Share Capital Billing, Term (days)); none when the term is blank (open).
                // A contribution: no penalty or compromise is suggested on it (ps_build_quote leaves it out)
                'due_date' => $b['due_date'] ?? null,
                'disconnection_date' => null,
                'disconnection_days' => 0,
                'description' => ShareCapitalBilling::billDescription($b),
                // no receivable on the books: the account the deposit is credited to stands in (never used as A/R)
                'ar_account_id' => -1,
                'parent_id' => (int)$b['plan_id'],
                'branch_id' => 0,
                'segment_id' => 0,
                'division_id' => 0,
            ];
        }

        if ($type === 'water') {
            $stmt = $pdo->prepare("
                SELECT br.reading_id AS source_id, br.member_id, br.amount_due AS total_amount,
                       COALESCE(br.amount_paid, 0) AS amount_paid, br.billing_date AS issue_date, br.due_date, br.disconnection_date, br.disconnection_days, br.consumption,
                       bp.bp_code, br.is_billed, br.is_paid,
                       br.branch_id, br.segment_id, br.division_id
                FROM bill_readings br
                LEFT JOIN bill_periods bp ON bp.period_id = br.period_id
                WHERE br.reading_id = ? AND br.member_id = ?
                  AND br.is_billed = 1
                  AND COALESCE(br.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1{$lock}
            ");
            $stmt->execute([$id, $memberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Water bill was not found or is not collectible.');

            // the A/R account of the bill's live invoice in the ledger (the posting bridge)
            $arAccount = (int)(WaterPosting::billArAccount('water', $id) ?: 0);
            if ($arAccount <= 0) {
                throw new Exception('Water bill has no posted accounting document (invoice). Post the billing period first.');
            }
            return [
                'type' => $type,
                'source_id' => $id,
                'member_id' => $memberId,
                'total_cents' => ps_money_to_cents($row['total_amount'], 'Water bill total'),
                'paid_cents' => ps_money_to_cents($row['amount_paid'], 'Water amount paid'),
                'issue_date' => $row['issue_date'] ?: null,
                'due_date' => $row['due_date'] ?: null,
                'disconnection_date' => $row['disconnection_date'] ?: null,
                'disconnection_days' => (int)($row['disconnection_days'] ?? 0),
                'description' => 'Water Bill (' . ($row['bp_code'] ?: 'Period') . ') - ' . (int)$row['consumption'] . 'm³',
                'ar_account_id' => $arAccount,
                'parent_id' => null,
                'branch_id' => (int)($row['branch_id'] ?? 0),
                'segment_id' => (int)($row['segment_id'] ?? 0),
                'division_id' => (int)($row['division_id'] ?? 0),
            ];
        }

        if ($type === 'installment') {
            $stmt = $pdo->prepare("
                SELECT s.schedule_id AS source_id, s.installment_id, b.member_id,
                       s.amount AS total_amount, COALESCE(s.amount_paid, 0) AS amount_paid,
                       b.start_date AS issue_date, s.due_date, s.disconnection_date, s.disconnection_days, s.status, c.code, c.ar_account,
                       b.branch_id, b.segment_id, b.division_id,
                       b.status AS parent_status, b.bill_lifecycle_status
                FROM installment_schedules s
                JOIN installment_bills b ON b.installment_id = s.installment_id
                JOIN bill_codes c ON c.code_id = b.bill_code_id
                WHERE s.schedule_id = ? AND b.member_id = ?
                  AND b.status NOT IN ('cancelled','deleted')
                  AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1{$lock}
            ");
            $stmt->execute([$id, $memberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Installment schedule was not found or is not collectible.');
            return [
                'type' => $type,
                'source_id' => $id,
                'member_id' => $memberId,
                'total_cents' => ps_money_to_cents($row['total_amount'], 'Installment amount'),
                'paid_cents' => ps_money_to_cents($row['amount_paid'], 'Installment amount paid'),
                'issue_date' => $row['issue_date'] ?: null,
                'due_date' => $row['due_date'] ?: null,
                'disconnection_date' => $row['disconnection_date'] ?: null,
                'disconnection_days' => (int)($row['disconnection_days'] ?? 0),
                'description' => ($row['code'] ?: 'Bill') . ' (Installment)',
                'ar_account_id' => (int)($row['ar_account'] ?? 0),
                'parent_id' => (int)$row['installment_id'],
                'branch_id' => (int)($row['branch_id'] ?? 0),
                'segment_id' => (int)($row['segment_id'] ?? 0),
                'division_id' => (int)($row['division_id'] ?? 0),
            ];
        }

        if ($type === 'onetime') {
            $stmt = $pdo->prepare("
                SELECT b.bill_id AS source_id, b.member_id, b.total_amount,
                       COALESCE(b.amount_paid, 0) AS amount_paid, b.bill_date AS issue_date, b.due_date, b.disconnection_date, b.disconnection_days, b.status,
                       c.code, c.ar_account, b.branch_id, b.segment_id, b.division_id
                FROM one_time_bills b
                JOIN bill_codes c ON c.code_id = b.bill_code_id
                WHERE b.bill_id = ? AND b.member_id = ?
                  AND b.status NOT IN ('cancelled','deleted')
                  AND COALESCE(b.bill_lifecycle_status, 'active') <> 'deleted'
                LIMIT 1{$lock}
            ");
            $stmt->execute([$id, $memberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('One-time bill was not found or is not collectible.');
            return [
                'type' => $type,
                'source_id' => $id,
                'member_id' => $memberId,
                'total_cents' => ps_money_to_cents($row['total_amount'], 'One-time bill total'),
                'paid_cents' => ps_money_to_cents($row['amount_paid'], 'One-time amount paid'),
                'issue_date' => $row['issue_date'] ?: null,
                'due_date' => $row['due_date'] ?: null,
                'disconnection_date' => $row['disconnection_date'] ?: null,
                'disconnection_days' => (int)($row['disconnection_days'] ?? 0),
                'description' => ($row['code'] ?: 'Bill') . ' (One-Time)',
                'ar_account_id' => (int)($row['ar_account'] ?? 0),
                'parent_id' => null,
                'branch_id' => (int)($row['branch_id'] ?? 0),
                'segment_id' => (int)($row['segment_id'] ?? 0),
                'division_id' => (int)($row['division_id'] ?? 0),
            ];
        }

        $stmt = $pdo->prepare("
            SELECT rb.bill_id AS source_id, rb.member_id, rb.total_amount,
                   COALESCE(rb.amount_paid, 0) AS amount_paid, rb.bill_date AS issue_date, rb.due_date, rb.disconnection_date, rb.disconnection_days, rb.status,
                   c.code, c.ar_account, rb.branch_id, rb.segment_id, rb.division_id
            FROM recurring_bills rb
            JOIN bill_codes c ON c.code_id = rb.bill_code_id
            WHERE rb.bill_id = ? AND rb.member_id = ?
              AND rb.status NOT IN ('cancelled','deleted')
              AND COALESCE(rb.bill_lifecycle_status, 'active') <> 'deleted'
            LIMIT 1{$lock}
        ");
        $stmt->execute([$id, $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Recurring bill was not found or is not collectible.');
        return [
            'type' => $type,
            'source_id' => $id,
            'member_id' => $memberId,
            'total_cents' => ps_money_to_cents($row['total_amount'], 'Recurring bill total'),
            'paid_cents' => ps_money_to_cents($row['amount_paid'], 'Recurring amount paid'),
            'issue_date' => $row['issue_date'] ?: null,
            'due_date' => $row['due_date'] ?: null,
            'disconnection_date' => $row['disconnection_date'] ?: null,
            'disconnection_days' => (int)($row['disconnection_days'] ?? 0),
            'description' => ($row['code'] ?: 'Bill') . ' (Recurring)',
            'ar_account_id' => (int)($row['ar_account'] ?? 0),
            'parent_id' => null,
            'branch_id' => (int)($row['branch_id'] ?? 0),
            'segment_id' => (int)($row['segment_id'] ?? 0),
            'division_id' => (int)($row['division_id'] ?? 0),
        ];
    }
}

if (!function_exists('ps_receivable_manual_charge_history')) {
    /**
     * Return active manual-charge payments already attached to one receivable.
     * payment_transaction_items.penalty_amount stores the item-level manual
     * charge amount for backward schema compatibility. The parent transaction
     * identifies whether that amount was a Penalty or a Compromise.
     */
    function ps_receivable_manual_charge_history(PDO $pdo, string $type, int $sourceId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE
                    WHEN pt.transaction_status = 'active'
                     AND pti.allocation_status = 'active'
                     AND pt.penalty_amount > 0
                    THEN pti.penalty_amount ELSE 0 END), 0) AS penalty_paid,
                COALESCE(SUM(CASE
                    WHEN pt.transaction_status = 'active'
                     AND pti.allocation_status = 'active'
                     AND pt.compromise_amount > 0
                    THEN pti.penalty_amount ELSE 0 END), 0) AS compromise_paid
            FROM payment_transaction_items pti
            INNER JOIN payment_transactions pt ON pt.transaction_id = pti.transaction_id
            WHERE pti.source_type = ? AND pti.source_id = ?
        ");
        $stmt->execute([$type, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'penalty_paid_cents' => ps_money_to_cents($row['penalty_paid'] ?? 0, 'Penalty history'),
            'compromise_paid_cents' => ps_money_to_cents($row['compromise_paid'] ?? 0, 'Compromise history'),
        ];
    }
}

if (!function_exists('ps_receivable_manual_charge_stage')) {
    /**
     * Determine which optional manual charge is applicable to a receivable.
     *
     * The cashier still enters the amount manually. Dates determine when a
     * stage may open, while active payment history determines whether that
     * stage was already settled. Compromise is allowed only after the
     * disconnection/compromise date when no active Penalty payment exists.
     */
    function ps_receivable_manual_charge_stage(array $receivable, string $businessDate): string
    {
        $dueDate = trim((string)($receivable['due_date'] ?? ''));
        if ($dueDate === '') {
            return 'none';
        }

        $dueDate = substr($dueDate, 0, 10);
        if ($businessDate <= $dueDate) {
            return 'none';
        }

        $penaltyPaidCents = max(0, (int)($receivable['penalty_paid_cents'] ?? 0));
        $compromisePaidCents = max(0, (int)($receivable['compromise_paid_cents'] ?? 0));
        if ($compromisePaidCents > 0) {
            return 'none';
        }

        $disconnectionDate = trim((string)($receivable['disconnection_date'] ?? ''));
        if ($disconnectionDate !== '') {
            $disconnectionDate = substr($disconnectionDate, 0, 10);
        } else {
            $disconnectionDays = max(0, (int)($receivable['disconnection_days'] ?? 0));
            if ($disconnectionDays > 0) {
                $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
                if ($due instanceof DateTimeImmutable) {
                    $disconnectionDate = $due->modify('+' . $disconnectionDays . ' days')->format('Y-m-d');
                }
            }
        }

        if ($disconnectionDate !== '' && $businessDate >= $disconnectionDate) {
            return $penaltyPaidCents > 0 ? 'none' : 'compromise';
        }

        return $penaltyPaidCents > 0 ? 'none' : 'penalty';
    }
}

if (!function_exists('ps_receivable_is_overdue')) {
    function ps_receivable_is_overdue(array $receivable, string $businessDate): bool
    {
        return ps_receivable_manual_charge_stage($receivable, $businessDate) !== 'none';
    }
}

if (!function_exists('ps_manual_charge_account_id')) {
    /** Income account a manual charge is credited to, or 0 when unmapped. */
    function ps_manual_charge_account_id(PDO $pdo, string $name): int
    {
        return (int)(ps_setting($pdo, 'collection_' . $name . '_account_id', '0') ?? 0);
    }
}

if (!function_exists('ps_manual_charge_configuration')) {
    /**
     * Penalty and Compromise are ALWAYS available to the cashier. The only real
     * prerequisite is an active income account to credit, so that — not a
     * feature toggle — is what "ready" means here.
     */
    function ps_manual_charge_configuration(PDO $pdo): array
    {
        $ready = [];
        foreach (['penalty', 'compromise'] as $name) {
            $accountId = ps_manual_charge_account_id($pdo, $name);
            $active = false;
            if ($accountId > 0) {
                $stmt = $pdo->prepare("SELECT AccntID FROM chartofaccntstbl WHERE AccntID = ? AND Status = 'A' LIMIT 1");
                $stmt->execute([$accountId]);
                $active = (bool)$stmt->fetchColumn();
            }
            $ready[$name] = $active;
        }

        return [
            // Kept for backward compatibility with older callers/templates.
            'penalty_enabled' => true,
            'compromise_enabled' => true,
            'penalty_account_ready' => $ready['penalty'],
            'compromise_account_ready' => $ready['compromise'],
        ];
    }
}

if (!function_exists('ps_assert_manual_charge_account')) {
    /**
     * Checked only when the cashier actually types a non-zero amount, so an
     * unmapped account never blocks an ordinary zero-charge collection.
     */
    function ps_assert_manual_charge_account(PDO $pdo, string $name): void
    {
        $accountId = ps_manual_charge_account_id($pdo, $name);
        if ($accountId <= 0) {
            throw new Exception(
                ucfirst($name) . ' cannot be charged yet because no ' . ucfirst($name)
                . ' income account is mapped. Set it in Settings -> Collection Policy.'
            );
        }

        $stmt = $pdo->prepare("SELECT AccntID FROM chartofaccntstbl WHERE AccntID = ? AND Status = 'A' LIMIT 1");
        $stmt->execute([$accountId]);
        if (!$stmt->fetchColumn()) {
            throw new Exception(
                'The ' . ucfirst($name) . ' income account is missing or inactive in the Chart of Accounts.'
            );
        }
    }
}

if (!function_exists('ps_resolve_manual_charge_cents')) {
    /**
     * Penalty and Compromise are cashier-entered on every collection and always
     * START AT ZERO. Zero simply means the charge was not applied, so a blank
     * or 0.00 box is a valid, silent "no charge" and never an error.
     *
     * The legacy apply_<name> checkbox is still honoured when it is explicitly
     * false, so older callers keep working, but it is no longer required.
     *
     * There is no on/off setting in front of this. A cashier can always type an
     * amount; whether the income account exists is checked separately by
     * ps_assert_manual_charge_account() and only when the amount is non-zero.
     */
    function ps_resolve_manual_charge_cents(array $input, string $name, bool $enabled = true): int
    {
        if (array_key_exists('apply_' . $name, $input)
            && $input['apply_' . $name] !== null
            && $input['apply_' . $name] !== ''
            && !filter_var($input['apply_' . $name], FILTER_VALIDATE_BOOLEAN)
        ) {
            return 0;
        }

        $raw = $input[$name . '_amount'] ?? null;
        if ($raw === null || trim((string)$raw) === '') {
            return 0;
        }

        return ps_money_to_cents($raw, ucfirst($name) . ' amount');
    }
}

if (!function_exists('ps_build_quote')) {
    function ps_build_quote(PDO $pdo, int $memberId, array $items, string $businessDate, bool $forUpdate = false, array $manualCharges = []): array
    {
        ps_assert_required_schema($pdo);
        ps_assert_member($pdo, $memberId, $forUpdate);
        $businessDate = ps_validate_business_date($pdo, $businessDate);
        if (empty($items)) throw new Exception('Select at least one receivable.');
        if (count($items) > 100) throw new Exception('A payment can contain at most 100 receivable allocations.');

        usort($items, static function ($left, $right): int {
            $leftType = strtolower(trim((string)($left['db_type'] ?? $left['type'] ?? '')));
            $rightType = strtolower(trim((string)($right['db_type'] ?? $right['type'] ?? '')));
            $typeCompare = strcmp($leftType, $rightType);
            if ($typeCompare !== 0) return $typeCompare;
            return ((int)($left['db_id'] ?? $left['id'] ?? 0)) <=> ((int)($right['db_id'] ?? $right['id'] ?? 0));
        });

        $seen = [];
        $allocations = [];
        $principalTotal = 0;
        $shareCapitalTotal = 0;
        foreach ($items as $input) {
            if (!is_array($input)) throw new Exception('Invalid payment allocation.');
            $type = strtolower(trim((string)($input['db_type'] ?? $input['type'] ?? '')));
            $id = (int)($input['db_id'] ?? $input['id'] ?? 0);
            $key = $type . ':' . $id;
            if (isset($seen[$key])) throw new Exception('The same receivable was selected more than once.');
            $seen[$key] = true;

            $r = ps_fetch_receivable($pdo, $type, $id, $memberId, $forUpdate);
            $issueDate = trim((string)($r['issue_date'] ?? ''));
            if ($issueDate === '') {
                throw new Exception($r['description'] . ' has no valid billing/issue date and cannot be collected.');
            }
            $issueDate = substr($issueDate, 0, 10);
            if ($businessDate < $issueDate) {
                throw new Exception($r['description'] . ' cannot be paid before its billing/issue date of ' . $issueDate . '.');
            }
            $r['issue_date'] = $issueDate;
            $r = array_merge($r, ps_receivable_manual_charge_history($pdo, $type, $id));
            $balanceCents = max(0, $r['total_cents'] - $r['paid_cents']);
            if ($balanceCents <= 0) throw new Exception($r['description'] . ' has no remaining balance.');
            $requestedRaw = $input['payment_amount'] ?? $input['allocation_amount'] ?? null;
            $principalCents = ($requestedRaw === null || $requestedRaw === '')
                ? $balanceCents
                : ps_money_to_cents($requestedRaw, 'Payment allocation');
            if ($principalCents <= 0) throw new Exception('Each payment allocation must be at least 0.01.');
            if ($principalCents > $balanceCents) {
                throw new Exception($r['description'] . ' allocation exceeds its current balance of ' . number_format(ps_cents_to_money($balanceCents), 2) . '.');
            }
            if ($type !== 'sharecap' && $r['ar_account_id'] <= 0) throw new Exception($r['description'] . ' has no AR account mapping.');

            $balanceAfter = $balanceCents - $principalCents;
            $allocations[] = array_merge($r, [
                'balance_before_cents' => $balanceCents,
                'principal_cents' => $principalCents,
                'penalty_cents' => 0,
                'manual_charge_type' => 'none',
                'balance_after_cents' => $balanceAfter,
                'allocation_status' => $balanceAfter === 0 ? 'settled' : 'partial',
            ]);
            $principalTotal += $principalCents;
            if ($type === 'sharecap') {
                $shareCapitalTotal += $principalCents;
            }
        }

        $chargeConfig = ps_manual_charge_configuration($pdo);
        $penaltyCents = ps_resolve_manual_charge_cents($manualCharges, 'penalty');
        $compromiseCents = ps_resolve_manual_charge_cents($manualCharges, 'compromise');

        // Only a charge that is actually being made needs an income account.
        if ($penaltyCents > 0) ps_assert_manual_charge_account($pdo, 'penalty');
        if ($compromiseCents > 0) ps_assert_manual_charge_account($pdo, 'compromise');

        /*
         * Penalty and Compromise are open cashier fields that default to zero.
         * They are no longer gated on a due-date stage or on a single eligible
         * bill: the cashier decides, and a zero stays a no-op. Whatever is
         * entered is attached to the OLDEST selected receivable so the charge
         * lands on the bill it belongs to.
         */
        // a share capital contribution is never a reason for a penalty or compromise (it is not a receivable)
        $billAllocations = array_values(array_filter($allocations, static fn(array $a): bool => ($a['type'] ?? '') !== 'sharecap'));
        $chargeStages = array_map(
            static fn(array $allocation): string => ps_receivable_manual_charge_stage($allocation, $businessDate),
            $billAllocations
        );
        $penaltyEligibleCount = count(array_filter($chargeStages, static fn(string $stage): bool => $stage === 'penalty'));
        $compromiseEligibleCount = count(array_filter($chargeStages, static fn(string $stage): bool => $stage === 'compromise'));
        $pastDueCount = count(array_filter($billAllocations, static function (array $allocation) use ($businessDate): bool {
            $dueDate = trim((string)($allocation['due_date'] ?? ''));
            return $dueDate !== '' && $businessDate > substr($dueDate, 0, 10);
        }));

        $manualChargeCents = $penaltyCents + $compromiseCents;
        if ($manualChargeCents > 0) {
            // a penalty or compromise is charged on a bill, never on a share capital contribution
            $billIndexes = array_keys(array_filter($allocations, static fn(array $a): bool => $a['type'] !== 'sharecap'));
            if (!$billIndexes) {
                throw new Exception('Penalty and compromise are charged with a bill: select a bill, or leave them at zero for a share capital collection.');
            }
            $targetIndex = $billIndexes[0];
            $oldestDue = null;
            foreach ($allocations as $index => $allocation) {
                if ($allocation['type'] === 'sharecap') continue;
                $dueDate = substr(trim((string)($allocation['due_date'] ?? '')), 0, 10);
                if ($dueDate === '') continue;
                if ($oldestDue === null || $dueDate < $oldestDue) {
                    $oldestDue = $dueDate;
                    $targetIndex = $index;
                }
            }

            if ($penaltyCents > 0 && $compromiseCents > 0) {
                $chargeType = 'both';
            } elseif ($compromiseCents > 0) {
                $chargeType = 'compromise';
            } else {
                $chargeType = 'penalty';
            }

            $allocations[$targetIndex]['penalty_cents'] = $manualChargeCents;
            $allocations[$targetIndex]['manual_charge_type'] = $chargeType;
        }

        $manualChargeStage = $penaltyCents > 0 && $compromiseCents > 0
            ? 'both'
            : ($compromiseCents > 0 ? 'compromise' : ($penaltyCents > 0 ? 'penalty' : 'none'));
        $eligibleManualChargeStage = $compromiseEligibleCount > 0
            ? 'compromise'
            : ($penaltyEligibleCount > 0 ? 'penalty' : 'none');

        return [
            'member_id' => $memberId,
            'business_date' => $businessDate,
            'allocations' => $allocations,
            'principal_cents' => $principalTotal,
            // the share capital part of the principal (deposits to the members' share capital, not bills paid)
            'share_capital_cents' => $shareCapitalTotal,
            'bills_cents' => $principalTotal - $shareCapitalTotal,
            'penalty_cents' => $penaltyCents,
            'compromise_cents' => $compromiseCents,
            'total_cents' => $principalTotal + $penaltyCents + $compromiseCents,
            'charge_configuration' => $chargeConfig,
            'manual_charge_stage' => $manualChargeStage,
            'eligible_manual_charge_stage' => $eligibleManualChargeStage,
            'has_overdue_receivable' => $pastDueCount > 0,
            'overdue_receivable_count' => $pastDueCount,
            'penalty_eligible_count' => $penaltyEligibleCount,
            'compromise_eligible_count' => $compromiseEligibleCount,
        ];
    }
}

if (!function_exists('ps_generate_receipt_no')) {
    function ps_generate_receipt_no(PDO $pdo): string
    {
        for ($i = 0; $i < 10; $i++) {
            $receipt = 'COL-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE receipt_no = ?');
            $stmt->execute([$receipt]);
            if ((int)$stmt->fetchColumn() === 0) return $receipt;
        }
        throw new Exception('Unable to generate a unique receipt number.');
    }
}

if (!function_exists('ps_refresh_installment_parent')) {
    function ps_refresh_installment_parent(PDO $pdo, int $installmentId): void
    {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN COALESCE(amount_paid,0) >= amount THEN 1 ELSE 0 END) AS paid_count,
                SUM(CASE WHEN COALESCE(amount_paid,0) > 0 AND COALESCE(amount_paid,0) < amount THEN 1 ELSE 0 END) AS partial_count
            FROM installment_schedules
            WHERE installment_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$installmentId]);
        $state = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($state['total_count'] ?? 0);
        $paid = (int)($state['paid_count'] ?? 0);
        $partial = (int)($state['partial_count'] ?? 0);
        $status = ($total > 0 && $paid === $total) ? 'completed' : 'active';
        $pdo->prepare('UPDATE installment_bills SET status = ?, updated_at = NOW() WHERE installment_id = ?')
            ->execute([$status, $installmentId]);
        if ($partial > 0 && $status === 'completed') {
            throw new Exception('Installment status derivation failed.');
        }
    }
}

if (!function_exists('ps_apply_allocation')) {
    function ps_apply_allocation(PDO $pdo, array $a, string $receiptNo, int $userId, string $businessDate): void
    {
        $principal = ps_cents_to_money($a['principal_cents']);
        $manualChargeType = (string)($a['manual_charge_type'] ?? 'none');
        // Penalty, Compromise, or both are recorded the same way on the bill's
        // penalty_amount column. The split between the two lives on the
        // transaction header and on the journal, where accounting reads it.
        $penalty = $manualChargeType !== 'none' ? ps_cents_to_money($a['penalty_cents']) : 0.0;
        $newPaid = ps_cents_to_money($a['paid_cents'] + $a['principal_cents']);
        $settled = $a['balance_after_cents'] === 0;

        if ($a['type'] === 'sharecap') {
            // settled with its deposit to the share capital account (ShareCapitalBilling::collect, in ps_process_payment)
            return;
        }

        if ($a['type'] === 'water') {
            $stmt = $pdo->prepare("
                UPDATE bill_readings
                SET amount_paid = ?, penalty_amount = COALESCE(penalty_amount,0) + ?,
                    is_paid = ?, updated_at = NOW()
                WHERE reading_id = ? AND member_id = ?
            ");
            $stmt->execute([$newPaid, $penalty, $settled ? 1 : 0, $a['source_id'], $a['member_id']]);
            return;
        }

        if ($a['type'] === 'installment') {
            $status = $settled ? 'paid' : 'partial';
            $stmt = $pdo->prepare("
                UPDATE installment_schedules
                SET amount_paid = ?, penalty_amount = COALESCE(penalty_amount,0) + ?,
                    status = ?, paid_at = CASE WHEN ? = 'paid' THEN CONCAT(?, ' 00:00:00') ELSE NULL END,
                    payment_reference = ?, marked_by = NULL
                WHERE schedule_id = ?
            ");
            // The legacy marked_by column references the obsolete users table,
            // while authenticated cashiers are stored in operator. The canonical
            // payment header is the authoritative actor record, so do not write an
            // incompatible operator ID into that legacy foreign key.
            $stmt->execute([$newPaid, $penalty, $status, $status, $businessDate, $receiptNo, $a['source_id']]);
            ps_refresh_installment_parent($pdo, (int)$a['parent_id']);
            return;
        }

        if ($a['type'] === 'onetime') {
            $stmt = $pdo->prepare("
                UPDATE one_time_bills
                SET amount_paid = ?, penalty_amount = COALESCE(penalty_amount,0) + ?,
                    status = ?, updated_at = NOW()
                WHERE bill_id = ? AND member_id = ?
            ");
            $stmt->execute([$newPaid, $penalty, $settled ? 'paid' : 'unpaid', $a['source_id'], $a['member_id']]);
            return;
        }

        $status = $settled ? 'paid' : (($a['due_date'] && $businessDate > $a['due_date']) ? 'overdue' : 'unpaid');
        $stmt = $pdo->prepare("
            UPDATE recurring_bills
            SET amount_paid = ?, penalty_amount = COALESCE(penalty_amount,0) + ?, balance = ?, status = ?, updated_at = NOW()
            WHERE bill_id = ? AND member_id = ?
        ");
        $stmt->execute([$newPaid, $penalty, ps_cents_to_money($a['balance_after_cents']), $status, $a['source_id'], $a['member_id']]);
        // payment_transactions + payment_transaction_items are the authoritative
        // recurring payment history. The legacy recurring_bill_payments table is
        // intentionally not written to by the canonical service.
    }
}

if (!function_exists('ps_insert_transaction')) {
    function ps_insert_transaction(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO payment_transactions
                (receipt_no, member_id, payment_type, base_amount, penalty_amount, compromise_amount, total_paid,
                 tendered_amount, change_amount, cash_tendered, cashier_id, payment_date,
                 business_date, created_at, transaction_status, idempotency_key,
                 journal_voucher_id, journal_head_id, journal_entry_id,
                 journal_status, branch_id, segment_id, division_id)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), 'active', ?, ?, ?, ?, 'posted', ?, ?, ?)
        ");
        $stmt->execute([
            $data['receipt_no'], $data['member_id'], $data['payment_type'],
            $data['base_amount'], $data['penalty_amount'], $data['compromise_amount'], $data['total_paid'],
            $data['tendered_amount'], $data['change_amount'], $data['tendered_amount'],
            $data['cashier_id'], $data['business_date'],
            $data['idempotency_key'], $data['journal_head_id'], $data['journal_head_id'],
            $data['journal_entry_id'], $data['branch_id'], $data['segment_id'], $data['division_id'],
        ]);
        $id = (int)$pdo->lastInsertId();
        if (!empty($data['share_capital_amount']) && ShareCapitalBilling::ready()) {
            $pdo->prepare('UPDATE payment_transactions SET share_capital_amount = ? WHERE transaction_id = ?')->execute([$data['share_capital_amount'], $id]);
        }
        if (!empty($data['class_id']) && water_class_columns()) {
            $pdo->prepare('UPDATE payment_transactions SET class_id = ? WHERE transaction_id = ?')->execute([(int)$data['class_id'], $id]);
        }
        return $id;
    }
}

if (!function_exists('ps_insert_allocation')) {
    function ps_insert_allocation(PDO $pdo, int $transactionId, array $a): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO payment_transaction_items
                (transaction_id, source_type, source_id, description, due_date,
                 amount, principal_amount, penalty_amount, balance_before, balance_after,
                 allocation_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $principal = ps_cents_to_money($a['principal_cents']);
        $penalty = ps_cents_to_money($a['penalty_cents']);
        $stmt->execute([
            $transactionId, $a['type'], $a['source_id'], $a['description'], $a['due_date'],
            ps_cents_to_money($a['principal_cents'] + $a['penalty_cents']),
            $principal, $penalty,
            ps_cents_to_money($a['balance_before_cents']),
            ps_cents_to_money($a['balance_after_cents']),
        ]);
    }
}

if (!function_exists('ps_process_payment')) {
    function ps_process_payment(PDO $pdo, array $input, int $userId): array
    {
        $memberId = (int)($input['member_id'] ?? 0);
        $items = $input['items'] ?? [];
        $businessDate = (string)($input['transaction_date'] ?? date('Y-m-d'));
        $tenderedCents = ps_money_to_cents($input['tendered'] ?? 0, 'Tendered amount');
        // the class of the collection (Chart of Accounts > Class List) replaces the former branch / segment / division:
        // the receipt, its finance charges and its payment(s) post under it
        $classId = water_required_class($input['class_id'] ?? null, 'the collection');
        $branchId = 0;
        $segmentId = 0;
        $divisionId = 0;
        $idempotencyKey = trim((string)($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $idempotencyKey)) {
            throw new Exception('Invalid or missing payment request key. Refresh the collection page and try again.');
        }

        ps_assert_required_schema($pdo);
        $existing = $pdo->prepare('SELECT receipt_no FROM payment_transactions WHERE idempotency_key = ? LIMIT 1');
        $existing->execute([$idempotencyKey]);
        $existingReceipt = $existing->fetchColumn();
        if ($existingReceipt) {
            $receipt = cr_fetch_receipt($pdo, (string)$existingReceipt);
            return ['duplicate' => true, 'receipt' => $receipt, 'receipt_no' => (string)$existingReceipt];
        }

        $pdo->beginTransaction();
        try {
            $quote = ps_build_quote($pdo, $memberId, is_array($items) ? $items : [], $businessDate, true, $input);
            if ($tenderedCents < $quote['total_cents']) {
                throw new Exception('Tendered amount is less than the selected payment total.');
            }
            $changeCents = $tenderedCents - $quote['total_cents'];
            $receiptNo = ps_generate_receipt_no($pdo);

            $types = array_values(array_unique(array_map(fn($a) => $a['type'], $quote['allocations'])));
            $paymentType = count($types) === 1 ? $types[0] : 'mixed';
            $creditLines = [];
            foreach ($quote['allocations'] as $a) {
                if ($a['type'] === 'sharecap') {
                    continue;   // a deposit to the share capital account, posted below by its savings transaction code
                }
                $creditLines[] = [
                    'account_id' => $a['ar_account_id'],
                    'amount' => ps_cents_to_money($a['principal_cents']),
                    'description' => 'Collection of ' . $a['description'] . ' #' . $a['source_id'],
                    // the bill the ledger applies this part of the payment to
                    'source' => $a['type'] === 'installment' ? 'installment' : $a['type'],
                    'source_id' => $a['type'] === 'installment' ? (int)$a['parent_id'] : (int)$a['source_id'],
                ];
            }

            // the bills and the penalty / compromise: the water payment (Dr cash, Cr A/R and the charges' income)
            $journal = ['journal_head_id' => null, 'journal_entry_id' => null];
            if ($quote['bills_cents'] + $quote['penalty_cents'] + $quote['compromise_cents'] > 0) {
                $journal = WaterPosting::withClass($classId, fn() => wc_create_collection_journal(
                    $pdo, $receiptNo, $memberId,
                    ps_cents_to_money($quote['bills_cents']),
                    ps_cents_to_money($quote['penalty_cents']),
                    ps_cents_to_money($quote['compromise_cents']),
                    $quote['business_date'], $branchId, $segmentId, $divisionId, $creditLines
                ));
                if (!$journal) throw new Exception('Collection journal was not created.');
            }

            foreach ($quote['allocations'] as $a) {
                ps_apply_allocation($pdo, $a, $receiptNo, $userId, $quote['business_date']);
            }

            // the share capital contributions: each a deposit to the member's share capital account by the savings
            // transaction code of Fixed Share Capital Billing, with its journal entry (Dr cash, Cr share capital)
            if ($quote['share_capital_cents'] > 0) {
                $cashAccountId = (int)(uph_current_cash_account_id($pdo, $userId) ?: 0);
                foreach ($quote['allocations'] as $a) {
                    if ($a['type'] === 'sharecap') {
                        ShareCapitalBilling::collect((int)$a['source_id'], (string)ps_cents_to_money($a['principal_cents']), $quote['business_date'], $cashAccountId,
                            ['receipt_no' => $receiptNo, 'class_id' => $classId]);
                    }
                }
            }

            $transactionId = ps_insert_transaction($pdo, [
                'receipt_no' => $receiptNo,
                'member_id' => $memberId,
                'payment_type' => $paymentType,
                'base_amount' => ps_cents_to_money($quote['bills_cents']),
                'penalty_amount' => ps_cents_to_money($quote['penalty_cents']),
                'compromise_amount' => ps_cents_to_money($quote['compromise_cents']),
                'share_capital_amount' => ps_cents_to_money($quote['share_capital_cents']),
                'total_paid' => ps_cents_to_money($quote['total_cents']),
                'tendered_amount' => ps_cents_to_money($tenderedCents),
                'change_amount' => ps_cents_to_money($changeCents),
                'cashier_id' => $userId,
                'business_date' => $quote['business_date'],
                'idempotency_key' => $idempotencyKey,
                'journal_head_id' => $journal['journal_head_id'],
                'journal_entry_id' => $journal['journal_entry_id'],
                'branch_id' => $branchId,
                'segment_id' => $segmentId,
                'division_id' => $divisionId,
                'class_id' => $classId,
            ]);
            foreach ($quote['allocations'] as $a) ps_insert_allocation($pdo, $transactionId, $a);
            if ($quote['share_capital_cents'] > 0) {
                ShareCapitalBilling::linkReceipt($receiptNo, $transactionId);
            }

            bl_audit($pdo, $userId, 'CREATE_PAYMENT', 'payment_transactions', (string)$transactionId, [
                'receipt_no' => $receiptNo,
                'business_date' => $quote['business_date'],
                'principal' => ps_cents_to_money($quote['principal_cents']),
                'share_capital' => ps_cents_to_money($quote['share_capital_cents']),
                'penalty' => ps_cents_to_money($quote['penalty_cents']),
                'compromise' => ps_cents_to_money($quote['compromise_cents']),
                'allocations' => array_map(fn($a) => [
                    'type' => $a['type'], 'id' => $a['source_id'],
                    'principal' => ps_cents_to_money($a['principal_cents']),
                    'manual_charge_type' => (string)($a['manual_charge_type'] ?? 'none'),
                    'manual_charge' => ps_cents_to_money($a['penalty_cents']),
                    'balance_after' => ps_cents_to_money($a['balance_after_cents']),
                ], $quote['allocations']),
            ]);

            $pdo->commit();
            return [
                'duplicate' => false,
                'receipt_no' => $receiptNo,
                'transaction_id' => $transactionId,
                'quote' => $quote,
                'change_amount' => ps_cents_to_money($changeCents),
                'receipt' => cr_fetch_receipt($pdo, $receiptNo),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                $retry = $pdo->prepare('SELECT receipt_no FROM payment_transactions WHERE idempotency_key = ? LIMIT 1');
                $retry->execute([$idempotencyKey]);
                $receiptNo = $retry->fetchColumn();
                if ($receiptNo) {
                    return ['duplicate' => true, 'receipt_no' => (string)$receiptNo, 'receipt' => cr_fetch_receipt($pdo, (string)$receiptNo)];
                }
            }
            throw $e;
        }
    }
}

if (!function_exists('ps_restore_allocation')) {
    function ps_restore_allocation(PDO $pdo, array $item, int $userId, string $action, string $manualChargeType = 'none'): void
    {
        $type = (string)$item['source_type'];
        $id = (int)$item['source_id'];
        $principalCents = ps_money_to_cents($item['principal_amount'] ?? $item['amount'] ?? 0);
        // Mirror of ps_apply_allocation(): whatever manual charge was written
        // onto the bill is taken back off it, whether it was a Penalty, a
        // Compromise, or both.
        $penaltyCents = ps_money_to_cents($item['penalty_amount'] ?? 0);
        if ($principalCents <= 0) return;
        if ($type === 'sharecap') return;   // ShareCapitalBilling::reverseReceipt() negates the deposit and reopens the bill

        if ($type === 'water') {
            $r = $pdo->prepare('SELECT amount_due, COALESCE(amount_paid,0) amount_paid, COALESCE(penalty_amount,0) penalty_amount, member_id FROM bill_readings WHERE reading_id = ? FOR UPDATE');
            $r->execute([$id]); $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Cannot restore missing water bill allocation.');
            $newPaid = max(0, ps_money_to_cents($row['amount_paid']) - $principalCents);
            $newPenalty = max(0, ps_money_to_cents($row['penalty_amount']) - $penaltyCents);
            $isPaid = $newPaid >= ps_money_to_cents($row['amount_due']);
            $pdo->prepare('UPDATE bill_readings SET amount_paid=?, penalty_amount=?, is_paid=?, updated_at=NOW() WHERE reading_id=?')
                ->execute([ps_cents_to_money($newPaid), ps_cents_to_money($newPenalty), $isPaid ? 1 : 0, $id]);
        } elseif ($type === 'installment') {
            $r = $pdo->prepare('SELECT amount, COALESCE(amount_paid,0) amount_paid, COALESCE(penalty_amount,0) penalty_amount, installment_id FROM installment_schedules WHERE schedule_id = ? FOR UPDATE');
            $r->execute([$id]); $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Cannot restore missing installment allocation.');
            $newPaid = max(0, ps_money_to_cents($row['amount_paid']) - $principalCents);
            $newPenalty = max(0, ps_money_to_cents($row['penalty_amount']) - $penaltyCents);
            $status = $newPaid === 0 ? 'pending' : ($newPaid >= ps_money_to_cents($row['amount']) ? 'paid' : 'partial');
            $pdo->prepare("UPDATE installment_schedules SET amount_paid=?, penalty_amount=?, status=?, paid_at=NULL, payment_reference=NULL, marked_by=NULL WHERE schedule_id=?")
                ->execute([ps_cents_to_money($newPaid), ps_cents_to_money($newPenalty), $status, $id]);
            ps_refresh_installment_parent($pdo, (int)$row['installment_id']);
        } elseif ($type === 'onetime') {
            $r = $pdo->prepare('SELECT total_amount, COALESCE(amount_paid,0) amount_paid, COALESCE(penalty_amount,0) penalty_amount FROM one_time_bills WHERE bill_id = ? FOR UPDATE');
            $r->execute([$id]); $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Cannot restore missing one-time allocation.');
            $newPaid = max(0, ps_money_to_cents($row['amount_paid']) - $principalCents);
            $newPenalty = max(0, ps_money_to_cents($row['penalty_amount']) - $penaltyCents);
            $status = $newPaid >= ps_money_to_cents($row['total_amount']) ? 'paid' : 'unpaid';
            $pdo->prepare('UPDATE one_time_bills SET amount_paid=?, penalty_amount=?, status=?, updated_at=NOW() WHERE bill_id=?')
                ->execute([ps_cents_to_money($newPaid), ps_cents_to_money($newPenalty), $status, $id]);
        } else {
            $r = $pdo->prepare('SELECT total_amount, COALESCE(amount_paid,0) amount_paid, COALESCE(penalty_amount,0) penalty_amount, due_date FROM recurring_bills WHERE bill_id = ? FOR UPDATE');
            $r->execute([$id]); $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Cannot restore missing recurring allocation.');
            $newPaid = max(0, ps_money_to_cents($row['amount_paid']) - $principalCents);
            $newPenalty = max(0, ps_money_to_cents($row['penalty_amount']) - $penaltyCents);
            $balance = max(0, ps_money_to_cents($row['total_amount']) - $newPaid);
            $status = $balance === 0 ? 'paid' : (($row['due_date'] && date('Y-m-d') > $row['due_date']) ? 'overdue' : 'unpaid');
            $pdo->prepare('UPDATE recurring_bills SET amount_paid=?, penalty_amount=?, balance=?, status=?, updated_at=NOW() WHERE bill_id=?')
                ->execute([ps_cents_to_money($newPaid), ps_cents_to_money($newPenalty), ps_cents_to_money($balance), $status, $id]);
        }
    }
}

if (!function_exists('ps_reverse_or_refund_payment')) {
    function ps_reverse_or_refund_payment(PDO $pdo, string $receiptNo, string $action, string $reason, string $businessDate, int $userId): array
    {
        $action = strtolower($action);
        if (!in_array($action, ['reversal', 'refund'], true)) throw new Exception('Invalid payment adjustment type.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new Exception('A clear adjustment reason is required.');
        $businessDate = ps_validate_business_date($pdo, $businessDate);
        if (!cr_table_exists($pdo, 'payment_adjustments')) {
            throw new Exception('Payment adjustment migration is incomplete: payment_adjustments is missing.');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE receipt_no = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$receiptNo]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tx) throw new Exception('Payment receipt was not found.');
            if (($tx['transaction_status'] ?? 'active') !== 'active') throw new Exception('Only active payments can be reversed or refunded.');

            $itemsStmt = $pdo->prepare("SELECT * FROM payment_transaction_items WHERE transaction_id = ? AND allocation_status = 'active' ORDER BY item_id FOR UPDATE");
            $itemsStmt->execute([(int)$tx['transaction_id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) throw new Exception('Payment has no active allocation details and cannot be adjusted automatically.');

            $hadPenalty = ps_money_to_cents($tx['penalty_amount'] ?? 0) > 0;
            $hadCompromise = ps_money_to_cents($tx['compromise_amount'] ?? 0) > 0;
            $manualChargeType = $hadPenalty && $hadCompromise
                ? 'both'
                : ($hadPenalty ? 'penalty' : ($hadCompromise ? 'compromise' : 'none'));
            foreach ($items as $item) ps_restore_allocation($pdo, $item, $userId, $action, $manualChargeType);
            // the share capital deposits of the receipt are negated (error correction) and their bills reopened
            if (ShareCapitalBilling::ready()) {
                ShareCapitalBilling::reverseReceipt((int)$tx['transaction_id'], $reason, $businessDate);
            }
            $reversal = ['journal_head_id' => null, 'journal_entry_id' => null];
            if (trim((string)($tx['journal_head_id'] ?? '')) !== '') {
                $reversal = wc_create_collection_reversal_journal($pdo, (string)$tx['journal_head_id'], $receiptNo, $businessDate, $reason, $action);
                if (!$reversal) throw new Exception('Payment reversal journal was not created.');
            }

            $newStatus = $action === 'refund' ? 'refunded' : 'reversed';
            $pdo->prepare("
                UPDATE payment_transactions SET transaction_status=?, reversed_at=NOW(), reversed_by=?, reversal_reason=?,
                    reversal_journal_voucher_id=?, journal_status='reversed'
                WHERE transaction_id=?
            ")->execute([$newStatus, $userId, $reason, $reversal['journal_head_id'], $tx['transaction_id']]);
            $pdo->prepare("UPDATE payment_transaction_items SET allocation_status=? WHERE transaction_id=?")
                ->execute([$newStatus, $tx['transaction_id']]);
            $pdo->prepare("
                INSERT INTO payment_adjustments
                    (transaction_id, adjustment_type, amount, business_date, reason, created_by, created_at,
                     journal_voucher_id, journal_entry_id, status)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'completed')
            ")->execute([
                $tx['transaction_id'], $action, $tx['total_paid'], $businessDate, $reason, $userId,
                $reversal['journal_head_id'], $reversal['journal_entry_id'],
            ]);
            bl_audit($pdo, $userId, strtoupper($action) . '_PAYMENT', 'payment_transactions', (string)$tx['transaction_id'], ['receipt_no' => $receiptNo, 'reason' => $reason,
                'class_id' => WaterPosting::currentClass(), 'class' => water_class_name(WaterPosting::currentClass())]);
            $pdo->commit();
            return ['receipt_no' => $receiptNo, 'status' => $newStatus, 'reversal_journal_voucher_id' => $reversal['journal_head_id']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('ps_adjust_bill')) {
    function ps_adjust_bill(PDO $pdo, string $billType, int $billId, string $adjustmentType, $amount, string $reason, string $businessDate, int $userId): array
    {
        if (!in_array($billType, ['water', 'one_time', 'installment', 'recurring'], true)) throw new Exception('Invalid bill type.');
        if (!in_array($adjustmentType, ['debit', 'credit'], true)) throw new Exception('Invalid bill adjustment type.');
        $amountCents = ps_money_to_cents($amount, 'Adjustment amount');
        if ($amountCents <= 0) throw new Exception('Adjustment amount must be greater than zero.');
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) throw new Exception('A clear adjustment reason is required.');
        $businessDate = ps_validate_business_date($pdo, $businessDate);
        if (!cr_table_exists($pdo, 'bill_adjustments')) {
            throw new Exception('Bill adjustment migration is incomplete: bill_adjustments is missing.');
        }

        $pdo->beginTransaction();
        try {
            $old = bl_fetch_bill_for_update($pdo, $billType, $billId);
            if (bl_is_deleted($old)) throw new Exception('Deleted bills cannot be adjusted.');

            $installmentSchedules = [];
            if ($billType === 'water') {
                $oldTotalCents = ps_money_to_cents($old['amount_due'] ?? 0, 'Water bill total');
                $paidCents = ps_money_to_cents($old['amount_paid'] ?? 0, 'Water amount paid');
            } elseif ($billType === 'installment') {
                $oldTotalCents = ps_money_to_cents($old['total_amount'] ?? 0, 'Installment total');
                $scheduleStmt = $pdo->prepare(
                    'SELECT schedule_id, amount, COALESCE(amount_paid,0) AS amount_paid '
                    . 'FROM installment_schedules WHERE installment_id=? ORDER BY due_date, schedule_id FOR UPDATE'
                );
                $scheduleStmt->execute([$billId]);
                $installmentSchedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$installmentSchedules) throw new Exception('Installment bill has no schedules to adjust.');
                $paidCents = 0;
                foreach ($installmentSchedules as $scheduleRow) {
                    $scheduleTotal = ps_money_to_cents($scheduleRow['amount'] ?? 0, 'Installment schedule amount');
                    $schedulePaid = ps_money_to_cents($scheduleRow['amount_paid'] ?? 0, 'Installment schedule amount paid');
                    if ($schedulePaid > $scheduleTotal) {
                        throw new Exception('Installment schedule contains an overpaid balance and must be reconciled before adjustment.');
                    }
                    $paidCents += $schedulePaid;
                }
            } else {
                $oldTotalCents = ps_money_to_cents($old['total_amount'] ?? 0, 'Bill total');
                $paidCents = ps_money_to_cents($old['amount_paid'] ?? 0, 'Amount paid');
            }

            $deltaCents = $adjustmentType === 'debit' ? $amountCents : -$amountCents;
            $newTotalCents = $oldTotalCents + $deltaCents;
            if ($newTotalCents < 0) throw new Exception('Credit would reduce the bill below zero.');
            if ($newTotalCents < $paidCents) throw new Exception('Credit would reduce the bill below the amount already paid. Refund or reverse the payment first.');

            $voucher = bl_ac_create_bill_adjustment_journal(
                $pdo, $billType, $billId, $old, ps_cents_to_money($deltaCents), 0.00,
                ucfirst($adjustmentType) . ' adjustment - ' . $reason, $userId, $businessDate
            );
            if (!$voucher) throw new Exception('Bill adjustment journal was not created.');
            $newTotal = ps_cents_to_money($newTotalCents);

            if ($billType === 'water') {
                $isPaid = $newTotalCents === $paidCents ? 1 : 0;
                $pdo->prepare("UPDATE bill_readings SET amount_due=?, is_paid=?, bill_edit_count=COALESCE(bill_edit_count,0)+1,
                    last_bill_edited_at=NOW(), last_bill_edited_by=?, revision_note=?,
                    last_adjustment_journal_voucher_id=?, last_adjustment_jv_entry_id=?, last_accounting_adjusted_at=NOW(), updated_at=NOW()
                    WHERE reading_id=?")
                    ->execute([$newTotal, $isPaid, $userId, $reason,
                        $voucher['journal_voucher_id'] ?? $voucher['journal_head_id'] ?? null,
                        $voucher['jv_entry_id'] ?? $voucher['journal_entry_id'] ?? null,
                        $billId]);
            } elseif ($billType === 'one_time') {
                $status = $newTotalCents === $paidCents ? 'paid' : 'unpaid';
                $pdo->prepare("UPDATE one_time_bills SET total_amount=?, status=?, bill_edit_count=COALESCE(bill_edit_count,0)+1,
                    last_bill_edited_at=NOW(), last_bill_edited_by=?, revision_note=?,
                    last_adjustment_journal_voucher_id=?, last_adjustment_jv_entry_id=?, last_accounting_adjusted_at=NOW(), updated_at=NOW()
                    WHERE bill_id=?")
                    ->execute([$newTotal, $status, $userId, $reason,
                        $voucher['journal_voucher_id'] ?? $voucher['journal_head_id'] ?? null,
                        $voucher['jv_entry_id'] ?? $voucher['journal_entry_id'] ?? null,
                        $billId]);
            } elseif ($billType === 'recurring') {
                $balance = ps_cents_to_money($newTotalCents - $paidCents);
                $status = $balance <= 0 ? 'paid' : (((string)($old['due_date'] ?? '') < date('Y-m-d')) ? 'overdue' : 'unpaid');
                $pdo->prepare("UPDATE recurring_bills SET total_amount=?, balance=?, status=?, bill_edit_count=COALESCE(bill_edit_count,0)+1,
                    last_bill_edited_at=NOW(), last_bill_edited_by=?, revision_note=?,
                    last_adjustment_journal_voucher_id=?, last_adjustment_jv_entry_id=?, last_accounting_adjusted_at=NOW(), updated_at=NOW()
                    WHERE bill_id=?")
                    ->execute([$newTotal, $balance, $status, $userId, $reason,
                        $voucher['journal_voucher_id'] ?? $voucher['journal_head_id'] ?? null,
                        $voucher['jv_entry_id'] ?? $voucher['journal_entry_id'] ?? null,
                        $billId]);
            } else {
                $remainingCents = $newTotalCents - $paidCents;
                $open = array_values(array_filter(
                    $installmentSchedules,
                    fn($r) => ps_money_to_cents($r['amount_paid']) < ps_money_to_cents($r['amount'])
                ));
                if ($remainingCents > 0 && !$open) throw new Exception('No open installment schedule is available for the adjustment.');
                $openCount = count($open);
                $allocated = 0;
                foreach ($open as $i => $schedule) {
                    $paidSchedule = ps_money_to_cents($schedule['amount_paid']);
                    $remainingPart = $i === $openCount - 1 ? ($remainingCents - $allocated) : intdiv($remainingCents, $openCount);
                    $allocated += $remainingPart;
                    $newScheduleTotal = $paidSchedule + $remainingPart;
                    $status = $remainingPart === 0 ? 'paid' : ($paidSchedule > 0 ? 'partial' : 'pending');
                    $pdo->prepare('UPDATE installment_schedules SET amount=?, status=? WHERE schedule_id=?')
                        ->execute([ps_cents_to_money($newScheduleTotal), $status, $schedule['schedule_id']]);
                }
                $pdo->prepare("UPDATE installment_bills SET total_amount=?, bill_edit_count=COALESCE(bill_edit_count,0)+1,
                    last_bill_edited_at=NOW(), last_bill_edited_by=?, revision_note=?,
                    last_adjustment_journal_voucher_id=?, last_adjustment_jv_entry_id=?, last_accounting_adjusted_at=NOW(), updated_at=NOW()
                    WHERE installment_id=?")
                    ->execute([$newTotal, $userId, $reason,
                        $voucher['journal_voucher_id'] ?? $voucher['journal_head_id'] ?? null,
                        $voucher['jv_entry_id'] ?? $voucher['journal_entry_id'] ?? null,
                        $billId]);
                ps_refresh_installment_parent($pdo, $billId);
            }

            $pdo->prepare("
                INSERT INTO bill_adjustments
                    (bill_type, bill_id, adjustment_type, amount, business_date, reason, created_by, created_at,
                     journal_voucher_id, journal_entry_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'posted')
            ")->execute([
                $billType, $billId, $adjustmentType, ps_cents_to_money($amountCents), $businessDate,
                $reason, $userId, $voucher['journal_voucher_id'] ?? $voucher['journal_head_id'] ?? null,
                $voucher['jv_entry_id'] ?? $voucher['journal_entry_id'] ?? null,
            ]);
            bl_audit($pdo, $userId, strtoupper($adjustmentType) . '_BILL', $billType, (string)$billId, [
                'class_id' => WaterPosting::currentClass(), 'class' => water_class_name(WaterPosting::currentClass()),
                'reason' => $reason, 'old_total' => ps_cents_to_money($oldTotalCents), 'new_total' => $newTotal,
            ]);
            $pdo->commit();
            return ['bill_type' => $billType, 'bill_id' => $billId, 'old_total' => ps_cents_to_money($oldTotalCents), 'new_total' => $newTotal];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
