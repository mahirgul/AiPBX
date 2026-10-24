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
            $errors['name'] = 'Name must be between 1 and 100 characters';
        }

        $inputSource = (string)($data['input_source'] ?? 'dtmf');
        if (!in_array($inputSource, ['dtmf', 'caller_id'], true)) {
            $errors['input_source'] = 'Input source must be dtmf or caller_id';
        }

        $dataSource = (string)($data['data_source'] ?? 'database');
        if (!in_array($dataSource, ['database', 'http'], true)) {
            $errors['data_source'] = 'Data source must be database or http';
        }

        $dbId = !empty($data['external_db_id']) ? (int)$data['external_db_id'] : null;
        $querySql = trim((string)($data['query_sql'] ?? ''));
        if ($dataSource === 'database') {
            if ($dbId === null || !ExternalDbRepository::exists($dbId)) {
                $errors['external_db_id'] = 'Please select a valid external database connection';
            }
            if ($querySql === '') {
                $errors['query_sql'] = 'Query SQL cannot be empty';
            } elseif (!preg_match('/^(SELECT|WITH)\b/i', $querySql)) {
                $errors['query_sql'] = 'Query must be a read-only SELECT or WITH statement';
            }
        }

        $httpUrl = trim((string)($data['http_url'] ?? ''));
        $httpMethod = strtoupper(trim((string)($data['http_method'] ?? 'GET')));
        if ($dataSource === 'http') {
            if ($httpUrl === '') {
                $errors['http_url'] = 'HTTP URL cannot be empty';
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
                $errors['http_method'] = 'HTTP method must be GET or POST';
            }
        }

        $channelVar = trim((string)($data['channel_var_name'] ?? 'IVR_DATA_VAL'));
        if ($channelVar === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $channelVar)) {
            $errors['channel_var_name'] = 'Channel variable name must be alphanumeric/underscore (up to 64 chars)';
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $description = trim((string)($data['description'] ?? ''));
        $promptFile = trim((string)($data['prompt_file'] ?? ''));
        $maxDigits = max(1, min(30, (int)($data['max_digits'] ?? 10)));
        $inputTimeout = max(1, min(30, (int)($data['input_timeout_sec'] ?? 5)));

        $foundDestType = (string)($data['found_dest_type'] ?? 'queue');
        $foundDestId = (string)($data['found_dest_id'] ?? '');
        $notFoundDestType = (string)($data['not_found_dest_type'] ?? 'hangup');
        $notFoundDestId = (string)($data['not_found_dest_id'] ?? '');
        $timeoutDestType = (string)($data['timeout_dest_type'] ?? 'hangup');
        $timeoutDestId = (string)($data['timeout_dest_id'] ?? '');
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
        return $stmt->rowCount() > 0;
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

                // Bind only parameters that actually appear in the query to avoid PDO HY093
                $candidateParams = [
                    ':input' => $input,
                    ':caller_id' => $callerId,
                ];
                $params = [];
                foreach ($candidateParams as $paramKey => $paramVal) {
                    if (str_contains($sql, $paramKey)) {
                        $params[$paramKey] = $paramVal;
                    }
                }

                $rows = ExternalDbService::executeQuery($dbId, $sql, $params);
                if (!empty($rows)) {
                    $firstRow = $rows[0];
                    $firstVal = (string)reset($firstRow);
                    return ['status' => 'found', 'var' => $varName, 'val' => $firstVal];
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

                // SSRF check
                WebhookService::validateTargetUrl($url);

                $method = (string)($step['http_method'] ?? 'GET');
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT_MS => 2500, // 2.5s hard limit
                    CURLOPT_CONNECTTIMEOUT_MS => 1500,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);

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
                    if (strlen($val) > 64) {
                        $val = substr($val, 0, 64);
                    }
                    return ['status' => 'found', 'var' => $varName, 'val' => $val];
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
}
