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
            echo 'if (typeof showFooterToast === "function") showFooterToast('
                . json_encode($item['message'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ', '
                . json_encode($item['type'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
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

        <!-- Left: version & sponsor -->
        <div class="footer-left-group">
            <button type="button" class="footer-stat-pill u-muted u-fs-11" onclick="openAipbxLicenseModal()" style="border:none; cursor:pointer; background:transparent;" title="<?php echo htmlspecialchars(t('license.modal_title', 'Open Source License & Disclaimer')); ?>">
                <i class="fas fa-balance-scale" style="margin-right:4px;"></i>v<?php echo htmlspecialchars(AIPBX_VERSION); ?> · MIT
            </button>
            <a href="https://github.com/sponsors/mahirgul" target="_blank" rel="noopener noreferrer" class="footer-stat-pill u-muted u-fs-11" style="text-decoration:none; color:#ea4aaa; font-weight:600;" title="Sponsor @mahirgul on GitHub">
                <i class="fas fa-heart" style="margin-right:4px; color:#ea4aaa;"></i>Sponsor
            </a>
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
                            <select name="lang" onchange="if(this.form.elements['redirect']){this.form.elements['redirect'].value=window.location.pathname+window.location.search;}this.form.submit();" aria-label="<?php echo t('sidebar.language_tooltip'); ?>">
                                <?php foreach (UI_LANGUAGES as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>"<?php echo $code === getUserLanguage() ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <div style="width: 1px; height: 18px; background: var(--border-color);"></div>
                        <a href="/security" class="btn btn-xs" style="flex: 1; border-radius: 6px; padding: 6px; border: none; cursor: pointer; background: transparent; color: var(--primary); text-align: center; text-decoration: none;" title="<?php echo t('sidebar.security_settings'); ?>">
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

<?php
$_licAcceptedAt = function_exists('getSystemSetting') ? (string)getSystemSetting('license_accepted_at', '') : '';
$_licAcceptedBy = function_exists('getSystemSetting') ? (string)getSystemSetting('license_accepted_by', '') : '';
$_licNeedsPrompt = ($_licAcceptedAt === '' && !empty($user) && ($user['role'] ?? '') === 'admin');
?>
<div id="aipbxLicenseModal" class="modal" style="display:<?php echo $_licNeedsPrompt ? 'flex' : 'none'; ?>; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.72); align-items:center; justify-content:center; padding:16px;">
    <div class="card" style="max-width:620px; width:100%; max-height:90vh; overflow-y:auto; margin:0; padding:24px; border-radius:12px; box-shadow:0 20px 50px rgba(0,0,0,0.35);">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">
            <h3 style="margin:0; font-size:18px; display:flex; align-items:center; gap:8px;">
                <i class="fas fa-balance-scale" style="color:var(--primary);"></i>
                <?php echo htmlspecialchars(t('license.modal_title', 'Open Source License & Telecom Disclaimer')); ?>
            </h3>
            <?php if (!$_licNeedsPrompt): ?>
            <button type="button" onclick="closeAipbxLicenseModal()" class="btn btn-xs btn-secondary" style="border:none; background:transparent; font-size:16px; cursor:pointer;">✕</button>
            <?php endif; ?>
        </div>

        <p class="u-fs-13 u-muted" style="margin:0 0 12px 0; line-height:1.55;">
            <?php echo htmlspecialchars(t('license.intro', 'AiPBX is free and open-source software released under the MIT License and provided "AS IS" without warranty of any kind. By operating this PBX system, you acknowledge and agree to the following terms:')); ?>
        </p>

        <ul class="u-fs-13" style="margin:0 0 16px 0; padding-left:20px; line-height:1.6;">
            <li style="margin-bottom:8px;"><?php echo htmlspecialchars(t('license.item_oss', 'No Warranty (MIT License): The authors and contributors shall not be liable for any direct, indirect, or consequential damages arising from the use of this software.')); ?></li>
            <li style="margin-bottom:8px;"><?php echo htmlspecialchars(t('license.item_emergency', 'Emergency Calls (112 / 911 / 999): AiPBX is not a certified emergency call service. Power, internet, or SIP trunk outages may prevent emergency calls. Always maintain an independent emergency line.')); ?></li>
            <li style="margin-bottom:8px;"><?php echo htmlspecialchars(t('license.item_fraud', 'Telecom Billing & Toll Fraud: Securing server access, enforcing strong SIP/admin credentials, and restricting outbound routes are your sole responsibility. The authors are not liable for carrier charges or toll fraud.')); ?></li>
            <li style="margin-bottom:4px;"><?php echo htmlspecialchars(t('license.item_privacy', 'Privacy & Recording Compliance (GDPR / KVKK): Enabling call recording, fax archiving, or AI transcription requires complying with applicable caller-consent and data protection laws in your jurisdiction.')); ?></li>
        </ul>

        <?php if ($_licAcceptedAt !== ''): ?>
        <div class="alert alert-info u-fs-12" style="margin-bottom:14px; padding:8px 12px;">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars(t('license.accepted_info', 'Accepted on')) . ': ' . htmlspecialchars($_licAcceptedAt . ($_licAcceptedBy !== '' ? ' — ' . $_licAcceptedBy : '')); ?>
        </div>
        <?php endif; ?>

        <?php if ($_licNeedsPrompt): ?>
        <div style="padding:12px; border:1px solid var(--border-color); border-radius:8px; margin-bottom:16px;">
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-size:13px; font-weight:600; margin:0;">
                <input type="checkbox" id="aipbxLicenseAgreeChk" onchange="this.form && (this.form.querySelector('button').disabled = !this.checked); var b = this.closest('.card').querySelector('[data-license-accept]'); if (b) b.disabled = !this.checked;" style="margin-top:3px;">
                <span><?php echo htmlspecialchars(t('license.checkbox_label', 'I have read, understood, and accept the MIT License and Telecommunications Responsibility Disclaimer.')); ?></span>
            </label>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" data-license-accept="1" class="btn btn-primary" disabled onclick="submitAipbxLicenseAccept(this)">
                <i class="fas fa-check"></i> <?php echo htmlspecialchars(t('license.accept_btn', 'Accept & Continue')); ?>
            </button>
        </div>
        <?php else: ?>
        <div style="display:flex; justify-content:flex-end; gap:8px;">
            <button type="button" class="btn btn-secondary" onclick="closeAipbxLicenseModal()">
                <?php echo htmlspecialchars(t('license.close_btn', 'Close')); ?>
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
function openAipbxLicenseModal() {
    var m = document.getElementById('aipbxLicenseModal');
    if (m) m.style.display = 'flex';
}
function closeAipbxLicenseModal() {
    var m = document.getElementById('aipbxLicenseModal');
    if (m) m.style.display = 'none';
}
function submitAipbxLicenseAccept(btn) {
    if (btn) btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf_token', <?php echo json_encode(function_exists('getCSRFToken') ? getCSRFToken() : ($_SESSION['csrf_token'] ?? '')); ?>);
    fetch('/accept-license', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success) {
                closeAipbxLicenseModal();
            } else if (btn) {
                btn.disabled = false;
            }
        })
        .catch(function() { if (btn) btn.disabled = false; });
}
</script>

<!-- 100% Offline Local JavaScript Modules -->
<script src="<?php echo asset('/assets/js/theme.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/nav.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/modal.js'); ?>"></script>
<script src="<?php echo asset('/assets/js/footer_notify.js'); ?>"></script>
<?php if (($_SESSION['user_role'] ?? '') === 'admin'): ?><script src="<?php echo asset('/assets/js/notices.js'); ?>"></script><?php endif; ?>
<script src="<?php echo asset('/assets/js/datatable_enhancer.js'); ?>"></script>

<?php if (!empty($all_notifications)): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const notifications = <?php echo json_encode($all_notifications, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
