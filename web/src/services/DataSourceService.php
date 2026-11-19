<?php

declare(strict_types=1);

require_once __DIR__ . '/ExternalDbService.php';
require_once __DIR__ . '/WebhookService.php';
require_once __DIR__ . '/../secret_box.php';

/**
 * One way to ask an outside source for a value during a call: a database
 * connection, an HTTP address, or a value the call already has. Dynamic
 * Routing, the IVR data step, the auto-dialer and the caller-name lookup all
 * use this, so variables, limits and checks are the same everywhere.
 *
 * Variables: {name} in URLs, bodies and values (URL-encoded in a URL),
 * :name in SQL (always a bound parameter, never pasted into the query).
 * Names are the fixed ones in VARS, or var_NAME for a channel variable NAME.
 *
 * AGI sources run in the dialplan through the aipbx-agi service; see
 * AgiScriptService. Their test goes through AgiScriptService::test().
 */
final class DataSourceService
{
    public const TYPES = ['database', 'http', 'agi', 'value'];

    /** The variables every source can use (docs/dynamic-routing.md lists them). */
    public const VARS = ['input', 'caller_id', 'caller_name', 'did', 'uniqueid', 'linkedid', 'lang', 'date', 'time'];

    /** The dialplan expression that gives each variable's value. */
    public const DIALPLAN_VALUES = [
        'input' => '${DR_INPUT}', 'caller_id' => '${CALLERID(num)}', 'caller_name' => '${CALLERID(name)}',
        'did' => '${NORMALIZED_DID}', 'uniqueid' => '${UNIQUEID}', 'linkedid' => '${CHANNEL(linkedid)}',
        'lang' => '${CHANNEL(language)}', 'date' => '${STRFTIME(${EPOCH},,%Y-%m-%d)}', 'time' => '${STRFTIME(${EPOCH},,%H:%M)}',
    ];

    public const TIMEOUT_MS = 2500;
    /** Extra result variables: at most this many, each value at most VALUE_MAX characters. */
    public const MAX_VARS = 20;
    public const VALUE_MAX = 128;

    private const NAME_RE = '(?:var_[A-Za-z][A-Za-z0-9_]{0,39}|[a-z][a-z_]{1,19})';

    /**
     * Scans SQL outside string literals, backtick identifiers, and comments.
     */
    private static function scanSqlPlaceholders(string $sql, string $namePattern, callable $onParam): string
    {
        $re = "~'(?:\\\\.|''|[^'\\\\])*'|\"(?:\\\\.|\"\"|[^\"\\\\])*\"|`(?:``|[^`])*`|--[^\\r\\n]*|#[^\\r\\n]*|/\\*[\\s\\S]*?\\*/|(?<![:\\w]):({$namePattern})\\b~";
        return (string) preg_replace_callback($re, static function (array $m) use ($onParam): string {
            if (!isset($m[1]) || $m[1] === '') {
                return $m[0];
            }
            return $onParam($m[0], $m[1]);
        }, $sql);
    }

    /**
     * The variable names a template uses, known ones only, each once.
     *
     * @return list<string>
     */
    public static function placeholders(string $template, bool $sql = false): array
    {
        if ($sql) {
            $found = [];
            self::scanSqlPlaceholders($template, self::NAME_RE, static function (string $full, string $name) use (&$found): string {
                if (self::isVar($name)) {
                    $found[] = $name;
                }
                return $full;
            });
            return array_values(array_unique($found));
        }
        preg_match_all('/\{(' . self::NAME_RE . ')\}/', $template, $m);
        return array_values(array_unique(array_filter($m[1], [self::class, 'isVar'])));
    }

    public static function isVar(string $name): bool
    {
        return in_array($name, self::VARS, true) || preg_match('/^var_[A-Za-z][A-Za-z0-9_]{0,39}$/', $name) === 1;
    }

