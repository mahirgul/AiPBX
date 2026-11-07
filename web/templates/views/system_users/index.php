<?php if (!empty($generated_password)): ?>
<div class="card u-bd-2-solid-warning u-mb-16">
    <div class="u-p-16-20 u-d-flex u-wrap u-ai-center u-gap-16">
        <i class="fas fa-key u-fs-24 u-warning"></i>
        <div class="u-flex-1 u-minw-220">
            <div class="u-fw-700 u-mb-4"><?php echo t('system_users.generated_password_title'); ?> — <?php echo htmlspecialchars($generated_for); ?></div>
            <div class="u-fs-12-5 u-muted"><?php echo t('system_users.generated_password_hint'); ?></div>
        </div>
        <code class="u-fs-18 u-fw-700 u-letter-spacing-1 u-p-8-16 u-r-8 u-bg-bg-main u-user-select-all"><?php echo htmlspecialchars($generated_password); ?></code>
        <button type="button" class="btn btn-secondary btn-sm" data-pw="<?php echo htmlspecialchars($generated_password); ?>"
                onclick="UIHelper.copyToClipboard(this.dataset.pw, <?php echo htmlspecialchars(json_encode(t('system_users.generated_password_copied')), ENT_QUOTES); ?>);">
            <i class="fas fa-copy"></i> <?php echo t('system_users.generated_password_copy'); ?>
        </button>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($import_preview)): ?>
<div class="card u-mb-16 u-bd-1-solid-primary">
    <?php echo uiPageHeaderOpen('fa-file-csv', t('system_users.import_preview_title')); ?>
