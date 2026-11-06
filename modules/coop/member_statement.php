<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.member_statement';
$m = coop_member_param();
$a = (string)g('a');

// ------------------------------------------------------------------ member ID card
if ($a === 'id_card' && $m) {
    $copy = coop_print_gate('member_id', 'member', (int)$m['id'], $m['member_no'], $pageKey, 'member ID card');
    if ($copy === null) {
        exit;
    }
    layout_start('ID ' . $m['member_no'], ['bare' => true]);
    $type = Members::types()[(int)$m['member_type_id']]['name'] ?? '';
    ?>
    <div class="d-flex gap-4 flex-wrap justify-content-center mt-3">
      <div class="id-card">
        <?= $m['photo_file'] ? '<img src="' . h(url('file.member', ['id' => $m['id'], 'f' => 'photo'])) . '" alt="">' : '<span class="member-photo is-blank" style="border-radius:6px;font-size:1.6rem">' . h(mb_strtoupper(mb_substr((string)$m['first_name'], 0, 1) . mb_substr((string)$m['last_name'], 0, 1))) . '</span>' ?>
        <div>
          <div class="small fw-bold"><?= h(Coop::coopName()) ?></div>
          <div class="small text-muted mb-1">Member identification</div>
          <div class="id-name"><?= h($m['full_name']) ?></div>
          <div>Member no. <strong><?= h($m['member_no']) ?></strong></div>
          <div><?= h($type) ?>, since <?= h(fdate($m['membership_date'])) ?></div>
          <div class="small"><?= h(Coop::branchName($m['branch_id'] ? (int)$m['branch_id'] : null)) ?></div>
          <?= $copy > 1 ? '<div class="small text-danger">Reissued (copy ' . ($copy - 1) . ')</div>' : '' ?>
        </div>
      </div>
      <div class="id-card" style="display:block">
        <div class="small">Date of birth: <?= h(fdate($m['birth_date'])) ?></div>
        <div class="small">Address: <?= h(trim(implode(', ', array_filter([$m['res_street'], $m['res_barangay'], $m['res_city'], $m['res_province']])))) ?></div>
        <div class="small">Mobile: <?= h((string)$m['mobile']) ?></div>
        <div class="small mt-2">In case of emergency, contact the cooperative office.</div>
        <div class="mt-3" style="border-top:1px solid #333;width:60%;font-size:8pt">Member's signature</div>
        <?= $m['signature_file'] ? '<img src="' . h(url('file.member', ['id' => $m['id'], 'f' => 'signature'])) . '" alt="" style="max-height:28px;width:auto;height:auto;border-radius:0">' : '' ?>
        <div class="mt-2" style="font-size:8pt"><?= h(Coop::setting('signatory_gm')) ?><br>General Manager</div>
      </div>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ share capital certificate
if ($a === 'certificate' && $m) {
    $paid = Coop::memberSummary((int)$m['id'])['share_capital'];
    // par value: the member's own, else the share capital product's, else the cooperative's default
    $productPar = Dec::of(DB::value("SELECT p.par_value FROM sav_accounts a JOIN sav_products p ON p.id = a.product_id WHERE a.member_id = :m AND p.product_type = 'share_capital' AND a.status <> 'closed' ORDER BY a.id LIMIT 1", ['m' => $m['id']]) ?? '0');
    $par = Dec::isPos($m['par_value']) ? Dec::of($m['par_value']) : (Dec::isPos($productPar) ? $productPar : Dec::of(Coop::setting('default_par_value', '100')));
    $shares = Dec::isPos($par) ? Dec::round(Dec::div($paid, $par, 6), 0) : '0';
    $existing = DB::value("SELECT doc_no FROM document_prints WHERE doc_key = 'share_certificate' AND ref_type = 'member' AND ref_id = :m ORDER BY id LIMIT 1", ['m' => $m['id']]);
    $certNo = $existing ?: Seq::peek('share_certificate');
    $copy = coop_print_gate('share_certificate', 'member', (int)$m['id'], (string)$certNo, $pageKey, 'share capital certificate');
    if ($copy === null) {
        exit;
    }
    if (!$existing) {
        Seq::next('share_certificate');
    }
    layout_start('Share certificate ' . $certNo, ['bare' => true]);
    echo '<div class="doc coop-doc"><div class="cert-frame">' . coop_copy_mark($copy) . coop_print_head('SHARE CAPITAL CERTIFICATE', (string)$certNo, today())
        . '<div class="doc-clause mt-4">' . CoopDocs::render('share_certificate', today(), ['coop' => Coop::coopName(), 'member' => $m['full_name'], 'member_no' => $m['member_no'], 'certificate_no' => $certNo,
            'shares' => number_format((float)$shares), 'par_value' => Dec::money($par), 'amount' => Dec::money($paid), 'amount_words' => amount_in_words((float)$paid), 'as_of' => fdate(today())]) . '</div>'
        . '<div class="text-center my-3"><span class="cert-amount">PHP ' . cm($paid) . '</span></div>'
        . coop_signatures(['Chairperson' => Coop::setting('signatory_chair'), 'Secretary / Treasurer' => Coop::setting('signatory_treasurer'), 'General Manager' => Coop::setting('signatory_gm')]) . '</div></div>';
    echo '<script>window.addEventListener("load", function () { window.print(); });</script>';
    layout_end();
    exit;
}

// ------------------------------------------------------------------ certificate of balances
// Issued from the statement page (POST, once): each issue takes the next number and is recorded in the print history.
handle_post(function () use ($pageKey, $m) {
    if (p('action') !== 'balance_cert' || !$m) {
        fail('Unknown action.');
    }
    Access::require($pageKey, 'print');
    coop_once();
    $asOf = pdate('as_of');
    if (!$asOf || $asOf > today()) {
        fail('Enter an as-of date that is not in the future.');
    }
    $purpose = trim((string)p('purpose'));
    if ($purpose === '') {
        fail('Enter the purpose the member asked the certificate for.');
    }
    $no = Seq::next('balance_certificate');
    DB::insert('document_prints', ['doc_key' => 'balance_certificate', 'ref_type' => 'member', 'ref_id' => $m['id'], 'doc_no' => mb_substr($no, 0, 40), 'copy_no' => 1,
        'reason' => mb_substr('As of ' . $asOf . ': ' . $purpose, 0, 255), 'printed_by' => Auth::id()]);
    Audit::log('print', 'coop', 'member', (int)$m['id'], 'balance_certificate ' . $no . ' as of ' . $asOf . ' for ' . $purpose, ['page_key' => $pageKey]);
    $_SESSION['coop_balance_cert'][$no] = ['member' => (int)$m['id'], 'as_of' => $asOf, 'purpose' => mb_substr($purpose, 0, 200), 'issued' => today()];
    redirect(url($pageKey, ['a' => 'balance_cert', 'member' => $m['id'], 'no' => $no]));
});

if ($a === 'balance_cert' && $m) {
    Access::require($pageKey, 'print');
    $no = (string)g('no');
    $c = $_SESSION['coop_balance_cert'][$no] ?? null;
    if (!$c || $c['member'] !== (int)$m['id']) {
        flash('warning', 'Issue a new certificate of balances from the member statement.');
        redirect(url($pageKey, ['member' => $m['id']]));
    }
    $b = Members::balancesAsOf((int)$m['id'], $c['as_of']);
    // what is past due can only be worked out for today (the schedule shows today's payments)
    $current = $c['as_of'] === today();
    $pastDue = [];
    if ($current) {
        foreach ($b['loans'] as $l) {
            $loan = Loans::loan((int)$l['id']);
            $pastDue[$l['id']] = in_array($loan['status'], Loans::OPEN_STATUSES, true) ? Loans::dues($loan, today())['total_due'] : '0';
        }
    }
    $type = Members::types()[(int)$m['member_type_id']]['name'] ?? 'regular';
    layout_start('Certificate ' . $no, ['bare' => true]);
    ?>
    <div class="no-print mb-2"><a href="<?= h(url($pageKey, ['member' => $m['id']])) ?>">Back to the statement</a></div>
    <div class="doc coop-doc">
      <?= coop_print_head('CERTIFICATE OF BALANCES', $no, $c['issued']) ?>
      <div class="doc-clause mt-4"><?= CoopDocs::render('balance_certificate', $c['issued'], ['coop' => Coop::coopName(), 'member' => $m['full_name'], 'member_no' => $m['member_no'],
          'member_type' => mb_strtolower($type), 'membership_date' => fdate($m['membership_date']), 'status' => Coop::MEMBER_STATUSES[$m['status']] ?? $m['status'],
          'as_of' => fdate($c['as_of']), 'purpose' => $c['purpose'], 'today' => fdate($c['issued'])]) ?></div>
      <table class="table table-sm mt-3">
        <thead><tr><th>Account</th><th>Account no.</th><th class="num">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($b['accounts'] as $x): ?>
          <tr><td><?= h($x['product_name']) ?></td><td><?= h($x['account_no']) ?></td><td class="num"><?= cm($x['balance']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$b['accounts']): ?><tr><td colspan="3" class="text-muted">No share capital or deposit account.</td></tr><?php endif; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="2">Total paid-up share capital</td><td class="num fw-semibold"><?= cm($b['totals']['share_capital']) ?></td></tr>
          <tr><td colspan="2">Total deposits</td><td class="num fw-semibold"><?= cm($b['totals']['deposits']) ?></td></tr>
        </tfoot>
      </table>
      <table class="table table-sm mt-3">
        <thead><tr><th>Loan</th><th>Released</th><th>Maturity</th><?php if ($current): ?><th class="num">Past due today</th><?php endif; ?><th class="num">Principal balance</th></tr></thead>
        <tbody>
        <?php foreach ($b['loans'] as $l): ?>
          <tr><td><?= h($l['product_name']) ?> <?= h($l['loan_no']) ?></td><td><?= h(fdate($l['release_date'])) ?></td><td><?= h(fdate($l['due_date'])) ?></td>
            <?php if ($current): ?><td class="num"><?= cm($pastDue[$l['id']] ?? '0') ?></td><?php endif; ?><td class="num"><?= cm($l['principal_balance']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$b['loans']): ?><tr><td colspan="<?= $current ? 5 : 4 ?>">No outstanding loan.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($b['loans']): ?><tfoot><tr><td colspan="<?= $current ? 4 : 3 ?>">Total loan principal outstanding</td><td class="num fw-semibold"><?= cm($b['totals']['loans']) ?></td></tr></tfoot><?php endif; ?>
      </table>
      <p class="mt-3">This certification is issued on <?= h(fdate($c['issued'])) ?> upon the request of the member for <?= h($c['purpose']) ?>.
        <?= $current ? 'Past due amounts include the interest and penalties due today.' : 'Loan balances are principal; interest is charged by the schedule and is not included.' ?></p>
      <p class="small">Not valid without the seal of the Cooperative or with erasures or alterations.</p>
      <?= coop_signatures(['Prepared by' => (string)(Auth::user()['full_name'] ?? ''), 'Certified correct' => Coop::setting('signatory_bookkeeper'), 'Noted' => Coop::setting('signatory_gm')]) ?>
    </div>
    <script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

// ------------------------------------------------------------------ statement of account
$from = gdate('from', date('Y-01-01'));
$to = gdate('to', today());
$st = $m ? Members::statement((int)$m['id'], $from, $to) : null;
$print = g('print') === '1';
if ($print && $st) {
    Access::require($pageKey, 'print');
    Audit::log('print', 'coop', 'member', (int)$m['id'], 'Statement ' . $m['full_name'] . ' ' . $from . ' to ' . $to, ['page_key' => $pageKey]);
}
$actions = '';
if ($st && Access::can($pageKey, 'print')) {
    $actions = btn_link(current_url(['print' => 1]), 'Print', 'btn-outline-secondary', 'printer') . ' '
        . btn_link(url($pageKey, ['a' => 'certificate', 'member' => $m['id']]), 'Share certificate', 'btn-outline-secondary', 'award') . ' '
        . btn_link(url($pageKey, ['a' => 'id_card', 'member' => $m['id']]), 'ID card', 'btn-outline-secondary', 'person-vcard') . ' '
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#balCert"><i class="bi bi-file-earmark-check"></i> Certificate of balances</button>';
}
if ($print) {
    layout_start('Statement of Account', ['bare' => true]);
} else {
    coop_layout('Member Statement of Account', ['actions' => $actions]);
    ?>
    <form class="row g-2 align-items-end filters mb-3 no-print">
      <input type="hidden" name="p" value="<?= h($pageKey) ?>">
      <?= member_pick('member', $m ? (int)$m['id'] : null, ['class' => 'col-md-5', 'submit' => true, 'autofocus' => !$m]) ?>
      <?= input_field('from', 'From', $from, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <?= input_field('to', 'To', $to, ['type' => 'date', 'class' => 'col-6 col-md-2']) ?>
      <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button></div>
    </form>
    <?php
}
if ($st):
    $mm = $st['member'];
?>
<div class="<?= $print ? 'doc coop-doc' : '' ?>">
  <?php if ($print): ?><?= coop_print_head('STATEMENT OF ACCOUNT', '', today(), ['Period' => fdate($from) . ' to ' . fdate($to)]) ?><?php endif; ?>
  <div class="<?= $print ? 'my-3' : 'mb-3' ?>"><?= $print ? '<strong>' . h($mm['full_name']) . '</strong>, member no. ' . h($mm['member_no']) . '<br>' . h(trim(implode(', ', array_filter([$mm['res_street'], $mm['res_barangay'], $mm['res_city'], $mm['res_province']])))) : member_card($mm) ?></div>
  <?php foreach ($st['accounts'] as $acc): if ($acc['status'] === 'closed' && !$acc['txns'] && Dec::isZero($acc['opening'])) { continue; } ?>
  <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span><?= h($acc['product_name']) ?> <?= h($acc['account_no']) ?></span><span class="small text-muted"><?= h(Savings::TYPES[$acc['product_type']] ?? '') ?></span></div>
    <table class="table table-sm"><thead><tr><th>Date</th><th>Code</th><th>Particulars</th><th>OR / ref.</th><th class="num">Withdrawals</th><th class="num">Deposits</th><th class="num">Balance</th></tr></thead><tbody>
      <tr class="text-muted"><td><?= h(fdate($from)) ?></td><td></td><td>Balance forwarded</td><td></td><td></td><td></td><td class="num"><?= cm($acc['opening']) ?></td></tr>
      <?php $bal = $acc['opening']; foreach ($acc['txns'] as $t): $bal = Dec::add(Dec::sub($bal, $t['debit'], 2), $t['credit'], 2); ?>
      <tr><td><?= h(fdate($t['txn_date'])) ?></td><td><?= h($t['txn_code']) ?></td><td><?= h((string)$t['description']) ?><?= (int)$t['is_cleared'] === 0 ? ' <span class="small text-warning">clearing</span>' : '' ?></td>
        <td class="small"><?= h((string)$t['or_no']) ?></td><td class="num"><?= cm($t['debit'], true) ?></td><td class="num"><?= cm($t['credit'], true) ?></td><td class="num"><?= cm($bal) ?></td></tr>
      <?php endforeach; ?>
    </tbody><tfoot><tr><td colspan="6" class="fw-semibold">Balance <?= h(fdate($to)) ?></td><td class="num fw-semibold"><?= cm($acc['closing']) ?></td></tr></tfoot></table>
    <?php foreach ($acc['td'] as $td): ?><div class="card-body small pt-2">Certificate <?= h($td['certificate_no']) ?>: <?= cm($td['principal']) ?> at <?= h(Dec::fix($td['rate'], 2)) ?> %, <?= h(fdate($td['placement_date'])) ?> to <?= h(fdate($td['maturity_date'])) ?>, maturity value <?= cm($td['maturity_value']) ?>, <?= h(str_replace('_', ' ', $td['status'])) ?></div><?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <?php foreach ($st['loans'] as $l): if (in_array($l['status'], ['paid', 'renewed', 'cancelled', 'written_off'], true) && !$l['txns']) { continue; } ?>
  <div class="card mb-3"><div class="card-header d-flex justify-content-between"><span><?= h($l['product_name']) ?> <?= h($l['loan_no']) ?></span><span class="small"><?= cm($l['principal']) ?> released <?= h(fdate($l['release_date'])) ?>, matures <?= h(fdate($l['due_date'])) ?>, <?= coop_status($l['status']) ?></span></div>
    <table class="table table-sm"><thead><tr><th>Date</th><th>Entry</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th class="num">Principal balance</th></tr></thead><tbody>
    <?php foreach ($l['txns'] as $t): if ($t['kind'] === 'accrual') { continue; } ?>
      <tr><td><?= h(fdate($t['txn_date'])) ?></td><td><?= h(Loans::ledgerKind($t)) ?><?= $t['or_no'] ? ' OR ' . h($t['or_no']) : '' ?></td>
        <td class="num"><?= cm(Dec::sub($t['principal_cr'], $t['principal_dr'], 2), true) ?></td><td class="num"><?= cm(Dec::sub($t['interest_cr'], $t['interest_dr'], 2), true) ?></td>
        <td class="num"><?= cm(Dec::sub($t['penalty_cr'], $t['penalty_dr'], 2), true) ?></td><td class="num"><?= cm($t['principal_bal']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$l['txns']): ?><tr><td colspan="6" class="text-muted">No movement in the period.</td></tr><?php endif; ?>
    </tbody><tfoot><tr><td colspan="5" class="fw-semibold">Outstanding: principal <?= cm($l['principal_balance']) ?>, interest <?= cm($l['interest_balance']) ?>, penalties <?= cm($l['penalty_balance']) ?></td><td class="num fw-semibold"><?= cm($l['principal_balance']) ?></td></tr></tfoot></table>
  </div>
  <?php endforeach; ?>
  <?php if ($st['distributions']): ?>
  <div class="card mb-3"><div class="card-header">Interest on share capital and patronage refund</div><table class="table table-sm"><thead><tr><th>Batch</th><th>Kind</th><th>Period ending</th><th class="num">Basis</th><th class="num">Amount</th></tr></thead><tbody>
    <?php foreach ($st['distributions'] as $d): ?><tr><td><?= h($d['batch_no']) ?></td><td><?= $d['kind'] === 'isc' ? 'Interest on share capital' : 'Patronage refund' ?></td><td><?= h(fdate($d['date_to'])) ?></td><td class="num"><?= cm($d['basis_amount']) ?></td><td class="num"><?= cm($d['net_amount']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($print): ?><p class="small mt-3">Please examine this statement and report any discrepancy to the cooperative within fifteen (15) days; otherwise it will be considered correct.</p>
    <?= coop_signatures(['Prepared by' => (string)(Auth::user()['full_name'] ?? ''), 'Certified correct' => Coop::setting('signatory_bookkeeper')]) ?>
    <script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</div>
<?php if (!$print && Access::can($pageKey, 'print')): ?>
<div class="modal fade" id="balCert" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" data-once>
  <?= csrf_field() ?><?= Coop::onceField() ?><input type="hidden" name="action" value="balance_cert"><input type="hidden" name="member" value="<?= (int)$mm['id'] ?>">
  <div class="modal-header"><h5 class="modal-title">Certificate of balances</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <?= input_field('as_of', 'Balances as of', (string)p('as_of', today()), ['type' => 'date', 'class' => 'col-md-5', 'required' => true, 'extra' => 'max="' . h(today()) . '"']) ?>
    <?= input_field('purpose', 'Purpose', (string)p('purpose'), ['class' => 'col-md-7', 'required' => true, 'extra' => 'maxlength="200" placeholder="e.g. loan application with another lender"']) ?>
    <div class="col-12 form-text">Each certificate takes the next number and is kept in the document print history.</div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-sm btn-primary"><i class="bi bi-printer"></i> Issue and print</button></div>
</form></div></div>
<?php endif; ?>
<?php elseif (!$print): ?>
  <div class="text-muted">Choose a member to see their statement.</div>
<?php endif;
layout_end();
