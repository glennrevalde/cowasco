<?php
declare(strict_types=1);

$defs = Access::allPageDefs();
$mods = Access::modules();
$groups = [];
foreach ($defs as $k => $d) {
    if (($d['type'] === 'report') && Access::page($k)) {
        $groups[$mods[$d['module']][0]][$d['group'] === 'Reports' ? '' : $d['group']][] = [$k, $d['name']];
    }
}
$mem = DB::all('SELECT m.*, u.username FROM memorized_reports m JOIN users u ON u.id = m.created_by WHERE m.created_by = :u OR m.is_shared = 1 ORDER BY m.group_name, m.name', ['u' => Auth::id()]);
layout_start('Report Center');
?>
<p class="text-muted">Every report can be filtered by date and other fields, customized (columns and sorting), drilled into, memorized, printed or saved as PDF, exported to a spreadsheet, and emailed. You only see the reports your role and page access allow.</p>
<?php if ($mem): ?>
<div class="card mb-3"><div class="card-header fw-semibold">Memorized reports</div><div class="card-body d-flex flex-wrap gap-2">
  <?php foreach ($mem as $m): if (!Access::page($m['report_key'])) { continue; } ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(base_url() . '/index.php?p=' . urlencode($m['report_key']) . '&' . $m['query_string']) ?>"><?= h(($m['group_name'] ? $m['group_name'] . ' › ' : '') . $m['name']) ?></a>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
<div class="row g-3">
<?php foreach ($groups as $module => $subs): ?>
  <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-header fw-semibold"><?= h($module) ?></div><div class="card-body">
    <?php foreach ($subs as $sub => $list): ?>
      <?php if ($sub): ?><div class="small text-muted text-uppercase mt-2"><?= h($sub) ?></div><?php endif; ?>
      <?php foreach ($list as [$k, $name]): ?><div><a href="<?= h(url($k)) ?>"><?= h($name) ?></a></div><?php endforeach; ?>
    <?php endforeach; ?>
  </div></div></div>
<?php endforeach; ?>
</div>
<?php
layout_end();
