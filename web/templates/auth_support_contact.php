<?php
// "Need help?" line of the sign-in pages: the contact from Brand settings (#13).
// Prints nothing when no contact is set.
$supName = trim((string) getSystemSetting('support_name', ''));
$supEmail = trim((string) getSystemSetting('support_email', ''));
$supPhone = trim((string) getSystemSetting('support_phone', ''));
if ($supName === '' && $supEmail === '' && $supPhone === '') {
    return;
}
$parts = [];
if ($supName !== '') {
    $parts[] = '<strong>' . htmlspecialchars($supName) . '</strong>';
}
if ($supEmail !== '') {
    $parts[] = '<a href="mailto:' . htmlspecialchars($supEmail) . '"><i class="fas fa-envelope" aria-hidden="true"></i> ' . htmlspecialchars($supEmail) . '</a>';
}
if ($supPhone !== '') {
    $parts[] = '<a href="tel:' . htmlspecialchars(preg_replace('/[^0-9+]/', '', $supPhone)) . '"><i class="fas fa-phone" aria-hidden="true"></i> ' . htmlspecialchars($supPhone) . '</a>';
}
?>
<div class="auth-support-contact">
    <span class="u-muted"><?php echo t('login.support_label'); ?></span>
    <?php echo implode(' <span class="u-muted">·</span> ', $parts); ?>
</div>
