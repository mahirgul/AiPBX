<?php
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$kindLabel = fn(string $k): string => $k === 'odoo' ? 'Odoo' : t('connections.kind_generic');
?>

<?php if (!empty($message)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $h($message); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($error); ?></div>
<?php endif; ?>

<?php if (!empty($pack)): ?>
<div class="card u-mb-16">
    <?php echo uiPageHeader('fa-box-open', t('connections.pack_title')); ?>
    <p class="u-fs-13"><?php echo t('connections.pack_desc'); ?></p>
    <textarea id="connectionPack" class="form-control u-fs-12" rows="14" readonly><?php echo $h($pack); ?></textarea>
    <button type="button" class="btn btn-primary btn-sm u-mt-10" onclick="UIHelper.copyToClipboard(document.getElementById('connectionPack').value, <?php echo jsArg(t('connections.pack_copied')); ?>)">
        <i class="fas fa-copy"></i> <?php echo t('connections.pack_copy'); ?>
    </button>
</div>
<?php endif; ?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-link', t('connections.title'), 'connectionsHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
        <button type="button" class="btn btn-primary btn-sm" onclick="UIHelper.openOverlayModal('connectionModal')">
            <i class="fas fa-plus-circle"></i> <?php echo t('connections.new'); ?>
        </button>
<?php echo uiPageHeaderClose(); ?>

    <?php echo uiHelpBoxOpen('connectionsHelpBox', t('connections.help_title')); ?>
        <p><?php echo t('connections.help_desc'); ?></p>
        <p><?php echo t('connections.help_odoo'); ?></p>
    <?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        t('connections.col_name'),
        t('connections.col_kind'),
        ['label' => t('connections.col_webhook'), 'class' => 'col-hide-mobile'],
        t('connections.col_delivery'),
        ['label' => t('connections.col_token'), 'class' => 'col-hide-mobile'],
        ['label' => t('common.actions'), 'class' => 'text-right'],
    ], ['id' => 'connectionsTable']); ?>
        <?php if (empty($connections)): ?>
            <?php echo uiTableEmptyRow(6, t('connections.empty'), 'fa-link'); ?>
        <?php else: ?>
            <?php foreach ($connections as $c): $code = (int) ($c['last_status_code'] ?? 0); ?>
                <tr>
                    <td class="u-strong"><?php echo $h($c['name']); ?></td>
                    <td><span class="badge badge-secondary"><?php echo $h($kindLabel((string) $c['kind'])); ?></span></td>
                    <td class="col-hide-mobile u-fs-12"><code><?php echo $h($c['target_url'] ?? ''); ?></code></td>
                    <td class="u-fs-12">
                        <?php if (empty($c['last_triggered_at'])): ?>
                            <span class="u-muted"><?php echo t('connections.never'); ?></span>
                        <?php else: ?>
                            <span class="badge <?php echo $code >= 200 && $code < 300 ? 'badge-success' : 'badge-danger'; ?>"><?php echo $code ?: '—'; ?></span>
                            <?php echo $h($c['last_triggered_at']); ?>
                            <?php if (!empty($c['last_error']) && ($code < 200 || $code >= 300)): ?>
                                <div class="u-muted u-fs-11"><?php echo $h(mb_substr((string) $c['last_error'], 0, 120)); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="col-hide-mobile u-fs-12">
                        <code><?php echo $h($c['token_prefix'] ?? ''); ?></code>
                        <div class="u-muted u-fs-11"><?php echo !empty($c['last_used_at']) ? $h($c['last_used_at'] . ' · ' . ($c['last_used_ip'] ?? '')) : t('connections.never'); ?></div>
                    </td>
                    <td class="text-right u-nowrap">
                        <?php foreach (['test' => ['fa-paper-plane', 'btn-secondary', ''], 'rotate' => ['fa-rotate', 'btn-secondary', t('connections.confirm_rotate')], 'delete' => ['fa-trash-alt', 'btn-danger', t('connections.confirm_delete')]] as $act => [$icon, $cls, $confirm]): ?>
                            <form method="POST" class="u-d-inline"<?php echo $confirm !== '' ? ' onsubmit="return confirm(' . jsArg($confirm) . ');"' : ''; ?>>
                                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                <input type="hidden" name="action" value="<?php echo $act; ?>">
                                <input type="hidden" name="connection_id" value="<?php echo (int) $c['id']; ?>">
                                <button type="submit" class="btn btn-icon <?php echo $cls; ?> btn-sm" title="<?php echo $h(t('connections.act_' . $act)); ?>"><i class="fas <?php echo $icon; ?>"></i></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php echo uiTableClose(); ?>
</div>

<div class="modal-overlay" id="connectionModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-link"></i> <?php echo t('connections.new'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('connectionModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label class="form-label" for="conn_name"><?php echo t('connections.col_name'); ?></label>
                    <input type="text" name="name" id="conn_name" class="form-control" maxlength="120" required>
                </div>
                <div class="form-group u-mt-10">
                    <label class="form-label" for="conn_kind"><?php echo t('connections.col_kind'); ?></label>
                    <select name="kind" id="conn_kind" class="form-control">
                        <option value="odoo">Odoo (AiPBX Connector)</option>
                        <option value="generic"><?php echo t('connections.kind_generic'); ?></option>
                    </select>
                </div>
                <div class="form-group u-mt-10">
                    <label class="form-label" for="conn_url"><?php echo t('connections.field_url'); ?></label>
                    <input type="url" name="base_url" id="conn_url" class="form-control" placeholder="https://odoo.example.com" required>
                    <small class="u-hint u-fs-11"><?php echo t('connections.field_url_hint'); ?></small>
                </div>
                <div class="form-group u-mt-10">
                    <label class="form-label" for="conn_ips"><?php echo t('connections.field_ips'); ?></label>
                    <input type="text" name="allowed_ips" id="conn_ips" class="form-control" placeholder="203.0.113.10, 10.0.0.0/24">
                    <small class="u-hint u-fs-11"><?php echo t('connections.field_ips_hint'); ?></small>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('connectionModal')"); ?>
            </form>
        </div>
    </div>
</div>
