<form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_asterisk_settings" value="1">

    <!-- Settings Single-Line Fixed Tabs -->
    <div class="settings-tabs">
        <button type="button" class="settings-tab-btn active" data-tab="pjsip" onclick="switchSettingsTab('pjsip', this)">
            <i class="fas fa-network-wired"></i> <?php echo t('asterisk_settings.tab_pjsip'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="rtp" onclick="switchSettingsTab('rtp', this)">
            <i class="fas fa-wave-square"></i> <?php echo t('asterisk_settings.tab_rtp'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="t38" onclick="switchSettingsTab('t38', this)">
            <i class="fas fa-fax"></i> <?php echo t('asterisk_settings.tab_t38'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="ring" onclick="switchSettingsTab('ring', this)">
            <i class="fas fa-bell"></i> <?php echo t('asterisk_settings.tab_ring'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="video" onclick="switchSettingsTab('video', this)">
            <i class="fas fa-video"></i> <?php echo t('asterisk_settings.tab_video'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="lang" onclick="switchSettingsTab('lang', this)">
            <i class="fas fa-language"></i> <?php echo t('asterisk_settings.tab_lang'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="amd" onclick="switchSettingsTab('amd', this)">
            <i class="fas fa-robot"></i> <?php echo t('asterisk_settings.tab_amd', 'AMD'); ?>
        </button>
    </div>

    <!-- SECTION 1: PJSIP, NAT & network global settings -->
    <div id="tab_pjsip" class="settings-tab-pane active">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-network-wired u-primary"></i> <?php echo t('asterisk_settings.section1_title'); ?>
            </div>
            <button type="button" class="btn-help" onclick="toggleModuleHelp('astHelpBox')" title="<?php echo t('common.module_guide'); ?>">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>

        <!-- Collapsible Help Box -->
        <div class="module-help-box" id="astHelpBox">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.help_title'); ?></h4>
            <?php echo t('asterisk_settings.help_body'); ?>
        </div>

        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_wss_port'); ?></label>
                <input type="number" name="pjsip_wss_port" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_wss_port']); ?>" required>
                <small class="u-hint"><?php echo t('asterisk_settings.wss_port_help'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_udp_port'); ?></label>
                <input type="number" name="pjsip_udp_port" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_udp_port']); ?>" required>
                <small class="u-hint"><?php echo t('asterisk_settings.udp_port_help'); ?></small>
            </div>
        </div>

        <!-- The public address and the local networks are on Network -> Network settings. -->
        <div class="alert alert-info u-fs-12 u-mt-12"><i class="fas fa-network-wired"></i> <?php echo t('netset.moved_asterisk'); ?>
            <a href="/network-settings"><?php echo t('sidebar.item_network_settings'); ?></a></div>

        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo t('asterisk_settings.field_codecs'); ?></label>
            <div style="display: flex; gap: 16px; margin-top: 6px;">
                <label class="u-check-label-6">
                    <input type="checkbox" name="codecs[]" value="opus" <?php echo in_array('opus', $active_codecs) ? 'checked' : ''; ?>> Opus
                </label>
                <label class="u-check-label-6">
                    <input type="checkbox" name="codecs[]" value="alaw" <?php echo in_array('alaw', $active_codecs) ? 'checked' : ''; ?>> aLaw (PCMA)
                </label>
                <label class="u-check-label-6">
                    <input type="checkbox" name="codecs[]" value="ulaw" <?php echo in_array('ulaw', $active_codecs) ? 'checked' : ''; ?>> uLaw (PCMU)
                </label>
                <label class="u-check-label-6">
                    <input type="checkbox" name="codecs[]" value="g722" <?php echo in_array('g722', $active_codecs) ? 'checked' : ''; ?>> G.722
                </label>
            </div>
            <small class="u-hint"><?php echo t('asterisk_settings.codecs_help'); ?></small>
        </div>


        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo t('asterisk_settings.field_wired_codecs'); ?></label>
            <div style="display: flex; gap: 16px; margin-top: 6px;">
                <label class="u-check-label-6">
                    <input type="checkbox" name="wired_codecs[]" value="alaw" <?php echo in_array('alaw', $active_wired_codecs) ? 'checked' : ''; ?>> aLaw (PCMA)
                </label>
                <label class="u-check-label-6">
                    <input type="checkbox" name="wired_codecs[]" value="ulaw" <?php echo in_array('ulaw', $active_wired_codecs) ? 'checked' : ''; ?>> uLaw (PCMU)
                </label>
                <label class="u-check-label-6">
                    <input type="checkbox" name="wired_codecs[]" value="g729" <?php echo in_array('g729', $active_wired_codecs) ? 'checked' : ''; ?>> G.729
                </label>
                <label class="u-check-label-6">
                    <input type="checkbox" name="wired_codecs[]" value="g722" <?php echo in_array('g722', $active_wired_codecs) ? 'checked' : ''; ?>> G.722
                </label>
            </div>
            <small class="u-hint"><?php echo t('asterisk_settings.wired_codecs_help'); ?></small>
        </div>

        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo t('asterisk_settings.field_user_agent'); ?></label>
            <input type="text" name="pjsip_user_agent" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_user_agent'] ?? 'Asterisk PBX'); ?>" placeholder="Asterisk PBX">
            <small class="u-hint"><?php echo t('asterisk_settings.user_agent_help'); ?></small>
        </div>

        <div class="u-grid-2 u-mt-12">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_direct_media'); ?></label>
                <select name="pjsip_direct_media" class="form-control">
                    <option value="no" <?php echo $s['pjsip_direct_media'] === 'no' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.direct_media_no'); ?></option>
                    <option value="yes" <?php echo $s['pjsip_direct_media'] === 'yes' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.direct_media_yes'); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_rtp_symmetric'); ?></label>
                <select name="pjsip_rtp_symmetric" class="form-control">
                    <option value="yes" <?php echo $s['pjsip_rtp_symmetric'] === 'yes' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.rtp_symmetric_yes'); ?></option>
                    <option value="no" <?php echo $s['pjsip_rtp_symmetric'] === 'no' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.rtp_symmetric_no'); ?></option>
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 12px;">
            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_qualify'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.qualify_help'); ?></span></span>
                </label>
                <input type="number" name="pjsip_qualify_frequency" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_qualify_frequency']); ?>" min="0" max="600">
                <small class="u-hint"><?php echo t('asterisk_settings.qualify_unit'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_internal_timeout'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.internal_timeout_help'); ?></span></span>
                </label>
                <input type="number" name="pjsip_internal_dial_timeout" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_internal_dial_timeout']); ?>" min="5" max="120" required>
                <small class="u-hint"><?php echo t('asterisk_settings.internal_timeout_unit'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_external_timeout'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.external_timeout_help'); ?></span></span>
                </label>
                <input type="number" name="pjsip_external_dial_timeout" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_external_dial_timeout']); ?>" min="5" max="180" required>
                <small class="u-hint"><?php echo t('asterisk_settings.external_timeout_unit'); ?></small>
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION: RTP (media) settings -->
    <div id="tab_rtp" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-wave-square u-primary"></i> <?php echo t('asterisk_settings.rtp_section_title'); ?>
            </div>
        </div>

        <div class="module-help-box" style="display: block;">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.info_title'); ?></h4>
            <?php echo t('asterisk_settings.rtp_help_body'); ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_rtp_start'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.rtp_start_help'); ?></span></span>
                </label>
                <input type="number" name="rtp_start" class="form-control" value="<?php echo htmlspecialchars($s['rtp_start']); ?>" min="1024" max="65534" required>
            </div>

            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_rtp_end'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.rtp_end_help'); ?></span></span>
                </label>
                <input type="number" name="rtp_end" class="form-control" value="<?php echo htmlspecialchars($s['rtp_end']); ?>" min="1025" max="65535" required>
            </div>

            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_rtp_strict'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.rtp_strict_help'); ?></span></span>
                </label>
                <select name="rtp_strict" class="form-control">
                    <option value="yes" <?php echo $s['rtp_strict'] === 'yes' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.rtp_strict_yes'); ?></option>
                    <option value="no" <?php echo $s['rtp_strict'] === 'no' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.rtp_strict_no'); ?></option>
                </select>
            </div>
        </div>

        <p class="u-warning u-fs-12 u-mt-12">
            <i class="fas fa-triangle-exclamation"></i> <?php echo t('asterisk_settings.rtp_restart_warning'); ?>
        </p>
    </div>
    </div>

    <!-- SECTION: T.38 UDPTL (fax media) settings -->
    <div id="tab_t38" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-fax u-primary"></i> <?php echo t('asterisk_settings.udptl_section_title'); ?>
            </div>
        </div>

        <div class="module-help-box" style="display: block;">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.info_title'); ?></h4>
            <?php echo t('asterisk_settings.udptl_help_body'); ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_udptl_start'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.udptl_start_help'); ?></span></span>
                </label>
                <input type="number" name="udptl_start" class="form-control" value="<?php echo htmlspecialchars($s['udptl_start'] ?? '4100'); ?>" min="1024" max="65534" required>
            </div>

            <div class="form-group">
                <label class="form-label form-label-help">
                    <span><?php echo t('asterisk_settings.field_udptl_end'); ?></span>
                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('asterisk_settings.udptl_end_help'); ?></span></span>
                </label>
                <input type="number" name="udptl_end" class="form-control" value="<?php echo htmlspecialchars($s['udptl_end'] ?? '4999'); ?>" min="1025" max="65535" required>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_udptl_checksums'); ?></label>
                <select name="udptl_checksums" class="form-control">
                    <option value="yes" <?php echo ($s['udptl_checksums'] ?? 'yes') === 'yes' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.yes_on'); ?></option>
                    <option value="no" <?php echo ($s['udptl_checksums'] ?? 'yes') === 'no' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.no_off'); ?></option>
                </select>
            </div>
        </div>

        <div class="u-grid-2 u-mt-12">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_udptl_fec_entries'); ?></label>
                <input type="number" name="udptl_fec_entries" class="form-control" value="<?php echo htmlspecialchars($s['udptl_fec_entries'] ?? '3'); ?>" min="0" max="9">
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_udptl_fec_span'); ?></label>
                <input type="number" name="udptl_fec_span" class="form-control" value="<?php echo htmlspecialchars($s['udptl_fec_span'] ?? '3'); ?>" min="0" max="9">
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION: softphone ring & ringback tone -->
    <div id="tab_ring" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-bell u-primary"></i> <?php echo t('asterisk_settings.ring_section_title'); ?>
            </div>
        </div>

        <div class="module-help-box">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.info_title'); ?></h4>
            <?php echo t('asterisk_settings.ring_help'); ?>
        </div>

        <div class="u-grid-2">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_ring_incoming'); ?></label>
                <select name="webrtc_ring_incoming" class="form-control">
                    <option value=""><?php echo t('asterisk_settings.default_ringtone'); ?></option>
                    <?php foreach ($ring_sound_options as $opt): $val = str_replace('custom/', '', $opt['audio_file']); ?>
                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $s['webrtc_ring_incoming'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_ring_outgoing'); ?></label>
                <select name="webrtc_ring_outgoing" class="form-control">
                    <option value=""><?php echo t('asterisk_settings.default_ring1'); ?></option>
                    <?php foreach ($ring_sound_options as $opt): $val = str_replace('custom/', '', $opt['audio_file']); ?>
                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $s['webrtc_ring_outgoing'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if (empty($ring_sound_options)): ?>
            <small style="color: var(--text-muted); display: block; margin-top: 8px;"><?php echo t('asterisk_settings.no_sounds_uploaded'); ?> <a href="/sounds"><?php echo t('asterisk_settings.sounds_page_link'); ?></a> <?php echo t('asterisk_settings.sounds_page_link_suffix'); ?></small>
        <?php endif; ?>
    </div>
    </div>

    <!-- SECTION: video calls -->
    <div id="tab_video" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-video u-primary"></i> <?php echo t('asterisk_settings.video_section_title'); ?>
            </div>
        </div>

        <div class="module-help-box">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.info_title'); ?></h4>
            <?php echo t('asterisk_settings.video_calls_help'); ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_video_enabled'); ?></label>
                <select name="video_calls_enabled" class="form-control">
                    <option value="0" <?php echo ($s['video_calls_enabled'] ?? '0') !== '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.video_disabled'); ?></option>
                    <option value="1" <?php echo ($s['video_calls_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.video_enabled'); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_video_codec'); ?></label>
                <select name="video_codec" class="form-control">
                    <option value="vp8" <?php echo ($s['video_codec'] ?? 'vp8') !== 'h264' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.video_codec_vp8'); ?></option>
                    <option value="h264" <?php echo ($s['video_codec'] ?? 'vp8') === 'h264' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.video_codec_h264'); ?></option>
                </select>
                <small class="u-hint"><?php echo t('asterisk_settings.video_codec_help'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_video_resolution'); ?></label>
                <select name="video_max_resolution" class="form-control">
                    <?php foreach (['640x360', '960x540', '1280x720', '1920x1080'] as $res): ?>
                        <option value="<?php echo $res; ?>" <?php echo ($s['video_max_resolution'] ?? '1280x720') === $res ? 'selected' : ''; ?>><?php echo $res; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_video_framerate'); ?></label>
                <select name="video_max_framerate" class="form-control">
                    <?php foreach ([15, 24, 30] as $fps): ?>
                        <option value="<?php echo $fps; ?>" <?php echo intval($s['video_max_framerate'] ?? 24) === $fps ? 'selected' : ''; ?>><?php echo $fps; ?> fps</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION: language settings -->
    <div id="tab_lang" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-language u-primary"></i> <?php echo t('asterisk_settings.lang_section_title'); ?>
            </div>
        </div>

        <div class="module-help-box">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.info_title'); ?></h4>
            <?php echo t('asterisk_settings.lang_help'); ?>
        </div>

        <div class="form-group" style="max-width: 320px;">
            <label class="form-label"><?php echo t('asterisk_settings.field_default_lang'); ?></label>
            <select name="system_default_language" class="form-control">
                <?php foreach (getAvailableLanguages() as $lang_code): ?>
                    <option value="<?php echo htmlspecialchars($lang_code); ?>" <?php echo $s['system_default_language'] === $lang_code ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(LANGUAGE_LABELS[$lang_code] ?? $lang_code); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="u-hint"><?php echo t('asterisk_settings.lang_packs_help'); ?></small>
        </div>
    </div>
    </div>

    <!-- SECTION: AMD (Answering Machine Detection) settings -->
    <div id="tab_amd" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-robot u-primary"></i> <?php echo t('asterisk_settings.amd_section_title', 'Answering Machine Detection (AMD)'); ?>
            </div>
        </div>

        <div class="module-help-box" style="display: block;">
            <h4><i class="fas fa-info-circle"></i> <?php echo t('asterisk_settings.info_title'); ?></h4>
            <p><?php echo t('asterisk_settings.amd_help_body', 'Global timing and word-detection parameters used by Asterisk\'s <code>AMD()</code> application during automated outbound campaigns (Auto Dialer). Times are in milliseconds (ms).'); ?></p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_initial_silence', 'Initial Silence (ms)'); ?></label>
                <input type="number" name="amd_initial_silence" class="form-control" value="<?php echo htmlspecialchars($s['amd_initial_silence'] ?? '2500'); ?>" min="250" max="15000" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_initial_silence_help', 'Max silence before greeting; if exceeded → MACHINE (default: 2500 ms)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_greeting', 'Max Greeting Length (ms)'); ?></label>
                <input type="number" name="amd_greeting" class="form-control" value="<?php echo htmlspecialchars($s['amd_greeting'] ?? '1500'); ?>" min="250" max="15000" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_greeting_help', 'Max length of initial greeting; if exceeded → MACHINE (default: 1500 ms)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_after_greeting_silence', 'After-Greeting Silence (ms)'); ?></label>
                <input type="number" name="amd_after_greeting_silence" class="form-control" value="<?php echo htmlspecialchars($s['amd_after_greeting_silence'] ?? '800'); ?>" min="100" max="10000" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_after_greeting_silence_help', 'Silence after greeting required to classify as HUMAN (default: 800 ms)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_total_analysis_time', 'Total Analysis Time (ms)'); ?></label>
                <input type="number" name="amd_total_analysis_time" class="form-control" value="<?php echo htmlspecialchars($s['amd_total_analysis_time'] ?? '5000'); ?>" min="1000" max="30000" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_total_analysis_time_help', 'Max total decision time before returning NOTSURE (default: 5000 ms)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_min_word_length', 'Min Word Length (ms)'); ?></label>
                <input type="number" name="amd_min_word_length" class="form-control" value="<?php echo htmlspecialchars($s['amd_min_word_length'] ?? '100'); ?>" min="20" max="2000" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_min_word_length_help', 'Minimum continuous voice duration counted as a word (default: 100 ms)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_between_words_silence', 'Between-Words Silence (ms)'); ?></label>
                <input type="number" name="amd_between_words_silence" class="form-control" value="<?php echo htmlspecialchars($s['amd_between_words_silence'] ?? '50'); ?>" min="10" max="2000" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_between_words_silence_help', 'Minimum silence gap separating two words (default: 50 ms)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_maximum_number_of_words', 'Maximum Number of Words'); ?></label>
                <input type="number" name="amd_maximum_number_of_words" class="form-control" value="<?php echo htmlspecialchars($s['amd_maximum_number_of_words'] ?? '2'); ?>" min="1" max="20" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_maximum_number_of_words_help', 'If greeting exceeds this word count → MACHINE (default: 2)'); ?></small>
            </div>

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_amd_silence_threshold', 'Silence Threshold (1–32767)'); ?></label>
                <input type="number" name="amd_silence_threshold" class="form-control" value="<?php echo htmlspecialchars($s['amd_silence_threshold'] ?? '256'); ?>" min="1" max="32767" required>
                <small class="u-hint"><?php echo t('asterisk_settings.amd_silence_threshold_help', 'Audio noise floor below which samples count as silence (default: 256)'); ?></small>
            </div>
        </div>
    </div>
    </div>

    <?php if (hasModulePermission('asterisk_settings', 'edit')): ?>
        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px; font-size: 15px; font-weight: 700;" title="<?php echo t('asterisk_settings.save_all_tooltip'); ?>">
            <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
        </button>
    <?php endif; ?>
</form>
