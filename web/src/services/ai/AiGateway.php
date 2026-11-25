<?php
/** Versioned consumer contract. Provider execution belongs to the Python worker. */
require_once __DIR__ . '/CloudAiService.php';
require_once __DIR__ . '/AssistantSettingsService.php';
require_once dirname(__DIR__) . '/LocalAiService.php';
require_once dirname(__DIR__, 2) . '/core/PluginManager.php';
require_once __DIR__ . '/OllamaService.php';

final class AiGatewayException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('AI: ' . $errorCode);
    }

    public function userMessage(): string
    {
        $key = match ($this->errorCode) {
            'permission_denied', 'feature_disabled' => 'permission',
            'policy_blocked' => 'privacy',
            'provider_auth_failed', 'connection_unavailable' => 'credentials',
            'model_unavailable', 'model_not_ready', 'binding_unresolved' => 'model',
            'configuration_changed' => 'changed',
            'deadline_exceeded' => 'timeout',
            'rate_limited', 'capacity_exceeded', 'budget_exceeded' => 'busy',
            default => 'unavailable',
        };
        return t('ai_gateway.error_' . $key);
    }
}

final class AiGateway
{
    public const VERSION = 1;
    public const OPERATIONS = ['chat', 'transcribe', 'synthesize', 'embed'];

    /** Server-side principal only; the session role is never an authorization source. */
    public static function authorize(int $userId, string $feature, ?int $epoch = null): array
    {
        $q = getDB()->prepare('SELECT id, role, token_epoch, is_active, is_device FROM sys_users WHERE id = ?');
        $q->execute([$userId]);
        $u = $q->fetch();
        if (!$u || !$u['is_active'] || $u['is_device'] || ($epoch !== null && (int) $u['token_epoch'] !== $epoch)) {
            throw new AiGatewayException('permission_denied');
        }
        if ((in_array($feature, ['panel', 'knowledge'], true) && $u['role'] !== 'admin')
            || ($feature === 'assistant' && !AssistantSettingsService::canAsk((string) $u['role']))
            || !in_array($feature, ['panel', 'assistant', 'knowledge'], true)) {
            throw new AiGatewayException('permission_denied');
        }
        if ($feature === 'assistant' || $feature === 'knowledge') {
            $slug = $feature === 'assistant' ? 'pbx-assistant' : 'ai-knowledge';
            $q = getDB()->prepare("SELECT enabled FROM sys_plugins WHERE slug = ?");
            $q->execute([$slug]);
            if (!(bool) $q->fetchColumn()) {
                throw new AiGatewayException('feature_disabled');
            }
        }
        $q = getDB()->query("SELECT COUNT(*) FROM sys_plugins WHERE slug IN ('ailocal', 'aicloud') AND enabled = 1");
        if ((int) $q->fetchColumn() === 0) {
            throw new AiGatewayException('feature_disabled');
        }
        return $u;
    }

    /** Service principal of voice-application knowledge lookups (embedding only). */
    private static function voiceKnowledgePrincipal(): array
    {
        if (!PluginManager::isEnabled('ai-knowledge')) {
            throw new AiGatewayException('feature_disabled');
        }
        return ['id' => 0, 'token_epoch' => 0, 'role' => 'service'];
    }

    private static function requireScopePlugin(string $scope): void
    {
        $slug = $scope === 'local' ? 'ailocal' : 'aicloud';
        $q = getDB()->prepare('SELECT enabled FROM sys_plugins WHERE slug = ? LIMIT 1');
        $q->execute([$slug]);
        if (!(bool) $q->fetchColumn()) {
            throw new AiGatewayException('feature_disabled');
        }
    }

