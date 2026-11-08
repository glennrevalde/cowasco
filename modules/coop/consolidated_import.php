<?php
declare(strict_types=1);

/**
 * Members & Others > Consolidated Import: the members with their savings accounts, loans, water-service account and
 * bill balances from one file (Import Members, Import Savings, Import Loans and Upload Bill Balances in one). The file is
 * sent in pieces and read into the database, its columns are matched to what they hold (the user may change it), every
 * member, savings account, loan and bill balance becomes a line of the review, checked; a line is corrected in place or
 * removed, and the lines are saved a few members at a time (app/ConsolidatedImport.php).
 */
require_once __DIR__ . '/_coop.php';
$pageKey = ConsolidatedImport::PAGE;
$ready = ConsolidatedImport::ready();
$canCreate = Access::can($pageKey, 'create');

/** The JSON answer of an action; a problem the user can correct comes back as its message */
$json = function (callable $fn): never {
    try {
        json_out($fn());
    } catch (UserError $e) {
        if (DB::inTransaction()) {
            DB::rollBack();
        }
        json_out(['error' => $e->getMessage()], 422);
    }
};
$batchOf = function (bool $forChange = true) use ($pageKey): array {
    if ($forChange) {
        Access::require($pageKey, 'create');
    }
    $b = ConsolidatedImport::batch((int)(p('id') ?: g('id')));
    if (!$b || $b['status'] === 'cancelled') {
        fail('That file is no longer open: read it again.');
    }
    return $b;
};

// ------------------------------------------------------------------ the templates
if (g('t') === 'template') {
    if (!$ready) {
        fail('Run the database update database/migrations/2027_04_25_consolidated_import.sql first.');
    }
    ConsolidatedImport::template(g('layout') === 'long' ? 'long' : 'wide', g('format') === 'csv' ? 'csv' : 'xlsx');
}

// ------------------------------------------------------------------ the actions of the page (JSON)
$a = (string)(p('a') ?: g('a'));
if ($ready && $a !== '' && in_array($a, ['create', 'chunk', 'parse', 'check', 'lines', 'edit', 'remove', 'restore', 'save', 'summary'], true)) {
    $json(function () use ($a, $pageKey, $batchOf) {
        @set_time_limit(600);
        switch ($a) {
            case 'create':
                Access::require($pageKey, 'create');
                $id = ConsolidatedImport::create((string)p('file_name'), (int)p('bytes'));
                return ['id' => $id, 'chunk' => ConsolidatedImport::CHUNK_BYTES];

            case 'chunk':
                $b = $batchOf();
                $up = $_FILES['data'] ?? null;
                if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    fail('A piece of the file did not arrive (the server refused it): read the file again.');
                }
                $have = ConsolidatedImport::storeChunk($b, (int)p('index'), (string)file_get_contents((string)$up['tmp_name']));
                return ['have' => $have];

            case 'parse':
                $b = ConsolidatedImport::parse($batchOf());
                Audit::log('import', 'coop', 'consolidated_import', (int)$b['id'], $b['batch_no'] . ' ' . $b['file_name'] . ': ' . $b['file_rows'] . ' row(s) read for review', ['page_key' => $pageKey]);
                return ['id' => (int)$b['id'], 'url' => url($pageKey, ['id' => $b['id']])];

            case 'check':
                $b = $batchOf();
                if (!in_array($b['status'], ['review', 'saving'], true)) {
                    fail('This file is saved: its lines are not checked again.');
                }
                $r = ConsolidatedImport::checkStep($b);
                return $r + ['summary' => ConsolidatedImport::summary(ConsolidatedImport::batch((int)$b['id']))];

            case 'summary':
                $b = $batchOf(false);
                return ['summary' => ConsolidatedImport::summary($b)];

            case 'lines':
                $b = $batchOf(false);
                $kind = (string)g('kind', 'member');
                return ConsolidatedImport::lines($b, $kind, ['bad' => g('bad') === '1', 'q' => (string)g('q'), 'status' => (string)g('status', 'pending')], (int)g('page', '1'));

            case 'edit':
                $b = $batchOf();
                return ConsolidatedImport::edit($b, (int)p('line'), (string)p('field'), (string)p('value'));

            case 'remove':
                $b = $batchOf();
                return ConsolidatedImport::remove($b, (int)p('line'));

            case 'restore':
                $b = $batchOf();
                return ConsolidatedImport::restore($b, array_map('intval', explode(',', (string)p('lines'))));

            case 'save':
                $b = $batchOf();
                $r = ConsolidatedImport::saveStep($b);
                return $r + ['summary' => ConsolidatedImport::summary(ConsolidatedImport::batch((int)$b['id']))];
        }
        fail('Unknown action.');
    });
}

