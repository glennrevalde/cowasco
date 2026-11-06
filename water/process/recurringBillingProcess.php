<?php
require_once __DIR__ . '/../common/discountPrivilegeBillingHelpers.php';
require_once __DIR__ . '/../common/billLifecycleHelpers.php';
require_once __DIR__ . '/../common/billingHelpers.php';
require_once __DIR__ . '/../common/waterBillCodeExclusionHelpers.php';
/**
 * process/recurringBillingProcess.php
 *
 * Recurring billing rules:
 * - bill_date is the date the recurring charge is issued.
 * - billing_period_end is the final date covered by the recurring bill.
 * - due_date is billing_period_end + term_days.
 * - next_billing_date is based on frequency, not on due_date.
 * - Example: a 07/01 to 07/31 monthly period with a 15-day term is due 08/15.
 * - Journal entries are created only when a recurring bill is generated.
 */

if (!function_exists('rb_today')) {
    function rb_today(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('rb_now')) {
    function rb_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('rb_date_is_valid')) {
    function rb_date_is_valid(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

if (!function_exists('rb_money')) {
    function rb_money($amount): string
    {
        $cents = dp_money_cents($amount, 'Recurring billing amount', true);
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        return $sign . '₱ ' . number_format(intdiv($absolute, 100), 0, '.', ',')
            . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('rb_format_date')) {
    function rb_format_date($date): string
    {
        return $date ? date('m/d/Y', strtotime($date)) : '—';
    }
}

if (!function_exists('rb_uuid')) {
    function rb_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('rb_generate_global_ref')) {
    function rb_generate_global_ref(PDO $pdo): int
    {
        if (!function_exists('generateGlobalRef')) {
            throw new RuntimeException('The global billing reference generator is unavailable.');
        }
        // a number already used by a document of the ledger is never reused
        $usedRef = $pdo->prepare('SELECT 1 FROM water_postings WHERE doc_no = ? LIMIT 1');
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $globalRef = (int)generateGlobalRef($pdo);
            if ($globalRef <= 0) {
                continue;
            }
            $usedRef->execute([(string)$globalRef]);
            if (!$usedRef->fetchColumn()) {
                return $globalRef;
            }
        }
        throw new RuntimeException('Unable to allocate an unused global billing reference after 100 attempts. Synchronize global_billing_sequence with the existing documents.');
    }
}

if (!function_exists('rb_frequency_months')) {
    function rb_frequency_months(string $frequency): int
    {
        switch ($frequency) {
            case 'Quarterly':
                return 3;
            case 'Semi-Annually':
                return 6;
            case 'Yearly':
                return 12;
            case 'Monthly':
                return 1;
            default:
                throw new InvalidArgumentException('Invalid recurring frequency.');
        }
    }
}

if (!function_exists('rb_next_billing_date')) {
    function rb_next_billing_date(string $billDate, string $frequency, ?string $anchorDate = null): string
    {
        $anchorDate = $anchorDate ?: $billDate;
        if (!rb_date_is_valid($billDate) || !rb_date_is_valid($anchorDate)) {
            throw new InvalidArgumentException('Invalid recurring billing date.');
        }

        $source = new DateTimeImmutable($billDate);
        $anchor = new DateTimeImmutable($anchorDate);
        $targetMonth = $source->modify('first day of this month')->modify('+' . rb_frequency_months($frequency) . ' months');
        $targetLastDay = (int)$targetMonth->format('t');
        $anchorDay = (int)$anchor->format('d');
        $anchorIsMonthEnd = $anchorDay === (int)$anchor->format('t');
        $targetDay = $anchorIsMonthEnd ? $targetLastDay : min($anchorDay, $targetLastDay);

        return $targetMonth->setDate(
            (int)$targetMonth->format('Y'),
            (int)$targetMonth->format('m'),
            $targetDay
        )->format('Y-m-d');
    }
}

if (!function_exists('rb_period_end')) {
    function rb_period_end(string $billDate, string $frequency, ?string $anchorDate = null): string
    {
        $date = new DateTime(rb_next_billing_date($billDate, $frequency, $anchorDate));
        $date->modify('-1 day');
        return $date->format('Y-m-d');
    }
}

if (!function_exists('rb_due_date')) {
    function rb_due_date(string $billingPeriodEnd, int $termDays): string
    {
        if (!rb_date_is_valid($billingPeriodEnd) || $termDays < 1 || $termDays > 3660) {
            throw new InvalidArgumentException('Invalid recurring billing period end date or term.');
        }

        return (new DateTimeImmutable($billingPeriodEnd))
            ->modify('+' . $termDays . ' days')
            ->format('Y-m-d');
    }
}

if (!function_exists('rb_first_billing_on_or_after')) {
    function rb_first_billing_on_or_after(string $firstBillingDate, string $frequency, string $subscriptionStartDate): string
    {
        $date = $firstBillingDate;
        $limit = 0;

        while ($date < $subscriptionStartDate && $limit < 240) {
            $date = rb_next_billing_date($date, $frequency, $firstBillingDate);
            $limit++;
        }

        if ($date < $subscriptionStartDate) {
            throw new RuntimeException('Recurring schedule exceeds the supported 240-cycle range.');
        }
        return $date;
    }
}


if (!function_exists('rb_latest_billing_on_or_before')) {
    function rb_latest_billing_on_or_before(string $firstBillingDate, string $frequency, string $today): string
    {
        $date = $firstBillingDate;
        $lastValidDate = $firstBillingDate;
        $limit = 0;

        while ($date <= $today && $limit < 240) {
            $lastValidDate = $date;
            $date = rb_next_billing_date($date, $frequency, $firstBillingDate);
            $limit++;
        }

        if ($date <= $today) {
            throw new RuntimeException('Recurring schedule exceeds the supported 240-cycle range.');
        }
        return $lastValidDate;
    }
}

if (!function_exists('rb_first_future_billing_after')) {
    function rb_first_future_billing_after(string $firstBillingDate, string $frequency, string $afterDate): string
    {
        $date = $firstBillingDate;
        $limit = 0;

        while ($date <= $afterDate && $limit < 240) {
            $date = rb_next_billing_date($date, $frequency, $firstBillingDate);
            $limit++;
        }

        if ($date <= $afterDate) {
            throw new RuntimeException('Recurring schedule exceeds the supported 240-cycle range.');
        }
        return $date;
    }
}

if (!function_exists('rb_initial_next_billing_date')) {
    function rb_initial_next_billing_date(array $plan, string $subscriptionStartDate, string $catchUpMode = 'schedule_only', ?string $today = null): string
    {
        $today = $today ?: rb_today();
        $mode = strtolower(trim($catchUpMode));
        $firstBillingDate = (string)$plan['first_billing_date'];
        $frequency = (string)$plan['frequency'];

        /*
         * schedule_only:
         *   No immediate bill/accounting. The next billing date is the next future
         *   scheduled cycle after today/enrollment date.
         *
         * current_cycle:
         *   Bill only the latest cycle that already started.
         *   Example: First Billing Feb 20, today May 25 => next billing date May 20.
         *
         * all_cycles:
         *   Bill from the plan's first billing date up to today.
         *   Example: Feb 20, Mar 20, Apr 20, May 20.
         */
        if ($mode === 'all_cycles') {
            return $firstBillingDate;
        }

        if ($mode === 'current_cycle') {
            if ($firstBillingDate > $today) {
                return $firstBillingDate;
            }

            return rb_latest_billing_on_or_before($firstBillingDate, $frequency, $today);
        }

        $anchor = max($today, $subscriptionStartDate);
        return rb_first_future_billing_after($firstBillingDate, $frequency, $anchor);
    }
}

if (!function_exists('rb_billing_cycles_preview')) {
    function rb_billing_cycles_preview(string $firstBillingDate, string $frequency, int $termDays, ?string $today = null, int $limit = 24): array
    {
        $today = $today ?: rb_today();
        $cycles = [];
        $date = $firstBillingDate;
        $count = 0;

        while ($date <= $today && $count < $limit) {
            $periodEnd = rb_period_end($date, $frequency, $firstBillingDate);

            $cycles[] = [
                'bill_date' => $date,
                'period_start' => $date,
                'period_end' => $periodEnd,
                'due_date' => rb_due_date($periodEnd, $termDays),
                'is_current' => rb_next_billing_date($date, $frequency, $firstBillingDate) > $today,
            ];

            $date = rb_next_billing_date($date, $frequency, $firstBillingDate);
            $count++;
        }

        if (empty($cycles) && rb_date_is_valid($firstBillingDate)) {
            $periodEnd = rb_period_end($firstBillingDate, $frequency, $firstBillingDate);

            $cycles[] = [
                'bill_date' => $firstBillingDate,
                'period_start' => $firstBillingDate,
                'period_end' => $periodEnd,
                'due_date' => rb_due_date($periodEnd, $termDays),
                'is_current' => false,
            ];
        }

        return $cycles;
    }
}


if (!function_exists('rb_get_bill_code')) {
    function rb_get_bill_code(PDO $pdo, int $billCodeId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM bill_codes WHERE code_id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$billCodeId]);
        $billCode = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$billCode) {
            throw new Exception('Bill code not found.');
        }

        if (($billCode['type'] ?? '') === 'Inventory') {
            throw new Exception('Inventory bill codes are not supported by recurring billing because each cycle would require a separately locked quantity and cost snapshot.');
        }

        if (empty($billCode['ar_account']) || empty($billCode['income_account'])) {
            throw new Exception('Selected bill code must have AR Account and Income Account before it can create journal entries.');
        }

        return $billCode;
    }
}

