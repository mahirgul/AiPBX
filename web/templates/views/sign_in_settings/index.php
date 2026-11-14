<div class="card">
    <?php echo uiPageHeader('fa-right-to-bracket', t('sign_in_settings.title')); ?>
    <p class="u-muted u-fs-13 u-m-0"><?php echo t('sign_in_settings.desc'); ?></p>
</div>

<form method="POST" autocomplete="off" class="card u-mt-20 u-p-20">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_login_captcha" value="1">
    <h3 class="u-fs-16 u-fw-700 u-m-0-0-12-0"><i class="fas fa-shield-alt"></i> <?php echo t('brand_settings.captcha_title'); ?></h3>
    <div class="form-group">
        <label class="form-label"><?php echo t('brand_settings.captcha_provider'); ?></label>
        <select name="login_captcha_provider" class="form-control">
            <option value="math" <?php echo $captcha_provider !== 'turnstile' ? 'selected' : ''; ?>><?php echo t('brand_settings.captcha_math'); ?></option>
            <option value="turnstile" <?php echo $captcha_provider === 'turnstile' ? 'selected' : ''; ?>>Cloudflare Turnstile</option>
        </select>
        <small class="u-muted u-fs-11"><?php echo t('brand_settings.captcha_help'); ?></small>
    </div>
    <div class="form-group">
        <label class="form-label"><?php echo t('brand_settings.turnstile_site_key'); ?></label>
        <input type="text" name="login_turnstile_site_key" class="form-control" maxlength="100" value="<?php echo htmlspecialchars($captcha_site_key, ENT_QUOTES); ?>">
    </div>
    <div class="form-group">
        <label class="form-label"><?php echo t('brand_settings.turnstile_secret'); ?></label>
        <input type="password" name="login_turnstile_secret" class="form-control" maxlength="200" autocomplete="new-password" placeholder="<?php echo $captcha_secret_set ? t('brand_settings.turnstile_secret_keep') : ''; ?>">
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('brand_settings.captcha_save'); ?></button></div>
</form>

<form method="POST" autocomplete="off" class="card u-mt-20 u-p-20">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_login_alert" value="1">
    <h3 class="u-fs-16 u-fw-700 u-m-0-0-12-0"><i class="fas fa-bell"></i> <?php echo t('login_alert.title'); ?></h3>
    <p class="u-muted u-fs-12"><?php echo t('login_alert.help'); ?></p>
    <label class="u-d-flex u-ai-center u-gap-6 u-mb-12 u-fs-13">
        <input type="checkbox" name="login_new_ip_alert" value="1" <?php echo $login_alert_on ? 'checked' : ''; ?>> <?php echo t('login_alert.enable'); ?>
    </label>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button></div>
</form>

<form method="POST" autocomplete="off" class="card u-mt-20 u-p-20">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_sso_only" value="1">
    <h3 class="u-fs-16 u-fw-700 u-m-0-0-12-0"><i class="fas fa-id-badge"></i> <?php echo t('sso_only.title'); ?></h3>
    <p class="u-muted u-fs-12"><?php echo t('sso_only.help'); ?></p>
    <div class="form-group">
        <label class="form-label"><?php echo t('sso_only.field_provider'); ?></label>
        <select name="auth_sso_provider" class="form-control">
            <option value="saml" <?php echo $sso_only_provider === 'saml' ? 'selected' : ''; ?> <?php echo $sso_saml_enabled ? '' : 'disabled'; ?>>SAML</option>
            <option value="oidc" <?php echo $sso_only_provider === 'oidc' ? 'selected' : ''; ?> <?php echo $sso_oidc_enabled ? '' : 'disabled'; ?>>OpenID Connect</option>
        </select>
    </div>
    <label class="u-d-flex u-ai-center u-gap-6 u-mb-12 u-fs-13">
        <input type="checkbox" name="auth_sso_only" value="1" <?php echo $sso_only_on ? 'checked' : ''; ?>> <?php echo t('sso_only.field_on'); ?>
    </label>
    <div class="form-actions"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button></div>
</form>
