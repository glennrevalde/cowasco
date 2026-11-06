<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.lists';
$listKeys = Members::LIST_KEYS;
$tabs = ['types' => 'Member types', 'clusters' => 'Clusters', 'segments' => 'Segmentation'] + $listKeys;
$tab = isset($tabs[g('tab')]) ? (string)g('tab') : 'types';
$editId = gint('id');
$grouping = isset(Members::GROUPING_LISTS[$tab]);

handle_post(function () use ($pageKey, $tab, $editId, $listKeys, $tabs) {
    if ((string)p('action') === 'delete') {
        Access::require($pageKey, 'delete');
        if (!isset($listKeys[$tab])) {
            fail('Member types, clusters and segmentation are switched off, not deleted.');
        }
        $id = pint('id');
        $row = DB::row('SELECT * FROM coop_lists WHERE id = :id AND list_key = :k', ['id' => $id, 'k' => $tab]);
        if (!$row) {
            fail('Not found.');
        }
        $used = Members::listUsage($tab, (string)$row['name']);
        if ($used) {
            fail('"' . $row['name'] . '" cannot be deleted: it is used by ' . implode(' and ', $used) . '. Move them to another entry first, or switch this one off (not active) so it is no longer offered.');
        }
        DB::run('DELETE FROM coop_lists WHERE id = :id', ['id' => $id]);
        Audit::log('delete', 'coop', 'coop_lists', $id, $row['name'], ['page_key' => $pageKey, 'snapshot' => $row]);
        flash('success', '"' . $row['name'] . '" was deleted from ' . mb_strtolower($tabs[$tab]) . '.');
        redirect(url($pageKey, ['tab' => $tab]));
    }

    Access::require($pageKey, $editId ? 'edit' : 'create');
    $max = Members::GROUPING_LISTS[$tab] ?? 80;
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)p('name'))), 0, $max);
    if ($name === '') {
        fail('Enter the name.');
    }
    $active = p('is_active', '1') === '1' ? 1 : 0;
    switch ($tab) {
        case 'types':
            $d = ['code' => mb_substr(strtoupper(trim((string)p('code'))), 0, 10), 'name' => $name, 'member_kind' => array_key_exists(p('member_kind'), Members::KINDS) ? p('member_kind') : 'individual',
                'is_regular' => p('is_regular') === '1' ? 1 : 0, 'min_share_capital' => Dec::fix(Dec::of(p('min_share_capital'))), 'sort_order' => (int)p('sort_order'), 'is_active' => $active];
            if ($d['code'] === '') {
                fail('Enter the code.');
            }
            if (DB::value('SELECT id FROM member_types WHERE code = :c AND id <> :id', ['c' => $d['code'], 'id' => $editId ?? 0])) {
                fail('That code is already used.');
            }
            $table = 'member_types';
            break;
        case 'clusters':
            $d = ['name' => $name, 'branch_id' => pint('branch_id'), 'is_active' => $active];
            $table = 'member_clusters';
            break;
        case 'segments':
            $d = ['name' => $name, 'is_active' => $active];
            $table = 'member_segments';
            break;
        default:
            $d = ['list_key' => $tab, 'name' => $name, 'extra' => mb_substr(trim((string)p('extra')), 0, 120) ?: null, 'sort_order' => (int)p('sort_order'), 'is_active' => $active];
            $table = 'coop_lists';
            if (DB::value('SELECT id FROM coop_lists WHERE list_key = :k AND name = :n AND id <> :id', ['k' => $tab, 'n' => $name, 'id' => $editId ?? 0])) {
                fail('That name is already on the list.');
            }
    }
    DB::tx(function () use ($table, $d, &$editId, $name, $pageKey, $tab) {
        if ($editId) {
            $old = DB::row("SELECT * FROM $table WHERE id = :id", ['id' => $editId]);
            if (!$old || ($table === 'coop_lists' && $old['list_key'] !== $tab)) {
                fail('Not found.');
            }
            DB::update($table, $d, 'id = :id', ['id' => $editId]);
            // a member grouping is kept on the member by its name: a new name is carried to everyone in it
            $moved = $table === 'coop_lists' && $old['name'] !== $name ? Members::renameListEntry($tab, (string)$old['name'], $name) : 0;
            Audit::log('edit', 'coop', $table, $editId, $name, ['page_key' => $pageKey, 'before' => $old, 'after' => $d] + ($moved ? ['reason' => 'Renamed on ' . $moved . ' record(s)'] : []));
        } else {
            $editId = DB::insert($table, $d);
            Audit::log('create', 'coop', $table, $editId, $name, ['page_key' => $pageKey, 'snapshot' => $d]);
        }
    });
    flash('success', 'Saved.');
    redirect(url($pageKey, ['tab' => $tab]));
});

