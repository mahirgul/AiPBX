<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/core/ApiResponse.php';
require_once dirname(__DIR__) . '/core/ApiRequestContext.php';
require_once dirname(__DIR__) . '/core/ResourceNormalizer.php';
require_once dirname(__DIR__) . '/core/PluginManager.php';
require_once dirname(__DIR__) . '/services/WebhookService.php';
require_once dirname(__DIR__) . '/services/RingGroupService.php';
require_once dirname(__DIR__) . '/services/ConferenceService.php';
require_once dirname(__DIR__) . '/services/BossSecretaryService.php';
require_once dirname(__DIR__) . '/services/DialPermissionService.php';
require_once __DIR__ . '/api/ApiV1OpenApi.php';
require_once __DIR__ . '/api/ApiV1PbxHandlers.php';

/**
 * Full-Coverage REST API v1 Dispatcher (`/api/v1/*`).
 */
final class ApiV1Controller
{
    public static function serve(string $path): never
    {
        // CORS preflight support for API clients
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token');

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $rel = trim(substr($path, strlen('/api/v1')), '/');
        if ($rel === '' || $rel === 'openapi.json') {
            ApiV1OpenApi::serve();
        }

        // Authenticate Bearer token (or active admin session)
        ApiRequestContext::authenticate();

        $segments = array_values(array_filter(explode('/', $rel), fn($s) => $s !== ''));
        $domain = $segments[0] ?? '';
        $idOrSub = $segments[1] ?? null;
        $subAction = $segments[2] ?? null;
        $subId = $segments[3] ?? null;

        try {
            switch ($domain) {
                case 'system':
                    self::handleSystem($method, (string)$idOrSub);
                case 'users':
                    self::handleUsers($method, $idOrSub, $subAction);
                case 'roles':
                    self::handleRoles($method);
                case 'extensions':
                    ApiV1PbxHandlers::handleExtensions($method, $idOrSub);
                case 'trunks':
                    ApiV1PbxHandlers::handleTrunks($method, $idOrSub, $subAction);
                case 'did-routes':
                    ApiV1PbxHandlers::handleDidRoutes($method, $idOrSub, $subAction);
                case 'outbound-routes':
                    ApiV1PbxHandlers::handleOutboundRoutes($method, $idOrSub, $subAction);
                case 'ivrs':
                    ApiV1PbxHandlers::handleIvrs($method, $idOrSub, $subAction, $subId);
                case 'time-conditions':
                    ApiV1PbxHandlers::handleTimeConditions($method, $idOrSub, $subAction);
                case 'time-groups':
                    ApiV1PbxHandlers::handleTimeGroups($method, $idOrSub);
                case 'ring-groups':
                    ApiV1PbxHandlers::handleRingGroups($method, $idOrSub);
                case 'conferences':
                    ApiV1PbxHandlers::handleConferences($method, $idOrSub);
                case 'boss-secretary':
                    ApiV1PbxHandlers::handleBossSecretary($method, $idOrSub);
                case 'dial-permissions':
                    ApiV1PbxHandlers::handleDialPermissions($method, $idOrSub);
                case 'feature-codes':
                    ApiV1PbxHandlers::handleFeatureCodes($method, $idOrSub);
                case 'hangup-actions':
                    ApiV1PbxHandlers::handleHangupActions($method, $idOrSub);
                case 'queues':
                    ApiV1PbxHandlers::handleQueues($method, $idOrSub, $subAction);
                case 'calls':
                    ApiV1PbxHandlers::handleCalls($method, (string)$idOrSub);
                case 'cdr':
                    ApiV1PbxHandlers::handleCdr($method);
                case 'voicemails':
                    ApiV1PbxHandlers::handleVoicemails($method);
                case 'announcements':
                    self::handleAnnouncements($method, $idOrSub);
                case 'phones':
                    self::handlePhones($method, $idOrSub);
                case 'web-widgets':
                    self::handleWebWidgets($method, $idOrSub);
                case 'webhooks':
                    self::handleWebhooks($method, $idOrSub, $subAction);
                case 'plugins':
                    self::handlePlugins($method, $idOrSub, $subAction, $subId, $segments[4] ?? null);
                default:
                    ApiResponse::error('NOT_FOUND', "Unknown API v1 endpoint: /api/v1/{$rel}", 404);
            }
        } catch (\Throwable $e) {
            error_log('[ApiV1] ' . $e->getMessage());
            ApiResponse::error('SERVER_ERROR', 'Internal server error.', 500);
        }
    }

    // ------------------------------------------------------------------ System

