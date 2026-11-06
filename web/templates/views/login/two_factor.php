<?php /* Layout: templates/layouts/auth_header.php — BaseController::renderAuthPage() */ ?>
    <div class="auth-card u-maxw-420">
        <div class="u-text-center u-mb-24">
            <div class="brand-icon u-w-56 u-h-56 u-m-0-auto-16-auto u-fs-24 u-bg-rgba-2-132-199-0-1 u-r-50pc u-d-flex u-ai-center u-jc-center">
                <i class="fas fa-shield-alt u-primary u-fs-26"></i>
            </div>
            <h2 class="u-fs-20 u-fw-800"><?php echo t('login_2fa.title'); ?></h2>
            <p class="u-muted u-fs-13 u-mt-6">
                <?php echo t('login_2fa.account_label'); ?> <strong><?php echo htmlspecialchars($user['full_name'] ?: $user['username']); ?></strong>
            </p>
        </div>

        <?php require dirname(__DIR__, 2) . '/auth_error.php'; ?>

        <form method="POST" action="/login-2fa" id="twoFactorForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="auth_mode" id="auth_mode" value="totp">

            <!-- TOTP 6-Digit Section -->
            <div id="section_totp">
                <div class="form-group u-text-center">
                    <label class="form-label u-fs-13 u-muted u-mb-12">
                        <?php echo t('login_2fa.code_prompt'); ?>
                    </label>
                    <input type="text" name="totp_code" id="totp_code" class="form-control u-fs-28 u-fw-700 u-letter-spacing-10 u-ta-center u-h-54 u-r-12"
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000"
                          
                           autofocus>
                </div>
            </div>

            <!-- Recovery Code Section (Toggled) -->
            <div id="section_recovery" style="display: none;">
                <div class="form-group">
                    <label class="form-label u-fs-13 u-muted u-mb-8">
                        <?php echo t('login_2fa.recovery_prompt'); ?>
                    </label>
                    <input type="text" name="recovery_code" id="recovery_code" class="form-control u-fs-18 u-fw-700 u-letter-spacing-2 u-ta-center u-uppercase u-h-50 u-r-12"
                           placeholder="<?php echo t('common.eg'); ?> 8F3K-9M2Q"
                          >
                    <small class="u-muted u-mt-8 u-fs-11" style="display: block;">
                        <?php echo t('login_2fa.recovery_help'); ?>
                    </small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary u-w-100 u-jc-center u-p-14 u-mt-14 u-fs-15 u-fw-700">
                <i class="fas fa-check-circle u-mr-6"></i> <?php echo t('login_2fa.btn_verify'); ?>
            </button>
        </form>

        <div class="u-mt-20 u-ta-center u-d-flex u-col u-gap-10 u-fs-13">
            <button class="u-bg-none u-bd-none u-primary u-pointer u-text-decoration-underline u-fs-13" type="button" id="toggleModeBtn" onclick="toggleAuthMode()">
                <i class="fas fa-key"></i> <?php echo t('login_2fa.use_recovery_code'); ?>
            </button>

            <form method="POST" action="/login-2fa" class="u-inline">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="u-bg-none u-bd-none u-muted u-pointer u-fs-12" type="submit">
                    <i class="fas fa-arrow-left"></i> <?php echo t('login_2fa.btn_cancel'); ?>
                </button>
            </form>
        </div>
    </div>

    <script>
        window.LOGIN_2FA_TEXT = {
            use_totp: <?php echo json_encode(t('login_2fa.use_totp_code')); ?>,
            use_recovery: <?php echo json_encode(t('login_2fa.use_recovery_code')); ?>
        };
    </script>
<script src="<?php echo asset('/assets/js/login_two_factor.js'); ?>"></script>
