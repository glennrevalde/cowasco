<?php
declare(strict_types=1);

/**
 * Thin PDO wrapper.
 *
 * Every module reads and writes "the company database" through this class. Which database that is,
 * is decided in one place only: Tenant (app/Tenant.php) calls useDatabase() with the database the master
 * database maps the logged-in user's company to. With several companies and none chosen for the session,
 * the connection is blocked, so nothing can be read from any company by accident.
 */
final class DB
{
    private static ?PDO $pdo = null;
    /** the company database in use; null = the one in config/config.php (the first company) */
    private static ?string $database = null;
    /** set while no company is chosen: why the database cannot be used */
    private static ?string $blocked = null;
    /** Callbacks waiting for the current transaction to be committed (DB::afterCommit) */
    private static array $afterCommit = [];
    /** The water-billing pages run with the SQL mode their code was written for (MySQL 5.5's default, not strict) */
    private static bool $relaxedMode = false;

    /**
     * Switches this request's connection to the non-strict SQL mode of the former water-billing program. Its pages
     * and endpoints call this (water/database/config.php); the accounting pages keep the strict mode.
     */
    public static function relaxSqlMode(): void
    {
        self::$relaxedMode = true;
        if (self::$pdo !== null) {
            self::$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        }
    }

    public static function isRelaxed(): bool
    {
        return self::$relaxedMode;
    }

    /**
     * Back to the strict SQL mode of the accounting pages after a water file was loaded for its functions only (a
     * coop page that uses the water code, such as Consolidated Import: the request stays strict).
     */
    public static function restoreStrict(): void
    {
        self::$relaxedMode = false;
        if (self::$pdo !== null) {
            self::$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
    }

    /**
     * Runs $fn with the relaxed SQL mode the water code was written for (water code called from an accounting page);
     * the strict mode is back afterwards. Nothing changes on a request that is relaxed already.
     */
    public static function withRelaxed(callable $fn)
    {
        if (self::$relaxedMode) {
            return $fn();
        }
        $pdo = self::pdo();
        self::$relaxedMode = true;
        $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        try {
            return $fn();
        } finally {
            self::restoreStrict();
        }
    }

    /**
     * The strict SQL mode until the returned object goes out of scope ($guard = DB::strictScope(); at the top of a
     * method: the mode is back to relaxed when the method returns or throws). Nothing happens on a strict request.
     */
    public static function strictScope(): object
    {
        if (!self::$relaxedMode || self::$pdo === null) {
            return new class {
            };
        }
        $pdo = self::$pdo;
        $was = (string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (str_contains($was, 'STRICT_TRANS_TABLES')) {
            return new class {
            };
        }
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return new class($pdo, $was) {
            public function __construct(private PDO $pdo, private string $was)
            {
            }

            public function __destruct()
            {
                try {
                    $this->pdo->exec('SET SESSION sql_mode = ' . $this->pdo->quote($this->was));
                } catch (Throwable $e) {
                    // the connection is gone; nothing to restore
                }
            }
        };
    }

    /**
     * Runs $fn with the strict SQL mode of the accounting pages, also when the request runs relaxed (a water page or
     * endpoint): every ledger document a water event posts is written under the same rules as one entered on an
     * accounting page (a value that does not fit is an error, never silently cut). The mode is restored afterwards.
     */
    public static function withStrict(callable $fn)
    {
        if (!self::$relaxedMode) {
            return $fn();
        }
        $pdo = self::pdo();
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        try {
            return $fn();
        } finally {
            $pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (self::$blocked !== null) {
                throw new RuntimeException(self::$blocked);
            }
            self::$pdo = self::connect(self::databaseName());
        }
        return self::$pdo;
    }

    /**
     * A new connection with this system's settings, to one database ($database) or to the server only (null).
     * Same credentials as config/config.php; used here, by Master and by the company provisioning.
     */
    public static function connect(?string $database): PDO
    {
        $c = $GLOBALS['CONFIG']['db'];
        if ($database !== null && !self::validName($database)) {
            throw new RuntimeException('Invalid database name.');
        }
        $dsn = "mysql:host={$c['host']};port={$c['port']};" . ($database !== null ? "dbname=$database;" : '') . "charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);
        // Same behaviour on MySQL 5.5 - 8.x and MariaDB, text compared with the tables' collation,
        // and database timestamps in the app's time zone
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET SESSION sql_mode = '" . (self::$relaxedMode ? 'NO_ENGINE_SUBSTITUTION' : 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION') . "'");
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        return $pdo;
    }

