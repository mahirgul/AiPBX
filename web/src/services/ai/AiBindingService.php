<?php

/**
 * What each AI feature uses: one explicit account or model per feature and task (voicemail STT, call-analysis
 * summary, the PBX Assistant, reminders ...). There is no default model and no per-language override: a feature
 * with nothing chosen is off, and its page pre-selects the first usable one when the administrator first sets it up.
 */
final class AiBindingService
{
    /** The model a feature uses for a task ('stt', 'chat', 'tts', 'embed'), or null when it is off. */
    public static function resolve(string $feature, string $task, string $scopeRef = 'global'): ?string
    {
        try {
            $st = getDB()->prepare('SELECT model_ref_id FROM ai_bindings WHERE feature = ? AND scope_ref = ? AND task = ? AND language = ? AND mode = ? LIMIT 1');
            $st->execute([$feature, $scopeRef, $task, 'default', 'explicit']);
            $ref = trim((string) $st->fetchColumn());
        } catch (\Throwable $e) {
            return null;
        }
        return $ref !== '' ? $ref : null;
    }

    /** Whether the administrator ever chose (or switched off) this feature's model. */
    public static function isConfigured(string $feature, string $task, string $scopeRef = 'global'): bool
    {
        try {
            $st = getDB()->prepare('SELECT 1 FROM ai_bindings WHERE feature = ? AND scope_ref = ? AND task = ? LIMIT 1');
            $st->execute([$feature, $scopeRef, $task]);
            return $st->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Chooses the model of a feature ('' or null: off). */
    public static function save(string $feature, string $task, ?string $ref, string $scopeRef = 'global'): void
    {
        $ref = trim((string) $ref);
        if ($ref !== '' && !isset(self::options($task)[$ref])) {
            throw new \InvalidArgumentException(t('builtin_ai_agent.err_model'));
        }
        getDB()->prepare(
            'INSERT INTO ai_bindings (feature, scope_ref, task, language, mode, model_ref_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), model_ref_id = VALUES(model_ref_id), updated_at = VALUES(updated_at)'
        )->execute([$feature, $scopeRef, $task, 'default', $ref !== '' ? 'explicit' : 'off', $ref !== '' ? $ref : null]);
    }

    /**
     * What a feature page offers for a task: [ref => label], local voices that are installed and cloud accounts that
     * can do it. @return array<string, string>
     */
    public static function options(string $task): array
    {
        require_once __DIR__ . '/BuiltinAiAgentService.php';
        return BuiltinAiAgentService::models()[$task === 'chat' ? 'llm' : $task] ?? ($task === 'embed' ? self::embedOptions() : []);
    }

    /** @return array<string, string> */
    private static function embedOptions(): array
    {
        $out = [];
        if (AiAppService::isScopeEnabled('cloud')) {
            foreach (CloudAiService::withCap('embed') as $account) {
                $out['cloud:' . $account] = CloudAiService::provider($account)['title'];
            }
        }
        return $out;
    }

    /** The model a feature page shows selected: the saved choice, or (never set up) the first usable one. */
    public static function current(string $feature, string $task, string $scopeRef = 'global'): string
    {
        if (self::isConfigured($feature, $task, $scopeRef)) {
            return (string) self::resolve($feature, $task, $scopeRef);
        }
        return (string) (array_key_first(self::options($task)) ?? '');
    }

    /** `<select>` with "off" and every usable model; the first usable one is selected for a feature never set up. */
    public static function select(string $feature, string $task, string $name, string $attrs = ''): string
    {
        $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES);
        $current = self::current($feature, $task);
        $html = '<select name="' . $h($name) . '" class="form-control form-control-sm"' . $attrs . '><option value="">' . $h(t('ai_selector.off', 'Off')) . '</option>';
        foreach (self::options($task) as $ref => $label) {
            $html .= '<option value="' . $h($ref) . '"' . ($ref === $current ? ' selected' : '') . '>' . $h($label) . '</option>';
        }
        return $html . '</select>';
    }

    /**
     * Who uses an account or voice before it is deleted or its plugin disabled.
     * @return list<array{source:string, label:string, target:string}>
     */
    public static function dependenciesForScope(string $scope): array
    {
        $deps = [];
        $prefix = $scope === 'local' ? 'local:' : 'cloud:';
        try {
            foreach (getDB()->query("SELECT feature, task, model_ref_id FROM ai_bindings WHERE mode = 'explicit'")->fetchAll() as $r) {
                if (str_starts_with((string) $r['model_ref_id'], $prefix)) {
                    $deps[] = ['source' => 'ai_bindings', 'label' => $r['feature'] . ' (' . $r['task'] . ')', 'target' => (string) $r['model_ref_id']];
                }
            }
        } catch (\Throwable $e) {
        }
        return $deps;
    }
}
