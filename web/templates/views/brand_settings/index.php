<form method="POST" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_brand_settings" value="1">

    <!-- Brand Settings Single-Line Fixed Tabs -->
    <div class="card tabbed-page">
        <?php echo uiPageHeader('fa-palette', t('brand_settings.title')); ?>
    <div class="settings-tabs">
        <button type="button" class="settings-tab-btn active" data-tab="identity" onclick="switchSettingsTab('identity', this)">
            <i class="fas fa-id-card"></i> <?php echo t('brand_settings.tab_identity'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="logo" onclick="switchSettingsTab('logo', this)">
            <i class="fas fa-image"></i> <?php echo t('brand_settings.tab_logo'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="colors" onclick="switchSettingsTab('colors', this)">
            <i class="fas fa-palette"></i> <?php echo t('brand_settings.tab_colors'); ?>
        </button>
    </div>

    <!-- SECTION 1: identity -->
    <div id="tab_identity" class="settings-tab-pane active">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-id-card u-primary"></i> <?php echo t('brand_settings.identity_title'); ?>
            </div>
            <?php echo uiHelpButton('brandHelpBox'); ?>
        </div>

        <?php echo uiHelpBoxOpen('brandHelpBox', t('brand_settings.help_title')); ?>
            <?php echo t('brand_settings.help_body'); ?>
            <div class="u-mt-10"><strong><?php echo t('common.help_limits'); ?></strong><ul class="u-m-4-0-0-20"><li><?php echo t('brand_settings.limit_turnstile'); ?></li></ul></div>
<?php echo uiHelpBoxClose(); ?>

        <div class="u-d-grid u-gtc-1fr-1fr-1fr u-gap-16">
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_site_title'); ?></label>
                <input type="text" name="site_title" class="form-control" value="<?php echo htmlspecialchars($s['site_title']); ?>" required placeholder="AiPBX">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_brand_title'); ?></label>
                <input type="text" name="brand_title" class="form-control" value="<?php echo htmlspecialchars($s['brand_title']); ?>" required placeholder="AiPBX">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_brand_sub'); ?></label>
                <input type="text" name="brand_sub" class="form-control" value="<?php echo htmlspecialchars($s['brand_sub']); ?>" required placeholder="PBX & Call Center">
            </div>
            <div class="form-group">
                <label class="form-label"><i class="fas fa-life-ring"></i> <?php echo t('brand_settings.support_title'); ?></label>
                <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-180-1fr u-gap-12">
                    <input type="text" name="support_name" class="form-control" maxlength="80" value="<?php echo htmlspecialchars($s['support_name'] ?? ''); ?>" placeholder="<?php echo htmlspecialchars(t('brand_settings.support_name_ph')); ?>">
                    <input type="email" name="support_email" class="form-control" value="<?php echo htmlspecialchars($s['support_email'] ?? ''); ?>" placeholder="it@example.com">
                    <input type="text" name="support_phone" class="form-control" maxlength="40" value="<?php echo htmlspecialchars($s['support_phone'] ?? ''); ?>" placeholder="+90 212 000 00 00">
                </div>
                <small class="u-hint"><?php echo t('brand_settings.support_help'); ?></small>
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION 2: logo & favicon -->
    <div id="tab_logo" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-image u-primary"></i> <?php echo t('brand_settings.logo_favicon_title'); ?>
            </div>
        </div>

        <div class="u-d-grid u-gtc-1fr-1fr u-gap-24">
            <div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('brand_settings.field_logo_type'); ?></label>
                    <select name="site_logo_type" id="logo_type_select" class="form-control" onchange="toggleLogoTypeFields()">
                        <option value="icon" <?php echo $s['site_logo_type'] === 'icon' ? 'selected' : ''; ?>><?php echo t('brand_settings.logo_type_icon'); ?></option>
                        <option value="image" <?php echo $s['site_logo_type'] === 'image' ? 'selected' : ''; ?>><?php echo t('brand_settings.logo_type_image'); ?></option>
                    </select>
                </div>

                <div class="form-group" id="logo_icon_field" style="<?php echo $s['site_logo_type'] === 'image' ? 'display:none;' : ''; ?>">
                    <label class="form-label"><?php echo t('brand_settings.field_logo_icon'); ?></label>
                    <input type="text" name="site_logo_icon" id="logo_icon_input" class="form-control" value="<?php echo htmlspecialchars($s['site_logo_icon']); ?>" placeholder="fa-network-wired" oninput="updateBrandPreview()">
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.logo_icon_help'); ?> <a href="https://fontawesome.com/search?o=r&m=free" target="_blank" rel="noopener"><?php echo t('brand_settings.icon_library_link'); ?></a></small>
                </div>

                <div class="form-group" id="logo_image_field" style="<?php echo $s['site_logo_type'] !== 'image' ? 'display:none;' : ''; ?>">
                    <label class="form-label"><?php echo t('brand_settings.field_logo_file'); ?></label>
                    <input type="file" name="logo_file" class="form-control" accept=".png,.jpg,.jpeg,.svg,.webp" onchange="previewFileInput(this, 'logo_preview_img')">
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.logo_file_help'); ?></small>
                    <?php if ($s['site_logo_image']): ?>
                        <label class="u-d-flex u-ai-center u-gap-6 u-mt-8 u-fs-12 u-danger u-pointer">
                            <input type="checkbox" name="remove_logo_image" value="1"> <?php echo t('brand_settings.remove_logo'); ?>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="form-group" id="logo_dark_image_field" style="<?php echo $s['site_logo_type'] !== 'image' ? 'display:none;' : ''; ?>">
                    <label class="form-label"><?php echo t('brand_settings.field_logo_dark_file'); ?></label>
                    <input type="file" name="logo_dark_file" class="form-control" accept=".png,.jpg,.jpeg,.svg,.webp" onchange="previewFileInput(this, 'logo_dark_preview_img')">
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.logo_dark_file_help'); ?></small>
                    <?php if ($s['site_logo_image_dark']): ?>
                        <label class="u-d-flex u-ai-center u-gap-6 u-mt-8 u-fs-12 u-danger u-pointer">
                            <input type="checkbox" name="remove_logo_image_dark" value="1"> <?php echo t('brand_settings.remove_logo_dark'); ?>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="form-group" id="logo_style_field" style="<?php echo $s['site_logo_type'] !== 'image' ? 'display:none;' : ''; ?>">
                    <label class="form-label"><?php echo t('brand_settings.logo_style'); ?></label>
                    <select name="site_logo_style" class="form-control">
                        <option value="square" <?php echo ($s['site_logo_style'] ?? 'square') !== 'wide' ? 'selected' : ''; ?>><?php echo t('brand_settings.logo_style_square'); ?></option>
                        <option value="wide" <?php echo ($s['site_logo_style'] ?? '') === 'wide' ? 'selected' : ''; ?>><?php echo t('brand_settings.logo_style_wide'); ?></option>
                    </select>
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.logo_style_help'); ?></small>
                </div>

                <div class="form-group u-mt-20">
                    <label class="form-label"><i class="fas fa-moon"></i> <?php echo t('brand_settings.theme_default'); ?></label>
                    <select name="theme_default" class="form-control">
                        <option value="light" <?php echo ($s['theme_default'] ?? 'light') !== 'dark' ? 'selected' : ''; ?>><?php echo t('brand_settings.theme_light'); ?></option>
                        <option value="dark" <?php echo ($s['theme_default'] ?? '') === 'dark' ? 'selected' : ''; ?>><?php echo t('brand_settings.theme_dark'); ?></option>
                    </select>
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.theme_default_help'); ?></small>
                    <label class="u-d-flex u-ai-center u-gap-6 u-mt-10 u-fs-13 u-pointer">
                        <input type="checkbox" name="theme_locked" value="1" <?php echo ($s['theme_locked'] ?? '0') === '1' ? 'checked' : ''; ?>> <?php echo t('brand_settings.theme_locked'); ?>
                    </label>
                </div>

                <div class="form-group u-mt-20">
                    <label class="form-label"><i class="fas fa-envelope"></i> <?php echo t('brand_settings.mail_logo_title'); ?></label>
                    <select name="mail_logo_variant" class="form-control">
                        <option value="light" <?php echo ($s['mail_logo_variant'] ?? 'light') !== 'dark' ? 'selected' : ''; ?>><?php echo t('brand_settings.mail_logo_light'); ?></option>
                        <option value="dark" <?php echo ($s['mail_logo_variant'] ?? '') === 'dark' ? 'selected' : ''; ?>><?php echo t('brand_settings.mail_logo_dark'); ?></option>
                    </select>
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.mail_logo_help'); ?></small>
                    <label class="u-d-flex u-ai-center u-gap-6 u-mt-10 u-fs-13 u-pointer">
                        <input type="checkbox" name="mail_show_brand_title" value="1" <?php echo ($s['mail_show_brand_title'] ?? '1') !== '0' ? 'checked' : ''; ?>> <?php echo t('brand_settings.mail_show_title'); ?>
                    </label>
                </div>

                <div class="form-group u-mt-20">
                    <label class="form-label"><?php echo t('brand_settings.field_favicon_file'); ?></label>
                    <input type="file" name="favicon_file" class="form-control" accept=".ico,.png" onchange="previewFileInput(this, 'favicon_preview_img')">
                    <small class="u-muted u-fs-11"><?php echo t('brand_settings.favicon_file_help'); ?></small>
                    <?php if ($s['site_favicon_url']): ?>
                        <label class="u-d-flex u-ai-center u-gap-6 u-mt-8 u-fs-12 u-danger u-pointer">
                            <input type="checkbox" name="remove_favicon" value="1"> <?php echo t('brand_settings.remove_favicon'); ?>
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <label class="form-label"><?php echo t('brand_settings.preview'); ?></label>
                <div class="u-d-flex u-ai-center u-gap-20 u-bg-bg-input u-bd-1-solid-border-color u-r-12 u-p-20">
                    <div>
                        <div class="u-w-64 u-h-64 u-r-12 u-bg-brand-icon-bg u-bd-1-solid-brand-icon-border u-box-shadow-brand-icon-shadow u-ai-center u-jc-center u-of-hidden u-p-4 u-box-sizing-border-box" style="display: flex;" id="brand_logo_preview_box">
                            <?php if ($s['site_logo_type'] === 'image' && $logo_preview_url): ?>
                                <img class="u-maxw-100pc u-maxh-100pc u-object-fit-contain" id="logo_preview_img" src="<?php echo htmlspecialchars($logo_preview_url); ?>" alt="Logo">
                            <?php else: ?>
                                <i id="logo_preview_icon" class="fas <?php echo htmlspecialchars($s['site_logo_icon']); ?> u-fs-28 u-primary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="u-fs-11 u-muted u-text-center u-mt-6"><?php echo t('brand_settings.logo_label'); ?></div>
                    </div>
                    <div>
                        <div class="u-w-64 u-h-64 u-r-12 u-bg-1e293b u-bd-1-solid-border-color u-d-flex u-ai-center u-jc-center u-of-hidden u-p-4 u-box-sizing-border-box">
                            <?php if ($logo_dark_preview_url): ?>
                                <img class="u-maxw-100pc u-maxh-100pc u-object-fit-contain" id="logo_dark_preview_img" src="<?php echo htmlspecialchars($logo_dark_preview_url); ?>" alt="Logo">
                            <?php else: ?>
                                <i class="fas fa-moon u-fs-20 u-c-94a3b8" id="logo_dark_preview_img"></i>
                            <?php endif; ?>
                        </div>
                        <div class="u-fs-11 u-muted u-text-center u-mt-6"><?php echo t('brand_settings.logo_dark_label'); ?></div>
                    </div>
                    <div>
                        <div class="u-w-48 u-h-48 u-r-8 u-bg-bg-sidebar u-bd-1-solid-border-color u-d-flex u-ai-center u-jc-center u-of-hidden">
                            <?php if ($favicon_preview_url): ?>
                                <img class="u-maxw-100pc u-maxh-100pc u-object-fit-contain" id="favicon_preview_img" src="<?php echo htmlspecialchars($favicon_preview_url); ?>" alt="Favicon">
                            <?php else: ?>
                                <i class="fas fa-globe u-fs-20 u-muted" id="favicon_preview_img"></i>
                            <?php endif; ?>
                        </div>
                        <div class="u-fs-11 u-muted u-text-center u-mt-6"><?php echo t('brand_settings.favicon_label'); ?></div>
                    </div>
                    <div class="u-flex-1">
                        <div class="u-fs-13 u-fw-700 u-text-main" id="brand_title_preview"><?php echo htmlspecialchars($s['brand_title']); ?></div>
                        <div class="u-muted u-fs-11" id="brand_sub_preview"><?php echo htmlspecialchars($s['brand_sub']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION 3: colours -->
    <div id="tab_colors" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-palette u-primary"></i> <?php echo t('brand_settings.colors_title'); ?>
            </div>
        </div>

        <p class="u-fs-12 u-muted u-m-0-0-16-0">
            <?php echo t('brand_settings.colors_help'); ?>
        </p>

        <div class="u-d-grid u-gtc-1fr-1fr u-gap-24">
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_primary_color'); ?></label>
                <div class="u-d-flex u-ai-center u-gap-10">
                    <input class="u-w-44 u-h-40 u-r-8 u-bd-1-solid-border-color u-pointer u-p-2" type="color" id="primary_color_picker" value="<?php echo htmlspecialchars($s['brand_color_primary'] ?: '#0284c7'); ?>" onchange="syncColorText('primary')">
                    <input type="text" name="brand_color_primary" id="primary_color_text" class="form-control u-flex-1" value="<?php echo htmlspecialchars($s['brand_color_primary']); ?>" placeholder="<?php echo t('brand_settings.color_placeholder'); ?>" oninput="syncColorPicker('primary')" pattern="^#[0-9A-Fa-f]{6}$">
                </div>
                <small class="u-muted u-fs-11"><?php echo t('brand_settings.color_help'); ?></small>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_secondary_color'); ?></label>
                <div class="u-d-flex u-ai-center u-gap-10">
                    <input class="u-w-44 u-h-40 u-r-8 u-bd-1-solid-border-color u-pointer u-p-2" type="color" id="secondary_color_picker" value="<?php echo htmlspecialchars($s['brand_color_secondary'] ?: '#2563eb'); ?>" onchange="syncColorText('secondary')">
                    <input type="text" name="brand_color_secondary" id="secondary_color_text" class="form-control u-flex-1" value="<?php echo htmlspecialchars($s['brand_color_secondary']); ?>" placeholder="<?php echo t('brand_settings.color_placeholder'); ?>" oninput="syncColorPicker('secondary')" pattern="^#[0-9A-Fa-f]{6}$">
                </div>
                <small class="u-muted u-fs-11"><?php echo t('brand_settings.color_help'); ?></small>
            </div>
        </div>

        <div class="u-d-flex u-ai-center u-gap-16 u-mt-16 u-p-16 u-bg-bg-input u-r-12">
            <span class="u-fs-12 u-muted u-fw-700"><?php echo t('brand_settings.live_preview'); ?></span>
            <button type="button" class="btn" id="preview_btn_primary" style="background: <?php echo htmlspecialchars($s['brand_color_primary'] ?: 'var(--primary)'); ?>; color: #fff;"><?php echo t('brand_settings.primary_button'); ?></button>
            <button type="button" class="btn" id="preview_btn_secondary" style="background: <?php echo htmlspecialchars($s['brand_color_secondary'] ?: 'var(--secondary)'); ?>; color: #fff;"><?php echo t('brand_settings.secondary_button'); ?></button>
            <?php if (hasModulePermission('brand_settings', 'edit')): ?>
                <button type="button" class="btn btn-secondary u-ml-auto" onclick="resetBrandColors()"><i class="fas fa-undo"></i> <?php echo t('brand_settings.reset_colors'); ?></button>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <?php if (hasModulePermission('brand_settings', 'edit')): ?>
        <div class="form-actions"><button type="submit" form="brandResetForm" class="btn btn-secondary" onclick="return confirm(<?php echo jsArg(t('brand_settings.reset_all_confirm')); ?>);">
            <i class="fas fa-undo"></i> <?php echo t('brand_settings.reset_all'); ?>
        </button>
        <button type="submit" class="btn btn-primary" title="<?php echo t('brand_settings.save'); ?>">
            <i class="fas fa-save"></i> <?php echo t('brand_settings.save'); ?>
        </button></div>
    <?php endif; ?>
    </div>
</form>

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

<form method="POST" id="brandResetForm" data-no-spa="true" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="reset_brand_settings" value="1">
</form>

<script src="<?php echo asset('/assets/js/brand_settings.js'); ?>"></script>
