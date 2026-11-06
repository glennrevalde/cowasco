<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'loan.application';
$centerKey = 'loan.applications';
$app = gint('id') ? Loans::application((int)gint('id')) : null;
if ($app && !Coop::branchAllowed($app['branch_id'] ? (int)$app['branch_id'] : null)) {
    Access::denyPage($pageKey, 'open an application of another branch on');
}
$editing = !$app || g('a') === 'edit';
$check = null;

handle_post(function () use ($app, $pageKey, $centerKey, &$check) {
    $action = (string)p('action', 'save');
    if ($action === 'save') {
        Access::require($pageKey, $app ? 'edit' : 'create');
        $productId = (int)pint('product_id');
        $p = $productId ? Loans::product($productId) : null;
        $rate = (string)p('rate_choice');
        if ($rate !== '' && $p) {
            $allowed = array_filter([$p['rate_default'], $p['rate_opt1'], $p['rate_opt2'], $p['rate_opt3']], fn($x) => $x !== null);
            if (!in_array($rate, array_map(fn($x) => Dec::fix($x, 4), $allowed), true)) {
                fail('Choose one of the product\'s rates.');
            }
        }
        $d = ['member_id' => (int)pint('member_id'), 'product_id' => $productId, 'application_date' => (string)p('application_date'), 'purpose' => mb_substr(trim((string)p('purpose')), 0, 80) ?: null,
            'purpose_detail' => mb_substr(trim((string)p('purpose_detail')), 0, 255) ?: null, 'term' => (string)p('term'), 'term_unit' => (string)p('term_unit'), 'mode_of_payment' => (string)p('mode_of_payment'),
            'applied_amount' => Coop::amount('applied_amount', true), 'rate' => $rate !== '' ? $rate : null, 'amort_type' => (string)p('amort_type'), 'monthly_income' => Coop::amount('monthly_income'),
            'spouse_income' => Coop::amount('spouse_income'), 'ndi' => Coop::amount('ndi'), 'dependents' => (int)p('dependents'), 'employer_name' => mb_substr(trim((string)p('employer_name')), 0, 120) ?: null,
            'business_name' => mb_substr(trim((string)p('business_name')), 0, 120) ?: null, 'spouse_name' => mb_substr(trim((string)p('spouse_name')), 0, 120) ?: null,
            'officer_id' => pint('officer_id') ?: null, 'remarks' => mb_substr(trim((string)p('remarks')), 0, 255) ?: null,
            'previous_loan_id' => pint('previous_loan_id') ?: null, 'is_restructure' => p('is_restructure') === '1' ? 1 : 0];
        if ($d['rate'] === null) {
            unset($d['rate']);
        }
        $id = Loans::saveApplication($d, coop_grid_posted('collaterals'), coop_grid_posted('comakers'), $app ? (int)$app['id'] : null, $pageKey);
        // the pre-release checklist of the product is listed once on the application
        $reqs = (array)json_decode((string)(Loans::product($productId)['requirements_json'] ?? '[]'), true);
        foreach ($reqs as $req) {
            if (!DB::value('SELECT id FROM loan_application_docs WHERE application_id = :a AND requirement = :r', ['a' => $id, 'r' => $req])) {
                DB::insert('loan_application_docs', ['application_id' => $id, 'requirement' => mb_substr($req, 0, 120), 'is_complete' => 0]);
            }
        }
        flash('success', $app ? 'Application saved.' : 'Application saved. Validate it to run the eligibility checks.');
        redirect(url($pageKey, ['id' => $id]));
    }
    if (!$app) {
        fail('Application not found.');
    }
    switch ($action) {
        case 'validate':
            Access::require($pageKey, 'edit');
            $r = Loans::validateApplication((int)$app['id'], trim((string)p('override')), $centerKey);
            if (!$r['ok']) {
                $check = $r['checks'];
                $GLOBALS['FORM_ERROR'] = 'Some checks failed. ' . (Access::can($centerKey, 'approve') ? 'Enter the reason to override them (recorded in the audit trail), or correct the application.' : 'Correct the application, or ask an officer who may approve to override.');
                return;
            }
            flash('success', $r['status'] === 'for_ci' ? 'Validated. The product needs a credit investigation next.' : 'Validated. Submit it for approval.');
            break;
        case 'submit':
            Access::require($pageKey, 'edit');
            Loans::submitForApproval((int)$app['id'], $centerKey);
            flash('success', 'Submitted for approval.');
            break;
        case 'act':
            Loans::act((int)$app['id'], (string)p('decision'), trim((string)p('approved_amount')) !== '' ? Coop::amount('approved_amount') : null, trim((string)p('remarks')), $centerKey);
            flash('success', 'Recorded: ' . (Loans::APP_STATUSES[Loans::application((int)$app['id'])['status']] ?? '') . '.');
            break;
        case 'cancel':
            Access::require($pageKey, 'edit');
            Loans::cancelApplication((int)$app['id'], trim((string)p('reason')), $centerKey);
            flash('success', 'Application cancelled.');
            break;
        case 'delete':
            Access::require($pageKey, 'delete');
            Loans::deleteApplication((int)$app['id'], trim((string)p('reason')), $pageKey);
            flash('success', 'Application ' . $app['application_no'] . ' deleted.');
            redirect(url($centerKey));
        case 'doc':
            Access::require($pageKey, 'edit');
            $doc = DB::row('SELECT * FROM loan_application_docs WHERE id = :d AND application_id = :a', ['d' => pint('doc_id'), 'a' => $app['id']]);
            if (!$doc) {
                fail('Requirement not found.');
            }
            $upd = ['is_complete' => p('is_complete') === '1' ? 1 : 0, 'remarks' => mb_substr(trim((string)p('doc_remarks')), 0, 255) ?: null];
            $f = Members::storeUpload($_FILES['file'] ?? null, (int)$app['member_id'], 'loandoc', false);
            if ($f) {
                $upd['file_name'] = $f[0];
                $upd['original_name'] = $f[1];
            }
            DB::update('loan_application_docs', $upd, 'id = :id', ['id' => $doc['id']]);
            Audit::log('edit', 'loan', 'loan_application', (int)$app['id'], $app['application_no'] . ': ' . $doc['requirement'], ['page_key' => $pageKey, 'reason' => $upd['is_complete'] ? 'Complete' : 'Not complete']);
            flash('success', 'Requirement updated.');
            break;
        case 'add_doc':
            Access::require($pageKey, 'edit');
            $req = mb_substr(trim((string)p('requirement')), 0, 120);
            if ($req === '') {
                fail('Enter the requirement.');
            }
            DB::insert('loan_application_docs', ['application_id' => $app['id'], 'requirement' => $req, 'is_complete' => 0]);
            break;
        default:
            fail('Unknown action.');
    }
    redirect(url($pageKey, ['id' => $app['id']]));
});

