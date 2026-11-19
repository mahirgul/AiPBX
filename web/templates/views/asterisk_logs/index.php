<?php
/** @var array $available @var string $log_key @var int $lines_limit @var string $level @var string $search @var array $result */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-file-alt', t('asterisk_logs.title'), 'asteriskLogsHelpBox'); ?>
    <?php echo uiPageHeaderActions(); ?>
    <?php echo uiPageHeaderClose(); ?>
    <?php echo uiHelpBoxOpen('asteriskLogsHelpBox', t('asterisk_logs.help_title')); ?>
        <?php echo $h(t('asterisk_logs.help_body')); ?>
    <?php echo uiHelpBoxClose(); ?>

    <form method="GET" action="/asterisk-logs" class="u-card-pad u-d-flex u-gap-12 u-ai-flex-end u-wrap">
        <div class="form-group u-mb-0">
            <label class="form-label"><?php echo $h(t('asterisk_logs.field_file')); ?></label>
            <select name="log" class="form-control">
                <?php foreach (AsteriskLogService::LOG_FILES as $k => $fname): ?>
                    <option value="<?php echo $h($k); ?>" <?php echo $log_key === $k ? 'selected' : ''; ?>>
                        <?php echo $h($fname); ?><?php echo isset($available[$k]) ? '' : ' (' . $h(t('asterisk_logs.unavailable')) . ')'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group u-mb-0">
            <label class="form-label"><?php echo $h(t('asterisk_logs.field_lines')); ?></label>
            <select name="lines" class="form-control">
                <?php foreach ([100, 200, 500, 1000] as $n): ?>
                    <option value="<?php echo $n; ?>" <?php echo $lines_limit === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group u-mb-0">
            <label class="form-label"><?php echo $h(t('asterisk_logs.field_level')); ?></label>
            <select name="level" class="form-control">
                <option value=""><?php echo $h(t('asterisk_logs.level_all')); ?></option>
                <?php foreach (['ERROR', 'WARNING', 'NOTICE', 'VERBOSE'] as $lv): ?>
                    <option value="<?php echo $lv; ?>" <?php echo strtoupper($level) === $lv ? 'selected' : ''; ?>><?php echo $lv; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group u-mb-0 u-flex-1">
            <label class="form-label"><?php echo $h(t('common.search', 'Search')); ?></label>
            <input type="text" name="q" value="<?php echo $h($search); ?>" class="form-control" placeholder="<?php echo $h(t('asterisk_logs.search_ph')); ?>">
        </div>

        <div class="form-group u-mb-0">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt"></i> <?php echo $h(t('asterisk_logs.btn_refresh')); ?></button>
        </div>
    </form>

    <div class="u-card-pad">
        <?php if (empty($result['ok'])): ?>
            <div class="alert alert-warning u-fs-12"><?php echo $h($result['error'] ?? t('asterisk_logs.empty')); ?></div>
        <?php elseif (empty($result['lines'])): ?>
            <p class="u-muted u-fs-13"><?php echo $h(t('asterisk_logs.empty')); ?></p>
        <?php else: ?>
            <div class="u-muted u-fs-12 u-mb-8"><?php echo $h(sprintf(t('asterisk_logs.showing_count'), count($result['lines']), $result['file'])); ?></div>
            <pre class="u-p-12 u-r-8 u-bd-1-solid-border-color u-bg-bg-sidebar u-fs-12 u-overflow-y-auto u-maxh-60vh u-white-space-pre-wrap u-word-break-break-word"><?php echo $h(implode("\n", $result['lines'])); ?></pre>
        <?php endif; ?>
    </div>
</div>
