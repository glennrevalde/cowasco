<?php
declare(strict_types=1);

/**
 * The requests of ADDITIONAL_MODULE_FOR_WATER_COOP_SYSTEMS_v9.xlsx (version 38) at the level of the program's rules:
 *  - Interest on Share Capital and Patronage Refund: pointing at a member's basis shows where it comes from (the share
 *    capital balance day by day and its average; the income, purchases or deliveries document by document), worked
 *    out with the same arithmetic as the batch, to the centavo;
 *  - the totals under the list of members;
 *  - a computation never posted (in review, reviewed or approved) is deleted, with the reason, in the audit trail.
 * The pages (the window on the basis, the totals, the Delete buttons) are checked in the browser (docs/TEST_EVIDENCE.md).
 */

test('Interest on share capital: the average daily balance shown day by day equals the basis of the batch, to the centavo', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $m = newMember('Promedio', 'Lucia');
    $sc = openAccount($m, 'SC', '2025-12-01');
    eq((int)DB::value('SELECT COUNT(*) FROM sav_txns WHERE account_id = :a', ['a' => $sc]), 0, 'a share capital account with no entry yet');
    $put = function (string $date, string $cr, string $dr, ?string $value = null, int $void = 0) use ($sc) {
        DB::insert('sav_txns', ['account_id' => $sc, 'txn_date' => $date, 'value_date' => $value, 'txn_code' => $dr === '0' ? 'CD' : 'CW', 'description' => 'v38 test',
            'debit' => $dr, 'credit' => $cr, 'balance_after' => '0', 'is_voided' => $void, 'is_cleared' => 1, 'pb_printed' => 0, 'is_back_entry' => 0]);
    };
    // 1,000.00 before the period; in the period (01/01 to 03/31, 90 days): +500 on the first day, +2,000 and -300 on 02/10,
    // +100 dated 03/05 with the value date 03/01, -200 on the last day; a voided 9,999.00 does not count
    $put('2025-12-15', '1000', '0');
    $put('2026-01-01', '500', '0');
    $put('2026-02-10', '2000', '0');
    $put('2026-02-10', '0', '300');
    $put('2026-02-20', '9999', '0', null, 1);
    $put('2026-03-05', '100', '0', '2026-03-01');
    $put('2026-03-31', '0', '200');
    // worked out here with bcmath: 1,500 x 40 days + 3,200 x 19 + 3,300 x 30 + 3,100 x 1 = 222,900 balance-days / 90
    $sum = bcadd(bcadd(bcmul('1500', '40', 2), bcmul('3200', '19', 2), 2), bcadd(bcmul('3300', '30', 2), bcmul('3100', '1', 2), 2), 2);
    eq($sum, '222900.00', 'the balance-days of the period');
    $adb = bcdiv(bcadd(bcdiv($sum, '90', 6), '0.005', 6), '1', 2);      // 2,476.666... rounded half up: 2,476.67
    $s = Savings::balanceSegments($sc, '2026-01-01', '2026-03-31');
    eq(array_map(fn($x) => [$x['from'], $x['to'], $x['days'], Dec::fix($x['balance']), Dec::fix($x['product'])], $s['segments']), [
        ['2026-01-01', '2026-02-09', 40, '1500.00', '60000.00'],
        ['2026-02-10', '2026-02-28', 19, '3200.00', '60800.00'],
        ['2026-03-01', '2026-03-30', 30, '3300.00', '99000.00'],
        ['2026-03-31', '2026-03-31', 1, '3100.00', '3100.00'],
    ], 'each stretch of days the balance stood the same, with its balance-days (the value date counts, the voided entry does not)');
    eq([Dec::fix($s['opening']), Dec::fix($s['sum']), $s['days'], Dec::fix($s['adb']), Dec::fix($s['ending'])], ['1000.00', '222900.00', 90, $adb, '3100.00'], 'the opening, the sum, the days, the average and the ending balance');
    eq(array_map(fn($c) => [$c['date'], Dec::fix($c['in']), Dec::fix($c['out']), $c['count']], $s['changes']), [
        ['2026-01-01', '500.00', '0.00', 1], ['2026-02-10', '2000.00', '300.00', 2], ['2026-03-01', '100.00', '0.00', 1], ['2026-03-31', '0.00', '200.00', 1],
    ], 'what came in and went out on each day the balance changed');
    // the same as balances() over other periods: from the start of the year, a period with no entry, a single day
    foreach ([['2025-12-01', '2026-10-29'], ['2026-04-01', '2026-06-30'], ['2026-02-10', '2026-02-10'], ['2026-01-15', '2026-03-15']] as [$f, $t]) {
        $a = Savings::balances($sc, $f, $t);
        $b = Savings::balanceSegments($sc, $f, $t);
        eq([Dec::fix($b['adb']), Dec::fix($b['ending']), $b['days'], array_sum(array_column($b['segments'], 'days'))], [Dec::fix($a['adb']), Dec::fix($a['ending']), $a['days'], $a['days']], "segments of $f to $t agree with the average daily balance");
    }
    // every share capital account of the test database: the details agree with balances()
    $bad = 0;
    foreach (DB::column("SELECT a.id FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE p.product_type = 'share_capital'") as $aid) {
        $a = Savings::balances((int)$aid, '2026-01-01', today());
        $b = Savings::balanceSegments((int)$aid, '2026-01-01', today());
        $bad += (Dec::cmp($a['adb'], $b['adb']) !== 0 || Dec::cmp($a['ending'], $b['ending']) !== 0) ? 1 : 0;
    }
    eq($bad, 0, 'every share capital account: the same average and ending balance');
    // a batch: the details of each line equal the basis stored; the member's computed amount is the basis x the rate
    $bid = Distribution::compute('isc', ['period_id' => null, 'date_from' => '2026-01-01', 'date_to' => '2026-03-31', 'method' => 'rate', 'rate' => '10', 'pool' => '0',
        'basis' => 'adb', 'credit_mode' => 'share_capital', 'credit_product_id' => null, 'description' => 'ISC details'], ['product_id' => $ids['sav.SC']], null, 'dist.isc');
    $batch = Distribution::batch($bid);
    $lines = DB::all('SELECT * FROM dist_batch_lines WHERE batch_id = :b', ['b' => $bid]);
    check(count($lines) > 0, 'members on the batch');
    $diff = 0;
    foreach ($lines as $l) {
        $d = Distribution::basisDetail($batch, $l);
        $diff += ($d['changed'] || Dec::cmp($d['current'], $l['basis_amount']) !== 0) ? 1 : 0;
    }
    eq($diff, 0, 'the details of every member give the basis of the batch');
    $mine = DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b AND sav_account_id = :a', ['b' => $bid, 'a' => $sc]);
    $d = Distribution::basisDetail($batch, $mine);
    eq([Dec::fix($mine['basis_amount']), Dec::fix($d['adb']), (string)$d['account'], count($d['segments'])], [$adb, $adb, (string)DB::value('SELECT account_no FROM sav_accounts WHERE id = :a', ['a' => $sc]), 4], 'the member: the average of 2,476.67 on its account, in four stretches');
    eq(Dec::fix($mine['computed']), bcdiv(bcadd(bcdiv(bcmul($adb, '10', 4), '100', 6), '0.005', 6), '1', 2), 'the computed amount is the basis x 10 %');
    // an entry dated in the period made after the batch was computed: the details say so (Recompute takes it)
    $put('2026-02-15', '900', '0');
    $d = Distribution::basisDetail($batch, $mine);
    check($d['changed'], 'an entry made afterwards: the basis worked out now differs from the batch');
    eq([Dec::fix($d['stored']), Dec::fix($d['current'])], [$adb, Dec::fix(Savings::balances($sc, '2026-01-01', '2026-03-31')['adb'])], 'the batch keeps its basis; the details show the one worked out now');
    // the ending balance basis
    $eb = $batch;
    $eb['basis'] = 'ending';
    $mineE = $mine;
    $mineE['basis_amount'] = '4000.00';
    $d = Distribution::basisDetail($eb, $mineE);
    eq([Dec::fix($d['current']), $d['changed']], ['4000.00', false], 'the ending balance: 3,100.00 + 900.00');
    Distribution::deleteBatch($bid, 'test batch of the details', 'dist.isc');
});

