<?php
declare(strict_types=1);

/**
 * v40: files attached to a transaction: the signed copy of a voucher, bill, purchase order, sales order or invoice
 * and the documents that go with it (PDF, pictures, Word, Excel, PowerPoint, web pages and most other files).
 *
 * The files are kept in the database (table txn_attachments, their content in txn_attachment_chunks in pieces of
 * 256 KB, so a file of any size goes through MySQL's usual 1 MB packet limit) and go with the database's backups.
 *
 * On the form of a transaction (Make Journal Entries, Write Checks, Cash Voucher, Enter Bills, Create Purchase Orders,
 * Create Sales Orders, Create Invoices) the Attachments box takes one or more files; they are saved with the
 * transaction when it is saved. A file chosen on a form that comes back with a question or an error is kept for the
 * next save (it is held under the form's session key until then). On the page of a saved transaction the files are
 * listed with View and Download, and Add files attaches more straight away (the signed copy after printing).
 *
 * Rights: the users who may open the transaction's page see and download its files; attaching needs the Create or
 * Edit right on the page, removing a file the Edit or Delete right. Every file attached or removed is recorded in the
 * audit trail.
 */
final class Attachments
{
    /** The transactions that take attachments */
    public const TYPES = ['journal', 'opening_balance', 'check', 'cash_voucher', 'bill', 'purchase_order', 'sales_order', 'invoice'];

    /** The posted file box, the files kept from an earlier try, and the files ticked to remove */
    public const FIELD = 'att_files';
    public const KEPT = 'att_kept';
    public const REMOVE = 'att_remove';

    private const CHUNK = 262144;           // 256 KB a piece
    private const MAX_FILE = 52428800;      // 50 MB a file at most, whatever php.ini allows

    /** Program files are refused: they are of no use as supporting documents and could do harm when opened */
    private const BLOCKED = ['exe', 'msi', 'msp', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'vbs', 'vbe', 'jse', 'wsf',
        'wsh', 'ps1', 'psm1', 'hta', 'lnk', 'reg', 'msc', 'jar', 'apk', 'app', 'deb', 'rpm', 'sh', 'php', 'phtml', 'phar'];

