<?php
$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
$selected = static fn ($value, $current): string => (string) $value === (string) $current ? ' selected' : '';
$collectionNames = array_column($collections, 'name', 'id');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-headset', t('builtin_ai_agent.title'), 'builtinAgentHelp'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <button type="button" class="btn btn-secondary btn-sm" id="builtinAgentClearCache" title="<?php echo $h(t('builtin_ai_agent.clear_cache_help')); ?>"
            data-confirm="<?php echo $h(t('builtin_ai_agent.clear_cache_confirm')); ?>" data-csrf="<?php echo $h($csrf_token); ?>"><i class="fas fa-eraser"></i> <?php echo $h(t('builtin_ai_agent.clear_cache')); ?></button>
    <a href="/ai-agent?export=csv" class="btn btn-secondary btn-sm" title="<?php echo $h(t('builtin_ai_agent.export_help')); ?>"><i class="fas fa-file-csv"></i> CSV</a>
    <a href="/ai-agent?new=1" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> <?php echo $h(t('ai_agents.btn_new')); ?></a>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('builtinAgentHelp', t('builtin_ai_agent.title')); ?>
    <p><?php echo $h(t('builtin_ai_agent.help')); ?> <a href="/ai-knowledge"><?php echo $h(t('ai_apps.field_knowledge')); ?></a></p>
    <code><?php echo $h(t('builtin_ai_agent.tool_example')); ?></code>
    <?php echo uiHelpBoxClose(); ?>
    <?php echo uiTabs([
        'wizard' => ['label' => t('builtin_ai_agent.wizard_title'), 'icon' => 'fa-list-check', 'badge' => sprintf('%d/%d', $wizard['done'], $wizard['total'])],
        'agents' => ['label' => t('builtin_ai_agent.tab_agents'), 'icon' => 'fa-headset', 'badge' => (string) count($agents)],
        'calls'  => ['label' => t('builtin_ai_agent.calls_title'), 'icon' => 'fa-clock-rotate-left'],
    ], $tab); ?>

    <div data-tab-pane="wizard"<?php echo $tab === 'wizard' ? '' : ' hidden'; ?>>
        <?php require __DIR__ . '/wizard_panel.php'; ?>
    </div>

    <div data-tab-pane="agents"<?php echo $tab === 'agents' ? '' : ' hidden'; ?>>
    <?php echo uiTableOpen([
        t('ai_apps.col_title'), t('ai_apps.col_number'), t('ai_apps.field_knowledge'), t('ai_agents.col_fallback'),
        sprintf(t('builtin_ai_agent.stats_col'), BuiltinAiAgentService::STATS_DAYS), '',
    ], ['frame' => 'u-card-pad']); ?>
    <?php if (!$agents): ?><?php echo uiTableEmptyRow(6, t('common.no_records')); ?><?php endif; ?>
    <?php $pct = static fn (array $s, string $k): string => $s['calls'] > 0 ? (string) round(100 * $s[$k] / $s['calls']) : '0'; ?>
    <?php foreach ($agents as $agent): ?>
        <?php $cfg = AiAppService::config($agent); ?>
        <tr>
            <td><?php echo $h($agent['title']); ?>
                <?php $broken = BuiltinAiAgentService::brokenTransfers($cfg); if ($broken): ?>
                    <span class="badge badge-danger u-fs-10" title="<?php echo $h(sprintf(t('builtin_ai_agent.broken_help'), implode(', ', $broken))); ?>"><i class="fas fa-triangle-exclamation"></i> <?php echo $h(t('builtin_ai_agent.broken')); ?></span>
                <?php endif; ?></td>
            <td><?php echo $h($agent['internal_number'] ?? '—'); ?></td>
            <td><?php echo $h(implode(', ', array_filter(array_map(static fn ($i) => $collectionNames[(int) $i] ?? '', (array) ($cfg['knowledge_collections'] ?? [])))) ?: '—'); ?></td>
            <td><?php echo $h($targets[$agent['dest_type']][$agent['dest_id']] ?? $agent['dest_id']); ?></td>
            <td class="u-fs-12">
                <?php $s = $stats[(int) $agent['id']] ?? null; ?>
                <?php if ($s === null): ?>—<?php else: ?>
                    <strong><?php echo (int) $s['calls']; ?></strong>
                    <span class="u-muted"><?php echo $h(sprintf(t('builtin_ai_agent.stats_line'), $pct($s, 'handed'), $pct($s, 'ended'), $pct($s, 'missed'), $pct($s, 'hangup'), (string) $s['turns'])); ?></span>
                <?php endif; ?>
            </td>
            <td class="u-ta-right u-nowrap">
                <a href="/ai-agent?edit=<?php echo (int) $agent['id']; ?>" class="btn btn-icon btn-secondary btn-sm" title="<?php echo $h(t('common.edit')); ?>"><i class="fas fa-pen"></i></a>
                <?php echo uiDeleteForm((int) $agent['id'], 'agent_id', 'delete_agent', t('ai_agents.confirm_delete')); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
