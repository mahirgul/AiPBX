<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-phone-alt" style="color: var(--primary);"></i> <?php echo t('extensions.title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('extHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('extensions', 'edit')): ?>
                <form method="POST" autocomplete="off" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                    <input type="hidden" name="sync_all_extensions" value="1">
                    <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo t('extensions.sync_all_tooltip'); ?>">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </form>
                <button class="btn btn-primary btn-sm" onclick="openCreateExtensionModal()" title="<?php echo t('extensions.new_tooltip'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="extHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('extensions.help_title'); ?></h4>
        <?php echo t('extensions.help_body'); ?><br>
        - <strong><?php echo t('extensions.help_wss'); ?></strong><br>
        - <strong><?php echo t('extensions.help_autoconfig'); ?></strong><br>
        - <strong><?php echo t('extensions.help_remove'); ?></strong>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success" style="margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('extensions.col_extension'); ?></th>
                    <th><?php echo t('extensions.col_fullname'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('extensions.col_username'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('extensions.col_type'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('extensions.col_sip_password'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('extensions.col_live_status'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('extensions.col_perm_role', 'Yetki / Grup'); ?></th>
                    <th><?php echo t('extensions.col_status'); ?></th>
                    <th class="text-right"><?php echo t('extensions.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($extensions)): ?>
                    <?php echo uiTableEmptyRow(10, t('extensions.empty'), 'fa-phone-alt'); ?>
                <?php else: ?>
                    <?php foreach ($extensions as $e): ?>
                        <?php
                        // Dual-Endpoint: her dahilinin -sip ve -webrtc olmak üzere iki ayrı PJSIP
                        // durumu var; ikisinden en "canlı" olanı (Görüşmede > Boşta > Erişilemiyor)
                        // gösterilir (2026-08-19 düzeltmesi — bkz. AsteriskHelper::getPJSIPStatuses()).
                        $status_priority = ['busy' => 3, 'idle' => 2, 'unknown' => 1, 'down' => 0];
                        $best_status = null;
                        foreach (['-sip', '-webrtc', '-mob-webrtc'] as $suffix) {
                            $cls = AsteriskHelper::classifyStatus($pjsip_statuses[$e['extension'] . $suffix] ?? null);
                            if ($cls !== null && ($best_status === null || $status_priority[$cls] > $status_priority[$best_status])) {
                                $best_status = $cls;
                            }
                        }
                        switch ($best_status) {
                            case 'busy': $status_badge = 'badge-info'; $status_text = t('extensions.status_busy'); break;
                            case 'idle': $status_badge = 'badge-success'; $status_text = t('extensions.status_idle'); break;
                            case 'down': $status_badge = 'badge-warning'; $status_text = t('extensions.status_down'); break;
                            default: $status_badge = 'badge-secondary'; $status_text = t('extensions.status_unknown');
                        }
                        ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $e['id']; ?></td>
                            <td><span class="badge badge-info"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($e['extension']); ?></span></td>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($e['full_name']); ?></td>
                            <td class="col-hide-mobile"><?php echo htmlspecialchars($e['username']); ?></td>
                            <td class="col-hide-mobile">
                                <?php if ($e['extension_type'] === 'fax'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-fax"></i> <?php echo t('extensions.type_fax'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-info"><i class="fas fa-phone-alt"></i> <?php echo t('extensions.type_sip'); ?></span>
                                    <?php if (isset($e['sip_auth_digest']) && (int)$e['sip_auth_digest'] === 0): ?>
                                        <span class="badge badge-warning" title="<?php echo t('extensions.auth_digest_off_tooltip'); ?>" style="margin-left: 4px; font-size: 10px;"><i class="fas fa-unlock"></i> <?php echo t('extensions.auth_digest_badge_off'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" title="<?php echo t('extensions.auth_digest_on_tooltip'); ?>" style="margin-left: 4px; font-size: 10px;"><i class="fas fa-lock"></i> <?php echo t('extensions.auth_digest_badge_on'); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($e['extension_type'] === 'fax'): ?>
                                    <span class="text-muted" style="font-size: 12px;"><?php echo t('extensions.no_sip_registration'); ?></span>
                                <?php elseif (isset($e['sip_auth_digest']) && (int)$e['sip_auth_digest'] === 0 && empty($e['sip_password'])): ?>
                                    <span class="text-muted" style="font-size: 12px; font-style: italic;"><i class="fas fa-unlock"></i> <?php echo t('extensions.no_auth_required'); ?></span>
                                <?php else: ?>
                                    <div style="display: inline-flex; align-items: center; gap: 6px;">
                                        <code data-pw="<?php echo htmlspecialchars($e['sip_password']); ?>" style="letter-spacing: 1px;">••••••</code>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleRowPassword(this)" title="<?php echo t('extensions.show_hide_password_tooltip'); ?>" style="padding: 2px 6px; font-size: 10px;">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($e['extension_type'] === 'fax'): ?>
                                    <span class="badge badge-secondary" style="opacity: 0.6;">-</span>
                                <?php else: ?>
                                    <span class="badge <?php echo $status_badge; ?>"><?php echo htmlspecialchars($status_text); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <div style="display: flex; flex-direction: column; gap: 3px;">
                                    <span class="badge badge-secondary" style="font-size: 10px;" title="Arama Yetki Grubu">
                                        <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($e['permission_group_name'] ?? 'Her Yöne Açık'); ?>
                                    </span>
                                    <?php if (!empty($e['boss_secretary_role']) && $e['boss_secretary_role'] !== 'none'): ?>
                                        <?php if ($e['boss_secretary_role'] === 'boss'): ?>
                                            <span class="badge badge-warning" style="font-size: 10px;" title="Şef / Müdür"><i class="fas fa-crown"></i> Şef</span>
                                        <?php else: ?>
                                            <span class="badge badge-info" style="font-size: 10px;" title="Sekreter"><i class="fas fa-user-tie"></i> Sekreter</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ((int)($e['voicemail_enabled'] ?? 1) === 1): ?>
                                        <span class="badge badge-primary" style="font-size: 9px; opacity: 0.8;" title="Sesli Posta Kutusu"><i class="fas fa-voicemail"></i> VM</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($e['id'], $e['is_active'], 'user_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($e, 'openEditExtensionModal', 'user_id', 'remove_extension', sprintf(t('extensions.remove_confirm'), $e['full_name'], $e['extension']), t('extensions.remove_action_title'), 'fa-phone-slash'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit Extension Modal -->
<div class="modal-overlay" id="extensionModal">
    <div class="modal-card" style="max-width: 560px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="extensionModalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('extensions.modal_new_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeExtensionModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_extension" value="1">
                <input type="hidden" name="user_id" id="modal_user_id" value="">

                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('extensions.field_fullname'); ?></label>
                        <input type="text" name="full_name" id="modal_full_name" class="form-control" placeholder="<?php echo t('extensions.field_fullname_placeholder'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('extensions.field_extension'); ?></label>
                        <input type="text" name="extension" id="modal_extension" class="form-control" placeholder="<?php echo t('extensions.field_extension_placeholder'); ?>" pattern="[0-9]{3,6}" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('extensions.field_type'); ?></label>
                        <select name="extension_type" id="modal_extension_type" class="form-control" onchange="toggleExtensionTypeFields()">
                            <option value="sip"><?php echo t('extensions.type_option_sip'); ?></option>
                            <option value="fax"><?php echo t('extensions.type_option_fax'); ?></option>
                        </select>
                        <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('extensions.type_help'); ?></small>
                    </div>

                    <div class="form-group" id="sip_auth_digest_group">
                        <label class="form-label"><?php echo t('extensions.field_auth_digest'); ?></label>
                        <select name="sip_auth_digest" id="modal_sip_auth_digest" class="form-control" onchange="toggleAuthDigestFields()">
                            <option value="1"><?php echo t('extensions.auth_digest_enabled'); ?></option>
                            <option value="0"><?php echo t('extensions.auth_digest_disabled'); ?></option>
                        </select>
                        <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('extensions.auth_digest_help'); ?></small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('extensions.field_outbound_group'); ?></label>
                    <input type="number" name="outbound_group" id="modal_outbound_group" class="form-control"
                           value="1" min="1" max="99" required>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">
                        <?php echo t('extensions.outbound_group_help'); ?>
                    </small>
                </div>

                <div id="sip_password_group" class="form-group">
                    <label class="form-label" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span><?php echo t('extensions.field_sip_password'); ?></span>
                        <span style="display: inline-flex; gap: 6px;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('modal_sip_password').value = generateSipPassword(); document.getElementById('modal_sip_password').type = 'text';" title="<?php echo t('extensions.gen_password_tooltip'); ?>" style="padding: 4px 10px; font-size: 11px;">
                                <i class="fas fa-magic"></i>
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="toggleSipPasswordField()" title="<?php echo t('extensions.toggle_password_tooltip'); ?>" style="padding: 4px 10px; font-size: 11px;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </span>
                    </label>
                    <input type="password" name="sip_password" id="modal_sip_password" autocomplete="new-password" class="form-control" minlength="6" placeholder="<?php echo t('extensions.sip_password_placeholder'); ?>" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('extensions.field_cid_internal'); ?></label>
                        <input type="text" name="cid_internal" id="modal_cid_internal" class="form-control" placeholder="<?php echo t('extensions.cid_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('extensions.field_cid_external'); ?></label>
                        <input type="text" name="cid_external" id="modal_cid_external" class="form-control" placeholder="<?php echo t('extensions.cid_placeholder'); ?>">
                    </div>
                </div>
                <small style="color: var(--text-muted); font-size: 11px; margin-top: -8px; margin-bottom: 10px; display: block;"><?php echo t('extensions.cid_help'); ?></small>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-shield-alt text-info"></i> <?php echo t('extensions.field_permission_group', 'Arama Yetki Grubu'); ?></label>
                        <select name="permission_group_id" id="modal_permission_group_id" class="form-control">
                            <?php foreach ($permission_groups ?? [] as $pg): ?>
                                <option value="<?php echo $pg['id']; ?>"><?php echo htmlspecialchars($pg['group_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user-tie text-warning"></i> <?php echo t('extensions.field_boss_secretary_group', 'Şef - Sekreter Grubu'); ?></label>
                        <select name="boss_secretary_group_id" id="modal_boss_secretary_group_id" class="form-control">
                            <option value="">-- Grup Yok --</option>
                            <?php foreach ($boss_secretary_groups ?? [] as $bsg): ?>
                                <option value="<?php echo $bsg['id']; ?>"><?php echo htmlspecialchars($bsg['group_name']); ?> (Grup <?php echo $bsg['group_number']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="card" style="background: var(--bg-input); padding: 14px; border-radius: 10px; margin-top: 14px; margin-bottom: 14px; border: 1px solid var(--border-color);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                        <span style="font-weight: 700; font-size: 13px; color: var(--text-main);"><i class="fas fa-voicemail" style="color: var(--primary);"></i> Sesli Posta (Voicemail)</span>
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; margin: 0;">
                            <input type="checkbox" name="voicemail_enabled" id="modal_voicemail_enabled" value="1" checked style="accent-color: var(--primary);">
                            <span>Sesli Posta Kutusu Etkin</span>
                        </label>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label class="form-label" style="font-size: 11px;">Sesli Posta PIN (Şifre)</label>
                            <input type="text" name="voicemail_pin" id="modal_voicemail_pin" class="form-control" placeholder="Boş ise dahili no" style="font-size: 12px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 8px;">
                            <label class="form-label" style="font-size: 11px;">Sesli Posta E-posta</label>
                            <input type="email" name="voicemail_email" id="modal_voicemail_email" class="form-control" placeholder="ornek@alanadi.com" style="font-size: 12px;">
                        </div>
                    </div>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; font-size: 11.5px;">
                        <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="checkbox" name="vm_on_noanswer" id="modal_vm_on_noanswer" value="1" style="accent-color: var(--primary);">
                            <span>Cevapsızda</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="checkbox" name="vm_on_busy" id="modal_vm_on_busy" value="1" style="accent-color: var(--primary);">
                            <span>Meşgulde</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="checkbox" name="vm_on_unavail" id="modal_vm_on_unavail" value="1" style="accent-color: var(--primary);">
                            <span>Ulaşılamadığında</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                            <input type="checkbox" name="vm_always" id="modal_vm_always" value="1" style="accent-color: var(--primary);">
                            <span>Her Zaman Sesli Posta</span>
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('extensions.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeExtensionModal()', t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/extensions.js?v=<?php echo time(); ?>"></script>
