<?php
/**
 * Read-only staging and controlled import helpers for the approved company source files.
 *
 * Uploading/staging never writes to operational member, billing, payment, or
 * accounting tables. Final writes happen only through explicit chunk actions.
 */

require_once __DIR__ . '/../process/sysSequenceHelpers.php';
require_once __DIR__ . '/memberAddressHelpers.php';
require_once __DIR__ . '/dataImportMappingHelpers.php';
require_once __DIR__ . '/billingHelpers.php';
require_once __DIR__ . '/../process/collectionAccountingHelpers.php';
$userProfileHelper = __DIR__ . '/userProfileHelpers.php';
if (is_file($userProfileHelper)) {
    require_once $userProfileHelper;
}
$discountEnrollmentHelper = __DIR__ . '/discountEnrollmentHelpers.php';
if (is_file($discountEnrollmentHelper)) {
    require_once $discountEnrollmentHelper;
}

if (!defined('DI_MAX_UPLOAD_BYTES')) {
    define('DI_MAX_UPLOAD_BYTES', 50 * 1024 * 1024);
}

if (!function_exists('di_account_headers')) {
    function di_account_headers(): array
    {
        return [
            'Account No', 'Zone', 'Reading Day', 'Sequence No', 'Account Name',
            'Discount Volume', 'Discount Flag', 'Reading Flag', 'Account Address',
            'Rate Code', 'Meter Number', 'Present Reading', 'Previous Reading',
            'Previous Rdg Date', 'Meter Reading Date', 'Installment Start Date',
            'Inst Total Amt', 'Mo Inst Amt', 'Inst Amt Balance', 'Active Status Flag',
            'OR Flag', 'Usage', 'Bill Amount', 'Share Capital Bal', 'Membership Fee',
            'Other Fees', 'Installation Fee', 'Water Meter', 'Fittings Fee',
            'Fittings Fee Balance', 'Recon Fee', 'Billing Flag', 'Total Credit',
            'Credit Balance', 'Monthly Amortization', 'Pay Amortization', 'RateKWHR',
            'AmountBM', 'Credit Balance1', 'Monthly Amortization1', 'SeniorMaster',
            'SeniorDisNo', 'Funos', 'FunosBal', 'FunosPrev', 'cellno', 'SMSDate',
            'sc50Flag', 'TermLoan', 'DateLoan', 'DamayanExemptFlag',
        ];
    }
}

if (!function_exists('di_billing_headers')) {
    function di_billing_headers(): array
    {
        return [
            'Account No', 'Receipt Number', 'Pres Rdg Date', 'Prev Rdg Date',
            'Due Date', 'Penalty Date', 'Disconnection', 'Present', 'Previous',
            'Usage', 'Rate Code', 'Bill Amount', 'Penalty', 'Installation Fee',
            'Meter Instal Amt', 'Compromise Amt', 'Recon Fee', 'Date Paid',
            'Collector', 'Fittings Fee', 'Total', 'Cash Rcvd', 'Amt Paid',
            'Cash Change', 'Payment Flag', 'Receipt Flag', 'Share Capital',
            'Membership Fee', 'Other Fees', 'Water Meter', 'Senior', 'SeniorDisNo',
            'Funos', 'FunosBal', 'FunosPrev', 'SpecialAccountFlag',
            'MonthlyAmortization', 'DamayanContribution',
        ];
    }
}

if (!function_exists('di_json_encode')) {
    function di_json_encode($value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new RuntimeException('Unable to encode staged import data.');
        }
        return $json;
    }
}

if (!function_exists('di_json_decode')) {
    function di_json_decode(?string $value, array $fallback = []): array
    {
        if ($value === null || trim($value) === '') return $fallback;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }
}

if (!function_exists('di_table_exists')) {
    function di_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('di_column_exists')) {
    function di_column_exists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('di_column_character_length')) {
    function di_column_character_length(PDO $pdo, string $table, string $column): ?int
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $length = $stmt->fetchColumn();
            return $length === false || $length === null ? null : (int)$length;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('di_column_is_nullable')) {
    function di_column_is_nullable(PDO $pdo, string $table, string $column): ?bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null) return null;
            return strtoupper((string)$value) === 'YES';
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('di_schema_ready')) {
    function di_schema_ready(PDO $pdo): bool
    {
        return di_table_exists($pdo, 'data_import_batches')
            && di_table_exists($pdo, 'data_import_rows');
    }
}

if (!function_exists('di_compatibility_issues')) {
    function di_compatibility_issues(PDO $pdo): array
    {
        // the integrated system keeps the water-service accounts in water_accounts (the cooperative owns `members`)
        $requiredTables = [
            'water_accounts', 'addresses', 'memberaddress', 'addresstype', 'rate_code',
            'water_pipe_sizes', 'water_meter_members',
            'bill_codes', 'bill_periods', 'bill_readings', 'payment_transactions',
            'payment_transaction_items', 'recurring_plans', 'recurring_subscriptions',
            'recurring_bills', 'syssequence', 'operator', 'data_import_batches',
            'data_import_rows',
        ];
        $requiredColumns = [
            'water_accounts' => [
                'pkey', 'lastname', 'firstname', 'middlename', 'datecreated', 'createdby',
                'status', 'account_number', 'zone', 'meter_sequence', 'rc_id',
                'pipe_size_id', 'connection_status',
            ],
            'addresses' => [
                'pkey', 'city_id', 'housebldg', 'street', 'other_address_detail',
                'zippostal_code', 'status',
            ],
            'memberaddress' => [
                'member_key', 'address_key', 'dtaddressFrom', 'address_type_key',
                'dtaddressto', 'status',
            ],
            'addresstype' => ['pkey', 'name', 'status'],
            'rate_code' => ['rc_id', 'rc_code', 'rc_name'],
            'water_pipe_sizes' => ['pipe_size_id', 'size_label', 'diameter_inches', 'is_active'],
            'water_meter_members' => ['rc_id', 'pipe_size_id'],
            'bill_codes' => ['code_id', 'code', 'description'],
            'bill_periods' => [
                'period_id', 'bp_code', 'start_date', 'end_date', 'status', 'opened_by',
                'closed_by', 'created_at', 'updated_at', 'closed_at',
            ],
            'bill_readings' => [
                'reading_id', 'period_id', 'member_id', 'prev_reading', 'pres_reading',
                'consumption', 'is_billed', 'billing_date', 'due_date', 'term_days',
                'amount_due', 'amount_paid', 'penalty_amount', 'snapshot_version',
                'rate_code_id_snapshot', 'pipe_size_id_snapshot', 'pipe_size_snapshot',
                'rate_code_snapshot', 'branch_id', 'segment_id', 'division_id',
                'is_paid', 'bill_lifecycle_status', 'entry_mode', 'encoded_by',
                'created_at', 'updated_at', 'disconnection_days', 'disconnection_date',
            ],
            'recurring_plans' => [
                'plan_id', 'bill_code_id', 'term_days', 'status', 'disconnection_days',
            ],
            'recurring_subscriptions' => [
                'subscription_id', 'plan_id', 'member_id', 'subscription_start_date',
                'next_billing_date', 'last_billed_date', 'status', 'created_by',
                'created_at', 'updated_at',
            ],
            'recurring_bills' => [
                'bill_id', 'subscription_id', 'plan_id', 'member_id', 'bill_code_id',
                'billing_period_start', 'billing_period_end', 'bill_date', 'due_date',
                'term_days', 'total_amount', 'gross_amount', 'discount_amount',
                'amount_paid', 'penalty_amount', 'balance', 'status',
                'bill_lifecycle_status', 'revision_note', 'created_by', 'created_at',
                'updated_at', 'branch_id', 'segment_id', 'division_id',
                'disconnection_days', 'disconnection_date',
            ],
            'payment_transactions' => [
                'transaction_id', 'receipt_no', 'member_id', 'payment_type',
                'base_amount', 'penalty_amount', 'compromise_amount', 'total_paid',
                'tendered_amount', 'change_amount', 'receipt_print_count',
                'last_printed_at', 'cashier_id', 'payment_date', 'business_date',
                'created_at', 'transaction_status', 'idempotency_key', 'branch_id',
                'segment_id', 'division_id', 'journal_status', 'cash_tendered',
            ],
            'payment_transaction_items' => [
                'transaction_id', 'source_type', 'source_id', 'description', 'due_date',
                'amount', 'principal_amount', 'penalty_amount', 'balance_before',
                'balance_after', 'allocation_status', 'created_at',
            ],
            'syssequence' => ['SeqName', 'SeqCurValue', 'SeqStep', 'SeqStatus'],
            'operator' => ['pkey'],
            'data_import_batches' => [
                'batch_id', 'import_type', 'source_name', 'source_sha256',
                'stored_file_name', 'status', 'options_json', 'created_by',
            ],
            'data_import_rows' => [
                'row_id', 'batch_id', 'row_number', 'external_account_no',
                'source_key', 'raw_json', 'normalized_json', 'issues_json',
                'import_status', 'payment_status', 'recurring_status',
                'target_member_id', 'target_bill_id', 'target_payment_id',
                'target_recurring_ids_json', 'imported_at', 'updated_at',
            ],
        ];
        $issues = [];
        foreach ($requiredTables as $table) {
            if (!di_table_exists($pdo, $table)) $issues[] = 'Missing table: ' . $table;
        }
        foreach ($requiredColumns as $table => $columns) {
            if (!di_table_exists($pdo, $table)) continue;
            foreach ($columns as $column) {
                if (!di_column_exists($pdo, $table, $column)) $issues[] = 'Missing column: ' . $table . '.' . $column;
            }
        }
        if (di_table_exists($pdo, 'water_accounts')) {
            $shortNameColumns = [];
            foreach (['lastname', 'firstname'] as $column) {
                if (!di_column_exists($pdo, 'water_accounts', $column)) continue;
                $length = di_column_character_length($pdo, 'water_accounts', $column);
                if ($length !== null && $length < 100) $shortNameColumns[] = 'water_accounts.' . $column;
            }
            if ($shortNameColumns) {
                $issues[] = 'Account-name columns are too short (' . implode(', ', $shortNameColumns)
                    . '). Run database/migrations/20260904_expand_member_account_names.sql';
            }
        }
        if (di_table_exists($pdo, 'addresses')) {
            $requiredNullable = [];
            foreach (['city_id', 'zippostal_code'] as $column) {
                if (!di_column_exists($pdo, 'addresses', $column)) continue;
                if (di_column_is_nullable($pdo, 'addresses', $column) === false) {
                    $requiredNullable[] = 'addresses.' . $column;
                }
            }
            if ($requiredNullable) {
                $issues[] = 'Source-only address columns must allow missing structured values ('
                    . implode(', ', $requiredNullable)
                    . '). Run database/migrations/20260904_allow_source_only_member_addresses.sql';
            }
        }
        if (di_table_exists($pdo, 'addresstype')) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM addresstype WHERE pkey=1 AND status='A'");
                if ((int)$stmt->fetchColumn() <= 0) $issues[] = 'Missing active address type: Residence (pkey 1)';
            } catch (Throwable $e) {
                $issues[] = 'Unable to verify address type: Residence (pkey 1)';
            }
        }
        // SysMemberIDSeq (the account key sequence) is created on first use by sysseq_next_numeric(), so a new
        // installation without it is compatible
        return $issues;
    }
}

if (!function_exists('di_assert_compatible_schema')) {
    function di_assert_compatible_schema(PDO $pdo): void
    {
        $issues = di_compatibility_issues($pdo);
        if ($issues) {
            throw new RuntimeException('Importer prerequisites are incomplete. ' . implode('; ', $issues));
        }
    }
}

if (!function_exists('di_storage_dir')) {
    function di_storage_dir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'imports';
    }
}

if (!function_exists('di_utf8')) {
    function di_utf8($value): string
    {
        $value = (string)($value ?? '');
        if (substr($value, 0, 3) === "\xEF\xBB\xBF") {
            $value = substr($value, 3);
        }
        if ($value === '') return '';
        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        return $converted === false ? $value : $converted;
    }
}

if (!function_exists('di_normalize_account_number')) {
    function di_normalize_account_number($value): string
    {
        $value = trim(di_utf8($value));
        $value = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], '', $value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        if (substr($value, 0, 1) === "'") $value = substr($value, 1);
        if (preg_match('/^(\d+)\.0+$/', $value, $m)) $value = $m[1];
        return $value;
    }
}

if (!function_exists('di_receipt_identity')) {
    function di_receipt_identity($value): string
    {
        return strtoupper(trim(di_utf8($value)));
    }
}

if (!function_exists('di_xlsx_date_headers')) {
    function di_xlsx_date_headers(): array
    {
        return [
            'Previous Rdg Date', 'Meter Reading Date', 'Installment Start Date', 'SMSDate', 'DateLoan',
            'Pres Rdg Date', 'Prev Rdg Date', 'Due Date', 'Penalty Date', 'Disconnection', 'Date Paid',
        ];
    }
}

if (!function_exists('di_excel_serial_to_date')) {
    function di_excel_serial_to_date($value): ?string
    {
        if (!is_numeric($value)) return null;
        $serial = (float)$value;
        if (!is_finite($serial) || $serial <= 0) return null;
        // The supplied workbook uses the standard Excel 1900 date system.
        // 1899-12-30 also compensates for Excel's historical 1900 leap-year bug.
        $seconds = (int)round($serial * 86400);
        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        return $base->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('m/d/Y');
    }
}

if (!function_exists('di_xlsx_column_index')) {
    function di_xlsx_column_index(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $m)) return 0;
        $letters = strtoupper($m[1]);
        $index = 0;
        for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }
}

if (!function_exists('di_xlsx_extract_entry')) {
    function di_xlsx_extract_entry(ZipArchive $zip, string $entryName, string $suffix): string
    {
        $stream = $zip->getStream($entryName);
        if (!$stream) throw new RuntimeException('Unable to read ' . $entryName . ' from the XLSX workbook.');
        $tmp = tempnam(di_storage_dir(), 'xlsx_');
        if ($tmp === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create temporary XLSX workspace.');
        }
        $target = $tmp . $suffix;
        @unlink($tmp);
        $out = @fopen($target, 'wb');
        if (!$out) {
            fclose($stream);
            throw new RuntimeException('Unable to create temporary XLSX worksheet file.');
        }
        try {
            if (stream_copy_to_stream($stream, $out) === false) {
                throw new RuntimeException('Unable to extract XLSX worksheet data.');
            }
        } finally {
            fclose($stream);
            fclose($out);
        }
        return $target;
    }
}

if (!function_exists('di_xlsx_shared_strings')) {
    function di_xlsx_shared_strings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
        $tmp = di_xlsx_extract_entry($zip, 'xl/sharedStrings.xml', '.xml');
        $strings = [];
        $reader = new XMLReader();
        try {
            if (!$reader->open($tmp, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Unable to parse XLSX shared strings.');
            }
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
                $outer = $reader->readOuterXML();
                $node = @simplexml_load_string($outer);
                if (!$node) {
                    $strings[] = '';
                    continue;
                }
                $parts = $node->xpath('.//*[local-name()="t"]') ?: [];
                $text = '';
                foreach ($parts as $part) $text .= (string)$part;
                $strings[] = di_utf8($text);
            }
        } finally {
            $reader->close();
            @unlink($tmp);
        }
        return $strings;
    }
}

if (!function_exists('di_xlsx_rows')) {
    /** @return Generator<int,array<int,string>> */
    function di_xlsx_rows(string $path): Generator
    {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader') || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('XLSX import requires PHP extensions zip, xmlreader, and SimpleXML. Enable them in php.ini or export the workbook as CSV.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('The uploaded XLSX workbook is not readable.');
        $sheetEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetEntry = $name;
                break;
            }
        }
        if ($sheetEntry === null) {
            $zip->close();
            throw new InvalidArgumentException('The XLSX workbook has no worksheet.');
        }
        $shared = di_xlsx_shared_strings($zip);
        $tmp = di_xlsx_extract_entry($zip, $sheetEntry, '.xml');
        $zip->close();

        $reader = new XMLReader();
        $headers = [];
        $dateHeaders = array_fill_keys(di_xlsx_date_headers(), true);
        $logicalRow = 0;
        try {
            if (!$reader->open($tmp, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Unable to parse the XLSX worksheet.');
            }
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
                $outer = $reader->readOuterXML();
                $rowNode = @simplexml_load_string($outer);
                if (!$rowNode) continue;
                $cells = $rowNode->xpath('./*[local-name()="c"]') ?: [];
                $values = [];
                $maxIndex = -1;
                foreach ($cells as $cell) {
                    $attrs = $cell->attributes();
                    $ref = (string)($attrs['r'] ?? 'A1');
                    $type = (string)($attrs['t'] ?? '');
                    $index = di_xlsx_column_index($ref);
                    $maxIndex = max($maxIndex, $index);
                    $value = '';
                    if ($type === 'inlineStr') {
                        $parts = $cell->xpath('.//*[local-name()="t"]') ?: [];
                        foreach ($parts as $part) $value .= (string)$part;
                    } else {
                        $v = $cell->xpath('./*[local-name()="v"]');
                        $value = $v ? (string)$v[0] : '';
                        if ($type === 's' && $value !== '' && isset($shared[(int)$value])) $value = $shared[(int)$value];
                        elseif ($type === 'b') $value = $value === '1' ? 'TRUE' : 'FALSE';
                    }
                    $values[$index] = trim(di_utf8($value));
                }
                if ($logicalRow === 0) {
                    if ($maxIndex < 0) continue;
                    $headers = [];
                    for ($i = 0; $i <= $maxIndex; $i++) $headers[$i] = trim((string)($values[$i] ?? ''));
                    $logicalRow++;
                    yield $logicalRow => $headers;
                    continue;
                }
                if (!$headers) continue;
                $row = [];
                foreach ($headers as $i => $header) {
                    $value = (string)($values[$i] ?? '');
                    if ($value !== '' && isset($dateHeaders[$header]) && is_numeric($value)) {
                        $converted = di_excel_serial_to_date($value);
                        if ($converted !== null) $value = $converted;
                    }
                    $row[$i] = $value;
                }
                if (count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) continue;
                $logicalRow++;
                yield $logicalRow => $row;
            }
        } finally {
            $reader->close();
            @unlink($tmp);
        }
    }
}

if (!function_exists('di_xlsx_to_csv')) {
    function di_xlsx_to_csv(string $xlsxPath, string $csvPath): void
    {
        $out = @fopen($csvPath, 'wb');
        if (!$out) throw new RuntimeException('Unable to create the protected CSV staging copy.');
        $rowCount = 0;
        try {
            foreach (di_xlsx_rows($xlsxPath) as $values) {
                if (fputcsv($out, $values) === false) throw new RuntimeException('Unable to write the normalized XLSX staging copy.');
                $rowCount++;
            }
        } finally {
            fclose($out);
        }
        if ($rowCount < 2) {
            @unlink($csvPath);
            throw new InvalidArgumentException('The XLSX workbook contains no importable data rows.');
        }
    }
}

if (!function_exists('di_clean_headers')) {
    function di_clean_headers(array $headers): array
    {
        return array_map(static fn($header): string => trim(di_utf8($header)), $headers);
    }
}

if (!function_exists('di_detect_import_type')) {
    function di_detect_import_type(array $headers): string
    {
        // Headers are matched BY NAME (any column order, BOM/encoding cleaned
        // by di_clean_headers). Duplicate or missing required headers are
        // rejected explicitly instead of failing later on a wrong column.
        $headers = di_clean_headers($headers);
        $nonEmpty = array_values(array_filter($headers, static fn($h): bool => $h !== ''));
        $duplicates = array_keys(array_filter(array_count_values($nonEmpty), static fn(int $n): bool => $n > 1));
        if ($duplicates) {
            throw new InvalidArgumentException('The CSV header row repeats these column names: ' . implode(', ', $duplicates) . '. Each column must appear once.');
        }
        $set = array_flip($nonEmpty);
        $accountMissing = array_values(array_filter(di_account_headers(), static fn($h): bool => !isset($set[$h])));
        $billingMissing = array_values(array_filter(di_billing_headers(), static fn($h): bool => !isset($set[$h])));
        if (!$accountMissing) return 'account_master';
        if (!$billingMissing) return 'billing_master';

        $expected = [
            'Account Master' => di_account_headers(),
            'Billing Master' => di_billing_headers(),
        ];
        $details = [];
        foreach ($expected as $name => $candidate) {
            $missing = array_values(array_diff($candidate, $headers));
            $extra = array_values(array_diff($headers, $candidate));
            $details[] = $name . ': missing [' . implode(', ', array_slice($missing, 0, 6))
                . '] extra [' . implode(', ', array_slice($extra, 0, 6)) . ']';
        }
        throw new InvalidArgumentException(
            'The CSV headers do not match either approved company file. ' . implode(' | ', $details)
        );
    }
}

if (!function_exists('di_csv_header')) {
    function di_csv_header(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('The uploaded CSV could not be opened.');
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (!is_array($headers) || !$headers) {
                throw new InvalidArgumentException('The uploaded CSV has no readable header row.');
            }
            return di_clean_headers($headers);
        } finally {
            fclose($handle);
        }
    }
}

if (!function_exists('di_csv_rows')) {
    /** @return Generator<int,array<string,string>> */
    function di_csv_rows(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('The staged CSV could not be opened.');
        try {
            $headers = fgetcsv($handle, 0, ',', '"', '\\');
            if (!is_array($headers) || !$headers) {
                throw new InvalidArgumentException('The CSV has no header row.');
            }
            $headers = di_clean_headers($headers);
            $rowNumber = 1;
            while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $rowNumber++;
                if ($values === [null] || (count($values) === 1 && trim((string)$values[0]) === '')) {
                    continue;
                }
                $values = array_map(static fn($value): string => trim(di_utf8($value)), $values);
                if (count($values) !== count($headers)) {
                    yield $rowNumber => [
                        '__csv_error' => 'Expected ' . count($headers) . ' columns but found ' . count($values) . '.',
                    ];
                    continue;
                }
                $row = array_combine($headers, $values);
                yield $rowNumber => is_array($row) ? $row : ['__csv_error' => 'Unable to combine CSV columns.'];
            }
        } finally {
            fclose($handle);
        }
    }
}

if (!function_exists('di_date_order')) {
    /**
     * Confirmed source date convention for the current validation run
     * ('mdy' | 'dmy' | 'unresolved'). Set from the mapping rules by
     * di_validation_context(); defaults to the historically used MM/DD/YYYY.
     */
    function di_date_order(?string $set = null): string
    {
        static $order = 'mdy';
        if ($set !== null) $order = in_array($set, ['mdy', 'dmy', 'unresolved'], true) ? $set : 'mdy';
        return $order;
    }
}

