<?php
declare(strict_types=1);

/**
 * Cooperative > Passbook Types: the Passbook Type Setup of the former program and the design of each passbook.
 *
 *  - Passbook Information: Type ID, Name, Description, and the kind (a savings passbook or a loan passbook, each with
 *    its own design);
 *  - Cover, Header and Footer: Top and Left, where each part starts printing; Passbook Body: Top and Left of line 1,
 *    Interval (the distance from one line to the next), Start Line Skip, No. of Line Skip and Max Lines;
 *    all in tups (1 inch = 1,440 tups);
 *  - the paper (any passbook size), the font, when the header prints;
 *  - the design of the Cover, the Header, the Body and the Footer: the fields printed, each placed from the origin of
 *    its part (Left, Top), its width and alignment, its format, its font size. They are dragged on the drawing of the
 *    page or typed in the table; a test page prints the design with sample entries.
 * The margins of the slip validation are at the foot of the page.
 */
require_once __DIR__ . '/_coop.php';
$pageKey = 'coop.passbooks';
$edit = gint('id') ? Passbook::type((int)gint('id')) : null;
if (gint('id') && !$edit) {
    flash('warning', 'Passbook type not found.');
    redirect(url($pageKey));
}
$usedBy = function (?int $id): array {
    if (!$id) {
        return [];
    }
    return array_merge(
        DB::all("SELECT 'Savings product' AS kind, CONCAT(code, ' ', name) AS name FROM sav_products WHERE passbook_type_id = :t", ['t' => $id]),
        DB::all("SELECT 'Loan product' AS kind, CONCAT(code, ' ', name) AS name FROM loan_products WHERE passbook_type_id = :t", ['t' => $id]),
        DB::all("SELECT 'Savings account' AS kind, account_no AS name FROM sav_accounts WHERE passbook_type_id = :t LIMIT 5", ['t' => $id])
    );
};
$used = $usedBy($edit ? (int)$edit['id'] : null);
// the kind: a new type takes it from the button that opened it; a type not used yet may change it
$ledger = $edit ? $edit['ledger'] : (g('ledger') === 'loan' ? 'loan' : 'savings');
if ((is_post() || (!$used && $edit)) && isset(Passbook::LEDGERS[(string)(is_post() ? p('ledger') : g('ledger'))])) {
    $ledger = (string)(is_post() ? p('ledger') : g('ledger'));
}
$measures = ['cover_top' => 'Top', 'cover_left' => 'Left', 'header_top' => 'Top', 'header_left' => 'Left', 'footer_top' => 'Top', 'footer_left' => 'Left',
    'body_top' => 'Top', 'body_left' => 'Left', 'body_interval' => 'Interval', 'page_width' => 'Page width', 'page_height' => 'Page height'];

/** The design posted by the form: L[section][n][f|x|y|w|a|fmt|z|t|fs] and opt[...] */
$postedLayout = function (string $ledger): array {
    $j = [];
    foreach (array_keys(Passbook::SECTIONS) as $sec) {
        $j[$sec] = array_values(array_filter((array)($_POST['L'][$sec] ?? []), 'is_array'));
    }
    $j['opt'] = (array)($_POST['opt'] ?? []);
    return Passbook::clean($ledger, $j);
};

// ------------------------------------------------------------------ a test page (the saved design with sample entries)
if ($edit && g('a') === 'test') {
    Access::require($pageKey, 'view');
    $what = in_array(g('what'), ['cover', 'page', 'lines'], true) ? (string)g('what') : 'page';
    $hint = ['cover' => 'Put the inside cover of a ' . mb_strtolower(Passbook::LEDGERS[$edit['ledger']]) . ' (or plain paper) in the passbook printer.',
        'page' => 'Put a page of a ' . mb_strtolower(Passbook::LEDGERS[$edit['ledger']]) . ' (or plain paper) in the passbook printer: the header, every line of the page with sample entries, and the footer.',
        'lines' => 'Put a page of a ' . mb_strtolower(Passbook::LEDGERS[$edit['ledger']]) . ' in the passbook printer: the line numbers print on every line of the page, the skipped lines stay blank.'][$what];
    coop_sheet_start('Test: ' . $edit['name'], h($hint));
    echo Passbook::renderTest($edit, $what);
    coop_sheet_end();
    exit;
}

