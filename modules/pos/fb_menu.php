<?php
declare(strict_types=1);

/**
 * Point of Sale > FB POS Menu & Groups (v35): the menu groups of the FB POS (named and ordered by the user: Burgers,
 * Fries & Sides, Drinks...) and the items sold on it: whether each is shown, its group and its order. The picture of an
 * item is uploaded on the item itself (Item List > the item > FB POS).
 */

$pageKey = 'pos.fb_menu';
if (!FbPos::ready()) {
    layout_start('FB POS Menu & Groups');
    echo '<div class="alert alert-warning">Run database/migrations/2027_05_09_accounting_update_v40.sql first.</div>';
    layout_end();
    return;
}

$editGroup = gint('group') ? DB::row('SELECT * FROM fb_pos_groups WHERE id = :id', ['id' => gint('group')]) : null;

handle_post(function () use ($pageKey, $editGroup) {
    $do = p('do');
    if ($do === 'group_save') {
        Access::require($pageKey, $editGroup ? 'edit' : 'create');
        $d = [
            'name' => mb_substr(trim((string)p('name')), 0, 60),
            'sort_order' => (int)p('sort_order', '0'),
            'is_active' => p('is_active', '0') === '1' ? 1 : 0,
        ];
        if ($d['name'] === '') {
            fail('Enter the name of the group.');
        }
        if (DB::value('SELECT id FROM fb_pos_groups WHERE name = :n AND id <> :id', ['n' => $d['name'], 'id' => $editGroup['id'] ?? 0])) {
            fail('Another group already has this name.');
        }
        if ($editGroup) {
            DB::update('fb_pos_groups', $d, 'id = :id', ['id' => $editGroup['id']]);
            Audit::log('edit', 'pos', 'fb_pos_group', (int)$editGroup['id'], $d['name'], ['before' => $editGroup, 'after' => $d, 'page_key' => $pageKey]);
            flash('success', 'Group ' . $d['name'] . ' saved.');
        } else {
            $id = DB::insert('fb_pos_groups', $d);
            Audit::log('create', 'pos', 'fb_pos_group', $id, $d['name'], ['snapshot' => $d, 'page_key' => $pageKey]);
            flash('success', 'Group ' . $d['name'] . ' added.');
        }
        redirect(url($pageKey));
    }
    if ($do === 'group_delete') {
        Access::require($pageKey, 'delete');
        $g = DB::row('SELECT * FROM fb_pos_groups WHERE id = :id', ['id' => pint('id')]);
        if ($g) {
            DB::tx(function () use ($g, $pageKey) {
                // its items stay on the FB POS, under Other
                DB::run('UPDATE items SET fb_group_id = NULL WHERE fb_group_id = :g', ['g' => $g['id']]);
                DB::run('DELETE FROM fb_pos_groups WHERE id = :id', ['id' => $g['id']]);
                Audit::log('delete', 'pos', 'fb_pos_group', (int)$g['id'], $g['name'], ['snapshot' => $g, 'page_key' => $pageKey]);
            });
            flash('success', 'Group ' . $g['name'] . ' deleted. Its items are shown under Other until they are given another group.');
        }
        redirect(url($pageKey));
    }
    if ($do === 'items_save') {
        Access::require($pageKey, 'edit');
        $groups = FbPos::groups();
        $rows = array_map('intval', (array)($_POST['row'] ?? []));
        $changed = 0;
        DB::tx(function () use ($rows, $groups, &$changed, $pageKey) {
            foreach ($rows as $id) {
                $it = DB::row('SELECT id, code, name, fb_pos, fb_group_id, fb_sort FROM items WHERE id = :id', ['id' => $id]);
                if (!$it) {
                    continue;
                }
                $gid = (int)($_POST['grp'][$id] ?? 0);
                $d = [
                    'fb_pos' => !empty($_POST['show'][$id]) ? 1 : 0,
                    'fb_group_id' => isset($groups[$gid]) ? $gid : null,
                    'fb_sort' => (int)($_POST['sort'][$id] ?? 0),
                ];
                if ((int)$it['fb_pos'] !== $d['fb_pos'] || (int)$it['fb_group_id'] !== (int)$d['fb_group_id'] || (int)$it['fb_sort'] !== $d['fb_sort']) {
                    DB::update('items', $d, 'id = :id', ['id' => $id]);
                    Audit::log('edit', 'pos', 'item', $id, $it['code'] . ' ' . $it['name'], [
                        'before' => array_intersect_key($it, $d), 'after' => $d, 'page_key' => $pageKey, 'reason' => 'FB POS menu',
                    ]);
                    $changed++;
                }
            }
        });
        flash('success', $changed ? $changed . ' item' . ($changed === 1 ? '' : 's') . ' changed on the FB POS menu.' : 'Nothing was changed.');
        redirect(url($pageKey, array_filter(['q' => p('q'), 'show' => p('show'), 'cat' => p('cat'), 'gf' => p('gf')])));
    }
    fail('Unknown action.');
});

