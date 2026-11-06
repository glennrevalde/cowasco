<?php
declare(strict_types=1);

/**
 * System Administration > Import from CSV
 * Loads customers, suppliers, fixed assets and whole transactions from spreadsheet files.
 * The columns of every type, its blank template and the checks are in app/Importer.php.
 *
 * Nothing is written until the whole file has been read and checked, and the writing itself runs in one
 * transaction: a file with a bad line leaves the books exactly as they were.
 */
$pageKey = 'admin.import';
require_once __DIR__ . '/_import_lib.php';
$types = Importer::types();
$type = (string)(g('type') ?: p('type'));
if (!isset($types[$type])) {
    $type = '';
}

// ------------------------------------------------------------------ blank template
if (g('a') === 'template' && $type) {
    Access::require($pageKey, 'print');
    [$head, $rows] = Importer::template($type);
    csv_download('import_' . $type . '_template.csv', $head, $rows);
}

// ------------------------------------------------------------------ the import itself
$result = null;
handle_post(function () use (&$result, $type, $types, $pageKey) {
    Access::require($pageKey, 'create');
    if (!$type) {
        fail('Choose what to import.');
    }
    // the file that was just checked, imported without choosing it again
    if (p('mode') === 'import_checked') {
        $token = preg_replace('/[^a-f0-9]/', '', (string)p('token'));
        $kept = $_SESSION['import_checked'][$token] ?? null;
        if (!$kept || $kept['type'] !== $type || !is_file($kept['path'])) {
            fail('The checked file is no longer here. Choose the file and check it again.');
        }
        $rows = Importer::read($kept['path'], $type);
        $result = import_run($type, $rows, false, (string)$kept['name'], $pageKey);
        if (!$result['errors']) {
            @unlink($kept['path']);
            unset($_SESSION['import_checked'][$token]);
            flash('success', $result['ok'] . ' ' . strtolower($types[$type]['label']) . ' imported.');
        }
        return;
    }
    $up = $_FILES['file'] ?? null;
    if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        fail('Choose the CSV file to import.');
    }
    if ($up['error'] !== UPLOAD_ERR_OK) {
        fail('The file could not be uploaded' . (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': it is too large.' : '.'));
    }
    if (!preg_match('/\.(csv|txt)$/i', (string)$up['name'])) {
        fail('Choose a CSV file (.csv). In Excel: File > Save As > CSV (Comma delimited).');
    }
    $rows = Importer::read($up['tmp_name'], $type);
    $dry = p('mode') !== 'import';
    $result = import_run($type, $rows, $dry, (string)$up['name'], $pageKey);
    if ($dry && !$result['errors']) {
        // keep the checked file, so it can be imported from the result without being chosen again
        $result['token'] = import_keep_checked($up['tmp_name'], $type, (string)$up['name']);
    }
    if (!$dry && $result['ok'] > 0) {
        flash('success', $result['ok'] . ' ' . strtolower($types[$type]['label']) . ' imported.');
    }
});

// ------------------------------------------------------------------ the page
$groups = [];
foreach ($types as $k => $t) {
    $groups[$t['group']][$k] = $t['label'];
}
$recent = DB::all('SELECT b.*, u.username FROM import_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 15');
layout_start('Import from CSV', ['actions' => page_link('admin.backup', [], 'Backup & Restore', 'btn-outline-secondary', 'database')]);
?>
<p class="text-muted small">Load records from a spreadsheet. Save the sheet as <strong>CSV</strong> in Excel, with the column names on the first line.
  Download the template of the kind you are importing to get the exact columns; a column you do not need can be left out, and the order does not matter.
  <strong>Check the file</strong> first: it reads the whole file and reports every problem without changing anything.</p>

<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" enctype="multipart/form-data" class="card" data-no-class-check><div class="card-header fw-semibold">Import a file</div><div class="card-body">
      <?= csrf_field() ?>
      <label class="form-label">What are you importing?</label>
      <select class="form-select form-select-sm mb-1" name="type" id="impType" required onchange="location = '<?= h(url($pageKey)) ?>&type=' + this.value">
        <option value="">— choose —</option>
        <?php foreach ($groups as $gname => $items): ?>
          <optgroup label="<?= h($gname) ?>"><?php foreach ($items as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?></optgroup>
        <?php endforeach; ?>
      </select>
      <?php if ($type): ?>
        <div class="form-text mb-2"><?= h($types[$type]['note']) ?></div>
        <div class="mb-3"><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey, ['a' => 'template', 'type' => $type])) ?>"><i class="bi bi-download"></i> Download the blank template</a></div>
        <label class="form-label d-block">CSV file</label>
        <input class="form-control form-control-sm" type="file" name="file" accept=".csv,text/csv,text/plain" required>
        <div class="d-flex gap-2 mt-3 flex-wrap">
          <button class="btn btn-primary" name="mode" value="check"><i class="bi bi-search"></i> Check the file</button>
          <button class="btn btn-outline-secondary" name="mode" value="import" data-confirm="Check and import this file in one step? Records are added to the books."><i class="bi bi-upload"></i> Check and import in one step</button>
        </div>
        <div class="form-text">Checking reads every line and reports every problem without writing anything. When the file is in order, it is imported from the result below with one click.</div>
      <?php endif; ?>
    </div></form>
  </div>

  <div class="col-lg-7">
    <?php if ($type): $def = $types[$type]; ?>
    <div class="card"><div class="card-header fw-semibold">The columns of "<?= h($def['label']) ?>"</div>
      <div class="table-responsive" style="max-height:420px"><table class="table table-sm table-tight mb-0">
        <thead><tr><th>Column</th><th>What goes in it</th><th>Needed</th></tr></thead>
        <?php foreach ($def['columns'] as $col => $spec): if (($spec[0] ?? '') === '(not used)') { continue; } ?>
          <tr><td><code><?= h($col) ?></code></td><td class="small"><?= h($spec[0]) ?></td><td class="small"><?= !empty($spec[2]) ? 'yes' : '' ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($result): $def = $types[$result['type']]; ?>