    private static function handleSystem(string $method, string $sub): never
    {
        if ($sub === 'info' && $method === 'GET') {
            ApiRequestContext::requireScope('system:read');
            ApiResponse::success([
                'version'            => defined('AIPBX_VERSION') ? AIPBX_VERSION : 'dev',
                'site_title'         => getSystemSetting('site_title', 'AiPBX'),
                'php_version'        => PHP_VERSION,
                'timezone'           => date_default_timezone_get(),
                'server_time'        => date(DATE_ATOM),
                'pending_sync_count' => getPendingSyncCount(),
            ]);
        }

        if ($sub === 'health' && $method === 'GET') {
            ApiRequestContext::requireScope('system:read');
            require_once dirname(__DIR__) . '/services/HealthService.php';
            ApiResponse::success([
                'summary' => HealthService::summary(time()),
                'checks'  => HealthService::checks(),
            ]);
        }

        if ($sub === 'pending-sync' && $method === 'GET') {
            ApiRequestContext::requireScope('system:read');
            ApiResponse::success([
                'count'   => getPendingSyncCount(),
                'domains' => getPendingSyncList(),
            ]);
        }

        if ($sub === 'apply-sync' && $method === 'POST') {
            ApiRequestContext::requireScope('system:write');
            $res = applyPendingSync($_SESSION['user_id'] ?? null);
            $failed = array_filter($res, fn($r) => empty($r['success']));
            if (!empty($failed)) {
                ApiResponse::error('SYNC_FAILED', 'One or more Asterisk domains failed to apply and were rolled back.', 422, $res);
            }
            ApiResponse::success(['applied_domains' => $res]);
        }

        if ($sub === 'audit-log' && $method === 'GET') {
            ApiRequestContext::requireScope('system:read');
            $pg = ApiRequestContext::pagination(50, 200);
            $total = (int)DBHelper::fetchColumn('SELECT COUNT(*) FROM sys_audit_log');
            $rows = DBHelper::fetchAll(
                "SELECT * FROM sys_audit_log ORDER BY id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}"
            );
            ApiResponse::success($rows, [
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $total,
            ]);
        }

        if ($sub === 'settings' && $method === 'GET') {
            ApiRequestContext::requireScope('system:read');
            $allowedKeys = [
                'site_title', 'brand_title', 'brand_sub', 'site_logo_type',
                'site_logo_icon', 'site_logo_image', 'site_logo_image_dark',
                'site_logo_style', 'site_favicon_url', 'system_default_language',
                'pjsip_wss_port', 'pjsip_udp_port', 'pjsip_external_ip',
                'pjsip_local_net', 'pjsip_codecs', 'pjsip_wired_codecs',
                'pjsip_user_agent', 'pjsip_direct_media', 'pjsip_rtp_symmetric',
                'pjsip_qualify_frequency', 'pjsip_internal_dial_timeout',
                'pjsip_external_dial_timeout', 'rtp_start', 'rtp_end',
                'udptl_start', 'udptl_end', 'udptl_checksums',
                'google_oauth_enabled', 'teams_enabled', 'teams_domain',
            ];
            $placeholders = implode(',', array_fill(0, count($allowedKeys), '?'));
            $rows = DBHelper::fetchAll(
                "SELECT setting_key, setting_value FROM sys_settings
                 WHERE setting_key IN ({$placeholders})
                 ORDER BY setting_key ASC",
                $allowedKeys
            );
            $settings = [];
            foreach ($rows as $r) {
                $settings[$r['setting_key']] = $r['setting_value'];
            }
            ApiResponse::success($settings);
        }

        ApiResponse::error('NOT_FOUND', "Unknown system endpoint: /api/v1/system/{$sub}", 404);
    }

    // ------------------------------------------------------------------ Users & Roles

