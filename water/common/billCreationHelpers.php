<?php
/**
 * Shared validation and lifecycle helpers for one-time and installment bills.
 */

if (!function_exists('bc_money_cents')) {
    /** Parse a non-negative decimal into exact integer cents with half-up rounding. */
    function bc_money_cents($value, string $label = 'Amount', bool $allowZero = false): int
    {
        if (is_int($value)) {
            $raw = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException("{$label} is outside the supported range.");
            }
            $raw = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        } else {
            $raw = trim((string)$value);
        }

        if (!preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $raw, $match)) {
            throw new InvalidArgumentException("{$label} must be a non-negative decimal number.");
        }

        $wholeText = ltrim($match[1], '0');
        $wholeText = $wholeText === '' ? '0' : $wholeText;
        if (strlen($wholeText) > 12 || (strlen($wholeText) === 12 && strcmp($wholeText, '999999999999') > 0)) {
            throw new InvalidArgumentException("{$label} exceeds DECIMAL(14,2).");
        }

        $fraction = $match[2] ?? '';
        $padded = str_pad($fraction, 3, '0');
        $centsPart = (int)substr($padded, 0, 2);
        $roundDigit = (int)($padded[2] ?? '0');
        $cents = ((int)$wholeText * 100) + $centsPart + ($roundDigit >= 5 ? 1 : 0);
        if ($cents > 99999999999999) {
            throw new InvalidArgumentException("{$label} exceeds DECIMAL(14,2) after rounding.");
        }
        if ($allowZero ? $cents < 0 : $cents <= 0) {
            throw new InvalidArgumentException($allowZero ? "{$label} cannot be negative." : "{$label} must be greater than zero.");
        }
        return $cents;
    }
}

if (!function_exists('bc_cents_amount')) {
    function bc_cents_amount(int $cents): float
    {
        return round($cents / 100, 2);
    }
}

if (!function_exists('bc_positive_quantity')) {
    function bc_positive_quantity($value, string $label = 'Quantity'): float
    {
        $hundredths = bc_money_cents($value, $label);
        if ($hundredths > 999999999999) {
            throw new InvalidArgumentException("{$label} exceeds DECIMAL(12,2).");
        }
        return $hundredths / 100;
    }
}

if (!function_exists('bc_date')) {
    function bc_date($value, string $label): string
    {
        $value = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$label} must be a valid date in YYYY-MM-DD format.");
        }
        return $value;
    }
}


if (!function_exists('bc_optional_id')) {
    /** Parse an optional positive database identifier. Empty means not assigned. */
    function bc_optional_id($value, string $label): int
    {
        $raw = trim((string)$value);
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        if (!preg_match('/^[1-9]\d*$/', $raw)) {
            throw new InvalidArgumentException("{$label} must be a valid positive identifier.");
        }
        if (strlen($raw) > 10 || (strlen($raw) === 10 && strcmp($raw, '2147483647') > 0)) {
            throw new InvalidArgumentException("{$label} is outside the supported identifier range.");
        }
        return (int)$raw;
    }
}

if (!function_exists('bc_validate_org_units')) {
    /**
     * Validate optional organization-unit assignments against active master data.
     * The calling create transaction keeps the selected rows stable until commit.
     */
    function bc_validate_org_units(PDO $pdo, int $branchId, int $segmentId, int $divisionId): void
    {
        $checks = [
            [$branchId, 'SELECT pkey FROM branches WHERE pkey = ? AND Deleted = 0 FOR UPDATE', 'branch'],
            [$segmentId, "SELECT pkey FROM ic_segments WHERE pkey = ? AND status = 'A' FOR UPDATE", 'segment'],
            [$divisionId, "SELECT pkey FROM ic_divisions WHERE pkey = ? AND status = 'A' FOR UPDATE", 'division'],
        ];

        foreach ($checks as [$id, $sql, $label]) {
            if ($id === 0) {
                continue;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === false) {
                throw new RuntimeException("The selected {$label} does not exist or is inactive.");
            }
        }
    }
}

if (!function_exists('bc_lock_active_member')) {
    function bc_lock_active_member(PDO $pdo, int $memberId): array
    {
        if ($memberId <= 0) {
            throw new InvalidArgumentException('Invalid member selected.');
        }
        $stmt = $pdo->prepare("SELECT pkey, account_number, status FROM water_accounts WHERE pkey = ? FOR UPDATE");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$member || strtoupper((string)($member['status'] ?? '')) !== 'A') {
            throw new RuntimeException('The selected member does not exist or is inactive.');
        }
        return $member;
    }
}

