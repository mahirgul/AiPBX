<?php
$group_slugs = RoleRepository::groupSlugs();
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-user-shield" style="color: var(--warning);"></i> <?php echo t('roles.header_title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('roleHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <button class="btn btn-primary btn-sm" onclick="openCreateRoleModal()" title="<?php echo t('roles.new_tooltip'); ?>">
                <i class="fas fa-plus-circle"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="roleHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('roles.help_title'); ?></h4>
        <?php echo t('roles.help_body'); ?><br>
        - <strong><?php echo t('roles.help_system'); ?></strong><br>
        - <strong><?php echo t('roles.help_custom'); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th class="col-hide-mobile"><?php echo t('roles.col_role_id'); ?></th>
                    <th><?php echo t('roles.col_role'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('roles.col_description'); ?></th>
                    <th><?php echo t('roles.col_users'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('roles.col_type'); ?></th>
                    <th class="text-right"><?php echo t('roles.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($roles)): ?>
                    <?php echo uiTableEmptyRow(7, t('roles.empty'), 'fa-user-shield'); ?>
                <?php else: ?>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $r['id']; ?></td>
                            <td class="col-hide-mobile"><code><?php echo htmlspecialchars($r['role_key']); ?></code></td>
                            <td>
                                <strong style="color: var(--text-main); font-size: 14px;">
                                    <?php echo htmlspecialchars($r['role_name']); ?>
                                </strong>
                            </td>
                            <td class="col-hide-mobile"><span class="cell-truncate" style="color: var(--text-muted); font-size: 13px;" title="<?php echo htmlspecialchars($r['description']); ?>"><?php echo htmlspecialchars($r['description']); ?></span></td>
                            <td>
                                <span class="badge badge-info"><i class="fas fa-users"></i> <?php echo $r['user_count']; ?> <?php echo t('roles.user_count_suffix'); ?></span>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($r['is_system']): ?>
                                    <span class="badge badge-success"><i class="fas fa-shield-alt"></i> <?php echo t('roles.system_protected'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo t('roles.custom_role'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($r, 'openEditRoleModal', 'role_id', 'delete_role', sprintf(t('roles.delete_confirm'), $r['role_name']), t('roles.delete'), 'fa-trash-alt', t('roles.edit_permissions'), 'fa-key', ['role_key' => $r['role_key']], !$r['is_system'], 'btn-primary'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Create / Edit Role Permissions Matrix -->
<div class="modal-overlay" id="roleModal" style="display: none;">
    <div class="modal-card" style="max-width: 920px; width: 95%;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="roleModalTitle">
                <i class="fas fa-user-shield" style="color: var(--warning);"></i> <?php echo t('roles.modal_title'); ?>
            </h3>
            <button class="btn btn-secondary" onclick="closeRoleModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="max-height: 80vh; overflow-y: auto;">
            <form method="POST" autocomplete="off" id="roleForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_role" value="1">
                <input type="hidden" name="role_id" id="modal_role_id" value="">

                <div id="modal_system_role_hint" style="display:none; background: var(--info-soft, rgba(59,130,246,0.08)); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12.5px; color: var(--text-muted);">
                    <i class="fas fa-circle-info" style="color: var(--primary);"></i>
                    <?php echo t('roles.system_role_hint'); ?>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('roles.field_role_key'); ?></label>
                        <input type="text" name="role_key" id="modal_role_key" class="form-control" placeholder="supervisor" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('roles.field_role_name'); ?></label>
                        <input type="text" name="role_name" id="modal_role_name" class="form-control" placeholder="<?php echo t('roles.field_role_name_placeholder'); ?>" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label"><?php echo t('roles.field_description'); ?></label>
                    <input type="text" name="description" id="modal_description" class="form-control" placeholder="<?php echo t('roles.description_placeholder'); ?>">
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-body); padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; border: 1px solid var(--border-color);">
                    <h4 style="margin:0; font-size: 14px; font-weight: 700; color: var(--text-main);">
                        <i class="fas fa-list-check" style="color: var(--primary);"></i> <?php echo t('roles.matrix_title'); ?>
                    </h4>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms(true)">
                            <i class="fas fa-check-double"></i> <?php echo t('roles.select_all'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms(false)">
                            <i class="fas fa-ban"></i> <?php echo t('roles.clear_all'); ?>
                        </button>
                    </div>
                </div>

                <!-- Module Permission Table -->
                <div class="table-responsive">
                    <table class="data-table perm-matrix-table" data-no-dt="true" style="font-size: 13px;">
                        <thead>
                            <tr>
                                <th><?php echo t('roles.col_module'); ?></th>
                                <th><?php echo t('roles.col_group'); ?></th>
                                <th style="text-align: center;"><?php echo t('roles.col_perm_view'); ?></th>
                                <th style="text-align: center;"><?php echo t('roles.col_perm_access'); ?></th>
                                <th style="text-align: center;"><?php echo t('roles.col_perm_edit'); ?></th>
                                <th style="text-align: center;"><?php echo t('roles.col_perm_delete'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modules_definition as $m_key => $m_info):
                                $group_slug = $group_slugs[$m_info['group']] ?? 'general';
                            ?>
                                <tr>
                                    <td>
                                        <strong style="color: var(--text-main);"><?php echo htmlspecialchars(t('roles.module_' . $m_key, $m_info['title'])); ?></strong>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?php echo $m_key; ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars(t('roles.group_' . $group_slug, $m_info['group'])); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="perms[<?php echo $m_key; ?>][view]" value="1" class="perm-cb perm-view" id="p_<?php echo $m_key; ?>_view">
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="perms[<?php echo $m_key; ?>][access]" value="1" class="perm-cb perm-access" id="p_<?php echo $m_key; ?>_access">
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="perms[<?php echo $m_key; ?>][edit]" value="1" class="perm-cb perm-edit" id="p_<?php echo $m_key; ?>_edit">
                                    </td>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="perms[<?php echo $m_key; ?>][delete]" value="1" class="perm-cb perm-delete" id="p_<?php echo $m_key; ?>_delete">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php echo uiModalFooter('closeRoleModal()', t('roles.save_permissions')); ?>
            </form>
        </div>
    </div>
</div>

<script>
    window.ALL_PERMISSIONS = <?php echo json_encode($all_permissions); ?>;
</script>
<script src="/assets/js/roles.js?v=<?php echo time(); ?>"></script>
