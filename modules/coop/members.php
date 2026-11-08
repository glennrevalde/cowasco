<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.members';

// ------------------------------------------------------------------ Import Member: a CSV file of members, reviewed, then uploaded
if (g('a') === 'import') {
    Access::require('coop.member', 'create');
    $canPost = Access::can('coop.member', 'approve');
    $cols = CsvImports::cols('members');
    $cfg = [
        'cols' => $cols, 'aliases' => CsvImports::aliases('members'), 'noun' => ['member', 'members'], 'template_name' => 'import_members_template.csv', 'samples' => CsvImports::samples('members'),
        'opt' => fn() => [], 'check' => fn(array $rows, array $opt) => CsvImports::check('members', $rows, $opt),
        'post' => function (array $rows, array $opt, string $file) use ($pageKey) {
            $r = CsvImports::post('members', $rows, $opt, $file, $pageKey);
            return $r['count'] . ' member(s) imported and Active' . ($r['refs'] ? ': member no. ' . implode(', ', array_slice($r['refs'], 0, 8)) . (count($r['refs']) > 8 ? ' ...' : '') : '') . '.';
        },
        'can_post' => $canPost, 'no_post' => 'You may read and check the file; uploading it (members created Active) needs the Approve right on Member Information.',
        'done' => url($pageKey, ['status' => 'active']), 'confirm' => 'Upload the members? Each one is created as an Active member.',
        'intro' => 'Individual members of the former system: each row becomes a member with the status <strong>Active</strong>. Import the members first, then their savings and time deposits. '
            . 'The <strong>full template</strong> has a column for every other detail of the member (addresses, work, grouping, spouse, beneficiaries ...): keep only the columns you have.',
        'help' => 'Dates as YYYY-MM-DD or MM/DD/YYYY. A member no. or a name and date of birth already on file is refused.',
    ];
    $state = CsvReview::handle($cfg);
    coop_layout('Import members', ['actions' => (Access::can('coop.consolidated_import', 'create') ? btn_link(url('coop.consolidated_import'), 'Consolidated Import', 'btn-outline-primary', 'files') . ' ' : '') . btn_link(url($pageKey), 'Member Center', 'btn-outline-secondary', 'arrow-left'), 'js' => ['js/csv_review.js']]);
    echo CsvReview::render($cfg, $state);
    layout_end();
    exit;
}

$q = trim((string)g('q'));
$status = array_key_exists(g('status'), Coop::MEMBER_STATUSES) ? (string)g('status') : '';
$type = gint('type');
$branch = gint('branch');
$cluster = gint('cluster');
$flag = in_array(g('flag'), ['hold', 'alert', 'no_capital', 'underpaid'], true) ? (string)g('flag') : '';
// account class, account group, address sub-group or savings group: "account_group:Market vendors"
$grp = (string)g('grp');
$grpKey = '';
$grpName = '';
if (preg_match('/^(\w+):(.+)$/u', $grp, $gm) && isset(Members::GROUPING_LISTS[$gm[1]])) {
    [$grpKey, $grpName] = [$gm[1], $gm[2]];
}
$grpOptions = [];
foreach (Members::GROUPING_LISTS as $gk => $len) {
    foreach (Members::listNames($gk) as $gn) {
        $grpOptions[$gk . ':' . $gn] = ucfirst(Members::LIST_SINGULAR[$gk]) . ': ' . $gn;
    }
}

