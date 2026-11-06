<?php
declare(strict_types=1);

/**
 * Data migration: the former COWASCO Waters database -> the integrated cooperative + water database.
 *
 *   php tools/migrate_water.php --source=mnssmc_test [--dry-run] [--phase=NAME] [--cutover=YYYY-MM-DD] [--company=N]
 *
 *   --source    the former water database (same MySQL server; opened read-only, never written to)
 *   --dry-run   runs every phase inside one transaction, rolls everything back and prints the counts
 *   --phase     runs one phase only: reference, coa, users, bill_codes, accounts, suppliers, membership, history, verify
 *   --coa-map   a chart-of-accounts crosswalk the accountant reviewed (the map_to_code column of the
 *               <run>-coa_crosswalk.csv a dry run writes): those former accounts map to existing accounts
 *   --import-membership --savings-product=CODE
 *               brings the legacy cooperative membership register (membershipinfo) and its savings balances into
 *               the cooperative members and savings modules (opening balances against 39998 Legacy Savings Opening
 *               Balance Clearing). Without it the register is kept read-only and written to CSV files for review.
 *   --cutover   the opening-balance date (default: today); the legacy ledger is taken up to this date
 *   --company   the company whose database is the target (multi-company installs); default: the first company
 *   --allow-existing  go on although the target water tables hold rows that did not come from the source (they are
 *               kept; a row with the same key is never overwritten). Without it the tool stops at the pre-flight.
 *
 * The target is the database of config/config.php (or DB_NAME), like the other tools.
 *
 * Restartable and idempotent: every phase runs in its own transaction and detects what it already wrote (rows with
 * the same primary key, the crosswalk tables water_coa_map / water_user_map / party_crosswalk, the event keys of
 * water_postings), so a second run writes nothing new. Every phase writes a water_migration_log row.
 *
 * The full methodology is in docs/WATER_MIGRATION.md.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/water_migration_lib.php';

final class WaterMigration
{
    public const PHASES = ['reference', 'coa', 'users', 'bill_codes', 'accounts', 'suppliers', 'membership', 'history', 'verify'];
    public const CLEARING_CODE = '39999';
    public const CLEARING_NAME = 'Legacy Water Balances Clearing';
    public const SAV_CLEARING_CODE = '39998';
    public const SAV_CLEARING_NAME = 'Legacy Savings Opening Balance Clearing';
    public const OB_EVENT = 'LEGACY-OB';
    public const SAVOB_EVENT = 'LEGACY-SAVOB';

    /** Options of the run: coa_map (CSV file), import_membership (bool), savings_product (code) */
    private array $opt = [];
    /** legacy AccntID => target account code, from the --coa-map file the accountant reviewed */
    private array $coaOverride = [];

    private PDO $src;
    private string $srcDb;
    private string $dstDb;
    private string $runId;
    private string $cutover;
    private bool $dryRun;
    private int $adminId;
    private array $log = [];               // log rows written at the end of a dry run
    private array $exceptions = [];        // group => [lines]
    private array $userMap = [];           // legacy operator id => user id (null = skipped)
    private array $coaMap = [];            // legacy AccntID => account id (null = skipped)
    private array $colCache = [];
    private array $pkCache = [];
    public bool $verifyOk = true;
    public array $verifyRows = [];

    public function __construct(string $srcDb, string $cutover, bool $dryRun, array $opt = [])
    {
        $this->opt = $opt;
        if (!empty($opt['coa_map'])) {
            $this->coaOverride = wm_read_coa_map((string)$opt['coa_map']);
        }
        $this->srcDb = $srcDb;
        $this->dstDb = DB::databaseName();
        if ($srcDb === $this->dstDb) {
            throw new RuntimeException('The source and the target are the same database.');
        }
        $this->src = wm_open_source($srcDb);
        $this->cutover = $cutover;
        $this->dryRun = $dryRun;
        $this->runId = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
        $this->adminId = (int)Auth::id();
        $this->loadMaps();
    }

    public function runId(): string
    {
        return $this->runId;
    }

    private function loadMaps(): void
    {
        $this->userMap = [];
        foreach (DB::all('SELECT legacy_operator_id, user_id FROM water_user_map') as $r) {
            $this->userMap[(int)$r['legacy_operator_id']] = $r['user_id'] !== null ? (int)$r['user_id'] : null;
        }
        $this->coaMap = [];
        foreach (DB::all('SELECT legacy_accnt_id, account_id FROM water_coa_map') as $r) {
            $this->coaMap[(int)$r['legacy_accnt_id']] = $r['account_id'] !== null ? (int)$r['account_id'] : null;
        }
    }

    // ------------------------------------------------------------------ running

    /**
     * Rows already in a target water table: [foreign, conflicting] = rows whose key is not in the source at all, and
     * rows with a source key whose content differs from the source (not written by this tool, or changed since).
     */
    public function existingRows(string $srcTable, string $dstTable, array $opt = []): array
    {
        $srcCols = $this->columns($this->srcDb, $srcTable);
        $dstCols = $this->columns($this->dstDb, $dstTable);
        if (!$srcCols || !$dstCols) {
            return [0, 0];
        }
        $pk = $this->pk($dstTable);
        $on = implode(' AND ', array_map(fn($c) => "t.`$c` = s.`$c`", $pk));
        $foreign = (int)DB::value("SELECT COUNT(*) FROM `$dstTable` t LEFT JOIN `{$this->srcDb}`.`$srcTable` s ON $on WHERE s.`{$pk[0]}` IS NULL");
        $skip = array_merge($pk, $opt['users'] ?? [], $opt['accounts'] ?? [], $dstTable === 'water_billing_settings' ? ['setting_value'] : []);
        $diff = [];
        foreach (array_intersect(array_keys($srcCols), array_keys($dstCols)) as $c) {
            if (in_array($c, $skip, true)) {
                continue;
            }
            $cond = "NOT (t.`$c` <=> s.`$c`)";
            if (in_array($dstCols[$c][0], ['date', 'datetime', 'timestamp', 'year'], true)) {
                $cond = "($cond AND NOT (s.`$c` LIKE '0000%'))";       // a zero date is rewritten on purpose
            }
            $diff[] = $cond;
        }
        $conflicting = $diff ? (int)DB::value("SELECT COUNT(*) FROM `$dstTable` t JOIN `{$this->srcDb}`.`$srcTable` s ON $on WHERE " . implode(' OR ', $diff)) : 0;
        return [$foreign, $conflicting];
    }

    /** The target water tables must be empty, or hold only what an earlier run of this tool wrote */
    public function preflight(bool $allowExisting): bool
    {
        $rows = [];
        foreach (WM_TABLES as $phase => $tables) {
            foreach ($tables as $srcTable => [$dstTable, $opt]) {
                [$foreign, $conflicting] = $this->existingRows($srcTable, $dstTable, $opt);
                if ($foreign > 0 || $conflicting > 0) {
                    $rows[] = [$dstTable, $foreign, $conflicting];
                }
            }
        }
        if (!$rows) {
            return true;
        }
        wm_out('PRE-FLIGHT: the target water tables already hold rows that did not come from ' . $this->srcDb . ':');
        wm_out(wm_table(['Target table', 'Rows not in source', 'Rows differing from source'], $rows));
        if ($allowExisting) {
            wm_out('--allow-existing given: those rows are kept as they are; differing rows are listed in the exceptions.');
            foreach ($rows as [$t, $f, $c]) {
                if ($c > 0) {
                    $this->note('pre-existing rows', "$t: $c row(s) with a source key already exist with different content; they were kept, not overwritten.");
                }
                if ($f > 0) {
                    $this->note('pre-existing rows', "$t: $f row(s) exist that are not in the source database (left untouched).");
                }
            }
            return true;
        }
        wm_out('The migration expects empty water tables (a fresh install plus the 2026_12_01 migration). Remove those rows, or run with --allow-existing to keep them (their keys are then skipped).');
        return false;
    }

    public function run(array $phases): bool
    {
        wm_out('Water-billing data migration  run ' . $this->runId . ($this->dryRun ? '  (DRY RUN: nothing will be kept)' : ''));
        wm_out('  source: ' . $this->srcDb . '   target: ' . $this->dstDb . '   cutover: ' . $this->cutover . '   acting as user #' . $this->adminId);
        wm_out();
        $ok = true;
        if ($this->dryRun) {
            DB::begin();
        }
        foreach ($phases as $phase) {
            $t0 = microtime(true);
            wm_out('== Phase ' . $phase);
            $fn = 'phase' . str_replace('_', '', ucwords($phase, '_'));
            try {
                $res = $this->dryRun ? $this->$fn() : DB::tx(fn() => $this->$fn());
                $res += ['read' => 0, 'written' => 0, 'skipped' => 0, 'amount_in' => null, 'amount_out' => null, 'notes' => []];
                $status = $phase === 'verify' && !$this->verifyOk ? 'failed' : 'done';
                $this->writeLog($phase, $status, $res);
                wm_out(sprintf('   %s: read %d, written %d, skipped %d  (%.1fs)', $status, $res['read'], $res['written'], $res['skipped'], microtime(true) - $t0));
                if ($status === 'failed') {
                    $ok = false;
                }
            } catch (Throwable $e) {
                if ($this->dryRun) {
                    DB::rollBack();
                }
                $this->writeLog($phase, 'failed', ['notes' => ['error' => $e->getMessage()]], true);
                wm_err('   FAILED: ' . $e->getMessage());
                wm_err('   at ' . basename($e->getFile()) . ':' . $e->getLine());
                $this->printExceptions();
                return false;
            }
        }
        if ($this->dryRun) {
            DB::rollBack();
            foreach ($this->log as $row) {
                DB::insert('water_migration_log', $row);
            }
            wm_out();
            wm_out('Dry run: everything was rolled back; the counts above are what a real run would write.');
        }
        $this->printExceptions();
        return $ok;
    }

    private function writeLog(string $phase, string $status, array $res, bool $now = false): void
    {
        $notes = $res['notes'] ?? [];
        if ($this->exceptions && $phase !== 'verify') {
            $notes['exceptions'] = array_map('count', $this->exceptions);
        }
        $json = json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 60000) {
            $json = json_encode(['truncated' => true] + array_filter($notes, fn($v) => !is_array($v) || count($v) < 200), JSON_UNESCAPED_UNICODE);
        }
        $row = [
            'run_id' => $this->runId, 'phase' => $phase, 'status' => $this->dryRun ? 'dry-run' : $status,
            'rows_read' => (int)($res['read'] ?? 0), 'rows_written' => (int)($res['written'] ?? 0), 'rows_skipped' => (int)($res['skipped'] ?? 0),
            'amount_in' => isset($res['amount_in']) ? r2($res['amount_in']) : null, 'amount_out' => isset($res['amount_out']) ? r2($res['amount_out']) : null,
            'notes' => $json,
        ];
        if ($this->dryRun && !$now) {
            $this->log[] = $row;
            return;
        }
        if ($this->dryRun) {
            $row['status'] = 'failed';
        }
        DB::insert('water_migration_log', $row);
    }

    private function note(string $group, string $line): void
    {
        $this->exceptions[$group][] = $line;
    }

    public function printExceptions(): void
    {
        if (!$this->exceptions) {
            wm_out();
            wm_out('Exceptions: none.');
            return;
        }
        wm_out();
        wm_out('EXCEPTIONS (review after the run)');
        $full = '';
        foreach ($this->exceptions as $group => $lines) {
            wm_out('-- ' . $group . ' (' . count($lines) . ')');
            $full .= '-- ' . $group . ' (' . count($lines) . ")\n";
            foreach ($lines as $i => $l) {
                $full .= '   ' . $l . "\n";
                if ($i < 25) {
                    wm_out('   ' . $l);
                } elseif ($i === 25) {
                    wm_out('   ... and ' . (count($lines) - 25) . ' more (full list in the exceptions file)');
                }
            }
        }
        $dir = APP_ROOT . '/storage/water_migration';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $this->runId . ($this->dryRun ? '-dryrun' : '') . '-exceptions.txt';
        if (@file_put_contents($file, $full) !== false) {
            wm_out('Full exceptions list: ' . $file);
        }
    }

    // ------------------------------------------------------------------ generic row copy

    private function columns(string $db, string $table): array
    {
        return $this->colCache["$db.$table"] ??= wm_columns(DB::pdo(), $db, $table);
    }

    private function pk(string $table): array
    {
        return $this->pkCache[$table] ??= wm_pk(DB::pdo(), $this->dstDb, $table);
    }

    /**
     * Copies every row of $srcTable into $dstTable with the same primary key, skipping keys already present.
     * $opt: users => [columns remapped through water_user_map], accounts => [columns remapped through water_coa_map],
     *       transform => fn(array $row): ?array (null drops the row), where => SQL condition on the source
     * Returns [read, written, skipped, zero_dates, unmapped_users, unmapped_accounts]
     */
    private function copyTable(string $srcTable, string $dstTable, array $opt = []): array
    {
        $srcCols = $this->columns($this->srcDb, $srcTable);
        $dstCols = $this->columns($this->dstDb, $dstTable);
        if (!$srcCols) {
            $this->note('tables', "Source table $srcTable does not exist; nothing copied.");
            return ['read' => 0, 'written' => 0, 'skipped' => 0, 'zero_dates' => 0, 'unmapped_users' => 0, 'unmapped_accounts' => 0];
        }
        if (!$dstCols) {
            throw new RuntimeException("Target table $dstTable does not exist. Run database/migrations/2026_12_01_water_billing.sql first.");
        }
        $common = array_values(array_intersect(array_keys($srcCols), array_keys($dstCols)));
        // columns the transform fills that the source does not have (water_accounts.legacy_member_id)
        $insCols = array_values(array_unique(array_merge($common, array_values(array_intersect($opt['extra'] ?? [], array_keys($dstCols))))));
        $pk = $this->pk($dstTable);
        if (!$pk) {
            throw new RuntimeException("Target table $dstTable has no primary key.");
        }
        $existing = [];
        foreach (DB::all('SELECT `' . implode('`,`', $pk) . '` FROM `' . $dstTable . '`') as $r) {
            $existing[wm_pk_key($r, $pk)] = true;
        }
        $dateCols = [];
        foreach ($common as $c) {
            if (in_array($dstCols[$c][0], ['date', 'datetime', 'timestamp', 'year'], true)) {
                $dateCols[] = $c;
            }
        }
        $userCols = array_values(array_intersect($opt['users'] ?? [], $common));
        $acctCols = array_values(array_intersect($opt['accounts'] ?? [], $common));
        $transform = $opt['transform'] ?? null;
        $stats = ['read' => 0, 'written' => 0, 'skipped' => 0, 'zero_dates' => 0, 'unmapped_users' => 0, 'unmapped_accounts' => 0];
        $sql = 'SELECT `' . implode('`,`', $common) . '` FROM `' . $srcTable . '`' . (!empty($opt['where']) ? ' WHERE ' . $opt['where'] : '');
        $stmt = $this->src->query($sql);
        $batch = [];
        $flush = function () use (&$batch, $dstTable, $insCols) {
            if (!$batch) {
                return;
            }
            $ph = '(' . implode(',', array_fill(0, count($insCols), '?')) . ')';
            $sql = 'INSERT INTO `' . $dstTable . '` (`' . implode('`,`', $insCols) . '`) VALUES ' . implode(',', array_fill(0, count($batch), $ph));
            $params = [];
            foreach ($batch as $row) {
                foreach ($insCols as $c) {
                    $params[] = $row[$c] ?? null;
                }
            }
            DB::pdo()->prepare($sql)->execute($params);
            $batch = [];
        };
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats['read']++;
            if (isset($existing[wm_pk_key($row, $pk)])) {
                $stats['skipped']++;
                continue;
            }
            // zero dates: NULL when the column allows it, else the row's own creation date, else now
            foreach ($dateCols as $c) {
                if (wm_is_zero_date($row[$c])) {
                    $stats['zero_dates']++;
                    if ($dstCols[$c][1]) {
                        $row[$c] = null;
                    } else {
                        $fallback = null;
                        foreach (['created_at', 'datecreated', 'date_created', 'dateCreated', 'CreateTime'] as $f) {
                            if (isset($row[$f]) && !wm_is_zero_date($row[$f]) && $f !== $c) {
                                $fallback = $row[$f];
                                break;
                            }
                        }
                        $row[$c] = $fallback ?? date($dstCols[$c][0] === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
                    }
                }
            }
            foreach ($userCols as $c) {
                if ($row[$c] === null || (int)$row[$c] === 0) {
                    continue;
                }
                $mapped = $this->mapUser((int)$row[$c]);
                if ($mapped === null) {
                    $stats['unmapped_users']++;
                    $mapped = $dstCols[$c][1] ? null : $this->adminId;
                }
                $row[$c] = $mapped;
            }
            foreach ($acctCols as $c) {
                if ($row[$c] === null || (int)$row[$c] === 0) {
                    continue;
                }
                $mapped = $this->coaMap[(int)$row[$c]] ?? null;
                if ($mapped === null) {
                    $stats['unmapped_accounts']++;
                    $this->note('accounts', "$dstTable." . $c . ' of row ' . wm_pk_key($row, $pk) . ': legacy account ' . $row[$c] . ' is not mapped; set to ' . ($dstCols[$c][1] ? 'NULL' : '0') . '.');
                    $mapped = $dstCols[$c][1] ? null : 0;
                }
                $row[$c] = $mapped;
            }
            if ($transform) {
                $row = $transform($row);
                if ($row === null) {
                    $stats['skipped']++;
                    continue;
                }
            }
            $batch[] = $row;
            $existing[wm_pk_key($row, $pk)] = true;
            $stats['written']++;
            if (count($batch) >= 200) {
                $flush();
            }
        }
        $flush();
        if ($stats['unmapped_users'] > 0) {
            $this->note('users', "$dstTable: " . $stats['unmapped_users'] . ' row(s) referenced a skipped or unknown legacy operator (set to NULL, or to the migrating user where the column is NOT NULL).');
        }
        if ($stats['zero_dates'] > 0) {
            $this->note('dates', "$dstTable: " . $stats['zero_dates'] . ' zero date(s) replaced (NULL, or the row\'s own creation date / today where the column is NOT NULL).');
        }
        return $stats;
    }

    private function copyGroup(string $phase, array $skipTables = []): array
    {
        $tot = ['read' => 0, 'written' => 0, 'skipped' => 0, 'notes' => ['tables' => []]];
        foreach (WM_TABLES[$phase] as $srcTable => [$dstTable, $opt]) {
            if (in_array($srcTable, $skipTables, true)) {
                continue;
            }
            $s = $this->copyTable($srcTable, $dstTable, $opt);
            wm_out(sprintf('   %-34s -> %-40s read %6d  written %6d  skipped %6d', $srcTable, $dstTable, $s['read'], $s['written'], $s['skipped']));
            $tot['read'] += $s['read'];
            $tot['written'] += $s['written'];
            $tot['skipped'] += $s['skipped'];
            $tot['notes']['tables'][$dstTable] = ['read' => $s['read'], 'written' => $s['written'], 'skipped' => $s['skipped']];
        }
        return $tot;
    }

    private function phaseOfTable(string $srcTable): string
    {
        foreach (WM_TABLES as $phase => $tables) {
            if (isset($tables[$srcTable])) {
                return $phase;
            }
        }
        return '';
    }

    private function mapUser(int $legacyId): ?int
    {
        return $this->userMap[$legacyId] ?? null;
    }

    private function srcAll(string $sql, array $params = []): array
    {
        $st = $this->src->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function srcValue(string $sql, array $params = [])
    {
        $st = $this->src->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    private function srcCount(string $table, string $where = '1 = 1'): int
    {
        if (!$this->columns($this->srcDb, $table)) {
            return 0;
        }
        return (int)$this->srcValue("SELECT COUNT(*) FROM `$table` WHERE $where");
    }

    // ------------------------------------------------------------------ phase 1: reference tables

    private function phaseReference(): array
    {
        $res = $this->copyGroup('reference');
        // the water branches post under a class (branch) of the ledger; the cooperative branch list gets them too
        $made = ['classes' => [], 'coop_branches' => []];
        foreach (DB::all('SELECT pkey, name, description, class_id FROM branches ORDER BY pkey') as $b) {
            $name = mb_substr(trim((string)$b['name']), 0, 80);
            if ($name === '') {
                continue;
            }
            $classId = $b['class_id'] ? (int)$b['class_id'] : (int)(DB::value('SELECT id FROM classes WHERE name = :n', ['n' => $name]) ?: 0);
            if (!$classId) {
                $classId = DB::insert('classes', ['name' => $name, 'description' => 'Water utility branch (migrated)', 'is_active' => 1, 'sort_order' => 90, 'created_by' => $this->adminId]);
                $made['classes'][] = $classId;
                $res['written']++;
            }
            if (!$b['class_id']) {
                DB::run('UPDATE branches SET class_id = :c WHERE pkey = :id', ['c' => $classId, 'id' => $b['pkey']]);
            }
            if (!DB::value('SELECT id FROM coop_branches WHERE name = :n', ['n' => mb_substr($name, 0, 120)])) {
                $code = 'WB' . (int)$b['pkey'];
                if (DB::value('SELECT id FROM coop_branches WHERE code = :c', ['c' => $code])) {
                    $code = 'W' . substr(md5($name), 0, 8);
                }
                $first = (int)DB::value('SELECT COUNT(*) FROM coop_branches') === 0;
                $id = DB::insert('coop_branches', ['code' => $code, 'name' => mb_substr($name, 0, 120), 'address' => mb_substr((string)$b['description'], 0, 255) ?: null,
                    'class_id' => $classId, 'is_head_office' => $first ? 1 : 0, 'or_prefix' => '', 'is_active' => 1]);
                $made['coop_branches'][] = $id;
                $res['written']++;
            }
        }
        $res['notes']['made'] = $made;
        Audit::log('migrate', 'water', 'migration', null, 'reference ' . $this->runId, ['page_key' => 'water.settings', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 2: chart of accounts

    /** The legacy chart as a tree: AccntID => row, with the names of every ancestor */
    private function legacyChart(): array
    {
        $rows = [];
        foreach ($this->srcAll('SELECT * FROM chartofaccntstbl ORDER BY AccntID') as $r) {
            $rows[(int)$r['AccntID']] = $r;
        }
        return $rows;
    }

    private function ancestors(array $chart, int $id): array
    {
        $names = [];
        $seen = [];
        $cur = (int)($chart[$id]['ParentAccnt'] ?? 0);
        while ($cur > 0 && isset($chart[$cur]) && !isset($seen[$cur])) {
            $seen[$cur] = true;
            $names[] = strtoupper(trim((string)$chart[$cur]['AccntName']));
            $cur = (int)$chart[$cur]['ParentAccnt'];
        }
        return $names;
    }

    private function categoryOf(array $chart, int $id): string
    {
        static $byDigit = [1 => 'Asset', 2 => 'Liability', 3 => 'Equity', 4 => 'Income', 5 => 'Expense'];
        $digit = (int)substr((string)$id, 0, 1);
        if (isset($byDigit[$digit])) {
            return $byDigit[$digit];
        }
        // codes outside 1..5 (the 7xxxx / 8xxxx expense groups): the class the account sits under
        $cur = $id;
        $seen = [];
        while ($cur > 0 && isset($chart[$cur]) && !isset($seen[$cur])) {
            $seen[$cur] = true;
            if ((int)$chart[$cur]['Type'] === 1) {
                return $byDigit[(int)substr((string)$cur, 0, 1)] ?? 'Expense';
            }
            $cur = (int)$chart[$cur]['ParentAccnt'];
        }
        return 'Expense';
    }

    private function accountTypeOf(string $category, string $name, array $ancestors, bool $isAr): string
    {
        $anc = implode(' / ', $ancestors);
        switch ($category) {
            case 'Asset':
                if (preg_match('/cash|bank|petty|coci|vault/i', $name)) {
                    return 'Bank';
                }
                // typed by what the account IS (its name), never by how a bill code happened to use it: a
                // misused account stays what it is and the bill code is reported (mapping exceptions block posting)
                if (stripos($name, 'Receivable') !== false) {
                    return 'Accounts Receivable';
                }
                if (stripos($anc, 'PROPERTY, PLANT AND EQUIPMENT') !== false) {
                    return 'Fixed Asset';
                }
                return 'Other Current Asset';
            case 'Liability':
                if (stripos($name, 'Payable') !== false && stripos($anc, 'CURRENT') !== false && stripos($anc, 'NON-CURRENT') === false && stripos($anc, 'NON CURRENT') === false) {
                    return 'Accounts Payable';
                }
                if (stripos($anc, 'CURRENT') !== false && stripos($anc, 'NON-CURRENT') === false && stripos($anc, 'NON CURRENT') === false) {
                    return 'Other Current Liability';
                }
                return 'Long Term Liability';
            case 'Equity':
                return 'Equity';
            case 'Income':
                return 'Income';
            default:
                return stripos($name, 'Cost of') !== false ? 'Cost of Goods Sold' : 'Expense';
        }
    }

    private function phaseCoa(): array
    {
        $chart = $this->legacyChart();
        $res = ['read' => count($chart), 'written' => 0, 'skipped' => 0, 'notes' => ['imported' => 0, 'matched' => 0, 'roots' => 0, 'suffixed' => []]];
        // the accounts a bill code or the settings use as A/R
        $arIds = [];
        foreach ($this->srcAll('SELECT ar_account FROM bill_codes WHERE ar_account IS NOT NULL') as $r) {
            $arIds[(int)$r['ar_account']] = true;
        }
        $v = (int)$this->srcValue("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_billing_default_ar_account_id'");
        if ($v > 0) {
            $arIds[$v] = true;
        }
        $existingCodes = [];
        $existingNames = [];
        foreach (DB::all('SELECT id, code, name, category FROM accounts') as $r) {
            $existingCodes[(string)$r['code']] = (int)$r['id'];
            $existingNames[(int)$r['id']] = [Party::normName((string)$r['name']), (string)$r['category']];
        }
        // the reviewed crosswalk file: legacy AccntID -> an account the target chart already has
        foreach ($this->coaOverride as $legacyId => $targetCode) {
            if (array_key_exists($legacyId, $this->coaMap)) {
                continue;
            }
            if (!isset($chart[$legacyId])) {
                $this->note('chart of accounts', "Crosswalk file: legacy account $legacyId is not in the former chart; line ignored.");
                continue;
            }
            $target = $existingCodes[(string)$targetCode] ?? null;
            if (!$target) {
                throw new RuntimeException("Crosswalk file: legacy account $legacyId points to account code $targetCode, which is not in the target chart of accounts.");
            }
            DB::insert('water_coa_map', ['legacy_accnt_id' => $legacyId, 'legacy_name' => mb_substr((string)$chart[$legacyId]['AccntName'], 0, 45), 'legacy_type' => (int)$chart[$legacyId]['Type'],
                'account_id' => $target, 'how' => 'matched', 'note' => mb_substr('crosswalk file: -> ' . $targetCode, 0, 255)]);
            $this->coaMap[$legacyId] = $target;
            $res['written']++;
            $res['notes']['matched']++;
        }
        $mapRow = function (int $legacyId, array $r, ?int $accountId, string $how, string $note) use (&$res) {
            if (array_key_exists($legacyId, $this->coaMap)) {
                $res['skipped']++;
                return;
            }
            DB::insert('water_coa_map', ['legacy_accnt_id' => $legacyId, 'legacy_name' => mb_substr((string)$r['AccntName'], 0, 45), 'legacy_type' => (int)$r['Type'],
                'account_id' => $accountId, 'how' => $how, 'note' => mb_substr($note, 0, 255)]);
            $this->coaMap[$legacyId] = $accountId;
            $res['written']++;
        };
        // summary accounts (Type 4) first: they are the parents; then the detail accounts (Type 3)
        foreach ([4, 3] as $type) {
            foreach ($chart as $id => $r) {
                if ((int)$r['Type'] !== $type) {
                    continue;
                }
                if (array_key_exists($id, $this->coaMap)) {
                    $res['skipped']++;
                    continue;
                }
                $name = mb_substr(trim((string)$r['AccntName']), 0, 120);
                $category = $this->categoryOf($chart, $id);
                $ancestors = $this->ancestors($chart, $id);
                $accountType = $this->accountTypeOf($category, $name, $ancestors, isset($arIds[$id]));
                $parentId = null;
                if ($type === 3) {
                    $summary = (int)$r['SummaryAccnt'];
                    if ($summary > 0 && isset($chart[$summary]) && (int)$chart[$summary]['Type'] === 4) {
                        $parentId = $this->coaMap[$summary] ?? null;
                        if ($parentId && (string)DB::value('SELECT category FROM accounts WHERE id = :id', ['id' => $parentId]) !== $category) {
                            $this->note('chart of accounts', "Account $id $name: its summary account $summary is of another category; imported without a parent.");
                            $parentId = null;
                        }
                    }
                }
                $code = (string)$id;
                if (isset($existingCodes[$code])) {
                    $same = $existingNames[$existingCodes[$code]] ?? ['', ''];
                    if ($same[0] !== '' && $same[0] === Party::normName($name) && $same[1] === $category) {
                        // the same account in both charts: same code, same name, same category (a key match, never a name match alone)
                        $mapRow($id, $r, $existingCodes[$code], 'matched', 'same code, name and category as the target chart');
                        $res['notes']['matched']++;
                        continue;
                    }
                    $code .= 'W';
                    $res['notes']['suffixed'][] = $id;
                    $this->note('chart of accounts', "Account $id $name: the code $id is already used by the cooperative chart for \"" . DB::value('SELECT name FROM accounts WHERE id = :i', ['i' => $existingCodes[(string)$id]]) . "\"; imported as $code. If they are the same account, map it in a crosswalk file (--coa-map) and run again on a fresh target.");
                }
                // the legacy class the account sits under disagrees with its code (data entry in the former program)
                $base = (int)$r['BaseAccnt'];
                $baseCat = ['1' => 'Asset', '2' => 'Liability', '3' => 'Equity', '4' => 'Income', '5' => 'Expense'][substr((string)$base, 0, 1)] ?? null;
                if ($baseCat && $baseCat !== $category) {
                    $this->note('chart of accounts', "Account $id $name: the code says $category but the former program listed it under $baseCat (BaseAccnt $base); imported as $category (by code). Review on Chart of Accounts.");
                }
                if (isset($arIds[$id]) && stripos($name, 'Receivable') === false) {
                    $this->note('chart of accounts', "Account $id $name is used as the A/R account of a bill code or the default A/R setting although it is not a receivable; imported as $accountType (what it is). The bill code is listed in the mapping exceptions and cannot post until its A/R account is corrected on the Bill Codes page.");
                }
                $accountId = DB::insert('accounts', [
                    'code' => $code, 'name' => $name !== '' ? $name : ('Legacy account ' . $id), 'account_type' => $accountType, 'category' => $category,
                    'normal_balance' => in_array($category, ['Asset', 'Expense'], true) ? 'Debit' : 'Credit',
                    'parent_id' => $parentId, 'description' => 'Imported from COWASCO chart of accounts (legacy AccntID ' . $id . ')',
                    'with_sl' => (int)$r['WithSL'] === 1 ? 1 : 0, 'is_active' => strtoupper(trim((string)$r['Status'])) === 'A' ? 1 : 0, 'is_system' => 0,
                ]);
                $existingCodes[$code] = $accountId;
                $mapRow($id, $r, $accountId, 'imported', ($type === 4 ? 'summary account' : 'detail account') . ' -> ' . $accountType . ($parentId ? ', under ' . $r['SummaryAccnt'] : ''));
                $res['notes']['imported']++;
            }
        }
        // roots and classes (Type 0, 1, 2) are structure only
        foreach ($chart as $id => $r) {
            if ((int)$r['Type'] > 2) {
                continue;
            }
            if (array_key_exists($id, $this->coaMap)) {
                $res['skipped']++;
                continue;
            }
            $mapRow($id, $r, null, 'skipped', 'root / class / group of the legacy tree (Type ' . (int)$r['Type'] . '); not an account');
            $res['notes']['roots']++;
        }
        Accts::reset();

        // the water default accounts (only the ones still unset)
        $settings = [];
        foreach ($this->srcAll('SELECT setting_key, setting_value FROM water_billing_settings') as $s) {
            $settings[$s['setting_key']] = $s['setting_value'];
        }
        $defaults = [
            'water_ar'         => $this->coaMap[(int)($settings['water_billing_default_ar_account_id'] ?? 0)] ?? null,
            'water_income'     => $this->coaMap[(int)($settings['water_billing_default_income_account_id'] ?? 0)] ?? null,
            'water_penalty'    => (int)($settings['collection_penalty_account_id'] ?? 0) > 0 ? ($this->coaMap[(int)$settings['collection_penalty_account_id']] ?? null) : null,
            'water_compromise' => (int)($settings['collection_compromise_account_id'] ?? 0) > 0 ? ($this->coaMap[(int)$settings['collection_compromise_account_id']] ?? null) : null,
        ];
        $cashGl = (int)($this->srcValue('SELECT cashGL FROM profile WHERE profID = 1 AND cashGL > 0') ?: 0);
        if ($cashGl <= 0) {
            $cashGl = (int)($this->srcValue('SELECT cashGL FROM profile WHERE cashGL > 0 AND deleted = 0 ORDER BY profID LIMIT 1') ?: 0);
        }
        $defaults['water_cash'] = $cashGl > 0 ? ($this->coaMap[$cashGl] ?? null) : null;
        $clearing = DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => self::CLEARING_CODE]);
        if (!$clearing) {
            $clearing = DB::insert('accounts', ['code' => self::CLEARING_CODE, 'name' => self::CLEARING_NAME, 'account_type' => 'Equity', 'category' => 'Equity', 'normal_balance' => 'Credit',
                'description' => 'Offset of the legacy water receivables and ledger balances brought in at cutover (tools/migrate_water.php)', 'with_sl' => 0, 'is_active' => 1, 'is_system' => 0]);
            $res['written']++;
            $res['notes']['clearing_created'] = (int)$clearing;
        }
        $defaults['water_legacy_clearing'] = (int)$clearing;
        $res['notes']['defaults'] = [];
        foreach ($defaults as $key => $accountId) {
            $cur = DB::row('SELECT setting_key, account_id FROM default_accounts WHERE setting_key = :k', ['k' => $key]);
            if (!$cur) {
                DB::insert('default_accounts', ['setting_key' => $key, 'label' => ucwords(str_replace('_', ' ', $key)), 'module' => 'water', 'account_id' => $accountId]);
                $res['notes']['defaults'][$key] = $accountId;
                $res['written']++;
            } elseif ($cur['account_id'] === null && $accountId) {
                DB::run('UPDATE default_accounts SET account_id = :a WHERE setting_key = :k', ['a' => $accountId, 'k' => $key]);
                $res['notes']['defaults'][$key] = $accountId;
                $res['written']++;
            } elseif ($cur['account_id'] === null && !$accountId) {
                $this->note('default accounts', "$key stays unset (the former program had no value for it). Set it on Financial > Module Default Accounts (Water) when needed.");
            }
        }
        Accts::reset();
        // the crosswalk as it stands, for the accountant's review (edit the account_code column of any line that should
        // point to an existing account and pass the file back with --coa-map on a fresh target)
        $xw = DB::all("SELECT m.legacy_accnt_id, m.legacy_name, m.legacy_type, m.how, a.code, a.name, a.account_type, m.note, '' AS map_to_code
                         FROM water_coa_map m LEFT JOIN accounts a ON a.id = m.account_id ORDER BY m.legacy_accnt_id");
        $file = wm_write_csv(APP_ROOT . '/storage/water_migration/' . $this->runId . ($this->dryRun ? '-dryrun' : '') . '-coa_crosswalk.csv',
            ['legacy_accnt_id', 'legacy_name', 'legacy_type', 'how', 'account_code', 'account_name', 'account_type', 'note', 'map_to_code'], $xw);
        if ($file !== '') {
            wm_out('   chart of accounts crosswalk for review: ' . $file);
            $res['notes']['crosswalk_file'] = basename($file);
        }
        Audit::log('migrate', 'water', 'migration', null, 'coa ' . $this->runId, ['page_key' => 'coa.accounts', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 3: users, roles, page access

    private function pageKeyOf(string $legacyKey): ?string
    {
        if (isset(WM_MOVED_PAGES[$legacyKey])) {
            return WM_MOVED_PAGES[$legacyKey];
        }
        if (WaterPages::isRetired($legacyKey)) {
            return null;
        }
        return 'water.' . $legacyKey;
    }

    private function phaseUsers(): array
    {
        $res = ['read' => 0, 'written' => 0, 'skipped' => 0, 'notes' => ['users_created' => [], 'users_matched' => [], 'roles_created' => [], 'user_roles' => [], 'overrides' => [], 'must_change_password' => []]];
        $pages = DB::pairs('SELECT page_key, id FROM pages');
        $profiles = [];
        foreach ($this->srcAll('SELECT * FROM profile ORDER BY profID') as $p) {
            $profiles[(int)$p['profID']] = $p;
        }
        $assignments = $this->srcAll('SELECT op.pkey, op.operatorID, op.profID, op.is_super_admin FROM operator_profile op ORDER BY op.pkey');
        $perms = [];      // assignment pkey => [page id => true]
        $unknownPages = [];
        foreach ($this->srcAll('SELECT operator_profile_id, page_key FROM operator_profile_page_permissions WHERE can_access = 1') as $pp) {
            $key = $this->pageKeyOf((string)$pp['page_key']);
            if ($key === null) {
                continue;                // retired page without a replacement
            }
            if (!isset($pages[$key])) {
                $unknownPages[$key] = true;
                continue;
            }
            $perms[(int)$pp['operator_profile_id']][(int)$pages[$key]] = true;
        }
        foreach (array_keys($unknownPages) as $k) {
            $this->note('page access', "Page $k is not registered in the integrated system; the right was dropped.");
        }
        $adminRole = (int)(DB::value('SELECT id FROM roles WHERE is_admin = 1 ORDER BY id LIMIT 1') ?: 0);

        // operators -> users
        foreach ($this->srcAll('SELECT * FROM operator ORDER BY pkey') as $op) {
            $res['read']++;
            $legacyId = (int)$op['pkey'];
            if (array_key_exists($legacyId, $this->userMap)) {
                $res['skipped']++;
                continue;
            }
            $username = mb_substr(trim((string)$op['name']), 0, 50);
            if ((int)$op['isSystem'] === 1 || (int)$op['deleted'] === 1) {
                DB::insert('water_user_map', ['legacy_operator_id' => $legacyId, 'legacy_name' => mb_substr($username, 0, 45), 'user_id' => null,
                    'note' => 'skipped: ' . ((int)$op['isSystem'] === 1 ? 'system operator' : 'deleted operator')]);
                $this->userMap[$legacyId] = null;
                $res['skipped']++;
                $this->note('users', "Operator $legacyId '$username' was " . ((int)$op['isSystem'] === 1 ? 'a system operator' : 'deleted') . ': no user account; its references point to the migrating user (NOT NULL columns) or NULL.');
                continue;
            }
            $existing = DB::row('SELECT id, username, status FROM users WHERE username = :u', ['u' => $username]);
            if ($existing) {
                $userId = (int)$existing['id'];
                DB::insert('water_user_map', ['legacy_operator_id' => $legacyId, 'legacy_name' => mb_substr($username, 0, 45), 'user_id' => $userId, 'note' => 'matched: a user with the same username existed']);
                $res['notes']['users_matched'][] = $userId;
                $this->note('users', "Operator $legacyId '$username' matched the existing user #$userId (same username); the legacy password was not copied.");
            } else {
                $pw = (string)$op['password'];
                $info = password_get_info($pw);
                $isHash = !empty($info['algo']);
                $cashGl = 0;
                $cashRows = $this->srcAll('SELECT p.cashGL, op.is_super_admin FROM operator_profile op JOIN profile p ON p.profID = op.profID
                                           WHERE op.operatorID = ? AND p.cashGL > 0 ORDER BY op.is_super_admin DESC, op.pkey', [$legacyId]);
                if ($cashRows) {
                    $cashGl = (int)$cashRows[0]['cashGL'];
                }
                $cashAccount = $cashGl > 0 ? ($this->coaMap[$cashGl] ?? null) : null;
                if ($cashGl > 0 && !$cashAccount) {
                    $this->note('users', "Operator $legacyId '$username': its cash account (legacy $cashGl) is not in the chart; set the cash account on User Accounts.");
                }
                $userId = DB::insert('users', [
                    'username' => $username, 'password_hash' => $isHash ? $pw : Auth::hash($pw !== '' ? $pw : bin2hex(random_bytes(8))),
                    'full_name' => mb_substr(trim((string)$op['description']) ?: $username, 0, 120), 'email' => null,
                    'coop_cash_account_id' => $cashAccount, 'status' => 'active', 'approval_status' => 'approved', 'created_via' => 'migration',
                    'approved_by' => $this->adminId, 'approved_at' => date('Y-m-d H:i:s'), 'is_platform' => 0,
                    'must_change_password' => $isHash ? 0 : 1, 'created_by' => $this->adminId,
                ]);
                DB::insert('water_user_map', ['legacy_operator_id' => $legacyId, 'legacy_name' => mb_substr($username, 0, 45), 'user_id' => $userId,
                    'note' => $isHash ? 'created: bcrypt password copied' : 'created: password re-hashed, must change at first login']);
                $res['notes']['users_created'][] = $userId;
                if (!$isHash) {
                    $res['notes']['must_change_password'][] = $username;
                    $this->note('users', "User '$username' (#$userId): the former program stored a plain-text password; it was hashed and the user must change it at the first login.");
                }
                $res['written']++;
            }
            $this->userMap[$legacyId] = $userId;
        }

        // roles: one per legacy profile with at least one assignment of a migrated operator
        $roleOfProfile = [];
        $rolePages = [];
        foreach ($assignments as $a) {
            $opId = (int)$a['operatorID'];
            $profId = (int)$a['profID'];
            if (empty($this->userMap[$opId]) || !isset($profiles[$profId])) {
                continue;
            }
            if (!isset($roleOfProfile[$profId])) {
                $name = mb_substr('Water: ' . trim((string)$profiles[$profId]['name']), 0, 60);
                $roleId = (int)(DB::value('SELECT id FROM roles WHERE name = :n', ['n' => $name]) ?: 0);
                if (!$roleId) {
                    $roleId = DB::insert('roles', ['name' => $name, 'description' => mb_substr('Migrated from the water-billing profile "' . $profiles[$profId]['name'] . '" (legacy profID ' . $profId . ')', 0, 255),
                        'is_admin' => 0, 'is_system' => 0, 'all_sites' => 1]);
                    $res['notes']['roles_created'][] = $roleId;
                    $res['written']++;
                }
                $roleOfProfile[$profId] = $roleId;
                $rolePages[$profId] = [];
            }
            foreach (array_keys($perms[(int)$a['pkey']] ?? []) as $pageId) {
                $rolePages[$profId][$pageId] = true;
            }
        }
        foreach ($roleOfProfile as $profId => $roleId) {
            $isAdminish = stripos((string)$profiles[$profId]['name'], 'Administrator') !== false;
            $have = DB::column('SELECT page_id FROM role_page_access WHERE role_id = :r', ['r' => $roleId]);
            $have = array_fill_keys(array_map('intval', $have), true);
            foreach (array_keys($rolePages[$profId]) as $pageId) {
                if (isset($have[$pageId])) {
                    continue;
                }
                DB::insert('role_page_access', ['role_id' => $roleId, 'page_id' => $pageId, 'allowed' => 1, 'can_create' => 1, 'can_edit' => 1, 'can_delete' => 1, 'can_print' => 1, 'can_approve' => $isAdminish ? 1 : 0]);
                $res['written']++;
            }
            if (!$rolePages[$profId]) {
                $this->note('page access', 'Role "' . DB::value('SELECT name FROM roles WHERE id = :id', ['id' => $roleId]) . '" has no page rights: none of its legacy assignments had page permissions. Give it pages on User Role Configuration.');
            }
        }

        // user roles, super administrators and the overrides that keep each operator's effective access what it was
        $userAssignments = [];
        foreach ($assignments as $a) {
            $opId = (int)$a['operatorID'];
            if (empty($this->userMap[$opId])) {
                continue;
            }
            $userAssignments[$opId][] = $a;
        }
        foreach ($userAssignments as $opId => $list) {
            $userId = (int)$this->userMap[$opId];
            $super = false;
            $legacyEff = [];
            $roleUnion = [];
            foreach ($list as $a) {
                $profId = (int)$a['profID'];
                if ((int)$a['is_super_admin'] === 1) {
                    $super = true;
                }
                if (!isset($roleOfProfile[$profId])) {
                    continue;
                }
                $roleId = $roleOfProfile[$profId];
                if (!DB::value('SELECT 1 FROM user_roles WHERE user_id = :u AND role_id = :r', ['u' => $userId, 'r' => $roleId])) {
                    DB::insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
                    $res['notes']['user_roles'][] = [$userId, $roleId];
                    $res['written']++;
                }
                foreach (array_keys($perms[(int)$a['pkey']] ?? []) as $pageId) {
                    $legacyEff[$pageId] = true;
                }
                foreach (array_keys($rolePages[$profId]) as $pageId) {
                    $roleUnion[$pageId] = true;
                }
            }
            if ($super) {
                if ($adminRole && !DB::value('SELECT 1 FROM user_roles WHERE user_id = :u AND role_id = :r', ['u' => $userId, 'r' => $adminRole])) {
                    DB::insert('user_roles', ['user_id' => $userId, 'role_id' => $adminRole]);
                    $res['notes']['user_roles'][] = [$userId, $adminRole];
                    $res['written']++;
                }
                continue;        // an administrator is never denied a page
            }
            foreach (array_keys($roleUnion) as $pageId) {
                if (isset($legacyEff[$pageId])) {
                    continue;
                }
                if (DB::value('SELECT 1 FROM user_page_overrides WHERE user_id = :u AND page_id = :p', ['u' => $userId, 'p' => $pageId])) {
                    continue;
                }
                DB::insert('user_page_overrides', ['user_id' => $userId, 'page_id' => $pageId, 'allowed' => 0, 'can_create' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_print' => 0, 'can_approve' => 0,
                    'reason' => 'Water migration: the operator\'s own profile assignment did not have this page', 'created_by' => $this->adminId]);
                $res['notes']['overrides'][] = [$userId, $pageId];
                $res['written']++;
            }
        }
        Access::reset();
        Audit::log('migrate', 'water', 'migration', null, 'users ' . $this->runId, ['page_key' => 'admin.users', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 4: bill codes and settings

    private function phaseBillCodes(): array
    {
        $res = $this->copyGroup('bill_codes', ['water_billing_settings']);
        // settings: account ids remapped
        $s = $this->copyTable('water_billing_settings', 'water_billing_settings', ['transform' => function (array $row) {
            if (str_ends_with((string)$row['setting_key'], '_account_id')) {
                $v = (int)$row['setting_value'];
                if ($v > 0) {
                    $mapped = $this->coaMap[$v] ?? null;
                    if (!$mapped) {
                        $this->note('settings', 'Setting ' . $row['setting_key'] . ': legacy account ' . $v . ' is not mapped; set to 0.');
                    }
                    $row['setting_value'] = (string)($mapped ?: 0);
                }
            }
            return $row;
        }]);
        wm_out(sprintf('   %-34s -> %-40s read %6d  written %6d  skipped %6d', 'water_billing_settings', 'water_billing_settings', $s['read'], $s['written'], $s['skipped']));
        $res['read'] += $s['read'];
        $res['written'] += $s['written'];
        $res['skipped'] += $s['skipped'];
        $res['notes']['tables']['water_billing_settings'] = ['read' => $s['read'], 'written' => $s['written'], 'skipped' => $s['skipped']];
        // an inventory bill code (meters, pipes, fittings) sells a material: it gets its item in the ONE item master, so
        // the cooperative inventory moves the stock and costs it. Never combined with an existing item by code or name.
        $res['notes']['items_created'] = [];
        foreach (DB::all("SELECT b.*, ia.category AS inc_cat, ca.account_type AS cogs_type, ca.category AS cogs_cat, aa.account_type AS asset_type
                            FROM bill_codes b
                            LEFT JOIN accounts ia ON ia.id = b.income_account
                            LEFT JOIN accounts ca ON ca.id = b.cogs_account
                            LEFT JOIN accounts aa ON aa.id = b.asset_account
                           WHERE LOWER(b.type) = 'inventory' AND b.item_id IS NULL ORDER BY b.code_id") as $bc) {
            $code = mb_substr(trim((string)$bc['code']), 0, 40);
            $same = DB::row('SELECT id, code, name FROM items WHERE code = :c OR name = :n LIMIT 1', ['c' => $code, 'n' => mb_substr(trim((string)$bc['description'] ?: $code), 0, 120)]);
            $itemCode = $code;
            if ($same || DB::value('SELECT id FROM items WHERE code = :c', ['c' => $itemCode])) {
                $itemCode = mb_substr('WB-' . $code, 0, 40);
                $n = 2;
                while (DB::value('SELECT id FROM items WHERE code = :c', ['c' => $itemCode])) {
                    $itemCode = mb_substr('WB-' . $code, 0, 36) . '-' . $n++;
                }
                $this->note('items', 'Bill code ' . $bc['code'] . ': the item master already has "' . $same['code'] . ' ' . $same['name'] . '"; a separate item ' . $itemCode . ' was made (nothing combined). If they are the same SKU and unit, point the bill code to that item on the Bill Codes page and make ' . $itemCode . ' inactive.');
            }
            $fallback = [];
            $income = $bc['income_account'] && $bc['inc_cat'] === 'Income' ? (int)$bc['income_account'] : null;
            if (!$income) {
                $income = Accts::get('sales_income');
                $fallback[] = 'income';
            }
            $cogs = $bc['cogs_account'] && ($bc['cogs_type'] === 'Cost of Goods Sold' || $bc['cogs_cat'] === 'Expense') ? (int)$bc['cogs_account'] : null;
            if (!$cogs) {
                $cogs = Accts::get('cogs');
                $fallback[] = 'cost of goods sold';
            }
            $asset = $bc['asset_account'] && $bc['asset_type'] === 'Other Current Asset' ? (int)$bc['asset_account'] : null;
            if (!$asset) {
                $asset = Accts::get('inventory_asset');
                $fallback[] = 'inventory asset';
            }
            $itemId = DB::insert('items', [
                'item_type' => 'inventory', 'code' => $itemCode, 'name' => mb_substr(trim((string)$bc['description']) ?: $code, 0, 120),
                'description' => mb_substr('Water material (bill code ' . $bc['code'] . ')', 0, 255), 'unit' => 'pc',
                'cost' => r2((float)$bc['cost_price']), 'avg_cost' => r2((float)$bc['cost_price']), 'sales_price' => r2((float)$bc['sales_price']),
                'income_account_id' => $income, 'cogs_account_id' => $cogs, 'asset_account_id' => $asset, 'is_active' => 1,
            ]);
            DB::run('UPDATE bill_codes SET item_id = :i WHERE code_id = :c AND item_id IS NULL', ['i' => $itemId, 'c' => $bc['code_id']]);
            $res['notes']['items_created'][] = $itemId;
            $res['written']++;
            $this->note('items', 'Bill code ' . $bc['code'] . ' -> item ' . $itemCode . ' (#' . $itemId . '), opening quantity 0 (the former program held no stock for it)'
                . ($fallback ? '; its legacy ' . implode(', ', $fallback) . ' account was not of the right kind, so the cooperative default was used. Review on the Item List.' : '.'));
        }
        // what the bill codes point to now
        foreach (DB::all('SELECT b.*, ar.account_type AS ar_type, ar.name AS ar_name FROM bill_codes b LEFT JOIN accounts ar ON ar.id = b.ar_account ORDER BY b.code_id') as $bc) {
            if (strtolower((string)$bc['type']) === 'inventory' && !$bc['item_id']) {
                $this->note('bill codes', 'Bill code ' . $bc['code'] . ' is an inventory bill code: link it to an item of the item master on the Bill Codes page (item_id is empty).');
            }
            if (!$bc['ar_account']) {
                $this->note('bill codes', 'Bill code ' . $bc['code'] . ' has no A/R account; the water default A/R applies.');
            } elseif ($bc['ar_type'] !== 'Accounts Receivable') {
                $this->note('bill codes', 'Bill code ' . $bc['code'] . ': its A/R account "' . $bc['ar_name'] . '" is not an Accounts Receivable account (' . $bc['ar_type'] . '). Review on the Bill Codes page.');
            }
            if (!$bc['income_account']) {
                $this->note('bill codes', 'Bill code ' . $bc['code'] . ' has no income account.');
            }
        }
        Audit::log('migrate', 'water', 'migration', null, 'bill_codes ' . $this->runId, ['page_key' => 'water.bill_codes', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 5: water-service accounts and their parties

    private function phaseAccounts(): array
    {
        $res = ['read' => 0, 'written' => 0, 'skipped' => 0, 'notes' => []];
        $s = $this->copyTable('members', 'water_accounts', WM_TABLES['accounts']['members'][1] + ['extra' => ['legacy_member_id'], 'transform' => function (array $row) {
            $row['legacy_member_id'] = (int)$row['pkey'];
            return $row;
        }]);
        wm_out(sprintf('   %-34s -> %-40s read %6d  written %6d  skipped %6d', 'members', 'water_accounts', $s['read'], $s['written'], $s['skipped']));
        $res['read'] += $s['read'];
        $res['written'] += $s['written'];
        $res['skipped'] += $s['skipped'];
        $res['notes']['tables']['water_accounts'] = ['read' => $s['read'], 'written' => $s['written'], 'skipped' => $s['skipped']];
        $rest = $this->copyGroup('accounts', ['members']);
        $res['read'] += $rest['read'];
        $res['written'] += $rest['written'];
        $res['skipped'] += $rest['skipped'];
        $res['notes']['tables'] += $rest['notes']['tables'];

        // one party per water-service account (fast path: prepared statements, one audit row for the phase)
        $todo = DB::all('SELECT * FROM water_accounts WHERE legacy_member_id IS NOT NULL AND party_id IS NULL ORDER BY pkey');
        $res['notes']['parties_created'] = 0;
        $res['notes']['parties_linked'] = 0;
        if ($todo) {
            $seq = DB::row("SELECT prefix, next_no, pad FROM sequences WHERE seq_key = 'party' FOR UPDATE");
            if (!$seq) {
                DB::insert('sequences', ['seq_key' => 'party', 'prefix' => 'P-', 'next_no' => 1, 'pad' => 6]);
                $seq = ['prefix' => 'P-', 'next_no' => 1, 'pad' => 6];
            }
            $next = (int)$seq['next_no'];
            DB::run("UPDATE sequences SET next_no = next_no + :n WHERE seq_key = 'party'", ['n' => count($todo)]);
            $insParty = DB::pdo()->prepare('INSERT INTO parties (party_no, party_type, legal_name, display_name, last_name, first_name, middle_name, tin, birth_date, address, mobile, status, src_table, src_id, notes, created_by)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insXw = DB::pdo()->prepare("INSERT INTO party_crosswalk (source, source_id, source_ref, party_id, how) VALUES ('water_acct', ?, ?, ?, ?)");
            $insLog = DB::pdo()->prepare("INSERT INTO party_role_log (party_id, role, ref_id, action, reason, created_by) VALUES (?, 'water', ?, 'granted', ?, ?)");
            $updAcct = DB::pdo()->prepare('UPDATE water_accounts SET party_id = ? WHERE pkey = ?');
            $findXw = DB::pdo()->prepare("SELECT party_id FROM party_crosswalk WHERE source = 'water_acct' AND source_id = ?");
            $findSrc = DB::pdo()->prepare("SELECT id FROM parties WHERE src_table = 'water_acct' AND src_id = ?");
            $blankNames = 0;
            foreach ($todo as $a) {
                $id = (int)$a['pkey'];
                // a party made for this account before (a crosswalk row or a party born from it) is linked, not duplicated
                $findXw->execute([$id]);
                $partyId = (int)($findXw->fetchColumn() ?: 0);
                if (!$partyId) {
                    $findSrc->execute([$id]);
                    $partyId = (int)($findSrc->fetchColumn() ?: 0);
                }
                if ($partyId) {
                    $updAcct->execute([$partyId, $id]);
                    $res['notes']['parties_linked']++;
                    continue;
                }
                $f = WaterAccounts::partyFields($a);
                $legal = mb_substr(trim((string)$f['legal_name']), 0, 160);
                if ($legal === '') {
                    $legal = 'Water account ' . ($a['account_number'] ?: $id);
                    $blankNames++;
                }
                $display = mb_substr(trim((string)$f['display_name'], " ,"), 0, 160) ?: $legal;
                $partyNo = $seq['prefix'] . str_pad((string)$next, (int)$seq['pad'], '0', STR_PAD_LEFT);
                $next++;
                $insParty->execute([$partyNo, 'person', $legal, $display, mb_substr((string)$f['last_name'], 0, 80) ?: null, mb_substr((string)$f['first_name'], 0, 80) ?: null,
                    mb_substr((string)$f['middle_name'], 0, 80) ?: null, mb_substr(trim((string)$f['tin']), 0, 30) ?: null, $f['birth_date'] ?: null,
                    $f['address'] !== '' ? $f['address'] : null, ($f['mobile'] ?? '') !== '' ? $f['mobile'] : null, 'active', 'water_acct', $id, mb_substr((string)$f['notes'], 0, 255), $this->adminId]);
                $partyId = (int)DB::pdo()->lastInsertId();
                $insXw->execute([$id, mb_substr((string)($a['account_number'] ?: $id) . ' ' . $display, 0, 160), $partyId, 'created']);
                $insLog->execute([$partyId, $id, 'Water-service account (migrated from ' . $this->srcDb . ')', $this->adminId]);
                $updAcct->execute([$partyId, $id]);
                $res['notes']['parties_created']++;
                $res['written']++;
            }
            if ($blankNames > 0) {
                $this->note('water accounts', "$blankNames water-service account(s) have no name; their party is named after the account number.");
            }
        }
        wm_out(sprintf('   parties: created %d, linked to existing %d', $res['notes']['parties_created'], $res['notes']['parties_linked']));
        // accounts the water program cannot bill yet (it refuses to compute a bill without a rate code and a pipe size)
        foreach (DB::all("SELECT pkey, account_number, lastname, firstname, rc_id, pipe_size_id FROM water_accounts
                           WHERE status = 'A' AND legacy_member_id IS NOT NULL AND (rc_id IS NULL OR pipe_size_id IS NULL) ORDER BY pkey") as $a) {
            $this->note('water accounts', 'Account ' . ($a['account_number'] ?: $a['pkey']) . ' ' . trim($a['lastname'] . ', ' . $a['firstname'], ', ')
                . ': no ' . ($a['rc_id'] === null ? 'rate code' : 'pipe size') . ' in the former database; it cannot be billed until one is assigned on Water Service Accounts (the former program refused it too).');
        }

        // the legacy membership register (membershipinfo) is the membership phase's: its member ids are not water-service
        // account keys, so nothing is linked here by id or by name
        Audit::log('migrate', 'water', 'migration', null, 'accounts ' . $this->runId, ['page_key' => 'water.member_list', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 6: suppliers -> vendors

    private function phaseSuppliers(): array
    {
        $res = ['read' => 0, 'written' => 0, 'skipped' => 0, 'notes' => ['vendors_created' => [], 'parties_created' => [], 'same_name' => []]];
        foreach ($this->srcAll('SELECT * FROM suppliers ORDER BY supplier_id') as $s) {
            $res['read']++;
            $sid = (int)$s['supplier_id'];
            if (DB::value("SELECT party_id FROM party_crosswalk WHERE source = 'water_supp' AND source_id = :i", ['i' => $sid])) {
                $res['skipped']++;
                continue;
            }
            $name = mb_substr(trim((string)$s['supplier_name']), 0, 120);
            if ($name === '') {
                $name = 'Water supplier ' . $sid;
            }
            $active = strtolower((string)$s['status']) === 'active' ? 1 : 0;
            // never merged by name: a supplier whose name the target already uses becomes its own supplier record (the
            // name gets the legacy number) and the pair goes to Identity Review, where a person decides
            $existing = DB::row('SELECT id, party_id, name FROM vendors WHERE name = :n ORDER BY is_active DESC, id LIMIT 1', ['n' => $name]);
            $vendorName = $name;
            if ($existing) {
                $vendorName = mb_substr($name, 0, 105) . ' (water supplier ' . $sid . ')';
                $res['notes']['same_name'][] = (int)$existing['id'];
                $this->note('suppliers', "Supplier $sid '$name' has the same name as the existing supplier #" . $existing['id'] . ": created as '$vendorName' (nothing merged by name); the pair is queued on Parties > Identity Review.");
            }
            $partyId = Party::create([
                'party_type' => 'organization', 'legal_name' => $name, 'display_name' => $name,
                'email' => mb_substr(trim((string)$s['email']), 0, 120) ?: null, 'phone' => mb_substr(trim((string)$s['contact_number']), 0, 40) ?: null,
                'address' => mb_substr(trim((string)$s['address']), 0, 255) ?: null, 'notes' => 'Water supplier ' . $sid . ' (migrated from ' . $this->srcDb . ')',
            ], 'water_supp', $sid, 'water.settings');
            $vendorId = DB::insert('vendors', ['party_id' => $partyId, 'name' => $vendorName, 'company_name' => $name, 'contact_person' => mb_substr(trim((string)$s['contact_person']), 0, 120) ?: null,
                'phone' => mb_substr(trim((string)$s['contact_number']), 0, 40) ?: null, 'email' => mb_substr(trim((string)$s['email']), 0, 120) ?: null,
                'address' => mb_substr(trim((string)$s['address']), 0, 255) ?: null, 'is_active' => $active]);
            Party::crosswalk('water_supp', $sid, mb_substr($name, 0, 160), $partyId, 'created');
            Party::crosswalk('vendor', $vendorId, mb_substr($name, 0, 160), $partyId, 'created');
            Party::log($partyId, 'supplier', $vendorId, 'granted', 'Water supplier (migrated from ' . $this->srcDb . ')');
            $res['notes']['vendors_created'][] = $vendorId;
            $res['notes']['parties_created'][] = $partyId;
            $res['written']++;
            if ($existing) {
                // the possible duplicate is put in front of a person (Identity Review), never decided here
                $other = (int)($existing['party_id'] ?: (Party::ensureFor('vendor', (int)$existing['id']) ?: 0));
                if ($other && $other !== $partyId) {
                    [$a, $b] = [min($other, $partyId), max($other, $partyId)];
                    if (!DB::value('SELECT id FROM party_match_reviews WHERE party_a = :a AND party_b = :b', ['a' => $a, 'b' => $b])) {
                        DB::insert('party_match_reviews', ['party_a' => $a, 'party_b' => $b, 'basis' => 'Same supplier name (water migration)', 'score' => 40, 'status' => 'open']);
                    }
                }
            } else {
                Party::proposeFor($partyId);
            }
        }
        Audit::log('migrate', 'water', 'migration', null, 'suppliers ' . $this->runId, ['page_key' => 'vend.vendors', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 7: the legacy cooperative membership register and savings

    /** "Alegado , Sarah Jane .." / "Agtag, Wilma S." -> [last, first, middle initial] */
    private static function splitLegacyName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (!str_contains($name, ',')) {
            return [$name, '', ''];
        }
        [$last, $rest] = array_map('trim', explode(',', $name, 2));
        $rest = trim(preg_replace('/(\s*\.)+\s*$/', '', $rest));          // trailing ".." / "."
        $words = $rest === '' ? [] : explode(' ', $rest);
        $middle = '';
        if (count($words) > 1 && preg_match('/^([A-Za-zÑñ])\.?$/u', (string)end($words), $mm)) {
            $middle = strtoupper($mm[1]);
            array_pop($words);
        }
        return [$last, trim(implode(' ', $words)), $middle];
    }

    private function phaseMembership(): array
    {
        $res = $this->copyGroup('membership');      // the legacy savings register: read-only history (legacy_sav*)
        $asOf = $this->cutover;
        $runDir = APP_ROOT . '/storage/water_migration/' . $this->runId . ($this->dryRun ? '-dryrun' : '');
        $sides = [];
        foreach (DB::all('SELECT pkey, transcode, journalside FROM legacy_savingstranscode') as $c) {
            $sides[(int)$c['pkey']] = [(string)$c['transcode'], strtoupper((string)$c['journalside'])];
        }
        foreach (DB::all('SELECT DISTINCT savTransCode FROM legacy_savaccnttransaction') as $c) {
            if (!isset($sides[(int)$c['savTransCode']]) || !in_array($sides[(int)$c['savTransCode']][1], ['DEBIT', 'CREDIT'], true)) {
                throw new RuntimeException('Legacy savings transaction code ' . $c['savTransCode'] . ' has no DEBIT/CREDIT side: the balances cannot be computed safely.');
            }
        }
        // the balance of every legacy savings account at cutover: credits (deposits, transitory, PR, dividends) less
        // debits (withdrawals); error corrections carry their own sign; deleted lines and lines after cutover excluded
        $accounts = DB::all("SELECT a.accountno, a.memberid, a.savingsid, a.status, a.applicationdate, a.deleted,
                                    COALESCE(SUM(CASE WHEN c.journalside = 'CREDIT' THEN t.amount WHEN c.journalside = 'DEBIT' THEN -t.amount ELSE 0 END), 0) AS bal,
                                    COUNT(t.savtransHeadID) AS lines_n, MAX(t.savtransTransDate) AS last_date
                               FROM legacy_savingsaccounts a
                               LEFT JOIN legacy_savaccnttransaction t ON t.savaccountno = a.accountno AND t.isdeleted = 0 AND DATE(t.savtransTransDate) <= :d
                               LEFT JOIN legacy_savingstranscode c ON c.pkey = t.savTransCode
                              GROUP BY a.accountno, a.memberid, a.savingsid, a.status, a.applicationdate, a.deleted ORDER BY a.memberid, a.accountno", ['d' => $asOf]);
        $after = (int)DB::value('SELECT COUNT(*) FROM legacy_savaccnttransaction WHERE isdeleted = 0 AND DATE(savtransTransDate) > :d', ['d' => $asOf]);
        if ($after > 0) {
            $this->note('legacy savings', "$after legacy savings transaction(s) are dated after the cutover $asOf and are not in the balances. Record them in the cooperative savings module after go-live.");
        }
        $info = [];
        foreach (DB::all('SELECT * FROM membershipinfo ORDER BY memberID') as $m) {
            $info[(int)$m['memberID']] = $m;
        }
        $types = DB::pairs('SELECT code, id FROM member_types WHERE is_active = 1');
        $memberRows = [];
        $savRows = [];
        $passbookUsed = [];
        $totals = ['members' => 0, 'active' => 0, 'other_status' => 0, 'accounts' => 0, 'balance' => 0.0, 'zero_closed' => 0, 'negative' => 0];
        foreach ($info as $id => $m) {
            [$last, $first, $middle] = self::splitLegacyName((string)$m['memberName']);
            if ($first === '') {
                $first = '-';
                $this->note('legacy membership', "Member $id \"" . trim((string)$m['memberName']) . '": no first name in the former register; imported with "-" as first name. Correct it on Member Information.');
            }
            $statusCode = (int)$m['membershipStatus'];
            $status = $statusCode === 1 ? 'active' : 'applicant';
            if ($statusCode !== 1) {
                $totals['other_status']++;
                $this->note('legacy membership', "Member $id " . trim((string)$m['memberName']) . ": legacy membership status code $statusCode has no definition in the former database; brought in as applicant. Set the right status (active, resigned, terminated, deceased) on Member Information.");
            } else {
                $totals['active']++;
            }
            $type = '';
            if ((int)$m['membershipType'] === 1) {
                $type = isset($types['ASI']) ? 'ASI' : '';
                $this->note('legacy membership', "Member $id " . trim((string)$m['memberName']) . ': legacy membership type 1 taken as an associate member' . ($type ? ' (ASI)' : ' (no ASI type set up: the first active type)') . '. Confirm on Member Information.');
            }
            $memberRows[] = ['member_no' => (string)$id, 'last_name' => mb_substr($last, 0, 80), 'first_name' => mb_substr($first, 0, 80), 'middle_name' => $middle, 'suffix' => '',
                'gender' => '', 'birth_date' => '', 'civil_status' => '', 'member_type' => $type, 'branch' => '', 'membership_date' => wm_is_zero_date($m['membershipDate']) ? '' : substr((string)$m['membershipDate'], 0, 10),
                'mobile' => '', 'email' => '', 'tin' => '', 'street' => '', 'barangay' => '', 'city' => '', 'province' => '', 'subscribed_shares' => '', 'par_value' => '', 'status' => $status,
                '_legacy' => $id, '_name' => trim((string)$m['memberName'])];
            $totals['members']++;
        }
        foreach ($accounts as $a) {
            $bal = r2((float)$a['bal']);
            $mid = (int)$a['memberid'];
            if (!isset($info[$mid])) {
                $this->note('legacy savings', 'Savings account ' . $a['accountno'] . ' belongs to member ' . $mid . ', who is not in the membership register; not brought in (' . wm_money($bal) . ').');
                continue;
            }
            if ($bal < 0) {
                $totals['negative']++;
                $this->note('legacy savings', 'Savings account ' . $a['accountno'] . ' of member ' . $mid . ' has a negative balance (' . wm_money($bal) . ') at cutover; not brought in. Investigate in the former records.');
                continue;
            }
            if (in_array(strtoupper((string)$a['status']), ['CLOSED', 'WITHDRAWN'], true) || (int)$a['deleted'] === 1) {
                if (abs($bal) < 0.005) {
                    $totals['zero_closed']++;
                    continue;
                }
                $this->note('legacy savings', 'Savings account ' . $a['accountno'] . ' is ' . $a['status'] . ' in the former program but holds ' . wm_money($bal) . ' at cutover; brought in as an open account so the balance is not lost. Review.');
            }
            $pb = trim((string)($info[$mid]['passbook'] ?? ''));
            if ($pb !== '' && isset($passbookUsed[$pb])) {
                $pb = '';      // a passbook number belongs to one account
            }
            if ($pb !== '') {
                $passbookUsed[$pb] = true;
            }
            $savRows[] = ['member_no' => (string)$mid, 'product' => (string)($this->opt['savings_product'] ?? ''), 'balance' => number_format($bal, 2, '.', ''), 'account_no' => (string)$a['accountno'],
                'opened_on' => wm_is_zero_date($a['applicationdate']) ? '' : substr((string)$a['applicationdate'], 0, 10), 'passbook_no' => $pb, '_legacy' => (string)$a['accountno']];
            $totals['accounts']++;
            $totals['balance'] = r2($totals['balance'] + $bal);
        }
        $strip = fn(array $rows) => array_map(fn($r) => array_filter($r, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), $rows);
        $mFile = wm_write_csv($runDir . '-legacy_members.csv', array_keys(array_filter($memberRows[0] ?? ['member_no' => 1], fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY)), $strip($memberRows));
        $sFile = wm_write_csv($runDir . '-legacy_savings.csv', ['member_no', 'product', 'balance', 'account_no', 'opened_on', 'passbook_no'], $strip($savRows));
        $res['notes']['register'] = $totals;
        $res['notes']['files'] = [basename($mFile), basename($sFile)];
        $res['amount_in'] = $totals['balance'];
        wm_out(sprintf('   legacy register: %d member(s) (%d active, %d other status), %d savings account(s) with %s at %s (%d closed at zero left out)',
            $totals['members'], $totals['active'], $totals['other_status'], $totals['accounts'], wm_money($totals['balance']), $asOf, $totals['zero_closed']));
        wm_out('   files for review: ' . $mFile . ', ' . $sFile);

        if (empty($this->opt['import_membership'])) {
            $this->note('legacy membership', 'NOT IMPORTED (the default): the former database carries a cooperative membership register of ' . $totals['members'] . ' member(s) and '
                . $totals['accounts'] . ' savings account(s) holding ' . wm_money($totals['balance']) . ' at ' . $asOf . '. They are kept read-only (membershipinfo, legacy_sav*) and written to the two CSV files above. '
                . 'Importing them creates cooperative members and posts the savings as opening balances: do it only when the cooperative database does not already hold these members and savings. '
                . 'Then run: php tools/migrate_water.php --source=' . $this->srcDb . ' --phase=membership --import-membership --savings-product=<code> --cutover=' . $asOf);
            $res['amount_out'] = 0.0;
            return $res;
        }

        // ---- the import (all or nothing, through the cooperative's own opening-balance import)
        $code = strtoupper(trim((string)($this->opt['savings_product'] ?? '')));
        $product = $code !== '' ? DB::row('SELECT * FROM sav_products WHERE UPPER(code) = :c', ['c' => $code]) : null;
        if (!$product) {
            throw new RuntimeException('--import-membership needs --savings-product=<code> naming a savings product of the cooperative (Savings Products). ' . ($code !== '' ? "Product $code was not found." : ''));
        }
        if ($product['product_type'] === 'time_deposit') {
            throw new RuntimeException("Product $code is a time deposit product; choose a savings product.");
        }
        $res['notes']['members_created'] = [];
        $res['notes']['members_matched'] = [];
        $toImport = [];
        $linked = 0;
        foreach ($memberRows as $r) {
            $xw = DB::value("SELECT party_id FROM party_crosswalk WHERE source = 'water_coopmb' AND source_id = :i", ['i' => $r['_legacy']]);
            if ($xw) {
                $res['skipped']++;
                $linked++;
                continue;
            }
            $existing = DB::row('SELECT id, party_id, last_name, first_name, full_name FROM members WHERE member_no = :n', ['n' => $r['member_no']]);
            if ($existing) {
                // the same member number: one record when the names agree too, else a conflict a person must settle
                if (Party::normName((string)$existing['last_name'] . ' ' . (string)$existing['first_name']) === Party::normName($r['last_name'] . ' ' . $r['first_name']) && $existing['party_id']) {
                    Party::crosswalk('water_coopmb', (int)$r['_legacy'], mb_substr($r['_legacy'] . ' ' . $r['_name'], 0, 160), (int)$existing['party_id'], 'matched');
                    $res['notes']['members_matched'][] = (int)$existing['id'];
                    $res['written']++;
                } else {
                    $this->note('legacy membership', 'Member ' . $r['_legacy'] . ' ' . $r['_name'] . ': member no. ' . $r['member_no'] . ' already belongs to ' . $existing['full_name'] . ' in the cooperative; not imported. Settle it on Member Information.');
                }
                continue;
            }
            $toImport[] = $r;
        }
        if ($toImport) {
            $c = CoopImport::check('members', $toImport, $asOf);
            if (!$c['ok']) {
                foreach ($c['rows'] as $r) {
                    if ($r['errors']) {
                        $this->note('legacy membership', 'Member ' . $r['member_no'] . ': ' . implode('; ', $r['errors']));
                    }
                }
                throw new RuntimeException('The legacy members did not pass the cooperative import check (see the exceptions). Nothing was imported.');
            }
            $res['notes']['members_import_id'] = CoopImport::run('members', $toImport, $asOf, null, basename($mFile), 'coop.import');
            foreach ($toImport as $r) {
                $m = DB::row('SELECT id, party_id FROM members WHERE member_no = :n', ['n' => $r['member_no']]);
                if ($m && $m['party_id']) {
                    Party::crosswalk('water_coopmb', (int)$r['_legacy'], mb_substr($r['_legacy'] . ' ' . $r['_name'], 0, 160), (int)$m['party_id'], 'created');
                    // the member may also hold a water-service account (a separate party until a person links them)
                    Party::proposeFor((int)$m['party_id']);
                }
                $res['notes']['members_created'][] = $m ? (int)$m['id'] : null;
                $res['written']++;
            }
        }
        wm_out(sprintf('   members: imported %d, already imported by an earlier run %d, matched by member no. and name %d', count($res['notes']['members_created']), $linked, count($res['notes']['members_matched'])));

        if ($existing = WaterPosting::byEvent(self::SAVOB_EVENT)) {
            wm_out('   savings opening balances already imported (transaction #' . $existing['txn_id'] . ')');
            $res['amount_out'] = (float)$existing['amount'];
            return $res;
        }
        $savToImport = array_values(array_filter($savRows, fn($r) => !DB::value('SELECT id FROM sav_accounts WHERE account_no = :a', ['a' => $r['account_no']])
            && DB::value('SELECT id FROM members WHERE member_no = :n', ['n' => $r['member_no']])));
        foreach ($savToImport as &$r) {
            $r['product'] = $product['code'];
        }
        unset($r);
        if (!$savToImport) {
            wm_out('   savings: nothing to import');
            return $res;
        }
        $clearing = DB::value('SELECT id FROM accounts WHERE code = :c', ['c' => self::SAV_CLEARING_CODE]);
        if (!$clearing) {
            $clearing = DB::insert('accounts', ['code' => self::SAV_CLEARING_CODE, 'name' => self::SAV_CLEARING_NAME, 'account_type' => 'Equity', 'category' => 'Equity', 'normal_balance' => 'Credit',
                'description' => 'Offset of the legacy savings balances brought in at cutover (tools/migrate_water.php --phase=membership). Clear it against the signed-off legacy trial balance.', 'with_sl' => 0, 'is_active' => 1, 'is_system' => 0]);
            $res['notes']['savings_clearing_created'] = (int)$clearing;
        }
        $c = CoopImport::check('savings', $savToImport, $asOf);
        if (!$c['ok']) {
            foreach ($c['rows'] as $r) {
                if ($r['errors']) {
                    $this->note('legacy savings', 'Savings account ' . ($r['account_no'] ?? '') . ' of member ' . $r['member_no'] . ': ' . implode('; ', $r['errors']));
                }
            }
            throw new RuntimeException('The legacy savings balances did not pass the cooperative import check (see the exceptions). Nothing was imported.');
        }
        $importId = CoopImport::run('savings', $savToImport, $asOf, (int)$clearing, basename($sFile), 'coop.import');
        $txnId = (int)DB::value('SELECT txn_id FROM coop_imports WHERE id = :i', ['i' => $importId]);
        $amount = r2(array_sum(array_map(fn($r) => (float)$r['balance'], $savToImport)));
        DB::insert('water_postings', ['event_key' => self::SAVOB_EVENT, 'source_type' => 'legacy_savings', 'source_id' => (string)$importId, 'doc_no' => mb_substr('SAVOB-' . str_replace('-', '', $asOf), 0, 60),
            'txn_id' => $txnId ?: null, 'txn_type' => 'coop_jv', 'amount' => $amount, 'status' => 'posted', 'reason' => 'Legacy savings balances at cutover (' . count($savToImport) . ' accounts)', 'created_by' => $this->adminId]);
        $res['notes']['savings_import_id'] = $importId;
        $res['notes']['savings_txn_id'] = $txnId;
        $res['notes']['savings_accounts'] = count($savToImport);
        $res['amount_out'] = $amount;
        $res['written'] += count($savToImport);
        wm_out(sprintf('   savings: %d account(s) imported with %s (import #%d, voucher #%d), offset to %s %s', count($savToImport), wm_money($amount), $importId, $txnId, self::SAV_CLEARING_CODE, self::SAV_CLEARING_NAME));
        return $res;
    }

    // ------------------------------------------------------------------ phase 8: history and the opening balances

    /** The A/R account of a legacy bill: the debit line of its legacy journal voucher, else the bill code, else the default */
    private function billArAccount(?string $jvId, ?int $billCodeId): ?int
    {
        if ($jvId !== null && trim($jvId) !== '') {
            $acct = DB::value("SELECT AccountID FROM legacy_journalentries WHERE JournalHeadID = :j AND Debit > 0 AND Status = 'A' ORDER BY Debit DESC, JournalID LIMIT 1", ['j' => trim($jvId)]);
            if ($acct !== null && !empty($this->coaMap[(int)$acct])) {
                return (int)$this->coaMap[(int)$acct];
            }
        }
        if ($billCodeId) {
            $ar = DB::row("SELECT b.code, b.ar_account, a.account_type FROM bill_codes b LEFT JOIN accounts a ON a.id = b.ar_account WHERE b.code_id = :id", ['id' => $billCodeId]);
            if ($ar && $ar['ar_account'] && $ar['account_type'] === 'Accounts Receivable') {
                return (int)$ar['ar_account'];
            }
            if ($ar && $ar['ar_account']) {
                $this->note('opening balance', 'Bill code ' . $ar['code'] . ': its A/R account is not a receivable; its legacy open bills are brought in on the default water A/R account instead.');
            }
        }
        return WaterPosting::defaultAccount('water_ar');
    }

    private function phaseHistory(): array
    {
        $res = $this->copyGroup('history');
        $res['notes']['open_bills'] = ['water' => [0, 0.0], 'one_time' => [0, 0.0], 'installment' => [0, 0.0], 'recurring' => [0, 0.0]];
        $clearing = WaterPosting::defaultAccount('water_legacy_clearing');
        if (!$clearing) {
            throw new RuntimeException('The water_legacy_clearing default account is not set (phase coa).');
        }
        $unitId = WaterPosting::unitId();
        $postingsFrom = (int)(DB::value('SELECT COALESCE(MAX(id), 0) FROM water_postings') ?: 0) + 1;
        $txnFrom = (int)(DB::value('SELECT COALESCE(MAX(id), 0) FROM transactions') ?: 0) + 1;
        $waterCode = (int)(DB::value("SELECT setting_value FROM water_billing_settings WHERE setting_key = 'water_billing_bill_code_id'") ?: 0);
        $openByAr = [];          // A/R account id => sum of open invoices posted (this run and earlier runs)
        $posted = 0;
        $postedAmount = 0.0;
        $post = function (string $source, int $id, array $opt, string $kind) use (&$res, &$posted, &$postedAmount, &$openByAr, $clearing) {
            $open = r2((float)$opt['open']);
            if ($open <= 0) {
                return;
            }
            $res['notes']['open_bills'][$kind][0]++;
            $res['notes']['open_bills'][$kind][1] = r2($res['notes']['open_bills'][$kind][1] + $open);
            $existing = WaterPosting::byEvent(WaterPosting::eventKey($source, $id));
            if ($existing) {
                // posted by an earlier run (or by the live system): counted, not posted again
                $res['skipped']++;
                $arUsed = (int)DB::value('SELECT account_id FROM transactions WHERE id = :t', ['t' => $existing['txn_id']]);
                $openByAr[$arUsed] = r2(($openByAr[$arUsed] ?? 0) + (float)$existing['amount']);
                return;
            }
            $ar = $opt['ar_account_id'];
            $r = WaterPosting::postBill($source, $id, [
                'water_account_id' => $opt['member_id'], 'txn_date' => $opt['txn_date'], 'due_date' => $opt['due_date'], 'doc_no' => $opt['doc_no'],
                'ar_account_id' => $ar,
                'lines' => [['account_id' => $clearing, 'amount' => $open, 'description' => 'Legacy open balance at cutover']], 'opening' => true,
                'memo' => mb_substr($opt['memo'], 0, 255), 'reason' => 'Legacy open balance brought in at cutover ' . $this->cutover,
                'branch_id' => (int)($opt['branch_id'] ?? 0), 'segment_id' => (int)($opt['segment_id'] ?? 0), 'division_id' => (int)($opt['division_id'] ?? 0),
            ]);
            if ($r) {
                $arUsed = (int)DB::value('SELECT account_id FROM transactions WHERE id = :t', ['t' => $r['txn_id']]);
                $openByAr[$arUsed] = r2(($openByAr[$arUsed] ?? 0) + $open);
                $posted++;
                $postedAmount = r2($postedAmount + $open);
                $res['written']++;
            }
        };
        // metered water bills
        foreach (DB::all("SELECT b.*, p.bp_code FROM bill_readings b LEFT JOIN bill_periods p ON p.period_id = b.period_id
                           WHERE b.is_billed = 1 AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.amount_due - COALESCE(b.amount_paid, 0) > 0.004 ORDER BY b.reading_id") as $b) {
            $date = $b['billing_date'] && !wm_is_zero_date($b['billing_date']) ? substr((string)$b['billing_date'], 0, 10) : substr((string)$b['created_at'], 0, 10);
            $post('water', (int)$b['reading_id'], [
                'member_id' => (int)$b['member_id'], 'open' => (float)$b['amount_due'] - (float)$b['amount_paid'], 'txn_date' => $date,
                'due_date' => $b['due_date'] && !wm_is_zero_date($b['due_date']) ? substr((string)$b['due_date'], 0, 10) : $date,
                'doc_no' => $b['global_ref_number'] ?: null, 'ar_account_id' => $this->billArAccount($b['billing_journal_voucher_id'], $waterCode ?: null),
                'memo' => 'Water bill #' . $b['reading_id'] . ' period ' . ($b['bp_code'] ?? $b['period_id']) . ': legacy open balance at cutover (due ' . wm_money($b['amount_due']) . ', paid ' . wm_money($b['amount_paid']) . ')',
                'branch_id' => $b['branch_id'], 'segment_id' => $b['segment_id'], 'division_id' => $b['division_id'],
            ], 'water');
        }
        // one-time bills
        foreach (DB::all("SELECT b.*, c.code FROM one_time_bills b LEFT JOIN bill_codes c ON c.code_id = b.bill_code_id
                           WHERE b.status = 'unpaid' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount - COALESCE(b.amount_paid, 0) > 0.004 ORDER BY b.bill_id") as $b) {
            $date = substr((string)$b['bill_date'], 0, 10);
            $post('one_time', (int)$b['bill_id'], [
                'member_id' => (int)$b['member_id'], 'open' => (float)$b['total_amount'] - (float)$b['amount_paid'], 'txn_date' => $date,
                'due_date' => $b['due_date'] && !wm_is_zero_date($b['due_date']) ? substr((string)$b['due_date'], 0, 10) : $date,
                'doc_no' => $b['global_ref_number'] ?: null, 'ar_account_id' => $this->billArAccount(null, (int)$b['bill_code_id']),
                'memo' => 'One-time bill #' . $b['bill_id'] . ' (' . $b['code'] . '): legacy open balance at cutover',
                'branch_id' => $b['branch_id'], 'segment_id' => $b['segment_id'], 'division_id' => $b['division_id'],
            ], 'one_time');
        }
        // installment contracts: one invoice per contract for the open schedules
        foreach (DB::all("SELECT b.*, c.code, (SELECT COALESCE(SUM(GREATEST(0, s.amount - COALESCE(s.amount_paid, 0))), 0) FROM installment_schedules s WHERE s.installment_id = b.installment_id AND s.status IN ('pending', 'partial')) AS open_amount,
                                 (SELECT MIN(s.due_date) FROM installment_schedules s WHERE s.installment_id = b.installment_id AND s.status IN ('pending', 'partial')) AS next_due
                            FROM installment_bills b LEFT JOIN bill_codes c ON c.code_id = b.bill_code_id
                           WHERE b.status = 'active' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' ORDER BY b.installment_id") as $b) {
            $date = substr((string)$b['start_date'], 0, 10);
            $post('installment', (int)$b['installment_id'], [
                'member_id' => (int)$b['member_id'], 'open' => (float)$b['open_amount'], 'txn_date' => $date,
                'due_date' => $b['next_due'] && !wm_is_zero_date($b['next_due']) ? substr((string)$b['next_due'], 0, 10) : $date,
                'doc_no' => $b['global_ref_number'] ?: null, 'ar_account_id' => $this->billArAccount(null, (int)$b['bill_code_id']),
                'memo' => 'Installment contract #' . $b['installment_id'] . ' (' . $b['code'] . '): legacy open schedules at cutover',
                'branch_id' => $b['branch_id'], 'segment_id' => $b['segment_id'], 'division_id' => $b['division_id'],
            ], 'installment');
        }
        // recurring bills
        foreach (DB::all("SELECT b.*, c.code FROM recurring_bills b LEFT JOIN bill_codes c ON c.code_id = b.bill_code_id
                           WHERE b.status IN ('unpaid', 'overdue') AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND b.total_amount - COALESCE(b.amount_paid, 0) > 0.004 ORDER BY b.bill_id") as $b) {
            $date = substr((string)$b['bill_date'], 0, 10);
            $post('recurring', (int)$b['bill_id'], [
                'member_id' => (int)$b['member_id'], 'open' => (float)$b['total_amount'] - (float)$b['amount_paid'], 'txn_date' => $date,
                'due_date' => $b['due_date'] && !wm_is_zero_date($b['due_date']) ? substr((string)$b['due_date'], 0, 10) : $date,
                'doc_no' => $b['global_ref_number'] ?: null, 'ar_account_id' => $this->billArAccount($b['journal_voucher_id'], (int)$b['bill_code_id']),
                'memo' => 'Recurring bill #' . $b['bill_id'] . ' (' . $b['code'] . '): legacy open balance at cutover',
                'branch_id' => $b['branch_id'], 'segment_id' => $b['segment_id'], 'division_id' => $b['division_id'],
            ], 'recurring');
        }
        // opening stock of the water materials: the quantity and value the former stock ledger held at cutover go into
        // the ONE inventory ledger as an inventory adjustment per item, offset against the legacy inventory asset
        // account the opening balance journal below carries (so the value is moved, never counted twice)
        $res['notes']['opening_stock'] = [];
        foreach (DB::all("SELECT m.code_id, b.code, b.item_id, b.asset_account, SUM(m.qty_in - m.qty_out) AS qty, SUM(m.movement_value) AS val
                            FROM legacy_inventory_stock_movements m JOIN bill_codes b ON b.code_id = m.code_id
                           WHERE m.movement_date <= :d GROUP BY m.code_id, b.code, b.item_id, b.asset_account ORDER BY m.code_id", ['d' => $this->cutover]) as $st) {
            $qty = round((float)$st['qty'], 4);
            $val = r2((float)$st['val']);
            if (abs($qty) < 0.00005 && abs($val) < 0.005) {
                continue;
            }
            $event = 'LEGACY-STOCK-' . (int)$st['code_id'];
            if (WaterPosting::byEvent($event)) {
                $res['skipped']++;
                continue;
            }
            if (!$st['item_id']) {
                $this->note('opening stock', 'Bill code ' . $st['code'] . ' holds ' . $qty . ' on hand (' . wm_money($val) . ') but has no item: not brought in. Link the item on Bill Codes and run the history phase again.');
                continue;
            }
            if ($qty <= 0) {
                $this->note('opening stock', 'Bill code ' . $st['code'] . ': the former stock ledger shows ' . $qty . ' on hand (' . wm_money($val) . '); nothing brought in. Count the stock and record it on Adjust Quantity/Value on Hand.');
                continue;
            }
            $assetAcct = $st['asset_account'] ? (int)$st['asset_account'] : 0;        // already the mapped account (bill_codes copied with accounts remapped)
            $offset = $assetAcct ?: (int)WaterPosting::defaultAccount('water_legacy_clearing');
            $rate = round($val / $qty, 4);
            $line = ['line_kind' => 'item', 'item_id' => (int)$st['item_id'], 'qty' => $qty, 'rate' => $rate, 'amount' => 0, 'tax_amount' => 0,
                'value_change' => r2($val - r2($qty * $rate)), 'site_id' => WaterPosting::siteId(), 'description' => 'Opening stock from the former water inventory'];
            $txnId = Txn::save('inventory_adjustment', ['txn_date' => $this->cutover, 'txn_no' => mb_substr('WSTK-' . $st['code_id'], 0, 30), 'account_id' => $offset,
                'site_id' => WaterPosting::siteId(), 'business_unit_id' => $unitId, 'memo' => 'Opening stock of bill code ' . $st['code'] . ' at cutover ' . $this->cutover],
                [$line], null, ['skip_policy' => true, 'reason' => 'Water migration run ' . $this->runId]);
            DB::insert('water_postings', ['event_key' => $event, 'source_type' => 'legacy_stock', 'source_id' => (string)$st['code_id'], 'doc_no' => 'WSTK-' . $st['code_id'],
                'txn_id' => $txnId, 'txn_type' => 'inventory_adjustment', 'amount' => $val, 'status' => 'posted', 'reason' => 'Opening stock at cutover', 'created_by' => $this->adminId]);
            $res['notes']['opening_stock'][] = ['code' => $st['code'], 'item_id' => (int)$st['item_id'], 'qty' => $qty, 'value' => $val, 'txn_id' => $txnId];
            $res['written']++;
            if (!$assetAcct) {
                $this->note('opening stock', 'Bill code ' . $st['code'] . ' has no legacy inventory asset account; its opening stock (' . wm_money($val) . ') was offset to the clearing account.');
            }
        }

        $openTotal = 0.0;
        foreach ($res['notes']['open_bills'] as $k => [$n, $amt]) {
            $openTotal = r2($openTotal + $amt);
            wm_out(sprintf('   open %-12s %6d bill(s)  %16s', $k, $n, wm_money($amt)));
        }
        wm_out(sprintf('   open invoices posted this run: %d for %s (A/R subledger total of all runs: %s)', $posted, wm_money($postedAmount), wm_money(array_sum($openByAr))));
        $res['amount_in'] = $openTotal;

        // the opening balance journal: the legacy ledger at cutover, A/R reduced by the open invoices, difference to clearing
        $legacy = DB::all("SELECT AccountID, SUM(COALESCE(Debit, 0)) AS dr, SUM(COALESCE(Credit, 0)) AS cr FROM legacy_journalentries
                            WHERE Status = 'A' AND TransactionDate IS NOT NULL AND DATE(TransactionDate) <= :d GROUP BY AccountID ORDER BY AccountID", ['d' => $this->cutover]);
        $lines = [];
        $legacyAr = [];
        $unmappedAmount = 0.0;
        $legacyDr = 0.0;
        $legacyCr = 0.0;
        $arTypes = [];
        foreach (DB::all("SELECT id FROM accounts WHERE account_type = 'Accounts Receivable'") as $a) {
            $arTypes[(int)$a['id']] = true;
        }
        $byAccount = [];
        foreach ($legacy as $l) {
            $signed = r2((float)$l['dr'] - (float)$l['cr']);
            $legacyDr = r2($legacyDr + (float)$l['dr']);
            $legacyCr = r2($legacyCr + (float)$l['cr']);
            $acct = $this->coaMap[(int)$l['AccountID']] ?? null;
            if (!$acct) {
                $unmappedAmount = r2($unmappedAmount + $signed);
                if (abs($signed) >= 0.005) {
                    $this->note('opening balance', 'Legacy account ' . $l['AccountID'] . ' has a balance of ' . wm_money($signed) . ' but no account in the chart (a root / class row); booked to the clearing account.');
                }
                continue;
            }
            $byAccount[$acct] = r2(($byAccount[$acct] ?? 0) + $signed);
        }
        foreach ($openByAr as $acct => $open) {
            $legacyAr[$acct] = $byAccount[$acct] ?? 0.0;
            $byAccount[$acct] = r2(($byAccount[$acct] ?? 0) - $open);
        }
        foreach ($byAccount as $acct => $amt) {
            if (abs($amt) >= 0.005) {
                $lines[] = ['line_kind' => 'journal', 'account_id' => (int)$acct, 'amount' => $amt,
                    'description' => isset($openByAr[$acct]) ? 'Legacy balance at cutover less open bills (' . wm_money($openByAr[$acct]) . ')' : 'Legacy ledger balance at cutover'];
            }
        }
        $sum = 0.0;
        foreach ($lines as $l) {
            $sum = r2($sum + $l['amount']);
        }
        $clearingAmount = r2(-$sum);
        if (abs($clearingAmount) >= 0.005) {
            $lines[] = ['line_kind' => 'journal', 'account_id' => $clearing, 'amount' => $clearingAmount, 'description' => 'Legacy water balances clearing (offset)'];
        }
        $res['notes']['opening_balance'] = ['legacy_debits' => $legacyDr, 'legacy_credits' => $legacyCr, 'legacy_ar' => $legacyAr, 'open_by_ar' => $openByAr,
            'open_total' => $openTotal, 'posted_this_run' => $postedAmount, 'unmapped' => $unmappedAmount, 'clearing_line' => $clearingAmount, 'lines' => count($lines)];
        $existingOb = WaterPosting::byEvent(self::OB_EVENT);
        if ($existingOb) {
            $res['skipped']++;
            $res['notes']['opening_balance']['txn_id'] = (int)$existingOb['txn_id'];
            wm_out('   opening balance journal already posted (transaction #' . $existingOb['txn_id'] . ')');
        } elseif (count($lines) >= 2) {
            $classId = null;
            $branch = DB::row('SELECT pkey FROM branches WHERE Deleted = 0 ORDER BY pkey LIMIT 1');
            if ($branch) {
                $classId = WaterPosting::classIdForBranch((int)$branch['pkey']);
            }
            $txnId = Txn::save('opening_balance', [
                'txn_date' => $this->cutover, 'txn_no' => mb_substr('WOB-' . str_replace('-', '', $this->cutover), 0, 30), 'ref_no' => mb_substr('Water migration ' . $this->runId, 0, 60),
                'memo' => 'Water billing: legacy ledger balances at cutover ' . $this->cutover . ' (' . $this->srcDb . ')', 'status' => 'open',
                'class_id' => $classId, 'business_unit_id' => $unitId,
            ], $lines, null, ['skip_policy' => true, 'reason' => 'Water migration run ' . $this->runId]);
            DB::insert('water_postings', ['event_key' => self::OB_EVENT, 'source_type' => 'legacy_opening', 'source_id' => $this->cutover, 'doc_no' => 'WOB-' . str_replace('-', '', $this->cutover),
                'txn_id' => $txnId, 'txn_type' => 'opening_balance', 'amount' => r2(array_sum(array_map(fn($l) => max(0, $l['amount']), $lines))), 'status' => 'posted',
                'reason' => 'Legacy ledger balances at cutover', 'created_by' => $this->adminId]);
            $res['notes']['opening_balance']['txn_id'] = $txnId;
            $res['written']++;
            wm_out('   opening balance journal posted: transaction #' . $txnId . ' with ' . count($lines) . ' line(s)');
        } else {
            wm_out('   opening balance journal: nothing to post (no legacy ledger balances at ' . $this->cutover . ')');
        }
        foreach ($legacyAr as $acct => $bal) {
            $name = DB::value('SELECT CONCAT(code, \' \', name) FROM accounts WHERE id = :id', ['id' => $acct]);
            $diff = r2($bal - ($openByAr[$acct] ?? 0));
            wm_out(sprintf('   A/R %-40s legacy balance %16s  open bills %16s  difference %16s', $name, wm_money($bal), wm_money($openByAr[$acct] ?? 0), wm_money($diff)));
            if (abs($diff) >= 0.005) {
                $this->note('opening balance', "A/R $name: the legacy ledger balance (" . wm_money($bal) . ') differs from the open bills (' . wm_money($openByAr[$acct] ?? 0) . ') by ' . wm_money($diff) . '; the difference stays in the account without a bill (control <> subledger). Reconcile in the former program or adjust with a journal entry.');
            }
        }
        $res['amount_out'] = r2(array_sum($openByAr));
        $res['notes']['postings_from'] = $postingsFrom;
        $res['notes']['postings_to'] = (int)(DB::value('SELECT COALESCE(MAX(id), 0) FROM water_postings') ?: 0);
        $res['notes']['txn_from'] = $txnFrom;
        $res['notes']['txn_to'] = (int)(DB::value('SELECT COALESCE(MAX(id), 0) FROM transactions') ?: 0);
        Settings::set('water_cutover_date', $this->cutover);
        Settings::set('water_source_db', $this->srcDb);
        Settings::flush();
        Audit::log('migrate', 'water', 'migration', null, 'history ' . $this->runId, ['page_key' => 'water.billing_history', 'snapshot' => $res['notes'], 'reason' => 'Water migration run ' . $this->runId, 'amount' => $openTotal]);
        return $res;
    }

    // ------------------------------------------------------------------ phase 8: verify

    /** One verification row; a WARN row (information for the accountant) does not fail the run */
    private function check(string $what, $expected, $actual, ?bool $ok = null, bool $warnOnly = false): void
    {
        $ok ??= is_float($expected) || is_float($actual) ? abs((float)$expected - (float)$actual) < 0.005 : (string)$expected === (string)$actual;
        $fmt = fn($v) => is_float($v) ? wm_money($v) : (string)$v;
        $this->verifyRows[] = [$what, $fmt($expected), $fmt($actual), $ok ? 'PASS' : ($warnOnly ? 'WARN' : 'FAIL')];
        if (!$ok && !$warnOnly) {
            $this->verifyOk = false;
        }
    }

    private function phaseVerify(): array
    {
        $this->verifyRows = [];
        $this->verifyOk = true;
        $res = ['read' => 0, 'written' => 0, 'skipped' => 0, 'notes' => []];
        // row counts: source rows present in the target (same primary key)
        foreach (wm_all_tables() as $srcTable => $dstTable) {
            if (!$this->columns($this->srcDb, $srcTable)) {
                continue;
            }
            $pk = $this->pk($dstTable);
            $on = implode(' AND ', array_map(fn($c) => "t.`$c` = s.`$c`", $pk));
            $srcN = $this->srcCount($srcTable);
            $both = (int)DB::value("SELECT COUNT(*) FROM `$dstTable` t JOIN `{$this->srcDb}`.`$srcTable` s ON $on");
            $this->check("rows $srcTable -> $dstTable", $srcN, $both);
            $res['read'] += $srcN;
            [, $conflicting] = $this->existingRows($srcTable, $dstTable, WM_TABLES[$this->phaseOfTable($srcTable)][$srcTable][1] ?? []);
            if ($conflicting > 0) {
                $this->check("rows $dstTable differing from the source", 0, $conflicting);
            }
        }
        // chart of accounts
        $legacyAccounts = $this->srcCount('chartofaccntstbl', 'Type IN (3, 4)');
        $mapped = (int)DB::value("SELECT COUNT(*) FROM water_coa_map m JOIN accounts a ON a.id = m.account_id WHERE m.how IN ('imported', 'matched')");
        $this->check('chart of accounts: detail + summary accounts mapped', $legacyAccounts, $mapped);
        $this->check('chart of accounts: legacy rows in water_coa_map', $this->srcCount('chartofaccntstbl'), (int)DB::value('SELECT COUNT(*) FROM water_coa_map'));
        // users
        $eligible = $this->srcCount('operator', 'isSystem = 0 AND deleted = 0');
        $users = (int)DB::value('SELECT COUNT(*) FROM water_user_map m JOIN users u ON u.id = m.user_id');
        $this->check('users: eligible operators with a user account', $eligible, $users);
        $roles = (int)DB::value("SELECT COUNT(*) FROM roles WHERE name LIKE 'Water: %'");
        $profilesUsed = (int)$this->srcValue('SELECT COUNT(DISTINCT op.profID) FROM operator_profile op JOIN operator o ON o.pkey = op.operatorID WHERE o.isSystem = 0 AND o.deleted = 0');
        $this->check('roles: one per legacy profile in use', $profilesUsed, $roles, $roles >= $profilesUsed);
        // parties
        $accts = (int)DB::value('SELECT COUNT(*) FROM water_accounts WHERE legacy_member_id IS NOT NULL');
        $withParty = (int)DB::value('SELECT COUNT(*) FROM water_accounts w JOIN parties p ON p.id = w.party_id WHERE w.legacy_member_id IS NOT NULL');
        $this->check('parties: water accounts with a party', $accts, $withParty);
        $this->check('parties: crosswalk rows (water_acct)', $accts, (int)DB::value("SELECT COUNT(*) FROM party_crosswalk WHERE source = 'water_acct'"));
        $this->check('suppliers: legacy suppliers mapped', $this->srcCount('suppliers'), (int)DB::value("SELECT COUNT(*) FROM party_crosswalk WHERE source = 'water_supp'"));
        // the legacy cooperative register: always kept read-only; when imported, the members, the savings ledgers and the GL agree
        $imported = (int)DB::value("SELECT COUNT(*) FROM party_crosswalk WHERE source = 'water_coopmb'");
        if ($imported > 0) {
            $this->check('legacy members: crosswalk rows vs membership register', $this->srcCount('membershipinfo'), $imported, $imported <= $this->srcCount('membershipinfo'), $imported < $this->srcCount('membershipinfo'));
            $sav = WaterPosting::byEvent(self::SAVOB_EVENT);
            if ($sav) {
                $voucher = r2((float)DB::value('SELECT COALESCE(SUM(credit - debit), 0) FROM journal_lines WHERE txn_id = :t AND account_id <> (SELECT id FROM accounts WHERE code = :c)', ['t' => $sav['txn_id'], 'c' => self::SAV_CLEARING_CODE]));
                $ledgers = r2((float)DB::value("SELECT COALESCE(SUM(t.credit - t.debit), 0) FROM sav_txns t WHERE t.txn_id = :x AND t.is_voided = 0", ["x" => $sav["txn_id"]]));
                $this->check('legacy savings: opening voucher credits to the savings liability', r2((float)$sav['amount']), $voucher);
                $this->check('legacy savings: member savings ledgers opened with the same total', r2((float)$sav['amount']), $ledgers);
                $legacyTotal = r2((float)DB::value("SELECT COALESCE(SUM(CASE WHEN c.journalside = 'CREDIT' THEN t.amount ELSE -t.amount END), 0)
                                                     FROM legacy_savaccnttransaction t JOIN legacy_savingstranscode c ON c.pkey = t.savTransCode
                                                     JOIN legacy_savingsaccounts a ON a.accountno = t.savaccountno
                                                    WHERE t.isdeleted = 0 AND DATE(t.savtransTransDate) <= :d
                                                      AND a.accountno IN (SELECT account_no FROM sav_accounts WHERE account_no IS NOT NULL)", ['d' => $this->cutover]));
                $this->check('legacy savings: imported balances = legacy register for the same accounts', $legacyTotal, r2((float)$sav['amount']));
            }
        }
        // bill codes and mapping
        // a bill code or default pointing at a missing / inactive account is a failure; an optional account the former
        // program never had (collection policy penalty / compromise) and an inventory bill code without an item are
        // warnings for the set-up after the migration
        $problems = WaterPosting::mappingProblems();
        // a bill code the former program had set up wrong (an A/R that is not a receivable, income that is cash) came over
        // as it was: a set-up exception to settle before go-live (its bills cannot post until then), not a migration error
        $hard = array_filter($problems, fn($p) => !str_contains($p['problem'], 'inventory bill code with no item') && !str_contains($p['problem'], 'cannot post')
            && !(str_starts_with($p['what'], 'Collection policy') && $p['problem'] === 'not mapped'));
        foreach ($problems as $p) {
            $this->note('mapping check', $p['what'] . ': ' . $p['problem']);
        }
        $this->check('bill codes and defaults: accounts resolve (WaterPosting::mappingProblems)', 0, count($hard));
        if (count($problems) > count($hard)) {
            $this->check('mapping warnings to settle on the Bill Codes / Collection Policy pages', 0, count($problems) - count($hard), false, true);
        }
        // open balances: water records vs ledger, per source
        $openWater = [
            'water' => (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, amount_due - COALESCE(amount_paid, 0))), 0) FROM bill_readings WHERE is_billed = 1 AND COALESCE(bill_lifecycle_status, 'active') = 'active'"),
            'one_time' => (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, total_amount - COALESCE(amount_paid, 0))), 0) FROM one_time_bills WHERE status = 'unpaid' AND COALESCE(bill_lifecycle_status, 'active') = 'active'"),
            'installment' => (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, s.amount - COALESCE(s.amount_paid, 0))), 0) FROM installment_schedules s JOIN installment_bills b ON b.installment_id = s.installment_id
                                                 WHERE b.status = 'active' AND COALESCE(b.bill_lifecycle_status, 'active') = 'active' AND s.status IN ('pending', 'partial')"),
            'recurring' => (float)DB::value("SELECT COALESCE(SUM(GREATEST(0, total_amount - COALESCE(amount_paid, 0))), 0) FROM recurring_bills WHERE status IN ('unpaid', 'overdue') AND COALESCE(bill_lifecycle_status, 'active') = 'active'"),
        ];
        $ledgerSql = "SELECT COALESCE(SUM(t.total), 0) - COALESCE((SELECT SUM(pa.amount + pa.discount + pa.withholding) FROM payment_applications pa
                          JOIN transactions p ON p.id = pa.payment_txn_id AND p.is_voided = 0
                          JOIN water_postings w2 ON w2.txn_id = pa.target_txn_id AND w2.status = 'posted' AND w2.source_type = :s2), 0)
                        FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                       WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice' AND w.source_type = :s";
        $ledgerTotal = 0.0;
        $waterTotal = 0.0;
        foreach ($openWater as $k => $amt) {
            $st = WaterPosting::sourceType($k);
            $ledger = r2((float)DB::value($ledgerSql, ['s' => $st, 's2' => $st]));
            $this->check("open balance $k: water records vs ledger invoices", r2($amt), $ledger);
            $ledgerTotal = r2($ledgerTotal + $ledger);
            $waterTotal = r2($waterTotal + $amt);
        }
        $res['amount_in'] = $waterTotal;
        $res['amount_out'] = $ledgerTotal;
        // the opening balance journal balances, and the ledger equals the legacy trial balance per account
        $ob = WaterPosting::byEvent(self::OB_EVENT);
        if ($ob) {
            $sums = DB::row('SELECT COALESCE(SUM(debit), 0) AS dr, COALESCE(SUM(credit), 0) AS cr FROM journal_lines WHERE txn_id = :t', ['t' => $ob['txn_id']]);
            $this->check('opening balance journal: debits = credits', r2((float)$sums['dr']), r2((float)$sums['cr']));
        }
        $legacyTb = DB::all("SELECT AccountID, SUM(COALESCE(Debit, 0) - COALESCE(Credit, 0)) AS bal FROM legacy_journalentries
                              WHERE Status = 'A' AND DATE(TransactionDate) <= :d GROUP BY AccountID", ['d' => $this->cutover]);
        $tbMismatch = 0;
        $tbChecked = 0;
        foreach ($legacyTb as $l) {
            $acct = $this->coaMap[(int)$l['AccountID']] ?? null;
            if (!$acct) {
                continue;
            }
            $gl = r2((float)DB::value('SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN transactions t ON t.id = j.txn_id WHERE j.account_id = :a AND t.is_voided = 0', ['a' => $acct]));
            $tbChecked++;
            if (abs($gl - (float)$l['bal']) >= 0.005) {
                $tbMismatch++;
                $this->note('ledger', 'Account ' . DB::value('SELECT CONCAT(code, \' \', name) FROM accounts WHERE id = :id', ['id' => $acct]) . ': legacy balance ' . wm_money($l['bal']) . ', ledger ' . wm_money($gl) . ' (other postings exist on this account, or the cutover changed).');
            }
        }
        $this->check("ledger = legacy trial balance per account ($tbChecked accounts)", 0, $tbMismatch);
        $clearing = WaterPosting::defaultAccount('water_legacy_clearing');
        $clearingBal = $clearing ? r2((float)DB::value('SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN transactions t ON t.id = j.txn_id WHERE j.account_id = :a AND t.is_voided = 0', ['a' => $clearing])) : 0.0;
        $legacyBalanced = abs((float)DB::value("SELECT COALESCE(SUM(COALESCE(Debit, 0) - COALESCE(Credit, 0)), 0) FROM legacy_journalentries WHERE Status = 'A' AND DATE(TransactionDate) <= :d", ['d' => $this->cutover])) < 0.005;
        $this->check('clearing account balance (0.00 when the legacy ledger balanced)', 0.0, $clearingBal, $legacyBalanced ? abs($clearingBal) < 0.005 : true);
        // A/R control vs subledger, per A/R account carrying open bills
        foreach (DB::all("SELECT t.account_id, SUM(t.total) AS inv FROM water_postings w JOIN transactions t ON t.id = w.txn_id WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice' GROUP BY t.account_id") as $ar) {
            $ctl = r2((float)DB::value('SELECT COALESCE(SUM(j.debit - j.credit), 0) FROM journal_lines j JOIN transactions t ON t.id = j.txn_id WHERE j.account_id = :a AND t.is_voided = 0', ['a' => $ar['account_id']]));
            // the subledger of the account: its open water invoices (invoices less what is applied to them)
            $sub = r2((float)DB::value("SELECT COALESCE(SUM(t.total), 0) - COALESCE((SELECT SUM(pa.amount + pa.discount + pa.withholding) FROM payment_applications pa
                                              JOIN transactions p ON p.id = pa.payment_txn_id AND p.is_voided = 0
                                              JOIN transactions tg ON tg.id = pa.target_txn_id AND tg.account_id = :a2 AND tg.is_voided = 0
                                              JOIN water_postings w2 ON w2.txn_id = tg.id AND w2.status = 'posted' AND w2.txn_type = 'invoice'), 0)
                                          FROM water_postings w JOIN transactions t ON t.id = w.txn_id
                                         WHERE w.status = 'posted' AND t.is_voided = 0 AND t.txn_type = 'invoice' AND t.account_id = :a", ['a' => $ar['account_id'], 'a2' => $ar['account_id']]));
            $name = DB::value('SELECT CONCAT(code, \' \', name) FROM accounts WHERE id = :id', ['id' => $ar['account_id']]);
            $ok = abs($ctl - $sub) < 0.005;
            $this->check("A/R control vs subledger: $name", $ctl, $sub, $ok, true);
            if (!$ok) {
                $this->note('ledger', "A/R $name: control " . wm_money($ctl) . ' vs open bills ' . wm_money($sub) . ' (difference ' . wm_money($ctl - $sub) . ' has no bill behind it; see the opening-balance exceptions).');
            }
        }
        wm_out();
        wm_out('VERIFICATION  (source ' . $this->srcDb . ' -> target ' . $this->dstDb . ', cutover ' . $this->cutover . ', run ' . $this->runId . ')');
        wm_out(wm_table(['Check', 'Expected', 'Actual', 'Result'], $this->verifyRows));
        $new = Party::findMatches();
        $res['written'] += $new;
        $res['notes']['party_match_reviews_new'] = $new;
        wm_out('Identity review: ' . $new . ' new possible duplicate pair(s) queued (Parties > Identity Review).');
        $res['notes']['checks'] = $this->verifyRows;
        $res['notes']['result'] = $this->verifyOk ? 'PASS' : 'FAIL';
        wm_out('Result: ' . ($this->verifyOk ? 'PASS (all checks equal)' : 'FAIL (see the checks marked FAIL and the exceptions)'));
        return $res;
    }
}

// ---------------------------------------------------------------------------------------- command line
$opts = getopt('', ['source:', 'dry-run', 'phase:', 'cutover:', 'company:', 'allow-existing', 'coa-map:', 'import-membership', 'savings-product:', 'help']);
if (isset($opts['help']) || empty($opts['source'])) {
    wm_out('Usage: php tools/migrate_water.php --source=<former water database> [--dry-run] [--phase=' . implode('|', WaterMigration::PHASES) . '] [--cutover=YYYY-MM-DD] [--company=N] [--allow-existing]');
    wm_out('         [--coa-map=<reviewed crosswalk CSV>] [--import-membership --savings-product=<code>]');
    exit(empty($opts['source']) && !isset($opts['help']) ? 1 : 0);
}
$_SESSION = [];
Tenant::bootCli(isset($opts['company']) ? (int)$opts['company'] : null);
$adminId = (int)(DB::value("SELECT id FROM users WHERE is_platform = 0 AND status = 'active' AND id IN (SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.is_admin = 1) ORDER BY id LIMIT 1")
    ?: DB::value("SELECT id FROM users WHERE username = 'admin'") ?: 0);
if (!$adminId) {
    wm_err('No administrator user found in the target database.');
    exit(1);
}
Auth::actAs($adminId);
$cutover = (string)($opts['cutover'] ?? date('Y-m-d'));
if (!valid_date($cutover)) {
    wm_err('--cutover must be a date (YYYY-MM-DD).');
    exit(1);
}
$phases = WaterMigration::PHASES;
if (!empty($opts['phase'])) {
    if (!in_array($opts['phase'], WaterMigration::PHASES, true)) {
        wm_err('Unknown phase ' . $opts['phase'] . '. Phases: ' . implode(', ', WaterMigration::PHASES));
        exit(1);
    }
    $phases = [$opts['phase']];
}
if (!wm_table_exists(DB::pdo(), DB::databaseName(), 'water_migration_log')) {
    wm_err('The target database has no water tables. Run database/migrations/2026_12_01_water_billing.sql first.');
    exit(1);
}
$m = new WaterMigration((string)$opts['source'], $cutover, isset($opts['dry-run']), [
    'coa_map' => $opts['coa-map'] ?? null,
    'import_membership' => isset($opts['import-membership']),
    'savings_product' => $opts['savings-product'] ?? '',
]);
if (!$m->preflight(isset($opts['allow-existing']))) {
    exit(1);
}
$ok = $m->run($phases);
exit($ok ? 0 : 1);
