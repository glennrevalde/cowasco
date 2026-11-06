<?php
/**
 * water/process/accessControlHelpers.php
 *
 * Page and endpoint authorization of the water-billing pages, on the ONE access model of the integrated system.
 *
 * The former program kept its own catalog (access_control_pages) and rights per operator/profile assignment. Those
 * are now the pages "water.<key>" of the registry (app/WaterPages.php), given to roles on Page Access Configuration
 * and User Role Configuration, with user-level overrides, exactly like every accounting and cooperative page.
 * The function names the water pages call are kept; every one of them is answered by app/Access.php.
 *
 *   requirePageAccess($pdo, $key)      a page: logged in, and the page is allowed (else the denied page + audit)
 *   requireProcessAccess($pdo, $key)   an endpoint: logged in, its page allowed; a state-changing request (POST)
 *                                      also needs the Create or Edit right on that page
 *   userCanAccessPage($pdo, $key)      presentation checks (links, cards)
 *   access_is_superadmin()             the Administrator role (or the Company Owner / Super Admin)
 */

require_once __DIR__ . '/../database/config.php';

if (!function_exists('access_current_page_key')) {
    function access_current_page_key(): string
    {
        return basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php');
    }
}

if (!function_exists('access_normalize_role')) {
    function access_normalize_role(?string $role = null): string
    {
        return strtolower(trim((string)($role ?? ($_SESSION['role'] ?? 'operator'))));
    }
}

if (!function_exists('access_is_public_page')) {
    function access_is_public_page(string $pageKey): bool
    {
        return in_array($pageKey, ['login', 'register', 'forgot_password', 'reset_password', 'account_setup_required'], true);
    }
}

if (!function_exists('access_is_shared_authenticated_page')) {
    function access_is_shared_authenticated_page(string $pageKey): bool
    {
        return in_array($pageKey, WaterPages::OPEN, true);
    }
}

if (!function_exists('access_current_operator_id')) {
    function access_current_operator_id(): int
    {
        return (int)(Auth::id() ?? 0);
    }
}

if (!function_exists('access_current_operator_profile_id')) {
    /** The former operator/profile assignment id; the user id stands in for it */
    function access_current_operator_profile_id(): int
    {
        return (int)(Auth::id() ?? 0);
    }
}

if (!function_exists('access_is_superadmin')) {
    function access_is_superadmin(?string $unusedRole = null): bool
    {
        if (!isLoggedIn()) {
            return false;
        }
        return Access::isAdmin();
    }
}

if (!function_exists('access_required_tables')) {
    function access_required_tables(): array
    {
        return ['pages', 'role_page_access', 'user_page_overrides'];
    }
}

if (!function_exists('access_missing_tables')) {
    function access_missing_tables(PDO $pdo): array
    {
        try {
            $required = access_required_tables();
            $placeholders = implode(',', array_fill(0, count($required), '?'));
            $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)");
            $stmt->execute($required);
            $found = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));
            return array_values(array_filter($required, fn($t) => !in_array(strtolower($t), $found, true)));
        } catch (Throwable $e) {
            return access_required_tables();
        }
    }
}

if (!function_exists('access_tables_exist')) {
    function access_tables_exist(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache === null) {
            $cache = count(access_missing_tables($pdo)) === 0;
        }
        return $cache;
    }
}

if (!function_exists('access_current_database_name')) {
    function access_current_database_name(PDO $pdo): string
    {
        try {
            return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e) {
            return 'unknown';
        }
    }
}

if (!function_exists('access_page_aliases')) {
    function access_page_aliases(): array
    {
        return WaterPages::ALIASES;
    }
}

if (!function_exists('access_resolve_key')) {
    /**
     * The registry key a water page key is checked under: its own "water.<key>" page, or, for a detail/print page
     * that is not in page access, the first owning page the user may open.
     */
    function access_resolve_key(string $pageKey): ?string
    {
        $pageKey = basename(trim($pageKey), '.php');
        if ($pageKey === '') {
            return null;
        }
        if (WaterPages::isRetired($pageKey)) {
            // a retired page is reachable by everyone: it only shows where the function moved to
            return '';
        }
        $full = WaterPages::accessKey($pageKey);
        if (Access::pageDef($full)) {
            if (Access::page($full)) {
                return $full;
            }
            foreach ((WaterPages::ALIASES[$pageKey] ?? []) as $parent) {
                $pk = WaterPages::accessKey($parent);
                if (Access::pageDef($pk) && Access::page($pk)) {
                    return $pk;
                }
            }
            return $full;   // registered but not allowed: the denial names this page
        }
        foreach ((WaterPages::ALIASES[$pageKey] ?? []) as $parent) {
            $pk = WaterPages::accessKey($parent);
            if (Access::pageDef($pk) && Access::page($pk)) {
                return $pk;
            }
        }
        return null;
    }
}

if (!function_exists('pageExistsInAccessControl')) {
    function pageExistsInAccessControl(PDO $pdo, string $pageKey): bool
    {
        return Access::pageDef(WaterPages::accessKey(basename($pageKey, '.php'))) !== null;
    }
}

