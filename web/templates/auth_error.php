<?php
/**
 * Error box for the sign-in style pages (login, 2FA, password reset). These
 * pages use renderAuthPage(), which has no toast area, so the error is shown
 * inline. Expects $error.
 */
if (!empty($error)): ?>
    <div class="auth-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif;
