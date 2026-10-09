<?php
/** @var array $status runtime, service and models (LocalAiService::status) */
$texts = [];
foreach ([
    'rt_absent', 'rt_installing', 'rt_installed', 'rt_failed', 'rt_removing', 'svc_down', 'svc_starting',
    'st_absent', 'st_downloading', 'st_installed', 'st_loading', 'st_ready', 'st_error',
    'btn_download', 'btn_remove', 'btn_benchmark', 'accept_license', 'license', 'homepage',
    'confirm_runtime_remove', 'confirm_model_remove', 'confirm_runtime_install', 'need_license',
    'bench_result', 'bench_live_ok', 'bench_live_slow', 'measuring', 'cpu', 'cores', 'ram', 'process', 'kind_tts', 'kind_embedding',
    'no_models', 'btn_run', 'btn_stop', 'st_stopped', 'memory', 'disk_used', 'measured', 'noncommercial',
    'try_title', 'try_play', 'try_none', 'try_result', 'msg_try_error', 'gender_female', 'gender_male',
    'add_in_list', 'add_btn', 'add_card', 'add_speakers', 'add_loading', 'add_none', 'add_all_languages',
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
        <div class="u-muted u-fs-12" id="aim-disk"></div>
    </div>
    <div style="padding: 0 20px 20px;" id="aim-models"></div>
</div>

<div class="card" id="aim-add-card" style="display: none;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-magnifying-glass u-primary"></i> <?php echo t('ai_models.add_title'); ?></div>
        <button type="button" class="btn btn-secondary btn-sm" id="aim-add-open"><i class="fas fa-list"></i> <?php echo t('ai_models.add_open'); ?></button>
    </div>
    <div style="padding: 0 20px 20px;">
        <p class="u-muted u-fs-12 u-mt-0"><?php echo t('ai_models.add_help'); ?></p>
        <div id="aim-add-body" style="display: none;">
            <div class="u-flex-gap" style="flex-wrap: wrap;">
                <select id="aim-add-lang" class="form-control" style="max-width: 260px;"></select>
                <select id="aim-add-quality" class="form-control" style="max-width: 180px;">
                    <option value=""><?php echo t('ai_models.add_all_qualities'); ?></option>
                    <option value="x_low">x_low</option><option value="low">low</option><option value="medium">medium</option><option value="high">high</option>
                </select>
            </div>
            <div id="aim-add-list" class="u-mt-10"></div>
        </div>
    </div>
</div>

<div class="card" id="aim-try-card" style="display: none;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-headphones u-primary"></i> <?php echo t('ai_models.try_title'); ?></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <p class="u-muted u-fs-12 u-mt-0"><?php echo t('ai_models.try_help'); ?></p>
        <textarea id="aim-try-text" class="form-control" rows="2" maxlength="1000"><?php echo htmlspecialchars(t('ai_models.try_default_text')); ?></textarea>
        <div class="u-flex-gap u-mt-10" style="flex-wrap: wrap; align-items: center;">
            <select id="aim-try-model" class="form-control" style="max-width: 320px;"></select>
            <label class="u-fs-12"><?php echo t('ai_models.try_speed'); ?> <input type="number" id="aim-try-speed" class="form-control" value="1" min="0.5" max="2" step="0.1" style="width: 80px; display: inline-block;"></label>
            <button type="button" class="btn btn-primary" id="aim-try-go"><i class="fas fa-play"></i> <?php echo t('ai_models.try_play'); ?></button>
        </div>
        <div id="aim-try-results" class="u-mt-10"></div>
    </div>
</div>

<script>
window.AI_MODELS_PAGE = {
    status: <?php echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
    text: <?php echo json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>
};
</script>
<script src="<?php echo asset('/assets/js/ai_models.js'); ?>"></script>