switch ($tab) {
    case 'types':
        $rows = DB::all('SELECT t.*, (SELECT COUNT(*) FROM members m WHERE m.member_type_id = t.id) AS used FROM member_types t ORDER BY t.sort_order, t.name');
        $edit = $editId ? DB::row('SELECT * FROM member_types WHERE id = :id', ['id' => $editId]) : null;
        break;
    case 'clusters':
        $rows = DB::all('SELECT c.*, (SELECT COUNT(*) FROM members m WHERE m.cluster_id = c.id) AS used FROM member_clusters c ORDER BY c.name');
        $edit = $editId ? DB::row('SELECT * FROM member_clusters WHERE id = :id', ['id' => $editId]) : null;
        break;
    case 'segments':
        $rows = DB::all('SELECT s.*, (SELECT COUNT(*) FROM members m WHERE m.segment_id = s.id) AS used FROM member_segments s ORDER BY s.name');
        $edit = $editId ? DB::row('SELECT * FROM member_segments WHERE id = :id', ['id' => $editId]) : null;
        break;
    default:
        $rows = DB::all('SELECT l.* FROM coop_lists l WHERE l.list_key = :k ORDER BY l.sort_order, l.name', ['k' => $tab]);
        $counts = Members::listCounts($tab);
        foreach ($rows as &$r) {
            $r['used'] = $counts[mb_strtolower((string)$r['name'])] ?? 0;
        }
        unset($r);
        $edit = $editId ? DB::row('SELECT * FROM coop_lists WHERE id = :id AND list_key = :k', ['id' => $editId, 'k' => $tab]) : null;
}
$isList = isset($listKeys[$tab]);
$canDelete = $isList && Access::can($pageKey, 'delete');
$usedLabel = $tab === 'address_subgroup' ? 'Members / water accounts' : 'Members';
$single = ['types' => 'member type', 'clusters' => 'cluster', 'segments' => 'segmentation'] + Members::LIST_SINGULAR;

