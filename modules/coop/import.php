<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.import';

if (g('a') === 'sample') {
    $kind = isset(CoopImport::KINDS[g('kind')]) ? (string)g('kind') : 'members';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="opening_' . $kind . '_sample.csv"');
    echo CoopImport::sample($kind);
    exit;
}

$result = null;
$token = null;
handle_post(function () use ($pageKey, &$result, &$token) {
    $action = (string)p('action');
    if ($action === 'check') {
        Access::require($pageKey, 'create');
        $kind = (string)p('kind');
        $asOf = (string)p('as_of');
        $text = (string)p('data');
        $file = '';
        $up = $_FILES['file'] ?? null;
        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int)$up['size'] > 10 * 1024 * 1024) {
                fail('The file is larger than 10 MB.');
            }
            if (!in_array(strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)), ['csv', 'txt', 'tsv'], true)) {
                fail('Upload a CSV file (save the spreadsheet as CSV first).');
            }
            $text = (string)file_get_contents($up['tmp_name']);
            $file = (string)$up['name'];
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $rows = CoopImport::parse($text);
        $result = CoopImport::check($kind, $rows, $asOf);
        $token = bin2hex(random_bytes(8));
        $_SESSION['coop_import'] = [$token => ['kind' => $kind, 'as_of' => $asOf, 'offset' => pint('offset_account_id'), 'rows' => $rows, 'file' => $file]];
        $result += ['kind' => $kind, 'as_of' => $asOf, 'file' => $file];
        return;
    }
    if ($action === 'run') {
        Access::require($pageKey, 'create');
        coop_once();
        $s = $_SESSION['coop_import'][(string)p('token')] ?? null;
        if (!$s) {
            fail('The checked file is no longer in your session. Check it again.');
        }
        $id = CoopImport::run($s['kind'], $s['rows'], $s['as_of'], $s['offset'] ?: null, $s['file'], $pageKey);
        unset($_SESSION['coop_import']);
        $imp = DB::row('SELECT * FROM coop_imports WHERE id = :id', ['id' => $id]);
        flash('success', 'Imported ' . $imp['rows_count'] . ' row(s)' . ($imp['txn_id'] ? ' with voucher ' . Txn::load((int)$imp['txn_id'])['txn_no'] : '') . '. Check the SL vs GL reconciliation report next.');
        redirect(url($pageKey));
    }
    fail('Unknown action.');
});

