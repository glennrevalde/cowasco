<?php
declare(strict_types=1);

/**
 * Party Center: one record for each person or organization, with every role it holds (member, customer, supplier,
 * employee, other name). Roles are given, taken away and restored here without deleting their records or history;
 * a role linked to the wrong party is moved with the link-or-move permission and a reason.
 */
$pageKey = 'party.center';
if (!Party::ready()) {
    layout_start('Party Center');
    echo '<div class="alert alert-warning">The Party Master needs a database update. Import <code>database/migrations/2026_11_15_multipurpose_party.sql</code>, then reload this page.</div>';
    layout_end();
    return;
}
$a = (string)g('a');
$coop = Coop::ready();
$branches = $coop ? Coop::branchOptions(false) : [];

// ------------------------------------------------------------------ new / edit party
if ($a === 'new' || $a === 'edit') {
    $p = null;
    if ($a === 'edit') {
        Access::require($pageKey, 'edit');
        $p = Party::get((int)gint('id'));
        if (!$p || $p['status'] === 'merged') {
            flash('warning', 'Party not found, or merged into another party.');
            redirect(url($pageKey));
        }
    } else {
        Access::require($pageKey, 'create');
    }
    $member = $p ? Party::member((int)$p['id']) : null;
    $canConf = Party::canSeeConfidential();
    $hideConf = $member && !$canConf;
    handle_post(function () use ($p, $pageKey, $hideConf) {
        $f = [];
        foreach (['party_type', 'legal_name', 'display_name', 'last_name', 'first_name', 'middle_name', 'suffix', 'tin', 'birth_date', 'registration_no', 'email', 'mobile', 'phone', 'address', 'notes'] as $k) {
            if (array_key_exists($k, $_POST)) {
                $f[$k] = trim((string)$_POST[$k]);
            }
        }
        if ($hideConf) {
            // the user does not see the member's confidential details, so the form did not carry them
            foreach (Party::CONFIDENTIAL as $k) {
                unset($f[$k]);
            }
        }
        if (array_key_exists('home_branch_id', $_POST)) {
            $f['home_branch_id'] = pint('home_branch_id');
        }
        if ($p && array_key_exists('status', $_POST)) {
            $f['status'] = (string)p('status');
        }
        if (($f['party_type'] ?? 'person') === 'person' && trim((string)($f['legal_name'] ?? '')) === '' && (($f['first_name'] ?? '') !== '' || ($f['last_name'] ?? '') !== '')) {
            $f['legal_name'] = Party::personName($f['first_name'] ?? '', $f['middle_name'] ?? '', $f['last_name'] ?? '', $f['suffix'] ?? '');
        }
        if (!empty($f['email']) && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            fail('The e-mail address is not valid.');
        }
        if (!empty($f['birth_date']) && !valid_date($f['birth_date'])) {
            fail('Enter a valid date of birth.');
        }
        if ($p) {
            Party::update((int)$p['id'], $f, trim((string)p('reason')), $pageKey);
            flash('success', 'Party saved.');
            redirect(url($pageKey, ['id' => $p['id']]));
        }
        if (trim((string)($f['legal_name'] ?? '')) === '') {
            fail('Enter the legal name (or the first and last name of a person).');
        }
        if (p('distinct_party') !== '1') {
            $cands = Party::candidatesFor($f);
            if ($cands) {
                fail(Party::candidatesMessage($cands, 'party') . ' Open it from the list instead.');
            }
        }
        $id = Party::create($f, null, null, $pageKey);
        Party::log($id, 'party', null, 'granted', 'Party created on the Party Center');
        flash('success', 'Party created. Give it its roles below.');
        redirect(url($pageKey, ['id' => $id]));
    });
    layout_start($p ? 'Edit party: ' . $p['display_name'] : 'New party', ['actions' => btn_link(url($pageKey, $p ? ['id' => $p['id']] : []), 'Cancel')]);
    $rec = $p ? ($hideConf ? Party::visible($p) : $p) : ['party_type' => 'person'];
    ?>
    <form method="post" class="card"><div class="card-body"><?= csrf_field() ?>
      <?php if ($member): ?>
        <div class="alert alert-info small py-2">This party is member no. <?= h($member['member_no']) ?>. Its name, date of birth, TIN, mobile number, e-mail, address and branch come from the member record; change them on the Member Information page.</div>
      <?php endif; ?>
      <div class="row g-2">
        <?= select_field('party_type', 'Kind of party', Party::TYPES, fv('party_type', $rec), ['class' => 'col-6 col-md-3', 'extra' => ($member ? 'disabled ' : '') . 'id="partyType"']) ?>
        <?= input_field('legal_name', 'Legal name (printed on documents)', fv('legal_name', $rec), ['class' => 'col-md-5', 'extra' => $member ? 'readonly' : 'maxlength="160"']) ?>
        <?= input_field('display_name', 'Name in lists', fv('display_name', $rec), ['class' => 'col-md-4', 'extra' => $member ? 'readonly' : 'maxlength="160" placeholder="Same as the legal name"']) ?>
      </div>
      <div class="row g-2 mt-1" data-toggle-by="#partyType" data-show-when="person">
        <?= input_field('last_name', 'Last name', fv('last_name', $rec), ['class' => 'col-6 col-md-3', 'extra' => $member ? 'readonly' : '']) ?>
        <?= input_field('first_name', 'First name', fv('first_name', $rec), ['class' => 'col-6 col-md-3', 'extra' => $member ? 'readonly' : '']) ?>
        <?= input_field('middle_name', 'Middle name', fv('middle_name', $rec), ['class' => 'col-8 col-md-3', 'extra' => $member ? 'readonly' : '']) ?>
        <?= input_field('suffix', 'Suffix', fv('suffix', $rec), ['class' => 'col-4 col-md-1', 'extra' => $member ? 'readonly' : '']) ?>
        <?php if (!$hideConf): ?><?= input_field('birth_date', 'Date of birth', fv('birth_date', $rec), ['class' => 'col-md-2', 'type' => 'date', 'extra' => $member ? 'readonly' : '']) ?><?php endif; ?>
      </div>
      <div class="row g-2 mt-1">
        <?php if (!$hideConf): ?>
        <?= input_field('tin', 'TIN', fv('tin', $rec), ['class' => 'col-6 col-md-3', 'extra' => $member ? 'readonly' : 'maxlength="30"']) ?>
        <?= input_field('registration_no', 'SEC / CDA / DTI registration no.', fv('registration_no', $rec), ['class' => 'col-6 col-md-3', 'extra' => 'maxlength="60"']) ?>
        <?= input_field('email', 'E-mail', fv('email', $rec), ['class' => 'col-md-3', 'type' => 'email', 'extra' => $member ? 'readonly' : '']) ?>
        <?= input_field('mobile', 'Mobile', fv('mobile', $rec), ['class' => 'col-6 col-md-3', 'type' => 'tel', 'extra' => $member ? 'readonly' : '']) ?>
        <?= input_field('phone', 'Phone', fv('phone', $rec), ['class' => 'col-6 col-md-3']) ?>
        <?= input_field('address', 'Address', fv('address', $rec), ['class' => 'col-md-6', 'extra' => $member ? 'readonly' : 'maxlength="255"']) ?>
        <?php else: ?>
        <div class="col-12 small text-muted">The member's contact details and TIN are confidential and are not shown to you.</div>
        <?php endif; ?>
        <?php if ($branches): ?><?= select_field('home_branch_id', 'Home branch', $branches, fv('home_branch_id', $rec), ['class' => 'col-md-3', 'blank' => '', 'extra' => $member ? 'disabled' : '']) ?><?php endif; ?>
        <?= input_field('notes', 'Notes', fv('notes', $rec), ['class' => 'col-md-9', 'extra' => 'maxlength="255"']) ?>
        <?php if ($p): ?>
        <?= select_field('status', 'Status', ['active' => 'Active', 'inactive' => 'Inactive (no longer dealt with)'], fv('status', $rec), ['class' => 'col-md-4', 'extra' => 'data-no-combo']) ?>
        <?= input_field('reason', 'Reason for the change (required when the legal name or the status changes)', fv('reason', null), ['class' => 'col-md-8']) ?>
        <?php endif; ?>
        <?php if (!$p && !empty($GLOBALS['FORM_ERROR']) && str_contains((string)$GLOBALS['FORM_ERROR'], 'different person')): ?>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="distinct_party" value="1" id="distinctParty"><label class="form-check-label small" for="distinctParty">This is a different person or organization</label></div></div>
        <?php endif; ?>
      </div>
      <div class="mt-3"><?= save_buttons($p ? 'Save party' : 'Create party', url($pageKey, $p ? ['id' => $p['id']] : []), false) ?></div>
    </div></form>
    <?php
    layout_end();
    return;
}

