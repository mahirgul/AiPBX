<?php /* Layout: templates/layouts/auth_header.php — GoogleAuthController::renderMobileCallback() */ ?>
<div class="auth-card u-maxw-400 u-ta-center u-p-32-24 u-r-16 u-m-auto">
    <div style="font-size: 48px; margin-bottom: 16px; color: <?php echo $success ? 'var(--primary)' : 'var(--danger)'; ?>;">
        <i class="fas <?php echo $success ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
    </div>
    <h2 class="u-fs-20 u-fw-700 u-m-0-0-12-0"><?php echo htmlspecialchars($title); ?></h2>
    <p class="u-fs-14 u-muted u-lh-1-5 u-m-0-0-24-0">
        <?php echo $success ? t('google.returning') : htmlspecialchars($error_message); ?>
    </p>
    <a id="btnReturn" href="<?php echo htmlspecialchars($deep_link); ?>" class="btn btn-primary u-w-100 u-jc-center u-p-12 u-fw-700">
        <?php echo $success ? t('google.open_app') : t('google.back_to_app'); ?>
    </a>
</div>
<script>
    // Try to return to the app automatically
    window.location.href = <?php echo json_encode($deep_link); ?>;
    setTimeout(function () {
        var btn = document.getElementById('btnReturn');
        if (btn) btn.focus();
    }, 1500);
</script>
