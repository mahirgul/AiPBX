<?php
/**
 * My phone & call management view
 */

$ext = $ext ?? '';
$details = $extDetails ?? [];
$isDnd = !empty($details['dnd_enabled']);
$cfNum = $details['call_forward_number'] ?? '';
$cfBusyNum = $details['cf_busy_number'] ?? '';
$cfNoAnsNum = $details['cf_noanswer_number'] ?? '';
$cfTimeout = intval($details['cf_noanswer_timeout'] ?? 20);
if ($cfTimeout < 5 || $cfTimeout > 120) {
    $cfTimeout = 20;
}
$phoneMode = $details['allowed_phone_mode'] ?? 'both';
$activeModes = parsePhoneModes($details['allowed_phone_mode'] ?? 'both');
$hasActiveCf = !empty($cfNum) || !empty($cfBusyNum) || !empty($cfNoAnsNum);
$currentTab = $tab ?? 'history';
$stats = $stats ?? [];
$calls = $calls ?? [];
$filter = $filter ?? 'all';
$search = $search ?? '';
$vmMessages = $voicemailMessages ?? [];
$vmEnabled = (int)($details['voicemail_enabled'] ?? 1);
$vmPin = $details['voicemail_pin'] ?? '';
$vmEmail = $details['voicemail_email'] ?? '';
$vmNotify = (int)($details['voicemail_email_notify'] ?? 1);
$vmAccountEmail = trim((string)($details['email'] ?? ''));
$vmAttach = (int)($details['voicemail_attach_audio'] ?? 1);
$vmNa = (int)($details['vm_on_noanswer'] ?? 0);
$vmBusy = (int)($details['vm_on_busy'] ?? 0);
$vmUnavail = (int)($details['vm_on_unavail'] ?? 0);
$vmAlways = (int)($details['vm_always'] ?? 0);
$isFaxUser = (($_SESSION['user_role'] ?? '') === 'fax_user' || ($details['extension_type'] ?? '') === 'fax');
if ($isFaxUser && ($currentTab ?? '') === 'voicemail') {
    $currentTab = 'history';
}
?>

<link rel="stylesheet" href="<?php echo asset('/assets/css/pages/my_phone.css'); ?>">

<!-- 1. Top extension & status bar -->
    <div class="card tabbed-page my-phone-header-card">
        <?php echo uiPageHeaderOpen('fa-phone-alt', htmlspecialchars($details['full_name'] ?? ''), 'myPhoneHelpBox'); ?>
<?php if ($ext !== ''): ?>
                        <span class="badge u-fs-12 u-p-2-8 u-bg-primary u-c-fff u-fw-700 u-r-12">
                            #<?php echo htmlspecialchars($ext); ?>
                        </span>
                    <?php endif; ?>
<span class="badge badge-secondary"><i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($details['role_name'] ?? ($details['role'] ?? '')); ?></span>
<!-- WebRTC status badge -->
            <?php $isWebrtcOnline = ($webrtcStatus && stripos($webrtcStatus, 'not in use') !== false); ?>
            <span id="my-phone-webrtc-pill" class="badge u-bg-bg-input u-bd-1-solid-border-color u-muted u-p-4-12 u-fs-12 u-r-8 u-ai-center u-gap-8" style="display: inline-flex;">
                <i class="fas fa-globe"></i> WebRTC: <strong id="my-phone-webrtc-text" style="color: <?php echo $isWebrtcOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;"><?php echo $isWebrtcOnline ? t('my_phone.webrtc_status_online') : t('my_phone.webrtc_status_offline'); ?></strong>
            </span>
            <!-- SIP status badge -->
            <?php $isSipOnline = ($sipStatus && stripos($sipStatus, 'not in use') !== false); ?>
            <span class="badge u-bg-bg-input u-bd-1-solid-border-color u-muted u-p-4-12 u-fs-12 u-r-8 u-d-inline-flex u-ai-center u-gap-8">
                <i class="fas fa-phone-square-alt"></i> SIP: 
                <strong style="color: <?php echo $isSipOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                    <?php echo $isSipOnline ? t('my_phone.sip_status_online') : t('my_phone.sip_status_offline'); ?>
                </strong>
            </span>
            <!-- Mobile status badge -->
            <?php $isMobileOnline = (!empty($mobileStatus) && stripos($mobileStatus, 'not in use') !== false); ?>
            <span class="badge u-bg-bg-input u-bd-1-solid-border-color u-muted u-p-4-12 u-fs-12 u-r-8 u-d-inline-flex u-ai-center u-gap-8">
                <i class="fas fa-mobile-alt"></i> <?php echo t('my_phone.lbl_mobile'); ?>: 
                <strong style="color: <?php echo $isMobileOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                    <?php echo $isMobileOnline ? t('my_phone.mobile_status_online') : t('my_phone.mobile_status_offline'); ?>
                </strong>
            </span>
            <!-- DND badge -->
            <?php if ($isDnd): ?>
                <span class="badge u-bg-rgba-239-68-68-0-15 u-danger u-bd-1-solid-rgba-239-68-68-0-3 u-p-4-12 u-fs-12 u-r-8">
                    <i class="fas fa-minus-circle"></i> <?php echo t('my_phone.dnd_on'); ?>
                </span>
            <?php endif; ?>
            <!-- Call forwarding badge -->
            <?php if ($cfNum !== ''): ?>
                <span class="badge u-bg-rgba-245-158-11-0-15 u-warning u-bd-1-solid-rgba-245-158-11-0-3 u-p-4-12 u-fs-12 u-r-8">
                    <i class="fas fa-share"></i> <?php echo htmlspecialchars($cfNum); ?>
                </span>
            <?php endif; ?>
