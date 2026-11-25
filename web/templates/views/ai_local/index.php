<?php
/** @var bool $plugin_enabled @var string $active_tab @var array $local_models @var array $status @var array $bindings @var array $dependencies @var string $try_output @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$tabs = [
    'ollama'      => ['label' => 'Ollama', 'icon' => 'fa-brain'],
    'huggingface' => ['label' => 'Hugging Face', 'icon' => 'fa-cubes'],
    'connections' => ['label' => t('ai_local.tab_connections', 'Connections'), 'icon' => 'fa-plug'],
    'languages'   => ['label' => t('ai_settings.tab_languages', 'Languages'), 'icon' => 'fa-language'],
    'try'         => ['label' => t('ai_settings.tab_try', 'Try'), 'icon' => 'fa-comments'],
];
$readyLlm = array_values(array_filter($local_models, fn($m) => ($m['kind'] ?? '') === 'llm' && in_array($m['state'] ?? '', ['ready', 'installed'], true)));
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-microchip', t('ai_local.title', 'Local AI'), 'aiLocalHelpBox'); ?>
        <?php
        $rtState = (string) ($status['runtime']['state'] ?? 'absent');
        $rtBadgeCls = match ($rtState) {
            'installed' => 'badge-success',
            'installing' => 'badge-warning',
            'failed' => 'badge-danger',
            default => 'badge-secondary',
        };
        ?>
        <span class="u-fs-12 u-muted" title="<?php echo $h(t('ai_models.runtime', 'Local AI Runtime')); ?>"><?php echo $h(t('ai_models.runtime', 'Local AI Runtime')); ?>:</span>
        <span id="ail-runtime-badge" class="badge <?php echo $rtBadgeCls; ?>"><?php echo $h($rtState); ?></span>
    <?php echo uiPageHeaderActions(); ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="ailApi('runtime_install')"><i class="fas fa-download"></i> <?php echo $h(t('ai_models.btn_runtime_install', 'Install Runtime')); ?></button>
        <button type="button" class="btn btn-danger btn-sm" onclick="if(confirm('Remove runtime and models?')) ailApi('runtime_remove')"><i class="fas fa-trash"></i> <?php echo $h(t('ai_models.btn_runtime_remove', 'Remove Runtime')); ?></button>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiTabs($tabs, $active_tab); ?>
    <?php echo uiHelpBoxOpen('aiLocalHelpBox', t('ai_local.title', 'Local AI')); ?>
        <?php echo $h(t('ai_local.help_body', 'Manage local speech and language models, Ollama & Hugging Face catalogs, and local Gateway testing.')); ?>
        <p class="u-mt-8 u-mb-0"><strong><?php echo $h(t('ai_local.help_gpu_title', 'GPU or CPU')); ?>:</strong>
            <?php echo $h(t('ai_local.help_gpu', 'The models use an NVIDIA GPU when the server has one, otherwise the CPU. Install the NVIDIA driver first (nvidia-smi must list the GPU), then install the runtime: the GPU packages (a few GB) are added automatically. If the GPU is added or the driver installed later, click Install Runtime again (your models are kept). If the GPU does not work, the models fall back to the CPU. To force the CPU, run systemctl edit aipbx-ai, add Environment=AIPBX_AI_DEVICE=cpu under [Service], and restart the service.')); ?></p>
        <p class="u-mt-8 u-mb-0"><strong><?php echo $h(t('ai_models.tts_settings', 'Voice settings')); ?>:</strong>
            <?php echo $h(t('ai_local.help_tts', 'On the Hugging Face tab, open "Voice settings" under a downloaded voice to set its speed, its speaker (voices with several speakers) and the pause between sentences.')); ?></p>
    <?php echo uiHelpBoxClose(); ?>

<?php if (!$plugin_enabled): ?>
    <div class="alert alert-warning u-mb-12">
        <i class="fas fa-plug-circle-xmark"></i>
        <?php echo $h(t('ai_local.plugin_disabled', 'Local AI plugin (ailocal) is currently disabled. Enable it from Plugins to run local models.')); ?>
    </div>
<?php endif; ?>

<!-- TAB: OLLAMA CATALOG -->
<?php
$olState = (string) ($status['ollama']['state'] ?? 'absent');
$olRunning = in_array($olState, ['installed', 'ready', 'running'], true);
?>
<div data-tab-pane="ollama"<?php echo $active_tab === 'ollama' ? '' : ' hidden'; ?>>
        <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-8 u-mb-12">
            <div>
                <strong>Ollama:</strong>
                <span id="ail-ollama-badge" class="badge <?php echo $olRunning ? 'badge-success' : 'badge-secondary'; ?>"><?php echo $h($olState); ?></span>
                <span class="u-fs-12 u-muted u-ml-8"><?php echo $h(t('ai_local.ollama_hint', 'Pull a model below, then use Run / Stop per model.')); ?></span>
            </div>
            <div class="u-d-flex u-gap-8">
                <button type="button" class="btn btn-secondary btn-sm" onclick="ailLoadOllama(true)"><i class="fas fa-sync-alt"></i> <?php echo $h(t('ai_local.refresh_list', 'Refresh list')); ?></button>
                <button type="button" id="ail-ollama-install-btn" class="btn btn-primary btn-sm" style="<?php echo $olRunning ? 'display:none;' : ''; ?>" onclick="ailApi('ollama_install')"><i class="fas fa-download"></i> <?php echo $h(t('ai_models.btn_ollama_install', 'Install Ollama')); ?></button>
            </div>
        </div>
        <?php echo uiTableOpen([
            ['label' => t('ai_models.col_model', 'Model')],
            ['label' => t('ai_models.col_type', 'Type')],
            ['label' => t('ai_models.col_parameters', 'Parameters')],
            ['label' => t('ai_models.col_size', 'Size')],
            ['label' => t('ai_models.col_state', 'State')],
            ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
        ], ['id' => 'ail-ol-table', 'body_id' => 'ail-ol-tbody', 'attrs' => 'data-no-dt="true"']); ?>
        <?php echo uiTableEmptyRow(6, t('common.loading', 'Loading...'), 'fa-spinner fa-spin'); ?>
        <?php echo uiTableClose(); ?>
</div>

<!-- TAB: HUGGING FACE: only small speech/embedding models the local runtime can run -->
<div data-tab-pane="huggingface"<?php echo $active_tab === 'huggingface' ? '' : ' hidden'; ?>>
        <div class="alert alert-info u-mb-12 u-fs-12">
            <i class="fas fa-circle-info"></i>
            <?php echo $h(t('ai_local.hf_runnable_body', 'Only small models that run on this server are listed: recommended models, Piper voices (TTS), Whisper in CTranslate2 (STT) and ONNX sentence models. Language models are on the Ollama tab.')); ?>
        </div>
        <?php $hfToken = LocalAiService::hfToken(); ?>
        <form method="POST" autocomplete="off" class="u-d-flex u-gap-8 u-wrap u-ai-center u-mb-12">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="huggingface">
            <input type="hidden" name="save_hf_token" value="1">
            <label class="u-fs-12 u-fw-600" for="ail-hf-token"><?php echo $h(t('ai_local.hf_token')); ?></label>
            <input type="password" id="ail-hf-token" name="hf_token" class="form-control form-control-sm u-maxw-260" autocomplete="new-password"
                   placeholder="<?php echo $h($hfToken !== '' ? SecretBox::mask($hfToken) : 'hf_...'); ?>">
            <button type="submit" class="btn btn-secondary btn-sm"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?></button>
            <?php if ($hfToken !== ''): ?>
                <button type="submit" name="clear_hf_token" value="1" class="btn btn-secondary btn-sm"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-trash"></i> <?php echo $h(t('common.clear', 'Clear')); ?></button>
            <?php endif; ?>
            <small class="u-muted u-fs-11"><?php echo $h(t('ai_local.hf_token_help')); ?></small>
        </form>
        <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-8 u-mb-12">
            <div class="u-d-flex u-gap-8 u-wrap">
                <input type="search" id="ail-hf-query" class="form-control form-control-sm u-maxw-260" placeholder="<?php echo $h(t('ai_local.hf_search_placeholder', 'Name, language (tr, de, en) or Hub search...')); ?>" value="" onkeydown="if(event.key==='Enter'){event.preventDefault();ailLoadHf(false);}">
                <select id="ail-hf-kind" class="form-control form-control-sm u-w-auto" onchange="ailRenderHf()">
                    <option value=""><?php echo $h(t('ai_models.filter_all_kinds', 'All kinds')); ?></option>
                    <option value="tts">TTS</option><option value="stt">STT</option><option value="embedding">Embedding</option>
                </select>
                <button type="button" class="btn btn-secondary btn-sm" onclick="ailLoadHf(false)"><i class="fas fa-search"></i></button>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="ailLoadHf(true)"><i class="fas fa-sync-alt"></i> <?php echo $h(t('ai_local.refresh_list', 'Refresh list')); ?></button>
        </div>
        <?php echo uiTableOpen([
            ['label' => t('ai_models.col_model', 'Model')],
            ['label' => t('ai_models.col_type', 'Type')],
            ['label' => t('ai_local.col_language', 'Language')],
            ['label' => t('ai_models.col_size', 'Size')],
            ['label' => t('ai_models.col_state', 'State')],
            ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
        ], ['id' => 'ail-hf-table', 'body_id' => 'ail-hf-tbody', 'attrs' => 'data-no-dt="true"']); ?>
        <?php echo uiTableEmptyRow(6, t('common.loading', 'Loading...'), 'fa-spinner fa-spin'); ?>
        <?php echo uiTableClose(); ?>
</div>

<?php
$taskKinds = ['chat' => 'llm', 'stt' => 'stt', 'tts' => 'tts'];
$taskLabels = ['chat' => 'Chat / LLM', 'stt' => 'STT', 'tts' => 'TTS'];
$localOptions = static function (string $task, string $selected, string $emptyLabel) use ($local_models, $taskKinds, $h): string {
    $kind = $taskKinds[$task] ?? $task;
    $out = '<option value="">' . $h($emptyLabel) . '</option>';
    foreach ($local_models as $m) {
        if (($m['kind'] ?? 'llm') !== $kind) continue;
        $ref = 'local:' . $m['id'];
        // Only downloaded models can be a default; a saved choice whose files are gone stays visible, marked.
        $installed = in_array($m['state'] ?? '', ['installed', 'loading', 'ready'], true);
        if (!$installed && $selected !== $ref) continue;
        $sel = $selected === $ref ? ' selected' : '';
        $title = ($m['title'] ?? $m['id']) . ' [Cascade]' . ($installed ? '' : ' (' . t('ai_local.not_downloaded', 'not downloaded') . ')');
        $out .= '<option value="' . $h($ref) . '"' . $sel . '>' . $h($title) . '</option>';
    }
    return $out;
};
?>

<!-- TAB: CONNECTIONS / DEFAULT BINDINGS -->
<div data-tab-pane="connections"<?php echo $active_tab === 'connections' ? '' : ' hidden'; ?>>
        <h3 class="card-title u-mb-12"><?php echo $h(t('ai_local.defaults_title', 'Default Local Bindings')); ?></h3>
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="connections">
            <input type="hidden" name="save_local_defaults" value="1">
            <?php foreach ($taskLabels as $task => $label):
                $b = $bindings[$task] ?? ['mode' => 'off', 'model_ref_id' => ''];
                $selVal = ($b['mode'] ?? '') === 'explicit' ? (string) ($b['model_ref_id'] ?? '') : '';
            ?>
                <div class="form-group u-mb-12">
                    <label class="form-label"><?php echo $h($label); ?></label>
                    <select name="model_<?php echo $h($task); ?>" class="form-control u-maxw-320">
                        <?php echo $localOptions($task, $selVal, '-- ' . t('ai_selector.off', 'Off') . ' --'); ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?></button>
        </form>
</div>

<!-- TAB: LANGUAGES -->
<div data-tab-pane="languages"<?php echo $active_tab === 'languages' ? '' : ' hidden'; ?>>
    <div class="u-mb-16">
        <h3 class="card-title u-mb-8"><i class="fas fa-language u-primary"></i> <?php echo $h(t('ai_cloud.engines_title', 'Language Overrides')); ?></h3>
        <p class="u-muted u-fs-12 u-mt-0 u-mb-10"><?php echo $h(t('ai_cloud.engines_help', 'Optional per-language local models. Leave empty to use the Default Local Binding.')); ?></p>
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="languages">
            <input type="hidden" name="save_local_languages" value="1">
            <?php echo uiTableOpen([
                ['label' => t('ai_cloud.lang_name', 'Language')],
                ['label' => 'Chat / LLM'],
                ['label' => 'STT'],
                ['label' => 'TTS'],
            ], ['search' => false]); ?>
                <?php foreach ($languages as $code => $langInfo): ?>
                    <tr>
                        <td><strong><?php echo $h($langInfo['name']); ?></strong> <span class="u-muted u-fs-11 u-mono">(<?php echo $h($code); ?>)</span></td>
                        <?php foreach ($taskLabels as $task => $label):
                            $lb = $lang_bindings[$code][$task] ?? ['mode' => 'off', 'model_ref_id' => ''];
                            $lVal = ($lb['mode'] ?? '') === 'explicit' ? (string) ($lb['model_ref_id'] ?? '') : '';
                        ?>
                            <td>
                                <select name="lang_model[<?php echo $h($code); ?>][<?php echo $h($task); ?>]" class="form-control form-control-sm">
                                    <?php echo $localOptions($task, $lVal, '-- ' . t('ai_cloud.engine_none', 'Use default') . ' --'); ?>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
            <div class="u-mt-12">
                <button type="submit" class="btn btn-primary"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?></button>
            </div>
        </form>
    </div>

    <div>
        <h3 class="card-title u-mb-8"><i class="fas fa-globe u-primary"></i> <?php echo $h(t('ai_cloud.languages_title', 'AI Languages')); ?></h3>
        <p class="u-muted u-fs-12 u-mt-0 u-mb-10"><?php echo $h(t('ai_cloud.languages_help', 'Manage languages available for STT, TTS, and language-specific model overrides.')); ?></p>
        <?php echo uiTableOpen([
            ['label' => t('ai_cloud.lang_code', 'Code')],
            ['label' => t('ai_cloud.lang_locale', 'Locale')],
            ['label' => t('ai_cloud.lang_name', 'Name')],
            ['label' => '', 'class' => 'u-text-right'],
        ], ['search' => false]); ?>
            <?php foreach ($languages as $code => $langInfo): ?>
                <tr>
                    <td class="u-mono"><?php echo $h($code); ?></td>
                    <td class="u-mono"><?php echo $h($langInfo['locale']); ?></td>
                    <td><?php echo $h($langInfo['name']); ?></td>
                    <td class="u-text-right">
                        <?php if (count($languages) > 1): ?>
                            <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('ai_cloud.lang_confirm_delete', 'Delete this language?'))); ?>)">
                                <?php echo $csrf; ?>
                                <input type="hidden" name="_tab" value="languages">
                                <button type="submit" name="delete_language" value="<?php echo $h($code); ?>" class="btn btn-icon btn-danger btn-xs"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-trash-alt"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
        <form method="POST" class="u-d-flex u-gap-8 u-wrap u-mt-10 u-ai-center">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="languages">
            <input type="text" name="lang_code" class="form-control form-control-sm u-w-80" maxlength="3" required pattern="[a-z]{2,3}" placeholder="fr" title="<?php echo $h(t('ai_cloud.lang_code', 'Code')); ?>">
            <input type="text" name="lang_locale" class="form-control form-control-sm u-maxw-130" maxlength="20" placeholder="fr-FR" title="<?php echo $h(t('ai_cloud.lang_locale', 'Locale')); ?>">
            <input type="text" name="lang_name" class="form-control form-control-sm u-maxw-180" maxlength="40" required placeholder="French" title="<?php echo $h(t('ai_cloud.lang_name', 'Name')); ?>">
            <button type="submit" name="add_language" value="1" class="btn btn-primary btn-sm"<?php echo $plugin_enabled ? '' : ' disabled'; ?>><i class="fas fa-plus"></i> <?php echo $h(t('ai_cloud.lang_add', 'Add language')); ?></button>
        </form>
    </div>
</div>

<!-- TAB: TRY -->
<div data-tab-pane="try"<?php echo $active_tab === 'try' ? '' : ' hidden'; ?>>
        <h3 class="card-title u-mb-12"><?php echo $h(t('ai_local.try_title', 'Try Local Chat')); ?></h3>
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="try">
            <input type="hidden" name="try_local_chat" value="1">
            <div class="form-group u-mb-10">
                <label class="form-label"><?php echo $h(t('ai_models.col_model', 'Model')); ?></label>
                <select name="try_model" class="form-control" required>
                    <?php foreach ($readyLlm as $m): ?>
                        <option value="<?php echo $h('local:' . $m['id']); ?>"><?php echo $h($m['title'] ?? $m['id']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group u-mb-10">
                <label class="form-label"><?php echo $h(t('assistant.placeholder', 'Prompt')); ?></label>
                <input type="text" name="try_prompt" class="form-control" value="<?php echo $h($_POST['try_prompt'] ?? ''); ?>" required>
            </div>
            <button type="submit" class="btn btn-primary"<?php echo ($plugin_enabled && !empty($readyLlm)) ? '' : ' disabled'; ?>><i class="fas fa-play"></i> <?php echo $h(t('ai_settings.tab_try', 'Try')); ?></button>
        </form>
        <?php if ($try_output !== ''): ?>
            <div class="u-mt-12 u-p-12 u-r-8 u-bd-1-solid-border-color u-bg-bg-input">
                <strong><?php echo $h(t('ai_models.try_answer', 'Answer')); ?>:</strong>
                <div class="u-mt-6 u-white-space-pre-wrap"><?php echo $h($try_output); ?></div>
            </div>
        <?php endif; ?>
</div>
</div>

<?php if (!empty($dependencies)): ?>
    <div class="card u-mt-12">
        <div class="u-fs-12 u-fw-700 u-mb-6"><i class="fas fa-link u-primary"></i> <?php echo $h(t('ai_local.active_deps', 'Active local consumers')); ?></div>
        <div class="u-d-flex u-wrap u-gap-6">
            <?php foreach ($dependencies as $dep): ?>
                <span class="badge badge-info u-fs-11"><?php echo $h($dep['label'] . ' → ' . $dep['target']); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<script>window.AI_LOCAL = <?php echo json_encode([
    'csrf' => $csrf_token,
    'models' => array_values($local_models),
    't_no_records' => t('common.no_records', 'No records'),
    't_badge_recommended' => t('ai_local.badge_recommended', 'Recommended'),
    't_btn_download' => t('ai_models.btn_download', 'Download'),
    't_btn_cancel_download' => t('ai_models.btn_cancel_download', 'Cancel download'),
    't_btn_run' => t('ai_models.btn_run', 'Run'),
    't_btn_stop' => t('ai_models.btn_stop', 'Stop'),
    't_confirm_model_remove' => t('ai_models.confirm_model_remove', 'Remove this model and its files?'),
    't_tts_settings' => t('ai_models.tts_settings', 'Voice settings'),
    't_tts_speed' => t('ai_models.tts_speed', 'Speed'),
    't_tts_speaker' => t('ai_models.tts_speaker', 'Speaker'),
    't_tts_pause' => t('ai_models.tts_pause', 'Pause between sentences'),
    't_tts_pause_short' => t('ai_models.tts_pause_short', 'Short'),
    't_tts_pause_normal' => t('ai_models.tts_pause_normal', 'Normal'),
    't_tts_pause_long' => t('ai_models.tts_pause_long', 'Long'),
    't_tts_start' => t('ai_models.tts_start_for_speakers', 'Start the voice to choose a speaker.'),
    't_btn_save' => t('common.save', 'Save'),
    't_license' => t('ai_local.license', 'License'),
    't_noncommercial' => t('ai_local.noncommercial', 'not for commercial use'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;</script>
<script src="<?php echo asset('/assets/js/ai_local.js'); ?>"></script>
