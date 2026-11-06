<?php
/**
 * common/businessRequestGuard.php
 *
 * Request-method + CSRF guard for BUSINESS mutation endpoints only.
 *
 * This helper never touches the protected operator-login / authentication
 * files (database/config.php, accessControlHelpers.php, login/register/
 * forgot-password flows). It reads the session CSRF token that
 * database/config.php already issues through csrfToken()/verifyCsrf() and
 * applies endpoint-local validation:
 *
 *   - the request method must be one of the allowed methods (POST by default);
 *   - a CSRF token must be present in the POST body (csrf_token), the JSON body
 *     (csrf_token) or the X-CSRF-Token request header;
 *   - an EMPTY token is always rejected, even when the session has no token yet;
 *   - a permitted session with a missing/incorrect token never changes data.
 *
 * On failure the guard answers a JSON client with HTTP 403 (code csrf_invalid; 419 is unknown to Apache) + a structured
 * error, and a normal form post with a flash message and a redirect back to
 * the owning business page. It also hosts the FORCE CONFIGURATION check for
 * transactional actions (see common/setupReadinessHelpers.php) so that every
 * business mutation consumes the same readiness rules as the UI.
 */

require_once __DIR__ . '/../database/config.php';

if (!function_exists('business_request_wants_json')) {
    function business_request_wants_json(): bool
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') return true;
        if (strpos($contentType, 'application/json') !== false) return true;
        if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) return true;
        if ((string)($_POST['ajax'] ?? '') === '1') return true;
        return false;
    }
}

if (!function_exists('business_request_json_body')) {
    /** Decode a JSON request body once per request (empty array when not JSON). */
    function business_request_json_body(): array
    {
        static $decoded = null;
        if ($decoded !== null) return $decoded;
        $decoded = [];
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') === false) return $decoded;
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') return $decoded;
        $data = json_decode($raw, true);
        if (is_array($data)) $decoded = $data;
        return $decoded;
    }
}

if (!function_exists('business_request_csrf_token')) {
    /** Locate the token supplied by the caller: form field, JSON field or header. */
    function business_request_csrf_token(): string
    {
        $candidates = [
            (string)($_POST['csrf_token'] ?? ''),
            (string)(business_request_json_body()['csrf_token'] ?? ''),
            (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') return $candidate;
        }
        return '';
    }
}

if (!function_exists('business_ini_bytes')) {
    function business_ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return 0;
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        if ($unit === 'g') return (int)($number * 1073741824);
        if ($unit === 'm') return (int)($number * 1048576);
        if ($unit === 'k') return (int)($number * 1024);
        return (int)$number;
    }
}

if (!function_exists('business_csrf_is_valid')) {
    function business_csrf_is_valid(): bool
    {
        $token = business_request_csrf_token();
        if ($token === '') return false;
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        if ($sessionToken === '') return false;
        if (function_exists('verifyCsrf')) return verifyCsrf($token);
        return hash_equals($sessionToken, $token);
    }
}

