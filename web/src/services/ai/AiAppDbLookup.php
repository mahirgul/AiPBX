<?php

declare(strict_types=1);

require_once __DIR__ . '/../DataSourceService.php';
require_once __DIR__ . '/../ExternalDbQueryService.php';

final class AiAppDbLookup
{
    private const CUSTOMER = ExternalDbQueryService::CUSTOMER;
    private const ID = ExternalDbQueryService::KEY_PATTERN;
    public const MAX_QUERIES = 10;

    /** One saved lookup per line. Only this metadata is sent to the voice model. */
    public static function metadata(array $lookups): array
    {
        $out = [];
        foreach ($lookups as $lookup) {
            $lookup = self::resolveDefinition($lookup);
            if ($lookup) {
                $out[] = ['id' => $lookup['id'], 'description' => $lookup['description'], 'params' => $lookup['params']];
            }
        }
        return $out;
    }

    public static function parse(string $input): array
    {
        $lookups = [];
        foreach (preg_split('/\R/', $input) ?: [] as $i => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match(self::ID, $line)) {
                if (count($lookups) >= self::MAX_QUERIES || isset($lookups[$line]) || !ExternalDbQueryService::find($line)) {
                    throw new InvalidArgumentException(sprintf(t('ai_apps.err_db_lookup'), $i + 1));
                }
                $lookups[$line] = ['id' => $line, 'saved' => true];
                continue;
            }
            [$id, $description, $dbId, $access, $sql] = array_pad(array_map('trim', explode('|', $line, 5)), 5, '');
            $db = ctype_digit($dbId) ? ExternalDbRepository::findById((int) $dbId) : null;
            $vars = DataSourceService::placeholders($sql, true);
            $params = array_values(array_diff($vars, [self::CUSTOMER]));
            if (count($lookups) >= self::MAX_QUERIES || !preg_match(self::ID, $id) || isset($lookups[$id])
                || $description === '' || mb_strlen($description) > 300
                || !$db || !empty($db['allow_write'])
                || !in_array($access, ['public', 'verified'], true)
                || in_array(self::CUSTOMER, $vars, true) !== ($access === 'verified')
                || strlen($sql) > 2000 || !preg_match('/^SELECT\b/i', $sql)
                || str_contains(rtrim($sql, ';'), ';')
                || DataSourceService::unknownPlaceholders($sql, true)
                || count($params) > 3 || array_filter($params, static fn (string $name): bool => !str_starts_with($name, 'var_'))) {
                throw new InvalidArgumentException(sprintf(t('ai_apps.err_db_lookup'), $i + 1));
            }
            $lookups[$id] = [
                'id' => $id, 'description' => $description, 'db_id' => (int) $dbId, 'access' => $access,
                'sql' => $sql, 'params' => array_map(static fn (string $name): string => substr($name, 4), $params),
            ];
        }
        return array_values($lookups);
    }

    private static function resolveDefinition(array $lookup): ?array
    {
        if (empty($lookup['saved'])) {
            return $lookup;
        }
        $query = ExternalDbQueryService::find($lookup['id']);
        if (!$query || !ExternalDbQueryService::available($query)
            || (isset($lookup['connection_scope']) && $lookup['connection_scope'] !== (int) $query['external_db_id'])) {
            return null;
        }
        $bindings = [];
        foreach (DataSourceService::placeholders($query['query_sql'], true) as $var) {
            if ($var === 'input' || (str_starts_with($var, 'var_') && $var !== self::CUSTOMER)) {
                $name = $var === 'input' ? $var : substr($var, 4);
                if (isset($bindings[$name])) {
                    return null;
                }
                $bindings[$name] = $var;
            }
        }
        if (count($bindings) > 3) {
            return null;
        }
        return $lookup + ['description' => $query['description'], 'db_id' => (int) $query['external_db_id'],
            'sql' => $query['query_sql'], 'access' => $query['access_mode'], 'params' => array_keys($bindings), 'bindings' => $bindings];
    }

    /** The resolver reads the saved app on every request, including during in-flight calls. */
    public static function run(int $appId, string $queryId, array $params, string $verifiedCustomer, array $context = []): array
    {
        if ($appId < 1 || !preg_match(self::ID, $queryId)) {
            return ['status' => 'unavailable'];
        }
        $app = AiAppService::get($appId);
        if (!$app || !$app['is_active'] || !AiAppService::listens($app['app_type'])) {
            return ['status' => 'unavailable'];
        }
        $config = AiAppService::config($app);
        $builtin = $app['app_type'] === AiAppService::BUILTIN_AGENT;
        $local = $builtin && str_starts_with((string) ($config['understand'] ?? ''), 'local:');
        if (empty($config['conversation']) || (!$local && AiAppService::isPrivacyCloudBlocked())
            || !PluginManager::isEnabled($builtin ? 'ai-knowledge' : 'ai-apps')
            || ($local && !PluginManager::isEnabled('ailocal'))) {
            return ['status' => 'unavailable'];
        }
        if ($builtin) {
            require_once __DIR__ . '/BuiltinAiAgentService.php';
            $config = BuiltinAiAgentService::toolsForCollection((int) ($config['knowledge_collection'] ?? 0));
        }
        $lookups = array_column($config['db_lookups'] ?? [], null, 'id');
        $lookup = isset($lookups[$queryId]) ? self::resolveDefinition($lookups[$queryId]) : null;
        if (!$lookup || array_diff($lookup['params'], array_keys($params)) || array_diff(array_keys($params), $lookup['params'])) {
            return ['status' => 'unavailable'];
        }
        $values = [];
        if (!empty($lookup['saved'])) {
            foreach (array_intersect(['caller_id', 'caller_name', 'did', 'uniqueid', 'linkedid', 'lang'], DataSourceService::placeholders($lookup['sql'], true)) as $var) {
                $values[$var] = is_string($context[$var] ?? null) ? mb_substr($context[$var], 0, 150) : '';
            }
        }
        if ($lookup['access'] === 'verified') {
            if (!preg_match('/^[A-Za-z0-9._@-]{1,64}$/D', $verifiedCustomer)) {
                return ['status' => 'verification_required'];
            }
            $values[self::CUSTOMER] = $verifiedCustomer;
        }
        foreach ($params as $name => $value) {
            if (!is_string($value) || !preg_match('/^[^\p{C}]{1,48}$/uD', $value) || trim($value) === '') {
                return ['status' => 'unavailable'];
            }
            $values[$lookup['bindings'][$name] ?? 'var_' . $name] = $value;
        }
        $db = ExternalDbRepository::findById($lookup['db_id']);
        if (!$db || empty($db['is_active']) || !empty($db['allow_write'])) {
            return ['status' => 'unavailable'];
        }
        $result = DataSourceService::run(['type' => 'database', 'db_id' => $lookup['db_id'], 'sql' => $lookup['sql']], $values);
        return match ($result['status']) {
            'found' => ['status' => 'found', 'value' => $result['value']],
            'not_found' => ['status' => 'not_found'],
            default => ['status' => 'unavailable'],
        };
    }
}