    /** Unknown names in a template, for a save-time error. @return list<string> */
    public static function unknownPlaceholders(string $template, bool $sql = false): array
    {
        if ($sql) {
            $unknown = [];
            self::scanSqlPlaceholders($template, '[A-Za-z_][A-Za-z0-9_]*', static function (string $full, string $name) use (&$unknown): string {
                if (!self::isVar($name)) {
                    $unknown[] = $name;
                }
                return $full;
            });
            return array_values(array_unique($unknown));
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $m);
        return array_values(array_unique(array_filter($m[1], fn (string $n): bool => !self::isVar($n))));
    }

    /** {name} replaced by its value; URL-encoded for a URL. Unknown names stay as written. */
    public static function fill(string $template, array $values, bool $urlEncode = false): string
    {
        return (string) preg_replace_callback('/\{(' . self::NAME_RE . ')\}/', function (array $m) use ($values, $urlEncode): string {
            if (!self::isVar($m[1])) {
                return $m[0];
            }
            $v = (string) ($values[$m[1]] ?? '');
            return $urlEncode ? rawurlencode($v) : $v;
        }, $template);
    }

    /**
     * :name in SQL becomes a bound parameter; each occurrence its own name.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function bindSql(string $sql, array $values): array
    {
        $params = [];
        $n = 0;
        $sql = self::scanSqlPlaceholders($sql, self::NAME_RE, static function (string $full, string $varName) use (&$params, &$n, $values): string {
            if (!self::isVar($varName) && !array_key_exists($varName, $values)) {
                return $full;
            }
            $name = ':p' . (++$n);
            $params[$name] = (string) ($values[$varName] ?? '');
            return $name;
        });
        return [$sql, $params];
    }

    /** Date and time when the caller did not give them. */
    public static function withDefaults(array $values): array
    {
        return $values + ['date' => date('Y-m-d'), 'time' => date('H:i')];
    }

