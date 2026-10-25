<?php

declare(strict_types=1);

require_once __DIR__ . '/ExternalDbService.php';
require_once __DIR__ . '/WebhookService.php';
require_once __DIR__ . '/../repositories/IvrDataStepRepository.php';

/**
 * Service for managing IVR Data Steps (Roadmap 2.3).
 * Queries an external database or HTTP endpoint during an IVR call and sets channel variables.
 */
class IvrDataStepService
{
    /**
     * @param array<string, mixed> $data
     * @return array{ok: bool, id?: int, errors: array<string, string>}
     */
    public static function saveStep(array $data, ?int $userId = null): array
    {
        $errors = [];
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            $errors['name'] = t('ivr_data.err_name');
        }

        $inputSource = (string)($data['input_source'] ?? 'dtmf');
        if (!in_array($inputSource, ['dtmf', 'caller_id'], true)) {
            $errors['input_source'] = t('ivr_data.err_input');
        }

        $dataSource = (string)($data['data_source'] ?? 'database');
        if (!in_array($dataSource, ['database', 'http'], true)) {
            $errors['data_source'] = t('ivr_data.err_source');
        }

        $dbId = !empty($data['external_db_id']) ? (int)$data['external_db_id'] : null;
        $querySql = trim((string)($data['query_sql'] ?? ''));
        if ($dataSource === 'database') {
            if ($dbId === null || !ExternalDbRepository::exists($dbId)) {
                $errors['external_db_id'] = t('ivr_data.err_db');
            }
            if ($querySql === '') {
                $errors['query_sql'] = t('ivr_data.err_sql_empty');
            } elseif (!preg_match('/^(SELECT|WITH)\b/i', $querySql)) {
                $errors['query_sql'] = t('ivr_data.err_sql_select');
            }
        }

        $httpUrl = trim((string)($data['http_url'] ?? ''));
        $httpMethod = strtoupper(trim((string)($data['http_method'] ?? 'GET')));
        if ($dataSource === 'http') {
            if ($httpUrl === '') {
                $errors['http_url'] = t('ivr_data.err_url');
            } else {
                try {
                    // Check URL without placeholder template variables
                    $testUrl = str_replace(['{input}', '{caller_id}'], ['123', '456'], $httpUrl);
                    WebhookService::validateTargetUrl($testUrl);
                } catch (\Throwable $e) {
                    $errors['http_url'] = $e->getMessage();
                }
            }
            if (!in_array($httpMethod, ['GET', 'POST'], true)) {
                $errors['http_method'] = t('ivr_data.err_method');
            }
        }

