<?php
/**
 * Billing-period lifecycle helpers.
 *
 * Billing periods are stored as exact date/time cutoffs. The standard monthly
 * cycle starts at 12:00 PM on the previous month-end and ends at 11:59 AM on
 * the current month-end. Operators may submit a different end date/time.
 */

if (!function_exists('bp_parse_datetime')) {
    function bp_parse_datetime(string $value, string $defaultTime = '00:00:00'): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $formats = [
            'Y-m-d\\TH:i:s',
            'Y-m-d\\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'm/d/Y h:i A',
            'm/d/Y g:i A',
            'm/d/Y H:i',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            $valid = $date instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
                && $date->format($format) === $value;
            if ($valid) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        foreach (['Y-m-d', 'm/d/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            $valid = $date instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
                && $date->format($format) === $value;
            if ($valid) {
                $parts = array_map('intval', explode(':', $defaultTime));
                return $date->setTime($parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0)
                    ->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}

if (!function_exists('bp_parse_date')) {
    /**
     * Legacy date-only parser retained for callers that explicitly need a date.
     */
    function bp_parse_date(string $value, array $formats = ['Y-m-d', 'm/d/Y']): ?string
    {
        $value = trim($value);
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            $valid = $date instanceof DateTimeImmutable
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
                && $date->format($format) === $value;
            if ($valid) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }
}

if (!function_exists('bp_datetime_object')) {
    function bp_datetime_object(string $value): DateTimeImmutable
    {
        $normalized = bp_parse_datetime($value);
        if ($normalized === null) {
            throw new InvalidArgumentException('Invalid billing-period date and time.');
        }
        return new DateTimeImmutable($normalized);
    }
}

if (!function_exists('bp_generate_code')) {
    function bp_generate_code(string $startDate, string $endDate): string
    {
        $start = bp_datetime_object($startDate);
        $end = bp_datetime_object($endDate);
        return $start->format('mdy') . '_' . $end->format('mdy');
    }
}

if (!function_exists('bp_validate_range')) {
    function bp_validate_range(string $startDate, string $endDate): void
    {
        $start = bp_datetime_object($startDate);
        $end = bp_datetime_object($endDate);
        if ($end <= $start) {
            throw new InvalidArgumentException('The period end date and time must be later than its start date and time.');
        }
    }
}

if (!function_exists('bp_add_months_clamped')) {
    function bp_add_months_clamped(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        if ($months < 0) {
            throw new InvalidArgumentException('Month duration cannot be negative.');
        }

        $day = (int)$date->format('d');
        $firstOfTarget = $date->modify('first day of this month')->modify('+' . $months . ' months');
        $lastDay = (int)$firstOfTarget->format('t');
        return $firstOfTarget->setDate(
            (int)$firstOfTarget->format('Y'),
            (int)$firstOfTarget->format('m'),
            min($day, $lastDay)
        );
    }
}

if (!function_exists('bp_period_end_from_start')) {
    /**
     * Return the standard end cutoff for the selected duration.
     *
     * Example: 2026-06-30 12:00:00 + one monthly cycle becomes
     * 2026-07-31 11:59:00.
     */
    function bp_period_end_from_start(string $startDate, int $months): string
    {
        if (!in_array($months, [1, 3, 6, 12], true)) {
            throw new InvalidArgumentException('Unsupported billing-period duration.');
        }

        $start = bp_datetime_object($startDate);
        $startsOnMonthEnd = $start->format('d') === $start->format('t');

        if ($startsOnMonthEnd) {
            $end = $start
                ->modify('first day of this month')
                ->modify('+' . $months . ' months')
                ->modify('last day of this month');
        } else {
            $end = bp_add_months_clamped($start, $months)->modify('-1 day');
        }

        return $end->setTime(11, 59, 0)->format('Y-m-d H:i:s');
    }
}

if (!function_exists('bp_next_period_dates')) {
    function bp_next_period_dates(string $currentEndDate, int $months): array
    {
        if (!in_array($months, [1, 3, 6, 12], true)) {
            throw new InvalidArgumentException('Unsupported billing-period duration.');
        }

        $currentEnd = bp_datetime_object($currentEndDate);
        $nextStart = $currentEnd->modify('+1 minute');
        $nextStartValue = $nextStart->format('Y-m-d H:i:s');

        return [
            'start_date' => $nextStartValue,
            'end_date' => bp_period_end_from_start($nextStartValue, $months),
        ];
    }
}

if (!function_exists('bp_next_period_dates_from_range')) {
    /**
     * Standard ranges keep the selected month duration. A custom range repeats
     * its exact measured date/time span, beginning one minute after the prior
     * period ends, so there is neither a gap nor an overlap.
     */
    function bp_next_period_dates_from_range(
        string $currentStartDate,
        string $currentEndDate,
        int $months
    ): array {
        bp_validate_range($currentStartDate, $currentEndDate);

        $currentStart = bp_datetime_object($currentStartDate);
        $currentEnd = bp_datetime_object($currentEndDate);
        $defaultEnd = bp_period_end_from_start($currentStartDate, $months);

        if ($currentEnd->format('Y-m-d H:i:s') === $defaultEnd) {
            return bp_next_period_dates($currentEndDate, $months);
        }

        $durationSeconds = $currentEnd->getTimestamp() - $currentStart->getTimestamp();
        $nextStart = $currentEnd->modify('+1 minute');
        $nextEnd = $nextStart->modify('+' . $durationSeconds . ' seconds');

        return [
            'start_date' => $nextStart->format('Y-m-d H:i:s'),
            'end_date' => $nextEnd->format('Y-m-d H:i:s'),
        ];
    }
}

if (!function_exists('bp_fetch_for_update')) {
    function bp_fetch_for_update(PDO $pdo, int $periodId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM bill_periods WHERE period_id = ? FOR UPDATE');
        $stmt->execute([$periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$period) {
            throw new RuntimeException('Billing period was not found.');
        }
        $period['status'] = strtolower((string)($period['status'] ?? ''));
        return $period;
    }
}

if (!function_exists('bp_assert_open')) {
    function bp_assert_open(array $period, string $operation = 'perform this operation'): void
    {
        if (strtolower((string)($period['status'] ?? '')) !== 'open') {
            throw new RuntimeException('This billing period is closed and cannot be used to ' . $operation . '.');
        }
    }
}

if (!function_exists('bp_assert_no_overlap')) {
    function bp_assert_no_overlap(PDO $pdo, string $startDate, string $endDate, int $excludePeriodId = 0): void
    {
        bp_validate_range($startDate, $endDate);
        $sql = "
            SELECT period_id, bp_code, start_date, end_date, status
            FROM bill_periods
            WHERE start_date <= :new_end
              AND end_date >= :new_start
        ";
        $params = [':new_start' => $startDate, ':new_end' => $endDate];
        if ($excludePeriodId > 0) {
            $sql .= ' AND period_id <> :exclude_id';
            $params[':exclude_id'] = $excludePeriodId;
        }
        $sql .= ' ORDER BY start_date ASC, period_id ASC FOR UPDATE';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($conflict) {
            throw new RuntimeException(sprintf(
                'The requested date range overlaps billing period %s (%s to %s).',
                (string)$conflict['bp_code'],
                (string)$conflict['start_date'],
                (string)$conflict['end_date']
            ));
        }
    }
}

if (!function_exists('bp_activity_summary')) {
    function bp_activity_summary(PDO $pdo, int $periodId, bool $forUpdate = false): array
    {
        $sql = "
            SELECT
                COUNT(*) AS reading_count,
                COALESCE(SUM(CASE WHEN is_billed = 0 THEN 1 ELSE 0 END), 0) AS unbilled_count,
                COALESCE(SUM(CASE WHEN is_billed = 1 THEN 1 ELSE 0 END), 0) AS billed_count,
                COALESCE(SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END), 0) AS paid_count
            FROM bill_readings
            WHERE period_id = ?
        ";
        if ($forUpdate) {
            // Lock the actual rows rather than relying on an aggregate lock.
            $lock = $pdo->prepare('SELECT reading_id FROM bill_readings WHERE period_id = ? FOR UPDATE');
            $lock->execute([$periodId]);
            $lock->fetchAll(PDO::FETCH_COLUMN);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'reading_count' => (int)($row['reading_count'] ?? 0),
            'unbilled_count' => (int)($row['unbilled_count'] ?? 0),
            'billed_count' => (int)($row['billed_count'] ?? 0),
            'paid_count' => (int)($row['paid_count'] ?? 0),
        ];
    }
}

if (!function_exists('bp_insert')) {
    function bp_insert(PDO $pdo, string $startDate, string $endDate, int $openedBy, string $createdAt): int
    {
        bp_validate_range($startDate, $endDate);
        bp_assert_no_overlap($pdo, $startDate, $endDate);
        $code = bp_generate_code($startDate, $endDate);

        $stmt = $pdo->prepare("
            INSERT INTO bill_periods
                (bp_code, start_date, end_date, status, opened_by, closed_by, created_at, updated_at, closed_at)
            VALUES
                (?, ?, ?, 'open', ?, NULL, ?, ?, NULL)
        ");
        $stmt->execute([$code, $startDate, $endDate, $openedBy, $createdAt, $createdAt]);
        return (int)$pdo->lastInsertId();
    }
}
