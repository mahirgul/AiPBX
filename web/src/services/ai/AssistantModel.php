<?php
/** PBX Assistant model selection. Actual requests use AiGateway; no provider fallback after execution failure. */

require_once __DIR__ . '/AssistantPlanner.php';
require_once __DIR__ . '/AssistantSettingsService.php';
require_once __DIR__ . '/CloudAiService.php';
require_once dirname(__DIR__) . '/LocalAiService.php';

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
     * Effective model mode given `assistant.model` and `privacy_block_cloud_ai`.
     *
     * @return string 'rules_only' | 'cloud:<id>' | 'local:<id>' | 'none'
     */
    public static function resolvedMode(): string
    {
        require_once __DIR__ . '/AiBindingService.php';
        $binding = AssistantSettingsService::binding();
        $cloudBlocked = AssistantSettingsService::isCloudBlocked();

        if ($binding['mode'] === 'off') {
            return 'rules_only';
        }

        $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
        $selected = '';
        if (!empty($binding['from_binding'])) {
            $selected = (string) (AiBindingService::resolve('assistant', 'chat', $lang, 'global') ?? '');
        } else {
            $selected = AssistantSettingsService::model();
            if ($selected === '') {
                $selected = (string) (AiBindingService::resolve('assistant', 'chat', $lang, 'global') ?? '');
            }
        }

        // "Local default" / "Cloud default": the default chat model of the Local AI / Cloud AI page.
        // A local default never falls back to a cloud model.
        if ($selected === '' && in_array($binding['mode'], ['local_default', 'cloud_default'], true)) {
            $selected = (string) (AiBindingService::panelDefault('chat', $binding['mode'] === 'local_default' ? 'local' : 'cloud', $lang) ?? '');
            if ($selected === '' && $binding['mode'] === 'local_default') {
                return 'none';
            }
        }
        if ($selected === 'rules_only') {
            return 'rules_only';
        }
        if (str_starts_with($selected, 'cloud:')) {
            $id = substr($selected, 6);
            if ($cloudBlocked && !self::isLocalProvider($id)) {
                return 'rules_only';
            }
            return self::isCloudConfigured($id) ? $selected : 'none';
        }
        if (str_starts_with($selected, 'local:')) {
            return self::localModelAvailable(substr($selected, 6)) ? $selected : 'none';
        }
        if (empty($binding['from_binding'])) {
            $langEngine = self::engineByLanguage($cloudBlocked);
            if ($langEngine !== null) {
                return $langEngine;
            }
        }
        return 'none';
    }

    private static function engineByLanguage(bool $cloudBlocked): ?string
    {
        try {
            $lang = function_exists('getUserLanguage') ? getUserLanguage() : 'en';
            $e = CloudAiService::engine('llm', $lang, false);
            if (str_starts_with($e, 'local:')) {
                return self::localModelAvailable(substr($e, 6)) ? $e : null;
            }
            if ($e !== '' && str_starts_with($e, 'cloud:')) {
                $id = substr($e, 6);
                if ((!$cloudBlocked || self::isLocalProvider($id)) && self::isCloudConfigured($id)) {
                    return $e;
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    private static function localModelAvailable(string $id): bool
    {
        $nativeOllama = str_starts_with($id, 'ollama-');
        $model = $nativeOllama ? OllamaService::model($id) : LocalAiService::model($id);
        return ($model['kind'] ?? '') === 'llm'
            && in_array($model['state'] ?? '', $nativeOllama ? ['ready', 'installed'] : ['ready'], true);
    }

    private static function isLocalProvider(string $id): bool
    {
        try {
            $baseUrl = (string) (CloudAiService::config($id)['base_url'] ?? '');
            if ($baseUrl === '') {
                return false;
            }
            $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
            return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
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
