<?php
/** @var bool $plugin_enabled @var string $active_tab @var array $qa_settings @var array $jobs @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$tabs = [
    'settings' => ['label' => t('common.settings', 'Settings'), 'icon' => 'fa-sliders'],
    'jobs'     => ['label' => t('ai_call_analysis.tab_jobs', 'Recent Jobs'), 'icon' => 'fa-list-check'],
];

$qaCriteria = (string) ($qa_settings['criteria'] ?? '');
$qaAuto = !empty($qa_settings['auto']);
$qaDailyMax = (int) ($qa_settings['daily_max'] ?? 30);
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-chart-line', t('ai_call_analysis.title', 'Call Analysis'), 'aiCallAnalysisHelpBox'); ?>
        <?php echo uiPageHeaderActions([
            ['href' => '/ai-local', 'icon' => 'fa-microchip', 'label' => t('ai_local.title', 'Local AI'), 'class' => 'btn-secondary btn-sm'],
            ['href' => '/ai-cloud', 'icon' => 'fa-cloud', 'label' => t('ai_cloud.title', 'Cloud AI'), 'class' => 'btn-secondary btn-sm'],
        ]); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiTabs($tabs, $active_tab); ?>
    <?php echo uiHelpBoxOpen('aiCallAnalysisHelpBox', t('ai_call_analysis.title', 'Call Analysis')); ?>
        <?php echo $h(t('ai_call_analysis.help_body', 'Configure speech-to-text and language model bindings for call recording transcription, summaries, and automatic quality review (Auto-QA).')); ?>
    <?php echo uiHelpBoxClose(); ?>

<?php if (!$plugin_enabled): ?>
    <div class="alert alert-warning u-mb-12">
        <i class="fas fa-plug-circle-xmark"></i>
        <?php echo $h(t('ai_call_analysis.plugin_disabled', 'Call Analysis plugin (ai-call-analysis) is currently disabled. Enable it from Plugins to run analysis jobs.')); ?>
    </div>
<?php endif; ?>

<div data-tab-pane="settings"<?php echo $active_tab === 'settings' ? '' : ' hidden'; ?>>
        <form method="POST">
            <?php echo $csrf; ?>
            <input type="hidden" name="_tab" value="settings">
            <input type="hidden" name="save_call_analysis" value="1">

            <h3 class="card-title u-mb-12"><?php echo $h(t('ai_call_analysis.bindings_title', 'AI Model Bindings')); ?></h3>

            <div class="form-group u-mb-12">
                <label class="form-label"><?php echo $h(t('ai_call_analysis.stt_binding', 'Transcription (STT)')); ?></label>
                <?php echo AiBindingService::select('ai_call_analysis', 'stt', 'stt_model_ref'); ?>
            </div>
            <div class="form-group u-mb-16">
                <label class="form-label"><?php echo $h(t('ai_call_analysis.chat_binding', 'Summary & Auto-QA (Chat / LLM)')); ?></label>
                <?php echo AiBindingService::select('ai_call_analysis', 'chat', 'chat_model_ref'); ?>
                <small class="u-hint"><a href="/ai-cloud"><?php echo $h(t('ai_cloud.title', 'Cloud AI')); ?></a></small>
            </div>

            <hr class="u-mt-12 u-mb-16">
            <h3 class="card-title u-mb-12"><?php echo $h(t('ai_call_analysis.qa_title', 'Quality Review (Auto-QA) Criteria')); ?></h3>

            <div class="form-group u-mb-12">
                <label class="form-label" for="ca-qa-criteria"><?php echo $h(t('ai_call_analysis.qa_criteria', 'Evaluation Criteria')); ?></label>
                <textarea id="ca-qa-criteria" name="qa_criteria" class="form-control" rows="4" maxlength="3000" placeholder="<?php echo $h(t('ai_call_analysis.qa_criteria_ph', 'e.g. Greeted caller politely with company name, verified issue, and provided clear next steps.')); ?>"><?php echo $h($qaCriteria); ?></textarea>
            </div>

            <div class="u-grid-2 u-mb-12">
                <div class="form-group">
                    <label class="u-d-flex u-ai-center u-gap-8">
                        <input type="checkbox" class="u-accent" name="qa_auto" value="1"<?php echo $qaAuto ? ' checked' : ''; ?>>
                        <span><?php echo $h(t('ai_call_analysis.qa_auto', 'Automatically evaluate recorded calls')); ?></span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label" for="ca-qa-daily-max"><?php echo $h(t('ai_call_analysis.qa_daily_max', 'Daily maximum')); ?></label>
                    <input type="number" id="ca-qa-daily-max" name="qa_daily_max" class="form-control u-maxw-180" min="1" max="1000" value="<?php echo $qaDailyMax; ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"<?php echo $plugin_enabled ? '' : ' disabled'; ?>>
                <i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?>
            </button>
        </form>
    </div>

<div data-tab-pane="jobs"<?php echo $active_tab === 'jobs' ? '' : ' hidden'; ?>>
        <h3 class="card-title u-mb-12"><?php echo $h(t('ai_call_analysis.jobs_title', 'Recent Call Analysis Jobs')); ?></h3>
        <?php echo uiTableOpen([
            ['label' => 'ID'],
            ['label' => t('ai_call_analysis.col_cdr', 'CDR')],
            ['label' => t('ai_call_analysis.col_binding', 'Binding')],
            ['label' => t('common.status', 'Status')],
            ['label' => t('ai_call_analysis.col_attempts', 'Attempts')],
            ['label' => t('ai_call_analysis.col_updated', 'Updated')],
            ['label' => t('common.action', 'Action'), 'class' => 'u-text-right'],
        ], ['id' => 'ca-jobs-table', 'search' => false]); ?>
            <?php if (empty($jobs)): ?>
                <?php echo uiTableEmptyRow(7, t('common.no_records', 'No records')); ?>
            <?php else: ?>
                <?php foreach ($jobs as $j):
                    $st = (string) ($j['status'] ?? 'pending');
                    $badgeCls = match ($st) {
                        'succeeded' => 'badge-success',
                        'failed'    => 'badge-danger',
                        'running'   => 'badge-primary',
                        'cancelled' => 'badge-secondary',
                        default     => 'badge-warning',
                    };
                ?>
                    <tr>
                        <td class="u-mono u-fs-12">#<?php echo (int) $j['id']; ?></td>
                        <td class="u-mono u-fs-12"><?php echo $h($j['entity_id'] ?? ''); ?></td>
                        <td class="u-mono u-fs-11"><?php echo $h($j['binding_ref'] ?? '—'); ?></td>
                        <td>
                            <span class="badge <?php echo $badgeCls; ?>"><?php echo $h($st); ?></span>
                            <?php if (!empty($j['last_error'])): ?>
                                <div class="u-fs-11 u-danger u-mt-4"><?php echo $h(mb_substr((string) $j['last_error'], 0, 120)); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) ($j['attempts'] ?? 0); ?> / <?php echo (int) ($j['max_attempts'] ?? 3); ?></td>
                        <td class="u-fs-12 u-muted"><?php echo $h($j['updated_at'] ?? ''); ?></td>
                        <td class="u-text-right">
                            <?php if (in_array($st, ['pending', 'running'], true)): ?>
                                <form method="POST" class="u-d-inline">
                                    <?php echo $csrf; ?>
                                    <input type="hidden" name="_tab" value="jobs">
                                    <button type="submit" name="cancel_job" value="<?php echo (int) $j['id']; ?>" class="btn btn-secondary btn-sm">
                                        <?php echo $h(t('common.cancel', 'Cancel')); ?>
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
