<form method="POST" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_brand_settings" value="1">

    <!-- BÖLÜM 1: Kimlik -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-id-card" style="color: var(--primary);"></i> <?php echo t('brand_settings.identity_title'); ?>
            </div>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('brandHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>

        <div class="module-help-box" id="brandHelpBox" style="margin: 0 0 16px 0;">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('brand_settings.help_title'); ?></h4>
            <?php echo t('brand_settings.help_body'); ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_site_title'); ?></label>
                <input type="text" name="site_title" class="form-control" value="<?php echo htmlspecialchars($s['site_title']); ?>" required placeholder="AI PBX Portalı">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_brand_title'); ?></label>
                <input type="text" name="brand_title" class="form-control" value="<?php echo htmlspecialchars($s['brand_title']); ?>" required placeholder="AI PBX">
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_brand_sub'); ?></label>
                <input type="text" name="brand_sub" class="form-control" value="<?php echo htmlspecialchars($s['brand_sub']); ?>" required placeholder="Santral & Çağrı Merkezi">
            </div>
        </div>
    </div>

    <!-- BÖLÜM 2: Logo & Favicon -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-image" style="color: var(--primary);"></i> <?php echo t('brand_settings.logo_favicon_title'); ?>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
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
                    <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('brand_settings.logo_icon_help'); ?> <a href="https://fontawesome.com/search?o=r&m=free" target="_blank" rel="noopener"><?php echo t('brand_settings.icon_library_link'); ?></a></small>
                </div>

                <div class="form-group" id="logo_image_field" style="<?php echo $s['site_logo_type'] !== 'image' ? 'display:none;' : ''; ?>">
                    <label class="form-label"><?php echo t('brand_settings.field_logo_file'); ?></label>
                    <input type="file" name="logo_file" class="form-control" accept=".png,.jpg,.jpeg,.svg,.webp" onchange="previewFileInput(this, 'logo_preview_img')">
                    <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('brand_settings.logo_file_help'); ?></small>
                    <?php if ($s['site_logo_image']): ?>
                        <label style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 12px; color: var(--danger); cursor: pointer;">
                            <input type="checkbox" name="remove_logo_image" value="1"> <?php echo t('brand_settings.remove_logo'); ?>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-top: 20px;">
                    <label class="form-label"><?php echo t('brand_settings.field_favicon_file'); ?></label>
                    <input type="file" name="favicon_file" class="form-control" accept=".ico,.png" onchange="previewFileInput(this, 'favicon_preview_img')">
                    <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('brand_settings.favicon_file_help'); ?></small>
                    <?php if ($s['site_favicon_url']): ?>
                        <label style="display: flex; align-items: center; gap: 6px; margin-top: 8px; font-size: 12px; color: var(--danger); cursor: pointer;">
                            <input type="checkbox" name="remove_favicon" value="1"> <?php echo t('brand_settings.remove_favicon'); ?>
                        </label>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <label class="form-label"><?php echo t('brand_settings.preview'); ?></label>
                <div style="display: flex; align-items: center; gap: 20px; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px;">
                    <div>
                        <div style="width: 64px; height: 64px; border-radius: 12px; background: var(--brand-icon-bg); border: 1px solid var(--brand-icon-border); box-shadow: var(--brand-icon-shadow); display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 4px; box-sizing: border-box;" id="brand_logo_preview_box">
                            <?php if ($s['site_logo_type'] === 'image' && $logo_preview_url): ?>
                                <img id="logo_preview_img" src="<?php echo htmlspecialchars($logo_preview_url); ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                            <?php else: ?>
                                <i id="logo_preview_icon" class="fas <?php echo htmlspecialchars($s['site_logo_icon']); ?>" style="font-size: 28px; color: var(--primary);"></i>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 11px; color: var(--text-muted); text-align: center; margin-top: 6px;"><?php echo t('brand_settings.logo_label'); ?></div>
                    </div>
                    <div>
                        <div style="width: 48px; height: 48px; border-radius: 8px; background: var(--bg-sidebar); border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                            <?php if ($favicon_preview_url): ?>
                                <img id="favicon_preview_img" src="<?php echo htmlspecialchars($favicon_preview_url); ?>" alt="Favicon" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                            <?php else: ?>
                                <i class="fas fa-globe" id="favicon_preview_img" style="font-size: 20px; color: var(--text-muted);"></i>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 11px; color: var(--text-muted); text-align: center; margin-top: 6px;"><?php echo t('brand_settings.favicon_label'); ?></div>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-size: 13px; font-weight: 700; color: var(--text-main);" id="brand_title_preview"><?php echo htmlspecialchars($s['brand_title']); ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);" id="brand_sub_preview"><?php echo htmlspecialchars($s['brand_sub']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BÖLÜM 3: Renkler -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-palette" style="color: var(--primary);"></i> <?php echo t('brand_settings.colors_title'); ?>
            </div>
        </div>

        <p style="font-size: 12px; color: var(--text-muted); margin: 0 0 16px 0;">
            <?php echo t('brand_settings.colors_help'); ?>
        </p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_primary_color'); ?></label>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="color" id="primary_color_picker" value="<?php echo htmlspecialchars($s['brand_color_primary'] ?: '#0284c7'); ?>" onchange="syncColorText('primary')" style="width: 44px; height: 40px; border-radius: 8px; border: 1px solid var(--border-color); cursor: pointer; padding: 2px;">
                    <input type="text" name="brand_color_primary" id="primary_color_text" class="form-control" value="<?php echo htmlspecialchars($s['brand_color_primary']); ?>" placeholder="<?php echo t('brand_settings.color_placeholder'); ?>" oninput="syncColorPicker('primary')" pattern="^#[0-9A-Fa-f]{6}$" style="flex: 1;">
                </div>
                <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('brand_settings.color_help'); ?></small>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('brand_settings.field_secondary_color'); ?></label>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="color" id="secondary_color_picker" value="<?php echo htmlspecialchars($s['brand_color_secondary'] ?: '#2563eb'); ?>" onchange="syncColorText('secondary')" style="width: 44px; height: 40px; border-radius: 8px; border: 1px solid var(--border-color); cursor: pointer; padding: 2px;">
                    <input type="text" name="brand_color_secondary" id="secondary_color_text" class="form-control" value="<?php echo htmlspecialchars($s['brand_color_secondary']); ?>" placeholder="<?php echo t('brand_settings.color_placeholder'); ?>" oninput="syncColorPicker('secondary')" pattern="^#[0-9A-Fa-f]{6}$" style="flex: 1;">
                </div>
                <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('brand_settings.color_help'); ?></small>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 16px; margin-top: 16px; padding: 16px; background: var(--bg-input); border-radius: 10px;">
            <span style="font-size: 12px; color: var(--text-muted); font-weight: 700;"><?php echo t('brand_settings.live_preview'); ?></span>
            <button type="button" class="btn" id="preview_btn_primary" style="background: <?php echo htmlspecialchars($s['brand_color_primary'] ?: 'var(--primary)'); ?>; color: #fff;"><?php echo t('brand_settings.primary_button'); ?></button>
            <button type="button" class="btn" id="preview_btn_secondary" style="background: <?php echo htmlspecialchars($s['brand_color_secondary'] ?: 'var(--secondary)'); ?>; color: #fff;"><?php echo t('brand_settings.secondary_button'); ?></button>
            <?php if (hasModulePermission('brand_settings', 'edit')): ?>
                <button type="button" class="btn btn-secondary" onclick="resetBrandColors()" style="margin-left: auto;"><i class="fas fa-undo"></i> <?php echo t('brand_settings.reset_colors'); ?></button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (hasModulePermission('brand_settings', 'edit')): ?>
        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; font-size: 15px; font-weight: 700;" title="<?php echo t('brand_settings.save'); ?>">
            <i class="fas fa-save"></i> <?php echo t('brand_settings.save'); ?>
        </button>
    <?php endif; ?>