// ------------------------------------------------------------------ the column choices, the values for the blank cells, cancelling
handle_post(function () use ($pageKey, $ready, $batchOf) {
    if (!$ready) {
        fail('Run the database update database/migrations/2027_04_25_consolidated_import.sql first.');
    }
    $action = (string)p('action');
    $b = $batchOf();
    if ($action === 'mapping') {
        $posted = [];
        foreach ((array)($_POST['map'] ?? []) as $i => $m) {
            $posted[(int)$i] = ['field' => (string)($m['field'] ?? ''), 'slot' => (int)($m['slot'] ?? 1)];
        }
        $b = ConsolidatedImport::setMapping($b, $posted);
        flash('success', 'The lines were made again from the columns chosen: they are being checked.');
        redirect(url($pageKey, ['id' => $b['id']]));
    }
    if ($action === 'options') {
        ConsolidatedImport::setOptions($b, $_POST);
        flash('success', 'The values for the blank cells are kept: every line is checked again with them.');
        redirect(url($pageKey, ['id' => $b['id']]));
    }
    if ($action === 'cancel') {
        ConsolidatedImport::cancel($b);
        flash('info', 'File ' . $b['batch_no'] . ' was closed. Nothing of it was saved.');
        redirect(url($pageKey));
    }
    fail('Unknown action.');
});

$statusText = ['reading' => 'Being read', 'review' => 'Under review', 'saving' => 'Being saved', 'saved' => 'Saved', 'cancelled' => 'Closed'];
$kindIcons = ['member' => 'person-vcard', 'savings' => 'piggy-bank', 'loan' => 'cash-coin', 'bill' => 'droplet-half'];
$b = $ready && gint('id') ? ConsolidatedImport::batch((int)gint('id')) : null;
if ($b && $b['status'] === 'cancelled') {
    $b = null;
}

$actions = btn_link(url('coop.members'), 'Member Center', 'btn-outline-secondary', 'people');
if ($b) {
    $actions = btn_link(url($pageKey), 'Another file', 'btn-outline-primary', 'file-earmark-plus') . ' ' . $actions;
}
coop_layout('Consolidated Import', ['actions' => $actions, 'js' => ['js/consolidated_import.js']]);

if (!$ready): ?>
  <div class="alert alert-warning">Run the database update <code>database/migrations/2027_04_25_consolidated_import.sql</code> first (see UPGRADE_v56.txt).</div>
<?php layout_end(); return; endif;

$fields = ConsolidatedImport::fields();
$sections = ConsolidatedImport::SECTIONS;

/** The columns a file may have, by part, for the help of the page */
$columnHelp = function () use ($fields, $sections): string {
    $o = '';
    foreach ($sections as $sec => $title) {
        $rows = array_filter($fields, fn($c) => $c['section'] === $sec);
        if (!$rows) {
            continue;
        }
        $o .= '<details class="ci-cols-part"><summary>' . h($title) . ' <span>' . count($rows) . ' columns</span></summary><dl class="csvr-cols">';
        foreach ($rows as $k => $c) {
            $o .= '<div><dt>' . ($c['req'] ? '<strong>' . h($k) . '</strong>' : h($k)) . '</dt><dd>' . h($c['label']) . (!empty($c['hint']) ? '<span>' . h($c['hint']) . '</span>' : '')
                . ($c['rep'] ? '<span>A second one: ' . h(ConsolidatedImport::secondName($k)) . ', a third: ' . h(str_replace('2_', '3_', ConsolidatedImport::secondName($k))) . '.</span>' : '') . '</dd></div>';
        }
        $o .= '</dl></details>';
    }
    return $o;
};