if (!function_exists('rb_create_plan')) {
    function rb_create_plan(PDO $pdo, array $data): int
    {
        $billCodeId = (int)($data['bill_code_id'] ?? 0);
        $amountCents = dp_money_cents($data['amount'] ?? null, 'Recurring plan amount');
        $amount = dp_cents_amount($amountCents);
        $frequency = trim((string)($data['frequency'] ?? 'Monthly'));
        $firstBillingDate = trim((string)($data['first_billing_date'] ?? ''));
        $termDays = (int)($data['term_days'] ?? 15);
        $createdBy = (int)($data['created_by'] ?? 0);

        $allowedFrequency = ['Monthly', 'Quarterly', 'Semi-Annually', 'Yearly'];

        if ($billCodeId <= 0) {
            throw new Exception('Please select a bill code.');
        }

        if ($amountCents <= 0) {
            throw new Exception('Amount must be greater than zero.');
        }

        if (!in_array($frequency, $allowedFrequency, true)) {
            throw new Exception('Invalid frequency selected.');
        }

        if (!rb_date_is_valid($firstBillingDate)) {
            throw new Exception('Invalid first billing date.');
        }

        if ($termDays <= 0 || $termDays > 3660) {
            throw new Exception('Term days must be between 1 and 3660.');
        }
        if ($createdBy <= 0) {
            throw new Exception('A valid operator session is required.');
        }

        assert_not_water_billing_reserved_bill_code($pdo, $billCodeId, 'Recurring Billing');

        rb_get_bill_code($pdo, $billCodeId);

        $operator = $pdo->prepare('SELECT pkey FROM operator WHERE pkey = ? LIMIT 1 FOR UPDATE');
        $operator->execute([$createdBy]);
        if (!$operator->fetchColumn()) {
            throw new RuntimeException('The current operator record no longer exists.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO recurring_plans
                (bill_code_id, amount, frequency, first_billing_date, term_days, status, created_by, created_at, updated_at)
            VALUES
                (:bill_code_id, :amount, :frequency, :first_billing_date, :term_days, 'active', :created_by, NOW(), NOW())
        ");

        $stmt->execute([
            ':bill_code_id' => $billCodeId,
            ':amount' => $amount,
            ':frequency' => $frequency,
            ':first_billing_date' => $firstBillingDate,
            ':term_days' => $termDays,
            ':created_by' => $createdBy,
        ]);

        $planId = (int)$pdo->lastInsertId();

        if (function_exists('logAudit')) {
            logAudit($pdo, $createdBy, 'CREATE_RECURRING_PLAN', 'recurring_plans', (string)$planId, json_encode($data));
        }

        return $planId;
    }
}


if (!function_exists('rb_update_plan')) {
    /**
     * Edit a recurring plan only while no recurring bill has been posted from it.
     * Existing subscription dates are recalculated without moving a member's
     * next billing date backward and without generating any financial record.
     */
    function rb_update_plan(PDO $pdo, array $data): void
    {
        $planId = (int)($data['plan_id'] ?? 0);
        $billCodeId = (int)($data['bill_code_id'] ?? 0);
        $amountCents = dp_money_cents($data['amount'] ?? null, 'Recurring plan amount');
        $amount = dp_cents_amount($amountCents);
        $frequency = trim((string)($data['frequency'] ?? 'Monthly'));
        $firstBillingDate = trim((string)($data['first_billing_date'] ?? ''));
        $termDays = (int)($data['term_days'] ?? 15);
        $updatedBy = (int)($data['updated_by'] ?? 0);
        $allowedFrequency = ['Monthly', 'Quarterly', 'Semi-Annually', 'Yearly'];

        if ($planId <= 0) throw new InvalidArgumentException('Invalid recurring plan.');
        if ($billCodeId <= 0) throw new InvalidArgumentException('Please select a bill code.');
        if ($amountCents <= 0) throw new InvalidArgumentException('Amount must be greater than zero.');
        if (!in_array($frequency, $allowedFrequency, true)) throw new InvalidArgumentException('Invalid frequency selected.');
        if (!rb_date_is_valid($firstBillingDate)) throw new InvalidArgumentException('Invalid first billing date.');
        if ($termDays <= 0 || $termDays > 3660) throw new InvalidArgumentException('Term days must be between 1 and 3660.');
        if ($updatedBy <= 0) throw new RuntimeException('A valid operator session is required.');

        $lock = $pdo->prepare('SELECT * FROM recurring_plans WHERE plan_id = ? LIMIT 1 FOR UPDATE');
        $lock->execute([$planId]);
        $oldPlan = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$oldPlan) throw new RuntimeException('Recurring plan not found.');

        $posted = $pdo->prepare('SELECT COUNT(*) FROM recurring_bills WHERE plan_id = ?');
        $posted->execute([$planId]);
        if ((int)$posted->fetchColumn() > 0) {
            throw new RuntimeException('This recurring plan already has a posted bill and is locked from editing. Set it to inactive if it should no longer be used.');
        }

        assert_not_water_billing_reserved_bill_code($pdo, $billCodeId, 'Recurring Billing');
        rb_get_bill_code($pdo, $billCodeId);

        $subscriptions = $pdo->prepare(
            'SELECT subscription_id, subscription_start_date, next_billing_date, last_billed_date '
            . 'FROM recurring_subscriptions WHERE plan_id = ? ORDER BY subscription_id FOR UPDATE'
        );
        $subscriptions->execute([$planId]);
        $subscriptionRows = $subscriptions->fetchAll(PDO::FETCH_ASSOC);

        foreach ($subscriptionRows as $subscription) {
            if (!empty($subscription['last_billed_date'])) {
                throw new RuntimeException('This recurring plan has a subscription with posted billing history and can no longer be edited.');
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE recurring_plans SET bill_code_id=?, amount=?, frequency=?, first_billing_date=?, term_days=?, updated_at=NOW() WHERE plan_id=?'
        );
        $stmt->execute([$billCodeId, $amount, $frequency, $firstBillingDate, $termDays, $planId]);

        $updateSubscription = $pdo->prepare(
            'UPDATE recurring_subscriptions SET next_billing_date=?, updated_at=NOW() WHERE subscription_id=?'
        );
        foreach ($subscriptionRows as $subscription) {
            $subscriptionStart = (string)$subscription['subscription_start_date'];
            $currentNext = (string)$subscription['next_billing_date'];
            $notBefore = $subscriptionStart;
            if (rb_date_is_valid($currentNext) && $currentNext > $notBefore) {
                $notBefore = $currentNext;
            }
            $nextBillingDate = rb_first_billing_on_or_after($firstBillingDate, $frequency, $notBefore);
            $updateSubscription->execute([$nextBillingDate, (int)$subscription['subscription_id']]);
        }

        if (function_exists('logAudit')) {
            logAudit($pdo, $updatedBy, 'UPDATE_RECURRING_PLAN', 'recurring_plans', (string)$planId, json_encode([
                'before' => [
                    'bill_code_id' => (int)$oldPlan['bill_code_id'],
                    'amount' => (string)$oldPlan['amount'],
                    'frequency' => (string)$oldPlan['frequency'],
                    'first_billing_date' => (string)$oldPlan['first_billing_date'],
                    'term_days' => (int)$oldPlan['term_days'],
                ],
                'after' => [
                    'bill_code_id' => $billCodeId,
                    'amount' => $amount,
                    'frequency' => $frequency,
                    'first_billing_date' => $firstBillingDate,
                    'term_days' => $termDays,
                ],
                'subscriptions_recalculated' => count($subscriptionRows),
            ]));
        }
    }
}

if (!function_exists('rb_enroll_member')) {
    function rb_enroll_member(PDO $pdo, int $planId, int $memberId, string $subscriptionStartDate, int $createdBy, string $catchUpMode = 'schedule_only'): int
    {
        if ($planId <= 0 || $memberId <= 0) {
            throw new Exception('Please select a valid plan and member.');
        }

        if (!rb_date_is_valid($subscriptionStartDate)) {
            throw new Exception('Invalid enrollment date.');
        }

        if (!in_array($catchUpMode, ['schedule_only', 'current_cycle', 'all_cycles'], true)) {
            throw new Exception('Invalid catch-up billing option.');
        }

        if ($createdBy <= 0) {
            throw new Exception('A valid operator session is required.');
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM recurring_plans
            WHERE plan_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            throw new Exception('Recurring plan not found.');
        }

        if ($plan['status'] !== 'active') {
            throw new Exception('Cannot enroll into an inactive plan.');
        }

        $memberStmt = $pdo->prepare("
            SELECT pkey
            FROM water_accounts
            WHERE pkey = ?
              AND status = 'A'
            LIMIT 1
            FOR UPDATE
        ");
        $memberStmt->execute([$memberId]);

        if (!$memberStmt->fetchColumn()) {
            throw new Exception('Active member not found.');
        }

        $operatorStmt = $pdo->prepare("
            SELECT pkey
            FROM operator
            WHERE pkey = ?
            LIMIT 1
            FOR UPDATE
        ");
        $operatorStmt->execute([$createdBy]);

        if (!$operatorStmt->fetchColumn()) {
            throw new RuntimeException('The current operator record no longer exists.');
        }

        $nextBillingDate = rb_initial_next_billing_date(
            $plan,
            $subscriptionStartDate,
            $catchUpMode,
            rb_today()
        );

        $existingStmt = $pdo->prepare("
            SELECT
                s.*,
                (
                    SELECT COUNT(*)
                    FROM recurring_bills b
                    WHERE b.subscription_id = s.subscription_id
                      AND b.bill_lifecycle_status = 'active'
                      AND b.status NOT IN ('cancelled', 'deleted')
                ) AS active_bill_count
            FROM recurring_subscriptions s
            WHERE s.plan_id = ?
              AND s.member_id = ?
              AND s.status IN ('active', 'paused')
            ORDER BY
                active_bill_count DESC,
                CASE WHEN s.status = 'active' THEN 0 ELSE 1 END,
                s.subscription_id DESC
            FOR UPDATE
        ");
        $existingStmt->execute([$planId, $memberId]);
        $existingSubscriptions = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($existingSubscriptions) {
            $withActiveBills = array_values(array_filter(
                $existingSubscriptions,
                static function (array $row): bool {
                    return (int)$row['active_bill_count'] > 0;
                }
            ));

            if (count($withActiveBills) > 1) {
                throw new RuntimeException(
                    'Multiple active subscriptions with live recurring bills were found for this member and plan.'
                );
            }

            $keep = $withActiveBills[0] ?? $existingSubscriptions[0];
            $keepId = (int)$keep['subscription_id'];

            $endDuplicate = $pdo->prepare("
                UPDATE recurring_subscriptions
                SET status = 'ended',
                    updated_at = NOW()
                WHERE subscription_id = ?
                  AND status IN ('active', 'paused')
            ");

            foreach ($existingSubscriptions as $candidate) {
                $candidateId = (int)$candidate['subscription_id'];

                if ($candidateId === $keepId) {
                    continue;
                }

                if ((int)$candidate['active_bill_count'] > 0) {
                    throw new RuntimeException(
                        'Another active subscription still has live recurring bills and cannot be ended automatically.'
                    );
                }

                $endDuplicate->execute([$candidateId]);
            }

            if ((int)$keep['active_bill_count'] === 0) {
                $reuse = $pdo->prepare("
                    UPDATE recurring_subscriptions
                    SET subscription_start_date = :subscription_start_date,
                        next_billing_date = :next_billing_date,
                        last_billed_date = NULL,
                        status = 'active',
                        created_by = :created_by,
                        updated_at = NOW()
                    WHERE subscription_id = :subscription_id
                ");

                $reuse->execute([
                    ':subscription_start_date' => $subscriptionStartDate,
                    ':next_billing_date' => $nextBillingDate,
                    ':created_by' => $createdBy,
                    ':subscription_id' => $keepId,
                ]);
            } elseif ($keep['status'] === 'paused') {
                $pdo->prepare("
                    UPDATE recurring_subscriptions
                    SET status = 'active',
                        updated_at = NOW()
                    WHERE subscription_id = ?
                ")->execute([$keepId]);
            }

            if (function_exists('logAudit')) {
                logAudit(
                    $pdo,
                    $createdBy,
                    'REUSE_RECURRING_SUBSCRIPTION',
                    'recurring_subscriptions',
                    (string)$keepId,
                    json_encode([
                        'plan_id' => $planId,
                        'member_id' => $memberId,
                        'next_billing_date' => $nextBillingDate,
                        'catch_up_mode' => $catchUpMode,
                    ])
                );
            }

            return $keepId;
        }

        $insert = $pdo->prepare("
            INSERT INTO recurring_subscriptions
                (plan_id, member_id, subscription_start_date, next_billing_date, last_billed_date, status, created_by, created_at, updated_at)
            VALUES
                (:plan_id, :member_id, :subscription_start_date, :next_billing_date, NULL, 'active', :created_by, NOW(), NOW())
        ");

        $insert->execute([
            ':plan_id' => $planId,
            ':member_id' => $memberId,
            ':subscription_start_date' => $subscriptionStartDate,
            ':next_billing_date' => $nextBillingDate,
            ':created_by' => $createdBy,
        ]);

        $subscriptionId = (int)$pdo->lastInsertId();

        if (function_exists('logAudit')) {
            logAudit(
                $pdo,
                $createdBy,
                'CREATE_RECURRING_SUBSCRIPTION',
                'recurring_subscriptions',
                (string)$subscriptionId,
                json_encode([
                    'plan_id' => $planId,
                    'member_id' => $memberId,
                    'next_billing_date' => $nextBillingDate,
                    'catch_up_mode' => $catchUpMode,
                ])
            );
        }

        return $subscriptionId;
    }
}

if (!function_exists('rb_get_active_member_ids')) {
    function rb_get_active_member_ids(PDO $pdo): array
    {
        return array_map('intval', $pdo->query("
            SELECT pkey
            FROM water_accounts
            WHERE status = 'A'
            ORDER BY lastname ASC, firstname ASC
        ")->fetchAll(PDO::FETCH_COLUMN));
    }
}



if (!function_exists('rb_batch_enroll_members')) {
    function rb_batch_enroll_members(PDO $pdo, int $planId, array $memberIds, string $subscriptionStartDate, int $createdBy, string $catchUpMode = 'schedule_only'): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];
        foreach ($memberIds as $memberId) {
            $memberId = (int)$memberId;
            if ($memberId <= 0) continue;
            try {
                rb_enroll_member($pdo, $planId, $memberId, $subscriptionStartDate, $createdBy, $catchUpMode);
                $created++;
            } catch (Exception $e) {
                $skipped++;
                $errors[] = 'Member #' . $memberId . ': ' . $e->getMessage();
            }
        }
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }
}

if (!function_exists('rb_create_journal_for_bill')) {
    /**
     * Posts a generated recurring bill as an invoice of the account's customer (app/WaterPosting.php):
     * Dr the bill code's A/R account, Cr its income account. Returns the bill's global reference number (its
     * invoice number) and the ledger document id the bill row remembers as journal_voucher_id.
     */
    function rb_create_journal_for_bill(PDO $pdo, int $billId, array $bill, array $billCode): array
    {
        $amount = dp_cents_amount(dp_money_cents($bill['total_amount'], 'Recurring bill amount'));
        $memberId = (int)$bill['member_id'];
        $billDate = $bill['bill_date'];
        $description = "Recurring bill #{$billId} ({$billCode['code']}) for account #{$memberId}";
        $globalRef = rb_generate_global_ref($pdo);
        if ($amount <= 0) {
            return ['global_ref_number' => $globalRef, 'journal_voucher_id' => null];
        }
        $posted = WaterPosting::postBill('recurring', $billId, [
            'water_account_id' => $memberId,
            'txn_date' => $billDate,
            'due_date' => $bill['due_date'] ?? null,
            'doc_no' => (string)$globalRef,
            'ar_account_id' => (int)$billCode['ar_account'],
            'lines' => [['account_id' => (int)$billCode['income_account'], 'amount' => $amount, 'description' => $description]],
            'memo' => $description,
            'branch_id' => (int)($bill['branch_id'] ?? 0), 'segment_id' => (int)($bill['segment_id'] ?? 0), 'division_id' => (int)($bill['division_id'] ?? 0),
        ]);
        return ['global_ref_number' => $globalRef, 'journal_voucher_id' => $posted ? $posted['journal_head_id'] : null];
    }
}

if (!function_exists('rb_generate_bill')) {
    function rb_generate_bill(PDO $pdo, array $subscription, array $plan, array $billCode, string $billDate, int $createdBy): ?int
    {
        $periodEnd = rb_period_end($billDate, $plan['frequency'], (string)$plan['first_billing_date']);
        $dueDate = rb_due_date($periodEnd, (int)$plan['term_days']);
        $grossAmount = dp_cents_amount(dp_money_cents($plan['amount'], 'Recurring plan amount'));
        $discount = dp_compute_member_bill_discount($pdo, (int)$subscription['member_id'], (int)$plan['bill_code_id'], 'recurring', $grossAmount);
        $amount = dp_cents_amount(dp_money_cents($discount['net_amount'], 'Recurring bill net amount'));

        $dup = $pdo->prepare("
            SELECT bill_id
            FROM recurring_bills
            WHERE plan_id = ?
              AND member_id = ?
              AND bill_date = ?
              AND bill_lifecycle_status = 'active'
              AND status NOT IN ('cancelled', 'deleted')
            LIMIT 1
            FOR UPDATE
        ");
        $dup->execute([
            (int)$plan['plan_id'],
            (int)$subscription['member_id'],
            $billDate,
        ]);

        if ($dup->fetchColumn()) {
            return null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO recurring_bills
                (global_ref_number, journal_voucher_id, subscription_id, plan_id, member_id, bill_code_id,
                 billing_period_start, billing_period_end, bill_date, due_date, term_days,
                 total_amount, gross_amount, discount_amount, discount_id, discount_rule_id, amount_paid, balance, status, created_by, created_at, updated_at)
            VALUES
                (NULL, NULL, :subscription_id, :plan_id, :member_id, :bill_code_id,
                 :billing_period_start, :billing_period_end, :bill_date, :due_date, :term_days,
                 :total_amount, :gross_amount, :discount_amount, :discount_id, :discount_rule_id, 0.00, :balance, 'unpaid', :created_by, NOW(), NOW())
        ");

        $stmt->execute([
            ':subscription_id' => (int)$subscription['subscription_id'],
            ':plan_id' => (int)$plan['plan_id'],
            ':member_id' => (int)$subscription['member_id'],
            ':bill_code_id' => (int)$plan['bill_code_id'],
            ':billing_period_start' => $billDate,
            ':billing_period_end' => $periodEnd,
            ':bill_date' => $billDate,
            ':due_date' => $dueDate,
            ':term_days' => (int)$plan['term_days'],
            ':total_amount' => $amount,
            ':gross_amount' => $discount['gross_amount'],
            ':discount_amount' => $discount['discount_amount'],
            ':discount_id' => $discount['discount_id'],
            ':discount_rule_id' => $discount['discount_rule_id'],
            ':balance' => $amount,
            ':created_by' => $createdBy,
        ]);

        $billId = (int)$pdo->lastInsertId();

        $bill = [
            'bill_id' => $billId,
            'member_id' => (int)$subscription['member_id'],
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'total_amount' => $amount,
            'gross_amount' => $discount['gross_amount'],
            'discount_amount' => $discount['discount_amount'],
        ];

        $journal = rb_create_journal_for_bill($pdo, $billId, $bill, $billCode);

        $update = $pdo->prepare("
            UPDATE recurring_bills
            SET global_ref_number = :global_ref_number,
                journal_voucher_id = :journal_voucher_id,
                updated_at = NOW()
            WHERE bill_id = :bill_id
        ");

        $update->execute([
            ':global_ref_number' => $journal['global_ref_number'],
            ':journal_voucher_id' => $journal['journal_voucher_id'],
            ':bill_id' => $billId,
        ]);

        if (function_exists('logAudit')) {
            logAudit($pdo, $createdBy, 'GENERATE_RECURRING_BILL', 'recurring_bills', (string)$billId, json_encode([
                'global_ref_number' => $journal['global_ref_number'],
                'subscription_id' => $subscription['subscription_id'],
                'bill_date' => $billDate,
            ]));
        }

        return $billId;
    }
}

if (!function_exists('rb_update_overdue_statuses')) {
    function rb_update_overdue_statuses(PDO $pdo, ?string $today = null): int
    {
        $today = $today ?: rb_today();

        $stmt = $pdo->prepare("
            UPDATE recurring_bills
            SET status = 'overdue', updated_at = NOW()
            WHERE status = 'unpaid'
              AND bill_lifecycle_status = 'active'
              AND due_date < :today
              AND balance > 0
        ");

        $stmt->execute([':today' => $today]);
        return $stmt->rowCount();
    }
}

if (!function_exists('rb_process_recurring_bills')) {
    function rb_process_recurring_bills(PDO $pdo, ?string $today = null, ?int $createdBy = null): array
    {
        $today = $today ?: rb_today();
        if (!rb_date_is_valid($today)) {
            return ['success' => false, 'generated' => 0, 'advanced' => 0, 'message' => 'Invalid recurring processing date.'];
        }
        $createdBy = $createdBy ?: (int)($_SESSION['user_id'] ?? 0);
        if ($createdBy <= 0) {
            return ['success' => false, 'generated' => 0, 'advanced' => 0, 'message' => 'A valid operator is required to generate recurring bills.'];
        }
        $generated = 0;
        $advanced = 0;

        if ($pdo->inTransaction()) {
            return ['success' => false, 'generated' => 0, 'advanced' => 0, 'message' => 'Recurring processing must start outside another database transaction.'];
        }
        $pdo->beginTransaction();

        try {
            rb_update_overdue_statuses($pdo, $today);

            $operator = $pdo->prepare('SELECT pkey FROM operator WHERE pkey = ? LIMIT 1 FOR UPDATE');
            $operator->execute([$createdBy]);
            if (!$operator->fetchColumn()) {
                throw new RuntimeException('The recurring-billing operator record does not exist.');
            }

            $stmt = $pdo->prepare("
                SELECT
                    s.subscription_id,
                    s.member_id,
                    s.next_billing_date,
                    s.status AS subscription_status,
                    p.plan_id,
                    p.bill_code_id,
                    p.amount,
                    p.frequency,
                    p.first_billing_date,
                    p.term_days,
                    p.status AS plan_status
                FROM recurring_subscriptions s
                INNER JOIN recurring_plans p ON p.plan_id = s.plan_id
                WHERE s.status = 'active'
                  AND p.status = 'active'
                  AND s.next_billing_date <= :today
                ORDER BY s.next_billing_date ASC, s.subscription_id ASC
                FOR UPDATE
            ");

            $stmt->execute([':today' => $today]);
            $dueSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($dueSubscriptions as $row) {
                $billCode = rb_get_bill_code($pdo, (int)$row['bill_code_id']);
                $workingDate = $row['next_billing_date'];
                $loopGuard = 0;

                while ($workingDate <= $today && $loopGuard < 120) {
                    $billId = rb_generate_bill($pdo, $row, $row, $billCode, $workingDate, $createdBy);

                    if ($billId) {
                        $generated++;
                    }

                    $nextDate = rb_next_billing_date($workingDate, $row['frequency'], $row['first_billing_date']);

                    $updateSub = $pdo->prepare("
                        UPDATE recurring_subscriptions
                        SET last_billed_date = :last_billed_date,
                            next_billing_date = :next_billing_date,
                            updated_at = NOW()
                        WHERE subscription_id = :subscription_id
                    ");

                    $updateSub->execute([
                        ':last_billed_date' => $workingDate,
                        ':next_billing_date' => $nextDate,
                        ':subscription_id' => (int)$row['subscription_id'],
                    ]);

                    $workingDate = $nextDate;
                    $advanced++;
                    $loopGuard++;
                }

                if ($workingDate <= $today) {
                    throw new RuntimeException('Recurring catch-up exceeded 120 cycles for subscription #' . (int)$row['subscription_id'] . '. No partial generation was committed.');
                }
            }

            rb_update_overdue_statuses($pdo, $today);

            $pdo->commit();

            return [
                'success' => true,
                'generated' => $generated,
                'advanced' => $advanced,
                'message' => $generated . ' recurring bill(s) generated.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'success' => false,
                'generated' => 0,
                'advanced' => 0,
                'message' => $e->getMessage(),
            ];
        }
    }
}

if (!function_exists('rb_record_payment')) {
    /**
     * Legacy compatibility guard. Recurring payments must be allocated through
     * common/paymentService.php so balances, official receipts, journals, and
     * reversals remain synchronized.
     */
    function rb_record_payment(PDO $pdo, int $billId, float $amount, string $paymentDate, string $remarks, int $createdBy): void
    {
        throw new Exception('Direct recurring-bill payments are disabled. Use the Collections module.');
    }
}

if (!function_exists('rb_update_plan_status')) {
    function rb_update_plan_status(PDO $pdo, int $planId, string $status): void
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new Exception('Invalid plan status.');
        }

        if ($planId <= 0) {
            throw new InvalidArgumentException('Invalid recurring plan.');
        }
        $lock = $pdo->prepare('SELECT status FROM recurring_plans WHERE plan_id = ? FOR UPDATE');
        $lock->execute([$planId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Recurring plan not found.');
        }
        $stmt = $pdo->prepare("UPDATE recurring_plans SET status = ?, updated_at = NOW() WHERE plan_id = ?");
        $stmt->execute([$status, $planId]);
    }
}

if (!function_exists('rb_update_subscription_status')) {
    function rb_update_subscription_status(PDO $pdo, int $subscriptionId, string $status): void
    {
        if (!in_array($status, ['active', 'paused', 'ended'], true)) {
            throw new Exception('Invalid subscription status.');
        }

        if ($subscriptionId <= 0) {
            throw new InvalidArgumentException('Invalid recurring subscription.');
        }
        $lock = $pdo->prepare('SELECT status FROM recurring_subscriptions WHERE subscription_id = ? FOR UPDATE');
        $lock->execute([$subscriptionId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Recurring subscription not found.');
        }
        $stmt = $pdo->prepare("UPDATE recurring_subscriptions SET status = ?, updated_at = NOW() WHERE subscription_id = ?");
        $stmt->execute([$status, $subscriptionId]);
    }
}

if (!function_exists('rb_delete_plan')) {
    function rb_delete_plan(PDO $pdo, int $planId): void
    {
        if ($planId <= 0) {
            throw new InvalidArgumentException('Invalid recurring plan.');
        }
        $lock = $pdo->prepare('SELECT plan_id FROM recurring_plans WHERE plan_id = ? FOR UPDATE');
        $lock->execute([$planId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('Recurring plan not found.');
        }

        $subscriptions = $pdo->prepare('SELECT COUNT(*) FROM recurring_subscriptions WHERE plan_id = ?');
        $subscriptions->execute([$planId]);
        $bills = $pdo->prepare('SELECT COUNT(*) FROM recurring_bills WHERE plan_id = ?');
        $bills->execute([$planId]);
        if ((int)$subscriptions->fetchColumn() > 0 || (int)$bills->fetchColumn() > 0) {
            throw new RuntimeException('This plan already has subscriptions or bills and cannot be deleted. Set it to inactive to preserve billing history.');
        }

        $stmt = $pdo->prepare("DELETE FROM recurring_plans WHERE plan_id = ?");
        $stmt->execute([$planId]);
    }
}

if (!function_exists('rb_get_data')) {
    function rb_get_data(PDO $pdo): array
    {
        $members = $pdo->query("
            SELECT pkey, CONCAT_WS(', ', NULLIF(lastname, ''), NULLIF(firstname, '')) AS full_name
            FROM water_accounts
            WHERE status = 'A'
            ORDER BY lastname ASC, firstname ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $excludeWaterBillingBillCode = water_billing_excluded_bill_code_where($pdo, 'bc');

        $billCodes = $pdo->query("
            SELECT bc.code_id, bc.code, bc.description, bc.type, bc.sales_price, bc.allow_member_discount, bc.ar_account, bc.income_account, bc.cogs_account, bc.asset_account
            FROM bill_codes bc
            WHERE bc.type <> 'Inventory'
              {$excludeWaterBillingBillCode}
            ORDER BY bc.code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $plans = $pdo->query("
            SELECT p.*, c.code, c.description, c.sales_price, c.type,
                   (SELECT COUNT(*) FROM recurring_subscriptions rs WHERE rs.plan_id = p.plan_id) AS subscription_count,
                   (SELECT COUNT(*) FROM recurring_bills rb WHERE rb.plan_id = p.plan_id) AS bill_count
            FROM recurring_plans p
            INNER JOIN bill_codes c ON c.code_id = p.bill_code_id
            ORDER BY p.plan_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $subscriptions = $pdo->query("
            SELECT
                s.*,
                p.amount AS plan_amount,
                p.frequency,
                p.first_billing_date,
                p.term_days,
                c.code,
                m.pkey AS accnt_no,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name
            FROM recurring_subscriptions s
            INNER JOIN recurring_plans p ON p.plan_id = s.plan_id
            INNER JOIN bill_codes c ON c.code_id = p.bill_code_id
            INNER JOIN water_accounts m ON m.pkey = s.member_id
            ORDER BY s.subscription_id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $bills = $pdo->query("
            SELECT
                b.*,
                CASE
                    WHEN b.status = 'unpaid'
                     AND b.bill_lifecycle_status = 'active'
                     AND b.balance > 0
                     AND b.due_date < CURDATE()
                    THEN 'overdue'
                    ELSE b.status
                END AS display_status,
                c.code,
                m.pkey AS accnt_no,
                CONCAT_WS(', ', NULLIF(m.lastname, ''), NULLIF(m.firstname, '')) AS member_name
            FROM recurring_bills b
            INNER JOIN bill_codes c ON c.code_id = b.bill_code_id
            INNER JOIN water_accounts m ON m.pkey = b.member_id
            ORDER BY b.bill_date DESC, b.bill_id DESC
            LIMIT 150
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'generated_bills' => (int)$pdo->query("SELECT COUNT(*) FROM recurring_bills WHERE status NOT IN ('cancelled', 'deleted') AND bill_lifecycle_status = 'active'")->fetchColumn(),
            'active_plans' => (int)$pdo->query("SELECT COUNT(*) FROM recurring_plans WHERE status = 'active'")->fetchColumn(),
            'active_subscriptions' => (int)$pdo->query("SELECT COUNT(*) FROM recurring_subscriptions WHERE status = 'active'")->fetchColumn(),
            'overdue_bills' => (int)$pdo->query("SELECT COUNT(*) FROM recurring_bills WHERE status IN ('unpaid','overdue') AND bill_lifecycle_status = 'active' AND balance > 0 AND due_date < CURDATE()")->fetchColumn(),
            'generated_today' => $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM recurring_bills WHERE bill_date = CURDATE() AND status NOT IN ('cancelled', 'deleted') AND bill_lifecycle_status = 'active'")->fetchColumn(),
        ];

        return [
            'members' => $members,
            'bill_codes' => $billCodes,
            'plans' => $plans,
            'subscriptions' => $subscriptions,
            'bills' => $bills,
            'stats' => $stats,
        ];
    }
}


if (!function_exists('rb_soft_delete_bill')) {
    function rb_soft_delete_bill(PDO $pdo, int $billId, int $userId, string $reason = 'Deleted from Recurring Billing page'): void
    {
        bl_soft_delete_bill($pdo, 'recurring', $billId, $userId, $reason);
    }
}