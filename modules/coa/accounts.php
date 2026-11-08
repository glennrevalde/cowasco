<?php
declare(strict_types=1);

$pageKey = 'coa.accounts';
$a = g('a');
// the account types: the built-in ones (account_type) and the user's own (Chart of Accounts > Account Types), each
// behaving as one built-in type
$catOf = array_map(fn($b) => $b[0], AccountTypes::BASE);
$canChange = (bool)Access::special('coa_changes');

if ($a === 'delete' && is_post()) {
    Access::require($pageKey, 'delete');
    $acc = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => (int)g('id')]);
    try {
        if (!$acc || !$canChange) {
            fail('You are not allowed to change the Chart of Accounts.');
        }
        if ((int)$acc['is_system'] === 1) {
            fail('System accounts cannot be deleted. Make them inactive instead.');
        }
        $used = (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM transaction_lines WHERE account_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM default_accounts WHERE account_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM accounts WHERE parent_id = :a', ['a' => $acc['id']])
            + (int)DB::value('SELECT COUNT(*) FROM items WHERE :a IN (income_account_id, cogs_account_id, asset_account_id, expense_account_id)', ['a' => $acc['id']]);
        if ($used) {
            fail('This account is in use. Make it inactive instead of deleting it.');
        }
        DB::run('DELETE FROM accounts WHERE id = :id', ['id' => $acc['id']]);
        Audit::log('delete', 'coa', 'account', (int)$acc['id'], $acc['code'] . ' ' . $acc['name'], ['snapshot' => $acc, 'reason' => p('reason') ?: null, 'page_key' => $pageKey]);
        flash('success', 'Account deleted.');
    } catch (UserError $e) {
        flash('danger', $e->getMessage());
    }
    redirect(url($pageKey));
}

