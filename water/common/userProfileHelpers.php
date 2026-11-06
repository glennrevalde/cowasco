<?php
/**
 * common/userProfileHelpers.php
 *
 * The former operator/profile helpers, answered by the cooperative user accounts. Operators are users, profiles
 * are roles, the cashier's cash account is users.coop_cash_account_id (System Administration > User Accounts).
 * The function names are kept for the water pages that still call them.
 */

require_once __DIR__ . '/../database/config.php';

if (!function_exists('uph_table_exists')) {
    function uph_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (!array_key_exists($table, $cache)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            $cache[$table] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$table];
    }
}

if (!function_exists('uph_column_exists')) {
    function uph_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            $cache[$key] = (int)$stmt->fetchColumn() > 0;
        }
        return $cache[$key];
    }
}

if (!function_exists('uph_profiles_ready')) {
    function uph_profiles_ready(PDO $pdo): bool
    {
        return uph_table_exists($pdo, 'users') && uph_table_exists($pdo, 'roles');
    }
}

if (!function_exists('uph_account_label')) {
    function uph_account_label($accountId, $accountName = ''): string
    {
        $accountId = (int)$accountId;
        $accountName = trim((string)$accountName);
        if ($accountId <= 0) {
            return 'Not set';
        }
        return $accountName !== '' ? $accountId . ' - ' . $accountName : (string)$accountId;
    }
}

