<!-- 3-Tab Navigation Bar -->
<div class="sub-tab-nav">
    <button type="button" class="sub-tab-btn active" id="tab-btn-announcements" onclick="switchSoundTab('announcements')">
        <i class="fas fa-volume-up"></i> <?php echo t('sounds.tab_announcements'); ?>
    </button>
    <button type="button" class="sub-tab-btn" id="tab-btn-moh" onclick="switchSoundTab('moh')">
        <i class="fas fa-music"></i> <?php echo t('sounds.tab_moh'); ?>
    </button>
    <button type="button" class="sub-tab-btn" id="tab-btn-packs" onclick="switchSoundTab('packs')">
        <i class="fas fa-box-archive"></i> <?php echo t('sound_packs.tab'); ?>
    </button>
</div>

<!-- TAB 1: Sound announcements -->
<div class="tab-pane active" id="tab-pane-announcements">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-volume-up u-primary"></i> <?php echo t('sounds.announcements_title'); ?>
            </div>
            <div class="u-flex-gap">
                <button type="button" class="btn-help" onclick="toggleModuleHelp('soundHelpBox')" title="Modül Rehberi">
                    <i class="fas fa-question-circle"></i>
                </button>
                <?php if (hasModulePermission('sounds', 'edit')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openUploadSoundModal()" title="<?php echo t('sounds.new_tooltip'); ?>">
                        <i class="fas fa-file-audio"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Collapsible Help Box -->
        <div class="module-help-box" id="soundHelpBox">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('sounds.help_title'); ?></h4>
            <?php echo t('sounds.help_body'); ?><br>
            - <strong><?php echo t('sounds.help_wavesurfer'); ?></strong><br>
            - <strong><?php echo t('sounds.help_autoconvert'); ?></strong>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="col-hide-mobile u-w-45">#</th>
                        <th><?php echo t('sounds.col_announcement'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('internal_number.col'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sounds.col_audio_file'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sounds.col_size_date'); ?></th>
                        <th><?php echo t('sounds.col_status'); ?></th>
                        <th class="u-text-right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($announcements)): ?>
                        <?php echo uiTableEmptyRow(6, t('sounds.empty'), 'fa-volume-up'); ?>
                    <?php else: ?>
                        <?php foreach ($announcements as $anc):
                            $file_name = str_replace('custom/', '', $anc['audio_file']);
                        ?>
                            <tr>
                                <td class="col-hide-mobile u-muted u-fs-12">#<?php echo $anc['id']; ?></td>
                                <td style="font-weight: 700; color: var(--primary); cursor: pointer;" onclick="playAnnouncement('<?php echo htmlspecialchars($file_name); ?>', '<?php echo htmlspecialchars($anc['title'], ENT_QUOTES); ?>')">
                                    <i class="fas fa-volume-up u-primary u-mr-6"></i> <?php echo htmlspecialchars($anc['title']); ?>
                                </td>
                                <td class="col-hide-mobile">
                                    <?php echo !empty($anc['internal_number'])
                                        ? '<span class="badge badge-info">' . htmlspecialchars($anc['internal_number']) . '</span>'
                                        : t('internal_number.none'); ?>
                                </td>
                                <td>
                                    <span class="sound-badge" style="cursor: pointer;" onclick="playAnnouncement('<?php echo htmlspecialchars($file_name); ?>', '<?php echo htmlspecialchars($anc['title'], ENT_QUOTES); ?>')">
                                        <code><?php echo htmlspecialchars($anc['audio_file']); ?></code>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                        $full_p = $custom_dir . '/' . $file_name . '.wav';
                                        if (file_exists($full_p)) {
                                            echo round(filesize($full_p) / 1024, 1) . ' KB | ' . date('d.m.Y H:i', filemtime($full_p));
                                        } else {
                                            echo '<span class="u-muted">' . t('sounds.available') . '</span>';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php echo uiStatusToggleForm($anc['id'], $anc['is_active'] ?? 1, 'anc_id'); ?>
                                </td>
                                <td class="u-text-right u-nowrap">
                                    <button class="btn btn-secondary btn-sm btn-play-sound" data-sound="<?php echo htmlspecialchars($file_name); ?>" onclick="playAnnouncement('<?php echo htmlspecialchars($file_name); ?>', '<?php echo htmlspecialchars($anc['title'], ENT_QUOTES); ?>')" title="<?php echo t('sounds.listen_tooltip'); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    <a href="/api/sound_play.php?file=<?php echo htmlspecialchars($file_name); ?>&download=1" class="btn btn-secondary btn-sm" title="<?php echo t('sounds.download_tooltip'); ?>">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php echo uiEditButton($anc, 'openEditSoundModal'); ?>
                                    <?php echo uiDeleteForm($anc['id'], 'anc_id', 'delete_announcement', sprintf(t('sounds.delete_announcement_confirm'), $anc['title']), t('sounds.delete'), 'fa-trash-alt', [], 'display:inline-block; margin-left: 4px;'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tab-pane" id="tab-pane-moh">
    <div class="card u-mb-24">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-music u-primary"></i> <?php echo t('sounds.moh_section_title'); ?>
            </div>
            <div class="u-flex-gap">
                <button type="button" class="btn-help" onclick="toggleModuleHelp('mohHelpBox')" title="Modül Rehberi">
                    <i class="fas fa-question-circle"></i>
                </button>
                <?php if (hasModulePermission('sounds', 'edit')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openMohModal()" title="<?php echo t('sounds.new_moh_tooltip'); ?>">
                        <i class="fas fa-plus-circle"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Collapsible Help Box -->
        <div class="module-help-box" id="mohHelpBox">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('sounds.moh_help_title'); ?></h4>
            <?php echo t('sounds.moh_help_body'); ?><br>
            <?php echo t('sounds.moh_help_sync'); ?>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="col-hide-mobile u-w-45">#</th>
                        <th><?php echo t('sounds.col_moh_class'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sounds.col_directory'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sounds.col_mode'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sounds.col_sort'); ?></th>
                        <th><?php echo t('sounds.col_files'); ?></th>
                        <th class="u-text-right"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($moh_classes as $mc): ?>
                        <tr>
                            <td class="col-hide-mobile u-muted u-fs-12">#<?php echo $mc['id']; ?></td>
                            <td class="u-fw-700 u-primary">
                                <i class="fas fa-compact-disc"></i> <?php echo htmlspecialchars($mc['name']); ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($mc['directory']); ?></code></td>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($mc['mode']); ?></span></td>
                            <td><?php echo htmlspecialchars($mc['sort']); ?></td>
                            <td>
                                <?php
                                    $m_dir = $mc['directory'];
                                    $files = is_dir($m_dir) ? glob($m_dir . '/*.{wav,mp3,gsm,alaw,ulaw}', GLOB_BRACE) : [];
                                    echo count($files) . ' ' . t('sounds.track_count_suffix');
                                ?>
                            </td>
                            <td class="u-text-right u-nowrap">
                                <button class="btn btn-secondary btn-sm" onclick="openMohUploadModal('<?php echo htmlspecialchars($mc['name'], ENT_QUOTES); ?>')" title="<?php echo t('sounds.upload_music_tooltip'); ?>">
                                    <i class="fas fa-upload"></i>
                                </button>
                                <?php if ($mc['name'] !== 'default'): ?>
                                    <?php echo uiDeleteForm($mc['id'], 'moh_id', 'delete_moh_class', sprintf(t('sounds.delete_moh_confirm'), $mc['name']), t('sounds.delete'), 'fa-trash-alt', [], 'display:inline; margin-left: 4px;'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 3: Asterisk sound packs (downloads.asterisk.org) -->
<div class="tab-pane" id="tab-pane-packs">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-box-archive u-primary"></i> <?php echo t('sound_packs.title'); ?>
            </div>
            <div class="u-flex-gap">
                <button type="button" class="btn-help" onclick="toggleModuleHelp('soundPackHelpBox')" title="Modül Rehberi">
                    <i class="fas fa-question-circle"></i>
                </button>
            </div>
        </div>

        <div class="module-help-box" id="soundPackHelpBox">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('sound_packs.help_title'); ?></h4>
            <?php echo t('sound_packs.help_body'); ?>
        </div>

        <?php if (hasModulePermission('sounds', 'edit')): ?>
            <div class="u-flex-gap" style="flex-wrap: wrap; align-items: flex-end; margin-bottom: 16px;">
                <div class="form-group u-m-0">
                    <label class="form-label" for="sp-kind"><?php echo t('sound_packs.field_kind'); ?></label>
                    <select id="sp-kind" class="form-control" onchange="spFillLanguages()">
                        <option value="core"><?php echo t('sound_packs.kind_core'); ?></option>
                        <option value="extra"><?php echo t('sound_packs.kind_extra'); ?></option>
                        <option value="moh"><?php echo t('sound_packs.kind_moh'); ?></option>
                    </select>
                </div>
                <div class="form-group u-m-0">
                    <label class="form-label" for="sp-lang"><?php echo t('sound_packs.field_lang'); ?></label>
                    <select id="sp-lang" class="form-control"></select>
                </div>
                <div class="form-group u-m-0">
                    <label class="form-label" for="sp-format"><?php echo t('sound_packs.field_format'); ?></label>
                    <select id="sp-format" class="form-control">
                        <?php foreach ($sound_packs['formats'] as $fmt): ?>
                            <option value="<?php echo $fmt; ?>"><?php echo $fmt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="sp-install-btn" onclick="spInstall()">
                    <i class="fas fa-download"></i> <?php echo t('sound_packs.btn_install'); ?>
                </button>
            </div>
        <?php endif; ?>

        <div id="sp-state" class="u-fw-600" style="margin-bottom: 10px;"></div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?php echo t('sound_packs.col_pack'); ?></th>
                        <th><?php echo t('sound_packs.col_lang'); ?></th>
                        <th><?php echo t('sound_packs.field_format'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sound_packs.col_version'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sound_packs.col_files'); ?></th>
                        <th class="col-hide-mobile"><?php echo t('sound_packs.col_installed_at'); ?></th>
                        <th class="u-text-right"></th>
                    </tr>
                </thead>
                <tbody id="sp-installed"></tbody>
            </table>
        </div>

        <details style="margin-top: 16px;">
            <summary class="u-strong" style="cursor: pointer;"><?php echo t('sound_packs.log'); ?></summary>
            <pre id="sp-log" style="white-space: pre-wrap; background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 12px; font-size: 11px; max-height: 280px; overflow: auto; margin-top: 8px;"></pre>
        </details>
    </div>
</div>

<script>
window.SOUND_PACKS_PAGE = <?php echo json_encode($sound_packs + [
    'can_edit' => hasModulePermission('sounds', 'edit'),
    'text' => [
        'kind_core' => t('sound_packs.kind_core'),
        'kind_extra' => t('sound_packs.kind_extra'),
        'kind_moh' => t('sound_packs.kind_moh'),
        'empty' => t('sound_packs.empty'),
        'verified' => t('sound_packs.verified'),
        'unverified' => t('sound_packs.unverified'),
        'remove' => t('sound_packs.btn_remove'),
        'installed' => t('sound_packs.already_installed'),
        'confirm_install' => t('sound_packs.confirm_install'),
        'confirm_remove' => t('sound_packs.confirm_remove'),
        'running' => t('sound_packs.state_running'),
        'done' => t('sound_packs.state_done'),
        'failed' => t('sound_packs.state_failed'),
        'request_failed' => t('sound_packs.start_failed'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>

<!-- Upload Announcement Modal -->
<!-- Upload / Edit Announcement Modal -->
<div class="modal-overlay" id="uploadSoundModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="u-title" id="soundModalTitle"><i class="fas fa-file-audio u-primary"></i> <?php echo t('sounds.modal_upload_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="closeUploadSoundModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" enctype="multipart/form-data" id="soundModalForm">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="upload_sound" id="modal_action_upload" value="1">
                <input type="hidden" name="save_announcement" id="modal_action_save" value="0" disabled>
                <input type="hidden" name="anc_id" id="modal_anc_id" value="0">

                <div class="form-group" id="sound_name_group">
                    <label class="form-label"><?php echo t('sounds.field_sound_name'); ?></label>
                    <input type="text" name="sound_name" id="modal_sound_name" class="form-control" placeholder="<?php echo t('sounds.field_sound_name_placeholder'); ?>" required>
                    <small class="u-muted u-fs-11"><?php echo t('sounds.field_sound_name_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('sounds.field_title'); ?></label>
                    <input type="text" name="title" id="modal_title" class="form-control" placeholder="<?php echo t('sounds.field_title_placeholder'); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('internal_number.field'); ?></label>
                    <input type="text" name="internal_number" id="modal_internal_number"
                           class="form-control" inputmode="numeric" pattern="[0-9]{2,6}" maxlength="6"
                           placeholder="ör: 1010">
                    <small class="u-hint"><?php echo t('internal_number.help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" id="audio_file_label"><?php echo t('sounds.field_audio_file'); ?></label>
                    <input type="file" name="audio_file" id="modal_audio_file" class="form-control" accept=".wav,.gsm,.alaw,.ulaw,.mp3" required>
                    <small class="u-muted u-fs-11" id="audio_file_help"><?php echo t('sounds.field_audio_file_help'); ?></small>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn btn-secondary" onclick="closeUploadSoundModal()" title="<?php echo t('sounds.cancel_tooltip'); ?>"><i class="fas fa-times"></i></button>
                    <button type="submit" class="btn btn-primary" id="modal_sound_submit" title="<?php echo t('sounds.save_tooltip'); ?>"><i class="fas fa-save"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create MOH Class Modal -->
<div class="modal-overlay" id="mohModal">
    <div class="modal-card" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-plus-circle u-primary"></i> <?php echo t('sounds.modal_moh_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="closeMohModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_moh_class" value="1">

                <div class="form-group">
                    <label class="form-label"><?php echo t('sounds.field_class_name'); ?></label>
                    <input type="text" name="class_name" class="form-control" placeholder="<?php echo t('sounds.field_class_name_placeholder'); ?>" required>
                    <small class="u-hint"><?php echo t('sounds.field_class_name_help'); ?></small>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn btn-secondary" onclick="closeMohModal()" title="<?php echo t('sounds.cancel_tooltip'); ?>"><i class="fas fa-times"></i></button>
                    <button type="submit" class="btn btn-primary" title="<?php echo t('sounds.save_tooltip'); ?>"><i class="fas fa-save"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- WaveSurfer Audio Player Modal -->
<div class="modal-overlay" id="audioPlayerModal">
    <div class="modal-card" style="max-width: 620px;">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(79, 70, 229, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fas fa-file-audio"></i>
                </div>
                <div>
                    <h3 class="u-title u-m-0" id="playerModalTitle"><?php echo t('sounds.player_title'); ?></h3>
                    <small class="u-muted u-fs-11" id="playerModalFile">custom/welcome.wav</small>
                </div>
            </div>
            <button class="btn btn-secondary u-btn-pad" onclick="closeAudioPlayerModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body u-p-20">
            <!-- Waveform visualizer container -->
            <div style="background: rgba(0, 0, 0, 0.04); padding: 16px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 16px; position: relative;">
                <div id="waveform" style="width: 100%; min-height: 90px;"></div>
                <div id="waveformLoading" style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.85); border-radius: 12px; font-size: 13px; color: var(--primary); gap: 8px; font-weight: 600; z-index: 5;">
                    <i class="fas fa-spinner fa-spin"></i> <?php echo t('sounds.waveform_loading'); ?>
                </div>
            </div>

            <!-- Controls bar -->
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div class="u-flex-center">
                    <button class="btn btn-primary" id="wavePlayBtn" onclick="toggleWavePlay()" style="min-width: 110px;">
                        <i class="fas fa-play"></i> <?php echo t('sounds.play'); ?>
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="waveSkip(-5)" title="<?php echo t('sounds.back5s_tooltip'); ?>">
                        <i class="fas fa-undo"></i> -5s
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="waveSkip(5)" title="<?php echo t('sounds.forward5s_tooltip'); ?>">
                        <i class="fas fa-redo"></i> +5s
                    </button>
                    <a id="waveDownloadBtn" href="#" class="btn btn-secondary btn-sm" title="<?php echo t('sounds.download_tooltip'); ?>">
                        <i class="fas fa-download"></i> <?php echo t('sounds.download'); ?>
                    </a>
                </div>

                <!-- Time display -->
                <div style="font-family: monospace; font-size: 14px; font-weight: 700; color: var(--primary); background: rgba(79, 70, 229, 0.1); padding: 6px 14px; border-radius: 8px;">
                    <span id="waveCurrentTime">00:00</span> / <span id="waveTotalDuration">00:00</span>
                </div>

                <!-- Volume control -->
                <div class="u-flex-center">
                    <button class="btn btn-secondary btn-sm" id="muteBtn" onclick="toggleWaveMute()" style="padding: 6px 10px;">
                        <i class="fas fa-volume-up" id="muteIcon"></i>
                    </button>
                    <input type="range" id="volumeSlider" min="0" max="1" step="0.05" value="1" style="width: 80px; cursor: pointer;" oninput="setWaveVolume(this.value)">
                </div>
            </div>
        </div>
    </div>
</div>

<!--
    MOH music upload modal.
    It is a separate modal because SoundService::uploadMOHFile() expects the
    file in the 'moh_audio' field and the class in 'moh_class'; the shared
    announcement modal works with 'audio_file' + required name/title fields.
    The upload button on the MOH row used to write to an element that did not
    exist (upload_moh_class_select), throwing a JS error, so the modal never
    opened; the upload_moh_file backend was dead because of it (2026-09-01).
-->
<div class="modal-overlay" id="mohUploadModal">
    <div class="modal-card" style="max-width: 480px;">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-music u-primary"></i> <?php echo t('sounds.moh_upload_title'); ?></h3>
            <button class="btn btn-secondary u-btn-pad" onclick="closeMohUploadModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="upload_moh_file" value="1">

                <div class="form-group">
                    <label class="form-label"><?php echo t('sounds.moh_upload_class'); ?></label>
                    <select name="moh_class" id="upload_moh_class_select" class="form-control" required>
                        <?php foreach ($moh_classes as $mc_u): ?>
                            <option value="<?php echo htmlspecialchars($mc_u['name']); ?>"><?php echo htmlspecialchars($mc_u['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group u-mt-12">
                    <label class="form-label"><?php echo t('sounds.moh_upload_file'); ?></label>
                    <input type="file" name="moh_audio" id="modal_moh_audio" class="form-control" accept=".wav,.gsm,.alaw,.ulaw,.mp3" required>
                    <small class="u-muted u-fs-11"><?php echo t('sounds.moh_upload_file_help'); ?></small>
                </div>

                <div style="text-align: right; margin-top: 16px;">
                    <button type="submit" class="btn btn-primary" title="<?php echo htmlspecialchars(t('sounds.moh_upload_submit')); ?>">
                        <i class="fas fa-upload"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/wavesurfer.min.js"></script>
<script src="<?php echo asset('/assets/js/sounds.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/sound_packs.js'); ?>"></script>