coop_layout('Member Types, Clusters & Other Lists');
echo coop_tabs($tabs, $tab, $pageKey);
?>
<?php if ($grouping): ?>
<p class="small text-muted mb-2"><?= h(Members::GROUPING_HELP[$tab]) ?></p>
<?php endif; ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>
    <?php if ($tab === 'types'): ?><th>Code</th><th>Member type</th><th>Kind</th><th>Regular</th><th class="num">Min. share capital</th><th class="num">Members</th>
    <?php elseif ($tab === 'clusters'): ?><th>Cluster</th><th>Branch</th><th class="num">Members</th>
    <?php elseif ($tab === 'segments'): ?><th>Segmentation</th><th class="num">Members</th>
    <?php else: ?><th>Name</th><th>Note</th><th class="num">Order</th><th class="num"><?= h($usedLabel) ?></th><?php endif; ?><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr class="<?= (int)$r['is_active'] ? '' : 'text-muted' ?>">
      <?php if ($tab === 'types'): ?><td><?= h($r['code']) ?></td><td><?= h($r['name']) ?></td><td><?= h(Members::KINDS[$r['member_kind']] ?? $r['member_kind']) ?></td><td><?= (int)$r['is_regular'] ? 'Yes' : 'No' ?></td><td class="num"><?= cm($r['min_share_capital'], true) ?></td><td class="num"><?= (int)$r['used'] ?></td>
      <?php elseif ($tab === 'clusters'): ?><td><?= h($r['name']) ?></td><td><?= h(Coop::branchName($r['branch_id'] ? (int)$r['branch_id'] : null)) ?></td><td class="num"><?= (int)$r['used'] ?></td>
      <?php elseif ($tab === 'segments'): ?><td><?= h($r['name']) ?></td><td class="num"><?= (int)$r['used'] ?></td>
      <?php else: ?><td><?= h($r['name']) ?><?= (int)$r['is_active'] ? '' : ' <span class="small">(not active)</span>' ?></td><td class="small"><?= h((string)$r['extra']) ?></td><td class="num"><?= (int)$r['sort_order'] ?></td><td class="num"><?= (int)$r['used'] ?></td><?php endif; ?>
      <td class="text-end text-nowrap">
        <?php if (Access::can($pageKey, 'edit')): ?><a href="<?= h(url($pageKey, ['tab' => $tab, 'id' => $r['id']])) ?>">Edit</a><?php endif; ?>
        <?php if ($canDelete): ?>
          <form method="post" class="d-inline ms-2"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-link btn-sm p-0 text-danger align-baseline" data-confirm="Delete &quot;<?= h($r['name']) ?>&quot; from <?= h(mb_strtolower($tabs[$tab])) ?>?<?= (int)$r['used'] ? ' It is used by ' . (int)$r['used'] . ' record(s), so it will be refused; switch it off instead.' : '' ?>" data-confirm-title="Delete <?= h($single[$tab] ?? 'entry') ?>">Delete</button></form>
        <?php endif; ?>
      </td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-muted">Nothing on this list yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <p class="small text-muted mt-2"><?= $isList ? 'An entry that is in use cannot be deleted: move its members to another entry, or switch it off (not active) so it is no longer offered and old records keep their names. Renaming an entry renames it on every member that has it.' : 'An entry in use is switched off (not active) rather than deleted, so old records keep their names.' ?></p></div>
  <div class="col-lg-5"><?php if (Access::can($pageKey, $edit ? 'edit' : 'create')): ?>
    <form method="post" class="card"><div class="card-header"><?= $edit ? 'Edit' : 'New' ?> <?= h($single[$tab] ?? 'entry') ?></div><div class="card-body row g-2">
      <?= csrf_field() ?>
      <?php if ($tab === 'types'): ?>
        <?= input_field('code', 'Code', fv('code', $edit), ['class' => 'col-4', 'required' => true]) ?>
        <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-8', 'required' => true]) ?>
        <?= select_field('member_kind', 'Kind', Members::KINDS, fv('member_kind', $edit, 'individual'), ['class' => 'col-6']) ?>
        <?= coop_money('min_share_capital', 'Minimum share capital to activate', fv('min_share_capital', $edit, '0'), ['class' => 'col-6']) ?>
        <?= check_field('is_regular', 'Regular member (votes, may be elected)', fv('is_regular', $edit, 1), 'col-12') ?>
        <?= input_field('sort_order', 'Order', fv('sort_order', $edit, '0'), ['class' => 'col-4', 'type' => 'number', 'step' => '1']) ?>
      <?php elseif ($tab === 'clusters'): ?>
        <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-12', 'required' => true]) ?>
        <?= select_field('branch_id', 'Branch', Coop::branchOptions(false), fv('branch_id', $edit), ['class' => 'col-12', 'blank' => 'Any branch']) ?>
      <?php elseif ($tab === 'segments'): ?>
        <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-12', 'required' => true]) ?>
      <?php else: ?>
        <?= input_field('name', 'Name', fv('name', $edit), ['class' => 'col-12', 'required' => true, 'extra' => 'maxlength="' . (Members::GROUPING_LISTS[$tab] ?? 80) . '"']) ?>
        <?= input_field('extra', $grouping ? 'Description' : 'Note', fv('extra', $edit), ['class' => 'col-8']) ?>
        <?= input_field('sort_order', 'Order', fv('sort_order', $edit, '0'), ['class' => 'col-4', 'type' => 'number', 'step' => '1']) ?>
      <?php endif; ?>
      <?= check_field('is_active', 'Active (offered on the forms)', fv('is_active', $edit, 1), 'col-12') ?>
      <div class="col-12"><?= save_buttons('Save', $edit ? url($pageKey, ['tab' => $tab]) : null, false) ?></div>
    </div></form>
  <?php endif; ?></div>
</div>
<?php
layout_end();
