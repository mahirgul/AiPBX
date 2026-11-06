<div>
    <!-- Header & info card -->
    <div class="card page-header-card">
        <?php echo uiPageHeaderOpen('fa-shield-alt', t('security.page_title'), 'securityHelpBox'); ?>
<span class="badge" style="padding: 8px 14px; font-size: 12.5px; border-radius: 20px; font-weight: 700; background: <?php echo $twoFactorEnabled ? 'rgba(34, 197, 94, 0.15)' : 'rgba(239, 68, 68, 0.15)'; ?>; color: <?php echo $twoFactorEnabled ? 'var(--success)' : 'var(--danger)'; ?>; border: 1px solid <?php echo $twoFactorEnabled ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <i class="fas <?php echo $twoFactorEnabled ? 'fa-lock' : 'fa-unlock-alt'; ?>"></i>
                    2FA: <?php echo $twoFactorEnabled ? t('security.status_enabled') : t('security.status_disabled'); ?>
                </span>
                <span class="badge" style="padding: 8px 14px; font-size: 12.5px; border-radius: 20px; font-weight: 700; background: <?php echo !empty($passkeys) ? 'rgba(2, 132, 199, 0.15)' : 'rgba(148, 163, 184, 0.15)'; ?>; color: <?php echo !empty($passkeys) ? 'var(--primary)' : 'var(--text-muted)'; ?>; border: 1px solid <?php echo !empty($passkeys) ? 'var(--primary)' : 'var(--border-color)'; ?>;">
                    <i class="fas fa-fingerprint"></i>
                    Passkey: <?php echo count($passkeys); ?> <?php echo t('security.unit_registered'); ?>
                </span>
