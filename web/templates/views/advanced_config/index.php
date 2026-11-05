<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$appliedKey = static fn(array $e): string => json_encode([$e['config_file'] ?? 'pjsip', $e['target'] ?? 'object', $e['object_type'], $e['object_name'], trim((string) $e['options']), (int) $e['is_active']]);
$appliedSet = [];
foreach ($applied as $a) {
    $appliedSet[$appliedKey($a)] = true;
}
$form = $edit ?? ['id' => 0, 'config_file' => 'pjsip', 'target' => 'object', 'object_type' => 'endpoint', 'object_name' => '', 'options' => '', 'note' => '', 'is_active' => 1];
// A save that failed: the window opens again with what was typed.
$reopen = !empty($error) && !empty($_POST['save_entry']);
if ($reopen) {
    $form = [
        'id' => (int) ($_POST['id'] ?? 0), 'config_file' => (string) ($_POST['config_file'] ?? 'pjsip'),
        'target' => (string) ($_POST['target'] ?? 'object'), 'object_type' => (string) ($_POST['object_type'] ?? 'endpoint'),
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
            <a class="btn btn-secondary btn-sm" href="/advanced-config?export=1"><i class="fas fa-download"></i> <?php echo t('advanced_config.export'); ?></a>
            <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('advancedImportModal')"><i class="fas fa-upload"></i> <?php echo t('advanced_config.import'); ?></button>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('advancedConfigHelpBox')"><i class="fas fa-question-circle"></i></button>
        </div>
    </div>
    <div class="module-help-box" id="advancedConfigHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('advanced_config.title'); ?></h4>
        <p><?php echo t('advanced_config.intro'); ?></p>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('advanced_config.limit_values'); ?></li><li><?php echo t('advanced_config.limit_generated'); ?></li><li><?php echo t('advanced_config.limit_no_list'); ?></li><li><?php echo t('advanced_config.limit_refused'); ?></li><li><?php echo t('advanced_config.limit_first_value'); ?></li><li><?php echo t('advanced_config.limit_transport'); ?></li><li><?php echo t('advanced_config.limit_own_objects'); ?></li><li><?php echo t('advanced_config.limit_export'); ?></li></ul></div>
    </div>
    <p class="u-muted u-fs-12" style="margin: 0 20px 12px;"><?php echo t('advanced_config.intro'); ?></p>
    <?php if (!$entries): ?>
        <p class="u-muted" style="padding: 0 20px 16px;"><?php echo t('advanced_config.empty'); ?></p>
    <?php else: ?>
    <div class="table-responsive"><table class="data-table">
        <thead><tr><th><?php echo t('advanced_config.field_file'); ?></th><th><?php echo t('advanced_config.field_type'); ?></th><th><?php echo t('advanced_config.field_name'); ?></th><th><?php echo t('advanced_config.field_options'); ?></th><th><?php echo t('advanced_config.status'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e):
            $state = $states[(int) $e['id']];
            $isApplied = isset($appliedSet[$appliedKey($e)]);
        ?>
            <tr>
                <td><?php echo $h($e['config_file']); ?></td>
                <td><?php echo $h($e['object_type']); ?></td>
                <td><?php if ($e['target'] !== 'object'): ?><span class="badge badge-secondary"><?php echo t('advanced_config.target_' . $e['target']); ?></span> <?php endif; ?><?php echo $h($e['object_name']); ?><?php if ($e['note'] !== null && $e['note'] !== ''): ?><div class="u-muted u-fs-11"><?php echo $h($e['note']); ?></div><?php endif; ?></td>
                <td><code style="white-space: pre-wrap;"><?php echo $h($e['options']); ?></code>
                    <?php foreach ($replaced[(int) $e['id']] as $r): ?>
                        <div class="u-fs-11 <?php echo $r['wins'] ? 'u-muted' : 'u-danger'; ?>"><?php echo $h(sprintf(t($r['wins'] ? 'advanced_config.replaces' : 'advanced_config.no_effect'), $r['name'], $r['value'])); ?></div>
                    <?php endforeach; ?>
                </td>
                <td>
                    <?php if (empty($e['is_active'])): ?><span class="badge badge-secondary"><?php echo t('advanced_config.status_off'); ?></span>
                    <?php elseif ($state === 'taken'): ?><span class="badge badge-warning"><?php echo t('advanced_config.status_taken'); ?></span>
                    <?php elseif ($state === 'missing'): ?><span class="badge badge-warning"><?php echo t('advanced_config.status_missing'); ?></span>
                    <?php elseif (!$isApplied): ?><span class="badge badge-warning"><?php echo t('advanced_config.status_pending'); ?></span>
                    <?php else: ?><span class="badge badge-success"><?php echo t('advanced_config.status_applied'); ?></span><?php endif; ?>
                    <div class="u-muted u-fs-11"><?php echo t(AdvancedPjsipService::needsRestart($e) ? 'advanced_config.needs_restart' : 'advanced_config.needs_reload'); ?></div>
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
        <?php if ((int) $form['id'] === 0): ?>
        <div class="form-group">
            <label class="form-label"><?php echo t('advanced_config.recipe'); ?></label>
            <select id="advancedRecipe" class="form-control">
                <option value=""><?php echo t('advanced_config.recipe_none'); ?></option>
                <?php foreach (array_keys($recipes) as $key): ?>
                    <option value="<?php echo $h($key); ?>"><?php echo t('advanced_config.recipe_' . $key); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="u-hint"><?php echo t('advanced_config.recipe_help'); ?></small>
        </div>
        <?php endif; ?>
        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('advanced_config.field_file'); ?></label>
                <select name="config_file" id="advancedFile" class="form-control">
                    <?php foreach ($files as $file): ?>
                        <option value="<?php echo $h($file); ?>" <?php echo $form['config_file'] === $file ? 'selected' : ''; ?>><?php echo $h($file); ?>.conf</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="advancedTargetGroup">
                <label class="form-label"><?php echo t('advanced_config.field_target'); ?></label>
                <select name="target" id="advancedTarget" class="form-control">
                    <?php foreach ($targets as $target): ?>
                        <option value="<?php echo $h($target); ?>" <?php echo $form['target'] === $target ? 'selected' : ''; ?>><?php echo t('advanced_config.target_' . $target); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="u-grid-2">
            <div class="form-group" id="advancedTypeGroup">
                <label class="form-label"><?php echo t('advanced_config.field_type'); ?></label>
                <select name="object_type" id="advancedType" class="form-control">
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo $h($type); ?>" <?php echo $form['object_type'] === $type ? 'selected' : ''; ?>><?php echo $h($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="advancedNameGroup">
                <label class="form-label" id="advancedNameLabel"><?php echo t('advanced_config.field_name'); ?></label>
                <input type="text" name="object_name" id="advancedName" class="form-control" maxlength="80" list="advancedObjects" value="<?php echo $h($form['object_name']); ?>" placeholder="transport-udp">
                <datalist id="advancedObjects">
                    <?php foreach ($objects as $object): ?>
                        <option value="<?php echo $h(substr($object, strpos($object, ':') + 1)); ?>"><?php echo $h(strtok($object, ':')); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <datalist id="advancedModels">
                    <?php foreach ($models as $model): ?><option value="<?php echo $h($model); ?>"></option><?php endforeach; ?>
                </datalist>
                <?php foreach ($sections as $file => $fileSections): ?>
                <datalist id="advancedSections-<?php echo $h($file); ?>">
                    <?php foreach ($fileSections as $section): ?><option value="<?php echo $h($section); ?>"></option><?php endforeach; ?>
                </datalist>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" id="advancedOptionsLabel"><?php echo t('advanced_config.field_options'); ?></label>
            <div id="advancedHelper" style="display: none; margin-bottom: 8px;">
                <div class="u-flex-gap">
                    <input type="text" id="advancedOptionName" class="form-control" list="advancedOptionNames" placeholder="<?php echo t('advanced_config.option_search'); ?>" autocomplete="off">
                    <button type="button" id="advancedOptionAdd" class="btn btn-secondary btn-sm"><?php echo t('advanced_config.option_add'); ?></button>
                </div>
                <datalist id="advancedOptionNames"></datalist>
                <small class="u-hint" id="advancedOptionHelp"></small>
            </div>
            <textarea name="options" id="advancedOptions" data-saved="<?php echo $h((int) $form['id'] > 0 && $edit ? $edit['options'] : ''); ?>" class="form-control" rows="4" required spellcheck="false" style="font-family: monospace;" placeholder="tos=cs3&#10;symmetric_transport=yes"><?php echo $h($form['options']); ?></textarea>
            <small class="u-hint" id="advancedOptionsHint"><?php echo t('advanced_config.options_help'); ?></small>
            <small class="u-hint" id="advancedRawHint" style="display: none;"><?php echo t('advanced_config.raw_help'); ?></small>
            <pre id="advancedDiff" class="u-fs-12" style="display: none; margin-top: 8px; padding: 8px; overflow-x: auto;"></pre>
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

<div class="modal-overlay" id="advancedImportModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-upload u-primary"></i> <?php echo t('advanced_config.import'); ?></h3>
            <a class="btn btn-secondary u-btn-pad" href="/advanced-config"><i class="fas fa-times"></i></a>
        </div>
        <div class="modal-body">
            <form method="POST" action="/advanced-config" enctype="multipart/form-data" onsubmit="return confirm(<?php echo jsArg(t('advanced_config.import_confirm')); ?>);">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="import_entries" value="1">
                <div class="form-group">
                    <input type="file" name="import_file" class="form-control" accept=".json,application/json" required>
                    <small class="u-hint"><?php echo t('advanced_config.import_help'); ?></small>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> <?php echo t('advanced_config.import'); ?></button>
            </form>
        </div>
    </div>
</div>

<script>window.ADVANCED_CONFIG = <?php echo json_encode([
    'help' => $help,
    'recipes' => $recipes,
    'editing' => (int) $form['id'] > 0,
    'labels' => [
        'section' => t('advanced_config.field_section'), 'model' => t('advanced_config.target_model'), 'name' => t('advanced_config.field_name'),
        'options' => t('advanced_config.field_options'), 'raw' => t('advanced_config.raw_text'), 'changes' => t('advanced_config.changes'),
    ],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo asset('/assets/js/advanced_config.js'); ?>"></script>

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
