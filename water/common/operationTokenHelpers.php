<?php
/**
 * Form-operation token helpers.
 *
 * This file is intentionally independent from database/config.php so projects
 * that preserved their local database credentials can still use the updated
 * duplicate-submission protection without replacing the configuration file.
 */

require_once __DIR__ . '/../database/config.php';

if (!function_exists('operationToken')) {
    function operationToken(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '' || strlen($scope) > 100) {
            throw new InvalidArgumentException('Invalid operation-token scope.');
        }

        if (!isset($_SESSION['operation_tokens']) || !is_array($_SESSION['operation_tokens'])) {
            $_SESSION['operation_tokens'] = [];
        }

        if (empty($_SESSION['operation_tokens'][$scope])) {
            $_SESSION['operation_tokens'][$scope] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION['operation_tokens'][$scope];
    }
}

if (!function_exists('requireOperationToken')) {
    function requireOperationToken(string $scope, ?string $submittedToken): void
    {
        $scope = trim($scope);
        $submittedToken = (string)($submittedToken ?? '');
        $currentToken = (string)($_SESSION['operation_tokens'][$scope] ?? '');

        if (
            $scope === '' ||
            $currentToken === '' ||
            $submittedToken === '' ||
            !hash_equals($currentToken, $submittedToken)
        ) {
            throw new RuntimeException(
                'This form was already submitted or has expired. Reload the page before trying again.'
            );
        }
    }
}

if (!function_exists('consumeOperationToken')) {
    function consumeOperationToken(string $scope, ?string $submittedToken): void
    {
        requireOperationToken($scope, $submittedToken);
        unset($_SESSION['operation_tokens'][$scope]);
    }
}
