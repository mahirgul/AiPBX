<?php
$is_enabled = !empty($settings['enabled']);
$raw_enabled = !empty($settings['raw_enabled']);
$client_id = $settings['client_id'] ?? '';
$client_secret = $settings['client_secret'] ?? '';
$redirect_uri = $settings['redirect_uri'] ?? '';
?>

<div class="card">
    <?php echo uiPageHeaderOpen('fab fa-google', t('google_integration.header_title'), 'googleHelpBox'); ?>
<span class="badge <?php echo $is_enabled ? 'badge-success' : 'badge-secondary'; ?> u-ml-8 u-fs-11">
                <?php echo $is_enabled ? t('common.active') : t('common.passive'); ?>
            </span>
<?php echo uiPageHeaderActions(); ?>

<?php echo uiPageHeaderClose(); ?>

    <!-- Guide box -->
    <?php echo uiHelpBoxOpen('googleHelpBox', t('google_integration.guide_title')); ?>
        <p>
            <?php echo t('google_integration.guide_p1'); ?>
        </p>
        <p class="u-mb-0">
            <?php echo t('google_integration.guide_p2'); ?>
        </p>
<?php echo uiHelpBoxClose(); ?>

    <form method="POST" action="/google-integration" autocomplete="off" class="u-p-20">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" value="save_settings">

        <div style="background: var(--bg-hover, rgba(0,0,0,0.02)); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <label class="u-fs-14 u-fw-700 u-pointer u-m-0" for="googleOauthToggle" style="display: block;">
                    <i class="fas fa-power-off" style="color: <?php echo $raw_enabled ? '#10b981' : 'var(--text-muted)'; ?>; margin-right: 6px;"></i>
                    <?php echo t('google_integration.enable_label'); ?>
                </label>
                <small class="u-muted u-mt-4" style="display: block;">
                    <?php echo t('google_integration.enable_desc'); ?>
                </small>
            </div>
            <div>
                <input class="u-w-20 u-h-20 u-pointer" type="checkbox" name="google_oauth_enabled" value="1" id="googleOauthToggle" <?php echo $raw_enabled ? 'checked' : ''; ?>>
            </div>
        </div>

        <div class="form-group u-mb-18">
            <label class="form-label u-fs-13 u-fw-700">
                <i class="fas fa-id-badge u-primary"></i> Google Client ID
            </label>
            <input type="text" name="google_client_id" class="form-control u-h-40 u-mono u-fs-13" value="<?php echo htmlspecialchars($client_id); ?>" placeholder="<?php echo t('common.eg'); ?> 1234567890-abcdefg123456.apps.googleusercontent.com">
            <small class="u-muted u-mt-4" style="display: block;">
                <?php echo t('google_integration.client_id_desc'); ?>
            </small>
        </div>

        <div class="form-group u-mb-18">
            <label class="form-label u-fs-13 u-fw-700">
                <i class="fas fa-key u-primary"></i> Google Client Secret
            </label>
            <div class="u-relative">
                <input type="password" id="googleClientSecretInput" name="google_client_secret" class="form-control u-h-40 u-mono u-fs-13 u-pr-40" value="<?php echo htmlspecialchars($client_secret); ?>" placeholder="<?php echo t('common.eg'); ?> GOCSPX-xxxxxx...">
                <button class="u-pos-absolute u-right-10 u-top-50pc u-transform-translatey-50pc u-bg-none u-bd-none u-muted u-pointer" type="button" onclick="toggleSecretVisibility()" title="<?php echo t('my_phone.show_hide'); ?>">
                    <i class="fas fa-eye" id="secretEyeIcon"></i>
                </button>
            </div>
            <small class="u-muted u-mt-4" style="display: block;">
                <?php echo t('google_integration.client_secret_desc'); ?>
            </small>
        </div>

        <div class="form-group u-mb-24">
            <label class="form-label u-fs-13 u-fw-700">
                <i class="fas fa-link u-primary"></i> <?php echo t('google_integration.redirect_uri_label'); ?>
            </label>
            <div class="u-flex-gap">
                <input type="text" id="redirectUriInput" readonly class="form-control" value="<?php echo htmlspecialchars($redirect_uri); ?>" style="height: 40px; font-family: monospace; font-size: 13px; background: var(--bg-hover, rgba(0,0,0,0.03)); user-select: all;">
                <button type="button" class="btn btn-secondary u-nowrap" onclick="copyRedirectUri()">
                    <i class="fas fa-copy"></i> <span id="copyBtnText"><?php echo t('common.copy'); ?></span>
                </button>
            </div>
            <small class="u-muted u-mt-4" style="display: block;">
                <?php echo t('google_integration.redirect_uri_desc'); ?>
            </small>
        </div>

        <div class="u-d-flex u-jc-flex-end">
            <button type="submit" class="btn btn-primary u-fw-700">
                <i class="fas fa-save"></i> <?php echo t('google_integration.btn_save'); ?>
            </button>
        </div>
    </form>
</div>

<!-- Setup steps & info card -->
<div class="card u-mt-20">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-tasks u-primary"></i> <?php echo t('google_integration.setup_guide_title'); ?>
        </div>
    </div>
    <div class="u-p-20 u-lh-1-7 u-fs-14">
        <ol class="u-pl-20 u-mb-0">
            <li class="u-mb-10">
                <?php echo t('google_integration.setup_step1'); ?> 
                <a class="u-primary u-text-decoration-underline" href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">
                    console.cloud.google.com/apis/credentials <i class="fas fa-external-link-alt u-fs-11"></i>
                </a>
            </li>
            <li class="u-mb-10">
                <?php echo t('google_integration.setup_step2'); ?>
            </li>
            <li class="u-mb-10">
                <?php echo t('google_integration.setup_step3'); ?>
            </li>
            <li class="u-mb-10">
                <?php echo sprintf(t('google_integration.setup_step4'), htmlspecialchars($redirect_uri)); ?>
            </li>
            <li class="u-mb-10">
                <?php echo t('google_integration.setup_step5'); ?>
            </li>
            <li class="u-mb-10">
                <?php echo t('google_integration.setup_step6'); ?>
            </li>
            <li class="u-mb-0">
                <?php echo t('google_integration.setup_step7'); ?>
            </li>
        </ol>
    </div>
</div>

<script>window.GOOGLE_INTEGRATION_COPIED = <?php echo json_encode(t('google_integration.copied')); ?>;</script>
<script src="<?php echo asset('/assets/js/google_integration.js'); ?>"></script>