if (!function_exists('business_guard_fail')) {
    /**
     * Stop the request without changing data.
     *
     * @param string $fallbackView  Business page to return a browser form to (relative to /process/).
     * @param string $message       Safe, public message.
     * @param int    $status        HTTP status for JSON clients.
     * @param array  $extra         Additional JSON fields (for example configuration_required details).
     */
    function business_guard_fail(string $fallbackView, string $message, int $status = 403, array $extra = []): void
    {
        $forceJson = !empty($GLOBALS['__business_guard_force_json']);
        if ($forceJson || business_request_wants_json()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(array_merge([
                'success' => false,
                'ok' => false,
                'error' => $message,
                'message' => $message,
            ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $_SESSION['error_msg'] = $message;
        // Pages read flash messages under different keys (msg / message / error).
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = ['msg' => $message, 'message' => $message, 'type' => 'error', 'error' => $message];
        } else {
            $_SESSION['flash']['error'] = $message;
            $_SESSION['flash']['msg'] = $message;
            $_SESSION['flash']['message'] = $message;
            $_SESSION['flash']['type'] = 'error';
        }

        $target = $fallbackView !== '' ? $fallbackView : '../views/dashboard.php';
        // Prefer returning to the same-origin business page the form came from.
        $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') {
            $refHost = (string)parse_url($referer, PHP_URL_HOST);
            $refPath = (string)parse_url($referer, PHP_URL_PATH);
            if (($refHost === '' || strcasecmp($refHost, (string)($_SERVER['HTTP_HOST'] ?? '')) === 0)
                && strpos($refPath, '/views/') !== false
                && !preg_match('#/views/(login|register|forgot_password|reset_password)\.php$#i', $refPath)) {
                $target = '../views/' . basename($refPath);
                $query = (string)parse_url($referer, PHP_URL_QUERY);
                if ($query !== '') $target .= '?' . $query;
            }
        }
        if (!headers_sent()) {
            header('Location: ' . $target);
        } else {
            echo '<script>window.location.href=' . json_encode($target) . ';</script>';
        }
        exit;
    }
}

if (!function_exists('business_require_mutation')) {
    /**
     * Enforce method + CSRF (+ optional configuration readiness) for a business
     * mutation endpoint.
     *
     * Options:
     *   methods   => ['POST']            allowed request methods
     *   action    => 'billing'           readiness action group (see setupReadinessHelpers)
     *   skip_csrf => false               never true for a mutation; kept for CLI helpers
 *   json      => false               endpoint always answers JSON (fetch/XHR callers)
     */
    function business_require_mutation(?PDO $pdo, string $fallbackView, array $options = []): void
    {
        if (PHP_SAPI === 'cli') return; // CLI runners (recurring billing) are not HTTP requests.
        $GLOBALS['__business_guard_force_json'] = !empty($options['json']);

        $methods = array_map('strtoupper', (array)($options['methods'] ?? ['POST']));
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, $methods, true)) {
            business_guard_fail($fallbackView, 'This action must be submitted as a form request (' . implode('/', $methods) . ').', 405);
        }

        if (empty($options['skip_csrf']) && !business_csrf_is_valid()) {
            // An oversized POST arrives empty (PHP drops the body), which would
            // otherwise look like a missing token. Say what actually happened.
            $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $postMax = business_ini_bytes((string)ini_get('post_max_size'));
            if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax && empty($_POST) && empty($_FILES)) {
                business_guard_fail($fallbackView, 'The submitted data (' . round($contentLength / 1048576, 1) . ' MB) exceeds the server limit post_max_size = ' . ini_get('post_max_size') . '. Nothing was received; raise post_max_size/upload_max_filesize in php.ini or split the file.', 413, ['code' => 'post_too_large']);
            }
            $reason = business_request_csrf_token() === ''
                ? 'Your request is missing its security token. Reload the page and try again.'
                : 'Your form session expired or the security token did not match. Reload the page and try again.';
            business_guard_fail($fallbackView, $reason, 403, ['code' => 'csrf_invalid']);
        }

        $action = trim((string)($options['action'] ?? ''));
        if ($action !== '' && $pdo instanceof PDO) {
            $helper = __DIR__ . '/setupReadinessHelpers.php';
            if (is_file($helper)) {
                require_once $helper;
                if (function_exists('sr_block_for_action')) {
                    $block = sr_block_for_action($pdo, $action);
                    if ($block !== null) {
                        business_guard_fail(
                            $fallbackView,
                            (string)$block['message'],
                            409,
                            ['code' => 'configuration_required', 'configuration_required' => $block]
                        );
                    }
                }
            }
        }
    }
}

if (!function_exists('business_require_read_access')) {
    /**
     * Authorize a READ-ONLY business endpoint that several business pages
     * legitimately depend on (audit F07).
     *
     * The endpoint keeps its mapped default key. A caller may name the page it
     * is loading data for through ?context=<page_key>, but ONLY keys in the
     * endpoint's explicit allowlist are accepted, and the exact operator +
     * profile assignment must hold that page permission (unchanged
     * requireProcessAccess()/userCanAccessPage() semantics). No new permission
     * is created and the shared access-control helper is not modified.
     */
    function business_require_read_access(PDO $pdo, array $allowedContexts, string $defaultKey): string
    {
        $context = trim((string)($_GET['context'] ?? $_POST['context'] ?? ''));
        $key = ($context !== '' && in_array($context, $allowedContexts, true)) ? $context : $defaultKey;
        if (!function_exists('requireProcessAccess')) {
            require_once __DIR__ . '/../process/accessControlHelpers.php';
        }
        requireProcessAccess($pdo, $key);
        return $key;
    }
}