if (!function_exists('di_parse_date')) {
    /**
     * Strict slash-date parser (audit F12). The text must be exactly
     * <1-2 digits>/<1-2 digits>/<4 digits>; anything appended to a numeric
     * part, a different separator, or an ambiguous convention is rejected —
     * never normalized into a different date. Calendar validity is verified
     * after the shape check (no 31 Feb), and the year must be 2000–2100.
     */
    function di_parse_date($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $iso)) {
            // Already normalized (revalidation of staged rows / XLSX serials).
            if (!checkdate((int)$iso[2], (int)$iso[3], (int)$iso[1])) return null;
            $year = (int)$iso[1];
            return ($year < 2000 || $year > 2100) ? null : $value;
        }
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) return null;
        $order = di_date_order();
        if ($order === 'unresolved') return null;
        $month = $order === 'dmy' ? (int)$m[2] : (int)$m[1];
        $day = $order === 'dmy' ? (int)$m[1] : (int)$m[2];
        $year = (int)$m[3];
        if (!checkdate($month, $day, $year)) return null;
        if ($year < 2000 || $year > 2100) return null;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('di_parse_optional_money')) {
    /**
     * Optional charge cells: a blank is zero when the confirmed blank policy
     * says so; malformed non-blank values still return null (rejected).
     * Returns [amount|null, was_blank].
     */
    function di_parse_optional_money($value, string $blankPolicy): array
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return [$blankPolicy === 'zero' ? '0.00' : null, true];
        return [di_parse_money($raw), false];
    }
}

if (!function_exists('di_parse_bool')) {
    function di_parse_bool($value): ?bool
    {
        $value = strtoupper(trim((string)($value ?? '')));
        if (in_array($value, ['TRUE', '1', 'YES', 'Y'], true)) return true;
        if (in_array($value, ['FALSE', '0', 'NO', 'N'], true)) return false;
        return null;
    }
}

if (!function_exists('di_parse_int')) {
    function di_parse_int($value): ?int
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) return null;
        return (int)$value;
    }
}

if (!function_exists('di_parse_money')) {
    function di_parse_money($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || !is_numeric($value)) return null;
        $amount = round((float)$value, 2);
        if (!is_finite($amount)) return null;
        if (abs($amount) < 0.005) $amount = 0.0;
        return number_format($amount, 2, '.', '');
    }
}

if (!function_exists('di_money_float')) {
    function di_money_float($value): float
    {
        $parsed = di_parse_money($value);
        return $parsed === null ? 0.0 : (float)$parsed;
    }
}

if (!function_exists('di_parse_name')) {
    function di_parse_name(string $value): array
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        // Account No is the authoritative member identity. Personal name fields are
        // intentionally optional because the Account Master also contains companies,
        // schools, government offices, associations, churches, and other institutions.
        // A blank account name therefore never blocks creation of the member record.
        if ($value === '') {
            return [
                'valid' => true,
                'display_name' => '',
                'lastname' => '',
                'firstname' => '',
                'middlename' => '',
                'message' => 'Account Name is blank. The member will be imported without personal-name fields.',
            ];
        }

        // Preserve single/unstructured names (which includes most institution names)
        // in lastname and leave firstname NULL. For the source's normal "LAST, FIRST"
        // convention, split at the first comma. The original full value remains in
        // normalized_json/raw_json even when a database field must be length-limited.
        $lastname = $value;
        $firstname = '';
        $middlename = '';
        if (strpos($value, ',') !== false) {
            [$left, $right] = array_map('trim', explode(',', $value, 2));
            if ($left !== '' && $right !== '') {
                $lastname = $left;
                $firstname = $right;
            }
        }

        $length = static fn(string $text): int => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $cut = static fn(string $text, int $max): string => function_exists('mb_substr')
            ? mb_substr($text, 0, $max, 'UTF-8')
            : substr($text, 0, $max);

        $message = '';
        if ($length($lastname) > 100 || $length($firstname) > 100) {
            $lastname = $cut($lastname, 100);
            $firstname = $cut($firstname, 100);
            $message = 'Account Name exceeded a member-name field and was safely length-limited for the database. The complete source name remains preserved in staging.';
        }

        return [
            'valid' => true,
            'display_name' => $value,
            'lastname' => $lastname,
            'firstname' => $firstname,
            'middlename' => $middlename,
            'message' => $message,
        ];
    }
}

if (!function_exists('di_issue')) {
    function di_issue(array &$issues, string $scope, string $severity, string $code, string $message): void
    {
        $issues[] = compact('scope', 'severity', 'code', 'message');
    }
}

if (!function_exists('di_scope_status')) {
    function di_scope_status(array $issues, string $scope): string
    {
        $hasReview = false;
        foreach ($issues as $issue) {
            if (($issue['scope'] ?? '') !== $scope) continue;
            if (($issue['severity'] ?? '') === 'error') return 'error';
            if (($issue['severity'] ?? '') === 'review') $hasReview = true;
        }
        return $hasReview ? 'review' : 'ready';
    }
}

if (!function_exists('di_upload_options')) {
    function di_upload_options(array $input): array
    {
        $rateMap = [];
        $pipeMap = [];
        foreach (['A', 'B', 'C', 'D'] as $code) {
            $tariff = trim((string)($input['tariff_map'][$code] ?? ''));
            if (preg_match('/^(\d+)\|(\d+)$/', $tariff, $matches)) {
                $rateMap[$code] = (int)$matches[1];
                $pipeMap[$code] = (int)$matches[2];
                continue;
            }
            $selectedRate = (int)($input['rate_map'][$code] ?? 0);
            if ($selectedRate > 0) $rateMap[$code] = $selectedRate;
        }
        $addressMode = (string)($input['address_mode'] ?? 'source');
        if (!in_array($addressMode, ['source', 'default', 'skip'], true)) $addressMode = 'source';

        $recurringMap = [];
        foreach (di_recurring_columns() as $column) {
            $planId = (int)($input['recurring_map'][$column] ?? 0);
            if ($planId > 0) $recurringMap[$column] = $planId;
        }

        return [
            'rate_map' => $rateMap,
            'pipe_map' => $pipeMap,
            'default_pipe_size_id' => max(0, (int)($input['default_pipe_size_id'] ?? 0)),
            'address_mode' => $addressMode,
            'default_city_id' => max(0, (int)($input['default_city_id'] ?? 0)),
            'default_zip' => max(0, min(32767, (int)($input['default_zip'] ?? 0))),
            'source_discount_id' => max(0, (int)($input['source_discount_id'] ?? 0)),
            'senior_discount_id' => max(0, (int)($input['senior_discount_id'] ?? 0)),
            // the class of everything the import posts (Chart of Accounts > Class List); branch, segment and
            // division are no longer asked
            'default_class_id' => max(0, (int)($input['default_class_id'] ?? 0)),
            'stage_payments' => !empty($input['stage_payments']),
            'recurring_map' => $recurringMap,
            // Existing operational member records are preserved. Account No is still
            // considered a valid match even when descriptive source fields differ.
            'existing_account_mode' => 'skip',
        ];
    }
}

if (!function_exists('di_batch_class_id')) {
    /** The class chosen for an import batch (its Class box), refused when missing or no longer active */
    function di_batch_class_id(PDO $pdo, int $batchId): int
    {
        if (!function_exists('water_required_class')) {
            require_once __DIR__ . '/../process/organizationUnitHelpers.php';
        }
        $stmt = $pdo->prepare('SELECT options_json FROM data_import_batches WHERE batch_id = ? LIMIT 1');
        $stmt->execute([$batchId]);
        $options = di_json_decode((string)($stmt->fetchColumn() ?: '{}'));
        return water_required_class($options['default_class_id'] ?? null, 'the import (the Class box of the batch options)');
    }
}

if (!function_exists('di_recurring_columns')) {
    function di_recurring_columns(): array
    {
        return [
            'Installation Fee', 'Meter Instal Amt', 'Recon Fee', 'Fittings Fee',
            'Share Capital', 'Membership Fee', 'Other Fees', 'Water Meter',
            'Funos', 'MonthlyAmortization', 'DamayanContribution',
        ];
    }
}

if (!function_exists('di_label_key')) {
    function di_label_key($value): string
    {
        $value = strtoupper(trim((string)$value));
        return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
    }
}

if (!function_exists('di_bill_code_accounting_readiness')) {
    /**
     * Return the accounting configuration required by the canonical billing
     * flow. Service/charge bill codes require AR (debit) + Income (credit).
     * Inventory codes additionally require Asset + COGS accounts.
     */
    function di_bill_code_accounting_readiness(array $billCode): array
    {
        $missing = [];
        $ar = (int)($billCode['ar_account'] ?? 0);
        $income = (int)($billCode['income_account'] ?? 0);
        $type = trim((string)($billCode['type'] ?? ''));

        if ($ar <= 0) {
            $missing[] = 'AR / debit account';
        } elseif (array_key_exists('ar_status', $billCode) && strtoupper(trim((string)$billCode['ar_status'])) !== 'A') {
            $missing[] = 'active AR / debit account';
        }

        if ($income <= 0) {
            $missing[] = 'Income / credit account';
        } elseif (array_key_exists('income_status', $billCode) && strtoupper(trim((string)$billCode['income_status'])) !== 'A') {
            $missing[] = 'active Income / credit account';
        }

        if (strcasecmp($type, 'Inventory') === 0) {
            $asset = (int)($billCode['asset_account'] ?? 0);
            $cogs = (int)($billCode['cogs_account'] ?? 0);
            if ($asset <= 0) {
                $missing[] = 'Inventory asset account';
            } elseif (array_key_exists('asset_status', $billCode) && strtoupper(trim((string)$billCode['asset_status'])) !== 'A') {
                $missing[] = 'active Inventory asset account';
            }
            if ($cogs <= 0) {
                $missing[] = 'COGS account';
            } elseif (array_key_exists('cogs_status', $billCode) && strtoupper(trim((string)$billCode['cogs_status'])) !== 'A') {
                $missing[] = 'active COGS account';
            }
        }

        return [
            'ready' => !$missing,
            'missing' => $missing,
            'message' => !$missing
                ? 'Accounting mapping is complete.'
                : 'Missing: ' . implode(', ', $missing) . '.',
        ];
    }
}

if (!function_exists('di_recurring_expected_aliases')) {
    function di_recurring_expected_aliases(): array
    {
        return [
            'Installation Fee' => ['INSTALLATIONFEE', 'INSTALLATION'],
            'Meter Instal Amt' => ['METERINSTALLATION', 'METERINSTALL', 'METERINSTAL'],
            'Recon Fee' => ['RECONNECTIONFEE', 'RECONFEE', 'RECONNECTION'],
            'Fittings Fee' => ['FITTINGSFEE', 'FITTINGFEE', 'FITTINGS'],
            'Share Capital' => ['SHARECAPITAL'],
            'Membership Fee' => ['MEMBERSHIPFEE', 'MEMBERSHIP'],
            'Other Fees' => ['OTHERFEES', 'OTHERFEE', 'OTHERCHARGE'],
            'Water Meter' => ['WATERMETER', 'METERFEE'],
            'Funos' => ['FUNOS'],
            'MonthlyAmortization' => ['MONTHLYAMORTIZATION', 'AMORTIZATION'],
            'DamayanContribution' => ['DAMAYAN'],
        ];
    }
}