    /** Transitional references preserve existing selections until the provider split. */
    public static function connection(string $selection, array $draft = [], string $operation = 'chat'): array
    {
        $requiredCap = $operation === 'embed' ? 'embed' : 'llm';
        if (str_starts_with($selection, 'local:')) {
            $id = substr($selection, 6);
            if (!preg_match('/^ollama-[a-f0-9]{32}$/', $id)) {
                $m = LocalAiService::model($id);
                $wantKind = $operation === 'embed' ? 'embedding' : 'llm';
                if (($m['kind'] ?? '') !== $wantKind || ($m['state'] ?? '') !== 'ready') {
                    throw new AiGatewayException('model_not_ready');
                }
                self::requireScopePlugin('local');
                return ['scope' => 'local', 'adapter' => 'runtime', 'model' => $id, 'secret_ref' => null];
            }
            $m = OllamaService::model($id);
            if ($m === null) {
                throw new AiGatewayException('model_not_ready');
            }
            self::requireScopePlugin('local');
            return ['scope' => 'local', 'adapter' => 'ollama', 'model' => $m['name'], 'secret_ref' => null];
        }
        if (!preg_match('/^cloud:([a-z_]{2,20})$/', $selection, $m)) {
            throw new AiGatewayException('binding_unresolved');
        }
        $id = $m[1];
        if (!in_array($requiredCap, CloudAiService::provider($id)['caps'], true)) {
            throw new AiGatewayException('capability_unsupported');
        }
        // Explicit local Ollama / NVIDIA NIM connections stay local; remote endpoints remain under cloud policy.
        $cfg = CloudAiService::gatewayConfig($id, $draft);
        if ($operation === 'embed' && ($cfg['adapter'] ?? '') === 'openai') {
            $cfg['url'] = preg_replace('#/chat/completions$#', '/embeddings', (string) ($cfg['url'] ?? ''));
        }
        $host = strtolower((string) parse_url($cfg['url'], PHP_URL_HOST));
        $scope = in_array(CloudAiService::typeOf($id), ['ollama', 'nvidia_local'], true) && in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true) ? 'local' : 'cloud';
        self::requireScopePlugin($scope);
        $policy = getDB()->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'privacy_block_cloud_ai'")->fetchColumn();
        if ($scope === 'cloud' && $policy === '1') {
            throw new AiGatewayException('policy_blocked');
        }
        if ($scope !== 'local' && !CloudAiService::configured($id, $draft)) {
            throw new AiGatewayException('connection_unavailable');
        }
        if (CloudAiService::overLimit($id, 'llm')) {
            throw new AiGatewayException('budget_exceeded');
        }
        return $cfg + ['scope' => $scope, 'secret_ref' => 'legacy:' . $id, 'provider' => $id];
    }

    public static function chat(string $selection, string $prompt, string $feature = 'assistant', int $maxTokens = 300, array $draft = []): string
    {
        return (string) self::execute('chat', $selection, $prompt, $feature, $maxTokens, $draft)['output'];
    }

    /** @return array{embedding: list<float>, dimensions: int, resolved_model_ref: string, usage: array<string, mixed>} */
    public static function embed(string $selection, string $text, string $feature = 'knowledge', array $draft = []): array
    {
        $res = self::execute('embed', $selection, $text, $feature, 1, $draft);
        $vec = array_map('floatval', (array) ($res['embedding'] ?? []));
        return [
            'embedding' => $vec,
            'dimensions' => (int) ($res['dimensions'] ?? count($vec)),
            'resolved_model_ref' => (string) ($res['resolved_model_ref'] ?? $selection),
            'usage' => (array) ($res['usage'] ?? []),
        ];
    }

    public static function execute(string $operation, string $selection, string $input, string $feature, int $maxTokens = 300, array $draft = []): array
    {
        // A voice application's knowledge lookup runs during a call, with no signed-in user:
        // a service principal limited to computing one query embedding.
        if ($feature === 'voice_knowledge') {
            if ($operation !== 'embed') {
                throw new AiGatewayException('permission_denied');
            }
            $user = self::voiceKnowledgePrincipal();
        } else {
            $user = self::authorize((int) ($_SESSION['user_id'] ?? 0), $feature);
        }
        $draft = array_intersect_key($draft, array_flip(['api_key', 'model', 'base_url']));
        if (!in_array($operation, ['chat', 'embed'], true)) {
            throw new AiGatewayException('capability_unsupported');
        }
        if ($input === '' || strlen($input) > 100000 || $maxTokens < 1 || $maxTokens > 2048 || ($draft !== [] && $feature !== 'panel')) {
            throw new AiGatewayException('invalid_request');
        }
        $connection = self::connection($selection, $draft, $operation);
        $requestId = bin2hex(random_bytes(16));
        $payload = json_encode(['protocol_version' => self::VERSION, 'request_id' => $requestId,
            'operation' => $operation, 'input' => $input, 'max_tokens' => $maxTokens,
            'deadline_at' => time() + 90], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($payload) > 131072) { throw new AiGatewayException('invalid_request'); }
        $db = getDB();
        $db->exec('DELETE FROM ai_gateway_tickets WHERE expires_at < UNIX_TIMESTAMP()');
        $q = $db->prepare('INSERT INTO ai_gateway_tickets (request_id,user_id,token_epoch,feature,selection,config_revision,payload_hash,draft_sealed,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $q->execute([$requestId, $user['id'], $user['token_epoch'], $feature, $selection,
            hash('sha256', json_encode($connection, JSON_THROW_ON_ERROR)), hash('sha256', $payload),
            $draft === [] ? null : SecretBox::encrypt(json_encode($draft, JSON_THROW_ON_ERROR)), time() + 90]);
        try {
            $token = trim((string) file_get_contents(LocalAiService::tokenFile()));
            $response = TtsHttp::request('POST', LocalAiService::BASE_URL . '/v1/gateway/execute',
                ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], $payload, 95);
            $data = json_decode($response['body'], true);
            if (!is_array($data) || ($data['protocol_version'] ?? null) !== self::VERSION || ($data['request_id'] ?? '') !== $requestId) {
                throw new AiGatewayException('protocol_mismatch');
            }
            if (($data['status'] ?? '') !== 'succeeded') {
                $code = (string) ($data['error']['code'] ?? 'provider_unavailable');
                throw new AiGatewayException(preg_match('/^[a-z_]{1,40}$/', $code) ? $code : 'provider_unavailable');
            }
            if (isset($connection['provider']) && $draft === []) {
                CloudAiService::count($connection['provider'], 'llm_tokens', (float) ($data['usage']['amount'] ?? 0));
            }
            return $data;
        } catch (TtsException $e) {
            throw new AiGatewayException('worker_unavailable');
        } finally {
            $q = $db->prepare('DELETE FROM ai_gateway_tickets WHERE request_id = ?');
            $q->execute([$requestId]);
        }
    }

    /** Service principal scoped to one active voice application and one configured task. */
    public static function resolveApp(string $reference): array
    {
        if (!preg_match('/^app:([1-9][0-9]{0,6}):(llm|stt|tts):([a-z_]{2,20})$/', $reference, $m)) {
            throw new AiGatewayException('permission_denied');
        }
        $db = getDB();
        if (!PluginManager::isEnabled('aicloud')) {
            throw new AiGatewayException('feature_disabled');
        }
        if ($db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'privacy_block_cloud_ai'")->fetchColumn() === '1') {
            throw new AiGatewayException('policy_blocked');
        }
        $q = $db->prepare('SELECT * FROM pbx_ai_apps WHERE id = ? AND is_active = 1');
        $q->execute([(int) $m[1]]);
        $app = $q->fetch();
        if (!$app) { throw new AiGatewayException('permission_denied'); }
        require_once __DIR__ . '/AiAppService.php';
        if ($app['app_type'] !== AiAppService::BUILTIN_AGENT && !PluginManager::isEnabled('ai-apps')) {
            throw new AiGatewayException('feature_disabled');
        }
        $config = AiAppService::serviceConfig($app, true);
        $credential = $config[$m[2] . '_cloud'] ?? null;
        if (!is_array($credential) || ($credential['provider'] ?? '') !== $m[3]) {
            throw new AiGatewayException('configuration_changed');
        }
        return ['credential' => $credential];
    }

    public static function localForApp(int $appId): array
    {
        require_once __DIR__ . '/AiAppService.php';
        $app = AiAppService::get($appId);
        if (!$app || !$app['is_active'] || $app['app_type'] !== AiAppService::BUILTIN_AGENT) {
            throw new AiGatewayException('permission_denied');
        }
        $selection = (string) (AiAppService::config($app)['understand'] ?? '');
        if (!str_starts_with($selection, 'local:')) {
            throw new AiGatewayException('configuration_changed');
        }
        return ['connection' => self::connection($selection)];
    }

    /**
     * Knowledge base lookup of a voice application's conversation: only the collection
     * the administrator chose for this active application. The text the model reads.
     */
    public static function knowledgeForApp(int $appId, string $query): array
    {
        $query = trim($query);
        if ($appId < 1 || $query === '' || mb_strlen($query) > 300) {
            throw new AiGatewayException('invalid_request');
        }
        $db = getDB();
        if (!PluginManager::isEnabled('ai-knowledge')) {
            throw new AiGatewayException('feature_disabled');
        }
        $q = $db->prepare('SELECT * FROM pbx_ai_apps WHERE id = ? AND is_active = 1');
        $q->execute([$appId]);
        $app = $q->fetch();
        if (!$app) { throw new AiGatewayException('permission_denied'); }
        require_once __DIR__ . '/AiAppService.php';
        require_once __DIR__ . '/AiKnowledgeService.php';
        if ($app['app_type'] !== AiAppService::BUILTIN_AGENT && !PluginManager::isEnabled('ai-apps')) {
            throw new AiGatewayException('feature_disabled');
        }
        $config = AiAppService::config($app);
        $collection = (int) ($config['knowledge_collection'] ?? 0);
        if (empty($config['conversation']) || $collection < 1) {
            throw new AiGatewayException('permission_denied');
        }
        // The collection was chosen by the administrator for this application: no role filter.
        $res = AiKnowledgeService::search($query, 'admin', $collection, 3, AiKnowledgeService::MIN_SCORE, 'voice_knowledge');
        $direct = AiKnowledgeService::directAnswer($res);
        if ($direct !== null) {
            return ['text' => '[1] ' . $direct['sources'][0]['doc_title'] . ': ' . $direct['answer']];
        }
        if (!$res['grounded']) {
            return ['text' => '(nothing about this in the knowledge base)'];
        }
        $lines = [];
        foreach ($res['hits'] as $i => $h) {
            $lines[] = '[' . ($i + 1) . '] ' . $h['doc_title'] . ': ' . $h['chunk_text'];
        }
        return ['text' => mb_substr(implode("\n", $lines), 0, 2000)];
    }

    /** Called only by the restricted Unix-socket resolver, once per issued request. */
    public static function resolve(string $requestId, string $payloadHash): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId) || !preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
            throw new AiGatewayException('permission_denied');
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM ai_gateway_tickets WHERE request_id = ? FOR UPDATE');
            $q->execute([$requestId]);
            $row = $q->fetch();
            if (!$row || $row['consumed_at'] !== null || (int) $row['expires_at'] <= time() || !hash_equals($row['payload_hash'], $payloadHash)) {
                throw new AiGatewayException('permission_denied');
            }
            if ($row['feature'] === 'voice_knowledge') {
                self::voiceKnowledgePrincipal();
            } else {
                self::authorize((int) $row['user_id'], $row['feature'], (int) $row['token_epoch']);
            }
            $draft = $row['draft_sealed'] === null ? [] : json_decode(SecretBox::decrypt($row['draft_sealed']), true, 16, JSON_THROW_ON_ERROR);
            $explicitOp = $row['operation'] ?? null;
            $ops = is_string($explicitOp) && in_array($explicitOp, ['chat', 'embed'], true)
                ? [$explicitOp]
                : ($row['feature'] === 'voice_knowledge' ? ['embed'] : ['chat', 'embed']);
            $connection = null;
            $lastErr = null;
            $mismatch = false;
            foreach ($ops as $op) {
                try {
                    $candidate = self::connection($row['selection'], $draft, $op);
                } catch (AiGatewayException $e) {
                    if (in_array($e->errorCode, ['model_not_ready', 'capability_unsupported'], true)) {
                        $lastErr = $e;
                        continue;
                    }
                    throw $e;
                }
                if (hash_equals($row['config_revision'], hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR)))) {
                    $connection = $candidate;
                    break;
                }
                $mismatch = true;
            }
            if ($connection === null) {
                throw $mismatch ? new AiGatewayException('configuration_changed') : ($lastErr ?? new AiGatewayException('configuration_changed'));
            }
            $q = $db->prepare('UPDATE ai_gateway_tickets SET consumed_at = UNIX_TIMESTAMP(), draft_sealed = NULL WHERE request_id = ?');
            $q->execute([$requestId]);
            $db->commit();
            return ['connection' => $connection, 'model_ref' => $row['selection'], 'config_revision' => $row['config_revision']];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