test('Patronage refund: the income earned shown document by document equals the basis of the batch', function () {
    Auth::actAs($GLOBALS['adminId']);
    $acc = acct('4400');
    $pid = Distribution::compute('pr', ['period_id' => null, 'date_from' => '2026-01-01', 'date_to' => today(), 'method' => 'pool', 'rate' => '0', 'pool' => '1000',
        'credit_mode' => 'payable', 'credit_product_id' => null], ['income_accounts' => [$acc]], null, 'dist.pr');
    $batch = Distribution::batch($pid);
    $f = (array)json_decode((string)$batch['filters_json'], true);
    $all = Distribution::patronage('income', $f, '2026-01-01', today());
    $lines = DB::all('SELECT * FROM dist_batch_lines WHERE batch_id = :b', ['b' => $pid]);
    check(count($lines) > 0, 'members with income earned from them');
    $bad = [];
    $docs = 0;
    foreach ($lines as $l) {
        $d = Distribution::basisDetail($batch, $l);
        foreach ($d['groups'] as $g) {
            $docs += count($g['docs']);
            if (Dec::cmp(Dec::sum(array_column($g['docs'], 'amount')), $g['total']) !== 0) {
                $bad[] = 'group ' . $g['label'];
            }
            foreach ($g['docs'] as $doc) {
                if ((int)DB::value('SELECT is_voided FROM transactions WHERE id = :t', ['t' => $doc['id']]) !== 0 || $doc['date'] < '2026-01-01' || $doc['date'] > today()) {
                    $bad[] = 'document ' . $doc['no'];
                }
            }
        }
        if ($d['changed'] || Dec::cmp($d['current'], $l['basis_amount']) !== 0 || Dec::cmp($d['current'], Dec::round($all[(int)$l['member_id']] ?? '0', 2)) !== 0) {
            $bad[] = 'member ' . $l['member_id'];
        }
    }
    eq($bad, [], 'every member: the documents add up to the group, the groups to the basis of the batch and to patronage()');
    check($docs >= count($lines), 'the documents are listed (' . $docs . ')');
    eq(Distribution::basisDetail($batch, $lines[0])['groups'][0]['label'], (string)DB::value("SELECT CONCAT(code, ' - ', name) FROM accounts WHERE id = :a", ['a' => $acc]), 'grouped by income account (code and name)');
    // a member with no income in the period: nothing listed
    eq(Distribution::patronageDetail('income', $f, '2026-01-01', today(), newMember('Sinventa', 'Pedro')), [], 'a member with no income: no document');
    eq(Distribution::patronageDetail('income', ['income_accounts' => []], '2026-01-01', today(), (int)$lines[0]['member_id']), [], 'no income account chosen: nothing');
    Distribution::deleteBatch($pid, 'test batch of the details', 'dist.pr');
});