$m = $app ? Coop::member((int)$app['member_id']) : (coop_member_param() ?: (pint('member_id') ? Coop::member((int)pint('member_id')) : null));
$products = Loans::productOptions();
$users = DB::pairs("SELECT id, full_name FROM users WHERE status = 'active' AND COALESCE(is_platform,0) = 0 ORDER BY full_name");

// --------------------------------------------------------------------------------------- the form
if ($editing) {
    Access::require($pageKey, $app ? 'edit' : 'create');
    if ($app && !in_array($app['status'], ['new', 'validated', 'returned', 'for_ci'], true)) {
        flash('warning', 'The application can no longer be edited.');
        redirect(url($pageKey, ['id' => $app['id']]));
    }
    $rec = $app ?: ['application_date' => today(), 'term_unit' => 'months', 'term' => '12', 'member_id' => $m['id'] ?? null, 'officer_id' => Auth::id()];
    // Capacity to pay: a new application starts from the entries of the borrower's last loan (or, for a first loan, the
    // member's Financial Information); an application being edited shows its own entries as last saved
    $capNote = 'Choose the member: Capacity to pay is filled from the borrower\'s last loan application, or for a first loan from the Financial Information tab.';
    if (!$app && $m) {
        $cap = Loans::capacityStart((int)$m['id']);
        $rec += $cap['data'];
        $capNote = coop_capacity_note($cap);
    } elseif ($app) {
        $capNote = 'As saved with this application' . (!empty($app['updated_at']) ? ' on ' . fdate(substr((string)$app['updated_at'], 0, 10)) : '') . '. What is saved here is where the borrower\'s next loan starts.';
    }
    $prodRates = [];
    foreach (DB::all('SELECT * FROM loan_products WHERE is_active = 1') as $pp) {
        $prodRates[$pp['id']] = ['rates' => array_values(array_map(fn($x) => Dec::fix($x, 4), array_filter([$pp['rate_default'], $pp['rate_opt1'], $pp['rate_opt2'], $pp['rate_opt3']], fn($x) => $x !== null))),
            'mode' => $pp['mode_of_payment'], 'unit' => $pp['term_unit'], 'amort' => $pp['amort_type'], 'method' => $pp['interest_method'], 'min' => Dec::fix($pp['term_min'], 0), 'max' => Dec::fix($pp['term_max'], 0),
            'amin' => Dec::fix($pp['amount_min']), 'amax' => Dec::fix($pp['amount_max'])];
    }
    $coll = is_post() ? coop_grid_posted('collaterals') : ($app ? DB::all('SELECT * FROM loan_collaterals WHERE application_id = :a', ['a' => $app['id']]) : []);
    $com = is_post() ? coop_grid_posted('comakers') : ($app ? DB::all('SELECT * FROM loan_comakers WHERE application_id = :a', ['a' => $app['id']]) : []);
    coop_layout($app ? 'Edit application ' . $app['application_no'] : 'New loan application', ['actions' => btn_link($app ? url($pageKey, ['id' => $app['id']]) : url($centerKey), 'Back', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <form method="post" data-once>
      <?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="row g-3">
        <div class="col-xl-7">
          <div class="card mb-3"><div class="card-header">Loan applied for</div><div class="card-body row g-2">
            <?= member_pick('member_id', $m ? (int)$m['id'] : null, ['class' => 'col-md-8', 'required' => true, 'status' => 'active']) ?>
            <?= input_field('application_date', 'Date', fv('application_date', $rec), ['class' => 'col-md-4', 'type' => 'date', 'required' => true]) ?>
            <?= select_field('product_id', 'Loan product', $products, fv('product_id', $rec), ['class' => 'col-md-6', 'required' => true, 'blank' => '', 'extra' => 'id="lProduct" data-no-combo']) ?>
            <div class="col-md-6"><label class="form-label">Amount applied for</label><input class="form-control form-control-sm text-end" name="applied_amount" value="<?= h(is_post() ? (string)p('applied_amount') : ($app ? Dec::money($app['applied_amount']) : '')) ?>" inputmode="decimal" data-money required id="lAmount"><div class="form-text" id="lAmountHint"></div></div>
            <?= input_field('term', 'Term', fv('term', $rec), ['class' => 'col-4 col-md-2', 'extra' => 'inputmode="decimal" id="lTerm"']) ?>
            <?= select_field('term_unit', 'in', ['months' => 'months', 'days' => 'days'], fv('term_unit', $rec), ['class' => 'col-4 col-md-2', 'extra' => 'id="lUnit" data-no-combo']) ?>
            <?= select_field('mode_of_payment', 'Mode of payment', Coop::modeOptions(), fv('mode_of_payment', $rec, 'monthly'), ['class' => 'col-4 col-md-4', 'extra' => 'id="lMode" data-no-combo']) ?>
            <div class="col-md-4"><label class="form-label">Rate</label><select class="form-select form-select-sm" name="rate_choice" id="lRate" data-no-combo><option value="">By the product (term / amount)</option>
              <?php foreach ($prodRates[(int)fv('product_id', $rec, 0)]['rates'] ?? [] as $rt): ?><option value="<?= h($rt) ?>" <?= Dec::cmp((string)fv('rate_choice', null, $app['rate'] ?? '-1'), $rt) === 0 ? 'selected' : '' ?>><?= h(Dec::fix($rt, 2)) ?> %</option><?php endforeach; ?></select></div>
            <?= select_field('amort_type', 'Amortization', Loans::AMORT_TYPES, fv('amort_type', $rec, 'equal_amortization'), ['class' => 'col-md-4', 'extra' => 'id="lAmort" data-no-combo']) ?>
            <?= select_field('purpose', 'Purpose', array_combine(Members::listNames('loan_purpose'), Members::listNames('loan_purpose')) ?: [], fv('purpose', $rec), ['class' => 'col-md-4', 'blank' => '', 'extra' => 'data-no-combo']) ?>
            <?= input_field('purpose_detail', 'Details of the purpose', fv('purpose_detail', $rec), ['class' => 'col-12']) ?>
            <?php if ($m && ($openLoans = DB::all("SELECT l.id, l.loan_no, l.principal_balance, p.name FROM loan_accounts l JOIN loan_products p ON p.id = l.product_id WHERE l.member_id = :m AND l.status IN ('current','past_due','restructured') ORDER BY l.release_date", ['m' => $m['id']]))): ?>
              <?= select_field('previous_loan_id', 'Renewal or restructuring of', array_column(array_map(fn($l) => ['id' => $l['id'], 't' => $l['loan_no'] . ' ' . $l['name'] . ' (balance ' . Dec::money($l['principal_balance']) . ')'], $openLoans), 't', 'id'), fv('previous_loan_id', $rec), ['class' => 'col-md-8', 'blank' => 'No, a new loan', 'extra' => 'data-no-combo']) ?>
              <?= check_field('is_restructure', 'Restructuring of a loan in arrears', fv('is_restructure', $rec, 0), 'col-md-4 pt-md-4') ?>
              <div class="col-12 small text-muted">The loan chosen is paid off from the proceeds on release and does not count against the product's limit of open loans.</div>
            <?php endif; ?>
          </div></div>
          <div class="card mb-3" id="lCapacity" data-api="<?= h(url('api.coop', ['a' => 'capacity'])) ?>"<?= $app ? ' data-keep' : '' ?>><div class="card-header">Capacity to pay</div><div class="card-body row g-2">
            <?= coop_money('monthly_income', 'Monthly income', fv('monthly_income', $rec, '0'), ['class' => 'col-6 col-md-3']) ?>
            <?= coop_money('spouse_income', 'Spouse income', fv('spouse_income', $rec, '0'), ['class' => 'col-6 col-md-3']) ?>
            <?= coop_money('ndi', 'Net disposable income', fv('ndi', $rec, '0'), ['class' => 'col-6 col-md-3']) ?>
            <?= input_field('dependents', 'Dependents', fv('dependents', $rec, '0'), ['class' => 'col-6 col-md-3', 'type' => 'number', 'step' => '1']) ?>
            <?= input_field('employer_name', 'Employer', fv('employer_name', $rec), ['class' => 'col-md-4']) ?>
            <?= input_field('business_name', 'Business', fv('business_name', $rec), ['class' => 'col-md-4']) ?>
            <?= input_field('spouse_name', 'Spouse', fv('spouse_name', $rec), ['class' => 'col-md-4']) ?>
            <div class="col-12 small text-muted"><span data-cap-note><?= h($capNote) ?></span> The eligibility check of the net disposable income uses the member's Financial Information tab.</div>
          </div></div>
          <div class="card mb-3"><div class="card-header">Co-makers</div><div class="card-body">
            <?= coop_grid('gCom', 'comakers', ['comaker_member_id' => ['Member', 'select', Coop::memberOptions()], 'name' => ['or name (non-member)', 'text'], 'address' => ['Address', 'text'], 'amount_guaranteed' => ['Guaranteed', 'money']], $com, 0, 'Add a co-maker') ?>
          </div></div>
          <div class="card mb-3"><div class="card-header">Collateral</div><div class="card-body">
            <?= coop_grid('gColl', 'collaterals', ['collateral_type' => ['Type', 'select', array_combine(Members::listNames('collateral_type'), Members::listNames('collateral_type')) ?: []], 'description' => ['Description', 'text'],
                'ref_no' => ['TCT / OR-CR / ref.', 'text'], 'fmv' => ['Fair market value', 'money'], 'loan_value' => ['Loan value', 'money'], 'expiry_date' => ['Document expiry', 'date'], 'insurance_expiry' => ['Insurance expiry', 'date']], $coll, 0, 'Add collateral') ?>
          </div></div>
          <div class="card"><div class="card-body row g-2">
            <?= select_field('officer_id', 'Loan officer', $users, fv('officer_id', $rec), ['class' => 'col-md-4']) ?>
            <?= input_field('remarks', 'Remarks', fv('remarks', $rec), ['class' => 'col-md-8']) ?>
          </div></div>
        </div>
        <div class="col-xl-5">
          <div class="card mb-3" style="position:sticky;top:70px"><div class="card-header">Amortization preview</div><div class="card-body"
            data-schedule data-api="<?= h(url('api.coop', ['a' => 'schedule'])) ?>" data-fields='<?= h(json_encode(['principal' => 'applied_amount', 'product_id' => 'product_id', 'term' => 'term', 'term_unit' => 'term_unit', 'mode' => 'mode_of_payment', 'rate' => 'rate_choice', 'amort' => 'amort_type', 'date' => 'application_date'])) ?>'>
            <div class="text-muted small">Choose the product, the amount and the term.</div></div></div>
        </div>
      </div>
      <div class="sticky-actions mt-3"><?= save_buttons($app ? 'Save application' : 'Save application', $app ? url($pageKey, ['id' => $app['id']]) : url($centerKey), false) ?></div>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      const rates = <?= json_encode($prodRates) ?>;
      const sel = document.getElementById('lProduct');
      const apply = (fresh) => {
        const p = rates[sel.value];
        const r = document.getElementById('lRate');
        const keep = r.value;
        r.innerHTML = '<option value="">By the product (term / amount)</option>' + (p ? p.rates.map((x) => '<option value="' + x + '"' + (x === keep ? ' selected' : '') + '>' + (+x).toFixed(2) + ' %</option>').join('') : '');
        if (!p) return;
        document.getElementById('lAmountHint').textContent = 'From ' + window.CoopMoney.money(window.CoopMoney.cents(p.amin)) + (+p.amax > 0 ? ' to ' + window.CoopMoney.money(window.CoopMoney.cents(p.amax)) : '') + ', ' + p.min + ' to ' + p.max + ' ' + p.unit;
        if (fresh) {
          document.getElementById('lMode').value = p.mode; document.getElementById('lUnit').value = p.unit; document.getElementById('lAmort').value = p.amort;
          ['lMode', 'lUnit', 'lAmort'].forEach((id) => document.getElementById(id).dispatchEvent(new Event('change', { bubbles: true })));
        }
      };
      sel.addEventListener('change', () => apply(true));
      apply(false);
      // a new application: the member chosen brings the Capacity to pay of the borrower's last loan
      const cap = document.getElementById('lCapacity');
      if (cap && !cap.hasAttribute('data-keep')) {
        cap.closest('form').addEventListener('member:picked', (e) => {
          if (!e.detail || e.detail.preset || !e.detail.id) return;
          fetch(cap.dataset.api + '&member=' + encodeURIComponent(e.detail.id), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then((d) => {
              if (!d || !d.data) return;
              Object.keys(d.data).forEach((k) => {
                const f = cap.querySelector('[name="' + k + '"]');
                if (!f) return;
                f.value = f.hasAttribute('data-money') ? window.CoopMoney.money(window.CoopMoney.cents(d.data[k] || '0')) : (d.data[k] ?? '');
              });
              const n = cap.querySelector('[data-cap-note]');
              if (n && d.note) n.textContent = d.note;
            }).catch(() => {});
        });
      }
    });
    </script>
    <?php
    layout_end();
    exit;
}

// --------------------------------------------------------------------------------------- the application
$checks = $check ?? (array)json_decode((string)$app['eligibility_json'], true);
$approvals = DB::all('SELECT x.*, u.full_name FROM loan_approvals x LEFT JOIN users u ON u.id = x.approver_id WHERE x.application_id = :a ORDER BY x.acted_at', ['a' => $app['id']]);
$docs = DB::all('SELECT * FROM loan_application_docs WHERE application_id = :a ORDER BY id', ['a' => $app['id']]);
$coll = DB::all('SELECT * FROM loan_collaterals WHERE application_id = :a', ['a' => $app['id']]);
$com = DB::all('SELECT c.*, m.member_no FROM loan_comakers c LEFT JOIN members m ON m.id = c.comaker_member_id WHERE c.application_id = :a', ['a' => $app['id']]);
$levels = Loans::levelsFor($app['applied_amount'], (int)$app['product_id']);
$st = $app['status'];
$steps = ['new' => 'Application', 'validated' => 'Validated', 'for_approval' => 'For approval', 'approved' => 'Approved', 'released' => 'Released'];
if ((int)$app['needs_ci'] === 1) {
    $steps = ['new' => 'Application', 'validated' => 'Validated', 'for_ci' => 'Credit investigation', 'for_approval' => 'For approval', 'approved' => 'Approved', 'released' => 'Released'];
}
$order = array_keys($steps);
$pos = array_search(in_array($st, ['returned'], true) ? 'new' : $st, $order, true);
$schedule = Loans::schedule($app['approved_amount'] ?: $app['applied_amount'], $app['rate'], $app['term'], $app['term_unit'], $app['mode_of_payment'], $app['interest_method'], $app['amort_type'] ?: $app['product_amort_type'],
    Coop::nthDue(today(), $app['mode_of_payment'], 1), (int)(Loans::product((int)$app['product_id'])['calendar_days'] ?? 365));
$actions = [];
if (in_array($st, ['new', 'validated', 'returned', 'for_ci'], true) && Access::can($pageKey, 'edit')) {
    $actions[] = btn_link(url($pageKey, ['id' => $app['id'], 'a' => 'edit']), 'Edit', 'btn-outline-primary', 'pencil');
}
if ((int)$app['needs_ci'] === 1 && Access::page('loan.ci')) {
    $actions[] = btn_link(url('loan.ci', ['app' => $app['id']]), 'Credit investigation', 'btn-outline-secondary', 'house-door');
}
if ($st === 'approved' && Access::can('loan.release', 'create')) {
    $actions[] = btn_link(url('loan.release', ['app' => $app['id']]), 'Release the loan', 'btn-primary', 'cash-coin');
}
if ($st === 'released' && $app['loan_id']) {
    $actions[] = btn_link(url('loan.accounts', ['id' => $app['loan_id']]), 'Loan account', 'btn-outline-primary', 'journal-text');
}
$actions[] = btn_link(url($centerKey), 'Applications', 'btn-outline-secondary', 'arrow-left');
coop_layout('Loan application ' . $app['application_no'], ['actions' => implode(' ', $actions)]);
?>
<div class="app-steps"><?php foreach ($steps as $k => $label): $i = array_search($k, $order, true); ?><span class="<?= $pos !== false && $i < $pos ? 'is-done' : ($i === $pos ? 'is-now' : '') ?>"><?= h($label) ?></span><?php endforeach; ?>
  <?php if (in_array($st, ['disapproved', 'cancelled', 'returned'], true)): ?><span class="is-stop"><?= h(Loans::APP_STATUSES[$st]) ?></span><?php endif; ?></div>
<div class="mb-3"><?= member_card(Coop::member((int)$app['member_id'])) ?></div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3"><div class="card-body"><?= coop_kv([
        'Product' => h($app['product_code'] . ' ' . $app['product_name']), 'Date' => h(fdate($app['application_date'])), 'Applied for' => '<strong>' . cm($app['applied_amount']) . '</strong>',
        'Approved' => $app['approved_amount'] ? '<strong>' . cm($app['approved_amount']) . '</strong>' : '', 'Term' => h(Dec::fix($app['term'], 0) . ' ' . $app['term_unit']) . ', ' . h(mb_strtolower(Coop::modeLabel($app['mode_of_payment']))),
        'Rate' => h(Dec::fix($app['rate'], 2)) . ' % a year, ' . h(mb_strtolower(Loans::INTEREST_METHODS[$app['interest_method']] ?? '')), 'Amortization' => cm($schedule['rows'][0]['total'] ?? '0') . ' x ' . (int)$schedule['installments'],
        'Total interest' => cm($schedule['total_interest']), 'Purpose' => h(trim((string)$app['purpose'] . ($app['purpose_detail'] ? ': ' . $app['purpose_detail'] : ''))),
        'Officer' => h((string)DB::value('SELECT full_name FROM users WHERE id = :u', ['u' => $app['officer_id']])), 'Status' => coop_status($st),
        ((int)$app['is_restructure'] ? 'Restructuring of' : 'Renewal of') => $app['previous_loan_id'] ? safe_link('loan.accounts', ['id' => $app['previous_loan_id']], (string)DB::value('SELECT loan_no FROM loan_accounts WHERE id = :l', ['l' => $app['previous_loan_id']])) . ' <span class="small text-muted">paid off from the proceeds</span>' : '', 'Remarks' => h((string)$app['remarks']), 'Cancelled' => h((string)$app['cancel_reason'])]) ?></div></div>
    <div class="card mb-3"><div class="card-header">Capacity to pay</div><div class="card-body"><?= coop_kv(['Monthly income' => cm($app['monthly_income']), 'Spouse income' => cm($app['spouse_income']),
        'Net disposable income' => cm($app['ndi']), 'Dependents' => (string)(int)$app['dependents'], 'Employer' => h((string)$app['employer_name']), 'Business' => h((string)$app['business_name']), 'Spouse' => h((string)$app['spouse_name'])]) ?>
      <div class="small text-muted mt-2">These entries are where the borrower's next loan application starts.</div></div></div>
    <div class="card mb-3"><div class="card-header">Eligibility</div><div class="card-body">
      <?php if ($checks): ?><ul class="check-list"><?php foreach ($checks as $c): ?><li><i class="bi <?= $c['ok'] ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i><div><?= h($c['check']) ?><small><?= h((string)$c['detail']) ?></small></div></li><?php endforeach; ?></ul>
      <?php else: ?><div class="text-muted small">Not checked yet.</div><?php endif; ?>
      <?php if (in_array($st, ['new', 'returned', 'validated', 'for_ci'], true) && Access::can($pageKey, 'edit')): ?>
        <form method="post" class="row g-2 align-items-end mt-2" data-once><?= csrf_field() ?><input type="hidden" name="action" value="validate">
          <?php if ($check !== null && Access::can($centerKey, 'approve')): ?><div class="col-md-8"><label class="form-label">Reason for overriding the failed checks</label><input class="form-control form-control-sm" name="override" required></div><?php endif; ?>
          <div class="col-md-4"><button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-list-check"></i> <?= $check !== null && Access::can($centerKey, 'approve') ? 'Override and validate' : 'Run the checks' ?></button></div></form>
      <?php endif; ?>
      <?php if (in_array($st, ['validated', 'for_ci'], true) && Access::can($pageKey, 'edit')): ?>
        <form method="post" class="mt-2" data-once><?= csrf_field() ?><input type="hidden" name="action" value="submit"><button class="btn btn-primary btn-sm"<?= (int)$app['needs_ci'] === 1 && !$app['ci_id'] ? ' disabled title="The credit investigation is needed first"' : '' ?>><i class="bi bi-send"></i> Submit for approval</button></form>
      <?php endif; ?>
    </div></div>
    <?php if ($st === 'for_approval' && Access::can($centerKey, 'approve')): ?>
    <form method="post" class="card mb-3" data-once><div class="card-header">Approval<?= $levels ? ': level ' . (count(array_filter($approvals, fn($a) => $a['action'] === 'approved')) + 1) . ' of ' . count($levels) : '' ?></div><div class="card-body row g-2">
      <?= csrf_field() ?><input type="hidden" name="action" value="act">
      <?= coop_money('approved_amount', 'Approved amount', $app['approved_amount'] ?: $app['applied_amount'], ['class' => 'col-md-4']) ?>
      <?= input_field('remarks', 'Remarks', '', ['class' => 'col-md-8']) ?>
      <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-success btn-sm" name="decision" value="approved"><i class="bi bi-check2-circle"></i> Approve</button>
        <button class="btn btn-outline-warning btn-sm" name="decision" value="returned">Return for correction</button>
        <button class="btn btn-outline-danger btn-sm" name="decision" value="disapproved" data-confirm="Disapprove the application?">Disapprove</button></div>
    </div></form>
    <?php endif; ?>
    <div class="card mb-3"><div class="card-header">Approvals</div><table class="table table-sm"><tbody>
      <?php foreach ($levels as $lv => $rule): ?><tr class="small text-muted"><td colspan="4">Level <?= (int)$lv ?> needed: <?= h($rule['position'] ?: 'approver') ?><?= $rule['role_id'] ? ' (' . h((string)DB::value('SELECT name FROM roles WHERE id = :r', ['r' => $rule['role_id']])) . ')' : '' ?></td></tr><?php endforeach; ?>
      <?php foreach ($approvals as $a): ?><tr><td><?= h(fdatetime($a['acted_at'])) ?></td><td>Level <?= (int)$a['level_no'] ?>: <?= h($a['full_name']) ?><div class="small text-muted"><?= h((string)$a['position']) ?></div></td>
        <td><?= coop_status($a['action']) ?><?= $a['approved_amount'] ? ' ' . cm($a['approved_amount']) : '' ?></td><td class="small"><?= h((string)$a['remarks']) ?></td></tr><?php endforeach; ?>
      <?php if (!$approvals): ?><tr><td class="text-muted">No decision yet.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="card mb-3"><div class="card-header">Co-makers and collateral</div><table class="table table-sm"><tbody>
      <?php foreach ($com as $c): ?><tr><td>Co-maker</td><td><?= h($c['name']) ?><?= $c['member_no'] ? ' <span class="small text-muted">' . h($c['member_no']) . '</span>' : ' <span class="small text-muted">non-member</span>' ?></td><td class="num"><?= cm($c['amount_guaranteed']) ?></td></tr><?php endforeach; ?>
      <?php foreach ($coll as $c): ?><tr><td>Collateral</td><td><?= h($c['collateral_type'] . ': ' . $c['description']) ?><div class="small text-muted"><?= h((string)$c['ref_no']) ?><?= $c['expiry_date'] ? ', expires ' . h(fdate($c['expiry_date'])) : '' ?></div></td><td class="num"><?= cm($c['loan_value']) ?></td></tr><?php endforeach; ?>
      <?php if (!$com && !$coll): ?><tr><td class="text-muted">None.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <div class="col-xl-5">
    <div class="card mb-3"><div class="card-header">Documents to complete before release</div><div class="card-body">
      <?php foreach ($docs as $d): ?>
        <form method="post" enctype="multipart/form-data" class="row g-1 align-items-center mb-2 pb-2 border-bottom"><?= csrf_field() ?><input type="hidden" name="action" value="doc"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
          <div class="col-12 d-flex justify-content-between"><strong class="small"><?= h($d['requirement']) ?></strong><?= (int)$d['is_complete'] ? '<span class="small text-success">complete</span>' : '<span class="small text-warning">to complete</span>' ?></div>
          <?php if ($d['file_name']): ?><div class="col-12 small"><a href="<?= h(url('file.member', ['id' => $app['member_id'], 'f' => 'loandoc', 'doc' => $d['id']])) ?>" target="_blank" rel="noopener"><?= h((string)$d['original_name']) ?></a></div><?php endif; ?>
          <?php if (Access::can($pageKey, 'edit') && $st !== 'released'): ?>
          <div class="col-4"><select class="form-select form-select-sm" name="is_complete" data-no-combo><option value="0">Not yet</option><option value="1" <?= (int)$d['is_complete'] ? 'selected' : '' ?>>Complete</option></select></div>
          <div class="col-5"><input class="form-control form-control-sm" type="file" name="file" accept="image/*,application/pdf" aria-label="File"></div>
          <div class="col-3"><button class="btn btn-outline-primary btn-sm w-100">Save</button></div>
          <?php endif; ?>
        </form>
      <?php endforeach; ?>
      <?php if (Access::can($pageKey, 'edit') && $st !== 'released'): ?><form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="add_doc"><input class="form-control form-control-sm" name="requirement" placeholder="Another requirement"><button class="btn btn-outline-secondary btn-sm">Add</button></form><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header">Amortization (if released today)</div><div class="sched-wrap" style="max-height:300px"><table class="table table-sm sched-table"><thead><tr><th>#</th><th>Due</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Total</th></tr></thead><tbody>
      <?php foreach ($schedule['rows'] as $r): ?><tr><td><?= (int)$r['inst_no'] ?></td><td><?= h(fdate($r['due_date'])) ?></td><td class="num"><?= cm($r['principal']) ?></td><td class="num"><?= cm($r['interest']) ?></td><td class="num"><?= cm($r['total']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <?php $canCancel = !in_array($st, ['released', 'cancelled'], true) && Access::can($pageKey, 'edit');
    $canDelete = Access::can($pageKey, 'delete') && Loans::deleteBlocker($app) === null; ?>
    <?php if ($canCancel || $canDelete): ?>
      <div class="app-end-actions">
        <?php if ($canCancel): ?><button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#cancelApp"><i class="bi bi-x-circle"></i> Cancel the application</button><?php endif; ?>
        <?php if ($canDelete): ?><button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteApp"><i class="bi bi-trash3"></i> Delete the application</button><?php endif; ?>
      </div>
      <?php if ($canCancel): ?><?= str_replace('</form>', '<input type="hidden" name="action" value="cancel"></form>', reason_modal('cancelApp', 'Cancel the application', url($pageKey, ['id' => $app['id']]))) ?><?php endif; ?>
      <?php if ($canDelete): ?><?= coop_app_delete_modal('deleteApp', $app, url($pageKey, ['id' => $app['id']])) ?><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
