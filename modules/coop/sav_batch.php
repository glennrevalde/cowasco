<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'sav.batch';
$b = gint('id') ? SavBatch::batch((int)gint('id')) : null;

handle_post(function () use ($pageKey, $b) {
    $action = (string)p('action');
    if ($action === 'create') {
        Access::require($pageKey, 'create');
        coop_once();
        $text = (string)p('lines');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ((int)$_FILES['file']['size'] > 2097152) {
                fail('The file is larger than 2 MB.');
            }
            $text .= "\n" . (string)file_get_contents($_FILES['file']['tmp_name']);
        }
        $productId = pint('product_id');
        $rows = SavBatch::parse($text);
        if (p('fill') === '1') {
            // every active member with an account of the product, for the same amount (monthly CBU)
            if (!$productId) {
                fail('Choose the product to fill the list from.');
            }
            $amt = Coop::amount('fill_amount', true);
            [$bf, $bp] = Coop::branchFilter('a.branch_id');
            foreach (DB::all("SELECT a.account_no FROM sav_accounts a JOIN members m ON m.id = a.member_id WHERE a.product_id = :p AND a.status IN ('active','dormant') AND m.status = 'active' AND m.hold_flag = 0" . $bf . ' ORDER BY m.full_name', ['p' => $productId] + $bp) as $r) {
                $rows[] = ['ref' => $r['account_no'], 'amount' => $amt, 'remarks' => '', 'line' => 0];
            }
        }
        $classId = Classes::required('class_id', 'the batch');
        $id = SavBatch::create(['batch_date' => p('batch_date'), 'description' => trim((string)p('description')), 'source' => p('source'), 'contra_account_id' => pint('contra_account_id'), 'class_id' => $classId], $rows, $productId, $pageKey);
        redirect(url($pageKey, ['id' => $id]));
    }
    if (!$b) {
        fail('Batch not found.');
    }
    if ($b['status'] !== 'draft') {
        fail('The batch is ' . $b['status'] . '.');
    }
    if ($action === 'remove_line') {
        Access::require($pageKey, 'edit');
        DB::run('DELETE FROM sav_batch_lines WHERE id = :l AND batch_id = :b', ['l' => pint('line_id'), 'b' => $b['id']]);
        SavBatch::check((int)$b['id']);
        Audit::log('edit', 'sav', 'sav_batch', (int)$b['id'], $b['batch_no'], ['page_key' => $pageKey, 'reason' => 'Line removed']);
    } elseif ($action === 'fix_line') {
        Access::require($pageKey, 'edit');
        DB::update('sav_batch_lines', ['member_no' => mb_substr(trim((string)p('ref')), 0, 30), 'amount' => Dec::fix(Dec::of(p('amount')))], 'id = :l AND batch_id = :b', ['l' => pint('line_id'), 'b' => $b['id']]);
        SavBatch::check((int)$b['id']);
    } elseif ($action === 'post') {
        Access::require($pageKey, 'create');
        coop_once();
        $classId = Classes::required('class_id', 'the batch');
        if (SavBatch::classReady()) {
            DB::update('sav_batches', ['class_id' => $classId], 'id = :id', ['id' => $b['id']]);
        }
        $txn = CoopLedger::withClass($classId, fn() => SavBatch::post((int)$b['id'], $pageKey));
        flash('success', 'Batch posted on voucher ' . Txn::load($txn)['txn_no'] . '.');
    } elseif ($action === 'cancel') {
        Access::require($pageKey, 'delete');
        DB::update('sav_batches', ['status' => 'voided'], 'id = :id', ['id' => $b['id']]);
        Audit::log('cancel', 'sav', 'sav_batch', (int)$b['id'], $b['batch_no'], ['page_key' => $pageKey, 'reason' => trim((string)p('reason')) ?: 'Draft discarded']);
        flash('success', 'Draft discarded.');
        redirect(url($pageKey));
    }
    redirect(url($pageKey, ['id' => $b['id']]));
});

