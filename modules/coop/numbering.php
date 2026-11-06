<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.numbering';
$seqs = [
    'member' => 'Member number', 'loan_application' => 'Loan application', 'loan' => 'Loan account ({SEQ} of the loan number format)', 'pn' => 'Promissory note',
    'coop_or' => 'Official receipt (each branch adds its own prefix)', 'coop_cv' => 'Member disbursement voucher', 'coop_jv' => 'Cooperative journal voucher', 'loan_release' => 'Loan release voucher ID (Voucher ID of the Cash / Check Voucher of a loan release)',
    'td_certificate' => 'Time deposit certificate', 'share_certificate' => 'Share capital certificate', 'balance_certificate' => 'Certificate of balances', 'sav_account' => 'Savings account ({SEQ} of the account number format)',
    'sav_batch' => 'Batch deduction', 'dist_batch' => 'Distribution batch', 'passbook' => 'Passbook',
    'sav_trans' => 'Savings transaction (Savings Transaction Center: deposits, withdrawals, error corrections, memos, time deposits)',
];

handle_post(function () use ($pageKey, $seqs) {
    Access::require($pageKey, 'edit');
    $changes = [];
    DB::tx(function () use ($seqs, &$changes) {
        foreach ($seqs as $key => $label) {
            if (!isset($_POST['seq'][$key])) {
                continue;
            }
            $in = $_POST['seq'][$key];
            $old = DB::row('SELECT * FROM sequences WHERE seq_key = :k FOR UPDATE', ['k' => $key]);
            $new = ['prefix' => mb_substr(trim((string)($in['prefix'] ?? '')), 0, 10), 'next_no' => max(1, (int)($in['next_no'] ?? 1)), 'pad' => max(0, min(12, (int)($in['pad'] ?? 0)))];
            if (!$old) {
                DB::insert('sequences', ['seq_key' => $key] + $new);
                $changes[] = ['field' => $key, 'old' => '', 'new' => json_encode($new)];
                continue;
            }
            if ($old['prefix'] !== $new['prefix'] || (int)$old['next_no'] !== $new['next_no'] || (int)$old['pad'] !== $new['pad']) {
                if ($new['next_no'] < (int)$old['next_no'] && p('allow_lower') !== '1') {
                    fail($label . ': the next number would go back from ' . $old['next_no'] . ' to ' . $new['next_no'] . ' and numbers already issued could repeat. Tick "allow going back" if this is intended.');
                }
                DB::update('sequences', $new, 'seq_key = :k', ['k' => $key]);
                $changes[] = ['field' => $key, 'old' => $old['prefix'] . '/' . $old['next_no'] . '/' . $old['pad'], 'new' => $new['prefix'] . '/' . $new['next_no'] . '/' . $new['pad']];
            }
        }
    });
    if ($changes) {
        Audit::log('settings', 'coop', 'sequences', null, 'Cooperative document numbering', ['page_key' => $pageKey, 'changes' => $changes]);
    }
    flash('success', $changes ? 'Numbering saved.' : 'Nothing was changed.');
    redirect(url($pageKey));
});

$rows = DB::pairs('SELECT seq_key, CONCAT(prefix, "|", next_no, "|", pad) FROM sequences');
coop_layout('Document Numbering');
?>
<form method="post" data-once>
  <?= csrf_field() ?>
  <div class="card"><table class="table table-sm"><thead><tr><th>Document</th><th>Prefix</th><th>Next number</th><th>Digits</th><th>Next one will be</th></tr></thead><tbody>
  <?php foreach ($seqs as $key => $label): [$prefix, $next, $pad] = explode('|', $rows[$key] ?? '|1|6'); ?>
    <tr><td><?= h($label) ?></td>
      <td><input class="form-control form-control-sm" style="max-width:110px" name="seq[<?= h($key) ?>][prefix]" value="<?= h($prefix) ?>"></td>
      <td><input class="form-control form-control-sm" style="max-width:140px" type="number" step="1" min="1" name="seq[<?= h($key) ?>][next_no]" value="<?= (int)$next ?>"></td>
      <td><input class="form-control form-control-sm" style="max-width:90px" type="number" step="1" min="0" max="12" name="seq[<?= h($key) ?>][pad]" value="<?= (int)$pad ?>"></td>
      <td class="font-monospace"><?= h($prefix . str_pad((string)$next, (int)$pad, '0', STR_PAD_LEFT)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <p class="small text-muted mt-2">Account number formats are set on Cooperative Profile &amp; Policies. Numbers are drawn inside the saving transaction with a row lock, so two users never get the same number; a number typed by hand (a pre-printed receipt) is checked against those already used.</p>
  <?php if (Access::can($pageKey, 'edit')): ?><div class="d-flex gap-3 align-items-center">
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save numbering</button>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="allow_lower" value="1" id="allowLower"><label class="form-check-label small" for="allowLower">Allow a next number lower than now (risk of repeats)</label></div>
  </div><?php endif; ?>
</form>
<?php
layout_end();
