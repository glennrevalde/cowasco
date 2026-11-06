<?php
declare(strict_types=1);

/**
 * The review of a CSV file before it is uploaded (members, savings, time deposits, batch deposits and withdrawals,
 * inventory items): the file is read into a grid of rows the user can correct or remove, each row checked and its
 * problems written under it; "Check again" checks the grid as it now stands, "Upload" checks it once more and saves
 * it only when no row has a problem (all the rows or none).
 *
 * A column: key => ['label' => .., 'req' => bool, 'type' => text|date|money|num|select, 'options' => [value => label]
 * (select), 'size' => xs|sm|md|lg (width of the cell), 'more' => bool (shown under "More" of the row instead of in
 * the grid), 'hint' => text for the template note, 'extra' => bool (a detail of the full template only: in the grid
 * only when the file has it), 'group' => heading of an extra column on the page].
 * A row: the column keys, '_line' (its line in the file), and after a check '_errors' (list), '_notes' (list) and
 * '_info' (HTML: what the row was matched to, e.g. the member on file).
 *
 * The grid is sent back as one JSON field (rows_json), so a file of thousands of rows is never cut short by PHP's
 * limit on the number of form fields (max_input_vars).
 */
final class CsvReview
{
    public const MAX_ROWS = 5000;

    /** The column keys of the file read last (the columns it has), for an import that changes only those */
    public static array $lastHeader = [];

