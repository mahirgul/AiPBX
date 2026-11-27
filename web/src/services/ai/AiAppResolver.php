<?php
/**
 * Unix credential and knowledge broker helpers for active voice applications (`web/bin/ai_gateway_resolve.php`).
 *
 * Sits above AiGateway, AiAppService and AiKnowledgeService so none of those lower layers depend on one another
 * through the broker's voice-app glue.
 */

require_once __DIR__ . '/AiGateway.php';
require_once __DIR__ . '/AiAppService.php';
require_once __DIR__ . '/AiKnowledgeService.php';
require_once dirname(__DIR__, 2) . '/core/PluginManager.php';

final class AiAppResolver
{
    /**
     * Service principal scoped to one active voice application and one configured task.
     *
     * @return array{credential: array<string, mixed>}
     */
    public static function resolveApp(string $reference): array
    {
        if (!preg_match('/^app:([1-9][0-9]{0,6}):(llm|stt|tts|realtime):([a-z_]{2,20})$/', $reference, $m)) {
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

    /**
     * Knowledge base lookup of a voice application's conversation: only the collection
     * the administrator chose for this active application. The text the model reads.
     *
     * @return array{text: string}
     */
    public static function knowledgeForApp(int $appId, string $query, int $sourceId = 0): array
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
        if ($app['app_type'] !== AiAppService::BUILTIN_AGENT && !PluginManager::isEnabled('ai-apps')) {
            throw new AiGatewayException('feature_disabled');
        }
        $config = AiAppService::config($app);
        $attached = array_map('intval', (array) ($config['knowledge_collections'] ?? []));
        // One source attached: the model may leave it out. Several: it must name one, and only an attached one is searched.
        $collection = $sourceId > 0 ? $sourceId : (count($attached) === 1 ? $attached[0] : 0);
        if (empty($config['conversation']) || $collection < 1 || !in_array($collection, $attached, true)) {
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
}
