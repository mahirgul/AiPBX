<?php
/**
 * Integrations → Web widgets: website call widgets and the call-back form.
 *
 * @var array $widgets
 * @var array $modules destination modules a widget may use
 * @var array $routes active outbound routes
 * @var array $log recent widget requests (abuse log)
 */

use PBX\Destinations\DestinationRegistry;

$canEdit = hasModulePermission('web_widgets', 'edit');
$csrf = getCSRFToken();
$labelCache = [];
$resultBadges = [
    'claimed' => 'success', 'callback' => 'success', 'issued' => 'info',
    'expired' => 'secondary', 'disabled' => 'secondary',
    'denied_origin' => 'danger', 'limit_daily' => 'warning', 'bad_number' => 'danger',
];
?>
<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/web_widgets.css'); ?>">

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-headset u-primary"></i> <?php echo t('web_widgets.title'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($widgets); ?></span>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('webWidgetsHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="openCreateWidgetModal()" title="<?php echo t('web_widgets.add'); ?>"><i class="fas fa-plus-circle"></i></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="module-help-box" id="webWidgetsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('web_widgets.help_title'); ?></h4>
        <p><?php echo t('web_widgets.help_body'); ?></p>
        <ul>
            <li><strong><?php echo t('web_widgets.help_number'); ?></strong> <?php echo t('web_widgets.help_number_desc'); ?></li>
            <li><strong><?php echo t('web_widgets.help_security'); ?></strong> <?php echo t('web_widgets.help_security_desc'); ?></li>
            <li><strong><?php echo t('web_widgets.help_callback'); ?></strong> <?php echo t('web_widgets.help_callback_desc'); ?></li>
            <li><strong><?php echo t('web_widgets.help_wordpress'); ?></strong> <?php echo t('web_widgets.help_wordpress_desc'); ?></li>
        </ul>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('web_widgets.col_name'); ?></th>
                    <th><?php echo t('web_widgets.col_destination'); ?></th>
                    <th><?php echo t('web_widgets.col_features'); ?></th>
                    <th><?php echo t('web_widgets.col_today'); ?></th>
                    <th><?php echo t('web_widgets.col_embed'); ?></th>
                    <th class="text-right"><?php echo t('web_widgets.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($widgets)): ?>
                    <?php echo uiTableEmptyRow(6, t('web_widgets.empty'), 'fa-headset'); ?>
                <?php else: ?>
                    <?php foreach ($widgets as $w): ?>
                        <?php
                        if ($w['dest_type'] === 'external') {
                            $destLabel = t('web_widgets.dest_external') . ': ' . $w['external_number'];
                        } else {
                            $mod = DestinationRegistry::getModule($w['dest_type']);
                            $destLabel = ($mod ? $mod->getName() : $w['dest_type']) . ': '
                                . (DestinationRegistry::resolveLabel($w['dest_type'], $w['dest_id'], $labelCache) ?? $w['dest_id']);
                        }
                        $editData = $w;
                        unset($editData['embed'], $editData['calls_today'], $editData['created_at']);
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($w['name']); ?></strong>
                                <span class="badge badge-info"><?php echo htmlspecialchars($w['number']); ?></span>
                                <?php if ((int) $w['is_active'] !== 1): ?><span class="badge badge-secondary"><?php echo t('web_widgets.off'); ?></span><?php endif; ?>
                                <div class="text-muted u-fs-11"><?php echo htmlspecialchars(str_replace("\n", ', ', (string) $w['allowed_origins'])); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($destLabel); ?></td>
                            <td>
                                <?php if ((int) $w['call_enabled'] === 1): ?><span class="badge badge-primary"><i class="fas fa-microphone"></i> <?php echo t('web_widgets.feature_call'); ?></span><?php endif; ?>
                                <?php if ((int) $w['callback_enabled'] === 1): ?><span class="badge badge-warning"><i class="fas fa-phone-volume"></i> <?php echo t('web_widgets.feature_callback'); ?></span><?php endif; ?>
                            </td>
                            <td><?php echo (int) $w['calls_today']; ?><?php if ((int) $w['daily_limit'] > 0): ?> / <?php echo (int) $w['daily_limit']; ?><?php endif; ?></td>
                            <td>
                                <button type="button" class="btn btn-secondary btn-sm" data-code="<?php echo htmlspecialchars($w['embed']); ?>" onclick="copyWidgetCode(this)" title="<?php echo htmlspecialchars($w['embed']); ?>">
                                    <i class="fas fa-copy"></i> <?php echo t('web_widgets.copy_code'); ?>
                                </button>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <a class="btn btn-secondary btn-sm" href="/web-widgets?try=<?php echo (int) $w['id']; ?>" target="_blank" rel="noopener" title="<?php echo t('web_widgets.try'); ?>"><i class="fas fa-play"></i></a>
                                    <?php if ($canEdit): ?>
                                        <form method="POST" class="u-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="widget_id" value="<?php echo (int) $w['id']; ?>">
                                            <button type="submit" name="toggle_widget" value="1" class="btn btn-<?php echo (int) $w['is_active'] === 1 ? 'warning' : 'success'; ?> btn-sm" title="<?php echo t((int) $w['is_active'] === 1 ? 'web_widgets.disable' : 'web_widgets.enable'); ?>"><i class="fas fa-power-off"></i></button>
                                        </form>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditWidgetModal(<?php echo json_encode($editData, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>)' title="<?php echo t('common.edit'); ?>"><i class="fas fa-edit"></i></button>
                                    <?php endif; ?>
                                    <?php if (hasModulePermission('web_widgets', 'delete')): ?>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('web_widgets.delete_confirm')), ENT_QUOTES); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="widget_id" value="<?php echo (int) $w['id']; ?>">
                                            <button type="submit" name="delete_widget" value="1" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>"><i class="fas fa-trash"></i></button>
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
        <div class="card-title"><i class="fas fa-list u-primary"></i> <?php echo t('web_widgets.log_title'); ?></div>
    </div>
    <p class="text-muted u-fs-12"><?php echo t('web_widgets.log_desc'); ?></p>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th><?php echo t('web_widgets.col_time'); ?></th>
                    <th><?php echo t('web_widgets.col_name'); ?></th>
                    <th><?php echo t('web_widgets.col_kind'); ?></th>
                    <th><?php echo t('web_widgets.col_result'); ?></th>
                    <th><?php echo t('web_widgets.col_visitor'); ?></th>
                    <th><?php echo t('web_widgets.col_origin'); ?></th>
                    <th><?php echo t('web_widgets.col_ip'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($log)): ?>
                    <?php echo uiTableEmptyRow(7, t('web_widgets.log_empty'), 'fa-list'); ?>
                <?php else: ?>
                    <?php foreach ($log as $row): ?>
                        <tr>
                            <td class="u-fs-11"><?php echo htmlspecialchars($row['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($row['widget_name']); ?></td>
                            <td><?php echo t('web_widgets.kind_' . $row['kind'], $row['kind']); ?></td>
                            <td><?php echo uiStatusBadge($row['result'], $resultBadges, 'secondary', t('web_widgets.result_' . $row['result'], $row['result'])); ?></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars(trim($row['caller_name'] . ' ' . $row['caller_number'])); ?></td>
                            <td class="u-fs-11"><?php echo htmlspecialchars($row['origin']); ?></td>
                            <td class="u-fs-11" title="<?php echo htmlspecialchars($row['user_agent']); ?>"><?php echo htmlspecialchars($row['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Modal: add / edit widget -->
<div class="modal-overlay" id="widgetModal">
    <div class="modal-card" style="max-width: 720px;">
        <div class="modal-header">
            <h3 class="u-title" id="widgetModalTitle"><i class="fas fa-headset u-primary"></i> <?php echo t('web_widgets.add'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('widgetModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" id="widgetForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="save_widget" value="1">
                <input type="hidden" name="id" id="widget_id" value="0">

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.name'); ?></label>
                        <input type="text" name="name" id="widget_name" class="form-control" maxlength="100" required placeholder="<?php echo t('web_widgets.name_ph'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.number'); ?></label>
                        <input type="text" name="number" id="widget_number" class="form-control" pattern="[0-9]{2,20}" required placeholder="7001">
                        <div class="text-muted u-fs-11"><?php echo t('web_widgets.number_help'); ?></div>
                    </div>
                </div>

                <h4 class="web-widgets-section"><?php echo t('web_widgets.section_destination'); ?></h4>
                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.dest_type'); ?></label>
                        <select name="dest_type" id="widget_dest_type" class="form-control" onchange="onWidgetDestTypeChange()">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                            <option value="external"><?php echo t('web_widgets.dest_external'); ?></option>
                        </select>
                    </div>
                    <div class="form-group" id="widget_dest_id_group">
                        <label class="form-label"><?php echo t('web_widgets.dest_id'); ?></label>
                        <select name="dest_id" id="widget_dest_id" class="form-control"></select>
                    </div>
                    <div class="form-group" id="widget_external_group">
                        <label class="form-label"><?php echo t('web_widgets.external_number'); ?></label>
                        <input type="text" name="external_number" id="widget_external_number" class="form-control" placeholder="905321234567">
                    </div>
                </div>
                <div class="alert alert-warning u-fs-12" id="widget_external_warning"><?php echo t('web_widgets.external_warning'); ?></div>

                <h4 class="web-widgets-section"><?php echo t('web_widgets.section_features'); ?></h4>
                <label class="u-check-label-6"><input type="checkbox" name="call_enabled" id="widget_call_enabled" value="1" class="u-accent"> <?php echo t('web_widgets.call_enabled'); ?></label>
                <label class="u-check-label-6"><input type="checkbox" name="callback_enabled" id="widget_callback_enabled" value="1" class="u-accent" onchange="onWidgetDestTypeChange()"> <?php echo t('web_widgets.callback_enabled'); ?></label>
                <div class="u-grid-2" id="widget_callback_group">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.callback_prefixes'); ?></label>
                        <input type="text" name="callback_prefixes" id="widget_callback_prefixes" class="form-control" placeholder="05, 902">
                        <div class="text-muted u-fs-11"><?php echo t('web_widgets.callback_prefixes_help'); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.callback_cid'); ?></label>
                        <input type="text" name="callback_cid" id="widget_callback_cid" class="form-control">
                        <div class="text-muted u-fs-11"><?php echo t('web_widgets.callback_cid_help'); ?></div>
                    </div>
                </div>
                <div class="form-group" id="widget_route_group">
                    <label class="form-label"><?php echo t('web_widgets.outbound_route'); ?></label>
                    <select name="outbound_route_id" id="widget_outbound_route_id" class="form-control">
                        <option value="0">—</option>
                        <?php foreach ($routes as $r): ?>
                            <option value="<?php echo (int) $r['id']; ?>"><?php echo htmlspecialchars($r['route_name'] . ' (' . $r['match_pattern'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h4 class="web-widgets-section"><?php echo t('web_widgets.section_security'); ?></h4>
                <div class="form-group">
                    <label class="form-label"><?php echo t('web_widgets.allowed_origins'); ?></label>
                    <textarea name="allowed_origins" id="widget_allowed_origins" class="form-control" rows="3" required placeholder="www.example.com&#10;*.example.org"></textarea>
                    <div class="text-muted u-fs-11"><?php echo t('web_widgets.allowed_origins_help'); ?></div>
                </div>
                <div class="web-widgets-limits">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.max_concurrent'); ?></label>
                        <input type="number" name="max_concurrent" id="widget_max_concurrent" class="form-control" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.max_call_seconds'); ?></label>
                        <input type="number" name="max_call_seconds" id="widget_max_call_seconds" class="form-control" min="60" max="14400">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.ip_hourly_limit'); ?></label>
                        <input type="number" name="ip_hourly_limit" id="widget_ip_hourly_limit" class="form-control" min="1" max="1000">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.daily_limit'); ?></label>
                        <input type="number" name="daily_limit" id="widget_daily_limit" class="form-control" min="0" max="100000">
                        <div class="text-muted u-fs-11"><?php echo t('web_widgets.daily_limit_help'); ?></div>
                    </div>
                </div>

                <h4 class="web-widgets-section"><?php echo t('web_widgets.section_look'); ?></h4>
                <div class="web-widgets-limits">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.button_text'); ?></label>
                        <input type="text" name="button_text" id="widget_button_text" class="form-control" maxlength="60" placeholder="<?php echo t('web_widgets.button_text_ph'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.color'); ?></label>
                        <input type="color" name="color" id="widget_color" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.position'); ?></label>
                        <select name="position" id="widget_position" class="form-control">
                            <option value="right"><?php echo t('web_widgets.position_right'); ?></option>
                            <option value="left"><?php echo t('web_widgets.position_left'); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('web_widgets.language'); ?></label>
                        <select name="language" id="widget_language" class="form-control">
                            <?php foreach (WebWidgetService::LANGUAGES as $code): ?>
                                <option value="<?php echo $code; ?>"><?php echo htmlspecialchars(UI_LANGUAGES[$code] ?? $code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label class="u-check-label-6"><input type="checkbox" name="ask_name" id="widget_ask_name" value="1" class="u-accent"> <?php echo t('web_widgets.ask_name'); ?></label>
                <label class="u-check-label-6"><input type="checkbox" name="is_active" id="widget_is_active" value="1" class="u-accent"> <?php echo t('web_widgets.is_active'); ?></label>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('widgetModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo asset('/assets/js/destinations_helper.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/web_widgets.js'); ?>"></script>
