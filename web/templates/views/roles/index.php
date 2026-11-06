<?php
$group_slugs = RoleRepository::groupSlugs();
?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-user-shield', t('roles.header_title'), 'roleHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

            <button class="btn btn-primary btn-sm" onclick="openCreateRoleModal()" title="<?php echo t('roles.new_tooltip'); ?>">
                <i class="fas fa-plus-circle"></i> <?php echo t('roles.new_tooltip'); ?>
            </button>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('roleHelpBox', t('roles.help_title')); ?>
        <?php echo t('roles.help_body'); ?><br>
        - <strong><?php echo t('roles.help_system'); ?></strong><br>
        - <strong><?php echo t('roles.help_custom'); ?></strong>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        ['label' => '#', 'class' => 'col-hide-mobile u-w-50'],
        ['label' => t('roles.col_role_id'), 'class' => 'col-hide-mobile'],
        t('roles.col_role'),
        ['label' => t('roles.col_description'), 'class' => 'col-hide-mobile'],
        t('roles.col_users'),
        ['label' => t('roles.col_type'), 'class' => 'col-hide-mobile'],
        ['label' => t('roles.col_actions'), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($roles)): ?>
                    <?php echo uiTableEmptyRow(7, t('roles.empty'), 'fa-user-shield'); ?>
                <?php else: ?>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $r['id']; ?></td>
                            <td class="col-hide-mobile"><code><?php echo htmlspecialchars($r['role_key']); ?></code></td>
                            <td>
                                <strong class="u-text-main u-fs-14">
                                    <?php echo htmlspecialchars($r['role_name']); ?>
                                </strong>
                            </td>
                            <td class="col-hide-mobile"><span class="cell-truncate u-muted u-fs-13" title="<?php echo htmlspecialchars($r['description']); ?>"><?php echo htmlspecialchars($r['description']); ?></span></td>
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
            <?php echo uiTableClose(); ?>
</div>

