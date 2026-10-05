<?php
/**
 * Base repository class that gathers the common CRUD/list queries in one place.
 * Goal: get rid of the "$db->query(\"SELECT * FROM ...\")" blocks repeated on
 * every page. A module's repository writes only its own specific queries
 * (see the templates/views and src/controllers examples) and inherits
 * everything else from here.
 *
 * To stay consistent with the existing static-method/global-class convention
 * across the project (UserService, QueueHelper, DBHelper etc. are all static),
 * this class is used through static methods too, without instantiation:
 *
 *   class QueueRepository extends BaseRepository {
 *       protected static string $table = 'pbx_queues';
 *   }
 *   QueueRepository::findAll('title ASC');
 *   QueueRepository::findById(3);
 */
abstract class BaseRepository
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    protected static function db(): PDO
    {
        return getDB();
    }

    public static function findAll(string $orderBy = ''): array
    {
        $sql = 'SELECT * FROM ' . static::$table;
        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $orderBy;
        }
        return static::db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findById($id): ?array
    {
        $stmt = static::db()->prepare('SELECT * FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @param string $where Parameterized WHERE condition (e.g. "role = ? AND is_active = ?")
     * @param array $params Values for the "?" placeholders in the condition
     */
    public static function findWhere(string $where, array $params = [], string $orderBy = ''): array
    {
        $sql = 'SELECT * FROM ' . static::$table . ' WHERE ' . $where;
        if ($orderBy !== '') {
            $sql .= ' ORDER BY ' . $orderBy;
        }
        $stmt = static::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findOneWhere(string $where, array $params = []): ?array
    {
        $rows = static::findWhere($where, $params);
        return $rows[0] ?? null;
    }

    public static function count(string $where = '1=1', array $params = []): int
    {
        $stmt = static::db()->prepare('SELECT COUNT(*) FROM ' . static::$table . ' WHERE ' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function exists($id): bool
    {
        return static::findById($id) !== null;
    }
}
