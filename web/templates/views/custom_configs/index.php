<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$files = $files ?? [];
$selected = (string) ($selected ?? 'pjsip');
$meta = $selected_meta ?? ($files[$selected] ?? []);
$content = (string) ($content ?? '');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-file-code', t('custom_configs.title'), 'customConfigsHelpBox'); ?>
        <?php echo uiPageHeaderActions(); ?>
            <a href="/custom-dialplan" class="btn btn-secondary btn-sm"><i class="fas fa-code"></i> <?php echo $h(t('sidebar.item_custom_dialplan')); ?></a>
            <a href="/advanced-config" class="btn btn-secondary btn-sm"><i class="fas fa-sliders-h"></i> <?php echo $h(t('sidebar.item_advanced_config')); ?></a>
        <?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('customConfigsHelpBox', t('common.module_guide')); ?>
        <?php echo $h(t('custom_configs.help_body')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <div class="u-d-flex u-jc-space-between u-ai-center u-wrap u-gap-12 u-mb-12">
        <div class="u-d-flex u-ai-center u-gap-8 u-wrap">
            <label for="customConfigSelect" class="form-label u-m-0 u-fw-600"><i class="fas fa-folder-open u-primary"></i> <?php echo $h(t('custom_configs.files_title')); ?>:</label>
            <select id="customConfigSelect" class="form-control form-control-sm u-w-auto u-mono" onchange="window.location='/custom-configs?file=' + encodeURIComponent(this.value)">
                <?php foreach ($files as $key => $f): ?>
                    <option value="<?php echo $h($key); ?>"<?php echo $key === $selected ? ' selected' : ''; ?>>
                        <?php echo $h($f['filename'] . (!empty($f['has_content']) ? ' (' . t('custom_configs.badge_modified') . ')' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (!empty($meta['reload'])): ?>
            <span class="badge badge-secondary u-mono u-fs-11"><?php echo $h($meta['reload']); ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($meta['first_value_wins'])): ?>
        <div class="alert alert-warning u-mb-12">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $h(t('custom_configs.first_value_warning')); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/custom-configs?file=<?php echo $h($selected); ?>" data-no-spa="true">
        <input type="hidden" name="csrf_token" value="<?php echo $h(getCSRFToken()); ?>">
        <input type="hidden" name="save_custom_config" value="1">
        <input type="hidden" name="config_key" value="<?php echo $h($selected); ?>">

        <div class="form-group">
            <textarea name="content" class="form-control u-mono u-fs-12" rows="18" spellcheck="false"><?php echo $h($content); ?></textarea>
            <small class="u-hint"><?php echo $h(t('custom_configs.editor_hint')); ?></small>
        </div>

        <div class="u-d-flex u-jc-flex-end u-mt-12">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo $h(t('custom_configs.btn_save_reload')); ?></button>
        </div>
    </form>
</div>
