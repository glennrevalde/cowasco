<?php
/**
 * Import of One-Time Billing charges (water/views/one_time_billing_import.php): the template (GET action=template),
 * then, as JSON, read (the file), check (the lines as corrected) and post (one bill and invoice per line, or none).
 */
require_once __DIR__ . '/../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
require_once __DIR__ . '/../common/importReviewHelpers.php';
require_once __DIR__ . '/../common/oneTimeBillImportHelpers.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';

$pdo = getDB();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ((string)($_GET['action'] ?? '') === 'template') {
        ir_template('one_time_charges_import', OTBI_COLUMNS, otbi_samples($pdo), (string)($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv');
    }
    http_response_code(405);
    exit('This action must be submitted from the import page.');
}
/* BUSINESS_MUTATION_GUARD_START */
business_require_mutation($pdo, '../views/one_time_billing_import.php', ['action' => 'billing', 'json' => true]);
/* BUSINESS_MUTATION_GUARD_END */

try {
    $body = ir_body();
    $action = (string)($body['action'] ?? $_POST['action'] ?? '');
    $defaults = is_array($body['defaults'] ?? null) ? $body['defaults'] : $_POST;
    $common = [];
    foreach (['charge_code', 'amount', 'bill_date', 'term_days'] as $k) {
        $common[$k] = trim((string)($defaults[$k] ?? ''));
    }
    // what stops the whole import, shown above the lines: the class of the bills, too many lines
    $general = fn(array $rows) => array_values(array_filter([otbi_class_problem($defaults['class_id'] ?? null), ir_too_many(count($rows), OTBI_MAX_LINES)]));
    if ($action === 'read') {
        [$rows, $file] = ir_read_file(OTBI_COLUMNS, OTBI_ALIASES);
        ir_answer(['success' => true, 'file' => $file] + ir_payload(otbi_check($pdo, $rows, $common), OTBI_COLUMNS, ['general' => $general($rows)]));
    }
    if ($action === 'check') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rows = ir_rows(OTBI_COLUMNS, $body);
        ir_answer(['success' => true] + ir_payload(otbi_check($pdo, $rows, $common), OTBI_COLUMNS, ['general' => $general($rows)]));
    }
    if ($action === 'post') {
        $accessKey = access_resolve_key('one_time_billing') ?: 'water.one_time_billing';
        if (!Access::can($accessKey, 'create')) {
            fail('Access denied. Issuing an import takes the Create right on One-Time Billing.');
        }
        try {
            requireOperationToken('one_time_billing_import', (string)($body['operation_token'] ?? ''));
        } catch (Throwable $e) {
            fail($e->getMessage());
        }
        $userId = (int)($_SESSION['user_id'] ?? $_SESSION['operator_id'] ?? 0);
        $file = mb_substr((string)($body['file'] ?? ''), 0, 190);
        try {
            $r = otbi_post($pdo, ir_rows(OTBI_COLUMNS, $body), $common, $defaults['class_id'] ?? null, $userId, $file);
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // the class and the session checks of the water helpers (their own messages); a database error is not one
            fail($e->getMessage());
        }
        consumeOperationToken('one_time_billing_import', (string)$body['operation_token']);
        $_SESSION['success_msg'] = 'Imported ' . ($file !== '' ? $file . ': ' : ': ') . $r['count'] . ' one-time bill(s) issued for ₱' . number_format($r['total'], 2)
            . ' (' . implode(', ', $r['refs']) . ($r['count'] > count($r['refs']) ? ' ...' : '') . ').'
            . ($r['discount'] > 0.004 ? ' Discount applied: ₱' . number_format($r['discount'], 2) . '.' : '');
        ir_answer(['success' => true, 'redirect' => 'one_time_billing.php']);
    }
    fail('Unknown action.');
} catch (UserError $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ir_answer(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[oneTimeBillImport] ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    ir_answer(['success' => false, 'error' => 'The import could not be completed, and nothing was issued. The details are in the server\'s error log; try again, or tell the administrator.'], 500);
}