$history = DB::all('SELECT i.*, t.txn_no, t.txn_type, a.code AS acode, a.name AS aname, u.full_name FROM coop_imports i LEFT JOIN transactions t ON t.id = i.txn_id LEFT JOIN accounts a ON a.id = i.offset_account_id LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC LIMIT 50');
$kinds = array_map(fn($k) => $k[0], CoopImport::KINDS);
coop_layout('Opening Balances Import', ['actions' => page_link('rpt.coop_sl_gl', [], 'SL vs GL reconciliation', 'btn-outline-secondary', 'check2-square')]);
?>
<?php if ($result): $rows = $result['rows']; $bad = array_filter($rows, fn($r) => $r['errors']); $cols = array_map(fn($c) => rtrim($c, '*'), CoopImport::KINDS[$result['kind']][1]); ?>
  <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span><?= h($kinds[$result['kind']]) ?> as of <?= h(fdate($result['as_of'])) ?><?= $result['file'] ? ', ' . h($result['file']) : '' ?></span>
    <span class="<?= $bad ? 'text-danger' : 'text-success' ?> fw-semibold"><?= count($rows) ?> row(s), <?= count($bad) ?> with errors</span></div>
    <div class="table-responsive" style="max-height:420px"><table class="table table-sm"><thead><tr><th>Line</th><?php foreach (array_slice($cols, 0, 7) as $c): ?><th><?= h($c) ?></th><?php endforeach; ?><th>Check</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= $r['errors'] ? 'table-danger' : '' ?>"><td><?= (int)$r['_line'] ?></td><?php foreach (array_slice($cols, 0, 7) as $c): ?><td class="small"><?= h((string)($r[$c] ?? '')) ?></td><?php endforeach; ?>
      <td class="small"><?= $r['errors'] ? h(implode('; ', $r['errors'])) : '<span class="text-success">ok</span>' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($result['gl']): ?><div class="card-body border-top"><div class="small fw-semibold mb-1">General ledger effect (control accounts; the offset takes the difference)</div><table class="table table-sm mb-0" style="max-width:640px"><tbody>
      <?php foreach ($result['gl'] as $acct => $amt): ?><tr><td><?= h(Coop::accountLabel((int)$acct)) ?></td><td class="num"><?= Dec::isPos($amt) ? 'Dr ' . cm($amt) : 'Cr ' . cm(Dec::abs($amt)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <div class="card-body border-top">
      <?php if (!$result['ok']): ?><div class="text-danger small">Correct the rows marked in red in the file and check it again. Nothing has been saved.</div>
      <?php elseif (!Access::isAdmin() && !Access::can($pageKey, 'approve')): ?><div class="small text-muted">The file is ready. Importing needs the Approve right on this page.</div>
      <?php else: ?>
        <form method="post" data-once data-confirm="Import <?= count($rows) ?> row(s) as of <?= h(fdate($result['as_of'])) ?>? This cannot be voided; corrections are made with adjusting entries." data-confirm-ok="Import">
          <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="run"><input type="hidden" name="token" value="<?= h((string)$token) ?>">
          <button class="btn btn-primary btn-sm"><i class="bi bi-box-arrow-in-down"></i> Import <?= count($rows) ?> row(s)</button>
          <span class="small text-muted ms-2">All rows are saved together, or none.</span></form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-5">
    <?php if (Access::can($pageKey, 'create')): ?>
    <form method="post" enctype="multipart/form-data" class="card mb-3"><div class="card-header">Check a file</div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="check">
      <?= select_field('kind', 'Import', $kinds, (string)p('kind', 'members'), ['class' => 'col-md-7', 'extra' => 'id="impKind" data-no-combo']) ?>
      <?= input_field('as_of', 'Cut-off date', (string)p('as_of', date('Y-m-d', strtotime('last day of last month'))), ['class' => 'col-md-5', 'type' => 'date', 'required' => true]) ?>
      <div class="col-12" data-toggle-by="#impKind" data-show-when="savings,time_deposits,loans"><?= str_replace('col-md-4', '', select_field('offset_account_id', 'Offset account', Accts::all(), pint('offset_account_id') ?: Accts::get('opening_equity'), ['class' => ''])) ?>
        <div class="form-text">Normally Opening Balance Equity, cleared when the opening trial balance is entered.</div></div>
      <div class="col-12"><label class="form-label">CSV file</label><input class="form-control form-control-sm" type="file" name="file" accept=".csv,.txt,.tsv,text/csv"></div>
      <div class="col-12"><label class="form-label">or paste the rows (first line is the header)</label><textarea class="form-control form-control-sm font-monospace" name="data" rows="6"><?= h((string)p('data')) ?></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-list-check"></i> Check</button> <span class="small text-muted">Nothing is saved by checking.</span></div>
    </div></form>
    <?php endif; ?>
    <div class="card"><div class="card-header">Columns</div><div class="card-body small">
      <?php foreach (CoopImport::KINDS as $k => [$label, $cols, $help]): ?>
        <div class="mb-3"><div class="fw-semibold"><?= h($label) ?> <a class="ms-1" href="<?= h(url($pageKey, ['a' => 'sample', 'kind' => $k])) ?>">sample</a></div>
          <div><?= implode(', ', array_map(fn($c) => str_ends_with($c, '*') ? '<strong>' . h(rtrim($c, '*')) . '</strong>' : h($c), $cols)) ?></div><div class="text-muted"><?= h($help) ?></div></div>
      <?php endforeach; ?>
      <div class="text-muted">Bold columns are required. Import in this order: members, then savings and share capital, time deposits and loans.</div>
    </div></div>
  </div>
  <div class="col-xl-7"><div class="card"><div class="card-header">Imports done</div><div class="table-responsive"><table class="table table-sm"><thead><tr><th>When</th><th>What</th><th>Cut-off</th><th class="num">Rows</th><th class="num">Amount</th><th>Voucher</th></tr></thead><tbody>
    <?php foreach ($history as $i): ?><tr><td class="small"><?= h(fdatetime($i['created_at'])) ?><div class="text-muted"><?= h((string)$i['full_name']) ?></div></td><td><?= h($kinds[$i['kind']] ?? $i['kind']) ?><div class="small text-muted"><?= h((string)$i['file_name']) ?></div></td>
      <td class="small"><?= h(fdate($i['as_of'])) ?></td><td class="num"><?= (int)$i['rows_count'] ?></td><td class="num"><?= $i['kind'] === 'members' ? '' : cm($i['total']) ?></td>
      <td class="small"><?= $i['txn_id'] ? '<a href="' . h(Txn::url(['id' => $i['txn_id'], 'txn_type' => $i['txn_type']])) . '">' . h($i['txn_no']) . '</a><div class="text-muted">against ' . h($i['acode'] . ' ' . $i['aname']) . '</div>' : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$history): ?><tr><td colspan="6" class="text-muted">Nothing imported yet.</td></tr><?php endif; ?>
  </tbody></table></div></div></div>
</div>
<?php
layout_end();
