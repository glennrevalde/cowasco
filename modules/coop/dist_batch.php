<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$kind = ($PARAMS['kind'] ?? 'isc') === 'pr' ? 'pr' : 'isc';
$pageKey = 'dist.' . $kind;
$label = $kind === 'isc' ? 'Interest on Share Capital' : 'Patronage Refund';
$batch = gint('id') ? Distribution::batch((int)gint('id')) : null;
if ($batch && $batch['kind'] !== $kind) {
    redirect(url('dist.' . $batch['kind'], ['id' => $batch['id']]));
}

/** The header and filters of the form, from the post */
$readForm = function () use ($kind): array {
    $h = ['period_id' => pint('period_id') ?: null, 'description' => trim((string)p('description')), 'date_from' => (string)p('date_from'), 'date_to' => (string)p('date_to'),
        'method' => p('method') === 'pool' ? 'pool' : 'rate', 'rate' => Dec::of(p('rate', '0')), 'pool' => Dec::of(p('pool', '0')), 'basis' => $kind === 'pr' ? (string)p('pr_basis', 'income') : (string)p('basis', 'adb'),
        'credit_mode' => (string)p('credit_mode'), 'credit_product_id' => pint('credit_product_id') ?: null, 'memo' => trim((string)p('memo'))];
    $f = ['cluster_id' => pint('cluster_id') ?: null, 'segment_id' => pint('segment_id') ?: null, 'branch_id' => pint('branch_id') ?: null,
        'member_types' => array_values(array_filter(array_map('intval', (array)($_POST['member_types'] ?? [])))),
        'amount_min' => trim((string)p('amount_min')) === '' ? '' : Dec::of(p('amount_min')), 'amount_max' => trim((string)p('amount_max')) === '' ? '' : Dec::of(p('amount_max')),
        'exclude_delinquent' => p('exclude_delinquent') === '1'];
    if ($kind === 'isc') {
        $f['product_id'] = pint('product_id') ?: null;
    } else {
        $f['income_accounts'] = array_values(array_filter(array_map('intval', (array)($_POST['income_accounts'] ?? []))));
    }
    return [$h, $f];
};

handle_post(function () use ($batch, $kind, $pageKey, $readForm) {
    $action = (string)p('action', 'compute');
    if ($action === 'compute') {
        Access::require($pageKey, $batch ? 'edit' : 'create');
        [$h, $f] = $readForm();
        $id = Distribution::compute($kind, $h, $f, $batch ? (int)$batch['id'] : null, $pageKey);
        $nb = Distribution::batch($id);
        // nothing is posted: the computation opens for review
        flash('success', ($batch ? 'Computed again: ' : 'Computed: ') . (int)$nb['members_count'] . ' member(s), ' . Dec::money($nb['total_amount']) . '. Nothing is posted yet: review the list below.');
        redirect(url($pageKey, ['id' => $id]));
    }
    if (!$batch) {
        fail('Batch not found.');
    }
    switch ($action) {
        case 'remove':
            // one member (its Remove) or the members ticked in the list
            Access::require($pageKey, 'edit');
            $ids = array_map('intval', (array)($_POST['line_ids'] ?? []));
            if (pint('line_id')) {
                $ids = [(int)pint('line_id')];
            }
            $n = Distribution::removeLines((int)$batch['id'], $ids, Distribution::removeReason((string)p('reason_pick'), (string)p('reason')), $pageKey);
            flash('success', $n . ' member(s) removed from the list. ' . ($batch['method'] === 'pool' ? 'The amount is shared again among the members left.' : 'The others keep their amounts.'));
            break;
        case 'restore':
            Access::require($pageKey, 'edit');
            Distribution::exclude((int)$batch['id'], (int)pint('line_id'), false, '', null, $pageKey);
            flash('success', 'The member is back on the list.');
            break;
        case 'line':
            Access::require($pageKey, 'edit');
            $adj = trim((string)p('adjustment'));
            Distribution::exclude((int)$batch['id'], (int)pint('line_id'), p('exclude') === '1', trim((string)p('reason')), $adj === '' ? null : Dec::of($adj), $pageKey);
            flash('success', 'The adjustment is saved; the list is back in review.');
            break;
        case 'validate':
            Access::require($pageKey, 'edit');
            $problems = Distribution::validate((int)$batch['id'], $pageKey);
            if ($problems) {
                $GLOBALS['FORM_ERROR'] = 'The batch cannot be validated yet: ' . implode(' ', array_slice($problems, 0, 8)) . (count($problems) > 8 ? ' (and ' . (count($problems) - 8) . ' more)' : '');
                return;
            }
            flash('success', 'The review is finished. An approver records the resolution next, then the batch is posted.');
            break;
        case 'approve':
            Distribution::approve((int)$batch['id'], trim((string)p('resolution')), $pageKey);
            flash('success', 'Approved and locked.');
            break;
        case 'post':
            Access::require($pageKey, 'create');
            coop_once();
            $txnId = Distribution::post((int)$batch['id'], (string)p('post_date'), (int)pint('debit_account_id'), $pageKey);
            flash('success', 'Posted (voucher ' . Txn::load($txnId)['txn_no'] . '). ' . ($batch['credit_mode'] === 'payable' ? 'Each member\'s amount is payable to the member, paid out from the report Unclaimed Distributions (click the member).' : 'Each member\'s account was credited.'));
            break;
        case 'reverse':
            coop_once();
            $txnId = Distribution::reverse((int)$batch['id'], (string)p('rev_date'), trim((string)p('reason')), $pageKey);
            flash('success', 'Reversed by voucher ' . Txn::load($txnId)['txn_no'] . '.');
            break;
        case 'delete':
            // a computation never posted (in review, reviewed or approved) is deleted with its list; the audit trail keeps it
            Access::require($pageKey, 'delete');
            $n = (int)DB::value('SELECT COUNT(*) FROM dist_batch_lines WHERE batch_id = :b', ['b' => $batch['id']]);
            Distribution::deleteBatch((int)$batch['id'], (string)p('reason'), $pageKey);
            flash('success', 'Batch ' . $batch['batch_no'] . ' deleted with its list of ' . $n . ' member(s). Nothing had been posted; the batch as it was is kept in the audit trail.');
            redirect(url('dist.batches'));
        default:
            fail('Unknown action.');
    }
    redirect(url($pageKey, ['id' => $batch['id']]));
});

