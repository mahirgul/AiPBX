<?php

declare(strict_types=1);

require_once __DIR__ . '/ApiResponse.php';

/**
 * Stateless authentication, scope authorization, IP allowlist verification,
 * rate limiting, and in-memory request context for /api/v1/* endpoints.
 */
final class ApiRequestContext
{
    /** @var array<string, mixed>|null */
    private static ?array $tokenRow = null;

    /** @var list<string> */
    private static array $scopes = [];

    private static string $internalCsrf = '';

    /**
     * Authenticate the incoming API request via Bearer token (`apbx_...`)
     * or an active admin browser session (for interactive OpenAPI / admin tools).
     */
    public static function authenticate(): void
    {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authHeader === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }

        if (preg_match('/^Bearer\s+(apbx_[A-Za-z0-9_-]{20,80})$/i', trim($authHeader), $m)) {
            self::authenticateBearerToken($m[1]);
            return;
        }

        // Allow logged-in admin session for browser-based GETs or CSRF-verified mutations
        if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin' && _isSessionActivityFresh()) {
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                if (!verifyCSRFToken($csrfHeader)) {
                    ApiResponse::error('CSRF_REQUIRED', 'Missing or invalid X-CSRF-Token header for session-authenticated API mutation.', 403);
                }
            }
            self::$scopes = ['*'];
            self::$internalCsrf = getCSRFToken();
            $GLOBALS['AIPBX_AUDIT_ACTOR'] = null;
            return;
        }

        ApiResponse::error(
            'UNAUTHORIZED',
            'Missing or invalid Authorization: Bearer apbx_<token> header.',
            401
        );
    }

    private static function authenticateBearerToken(string $rawToken): void
    {
        $hash = hash('sha256', $rawToken);
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM sys_api_tokens WHERE token_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['is_active'])) {
            ApiResponse::error('INVALID_TOKEN', 'API token is invalid or has been revoked.', 401);
        }

        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
            ApiResponse::error('TOKEN_EXPIRED', 'API token has expired.', 401);
        }

        $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        if (!self::isIpAllowed($clientIp, (string)($row['allowed_ips'] ?? ''))) {
            ApiResponse::error('IP_NOT_ALLOWED', "Client IP ({$clientIp}) is not in the token's allowlist.", 403);
        }

        $rateLimit = max(10, (int)($row['rate_limit_per_min'] ?? 120));
        self::enforceRateLimit((int)$row['id'], $rateLimit);

        // Throttled update of last_used_at (at most once every 30 seconds)
        $lastUsed = !empty($row['last_used_at']) ? strtotime((string)$row['last_used_at']) : 0;
        if ((time() - $lastUsed) >= 30 || ($row['last_used_ip'] ?? '') !== $clientIp) {
            try {
                $upd = $db->prepare('UPDATE sys_api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?');
                $upd->execute([$clientIp, $row['id']]);
            } catch (\Throwable $e) {
                // Non-blocking telemetry
            }
        }

        self::$tokenRow = $row;
        $decodedScopes = json_decode((string)($row['scopes_json'] ?? '[]'), true);
        self::$scopes = is_array($decodedScopes) ? array_values(array_map('strval', $decodedScopes)) : [];

        // Populate in-memory request context so existing services work without cookies
        self::$internalCsrf = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = self::$internalCsrf;
        $_SESSION['user_id'] = !empty($row['created_by']) ? (int)$row['created_by'] : null;
        $_SESSION['user_role'] = 'admin';

        if (!empty($row['plugin_slug'])) {
            $GLOBALS['AIPBX_AUDIT_ACTOR'] = '[Plugin: ' . $row['plugin_slug'] . ']';
        } else {
            $GLOBALS['AIPBX_AUDIT_ACTOR'] = '[API: ' . $row['name'] . ']';
        }
    }

    /**
     * Enforce that the current token holds the required scope.
     * Note: `<domain>:write` automatically grants `<domain>:read`, and `*` grants all scopes.
     */
    public static function requireScope(string $requiredScope): void
    {
        if (self::hasScope($requiredScope)) {
            return;
        }
        ApiResponse::error(
            'FORBIDDEN_SCOPE',
            "API token lacks the required scope: {$requiredScope}",
            403,
            ['required_scope' => $requiredScope, 'granted_scopes' => self::$scopes]
        );
    }

    public static function hasScope(string $requiredScope): bool
    {
        if (in_array('*', self::$scopes, true) || in_array($requiredScope, self::$scopes, true)) {
            return true;
        }
        if (str_contains($requiredScope, ':')) {
            [$domain, $action] = explode(':', $requiredScope, 2);
            if (in_array($domain . ':*', self::$scopes, true)) {
                return true;
            }
            if ($action === 'read' && in_array($domain . ':write', self::$scopes, true)) {
                return true;
            }
        }
        return false;
    }

    public static function csrfToken(): string
    {
        if (self::$internalCsrf === '') {
            self::$internalCsrf = getCSRFToken();
        }
        return self::$internalCsrf;
    }

    /**
     * Parse JSON request body (or form-encoded fallback) and inject internal CSRF token.
     *
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $data = [];
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                ApiResponse::error('INVALID_JSON', 'Request body must be a valid JSON object.', 400);
            }
            $data = $decoded;
        } elseif (!empty($_POST)) {
            $data = $_POST;
        }
        $data['csrf_token'] = self::csrfToken();
        return $data;
    }

    /**
     * Parse `page` and `per_page` query parameters.
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    public static function pagination(int $defaultPerPage = 50, int $maxPerPage = 200): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min($maxPerPage, (int)($_GET['per_page'] ?? $defaultPerPage)));
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function tokenRow(): ?array
    {
        return self::$tokenRow;
    }

    public static function isIpAllowed(string $clientIp, string $allowlist): bool
    {
        $allowlist = trim($allowlist);
        if ($allowlist === '' || $allowlist === '*') {
            return true;
        }
        $entries = array_filter(array_map('trim', preg_split('/[\s,]+/', $allowlist) ?: []));
        if (empty($entries)) {
            return true;
        }
        $clientBin = @inet_pton($clientIp);
        foreach ($entries as $entry) {
            if ($entry === $clientIp) {
                return true;
            }
            if (str_contains($entry, '/') && $clientBin !== false) {
                [$subnet, $bits] = explode('/', $entry, 2);
                $subBin = @inet_pton(trim($subnet));
                if ($subBin === false || strlen($subBin) !== strlen($clientBin)) {
                    continue;
                }
                $maxBits = strlen($clientBin) * 8;
                $bits = (int)$bits;
                if ($bits < 0 || $bits > $maxBits) {
                    continue;
                }
                $fullBytes = intdiv($bits, 8);
                $remBits = $bits % 8;
                if ($fullBytes > 0 && substr($clientBin, 0, $fullBytes) !== substr($subBin, 0, $fullBytes)) {
                    continue;
                }
                if ($remBits > 0) {
                    $mask = (0xFF << (8 - $remBits)) & 0xFF;
                    if ((ord($clientBin[$fullBytes]) & $mask) !== (ord($subBin[$fullBytes]) & $mask)) {
                        continue;
                    }
                }
                return true;
            }
        }
        return false;
    }

    private static function enforceRateLimit(int $tokenId, int $limitPerMin): void
    {
        $window = (int)floor(time() / 60);
        $resetAt = ($window + 1) * 60;
        $dir = is_dir('/var/lib/aipbx') ? '/var/lib/aipbx/api-ratelimit' : sys_get_temp_dir() . '/aipbx_api_ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        // Single per-token counter file storing "<window>:<count>" so files never accumulate per minute
        $file = $dir . '/tok_' . $tokenId . '.cnt';
        $count = 1;
        $fp = @fopen($file, 'c+');
        if ($fp !== false) {
            if (flock($fp, LOCK_EX)) {
                $raw = trim((string)stream_get_contents($fp));
                $parts = explode(':', $raw, 2);
                $storedWin = (int)$parts[0];
                $storedCnt = isset($parts[1]) ? (int)$parts[1] : 0;
                $count = ($storedWin === $window) ? ($storedCnt + 1) : 1;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $window . ':' . $count);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        $remaining = max(0, $limitPerMin - $count);
        header('X-RateLimit-Limit: ' . $limitPerMin);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $resetAt);

        if ($count > $limitPerMin) {
            $retryAfter = max(1, $resetAt - time());
            header('Retry-After: ' . $retryAfter);
            ApiResponse::error(
                'RATE_LIMIT_EXCEEDED',
                "Rate limit of {$limitPerMin} requests per minute exceeded. Retry in {$retryAfter}s.",
                429
            );
        }
    }
}
