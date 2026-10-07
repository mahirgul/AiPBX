<?php
// EN/TR switch of the sign-in pages (auth_header.php; the phone sign-in
// page prints it inside its brand card with $lang_switch_class).
$auth_redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
?>
<nav class="auth-lang-switch<?php echo isset($lang_switch_class) ? ' ' . htmlspecialchars($lang_switch_class) : ''; ?>" aria-label="Language">
    <?php foreach (UI_LANGUAGES as $code => $label): ?>
        <?php if ($code === getUserLanguage()): ?>
            <span class="active"><?php echo htmlspecialchars($label); ?></span>
        <?php else: ?>
            <a href="/set-language?lang=<?php echo urlencode($code); ?>&amp;redirect=<?php echo $auth_redirect; ?>" hreflang="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
