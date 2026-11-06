<?php if (!empty($pageTestFailedAt)): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars(sprintf(t('system_update.page_test_failed'), $pageTestFailedAt)); ?></div>
<?php endif; ?>
<?php
$latest = $check['latest'] ?? '';
$available = !empty($check['available']);
// Older updaters did not say; then the update restarts Asterisk.
$restart = !array_key_exists('restart', $check ?? []) || !empty($check['restart']);
$state = $status['state'] ?? '';
$state_labels = [
    'running' => t('system_update.state_running'),
    'done' => t('system_update.state_done'),
    'failed' => t('system_update.state_failed'),
    'rolled_back' => t('system_update.state_rolled_back'),
];
$state_colors = ['running' => 'var(--info, #0ea5e9)', 'done' => 'var(--success)', 'failed' => 'var(--danger)', 'rolled_back' => 'var(--warning)'];
?>
<div class="card">
    <?php echo uiPageHeader('fa-cloud-arrow-down', t('system_update.page_title'), 'systemUpdateHelpBox'); ?>

    <?php echo uiHelpBoxOpen('systemUpdateHelpBox', t('system_update.help_title')); ?>
        <?php echo t('system_update.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

    <div class="u-grid-2 u-mb-20">
        <div class="card u-p-16">
            <div class="u-muted u-fs-12"><?php echo t('system_update.current'); ?></div>
            <div class="u-fs-24 u-fw-800" id="su-current">v<?php echo htmlspecialchars($current); ?></div>
        </div>
        <div class="card u-p-16">
            <div class="u-muted u-fs-12"><?php echo t('system_update.latest'); ?></div>
            <div class="u-fs-24 u-fw-800" id="su-latest"><?php echo htmlspecialchars($latest ?: '—'); ?></div>
            <div class="u-muted u-fs-11" id="su-checked">
                <?php if (!empty($check['checked_at'])): ?>
                    <?php echo t('system_update.checked_at'); ?>: <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($check['checked_at']))); ?>
                <?php else: ?>
                    <?php echo t('system_update.never_checked'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="u-flex-gap u-wrap u-ai-center u-mb-16">
        <button type="button" class="btn btn-secondary" id="su-check-btn" onclick="suCheck()">
            <i class="fas fa-rotate"></i> <?php echo t('system_update.btn_check'); ?>
        </button>
        <button type="button" class="btn btn-primary" id="su-start-btn" onclick="suStart()" <?php echo ($available && $state !== 'running') ? '' : 'disabled'; ?>>
            <i class="fas fa-download"></i> <?php echo t('system_update.btn_update'); ?>
        </button>
    </div>

    <div id="su-calls-choice" class="u-fs-12" style="margin-bottom: 12px; <?php echo $restart ? '' : 'display: none;'; ?>">
        <div class="u-fw-600 u-mb-4"><?php echo t('system_update.calls_choice'); ?></div>
        <label class="u-check-label u-mb-4" style="display: block;"><input type="radio" name="su-calls" value="wait" class="u-accent" checked> <?php echo t('system_update.calls_wait'); ?></label>
        <label class="u-check-label" style="display: block;"><input type="radio" name="su-calls" value="end" class="u-accent"> <?php echo t('system_update.calls_end'); ?></label>
    </div>
    <div id="su-waiting" class="u-fs-12 u-mb-12" style="display: none;">
        <button type="button" class="btn btn-danger btn-sm" onclick="suWhileWaiting('end_calls')"><?php echo t('system_update.end_calls_now'); ?></button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="suWhileWaiting('cancel')"><?php echo t('system_update.cancel_update'); ?></button>
    </div>
    <div id="su-restart" class="u-fs-12" style="margin-bottom: 12px; <?php echo $available ? '' : 'display: none;'; ?> color: <?php echo $restart ? 'var(--warning)' : 'var(--success)'; ?>;">
        <?php if ($restart): ?>
            <i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars(sprintf(t('system_update.restart_yes'), (string)($check['restart_reason'] ?? ''))); ?>
        <?php else: ?>
            <i class="fas fa-circle-check"></i> <?php echo htmlspecialchars(t('system_update.restart_no')); ?>
        <?php endif; ?>
    </div>

    <div id="su-state" class="u-fw-600" style="margin-bottom: 10px; color: <?php echo $state_colors[$state] ?? 'inherit'; ?>;">
        <?php if ($state !== ''): ?>
            <?php echo htmlspecialchars(($state_labels[$state] ?? $state) . (!empty($status['step']) ? ' — ' . $status['step'] : '') . (!empty($status['updated_at']) ? ' (' . date('d.m.Y H:i', strtotime($status['updated_at'])) . ')' : '')); ?>
        <?php endif; ?>
    </div>

    <div id="su-notes-box" style="<?php echo $available ? '' : 'display: none;'; ?> margin-bottom: 16px;">
        <div class="u-strong u-mb-6"><?php echo t('system_update.notes'); ?></div>
        <pre class="u-white-space-pre-wrap u-bg-bg-main u-bd-1-solid-border-color u-r-8 u-p-12 u-fs-12 u-maxh-260 u-of-auto" id="su-notes"><?php echo htmlspecialchars($check['notes'] ?? ''); ?></pre>
    </div>

    <div>
        <div class="u-strong u-mb-6"><?php echo t('system_update.log'); ?></div>
        <pre class="u-white-space-pre-wrap u-bg-0f172a u-c-e2e8f0 u-r-8 u-p-12 u-fs-11 u-maxh-360 u-of-auto" id="su-log"><?php echo htmlspecialchars($log_tail ?: '—'); ?></pre>
    </div>
</div>

<script>
window.SYSTEM_UPDATE_PAGE = {
    running: <?php echo json_encode($state === 'running'); ?>,
    restart: <?php echo json_encode($restart); ?>,
    text: {
    running: <?php echo json_encode(t('system_update.state_running')); ?>,
    done: <?php echo json_encode(t('system_update.state_done')); ?>,
    failed: <?php echo json_encode(t('system_update.state_failed')); ?>,
    rolled_back: <?php echo json_encode(t('system_update.state_rolled_back')); ?>,
    confirm: <?php echo json_encode(t('system_update.confirm')); ?>,
    confirm_no_restart: <?php echo json_encode(t('system_update.confirm_no_restart')); ?>,
    confirm_end_calls: <?php echo json_encode(t('system_update.confirm_end_calls')); ?>,
    restart_yes: <?php echo json_encode(t('system_update.restart_yes')); ?>,
    restart_no: <?php echo json_encode(t('system_update.restart_no')); ?>,
    up_to_date: <?php echo json_encode(t('system_update.up_to_date')); ?>,
    unreachable: <?php echo json_encode(t('system_update.unreachable')); ?>
    }
};
</script>
<script src="<?php echo asset('/assets/js/system_update.js'); ?>"></script>
