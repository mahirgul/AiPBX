<?php
/**
 * Mobile sign-in QR code dialog (assets/js/qr_login.js). One copy in the layout:
 * opened from My Phone and from the phone settings dialog in the header.
 */
?>
<!-- Modal: quick mobile sign-in with a QR code -->
<div class="modal-overlay" id="qrLoginModal" style="display: none;">
    <div class="modal-card u-maxw-440 u-ta-center">
        <div class="modal-header">
            <div class="u-d-flex u-ai-center u-gap-10">
                <div class="u-w-36 u-h-36 u-r-12 u-bg-rgba-37-99-235-0-12-2 u-primary u-d-flex u-ai-center u-jc-center u-fs-18">
                    <i class="fas fa-qrcode"></i>
                </div>
                <div>
                    <h3 class="u-fs-16 u-fw-700 u-m-0 u-ta-left"><?php echo t('my_phone.mobile_qr'); ?></h3>
                    <small class="u-muted u-fs-11 u-ta-left" style="display: block;"><?php echo t('my_phone.lbl_extension'); ?>: <?php echo htmlspecialchars((string) ($user['extension'] ?? '')); ?> (<?php echo htmlspecialchars((string) ($user['full_name'] ?? '')); ?>)</small>
                </div>
            </div>
            <button class="btn btn-icon btn-secondary btn-sm" onclick="closeQrLoginModal()" title="<?php echo t('common.close'); ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body u-p-24-20">
            <p class="u-fs-13 u-muted u-mb-20 u-lh-1-5">
                <?php echo t('my_phone.qr_howto'); ?>
            </p>

            <div class="u-jc-center u-ai-center u-minh-240 u-bg-ffffff u-p-16 u-r-12 u-bd-1-solid-border-color u-box-shadow-0-4-12-rgba-0-0-0-0-06 u-m-0-auto u-maxw-250" id="qrCodeContainer" style="display: flex;">
                <div id="qrLoadingSpinner" class="u-muted u-text-center">
                    <i class="fas fa-spinner fa-spin u-fs-28 u-primary u-mb-8" style="display: block;"></i>
                    <?php echo t('my_phone.qr_generating'); ?>
                </div>
                <div class="u-w-100" id="qrSvgWrapper" style="display: none;"></div>
            </div>

            <div class="u-mt-16 u-bg-rgba-16-185-129-0-15 u-bd-1-solid-rgba-16-185-129-0-4 u-r-8 u-p-12 u-c-059669 u-fw-600 u-fs-13" id="qrSuccessAlert" style="display: none;">
                <i class="fas fa-check-circle u-mr-6"></i> <?php echo t('my_phone.qr_paired'); ?>: <span id="qrPairedDevice"></span>
            </div>

            <div id="qrCountdownContainer" class="u-mt-16 u-fs-12 u-muted u-d-flex u-ai-center u-jc-center u-gap-8">
                <i class="fas fa-clock"></i> <?php echo t('my_phone.qr_remaining'); ?>: <strong id="qrCountdown" class="u-primary">10:00</strong>
                <button type="button" class="btn btn-ghost btn-xs" onclick="generateNewQrCode()" title="<?php echo t('my_phone.qr_new'); ?>">
                    <i class="fas fa-sync-alt"></i> <?php echo t('common.refresh'); ?>
                </button>
            </div>

            <div class="u-mt-20 u-fs-12 u-muted u-bg-bg-input u-p-12-16 u-r-8 u-ta-left u-lh-1-5 u-bd-1-solid-border-color">
                <i class="fas fa-shield-alt u-primary u-mr-4"></i>
                <?php echo t('my_phone.qr_secure'); ?>
            </div>
        </div>
    </div>
</div>
