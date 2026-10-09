<?php
/** @var array $status runtime, service and models (LocalAiService::status) */
$texts = [];
foreach ([
    'rt_absent', 'rt_installing', 'rt_installed', 'rt_failed', 'rt_removing', 'svc_down', 'svc_starting',
    'st_absent', 'st_downloading', 'st_installed', 'st_loading', 'st_ready', 'st_error',
    'btn_download', 'btn_remove', 'btn_benchmark', 'accept_license', 'license', 'homepage',
    'confirm_runtime_remove', 'confirm_model_remove', 'confirm_runtime_install', 'need_license',
    'bench_result', 'bench_live_ok', 'bench_live_slow', 'measuring', 'cpu', 'cores', 'ram', 'process', 'kind_tts', 'kind_embedding',
    'no_models',
] as $k) {
    $texts[$k] = t('ai_models.' . $k);
}
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-microchip u-primary"></i> <?php echo t('ai_models.title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('aiModelsHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="aiModelsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('ai_models.help_title'); ?></h4>
        <?php echo t('ai_models.help_body'); ?>
    </div>

    <div style="padding: 0 20px 20px;">
        <div class="u-flex-between" style="flex-wrap: wrap; gap: 10px; align-items: center;">
            <div>
                <div class="u-strong"><?php echo t('ai_models.runtime'); ?></div>
                <div class="u-muted u-fs-12"><?php echo t('ai_models.runtime_desc'); ?></div>
                <div id="aim-runtime" class="u-fs-12 u-mt-6"></div>
            </div>
            <div class="u-flex-gap">
                <button type="button" class="btn btn-primary" id="aim-rt-install" onclick="aimRuntime('runtime_install')"><i class="fas fa-download"></i> <?php echo t('ai_models.btn_runtime_install'); ?></button>
                <button type="button" class="btn btn-danger" id="aim-rt-remove" onclick="aimRuntime('runtime_remove')"><i class="fas fa-trash-alt"></i> <?php echo t('ai_models.btn_runtime_remove'); ?></button>
            </div>
        </div>
        <div id="aim-service" class="u-muted u-fs-12 u-mt-10"></div>
    </div>
</div>

<div class="card" id="aim-models-card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-cubes u-primary"></i> <?php echo t('ai_models.models'); ?></div>
    </div>
    <div style="padding: 0 20px 20px;" id="aim-models"></div>
</div>

<script>
window.AI_MODELS_PAGE = {
    status: <?php echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    text: <?php echo json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo asset('/assets/js/ai_models.js'); ?>"></script>
