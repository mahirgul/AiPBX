<form method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_asterisk_settings" value="1">

    <!-- Settings Single-Line Fixed Tabs -->
    <div class="card tabbed-page">
        <?php echo uiPageHeader('fa-sliders-h', t('asterisk_settings.title')); ?>
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
        <button type="button" class="settings-tab-btn" data-tab="voicemail" onclick="switchSettingsTab('voicemail', this)">
            <i class="fas fa-voicemail"></i> <?php echo t('asterisk_settings.tab_voicemail'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="signaling" onclick="switchSettingsTab('signaling', this)">
            <i class="fas fa-satellite-dish"></i> <?php echo t('asterisk_settings.tab_signaling'); ?>
        </button>
        <button type="button" class="settings-tab-btn" data-tab="tuning" onclick="switchSettingsTab('tuning', this)">
            <i class="fas fa-sliders-h"></i> <?php echo t('asterisk_settings.tab_tuning'); ?>
        </button>
    </div>

    <!-- SECTION 1: PJSIP, NAT & network global settings -->
    <div id="tab_pjsip" class="settings-tab-pane active">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-network-wired u-primary"></i> <?php echo t('asterisk_settings.section1_title'); ?>
            </div>
            <?php echo uiHelpButton('astHelpBox'); ?>
        </div>

                <?php echo uiHelpBoxOpen('astHelpBox', t('asterisk_settings.help_title')); ?>
            <?php echo t('asterisk_settings.help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

        <p class="u-muted u-fs-12"><i class="fas fa-route"></i> <?php echo t('asterisk_settings.ports_moved'); ?> <a href="/transports"><?php echo t('transports.title'); ?></a></p>

        <div class="u-grid-2 u-mt-12">

            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_tls_method'); ?></label>
                <select name="pjsip_tls_method" class="form-control">
                    <option value="tlsv1_2" <?php echo (($s['pjsip_tls_method'] ?? 'tlsv1_2') === 'tlsv1_2') ? 'selected' : ''; ?>>TLSv1.2 (Default)</option>
                    <option value="tlsv1_2,tlsv1_3" <?php echo (($s['pjsip_tls_method'] ?? '') === 'tlsv1_2,tlsv1_3') ? 'selected' : ''; ?>>TLSv1.2 + TLSv1.3</option>
                    <option value="tlsv1_3" <?php echo (($s['pjsip_tls_method'] ?? '') === 'tlsv1_3') ? 'selected' : ''; ?>>TLSv1.3</option>
                </select>
                <small class="u-hint"><?php echo t('asterisk_settings.tls_method_help'); ?></small>
            </div>
        </div>

        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo t('asterisk_settings.field_tls_domain'); ?></label>
            <input type="text" name="pjsip_tls_domain" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_tls_domain'] ?? ''); ?>" placeholder="sip-tls.example.com">
            <small class="u-hint"><?php echo t('asterisk_settings.tls_domain_help'); ?></small>
        </div>

        <div class="form-group u-mt-12">
            <label class="u-check-label-6">
                <input type="checkbox" name="pjsip_tls_verify_server" value="yes" <?php echo (($s['pjsip_tls_verify_server'] ?? 'no') === 'yes') ? 'checked' : ''; ?>>
                <?php echo t('asterisk_settings.field_tls_verify_server', 'Verify SIP TLS Server Certificates'); ?>
            </label>
            <small class="u-hint u-d-block"><?php echo t('asterisk_settings.tls_verify_server_help', 'Enforces X.509 certificate verification for outbound TLS SIP connections.'); ?></small>
        </div>


        <div class="form-group u-mt-12">
            <label class="form-label"><?php echo t('asterisk_settings.field_codecs'); ?></label>
            <div class="u-d-flex u-gap-16 u-mt-6">
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
            <div class="u-d-flex u-gap-16 u-mt-6">
                <label class="u-check-label-6">
                    <input type="checkbox" name="wired_codecs[]" value="opus" <?php echo in_array('opus', $active_wired_codecs) ? 'checked' : ''; ?>> Opus
                </label>
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

        <div class="u-d-grid u-gtc-1fr-1fr-1fr u-gap-16 u-mt-12">
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
    <!-- The addresses Asterisk uses; their own form (server-address-form) below the page form. -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-network-wired u-primary"></i> <?php echo t('netcfg.pbx_addresses_title'); ?></div>
        </div>
        <div class="u-card-pad">
            <div class="u-grid-2">
                <?php if ($netset['server_ip'] !== ''): ?>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netset.server_ip'); ?></label>
                    <input form="server-address-form" type="text" name="server_ip" class="form-control" list="pbxLocalIps" maxlength="15" value="<?php echo htmlspecialchars((string) $netset['server_ip']); ?>" placeholder="<?php echo htmlspecialchars((string) $netPrimary); ?>">
                    <small class="u-hint"><?php echo t('netset.server_ip_help'); ?></small>
                </div>
                <?php else: ?><input form="server-address-form" type="hidden" name="server_ip" value=""><?php endif; ?>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_external_ip'); ?></label>
                    <input form="server-address-form" type="text" name="pjsip_external_ip" class="form-control" maxlength="253" value="<?php echo htmlspecialchars((string) $netset['pjsip_external_ip']); ?>" placeholder="203.0.113.10">
                    <small class="u-hint"><?php echo t('netset.public_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('netset.field_media_ip'); ?></label>
                    <input form="server-address-form" type="text" name="pjsip_external_media_ip" class="form-control" maxlength="253" value="<?php echo htmlspecialchars((string) $netset['pjsip_external_media_ip']); ?>">
                    <small class="u-hint"><?php echo t('netset.media_ip_help'); ?></small>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_local_net'); ?></label>
                <input form="server-address-form" type="text" name="pjsip_local_net" class="form-control" maxlength="600" value="<?php echo htmlspecialchars((string) $netset['pjsip_local_net']); ?>" placeholder="192.168.1.0/24,10.0.0.0/24">
                <small class="u-hint"><?php echo t('asterisk_settings.local_net_help'); ?></small>
            </div>
            <div class="form-actions u-ai-center">
                <span class="u-muted u-fs-12"> · <?php echo t('netset.apply_note'); ?></span>
                <button type="submit" form="server-address-form" class="btn btn-primary"><i class="fas fa-save"></i> <?php echo t('common.save'); ?></button>
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

        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <?php echo t('asterisk_settings.rtp_help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

        <div class="u-d-grid u-gtc-1fr-1fr-1fr u-gap-16">
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

        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <?php echo t('asterisk_settings.udptl_help_body'); ?>
<?php echo uiHelpBoxClose(); ?>

        <div class="u-d-grid u-gtc-1fr-1fr-1fr u-gap-16">
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
            <div class="card-title"><i class="fas fa-comment-dots u-primary"></i> <?php echo t('asterisk_settings.status_prompt_title'); ?></div>
        </div>
        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <p><?php echo t('asterisk_settings.status_prompt_help'); ?></p>
<?php echo uiHelpBoxClose(); ?>
        <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16">
            <?php foreach (['busy', 'noanswer', 'unavail'] as $spCase): ?>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.status_prompt_' . $spCase); ?></label>
                    <select name="status_prompt_<?php echo $spCase; ?>" class="form-control">
                        <option value="0" <?php echo ($s['status_prompt_' . $spCase] ?? '0') !== '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.status_prompt_tone'); ?></option>
                        <option value="1" <?php echo ($s['status_prompt_' . $spCase] ?? '0') === '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.status_prompt_spoken'); ?></option>
                    </select>
                    <small class="u-hint"><?php echo t('asterisk_settings.status_prompt_' . $spCase . '_text'); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-bell u-primary"></i> <?php echo t('asterisk_settings.ring_section_title'); ?>
            </div>
        </div>

        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <?php echo t('asterisk_settings.ring_help'); ?>
<?php echo uiHelpBoxClose(); ?>

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
            <small class="u-muted u-mt-8" style="display: block;"><?php echo t('asterisk_settings.no_sounds_uploaded'); ?> <a href="/sounds"><?php echo t('asterisk_settings.sounds_page_link'); ?></a> <?php echo t('asterisk_settings.sounds_page_link_suffix'); ?></small>
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

        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <?php echo t('asterisk_settings.video_calls_help'); ?>
<?php echo uiHelpBoxClose(); ?>

        <div class="u-d-grid u-gtc-1fr-1fr-1fr u-gap-16">
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

        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <?php echo t('asterisk_settings.lang_help'); ?>
<?php echo uiHelpBoxClose(); ?>

        <div class="form-group u-maxw-320">
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

    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-globe u-primary"></i> <?php echo t('region.title'); ?></div>
        </div>
        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <?php echo t('region.help'); ?>
<?php echo uiHelpBoxClose(); ?>
        <?php if ($system_timezone !== $portal_timezone): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo sprintf(t('region.mismatch'), htmlspecialchars($system_timezone), htmlspecialchars($portal_timezone)); ?></div>
        <?php endif; ?>
        <div class="form-group u-maxw-320">
            <label class="form-label"><?php echo t('region.timezone'); ?></label>
            <select name="system_timezone" form="region-form" class="form-control">
                <?php foreach (DateTimeZone::listIdentifiers() as $zone): ?>
                    <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $zone === $system_timezone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group u-maxw-320">
            <label class="form-label"><?php echo t('region.tones'); ?></label>
            <select name="tone_country" form="region-form" class="form-control">
                <?php foreach (RegionSettingsService::TONES as $code => $name): ?>
                    <option value="<?php echo $code; ?>" <?php echo $code === $tone_country ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="u-hint"><?php echo t('region.tones_help'); ?></small>
        </div>
        <?php if (hasModulePermission('asterisk_settings', 'edit')): ?>
            <label class="u-check-label u-fs-12 u-mb-12" style="display: block;">
                <input type="checkbox" name="end_calls" value="1" form="region-form" class="u-accent"> <?php echo t('region.end_calls'); ?>
            </label>
            <button type="submit" form="region-form" class="btn btn-primary" onclick="return confirm(<?php echo jsArg(t('region.confirm')); ?>);">
                <i class="fas fa-save"></i> <?php echo t('region.save'); ?>
            </button>
        <?php endif; ?>
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

        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <p><?php echo t('asterisk_settings.amd_help_body', 'Global timing and word-detection parameters used by Asterisk\'s <code>AMD()</code> application during automated outbound campaigns (Auto Dialer). Times are in milliseconds (ms).'); ?></p>
<?php echo uiHelpBoxClose(); ?>

        <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16">
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

    <!-- SECTION: voicemail limits and PIN rules (VoicemailPolicy) -->
    <div id="tab_voicemail" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fas fa-voicemail u-primary"></i> <?php echo t('asterisk_settings.vm_title'); ?></div>
        </div>
        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <p><?php echo t('asterisk_settings.vm_help'); ?></p>
            <?php if (($vm_weak_pins ?? 0) > 0): ?>
                <p class="u-warning"><strong><?php echo htmlspecialchars(sprintf(t('asterisk_settings.vm_weak_pins'), (int) $vm_weak_pins)); ?></strong></p>
            <?php endif; ?>
<?php echo uiHelpBoxClose(); ?>
        <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16">
            <?php foreach (VoicemailPolicy::LIMITS as $vmKey => [$vmDef, $vmMin, $vmMax]): ?>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_' . $vmKey); ?></label>
                    <input type="number" name="<?php echo $vmKey; ?>" class="form-control" value="<?php echo (int) ($vm_settings[$vmKey] ?? $vmDef); ?>" min="<?php echo $vmMin; ?>" max="<?php echo $vmMax; ?>" required>
                    <small class="u-hint"><?php echo htmlspecialchars(sprintf(t('asterisk_settings.vm_range'), $vmMin, $vmMax, $vmDef)); ?></small>
                </div>
            <?php endforeach; ?>
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_vm_pin_not_extension'); ?></label>
                <select name="vm_pin_not_extension" class="form-control">
                    <option value="1" <?php echo !empty($vm_settings['vm_pin_not_extension']) ? 'selected' : ''; ?>><?php echo t('asterisk_settings.vm_rule_on'); ?></option>
                    <option value="0" <?php echo empty($vm_settings['vm_pin_not_extension']) ? 'selected' : ''; ?>><?php echo t('asterisk_settings.vm_rule_off'); ?></option>
                </select>
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION: signaling capture (SIP ladder + RTCP quality, local only) -->
    <div id="tab_signaling" class="settings-tab-pane" style="display: none;">
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fas fa-satellite-dish u-primary"></i> <?php echo t('asterisk_settings.signaling_title'); ?>
            </div>
        </div>
        <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.info_title')); ?>
            <p><?php echo t('asterisk_settings.signaling_help'); ?></p>
<?php echo uiHelpBoxClose(); ?>
        <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-220-1fr u-gap-16">
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_signaling_capture'); ?></label>
                <select name="signaling_capture" class="form-control">
                    <option value="0" <?php echo ($s['signaling_capture'] ?? '0') !== '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.signaling_off'); ?></option>
                    <option value="1" <?php echo ($s['signaling_capture'] ?? '0') === '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.signaling_on'); ?></option>
                </select>
                <small class="u-hint"><?php echo htmlspecialchars(sprintf(t('asterisk_settings.signaling_status'), (int) ($signaling_status['rows'] ?? 0), (string) ($signaling_status['last'] ?? '—'))); ?></small>
            </div>
            <div class="form-group">
                <label class="form-label"><?php echo t('asterisk_settings.field_signaling_retention'); ?></label>
                <input type="number" name="signaling_retention_days" class="form-control" value="<?php echo htmlspecialchars($s['signaling_retention_days'] ?? '30'); ?>" min="1" max="3650" required>
                <small class="u-hint"><?php echo t('asterisk_settings.signaling_retention_help'); ?></small>
            </div>
        </div>
    </div>
    </div>

    <!-- SECTION: Fine Tuning & Maintenance (Roadmap 2.5 & 2.6) -->
    <div id="tab_tuning" class="settings-tab-pane" style="display: none;">
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-sliders-h u-primary"></i> <?php echo t('asterisk_settings.tuning_title'); ?>
                </div>
            </div>
            <?php echo uiHelpBoxOpen('asteriskSettingsHelpBox', t('asterisk_settings.tuning_help_title')); ?>
                <p><?php echo t('asterisk_settings.tuning_help_body'); ?></p>
