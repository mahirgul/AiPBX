/* Page script of templates/views/brand_settings/index.php */

function toggleLogoTypeFields() {
    const type = document.getElementById('logo_type_select').value;
    document.getElementById('logo_icon_field').style.display = (type === 'icon') ? '' : 'none';
    document.getElementById('logo_image_field').style.display = (type === 'image') ? '' : 'none';
    document.getElementById('logo_dark_image_field').style.display = (type === 'image') ? '' : 'none';
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
            // Without an uploaded file an icon (<i>) was shown instead — turn it
            // into a real <img> so the preview works on the first upload.
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
