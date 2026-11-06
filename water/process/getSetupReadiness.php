<?php
/**
 * process/getSetupReadiness.php
 *
 * JSON view of the FORCE CONFIGURATION readiness evaluation for the signed-in
 * operator. Read-only. Uses the unchanged session/login state: a request
 * without a valid session receives a structured 401 (never the modal HTML).
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/accessControlHelpers.php';
require_once __DIR__ . '/../common/setupReadinessHelpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in before loading configuration readiness.']);
    exit;
}

try {
    $pdo = getDB();
    $pageKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_GET['page'] ?? '')));
    $summary = sr_summary($pdo, $pageKey, true);
    $summary['progress'] = sr_progress_load($pdo);
    $summary['success'] = true;
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[setup readiness] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Configuration readiness could not be evaluated.']);
}
