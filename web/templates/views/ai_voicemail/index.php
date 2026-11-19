<?php
/** @var bool $plugin_enabled @var bool $can_edit @var string $active_tab @var bool $transcribe_on @var bool $summary_on @var array $bindings @var ?string $resolved_stt @var ?string $resolved_chat @var array $stt_options @var array $chat_options @var array $languages @var array $lang_bindings @var array $jobs @var bool $local_enabled @var bool $cloud_enabled @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$tabs = [
    'settings'  => ['label' => t('ai_voicemail.tab_settings', 'Settings & Bindings'), 'icon' => 'fa-sliders'],
    'languages' => ['label' => t('ai_settings.tab_languages', 'Languages'), 'icon' => 'fa-language'],
    'jobs'      => ['label' => t('ai_voicemail.tab_jobs', 'Recent Jobs'), 'icon' => 'fa-list-check'],
];
$modes = [
    'off'           => t('ai_selector.off', 'Off'),
    'local_default' => t('ai_selector.local_default', 'Local default'),
    'cloud_default' => t('ai_selector.cloud_default', 'Cloud default'),
    'explicit'      => t('ai_selector.explicit', 'Explicit model'),
];
$disabledAttr = ($plugin_enabled && $can_edit) ? '' : ' disabled';
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-voicemail', t('ai_voicemail.title', 'Voicemail AI'), 'aiVoicemailHelpBox'); ?>
        <?php echo uiPageHeaderActions([
            ['label' => t('ai_local.title', 'Local AI'), 'href' => '/ai-local', 'icon' => 'fa-microchip', 'class' => 'btn-secondary'],
            ['label' => t('ai_cloud.title', 'Cloud AI'), 'href' => '/ai-cloud', 'icon' => 'fa-cloud', 'class' => 'btn-secondary'],
        ]); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('aiVoicemailHelpBox', t('ai_voicemail.title', 'Voicemail AI')); ?>
        <?php echo $h(t('ai_voicemail.help_body', 'Configure automatic speech-to-text transcription and one-sentence summaries for voicemail messages. Choose local default, cloud default, explicit model, or off without silent local-to-cloud fallback.')); ?>
    <?php echo uiHelpBoxClose(); ?>
</div>

<?php if (!$plugin_enabled): ?>
    <div class="alert alert-warning u-mb-12">
        <i class="fas fa-plug-circle-xmark"></i>
        <?php echo $h(t('ai_voicemail.plugin_disabled', 'Voicemail AI plugin (ai-voicemail) is currently disabled. Enable it from Plugins to process voicemails.')); ?>
    </div>
<?php endif; ?>

<?php echo uiTabs($tabs, $active_tab); ?>

<!-- TAB: SETTINGS & BINDINGS -->
<div data-tab-pane="settings"<?php echo $active_tab === 'settings' ? '' : ' hidden'; ?>>
    <div class="card">
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="settings">
            <input type="hidden" name="save_voicemail_ai" value="1">

            <div class="u-mb-16">
                <label class="u-d-flex u-ai-center u-gap-8 u-mb-8">
                    <input type="checkbox" name="ai_vm_transcribe" value="1"<?php echo $transcribe_on ? ' checked' : ''; ?><?php echo $disabledAttr; ?>>
                    <span><strong><?php echo $h(t('ai_voicemail.enable_transcribe', 'Transcribe incoming voicemails')); ?></strong></span>
                </label>
                <label class="u-d-flex u-ai-center u-gap-8">
                    <input type="checkbox" name="ai_vm_summary" value="1"<?php echo $summary_on ? ' checked' : ''; ?><?php echo $disabledAttr; ?>>
                    <span><strong><?php echo $h(t('ai_voicemail.enable_summary', 'Generate one-sentence voicemail summary')); ?></strong></span>
                </label>
                <div class="u-muted u-fs-12 u-mt-4">
                    <?php echo $h(t('ai_voicemail.notify_note', 'Voicemail e-mail and push notifications remain independent and are always delivered even if AI fails or is disabled.')); ?>
                </div>
            </div>

            <?php
            $bindingRows = [
                'stt' => [
                    'title'    => t('ai_voicemail.stt_binding', 'Speech-to-Text (STT)'),
                    'binding'  => $bindings['stt'] ?? ['mode' => 'off', 'model_ref_id' => null],
                    'resolved' => $resolved_stt,
                    'options'  => $stt_options,
                ],
                'chat' => [
                    'title'    => t('ai_voicemail.chat_binding', 'Summary Language Model (Chat)'),
                    'binding'  => $bindings['chat'] ?? ['mode' => 'off', 'model_ref_id' => null],
                    'resolved' => $resolved_chat,
                    'options'  => $chat_options,
                ],
            ];
            ?>
            <?php echo uiTableOpen([
                ['label' => t('ai_voicemail.col_task', 'Task')],
                ['label' => t('ai_voicemail.col_mode', 'Binding Mode')],
                ['label' => t('ai_voicemail.col_explicit', 'Explicit Model')],
                ['label' => t('ai_voicemail.col_resolved', 'Effective Engine')],
            ], ['search' => false]); ?>
                <?php foreach ($bindingRows as $task => $info):
                    $mode = (string) ($info['binding']['mode'] ?? 'off');
                    $ref  = (string) ($info['binding']['model_ref_id'] ?? '');
                ?>
                    <tr>
                        <td><strong><?php echo $h($info['title']); ?></strong></td>
                        <td>
                            <select name="<?php echo $h($task); ?>_mode" class="form-control form-control-sm aivm-mode" data-task="<?php echo $h($task); ?>"<?php echo $disabledAttr; ?>>
                                <?php foreach ($modes as $mKey => $mLabel): ?>
                                    <option value="<?php echo $h($mKey); ?>"<?php echo $mode === $mKey ? ' selected' : ''; ?>><?php echo $h($mLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?php echo $h($task); ?>_model_ref" class="form-control form-control-sm aivm-ref" data-task="<?php echo $h($task); ?>"<?php echo $mode === 'explicit' ? '' : ' disabled'; ?><?php echo $disabledAttr; ?>>
                                <option value="">-- <?php echo $h(t('ai_cloud.engine_none', 'Select model')); ?> --</option>
                                <?php foreach ($info['options'] as $opt): ?>
                                    <option value="<?php echo $h($opt['ref']); ?>"<?php echo $ref === $opt['ref'] ? ' selected' : ''; ?>><?php echo $h($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="u-mono u-fs-12">
                            <?php if ($info['resolved'] !== null): ?>
                                <span class="badge badge-success"><?php echo $h($info['resolved']); ?></span>
                            <?php else: ?>
                                <span class="badge badge-secondary"><?php echo $h(t('common.off', 'Off / Unset')); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>

            <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-8 u-mt-12">
                <div class="u-fs-12 u-muted">
                    <?php echo $h(t('ai_voicemail.defaults_hint', 'Default local and cloud models are managed on')); ?>
                    <a href="/ai-local"><?php echo $h(t('ai_local.title', 'Local AI')); ?></a>
                    (<?php echo $local_enabled ? $h(t('common.enabled', 'Enabled')) : $h(t('common.disabled', 'Disabled')); ?>)
                    ·
                    <a href="/ai-cloud"><?php echo $h(t('ai_cloud.title', 'Cloud AI')); ?></a>
                    (<?php echo $cloud_enabled ? $h(t('common.enabled', 'Enabled')) : $h(t('common.disabled', 'Disabled')); ?>)
                </div>
                <button type="submit" class="btn btn-primary"<?php echo $disabledAttr; ?>>
                    <i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TAB: LANGUAGES -->
<div data-tab-pane="languages"<?php echo $active_tab === 'languages' ? '' : ' hidden'; ?>>
    <div class="card">
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="languages">
            <input type="hidden" name="save_voicemail_languages" value="1">
            <p class="u-muted u-fs-12 u-mt-0 u-mb-10">
                <?php echo $h(t('ai_voicemail.languages_help', 'Optional per-language overrides for voicemail STT and summary models. Leave as Off to use the default binding above.')); ?>
            </p>
            <?php echo uiTableOpen([
                ['label' => t('ai_cloud.lang_name', 'Language')],
                ['label' => 'STT Mode'],
                ['label' => 'STT Model'],
                ['label' => 'Summary Mode'],
                ['label' => 'Summary Model'],
            ], ['search' => false]); ?>
                <?php foreach ($languages as $code => $langInfo): ?>
                    <tr>
                        <td><strong><?php echo $h($langInfo['name']); ?></strong> <span class="u-muted u-fs-11 u-mono">(<?php echo $h($code); ?>)</span></td>
                        <?php foreach (['stt' => $stt_options, 'chat' => $chat_options] as $task => $opts):
                            $lb = $lang_bindings[$code][$task] ?? ['mode' => 'off', 'model_ref_id' => ''];
                            $lMode = (string) ($lb['mode'] ?? 'off');
                            $lRef = (string) ($lb['model_ref_id'] ?? '');
                        ?>
                            <td>
                                <select name="lang_mode[<?php echo $h($code); ?>][<?php echo $h($task); ?>]" class="form-control form-control-sm"<?php echo $disabledAttr; ?>>
                                    <?php foreach ($modes as $mKey => $mLabel): ?>
                                        <option value="<?php echo $h($mKey); ?>"<?php echo $lMode === $mKey ? ' selected' : ''; ?>><?php echo $h($mLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="lang_model[<?php echo $h($code); ?>][<?php echo $h($task); ?>]" class="form-control form-control-sm"<?php echo $disabledAttr; ?>>
                                    <option value="">-- <?php echo $h(t('ai_cloud.engine_none', 'Use default')); ?> --</option>
                                    <?php foreach ($opts as $opt): ?>
                                        <option value="<?php echo $h($opt['ref']); ?>"<?php echo $lRef === $opt['ref'] ? ' selected' : ''; ?>><?php echo $h($opt['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>
            <div class="u-mt-12">
                <button type="submit" class="btn btn-primary"<?php echo $disabledAttr; ?>>
                    <i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TAB: RECENT JOBS -->
<div data-tab-pane="jobs"<?php echo $active_tab === 'jobs' ? '' : ' hidden'; ?>>
    <div class="card">
        <h3 class="card-title u-mb-12"><i class="fas fa-list-check u-primary"></i> <?php echo $h(t('ai_voicemail.tab_jobs', 'Recent Voicemail AI Jobs')); ?></h3>
        <?php echo uiTableOpen([
            ['label' => 'ID'],
            ['label' => t('ai_jobs.col_entity', 'Voicemail')],
            ['label' => t('ai_jobs.col_binding', 'Binding')],
            ['label' => t('ai_jobs.col_status', 'Status')],
            ['label' => t('ai_jobs.col_attempts', 'Attempts')],
            ['label' => t('ai_jobs.col_error', 'Last Error')],
            ['label' => t('ai_jobs.col_updated', 'Updated')],
            ['label' => '', 'class' => 'u-text-right'],
        ]); ?>
            <?php if (empty($jobs)): ?>
                <?php echo uiTableEmptyRow(8, t('common.no_records', 'No jobs recorded yet.')); ?>
            <?php else: ?>
                <?php foreach ($jobs as $job):
                    $status = (string) ($job['status'] ?? 'pending');
                    $badge = match ($status) {
                        'succeeded' => 'success',
                        'running'   => 'info',
                        'failed'    => 'danger',
                        'cancelled' => 'secondary',
                        default     => 'warning',
                    };
                ?>
                    <tr>
                        <td class="u-mono">#<?php echo (int) $job['id']; ?></td>
                        <td class="u-mono u-fs-12"><?php echo $h($job['entity_type'] . ':' . $job['entity_id']); ?></td>
                        <td class="u-mono u-fs-12"><?php echo $h($job['binding_ref'] ?? '-'); ?></td>
                        <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $h($status); ?></span></td>
                        <td><?php echo (int) $job['attempts']; ?> / <?php echo (int) $job['max_attempts']; ?></td>
                        <td class="u-fs-12 u-maxw-260"><?php echo $h($job['last_error'] ?? '-'); ?></td>
                        <td class="u-fs-12 u-nowrap"><?php echo $h($job['updated_at'] ?? ''); ?></td>
                        <td class="u-text-right">
                            <?php if ($can_edit && in_array($status, ['pending', 'running'], true)): ?>
                                <form method="POST" class="u-inline">
                                    <?php echo $csrf; ?>
                                    <input type="hidden" name="_tab" value="jobs">
                                    <input type="hidden" name="cancel_job_id" value="<?php echo (int) $job['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-xs" title="<?php echo $h(t('common.cancel', 'Cancel')); ?>">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.aivm-mode').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var task = sel.getAttribute('data-task');
            var ref = document.querySelector('.aivm-ref[data-task="' + task + '"]');
            if (ref) {
                ref.disabled = (sel.value !== 'explicit');
            }
        });
    });
})();
</script>
