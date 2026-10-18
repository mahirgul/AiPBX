<?php
/**
 * Custom interactive console rendered on `/plugins/ai-assistant`.
 *
 * Translates natural-language admin instructions (TR/EN) into previewable
 * `/api/v1/*` REST API calls and executes them only after explicit confirmation.
 *
 * @var array<string, mixed> $plugin
 * @var array<string, mixed> $settings
 * @var string $csrf_token
 */

require_once dirname(__DIR__, 3) . '/web/src/controllers/ApiV1Controller.php';

$assistantPlan = null;
$executionResult = null;
$assistantError = '';

/**
 * Translate a natural language prompt into a structured `/api/v1/*` plan using
 * the configured OpenAI-compatible endpoint (with deterministic fallback rules).
 *
 * @param array<string, mixed> $cfg
 * @return array{explanation: string, method: string, path: string, body: array<string, mixed>}
 */
$buildAssistantPlan = static function (string $prompt, array $cfg): array {
    $baseUrl = rtrim(trim((string)($cfg['api_base_url'] ?? 'https://api.openai.com/v1')), '/');
    $apiKey = trim((string)($cfg['api_key'] ?? ''));
    $model = trim((string)($cfg['model'] ?? 'gpt-4o-mini'));

    if ($apiKey !== '' && $baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        $sysPrompt = <<<'SYS'
You are the AiPBX REST API v1 Assistant. Convert the administrator's request into a single JSON object with keys:
- "explanation": concise explanation in the user's language (Turkish or English)
- "method": one of "GET", "POST", "PATCH", "DELETE"
- "path": valid AiPBX /api/v1/* path, such as:
  - "/api/v1/system/health" (GET)
  - "/api/v1/system/sync" (POST)
  - "/api/v1/extensions" (GET or POST {"extension":"205","name":"Ahmet Yilmaz","secret":"StrongPass123!","webrtc":1})
  - "/api/v1/queues" (GET or POST {"name":"support","strategy":"rrmemory","timeout":15,"members":["101","102"]})
  - "/api/v1/ring-groups" (GET or POST {"group_num":"600","description":"Sales","strategy":"ringall","ring_time":20,"extensions":["101","102"]})
  - "/api/v1/ivrs" (GET)
  - "/api/v1/trunks" (GET)
  - "/api/v1/calls/active" (GET)
  - "/api/v1/cdrs" (GET)
- "body": JSON object for POST/PATCH requests, or {} for GET/DELETE.
Output ONLY valid JSON.
SYS;

        $payload = json_encode([
            'model' => $model !== '' ? $model : 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $sysPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 400,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($baseUrl . '/chat/completions');
        if ($ch !== false && is_string($payload)) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (is_string($resp) && $code >= 200 && $code < 300) {
                $decoded = json_decode($resp, true);
                $rawContent = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
                $rawContent = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $rawContent) ?? $rawContent;
                $parsed = json_decode($rawContent, true);
                if (is_array($parsed) && !empty($parsed['method']) && !empty($parsed['path'])) {
                    return [
                        'explanation' => (string)($parsed['explanation'] ?? 'AI API plan generated.'),
                        'method'      => strtoupper((string)$parsed['method']),
                        'path'        => (string)$parsed['path'],
                        'body'        => is_array($parsed['body'] ?? null) ? $parsed['body'] : [],
                    ];
                }
            }
        }
    }

    // Deterministic built-in intent parser fallback (works even before an external API key is entered)
    $lower = mb_strtolower($prompt, 'UTF-8');
    if (preg_match('/(?:dahili|extension)\s+(\d{2,6})(?:\s+([a-zA-ZğüşıöçĞÜŞİÖÇ\s]{2,40}))?/u', $prompt, $m)
        && (str_contains($lower, 'ekle') || str_contains($lower, 'oluştur') || str_contains($lower, 'create') || str_contains($lower, 'add'))) {
        $extNum = $m[1];
        $extName = trim($m[2] ?? ('Ext ' . $extNum));
        return [
            'explanation' => "{$extNum} numaralı ({$extName}) yeni dahili oluşturulacak.",
            'method'      => 'POST',
            'path'        => '/api/v1/extensions',
            'body'        => [
                'extension' => $extNum,
                'name'      => $extName,
                'secret'    => 'AiPbx_' . bin2hex(random_bytes(4)) . '!',
                'webrtc'    => 1,
            ],
        ];
    }
    if (str_contains($lower, 'aktif çağrı') || str_contains($lower, 'active call') || str_contains($lower, 'canlı çağrı')) {
        return [
            'explanation' => 'Canlı devam eden çağrılar listelenecek.',
            'method'      => 'GET',
            'path'        => '/api/v1/calls/active',
            'body'        => [],
        ];
    }
    if (str_contains($lower, 'kuyruk') || str_contains($lower, 'queue')) {
        return [
            'explanation' => 'Mevcut çağrı kuyrukları listelenecek.',
            'method'      => 'GET',
            'path'        => '/api/v1/queues',
            'body'        => [],
        ];
    }
    if (str_contains($lower, 'dahili') || str_contains($lower, 'extension')) {
        return [
            'explanation' => 'Kayıtlı dahililer listelenecek.',
            'method'      => 'GET',
            'path'        => '/api/v1/extensions',
            'body'        => [],
        ];
    }
    if (str_contains($lower, 'sync') || str_contains($lower, 'senkron') || str_contains($lower, 'uygula')) {
        return [
            'explanation' => 'Bekleyen Asterisk yapılandırma değişiklikleri senkronize edilecek.',
            'method'      => 'POST',
            'path'        => '/api/v1/system/sync',
            'body'        => [],
        ];
    }

    return [
        'explanation' => 'Sistem sağlık ve özet durumu sorgulanacak.',
        'method'      => 'GET',
        'path'        => '/api/v1/system/health',
        'body'        => [],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    if (empty($plugin['enabled'])) {
        $assistantError = 'Please enable the AI Assistant plugin in the Plugin Catalog before running commands.';
    } elseif (!empty($_POST['assistant_prompt'])) {
        $prompt = trim((string)$_POST['assistant_prompt']);
        $assistantPlan = $buildAssistantPlan($prompt, $settings);
        $draftId = 'draft_' . bin2hex(random_bytes(6));
        $assistantPlan['draft_id'] = $draftId;
        $assistantPlan['prompt'] = $prompt;
        PluginManager::kvSet('ai-assistant', 'drafts', $draftId, $assistantPlan);
    } elseif (!empty($_POST['execute_draft_id'])) {
        $draftId = trim((string)$_POST['execute_draft_id']);
        $savedDraft = PluginManager::kvGet('ai-assistant', 'drafts', $draftId);
        if (!$savedDraft || !is_array($savedDraft['value'] ?? null)) {
            $assistantError = 'Draft plan expired or not found.';
        } else {
            $plan = $savedDraft['value'];
            $method = strtoupper((string)($plan['method'] ?? 'GET'));
            $path = (string)($plan['path'] ?? '/api/v1/system/health');
            $body = is_array($plan['body'] ?? null) ? $plan['body'] : [];

            if (!str_starts_with($path, '/api/v1/')) {
                $assistantError = 'Only /api/v1/* endpoints are permitted.';
            } else {
                $executionResult = ApiV1Controller::invokeLoopback($method, $path, $body);
                PluginManager::kvDelete('ai-assistant', 'drafts', $draftId);
                PluginManager::kvSet('ai-assistant', 'history', date('Ymd_His') . '_' . substr($draftId, -4), [
                    'prompt'      => $plan['prompt'] ?? '',
                    'method'      => $method,
                    'path'        => $path,
                    'body'        => $body,
                    'status_code' => $executionResult['status_code'] ?? 200,
                    'executed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }
        }
    }
}

$history = PluginManager::kvList('ai-assistant', 'history', 20);
?>

<div class="card u-mb-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-wand-magic-sparkles" style="color: #a855f7;"></i>
            AI Doğal Dil Santral Asistanı (Preview & Execute via <code>/api/v1/*</code>)
        </div>
    </div>
    <div style="padding: 20px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label class="form-label" style="font-weight: 600;">Komutunuzu Yazın (Türkçe veya İngilizce)</label>
                <div style="display: flex; gap: 10px;">
                    <input
                        type="text"
                        name="assistant_prompt"
                        class="form-control"
                        required
                        placeholder="Örn: Dahili 205 Ahmet Yılmaz oluştur / Aktif çağrıları göster / Kuyrukları listele"
                        value="<?php echo htmlspecialchars((string)($_POST['assistant_prompt'] ?? '')); ?>"
                    >
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                        <i class="fas fa-bolt"></i> Plan Oluştur (Önizle)
                    </button>
                </div>
            </div>
        </form>

        <?php if ($assistantError !== ''): ?>
            <div class="alert alert-danger u-mt-12"><?php echo htmlspecialchars($assistantError); ?></div>
        <?php endif; ?>

        <?php if (is_array($assistantPlan)): ?>
            <div style="margin-top: 16px; padding: 16px; border-radius: 8px; border: 1px solid #a855f7; background: rgba(168, 85, 247, 0.06);">
                <div style="font-weight: 600; margin-bottom: 8px;">
                    <i class="fas fa-eye"></i> Önizleme Planı: <?php echo htmlspecialchars($assistantPlan['explanation']); ?>
                </div>
                <div style="font-family: monospace; font-size: 12px; margin-bottom: 12px;">
                    <span class="badge badge-info"><?php echo htmlspecialchars($assistantPlan['method']); ?></span>
                    <code><?php echo htmlspecialchars($assistantPlan['path']); ?></code>
                    <?php if (!empty($assistantPlan['body'])): ?>
                        <pre style="margin-top: 8px; padding: 10px; border-radius: 6px; background: rgba(15,23,42,0.08);"><?php echo htmlspecialchars((string)json_encode($assistantPlan['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    <?php endif; ?>
                </div>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="execute_draft_id" value="<?php echo htmlspecialchars($assistantPlan['draft_id']); ?>">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-check-circle"></i> Onayla ve Uygula (<code>/api/v1</code>)
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (is_array($executionResult)): ?>
            <div style="margin-top: 16px; padding: 16px; border-radius: 8px; border: 1px solid var(--border-color);">
                <div style="font-weight: 600; margin-bottom: 8px;">
                    <i class="fas fa-terminal u-success"></i> API Yanıtı (HTTP <?php echo (int)($executionResult['status_code'] ?? 200); ?>)
                </div>
                <pre style="margin: 0; padding: 12px; border-radius: 6px; background: rgba(15,23,42,0.06); font-size: 12px; max-height: 300px; overflow: auto;"><?php echo htmlspecialchars((string)json_encode($executionResult['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-history u-primary"></i> Son Uygulanan Asistan İşlemleri
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($history); ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Zaman</th>
                    <th>Komut</th>
                    <th>API Çağrısı</th>
                    <th>Durum</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <?php echo uiTableEmptyRow(4, 'Henüz çalıştırılmış bir asistan komutu yok.', 'fa-wand-magic-sparkles'); ?>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                        <?php $v = is_array($h['value'] ?? null) ? $h['value'] : []; ?>
                        <tr>
                            <td class="u-fs-12"><?php echo htmlspecialchars((string)($v['executed_at'] ?? $h['updated_at'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($v['prompt'] ?? '')); ?></td>
                            <td>
                                <span class="badge badge-info"><?php echo htmlspecialchars((string)($v['method'] ?? 'GET')); ?></span>
                                <code><?php echo htmlspecialchars((string)($v['path'] ?? '')); ?></code>
                            </td>
                            <td><span class="badge badge-success">HTTP <?php echo (int)($v['status_code'] ?? 200); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
