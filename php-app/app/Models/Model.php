<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Base model. Concrete models declare a table name and column whitelist;
 * everything else is inherited.
 */
abstract class Model
{
    protected static string $table = '';
    /** @var list<string> Columns that may be written by create()/update(). */
    protected static array $fillable = [];

    public static function table(): string
    {
        return static::$table;
    }

    protected static function db(): Database
    {
        return Database::instance();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return static::db()->first('SELECT * FROM ' . static::$table . ' WHERE id = ? LIMIT 1', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findBy(string $column, mixed $value): ?array
    {
        if (!in_array($column, array_merge(static::$fillable, ['id']), true)) {
            return null;
        }
        return static::db()->first('SELECT * FROM ' . static::$table . " WHERE {$column} = ? LIMIT 1", [$value]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public static function create(array $data): ?array
    {
        $payload = static::filter($data);
        if ($payload === []) {
            return null;
        }
        $id = static::db()->insert(static::$table, $payload);
        return static::find($id);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function modify(int $id, array $data): int
    {
        $payload = static::filter($data);
        if ($payload === []) {
            return 0;
        }
        return static::db()->update(static::$table, $payload, ['id' => $id]);
    }

    public static function remove(int $id): int
    {
        return static::db()->query('DELETE FROM ' . static::$table . ' WHERE id = ?', [$id])->rowCount();
    }

    public static function count(string $where = '1=1', array $params = []): int
    {
        return (int) static::db()->scalar('SELECT COUNT(*) FROM ' . static::$table . " WHERE {$where}", $params);
    }

    /**
     * Explicit column map: table => allowed columns.
     * Kept next to each model so a typo can never inject an unexpected column.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected static function filter(array $data): array
    {
        return array_intersect_key($data, array_flip(static::$fillable));
    }

    /** @return list<string> */
    public static function fillable(): array
    {
        return static::$fillable;
    }
}
