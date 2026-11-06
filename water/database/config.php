<?php
/**
 * Water-billing compatibility bootstrap.
 *
 * Every water page and process endpoint starts here (they require '../database/config.php'). In the stand-alone
 * COWASCO Waters program this file opened its own PDO connection and session; in the integrated system it loads the
 * cooperative application (app/bootstrap.php) and exposes the same function names on top of it, so the water
 * code keeps running on ONE database connection, ONE session, ONE login and ONE access model:
 *
 *   getDB()            the shared PDO of the company database (DB::pdo())
 *   isLoggedIn()       the cooperative login (Auth::user())
 *   $_SESSION[...]     user_id, operator_id, username, full_name, cash_account_id, is_super_admin, role:
 *                      filled from the logged-in cooperative user on every request (never persisted)
 *   csrfToken()        the cooperative CSRF token, verifyCsrf() checks it
 *   redirect()/flash() shared with the accounting pages (app/helpers.php)
 *
 * Nothing here opens a second connection or a second session.
 */

if (!defined('WATER_ROOT')) {
    define('WATER_ROOT', dirname(__DIR__));
}
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!defined('APP_NAME')) {
    define('APP_NAME', app_name());
}
if (!defined('WATER_MODULE_NAME')) {
    define('WATER_MODULE_NAME', 'Water Billing');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', DB::databaseName());
}
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 2592000);
}
if (!defined('REMEMBER_ME_DAYS')) {
    define('REMEMBER_ME_DAYS', 30);
}
if (!defined('PASSWORD_RESET_TTL')) {
    define('PASSWORD_RESET_TTL', 3600);
}

// ==============================================
//  Database: the one shared connection
// ==============================================

if (!function_exists('getDB')) {
    function getDB(): PDO
    {
        return DB::pdo();
    }
}

// ==============================================
//  Session bridge: the cooperative user seen the way the water code expects it
// ==============================================

if (!function_exists('water_sync_session')) {
    /**
     * Copies the logged-in cooperative user into the session keys the water code reads. Called on every request;
     * the keys are derived, so a change of rights or cash account is picked up at once.
     */
    function water_sync_session(): void
    {
        if (PHP_SAPI === 'cli' && !empty($_SESSION['user_id']) && empty($_SESSION['full_name'])) {
            // command-line tools (tests, recurring billing) act as a user through Auth::actAs()
        }
        $u = null;
        try {
            $u = DB::isBlocked() ? null : Auth::user();
        } catch (Throwable $e) {
            $u = null;
        }
        if (!$u) {
            foreach (['operator_id', 'username', 'operator_name', 'full_name', 'email', 'operator_accesslevel', 'cash_account_id',
                      'cash_account_name', 'is_super_admin', 'role', 'role_name', 'active_profile_name', 'operator_profile_id',
                      'active_operator_profile_id', 'active_profile_id', 'active_profile_key'] as $k) {
                unset($_SESSION[$k]);
            }
            return;
        }
        $isAdmin = Access::isAdmin((int)$u['id']);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['operator_id'] = (int)$u['id'];
        $_SESSION['username'] = (string)$u['username'];
        $_SESSION['operator_name'] = (string)$u['username'];
        $_SESSION['full_name'] = trim((string)($u['full_name'] ?? '')) !== '' ? (string)$u['full_name'] : (string)$u['username'];
        $_SESSION['email'] = (string)($u['email'] ?? '');
        $_SESSION['operator_accesslevel'] = $isAdmin ? 5 : 0;
        $_SESSION['cash_account_id'] = (int)($u['coop_cash_account_id'] ?? 0);
        $_SESSION['cash_account_name'] = '';
        $_SESSION['is_super_admin'] = $isAdmin ? 1 : 0;
        $_SESSION['role'] = $isAdmin ? 'superadmin' : 'operator';
        $roles = Access::roleNames((int)$u['id']);
        $_SESSION['role_name'] = $roles ? implode(', ', $roles) : ($isAdmin ? 'Administrator' : 'Operator');
        $_SESSION['active_profile_name'] = $_SESSION['role_name'];
        // the former operator/profile assignment: the user id stands in for both, so legacy checks keep passing
        $_SESSION['operator_profile_id'] = (int)$u['id'];
        $_SESSION['active_operator_profile_id'] = (int)$u['id'];
        $_SESSION['active_profile_id'] = (int)$u['id'];
        $_SESSION['active_profile_key'] = 'user';
        $_SESSION['last_activity_at'] = time();
    }
}

