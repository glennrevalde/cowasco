<?php
declare(strict_types=1);

require_once __DIR__ . '/_coop.php';
$pageKey = 'cda.capr';
$year = (int)(g('year') ?: (int)date('Y') - 1);
$fyEnd = Coop::setting('fiscal_year_end', '12-31');
$to = $year . '-' . $fyEnd;
if (!valid_date($to)) {
    $to = $year . '-12-31';
}
$from = Coop::addDays(($year - 1) . '-' . substr($to, 5), 1);
// a year still in progress is reported as of today (balances and arrears cannot be known for a future date)
$inProgress = $to > today();
if ($inProgress) {
    $to = today();
}
$report = preg_replace('/[^a-z0-9_]/', '', (string)(g('report') ?: 'capr')) ?: 'capr';
$lines = CoopMetrics::report($report, $from, $to);
$reports = DB::column('SELECT DISTINCT report FROM cda_report_mappings ORDER BY report');
$fmt = function (array $l): string {
    $v = (string)$l['value'];
    if ($v === '' || !is_numeric($v)) {
        return $v;
    }
    return str_contains($v, '.') ? Dec::money($v) : number_format((int)$v);
};

if (in_array(g('a'), ['csv', 'print'], true)) {
    Access::require($pageKey, 'print');
    if (g('a') === 'csv') {
        Audit::log('export', 'cda', 'capr', $year, strtoupper($report) . ' ' . $year, ['page_key' => $pageKey]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report . '_' . $year . '.csv"');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Section', 'Field code', 'Item', 'Value', 'Source']);
        foreach ($lines as $l) {
            // numbers go out plain (no thousands separator) so the receiving form or spreadsheet reads them as numbers
            fputcsv($fh, [(string)$l['section'], $l['field_code'], $l['label'], $l['error'] ? 'ERROR: ' . $l['error'] : (string)$l['value'], $l['source']]);
        }
        exit;
    }
    $copy = coop_print_gate('cda.' . $report, 'capr', $year, strtoupper($report) . ' ' . $year, $pageKey, 'report data');
    if ($copy === null) {
        exit;
    }
    layout_start(strtoupper($report) . ' ' . $year, ['bare' => true]);
    ?>
    <div class="doc coop-doc"><?= coop_copy_mark($copy) ?>
      <?= coop_print_head($report === 'capr' ? 'COOPERATIVE ANNUAL PROGRESS REPORT DATA' : strtoupper($report) . ' DATA', (string)$year, $to, ['Period' => fdate($from) . ' to ' . fdate($to)]) ?>
      <?php $sec = null; ?><table class="table table-sm doc-table"><tbody>
      <?php foreach ($lines as $l): if ($l['section'] !== $sec): $sec = $l['section']; ?><tr><th colspan="2" class="pt-3"><?= h((string)$sec) ?></th></tr><?php endif; ?>
        <tr><td><?= h($l['label']) ?></td><td class="num"><?= $l['error'] ? '<em>' . h($l['error']) . '</em>' : h($fmt($l)) ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <p class="small">Figures from the books of the cooperative as of <?= h(fdate($to)) ?>. Transcribe them into the current CDA form; the line definitions are kept in Report Field Mappings.</p>
      <?= coop_signatures(['Prepared by' => Coop::setting('signatory_bookkeeper'), 'Certified correct' => Coop::setting('signatory_gm'), 'Chairperson' => Coop::setting('signatory_chair')]) ?>
    </div><script>window.addEventListener('load', function () { window.print(); });</script>
    <?php
    layout_end();
    exit;
}

$errors = array_filter($lines, fn($l) => $l['error']);
coop_layout('CAPR Data Preparation & Export', ['actions' => page_link($pageKey, ['a' => 'print', 'year' => $year, 'report' => $report], 'Print', 'btn-outline-secondary', 'printer', 'print') . ' '
    . page_link($pageKey, ['a' => 'csv', 'year' => $year, 'report' => $report], 'Export CSV', 'btn-outline-secondary', 'filetype-csv', 'print') . ' ' . page_link('cda.mappings', ['report' => $report], 'Field mappings', 'btn-outline-secondary', 'diagram-3')]);
?>
<form class="row g-2 align-items-end filters mb-3 no-print"><input type="hidden" name="p" value="<?= h($pageKey) ?>">
  <?= input_field('year', 'Fiscal year', (string)$year, ['class' => 'col-6 col-md-2', 'type' => 'number', 'step' => '1']) ?>
  <?php if (count($reports) > 1): ?><?= select_field('report', 'Report', array_combine($reports, array_map('strtoupper', $reports)), $report, ['class' => 'col-6 col-md-2', 'extra' => 'data-no-combo']) ?><?php endif; ?>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Compute</button></div>
  <div class="col-auto small text-muted">Period <?= h(fdate($from)) ?> to <?= h(fdate($to)) ?> (fiscal year end <?= h($fyEnd) ?>).</div>
</form>
<?php if ($inProgress): ?><div class="alert alert-light small">Fiscal year <?= $year ?> is not over: the figures are as of today.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-warning small"><?= count($errors) ?> line(s) cannot be computed; correct their source in Report Field Mappings.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card"><table class="table table-sm mb-0"><tbody>
  <?php $sec = null; foreach ($lines as $l): if ($l['section'] !== $sec): $sec = $l['section']; ?><tr class="table-light"><th colspan="3"><?= h((string)$sec) ?></th></tr><?php endif; ?>
    <tr><td><?= h($l['label']) ?><div class="small text-muted"><?= h($l['field_code']) ?></div></td><td class="small text-muted"><?= h($l['source']) ?></td>
      <td class="num fw-semibold"><?= $l['error'] ? '<span class="text-danger small">' . h($l['error']) . '</span>' : h($fmt($l)) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$lines): ?><tr><td class="text-muted">No line is mapped for this report yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
  <div class="col-xl-4">
    <div class="card"><div class="card-body small text-muted">
      <p>The figures come from the general ledger and the member ledgers at the end of the fiscal year. Before submitting, check the SL vs GL reconciliation and the trial balance for the same date.</p>
      <p class="mb-0">When the CDA revises the CAPR form, change the lines in Report Field Mappings: each line takes a named figure, a list of account codes or a fixed text, so no program change is needed.</p>
    </div></div>
  </div>
</div>
<?php
layout_end();
