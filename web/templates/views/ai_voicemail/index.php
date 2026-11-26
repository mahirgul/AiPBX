<?php
/** @var bool $plugin_enabled @var bool $can_edit @var string $active_tab @var bool $transcribe_on @var bool $summary_on @var array $bindings @var ?string $resolved_stt @var ?string $resolved_chat @var array $stt_options @var array $chat_options @var array $languages @var array $lang_bindings @var array $history @var string $summary_lang @var bool $local_enabled @var bool $cloud_enabled @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
$tabs = [
    'settings' => ['label' => t('ai_voicemail.tab_settings', 'Settings'), 'icon' => 'fa-sliders'],
    'history'  => ['label' => t('ai_voicemail.tab_history', 'History'), 'icon' => 'fa-clock-rotate-left', 'badge' => (string) count($history)],
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

            <div class="form-group u-mt-12">
                <label class="form-label" for="ai_vm_summary_lang"><?php echo $h(t('ai_voicemail.summary_lang')); ?></label>
                <select name="ai_vm_summary_lang" id="ai_vm_summary_lang" class="form-control u-maxw-260"<?php echo $disabledAttr; ?>>
                    <option value=""<?php echo $summary_lang === '' ? ' selected' : ''; ?>><?php echo $h(t('ai_voicemail.summary_lang_message')); ?></option>
                    <?php $langs = AiLanguages::TABLE; uasort($langs, static fn ($a, $b) => strcmp($a['name'], $b['name'])); ?>
                    <?php foreach ($langs as $code => $l): ?><option value="<?php echo $h($code); ?>"<?php echo $summary_lang === $code ? ' selected' : ''; ?>><?php echo $h($l['name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="u-d-flex u-jc-flex-end u-mt-12">
                <button type="submit" class="btn btn-primary"<?php echo $disabledAttr; ?>>
                    <i class="fas fa-save"></i> <?php echo $h(t('common.save', 'Save')); ?>
                </button>
            </div>
        </form>

    </div>

    <!-- TAB: HISTORY: queued, running and failed jobs and finished transcripts, one table -->
    <div data-tab-pane="history"<?php echo $active_tab === 'history' ? '' : ' hidden'; ?>>
        <?php echo uiTableOpen([
            ['label' => t('ai_voicemail.col_time', 'Time')],
            ['label' => t('ai_voicemail.col_mailbox', 'Mailbox')],
            ['label' => t('ai_jobs.col_status', 'Status')],
            ['label' => t('ai_voicemail.col_result', 'Summary / transcript')],
            ['label' => t('ai_jobs.col_binding', 'Model'), 'class' => 'col-hide-mobile'],
            ['label' => '', 'class' => 'u-text-right'],
        ]); ?>
            <?php if (empty($history)): ?>
                <?php echo uiTableEmptyRow(6, t('ai_voicemail.empty_history', 'No voicemail has been processed yet.'), 'fa-voicemail'); ?>
            <?php else: ?>
                <?php foreach ($history as $row):
                    $status = (string) $row['status'];
                    $badge = match ($status) { 'succeeded' => 'success', 'running' => 'info', 'failed' => 'danger', 'cancelled' => 'secondary', default => 'warning' };
                ?>
                    <tr>
                        <td class="u-fs-12 u-nowrap"><?php echo $h($row['time']); ?>
                            <?php if ($row['origtime'] > 0): ?><div class="u-muted u-fs-11"><?php echo $h(date('Y-m-d H:i', $row['origtime'])); ?></div><?php endif; ?></td>
                        <td><span class="badge badge-info"><?php echo $h($row['mailbox']); ?></span></td>
                        <td><span class="badge badge-<?php echo $badge; ?>"><?php echo $h($status); ?></span>
                            <?php if ($row['attempts'] !== '' && $status !== 'succeeded'): ?><div class="u-muted u-fs-11"><?php echo $h($row['attempts']); ?></div><?php endif; ?></td>
                        <td class="u-fs-13">
                            <?php if ($row['summary'] !== ''): ?><div><?php echo nl2br($h($row['summary'])); ?></div><?php endif; ?>
                            <?php if ($row['transcript'] !== ''): ?>
                                <details class="u-mt-4"><summary class="u-muted u-fs-11 u-pointer"><?php echo $h(t('ai_voicemail.transcript', 'Transcript')); ?></summary>
                                    <div class="u-muted u-fs-12 u-mt-4"><?php echo nl2br($h($row['transcript'])); ?></div></details>
                            <?php endif; ?>
                            <?php if ($row['error'] !== ''): ?><div class="u-danger u-fs-12"><?php echo $h($row['error']); ?></div><?php endif; ?>
                        </td>
                        <td class="u-mono u-fs-12 col-hide-mobile"><?php echo $h($row['engine'] !== '' ? $row['engine'] : '-'); ?></td>
                        <td class="u-text-right">
                            <?php if ($can_edit && $row['job_id'] > 0 && in_array($status, ['pending', 'running'], true)): ?>
                                <form method="POST" class="u-inline">
                                    <?php echo $csrf; ?>
                                    <input type="hidden" name="_tab" value="history">
                                    <input type="hidden" name="cancel_job_id" value="<?php echo (int) $row['job_id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-xs" title="<?php echo $h(t('common.cancel', 'Cancel')); ?>"><i class="fas fa-ban"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php echo uiTableClose(); ?>
    </div>
</div>
