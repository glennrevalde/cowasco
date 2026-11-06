<?php
declare(strict_types=1);

require_once APP_ROOT . '/modules/txn/_common.php';
require_once APP_ROOT . '/modules/txn/_grids.php';
require_once APP_ROOT . '/modules/txn/_memorize.php';

$pageKey = 'coa.journal';
if (txn_common_actions($pageKey, ['journal', 'opening_balance'])) {
    return;
}
$a = g('a', 'list');
if ($a === 'list') {
    render_txn_list($pageKey, ['journal', 'opening_balance'], 'Journal Entries', ['statuses' => ['open' => 'Posted', 'void' => 'Void'], 'new_label' => 'New journal entry']);
    return;
}
$t = null;
$lines = [];
if ($a === 'edit') {
    Access::require($pageKey, 'edit');
    $t = Txn::load((int)g('id'));
    if (!$t || !in_array($t['txn_type'], ['journal', 'opening_balance'], true) || (int)$t['is_voided'] === 1) {
        redirect(url($pageKey));
    }
    $lines = Txn::lines((int)$t['id']);
} else {
    Access::require($pageKey, 'create');
}

handle_post(function () use ($t, $pageKey) {
    $lines = parse_journal_lines(grid_rows('lines'));
    if (count($lines) < 2) {
        fail('A journal entry needs at least two lines.');
    }
    $dr = 0.0;
    $cr = 0.0;
    foreach ($lines as $l) {
        if ($l['amount'] > 0) {
            $dr += $l['amount'];
        } else {
            $cr -= $l['amount'];
        }
    }
    if (abs(r2($dr) - r2($cr)) >= 0.01) {
        fail('Debits (' . money($dr) . ') must equal credits (' . money($cr) . ').');
    }
    // accounts marked "With SL" keep a listing of names, so every line to one must carry a name
    Accts::requireSl($lines);
    foreach ($lines as $l) {
        if (in_array($l['account_id'], Accts::arIds(), true) && !$l['customer_id']) {
            fail('Lines posted to Accounts Receivable need a customer name.');
        }
        if (in_array($l['account_id'], Accts::apIds(), true) && !$l['vendor_id']) {
            fail('Lines posted to Accounts Payable need a vendor name.');
        }
    }
    $type = p('is_opening') === '1' ? 'opening_balance' : 'journal';
    $h = ['txn_date' => pdate('txn_date'), 'memo' => p('memo') ?: null, 'ref_no' => p('ref_no') ?: null, 'status' => 'open'];
    if (Classes::ready()) {
        $h['class_id'] = Classes::posted();
    }
    if (p('txn_no') !== '') {
        $h['txn_no'] = mb_substr((string)p('txn_no'), 0, 30);
    }
    if ($t && JournalEdits::ready() && !Memorized::$capture) {
        // a saved entry is changed with a second person's approval (maker and checker): the change is filed with its
        // explanation and made when an approver approves it, here at the desk or later on Journal Entry Changes
        Access::require($pageKey, 'edit');
        if (BusinessUnits::ready() && array_key_exists('business_unit_id', $_POST)) {
            $h['business_unit_id'] = BusinessUnits::valid($_POST['business_unit_id']);
        }
        $what = 'change of journal entry ' . $t['txn_no'];
        $approver = JournalEdits::approverOnForm($what);
        $done = DB::tx(function () use ($t, $type, $h, $lines, $approver) {
            $reqId = JournalEdits::request($t, $type, $h, $lines, (string)p('change_explanation'));
            if ($approver) {
                JournalEdits::approve($reqId, 'Approved at the desk', $approver);
            }
            return $reqId;
        });
        if ($approver) {
            flash('success', 'Journal entry ' . $t['txn_no'] . ' changed, approved by ' . $approver['full_name'] . '. The entry as it was and the reason stay on file.');
        } else {
            flash('info', 'The change to journal entry ' . $t['txn_no'] . ' was sent for approval (request ' . $done . '). The entry stays as it is until an approver approves the change.');
        }
        redirect(url($pageKey, ['a' => 'view', 'id' => $t['id']]));
    }
    $id = Txn::save($type, $h, $lines, $t ? (int)$t['id'] : null, ['reason' => p('reason') ?: null]);
    flash('success', 'Journal entry saved.');
    redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey, ['a' => 'view', 'id' => $id]));
});

