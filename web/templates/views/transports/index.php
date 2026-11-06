<?php
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<?php if ($restartNeeded): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo t('transports.restart_needed'); ?> <a href="/"><?php echo t('transports.restart_link'); ?></a></div>
<?php endif; ?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-route', t('transports.title'), 'transportsHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
<button type="button" class="btn btn-primary btn-sm" onclick="transportEditCustom(0)"><i class="fas fa-plus"></i> <?php echo t('transports.add'); ?></button>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('transportsHelpBox', t('common.module_guide')); ?><?php echo t('transports.intro'); ?><?php echo uiHelpBoxClose(); ?>
    
    <?php echo uiTableOpen([
        t('transports.col_name'),
        t('transports.col_protocol'),
        t('transports.col_address'),
        t('transports.col_port'),
        t('transports.col_state'),
        '',
    ]); ?>
        <?php foreach ($builtin as $b): ?>
            <tr>
                <td><?php echo $h($b['name']); ?></td>
                <td><?php echo $h(strtoupper($b['protocol'])); ?></td>
                <td><?php echo $h($b['bind_addr']); ?></td>
                <td><?php echo (int) $b['port']; ?></td>
                <td><span class="badge <?php echo $b['enabled'] ? 'badge-success' : 'badge-secondary'; ?>"><?php echo t($b['enabled'] ? 'transports.state_on' : 'transports.state_off'); ?></span></td>
                <td class="text-right">
                    <?php if ($b['editable']): ?>
                        <button type="button" class="btn btn-icon btn-xs btn-secondary" onclick="transportEditBuiltin(<?php echo $h(json_encode($b)); ?>)"><i class="fas fa-edit"></i></button>
                    <?php else: ?>
                        <span class="u-muted u-fs-11" title="<?php echo t('transports.web_note'); ?>"><i class="fas fa-lock"></i></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($custom as $c): ?>
            <tr>
                <td><?php echo $h($c['name']); ?><?php if ($c['external_address'] !== ''): ?><div class="u-muted u-fs-11"><?php echo $h($c['external_address']); ?></div><?php endif; ?></td>
                <td><?php echo $h(strtoupper((string) $c['protocol'])); ?></td>
                <td><?php echo $h($c['bind_addr']); ?></td>
                <td><?php echo (int) $c['port']; ?></td>
                <td><span class="badge <?php echo !empty($c['is_active']) ? 'badge-success' : 'badge-secondary'; ?>"><?php echo t(!empty($c['is_active']) ? 'transports.state_on' : 'transports.state_off'); ?></span></td>
                <td class="text-right u-nowrap">
                    <button type="button" class="btn btn-icon btn-xs btn-secondary" onclick="transportEditCustom(<?php echo (int) $c['id']; ?>)"><i class="fas fa-edit"></i></button>
                    <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo $h(json_encode(t('transports.delete_confirm'))); ?>);">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                        <input type="hidden" name="delete_custom" value="1">
                        <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                        <button type="submit" class="btn btn-icon btn-xs btn-danger"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php echo uiTableClose(); ?>
    <ul class="u-muted u-fs-12 u-m-12-20-16-40">
        <li><?php echo t('transports.note_restart'); ?></li>
        <li><?php echo t('transports.note_firewall'); ?></li>
        <li><?php echo t('transports.note_phones'); ?></li>
        <li><?php echo t('transports.web_note'); ?></li>
    </ul>
</div>

<div class="modal-overlay" id="transportBuiltinModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-route u-primary"></i> <span id="builtinTitle"></span></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('transportBuiltinModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="save_builtin" value="1">
                <input type="hidden" name="key" id="builtin_key">
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('transports.col_address'); ?></label>
                        <input type="text" name="bind_addr" id="builtin_addr" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('transports.col_port'); ?></label>
                        <input type="number" name="port" id="builtin_port" class="form-control" min="1" max="65535" required>
                    </div>
                </div>
                <label class="u-check-label u-mb-12" id="builtin_enabled_label">
                    <input type="checkbox" name="enabled" id="builtin_enabled" value="1" class="u-accent"> <?php echo t('transports.state_on'); ?>
                </label>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('transportBuiltinModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="transportCustomModal">
    <div class="modal-card u-maxw-560">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-route u-primary"></i> <?php echo t('transports.add'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('transportCustomModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
                <input type="hidden" name="save_custom" value="1">
                <input type="hidden" name="id" id="custom_id" value="0">
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('transports.col_name'); ?></label>
                        <input type="text" name="name" id="custom_name" class="form-control" maxlength="32" required placeholder="transport-outside">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('transports.col_protocol'); ?></label>
                        <select name="protocol" id="custom_protocol" class="form-control">
                            <?php foreach ($protocols as $p): ?><option value="<?php echo $h($p); ?>"><?php echo $h(strtoupper($p)); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('transports.col_address'); ?></label>
                        <input type="text" name="bind_addr" id="custom_addr" class="form-control" value="0.0.0.0" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('transports.col_port'); ?></label>
                        <input type="number" name="port" id="custom_port" class="form-control" min="1" max="65535" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('transports.field_external'); ?></label>
                    <input type="text" name="external_address" id="custom_external" class="form-control" maxlength="255">
                    <small class="u-hint"><?php echo t('transports.field_external_help'); ?></small>
                </div>
                <label class="u-check-label u-mb-12">
                    <input type="checkbox" name="is_active" id="custom_active" value="1" class="u-accent" checked> <?php echo t('transports.state_on'); ?>
                </label>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('transportCustomModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<script>window.TRANSPORTS_CUSTOM = <?php echo json_encode($custom, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo asset('/assets/js/transports.js'); ?>"></script>