handle_post(function () use ($edit, $pageKey, $measures, $ledger, $postedLayout, $used) {
    // the machine validation printed on the deposit / withdrawal slip (Savings Account Transaction: Show Preview)
    if (p('action') === 'validation') {
        Access::require($pageKey, 'edit');
        $changes = [];
        $cur = coop_slip_settings();
        foreach (['slip_margin_top' => ['top', 'Top margin', 0, 5], 'slip_margin_right' => ['right', 'Right margin', 0, 5], 'slip_margin_bottom' => ['bottom', 'Bottom margin', 0, 5],
                  'slip_margin_left' => ['left', 'Left margin', 0, 5], 'slip_line_gap' => ['gap', 'Distance between the lines', 0.1, 2]] as $k => [$ck, $label, $lo, $hi]) {
            $raw = trim(str_replace(',', '.', (string)p($k)));
            if ($raw === '' || !is_numeric($raw) || (float)$raw < $lo || (float)$raw > $hi) {
                fail($label . ': enter inches from ' . $lo . ' to ' . $hi . ' (for example 0.25).');
            }
            $v = number_format((float)$raw, 2, '.', '');
            if ($v !== number_format($cur[$ck], 2, '.', '')) {
                $changes[] = ['field' => $k, 'old' => number_format($cur[$ck], 2, '.', ''), 'new' => $v];
            }
            Settings::set('coop_' . $k, $v);
        }
        $font = (int)p('sav_validation_font');
        if ($font < 6 || $font > 16) {
            fail('Font size: enter 6 to 16 points.');
        }
        Settings::set('coop_sav_validation_font', (string)$font);
        Audit::log('edit', 'coop', 'setting', null, 'Slip validation margins', ['page_key' => $pageKey, 'changes' => $changes]);
        flash('success', 'The margins of the slip validation were saved.');
        redirect(url($pageKey, $edit ? ['id' => $edit['id']] : []));
    }
    if (p('action') === 'delete') {
        Access::require($pageKey, 'delete');
        if (!$edit) {
            fail('Choose the passbook type.');
        }
        if ($used) {
            fail('The passbook type is used by ' . count($used) . ' product(s) or account(s). Choose another passbook type on them first, or make this one inactive.');
        }
        DB::run('DELETE FROM passbook_types WHERE id = :id', ['id' => $edit['id']]);
        Audit::log('delete', 'coop', 'passbook_type', (int)$edit['id'], $edit['name'], ['page_key' => $pageKey, 'snapshot' => $edit]);
        flash('success', 'Passbook type ' . $edit['name'] . ' deleted.');
        redirect(url($pageKey));
    }
    Access::require($pageKey, $edit ? 'edit' : 'create');
    $d = ['name' => mb_substr(trim((string)p('name')), 0, 80), 'description' => mb_substr(trim((string)p('description')), 0, 255) ?: null, 'ledger' => $ledger,
        'start_line_skip' => (int)p('start_line_skip'), 'line_skip' => (int)p('line_skip'), 'max_lines' => (int)p('max_lines'),
        'font_size_pt' => (float)str_replace(',', '.', (string)p('font_size_pt', '9')), 'is_active' => p('is_active', '1') === '1' ? 1 : 0, 'skip_lines' => null];
    if ($d['name'] === '') {
        fail('Enter the name of the passbook type.');
    }
    if ($edit && $used && $ledger !== $edit['ledger']) {
        fail('The passbook type is used by products or accounts: it stays a ' . mb_strtolower(Passbook::LEDGERS[$edit['ledger']]) . '.');
    }
    foreach ($measures as $k => $label) {
        $raw = trim((string)p($k));
        if ($raw === '' || !preg_match('/^\d+$/', $raw) || (int)$raw > 30000) {
            $part = ['cover' => 'Cover', 'header' => 'Header', 'footer' => 'Footer', 'body' => 'Passbook Body', 'page' => 'Paper'][explode('_', $k)[0]];
            fail($part . ', ' . $label . ': enter tups from 0 to 30,000 (1,440 tups = 1 inch).');
        }
        $d[$k] = (int)$raw;
    }
    if ($d['page_width'] < 1440 || $d['page_height'] < 1440) {
        fail('The page must be at least 1 inch (1,440 tups) wide and high.');
    }
    if ($d['body_interval'] < 100 || $d['body_interval'] > 2000) {
        fail('Passbook Body, Interval: the distance from one line to the next, 100 to 2,000 tups (275 is about 1/5 inch).');
    }
    if ($d['max_lines'] < 1 || $d['max_lines'] > 99) {
        fail('Max Lines: the last line of a page, 1 to 99.');
    }
    if ($d['start_line_skip'] < 0 || $d['start_line_skip'] === 1 || $d['start_line_skip'] > $d['max_lines']) {
        fail('Start Line Skip: the first line never printed on (the centre fold), from 2 to ' . $d['max_lines'] . ', or 0 when no line is skipped. Printing always starts on line 1.');
    }
    if ($d['start_line_skip'] === 0) {
        $d['line_skip'] = 0;
    } elseif ($d['line_skip'] < 1 || $d['start_line_skip'] + $d['line_skip'] - 1 >= $d['max_lines']) {
        fail('No. of Line Skip: the lines skipped from line ' . $d['start_line_skip'] . ', at least 1, and a line must be left after them before Max Lines (' . $d['max_lines'] . ').');
    }
    if ($d['font_size_pt'] < 6 || $d['font_size_pt'] > 16) {
        fail('Font size: 6 to 16 points.');
    }
    $layout = $postedLayout($ledger);
    if (!$layout['body']) {
        fail('The body prints no field: add the columns of the passbook lines to the Body.');
    }
    $d['layout_json'] = json_encode($layout, JSON_UNESCAPED_UNICODE);
    if (DB::value('SELECT id FROM passbook_types WHERE name = :n AND id <> :id', ['n' => $d['name'], 'id' => $edit['id'] ?? 0])) {
        fail('A passbook type with that name exists.');
    }
    if ($edit) {
        DB::update('passbook_types', $d, 'id = :id', ['id' => $edit['id']]);
        $before = $edit;
        unset($before['layout']);
        Audit::log('edit', 'coop', 'passbook_type', (int)$edit['id'], $d['name'], ['page_key' => $pageKey, 'before' => $before, 'after' => $d]);
        $id = (int)$edit['id'];
    } else {
        $id = DB::insert('passbook_types', $d);
        Audit::log('create', 'coop', 'passbook_type', $id, $d['name'], ['page_key' => $pageKey, 'snapshot' => $d]);
    }
    flash('success', 'Passbook type ' . $d['name'] . ' saved.');
    redirect(url($pageKey, ['id' => $id]));
});

