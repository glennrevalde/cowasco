<?php
/**
 * Upload Bill Balances (water/views/bill_balance_upload.php): the templates (GET action=template, layout=wide|long),
 * then, as JSON, read (the file, each balance a line of the review), check (the lines as corrected) and post (every
 * balance recorded, or none).
 */
require_once __DIR__ . '/../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
require_once __DIR__ . '/../common/importReviewHelpers.php';
require_once __DIR__ . '/../common/billBalanceUploadHelpers.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';

$pdo = getDB();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ((string)($_GET['action'] ?? '') === 'template') {
        $wide = (string)($_GET['layout'] ?? 'wide') !== 'long';
        ir_template($wide ? 'bill_balances_one_row_per_member' : 'bill_balances_one_row_per_balance', $wide ? BBU_WIDE_COLUMNS : BBU_COLUMNS,
            bbu_samples($pdo, $wide ? 'wide' : 'long'), (string)($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv');
    }
    http_response_code(405);
    exit('This action must be submitted from the upload page.');
}
/* BUSINESS_MUTATION_GUARD_START */
business_require_mutation($pdo, '../views/bill_balance_upload.php', ['action' => 'billing', 'json' => true]);
/* BUSINESS_MUTATION_GUARD_END */

try {
    $body = ir_body();
    $action = (string)($body['action'] ?? $_POST['action'] ?? '');
    $defaults = is_array($body['defaults'] ?? null) ? $body['defaults'] : $_POST;
    $common = [];
    foreach (['bill_date', 'due_date', 'recurring_code', 'installment_code', 'one_time_code', 'installments', 'payment_mode'] as $k) {
        $common[$k] = trim((string)($defaults[$k] ?? ''));
    }
    // what stops the whole upload, shown above the lines: the tables not installed, the class of the journal entries
    // (when a line makes one), too many lines
    $general = function (array $rows) use ($pdo, $defaults) {
        $journal = (bool)array_filter($rows, fn($r) => in_array(bbu_type_key((string)($r['billing_type'] ?? '')), ['recurring', 'installment', 'one_time'], true));
        return array_values(array_filter([
            bbu_ready($pdo) ? null : 'Run the database update database/migrations/2027_04_18_bill_balance_upload.sql first.',
            $journal ? bbu_class_problem($defaults['class_id'] ?? null) : null,
            ir_too_many(count($rows), BBU_MAX_LINES),
        ]));
    };
    if ($action === 'read') {
        [$fileRows, $file] = ir_read_file(BBU_FILE_COLUMNS, BBU_ALIASES);
        $rows = bbu_expand($fileRows, bbu_codes($pdo));
        ir_answer(['success' => true, 'file' => $file, 'file_rows' => count($fileRows)]
            + ir_payload(bbu_check($pdo, $rows, $common), BBU_COLUMNS, ['general' => $general($rows)]));
    }
    if ($action === 'check') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rows = bbu_rows($body);
        ir_answer(['success' => true] + ir_payload(bbu_check($pdo, $rows, $common), BBU_COLUMNS, ['general' => $general($rows)]));
    }
    if ($action === 'post') {
        if (!Access::can('water.bill_balance_upload', 'create')) {
            fail('Access denied. Recording an upload takes the Create right on Upload Bill Balances.');
        }
        try {
            requireOperationToken('bill_balance_upload', (string)($body['operation_token'] ?? ''));
        } catch (Throwable $e) {
            fail($e->getMessage());
        }
        $userId = (int)($_SESSION['user_id'] ?? $_SESSION['operator_id'] ?? 0);
        $file = mb_substr((string)($body['file'] ?? ''), 0, 190);
        try {
            $r = bbu_post($pdo, bbu_rows($body), $common, $defaults['class_id'] ?? null, $userId, $file);
        } catch (PDOException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // the class, the account and the session checks of the water helpers (their own messages)
            fail($e->getMessage());
        }
        consumeOperationToken('bill_balance_upload', (string)$body['operation_token']);
        $parts = [];
        foreach ($r['by_type'] as $t => [$n, $amt]) {
            if ($n > 0) {
                $parts[] = BBU_TYPES[$t][0] . ' ' . $n . ' (₱' . number_format($amt, 2) . ')';
            }
        }
        $_SESSION['success_msg'] = 'Uploaded ' . $r['upload_no'] . ($file !== '' ? ' from ' . $file : '') . ': ' . $r['count'] . ' balance' . ($r['count'] === 1 ? '' : 's')
            . ' for ₱' . number_format($r['total'], 2) . '; ' . implode(', ', $parts) . '.';
        ir_answer(['success' => true, 'redirect' => 'bill_balance_upload.php?upload=' . $r['upload_id'] . '#upload']);
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
    error_log('[billBalanceUpload] ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    ir_answer(['success' => false, 'error' => 'The upload could not be completed, and nothing was recorded. The details are in the server\'s error log; try again, or tell the administrator.'], 500);
}
