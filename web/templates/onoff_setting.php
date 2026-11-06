<?php
/**
 * A setting row that turns a whole feature on or off (Outbound and Inbound Normalization).
 *
 * @var array{label: string, desc: string, on: bool, can_edit: bool, csrf: string, confirm_off: string, fields: array<string, string>} $onoff
 *      fields: the hidden inputs that name the action; 'enabled' is added.
 */
?>
<div class="dpr-setting">
    <div>
        <div class="dpr-setting-title">
            <?php echo $onoff['label']; ?>
            <span class="badge <?php echo $onoff['on'] ? 'badge-success' : 'badge-danger'; ?>"><?php echo t($onoff['on'] ? 'dialplan_rules.state_on' : 'dialplan_rules.state_off'); ?></span>
        </div>
        <div class="dpr-setting-desc"><?php echo $onoff['desc']; ?></div>
    </div>
    <?php if ($onoff['can_edit']): ?>
        <form method="POST"<?php if ($onoff['on']): ?> onsubmit="return confirm(<?php echo htmlspecialchars(json_encode($onoff['confirm_off']), ENT_QUOTES); ?>);"<?php endif; ?>>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($onoff['csrf'], ENT_QUOTES); ?>">
            <?php foreach ($onoff['fields'] + ['enabled' => $onoff['on'] ? '0' : '1'] as $k => $v): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($k, ENT_QUOTES); ?>" value="<?php echo htmlspecialchars($v, ENT_QUOTES); ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-sm <?php echo $onoff['on'] ? 'btn-secondary' : 'btn-success'; ?>">
                <i class="fas fa-power-off"></i> <?php echo t($onoff['on'] ? 'dialplan_rules.btn_turn_off' : 'dialplan_rules.btn_turn_on'); ?>
            </button>
        </form>
    <?php endif; ?>
</div>
