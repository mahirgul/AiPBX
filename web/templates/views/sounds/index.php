<!-- One card: the page title, the tabs and the panes (like Time Plan). -->
<div class="card tabbed-page">
    <?php echo uiPageHeader('fa-volume-up', t('sounds.title')); ?>
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
            <div class="card-title"><i class="fas fa-volume-up u-primary"></i> <?php echo t('sounds.announcements_title'); ?></div>
            <div class="u-flex-gap">
                <?php echo uiHelpButton('soundHelpBox'); ?>
                <?php if (hasModulePermission('sounds', 'edit')): ?>
                    <button class="btn btn-primary btn-sm" onclick="openUploadSoundModal()" title="<?php echo t('sounds.new_tooltip'); ?>">
                        <i class="fas fa-file-audio"></i> <?php echo t('sounds.new_tooltip'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>

                <?php echo uiHelpBoxOpen('soundHelpBox', t('sounds.help_title')); ?>
            <?php echo t('sounds.help_body'); ?><br>
            - <strong><?php echo t('sounds.help_wavesurfer'); ?></strong><br>
            - <strong><?php echo t('sounds.help_autoconvert'); ?></strong>
<?php echo uiHelpBoxClose(); ?>

        <?php echo uiTableOpen([
            ['label' => '#', 'class' => 'col-hide-mobile u-w-45'],
            t('sounds.col_announcement'),
            ['label' => t('internal_number.col'), 'class' => 'col-hide-mobile'],
            ['label' => t('sounds.col_audio_file'), 'class' => 'col-hide-mobile'],
            ['label' => t('sounds.col_size_date'), 'class' => 'col-hide-mobile'],
            t('sounds.col_status'),
            ['label' => '', 'class' => 'u-text-right'],
        ]); ?>
                    <?php if (empty($announcements)): ?>
                        <?php echo uiTableEmptyRow(6, t('sounds.empty'), 'fa-volume-up'); ?>
                    <?php else: ?>
                        <?php foreach ($announcements as $anc):
                            $file_name = str_replace('custom/', '', $anc['audio_file']);
                        ?>
                            <tr>
                                <td class="col-hide-mobile u-muted u-fs-12">#<?php echo $anc['id']; ?></td>
                                <td class="u-fw-700 u-primary u-pointer" onclick="playAnnouncement(<?php echo jsArg($file_name); ?>, <?php echo jsArg($anc['title']); ?>)">
                                    <i class="fas fa-volume-up u-primary u-mr-6"></i> <?php echo htmlspecialchars($anc['title']); ?>
                                </td>
                                <td class="col-hide-mobile">
                                    <?php echo !empty($anc['internal_number'])
                                        ? '<span class="badge badge-info">' . htmlspecialchars($anc['internal_number']) . '</span>'
                                        : t('internal_number.none'); ?>
                                </td>
                                <td>
                                    <span class="sound-badge u-pointer" onclick="playAnnouncement(<?php echo jsArg($file_name); ?>, <?php echo jsArg($anc['title']); ?>)">
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
                                    <button class="btn btn-icon btn-secondary btn-sm" data-sound="<?php echo htmlspecialchars($file_name); ?>" onclick="playAnnouncement(<?php echo jsArg($file_name); ?>, <?php echo jsArg($anc['title']); ?>)" title="<?php echo t('sounds.listen_tooltip'); ?>">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    <a href="/api/sound_play.php?file=<?php echo htmlspecialchars($file_name); ?>&download=1" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('sounds.download_tooltip'); ?>">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php echo uiEditButton($anc, 'openEditSoundModal'); ?>
                                    <?php echo uiDeleteForm($anc['id'], 'anc_id', 'delete_announcement', sprintf(t('sounds.delete_announcement_confirm'), $anc['title']), t('sounds.delete'), 'fa-trash-alt', [], 'display:inline-block; margin-left: 4px;'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php echo uiTableClose(); ?>
    </div>
</div>

<div class="tab-pane" id="tab-pane-moh">
    <div class="card u-mb-24">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-music u-primary"></i> <?php echo t('sounds.moh_section_title'); ?>
            </div>
            <div class="u-flex-gap">
                <?php echo uiHelpButton('mohHelpBox'); ?>
                <?php if (hasModulePermission('sounds', 'edit')): ?>
                    <button class="btn btn-icon btn-primary btn-sm" onclick="openMohModal()" title="<?php echo t('sounds.new_moh_tooltip'); ?>">
                        <i class="fas fa-plus-circle"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>

                <?php echo uiHelpBoxOpen('mohHelpBox', t('sounds.moh_help_title')); ?>
            <?php echo t('sounds.moh_help_body'); ?><br>
            <?php echo t('sounds.moh_help_sync'); ?>
<?php echo uiHelpBoxClose(); ?>

        <?php echo uiTableOpen([
            ['label' => '#', 'class' => 'col-hide-mobile u-w-45'],
            t('sounds.col_moh_class'),
            ['label' => t('sounds.col_directory'), 'class' => 'col-hide-mobile'],
            ['label' => t('sounds.col_mode'), 'class' => 'col-hide-mobile'],
            ['label' => t('sounds.col_sort'), 'class' => 'col-hide-mobile'],
            t('sounds.col_files'),
            ['label' => '', 'class' => 'u-text-right'],
        ]); ?>
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
                                <button class="btn btn-icon btn-secondary btn-sm" onclick="openMohUploadModal(<?php echo jsArg($mc['name']); ?>)" title="<?php echo t('sounds.upload_music_tooltip'); ?>">
                                    <i class="fas fa-upload"></i>
                                </button>
                                <?php if ($mc['name'] !== 'default'): ?>
                                    <?php echo uiDeleteForm($mc['id'], 'moh_id', 'delete_moh_class', sprintf(t('sounds.delete_moh_confirm'), $mc['name']), t('sounds.delete'), 'fa-trash-alt', [], 'display:inline; margin-left: 4px;'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php echo uiTableClose(); ?>
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
                <?php echo uiHelpButton('soundPackHelpBox'); ?>
            </div>
        </div>

        <?php echo uiHelpBoxOpen('soundPackHelpBox', t('sound_packs.help_title')); ?>
            <?php echo t('sound_packs.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

        <?php if (hasModulePermission('sounds', 'edit')): ?>
            <div class="u-flex-gap u-wrap u-ai-flex-end u-mb-16">
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

        <div id="sp-state" class="u-fw-600 u-mb-10"></div>

        <?php echo uiTableOpen([
            t('sound_packs.col_pack'),
            t('sound_packs.col_lang'),
            t('sound_packs.field_format'),
            ['label' => t('sound_packs.col_version'), 'class' => 'col-hide-mobile'],
            ['label' => t('sound_packs.col_files'), 'class' => 'col-hide-mobile'],
            ['label' => t('sound_packs.col_installed_at'), 'class' => 'col-hide-mobile'],
            ['label' => '', 'class' => 'u-text-right'],
        ], ['body_id' => 'sp-installed']); ?><?php echo uiTableClose(); ?>

        <details class="u-mt-16">
            <summary class="u-strong u-pointer"><?php echo t('sound_packs.log'); ?></summary>
            <pre class="u-white-space-pre-wrap u-bg-0f172a u-c-e2e8f0 u-r-8 u-p-12 u-fs-11 u-maxh-280 u-of-auto u-mt-8" id="sp-log"></pre>
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
</div><!-- /tabbed-page -->

<!-- Upload / Edit Announcement Modal -->
<div class="modal-overlay" id="uploadSoundModal">
    <div class="modal-card u-maxw-500">
        <div class="modal-header">
            <h3 class="u-title" id="soundModalTitle"><i class="fas fa-file-audio u-primary"></i> <?php echo t('sounds.modal_upload_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeUploadSoundModal()"><i class="fas fa-times"></i></button>
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
                           placeholder="<?php echo t('common.eg'); ?> 1010">
                    <small class="u-hint"><?php echo t('internal_number.help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" id="audio_file_label"><?php echo t('sounds.field_audio_file'); ?></label>
                    <input type="file" name="audio_file" id="modal_audio_file" class="form-control" accept=".wav,.gsm,.alaw,.ulaw,.mp3" required>
                    <small class="u-muted u-fs-11" id="audio_file_help"><?php echo t('sounds.field_audio_file_help'); ?></small>
                </div>

                <div class="u-d-flex u-gap-12 u-jc-flex-end u-mt-24">
                    <button type="button" class="btn btn-icon btn-secondary" onclick="closeUploadSoundModal()" title="<?php echo t('sounds.cancel_tooltip'); ?>"><i class="fas fa-times"></i></button>
                    <button type="submit" class="btn btn-icon btn-primary" id="modal_sound_submit" title="<?php echo t('sounds.save_tooltip'); ?>"><i class="fas fa-save"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create MOH Class Modal -->
<div class="modal-overlay" id="mohModal">
    <div class="modal-card u-maxw-500">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-plus-circle u-primary"></i> <?php echo t('sounds.modal_moh_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeMohModal()"><i class="fas fa-times"></i></button>
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

                <div class="u-d-flex u-gap-12 u-jc-flex-end u-mt-24">
                    <button type="button" class="btn btn-icon btn-secondary" onclick="closeMohModal()" title="<?php echo t('sounds.cancel_tooltip'); ?>"><i class="fas fa-times"></i></button>
                    <button type="submit" class="btn btn-icon btn-primary" title="<?php echo t('sounds.save_tooltip'); ?>"><i class="fas fa-save"></i></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- WaveSurfer Audio Player Modal -->
<div class="modal-overlay" id="audioPlayerModal">
    <div class="modal-card u-maxw-620">
        <div class="modal-header">
            <div class="u-d-flex u-ai-center u-gap-10">
                <div class="u-w-36 u-h-36 u-r-12 u-bg-rgba-79-70-229-0-12 u-primary u-d-flex u-ai-center u-jc-center u-fs-18">
                    <i class="fas fa-file-audio"></i>
                </div>
                <div>
                    <h3 class="u-title u-m-0" id="playerModalTitle"><?php echo t('sounds.player_title'); ?></h3>
                    <small class="u-muted u-fs-11" id="playerModalFile">custom/welcome.wav</small>
                </div>
            </div>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeAudioPlayerModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body u-p-20">
            <!-- Waveform visualizer container -->
            <div class="u-bg-rgba-0-0-0-0-04 u-p-16 u-r-12 u-bd-1-solid-border-color u-mb-16 u-relative">
                <div class="u-w-100 u-minh-90" id="waveform"></div>
                <div class="u-pos-absolute u-inset-0 u-ai-center u-jc-center u-bg-rgba-255-255-255-0-85 u-r-12 u-fs-13 u-primary u-gap-8 u-fw-600 u-z-index-5" id="waveformLoading" style="display: flex;">
                    <i class="fas fa-spinner fa-spin"></i> <?php echo t('sounds.waveform_loading'); ?>
                </div>
            </div>

            <!-- Controls bar -->
            <div class="u-d-flex u-ai-center u-jc-space-between u-gap-12 u-wrap">
                <div class="u-flex-center">
                    <button class="btn btn-primary u-minw-110" id="wavePlayBtn" onclick="toggleWavePlay()">
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
                <div class="u-mono u-fs-14 u-fw-700 u-primary u-bg-rgba-79-70-229-0-1 u-p-8-16 u-r-8">
                    <span id="waveCurrentTime">00:00</span> / <span id="waveTotalDuration">00:00</span>
                </div>

                <!-- Volume control -->
                <div class="u-flex-center">
                    <button class="btn btn-icon btn-secondary btn-sm" id="muteBtn" onclick="toggleWaveMute()">
                        <i class="fas fa-volume-up" id="muteIcon"></i>
                    </button>
                    <input class="u-w-80 u-pointer" type="range" id="volumeSlider" min="0" max="1" step="0.05" value="1" oninput="setWaveVolume(this.value)">
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
    <div class="modal-card u-maxw-480">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-music u-primary"></i> <?php echo t('sounds.moh_upload_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeMohUploadModal()"><i class="fas fa-times"></i></button>
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

                <div class="u-ta-right u-mt-16">
                    <button type="submit" class="btn btn-icon btn-primary" title="<?php echo htmlspecialchars(t('sounds.moh_upload_submit')); ?>">
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
