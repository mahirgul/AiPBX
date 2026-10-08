<script src="<?php echo asset('/assets/js/particles.min.js'); ?>" defer></script>
<script src="<?php echo asset('/assets/js/auth_background.js'); ?>" defer></script>
<script>window.AIPBX_REVEAL_LABELS = <?php echo json_encode(['show' => t('login.show_password'), 'hide' => t('login.hide_password')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;</script>
<script src="<?php echo asset('/assets/js/password_reveal.js'); ?>" defer></script>
</body>
</html>