// ------------------------------------------------------------------ Import: the accounts of a chart of accounts from a CSV file
// or an Excel workbook, shown for review first (a line with a problem in red, its cells correctable, any line
// removable), saved when no line is in red (version 57; app/CoaImport.php)
if ($a === 'import') {
    Access::require($pageKey, 'create');
    if (!$canChange) {
        Access::denyPage($pageKey, 'change the Chart of Accounts');
    }
    $opt = fn() => ['existing' => isset(CoaImport::EXISTING[(string)p('existing')]) ? (string)p('existing') : 'update'];
    $o = is_post() ? $opt() : ['existing' => 'update'];
    $cfg = [
        'cols' => CoaImport::cols(), 'aliases' => CoaImport::aliases(), 'noun' => ['account', 'accounts'], 'excel' => true,
        'template_name' => 'chart_of_accounts_template.csv', 'samples' => CoaImport::samples(), 'opt' => $opt,
        'check' => fn(array $rows, array $o) => CoaImport::check($rows, $o),
        'post' => function (array $rows, array $o, string $file) use ($pageKey) {
            $r = CoaImport::post($rows, $o, $file, $pageKey);
            $parts = [];
            foreach (['added' => 'added', 'updated' => 'updated', 'same' => 'already the same', 'skipped' => 'left as they were'] as $k => $w) {
                if ($r[$k]) {
                    $parts[] = $r[$k] . ' ' . $w;
                }
            }
            return 'Chart of accounts imported: ' . (implode(', ', $parts) ?: 'nothing to change') . ($r['codes'] ? ' (' . implode(', ', array_slice($r['codes'], 0, 10)) . (count($r['codes']) > 10 ? ' ...' : '') . ')' : '') . '.';
        },
        'can_post' => true, 'post_label' => 'Save', 'post_icon' => 'check2-circle', 'done' => url($pageKey),
        'confirm' => 'Save the accounts to the chart of accounts? The new accounts are added and the accounts already on the chart are updated or left as they are, as chosen above.',
        'fields' => select_field('existing', 'An account whose code is already on the chart', CoaImport::EXISTING, $o['existing'], ['class' => 'col-12 col-lg-10', 'extra' => 'data-no-combo']),
        'intro' => 'The accounts of a chart of accounts, one line per account, from a CSV file or an Excel workbook: the code, the name and the type, and if you have them the main account it is under, '
            . 'the normal balance, With SL, Active, the description and the bank account no. The file of <strong>Export</strong> on the Chart of Accounts is read as it is. '
            . 'Every line is shown for review before anything is saved: a line with a problem is in red with its reason, any cell can be corrected and any line removed.',
        'help' => 'A sub-account names the code of its main account (on the chart or in the file, of the same category; one level of sub-accounts). The type is one of Financial &gt; Account Types (Bank, Accounts Receivable, Expense ... or a type of your own).',
    ];
    $state = CsvReview::handle($cfg);
    if ($state['rows'] && $state['summary']) {
        $sm = $state['summary'];
        $bits = [];
        foreach (['new' => 'new account', 'update' => 'to update', 'same' => 'already the same', 'skip' => 'left as on the chart'] as $k => $w) {
            if (!empty($sm[$k])) {
                $bits[] = '<strong>' . (int)$sm[$k] . '</strong> ' . ($k === 'new' && (int)$sm[$k] !== 1 ? 'new accounts' : $w);
            }
        }
        $cfg['extra'] = $bits ? '<div class="coa-imp-sum small text-muted">Of the lines without a problem: ' . implode(', ', $bits) . '.</div>' : '';
    }
    layout_start('Import the chart of accounts', ['actions' => btn_link(url($pageKey), 'Chart of Accounts', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/csv_review.js']]);
    echo CsvReview::render($cfg, $state);
    layout_end();
    return;
}

if ($a === 'new' || $a === 'edit') {
    $acc = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $acc = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => (int)g('id')]);
        if (!$acc) {
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    if (!$canChange) {
        Access::denyPage($pageKey, 'change the Chart of Accounts');
    }
    handle_post(function () use ($acc, $catOf, $pageKey) {
        // the type chosen (an id of Account Types); a form of before posts the built-in type's name
        $t = pint('type_id') ? AccountTypes::get((int)pint('type_id')) : (isset($catOf[(string)p('account_type')]) ? AccountTypes::system((string)p('account_type')) : null);
        if (!$t || (!(int)$t['is_active'] && (!$acc || (int)AccountTypes::forAccount($acc)['id'] !== (int)$t['id']))) {
            fail('Choose the account type.');
        }
        $type = (string)$t['base_type'];
        $d = [
            'code' => mb_substr(trim((string)p('code')), 0, 20),
            'name' => mb_substr(trim((string)p('name')), 0, 120),
            'account_type' => $type,
            'category' => $catOf[$type],
            'normal_balance' => in_array(p('normal_balance'), ['Debit', 'Credit'], true) ? p('normal_balance') : (in_array($catOf[$type], ['Asset', 'Expense'], true) ? 'Debit' : 'Credit'),
            'parent_id' => pint('parent_id'),
            'description' => p('description') ?: null,
            'bank_account_no' => p('bank_account_no') ?: null,
            'with_sl' => p('with_sl', '0') === '1' ? 1 : 0,
            'is_active' => p('is_active', '1') === '1' ? 1 : 0,
        ];
        if ($d['code'] === '' || $d['name'] === '') {
            fail('Enter the account code and name.');
        }
        if (DB::value('SELECT id FROM accounts WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $acc['id'] ?? 0])) {
            fail('Another account uses this code.');
        }
        if ($d['parent_id']) {
            $parent = DB::row('SELECT * FROM accounts WHERE id = :id', ['id' => $d['parent_id']]);
            if (!$parent || $parent['category'] !== $d['category'] || ($acc && (int)$parent['id'] === (int)$acc['id'])) {
                fail('A sub-account must be under a parent account of the same category.');
            }
        }
        if ($acc && (int)$acc['is_system'] === 1 && !$d['is_active']) {
            fail('System accounts used by the modules cannot be made inactive.');
        }
        if ($acc && $acc['account_type'] !== $type && (int)DB::value('SELECT COUNT(*) FROM journal_lines WHERE account_id = :a', ['a' => $acc['id']]) > 0
            && $acc['category'] !== $d['category']) {
            fail('This account has transactions; it cannot be moved to a different category.');
        }
        if ($acc && (int)$acc['is_system'] === 1 && $acc['account_type'] !== $type) {
            fail('This account is one the modules post to: it can only take a type that behaves as ' . $acc['account_type'] . '.');
        }
        if ($acc && $acc['category'] !== $d['category'] && (int)DB::value('SELECT COUNT(*) FROM accounts WHERE parent_id = :a', ['a' => $acc['id']]) > 0) {
            fail('This account has sub-accounts of its category; it cannot be moved to another category.');
        }
        if (AccountTypes::ready()) {
            $d['type_id'] = (int)$t['id'] > 0 ? (int)$t['id'] : null;
        }
        if ($acc) {
            DB::update('accounts', $d, 'id = :id', ['id' => $acc['id']]);
            $id = (int)$acc['id'];
            Audit::log('edit', 'coa', 'account', $id, $d['code'] . ' ' . $d['name'], ['before' => $acc, 'after' => $d, 'page_key' => $pageKey]);
        } else {
            $id = DB::insert('accounts', $d);
            Audit::log('create', 'coa', 'account', $id, $d['code'] . ' ' . $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
        }
        Accts::reset();
        flash('success', 'Account saved.');
        redirect_after_save(url($pageKey, ['a' => 'new']), url($pageKey));
    });
    $curType = is_post() && pint('type_id') ? (int)pint('type_id') : ($acc ? (int)AccountTypes::forAccount($acc)['id'] : (int)AccountTypes::system('Expense')['id']);
    $parents = DB::all('SELECT * FROM accounts WHERE parent_id IS NULL AND id <> :id ORDER BY code', ['id' => $acc['id'] ?? 0]);
    $parentSel = (string)fv('parent_id', $acc);
    layout_start($acc ? 'Edit account ' . $acc['code'] : 'New account', ['actions' => page_link('coa.account_types', [], 'Account types', 'btn-outline-secondary', 'tags') . ' ' . btn_link(url($pageKey), 'Cancel')]);
    ?>
    <form method="post" class="card acct-form" style="max-width:860px"><div class="card-body row g-2">
      <?= csrf_field() ?>
      <div class="col-md-4"><label class="form-label" for="acType">Account type</label>
        <select class="form-select form-select-sm" name="type_id" id="acType" required data-no-combo><?= AccountTypes::optionsHtml($curType) ?></select></div>
      <?= input_field('code', 'Code', fv('code', $acc), ['class' => 'col-md-2', 'required' => true]) ?>
      <?= input_field('name', 'Name', fv('name', $acc), ['class' => 'col-md-6', 'required' => true]) ?>
      <div class="col-12"><div class="acct-type-note" id="acTypeNote" aria-live="polite"></div></div>
      <div class="col-md-6"><label class="form-label" for="acParent">Sub-account of</label>
        <select class="form-select form-select-sm" name="parent_id" id="acParent" data-no-combo><option value=""></option>
          <?php foreach ($parents as $pa): ?><option value="<?= (int)$pa['id'] ?>" data-type="<?= (int)AccountTypes::forAccount($pa)['id'] ?>" data-category="<?= h($pa['category']) ?>"<?= (string)$pa['id'] === $parentSel ? ' selected' : '' ?>><?= h($pa['code'] . ' - ' . $pa['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?= select_field('normal_balance', 'Normal balance', ['Debit' => 'Debit', 'Credit' => 'Credit'], fv('normal_balance', $acc, ''), ['blank' => 'From type', 'class' => 'col-md-3']) ?>
      <?= check_field('is_active', 'Active', fv('is_active', $acc, 1), 'col-md-3') ?>
      <?= check_field('with_sl', 'With SL (keeps a subsidiary ledger)', fv('with_sl', $acc, 0), 'col-md-6') ?>
      <?= input_field('bank_account_no', 'Bank account no. (bank accounts)', fv('bank_account_no', $acc), ['class' => 'col-md-4']) ?>
      <?= input_field('description', 'Description', fv('description', $acc), ['class' => 'col-md-8']) ?>
      <div class="col-12 small text-muted">Contra accounts (accumulated depreciation, sales returns, drawings) use the opposite normal balance of their type.
        A sub-account is shown under its main account on the financial statements, and the main account's type decides where; the main account shows the total of its sub-accounts.</div>
      <div class="col-12 small text-muted"><strong>With SL</strong> keeps a listing of the names that transacted this account. While it is ticked, a journal entry,
        cash voucher or check that posts to this account must name a customer, supplier, employee or other name, and the
        <?= page_link('rpt.subsidiary_ledger', [], 'Subsidiary Ledger', 'btn-link p-0 align-baseline') ?> and
        <?= page_link('rpt.schedule_of_accounts', [], 'Schedule of Accounts', 'btn-link p-0 align-baseline') ?> reports show those names.</div>
      <div class="col-12"><?= save_buttons('Save account', url($pageKey), Access::can($pageKey, 'create')) ?></div>
    </div></form>
    <script>
    (function () {
      // what the type chosen means, and a new sub-account takes the type of its main account
      const types = <?= json_encode(array_map(fn($t) => ['name' => $t['name'], 'base' => $t['base_type'], 'system' => (int)$t['is_system'], 'where' => AccountTypes::placement($t),
          'note' => AccountTypes::BASE_NOTE[$t['base_type']] ?? ''], AccountTypes::all()), JSON_UNESCAPED_UNICODE) ?>;
      const sel = document.getElementById('acType'), note = document.getElementById('acTypeNote'), par = document.getElementById('acParent');
      if (!sel || !note) return;
      let touched = <?= $acc ? 'true' : 'false' ?>;
      const show = () => {
        const t = types[sel.value];
        if (!t) { note.textContent = ''; return; }
        note.innerHTML = '';
        const b = document.createElement('div');
        b.innerHTML = '<i class="bi bi-info-circle"></i>';
        const txt = document.createElement('div');
        const l1 = document.createElement('div');
        l1.textContent = t.system ? ('Built-in type: ' + t.note + '.') : ('Behaves as ' + t.base + ': ' + t.note + '.');
        const l2 = document.createElement('div');
        l2.className = 'text-muted';
        l2.textContent = t.where + '.';
        txt.append(l1, l2);
        note.append(b.firstChild, txt);
      };
      sel.addEventListener('change', () => { touched = true; show(); });
      if (par) par.addEventListener('change', () => {
        const o = par.options[par.selectedIndex];
        if (!touched && o && o.dataset.type && sel.querySelector('option[value="' + o.dataset.type + '"]')) { sel.value = o.dataset.type; show(); }
      });
      show();
    })();
    </script>
    <?php
    layout_end();
    return;
}

$showInactive = g('inactive') === '1';
$accounts = DB::all(
    'SELECT a.*, p.code AS parent_code, COALESCE(SUM(j.debit - j.credit),0) AS net
       FROM accounts a LEFT JOIN accounts p ON p.id = a.parent_id LEFT JOIN journal_lines j ON j.account_id = a.id'
    . ($showInactive ? '' : ' WHERE a.is_active = 1') . '
      GROUP BY a.id, p.code ORDER BY a.code'
);
// the main accounts in code order, each followed by its sub-accounts; a main account's total is its own balance and
// its sub-accounts' (inactive sub-accounts included in the total)
$all = array_column(DB::all('SELECT a.id, a.parent_id, a.category, a.account_type, COALESCE(SUM(j.debit - j.credit),0) AS net FROM accounts a LEFT JOIN journal_lines j ON j.account_id = a.id GROUP BY a.id, a.parent_id, a.category, a.account_type'), null, 'id');
$byId = array_column($accounts, null, 'id');
$mainOf = fn(array $r) => !empty($r['parent_id']) && isset($all[(int)$r['parent_id']]) && $all[(int)$r['parent_id']]['category'] === $r['category'] ? (int)$r['parent_id'] : (int)$r['id'];
$subTotal = [];
$subBank = [];
$subCount = [];
foreach ($all as $r) {
    $m = $mainOf($r);
    $subTotal[$m] = ($subTotal[$m] ?? 0.0) + (float)$r['net'];
    if ((int)$r['id'] !== $m) {
        $subCount[$m] = ($subCount[$m] ?? 0) + 1;
        $subBank[$m] = ($subBank[$m] ?? false) || $r['account_type'] === 'Bank';
    }
}
$ordered = [];
foreach ($accounts as $r) {
    if ($mainOf($r) === (int)$r['id'] || !isset($byId[$mainOf($r)])) {
        $ordered[] = $r;
        foreach ($accounts as $c) {
            if ((int)$c['id'] !== (int)$r['id'] && $mainOf($c) === (int)$r['id']) {
                $ordered[] = $c;
            }
        }
    }
}
if (g('export') === 'csv') {
    Access::require($pageKey, 'print');
    Audit::log('export', 'coa', 'list', null, 'Chart of Accounts', ['page_key' => $pageKey]);
    csv_download('chart_of_accounts.csv', ['Code', 'Name', 'Type', 'Behaves as', 'Category', 'Normal balance', 'Parent', 'With SL', 'Balance', 'Total with sub-accounts', 'Active'],
        array_map(function ($r) use ($subTotal, $subCount) {
            $sign = $r['normal_balance'] === 'Credit' ? -1 : 1;
            return [$r['code'], $r['name'], AccountTypes::nameOf($r), $r['account_type'], $r['category'], $r['normal_balance'], $r['parent_code'],
                (int)$r['with_sl'] ? 'Yes' : '', $sign * $r['net'], empty($subCount[(int)$r['id']]) ? '' : $sign * ($subTotal[(int)$r['id']] ?? 0), $r['is_active']];
        }, $ordered));
}
$byCat = [];
foreach ($ordered as $r) {
    $byCat[$r['category']][] = $r;
}
$showBank = Access::special('view_bank_balances') || Access::page('bank.register');
layout_start('Chart of Accounts', ['actions' => ($canChange ? page_link($pageKey, ['a' => 'new'], 'New account', 'btn-primary', 'plus-lg', 'create')
        . ' ' . page_link($pageKey, ['a' => 'import'], 'Import', 'btn-outline-primary', 'upload', 'create') : '')
    . ' ' . page_link('coa.account_types', [], 'Account types', 'btn-outline-secondary', 'tags')
    . ' ' . (Access::can($pageKey, 'print') ? btn_link(current_url(['export' => 'csv']), 'Export', 'btn-outline-secondary', 'download') : '')
    . ' ' . page_link('coa.default_accounts', [], 'Module default accounts', 'btn-outline-secondary', 'diagram-3')]);
?>
<form method="get" class="mb-2 filters"><input type="hidden" name="p" value="<?= h($pageKey) ?>"><label class="small"><input type="checkbox" name="inactive" value="1" <?= checked($showInactive) ?> data-autosubmit> Include inactive accounts</label></form>
<?php foreach (['Asset', 'Liability', 'Equity', 'Income', 'Expense'] as $cat): if (empty($byCat[$cat])) { continue; } ?>
<div class="card mb-3 coa-card"><div class="card-header fw-semibold"><?= h($cat === 'Liability' ? 'Liabilities' : $cat . ($cat === 'Equity' ? '' : 's')) ?></div>
  <table class="table table-sm table-hover mb-0 stack-sm coa-list">
    <thead><tr><th style="width:90px">Code</th><th>Name</th><th>Type</th><th>Normal</th><th style="width:70px">With SL</th><th class="num">Balance</th><th></th></tr></thead>
    <?php foreach ($byCat[$cat] as $r):
        $id = (int)$r['id'];
        $isSub = $mainOf($r) !== $id && isset($byId[$mainOf($r)]);
        $sign = $r['normal_balance'] === 'Credit' ? -1 : 1;
        $bal = $sign * (float)$r['net'];
        $hasSubs = !empty($subCount[$id]);
        $total = $sign * (float)($subTotal[$id] ?? $r['net']);
        $t = AccountTypes::forAccount($r);
        $masked = !$showBank && ($r['account_type'] === 'Bank' || ($hasSubs && !empty($subBank[$id]))); ?>
      <tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?><?= $isSub ? ' coa-sub' : '' ?><?= $hasSubs ? ' coa-main' : '' ?>">
        <td data-label="Code"><?= h($r['code']) ?></td>
        <td data-label="Name" class="<?= $isSub ? 'ps-4' : '' ?>"><?= $isSub ? '<i class="bi bi-arrow-return-right" aria-hidden="true"></i> ' : '' ?><?= safe_link('rpt.general_ledger', ['account_id' => $r['id']], $r['name']) ?>
          <?= (int)$r['is_system'] ? '<span class="coa-sys">system</span>' : '' ?><?= $hasSubs ? '<div class="small text-muted">' . (int)$subCount[$id] . ' sub-account' . ($subCount[$id] === 1 ? '' : 's') . '</div>' : '' ?></td>
        <td data-label="Type" class="small"><?= h($t['name']) ?><?= (int)$t['is_system'] === 1 ? '' : '<div class="text-muted">' . h($r['account_type']) . '</div>' ?></td><td data-label="Normal" class="small"><?= h($r['normal_balance']) ?></td>
        <td data-label="With SL" class="small"><?php if ((int)$r['with_sl']): ?><i class="bi bi-check-lg text-success"></i> Yes<?php endif; ?></td>
        <td data-label="<?= $hasSubs ? 'Total with sub-accounts' : 'Balance' ?>" class="num">
          <?php if ($masked): ?>•••<?php elseif ($hasSubs): ?><strong title="Its own balance and its sub-accounts'"><?= money($total) ?></strong>
            <?= abs($bal) >= 0.005 ? '<div class="small text-muted">own ' . money($bal) . '</div>' : '' ?><?php else: ?><?= money($bal) ?><?php endif; ?></td>
        <td class="text-nowrap small coa-acts">
          <?= $canChange && Access::can($pageKey, 'edit') ? '<a href="' . h(url($pageKey, ['a' => 'edit', 'id' => $r['id']])) . '">Edit</a>' : '' ?>
          <?= Access::page('bank.register') && in_array($r['account_type'], ['Bank', 'Credit Card', 'Accounts Receivable', 'Accounts Payable', 'Other Current Asset', 'Other Current Liability'], true) ? ' | <a href="' . h(url('bank.register', ['account_id' => $r['id']])) . '">Register</a>' : '' ?>
          <?php if ($canChange && Access::can($pageKey, 'delete') && !(int)$r['is_system'] && abs((float)$r['net']) < 0.005 && !$hasSubs): ?>
            <form method="post" action="<?= h(url($pageKey, ['a' => 'delete', 'id' => $r['id']])) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 text-danger" data-confirm="Delete this account?">Delete</button></form>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endforeach; ?>
<?php
layout_end();
