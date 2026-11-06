<?php
declare(strict_types=1);

/**
 * Version 50: an open billing period is no longer a step of the water set-up guide ("Billing readiness" blocked
 * Reading Entry and Post Billing with "No open billing period exists for new readings"). Reading Entry says itself
 * when no period is open; saving a reading and posting bills refuse a period that is not open, as before.
 * The pages are checked over HTTP (tests/water/e2e_http.php, section v50).
 */

require_once APP_ROOT . '/water/common/setupReadinessHelpers.php';
require_once APP_ROOT . '/water/common/waterReadingWorkflowHelpers.php';
require_once APP_ROOT . '/water/common/billingPeriodHelpers.php';

test('Version 50: no Billing readiness step; a reading or a posting still needs an open billing period', function () {
    $pdo = DB::pdo();
    $catalog = sr_requirement_catalog();
    check(!isset($catalog['billing']), 'no Billing readiness step');
    check(!function_exists('sr_check_billing'), 'nor its check');
    eq(sr_action_requirements()['billing'], ['administrator', 'institution', 'organization', 'accounts', 'bill_codes', 'tariff'],
        'billing actions need the set-up steps only, not an open period');
    // no open period at all: the guide does not stop billing for it
    $periods = DB::all('SELECT period_id, status FROM bill_periods');
    DB::run("UPDATE bill_periods SET status = 'closed'");
    try {
        $block = sr_block_for_action($pdo, 'billing');
        check($block === null || $block['requirement_key'] !== 'billing', 'with no open period the guide does not block billing for it');
        $all = sr_evaluate($pdo, true);
        foreach ($all as $key => $item) {
            check(stripos((string)$item['message'], 'open billing period') === false, "$key says nothing about an open billing period");
        }
        // ...and the work itself still refuses a period that is not open
        $closed = (int)DB::value('SELECT period_id FROM bill_periods ORDER BY period_id LIMIT 1');
        if ($closed > 0) {
            $refused = '';
            try {
                DB::tx(fn() => wr_lock_open_period($pdo, $closed));
            } catch (Throwable $e) {
                $refused = $e->getMessage();
            }
            check(str_contains($refused, 'closed and cannot be used to accept meter readings'), 'a reading of a closed period is refused: ' . $refused);
            $refused = '';
            try {
                bp_assert_open(DB::row('SELECT * FROM bill_periods WHERE period_id = :p', ['p' => $closed]), 'post bills');
            } catch (Throwable $e) {
                $refused = $e->getMessage();
            }
            check(str_contains($refused, 'cannot be used to post bills'), 'posting bills of a closed period is refused');
        }
        $refused = '';
        try {
            wr_lock_open_period($pdo, 0);
        } catch (Throwable $e) {
            $refused = $e->getMessage();
        }
        check(str_contains($refused, 'A valid billing period is required'), 'a reading without a period is refused');
    } finally {
        foreach ($periods as $pr) {
            DB::run('UPDATE bill_periods SET status = :s WHERE period_id = :p', ['s' => $pr['status'], 'p' => $pr['period_id']]);
        }
    }
});
