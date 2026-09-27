<?php /* Düzen: templates/layouts/auth_header.php — GoogleAuthController::renderMobileCallback() */ ?>
<div class="auth-card" style="max-width: 400px; text-align: center; padding: 32px 24px; border-radius: 16px; margin: auto;">
    <div style="font-size: 48px; margin-bottom: 16px; color: <?php echo $success ? 'var(--primary)' : 'var(--danger)'; ?>;">
        <i class="fas <?php echo $success ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
    </div>
    <h2 style="font-size: 20px; font-weight: 800; margin: 0 0 10px 0;"><?php echo htmlspecialchars($title); ?></h2>
    <p style="font-size: 14px; color: var(--text-muted); line-height: 1.5; margin: 0 0 24px 0;">
        <?php echo $success ? 'Uygulamaya dönülüyor, lütfen bekleyin...' : htmlspecialchars($error_message); ?>
    </p>
    <a id="btnReturn" href="<?php echo htmlspecialchars($deep_link); ?>" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-weight: 700;">
        <?php echo $success ? 'Uygulamayı Aç' : 'Uygulamaya Geri Dön'; ?>
    </a>
</div>
<script>
    // Otomatik uygulamaya dönmeyi dene
    window.location.href = <?php echo json_encode($deep_link); ?>;
    setTimeout(function () {
        var btn = document.getElementById('btnReturn');
        if (btn) btn.focus();
    }, 1500);
</script>
