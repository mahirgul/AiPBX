<?php
// Central Notification Collector with strict message deduplication
$all_notifications = [];
$seen_messages = [];

if (function_exists('getFlashNotifications')) {
    $flash_items = getFlashNotifications();
    foreach ($flash_items as $item) {
        $msg_text = trim($item['message'] ?? '');
        if (!empty($msg_text) && !isset($seen_messages[$msg_text])) {
            $all_notifications[] = ['message' => $msg_text, 'type' => $item['type'] ?? 'info'];
            $seen_messages[$msg_text] = true;
        }
    }
}

if (!empty($message)) {
    $m_text = trim($message);
    if (!empty($m_text) && !isset($seen_messages[$m_text])) {
        $all_notifications[] = ['message' => $m_text, 'type' => 'success'];
        $seen_messages[$m_text] = true;
    }
}
if (!empty($error)) {
    $e_text = trim($error);
    if (!empty($e_text) && !isset($seen_messages[$e_text])) {
        $all_notifications[] = ['message' => $e_text, 'type' => 'danger'];
        $seen_messages[$e_text] = true;
    }
}
if (!empty($warning)) {
    $w_text = trim($warning);
    if (!empty($w_text) && !isset($seen_messages[$w_text])) {
        $all_notifications[] = ['message' => $w_text, 'type' => 'warning'];
        $seen_messages[$w_text] = true;
    }
}
if (!empty($info)) {
    $i_text = trim($info);
    if (!empty($i_text) && !isset($seen_messages[$i_text])) {
        $all_notifications[] = ['message' => $i_text, 'type' => 'info'];
        $seen_messages[$i_text] = true;
    }
}

