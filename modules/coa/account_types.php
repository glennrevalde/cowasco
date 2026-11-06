<?php
declare(strict_types=1);

/**
 * Chart of Accounts > Account Types: the fifteen built-in types and the user's own (Cash, Loans Receivable ...). A type
 * of the user's behaves as one built-in type (what the modules do with its accounts), is a classification of its own on
 * the Balance Sheet and the Income Statement, and is linked to a section of the Statement of Cash Flows.
 */
$pageKey = 'coa.account_types';
$a = (string)g('a');
$canChange = (bool)Access::special('coa_changes');
if (!AccountTypes::ready()) {
    layout_start('Account Types');
    echo '<div class="alert alert-warning">Run the upgrade script database/migrations/2027_01_24_account_types.sql first.</div>';
    layout_end();
    return;
}
$id = (int)gint('id');
$type = $id ? AccountTypes::get($id) : null;
if ($id && !$type) {
    flash('warning', 'Account type not found.');
    redirect(url($pageKey));
}

handle_post(function () use ($pageKey, $type, $canChange) {
    if (!$canChange) {
        fail('You are not allowed to change the Chart of Accounts.');
    }
    $action = (string)p('action');
    if ($action === 'save') {
        Access::require($pageKey, $type ? 'edit' : 'create');
        $newId = AccountTypes::save(['name' => p('name'), 'base_type' => p('base_type'), 'cash_flow' => p('cash_flow'), 'sort_order' => p('sort_order'),
            'description' => p('description'), 'is_active' => p('is_active', '1') === '1'], $type ? (int)$type['id'] : null, $pageKey);
        flash('success', $type ? 'Account type saved.' : 'Account type added. Give it its accounts below.');
        redirect(wants_new() ? url($pageKey, ['a' => 'new']) : url($pageKey, ['a' => 'edit', 'id' => $newId]));
    }
    if ($action === 'delete') {
        Access::require($pageKey, 'delete');
        if (!$type) {
            fail('Account type not found.');
        }
        AccountTypes::delete((int)$type['id'], $pageKey);
        flash('success', 'Account type ' . $type['name'] . ' deleted.');
        redirect(url($pageKey));
    }
    if ($action === 'assign') {
        // accounts given this type, or (from the accounts of this type) moved to another type
        Access::require($pageKey, 'edit');
        Access::require('coa.accounts', 'edit');
        $to = (int)pint('to_type') ?: (int)($type['id'] ?? 0);
        $ids = array_map('intval', (array)($_POST['accounts'] ?? []));
        if (!$ids) {
            fail('Tick the accounts first.');
        }
        $target = AccountTypes::get($to);
        if (!$target) {
            fail('Choose the account type.');
        }
        $n = AccountTypes::assign($to, $ids, $pageKey);
        flash('success', $n . ' account(s) are now of the type ' . $target['name'] . '.');
        redirect(url($pageKey, ['a' => 'edit', 'id' => $type['id'] ?? $to]));
    }
    fail('Unknown action.');
});

$all = AccountTypes::all();
$usage = AccountTypes::usage();
$flowWords = ['cash' => 'Cash and cash equivalents', 'operating' => 'Operating', 'investing' => 'Investing', 'financing' => 'Financing'];

