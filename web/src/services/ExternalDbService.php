<?php

declare(strict_types=1);

require_once __DIR__ . '/../secret_box.php';
require_once __DIR__ . '/../repositories/ExternalDbRepository.php';

/**
 * Service for managing external database connections (Roadmap 2.2).
 * Used by IVR Data Step (Roadmap 2.3) and plugins.
 */
class ExternalDbService
{
    public const SUPPORTED_DRIVERS = [
        'mysql'  => 'MySQL / MariaDB',
        'pgsql'  => 'PostgreSQL',
        'sqlite' => 'SQLite',
    ];

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors: array<string, string>}
     */
    public static function saveConnection(array $data, ?int $userId = null): array
    {
        $errors = [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            $errors['name'] = 'Name must be between 1 and 100 characters';
        }

        $driver = strtolower(trim((string)($data['driver'] ?? 'mysql')));
        if (!array_key_exists($driver, self::SUPPORTED_DRIVERS)) {
            $errors['driver'] = 'Unsupported database driver';
        }

        $host = trim((string)($data['host'] ?? 'localhost'));
        if ($driver !== 'sqlite' && $host === '') {
            $errors['host'] = 'Host cannot be empty';
        }

        $port = (int)($data['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        if ($driver !== 'sqlite' && ($port <= 0 || $port > 65535)) {
            $errors['port'] = 'Port must be between 1 and 65535';
        }

        $dbName = trim((string)($data['database_name'] ?? ''));
        if ($dbName === '') {
            $errors['database_name'] = 'Database name cannot be empty';
        }

        $username = trim((string)($data['username'] ?? ''));
        if ($driver !== 'sqlite' && $username === '') {
            $errors['username'] = 'Username cannot be empty';
        }

        $timeout = (int)($data['timeout_sec'] ?? 3);
        if ($timeout < 1 || $timeout > 10) {
            $errors['timeout_sec'] = 'Timeout must be between 1 and 10 seconds';
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $charset = trim((string)($data['charset'] ?? 'utf8mb4'));
        $sslMode = trim((string)($data['ssl_mode'] ?? 'prefer'));
        $rawPassword = (string)($data['password'] ?? '');

        $db = getDB();

        if ($id !== null) {
            $existing = ExternalDbRepository::findById($id);
            if (!$existing) {
                return ['ok' => false, 'errors' => ['id' => 'Connection not found']];
            }

            // Keep existing password if not provided
            $passwordEnc = $existing['password_encrypted'];
            if ($rawPassword !== '') {
                $passwordEnc = SecretBox::encrypt($rawPassword);
            }

            $stmt = $db->prepare(
                'UPDATE pbx_external_dbs
                 SET name = ?, driver = ?, host = ?, port = ?, database_name = ?,
                     username = ?, password_encrypted = ?, timeout_sec = ?, charset = ?,
                     ssl_mode = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $name, $driver, $host, $port, $dbName,
                $username, $passwordEnc, $timeout, $charset,
                $sslMode, $isActive, $id
            ]);

            return ['ok' => true, 'id' => $id, 'errors' => []];
        }

        $passwordEnc = $rawPassword !== '' ? SecretBox::encrypt($rawPassword) : null;
        $stmt = $db->prepare(
            'INSERT INTO pbx_external_dbs
             (name, driver, host, port, database_name, username, password_encrypted,
              timeout_sec, charset, ssl_mode, is_active, created_at, updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $stmt->execute([
            $name, $driver, $host, $port, $dbName,
            $username, $passwordEnc, $timeout, $charset,
            $sslMode, $isActive, $userId
        ]);
        $newId = (int)$db->lastInsertId();

        return ['ok' => true, 'id' => $newId, 'errors' => []];
    }

    public static function deleteConnection(int $id): bool
    {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM pbx_external_dbs WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function toggleActive(int $id, bool $isActive): bool
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE pbx_external_dbs SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$isActive ? 1 : 0, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Tests a database connection.
     *
     * @param array<string, mixed> $params Connection parameters or row from pbx_external_dbs
     * @return array{ok: bool, message: string, latency_ms: float}
     */
    public static function testConnection(array $params): array
    {
        $start = microtime(true);
        $id = !empty($params['id']) ? (int)$params['id'] : null;

        try {
            $pdo = self::connect($params);
            // Ping query
            $driver = (string)($params['driver'] ?? 'mysql');
            $pingSql = ($driver === 'sqlite') ? 'SELECT 1' : 'SELECT 1';
            $stmt = $pdo->query($pingSql);
            $val = $stmt ? (int)$stmt->fetchColumn() : 0;

            if ($val !== 1) {
                throw new RuntimeException('Ping query did not return expected result');
            }

            $latency = round((microtime(true) - $start) * 1000, 2);
            $msg = "Connection successful ({$latency} ms)";

            if ($id !== null) {
                ExternalDbRepository::updateTestStatus($id, 'ok', $msg);
            }

            return ['ok' => true, 'message' => $msg, 'latency_ms' => $latency];
        } catch (Throwable $e) {
            $latency = round((microtime(true) - $start) * 1000, 2);
            $errMsg = $e->getMessage();

            if ($id !== null) {
                ExternalDbRepository::updateTestStatus($id, 'error', $errMsg);
            }

            return ['ok' => false, 'message' => $errMsg, 'latency_ms' => $latency];
        }
    }

    /**
     * Executes a read-only parameterized query against an external database.
     *
     * @param int $connectionId ID of connection in pbx_external_dbs
     * @param string $sql SELECT query
     * @param array<int|string, mixed> $params Parameter values
     * @return array<int, array<string, mixed>> Result rows
     * @throws InvalidArgumentException When query is not strictly read-only
     */
    public static function executeQuery(int $connectionId, string $sql, array $params = []): array
    {
        $connRow = ExternalDbRepository::findById($connectionId);
        if (!$connRow) {
            throw new RuntimeException("External database connection #{$connectionId} not found");
        }
        if (empty($connRow['is_active'])) {
            throw new RuntimeException("External database connection '{$connRow['name']}' is disabled");
        }

        // Strict read-only query guard
        $cleanSql = trim(preg_replace('#/\*.*?\*/#s', '', $sql) ?? '');
        $cleanSql = trim(preg_replace('#--.*?(\r?\n|$)#', '', $cleanSql) ?? '');

        if (!preg_match('/^(SELECT|WITH)\b/i', $cleanSql)) {
            throw new InvalidArgumentException('Only read-only SELECT or WITH queries are permitted on external connections');
        }

        // Check for multiple semicolon-delimited statements
        $semiCount = substr_count(rtrim($cleanSql, ';'), ';');
        if ($semiCount > 0) {
            throw new InvalidArgumentException('Multiple SQL statements in a single query are not allowed');
        }

        $pdo = self::connect($connRow);
        $stmt = $pdo->prepare($cleanSql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Builds and returns a connected PDO instance for the given config.
     *
     * @param array<string, mixed> $config
     */
    public static function connect(array $config): PDO
    {
        $driver = strtolower(trim((string)($config['driver'] ?? 'mysql')));
        $host = trim((string)($config['host'] ?? 'localhost'));
        $port = (int)($config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        $dbName = trim((string)($config['database_name'] ?? ''));
        $username = trim((string)($config['username'] ?? ''));
        $timeout = max(1, min(10, (int)($config['timeout_sec'] ?? 3)));
        $charset = trim((string)($config['charset'] ?? 'utf8mb4'));

        // Handle password decryption
        $password = '';
        if (isset($config['password'])) {
            $password = (string)$config['password'];
        } elseif (!empty($config['password_encrypted'])) {
            $password = SecretBox::decrypt((string)$config['password_encrypted']);
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        switch ($driver) {
            case 'pgsql':
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
                break;
            case 'sqlite':
                $dsn = "sqlite:{$dbName}";
                break;
            case 'mysql':
            default:
                $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
                break;
        }

        return new PDO($dsn, $username, $password, $options);
    }
}
