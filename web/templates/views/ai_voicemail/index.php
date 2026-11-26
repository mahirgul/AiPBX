<?php
/** @var bool $plugin_enabled @var bool $can_edit @var string $active_tab @var bool $transcribe_on @var bool $summary_on @var array $bindings @var ?string $resolved_stt @var ?string $resolved_chat @var array $stt_options @var array $chat_options @var array $languages @var array $lang_bindings @var array $jobs @var bool $local_enabled @var bool $cloud_enabled @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$tabs = [
    'settings' => ['label' => t('ai_voicemail.tab_settings', 'Settings'), 'icon' => 'fa-sliders'],
    'jobs'     => ['label' => t('ai_voicemail.tab_jobs', 'Jobs'), 'icon' => 'fa-list-check', 'badge' => (string) count($jobs)],
];
$disabledAttr = ($plugin_enabled && $can_edit) ? '' : ' disabled';
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-voicemail', t('ai_voicemail.title', 'Voicemail AI'), 'aiVoicemailHelpBox'); ?>
        <?php echo uiPageHeaderActions([
            ['label' => t('ai_cloud.title', 'Cloud AI'), 'href' => '/ai-cloud', 'icon' => 'fa-cloud', 'class' => 'btn-secondary btn-sm'],
        ]); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiTabs($tabs, $active_tab); ?>
    <?php echo uiHelpBoxOpen('aiVoicemailHelpBox', t('ai_voicemail.title', 'Voicemail AI')); ?>
        <?php echo $h(t('ai_voicemail.help_body', 'Transcribe new voicemail messages and optionally summarize them in e-mail notifications.')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <?php if (!$plugin_enabled): ?>
        <div class="alert alert-warning u-mb-12">
            <i class="fas fa-plug-circle-xmark"></i>
            <?php echo $h(t('ai_voicemail.plugin_disabled', 'Voicemail AI plugin is disabled.')); ?>
        </div>
    <?php endif; ?>

    <!-- TAB: SETTINGS -->
    <div data-tab-pane="settings"<?php echo $active_tab === 'settings' ? '' : ' hidden'; ?>>
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
                    <span><strong><?php echo $h(t('ai_voicemail.enable_summary', 'Summarize voicemail transcripts')); ?></strong></span>
                </label>
            </div>

            <?php echo uiTableOpen([
                ['label' => t('ai_voicemail.col_task', 'Task')],
                ['label' => t('ai_voicemail.col_explicit', 'Model')],
            ], ['search' => false]); ?>
                <?php foreach (['stt' => t('ai_voicemail.stt_binding', 'Speech-to-Text (STT)'), 'chat' => t('ai_voicemail.chat_binding', 'Summary Language Model (Chat)')] as $task => $title): ?>
                    <tr>
                        <td><strong><?php echo $h($title); ?></strong></td>
                        <td><?php echo AiBindingService::select('ai_voicemail', $task, $task . '_model_ref', $disabledAttr); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php echo uiTableClose(); ?>

            <div class="u-d-flex u-jc-flex-end u-mt-12">
                <button type="submit" class="btn btn-primary"<?php echo $disabledAttr; ?>>
                    <i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?>
                </button>
            </div>
        </form>

    </div>

    <!-- TAB: RECENT JOBS -->
    <div data-tab-pane="jobs"<?php echo $active_tab === 'jobs' ? '' : ' hidden'; ?>>
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