    /** Types the browser may show in a tab (everything else is always downloaded) */
    private const INLINE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'txt' => 'text/plain'];

    private const MIME = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'heic' => 'image/heic', 'svg' => 'image/svg+xml',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12', 'csv' => 'text/csv',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'rtf' => 'application/rtf', 'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'txt' => 'text/plain', 'htm' => 'text/html', 'html' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript',
        'json' => 'application/json', 'xml' => 'application/xml', 'eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg',
    ];

    private static ?bool $ready = null;
    /** @var int[]|null the files of this request held for the form (staged), once read */
    private static ?array $staged = null;
    /** @var string[] files that could not be taken, with the reason */
    private static array $problems = [];

    /** Whether the attachment tables are there (database/migrations/2027_05_09_accounting_update_v40.sql) */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                DB::value('SELECT COUNT(transaction_id) + COUNT(stage_key) FROM txn_attachments WHERE 1 = 0');
                self::$ready = true;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function enabled(string $type): bool
    {
        return in_array($type, self::TYPES, true) && self::ready();
    }

    /** enctype for a form that takes attachments */
    public static function enctype(string $type): string
    {
        return self::enabled($type) ? ' enctype="multipart/form-data"' : '';
    }

    // ------------------------------------------------------------------ limits

    /** The largest file the server takes */
    public static function maxFile(): int
    {
        return max(1, min(self::MAX_FILE, self::iniBytes('upload_max_filesize') ?: self::MAX_FILE, self::iniBytes('post_max_size') ?: self::MAX_FILE));
    }

    /** The most a save may carry at once (all files and the form) */
    public static function maxPost(): int
    {
        return self::iniBytes('post_max_size') ?: self::MAX_FILE;
    }

    public static function maxCount(): int
    {
        return max(1, (int)ini_get('max_file_uploads') ?: 20);
    }

    public static function iniBytes(string $key): int
    {
        $v = trim((string)ini_get($key));
        if ($v === '' || $v === '0' || $v === '-1') {
            return 0;
        }
        $n = (float)$v;
        $u = strtolower(substr($v, -1));
        $mult = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024][$u] ?? 1;
        return (int)round($n * $mult);
    }

    public static function size(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' bytes';
    }

    // ------------------------------------------------------------------ reading

    /** The files of a transaction, oldest first */
    public static function forTxn(int $txnId): array
    {
        if (!self::ready()) {
            return [];
        }
        return DB::all(
            'SELECT a.id, a.transaction_id, a.file_name, a.mime_type, a.file_size, a.uploaded_at, u.full_name AS uploaded_by_name
               FROM txn_attachments a LEFT JOIN users u ON u.id = a.uploaded_by
              WHERE a.transaction_id = :t ORDER BY a.id',
            ['t' => $txnId]
        );
    }

    /** How many files each of these transactions has: id => count */
    public static function counts(array $txnIds): array
    {
        $txnIds = array_values(array_unique(array_filter(array_map('intval', $txnIds))));
        if (!$txnIds || !self::ready()) {
            return [];
        }
        [$in, $params] = DB::in('t', $txnIds);
        $out = [];
        foreach (DB::all("SELECT transaction_id, COUNT(*) AS n FROM txn_attachments WHERE transaction_id IN $in GROUP BY transaction_id", $params) as $r) {
            $out[(int)$r['transaction_id']] = (int)$r['n'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return self::ready() ? DB::row('SELECT * FROM txn_attachments WHERE id = :id', ['id' => $id]) : null;
    }

    // ------------------------------------------------------------------ rights

    public static function canView(array $t): bool
    {
        return Access::page(Txn::page($t['txn_type'])) && Access::siteAllowed($t['site_id'] ? (int)$t['site_id'] : null);
    }

    public static function canAdd(array $t): bool
    {
        $page = Txn::page($t['txn_type']);
        return self::canView($t) && (Access::can($page, 'create') || Access::can($page, 'edit'));
    }

    public static function canRemove(array $t): bool
    {
        $page = Txn::page($t['txn_type']);
        return self::canView($t) && (Access::can($page, 'edit') || Access::can($page, 'delete'));
    }

    // ------------------------------------------------------------------ saving

    /** The key the files of this session's forms are held under until the form is saved */
    private static function stageKey(): string
    {
        if (empty($_SESSION['att_stage_key'])) {
            $_SESSION['att_stage_key'] = bin2hex(random_bytes(16));
        }
        return (string)$_SESSION['att_stage_key'];
    }

    /**
     * The files of the posted form, held under the session's key: the files uploaded now and the ones kept from an
     * earlier try (att_kept[]). Read once a request. @return int[] attachment ids
     */
    public static function staged(): array
    {
        if (self::$staged !== null) {
            return self::$staged;
        }
        self::$staged = [];
        if (!is_post() || !self::ready()) {
            return self::$staged;
        }
        $key = self::stageKey();
        $kept = array_values(array_filter(array_map('intval', (array)($_POST[self::KEPT] ?? []))));
        if ($kept) {
            [$in, $params] = DB::in('k', $kept);
            self::$staged = array_map('intval', DB::column("SELECT id FROM txn_attachments WHERE id IN $in AND transaction_id IS NULL AND stage_key = :sk ORDER BY id",
                $params + ['sk' => $key]));
        }
        foreach (self::uploadedFiles() as $f) {
            try {
                self::$staged[] = self::store($f['tmp'], $f['name'], null, $key);
            } catch (UserError $e) {
                self::$problems[] = $e->getMessage();
            }
        }
        return self::$staged;
    }

    /** The files in the posted file box: [[tmp, name]], with the ones that did not arrive noted as problems */
    private static function uploadedFiles(): array
    {
        $f = $_FILES[self::FIELD] ?? null;
        if (!is_array($f) || !isset($f['name'])) {
            return [];
        }
        $names = (array)$f['name'];
        $out = [];
        foreach ($names as $i => $name) {
            $name = (string)$name;
            $err = (int)((array)$f['error'])[$i];
            if ($err === UPLOAD_ERR_NO_FILE || $name === '') {
                continue;
            }
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                self::$problems[] = '"' . $name . '" is larger than the server accepts (' . self::size(self::maxFile()) . ' a file).';
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                self::$problems[] = '"' . $name . '" did not arrive in full (upload error ' . $err . '). Try it again.';
                continue;
            }
            $tmp = (string)((array)$f['tmp_name'])[$i];
            if (!is_uploaded_file($tmp)) {
                continue;
            }
            $out[] = ['tmp' => $tmp, 'name' => $name];
        }
        return $out;
    }

    /** A clean file name: no folders, no control characters, at most 200 characters */
    public static function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '') {
            $name = 'attachment';
        }
        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 190) . ($ext !== '' ? '.' . mb_substr($ext, 0, 9) : '');
        }
        return $name;
    }

    public static function ext(string $name): string
    {
        return strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    }

    /** Whether a file of this name is accepted ('' = yes, else the reason) */
    public static function refused(string $name): string
    {
        $ext = self::ext($name);
        if (in_array($ext, self::BLOCKED, true)) {
            return '"' . $name . '" is a program file (.' . $ext . '); program files cannot be attached.';
        }
        return '';
    }

    /**
     * Keeps one file in the database and returns its id. $txnId null: held under $stageKey for a form not saved yet.
     */
    public static function store(string $path, string $name, ?int $txnId, ?string $stageKey = null): int
    {
        $name = self::cleanName($name);
        if ($why = self::refused($name)) {
            fail($why);
        }
        $size = (int)@filesize($path);
        if ($size <= 0) {
            fail('"' . $name . '" is empty.');
        }
        if ($size > self::maxFile()) {
            fail('"' . $name . '" is larger than ' . self::size(self::maxFile()) . '.');
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            fail('"' . $name . '" could not be read.');
        }
        $ext = self::ext($name);
        $mime = self::MIME[$ext] ?? null;
        if ($mime === null && function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            $mime = $fi ? (string)@finfo_file($fi, $path) : null;
        }
        $mime = $mime ?: 'application/octet-stream';
        return DB::tx(function () use ($fh, $path, $name, $mime, $size, $txnId, $stageKey) {
            $id = (int)DB::insert('txn_attachments', [
                'transaction_id' => $txnId, 'stage_key' => $txnId ? null : $stageKey,
                'file_name' => $name, 'mime_type' => mb_substr($mime, 0, 150), 'file_size' => $size,
                'sha1' => sha1_file($path) ?: null, 'chunks' => 0, 'uploaded_by' => Auth::id(),
            ]);
            $seq = 0;
            while (!feof($fh)) {
                $data = fread($fh, self::CHUNK);
                if ($data === false || $data === '') {
                    break;
                }
                // written as hex: no character set can touch the bytes, and a piece stays well under 1 MB
                DB::run('INSERT INTO txn_attachment_chunks (attachment_id, seq, data) VALUES (:a, :s, UNHEX(:d))',
                    ['a' => $id, 's' => $seq++, 'd' => bin2hex($data)]);
            }
            fclose($fh);
            DB::update('txn_attachments', ['chunks' => $seq], 'id = :id', ['id' => $id]);
            return $id;
        });
    }

    /**
     * After a transaction is saved: the files of the form become its attachments, and the ones ticked to remove are
     * removed. Returns a line for the message ("2 files attached."), or '' when nothing changed.
     */
    public static function claim(int $txnId, string $pageKey): string
    {
        if (!self::ready() || Memorized::$capture) {
            return '';
        }
        $t = Txn::load($txnId);
        if (!$t || !in_array($t['txn_type'], self::TYPES, true)) {
            return '';
        }
        $ids = self::staged();
        $added = [];
        if ($ids) {
            [$in, $params] = DB::in('s', $ids);
            DB::run("UPDATE txn_attachments SET transaction_id = :t, stage_key = NULL WHERE id IN $in AND transaction_id IS NULL AND stage_key = :sk",
                $params + ['t' => $txnId, 'sk' => self::stageKey()]);
            $added = DB::column("SELECT file_name FROM txn_attachments WHERE id IN $in AND transaction_id = :t", $params + ['t' => $txnId]);
            foreach ($added as $n) {
                Audit::log('attach_file', Txn::module($t['txn_type']), 'transaction', $txnId, Txn::ref($t), ['page_key' => $pageKey, 'reason' => 'File attached: ' . $n]);
            }
        }
        $removed = [];
        $rm = array_values(array_filter(array_map('intval', (array)($_POST[self::REMOVE] ?? []))));
        if ($rm && self::canRemove($t)) {
            foreach ($rm as $aid) {
                $a = self::find($aid);
                if ($a && (int)$a['transaction_id'] === $txnId) {
                    self::remove($a, $t, $pageKey);
                    $removed[] = $a['file_name'];
                }
            }
        }
        self::sweep();
        foreach (self::$problems as $p) {
            flash('warning', 'Not attached: ' . $p);
        }
        $msg = [];
        if ($added) {
            $msg[] = count($added) === 1 ? '1 file attached.' : count($added) . ' files attached.';
        }
        if ($removed) {
            $msg[] = count($removed) === 1 ? '1 file removed.' : count($removed) . ' files removed.';
        }
        return implode(' ', $msg);
    }

    /** Attaches the posted files to a saved transaction (Add files on its page). Returns how many were attached. */
    public static function addNow(array $t, string $pageKey): int
    {
        $n = 0;
        foreach (self::uploadedFiles() as $f) {
            try {
                self::store($f['tmp'], $f['name'], (int)$t['id']);
                Audit::log('attach_file', Txn::module($t['txn_type']), 'transaction', (int)$t['id'], Txn::ref($t), ['page_key' => $pageKey, 'reason' => 'File attached: ' . self::cleanName($f['name'])]);
                $n++;
            } catch (UserError $e) {
                self::$problems[] = $e->getMessage();
            }
        }
        foreach (self::$problems as $p) {
            flash('warning', 'Not attached: ' . $p);
        }
        return $n;
    }

    public static function remove(array $a, array $t, string $pageKey): void
    {
        DB::run('DELETE FROM txn_attachments WHERE id = :id', ['id' => (int)$a['id']]);
        Audit::log('remove_file', Txn::module($t['txn_type']), 'transaction', (int)$t['id'], Txn::ref($t), [
            'page_key' => $pageKey, 'reason' => 'File removed: ' . $a['file_name'] . ' (' . self::size((int)$a['file_size']) . ')',
        ]);
    }

    /** Files held for forms that were never saved are let go after a day */
    private static function sweep(): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }
        DB::run('DELETE FROM txn_attachments WHERE transaction_id IS NULL AND uploaded_at < :d', ['d' => date('Y-m-d H:i:s', time() - 86400)]);
    }

    // ------------------------------------------------------------------ sending

    /** Sends a file: shown in the tab ($inline, for PDF, pictures and plain text) or downloaded */
    public static function send(array $a, bool $inline): never
    {
        @set_time_limit(0);
        $ext = self::ext((string)$a['file_name']);
        $inline = $inline && isset(self::INLINE[$ext]);
        $type = $inline ? self::INLINE[$ext] : ((string)$a['mime_type'] ?: 'application/octet-stream');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $name = (string)$a['file_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'attachment';
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . (int)$a['file_size']);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        $n = (int)$a['chunks'];
        for ($i = 0; $i < $n; $i++) {
            echo (string)DB::value('SELECT data FROM txn_attachment_chunks WHERE attachment_id = :a AND seq = :s', ['a' => (int)$a['id'], 's' => $i]);
            flush();
        }
        exit;
    }

    // ------------------------------------------------------------------ the boxes

    public static function icon(string $name): string
    {
        $ext = self::ext($name);
        return match (true) {
            $ext === 'pdf' => 'file-earmark-pdf',
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'svg'], true) => 'file-earmark-image',
            in_array($ext, ['doc', 'docx', 'rtf', 'odt'], true) => 'file-earmark-word',
            in_array($ext, ['xls', 'xlsx', 'xlsm', 'csv', 'ods'], true) => 'file-earmark-excel',
            in_array($ext, ['ppt', 'pptx', 'odp'], true) => 'file-earmark-ppt',
            in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar'], true) => 'file-earmark-zip',
            in_array($ext, ['htm', 'html', 'css', 'js', 'json', 'xml'], true) => 'file-earmark-code',
            in_array($ext, ['txt', 'eml', 'msg'], true) => 'file-earmark-text',
            default => 'file-earmark',
        };
    }

    private static function fileUrl(int $id, bool $inline = false): string
    {
        return url('file.attachment', ['id' => $id] + ($inline ? ['view' => 1] : []));
    }

    private static function canInline(string $name): bool
    {
        return isset(self::INLINE[self::ext($name)]);
    }

    private static function limitsText(): string
    {
        return 'PDF, pictures, Word, Excel, PowerPoint, web pages and other files, up to ' . self::size(self::maxFile()) . ' each.';
    }

    private static function dataAttrs(): string
    {
        return ' data-att-max="' . self::maxFile() . '" data-att-post="' . self::maxPost() . '" data-att-count="' . self::maxCount() . '"';
    }

    private static function fileRow(array $a, string $extra = ''): string
    {
        $meta = self::size((int)$a['file_size']);
        if (!empty($a['uploaded_by_name']) || !empty($a['uploaded_at'])) {
            $meta .= ', ' . trim(($a['uploaded_by_name'] ?? '') . ' ' . fdatetime($a['uploaded_at'] ?? ''));
        }
        $id = (int)$a['id'];
        $view = self::canInline((string)$a['file_name'])
            ? '<a class="btn btn-sm btn-light att-btn" href="' . h(self::fileUrl($id, true)) . '" target="_blank" rel="noopener" title="Open in a new tab"><i class="bi bi-eye"></i><span> View</span></a>' : '';
        return '<li class="att-item" data-att-id="' . $id . '"><span class="att-ico"><i class="bi bi-' . self::icon((string)$a['file_name']) . '"></i></span>'
            . '<span class="att-text"><a class="att-name" href="' . h(self::fileUrl($id)) . '" title="Download ' . h($a['file_name']) . '">' . h($a['file_name']) . '</a>'
            . '<span class="att-meta">' . h($meta) . '</span></span>'
            . '<span class="att-actions">' . $view
            . '<a class="btn btn-sm btn-light att-btn" href="' . h(self::fileUrl($id)) . '" title="Download"><i class="bi bi-download"></i><span> Download</span></a>'
            . $extra . '</span></li>';
    }

    /**
     * The Attachments box of a form (inside the <form>, which needs enctype="multipart/form-data": see enctype()).
     * $t: the transaction being edited, or null for a new one.
     */
    public static function formCard(string $type, ?array $t, bool $section = false): string
    {
        if (!self::enabled($type)) {
            return '';
        }
        $existing = $t ? self::forTxn((int)$t['id']) : [];
        $kept = [];
        if (self::staged()) {
            [$in, $params] = DB::in('s', self::staged());
            $kept = DB::all("SELECT id, file_name, file_size FROM txn_attachments WHERE id IN $in ORDER BY id", $params);
        }
        $removing = array_map('intval', (array)($_POST[self::REMOVE] ?? []));
        $canRemove = $t ? self::canRemove($t) : false;
        $count = count($existing) + count($kept);
        // a box of its own, or ($section) a part of the form's card
        $h = ($section ? '<div class="card-body border-top att-card att-section" data-att' . self::dataAttrs() . '>' : '<div class="card mb-3 att-card" data-att' . self::dataAttrs() . '>')
            . '<div class="' . ($section ? 'att-head att-head-plain' : 'card-header att-head') . '"><span class="att-title"><i class="bi bi-paperclip"></i> Attachments</span>'
            . '<span class="att-count" data-att-count-label>' . ($count ? $count . ' file' . ($count === 1 ? '' : 's') : 'None yet') . '</span></div>'
            . ($section ? '<div>' : '<div class="card-body">');
        foreach (self::$problems as $p) {
            $h .= '<div class="att-problem"><i class="bi bi-exclamation-triangle"></i> Not attached: ' . h($p) . '</div>';
        }
        $h .= '<ul class="att-list" data-att-list>';
        foreach ($existing as $a) {
            $rm = in_array((int)$a['id'], $removing, true);
            $extra = $canRemove
                ? '<label class="btn btn-sm btn-light att-btn att-rm" title="Remove this file when the form is saved"><input type="checkbox" class="d-none" name="' . self::REMOVE . '[]" value="' . (int)$a['id'] . '"' . ($rm ? ' checked' : '') . ' data-att-remove>'
                  . '<i class="bi bi-x-lg"></i><span> Remove</span></label>' : '';
            $h .= str_replace('<li class="att-item"', '<li class="att-item' . ($rm ? ' is-removing' : '') . '"', self::fileRow($a, $extra));
        }
        foreach ($kept as $a) {
            $h .= '<li class="att-item is-new"><input type="hidden" name="' . self::KEPT . '[]" value="' . (int)$a['id'] . '">'
                . '<span class="att-ico"><i class="bi bi-' . self::icon((string)$a['file_name']) . '"></i></span>'
                . '<span class="att-text"><span class="att-name">' . h($a['file_name']) . '</span><span class="att-meta">' . h(self::size((int)$a['file_size'])) . ', attached when you save</span></span>'
                . '<span class="att-actions"><button type="button" class="btn btn-sm btn-light att-btn" data-att-drop title="Take it off"><i class="bi bi-x-lg"></i><span> Remove</span></button></span></li>';
        }
        $h .= '</ul>'
            . '<label class="att-drop">'
            . '<input type="file" name="' . self::FIELD . '[]" multiple data-att-input>'
            . '<span class="att-drop-ico"><i class="bi bi-cloud-arrow-up"></i></span>'
            . '<span class="att-drop-text"><strong>Add files</strong> <span class="att-drop-or">or drop them here</span>'
            . '<span class="att-drop-hint">' . h(self::limitsText()) . ' They are saved with the ' . h(strtolower(Txn::label($type))) . ' when you click Save.</span></span>'
            . '</label>'
            . '<div class="att-warn" data-att-warn hidden></div>'
            . '</div></div>';
        return $h;
    }

    /** The Attachments box on the page of a saved transaction: its files, with Add files (attached straight away) */
    public static function viewCard(array $t, string $pageKey): string
    {
        if (!self::enabled((string)$t['txn_type']) || !self::canView($t)) {
            return '';
        }
        $files = self::forTxn((int)$t['id']);
        $canAdd = self::canAdd($t);
        $canRemove = self::canRemove($t);
        if (!$files && !$canAdd) {
            return '';
        }
        $count = count($files);
        $h = '<div class="card mb-3 att-card" id="attachments"' . self::dataAttrs() . '>'
            . '<div class="card-header att-head"><span class="att-title"><i class="bi bi-paperclip"></i> Attachments</span>'
            . '<span class="att-count">' . ($count ? $count . ' file' . ($count === 1 ? '' : 's') : 'None yet') . '</span></div>'
            . '<div class="card-body">';
        if ($files) {
            $h .= '<ul class="att-list">';
            foreach ($files as $a) {
                $extra = $canRemove
                    ? '<form method="post" action="' . h(url('file.attachment', ['a' => 'remove', 'id' => (int)$a['id']])) . '" class="d-inline">'
                      . csrf_field() . '<button class="btn btn-sm btn-light att-btn att-rm" title="Remove" data-confirm="Remove ' . h($a['file_name']) . ' from ' . h(Txn::ref($t)) . '?"><i class="bi bi-x-lg"></i><span> Remove</span></button></form>' : '';
                $h .= self::fileRow($a, $extra);
            }
            $h .= '</ul>';
        } else {
            $h .= '<p class="att-empty">No files yet. Attach the signed copy and its supporting documents here.</p>';
        }
        if ($canAdd) {
            $h .= '<form method="post" enctype="multipart/form-data" action="' . h(url('file.attachment', ['a' => 'add', 'txn' => (int)$t['id']])) . '" data-att data-att-now' . self::dataAttrs() . '>'
                . csrf_field()
                . '<label class="att-drop att-drop-sm">'
                . '<input type="file" name="' . self::FIELD . '[]" multiple data-att-input>'
                . '<span class="att-drop-ico"><i class="bi bi-cloud-arrow-up"></i></span>'
                . '<span class="att-drop-text"><strong>Add files</strong> <span class="att-drop-or">or drop them here</span>'
                . '<span class="att-drop-hint">' . h(self::limitsText()) . '</span></span></label>'
                . '<ul class="att-list att-queue" data-att-list></ul>'
                . '<div class="att-warn" data-att-warn hidden></div>'
                . '<div class="att-send" data-att-send hidden><button class="btn btn-primary btn-sm"><i class="bi bi-upload"></i> Attach <span data-att-n></span></button></div>'
                . '</form>';
        }
        return $h . '</div></div>';
    }

    /** A paper clip with the number of files, for the lists ('' when none) */
    public static function clip(int $n): string
    {
        return $n > 0 ? ' <span class="att-clip" title="' . $n . ' attached file' . ($n === 1 ? '' : 's') . '"><i class="bi bi-paperclip"></i>' . ($n > 1 ? $n : '') . '</span>' : '';
    }
}
