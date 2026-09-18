<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-users-cog" style="color: var(--primary);"></i> <?php echo t('system_users.title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('userHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openRolesModal()" title="<?php echo t('system_users.roles_tooltip'); ?>">
                <i class="fas fa-user-tag"></i> <?php echo t('system_users.roles_tooltip'); ?>
            </button>
            <button class="btn btn-primary btn-sm" onclick="openCreateUserModal()" title="<?php echo t('system_users.new_tooltip'); ?>">
                <i class="fas fa-user-plus"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="userHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('system_users.help_title'); ?></h4>
        <?php echo t('system_users.help_body'); ?><br>
        - <strong><?php echo t('system_users.help_admin'); ?></strong><br>
        - <strong><?php echo t('system_users.help_cc_agent'); ?></strong><br>
        - <strong><?php echo t('system_users.help_fax_user'); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th class="col-hide-mobile"><?php echo t('system_users.col_user'); ?></th>
                    <th><?php echo t('system_users.col_fullname'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('system_users.col_email'); ?></th>
                    <th><?php echo t('system_users.col_role'); ?></th>
                    <th><?php echo t('system_users.col_extension'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('system_users.col_permissions'); ?></th>
                    <th>2FA</th>
                    <th><?php echo t('system_users.col_status'); ?></th>
                    <th class="text-right"><?php echo t('system_users.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <?php echo uiTableEmptyRow(10, t('system_users.empty'), 'fa-users-cog'); ?>
                <?php else: ?>
                    <?php foreach ($users as $u):
                        $role_label = htmlspecialchars($u['role_name'] ?? $u['role']);
                        $role_class = 'role-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $u['role']);
                    ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $u['id']; ?></td>
                            <td class="col-hide-mobile" style="font-weight: 700; color: var(--text-main);">
                                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($u['username']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td class="col-hide-mobile"><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                            <td>
                                <span class="role-badge <?php echo $role_class; ?>">
                                    <?php echo $role_label; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($u['extension'])): ?>
                                    <span class="badge badge-info"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($u['extension']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($u['can_listen_recordings']): ?>
                                    <span class="badge badge-success" style="margin-right: 4px;"><i class="fas fa-headphones"></i> <?php echo t('system_users.perm_recordings'); ?></span>
                                <?php endif; ?>
                                <?php if ($u['can_view_all_cdrs']): ?>
                                    <span class="badge badge-warning"><i class="fas fa-list-alt"></i> <?php echo t('system_users.perm_all_cdr'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['two_factor_enabled'])): ?>
                                    <span class="badge" style="background: rgba(34, 197, 94, 0.15); color: var(--success); font-weight: 700; font-size: 11px;" title="<?php echo t('system_users.2fa_active_tooltip', '2FA Aktif'); ?>">
                                        <i class="fas fa-lock"></i> Aktif
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted); font-size: 11px;" title="<?php echo t('system_users.2fa_inactive_tooltip', '2FA Kapalı'); ?>">
                                        <i class="fas fa-unlock-alt"></i> Kapalı
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($u['id'], $u['is_active'], 'user_id'); ?>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php if (!empty($u['two_factor_enabled'])): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('<?php echo sprintf(t('system_users.reset_2fa_confirm', '%s kullanıcısının 2FA doğrulaması sıfırlanacaktır. Emin misiniz?'), htmlspecialchars($u['username'], ENT_QUOTES)); ?>');">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="reset_2fa" value="1">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" title="<?php echo t('system_users.reset_2fa_tooltip', '2FA Sıfırla'); ?>" style="color: var(--warning);">
                                                <i class="fas fa-shield-alt"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary btn-sm" onclick='openResetUserModal(<?php echo $u['id']; ?>, "<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>")' title="<?php echo t('system_users.reset_password_tooltip'); ?>">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <?php echo uiEditButton($u, 'openEditUserModal'); ?>
                                    <?php if ($u['username'] !== 'admin'): ?>
                                        <?php echo uiDeleteForm($u['id'], 'user_id', 'delete_user', sprintf(t('system_users.delete_confirm'), $u['username'])); ?>
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

<!-- Create / Edit User Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="userModalTitle"><i class="fas fa-user-plus" style="color: var(--primary);"></i> <?php echo t('system_users.modal_new_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeUserModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_system_user" value="1">
                <input type="hidden" name="user_id" id="modal_user_id" value="0">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_username'); ?></label>
                        <input type="text" name="username" id="modal_username" class="form-control" required placeholder="<?php echo t('system_users.field_username_placeholder'); ?>">
                    </div>
                    <div class="form-group" id="modal_password_group">
                        <label class="form-label"><?php echo t('system_users.field_password'); ?></label>
                        <input type="password" name="password" id="modal_password" class="form-control" placeholder="******" autocomplete="new-password">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_fullname'); ?></label>
                        <input type="text" name="full_name" id="modal_full_name" class="form-control" required placeholder="<?php echo t('system_users.field_fullname_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_email'); ?></label>
                        <input type="email" name="email" id="modal_email" class="form-control" placeholder="ahmet@example.com">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_role'); ?></label>
                    <select name="role" id="modal_role" class="form-control">
                        <?php foreach ($all_roles as $r_item): ?>
                            <option value="<?php echo htmlspecialchars($r_item['role_key']); ?>"><?php echo htmlspecialchars($r_item['role_name']); ?> (<?php echo htmlspecialchars($r_item['role_key']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_extension'); ?></label>
                        <input type="text" name="extension" id="modal_extension" class="form-control" placeholder="ör: 3001">
                        <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('system_users.extension_help'); ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display: flex; justify-content: space-between; align-items: center;">
                            <span><?php echo t('system_users.field_sip_password'); ?></span>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="if(typeof generateSipPassword === 'function'){ document.getElementById('modal_sip_password').value = generateSipPassword(); }" title="<?php echo t('system_users.gen_password_tooltip'); ?>" style="padding: 2px 8px; font-size: 10px;">
                                <i class="fas fa-magic"></i> <?php echo t('system_users.auto_password'); ?>
                            </button>
                        </label>
                        <input type="text" name="sip_password" id="modal_sip_password" class="form-control" placeholder="<?php echo t('system_users.sip_password_placeholder'); ?>">
                        <small style="color: var(--text-muted); font-size: 11px;"><?php echo t('system_users.sip_password_help'); ?></small>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_cid_internal'); ?></label>
                        <input type="text" name="cid_internal" id="modal_cid_internal" class="form-control" placeholder="<?php echo t('system_users.cid_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_cid_external'); ?></label>
                        <input type="text" name="cid_external" id="modal_cid_external" class="form-control" placeholder="<?php echo t('system_users.cid_placeholder'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label form-label-help">
                        <span><?php echo t('system_users.field_pickup_group'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('system_users.pickup_group_help'); ?></span></span>
                    </label>
                    <input type="text" name="pickup_group" id="modal_pickup_group" class="form-control" placeholder="<?php echo t('system_users.pickup_group_placeholder'); ?>">
                </div>

                <div class="form-group" style="background: rgba(255, 255, 255, 0.03); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); margin-top: 10px;">
                    <label class="form-label form-label-help" style="font-weight: 700;">
                        <span><?php echo t('system_users.field_phone_mode'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('system_users.phone_mode_help'); ?></span></span>
                    </label>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 6px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12.5px; margin: 0; user-select: none;">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_web" value="web">
                            <span><i class="fas fa-laptop" style="color: var(--primary);"></i> Web</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12.5px; margin: 0; user-select: none;">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_mobil" value="mobil">
                            <span><i class="fas fa-mobile-alt" style="color: var(--success);"></i> Mobil</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12.5px; margin: 0; user-select: none;">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_sip" value="sip">
                            <span><i class="fas fa-phone-alt" style="color: var(--secondary);"></i> SIP</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12.5px; margin: 0; user-select: none;">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_video" value="video">
                            <span><i class="fas fa-video" style="color: #8b5cf6;"></i> Görüntü</span>
                        </label>
                    </div>
                </div>

                <div class="form-group" style="background: rgba(255, 255, 255, 0.03); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); margin-top: 10px;">
                    <label class="form-label" style="font-weight: 700;"><?php echo t('system_users.extra_perms'); ?></label>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; align-items: center; margin-top: 6px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                            <input type="checkbox" name="can_listen_recordings" id="modal_listen_recordings" value="1"> <?php echo t('system_users.perm_listen_label'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                            <input type="checkbox" name="can_view_all_cdrs" id="modal_view_all_cdrs" value="1"> <?php echo t('system_users.perm_view_cdr_label'); ?>
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; color: var(--primary); font-weight: 600;">
                            <input type="checkbox" name="can_view_queue_monitor" id="modal_view_queue_monitor" value="1"> <?php echo t('system_users.perm_queue_monitor_label'); ?>
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('system_users.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeUserModal()'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal-card" style="max-width: 440px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-key" style="color: var(--primary);"></i> <?php echo t('system_users.reset_modal_title'); ?> <span id="reset_username_label"></span></h3>
            <button class="btn btn-secondary" onclick="closeResetUserModal()" style="padding: 6px 12px;" title="<?php echo t('system_users.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="user_id" id="reset_user_id">

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_new_password'); ?></label>
                    <input type="password" name="new_password" id="reset_new_password" autocomplete="new-password" class="form-control" placeholder="<?php echo t('system_users.new_password_placeholder'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_new_sip_password'); ?></label>
                    <input type="text" name="new_sip_password" id="reset_new_sip_password" class="form-control" placeholder="<?php echo t('system_users.new_sip_password_placeholder'); ?>">
                </div>

                <?php echo uiModalFooter('closeResetUserModal()', t('system_users.update_passwords'), '', 'fa-check'); ?>
            </form>
        </div>
    </div>
</div>

<!-- System Roles Management Modal -->
<div class="modal-overlay" id="rolesModal">
    <div class="modal-card" style="max-width: 680px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;"><i class="fas fa-user-tag" style="color: var(--primary);"></i> <?php echo t('system_users.roles_modal_title'); ?></h3>
            <button class="btn btn-secondary" onclick="closeRolesModal()" style="padding: 6px 12px;" title="<?php echo t('system_users.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 16px;">
            <!-- Roles Table -->
            <div style="max-height: 220px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px;">
                <table class="data-table" data-no-dt="true" style="font-size: 12px;">
                    <thead>
                        <tr>
                            <th><?php echo t('system_users.col_role_key'); ?></th>
                            <th><?php echo t('system_users.col_role_name'); ?></th>
                            <th><?php echo t('system_users.col_description'); ?></th>
                            <th class="text-right"><?php echo t('system_users.col_action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sys_roles_full as $r_item): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($r_item['role_key']); ?></code></td>
                                <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($r_item['role_name']); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($r_item['description'] ?: '-'); ?></td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-secondary btn-sm" onclick='editSystemRole(<?php echo json_encode($r_item, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="<?php echo t('system_users.edit_tooltip'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Role Edit / Create Form -->
            <form method="POST" autocomplete="off" style="background: var(--bg-sidebar); padding: 14px; border-radius: 8px; border: 1px solid var(--border-color);">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_system_role" value="1">
                <input type="hidden" name="role_id" id="role_modal_id" value="0">

                <h4 style="font-size: 13px; font-weight: 700; margin-bottom: 10px; color: var(--text-main); display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fas fa-edit"></i> <?php echo t('system_users.role_form_title'); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="resetRoleForm()" style="font-size: 11px;"><?php echo t('system_users.clear'); ?></button>
                </h4>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label"><?php echo t('system_users.field_role_key'); ?></label>
                        <input type="text" name="role_key" id="role_modal_key" class="form-control" placeholder="<?php echo t('system_users.field_role_key_placeholder'); ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label"><?php echo t('system_users.field_role_name'); ?></label>
                        <input type="text" name="role_name" id="role_modal_name" class="form-control" placeholder="<?php echo t('system_users.field_role_name_placeholder'); ?>" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label"><?php echo t('system_users.field_role_desc'); ?></label>
                    <input type="text" name="description" id="role_modal_desc" class="form-control" placeholder="<?php echo t('system_users.role_desc_placeholder'); ?>">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('system_users.save_role'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/system_users.js?v=<?php echo time(); ?>"></script>
