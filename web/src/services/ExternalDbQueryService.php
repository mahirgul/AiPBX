<?php

declare(strict_types=1);

require_once __DIR__ . '/DataSourceService.php';

final class ExternalDbQueryService
{
    public const KEY_PATTERN = '/^[a-z0-9_]{1,30}$/D';
    public const CUSTOMER = 'var_verified_customer_id';

    public static function all(): array
    {
        return getDB()->query('SELECT q.*, d.name AS connection_name, d.is_active AS connection_active, d.allow_write
            FROM pbx_external_db_queries q JOIN pbx_external_dbs d ON d.id = q.external_db_id ORDER BY q.query_key')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int|string $key): ?array
    {
        $column = is_int($key) ? 'q.id' : 'q.query_key';
        $stmt = getDB()->prepare("SELECT q.*, d.is_active AS connection_active, d.allow_write
            FROM pbx_external_db_queries q JOIN pbx_external_dbs d ON d.id = q.external_db_id WHERE $column = ?");
        $stmt->execute([$key]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function keysForConnection(int $id): array
    {
        $stmt = getDB()->prepare('SELECT q.query_key FROM pbx_external_db_queries q
            JOIN pbx_external_dbs d ON d.id = q.external_db_id
            WHERE d.id = ? AND q.is_active = 1 AND d.is_active = 1 AND d.allow_write = 0 ORDER BY q.query_key');
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function available(array $query): bool
    {
        return !empty($query['is_active']) && !empty($query['connection_active']) && empty($query['allow_write']);
    }

    public static function save(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $old = $id > 0 ? self::find($id) : null;
        $key = $old['query_key'] ?? trim((string) ($data['query_key'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $dbId = (int) ($data['external_db_id'] ?? 0);
        $db = ExternalDbRepository::findById($dbId);
        $sql = trim((string) ($data['query_sql'] ?? ''));
        $access = (string) ($data['access_mode'] ?? '');
        $active = !empty($data['is_active']) ? 1 : 0;
        if (($id > 0 && !$old) || !preg_match(self::KEY_PATTERN, $key) || $description === '' || mb_strlen($description) > 300
            || !$db || !empty($db['allow_write']) || !in_array($access, ['public', 'verified'], true)
            || (in_array(self::CUSTOMER, DataSourceService::placeholders($sql, true)) !== ($access === 'verified'))
            || strlen($sql) > 8000 || DataSourceService::unknownPlaceholders($sql, true)) {
            throw new InvalidArgumentException(t('external_dbs.query_invalid'));
        }
        try {
            ExternalDbService::assertReadOnlyQuery($sql);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(t('external_dbs.query_invalid'));
        }
        $conn = getDB();
        if (!$old) {
            $stmt = $conn->prepare('INSERT INTO pbx_external_db_queries (query_key, description, external_db_id, query_sql, access_mode, is_active)
                VALUES (?, ?, ?, ?, ?, ?)');
            try {
                $stmt->execute([$key, $description, $dbId, $sql, $access, $active]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                throw new InvalidArgumentException(t('external_dbs.query_key_exists'));
            }
            $id = (int) $conn->lastInsertId();
        } else {
            $conn->prepare('UPDATE pbx_external_db_queries SET description=?, external_db_id=?, query_sql=?, access_mode=?, is_active=? WHERE id=?')
                ->execute([$description, $dbId, $sql, $access, $active, $id]);
        }
        markPendingSync('ai_apps', 'external_db_query', $id, $key, 'update', $_SESSION['user_id'] ?? null);
        if (DBHelper::fetchColumn('SELECT 1 FROM pbx_ivr_data_steps WHERE saved_query_id = ? LIMIT 1', [$id])) {
            markPendingSync('ivrs', 'external_db_query', $id, $key, 'update', $_SESSION['user_id'] ?? null);
        }
        return $id;
    }

    public static function ivrSource(int $id): array
    {
        $query = $id > 0 ? self::find($id) : null;
        return $query && self::available($query) && $query['access_mode'] === 'public'
            ? ['type' => 'database', 'db_id' => (int) $query['external_db_id'], 'sql' => $query['query_sql']]
            : ['type' => 'database', 'db_id' => 0, 'sql' => ''];
    }
}
