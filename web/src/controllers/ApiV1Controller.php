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
            self::serveOpenApiSpec();
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
                    self::handleExtensions($method, $idOrSub);
                case 'trunks':
                    self::handleTrunks($method, $idOrSub, $subAction);
                case 'did-routes':
                    self::handleDidRoutes($method, $idOrSub, $subAction);
                case 'outbound-routes':
                    self::handleOutboundRoutes($method, $idOrSub, $subAction);
                case 'ivrs':
                    self::handleIvrs($method, $idOrSub, $subAction, $subId);
                case 'time-conditions':
                    self::handleTimeConditions($method, $idOrSub, $subAction);
                case 'time-groups':
                    self::handleTimeGroups($method, $idOrSub);
                case 'ring-groups':
                    self::handleRingGroups($method, $idOrSub);
                case 'conferences':
                    self::handleConferences($method, $idOrSub);
                case 'boss-secretary':
                    self::handleBossSecretary($method, $idOrSub);
                case 'dial-permissions':
                    self::handleDialPermissions($method, $idOrSub);
                case 'feature-codes':
                    self::handleFeatureCodes($method, $idOrSub);
                case 'hangup-actions':
                    self::handleHangupActions($method, $idOrSub);
                case 'queues':
                    self::handleQueues($method, $idOrSub, $subAction);
                case 'calls':
                    self::handleCalls($method, (string)$idOrSub);
                case 'cdr':
                    self::handleCdr($method);
                case 'voicemails':
                    self::handleVoicemails($method);
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

    // ------------------------------------------------------------------ Extensions

    private static function handleExtensions(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $rows = DBHelper::fetchAll(
                "SELECT id, username, full_name, email, role, extension, extension_type,
                        outbound_group, permission_group_id, boss_secretary_group_id, boss_secretary_role,
                        voicemail_enabled, voicemail_email, voicemail_email_notify,
                        voicemail_attach_audio, vm_on_noanswer, vm_on_busy, vm_on_unavail, vm_always,
                        cid_internal, cid_external, is_active
                 FROM sys_users
                 WHERE extension IS NOT NULL AND extension != ''
                 ORDER BY CAST(extension AS UNSIGNED) ASC, extension ASC"
            );
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows));
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = DBHelper::fetchOne(
                "SELECT * FROM sys_users WHERE (id = ? OR extension = ?) AND extension IS NOT NULL AND extension != '' LIMIT 1",
                [(int)$idStr, $idStr]
            );
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Extension not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['user_id'] = (int)($body['user_id'] ?? 0);
            if (!isset($body['is_active'])) {
                $body['is_active'] = 1;
            }
            $res = ExtensionService::saveExtension($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null, 201);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne(
                "SELECT * FROM sys_users WHERE (id = ? OR extension = ?) LIMIT 1",
                [(int)$idStr, $idStr]
            );
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Extension not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('extension', $existing, ApiRequestContext::jsonBody());
            $res = ExtensionService::saveExtension($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM sys_users WHERE id = ?', [(int)$existing['id']]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne(
                "SELECT id FROM sys_users WHERE (id = ? OR extension = ?) LIMIT 1",
                [(int)$idStr, $idStr]
            );
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Extension not found.', 404);
            }
            $res = ExtensionService::removeExtension((int)$existing['id'], ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/extensions', 405);
    }

    // ------------------------------------------------------------------ Trunks

    private static function handleTrunks(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $rows = DBHelper::fetchAll('SELECT * FROM pbx_trunks ORDER BY sort_order ASC, id ASC');
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows));
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_trunks WHERE id = ?', [(int)$idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Trunk not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['trunk_id'] = 0;
            $res = TrunkService::saveTrunk($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM pbx_trunks WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null, 201);
        }

        if ($method === 'POST' && $subAction === 'toggle') {
            ApiRequestContext::requireScope('pbx:write');
            $res = PBXHelper::toggleStatus('pbx_trunks', (int)$idStr, ApiRequestContext::csrfToken());
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_trunks WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_trunks WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Trunk not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('trunk', $existing, ApiRequestContext::jsonBody());
            $res = TrunkService::saveTrunk($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_trunks WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = TrunkService::deleteTrunk((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/trunks', 405);
    }

    // ------------------------------------------------------------------ DID Routes

    private static function handleDidRoutes(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $rows = DBHelper::fetchAll('SELECT * FROM pbx_dids ORDER BY sort_order ASC, id ASC');
            ApiResponse::success($rows);
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_dids WHERE id = ?', [(int)$idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Inbound route not found.', 404);
            }
            ApiResponse::success($row);
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['route_id'] = 0;
            $res = RouteService::saveDIDRoute($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM pbx_dids WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved, 201);
        }

        if ($method === 'POST' && $subAction === 'toggle') {
            ApiRequestContext::requireScope('pbx:write');
            $res = PBXHelper::toggleStatus('pbx_dids', (int)$idStr, ApiRequestContext::csrfToken());
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_dids WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_dids WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Inbound route not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('did_route', $existing, ApiRequestContext::jsonBody());
            $res = RouteService::saveDIDRoute($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_dids WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = RouteService::deleteDIDRoute((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/did-routes', 405);
    }

    // ------------------------------------------------------------------ Outbound Routes

    private static function handleOutboundRoutes(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $rows = DBHelper::fetchAll('SELECT * FROM pbx_outbound_routes ORDER BY sort_order ASC, id ASC');
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows));
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_outbound_routes WHERE id = ?', [(int)$idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Outbound route not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $payload = ResourceNormalizer::mergeForService('outbound_route', [], ApiRequestContext::jsonBody());
            $payload['route_id'] = 0;
            $res = RouteService::saveOutboundRoute($payload);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM pbx_outbound_routes WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null, 201);
        }

        if ($method === 'POST' && $subAction === 'toggle') {
            ApiRequestContext::requireScope('pbx:write');
            $res = PBXHelper::toggleStatus('pbx_outbound_routes', (int)$idStr, ApiRequestContext::csrfToken());
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_outbound_routes WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_outbound_routes WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Outbound route not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('outbound_route', $existing, ApiRequestContext::jsonBody());
            $res = RouteService::saveOutboundRoute($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_outbound_routes WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = RouteService::deleteOutboundRoute((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/outbound-routes', 405);
    }

    // ------------------------------------------------------------------ IVRs

    private static function handleIvrs(string $method, ?string $idStr, ?string $subAction, ?string $subId): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $ivrs = DBHelper::fetchAll('SELECT * FROM pbx_ivrs ORDER BY id ASC');
            $entries = DBHelper::fetchAll('SELECT * FROM pbx_ivr_entries ORDER BY ivr_id ASC, digit ASC');
            $byIvr = [];
            foreach ($entries as $e) {
                $byIvr[(int)$e['ivr_id']][] = $e;
            }
            foreach ($ivrs as &$ivr) {
                $ivr['entries'] = $byIvr[(int)$ivr['id']] ?? [];
            }
            ApiResponse::success($ivrs);
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $ivr = DBHelper::fetchOne('SELECT * FROM pbx_ivrs WHERE id = ?', [(int)$idStr]);
            if (!$ivr) {
                ApiResponse::error('NOT_FOUND', 'IVR menu not found.', 404);
            }
            $ivr['entries'] = DBHelper::fetchAll('SELECT * FROM pbx_ivr_entries WHERE ivr_id = ? ORDER BY digit ASC', [(int)$idStr]);
            ApiResponse::success($ivr);
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['ivr_id'] = 0;
            $res = IVRService::saveIVR($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM pbx_ivrs WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved, 201);
        }

        if ($method === 'POST' && $subAction === 'entries') {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['ivr_id'] = (int)$idStr;
            $res = IVRService::saveIVREntry($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }

        if ($method === 'DELETE' && $idStr !== null && $subAction === 'entries' && $subId !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = IVRService::deleteIVREntry((int)$subId, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_ivrs WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'IVR menu not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('ivr', $existing, ApiRequestContext::jsonBody());
            $res = IVRService::saveIVR($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_ivrs WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = IVRService::deleteIVR((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/ivrs', 405);
    }

    // ------------------------------------------------------------------ Time Conditions & Groups

    private static function handleTimeConditions(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $rows = DBHelper::fetchAll('SELECT * FROM pbx_time_conditions ORDER BY id ASC');
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows));
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_time_conditions WHERE id = ?', [(int)$idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Time condition not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['tc_id'] = 0;
            $res = TimeConditionService::saveTimeCondition($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM pbx_time_conditions WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null, 201);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_time_conditions WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Time condition not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('time_condition', $existing, ApiRequestContext::jsonBody());
            $res = TimeConditionService::saveTimeCondition($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_time_conditions WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = TimeConditionService::deleteTimeCondition((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/time-conditions', 405);
    }

    private static function handleTimeGroups(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            $rows = DBHelper::fetchAll('SELECT * FROM pbx_time_groups ORDER BY id ASC');
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows));
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_time_groups WHERE id = ?', [(int)$idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Time group not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['tg_id'] = 0;
            $res = TimeConditionService::saveTimeGroup($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_time_groups WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Time group not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('time_group', $existing, ApiRequestContext::jsonBody());
            $res = TimeConditionService::saveTimeGroup($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_time_groups WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = TimeConditionService::deleteTimeGroup((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/time-groups', 405);
    }

    // ------------------------------------------------------------------ Ring Groups, Conferences, Boss-Secretary, Dial Permissions

    private static function handleRingGroups(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            ApiResponse::success(RingGroupService::getRingGroups());
        }
        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = RingGroupService::getRingGroup((int)$idStr);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Ring group not found.', 404);
            }
            ApiResponse::success($row);
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $payload = ResourceNormalizer::mergeForService('ring_group', [], ApiRequestContext::jsonBody());
            $payload['id'] = 0;
            $res = RingGroupService::saveRingGroup($payload);
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = RingGroupService::getRingGroup((int)$idStr);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Ring group not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('ring_group', $existing, ApiRequestContext::jsonBody());
            $res = RingGroupService::saveRingGroup($payload);
            ApiResponse::fromServiceResult($res, RingGroupService::getRingGroup((int)$idStr));
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = RingGroupService::deleteRingGroup((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/ring-groups', 405);
    }

    private static function handleConferences(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            ApiResponse::success(ConferenceService::getConferences());
        }
        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            $row = ConferenceService::getConference((int)$idStr);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Conference room not found.', 404);
            }
            ApiResponse::success($row);
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['id'] = 0;
            $res = ConferenceService::saveConference($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = ConferenceService::getConference((int)$idStr);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Conference room not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('conference', $existing, ApiRequestContext::jsonBody());
            $res = ConferenceService::saveConference($payload);
            ApiResponse::fromServiceResult($res, ConferenceService::getConference((int)$idStr));
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = ConferenceService::deleteConference((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/conferences', 405);
    }

    private static function handleBossSecretary(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            ApiResponse::success(BossSecretaryService::getGroups());
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['id'] = 0;
            $res = BossSecretaryService::saveGroup($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = BossSecretaryService::getGroup((int)$idStr);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Boss-Secretary group not found.', 404);
            }
            $payload = array_merge($existing, ApiRequestContext::jsonBody());
            $res = BossSecretaryService::saveGroup($payload);
            ApiResponse::fromServiceResult($res, BossSecretaryService::getGroup((int)$idStr));
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = BossSecretaryService::deleteGroup((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/boss-secretary', 405);
    }

    private static function handleDialPermissions(string $method, ?string $idStr): never
    {
        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:read');
            ApiResponse::success(ResourceNormalizer::sanitizeList(DialPermissionService::getGroups()));
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['id'] = 0;
            $res = DialPermissionService::saveGroup($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DialPermissionService::getGroup((int)$idStr);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Dial permission group not found.', 404);
            }
            $payload = array_merge($existing, ApiRequestContext::jsonBody());
            $res = DialPermissionService::saveGroup($payload);
            ApiResponse::fromServiceResult($res, DialPermissionService::getGroup((int)$idStr));
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = DialPermissionService::deleteGroup((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/dial-permissions', 405);
    }

    private static function handleFeatureCodes(string $method, ?string $idStr): never
    {
        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            ApiResponse::success(DBHelper::fetchAll('SELECT * FROM pbx_feature_codes ORDER BY id ASC'));
        }
        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_feature_codes WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Feature code not found.', 404);
            }
            $validRoles = array_column(DBHelper::fetchAll('SELECT role_key FROM sys_roles'), 'role_key');
            $payload = ResourceNormalizer::mergeForService('feature_code', $existing, ApiRequestContext::jsonBody());
            $res = FeatureCodeService::saveFeatureCode($payload, $validRoles);
            ApiResponse::fromServiceResult($res, DBHelper::fetchOne('SELECT * FROM pbx_feature_codes WHERE id = ?', [(int)$idStr]));
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/feature-codes', 405);
    }

    private static function handleHangupActions(string $method, ?string $idStr): never
    {
        if ($method === 'GET') {
            ApiRequestContext::requireScope('pbx:read');
            ApiResponse::success(DBHelper::fetchAll('SELECT * FROM pbx_hangup_actions ORDER BY id ASC'));
        }
        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('pbx:write');
            $body = ApiRequestContext::jsonBody();
            $body['action_id'] = 0;
            $res = HangupActionService::saveHangupAction($body);
            ApiResponse::fromServiceResult($res, null, 201);
        }
        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_hangup_actions WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Hangup action not found.', 404);
            }
            $payload = array_merge($existing, ApiRequestContext::jsonBody());
            $payload['action_id'] = (int)$idStr;
            $res = HangupActionService::saveHangupAction($payload);
            ApiResponse::fromServiceResult($res, DBHelper::fetchOne('SELECT * FROM pbx_hangup_actions WHERE id = ?', [(int)$idStr]));
        }
        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('pbx:write');
            $res = HangupActionService::deleteHangupAction((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }
        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/hangup-actions', 405);
    }

    // ------------------------------------------------------------------ Queues

    private static function handleQueues(string $method, ?string $idStr, ?string $subAction): never
    {
        if ($method === 'GET' && $idStr === 'live') {
            ApiRequestContext::requireScope('queues:read');
            $queues = ResourceNormalizer::sanitizeList(DBHelper::fetchAll('SELECT * FROM pbx_queues WHERE is_active = 1 ORDER BY id ASC'));
            $cliRes = AsteriskHelper::execCLI('queue show');
            $cliRaw = trim((string)($cliRes['output'] ?? ''));
            ApiResponse::success([
                'queues'         => $queues,
                'asterisk_state' => $cliRaw,
            ]);
        }

        if ($method === 'GET' && $idStr === null) {
            ApiRequestContext::requireScope('queues:read');
            $rows = DBHelper::fetchAll('SELECT * FROM pbx_queues ORDER BY id ASC');
            ApiResponse::success(ResourceNormalizer::sanitizeList($rows));
        }

        if ($method === 'GET') {
            ApiRequestContext::requireScope('queues:read');
            $row = DBHelper::fetchOne('SELECT * FROM pbx_queues WHERE id = ? OR queue_name = ? LIMIT 1', [(int)$idStr, $idStr]);
            if (!$row) {
                ApiResponse::error('NOT_FOUND', 'Queue not found.', 404);
            }
            ApiResponse::success(ResourceNormalizer::sanitizeRow($row));
        }

        if ($method === 'POST' && $idStr === null) {
            ApiRequestContext::requireScope('queues:write');
            $body = ApiRequestContext::jsonBody();
            $body['queue_id'] = 0;
            $res = QueueService::saveQueue($body);
            $saved = !empty($res['success'])
                ? DBHelper::fetchOne('SELECT * FROM pbx_queues WHERE id = ?', [DBHelper::lastSavedId()])
                : null;
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null, 201);
        }

        if ($method === 'POST' && $subAction === 'toggle') {
            ApiRequestContext::requireScope('queues:write');
            $res = PBXHelper::toggleStatus('pbx_queues', (int)$idStr, ApiRequestContext::csrfToken());
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_queues WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if (in_array($method, ['PUT', 'PATCH'], true) && $idStr !== null) {
            ApiRequestContext::requireScope('queues:write');
            $existing = DBHelper::fetchOne('SELECT * FROM pbx_queues WHERE id = ?', [(int)$idStr]);
            if (!$existing) {
                ApiResponse::error('NOT_FOUND', 'Queue not found.', 404);
            }
            $payload = ResourceNormalizer::mergeForService('queue', $existing, ApiRequestContext::jsonBody());
            $res = QueueService::saveQueue($payload);
            $saved = DBHelper::fetchOne('SELECT * FROM pbx_queues WHERE id = ?', [(int)$idStr]);
            ApiResponse::fromServiceResult($res, $saved ? ResourceNormalizer::sanitizeRow($saved) : null);
        }

        if ($method === 'DELETE' && $idStr !== null) {
            ApiRequestContext::requireScope('queues:write');
            $res = QueueService::deleteQueue((int)$idStr, ApiRequestContext::csrfToken());
            ApiResponse::fromServiceResult($res);
        }

        ApiResponse::error('METHOD_NOT_ALLOWED', 'Unsupported method for /api/v1/queues', 405);
    }

    // ------------------------------------------------------------------ Calls, CDR & Voicemail

    private static function handleCalls(string $method, string $sub): never
    {
        if ($sub === 'active' && $method === 'GET') {
            ApiRequestContext::requireScope('calls:read');
            $rawRes = AsteriskHelper::execCLI('core show channels concise');
            $raw = (string)($rawRes['output'] ?? '');
            $channels = [];
            foreach (explode("\n", trim($raw)) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, '!')) {
                    continue;
                }
                $parts = explode('!', $line);
                $channels[] = [
                    'channel'     => $parts[0],
                    'context'     => $parts[1],
                    'extension'   => $parts[2] ?? '',
                    'state'       => $parts[4] ?? '',
                    'application' => $parts[5] ?? '',
                    'caller_id'   => $parts[7] ?? '',
                    'duration'    => (int)($parts[11] ?? 0),
                    'bridged_to'  => $parts[12] ?? '',
                    'uniqueid'    => $parts[13] ?? '',
                ];
            }
            ApiResponse::success($channels);
        }

        if ($sub === 'originate' && $method === 'POST') {
            ApiRequestContext::requireScope('calls:write');
            $body = ApiRequestContext::jsonBody();
            $fromExt = preg_replace('/[^0-9]/', '', (string)($body['from_extension'] ?? ''));
            $toNumber = preg_replace('/[^0-9+*#]/', '', (string)($body['to_number'] ?? ''));
            if ($fromExt === '' || $toNumber === '') {
                ApiResponse::error('VALIDATION_ERROR', 'Both from_extension and to_number are required.', 422);
            }
            $callerId = trim((string)($body['caller_id'] ?? "ClickToCall <{$fromExt}>"));
            $cmd = sprintf('channel originate Local/%s@from-internal extension %s@from-internal', $fromExt, $toNumber);
            $out = AsteriskHelper::execCLI($cmd);
            WebhookService::emit('call.started', [
                'source'         => 'api_originate',
                'from_extension' => $fromExt,
                'to_number'      => $toNumber,
                'caller_id'      => $callerId,
            ]);
            ApiResponse::success([
                'from_extension' => $fromExt,
                'to_number'      => $toNumber,
                'asterisk_reply' => trim((string)($out['output'] ?? '')),
            ]);
        }

        if ($sub === 'hangup' && $method === 'POST') {
            ApiRequestContext::requireScope('calls:write');
            $body = ApiRequestContext::jsonBody();
            $channel = preg_replace('/[^A-Za-z0-9_\/.-]/', '', (string)($body['channel'] ?? ''));
            if ($channel === '') {
                ApiResponse::error('VALIDATION_ERROR', 'Channel identifier is required.', 422);
            }
            $out = AsteriskHelper::execCLI('channel request hangup ' . $channel);
            ApiResponse::success(['channel' => $channel, 'asterisk_reply' => trim((string)($out['output'] ?? ''))]);
        }

        ApiResponse::error('NOT_FOUND', "Unknown calls endpoint: /api/v1/calls/{$sub}", 404);
    }

    private static function handleCdr(string $method): never
    {
        if ($method !== 'GET') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'Only GET is supported for /api/v1/cdr', 405);
        }
        ApiRequestContext::requireScope('calls:read');
        $pg = ApiRequestContext::pagination(50, 200);
        $where = '1=1';
        $params = [];

        if (!empty($_GET['date_from'])) {
            $where .= ' AND calldate >= ?';
            $params[] = trim((string)$_GET['date_from']) . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where .= ' AND calldate <= ?';
            $params[] = trim((string)$_GET['date_to']) . ' 23:59:59';
        }
        if (!empty($_GET['src'])) {
            $where .= ' AND src LIKE ?';
            $params[] = '%' . trim((string)$_GET['src']) . '%';
        }
        if (!empty($_GET['dst'])) {
            $where .= ' AND dst LIKE ?';
            $params[] = '%' . trim((string)$_GET['dst']) . '%';
        }
        if (!empty($_GET['disposition'])) {
            $where .= ' AND disposition = ?';
            $params[] = trim((string)$_GET['disposition']);
        }

        $total = (int)DBHelper::fetchColumn("SELECT COUNT(*) FROM asteriskcdr WHERE {$where}", $params);
        $rows = DBHelper::fetchAll(
            "SELECT calldate, clid, src, dst, dcontext, channel, dstchannel, lastapp, duration, billsec, disposition, uniqueid, userfield AS recordingfile
             FROM asteriskcdr
             WHERE {$where}
             ORDER BY calldate DESC
             LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
            $params
        );

        ApiResponse::success($rows, [
            'page'     => $pg['page'],
            'per_page' => $pg['per_page'],
            'total'    => $total,
        ]);
    }

    private static function handleVoicemails(string $method): never
    {
        if ($method !== 'GET') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'Only GET is supported for /api/v1/voicemails', 405);
        }
        ApiRequestContext::requireScope('calls:read');
        require_once dirname(__DIR__) . '/services/VoicemailService.php';
        $ext = preg_replace('/[^0-9]/', '', (string)($_GET['extension'] ?? ''));
        if ($ext === '') {
            ApiResponse::error('VALIDATION_ERROR', 'Query parameter ?extension=<ext> is required.', 422);
        }
        ApiResponse::success([
            'extension' => $ext,
            'messages'  => VoicemailService::getVoicemailMessages($ext),
        ]);
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

    // ------------------------------------------------------------------ OpenAPI 3.1 Specification

    private static function serveOpenApiSpec(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'AiPBX REST API & Plugin Platform',
                'version'     => defined('AIPBX_VERSION') ? AIPBX_VERSION : '1.0.0',
                'description' => 'Full-coverage stateless REST API for managing AiPBX extensions, trunks, routing, queues, calls, CDRs, webhooks, and plugins.',
            ],
            'servers' => [
                ['url' => '/api/v1', 'description' => 'AiPBX REST API v1'],
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'apbx_<token>',
                    ],
                ],
            ],
            'security' => [['BearerAuth' => []]],
            'paths'    => [
                '/system/info'             => ['get' => ['summary' => 'Get PBX version, time, and pending sync status', 'tags' => ['System']]],
                '/system/health'           => ['get' => ['summary' => 'Get system health check results', 'tags' => ['System']]],
                '/system/pending-sync'     => ['get' => ['summary' => 'List pending Asterisk configuration changes', 'tags' => ['System']]],
                '/system/apply-sync'       => ['post' => ['summary' => 'Apply all pending Asterisk changes with automatic rollback', 'tags' => ['System']]],
                '/system/audit-log'        => ['get' => ['summary' => 'List audit log records', 'tags' => ['System']]],
                '/users'                   => ['get' => ['summary' => 'List portal users', 'tags' => ['Users']], 'post' => ['summary' => 'Create user', 'tags' => ['Users']]],
                '/users/{id}'              => ['get' => ['summary' => 'Get user', 'tags' => ['Users']], 'patch' => ['summary' => 'Update user', 'tags' => ['Users']], 'delete' => ['summary' => 'Delete user', 'tags' => ['Users']]],
                '/roles'                   => ['get' => ['summary' => 'List roles and RBAC permissions', 'tags' => ['Users']]],
                '/extensions'              => ['get' => ['summary' => 'List SIP/Fax extensions', 'tags' => ['PBX']], 'post' => ['summary' => 'Create extension', 'tags' => ['PBX']]],
                '/extensions/{id}'         => ['get' => ['summary' => 'Get extension', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update extension', 'tags' => ['PBX']], 'delete' => ['summary' => 'Remove extension', 'tags' => ['PBX']]],
                '/trunks'                  => ['get' => ['summary' => 'List SIP trunks', 'tags' => ['PBX']], 'post' => ['summary' => 'Create SIP trunk', 'tags' => ['PBX']]],
                '/trunks/{id}'             => ['get' => ['summary' => 'Get SIP trunk', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update SIP trunk', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete SIP trunk', 'tags' => ['PBX']]],
                '/did-routes'              => ['get' => ['summary' => 'List inbound DID routes', 'tags' => ['PBX']], 'post' => ['summary' => 'Create inbound DID route', 'tags' => ['PBX']]],
                '/did-routes/{id}'         => ['get' => ['summary' => 'Get inbound DID route', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update inbound DID route', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete inbound DID route', 'tags' => ['PBX']]],
                '/outbound-routes'         => ['get' => ['summary' => 'List outbound routes', 'tags' => ['PBX']], 'post' => ['summary' => 'Create outbound route', 'tags' => ['PBX']]],
                '/outbound-routes/{id}'    => ['get' => ['summary' => 'Get outbound route', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update outbound route', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete outbound route', 'tags' => ['PBX']]],
                '/ivrs'                    => ['get' => ['summary' => 'List IVR menus & digit entries', 'tags' => ['PBX']], 'post' => ['summary' => 'Create IVR menu', 'tags' => ['PBX']]],
                '/ivrs/{id}'               => ['get' => ['summary' => 'Get IVR menu', 'tags' => ['PBX']], 'patch' => ['summary' => 'Update IVR menu', 'tags' => ['PBX']], 'delete' => ['summary' => 'Delete IVR menu', 'tags' => ['PBX']]],
                '/time-conditions'         => ['get' => ['summary' => 'List time conditions', 'tags' => ['PBX']], 'post' => ['summary' => 'Create time condition', 'tags' => ['PBX']]],
                '/time-groups'             => ['get' => ['summary' => 'List time groups', 'tags' => ['PBX']], 'post' => ['summary' => 'Create time group', 'tags' => ['PBX']]],
                '/ring-groups'             => ['get' => ['summary' => 'List ring groups', 'tags' => ['PBX']], 'post' => ['summary' => 'Create ring group', 'tags' => ['PBX']]],
                '/conferences'             => ['get' => ['summary' => 'List conference rooms', 'tags' => ['PBX']], 'post' => ['summary' => 'Create conference room', 'tags' => ['PBX']]],
                '/queues'                  => ['get' => ['summary' => 'List call center queues', 'tags' => ['Queues']], 'post' => ['summary' => 'Create queue', 'tags' => ['Queues']]],
                '/queues/live'             => ['get' => ['summary' => 'Live queue and agent telemetry', 'tags' => ['Queues']]],
                '/calls/active'            => ['get' => ['summary' => 'List active Asterisk channels', 'tags' => ['Calls']]],
                '/calls/originate'         => ['post' => ['summary' => 'Click-to-call originate', 'tags' => ['Calls']]],
                '/calls/hangup'            => ['post' => ['summary' => 'Hangup an active channel', 'tags' => ['Calls']]],
                '/cdr'                     => ['get' => ['summary' => 'Query Call Detail Records (CDR)', 'tags' => ['Calls']]],
                '/voicemails'              => ['get' => ['summary' => 'List voicemails for an extension', 'tags' => ['Calls']]],
                '/web-widgets'             => ['get' => ['summary' => 'List website call widgets', 'tags' => ['Plugins']], 'post' => ['summary' => 'Create or update website call widget', 'tags' => ['Plugins']]],
                '/webhooks'                => ['get' => ['summary' => 'List outbound webhooks', 'tags' => ['Webhooks']], 'post' => ['summary' => 'Create outbound webhook', 'tags' => ['Webhooks']]],
                '/plugins'                 => ['get' => ['summary' => 'List official catalog and custom plugins', 'tags' => ['Plugins']]],
                '/plugins/{slug}/enable'   => ['post' => ['summary' => 'Install and enable a plugin', 'tags' => ['Plugins']]],
                '/plugins/{slug}/disable'  => ['post' => ['summary' => 'Disable a plugin', 'tags' => ['Plugins']]],
                '/plugins/{slug}/settings' => ['get' => ['summary' => 'Read plugin settings', 'tags' => ['Plugins']], 'patch' => ['summary' => 'Update plugin settings', 'tags' => ['Plugins']]],
                '/plugins/{slug}/kv/{collection}/{key}' => ['get' => ['summary' => 'Get plugin KV item', 'tags' => ['Plugins']], 'put' => ['summary' => 'Store plugin KV item', 'tags' => ['Plugins']], 'delete' => ['summary' => 'Delete plugin KV item', 'tags' => ['Plugins']]],
            ],
        ];
        echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
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
