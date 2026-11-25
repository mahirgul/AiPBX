<?php
/** One request per process: no stale authorization/credential cache. Unix broker only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
try {
    $_SERVER['REQUEST_URI'] = '/api/v1/internal-ai-resolver';
    $_SERVER['HTTP_AUTHORIZATION'] = 'internal';
    $_SESSION = [];
    require dirname(__DIR__) . '/config.php';
    require dirname(__DIR__) . '/src/services/ai/AiGateway.php';
    $data = json_decode((string) stream_get_contents(STDIN, 65536), true, 8, JSON_THROW_ON_ERROR);
    if (isset($data['caller_number'])) {
        require_once dirname(__DIR__) . '/src/services/PhonebookService.php';
        require_once dirname(__DIR__) . '/src/services/DataSourceService.php';
        $number = $data['caller_number'];
        $name = '';
        $custId = '';
        if (is_string($number) && preg_match('/^\+?[0-9(). -]{3,40}$/D', $number)) {
            $name = PhonebookService::namesFor([$number])[$number] ?? '';
            $resolved = DataSourceService::withDefaults(['caller_id' => $number]);
            $custId = (string) ($resolved['customer_id'] ?? '');
        }
        $result = ['name' => $name, 'customer_id' => $custId];
    } elseif (isset($data['db_app'])) {
        require_once dirname(__DIR__) . '/src/services/ai/AiAppService.php';
        $result = AiAppDbLookup::run((int) $data['db_app'], (string) ($data['query_id'] ?? ''),
            $data['params'] ?? [], (string) ($data['verified_customer'] ?? ''), $data['call_context'] ?? []);
    } elseif (isset($data['local_app'])) {
        $result = AiGateway::localForApp((int) $data['local_app']);
    } elseif (isset($data['knowledge_app'])) {
        $result = AiGateway::knowledgeForApp((int) $data['knowledge_app'], (string) ($data['query'] ?? ''));
    } elseif (isset($data['secret_ref'])) {
        $result = AiGateway::resolveApp((string) $data['secret_ref']);
    } else {
        $result = AiGateway::resolve((string) ($data['request_id'] ?? ''), (string) ($data['payload_hash'] ?? ''));
    }
    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e instanceof AiGatewayException ? $e->errorCode : 'resolver_unavailable']);
}