— <?php echo htmlspecialchars($import_preview['file_name']); ?>
<?php echo uiPageHeaderActions(); ?>
<?php echo uiPageHeaderClose(); ?>
    <div class="u-p-0-16-16-16">
        <?php
        $total = count($import_preview['items']);
        $bad = $total - $import_preview['valid_count'];
        ?>
        <p class="u-fs-13 u-m-0-0-12-0">
            <?php echo sprintf(t('system_users.import_preview_summary'), $total, $import_preview['valid_count'], $bad); ?>
            <?php if ($import_preview['send_invitations']): ?><br><i class="fas fa-envelope"></i> <?php echo t('system_users.import_will_invite'); ?><?php endif; ?>
        </p>
        <?php echo uiTableOpen([
            '#',
            t('system_users.field_username'),
            t('system_users.field_fullname'),
            t('system_users.field_email'),
            t('system_users.import_col_extension'),
            t('system_users.field_role'),
            t('system_users.import_col_status'),
        ], ['frame' => 'u-maxh-420 u-of-auto']); ?>
                <?php foreach ($import_preview['items'] as $line => $item): $r = $item['row']; ?>
                    <tr style="<?php echo $item['errors'] ? 'background: rgba(239,68,68,0.08);' : ''; ?>">
                        <td><?php echo (int) $line; ?></td>
                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                        <td><?php echo htmlspecialchars($r['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['email']); ?></td>
                        <td><?php echo htmlspecialchars($r['extension']); ?></td>
                        <td><?php echo htmlspecialchars($r['role']); ?></td>
                        <td>
                            <?php if ($item['errors']): ?>
                                <span class="u-danger u-fs-12"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars(implode('; ', $item['errors'])); ?></span>
                            <?php else: ?>
                                <span class="u-success u-fs-12"><i class="fas fa-check-circle"></i> <?php echo t('system_users.import_row_ok'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php echo uiTableClose(); ?>
        <div class="u-d-flex u-gap-8 u-jc-flex-end u-mt-14">
            <a href="/system-users" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> <?php echo t('system_users.import_cancel'); ?></a>
            <?php if ($import_preview['valid_count'] > 0): ?>
            <form method="POST" class="u-m-0" onsubmit="this.querySelector('button').disabled = true;">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="csv_import" value="1">
                <input type="hidden" name="import_key" value="<?php echo htmlspecialchars($import_preview['key']); ?>">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-file-import"></i> <?php echo sprintf(t('system_users.import_confirm'), $import_preview['valid_count']); ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if (!empty($import_result)): ?>
<div class="card u-mb-16 u-bd-1-solid-success">
    <div class="card-header">
        <div class="card-title"><i class="fas fa-check-circle u-success"></i> <?php echo t('system_users.import_result_title'); ?></div>
    </div>
    <div class="u-p-0-16-16-16 u-fs-13">
        <p class="u-m-0-0-12-0"><?php echo sprintf(t('system_users.import_result_summary'), $import_result['created'], count($import_result['failed']), $import_result['invited']); ?></p>
        <?php if ($import_result['failed']): ?>
            <div class="u-danger u-mb-12">
                <?php foreach ($import_result['failed'] as $line => $f): ?>
                    <div><i class="fas fa-times-circle"></i> #<?php echo (int) $line; ?> <?php echo htmlspecialchars($f['username']); ?>: <?php echo htmlspecialchars($f['error']); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($import_result['generated']): ?>
            <div class="u-bd-2-solid-warning u-r-12 u-p-12-16">
                <div class="u-fw-700 u-mb-4"><i class="fas fa-key u-warning"></i> <?php echo t('system_users.import_generated_title'); ?></div>
                <div class="u-muted u-mb-10"><?php echo t('system_users.generated_password_hint'); ?></div>
                <?php echo uiTableOpen([
                    t('system_users.field_username'),
                    t('system_users.field_fullname'),
                    t('system_users.import_col_extension'),
                    t('system_users.field_password'),
                ]); ?>
                    <?php
                    $csvOut = "\xEF\xBB\xBFkullanici_adi;ad_soyad;dahili;sifre\r\n";
                    foreach ($import_result['generated'] as $g):
                        $csvOut .= implode(';', array_map(fn($v) => str_replace([';', "\r", "\n"], ' ', (string) $v), [$g['username'], $g['full_name'], $g['extension'], $g['password']])) . "\r\n";
                    ?>
                        <tr><td><?php echo htmlspecialchars($g['username']); ?></td><td><?php echo htmlspecialchars($g['full_name']); ?></td><td><?php echo htmlspecialchars($g['extension']); ?></td><td><code class="u-user-select-all"><?php echo htmlspecialchars($g['password']); ?></code></td></tr>
                    <?php endforeach; ?>
                    <?php echo uiTableClose(); ?>
                <a class="btn btn-secondary btn-sm" download="olusturulan_sifreler.csv" href="data:text/csv;charset=utf-8;base64,<?php echo base64_encode($csvOut); ?>">
                    <i class="fas fa-download"></i> <?php echo t('system_users.import_download_passwords'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<div class="card">
    <?php echo uiPageHeaderOpen('fa-users-cog', t('system_users.title'), 'userHelpBox'); ?>
<?php echo uiPageHeaderActions(); ?>
<button type="button" class="btn btn-secondary btn-sm" onclick="openRolesModal()" title="<?php echo t('system_users.roles_tooltip'); ?>">
                <i class="fas fa-user-tag"></i> <?php echo t('system_users.roles_tooltip'); ?>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openUserImportModal()" title="<?php echo t('system_users.import_title'); ?>">
                <i class="fas fa-file-csv"></i> <?php echo t('system_users.import_button'); ?>
            </button>
            <button class="btn btn-primary btn-sm" onclick="openCreateUserModal()" title="<?php echo t('system_users.new_tooltip'); ?>">
                <i class="fas fa-user-plus"></i> <?php echo t('system_users.new_tooltip'); ?>
            </button>
<?php echo uiPageHeaderClose(); ?>

        <?php echo uiHelpBoxOpen('userHelpBox', t('system_users.help_title')); ?>
        <?php echo t('system_users.help_body'); ?><br>
        - <strong><?php echo t('system_users.help_admin'); ?></strong><br>
        - <strong><?php echo t('system_users.help_cc_agent'); ?></strong><br>
        - <strong><?php echo t('system_users.help_fax_user'); ?></strong>
<?php echo uiHelpBoxClose(); ?>

    <!-- Bulk Actions Toolbar -->
    <div class="u-bg-rgba-37-99-235-0-08 u-bd-1-solid-rgba-37-99-235-0-25 u-r-8 u-p-12-16 u-m-12-16 u-ai-center u-jc-space-between" id="bulkActionBar" style="display: none;">
        <div class="u-fs-13 u-fw-600 u-primary u-d-flex u-ai-center u-gap-8">
            <i class="fas fa-check-square"></i>
            <span id="bulkSelectedCount">0</span> <?php echo t('system_users.users_selected'); ?>
        </div>
        <form method="POST" id="bulkMailForm" class="u-m-0" onsubmit="return confirmBulkSendMail();">
            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
            <input type="hidden" name="bulk_send_activation_mail" value="1">
            <input type="hidden" name="selected_users" id="bulkSelectedUsersInput" value="">
            <button type="submit" class="btn btn-primary btn-sm" id="btnBulkSendMail">
                <i class="fas fa-paper-plane"></i> <?php echo t('system_users.bulk_mail_btn'); ?>
            </button>
        </form>
    </div>

    <?php echo uiTableOpen([
        ['label' => '<input class="u-pointer u-accent" type="checkbox" id="selectAllUsers" onchange="toggleSelectAllUsers(this)" title="' . t('system_users.select_all') . '">', 'class' => 'u-w-36 u-ta-center'],
        ['label' => '#', 'class' => 'col-hide-mobile u-w-50'],
        ['label' => t('system_users.col_user'), 'class' => 'col-hide-mobile'],
        t('system_users.col_fullname'),
        ['label' => t('system_users.col_email'), 'class' => 'col-hide-mobile'],
        t('system_users.col_role'),
        t('system_users.col_extension'),
        ['label' => t('system_users.col_permissions'), 'class' => 'col-hide-mobile'],
        '2FA',
        t('system_users.col_status'),
        ['label' => t('system_users.col_actions'), 'class' => 'text-right'],
    ]); ?>
                <?php if (empty($users)): ?>
                    <?php echo uiTableEmptyRow(11, t('system_users.empty'), 'fa-users-cog'); ?>
                <?php else: ?>
                    <?php foreach ($users as $u):
                        $role_label = htmlspecialchars($u['role_name'] ?? $u['role']);
                        $role_class = 'role-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $u['role']);
                    ?>
                        <tr>
                            <td class="u-text-center">
                                <input type="checkbox" class="user-select-cb u-pointer u-accent" value="<?php echo $u['id']; ?>" data-has-email="<?php echo !empty($u['email']) ? '1' : '0'; ?>" onchange="updateBulkActionState()">
                            </td>
                            <td class="col-hide-mobile text-muted u-fs-12">#<?php echo $u['id']; ?></td>
                            <td class="col-hide-mobile u-strong">
                                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($u['username']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td class="col-hide-mobile"><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                            <td>
                                <span class="role-badge <?php echo $role_class; ?>">
                                    <?php echo $role_label; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($u['extension'])): ?>
                                    <span class="badge badge-info"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($u['extension']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-hide-mobile">
                                <?php if ($u['can_listen_recordings']): ?>
                                    <span class="badge badge-success u-mr-4"><i class="fas fa-headphones"></i> <?php echo t('system_users.perm_recordings'); ?></span>
                                <?php endif; ?>
                                <?php if ($u['can_view_all_cdrs']): ?>
                                    <span class="badge badge-warning"><i class="fas fa-list-alt"></i> <?php echo t('system_users.perm_all_cdr'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($u['two_factor_enabled'])): ?>
                                    <span class="badge u-bg-rgba-34-197-94-0-15 u-success u-fw-700 u-fs-11" title="<?php echo t('system_users.2fa_active_tooltip'); ?>">
                                        <i class="fas fa-lock"></i> <?php echo t('common.active'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge u-bg-rgba-148-163-184-0-15 u-muted u-fs-11" title="<?php echo t('system_users.2fa_inactive_tooltip'); ?>">
                                        <i class="fas fa-unlock-alt"></i> <?php echo t('system_users.twofa_off'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo uiStatusToggleForm($u['id'], $u['is_active'], 'user_id'); ?>
                            </td>
                            <td class="text-right">
                                <div class="table-actions-cell">
                                    <?php if (!empty($u['email'])): ?>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo jsArg(sprintf(t('system_users.send_mail_confirm'), $u['username'])); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="send_activation_mail" value="1">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-icon btn-secondary btn-sm u-primary" title="<?php echo t('system_users.send_mail_tooltip'); ?>">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-icon btn-secondary btn-sm u-opacity-0-35 u-cur-not-allowed" disabled title="<?php echo t('system_users.no_email_tooltip'); ?>">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!empty($u['two_factor_enabled'])): ?>
                                        <form method="POST" class="u-inline" onsubmit="return confirm(<?php echo jsArg(sprintf(t('system_users.reset_2fa_confirm'), $u['username'])); ?>);">
                                            <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                                            <input type="hidden" name="reset_2fa" value="1">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-icon btn-secondary btn-sm u-warning" title="<?php echo t('system_users.reset_2fa_tooltip'); ?>">
                                                <i class="fas fa-shield-alt"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-icon btn-secondary btn-sm" onclick='openResetUserModal(<?php echo $u['id']; ?>, "<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>")' title="<?php echo t('system_users.reset_password_tooltip'); ?>">
                                        <i class="fas fa-key"></i>
                                    </button>
                                    <?php echo uiEditButton($u, 'openEditUserModal'); ?>
                                    <?php if ((int) $u['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?>
                                        <?php echo uiDeleteForm($u['id'], 'user_id', 'delete_user', sprintf(t('system_users.delete_confirm'), $u['username'])); ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php echo uiTableClose(); ?>
</div>

<!-- Create / Edit User Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal-card u-maxw-540">
        <div class="modal-header">
            <h3 class="u-title" id="userModalTitle"><i class="fas fa-user-plus u-primary"></i> <?php echo t('system_users.modal_new_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeUserModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_system_user" value="1">
                <input type="hidden" name="user_id" id="modal_user_id" value="0">

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_username'); ?></label>
                        <input type="text" name="username" id="modal_username" class="form-control" required placeholder="<?php echo t('system_users.field_username_placeholder'); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_extension'); ?></label>
                        <input type="text" name="extension" id="modal_extension" class="form-control" placeholder="<?php echo t('common.eg'); ?> 3001">
                        <small class="u-muted u-fs-11"><?php echo t('system_users.extension_help'); ?></small>
                </div>

                <div class="u-grid-2">
                    <div class="form-group" id="modal_password_group">
                        <label class="form-label"><?php echo t('system_users.field_password'); ?></label>
                        <input type="password" name="password" id="modal_password" class="form-control" placeholder="******" autocomplete="new-password">
                        <small class="u-mt-4 u-fs-12 u-muted" style="display: block;"><?php echo t('system_users.password_auto_hint'); ?></small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_fullname'); ?></label>
                        <input type="text" name="full_name" id="modal_full_name" class="form-control" placeholder="<?php echo t('system_users.field_fullname_placeholder'); ?>">
                        <small class="u-muted u-fs-11"><?php echo t('system_users.fullname_auto_hint'); ?></small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_email'); ?></label>
                        <input type="email" name="email" id="modal_email" class="form-control" placeholder="name@example.com">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_role'); ?></label>
                    <select name="role" id="modal_role" class="form-control">
                        <?php foreach ($all_roles as $r_item): ?>
                            <option value="<?php echo htmlspecialchars($r_item['role_key']); ?>"><?php echo htmlspecialchars($r_item['role_name']); ?> (<?php echo htmlspecialchars($r_item['role_key']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                        <label class="form-label u-flex-between">
                            <span><?php echo t('system_users.field_sip_password'); ?></span>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="if(typeof generateSipPassword === 'function'){ document.getElementById('modal_sip_password').value = generateSipPassword(); }" title="<?php echo t('system_users.gen_password_tooltip'); ?>">
                                <i class="fas fa-magic"></i> <?php echo t('system_users.auto_password'); ?>
                            </button>
                        </label>
                        <input type="text" name="sip_password" id="modal_sip_password" class="form-control" placeholder="<?php echo t('system_users.sip_password_placeholder'); ?>">
                        <small class="u-muted u-fs-11"><?php echo t('system_users.sip_password_help'); ?></small>
                </div>

                <div class="u-grid-2">
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_cid_internal'); ?></label>
                        <input type="text" name="cid_internal" id="modal_cid_internal" class="form-control" placeholder="<?php echo t('system_users.cid_placeholder'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?php echo t('system_users.field_cid_external'); ?></label>
                        <input type="text" name="cid_external" id="modal_cid_external" class="form-control" placeholder="<?php echo t('system_users.cid_placeholder'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label form-label-help">
                        <span><?php echo t('system_users.field_pickup_group'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('system_users.pickup_group_help'); ?></span></span>
                    </label>
                    <input type="text" name="pickup_group" id="modal_pickup_group" class="form-control" placeholder="<?php echo t('system_users.pickup_group_placeholder'); ?>">
                </div>

                <div class="form-group u-bg-rgba-255-255-255-0-03 u-p-12 u-r-8 u-bd-1-solid-border-color u-mt-12">
                    <label class="form-label form-label-help u-fw-700">
                        <span><?php echo t('system_users.field_phone_mode'); ?></span>
                        <span class="field-help" tabindex="0">?<span class="field-help-tip"><?php echo t('system_users.phone_mode_help'); ?></span></span>
                    </label>
                    <div class="u-d-grid u-gtc-repeat-4-1fr u-gap-8 u-mt-8">
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13 u-m-0 u-user-select-none">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_web" value="web">
                            <span><i class="fas fa-laptop u-primary"></i> Web</span>
                        </label>
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13 u-m-0 u-user-select-none">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_mobil" value="mobil">
                            <span><i class="fas fa-mobile-alt u-success"></i> <?php echo t('my_phone.lbl_mobile'); ?></span>
                        </label>
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13 u-m-0 u-user-select-none">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_sip" value="sip">
                            <span><i class="fas fa-phone-alt u-c-secondary"></i> SIP</span>
                        </label>
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13 u-m-0 u-user-select-none">
                            <input type="checkbox" name="allowed_phone_modes[]" id="modal_mode_video" value="video">
                            <span><i class="fas fa-video u-c-8b5cf6"></i> <?php echo t('system_users.video'); ?></span>
                        </label>
                    </div>
                </div>

                <div class="form-group u-bg-rgba-255-255-255-0-03 u-p-12 u-r-8 u-bd-1-solid-border-color u-mt-12">
                    <label class="form-label u-fw-700"><?php echo t('system_users.extra_perms'); ?></label>
                    <div class="u-d-grid u-gtc-repeat-2-1fr u-gap-12 u-ai-center u-mt-8">
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13">
                            <input type="checkbox" name="can_listen_recordings" id="modal_listen_recordings" value="1"> <?php echo t('system_users.perm_listen_label'); ?>
                        </label>
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13">
                            <input type="checkbox" name="can_view_all_cdrs" id="modal_view_all_cdrs" value="1"> <?php echo t('system_users.perm_view_cdr_label'); ?>
                        </label>
                        <label class="u-d-flex u-ai-center u-gap-8 u-pointer u-fs-13 u-primary u-fw-600">
                            <input type="checkbox" name="can_view_queue_monitor" id="modal_view_queue_monitor" value="1"> <?php echo t('system_users.perm_queue_monitor_label'); ?>
                        </label>
                    </div>
                </div>

                <div class="form-group u-mt-10">
                    <label class="form-label u-check-label">
                        <input type="checkbox" name="is_active" id="modal_is_active" value="1" checked class="u-check">
                        <span class="u-fw-600"><?php echo t('system_users.field_active'); ?></span>
                    </label>
                </div>

                <?php echo uiModalFooter('closeUserModal()'); ?>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPasswordModal">
    <div class="modal-card u-maxw-440">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-key u-primary"></i> <?php echo t('system_users.reset_modal_title'); ?> <span id="reset_username_label"></span></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeResetUserModal()" title="<?php echo t('system_users.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="reset_password" value="1">
                <input type="hidden" name="user_id" id="reset_user_id">

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_new_password'); ?></label>
                    <input type="password" name="new_password" id="reset_new_password" autocomplete="new-password" class="form-control" placeholder="<?php echo t('system_users.new_password_placeholder'); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.field_new_sip_password'); ?></label>
                    <input type="text" name="new_sip_password" id="reset_new_sip_password" class="form-control" placeholder="<?php echo t('system_users.new_sip_password_placeholder'); ?>">
                </div>

                <?php echo uiModalFooter('closeResetUserModal()', t('system_users.update_passwords'), '', 'fa-check'); ?>
            </form>
        </div>
    </div>
</div>

<!-- System Roles Management Modal -->
<div class="modal-overlay" id="rolesModal">
    <div class="modal-card u-maxw-680">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-user-tag u-primary"></i> <?php echo t('system_users.roles_modal_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeRolesModal()" title="<?php echo t('system_users.close_tooltip'); ?>"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body u-d-flex u-col u-gap-16">
            <!-- Roles Table -->
            <div class="u-maxh-220 u-overflow-y-auto u-bd-1-solid-border-color u-r-8">
                <?php echo uiTableOpen([
                    t('system_users.col_role_key'),
                    t('system_users.col_role_name'),
                    t('system_users.col_description'),
                    ['label' => t('system_users.col_action'), 'class' => 'text-right'],
                ], ['search' => false]); ?>
                        <?php foreach ($sys_roles_full as $r_item): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($r_item['role_key']); ?></code></td>
                                <td class="u-strong"><?php echo htmlspecialchars($r_item['role_name']); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($r_item['description'] ?: '-'); ?></td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick='editSystemRole(<?php echo jsArg($r_item); ?>)' title="<?php echo t('system_users.edit_tooltip'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php echo uiTableClose(); ?>
            </div>

            <!-- Role Edit / Create Form -->
            <form class="u-bg-bg-sidebar u-p-16 u-r-8 u-bd-1-solid-border-color" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="save_system_role" value="1">
                <input type="hidden" name="role_id" id="role_modal_id" value="0">

                <h4 class="u-fs-13 u-fw-700 u-mb-12 u-text-main u-d-flex u-ai-center u-jc-space-between">
                    <span><i class="fas fa-edit"></i> <?php echo t('system_users.role_form_title'); ?></span>
                    <button type="button" class="btn btn-secondary btn-sm u-fs-11" onclick="resetRoleForm()"><?php echo t('system_users.clear'); ?></button>
                </h4>

                <div class="u-d-grid u-gtc-1fr-1fr u-gap-12 u-mb-12">
                    <div class="form-group u-mb-0">
                        <label class="form-label"><?php echo t('system_users.field_role_key'); ?></label>
                        <input type="text" name="role_key" id="role_modal_key" class="form-control" placeholder="<?php echo t('system_users.field_role_key_placeholder'); ?>" required>
                    </div>
                    <div class="form-group u-mb-0">
                        <label class="form-label"><?php echo t('system_users.field_role_name'); ?></label>
                        <input type="text" name="role_name" id="role_modal_name" class="form-control" placeholder="<?php echo t('system_users.field_role_name_placeholder'); ?>" required>
                    </div>
                </div>

                <div class="form-group u-mb-12">
                    <label class="form-label"><?php echo t('system_users.field_role_desc'); ?></label>
                    <input type="text" name="description" id="role_modal_desc" class="form-control" placeholder="<?php echo t('system_users.role_desc_placeholder'); ?>">
                </div>

                <div class="u-d-flex u-jc-flex-end u-gap-8">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> <?php echo t('system_users.save_role'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CSV Import Modal -->
<div class="modal-overlay" id="userImportModal">
    <div class="modal-card u-maxw-520">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-file-csv u-primary"></i> <?php echo t('system_users.import_title'); ?></h3>
            <button class="btn btn-icon btn-secondary u-btn-pad" onclick="closeUserImportModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">
                <input type="hidden" name="csv_preview" value="1">
                <p class="u-fs-13 u-muted u-mt-0"><?php echo t('system_users.import_help'); ?></p>
                <p class="u-fs-13"><a href="/system-users?download=user_import_template"><i class="fas fa-download"></i> <?php echo t('system_users.import_template'); ?></a></p>
                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.import_file'); ?></label>
                    <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('system_users.import_default_role'); ?></label>
                    <select name="default_role" class="form-control">
                        <?php foreach ($all_roles as $r): ?>
                            <option value="<?php echo htmlspecialchars($r['role_key']); ?>" <?php echo $r['role_key'] === 'cc_agent' ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="u-d-flex u-gap-8 u-ai-center u-fs-13 u-mb-16">
                    <input type="checkbox" name="send_invitations" value="1" checked> <?php echo t('system_users.import_send_invitations'); ?>
                </label>
                <div class="u-d-flex u-jc-flex-end">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> <?php echo t('system_users.import_check'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo asset('/assets/js/system_users.js'); ?>"></script>
