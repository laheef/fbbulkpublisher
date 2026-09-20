<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin, driver-aware PDO wrapper.
 *
 * Every query goes through prepared statements — there is no string
 * interpolation of user data anywhere in this class.
 *
 * Supports MySQL/MariaDB (production) and SQLite (demo + automated tests).
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private string $driver;
    private int $txDepth = 0;

    private function __construct(array $cfg)
    {
        $this->driver = strtolower((string) ($cfg['driver'] ?? 'mysql'));

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            if ($this->driver === 'sqlite') {
                $path = (string) ($cfg['sqlite_path'] ?? ':memory:');
                if ($path !== ':memory:') {
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                }
                $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                $this->pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $cfg['host'] ?? '127.0.0.1',
                    (int) ($cfg['port'] ?? 3306),
                    $cfg['database'] ?? '',
                    $cfg['charset'] ?? 'utf8mb4'
                );
                $this->pdo = new PDO($dsn, (string) ($cfg['username'] ?? ''), (string) ($cfg['password'] ?? ''), $options);
            }
        } catch (PDOException $e) {
            // Never leak credentials in the message.
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        // Pin the connection to UTC so stored/derived timestamps never drift.
        if ($this->driver !== 'sqlite') {
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
    }

    public static function instance(?array $cfg = null): Database
    {
        if (self::$instance === null) {
            $cfg ??= Config::get('database', []);
            self::$instance = new self($cfg);
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /** @param array<string,mixed>|list<mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->query($sql, $params)->fetchAll();
        return $rows;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @param array<string,mixed> $data
     * @return int inserted id
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $quoted = array_map(fn (string $c): string => $this->quoteIdent($c), $columns);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdent($table),
            implode(', ', $quoted),
            implode(', ', $placeholders)
        );

        $this->query($sql, $this->bindable($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            return 0;
        }
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $this->quoteIdent($column) . ' = :set_' . $column;
        }
        $wheres = [];
        foreach (array_keys($where) as $column) {
            $wheres[] = $this->quoteIdent($column) . ' = :w_' . $column;
        }

        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        foreach ($where as $k => $v) {
            $params['w_' . $k] = $v;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdent($table),
            implode(', ', $sets),
            implode(' AND ', $wheres)
        );

        return $this->query($sql, $this->bindable($params))->rowCount();
    }

    public function beginTransaction(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
        } else {
            // Nested: emulate with savepoints so composition is safe.
            $this->pdo->exec('SAVEPOINT sp' . $this->txDepth);
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->pdo->commit();
            return;
        }
        $this->pdo->exec('RELEASE SAVEPOINT sp' . $this->txDepth);
    }

    public function rollBack(): void
    {
        $this->txDepth--;
        if ($this->txDepth <= 0) {
            $this->txDepth = 0;
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return;
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT sp' . $this->txDepth);
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /** SQL fragment for "now" in UTC, per driver. */
    public function nowExpression(): string
    {
        return $this->isSqlite() ? "datetime('now')" : 'UTC_TIMESTAMP()';
    }

    /**
     * SELECT ... FOR UPDATE SKIP LOCKED (MySQL) / plain SELECT (SQLite).
     * SQLite serialises writers, so a write-lock transaction is sufficient.
     */
    public function forUpdateSkipLocked(): string
    {
        return $this->isSqlite() ? '' : ' FOR UPDATE SKIP LOCKED';
    }

    private function quoteIdent(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException('Unsafe SQL identifier: ' . $identifier);
        }
        return $this->isSqlite() ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    /**
     * Booleans must be sent as ints to MySQL with emulated prepares disabled.
     *
     * @param array<string,mixed>|list<mixed> $data
     * @return array<string,mixed>|list<mixed>
     */
    private function bindable(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $data[$k] = $v ? 1 : 0;
            } elseif (is_array($v) || is_object($v)) {
                $data[$k] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return $data;
    }
}