if (!$b):
    $batches = ConsolidatedImport::batches(); ?>
<div class="ci" id="ci" data-ci-start data-url="<?= h(url($pageKey)) ?>" data-csrf="<?= h(csrf_token()) ?>" data-max="<?= ConsolidatedImport::MAX_FILE_BYTES ?>">
  <div class="row g-3">
    <div class="col-xl-7">
      <div class="card ci-read">
        <div class="card-header">Read a file</div>
        <div class="card-body">
          <p class="ci-lead">One file for the members and everything they have. Each member's savings accounts, loans and water bill balances are imported with the member, in the same file.</p>
          <div class="ci-kinds">
            <?php foreach ([
                'member' => ['Members', 'Created Active, as Import Members does. A member already on file only gets the accounts of the file.'],
                'savings' => ['Savings', 'Any number of savings and share capital accounts a member, each with its balance (Import Savings).'],
                'loan' => ['Loans', 'Any number of loans a member, with what was paid (Import Loans).'],
                'bill' => ['Bill balances', 'Recurring, installment, one-time and fixed share capital balances of the member\'s water-service account (Upload Bill Balances).'],
            ] as $k => [$t, $d]): ?>
              <div class="ci-kind"><span class="ci-kind-ico"><i class="bi bi-<?= h($kindIcons[$k]) ?>"></i></span><div><strong><?= h($t) ?></strong><span><?= h($d) ?></span></div></div>
            <?php endforeach; ?>
          </div>
          <p class="ci-sub">A member may have only savings, savings and loans, savings and bill balances, or all of them: leave the columns of what a member does not have blank.</p>
          <?php if ($canCreate): ?>
          <form class="ci-pick" data-ci-pick>
            <label class="form-label" for="ciFile">CSV or Excel file</label>
            <div class="ci-pick-row">
              <input class="form-control" type="file" id="ciFile" accept=".csv,.txt,.tsv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
              <button class="btn btn-primary" data-ci-read><i class="bi bi-table"></i> Read the file for review</button>
            </div>
            <div class="ci-progress" data-ci-progress hidden>
              <div class="progress" role="progressbar" aria-label="Reading the file" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:0%"></div></div>
              <div class="ci-progress-text" data-ci-progress-text>Sending the file</div>
            </div>
            <div class="small text-muted mt-2">Up to <?= number_format(ConsolidatedImport::MAX_ROWS) ?> rows and <?= ConsolidatedImport::MAX_FILE_BYTES >> 20 ?> MB a file. The file is sent in pieces and kept in the database for the review: nothing is saved to the members, savings, loans or bills until you press Save.</div>
          </form>
          <?php else: ?>
          <div class="alert alert-light border small mb-0">Reading a file needs the Create right on Consolidated Import.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header">Files read</div>
        <?php if (!$batches): ?>
          <div class="card-body small text-muted">No file read yet.</div>
        <?php else: ?>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0 ci-batches">
            <thead><tr><th>No.</th><th>File</th><th class="text-end">Rows</th><th>Status</th><th>Read by</th><th>Read on</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($batches as $x): ?>
              <tr>
                <td data-label="No." class="text-nowrap"><?= h((string)$x['batch_no']) ?></td>
                <td data-label="File" class="ci-fname"><?= h((string)$x['file_name']) ?></td>
                <td data-label="Rows" class="text-end"><?= number_format((int)$x['file_rows']) ?></td>
                <td data-label="Status"><span class="ci-state is-<?= h((string)$x['status']) ?>"><?= h($statusText[$x['status']] ?? $x['status']) ?></span></td>
                <td data-label="Read by"><?= h((string)($x['full_name'] ?: $x['username'])) ?></td>
                <td data-label="Read on" class="text-nowrap"><?= h(fdatetime($x['created_at'])) ?></td>
                <td class="text-end"><?php if ($x['status'] !== 'reading'): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(url($pageKey, ['id' => $x['id']])) ?>">Open</a><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="card">
        <div class="card-header">The format</div>
        <div class="card-body small">
          <p class="mb-2">There is no fixed format. The first row of the file names the columns, in any order and with your own names: the page recognises the names of the four imports and the usual other ones, and you may say what any column holds before the lines are made (the choice is remembered for the next file). Only the columns of what you import are needed.</p>
          <ul class="ci-rules">
            <li><strong>Several accounts on one row:</strong> number the columns of the second one, <code>savings2_code</code>, <code>savings2_balance</code>, <code>loan2_principal</code> ... (or <code>savings_code_2</code>).</li>
            <li><strong>Or on the rows under the member:</strong> a row with no member name or member no. adds its accounts to the member above. A row with the same member no., or the same name and date of birth, is the same member.</li>
            <li><strong>A member already on file:</strong> give the member no.; only the accounts and balances of the file are added.</li>
            <li><strong>Water bill balances</strong> go to the member's water-service account: give its account no. (the member becomes the same person as its holder), or a rate code and pipe size to open one.</li>
          </ul>
          <div class="ci-tpls">
            <div class="ci-tpl"><div><strong>One row per member</strong><span>The member and the member's accounts across the row.</span></div>
              <div class="ci-tpl-links"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['t' => 'template', 'layout' => 'wide', 'format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['t' => 'template', 'layout' => 'wide', 'format' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a></div></div>
            <div class="ci-tpl"><div><strong>Rows under the member</strong><span>The member on its row, then one row per account.</span></div>
              <div class="ci-tpl-links"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['t' => 'template', 'layout' => 'long', 'format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['t' => 'template', 'layout' => 'long', 'format' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a></div></div>
          </div>
          <div class="ci-cols-title">Every column a file may have</div>
          <div class="text-muted mb-2">In bold: needed for that part (a member's savings account needs its savings code and balance, a loan its loan name, principal, term ...).</div>
          <?= $columnHelp() ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php layout_end(); return; endif;

// ================================================================== a file under review
$summary = ConsolidatedImport::summary($b);
$headers = $b['headers'];
$examples = [];
foreach (DB::all('SELECT cells FROM coop_import_raw WHERE batch_id = :b ORDER BY file_row LIMIT 25', ['b' => $b['id']]) as $r) {
    foreach (json_decode((string)$r['cells'], true) ?: [] as $i => $v) {
        if (trim((string)$v) !== '' && !isset($examples[$i])) {
            $examples[$i] = mb_strimwidth(trim((string)$v), 0, 40, '...');
        }
    }
}
$known = count(array_filter($b['mapping'], fn($m) => ($m['field'] ?? '') !== ''));
$o = $b['options'];
$editable = $canCreate && in_array($b['status'], ['review', 'saving'], true);
$columns = [];
foreach (ConsolidatedImport::KINDS as $k => $_) {
    $columns[$k] = ConsolidatedImport::columns($b, $k);
}
$missing = $b['status'] !== 'saved' ? ConsolidatedImport::missingRights($b) : [];
$by = DB::row('SELECT full_name, username FROM users WHERE id = :id', ['id' => (int)$b['created_by']]);
$cfg = [
    'id' => (int)$b['id'], 'status' => $b['status'], 'editable' => $editable, 'canSave' => $canCreate && !$missing && $b['status'] !== 'saved',
    'kinds' => array_map(fn($x) => ['title' => $x[0], 'one' => $x[1], 'many' => $x[2]], ConsolidatedImport::KINDS), 'columns' => $columns, 'summary' => $summary,
    'pageLines' => ConsolidatedImport::PAGE_LINES,
];
$codes = CsvImports::importCodes();
$accounts = LoanImport::accounts();
$classOptions = Classes::ready() ? Classes::options(((int)($o['class_id'] ?? 0)) ?: null) : [];
?>
<div class="ci" id="ci" data-ci-batch data-url="<?= h(url($pageKey)) ?>" data-csrf="<?= h(csrf_token()) ?>" data-cfg="<?= h(json_encode($cfg, JSON_UNESCAPED_UNICODE)) ?>">

  <div class="card csvr-head ci-file mb-3"><div class="card-body">
    <div class="csvr-head-row">
      <div class="csvr-file"><i class="bi bi-<?= preg_match('/\.xlsx$/i', (string)$b['file_name']) ? 'file-earmark-excel' : 'filetype-csv' ?>" aria-hidden="true"></i>
        <div><strong><?= h((string)$b['file_name']) ?></strong>
          <div class="small text-muted"><?= h((string)$b['batch_no']) ?>, <?= number_format((int)$b['file_rows']) ?> row(s) and <?= count($headers) ?> column(s), read by <?= h((string)($by['full_name'] ?? $by['username'] ?? '')) ?> on <?= h(fdatetime($b['created_at'])) ?></div></div></div>
      <div class="ci-file-state"><span class="ci-state is-<?= h($b['status']) ?>" data-ci-state><?= h($statusText[$b['status']] ?? $b['status']) ?></span>
        <?php if ($canCreate && $b['status'] === 'review'): ?>
          <form method="post" class="d-inline" data-confirm="Close this file? Nothing of it is saved; it leaves the list of files read." data-confirm-ok="Close the file"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> Close the file</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div></div>

  <?php if ($b['status'] === 'saved'): $k = $summary['kinds']; ?>
    <div class="ci-done mb-3"><i class="bi bi-check2-circle" aria-hidden="true"></i><div><strong>Saved on <?= h(fdatetime($b['saved_at'])) ?></strong>
      <span><?= number_format($k['member']['saved']) ?> member(s), <?= number_format($k['savings']['saved']) ?> savings account(s), <?= number_format($k['loan']['saved']) ?> loan(s) and <?= number_format($k['bill']['saved']) ?> bill balance(s). Each line shows what it became.</span>
      <div class="ci-done-links"><?= page_link('coop.members', [], 'Member Center', 'btn-outline-secondary', 'people') ?> <?= page_link('sav.accounts', [], 'Savings Account Center', 'btn-outline-secondary', 'piggy-bank') ?> <?= page_link('loan.accounts', [], 'Loan Accounts Center', 'btn-outline-secondary', 'cash-coin') ?> <?= page_link('rpt.coop_sl_gl', [], 'SL vs GL reconciliation', 'btn-outline-secondary', 'check2-square') ?></div></div></div>
  <?php elseif ($b['status'] === 'saving'): ?>
    <div class="alert alert-warning small"><i class="bi bi-hourglass-split"></i> Saving was started and has not finished: <?= number_format($summary['saved_members']) ?> of <?= number_format($summary['members']) ?> member(s) are saved. Press <strong>Go on saving</strong> to save the rest.</div>
  <?php endif; ?>

  <?php if ($b['status'] === 'review'): ?>
  <div class="row g-3 mb-3">
    <div class="col-xxl-7">
      <details class="card ci-map"<?= $known < count($headers) ? ' open' : '' ?>>
        <summary class="card-header"><span>Columns of the file</span><span class="ci-map-count"><?= $known ?> of <?= count($headers) ?> recognised</span></summary>
        <form method="post" class="card-body" data-confirm="Make the lines again from these columns? Corrections made in the review are replaced by the values of the file." data-confirm-ok="Make the lines again">
          <?= csrf_field() ?><input type="hidden" name="action" value="mapping"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <p class="small text-muted">What each column of the file holds. A column set to <em>Not imported</em> is left out. For the second or third account of a member on the same row, choose its number.</p>
          <div class="ci-map-list">
          <?php foreach ($headers as $i => $hname): $m = $b['mapping'][$i] ?? ['field' => '', 'slot' => 1]; $f = $m['field'] ? ($fields[$m['field']] ?? null) : null; ?>
            <div class="ci-map-row<?= $m['field'] === '' ? ' is-off' : '' ?>">
              <div class="ci-map-head"><strong title="<?= h((string)$hname) ?>"><?= h((string)$hname) ?></strong><span><?= isset($examples[$i]) ? 'e.g. ' . h($examples[$i]) : 'blank in the first rows' ?></span></div>
              <div class="ci-map-pick">
                <select class="form-select form-select-sm" name="map[<?= (int)$i ?>][field]" data-no-combo data-ci-mapfield aria-label="What column <?= h((string)$hname) ?> holds">
                  <option value="">Not imported</option>
                  <?php foreach ($sections as $sec => $title): ?>
                    <optgroup label="<?= h($title) ?>">
                    <?php foreach ($fields as $fk => $fc): if ($fc['section'] !== $sec) continue; ?>
                      <option value="<?= h($fk) ?>" data-rep="<?= $fc['rep'] ? '1' : '0' ?>"<?= $fk === $m['field'] ? ' selected' : '' ?>><?= h($fc['label']) ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm ci-map-slot" name="map[<?= (int)$i ?>][slot]" data-no-combo data-ci-mapslot aria-label="Which account of the member"<?= $f && $f['rep'] ? '' : ' hidden' ?>>
                  <?php for ($n = 1; $n <= 10; $n++): ?><option value="<?= $n ?>"<?= (int)$m['slot'] === $n ? ' selected' : '' ?>><?= $n === 1 ? '1st' : ($n === 2 ? '2nd' : ($n === 3 ? '3rd' : $n . 'th')) ?></option><?php endfor; ?>
                </select>
              </div>
            </div>
          <?php endforeach; ?>
          </div>
          <?php if ($canCreate): ?><div class="mt-3"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Make the lines again with these columns</button></div><?php endif; ?>
        </form>
      </details>
    </div>
    <div class="col-xxl-5">
      <form method="post" class="card ci-opts">
        <div class="card-header">Values for the blank cells</div>
        <div class="card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="options"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <div class="row g-2">
            <div class="col-sm-6"><label class="form-label" for="ciCut">Cut-off date</label><input class="form-control form-control-sm" type="date" id="ciCut" name="cut_off_date" value="<?= h((string)$o['cut_off_date']) ?>" max="<?= h(today()) ?>">
              <div class="form-text">The date of the savings balances, the loans' totals paid and the bill balances.</div></div>
            <div class="col-sm-6"><label class="form-label" for="ciTxn">Savings transaction code</label><select class="form-select form-select-sm" id="ciTxn" name="savings_txn_code">
              <?php foreach ($codes as $c => $label): ?><option value="<?= h((string)$c) ?>"<?= (string)$c === (string)$o['savings_txn_code'] ? ' selected' : '' ?>><?= h((string)$label) ?></option><?php endforeach; ?></select>
              <div class="form-text">The deposit code of the balances, against its GL account.</div></div>
            <div class="col-sm-6"><label class="form-label" for="ciBal">Loan balancing account</label><select class="form-select form-select-sm" id="ciBal" name="balancing_account">
              <?php foreach ($accounts as $c => $label): ?><option value="<?= h((string)$c) ?>"<?= (string)$c === (string)$o['balancing_account'] ? ' selected' : '' ?>><?= h((string)$label) ?></option><?php endforeach; ?></select>
              <div class="form-text">The other side of the loans' balances.</div></div>
            <div class="col-sm-6"><label class="form-label" for="ciClass">Class of the bill balances</label><select class="form-select form-select-sm" id="ciClass" name="class_id">
              <option value="">Choose the class</option>
              <?php foreach ($classOptions as $cid => $label): ?><option value="<?= (int)$cid ?>"<?= (string)$cid === (string)$o['class_id'] ? ' selected' : '' ?>><?= h((string)$label) ?></option><?php endforeach; ?></select>
              <div class="form-text">The recurring, installment and one-time balances' journal entries.</div></div>
            <div class="col-6"><label class="form-label" for="ciInst">Installments left</label><input class="form-control form-control-sm" id="ciInst" name="installments" inputmode="numeric" value="<?= h((string)$o['installments']) ?>"></div>
            <div class="col-6"><label class="form-label" for="ciMode">Installment payment mode</label><select class="form-select form-select-sm" id="ciMode" name="payment_mode" data-no-combo>
              <?php foreach (['Monthly', 'Quarterly', 'Semi-annually', 'Annually'] as $mo): ?><option<?= $mo === $o['payment_mode'] ? ' selected' : '' ?>><?= $mo ?></option><?php endforeach; ?></select></div>
          </div>
          <?php if ($canCreate): ?><div class="mt-3"><button class="btn btn-outline-primary btn-sm"><i class="bi bi-check2"></i> Use these values and check again</button></div><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="ci-tiles" data-ci-tiles>
    <?php foreach (ConsolidatedImport::KINDS as $k => [$title]): ?>
      <button type="button" class="ci-tile" data-ci-tile="<?= h($k) ?>" aria-pressed="false">
        <span class="ci-tile-ico"><i class="bi bi-<?= h($kindIcons[$k]) ?>"></i></span>
        <span class="ci-tile-body"><span class="ci-tile-title"><?= h($title) ?></span><span class="ci-tile-n" data-n>0</span>
          <span class="ci-tile-sub" data-bad></span><span class="ci-tile-total" data-total></span></span>
      </button>
    <?php endforeach; ?>
  </div>

  <div class="alert alert-danger small ci-general" data-ci-general hidden></div>
  <?php if ($missing && $b['status'] !== 'saved'): ?>
    <div class="alert alert-light border small"><i class="bi bi-lock"></i> You may read, check and correct the file. Saving it takes these rights: <?= h(implode('; ', $missing)) ?>.</div>
  <?php endif; ?>

  <div class="csvr ci-review" data-ci-review>
    <div class="csvr-bar">
      <div class="ci-views" role="tablist" data-ci-views>
        <?php foreach (ConsolidatedImport::KINDS as $k => [$title]): ?>
          <button type="button" class="ci-view" role="tab" data-ci-view="<?= h($k) ?>" aria-selected="false"><?= h($title) ?> <span data-count></span></button>
        <?php endforeach; ?>
      </div>
      <div class="csvr-tools">
        <input type="search" class="form-control form-control-sm" placeholder="Find in the lines" aria-label="Find in the lines" data-ci-find>
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="ciBad" data-ci-bad><label class="form-check-label" for="ciBad">Only lines with a problem</label></div>
        <select class="form-select form-select-sm ci-status-pick" data-ci-status data-no-combo aria-label="Which lines">
          <option value="pending"><?= $b['status'] === 'saved' ? 'Not saved' : 'In the review' ?></option><option value="removed">Removed</option><option value="saved">Saved</option>
        </select>
      </div>
    </div>
    <div class="csvr-scroll" data-ci-scroll><table class="csvr-table ci-table" data-ci-table></table></div>
    <div class="ci-pager" data-ci-pager>
      <span data-ci-range></span>
      <span class="ci-undo" data-ci-undowrap hidden><span data-ci-undotext></span> <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-ci-undo>Put back the last</button></span>
      <span class="ci-pager-btns"><button type="button" class="btn btn-sm btn-outline-secondary" data-ci-prev aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>
        <span data-ci-page></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ci-next aria-label="Next page"><i class="bi bi-chevron-right"></i></button></span>
    </div>
  </div>

  <div class="csvr-actions ci-actions"<?= $b['status'] === 'saved' ? ' hidden' : '' ?>>
    <div class="csvr-actions-msg" data-ci-msg></div>
    <div class="ci-run" data-ci-run hidden><div class="progress" role="progressbar" aria-label="Progress"><div class="progress-bar" style="width:0%"></div></div></div>
    <div class="csvr-actions-btns">
      <?php if ($editable): ?><button type="button" class="btn btn-outline-primary" data-ci-check title="Check every line again"><i class="bi bi-list-check"></i> Check again</button><?php endif; ?>
      <?php if ($canCreate && in_array($b['status'], ['review', 'saving'], true)): ?>
        <button type="button" class="btn btn-primary" data-ci-save<?= $missing ? ' disabled' : '' ?>><i class="bi bi-database-check"></i> <span data-ci-savelabel><?= $b['status'] === 'saving' ? 'Go on saving' : 'Save to the database' ?></span></button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php layout_end();