$periods = DB::all("SELECT * FROM dist_periods WHERE status IN ('approved','posted') ORDER BY fiscal_year DESC");
$periodOpts = [];
foreach ($periods as $pp) {
    $periodOpts[$pp['id']] = 'Fiscal year ' . $pp['fiscal_year'] . ' (' . ($kind === 'isc' ? 'ISC ' . Dec::money($pp['isc_amount']) : 'PR ' . Dec::money($pp['pr_amount'])) . ')';
}
$lines = $batch ? DB::all('SELECT bl.*, m.full_name, m.member_no, a.account_no FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id LEFT JOIN sav_accounts a ON a.id = bl.sav_account_id WHERE bl.batch_id = :b ORDER BY bl.is_excluded, m.full_name', ['b' => $batch['id']]) : [];

// ------------------------------------------------------------------ where a member's basis comes from
// The window opened when the basis amount of a member is pointed at or tapped (assets/js/dist_batch.js asks for it):
// ISC, the share capital balance day by day and the average worked out from it (or the ending balance); PR, the income,
// purchases or deliveries of the member document by document. Then how the member's amount comes from the basis.
$basisBox = function (array $batch, array $line, array $d): string {
    $e = fn($s) => h((string)$s);
    $ex = (int)$line['is_excluded'] === 1;
    $days = Coop::daysBetween($d['from'], $d['to']) + 1;
    $span = fn(string $a, string $b) => $a === $b ? $e(fdate($a)) : $e(fdate($a)) . ' to ' . $e(fdate($b));
    $o = '<div class="db-detail" data-basis-ok>';
    $o .= '<div class="db-who"><strong>' . $e($line['full_name']) . '</strong> <span>' . $e($line['member_no']) . '</span></div>';
    if ($batch['kind'] === 'isc') {
        $isAdb = $d['basis'] !== 'ending';
        $o .= '<div class="db-sub">' . ($d['account'] !== '' ? 'Share capital account ' . $e($d['account']) . ($d['product'] !== '' ? ' (' . $e($d['product']) . ')' : '') . '<br>' : '')
            . 'Period ' . $span($d['from'], $d['to']) . ', ' . $days . ' days</div>';
        $chg = [];
        foreach ($d['changes'] as $c) {
            $chg[$c['date']] = $c;
        }
        $moveNote = function (?array $c) {
            if (!$c) {
                return '';
            }
            $bits = [];
            if (Dec::isPos($c['in'])) {
                $bits[] = '<span class="db-in">+' . cm($c['in']) . '</span>';
            }
            if (Dec::isPos($c['out'])) {
                $bits[] = '<span class="db-out">-' . cm($c['out']) . '</span>';
            }
            return implode(' ', $bits);
        };
        if ($isAdb) {
            // each stretch of days the balance stood the same: the balance times its days, added up, divided by the days
            $o .= '<div class="db-scroll"><table class="db-table"><thead><tr><th>Dates</th><th class="num">Days</th><th class="num">Balance</th><th class="num">Balance &times; days</th></tr></thead><tbody>';
            if ($d['segments'] && isset($chg[$d['segments'][0]['from']])) {
                // money moved on the first day: the balance the period started from, before it
                $o .= '<tr><td>Before ' . $e(fdate($d['from'])) . '</td><td class="num"></td><td class="num">' . cm($d['opening']) . '<div class="db-move db-muted">brought forward</div></td><td class="num"></td></tr>';
            }
            foreach ($d['segments'] as $i => $s) {
                $c = $chg[$s['from']] ?? null;
                $note = $c ? $moveNote($c) : ($i === 0 ? '<span class="db-muted">brought forward</span>' : '');
                $o .= '<tr><td>' . $span($s['from'], $s['to']) . '</td><td class="num">' . (int)$s['days'] . '</td><td class="num">' . cm($s['balance']) . ($note !== '' ? '<div class="db-move">' . $note . '</div>' : '') . '</td><td class="num">' . cm($s['product']) . '</td></tr>';
            }
            $o .= '</tbody><tfoot><tr><td>Total</td><td class="num">' . (int)$d['days'] . '</td><td></td><td class="num">' . cm($d['sum']) . '</td></tr></tfoot></table></div>';
            $o .= '<div class="db-result"><span>Average daily balance</span><span>' . cm($d['sum']) . ' &divide; ' . (int)$d['days'] . ' days = <strong>' . cm($d['adb']) . '</strong></span></div>';
        } else {
            // the balance brought forward and each change of the period, to the balance of the last day
            $bal = $d['opening'];
            $o .= '<div class="db-scroll"><table class="db-table"><thead><tr><th>Date</th><th class="num">Change</th><th class="num">Balance</th></tr></thead><tbody>';
            $o .= '<tr><td>' . $e(fdate($d['from'])) . '</td><td class="num"><span class="db-muted">brought forward</span></td><td class="num">' . cm($bal) . '</td></tr>';
            foreach ($d['changes'] as $c) {
                $bal = Dec::add($bal, Dec::sub($c['in'], $c['out'], 2), 2);
                $o .= '<tr><td>' . $e(fdate($c['date'])) . '</td><td class="num">' . $moveNote($c) . '</td><td class="num">' . cm($bal) . '</td></tr>';
            }
            $o .= '</tbody></table></div>';
            $o .= '<div class="db-result"><span>Ending balance</span><span>the balance at the end of ' . $e(fdate($d['to'])) . ' = <strong>' . cm($d['ending']) . '</strong></span></div>';
        }
    } else {
        $o .= '<div class="db-sub">' . $e(Distribution::PR_BASES[$d['basis']] ?? Distribution::PR_BASES['income']) . '<br>Period ' . $span($d['from'], $d['to']) . '</div>';
        if (!$d['groups']) {
            $o .= '<p class="db-muted mb-2">Nothing counts for this member in the period now.</p>';
        }
        $cap = 40;
        foreach ($d['groups'] as $g) {
            $o .= '<div class="db-group"><div class="db-group-head"><span>' . $e($g['label']) . '</span><span class="num">' . cm($g['total']) . '</span></div>'
                . '<div class="db-scroll"><table class="db-table db-docs"><tbody>';
            foreach (array_slice($g['docs'], 0, $cap) as $doc) {
                $o .= '<tr><td class="text-nowrap">' . $e(fdate($doc['date'])) . '</td><td>' . Txn::link(['id' => $doc['id'], 'txn_type' => $doc['type'], 'txn_no' => $doc['no']]) . ' <span class="db-muted">' . $e(Txn::label($doc['type'])) . '</span></td><td class="num">' . cm($doc['amount']) . '</td></tr>';
            }
            if (count($g['docs']) > $cap) {
                $rest = array_slice($g['docs'], $cap);
                $o .= '<tr><td></td><td class="db-muted">' . count($rest) . ' more document(s)</td><td class="num">' . cm(Dec::sum(array_column($rest, 'amount'))) . '</td></tr>';
            }
            $o .= '</tbody></table></div></div>';
        }
        $totals = array_map(fn($g) => cm($g['total']), $d['groups']);
        $o .= '<div class="db-result"><span>' . $e(Distribution::basisLabel($batch)) . '</span><span>' . (count($totals) > 1 ? implode(' + ', $totals) . ' = ' : '') . '<strong>' . cm($d['current']) . '</strong></span></div>';
    }
    if ($d['changed']) {
        $o .= '<div class="db-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Worked out again now, the basis is ' . cm($d['current']) . '; the batch has ' . cm($d['stored'])
            . '. An entry dated in the period was recorded or voided after the batch was computed.' . (in_array($batch['status'], ['draft', 'validated'], true) ? ' Recompute takes it.' : '') . '</div>';
    }
    // how the member's amount comes from the basis
    $o .= '<div class="db-calc">';
    if ($ex) {
        $o .= '<div><span>Removed from the list</span><span>' . $e((string)$line['exclude_reason']) . '. No amount.</span></div>';
    } else {
        if ($batch['method'] === 'rate') {
            $o .= '<div><span>Computed</span><span>' . cm($line['basis_amount']) . ' &times; ' . $e(Dec::fix($batch['rate'], 4)) . ' % = <strong>' . cm($line['computed']) . '</strong></span></div>'
                . '<div class="db-how">The member\'s basis &times; the rate of the batch.</div>';
        } else {
            $share = Dec::isPos($batch['total_basis']) ? Dec::round(Dec::div(Dec::mul($batch['pool'], $line['basis_amount'], 10), $batch['total_basis'], 10), 2) : '0';
            $round = Dec::sub($line['computed'], $share, 2);
            $o .= '<div><span>Computed</span><span>' . cm($batch['pool']) . ' &times; ' . cm($line['basis_amount']) . ' &divide; ' . cm($batch['total_basis']) . ' = <strong>' . cm($share) . '</strong></span></div>'
                . '<div class="db-how">The amount to distribute &times; the member\'s basis &divide; the basis of the ' . (int)$batch['members_count'] . ' member(s) on the list.</div>';
            if (!Dec::isZero($round)) {
                $o .= '<div><span>Rounding</span><span>' . cm($round) . ', so <strong>' . cm($line['computed']) . '</strong></span></div>'
                    . '<div class="db-how">The difference of rounding goes to the member with the largest basis, so the amount is given out to the centavo.</div>';
            }
        }
        if (!Dec::isZero($line['adjustment'])) {
            $o .= '<div><span>Adjustment</span><span>' . cm($line['adjustment']) . ($line['exclude_reason'] ? ' (' . $e($line['exclude_reason']) . ')' : '') . '</span></div>';
        }
        $o .= '<div class="db-net"><span>Net</span><span><strong>' . cm($line['net_amount']) . '</strong></span></div>';
    }
    return $o . '</div></div>';
};
if ($batch && g('a') === 'basis') {
    Access::require($pageKey, 'view');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $line = DB::row('SELECT bl.*, m.full_name, m.member_no FROM dist_batch_lines bl JOIN members m ON m.id = bl.member_id WHERE bl.id = :l AND bl.batch_id = :b', ['l' => (int)gint('line'), 'b' => $batch['id']]);
    if (!$line) {
        http_response_code(404);
        echo '<div class="db-detail" data-basis-ok><p class="db-muted mb-0">This member is no longer on the batch. Reload the page.</p></div>';
        exit;
    }
    echo $basisBox($batch, $line, Distribution::basisDetail($batch, $line));
    exit;
}