if (!function_exists('uph_fetch_cash_accounts')) {
    /** Bank / cash accounts a cashier may collect into */
    function uph_fetch_cash_accounts(PDO $pdo): array
    {
        return $pdo->query("SELECT id AS AccntID, CONCAT(code, ' - ', name) AS AccntName FROM accounts WHERE is_active = 1 AND account_type IN ('Bank', 'Other Current Asset') ORDER BY code")
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('uph_fetch_gl_accounts')) {
    function uph_fetch_gl_accounts(PDO $pdo): array
    {
        return $pdo->query("SELECT id AS AccntID, CONCAT(code, ' - ', name) AS AccntName, account_type AS AccntType FROM accounts WHERE is_active = 1 ORDER BY code")
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('uph_fetch_operators')) {
    /** The user accounts in the shape of the former operator rows */
    function uph_fetch_operators(PDO $pdo, bool $activeOnly = true): array
    {
        $sql = "SELECT u.id AS pkey, u.username AS name, COALESCE(u.full_name, u.username) AS description, 0 AS accesslevel, NULL AS relatedkey,
                       u.is_platform AS isSystem, IF(u.status = 'active', 0, 1) AS deleted
                  FROM users u WHERE u.is_platform = 0" . ($activeOnly ? " AND u.status = 'active'" : '') . ' ORDER BY u.username';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('uph_fetch_profiles')) {
    /** The roles in the shape of the former profile rows */
    function uph_fetch_profiles(PDO $pdo, bool $activeOnly = true): array
    {
        return $pdo->query('SELECT r.id AS profID, r.name, 0 AS typeid, 0 AS groupid, NULL AS cashGL, r.is_admin AS issystem, 0 AS deleted FROM roles r ORDER BY r.name')
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('uph_fetch_user_profiles')) {
    function uph_fetch_user_profiles(PDO $pdo, int $operatorId): array
    {
        return uph_fetch_operator_profiles($pdo, $operatorId);
    }
}

if (!function_exists('uph_fetch_operator_profiles')) {
    /** The roles of a user, each with the user's cash account (one cash account per user now) */
    function uph_fetch_operator_profiles(PDO $pdo, int $operatorId): array
    {
        $stmt = $pdo->prepare('SELECT u.id AS operator_profile_id, u.id AS operatorID, r.id AS profID, r.name AS profile_name, LOWER(r.name) AS profile_key,
                                      u.coop_cash_account_id AS cashGL, a.name AS cash_account_name, r.is_admin AS is_super_admin, 1 AS is_active
                                 FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                                 LEFT JOIN accounts a ON a.id = u.coop_cash_account_id
                                WHERE u.id = ? ORDER BY r.name');
        $stmt->execute([$operatorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('uph_fetch_profile_for_user')) {
    function uph_fetch_profile_for_user(PDO $pdo, int $operatorId, int $profileId): ?array
    {
        foreach (uph_fetch_operator_profiles($pdo, $operatorId) as $p) {
            if ((int)$p['profID'] === $profileId) {
                return $p;
            }
        }
        return null;
    }
}

if (!function_exists('uph_fetch_operator_profile_assignment')) {
    /** The former operator/profile assignment: the user itself (its id stands for the assignment) */
    function uph_fetch_operator_profile_assignment(PDO $pdo, int $operatorProfileId, ?int $operatorId = null): ?array
    {
        $userId = $operatorId ?: $operatorProfileId;
        $stmt = $pdo->prepare('SELECT u.id AS operator_profile_id, u.id AS operatorID, u.username AS operator_name, COALESCE(u.full_name, u.username) AS operator_description,
                                      u.coop_cash_account_id AS cashGL, a.name AS cash_account_name, IF(u.status = \'active\', 1, 0) AS is_active
                                 FROM users u LEFT JOIN accounts a ON a.id = u.coop_cash_account_id WHERE u.id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['profID'] = 0;
        $row['profile_name'] = implode(', ', Access::roleNames((int)$row['operatorID'])) ?: 'Operator';
        $row['profile_key'] = 'user';
        $row['is_super_admin'] = Access::isAdmin((int)$row['operatorID']) ? 1 : 0;
        return $row;
    }
}

if (!function_exists('uph_fetch_operator_profile_assignments')) {
    function uph_fetch_operator_profile_assignments(PDO $pdo, bool $activeOnly = true): array
    {
        $out = [];
        foreach (uph_fetch_operators($pdo, $activeOnly) as $o) {
            $a = uph_fetch_operator_profile_assignment($pdo, (int)$o['pkey']);
            if ($a) {
                $out[] = $a;
            }
        }
        return $out;
    }
}

if (!function_exists('uph_is_admin_profile_name')) {
    function uph_is_admin_profile_name(string $profileName): bool
    {
        return in_array(strtolower(trim($profileName)), ['administrator', 'admin', 'super admin', 'superadmin'], true);
    }
}

if (!function_exists('uph_access_role_from_operator_profile')) {
    function uph_access_role_from_operator_profile(array $operator, array $profile): string
    {
        return !empty($profile['is_super_admin']) ? 'superadmin' : 'operator';
    }
}

if (!function_exists('uph_select_session_profile')) {
    function uph_select_session_profile(PDO $pdo, int $operatorId, int $operatorProfileId): ?array
    {
        water_sync_session();
        return uph_fetch_operator_profile_assignment($pdo, $operatorProfileId, $operatorId);
    }
}

if (!function_exists('uph_current_cash_account_id')) {
    /** The cash account the signed-in cashier collects into (users.coop_cash_account_id) */
    function uph_current_cash_account_id(PDO $pdo, ?int $operatorId = null): ?int
    {
        $operatorId = $operatorId ?: (int)(Auth::id() ?? 0);
        if ($operatorId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT coop_cash_account_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$operatorId]);
        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) {
            $id = (int)(WaterPosting::defaultAccount('water_cash') ?: 0);
        }
        $_SESSION['cash_account_id'] = $id;
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('uph_operator_password_matches')) {
    function uph_operator_password_matches(string $input, string $stored): bool
    {
        $stored = (string)$stored;
        if ($stored === '') {
            return false;
        }
        $info = password_get_info($stored);
        if (!empty($info['algo'])) {
            return password_verify($input, $stored);
        }
        return hash_equals($stored, $input);
    }
}

if (!function_exists('uph_find_operator_by_name')) {
    function uph_find_operator_by_name(PDO $pdo, string $name): ?array
    {
        $stmt = $pdo->prepare("SELECT id AS pkey, username AS name, password_hash AS password, 0 AS accesslevel, COALESCE(full_name, username) AS description,
                                      NULL AS relatedkey, is_platform AS isSystem, IF(status = 'active', 0, 1) AS deleted
                                 FROM users WHERE username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('uph_current_operator_id')) {
    function uph_current_operator_id(): int
    {
        return (int)(Auth::id() ?? 0);
    }
}

if (!function_exists('uph_current_operator_profile_id')) {
    function uph_current_operator_profile_id(): int
    {
        return (int)(Auth::id() ?? 0);
    }
}

if (!function_exists('uph_create_operator')) {
    function uph_create_operator(PDO $pdo, string $name, string $password, string $description = ''): int
    {
        throw new RuntimeException('User accounts are created on System Administration > User Accounts.');
    }
}
