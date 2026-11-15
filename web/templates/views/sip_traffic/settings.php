<?php
/** @var string $csrf @var array $settings @var array $status */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-sliders', t('siptrace.settings_title')); ?>
<?php echo uiPageHeaderActions(); ?>
        <a href="/sip-traffic" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> <?php echo t('siptrace.back_files'); ?></a>
        <a href="/sip-live" class="btn btn-secondary btn-sm"><i class="fas fa-satellite-dish"></i> <?php echo t('sidebar.item_sip_live'); ?></a>
<?php echo uiPageHeaderClose(); ?>

    <div class="alert alert-info u-fs-13 u-mb-12" style="display: flex; flex-wrap: wrap; gap: 16px;">
        <span><strong><?php echo t('siptrace.status_events_24h'); ?>:</strong> <?php echo (int) ($status['rows'] ?? 0); ?></span>
        <span><strong><?php echo t('siptrace.status_last_event'); ?>:</strong> <?php echo $h(!empty($status['last']) ? $status['last'] : '—'); ?></span>
        <span><strong><?php echo t('siptrace.status_files'); ?>:</strong> <?php echo (int) ($status['files'] ?? 0); ?> (<?php echo $h(number_format(((int) ($status['bytes'] ?? 0)) / 1048576, 2)); ?> MB)</span>
    </div>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <div class="form-group">
            <label class="form-label u-check-label">
                <input type="hidden" name="capture" value="0">
                <input type="checkbox" name="capture" value="1" class="u-check" <?php echo !empty($settings['capture']) ? 'checked' : ''; ?>>
                <span class="u-fw-600"><?php echo t('siptrace.field_capture'); ?></span>
            </label>
        </div>
        <div class="form-group">
            <label class="form-label u-check-label">
                <input type="hidden" name="files" value="0">
                <input type="checkbox" name="files" value="1" class="u-check" <?php echo !empty($settings['files']) ? 'checked' : ''; ?>>
                <span><?php echo t('siptrace.field_files'); ?></span>
            </label>
        </div>
        <div class="form-group u-mt-10" style="max-width: 280px;">
            <label class="form-label" for="sip_days"><?php echo t('siptrace.field_days'); ?></label>
            <input type="number" id="sip_days" name="days" min="1" max="10" class="form-control" value="<?php echo (int) ($settings['days'] ?? 10); ?>">
        </div>
        <div class="form-group u-mt-10" style="max-width: 280px;">
            <label class="form-label" for="sip_max_mb"><?php echo t('siptrace.field_max_mb'); ?></label>
            <input type="number" id="sip_max_mb" name="max_mb" min="100" max="51200" class="form-control" value="<?php echo (int) ($settings['max_mb'] ?? 2048); ?>">
        </div>
        <div class="form-group u-mt-10" style="max-width: 280px;">
            <label class="form-label" for="sip_retention"><?php echo t('siptrace.field_retention'); ?></label>
            <input type="number" id="sip_retention" name="retention" min="1" max="3650" class="form-control" value="<?php echo (int) ($settings['retention'] ?? 30); ?>">
        </div>
        <div class="u-mt-12">
            <button type="submit" name="save_sip_capture" value="1" class="btn btn-primary btn-sm">
                <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
            </button>
        </div>
    </form>
</div>
