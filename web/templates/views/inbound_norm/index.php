<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$form = $edit ?? ['id' => 0, 'trunk_name' => '', 'target' => 'caller', 'rule_name' => '', 'match_pattern' => '', 'strip_front' => 0, 'prepend' => '', 'is_active' => 1];
$trunkOptions = static function (?string $selected) use ($trunks, $h): string {
    $out = '<option value="">' . t('inbound_norm.all_trunks') . '</option>';
    foreach ($trunks as $trunk) {
        $out .= '<option value="' . $h($trunk) . '"' . ((string) $selected === (string) $trunk ? ' selected' : '') . '>' . $h($trunk) . '</option>';
    }
    return $out;
};
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $h($message); ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($error); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-sliders-h u-primary"></i> <?php echo t('inbound_norm.title'); ?></div>
        <button type="button" class="btn-help" onclick="toggleModuleHelp('inboundNormHelpBox')"><i class="fas fa-question-circle"></i></button>
    </div>
    <div class="module-help-box" id="inboundNormHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('inbound_norm.title'); ?></h4>
        <p><?php echo t('inbound_norm.help'); ?></p>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('inbound_norm.limit_order'); ?></li></ul></div>
    </div>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th><?php echo t('inbound_norm.field_trunk'); ?></th><th><?php echo t('inbound_norm.field_target'); ?></th><th><?php echo t('inbound_norm.field_name'); ?></th><th><?php echo t('inbound_norm.field_pattern'); ?></th><th><?php echo t('inbound_norm.field_strip'); ?></th><th><?php echo t('inbound_norm.field_prepend'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
            <tr style="<?php echo empty($r['is_active']) ? 'opacity: .5;' : ''; ?>">
                <td><?php echo $r['trunk_name'] !== null ? $h($r['trunk_name']) : t('inbound_norm.all_trunks'); ?></td>
                <td><?php echo t('inbound_norm.target_' . $r['target']); ?></td>
                <td><?php echo $h($r['rule_name']); ?></td>
                <td><code><?php echo $h($r['match_pattern']); ?></code></td>
                <td><?php echo (int) $r['strip_front']; ?></td>
                <td><code><?php echo $h($r['prepend']); ?></code></td>
                <td style="white-space: nowrap;"><?php if ($canEdit): ?>
                    <a class="btn btn-xs btn-secondary" href="/inbound-normalization?edit=<?php echo (int) $r['id']; ?>"><i class="fas fa-edit"></i></a>
                    <form method="POST" style="display: inline;"><input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>"><input type="hidden" name="delete_rule" value="1"><input type="hidden" name="rule_id" value="<?php echo (int) $r['id']; ?>"><button type="submit" class="btn btn-xs btn-danger"><i class="fas fa-trash"></i></button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rules): ?><tr><td colspan="7" class="u-muted"><?php echo t('inbound_norm.empty'); ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-vial u-primary"></i> <?php echo t('inbound_norm.simulate_title'); ?></div></div>
    <form method="POST" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="simulate">
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_trunk'); ?></label><select name="trunk_name" class="form-control"><?php echo $trunkOptions($simulation['trunk'] ?? ''); ?></select></div>
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_target'); ?></label><select name="target" class="form-control"><option value="caller"><?php echo t('inbound_norm.target_caller'); ?></option><option value="did" <?php echo ($simulation['target'] ?? '') === 'did' ? 'selected' : ''; ?>><?php echo t('inbound_norm.target_did'); ?></option></select></div>
        </div>
        <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_number'); ?></label><input type="text" name="number" class="form-control" maxlength="40" required placeholder="+4366412345678" value="<?php echo $h($simulation['number'] ?? ''); ?>"></div>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-play"></i> <?php echo t('inbound_norm.simulate'); ?></button>
        <?php if ($simulation !== null): ?>
            <div class="alert alert-info" id="inboundNormResult" style="margin-top: 12px;"><code><?php echo $h($simulation['number']); ?></code> → <code><?php echo $h($simulation['result']); ?></code> · <?php echo $simulation['rule'] !== null ? $h($simulation['rule']) : t('inbound_norm.no_rule'); ?></div>
        <?php endif; ?>
    </form>
</div>

<?php if ($canEdit): ?>
<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-plus-circle u-primary"></i> <?php echo t($edit ? 'inbound_norm.edit_title' : 'inbound_norm.new_title'); ?></div></div>
    <form method="POST" autocomplete="off" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="save_rule" value="1">
        <input type="hidden" name="rule_id" value="<?php echo (int) $form['id']; ?>">
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_trunk'); ?></label><select name="trunk_name" class="form-control"><?php echo $trunkOptions($form['trunk_name']); ?></select></div>
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_target'); ?></label><select name="target" class="form-control"><option value="caller"><?php echo t('inbound_norm.target_caller'); ?></option><option value="did" <?php echo $form['target'] === 'did' ? 'selected' : ''; ?>><?php echo t('inbound_norm.target_did'); ?></option></select></div>
        </div>
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_name'); ?></label><input type="text" name="rule_name" class="form-control" maxlength="120" required value="<?php echo $h($form['rule_name']); ?>"></div>
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_pattern'); ?></label><input type="text" name="match_pattern" class="form-control" maxlength="40" required placeholder="_+43." value="<?php echo $h($form['match_pattern']); ?>"></div>
        </div>
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_strip'); ?></label><input type="number" name="strip_front" class="form-control" min="0" max="20" value="<?php echo (int) $form['strip_front']; ?>"></div>
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_prepend'); ?></label><input type="text" name="prepend" class="form-control" maxlength="30" placeholder="0" value="<?php echo $h($form['prepend']); ?>"></div>
        </div>
        <label style="display: flex; align-items: center; gap: 6px; margin-bottom: 12px; font-size: 13px;"><input type="checkbox" name="is_active" value="1" <?php echo !empty($form['is_active']) ? 'checked' : ''; ?>> <?php echo t('inbound_norm.field_active'); ?></label>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
        <span class="u-muted u-fs-12"> · <?php echo t('netset.apply_note'); ?></span>
    </form>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-magic u-primary"></i> <?php echo t('inbound_norm.presets_title'); ?></div></div>
    <form method="POST" style="padding: 0 20px 20px;">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="load_presets" value="1">
        <p class="u-muted u-fs-12"><?php echo t('inbound_norm.presets_help'); ?></p>
        <div class="u-grid-2">
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_trunk'); ?></label><select name="trunk_name" class="form-control"><?php echo $trunkOptions(''); ?></select></div>
            <div class="form-group"><label class="form-label"><?php echo t('inbound_norm.field_country'); ?></label><select name="country_code" class="form-control" required><option value=""></option><?php foreach ($countries as $cc => $name): ?><option value="<?php echo $h($cc); ?>"><?php echo $h($name); ?> (+<?php echo $h($cc); ?>)</option><?php endforeach; ?></select></div>
        </div>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-download"></i> <?php echo t('inbound_norm.presets_load'); ?></button>
    </form>
</div>
<?php endif; ?>
