<?php
/**
 * Ortak CRUD/listeleme sorgularını tek yerde toplayan temel Repository sınıfı.
 * Amaç: her sayfada tekrar eden "$db->query(\"SELECT * FROM ...\")" bloklarını
 * ortadan kaldırmak. Bir modülün Repository'si sadece kendine özgü sorguları
 * yazar (bkz. templates/views ve src/controllers örnekleri), geri kalan her
 * şeyi buradan miras alır.
 *
 * Proje genelindeki mevcut static-method/global-class konvansiyonuyla tutarlı
 * kalsın diye (UserService, QueueHelper, DBHelper vb. hep static) bu sınıf da
 * static metotlarla, örneklenmeden (instantiate edilmeden) kullanılır:
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
     * @param string $where Parametreli WHERE koşulu (ör. "role = ? AND is_active = ?")
     * @param array $params Koşuldaki "?" yer tutucularına karşılık gelen değerler
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