<!-- Modal: Create / Edit Role Permissions Matrix -->
<div class="modal-overlay" id="roleModal" style="display: none;">
    <div class="modal-card u-maxw-920 u-w-95pc">
        <div class="modal-header">
            <h3 class="u-title" id="roleModalTitle">
                <i class="fas fa-user-shield u-warning"></i> <?php echo t('roles.modal_title'); ?>
            </h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeRoleModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body u-maxh-80vh u-overflow-y-auto">
            <form method="POST" autocomplete="off" id="roleForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_role" value="1">
                <input type="hidden" name="role_id" id="modal_role_id" value="">

                <div id="modal_system_role_hint" style="display:none; background: var(--info-soft, rgba(59,130,246,0.08)); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 12.5px; color: var(--text-muted);">
                    <i class="fas fa-circle-info u-primary"></i>
                    <?php echo t('roles.system_role_hint'); ?>
                </div>

                <div class="u-grid-2 u-mb-20">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('roles.field_role_key'); ?></label>
                        <input type="text" name="role_key" id="modal_role_key" class="form-control" placeholder="supervisor" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('roles.field_role_name'); ?></label>
                        <input type="text" name="role_name" id="modal_role_name" class="form-control" placeholder="<?php echo t('roles.field_role_name_placeholder'); ?>" required>
                    </div>
                </div>

                <div class="form-group u-mb-24">
                    <label class="form-label"><?php echo t('roles.field_description'); ?></label>
                    <input type="text" name="description" id="modal_description" class="form-control" placeholder="<?php echo t('roles.description_placeholder'); ?>">
                </div>

                <div class="u-d-flex u-jc-space-between u-ai-center u-bg-bg-body u-p-12-16 u-r-12 u-mb-16 u-bd-1-solid-border-color">
                    <h4 class="u-m-0 u-fs-14 u-fw-700 u-text-main">
                        <i class="fas fa-list-check u-primary"></i> <?php echo t('roles.matrix_title'); ?>
                    </h4>
                    <div class="u-flex-gap">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms(true)">
                            <i class="fas fa-check-double"></i> <?php echo t('roles.select_all'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAllPerms(false)">
                            <i class="fas fa-ban"></i> <?php echo t('roles.clear_all'); ?>
                        </button>
                    </div>
                </div>

                <!-- Module Permission Table -->
                <?php echo uiTableOpen([
                    t('roles.col_module'),
                    ['label' => t('roles.col_perm_view'), 'class' => 'u-text-center'],
                    ['label' => t('roles.col_perm_access'), 'class' => 'u-text-center'],
                    ['label' => t('roles.col_perm_edit'), 'class' => 'u-text-center'],
                    ['label' => t('roles.col_perm_delete'), 'class' => 'u-text-center'],
                ], ['search' => false, 'class' => 'perm-matrix-table']); ?>
                            <?php 
                            $last_group = null;
                            $group_icons = [
                                'General' => 'fas fa-layer-group',
                                'Outbound Line Management' => 'fas fa-network-wired',
                                'PBX Management' => 'fas fa-server',
                                'Network & Devices' => 'fas fa-ethernet',
                                'Administration' => 'fas fa-cog',
                                'Security' => 'fas fa-shield-alt',
                                'Integrations' => 'fas fa-puzzle-piece',
                                'AI' => 'fas fa-wand-magic-sparkles',
                                'Fax System' => 'fas fa-fax',
                                'Call Center' => 'fas fa-headset',
                            ];
                            $group_colors = [
                                'General' => 'var(--primary)',
                                'Outbound Line Management' => 'var(--secondary)',
                                'PBX Management' => '#8b5cf6',
                                'Network & Devices' => 'var(--primary)',
                                'Administration' => 'var(--warning)',
                                'Security' => 'var(--danger)',
                                'Integrations' => 'var(--purple)',
                                'AI' => 'var(--purple)',
                                'Fax System' => 'var(--teal)',
                                'Call Center' => 'var(--success)',
                            ];

                            foreach ($modules_definition as $m_key => $m_info):
                                $group_name = $m_info['group'];
                                $group_slug = $group_slugs[$group_name] ?? 'general';
                                $is_new_group = ($last_group !== null && $last_group !== $group_name);
                                if ($last_group === null || $is_new_group):
                                    $last_group = $group_name;
                                    $icon = $group_icons[$group_name] ?? 'fas fa-folder';
                                    $color = $group_colors[$group_name] ?? 'var(--primary)';
                            ?>
                                <tr class="perm-group-header-row" style="<?php echo $is_new_group ? 'border-top: 3px solid var(--border-color);' : ''; ?>">
                                    <td colspan="5" style="background: var(--bg-body); padding: <?php echo $is_new_group ? '14px 16px 8px 16px' : '10px 16px 8px 16px'; ?>; border-bottom: 1px solid var(--border-color); user-select: none;">
                                        <div class="u-flex-between">
                                            <span class="u-fs-13 u-fw-700 u-text-main u-d-inline-flex u-ai-center u-gap-8 u-uppercase u-letter-spacing-0-5">
                                                <i class="<?php echo $icon; ?>" style="color: <?php echo $color; ?>; font-size: 13px;"></i>
                                                <span><?php echo htmlspecialchars(t('roles.group_' . $group_slug, $group_name)); ?></span>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                                <tr class="perm-module-row">
                                    <td class="u-pl-24">
                                        <strong class="u-text-main"><?php echo htmlspecialchars(t('roles.module_' . $m_key, $m_info['title'])); ?></strong>
                                        <?php if (!empty($m_info['plugin'])): ?>
                                            <?php
                                            require_once dirname(__DIR__, 3) . '/src/core/PluginManager.php';
                                            $isPluginEnabled = PluginManager::isEnabled((string)$m_info['plugin']);
                                            ?>
                                            <span class="badge <?php echo $isPluginEnabled ? 'badge-info' : 'badge-secondary'; ?> u-fs-11 u-ml-4" title="Plugin: <?php echo htmlspecialchars((string)$m_info['plugin']); ?>">
                                                <i class="fas fa-plug"></i> <?php echo htmlspecialchars((string)$m_info['plugin']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <div class="u-fs-11 u-muted u-mono"><?php echo $m_key; ?></div>
                                    </td>
                                    <?php if (!empty($m_info['admin_only'])): ?>
                                        <td colspan="4" class="u-text-center u-muted u-fs-12" title="<?php echo htmlspecialchars(t('roles.admin_only_hint')); ?>">
                                            <i class="fas fa-lock"></i> <?php echo t('roles.admin_only'); ?>
                                        </td>
                                    <?php else: foreach (RoleRepository::ALL_ACTIONS as $action): ?>
                                        <td class="u-text-center">
                                            <?php if (in_array($action, $m_info['actions'], true)): ?>
                                                <input type="checkbox" name="perms[<?php echo $m_key; ?>][<?php echo $action; ?>]" value="1" class="perm-cb perm-<?php echo $action; ?>" id="p_<?php echo $m_key; ?>_<?php echo $action; ?>">
                                            <?php else: ?>
                                                <span class="u-muted" title="<?php echo htmlspecialchars(t('roles.action_not_used')); ?>">—</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php echo uiTableClose(); ?>

                <?php echo uiModalFooter('closeRoleModal()', t('roles.save_permissions')); ?>
            </form>
        </div>
    </div>
</div>

<script>
    window.ALL_PERMISSIONS = <?php echo json_encode($all_permissions); ?>;
    // Modules whose edit/delete only admin may have (RoleRepository::modulesDefinition()).
    window.ADMIN_ONLY_EDIT_MODULES = <?php echo json_encode(array_keys(array_filter($modules_definition, fn($m) => !empty($m['admin_only_edit'])))); ?>;
</script>
<script src="<?php echo asset('/assets/js/roles.js'); ?>"></script>
