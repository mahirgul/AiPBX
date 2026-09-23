<div class="card">
    <div class="card-header">
        <div class="card-title">
            <i class="fas fa-network-wired" style="color: var(--primary);"></i> <?php echo t('trunks.header'); ?>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" class="btn-help" onclick="toggleModuleHelp('trunkHelpBox')" title="Modül Rehberi">
                <i class="fas fa-question-circle"></i>
            </button>
            <?php if (hasModulePermission('trunks', 'edit')): ?>
                <button class="btn btn-primary btn-sm" onclick="openCreateTrunkModal()" title="Yeni Ekle">
                    <i class="fas fa-plus-circle"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Collapsible Help Box -->
    <div class="module-help-box" id="trunkHelpBox">
        <h4><i class="fas fa-info-circle"></i> <?php echo t('trunks.help_title'); ?></h4>
        <?php echo t('trunks.help_body'); ?><br>
        - <strong><?php echo t('trunks.help_transport'); ?></strong><br>
        - <strong><?php echo t('trunks.help_t38'); ?></strong><br>
        - <strong><?php echo t('trunks.help_qualify'); ?></strong>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th class="col-hide-mobile" style="width: 50px;">#</th>
                    <th><?php echo t('trunks.col_trunk'); ?></th>
                    <th><?php echo t('trunks.col_title'); ?></th>
                    <th><?php echo t('trunks.col_ip_port'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('trunks.col_transport'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('trunks.col_codecs'); ?></th>
                    <th class="col-hide-mobile"><?php echo t('trunks.col_t38'); ?></th>
                    <th><?php echo t('trunks.col_connection'); ?></th>
                    <th><?php echo t('trunks.col_status'); ?></th>
                    <th class="text-right"><?php echo t('trunks.col_actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trunks)): ?>
                    <?php echo uiTableEmptyRow(10, t('trunks.empty'), 'fa-server'); ?>
                <?php else: ?>
                    <?php foreach ($trunks as $t):
                        $live_class = AsteriskHelper::classifyStatus($trunk_statuses[$t['trunk_name']] ?? null);
                        switch ($live_class) {
                            case 'busy': $live_badge = 'badge-info'; $live_text = t('trunks.status_busy'); break;
                            case 'idle': $live_badge = 'badge-success'; $live_text = t('trunks.status_idle'); break;
                            case 'down': $live_badge = 'badge-danger'; $live_text = t('trunks.status_down'); break;
                            default: $live_badge = 'badge-secondary'; $live_text = t('trunks.status_unknown');
                        }
                    ?>
                        <tr>
                            <td class="col-hide-mobile text-muted" style="font-size: 12px;">#<?php echo $t['id']; ?></td>
                            <td><span class="badge badge-info"><i class="fas fa-server"></i> <?php echo htmlspecialchars($t['trunk_name']); ?></span></td>
                            <td style="font-weight: 700; color: var(--text-main);">
                                <?php echo htmlspecialchars($t['title']); ?>
                                <?php if (!empty($t['did_trim_digits'])): ?>
                                    <span class="badge badge-warning" title="DID Kırpma: Son <?php echo intval($t['did_trim_digits']); ?> hane" style="font-size: 10px; margin-left: 4px;">
                                        <i class="fas fa-cut"></i> -<?php echo intval($t['did_trim_digits']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($t['allow_outbound_routing'])): ?>
                                    <span class="badge badge-info" title="Transit / Trunk-to-Trunk Geçiş Aktif (Grup <?php echo intval($t['outbound_route_group'] ?? 1); ?>)" style="font-size: 10px; margin-left: 4px; background: #6366f1; color: #fff;">
                                        <i class="fas fa-random"></i> Transit (G<?php echo intval($t['outbound_route_group'] ?? 1); ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($t['ip_address'] . ':' . $t['port']); ?></code></td>
                            <td class="col-hide-mobile"><span class="badge badge-success"><?php echo strtoupper(htmlspecialchars($t['transport'])); ?></span></td>
                            <td class="col-hide-mobile"><?php echo htmlspecialchars($t['codecs']); ?></td>
                            <td class="col-hide-mobile">
                                <?php if ($t['t38_support']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check"></i> <?php echo t('trunks.t38_active'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo t('trunks.t38_inactive'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $live_badge; ?>" title="Asterisk PJSIP canlı durumu (OPTIONS/qualify sonucu)"><?php echo $live_text; ?></span>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($t['id'], $t['is_active'], 'trunk_id'); ?>
                            </td>
                            <td class="text-right">
                                <?php echo uiRowActions($t, 'openEditTrunkModal', 'trunk_id', 'delete_trunk', sprintf(t('trunks.confirm_delete'), $t['title'] . ' (' . $t['trunk_name'] . ')')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create / Edit Trunk Modal -->
<div class="modal-overlay" id="trunkModal">
    <div class="modal-card" style="max-width: 680px;">
        <div class="modal-header">
            <h3 style="font-size: 16px; font-weight: 700;" id="trunkModalTitle"><i class="fas fa-plus-circle" style="color: var(--primary);"></i> <?php echo t('trunks.new_trunk'); ?></h3>
            <button class="btn btn-secondary" onclick="closeTrunkModal()" style="padding: 6px 12px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_trunk" value="1">
                <input type="hidden" name="trunk_id" id="modal_trunk_id" value="">

                <!-- Tab Buttons (Single-Line Fixed Tabs) -->
                <div class="modal-tabs">
                    <button type="button" class="modal-tab-btn active trunk-tab-btn" data-tab="basic" onclick="switchTrunkTab('basic', this)"><i class="fas fa-sliders-h"></i> <?php echo t('trunks.tab_basic'); ?></button>
                    <button type="button" class="modal-tab-btn trunk-tab-btn" data-tab="auth" onclick="switchTrunkTab('auth', this)"><i class="fas fa-key"></i> <?php echo t('trunks.tab_auth'); ?></button>
                    <button type="button" class="modal-tab-btn trunk-tab-btn" data-tab="callerid" onclick="switchTrunkTab('callerid', this)"><i class="fas fa-id-badge"></i> <?php echo t('trunks.tab_callerid'); ?></button>
                    <button type="button" class="modal-tab-btn trunk-tab-btn" data-tab="advanced" onclick="switchTrunkTab('advanced', this)"><i class="fas fa-cogs"></i> <?php echo t('trunks.tab_advanced'); ?></button>
                </div>

                <!-- TAB 1: Temel Ayarlar -->
                <div id="trunk_tab_basic" class="trunk-tab-pane">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_trunk_name'); ?></label>
                            <input type="text" name="trunk_name" id="modal_trunk_name" class="form-control" placeholder="main_trunk" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_title'); ?></label>
                            <input type="text" name="title" id="modal_title" class="form-control" placeholder="NEC SV9100 Trunk" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_ip'); ?></label>
                            <input type="text" name="ip_address" id="modal_ip_address" class="form-control" placeholder="198.51.100.10" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_port'); ?></label>
                            <input type="number" name="port" id="modal_port" class="form-control" value="5060" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_transport'); ?></label>
                            <select name="transport" id="modal_transport" class="form-control">
                                <option value="udp">UDP (5060 Standard)</option>
                                <option value="tcp">TCP (5060)</option>
                                <option value="tls">TLS (5061 Encrypted SIP)</option>
                                <option value="wss">WSS (Secure WebSocket)</option>
                                <option value="ws">WS (WebSocket)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_codecs'); ?></label>
                            <input type="text" name="codecs" id="modal_codecs" class="form-control" value="alaw,ulaw" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_outbound_proxy'); ?></label>
                            <input type="text" name="outbound_proxy" id="modal_outbound_proxy" class="form-control" placeholder="sip:proxy.operator.com:5060">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_match_hosts'); ?></label>
                            <input type="text" name="match_hosts" id="modal_match_hosts" class="form-control" placeholder="198.51.100.10, 198.51.100.0/24">
                            <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('trunks.match_hosts_help'); ?></small>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 14px;">
                        <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-weight: 600;"><?php echo t('trunks.field_active'); ?></span>
                        </label>
                    </div>
                </div>

                <!-- TAB 2: Kimlik Doğrulama & Kayıt (Auth & Registration) -->
                <div id="trunk_tab_auth" class="trunk-tab-pane" style="display: none;">
                    <div class="form-group" style="margin-bottom: 16px; background: var(--bg-card); padding: 12px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 0;">
                            <input type="checkbox" name="registration_enabled" id="modal_registration_enabled" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-weight: 600;"><?php echo t('trunks.field_registration_enabled'); ?></span>
                        </label>
                        <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;">Operatör PJSIP Outbound Registration gerektiriyorsa işaretleyin.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label form-label-help">
                            <span><?php echo t('trunks.field_connection_mode'); ?></span>
                            <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('trunks.connection_mode_help'); ?></span></span>
                        </label>
                        <select name="connection_mode" id="modal_connection_mode" class="form-control">
                            <option value="ip"><?php echo t('trunks.connection_mode_ip'); ?></option>
                            <option value="register"><?php echo t('trunks.connection_mode_register'); ?></option>
                        </select>
                        <small style="color: var(--warning); display: block; margin-top: 6px;">
                            <i class="fas fa-triangle-exclamation"></i> <?php echo t('trunks.connection_mode_warning'); ?>
                        </small>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_auth_username'); ?></label>
                            <input type="text" name="auth_username" id="modal_auth_username" class="form-control" placeholder="0XXXXXXXXXX veya kullanıcı adı">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_auth_password'); ?></label>
                            <input type="password" name="auth_password" id="modal_auth_password" autocomplete="new-password" class="form-control" placeholder="SIP Parolası">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_registration_expiration'); ?></label>
                            <input type="number" name="registration_expiration" id="modal_registration_expiration" class="form-control" value="3600" min="30" max="86400">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_registration_retry_interval'); ?></label>
                            <input type="number" name="registration_retry_interval" id="modal_registration_retry_interval" class="form-control" value="60" min="5" max="3600">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 12px;">
                        <label class="form-label"><?php echo t('trunks.field_max_contacts'); ?></label>
                        <input type="number" name="max_contacts" id="modal_max_contacts" class="form-control" value="1" min="1" max="100">
                    </div>
                </div>

                <!-- TAB 3: Arayan Bilgisi & Başlıklar (Caller ID & Headers) -->
                <div id="trunk_tab_callerid" class="trunk-tab-pane" style="display: none;">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('trunks.field_outbound_caller_id'); ?></label>
                        <input type="text" name="outbound_caller_id" id="modal_outbound_caller_id" class="form-control" placeholder="0XXXXXXXXXX (Boşsa dahilinin numarası basılır)">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_from_user'); ?></label>
                            <input type="text" name="from_user" id="modal_from_user" class="form-control" placeholder="0XXXXXXXXXX">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_from_domain'); ?></label>
                            <input type="text" name="from_domain" id="modal_from_domain" class="form-control" placeholder="sip.operator.com">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
                        <div class="form-group">
                            <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="send_caller_name" id="modal_send_caller_name" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                                <span style="font-weight: 600; font-size: 13px;"><?php echo t('trunks.field_send_caller_name'); ?></span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="send_pai" id="modal_send_pai" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                                <span style="font-weight: 600; font-size: 13px;"><?php echo t('trunks.field_send_pai'); ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 8px;">
                        <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="send_rpid" id="modal_send_rpid" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-weight: 600; font-size: 13px;"><?php echo t('trunks.field_send_rpid'); ?></span>
                        </label>
                    </div>
                </div>

                <!-- TAB 4: Sinyalizasyon, Medya & Gelişmiş (Signaling & Advanced) -->
                <div id="trunk_tab_advanced" class="trunk-tab-pane" style="display: none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_dtmf_mode'); ?></label>
                            <select name="dtmf_mode" id="modal_dtmf_mode" class="form-control">
                                <option value="rfc4733">RFC 4733 / RFC 2833 (Önerilen)</option>
                                <option value="inband">Inband (Ses İçi)</option>
                                <option value="info">SIP INFO</option>
                                <option value="auto">Auto</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_t38'); ?></label>
                            <select name="t38_support" id="modal_t38_support" class="form-control">
                                <option value="1"><?php echo t('trunks.t38_option_active'); ?></option>
                                <option value="0"><?php echo t('trunks.t38_option_inactive'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_t38_ec'); ?></label>
                            <select name="t38_udptl_ec" id="modal_t38_udptl_ec" class="form-control">
                                <option value="redundancy">Redundancy (Önerilen)</option>
                                <option value="none">None</option>
                                <option value="fec">FEC</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_t38_nat'); ?></label>
                            <select name="t38_udptl_nat" id="modal_t38_udptl_nat" class="form-control">
                                <option value="yes">Yes (NAT Arkası)</option>
                                <option value="no">No (Doğrudan IP)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_t38_maxdatagram'); ?></label>
                            <input type="number" name="t38_udptl_maxdatagram" id="modal_t38_udptl_maxdatagram" class="form-control" value="400" min="100" max="2000">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px; background: var(--bg-card); padding: 12px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label form-label-help">
                                <span><?php echo t('trunks.field_fax_detect'); ?></span>
                                <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('trunks.fax_detect_help'); ?></span></span>
                            </label>
                            <select name="fax_detect" id="modal_fax_detect" class="form-control">
                                <option value="1">Aktif (Otomatik Algıla &amp; Yönlendir)</option>
                                <option value="0">Pasif</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label"><?php echo t('trunks.field_fax_detect_timeout'); ?></label>
                            <input type="number" name="fax_detect_timeout" id="modal_fax_detect_timeout" class="form-control" value="30" min="5" max="120">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_qualify'); ?></label>
                            <input type="number" name="qualify_frequency" id="modal_qualify_frequency" class="form-control" value="60" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_direct_media'); ?></label>
                            <select name="direct_media" id="modal_direct_media" class="form-control">
                                <option value="no">No (PBX üzerinden)</option>
                                <option value="yes">Yes (Doğrudan Medya)</option>
                                <option value="nonat">NoNAT</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_rtp_symmetric'); ?></label>
                            <select name="rtp_symmetric" id="modal_rtp_symmetric" class="form-control">
                                <option value="yes">Yes (Önerilen)</option>
                                <option value="no">No</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_rewrite_contact'); ?></label>
                            <select name="rewrite_contact" id="modal_rewrite_contact" class="form-control">
                                <option value="yes">Yes (Önerilen)</option>
                                <option value="no">No</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_force_rport'); ?></label>
                            <select name="force_rport" id="modal_force_rport" class="form-control">
                                <option value="yes">Yes (Önerilen)</option>
                                <option value="no">No</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_timers'); ?></label>
                            <select name="timers" id="modal_timers" class="form-control">
                                <option value="yes">Yes (Session Timers Aktif)</option>
                                <option value="no">No (Devre Dışı)</option>
                                <option value="always">Always</option>
                                <option value="never">Never</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 12px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_context'); ?></label>
                            <input type="text" name="context" id="modal_context" class="form-control" value="from-trunk-inbound">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('trunks.field_max_channels'); ?></label>
                            <input type="number" name="max_channels" id="modal_max_channels" class="form-control" value="0" min="0">
                        </div>
                    </div>

                    <!-- Gelen Çağrı & DID Normalizasyonu / Transit Rota -->
                    <div style="margin-top: 14px; background: var(--bg-card); padding: 14px; border: 1px solid var(--border-color); border-radius: 6px;">
                        <h4 style="font-size: 13px; font-weight: 700; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; color: var(--text-main);">
                            <i class="fas fa-random" style="color: var(--primary);"></i> <?php echo t('trunks.section_inbound_routing'); ?>
                        </h4>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label form-label-help">
                                    <span><?php echo t('trunks.field_did_trim_digits'); ?></span>
                                    <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('trunks.did_trim_digits_help'); ?></span></span>
                                </label>
                                <input type="number" name="did_trim_digits" id="modal_did_trim_digits" class="form-control" value="0" min="0" max="20">
                                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('trunks.did_trim_example'); ?></small>
                            </div>

                            <div class="form-group" style="margin-bottom: 0;">
                                <label class="form-label"><?php echo t('trunks.field_outbound_route_group'); ?></label>
                                <input type="number" name="outbound_route_group" id="modal_outbound_route_group" class="form-control" value="1" min="1" max="99">
                                <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('trunks.outbound_route_group_help'); ?></small>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 12px; margin-bottom: 0;">
                            <label class="form-label" style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" name="allow_outbound_routing" id="modal_allow_outbound_routing" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                                <span style="font-weight: 600; font-size: 13px;"><?php echo t('trunks.field_allow_outbound_routing'); ?></span>
                            </label>
                            <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('trunks.allow_outbound_routing_help'); ?></small>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 12px;">
                        <label class="form-label"><?php echo t('trunks.field_custom_params'); ?></label>
                        <textarea name="custom_pjsip_params" id="modal_custom_pjsip_params" class="form-control" rows="3" placeholder="trust_id_inbound=yes&#10;inband_progress=yes" style="font-family: monospace; font-size: 12px;"></textarea>
                        <small style="color: var(--text-muted); font-size: 11px; margin-top: 4px; display: block;"><?php echo t('trunks.custom_params_help'); ?></small>
                    </div>
                </div>

                <?php echo uiModalFooter('closeTrunkModal()'); ?>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/trunks.js?v=<?php echo time(); ?>"></script>