if (!function_exists('di_recurring_plan_matches_source')) {
    function di_recurring_plan_matches_source(string $sourceColumn, array $plan): bool
    {
        $aliases = di_recurring_expected_aliases()[$sourceColumn] ?? [];
        if (!$aliases) return true;
        $haystack = di_label_key(($plan['code'] ?? '') . ' ' . ($plan['description'] ?? ''));
        foreach ($aliases as $alias) {
            if ($alias !== '' && strpos($haystack, di_label_key($alias)) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('di_recurring_plan_readiness')) {
    function di_recurring_plan_readiness(array $plan, string $sourceColumn = ''): array
    {
        $missing = [];
        if ((int)($plan['plan_id'] ?? 0) <= 0) $missing[] = 'recurring plan';
        if ((int)($plan['bill_code_id'] ?? 0) <= 0) $missing[] = 'bill code';
        if (strtolower(trim((string)($plan['status'] ?? ''))) !== 'active') $missing[] = 'active recurring plan';

        $accounting = di_bill_code_accounting_readiness($plan);
        foreach ($accounting['missing'] as $item) $missing[] = $item;

        if ($sourceColumn !== '' && !di_recurring_plan_matches_source($sourceColumn, $plan)) {
            $missing[] = 'matching bill-code identity for ' . $sourceColumn;
        }

        $missing = array_values(array_unique($missing));
        return [
            'ready' => !$missing,
            'missing' => $missing,
            'message' => !$missing
                ? 'Ready: active plan, matching bill code, AR/debit account, and Income/credit account are configured.'
                : 'Not ready: ' . implode(', ', $missing) . '.',
        ];
    }
}

if (!function_exists('di_water_bill_code')) {
    function di_water_bill_code(PDO $pdo): ?array
    {
        if (!di_table_exists($pdo, 'bill_codes')) return null;
        $billCodeId = 0;
        if (di_table_exists($pdo, 'water_billing_settings')) {
            try {
                $stmt = $pdo->prepare("SELECT setting_value FROM water_billing_settings WHERE setting_key='water_billing_bill_code_id' LIMIT 1");
                $stmt->execute();
                $billCodeId = (int)$stmt->fetchColumn();
            } catch (Throwable $ignored) {}
        }

        if ($billCodeId > 0) {
            $stmt = $pdo->prepare(
                'SELECT c.*,ar.Status ar_status,inc.Status income_status,cogs.Status cogs_status,asset.Status asset_status '
                . 'FROM bill_codes c '
                . 'LEFT JOIN chartofaccntstbl ar ON ar.AccntID=c.ar_account '
                . 'LEFT JOIN chartofaccntstbl inc ON inc.AccntID=c.income_account '
                . 'LEFT JOIN chartofaccntstbl cogs ON cogs.AccntID=c.cogs_account '
                . 'LEFT JOIN chartofaccntstbl asset ON asset.AccntID=c.asset_account '
                . 'WHERE c.code_id=? LIMIT 1'
            );
            $stmt->execute([$billCodeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        $stmt = $pdo->query(
            "SELECT c.*,ar.Status ar_status,inc.Status income_status,cogs.Status cogs_status,asset.Status asset_status "
            . "FROM bill_codes c "
            . "LEFT JOIN chartofaccntstbl ar ON ar.AccntID=c.ar_account "
            . "LEFT JOIN chartofaccntstbl inc ON inc.AccntID=c.income_account "
            . "LEFT JOIN chartofaccntstbl cogs ON cogs.AccntID=c.cogs_account "
            . "LEFT JOIN chartofaccntstbl asset ON asset.AccntID=c.asset_account "
            . "WHERE UPPER(TRIM(c.code))='WATER BILL' ORDER BY c.code_id LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('di_water_bill_readiness')) {
    function di_water_bill_readiness(PDO $pdo): array
    {
        $code = di_water_bill_code($pdo);
        if (!$code) {
            return [
                'ready' => false,
                'bill_code' => null,
                'missing' => ['Water Bill bill code'],
                'message' => 'Create/configure the Water Bill bill code before importing historical water bills.',
            ];
        }
        $accounting = di_bill_code_accounting_readiness($code);
        return [
            'ready' => (bool)$accounting['ready'],
            'bill_code' => $code,
            'missing' => $accounting['missing'],
            'message' => $accounting['ready']
                ? 'Water Bill accounting is ready: AR/debit and Income/credit accounts are configured.'
                : 'Water Bill bill code is not accounting-ready. ' . $accounting['message'],
        ];
    }
}

if (!function_exists('di_active_chart_account')) {
    function di_active_chart_account(PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0 || !di_table_exists($pdo, 'chartofaccntstbl')) return false;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chartofaccntstbl WHERE AccntID=? AND Status='A'");
        $stmt->execute([$accountId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('di_setting_account_id')) {
    function di_setting_account_id(PDO $pdo, string $settingKey): int
    {
        if (!di_table_exists($pdo, 'water_billing_settings')) return 0;
        $stmt = $pdo->prepare('SELECT setting_value FROM water_billing_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$settingKey]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('di_collection_readiness')) {
    function di_collection_readiness(PDO $pdo, int $operatorId = 0): array
    {
        $missing = [];
        $cashAccountId = 0;
        if (function_exists('uph_current_cash_account_id')) {
            try { $cashAccountId = (int)(uph_current_cash_account_id($pdo, $operatorId) ?: 0); } catch (Throwable $ignored) {}
        } else {
            $cashAccountId = (int)($_SESSION['cash_account_id'] ?? 0);
        }
        if ($cashAccountId <= 0 || !di_active_chart_account($pdo, $cashAccountId)) {
            $missing[] = 'active CashGL / collection debit account for the current operator profile';
        }

        $water = di_water_bill_readiness($pdo);
        if (!$water['ready']) $missing[] = 'accounting-ready Water Bill code';

        return [
            'ready' => !$missing,
            'cash_account_id' => $cashAccountId,
            'missing' => $missing,
            'message' => !$missing
                ? 'Collection prerequisites are configured.'
                : 'Collections are blocked until these are configured: ' . implode(', ', $missing) . '.',
        ];
    }
}

if (!function_exists('di_current_recurring_plans')) {
    function di_current_recurring_plans(PDO $pdo): array
    {
        if (!di_table_exists($pdo, 'recurring_plans') || !di_table_exists($pdo, 'bill_codes')) return [];
        $sql = 'SELECT p.plan_id,p.bill_code_id,p.frequency,p.amount,p.term_days,p.disconnection_days,p.status,'
            . 'c.code,c.description,c.type,c.ar_account,c.income_account,c.cogs_account,c.asset_account,'
            . 'ar.Status ar_status,inc.Status income_status,cogs.Status cogs_status,asset.Status asset_status '
            . 'FROM recurring_plans p INNER JOIN bill_codes c ON c.code_id=p.bill_code_id '
            . 'LEFT JOIN chartofaccntstbl ar ON ar.AccntID=c.ar_account '
            . 'LEFT JOIN chartofaccntstbl inc ON inc.AccntID=c.income_account '
            . 'LEFT JOIN chartofaccntstbl cogs ON cogs.AccntID=c.cogs_account '
            . 'LEFT JOIN chartofaccntstbl asset ON asset.AccntID=c.asset_account '
            . 'ORDER BY c.code,p.plan_id';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $readiness = di_recurring_plan_readiness($row);
            $row['migration_ready'] = $readiness['ready'] ? 1 : 0;
            $row['migration_message'] = $readiness['message'];
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('di_gl_policy_known')) {
    /**
     * The general-ledger policies the integrated system supports for imported history:
     *  - opening_balance: the history goes to the water records only; the amounts still open at the cut-over are posted
     *    to the ledger as opening invoices (offset: Legacy Water Balances Clearing) by the Opening balances step, so the
     *    water receivables and the ledger agree and the open bills can be collected at the cashier;
     *  - full_posting: every imported bill, recurring charge and receipt is posted on its own date.
     */
    function di_gl_policy_known(string $policy): bool
    {
        return in_array($policy, ['opening_balance', 'full_posting'], true);
    }
}

if (!function_exists('di_batch_configuration_blockers')) {
    /**
     * Configuration blockers are different from ordinary data-quality Review
     * rows. If any of these exist, Billing Master operational writes are
     * intentionally blocked until configuration is fixed and the batch is
     * revalidated.
     */
    function di_batch_configuration_blockers(PDO $pdo, int $batchId): array
    {
        // Batch-wide blockers are CONFIGURATION defects: a mapping that points at
        // something broken or missing accounting. Row-level data gaps
        // (MEMBER_NOT_FOUND, RATE_MAPPING for an unmapped legacy code,
        // RECURRING_PLAN_REQUIRED for an unmapped charge column) keep only their
        // own rows in Review so the rest of the batch can still migrate and the
        // unresolved portion can be revalidated later (audit I05).
        $definitions = [
            'WATER_BILL_ACCOUNTING_REQUIRED' => 'The WATER BILL code is missing required AR/debit or Income/credit accounting.',
            'TARIFF_CONFIGURATION' => 'One or more mapped Rate Code + Pipe Size combinations have no configured tariff.',
            'RECURRING_PLAN_REUSED' => 'Two source recurring charges share the same destination plan.',
            'RECURRING_PLAN_INACTIVE' => 'A mapped recurring plan is inactive.',
            'BILL_CODE_ACCOUNTING_REQUIRED' => 'A recurring-charge bill code is missing required AR/debit or Income/credit accounting.',
            'BILL_CODE_MISMATCH' => 'A source recurring charge is mapped to an unrelated bill code.',
            'COLLECTION_ACCOUNTING_REQUIRED' => 'Collection accounting is incomplete for the active operator profile.',
            'PENALTY_ACCOUNT_REQUIRED' => 'Penalty receipts require an active penalty Income/credit account.',
            'COMPROMISE_ACCOUNT_REQUIRED' => 'Compromise receipts require an active compromise Income/credit account.',
            'MAPPING_REFERENCE_INVALID' => 'A batch mapping references a rate code, pipe size, plan, discount or city that no longer exists.',
            'GL_POLICY_UNRESOLVED' => 'The general-ledger policy for imported history is unresolved.',
        ];
        if ($batchId <= 0 || !$definitions) return [];

        $parts = [];
        $params = [];
        $i = 0;
        foreach ($definitions as $code => $message) {
            $parts[] = "SUM(issues_json LIKE ?) AS c{$i}";
            $params[] = '%"code":"' . $code . '"%';
            $i++;
        }
        $params[] = $batchId;
        $stmt = $pdo->prepare('SELECT ' . implode(',', $parts) . ' FROM data_import_rows WHERE batch_id=?');
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        $i = 0;
        foreach ($definitions as $code => $message) {
            $count = (int)($row['c' . $i] ?? 0);
            if ($count > 0) $out[] = ['code' => $code, 'count' => $count, 'message' => $message];
            $i++;
        }
        return $out;
    }
}

if (!function_exists('di_import_eligible_count')) {
    function di_import_eligible_count(PDO $pdo, int $batchId, string $mode): int
    {
        if ($mode === 'opening') {
            return array_sum(di_opening_candidates($pdo, $batchId, 1, true));
        }
        if ($mode === 'payments') {
            $where = "batch_id=? AND payment_status='ready' AND target_bill_id IS NOT NULL AND recurring_status IN ('not_applicable','imported')";
        } elseif ($mode === 'recurring') {
            $where = "batch_id=? AND recurring_status='ready' AND import_status IN ('imported','skipped') AND target_bill_id IS NOT NULL";
        } else {
            $where = "batch_id=? AND import_status='ready'";
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM data_import_rows WHERE ' . $where);
        $stmt->execute([$batchId]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('di_assert_import_prerequisites')) {
    function di_assert_import_prerequisites(PDO $pdo, array $batch, string $mode, int $userId): void
    {
        if (($batch['import_type'] ?? '') === 'billing_master' && in_array($mode, ['bills', 'recurring', 'payments'], true)) {
            $batchId = (int)($batch['batch_id'] ?? 0);
            $glPolicy = di_rule($pdo, 'gl_policy');
            if (!di_gl_policy_known($glPolicy)) {
                throw new RuntimeException('Billing Master migration is blocked: the general-ledger policy for imported history is unresolved. Confirm it in the mapping decisions, then Revalidate batch.');
            }
            $snapshot = di_json_decode((string)($batch['mapping_json'] ?? ''));
            if ($snapshot && isset($snapshot['hash']) && (string)$snapshot['hash'] !== (string)di_rules_snapshot($pdo)['hash']) {
                throw new RuntimeException('The mapping decisions changed after this batch was validated. Click Revalidate batch so the rows are re-checked under the current rules before importing.');
            }
            $fingerprint = hash('sha256', implode('|', [
                'gate-v2', (string)($batch['mapping_version'] ?? ''), (string)($batch['options_json'] ?? ''),
                (int)($batch['review_count'] ?? 0), (int)($batch['error_count'] ?? 0),
                (int)($batch['recurring_review_count'] ?? 0), (int)($batch['recurring_error_count'] ?? 0),
                (int)($batch['payment_review_count'] ?? 0), (int)($batch['payment_error_count'] ?? 0),
            ]));
            $cached = $_SESSION['di_configuration_gate'][$batchId] ?? null;
            if (is_array($cached) && hash_equals((string)($cached['fingerprint'] ?? ''), $fingerprint)) {
                $blockers = is_array($cached['blockers'] ?? null) ? $cached['blockers'] : [];
            } else {
                $blockers = di_batch_configuration_blockers($pdo, $batchId);
                $_SESSION['di_configuration_gate'][$batchId] = ['fingerprint' => $fingerprint, 'blockers' => $blockers];
            }
            if ($blockers) {
                $messages = [];
                foreach (array_slice($blockers, 0, 6) as $blocker) {
                    $messages[] = $blocker['code'] . ' (' . $blocker['count'] . ' row(s)): ' . $blocker['message'];
                }
                throw new RuntimeException('Billing Master migration is configuration-blocked. Fix the requirements shown in the preview/tooltips, then Revalidate batch. ' . implode(' | ', $messages));
            }
        }

        if ($mode === 'bills') {
            $water = di_water_bill_readiness($pdo);
            if (!$water['ready']) throw new RuntimeException('Water-bill migration is blocked. ' . $water['message']);
            return;
        }

        if ($mode === 'recurring') {
            $options = di_json_decode($batch['options_json'] ?? '{}');
            $plans = [];
            foreach (di_current_recurring_plans($pdo) as $plan) $plans[(int)$plan['plan_id']] = $plan;
            $problems = [];
            foreach (($options['recurring_map'] ?? []) as $column => $planId) {
                $planId = (int)$planId;
                if ($planId <= 0) continue;
                if (empty($plans[$planId])) {
                    $problems[] = $column . ': mapped recurring plan no longer exists';
                    continue;
                }
                $readiness = di_recurring_plan_readiness($plans[$planId], (string)$column);
                if (!$readiness['ready']) $problems[] = $column . ': ' . $readiness['message'];
            }
            if ($problems) {
                throw new RuntimeException('Recurring-charge migration is blocked until configuration is complete. ' . implode(' | ', array_slice($problems, 0, 6)));
            }
            return;
        }

        if ($mode === 'payments') {
            $collection = di_collection_readiness($pdo, $userId);
            if (!$collection['ready']) throw new RuntimeException('Collection migration is blocked. ' . $collection['message']);
        }
    }
}

if (!function_exists('di_name_identity')) {
    function di_name_identity(string $last, string $first, string $middle): string
    {
        $value = strtoupper(trim($last . '|' . $first . '|' . $middle));
        return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
    }
}

if (!function_exists('di_validation_context')) {
    function di_validation_context(PDO $pdo): array
    {
        $context = [
            'members' => [], 'rate_ids' => [], 'pipe_ids' => [], 'city_ids' => [],
            'rate_pipe_ids' => [], 'discount_ids' => [], 'receipts' => [], 'bills' => [],
            'mobile_channel_id' => 0, 'recurring_plans' => [],
            'water_bill_readiness' => ['ready' => false, 'message' => 'Water Bill accounting was not checked.'],
            'collection_readiness' => ['ready' => false, 'message' => 'Collection accounting was not checked.'],
            'penalty_account_id' => 0, 'penalty_account_ready' => false,
            'compromise_account_id' => 0, 'compromise_account_ready' => false,
        ];

        $contextAddress = [];
        $contextContact = [];
        foreach ($pdo->query('SELECT pkey,account_number,lastname,firstname,middlename,status,zone,meter_sequence,rc_id,pipe_size_id,connection_status FROM water_accounts WHERE account_number IS NOT NULL') as $row) {
            $account = di_normalize_account_number($row['account_number'] ?? '');
            if ($account === '') continue;
            $context['members'][$account] = [
                'pkey' => (int)$row['pkey'],
                'rc_id' => (int)($row['rc_id'] ?? 0),
                'pipe_size_id' => (int)($row['pipe_size_id'] ?? 0),
                'lastname' => (string)($row['lastname'] ?? ''),
                'firstname' => (string)($row['firstname'] ?? ''),
                'middlename' => (string)($row['middlename'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'zone' => $row['zone'] === null ? null : (int)$row['zone'],
                'meter_sequence' => $row['meter_sequence'] === null ? null : (string)$row['meter_sequence'],
                'connection_status' => (string)($row['connection_status'] ?? ''),
            ];
        }
        // Existing address / mobile contact for the side-by-side Account Master preview.
        if (di_table_exists($pdo, 'memberaddress') && di_table_exists($pdo, 'addresses')) {
            try {
                $addressSql = "SELECT ma.member_key, a.street, a.housebldg, a.other_address_detail FROM memberaddress ma INNER JOIN addresses a ON a.pkey = ma.address_key WHERE ma.status = 'A' ORDER BY ma.member_key, ma.dtaddressFrom DESC";
                $seen = [];
                foreach ($pdo->query($addressSql) as $row) {
                    $memberKey = (int)$row['member_key'];
                    if (isset($seen[$memberKey])) continue;
                    $seen[$memberKey] = true;
                    $contextAddress[$memberKey] = trim(implode(' ', array_filter([(string)($row['housebldg'] ?? ''), (string)($row['street'] ?? ''), (string)($row['other_address_detail'] ?? '')])));
                }
            } catch (Throwable $ignored) {}
        }
        if (di_table_exists($pdo, 'membercontactchannels')) {
            try {
                foreach ($pdo->query("SELECT memberkey, contactdata FROM membercontactchannels WHERE status = 'A' ORDER BY memberkey, activefromdate DESC") as $row) {
                    $memberKey = (int)$row['memberkey'];
                    if (!isset($contextContact[$memberKey])) $contextContact[$memberKey] = trim((string)$row['contactdata']);
                }
            } catch (Throwable $ignored) {}
        }
        foreach ($context['members'] as $account => &$memberRow) {
            $memberRow['address'] = $contextAddress[(int)$memberRow['pkey']] ?? '';
            $memberRow['contact'] = $contextContact[(int)$memberRow['pkey']] ?? '';
        }
        unset($memberRow);
        if (di_table_exists($pdo, 'rate_code')) {
            foreach ($pdo->query('SELECT rc_id FROM rate_code') as $row) $context['rate_ids'][(int)$row['rc_id']] = true;
        }
        if (di_table_exists($pdo, 'water_pipe_sizes')) {
            foreach ($pdo->query('SELECT pipe_size_id FROM water_pipe_sizes WHERE is_active = 1') as $row) $context['pipe_ids'][(int)$row['pipe_size_id']] = true;
        }
        if (di_table_exists($pdo, 'water_meter_members')) {
            foreach ($pdo->query('SELECT DISTINCT rc_id,pipe_size_id FROM water_meter_members') as $row) {
                $context['rate_pipe_ids'][(int)$row['rc_id'] . '|' . (int)$row['pipe_size_id']] = true;
            }
        }
        if (di_table_exists($pdo, 'cities')) {
            foreach ($pdo->query("SELECT pkey FROM cities WHERE status = 'A'") as $row) $context['city_ids'][(int)$row['pkey']] = true;
        }
        if (di_table_exists($pdo, 'discounts')) {
            $discountSql = di_column_exists($pdo, 'discounts', 'is_active')
                ? 'SELECT discount_id FROM discounts WHERE is_active=1'
                : 'SELECT discount_id FROM discounts';
            foreach ($pdo->query($discountSql) as $row) $context['discount_ids'][(int)$row['discount_id']] = true;
        }
        if (di_table_exists($pdo, 'payment_transactions')) {
            // Existing receipts keep their number, account, total and date so a
            // staged row is classified as "already imported" only when all of
            // them agree; a conflicting receipt enters Review (audit I08).
            foreach ($pdo->query('SELECT transaction_id,receipt_no,member_id,total_paid,business_date,transaction_status FROM payment_transactions') as $row) {
                $identity = di_receipt_identity($row['receipt_no'] ?? '');
                if ($identity === '') continue;
                $context['receipts'][$identity][] = [
                    'transaction_id' => (int)$row['transaction_id'],
                    'member_id' => (int)$row['member_id'],
                    'total_paid' => (float)$row['total_paid'],
                    'business_date' => (string)($row['business_date'] ?? ''),
                    'status' => (string)($row['transaction_status'] ?? ''),
                ];
            }
        }
        if (di_table_exists($pdo, 'bill_readings')) {
            $billSql = 'SELECT reading_id,member_id,billing_date,due_date,prev_reading,pres_reading,'
                . 'consumption,amount_due,amount_paid,penalty_amount,is_paid,bill_lifecycle_status '
                . 'FROM bill_readings WHERE billing_date IS NOT NULL';
            foreach ($pdo->query($billSql) as $row) {
                $context['bills'][(int)$row['member_id'] . '|' . (string)$row['billing_date']] = [
                    'reading_id' => (int)$row['reading_id'],
                    'due_date' => $row['due_date'] === null ? null : (string)$row['due_date'],
                    'prev_reading' => (int)$row['prev_reading'],
                    'pres_reading' => (int)$row['pres_reading'],
                    'consumption' => (int)$row['consumption'],
                    'amount_due' => (float)$row['amount_due'],
                    'amount_paid' => (float)$row['amount_paid'],
                    'penalty_amount' => (float)$row['penalty_amount'],
                    'is_paid' => (int)$row['is_paid'],
                    'bill_lifecycle_status' => (string)$row['bill_lifecycle_status'],
                ];
            }
        }
        if (di_table_exists($pdo, 'contactchannels')) {
            $stmt = $pdo->query(
                "SELECT pkey FROM contactchannels WHERE status='A' "
                . "AND (LOWER(name)='mobile' OR LOWER(description) LIKE '%mobile%') ORDER BY pkey LIMIT 1"
            );
            $context['mobile_channel_id'] = (int)$stmt->fetchColumn();
        }
        if (di_table_exists($pdo, 'recurring_plans')) {
            foreach (di_current_recurring_plans($pdo) as $row) {
                $context['recurring_plans'][(int)$row['plan_id']] = $row;
            }
        }
        $context['water_bill_readiness'] = di_water_bill_readiness($pdo);
        $context['collection_readiness'] = di_collection_readiness($pdo, (int)($_SESSION['operator_id'] ?? $_SESSION['user_id'] ?? 0));
        $context['penalty_account_id'] = di_setting_account_id($pdo, 'collection_penalty_account_id');
        $context['penalty_account_ready'] = di_active_chart_account($pdo, (int)$context['penalty_account_id']);
        $context['compromise_account_id'] = di_setting_account_id($pdo, 'collection_compromise_account_id');
        $context['compromise_account_ready'] = di_active_chart_account($pdo, (int)$context['compromise_account_id']);
        $context['rules'] = [];
        foreach (di_mapping_rules($pdo) as $key => $rule) $context['rules'][$key] = (string)$rule['value'];
        di_date_order($context['rules']['date_order'] ?? 'mdy');
        return $context;
    }
}

if (!function_exists('di_validate_account_row')) {
    function di_validate_account_row(array $row, array $options, array $context, int $duplicateCount): array
    {
        $issues = [];
        if (isset($row['__csv_error'])) {
            di_issue($issues, 'member', 'error', 'CSV_WIDTH', (string)$row['__csv_error']);
            return ['normalized' => [], 'issues' => $issues, 'import_status' => 'error', 'payment_status' => 'not_applicable', 'target_member_id' => null];
        }

        $account = di_normalize_account_number($row['Account No'] ?? '');
        if ($account === '' || !preg_match('/^\d{1,50}$/', $account)) {
            di_issue($issues, 'member', 'error', 'ACCOUNT_NUMBER', 'Account No must contain 1 to 50 digits after normalization.');
        }
        if ($duplicateCount > 1) {
            di_issue($issues, 'member', 'review', 'DUPLICATE_ACCOUNT', 'Account No occurs more than once in this source file.');
        }

        $name = di_parse_name((string)($row['Account Name'] ?? ''));
        if ((string)($name['message'] ?? '') !== '') {
            di_issue($issues, 'member', 'warning', 'NAME_OPTIONAL', (string)$name['message']);
        }

        // A member/customer master record must not be blocked by missing tariff
        // configuration. members.rc_id is nullable, and the source contains legitimate
        // accounts with a blank legacy Rate Code. Preserve those customers now and let
        // tariff setup be completed separately before billing is generated.
        $sourceRate = strtoupper(trim((string)($row['Rate Code'] ?? '')));
        $mappedRateCandidate = (int)($options['rate_map'][$sourceRate] ?? 0);
        $mappedRate = ($mappedRateCandidate > 0 && !empty($context['rate_ids'][$mappedRateCandidate]))
            ? $mappedRateCandidate
            : null;
        if ($mappedRate === null) {
            di_issue(
                $issues,
                'member',
                'warning',
                'RATE_UNASSIGNED',
                'Rate Code ' . ($sourceRate ?: '(blank)') . ' has no valid destination mapping. The member will still be imported with rc_id NULL.'
            );
        }

        $requestedPipeSizeId = (int)($options['pipe_map'][$sourceRate] ?? $options['default_pipe_size_id'] ?? 0);
        $pipeSizeId = $requestedPipeSizeId;
        if ($pipeSizeId <= 0 || empty($context['pipe_ids'][$pipeSizeId])) {
            // pipe_size_id is NOT NULL in members. Prefer the schema's explicit
            // UNSPECIFIED / Existing record (id 1), otherwise use the first active
            // pipe size returned by the database.
            if (!empty($context['pipe_ids'][1])) {
                $pipeSizeId = 1;
            } else {
                $activePipeIds = array_map('intval', array_keys($context['pipe_ids']));
                sort($activePipeIds, SORT_NUMERIC);
                $pipeSizeId = (int)($activePipeIds[0] ?? 0);
            }
            di_issue(
                $issues,
                'member',
                'warning',
                'PIPE_SIZE_FALLBACK',
                'No valid source pipe-size mapping is available. The member will use the active UNSPECIFIED/default pipe size and can be configured later.'
            );
        }
        if ($mappedRate !== null && $pipeSizeId > 0 && empty($context['rate_pipe_ids'][$mappedRate . '|' . $pipeSizeId])) {
            di_issue(
                $issues,
                'member',
                'warning',
                'TARIFF_CONFIGURATION',
                'The selected rate code and pipe size do not have a configured water tariff. This does not block member import, but billing must not be generated until the tariff is configured.'
            );
        }

        $active = di_parse_bool($row['Active Status Flag'] ?? null);
        if ($active === null) {
            $active = true;
            di_issue($issues, 'member', 'warning', 'ACTIVE_STATUS_DEFAULT', 'Active Status Flag is invalid or blank. The member will be imported as active.');
        }

        $zone = di_parse_int($row['Zone'] ?? null);
        if ($zone === null) di_issue($issues, 'member', 'warning', 'ZONE', 'Zone is blank or invalid and will remain unassigned.');

        $sequence = trim((string)($row['Sequence No'] ?? ''));
        if (strlen($sequence) > 50) {
            $sequence = substr($sequence, 0, 50);
            di_issue($issues, 'member', 'warning', 'SEQUENCE_LENGTH', 'Sequence No exceeded 50 characters and was length-limited for the member record.');
        }

        $address = trim((string)($row['Account Address'] ?? ''));
        $addressMode = (string)($options['address_mode'] ?? 'source');
        if ($address !== '') {
            if ($addressMode === 'skip') {
                di_issue($issues, 'member', 'warning', 'ADDRESS_SKIPPED', 'Address is preserved in staging but automatic address import is disabled.');
            } elseif (strlen($address) > 100) {
                $address = function_exists('mb_substr') ? mb_substr($address, 0, 100, 'UTF-8') : substr($address, 0, 100);
                di_issue($issues, 'member', 'warning', 'ADDRESS_LENGTH', 'Account Address exceeded 100 characters and was length-limited for the address table. The complete source value remains preserved in staging.');
            } elseif ($addressMode === 'default' && (empty($context['city_ids'][(int)($options['default_city_id'] ?? 0)]) || (int)($options['default_zip'] ?? 0) <= 0)) {
                // The supplied schema allows city/ZIP to be NULL. Fall back to the
                // source-only address path rather than blocking the member.
                $addressMode = 'source';
                di_issue($issues, 'member', 'warning', 'ADDRESS_DEFAULT_FALLBACK', 'Default city/ZIP is incomplete. The source address will be imported without forcing structured city/ZIP values.');
            }
        }

        $meterNumber = trim((string)($row['Meter Number'] ?? ''));
        if ($meterNumber !== '' && preg_match('/^[0-9.]+E[+\-]?\d+$/i', $meterNumber)) {
            di_issue($issues, 'member', 'warning', 'METER_NUMBER_SCIENTIFIC', 'Meter Number was exported in scientific notation (' . $meterNumber . '); the text is preserved as-is and no digits are guessed.');
        }

        $phone = preg_replace('/\s+/', '', trim((string)($row['cellno'] ?? ''))) ?? '';
        if ($phone !== '' && !preg_match('/^[0-9+()\-]{5,50}$/', $phone)) {
            di_issue($issues, 'member', 'warning', 'PHONE_FORMAT', 'Mobile number has an unsupported format and will not be imported.');
            $phone = '';
        } elseif ($phone !== '' && (int)$context['mobile_channel_id'] <= 0) {
            di_issue($issues, 'member', 'warning', 'PHONE_CHANNEL', 'No active Mobile contact channel exists, so the number will remain staged only.');
        }

        $discountFlag = di_parse_bool($row['Discount Flag'] ?? null) === true;
        $seniorFlag = di_parse_bool($row['SeniorMaster'] ?? null) === true;
        $sourceDiscountId = (int)($options['source_discount_id'] ?? 0);
        $seniorDiscountId = (int)($options['senior_discount_id'] ?? 0);
        if ($discountFlag && empty($context['discount_ids'][$sourceDiscountId])) di_issue($issues, 'member', 'warning', 'DISCOUNT_SKIPPED', 'Discount Flag is TRUE but no destination discount was selected.');
        if ($seniorFlag && empty($context['discount_ids'][$seniorDiscountId])) di_issue($issues, 'member', 'warning', 'SENIOR_SKIPPED', 'SeniorMaster is TRUE but no Senior Citizen discount was selected.');

        $normalized = [
            'account_number' => $account,
            'account_name' => (string)$name['display_name'],
            'lastname' => (string)$name['lastname'],
            'firstname' => (string)$name['firstname'],
            'middlename' => (string)$name['middlename'],
            'zone' => $zone,
            'meter_sequence' => $sequence === '' ? null : $sequence,
            'status' => $active === true ? 'A' : ($active === false ? 'I' : null),
            'connection_status' => $active === false ? 'disconnected' : 'active',
            'source_rate_code' => $sourceRate,
            'rc_id' => $mappedRate,
            'pipe_size_id' => $pipeSizeId,
            'address' => $address,
            'address_mode' => $addressMode,
            'city_id' => $addressMode === 'default' ? (int)($options['default_city_id'] ?? 0) : null,
            'zip' => $addressMode === 'default' ? (int)($options['default_zip'] ?? 0) : null,
            'phone' => $phone,
            'mobile_channel_id' => (int)$context['mobile_channel_id'],
            'discount_flag' => $discountFlag,
            'discount_id' => $sourceDiscountId,
            'discount_volume' => di_parse_int($row['Discount Volume'] ?? null),
            'senior_flag' => $seniorFlag,
            'senior_discount_id' => $seniorDiscountId,
            'meter_number' => $meterNumber,
            'member_action' => 'insert',
            'billing_blockers' => array_values(array_filter([
                $mappedRate === null ? 'No rate code mapping (billing cannot compute a tariff until assigned)' : null,
                $requestedPipeSizeId <= 0 || empty($context['pipe_ids'][$requestedPipeSizeId]) ? 'No explicit pipe size (UNSPECIFIED/default used)' : null,
            ])),
        ];

        $targetMemberId = null;
        $status = di_scope_status($issues, 'member');
        if ($account !== '' && isset($context['members'][$account])) {
            $existing = $context['members'][$account];
            $targetMemberId = (int)$existing['pkey'];
            $normalized['member_action'] = 'preserve_existing';
            // Side-by-side comparison for the preview: every compared field with
            // its source and existing value. Existing data is always preserved.
            $existingName = trim((string)$existing['lastname'] . (trim((string)$existing['firstname']) !== '' ? ', ' . (string)$existing['firstname'] : ''));
            $comparison = [
                ['field' => 'Account Name', 'source' => (string)$name['display_name'], 'existing' => $existingName, 'differs' => $name['valid'] && di_name_identity((string)$name['lastname'], (string)$name['firstname'], (string)$name['middlename']) !== di_name_identity((string)$existing['lastname'], (string)$existing['firstname'], (string)$existing['middlename'])],
                ['field' => 'Rate Code', 'source' => $sourceRate . ($mappedRate !== null ? ' → rate #' . $mappedRate : ' (unmapped)'), 'existing' => 'rate #' . (int)$existing['rc_id'], 'differs' => $mappedRate > 0 && (int)$existing['rc_id'] !== $mappedRate],
                ['field' => 'Pipe Size', 'source' => 'pipe #' . (int)$pipeSizeId, 'existing' => 'pipe #' . (int)$existing['pipe_size_id'], 'differs' => $pipeSizeId > 0 && (int)$existing['pipe_size_id'] !== $pipeSizeId],
                ['field' => 'Active Status', 'source' => (string)$normalized['status'], 'existing' => (string)$existing['status'], 'differs' => $normalized['status'] !== null && (string)$existing['status'] !== (string)$normalized['status']],
                ['field' => 'Zone', 'source' => $zone === null ? '' : (string)$zone, 'existing' => $existing['zone'] === null ? '' : (string)$existing['zone'], 'differs' => $zone !== null && $existing['zone'] !== $zone],
                ['field' => 'Sequence No', 'source' => (string)($normalized['meter_sequence'] ?? ''), 'existing' => (string)($existing['meter_sequence'] ?? ''), 'differs' => $normalized['meter_sequence'] !== null && (string)($existing['meter_sequence'] ?? '') !== (string)$normalized['meter_sequence']],
                ['field' => 'Account Address', 'source' => trim((string)($row['Account Address'] ?? '')), 'existing' => (string)($existing['address'] ?? ''), 'differs' => trim((string)($row['Account Address'] ?? '')) !== '' && strcasecmp(preg_replace('/\s+/', ' ', trim((string)($row['Account Address'] ?? ''))) ?? '', preg_replace('/\s+/', ' ', (string)($existing['address'] ?? '')) ?? '') !== 0],
                ['field' => 'Mobile contact', 'source' => $phone, 'existing' => (string)($existing['contact'] ?? ''), 'differs' => $phone !== '' && $phone !== (string)($existing['contact'] ?? '')],
            ];
            $differences = [];
            foreach ($comparison as $entry) if (!empty($entry['differs'])) $differences[] = $entry['field'];
            $normalized['existing'] = ['pkey' => (int)$existing['pkey'], 'comparison' => $comparison, 'differences' => $differences];
            if ($differences) {
                di_issue($issues, 'member', 'warning', 'EXISTING_ACCOUNT_DIFFERENCE', 'Account No already exists. Existing member data is preserved; source differences: ' . implode(', ', $differences) . '. Billing can still match this account by Account No.');
            } else {
                di_issue($issues, 'member', 'warning', 'EXISTING_ACCOUNT', 'Account No already exists with identical compared values and will not be overwritten.');
            }
            $status = 'skipped';
        }

        return [
            'normalized' => $normalized,
            'issues' => $issues,
            'import_status' => $status,
            'payment_status' => 'not_applicable',
            'target_member_id' => $targetMemberId,
        ];
    }
}

if (!function_exists('di_validate_billing_row')) {
    function di_validate_billing_row(
        array $row,
        array $options,
        array $context,
        int $duplicateBillKeyCount,
        int $duplicateReceiptCount
    ): array {
        $issues = [];
        if (isset($row['__csv_error'])) {
            di_issue($issues, 'bill', 'error', 'CSV_WIDTH', (string)$row['__csv_error']);
            return ['normalized' => [], 'issues' => $issues, 'import_status' => 'error', 'payment_status' => 'error', 'recurring_status' => 'error', 'target_member_id' => null, 'target_bill_id' => null, 'target_payment_id' => null];
        }

        $rules = $context['rules'] ?? [];
        $rule = static fn(string $key, string $fallback = 'unresolved'): string => (string)($rules[$key] ?? $fallback);
        $blankPolicy = $rule('blank_optional_fee', 'unknown');
        $glPolicy = $rule('gl_policy');

        $account = di_normalize_account_number($row['Account No'] ?? '');
        if ($account === '' || !preg_match('/^\d{1,50}$/', $account)) {
            di_issue($issues, 'bill', 'error', 'ACCOUNT_NUMBER', 'Account No must contain 1 to 50 digits after normalization.');
        }
        $member = $context['members'][$account] ?? null;
        if (!$member) {
            di_issue($issues, 'bill', 'review', 'MEMBER_NOT_FOUND', 'Account No does not exist among the water-service accounts. Import the Account Master first.');
        }
        $memberId = $member ? (int)$member['pkey'] : null;

        if ($rule('date_order') === 'unresolved') {
            di_issue($issues, 'bill', 'review', 'RULE_UNRESOLVED', 'The source date convention (month/day/year or day/month/year) has not been confirmed in the mapping decisions.');
        }
        $billingDate = di_parse_date($row['Pres Rdg Date'] ?? null);
        $previousDate = di_parse_date($row['Prev Rdg Date'] ?? null);
        $dueDate = di_parse_date($row['Due Date'] ?? null);
        $disconnectionDate = di_parse_date($row['Disconnection'] ?? null);
        $penaltyDate = di_parse_date($row['Penalty Date'] ?? null);
        if ($billingDate === null) di_issue($issues, 'bill', 'error', 'BILLING_DATE', 'Pres Rdg Date is blank, malformed, or outside 2000-2100 (expected ' . ($rule('date_order') === 'dmy' ? 'DD/MM/YYYY' : 'MM/DD/YYYY') . ').');
        if (trim((string)($row['Prev Rdg Date'] ?? '')) !== '' && $previousDate === null) {
            di_issue($issues, 'bill', 'review', 'PREVIOUS_DATE', 'Prev Rdg Date is malformed.');
        } elseif ($previousDate !== null && $billingDate !== null && $previousDate > $billingDate) {
            di_issue($issues, 'bill', 'review', 'DATE_CHRONOLOGY', 'Prev Rdg Date is later than Pres Rdg Date.');
        }
        if (trim((string)($row['Due Date'] ?? '')) !== '' && $dueDate === null) {
            di_issue($issues, 'bill', 'review', 'DUE_DATE', 'Due Date is malformed.');
        }
        if (trim((string)($row['Disconnection'] ?? '')) !== '' && $disconnectionDate === null) {
            di_issue($issues, 'bill', 'warning', 'DISCONNECTION_DATE', 'Disconnection date is malformed and will not be stored.');
        }
        if (trim((string)($row['Penalty Date'] ?? '')) !== '' && $penaltyDate === null) {
            di_issue($issues, 'bill', 'warning', 'PENALTY_DATE', 'Penalty Date is malformed; it is preserved as raw source text only.');
        }

        $present = di_parse_int($row['Present'] ?? null);
        $previous = di_parse_int($row['Previous'] ?? null);
        $usage = di_parse_int($row['Usage'] ?? null);
        if ($present === null || $previous === null || $usage === null) {
            di_issue($issues, 'bill', 'error', 'READINGS', 'Present, Previous, and Usage must be whole numbers.');
        } elseif ($present < 0 || $previous < 0 || $usage < 0) {
            if ($rule('negative_usage', 'review') === 'accept') {
                di_issue($issues, 'bill', 'warning', 'NEGATIVE_READING', 'Negative reading/usage accepted as recorded in the source (confirmed rule).');
            } else {
                di_issue($issues, 'bill', 'review', 'NEGATIVE_READING', 'Negative readings or usage require meter-reset / rollover review.');
            }
        } elseif (($present - $previous) !== $usage) {
            if ($rule('negative_usage', 'review') === 'accept') {
                di_issue($issues, 'bill', 'warning', 'USAGE_MISMATCH', 'Usage differs from Present minus Previous; the source Usage is kept as recorded (confirmed rule).');
            } else {
                di_issue($issues, 'bill', 'review', 'USAGE_MISMATCH', 'Usage does not equal Present minus Previous.');
            }
        }

        $billAmount = di_parse_money($row['Bill Amount'] ?? null);
        [$penalty, $penaltyBlank] = di_parse_optional_money($row['Penalty'] ?? null, 'zero');
        [$compromise, $compromiseBlank] = di_parse_optional_money($row['Compromise Amt'] ?? null, 'zero');
        if ($billAmount === null || (float)$billAmount < 0) di_issue($issues, 'bill', 'error', 'BILL_AMOUNT', 'Bill Amount must be a non-negative number.');
        if ($penalty === null || (float)$penalty < 0) di_issue($issues, 'bill', 'review', 'PENALTY', 'Penalty must be a non-negative number.');
        if ($compromise === null || (float)$compromise < 0) di_issue($issues, 'payment', 'review', 'COMPROMISE', 'Compromise Amt must be a non-negative number.');

        if (!di_gl_policy_known($glPolicy)) {
            di_issue($issues, 'bill', 'review', 'GL_POLICY_UNRESOLVED', 'The general-ledger policy for imported history has not been confirmed in the mapping decisions.');
        }

        $waterReadiness = $context['water_bill_readiness'] ?? ['ready' => false, 'message' => 'Water Bill accounting configuration is unavailable.'];
        if (empty($waterReadiness['ready'])) {
            di_issue($issues, 'bill', 'review', 'WATER_BILL_ACCOUNTING_REQUIRED', (string)($waterReadiness['message'] ?? 'Configure the Water Bill bill code, AR/debit account, and Income/credit account before migration.'));
        }

        $sourceRateRaw = trim((string)($row['Rate Code'] ?? ''));
        $sourceRate = strtoupper($sourceRateRaw);
        if ($sourceRateRaw !== '' && $sourceRateRaw !== $sourceRate) {
            di_issue($issues, 'bill', 'warning', 'RATE_CODE_CASE', 'Rate Code "' . $sourceRateRaw . '" was normalized to "' . $sourceRate . '" for mapping; the original value is preserved.');
        }
        $historicalRateId = (int)($options['rate_map'][$sourceRate] ?? 0);
        $historicalPipeSizeId = (int)($options['pipe_map'][$sourceRate] ?? $options['default_pipe_size_id'] ?? 0);
        if ($sourceRate === '' || $historicalRateId <= 0 || empty($context['rate_ids'][$historicalRateId])) {
            di_issue($issues, 'bill', 'review', 'RATE_MAPPING', 'Historical Rate Code ' . ($sourceRate ?: '(blank)') . ' has no valid destination mapping.');
        } elseif ($historicalPipeSizeId <= 0 || empty($context['pipe_ids'][$historicalPipeSizeId]) || empty($context['rate_pipe_ids'][$historicalRateId . '|' . $historicalPipeSizeId])) {
            di_issue($issues, 'bill', 'review', 'TARIFF_CONFIGURATION', 'Historical Rate Code ' . $sourceRate . ' is not mapped to a configured rate-and-pipe tariff group.');
        }
        if ($member && $historicalRateId > 0 && (int)$member['rc_id'] > 0 && $historicalRateId !== (int)$member['rc_id']) {
            di_issue($issues, 'bill', 'warning', 'RATE_CHANGED', 'Historical source rate differs from the member current rate mapping; the historical mapping will be stored in the bill snapshot.');
        }

        if ($duplicateBillKeyCount > 1) {
            di_issue($issues, 'bill', 'review', 'DUPLICATE_BILL_KEY', 'Account No + Pres Rdg Date occurs more than once in the CSV.');
            di_issue($issues, 'payment', 'review', 'DUPLICATE_BILL_KEY', 'Payment allocation is blocked because the source bill key is duplicated.');
        }

        $targetBillId = null;
        $existingBill = null;
        if ($memberId && $billingDate !== null) {
            $existingBill = $context['bills'][$memberId . '|' . $billingDate] ?? null;
            if (is_array($existingBill)) {
                $targetBillId = (int)($existingBill['reading_id'] ?? 0) ?: null;
                di_issue($issues, 'bill', 'warning', 'EXISTING_BILL', 'A water bill for this member and billing date already exists.');

                $mismatches = [];
                if ($present !== null && (int)$existingBill['pres_reading'] !== $present) $mismatches[] = 'Present';
                if ($previous !== null && (int)$existingBill['prev_reading'] !== $previous) $mismatches[] = 'Previous';
                if ($usage !== null && (int)$existingBill['consumption'] !== $usage) $mismatches[] = 'Usage';
                if ($billAmount !== null && abs((float)$existingBill['amount_due'] - (float)$billAmount) > 0.02) $mismatches[] = 'Bill Amount';
                if ($dueDate !== null && (string)($existingBill['due_date'] ?? '') !== $dueDate) $mismatches[] = 'Due Date';
                if ($mismatches) {
                    $message = 'The existing water bill differs from the CSV in: ' . implode(', ', $mismatches) . '. Payment import is blocked.';
                    di_issue($issues, 'bill', 'warning', 'EXISTING_BILL_MISMATCH', $message);
                    di_issue($issues, 'payment', 'review', 'EXISTING_BILL_MISMATCH', $message);
                }
                if ((float)($existingBill['amount_paid'] ?? 0) > 0.005 || (int)($existingBill['is_paid'] ?? 0) === 1) {
                    di_issue($issues, 'payment', 'review', 'EXISTING_BILL_PAID', 'The existing water bill already has a payment state. A second historical payment will not be attached.');
                }
                if ((string)($existingBill['bill_lifecycle_status'] ?? '') !== 'active') {
                    di_issue($issues, 'payment', 'review', 'EXISTING_BILL_INACTIVE', 'The existing water bill is not active, so payment import is blocked.');
                }
            }
        }

        /* ---- recurring / extra charges ---------------------------------- */
        $recurringStatus = 'not_applicable';
        $recurringLines = [];
        $extraTotal = 0.0;
        $extraNames = [];
        $mappedPlanCounts = array_count_values(array_filter(array_map('intval', $options['recurring_map'] ?? [])));
        foreach (di_recurring_columns() as $column) {
            $rawAmount = trim((string)($row[$column] ?? ''));
            [$amount, $wasBlank] = di_parse_optional_money($rawAmount, $blankPolicy);
            if ($wasBlank && $amount === null) {
                di_issue($issues, 'recurring', 'review', 'BLANK_OPTIONAL_FEE', $column . ' is blank and the blank-cell policy is "unknown".');
                di_issue($issues, 'payment', 'review', 'BLANK_OPTIONAL_FEE', $column . ' is blank and the blank-cell policy is "unknown".');
                continue;
            }
            if ($amount === null) {
                di_issue($issues, 'recurring', 'review', 'RECURRING_FORMAT', $column . ' is not a valid amount.');
                di_issue($issues, 'payment', 'review', 'EXTRA_FORMAT', $column . ' is not a valid amount.');
                continue;
            }
            if ((float)$amount < 0) {
                di_issue($issues, 'recurring', 'review', 'RECURRING_NEGATIVE', $column . ' is negative.');
                di_issue($issues, 'payment', 'review', 'EXTRA_NEGATIVE', $column . ' is negative.');
                continue;
            }
            if ((float)$amount <= 0) continue;
            $extraTotal += (float)$amount;
            $extraNames[] = $column;
            $recurringStatus = 'ready';
            if ($rule('charge:' . $column, 'unresolved') !== 'recurring_plan') {
                di_issue($issues, 'recurring', 'review', 'CHARGE_SEMANTICS_UNRESOLVED', 'The meaning/accounting treatment of ' . $column . ' has not been confirmed in the mapping decisions.');
                continue;
            }
            $planId = (int)($options['recurring_map'][$column] ?? 0);
            if ($planId <= 0 || empty($context['recurring_plans'][$planId])) {
                di_issue($issues, 'recurring', 'review', 'RECURRING_PLAN_REQUIRED', $column . ' requires an explicit recurring plan mapping. Create the matching bill code first, configure its AR/debit and Income/credit accounts, then create an active recurring plan.');
            } elseif (($mappedPlanCounts[$planId] ?? 0) > 1) {
                di_issue($issues, 'recurring', 'review', 'RECURRING_PLAN_REUSED', $column . ' shares a destination plan with another source column. Use a distinct plan to preserve charge identity.');
            } else {
                $plan = $context['recurring_plans'][$planId];
                $planReadiness = di_recurring_plan_readiness($plan, $column);
                if (!$planReadiness['ready']) {
                    if (strtolower(trim((string)($plan['status'] ?? ''))) !== 'active') {
                        di_issue($issues, 'recurring', 'review', 'RECURRING_PLAN_INACTIVE', $column . ' is mapped to an inactive recurring plan. Activate or replace the plan before migration.');
                    }
                    $accounting = di_bill_code_accounting_readiness($plan);
                    if (!$accounting['ready']) {
                        di_issue($issues, 'recurring', 'review', 'BILL_CODE_ACCOUNTING_REQUIRED', $column . ' uses bill code "' . (string)($plan['code'] ?? '') . '" but its accounting setup is incomplete. ' . $accounting['message'] . ' The charge needs a valid AR/debit account and Income/credit account before migration.');
                    }
                    if (!di_recurring_plan_matches_source($column, $plan)) {
                        di_issue($issues, 'recurring', 'review', 'BILL_CODE_MISMATCH', $column . ' is mapped to bill code "' . (string)($plan['code'] ?? '') . '". Select a bill code that identifies the same charge (for example, DamayanContribution should map to a DAMAYAN bill code).');
                    }
                } else {
                    $recurringLines[] = ['source_column' => $column, 'amount' => $amount, 'plan_id' => $planId];
                }
            }
        }
        if ($recurringStatus === 'ready') {
            if (!di_gl_policy_known($glPolicy)) {
                di_issue($issues, 'recurring', 'review', 'GL_POLICY_UNRESOLVED', 'The general-ledger policy for imported history has not been confirmed.');
            }
            $recurringStatus = di_scope_status($issues, 'recurring');
        }

        /* ---- penalty / compromise coexistence ----------------------------- */
        $penaltyEffective = (float)($penalty ?? 0);
        $compromiseEffective = (float)($compromise ?? 0);
        $manualRule = $rule('penalty_compromise');
        if ($penaltyEffective > 0 && $compromiseEffective > 0) {
            if ($manualRule === 'compromise_replaces') {
                $penaltyEffective = 0.0;
                di_issue($issues, 'payment', 'warning', 'PENALTY_REPLACED', 'Penalty is kept as source metadata; Compromise Amt replaces it (confirmed rule).');
            } elseif ($manualRule === 'penalty_replaces') {
                $compromiseEffective = 0.0;
                di_issue($issues, 'payment', 'warning', 'COMPROMISE_REPLACED', 'Compromise Amt is kept as source metadata; Penalty replaces it (confirmed rule).');
            } elseif ($manualRule !== 'both') {
                di_issue($issues, 'payment', 'review', 'MANUAL_CHARGE_CONFLICT', 'This receipt carries both Penalty and Compromise Amt and the coexistence rule has not been confirmed.');
            }
        }

        /* ---- settlement evidence ------------------------------------------ */
        $receipt = trim((string)($row['Receipt Number'] ?? ''));
        $receiptIdentity = di_receipt_identity($receipt);
        $datePaidRaw = trim((string)($row['Date Paid'] ?? ''));
        $datePaid = di_parse_date($row['Date Paid'] ?? null);
        $paymentFlag = di_parse_bool($row['Payment Flag'] ?? null);
        $receiptFlag = di_parse_bool($row['Receipt Flag'] ?? null);
        $amtPaid = di_parse_money($row['Amt Paid'] ?? null);
        $change = di_parse_money($row['Cash Change'] ?? null);
        if (trim((string)($row['Cash Change'] ?? '')) === '') $change = '0.00';
        $sourceTotal = di_parse_money($row['Total'] ?? null);
        $paymentStatus = 'not_applicable';
        $targetPaymentId = null;
        $applied = null;
        $partial = false;

        $paidRule = $rule('paid_rule');
        $hasAnyEvidence = $paymentFlag === true || $receipt !== '' || $datePaidRaw !== '' || (float)($amtPaid ?? 0) > 0;
        $settled = false;
        if ($paidRule === 'flag_receipt_date') $settled = $paymentFlag === true && $receipt !== '' && $datePaid !== null;
        elseif ($paidRule === 'flag_only') $settled = $paymentFlag === true;
        elseif ($paidRule === 'amount_only') $settled = (float)($amtPaid ?? 0) > 0;

        if (!empty($options['stage_payments']) && $hasAnyEvidence) {
            if ($paidRule === 'unresolved') {
                $paymentStatus = 'ready';
                di_issue($issues, 'payment', 'review', 'RULE_UNRESOLVED', 'The paid-status rule has not been confirmed in the mapping decisions.');
            } elseif (!$settled) {
                if ($paymentFlag === true) {
                    $paymentStatus = 'ready';
                    di_issue($issues, 'payment', 'review', 'PAYMENT_EVIDENCE_INCOMPLETE', 'Payment Flag is TRUE but ' . ($receipt === '' ? 'Receipt Number' : 'Date Paid') . ' is missing or invalid, so settlement is not proven.');
                } elseif ($receipt !== '' || $datePaidRaw !== '') {
                    $paymentStatus = 'ready';
                    di_issue($issues, 'payment', 'review', 'PAYMENT_FLAG', 'Receipt/payment data exists but Payment Flag is not TRUE.');
                } else {
                    di_issue($issues, 'payment', 'warning', 'AMT_PAID_WITHOUT_EVIDENCE', 'Amt Paid is positive but there is no Payment Flag, receipt number or Date Paid; the bill is imported as unpaid and the amount is preserved as source metadata.');
                }
            } else {
                $paymentStatus = 'ready';
            }
        }

        if ($paymentStatus === 'ready') {
            $collectionReadiness = $context['collection_readiness'] ?? ['ready' => false, 'message' => 'Collection accounting configuration is unavailable.'];
            if (empty($collectionReadiness['ready'])) {
                di_issue($issues, 'payment', 'review', 'COLLECTION_ACCOUNTING_REQUIRED', (string)($collectionReadiness['message'] ?? 'Configure the collection debit/CashGL and receivable accounting before importing collections.'));
            }
            if (!di_gl_policy_known($glPolicy)) {
                di_issue($issues, 'payment', 'review', 'GL_POLICY_UNRESOLVED', 'The general-ledger policy for imported history has not been confirmed.');
            }
            if ($penaltyEffective > 0 && empty($context['penalty_account_ready'])) {
                di_issue($issues, 'payment', 'review', 'PENALTY_ACCOUNT_REQUIRED', 'This receipt contains a Penalty. Configure an active collection penalty Income/credit account before migration.');
            }
            if ($compromiseEffective > 0 && empty($context['compromise_account_ready'])) {
                di_issue($issues, 'payment', 'review', 'COMPROMISE_ACCOUNT_REQUIRED', 'This receipt contains a Compromise amount. Configure an active collection compromise Income/credit account before migration.');
            }
            if ($receipt === '' || strlen($receipt) > 50) di_issue($issues, 'payment', 'review', 'RECEIPT_NUMBER', 'A receipt number of at most 50 characters is required.');
            if ($datePaid === null) di_issue($issues, 'payment', 'review', 'DATE_PAID', 'Date Paid is required and must be a valid date.');
            elseif ($billingDate !== null && $datePaid < $billingDate) di_issue($issues, 'payment', 'warning', 'DATE_PAID_BEFORE_BILL', 'Date Paid precedes Pres Rdg Date (advance payment); the source dates are kept.');
            if ($duplicateReceiptCount > 1) di_issue($issues, 'payment', 'review', 'DUPLICATE_RECEIPT', 'Receipt Number occurs more than once in this CSV (split allocation or true duplicate must be confirmed).');

            if ($extraTotal > 0 && $recurringStatus !== 'ready') di_issue($issues, 'payment', 'review', 'RECURRING_NOT_READY', 'Recurring charges on this receipt must be mapped and imported first.');

            $principalExpected = round((float)($billAmount ?? 0) + $extraTotal, 2);
            if ($billAmount === null || (float)$billAmount < 0) di_issue($issues, 'payment', 'review', 'PAYMENT_BILL_AMOUNT', 'Bill Amount is invalid for payment allocation.');
            if ($principalExpected <= 0) di_issue($issues, 'payment', 'review', 'NO_PAYMENT_PRINCIPAL', 'Receipt has no water or recurring principal to allocate.');
            $expected = round($principalExpected + $penaltyEffective + $compromiseEffective, 2);
            if ($sourceTotal === null || abs((float)$sourceTotal - $expected) > 0.02) {
                di_issue($issues, 'payment', 'review', 'TOTAL_MISMATCH', 'Total (' . ($sourceTotal ?? 'blank') . ') does not reconcile with Bill Amount + charges + penalty + compromise (' . number_format($expected, 2, '.', '') . ').');
            }

            $meaning = $rule('amt_paid_meaning');
            if ($amtPaid === null || (float)$amtPaid <= 0) {
                di_issue($issues, 'payment', 'review', 'TENDERED_AMOUNT', 'Amt Paid must be a positive amount for a settled receipt.');
            } elseif ($meaning === 'unresolved') {
                di_issue($issues, 'payment', 'review', 'RULE_UNRESOLVED', 'The meaning of Amt Paid (tendered cash versus applied amount) has not been confirmed.');
            } else {
                $amt = (float)$amtPaid;
                if ($meaning === 'tendered') {
                    $applied = min($amt, $expected);
                    $expectedChange = round($amt - $applied, 2);
                } else {
                    $applied = $amt;
                    $expectedChange = 0.0;
                    if ($amt > $expected + 0.02) di_issue($issues, 'payment', 'review', 'EXCESS_PAYMENT', 'Amt Paid exceeds the validated total; an applied amount above the bill needs a credit/advance decision.');
                }
                if ($applied + 0.02 < $expected) {
                    $partial = true;
                    if ($rule('partial_payment', 'review') === 'allocate_partial') {
                        di_issue($issues, 'payment', 'warning', 'PARTIAL_PAYMENT', 'Amt Paid covers only part of the validated total; a partial payment will be recorded and the bill stays open.');
                    } else {
                        di_issue($issues, 'payment', 'review', 'PARTIAL_PAYMENT', 'Amt Paid is less than the validated total and partial payments are kept in Review.');
                    }
                }
                if ($change === null || abs((float)$change - $expectedChange) > 0.02) {
                    di_issue($issues, 'payment', 'review', 'CHANGE_MISMATCH', 'Cash Change (' . ($change ?? 'blank') . ') does not reconcile with Amt Paid minus the applied amount (' . number_format($expectedChange, 2, '.', '') . ').');
                }
            }

            if ($receiptFlag !== true) {
                di_issue($issues, 'payment', 'warning', 'RECEIPT_NOT_PRINTED', 'Receipt Flag is FALSE; print count will remain zero.');
            }
            if (trim((string)($row['Collector'] ?? '')) === '') {
                di_issue($issues, 'payment', 'warning', 'COLLECTOR_DEFAULT', 'Collector is blank; the source collector stays unknown and the importing operator is recorded as cashier.');
            }

            /* existing receipt: compare before classifying as already imported */
            if ($receiptIdentity !== '' && !empty($context['receipts'][$receiptIdentity])) {
                $scope = $rule('receipt_scope');
                $matches = $context['receipts'][$receiptIdentity];
                if ($scope === 'unresolved') {
                    di_issue($issues, 'payment', 'review', 'EXISTING_RECEIPT_CONFLICT', 'A receipt with this number already exists and the receipt-number uniqueness scope has not been confirmed.');
                } else {
                    $candidate = null;
                    foreach ($matches as $existingReceipt) {
                        if ($scope === 'per_year' && $datePaid !== null && substr((string)$existingReceipt['business_date'], 0, 4) !== substr($datePaid, 0, 4)) continue;
                        $candidate = $existingReceipt;
                        break;
                    }
                    if ($candidate !== null) {
                        $conflicts = [];
                        if ($memberId !== null && (int)$candidate['member_id'] !== (int)$memberId) $conflicts[] = 'account';
                        if ($applied !== null && abs((float)$candidate['total_paid'] - (float)$applied) > 0.02) $conflicts[] = 'total';
                        if ($datePaid !== null && (string)$candidate['business_date'] !== '' && (string)$candidate['business_date'] !== $datePaid) $conflicts[] = 'date';
                        if ($conflicts) {
                            di_issue($issues, 'payment', 'review', 'EXISTING_RECEIPT_CONFLICT', 'Receipt ' . $receipt . ' already exists (transaction #' . (int)$candidate['transaction_id'] . ') with a different ' . implode(', ', $conflicts) . '. It is not counted as imported; confirm whether this is a reused number or a duplicate.');
                        } else {
                            $targetPaymentId = (int)$candidate['transaction_id'];
                            $paymentStatus = 'skipped';
                            di_issue($issues, 'payment', 'warning', 'EXISTING_RECEIPT', 'Receipt already exists with the same account, total and date; it will not be imported again.');
                        }
                    }
                }
            }

            if ($paymentStatus !== 'skipped') $paymentStatus = di_scope_status($issues, 'payment');
        }

        $importStatus = di_scope_status($issues, 'bill');
        if ($targetBillId) $importStatus = 'skipped';

        $normalized = [
            'account_number' => $account,
            'member_id' => $memberId,
            'billing_date' => $billingDate,
            'previous_date' => $previousDate,
            'due_date' => $dueDate,
            'disconnection_date' => $disconnectionDate,
            'present_reading' => $present,
            'previous_reading' => $previous,
            'consumption' => $usage,
            'source_rate_code' => $sourceRateRaw,
            'historical_rc_id' => $historicalRateId,
            'historical_pipe_size_id' => $historicalPipeSizeId,
            'bill_amount' => $billAmount,
            'penalty_amount' => $penalty,
            'compromise_amount' => $compromise,
            'penalty_effective' => number_format($penaltyEffective, 2, '.', ''),
            'compromise_effective' => number_format($compromiseEffective, 2, '.', ''),
            'receipt_no' => $receipt,
            'date_paid' => $datePaid,
            'payment_flag' => $paymentFlag,
            'receipt_flag' => $receiptFlag,
            'source_total' => $sourceTotal,
            'amt_paid' => $amtPaid,
            'tendered_amount' => $amtPaid,
            'change_amount' => $change,
            'applied_total' => $applied === null ? null : number_format($applied, 2, '.', ''),
            'partial' => $partial,
            'settled' => $settled,
            'extra_total' => number_format($extraTotal, 2, '.', ''),
            'extra_columns' => $extraNames,
            'recurring_lines' => $recurringLines,
            'source_collector' => trim((string)($row['Collector'] ?? '')),
            'source' => [
                'penalty_date' => $penaltyDate ?? trim((string)($row['Penalty Date'] ?? '')),
                'cash_rcvd' => trim((string)($row['Cash Rcvd'] ?? '')),
                'senior' => trim((string)($row['Senior'] ?? '')),
                'senior_dis_no' => trim((string)($row['SeniorDisNo'] ?? '')),
                'funos_bal' => trim((string)($row['FunosBal'] ?? '')),
                'funos_prev' => trim((string)($row['FunosPrev'] ?? '')),
                'special_account_flag' => trim((string)($row['SpecialAccountFlag'] ?? '')),
                'rate_code' => $sourceRateRaw,
            ],
            'gl_policy' => $glPolicy,
            'branch_id' => 0,
            'segment_id' => 0,
            'division_id' => 0,
            'class_id' => (int)($options['default_class_id'] ?? 0),
        ];

        return [
            'normalized' => $normalized,
            'issues' => $issues,
            'import_status' => $importStatus,
            'payment_status' => $paymentStatus,
            'recurring_status' => $recurringStatus,
            'target_member_id' => $memberId,
            'target_bill_id' => $targetBillId ? (int)$targetBillId : null,
            'target_payment_id' => $targetPaymentId,
        ];
    }
}

if (!function_exists('di_duplicate_counts')) {
    function di_duplicate_counts(string $path, string $type): array
    {
        $accountCounts = [];
        $billCounts = [];
        $receiptCounts = [];
        foreach (di_csv_rows($path) as $row) {
            if (isset($row['__csv_error'])) continue;
            $account = di_normalize_account_number($row['Account No'] ?? '');
            if ($account !== '') $accountCounts[$account] = ($accountCounts[$account] ?? 0) + 1;
            if ($type === 'billing_master') {
                $rawDate = trim((string)($row['Pres Rdg Date'] ?? ''));
                $normalizedDate = di_parse_date($rawDate);
                $billKey = $account . '|' . ($normalizedDate ?: $rawDate);
                $billCounts[$billKey] = ($billCounts[$billKey] ?? 0) + 1;
                $receipt = di_receipt_identity($row['Receipt Number'] ?? '');
                if ($receipt !== '') $receiptCounts[$receipt] = ($receiptCounts[$receipt] ?? 0) + 1;
            }
        }
        return compact('accountCounts', 'billCounts', 'receiptCounts');
    }
}

if (!function_exists('di_refresh_batch_counts')) {
    function di_refresh_batch_counts(PDO $pdo, int $batchId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) row_count, "
            . "SUM(import_status='ready') ready_count, "
            . "SUM(import_status='review') review_count, "
            . "SUM(import_status='error') error_count, "
            . "SUM(import_status='imported') imported_count, "
            . "SUM(payment_status='ready') payment_ready_count, "
            . "SUM(payment_status='review') payment_review_count, "
            . "SUM(payment_status='error') payment_error_count, "
            . "SUM(payment_status='imported') payment_imported_count "
            . ",SUM(recurring_status='ready') recurring_ready_count "
            . ",SUM(recurring_status='review') recurring_review_count "
            . ",SUM(recurring_status='error') recurring_error_count "
            . ",SUM(recurring_status='imported') recurring_imported_count "
            . 'FROM data_import_rows WHERE batch_id=?'
        );
        $stmt->execute([$batchId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($counts as $key => $value) $counts[$key] = (int)$value;
        $update = $pdo->prepare(
            'UPDATE data_import_batches SET row_count=?, ready_count=?, review_count=?, error_count=?, '
            . 'imported_count=?, payment_ready_count=?, payment_review_count=?, payment_error_count=?, payment_imported_count=?, '
            . 'recurring_ready_count=?, recurring_review_count=?, recurring_error_count=?, recurring_imported_count=? '
            . 'WHERE batch_id=?'
        );
        $update->execute([
            $counts['row_count'] ?? 0, $counts['ready_count'] ?? 0,
            $counts['review_count'] ?? 0, $counts['error_count'] ?? 0,
            $counts['imported_count'] ?? 0, $counts['payment_ready_count'] ?? 0,
            $counts['payment_review_count'] ?? 0, $counts['payment_error_count'] ?? 0,
            $counts['payment_imported_count'] ?? 0,
            $counts['recurring_ready_count'] ?? 0, $counts['recurring_review_count'] ?? 0,
            $counts['recurring_error_count'] ?? 0, $counts['recurring_imported_count'] ?? 0,
            $batchId,
        ]);
        return $counts;
    }
}

if (!function_exists('di_stage_sidecar_path')) {
    /** Derived duplicate counts for a stored source file (not part of the source). */
    function di_stage_sidecar_path(string $storedFileName): string
    {
        return di_storage_dir() . DIRECTORY_SEPARATOR . basename($storedFileName) . '.dupes.json';
    }
}

if (!function_exists('di_load_duplicates')) {
    function di_load_duplicates(string $path, string $storedFileName, string $type): array
    {
        $sidecar = di_stage_sidecar_path($storedFileName);
        if (is_file($sidecar)) {
            $cached = di_json_decode((string)@file_get_contents($sidecar));
            if (isset($cached['accountCounts'], $cached['billCounts'], $cached['receiptCounts'])) return $cached;
        }
        $duplicates = di_duplicate_counts($path, $type);
        @file_put_contents($sidecar, di_json_encode($duplicates), LOCK_EX);
        @chmod($sidecar, 0660);
        return $duplicates;
    }
}

if (!function_exists('di_validate_staged_row')) {
    /** Validate one source row (shared by staging and revalidation). */
    function di_validate_staged_row(string $type, array $row, array $options, array $context, array $duplicates): array
    {
        $account = di_normalize_account_number($row['Account No'] ?? '');
        if ($type === 'account_master') {
            $validation = di_validate_account_row($row, $options, $context, (int)($duplicates['accountCounts'][$account] ?? 0));
            $sourceKey = $account;
        } else {
            $rawBillingDate = trim((string)($row['Pres Rdg Date'] ?? ''));
            $sourceKey = $account . '|' . (di_parse_date($rawBillingDate) ?: $rawBillingDate);
            $receipt = di_receipt_identity($row['Receipt Number'] ?? '');
            $validation = di_validate_billing_row(
                $row, $options, $context,
                (int)($duplicates['billCounts'][$sourceKey] ?? 0),
                $receipt === '' ? 0 : (int)($duplicates['receiptCounts'][$receipt] ?? 0)
            );
        }
        $validation['account'] = $account;
        $validation['source_key'] = $sourceKey;
        return $validation;
    }
}

if (!function_exists('di_batch_progress_columns_ready')) {
    function di_batch_progress_columns_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready === null) $ready = di_column_exists($pdo, 'data_import_batches', 'stage_next_row');
        return $ready;
    }
}

if (!function_exists('di_revalidate_begin')) {
    /**
     * Start a (resumable) revalidation. Rows already imported keep their
     * identities and are never re-validated; only unresolved rows move.
     */
    function di_revalidate_begin(PDO $pdo, int $batchId): array
    {
        di_assert_compatible_schema($pdo);
        $pdo->beginTransaction();
        try {
            $batchStmt = $pdo->prepare('SELECT * FROM data_import_batches WHERE batch_id=? LIMIT 1 FOR UPDATE');
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Import batch was not found.');
            if (in_array((string)$batch['status'], ['staging', 'importing'], true)) {
                throw new RuntimeException('The batch is still ' . $batch['status'] . '. Let it finish or resume it before revalidating.');
            }
            $type = (string)$batch['import_type'];
            $path = di_storage_dir() . DIRECTORY_SEPARATOR . basename((string)$batch['stored_file_name']);
            if (!is_file($path)) throw new RuntimeException('The protected source CSV for this batch is missing.');
            $currentHash = hash_file('sha256', $path);
            if (!is_string($currentHash) || !hash_equals((string)$batch['source_sha256'], $currentHash)) {
                throw new RuntimeException('The protected source CSV changed after staging. Upload a new copy.');
            }
            if (di_detect_import_type(di_csv_header($path)) !== $type) {
                throw new RuntimeException('The protected source CSV no longer matches this batch.');
            }
            $set = "status='revalidating', last_error=NULL";
            $params = [];
            if (di_batch_progress_columns_ready($pdo)) {
                $set .= ', stage_next_row=0, mapping_json=?, mapping_version=?';
                $snapshot = di_rules_snapshot($pdo);
                $params[] = di_json_encode($snapshot);
                $params[] = (int)$snapshot['version'];
            }
            $params[] = $batchId;
            $pdo->prepare('UPDATE data_import_batches SET ' . $set . ' WHERE batch_id=?')->execute($params);
            $pdo->commit();
            return $batch;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('di_revalidate_chunk')) {
    /**
     * Revalidate up to $limit not-yet-imported rows (ordered by row_number)
     * starting after the stored cursor. Returns progress counters.
     */
    function di_revalidate_chunk(PDO $pdo, int $batchId, int $limit = 2000): array
    {
        $limit = max(100, min(5000, $limit));
        $pdo->beginTransaction();
        try {
            $batchStmt = $pdo->prepare('SELECT * FROM data_import_batches WHERE batch_id=? LIMIT 1 FOR UPDATE');
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Import batch was not found.');
            if ((string)$batch['status'] !== 'revalidating') throw new RuntimeException('This batch is not being revalidated.');
            $cursor = (int)($batch['stage_next_row'] ?? 0);
            $type = (string)$batch['import_type'];
            $path = di_storage_dir() . DIRECTORY_SEPARATOR . basename((string)$batch['stored_file_name']);
            $options = di_json_decode((string)$batch['options_json']);
            $context = di_validation_context($pdo);
            $duplicates = di_load_duplicates($path, (string)$batch['stored_file_name'], $type);
            $referenceProblems = di_validate_option_references($pdo, $options, $context);

            $rowsStmt = $pdo->prepare('SELECT row_id,`row_number`,raw_json,import_status,payment_status,recurring_status,target_member_id,target_bill_id,target_payment_id,target_recurring_ids_json FROM data_import_rows WHERE batch_id=? AND `row_number`>? ORDER BY `row_number` LIMIT ' . $limit);
            $rowsStmt->execute([$batchId, $cursor]);
            $update = $pdo->prepare(
                'UPDATE data_import_rows SET external_account_no=?,source_key=?,normalized_json=?,issues_json=?,'
                . 'import_status=?,payment_status=?,recurring_status=?,target_member_id=?,target_bill_id=?,target_payment_id=?,updated_at=NOW() WHERE row_id=?'
            );
            $processed = 0; $locked = 0; $lastRow = $cursor;
            while ($staged = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
                $lastRow = (int)$staged['row_number'];
                $processed++;
                $row = di_json_decode((string)$staged['raw_json']);
                $validation = di_validate_staged_row($type, $row, $options, $context, $duplicates);
                foreach ($referenceProblems as $problem) di_issue($validation['issues'], 'bill', 'review', 'MAPPING_REFERENCE_INVALID', $problem);
                if ($referenceProblems) {
                    $validation['import_status'] = $validation['import_status'] === 'error' ? 'error' : 'review';
                }
                // Already imported portions keep their identity and status.
                $importStatus = $validation['import_status'];
                $paymentStatus = $validation['payment_status'];
                $recurringStatus = $validation['recurring_status'] ?? 'not_applicable';
                $memberId = $validation['target_member_id'] ?? null;
                $billId = $validation['target_bill_id'] ?? null;
                $paymentId = $validation['target_payment_id'] ?? null;
                $lockedRow = false;
                if ((string)$staged['import_status'] === 'imported') { $importStatus = 'imported'; $memberId = $staged['target_member_id']; $billId = $staged['target_bill_id'] ?? $billId; $lockedRow = true; }
                if ((string)$staged['recurring_status'] === 'imported') { $recurringStatus = 'imported'; $lockedRow = true; }
                if ((string)$staged['payment_status'] === 'imported') { $paymentStatus = 'imported'; $paymentId = $staged['target_payment_id']; $lockedRow = true; }
                if ($lockedRow) {
                    $locked++;
                    di_issue($validation['issues'], 'bill', 'warning', 'ALREADY_IMPORTED', 'Part of this row was already imported; its identities are locked and only the remaining portions were revalidated.');
                }
                $update->execute([
                    $validation['account'] === '' ? null : $validation['account'],
                    $validation['source_key'] === '' ? null : substr($validation['source_key'], 0, 191),
                    di_json_encode($validation['normalized']), di_json_encode($validation['issues']),
                    $importStatus, $paymentStatus, $recurringStatus,
                    $memberId, $billId, $paymentId, $staged['row_id'],
                ]);
            }
            $remaining = (int)$pdo->query('SELECT COUNT(*) FROM data_import_rows WHERE batch_id=' . (int)$batchId . ' AND `row_number`>' . (int)$lastRow)->fetchColumn();
            if ($remaining <= 0) {
                $counts = di_refresh_batch_counts($pdo, $batchId);
                $allRemaining = (int)($counts['ready_count'] ?? 0) + (int)($counts['recurring_ready_count'] ?? 0) + (int)($counts['payment_ready_count'] ?? 0);
                $importedAny = (int)($counts['imported_count'] ?? 0) + (int)($counts['recurring_imported_count'] ?? 0) + (int)($counts['payment_imported_count'] ?? 0);
                $status = $importedAny > 0 ? ($allRemaining > 0 ? 'in_progress' : 'completed_with_review') : 'staged';
                $set = 'status=?, last_error=NULL, started_at=CASE WHEN ?>0 THEN started_at ELSE NULL END, completed_at=NULL';
                if (di_batch_progress_columns_ready($pdo)) $set .= ', stage_next_row=0';
                $pdo->prepare('UPDATE data_import_batches SET ' . $set . ' WHERE batch_id=?')->execute([$status, $importedAny, $batchId]);
                unset($_SESSION['di_configuration_gate'][$batchId]);
                $pdo->commit();
                di_batch_reconciliation($pdo, $batchId, true);
                return ['processed' => $processed, 'remaining' => 0, 'done' => true, 'locked' => $locked, 'counts' => $counts, 'batch_status' => $status];
            }
            if (di_batch_progress_columns_ready($pdo)) {
                $pdo->prepare('UPDATE data_import_batches SET stage_next_row=? WHERE batch_id=?')->execute([$lastRow, $batchId]);
            }
            $pdo->commit();
            return ['processed' => $processed, 'remaining' => $remaining, 'done' => false, 'locked' => $locked];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            try { $pdo->prepare("UPDATE data_import_batches SET status='failed', last_error=? WHERE batch_id=?")->execute([substr($e->getMessage(), 0, 500), $batchId]); } catch (Throwable $ignored) {}
            throw $e;
        }
    }
}

if (!function_exists('di_revalidate_batch')) {
    /** Whole-batch revalidation (loops the resumable chunks; used by tests/CLI). */
    function di_revalidate_batch(PDO $pdo, int $batchId): array
    {
        di_revalidate_begin($pdo, $batchId);
        do {
            $result = di_revalidate_chunk($pdo, $batchId, 2000);
        } while (empty($result['done']));
        return $result['counts'] ?? [];
    }
}

if (!function_exists('di_flush_stage_rows')) {
    function di_flush_stage_rows(PDO $pdo, array &$buffer): void
    {
        if (!$buffer) return;
        $columns = '(batch_id,`row_number`,external_account_no,source_key,raw_json,normalized_json,issues_json,import_status,payment_status,recurring_status,target_member_id,target_bill_id,target_payment_id,updated_at)';
        $tuple = '(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
        $params = [];
        foreach ($buffer as $row) foreach ($row as $value) $params[] = $value;
        $sql = 'INSERT INTO data_import_rows ' . $columns . ' VALUES ' . implode(',', array_fill(0, count($buffer), $tuple));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $buffer = [];
    }
}

if (!function_exists('di_stage_begin')) {
    /**
     * Create the batch record for an uploaded source and return its id.
     * Row staging happens in bounded chunks (di_stage_chunk) so a large file
     * never depends on one long request; an interrupted staging resumes from
     * the stored cursor. Nothing operational is written.
     */
    function di_stage_begin(PDO $pdo, string $path, string $originalName, string $sha256, array $options, int $userId): int
    {
        di_assert_compatible_schema($pdo);
        $headers = di_csv_header($path);
        $type = di_detect_import_type($headers);
        $context = di_validation_context($pdo); // also sets the confirmed date order
        di_load_duplicates($path, basename($path), $type);
        $snapshot = di_rules_snapshot($pdo);
        $columns = 'import_type,source_name,source_sha256,stored_file_name,status,options_json,created_by,created_at';
        $values = "?,?,?,?,'staging',?,?,NOW()";
        $params = [$type, substr($originalName, 0, 191), $sha256, basename($path), di_json_encode($options), $userId];
        if (di_batch_progress_columns_ready($pdo)) {
            $columns .= ',stage_next_row,mapping_json,mapping_version';
            $values .= ',0,?,?';
            $params[] = di_json_encode($snapshot);
            $params[] = (int)$snapshot['version'];
        }
        $stmt = $pdo->prepare('INSERT INTO data_import_batches (' . $columns . ') VALUES (' . $values . ')');
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('di_stage_chunk')) {
    /** Stage up to $limit source rows after the stored cursor. */
    function di_stage_chunk(PDO $pdo, int $batchId, int $limit = 2000): array
    {
        $limit = max(100, min(5000, $limit));
        $pdo->beginTransaction();
        try {
            $batchStmt = $pdo->prepare('SELECT * FROM data_import_batches WHERE batch_id=? LIMIT 1 FOR UPDATE');
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Import batch was not found.');
            if ((string)$batch['status'] !== 'staging') throw new RuntimeException('This batch is not being staged.');
            $type = (string)$batch['import_type'];
            $path = di_storage_dir() . DIRECTORY_SEPARATOR . basename((string)$batch['stored_file_name']);
            if (!is_file($path)) throw new RuntimeException('The protected source CSV for this batch is missing.');
            $cursor = di_batch_progress_columns_ready($pdo)
                ? (int)($batch['stage_next_row'] ?? 0)
                : (int)$pdo->query('SELECT COALESCE(MAX(`row_number`),0) FROM data_import_rows WHERE batch_id=' . (int)$batchId)->fetchColumn();
            $options = di_json_decode((string)$batch['options_json']);
            $context = di_validation_context($pdo);
            $duplicates = di_load_duplicates($path, (string)$batch['stored_file_name'], $type);
            $referenceProblems = di_validate_option_references($pdo, $options, $context);

            $buffer = [];
            $processed = 0; $lastRow = $cursor; $more = false;
            foreach (di_csv_rows($path) as $rowNumber => $row) {
                if ($rowNumber <= $cursor) continue;
                if ($processed >= $limit) { $more = true; break; }
                $validation = di_validate_staged_row($type, $row, $options, $context, $duplicates);
                foreach ($referenceProblems as $problem) di_issue($validation['issues'], $type === 'account_master' ? 'member' : 'bill', 'review', 'MAPPING_REFERENCE_INVALID', $problem);
                if ($referenceProblems && $validation['import_status'] !== 'error') $validation['import_status'] = 'review';
                $buffer[] = [
                    $batchId, $rowNumber, $validation['account'] === '' ? null : $validation['account'],
                    $validation['source_key'] === '' ? null : substr($validation['source_key'], 0, 191),
                    di_json_encode($row), di_json_encode($validation['normalized']), di_json_encode($validation['issues']),
                    $validation['import_status'], $validation['payment_status'], $validation['recurring_status'] ?? 'not_applicable',
                    $validation['target_member_id'] ?? null, $validation['target_bill_id'] ?? null, $validation['target_payment_id'] ?? null,
                ];
                if (count($buffer) >= 100) di_flush_stage_rows($pdo, $buffer);
                $processed++;
                $lastRow = (int)$rowNumber;
            }
            di_flush_stage_rows($pdo, $buffer);
            if (!$more) {
                $set = "status='staged'";
                if (di_batch_progress_columns_ready($pdo)) $set .= ', stage_next_row=0';
                $pdo->prepare('UPDATE data_import_batches SET ' . $set . ' WHERE batch_id=?')->execute([$batchId]);
                $counts = di_refresh_batch_counts($pdo, $batchId);
                $pdo->commit();
                di_batch_reconciliation($pdo, $batchId, true);
                return ['processed' => $processed, 'done' => true, 'staged_rows' => (int)($counts['row_count'] ?? 0), 'counts' => $counts];
            }
            if (di_batch_progress_columns_ready($pdo)) {
                $pdo->prepare('UPDATE data_import_batches SET stage_next_row=? WHERE batch_id=?')->execute([$lastRow, $batchId]);
            }
            $pdo->prepare('UPDATE data_import_batches SET row_count=(SELECT COUNT(*) FROM data_import_rows WHERE batch_id=?) WHERE batch_id=?')->execute([$batchId, $batchId]);
            $pdo->commit();
            $staged = (int)$pdo->query('SELECT COUNT(*) FROM data_import_rows WHERE batch_id=' . (int)$batchId)->fetchColumn();
            return ['processed' => $processed, 'done' => false, 'staged_rows' => $staged];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            try { $pdo->prepare("UPDATE data_import_batches SET last_error=? WHERE batch_id=?")->execute([substr($e->getMessage(), 0, 500), $batchId]); } catch (Throwable $ignored) {}
            throw $e;
        }
    }
}

if (!function_exists('di_source_row_estimate')) {
    /** Cheap data-row estimate (line count minus header) for progress display. */
    function di_source_row_estimate(string $path): int
    {
        $count = 0;
        $handle = @fopen($path, 'rb');
        if (!$handle) return 0;
        while (!feof($handle)) { $chunk = fread($handle, 1048576); if ($chunk === false) break; $count += substr_count($chunk, "\n"); }
        fclose($handle);
        return max(0, $count - 1);
    }
}

if (!function_exists('di_stage_csv')) {
    /** Whole-file staging (loops the resumable chunks; used by tests/CLI). */
    function di_stage_csv(PDO $pdo, string $path, string $originalName, string $sha256, array $options, int $userId): int
    {
        $batchId = di_stage_begin($pdo, $path, $originalName, $sha256, $options, $userId);
        do {
            $result = di_stage_chunk($pdo, $batchId, 2000);
        } while (empty($result['done']));
        return $batchId;
    }
}

if (!function_exists('di_store_uploaded_source')) {
    function di_store_uploaded_source(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'The source file exceeds upload_max_filesize in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The source file exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL => 'The source upload was interrupted.',
                UPLOAD_ERR_NO_FILE => 'Choose an Account Master CSV or Billing Master CSV/XLSX file to upload.',
            ];
            throw new InvalidArgumentException($messages[$error] ?? 'The source upload failed.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $name = basename((string)($file['name'] ?? 'company.csv'));
        if (!is_uploaded_file($tmp)) throw new InvalidArgumentException('The upload was not received through PHP.');
        if ($size <= 0 || $size > DI_MAX_UPLOAD_BYTES) throw new InvalidArgumentException('Source file must be between 1 byte and 50 MB.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) throw new InvalidArgumentException('Only .csv and .xlsx source files are accepted.');

        $dir = di_storage_dir();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Unable to create the protected import storage directory.');
        if (!is_writable($dir)) throw new RuntimeException('storage/imports is not writable by PHP.');
        $token = date('Ymd_His') . '_' . bin2hex(random_bytes(12));
        $destination = $dir . DIRECTORY_SEPARATOR . $token . '.csv';

        if ($extension === 'csv') {
            if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Unable to store the uploaded CSV.');
        } else {
            $xlsxPath = $dir . DIRECTORY_SEPARATOR . $token . '.xlsx';
            if (!move_uploaded_file($tmp, $xlsxPath)) throw new RuntimeException('Unable to store the uploaded XLSX workbook.');
            try {
                di_xlsx_to_csv($xlsxPath, $destination);
            } finally {
                @unlink($xlsxPath);
            }
        }
        @chmod($destination, 0660);
        $sha = hash_file('sha256', $destination);
        if (!is_string($sha) || strlen($sha) !== 64) throw new RuntimeException('Unable to fingerprint the normalized source file.');
        return ['path' => $destination, 'stored_name' => basename($destination), 'original_name' => $name, 'sha256' => $sha];
    }
}

if (!function_exists('di_store_uploaded_csv')) {
    function di_store_uploaded_csv(array $file): array
    {
        return di_store_uploaded_source($file);
    }
}

if (!function_exists('di_lookup_data')) {
    function di_lookup_data(PDO $pdo): array
    {
        $out = ['rate_codes' => [], 'pipe_sizes' => [], 'tariff_groups' => [], 'cities' => [], 'discounts' => [], 'branches' => [], 'segments' => [], 'divisions' => []];
        try { $out['rate_codes'] = $pdo->query('SELECT rc_id,rc_code,rc_name FROM rate_code ORDER BY rc_code,rc_name,rc_id')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try { $out['pipe_sizes'] = $pdo->query('SELECT pipe_size_id,size_code,size_label FROM water_pipe_sizes WHERE is_active=1 ORDER BY diameter_inches,pipe_size_id')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try {
            $out['tariff_groups'] = $pdo->query(
                'SELECT DISTINCT rc.rc_id,rc.rc_code,rc.rc_name,ps.pipe_size_id,ps.size_code,ps.size_label,ps.diameter_inches '
                . 'FROM water_meter_members wmm '
                . 'INNER JOIN rate_code rc ON rc.rc_id=wmm.rc_id '
                . 'INNER JOIN water_pipe_sizes ps ON ps.pipe_size_id=wmm.pipe_size_id AND ps.is_active=1 '
                . 'ORDER BY rc.rc_code,rc.rc_name,ps.diameter_inches,ps.pipe_size_id'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        try { $out['cities'] = $pdo->query("SELECT pkey,city,province FROM cities WHERE status='A' ORDER BY city,province")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try {
            $discountSql = di_column_exists($pdo, 'discounts', 'is_active')
                ? 'SELECT discount_id,discount_type FROM discounts WHERE is_active=1 ORDER BY discount_type,discount_id'
                : 'SELECT discount_id,discount_type FROM discounts ORDER BY discount_type,discount_id';
            $out['discounts'] = $pdo->query($discountSql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        try { $out['branches'] = $pdo->query('SELECT pkey,name FROM branches WHERE Deleted=0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try { $out['segments'] = $pdo->query("SELECT pkey,name FROM ic_segments WHERE status='A' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        try { $out['divisions'] = $pdo->query("SELECT pkey,name FROM ic_divisions WHERE status='A' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('di_batches')) {
    function di_batches(PDO $pdo, int $limit = 20): array
    {
        if (!di_schema_ready($pdo)) return [];
        $limit = max(1, min(100, $limit));
        return $pdo->query('SELECT * FROM data_import_batches ORDER BY batch_id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('di_batch')) {
    function di_batch(PDO $pdo, int $batchId): ?array
    {
        if (!di_schema_ready($pdo)) return null;
        $stmt = $pdo->prepare('SELECT * FROM data_import_batches WHERE batch_id=? LIMIT 1');
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('di_preview_rows')) {
    function di_preview_rows(PDO $pdo, int $batchId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare(
            'SELECT row_id,`row_number`,external_account_no,source_key,import_status,payment_status,recurring_status,'
            . 'issues_json,target_member_id,target_bill_id,target_payment_id,target_recurring_ids_json '
            . 'FROM data_import_rows WHERE batch_id=? '
            . "ORDER BY CASE WHEN import_status IN ('review','error') OR payment_status IN ('review','error') OR recurring_status IN ('review','error') THEN 0 ELSE 1 END, row_id LIMIT " . $limit
        );
        $stmt->execute([$batchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['issues'] = di_json_decode($row['issues_json'] ?? '[]');
        unset($row);
        return $rows;
    }
}

if (!function_exists('di_batch_summary')) {
    function di_batch_summary(PDO $pdo, int $batchId, ?array $batch = null): array
    {
        $batch = $batch ?: di_batch($pdo, $batchId);
        if (!$batch) return [];
        $type = (string)$batch['import_type'];
        $duplicatePattern = $type === 'account_master'
            ? '%"code":"DUPLICATE_ACCOUNT"%'
            : '%"code":"DUPLICATE_BILL_KEY"%';
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) total_records, "
            . "SUM(import_status IN ('ready','skipped','imported')) valid_records, "
            . "SUM(import_status='error') invalid_records, "
            . "SUM(import_status='skipped' AND (target_member_id IS NOT NULL OR target_bill_id IS NOT NULL)) existing_records, "
            . "SUM(issues_json LIKE ?) duplicate_records, "
            . "SUM(issues_json LIKE '%\"code\":\"MEMBER_NOT_FOUND\"%') unmatched_accounts, "
            . "SUM(import_status IN ('review','error') OR recurring_status IN ('review','error') OR payment_status IN ('review','error')) attention_records, "
            . "SUM(import_status='imported') imported_primary_records, "
            . "SUM(recurring_status='imported') imported_recurring_records, "
            . "SUM(payment_status='imported') imported_payment_records, "
            . "SUM(issues_json LIKE '%\"code\":\"EXISTING_ACCOUNT_DIFFERENCE\"%') existing_with_differences, "
            . "SUM(issues_json LIKE '%\"code\":\"EXISTING_ACCOUNT\"%' AND issues_json NOT LIKE '%\"code\":\"EXISTING_ACCOUNT_DIFFERENCE\"%') existing_identical, "
            . "SUM(issues_json LIKE '%\"code\":\"RATE_UNASSIGNED\"%') billing_blocked_members, "
            . "SUM(issues_json LIKE '%\"code\":\"RULE_UNRESOLVED\"%' OR issues_json LIKE '%\"code\":\"CHARGE_SEMANTICS_UNRESOLVED\"%' OR issues_json LIKE '%\"code\":\"GL_POLICY_UNRESOLVED\"%') unresolved_rule_records, "
            . "SUM(issues_json LIKE '%\"code\":\"EXISTING_RECEIPT_CONFLICT\"%') conflicting_receipts, "
            . "SUM(issues_json LIKE '%\"code\":\"PARTIAL_PAYMENT\"%') partial_payments "
            . "FROM data_import_rows WHERE batch_id=?"
        );
        $stmt->execute([$duplicatePattern, $batchId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($summary as $key => $value) $summary[$key] = (int)$value;
        $summary['updated_records'] = 0; // Existing operational records are intentionally preserved.
        $summary['new_records'] = max(0, (int)$summary['valid_records'] - (int)$summary['existing_records']);
        return $summary;
    }
}

if (!function_exists('di_username')) {
    function di_username(): string
    {
        return substr(trim((string)($_SESSION['username'] ?? 'IMPORT')), 0, 45) ?: 'IMPORT';
    }
}

if (!function_exists('di_audit')) {
    function di_audit(PDO $pdo, int $userId, string $action, string $table, string $recordId, array $details): void
    {
        if (!di_table_exists($pdo, 'water_audit_logs')) return;
        $stmt = $pdo->prepare('INSERT INTO water_audit_logs (user_id,action,table_name,record_id,details,created_at) VALUES (?,?,?,?,?,NOW())');
        $stmt->execute([$userId ?: null, $action, $table, $recordId, di_json_encode($details)]);
    }
}

if (!function_exists('di_ensure_subsidiary_ledger')) {
    function di_ensure_subsidiary_ledger(PDO $pdo, int $memberId, string $fullName): void
    {
        if (!di_table_exists($pdo, 'subsidiaryledger')) return;
        $check = $pdo->prepare('SELECT COUNT(*) FROM subsidiaryledger WHERE slcode=? AND sltype=4 AND COALESCE(isdeleted,0)=0');
        $check->execute([(string)$memberId]);
        if ((int)$check->fetchColumn() > 0) return;
        $stmt = $pdo->prepare('INSERT INTO subsidiaryledger (slcode,sltype,slname,isdeleted) VALUES (?,4,?,0)');
        $stmt->execute([(string)$memberId, substr($fullName, 0, 100)]);
    }
}

if (!function_exists('di_import_member')) {
    function di_import_member(PDO $pdo, array $normalized, int $userId): int
    {
        $account = di_normalize_account_number($normalized['account_number'] ?? '');
        $check = $pdo->prepare('SELECT pkey FROM water_accounts WHERE account_number=? LIMIT 1 FOR UPDATE');
        $check->execute([$account]);
        $existing = (int)$check->fetchColumn();
        if ($existing > 0) return $existing;

        $maxId = (int)$pdo->query('SELECT COALESCE(MAX(pkey),0) FROM water_accounts')->fetchColumn();
        $memberId = sysseq_next_numeric($pdo, 'SysMemberIDSeq', $maxId);
        $username = di_username();
        $stmt = $pdo->prepare(
            'INSERT INTO water_accounts '
            . '(pkey,lastname,firstname,middlename,datecreated,createdby,status,account_number,zone,meter_sequence,rc_id,pipe_size_id,connection_status) '
            . 'VALUES (?,?,?,?,NOW(),?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $memberId, trim((string)$normalized['lastname']) !== '' ? $normalized['lastname'] : null,
            trim((string)$normalized['firstname']) !== '' ? $normalized['firstname'] : null,
            $normalized['middlename'] !== '' ? $normalized['middlename'] : null,
            $username, $normalized['status'], $account, $normalized['zone'],
            $normalized['meter_sequence'], $normalized['rc_id'], $normalized['pipe_size_id'],
            $normalized['connection_status'],
        ]);

        if (in_array(($normalized['address_mode'] ?? 'source'), ['source', 'default'], true) && trim((string)($normalized['address'] ?? '')) !== '') {
            $address = $pdo->prepare(
                "INSERT INTO addresses (city_id,housebldg,street,other_address_detail,zippostal_code,status) VALUES (?,NULL,?,NULL,?,'A')"
            );
            $cityId = (int)($normalized['city_id'] ?? 0);
            $zip = (int)($normalized['zip'] ?? 0);
            $address->execute([$cityId > 0 ? $cityId : null, $normalized['address'], $zip > 0 ? $zip : null]);
            $addressId = (int)$pdo->lastInsertId();
            $primaryAddressTypeId = member_primary_address_type_id($pdo);
            $link = $pdo->prepare(
                "INSERT INTO memberaddress (member_key,address_key,dtaddressFrom,address_type_key,dtaddressto,status) VALUES (?,?,CURDATE(),?,NULL,'A')"
            );
            $link->execute([$memberId, $addressId, $primaryAddressTypeId]);
        }

        if (trim((string)($normalized['phone'] ?? '')) !== '' && (int)($normalized['mobile_channel_id'] ?? 0) > 0) {
            $contact = $pdo->prepare(
                "INSERT INTO membercontactchannels (conchannelkey,memberkey,activefromdate,activetodate,contactdata,status) VALUES (?,?,CURDATE(),NULL,?,'A')"
            );
            $contact->execute([(int)$normalized['mobile_channel_id'], $memberId, $normalized['phone']]);
        }

        $discountIds = [];
        if (!empty($normalized['discount_flag']) && (int)($normalized['discount_id'] ?? 0) > 0) $discountIds[] = (int)$normalized['discount_id'];
        if (!empty($normalized['senior_flag']) && (int)($normalized['senior_discount_id'] ?? 0) > 0) $discountIds[] = (int)$normalized['senior_discount_id'];
        $discountIds = array_values(array_unique($discountIds));
        if ($discountIds && di_table_exists($pdo, 'discounted_members')) {
            if (function_exists('deh_assert_member_assignments')) {
                deh_assert_member_assignments($pdo, $memberId, $discountIds, null, true);
            }
            $discount = $pdo->prepare('INSERT IGNORE INTO discounted_members (member_id,discount_id,created_at) VALUES (?,?,NOW())');
            foreach ($discountIds as $discountId) $discount->execute([$memberId, $discountId]);
        }

        $accountName = trim((string)($normalized['account_name'] ?? ''));
        if ($accountName === '') {
            $accountName = trim((string)$normalized['lastname']);
            $givenName = trim((string)$normalized['firstname'] . ' ' . (string)$normalized['middlename']);
            if ($givenName !== '') $accountName .= ', ' . $givenName;
        }
        di_ensure_subsidiary_ledger($pdo, $memberId, $accountName);
        // every water-service account belongs to a party of the Party Master (a new party per account, never merged by
        // name: possible duplicates are queued for Identity Review when the Account Master import finishes). Importing
        // an account does not make anyone a cooperative member.
        $partyId = class_exists('WaterAccounts') && class_exists('Party') && Party::ready()
            ? WaterAccounts::ensureParty($memberId, null, 'Water-service account imported from the Account Master (Data Import Management)')
            : null;
        di_audit($pdo, $userId, 'IMPORT_MEMBER', 'water_accounts', (string)$memberId, ['account_number' => $account, 'party_id' => $partyId]);
        return $memberId;
    }
}

if (!function_exists('di_period')) {
    function di_period(PDO $pdo, string $billingDate, ?string $previousDate, int $userId): int
    {
        // Audit F11: no static cache. A row savepoint can roll back a period
        // created for a failed row, so the existence check runs against the
        // database (under FOR UPDATE) for every row instead of trusting PHP
        // memory that may reference a rolled-back id.
        $code = 'IM' . str_replace('-', '', $billingDate);
        $stmt = $pdo->prepare('SELECT period_id FROM bill_periods WHERE bp_code=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$code]);
        $periodId = (int)$stmt->fetchColumn();
        if ($periodId > 0) return $periodId;
        $start = $previousDate ?: (new DateTimeImmutable($billingDate))->modify('-1 month')->format('Y-m-d');
        $insert = $pdo->prepare(
            "INSERT INTO bill_periods (bp_code,start_date,end_date,status,opened_by,closed_by,created_at,updated_at,closed_at) "
            . "VALUES (?,CONCAT(?,' 00:00:00'),CONCAT(?,' 23:59:59'),'closed',?,?,NOW(),NOW(),NOW())"
        );
        $insert->execute([$code, $start, $billingDate, $userId, $userId]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('di_import_bill')) {
    function di_import_bill(PDO $pdo, array $normalized, int $userId): int
    {
        $waterReadiness = di_water_bill_readiness($pdo);
        if (!$waterReadiness['ready']) throw new RuntimeException('Water-bill migration is blocked. ' . $waterReadiness['message']);

        $memberId = (int)($normalized['member_id'] ?? 0);
        if ($memberId <= 0) {
            $member = $pdo->prepare('SELECT pkey FROM water_accounts WHERE account_number=? LIMIT 1');
            $member->execute([di_normalize_account_number($normalized['account_number'] ?? '')]);
            $memberId = (int)$member->fetchColumn();
        }
        if ($memberId <= 0) throw new RuntimeException('Member no longer exists for this Account No.');

        $existing = $pdo->prepare('SELECT reading_id FROM bill_readings WHERE member_id=? AND billing_date=? LIMIT 1 FOR UPDATE');
        $existing->execute([$memberId, $normalized['billing_date']]);
        $readingId = (int)$existing->fetchColumn();
        if ($readingId > 0) return $readingId;

        $periodId = di_period($pdo, $normalized['billing_date'], $normalized['previous_date'], $userId);
        $historicalRateId = (int)($normalized['historical_rc_id'] ?? 0);
        $historicalPipeId = (int)($normalized['historical_pipe_size_id'] ?? 0);
        $configuration = [];
        if ($historicalRateId > 0 && $historicalPipeId > 0) {
            $cfg = $pdo->prepare(
                'SELECT rc.rc_id,rc.rc_code,rc.rc_name,ps.pipe_size_id,ps.size_label '
                . 'FROM rate_code rc INNER JOIN water_pipe_sizes ps ON ps.pipe_size_id=? '
                . 'WHERE rc.rc_id=? AND EXISTS (SELECT 1 FROM water_meter_members w WHERE w.rc_id=rc.rc_id AND w.pipe_size_id=ps.pipe_size_id) LIMIT 1'
            );
            $cfg->execute([$historicalPipeId, $historicalRateId]);
            $configuration = $cfg->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        if (!$configuration) throw new RuntimeException('The historical rate-and-pipe tariff mapping is no longer configured. Revalidate the batch.');

        $termDays = 0;
        if (!empty($normalized['due_date'])) $termDays = max(0, (int)(new DateTimeImmutable($normalized['billing_date']))->diff(new DateTimeImmutable($normalized['due_date']))->format('%r%a'));
        $disconnectionDays = 0;
        if (!empty($normalized['disconnection_date']) && !empty($normalized['due_date'])) $disconnectionDays = max(0, (int)(new DateTimeImmutable($normalized['due_date']))->diff(new DateTimeImmutable($normalized['disconnection_date']))->format('%r%a'));
        $rateSnapshot = di_json_encode([
            'rc_id' => (int)$configuration['rc_id'],
            'rc_code' => (string)$configuration['rc_code'],
            'rc_name' => (string)$configuration['rc_name'],
            'source_rate_code' => (string)($normalized['source_rate_code'] ?? ''),
            'legacy_import' => true,
        ]);
        $createdAt = $normalized['billing_date'] . ' 00:00:00';
        $stmt = $pdo->prepare(
            'INSERT INTO bill_readings '
            . '(global_ref_number,period_id,member_id,prev_reading,pres_reading,consumption,is_billed,billing_date,due_date,term_days,'
            . 'amount_due,amount_paid,penalty_amount,snapshot_version,rate_code_id_snapshot,pipe_size_id_snapshot,pipe_size_snapshot,rate_code_snapshot,'
            . 'branch_id,segment_id,division_id,is_paid,bill_lifecycle_status,entry_mode,encoded_by,created_at,updated_at,disconnection_days,disconnection_date) '
            . "VALUES (NULL,?,?,?,?,?,1,?,?,?,?,0.00,?,0,?,?,?,?,?,?,?,0,'active','BATCH',?,?,?,?,?)"
        );
        $stmt->execute([
            $periodId, $memberId, $normalized['previous_reading'], $normalized['present_reading'], $normalized['consumption'],
            $normalized['billing_date'], $normalized['due_date'], $termDays, $normalized['bill_amount'], $normalized['penalty_amount'],
            (int)$configuration['rc_id'], (int)$configuration['pipe_size_id'], (string)$configuration['size_label'], $rateSnapshot,
            (int)($normalized['branch_id'] ?? 0), (int)($normalized['segment_id'] ?? 0), (int)($normalized['division_id'] ?? 0),
            di_username(), $createdAt, $createdAt, $disconnectionDays, $normalized['disconnection_date'],
        ]);
        $readingId = (int)$pdo->lastInsertId();
        // the imported bill belongs to the class of the import (its posting, now or at cut-over, carries it too)
        if (WaterPosting::currentClass() && WaterPosting::classColumns()) {
            $pdo->prepare('UPDATE bill_readings SET class_id = ? WHERE reading_id = ?')->execute([WaterPosting::currentClass(), $readingId]);
        }

        // Confirmed GL policy: full historical posting creates the same
        // WBILL-<reading> voucher a live posting would, dated on the source
        // billing date and using the source amount (never recalculated).
        $glPolicy = (string)($normalized['gl_policy'] ?? di_rule($pdo, 'gl_policy'));
        $journalHead = null;
        if ($glPolicy === 'full_posting') {
            $journalHead = di_post_water_bill_journal($pdo, $readingId, $memberId, (float)$normalized['bill_amount'], $normalized['billing_date'], $waterReadiness['bill_code'] ?? [], $normalized);
        } elseif ($glPolicy !== 'opening_balance') {
            throw new RuntimeException('The general-ledger policy for imported history is unresolved. Confirm it in the mapping decisions and revalidate.');
        }
        // opening_balance: the bill is water history only; if it is still open at the cut-over, the Opening balances
        // step posts its open amount to the ledger (di_post_opening_chunk)
        di_audit($pdo, $userId, 'IMPORT_WATER_BILL', 'bill_readings', (string)$readingId, [
            'account_number' => $normalized['account_number'], 'billing_date' => $normalized['billing_date'],
            'historical_rc_id' => (int)$configuration['rc_id'], 'historical_pipe_size_id' => (int)$configuration['pipe_size_id'],
            'gl_policy' => $glPolicy, 'journal_head_id' => $journalHead,
        ]);
        return $readingId;
    }
}

if (!function_exists('di_import_recurring_lines')) {
    function di_import_recurring_lines(PDO $pdo, array $normalized, int $userId): array
    {
        $memberId = (int)($normalized['member_id'] ?? 0);
        if ($memberId <= 0) {
            $member = $pdo->prepare('SELECT pkey FROM water_accounts WHERE account_number=? LIMIT 1');
            $member->execute([(string)$normalized['account_number']]);
            $memberId = (int)$member->fetchColumn();
        }
        if ($memberId <= 0) throw new RuntimeException('Member no longer exists for recurring charges.');
        $billDate = (string)$normalized['billing_date'];
        $dueDate = (string)($normalized['due_date'] ?: $billDate);
        $ids = [];
        foreach (($normalized['recurring_lines'] ?? []) as $line) {
            $planId = (int)($line['plan_id'] ?? 0);
            $amount = (float)($line['amount'] ?? 0);
            if ($planId <= 0 || $amount <= 0) continue;
            $plan = $pdo->prepare(
                'SELECT p.plan_id,p.bill_code_id,p.term_days,p.disconnection_days,p.status,p.frequency,p.amount,'
                . 'c.code,c.description,c.type,c.ar_account,c.income_account,c.cogs_account,c.asset_account,'
                . 'ar.Status ar_status,inc.Status income_status,cogs.Status cogs_status,asset.Status asset_status '
                . 'FROM recurring_plans p INNER JOIN bill_codes c ON c.code_id=p.bill_code_id '
                . 'LEFT JOIN chartofaccntstbl ar ON ar.AccntID=c.ar_account '
                . 'LEFT JOIN chartofaccntstbl inc ON inc.AccntID=c.income_account '
                . 'LEFT JOIN chartofaccntstbl cogs ON cogs.AccntID=c.cogs_account '
                . 'LEFT JOIN chartofaccntstbl asset ON asset.AccntID=c.asset_account '
                . 'WHERE p.plan_id=? LIMIT 1 FOR UPDATE'
            );
            $plan->execute([$planId]);
            $planRow = $plan->fetch(PDO::FETCH_ASSOC);
            if (!$planRow) throw new RuntimeException('A mapped recurring plan no longer exists.');
            $sourceColumn = (string)($line['source_column'] ?? '');
            $planReadiness = di_recurring_plan_readiness($planRow, $sourceColumn);
            if (!$planReadiness['ready']) {
                throw new RuntimeException(($sourceColumn !== '' ? $sourceColumn . ': ' : '') . $planReadiness['message'] . ' Revalidate the batch after fixing the bill code/plan.');
            }
            $sub = $pdo->prepare('SELECT subscription_id FROM recurring_subscriptions WHERE plan_id=? AND member_id=? ORDER BY subscription_id LIMIT 1 FOR UPDATE');
            $sub->execute([$planId, $memberId]);
            $subscriptionId = (int)$sub->fetchColumn();
            if ($subscriptionId <= 0) {
                $insertSub = $pdo->prepare("INSERT INTO recurring_subscriptions (plan_id,member_id,subscription_start_date,next_billing_date,last_billed_date,status,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'ended',?,NOW(),NOW())");
                $insertSub->execute([$planId, $memberId, $billDate, $billDate, $billDate, $userId]);
                $subscriptionId = (int)$pdo->lastInsertId();
            }
            $existing = $pdo->prepare('SELECT bill_id,total_amount FROM recurring_bills WHERE subscription_id=? AND bill_date=? LIMIT 1 FOR UPDATE');
            $existing->execute([$subscriptionId, $billDate]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            if ($existingRow) {
                if (abs((float)$existingRow['total_amount'] - $amount) > 0.02) throw new RuntimeException('Existing recurring bill amount differs from the staged source.');
                $ids[] = (int)$existingRow['bill_id'];
                continue;
            }
            $term = max(0, (int)$planRow['term_days']);
            $discDays = max(0, (int)$planRow['disconnection_days']);
            $discDate = (new DateTimeImmutable($dueDate))->modify('+' . $discDays . ' days')->format('Y-m-d');
            $glPolicy = (string)($normalized['gl_policy'] ?? di_rule($pdo, 'gl_policy'));
            if (!di_gl_policy_known($glPolicy)) {
                throw new RuntimeException('The general-ledger policy for imported history is unresolved. Confirm it in the mapping decisions and revalidate.');
            }
            $revisionNote = $glPolicy === 'full_posting' ? 'LEGACY_IMPORT_GL' : 'LEGACY_IMPORT_NON_GL';
            $stmt = $pdo->prepare("INSERT INTO recurring_bills (global_ref_number,journal_voucher_id,subscription_id,plan_id,member_id,bill_code_id,billing_period_start,billing_period_end,bill_date,due_date,term_days,total_amount,gross_amount,discount_amount,amount_paid,penalty_amount,balance,status,bill_lifecycle_status,revision_note,created_by,created_at,updated_at,branch_id,segment_id,division_id,disconnection_days,disconnection_date) VALUES (NULL,NULL,?,?,?,?,?,?,?,?,?,?,?,0,0,0,?,'unpaid','active',?,?,NOW(),NOW(),?,?,?,?,?)");
            $stmt->execute([
                $subscriptionId, $planId, $memberId, (int)$planRow['bill_code_id'], $billDate, $billDate,
                $billDate, $dueDate, $term, number_format($amount, 2, '.', ''), number_format($amount, 2, '.', ''),
                number_format($amount, 2, '.', ''), $revisionNote, $userId, (int)($normalized['branch_id'] ?? 0),
                (int)($normalized['segment_id'] ?? 0), (int)($normalized['division_id'] ?? 0), $discDays, $discDate,
            ]);
            $recurringBillId = (int)$pdo->lastInsertId();
            if (WaterPosting::currentClass() && WaterPosting::classColumns()) {
                $pdo->prepare('UPDATE recurring_bills SET class_id = ? WHERE bill_id = ?')->execute([WaterPosting::currentClass(), $recurringBillId]);
            }
            if ($glPolicy === 'full_posting') {
                di_post_recurring_bill_journal($pdo, $recurringBillId, $memberId, $amount, $billDate, $planRow, $normalized);
            }
            $ids[] = $recurringBillId;
        }
        return $ids;
    }
}

if (!function_exists('di_post_water_bill_journal')) {
    /**
     * Full historical posting for an imported water bill: assigns the invoice
     * (global reference) number and creates the WBILL-<reading> voucher from
     * the WATER BILL code's AR/debit and Income/credit accounts, dated on the
     * source billing date. Idempotent through wc_journal_exists().
     */
    function di_post_water_bill_journal(PDO $pdo, int $readingId, int $memberId, float $amount, string $billingDate, array $billCode, array $normalized): ?string
    {
        if (!function_exists('wc_create_water_billing_journal_from_breakdown') || !function_exists('generateGlobalRef')) {
            throw new RuntimeException('Journal helpers are unavailable; historical posting cannot proceed.');
        }
        $ar = (int)($billCode['ar_account'] ?? 0);
        $income = (int)($billCode['income_account'] ?? 0);
        if (!di_active_chart_account($pdo, $ar) || !di_active_chart_account($pdo, $income)) {
            throw new RuntimeException('WATER BILL AR/Income accounts are missing or inactive; revalidate the batch after fixing Bill Codes.');
        }
        $current = $pdo->prepare('SELECT global_ref_number, billing_journal_voucher_id FROM bill_readings WHERE reading_id = ? LIMIT 1');
        $current->execute([$readingId]);
        $state = $current->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($state['global_ref_number'] ?? 0) <= 0) {
            $globalRef = generateGlobalRef($pdo);
            $pdo->prepare('UPDATE bill_readings SET global_ref_number = ? WHERE reading_id = ? AND (global_ref_number IS NULL OR global_ref_number = 0)')->execute([$globalRef, $readingId]);
        }
        if ($amount <= 0) return null; // zero-amount bills post no journal (same as live posting)
        $existingHead = trim((string)($state['billing_journal_voucher_id'] ?? ''));
        if ($existingHead !== '' || wc_journal_exists($pdo, 'WBILL-' . $readingId)) return $existingHead ?: null;
        $details = [
            'amount_due' => $amount,
            'ar_lines' => [['account_id' => $ar, 'amount' => $amount, 'bill_codes' => [(string)($billCode['code'] ?? 'WATER BILL')]]],
            'income_lines' => [['account_id' => $income, 'amount' => $amount, 'bill_codes' => [(string)($billCode['code'] ?? 'WATER BILL')]]],
        ];
        $voucher = wc_create_water_billing_journal_from_breakdown(
            $pdo, $readingId, $memberId, $details, $billingDate,
            (int)($normalized['branch_id'] ?? 0), (int)($normalized['segment_id'] ?? 0), (int)($normalized['division_id'] ?? 0)
        );
        $head = (string)($voucher['journal_head_id'] ?? '');
        if ($head === '') throw new RuntimeException('The historical billing journal was not created.');
        $pdo->prepare('UPDATE bill_readings SET billing_journal_voucher_id = ? WHERE reading_id = ?')->execute([$head, $readingId]);
        return $head;
    }
}

if (!function_exists('di_post_recurring_bill_journal')) {
    /**
     * Full historical posting for an imported recurring charge, the same invoice a live recurring bill posts
     * (WaterPosting, event RBILL-<bill>): Dr the bill code's A/R account, Cr its income/credit account, dated on the
     * source bill date. Idempotent on the event key.
     */
    function di_post_recurring_bill_journal(PDO $pdo, int $billId, int $memberId, float $amount, string $billDate, array $planRow, array $normalized): ?string
    {
        if ($amount <= 0) return null;
        $ar = (int)($planRow['ar_account'] ?? 0);
        $income = (int)($planRow['income_account'] ?? 0);
        if (!di_active_chart_account($pdo, $ar) || !di_active_chart_account($pdo, $income)) {
            throw new RuntimeException('Bill code "' . (string)($planRow['code'] ?? '') . '" AR/Income accounts are missing or inactive.');
        }
        $globalRef = generateGlobalRef($pdo);
        $description = mb_substr('Imported recurring bill #' . $billId . ' (' . (string)($planRow['code'] ?? '') . ') for account #' . $memberId, 0, 200);
        $posted = WaterPosting::postBill('recurring', $billId, [
            'water_account_id' => $memberId, 'txn_date' => $billDate, 'due_date' => (string)($normalized['due_date'] ?? '') ?: $billDate,
            'doc_no' => (string)$globalRef, 'ar_account_id' => $ar,
            'lines' => [['account_id' => $income, 'amount' => round($amount, 2), 'description' => $description]],
            'memo' => $description, 'reason' => 'Imported history (Data Import Management, full posting)',
            'branch_id' => (int)($normalized['branch_id'] ?? 0), 'segment_id' => (int)($normalized['segment_id'] ?? 0), 'division_id' => (int)($normalized['division_id'] ?? 0),
        ]);
        $pdo->prepare('UPDATE recurring_bills SET global_ref_number = ?, journal_voucher_id = ? WHERE bill_id = ?')
            ->execute([$globalRef, $posted['journal_head_id'] ?? null, $billId]);
        return $posted ? (string)$posted['journal_head_id'] : null;
    }
}

if (!function_exists('di_import_payment')) {
    /**
     * Historical collection import under the confirmed rules:
     *  - settlement proven by the paid rule (validated at staging);
     *  - applied amount = validated applied_total (partial allowed by rule);
     *  - allocation order: water principal → recurring charges → penalty → compromise;
     *  - Penalty and Compromise may coexist on one receipt (dedicated path);
     *  - full_posting creates the COL-<receipt> collection voucher on Date Paid;
     *  - idempotent through the receipt uniqueness index, the idempotency key
     *    and the transaction reference.
     */
    function di_import_payment(PDO $pdo, array $normalized, int $billId, array $recurringIds, int $userId): int
    {
        $glPolicy = (string)($normalized['gl_policy'] ?? di_rule($pdo, 'gl_policy'));
        if (!di_gl_policy_known($glPolicy)) {
            throw new RuntimeException('The general-ledger policy for imported history is unresolved. Confirm it in the mapping decisions and revalidate.');
        }
        if (empty($normalized['settled'])) throw new RuntimeException('This row is not proven settled under the confirmed paid rule. Revalidate the batch.');
        if ($normalized['applied_total'] === null) throw new RuntimeException('The applied amount could not be determined. Revalidate the batch.');

        $collectionReadiness = di_collection_readiness($pdo, $userId);
        if (!$collectionReadiness['ready']) throw new RuntimeException('Collection migration is blocked. ' . $collectionReadiness['message']);
        $penalty = (float)($normalized['penalty_effective'] ?? $normalized['penalty_amount'] ?? 0);
        $compromise = (float)($normalized['compromise_effective'] ?? $normalized['compromise_amount'] ?? 0);
        if ($penalty > 0 && !di_active_chart_account($pdo, di_setting_account_id($pdo, 'collection_penalty_account_id'))) {
            throw new RuntimeException('Collection migration is blocked: configure an active penalty Income/credit account.');
        }
        if ($compromise > 0 && !di_active_chart_account($pdo, di_setting_account_id($pdo, 'collection_compromise_account_id'))) {
            throw new RuntimeException('Collection migration is blocked: configure an active compromise Income/credit account.');
        }

        $receipt = trim((string)$normalized['receipt_no']);
        $receiptIdentity = di_receipt_identity($receipt);
        $check = $pdo->prepare('SELECT transaction_id, member_id, total_paid, business_date FROM payment_transactions WHERE receipt_no = ? LIMIT 1 FOR UPDATE');
        $check->execute([$receipt]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            // Never treat a conflicting receipt as an import success.
            if ((int)$existing['member_id'] !== (int)($normalized['member_id'] ?? 0)
                || abs((float)$existing['total_paid'] - (float)$normalized['applied_total']) > 0.02
                || ((string)$existing['business_date'] !== '' && (string)$existing['business_date'] !== (string)$normalized['date_paid'])) {
                throw new RuntimeException('Receipt ' . $receipt . ' already exists with a different account, total or date. Review the receipt-number scope before importing this row.');
            }
            return (int)$existing['transaction_id'];
        }

        $bill = $pdo->prepare('SELECT reading_id,member_id,amount_due,amount_paid,penalty_amount,due_date,is_paid,bill_lifecycle_status,rate_code_snapshot,branch_id,segment_id,division_id FROM bill_readings WHERE reading_id=? LIMIT 1 FOR UPDATE');
        $bill->execute([$billId]);
        $billRow = $bill->fetch(PDO::FETCH_ASSOC);
        if (!$billRow) throw new RuntimeException('The staged water bill no longer exists.');
        if ((float)$billRow['amount_paid'] > 0.005 || (int)$billRow['is_paid'] === 1) throw new RuntimeException('The water bill already has a payment allocation.');
        if ((string)$billRow['bill_lifecycle_status'] !== 'active') throw new RuntimeException('The water bill is no longer active.');

        $principal = round((float)$normalized['bill_amount'], 2);
        if (abs((float)$billRow['amount_due'] - $principal) > 0.02) {
            throw new RuntimeException('The water bill amount changed after validation. Revalidate a fresh batch before importing its payment.');
        }

        $waterCode = di_water_bill_code($pdo) ?: [];
        $waterAr = (int)($waterCode['ar_account'] ?? 0);

        $recurringRows = [];
        $lockRecurring = $pdo->prepare('SELECT rb.bill_id, rb.total_amount, rb.amount_paid, rb.due_date, c.ar_account, c.code FROM recurring_bills rb INNER JOIN bill_codes c ON c.code_id = rb.bill_code_id WHERE rb.bill_id=? LIMIT 1 FOR UPDATE');
        foreach ($recurringIds as $recurringId) {
            $lockRecurring->execute([(int)$recurringId]);
            $recurringRow = $lockRecurring->fetch(PDO::FETCH_ASSOC);
            if (!$recurringRow) throw new RuntimeException('A staged recurring bill no longer exists.');
            if ((float)$recurringRow['amount_paid'] > 0) throw new RuntimeException('A recurring bill already has a payment allocation.');
            $recurringRows[] = $recurringRow;
        }

        /* ---- allocation ------------------------------------------------- */
        $applied = round((float)$normalized['applied_total'], 2);
        $remaining = $applied;
        $waterAlloc = round(min($remaining, $principal), 2); $remaining = round($remaining - $waterAlloc, 2);
        $recurringAllocs = [];
        foreach ($recurringRows as $index => $recurringRow) {
            $alloc = round(min($remaining, (float)$recurringRow['total_amount']), 2);
            $recurringAllocs[$index] = $alloc;
            $remaining = round($remaining - $alloc, 2);
        }
        $penaltyAlloc = round(min($remaining, $penalty), 2); $remaining = round($remaining - $penaltyAlloc, 2);
        $compromiseAlloc = round(min($remaining, $compromise), 2); $remaining = round($remaining - $compromiseAlloc, 2);
        if ($remaining > 0.02) throw new RuntimeException('The applied amount exceeds the bill, charges, penalty and compromise. Revalidate the batch.');
        $recurringPrincipal = round(array_sum($recurringAllocs), 2);
        $basePaid = round($waterAlloc + $recurringPrincipal, 2);
        $totalPaid = round($basePaid + $penaltyAlloc + $compromiseAlloc, 2);
        $tendered = round((float)($normalized['tendered_amount'] ?? $applied), 2);
        $change = round(max(0.0, (float)($normalized['change_amount'] ?? 0)), 2);
        $paymentDate = $normalized['date_paid'] . ' 00:00:00';
        $printCount = !empty($normalized['receipt_flag']) ? 1 : 0;
        $branch = (int)($normalized['branch_id'] ?? $billRow['branch_id'] ?? 0);
        $segment = (int)($normalized['segment_id'] ?? $billRow['segment_id'] ?? 0);
        $division = (int)($normalized['division_id'] ?? $billRow['division_id'] ?? 0);
        $memberId = (int)$billRow['member_id'];

        /* ---- journal (full posting) ------------------------------------ */
        $journal = null;
        if ($glPolicy === 'opening_balance') {
            // the history is not in the ledger; a bill whose open balance was already brought into the ledger at the
            // cut-over is settled through the ledger, at the cashier, not by an imported historical receipt
            $inLedger = WaterPosting::billPostings('water', $billId) ? 'water bill #' . $billId : null;
            foreach ($recurringRows as $recurringRow) {
                if (!$inLedger && WaterPosting::billPostings('recurring', (int)$recurringRow['bill_id'])) $inLedger = 'recurring bill #' . (int)$recurringRow['bill_id'];
            }
            if ($inLedger) {
                throw new RuntimeException('The opening balance of ' . $inLedger . ' is already in the ledger (posted at the cut-over), so this historical receipt is not imported. Record the payment at the cashier if it was received after the cut-over.');
            }
        }
        if ($glPolicy === 'full_posting') {
            if (!di_active_chart_account($pdo, $waterAr)) throw new RuntimeException('The WATER BILL AR account is missing or inactive; the collection journal cannot be posted.');
            $creditLines = [];
            if ($waterAlloc > 0) $creditLines[] = ['account_id' => $waterAr, 'amount' => $waterAlloc, 'description' => 'Collection of imported water bill #' . $billId, 'source' => 'water', 'source_id' => $billId];
            foreach ($recurringRows as $index => $recurringRow) {
                if ($recurringAllocs[$index] <= 0) continue;
                $creditLines[] = ['account_id' => (int)$recurringRow['ar_account'], 'amount' => $recurringAllocs[$index], 'description' => 'Collection of imported ' . (string)$recurringRow['code'] . ' #' . (int)$recurringRow['bill_id'], 'source' => 'recurring', 'source_id' => (int)$recurringRow['bill_id']];
            }
            $journal = wc_create_collection_journal($pdo, $receipt, $memberId, $basePaid, $penaltyAlloc, $compromiseAlloc, (string)$normalized['date_paid'], $branch, $segment, $division, $creditLines);
            if (!$journal) throw new RuntimeException('The historical collection journal was not created.');
        }

        $idempotency = 'IMP_' . substr(hash('sha256', $receiptIdentity . '|' . $billId), 0, 48);
        $stmt = $pdo->prepare(
            'INSERT INTO payment_transactions '
            . '(receipt_no,member_id,payment_type,base_amount,penalty_amount,compromise_amount,total_paid,tendered_amount,change_amount,'
            . 'receipt_print_count,last_printed_at,cashier_id,payment_date,business_date,created_at,transaction_status,idempotency_key,'
            . 'branch_id,segment_id,division_id,journal_status,cash_tendered,journal_voucher_id,journal_head_id,journal_entry_id) '
            . "VALUES (?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?,'active',?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $receipt, $memberId, $recurringRows ? 'mixed' : 'water', number_format($basePaid, 2, '.', ''),
            number_format($penaltyAlloc, 2, '.', ''), number_format($compromiseAlloc, 2, '.', ''),
            number_format($totalPaid, 2, '.', ''), number_format($tendered, 2, '.', ''), number_format($change, 2, '.', ''),
            $printCount, $printCount ? $paymentDate : null, $userId, $paymentDate, $normalized['date_paid'], $paymentDate,
            $idempotency, $branch, $segment, $division,
            $journal ? 'posted' : 'draft', number_format($tendered, 2, '.', ''),
            $journal['journal_head_id'] ?? null, $journal['journal_head_id'] ?? null, $journal['journal_entry_id'] ?? null,
        ]);
        $transactionId = (int)$pdo->lastInsertId();
        if (WaterPosting::currentClass() && WaterPosting::classColumns()) {
            $pdo->prepare('UPDATE payment_transactions SET class_id = ? WHERE transaction_id = ?')
                ->execute([WaterPosting::currentClass(), $transactionId]);
        }

        $item = $pdo->prepare(
            'INSERT INTO payment_transaction_items '
            . '(transaction_id,source_type,source_id,description,due_date,amount,principal_amount,penalty_amount,balance_before,balance_after,allocation_status,created_at) '
            . "VALUES (?,'water',?,?,?,?,?,?,?,?,'active',?)"
        );
        $manualAlloc = round($penaltyAlloc + $compromiseAlloc, 2);
        $waterSettled = ($principal - $waterAlloc) <= 0.005;
        $item->execute([
            $transactionId, $billId, $waterSettled ? 'Imported historical water payment' : 'Imported historical water payment (partial)',
            $billRow['due_date'], number_format($waterAlloc + $manualAlloc, 2, '.', ''),
            number_format($waterAlloc, 2, '.', ''), number_format($manualAlloc, 2, '.', ''),
            number_format($principal, 2, '.', ''), number_format($principal - $waterAlloc, 2, '.', ''), $paymentDate,
        ]);
        // Penalty and Compromise are recorded together on the bill's
        // penalty_amount column (same convention as the live cashier).
        $pdo->prepare('UPDATE bill_readings SET amount_paid=?,is_paid=?,penalty_amount=?,updated_at=? WHERE reading_id=?')
            ->execute([number_format($waterAlloc, 2, '.', ''), $waterSettled ? 1 : 0, number_format($manualAlloc, 2, '.', ''), $paymentDate, $billId]);

        $recurringItem = $pdo->prepare("INSERT INTO payment_transaction_items (transaction_id,source_type,source_id,description,due_date,amount,principal_amount,penalty_amount,balance_before,balance_after,allocation_status,created_at) VALUES (?,'recurring',?,?,?,?,?,0,?,?,'active',?)");
        $markRecurring = $pdo->prepare('UPDATE recurring_bills SET amount_paid=?,balance=?,status=?,updated_at=? WHERE bill_id=?');
        foreach ($recurringRows as $index => $recurringRow) {
            $alloc = $recurringAllocs[$index];
            $total = (float)$recurringRow['total_amount'];
            $balanceAfter = round($total - $alloc, 2);
            $recurringItem->execute([$transactionId, (int)$recurringRow['bill_id'], $balanceAfter <= 0.005 ? 'Imported historical recurring payment' : 'Imported historical recurring payment (partial)', $recurringRow['due_date'], number_format($alloc, 2, '.', ''), number_format($alloc, 2, '.', ''), number_format($total, 2, '.', ''), number_format($balanceAfter, 2, '.', ''), $paymentDate]);
            $markRecurring->execute([number_format($alloc, 2, '.', ''), number_format($balanceAfter, 2, '.', ''), $balanceAfter <= 0.005 ? 'paid' : 'unpaid', $paymentDate, (int)$recurringRow['bill_id']]);
        }
        di_audit($pdo, $userId, 'IMPORT_LEGACY_PAYMENT', 'payment_transactions', (string)$transactionId, [
            'receipt_no' => $receipt, 'water_bill_id' => $billId, 'recurring_bill_ids' => array_map('intval', $recurringIds),
            'source_collector' => (string)($normalized['source_collector'] ?? ''), 'journal_status' => $journal ? 'posted' : 'draft',
            'applied' => $applied, 'partial' => !empty($normalized['partial']), 'penalty' => $penaltyAlloc, 'compromise' => $compromiseAlloc,
            'gl_policy' => $glPolicy,
        ]);
        return $transactionId;
    }
}

if (!function_exists('di_opening_candidates')) {
    /**
     * What the Opening balances step still has to post: the water bills this batch imported that are open (amount due
     * less amount paid) and not yet in the ledger, and the imported recurring charges (all batches) in the same state.
     * $count = true returns [water count, recurring count] instead of rows.
     */
    function di_opening_candidates(PDO $pdo, int $batchId, int $limit = 100, bool $count = false, bool $includeHeld = false): array
    {
        // a bill whose source row carries payment evidence still in Review (Payment Flag TRUE without a receipt or a
        // total that does not reconcile ...) may well have been paid: it is held out of the opening balances until the
        // review is resolved, unless the operator chooses to treat those bills as unpaid
        $heldWater = $includeHeld ? '' : " AND r.payment_status NOT IN ('review', 'error')";
        $heldRecurring = $includeHeld ? '' : " AND NOT EXISTS (SELECT 1 FROM water_accounts wa JOIN data_import_rows r2 ON r2.source_key = CONCAT(wa.account_number, '|', rb.bill_date)
                                                 WHERE wa.pkey = rb.member_id AND r2.payment_status IN ('review', 'error'))";
        $waterSql = "FROM data_import_rows r JOIN bill_readings b ON b.reading_id = r.target_bill_id
                      WHERE r.batch_id = ? AND r.import_status = 'imported' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active'
                        AND b.amount_due - COALESCE(b.amount_paid, 0) > 0.004
                        AND NOT EXISTS (SELECT 1 FROM water_postings w WHERE w.event_key = CONCAT('WBILL-', b.reading_id))" . $heldWater;
        $recurringSql = "FROM recurring_bills rb JOIN bill_codes c ON c.code_id = rb.bill_code_id
                          WHERE rb.revision_note = 'LEGACY_IMPORT_NON_GL' AND COALESCE(rb.bill_lifecycle_status, 'active') = 'active'
                            AND rb.status NOT IN ('paid', 'cancelled', 'deleted') AND rb.total_amount - COALESCE(rb.amount_paid, 0) > 0.004
                            AND NOT EXISTS (SELECT 1 FROM water_postings w WHERE w.event_key = CONCAT('RBILL-', rb.bill_id))" . $heldRecurring;
        if ($count) {
            $w = $pdo->prepare('SELECT COUNT(*) ' . $waterSql);
            $w->execute([$batchId]);
            return [(int)$w->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) ' . $recurringSql)->fetchColumn()];
        }
        $limit = max(1, $limit);
        $w = $pdo->prepare('SELECT b.reading_id, b.member_id, b.global_ref_number, b.billing_date, b.due_date, b.amount_due, b.amount_paid, b.branch_id, b.segment_id, b.division_id '
            . $waterSql . ' ORDER BY r.row_id LIMIT ' . $limit);
        $w->execute([$batchId]);
        $water = $w->fetchAll(PDO::FETCH_ASSOC);
        $recurring = [];
        if (count($water) < $limit) {
            $recurring = $pdo->query('SELECT rb.bill_id, rb.member_id, rb.global_ref_number, rb.bill_date, rb.due_date, rb.total_amount, rb.amount_paid, rb.branch_id, rb.segment_id, rb.division_id, c.code, c.ar_account '
                . $recurringSql . ' ORDER BY rb.bill_id LIMIT ' . ($limit - count($water)))->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['water' => $water, 'recurring' => $recurring];
    }
}

if (!function_exists('di_opening_held')) {
    /** [count, open amount] of the batch's open water bills held out of the opening balances (payment evidence in Review) */
    function di_opening_held(PDO $pdo, int $batchId): array
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(b.amount_due - COALESCE(b.amount_paid, 0)), 0) amt
                                 FROM data_import_rows r JOIN bill_readings b ON b.reading_id = r.target_bill_id
                                WHERE r.batch_id = ? AND r.import_status = 'imported' AND r.payment_status IN ('review', 'error')
                                  AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.amount_due - COALESCE(b.amount_paid, 0) > 0.004
                                  AND NOT EXISTS (SELECT 1 FROM water_postings w WHERE w.event_key = CONCAT('WBILL-', b.reading_id))");
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [(int)($row['n'] ?? 0), round((float)($row['amt'] ?? 0), 2)];
    }
}

if (!function_exists('di_cutover_date')) {
    /** The cut-over date the opening balances are dated on: the one entered, else the one recorded, else today */
    function di_cutover_date(?string $input = null): string
    {
        $date = trim((string)$input);
        if ($date === '' && class_exists('Settings')) $date = trim((string)Settings::get('water_cutover_date', ''));
        if ($date === '') $date = date('Y-m-d');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) throw new InvalidArgumentException('Enter the cut-over date as YYYY-MM-DD.');
        if ($date > date('Y-m-d')) throw new InvalidArgumentException('The cut-over date cannot be in the future.');
        return $date;
    }
}

if (!function_exists('di_post_opening_chunk')) {
    /**
     * Opening balances step of the opening_balance policy: each imported bill still open is posted to the ledger as an
     * opening invoice of its water-service account (the same event key a live bill uses, WBILL-<reading> /
     * RBILL-<bill>, so the cashier collects it like any bill): Dr the bill code's A/R account, Cr the legacy water
     * balances clearing account, dated on the cut-over date and due on the bill's own due date. Idempotent.
     * Runs only after the batch's bills, recurring charges and collections are imported.
     */
    function di_post_opening_chunk(PDO $pdo, int $batchId, int $limit, int $userId, string $cutover, bool $includeHeld = false): array
    {
        $batch = di_batch($pdo, $batchId);
        if (!$batch) throw new RuntimeException('Import batch was not found.');
        if ((string)$batch['import_type'] !== 'billing_master') throw new RuntimeException('Opening balances come from a Billing Master batch.');
        if (di_rule($pdo, 'gl_policy') !== 'opening_balance') {
            throw new RuntimeException('Opening balances are posted only under the "opening balances at the cut-over" general-ledger policy (Mapping Decisions).');
        }
        $pending = [];
        foreach (['bills' => 'water bills', 'recurring' => 'recurring charges', 'payments' => 'collections'] as $m => $label) {
            $n = di_import_eligible_count($pdo, $batchId, $m);
            if ($n > 0) $pending[] = number_format($n) . ' ' . $label;
        }
        if ($pending) {
            throw new RuntimeException('Import the Ready rows of this batch first (' . implode(', ', $pending) . ' still to import): the opening balances are what stays open after the history is in.');
        }
        $water = di_water_bill_readiness($pdo);
        if (!$water['ready']) throw new RuntimeException('Opening balances are blocked. ' . $water['message']);
        $waterAr = (int)($water['bill_code']['ar_account'] ?? 0);
        $limit = max(1, min(250, $limit));
        $posted = 0;
        $amount = 0.0;
        $failure = null;
        $pdo->beginTransaction();
        try {
            $clearing = WaterPosting::legacyClearingAccount();
            if (class_exists('Settings') && trim((string)Settings::get('water_cutover_date', '')) === '') {
                Settings::set('water_cutover_date', $cutover);
            }
            $candidates = di_opening_candidates($pdo, $batchId, $limit, false, $includeHeld);
            $items = [];
            foreach ($candidates['water'] as $b) $items[] = ['water', $b];
            foreach ($candidates['recurring'] as $b) $items[] = ['recurring', $b];
            foreach ($items as $index => [$source, $b]) {
                $savepoint = 'di_open_' . $index;
                $pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    $isWater = $source === 'water';
                    $id = (int)($isWater ? $b['reading_id'] : $b['bill_id']);
                    $open = round(($isWater ? (float)$b['amount_due'] : (float)$b['total_amount']) - (float)$b['amount_paid'], 2);
                    $billDate = substr((string)($isWater ? $b['billing_date'] : $b['bill_date']), 0, 10);
                    $globalRef = (int)($b['global_ref_number'] ?? 0);
                    if ($globalRef <= 0) {
                        $globalRef = generateGlobalRef($pdo);
                        $pdo->prepare($isWater ? 'UPDATE bill_readings SET global_ref_number = ? WHERE reading_id = ?' : 'UPDATE recurring_bills SET global_ref_number = ? WHERE bill_id = ?')
                            ->execute([$globalRef, $id]);
                    }
                    $what = $isWater ? 'water bill of ' . $billDate : (string)$b['code'] . ' charge of ' . $billDate;
                    $r = WaterPosting::postBill($source, $id, [
                        'water_account_id' => (int)$b['member_id'], 'txn_date' => $cutover,
                        'due_date' => substr((string)($b['due_date'] ?: $billDate), 0, 10), 'doc_no' => (string)$globalRef,
                        'ar_account_id' => $isWater ? $waterAr : (int)$b['ar_account'],
                        'lines' => [['account_id' => $clearing, 'amount' => $open, 'description' => mb_substr('Opening balance of the imported ' . $what, 0, 255)]],
                        'opening' => true,
                        'memo' => mb_substr('Opening balance at cut-over ' . $cutover . ': imported ' . $what . ' (open ' . number_format($open, 2) . ')', 0, 255),
                        'reason' => 'Opening balance at cut-over ' . $cutover . ' (Data Import Management batch #' . $batchId . ')',
                        'branch_id' => (int)($b['branch_id'] ?? 0), 'segment_id' => (int)($b['segment_id'] ?? 0), 'division_id' => (int)($b['division_id'] ?? 0),
                    ]);
                    if ($r) {
                        $pdo->prepare($isWater ? 'UPDATE bill_readings SET billing_journal_voucher_id = ? WHERE reading_id = ?' : 'UPDATE recurring_bills SET journal_voucher_id = ? WHERE bill_id = ?')
                            ->execute([(string)$r['journal_head_id'], $id]);
                    }
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $posted++;
                    $amount = round($amount + $open, 2);
                } catch (Throwable $rowError) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $failure = 'The opening balance of ' . $source . ' bill #' . (int)($b['reading_id'] ?? $b['bill_id']) . ' could not be posted: ' . $rowError->getMessage();
                    break;
                }
            }
            if ($posted > 0) {
                di_audit($pdo, $userId, 'IMPORT_OPENING_BALANCES', 'data_import_batches', (string)$batchId, ['cutover' => $cutover, 'posted' => $posted, 'amount' => $amount]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        if ($failure !== null) {
            throw new RuntimeException($failure . ($posted ? ' (' . $posted . ' opening balance(s) of this chunk were posted and kept.)' : ''));
        }
        [$w, $r] = di_opening_candidates($pdo, $batchId, 1, true, $includeHeld);
        return ['processed' => $posted, 'failed' => 0, 'remaining' => $w + $r, 'amount' => $amount, 'held' => di_opening_held($pdo, $batchId)[0], 'counts' => di_refresh_batch_counts($pdo, $batchId),
            'batch_status' => (string)(di_batch($pdo, $batchId)['status'] ?? '')];
    }
}

if (!function_exists('di_import_chunk')) {
    function di_import_chunk(PDO $pdo, int $batchId, string $mode, int $limit, int $userId, array $extra = []): array
    {
        if (!di_schema_ready($pdo)) throw new RuntimeException('The data importer migration has not been applied.');
        di_assert_compatible_schema($pdo);
        // what the import posts (bills, charges, collections, opening balances) carries the class of the batch: the
        // run does not start without one
        if ($mode !== 'members' && WaterPosting::currentClass() === null) {
            $classId = di_batch_class_id($pdo, $batchId);
            return WaterPosting::withClass($classId, fn() => di_import_chunk($pdo, $batchId, $mode, $limit, $userId, $extra));
        }
        if ($mode === 'opening') {
            return di_post_opening_chunk($pdo, $batchId, min($limit, 100), $userId, di_cutover_date($extra['cutover_date'] ?? null), !empty($extra['include_held']));
        }
        if (!in_array($mode, ['members', 'bills', 'recurring', 'payments'], true)) throw new InvalidArgumentException('Invalid import mode.');
        $limit = max(1, min(250, $limit));
        $pdo->beginTransaction();
        $processed = 0;
        $failed = 0;
        try {
            $batchStmt = $pdo->prepare('SELECT * FROM data_import_batches WHERE batch_id=? LIMIT 1 FOR UPDATE');
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Import batch was not found.');
            if (in_array((string)$batch['status'], ['staging', 'revalidating'], true)) throw new RuntimeException('The batch is still being ' . ((string)$batch['status'] === 'staging' ? 'staged' : 'revalidated') . '. Finish or resume that step first.');
            if ($mode === 'members' && $batch['import_type'] !== 'account_master') throw new RuntimeException('This is not an Account Master batch.');
            if (in_array($mode, ['bills', 'recurring', 'payments'], true) && $batch['import_type'] !== 'billing_master') throw new RuntimeException('This is not a Billing Master batch.');

            di_assert_import_prerequisites($pdo, $batch, $mode, $userId);

            $statusColumn = $mode === 'payments' ? 'payment_status' : ($mode === 'recurring' ? 'recurring_status' : 'import_status');
            if ($mode === 'payments') {
                $where = "batch_id=? AND payment_status='ready' AND target_bill_id IS NOT NULL AND recurring_status IN ('not_applicable','imported')";
            } elseif ($mode === 'recurring') {
                $where = "batch_id=? AND recurring_status='ready' AND import_status IN ('imported','skipped') AND target_bill_id IS NOT NULL";
            } else {
                $where = "batch_id=? AND import_status='ready'";
            }
            $rowsStmt = $pdo->prepare(
                'SELECT * FROM data_import_rows WHERE ' . $where . ' ORDER BY row_id LIMIT ' . $limit . ' FOR UPDATE'
            );
            $rowsStmt->execute([$batchId]);
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE data_import_batches SET status='importing',started_at=COALESCE(started_at,NOW()),last_error=NULL WHERE batch_id=?")
                ->execute([$batchId]);

            foreach ($rows as $index => $row) {
                $savepoint = 'di_row_' . $index;
                $pdo->exec('SAVEPOINT ' . $savepoint);
                try {
                    $normalized = di_json_decode($row['normalized_json'] ?? '{}');
                    if ($mode === 'members') {
                        $targetId = di_import_member($pdo, $normalized, $userId);
                        $pdo->prepare("UPDATE data_import_rows SET import_status='imported',target_member_id=?,imported_at=NOW(),updated_at=NOW() WHERE row_id=?")
                            ->execute([$targetId, $row['row_id']]);
                    } elseif ($mode === 'bills') {
                        if ((int)($normalized['member_id'] ?? 0) <= 0) {
                            $member = $pdo->prepare('SELECT pkey FROM water_accounts WHERE account_number=? LIMIT 1');
                            $member->execute([$normalized['account_number']]);
                            $normalized['member_id'] = (int)$member->fetchColumn();
                        }
                        $targetId = di_import_bill($pdo, $normalized, $userId);
                        $pdo->prepare("UPDATE data_import_rows SET import_status='imported',target_member_id=?,target_bill_id=?,normalized_json=?,imported_at=NOW(),updated_at=NOW() WHERE row_id=?")
                            ->execute([(int)$normalized['member_id'], $targetId, di_json_encode($normalized), $row['row_id']]);
                    } elseif ($mode === 'recurring') {
                        if ((int)($normalized['member_id'] ?? 0) <= 0) {
                            $member = $pdo->prepare('SELECT pkey FROM water_accounts WHERE account_number=? LIMIT 1');
                            $member->execute([$normalized['account_number']]);
                            $normalized['member_id'] = (int)$member->fetchColumn();
                        }
                        $targetIds = di_import_recurring_lines($pdo, $normalized, $userId);
                        $pdo->prepare("UPDATE data_import_rows SET recurring_status='imported',target_member_id=?,target_recurring_ids_json=?,normalized_json=?,imported_at=NOW(),updated_at=NOW() WHERE row_id=?")
                            ->execute([(int)$normalized['member_id'], di_json_encode($targetIds), di_json_encode($normalized), $row['row_id']]);
                    } else {
                        $targetId = di_import_payment($pdo, $normalized, (int)$row['target_bill_id'], di_json_decode($row['target_recurring_ids_json'] ?? '[]'), $userId);
                        $pdo->prepare("UPDATE data_import_rows SET payment_status='imported',target_payment_id=?,imported_at=NOW(),updated_at=NOW() WHERE row_id=?")
                            ->execute([$targetId, $row['row_id']]);
                    }
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $processed++;
                } catch (Throwable $rowError) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $issues = di_json_decode($row['issues_json'] ?? '[]');
                    di_issue($issues, $mode === 'payments' ? 'payment' : ($mode === 'recurring' ? 'recurring' : ($mode === 'members' ? 'member' : 'bill')), 'error', 'IMPORT_FAILED', $rowError->getMessage());
                    if ($mode === 'recurring' && ($row['payment_status'] ?? '') === 'ready') {
                        $pdo->prepare("UPDATE data_import_rows SET recurring_status='error',payment_status='review',issues_json=?,updated_at=NOW() WHERE row_id=?")
                            ->execute([di_json_encode($issues), $row['row_id']]);
                    } else {
                        $pdo->prepare('UPDATE data_import_rows SET ' . $statusColumn . "='error',issues_json=?,updated_at=NOW() WHERE row_id=?")
                            ->execute([di_json_encode($issues), $row['row_id']]);
                    }
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    $failed++;
                }
            }

            $counts = di_refresh_batch_counts($pdo, $batchId);
            $remaining = di_import_eligible_count($pdo, $batchId, $mode);
            $allRemaining = (int)($counts['ready_count'] ?? 0) + (int)($counts['recurring_ready_count'] ?? 0) + (int)($counts['payment_ready_count'] ?? 0);
            $attentionRemaining = (int)($counts['review_count'] ?? 0) + (int)($counts['error_count'] ?? 0)
                + (int)($counts['recurring_review_count'] ?? 0) + (int)($counts['recurring_error_count'] ?? 0)
                + (int)($counts['payment_review_count'] ?? 0) + (int)($counts['payment_error_count'] ?? 0);
            $newStatus = $allRemaining === 0 ? ($attentionRemaining > 0 ? 'completed_with_review' : 'completed') : 'in_progress';
            $pdo->prepare('UPDATE data_import_batches SET status=?,completed_at=CASE WHEN ?=0 THEN NOW() ELSE NULL END WHERE batch_id=?')
                ->execute([$newStatus, $allRemaining, $batchId]);
            $pdo->commit();
            if ($remaining <= 0) {
                try { di_batch_reconciliation($pdo, $batchId, true); } catch (Throwable $ignored) {}
                // the parties made for the imported accounts are checked against each other and the parties on file:
                // possible duplicates (same name and birth date, mobile, TIN ...) are queued for Parties > Identity Review
                if ($mode === 'members' && class_exists('Party') && Party::ready()) {
                    try { Party::findMatches(); } catch (Throwable $ignored) {}
                }
            }
            return compact('processed', 'failed', 'remaining', 'counts') + ['batch_status' => $newStatus];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // A prerequisite refusal (nothing attempted) is not a failed import:
            // the batch keeps its status and only records the reason.
            $attempted = ($processed + $failed) > 0;
            try {
                if ($attempted) {
                    $pdo->prepare("UPDATE data_import_batches SET status='failed',last_error=? WHERE batch_id=?")
                        ->execute([substr($e->getMessage(), 0, 500), $batchId]);
                } else {
                    $pdo->prepare("UPDATE data_import_batches SET last_error=? WHERE batch_id=?")
                        ->execute([substr($e->getMessage(), 0, 500), $batchId]);
                }
            } catch (Throwable $ignored) {}
            throw $e;
        }
    }
}