if (!function_exists('access_operator_profile_has_any_assigned_access')) {
    function access_operator_profile_has_any_assigned_access(PDO $pdo, ?int $operatorProfileId = null): bool
    {
        if (!isLoggedIn()) {
            return false;
        }
        if (Access::isAdmin()) {
            return true;
        }
        foreach (Access::effective() as $k => $e) {
            if (!empty($e['allowed'])) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('access_user_has_any_assigned_access')) {
    function access_user_has_any_assigned_access(PDO $pdo, ?int $unused = null): bool
    {
        return access_operator_profile_has_any_assigned_access($pdo);
    }
}

if (!function_exists('access_operator_profile_has_explicit_page_access')) {
    function access_operator_profile_has_explicit_page_access(PDO $pdo, int $operatorProfileId, string $pageKey): ?bool
    {
        $full = WaterPages::accessKey(basename($pageKey, '.php'));
        if (!Access::pageDef($full)) {
            return null;
        }
        return Access::page($full);
    }
}

if (!function_exists('access_user_has_explicit_page_access')) {
    function access_user_has_explicit_page_access(PDO $pdo, int $unused, string $pageKey): ?bool
    {
        return access_operator_profile_has_explicit_page_access($pdo, 0, $pageKey);
    }
}

if (!function_exists('access_default_landing_page')) {
    function access_default_landing_page(PDO $pdo, ?int $unused = null, ?string $role = null): string
    {
        if (!isLoggedIn()) {
            return water_login_url();
        }
        if (userCanAccessPage($pdo, 'dashboard')) {
            return 'dashboard.php';
        }
        foreach (WaterPages::PAGES as $key => $def) {
            if ($def[2] && Access::page(WaterPages::accessKey($key))) {
                return $key . '.php';
            }
        }
        // no water page: the accounting home
        return base_url() . '/index.php?p=home.dashboard';
    }
}

if (!function_exists('access_redirect')) {
    function access_redirect(string $target): void
    {
        if (!headers_sent()) {
            header('Location: ' . $target);
            exit;
        }
        echo '<script>window.location.href=' . json_encode($target) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        exit;
    }
}

if (!function_exists('access_deny_request')) {
    function access_deny_request(string $message = 'Access denied.'): void
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $isAjax = $xrw === 'xmlhttprequest' || strpos($accept, 'application/json') !== false
            || !empty($GLOBALS['__business_guard_force_json']);
        if (isLoggedIn()) {
            Audit::log('access_denied', 'water', 'page', null, access_current_page_key() . ' (' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ')', ['page_key' => 'water.' . access_current_page_key()]);
        }
        if ($isAjax) {
            http_response_code(isLoggedIn() ? 403 : 401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $message]);
            exit;
        }
        $_SESSION['error_msg'] = $message;
        access_redirect(isLoggedIn() ? '../views/account_setup_required.php' : water_login_url());
    }
}

if (!function_exists('access_referrer_page_key')) {
    function access_referrer_page_key(): string
    {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        $path = parse_url($ref, PHP_URL_PATH);
        return $path ? basename((string)$path, '.php') : '';
    }
}

if (!function_exists('userCanAccessPage')) {
    function userCanAccessPage(PDO $pdo, string $pageKey, ?int $unused = null, ?string $role = null): bool
    {
        $pageKey = basename(trim($pageKey), '.php');
        if ($pageKey === '' || access_is_public_page($pageKey)) {
            return true;
        }
        if (!isLoggedIn()) {
            return false;
        }
        if (access_is_shared_authenticated_page($pageKey) || WaterPages::isRetired($pageKey)) {
            return true;
        }
        // an accounting page key ("rpt.trial_balance") is answered by the registry directly
        if (strpos($pageKey, '.') !== false) {
            return Access::page($pageKey);
        }
        $key = access_resolve_key($pageKey);
        return $key !== null && ($key === '' || Access::page($key));
    }
}

if (!function_exists('access_require_current_database')) {
    /**
     * The program files are newer than the database (an upgrade script not run yet): the water pages stop here as the
     * other pages do, and the home page names the script to run, instead of failing half-way through a bill.
     */
    function access_require_current_database(bool $request): void
    {
        if (PHP_SAPI === 'cli' || !Schema::pending()) {
            return;
        }
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($request && ($xrw === 'xmlhttprequest' || strpos($accept, 'application/json') !== false)) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'The database needs an update before work can continue: open the home page of the program for the file to run.']);
            exit;
        }
        access_redirect(base_url() . '/index.php');
    }
}

if (!function_exists('requirePageAccess')) {
    function requirePageAccess(PDO $pdo, ?string $pageKey = null): void
    {
        $pageKey = basename((string)($pageKey ?: access_current_page_key()), '.php');
        if (access_is_public_page($pageKey)) {
            return;
        }
        if (!isLoggedIn()) {
            access_redirect(water_login_url());
        }
        Auth::requireLogin('water.' . $pageKey);
        access_require_current_database(false);
        if (access_is_shared_authenticated_page($pageKey) || WaterPages::isRetired($pageKey)) {
            return;
        }
        $key = access_resolve_key($pageKey);
        if ($key === null) {
            Access::denyPage(WaterPages::accessKey($pageKey));
        }
        if ($key !== '' && !Access::page($key)) {
            Access::denyPage($key);
        }
    }
}

