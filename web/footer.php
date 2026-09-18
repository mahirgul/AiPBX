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

    // NOT: Bildirim script'i ve $extra_js (ör. cc_agent.php'nin agent_ui.js'i)
    // eskiden bu </section>'dan SONRA (yani .content-area dışında) basılıyordu.
    // spa_router.js SPA yanıtından sadece doc.querySelector('.content-area')'nın
    // innerHTML'ini alıp mevcut içerik alanına yazıyor — dışarıda kalan her şey
    // sessizce atılıyordu (bildirim hiç gösterilmiyordu, cc_agent.php'ye SPA ile
    // gidildiğinde agent_ui.js hiç yüklenmiyordu). Section kapanmadan ÖNCE basılınca
    // executePageScripts() bunları normal sayfa script'i gibi bulup çalıştırıyor.
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
            <div class="footer-stat-pill" id="footer-clock" style="font-weight: 700; color: var(--text-main);">
                <i class="far fa-clock"></i> <?php echo t('footer.loading'); ?>
            </div>
        </div>

        <!-- Sağ: Kullanıcı Menüsü -->
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
                            <?php echo htmlspecialchars($user['role_display_name'] ?? $user['role']); ?>
                        </span>
                    </div>
                    <i class="fas fa-chevron-up user-badge-arrow" id="user-dropdown-arrow"></i>
                </div>

                <div class="user-profile-dropdown" id="userProfileDropdown">
                    <!-- Simge tabanlı hızlı ayarlar: tema, yazı boyutu, dil, çıkış — tek satır -->
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
                        <?php
                            $cur_lang = getUserLanguage();
                            $next_lang = $cur_lang === 'tr' ? 'en' : 'tr';
                            $lang_redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
                        ?>
                        <a href="/set-language?lang=<?php echo $next_lang; ?>&redirect=<?php echo $lang_redirect; ?>" data-no-spa="true" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; font-size: 10px; font-weight: 700; border: none; cursor: pointer; background: transparent; text-decoration: none;" title="<?php echo $cur_lang === 'tr' ? t('sidebar.switch_to_en') : t('sidebar.switch_to_tr'); ?>">
                            <?php echo strtoupper($next_lang); ?>
                        </a>
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
<script src="/assets/js/theme.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/nav.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/modal.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/footer_notify.js?v=<?php echo time(); ?>"></script>
<script src="/assets/js/datatable_enhancer.js?v=<?php echo time(); ?>"></script>

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
