<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-sign-out-alt" style="color: var(--primary);"></i> <?php echo t('outbound.title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('outboundHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('outbound_routes', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateRouteModal()" title="Yeni Ekle">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="outboundHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('outbound.help_title'); ?></h4>
        <?php echo t('outbound.help_body'); ?><br>
        - <strong><?php echo t('outbound.help_pattern'); ?></strong><br>
        - <strong><?php echo t('outbound.help_transform'); ?></strong><br>
        - <strong><?php echo t('outbound.help_trunks'); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('outbound.col_route'); ?></th>
                    <th><?php echo t('outbound.col_pattern'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('outbound.col_transform'); ?></th>
                    <th><?php echo t('outbound.col_trunks'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('outbound.col_type'); ?></th>
                    <th><?php echo t('outbound.col_status'); ?></th>
                    <th class="text-right"><?php echo t('outbound.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($routes)): ?>
                    <?php echo uiTableEmptyRow(8, t('outbound.empty'), 'fa-sign-out-alt'); ?>
                <?php else: ?>
                    <?php foreach ($routes as $r): ?>
                        <?php $route_trunks = !empty($r['trunks_json']) ? (json_decode($r['trunks_json'], true) ?: []) : []; ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $r['id']; ?></td>
                            <td style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($r['route_name']); ?></td>
                            <td><span class="badge badge-info"><code><?php echo htmlspecialchars($r['match_pattern']); ?></code></span></td>
                            <td class="col-hide-mobile" style="font-size: 11px;">
                                <?php if (!empty($r['strip_front'])): ?><span class="badge badge-secondary"><?php echo t('outbound.strip_front'); ?>: <?php echo intval($r['strip_front']); ?></span><?php endif; ?>
                                <?php if (!empty($r['strip_back'])): ?><span class="badge badge-secondary"><?php echo t('outbound.strip_back'); ?>: <?php echo intval($r['strip_back']); ?></span><?php endif; ?>
                                <?php if (!empty($r['prepend'])): ?><span class="badge badge-secondary"><?php echo t('outbound.prepend'); ?>: <code><?php echo htmlspecialchars($r['prepend']); ?></code></span><?php endif; ?>
                                <?php if (!empty($r['append'])): ?><span class="badge badge-secondary"><?php echo t('outbound.append'); ?>: <code><?php echo htmlspecialchars($r['append']); ?></code></span><?php endif; ?>
                                <?php if (empty($r['strip_front']) && empty($r['strip_back']) && empty($r['prepend']) && empty($r['append'])): ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ($route_trunks as $i => $rt): ?>
                                    <?php if ($i > 0): ?><i class="fas fa-arrow-right" style="opacity: 0.4; font-size: 10px;" title="<?php echo htmlspecialchars(t('outbound.retry_tooltip')); ?>"></i><?php endif; ?>
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
                                    <span class="badge badge-secondary" style="opacity: 0.7;"><?php echo t('outbound.type_external'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($r['id'], $r['is_active'], 'route_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($r, 'openEditRouteModal', 'route_id', 'delete_route', sprintf(t('outbound.confirm_delete'), $r['route_name'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit Route Modal -->
<div class="modal-overlay" id="routeModal">
    <div class="modal-card" style="max-width: 560px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="routeModalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('outbound.new_route'); ?></h3>
            <button class="btn btn-secondary" onclick="closeRouteModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_route" value="1">
                <input type="hidden" name="route_id" id="modal_route_id" value="">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_route_name'); ?></label>
                        <input type="text" name="route_name" id="modal_route_name" class="form-control" placeholder="Örn: 9+Dış Hat Araması" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_pattern'); ?></label>
                        <input type="text" name="match_pattern" id="modal_match_pattern" class="form-control" placeholder="Örn: _9X. veya 112" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_prepend'); ?></label>
                        <input type="text" name="prepend" id="modal_prepend" class="form-control" placeholder="Örn: 0">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('outbound.field_append'); ?></label>
                        <input type="text" name="append" id="modal_append" class="form-control" placeholder="Örn: 00">
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

                <div class="form-group" style="margin-top: 4px;">
                    <label class="form-label"><i class="fas fa-server" style="opacity: 0.5;"></i> <?php echo t('outbound.field_trunks'); ?></label>
                    <div id="route_trunks_list" style="display: flex; flex-direction: column; gap: 8px;"></div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addRouteTrunkRow()" style="margin-top: 8px;">
                        <i class="fas fa-plus"></i> <?php echo t('outbound.add_trunk'); ?>
                    </button>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('outbound.trunks_help'); ?></small>
                </div>

                <!-- Clone template for each dynamic route trunk row -->
                <template id="route_trunk_row_template">
                    <div class="route-trunk-row" style="display: flex; gap: 8px; align-items: center;">
                        <select name="trunk_name[]" class="form-control route-trunk-select" style="flex: 1;">
                            <?php foreach ($trunks as $tk): ?>
                                <option value="<?php echo htmlspecialchars($tk['trunk_name']); ?>"><?php echo htmlspecialchars($tk['title'] . ' (' . $tk['trunk_name'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="trunk_cid[]" class="form-control route-trunk-cid" placeholder="<?php echo htmlspecialchars(t('outbound.callerid_placeholder')); ?>" style="flex: 1;">
                        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.route-trunk-row').remove()" title="<?php echo htmlspecialchars(t('outbound.remove')); ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </template>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label"><?php echo t('outbound.field_group'); ?></label>
                    <input type="number" name="route_group" id="modal_route_group" class="form-control"
                           value="1" min="1" max="99" required>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">
                        <?php echo t('outbound.group_help'); ?>
                    </small>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_internal" id="modal_is_internal" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('outbound.field_internal'); ?></span>
                    </label>
                    <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('outbound.internal_help'); ?></small>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('outbound.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeRouteModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/outbound_routes.js?v=<?php echo time(); ?>"></script>