if (!function_exists('access_process_page_key')) {
    function access_process_page_key(?string $scriptName = null): string
    {
        $scriptName = $scriptName ?: basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), '.php');
        return WaterPages::processPage($scriptName);
    }
}

if (!function_exists('requireProcessAccess')) {
    function requireProcessAccess(PDO $pdo, ?string $relatedPageKey = null): void
    {
        if (!isLoggedIn()) {
            access_deny_request('Please sign in before performing this action.');
        }
        access_require_current_database(true);
        $relatedPageKey = basename(trim((string)($relatedPageKey ?: access_process_page_key())), '.php');
        if ($relatedPageKey === '' || access_is_public_page($relatedPageKey)) {
            access_deny_request('Access denied. This server action is not mapped to an authorized module.');
        }
        if (access_is_shared_authenticated_page($relatedPageKey)) {
            return;
        }
        $key = access_resolve_key($relatedPageKey);
        if ($key === null || ($key !== '' && !Access::page($key))) {
            access_deny_request('Access denied. Your account does not have permission to perform actions from this page.');
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($key !== '' && $method !== 'GET' && $method !== 'HEAD' && !Access::can($key, 'create') && !Access::can($key, 'edit')) {
            access_deny_request('Access denied. Your role may open this page but may not create or change its records.');
        }
    }
}

if (!function_exists('access_page_catalog')) {
    /** The water pages indexed by key, in the shape the former catalog had */
    function access_page_catalog(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        $sort = 0;
        foreach (WaterPages::PAGES as $key => [$name, $group, $menu, $desc]) {
            $cache[$key] = ['page_key' => $key, 'file_name' => $key . '.php', 'label' => $name, 'module_group' => $group,
                'description' => $desc, 'sort_order' => ++$sort, 'is_active' => 1];
        }
        return $cache;
    }
}

if (!function_exists('access_allowed_page_keys')) {
    function access_allowed_page_keys(PDO $pdo): array
    {
        $keys = [];
        foreach (WaterPages::PAGES as $key => $def) {
            if (userCanAccessPage($pdo, $key)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }
}

if (!function_exists('access_allowed_page_files')) {
    function access_allowed_page_files(PDO $pdo): array
    {
        $files = [];
        foreach (access_allowed_page_keys($pdo) as $key) {
            $files[] = $key . '.php';
        }
        foreach (array_keys(WaterPages::RETIRED) as $key) {
            $files[] = $key . '.php';
        }
        return array_values(array_unique($files));
    }
}

if (!function_exists('access_visibility_payload')) {
    /** Presentation only: which water page files the browser may link to (the server checks stay the boundary) */
    function access_visibility_payload(PDO $pdo): array
    {
        $protected = [];
        foreach (array_keys(WaterPages::PAGES) as $key) {
            $protected[] = $key . '.php';
        }
        return [
            'superAdmin' => access_is_superadmin(),
            'protectedFiles' => $protected,
            'allowedFiles' => access_allowed_page_files($pdo),
        ];
    }
}

if (!function_exists('filterNavItemsByAccess')) {
    function filterNavItemsByAccess(PDO $pdo, array $navItems): array
    {
        if (access_is_superadmin()) {
            return $navItems;
        }
        $filtered = [];
        foreach ($navItems as $item) {
            $allowedKeys = [];
            foreach (($item['key'] ?? []) as $key) {
                if (userCanAccessPage($pdo, (string)$key)) {
                    $allowedKeys[] = (string)$key;
                }
            }
            if (!$allowedKeys) {
                continue;
            }
            $currentHrefKey = pathinfo(basename((string)parse_url((string)($item['href'] ?? ''), PHP_URL_PATH)), PATHINFO_FILENAME);
            if ($currentHrefKey === '' || !userCanAccessPage($pdo, $currentHrefKey)) {
                $item['href'] = $allowedKeys[0] . '.php';
            }
            $item['allowed_keys'] = $allowedKeys;
            $filtered[] = $item;
        }
        return $filtered;
    }
}

if (!function_exists('fetchAccessControlPagesGrouped')) {
    function fetchAccessControlPagesGrouped(PDO $pdo): array
    {
        $grouped = [];
        foreach (access_page_catalog($pdo) as $row) {
            $grouped[$row['module_group']][] = $row;
        }
        return $grouped;
    }
}

if (!function_exists('access_preset_tables_exist')) {
    function access_preset_tables_exist(PDO $pdo): bool
    {
        return false;
    }
}

if (!function_exists('fetchAccessControlPresets')) {
    function fetchAccessControlPresets(PDO $pdo): array
    {
        return [];
    }
}