<?php if ($edit !== null): ?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/ai_agent.css'); ?>">
<?php
$mode = ($edit['mode'] ?? '') === 'realtime' ? 'realtime' : (($edit['mode'] ?? '') === 'cascade' ? 'cascade' : (BuiltinAiAgentService::realtimeAccounts() ? 'realtime' : 'cascade'));
$rtAccounts = BuiltinAiAgentService::realtimeAccounts();
$destType = (string) ($edit['dest_type'] ?? 'hangup');
$destId = (string) ($edit['dest_id'] ?? '');
$destModules = \PBX\Destinations\DestinationRegistry::getModules();
$languages = CloudAiService::languages();
uasort($languages, static fn ($a, $b) => strcmp($a['name'], $b['name']));          // by name in the language picker
$agentLang = (string) ($edit['language'] ?? $edit['stt_lang'] ?? '');            // a refused save comes back with what was posted
$agentLang = isset($languages[$agentLang]) ? $agentLang : CloudAiService::language('');
[$srcTargets, $srcQueries, $srcBases, $srcPbx, $srcLangs] = BuiltinAiAgentService::instructionSources();
$typeNames = ['extension' => t('builtin_ai_agent.tt_extension'), 'queue' => t('builtin_ai_agent.tt_queue'), 'ring_group' => t('builtin_ai_agent.tt_ring_group')];
$targetOptions = [];                                   // what the transfer picker inserts: 1001, queue:<id>, group:<id>
foreach ($srcTargets as $type => $list) {
    foreach ($list as $id => $name) {
        $targetOptions[] = ['value' => ($type === 'extension' ? '' : ($type === 'queue' ? 'queue:' : 'group:')) . $id, 'label' => $typeNames[$type] . ' · ' . $name];
    }
}
$palette = ['targets' => $srcTargets, 'queries' => $srcQueries, 'bases' => array_map(static fn ($b) => $b['description'], $srcBases),
    'pbx' => $srcPbx, 'pbxOff' => BuiltinAiAgentService::TOOLS_OFF_BY_DEFAULT, 'languages' => $srcLangs, 'words' => AgentInstructions::WORDS, 'reserved' => AgentInstructions::RESERVED];
