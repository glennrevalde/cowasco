<?php
declare(strict_types=1);

/**
 * Lines of Business: the business units of the multipurpose cooperative (savings and credit, consumer store,
 * marketing, services ...), who may transact in each, member and nonmember prices, patronage refund eligibility,
 * the unit new documents take by module, the lines the cooperative runs, the accounts eliminated when the units
 * are consolidated, and the document rules (locked posted documents, member no. on documents).
 */
require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.business_units';
if (!BusinessUnits::ready()) {
    coop_layout('Lines of Business');
    echo '<div class="alert alert-warning">Lines of business need a database update. Import <code>database/migrations/2026_11_15_multipurpose_party.sql</code>, then reload this page.</div>';
    layout_end();
    return;
}
$edit = gint('id') ? DB::row('SELECT * FROM business_units WHERE id = :id', ['id' => gint('id')]) : null;
$levels = Lookup::priceLevels();
$sites = Lookup::sites();

handle_post(function () use ($edit, $pageKey, $levels) {
    $action = (string)p('action', 'unit');
    $back = url($pageKey, $edit ? ['id' => $edit['id']] : []);
    if ($action === 'unit') {
        Access::require($pageKey, $edit ? 'edit' : 'create');
        $d = [
            'code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 10),
            'name' => mb_substr(trim((string)p('name')), 0, 80),
            'kind' => array_key_exists(p('kind'), BusinessUnits::KINDS) ? p('kind') : 'other',
            'nonmember_policy' => array_key_exists(p('nonmember_policy'), BusinessUnits::POLICIES) ? p('nonmember_policy') : 'allow',
            'patronage_eligible' => p('patronage_eligible') === '1' ? 1 : 0,
            'member_price_level_id' => isset($levels[(int)p('member_price_level_id')]) ? (int)p('member_price_level_id') : null,
            'nonmember_price_level_id' => isset($levels[(int)p('nonmember_price_level_id')]) ? (int)p('nonmember_price_level_id') : null,
            'default_site_id' => pint('default_site_id'),
            'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null,
            'sort_order' => (int)p('sort_order', '0'),
            'is_active' => p('is_active', '1') === '1' ? 1 : 0,
        ];
        if ($d['code'] === '' || $d['name'] === '') {
            fail('Enter the code and the name of the line of business.');
        }
        if (!preg_match('/^[A-Z0-9-]+$/', $d['code'])) {
            fail('The code uses letters, numbers and dashes only.');
        }
        if (DB::value('SELECT id FROM business_units WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $edit['id'] ?? 0])) {
            fail('Code ' . $d['code'] . ' is already used.');
        }
        if ($edit) {
            if ($d['is_active'] === 0 && (int)$edit['is_active'] === 1) {
                foreach (BusinessUnits::defaults() as $m => $u) {
                    if ($u === (int)$edit['id']) {
                        fail('This line of business is the default of ' . BusinessUnits::MODULES[$m][0] . '. Choose another default first.');
                    }
                }
            }
            DB::update('business_units', $d, 'id = :id', ['id' => $edit['id']]);
            Audit::log('edit', 'coop', 'business_unit', (int)$edit['id'], $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey, 'before' => $edit, 'after' => $d]);
            $id = (int)$edit['id'];
        } else {
            $id = DB::insert('business_units', $d);
            Audit::log('create', 'coop', 'business_unit', $id, $d['code'] . ' ' . $d['name'], ['page_key' => $pageKey, 'snapshot' => $d]);
        }
        BusinessUnits::reset();
        flash('success', 'Line of business saved.');
        redirect(url($pageKey, ['id' => $id]));
    }
    Access::require($pageKey, 'edit');
    if ($action === 'settings') {
        $before = Settings::all();
        $units = BusinessUnits::options();
        foreach (array_keys(BusinessUnits::MODULES) as $m) {
            $v = (int)p('bu_default_' . $m);
            Settings::set('bu_default_' . $m, isset($units[$v]) ? (string)$v : '');
        }
        $on = array_map('strval', (array)($_POST['modules_on'] ?? []));
        $off = array_values(array_diff(array_keys(BusinessUnits::SWITCHABLE), $on));
        Settings::set('disabled_modules', implode(',', $off));
        $lock = p('lock_posted_documents') === '1' ? '1' : '0';
        if ($lock !== Settings::get('lock_posted_documents', '0') && !Access::can($pageKey, 'approve')) {
            fail('Turning the lock of posted documents on or off needs the Approve right on this page.');
        }
        Settings::set('lock_posted_documents', $lock);
        Settings::set('doc_show_member_no', p('doc_show_member_no') === '1' ? '1' : '0');
        Settings::set('member_price_level_id', isset($levels[(int)p('member_price_level_id')]) ? (string)(int)p('member_price_level_id') : '');
        Settings::set('nonmember_price_level_id', isset($levels[(int)p('nonmember_price_level_id')]) ? (string)(int)p('nonmember_price_level_id') : '');
        Settings::flush();
        $after = Settings::all();
        $keys = array_merge(array_map(fn($m) => 'bu_default_' . $m, array_keys(BusinessUnits::MODULES)), ['disabled_modules', 'lock_posted_documents', 'doc_show_member_no', 'member_price_level_id', 'nonmember_price_level_id']);
        Audit::log('settings', 'coop', 'business_units', null, 'Lines of business settings', ['page_key' => $pageKey,
            'before' => array_intersect_key($before, array_flip($keys)), 'after' => array_intersect_key($after, array_flip($keys))]);
        Access::reset();
        flash('success', 'Settings saved.');
        redirect(url($pageKey));
    }
    if ($action === 'assign') {
        // older documents without a unit get their module's default (amounts do not change; the ledger lines follow)
        $m = (string)p('module');
        if (!isset(BusinessUnits::MODULES[$m])) {
            fail('Choose the documents.');
        }
        $unit = BusinessUnits::valid(p('unit_id')) ?? fail('Choose the line of business to give them.');
        if (trim((string)p('reason')) === '') {
            fail('Enter the reason.');
        }
        [$in, $ip] = DB::in('ty', BusinessUnits::MODULES[$m][1]);
        $n = DB::tx(function () use ($in, $ip, $unit) {
            $ids = array_map('intval', DB::column("SELECT id FROM transactions WHERE business_unit_id IS NULL AND txn_type IN $in", $ip));
            if (!$ids) {
                return 0;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                [$cin, $cp] = DB::in('tx', $chunk);
                DB::run("UPDATE transactions SET business_unit_id = :u WHERE id IN $cin", ['u' => $unit] + $cp);
                DB::run("UPDATE journal_lines SET business_unit_id = :u WHERE txn_id IN $cin", ['u' => $unit] + $cp);
            }
            return count($ids);
        });
        Audit::log('assign_unit', 'coop', 'business_unit', $unit, BusinessUnits::name($unit), ['page_key' => $pageKey, 'reason' => p('reason') . ' (' . $n . ' ' . BusinessUnits::MODULES[$m][0] . ')']);
        flash('success', $n . ' document(s) given ' . BusinessUnits::name($unit) . '.');
        redirect(url($pageKey));
    }
    if ($action === 'patronage') {
        $cat = pint('category_id');
        $flag = p('flag') === '1' ? 1 : 0;
        $n = $cat
            ? DB::run("UPDATE items SET patronage_eligible = :f WHERE category_id = :c AND item_type IN ('inventory','non_inventory','assembly','service')", ['f' => $flag, 'c' => $cat])->rowCount()
            : DB::run("UPDATE items SET patronage_eligible = :f WHERE item_type IN ('inventory','non_inventory','assembly','service')", ['f' => $flag])->rowCount();
        Audit::log('patronage_items', 'coop', 'item', null, $cat ? 'Category ' . $cat : 'All goods and services', ['page_key' => $pageKey, 'reason' => ($flag ? 'Counts' : 'Does not count') . ' for patronage refund (' . $n . ' items)']);
        flash('success', $n . ' item(s) updated.');
        redirect(url($pageKey));
    }
    if ($action === 'interunit') {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['interunit_ids'] ?? [])))));
        $before = array_map('intval', DB::column('SELECT id FROM accounts WHERE is_interunit = 1'));
        DB::run('UPDATE accounts SET is_interunit = 0 WHERE is_interunit = 1');
        if ($ids) {
            [$in, $ip] = DB::in('ac', $ids);
            DB::run("UPDATE accounts SET is_interunit = 1 WHERE id IN $in", $ip);
        }
        Accts::reset();
        BusinessUnits::reset();
        Audit::log('interunit_accounts', 'coop', 'account', null, 'Inter-unit accounts', ['page_key' => $pageKey, 'changes' => [['field' => 'is_interunit', 'old' => implode(',', $before), 'new' => implode(',', $ids)]]]);
        flash('success', 'Inter-unit accounts saved.');
        redirect(url($pageKey));
    }
    fail('Unknown action.');
});

