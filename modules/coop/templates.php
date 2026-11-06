<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.templates';
$docs = CoopDocs::KEYS;
$key = isset($docs[g('doc')]) ? (string)g('doc') : array_key_first($docs);
$view = gint('id') ? DB::row('SELECT * FROM document_templates WHERE id = :id', ['id' => gint('id')]) : null;

handle_post(function () use ($pageKey, $docs, $key) {
    Access::require($pageKey, 'create');
    if (p('action') === 'deactivate') {
        $t = DB::row('SELECT * FROM document_templates WHERE id = :id', ['id' => pint('id')]);
        if (!$t) {
            fail('Template not found.');
        }
        DB::update('document_templates', ['is_active' => 0], 'id = :id', ['id' => $t['id']]);
        Audit::log('deactivate', 'coop', 'document_template', (int)$t['id'], $t['name'] . ' v' . $t['version_no'], ['page_key' => $pageKey]);
        flash('success', 'Version ' . $t['version_no'] . ' switched off; documents use the previous version or the standard wording.');
        redirect(url($pageKey, ['doc' => $t['doc_key']]));
    }
    $body = trim((string)p('body'));
    $eff = (string)p('effective_date');
    if ($body === '') {
        fail('Enter the text of the document.');
    }
    if (!valid_date($eff)) {
        fail('Enter the date the version takes effect.');
    }
    // the text is stored as written; it is shown with every tag except a short list of formatting tags taken out
    $body = CoopDocs::cleanHtml($body);
    $ver = (int)DB::value('SELECT COALESCE(MAX(version_no),0) FROM document_templates WHERE doc_key = :k', ['k' => $key]) + 1;
    $id = DB::insert('document_templates', ['doc_key' => $key, 'name' => mb_substr(trim((string)p('name')) ?: $docs[$key], 0, 120), 'paper' => in_array(p('paper'), ['A4', 'Letter', 'Legal'], true) ? p('paper') : 'A4',
        'body' => $body, 'effective_date' => $eff, 'version_no' => $ver, 'is_active' => 1, 'created_by' => Auth::id()]);
    Audit::log('create', 'coop', 'document_template', $id, $docs[$key] . ' v' . $ver, ['page_key' => $pageKey, 'reason' => 'Effective ' . $eff, 'snapshot' => ['body' => $body]]);
    flash('success', 'Version ' . $ver . ' saved. Documents dated from ' . fdate($eff) . ' use it; older documents keep the version of their date.');
    redirect(url($pageKey, ['doc' => $key]));
});

$versions = DB::all('SELECT t.*, u.full_name FROM document_templates t LEFT JOIN users u ON u.id = t.created_by WHERE t.doc_key = :k ORDER BY t.version_no DESC', ['k' => $key]);
$current = CoopDocs::template($key, today());
coop_layout('Document Templates');
?>
<ul class="nav nav-tabs mb-3"><?php foreach ($docs as $k => $label): ?><li class="nav-item"><a class="nav-link<?= $k === $key ? ' active' : '' ?>" href="<?= h(url($pageKey, ['doc' => $k])) ?>"><?= h($label) ?></a></li><?php endforeach; ?></ul>
<div class="row g-3">
  <div class="col-xl-7">
    <?php if (Access::can($pageKey, 'create')): ?>
    <form method="post" class="card" data-once><div class="card-header">New version of the <?= h(mb_strtolower($docs[$key])) ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?= input_field('name', 'Name', fv('name', null, $docs[$key]), ['class' => 'col-md-6']) ?>
      <?= input_field('effective_date', 'Effective from', fv('effective_date', null, today()), ['class' => 'col-6 col-md-3', 'type' => 'date', 'required' => true]) ?>
      <?= select_field('paper', 'Paper', ['A4' => 'A4', 'Letter' => 'Letter', 'Legal' => 'Legal'], fv('paper', null, 'A4'), ['class' => 'col-6 col-md-3']) ?>
      <div class="col-12"><label class="form-label">Text (paragraphs, <b>bold</b>, <i>italic</i>, lists and tables are kept)</label>
        <textarea class="form-control form-control-sm font-monospace" name="body" rows="14"><?= h((string)fv('body', null, $current ? $current['body'] : CoopDocs::standard($key))) ?></textarea></div>
      <div class="col-12 small text-muted">Placeholders: <?= h(implode(', ', array_map(fn($p) => '{{' . $p . '}}', CoopDocs::PLACEHOLDERS[$key] ?? []))) ?></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save as a new version</button></div>
    </div></form>
    <?php endif; ?>
  </div>
  <div class="col-xl-5">
    <div class="card"><div class="card-header">Versions</div><table class="table table-sm"><thead><tr><th>Version</th><th>Effective</th><th>By</th><th></th></tr></thead><tbody>
    <?php foreach ($versions as $v): ?><tr class="<?= (int)$v['is_active'] ? '' : 'text-muted' ?>"><td>v<?= (int)$v['version_no'] ?> <?= h($v['name']) ?><?= $current && (int)$current['id'] === (int)$v['id'] ? ' <span class="small text-success">in use today</span>' : '' ?></td>
      <td><?= h(fdate($v['effective_date'])) ?></td><td class="small"><?= h((string)$v['full_name']) ?></td>
      <td class="text-nowrap"><a href="<?= h(url($pageKey, ['doc' => $key, 'id' => $v['id']])) ?>">View</a>
        <?php if ((int)$v['is_active'] && Access::can($pageKey, 'create')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn-link btn-sm p-0 ms-2" data-confirm="Switch this version off?">Switch off</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$versions): ?><tr><td colspan="4" class="text-muted">No version yet: the documents print the standard wording.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($view): ?><div class="card mt-3"><div class="card-header">v<?= (int)$view['version_no'] ?> as saved</div><div class="card-body doc-clause"><?= CoopDocs::cleanHtml($view['body']) ?></div></div><?php endif; ?>
    <p class="small text-muted mt-2">Versions are never overwritten: a document printed again later uses the version that was in effect on its own date, and every print and reprint is logged.</p>
  </div>
</div>
<?php
layout_end();