<?php echo uiHelpBoxClose(); ?>

            <!-- SIP Timers & Limits -->
            <h4 class="u-fs-14 u-fw-700 u-m-16-0-12 u-text-main">
                <i class="fas fa-stopwatch u-primary"></i> <?php echo t('asterisk_settings.sec_sip_timers'); ?>
            </h4>
            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-200-1fr u-gap-16">
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_max_forwards'); ?></label>
                    <input type="number" name="pjsip_max_forwards" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_max_forwards'] ?? '70'); ?>" min="1" max="255" required>
                    <small class="u-hint"><?php echo t('asterisk_settings.max_forwards_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_timer_t1'); ?></label>
                    <input type="number" name="pjsip_timer_t1" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_timer_t1'] ?? '500'); ?>" min="50" max="10000" required>
                    <small class="u-hint"><?php echo t('asterisk_settings.timer_t1_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_timer_b'); ?></label>
                    <input type="number" name="pjsip_timer_b" class="form-control" value="<?php echo htmlspecialchars($s['pjsip_timer_b'] ?? '32000'); ?>" min="500" max="64000" required>
                    <small class="u-hint"><?php echo t('asterisk_settings.timer_b_help'); ?></small>
                </div>
            </div>

            <!-- Jitter Buffer -->
            <h4 class="u-fs-14 u-fw-700 u-m-24-0-12 u-text-main">
                <i class="fas fa-wave-square u-primary"></i> <?php echo t('asterisk_settings.sec_jitter_buffer'); ?>
            </h4>
            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-200-1fr u-gap-16">
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_jitterbuffer_enabled'); ?></label>
                    <select name="jitterbuffer_enabled" class="form-control">
                        <option value="1" <?php echo ($s['jitterbuffer_enabled'] ?? '1') === '1' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.jb_adaptive_on'); ?></option>
                        <option value="0" <?php echo ($s['jitterbuffer_enabled'] ?? '1') === '0' ? 'selected' : ''; ?>><?php echo t('asterisk_settings.jb_off'); ?></option>
                    </select>
                    <small class="u-hint"><?php echo t('asterisk_settings.jitterbuffer_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_jb_max_size'); ?></label>
                    <input type="number" name="jitterbuffer_max_size" class="form-control" value="<?php echo htmlspecialchars($s['jitterbuffer_max_size'] ?? '200'); ?>" min="50" max="2000">
                    <small class="u-hint"><?php echo t('asterisk_settings.jb_max_size_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_jb_initial_size'); ?></label>
                    <input type="number" name="jitterbuffer_initial_size" class="form-control" value="<?php echo htmlspecialchars($s['jitterbuffer_initial_size'] ?? '100'); ?>" min="20" max="1000">
                    <small class="u-hint"><?php echo t('asterisk_settings.jb_initial_size_help'); ?></small>
                </div>
            </div>

            <!-- Night Restart Window -->
            <h4 class="u-fs-14 u-fw-700 u-m-24-0-12 u-text-main">
                <i class="fas fa-moon u-primary"></i> <?php echo t('asterisk_settings.sec_night_restart'); ?>
            </h4>
            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-200-1fr u-gap-16">
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_night_restart_enabled'); ?></label>
                    <select name="night_restart_enabled" class="form-control">
                        <option value="0" <?php echo ($s['night_restart_enabled'] ?? '0') !== '1' ? 'selected' : ''; ?>><?php echo t('common.passive'); ?></option>
                        <option value="1" <?php echo ($s['night_restart_enabled'] ?? '0') === '1' ? 'selected' : ''; ?>><?php echo t('common.active'); ?></option>
                    </select>
                    <small class="u-hint"><?php echo t('asterisk_settings.night_restart_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_night_window_start'); ?></label>
                    <input type="text" name="night_restart_window_start" class="form-control" value="<?php echo htmlspecialchars($s['night_restart_window_start'] ?? '03:00'); ?>" placeholder="03:00">
                    <small class="u-hint"><?php echo t('asterisk_settings.night_window_start_help'); ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_night_window_end'); ?></label>
                    <input type="text" name="night_restart_window_end" class="form-control" value="<?php echo htmlspecialchars($s['night_restart_window_end'] ?? '05:00'); ?>" placeholder="05:00">
                    <small class="u-hint"><?php echo t('asterisk_settings.night_window_end_help'); ?></small>
                </div>
            </div>

            <!-- Security & ACL Threshold -->
            <h4 class="u-fs-14 u-fw-700 u-m-24-0-12 u-text-main">
                <i class="fas fa-shield-alt u-primary"></i> <?php echo t('asterisk_settings.sec_acl_security'); ?>
            </h4>
            <div class="u-d-grid u-gtc-repeat-auto-fit-minmax-240-1fr u-gap-16">
                <div class="form-group">
                    <label class="form-label"><?php echo t('asterisk_settings.field_failed_reg_threshold'); ?></label>
                    <input type="number" name="failed_reg_threshold" class="form-control" value="<?php echo htmlspecialchars($s['failed_reg_threshold'] ?? '10'); ?>" min="1" max="1000" required>
                    <small class="u-hint"><?php echo t('asterisk_settings.failed_reg_threshold_help'); ?></small>
                </div>
            </div>

            <!-- Diagnostics & Operations (Buttons) -->
            <h4 class="u-fs-14 u-fw-700 u-m-24-0-12 u-text-main">
                <i class="fas fa-tools u-primary"></i> <?php echo t('asterisk_settings.sec_diagnostics'); ?>
            </h4>
            <div class="u-d-flex u-wrap u-gap-16 u-ai-center u-bg-bg-card u-p-16 u-bd-1-solid-border-color u-r-8">
                <div>
                    <?php
                    $sipDebugUntil = (int)($s['sip_debug_until'] ?? 0);
                    $isDebugActive = ($sipDebugUntil > time());
                    $remainingSec = $isDebugActive ? ($sipDebugUntil - time()) : 0;
                    ?>
                    <?php if ($isDebugActive): ?>
                        <span class="badge badge-success u-mr-8 u-p-8-12">
                            <i class="fas fa-check-circle"></i> <?php echo sprintf(t('asterisk_settings.sip_debug_active'), ceil($remainingSec / 60)); ?>
                        </span>
                        <button type="submit" name="disable_sip_debug" value="1" class="btn btn-warning btn-sm">
                            <i class="fas fa-stop-circle"></i> <?php echo t('asterisk_settings.btn_stop_sip_debug'); ?>
                        </button>
                    <?php else: ?>
                        <button type="submit" name="enable_sip_debug" value="1" class="btn btn-secondary btn-sm">
                            <i class="fas fa-bug"></i> <?php echo t('asterisk_settings.btn_start_sip_debug'); ?>
                        </button>
                    <?php endif; ?>
                    <small class="u-hint u-mt-4" style="display: block;"><?php echo t('asterisk_settings.sip_debug_desc'); ?></small>
                </div>

                <div class="u-border-left-1-solid-border-color u-pl-16">
                    <button type="submit" name="rotate_logs" value="1" class="btn btn-secondary btn-sm">
                        <i class="fas fa-sync-alt"></i> <?php echo t('asterisk_settings.btn_rotate_logs'); ?>
                    </button>
                    <small class="u-hint u-mt-4" style="display: block;"><?php echo t('asterisk_settings.rotate_logs_desc'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <?php if (hasModulePermission('asterisk_settings', 'edit')): ?>
        <div class="form-actions"><button type="submit" class="btn btn-primary" title="<?php echo t('asterisk_settings.save_all_tooltip'); ?>">
            <i class="fas fa-save"></i> <?php echo t('common.save'); ?>
        </button></div>
    <?php endif; ?>
    </div>
</form>

<form id="server-address-form" method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_server" value="1">
</form>
<datalist id="pbxLocalIps">
    <?php foreach ($localIps as $ip): ?><option value="<?php echo htmlspecialchars((string) $ip); ?>"><?php endforeach; ?>
</datalist>

<form id="region-form" method="POST" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
    <input type="hidden" name="save_region" value="1">
</form>
