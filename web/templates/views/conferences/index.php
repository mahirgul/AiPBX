<?php
/**
 * Conference rooms view
 */
?>

<div class="card">
    <?php echo uiPageHeaderOpen('fa-users-rectangle', t('conferences.title'), 'confHelpBox'); ?>
<span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($conferences); ?></span>
<?php echo uiPageHeaderActions(); ?>

            <?php if (hasModulePermission('conferences', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateConfModal()" title="<?php echo t('conferences.new_conf_btn'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('conferences.new_conf_btn'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('confHelpBox', t('conferences.help_title')); ?>
        <p><?php echo t('conferences.help_body'); ?></p>
        <ul>
            <li><strong><?php echo t('conferences.help_quick_dial'); ?></strong> <?php $p = htmlspecialchars($confPrefix ?? '#9'); echo sprintf(t('conferences.help_quick_dial_desc'), $p, $p); ?></li>
            <li><strong><?php echo t('conferences.help_pins'); ?></strong> <?php echo t('conferences.help_pins_desc'); ?></li>
            <li><strong><?php echo t('conferences.help_wait_leader'); ?></strong> <?php echo t('conferences.help_wait_leader_desc'); ?></li>
            <li><strong><?php echo t('conferences.help_live_ctrl'); ?></strong> <?php echo t('conferences.help_live_ctrl_desc'); ?></li>
        </ul>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        ['label' => t('conferences.col_room'), 'class' => 'u-w-70'],
        t('conferences.col_title'),
        t('conferences.col_pins'),
        t('conferences.col_features'),
        t('conferences.col_capacity'),
        t('conferences.col_recording'),
        t('common.status'),
        ['label' => t('my_phone.col_actions'), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($conferences)): ?>
                    <?php echo uiTableEmptyRow(8, t('conferences.empty'), 'fa-users-rectangle'); ?>
                <?php else: ?>
                    <?php foreach ($conferences as $cf): ?>
                        <tr>
                            <td>
                                <div class="u-d-flex u-col u-gap-3">
                                    <span class="badge badge-info u-fs-13"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($cf['room_number']); ?></span>
                                    <span class="badge badge-secondary u-fs-11 u-mono u-letter-spacing-0-5" title="<?php echo t('conferences.quick_dial'); ?>"><?php echo htmlspecialchars(($confPrefix ?? '#9') . $cf['room_number']); ?></span>
                                </div>
                            </td>
                            <td class="u-strong"><?php echo htmlspecialchars($cf['title']); ?></td>
                            <td>
                                <div class="u-d-flex u-gap-4 u-wrap u-fs-11">
                                    <?php if (!empty($cf['user_pin'])): ?>
                                        <span class="badge badge-secondary" title="<?php echo t('conferences.user_pin'); ?>"><i class="fas fa-key"></i> <?php echo t('conferences.participant'); ?>: <?php echo htmlspecialchars($cf['user_pin']); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary u-opacity-0-7"><?php echo t('conferences.no_pin'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($cf['admin_pin'])): ?>
                                        <span class="badge badge-warning" title="<?php echo t('conferences.admin_pin'); ?>"><i class="fas fa-crown"></i> <?php echo t('conferences.moderator'); ?>: <?php echo htmlspecialchars($cf['admin_pin']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="u-d-flex u-gap-4 u-wrap">
                                    <?php if ((int)$cf['wait_marked'] === 1): ?>
                                        <span class="badge badge-warning" title="<?php echo t('conferences.wait_leader_t'); ?>"><i class="fas fa-user-clock"></i> <?php echo t('conferences.wait_leader_short'); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$cf['mute_on_join'] === 1): ?>
                                        <span class="badge badge-secondary" title="<?php echo t('conferences.muted_entry_t'); ?>"><i class="fas fa-microphone-slash"></i> <?php echo t('conferences.muted_entry'); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$cf['announce_join_leave'] === 1): ?>
                                        <span class="badge badge-info" title="<?php echo t('conferences.announce_t'); ?>"><i class="fas fa-volume-up"></i> <?php echo t('conferences.announce'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($cf['video_mode']) && $cf['video_mode'] !== 'none'): ?>
                                        <span class="badge badge-purple" title="<?php echo t('conferences.video_mode'); ?>: <?php echo htmlspecialchars($cf['video_mode']); ?>"><i class="fas fa-video"></i> <?php echo strtoupper($cf['video_mode'] === 'follow_talker' ? 'talker' : $cf['video_mode']); ?></span>
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
                                <div class="u-d-inline-flex u-gap-4">
                                    <button type="button" class="btn btn-info btn-sm" onclick="showLiveMembers(<?php echo jsArg($cf['room_number']); ?>, <?php echo jsArg($cf['title']); ?>)" title="<?php echo t('conferences.live_members'); ?>">
                                        <i class="fas fa-users-viewfinder"></i> <?php echo t('conferences.live'); ?>
                                    </button>
                                    <?php if (hasModulePermission('conferences', 'edit')): ?>
                                        <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick='openEditConfModal(<?php echo jsArg($cf); ?>)' title="<?php echo t('common.edit'); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (hasModulePermission('conferences', 'delete')): ?>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('conferences.confirm_delete')), ENT_QUOTES); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="delete_conference" value="1">
                                            <input type="hidden" name="conference_id" value="<?php echo $cf['id']; ?>">
                                            <button type="submit" class="btn btn-icon btn-danger btn-sm" title="<?php echo t('common.delete'); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>

<!-- Modal: live participants -->
<div class="modal-overlay" id="liveMembersModal">
    <div class="modal-card u-maxw-640">
        <div class="modal-header">
            <h3 class="u-title" id="liveMembersTitle"><i class="fas fa-users-viewfinder u-primary"></i> <?php echo t('conferences.live_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('liveMembersModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="u-minh-120" id="liveMembersBody">
                <div class="text-center text-muted u-p-30">
                    <i class="fas fa-spinner fa-spin fa-2x"></i><br><br><?php echo t('conferences.loading_members'); ?>
                </div>
            </div>
            <div class="u-mt-15 u-d-flex u-jc-space-between u-ai-center">
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
    <div class="modal-card u-maxw-600">
        <div class="modal-header">
            <h3 class="u-title" id="confModalTitle"><i class="fas fa-users-rectangle u-primary"></i> <?php echo t('conferences.room'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('confModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_conference" value="1">
                <input type="hidden" name="id" id="modal_conf_id" value="0">

                <div class="u-d-grid u-gtc-1fr-2fr u-gap-16">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('conferences.room_number'); ?></label>
                        <input type="text" name="room_number" id="modal_conf_number" class="form-control" placeholder="<?php echo t('common.eg'); ?> 1001" pattern="[0-9]{3,6}" required oninput="updateConfDialPreview(this.value)">
                        <small class="u-muted u-mt-4 u-fs-11" style="display: block;">
                            <?php echo t('conferences.quick_dial'); ?>: <strong class="u-primary"><?php echo htmlspecialchars($confPrefix ?? '#9'); ?><span id="modal_conf_number_preview">1001</span></strong>
                        </small>
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

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-video text-primary"></i> <?php echo t('conferences.video_mode'); ?></label>
                        <select name="video_mode" id="modal_conf_video_mode" class="form-control">
                            <option value="none"><?php echo t('conferences.video_mode_none'); ?></option>
                            <option value="sfu"><?php echo t('conferences.video_mode_sfu'); ?></option>
                            <option value="follow_talker"><?php echo t('conferences.video_mode_talker'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><?php echo t('conferences.max_video_streams'); ?></label>
                        <input type="number" name="max_video_streams" id="modal_conf_max_video_streams" class="form-control" value="10" min="1" max="20">
                    </div>
                </div>

                <div class="u-bg-bg-input u-p-12 u-r-8 u-mb-16">
                    <div class="u-fw-600 u-fs-13 u-mb-10 u-text-main"><?php echo t('conferences.advanced'); ?></div>
                    <div class="u-d-grid u-gtc-1fr-1fr u-gap-12 u-fs-12">
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

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('confModal')", t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/conferences.js'); ?>"></script>