// ------------------------------------------------------------------ CSV and printed register
if ($batch && in_array(g('a'), ['csv', 'print'], true)) {
    Access::require($pageKey, 'print');
    if (g('a') === 'csv') {
        $out = [['Member no.', 'Member', 'Account', $kind === 'isc' ? 'Basis (' . strtoupper($batch['basis']) . ')' : Distribution::basisLabel($batch), 'Computed', 'Adjustment', 'Net', 'Excluded', 'Reason']];
        foreach ($lines as $l) {
            $out[] = [$l['member_no'], $l['full_name'], (string)$l['account_no'], $l['basis_amount'], $l['computed'], $l['adjustment'], $l['net_amount'], (int)$l['is_excluded'] ? 'yes' : '', (string)$l['exclude_reason']];
        }
        Audit::log('export', 'dist', 'dist_batch', (int)$batch['id'], $batch['batch_no'], ['page_key' => $pageKey]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $batch['batch_no'] . '_' . $kind . '.csv"');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($out as $r) {
            fputcsv($fh, $r);
        }
        exit;
    }
    $copy = coop_print_gate('dist.register', 'dist_batch', (int)$batch['id'], $batch['batch_no'], $pageKey, 'distribution register');
    if ($copy === null) {
        exit;
    }
    layout_start($batch['batch_no'], ['bare' => true]);
    ?>
    <div class="doc coop-doc"><?= coop_copy_mark($copy) ?>
      <?= coop_print_head(mb_strtoupper($label) . ' REGISTER', $batch['batch_no'], $batch['date_to'], ['Status' => ucfirst($batch['status'])]) ?>
      <p class="small my-2">Period <?= h(fdate($batch['date_from'])) ?> to <?= h(fdate($batch['date_to'])) ?>; <?= $batch['method'] === 'rate' ? h(Dec::fix($batch['rate'], 4)) . ' % of the ' . mb_strtolower(Distribution::basisLabel($batch)) : 'pool of ' . cm($batch['pool']) . ' shared by the ' . ($kind === 'isc' ? 'balances' : mb_strtolower(Distribution::basisLabel($batch))) ?>; <?= h(mb_strtolower(Distribution::CREDIT_MODES[$batch['credit_mode']] ?? '')) ?>.<?= $batch['resolution_ref'] ? ' Resolution: ' . h($batch['resolution_ref']) . '.' : '' ?></p>
      <table class="table table-sm doc-table"><thead><tr><th>Member</th><th>Account</th><th class="num">Basis</th><th class="num">Computed</th><th class="num">Adjustment</th><th class="num">Net</th></tr></thead><tbody>
      <?php foreach ($lines as $l): if ((int)$l['is_excluded']) { continue; } ?><tr><td><?= h($l['full_name']) ?> <span class="small"><?= h($l['member_no']) ?></span></td><td><?= h((string)$l['account_no']) ?></td><td class="num"><?= cm($l['basis_amount']) ?></td>
        <td class="num"><?= cm($l['computed']) ?></td><td class="num"><?= cm($l['adjustment'], true) ?></td><td class="num"><?= cm($l['net_amount']) ?></td></tr><?php endforeach; ?>
      </tbody><?php $incl = array_filter($lines, fn($l) => (int)$l['is_excluded'] === 0); ?><tfoot><tr class="fw-bold"><td colspan="2">Total, <?= (int)$batch['members_count'] ?> member(s)</td><td class="num"><?= cm($batch['total_basis']) ?></td>
        <td class="num"><?= cm(Dec::sum(array_column($incl, 'computed'))) ?></td><td class="num"><?= cm(Dec::sum(array_column($incl, 'adjustment'))) ?></td><td class="num"><?= cm($batch['total_amount']) ?></td></tr></tfoot></table>
      <?php $ex = array_filter($lines, fn($l) => (int)$l['is_excluded'] === 1); if ($ex): ?><div class="small fw-semibold">Not included</div><table class="table table-sm doc-table"><tbody>
        <?php foreach ($ex as $l): ?><tr><td><?= h($l['full_name']) ?></td><td><?= h((string)$l['exclude_reason']) ?></td><td class="num"><?= cm($l['basis_amount']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
      <?= coop_signatures(['Prepared by' => (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $batch['created_by']]), 'Checked by' => Coop::setting('signatory_bookkeeper'), 'Approved by' => Coop::setting('signatory_gm')]) ?>
    </div><script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the form (new, or recompute a draft)
$editing = !$batch || (g('a') === 'edit' && in_array($batch['status'], ['draft', 'validated'], true));
if ($editing) {
    Access::require($pageKey, $batch ? 'edit' : 'create');
    $f = $batch ? (array)json_decode((string)$batch['filters_json'], true) : [];
    $period = gint('period') ? DB::row('SELECT * FROM dist_periods WHERE id = :p', ['p' => gint('period')]) : ($batch && $batch['period_id'] ? DB::row('SELECT * FROM dist_periods WHERE id = :p', ['p' => $batch['period_id']]) : ($periods[0] ?? null));
    $usedPool = $period ? Dec::of(DB::value("SELECT COALESCE(SUM(total_amount),0) FROM dist_batches WHERE period_id = :p AND kind = :k AND status IN ('approved','posted') AND id <> :x", ['p' => $period['id'], 'k' => $kind, 'x' => $batch['id'] ?? 0])) : '0';
    $rec = $batch ?: ['period_id' => $period['id'] ?? null, 'date_from' => $period['period_from'] ?? date('Y-01-01', strtotime('-1 year')), 'date_to' => $period['period_to'] ?? date('Y-12-31', strtotime('-1 year')),
        'method' => 'pool', 'pool' => $period ? Dec::sub($kind === 'isc' ? $period['isc_amount'] : $period['pr_amount'], $usedPool, 2) : '0', 'rate' => '0', 'basis' => Coop::setting('isc_basis', 'adb'),
        'credit_mode' => 'savings', 'description' => $label . ($period ? ' ' . $period['fiscal_year'] : '')];
    $savProducts = Savings::productOptions(['ordinary', 'special']);
    $incomeAccts = Accts::ofType(['Income', 'Other Income']);
    $selTypes = is_post() ? array_map('intval', (array)($_POST['member_types'] ?? [])) : array_map('intval', (array)($f['member_types'] ?? []));
    $selIncome = is_post() ? array_map('intval', (array)($_POST['income_accounts'] ?? [])) : array_map('intval', (array)($f['income_accounts'] ?? [Accts::get('loan_interest_income')]));
    coop_layout($batch ? 'Recompute ' . $batch['batch_no'] : 'New ' . mb_strtolower($label) . ' batch', ['actions' => btn_link($batch ? url($pageKey, ['id' => $batch['id']]) : url('dist.batches'), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" data-once<?= $batch ? ' data-confirm="Recompute the batch? Members removed by hand stay removed." data-confirm-ok="Recompute"' : '' ?>>
      <?= csrf_field() ?><input type="hidden" name="action" value="compute">
      <div class="row g-3">
        <div class="col-xl-6"><div class="card mb-3"><div class="card-header">Computation</div><div class="card-body row g-2" data-no-combo>
          <?= select_field('period_id', 'Fiscal year allocation', $periodOpts, fv('period_id', $rec), ['class' => 'col-12', 'blank' => 'None (not limited by an allocation)']) ?>
          <?php if ($period): ?><div class="col-12 small text-muted">Set aside for <?= $kind === 'isc' ? 'interest on share capital' : 'patronage refund' ?>: <?= cm($kind === 'isc' ? $period['isc_amount'] : $period['pr_amount']) ?>; already used by approved or posted batches: <?= cm($usedPool) ?>.</div><?php endif; ?>
          <?= input_field('description', 'Description', fv('description', $rec), ['class' => 'col-12']) ?>
          <?= input_field('date_from', $kind === 'isc' ? 'Balances from' : 'Patronage from', fv('date_from', $rec), ['class' => 'col-6', 'type' => 'date', 'required' => true]) ?>
          <?= input_field('date_to', 'to', fv('date_to', $rec), ['class' => 'col-6', 'type' => 'date', 'required' => true]) ?>
          <?php if ($kind === 'isc'): ?><?= select_field('basis', 'Basis', ['adb' => 'Average daily balance', 'ending' => 'Ending balance'], fv('basis', $rec), ['class' => 'col-md-6']) ?><?php endif; ?>
          <?php if ($kind === 'pr'): ?><?= select_field('pr_basis', 'Patronage measured by', Distribution::PR_BASES, fv('pr_basis', null, in_array($rec['basis'] ?? '', ['purchases', 'deliveries'], true) ? $rec['basis'] : 'income'), ['class' => 'col-12', 'extra' => 'id="prBasis"']) ?><?php endif; ?>
          <?= select_field('method', 'Method', ['pool' => 'Share an amount in proportion', 'rate' => 'Apply a rate'], fv('method', $rec), ['class' => 'col-md-6', 'extra' => 'id="dMethod"']) ?>
          <div class="col-md-6" data-toggle-by="#dMethod" data-show-when="pool"><?= str_replace('col-md-4', '', coop_money('pool', 'Amount to distribute', fv('pool', $rec), ['class' => ''])) ?></div>
          <div class="col-md-6" data-toggle-by="#dMethod" data-show-when="rate"><?= str_replace('col-md-4', '', input_field('rate', 'Rate %', Dec::fix(fv('rate', $rec, '0'), 4), ['class' => '', 'extra' => 'inputmode="decimal"'])) ?></div>
          <?= select_field('credit_mode', 'Credit to', Distribution::CREDIT_MODES, fv('credit_mode', $rec), ['class' => 'col-md-6', 'extra' => 'id="dCredit"']) ?>
          <div class="col-md-6" data-toggle-by="#dCredit" data-show-when="savings,auto"><?= str_replace('col-md-4', '', select_field('credit_product_id', 'Savings product', $savProducts, fv('credit_product_id', $rec), ['class' => '', 'blank' => ''])) ?></div>
          <?= input_field('memo', 'Memo', fv('memo', $rec), ['class' => 'col-12']) ?>
        </div></div></div>
        <div class="col-xl-6"><div class="card mb-3"><div class="card-header">Members covered</div><div class="card-body row g-2" data-no-combo>
          <?= select_field('branch_id', 'Branch', Coop::branchOptions(), $f['branch_id'] ?? (pint('branch_id') ?: null), ['class' => 'col-md-6', 'blank' => 'All branches']) ?>
          <?= select_field('cluster_id', 'Cluster', Members::clusters(), $f['cluster_id'] ?? (pint('cluster_id') ?: null), ['class' => 'col-md-6', 'blank' => 'All clusters']) ?>
          <?= select_field('segment_id', 'Segment', Members::segments(), $f['segment_id'] ?? (pint('segment_id') ?: null), ['class' => 'col-md-6', 'blank' => 'All segments']) ?>
          <?php if ($kind === 'isc'): ?><?= select_field('product_id', 'Share capital product', Savings::productOptions(['share_capital']), $f['product_id'] ?? (pint('product_id') ?: null), ['class' => 'col-md-6', 'blank' => 'Every share capital product']) ?><?php endif; ?>
          <div class="col-md-6"><label class="form-label">Member types</label><select class="form-select form-select-sm" name="member_types[]" multiple size="4"><?php foreach (Members::typeOptions() as $tid => $tn): ?><option value="<?= (int)$tid ?>"<?= in_array((int)$tid, $selTypes, true) ? ' selected' : '' ?>><?= h($tn) ?></option><?php endforeach; ?></select><div class="form-text">None selected: every type.</div></div>
          <?php if ($kind === 'pr'): ?><div class="col-md-6" data-toggle-by="#prBasis" data-show-when="income"><label class="form-label">Income accounts (patronage)</label><select class="form-select form-select-sm" name="income_accounts[]" multiple size="6"><?php foreach ($incomeAccts as $aid => $an): ?><option value="<?= (int)$aid ?>"<?= in_array((int)$aid, $selIncome, true) ? ' selected' : '' ?>><?= h($an) ?></option><?php endforeach; ?></select><div class="form-text">The member's share is the income these accounts earned from them in the period, in lines of business whose member transactions count, on the goods and services that count.</div></div>
          <div class="col-md-6 small text-muted" data-toggle-by="#prBasis" data-show-when="purchases,deliveries">Counted on the goods and services marked "counts for patronage refund", in lines of business whose member transactions count (Members &amp; Others &gt; Lines of Business). A member is recognized through the party, also when buying or selling as a customer or supplier.</div><?php endif; ?>
          <?= coop_money('amount_min', 'Basis from', $f['amount_min'] ?? p('amount_min'), ['class' => 'col-6 col-md-3']) ?>
          <?= coop_money('amount_max', 'to', $f['amount_max'] ?? p('amount_max'), ['class' => 'col-6 col-md-3']) ?>
          <?= check_field('exclude_delinquent', 'Leave out members with a past-due loan', !empty($f['exclude_delinquent']) || p('exclude_delinquent') === '1', 'col-12') ?>
        </div></div></div>
      </div>
      <div class="sticky-actions dist-compute-bar"><button class="btn btn-primary btn-sm"><i class="bi bi-calculator"></i> <?= $batch ? 'Recompute' : 'Compute' ?></button> <a class="btn btn-outline-secondary btn-sm" href="<?= h($batch ? url($pageKey, ['id' => $batch['id']]) : url('dist.batches')) ?>">Cancel</a>
        <span class="small text-muted">Computing posts nothing: the list of members and their amounts opens for your review first.</span></div>
    </form>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ the batch: the computation for review, then the review, approval and posting
$st = $batch['status'];
$open = in_array($st, ['draft', 'validated'], true);
$canEdit = $open && Access::can($pageKey, 'edit');
$actions = [];
if ($open && Access::can($pageKey, 'edit')) {
    $actions[] = btn_link(url($pageKey, ['id' => $batch['id'], 'a' => 'edit']), 'Recompute', 'btn-outline-primary', 'calculator');
}
if (Access::can($pageKey, 'print')) {
    $actions[] = btn_link(url($pageKey, ['id' => $batch['id'], 'a' => 'print']), 'Print register', 'btn-outline-secondary', 'printer');
    $actions[] = btn_link(url($pageKey, ['id' => $batch['id'], 'a' => 'csv']), 'CSV', 'btn-outline-secondary', 'filetype-csv');
}
// a computation never posted can be deleted (an approved one also needs the Approve right)
$canDelete = Distribution::deletable($batch) && Access::can($pageKey, 'delete') && ($st !== 'approved' || Access::can($pageKey, 'approve'));
if ($canDelete) {
    $actions[] = '<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#distDelModal"><i class="bi bi-trash3"></i> Delete</button>';
}
$actions[] = btn_link(url('dist.batches'), 'Batches', 'btn-outline-secondary', 'arrow-left');
coop_layout($label . ' ' . $batch['batch_no'], ['actions' => implode(' ', $actions), 'js' => ['js/dist_batch.js']]);
$txn = $batch['txn_id'] ? Txn::load((int)$batch['txn_id']) : null;
$steps = ['draft' => 'Review the list', 'validated' => 'Reviewed', 'approved' => 'Approved', 'posted' => 'Posted'];
$order = array_keys($steps);
$pos = array_search($st, $order, true);
// what each member owes the cooperative, shown beside the amount (members with a past-due loan or on hold stand out)
$obl = Distribution::obligations(array_column($lines, 'member_id'), today());
$inc = array_values(array_filter($lines, fn($l) => (int)$l['is_excluded'] === 0));
$removed = count($lines) - count($inc);
$withObl = count(array_filter($inc, fn($l) => Distribution::hasObligation($obl[(int)$l['member_id']] ?? [])));
$reasonSelect = function (string $id) {
    return '<select class="form-select form-select-sm" name="reason_pick" id="' . h($id) . '" data-no-combo aria-label="Reason">' . options(Distribution::REMOVE_REASONS, 'delinquent') . '</select>';
};
?>
<div class="app-steps"><?php foreach ($steps as $k => $lbl): $i = array_search($k, $order, true); ?><span class="<?= $pos !== false && $i < $pos ? 'is-done' : ($i === $pos ? 'is-now' : '') ?>"><?= h($lbl) ?></span><?php endforeach; ?>
  <?php if ($st === 'cancelled'): ?><span class="is-stop">Reversed</span><?php endif; ?></div>
<?php if ($open): ?>
<div class="dist-review-note">
  <i class="bi bi-clipboard-check" aria-hidden="true"></i>
  <div><strong>Nothing is posted yet.</strong> This is the computation of the <?= h(mb_strtolower($label)) ?> for your review: <?= count($inc) ?> member(s), <?= cm($batch['total_amount']) ?>.
    Remove from the list any member who should not receive it, such as a member with a past-due loan or another obligation to the cooperative, with the reason<?= $batch['method'] === 'pool' ? '; the amount is then shared again among the members left' : '' ?>.
    When the list is right, finish the review<?= $st === 'validated' ? ' (done)' : '' ?>; the batch is then approved and posted.
    <?php if ($st === 'draft' && Access::can($pageKey, 'edit') && $inc): ?><div class="mt-2"><button class="btn btn-primary btn-sm" form="distValidate"><i class="bi bi-list-check"></i> Finish the review</button>
      <a class="btn btn-link btn-sm" href="#distNext">What happens next</a></div><?php endif; ?></div>
</div>
<?php endif; ?>
<div class="coop-tiles">
  <div class="coop-tile"><div class="t-label">Members on the list</div><div class="t-value"><?= (int)$batch['members_count'] ?></div><div class="t-sub"><?= $removed ?> removed</div></div>
  <div class="coop-tile"><div class="t-label"><?= $kind === 'isc' ? ($batch['basis'] === 'ending' ? 'Ending balances' : 'Average daily balances') : h(Distribution::basisLabel($batch)) ?></div><div class="t-value"><?= cm($batch['total_basis']) ?></div></div>
  <div class="coop-tile is-ok"><div class="t-label">To distribute</div><div class="t-value"><?= cm($batch['total_amount']) ?></div><div class="t-sub"><?= $batch['method'] === 'rate' ? h(Dec::fix($batch['rate'], 4)) . ' %' : 'pool ' . cm($batch['pool']) . (Dec::isZero($batch['rounding_diff']) ? '' : ', rounding ' . cm($batch['rounding_diff'])) ?></div></div>
  <div class="coop-tile<?= $withObl ? ' is-warn' : '' ?>"><div class="t-label">With a past-due loan or on hold</div><div class="t-value"><?= $withObl ?></div><div class="t-sub">of the members on the list</div></div>
</div>
<div class="card dist-review mb-3" data-dist-review data-basis-title="<?= h($kind === 'isc' ? Distribution::basisLabel($batch) : 'Patronage: ' . mb_strtolower(Distribution::basisLabel($batch))) ?>">
      <div class="card-header dist-review-head">
        <span class="fw-semibold">Members</span>
        <div class="dist-review-tools">
          <input type="search" class="form-control form-control-sm" placeholder="Find a member" aria-label="Find a member" data-dist-find>
          <?php if ($withObl): ?><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="distOblOnly" data-dist-obl><label class="form-check-label" for="distOblOnly">Only those with a past-due loan or on hold (<?= $withObl ?>)</label></div><?php endif; ?>
        </div>
      </div>
      <?php if ($canEdit && $inc): ?>
      <form method="post" id="distBulk" class="dist-bulk" data-once data-dist-bulk hidden>
        <?= csrf_field() ?><input type="hidden" name="action" value="remove">
        <span class="dist-bulk-n" data-dist-count>0 ticked</span>
        <?= $reasonSelect('distBulkPick') ?>
        <input class="form-control form-control-sm" name="reason" placeholder="Details (needed for Other reason)" aria-label="Details of the reason">
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-dash"></i> Remove from the list</button>
      </form>
      <?php endif; ?>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 stack-sm dist-table">
        <thead><tr><?php if ($canEdit): ?><th class="dist-chk"><input class="form-check-input" type="checkbox" data-dist-all aria-label="Tick every member shown"></th><?php endif; ?>
          <th>Member</th><th class="num">Basis</th><th class="num">Computed</th><th class="num">Adjustment</th><th class="num">Net</th><th>Owes the cooperative</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $ex = (int)$l['is_excluded'] === 1; $o = $obl[(int)$l['member_id']] ?? []; $hasO = Distribution::hasObligation($o); ?>
          <tr class="<?= $ex ? 'dist-removed' : '' ?><?= $hasO ? ' dist-has-obl' : '' ?>" data-text="<?= h(mb_strtolower($l['full_name'] . ' ' . $l['member_no'] . ' ' . $l['account_no'])) ?>" data-obl="<?= $hasO ? '1' : '0' ?>" data-ex="<?= $ex ? '1' : '0' ?>" data-sum="<?= h(Dec::fix($l['basis_amount']) . '|' . Dec::fix($l['computed']) . '|' . Dec::fix($l['adjustment']) . '|' . Dec::fix($l['net_amount'])) ?>">
            <?php if ($canEdit): ?><td class="dist-chk"><?php if (!$ex): ?><input class="form-check-input" type="checkbox" name="line_ids[]" value="<?= (int)$l['id'] ?>" form="distBulk" data-dist-line aria-label="Tick <?= h($l['full_name']) ?>"><?php endif; ?></td><?php endif; ?>
            <td data-label="Member"><?= safe_link('coop.member', ['id' => $l['member_id']], $l['full_name']) ?><div class="small text-muted"><?= h($l['member_no']) ?><?= $l['account_no'] ? ', account ' . h((string)$l['account_no']) : '' ?></div>
              <?php if ($ex): ?><div class="small dist-why">Removed: <?= h((string)$l['exclude_reason']) ?></div><?php elseif ($l['exclude_reason']): ?><div class="small text-muted">Adjusted: <?= h((string)$l['exclude_reason']) ?></div><?php endif; ?></td>
            <td data-label="Basis" class="num"><button type="button" class="dist-basis" data-dist-basis="<?= h(url($pageKey, ['id' => $batch['id'], 'a' => 'basis', 'line' => $l['id']])) ?>" data-who="<?= h($l['full_name']) ?>" aria-haspopup="dialog" aria-expanded="false" aria-label="<?= h('Basis ' . Dec::money($l['basis_amount']) . ' of ' . $l['full_name'] . ': show how it is worked out') ?>"><?= cm($l['basis_amount']) ?></button></td>
            <td data-label="Computed" class="num"><?= $ex ? '' : cm($l['computed']) ?></td>
            <td data-label="Adjustment" class="num"><?= cm($l['adjustment'], true) ?></td>
            <td data-label="Net" class="num fw-semibold"><?= $ex ? '<span class="text-muted fw-normal">removed</span>' : cm($l['net_amount']) ?></td>
            <td data-label="Owes the cooperative" class="small dist-obl"><?php
              $bits = [];
              if (Dec::isPos($o['past_due'] ?? '0')) {
                  $bits[] = '<span class="text-danger">Past due ' . cm($o['past_due']) . '</span> <span class="text-muted">(' . h(implode(', ', $o['past_due_loans'])) . ')</span>';
              }
              if (($o['hold'] ?? '') !== '') {
                  $bits[] = '<span class="text-danger">On hold: ' . h($o['hold']) . '</span>';
              }
              if (Dec::isPos($o['loan_balance'] ?? '0')) {
                  $bits[] = '<span class="text-muted">Loans ' . cm($o['loan_balance']) . '</span>';
              }
              if (Dec::isPos($o['unpaid_capital'] ?? '0')) {
                  $bits[] = '<span class="text-muted">Subscription unpaid ' . cm($o['unpaid_capital']) . '</span>';
              }
              echo $bits ? implode('<br>', $bits) : '<span class="text-muted">None</span>'; ?></td>
            <?php if ($canEdit): ?><td class="text-end text-nowrap dist-acts">
              <?php if ($ex): ?>
                <form method="post" class="d-inline" data-once><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-link btn-sm p-0">Put back</button></form>
              <?php else: ?>
                <button type="button" class="btn btn-link btn-sm p-0 text-danger" data-bs-toggle="collapse" data-bs-target="#rm<?= (int)$l['id'] ?>" aria-expanded="false">Remove</button>
                <span class="text-muted">|</span>
                <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#ad<?= (int)$l['id'] ?>" aria-expanded="false">Adjust</button>
              <?php endif; ?></td><?php endif; ?>
          </tr>
          <?php if ($canEdit && !$ex): ?>
          <tr class="collapse dist-form-row" id="rm<?= (int)$l['id'] ?>"><td colspan="8"><form method="post" class="dist-inline" data-once><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
            <span class="small fw-semibold">Remove <?= h($l['full_name']) ?> from the list</span>
            <?= $reasonSelect('rp' . (int)$l['id']) ?>
            <input class="form-control form-control-sm" name="reason" placeholder="Details (needed for Other reason)" aria-label="Details of the reason">
            <button class="btn btn-sm btn-outline-danger">Remove</button></form></td></tr>
          <tr class="collapse dist-form-row" id="ad<?= (int)$l['id'] ?>"><td colspan="8"><form method="post" class="dist-inline" data-once><?= csrf_field() ?><input type="hidden" name="action" value="line"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="exclude" value="0">
            <span class="small fw-semibold">Adjust the amount of <?= h($l['full_name']) ?></span>
            <input class="form-control form-control-sm text-end dist-adj" name="adjustment" placeholder="+ or - amount" inputmode="decimal" value="<?= Dec::isZero($l['adjustment']) ? '' : h(Dec::fix($l['adjustment'])) ?>" aria-label="Adjustment">
            <input class="form-control form-control-sm" name="reason" placeholder="Reason (required)" required aria-label="Reason of the adjustment" value="<?= h((string)$l['exclude_reason']) ?>">
            <button class="btn btn-sm btn-outline-primary">Save</button></form></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$lines): ?><tr><td colspan="<?= $canEdit ? 8 : 6 ?>" class="text-muted">No member qualifies with these filters.</td></tr><?php endif; ?>
        <tr class="dist-none" hidden><td colspan="<?= $canEdit ? 8 : 6 ?>" class="text-muted">No member on the list matches.</td></tr>
        </tbody>
        <?php if ($lines):
            // the totals of the members on the list (what is distributed), the basis of those removed, and of the members shown by the search
            $exl = array_values(array_filter($lines, fn($l) => (int)$l['is_excluded'] === 1));
            $tail = '<td class="dist-foot-tail"' . ($canEdit ? ' colspan="2"' : '') . '></td>'; ?>
        <tfoot class="dist-foot">
          <tr class="dist-total"><?php if ($canEdit): ?><td class="dist-chk"></td><?php endif; ?>
            <td class="dist-foot-k">Total<div class="small">of the <?= count($inc) ?> member(s) on the list</div></td>
            <td data-label="Basis" class="num"><?= cm(Dec::sum(array_column($inc, 'basis_amount'))) ?></td>
            <td data-label="Computed" class="num"><?= cm(Dec::sum(array_column($inc, 'computed'))) ?></td>
            <td data-label="Adjustment" class="num"><?= cm(Dec::sum(array_column($inc, 'adjustment'))) ?></td>
            <td data-label="Net" class="num"><?= cm(Dec::sum(array_column($inc, 'net_amount'))) ?></td><?= $tail ?></tr>
          <?php if ($exl): ?>
          <tr class="dist-total-ex"><?php if ($canEdit): ?><td class="dist-chk"></td><?php endif; ?>
            <td class="dist-foot-k">Removed<div class="small"><?= count($exl) ?> member(s), not given any amount</div></td>
            <td data-label="Basis" class="num"><?= cm(Dec::sum(array_column($exl, 'basis_amount'))) ?></td><td></td><td></td><td></td><?= $tail ?></tr>
          <?php endif; ?>
          <tr class="dist-total-shown" hidden data-dist-shown><?php if ($canEdit): ?><td class="dist-chk"></td><?php endif; ?>
            <td class="dist-foot-k">Shown<div class="small" data-k="n"></div></td>
            <td data-label="Basis" class="num" data-k="b"></td><td data-label="Computed" class="num" data-k="c"></td><td data-label="Adjustment" class="num" data-k="a"></td><td data-label="Net" class="num" data-k="t"></td><?= $tail ?></tr>
        </tfoot>
        <?php endif; ?>
        </table></div>
</div>
<div class="row g-3" id="distNext">
  <div class="col-lg-5 order-lg-2">
    <div class="card mb-3"><div class="card-body"><?= coop_kv(['Batch' => '<strong>' . h($batch['batch_no']) . '</strong>', 'Description' => h((string)$batch['description']), 'Period' => h(fdate($batch['date_from']) . ' to ' . fdate($batch['date_to'])),
        'Fiscal year' => $batch['period_id'] ? safe_link('dist.surplus', ['id' => $batch['period_id']], (string)DB::value('SELECT fiscal_year FROM dist_periods WHERE id = :p', ['p' => $batch['period_id']])) : '',
        'Status' => $st === 'draft' ? badge('In review, not posted', 'primary') : ($st === 'validated' ? badge('Reviewed, not posted', 'info') : coop_status($st === 'cancelled' ? 'voided' : $st)),
        'Resolution' => h((string)$batch['resolution_ref']),
        'Approved' => $batch['approved_at'] ? h(fdatetime($batch['approved_at'])) . ' by ' . h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $batch['approved_by']])) : '',
        'Voucher' => $txn ? '<a href="' . h(Txn::url($txn)) . '">' . h($txn['txn_no']) . '</a>' : '', 'Memo' => h((string)$batch['memo'])]) ?></div></div>
  </div>
  <div class="col-lg-7 order-lg-1">
    <?php if ($st === 'draft' && Access::can($pageKey, 'edit')): ?>
      <form method="post" class="card mb-3" id="distValidate" data-once><div class="card-header">1. Finish the review</div><div class="card-body"><?= csrf_field() ?><input type="hidden" name="action" value="validate">
        <p class="small text-muted">When the list is right. It checks that every member left on the list has the account to credit and that the year's amount set aside is not exceeded. Removing or adjusting a member afterwards puts the list back in review.</p>
        <button class="btn btn-primary btn-sm"><i class="bi bi-list-check"></i> Finish the review</button></div></form>
    <?php endif; ?>
    <?php if ($st === 'validated'): ?>
      <?php if (Access::can($pageKey, 'approve')): ?>
      <form method="post" class="card mb-3" data-once data-confirm="Approve and lock the batch of <?= h(Dec::money($batch['total_amount'])) ?> for <?= (int)$batch['members_count'] ?> member(s)?" data-confirm-ok="Approve"><div class="card-header">2. Approve</div><div class="card-body"><?= csrf_field() ?><input type="hidden" name="action" value="approve">
        <?= input_field('resolution', 'Board / General Assembly resolution', '', ['class' => 'mb-2', 'required' => true]) ?>
        <div class="small text-muted mb-2">Approved by someone other than the user who computed it. Approving locks the list.</div><button class="btn btn-success btn-sm"><i class="bi bi-check2-circle"></i> Approve</button></div></form>
      <?php else: ?>
      <div class="card mb-3"><div class="card-header">2. Approve</div><div class="card-body small text-muted">The review is finished. A user with the Approve right on this page (not the one who computed it) records the resolution and approves the batch; it is then posted.</div></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($st === 'approved' && Access::can($pageKey, 'create')): ?>
      <form method="post" class="card mb-3" data-once data-confirm="Post <?= h(Dec::money($batch['total_amount'])) ?> to <?= (int)$batch['members_count'] ?> member(s)? This is done once." data-confirm-ok="Post"><div class="card-header">3. Post</div><div class="card-body row g-2"><?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="post">
        <?= input_field('post_date', 'Posting date', today(), ['class' => 'col-12', 'type' => 'date']) ?>
        <?= select_field('debit_account_id', 'Charged to', Accts::all(), Accts::get($kind === 'isc' ? 'isc_payable' : 'pr_payable'), ['class' => 'col-12']) ?>
        <div class="col-12 small text-muted">Normally the payable set up by the net surplus allocation. Each member on the list is credited: <?= h(mb_strtolower(Distribution::CREDIT_MODES[$batch['credit_mode']] ?? '')) ?>.</div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-journal-check"></i> Post to members</button></div></div></form>
    <?php endif; ?>
    <?php if ($st === 'posted' && Access::can($pageKey, 'approve')): ?>
      <form method="post" class="card" data-once data-confirm="Reverse the posted batch? Each member credit comes back out." data-confirm-ok="Reverse"><div class="card-header">Reverse</div><div class="card-body row g-2"><?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="reverse">
        <?= input_field('rev_date', 'Date', today(), ['class' => 'col-12', 'type' => 'date']) ?>
        <?= input_field('reason', 'Reason', '', ['class' => 'col-12', 'required' => true]) ?>
        <div class="col-12"><button class="btn btn-outline-danger btn-sm">Reverse the batch</button></div></div></form>
    <?php endif; ?>
  </div>
</div>
<?php if ($canDelete): ?><?= coop_dist_delete_modal('distDelModal', $batch) ?><?php endif; ?>
<?php
layout_end();
