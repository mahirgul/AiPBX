<?php

/**
 * Faz 2: Common binding & model selector helper (local_default / cloud_default / explicit / off)
 * and active usage dependency lookup for `ailocal` and `aicloud`.
 */
final class AiBindingService
{
    public const MODES = ['local_default', 'cloud_default', 'explicit', 'off'];

    public static function getBinding(string $feature, string $task, string $language = 'default', string $scopeRef = 'global'): array
    {
        try {
            $st = getDB()->prepare(
                'SELECT mode, model_ref_id FROM ai_bindings WHERE feature = ? AND scope_ref = ? AND task = ? AND language = ? LIMIT 1'
            );
            $st->execute([$feature, $scopeRef, $task, $language]);
            $row = $st->fetch();
            if ($row) {
                return [
                    'mode' => in_array($row['mode'], self::MODES, true) ? (string) $row['mode'] : 'off',
                    'model_ref_id' => $row['model_ref_id'] !== null ? (string) $row['model_ref_id'] : null,
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist before migration; return default off.
        }
        return ['mode' => 'off', 'model_ref_id' => null];
    }

    public static function saveBinding(string $feature, string $task, string $mode, ?string $modelRefId = null, string $language = 'default', string $scopeRef = 'global'): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Invalid binding mode: ' . $mode);
        }
        $ref = $mode === 'explicit' ? trim((string) $modelRefId) : null;
        if ($mode === 'explicit' && ($ref === null || $ref === '')) {
            throw new \InvalidArgumentException('Explicit binding requires model_ref_id');
        }
        $now = date('Y-m-d H:i:s');
        $st = getDB()->prepare(
            'INSERT INTO ai_bindings (feature, scope_ref, task, language, mode, model_ref_id, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), model_ref_id = VALUES(model_ref_id), updated_at = VALUES(updated_at)'
        );
        $st->execute([$feature, $scopeRef, $task, $language, $mode, $ref, $now]);
    }

    /**
     * Resolves a feature's binding (local_default / cloud_default / explicit / off)
     * into a concrete model reference ('local:...' or 'cloud:...') or null if off/unset.
     * Never silently falls back from local to cloud.
     */
    public static function resolve(string $feature, string $task, string $language = 'default', string $scopeRef = 'global'): ?string
    {
        $b = self::getBinding($feature, $task, $language, $scopeRef);
        if ($b['mode'] === 'off' && $language !== 'default') {
            $b = self::getBinding($feature, $task, 'default', $scopeRef);
        }

        $mode = $b['mode'] ?? 'off';
        if ($mode === 'off') {
            return null;
        }
        if ($mode === 'explicit') {
            $ref = trim((string) ($b['model_ref_id'] ?? ''));
            return $ref !== '' ? $ref : null;
        }
        if ($mode === 'local_default' || $mode === 'cloud_default') {
            return self::panelDefault($task, $mode === 'local_default' ? 'local' : 'cloud', $language);
        }
        return null;
    }

    /** The default model of the Local AI or Cloud AI page for a task (per language first), or null. */
    public static function panelDefault(string $task, string $scope, string $language = 'default'): ?string
    {
        $pb = $language !== 'default'
            ? self::getBinding('panel_default', $task, $language, $scope)
            : ['mode' => 'off', 'model_ref_id' => null];
        if ($pb['mode'] !== 'explicit') {
            $pb = self::getBinding('panel_default', $task, 'default', $scope);
        }
        $ref = $pb['mode'] === 'explicit' ? trim((string) ($pb['model_ref_id'] ?? '')) : '';
        return $ref !== '' ? $ref : null;
    }

    /**
     * Lists active consumer references for a scope ('local' or 'cloud') before
     * disabling the plugin or deleting a model/connection.
     *
     * @return list<array{source:string, label:string, target:string}>
     */
    public static function dependenciesForScope(string $scope): array
    {
        $deps = [];
        $prefix = $scope === 'local' ? 'local:' : 'cloud:';
        $defaultMode = $scope === 'local' ? 'local_default' : 'cloud_default';

        try {
            $rows = getDB()->query('SELECT feature, task, language, mode, model_ref_id FROM ai_bindings WHERE mode != "off"')->fetchAll();
            foreach ($rows as $r) {
                $ref = (string) ($r['model_ref_id'] ?? '');
                if ($r['mode'] === $defaultMode || ($r['mode'] === 'explicit' && str_starts_with($ref, $prefix))) {
                    $deps[] = [
                        'source' => 'ai_bindings',
                        'label' => $r['feature'] . ' (' . $r['task'] . '/' . $r['language'] . ')',
                        'target' => $r['mode'] === 'explicit' ? $ref : $r['mode'],
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $rows = getDB()->query(
                "SELECT setting_key, setting_value FROM sys_settings
                 WHERE setting_key LIKE 'ai_engine.%' OR setting_key LIKE 'ai_use.%' OR setting_key = 'assistant.model'"
            )->fetchAll();
            foreach ($rows as $r) {
                $val = trim((string) ($r['setting_value'] ?? ''));
                if ($val !== '' && str_starts_with($val, $prefix)) {
                    $deps[] = [
                        'source' => 'sys_settings',
                        'label' => (string) $r['setting_key'],
                        'target' => $val,
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        return $deps;
    }
}
