<div class="card">
    <?php echo uiPageHeaderOpen('fa-phone-alt', t('extensions.title'), 'extHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>

            <?php if (hasModulePermission('extensions', 'edit')): ?>
                <form method="POST" autocomplete="off" class="u-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                    <input type="hidden" name="sync_all_extensions" value="1">
                    <button type="submit" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('extensions.sync_all_tooltip'); ?>">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </form>
                <button class="btn btn-primary btn-sm" onclick="openCreateExtensionModal()" title="<?php echo t('extensions.new_tooltip'); ?>">
                    <i class="fas fa-plus-circle"></i> <?php echo t('extensions.new_tooltip'); ?>
                </button>
            <?php endif; ?>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('extHelpBox', t('extensions.help_title')); ?>
        <?php echo t('extensions.help_body'); ?><br>
        - <strong><?php echo t('extensions.help_wss'); ?></strong><br>
        - <strong><?php echo t('extensions.help_autoconfig'); ?></strong><br>
        - <strong><?php echo t('extensions.help_remove'); ?></strong>
<?php echo uiHelpBoxClose(); ?>

    <?php echo uiTableOpen([
        ['label' => '#', 'class' => 'col-hide-mobile u-w-50'],
        t('extensions.col_extension'),
        t('extensions.col_fullname'),
        ['label' => t('extensions.col_username'), 'class' => 'col-hide-mobile'],
        ['label' => t('extensions.col_type'), 'class' => 'col-hide-mobile'],
        ['label' => t('extensions.col_sip_password'), 'class' => 'col-hide-mobile'],
        ['label' => t('extensions.col_live_status'), 'class' => 'col-hide-mobile'],
        ['label' => t('extensions.col_perm_role'), 'class' => 'col-hide-mobile'],
        ['label' => t('extensions.col_actions'), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($extensions)): ?>
                    <?php echo uiTableEmptyRow(9, t('extensions.empty'), 'fa-phone-alt'); ?>
                <?php else: ?>
                    <?php foreach ($extensions as $e): ?>
                        <?php
                        // Dual endpoint: every extension has two separate PJSIP states,
                        // -sip and -webrtc; the most "alive" of the two (In call > Idle >
                        // Unreachable) is shown (2026-08-19 fix — see AsteriskHelper::getPJSIPStatuses()).
                        $status_priority = ['busy' => 3, 'idle' => 2, 'unknown' => 1, 'down' => 0];
                        $best_status = null;
                        foreach (['-sip', '-webrtc', '-mob-webrtc'] as $suffix) {
                            $cls = AsteriskHelper::classifyStatus($pjsip_statuses[$e['extension'] . $suffix] ?? null);
                            if ($cls !== null && ($best_status === null || $status_priority[$cls] > $status_priority[$best_status])) {
                                $best_status = $cls;
                            }
                        }
                        switch ($best_status) {
                            case 'busy': $status_badge = 'badge-info'; $status_text = t('extensions.status_busy'); break;
                            case 'idle': $status_badge = 'badge-success'; $status_text = t('extensions.status_idle'); break;
                            case 'down': $status_badge = 'badge-warning'; $status_text = t('extensions.status_down'); break;
                            default: $status_badge = 'badge-secondary'; $status_text = t('extensions.status_unknown');
                        }
                        ?>
                        <tr>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $e['id']; ?></td>
                            <td><span class="badge badge-info"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($e['extension']); ?></span></td>
                            <td class="u-strong"><?php echo htmlspecialchars($e['full_name']); ?></td>
                            <td class="col-hide-mobile"><?php echo htmlspecialchars($e['username']); ?></td>
                            <td class="col-hide-mobile">
                                <?php if ($e['extension_type'] === 'fax'): ?>
                                    <span class="badge badge-warning"><i class="fas fa-fax"></i> <?php echo t('extensions.type_fax'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-info"><i class="fas fa-phone-alt"></i> <?php echo t('extensions.type_sip'); ?></span>
                                    <?php if (isset($e['sip_auth_digest']) && (int)$e['sip_auth_digest'] === 0): ?>
                                        <span class="badge badge-warning u-ml-4 u-fs-10" title="<?php echo t('extensions.auth_digest_off_tooltip'); ?>"><i class="fas fa-unlock"></i> <?php echo t('extensions.auth_digest_badge_off'); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary u-ml-4 u-fs-10" title="<?php echo t('extensions.auth_digest_on_tooltip'); ?>"><i class="fas fa-lock"></i> <?php echo t('extensions.auth_digest_badge_on'); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($e['extension_type'] === 'fax'): ?>
                                    <span class="text-muted u-fs-12"><?php echo t('extensions.no_sip_registration'); ?></span>
                                <?php elseif (isset($e['sip_auth_digest']) && (int)$e['sip_auth_digest'] === 0 && empty($e['sip_password'])): ?>
                                    <span class="text-muted u-fs-12 u-font-style-italic"><i class="fas fa-unlock"></i> <?php echo t('extensions.no_auth_required'); ?></span>
                                <?php else: ?>
                                    <div class="u-d-inline-flex u-ai-center u-gap-6">
                                        <code class="u-letter-spacing-1" data-pw="<?php echo htmlspecialchars((string) ($e['sip_password'] ?? '')); ?>">••••••</code>
                                        <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="toggleRowPassword(this)" title="<?php echo t('extensions.show_hide_password_tooltip'); ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="copyRowPassword(this)" title="<?php echo t('extensions.copy_password_tooltip'); ?>">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($e['extension_type'] === 'fax'): ?>
                                    <span class="badge badge-secondary u-opacity-0-6">-</span>
                                <?php else: ?>
                                    <span class="badge <?php echo $status_badge; ?>"><?php echo htmlspecialchars($status_text); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <div class="u-d-flex u-col u-gap-3">
                                    <span class="badge badge-secondary u-fs-10" title="<?php echo t('extensions.dial_group'); ?>">
                                        <i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($e['permission_group_name'] ?? t('extensions.no_restriction')); ?>
                                    </span>
                                    <?php if (!empty($e['boss_secretary_role']) && $e['boss_secretary_role'] !== 'none'): ?>
                                        <?php if ($e['boss_secretary_role'] === 'boss'): ?>
                                            <span class="badge badge-warning u-fs-10" title="<?php echo t('extensions.boss_t'); ?>"><i class="fas fa-crown"></i> <?php echo t('extensions.boss'); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge-info u-fs-10" title="<?php echo t('extensions.secretary'); ?>"><i class="fas fa-user-tie"></i> <?php echo t('extensions.secretary'); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (($e['extension_type'] ?? 'sip') !== 'fax' && (int)($e['voicemail_enabled'] ?? 1) === 1): ?>
                                        <span class="badge badge-primary u-fs-10 u-opacity-0-8" title="<?php echo t('extensions.vm_box'); ?>"><i class="fas fa-voicemail"></i> VM</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-right">
                                <?php // Status toggle in the pinned actions column: as its own column it ended up under that column whenever the table was wider than the screen. ?>
                                <?php if (($e['extension_type'] ?? 'sip') === 'sip' && hasModulePermission('phones', 'view')): ?>
                                    <a class="btn btn-icon btn-secondary btn-sm" href="/phone-keys?user=<?php echo (int) $e['id']; ?>" title="<?php echo t('phones.keys_title'); ?>"><i class="fas fa-th"></i></a>
                                <?php endif; ?>
                                <?php echo uiStatusToggleForm($e['id'], $e['is_active'], 'user_id'); ?>
                                <?php echo uiRowActions($e, 'openEditExtensionModal', 'user_id', 'remove_extension', sprintf(t('extensions.remove_confirm'), $e['full_name'], $e['extension']), t('extensions.remove_action_title'), 'fa-phone-slash'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>

<!-- Create / Edit Extension Modal -->
<div class="modal-overlay" id="extensionModal">
    <div class="modal-card u-maxw-680">
        <div class="modal-header">
            <h3 class="u-title" id="extensionModalTitle"><i class="fas fa-plus-circle u-primary"></i> <?php echo t('extensions.modal_new_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeExtensionModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_extension" value="1">
                <input type="hidden" name="user_id" id="modal_user_id" value="">

                <!-- Modal Tabs -->
                <div class="modal-tabs">
                    <button type="button" class="modal-tab-btn active ext-tab-btn" data-tab="basic" onclick="switchExtensionTab('basic', this)"><i class="fas fa-user"></i> <?php echo t('extensions.tab_basic'); ?></button>
                    <button type="button" class="modal-tab-btn ext-tab-btn" data-tab="call_routing" onclick="switchExtensionTab('call_routing', this)"><i class="fas fa-phone-volume"></i> <?php echo t('extensions.tab_call_routing'); ?></button>
                    <button type="button" class="modal-tab-btn ext-tab-btn" data-tab="groups" onclick="switchExtensionTab('groups', this)"><i class="fas fa-users-cog"></i> <?php echo t('extensions.tab_groups'); ?></button>
                    <button type="button" class="modal-tab-btn ext-tab-btn" data-tab="voicemail" onclick="switchExtensionTab('voicemail', this)"><i class="fas fa-voicemail"></i> <?php echo t('extensions.tab_voicemail'); ?></button>
                    <button type="button" class="modal-tab-btn ext-tab-btn" data-tab="sip_advanced" onclick="switchExtensionTab('sip_advanced', this)"><i class="fas fa-network-wired"></i> <?php echo t('extensions.tab_sip_advanced'); ?></button>
                </div>

                <!-- TAB 1: Basic -->
                <div id="ext_tab_basic" class="ext-tab-pane">
                    <div class="u-d-grid u-gtc-2fr-1fr u-gap-16">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.field_fullname'); ?></label>
                            <input type="text" name="full_name" id="modal_full_name" class="form-control" placeholder="<?php echo t('extensions.field_fullname_placeholder'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.field_extension'); ?></label>
                            <input type="text" name="extension" id="modal_extension" class="form-control" placeholder="<?php echo t('extensions.field_extension_placeholder'); ?>" pattern="[0-9]{3,6}" required>
                        </div>
                    </div>

                    <input type="hidden" name="extension_type" id="modal_extension_type" value="sip">
                    <div class="form-group u-fs-12 text-muted u-mt-8 u-mb-8"><i class="fas fa-fax"></i> <?php echo t('extensions.fax_moved'); ?> <a href="/fax-settings"><?php echo t('fax_settings.title'); ?></a></div>

                    <div id="sip_password_group" class="form-group u-mt-8">
                        <label class="form-label u-d-flex u-jc-space-between u-ai-center u-mb-8">
                            <span><?php echo t('extensions.field_sip_password'); ?></span>
                            <span class="u-d-inline-flex u-gap-6">
                                <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="document.getElementById('modal_sip_password').value = generateSipPassword(); document.getElementById('modal_sip_password').type = 'text';" title="<?php echo t('extensions.gen_password_tooltip'); ?>">
                                    <i class="fas fa-magic"></i>
                                </button>
                                <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="toggleSipPasswordField()" title="<?php echo t('extensions.toggle_password_tooltip'); ?>">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </span>
                        </label>
                        <input type="password" name="sip_password" id="modal_sip_password" autocomplete="new-password" class="form-control" minlength="6" placeholder="<?php echo t('extensions.sip_password_placeholder'); ?>" required>
                    </div>

                    <div class="u-grid-2 u-mt-12">
                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.field_cid_internal'); ?></label>
                            <input type="text" name="cid_internal" id="modal_cid_internal" class="form-control" placeholder="<?php echo t('extensions.cid_placeholder'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.field_cid_external'); ?></label>
                            <input type="text" name="cid_external" id="modal_cid_external" class="form-control" placeholder="<?php echo t('extensions.cid_placeholder'); ?>">
                        </div>
                    </div>
                    <small class="u-muted u-fs-11 u-mt-8-2 u-mb-12" style="display: block;"><?php echo t('extensions.cid_help'); ?></small>

                    <div class="form-group u-mt-12">
                        <label class="form-label u-check-label">
                            <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked class="u-check">
                            <span class="u-fw-600"><?php echo t('extensions.field_active'); ?></span>
                        </label>
                    </div>
                </div>

                <!-- TAB 2: Call Rules & Forwarding -->
                <div id="ext_tab_call_routing" class="ext-tab-pane" style="display:none;">
                    <div class="card u-bg-bg-input u-p-14 u-r-12 u-mb-16 u-bd-1-solid-border-color">
                        <div class="u-flex-between">
                            <div>
                                <span class="u-fw-700 u-fs-13 u-text-main"><i class="fas fa-ban text-danger"></i> <?php echo t('extensions.field_dnd'); ?></span>
                                <small class="u-hint u-fs-11" style="display:block;"><?php echo t('extensions.dnd_help'); ?></small>
                            </div>
                            <label class="u-d-inline-flex u-ai-center u-gap-6 u-fs-12 u-pointer u-m-0">
                                <input type="hidden" name="dnd_enabled" value="0">
                                <input type="checkbox" name="dnd_enabled" id="modal_dnd_enabled" value="1" class="u-accent">
                                <span><?php echo t('common.active'); ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group u-mb-16">
                        <label class="form-label"><i class="fas fa-stopwatch u-primary"></i> <?php echo t('extensions.cf_noanswer_timeout'); ?></label>
                        <input type="number" name="cf_noanswer_timeout" id="modal_cf_noanswer_timeout" class="form-control" min="5" max="120" value="20" placeholder="20">
                        <small class="u-hint u-fs-11"><?php echo t('extensions.cf_noanswer_timeout_help'); ?></small>
                    </div>

                    <div class="form-group u-mb-16">
                        <label class="form-label"><i class="fas fa-share text-primary"></i> <?php echo t('extensions.cf_always'); ?></label>
                        <input type="text" name="call_forward_number" id="modal_call_forward_number" class="form-control" placeholder="1002 veya 0532xxxxxxx">
                        <small class="u-hint u-fs-11"><?php echo t('extensions.cf_always_help'); ?></small>
                    </div>

                    <div class="u-grid-2 u-gap-16">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-phone-slash text-warning"></i> <?php echo t('extensions.cf_busy'); ?></label>
                            <input type="text" name="cf_busy_number" id="modal_cf_busy_number" class="form-control" placeholder="1002 veya 0532xxxxxxx">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.cf_busy_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-phone-volume text-info"></i> <?php echo t('extensions.cf_noanswer'); ?></label>
                            <input type="text" name="cf_noanswer_number" id="modal_cf_noanswer_number" class="form-control" placeholder="1002 veya 0532xxxxxxx">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.cf_noanswer_help'); ?></small>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: Groups & Permissions -->
                <div id="ext_tab_groups" class="ext-tab-pane" style="display:none;">
                    <div class="u-grid-2 u-gap-16">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-route u-primary"></i> <?php echo t('extensions.field_outbound_group'); ?></label>
                            <input type="number" name="outbound_group" id="modal_outbound_group" class="form-control" value="1" min="1" max="99" required>
                            <small class="u-hint u-fs-11"><?php echo t('extensions.outbound_group_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-hand-holding-water text-success"></i> <?php echo t('extensions.field_pickup_group'); ?></label>
                            <input type="text" name="pickup_group" id="modal_pickup_group" class="form-control" placeholder="sales, tech">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.pickup_group_help'); ?></small>
                        </div>
                    </div>

                    <div class="u-grid-2 u-gap-16 u-mt-12">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-shield-alt text-info"></i> <?php echo t('extensions.field_permission_group'); ?></label>
                            <select name="permission_group_id" id="modal_permission_group_id" class="form-control">
                                <?php foreach ($permission_groups ?? [] as $pg): ?>
                                    <option value="<?php echo $pg['id']; ?>"><?php echo htmlspecialchars($pg['group_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-user-tie text-warning"></i> <?php echo t('extensions.field_boss_secretary_group'); ?></label>
                            <select name="boss_secretary_group_id" id="modal_boss_secretary_group_id" class="form-control">
                                <option value=""><?php echo t('extensions.no_group'); ?></option>
                                <?php foreach ($boss_secretary_groups ?? [] as $bsg): ?>
                                    <option value="<?php echo $bsg['id']; ?>"><?php echo htmlspecialchars($bsg['group_name']); ?> (<?php echo t('common.group'); ?> <?php echo $bsg['group_number']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: Voicemail -->
                <div id="ext_tab_voicemail" class="ext-tab-pane" style="display:none;">
                    <div class="card u-bg-bg-input u-p-16 u-r-12 u-bd-1-solid-border-color" id="voicemail_settings_group">
                        <div class="u-flex-between u-mb-10">
                            <span class="u-fw-700 u-fs-13 u-text-main"><i class="fas fa-voicemail u-primary"></i> <?php echo t('my_phone.vm_settings_title'); ?></span>
                            <label class="u-d-inline-flex u-ai-center u-gap-6 u-fs-12 u-pointer u-m-0">
                                <input type="hidden" name="voicemail_enabled" value="0">
                                <input type="checkbox" name="voicemail_enabled" id="modal_voicemail_enabled" value="1" checked class="u-accent">
                                <span><?php echo t('extensions.vm_enabled'); ?></span>
                            </label>
                        </div>
                        <div class="u-d-grid u-gtc-1fr-1fr u-gap-12">
                            <div class="form-group u-mb-8">
                                <label class="form-label u-fs-11"><?php echo t('my_phone.vm_pin'); ?></label>
                                <input type="text" name="voicemail_pin" id="modal_voicemail_pin" class="form-control u-fs-12" placeholder="<?php echo t('extensions.vm_pin_ph'); ?>">
                            </div>
                            <div class="form-group u-mb-8">
                                <label class="form-label u-fs-11"><?php echo t('my_phone.vm_email'); ?></label>
                                <input type="email" name="voicemail_email" id="modal_voicemail_email" class="form-control u-fs-12" placeholder="name@example.com">
                            </div>
                        </div>
                        <div class="u-d-flex u-gap-12 u-wrap u-mt-8 u-fs-11-5">
                            <input type="hidden" name="voicemail_email_notify" value="0">
                            <label class="u-d-inline-flex u-ai-center u-gap-4 u-pointer">
                                <input type="checkbox" name="voicemail_email_notify" id="modal_voicemail_email_notify" value="1" checked class="u-accent">
                                <span><?php echo t('extensions.vm_email_notify'); ?></span>
                            </label>
                            <input type="hidden" name="voicemail_attach_audio" value="0">
                            <label class="u-d-inline-flex u-ai-center u-gap-4 u-pointer">
                                <input type="checkbox" name="voicemail_attach_audio" id="modal_voicemail_attach_audio" value="1" checked class="u-accent">
                                <span><?php echo t('extensions.vm_attach'); ?></span>
                            </label>
                        </div>
                        <div class="u-d-flex u-gap-12 u-wrap u-mt-12 u-fs-11-5">
                            <label class="u-d-inline-flex u-ai-center u-gap-4 u-pointer">
                                <input type="checkbox" name="vm_on_noanswer" id="modal_vm_on_noanswer" value="1" class="u-accent">
                                <span><?php echo t('extensions.vm_noanswer'); ?></span>
                            </label>
                            <label class="u-d-inline-flex u-ai-center u-gap-4 u-pointer">
                                <input type="checkbox" name="vm_on_busy" id="modal_vm_on_busy" value="1" class="u-accent">
                                <span><?php echo t('extensions.vm_busy'); ?></span>
                            </label>
                            <label class="u-d-inline-flex u-ai-center u-gap-4 u-pointer">
                                <input type="checkbox" name="vm_on_unavail" id="modal_vm_on_unavail" value="1" class="u-accent">
                                <span><?php echo t('extensions.vm_unavail'); ?></span>
                            </label>
                            <label class="u-d-inline-flex u-ai-center u-gap-4 u-pointer">
                                <input type="checkbox" name="vm_always" id="modal_vm_always" value="1" class="u-accent">
                                <span><?php echo t('extensions.vm_always'); ?></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: Advanced SIP & Media -->
                <div id="ext_tab_sip_advanced" class="ext-tab-pane" style="display:none;">
                    <div class="u-grid-2 u-gap-16">
                        <div class="form-group" id="sip_auth_digest_group">
                            <label class="form-label"><?php echo t('extensions.field_auth_digest'); ?></label>
                            <select name="sip_auth_digest" id="modal_sip_auth_digest" class="form-control" onchange="toggleAuthDigestFields()">
                                <option value="1"><?php echo t('extensions.auth_digest_enabled'); ?></option>
                                <option value="0"><?php echo t('extensions.auth_digest_disabled'); ?></option>
                            </select>
                            <small class="u-hint u-fs-11"><?php echo t('extensions.auth_digest_help'); ?></small>
                        </div>

                        <div class="form-group" id="sip_send_pai_group">
                            <input type="hidden" name="sip_send_pai" value="0">
                            <label class="u-check-label u-mt-14">
                                <input type="checkbox" name="sip_send_pai" id="modal_sip_send_pai" value="1" class="u-accent" checked>
                                <?php echo t('extensions.field_send_pai'); ?>
                            </label>
                            <small class="u-hint u-fs-11" style="display: block;"><?php echo t('extensions.send_pai_help'); ?></small>
                        </div>
                    </div>

                    <div class="u-grid-2 u-gap-16 u-mt-12" id="sip_options_group">
                        <?php
                        $sipSelect = static function (string $name, string $label, array $values, string $help = ''): void {
                            echo '<div class="form-group"><label class="form-label">' . t($label) . '</label>';
                            echo '<select name="adv_' . $name . '" id="modal_adv_' . $name . '" class="form-control sip-option"><option value="">' . t('extensions.sip_default') . '</option>';
                            foreach ($values as $value => $text) {
                                echo '<option value="' . htmlspecialchars((string) $value) . '">' . htmlspecialchars((string) $text) . '</option>';
                            }
                            echo '</select>' . ($help !== '' ? '<small class="u-hint u-fs-11">' . t($help) . '</small>' : '') . '</div>';
                        };
                        $yesNo = ['yes' => t('common.yes'), 'no' => t('common.no')];
                        $sipSelect('transport', 'extensions.sip_transport', ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS'], 'extensions.sip_transport_help');
                        $sipSelect('dtmf_mode', 'extensions.sip_dtmf', ['rfc4733' => 'RFC 4733', 'inband' => 'Inband', 'info' => 'SIP INFO', 'auto' => 'Auto']);
                        $sipSelect('media_encryption', 'extensions.sip_srtp', ['no' => t('extensions.sip_srtp_off'), 'sdes' => 'SRTP (SDES)', 'dtls' => 'DTLS-SRTP (WebRTC)'], 'extensions.sip_srtp_help');
                        $sipSelect('media_encryption_optimistic', 'extensions.sip_media_encryption_optimistic', $yesNo, 'extensions.sip_media_encryption_optimistic_help');
                        $sipSelect('ice_support', 'extensions.sip_ice_support', $yesNo, 'extensions.sip_ice_support_help');
                        $sipSelect('direct_media', 'extensions.sip_direct_media', $yesNo);
                        $sipSelect('force_rport', 'extensions.sip_force_rport', $yesNo);
                        $sipSelect('rewrite_contact', 'extensions.sip_rewrite_contact', $yesNo);
                        $sipSelect('rtp_symmetric', 'extensions.sip_rtp_symmetric', $yesNo);
                        ?>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_media_address'); ?></label>
                            <input type="text" name="adv_media_address" id="modal_adv_media_address" class="form-control sip-option" placeholder="192.168.1.10">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.sip_media_address_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_codecs'); ?></label>
                            <input type="text" name="adv_allow" id="modal_adv_allow" class="form-control sip-option" placeholder="opus,g722,alaw,ulaw" maxlength="60">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.sip_codecs_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_max_contacts'); ?></label>
                            <input type="number" name="max_contacts" id="modal_max_contacts" class="form-control sip-option" min="1" max="50">
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_qualify'); ?></label>
                            <input type="number" name="adv_qualify_frequency" id="modal_adv_qualify_frequency" class="form-control sip-option" min="0" max="3600">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.sip_qualify_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_rtp_timeout'); ?></label>
                            <input type="number" name="adv_rtp_timeout" id="modal_adv_rtp_timeout" class="form-control sip-option" min="0" max="3600" placeholder="0">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.sip_rtp_timeout_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_rtp_keepalive'); ?></label>
                            <input type="number" name="adv_rtp_keepalive" id="modal_adv_rtp_keepalive" class="form-control sip-option" min="0" max="3600" placeholder="0">
                            <small class="u-hint u-fs-11"><?php echo t('extensions.sip_rtp_keepalive_help'); ?></small>
                        </div>

                        <div class="form-group">
                            <label class="form-label"><?php echo t('extensions.sip_language'); ?></label>
                            <input type="text" name="adv_language" id="modal_adv_language" class="form-control sip-option" placeholder="tr" maxlength="5">
                        </div>
                    </div>
                </div>

                <?php echo uiModalFooter('closeExtensionModal()', t('common.save'), '', 'fa-save'); ?>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/extensions.js'); ?>"></script>