    /** Database names are only ever made by the system: letters, digits and underscores */
    public static function validName(string $name): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $name);
    }

    /** The company database this request works on */
    public static function databaseName(): string
    {
        return self::$database ?? (string)$GLOBALS['CONFIG']['db']['name'];
    }

    /** Switches every later query to another company database (called by Tenant only) */
    public static function useDatabase(string $name): void
    {
        if (!self::validName($name)) {
            throw new RuntimeException('Invalid database name.');
        }
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            throw new RuntimeException('The company database cannot be changed in the middle of a transaction.');
        }
        if ($name !== self::databaseName()) {
            self::$pdo = null;
        }
        self::$database = $name;
        self::$blocked = null;
    }

    /** No company database may be used until one is chosen (several companies, none in this session) */
    public static function block(string $why): void
    {
        self::$pdo = null;
        self::$blocked = $why;
    }

    public static function isBlocked(): bool
    {
        return self::$blocked !== null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with((string)$k, ':') ? $k : ':' . $k);
            if (is_bool($v)) {
                $st->bindValue($key, $v ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($v)) {
                $st->bindValue($key, $v, PDO::PARAM_INT);
            } elseif ($v === null) {
                $st->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue($key, (string)$v, PDO::PARAM_STR);
            }
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function value(string $sql, array $params = [])
    {
        $r = self::run($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** key => value pairs from the first two columns */
    public static function pairs(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    /**
     * Tables whose updated_at is maintained here rather than by the database
     * (MySQL 5.5 allows only one auto-timestamp column per table, used for created_at).
     */
    private const TOUCH_UPDATED_AT = ['users', 'roles', 'accounts', 'customers', 'vendors', 'items', 'transactions', 'fixed_assets', 'process_formulas', 'uom_sets', 'classes', 'other_names', 'parties'];

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        $same = [];
        foreach ($data as $k => $v) {
            $sets[] = "`$k` = :set_$k";
            $params["set_$k"] = $v;
            $same[] = "CAST(`$k` AS BINARY) <=> CAST(:set_$k AS BINARY)";
        }
        if ($same && in_array($table, self::TOUCH_UPDATED_AT, true) && !array_key_exists('updated_at', $data)) {
            // Like ON UPDATE CURRENT_TIMESTAMP: changes only when a value really changes. It is listed first,
            // so it compares against the old values (single-table UPDATE assigns left to right).
            array_unshift($sets, '`updated_at` = IF(' . implode(' AND ', $same) . ', `updated_at`, NOW())');
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return self::run($sql, array_merge($params, $whereParams))->rowCount();
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
        $after = self::$afterCommit;
        self::$afterCommit = [];
        foreach ($after as $fn) {
            $fn();
        }
    }

    public static function rollBack(): void
    {
        self::$afterCommit = [];
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    /** Runs $fn once the current transaction is committed (at once when there is none); dropped on a rollback */
    public static function afterCommit(callable $fn): void
    {
        if (self::inTransaction()) {
            self::$afterCommit[] = $fn;
        } else {
            $fn();
        }
    }

    public static function inTransaction(): bool
    {
        return self::$pdo !== null && self::$pdo->inTransaction();
    }

    /** Run a callback inside one database transaction. */
    public static function tx(callable $fn)
    {
        $outer = self::inTransaction();
        if (!$outer) {
            self::begin();
        }
        try {
            $result = $fn();
            if (!$outer) {
                self::commit();
            }
            return $result;
        } catch (Throwable $e) {
            if (!$outer) {
                self::rollBack();
            }
            throw $e;
        }
    }

    /** Build "IN (:p0,:p1)" safely. Returns [sqlFragment, params]. */
    public static function in(string $prefix, array $values): array
    {
        if (!$values) {
            return ['(NULL)', []];
        }
        $ph = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $ph[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $v;
        }
        return ['(' . implode(',', $ph) . ')', $params];
    }
}
