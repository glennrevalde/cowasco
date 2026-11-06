<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.profile';

/** key => [label, kind, help, choices] ; kind: text | number | money | pct | yesno | select | textarea */
$groups = [
    'Registration' => [
        'registered_name' => ['Registered name (as in the CDA certificate)', 'text', 'Printed on every cooperative document. Blank uses the company name.'],
        'cda_reg_no' => ['CDA registration number', 'text', ''],
        'type' => ['Type of cooperative', 'select', '', ['Credit', 'Multi-Purpose', 'Consumers', 'Producers', 'Marketing', 'Service', 'Agrarian Reform', 'Cooperative Bank', 'Electric', 'Transport', 'Workers', 'Housing', 'Other']],
        'category' => ['Category', 'select', 'As registered (primary, secondary or tertiary).', ['Primary', 'Secondary', 'Tertiary']],
        'fiscal_year_end' => ['Fiscal year ends (MM-DD)', 'text', 'Usually 12-31.'],
        'division_name' => ['Division name printed under the cooperative name on documents', 'text', 'For example the savings and credit division. Blank prints nothing.'],
    ],
    'Signatories' => [
        'signatory_gm' => ['General Manager', 'text', ''],
        'signatory_chair' => ['Chairperson of the Board', 'text', ''],
        'signatory_treasurer' => ['Treasurer', 'text', ''],
        'signatory_loans' => ['Credit Committee chair / Loans officer', 'text', ''],
        'signatory_bookkeeper' => ['Bookkeeper / Accountant', 'text', ''],
    ],
    'Membership and share capital' => [
        'min_share_capital' => ['Minimum paid-up share capital to activate a member', 'money', 'Also checked per member type. Zero = no minimum.'],
        'default_par_value' => ['Par value per share', 'money', 'Used for new members (subscribed shares x par value).'],
        'membership_fee' => ['Membership fee', 'money', 'Suggested on the first official receipt of an applicant.'],
    ],
    'Teller and transactions' => [
        'or_require_shift' => ['Cash transactions need an open teller shift', 'yesno', 'Recommended: every peso received or paid belongs to a drawer that is counted at the end of the day.'],
        'backdate_days' => ['Days a transaction may be back-dated without the special permission', 'number', 'Zero: only users with "can record back-dated member transactions" may back-date.'],
        'large_txn_amount' => ['A receipt above this needs supervisor approval', 'money', 'Zero = no limit.'],
        'withdrawal_approval_above' => ['A withdrawal above this needs supervisor approval', 'money', 'On top of each role\'s own limit. Zero = only the role limits apply.'],
        'over_short_tolerance' => ['Cash over/short a teller may post without approval', 'money', 'Zero: every difference needs a supervisor.'],
        'sav_account_format' => ['Savings account number format', 'text', '{BRANCH} 2-digit branch, {MEMBER} member no., {PRODUCT} 2-digit product, {SEQ} running number. Reference layout: {BRANCH}{MEMBER}{PRODUCT}1'],
        'loan_account_format' => ['Loan account number format', 'text', '{BRANCH}, {PRODUCT} code, {SEQ}, {YEAR}. Reference layout: {BRANCH}-{PRODUCT}-{SEQ}'],
    ],
    'Loans' => [
        'allocation_order' => ['Payment allocation order', 'select', 'How a payment is split when no one splits it by hand: what is due is paid in this order; what is left, or the whole payment when no penalty and no interest is due, goes to the principal.', ['penalty,interest,principal' => 'Penalty, interest, then principal', 'interest,penalty,principal' => 'Interest, penalty, then principal', 'interest,principal,penalty' => 'Interest, principal, then penalty', 'principal,interest,penalty' => 'Principal, interest, then penalty']],
        'penalty_on_collection' => ['Compute penalties when a payment is received', 'yesno', 'No: only the penalties posted by the batch run (Post Loan Penalties) are collected.'],
        'loan_due_alert_days' => ['Remind of amortizations due within (days)', 'number', ''],
    ],
    'Alerts' => [
        'td_maturity_alert_days' => ['Time deposits maturing within (days)', 'number', ''],
        'dormancy_alert_days' => ['Accounts turning dormant within (days)', 'number', ''],
        'doc_expiry_alert_days' => ['Documents, collateral and insurance expiring within (days)', 'number', ''],
    ],
    'Year-end distribution (defaults; confirm against your bylaws and the General Assembly resolution)' => [
        'reserve_pct' => ['Reserve fund (% of net surplus)', 'pct', 'RA 9520 Art. 86: at least 10 %.'],
        'educ_pct' => ['Education and training fund (%)', 'pct', 'Not more than 10 %.'],
        'educ_apex_pct' => ['Part of the education fund due to the union / federation (%)', 'pct', 'Half, under the law as commonly applied; follow your bylaws.'],
        'cdf_pct' => ['Community development fund (%)', 'pct', 'At least 3 %.'],
        'optional_pct' => ['Optional fund (%)', 'pct', 'Not more than 7 %.'],
        'isc_basis' => ['Interest on share capital basis', 'select', '', ['adb' => 'Average daily balance', 'ending' => 'Ending balance']],
    ],
];

