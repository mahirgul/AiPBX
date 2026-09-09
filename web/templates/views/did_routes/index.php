<?php
use PBX\Destinations\DestinationRegistry;
$destOptionsCache = [];
?>
<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-route" style="color: var(--primary);"></i> <?php echo t('did.title'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('didHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <button class="btn btn-primary btn-sm" onclick="openCreateDidModal()" title="Yeni Ekle">
                <i class="fas fa-plus-circle"></i>
            </button>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="didHelpBox" style="display: none; padding: 15px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('did.help_title'); ?></h4>
        <?php echo t('did.help_body'); ?>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('did.col_did'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('did.col_desc'); ?></th>
                    <th><?php echo t('did.col_target'); ?></th>
                    <th><?php echo t('did.col_detail'); ?></th>
                    <th><?php echo t('did.col_recording'); ?></th>
                    <th><?php echo t('did.col_status'); ?></th>
                    <th class="text-right"><?php echo t('did.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($routes)): ?>
                    <?php echo uiTableEmptyRow(8, t('did.empty'), 'fa-route'); ?>
                <?php else: ?>
                    <?php foreach ($routes as $r):
                        $modObj = DestinationRegistry::getModule($r['dest_type']);
                        $modName = $modObj ? $modObj->getName() : $r['dest_type'];
                        $destLabel = DidRouteRepository::resolveDestLabel($r['dest_type'], $r['dest_id'], $r['did_number'], $destOptionsCache, $didDeptMap);
                    ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $r['id']; ?></td>
                            <td>
                                <span class="badge badge-info"><i class="fas fa-phone-volume"></i> <?php echo htmlspecialchars($r['did_number']); ?></span>
                            </td>
                            <td class="col-hide-mobile" style="font-weight: 700; color: var(--text-main);"><?php echo htmlspecialchars($r['title']); ?></td>
                            <td>
                                <span class="badge <?php echo DestinationRegistry::badgeClassFor($r['dest_type']); ?>"><?php echo htmlspecialchars($modName); ?></span>
                            </td>
                            <td>
                                <?php if ($destLabel !== null): ?>
                                    <span class="badge badge-primary"><?php echo htmlspecialchars($destLabel); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger" title="<?php echo htmlspecialchars(sprintf(t('did.target_not_found_tooltip'), $r['dest_id'])); ?>">
                                        <i class="fas fa-exclamation-triangle"></i> <?php echo t('did.target_not_found'); ?> (<?php echo htmlspecialchars($r['dest_id']); ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($r['record_call'])): ?>
                                    <span class="badge badge-danger"><i class="fas fa-microphone"></i> <?php echo t('did.recording_forced'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary" style="opacity: 0.6;"><?php echo t('did.recording_off'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($r['id'], $r['is_active'], 'route_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($r, 'openEditDidModal', 'route_id', 'delete_did_route', t('did.confirm_delete')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit DID Modal -->
<div class="modal-overlay" id="didModal">
    <div class="modal-card" style="max-width: 540px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="didModalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('did.new_route'); ?></h3>
            <button class="btn btn-secondary" onclick="closeDidModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_did_route" value="1">
                <input type="hidden" name="route_id" id="modal_route_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('did.field_did_number'); ?></label>
                    <input type="text" name="did_number" id="modal_did_number" class="form-control" required placeholder="ör: 3000">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('did.field_title'); ?></label>
                    <input type="text" name="title" id="modal_title" class="form-control" required placeholder="ör: Santral Ana Hat">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('did.field_dest_type'); ?></label>
                        <select name="dest_type" id="modal_dest_type" class="form-control">
                            <?php foreach ($modules as $m): ?>
                                <option value="<?php echo htmlspecialchars($m['key']); ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('did.field_dest_id'); ?></label>
                        <select name="dest_id" id="modal_dest_id" class="form-control">
                            <option value=""><?php echo t('did.loading'); ?></option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-language"></i> <?php echo t('did.field_language'); ?></label>
                    <select name="language" id="modal_language" class="form-control">
                        <option value=""><?php echo t('did.language_default'); ?></option>
                        <?php foreach (getAvailableLanguages() as $lang_code): ?>
                            <option value="<?php echo htmlspecialchars($lang_code); ?>"><?php echo htmlspecialchars(LANGUAGE_LABELS[$lang_code] ?? $lang_code); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: var(--text-muted); display: block; margin-top: 4px;"><?php echo t('did.language_help'); ?></small>
                </div>

                <div class="form-group" style="margin-top: 12px; background: var(--bg-input); padding: 12px 16px; border: 1px solid var(--border-color); border-radius: 8px;">
                    <label class="form-label" style="display: flex; align-items: center; gap: 10px; margin: 0; cursor: pointer;">
                        <input type="checkbox" name="record_call" id="modal_record_call" value="1" style="width: 18px; height: 18px; accent-color: var(--danger);">
                        <span style="font-size: 13px; font-weight: 600; color: var(--text-main);">
                            <i class="fas fa-microphone" style="color: var(--danger);"></i> <?php echo t('did.field_record_call'); ?>
                        </span>
                    </label>
                </div>

                <div class="form-group" style="margin-top: 10px;">
                    <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                        <span style="font-weight: 600;"><?php echo t('did.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeDidModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/destinations_helper.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/did_routes.js?v=<?php echo time(); ?>"></script>
