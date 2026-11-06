<?php $can_reorder = hasModulePermission('outbound_routes', 'edit'); ?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-sign-out-alt', t('outbound.title'), 'outboundHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

            <?php if (hasModulePermission('outbound_routes', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateRouteModal()" title="<?php echo t('common.add_new'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('common.add_new'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('outboundHelpBox', t('outbound.help_title')); ?>
        <?php echo t('outbound.help_body'); ?><br>
        <p class="u-mt-8"><?php echo t('common.help_number_order'); ?></p>
        - <strong><?php echo t('outbound.help_pattern'); ?></strong><br>
        - <strong><?php echo t('outbound.help_transform'); ?></strong><br>
        - <strong><?php echo t('outbound.help_trunks'); ?></strong>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        $can_reorder ? ['label' => '', 'class' => 'row-drag-handle'] : null,
        ['label' => '#', 'class' => 'col-hide-mobile u-w-50'],
        t('outbound.col_route'),
        t('outbound.col_pattern'),
        ['label' => t('outbound.col_transform'), 'class' => 'col-hide-mobile'],
        t('outbound.col_trunks'),
        ['label' => t('outbound.col_type'), 'class' => 'col-hide-mobile'],
        t('outbound.col_status'),
        ['label' => t('outbound.col_actions'), 'class' => 'text-right'],
    ], ['id' => 'routesTable']); ?>
                <?php if (empty($routes)): ?>
                    <?php echo uiTableEmptyRow(8 + ($can_reorder ? 1 : 0), t('outbound.empty'), 'fa-sign-out-alt'); ?>
                <?php else: ?>
                    <?php foreach ($routes as $r): ?>
                        <?php $route_trunks = !empty($r['trunks_json']) ? (json_decode($r['trunks_json'], true) ?: []) : []; ?>
                        <tr data-id="<?php echo (int)$r['id']; ?>">
                            <?php if ($can_reorder): ?><td class="row-drag-handle" title="<?php echo htmlspecialchars(t('common.drag_to_reorder')); ?>"><i class="fas fa-grip-vertical"></i></td><?php endif; ?>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $r['id']; ?></td>
                            <td class="u-strong">
                                <?php echo htmlspecialchars($r['route_name']); ?>
                                <?php if (!empty($r['is_emergency'])): ?>
                                    <span class="badge badge-danger" title="<?php echo htmlspecialchars($r['geolocation_uri'] ?? ''); ?>"><i class="fas fa-ambulance"></i> <?php echo t('outbound.badge_emergency'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-info"><code><?php echo htmlspecialchars($r['match_pattern']); ?></code></span></td>
                            <td class="col-hide-mobile u-fs-11">
                                <?php if (!empty($r['strip_front'])): ?><span class="badge badge-secondary"><?php echo t('outbound.strip_front'); ?>: <?php echo intval($r['strip_front']); ?></span><?php endif; ?>
                                <?php if (!empty($r['strip_back'])): ?><span class="badge badge-secondary"><?php echo t('outbound.strip_back'); ?>: <?php echo intval($r['strip_back']); ?></span><?php endif; ?>
                                <?php if (!empty($r['prepend'])): ?><span class="badge badge-secondary"><?php echo t('outbound.prepend'); ?>: <code><?php echo htmlspecialchars($r['prepend']); ?></code></span><?php endif; ?>
                                <?php if (!empty($r['append'])): ?><span class="badge badge-secondary"><?php echo t('outbound.append'); ?>: <code><?php echo htmlspecialchars($r['append']); ?></code></span><?php endif; ?>
                                <?php if (empty($r['strip_front']) && empty($r['strip_back']) && empty($r['prepend']) && empty($r['append'])): ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ($route_trunks as $i => $rt): ?>
                                    <?php if ($i > 0): ?><i class="fas fa-arrow-right u-opacity-0-4 u-fs-10" title="<?php echo htmlspecialchars(t('outbound.retry_tooltip')); ?>"></i><?php endif; ?>
                                    <span class="badge badge-success" title="<?php echo !empty($rt['callerid_override']) ? htmlspecialchars(t('outbound.callerid_prefix')) . ': ' . htmlspecialchars($rt['callerid_override']) : htmlspecialchars(t('outbound.callerid_default')); ?>">
                                        <i class="fas fa-server"></i> <?php echo htmlspecialchars($rt['trunk_name']); ?><?php echo !empty($rt['callerid_override']) ? ' (' . htmlspecialchars($rt['callerid_override']) . ')' : ''; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (empty($route_trunks)): ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if (!empty($r['is_internal'])): ?>
                                    <span class="badge badge-info"><i class="fas fa-building"></i> <?php echo t('outbound.type_internal'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary u-opacity-0-7"><?php echo t('outbound.type_external'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($r['id'], $r['is_active'], 'route_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php
                                    $actions = uiRowActions($r, 'openEditRouteModal', 'route_id', 'delete_route', sprintf(t('outbound.confirm_delete'), $r['route_name']));
                                    if (hasModulePermission('outbound_routes', 'edit')) {
                                        $cell = '<div class="table-actions-cell">';
                                        $actions = substr_replace($actions, $cell . uiEditButton($r, 'openCopyRouteModal', t('outbound.copy'), 'fa-copy'), strpos($actions, $cell), strlen($cell));
                                    }
                                    echo $actions;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>

<!-- Create / Edit Route Modal -->
<div class="modal-overlay" id="routeModal">
    <div class="modal-card u-maxw-560">
        <div class="modal-header">
            <h3 class="u-title" id="routeModalTitle"><i class="fas fa-plus-circle u-primary"></i> <?php echo t('outbound.new_route'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeRouteModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_route" value="1">
                <input type="hidden" name="route_id" id="modal_route_id" value="">

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_route_name'); ?></label>
                        <input type="text" name="route_name" id="modal_route_name" class="form-control" placeholder="<?php echo t('outbound_routes.name_ph'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_pattern'); ?></label>
                        <input type="text" name="match_pattern" id="modal_match_pattern" class="form-control" placeholder="<?php echo t('common.eg'); ?> _9X. / 112" required>
                    </div>
                </div>

                <div class="u-d-grid u-gtc-repeat-4-1fr u-gap-16">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_prepend'); ?></label>
                        <input type="text" name="prepend" id="modal_prepend" class="form-control" placeholder="<?php echo t('common.eg'); ?> 0">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_append'); ?></label>
                        <input type="text" name="append" id="modal_append" class="form-control" placeholder="<?php echo t('common.eg'); ?> 00">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_strip_front'); ?></label>
                        <input type="number" name="strip_front" id="modal_strip_front" class="form-control" value="0" min="0" max="10" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_strip_back'); ?></label>
                        <input type="number" name="strip_back" id="modal_strip_back" class="form-control" value="0" min="0" max="10" required>
                    </div>
                </div>

                <div class="form-group u-mt-4">
                    <label class="form-label"><i class="fas fa-server u-opacity-0-5"></i> <?php echo t('outbound.field_trunks'); ?></label>
                    <div id="route_trunks_list" class="u-d-flex u-col u-gap-8"></div>
                    <button type="button" class="btn btn-secondary btn-sm u-mt-8" onclick="addRouteTrunkRow()">
                        <i class="fas fa-plus"></i> <?php echo t('outbound.add_trunk'); ?>
                    </button>
                    <small class="u-hint"><?php echo t('outbound.trunks_help'); ?></small>
                </div>

                <!-- Clone template for each dynamic route trunk row -->
                <template id="route_trunk_row_template">
                    <div class="route-trunk-row u-flex-center">
                        <select name="trunk_name[]" class="form-control route-trunk-select u-flex-1">
                            <?php foreach ($trunks as $tk): ?>
                                <option value="<?php echo htmlspecialchars($tk['trunk_name']); ?>"><?php echo htmlspecialchars($tk['title'] . ' (' . $tk['trunk_name'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="trunk_cid[]" class="form-control route-trunk-cid u-flex-1" placeholder="<?php echo htmlspecialchars(t('outbound.callerid_placeholder')); ?>">
                        <button type="button" class="btn btn-icon btn-danger btn-sm" onclick="this.closest('.route-trunk-row').remove()" title="<?php echo htmlspecialchars(t('outbound.remove')); ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </template>

                <div class="form-group u-mt-10">
                    <label class="form-label"><?php echo t('outbound.field_group'); ?></label>
                    <input type="number" name="route_group" id="modal_route_group" class="form-control"
                           value="1" min="1" max="99" required>
                    <small class="u-hint u-fs-11">
                        <?php echo t('outbound.group_help'); ?>
                    </small>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_internal" id="modal_is_internal" value="1" class="u-check">
                        <span class="u-fw-600"><?php echo t('outbound.field_internal'); ?></span>
                    </label>
                    <small class="u-hint u-fs-11"><?php echo t('outbound.internal_help'); ?></small>
                </div>

                <div class="form-group u-mt-10" style="border: 1px dashed var(--border-color); border-radius: 6px; padding: 12px; background: rgba(var(--primary-rgb, 46, 125, 246), 0.03);">
                    <label class="form-label u-check-label u-mb-6">
                        <input type="checkbox" name="is_emergency" id="modal_is_emergency" value="1" class="u-check" onchange="toggleEmergencyFields(this.checked)">
                        <span class="u-fw-600"><i class="fas fa-ambulance text-danger"></i> <?php echo t('outbound.field_is_emergency'); ?></span>
                    </label>
                    <small class="u-hint u-fs-11 u-mb-8" style="display: block;"><?php echo t('outbound.emergency_help'); ?></small>

                    <div class="u-col u-gap-12" id="emergency_geo_fields" style="display: none;">
                        <div class="form-group u-mb-0">
                            <label class="form-label u-fs-12"><?php echo t('outbound.field_geolocation_uri'); ?></label>
                            <input type="text" name="geolocation_uri" id="modal_geolocation_uri" class="form-control" placeholder="<?php echo t('outbound.geolocation_uri_ph'); ?>">
                            <small class="u-hint u-fs-11">RFC 6442 Geolocation (SIP INVITE Header: &lt;geo:...&gt;)</small>
                        </div>
                        <div class="form-group u-mb-0">
                            <label class="form-label u-fs-12"><?php echo t('outbound.field_geolocation_routing'); ?></label>
                            <select name="geolocation_routing" id="modal_geolocation_routing" class="form-control">
                                <option value="yes"><?php echo t('outbound.geolocation_routing_yes'); ?></option>
                                <option value="no"><?php echo t('outbound.geolocation_routing_no'); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('outbound.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeRouteModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/outbound_routes.js'); ?>"></script>

<?php if ($can_reorder): ?>
<script>UIHelper.enableRowReorder(document.getElementById('routesTable'), 'outbound_routes');</script>
<?php endif; ?>