handle_post(function () use ($pageKey, $groups) {
    Access::require($pageKey, 'edit');
    $changes = [];
    foreach ($groups as $fields) {
        foreach ($fields as $key => $def) {
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $v = trim((string)$_POST[$key]);
            switch ($def[1]) {
                case 'money':
                    $v = Dec::fix(Dec::of($v));
                    break;
                case 'pct':
                    $v = Dec::fix(Dec::of($v), 2);
                    if (Dec::isNeg($v) || Dec::cmp($v, '100') > 0) {
                        fail($def[0] . ': enter a percentage from 0 to 100.');
                    }
                    break;
                case 'number':
                    $v = (string)max(0, (int)$v);
                    break;
                case 'yesno':
                    $v = $v === '1' ? '1' : '0';
                    break;
                case 'select':
                    $choices = $def[3];
                    $keys = array_is_list($choices) ? $choices : array_keys($choices);
                    if ($v !== '' && !in_array($v, $keys, true)) {
                        fail($def[0] . ': choose from the list.');
                    }
                    break;
                default:
                    $v = mb_substr($v, 0, $def[1] === 'textarea' ? 4000 : 200);
            }
            if ($key === 'fiscal_year_end' && !preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $v)) {
                fail('Enter the fiscal year end as MM-DD, for example 12-31.');
            }
            $old = Settings::get('coop_' . $key, '');
            if ($old !== $v) {
                $changes[] = ['field' => $key, 'old' => $old, 'new' => $v];
                Settings::set('coop_' . $key, $v);
            }
        }
    }
    $pct = Dec::sum([Coop::setting('reserve_pct', '0'), Coop::setting('educ_pct', '0'), Coop::setting('cdf_pct', '0'), Coop::setting('optional_pct', '0')]);
    if (Dec::cmp($pct, '100') > 0) {
        fail('The statutory percentages add up to more than 100 %.');
    }
    if ($changes) {
        Audit::log('settings', 'coop', 'settings', null, 'Cooperative Profile & Policies', ['page_key' => $pageKey, 'changes' => $changes]);
    }
    flash('success', $changes ? count($changes) . ' setting(s) saved.' : 'Nothing was changed.');
    redirect(url($pageKey));
});

coop_layout('Cooperative Profile & Policies');
$can = Access::can($pageKey, 'edit');
?>
<form method="post" data-once>
  <?= csrf_field() ?>
  <fieldset <?= $can ? '' : 'disabled' ?>>
  <div class="row g-3">
  <?php foreach ($groups as $title => $fields): ?>
    <div class="col-xl-6"><div class="card h-100"><div class="card-header"><?= h($title) ?></div><div class="card-body row g-2">
    <?php foreach ($fields as $key => $def):
        $val = is_post() ? (string)($_POST[$key] ?? '') : Coop::setting($key, '');
        if ($def[1] === 'money' && !is_post() && $val !== '') {
            $val = Dec::money($val);
        }
        $cls = $def[1] === 'textarea' ? 'col-12' : 'col-md-6';
        $help = $def[2] !== '' ? '<div class="form-text">' . h($def[2]) . '</div>' : ''; ?>
      <?php if ($def[1] === 'yesno'): ?>
        <div class="<?= $cls ?>"><label class="form-label"><?= h($def[0]) ?></label>
          <select class="form-select form-select-sm" name="<?= h($key) ?>"><?= options(['1' => 'Yes', '0' => 'No'], $val === '' ? '0' : $val) ?></select><?= $help ?></div>
      <?php elseif ($def[1] === 'select'): $choices = array_is_list($def[3]) ? array_combine($def[3], $def[3]) : $def[3]; ?>
        <div class="<?= $cls ?>"><label class="form-label"><?= h($def[0]) ?></label>
          <select class="form-select form-select-sm" name="<?= h($key) ?>"><?= options($choices, $val, '') ?></select><?= $help ?></div>
      <?php elseif ($def[1] === 'textarea'): ?>
        <div class="<?= $cls ?>"><label class="form-label"><?= h($def[0]) ?></label>
          <textarea class="form-control form-control-sm" name="<?= h($key) ?>" rows="4"><?= h($val) ?></textarea><?= $help ?></div>
      <?php else: ?>
        <div class="<?= $cls ?>"><label class="form-label"><?= h($def[0]) ?></label>
          <input class="form-control form-control-sm<?= in_array($def[1], ['money', 'pct', 'number'], true) ? ' text-end' : '' ?>" name="<?= h($key) ?>" value="<?= h($val) ?>"
            <?= $def[1] === 'money' ? 'inputmode="decimal" data-money' : ($def[1] === 'number' || $def[1] === 'pct' ? 'inputmode="decimal"' : '') ?>><?= $help ?></div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div></div></div>
  <?php endforeach; ?>
  </div>
  </fieldset>
  <?php if ($can): ?><div class="sticky-actions mt-3"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save settings</button>
    <span class="small text-muted ms-2">The company address, TIN and logo are on System Administration &gt; Company Settings; the wording of promissory notes, certificates and letters on Members &amp; Others &gt; Document Templates.</span></div><?php endif; ?>
</form>
<?php
layout_end();