$contraDefault = (int)DB::value("SELECT id FROM accounts WHERE code = '1230'");
if ($b) {
    $lines = DB::all('SELECT l.*, COALESCE(m1.full_name, m2.full_name) AS member FROM sav_batch_lines l LEFT JOIN sav_accounts a ON a.id = l.account_id LEFT JOIN members m1 ON m1.id = a.member_id
                       LEFT JOIN loan_accounts la ON la.id = l.loan_id LEFT JOIN members m2 ON m2.id = la.member_id WHERE l.batch_id = :b ORDER BY l.error_text IS NULL, l.id', ['b' => $b['id']]);
    $errors = count(array_filter($lines, fn($l) => $l['error_text']));
    $print = g('print') === '1';
    if ($print) {
        layout_start('Batch ' . $b['batch_no'], ['bare' => true]);
        echo '<div class="doc coop-doc">' . coop_print_head('BATCH DEDUCTIONS', $b['batch_no'], $b['batch_date'], ['Description' => (string)$b['description']]);
    } else {
        coop_layout('Batch ' . $b['batch_no'], ['actions' => btn_link(current_url(['print' => 1]), 'Print', 'btn-outline-secondary', 'printer') . ' ' . btn_link(url($pageKey), 'Batches', 'btn-outline-secondary', 'arrow-left')]);
        echo '<div class="coop-tiles"><div class="coop-tile"><div class="t-label">Lines</div><div class="t-value">' . count($lines) . '</div></div>'
            . '<div class="coop-tile is-ok"><div class="t-label">Total</div><div class="t-value">' . cm($b['total']) . '</div></div>'
            . '<div class="coop-tile ' . ($errors ? 'is-bad' : 'is-ok') . '"><div class="t-label">Lines with errors</div><div class="t-value">' . $errors . '</div></div>'
            . '<div class="coop-tile"><div class="t-label">Status</div><div class="t-value" style="font-size:1rem">' . coop_status($b['status']) . '</div><div class="t-sub">' . ($b['txn_id'] ? Txn::link((int)$b['txn_id']) : h(Coop::accountLabel((int)$b['contra_account_id']))) . '</div></div></div>';
    }
    ?>
    <div class="card mb-3"><table class="table table-sm"><thead><tr><th>Reference</th><th>Account / loan</th><th>Member</th><th class="num">Amount</th><th>Remarks / error</th><?= !$print && $b['status'] === 'draft' ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($lines as $l): ?><tr class="<?= $l['error_text'] ? 'table-warning' : '' ?>"><td><?= h($l['member_no']) ?></td><td><?= h((string)$l['account_no']) ?><?= $l['loan_id'] ? ' <span class="small text-muted">loan</span>' : '' ?></td><td><?= h((string)$l['member']) ?></td>
      <td class="num"><?= cm($l['amount']) ?></td><td class="small <?= $l['error_text'] ? 'text-danger' : '' ?>"><?= h((string)($l['error_text'] ?: $l['remarks'])) ?></td>
      <?php if (!$print && $b['status'] === 'draft'): ?><td class="text-nowrap">
        <?php if ($l['error_text'] && Access::can($pageKey, 'edit')): ?><form method="post" class="d-inline-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="fix_line"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
          <input class="form-control form-control-sm" name="ref" value="<?= h($l['member_no']) ?>" style="width:120px" aria-label="Reference"><input class="form-control form-control-sm text-end" name="amount" value="<?= h($l['amount']) ?>" style="width:100px" aria-label="Amount"><button class="btn btn-sm btn-outline-primary">Fix</button></form><?php endif; ?>
        <?php if (Access::can($pageKey, 'edit')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove_line"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0 ms-1" data-confirm="Remove this line?">Remove</button></form><?php endif; ?>
      </td><?php endif; ?></tr><?php endforeach; ?>
    </tbody><tfoot><tr><td colspan="3" class="fw-semibold">Total</td><td class="num fw-semibold"><?= cm($b['total']) ?></td><td colspan="2"></td></tr></tfoot></table></div>
    <?php if ($print): ?>
      <?= coop_signatures(['Prepared by' => (string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $b['created_by']]), 'Checked by' => Coop::setting('signatory_bookkeeper'), 'Approved by' => Coop::setting('signatory_gm')]) ?></div>
      <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php elseif ($b['status'] === 'draft'): ?>
      <div class="d-flex gap-2">
      <?php if (Access::can($pageKey, 'create')): ?><form method="post" class="d-flex flex-wrap gap-2 align-items-end" data-once data-confirm="Post batch <?= h($b['batch_no']) ?> of <?= h(Dec::money($b['total'])) ?>? Every member account and loan on it is credited at once." data-confirm-ok="Post batch">
        <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="post">
        <?= Classes::requiredField(fv('class_id', null, $b['class_id'] ?? null), 'batch-class') ?>
        <div><button class="btn btn-primary btn-sm"<?= $errors ? ' disabled title="Correct the errors first"' : '' ?>><i class="bi bi-check2-all"></i> Post the batch</button></div></form><?php endif; ?>
      <?php if (Access::can($pageKey, 'delete')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger btn-sm" data-confirm="Discard this draft?">Discard the draft</button></form><?php endif; ?>
      </div>
    <?php endif;
    layout_end();
    exit;
}

$batches = DB::all('SELECT b.*, u.full_name FROM sav_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 50');
coop_layout('Batch Deductions (Payroll / CBU)');
?>
<div class="row g-3">
  <div class="col-xl-6"><div class="card"><div class="card-header">Batches</div><table class="table table-sm table-hover"><thead><tr><th>Batch</th><th>Date</th><th>Description</th><th class="num">Lines</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($batches as $r): ?><tr><td><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>"><?= h($r['batch_no']) ?></a></td><td><?= h(fdate($r['batch_date'])) ?></td><td class="small"><?= h((string)$r['description']) ?></td>
      <td class="num"><?= (int)$r['lines_count'] ?></td><td class="num"><?= cm($r['total']) ?></td><td><?= coop_status($r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="6" class="text-muted">No batch yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="col-xl-6"><?php if (Access::can($pageKey, 'create')): ?>
    <form method="post" enctype="multipart/form-data" class="card" data-once><div class="card-header">New batch</div><div class="card-body row g-2">
      <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="create">
      <?= input_field('batch_date', 'Date', fv('batch_date', null, today()), ['class' => 'col-6 col-md-4', 'type' => 'date', 'required' => true]) ?>
      <?= select_field('source', 'Source', ['payroll' => 'Payroll deduction', 'cbu' => 'Capital build-up', 'collection' => 'Collector\'s list', 'other' => 'Other'], fv('source', null, 'payroll'), ['class' => 'col-6 col-md-4', 'extra' => 'data-no-combo']) ?>
      <?= select_field('contra_account_id', 'Money from (debit)', Accts::all(), fv('contra_account_id', null, $contraDefault), ['class' => 'col-md-4', 'required' => true]) ?>
      <?= input_field('description', 'Description', fv('description', null), ['class' => 'col-md-8', 'extra' => 'placeholder="e.g. DepEd payroll October 15"']) ?>
      <?= Classes::requiredField(fv('class_id', null), 'col-md-4') ?>
      <?= select_field('product_id', 'Product for lines given by member no.', Savings::productOptions(['ordinary', 'special', 'share_capital']), fv('product_id', null), ['class' => 'col-md-6', 'blank' => '', 'extra' => 'data-no-combo']) ?>
      <div class="col-md-6"><label class="form-label">Or a CSV file</label><input class="form-control form-control-sm" type="file" name="file" accept=".csv,.txt,text/csv,text/plain"></div>
      <div class="col-12"><label class="form-label">Lines: account no., loan no. or member no., then the amount (one per line)</label>
        <textarea class="form-control form-control-sm font-monospace" name="lines" rows="8" placeholder="01100001031, 500.00&#10;01-6-0000001, 1833.60&#10;100004, 300"><?= h((string)p('lines')) ?></textarea></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="fill" value="1" id="fill"><label class="form-check-label" for="fill">Add every active member with an account of the product above, for</label></div></div>
      <?= coop_money('fill_amount', 'Amount per member', p('fill_amount'), ['class' => 'col-6 col-md-4']) ?>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-list-check"></i> Check the lines</button> <span class="small text-muted">Nothing is posted until you post the checked batch.</span></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
