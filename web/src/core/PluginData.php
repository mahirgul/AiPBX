<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginManager.php';
require_once dirname(__DIR__) . '/services/DataSourceService.php';
require_once dirname(__DIR__) . '/services/ExternalDbService.php';

/**
 * External databases for plugins (Database connections page).
 *
 * A plugin names a saved connection by id and never sees its host, user or
 * password. Its manifest must ask for the scope: "external_db.read" to query,
 * "external_db.write" to write; writing also needs "Allow writing" on the
 * connection. Variables work as everywhere else (DataSourceService): :name in
 * SQL is always a bound parameter.
 */
final class PluginData
{
    public const SCOPE_READ = 'external_db.read';
    public const SCOPE_WRITE = 'external_db.write';
    /** Rows a plugin may read at once (a campaign list, not a data export). */
    public const MAX_ROWS = 50000;

    private static function requireScope(string $slug, string $scope): void
    {
        $p = PluginManager::getPlugin($slug);
        if (!$p || !PluginManager::isEnabled($slug) || !in_array($scope, (array) ($p['scopes'] ?? []), true)) {
            throw new RuntimeException("Plugin {$slug} may not use external databases ({$scope})");
        }
    }

    /**
     * The active connections, without anything secret.
     *
     * @return list<array{id: int, name: string, driver: string, allow_write: bool}>
     */
    public static function connections(string $slug): array
    {
        self::requireScope($slug, self::SCOPE_READ);
        $out = [];
        foreach (ExternalDbRepository::findActive() as $c) {
            $out[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'driver' => (string) $c['driver'], 'allow_write' => !empty($c['allow_write'])];
        }
        return $out;
    }

    /**
     * A read-only query (SELECT / WITH).
     *
     * @param array<string, string> $values variables for :name
     * @return list<array<string, mixed>>
     */
    public static function query(string $slug, int $connectionId, string $sql, array $values = []): array
    {
        self::requireScope($slug, self::SCOPE_READ);
        [$bound, $params] = DataSourceService::bindSql($sql, DataSourceService::withDefaults($values));
        $rows = ExternalDbService::executeQuery($connectionId, $bound, $params);
        return array_slice($rows, 0, self::MAX_ROWS);
    }

    /**
     * One INSERT or UPDATE (DataSourceService::write). Returns the rows changed.
     *
     * @param array<string, string> $values
     */
    public static function write(string $slug, int $connectionId, string $sql, array $values): int
    {
        self::requireScope($slug, self::SCOPE_WRITE);
        return DataSourceService::write($connectionId, $sql, $values);
    }

    /** A write template is checked when it is saved, not when the first call ends. */
    public static function writeProblem(string $sql, array $allowed): ?string
    {
        return DataSourceService::writeProblem($sql, $allowed);
    }
}