        $channelVar = trim((string)($data['channel_var_name'] ?? 'IVR_DATA_VAL'));
        if ($channelVar === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $channelVar)) {
            $errors['channel_var_name'] = t('ivr_data.err_var');
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $description = trim((string)($data['description'] ?? ''));
        $promptFile = FileHelper::sanitizeAudioPath((string)($data['prompt_file'] ?? ''));
        $maxDigits = max(1, min(30, (int)($data['max_digits'] ?? 10)));
        $inputTimeout = max(1, min(30, (int)($data['input_timeout_sec'] ?? 5)));

        $foundDestType = sanitizeDestType($data['found_dest_type'] ?? 'queue');
        $foundDestId = toCleanAscii((string)($data['found_dest_id'] ?? ''));
        $notFoundDestType = sanitizeDestType($data['not_found_dest_type'] ?? 'hangup');
        $notFoundDestId = toCleanAscii((string)($data['not_found_dest_id'] ?? ''));
        $timeoutDestType = sanitizeDestType($data['timeout_dest_type'] ?? 'hangup');
        $timeoutDestId = toCleanAscii((string)($data['timeout_dest_id'] ?? ''));

        // Prevent a step from pointing directly to itself (infinite dialplan loop)
        if ($id !== null) {
            if ($foundDestType === 'ivr_data' && (int)$foundDestId === $id) {
                $foundDestType = 'hangup';
                $foundDestId = '';
            }
            if ($notFoundDestType === 'ivr_data' && (int)$notFoundDestId === $id) {
                $notFoundDestType = 'hangup';
                $notFoundDestId = '';
            }
            if ($timeoutDestType === 'ivr_data' && (int)$timeoutDestId === $id) {
                $timeoutDestType = 'hangup';
                $timeoutDestId = '';
            }
        }
        $isActive = isset($data['is_active']) ? (!empty($data['is_active']) ? 1 : 0) : 1;

        $db = getDB();

        if ($id !== null) {
            $stmt = $db->prepare(
                'UPDATE pbx_ivr_data_steps
                 SET name = ?, description = ?, prompt_file = ?, input_source = ?,
                     max_digits = ?, input_timeout_sec = ?, data_source = ?,
                     external_db_id = ?, query_sql = ?, http_url = ?, http_method = ?,
                     channel_var_name = ?, found_dest_type = ?, found_dest_id = ?,
                     not_found_dest_type = ?, not_found_dest_id = ?,
                     timeout_dest_type = ?, timeout_dest_id = ?, is_active = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $name, $description, $promptFile ?: null, $inputSource,
                $maxDigits, $inputTimeout, $dataSource,
                $dbId, $querySql ?: null, $httpUrl ?: null, $httpMethod,
                $channelVar, $foundDestType, $foundDestId,
                $notFoundDestType, $notFoundDestId,
                $timeoutDestType, $timeoutDestId, $isActive, $id
            ]);

            if (function_exists('markPendingSync')) {
                markPendingSync('ivrs', 'ivr_data', $id, "IVR Data Step: {$name}", 'update', $userId);
            }

            return ['ok' => true, 'id' => $id, 'errors' => []];
        }

        $stmt = $db->prepare(
            'INSERT INTO pbx_ivr_data_steps
             (name, description, prompt_file, input_source, max_digits, input_timeout_sec,
              data_source, external_db_id, query_sql, http_url, http_method,
              channel_var_name, found_dest_type, found_dest_id,
              not_found_dest_type, not_found_dest_id,
              timeout_dest_type, timeout_dest_id, is_active, created_at, updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)'
        );
        $stmt->execute([
            $name, $description, $promptFile ?: null, $inputSource,
            $maxDigits, $inputTimeout, $dataSource,
            $dbId, $querySql ?: null, $httpUrl ?: null, $httpMethod,
            $channelVar, $foundDestType, $foundDestId,
            $notFoundDestType, $notFoundDestId,
            $timeoutDestType, $timeoutDestId, $isActive, $userId
        ]);
        $newId = (int)$db->lastInsertId();

        if (function_exists('markPendingSync')) {
            markPendingSync('ivrs', 'ivr_data', $newId, "IVR Data Step: {$name}", 'create', $userId);
        }

        return ['ok' => true, 'id' => $newId, 'errors' => []];
    }

    /**
     * How many places route calls to this step (IVR keys, inbound routes, time
     * plans …): deleting it would leave them pointing at nothing.
     */
    /**
     * :input and :caller_id may each appear several times; native prepared
     * statements (no emulation) refuse a repeated name (HY093), so every
     * occurrence gets its own name. Only names in the query are bound.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function bindLookupParams(string $sql, string $input, string $callerId): array
    {
        $values = ['input' => $input, 'caller_id' => $callerId];
        $params = [];
        $n = 0;
        $sql = (string) preg_replace_callback('/(?<!:):(input|caller_id)\b/', function (array $m) use (&$params, &$n, $values): string {
            $name = ':p' . (++$n) . '_' . $m[1];
            $params[$name] = $values[$m[1]];
            return $name;
        }, $sql);
        return [$sql, $params];
    }

    public static function usageCount(int $id): int
    {
        $db = getDB();
        $pairs = [
            ['pbx_dids', 'dest'], ['pbx_ring_groups', 'fallback_dest'], ['pbx_ivrs', 'timeout_dest'], ['pbx_ivrs', 'invalid_dest'],
            ['pbx_announcements', 'post_dest'], ['pbx_ai_apps', 'dest'], ['pbx_web_widgets', 'dest'], ['pbx_ivr_entries', 'dest'],
            ['pbx_boss_secretary_groups', 'fallback_dest'], ['pbx_time_conditions', 'match_dest'], ['pbx_time_conditions', 'nomatch_dest'],
            ['pbx_ivr_data_steps', 'found_dest'], ['pbx_ivr_data_steps', 'not_found_dest'], ['pbx_ivr_data_steps', 'timeout_dest'],
        ];
        $n = 0;
        foreach ($pairs as [$table, $col]) {
            try {
                $st = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col}_type = 'ivr_data' AND {$col}_id = ?");
                $st->execute([(string) $id]);
                $n += (int) $st->fetchColumn();
            } catch (\Throwable $e) {
                // a table of a feature that is not installed
            }
        }
        $st = $db->prepare("SELECT COUNT(*) FROM pbx_time_conditions WHERE rules_json REGEXP ?");
        $st->execute(['"(match|nomatch)_dest_type":"ivr_data","(match|nomatch)_dest_id":"?' . $id . '"?[,}]']);
        return $n + (int) $st->fetchColumn();
    }

    public static function deleteStep(int $id): bool
    {
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM pbx_ivr_data_steps WHERE id = ?');
        $stmt->execute([$id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('ivrs', 'ivr_data', $id, "IVR Data Step #{$id}", 'delete', $_SESSION['user_id'] ?? null);
        }
        return $ok;
    }

    public static function toggleActive(int $id, bool $isActive): bool
    {
        $db = getDB();
        $stmt = $db->prepare('UPDATE pbx_ivr_data_steps SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$isActive ? 1 : 0, $id]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && function_exists('markPendingSync')) {
            markPendingSync('ivrs', 'ivr_data', $id, "IVR Data Step #{$id} " . ($isActive ? 'on' : 'off'), 'update', $_SESSION['user_id'] ?? null);
        }
        return $ok;
    }

    /**
     * Executes the data lookup (called from Asterisk loopback API).
     * Hard limit: 2.5 seconds.
     *
     * @return array{status: 'found'|'not_found'|'timeout', var: string, val: string}
     */
    public static function performLookup(int $stepId, string $input, string $callerId): array
    {
        $step = IvrDataStepRepository::findById($stepId);
        if (!$step || empty($step['is_active'])) {
            return ['status' => 'not_found', 'var' => 'IVR_DATA_VAL', 'val' => ''];
        }

        $varName = (string)($step['channel_var_name'] ?? 'IVR_DATA_VAL');
        $dataSource = (string)($step['data_source'] ?? 'database');

        try {
            if ($dataSource === 'database') {
                $dbId = (int)($step['external_db_id'] ?? 0);
                $sql = (string)($step['query_sql'] ?? '');

                [$sql, $params] = self::bindLookupParams($sql, $input, $callerId);

                $rows = ExternalDbService::executeQuery($dbId, $sql, $params);
                if (!empty($rows)) {
                    $firstRow = $rows[0];
                    return ['status' => 'found', 'var' => $varName, 'val' => self::cleanValue((string) reset($firstRow))];
                }
                return ['status' => 'not_found', 'var' => $varName, 'val' => ''];
            }

            if ($dataSource === 'http') {
                $rawUrl = (string)($step['http_url'] ?? '');
                $url = str_replace(
                    ['{input}', '{caller_id}'],
                    [rawurlencode($input), rawurlencode($callerId)],
                    $rawUrl
                );

                // SSRF check, with the checked address pinned for the request
                $resolve = WebhookService::validatedResolve($url);

                $method = (string)($step['http_method'] ?? 'GET');
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => 2500, // 2.5s hard limit
                    CURLOPT_CONNECTTIMEOUT_MS => 1500,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                if ($resolve) {
                    curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
                }

                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        'input' => $input,
                        'caller_id' => $callerId,
                    ]));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                }

                $resp = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300 && is_string($resp) && trim($resp) !== '') {
                    $val = trim($resp);
                    // If JSON was returned, try extracting 'value' or 'result'
                    $json = json_decode($val, true);
                    if (is_array($json)) {
                        $val = (string)($json['value'] ?? $json['result'] ?? reset($json));
                    }
                    return ['status' => 'found', 'var' => $varName, 'val' => self::cleanValue($val)];
                }

                if ($httpCode === 404) {
                    return ['status' => 'not_found', 'var' => $varName, 'val' => ''];
                }

                return ['status' => 'timeout', 'var' => $varName, 'val' => ''];
            }

            return ['status' => 'not_found', 'var' => $varName, 'val' => ''];
        } catch (\Throwable $e) {
            return ['status' => 'timeout', 'var' => $varName, 'val' => ''];
        }
    }

    /**
     * The answer goes into a channel variable through "status|value": no line
     * breaks, no "|" and no control characters, at most 64 characters.
     */
    public static function cleanValue(string $val): string
    {
        $val = preg_replace('/[\x00-\x1F\x7F|]+/u', ' ', $val) ?? '';
        return mb_substr(trim($val), 0, 64);
    }
}
