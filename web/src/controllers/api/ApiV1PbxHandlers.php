<?php

declare(strict_types=1);

/**
 * PBX, Routing, Queue, and Call/CDR domain handlers for REST API v1 (`/api/v1/*`).
 */
final class ApiV1PbxHandlers
{
    // ------------------------------------------------------------------ Extensions

    public static function handleExtensions(string $method, ?string $idStr): never
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

    public static function handleTrunks(string $method, ?string $idStr, ?string $subAction): never
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

    public static function handleDidRoutes(string $method, ?string $idStr, ?string $subAction): never
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

    public static function handleOutboundRoutes(string $method, ?string $idStr, ?string $subAction): never
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

    public static function handleIvrs(string $method, ?string $idStr, ?string $subAction, ?string $subId): never
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

    public static function handleTimeConditions(string $method, ?string $idStr, ?string $subAction): never
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

    public static function handleTimeGroups(string $method, ?string $idStr): never
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

    public static function handleRingGroups(string $method, ?string $idStr): never
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

    public static function handleConferences(string $method, ?string $idStr): never
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

    public static function handleBossSecretary(string $method, ?string $idStr): never
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

    public static function handleDialPermissions(string $method, ?string $idStr): never
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

    public static function handleFeatureCodes(string $method, ?string $idStr): never
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

    public static function handleHangupActions(string $method, ?string $idStr): never
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

    public static function handleQueues(string $method, ?string $idStr, ?string $subAction): never
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

    public static function handleCalls(string $method, string $sub): never
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
            $extUser = DBHelper::fetchOne(
                'SELECT outbound_group, permission_group_id FROM sys_users WHERE extension = ? AND is_active = 1 LIMIT 1',
                [$fromExt]
            );
            $permGroup = max(1, (int)($extUser['permission_group_id'] ?? 1));
            if (!DialPermissionService::isDestinationAllowed($permGroup, $toNumber)) {
                ApiResponse::error('FORBIDDEN', 'Destination is barred by the extension dial permission group.', 403);
            }
            $outboundGrp = max(1, (int)($extUser['outbound_group'] ?? 1));
            $outboundContext = ($outboundGrp > 1) ? "from-internal-web-{$outboundGrp}" : 'from-internal-web';
            // The CLI originate has no caller ID option: the call shows the extension's own
            // caller ID, so the event does not claim another one (a "caller_id" in the body
            // used to be echoed in the webhook without being applied).
            $cmd = sprintf('channel originate Local/%s@cc-internal extension %s@%s', $fromExt, $toNumber, $outboundContext);
            $out = AsteriskHelper::execCLI($cmd);
            WebhookService::emit('call.started', [
                'source'         => 'api_originate',
                'from_extension' => $fromExt,
                'to_number'      => $toNumber,
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
            $channel = preg_replace('/[^A-Za-z0-9\/_.@;-]/', '', (string)($body['channel'] ?? ''));
            if ($channel === '') {
                ApiResponse::error('VALIDATION_ERROR', 'Channel identifier is required.', 422);
            }
            $out = AsteriskHelper::execCLI('channel request hangup ' . $channel);
            ApiResponse::success(['channel' => $channel, 'asterisk_reply' => trim((string)($out['output'] ?? ''))]);
        }

        ApiResponse::error('NOT_FOUND', "Unknown calls endpoint: /api/v1/calls/{$sub}", 404);
    }

    public static function handleCdr(string $method): never
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

    public static function handleVoicemails(string $method): never
    {
        if ($method !== 'GET') {
            ApiResponse::error('METHOD_NOT_ALLOWED', 'Only GET is supported for /api/v1/voicemails', 405);
        }
        ApiRequestContext::requireScope('calls:read');
        require_once dirname(__DIR__, 2) . '/services/VoicemailService.php';
        $ext = preg_replace('/[^0-9]/', '', (string)($_GET['extension'] ?? ''));
        if ($ext === '') {
            ApiResponse::error('VALIDATION_ERROR', 'Query parameter ?extension=<ext> is required.', 422);
        }
        ApiResponse::success([
            'extension' => $ext,
            'messages'  => VoicemailService::getVoicemailMessages($ext),
        ]);
    }
}