test('Delete a computation never posted: in review, reviewed or approved, with the reason and the rights; a posted batch is reversed instead', function () {
    global $ids;
    Auth::actAs($GLOBALS['adminId']);
    $h = ['period_id' => null, 'date_from' => today(), 'date_to' => today(), 'method' => 'pool', 'rate' => '0', 'pool' => '500',
        'basis' => 'ending', 'credit_mode' => 'share_capital', 'credit_product_id' => null, 'description' => 'To delete'];
    $mk = fn() => Distribution::compute('isc', $h, [], null, 'dist.isc');
    $audit = fn(int $id) => DB::row("SELECT * FROM audit_trail WHERE record_type = 'dist_batch' AND record_id = :b AND action = 'delete' ORDER BY id DESC LIMIT 1", ['b' => $id]);
    // in review
    $a = $mk();
    $ba = Distribution::batch($a);
    $n = (int)DB::value('SELECT COUNT(*) FROM dist_batch_lines WHERE batch_id = :b', ['b' => $a]);
    check($n > 0 && Distribution::deletable($ba), 'a batch in review can be deleted');
    refuses(fn() => Distribution::deleteBatch($a, '  ', 'dist.isc'), 'reason', 'the reason is needed');
    asUser('book');
    check(!Access::can('dist.isc', 'delete'), 'the bookkeeper has no Delete right on the page');
    refuses(fn() => Distribution::deleteBatch($a, 'not needed', 'dist.isc'), 'Delete right', 'without the Delete right: refused');
    Auth::actAs($GLOBALS['adminId']);
    $txns = (int)DB::value('SELECT COUNT(*) FROM transactions');
    Distribution::deleteBatch($a, 'Computed on the wrong date', 'dist.isc');
    eq([Distribution::batch($a), (int)DB::value('SELECT COUNT(*) FROM dist_batch_lines WHERE batch_id = :b', ['b' => $a]), (int)DB::value('SELECT COUNT(*) FROM transactions')], [null, 0, $txns], 'deleted with its list; no voucher touched');
    $row = $audit($a);
    $snap = json_decode((string)$row['snapshot'], true);
    eq([$row['reason'], $row['record_ref'], $snap['batch']['batch_no'], count($snap['lines'])], ['Computed on the wrong date', $ba['batch_no'] . ' ISC', $ba['batch_no'], $n], 'the audit trail keeps the batch, its lines and the reason');
    // the number is not used again
    $b = $mk();
    check(strcmp(Distribution::batch($b)['batch_no'], $ba['batch_no']) > 0, 'the next batch takes a new number');
    // reviewed
    eq(Distribution::validate($b, 'dist.isc'), [], 'reviewed');
    Distribution::deleteBatch($b, 'Reviewed but not wanted', 'dist.isc');
    eq(Distribution::batch($b), null, 'a reviewed batch is deleted');
    // approved: the Approve right as well
    $c = $mk();
    Distribution::validate($c, 'dist.isc');
    asUser('gm');
    Distribution::approve($c, 'BR-2026-38', 'dist.isc');
    check(Distribution::deletable(Distribution::batch($c)), 'an approved batch not yet posted can be deleted');
    $book = (int)$ids['user.book'];
    $page = (int)DB::value("SELECT id FROM pages WHERE page_key = 'dist.isc'");
    DB::run('DELETE FROM user_page_overrides WHERE user_id = :u AND page_id = :p', ['u' => $book, 'p' => $page]);
    DB::insert('user_page_overrides', ['user_id' => $book, 'page_id' => $page, 'allowed' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 1, 'can_print' => 1, 'can_approve' => 0]);
    Access::reset();
    asUser('book');
    refuses(fn() => Distribution::deleteBatch($c, 'not needed', 'dist.isc'), 'Approve right', 'an approved batch: the Delete right alone is not enough');
    DB::run('DELETE FROM user_page_overrides WHERE user_id = :u AND page_id = :p', ['u' => $book, 'p' => $page]);
    Access::reset();
    asUser('gm');
    Distribution::deleteBatch($c, 'The board changed the rate', 'dist.isc');
    eq(Distribution::batch($c), null, 'the general manager (Delete and Approve) deletes the approved batch');
    // posted: not deleted (reversed instead); reversed: stays on file
    Auth::actAs($GLOBALS['adminId']);
    $d = $mk();
    Distribution::validate($d, 'dist.isc');
    asUser('gm');
    Distribution::approve($d, 'BR-2026-39', 'dist.isc');
    Auth::actAs($GLOBALS['adminId']);
    Distribution::post($d, today(), 0, 'dist.isc');
    check(!Distribution::deletable(Distribution::batch($d)), 'a posted batch cannot be deleted');
    refuses(fn() => Distribution::deleteBatch($d, 'x', 'dist.isc'), 'Reverse it instead', 'a posted batch: refused, reverse it');
    Distribution::reverse($d, today(), 'Test of the delete', 'dist.isc');
    refuses(fn() => Distribution::deleteBatch($d, 'x', 'dist.isc'), 'stays on file', 'a reversed batch stays on file');
    check((bool)Distribution::batch($d), 'the posted and reversed batch is still there');
    // a line already credited or paid stops the delete (a batch left half-posted)
    $e = $mk();
    DB::run('UPDATE dist_batch_lines SET paid_txn_id = 1 WHERE batch_id = :b LIMIT 1', ['b' => $e]);
    refuses(fn() => Distribution::deleteBatch($e, 'x', 'dist.isc'), 'already credited or paid', 'members already credited or paid: refused');
    DB::run('UPDATE dist_batch_lines SET paid_txn_id = NULL WHERE batch_id = :b', ['b' => $e]);
    Distribution::deleteBatch($e, 'cleaned up', 'dist.isc');
    eq(Distribution::batch($e), null, 'deleted once nothing is credited');
    // a large batch: the audit trail keeps it in parts of 1,000 lines, each with the reason
    $g = $mk();
    $one = DB::row('SELECT * FROM dist_batch_lines WHERE batch_id = :b LIMIT 1', ['b' => $g]);
    unset($one['id']);
    $have = (int)DB::value('SELECT COUNT(*) FROM dist_batch_lines WHERE batch_id = :b', ['b' => $g]);
    for ($i = $have; $i < 1250; $i++) {
        DB::insert('dist_batch_lines', $one);
    }
    $maxBefore = (int)DB::value('SELECT COALESCE(MAX(id),0) FROM audit_trail');
    Distribution::deleteBatch($g, 'Large batch computed twice', 'dist.isc');
    $parts = DB::all("SELECT reason, snapshot FROM audit_trail WHERE id > :x AND record_type = 'dist_batch' AND record_id = :b AND action = 'delete' ORDER BY id", ['x' => $maxBefore, 'b' => $g]);
    $snaps = array_map(fn($r) => json_decode((string)$r['snapshot'], true), $parts);
    eq([count($parts), array_column($parts, 'reason'), array_column($snaps, 'part'), count($snaps[0]['lines'] ?? []) + count($snaps[1]['lines'] ?? []), isset($snaps[0]['batch']), isset($snaps[1]['batch'])],
        [2, ['Large batch computed twice', 'Large batch computed twice'], ['lines 1 to 1000 of 1250', 'lines 1001 to 1250 of 1250'], 1250, true, false], 'a batch of 1,250 lines: two audit rows, the batch in the first, every line kept');
    eq((int)DB::value('SELECT COUNT(*) FROM dist_batch_lines WHERE batch_id = :b', ['b' => $g]), 0, 'its lines are gone');
});