$units = DB::all('SELECT u.*, (SELECT COUNT(*) FROM transactions t WHERE t.business_unit_id = u.id) AS docs FROM business_units u ORDER BY u.sort_order, u.code');
$defaults = BusinessUnits::defaults();
$disabled = BusinessUnits::disabledModules();
$unassigned = [];
foreach (BusinessUnits::MODULES as $m => [, $types]) {
    [$in, $ip] = DB::in('ty', $types);
    $unassigned[$m] = (int)DB::value("SELECT COUNT(*) FROM transactions WHERE business_unit_id IS NULL AND txn_type IN $in", $ip);
}
$canEdit = Access::can($pageKey, 'edit');
$unitOpts = BusinessUnits::options();
// the level that applies: the unit's own, else the general member or nonmember level ("general"), else standard prices
$priceText = function (int $own, string $who) use ($levels): string {
    if ($own && isset($levels[$own])) {
        return $levels[$own];
    }
    $g = (int)Settings::get($who . '_price_level_id', '');
    return $g && isset($levels[$g]) ? $levels[$g] . ' (general)' : 'Standard';
};
coop_layout('Lines of Business', ['actions' => page_link('rpt.sop_business_unit', [], 'Statement of Operations by Line of Business', 'btn-outline-secondary', 'bar-chart')]);
?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card mb-3">
      <div class="card-header fw-semibold">Lines of business</div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0 stack-sm">
        <thead><tr><th>Code</th><th>Line of business</th><th>Who may transact</th><th>Patronage</th><th>Prices (member / nonmember)</th><th class="num">Documents</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($units as $u): ?>
          <tr class="<?= (int)$u['is_active'] ? '' : 'text-muted' ?>">
            <td data-label="Code" class="fw-semibold"><?= h($u['code']) ?></td>
            <td data-label="Line of business"><?= h($u['name']) ?><div class="small text-muted"><?= h(BusinessUnits::KINDS[$u['kind']] ?? $u['kind']) ?><?= (int)$u['is_active'] ? '' : ', inactive' ?></div></td>
            <td data-label="Who may transact" class="small"><?= h(BusinessUnits::POLICIES[$u['nonmember_policy']] ?? $u['nonmember_policy']) ?></td>
            <td data-label="Patronage" class="small"><?= (int)$u['patronage_eligible'] ? 'Counts' : 'Does not count' ?></td>
            <td data-label="Prices" class="small"><?= h($priceText((int)$u['member_price_level_id'], 'member') . ' / ' . $priceText((int)$u['nonmember_price_level_id'], 'nonmember')) ?></td>
            <td data-label="Documents" class="num"><?= number_format((int)$u['docs']) ?></td>
            <td class="text-end"><?= $canEdit ? '<a href="' . h(url($pageKey, ['id' => $u['id']])) . '">Edit</a>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$units): ?><tr><td colspan="7" class="text-muted text-center py-3">No line of business yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>

    <form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
      <div class="card-header fw-semibold">Defaults, lines run and document rules</div>
      <div class="card-body">
        <div class="small text-muted mb-2">The line of business a new document takes when its form does not choose one. Forms show the choice while two or more lines are active.</div>
        <div class="row g-2">
          <?php foreach (BusinessUnits::MODULES as $m => [$label]): ?>
            <?= select_field('bu_default_' . $m, $label, $unitOpts, $defaults[$m] ?? '', ['class' => 'col-md-6', 'blank' => 'None', 'extra' => 'data-no-combo']) ?>
          <?php endforeach; ?>
        </div>
        <div class="fw-semibold small mt-3 mb-1">Lines the cooperative runs</div>
        <div class="row g-1">
          <?php foreach (BusinessUnits::SWITCHABLE as $code => $label): ?>
            <div class="col-6 col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="modules_on[]" value="<?= h($code) ?>" id="mod_<?= h($code) ?>" <?= in_array($code, $disabled, true) ? '' : 'checked' ?>>
              <label class="form-check-label small" for="mod_<?= h($code) ?>"><?= h($label) ?></label></div></div>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-1">A line switched off closes its entry pages for everybody. Its reports stay, so its history can be read.</div>
        <div class="fw-semibold small mt-3 mb-1">Prices when the line of business has none</div>
        <div class="row g-2">
          <?= select_field('member_price_level_id', 'Members', $levels, Settings::get('member_price_level_id', ''), ['class' => 'col-md-6', 'blank' => 'Standard prices', 'extra' => 'data-no-combo']) ?>
          <?= select_field('nonmember_price_level_id', 'Nonmembers and walk-in customers', $levels, Settings::get('nonmember_price_level_id', ''), ['class' => 'col-md-6', 'blank' => 'Standard prices', 'extra' => 'data-no-combo']) ?>
        </div>
        <div class="small text-muted mt-1">A customer's own price level always comes first. Price levels can carry dates (Financial &gt; Terms, Tax Codes &amp; Other Lists) for promotions.</div>
        <div class="fw-semibold small mt-3 mb-1">Documents</div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="lock_posted_documents" value="1" id="lockPosted" <?= Settings::get('lock_posted_documents', '0') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label small" for="lockPosted">Lock posted documents: amounts, accounts, names, dates and lines of a posted document change only by a user with "Can change or delete a posted document", with a reason; nobody else deletes them (they void them)</label></div>
        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="doc_show_member_no" value="1" id="showMemberNo" <?= Settings::get('doc_show_member_no', '1') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label small" for="showMemberNo">Print the member no. under the name of a member on invoices, receipts, checks and vouchers</label></div>
      </div>
      <?php if ($canEdit): ?><div class="card-footer"><button class="btn btn-sm btn-primary">Save settings</button></div><?php endif; ?>
    </form>

    <?php if ($canEdit && array_sum($unassigned) > 0): ?>
    <form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="assign">
      <div class="card-header fw-semibold">Documents without a line of business</div>
      <div class="card-body row g-2">
        <div class="col-12 small">
          <?php foreach ($unassigned as $m => $n): if (!$n) { continue; } ?><div><?= h(BusinessUnits::MODULES[$m][0]) ?>: <strong><?= number_format($n) ?></strong></div><?php endforeach; ?>
        </div>
        <?= select_field('module', 'Documents', array_map(fn($x) => $x[0], array_filter(BusinessUnits::MODULES, fn($x, $k) => $unassigned[$k] > 0, ARRAY_FILTER_USE_BOTH)), '', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
        <?= select_field('unit_id', 'Give them', $unitOpts, '', ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
        <?= input_field('reason', 'Reason', '', ['class' => 'col-md-9', 'required' => true]) ?>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Assign</button></div>
        <div class="col-12 small text-muted">Only the line of business is recorded on the documents and their ledger lines; amounts, numbers and dates do not change.</div>
      </div>
    </form>
    <?php endif; ?>
  </div>

  <div class="col-xl-5">
    <?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="unit">
      <div class="card-header fw-semibold"><?= $edit ? 'Edit ' . h($edit['code'] . ' ' . $edit['name']) : 'New line of business' ?></div>
      <div class="card-body row g-2">
        <?= input_field('code', 'Code', fv('code', $edit), ['class' => 'col-4', 'required' => true, 'extra' => 'maxlength="10" placeholder="MK"']) ?>
        <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-8', 'required' => true, 'extra' => 'maxlength="80"']) ?>
        <?= select_field('kind', 'Kind', BusinessUnits::KINDS, fv('kind', $edit, 'retail'), ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
        <?= select_field('nonmember_policy', 'Who may transact', BusinessUnits::POLICIES, fv('nonmember_policy', $edit, 'allow'), ['class' => 'col-md-6', 'extra' => 'data-no-combo']) ?>
        <?= select_field('member_price_level_id', 'Price level of members', $levels, fv('member_price_level_id', $edit), ['class' => 'col-md-6', 'blank' => 'The general one', 'extra' => 'data-no-combo']) ?>
        <?= select_field('nonmember_price_level_id', 'Price level of nonmembers', $levels, fv('nonmember_price_level_id', $edit), ['class' => 'col-md-6', 'blank' => 'The general one', 'extra' => 'data-no-combo']) ?>
        <?= select_field('default_site_id', 'Usual site', $sites, fv('default_site_id', $edit), ['class' => 'col-md-6', 'blank' => '', 'extra' => 'data-no-combo']) ?>
        <?= input_field('sort_order', 'Order in lists', fv('sort_order', $edit, '0'), ['class' => 'col-md-6', 'type' => 'number', 'step' => '1']) ?>
        <?= input_field('description', 'Description', fv('description', $edit), ['class' => 'col-12', 'extra' => 'maxlength="255"']) ?>
        <?= check_field('patronage_eligible', 'Member transactions here count for patronage refund', fv('patronage_eligible', $edit, 1), 'col-12') ?>
        <?= check_field('is_active', 'Active', fv('is_active', $edit, 1), 'col-12') ?>
        <div class="col-12 small text-muted">"Members only" refuses sales to nonmembers and walk-in customers; "Nonmembers need approval" asks for a supervisor. A marketing line applies the same rule to the suppliers of produce.</div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Save</button> <?php if ($edit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey)) ?>">New</a><?php endif; ?></div>
      </div>
    </form>
    <?php endif; ?>

    <?php if ($canEdit):
        $cats = DB::pairs('SELECT id, name FROM item_categories ORDER BY name');
        $eligible = (int)DB::value("SELECT COUNT(*) FROM items WHERE patronage_eligible = 1 AND is_active = 1 AND item_type IN ('inventory','non_inventory','assembly','service')");
        $itemsTotal = (int)DB::value("SELECT COUNT(*) FROM items WHERE is_active = 1 AND item_type IN ('inventory','non_inventory','assembly','service')");
    ?>
    <form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="patronage">
      <div class="card-header fw-semibold">Goods and services that count for patronage refund</div>
      <div class="card-body row g-2">
        <div class="col-12 small"><?= number_format($eligible) ?> of <?= number_format($itemsTotal) ?> active goods and services count. Not every member sale qualifies: only these items, in lines of business that count.</div>
        <?= select_field('category_id', 'Category', $cats, '', ['class' => 'col-md-6', 'blank' => 'Every good and service', 'extra' => 'data-no-combo']) ?>
        <?= select_field('flag', 'They', ['1' => 'Count', '0' => 'Do not count'], '1', ['class' => 'col-md-3', 'extra' => 'data-no-combo']) ?>
        <div class="col-md-3 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary w-100">Apply</button></div>
        <div class="col-12 small text-muted">One item at a time: Inventory &gt; Item List, "Counts for patronage refund".</div>
      </div>
    </form>

    <?php
        $interunit = array_map('intval', DB::column('SELECT id FROM accounts WHERE is_interunit = 1'));
        $acctOpts = [];
        foreach (DB::all("SELECT id, code, name, category FROM accounts WHERE (is_active = 1 OR is_interunit = 1) AND category IN ('Asset','Liability','Income','Expense') ORDER BY code") as $a) {
            $acctOpts[(int)$a['id']] = $a['code'] . ' ' . $a['name'];
        }
    ?>
    <form method="post" class="card mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="interunit">
      <div class="card-header fw-semibold">Inter-unit accounts</div>
      <div class="card-body row g-2">
        <?= multi_check_field('interunit_ids', 'Due to / due from, inter-unit sales and charges, and their cost', $acctOpts, $interunit, ['all' => 'None', 'noun' => 'accounts', 'class' => 'col-12', 'id' => 'interunitAcc']) ?>
        <div class="col-12 small text-muted">Accounts used only for dealings between lines of business, such as Due from Consumer Store and Due to Savings and Credit, or Inter-unit Sales and Inter-unit Purchases. They show on each unit's statements and are eliminated (left out) when the whole cooperative is reported: what the units owe each other nets out, and what they sold or charged each other nets out against its cost, so nothing is counted twice.</div>
        <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save</button></div>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
layout_end();
