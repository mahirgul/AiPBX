<?php
/** @var bool $plugin_enabled @var array $local_models @var array $status @var array $dependencies @var string $csrf_token */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$csrf = '<input type="hidden" name="csrf_token" value="' . $h($csrf_token) . '">';
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-microchip', t('ai_local.title', 'Local TTS'), 'aiLocalHelpBox'); ?>
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
        <?php echo uiHelpBoxOpen('aiLocalHelpBox', t('ai_local.title', 'Local TTS')); ?>
        <?php echo $h(t('ai_local.help_body')); ?>
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

<?php echo uiTableOpen([
    ['label' => t('ai_models.col_model', 'Model')],
    ['label' => t('ai_models.col_type', 'Type')],
    ['label' => t('ai_local.col_language', 'Language')],
    ['label' => t('ai_models.col_size', 'Size')],
    ['label' => t('ai_models.col_state', 'State')],
    ['label' => t('ai_models.col_actions', 'Actions'), 'class' => 'u-text-right'],
], ['id' => 'ail-table', 'body_id' => 'ail-tbody', 'attrs' => 'data-no-dt="true"']); ?>
<?php echo uiTableEmptyRow(6, t('common.loading', 'Loading...'), 'fa-spinner fa-spin'); ?>
<?php echo uiTableClose(); ?>
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
