<?php
/**
 * PBX → Phones: phone list, waiting phones, CSV import, provisioning settings.
 *
 * @var array $phones
 * @var array $waiting
 * @var array $users
 * @var array $settings
 * @var array $discovered        phones the engine found on the network
 * @var array $discoverySummary  how many of them are new / ignored / provisioned
 * @var array $discovery          the discovery settings
 * @var int   $discoveryPoolFree  free extensions left in the pool
 * @var array $discoveryEngine    the engine's last run (state + log tail)
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
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('discoverySettingsModal')" title="<?php echo t('phonedisc.settings_title'); ?>"><i class="fas fa-wifi"></i></button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('provisionSettingsModal')" title="<?php echo t('phones.settings_title'); ?>"><i class="fas fa-sliders-h"></i></button>
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
                            <td><?php echo htmlspecialchars($m['label'] ?? $p['model']); ?>
                                <?php if (($p['firmware_state'] ?? '') !== ''): ?>
                                    <div class="text-muted u-fs-11"<?php echo $p['firmware_note'] !== '' ? ' title="' . htmlspecialchars((string) $p['firmware_note']) . '"' : ''; ?>>
                                        <?php echo t('phones.firmware_' . $p['firmware_state']); ?>
                                        <?php if ($p['firmware_load'] !== ''): ?><code><?php echo htmlspecialchars((string) $p['firmware_load']); ?></code><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
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
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="showPhoneFile(<?php echo (int) $p['id']; ?>, <?php echo htmlspecialchars(json_encode(PhoneModels::formatMac($p['mac'])), ENT_QUOTES); ?>)" title="<?php echo t('phones.preview'); ?>"><i class="fas fa-file-code"></i></button>
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
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditPhoneModal(<?php echo jsArg(['id' => (int) $p['id'], 'mac' => PhoneModels::formatMac($p['mac']), 'model' => $p['model'], 'user_id' => (int) $p['user_id'], 'notes' => $p['notes']]); ?>)' title="<?php echo t('common.edit'); ?>"><i class="fas fa-edit"></i></button>
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

<?php
// --- Phones the discovery engine found on the network -----------------------
$discoveryStateBadge = [
    'new' => ['badge-warning', 'phonedisc.state_new'],
    'ignored' => ['badge-secondary', 'phonedisc.state_ignored'],
    'provisioned' => ['badge-info', 'phonedisc.state_provisioned'],
    'deleted' => ['badge-secondary', 'phonedisc.state_deleted'],
];
$engineState = $discoveryEngine['state'];
$engineRan = isset($engineState['at']);
$pool = array_filter(explode(',', $discovery['provision_discovery_pool']));
// vendor => model: a vendor that is not here is never provisioned automatically.
$configuredModels = PhoneDiscoveryService::parseModels($discovery['provision_discovery_models']);
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-wifi u-primary"></i> <?php echo t('phonedisc.title'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($discovered); ?></span>
            <?php if ($discoverySummary['new'] > 0): ?>
                <span class="badge badge-warning u-fs-11 u-ml-8"><?php echo sprintf(t('phonedisc.badge_new'), $discoverySummary['new']); ?></span>
            <?php endif; ?>
        </div>
        <div class="u-flex-center">
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-secondary btn-sm" onclick="UIHelper.openOverlayModal('discoverySettingsModal')" title="<?php echo t('phonedisc.settings_title'); ?>"><i class="fas fa-sliders-h"></i> <?php echo t('phonedisc.settings_btn'); ?></button>
            <?php endif; ?>
        </div>
    </div>
    <p class="text-muted u-fs-12"><?php echo t('phonedisc.desc'); ?></p>
    <div class="alert alert-info u-fs-12 u-mb-10">
        <i class="fas fa-robot"></i>
        <?php if ($discovery['provision_discovery'] !== '1'): ?>
            <?php echo t('phonedisc.engine_off'); ?>
        <?php elseif ($pool === []): ?>
            <?php echo t('phonedisc.engine_record_only'); ?>
        <?php else: ?>
            <?php echo sprintf(t('phonedisc.engine_pool'), count($pool), $discoveryPoolFree); ?>
        <?php endif; ?>
        <span class="text-muted">
            <?php if ($engineRan): ?>
                — <?php echo sprintf(t('phonedisc.engine_last_run'), htmlspecialchars((string) (($engineState['at'] ?? 0) ? date('Y-m-d H:i', (int) $engineState['at']) : '')),
                    (int) ($engineState['seen'] ?? 0), (int) ($engineState['new'] ?? 0)); ?>
            <?php else: ?>
                — <strong><?php echo t('phonedisc.engine_never'); ?></strong>
            <?php endif; ?>
            <?php foreach (($discoveryEngine['log'] ?? []) as $i => $line): ?>
                <?php if ($i === 0): ?><br><?php endif; ?><code class="u-fs-11"><?php echo htmlspecialchars($line); ?></code><br>
            <?php endforeach; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('phones.col_mac'); ?></th>
                    <th><?php echo t('phones.col_vendor'); ?></th>
                    <th><?php echo t('phones.col_ip'); ?></th>
                    <th><?php echo t('phonedisc.col_source'); ?></th>
                    <th><?php echo t('phonedisc.col_seen'); ?></th>
                    <th><?php echo t('phonedisc.col_state'); ?></th>
                    <th class="text-right"><?php echo t('phones.assign'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($discovered)): ?>
                    <?php echo uiTableEmptyRow(7, t('phonedisc.empty'), 'fa-wifi'); ?>
                <?php else: ?>
                    <?php foreach ($discovered as $d): ?>
                        <?php [$badge, $label] = $discoveryStateBadge[$d['state']] ?? ['badge-secondary', 'phonedisc.state_new']; ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars(PhoneModels::formatMac((string) $d['mac'])); ?></code></td>
                            <td><?php echo htmlspecialchars(PhoneModels::VENDORS[$d['vendor']] ?? ($d['vendor'] ?: '?')); ?></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars((string) $d['ip']); ?>
                                <?php if ($d['hostname'] !== ''): ?><div class="text-muted"><?php echo htmlspecialchars((string) $d['hostname']); ?></div><?php endif; ?>
                            </td>
                            <td class="u-fs-11">
                                <?php echo htmlspecialchars(t('phonedisc.src_' . ($d['source'] ?: 'unknown'))); ?>
                            </td>
                            <td class="u-fs-11"><?php echo htmlspecialchars((string) $d['last_seen_at']); ?>
                                <div class="text-muted"><?php echo sprintf(t('phonedisc.seen_times'), (int) $d['seen_count']); ?></div>
                            </td>
                            <td>
                                <span class="badge <?php echo $badge; ?>"><?php echo t($label); ?></span>
                                <?php if (!empty($d['extension'])): ?>
                                    <div class="u-fs-11"><?php echo htmlspecialchars((string) $d['extension'] . ' — ' . (string) $d['full_name']); ?></div>
                                <?php endif; ?>
                                <?php if ($d['state'] === 'new' && !isset($configuredModels[$d['vendor']])): ?>
                                    <div class="text-muted u-fs-11"><?php echo t('phonedisc.not_automatic_hint'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php if ($canEdit && in_array($d['state'], ['new', 'deleted'], true)): ?>
                                        <form method="POST" class="phones-assign-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="discovery_id" value="<?php echo (int) $d['id']; ?>">
                                            <select name="model" class="form-control form-control-sm" required><?php echo $modelOptions(PhoneDiscoveryService::defaultModelForVendor((string) $d['vendor'])); ?></select>
                                            <select name="user_id" class="form-control form-control-sm"><?php echo $userOptions(); ?></select>
                                            <button type="submit" name="approve_discovery" value="1" class="btn btn-primary btn-sm" title="<?php echo t('phonedisc.approve'); ?>" onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('phonedisc.approve_confirm')), ENT_QUOTES); ?>);"><i class="fas fa-check"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canEdit && $d['state'] === 'new'): ?>
                                        <form method="POST" class="u-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="discovery_id" value="<?php echo (int) $d['id']; ?>">
                                            <button type="submit" name="ignore_discovery" value="1" class="btn btn-danger btn-sm" title="<?php echo t('phonedisc.ignore'); ?>"><i class="fas fa-ban"></i></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canEdit && $d['state'] === 'ignored'): ?>
                                        <form method="POST" class="u-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="discovery_id" value="<?php echo (int) $d['id']; ?>">
                                            <button type="submit" name="restore_discovery" value="1" class="btn btn-secondary btn-sm" title="<?php echo t('phonedisc.restore'); ?>"><i class="fas fa-undo"></i></button>
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

<!-- The phones' requests (HTTPS, FTP, TFTP, DHCP) are on PBX -> Phone logs, one tab each. -->
<p class="u-muted u-fs-12" style="margin: 0 0 16px;"><i class="fas fa-scroll"></i> <?php echo t('phones.log_moved'); ?>
    <a href="/phone-logs?tab=http"><?php echo t('phonelogs.title'); ?></a></p>

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
<!-- The file(s) a phone gets (showPhoneFile in phones.js) -->
<div class="modal-overlay" id="phoneFileModal">
    <div class="modal-card" style="max-width: 900px;">
        <div class="modal-header u-flex-between">
            <h3 class="u-title"><i class="fas fa-file-code u-primary"></i> <span id="phoneFileTitle"></span></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('phoneFileModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="phoneFileTabs" class="u-flex-gap u-mb-10"></div>
            <pre id="phoneFileContent" style="max-height: 60vh; overflow: auto; background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 8px; padding: 12px; font-size: 12px; line-height: 1.45; white-space: pre; margin: 0;"></pre>
            <div class="u-flex-between u-mt-12">
                <span class="text-muted u-fs-11" id="phoneFileNote"></span>
                <span class="u-flex-gap">
                    <?php if ($canEdit): ?><button type="button" class="btn btn-secondary btn-sm" id="phoneFileReveal" onclick="togglePhoneFilePasswords()"><i class="fas fa-eye"></i> <span></span></button><?php endif; ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="copyPhoneFile()"><i class="fas fa-copy"></i> <?php echo t('common.copy'); ?></button>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Modal: phone discovery (the engine's switches and the extension pool) -->
<div class="modal-overlay" id="discoverySettingsModal">
    <div class="modal-card" style="max-width: 640px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-wifi u-primary"></i> <?php echo t('phonedisc.settings_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('discoverySettingsModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="save_discovery_settings" value="1">
                <div class="form-group">
                    <label class="u-check-label"><input type="checkbox" class="u-accent" name="provision_discovery" value="1" <?php echo $discovery['provision_discovery'] === '1' ? 'checked' : ''; ?>> <?php echo t('phonedisc.enable'); ?></label>
                    <div class="text-muted u-fs-11"><?php echo t('phonedisc.enable_help'); ?></div>
                </div>
                <div class="form-group">
                    <label class="u-check-label"><input type="checkbox" class="u-accent" name="provision_discovery_sweep" value="1" <?php echo $discovery['provision_discovery_sweep'] === '1' ? 'checked' : ''; ?>> <?php echo t('phonedisc.sweep'); ?></label>
                    <div class="text-muted u-fs-11"><?php echo t('phonedisc.sweep_help'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phonedisc.networks'); ?></label>
                    <textarea name="provision_discovery_networks" class="form-control" rows="3" placeholder="192.168.10.0/24"><?php echo htmlspecialchars($discovery['provision_discovery_networks']); ?></textarea>
                    <div class="text-muted u-fs-11"><?php echo t('phonedisc.networks_help'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><i class="fas fa-forward"></i> <?php echo t('phonedisc.pool'); ?></label>
                    <textarea name="provision_discovery_pool" class="form-control" rows="2" placeholder="5000-5099"><?php echo htmlspecialchars($discovery['provision_discovery_pool']); ?></textarea>
                    <div class="text-muted u-fs-11"><?php echo sprintf(t('phonedisc.pool_help'), $discoveryPoolFree); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phonedisc.models'); ?></label>
                    <textarea name="provision_discovery_models" class="form-control" rows="3" placeholder="yealink=yealink-t46u&#10;grandstream=grandstream-grp2612"><?php echo htmlspecialchars($discovery['provision_discovery_models']); ?></textarea>
                    <div class="text-muted u-fs-11"><?php echo t('phonedisc.models_help'); ?></div>
                </div>
                <div class="alert alert-warning u-fs-12"><i class="fas fa-shield-alt"></i> <?php echo t('phonedisc.safety'); ?></div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('discoverySettingsModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<div class="modal-overlay" id="provisionSettingsModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-sliders-h u-primary"></i> <?php echo t('phones.settings_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('provisionSettingsModal')"><i class="fas fa-times"></i></button>
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
                    <label class="form-label"><?php echo t('phones.cisco_load'); ?></label>
                    <input type="text" name="provision_cisco_load" class="form-control" maxlength="40" placeholder="SIP42.9-4-2SR3-1S" value="<?php echo htmlspecialchars($settings['provision_cisco_load']); ?>">
                    <div class="text-muted u-fs-11"><?php echo t('phones.cisco_load_help'); ?></div>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('phones.cisco_loads'); ?></label>
                    <textarea name="provision_cisco_loads" class="form-control" rows="3" placeholder="cisco-cm-7962g=SIP42.9-4-2SR3-1S"><?php echo htmlspecialchars($settings['provision_cisco_loads']); ?></textarea>
                    <div class="text-muted u-fs-11"><?php echo t('phones.cisco_loads_help'); ?></div>
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
                <!-- Addresses for the phones (SIP server, proxy, files, syslog, NTP) and the allowed
                     networks are on Network -> Network settings, next to the server's own addresses. -->
                <div class="alert alert-info u-fs-12" style="margin: 8px 0 12px;"><i class="fas fa-network-wired"></i> <?php echo t('netset.moved_phones'); ?>
                    <a href="/network-settings"><?php echo t('sidebar.item_network_settings'); ?></a></div>
                <div class="form-group">
                    <label class="u-check-label"><input type="checkbox" class="u-accent" name="provision_tftp_export" value="1" <?php echo $settings['provision_tftp_export'] === '1' ? 'checked' : ''; ?>> <?php echo t('phones.tftp_export'); ?></label>
                    <div class="text-muted u-fs-11"><?php echo t('phones.tftp_export_help'); ?></div>
                </div>
                <?php echo uiModalFooter("UIHelper.closeOverlayModal('provisionSettingsModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/phones.js'); ?>"></script>