// ------------------------------------------------------------------ role actions on a party
$selId = gint('id');
handle_post(function () use ($pageKey, $selId) {
    $action = (string)p('action');
    $back = url($pageKey, ['id' => $selId]);
    $reason = trim((string)p('reason'));
    if ($action === 'grant') {
        Access::require($pageKey, 'create');
        $role = (string)p('role');
        if (!in_array($role, ['customer', 'supplier', 'employee', 'other'], true)) {
            fail('Choose the role to give.');
        }
        if ($role === 'customer' && !Access::can('cust.center', 'create')) {
            fail('You are not allowed to add customers.');
        }
        if ($role === 'supplier' && !Access::can('vend.center', 'create')) {
            fail('You are not allowed to add suppliers.');
        }
        if (in_array($role, ['employee', 'other'], true) && !Access::can('coa.names', 'create')) {
            fail('You are not allowed to add employees and other names.');
        }
        $refId = Party::grant((int)$selId, $role, $reason, [
            'terms_id' => pint('terms_id'), 'credit_limit' => pnum('credit_limit'), 'price_level_id' => pint('price_level_id'),
            'allow_pos_charge' => p('allow_pos_charge') === '1', 'position' => p('position'), 'employee_no' => p('employee_no'),
        ], $pageKey);
        $given = array_values(array_filter(Party::roles((int)$selId), fn($r) => $r['role'] === $role && $r['ref_id'] === $refId));
        flash('success', Party::ROLES[$role] . ' role given' . ($given ? ' (' . $given[0]['ref_no'] . ', ' . $given[0]['name'] . ')' : '') . '.');
        redirect($back);
    }
    if ($action === 'revoke' || $action === 'restore') {
        Access::require($pageKey, 'edit');
        $role = (string)p('role');
        if ($role === 'water') {
            fail('A water-service account is opened, disconnected or closed on Water Billing > Water Service Accounts, not here.');
        }
        $action === 'revoke' ? Party::revoke($role, (int)pint('ref_id'), $reason, $pageKey) : Party::restore($role, (int)pint('ref_id'), $reason, $pageKey);
        flash('success', Party::ROLES[$role] . ($action === 'revoke' ? ' role taken away. The record and its documents stay.' : ' role restored.'));
        redirect($back);
    }
    if ($action === 'move') {
        Access::require($pageKey, 'edit');
        if ((string)p('role') === 'water') {
            fail('A water-service account is linked to its party on Water Billing > Water Service Accounts, not here.');
        }
        $to = Party::move((string)p('role'), (int)pint('ref_id'), pint('to_party_id') ?: null, $reason, $pageKey);
        flash('success', 'Role moved.');
        redirect(url($pageKey, ['id' => $to]));
    }
    fail('Unknown action.');
});