// ------------------------------------------------------------------ the page
$rows = DB::all('SELECT t.*, (SELECT COUNT(*) FROM sav_products p WHERE p.passbook_type_id = t.id) + (SELECT COUNT(*) FROM loan_products l WHERE l.passbook_type_id = t.id) AS used FROM passbook_types t ORDER BY t.id');
$defaults = Passbook::defaultSetup($ledger) + ['is_active' => 1, 'name' => '', 'description' => ''];
$cur = $edit ?: $defaults;
if (is_post() && isset($_POST['L'])) {
    $layout = $postedLayout($ledger);
} elseif ($edit && $ledger === $edit['ledger']) {
    $layout = $edit['layout'];
} else {
    $layout = Passbook::defaultLayout($ledger);
}
$canSave = Access::can($pageKey, $edit ? 'edit' : 'create');
$catalog = [];
foreach (Passbook::FIELDS[$ledger] as $sec => $fields) {
    foreach ($fields as $k => [$label, $kind]) {
        $catalog[$sec][$k] = ['label' => $label, 'kind' => $kind];
    }
}
$sample = ['values' => Passbook::sampleValues($ledger), 'lines' => Passbook::sampleLines($ledger, 99)];
$fmtOpts = Passbook::FORMATS;
$mkRow = function (string $sec, $i, array $it) use ($catalog, $fmtOpts): string {
    $kind = $catalog[$sec][$it['f']]['kind'] ?? 'text';
    $n = fn(string $k) => 'L[' . $sec . '][' . $i . '][' . $k . ']';
    $fieldOpts = '';
    foreach ($catalog[$sec] as $k => $c) {
        $fieldOpts .= '<option value="' . h($k) . '"' . ($k === $it['f'] ? ' selected' : '') . '>' . h($c['label']) . '</option>';
    }
    $fmt = '';
    foreach ($fmtOpts[$kind] ?? [] as $k => $ex) {
        $fmt .= '<option value="' . h($k) . '"' . ($k === $it['fmt'] ? ' selected' : '') . '>' . h($ex) . '</option>';
    }
    $zero = '';
    foreach (Passbook::ZERO as $k => $label) {
        $zero .= '<option value="' . h($k) . '"' . ($k === $it['z'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $align = '';
    foreach (Passbook::ALIGN as $k => $label) {
        $align .= '<option value="' . h($k) . '"' . ($k === $it['a'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return '<tr data-item data-kind="' . h($kind) . '">'
        . '<td data-label="Field"><select class="form-select form-select-sm" name="' . $n('f') . '" data-k="f" aria-label="Field">' . $fieldOpts . '</select>'
        . '<input class="form-control form-control-sm mt-1 pbd-text" name="' . $n('t') . '" data-k="t" value="' . h($it['t']) . '" placeholder="Text, e.g. Balance as of {date}" aria-label="Text"' . ($it['f'] === 'text' ? '' : ' hidden') . '></td>'
        . '<td data-label="Left"><input class="form-control form-control-sm text-end" type="number" step="1" name="' . $n('x') . '" data-k="x" value="' . (int)$it['x'] . '" aria-label="Left"></td>'
        . '<td data-label="Top"><input class="form-control form-control-sm text-end" type="number" step="1" name="' . $n('y') . '" data-k="y" value="' . (int)$it['y'] . '" aria-label="Top"></td>'
        . '<td data-label="Width"><input class="form-control form-control-sm text-end" type="number" step="1" min="0" name="' . $n('w') . '" data-k="w" value="' . (int)$it['w'] . '" aria-label="Width"></td>'
        . '<td data-label="Align"><select class="form-select form-select-sm" name="' . $n('a') . '" data-k="a" aria-label="Align">' . $align . '</select></td>'
        . '<td data-label="Format"><select class="form-select form-select-sm" name="' . $n('fmt') . '" data-k="fmt" aria-label="Format"' . ($fmt === '' ? ' hidden' : '') . '>' . $fmt . '</select>'
        . '<select class="form-select form-select-sm' . ($fmt === '' ? '' : ' mt-1') . '" name="' . $n('z') . '" data-k="z" aria-label="A zero amount prints"' . ($kind === 'money' ? '' : ' hidden') . ' title="A zero amount prints">' . $zero . '</select></td>'
        . '<td data-label="Size (pt)"><input class="form-control form-control-sm text-end" type="number" step="0.5" min="0" max="20" name="' . $n('fs') . '" data-k="fs" value="' . ((float)$it['fs'] > 0 ? h((string)$it['fs']) : '') . '" placeholder="auto" aria-label="Font size"></td>'
        . '<td class="grid-acts"><button type="button" class="btn btn-link btn-sm text-danger" data-del-item title="Remove the field" aria-label="Remove the field"><i class="bi bi-x-lg"></i></button></td></tr>';
};
$secHelp = [
    'cover' => 'Printed on the inside cover by Print Passbook Cover. Left and Top count from Cover Left / Top.',
    'header' => 'Printed on a page before its first line (line 1). Left and Top count from Header Left / Top.',
    'body' => 'One line per transaction: line 1 at Body Top, each next line one Interval lower. Left counts from Body Left; Top moves a column up or down within its line.',
    'footer' => 'Printed by Print Passbook Footer, and at the foot of a full page when the entries continue on the next page. Left and Top count from Footer Left / Top. A text may hold {balance}, {date}, {page}, {next_page}, {number} or {name}.',
];
coop_layout('Passbook Types', ['js' => ['js/passbook_designer.js'],
    'actions' => (Access::page('sav.passbook') ? btn_link(url('sav.passbook'), 'Passbook Printing', 'btn-outline-secondary', 'printer') : '')]);
?>
<div class="pbt">
  <aside class="pbt-side">
    <div class="card pbt-list"><div class="card-header d-flex align-items-center gap-2"><span>Passbook types</span></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th class="num">Type ID</th><th>Name</th><th>Kind</th><th class="num">Used by</th></tr></thead><tbody>
      <?php foreach ($rows as $r): $rl = Passbook::ledgerOf($r); ?>
        <tr class="<?= $edit && (int)$edit['id'] === (int)$r['id'] ? 'is-current' : '' ?><?= (int)$r['is_active'] ? '' : ' text-muted' ?>" data-href="<?= h(url($pageKey, ['id' => $r['id']])) ?>">
          <td class="num"><?= (int)$r['id'] ?></td><td><a href="<?= h(url($pageKey, ['id' => $r['id']])) ?>"><?= h($r['name']) ?></a><div class="small text-muted"><?= h((string)$r['description']) ?></div></td>
          <td class="small"><?= $rl === 'loan' ? 'Loan' : 'Savings' ?></td><td class="num small"><?= (int)$r['used'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="4" class="text-muted">No passbook type yet.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if (Access::can($pageKey, 'create')): ?>
      <div class="card-body d-grid gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?= h(url($pageKey, ['ledger' => 'savings'])) ?>"><i class="bi bi-plus-lg"></i> New savings passbook</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= h(url($pageKey, ['ledger' => 'loan'])) ?>"><i class="bi bi-plus-lg"></i> New loan passbook</a>
      </div>
      <?php endif; ?>
    </div>
    <p class="small text-muted mt-2 mb-0">All measurements are in tups, as in the former program: 1 inch = 1,440 tups (1 cm = 567). Print a test page and adjust until every field falls on its place in the passbook.</p>
  </aside>

  <form method="post" class="pbt-main" id="pbtForm" data-once
        data-catalog='<?= h(json_encode($catalog)) ?>' data-formats='<?= h(json_encode(Passbook::FORMATS)) ?>' data-sample='<?= h(json_encode($sample)) ?>' data-ledger="<?= h($ledger) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="card mb-3"><div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span><?= $edit ? 'Passbook Type Setup: ' . h($edit['name']) : 'Passbook Type Setup: new ' . h(mb_strtolower(Passbook::LEDGERS[$ledger])) ?></span>
        <?php if ($used): ?><span class="small text-muted fw-normal ms-auto">Used by <?= h(implode(', ', array_map(fn($u) => $u['name'], array_slice($used, 0, 4)))) ?><?= count($used) > 4 ? ' and ' . (count($used) - 4) . ' more' : '' ?></span><?php endif; ?></div>
      <div class="card-body">
        <fieldset<?= $canSave ? '' : ' disabled' ?>>
        <div class="pbt-grid">
          <fieldset class="pbt-box pbt-info"><legend>Passbook Information</legend>
            <div class="pbt-row"><label for="pbtId">Type ID</label><input class="form-control form-control-sm" id="pbtId" value="<?= $edit ? (int)$edit['id'] : 'new' ?>" readonly></div>
            <div class="pbt-row"><label for="pbtName">Name</label><input class="form-control form-control-sm" id="pbtName" name="name" value="<?= h((string)fv('name', $cur)) ?>" required maxlength="80"></div>
            <div class="pbt-row"><label for="pbtDesc">Description</label><textarea class="form-control form-control-sm" id="pbtDesc" name="description" rows="2" maxlength="255"><?= h((string)fv('description', $cur)) ?></textarea></div>
            <div class="pbt-row"><label for="pbtKind">Kind</label>
              <?php if ($edit && $used): ?><input type="hidden" name="ledger" value="<?= h($ledger) ?>"><input class="form-control form-control-sm" id="pbtKind" value="<?= h(Passbook::LEDGERS[$ledger]) ?>" readonly>
              <?php else: ?><select class="form-select form-select-sm" id="pbtKind" name="ledger" data-no-combo data-kind-switch="<?= h(url($pageKey, $edit ? ['id' => $edit['id']] : [])) ?>"><?= options(Passbook::LEDGERS, $ledger) ?></select><?php endif; ?></div>
            <div class="pbt-row"><span></span><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="pbtActive" <?= (int)fv('is_active', $cur, 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="pbtActive">Active</label></div></div>
          </fieldset>
          <div class="pbt-margins">
            <?php foreach (['cover' => 'Cover', 'header' => 'Header', 'footer' => 'Footer'] as $sec => $title): ?>
            <fieldset class="pbt-box"><legend><?= h($title) ?></legend>
              <div class="pbt-row"><label for="pbt_<?= $sec ?>_top">Top</label><input class="form-control form-control-sm text-end" type="number" min="0" max="30000" step="1" id="pbt_<?= $sec ?>_top" name="<?= $sec ?>_top" value="<?= h((string)fv($sec . '_top', $cur)) ?>" data-m></div>
              <div class="pbt-row"><label for="pbt_<?= $sec ?>_left">Left</label><input class="form-control form-control-sm text-end" type="number" min="0" max="30000" step="1" id="pbt_<?= $sec ?>_left" name="<?= $sec ?>_left" value="<?= h((string)fv($sec . '_left', $cur)) ?>" data-m></div>
            </fieldset>
            <?php endforeach; ?>
            <fieldset class="pbt-box pbt-body"><legend>Passbook Body</legend>
              <div class="pbt-row"><label for="pbt_body_top">Top</label><input class="form-control form-control-sm text-end" type="number" min="0" max="30000" step="1" id="pbt_body_top" name="body_top" value="<?= h((string)fv('body_top', $cur)) ?>" data-m></div>
              <div class="pbt-row"><label for="pbtSkipStart">Start Line Skip</label><input class="form-control form-control-sm text-end" type="number" min="0" max="99" step="1" id="pbtSkipStart" name="start_line_skip" value="<?= h((string)fv('start_line_skip', $cur)) ?>" data-m></div>
              <div class="pbt-row"><label for="pbt_body_left">Left</label><input class="form-control form-control-sm text-end" type="number" min="0" max="30000" step="1" id="pbt_body_left" name="body_left" value="<?= h((string)fv('body_left', $cur)) ?>" data-m></div>
              <div class="pbt-row"><label for="pbtSkipN">No. of Line Skip</label><input class="form-control form-control-sm text-end" type="number" min="0" max="99" step="1" id="pbtSkipN" name="line_skip" value="<?= h((string)fv('line_skip', $cur)) ?>" data-m></div>
              <div class="pbt-row"><label for="pbt_body_interval">Interval</label><input class="form-control form-control-sm text-end" type="number" min="100" max="2000" step="1" id="pbt_body_interval" name="body_interval" value="<?= h((string)fv('body_interval', $cur)) ?>" data-m></div>
              <div class="pbt-row"><label for="pbtMax">Max Lines</label><input class="form-control form-control-sm text-end" type="number" min="1" max="99" step="1" id="pbtMax" name="max_lines" value="<?= h((string)fv('max_lines', $cur)) ?>" data-m></div>
              <div class="pbt-rule" data-rule></div>
            </fieldset>
          </div>
          <fieldset class="pbt-box pbt-paper"><legend>Paper and printing</legend>
            <div class="pbt-row"><label for="pbtSize">Passbook size</label><select class="form-select form-select-sm" id="pbtSize" data-no-combo><option value="">Custom</option><?= options(Passbook::PAGE_SIZES, (int)fv('page_width', $cur) . 'x' . (int)fv('page_height', $cur)) ?></select></div>
            <div class="pbt-row"><label for="pbtW">Page width</label><input class="form-control form-control-sm text-end" type="number" min="1440" max="30000" step="1" id="pbtW" name="page_width" value="<?= h((string)fv('page_width', $cur)) ?>" data-m></div>
            <div class="pbt-row"><label for="pbtH">Page height</label><input class="form-control form-control-sm text-end" type="number" min="1440" max="30000" step="1" id="pbtH" name="page_height" value="<?= h((string)fv('page_height', $cur)) ?>" data-m></div>
            <div class="pbt-row"><label for="pbtFont">Font</label><select class="form-select form-select-sm" id="pbtFont" name="opt[font]" data-no-combo data-m><?= options(Passbook::FONTS, $layout['opt']['font']) ?></select></div>
            <div class="pbt-row"><label for="pbtCpi">Characters per inch</label><select class="form-select form-select-sm" id="pbtCpi" name="opt[cpi]" data-no-combo data-m><?= options(Passbook::CPI, (string)$layout['opt']['cpi']) ?></select></div>
            <div class="pbt-row"><label for="pbtFs">Font size (pt)</label><input class="form-control form-control-sm text-end" type="number" min="6" max="16" step="0.5" id="pbtFs" name="font_size_pt" value="<?= h((string)(float)fv('font_size_pt', $cur, 9)) ?>" data-m></div>
            <div class="pbt-row"><label for="pbtHm">Header prints</label><select class="form-select form-select-sm" id="pbtHm" name="opt[header]" data-no-combo><?= options(Passbook::HEADER_MODES, $layout['opt']['header']) ?></select></div>
            <div class="pbt-row"><span></span><div class="form-check"><input type="hidden" name="opt[footer_full]" value="0"><input class="form-check-input" type="checkbox" id="pbtFf" name="opt[footer_full]" value="1" <?= !empty($layout['opt']['footer_full']) ? 'checked' : '' ?>><label class="form-check-label" for="pbtFf">Footer at the foot of a full page</label></div></div>
            <div class="pbt-note">Note: 1 inch = 1,440 tups. All measurements are in tups.</div>
          </fieldset>
        </div>
        </fieldset>
      </div>
    </div>

    <div class="card mb-3 pbd"><div class="card-header pbd-head">
        <span>Design of the <?= h(mb_strtolower(Passbook::LEDGERS[$ledger])) ?></span>
        <div class="pbd-tabs" role="tablist" aria-label="Part of the passbook">
          <?php foreach (Passbook::SECTIONS as $sec => $title): ?><button type="button" role="tab" class="pbd-tab<?= $sec === 'body' ? ' is-on' : '' ?>" data-sec="<?= $sec ?>" aria-selected="<?= $sec === 'body' ? 'true' : 'false' ?>"><?= h($title) ?> <span class="pbd-count"><?= count($layout[$sec]) ?></span></button><?php endforeach; ?>
        </div></div>
      <div class="card-body">
        <div class="pbd-stage-wrap"><div class="pbd-tools"><span class="small text-muted" data-stage-help>Drag a field to move it (arrow keys: 10 tups, with Shift: 100); drag the blue cross to move the whole part.</span>
            <div class="pbd-zoom"><label class="small text-muted" for="pbdZoom">Zoom</label><input type="range" id="pbdZoom" min="40" max="160" value="100" step="1"></div></div>
          <div class="pbd-stage" data-stage aria-label="Drawing of the passbook page"></div></div>
        <?php foreach (Passbook::SECTIONS as $sec => $title): ?>
        <div class="pbd-sec" data-sec-pane="<?= $sec ?>"<?= $sec === 'body' ? '' : ' hidden' ?>>
          <div class="small text-muted mb-2"><?= h($secHelp[$sec]) ?></div>
          <div class="table-responsive"><table class="table table-sm align-middle pbd-items"><thead><tr><th>Field</th><th class="num">Left</th><th class="num">Top</th><th class="num">Width</th><th>Align</th><th>Format</th><th class="num">Size (pt)</th><th></th></tr></thead>
            <tbody data-items="<?= $sec ?>"><?php foreach ($layout[$sec] as $i => $it): ?><?= $mkRow($sec, $i, $it) ?><?php endforeach; ?></tbody></table></div>
          <template data-tpl="<?= $sec ?>"><?= $mkRow($sec, '__i__', ['f' => (string)array_key_first($catalog[$sec]), 'x' => 0, 'y' => 0, 'w' => 0, 'a' => 'l', 'fmt' => '', 'z' => '', 't' => '', 'fs' => 0]) ?></template>
          <?php if ($canSave): ?><button type="button" class="btn btn-outline-secondary btn-sm" data-add-item="<?= $sec ?>"><i class="bi bi-plus-lg"></i> Add a field</button>
            <button type="button" class="btn btn-link btn-sm" data-reset-sec="<?= $sec ?>" data-default='<?= h(json_encode(Passbook::defaultLayout($ledger)[$sec])) ?>'>Use the default <?= h(mb_strtolower($title)) ?></button><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="sticky-actions pbt-actions">
      <?php if ($canSave): ?><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg"></i> Save</button><?php endif; ?>
      <?php if ($edit): ?>
        <div class="btn-group"><button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-printer"></i> Print a test page</button>
          <div class="dropdown-menu">
            <a class="dropdown-item" target="_blank" rel="noopener" data-test href="<?= h(url($pageKey, ['id' => $edit['id'], 'a' => 'test', 'what' => 'page'])) ?>">A page with sample entries (header, lines, footer)</a>
            <a class="dropdown-item" target="_blank" rel="noopener" data-test href="<?= h(url($pageKey, ['id' => $edit['id'], 'a' => 'test', 'what' => 'lines'])) ?>">The line numbers of a page</a>
            <a class="dropdown-item" target="_blank" rel="noopener" data-test href="<?= h(url($pageKey, ['id' => $edit['id'], 'a' => 'test', 'what' => 'cover'])) ?>">The cover</a>
          </div></div>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h(url($pageKey, $edit ? ['id' => $edit['id']] : ['ledger' => $ledger])) ?>">Clear</a>
      <?php if ($edit && !$used && Access::can($pageKey, 'delete')): ?>
        <button class="btn btn-outline-danger btn-sm ms-auto" name="action" value="delete" formnovalidate data-confirm="Delete the passbook type <?= h($edit['name']) ?>?"><i class="bi bi-trash"></i> Delete</button>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if (Access::can($pageKey, 'edit')): ?>
  <?php $slip = coop_slip_settings(); $f2 = fn($v) => number_format((float)$v, 2, '.', ''); ?>
  <form method="post" class="card mt-3" id="slipForm"><div class="card-header">Slip validation: margins</div><div class="card-body">
    <?= csrf_field() ?><input type="hidden" name="action" value="validation">
    <p class="small text-muted">The machine validation printed on the deposit or withdrawal slip (Savings Account Transaction) and on the loan payslip (Loan Collection), with Show Preview. Nothing else is printed: the margins are measured from the edges of the slip, in inches (1 inch = 25.4 mm).</p>
    <div class="row g-2">
      <?= input_field('slip_margin_top', 'Top margin (in)', $f2($slip['top']), ['class' => 'col-6 col-md-3 col-xl-2', 'type' => 'number', 'step' => '0.01', 'extra' => 'min="0" max="5"']) ?>
      <?= input_field('slip_margin_right', 'Right margin (in)', $f2($slip['right']), ['class' => 'col-6 col-md-3 col-xl-2', 'type' => 'number', 'step' => '0.01', 'extra' => 'min="0" max="5"']) ?>
      <?= input_field('slip_margin_bottom', 'Bottom margin (in)', $f2($slip['bottom']), ['class' => 'col-6 col-md-3 col-xl-2', 'type' => 'number', 'step' => '0.01', 'extra' => 'min="0" max="5"']) ?>
      <?= input_field('slip_margin_left', 'Left margin (in)', $f2($slip['left']), ['class' => 'col-6 col-md-3 col-xl-2', 'type' => 'number', 'step' => '0.01', 'extra' => 'min="0" max="5"']) ?>
      <?= input_field('slip_line_gap', 'Distance between the lines (in)', $f2($slip['gap']), ['class' => 'col-6 col-md-3 col-xl-2', 'type' => 'number', 'step' => '0.01', 'extra' => 'min="0.1" max="2"']) ?>
      <?= input_field('sav_validation_font', 'Font size (pt)', (string)(int)$slip['font'], ['class' => 'col-6 col-md-3 col-xl-2', 'type' => 'number', 'step' => '1', 'extra' => 'min="6" max="16"']) ?>
    </div>
    <div class="slip-preview mt-3" aria-hidden="true"><div class="slip-preview-paper" data-slip-preview>
      <div class="slip-preview-text">0000012    01100001011    10/19/2026  14:05<br>Santos, Maria    CSD    1,500.00    3</div></div></div>
    <button class="btn btn-outline-primary btn-sm mt-2"><i class="bi bi-check-lg"></i> Save the margins</button>
  </div></form>
  <script>
  (function () {
    // the preview follows the margins as they are typed (an 8 x 3.5 inch slip drawn at 60 px to the inch)
    const f = document.getElementById('slipForm'); if (!f) return;
    const p = f.querySelector('[data-slip-preview]'), t = p.querySelector('.slip-preview-text');
    const v = (n) => Math.max(0, parseFloat((f.elements[n] || {}).value || '0') || 0) * 60;
    const draw = () => { t.style.paddingTop = v('slip_margin_top') + 'px'; t.style.paddingRight = v('slip_margin_right') + 'px'; t.style.paddingBottom = v('slip_margin_bottom') + 'px';
      t.style.paddingLeft = v('slip_margin_left') + 'px'; t.style.lineHeight = Math.max(6, v('slip_line_gap')) + 'px'; t.style.fontSize = Math.max(6, parseFloat(f.elements.sav_validation_font.value || '10')) * 60 / 72 + 'px'; };
    f.addEventListener('input', draw); draw();
  })();
  </script>
<?php endif; ?>
<?php
layout_end();