    private static function handleUsers(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('users:read');
            $pg = ApiRequestContext::pagination(50, 200);
            $where = '1=1';
            $params = [];
            if (!empty($_GET['role'])) {
                $where .= ' AND role = ?';
                $params[] = (string)$_GET['role'];
            }
            if (!empty($_GET['q'])) {
                $where .= ' AND (username LIKE ? OR full_name LIKE ? OR extension LIKE ? OR email LIKE ?)';
                $q = '%' . trim((string)$_GET['q']) . '%';
                array_push($params, $q, $q, $q, $q);
            }
            $total = (int)DBHelper::fetchColumn("SELECT COUNT(*) FROM sys_users WHERE {$where}", $params);
            $rows = DBHelper::fetchAll(
                "SELECT * FROM sys_users WHERE {$where} ORDER BY id ASC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
                $params
            );
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows), [
                'page'     => $pg['page'],
                'per_page' => $pg['per_page'],
                'total'    => $total,
            ]);
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('users:read');
            $row = DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [(int)$idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'User not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('users:write');
            $body = ApiRequestContext::jsonBody();
            $body['user_id'] = 0;
            $res = UserService::saveUser($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null, 201);
        }

        if ($method === 'POST' && $subAction === 'toggle') {
            ApiRequestContext::requireScope('users:write');
            $res = PBXHelper::toggleStatus('sys_users', (int)$idStr, ApiRequestContext::csrfToken());
            $saved = DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('users:write');
            $existing = DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'User not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('user', $existing, ApiRequestContext::jsonBody());
            $res = UserService::saveUser($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('users:write');
            $res = UserService::deleteUser((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', "Unsupported method {$method} for /api/v1/users", 405);
    }

    private static function handleRoles(string $method): never
    {
        if ($method === 'GET') {
            ApiRequestContext::requireScope('users:read');
            require_once dirname(__DIR__) . '/repositories/RoleRepository.php';
            ApiResponse::success([
                'roles'       => RoleRepository::allWithUserCount(),
                'permissions' => RoleRepository::allPermissionsMap(),
            ]);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/roles', 405);
    }

    // ------------------------------------------------------------------ Announcements & Phones

    private static function handleAnnouncements(string $method, ?string $idStr): never
    {
        if ($method === 'GET') {
            ApiRequestContext::requireScope('sounds:read');
            ApiResponse::success(DBHelper::fetchAll('SELECT * FROM pbx_announcements ORDER BY id ASC'));
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('sounds:write');
            $body = ApiRequestContext::jsonBody();
            $body['announcement_id'] = 0;
            $res = SoundService::saveAnnouncement($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('sounds:write');
            $res = SoundService::deleteAnnouncement((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/announcements', 405);
    }

    private static function handlePhones(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('devices:read');
            ApiResponse::success(ResourceNormalizer::sanitizeList(DBHelper::fetchAll('SELECT * FROM pbx_phones ORDER BY id ASC')));
        }
        if ($method === 'GET') {
            ApiRequestContext::requireScope('devices:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_phones WHERE id = ? OR mac = ? LIMIT 1', [(int)$idStr, strtolower($idStr)]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Phone device not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/phones', 405);
    }

    // ------------------------------------------------------------------ Webhooks

    private static function handleWebhooks(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('system:read');
            ApiResponse::success([
                'webhooks'         => WebhookService::all(),
                'supported_events' => WebhookService::SUPPORTED_EVENTS,
            ]);
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('system:write');
            $res = WebhookService::save(ApiRequestContext::jsonBody());
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if ($method === 'POST' && $subAction === 'test') {
            ApiRequestContext::requireScope('system:write');
            $res = WebhookService::sendTestPing((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('system:write');
            $res = WebhookService::delete((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/webhooks', 405);
    }

    // ------------------------------------------------------------------ Plugins

    private static function handlePlugins(string $method, ?string $slug, ?string $subAction, ?string $collection, ?string $itemKey): never
    {
        if ($method === 'GET' && $slug === null) {
            ApiRequestContext::requireScope('system:read');
            ApiResponse::success(PluginManager::allPlugins());
        }

        if ($slug === null) {
            ApiResponse::error('NOT_FOUND', 'Plugin slug is required.', 404);
        }

        if ($subAction === null && $method === 'GET') {
            if (!ApiRequestContext::hasScope('plugin:' . $slug)) {
                ApiRequestContext::requireScope('system:read');
            }
            $p = PluginManager::getPlugin($slug);
            if (!$p) {
                ApiResponse::error('NOT_FOUND', "Plugin '{$slug}' not found.", 404);
            }
            ApiResponse::success($p);
        }

        if ($subAction === 'enable' && $method === 'POST') {
            ApiRequestContext::requireScope('system:write');
            $res = PluginManager::enable($slug, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res, PluginManager::getPlugin($slug));
        }

        if ($subAction === 'disable' && $method === 'POST') {
            ApiRequestContext::requireScope('system:write');
            $res = PluginManager::disable($slug, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res, PluginManager::getPlugin($slug));
        }

        if ($subAction === 'settings') {
            $hasPluginScope = ApiRequestContext::hasScope('plugin:' . $slug);
            if ($method === 'GET') {
                if (!$hasPluginScope) {
                    ApiRequestContext::requireScope('system:read');
                }
                ApiResponse::success(PluginManager::getAllSettings($slug, !$hasPluginScope));
            }
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                if (!$hasPluginScope) {
                    ApiRequestContext::requireScope('system:write');
                }
                $res = PluginManager::savePluginSettings($slug, ApiRequestContext::jsonBody(), ApiRequestContext::csrfToken());
                ApiResponse::fromServiceResult($res, PluginManager::getAllSettings($slug, true));
            }
        }

        if ($subAction === 'kv' && $collection !== null) {
            $hasPluginScope = ApiRequestContext::hasScope('plugin:' . $slug);
            if ($method === 'GET' && $itemKey === null) {
                if (!$hasPluginScope) {
                    ApiRequestContext::requireScope('system:read');
                }
                ApiResponse::success(PluginManager::kvList($slug, $collection));
            }
            if ($method === 'GET') {
                if (!$hasPluginScope) {
                    ApiRequestContext::requireScope('system:read');
                }
                $item = PluginManager::kvGet($slug, $collection, $itemKey);
                if (!$item) {
                    ApiResponse::error('NOT_FOUND', 'KV item not found.', 404);
                }
                ApiResponse::success($item);
            }
            if (in_array($method, ['PUT', 'POST'], true) && $itemKey !== null) {
                if (!$hasPluginScope) {
                    ApiRequestContext::requireScope('system:write');
                }
                $body = ApiRequestContext::jsonBody();
                unset($body['csrf_token']);
                $value = $body['value'] ?? $body;
                PluginManager::kvSet($slug, $collection, $itemKey, $value);
                ApiResponse::success(PluginManager::kvGet($slug, $collection, $itemKey));
            }
            if ($method === 'DELETE' && $itemKey !== null) {
                if (!$hasPluginScope) {
                    ApiRequestContext::requireScope('system:write');
                }
                PluginManager::kvDelete($slug, $collection, $itemKey);
                ApiResponse::success(['deleted' => true, 'key' => $itemKey]);
            }
        }

        ApiResponse::error('NOT_FOUND', "Unknown plugin endpoint for '{$slug}'.", 404);
    }

    // ------------------------------------------------------------------ Web Widgets

    private static function handleWebWidgets(string $method, ?string $idStr): never
    {
        require_once dirname(__DIR__) . '/services/WebWidgetService.php';
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $widgets = WebWidgetService::listWidgets();
            foreach ($widgets as &$w) {
                $w['token']  = (string)($w['public_id'] ?? '');
                $w['number'] = (string)($w['dest_value'] ?? '');
                $w['embed']  = WebWidgetService::embedCode($w);
                unset($w['sip_secret']);
            }
            unset($w);
            ApiResponse::success($widgets);
        }
        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $w = WebWidgetService::getWidget((int)$idStr);
            if (!$w) {
                ApiResponse::error('NOT_FOUND', 'Widget not found.', 404);
            }
            $w['token']  = (string)($w['public_id'] ?? '');
            $w['number'] = (string)($w['dest_value'] ?? '');
            $w['embed']  = WebWidgetService::embedCode($w);
            unset($w['sip_secret']);
            ApiResponse::success($w);
        }
        if ($method === 'POST') {
            ApiRequestContext::requireScope('pbx:write');
            $data = ApiRequestContext::jsonBody();
            $data['csrf_token'] = ApiRequestContext::csrfToken();
            $res = WebWidgetService::saveWidget($data);
            ApiResponse::fromServiceResult($res);
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = WebWidgetService::deleteWidget((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/web-widgets', 405);
    }

    /**
     * Execute a loopback `/api/v1/*` request on behalf of an enabled plugin or admin assistant.
     *
     * @param array<string, mixed> $body
     * @return array{status_code: int, response: array<string, mixed>}
     */
    public static function invokeLoopback(string $method, string $path, array $body = [], string $pluginSlug = 'ai-assistant'): array
    {
        if (!PluginManager::isEnabled($pluginSlug)) {
            return [
                'status_code' => 403,
                'response'    => ['success' => false, 'error' => "Plugin '{$pluginSlug}' is disabled."],
            ];
        }

        $token = PluginManager::getSetting($pluginSlug, '_worker_api_token', '');
        if ($token === '') {
            $pluginMeta = PluginManager::getPlugin($pluginSlug);
            $declaredScopes = is_array($pluginMeta['scopes'] ?? null) && !empty($pluginMeta['scopes'])
                ? $pluginMeta['scopes']
                : ['pbx:read'];
            $token = ApiTokenService::issuePluginToken($pluginSlug, (string)($pluginMeta['name'] ?? $pluginSlug), $declaredScopes);
            PluginManager::setSetting($pluginSlug, '_worker_api_token', $token, true);
        }

        $url = 'https://127.0.0.1' . (str_starts_with($path, '/') ? $path : '/' . $path);
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status_code' => 500, 'response' => ['success' => false, 'error' => 'cURL init failed']];
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true) && !empty($body)) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'status_code' => $code > 0 ? $code : 500,
            'response'    => is_array($decoded) ? $decoded : ['raw' => (string)$raw],
        ];
    }
}
