<?php
/**
 * PBX → Phones: phone list, waiting phones, CSV import, provisioning settings.
 *
 * @var array $phones
 * @var array $waiting
 * @var array $users
 * @var array $settings
 * @var array $log
 * @var string $macBaseUrl
 */
$canEdit = hasModulePermission('phones', 'edit');
$csrf = getCSRFToken();
$modelOptions = function (string $selected = '') {
    $html = '';
    foreach (PhoneModels::VENDORS as $vendor => $vendorLabel) {
        $html .= '<optgroup label="' . htmlspecialchars($vendorLabel) . '">';
        foreach (PhoneModels::MODELS as $key => $m) {
            if ($m['vendor'] === $vendor) {
                $html .= '<option value="' . htmlspecialchars($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . htmlspecialchars($m['label']) . '</option>';
            }
        }
        $html .= '</optgroup>';
    }
    return $html;
};
$userOptions = function () use ($users) {
    $html = '<option value="0">' . htmlspecialchars(t('phones.unassigned')) . '</option>';
    foreach ($users as $u) {
        $html .= '<option value="' . (int) $u['id'] . '">' . htmlspecialchars($u['extension'] . ' — ' . $u['full_name']) . '</option>';
    }
    return $html;
};
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/phones.css'); ?>">

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-phone u-primary"></i> <?php echo t('phones.title'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($phones); ?></span>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('phonesHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <a href="/phone-keys" class="btn btn-secondary btn-sm" title="<?php echo t('phones.keys_title'); ?>"><i class="fas fa-th"></i></a>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('phoneSettingsModal')" title="<?php echo t('phones.settings_title'); ?>"><i class="fas fa-sliders-h"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('phoneImportModal')" title="<?php echo t('phones.import_title'); ?>"><i class="fas fa-file-csv"></i></button>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreatePhoneModal()" title="<?php echo t('phones.add'); ?>"><i class="fas fa-plus-circle"></i></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="module-help-box" id="phonesHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('phones.help_title'); ?></h4>
        <p><?php echo t('phones.help_body'); ?></p>
        <ul>
            <li><strong><?php echo t('phones.help_url'); ?></strong> <?php echo t('phones.help_url_desc'); ?></li>
            <li><strong><?php echo t('phones.help_mac'); ?></strong> <?php echo sprintf(t('phones.help_mac_desc'), '<code>' . htmlspecialchars($macBaseUrl) . '</code>'); ?></li>
            <li><strong><?php echo t('phones.help_keys'); ?></strong> <?php echo t('phones.help_keys_desc'); ?></li>
        </ul>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('phones.col_mac'); ?></th>
                    <th><?php echo t('phones.col_model'); ?></th>
                    <th><?php echo t('phones.col_extension'); ?></th>
                    <th><?php echo t('phones.col_last_fetch'); ?></th>
                    <th><?php echo t('phones.col_url'); ?></th>
                    <th class="text-right"><?php echo t('phones.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($phones)): ?>
                    <?php echo uiTableEmptyRow(6, t('phones.empty'), 'fa-phone'); ?>
                <?php else: ?>
                    <?php foreach ($phones as $p): ?>
                        <?php $m = PhoneModels::get($p['model']); ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars(PhoneModels::formatMac($p['mac'])); ?></code>
                                <?php if ($p['notes'] !== ''): ?><div class="text-muted u-fs-11"><?php echo htmlspecialchars($p['notes']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($m['label'] ?? $p['model']); ?></td>
                            <td>
                                <?php if ($p['extension'] !== null): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($p['extension']); ?></span>
                                    <?php echo htmlspecialchars((string) $p['full_name']); ?>
                                <?php else: ?>
                                    <span class="badge badge-warning"><?php echo t('phones.unassigned'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="u-fs-11">
                                <?php if ($p['last_fetch_at']): ?>
                                    <?php echo htmlspecialchars($p['last_fetch_at']); ?><br>
                                    <span class="text-muted"><?php echo htmlspecialchars((string) $p['last_ip']); ?></span>
                                    <div class="text-muted" title="<?php echo htmlspecialchars((string) $p['last_user_agent']); ?>"><?php echo htmlspecialchars(mb_strimwidth((string) $p['last_user_agent'], 0, 40, '…')); ?></div>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo t('phones.never'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-secondary btn-sm" data-url="<?php echo htmlspecialchars($p['url']); ?>" onclick="copyProvisionUrl(this)" title="<?php echo htmlspecialchars($p['url']); ?>">
                                    <i class="fas fa-copy"></i> <?php echo t('phones.copy_url'); ?>
                                </button>
                                <div class="text-muted u-fs-11"><?php echo htmlspecialchars($p['device_file']); ?></div>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php if ($p['user_id']): ?>
                                        <a class="btn btn-secondary btn-sm" href="/phone-keys?user=<?php echo (int) $p['user_id']; ?>" title="<?php echo t('phones.keys_title'); ?>"><i class="fas fa-th"></i></a>
                                    <?php endif; ?>
                                    <?php if ($canEdit): ?>
                                        <form method="POST" class="u-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="phone_id" value="<?php echo (int) $p['id']; ?>">
                                            <button type="submit" name="resync_phone" value="1" class="btn btn-info btn-sm" title="<?php echo t('phones.resync'); ?>"><i class="fas fa-sync-alt"></i></button>
                                            <?php if ($p['can_reboot']): ?>
                                                <button type="submit" name="reboot_phone" value="1" class="btn btn-warning btn-sm" title="<?php echo t('phones.reboot'); ?>" onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('phones.reboot_confirm')), ENT_QUOTES); ?>);"><i class="fas fa-power-off"></i></button>
                                            <?php endif; ?>
                                            <button type="submit" name="regenerate_token" value="1" class="btn btn-secondary btn-sm" title="<?php echo t('phones.new_url'); ?>" onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('phones.new_url_confirm')), ENT_QUOTES); ?>);"><i class="fas fa-key"></i></button>
                                        </form>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="showPhoneAdminPassword(<?php echo (int) $p['id']; ?>)" title="<?php echo t('phones.admin_password'); ?>"><i class="fas fa-user-lock"></i></button>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditPhoneModal(<?php echo json_encode(['id' => (int) $p['id'], 'mac' => PhoneModels::formatMac($p['mac']), 'model' => $p['model'], 'user_id' => (int) $p['user_id'], 'notes' => $p['notes']], JSON_HEX_APOS | JSON_HEX_TAG); ?>)' title="<?php echo t('common.edit'); ?>"><i class="fas fa-edit"></i></button>
                                    <?php endif; ?>
                                    <?php if (hasModulePermission('phones', 'delete')): ?>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('phones.delete_confirm')), ENT_QUOTES); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="phone_id" value="<?php echo (int) $p['id']; ?>">
                                            <button type="submit" name="delete_phone" value="1" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-hourglass-half u-warning"></i> <?php echo t('phones.waiting_title'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($waiting); ?></span>
        </div>
    </div>
    <p class="text-muted u-fs-12"><?php echo t('phones.waiting_desc'); ?></p>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('phones.col_mac'); ?></th>
                    <th><?php echo t('phones.col_vendor'); ?></th>
                    <th><?php echo t('phones.col_ip'); ?></th>
                    <th><?php echo t('phones.col_last_seen'); ?></th>
                    <th class="text-right"><?php echo t('phones.assign'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($waiting)): ?>
                    <?php echo uiTableEmptyRow(5, t('phones.waiting_empty'), 'fa-check'); ?>
                <?php else: ?>
                    <?php foreach ($waiting as $w): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars(PhoneModels::formatMac($w['mac'])); ?></code></td>
                            <td><?php echo htmlspecialchars(PhoneModels::VENDORS[$w['vendor']] ?? '?'); ?>
                                <div class="text-muted u-fs-11" title="<?php echo htmlspecialchars($w['user_agent']); ?>"><?php echo htmlspecialchars(mb_strimwidth($w['user_agent'], 0, 40, '…')); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($w['ip']); ?></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars($w['last_seen_at']); ?> (<?php echo (int) $w['request_count']; ?>×)</td>
                            <td class="text-right">
                                <?php if ($canEdit): ?>
                                    <form method="POST" class="phones-assign-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="waiting_id" value="<?php echo (int) $w['id']; ?>">
                                        <select name="model" class="form-control form-control-sm" required><?php echo $modelOptions(''); ?></select>
                                        <select name="user_id" class="form-control form-control-sm"><?php echo $userOptions(); ?></select>
                                        <button type="submit" name="assign_waiting" value="1" class="btn btn-primary btn-sm" title="<?php echo t('phones.assign'); ?>"><i class="fas fa-check"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (hasModulePermission('phones', 'delete')): ?>
                                    <form method="POST" class="u-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                        <input type="hidden" name="waiting_id" value="<?php echo (int) $w['id']; ?>">
                                        <button type="submit" name="delete_waiting" value="1" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>"><i class="fas fa-times"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-list u-primary"></i> <?php echo t('phones.log_title'); ?></div>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('phones.col_time'); ?></th>
                    <th><?php echo t('phones.col_mac'); ?></th>
                    <th><?php echo t('phones.col_file'); ?></th>
                    <th><?php echo t('phones.col_result'); ?></th>
                    <th><?php echo t('phones.col_ip'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($log)): ?>
                    <?php echo uiTableEmptyRow(5, t('phones.log_empty'), 'fa-list'); ?>
                <?php else: ?>
                    <?php foreach ($log as $row): ?>
                        <tr>
                            <td class="u-fs-11"><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td><code><?php echo $row['mac'] !== '' ? htmlspecialchars(PhoneModels::formatMac($row['mac'])) : '-'; ?></code></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars($row['file']); ?></td>
                            <td><?php echo uiStatusBadge($row['result'], ['served' => 'success', 'unknown' => 'warning', 'denied' => 'danger', 'bad_token' => 'danger', 'mac_mismatch' => 'danger'], 'secondary', t('phones.result_' . $row['result'], $row['result'])); ?></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars($row['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Modal: add / edit phone -->
<div class="modal-overlay" id="phoneModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header">
            <h3 class="u-title" id="phoneModalTitle"><i class="fas fa-phone u-primary"></i> <?php echo t('phones.add'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phoneModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="save_phone" value="1">
                <input type="hidden" name="id" id="phone_id" value="0">
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.col_mac'); ?></label>
                    <input type="text" name="mac" id="phone_mac" class="form-control" placeholder="00:15:65:AA:BB:CC" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.col_model'); ?></label>
                    <select name="model" id="phone_model" class="form-control" required><?php echo $modelOptions('yealink-t46u'); ?></select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.col_extension'); ?></label>
                    <select name="user_id" id="phone_user" class="form-control"><?php echo $userOptions(); ?></select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.notes'); ?></label>
                    <input type="text" name="notes" id="phone_notes" class="form-control" maxlength="255">
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phoneModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal: CSV import -->
<div class="modal-overlay" id="phoneImportModal">
    <div class="modal-card" style="max-width: 560px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-file-csv u-primary"></i> <?php echo t('phones.import_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phoneImportModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="import_csv" value="1">
                <p class="text-muted u-fs-12"><?php echo t('phones.import_desc'); ?></p>
                <pre class="u-fs-11">mac,model,extension
00:15:65:aa:bb:cc,yealink-t46u,1001
000b82112233,Grandstream GXP2170,1002</pre>
                <div class="form-group">
                    <input type="file" name="csv_file" accept=".csv,text/csv,text/plain" class="form-control">
                </div>
                <div class="form-group">
                    <textarea name="csv_text" class="form-control" rows="6" placeholder="<?php echo t('phones.import_paste'); ?>"></textarea>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phoneImportModal')", t('phones.import_btn'), '', 'fa-upload'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal: provisioning settings -->
<div class="modal-overlay" id="phoneSettingsModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-sliders-h u-primary"></i> <?php echo t('phones.settings_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phoneSettingsModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="save_settings" value="1">
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.transport'); ?></label>
                        <select name="provision_transport" class="form-control">
                            <option value="udp" <?php echo $settings['provision_transport'] === 'udp' ? 'selected' : ''; ?>>UDP</option>
                            <option value="tls" <?php echo $settings['provision_transport'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.srtp'); ?></label>
                        <select name="provision_srtp" class="form-control">
                            <?php foreach (['0' => 'phones.srtp_off', '1' => 'phones.srtp_optional', '2' => 'phones.srtp_required'] as $v => $k): ?>
                                <option value="<?php echo $v; ?>" <?php echo $settings['provision_srtp'] === (string) $v ? 'selected' : ''; ?>><?php echo t($k); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.codecs'); ?></label>
                    <div class="phones-codecs">
                        <?php $selectedCodecs = explode(',', $settings['provision_codecs']); ?>
                        <?php foreach (PhoneProvisionService::CODECS as $codec): ?>
                            <label class="u-check-label-6"><input type="checkbox" name="provision_codecs[]" value="<?php echo $codec; ?>" class="u-accent" <?php echo in_array($codec, $selectedCodecs, true) ? 'checked' : ''; ?>> <?php echo strtoupper($codec); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.ntp'); ?></label>
                        <input type="text" name="provision_ntp" class="form-control" value="<?php echo htmlspecialchars($settings['provision_ntp']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.timezone'); ?></label>
                        <select name="provision_timezone" class="form-control">
                            <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                                <option value="<?php echo htmlspecialchars($tz); ?>" <?php echo $tz === $settings['provision_timezone'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tz); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.language'); ?></label>
                        <select name="provision_language" class="form-control">
                            <?php foreach (UI_LANGUAGES as $code => $label): ?>
                                <option value="<?php echo $code; ?>" <?php echo $code === $settings['provision_language'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('phones.rate_limit'); ?></label>
                        <input type="number" name="provision_rate_limit" class="form-control" min="5" max="1000" value="<?php echo (int) $settings['provision_rate_limit']; ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.allowed_networks'); ?></label>
                    <textarea name="provision_allowed_networks" class="form-control" rows="3" placeholder="192.168.1.0/24&#10;10.0.0.0/8"><?php echo htmlspecialchars($settings['provision_allowed_networks']); ?></textarea>
                    <div class="text-muted u-fs-11"><?php echo t('phones.allowed_networks_help'); ?></div>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('phoneSettingsModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/phones.js'); ?>"></script>
