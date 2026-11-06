<?php
/**
 * process/saveSetupTutorialProgress.php
 *
 * Stores tutorial PRESENTATION state only (which screens the operator has
 * seen). It never marks a requirement complete: readiness is always derived
 * from the live configuration by common/setupReadinessHelpers.php.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/businessRequestGuard.php';
require_once __DIR__ . '/../common/setupReadinessHelpers.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in first.']);
    exit;
}
$pdo = getDB();
business_require_mutation($pdo, '../views/dashboard.php', ['json' => true]);

$body = business_request_json_body();
$key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['requirement_key'] ?? $body['requirement_key'] ?? '')));
$state = preg_replace('/[^a-z_]/', '', strtolower((string)($_POST['state'] ?? $body['state'] ?? '')));
$saved = $key !== '' && sr_progress_save($pdo, $key, $state);
echo json_encode(['success' => $saved, 'note' => 'Presentation state only; completion is derived from saved configuration.']);