    /**
     * Checks a source before it is saved.
     *
     * @param array<string, mixed> $s
     * @return array<string, string> field => error
     */
    public static function validate(array $s): array
    {
        $e = [];
        $type = (string) ($s['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) {
            return ['type' => t('data_source.err_type')];
        }
        if ($type === 'database') {
            $sql = trim((string) ($s['sql'] ?? ''));
            if ((int) ($s['db_id'] ?? 0) <= 0 || !ExternalDbRepository::exists((int) $s['db_id'])) {
                $e['db_id'] = t('data_source.err_db');
            }
            if (!preg_match('/^(SELECT|WITH)\b/i', $sql)) {
                $e['sql'] = t('data_source.err_sql');
            } elseif ($bad = self::unknownPlaceholders($sql, true)) {
                $e['sql'] = sprintf(t('data_source.err_var'), ':' . $bad[0]);
            }
        } elseif ($type === 'http') {
            $url = trim((string) ($s['url'] ?? ''));
            if ($bad = self::unknownPlaceholders($url . ' ' . (string) ($s['body'] ?? ''))) {
                $e['url'] = sprintf(t('data_source.err_var'), '{' . $bad[0] . '}');
            } else {
                try {
                    WebhookService::validateTargetUrl(self::fill($url, array_fill_keys(self::placeholders($url), '1'), true));
                } catch (\Throwable $ex) {
                    $e['url'] = $ex->getMessage();
                }
            }
            if (!in_array(strtoupper((string) ($s['method'] ?? 'GET')), ['GET', 'POST'], true)) {
                $e['method'] = t('data_source.err_method');
            }
            if (self::parseHeaders((string) ($s['headers'] ?? '')) === null) {
                $e['headers'] = t('data_source.err_headers');
            }
        } elseif ($type === 'value') {
            $v = trim((string) ($s['value'] ?? ''));
            if ($v === '' || self::unknownPlaceholders($v)) {
                $e['value'] = t('data_source.err_value');
            }
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/i', (string) ($s['agi'] ?? ''))) {
            $e['agi'] = t('data_source.err_agi');
        }
        return $e;
    }

    /**
     * "Name: value" lines; null when a line is not a header.
     *
     * @return list<string>|null
     */
    public static function parseHeaders(string $text): ?array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9-]{1,64}:\s*[^\r\n]{0,2000}$/', $line)) {
                return null;
            }
            $out[] = $line;
        }
        return $out;
    }

    /** Headers carry tokens: stored encrypted, decrypted only for the request. */
    public static function sealHeaders(string $text): string
    {
        return trim($text) === '' ? '' : SecretBox::encrypt(trim($text));
    }

    /**
     * Runs a database, HTTP or value source.
     *
     * status: found, not_found, or timeout (any error: the call takes the error branch).
     *
     * @param array<string, mixed> $s source settings (see validate())
     * @param array<string, string> $values variable values
     * @return array{status: string, value: string, vars: array<string, string>, ms: int, error: string}
     */
    public static function run(array $s, array $values, int $timeoutMs = self::TIMEOUT_MS): array
    {
        $t0 = hrtime(true);
        $values = self::withDefaults($values);
        try {
            [$status, $value, $vars] = match ((string) ($s['type'] ?? '')) {
                'database' => self::runDatabase($s, $values),
                'http' => self::runHttp($s, $values, $timeoutMs),
                'value' => self::found(self::fill((string) ($s['value'] ?? ''), $values), []),
                default => throw new InvalidArgumentException('source type'),
            };
            $error = '';
        } catch (\Throwable $e) {
            [$status, $value, $vars, $error] = ['timeout', '', [], $e->getMessage()];
        }
        return ['status' => $status, 'value' => $value, 'vars' => $vars, 'ms' => (int) ((hrtime(true) - $t0) / 1e6), 'error' => $error];
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} */
    private static function found(string $value, array $vars): array
    {
        $value = self::clean($value);
        return [$value === '' ? 'not_found' : 'found', $value, $vars];
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} */
    private static function runDatabase(array $s, array $values): array
    {
        [$sql, $params] = self::bindSql((string) ($s['sql'] ?? ''), $values);
        $rows = ExternalDbService::executeQuery((int) ($s['db_id'] ?? 0), $sql, $params);
        if (!$rows) {
            return ['not_found', '', []];
        }
        $row = $rows[0];
        return self::found((string) (reset($row) ?? ''), self::flatVars($row));
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} */
    private static function runHttp(array $s, array $values, int $timeoutMs): array
    {
        $url = self::fill((string) ($s['url'] ?? ''), $values, true);
        $resolve = WebhookService::validatedResolve($url);
        $headers = self::parseHeaders(SecretBox::decrypt((string) ($s['headers_enc'] ?? ''))) ?? [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_CONNECTTIMEOUT_MS => min(1500, $timeoutMs),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'AiPBX',
        ]);
        if ($resolve) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }
        if (strtoupper((string) ($s['method'] ?? 'GET')) === 'POST') {
            $body = trim((string) ($s['body'] ?? ''));
            $body = $body === '' ? (string) json_encode($values) : self::fill($body, array_map(
                // Inside a JSON body template a value must not break the JSON.
                fn ($v): string => substr((string) json_encode((string) $v, JSON_UNESCAPED_UNICODE), 1, -1),
                $values
            ));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/json';
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($code === 404) {
            return ['not_found', '', []];
        }
        if (!is_string($resp) || $code < 200 || $code >= 300) {
            throw new RuntimeException($err !== '' ? $err : 'HTTP ' . $code);
        }
        return self::fromBody(trim($resp), trim((string) ($s['json_path'] ?? '')));
    }

    /**
     * The answer of an HTTP source: plain text is the value; JSON gives the value
     * at the path (or "value"/"result"/the first field) and its object's fields.
     *
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    public static function fromBody(string $body, string $path = ''): array
    {
        $json = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $path !== '' ? ['not_found', '', []] : self::found($body, []);
        }
        if (!is_array($json)) {
            if ($path !== '' || $json === null || $json === false) {
                return ['not_found', '', []];
            }
            return self::found(is_bool($json) ? '1' : (string) $json, []);
        }
        $node = $json;
        $parent = $json;
        if ($path !== '') {
            foreach (explode('.', $path) as $key) {
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    return ['not_found', '', []];
                }
                $parent = $node;
                $node = $node[$key];
            }
        } elseif (array_is_list($json) && isset($json[0]) && is_array($json[0])) {
            $node = $parent = $json[0];
        }
        if (is_array($node)) {
            $parent = $node;
            $node = $node['value'] ?? $node['result'] ?? self::firstScalar($node);
        }
        if ($node === null || $node === false || $node === []) {
            return ['not_found', '', self::flatVars($parent)];
        }
        return self::found(is_bool($node) ? '1' : (string) (is_scalar($node) ? $node : ''), self::flatVars($parent));
    }

    private static function firstScalar(array $a): mixed
    {
        foreach ($a as $v) {
            if (is_scalar($v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * The scalar fields of a row or object as variable suffixes (upper case,
     * letters, digits and _), at most MAX_VARS.
     *
     * @return array<string, string>
     */
    public static function flatVars(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (count($out) >= self::MAX_VARS) {
                break;
            }
            $name = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9_]+/', '_', (string) $k), '_'));
            if ($name === '' || !(is_scalar($v) || $v === null)) {
                continue;
            }
            $out[substr($name, 0, 32)] = self::clean(is_bool($v) ? ($v ? '1' : '0') : (string) $v);
        }
        return $out;
    }

    /** Safe in a channel variable and in "a|b" answers: no control characters, no |, no ; or ^. */
    public static function clean(string $v, int $max = self::VALUE_MAX): string
    {
        $v = preg_replace('/[\x00-\x1F\x7F|;^]+/u', ' ', $v) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    /**
     * The answer the dialplan reads with CURL(), on one line:
     * "status|value|NAME=value|NAME=value" (values carry no "|").
     *
     * @param array{status: string, value: string, vars: array<string, string>} $r
     */
    public static function encodeAnswer(array $r): string
    {
        $out = $r['status'] . '|' . self::clean($r['value']);
        foreach ($r['vars'] as $k => $v) {
            $out .= '|' . $k . '=' . self::clean($v);
        }
        return $out . "\n";
    }

    /**
     * Writes to an external database: one INSERT or UPDATE, only on a connection
     * where writing was turned on. Variables are bound like in run(); the
     * caller's own names (an auto-dialer result, ...) are allowed too.
     * What it may really change is set by the database user's own rights.
     *
     * @param array<string, string> $values
     */
    public static function write(int $connectionId, string $sql, array $values): int
    {
        $conn = ExternalDbRepository::findById($connectionId);
        if (!$conn || empty($conn['is_active'])) {
            throw new RuntimeException(t('data_source.err_db'));
        }
        if (empty($conn['allow_write'])) {
            throw new RuntimeException(t('data_source.err_write_off'));
        }
        $problem = self::writeProblem($sql, array_keys($values));
        if ($problem !== null) {
            throw new InvalidArgumentException($problem);
        }
        [$sql, $params] = self::bindSql(rtrim(trim($sql), ';'), self::withDefaults($values));
        $stmt = ExternalDbService::connect($conn, true)->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Null when the statement is one INSERT or UPDATE with known variables only.
     *
     * @param list<string> $allowed names besides VARS
     */
    public static function writeProblem(string $sql, array $allowed = []): ?string
    {
        $check = preg_replace("/'(?:''|\\\\'|[^'])*'/s", "''", $sql) ?? $sql;
        $check = trim(preg_replace('#/\*.*?\*/|(--|\#)[^\n]*#s', '', $check) ?? '');
        if (!preg_match('/^(INSERT|UPDATE)\b/i', $check)) {
            return t('data_source.err_write_sql');
        }
        if (substr_count(rtrim($check, "; \t\r\n"), ';') > 0
            || preg_match('/\b(DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|RENAME|LOAD|OUTFILE|DUMPFILE|HANDLER|CALL|EXEC)\b/i', $check)) {
            return t('data_source.err_write_sql');
        }
        $bad = array_values(array_diff(self::unknownPlaceholders($sql, true), $allowed));
        return $bad ? sprintf(t('data_source.err_var'), ':' . $bad[0]) : null;
    }
}
