<?php
/**
 * Import of Batch Share Capital Billing (water/views/share_capital_import.php): the template (GET action=template),
 * then, as JSON, read (the file), check (the lines as corrected) and post (every line on fixed billing, or none).
 */
require_once __DIR__ . '/../database/config.php';
/* PROCESS_ACCESS_GUARD_START */
require_once __DIR__ . '/accessControlHelpers.php';
$__processGuardPdo = getDB();
requireProcessAccess($__processGuardPdo);
unset($__processGuardPdo);
/* PROCESS_ACCESS_GUARD_END */
require_once __DIR__ . '/../common/importReviewHelpers.php';
require_once __DIR__ . '/../common/operationTokenHelpers.php';

$pageKey = 'water.share_capital_billing';
$cols = ShareCapitalBilling::IMPORT_COLUMNS;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    if ((string)($_GET['action'] ?? '') === 'template') {
        ir_template('batch_share_capital_billing_import', $cols, ShareCapitalBilling::importSamples(), (string)($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv');
    }
    http_response_code(405);
    exit('This action must be submitted from the import page.');
}
/* BUSINESS_MUTATION_GUARD_START */
business_require_mutation(getDB(), '../views/share_capital_import.php', ['action' => 'billing', 'json' => true]);
/* BUSINESS_MUTATION_GUARD_END */

try {
    if (!ShareCapitalBilling::ready()) {
        fail('Run the database updates of Fixed Share Capital Billing first.');
    }
    $body = ir_body();
    $action = (string)($body['action'] ?? $_POST['action'] ?? '');
    $defaults = is_array($body['defaults'] ?? null) ? $body['defaults'] : $_POST;
    $common = [];
    foreach (['amount', 'frequency', 'start_date', 'posting_day', 'term_days'] as $k) {
        $common[$k] = trim((string)($defaults[$k] ?? ''));
    }
    // what stops the whole import, shown above the lines
    $general = fn(array $rows) => array_values(array_filter([ir_too_many(count($rows), ShareCapitalBilling::MAX_BATCH)]));
    if ($action === 'read') {
        [$rows, $file] = ir_read_file($cols, ShareCapitalBilling::IMPORT_ALIASES);
        ir_answer(['success' => true, 'file' => $file] + ir_payload(ShareCapitalBilling::checkRows($rows, $common), $cols, ['general' => $general($rows)]));
    }
    if ($action === 'check') {
        // the check as you type writes nothing to the session: the next request goes through at once
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rows = ir_rows($cols, $body);
        ir_answer(['success' => true] + ir_payload(ShareCapitalBilling::checkRows($rows, $common), $cols, ['general' => $general($rows)]));
    }
    if ($action === 'post') {
        $accessKey = access_resolve_key('share_capital_billing') ?: $pageKey;
        if (!Access::can($accessKey, 'create')) {
            fail('Access denied. Posting an import takes the Create right on Fixed Share Capital Billing.');
        }
        try {
            requireOperationToken('share_capital_import', (string)($body['operation_token'] ?? ''));
        } catch (Throwable $e) {
            fail($e->getMessage());
        }
        $rows = ir_rows($cols, $body);
        $r = ShareCapitalBilling::saveBatch($rows, $common, 'import', $pageKey);
        consumeOperationToken('share_capital_import', (string)$body['operation_token']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Imported: ' . count($r['ids']) . ' member(s) put on fixed billing (batch ' . $r['batch_no'] . ' from '
            . mb_substr((string)($body['file'] ?? 'the file'), 0, 120) . '), ₱' . number_format((float)$r['amount'], 2) . ' a posting.'
            . ($r['bills'] > 0 ? ' ' . $r['bills'] . ' bill(s) of posting days already past were posted.' : '')];
        ir_answer(['success' => true, 'redirect' => 'share_capital_billing.php']);
    }
    fail('Unknown action.');
} catch (UserError $e) {
    if (DB::inTransaction()) {
        DB::rollBack();
    }
    ir_answer(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (DB::inTransaction()) {
        DB::rollBack();
    }
    error_log('[shareCapitalImport] ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    ir_answer(['success' => false, 'error' => 'The import could not be completed, and nothing was saved. The details are in the server\'s error log; try again, or tell the administrator.'], 500);
}