    /** Header as typed in a spreadsheet ("Member Code", "member-code", "MEMBER_CODE *") to the column key */
    public static function headerKey(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        $h = strtolower(trim($h, " \t*\"'"));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h);
        return trim((string)$h, '_');
    }

    /** Rows of an uploaded CSV file (field $field), keyed by the column keys; other columns of the file are kept as they are named */
    public static function readUpload(array $cols, array $aliases = [], string $field = 'file'): array
    {
        $up = $_FILES[$field] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            fail('Choose the CSV file.');
        }
        if ($up['error'] !== UPLOAD_ERR_OK) {
            fail('The file could not be uploaded' . (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': it is too large.' : '.'));
        }
        if (!preg_match('/\.(csv|txt|tsv)$/i', (string)$up['name'])) {
            fail('Choose a CSV file (.csv). In Excel: File > Save As > CSV (Comma delimited).');
        }
        $fh = Importer::openAsUtf8((string)$up['tmp_name']);
        $text = (string)stream_get_contents($fh);
        fclose($fh);
        return self::readText($text, $cols, $aliases);
    }

    /** Rows of a CSV text (comma, semicolon or tab), keyed by the column keys */
    public static function readText(string $text, array $cols, array $aliases = []): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            fail('The file is empty.');
        }
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $text);
        rewind($fh);
        $first = (string)strtok($text, "\n");
        $delim = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
        $head = fgetcsv($fh, 0, $delim, '"', '');
        $map = [];
        $known = 0;
        foreach ((array)$head as $i => $h) {
            $k = self::headerKey((string)$h);
            $k = $aliases[$k] ?? $k;
            if ($k === '') {
                continue;
            }
            $map[$i] = $k;
            $known += isset($cols[$k]) ? 1 : 0;
        }
        self::$lastHeader = array_values(array_filter($map, fn($k) => isset($cols[$k])));
        if (!$known) {
            fclose($fh);
            fail('The first line of the file must hold the column names (' . implode(', ', array_keys($cols)) . '). Download the template to see them.');
        }
        $missing = array_keys(array_filter($cols, fn($c) => !empty($c['req']) && empty($c['filled'])));
        $missing = array_values(array_diff($missing, $map));
        if ($missing) {
            fclose($fh);
            fail('The file has no column ' . implode(', ', $missing) . '. Download the template to see the columns.');
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || !array_filter(array_map(fn($v) => trim((string)$v), $r), fn($v) => $v !== '')) {
                continue;
            }
            $row = ['_line' => $line];
            foreach ($cols as $k => $_) {
                $row[$k] = '';
            }
            foreach ($map as $i => $k) {
                $row[$k] = trim((string)($r[$i] ?? ''));
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fclose($fh);
                fail('The file has more than ' . number_format(self::MAX_ROWS) . ' rows. Split it into smaller files.');
            }
        }
        fclose($fh);
        if (!$rows) {
            fail('The file holds no data lines: the first line is the header, one row per line after it.');
        }
        return $rows;
    }

    /** The rows of the grid as sent back (the rows removed are not there) */
    public static function posted(array $cols): array
    {
        $data = json_decode((string)($_POST['rows_json'] ?? ''), true);
        if (!is_array($data)) {
            fail('The rows of the review were not received. Read the file again.');
        }
        $rows = [];
        foreach ($data as $r) {
            if (!is_array($r)) {
                continue;
            }
            $row = ['_line' => (int)($r['_line'] ?? 0)];
            foreach ($cols as $k => $_) {
                $row[$k] = trim((string)($r[$k] ?? ''));
            }
            // the columns of the file that are not edited in the grid (kept as they were read)
            foreach ((array)($r['_keep'] ?? []) as $k => $v) {
                if (is_string($k) && $k !== '' && $k[0] !== '_' && !isset($row[$k]) && (is_string($v) || is_numeric($v))) {
                    $row[$k] = trim((string)$v);
                }
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) {
                fail('More than ' . number_format(self::MAX_ROWS) . ' rows.');
            }
        }
        return $rows;
    }

    /** Sends a CSV template: the header, a note row of what each column holds, and example rows */
    public static function template(string $fileName, array $cols, array $samples): never
    {
        $head = array_keys($cols);
        $rows = [];
        foreach ($samples as $s) {
            $rows[] = array_map(fn($k) => (string)($s[$k] ?? ''), $head);
        }
        csv_download($fileName, $head, $rows);
    }

    /** The columns of the full template only (the other details a file may add) */
    public static function extras(array $cols): array
    {
        return array_filter($cols, fn($c) => !empty($c['extra']));
    }

    /** What the columns are, for the page: the required ones in bold, with their hints; the full template's under their headings */
    public static function columnHelp(array $cols): string
    {
        $row = fn(string $k, array $c) => '<div><dt>' . (!empty($c['req']) ? '<strong>' . h($k) . '</strong>' : h($k)) . '</dt><dd>' . h($c['label']) . (!empty($c['hint']) ? '<span>' . h($c['hint']) . '</span>' : '') . '</dd></div>';
        $out = '<dl class="csvr-cols">';
        foreach ($cols as $k => $c) {
            if (empty($c['extra'])) {
                $out .= $row($k, $c);
            }
        }
        $out .= '</dl>';
        $extras = self::extras($cols);
        if ($extras) {
            $groups = [];
            foreach ($extras as $k => $c) {
                $groups[$c['group'] ?? 'Other details'][$k] = $c;
            }
            $out .= '<details class="csvr-more-cols"><summary>The full template: ' . count($extras) . ' more columns, every other detail of the record</summary>'
                . '<p class="text-muted mb-2">Keep only the columns you have and delete the others; a column left out or left blank is simply not filled in.</p>';
            foreach ($groups as $g => $list) {
                $out .= '<div class="csvr-cols-group">' . h($g) . '</div><dl class="csvr-cols">';
                foreach ($list as $k => $c) {
                    $out .= $row($k, $c);
                }
                $out .= '</dl>';
            }
            $out .= '</details>';
        }
        return $out;
    }

    /** The columns the review shows: every column except the details of the full template no row fills */
    public static function shown(array $cols, array $rows): array
    {
        // the columns a problem names are shown too, so the missing value can be typed in
        $named = [];
        foreach ($rows as $r) {
            if (!empty($r['_errors'])) {
                $named += self::named((array)$r['_errors'], $cols, (array)($r['_cells'] ?? []));
            }
        }
        return array_filter($cols, function ($c, $k) use ($rows, $named) {
            if (empty($c['extra']) || isset($named[$k])) {
                return true;
            }
            foreach ($rows as $r) {
                if (trim((string)($r[$k] ?? '')) !== '') {
                    return true;
                }
            }
            return false;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The review grid. $opt: 'total' => column key added up in the summary (money), 'total_label' => its name, 'form' => id of the form the grid
     * belongs to, 'noun' => [singular, plural], 'keep' => the extra columns of the file kept with each row.
     */
    public static function grid(array $cols, array $rows, array $opt = []): string
    {
        $noun = $opt['noun'] ?? ['row', 'rows'];
        $grid = array_filter($cols, fn($c) => empty($c['more']));
        $more = array_filter($cols, fn($c) => !empty($c['more']));
        $bad = count(array_filter($rows, fn($r) => !empty($r['_errors'])));
        $total = $opt['total'] ?? null;
        $sum = '0';
        foreach ($rows as $r) {
            if ($total !== null) {
                try {
                    $sum = Dec::add($sum, Dec::of(($r[$total] ?? '') === '' ? '0' : $r[$total]), 2);
                } catch (UserError $e) {
                }
            }
        }
        $o = '<div class="csvr" data-csvr data-noun="' . h($noun[0]) . '" data-nouns="' . h($noun[1]) . '"' . ($total !== null ? ' data-total="' . h($total) . '"' : '') . '>';
        $o .= '<div class="csvr-bar">'
            . '<div class="csvr-counts"><span class="csvr-count"><strong data-csvr-n>' . count($rows) . '</strong> <span data-csvr-noun>' . h(count($rows) === 1 ? $noun[0] : $noun[1]) . '</span></span>'
            . '<span class="csvr-count is-bad"' . ($bad ? '' : ' hidden') . ' data-csvr-badwrap><strong data-csvr-bad>' . $bad . '</strong> with a problem</span>'
            . '<span class="csvr-count is-edit" hidden data-csvr-editwrap><strong data-csvr-edited>0</strong> changed, not checked yet</span>'
            . ($total !== null ? '<span class="csvr-count">' . h($opt['total_label'] ?? 'Total') . ' <strong data-csvr-total>' . h(Dec::money($sum)) . '</strong></span>' : '')
            . '<span class="csvr-count is-muted" hidden data-csvr-removedwrap><strong data-csvr-removed>0</strong> removed <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-csvr-undo>Put back the last</button></span></div>'
            . '<div class="csvr-tools"><input type="search" class="form-control form-control-sm" placeholder="Find in the rows" aria-label="Find in the rows" data-csvr-find>'
            . '<div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="csvrBad" data-csvr-badonly' . ($bad ? '' : ' disabled') . '><label class="form-check-label" for="csvrBad">Only rows with a problem</label></div>'
            . '<button type="button" class="btn btn-sm btn-outline-danger" data-csvr-removebad' . ($bad ? '' : ' hidden') . '><i class="bi bi-trash3"></i> Remove the rows with a problem</button>'
            . '<span class="csvr-checking" data-csvr-checking hidden><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Checking</span></div>'
            . '</div>';
        $o .= '<div class="csvr-scroll"><table class="csvr-table"><thead><tr><th class="csvr-no">Line</th>';
        foreach ($grid as $k => $c) {
            $o .= '<th class="csvr-c-' . h($c['size'] ?? 'md') . '">' . h($c['label']) . (!empty($c['req']) ? ' <span class="csvr-req" title="Required">*</span>' : '') . '</th>';
        }
        $o .= '<th class="csvr-act"><span class="visually-hidden">Remove</span></th></tr></thead>';
        foreach ($rows as $i => $r) {
            $errs = (array)($r['_errors'] ?? []);
            $notes = (array)($r['_notes'] ?? []);
            $state = $errs ? 'is-bad' : (array_key_exists('_errors', $r) ? 'is-ok' : '');
            $keep = [];
            foreach ($r as $k => $v) {
                if ($k !== '' && $k[0] !== '_' && !isset($cols[$k]) && (string)$v !== '') {
                    $keep[$k] = (string)$v;
                }
            }
            $o .= '<tbody class="csvr-rec ' . $state . '" data-csvr-row data-line="' . (int)$r['_line'] . '"' . ($keep ? ' data-keep="' . h(json_encode($keep, JSON_UNESCAPED_UNICODE)) . '"' : '') . '>';
            $o .= '<tr class="csvr-main"><td class="csvr-no"><span class="csvr-dot" aria-hidden="true"></span>' . (int)$r['_line'] . '</td>';
            // the cells the messages name are marked; the other columns open by themselves when one of them is named
            $named = self::named($errs, $cols, (array)($r['_cells'] ?? []));
            foreach ($grid as $k => $c) {
                $o .= '<td class="csvr-c-' . h($c['size'] ?? 'md') . '" data-label="' . h($c['label']) . '">' . self::input($k, $c, (string)($r[$k] ?? ''), isset($named[$k])) . '</td>';
            }
            $openMore = $more && array_intersect_key($more, $named);
            $o .= '<td class="csvr-act">' . ($more ? '<button type="button" class="csvr-btn' . ($openMore ? ' is-on' : '') . '" data-csvr-more aria-expanded="' . ($openMore ? 'true' : 'false') . '" title="The other columns of the row"><i class="bi bi-three-dots"></i></button>' : '')
                . '<button type="button" class="csvr-btn is-danger" data-csvr-remove title="Remove this row from the upload" aria-label="Remove line ' . (int)$r['_line'] . '"><i class="bi bi-x-lg"></i></button></td></tr>';
            if ($more) {
                $o .= '<tr class="csvr-more"' . ($openMore ? '' : ' hidden') . '><td></td><td colspan="' . (count($grid) + 1) . '"><div class="csvr-more-grid">';
                foreach ($more as $k => $c) {
                    $o .= '<label><span' . (!empty($c['hint']) ? ' title="' . h($c['hint']) . '"' : '') . '>' . h($c['label']) . (!empty($c['req']) ? ' *' : '') . '</span>' . self::input($k, $c, (string)($r[$k] ?? ''), isset($named[$k])) . '</label>';
                }
                $o .= '</div></td></tr>';
            }
            if ($errs || $notes || !empty($r['_info'])) {
                $o .= '<tr class="csvr-msg"><td></td><td colspan="' . (count($grid) + 1) . '">'
                    . (!empty($r['_info']) ? '<div class="csvr-info">' . $r['_info'] . '</div>' : '')
                    . implode('', array_map(fn($e) => '<div class="csvr-err"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' . h($e) . '</div>', $errs))
                    . implode('', array_map(fn($e) => '<div class="csvr-note"><i class="bi bi-info-circle" aria-hidden="true"></i> ' . h($e) . '</div>', $notes))
                    . '</td></tr>';
            }
            $o .= '</tbody>';
        }
        $o .= '<tbody class="csvr-none" hidden><tr><td colspan="' . (count($grid) + 2) . '">No row matches.</td></tr></tbody>';
        $o .= '</table></div><input type="hidden" name="rows_json" value="" data-csvr-json></div>';
        return $o;
    }

    /** One cell of the grid */
    /** The columns a row's messages name, by their heading or column name ("Enter the barangay.", ... ("opening_site")) */
    /**
     * The cells a row's messages name. $cells (the row's _cells) maps the position of a message to the columns the
     * check names itself; any other message names the columns whose label (or key) it uses.
     */
    private static function named(array $errs, array $cols, array $cells = []): array
    {
        $out = [];
        foreach (array_values($errs) as $i => $e) {
            if (isset($cells[$i])) {
                foreach ((array)$cells[$i] as $k) {
                    if (isset($cols[$k])) {
                        $out[$k] = true;
                    }
                }
                continue;
            }
            $text = mb_strtolower((string)$e);
            foreach ($cols as $k => $c) {
                $label = mb_strtolower(trim((string)preg_replace('/\s*\(.*$/', '', (string)$c['label'])));
                if ((str_contains($k, '_') && str_contains($text, mb_strtolower($k))) || ($label !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($label, '/') . '(?![\pL\pN])/u', $text))) {
                    $out[$k] = true;
                }
            }
        }
        return $out;
    }

    private static function input(string $k, array $c, string $v, bool $bad = false): string
    {
        $type = $c['type'] ?? 'text';
        $a = ' data-k="' . h($k) . '" aria-label="' . h($c['label']) . '"' . ($bad ? ' aria-invalid="true"' : '');
        if ($type === 'select') {
            $opts = (array)($c['options'] ?? []);
            $html = '<select class="form-select form-select-sm' . ($bad ? ' is-invalid' : '') . '" data-no-combo' . $a . '>';
            $found = $v === '';
            $html .= '<option value=""></option>';
            foreach ($opts as $ov => $ol) {
                $sel = strcasecmp((string)$ov, $v) === 0;
                $found = $found || $sel;
                $html .= '<option value="' . h((string)$ov) . '"' . ($sel ? ' selected' : '') . '>' . h((string)$ol) . '</option>';
            }
            if (!$found) {
                // a value of the file that is not on the list stays (the check says what is wrong with it)
                $html .= '<option value="' . h($v) . '" selected>' . h($v) . '</option>';
            }
            return $html . '</select>';
        }
        $inv = $bad ? ' is-invalid' : '';
        $extra = match ($type) {
            'money', 'num' => ' inputmode="decimal" class="form-control form-control-sm text-end' . $inv . '"',
            'date' => ' class="form-control form-control-sm' . $inv . '" placeholder="YYYY-MM-DD"',
            default => ' class="form-control form-control-sm' . $inv . '"',
        };
        return '<input type="text" value="' . h($v) . '"' . $extra . $a . ' autocomplete="off" spellcheck="false">';
    }

    // ------------------------------------------------------------------ the page of an upload

    /**
     * Runs the steps of an upload page: the template, reading the file, checking the grid again, uploading.
     * $cfg: cols, aliases, noun, total, template_name, samples, opt (fn(): array of the page's choices, read from the
     * post), check (fn(rows, opt): [rows, summary, general]), post (fn(rows, opt, fileName): string message),
     * can_post (bool), done (URL after the upload). Returns the state to render: rows, summary, general, file, step.
     */
    public static function handle(array $cfg): array
    {
        if (g('t') === 'template') {
            $full = g('set') === 'full' && self::extras($cfg['cols']);
            self::template($full ? preg_replace('/\.csv$/', '_full.csv', $cfg['template_name']) : $cfg['template_name'],
                $full ? $cfg['cols'] : array_filter($cfg['cols'], fn($c) => empty($c['extra'])), $cfg['samples']);
        }
        $state = ['rows' => [], 'summary' => null, 'general' => [], 'file' => '', 'step' => ''];
        if (!is_post()) {
            return $state;
        }
        $step = (string)p('step');
        $state['step'] = $step;
        if ($step === 'check' && ($_SERVER['HTTP_X_CSVR_CHECK'] ?? '') === '1' && session_status() === PHP_SESSION_ACTIVE) {
            // the check as you type writes nothing to the session: it lets the next request through at once
            session_write_close();
        }
        $state['file'] = mb_substr(trim((string)p('file_name')), 0, 190);
        try {
            $opt = ($cfg['opt'])();
            if ($step === 'read') {
                $rows = self::readUpload($cfg['cols'], $cfg['aliases'] ?? []);
                $state['file'] = mb_substr((string)($_FILES['file']['name'] ?? ''), 0, 190);
            } else {
                $rows = self::posted($cfg['cols']);
            }
            [$state['rows'], $state['summary'], $state['general']] = ($cfg['check'])($rows, $opt);
            if ($step === 'check' && ($_SERVER['HTTP_X_CSVR_CHECK'] ?? '') === '1') {
                self::answer($cfg, $state);
            }
            if ($step === 'post') {
                if (empty($cfg['can_post'])) {
                    fail($cfg['no_post'] ?? 'You may check the file, but uploading it needs the Approve right on this page.');
                }
                if (!$state['rows']) {
                    fail('There is no row to upload.');
                }
                // once only: a refresh, Back or a second tab never uploads or posts the same rows again
                Coop::consumeOnce();
                try {
                    $msg = ($cfg['post'])($rows, $opt, $state['file']);
                } catch (PDOException $e) {
                    // a value the database would not take: nothing is saved, and the rows come back to be corrected
                    if (DB::inTransaction()) {
                        DB::rollBack();
                    }
                    error_log('CSV upload: ' . $e->getMessage());
                    fail('Nothing was saved: the database refused a value of the file (' . preg_replace('/^SQLSTATE\[\w+\]: [^:]*: \d+ /', '', $e->getMessage()) . '). Correct the row and upload again.');
                }
                flash('success', $msg);
                redirect($cfg['done']);
            }
        } catch (UserError $e) {
            if (DB::inTransaction()) {
                DB::rollBack();
            }
            if ($step === 'check' && ($_SERVER['HTTP_X_CSVR_CHECK'] ?? '') === '1') {
                self::answer($cfg, ['rows' => [], 'summary' => null, 'general' => [$e->getMessage()]]);
            }
            $GLOBALS['FORM_ERROR'] = $e->getMessage();
            if ($step === 'read') {
                $state['rows'] = [];
            } elseif (!$state['rows'] && $step !== 'read') {
                try {
                    $state['rows'] = self::posted($cfg['cols']);
                } catch (UserError $e2) {
                    $state['rows'] = [];
                }
            }
        }
        return $state;
    }

    /** What the status line says of the rows in red (the same words as assets/js/csv_review.js) */
    private static function badText(int $bad): string
    {
        return match (true) {
            $bad === 0 => 'Correct the problem above the rows.',
            $bad === 1 => 'The row in red has a problem: correct it (it is checked again as you type) or remove it.',
            default => 'The ' . $bad . ' rows in red have a problem: correct them (they are checked again as you type) or remove them.',
        };
    }

    /** The checker's answer: each row's state, messages and the cells they name, by its line in the file (JSON) */
    private static function answer(array $cfg, array $state): never
    {
        $rows = [];
        foreach ($state['rows'] as $r) {
            $errs = array_values((array)($r['_errors'] ?? []));
            $rows[] = ['line' => (int)$r['_line'], 'bad' => (bool)$errs, 'errors' => $errs, 'notes' => array_values((array)($r['_notes'] ?? [])), 'info' => (string)($r['_info'] ?? ''),
                'named' => array_keys(self::named($errs, $cfg['cols'], (array)($r['_cells'] ?? [])))];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['rows' => $rows, 'general' => array_values($state['general'])], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** The body of an upload page: the file and the choices first; then the review grid */
    public static function render(array $cfg, array $state): string
    {
        $noun = $cfg['noun'] ?? ['row', 'rows'];
        // the columns of the grid may depend on the file (an item import shows the columns the file has)
        if (isset($cfg['grid_cols']) && $cfg['grid_cols'] instanceof Closure && $state['rows']) {
            $cfg['cols'] = ($cfg['grid_cols'])();
        }
        if (isset($cfg['fields']) && $cfg['fields'] instanceof Closure) {
            $cfg['fields'] = ($cfg['fields'])();
        }
        $o = '';
        $tpl = current_url(['t' => 'template']);
        if (!$state['rows']) {
            $o .= '<div class="row g-3 csvr-start"><div class="col-xl-7"><form method="post" enctype="multipart/form-data" class="card csvr-step" data-csvr-read>' . csrf_field()
                . '<input type="hidden" name="step" value="read"><div class="card-header">1. The file</div><div class="card-body">'
                . (!empty($cfg['intro']) ? '<p class="small text-muted">' . $cfg['intro'] . '</p>' : '')
                . '<div class="row g-2">' . ($cfg['fields'] ?? '') . '<div class="col-12"><label class="form-label" for="csvrFile">CSV file</label>'
                . '<input class="form-control" type="file" id="csvrFile" name="file" accept=".csv,.txt,.tsv,text/csv" required></div></div>'
                . '<div class="csvr-step-foot"><button class="btn btn-primary"><i class="bi bi-table"></i> Read the file for review</button>'
                . '<a class="btn btn-outline-secondary" href="' . h($tpl) . '"><i class="bi bi-download"></i> Download the CSV template</a>'
                . (self::extras($cfg['cols']) ? '<a class="btn btn-outline-secondary" href="' . h(current_url(['t' => 'template', 'set' => 'full'])) . '" title="Every detail of the record as a column: keep the ones you have"><i class="bi bi-file-earmark-spreadsheet"></i> Full template (every detail)</a>' : '')
                . '</div>'
                . '<div class="small text-muted mt-2">Nothing is saved yet: every row is shown for review first, where it can be corrected or removed, then uploaded.</div>'
                . '</div></form></div>'
                . '<div class="col-xl-5"><div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>The columns</span><a class="small" href="' . h($tpl) . '">Template</a></div><div class="card-body small">'
                . self::columnHelp($cfg['cols']) . '<div class="text-muted mt-2">Required columns in bold. The first line of the file holds the column names; one ' . h($noun[0]) . ' per line after it. '
                . 'Save from Excel as CSV (Comma delimited).' . (!empty($cfg['help']) ? ' ' . $cfg['help'] : '') . '</div></div></div></div></div>';
            return $o;
        }
        $sum = $state['summary'] ?? ['rows' => count($state['rows']), 'bad' => 0];
        $o .= '<form method="post" class="csvr-form" data-csvr-form>' . csrf_field() . Coop::onceField() . '<input type="hidden" name="file_name" value="' . h($state['file']) . '">';
        $o .= '<div class="card csvr-head mb-3"><div class="card-body"><div class="csvr-head-row"><div class="csvr-file"><i class="bi bi-filetype-csv" aria-hidden="true"></i><div><strong>' . h($state['file'] ?: 'The file') . '</strong>'
            . '<div class="small text-muted">Correct a cell by typing in it (the row is checked again as you type), remove a row with <i class="bi bi-x-lg"></i>. ' . h($cfg['post_label'] ?? 'Upload') . ' saves every row, or none if a row still has a problem.</div></div></div></div>'
            . (!empty($cfg['fields']) ? '<div class="row g-2 mt-1">' . $cfg['fields'] . '</div>' : '') . '</div></div>';
        $o .= '<div class="alert alert-danger small csvr-general" data-csvr-general' . ($state['general'] ? '' : ' hidden') . '>' . implode('<br>', array_map('h', $state['general'])) . '</div>';
        $o .= self::grid(self::shown($cfg['cols'], $state['rows']), $state['rows'], ['noun' => $noun, 'total' => $cfg['total'] ?? null, 'total_label' => $cfg['total_label'] ?? 'Total']);
        $bad = (int)($sum['bad'] ?? 0);
        $o .= isset($cfg['extra']) && $cfg['extra'] instanceof Closure ? ($cfg['extra'])() : ($cfg['extra'] ?? '');
        $o .= '<div class="csvr-actions"><div class="csvr-actions-msg" data-csvr-status>' . ($bad || $state['general'] ? '<span class="text-danger"><i class="bi bi-exclamation-circle"></i> ' . self::badText($bad) . '</span>'
            : '<span class="text-success"><i class="bi bi-check2-circle"></i> Every row is ready.</span>') . '</div>'
            . '<div class="csvr-actions-btns"><a class="btn btn-outline-secondary" href="' . h(current_url()) . '" title="Start over with another file" aria-label="Start over"><i class="bi bi-arrow-counterclockwise"></i><span class="csvr-so"> Start over</span></a>'
            . '<button class="btn btn-outline-primary" name="step" value="check"><i class="bi bi-list-check"></i> Check again</button>'
            . (!empty($cfg['can_post']) ? '<button class="btn btn-primary" name="step" value="post" data-csvr-upload data-confirm="' . h($cfg['confirm'] ?? 'Upload the rows?') . '" data-confirm-ok="' . h($cfg['post_label'] ?? 'Upload') . '"><i class="bi bi-' . h($cfg['post_icon'] ?? 'cloud-arrow-up') . '"></i> <span>' . h($cfg['post_label'] ?? 'Upload') . ' <span data-csvr-upn>' . count($state['rows']) . '</span> <span data-csvr-upnoun>' . h(count($state['rows']) === 1 ? $noun[0] : $noun[1]) . '</span></span></button>'
                : '<span class="small text-muted">' . h($cfg['no_post'] ?? 'Uploading needs the Approve right on this page.') . '</span>')
            . '</div></div></form>';
        return $o;
    }

    // ------------------------------------------------------------------ helpers of the checks

    /** A date of the file: YYYY-MM-DD, MM/DD/YYYY, 12-Mar-2024 ... ; null when it is not a date */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $v, $m)) {
            $y = (int)$m[3];
            $y = $y < 100 ? ($y > (int)date('y') + 1 ? 1900 + $y : 2000 + $y) : $y;
            return checkdate((int)$m[1], (int)$m[2], $y) ? sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]) : null;
        }
        $t = strtotime($v);
        return $t === false ? null : date('Y-m-d', $t);
    }

    /**
     * A date of the file in a form a person or a spreadsheet writes it: 2027-01-31, 2027/01/31, 01/31/2027, 01/31/27,
     * 01-31-2027, 31-Jan-2027, 31 Jan 2027, Jan 31, 2027, January 31, 2027. Unlike date(), nothing else is guessed
     * ("x", "2027" or "Jan" are not dates); null when it is not one of these.
     */
    public static function dateStrict(string $v): ?string
    {
        $v = trim(preg_replace('/\s+/', ' ', $v));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $v) || preg_match('#^\d{1,2}/\d{1,2}/\d{2,4}$#', $v)) {
            return self::date($v);
        }
        if (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $v, $m)) {
            return checkdate((int)$m[1], (int)$m[2], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
        }
        if (preg_match('/^(\d{1,2})[- ]([A-Za-z]{3,9})\.?[- ](\d{4})$/', $v, $m)) {
            [$d, $mon, $y] = [(int)$m[1], $m[2], (int)$m[3]];
        } elseif (preg_match('/^([A-Za-z]{3,9})\.? (\d{1,2}),? (\d{4})$/', $v, $m)) {
            [$mon, $d, $y] = [$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $full = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];
        $mon = strtolower($mon);
        $mn = $months[$mon] ?? ((($i = array_search($mon, $full, true)) !== false) ? $i + 1 : 0);
        return $mn && checkdate($mn, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mn, $d) : null;
    }

    /** An amount of the file ("12,500.00", "₱ 1,000", "(250.00)"); null when it is not a number */
    public static function amount(string $v): ?string
    {
        $v = trim(str_replace([',', '₱', 'PHP', 'Php', ' '], '', $v));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\((.+)\)$/', $v, $m)) {
            $v = '-' . $m[1];
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            return null;
        }
        return Dec::round($v, 2);
    }

    /** True when $given names the person on file: every word of the last and first name on file is in it, or every word of it is in the name on file */
    public static function nameMatches(string $given, string $last, string $first, string $full): bool
    {
        $words = fn(string $s) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [], fn($w) => $w !== ''));
        $g = $words($given);
        if (!$g) {
            return false;
        }
        // every word of the last name and the first word of the first name, in any order ("Dela Cruz, Juan S.",
        // "Juan S. Dela Cruz"); a first name may be shortened ("Ma." for Maria)
        $has = fn(string $need, bool $short) => in_array($need, $g, true)
            || ($short && (bool)array_filter($g, fn($w) => mb_strlen($w) >= 2 && mb_strlen($w) < mb_strlen($need) && str_starts_with($need, $w)));
        $lastWords = $words($last);
        $firstWords = $words($first);
        if (!$lastWords && !$firstWords) {
            $lastWords = $words($full);
        }
        foreach ($lastWords as $w) {
            if (!$has($w, false)) {
                return false;
            }
        }
        return !$firstWords || $has($firstWords[0], true);
    }

}
