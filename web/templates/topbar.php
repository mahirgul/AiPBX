<?php
/**
 * Header Topbar & Quick Controls Component
 */
?>
<header class="top-bar">
    <div class="top-bar-left">
<?php
$is_collapsed_cookie = $is_collapsed_cookie ?? (isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] === 'true');
?>
        <button type="button" class="sidebar-toggle-btn" id="header-sidebar-toggle" onclick="toggleSidebar(event)" title="<?php echo t('sidebar.toggle_tooltip'); ?>">
            <i class="fas <?php echo $is_collapsed_cookie ? 'fa-chevron-right' : 'fa-chevron-left'; ?>" id="header-sidebar-toggle-icon"></i>
        </button>

        <!-- Live Phone Status Badge (clicking opens the phone settings modal) -->
        <div class="header-phone-status-pill" id="header-phone-status-pill" title="<?php echo t('topbar.phone_status_tooltip'); ?>" onclick="openPhoneSettingsModal()">
            <span class="status-dot" id="header-status-dot"></span>
            <span class="status-text" id="header-status-text"><?php echo htmlspecialchars($user['extension'] ?: '3000'); ?></span>
        </div>


        <!-- Break: a pill like the phone status (icon only on a phone); the native list lies over it, invisible -->
        <?php if (!empty($is_cc_agent)):
            $breakWorking = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', t('topbar.break_working')));
            $breakOptions = [];
            $breakRows = [];
            if (class_exists('PauseReasonRepository')) {
                try {
                    $breakRows = PauseReasonRepository::activeOrdered();
                } catch (\Throwable $e) {
                    $breakRows = [];
                }
            }
            foreach ($breakRows as $br) {
                if ((string) ($br['name'] ?? '') !== '') {
                    $breakOptions[] = [(string) $br['name'], !empty($br['icon']) ? (string) $br['icon'] : '☕'];
                }
            }
            if ($breakOptions === []) {
                foreach (array_filter(array_map('trim', explode(',', getSystemSetting('cc_break_reasons', t('api_cc.default_break_reasons'))))) as $reason) {
                    $breakOptions[] = [$reason, '⏸️'];
                }
            }
        ?>
            <label class="header-phone-status-pill header-break-pill" id="header-break-pill" title="<?php echo t('topbar.break_selector_tooltip'); ?>">
                <span class="header-break-emoji" id="header-break-emoji" aria-hidden="true">✅</span>
                <span class="status-text" id="header-break-text"><?php echo htmlspecialchars($breakWorking); ?></span>
                <select id="header-break-select" class="header-break-select" onchange="handleHeaderBreakChange(this.value)" aria-label="<?php echo htmlspecialchars(t('topbar.break_selector_tooltip')); ?>">
                    <option value="" data-emoji="✅"><?php echo htmlspecialchars('✅ ' . $breakWorking); ?></option>
                    <?php foreach ($breakOptions as [$bName, $bIcon]): ?>
                        <option value="<?php echo htmlspecialchars($bName); ?>" data-emoji="<?php echo htmlspecialchars($bIcon); ?>"><?php echo htmlspecialchars($bIcon . ' ' . $bName); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <!-- Quick Phone Call & Dynamic Call Control Group -->
        <div class="header-quick-dial-wrapper" id="header-phone-controls">
            <!-- 1. IDLE / READY STATE CONTROLS -->
            <div id="header-idle-controls" style="display: flex; align-items: center; gap: 6px;">
                <!-- Quick Dial Input & Autocomplete Dropdown -->
                <input type="text" id="header-quick-dial-input" class="header-quick-dial-input" placeholder="<?php echo t('topbar.quick_dial_placeholder'); ?>" autocomplete="off" onkeydown="handleHeaderQuickDialKeydown(event)" oninput="handleHeaderQuickDialInput(this.value)">
                <div class="header-autocomplete-dropdown" id="header-autocomplete-dropdown" style="display: none;"></div>
                <button type="button" id="header-video-call-btn" class="topbar-btn btn-primary" onclick="headerQuickDialCall(true)" title="<?php echo t('topbar.video_call_tooltip'); ?>" style="display: none;">
                    <i class="fas fa-video"></i>
                </button>
            </div>

            <!-- 2. INCOMING CALL STATE CONTROLS -->
            <div id="header-incoming-controls" style="display: none; align-items: center; gap: 6px;">
                <button type="button" class="topbar-btn btn-success header-btn-pulse" onclick="headerPhoneAnswerCall()" title="<?php echo t('topbar.answer_tooltip'); ?>">
                    <i class="fas fa-phone"></i> <span class="topbar-btn-label"><?php echo t('topbar.answer_label'); ?></span>
                </button>
                <button type="button" id="header-answer-video-btn" class="topbar-btn btn-primary header-btn-pulse" onclick="headerPhoneAnswerCall(true)" title="<?php echo t('topbar.answer_video_tooltip'); ?>" style="display: none;">
                    <i class="fas fa-video"></i> <span class="topbar-btn-label"><?php echo t('topbar.answer_video_label'); ?></span>
                </button>
                <button type="button" class="topbar-btn btn-danger" onclick="headerPhoneRejectCall()" title="<?php echo t('topbar.reject_tooltip'); ?>">
                    <i class="fas fa-phone-slash"></i> <span class="topbar-btn-label"><?php echo t('topbar.reject_label'); ?></span>
                </button>
            </div>

            <!-- 3. ACTIVE CALL / DIALING STATE CONTROLS -->
            <div id="header-active-controls" style="display: none; align-items: center; gap: 6px;">
                <button type="button" class="topbar-btn" onclick="toggleHeaderSoftphoneDrawer()" title="<?php echo t('topbar.dtmf_tooltip'); ?>">
                    <i class="fas fa-th"></i>
                </button>
                <button type="button" id="header-camera-btn" class="topbar-btn" onclick="headerPhoneToggleCamera()" title="<?php echo t('topbar.camera_tooltip'); ?>" style="display: none;">
                    <i class="fas fa-video"></i>
                </button>
                <button type="button" id="header-hold-btn" class="topbar-btn btn-warning" onclick="headerPhoneToggleHold()" title="<?php echo t('topbar.hold_tooltip'); ?>">
                    <i class="fas fa-pause"></i> <span id="header-hold-text" class="topbar-btn-label"><?php echo t('topbar.hold_label'); ?></span>
                </button>
                <button type="button" class="topbar-btn btn-primary" onclick="headerPhonePromptTransfer()" title="<?php echo t('topbar.transfer_tooltip'); ?>">
                    <i class="fas fa-exchange-alt"></i> <span class="topbar-btn-label"><?php echo t('topbar.transfer_label'); ?></span>
                </button>
                <button type="button" class="topbar-btn btn-danger" onclick="headerPhoneHangup()" title="<?php echo t('topbar.hangup_tooltip'); ?>">
                    <i class="fas fa-phone-slash"></i> <span class="topbar-btn-label"><?php echo t('topbar.hangup_label'); ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Top Bar Right: Pending Sync (Uygula) Dropdown & Actions -->
    <div class="top-bar-right">
        <?php if (userHasRole('admin')): /* api/notices.php checks it too */ ?>
            <div class="header-notices-wrapper" id="header-notices">
                <button type="button" class="topbar-btn header-notices-btn" id="header-notices-btn" onclick="toggleNotices(event)"
                        title="<?php echo htmlspecialchars(t('notices.title')); ?>" aria-label="<?php echo htmlspecialchars(t('notices.title')); ?>" aria-expanded="false">
                    <i class="fas fa-bell"></i>
                    <span class="header-notices-count" id="header-notices-count" hidden>0</span>
                </button>
                <div class="header-sync-dropdown header-notices-dropdown" id="header-notices-dropdown" role="dialog" aria-label="<?php echo htmlspecialchars(t('notices.title')); ?>">
                    <div class="header-sync-dropdown-title"><i class="fas fa-bell"></i> <?php echo htmlspecialchars(t('notices.title')); ?></div>
                    <div class="header-notices-list" id="header-notices-list"><div class="header-notices-empty"><?php echo htmlspecialchars(t('notices.loading')); ?></div></div>
                    <a href="/health" class="header-notices-foot" onclick="closeNotices()"><?php echo htmlspecialchars(t('notices.all_checks')); ?> →</a>
                </div>
            </div>
        <?php endif; ?>
        <?php
        if (!isset($pending_sync_count)) {
            require_once __DIR__ . '/../src/asterisk_sync.php';
            $pending_sync_count = hasModulePermission('pending_sync', 'view') ? getPendingSyncCount() : 0;
        }
        ?>
        <?php if (hasModulePermission('pending_sync', 'view')): ?>
            <div class="header-sync-wrapper" id="header-pending-sync-container" style="display: <?php echo $pending_sync_count > 0 ? 'inline-flex' : 'none'; ?>;">
                <button type="button" class="btn header-sync-btn" id="header-sync-btn" onclick="toggleHeaderSyncDropdown(event)" title="<?php echo t('sidebar.pending_sync_tooltip'); ?>">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span class="header-sync-label"><?php echo t('sidebar.pending_sync_label'); ?></span>
                    <span class="header-sync-count" id="header-pending-sync-count"><?php echo $pending_sync_count; ?></span>
                    <i class="fas fa-chevron-down header-sync-arrow"></i>
                </button>

                <div class="header-sync-dropdown" id="headerSyncDropdown">
                    <div class="header-sync-dropdown-title">
                        <i class="fas fa-tasks"></i> <?php echo t('topbar.pending_changes_title'); ?> (<span id="header-sync-dropdown-count"><?php echo $pending_sync_count; ?></span>)
                    </div>
                    
                    <?php if (hasModulePermission('pending_sync', 'edit')): ?>
                    <button type="button" class="header-sync-dropdown-item" onclick="executeDirectPendingSync(event)">
                        <div class="sync-item-icon sync-item-icon-direct">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="sync-item-text">
                            <div class="sync-item-title"><?php echo t('topbar.direct_apply'); ?></div>
                            <div class="sync-item-desc"><?php echo t('topbar.direct_apply_desc'); ?></div>
                        </div>
                    </button>
                    <?php endif; ?>

                    <a href="/pending-sync" class="header-sync-dropdown-item" onclick="closeHeaderSyncDropdown()">
                        <div class="sync-item-icon sync-item-icon-review">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <div class="sync-item-text">
                            <div class="sync-item-title"><?php echo t('topbar.review_apply'); ?></div>
                            <div class="sync-item-desc"><?php echo t('topbar.review_apply_desc'); ?></div>
                        </div>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