<?php echo uiPageHeaderActions(); ?>
<?php echo uiPageHeaderClose(); ?>
<?php echo uiHelpBoxOpen('myPhoneHelpBox', t('common.module_guide')); ?><?php echo t('my_phone.help_body'); ?><?php echo uiHelpBoxClose(); ?>

    <?php if ($ext === ''): ?>
        <div class="card mb-4 u-ta-center u-p-48-24">
            <i class="fas fa-phone-slash u-fs-48 u-muted u-opacity-0-5 u-mb-16"></i>
            <h3 class="u-fs-18 u-fw-700 u-text-main u-mb-8">
                <?php echo t('my_phone.no_extension'); ?>
            </h3>
            <p class="u-muted u-fs-13 u-maxw-480 u-m-0-auto">
                <?php echo t('my_phone.no_extension_help'); ?>
            </p>
        </div>
    <?php else: ?>
        <!-- 3. Tab navigation buttons -->
        <?php
        $myPhoneTabs = [
            'history' => ['label' => t('my_phone.tab_history'), 'icon' => 'fa-history', 'badge' => (string) count($calls)],
            'calls' => ['label' => t('my_phone.tab_calls'), 'icon' => 'fa-phone-alt'] + (($isDnd || $hasActiveCf) ? ['badge_html' => '<i class="fas fa-check"></i> ' . htmlspecialchars(t('common.active')), 'badge_class' => 'badge-warning'] : []),
            'settings' => ['label' => t('my_phone.tab_settings'), 'icon' => 'fa-sliders-h'],
        ];
        if (!$isFaxUser) {
            $myPhoneTabs['voicemail'] = ['label' => t('my_phone.tab_voicemail'), 'icon' => 'fa-voicemail', 'badge' => (string) count($vmMessages), 'badge_class' => !empty($vmMessages) ? 'badge-danger' : 'badge-secondary'];
        }
        echo uiTabs($myPhoneTabs, $currentTab);
        ?>

        <!-- TAB 1: MY CALL HISTORY (wide & modern data table) -->
        <div id="tab-pane-history" class="card" data-tab-pane="history"<?php echo $currentTab === 'history' ? '' : ' hidden'; ?>>
            <div class="card-header u-pb-16 u-mb-16 u-border-bottom-1-solid-border-color u-d-flex u-wrap u-ai-center u-jc-space-between u-gap-12">
                <div class="card-title u-flex-center u-title">
                    <i class="fas fa-phone-volume u-primary"></i> <?php echo t('my_phone.recent_calls'); ?>
                </div>
                <div class="u-flex-center">
                    <a href="/my-phone?tab=history" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('common.refresh'); ?>"><i class="fas fa-sync-alt"></i></a>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="switchMyPhoneTab('calls')" title="<?php echo htmlspecialchars(t('my_phone.tab_calls')); ?>">
                        <i class="fas fa-phone-alt"></i> <?php echo t('my_phone.tab_calls'); ?>
                    </button>
                </div>
            </div>

            <!-- Filter & search bar (same as the CDR reports) -->
            <form method="GET" action="/my-phone" id="myPhoneFilterForm" class="my-phone-filter-bar">
                <input type="hidden" name="tab" value="history">
                <input type="hidden" name="filter" id="myPhoneFilterInput" value="<?php echo htmlspecialchars($filter); ?>">

                <!-- Direction filter buttons -->
                <div class="my-phone-filter-buttons">
                    <button type="button" onclick="setMyPhoneFilter('all')" class="btn btn-xs <?php echo $filter === 'all' ? 'btn-primary' : 'btn-ghost'; ?> u-r-8 u-fw-600">
                        <?php echo t('my_phone.filter_all'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('in')" class="btn btn-xs <?php echo $filter === 'in' ? 'btn-primary' : 'btn-ghost'; ?> u-r-8 u-fw-600 u-d-inline-flex u-ai-center u-gap-4">
                        <i class="fas fa-arrow-down u-success"></i> <?php echo t('my_phone.filter_in'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('out')" class="btn btn-xs <?php echo $filter === 'out' ? 'btn-primary' : 'btn-ghost'; ?> u-r-8 u-fw-600 u-d-inline-flex u-ai-center u-gap-4">
                        <i class="fas fa-arrow-up u-primary"></i> <?php echo t('my_phone.filter_out'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('missed')" class="btn btn-xs <?php echo $filter === 'missed' ? 'btn-primary' : 'btn-ghost'; ?> u-r-8 u-fw-600 u-d-inline-flex u-ai-center u-gap-4">
                        <i class="fas fa-phone-slash u-danger"></i> <?php echo t('my_phone.filter_missed'); ?>
                    </button>
                </div>

                <!-- Search box -->
                <div class="my-phone-search-wrapper u-relative u-flex-1 u-minw-200 u-maxw-380">
                    <i class="fas fa-search u-pos-absolute u-left-12 u-top-50pc u-transform-translatey-50pc u-muted u-fs-12 u-pointer-events-none"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm u-pl-32 u-r-8" placeholder="<?php echo t('my_phone.search_placeholder'); ?>">
                </div>

                <button type="submit" class="btn btn-icon btn-primary btn-sm u-r-8" title="<?php echo t('common.filter'); ?>">
                    <i class="fas fa-filter"></i>
                </button>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a href="/my-phone?tab=history" class="btn btn-icon btn-secondary btn-sm u-r-8 u-d-inline-flex u-ai-center" title="<?php echo t('common.reset'); ?>">
                        <i class="fas fa-undo"></i>
                    </a>
                <?php endif; ?>
            </form>

            <!-- Rich data table in the CDR style -->
            <?php echo uiTableOpen([
                ['label' => '#', 'class' => 'col-hide-mobile u-w-45'],
                t('my_phone.col_date'),
                t('my_phone.col_direction'),
                t('my_phone.col_party'),
                ['label' => t('my_phone.col_device'), 'class' => 'col-hide-mobile'],
                t('my_phone.col_duration'),
                t('my_phone.col_status'),
                ['label' => t('my_phone.col_actions'), 'class' => 'text-right'],
            ]); ?>
                        <?php if (empty($calls)): ?>
                            <tr>
                                <td class="u-ta-center u-muted u-p-48-24" colspan="8">
                                    <i class="fas fa-history u-fs-40 u-opacity-0-35 u-mb-12" style="display: block;"></i>
                                    <div class="u-fw-600 u-fs-14 u-text-main u-mb-4"><?php echo t('my_phone.no_calls'); ?></div>
                                    <small class="u-fs-12"><?php echo t('my_phone.no_calls_desc'); ?></small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $formatDuration = fn(int $sn) => sprintf('%02d:%02d', intdiv($sn, 60), $sn % 60);
                            foreach ($calls as $c):
                                $dir = $c['direction'];
                                $bill = (int)$c['billsec'];
                                $ring = (int)($c['ring_sec'] ?? 0);
                                $dur = (int)$c['duration'];
                                $party = htmlspecialchars($c['party']);
                                $partyName = htmlspecialchars($c['party_name'] ?? '');
                                $dev = $c['device_type'] ?? '';
                                $hasRec = !empty($c['has_recording']);

                                // Status Badge Color
                                $disp = $c['disposition'];
                                if ($disp === 'ANSWERED') {
                                    $badgeClass = 'badge-success';
                                    $dispLabel = t('my_phone.disp_answered');
                                } elseif ($disp === 'BUSY') {
                                    $badgeClass = 'badge-info';
                                    $dispLabel = t('my_phone.disp_busy');
                                } elseif (in_array($disp, ['NO ANSWER', 'NOANSWER', 'CANCEL'])) {
                                    $badgeClass = 'badge-warning';
                                    $dispLabel = t('my_phone.disp_noanswer');
                                } else {
                                    $badgeClass = 'badge-danger';
                                    $dispLabel = htmlspecialchars($disp);
                                }
                            ?>
                                <tr>
                                    <!-- ID -->
                                    <td class="col-hide-mobile u-muted u-fs-12">#<?php echo $c['id']; ?></td>
                                    
                                    <!-- Date & time -->
                                    <td class="u-fw-600 u-nowrap">
                                        <?php echo date('d.m.Y H:i:s', strtotime($c['calldate'])); ?>
                                    </td>

                                    <!-- Direction badge -->
                                    <td>
                                        <?php if ($dir === 'in'): ?>
                                            <span class="badge u-bg-rgba-16-185-129-0-15 u-c-059669 u-bd-1-solid-rgba-16-185-129-0-35 u-fs-11 u-fw-600 u-p-4-8 u-r-8 u-d-inline-flex u-ai-center u-gap-4">
                                                <i class="fas fa-arrow-down"></i> <?php echo t('my_phone.filter_in'); ?>
                                            </span>
                                        <?php elseif ($dir === 'out'): ?>
                                            <span class="badge u-bg-rgba-59-130-246-0-15 u-c-2563eb u-bd-1-solid-rgba-59-130-246-0-35 u-fs-11 u-fw-600 u-p-4-8 u-r-8 u-d-inline-flex u-ai-center u-gap-4">
                                                <i class="fas fa-arrow-up"></i> <?php echo t('my_phone.filter_out'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge u-bg-rgba-239-68-68-0-15 u-c-dc2626 u-bd-1-solid-rgba-239-68-68-0-35 u-fs-11 u-fw-600 u-p-4-8 u-r-8 u-d-inline-flex u-ai-center u-gap-4">
                                                <i class="fas fa-phone-slash"></i> <?php echo t('my_phone.filter_missed'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Other party -->
                                    <td>
                                        <div class="u-fw-700 u-primary u-fs-13-5 u-d-flex u-ai-center u-gap-6">
                                            <i class="fas fa-phone-alt u-fs-11 u-opacity-0-7"></i>
                                            <span><?php echo $party; ?></span>
                                        </div>
                                        <?php if (!empty($partyName) && $partyName !== $party): ?>
                                            <div class="u-fs-11 u-muted u-mt-2">
                                                <i class="fas fa-user u-fs-10 u-mr-3"></i><?php echo $partyName; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Device -->
                                    <td class="col-hide-mobile">
                                        <?php if ($dev === 'mobil'): ?>
                                            <span class="badge u-bg-rgba-13-202-240-0-15 u-c-087990 u-bd-1-solid-rgba-13-202-240-0-35 u-fs-11 u-fw-600 u-p-4-8 u-r-8 u-d-inline-flex u-ai-center u-gap-4">
                                                <i class="fas fa-mobile-alt"></i> <?php echo t('my_phone.lbl_mobile'); ?>
                                            </span>
                                        <?php elseif ($dev === 'webrtc'): ?>
                                            <span class="badge u-bg-rgba-16-185-129-0-15 u-c-059669 u-bd-1-solid-rgba-16-185-129-0-35 u-fs-11 u-fw-600 u-p-4-8 u-r-8 u-d-inline-flex u-ai-center u-gap-4">
                                                <i class="fas fa-desktop"></i> WebRTC
                                            </span>
                                        <?php elseif ($dev === 'sip'): ?>
                                            <span class="badge u-bg-rgba-100-116-139-0-15 u-c-475569 u-bd-1-solid-rgba-100-116-139-0-35 u-fs-11 u-fw-600 u-p-4-8 u-r-8 u-d-inline-flex u-ai-center u-gap-4">
                                                <i class="fas fa-phone-alt"></i> SIP
                                            </span>
                                        <?php else: ?>
                                            <span class="u-muted u-fs-12">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Duration -->
                                    <td>
                                        <?php if ($disp === 'ANSWERED'): ?>
                                            <div class="u-mono u-fs-13 u-fw-700 u-success">
                                                <i class="fas fa-phone-volume u-fs-11 u-mr-4"></i><?php echo $formatDuration($bill); ?>
                                            </div>
                                            <div class="u-fs-11 u-muted u-mt-2 u-nowrap">
                                                <?php echo t('my_phone.ring'); ?>: <?php echo $formatDuration($ring); ?> • <?php echo t('my_phone.total'); ?>: <?php echo $formatDuration($dur); ?>
                                            </div>
                                        <?php elseif (in_array($disp, ['NO ANSWER', 'NOANSWER', 'CANCEL', 'BUSY'])): ?>
                                            <div class="u-mono u-fs-12 u-warning u-fw-600">
                                                <i class="fas fa-bell u-fs-10 u-mr-3"></i><?php echo t('my_phone.ring'); ?>: <?php echo $formatDuration($ring > 0 ? $ring : $dur); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="u-muted u-fs-12 u-mono">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status -->
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?> u-fs-11">
                                            <?php echo $dispLabel; ?>
                                        </span>
                                    </td>

                                    <!-- Aksiyonlar -->
                                    <td class="text-right u-nowrap">
                                        <div class="u-d-inline-flex u-ai-center u-gap-6">
                                            <?php if ($hasRec): ?>
                                                <button type="button" class="btn btn-icon btn-secondary btn-sm" onclick="playCdrAudio(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($party), ENT_QUOTES); ?>', '<?php echo date('d.m.Y H:i', strtotime($c['calldate'])); ?>')" title="<?php echo t('my_phone.listen_recording'); ?>">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <a href="/api/cc_audio.php?id=<?php echo $c['id']; ?>&download=1" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('my_phone.download_recording'); ?>">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-success u-r-8 u-fw-600 u-d-inline-flex u-ai-center u-gap-4" onclick="callTargetNumber(<?php echo jsArg($party); ?>)" title="<?php echo htmlspecialchars(sprintf(t('my_phone.call_number'), $party)); ?>">
                                                <i class="fas fa-phone"></i> <?php echo t('my_phone.btn_call'); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php echo uiTableClose(); ?>
        </div>

        <!-- TAB: Call settings (general, forwarding, voicemail) -->
        <div id="tab-pane-calls" class="my-phone-settings-grid" data-tab-pane="calls"<?php echo $currentTab === 'calls' ? '' : ' hidden'; ?>>
            <!-- Settings cards share one form (display: contents) so any Save stores every setting. -->
            <form method="POST" action="/my-phone" class="my-phone-settings-form">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">

            <!-- Card: do not disturb -->
            <div class="card u-p-24 u-r-12">
                <h3 class="u-fs-16 u-fw-700 u-mb-16 u-text-main u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-minus-circle u-danger"></i> <?php echo t('my_phone.dnd_label'); ?>
                </h3>
                <label class="u-d-flex u-ai-center u-jc-space-between u-gap-12 u-pointer u-m-0-0-20">
                    <small class="u-muted u-fs-12 u-lh-1-4"><?php echo t('my_phone.dnd_desc'); ?></small>
                    <input class="u-w-20 u-h-20 u-pointer u-flex-shrink-0" type="checkbox" name="dnd_enabled" value="1" <?php echo $isDnd ? 'checked' : ''; ?>>
                </label>
                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <div class="form-actions"><button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button></div>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary u-w-100 u-r-8 u-fw-700" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>

            <!-- Card: phone modes -->
            <div class="card u-p-24 u-r-12">
                <h3 class="u-fs-16 u-fw-700 u-mb-16 u-text-main u-d-flex u-ai-center u-jc-space-between u-gap-8">
                    <span class="u-d-flex u-ai-center u-gap-8"><i class="fas fa-phone-volume u-primary"></i> <?php echo t('my_phone.active_phone_modes'); ?></span>
                    <span class="badge badge-info u-fw-600 u-fs-10"><?php echo count($activeModes); ?> / 4 <?php echo t('my_phone.modes_active'); ?></span>
                </h3>
                        <small class="u-muted u-fs-11 u-mb-12 u-lh-1-4" style="display: block;">
                            <?php echo t('my_phone.phone_modes_desc'); ?>
                        </small>

                        <div class="my-phone-modes-grid">
                            <!-- Web (browser) -->
                            <label class="u-d-flex u-ai-center u-gap-12 u-p-12-12 u-bg-bg-card u-bd-1-solid-border-color u-r-8 u-pointer u-m-0 u-user-select-none">
                                <input class="u-w-17 u-h-17 u-pointer u-accent" type="checkbox" name="phone_modes[]" value="web" <?php echo in_array('web', $activeModes, true) ? 'checked' : ''; ?>>
                                <div class="u-d-flex u-col">
                                    <span class="u-fw-600 u-fs-13 u-text-main u-d-flex u-ai-center u-gap-4">
                                        <i class="fas fa-laptop u-primary u-fs-12"></i> <?php echo t('my_phone.mode_web'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_web_sub'); ?></small>
                                </div>
                            </label>

                            <!-- Mobil (Uygulama) -->
                            <label class="u-d-flex u-ai-center u-gap-12 u-p-12-12 u-bg-bg-card u-bd-1-solid-border-color u-r-8 u-pointer u-m-0 u-user-select-none">
                                <input class="u-w-17 u-h-17 u-pointer u-accent-color-success" type="checkbox" name="phone_modes[]" value="mobil" <?php echo in_array('mobil', $activeModes, true) ? 'checked' : ''; ?>>
                                <div class="u-d-flex u-col">
                                    <span class="u-fw-600 u-fs-13 u-text-main u-d-flex u-ai-center u-gap-4">
                                        <i class="fas fa-mobile-alt u-success u-fs-12"></i> <?php echo t('my_phone.mode_mobil'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_mobil_sub'); ?></small>
                                </div>
                            </label>

                            <!-- SIP (desk phone) -->
                            <label class="u-d-flex u-ai-center u-gap-12 u-p-12-12 u-bg-bg-card u-bd-1-solid-border-color u-r-8 u-pointer u-m-0 u-user-select-none">
                                <input class="u-w-17 u-h-17 u-pointer u-accent-color-secondary" type="checkbox" name="phone_modes[]" value="sip" <?php echo in_array('sip', $activeModes, true) ? 'checked' : ''; ?>>
                                <div class="u-d-flex u-col">
                                    <span class="u-fw-600 u-fs-13 u-text-main u-d-flex u-ai-center u-gap-4">
                                        <i class="fas fa-phone-alt u-c-secondary u-fs-12"></i> <?php echo t('my_phone.mode_sip_desk'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_sip_sub'); ?></small>
                                </div>
                            </label>

                            <!-- Video -->
                            <label class="u-d-flex u-ai-center u-gap-12 u-p-12-12 u-bg-bg-card u-bd-1-solid-border-color u-r-8 u-pointer u-m-0 u-user-select-none">
                                <input class="u-w-17 u-h-17 u-pointer u-accent-color-8b5cf6" type="checkbox" name="phone_modes[]" value="video" <?php echo in_array('video', $activeModes, true) ? 'checked' : ''; ?>>
                                <div class="u-d-flex u-col">
                                    <span class="u-fw-600 u-fs-13 u-text-main u-d-flex u-ai-center u-gap-4">
                                        <i class="fas fa-video u-c-8b5cf6 u-fs-12"></i> <?php echo t('my_phone.mode_video'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_video_sub'); ?></small>
                                </div>
                            </label>
                        </div>
                <div class="u-mt-18"></div>
                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <div class="form-actions"><button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button></div>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary u-w-100 u-r-8 u-fw-700" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>

            <!-- Card: forwarding -->
            <div class="card u-p-24 u-r-12">
                    <!-- Call forwarding options (always, when busy, on no answer) -->
                    <div class="u-mb-20">
                        <div class="u-d-flex u-ai-center u-jc-space-between u-mb-14">
                            <div class="u-fw-700 u-fs-16 u-text-main u-d-flex u-ai-center u-gap-8">
                                <i class="fas fa-share u-warning"></i> <?php echo t('my_phone.cf_card_title'); ?>
                            </div>
                            <?php
                                $hasActiveCf = !empty($cfNum) || !empty($cfBusyNum) || !empty($cfNoAnsNum);
                            ?>
                            <span class="badge <?php echo $hasActiveCf ? 'badge-warning' : 'badge-secondary'; ?> u-fs-10-5 u-fw-600">
                                <i class="fas <?php echo $hasActiveCf ? 'fa-check-circle' : 'fa-ban'; ?>"></i>
                                <?php echo $hasActiveCf ? t('my_phone.cf_status_active') : t('my_phone.cf_status_disabled'); ?>
                            </span>
                        </div>

                        <!-- 1. Always forward (unconditional) -->
                        <div class="form-group u-mb-14">
                            <label class="form-label u-fs-11-5 u-fw-600 u-d-flex u-ai-center u-jc-space-between u-mb-4">
                                <span><i class="fas fa-forward u-warning u-mr-4"></i> <?php echo t('my_phone.cf_always_label'); ?></span>
                                <?php if (!empty($cfNum)): ?>
                                    <span class="badge badge-warning u-fs-10 u-p-2-8"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="call_forward_number" value="<?php echo htmlspecialchars($cfNum); ?>" class="form-control form-control-sm u-fs-12-5" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>">
                            <small class="u-muted u-fs-11 u-mt-2" style="display: block;">
                                <?php echo t('my_phone.cf_always_help'); ?>
                            </small>
                        </div>

                        <!-- 2. Forward when busy -->
                        <div class="form-group u-mb-14">
                            <label class="form-label u-fs-11-5 u-fw-600 u-d-flex u-ai-center u-jc-space-between u-mb-4">
                                <span><i class="fas fa-phone-slash u-danger u-mr-4"></i> <?php echo t('my_phone.cf_busy_label'); ?></span>
                                <?php if (!empty($cfBusyNum)): ?>
                                    <span class="badge badge-info u-fs-10 u-p-2-8"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="cf_busy_number" value="<?php echo htmlspecialchars($cfBusyNum); ?>" class="form-control form-control-sm u-fs-12-5" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>">
                            <small class="u-muted u-fs-11 u-mt-2" style="display: block;">
                                <?php echo t('my_phone.cf_busy_help'); ?>
                            </small>
                        </div>

                        <!-- 3. Forward on no answer -->
                        <div class="form-group u-mb-0">
                            <label class="form-label u-fs-11-5 u-fw-600 u-d-flex u-ai-center u-jc-space-between u-mb-4">
                                <span><i class="fas fa-phone-volume u-c-info u-mr-4"></i> <?php echo t('my_phone.cf_noanswer_label'); ?></span>
                                <?php if (!empty($cfNoAnsNum)): ?>
                                    <span class="badge badge-info u-fs-10 u-p-2-8"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <div class="u-flex-gap">
                                <input type="text" name="cf_noanswer_number" value="<?php echo htmlspecialchars($cfNoAnsNum); ?>" class="form-control form-control-sm u-fs-12-5 u-flex-1" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>">
                                <select name="cf_noanswer_timeout" class="form-control form-control-sm u-w-90 u-fs-12" title="<?php echo t('my_phone.cf_timeout_title'); ?>">
                                    <option value="10" <?php echo $cfTimeout === 10 ? 'selected' : ''; ?>>10 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="15" <?php echo $cfTimeout === 15 ? 'selected' : ''; ?>>15 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="20" <?php echo $cfTimeout === 20 ? 'selected' : ''; ?>>20 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="25" <?php echo $cfTimeout === 25 ? 'selected' : ''; ?>>25 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="30" <?php echo $cfTimeout === 30 ? 'selected' : ''; ?>>30 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="45" <?php echo $cfTimeout === 45 ? 'selected' : ''; ?>>45 <?php echo t('my_phone.sec'); ?></option>
                                </select>
                            </div>
                            <small class="u-muted u-fs-11 u-mt-2" style="display: block;">
                                <?php echo t('my_phone.cf_noanswer_help'); ?>
                            </small>
                        </div>
                    </div>

                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <div class="form-actions"><button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button></div>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary u-w-100 u-r-8 u-fw-700" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>

            </form>

        </div>

        <!-- TAB 2: PHONE & DEVICE SETTINGS (wide 3-column grid) -->
        <div id="tab-pane-settings" class="my-phone-settings-grid" data-tab-pane="settings"<?php echo $currentTab === 'settings' ? '' : ' hidden'; ?>>
            
            <!-- Card 2: WebRTC device & ring tone settings -->
            <div class="card u-p-24 u-r-12">
                <h3 class="u-fs-16 u-fw-700 u-mb-20 u-text-main u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-headphones u-primary"></i> <?php echo t('my_phone.device_settings'); ?>
                </h3>

                <!-- Mikrofon -->
                <div class="form-group u-mb-16">
                    <label class="form-label u-fw-600 u-fs-12">
                        <i class="fas fa-microphone"></i> <?php echo t('phone_settings.field_mic'); ?>
                    </label>
                    <select id="my_phone_mic_select" class="form-control u-fs-12-5" onchange="savePhoneMicDevice(this.value)">
                        <option value="default"><?php echo t('my_phone.system_default'); ?></option>
                    </select>
                </div>

                <!-- Speaker -->
                <div class="form-group u-mb-16">
                    <label class="form-label u-fw-600 u-fs-12">
                        <i class="fas fa-volume-up"></i> <?php echo t('phone_settings.field_speaker'); ?>
                    </label>
                    <div class="u-flex-gap">
                        <select id="my_phone_speaker_select" class="form-control u-flex-1 u-fs-12-5" onchange="savePhoneSpeakerDevice(this.value)">
                            <option value="default"><?php echo t('my_phone.system_default'); ?></option>
                        </select>
                        <button type="button" class="btn btn-icon btn-secondary" onclick="testPhoneSpeaker()" title="<?php echo t('phone_settings.test_sound_tooltip'); ?>">
                            <i class="fas fa-play"></i>
                        </button>
                    </div>
                </div>

                <!-- Ringtone volume -->
                <div class="form-group u-mb-0">
                    <label class="form-label u-fs-12 u-fw-600 u-d-flex u-jc-space-between">
                        <span><i class="fas fa-bell"></i> <?php echo t('phone_settings.field_ring_volume'); ?></span>
                        <span id="my-phone-vol-label" class="u-primary u-fw-700">100%</span>
                    </label>
                    <input type="range" id="my_phone_ring_slider" min="0" max="100" value="100" class="u-w-100 u-pointer" oninput="updateVolSlider(this.value)">
                </div>
            </div>

            <!-- Card 3: desk IP phone registration details (credentials) -->
            <div class="card u-p-24 u-r-12">
                <h3 class="u-fs-16 u-fw-700 u-mb-20 u-text-main u-d-flex u-ai-center u-gap-8">
                    <i class="fas fa-key u-primary"></i> <?php echo t('my_phone.sip_credentials_title'); ?>
                </h3>
                <div class="u-fs-13 u-bg-bg-input u-p-16 u-r-12 u-bd-1-solid-border-color u-d-flex u-col u-gap-12">
                    <div class="u-d-flex u-jc-space-between">
                        <span class="u-muted"><?php echo t('my_phone.sip_server'); ?>:</span>
                        <strong class="u-mono"><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? '127.0.0.1'); ?></strong>
                    </div>
                    <div class="u-d-flex u-jc-space-between">
                        <span class="u-muted"><?php echo t('my_phone.sip_port'); ?>:</span>
                        <strong class="u-mono">5060 (UDP/TCP)</strong>
                    </div>
                    <div class="u-d-flex u-jc-space-between">
                        <span class="u-muted"><?php echo t('my_phone.sip_username'); ?>:</span>
                        <strong class="u-mono u-primary"><?php echo htmlspecialchars($ext); ?></strong>
                    </div>
                    <div class="u-flex-between">
                        <span class="u-muted"><?php echo t('my_phone.sip_password'); ?>:</span>
                        <div class="u-d-flex u-ai-center u-gap-6">
                            <input class="u-bg-transparent u-bd-none u-mono u-w-110 u-ta-right u-text-main" type="password" id="my_phone_sip_pass_val" value="<?php echo htmlspecialchars($details['sip_password'] ?? ''); ?>" readonly />
                            <button type="button" class="btn btn-icon btn-xs btn-ghost" onclick="toggleSipPassVisibility()" title="<?php echo t('my_phone.show_hide'); ?>">
                                <i class="fas fa-eye" id="my_phone_pass_eye"></i>
                            </button>
                            <button type="button" class="btn btn-icon btn-xs btn-ghost" onclick="copySipPassword()" title="<?php echo t('common.copy'); ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 4: mobile app & device information -->
            <div class="card u-p-24 u-r-12">
                <h3 class="u-fs-16 u-fw-700 u-mb-20 u-text-main u-d-flex u-ai-center u-jc-space-between u-wrap u-gap-8">
                    <span class="u-flex-center">
                        <i class="fab fa-android u-c-3ddc84"></i> <?php echo t('my_phone.mobile_app_info'); ?>
                    </span>
                    <div class="u-flex-center">
                        <a href="<?php echo htmlspecialchars(ANDROID_PLAY_URL); ?>" target="_blank" rel="noopener" class="btn btn-xs btn-primary">
                            <i class="fab fa-google-play"></i> <?php echo t('common.get_on_google_play'); ?>
                        </a>
                    </div>
                </h3>

                <!-- QR code quick mobile sign-in intro box -->
                <div class="u-bg-linear-gradient-135deg-rgba-37-99-235-0-06-rgba-16-185-129-0-06 u-bd-1-dashed-rgba-37-99-235-0-28 u-r-12 u-p-16-16 u-mb-16 u-d-flex u-ai-center u-jc-space-between u-gap-12 u-wrap">
                    <div class="u-d-flex u-ai-center u-gap-12">
                        <div class="u-w-38 u-h-38 u-r-8 u-bg-rgba-37-99-235-0-12 u-d-flex u-ai-center u-jc-center u-primary u-fs-18">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <div>
                            <div class="u-fw-700 u-fs-13 u-text-main"><?php echo t('my_phone.quick_mobile_login'); ?></div>
                            <div class="u-fs-11-5 u-muted"><?php echo t('my_phone.quick_mobile_login_desc'); ?></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary u-fw-600 u-d-inline-flex u-ai-center u-gap-8" onclick="openQrLoginModal()">
                        <i class="fas fa-qrcode"></i> <?php echo t('my_phone.show_qr'); ?>
                    </button>
                </div>

                <?php if (empty($mobileDevices)): ?>
                    <div class="u-fs-13 u-bg-bg-input u-p-16 u-r-12 u-bd-1-solid-border-color u-muted u-ta-center">
                        <i class="fas fa-mobile-alt u-fs-24 u-mb-8 u-opacity-0-5" style="display: block;"></i>
                        <?php echo t('my_phone.no_device'); ?><br>
                        <?php echo t('my_phone.no_device_desc'); ?>
                    </div>
                <?php else: ?>
                    <div class="u-d-flex u-col u-gap-12">
                        <?php foreach ($mobileDevices as $md): ?>
                            <div class="u-fs-13 u-bg-bg-input u-p-16-16 u-r-12 u-bd-1-solid-border-color u-d-flex u-col u-gap-8">
                                <div class="u-flex-between">
                                    <strong class="u-fs-13 u-text-main">
                                        <i class="fas fa-mobile-alt u-mr-6 u-primary"></i>
                                        <?php echo htmlspecialchars($md['device_name'] ?: 'Android'); ?>
                                    </strong>
                                    <span class="badge badge-success u-fs-10 u-p-4-8">
                                        v<?php echo htmlspecialchars($md['app_version'] ?: '1.0'); ?>
                                    </span>
                                </div>
                                <div class="u-d-flex u-jc-space-between u-muted u-fs-11-5">
                                    <span><?php echo t('my_phone.platform'); ?>:</span>
                                    <span><?php echo htmlspecialchars(ucfirst($md['platform'])); ?></span>
                                </div>
                                <div class="u-d-flex u-jc-space-between u-muted u-fs-11-5">
                                    <span><?php echo t('my_phone.last_sync'); ?>:</span>
                                    <span><?php echo htmlspecialchars($md['updated_at']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <?php if (!$isFaxUser): ?>
        <!-- TAB 3: MY VOICEMAIL (voicemail messages) -->
        <div id="tab-pane-voicemail" class="card" data-tab-pane="voicemail"<?php echo $currentTab === 'voicemail' ? '' : ' hidden'; ?>>
            <div class="card-header u-pb-16 u-mb-16 u-border-bottom-1-solid-border-color u-d-flex u-wrap u-ai-center u-jc-space-between u-gap-12">
                <div class="card-title u-flex-center u-title">
                    <i class="fas fa-voicemail u-primary"></i> <?php echo t('my_phone.voicemail_inbox'); ?>
                </div>
                <div class="u-d-flex u-ai-center u-gap-10 u-fs-12 u-muted">
                    <span><i class="fas fa-phone-alt text-info"></i> <?php echo t('my_phone.vm_internal'); ?>: <strong class="badge badge-info u-fs-11">*97</strong></span>
                    <span><i class="fas fa-hashtag text-warning"></i> <?php echo t('my_phone.vm_remote'); ?>: <strong class="badge badge-secondary u-fs-11">*98</strong></span>
                    <a href="/my-phone?tab=voicemail" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('common.refresh'); ?>"><i class="fas fa-sync-alt"></i></a>
                </div>
            </div>

            <!-- Voicemail settings: own form, so saving it never touches the call settings (and back) -->
            <form method="POST" action="/my-phone" class="card u-bg-bg-input u-bd-1-solid-border-color u-r-12 u-p-16 u-mb-16">
                <input type="hidden" name="action" value="save_voicemail">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
                <div class="u-flex-between u-mb-10 u-wrap u-gap-8">
                    <div class="u-fw-700 u-fs-13 u-text-main"><i class="fas fa-sliders-h u-primary"></i> <?php echo t('my_phone.vm_settings_title'); ?></div>
                    <label class="u-flex-center u-fs-12 u-gap-6 u-pointer u-m-0">
                        <input type="checkbox" name="voicemail_enabled" value="1" <?php echo $vmEnabled ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.enabled'); ?></span>
                    </label>
                </div>
                <div class="my-phone-vm-grid">
                    <div class="form-group u-mb-0">
                        <label class="form-label u-fs-11 u-mb-4"><?php echo t('my_phone.vm_pin'); ?></label>
                        <input type="password" name="voicemail_pin" value="" autocomplete="new-password" inputmode="numeric" class="form-control form-control-sm u-fs-12" placeholder="<?php echo t('my_phone.vm_pin_keep'); ?>">
                    </div>
                    <div class="form-group u-mb-0">
                        <label class="form-label u-fs-11 u-mb-4"><?php echo t('my_phone.vm_email'); ?></label>
                        <input type="email" name="voicemail_email" value="<?php echo htmlspecialchars($vmEmail); ?>" class="form-control form-control-sm u-fs-12" placeholder="<?php echo htmlspecialchars($vmAccountEmail !== '' ? $vmAccountEmail : t('my_phone.email_placeholder')); ?>">
                        <small class="u-fs-11 u-muted"><?php echo t('my_phone.vm_email_help'); ?></small>
                    </div>
                </div>
                <div class="my-phone-vm-grid u-fs-12 u-mt-12">
                    <div class="u-d-flex u-col u-gap-6">
                        <div class="u-fw-700 u-fs-11"><?php echo t('my_phone.vm_notify_title'); ?></div>
                    <label class="u-flex-center u-gap-8 u-pointer u-m-0">
                        <input type="checkbox" name="voicemail_email_notify" value="1" <?php echo $vmNotify ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.vm_email_notify'); ?></span>
                    </label>
                    <label class="u-flex-center u-gap-8 u-pointer u-m-0">
                        <input type="checkbox" name="voicemail_attach_audio" value="1" <?php echo $vmAttach ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.vm_attach'); ?></span>
                    </label>
                    </div>
                    <div class="u-d-flex u-col u-gap-6">
                        <div class="u-fw-700 u-fs-11"><?php echo t('my_phone.vm_forward_title'); ?></div>
                    <label class="u-flex-center u-gap-8 u-pointer u-m-0">
                        <input type="checkbox" name="vm_on_noanswer" value="1" <?php echo $vmNa ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.vm_on_noanswer'); ?></span>
                    </label>
                    <label class="u-flex-center u-gap-8 u-pointer u-m-0">
                        <input type="checkbox" name="vm_on_busy" value="1" <?php echo $vmBusy ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.vm_on_busy'); ?></span>
                    </label>
                    <label class="u-flex-center u-gap-8 u-pointer u-m-0">
                        <input type="checkbox" name="vm_on_unavail" value="1" <?php echo $vmUnavail ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.vm_on_unavail'); ?></span>
                    </label>
                    <label class="u-flex-center u-gap-8 u-pointer u-m-0">
                        <input type="checkbox" name="vm_always" value="1" <?php echo $vmAlways ? 'checked' : ''; ?> class="u-accent">
                        <span><?php echo t('my_phone.vm_always'); ?></span>
                    </label>
                    </div>
                </div>
                <?php if (hasModulePermission('my_phone', 'edit')): ?>
                    <button type="submit" class="btn btn-primary btn-sm u-mt-16 u-r-8 u-fw-700">
                        <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                    </button>
                <?php endif; ?>
            </form>

            <!-- Info card -->
            <div class="u-bg-bg-input u-bd-1-solid-border-color u-r-12 u-p-12-16 u-mb-16 u-d-flex u-ai-center u-jc-space-between u-wrap u-gap-12 u-fs-12">
                <div class="u-d-flex u-ai-center u-gap-10">
                    <i class="fas fa-info-circle text-primary u-fs-18"></i>
                    <span><?php echo t('my_phone.vm_desc'); ?></span>
                </div>
            </div>

            <?php echo uiTableOpen([
                ['label' => '#', 'class' => 'u-w-50'],
                t('my_phone.col_datetime'),
                t('my_phone.col_caller'),
                t('my_phone.col_folder'),
                t('my_phone.col_duration'),
                t('my_phone.col_audio'),
                ['label' => t('my_phone.col_actions'), 'class' => 'text-right'],
            ], ['body_id' => 'voicemailMessagesList']); ?>
                        <?php if (empty($vmMessages)): ?>
                            <?php echo uiTableEmptyRow(7, t('my_phone.vm_empty'), 'fa-inbox'); ?>
                        <?php else: ?>
                            <?php foreach ($vmMessages as $vm): ?>
                                <tr>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($vm['number']); ?></span></td>
                                    <td class="u-fw-600"><?php echo htmlspecialchars($vm['origdate']); ?></td>
                                    <td>
                                        <span class="badge badge-info"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($vm['callerid'] ?: t('mail_templates.unknown_caller')); ?></span>
                                        <?php $vmText = ($vm['summary'] ?? '') !== '' ? $vm['summary'] : ($vm['transcript'] ?? ''); ?>
                                        <?php if ($vmText !== ''): ?>
                                            <div class="u-muted u-fs-12 u-mt-4 u-maxw-420 u-white-space-normal" title="<?php echo htmlspecialchars((string) ($vm['transcript'] ?? '')); ?>"><i class="fas fa-quote-left"></i> <?php echo htmlspecialchars(mb_strimwidth($vmText, 0, 160, '…')); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo ['INBOX' => 'badge-primary', 'Old' => 'badge-teal', 'Archive' => 'badge-purple'][$vm['folder']] ?? 'badge-teal'; ?>">
                                            <?php echo htmlspecialchars($vm['folder_name']); ?>
                                        </span>
                                    </td>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($vm['duration_formatted']); ?></span></td>
                                    <td>
                                        <?php if ($vm['has_audio']): ?>
                                            <audio class="u-h-30 u-maxw-220" controls preload="none">
                                                <source src="/api/voicemail.php?action=play&ext=<?php echo urlencode($ext); ?>&folder=<?php echo urlencode($vm['folder']); ?>&msg=<?php echo urlencode($vm['number']); ?>" type="audio/wav">
                                            </audio>
                                        <?php else: ?>
                                            <span class="text-muted u-fs-11"><?php echo t('my_phone.no_audio'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div class="u-d-inline-flex u-gap-4">
                                            <?php if ($vm['has_audio']): ?>
                                                <a href="/api/voicemail.php?action=play&ext=<?php echo urlencode($ext); ?>&folder=<?php echo urlencode($vm['folder']); ?>&msg=<?php echo urlencode($vm['number']); ?>&download=1" class="btn btn-icon btn-secondary btn-sm" title="<?php echo t('common.download'); ?>">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-icon btn-danger btn-sm" onclick="deleteVoicemailMessage(<?php echo jsArg($vm['id']); ?>)" title="<?php echo t('common.delete'); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php echo uiTableClose(); ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    </div><!-- /tabbed-page: title, tabs and panes in one card (like Time Plan) -->

<!-- Modal: quick mobile sign-in with a QR code -->
<div class="modal-overlay" id="qrLoginModal" style="display: none;">
    <div class="modal-card u-maxw-440 u-ta-center">
        <div class="modal-header">
            <div class="u-d-flex u-ai-center u-gap-10">
                <div class="u-w-36 u-h-36 u-r-12 u-bg-rgba-37-99-235-0-12-2 u-primary u-d-flex u-ai-center u-jc-center u-fs-18">
                    <i class="fas fa-qrcode"></i>
                </div>
                <div>
                    <h3 class="u-fs-16 u-fw-700 u-m-0 u-ta-left"><?php echo t('my_phone.mobile_qr'); ?></h3>
                    <small class="u-muted u-fs-11 u-ta-left" style="display: block;"><?php echo t('my_phone.lbl_extension'); ?>: <?php echo htmlspecialchars($ext); ?> (<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>)</small>
                </div>
            </div>
            <button class="btn btn-icon btn-secondary btn-sm" onclick="closeQrLoginModal()" title="<?php echo t('common.close'); ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body u-p-24-20">
            <p class="u-fs-13 u-muted u-mb-20 u-lh-1-5">
                <?php echo t('my_phone.qr_howto'); ?>
            </p>

            <div class="u-jc-center u-ai-center u-minh-240 u-bg-ffffff u-p-16 u-r-12 u-bd-1-solid-border-color u-box-shadow-0-4-12-rgba-0-0-0-0-06 u-m-0-auto u-maxw-250" id="qrCodeContainer" style="display: flex;">
                <div id="qrLoadingSpinner" class="u-muted u-text-center">
                    <i class="fas fa-spinner fa-spin u-fs-28 u-primary u-mb-8" style="display: block;"></i>
                    <?php echo t('my_phone.qr_generating'); ?>
                </div>
                <div class="u-w-100" id="qrSvgWrapper" style="display: none;"></div>
            </div>

            <div class="u-mt-16 u-bg-rgba-16-185-129-0-15 u-bd-1-solid-rgba-16-185-129-0-4 u-r-8 u-p-12 u-c-059669 u-fw-600 u-fs-13" id="qrSuccessAlert" style="display: none;">
                <i class="fas fa-check-circle u-mr-6"></i> <?php echo t('my_phone.qr_paired'); ?>: <span id="qrPairedDevice"></span>
            </div>

            <div id="qrCountdownContainer" class="u-mt-16 u-fs-12 u-muted u-d-flex u-ai-center u-jc-center u-gap-8">
                <i class="fas fa-clock"></i> <?php echo t('my_phone.qr_remaining'); ?>: <strong id="qrCountdown" class="u-primary">10:00</strong>
                <button type="button" class="btn btn-ghost btn-xs" onclick="generateNewQrCode()" title="<?php echo t('my_phone.qr_new'); ?>">
                    <i class="fas fa-sync-alt"></i> <?php echo t('common.refresh'); ?>
                </button>
            </div>

            <div class="u-mt-20 u-fs-12 u-muted u-bg-bg-input u-p-12-16 u-r-8 u-ta-left u-lh-1-5 u-bd-1-solid-border-color">
                <i class="fas fa-shield-alt u-primary u-mr-4"></i>
                <?php echo t('my_phone.qr_secure'); ?>
            </div>
        </div>
    </div>
</div>

<!-- WaveSurfer audio player modal (component shared with the CDR reports) -->
<?php require dirname(__DIR__, 2) . '/cdr_audio_player.php'; ?>

<script src="<?php echo asset('/assets/js/my_phone.js'); ?>"></script>