if (is_post()) {
    $lines = journal_rows_for_redisplay(grid_rows('lines'));
}
// a new entry filled from a memorized journal entry ("Load Memorized Transaction")
$mem = (!$t && !is_post() && gint('mem')) ? Memorized::forForm((int)gint('mem'), ['journal']) : null;
if ($mem) {
    $lines = $mem['lines'];
}
$rec = $t ?: ($mem['header'] ?? null);
[$memButton, $memBox] = memorize_controls('journal', 'Journal entry');
[$loadButton, $loadList] = !$t ? memorized_loader(['journal'], $pageKey) : ['', ''];
$approvalFlow = $t && JournalEdits::ready();
$pendingChange = $approvalFlow ? JournalEdits::pendingFor((int)$t['id']) : null;
layout_start($t ? 'Edit journal entry ' . $t['txn_no'] : 'Make Journal Entries', ['actions' => $loadButton . ' ' . ($approvalFlow && Access::page('coa.je_edits') ? btn_link(url('coa.je_edits'), 'Journal entry changes', 'btn-outline-secondary', 'shield-check') . ' ' : '') . btn_link(url($pageKey), 'Journal list')]);
echo memorized_loaded_note($mem);
if ($approvalFlow): ?>
<div class="alert alert-info d-flex gap-2 je-approval-note"><i class="bi bi-shield-check"></i><div>
  <strong>Changes to a saved journal entry need approval.</strong> Make the change below and write why it is needed. The entry is changed when a user with the Approve right on Make Journal Entries approves it: at your desk with their own username and password (or an approval code), or later on Journal Entry Changes. You cannot approve your own change.
  <?php if ($pendingChange): ?><div class="mt-1 small">A change requested by <?= h((string)$pendingChange['requester']) ?> on <?= h(fdatetime($pendingChange['requested_at'])) ?> is already waiting; saving another one replaces it.</div><?php endif; ?>
</div></div>
<?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-body row g-2">
  <?= input_field('txn_date', 'Date', fv('txn_date', $rec, today()), ['type' => 'date', 'class' => 'col-md-2']) ?>
  <?= input_field('txn_no', 'Entry no.', fv('txn_no', $t, ''), ['class' => 'col-md-2', 'extra' => 'placeholder="' . h($t ? '' : Seq::peek('journal') . ' (auto)') . '"']) ?>
  <?= input_field('ref_no', 'Reference', fv('ref_no', $rec), ['class' => 'col-md-2']) ?>
  <?= input_field('memo', 'Memo', fv('memo', $rec), ['class' => Classes::ready() ? 'col-md-4' : 'col-md-6']) ?>
  <?= Classes::field(fv('class_id', $rec), 'col-md-2') ?>
  <?= BusinessUnits::field($t, 'journal', 'col-md-2') ?>
  <?= check_field('is_opening', 'Opening balance entry', fv('is_opening', $t ? ['is_opening' => $t['txn_type'] === 'opening_balance' ? 1 : 0] : null, 0), 'col-md-3') ?>
  <?php if ($t && !$approvalFlow): ?><?= input_field('reason', 'Reason for change', fv('reason'), ['class' => 'col-md-9']) ?><?php endif; ?>
</div></div>
<div class="card card-body mb-3"><?= journal_grid($lines) ?>
  <p class="small text-muted mb-0 mt-2">Routine activity posts automatically from invoices, bills, payments and the POS. Use journal entries for opening balances, accruals, corrections and closing entries.
    <?php if (Classes::ready()): ?>The class at the top applies to every line; choose a class on a line only when that line belongs to a different one.<?php endif; ?></p></div>
<?php if ($approvalFlow): ?>
<div class="card mb-3 border-warning-subtle"><div class="card-body row g-2">
  <div class="col-12"><label class="form-label" for="changeWhy">Explanation: why is this journal entry changed? (required)</label>
    <textarea class="form-control form-control-sm" id="changeWhy" name="change_explanation" rows="2" maxlength="1000" required minlength="<?= JournalEdits::MIN_EXPLANATION ?>" placeholder="e.g. Wrong expense account: rent was charged to utilities (OR 5543)"><?= h((string)fv('change_explanation')) ?></textarea></div>
  <div class="col-12"><?= Approval::fields() ?><div class="small text-muted mt-1">Leave the approval empty to send the change for approval; the approver sees the entry before and after.</div></div>
</div></div>
<?php endif; ?>
<div class="d-flex flex-wrap gap-2 align-items-center">
  <?= $approvalFlow ? '' : posted_change_field($t) ?>
  <?= $approvalFlow ? '<div class="d-flex gap-1 flex-wrap align-items-center"><button class="btn btn-primary btn-sm" name="save" value="1"><i class="bi bi-send-check"></i> Save the change</button> <a class="btn btn-outline-secondary btn-sm" href="' . h(url($pageKey, ['a' => 'view', 'id' => $t['id']])) . '">Cancel</a></div>'
      : save_buttons('Save entry', $t ? url($pageKey, ['a' => 'view', 'id' => $t['id']]) : url($pageKey), Access::can($pageKey, 'create')) ?>
  <?php if ($memButton): ?><div class="ms-auto"><?= $memButton ?></div><?php endif; ?>
</div>
<?= $memBox ?>
</form>
<?= $loadList ?>
<?php
layout_end();