// SPA AJAX Response Flusher
$is_spa_request = !empty($_SERVER['HTTP_X_SPA_REQUEST']) || (isset($_GET['spa']) && $_GET['spa'] === '1');
if ($is_spa_request) {
    $spa_content = ob_get_clean();
    echo '<section class="content-area">';
    echo $spa_content;

    // NOTE: the notification script and $extra_js (e.g. cc_agent.php's
    // agent_ui.js) used to be printed AFTER this </section> (i.e. outside
    // .content-area). spa_router.js takes only the innerHTML of
    // doc.querySelector('.content-area') from the SPA response and writes it
    // into the current content area — everything outside was silently dropped
    // (the notification never showed, and agent_ui.js never loaded when going
    // to cc_agent.php via SPA). Printed BEFORE the section closes,
    // executePageScripts() finds and runs them like normal page scripts.
    if (!empty($all_notifications)) {
        echo '<script>';
        foreach ($all_notifications as $item) {
            echo 'if (typeof showFooterToast === "function") showFooterToast(' . json_encode($item['message']) . ', ' . json_encode($item['type']) . ');';
        }
        echo '</script>';
    }

    if (isset($extra_js)) echo $extra_js;

    echo '</section>';
    exit;
}
?>

        </section>
    </main>

    <!-- Sticky Fixed Bottom Notification & Status Footer Bar -->
    <footer class="app-footer-bar" id="app-footer-bar">
        <!-- Centered Floating Toast Notification Pill Container -->
        <div class="footer-toast-container" id="footer-toast-container"></div>

        <!-- Sol: Saat ve Tarih -->
        <div class="footer-left-group">
            <div class="footer-stat-pill u-strong" id="footer-clock">
                <i class="far fa-clock"></i> <?php echo t('footer.loading'); ?>
            </div>
            <div class="footer-stat-pill u-muted u-fs-11" title="AiPBX">v<?php echo htmlspecialchars(AIPBX_VERSION); ?></div>
        </div>

        <!-- Right: user menu -->
        <div class="footer-right-group">
            <?php
            if (!isset($user) && function_exists('getCurrentUser')) {
                $user = getCurrentUser();
            }
            if (!empty($user)):
            ?>
            <div class="user-profile-wrapper">
                <div class="user-badge" onclick="toggleUserProfileDropdown(event)" title="<?php echo t('sidebar.user_menu_tooltip'); ?>">
                    <i class="fas fa-user-circle user-badge-icon"></i>
                    <div class="user-badge-text">
                        <span class="user-badge-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                        <span class="user-badge-role">
                            <?php echo htmlspecialchars(localizeRole(['role' => $user['role'], 'role_name' => $user['role_display_name'] ?? $user['role']])['role_name']); ?>
                        </span>
                    </div>
                    <i class="fas fa-chevron-up user-badge-arrow" id="user-dropdown-arrow"></i>
                </div>

                <div class="user-profile-dropdown" id="userProfileDropdown">
                    <!-- Icon-based quick settings: theme, font size, language, logout — one row -->
                    <div style="display: flex; align-items: center; gap: 4px; padding: 6px 8px;">
                        <button type="button" class="btn btn-xs" id="theme-toggle-btn" style="flex: 1; border-radius: 6px; padding: 6px; border: none; cursor: pointer; background: transparent;" title="<?php echo t('sidebar.theme_toggle_tooltip'); ?>">
                            <i class="fas fa-moon" id="theme-icon"></i>
                        </button>
                        <div style="width: 1px; height: 18px; background: var(--border-color);"></div>
                        <button type="button" onclick="setFontSizePref('small')" id="btn-font-small" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; font-size: 10px; font-weight: 700; border: none; cursor: pointer;" title="<?php echo t('sidebar.font_small_tooltip'); ?>">
                            A
                        </button>
                        <button type="button" onclick="setFontSizePref('normal')" id="btn-font-normal" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; font-size: 13px; font-weight: 700; border: none; cursor: pointer;" title="<?php echo t('sidebar.font_normal_tooltip'); ?>">
                            A
                        </button>
                        <button type="button" onclick="setFontSizePref('large')" id="btn-font-large" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; font-size: 16px; font-weight: 700; border: none; cursor: pointer;" title="<?php echo t('sidebar.font_large_tooltip'); ?>">
                            A
                        </button>
                        <div style="width: 1px; height: 18px; background: var(--border-color);"></div>
                        <?php // Interface language: a plain GET form, so it also works without the SPA router. ?>
                        <form action="/set-language" method="get" data-no-spa="true" class="user-lang-form" title="<?php echo t('sidebar.language_tooltip'); ?>">
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/'); ?>">
                            <select name="lang" onchange="this.form.submit()" aria-label="<?php echo t('sidebar.language_tooltip'); ?>">
                                <?php foreach (UI_LANGUAGES as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>"<?php echo $code === getUserLanguage() ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <div style="width: 1px; height: 18px; background: var(--border-color);"></div>
                        <a href="/security" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; border: none; cursor: pointer; background: transparent; color: var(--primary); text-align: center; text-decoration: none;" title="<?php echo t('sidebar.security_settings', 'Güvenlik & 2FA'); ?>">
                            <i class="fas fa-shield-alt"></i>
                        </a>
                        <div style="width: 1px; height: 18px; background: var(--border-color);"></div>
                        <a href="/logout" data-no-spa="true" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; border: none; cursor: pointer; background: transparent; color: var(--danger); text-align: center; text-decoration: none;" title="<?php echo t('sidebar.logout_tooltip'); ?>">
                            <i class="fas fa-sign-out-alt"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </footer>
</div>

<!-- 100% Offline Local JavaScript Modules -->
<script src="<?php echo asset('/assets/js/theme.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/nav.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/modal.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/footer_notify.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/datatable_enhancer.js'); ?>"></script>

<?php if (!empty($all_notifications)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const notifications = <?php echo json_encode($all_notifications); ?>;
        notifications.forEach(function(item, index) {
            setTimeout(function() {
                if (window.notify) {
                    window.notify.show(item.message, item.type);
                } else if (typeof showFooterToast === 'function') {
                    showFooterToast(item.message, item.type);
                }
            }, index * 400);
        });
    });
</script>
<?php endif; ?>

<?php if (isset($extra_js)): echo $extra_js; endif; ?>
</body>
</html>