if (!function_exists('bc_lock_bill_code')) {
    function bc_lock_bill_code(PDO $pdo, int $billCodeId): array
    {
        if ($billCodeId <= 0) {
            throw new InvalidArgumentException('Invalid bill code selected.');
        }
        $stmt = $pdo->prepare('SELECT * FROM bill_codes WHERE code_id = ? FOR UPDATE');
        $stmt->execute([$billCodeId]);
        $billCode = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$billCode) {
            throw new RuntimeException('The selected bill code does not exist.');
        }
        if (empty($billCode['ar_account']) || empty($billCode['income_account'])) {
            throw new RuntimeException('Bill code is missing AR or Income account mapping.');
        }
        return $billCode;
    }
}

if (!function_exists('bc_is_inventory')) {
    function bc_is_inventory(array $billCode): bool
    {
        return strcasecmp(trim((string)($billCode['type'] ?? '')), 'Inventory') === 0;
    }
}

if (!function_exists('bc_authoritative_gross')) {
    /**
     * Inventory selling price is always derived server-side. Non-inventory
     * amounts remain operator supplied but are normalized to exact cents.
     */
    function bc_authoritative_gross(array $billCode, float $quantity, $submittedAmount): float
    {
        if (bc_is_inventory($billCode)) {
            $salesCents = bc_money_cents($billCode['sales_price'] ?? null, 'Inventory sales price');
            $quantityHundredths = (int)round($quantity * 100, 0, PHP_ROUND_HALF_UP);
            $numerator = $salesCents * $quantityHundredths;
            $grossCents = intdiv($numerator + 50, 100);
            if ($grossCents <= 0) {
                throw new RuntimeException('Inventory gross amount must be greater than zero.');
            }
            return bc_cents_amount($grossCents);
        }
        return bc_cents_amount(bc_money_cents($submittedAmount, 'Total amount'));
    }
}

if (!function_exists('bc_term_days')) {
    function bc_term_days(string $from, string $to): int
    {
        $start = new DateTimeImmutable(bc_date($from, 'Bill date'));
        $end = new DateTimeImmutable(bc_date($to, 'Due date'));
        if ($end < $start) {
            throw new InvalidArgumentException('Due date cannot be earlier than bill date.');
        }
        $days = (int)$start->diff($end)->days;
        if ($days > 3650) {
            throw new InvalidArgumentException('Due date cannot be more than 10 years after the bill date.');
        }
        return $days;
    }
}

if (!function_exists('bc_mode_gap_months')) {
    function bc_mode_gap_months(string $mode): int
    {
        $map = ['monthly' => 1, 'quarterly' => 3, 'semi_annually' => 6, 'annually' => 12];
        if (!array_key_exists($mode, $map)) {
            throw new InvalidArgumentException('Invalid payment mode.');
        }
        return $map[$mode];
    }
}

if (!function_exists('bc_add_months_safe')) {
    function bc_add_months_safe(string $date, int $months): string
    {
        $base = new DateTimeImmutable(bc_date($date, 'Start date'));
        $day = (int)$base->format('d');
        $targetMonth = $base->modify('first day of this month')->modify("+{$months} months");
        $lastDay = (int)$targetMonth->format('t');
        return $targetMonth->setDate((int)$targetMonth->format('Y'), (int)$targetMonth->format('m'), min($day, $lastDay))->format('Y-m-d');
    }
}

if (!function_exists('bc_installment_dates')) {
    function bc_installment_dates(string $startDate, int $termMonths, string $mode): array
    {
        bc_date($startDate, 'Start date');
        $gap = bc_mode_gap_months($mode);
        if ($termMonths <= 0 || $termMonths > 120 || $termMonths % $gap !== 0) {
            throw new InvalidArgumentException('Term must be between 1 and 120 months and divisible by the selected payment mode.');
        }
        $count = intdiv($termMonths, $gap);
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = bc_add_months_safe($startDate, $i * $gap);
        }
        if (count(array_unique($dates)) !== count($dates)) {
            throw new RuntimeException('The generated installment schedule contains duplicate dates.');
        }
        return $dates;
    }
}