$openAll = isset($_POST['save_agent']);          // a refused save opens every card, so the problem is seen
$instrValue = array_key_exists('instructions', $edit) ? (string) $edit['instructions'] : ($default_instructions ?? '');
?>
<div class="u-mt-12">
    <form method="post" action="/ai-agent" id="builtinAgentForm" data-open-all="<?php echo $openAll ? 1 : 0; ?>" data-warn-tokens="<?php echo (int) BuiltinAiAgentService::WARN_TOKENS; ?>"
          data-palette="<?php echo $h(json_encode($palette, JSON_UNESCAPED_UNICODE)); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
        <input type="hidden" name="id" value="<?php echo (int) ($edit['id'] ?? 0); ?>">
        <details class="agent-card" data-card="name"<?php echo $openAll ? ' open' : ''; ?>>
            <summary><i class="fas fa-id-card u-primary"></i> <strong><?php echo $h(t('builtin_ai_agent.card_name')); ?></strong> <span class="agent-card-hint u-muted u-fs-12" data-hint="name"></span></summary>
            <div class="agent-card-body">
        <div class="u-d-grid u-gtc-1fr-1fr u-gap-8">
            <div class="form-group">
                <label class="form-label" for="builtin_agent_title"><?php echo $h(t('ai_apps.field_title')); ?></label>
                <input class="form-control" id="builtin_agent_title" name="title" maxlength="100" required value="<?php echo $h($edit['title'] ?? ''); ?>">
            </div>
            <div class="u-d-grid u-gtc-1fr-1fr u-gap-8">
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_number"><?php echo $h(t('ai_apps.field_number')); ?></label>
                    <input class="form-control" id="builtin_agent_number" name="internal_number" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6" value="<?php echo $h($edit['internal_number'] ?? ''); ?>">
                </div>
            </div>
        </div>
            </div>
        </details>
        <details class="agent-card" data-card="model"<?php echo $openAll ? ' open' : ''; ?>>
            <summary><i class="fas fa-microchip u-primary"></i> <strong><?php echo $h(t('builtin_ai_agent.card_model')); ?></strong> <span class="agent-card-hint u-muted u-fs-12" data-hint="mode"></span></summary>
            <div class="agent-card-body">
        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo $h(t('builtin_ai_agent.mode')); ?></label>
            <div class="u-flex-gap">
                <?php foreach (['realtime' => 'mode_realtime', 'cascade' => 'mode_cascade'] as $m => $key): ?>
                    <label class="u-check-label"><input type="radio" class="u-accent" name="mode" value="<?php echo $m; ?>" data-agent-mode<?php echo $mode === $m ? ' checked' : ''; ?>> <?php echo $h(t('builtin_ai_agent.' . $key)); ?></label>
                <?php endforeach; ?>
            </div>
            <small class="u-hint"><?php echo $h(t('builtin_ai_agent.mode_help')); ?></small>
        </div>

        <div class="u-d-grid u-gtc-1fr-1fr u-gap-8">
            <div class="form-group" data-agent-pane="realtime"<?php echo $mode === 'realtime' ? '' : ' hidden'; ?>>
                <label class="form-label" for="builtin_agent_realtime_account"><?php echo $h(t('builtin_ai_agent.realtime_account')); ?></label>
                <select class="form-control" id="builtin_agent_realtime_account" name="realtime_account">
                    <option value=""><?php echo $h(t('builtin_ai_agent.select')); ?></option>
                    <?php foreach ($rtAccounts as $ref => $label): ?>
                        <option value="<?php echo $h($ref); ?>"<?php echo $selected($ref, $edit['realtime_account'] ?? array_key_first($rtAccounts)); ?>><?php echo $h($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="u-hint"><?php echo $h(t('builtin_ai_agent.realtime_account_help')); ?> <a href="/ai-cloud"><?php echo $h(t('builtin_ai_agent.cloud_link')); ?></a></small>
            </div>
        </div>
        <div class="u-d-grid u-gtc-1fr-1fr u-gap-8" data-agent-pane="cascade"<?php echo $mode === 'cascade' ? '' : ' hidden'; ?>>
            <?php foreach (['understand' => ['llm', t('builtin_ai_agent.model')], 'stt_model' => ['stt', t('builtin_ai_agent.stt')], 'model_id' => ['tts', t('builtin_ai_agent.tts')]] as $field => [$kind, $label]): ?>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_<?php echo $h($field); ?>"><?php echo $h($label); ?></label>
                    <select class="form-control" id="builtin_agent_<?php echo $h($field); ?>" name="<?php echo $h($field); ?>">
                        <?php $current = (string) ($edit[$field] ?? ''); ?>
                        <?php if ($current === '' || !isset($models[$kind][$current])): ?><option value=""><?php echo $h(t('builtin_ai_agent.select')); ?></option><?php endif; ?>
                        <?php foreach ($models[$kind] as $ref => $name): ?>
                            <option value="<?php echo $h($ref); ?>"<?php echo $selected($ref, $current !== '' ? $current : array_key_first($models[$kind])); ?>><?php echo $h($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
            </div>
        </details>
        <details class="agent-card" data-card="instructions"<?php echo $openAll ? ' open' : ''; ?>>
            <summary><i class="fas fa-pen-to-square u-primary"></i> <strong><?php echo $h(t('builtin_ai_agent.card_instructions')); ?></strong> <span class="agent-card-hint u-muted u-fs-12" data-hint="instructions"></span></summary>
            <div class="agent-card-body">
        <div class="form-group u-mt-12 ai-editor-box" id="builtinAgentInstrBox">
            <div class="u-flex-between u-mb-6 u-wrap u-gap-8">
                <label class="form-label u-mb-0" for="builtin_agent_instructions"><?php echo $h(t('builtin_ai_agent.instructions')); ?></label>
                <div class="u-flex-gap">
                    <span class="u-fs-12 u-muted" id="agentInstrCount" data-warn="<?php echo $h(t('builtin_ai_agent.instructions_long')); ?>"></span>
                    <button type="button" class="btn btn-secondary btn-xs" data-agent-act="fullscreen"><i class="fas fa-expand"></i> <?php echo $h(t('builtin_ai_agent.btn_fullscreen')); ?></button>
                    <button type="button" class="btn btn-secondary btn-xs" data-agent-act="copy"><i class="fas fa-copy"></i> <?php echo $h(t('builtin_ai_agent.btn_copy')); ?></button>
                    <button type="button" class="btn btn-secondary btn-xs" data-agent-act="reset" data-default="<?php echo $h($default_instructions ?? ''); ?>"><i class="fas fa-rotate-left"></i> <?php echo $h(t('builtin_ai_agent.btn_reset')); ?></button>
                </div>
            </div>
            <div class="u-d-flex u-wrap u-gap-6 u-mb-6">
                <?php foreach (['transfer' => 'fa-share', 'greeting' => 'fa-hand', 'knowledge' => 'fa-book-open', 'db' => 'fa-database', 'http' => 'fa-globe', 'pbx' => 'fa-phone-volume', 'waiting' => 'fa-hourglass-half', 'language' => 'fa-language', 'barge_in' => 'fa-hand', 'announcements' => 'fa-bullhorn', 'email' => 'fa-envelope'] as $word => $icon): ?>
                    <button type="button" class="btn btn-primary btn-xs" data-palette-open="<?php echo $word; ?>"><i class="fas <?php echo $icon; ?>"></i> <?php echo $h(t('builtin_ai_agent.add_' . $word)); ?></button>
                <?php endforeach; ?>
                <span class="u-muted u-fs-12 u-ml-6"><?php echo $h(t('builtin_ai_agent.variables')); ?></span>
                <span id="agentInstrVars" class="u-d-flex u-wrap u-gap-6">
                    <?php foreach (BuiltinAiAgentService::INSTRUCTION_VARS as $vChip): ?>
                        <span role="button" tabindex="0" draggable="true" class="btn btn-secondary btn-xs u-mono" data-insert-var="<?php echo $h($vChip); ?>" title="<?php echo $h(t('builtin_ai_agent.var_hint')); ?>"><?php echo $h($vChip); ?></span>
                    <?php endforeach; ?>
                </span>
            </div>
            <div class="agent-palette" id="agentPalette" hidden>
                <div data-palette="transfer" hidden>
                    <input class="form-control form-control-sm" data-p="name" placeholder="<?php echo $h(t('builtin_ai_agent.tr_id')); ?>">
                    <select class="form-control form-control-sm" data-p="target">
                        <?php foreach ($targetOptions as $o): ?><option value="<?php echo $h($o['value']); ?>"><?php echo $h($o['label']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div data-palette="greeting" hidden>
                    <select class="form-control form-control-sm" data-p="lang">
                        <?php foreach ($languages as $code => $l): ?><option value="<?php echo $h($code); ?>"<?php echo $selected($code, $agentLang); ?>><?php echo $h($l['name']); ?></option><?php endforeach; ?>
                    </select>
                    <input class="form-control form-control-sm" data-p="text" maxlength="250" placeholder="<?php echo $h(t('builtin_ai_agent.greeting_placeholder')); ?>">
                </div>
                <div data-palette="knowledge" hidden>
                    <select class="form-control form-control-sm" data-p="base">
                        <?php foreach ($srcBases as $name => $b): ?><option value="<?php echo $h($name); ?>"><?php echo $h($name . ' — ' . $b['description']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div data-palette="db" hidden>
                    <select class="form-control form-control-sm" data-p="query">
                        <?php foreach ($srcQueries as $key => $desc): ?><option value="<?php echo $h($key); ?>"><?php echo $h($key . ' — ' . $desc); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div data-palette="http" hidden>
                    <input class="form-control form-control-sm" data-p="name" placeholder="<?php echo $h(t('builtin_ai_agent.tr_id')); ?>">
                    <input class="form-control form-control-sm" data-p="url" placeholder="https://crm.example.com/api?no={order_no}">
                </div>
                <div data-palette="pbx" hidden>
                    <select class="form-control form-control-sm" data-p="tool">
                        <option value="*"><?php echo $h(t('builtin_ai_agent.opt_pbx_all')); ?></option>
                        <?php foreach ($srcPbx as $tool): ?><option value="<?php echo $h($tool); ?>"><?php echo $h($tool); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div data-palette="language" hidden>
                    <select class="form-control form-control-sm" data-p="lang">
                        <?php foreach ($languages as $code => $l): ?><option value="<?php echo $h($code); ?>"<?php echo $selected($code, $agentLang); ?>><?php echo $h($l['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div data-palette="barge_in" hidden>
                    <select class="form-control form-control-sm" data-p="on"><option value="on"><?php echo $h(t('builtin_ai_agent.opt_barge_in_on')); ?></option><option value="off"><?php echo $h(t('builtin_ai_agent.opt_barge_in_off')); ?></option></select>
                </div>
                <div data-palette="announcements" hidden>
                    <select class="form-control form-control-sm" data-p="on"><option value="on"><?php echo $h(t('builtin_ai_agent.opt_announcements_on')); ?></option><option value="off"><?php echo $h(t('builtin_ai_agent.opt_announcements_off')); ?></option></select>
                </div>
                <div data-palette="email" hidden>
                    <input type="text" class="form-control form-control-sm" data-p="mail" maxlength="254" placeholder="calls@example.com">
                </div>
                <div data-palette="waiting" hidden>
                    <input class="form-control form-control-sm" data-p="text" maxlength="120" placeholder="<?php echo $h(t('builtin_ai_agent.waiting_placeholder')); ?>">
                </div>
                <button type="button" class="btn btn-primary btn-xs" data-palette-insert><i class="fas fa-plus"></i> <?php echo $h(t('builtin_ai_agent.insert')); ?></button>
                <button type="button" class="btn btn-secondary btn-xs" data-palette-close><?php echo $h(t('common.cancel')); ?></button>
            </div>
            <small class="u-hint u-mb-6"><?php echo $h(t('builtin_ai_agent.instructions_help')); ?></small>
            <textarea class="form-control u-mono" id="builtin_agent_instructions" name="instructions" rows="18" maxlength="<?php echo (int) BuiltinAiAgentService::MAX_INSTRUCTIONS; ?>"><?php echo $h($instrValue); ?></textarea>
            <div class="agent-found u-mt-6" id="agentFound" aria-live="polite"
                 data-title="<?php echo $h(t('builtin_ai_agent.found_title')); ?>" data-none="<?php echo $h(t('builtin_ai_agent.found_none')); ?>"
                 data-unknown="<?php echo $h(t('builtin_ai_agent.why_target')); ?>" data-ambiguous="<?php echo $h(t('builtin_ai_agent.why_ambiguous')); ?>"
                 data-format="<?php echo $h(t('builtin_ai_agent.why_format')); ?>" data-name="<?php echo $h(t('builtin_ai_agent.why_name')); ?>"
                 data-reserved="<?php echo $h(t('builtin_ai_agent.why_reserved')); ?>" data-language="<?php echo $h(t('builtin_ai_agent.why_language')); ?>"
                 data-email="<?php echo $h(t('builtin_ai_agent.why_email')); ?>"
                 data-unknown-item="<?php echo $h(t('builtin_ai_agent.why_unknown')); ?>" data-line="<?php echo $h(t('builtin_ai_agent.found_line')); ?>"></div>
        </div>
            </div>
        </details>
        <details class="agent-card" data-card="fallback"<?php echo $openAll ? ' open' : ''; ?>>
            <summary><i class="fas fa-life-ring u-primary"></i> <strong><?php echo $h(t('builtin_ai_agent.card_fallback')); ?></strong> <span class="agent-card-hint u-muted u-fs-12" data-hint="fallback"></span></summary>
            <div class="agent-card-body">
        <div class="u-d-grid u-gtc-1fr-1fr u-gap-8 u-mt-6">
            <div class="form-group">
                <label class="form-label" for="agent_dest_type"><?php echo $h(t('builtin_ai_agent.fallback')); ?></label>
                <select class="form-control" id="agent_dest_type" name="dest_type" data-dest-target="agent_dest_id" data-dest-selected="<?php echo $h($destId); ?>">
                    <?php foreach ($destModules as $mod): if ($mod->getKey() === 'builtin_ai_agent') { continue; } ?>
                        <option value="<?php echo $h($mod->getKey()); ?>"<?php echo $destType === $mod->getKey() ? ' selected' : ''; ?>><?php echo $h($mod->getName()); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="agent_dest_id">&nbsp;</label>
                <select class="form-control" id="agent_dest_id" name="dest_id"></select>
            </div>
        </div>
            </div>
        </details>
        <details class="agent-card" id="builtinAgentAdvanced" data-card="advanced"<?php echo $openAll ? ' open' : ''; ?>>
            <summary><i class="fas fa-sliders u-primary"></i> <strong><?php echo $h(t('builtin_ai_agent.card_advanced')); ?></strong></summary>
            <div data-agent-pane="cascade"<?php echo $mode === 'cascade' ? '' : ' hidden'; ?>>
            <div class="agent-card-body">
            <?php $listen = BuiltinAiAgentService::listening(is_array($edit) ? $edit : []);
            $seconds = static fn (int $n): string => sprintf(t('builtin_ai_agent.seconds'), $n); ?>
            <div class="u-d-grid u-gtc-1fr-1fr u-gap-8 u-mt-6">
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_line_profile"><?php echo $h(t('builtin_ai_agent.line_profile')); ?></label>
                    <select class="form-control" id="builtin_agent_line_profile" name="line_profile">
                        <?php foreach (BuiltinAiAgentService::LINE_PROFILES as $lp): ?>
                            <option value="<?php echo $h($lp); ?>"<?php echo $selected($lp, $listen['line_profile']); ?>><?php echo $h(t('builtin_ai_agent.line_' . $lp)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_barge_profile"><?php echo $h(t('builtin_ai_agent.barge_profile')); ?></label>
                    <select class="form-control" id="builtin_agent_barge_profile" name="barge_profile">
                        <?php foreach (['noisy' => 'barge_low', 'normal' => 'barge_normal', 'faint' => 'barge_high'] as $bp => $key): ?>
                            <option value="<?php echo $h($bp); ?>"<?php echo $selected($bp, $listen['barge_profile']); ?>><?php echo $h(t('builtin_ai_agent.' . $key)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_retries"><?php echo $h(t('builtin_ai_agent.retries')); ?></label>
                    <select class="form-control" id="builtin_agent_retries" name="retries">
                        <?php foreach ([0 => 'retries_0', 1 => 'retries_1', 2 => 'retries_2'] as $n => $key): ?>
                            <option value="<?php echo $n; ?>"<?php echo $selected($n, $listen['retries']); ?>><?php echo $h(t('builtin_ai_agent.' . $key)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_silence_seconds"><?php echo $h(t('builtin_ai_agent.silence_seconds')); ?></label>
                    <select class="form-control" id="builtin_agent_silence_seconds" name="silence_seconds">
                        <?php foreach ([3, 4, 6, 8, 10] as $n): ?>
                            <option value="<?php echo $n; ?>"<?php echo $selected($n, $listen['silence_seconds']); ?>><?php echo $h($seconds($n)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_listen_seconds"><?php echo $h(t('builtin_ai_agent.listen_seconds')); ?></label>
                    <select class="form-control" id="builtin_agent_listen_seconds" name="listen_seconds">
                        <?php foreach ([5, 8, 10, 12, 15] as $n): ?>
                            <option value="<?php echo $n; ?>"<?php echo $selected($n, $listen['listen_seconds']); ?>><?php echo $h($seconds($n)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <small class="u-hint"><?php echo $h(t('builtin_ai_agent.listening_help')); ?></small>
            <?php $missing = ' — ' . t('builtin_ai_agent.detector_missing'); ?>
            <div class="u-d-grid u-gtc-1fr-1fr u-gap-8 u-mt-12">
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_speech_detection"><?php echo $h(t('builtin_ai_agent.speech_detection')); ?></label>
                    <select class="form-control" id="builtin_agent_speech_detection" name="speech_detection">
                        <option value="standard"<?php echo $selected('standard', $listen['speech_detection']); ?>><?php echo $h(t('builtin_ai_agent.speech_standard')); ?></option>
                        <option value="silero"<?php echo $selected('silero', $listen['speech_detection']); echo ($detectors['vad'] || $listen['speech_detection'] === 'silero') ? '' : ' disabled'; ?>><?php echo $h(t('builtin_ai_agent.speech_silero') . ($detectors['vad'] ? '' : $missing)); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="builtin_agent_end_of_turn"><?php echo $h(t('builtin_ai_agent.end_of_turn')); ?></label>
                    <select class="form-control" id="builtin_agent_end_of_turn" name="end_of_turn">
                        <option value="fixed"<?php echo $selected('fixed', $listen['end_of_turn']); ?>><?php echo $h(t('builtin_ai_agent.turn_fixed')); ?></option>
                        <option value="smart"<?php echo $selected('smart', $listen['end_of_turn']); echo ($detectors['turn'] || $listen['end_of_turn'] === 'smart') ? '' : ' disabled'; ?>><?php echo $h(t('builtin_ai_agent.turn_smart') . ($detectors['turn'] ? '' : $missing)); ?></option>
                    </select>
                </div>
            </div>
            <small class="u-hint"><?php echo $h(t('builtin_ai_agent.detectors_help')); ?> <a href="/ai-local"><?php echo $h(t('builtin_ai_agent.detectors_link')); ?></a></small>
            <div class="form-group u-mt-12">
                <label class="u-check-label">
                    <input type="checkbox" class="u-accent" name="end_on_goodbye" value="1" aria-describedby="builtinAgentGoodbyeHelp"<?php echo !empty($listen['end_on_goodbye']) ? ' checked' : ''; ?>>
                    <?php echo $h(t('builtin_ai_agent.end_on_goodbye')); ?>
                </label>
                <small class="u-hint" id="builtinAgentGoodbyeHelp"><?php echo $h(t('builtin_ai_agent.end_on_goodbye_help')); ?></small>
            </div>
            </div>
            </div>
        </details>
        <div class="u-flex-gap u-mt-12">
            <?php echo uiSaveButton(null, 'name="save_agent" value="1"'); ?>
            <a href="/ai-agent" class="btn btn-secondary"><?php echo $h(t('common.cancel')); ?></a>
        </div>
    </form>
</div>
<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
<?php endif; ?>
    </div>

    <div data-tab-pane="calls"<?php echo $tab === 'calls' ? '' : ' hidden'; ?>>
<?php
$appTitles = array_column(array_merge($agents, AiAppService::all(false)), 'title', 'id');
// Normal endings are green or grey; a call that failed, timed out or was not understood is red.
$resultClass = static fn (string $r, bool $notes): string => match (true) {
    in_array($r, ['expired', 'error', 'not_understood', 'timeout'], true) => 'badge-danger',
    $notes => 'badge-warning',
    in_array($r, ['matched', 'played', 'done', 'saved'], true) => 'badge-success',
    default => 'badge-secondary',
};
?>
<div>
    <h3 class="card-title u-mb-8"><i class="fas fa-clock-rotate-left u-primary"></i> <?php echo $h(t('builtin_ai_agent.calls_title')); ?></h3>
    <p class="u-muted u-fs-12 u-mt-0"><?php echo $h(t('builtin_ai_agent.calls_help')); ?></p>
    <?php echo uiTableOpen([
        t('builtin_ai_agent.calls_time'), t('ai_apps.col_title'), t('builtin_ai_agent.calls_caller'), t('builtin_ai_agent.calls_result'),
        t('builtin_ai_agent.calls_timing'), t('builtin_ai_agent.calls_said'), t('builtin_ai_agent.calls_problems'), '',
    ], ['frame' => 'u-card-pad', 'attrs' => 'data-no-dt="true"']); ?>
    <?php foreach ($ai_calls as $c): ?>
        <?php $late = (int) ($c['late_frames'] ?? 0); $notes = (array) ($c['notes'] ?? []); ?>
        <tr>
            <td class="u-nowrap u-fs-12"><?php echo $h(date('d.m H:i:s', (int) ($c['started'] ?? 0))); ?></td>
            <td><?php echo $h($appTitles[$c['app'] ?? 0] ?? ('#' . (int) ($c['app'] ?? 0))); ?></td>
            <td class="u-fs-12"><?php echo $h($c['caller'] ?? '—'); ?></td>
            <td><span class="badge <?php echo $resultClass((string) ($c['result'] ?? ''), $notes !== []); ?>"><?php echo $h($c['result'] ?? '—'); ?></span></td>
            <td class="u-fs-11 u-mono">
                <?php echo $h(t('builtin_ai_agent.calls_prepared')); ?> <?php echo (int) ($c['prepare_ms'] ?? 0); ?> ms ·
                <?php echo $h(t('builtin_ai_agent.calls_played')); ?> <?php echo $h($c['played_seconds'] ?? 0); ?>/<?php echo $h($c['audio_seconds'] ?? 0); ?> s
                <?php $turns = array_filter((array) ($c['turns'] ?? []), 'is_array'); ?>
                <?php if ($turns !== []): ?>
                    <?php $avg = static function (string $k) use ($turns): string { $v = array_values(array_filter(array_column($turns, $k), static fn($x): bool => $x !== null)); return $v !== [] ? (string) (int) round(array_sum($v) / count($v)) : '—'; }; ?>
                    <br><span title="<?php echo $h(t('builtin_ai_agent.calls_turns_help')); ?>"><?php echo $h(sprintf(t('builtin_ai_agent.calls_turns'), count($turns), $avg('stt_ms'), $avg('llm_ms'), $avg('tts_ms'), $avg('first_audio_ms'))); ?></span>
                <?php endif; ?>
                <?php if ($late > 0): ?><br><span class="u-danger"><?php echo $h(sprintf(t('builtin_ai_agent.calls_late'), $late, (int) ($c['max_late_ms'] ?? 0), (int) ($c['resyncs'] ?? 0))); ?></span><?php endif; ?>
            </td>
            <td class="u-fs-12"><?php echo $h(implode(' · ', array_map('strval', (array) ($c['transcripts'] ?? []))) ?: '—'); ?></td>
            <td class="u-fs-12"><?php foreach ($notes as $n): ?><div class="u-danger"><?php echo $h(($n[0] ?? 0) . ' s: ' . ($n[1] ?? '')); ?></div><?php endforeach; ?><?php echo $notes ? '' : '—'; ?></td>
            <td class="u-ta-right"><?php if (preg_match('/^[0-9a-f-]{36}$/', (string) ($c['uuid'] ?? ''))): ?><a href="/ai-agent?diag=<?php echo $h($c['uuid']); ?>" class="btn btn-icon btn-secondary btn-sm" title="<?php echo $h(t('builtin_ai_agent.calls_report_help')); ?>"><i class="fas fa-file-arrow-down"></i></a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php echo uiTableClose(); ?>
</div>
        <?php if (empty($ai_calls)): ?><p class="u-muted"><?php echo $h(t('common.no_records')); ?></p><?php endif; ?>
    </div>
</div>
