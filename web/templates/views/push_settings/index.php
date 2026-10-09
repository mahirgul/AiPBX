<?php
$has_service_account = !empty($settings['push_fcm_service_account']);
$has_apns_key = !empty($settings['push_apns_key']);
$apns_on = ($settings['push_apns_enabled'] ?? '0') === '1';
?>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/push_settings.css'); ?>">

<!-- Push Settings Single-Line Fixed Tabs -->
<div class="settings-tabs u-mb-20">
    <button type="button" class="settings-tab-btn active" data-tab="config" onclick="switchSettingsTab('config', this)">
        <i class="fas fa-sliders-h"></i> <?php echo t('push_settings.tab_config', 'Yapılandırma'); ?>
    </button>
    <?php if (hasModulePermission('push_settings', 'edit')): ?>
    <button type="button" class="settings-tab-btn" data-tab="test" onclick="switchSettingsTab('test', this)">
        <i class="fas fa-paper-plane"></i> <?php echo t('push_settings.tab_test', 'Test & Gönderim'); ?>
    </button>
    <?php endif; ?>
</div>

<!-- TAB 1: configuration -->
<div id="tab_config" class="settings-tab-pane active">
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-bell u-primary"></i> <?php echo t('push.title'); ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('pushHelpBox')" title="<?php echo t('push.module_guide_title'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="pushHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('push.help_title'); ?></h4>
        <p><?php echo t('push.help_intro'); ?></p>
        <ul style="margin: 8px 0 12px 20px; line-height: 1.6;">
            <li><?php echo t('push.help_layer0'); ?></li>
            <li><?php echo t('push.help_layer1'); ?></li>
        </ul>
        <p class="u-mb-0"><?php echo t('push.help_no_fcm'); ?></p>
    </div>

    <form method="POST" autocomplete="off" class="push-settings-form">
        <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
        <input type="hidden" name="save_push_settings" value="1">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div class="form-group">
                <label class="form-label u-fw-600"><?php echo t('push.push_layer'); ?></label>
                <select name="push_enabled" class="form-control" id="pushEnabledSelect" onchange="togglePushFields()">
                    <option value="1" <?php echo ($settings['push_enabled'] === '1') ? 'selected' : ''; ?>><?php echo t('push.push_on'); ?></option>
                    <option value="0" <?php echo ($settings['push_enabled'] === '0') ? 'selected' : ''; ?>><?php echo t('push.push_off'); ?></option>
                </select>
                <small class="u-hint"><?php echo t('push.push_off_hint'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label u-fw-600"><?php echo t('push.provider'); ?></label>
                <select name="push_provider" class="form-control" id="pushProviderSelect" onchange="togglePushFields()">
                    <option value="none" <?php echo ($settings['push_provider'] === 'none') ? 'selected' : ''; ?>><?php echo t('push.provider_none'); ?></option>
                    <option value="fcm" <?php echo ($settings['push_provider'] === 'fcm') ? 'selected' : ''; ?>>Google Firebase Cloud Messaging (FCM HTTP v1)</option>
                </select>
                <small class="u-hint"><?php echo t('push.provider_hint'); ?></small>
            </div>
        </div>

        <div id="fcmConfigSection" style="<?php echo ($settings['push_provider'] === 'fcm') ? '' : 'display: none;'; ?> background: var(--bg-surface-secondary, rgba(0,0,0,0.02)); padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border-color, #e0e0e0);">
            <h4 style="margin-top: 0; margin-bottom: 16px; color: var(--primary); display: flex; align-items: center; gap: 8px;">
                <i class="fab fa-google"></i> <?php echo t('push.fcm_title'); ?>
            </h4>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="form-group">
                    <label class="form-label"><?php echo t('push.fcm_project'); ?> <span class="u-danger">*</span></label>
                    <input type="text" name="push_fcm_project_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_project_id'] ?? ''); ?>" placeholder="<?php echo t('push.fcm_project_ph'); ?>">
                    <small class="u-hint"><?php echo t('push.fcm_project_hint'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('push.wake_wait'); ?></label>
                    <input type="number" name="push_wait_seconds" class="form-control" min="3" max="30" value="<?php echo htmlspecialchars($settings['push_wait_seconds'] ?? '8'); ?>">
                    <small class="u-hint"><?php echo t('push.wake_wait_hint'); ?></small>
                </div>
            </div>

            <div class="form-group u-mb-16">
                <label class="form-label u-flex-between">
                    <span><?php echo t('push.sa_json'); ?> <span class="u-danger">*</span></span>
                    <?php if ($has_service_account): ?>
                        <span class="badge" style="background: #28a745; color: #fff; font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                            <i class="fas fa-check-circle"></i> <?php echo t('push.sa_set'); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge" style="background: #ffc107; color: #212529; font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                            <i class="fas fa-exclamation-circle"></i> <?php echo t('push.sa_not_set'); ?>
                        </span>
                    <?php endif; ?>
                </label>
                <textarea name="push_fcm_service_account" class="form-control" rows="5" style="font-family: monospace; font-size: 12px;" placeholder="<?php echo htmlspecialchars($has_service_account ? t('push.sa_ph_set') : t('push.sa_ph_new')); ?>"></textarea>
                <small class="u-hint"><?php echo t('push.sa_hidden'); ?></small>
            </div>

            <div style="margin-top: 20px; border-top: 1px dashed var(--border-color, #ccc); padding-top: 16px;">
                <h5 style="margin-top: 0; margin-bottom: 12px; font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-mobile-alt"></i> <?php echo t('push.client_params'); ?>
                </h5>
                <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                    <?php echo t('push.client_params_desc'); ?>
                </p>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label u-fs-12"><?php echo t('push.app_id'); ?></label>
                        <input type="text" name="push_fcm_app_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_app_id'] ?? ''); ?>" placeholder="1:123456789:android:abcdef">
                    </div>
                    <div class="form-group">
                        <label class="form-label u-fs-12"><?php echo t('push.api_key'); ?></label>
                        <input type="text" name="push_fcm_api_key" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_api_key'] ?? ''); ?>" placeholder="AIzaSy...">
                    </div>
                    <div class="form-group">
                        <label class="form-label u-fs-12"><?php echo t('push.sender_id'); ?></label>
                        <input type="text" name="push_fcm_sender_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_fcm_sender_id'] ?? ''); ?>" placeholder="<?php echo t('push.sender_id_ph'); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div style="background: var(--bg-surface-secondary, rgba(0,0,0,0.02)); padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border-color, #e0e0e0);">
            <h4 style="margin-top: 0; margin-bottom: 16px; color: var(--primary); display: flex; align-items: center; gap: 8px;">
                <i class="fab fa-apple"></i> <?php echo t('push.apns_title'); ?>
            </h4>

            <div class="form-group u-mb-16">
                <label class="form-label u-fw-600"><?php echo t('push.apns_enabled'); ?></label>
                <select name="push_apns_enabled" class="form-control" id="pushApnsEnabledSelect" onchange="togglePushFields()">
                    <option value="0" <?php echo $apns_on ? '' : 'selected'; ?>><?php echo t('push.apns_off'); ?></option>
                    <option value="1" <?php echo $apns_on ? 'selected' : ''; ?>><?php echo t('push.apns_on'); ?></option>
                </select>
                <small class="u-hint"><?php echo t('push.apns_hint'); ?></small>
            </div>

            <div id="apnsConfigSection" style="<?php echo $apns_on ? '' : 'display: none;'; ?>">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('push.apns_key_id'); ?> <span class="u-danger">*</span></label>
                        <input type="text" name="push_apns_key_id" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($settings['push_apns_key_id'] ?? ''); ?>" placeholder="ABC123DEFG">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('push.apns_team_id'); ?> <span class="u-danger">*</span></label>
                        <input type="text" name="push_apns_team_id" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($settings['push_apns_team_id'] ?? ''); ?>" placeholder="DEF123GHIJ">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('push.apns_bundle_id'); ?></label>
                        <input type="text" name="push_apns_bundle_id" class="form-control" value="<?php echo htmlspecialchars($settings['push_apns_bundle_id'] ?? 'com.mhrgl.AiPBX'); ?>" placeholder="com.mhrgl.AiPBX">
                        <small class="u-hint"><?php echo t('push.apns_bundle_hint'); ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('push.apns_environment'); ?></label>
                        <select name="push_apns_environment" class="form-control">
                            <option value="production" <?php echo (($settings['push_apns_environment'] ?? 'production') !== 'sandbox') ? 'selected' : ''; ?>><?php echo t('push.apns_env_production'); ?></option>
                            <option value="sandbox" <?php echo (($settings['push_apns_environment'] ?? '') === 'sandbox') ? 'selected' : ''; ?>><?php echo t('push.apns_env_sandbox'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-group u-mb-0">
                    <label class="form-label u-flex-between">
                        <span><?php echo t('push.apns_key'); ?> <span class="u-danger">*</span></span>
                        <?php if ($has_apns_key): ?>
                            <span class="badge" style="background: #28a745; color: #fff; font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                                <i class="fas fa-check-circle"></i> <?php echo t('push.apns_key_set'); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge" style="background: #ffc107; color: #212529; font-size: 11px; padding: 4px 8px; border-radius: 4px;">
                                <i class="fas fa-exclamation-circle"></i> <?php echo t('push.sa_not_set'); ?>
                            </span>
                        <?php endif; ?>
                    </label>
                    <textarea name="push_apns_key" class="form-control" rows="4" style="font-family: monospace; font-size: 12px;" placeholder="<?php echo htmlspecialchars($has_apns_key ? t('push.apns_key_ph_set') : t('push.apns_key_ph_new')); ?>"></textarea>
                    <small class="u-hint"><?php echo t('push.apns_key_hint'); ?></small>
                </div>
            </div>
        </div>

        <?php if (hasModulePermission('push_settings', 'edit')): ?>
        <div class="push-save-actions" style="display: flex; justify-content: flex-end; gap: 12px;">
            <button type="submit" name="save_push_settings" class="btn btn-primary">
                <i class="fas fa-save"></i> <?php echo t('common.save', 'Ayarları Kaydet'); ?>
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>
</div>

<?php if (hasModulePermission('push_settings', 'edit')): ?>
<!-- TAB 2: Test Bildirimi -->
<div id="tab_test" class="settings-tab-pane" style="display: none;">
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-paper-plane" style="color: var(--success, #28a745);"></i> <?php echo t('push.send_test'); ?>
        </div>
    </div>
    <div class="push-test-body">
        <p class="u-mt-0 u-muted u-fs-13">
            <?php echo t('push.test_desc'); ?>
        </p>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; align-items: flex-end;">
            <div class="form-group u-mb-0">
                <label class="form-label u-fw-600"><?php echo t('push.test_target'); ?></label>
                <select id="testTargetSelect" class="form-control">
                    <option value=""><?php echo t('push.choose_device'); ?></option>
                    <?php if (empty($devices)): ?>
                        <option value="" disabled><?php echo t('push.no_devices'); ?></option>
                    <?php else: ?>
                        <?php foreach ($devices as $d): ?>
                            <option value="<?php echo htmlspecialchars($d['extension']); ?>">
                                <?php echo t('my_phone.lbl_extension'); ?>: <?php echo htmlspecialchars($d['extension']); ?>
                                <?php if (!empty($d['full_name'])) echo ' (' . htmlspecialchars($d['full_name']) . ')'; ?>
                                - <?php echo htmlspecialchars($d['device_name'] ?: (($d['platform'] ?? '') === 'ios' ? 'iPhone' : 'Android')); ?>
                                [v<?php echo htmlspecialchars($d['app_version'] ?: '1.0'); ?>]
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <button type="button" id="btnTestPush" class="btn btn-success" onclick="sendTestPush()" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> <?php echo t('push.send_test'); ?>
                </button>
            </div>
        </div>

        <div id="testResultBox" style="display: none; margin-top: 16px;"></div>
    </div>
</div>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/push_settings.js'); ?>"></script>
