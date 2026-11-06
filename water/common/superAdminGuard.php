<?php
/**
 * Hard server-side guard for dangerous financial maintenance actions.
 *
 * Super Administrator privilege is granted only when the exact active
 * operator_profile row has is_super_admin = 1. Names, profile labels,
 * access levels, and numeric role IDs never create a bypass.
 */

if (!function_exists('wb_is_super_admin')) {
    function wb_is_super_admin(): bool
    {
        if (function_exists('access_is_superadmin')) {
            return access_is_superadmin();
        }

        // Fail closed if this helper is loaded without the access-control layer.
        return false;
    }
}

if (!function_exists('wb_require_super_admin')) {
    function wb_require_super_admin(string $mode = 'page'): void
    {
        if (wb_is_super_admin()) {
            return;
        }

        http_response_code(403);
        $message = 'Unauthorized. This action requires a database-designated Super Administrator assignment.';

        if ($mode === 'process') {
            $_SESSION['error_msg'] = $message;
            $requested = (string)($_POST['redirect_to'] ?? '');
            $allowed = [
                '../views/billing_management.php',
                '../views/billing_adjustments.php',
                '../views/billing_list.php'
            ];
            $back = in_array($requested, $allowed, true)
                ? $requested
                : '../views/billing_management.php';
            header('Location: ' . $back);
            exit;
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Forbidden</title>';
        echo '<style>body{font-family:Arial,sans-serif;background:#f8f9fb;color:#111827;padding:40px}.box{max-width:720px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.08)}h1{margin:0 0 10px;font-size:24px}.muted{color:#6b7280;line-height:1.6}a{display:inline-block;margin-top:18px;color:#111827;font-weight:700}</style>';
        echo '</head><body><div class="box"><h1>403 - Super Administrator Only</h1>';
        echo '<p class="muted">This assignment is not marked as Super Administrator in the database. No username or profile name automatically grants this privilege.</p>';
        echo '<a href="billing_management.php">Back to Billing Management</a></div></body></html>';
        exit;
    }
}
