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
    // No SQLite: its "database" is a file path on this server, which would let
    // a connection open any SQLite file the portal can read (Asterisk's own).
    public const SUPPORTED_DRIVERS = [
        'mysql'  => 'MySQL / MariaDB',
        'pgsql'  => 'PostgreSQL',
    ];

    /** Longest a lookup may take on the remote server (an IVR caller is waiting). */
    public const STATEMENT_TIMEOUT_MS = 2500;

    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors: array<string, string>}
     */
    public static function saveConnection(array $data, ?int $userId = null): array
    {
        $errors = [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            $errors['name'] = t('external_dbs.err_name');
        }

        $driver = strtolower(trim((string)($data['driver'] ?? 'mysql')));
        if (!array_key_exists($driver, self::SUPPORTED_DRIVERS)) {
            $errors['driver'] = t('external_dbs.err_driver');
        }

        // Host, database and user name go into the PDO DSN: a ';' there would
        // add DSN parameters of its own.
        $host = trim((string)($data['host'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9.:\[\]-]{1,253}$/', $host)) {
            $errors['host'] = t('external_dbs.err_host');
        }

        $port = (int)($data['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        if ($port <= 0 || $port > 65535) {
            $errors['port'] = t('external_dbs.err_port');
        }

        $dbName = trim((string)($data['database_name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $dbName)) {
            $errors['database_name'] = t('external_dbs.err_database');
        }

        $username = trim((string)($data['username'] ?? ''));
        if ($username === '' || strlen($username) > 128) {
            $errors['username'] = t('external_dbs.err_username');
        }

        $timeout = (int)($data['timeout_sec'] ?? 3);
        if ($timeout < 1 || $timeout > 10) {
            $errors['timeout_sec'] = t('external_dbs.err_timeout');
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;
        $charset = (string) ($data['charset'] ?? 'utf8mb4');
        $charset = in_array($charset, ['utf8mb4', 'utf8', 'latin1', 'latin5'], true) ? $charset : 'utf8mb4';
        $sslMode = (string) ($data['ssl_mode'] ?? 'prefer');
        $sslMode = in_array($sslMode, ['disable', 'prefer', 'require'], true) ? $sslMode : 'prefer';
        $rawPassword = (string)($data['password'] ?? '');

        $db = getDB();

        if ($id !== null) {
            $existing = ExternalDbRepository::findById($id);
            if (!$existing) {
                return ['ok' => false, 'errors' => ['id' => t('external_dbs.err_not_found')]];
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
        // A saved connection: what the form leaves out (or the list's test
        // button, which sends only the id) comes from the stored row; an empty
        // password field means "the stored one".
        if ($id !== null && ($stored = ExternalDbRepository::findById($id))) {
            foreach ($stored as $k => $v) {
                if (!isset($params[$k]) || $params[$k] === '') {
                    $params[$k] = $v;
                }
            }
            if ((string) ($params['password'] ?? '') === '') {
                unset($params['password']);
            }
        }

        try {
            $pdo = self::connect($params);
            // Ping query
            $driver = (string)($params['driver'] ?? 'mysql');
            $pingSql = 'SELECT 1';
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

        // Strict read-only query guard: strip single-quoted string literals and comments only for validation
        $validationSql = preg_replace("/'(?:''|\\\\'|[^'])*'/s", "''", $sql) ?? $sql;
        $validationSql = trim(preg_replace('#/\*.*?\*/#s', '', $validationSql) ?? '');
        $validationSql = trim(preg_replace('#(--|\#).*?(\r?\n|$)#', '', $validationSql) ?? '');
        // Writing files on the database server is not reading.
        if (preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b|\bLOAD_FILE\s*\(/i', $validationSql)) {
            throw new InvalidArgumentException('Only read-only SELECT or WITH queries are permitted on external connections');
        }

        if (!preg_match('/^(SELECT|WITH)\b/i', $validationSql)) {
            throw new InvalidArgumentException('Only read-only SELECT or WITH queries are permitted on external connections');
        }

        // Check for multiple semicolon-delimited statements
        $semiCount = substr_count(rtrim($validationSql, "; \t\r\n"), ';');
        if ($semiCount > 0) {
            throw new InvalidArgumentException('Multiple SQL statements in a single query are not allowed');
        }

        $pdo = self::connect($connRow);
        $stmt = $pdo->prepare(rtrim(trim($sql), ';'));
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

        if (!array_key_exists($driver, self::SUPPORTED_DRIVERS)
            || !preg_match('/^[A-Za-z0-9.:\[\]-]{1,253}$/', $host)
            || !preg_match('/^[A-Za-z0-9_$.-]{1,64}$/', $dbName)) {
            throw new InvalidArgumentException(t('external_dbs.err_settings'));
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $ms = self::STATEMENT_TIMEOUT_MS;
        if ($driver === 'pgsql') {
            $ssl = in_array($config['ssl_mode'] ?? 'prefer', ['disable', 'prefer', 'require'], true) ? $config['ssl_mode'] : 'prefer';
            $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$dbName};sslmode={$ssl}", $username, $password, $options);
            // Read-only and time-boxed, whatever rights the account has.
            $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
            $pdo->exec("SET statement_timeout = {$ms}");
            return $pdo;
        }
        $charset = in_array($charset, ['utf8mb4', 'utf8', 'latin1', 'latin5'], true) ? $charset : 'utf8mb4';
        $options[\Pdo\Mysql::ATTR_MULTI_STATEMENTS] = false;
        if (($config['ssl_mode'] ?? '') === 'require') {
            $options[\Pdo\Mysql::ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
        }
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}", $username, $password, $options);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        // MariaDB counts in seconds, MySQL in ms; whichever the server knows.
        try {
            $pdo->exec('SET SESSION max_statement_time = ' . ($ms / 1000));
        } catch (\Throwable $e) {
            try {
                $pdo->exec("SET SESSION max_execution_time = {$ms}");
            } catch (\Throwable $e2) {
                // an old server without either: the connect timeout still applies
            }
        }
        return $pdo;
    }
}