// ---------------------------------------------------------------------- the items
$q = trim((string)g('q'));
$show = in_array(g('show'), ['on', 'all'], true) ? g('show') : 'on';
$cat = gint('cat');
$gf = g('gf');
$sql = 'SELECT i.id, i.code, i.name, i.item_type, i.sales_price, i.fb_pos, i.fb_group_id, i.fb_sort, i.image_file FROM items i
         WHERE i.is_active = 1 AND i.item_type IN (' . implode(',', array_map(fn($t) => "'" . $t . "'", FbPos::TYPES)) . ')';
$params = [];
if ($show === 'on') {
    $sql .= ' AND i.fb_pos = 1';
}
if ($q !== '') {
    $sql .= ' AND (i.code LIKE :q OR i.name LIKE :q2 OR i.barcode = :q3)';
    $params += ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%', 'q3' => $q];
}
if ($cat) {
    $sql .= ' AND i.category_id = :c';
    $params['c'] = $cat;
}
if ($gf === 'none') {
    $sql .= ' AND i.fb_group_id IS NULL';
} elseif (ctype_digit((string)$gf) && (int)$gf > 0) {
    $sql .= ' AND i.fb_group_id = :g';
    $params['g'] = (int)$gf;
}
$limit = 300;
$items = DB::all($sql . ' ORDER BY i.fb_pos DESC, i.fb_sort, i.name LIMIT ' . ($limit + 1), $params);
$more = count($items) > $limit;
$items = array_slice($items, 0, $limit);
$groups = FbPos::groups();
$counts = array_map('intval', DB::pairs('SELECT fb_group_id, COUNT(*) FROM items WHERE fb_pos = 1 AND is_active = 1 AND fb_group_id IS NOT NULL GROUP BY fb_group_id'));
$onCount = (int)DB::value('SELECT COUNT(*) FROM items WHERE fb_pos = 1 AND is_active = 1');
$canEdit = Access::can($pageKey, 'edit');
$typeNames = ['inventory' => 'Inventory part', 'assembly' => 'Assembly', 'non_inventory' => 'Non-inventory', 'service' => 'Service', 'other_charge' => 'Other charge', 'group' => 'Item group'];

