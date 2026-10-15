<?php
/**
 * PBX → Phone logs: the syslog of desk phones (aipbx-syslogd), filtered by
 * IP or MAC and text, followed live.
 *
 * @var string $csrf
 * @var bool $receiving
 * @var bool $enabled
 * @var array<string, string> $labels MAC → "1001 · model"
 * @var string $who
 */
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-scroll u-primary"></i> <?php echo t('phonelogs.header_title'); ?>
            <span class="badge <?php echo $receiving ? 'badge-success' : 'badge-warning'; ?>" style="margin-left: 8px; font-size: 11px;">
                <?php echo $receiving ? t('phonelogs.receiving') : t('phonelogs.not_receiving'); ?>
            </span>
        </div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('phoneLogsHelpBox')" title="<?php echo t('common.module_guide'); ?>">
            <i class="fas fa-question-circle"></i>
        </button>
    </div>

    <div class="module-help-box" id="phoneLogsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('phonelogs.help_title'); ?></h4>
        <?php echo t('phonelogs.help_body'); ?>
    </div>

    <?php if (!$receiving): ?>
        <div class="alert alert-warning" style="margin: 0 20px 12px;">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo $enabled ? t('phonelogs.receiver_down') : t('phonelogs.receiver_off'); ?>
            <a href="/network-services"><?php echo t('sidebar.item_network_services'); ?></a>
        </div>
    <?php endif; ?>

    <div id="phoneLogs" data-labels="<?php echo $h(json_encode($labels)); ?>" style="padding: 0 20px 20px;">
        <div class="u-flex-gap u-mb-10" style="flex-wrap: wrap; align-items: center;">
            <input type="text" id="plWho" class="form-control" style="max-width: 260px;" maxlength="64"
                   value="<?php echo $h($who); ?>" placeholder="<?php echo $h(t('phonelogs.who_placeholder')); ?>">
            <input type="text" id="plText" class="form-control" style="max-width: 260px;" maxlength="200"
                   placeholder="<?php echo $h(t('phonelogs.text_placeholder')); ?>">
            <select id="plLevel" class="form-control" style="max-width: 180px;">
                <option value="7"><?php echo t('phonelogs.level_all'); ?></option>
                <option value="4"><?php echo t('phonelogs.level_warning'); ?></option>
                <option value="3"><?php echo t('phonelogs.level_error'); ?></option>
            </select>
            <label class="u-check-label-6"><input type="checkbox" id="plLive" class="u-accent" checked> <?php echo t('phonelogs.live'); ?></label>
            <button type="button" class="btn btn-secondary btn-sm" id="plClear"><i class="fas fa-eraser"></i> <?php echo t('phonelogs.clear'); ?></button>
            <span class="text-muted u-fs-11" id="plCount"></span>
        </div>
        <div id="plBox" style="height: 65vh; overflow: auto; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 8px; font-family: monospace; font-size: 12px; line-height: 1.5;">
            <table class="table" style="margin: 0; font-size: 12px;">
                <tbody id="plRows"></tbody>
            </table>
            <div class="text-muted" id="plEmpty" style="padding: 16px;"><?php echo t('phonelogs.empty'); ?></div>
        </div>
    </div>
</div>
<script src="<?php echo asset('/assets/js/phone_logs.js'); ?>"></script>
