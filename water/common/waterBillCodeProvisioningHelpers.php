<?php
/**
 * Self-healing Water Bill bill-code provisioning.
 *
 * The Water Billing module requires one dedicated bill code. When the legacy or
 * newly installed database does not yet contain WATER BILL, this helper creates
 * it with safe defaults, enables member discounts, and stores it as the global
 * Water Billing bill code.
 */

if (!function_exists('wbc_table_exists')) {
    function wbc_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('wbc_column_exists')) {
    function wbc_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('wbc_account_exists')) {
    function wbc_account_exists(PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0 || !wbc_table_exists($pdo, 'chartofaccntstbl')) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT 1 FROM chartofaccntstbl WHERE AccntID = ? LIMIT 1');
        $stmt->execute([$accountId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('wbc_setting_value')) {
    function wbc_setting_value(PDO $pdo, string $key): ?string
    {
        if (!wbc_table_exists($pdo, 'water_billing_settings')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT setting_value FROM water_billing_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return ($value === false || $value === null || trim((string)$value) === '') ? null : (string)$value;
    }
}

if (!function_exists('wbc_current_operator_id')) {
    function wbc_current_operator_id(PDO $pdo, ?int $operatorId = null): int
    {
        $candidate = (int)($operatorId ?? ($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0));
        if ($candidate > 0) {
            $stmt = $pdo->prepare('SELECT 1 FROM operator WHERE pkey = ? LIMIT 1');
            $stmt->execute([$candidate]);
            if ($stmt->fetchColumn()) {
                return $candidate;
            }
        }

        $stmt = $pdo->query('SELECT pkey FROM operator WHERE COALESCE(deleted, 0) = 0 ORDER BY isSystem ASC, pkey ASC LIMIT 1');
        $fallback = (int)($stmt ? $stmt->fetchColumn() : 0);
        if ($fallback <= 0) {
            throw new RuntimeException('No valid operator exists to own the automatically created Water Bill bill code.');
        }
        return $fallback;
    }
}

if (!function_exists('wbc_find_named_account')) {
    function wbc_find_named_account(PDO $pdo, array $patterns): ?int
    {
        if (!wbc_table_exists($pdo, 'chartofaccntstbl')) {
            return null;
        }

        foreach ($patterns as $pattern) {
            $stmt = $pdo->prepare(
                "SELECT AccntID
                 FROM chartofaccntstbl
                 WHERE COALESCE(Status, 'A') = 'A'
                   AND LOWER(TRIM(AccntName)) LIKE ?
                 ORDER BY CASE WHEN COALESCE(WithSL, 0) = 1 THEN 0 ELSE 1 END, AccntID ASC
                 LIMIT 1"
            );
            $stmt->execute([$pattern]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }
        return null;
    }
}

if (!function_exists('wbc_resolve_default_accounts')) {
    /**
     * Resolve the default debit (A/R) and credit (income) accounts.
     *
     * Priority:
     * 1. Accounts from the currently configured Water Billing bill code.
     * 2. Explicit default-account settings.
     * 3. Best matching active accounts by name (water receivable / water income first).
     */
    function wbc_resolve_default_accounts(PDO $pdo): array
    {
        $arId = null;
        $incomeId = null;

        $configuredId = (int)(wbc_setting_value($pdo, 'water_billing_bill_code_id') ?? 0);
        if ($configuredId > 0 && wbc_table_exists($pdo, 'bill_codes')) {
            $stmt = $pdo->prepare('SELECT ar_account, income_account FROM bill_codes WHERE code_id = ? LIMIT 1');
            $stmt->execute([$configuredId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (wbc_account_exists($pdo, (int)($row['ar_account'] ?? 0))) {
                $arId = (int)$row['ar_account'];
            }
            if (wbc_account_exists($pdo, (int)($row['income_account'] ?? 0))) {
                $incomeId = (int)$row['income_account'];
            }
        }

        if (!$arId) {
            $configuredAr = (int)(wbc_setting_value($pdo, 'water_billing_default_ar_account_id') ?? 0);
            if (wbc_account_exists($pdo, $configuredAr)) {
                $arId = $configuredAr;
            }
        }
        if (!$incomeId) {
            $configuredIncome = (int)(wbc_setting_value($pdo, 'water_billing_default_income_account_id') ?? 0);
            if (wbc_account_exists($pdo, $configuredIncome)) {
                $incomeId = $configuredIncome;
            }
        }

        if (!$arId) {
            $arId = wbc_find_named_account($pdo, [
                '%water%receivable%',
                '%receivable%water%',
            ]);
        }
        if (!$incomeId) {
            $incomeId = wbc_find_named_account($pdo, [
                '%water%income%',
                '%water%revenue%',
                '%water%service%',
            ]);
        }

        // The former program fell back to its own account numbers (11785, 40128) here. Account ids of the
        // cooperative chart of accounts carry no such meaning, so the search goes on by account name only; a wrong
        // pick is caught before anything posts (WaterPosting checks the A/R and income account types).

        if (!$arId) {
            $arId = wbc_find_named_account($pdo, ['%accounts receivable%']);
        }
        if (!$incomeId) {
            $incomeId = wbc_find_named_account($pdo, [
                '%service fee%',
                '%service income%',
                '%revenue%',
                '%income%',
            ]);
        }

        if (!$arId || !$incomeId) {
            throw new RuntimeException(
                'Water Bill could not be created because a default Accounts Receivable or Income account was not found. '
                . 'Create those accounts in Chart of Accounts, then reopen Water Rates.'
            );
        }

        return ['ar_account' => (int)$arId, 'income_account' => (int)$incomeId];
    }
}

if (!function_exists('wbc_upsert_setting')) {
    function wbc_upsert_setting(PDO $pdo, string $key, string $value, string $description): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO water_billing_settings
                (setting_key, setting_value, description, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                description = VALUES(description),
                updated_at = NOW()"
        );
        $stmt->execute([$key, $value, $description]);
    }
}

if (!function_exists('water_bill_code_ensure')) {
    /**
     * Ensure the WATER BILL bill code exists and is the global Water Billing code.
     *
     * Existing non-null account mappings are preserved. Missing mappings receive
     * defaults. Sales price is always zero and member discounts are always enabled.
     */
    function water_bill_code_ensure(PDO $pdo, ?int $operatorId = null): array
    {
        foreach (['bill_codes', 'water_billing_settings', 'chartofaccntstbl', 'operator'] as $requiredTable) {
            if (!wbc_table_exists($pdo, $requiredTable)) {
                throw new RuntimeException("Required table '{$requiredTable}' is missing.");
            }
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->query(
                "SELECT code_id, code, description, type, ar_account, income_account,
                        sales_price, allow_member_discount
                 FROM bill_codes
                 WHERE UPPER(TRIM(code)) = 'WATER BILL'
                 ORDER BY code_id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $billCode = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $defaults = wbc_resolve_default_accounts($pdo);
            $operatorId = wbc_current_operator_id($pdo, $operatorId);
            $created = false;
            $updated = false;

            if (!$billCode) {
                $insert = $pdo->prepare(
                    "INSERT INTO bill_codes
                        (code, description, type, ar_account, income_account,
                         cogs_account, asset_account, cost_price, sales_price,
                         allow_member_discount, modified_by, created_at, updated_at)
                     VALUES
                        ('WATER BILL', 'Water Bill', 'Service', ?, ?,
                         NULL, NULL, 0.00, 0.00, 1, ?, NOW(), NOW())"
                );
                $insert->execute([
                    $defaults['ar_account'],
                    $defaults['income_account'],
                    $operatorId,
                ]);
                $billCodeId = (int)$pdo->lastInsertId();
                $created = true;
            } else {
                $billCodeId = (int)$billCode['code_id'];
                $arId = wbc_account_exists($pdo, (int)($billCode['ar_account'] ?? 0))
                    ? (int)$billCode['ar_account']
                    : $defaults['ar_account'];
                $incomeId = wbc_account_exists($pdo, (int)($billCode['income_account'] ?? 0))
                    ? (int)$billCode['income_account']
                    : $defaults['income_account'];

                $updated = (string)$billCode['type'] !== 'Service'
                    || (int)($billCode['ar_account'] ?? 0) !== $arId
                    || (int)($billCode['income_account'] ?? 0) !== $incomeId
                    || abs((float)($billCode['sales_price'] ?? 0)) > 0.00001
                    || (int)($billCode['allow_member_discount'] ?? 0) !== 1;

                if ($updated) {
                    $update = $pdo->prepare(
                        "UPDATE bill_codes
                         SET code = 'WATER BILL',
                             description = CASE WHEN TRIM(COALESCE(description, '')) = '' THEN 'Water Bill' ELSE description END,
                             type = 'Service',
                             ar_account = ?,
                             income_account = ?,
                             cogs_account = NULL,
                             asset_account = NULL,
                             cost_price = 0.00,
                             sales_price = 0.00,
                             allow_member_discount = 1,
                             modified_by = ?,
                             updated_at = NOW()
                         WHERE code_id = ?"
                    );
                    $update->execute([$arId, $incomeId, $operatorId, $billCodeId]);
                }
            }

            wbc_upsert_setting(
                $pdo,
                'water_billing_bill_code_id',
                (string)$billCodeId,
                'Automatically maintained bill code used by Water Billing transactions.'
            );
            wbc_upsert_setting(
                $pdo,
                'water_billing_default_ar_account_id',
                (string)$defaults['ar_account'],
                'Default debit / Accounts Receivable account for an automatically created Water Bill bill code.'
            );
            wbc_upsert_setting(
                $pdo,
                'water_billing_default_income_account_id',
                (string)$defaults['income_account'],
                'Default credit / Income account for an automatically created Water Bill bill code.'
            );

            $stmt = $pdo->prepare(
                "SELECT bc.code_id, bc.code, bc.description, bc.type,
                        bc.ar_account, bc.income_account, bc.sales_price,
                        bc.allow_member_discount,
                        ar.AccntName AS ar_name, inc.AccntName AS income_name
                 FROM bill_codes bc
                 LEFT JOIN chartofaccntstbl ar ON ar.AccntID = bc.ar_account
                 LEFT JOIN chartofaccntstbl inc ON inc.AccntID = bc.income_account
                 WHERE bc.code_id = ? LIMIT 1"
            );
            $stmt->execute([$billCodeId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                throw new RuntimeException('Water Bill bill code could not be loaded after provisioning.');
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'bill_code' => $result,
                'created' => $created,
                'updated' => $updated,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