layout_start('FB POS Menu & Groups', ['actions' => page_link('pos.fb', [], 'Open the FB POS', 'btn-success', 'shop')]);
?>
<p class="text-muted small mb-3">The FB POS sells like the POS Register (same terminals, shifts, payment methods, receipts and reports); its screen shows the items ticked here as pictures with their price, under the menu groups below. Upload an item's picture on the item itself (Item List, the FB POS part of the item).</p>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-header fw-semibold">Menu groups</div>
      <div class="table-responsive"><table class="table table-sm mb-0 fbm-table">
        <thead><tr><th>Group</th><th class="num">Order</th><th class="num">Items</th><th>Shown</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($groups as $gid => $g): ?>
          <tr class="<?= $editGroup && (int)$editGroup['id'] === $gid ? 'table-active' : '' ?>"><td class="fw-semibold"><?= h($g['name']) ?></td><td class="num"><?= (int)$g['sort_order'] ?></td>
            <td class="num"><a href="<?= h(url($pageKey, ['gf' => $gid, 'show' => 'on'])) ?>"><?= (int)($counts[$gid] ?? 0) ?></a></td><td><?= (int)$g['is_active'] ? 'Yes' : 'No' ?></td>
            <td class="text-end text-nowrap">
              <?php if ($canEdit): ?><a class="btn btn-sm btn-link p-0 me-2" href="<?= h(url($pageKey, ['group' => $gid])) ?>">Edit</a><?php endif; ?>
              <?php if (Access::can($pageKey, 'delete')): ?><form method="post" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="do" value="group_delete"><input type="hidden" name="id" value="<?= (int)$gid ?>"><button class="btn btn-sm btn-link text-danger p-0" data-confirm="Delete the group <?= h($g['name']) ?>? Its items stay on the FB POS under Other.">Delete</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$groups): ?><tr><td colspan="5" class="text-muted text-center py-3">No group yet. Add the first one below, for example Burgers.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <?php if (Access::can($pageKey, $editGroup ? 'edit' : 'create')): ?>
      <form method="post" class="card-body border-top row g-2"><?= csrf_field() ?><input type="hidden" name="do" value="group_save">
        <div class="col-12 fw-semibold small"><?= $editGroup ? 'Edit ' . h($editGroup['name']) : 'New group' ?></div>
        <?= input_field('name', 'Name', fv('name', $editGroup), ['class' => 'col-8', 'extra' => 'maxlength="60" placeholder="Burgers"']) ?>
        <?= input_field('sort_order', 'Order', fv('sort_order', $editGroup, (string)(count($groups) + 1)), ['type' => 'number', 'class' => 'col-4']) ?>
        <?= check_field('is_active', 'Shown on the FB POS', fv('is_active', $editGroup, 1), 'col-12') ?>
        <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> <?= $editGroup ? 'Save group' : 'Add group' ?></button>
          <?php if ($editGroup): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey)) ?>">Cancel</a><?php endif; ?></div>
      </form>
      <?php endif; ?>
    </div>
    <div class="small text-muted"><?= $onCount ?> item<?= $onCount === 1 ? ' is' : 's are' ?> on the FB POS. The groups are its tabs, in the order given; items without a group are under Other.</div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><form method="get" class="row g-2 align-items-end"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
        <div class="col-12 col-md-4"><label class="form-label small mb-1" for="fbmQ">Find</label><input class="form-control form-control-sm" id="fbmQ" name="q" value="<?= h($q) ?>" placeholder="Code, name or barcode"></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1" for="fbmShow">Show</label><select class="form-select form-select-sm" id="fbmShow" name="show" data-no-combo><?= options(['on' => 'On the FB POS', 'all' => 'All items that can be sold'], $show) ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-1" for="fbmGf">Group</label><select class="form-select form-select-sm" id="fbmGf" name="gf" data-no-combo><?= options(['' => 'Any group', 'none' => 'No group (Other)'] + array_map(fn($g) => $g['name'], $groups), (string)$gf) ?></select></div>
        <div class="col-12 col-md-2"><button class="btn btn-sm btn-secondary w-100"><i class="bi bi-funnel"></i> Show</button></div>
      </form></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="items_save">
        <input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="show" value="<?= h($show) ?>"><input type="hidden" name="gf" value="<?= h((string)$gf) ?>">
        <div class="table-responsive"><table class="table table-sm mb-0 fbm-table fbm-items">
          <thead><tr><th></th><th>Item</th><th class="d-none d-md-table-cell">Type</th><th class="num">Price</th><th class="text-center">On FB POS</th><th>Group</th><th class="num">Order</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): $id = (int)$it['id']; $img = FbPos::imageUrl($it); ?>
            <tr><td class="fbm-c-pic"><input type="hidden" name="row[]" value="<?= $id ?>"><?= $img ? '<img class="fbm-thumb" src="' . h($img) . '" alt="">' : '<span class="fbm-thumb">' . h(FbPos::initials((string)$it['name'])) . '</span>' ?></td>
              <td class="fbm-c-name"><a href="<?= h(url('inv.items', ['a' => 'edit', 'id' => $id])) ?>"><?= h($it['name']) ?></a><div class="small text-muted"><?= h($it['code']) ?></div></td>
              <td class="d-none d-md-table-cell small text-muted"><?= h($typeNames[$it['item_type']] ?? $it['item_type']) ?></td>
              <td class="num fbm-c-price"><?= money($it['sales_price']) ?></td>
              <td class="text-center fbm-c-show"><label class="fbm-show"><input class="form-check-input" type="checkbox" name="show[<?= $id ?>]" value="1" <?= (int)$it['fb_pos'] ? 'checked' : '' ?> aria-label="Show <?= h($it['name']) ?> on the FB POS" <?= $canEdit ? '' : 'disabled' ?>><span class="d-sm-none">Show on the FB POS</span></label></td>
              <td class="fbm-c-grp"><select class="form-select form-select-sm" name="grp[<?= $id ?>]" data-no-combo aria-label="Group of <?= h($it['name']) ?>" <?= $canEdit ? '' : 'disabled' ?>><?= options(FbPos::groupOptions($it['fb_group_id'] ? (int)$it['fb_group_id'] : null), $it['fb_group_id'], 'Other (no group)') ?></select></td>
              <td class="fbm-c-ord"><input class="form-control form-control-sm text-end" style="width:72px;margin-left:auto" type="number" name="sort[<?= $id ?>]" value="<?= (int)$it['fb_sort'] ?>" aria-label="Order of <?= h($it['name']) ?>" <?= $canEdit ? '' : 'disabled' ?>></td></tr>
          <?php endforeach; ?>
          <?php if (!$items): ?><tr><td colspan="7" class="text-muted text-center py-4"><?= $show === 'on' && $q === '' && !$gf ? 'No item is on the FB POS yet. Choose Show: All items that can be sold, tick the ones to sell, and save.' : 'No item matches.' ?></td></tr><?php endif; ?>
          </tbody>
        </table></div>
        <div class="card-body d-flex flex-wrap gap-2 align-items-center border-top">
          <?php if ($canEdit && $items): ?><button class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save the menu</button><?php endif; ?>
          <span class="small text-muted"><?= $more ? 'The first ' . $limit . ' items are listed; narrow the list to see the others.' : count($items) . ' item' . (count($items) === 1 ? '' : 's') . ' listed.' ?> Order: lower numbers come first in their group.</span>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
layout_end();