// the water code was written for MySQL 5.5's default (non-strict) SQL mode: implicit defaults, lenient dates
DB::relaxSqlMode();

if (PHP_SAPI !== 'cli') {
    water_sync_session();
    // a water action (a POST: saving, posting, each chunk of a long import) is activity, as a page opened is: the
    // inactivity time-out counts from it, so a user who ran a 40-minute import is not logged out at the next click.
    // A session already past the time-out is left to end at the next page.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_SESSION['operator_id'])) {
        $idleLimit = max(1, Settings::int('session_timeout_minutes')) * 60;
        if (!isset($_SESSION['last_activity']) || time() - (int)$_SESSION['last_activity'] <= $idleLimit) {
            $_SESSION['last_activity'] = time();
        }
    }
    // ONE CSRF token for the whole system: the water code reads $_SESSION['csrf_token'], the accounting pages
    // $_SESSION['csrf'] (csrf_token()). Both names carry the same value, so a token issued by either page is
    // accepted by both sets of endpoints, and verifyCsrf() / verify_csrf() check the same secret.
    $_SESSION['csrf_token'] = csrf_token();
}

// ==============================================
//  Helpers kept with their original names
// ==============================================

if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool
    {
        try {
            return !DB::isBlocked() && Auth::user() !== null;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('water_login_url')) {
    function water_login_url(): string
    {
        return base_url_water() . '/login.php';
    }
}

if (!function_exists('base_url_water')) {
    /** The URL of the program folder (where index.php and login.php are), from a page inside water/views or water/process */
    function base_url_water(): string
    {
        static $base = null;
        if ($base === null) {
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
            $pos = strpos($script, '/water/');
            $base = $pos !== false ? substr($script, 0, $pos) : rtrim(dirname($script), '/');
        }
        return $base;
    }
}

if (!function_exists('water_page_url')) {
    /** Address of a water page by its key ("dashboard", "water_bills_collection", ...) */
    function water_page_url(string $pageKey, array $params = []): string
    {
        $url = base_url_water() . '/water/views/' . basename($pageKey, '.php') . '.php';
        $q = array_filter($params, fn($v) => $v !== null && $v !== '');
        return $q ? $url . '?' . http_build_query($q) : $url;
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin(): void
    {
        if (!isLoggedIn()) {
            header('Location: ' . water_login_url());
            exit;
        }
        // the cooperative session timeout, forced password change and expiry apply to the water pages too
        Auth::requireLogin('water');
    }
}

if (!function_exists('requireGuest')) {
    function requireGuest(): void
    {
        if (isLoggedIn()) {
            header('Location: dashboard.php');
            exit;
        }
    }
}

if (!function_exists('e')) {
    function e($val = ''): string
    {
        return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        return csrf_token();
    }
}

if (!function_exists('verifyCsrf')) {
    function verifyCsrf(string $token): bool
    {
        return $token !== '' && hash_equals((string)($_SESSION['csrf'] ?? ''), $token);
    }
}

if (!function_exists('configuredSessionInactivityHours')) {
    function configuredSessionInactivityHours(): int
    {
        $minutes = max(1, Settings::int('session_timeout_minutes'));
        return max(1, (int)ceil($minutes / 60));
    }
}

if (!function_exists('sessionRequestWantsJson')) {
    function sessionRequestWantsJson(): bool
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        return $requestedWith === 'xmlhttprequest'
            || strpos($accept, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false;
    }
}

// Session timeout for AJAX endpoints: the accounting Auth::requireLogin() redirects, which an AJAX caller cannot
// follow; answer those with 401 JSON instead (the water pages already handle that answer).
if (PHP_SAPI !== 'cli' && !isLoggedIn() && sessionRequestWantsJson()
    && strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/water/process/') !== false) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['success' => false, 'error' => 'Your session has ended. Sign in again.']));
}
