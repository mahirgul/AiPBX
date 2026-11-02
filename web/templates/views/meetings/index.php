<?php
/**
 * Video Meetings & Guest Conferences View (/meetings)
 */
$canEdit = $can_edit ?? hasModulePermission('meetings', 'edit');
$canDelete = $can_delete ?? hasModulePermission('meetings', 'delete');
?>

<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-video u-primary"></i> <?php echo t('meetings.title', 'Video Meetings'); ?>
            <span class="badge badge-secondary u-fs-11 u-ml-8"><?php echo count($meetings); ?></span>
        </div>
        <div class="u-flex-center">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('meetingsHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if ($canEdit): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateMeetingModal()" title="<?php echo t('meetings.btn_new_meeting', 'Schedule Meeting'); ?>">
                    <i class="fas fa-calendar-plus"></i> <span class="u-ml-4"><?php echo t('meetings.btn_new_meeting', 'Schedule Meeting'); ?></span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="meetingsHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('meetings.help_title', 'Video Meetings & Asterisk ConfBridge SFU'); ?></h4>
        <p><?php echo t('meetings.help_intro', 'AiPBX provides WebRTC multi-party video conferencing backed by Asterisk app_confbridge SFU / Follow-Talker modes.'); ?></p>
        <ul>
            <li><strong><?php echo t('meetings.help_sfu_title', 'SFU Mode (Selective Forwarding Unit):'); ?></strong> <?php echo t('meetings.help_sfu_desc', 'For modern WebRTC browsers. Streams are forwarded without re-encoding, allowing responsive multi-speaker gallery grids with minimal server CPU.'); ?></li>
            <li><strong><?php echo t('meetings.help_talker_title', 'Follow-Talker Mode:'); ?></strong> <?php echo t('meetings.help_talker_desc', 'For SIP hardware video phones. Actively switches the dominant speaker to all endpoints.'); ?></li>
            <li><strong><?php echo t('meetings.help_guests_title', 'Guest Magic Links:'); ?></strong> <?php echo t('meetings.help_guests_desc', 'Invite external clients or non-subscribers via email with a one-click magic link (/meet/{token}) and downloadable .ics calendar invite.'); ?></li>
        </ul>
        <div style="margin-top: 10px;"><strong><?php echo t('common.help_limits'); ?></strong><ul style="margin: 4px 0 0 20px;"><li><?php echo t('meetings.limit_gone'); ?></li></ul></div>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 80px;"><?php echo t('conferences.col_room'); ?></th>
                    <th><?php echo t('meetings.col_topic', 'Topic / Meeting'); ?></th>
                    <th><?php echo t('meetings.col_schedule', 'Schedule'); ?></th>
                    <th><?php echo t('meetings.col_mode', 'Video Mode'); ?></th>
                    <th><?php echo t('meetings.col_access', 'Access & PIN'); ?></th>
                    <th><?php echo t('meetings.col_participants', 'Participants'); ?></th>
                    <th><?php echo t('common.status'); ?></th>
                    <th class="text-right"><?php echo t('my_phone.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($meetings)): ?>
                    <?php echo uiTableEmptyRow(8, t('meetings.empty', 'No scheduled meetings yet.'), 'fa-video'); ?>
                <?php else: ?>
                    <?php foreach ($meetings as $m): ?>
                        <?php
                            $now = time();
                            $start = !empty($m['scheduled_start']) ? strtotime($m['scheduled_start']) : (!empty($m['starts_at']) ? strtotime($m['starts_at']) : 0);
                            $end = !empty($m['scheduled_end']) ? strtotime($m['scheduled_end']) : (!empty($m['ends_at']) ? strtotime($m['ends_at']) : 0);
                            $status = $m['status'] ?? 'scheduled';
                            $isLive = ($status === 'in_progress') || ($status === 'scheduled' && $now >= $start && ($end === 0 || $now <= $end));
                            $isPast = ($status === 'completed') || ($end > 0 && $now > $end);
                            $isCancelled = ($status === 'cancelled');
                            $guestToken = (string)($m['meeting_token'] ?? $m['guest_token'] ?? '');
                            $guestUrl = MeetingService::getMeetingUrl($guestToken);
                            $roomPin = (string)($m['pin'] ?? $m['user_pin'] ?? '');
                            $hostPin = (string)($m['host_pin'] ?? '');
                        ?>
                        <tr class="<?php echo $isCancelled ? 'u-opacity-60' : ''; ?>">
                            <td>
                                <span class="badge badge-info u-fs-13">
                                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($m['room_number']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="u-strong u-fs-14"><?php echo htmlspecialchars($m['title']); ?></div>
                                <?php if (!empty($m['description'])): ?>
                                    <div class="u-fs-11 text-muted u-mt-2"><?php echo htmlspecialchars($m['description']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($start > 0): ?>
                                    <div class="u-fs-12 u-fw-600">
                                        <i class="far fa-calendar-alt text-muted"></i> <?php echo date('d.m.Y H:i', $start); ?>
                                    </div>
                                    <?php if ($end > 0): ?>
                                        <div class="u-fs-11 text-muted">
                                            <i class="far fa-clock"></i> <?php echo t('meetings.until', 'until'); ?> <?php echo date('H:i', $end); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted u-fs-11"><?php echo t('meetings.always_open', 'Open / Recurring'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($m['video_mode'] ?? 'sfu') === 'sfu'): ?>
                                    <span class="badge badge-purple" title="Selective Forwarding Unit"><i class="fas fa-grip"></i> SFU Gallery</span>
                                <?php else: ?>
                                    <span class="badge badge-info" title="Active Speaker Switching"><i class="fas fa-user"></i> Follow-Talker</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap; font-size: 11px;">
                                    <?php if ($roomPin !== ''): ?>
                                        <span class="badge badge-secondary" title="<?php echo t('conferences.user_pin'); ?>"><i class="fas fa-key"></i> <?php echo htmlspecialchars($roomPin); ?></span>
                                    <?php endif; ?>
                                    <?php if ($hostPin !== ''): ?>
                                        <span class="badge badge-warning" title="<?php echo t('conferences.admin_pin'); ?>"><i class="fas fa-crown"></i> <?php echo htmlspecialchars($hostPin); ?></span>
                                    <?php endif; ?>
                                    <?php if ($roomPin === '' && $hostPin === ''): ?>
                                        <span class="badge badge-secondary" style="opacity: 0.7;"><?php echo t('conferences.no_pin'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-secondary">
                                    <i class="fas fa-users"></i> <?php echo (int)($m['invite_count'] ?? 0); ?> <?php echo t('meetings.invited', 'invited'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isCancelled): ?>
                                    <span class="badge badge-danger"><?php echo t('meetings.status_cancelled', 'Cancelled'); ?></span>
                                <?php elseif ($isLive): ?>
                                    <span class="badge badge-success"><i class="fas fa-circle fa-beat u-mr-4"></i> <?php echo t('meetings.status_live', 'Live Now'); ?></span>
                                <?php elseif ($isPast): ?>
                                    <span class="badge badge-secondary"><?php echo t('meetings.status_ended', 'Ended'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-primary"><?php echo t('meetings.status_scheduled', 'Scheduled'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <div style="display: inline-flex; gap: 4px;">
                                    <?php if (!$isCancelled): ?>
                                        <a href="/meet/<?php echo urlencode($guestToken); ?>" target="_blank" class="btn btn-success btn-sm" title="<?php echo t('meetings.btn_join', 'Join Room'); ?>">
                                            <i class="fas fa-video"></i> <?php echo t('meetings.btn_join', 'Join'); ?>
                                        </a>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyGuestLink(<?php echo jsArg($guestUrl); ?>)" title="<?php echo t('meetings.btn_copy_link', 'Copy Link'); ?>">
                                            <i class="fas fa-link"></i>
                                        </button>
                                        <?php if ($canEdit): ?>
                                            <button type="button" class="btn btn-info btn-sm" onclick="openInviteModal(<?php echo jsArg($m); ?>)" title="<?php echo t('meetings.btn_invite_guest', 'Invite Guest'); ?>">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="openEditMeetingModal(<?php echo jsArg($m); ?>)" title="<?php echo t('common.edit'); ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <a href="/meetings?ics=<?php echo (int)$m['id']; ?>" class="btn btn-secondary btn-sm" title="<?php echo t('meetings.btn_download_ics', 'Download .ics'); ?>">
                                            <i class="fas fa-calendar-alt"></i>
                                        </a>
                                        <?php if ($canDelete): ?>
                                            <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(t('meetings.confirm_cancel', 'Cancel this scheduled meeting?')), ENT_QUOTES); ?>);">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="cancel_meeting" value="1">
                                                <input type="hidden" name="meeting_id" value="<?php echo (int)$m['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" title="<?php echo t('common.cancel'); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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

<!-- Modal: Schedule / Edit Meeting -->
<div class="modal-overlay" id="meetingModal">
    <div class="modal-card" style="max-width: 620px;">
        <div class="modal-header">
            <h3 class="u-title" id="meetingModalTitle"><i class="fas fa-calendar-plus u-primary"></i> <?php echo t('meetings.modal_schedule_title', 'Schedule Video Meeting'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('meetingModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" id="meetingForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="save_meeting" value="1">
                <input type="hidden" name="id" id="modal_meeting_id" value="0">

                <div class="form-group">
                    <label class="form-label u-fw-600"><i class="fas fa-heading text-muted"></i> <?php echo t('meetings.lbl_title', 'Meeting Title / Topic'); ?> *</label>
                    <input type="text" name="title" id="modal_meeting_title" class="form-control" required placeholder="<?php echo t('meetings.ph_title', 'e.g. Sales Team Weekly sync'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-align-left text-muted"></i> <?php echo t('meetings.lbl_desc', 'Description / Agenda'); ?></label>
                    <textarea name="description" id="modal_meeting_desc" class="form-control" rows="2" placeholder="<?php echo t('meetings.ph_desc', 'Optional meeting notes or agenda...'); ?>"></textarea>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="far fa-calendar text-muted"></i> <?php echo t('meetings.lbl_start', 'Starts At'); ?> *</label>
                        <input type="datetime-local" name="starts_at" id="modal_meeting_starts_at" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="far fa-clock text-muted"></i> <?php echo t('meetings.lbl_end', 'Ends At'); ?></label>
                        <input type="datetime-local" name="ends_at" id="modal_meeting_ends_at" class="form-control">
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-video text-primary"></i> <?php echo t('meetings.lbl_video_mode', 'Video Architecture'); ?></label>
                        <select name="video_mode" id="modal_meeting_video_mode" class="form-control">
                            <option value="sfu"><?php echo t('meetings.mode_sfu', 'SFU - Multi-stream Grid (WebRTC)'); ?></option>
                            <option value="follow_talker"><?php echo t('meetings.mode_talker', 'Follow-Talker - Active Speaker (SIP Phones)'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-hashtag text-muted"></i> <?php echo t('conferences.room', 'Room Number'); ?></label>
                        <input type="text" name="room_number" id="modal_meeting_room" class="form-control" placeholder="<?php echo t('meetings.ph_room_auto', 'Auto-assign 8000+'); ?>" pattern="[0-9]*">
                    </div>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-key text-info"></i> <?php echo t('conferences.user_pin_lbl', 'Guest PIN'); ?></label>
                        <input type="text" name="user_pin" id="modal_meeting_user_pin" class="form-control" placeholder="<?php echo t('meetings.ph_optional', 'Optional PIN'); ?>" pattern="[0-9]*">
                    </div>

                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-crown text-warning"></i> <?php echo t('conferences.admin_pin_lbl', 'Host / Moderator PIN'); ?></label>
                        <input type="text" name="host_pin" id="modal_meeting_host_pin" class="form-control" placeholder="<?php echo t('meetings.ph_optional', 'Optional PIN'); ?>" pattern="[0-9]*">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-envelope-open-text text-muted"></i> <?php echo t('meetings.lbl_invites', 'Invite Guests via Email'); ?> <span class="text-muted u-fs-11">(<?php echo t('meetings.invites_hint', 'Comma or newline separated'); ?>)</span></label>
                    <textarea name="invites_emails" id="modal_meeting_invites" class="form-control" rows="2" placeholder="guest1@example.com, client@partner.com"></textarea>
                </div>

                <div class="form-group">
                    <input type="hidden" name="allow_guests" value="0">
                    <label class="u-flex-center u-pointer" style="gap: 8px; font-weight: 500;">
                        <input type="checkbox" name="allow_guests" id="modal_meeting_allow_guests" value="1" checked>
                        <span><?php echo t('meetings.allow_guests_lbl', 'Allow External Guests (Can join via link)'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('meetingModal')", t('common.save'), '', 'fa-calendar-check'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Single Invite -->
<div class="modal-overlay" id="inviteModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-envelope u-primary"></i> <?php echo t('meetings.modal_invite_title', 'Invite Guest'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="UIHelper.closeOverlayModal('inviteModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="add_invite" value="1">
                <input type="hidden" name="meeting_id" id="invite_meeting_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('meetings.lbl_guest_email', 'Guest Email'); ?> *</label>
                    <input type="email" name="email" id="invite_guest_email" class="form-control" required placeholder="guest@example.com">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('meetings.lbl_guest_name', 'Guest Name'); ?></label>
                    <input type="text" name="name" id="invite_guest_name" class="form-control" placeholder="<?php echo t('meetings.ph_guest_name', 'e.g. John Doe'); ?>">
                </div>

                <p class="u-fs-11 text-muted">
                    <i class="fas fa-info-circle"></i> <?php echo t('meetings.invite_note', 'An invitation email with magic join link and .ics calendar attachment will be sent automatically.'); ?>
                </p>

                <?php echo uiModalFooter("UIHelper.closeOverlayModal('inviteModal')", t('meetings.btn_send_invite', 'Send Invite'), '', 'fa-paper-plane'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/meetings.js'); ?>"></script>