// ------------------------------------------------------------------ the form of a type
if ($a === 'new' || $a === 'edit') {
    Access::require($pageKey, $type ? 'edit' : 'create');
    if (!$canChange) {
        Access::denyPage($pageKey, 'change the Chart of Accounts');
    }
    $rec = $type ?: ['name' => '', 'base_type' => (string)(g('base') ?: 'Other Current Asset'), 'cash_flow' => '', 'sort_order' => 0, 'description' => '', 'is_active' => 1, 'is_system' => 0];
    if (!isset(AccountTypes::BASE[$rec['base_type']])) {
        $rec['base_type'] = 'Other Current Asset';
    }
    $isSystem = (int)$rec['is_system'] === 1;
    $inUse = $type ? (int)DB::value('SELECT COUNT(*) FROM accounts WHERE type_id = :t', ['t' => $type['id']]) : 0;
    $baseLocked = $isSystem || $inUse > 0;
    $base = (string)(is_post() && !$baseLocked ? p('base_type', $rec['base_type']) : $rec['base_type']);
    $flow = (string)(is_post() ? p('cash_flow', '') : ($rec['cash_flow'] ?: AccountTypes::defaultCashFlow($base)));
    // the accounts of this type, and those that can be given it (the same category; an account the modules post to only
    // a type of its own built-in type)
    $accounts = [];
    $others = [];
    if ($type) {
        $byId = array_column(DB::all('SELECT * FROM accounts ORDER BY code'), null, 'id');
        foreach ($byId as $acc) {
            $t = AccountTypes::forAccount($acc);
            if ((int)$t['id'] === (int)$type['id']) {
                $accounts[] = $acc;
            } elseif ($acc['category'] === $type['category'] && ((int)$acc['is_system'] !== 1 || $acc['account_type'] === $type['base_type'])) {
                $acc['_type'] = $t['name'];
                $acc['_main'] = AccountTypes::mainOf($acc, $byId);
                $others[] = $acc;
            }
        }
    }
    $sameCat = $type ? array_filter($all, fn($t) => $t['category'] === $type['category'] && (int)$t['id'] !== (int)$type['id'] && (int)$t['is_active'] === 1) : [];
    layout_start($type ? 'Account type: ' . $type['name'] : 'New account type', ['actions' => btn_link(url($pageKey), 'Account types', 'btn-outline-secondary', 'arrow-left')]);
    ?>
    <div class="row g-3 acty-edit">
      <div class="col-xl-7">
        <form method="post" class="card acty-form" data-once>
          <div class="card-header"><?= $type ? 'The type' : 'A type of your own' ?></div>
          <div class="card-body row g-3">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <div class="col-md-6"><label class="form-label" for="atName">Name</label>
              <input class="form-control form-control-sm" id="atName" name="name" maxlength="60" value="<?= h((string)fv('name', $rec)) ?>" <?= $isSystem ? 'readonly' : 'required autofocus' ?> placeholder="Loans Receivable">
              <?php if ($isSystem): ?><div class="form-text">A built-in type keeps its name and what it is.</div><?php endif; ?></div>
            <div class="col-md-6"><label class="form-label" for="atBase">Behaves as</label>
              <?php if ($baseLocked): ?>
                <input type="hidden" name="base_type" value="<?= h($base) ?>"><input class="form-control form-control-sm" id="atBase" value="<?= h($base) ?>" readonly data-base="<?= h($base) ?>">
                <?php if (!$isSystem): ?><div class="form-text"><?= $inUse ?> account(s) are of this type: move them to another type to change it.</div><?php endif; ?>
              <?php else: ?>
                <select class="form-select form-select-sm" id="atBase" name="base_type" required data-no-combo>
                  <?php foreach (AccountTypes::CATEGORIES as $cat => $catLabel): ?><optgroup label="<?= h($catLabel) ?>">
                    <?php foreach (AccountTypes::BASE as $b => $info): if ($info[0] !== $cat) { continue; } ?><option value="<?= h($b) ?>"<?= $b === $base ? ' selected' : '' ?>><?= h($b) ?></option><?php endforeach; ?>
                  </optgroup><?php endforeach; ?>
                </select>
              <?php endif; ?></div>
            <div class="col-12"><div class="acty-base-note" id="atBaseNote"></div></div>
            <div class="col-12" id="atFlowBox">
              <span class="form-label d-block">On the Statement of Cash Flows</span>
              <div class="acty-flow" role="radiogroup" aria-label="Section of the Statement of Cash Flows">
                <?php foreach (AccountTypes::CASH_FLOW as $k => $label): ?>
                  <input class="btn-check" type="radio" name="cash_flow" id="atFlow<?= h($k) ?>" value="<?= h($k) ?>"<?= $flow === $k ? ' checked' : '' ?>>
                  <label class="btn btn-sm" for="atFlow<?= h($k) ?>"><?= h($label) ?></label>
                <?php endforeach; ?>
              </div>
              <div class="form-text">Cash and cash equivalents make the cash at the beginning and at the end of the statement; the change in the other accounts is an operating, investing or financing activity. Every balance sheet account is counted once, so the statement ties to the cash.</div>
            </div>
            <div class="col-12" id="atFlowPl" hidden><span class="form-label d-block">On the Statement of Cash Flows</span><div class="small">Part of the net income (operating activities).</div></div>
            <div class="col-6 col-md-3"><label class="form-label" for="atSort">Order</label>
              <input class="form-control form-control-sm" type="number" id="atSort" name="sort_order" min="0" max="9999" step="1" value="<?= (int)fv('sort_order', $rec, 0) ?>">
              <div class="form-text">Lower first, among the types of the same built-in type</div></div>
            <div class="col-md-9"><label class="form-label" for="atDesc">Description</label>
              <input class="form-control form-control-sm" id="atDesc" name="description" maxlength="255" value="<?= h((string)fv('description', $rec)) ?>"></div>
            <?php if (!$isSystem): ?><?= check_field('is_active', 'Active (offered when an account is added or changed)', fv('is_active', $rec, 1), 'col-12') ?><?php endif; ?>
            <div class="col-12"><div class="acty-where" id="atWhere" aria-live="polite"></div></div>
            <div class="col-12"><?= save_buttons('Save account type', url($pageKey), Access::can($pageKey, 'create')) ?></div>
          </div>
        </form>
        <?php if ($type && !$isSystem && !$inUse && Access::can($pageKey, 'delete')): ?>
          <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
            <button class="btn btn-outline-danger btn-sm" data-confirm="Delete the account type <?= h($type['name']) ?>?"><i class="bi bi-trash3"></i> Delete this type</button></form>
        <?php endif; ?>
      </div>
      <?php if ($type): ?>
      <div class="col-xl-5">
        <form method="post" class="card mb-3 acty-accts" data-once>
          <div class="card-header d-flex justify-content-between align-items-center"><span>Accounts of this type</span><span class="small text-muted fw-normal"><?= count($accounts) ?></span></div>
          <?= csrf_field() ?><input type="hidden" name="action" value="assign">
          <?php if ($accounts): ?>
            <div class="acty-list">
              <?php foreach ($accounts as $acc): ?>
                <label class="acty-item"><input class="form-check-input" type="checkbox" name="accounts[]" value="<?= (int)$acc['id'] ?>">
                  <span><strong><?= h($acc['code']) ?></strong> <?= h($acc['name']) ?><?= $acc['parent_id'] ? ' <span class="text-muted small">sub-account</span>' : '' ?><?= (int)$acc['is_active'] ? '' : ' <span class="text-muted small">inactive</span>' ?></span></label>
              <?php endforeach; ?>
            </div>
            <?php if ($sameCat && Access::can('coa.accounts', 'edit')): ?>
            <div class="card-body acty-move">
              <label class="form-label" for="atMove">Move the ticked accounts to</label>
              <div class="d-flex flex-wrap gap-2"><select class="form-select form-select-sm" id="atMove" name="to_type" data-no-combo><?php foreach ($sameCat as $tid => $t): ?><option value="<?= (int)$tid ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Move</button></div>
            </div>
            <?php endif; ?>
          <?php else: ?><div class="card-body small text-muted">No account is of this type yet. Give it accounts below, or choose it on an account (Chart of Accounts &gt; Edit).</div><?php endif; ?>
        </form>
        <?php if ($others && Access::can('coa.accounts', 'edit')): ?>
        <form method="post" class="card acty-add" data-once>
          <div class="card-header">Give accounts this type</div>
          <?= csrf_field() ?><input type="hidden" name="action" value="assign"><input type="hidden" name="to_type" value="<?= (int)$type['id'] ?>">
          <div class="card-body pb-2"><input type="search" class="form-control form-control-sm" placeholder="Find an account (code or name)" data-acty-find aria-label="Find an account">
            <div class="form-text">The <?= h(strtolower((string)$type['category'])) ?> accounts of other types. Ticking a main account ticks its sub-accounts too.</div></div>
          <div class="acty-list acty-pick">
            <?php foreach ($others as $acc): $isSub = (int)$acc['_main']['id'] !== (int)$acc['id']; ?>
              <label class="acty-item<?= $isSub ? ' is-sub' : '' ?>" data-text="<?= h(strtolower($acc['code'] . ' ' . $acc['name'])) ?>"><input class="form-check-input" type="checkbox" name="accounts[]" value="<?= (int)$acc['id'] ?>" data-main="<?= (int)$acc['_main']['id'] ?>" data-id="<?= (int)$acc['id'] ?>">
                <span><strong><?= h($acc['code']) ?></strong> <?= h($acc['name']) ?><span class="d-block small text-muted"><?= h($acc['_type']) ?><?= $acc['account_type'] !== $type['base_type'] ? ', will behave as ' . h($type['base_type']) : '' ?></span></span></label>
            <?php endforeach; ?>
          </div>
          <div class="card-body pt-2"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Give them the type <?= h($type['name']) ?></button>
            <div class="form-text">An account that takes a type of another built-in type behaves as that type from then on (for example a Bank type offers it where cash is paid or received). Its entries do not change.</div></div>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <script>
    (function () {
      const bases = <?= json_encode(array_map(fn($b) => ['cat' => $b[0], 'group' => $b[2], 'flow' => $b[3]], AccountTypes::BASE), JSON_UNESCAPED_UNICODE) ?>;
      const notes = <?= json_encode(AccountTypes::BASE_NOTE, JSON_UNESCAPED_UNICODE) ?>;
      const cats = <?= json_encode(AccountTypes::CATEGORIES) ?>;
      const flows = <?= json_encode(AccountTypes::CASH_FLOW) ?>;
      const base = document.getElementById('atBase'), name = document.getElementById('atName');
      const box = document.getElementById('atFlowBox'), pl = document.getElementById('atFlowPl');
      const note = document.getElementById('atBaseNote'), where = document.getElementById('atWhere');
      const baseOf = () => base ? (base.dataset.base || base.value) : '';
      const sync = (fresh) => {
        const b = bases[baseOf()];
        if (!b) return;
        const bs = ['Asset', 'Liability', 'Equity'].includes(b.cat);
        box.hidden = !bs; pl.hidden = bs;
        box.querySelectorAll('input').forEach((i) => { i.disabled = !bs; i.required = bs; });
        if (bs && (fresh || !box.querySelector('input:checked'))) {
          const d = box.querySelector('input[value="' + b.flow + '"]'); if (d) d.checked = true;
        }
        note.textContent = 'The program treats its accounts as ' + baseOf() + ' accounts: ' + (notes[baseOf()] || '') + '.';
        const nm = (name.value || '').trim() || 'this type';
        const f = box.querySelector('input:checked');
        where.innerHTML = '';
        const i = document.createElement('i'); i.className = 'bi bi-diagram-3';
        const t = document.createElement('div');
        t.textContent = bs
          ? 'Balance Sheet: ' + cats[b.cat] + (b.group !== cats[b.cat] ? ' > ' + b.group : '') + ' > ' + nm + ' (its accounts and a total).  Statement of Cash Flows: ' + (f ? flows[f.value] : '') + '.'
          : 'Income Statement: ' + b.group + ' > ' + nm + ' (its accounts and a total).  Statement of Cash Flows: part of the net income.';
        where.append(i, t);
      };
      if (base && base.tagName === 'SELECT') base.addEventListener('change', () => sync(true));
      box.addEventListener('change', () => sync(false));
      if (name) name.addEventListener('input', () => sync(false));
      sync(false);
      // the accounts to give the type: a filter, and a main account brings its sub-accounts
      const find = document.querySelector('[data-acty-find]');
      if (find) find.addEventListener('input', () => {
        const q = find.value.trim().toLowerCase();
        document.querySelectorAll('.acty-pick .acty-item').forEach((l) => { l.hidden = q !== '' && !l.dataset.text.includes(q); });
      });
      document.querySelectorAll('.acty-pick input[type=checkbox]').forEach((c) => c.addEventListener('change', () => {
        if (c.dataset.main !== c.dataset.id) return;
        document.querySelectorAll('.acty-pick input[data-main="' + c.dataset.id + '"]').forEach((s) => { if (s !== c) s.checked = c.checked; });
      }));
    })();
    </script>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ the list
Access::require($pageKey, 'view');
$byCat = [];
foreach ($all as $tid => $t) {
    $byCat[$t['category']][$tid] = $t;
}
$flowTypes = [];
foreach ($all as $t) {
    if ($t['cash_flow'] !== '') {
        $flowTypes[$t['cash_flow']][] = $t['name'];
    }
}
layout_start('Account Types', ['actions' => ($canChange ? page_link($pageKey, ['a' => 'new'], 'New account type', 'btn-primary', 'plus-lg', 'create') : '')
    . ' ' . page_link('coa.accounts', [], 'Chart of Accounts', 'btn-outline-secondary', 'journal-bookmark')]);
?>
<p class="acty-intro">Every account type is a classification of its own on the Balance Sheet and the Income Statement (a heading, its accounts and a total), and is linked to a section of the Statement of Cash Flows. A type of your own, such as Cash or Loans Receivable, behaves as one of the built-in types.</p>
<div class="row g-3">
  <div class="col-xl-8">
    <?php foreach (AccountTypes::CATEGORIES as $cat => $catLabel): if (empty($byCat[$cat])) { continue; } ?>
    <div class="card mb-3"><div class="card-header fw-semibold"><?= h($catLabel) ?></div>
      <table class="table table-sm table-hover mb-0 stack-sm acty-table">
        <thead><tr><th>Type</th><th>Behaves as</th><th>Shown under</th><th><?= in_array($cat, ['Income', 'Expense'], true) ? 'Cash flow' : 'Cash flow section' ?></th><th class="num">Accounts</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($byCat[$cat] as $tid => $t): ?>
          <tr class="<?= (int)$t['is_active'] ? '' : 'text-muted' ?>">
            <td data-label="Type"><strong><?= h($t['name']) ?></strong><?= (int)$t['is_system'] === 1 ? ' <span class="acty-sys">built-in</span>' : '' ?><?= (int)$t['is_active'] ? '' : ' <span class="acty-sys">inactive</span>' ?>
              <?= $t['description'] ? '<div class="small text-muted">' . h((string)$t['description']) . '</div>' : '' ?></td>
            <td data-label="Behaves as" class="small"><?= (int)$t['is_system'] === 1 ? '<span class="text-muted">itself</span>' : h($t['base_type']) ?></td>
            <td data-label="Shown under" class="small"><?= h($t['group']) ?></td>
            <td data-label="Cash flow" class="small"><?= $t['cash_flow'] !== '' ? h($flowWords[$t['cash_flow']]) : '<span class="text-muted">Net income</span>' ?></td>
            <td data-label="Accounts" class="num"><?= (int)($usage[$tid] ?? 0) ?></td>
            <td class="text-nowrap small text-end"><?= $canChange && Access::can($pageKey, 'edit') && $tid > 0 ? '<a href="' . h(url($pageKey, ['a' => 'edit', 'id' => $tid])) . '">' . ((int)$t['is_system'] === 1 ? 'Open' : 'Edit') . '</a>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="col-xl-4">
    <div class="card acty-cf mb-3"><div class="card-header">On the Statement of Cash Flows</div>
      <div class="card-body">
        <?php foreach (AccountTypes::CASH_FLOW as $k => $label): ?>
          <div class="acty-cf-row"><div class="acty-cf-k"><?= h($label) ?></div><div class="acty-cf-v"><?= h(implode(', ', $flowTypes[$k] ?? []) ?: 'none') ?></div></div>
        <?php endforeach; ?>
        <div class="acty-cf-row"><div class="acty-cf-k">Net income</div><div class="acty-cf-v text-muted">every income and expense type</div></div>
        <p class="small text-muted mt-2 mb-0">Each balance sheet type is in one section, so every account is counted once and the statement ties to the cash accounts.
          <?= safe_link('rpt.cash_flow', [], 'Open the Statement of Cash Flows') ?></p>
      </div></div>
    <div class="card acty-how"><div class="card-header">Adding a type</div>
      <div class="card-body small">
        <ol class="mb-0 ps-3">
          <li>Click <strong>New account type</strong>, name it (for example Loans Receivable) and choose what it behaves as (Other Current Asset) and its section of the Statement of Cash Flows.</li>
          <li>Give it its accounts: tick them under <strong>Give accounts this type</strong>, or choose the type on an account.</li>
          <li>The Balance Sheet, the Income Statement, their summaries and the Statement of Cash Flows show it as a classification of its own.</li>
        </ol>
      </div></div>
  </div>
</div>
<?php
layout_end();
