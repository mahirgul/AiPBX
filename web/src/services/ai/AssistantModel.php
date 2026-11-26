<?php
/** PBX Assistant model selection. Actual requests use AiGateway; no provider fallback after execution failure. */

require_once __DIR__ . '/AssistantPlanner.php';
require_once __DIR__ . '/AssistantSettingsService.php';
require_once __DIR__ . '/CloudAiService.php';

class AssistantModel
{
    /**
     * The ask() for AssistantPlanner::plan(). Returns the model's reply as
     * one JSON object string, or null when no model layer is reachable —
     * the caller then falls back to the planner's own refusal, which is the
     * correct answer for "I did not understand".
     */
    public static function ask(string $prompt): ?string
    {
        require_once __DIR__ . '/AiGateway.php';
        $selection = self::resolvedMode();
        if (in_array($selection, ['rules_only', 'none'], true)) {
            return null;
        }
        try {
            return AiGateway::chat($selection, $prompt, 'assistant');
        } catch (AiGatewayException $e) {
            return null;
        }
    }

    /**
     * What the Assistant uses now: its own model choice (AI -> PBX Assistant), nothing when left off (rules only).
     *
     * @return string 'rules_only' | 'cloud:<id>' | 'none'
     */
    public static function resolvedMode(): string
    {
        require_once __DIR__ . '/AiBindingService.php';
        $selected = (string) (AiBindingService::resolve('assistant', 'chat') ?? '');
        if ($selected === '') {
            return 'rules_only';
        }
        if (!str_starts_with($selected, 'cloud:')) {
            return 'none';
        }
        $id = substr($selected, 6);
        if (AssistantSettingsService::isCloudBlocked() && !self::isLocalProvider($id)) {
            return 'rules_only';
        }
        return self::isCloudConfigured($id) ? $selected : 'none';
    }

    private static function isLocalProvider(string $id): bool
    {
        try {
            $baseUrl = (string) (CloudAiService::config($id)['base_url'] ?? '');
            $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
            return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function isCloudConfigured(string $id): bool
    {
        try {
            return CloudAiService::configured($id);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
