<?php
/**
 * The import pages of Water Billing (Batch Share Capital Billing, One-Time Billing charges): the file (CSV or Excel)
 * is read into rows, every row is checked and shown for review (a row with a problem in red, its cells correctable,
 * any row removable), checked again as it is corrected, and posted only when no row has a problem: every row or none.
 * The rows travel as JSON, so a file of thousands of rows is never cut short by PHP's max_input_vars.
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/businessRequestGuard.php';

if (!function_exists('ir_answer')) {
    /** Sends a JSON answer and stops */
    function ir_answer(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

if (!function_exists('ir_read_file')) {
    /** The rows of the uploaded file (field "file"): a CSV file, or the first sheet of an Excel workbook. [rows, file name] */
    function ir_read_file(array $cols, array $aliases): array
    {
        $up = $_FILES['file'] ?? null;
        if (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            fail('Choose the file to import (CSV or Excel).');
        }
        if ($up['error'] !== UPLOAD_ERR_OK) {
            fail('The file could not be uploaded' . (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': it is too large.' : '.'));
        }
        if ((int)($up['size'] ?? 0) > IR_MAX_FILE_BYTES) {
            fail('The file is larger than ' . (IR_MAX_FILE_BYTES >> 20) . ' MB: split it into smaller files.');
        }
        $name = (string)$up['name'];
        if (preg_match('/\.xlsx$/i', $name)) {
            $text = XlsxReader::csvText((string)$up['tmp_name'], CsvReview::MAX_ROWS + 50);
        } elseif (preg_match('/\.xls$/i', $name)) {
            fail('Old Excel files (.xls) are not read. In Excel: File > Save As > Excel Workbook (.xlsx) or CSV (Comma delimited).');
        } elseif (preg_match('/\.(csv|txt|tsv)$/i', $name)) {
            $fh = Importer::openAsUtf8((string)$up['tmp_name']);
            $text = (string)stream_get_contents($fh);
            fclose($fh);
        } else {
            fail('Choose a CSV file (.csv) or an Excel workbook (.xlsx).');
        }
        return [CsvReview::readText($text, $cols, $aliases), mb_substr($name, 0, 190)];
    }
}

/** The largest file an import reads */
const IR_MAX_FILE_BYTES = 20 * 1024 * 1024;

if (!function_exists('ir_body')) {
    /**
     * The JSON body of a check or post request. A file larger than the server takes (post_max_size) arrives with
     * nothing in it: that is said, instead of an unknown action.
     */
    function ir_body(): array
    {
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 0 && !$_POST && !$_FILES && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) {
            fail('The file is larger than this server accepts (' . ini_get('post_max_size') . '): split it into smaller files, or save it as CSV.');
        }
        return business_request_json_body();
    }
}

if (!function_exists('ir_too_many')) {
    /** The message above the lines when a file has more lines than one posting takes; null when it does not */
    function ir_too_many(int $n, int $max): ?string
    {
        return $n > $max ? 'The file has ' . number_format($n) . ' lines: at most ' . number_format($max) . ' are posted at a time. Remove lines or split the file.' : null;
    }
}

if (!function_exists('ir_rows')) {
    /** The rows of the review as sent back (the rows removed are not there), keyed by the columns */
    function ir_rows(array $cols, array $body): array
    {
        $data = $body['rows'] ?? null;
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
            $rows[] = $row;
            if (count($rows) > CsvReview::MAX_ROWS) {
                fail('More than ' . number_format(CsvReview::MAX_ROWS) . ' rows.');
            }
        }
        return $rows;
    }
}

if (!function_exists('ir_payload')) {
    /** The rows of a check for the page: values, problems (and the cells they name), notes and what each was matched to */
    function ir_payload(array $checked, array $cols, array $extra = []): array
    {
        $rows = [];
        $bad = 0;
        foreach ($checked as $r) {
            $errs = array_values((array)($r['_errors'] ?? []));
            $bad += $errs ? 1 : 0;
            $named = [];
            foreach ((array)($r['_cells'] ?? []) as $list) {
                foreach ((array)$list as $k) {
                    $named[$k] = true;
                }
            }
            $values = [];
            foreach ($cols as $k => $_) {
                $values[$k] = (string)($r[$k] ?? '');
            }
            $rows[] = ['line' => (int)$r['_line'], 'values' => $values, 'errors' => $errs, 'notes' => array_values((array)($r['_notes'] ?? [])),
                'info' => (string)($r['_info'] ?? ''), 'cells' => array_keys($named), 'figure' => $r['_figure'] ?? null];
        }
        return ['rows' => $rows, 'bad' => $bad] + $extra;
    }
}

if (!function_exists('ir_template')) {
    /** The import template: the columns, a line of what each holds (Excel: on a second sheet) and example rows */
    function ir_template(string $base, array $cols, array $samples, string $format): never
    {
        $head = array_keys($cols);
        $rows = [];
        foreach ($samples as $s) {
            $rows[] = array_map(fn($k) => (string)($s[$k] ?? ''), $head);
        }
        if ($format === 'xlsx' && Xlsx::available()) {
            $help = [['Column', 'What it holds', 'Required']];
            foreach ($cols as $k => $c) {
                $help[] = [$k, $c['label'] . (!empty($c['hint']) ? ': ' . $c['hint'] : ''), !empty($c['req']) ? 'Yes' : ''];
            }
            (new Xlsx())->sheet('Import', array_merge([$head], $rows), [0])->sheet('Columns', $help, [0])->download($base . '.xlsx');
        }
        // a CSV opened in a spreadsheet runs a cell that starts with = + - @ as a formula: a name of the database never
        // does (a number such as -5 stays a number)
        $safe = fn(string $v) => $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v) ? "'" . $v : $v;
        csv_download($base . '.csv', $head, array_map(fn($r) => array_map($safe, $r), $rows));
    }
}