</form>

<script>
function toggleLogoTypeFields() {
    const type = document.getElementById('logo_type_select').value;
    document.getElementById('logo_icon_field').style.display = (type === 'icon') ? '' : 'none';
    document.getElementById('logo_image_field').style.display = (type === 'image') ? '' : 'none';
    updateBrandPreview();
}

function updateBrandPreview() {
    const type = document.getElementById('logo_type_select').value;
    const box = document.getElementById('brand_logo_preview_box');
    if (type === 'icon') {
        const iconName = document.getElementById('logo_icon_input').value.trim() || 'fa-network-wired';
        box.innerHTML = '<i class="fas ' + iconName.replace(/[^a-z0-9-]/gi, '') + '" style="font-size: 28px; color: var(--primary);"></i>';
    }
}

function previewFileInput(input, imgId) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const existing = document.getElementById(imgId);
        if (!existing) return;
        if (existing.tagName === 'IMG') {
            existing.src = e.target.result;
        } else {
            // Henüz yüklenmiş dosya yoksa yerine ikon (<i>) gösteriliyordu — ilk
            // yüklemede önizlemenin çalışması için gerçek bir <img>'e çeviriyoruz.
            const img = document.createElement('img');
            img.id = imgId;
            img.style.cssText = 'max-width:100%; max-height:100%; object-fit:contain;';
            img.src = e.target.result;
            existing.replaceWith(img);
        }
    };
    reader.readAsDataURL(input.files[0]);
}

function syncColorText(which) {
    document.getElementById(which + '_color_text').value = document.getElementById(which + '_color_picker').value;
    updateColorPreviewButtons();
}
function syncColorPicker(which) {
    const val = document.getElementById(which + '_color_text').value;
    if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
        document.getElementById(which + '_color_picker').value = val;
    }
    updateColorPreviewButtons();
}
function updateColorPreviewButtons() {
    const p = document.getElementById('primary_color_text').value;
    const s = document.getElementById('secondary_color_text').value;
    document.getElementById('preview_btn_primary').style.background = /^#[0-9A-Fa-f]{6}$/.test(p) ? p : 'var(--primary)';
    document.getElementById('preview_btn_secondary').style.background = /^#[0-9A-Fa-f]{6}$/.test(s) ? s : 'var(--secondary)';
}
function resetBrandColors() {
    document.getElementById('primary_color_text').value = '';
    document.getElementById('secondary_color_text').value = '';
    document.getElementById('primary_color_picker').value = '#0284c7';
    document.getElementById('secondary_color_picker').value = '#2563eb';
    updateColorPreviewButtons();
}
</script>