// ------------------------------------------------------------------ list
$q = trim((string)g('q'));
$role = array_key_exists(g('role'), Party::ROLES) || in_array(g('role'), ['none', 'multi'], true) ? (string)g('role') : '';
$status = in_array(g('status'), ['active', 'inactive', 'merged', 'all'], true) ? (string)g('status') : 'active';
$branch = gint('branch');
$where = [];
$params = [];
if ($status !== 'all') {
    $where[] = 'p.status = :st';
    $params['st'] = $status;
}
if ($q !== '') {
    $where[] = '(p.party_no LIKE :q1 OR p.legal_name LIKE :q2 OR p.display_name LIKE :q3 OR p.tin LIKE :q4 OR p.mobile LIKE :q5 OR p.email LIKE :q6
                 OR p.id IN (SELECT party_id FROM members WHERE member_no LIKE :q7 OR full_name LIKE :q8)
                 OR p.id IN (SELECT party_id FROM customers WHERE name LIKE :q9) OR p.id IN (SELECT party_id FROM vendors WHERE name LIKE :q10)'
        . (Party::waterReady() ? ' OR p.id IN (SELECT party_id FROM water_accounts WHERE account_number LIKE :q11)' : '') . ')';
    $like = '%' . $q . '%';
    $params += ['q1' => $q . '%', 'q2' => $like, 'q3' => $like, 'q4' => $q . '%', 'q5' => $like, 'q6' => $like, 'q7' => $q . '%', 'q8' => $like, 'q9' => $like, 'q10' => $like];
    if (Party::waterReady()) {
        $params['q11'] = $q . '%';
    }
}
$roleSql = [
    'member' => 'EXISTS (SELECT 1 FROM members m WHERE m.party_id = p.id)',
    'customer' => 'EXISTS (SELECT 1 FROM customers c WHERE c.party_id = p.id AND c.parent_id IS NULL)',
    'supplier' => 'EXISTS (SELECT 1 FROM vendors v WHERE v.party_id = p.id)',
    'employee' => "EXISTS (SELECT 1 FROM other_names o WHERE o.party_id = p.id AND o.member_id IS NULL AND o.name_type = 'employee')",
    'other' => "EXISTS (SELECT 1 FROM other_names o WHERE o.party_id = p.id AND o.member_id IS NULL AND o.name_type <> 'employee')",
];
if (Party::waterReady()) {
    $roleSql['water'] = 'EXISTS (SELECT 1 FROM water_accounts w WHERE w.party_id = p.id)';
}
if (isset($roleSql[$role])) {
    $where[] = $roleSql[$role];
} elseif ($role === 'none') {
    $where[] = 'NOT (' . implode(' OR ', $roleSql) . ')';
} elseif ($role === 'multi') {
    $where[] = '((' . implode(') + (', $roleSql) . ')) >= 2';
}
if ($branch) {
    $where[] = '(p.home_branch_id = :br OR p.id IN (SELECT party_id FROM members WHERE branch_id = :br2))';
    $params += ['br' => $branch, 'br2' => $branch];
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int)DB::value("SELECT COUNT(*) FROM parties p$sqlWhere", $params);
$per = 100;
$pg = max(1, (int)g('pg', '1'));
$list = DB::all("SELECT p.id, p.party_no, p.display_name, p.legal_name, p.party_type, p.status FROM parties p$sqlWhere ORDER BY p.display_name, p.id LIMIT " . (($pg - 1) * $per) . ", $per", $params);
$roleNames = Party::roleNamesAll(array_column($list, 'id'));
$selId = $selId ?? ($list[0]['id'] ?? null);
$sel = $selId ? Party::get((int)$selId) : null;

