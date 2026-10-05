<?php
/**
 * Microsoft Teams integration screen (Direct Routing, user mapping, webhooks, PowerShell)
 */
$is_teams_enabled = !empty($settings['teams_enabled']) && $settings['teams_enabled'] !== '0';
$is_webhook_enabled = !empty($settings['teams_webhook_enabled']) && $settings['teams_webhook_enabled'] !== '0';
$cert = $certInfo ?? ['exists' => false, 'message' => t('ms_teams.cert_not_checked')];

if (!$cert['exists']) {
    $cert_badge_text = $cert['message'] ?? t('ms_teams.cert_not_found');
} elseif (!empty($cert['is_expired'])) {
    $cert_badge_text = sprintf(t('ms_teams.cert_expired'), abs((int)($cert['days_remaining'] ?? 0)));
} elseif (!empty($cert['is_expiring_soon'])) {
    $cert_badge_text = sprintf(t('ms_teams.cert_expiring_soon'), (int)($cert['days_remaining'] ?? 0));
} else {
    $cert_badge_text = sprintf(t('ms_teams.cert_valid'), (int)($cert['days_remaining'] ?? 0));
}
?>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/ms_teams.css'); ?>">

<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div class="card-title" style="display: flex; align-items: center; gap: 10px; font-size: 18px; font-weight: 700;">
            <i class="fab fa-microsoft" style="color: #6264a7; font-size: 22px;"></i>
            <span><?php echo t('ms_teams.title'); ?></span>
            <?php if ($is_teams_enabled): ?>
                <span class="teams-header-badge badge-active"><i class="fas fa-check-circle"></i> <?php echo t('ms_teams.badge_dr_active'); ?></span>
            <?php else: ?>
                <span class="teams-header-badge badge-inactive"><i class="fas fa-pause-circle"></i> <?php echo t('ms_teams.badge_dr_inactive'); ?></span>
            <?php endif; ?>
            <?php if ($is_webhook_enabled): ?>
                <span class="teams-header-badge badge-active"><i class="fas fa-bell"></i> <?php echo t('ms_teams.badge_webhook_active'); ?></span>
            <?php endif; ?>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleTeamsHelp()" title="<?php echo t('ms_teams.guide'); ?>">
            <i class="fas fa-question-circle"></i> <?php echo t('ms_teams.help_and_guide'); ?>
        </button>
    </div>

    <!-- Rehber Kutusu -->
    <div class="module-help-box" id="teamsHelpBox">
        <h4 style="color: #464775; margin-top: 0;"><i class="fab fa-microsoft"></i> <?php echo t('ms_teams.guide_title'); ?></h4>
        <p style="font-size: 13px; line-height: 1.6; margin-bottom: 8px;">
            <?php echo t('ms_teams.guide_intro'); ?>
        </p>
        <ul style="font-size: 13px; line-height: 1.6; margin-bottom: 8px;">
            <li><strong><?php echo t('ms_teams.guide_dr_title'); ?></strong> <?php echo t('ms_teams.guide_dr_desc'); ?></li>
            <li><strong><?php echo t('ms_teams.guide_webhook_title'); ?></strong> <?php echo t('ms_teams.guide_webhook_desc'); ?></li>
            <li><strong><?php echo t('ms_teams.guide_ps_title'); ?></strong> <?php echo t('ms_teams.guide_ps_desc'); ?></li>
        </ul>
    </div>

    <!-- Sekmeler (Tabs) -->
    <div class="teams-tabs">
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'direct_routing' ? 'active' : ''; ?>" onclick="openTeamsTab('direct_routing')">
            <i class="fas fa-network-wired"></i> <span><?php echo t('ms_teams.tab_direct_routing'); ?></span>
        </button>
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'users' ? 'active' : ''; ?>" onclick="openTeamsTab('users')">
            <i class="fas fa-users-cog"></i> <span><?php echo t('ms_teams.tab_users'); ?> (<?php echo count($mappings); ?>)</span>
        </button>
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'webhooks' ? 'active' : ''; ?>" onclick="openTeamsTab('webhooks')">
            <i class="fas fa-paper-plane"></i> <span><?php echo t('ms_teams.tab_webhooks'); ?></span>
        </button>
        <button type="button" class="teams-tab-btn <?php echo $active_tab === 'powershell' ? 'active' : ''; ?>" onclick="openTeamsTab('powershell')">
            <i class="fas fa-terminal"></i> <span><?php echo t('ms_teams.tab_powershell'); ?></span>
        </button>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 1: Direct Routing (SBC / SIP)       -->
    <!-- ========================================== -->
    <div id="tab-direct_routing" class="teams-tab-pane <?php echo $active_tab === 'direct_routing' ? 'active' : ''; ?>">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
            <input type="hidden" name="save_direct_routing" value="1">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo t('ms_teams.dr_status'); ?></label>
                    <select name="teams_enabled" class="form-control" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                        <option value="1" <?php echo ($settings['teams_enabled'] === '1') ? 'selected' : ''; ?>><?php echo t('ms_teams.dr_status_enabled'); ?></option>
                        <option value="0" <?php echo ($settings['teams_enabled'] === '0') ? 'selected' : ''; ?>><?php echo t('ms_teams.dr_status_disabled'); ?></option>
                    </select>
                    <small class="u-hint"><?php echo t('ms_teams.dr_status_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo t('ms_teams.sbc_fqdn'); ?> <span class="u-danger">*</span></label>
                    <input type="text" name="teams_domain" class="form-control" value="<?php echo htmlspecialchars($settings['teams_domain']); ?>" placeholder="<?php echo t('ms_teams.sbc_fqdn_placeholder'); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    <small class="u-hint"><?php echo t('ms_teams.sbc_fqdn_help'); ?></small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo t('ms_teams.sip_tls_port'); ?></label>
                    <input type="number" name="teams_sip_port" class="form-control" value="<?php echo htmlspecialchars($settings['teams_sip_port'] ?: '5061'); ?>" min="1" max="65535" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    <small class="u-hint"><?php echo t('ms_teams.sip_tls_port_help'); ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label u-fw-600"><?php echo t('ms_teams.sbc_name'); ?></label>
                    <input type="text" name="teams_sbc_name" class="form-control" value="<?php echo htmlspecialchars($settings['teams_sbc_name']); ?>" placeholder="<?php echo t('ms_teams.sbc_name_placeholder'); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    <small class="u-hint"><?php echo t('ms_teams.sbc_name_help'); ?></small>
                </div>
            </div>

            <!-- Certificate status and paths -->
            <div style="background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 18px; margin-bottom: 24px;">
                <h4 style="margin-top: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-lock u-success"></i> <?php echo t('ms_teams.tls_section_title'); ?>
                </h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
                    <div class="form-group">
                        <label class="form-label u-fs-13 u-fw-600"><?php echo t('ms_teams.tls_cert_path'); ?></label>
                        <input type="text" name="teams_tls_cert_path" class="form-control" value="<?php echo htmlspecialchars($settings['teams_tls_cert_path']); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label class="form-label u-fs-13 u-fw-600"><?php echo t('ms_teams.tls_key_path'); ?></label>
                        <input type="text" name="teams_tls_key_path" class="form-control" value="<?php echo htmlspecialchars($settings['teams_tls_key_path']); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                    </div>
                </div>

                <!-- Certificate inspection badge -->
                <div class="cert-card">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <span class="badge badge-<?php echo htmlspecialchars($cert['color'] ?? 'secondary'); ?>" style="font-size: 12px; padding: 4px 8px;">
                                <?php echo htmlspecialchars($cert_badge_text); ?>
                            </span>
                            <?php if (!empty($cert['cn'])): ?>
                                <strong class="u-ml-8 u-fs-13">CN: <?php echo htmlspecialchars($cert['cn']); ?></strong>
                                <span class="u-muted u-fs-12">(<?php echo t('ms_teams.cert_issuer'); ?>: <?php echo htmlspecialchars($cert['issuer']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($cert['valid_to'])): ?>
                            <div class="u-muted u-fs-12">
                                <?php echo t('ms_teams.cert_valid_until'); ?>: <strong><?php echo htmlspecialchars($cert['valid_to']); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Microsoft PSTN hub proxy info card -->
            <div style="background: rgba(98, 100, 167, 0.05); border: 1px solid rgba(98, 100, 167, 0.2); border-radius: 8px; padding: 16px; margin-bottom: 24px;">
                <h5 style="margin: 0 0 10px 0; color: #464775; font-size: 14px; font-weight: 700;">
                    <i class="fas fa-globe"></i> <?php echo t('ms_teams.proxy_title'); ?>
                </h5>
                <p class="u-fs-12 u-muted u-mb-8">
                    <?php echo t('ms_teams.proxy_desc'); ?>
                </p>
                <div style="display: flex; flex-wrap: wrap; gap: 12px; font-size: 13px;">
                    <code style="background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">sip.pstnhub.microsoft.com:5061</code>
                    <code style="background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">sip2.pstnhub.microsoft.com:5061</code>
                    <code style="background: #ffffff; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0;">sip3.pstnhub.microsoft.com:5061</code>
                </div>
            </div>

            <?php if ($can_edit): ?>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-teams" style="padding: 10px 24px; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-save"></i> <?php echo t('ms_teams.save_dr_settings'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ========================================== -->
    <!-- TAB 2: User mapping                        -->
    <!-- ========================================== -->
    <div id="tab-users" class="teams-tab-pane <?php echo $active_tab === 'users' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h4 class="u-title u-m-0"><?php echo t('ms_teams.users_title'); ?></h4>
                <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">
                    <?php echo t('ms_teams.users_desc'); ?>
                </p>
            </div>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-teams btn-sm" onclick="openMappingModal()">
                    <i class="fas fa-plus"></i> <?php echo t('ms_teams.btn_new_mapping'); ?>
                </button>
            <?php endif; ?>
        </div>

        <div class="table-responsive" style="border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px;">
            <table class="table mapping-table u-mb-0">
                <thead style="background: var(--bg-surface, #f8fafc);">
                    <tr>
                        <th style="width: 140px;"><?php echo t('ms_teams.col_ext'); ?></th>
                        <th><?php echo t('ms_teams.col_user_name'); ?></th>
                        <th><?php echo t('ms_teams.col_upn'); ?></th>
                        <th><?php echo t('ms_teams.col_phone'); ?></th>
                        <th style="text-align: center; width: 100px;"><?php echo t('ms_teams.col_direct_route'); ?></th>
                        <th><?php echo t('ms_teams.col_notes'); ?></th>
                        <?php if ($can_edit || $can_delete): ?>
                            <th style="text-align: right; width: 120px;"><?php echo t('ms_teams.col_actions'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mappings)): ?>
                        <tr>
                            <td colspan="7" class="u-text-center u-p-30 u-muted">
                                <i class="fas fa-user-slash" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                                <?php echo t('ms_teams.empty_mappings'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mappings as $m): ?>
                            <tr id="mapping-row-<?php echo $m['id']; ?>">
                                <td>
                                    <span style="font-family: monospace; font-size: 14px; font-weight: 700; color: #6264a7;">
                                        <i class="fas fa-phone-alt u-fs-11"></i> <?php echo htmlspecialchars($m['extension']); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($m['full_name'] ?: '-'); ?></strong>
                                </td>
                                <td>
                                    <i class="fab fa-microsoft" style="color: #6264a7; margin-right: 4px;"></i>
                                    <code><?php echo htmlspecialchars($m['teams_upn']); ?></code>
                                </td>
                                <td>
                                    <?php echo !empty($m['phone_number']) ? htmlspecialchars($m['phone_number']) : '<span class="u-muted">-</span>'; ?>
                                </td>
                                <td class="u-text-center">
                                    <?php if (!empty($m['direct_routing_enabled'])): ?>
                                        <span class="badge badge-success u-fs-11"><?php echo t('ms_teams.status_active'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary u-fs-11"><?php echo t('ms_teams.status_inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="u-muted u-fs-13">
                                    <?php echo htmlspecialchars($m['notes'] ?: '-'); ?>
                                </td>
                                <?php if ($can_edit || $can_delete): ?>
                                    <td class="u-text-right">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <?php if ($can_edit): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick='editMapping(<?php echo json_encode($m, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' title="<?php echo t('ms_teams.btn_edit'); ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteMapping(<?php echo (int)$m['id']; ?>, '<?php echo htmlspecialchars($m['extension'], ENT_QUOTES); ?>')" title="<?php echo t('ms_teams.btn_delete'); ?>">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 3: Webhook & Bildirimler            -->
    <!-- ========================================== -->
    <div id="tab-webhooks" class="teams-tab-pane <?php echo $active_tab === 'webhooks' ? 'active' : ''; ?>">
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
            <input type="hidden" name="save_webhook_settings" value="1">

            <div style="background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <h4 style="margin-top: 0; font-size: 15px; font-weight: 700; color: #464775; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-plug"></i> <?php echo t('ms_teams.webhook_section_title'); ?>
                </h4>
                <p class="u-fs-13 u-muted u-mb-16">
                    <?php echo t('ms_teams.webhook_section_desc'); ?>
                </p>

                <div style="display: grid; grid-template-columns: 200px 1fr; gap: 16px; align-items: start;">
                    <div class="form-group">
                        <label class="form-label u-fw-600"><?php echo t('ms_teams.webhook_status'); ?></label>
                        <select name="teams_webhook_enabled" id="webhookEnabledSelect" class="form-control" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                            <option value="1" <?php echo ($settings['teams_webhook_enabled'] === '1') ? 'selected' : ''; ?>><?php echo t('ms_teams.webhook_status_enabled'); ?></option>
                            <option value="0" <?php echo ($settings['teams_webhook_enabled'] === '0') ? 'selected' : ''; ?>><?php echo t('ms_teams.webhook_status_disabled'); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label u-fw-600"><?php echo t('ms_teams.webhook_url'); ?></label>
                        <div class="u-flex-gap">
                            <input type="url" name="teams_webhook_url" id="teamsWebhookUrlInput" class="form-control" value="<?php echo htmlspecialchars($settings['teams_webhook_url']); ?>" placeholder="<?php echo t('ms_teams.webhook_url_placeholder'); ?>" <?php echo !$can_edit ? 'disabled' : ''; ?>>
                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-outline-secondary" id="btnTestWebhook" onclick="testTeamsWebhook()" style="white-space: nowrap;">
                                    <i class="fas fa-paper-plane"></i> <?php echo t('ms_teams.btn_test'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <small class="u-hint"><?php echo t('ms_teams.webhook_url_help'); ?></small>
                    </div>
                </div>

                <div id="webhookTestResult" style="display: none; margin-top: 12px;"></div>
            </div>

            <!-- Notification events (event toggles) -->
            <div style="background: #ffffff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <h4 style="margin-top: 0; font-size: 15px; font-weight: 700; margin-bottom: 16px;">
                    <i class="fas fa-bell u-primary"></i> <?php echo t('ms_teams.events_title'); ?>
                </h4>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_missed_calls" value="1" <?php echo !empty($settings['teams_notify_missed_calls']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong><?php echo t('ms_teams.event_missed_calls'); ?></strong>
                            <div class="u-muted u-fs-12"><?php echo t('ms_teams.event_missed_calls_desc'); ?></div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_voicemail" value="1" <?php echo !empty($settings['teams_notify_voicemail']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong><?php echo t('ms_teams.event_voicemail'); ?></strong>
                            <div class="u-muted u-fs-12"><?php echo t('ms_teams.event_voicemail_desc'); ?></div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_queue_alerts" value="1" <?php echo !empty($settings['teams_notify_queue_alerts']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong><?php echo t('ms_teams.event_queue_alerts'); ?></strong>
                            <div class="u-muted u-fs-12"><?php echo t('ms_teams.event_queue_alerts_desc'); ?></div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_fax" value="1" <?php echo !empty($settings['teams_notify_fax']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong><?php echo t('ms_teams.event_fax'); ?></strong>
                            <div class="u-muted u-fs-12"><?php echo t('ms_teams.event_fax_desc'); ?></div>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="teams_notify_cdr_summary" value="1" <?php echo !empty($settings['teams_notify_cdr_summary']) ? 'checked' : ''; ?> <?php echo !$can_edit ? 'disabled' : ''; ?> style="margin-top: 3px;">
                        <div>
                            <strong><?php echo t('ms_teams.event_cdr_summary'); ?></strong>
                            <div class="u-muted u-fs-12"><?php echo t('ms_teams.event_cdr_summary_desc'); ?></div>
                        </div>
                    </label>
                </div>
            </div>

            <?php if ($can_edit): ?>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-teams" style="padding: 10px 24px; font-size: 14px; font-weight: 600;">
                        <i class="fas fa-save"></i> <?php echo t('ms_teams.save_webhook_settings'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ========================================== -->
    <!-- SEKME 4: M365 PowerShell Rehberi          -->
    <!-- ========================================== -->
    <div id="tab-powershell" class="teams-tab-pane <?php echo $active_tab === 'powershell' ? 'active' : ''; ?>">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h4 class="u-title u-m-0"><?php echo t('ms_teams.ps_title'); ?></h4>
                <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted);">
                    <?php echo t('ms_teams.ps_desc'); ?>
                </p>
            </div>
            <div class="u-flex-gap">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyPowerShellScript()">
                    <i class="fas fa-copy"></i> <?php echo t('ms_teams.btn_copy'); ?>
                </button>
                <a href="/ms-teams?action=download_powershell" class="btn btn-sm btn-teams">
                    <i class="fas fa-download"></i> <?php echo t('ms_teams.btn_download_ps'); ?>
                </a>
            </div>
        </div>

        <div class="code-box" id="powerShellCodeBlock"><?php echo htmlspecialchars($powerShellScript); ?></div>

        <div style="margin-top: 20px; background: var(--bg-surface, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 8px; padding: 18px;">
            <h5 class="u-mt-0 u-fs-14 u-fw-700">
                <i class="fas fa-info-circle u-primary"></i> <?php echo t('ms_teams.ps_steps_title'); ?>
            </h5>
            <ol style="font-size: 13px; line-height: 1.7; margin-bottom: 0; padding-left: 20px;">
                <li><?php echo t('ms_teams.ps_step_1'); ?></li>
                <li><?php echo t('ms_teams.ps_step_2'); ?></li>
                <li><?php echo t('ms_teams.ps_step_3'); ?></li>
                <li><?php echo t('ms_teams.ps_step_4'); ?></li>
            </ol>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- USER MAPPING MODAL                         -->
<!-- ========================================== -->
<div class="modal fade" id="mappingModal" tabindex="-1" style="display: none; background: rgba(0,0,0,0.5); position: fixed; inset: 0; z-index: 9999; overflow-y: auto;">
    <div style="max-width: 540px; margin: 60px auto; background: var(--bg-card, #ffffff); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="padding: 16px 20px; background: #6264a7; color: #ffffff; display: flex; justify-content: space-between; align-items: center;">
            <h5 class="u-title u-m-0" id="mappingModalTitle">
                <i class="fas fa-user-plus"></i> <?php echo t('ms_teams.modal_new_title'); ?>
            </h5>
            <button type="button" onclick="closeMappingModal()" style="background: transparent; border: none; color: #ffffff; font-size: 20px; cursor: pointer;">&times;</button>
        </div>

        <form id="mappingForm" onsubmit="submitMappingForm(event)" style="padding: 20px;">
            <input type="hidden" name="id" id="mapId" value="">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">

            <div class="form-group u-mb-14">
                <label class="form-label u-fw-600"><?php echo t('ms_teams.modal_ext_label'); ?> <span class="u-danger">*</span></label>
                <select name="extension" id="mapExtension" class="form-control" required>
                    <option value=""><?php echo t('ms_teams.modal_select_ext'); ?></option>
                    <?php foreach ($extensions as $ext): ?>
                        <option value="<?php echo htmlspecialchars($ext['extension']); ?>">
                            <?php echo htmlspecialchars($ext['extension'] . ' - ' . $ext['full_name'] . ($ext['email'] ? ' (' . $ext['email'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group u-mb-14">
                <label class="form-label u-fw-600"><?php echo t('ms_teams.modal_upn_label'); ?> <span class="u-danger">*</span></label>
                <input type="email" name="teams_upn" id="mapTeamsUpn" class="form-control" placeholder="<?php echo t('ms_teams.modal_upn_placeholder'); ?>" required>
                <small class="u-muted u-fs-12"><?php echo t('ms_teams.modal_upn_help'); ?></small>
            </div>

            <div class="form-group u-mb-14">
                <label class="form-label u-fw-600"><?php echo t('ms_teams.modal_phone_label'); ?></label>
                <input type="text" name="phone_number" id="mapPhoneNumber" class="form-control" placeholder="<?php echo t('ms_teams.modal_phone_placeholder'); ?>">
                <small class="u-muted u-fs-12"><?php echo t('ms_teams.modal_phone_help'); ?></small>
            </div>

            <div class="form-group u-mb-14">
                <label class="form-label u-fw-600"><?php echo t('ms_teams.modal_dr_label'); ?></label>
                <select name="direct_routing_enabled" id="mapDirectRouting" class="form-control">
                    <option value="1"><?php echo t('ms_teams.modal_dr_enabled'); ?></option>
                    <option value="0"><?php echo t('ms_teams.modal_dr_disabled'); ?></option>
                </select>
            </div>

            <div class="form-group u-mb-18">
                <label class="form-label u-fw-600"><?php echo t('ms_teams.modal_notes_label'); ?></label>
                <input type="text" name="notes" id="mapNotes" class="form-control" placeholder="<?php echo t('ms_teams.modal_notes_placeholder'); ?>">
            </div>

            <div id="modalAlert" style="display: none; margin-bottom: 14px;"></div>

            <div style="display: flex; justify-content: flex-end; gap: 8px;">
                <button type="button" class="btn btn-outline-secondary" onclick="closeMappingModal()"><?php echo t('ms_teams.btn_cancel'); ?></button>
                <button type="submit" class="btn btn-teams" id="btnSaveMapping">
                    <i class="fas fa-save"></i> <?php echo t('ms_teams.btn_save'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
window.MS_TEAMS_I18N = {
    modal_new_title: <?php echo json_encode(t('ms_teams.modal_new_title'), JSON_UNESCAPED_UNICODE); ?>,
    modal_edit_title: <?php echo json_encode(t('ms_teams.modal_edit_title'), JSON_UNESCAPED_UNICODE); ?>,
    btn_save: <?php echo json_encode(t('ms_teams.btn_save'), JSON_UNESCAPED_UNICODE); ?>,
    saving: <?php echo json_encode(t('ms_teams.saving'), JSON_UNESCAPED_UNICODE); ?>,
    sending: <?php echo json_encode(t('ms_teams.sending'), JSON_UNESCAPED_UNICODE); ?>,
    btn_test: <?php echo json_encode(t('ms_teams.btn_test'), JSON_UNESCAPED_UNICODE); ?>,
    delete_confirm: <?php echo json_encode(t('ms_teams.js_delete_confirm'), JSON_UNESCAPED_UNICODE); ?>,
    enter_webhook_url: <?php echo json_encode(t('ms_teams.js_enter_webhook_url'), JSON_UNESCAPED_UNICODE); ?>,
    copied: <?php echo json_encode(t('ms_teams.js_copied'), JSON_UNESCAPED_UNICODE); ?>,
    copy_failed: <?php echo json_encode(t('ms_teams.js_copy_failed'), JSON_UNESCAPED_UNICODE); ?>,
    delete_failed: <?php echo json_encode(t('ms_teams.js_delete_failed'), JSON_UNESCAPED_UNICODE); ?>,
    conn_error: <?php echo json_encode(t('ms_teams.js_conn_error'), JSON_UNESCAPED_UNICODE); ?>,
    test_failed: <?php echo json_encode(t('ms_teams.js_test_failed'), JSON_UNESCAPED_UNICODE); ?>
};
</script>
<script src="<?php echo asset('/assets/js/ms_teams.js'); ?>"></script>