<?php echo uiPageHeaderActions(); ?>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('securityHelpBox', t('common.module_guide')); ?><?php echo t('security.page_subtitle'); ?><?php echo uiHelpBoxClose(); ?>
    </div>

    <?php if (!empty($newRecoveryCodes)): ?>
        <!-- Recovery codes display (printed only when newly generated) -->
        <div class="card mb-4 u-bg-rgba-34-197-94-0-08 u-bd-2-solid-success u-r-12 u-p-24">
            <div class="u-d-flex u-ai-flex-start u-gap-14">
                <div class="u-fs-28 u-success"><i class="fas fa-key"></i></div>
                <div class="u-flex-1">
                    <h3 class="u-fs-16 u-fw-700 u-success u-m-0-0-8-0">
                        <?php echo t('security.recovery_codes_title'); ?>
                    </h3>
                    <p class="u-fs-13 u-c-text-color u-m-0-0-16-0 u-lh-1-5">
                        <?php echo t('security.recovery_codes_desc'); ?>
                    </p>

                    <div class="u-d-grid u-gtc-repeat-auto-fill-minmax-140-1fr u-gap-12 u-mb-16">
                        <?php foreach ($newRecoveryCodes as $idx => $code): ?>
                            <div class="u-bg-bg-card u-bd-1-dashed-success u-p-8-12 u-r-8 u-ta-center u-mono u-fs-16 u-fw-700 u-letter-spacing-1">
                                <?php echo htmlspecialchars($code); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="u-d-flex u-gap-10 u-wrap">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="copyRecoveryCodes()">
                            <i class="fas fa-copy"></i> <?php echo t('security.btn_copy_codes'); ?>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="downloadRecoveryCodes()">
                            <i class="fas fa-download"></i> <?php echo t('security.btn_download_codes'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="u-d-grid u-gtc-1fr-1fr u-gap-20">
        <!-- LEFT COLUMN: 2FA TOTP AUTHENTICATOR -->
        <div class="card u-p-24">
            <div class="u-d-flex u-ai-center u-jc-space-between u-mb-16 u-border-bottom-1-solid-border-color u-pb-12">
                <h3 class="u-fs-16 u-fw-800 u-m-0 u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-mobile-alt u-primary"></i>
                    <?php echo t('security.2fa_card_title'); ?>
                </h3>
                <?php if ($twoFactorEnabled): ?>
                    <span class="badge u-bg-rgba-34-197-94-0-15 u-success u-fw-700">
                        <i class="fas fa-check-circle"></i> <?php echo t('security.status_active'); ?>
                    </span>
                <?php else: ?>
                    <span class="badge u-bg-rgba-148-163-184-0-15 u-muted u-fw-700">
                        <?php echo t('security.status_inactive'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($twoFactorEnabled): ?>
                <!-- 2FA enabled status -->
                <div class="u-ta-center u-p-20-0">
                    <div class="u-w-64 u-h-64 u-r-50pc u-bg-rgba-34-197-94-0-15 u-success u-d-inline-flex u-ai-center u-jc-center u-fs-32 u-mb-16">
                        <i class="fas fa-shield-check"></i>
                    </div>
                    <h4 class="u-fs-16 u-fw-700 u-m-0-0-8-0"><?php echo t('security.2fa_active_heading'); ?></h4>
                    <p class="u-muted u-fs-13 u-maxw-380 u-m-0-auto-20-auto u-lh-1-5">
                        <?php echo t('security.2fa_active_info'); ?>
                    </p>

                    <div class="u-d-flex u-gap-10 u-jc-center u-wrap">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openRegenCodesModal()">
                            <i class="fas fa-sync-alt"></i> <?php echo t('security.btn_regen_codes'); ?>
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="openDisable2faModal()">
                            <i class="fas fa-trash-alt"></i> <?php echo t('security.btn_disable_2fa'); ?>
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- 2FA setup area -->
                <div>
                    <p class="u-fs-13 u-muted u-mb-20 u-lh-1-5">
                        <?php echo t('security.2fa_setup_intro'); ?>
                    </p>

                    <div class="u-ta-center u-mb-20 u-bg-ffffff u-p-16 u-r-12 u-bd-1-solid-border-color u-d-inline-block u-w-100">
                        <img class="u-w-180 u-h-180 u-m-0-auto" src="<?php echo $qrCodeDataUri; ?>" alt="2FA QR Code" style="display: block;">
                        <div class="u-mt-10">
                            <span class="u-fs-11 u-muted u-mb-4" style="display: block;"><?php echo t('security.manual_key_label'); ?></span>
                            <code class="u-bg-bg-card u-p-4-12 u-r-8 u-fs-13 u-fw-700 u-letter-spacing-1 u-primary u-word-break-break-all" id="manual_secret_key"><?php echo htmlspecialchars($setupSecret); ?></code>
                            <button type="button" class="btn btn-icon btn-secondary btn-xs u-ml-6" onclick="copySecretKey()" title="<?php echo t('common.copy'); ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>

                    <form method="POST" action="/security" class="u-mt-14">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="enable_2fa">
                        <input type="hidden" name="secret" value="<?php echo htmlspecialchars($setupSecret); ?>">

                        <div class="form-group">
                            <label class="form-label u-fw-700 u-fs-13">
                                <?php echo t('security.verify_code_label'); ?>
                            </label>
                            <input type="text" name="verify_code" class="form-control u-fs-18 u-fw-700 u-letter-spacing-6 u-ta-center" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="<?php echo t('common.eg'); ?> 123456" required>
                        </div>

                        <div class="form-actions"><button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle"></i> <?php echo t('security.btn_activate_2fa'); ?>
                        </button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: PASSKEY (FIDO2 / WEBAUTHN) -->
        <div class="card u-p-24">
            <div class="u-d-flex u-ai-center u-jc-space-between u-mb-16 u-border-bottom-1-solid-border-color u-pb-12">
                <h3 class="u-fs-16 u-fw-800 u-m-0 u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-fingerprint u-primary"></i>
                    <?php echo t('security.passkey_card_title'); ?>
                </h3>
                <button type="button" class="btn btn-primary btn-sm" onclick="registerNewPasskey()">
                    <i class="fas fa-plus"></i> <?php echo t('security.btn_add_passkey'); ?>
                </button>
            </div>

            <p class="u-fs-13 u-muted u-mb-20 u-lh-1-5">
                <?php echo t('security.passkey_intro'); ?>
            </p>

            <?php if (empty($passkeys)): ?>
                <div class="u-ta-center u-p-32-20 u-bg-bg-card u-bd-1-dashed-border-color u-r-12">
                    <div class="u-fs-32 u-muted u-mb-10">
                        <i class="fas fa-key"></i>
                    </div>
                    <div class="u-fs-14 u-fw-700 u-c-text-color u-mb-4">
                        <?php echo t('security.no_passkeys_title'); ?>
                    </div>
                    <div class="u-fs-12 u-muted u-mb-16">
                        <?php echo t('security.no_passkeys_desc'); ?>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="registerNewPasskey()">
                        <i class="fas fa-fingerprint"></i> <?php echo t('security.btn_add_first_passkey'); ?>
                    </button>
                </div>
            <?php else: ?>
                <?php echo uiTableOpen([
                    t('security.col_device'),
                    t('security.col_created'),
                    t('security.col_last_used'),
                    ['label' => t('common.action'), 'class' => 'u-text-right'],
                ]); ?>
                            <?php foreach ($passkeys as $pk): ?>
                                <tr>
                                    <td>
                                        <div class="u-flex-center">
                                            <i class="fas fa-fingerprint u-primary"></i>
                                            <strong><?php echo htmlspecialchars($pk['device_name']); ?></strong>
                                        </div>
                                    </td>
                                    <td class="u-fs-12-5 u-muted">
                                        <?php echo date('d.m.Y H:i', strtotime($pk['created_at'])); ?>
                                    </td>
                                    <td class="u-fs-12-5 u-muted">
                                        <?php echo !empty($pk['last_used_at']) ? date('d.m.Y H:i', strtotime($pk['last_used_at'])) : '—'; ?>
                                    </td>
                                    <td class="u-text-right">
                                        <button type="button" class="btn btn-icon btn-outline-danger btn-xs" onclick="deletePasskey(<?php echo (int)$pk['id']; ?>, '<?php echo htmlspecialchars(addslashes($pk['device_name'])); ?>')" title="<?php echo t('common.delete'); ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php echo uiTableClose(); ?>
            <?php endif; ?>

            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <!-- Google OAuth 2.0 sign-in settings (system administrator) -->
            <div class="u-mt-24 u-pt-20 u-border-top-1-solid-border-color">
                <div class="u-d-flex u-ai-center u-jc-space-between u-mb-12">
                    <h4 class="u-fs-15 u-fw-800 u-m-0 u-d-flex u-ai-center u-gap-8">
                        <i class="fab fa-google u-c-ea4335"></i>
                        <?php echo t('security.google_oauth_title'); ?>
                    </h4>
                    <span class="badge <?php echo (!empty($googleSettings['enabled'])) ? 'badge-success' : 'badge-secondary'; ?> u-fs-11">
                        <?php echo (!empty($googleSettings['enabled'])) ? t('common.active') : t('common.passive'); ?>
                    </span>
                </div>
                <div style="background: var(--bg-hover, rgba(0,0,0,0.02)); border: 1px solid var(--border-color); border-radius: 8px; padding: 14px 16px; margin-bottom: 14px;">
                    <p class="u-fs-13 u-muted u-mb-12 u-lh-1-5">
                        <?php echo t('security.google_moved_notice'); ?>
                    </p>
                    <a href="/google-integration" class="btn btn-primary btn-sm u-fw-700 u-d-inline-flex u-ai-center u-gap-6">
                        <i class="fab fa-google"></i> <?php echo t('security.go_to_google_integration'); ?> <i class="fas fa-arrow-right u-fs-11"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Password change card (bottom right) -->
            <div class="u-mt-24 u-pt-20 u-border-top-1-solid-border-color">
                <h4 class="u-fs-16 u-fw-700 u-m-0-0-12-0 u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-lock u-muted"></i>
                    <?php echo t('security.change_password_title'); ?>
                </h4>

                <form method="POST" action="/security">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-group u-mb-10">
                        <label class="form-label u-fs-12"><?php echo t('security.field_current_password'); ?></label>
                        <input type="password" name="current_password" class="form-control u-h-38" required>
                    </div>
                    <div class="u-d-grid u-gtc-1fr-1fr u-gap-12 u-mb-12">
                        <div class="form-group u-m-0">
                            <label class="form-label u-fs-12"><?php echo t('security.field_new_password'); ?></label>
                            <input type="password" name="new_password" class="form-control u-h-38" minlength="8" required>
                        </div>
                        <div class="form-group u-m-0">
                            <label class="form-label u-fs-12"><?php echo t('security.field_confirm_password'); ?></label>
                            <input type="password" name="confirm_password" class="form-control u-h-38" minlength="8" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm u-fw-700">
                        <i class="fas fa-save"></i> <?php echo t('security.btn_save_password'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Disable 2FA modal -->
<div class="modal-overlay" id="modalDisable2fa">
    <div class="modal-card u-maxw-400">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-exclamation-triangle u-danger"></i> <?php echo t('security.modal_disable_2fa_title'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="closeDisable2faModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="/security">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="disable_2fa">
                <p class="u-fs-13 u-muted u-mb-16">
                    <?php echo t('security.disable_2fa_confirm_desc'); ?>
                </p>
                <div class="form-group">
                    <label class="form-label"><?php echo t('security.field_current_password'); ?></label>
                    <input type="password" name="current_password" class="form-control" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDisable2faModal()"><?php echo t('common.cancel'); ?></button>
                <button type="submit" class="btn btn-danger"><?php echo t('security.btn_confirm_disable'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Regenerate recovery codes modal -->
<div class="modal-overlay" id="modalRegenCodes">
    <div class="modal-card u-maxw-400">
        <div class="modal-header">
            <h3 class="u-title"><i class="fas fa-sync-alt u-primary"></i> <?php echo t('security.modal_regen_codes_title'); ?></h3>
            <button type="button" class="btn btn-icon btn-secondary u-btn-pad" onclick="closeRegenCodesModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" action="/security">
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="regen_recovery_codes">
                <p class="u-fs-13 u-muted u-mb-16">
                    <?php echo t('security.regen_codes_desc'); ?>
                </p>
                <div class="form-group">
                    <label class="form-label"><?php echo t('security.field_current_password'); ?></label>
                    <input type="password" name="current_password" class="form-control" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRegenCodesModal()"><?php echo t('common.cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><?php echo t('security.btn_confirm_regen'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
window.SECURITY_PAGE = {
    csrf: <?php echo json_encode($csrf_token); ?>,
    recoveryCodes: <?php echo json_encode($newRecoveryCodes ?? []); ?>,
    i18n: {
        secret_copied: <?php echo json_encode(t('security.secret_copied')); ?>,
        codes_copied: <?php echo json_encode(t('security.codes_copied')); ?>,
        webauthn_not_supported: <?php echo json_encode(t('security.webauthn_not_supported')); ?>,
        prompt_device_name: <?php echo json_encode(t('security.prompt_device_name')); ?>,
        passkey_added_success: <?php echo json_encode(t('security.passkey_added_success')); ?>,
        confirm_delete_passkey: <?php echo json_encode(t('security.confirm_delete_passkey')); ?>,
        passkey_deleted_success: <?php echo json_encode(t('security.passkey_deleted_success')); ?>
    }
};
</script>
<script src="<?php echo asset('/assets/js/security.js'); ?>"></script>