$actions = page_link($pageKey, ['a' => 'new'], 'New party', 'btn-primary', 'person-plus', 'create');
if (Access::page('party.review')) {
    $open = (int)DB::value("SELECT COUNT(*) FROM party_match_reviews WHERE status = 'open'");
    $actions .= ' ' . btn_link(url('party.review'), 'Identity Review' . ($open ? ' (' . $open . ')' : ''), 'btn-outline-secondary', 'people');
}
layout_start('Party Center', ['actions' => $actions]);
$filterRoles = ['' => 'Every role'] + Party::ROLES + ['multi' => 'More than one role', 'none' => 'No role'];
?>
<div class="row g-3 party-center<?= $sel ? ' has-pick' : '' ?>">
  <div class="col-lg-4 order-2 order-lg-1 center-pick">
    <div class="card">
      <div class="card-body pb-2">
        <form class="row g-1 align-items-center" method="get">
          <input type="hidden" name="p" value="<?= h($pageKey) ?>">
          <div class="col-12 live-search"><i class="bi bi-search"></i>
            <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Name, party no., member no., TIN, mobile" autocomplete="off"
                   data-live-filter="#partyList .list-group-item" data-live-count="#partyCount" data-live-empty="#partyNone" aria-label="Find a party"></div>
          <div class="col-6"><select class="form-select form-select-sm" name="role" data-autosubmit data-no-combo aria-label="Role"><?= options(array_slice($filterRoles, 1, null, true), $role, 'Every role') ?></select></div>
          <div class="col-6"><select class="form-select form-select-sm" name="status" data-autosubmit aria-label="Status"><?= options(['active' => 'Active', 'inactive' => 'Inactive', 'merged' => 'Merged', 'all' => 'All'], $status) ?></select></div>
          <?php if ($branches): ?><div class="col-12"><select class="form-select form-select-sm" name="branch" data-autosubmit aria-label="Branch"><?= options($branches, $branch, 'Every branch') ?></select></div><?php endif; ?>
        </form>
        <div class="d-flex justify-content-between mt-1"><span class="live-count"><span id="partyCount"><?= count($list) ?></span> of <?= number_format($total) ?> part<?= $total === 1 ? 'y' : 'ies' ?></span>
          <?php if ($total > $per): ?><span class="live-count">
            <?php if ($pg > 1): ?><a href="<?= h(current_url(['pg' => $pg - 1])) ?>">Previous</a><?php endif; ?>
            <?php if ($pg * $per < $total): ?> <a href="<?= h(current_url(['pg' => $pg + 1])) ?>">Next</a><?php endif; ?></span><?php endif; ?>
        </div>
      </div>
      <div class="list-group list-group-flush center-list" id="partyList">
        <?php foreach ($list as $r): ?>
          <a class="list-group-item list-group-item-action <?= (int)$r['id'] === (int)$selId ? 'active' : '' ?>" href="<?= h(current_url(['id' => $r['id'], 'a' => null])) ?>"
             data-filter-text="<?= h($r['party_no'] . ' ' . $r['display_name'] . ' ' . $r['legal_name'] . ' ' . implode(' ', $roleNames[(int)$r['id']] ?? [])) ?>">
            <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= h($r['display_name']) ?></span><span class="small opacity-75"><?= h($r['party_no']) ?></span></div>
            <div class="small opacity-75"><?= h(implode(', ', $roleNames[(int)$r['id']] ?? ['No role'])) ?><?= $r['status'] !== 'active' ? ', ' . h(Party::STATUSES[$r['status']] ?? $r['status']) : '' ?></div>
          </a>
        <?php endforeach; ?>
        <div class="list-group-item text-muted small" id="partyNone">No party matches. Press Enter to search the whole list.</div>
      </div>
    </div>
  </div>
  <div class="col-lg-8 order-1 order-lg-2">
  <?php if (!$sel): ?>
    <div class="card"><div class="card-body text-muted">No party chosen. Find one on the left, or create one.</div></div>
  <?php else:
      $pid = (int)$sel['id'];
      $view = Party::visible($sel);
      $roles = Party::roles($pid);
      $member = Party::member($pid);
      $merged = $sel['status'] === 'merged' ? Party::live($pid) : null;
      $canEdit = Access::can($pageKey, 'edit') && $sel['status'] !== 'merged';
      $canCreate = Access::can($pageKey, 'create') && in_array($sel['status'], ['active', 'inactive'], true);   // a role given to an inactive party makes it active again
      $canMove = $canEdit && Access::special('party_merge');
      $st = Access::page('party.statement') ? PartyStatement::build($pid, '1900-01-01', today()) : null;
  ?>
    <div class="card mb-3"><div class="card-body">
      <?php if ($merged): ?><div class="alert alert-warning small py-2">This party was merged into <a href="<?= h(url($pageKey, ['id' => $merged['id']])) ?>"><?= h($merged['display_name'] . ' (' . $merged['party_no'] . ')') ?></a>. Its records and documents are there now.</div><?php endif; ?>
      <div class="d-flex flex-wrap justify-content-between gap-3">
        <div class="min-w-0">
          <h2 class="h5 mb-0"><?= h($sel['display_name']) ?></h2>
          <div class="small text-muted"><?= h($sel['party_no']) ?>, <?= h(strtolower(Party::TYPES[$sel['party_type']] ?? $sel['party_type'])) ?><?= $sel['status'] !== 'active' ? ', ' . h(strtolower(Party::STATUSES[$sel['status']] ?? $sel['status'])) : '' ?></div>
          <div class="small mt-1">Printed on documents as <strong><?= h($sel['legal_name']) ?></strong><?= $member ? ', with member no. ' . h($member['member_no']) : '' ?></div>
          <dl class="kv party-facts small mt-2 mb-0">
            <?php foreach (['tin' => 'TIN', 'birth_date' => 'Date of birth', 'registration_no' => 'Registration no.', 'mobile' => 'Mobile', 'phone' => 'Phone', 'email' => 'E-mail', 'address' => 'Address'] as $k => $lbl): ?>
              <?php if (($view[$k] ?? '') !== '' && $view[$k] !== null): ?><div><dt><?= h($lbl) ?></dt><dd><?= h($k === 'birth_date' && !$view['_masked'] ? fdate($view[$k]) : $view[$k]) ?></dd></div><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($sel['home_branch_id'] && $coop): ?><div><dt>Home branch</dt><dd><?= h(Coop::branchName((int)$sel['home_branch_id'])) ?></dd></div><?php endif; ?>
          </dl>
          <?php if ($view['_masked']): ?><div class="small text-muted mt-1">Confidential member details are partly hidden.</div><?php endif; ?>
        </div>
      </div>
      <?php if ($st): ?>
      <div class="coop-tiles party-position mt-3 mb-0">
        <div class="coop-tile is-warn"><div class="t-label">Party owes the cooperative</div><div class="t-value"><?= Dec::money($st['totals']['owes']) ?></div></div>
        <div class="coop-tile"><div class="t-label">Cooperative owes the party</div><div class="t-value"><?= Dec::money($st['totals']['owed']) ?></div></div>
        <?php if ($member): ?><div class="coop-tile is-ok"><div class="t-label">Member equity interest</div><div class="t-value"><?= Dec::money($st['totals']['equity']) ?></div></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-1 mt-3">
        <?= $canEdit ? page_link($pageKey, ['a' => 'edit', 'id' => $pid], 'Edit party', 'btn-outline-primary', 'pencil', 'edit') : '' ?>
        <?= page_link('party.statement', ['party' => $pid], 'Consolidated statement', 'btn-outline-secondary', 'file-earmark-text') ?>
        <?php if ($canCreate): ?>
          <?php if (!$member && Access::can('coop.member', 'create')): ?><?= btn_link(url('coop.member', ['party' => $pid]), 'Enroll as member', 'btn-outline-success', 'person-plus') ?><?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#grantRole"><i class="bi bi-plus-lg"></i> Give a role</button>
        <?php endif; ?>
      </div>
    </div></div>

    <div class="card mb-3">
      <div class="card-header fw-semibold">Roles</div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle stack-sm">
        <thead><tr><th>Role</th><th>Number</th><th>Record</th><th>Status</th><th>Details</th><th></th></tr></thead>
        <tbody>
        <?php if (!$roles): ?><tr><td colspan="6" class="text-muted text-center py-3">No role yet. Give it one with "Give a role".</td></tr><?php endif; ?>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td data-label="Role" class="fw-semibold"><?= h($r['label']) ?></td>
            <td data-label="Number"><?= h($r['ref_no']) ?></td>
            <td data-label="Record"><?= Access::page($r['page']) ? '<a href="' . h(url($r['page'], $r['params'])) . '">' . h($r['name']) . '</a>' : h($r['name']) ?></td>
            <td data-label="Status" class="<?= $r['active'] ? 'text-success' : 'text-muted' ?>"><?= h($r['status']) ?><?= $r['branch'] ? '<div class="small text-muted">' . h($r['branch']) . '</div>' : '' ?></td>
            <td data-label="Details" class="small"><?= h($r['detail']) ?></td>
            <td class="text-end text-nowrap">
              <?php if ($r['role'] === 'water'): ?>
                <?php if (Access::page('water.member_list')): ?><a class="small" href="<?= h(url('water.member_list', $r['params'])) ?>">Water Service Accounts</a><?php else: ?><span class="small text-muted">Managed on Water Billing</span><?php endif; ?>
              <?php elseif ($canEdit && $r['role'] !== 'member'): ?>
                <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="modal" data-bs-target="#roleAct" data-role="<?= h($r['role']) ?>" data-ref="<?= (int)$r['ref_id'] ?>"
                        data-act="<?= $r['active'] ? 'revoke' : 'restore' ?>" data-what="<?= h(($r['active'] ? 'Take away the ' : 'Restore the ') . strtolower($r['label']) . ' role: ' . $r['name']) ?>"><?= $r['active'] ? 'Take away' : 'Restore' ?></button>
                <?php if ($canMove): ?>
                  <button type="button" class="btn btn-link btn-sm p-0 ms-2" data-bs-toggle="modal" data-bs-target="#roleMove" data-role="<?= h($r['role']) ?>" data-ref="<?= (int)$r['ref_id'] ?>"
                          data-what="<?= h('Move the ' . strtolower($r['label']) . ' record ' . $r['name']) ?>">Move</button>
                <?php endif; ?>
              <?php elseif ($r['role'] === 'member' && Access::page('coop.member')): ?>
                <a class="small" href="<?= h(url('coop.member', ['id' => $r['ref_id'], 'tab' => 'history'])) ?>">Membership status</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <?php $waterAccts = Party::waterReady() ? WaterAccounts::ofParty($pid) : []; if ($waterAccts): ?>
    <div class="card mb-3 party-water">
      <div class="card-header d-flex flex-wrap justify-content-between gap-2"><span class="fw-semibold">Water service</span>
        <span class="small text-muted"><?= count($waterAccts) ?> water-service account<?= count($waterAccts) === 1 ? '' : 's' ?>, balances from the water records and from the ledger</span></div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle stack-sm">
        <thead><tr><th>Account no.</th><th>Name on the account</th><th>Rate code</th><th>Zone</th><th>Connection</th><th class="num">Water bills</th><th class="num">Other bills</th><th class="num">Open balance</th><th class="num">In the ledger</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($waterAccts as $w):
            $wid = (int)$w['pkey'];
            $ob = WaterAccounts::openBalance($wid);
            $lb = WaterAccounts::ledgerBalance($wid);
            $other = r2($ob['one_time'] + $ob['installment'] + $ob['recurring']);
            $otherParts = array_filter(['one-time ' . money($ob['one_time']) => $ob['one_time'] > 0.004, 'installment ' . money($ob['installment']) => $ob['installment'] > 0.004, 'recurring ' . money($ob['recurring']) => $ob['recurring'] > 0.004]);
            $wActive = strtoupper((string)$w['status']) === 'A';
            $links = [];
            if (Access::page('water.member_list')) {
                $links[] = '<a href="' . h(url('water.member_list', ['search' => (string)($w['account_number'] ?: $wid)])) . '">Account</a>';
            }
            if (Access::page('water.customer_ledger')) {
                $links[] = '<a href="' . h(url('water.customer_ledger', ['member_id' => $wid])) . '">Water ledger</a>';
            }
            if (Access::page('water.water_bills_collection') && $wActive) {
                $links[] = '<a href="' . h(url('water.water_bills_collection', ['member_id' => $wid])) . '">Collect</a>';
            }
            if (Access::page('rpt.water_account_statement')) {
                $links[] = '<a href="' . h(url('rpt.water_account_statement', ['q' => (string)($w['account_number'] ?: $wid), 'dates' => 'all'])) . '">Statement</a>';
            }
        ?>
          <tr class="<?= $wActive ? '' : 'text-muted' ?>">
            <td data-label="Account no." class="fw-semibold"><?= h((string)($w['account_number'] ?: 'W-' . $wid)) ?><?= $wActive ? '' : '<div class="small fw-normal">inactive</div>' ?></td>
            <td data-label="Name"><?= h(WaterAccounts::listName($w)) ?></td>
            <td data-label="Rate code" class="small"><?= h((string)($w['rc_name'] ?: $w['rc_code'] ?: '')) ?></td>
            <td data-label="Zone"><?= h((string)($w['zone'] ?? '')) ?></td>
            <td data-label="Connection" class="<?= $w['connection_status'] === 'active' ? 'text-success' : 'text-warning' ?>"><?= h(ucfirst((string)$w['connection_status'])) ?></td>
            <td data-label="Water bills" class="num"><?= money($ob['water']) ?></td>
            <td data-label="Other bills" class="num"><?= money($other) ?><?= $otherParts ? '<div class="small text-muted">' . h(implode(', ', array_keys($otherParts))) . '</div>' : '' ?></td>
            <td data-label="Open balance" class="num fw-semibold"><?= money($ob['total']) ?></td>
            <td data-label="In the ledger" class="num"><?= money($lb) ?><?= abs($lb - $ob['total']) > 0.004 ? '<div class="small text-danger">differs by ' . money($ob['total'] - $lb) . '</div>' : '' ?></td>
            <td class="text-end text-nowrap small"><?= implode(' <span class="text-muted">|</span> ', $links) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="card-body py-2 small text-muted">The open balance is what the water records show still due on the account's bills; "In the ledger" is the open balance of the invoices those bills posted to the party's customer record. The two agree unless a bill or collection reached one side only (Water Posting Log &amp; Exceptions).</div>
    </div>
    <?php endif; ?>

    <?php
    $docs = DB::all('SELECT t.id, t.txn_type, t.txn_no, t.txn_date, t.total, t.cp_role, t.cp_name, t.cp_reissue_no, t.is_voided, t.status, bu.code AS bu_code
                       FROM transactions t LEFT JOIN business_units bu ON bu.id = t.business_unit_id
                      WHERE t.party_id = :p ORDER BY t.txn_date DESC, t.id DESC LIMIT 25', ['p' => $pid]);
    $docCount = (int)DB::value('SELECT COUNT(*) FROM transactions WHERE party_id = :p', ['p' => $pid]);
    ?>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between"><span class="fw-semibold">Documents</span><span class="small text-muted">latest <?= min(25, $docCount) ?> of <?= number_format($docCount) ?></span></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0 stack-sm">
        <thead><tr><th>Date</th><th>Document</th><th>As</th><th>Name printed</th><th>Unit</th><th class="num">Amount</th></tr></thead>
        <tbody>
        <?php if (!$docs): ?><tr><td colspan="6" class="text-muted text-center py-3">No documents yet.</td></tr><?php endif; ?>
        <?php foreach ($docs as $t): ?>
          <tr class="<?= (int)$t['is_voided'] ? 'text-muted' : '' ?>">
            <td data-label="Date"><?= h(fdate($t['txn_date'])) ?></td>
            <td data-label="Document"><?= h(Txn::label($t['txn_type'])) ?> <?= Txn::link($t) ?><?= (int)$t['is_voided'] ? ' (void)' : '' ?></td>
            <td data-label="As" class="text-nowrap"><?= h(Party::roleLabel($t['cp_role'], $t['txn_type'])) ?></td>
            <td data-label="Name printed"><?= h((string)$t['cp_name']) ?><?= (int)$t['cp_reissue_no'] > 0 ? ' <span class="small text-muted">(reissued ' . (int)$t['cp_reissue_no'] . ')</span>' : '' ?></td>
            <td data-label="Unit"><?= h((string)$t['bu_code']) ?></td>
            <td data-label="Amount" class="num"><?= money($t['total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <?php
    $log = DB::all('SELECT l.*, u.username, op.party_no AS other_no FROM party_role_log l LEFT JOIN users u ON u.id = l.created_by LEFT JOIN parties op ON op.id = l.other_party_id
                     WHERE l.party_id = :p ORDER BY l.created_at DESC, l.id DESC LIMIT 30', ['p' => $pid]);
    $cands = $sel['status'] === 'active' ? Party::candidatesFor($sel, $pid) : [];
    ?>
    <div class="row g-3">
      <div class="col-xl-7"><div class="card h-100">
        <div class="card-header fw-semibold">Role history</div>
        <ul class="list-group list-group-flush small">
          <?php if (!$log): ?><li class="list-group-item text-muted">Nothing recorded yet.</li><?php endif; ?>
          <?php foreach ($log as $l): ?>
            <li class="list-group-item"><span class="fw-semibold"><?= h(Party::ACTIONS[$l['action']] ?? $l['action']) ?></span>
              <?= h($l['role'] === 'party' ? 'party' : strtolower(Party::ROLES[$l['role']] ?? $l['role'])) ?><?= $l['other_no'] ? ', with ' . h($l['other_no']) : '' ?>
              <div class="text-muted"><?= h(fdatetime($l['created_at'])) ?><?= $l['username'] ? ', ' . h($l['username']) : '' ?><?= $l['reason'] ? '. ' . h($l['reason']) : '' ?></div></li>
          <?php endforeach; ?>
        </ul>
      </div></div>
      <div class="col-xl-5"><div class="card h-100">
        <div class="card-header fw-semibold">May be the same as</div>
        <ul class="list-group list-group-flush small">
          <?php if (!$cands): ?><li class="list-group-item text-muted">No other party with the same TIN, e-mail, mobile or name.</li><?php endif; ?>
          <?php foreach (array_slice($cands, 0, 6, true) as $cid => $c): ?>
            <li class="list-group-item"><a href="<?= h(url($pageKey, ['id' => $cid])) ?>"><?= h($c['party']['display_name']) ?></a> <span class="text-muted"><?= h($c['party']['party_no']) ?></span>
              <div class="text-muted"><?= h(implode(', ', $c['basis'])) ?><?= $c['roles'] ? '. ' . h(implode(', ', $c['roles'])) : '' ?></div></li>
          <?php endforeach; ?>
          <?php if ($cands && Access::page('party.review')): ?><li class="list-group-item"><form method="post" action="<?= h(url('party.review')) ?>" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="scan_party"><input type="hidden" name="party_id" value="<?= (int)$pid ?>"><button class="btn btn-link btn-sm p-0">Review and link on Identity Review</button></form></li><?php endif; ?>
        </ul>
      </div></div>
    </div>

    <?php if ($canCreate): ?>
    <div class="modal fade" id="grantRole" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['id' => $pid])) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="grant">
      <div class="modal-header"><h5 class="modal-title">Give <?= h($sel['display_name']) ?> a role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <?= select_field('role', 'Role', ['customer' => 'Customer (buys from the cooperative)', 'supplier' => 'Supplier (sells or delivers to the cooperative)', 'employee' => 'Employee', 'other' => 'Other name (payee)'], 'customer', ['class' => 'col-12', 'extra' => 'id="grantRoleSel" data-no-combo']) ?>
        <div class="col-12 row g-2 m-0 p-0" data-toggle-by="#grantRoleSel" data-show-when="customer">
          <?= select_field('terms_id', 'Terms', Lookup::terms(), '', ['class' => 'col-6', 'blank' => '']) ?>
          <?= input_field('credit_limit', 'Credit limit (0 = none)', '0', ['class' => 'col-6', 'type' => 'number']) ?>
          <?= select_field('price_level_id', 'Price level', Lookup::priceLevels(), '', ['class' => 'col-12', 'blank' => $member ? 'Member prices of the line of business (follows the membership)' : 'Nonmember prices of the line of business']) ?>
          <?= check_field('allow_pos_charge', 'Can charge purchases at the POS', 0, 'col-12') ?>
        </div>
        <div class="col-12 row g-2 m-0 p-0" data-toggle-by="#grantRoleSel" data-show-when="employee,other">
          <?= input_field('employee_no', 'Employee no.', '', ['class' => 'col-5']) ?>
          <?= input_field('position', 'Position / relationship', '', ['class' => 'col-7']) ?>
        </div>
        <div class="col-12"><label class="form-label">Reason or reference (recorded with the role)</label><input class="form-control form-control-sm" name="reason" maxlength="255" placeholder="e.g. Member buying on credit at the store"></div>
        <div class="col-12 small text-muted">The record is made from the party's name and details; its own number, terms and history start now. A role the party had before is restored instead.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Give the role</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <div class="modal fade" id="roleAct" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['id' => $pid])) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value=""><input type="hidden" name="role" value=""><input type="hidden" name="ref_id" value="">
      <div class="modal-header"><h5 class="modal-title" data-what>Role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Reason (required, recorded in the role history and the audit trail)</label><textarea class="form-control" name="reason" rows="2" required></textarea>
        <div class="small text-muted mt-2">Taking a role away makes its record inactive. Its number, open balance, documents and history stay.</div></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Confirm</button></div>
    </form></div></div>
    <?php endif; ?>
    <?php if ($canMove): ?>
    <div class="modal fade" id="roleMove" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" action="<?= h(url($pageKey, ['id' => $pid])) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="role" value=""><input type="hidden" name="ref_id" value="">
      <div class="modal-header"><h5 class="modal-title" data-what>Move role</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-2">
        <?= select_field('to_party_id', 'To party', array_diff_key(Party::options(), [$pid => 1]), '', ['class' => 'col-12', 'blank' => 'A new party made from the record', 'extra' => 'data-combo']) ?>
        <div class="col-12"><label class="form-label">Reason (required)</label><textarea class="form-control" name="reason" rows="2" required placeholder="What shows the record belongs to the other party"></textarea></div>
        <div class="col-12 small text-muted">For a record linked to the wrong party. Its documents follow it; the names printed on them stay as issued.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Move</button></div>
    </form></div></div>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
<script>
document.querySelectorAll('#roleAct, #roleMove').forEach((m) => m.addEventListener('show.bs.modal', (e) => {
  const b = e.relatedTarget; if (!b) return;
  const f = m.querySelector('form');
  if (f.elements.action && b.dataset.act) f.elements.action.value = b.dataset.act;
  f.elements.role.value = b.dataset.role; f.elements.ref_id.value = b.dataset.ref;
  m.querySelector('[data-what]').textContent = b.dataset.what;
}));
</script>
<?php
layout_end();
