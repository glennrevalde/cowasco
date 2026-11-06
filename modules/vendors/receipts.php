<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';

$pageKey = 'vend.receipts';
$a = g('a');
$dir = Tenant::storage('receipts');   // the company's own folder (config upload_dir for the first company)
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'pdf' => 'application/pdf', 'txt' => 'text/plain', 'eml' => 'message/rfc822', 'heic' => 'image/heic'];

/** Stores one file and creates the review-queue record */
function store_receipt(string $tmp, string $name, string $source, string $dir, array $allowed, bool $moveUploaded): int
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        fail("File type .$ext is not accepted. Use JPG, PNG, PDF, TXT or EML.");
    }
    if (filesize($tmp) > 10 * 1024 * 1024) {
        fail("$name is larger than 10 MB.");
    }
    $stored = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $stored;
    $ok = $moveUploaded ? move_uploaded_file($tmp, $dest) : rename($tmp, $dest);
    if (!$ok) {
        fail("Could not save $name. Check that the storage folder is writable.");
    }
    $details = ReceiptReader::read($dest, $name, $allowed[$ext]);
    $vendorExp = $details['vendor_id'] ? DB::value('SELECT default_expense_account_id FROM vendors WHERE id = :id', ['id' => $details['vendor_id']]) : null;
    $id = DB::insert('receipt_documents', [
        'file_path' => $stored, 'original_name' => mb_substr($name, 0, 255), 'mime_type' => $allowed[$ext], 'source' => $source,
        'vendor_id' => $details['vendor_id'], 'receipt_date' => $details['receipt_date'], 'amount' => $details['amount'],
        'account_id' => $vendorExp, 'uploaded_by' => Auth::id(),
    ]);
    Audit::log('receipt_uploaded', 'vend', 'receipt', $id, $name, ['reason' => "Source: $source", 'page_key' => 'vend.receipts']);
    return $id;
}