<div class="card mt-3 <?= $result['errors'] ? 'border-danger' : ($result['dry'] ? 'border-primary' : 'border-success') ?>">
  <div class="card-header fw-semibold"><?= $result['errors'] ? 'The file was not imported' : ($result['dry'] ? 'The file was checked' : 'Imported') ?>:
    <?= h($result['file']) ?>, <?= (int)$result['count'] ?> <?= $result['type'] === 'items' ? 'item(s)' : (in_array($result['type'], ['accounts', 'customers', 'vendors', 'fixed_assets'], true) ? 'record(s)' : 'document(s)') ?></div>
  <div class="card-body">
    <?php if ($result['errors']): ?>
      <p class="mb-2">Nothing was written. Correct these lines in the spreadsheet and try again:</p>
      <ul class="small mb-0 text-danger" style="max-height:320px;overflow:auto">
        <?php foreach (array_slice($result['errors'], 0, 200) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        <?php if (count($result['errors']) > 200): ?><li>… and <?= count($result['errors']) - 200 ?> more.</li><?php endif; ?>
      </ul>
    <?php elseif ($result['dry']): ?>
      <div class="import-ready">
        <p class="text-success mb-0"><i class="bi bi-check2-circle"></i> The file is in order: nothing was written yet. Look the lines over, then import them.</p>
        <?php if (!empty($result['token'])): ?>
        <form method="post" data-no-class-check class="d-flex gap-2 flex-wrap"><?= csrf_field() ?>
          <input type="hidden" name="type" value="<?= h($result['type']) ?>"><input type="hidden" name="token" value="<?= h($result['token']) ?>">
          <button class="btn btn-primary" name="mode" value="import_checked" data-confirm="Import the <?= (int)$result['count'] ?> checked line(s) now?"><i class="bi bi-upload"></i> Import these <?= (int)$result['count'] ?> <?= $result['type'] === 'items' ? 'item(s)' : 'record(s)' ?></button>
        </form>
        <?php endif; ?>
      </div>
      <?php if ($result['type'] === 'items'):
          $viewCostsHere = Access::viewCosts();
          $newItems = count(array_filter($result['preview'], fn($p) => empty($p['_existing'])));
          $opening = array_filter(array_column($result['preview'], '_opening'));
          $newCats = array_unique(array_filter(array_map(fn($p) => !empty($p['_category_new']) ? trim((string)$p['category']) : null, $result['preview'])));
      ?>
        <div class="import-facts">
          <div><span class="n"><?= $newItems ?></span> new item(s)</div>
          <div><span class="n"><?= count($result['preview']) - $newItems ?></span> to update</div>
          <div><span class="n"><?= count($opening) ?></span> with opening stock<?= $viewCostsHere && $opening ? ', ' . money(array_sum(array_column($opening, 'value'))) : '' ?></div>
          <?php if ($newCats): ?><div>New categories: <?= h(implode(', ', $newCats)) ?></div><?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="table-responsive" style="max-height:360px"><table class="table table-sm table-tight mb-0 import-preview">
        <?php if ($result['type'] === 'items'): ?>
          <thead><tr><th>Line</th><th>Item</th><th>Type</th><th>Category</th><th>Unit</th><?= $viewCostsHere ? '<th class="num">Cost</th>' : '' ?><th class="num">Price</th><th>Opening stock</th><th>Will be</th></tr></thead>
          <?php foreach (array_slice($result['preview'], 0, 200) as $p): $o = $p['_opening'] ?? null; ?>
            <tr><td><?= (int)$p['_line'] ?></td>
              <td><span class="fw-semibold"><?= h((string)$p['code']) ?></span><div class="small text-muted"><?= h((string)$p['name']) ?></div></td>
              <td class="small"><?= h(Importer::ITEM_TYPES[$p['_type'] ?? ''] ?? '') ?></td>
              <td class="small"><?= h((string)($p['category'] ?? '')) ?><?= !empty($p['_category_new']) ? ' <span class="text-muted">(new)</span>' : '' ?></td>
              <td class="small"><?= h(!empty($p['uom_set']) ? (string)$p['_raw']['uom_set'] : ((string)($p['unit'] ?? '') ?: 'pc')) ?></td>
              <?= $viewCostsHere ? '<td class="num">' . money((float)($p['cost'] ?? 0)) . '</td>' : '' ?>
              <td class="num"><?= money((float)($p['sales_price'] ?? 0)) ?></td>
              <td class="small"><?= $o ? qty($o['qty']) . ' at ' . h($o['site_name']) . ($viewCostsHere ? ', ' . money($o['value']) : '') . ', ' . h(fdate($o['date'])) : '' ?></td>
              <td class="small"><?= empty($p['_existing']) ? 'Added' : 'Updated (' . count(array_diff($p['_present'], ['code'])) . ' column' . (count(array_diff($p['_present'], ['code'])) === 1 ? '' : 's') . ')' ?></td></tr>
          <?php endforeach; ?>
        <?php elseif (in_array($result['type'], ['accounts', 'customers', 'vendors', 'fixed_assets'], true)): ?>
          <thead><tr><th>Line</th><th>Name</th><th>Details</th></tr></thead>
          <?php foreach (array_slice($result['preview'], 0, 100) as $p): ?>
            <tr><td><?= (int)$p['_line'] ?></td>
              <td><?= h(trim((string)($p['code'] ?? '') . ' ' . (string)($p['name'] ?? $p['description'] ?? ''))) ?></td>
              <td class="small"><?= h(implode(', ', array_filter([
                  $p['account_type'] ?? '', $p['company_name'] ?? '', $p['email'] ?? '',
                  !empty($p['parent_code']) ? 'under ' . $p['parent_code'] : '',
                  !empty($p['with_sl']) ? 'With SL' : '',
                  isset($p['cost']) ? money((float)$p['cost']) : '',
              ]))) ?></td></tr>
          <?php endforeach; ?>
        <?php else: ?>
          <thead><tr><th>Document</th><th>Date</th><th class="num">Lines</th><th class="num">Total</th></tr></thead>
          <?php foreach (array_slice($result['preview'], 0, 100) as $p): ?>
            <tr><td><?= h((string)$p['no']) ?></td><td><?= h(fdate($p['date'])) ?></td><td class="num"><?= (int)$p['lines'] ?></td><td class="num"><?= money($p['total']) ?></td></tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </table></div>
      <?php if (count($result['preview']) > ($result['type'] === 'items' ? 200 : 100)): ?><div class="small text-muted mt-1">… and <?= count($result['preview']) - ($result['type'] === 'items' ? 200 : 100) ?> more.</div><?php endif; ?>
    <?php else: ?>
      <p class="text-success mb-2"><i class="bi bi-check2-circle"></i> <?= (int)$result['ok'] ?> <?= h(strtolower($def['label'])) ?> written.</p>
      <div class="table-responsive" style="max-height:320px"><table class="table table-sm table-tight mb-0">
        <thead><tr><th>What was created</th><th></th></tr></thead>
        <?php foreach (array_slice($result['created'], 0, 100) as $cr): ?>
          <tr><td><?= h((string)($cr['name'] ?? '')) ?><?= !empty($cr['updated']) ? ' <span class="text-muted small">(updated)</span>' : '' ?></td>
            <td class="small"><?= $cr['type'] === 'item' ? safe_link('inv.items', ['a' => 'edit', 'id' => $cr['id']], 'Item') : (in_array($cr['type'], ['customer', 'vendor', 'asset', 'account'], true) ? h(ucfirst($cr['type'])) : Txn::link((int)$cr['id'], Txn::label($cr['type']))) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mt-3"><div class="card-header fw-semibold">Recent imports</div>
  <div class="table-responsive"><table class="table table-sm table-tight mb-0">
    <thead><tr><th>When</th><th>What</th><th>File</th><th class="num">Records</th><th class="num">Written</th><th>By</th><th>Notes</th></tr></thead>
    <?php foreach ($recent as $b): ?>
      <tr><td class="text-nowrap"><?= h(fdatetime($b['created_at'])) ?></td><td><?= h($types[$b['import_type']]['label'] ?? $b['import_type']) ?></td>
        <td class="small"><?= h($b['file_name']) ?></td><td class="num"><?= (int)$b['rows_total'] ?></td>
        <td class="num <?= (int)$b['rows_ok'] ? 'text-success' : 'text-muted' ?>"><?= (int)$b['rows_ok'] ?></td><td class="small"><?= h($b['username']) ?></td>
        <td class="small text-muted"><?= h(mb_strimwidth((string)$b['messages'], 0, 80, '…')) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="7" class="text-muted py-3">Nothing has been imported yet.</td></tr><?php endif; ?>
  </table></div>
</div>
<?php
layout_end();