[$bf, $bp] = Coop::branchFilter('m.branch_id');
$where = '1 = 1' . $bf;
$p = $bp;
if ($q !== '') {
    $where .= ' AND (m.full_name LIKE :q1 OR m.member_no LIKE :q2 OR m.passbook_no = :q3 OR m.mobile LIKE :q4 OR m.tin = :q5
                OR m.id IN (SELECT member_id FROM sav_accounts WHERE account_no = :q6) OR m.id IN (SELECT member_id FROM loan_accounts WHERE loan_no = :q7))';
    $p += ['q1' => '%' . $q . '%', 'q2' => $q . '%', 'q3' => $q, 'q4' => '%' . $q . '%', 'q5' => $q, 'q6' => $q, 'q7' => $q];
}
if ($status !== '') {
    $where .= ' AND m.status = :st';
    $p['st'] = $status;
}
if ($type) {
    $where .= ' AND m.member_type_id = :ty';
    $p['ty'] = $type;
}
if ($branch) {
    $where .= ' AND m.branch_id = :br';
    $p['br'] = $branch;
}
if ($cluster) {
    $where .= ' AND m.cluster_id = :cl';
    $p['cl'] = $cluster;
}
if ($grpKey !== '') {
    $where .= " AND m.`$grpKey` = :grp";
    $p['grp'] = $grpName;
}
$sc = "(SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products sp ON sp.id = a.product_id WHERE a.member_id = m.id AND sp.product_type = 'share_capital' AND a.status <> 'closed')";
if ($flag === 'hold') {
    $where .= ' AND m.hold_flag = 1';
} elseif ($flag === 'alert') {
    $where .= " AND COALESCE(m.alert_note, '') <> ''";
} elseif ($flag === 'no_capital') {
    $where .= " AND $sc = 0";
} elseif ($flag === 'underpaid') {
    $where .= " AND m.subscribed_capital > $sc";
}
$total = (int)DB::value("SELECT COUNT(*) FROM members m WHERE $where", $p);
$page = max(1, (int)g('pg', '1'));
$per = 50;
$rows = DB::all("SELECT m.*, $sc AS share_capital,
                        (SELECT COALESCE(SUM(a.balance),0) FROM sav_accounts a JOIN sav_products sp ON sp.id = a.product_id WHERE a.member_id = m.id AND sp.product_type IN ('ordinary','special') AND a.status <> 'closed') AS savings,
                        (SELECT COALESCE(SUM(l.principal_balance),0) FROM loan_accounts l WHERE l.member_id = m.id AND l.status IN ('current','past_due','restructured')) AS loans,
                        (SELECT COUNT(*) FROM loan_accounts l WHERE l.member_id = m.id AND l.status = 'past_due') AS past_due
                   FROM members m WHERE $where ORDER BY m.full_name LIMIT " . (($page - 1) * $per) . ", $per", $p);
$counts = DB::pairs('SELECT m.status, COUNT(*) FROM members m WHERE 1 = 1' . $bf . ' GROUP BY m.status', $bp);
$types = Members::typeOptions(false);

$actions = '';
if (Access::can('coop.member', 'create')) {
    $actions = '<div class="btn-group">' . btn_link(url('coop.member'), 'New member', 'btn-primary', 'person-plus')
        . '<button type="button" class="btn btn-sm btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="More kinds of members"></button><ul class="dropdown-menu dropdown-menu-end">'
        . '<li><a class="dropdown-item" href="' . h(url('coop.member', ['kind' => 'joint'])) . '"><i class="bi bi-people"></i> Joint account</a></li>'
        . '<li><a class="dropdown-item" href="' . h(url('coop.member', ['kind' => 'institutional'])) . '"><i class="bi bi-building"></i> Institution</a></li></ul></div>';
}
if (Access::can('coop.member', 'create')) {
    $actions .= ' ' . btn_link(url($pageKey, ['a' => 'import']), 'Import Member', 'btn-outline-primary', 'filetype-csv');
}
if (Access::can('coop.consolidated_import', 'create')) {
    $actions .= ' ' . btn_link(url('coop.consolidated_import'), 'Consolidated Import', 'btn-outline-primary', 'files');
}
if (Access::page('rpt.coop_members')) {
    $actions .= ' ' . btn_link(url('rpt.coop_members'), 'Masterlist report', 'btn-outline-secondary', 'file-earmark-spreadsheet');
}
coop_layout('Member Center', ['actions' => $actions]);
$tile = fn(string $st, string $label, string $cls) => '<a class="coop-tile ' . $cls . '" href="' . h(url($pageKey, ['status' => $st])) . '"><div class="t-label">' . h($label) . '</div><div class="t-value">' . number_format((int)($counts[$st] ?? 0)) . '</div></a>';
?>
<div class="coop-tiles">
  <?= $tile('active', 'Active members', 'is-ok') ?>
  <?= $tile('applicant', 'Applicants', 'is-warn') ?>
  <?= $tile('suspended', 'Suspended', 'is-warn') ?>
  <?= $tile('resigned', 'Resigned', '') ?>
  <?= $tile('terminated', 'Terminated', 'is-bad') ?>
  <?= $tile('deceased', 'Deceased', '') ?>
</div>
<form class="row g-2 align-items-end filters mb-3 no-print">
  <input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= input_field('q', 'Find', $q, ['class' => 'col-md-3', 'extra' => 'placeholder="Name, member, passbook, account, loan no., mobile, TIN" autofocus']) ?>
  <?= select_field('status', 'Status', Coop::MEMBER_STATUSES, $status, ['class' => 'col-6 col-md-2', 'blank' => 'Any status', 'extra' => 'data-no-combo']) ?>
  <?= select_field('type', 'Member type', $types, $type, ['class' => 'col-6 col-md-2', 'blank' => 'Any type', 'extra' => 'data-no-combo']) ?>
  <?php if (Coop::userBranchId() === null && count(Coop::branches()) > 1): ?><?= select_field('branch', 'Branch', Coop::branchOptions(false), $branch, ['class' => 'col-6 col-md-2', 'blank' => 'All branches']) ?><?php endif; ?>
  <?php if ($grpOptions): ?><?= select_field('grp', 'Grouping', $grpOptions, $grpKey !== '' ? $grp : '', ['class' => 'col-6 col-md-2', 'blank' => 'Any grouping']) ?><?php endif; ?>
  <?= select_field('flag', 'Show only', ['hold' => 'On hold', 'alert' => 'With an alert', 'no_capital' => 'No share capital yet', 'underpaid' => 'Subscription not fully paid'], $flag, ['class' => 'col-6 col-md-2', 'blank' => 'Everyone']) ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-search"></i> Show</button> <a class="btn btn-sm btn-outline-secondary" href="<?= h(url($pageKey)) ?>">Clear</a></div>
</form>
<div class="card">
  <table class="table table-sm table-hover"><thead><tr><th>Member</th><th>Type</th><th>Status</th><th>Branch</th><th>Member since</th><th class="num">Share capital</th><th class="num">Savings</th><th class="num">Loans</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= h(url('coop.member', ['id' => $r['id']])) ?>"><?= h($r['full_name']) ?></a>
        <?= (int)$r['hold_flag'] ? ' <i class="bi bi-slash-circle text-danger" title="On hold: ' . h((string)$r['hold_reason']) . '"></i>' : '' ?><?= $r['alert_note'] ? ' <i class="bi bi-exclamation-triangle text-warning" title="' . h($r['alert_note']) . '"></i>' : '' ?>
        <div class="small text-muted"><?= h($r['member_no']) ?><?= $r['res_city'] ? ', ' . h($r['res_city']) : '' ?></div></td>
      <td class="small"><?= h($types[(int)$r['member_type_id']] ?? '') ?></td><td><?= coop_status($r['status']) ?></td><td class="small"><?= h(Coop::branchName($r['branch_id'] ? (int)$r['branch_id'] : null)) ?></td>
      <td><?= h(fdate($r['membership_date'])) ?></td><td class="num"><?= cm($r['share_capital']) ?></td><td class="num"><?= cm($r['savings']) ?></td>
      <td class="num"><?= cm($r['loans'], true) ?><?= (int)$r['past_due'] ? '<div class="small text-danger">past due</div>' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No member matches.<?= Access::can('coop.member', 'create') ? ' <a href="' . h(url('coop.member')) . '">Add a member</a>.' : '' ?></td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
<nav class="mt-2 no-print"><ul class="pagination pagination-sm">
  <?php for ($i = max(1, $page - 4); $i <= min($pages, $page + 4); $i++): ?><li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= h(current_url(['pg' => $i])) ?>"><?= $i ?></a></li><?php endfor; ?>
  <li class="page-item disabled"><span class="page-link"><?= number_format($total) ?> members</span></li>
</ul></nav>
<?php endif; ?>
<?php
layout_end();