if ($a === 'file') {
    $doc = DB::row('SELECT * FROM receipt_documents WHERE id = :id', ['id' => (int)g('id')]);
    $path = $doc ? rtrim($dir, '/') . '/' . basename($doc['file_path']) : '';
    if (!$doc || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$doc['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($a === 'upload' && is_post()) {
    Access::require($pageKey, 'create');
    try {
        $n = 0;
        $files = $_FILES['files'] ?? null;
        if ($files && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    fail("Upload of $name failed (error {$files['error'][$i]}).");
                }
                store_receipt($files['tmp_name'][$i], $name, p('source', 'upload') === 'mobile' ? 'mobile' : 'upload', $dir, $allowed, true);
                $n++;
            }
        }
        flash($n ? 'success' : 'warning', $n ? "$n receipt(s) added to the review queue." : 'Choose at least one file.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey));
}

if ($a === 'import_inbox' && is_post()) {
    // e-mailed receipts: a mail rule saves attachments into storage/receipts/inbox
    Access::require($pageKey, 'create');
    $inbox = rtrim($dir, '/') . '/inbox';
    @mkdir($inbox, 0775, true);
    $n = 0;
    foreach (glob($inbox . '/*') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        try {
            store_receipt($f, basename($f), 'email', $dir, $allowed, false);
            $n++;
        } catch (UserError $e) {
            flash('warning', $e->getMessage());
        }
    }
    flash('info', "$n e-mailed receipt(s) imported from the inbox folder.");
    redirect(url($pageKey));
}

if ($a === 'update' && is_post()) {
    Access::require($pageKey, 'edit');
    $doc = DB::row('SELECT * FROM receipt_documents WHERE id = :id', ['id' => (int)g('id')]);
    if ($doc) {
        $new = [
            'vendor_id' => pint('vendor_id'), 'receipt_date' => pdate('receipt_date'), 'amount' => p('amount') !== '' ? r2(pnum('amount')) : null,
            'account_id' => pint('account_id'), 'memo' => p('memo') ?: null,
        ];
        $action = p('do');
        if ($action === 'reject') {
            $new['status'] = 'rejected';
        }
        if ($action === 'match') {
            $tid = pint('match_txn_id');
            if ($tid && Txn::load($tid)) {
                $new['status'] = 'matched';
                $new['linked_txn_id'] = $tid;
            } else {
                flash('warning', 'Choose the transaction to match.');
            }
        }
        $new['reviewed_by'] = Auth::id();
        $new['reviewed_at'] = date('Y-m-d H:i:s');
        DB::update('receipt_documents', $new, 'id = :id', ['id' => $doc['id']]);
        Audit::log('receipt_' . ($action ?: 'reviewed'), 'vend', 'receipt', (int)$doc['id'], $doc['original_name'], ['before' => $doc, 'after' => $new, 'page_key' => $pageKey]);
        if ($action === 'bill') {
            redirect(url('vend.bills', ['a' => 'new', 'receipt' => $doc['id'], 'vendor_id' => $new['vendor_id']]));
        }
        if ($action === 'check') {
            redirect(url('bank.write_checks', ['a' => 'new', 'receipt' => $doc['id'], 'vendor_id' => $new['vendor_id']]));
        }
        if ($action === 'card') {
            redirect(url('bank.write_checks', ['a' => 'new', 'receipt' => $doc['id'], 'vendor_id' => $new['vendor_id'], 'cc' => 1]));
        }
        flash('success', 'Receipt updated.');
    }
    redirect(url($pageKey, ['status' => g('status')]));
}

$status = g('status', 'for_review');
$docs = DB::all(
    'SELECT r.*, v.name AS vendor, u.username FROM receipt_documents r LEFT JOIN vendors v ON v.id = r.vendor_id LEFT JOIN users u ON u.id = r.uploaded_by'
    . ($status !== 'all' ? ' WHERE r.status = :s' : '') . ' ORDER BY r.uploaded_at DESC LIMIT 200',
    $status !== 'all' ? ['s' => $status] : []
);
$counts = DB::pairs('SELECT status, COUNT(*) FROM receipt_documents GROUP BY status');

layout_start('Receipt Management');
?>
<?php if (Access::can($pageKey, 'create')): ?>
<div class="card mb-3"><div class="card-body">
  <form method="post" enctype="multipart/form-data" action="<?= h(url($pageKey, ['a' => 'upload'])) ?>" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <div class="col-md-5"><label class="form-label">Upload receipts (JPG, PNG, PDF, TXT, EML, up to 10 MB each)</label>
      <input class="form-control form-control-sm" type="file" name="files[]" multiple accept="image/*,.pdf,.txt,.eml" capture="environment"></div>
    <div class="col-md-2"><label class="form-label">Source</label><select class="form-select form-select-sm" name="source"><option value="upload">Computer</option><option value="mobile">Mobile photo</option></select></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-upload"></i> Upload</button></div>
  </form>
  <form method="post" action="<?= h(url($pageKey, ['a' => 'import_inbox'])) ?>" class="mt-2 small">
    <?= csrf_field() ?>
    E-mailed receipts: set a mail rule to save attachments to <code><?= h(ltrim(str_replace('\\', '/', substr(realpath($dir) ?: $dir, strlen(realpath(APP_ROOT) ?: APP_ROOT))), '/')) ?>/inbox</code>, then
    <button class="btn btn-link btn-sm p-0 align-baseline">import them</button>.
    On a phone, the upload button opens the camera.
  </form>
</div></div>
<?php endif; ?>

<ul class="nav nav-pills mb-2 small">
  <?php foreach (['for_review' => 'For review', 'matched' => 'Matched', 'created' => 'Created', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link py-1 <?= $status === $k ? 'active' : '' ?>" href="<?= h(url($pageKey, ['status' => $k])) ?>"><?= h($lbl) ?> <?= $k !== 'all' ? '(' . (int)($counts[$k] ?? 0) . ')' : '' ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if (!$docs): ?><p class="text-muted">No receipts here.</p><?php endif; ?>
<?php foreach ($docs as $d):
    $candidates = $d['vendor_id'] ? DB::all(
        "SELECT id, txn_type, txn_no, ref_no, txn_date, total FROM transactions WHERE vendor_id = :v AND txn_type IN ('bill','check','item_receipt') AND is_voided = 0
          ORDER BY ABS(total - :a), ABS(DATEDIFF(txn_date, :d)) LIMIT 10",
        ['v' => $d['vendor_id'], 'a' => (float)$d['amount'], 'd' => $d['receipt_date'] ?: today()]
    ) : [];
    $isImage = str_starts_with((string)$d['mime_type'], 'image/');
?>
<div class="card mb-2"><div class="card-body">
  <div class="row g-3">
    <div class="col-md-2 text-center">
      <a href="<?= h(url($pageKey, ['a' => 'file', 'id' => $d['id']])) ?>" target="_blank">
        <?php if ($isImage): ?><img src="<?= h(url($pageKey, ['a' => 'file', 'id' => $d['id']])) ?>" class="img-fluid border rounded" style="max-height:120px" alt="receipt">
        <?php else: ?><i class="bi bi-file-earmark-text display-5"></i><?php endif; ?>
      </a>
      <div class="small text-truncate"><?= h($d['original_name']) ?></div>
      <div class="small text-muted"><?= h($d['source']) ?>, <?= h($d['username']) ?></div>
    </div>
    <div class="col-md-10">
      <form method="post" action="<?= h(url($pageKey, ['a' => 'update', 'id' => $d['id'], 'status' => $status])) ?>" class="row g-2">
        <?= csrf_field() ?>
        <?= select_field('vendor_id', 'Vendor', Lookup::vendors(), $d['vendor_id'], ['blank' => '', 'class' => 'col-md-3']) ?>
        <?= input_field('receipt_date', 'Date', $d['receipt_date'], ['type' => 'date', 'class' => 'col-md-2']) ?>
        <?= input_field('amount', 'Amount', $d['amount'], ['class' => 'col-md-2']) ?>
        <?= select_field('account_id', 'Account', Accts::all(), $d['account_id'], ['blank' => '', 'class' => 'col-md-3']) ?>
        <div class="col-md-2"><label class="form-label">Status</label><div><?= status_badge($d['status']) ?> <?= $d['linked_txn_id'] ? Txn::link((int)$d['linked_txn_id']) : '' ?></div></div>
        <?= input_field('memo', 'Memo', $d['memo'], ['class' => 'col-md-5']) ?>
        <?php if ($d['status'] === 'for_review' && Access::can($pageKey, 'edit')): ?>
        <div class="col-md-4"><label class="form-label">Match to existing transaction</label>
          <select class="form-select form-select-sm" name="match_txn_id"><option value=""></option>
            <?php foreach ($candidates as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h(Txn::label($c['txn_type']) . ' ' . ($c['ref_no'] ?: $c['txn_no']) . ', ' . fdate($c['txn_date']) . ', ' . money($c['total'])) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12 d-flex flex-wrap gap-1">
          <button class="btn btn-sm btn-outline-primary" name="do" value="">Save details</button>
          <button class="btn btn-sm btn-outline-success" name="do" value="match">Match</button>
          <?php if (Access::can('vend.bills', 'create')): ?><button class="btn btn-sm btn-success" name="do" value="bill">Create bill</button><?php endif; ?>
          <?php if (Access::can('bank.write_checks', 'create')): ?><button class="btn btn-sm btn-success" name="do" value="check">Create check</button>
            <button class="btn btn-sm btn-success" name="do" value="card">Create credit card charge</button><?php endif; ?>
          <button class="btn btn-sm btn-outline-danger" name="do" value="reject" data-confirm="Reject this receipt?">Reject</button>
        </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div></div>
<?php endforeach; ?>
<?php
layout_end();
