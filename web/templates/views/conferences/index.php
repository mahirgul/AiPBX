<?php
/**
 * Conference rooms view
 */
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-users-rectangle u-primary"></i> <?php echo t('conferences.title', 'Konferans Odaları (ConfBridge)'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($conferences); ?></span>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('confHelpBox')" title="<?php echo t('common.module_guide', 'Modül Rehberi'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('conferences', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateConfModal()" title="<?php echo t('conferences.new_conf_btn', 'Yeni Konferans Odası'); ?>">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="confHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('conferences.help_title', 'Konferans Odaları Nasıl Çalışır?'); ?></h4>
        <p><?php echo t('conferences.help_body', 'Konferans odaları, dahili ve harici arayanların aynı anda bağlanarak toplu görüşme yapmasını sağlar.'); ?></p>
        <ul>
            <li><strong><?php echo t('conferences.help_pins', 'Kullanıcı ve Yönetici PIN:'); ?></strong> <?php echo t('conferences.help_pins_desc', 'PIN tanımlanırsa arayanlardan PIN istenir. Yönetici PIN ile girenler lider yetkisi kazanır.'); ?></li>
            <li><strong><?php echo t('conferences.help_wait_leader', 'Lideri Bekle:'); ?></strong> <?php echo t('conferences.help_wait_leader_desc', 'Aktif edilirse, bir yönetici odaya girene kadar katılımcılar bekleme müziği dinler, görüşme lider gelince başlar.'); ?></li>
            <li><strong><?php echo t('conferences.help_live_ctrl', 'Canlı Denetim:'); ?></strong> <?php echo t('conferences.help_live_ctrl_desc', 'Aktif katılımcıları canlı izleyebilir, istediklerinizi sessize alabilir (Mute) veya odadan atabilirsiniz (Kick).'); ?></li>
        </ul>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 70px;"><?php echo t('conferences.col_room'); ?></th>
                    <th><?php echo t('conferences.col_title'); ?></th>
                    <th><?php echo t('conferences.col_pins'); ?></th>
                    <th><?php echo t('conferences.col_features'); ?></th>
                    <th><?php echo t('conferences.col_capacity'); ?></th>
                    <th><?php echo t('conferences.col_recording'); ?></th>
                    <th><?php echo t('common.status'); ?></th>
                    <th class="text-right"><?php echo t('my_phone.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($conferences)): ?>
                    <?php echo uiTableEmptyRow(8, t('conferences.empty', 'Henüz tanımlanmış bir konferans odası bulunmuyor.'), 'fa-users-rectangle'); ?>
                <?php else: ?>
                    <?php foreach ($conferences as $cf): ?>
                        <tr>
                            <td><span class="badge badge-info u-fs-13"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($cf['room_number']); ?></span></td>
                            <td class="u-strong"><?php echo htmlspecialchars($cf['title']); ?></td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap; font-size: 11px;">
                                    <?php if (!empty($cf['user_pin'])): ?>
                                        <span class="badge badge-secondary" title="<?php echo t('conferences.user_pin'); ?>"><i class="fas fa-key"></i> <?php echo t('conferences.participant'); ?>: <?php echo htmlspecialchars($cf['user_pin']); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" style="opacity: 0.7;"><?php echo t('conferences.no_pin'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($cf['admin_pin'])): ?>
                                        <span class="badge badge-warning" title="<?php echo t('conferences.admin_pin'); ?>"><i class="fas fa-crown"></i> <?php echo t('conferences.moderator'); ?>: <?php echo htmlspecialchars($cf['admin_pin']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php if ((int)$cf['wait_marked'] === 1): ?>
                                        <span class="badge badge-warning" title="<?php echo t('conferences.wait_leader_t'); ?>"><i class="fas fa-user-clock"></i> <?php echo t('conferences.wait_leader_short'); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$cf['mute_on_join'] === 1): ?>
                                        <span class="badge badge-secondary" title="<?php echo t('conferences.muted_entry_t'); ?>"><i class="fas fa-microphone-slash"></i> <?php echo t('conferences.muted_entry'); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$cf['announce_join_leave'] === 1): ?>
                                        <span class="badge badge-info" title="<?php echo t('conferences.announce_t'); ?>"><i class="fas fa-volume-up"></i> <?php echo t('conferences.announce'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><span class="badge badge-secondary"><i class="fas fa-users"></i> <?php echo t('cc_board.max'); ?> <?php echo (int)$cf['max_members']; ?></span></td>
                            <td>
                                <?php if ((int)$cf['record_conference'] === 1): ?>
                                    <span class="badge badge-danger" title="<?php echo t('conferences.recording_t'); ?>"><i class="fas fa-microphone"></i> <?php echo t('conferences.col_recording'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted u-fs-11">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$cf['is_active'] === 1): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('common.active'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times"></i> <?php echo t('common.passive'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div style="display: inline-flex; gap: 4px;">
                                    <button type="button" class="btn btn-info btn-sm" onclick="showLiveMembers('<?php echo htmlspecialchars($cf['room_number']); ?>', '<?php echo htmlspecialchars(addslashes($cf['title'])); ?>')" title="<?php echo t('conferences.live_members'); ?>">
                                        <i class="fas fa-users-viewfinder"></i> <?php echo t('conferences.live'); ?>
                                    </button>
                                    <?php if (hasModulePermission('conferences', 'edit')): ?>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick='openEditConfModal(<?php echo json_encode($cf); ?>)' title="<?php echo t('common.edit'); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('conferences.confirm_delete')), ENT_QUOTES); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="delete_conference" value="1">
                                            <input type="hidden" name="conference_id" value="<?php echo $cf['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.delete'); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
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

<!-- Modal: live participants -->
<div class="modal-overlay" id="liveMembersModal">
    <div class="modal-card" style="max-width: 640px;">
        <div class="modal-header">
            <h3 class="u-title" id="liveMembersTitle"><i class="fas fa-users-viewfinder u-primary"></i> <?php echo t('conferences.live_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('liveMembersModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="liveMembersBody" style="min-height: 120px;">
                <div class="text-center text-muted u-p-30">
                    <i class="fas fa-spinner fa-spin fa-2x"></i><br><br><?php echo t('conferences.loading_members'); ?>
                </div>
            </div>
            <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                <button type="button" class="btn btn-secondary btn-sm" onclick="refreshLiveMembers()">
                    <i class="fas fa-sync-alt"></i> <?php echo t('common.refresh'); ?>
                </button>
                <button type="button" class="btn btn-secondary" onclick="UIHelper.closeOverlayModal('liveMembersModal')"><?php echo t('common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: add / edit conference room -->
<div class="modal-overlay" id="confModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="u-title" id="confModalTitle"><i class="fas fa-users-rectangle u-primary"></i> <?php echo t('conferences.room'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('confModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_conference" value="1">
                <input type="hidden" name="id" id="modal_conf_id" value="0">

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('conferences.room_number'); ?></label>
                        <input type="text" name="room_number" id="modal_conf_number" class="form-control" placeholder="<?php echo t('common.eg'); ?> 6000" pattern="[0-9]{3,6}" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('conferences.title_lbl'); ?></label>
                        <input type="text" name="title" id="modal_conf_title" class="form-control" placeholder="<?php echo t('conferences.title_ph'); ?>" required>
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-key text-info"></i> <?php echo t('conferences.user_pin_lbl'); ?></label>
                        <input type="text" name="user_pin" id="modal_conf_user_pin" class="form-control" placeholder="<?php echo t('common.eg'); ?> 1234" pattern="[0-9]*">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-crown text-warning"></i> <?php echo t('conferences.admin_pin_lbl'); ?></label>
                        <input type="text" name="admin_pin" id="modal_conf_admin_pin" class="form-control" placeholder="<?php echo t('common.eg'); ?> 9876" pattern="[0-9]*">
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('conferences.max_members'); ?></label>
                        <input type="number" name="max_members" id="modal_conf_max_members" class="form-control" value="50" min="2" max="500">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('conferences.moh_class'); ?></label>
                        <input type="text" name="music_on_hold" id="modal_conf_moh" class="form-control" value="default" placeholder="default">
                    </div>
                </div>

                <div style="background: var(--bg-input); padding: 12px; border-radius: 8px; margin-bottom: 16px;">
                    <div class="u-fw-600 u-fs-13 u-mb-10 u-text-main"><?php echo t('conferences.advanced'); ?></div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12px;">
                        <label class="u-check-label-6">
                            <input type="checkbox" name="wait_marked" id="modal_conf_wait_marked" value="1" class="u-accent">
                            <span><?php echo t('conferences.wait_leader'); ?></span>
                        </label>
                        <label class="u-check-label-6">
                            <input type="checkbox" name="end_marked" id="modal_conf_end_marked" value="1" class="u-accent">
                            <span><?php echo t('conferences.end_on_leader_exit'); ?></span>
                        </label>
                        <label class="u-check-label-6">
                            <input type="checkbox" name="record_conference" id="modal_conf_record" value="1" class="u-accent">
                            <span><?php echo t('conferences.record'); ?></span>
                        </label>
                        <label class="u-check-label-6">
                            <input type="checkbox" name="mute_on_join" id="modal_conf_mute_on_join" value="1" class="u-accent">
                            <span><?php echo t('conferences.start_muted'); ?></span>
                        </label>
                        <label class="u-check-label-6">
                            <input type="checkbox" name="announce_join_leave" id="modal_conf_announce_join" value="1" checked class="u-accent">
                            <span><?php echo t('conferences.announce_join'); ?></span>
                        </label>
                        <label class="u-check-label-6">
                            <input type="checkbox" name="announce_user_count" id="modal_conf_announce_count" value="1" checked class="u-accent">
                            <span><?php echo t('conferences.announce_count'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_conf_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('conferences.room_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('confModal')", t('common.save', 'Kaydet'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/conferences.js'); ?>"></script>
