<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$files = $files ?? [];
$selected = (string) ($selected ?? 'pjsip');
$meta = $selected_meta ?? ($files[$selected] ?? []);
$content = (string) ($content ?? '');
?>

<?php echo uiPageHeaderOpen('fa-file-code', t('custom_configs.title'), 'customConfigsHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
        <a href="/custom-dialplan" class="btn btn-secondary btn-sm"><i class="fas fa-code"></i> <?php echo $h(t('sidebar.item_custom_dialplan')); ?></a>
        <a href="/advanced-config" class="btn btn-secondary btn-sm"><i class="fas fa-sliders-h"></i> <?php echo $h(t('sidebar.item_advanced_config')); ?></a>
    <?php echo uiPageHeaderClose(); ?>

<?php echo uiHelpBoxOpen('customConfigsHelpBox', t('common.module_guide')); ?>
    <?php echo $h(t('custom_configs.help_body')); ?>
<?php echo uiHelpBoxClose(); ?>

<div class="grid grid-cols-1 lg:grid-cols-4 u-gap-16">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title"><i class="fas fa-folder-open u-primary"></i> <?php echo $h(t('custom_configs.files_title')); ?></h2>
        </div>
        <div class="card-body u-p-8">
            <div class="grid grid-cols-1 u-gap-4">
                <?php foreach ($files as $key => $f):
                    $isSel = ($key === $selected);
                ?>
                    <a href="/custom-configs?file=<?php echo $h($key); ?>"
                       class="btn btn-sm <?php echo $isSel ? 'btn-primary' : 'btn-secondary'; ?> u-flex-between">
                        <span class="u-mono u-fs-12"><?php echo $h($f['filename']); ?></span>
                        <?php if (!empty($f['has_content'])): ?>
                            <span class="badge badge-success u-fs-10"><?php echo $h(t('custom_configs.badge_modified')); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="lg:col-span-3">
        <div class="card">
            <div class="card-header u-flex-between">
                <h2 class="card-title"><i class="fas fa-edit u-primary"></i> <span class="u-mono"><?php echo $h($meta['filename'] ?? ''); ?></span></h2>
                <span class="badge badge-secondary u-mono u-fs-11"><?php echo $h($meta['reload'] ?? ''); ?></span>
            </div>
            <div class="card-body">
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
        </div>
    </div>
</div>
