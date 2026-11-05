<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$appliedKey = static fn(array $e): string => json_encode([$e['object_type'], $e['object_name'], trim((string) $e['options']), (int) $e['is_active']]);
$appliedSet = [];
foreach ($applied as $a) {
    $appliedSet[$appliedKey($a)] = true;
}
$form = $edit ?? ['id' => 0, 'object_type' => 'endpoint', 'object_name' => '', 'options' => '', 'note' => '', 'is_active' => 1];
// A save that failed: the window opens again with what was typed.
$reopen = !empty($error) && !empty($_POST['save_entry']);
if ($reopen) {
    $form = [
        'id' => (int) ($_POST['id'] ?? 0), 'object_type' => (string) ($_POST['object_type'] ?? 'endpoint'),
        'object_name' => (string) ($_POST['object_name'] ?? ''), 'options' => (string) ($_POST['options'] ?? ''),
        'note' => (string) ($_POST['note'] ?? ''), 'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ];
}
?>
<?php if (!empty($message)): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $h($message); ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($error); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-sliders-h u-primary"></i> <?php echo t('advanced_config.title'); ?></div>
        <div class="u-flex-gap">
            <button type="button" class="btn btn-primary btn-sm" onclick="UIHelper.openOverlayModal('advancedEntryModal')"><i class="fas fa-plus"></i> <?php echo t('advanced_config.add'); ?></button>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('advancedConfigHelpBox')"><i class="fas fa-question-circle"></i></button>
        </div>
    </div>
    <div class="module-help-box" id="advancedConfigHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('advanced_config.title'); ?></h4>
        <p><?php echo t('advanced_config.intro'); ?></p>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('advanced_config.limit_values'); ?></li><li><?php echo t('advanced_config.limit_generated'); ?></li></ul></div>
    </div>
    <p class="u-muted u-fs-12" style="margin: 0 20px 12px;"><?php echo t('advanced_config.intro'); ?></p>
    <?php if (!$entries): ?>
        <p class="u-muted" style="padding: 0 20px 16px;"><?php echo t('advanced_config.empty'); ?></p>
    <?php else: ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th><?php echo t('advanced_config.field_type'); ?></th><th><?php echo t('advanced_config.field_name'); ?></th><th><?php echo t('advanced_config.field_options'); ?></th><th><?php echo t('advanced_config.status'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e):
            $exists = in_array($e['object_type'] . ':' . $e['object_name'], $objects, true);
            $isApplied = isset($appliedSet[$appliedKey($e)]);
        ?>
            <tr>
                <td><?php echo $h($e['object_type']); ?></td>
                <td><?php echo $h($e['object_name']); ?><?php if ($e['note'] !== null && $e['note'] !== ''): ?><div class="u-muted u-fs-11"><?php echo $h($e['note']); ?></div><?php endif; ?></td>
                <td><code style="white-space: pre-wrap;"><?php echo $h($e['options']); ?></code></td>
                <td>
                    <?php if (empty($e['is_active'])): ?><span class="badge badge-secondary"><?php echo t('advanced_config.status_off'); ?></span>
                    <?php elseif (!$exists): ?><span class="badge badge-warning"><?php echo t('advanced_config.status_missing'); ?></span>
                    <?php elseif (!$isApplied): ?><span class="badge badge-warning"><?php echo t('advanced_config.status_pending'); ?></span>
                    <?php else: ?><span class="badge badge-success"><?php echo t('advanced_config.status_applied'); ?></span><?php endif; ?>
                </td>
                <td style="white-space: nowrap;">
                    <a class="btn btn-xs btn-secondary" href="/advanced-config?edit=<?php echo (int) $e['id']; ?>"><i class="fas fa-edit"></i></a>
                    <form method="POST" action="/advanced-config" style="display: inline;" onsubmit="return confirm(<?php echo jsArg(t('advanced_config.delete_confirm')); ?>);">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="delete_entry" value="1">
                        <input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
                        <button type="submit" class="btn btn-xs btn-danger" title="<?php echo t('common.delete'); ?>"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>


<div class="modal-overlay<?php echo $edit || $reopen ? ' active' : ''; ?>" id="advancedEntryModal"<?php echo $edit || $reopen ? ' style="display: flex;"' : ''; ?>>
    <div class="modal-card" style="max-width: 640px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-sliders-h u-primary"></i> <?php echo t('advanced_config.title'); ?></h3>
            <a class="btn btn-secondary u-btn-pad" href="/advanced-config"><i class="fas fa-times"></i></a>
        </div>
        <div class="modal-body">
    <form method="POST" action="/advanced-config" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="save_entry" value="1">
        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('advanced_config.field_type'); ?></label>
                <select name="object_type" class="form-control">
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo $h($type); ?>" <?php echo $form['object_type'] === $type ? 'selected' : ''; ?>><?php echo $h($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('advanced_config.field_name'); ?></label>
                <input type="text" name="object_name" class="form-control" maxlength="80" required list="advancedObjects" value="<?php echo $h($form['object_name']); ?>" placeholder="transport-udp">
                <datalist id="advancedObjects">
                    <?php foreach ($objects as $object): ?>
                        <option value="<?php echo $h(substr($object, strpos($object, ':') + 1)); ?>"><?php echo $h(strtok($object, ':')); ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('advanced_config.field_options'); ?></label>
            <textarea name="options" class="form-control" rows="4" required spellcheck="false" style="font-family: monospace;" placeholder="tos=cs3&#10;symmetric_transport=yes"><?php echo $h($form['options']); ?></textarea>
            <small class="u-hint"><?php echo t('advanced_config.options_help'); ?></small>
        </div>
        <div class="form-group">
            <label class="form-label"><?php echo t('advanced_config.field_note'); ?></label>
            <input type="text" name="note" class="form-control" maxlength="200" value="<?php echo $h($form['note']); ?>">
        </div>
        <label style="display: flex; align-items: center; gap: 6px; margin-bottom: 12px; font-size: 13px;">
            <input type="checkbox" name="is_active" value="1" <?php echo !empty($form['is_active']) ? 'checked' : ''; ?>> <?php echo t('advanced_config.field_active'); ?>
        </label>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
        <a class="btn btn-secondary" href="/advanced-config"><?php echo t('common.cancel'); ?></a>
        <span class="u-muted u-fs-12"> · <?php echo t('netset.apply_note'); ?></span>
    </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-history u-primary"></i> <?php echo t('advanced_config.versions_title'); ?></div></div>
    <p class="u-muted u-fs-12" style="margin: 0 20px 12px;"><?php echo t('advanced_config.versions_help'); ?></p>
    <?php if ($versions): ?>
    <div class="table-responsive"><table class="data-table">
        <tbody>
        <?php foreach ($versions as $v): ?>
            <tr>
                <td><?php echo $h($v['created_at']); ?></td>
                <td><?php echo sprintf(t('advanced_config.version_before'), $h($v['label'])); ?></td>
                <td><?php echo $h($v['username'] ?? ''); ?></td>
                <td style="text-align: right;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="restore_version" value="1">
                        <input type="hidden" name="version_id" value="<?php echo (int) $v['id']; ?>">
                        <button type="submit" class="btn btn-xs btn-secondary"><i class="fas fa-undo"></i> <?php echo t('advanced_config.restore'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-file-code u-primary"></i> <?php echo t('advanced_config.edited_title'); ?></div></div>
    <p class="u-muted u-fs-12" style="margin: 0 20px 12px;"><?php echo t('advanced_config.edited_help'); ?></p>
    <?php if ($edited): ?>
    <ul style="margin: 0 20px 16px 36px;">
        <?php foreach ($edited as $path): ?><li><code><?php echo $h($path); ?></code></li><?php endforeach; ?>
    </ul>
    <?php else: ?>
    <p class="u-muted" style="padding: 0 20px 16px;"><?php echo t('advanced_config.edited_none'); ?></p>
    <?php endif; ?>
</div>
