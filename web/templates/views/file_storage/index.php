<?php
/** @var string $csrf @var array $settings @var string $maskedSecret @var array $status */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
$backend = $settings['storage_backend'];
$inUse = $status['backend'] ?? '';
$migration = $status['migration'] ?? [];
$localFiles = (int) ($status['local_files'] ?? 0);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-hard-drive u-primary"></i> <?php echo t('storage.header_title'); ?>
            <?php if (empty($status['reachable'])): ?>
                <span class="badge badge-warning" style="margin-left: 8px; font-size: 11px;"><?php echo t('storage.chat_unreachable'); ?></span>
            <?php else: ?>
                <span class="badge badge-success" style="margin-left: 8px; font-size: 11px;"><?php echo t('storage.in_use_' . ($inUse === 's3' ? 's3' : 'local')); ?></span>
            <?php endif; ?>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('storageHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="storageHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('storage.help_title'); ?></h4>
        <?php echo t('storage.help_body'); ?>
    </div>

    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;" id="storageForm">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">

        <div class="form-group">
            <label class="form-label"><?php echo t('storage.backend'); ?></label>
            <?php foreach (FileStorageService::BACKENDS as $b): ?>
                <label class="u-check-label-6" style="display: block; margin-bottom: 4px;">
                    <input type="radio" name="storage_backend" value="<?php echo $b; ?>" class="u-accent storage-backend" <?php echo $backend === $b ? 'checked' : ''; ?>>
                    <strong><?php echo t('storage.backend_' . $b); ?></strong>
                    <span class="u-muted u-fs-12">· <?php echo t('storage.backend_' . $b . '_desc'); ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="storage-s3">
            <div class="u-grid-2">
                <div class="form-group">
                    <label class="form-label"><?php echo t('storage.endpoint'); ?></label>
                    <input type="text" name="storage_s3_endpoint" class="form-control" value="<?php echo $h($settings['storage_s3_endpoint']); ?>" placeholder="https://minio.example.com:9000">
                    <small class="u-hint u-fs-11"><?php echo t('storage.endpoint_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('storage.region'); ?></label>
                    <input type="text" name="storage_s3_region" class="form-control" value="<?php echo $h($settings['storage_s3_region']); ?>" placeholder="us-east-1">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('storage.bucket'); ?></label>
                    <input type="text" name="storage_s3_bucket" class="form-control" value="<?php echo $h($settings['storage_s3_bucket']); ?>" placeholder="aipbx-chat">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('storage.prefix'); ?></label>
                    <input type="text" name="storage_s3_prefix" class="form-control" value="<?php echo $h($settings['storage_s3_prefix']); ?>" placeholder="pbx1">
                    <small class="u-hint u-fs-11"><?php echo t('storage.prefix_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('storage.access_key'); ?></label>
                    <input type="text" name="storage_s3_access_key" class="form-control" value="<?php echo $h($settings['storage_s3_access_key']); ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('storage.secret_key'); ?></label>
                    <input type="password" name="storage_s3_secret_key" class="form-control" value="" autocomplete="new-password"
                           placeholder="<?php echo $h($maskedSecret !== '' ? $maskedSecret . ' · ' . t('storage.secret_keep') : ''); ?>">
                </div>
            </div>
            <label class="u-check-label-6" style="display: block;">
                <input type="checkbox" name="storage_s3_path_style" value="1" class="u-accent" <?php echo $settings['storage_s3_path_style'] === '1' ? 'checked' : ''; ?>>
                <?php echo t('storage.path_style'); ?>
            </label>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
            <button type="submit" name="save_storage" value="1" class="btn btn-primary"><i class="fas fa-check"></i> <?php echo t('storage.save'); ?></button>
            <button type="submit" name="test_storage" value="1" class="btn btn-secondary storage-s3"><i class="fas fa-plug-circle-check"></i> <?php echo t('storage.test'); ?></button>
        </div>
    </form>
</div>

<?php if (!empty($status['reachable']) && ($inUse === 's3' || !empty($migration['started']))): ?>
<div class="card" style="margin-top: 16px;">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-cloud-arrow-up u-primary"></i> <?php echo t('storage.migrate_title'); ?></div>
    </div>
    <div style="padding: 0 20px 20px;">
        <p><?php echo sprintf(t('storage.local_files'), $localFiles); ?></p>
        <?php if (!empty($migration['started'])): ?>
            <p class="u-muted u-fs-12">
                <?php echo !empty($migration['running'])
                    ? sprintf(t('storage.migrate_running'), (int) $migration['moved'], (int) $migration['total'])
                    : sprintf(t('storage.migrate_done'), (int) $migration['moved'], (int) $migration['failed']); ?>
                <?php if (!empty($migration['last_error'])): ?>
                    <br><?php echo $h(t('storage.migrate_last_error') . ' ' . $migration['last_error']); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($inUse === 's3' && $localFiles > 0 && empty($migration['running'])): ?>
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <button type="submit" name="migrate_storage" value="1" class="btn btn-secondary"><i class="fas fa-truck-moving"></i> <?php echo t('storage.migrate'); ?></button>
            </form>
            <small class="u-hint u-fs-11"><?php echo t('storage.migrate_help'); ?></small>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/file_storage.js'); ?>"></script>
