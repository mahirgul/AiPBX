<?php
// Language dropdown of the sign-in pages (auth_header.php; the phone sign-in
// page prints it inside its brand card with $lang_switch_class = 'inline').
// A plain GET form to /set-language: works without JavaScript (the button in
// <noscript>), with JavaScript the choice applies on change.
?>
<form class="auth-lang-switch<?php echo isset($lang_switch_class) ? ' ' . htmlspecialchars($lang_switch_class) : ''; ?>" action="/set-language" method="get">
    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?>">
    <i class="fas fa-globe" aria-hidden="true"></i>
    <select name="lang" aria-label="Language" onchange="this.form.submit()">
        <?php foreach (UI_LANGUAGES as $code => $label): ?>
            <option value="<?php echo htmlspecialchars($code); ?>" lang="<?php echo htmlspecialchars($code); ?>"<?php echo $code === getUserLanguage() ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
        <?php endforeach; ?>
    </select>
    <noscript><button type="submit">OK</button></noscript>
</form>