if (!function_exists('bc_split_cents')) {
    function bc_split_cents(float $totalAmount, int $count): array
    {
        if ($count <= 0 || $count > 120) {
            throw new InvalidArgumentException('Invalid installment count.');
        }
        $total = bc_money_cents($totalAmount, 'Installment total');
        $base = intdiv($total, $count);
        $remainder = $total % $count;
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = bc_cents_amount($base + ($i < $remainder ? 1 : 0));
        }
        return $result;
    }
}

if (!function_exists('bc_validate_flexible_amounts')) {
    function bc_validate_flexible_amounts($amounts, int $expectedCount, float $expectedTotal): array
    {
        if (!is_array($amounts) || count($amounts) !== $expectedCount) {
            throw new InvalidArgumentException('Flexible schedule amount count does not match the generated schedule.');
        }
        $cents = [];
        foreach ($amounts as $index => $amount) {
            $cents[] = bc_money_cents($amount, 'Schedule amount #' . ($index + 1));
        }
        if (array_sum($cents) !== bc_money_cents($expectedTotal, 'Installment total')) {
            throw new InvalidArgumentException('The schedule amounts must add up exactly to the installment total.');
        }
        return array_map('bc_cents_amount', $cents);
    }
}


if (!function_exists('bc_assert_schedule_total')) {
    /** Ensure schedule lines reconcile exactly to the parent total in cents. */
    function bc_assert_schedule_total(array $amounts, float $expectedTotal, string $label = 'Installment schedule'): void
    {
        if ($amounts === []) {
            throw new InvalidArgumentException("{$label} cannot be empty.");
        }
        $actualCents = 0;
        foreach ($amounts as $index => $amount) {
            $actualCents += bc_money_cents($amount, $label . ' amount #' . ($index + 1), true);
        }
        $expectedCents = bc_money_cents($expectedTotal, $label . ' total', true);
        if ($actualCents !== $expectedCents) {
            throw new RuntimeException(sprintf(
                '%s does not reconcile to the parent total (expected %.2f, got %.2f).',
                $label,
                bc_cents_amount($expectedCents),
                bc_cents_amount($actualCents)
            ));
        }
    }
}

if (!function_exists('bc_lock_installment_schedule_state')) {
    /** Lock all schedule rows and derive authoritative status/paid totals. */
    function bc_lock_installment_schedule_state(PDO $pdo, int $installmentId): array
    {
        if ($installmentId <= 0) {
            throw new InvalidArgumentException('Invalid installment identifier.');
        }
        $stmt = $pdo->prepare("SELECT schedule_id, due_date, amount, status FROM installment_schedules WHERE installment_id = ? ORDER BY schedule_id FOR UPDATE");
        $stmt->execute([$installmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pending = 0;
        $paid = 0;
        $paidCents = 0;
        foreach ($rows as $row) {
            $status = strtolower((string)($row['status'] ?? ''));
            if ($status === 'pending') {
                $pending++;
            } elseif ($status === 'paid') {
                $paid++;
                $paidCents += bc_money_cents($row['amount'] ?? 0, 'Paid schedule amount', true);
            } else {
                throw new RuntimeException('Installment schedule #' . (int)($row['schedule_id'] ?? 0) . ' has an unsupported status.');
            }
        }

        return [
            'rows' => $rows,
            'pending_count' => $pending,
            'paid_count' => $paid,
            'paid_amount' => bc_cents_amount($paidCents),
        ];
    }
}

if (!function_exists('bc_installment_status_from_counts')) {
    function bc_installment_status_from_counts(int $pending, int $paid, string $currentStatus = 'active'): string
    {
        if (in_array($currentStatus, ['cancelled', 'deleted'], true)) {
            return $currentStatus;
        }
        if ($pending === 0 && $paid > 0) {
            return 'completed';
        }
        return 'active';
    }
}

if (!function_exists('bc_refresh_installment_status')) {
    function bc_refresh_installment_status(PDO $pdo, int $installmentId): string
    {
        $stmt = $pdo->prepare('SELECT status FROM installment_bills WHERE installment_id = ? FOR UPDATE');
        $stmt->execute([$installmentId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            throw new RuntimeException('Installment contract was not found.');
        }
        $state = bc_lock_installment_schedule_state($pdo, $installmentId);
        $new = bc_installment_status_from_counts((int)$state['pending_count'], (int)$state['paid_count'], (string)$current);
        if ($new !== $current) {
            $update = $pdo->prepare('UPDATE installment_bills SET status = ?, updated_at = NOW() WHERE installment_id = ?');
            $update->execute([$new, $installmentId]);
        }
        return $new;
    }
}
