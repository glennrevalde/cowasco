<?php
/**
 * Discount enrollment consistency helpers.
 *
 * A member may have:
 * - at most one active Water Billing Free Cubic Meter classification;
 * - at most one active Water Billing monetary (Percentage/Fixed) classification;
 * - at most one active Percentage/Fixed monetary classification across the member.
 *
 * Water Free Cubic Meter and one Water Billing monetary privilege are allowed
 * together. This file only validates functional enrollment combinations; it
 * does not change authentication or access-control behavior.
 */

if (!function_exists('deh_discount_slots')) {
    function deh_discount_slots(array $discount): array
    {
        if ((int)($discount['is_active'] ?? 0) !== 1) {
            return [];
        }

        $slots = [];
        $waterActive = (int)($discount['water_discount_active'] ?? 0) === 1;
        $waterMethod = strtolower(trim((string)($discount['water_discount_method'] ?? 'none')));
        if ($waterActive && $waterMethod === 'free_cubic') {
            $slots['water_free_cubic'] = 'Water Billing Free Cubic Meter';
        }

        $hasMonetaryPrivilege = $waterActive && in_array($waterMethod, ['percent', 'fixed'], true);
        foreach (['one_time', 'installment', 'recurring'] as $prefix) {
            $active = (int)($discount[$prefix . '_discount_active'] ?? 0) === 1;
            $method = strtolower(trim((string)($discount[$prefix . '_discount_method'] ?? 'none')));
            if ($active && in_array($method, ['percent', 'fixed'], true)) {
                $hasMonetaryPrivilege = true;
            }
        }
        if ($hasMonetaryPrivilege) {
            $slots['monetary'] = 'Percentage/Fixed monetary privilege';
        }

        return $slots;
    }
}

if (!function_exists('deh_fetch_discount_rows')) {
    function deh_fetch_discount_rows(PDO $pdo, array $discountIds): array
    {
        $discountIds = array_values(array_unique(array_filter(array_map('intval', $discountIds), static fn(int $id): bool => $id > 0)));
        if (!$discountIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($discountIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM discounts WHERE discount_id IN ({$placeholders})");
        $stmt->execute($discountIds);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['discount_id']] = $row;
        }
        return $rows;
    }
}

if (!function_exists('deh_member_assignment_ids')) {
    function deh_member_assignment_ids(PDO $pdo, int $memberId, ?int $excludeDmId = null, bool $forUpdate = false): array
    {
        $sql = 'SELECT dm_id, discount_id FROM discounted_members WHERE member_id = ?';
        $params = [$memberId];
        if ($excludeDmId !== null && $excludeDmId > 0) {
            $sql .= ' AND dm_id <> ?';
            $params[] = $excludeDmId;
        }
        $sql .= ' ORDER BY dm_id ASC';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 1));
    }
}

if (!function_exists('deh_assert_discount_rows_compatible')) {
    function deh_assert_discount_rows_compatible(array $discountRows): void
    {
        $slotOwners = [];
        foreach ($discountRows as $row) {
            $discountId = (int)($row['discount_id'] ?? 0);
            if ($discountId <= 0) {
                continue;
            }
            $name = trim((string)($row['discount_type'] ?? 'Discount #' . $discountId));
            foreach (deh_discount_slots($row) as $slot => $label) {
                if (!isset($slotOwners[$slot])) {
                    $slotOwners[$slot] = ['id' => $discountId, 'name' => $name, 'label' => $label];
                    continue;
                }
                if ((int)$slotOwners[$slot]['id'] === $discountId) {
                    continue;
                }

                $first = (string)$slotOwners[$slot]['name'];
                throw new RuntimeException(
                    "Discount enrollment conflict: \"{$first}\" and \"{$name}\" both provide {$label}. " .
                    'Only one active discount may occupy that privilege slot. Water Free Cubic Meter may be combined with one Water Billing Percentage/Fixed privilege.'
                );
            }
        }
    }
}

if (!function_exists('deh_assert_member_assignments')) {
    function deh_assert_member_assignments(
        PDO $pdo,
        int $memberId,
        array $additionalDiscountIds = [],
        ?int $excludeDmId = null,
        bool $forUpdate = false,
        array $discountOverrides = []
    ): void {
        if ($memberId <= 0) {
            throw new RuntimeException('Invalid member for discount enrollment validation.');
        }

        $ids = deh_member_assignment_ids($pdo, $memberId, $excludeDmId, $forUpdate);
        foreach ($additionalDiscountIds as $id) {
            $id = (int)$id;
            if ($id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));

        $rows = deh_fetch_discount_rows($pdo, $ids);
        foreach ($discountOverrides as $discountId => $row) {
            $discountId = (int)$discountId;
            if ($discountId > 0 && in_array($discountId, $ids, true)) {
                $row['discount_id'] = $discountId;
                $rows[$discountId] = $row;
            }
        }

        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                throw new RuntimeException('A selected discount classification no longer exists.');
            }
        }

        deh_assert_discount_rows_compatible(array_values($rows));
    }
}

if (!function_exists('deh_assert_discount_configuration_compatible')) {
    function deh_assert_discount_configuration_compatible(PDO $pdo, int $discountId, array $newConfiguration): void
    {
        if ($discountId <= 0) {
            throw new RuntimeException('Invalid discount classification.');
        }

        $newConfiguration['discount_id'] = $discountId;
        $stmt = $pdo->prepare('SELECT member_id FROM discounted_members WHERE discount_id = ? ORDER BY member_id ASC');
        $stmt->execute([$discountId]);
        $memberIds = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));

        foreach ($memberIds as $memberId) {
            deh_assert_member_assignments($pdo, $memberId, [], null, false, [$discountId => $newConfiguration]);
        }
    }
}
