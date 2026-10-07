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
    <div class="card mb-3 my-phone-header-card">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div style="width: 40px; height: 40px; border-radius: 10px; background: rgba(0, 242, 254, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                <i class="fas fa-phone-alt"></i>
            </div>
            <div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <h2 class="u-fs-16 u-fw-700 u-m-0 u-text-main">
                        <?php echo htmlspecialchars($details['full_name'] ?? ''); ?>
                    </h2>
                    <?php if ($ext !== ''): ?>
                        <span class="badge" style="font-size: 12px; padding: 2px 8px; background: var(--primary); color: #fff; font-weight: 700; border-radius: 12px;">
                            #<?php echo htmlspecialchars($ext); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                    <i class="fas fa-user-tag"></i> <?php echo htmlspecialchars($details['role_name'] ?? ($details['role'] ?? '')); ?>
                </div>
            </div>
        </div>

        <div class="my-phone-badges-wrapper">
            <!-- WebRTC Durum Rozeti -->
            <?php $isWebrtcOnline = ($webrtcStatus && stripos($webrtcStatus, 'not in use') !== false); ?>
            <span id="my-phone-webrtc-pill" class="badge" style="background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-muted); padding: 5px 10px; font-size: 11.5px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-globe"></i> WebRTC: <strong id="my-phone-webrtc-text" style="color: <?php echo $isWebrtcOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;"><?php echo $isWebrtcOnline ? t('my_phone.webrtc_status_online') : t('my_phone.webrtc_status_offline'); ?></strong>
            </span>
            <!-- SIP Durum Rozeti -->
            <?php $isSipOnline = ($sipStatus && stripos($sipStatus, 'not in use') !== false); ?>
            <span class="badge" style="background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-muted); padding: 5px 10px; font-size: 11.5px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-phone-square-alt"></i> SIP: 
                <strong style="color: <?php echo $isSipOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                    <?php echo $isSipOnline ? t('my_phone.sip_status_online') : t('my_phone.sip_status_offline'); ?>
                </strong>
            </span>
            <!-- Mobil Durum Rozeti -->
            <?php $isMobileOnline = (!empty($mobileStatus) && stripos($mobileStatus, 'not in use') !== false); ?>
            <span class="badge" style="background: var(--bg-input); border: 1px solid var(--border-color); color: var(--text-muted); padding: 5px 10px; font-size: 11.5px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                <i class="fas fa-mobile-alt"></i> <?php echo t('my_phone.lbl_mobile'); ?>: 
                <strong style="color: <?php echo $isMobileOnline ? 'var(--success)' : 'var(--text-muted)'; ?>;">
                    <?php echo $isMobileOnline ? t('my_phone.mobile_status_online') : t('my_phone.mobile_status_offline'); ?>
                </strong>
            </span>
            <!-- DND Rozeti -->
            <?php if ($isDnd): ?>
                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.3); padding: 5px 10px; font-size: 11.5px; border-radius: 8px;">
                    <i class="fas fa-minus-circle"></i> <?php echo t('my_phone.dnd_on'); ?>
                </span>
            <?php endif; ?>
            <!-- Call forwarding badge -->
            <?php if ($cfNum !== ''): ?>
                <span class="badge" style="background: rgba(245, 158, 11, 0.15); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.3); padding: 5px 10px; font-size: 11.5px; border-radius: 8px;">
                    <i class="fas fa-share"></i> <?php echo htmlspecialchars($cfNum); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($ext === ''): ?>
        <div class="card mb-4" style="text-align: center; padding: 48px 24px;">
            <i class="fas fa-phone-slash" style="font-size: 48px; color: var(--text-muted); opacity: 0.5; margin-bottom: 16px;"></i>
            <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
                <?php echo t('my_phone.no_extension'); ?>
            </h3>
            <p style="color: var(--text-muted); font-size: 13px; max-width: 480px; margin: 0 auto;">
                <?php echo t('my_phone.no_extension_help'); ?>
            </p>
        </div>
    <?php else: ?>
        <!-- 3. Tab navigation buttons -->
        <div style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
            <button type="button" class="btn btn-sm <?php echo $currentTab === 'history' ? 'btn-primary' : 'btn-secondary'; ?>" id="btn-tab-history" onclick="switchMyPhoneTab('history')" style="border-radius: 8px; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                <i class="fas fa-history"></i> <?php echo t('my_phone.tab_history'); ?>
                <span class="badge" style="background: rgba(0,0,0,0.15); font-size: 11px; padding: 2px 7px; border-radius: 10px;"><?php echo count($calls); ?></span>
            </button>
            <button type="button" class="btn btn-sm <?php echo $currentTab === 'calls' ? 'btn-primary' : 'btn-secondary'; ?>" id="btn-tab-calls" onclick="switchMyPhoneTab('calls')" style="border-radius: 8px; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                <i class="fas fa-phone-alt"></i> <?php echo t('my_phone.tab_calls'); ?>
                <?php if ($isDnd || $hasActiveCf): ?>
                    <span class="badge badge-warning" style="font-size: 10px; padding: 2px 6px; border-radius: 10px;"><i class="fas fa-check"></i> <?php echo t('common.active'); ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="btn btn-sm <?php echo $currentTab === 'settings' ? 'btn-primary' : 'btn-secondary'; ?>" id="btn-tab-settings" onclick="switchMyPhoneTab('settings')" style="border-radius: 8px; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                <i class="fas fa-sliders-h"></i> <?php echo t('my_phone.tab_settings'); ?>
            </button>
            <?php if (!$isFaxUser): ?>
            <button type="button" class="btn btn-sm <?php echo $currentTab === 'voicemail' ? 'btn-primary' : 'btn-secondary'; ?>" id="btn-tab-voicemail" onclick="switchMyPhoneTab('voicemail')" style="border-radius: 8px; font-weight: 700; padding: 8px 16px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px;">
                <i class="fas fa-voicemail"></i> <?php echo t('my_phone.tab_voicemail', 'Sesli Posta'); ?>
                <span class="badge <?php echo !empty($vmMessages) ? 'badge-danger' : 'badge-secondary'; ?>" style="font-size: 11px; padding: 2px 7px; border-radius: 10px;"><?php echo count($vmMessages); ?></span>
            </button>
            <?php endif; ?>
        </div>

        <!-- TAB 1: MY CALL HISTORY (wide & modern data table) -->
        <div id="tab-pane-history" class="card" style="display: <?php echo $currentTab === 'history' ? 'block' : 'none'; ?>; padding: 24px; border-radius: 14px;">
            <div class="card-header" style="padding: 0 0 16px 14px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;">
                <div class="card-title u-flex-center u-title">
                    <i class="fas fa-phone-volume u-primary"></i> <?php echo t('my_phone.recent_calls'); ?>
                </div>
                <div class="u-flex-center">
                    <a href="/my-phone?tab=history" class="btn btn-secondary btn-sm" title="<?php echo t('common.refresh'); ?>"><i class="fas fa-sync-alt"></i></a>
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
                    <button type="button" onclick="setMyPhoneFilter('all')" class="btn btn-xs <?php echo $filter === 'all' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px;">
                        <?php echo t('my_phone.filter_all'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('in')" class="btn btn-xs <?php echo $filter === 'in' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-arrow-down u-success"></i> <?php echo t('my_phone.filter_in'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('out')" class="btn btn-xs <?php echo $filter === 'out' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-arrow-up u-primary"></i> <?php echo t('my_phone.filter_out'); ?>
                    </button>
                    <button type="button" onclick="setMyPhoneFilter('missed')" class="btn btn-xs <?php echo $filter === 'missed' ? 'btn-primary' : 'btn-ghost'; ?>" style="border-radius: 6px; padding: 5px 12px; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
                        <i class="fas fa-phone-slash u-danger"></i> <?php echo t('my_phone.filter_missed'); ?>
                    </button>
                </div>

                <!-- Arama Kutusu -->
                <div class="my-phone-search-wrapper" style="position: relative; flex: 1; min-width: 200px; max-width: 380px;">
                    <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 12px; pointer-events: none;"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="form-control form-control-sm" placeholder="<?php echo t('my_phone.search_placeholder'); ?>" style="padding-left: 32px; border-radius: 8px;">
                </div>

                <button type="submit" class="btn btn-primary btn-sm" title="<?php echo t('common.filter'); ?>" style="border-radius: 8px; padding: 0 14px; height: 34px;">
                    <i class="fas fa-filter"></i>
                </button>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a href="/my-phone?tab=history" class="btn btn-secondary btn-sm" title="<?php echo t('common.reset'); ?>" style="border-radius: 8px; padding: 0 12px; height: 34px; display: inline-flex; align-items: center;">
                        <i class="fas fa-undo"></i>
                    </a>
                <?php endif; ?>
            </form>

            <!-- Rich data table in the CDR style -->
            <div class="table-responsive">
                <table class="data-table" data-no-dt="true">
                    <thead>
                        <tr>
                            <th class="col-hide-mobile u-w-45">#</th>
                            <th><?php echo t('my_phone.col_date'); ?></th>
                            <th><?php echo t('my_phone.col_direction'); ?></th>
                            <th><?php echo t('my_phone.col_party'); ?></th>
                            <th class="col-hide-mobile"><?php echo t('my_phone.col_device'); ?></th>
                            <th><?php echo t('my_phone.col_duration'); ?></th>
                            <th><?php echo t('my_phone.col_status'); ?></th>
                            <th class="text-right"><?php echo t('my_phone.col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($calls)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 48px 24px;">
                                    <i class="fas fa-history" style="font-size: 40px; opacity: 0.35; margin-bottom: 12px; display: block;"></i>
                                    <div class="u-fw-600 u-fs-14 u-text-main u-mb-4"><?php echo t('my_phone.no_calls'); ?></div>
                                    <small class="u-fs-12"><?php echo t('my_phone.no_calls_desc'); ?></small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $sureBicim = fn(int $sn) => sprintf('%02d:%02d', intdiv($sn, 60), $sn % 60);
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
                                    
                                    <!-- Tarih & Saat -->
                                    <td class="u-fw-600 u-nowrap">
                                        <?php echo date('d.m.Y H:i:s', strtotime($c['calldate'])); ?>
                                    </td>

                                    <!-- Direction badge -->
                                    <td>
                                        <?php if ($dir === 'in'): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-arrow-down"></i> <?php echo t('my_phone.filter_in'); ?>
                                            </span>
                                        <?php elseif ($dir === 'out'): ?>
                                            <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #2563eb; border: 1px solid rgba(59, 130, 246, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-arrow-up"></i> <?php echo t('my_phone.filter_out'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-phone-slash"></i> <?php echo t('my_phone.filter_missed'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Other party -->
                                    <td>
                                        <div style="font-weight: 700; color: var(--primary); font-size: 13.5px; display: flex; align-items: center; gap: 6px;">
                                            <i class="fas fa-phone-alt" style="font-size: 11px; opacity: 0.7;"></i>
                                            <span><?php echo $party; ?></span>
                                        </div>
                                        <?php if (!empty($partyName) && $partyName !== $party): ?>
                                            <div class="u-fs-11 u-muted u-mt-2">
                                                <i class="fas fa-user" style="font-size: 10px; margin-right: 3px;"></i><?php echo $partyName; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Cihaz -->
                                    <td class="col-hide-mobile">
                                        <?php if ($dev === 'mobil'): ?>
                                            <span class="badge" style="background: rgba(13, 202, 240, 0.15); color: #087990; border: 1px solid rgba(13, 202, 240, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-mobile-alt"></i> <?php echo t('my_phone.lbl_mobile'); ?>
                                            </span>
                                        <?php elseif ($dev === 'webrtc'): ?>
                                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-desktop"></i> WebRTC
                                            </span>
                                        <?php elseif ($dev === 'sip'): ?>
                                            <span class="badge" style="background: rgba(100, 116, 139, 0.15); color: #475569; border: 1px solid rgba(100, 116, 139, 0.35); font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-phone-alt"></i> SIP
                                            </span>
                                        <?php else: ?>
                                            <span class="u-muted u-fs-12">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Duration -->
                                    <td>
                                        <?php if ($disp === 'ANSWERED'): ?>
                                            <div style="font-family: monospace; font-size: 13px; font-weight: 700; color: var(--success);">
                                                <i class="fas fa-phone-volume u-fs-11 u-mr-4"></i><?php echo $sureBicim($bill); ?>
                                            </div>
                                            <div class="u-fs-11 u-muted u-mt-2 u-nowrap">
                                                <?php echo t('my_phone.ring'); ?>: <?php echo $sureBicim($ring); ?> • <?php echo t('my_phone.total'); ?>: <?php echo $sureBicim($dur); ?>
                                            </div>
                                        <?php elseif (in_array($disp, ['NO ANSWER', 'NOANSWER', 'CANCEL', 'BUSY'])): ?>
                                            <div style="font-family: monospace; font-size: 12px; color: var(--warning); font-weight: 600;">
                                                <i class="fas fa-bell" style="font-size: 10px; margin-right: 3px;"></i><?php echo t('my_phone.ring'); ?>: <?php echo $sureBicim($ring > 0 ? $ring : $dur); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: var(--text-muted); font-size: 12px; font-family: monospace;">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Durum -->
                                    <td>
                                        <span class="badge <?php echo $badgeClass; ?> u-fs-11">
                                            <?php echo $dispLabel; ?>
                                        </span>
                                    </td>

                                    <!-- Aksiyonlar -->
                                    <td class="text-right u-nowrap">
                                        <div style="display: inline-flex; align-items: center; gap: 6px;">
                                            <?php if ($hasRec): ?>
                                                <button type="button" class="btn btn-secondary btn-sm" onclick="playCdrAudio(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars(addslashes($party), ENT_QUOTES); ?>', '<?php echo date('d.m.Y H:i', strtotime($c['calldate'])); ?>')" title="<?php echo t('my_phone.listen_recording'); ?>" style="padding: 4px 8px;">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                                <a href="/api/cc_audio.php?id=<?php echo $c['id']; ?>&download=1" class="btn btn-secondary btn-sm" title="<?php echo t('my_phone.download_recording'); ?>" style="padding: 4px 8px;">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" onclick="callTargetNumber('<?php echo $party; ?>')" title="<?php echo htmlspecialchars(sprintf(t('my_phone.call_number'), $party)); ?>" style="border-radius: 6px; padding: 4px 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class="fas fa-phone"></i> <?php echo t('my_phone.btn_call'); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB: Call settings (general, forwarding, voicemail) -->
        <div id="tab-pane-calls" class="my-phone-settings-grid" style="display: <?php echo $currentTab === 'calls' ? 'grid' : 'none'; ?>;">
            <!-- Settings cards share one form (display: contents) so any Save stores every setting. -->
            <form method="POST" action="/my-phone" class="my-phone-settings-form">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="csrf_token" value="<?php echo getCSRFToken(); ?>">

            <!-- Card: do not disturb -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 14px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-minus-circle u-danger"></i> <?php echo t('my_phone.dnd_label'); ?>
                </h3>
                <label style="display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; margin: 0 0 18px;">
                    <small style="color: var(--text-muted); font-size: 12px; line-height: 1.4;"><?php echo t('my_phone.dnd_desc'); ?></small>
                    <input type="checkbox" name="dnd_enabled" value="1" <?php echo $isDnd ? 'checked' : ''; ?> style="width: 20px; height: 20px; cursor: pointer; flex-shrink: 0;">
                </label>
                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>

            <!-- Card: phone modes -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 14px; color: var(--text-main); display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                    <span style="display: flex; align-items: center; gap: 8px;"><i class="fas fa-phone-volume u-primary"></i> <?php echo t('my_phone.active_phone_modes'); ?></span>
                    <span class="badge badge-info u-fw-600 u-fs-10"><?php echo count($activeModes); ?> / 4 <?php echo t('my_phone.modes_active'); ?></span>
                </h3>
                        <small style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 12px; line-height: 1.4;">
                            <?php echo t('my_phone.phone_modes_desc'); ?>
                        </small>

                        <div class="my-phone-modes-grid">
                            <!-- Web (browser) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="web" <?php echo in_array('web', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--primary);">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-laptop u-primary u-fs-12"></i> <?php echo t('my_phone.mode_web'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_web_sub'); ?></small>
                                </div>
                            </label>

                            <!-- Mobil (Uygulama) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="mobil" <?php echo in_array('mobil', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--success);">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-mobile-alt u-success u-fs-12"></i> <?php echo t('my_phone.mode_mobil'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_mobil_sub'); ?></small>
                                </div>
                            </label>

                            <!-- SIP (desk phone) -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="sip" <?php echo in_array('sip', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: var(--secondary);">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-phone-alt" style="color: var(--secondary); font-size: 12px;"></i> <?php echo t('my_phone.mode_sip_desk'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_sip_sub'); ?></small>
                                </div>
                            </label>

                            <!-- Video -->
                            <label style="display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; margin: 0; user-select: none;">
                                <input type="checkbox" name="phone_modes[]" value="video" <?php echo in_array('video', $activeModes, true) ? 'checked' : ''; ?> style="width: 17px; height: 17px; cursor: pointer; accent-color: #8b5cf6;">
                                <div style="display: flex; flex-direction: column;">
                                    <span style="font-weight: 600; font-size: 12.5px; color: var(--text-main); display: flex; align-items: center; gap: 5px;">
                                        <i class="fas fa-video" style="color: #8b5cf6; font-size: 12px;"></i> <?php echo t('my_phone.mode_video'); ?>
                                    </span>
                                    <small class="u-muted u-fs-10"><?php echo t('my_phone.mode_video_sub'); ?></small>
                                </div>
                            </label>
                        </div>
                <div style="margin-top: 18px;"></div>
                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>

            <!-- Card: forwarding -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                    <!-- Call forwarding options (always, when busy, on no answer) -->
                    <div class="u-mb-20">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                            <div style="font-weight: 700; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-share u-warning"></i> <?php echo t('my_phone.cf_card_title'); ?>
                            </div>
                            <?php
                                $hasActiveCf = !empty($cfNum) || !empty($cfBusyNum) || !empty($cfNoAnsNum);
                            ?>
                            <span class="badge <?php echo $hasActiveCf ? 'badge-warning' : 'badge-secondary'; ?>" style="font-size: 10.5px; font-weight: 600;">
                                <i class="fas <?php echo $hasActiveCf ? 'fa-check-circle' : 'fa-ban'; ?>"></i>
                                <?php echo $hasActiveCf ? t('my_phone.cf_status_active') : t('my_phone.cf_status_disabled'); ?>
                            </span>
                        </div>

                        <!-- 1. Always forward (unconditional) -->
                        <div class="form-group u-mb-14">
                            <label class="form-label" style="font-size: 11.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-forward u-warning u-mr-4"></i> <?php echo t('my_phone.cf_always_label'); ?></span>
                                <?php if (!empty($cfNum)): ?>
                                    <span class="badge badge-warning" style="font-size: 9.5px; padding: 2px 6px;"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="call_forward_number" value="<?php echo htmlspecialchars($cfNum); ?>" class="form-control form-control-sm" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>" style="font-size: 12.5px;">
                            <small style="color: var(--text-muted); font-size: 10.5px; display: block; margin-top: 2px;">
                                <?php echo t('my_phone.cf_always_help'); ?>
                            </small>
                        </div>

                        <!-- 2. Forward when busy -->
                        <div class="form-group u-mb-14">
                            <label class="form-label" style="font-size: 11.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-phone-slash u-danger u-mr-4"></i> <?php echo t('my_phone.cf_busy_label'); ?></span>
                                <?php if (!empty($cfBusyNum)): ?>
                                    <span class="badge badge-info" style="font-size: 9.5px; padding: 2px 6px;"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <input type="text" name="cf_busy_number" value="<?php echo htmlspecialchars($cfBusyNum); ?>" class="form-control form-control-sm" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>" style="font-size: 12.5px;">
                            <small style="color: var(--text-muted); font-size: 10.5px; display: block; margin-top: 2px;">
                                <?php echo t('my_phone.cf_busy_help'); ?>
                            </small>
                        </div>

                        <!-- 3. Forward on no answer -->
                        <div class="form-group u-mb-0">
                            <label class="form-label" style="font-size: 11.5px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span><i class="fas fa-phone-volume" style="color: var(--info); margin-right: 4px;"></i> <?php echo t('my_phone.cf_noanswer_label'); ?></span>
                                <?php if (!empty($cfNoAnsNum)): ?>
                                    <span class="badge badge-info" style="font-size: 9.5px; padding: 2px 6px;"><i class="fas fa-check"></i> <?php echo t('my_phone.active'); ?></span>
                                <?php endif; ?>
                            </label>
                            <div class="u-flex-gap">
                                <input type="text" name="cf_noanswer_number" value="<?php echo htmlspecialchars($cfNoAnsNum); ?>" class="form-control form-control-sm" placeholder="<?php echo t('my_phone.cf_placeholder_internal_external'); ?>" style="font-size: 12.5px; flex: 1;">
                                <select name="cf_noanswer_timeout" class="form-control form-control-sm" style="width: 90px; font-size: 12px;" title="<?php echo t('my_phone.cf_timeout_title'); ?>">
                                    <option value="10" <?php echo $cfTimeout === 10 ? 'selected' : ''; ?>>10 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="15" <?php echo $cfTimeout === 15 ? 'selected' : ''; ?>>15 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="20" <?php echo $cfTimeout === 20 ? 'selected' : ''; ?>>20 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="25" <?php echo $cfTimeout === 25 ? 'selected' : ''; ?>>25 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="30" <?php echo $cfTimeout === 30 ? 'selected' : ''; ?>>30 <?php echo t('my_phone.sec'); ?></option>
                                    <option value="45" <?php echo $cfTimeout === 45 ? 'selected' : ''; ?>>45 <?php echo t('my_phone.sec'); ?></option>
                                </select>
                            </div>
                            <small style="color: var(--text-muted); font-size: 10.5px; display: block; margin-top: 2px;">
                                <?php echo t('my_phone.cf_noanswer_help'); ?>
                            </small>
                        </div>
                    </div>

                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>

            <?php if (!$isFaxUser): ?>
            <!-- Kart: Sesli Posta -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                    <!-- Sesli Posta (Voicemail) Tercihleri -->
                    <div class="u-mb-20">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="font-weight: 700; font-size: 15px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-voicemail u-primary"></i> <?php echo t('my_phone.vm_settings_title'); ?>
                            </div>
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; margin: 0; font-size: 12px;">
                                <input type="checkbox" name="voicemail_enabled" value="1" <?php echo $vmEnabled ? 'checked' : ''; ?> class="u-accent">
                                <span><?php echo t('my_phone.enabled'); ?></span>
                            </label>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                            <div class="form-group u-mb-0">
                                <label class="form-label u-fs-11 u-mb-4"><?php echo t('my_phone.vm_pin'); ?></label>
                                <input type="password" name="voicemail_pin" value="<?php echo htmlspecialchars($vmPin); ?>" class="form-control form-control-sm u-fs-12" placeholder="<?php echo t('my_phone.vm_pin_placeholder'); ?>">
                            </div>
                            <div class="form-group u-mb-0">
                                <label class="form-label u-fs-11 u-mb-4"><?php echo t('my_phone.vm_email'); ?></label>
                                <input type="email" name="voicemail_email" value="<?php echo htmlspecialchars($vmEmail); ?>" class="form-control form-control-sm u-fs-12" placeholder="<?php echo t('my_phone.email_placeholder'); ?>">
                            </div>
                        </div>

                        <div style="font-size: 11.5px; font-weight: 600; color: var(--text-main); margin-bottom: 6px;"><?php echo t('my_phone.vm_forward_title'); ?></div>
                        <div style="display: flex; flex-direction: column; gap: 6px; font-size: 12px;">
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="vm_on_noanswer" value="1" <?php echo $vmNa ? 'checked' : ''; ?> class="u-accent">
                                <span><?php echo t('my_phone.vm_on_noanswer'); ?></span>
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="vm_on_busy" value="1" <?php echo $vmBusy ? 'checked' : ''; ?> class="u-accent">
                                <span><?php echo t('my_phone.vm_on_busy'); ?></span>
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="vm_on_unavail" value="1" <?php echo $vmUnavail ? 'checked' : ''; ?> class="u-accent">
                                <span><?php echo t('my_phone.vm_on_unavail'); ?></span>
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                                <input type="checkbox" name="vm_always" value="1" <?php echo $vmAlways ? 'checked' : ''; ?> class="u-accent">
                                <span><?php echo t('my_phone.vm_always'); ?></span>
                            </label>
                        </div>
                    </div>
                    <?php if (hasModulePermission('my_phone', 'edit')): ?>
                        <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;">
                            <i class="fas fa-check"></i> <?php echo t('my_phone.btn_save_settings'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" style="width: 100%; border-radius: 8px; font-weight: 700; font-size: 13px; height: 38px;" disabled title="<?php echo t('roles.read_only_badge'); ?>">
                            <i class="fas fa-lock"></i> <?php echo t('roles.read_only_badge'); ?>
                        </button>
                    <?php endif; ?>
            </div>
            <?php endif; ?>
            </form>

        </div>

        <!-- TAB 2: PHONE & DEVICE SETTINGS (wide 3-column grid) -->
        <div id="tab-pane-settings" class="my-phone-settings-grid" style="display: <?php echo $currentTab === 'settings' ? 'grid' : 'none'; ?>;">
            
            <!-- Card 2: WebRTC device & ring tone settings -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-headphones u-primary"></i> <?php echo t('my_phone.device_settings'); ?>
                </h3>

                <!-- Mikrofon -->
                <div class="form-group u-mb-16">
                    <label class="form-label u-fw-600 u-fs-12">
                        <i class="fas fa-microphone"></i> <?php echo t('phone_settings.field_mic'); ?>
                    </label>
                    <select id="my_phone_mic_select" class="form-control" onchange="savePhoneMicDevice(this.value)" style="font-size: 12.5px;">
                        <option value="default"><?php echo t('my_phone.system_default'); ?></option>
                    </select>
                </div>

                <!-- Speaker -->
                <div class="form-group u-mb-16">
                    <label class="form-label u-fw-600 u-fs-12">
                        <i class="fas fa-volume-up"></i> <?php echo t('phone_settings.field_speaker'); ?>
                    </label>
                    <div class="u-flex-gap">
                        <select id="my_phone_speaker_select" class="form-control" onchange="savePhoneSpeakerDevice(this.value)" style="flex: 1; font-size: 12.5px;">
                            <option value="default"><?php echo t('my_phone.system_default'); ?></option>
                        </select>
                        <button type="button" class="btn btn-secondary" onclick="testPhoneSpeaker()" title="<?php echo t('phone_settings.test_sound_tooltip'); ?>" style="padding: 0 14px;">
                            <i class="fas fa-play"></i>
                        </button>
                    </div>
                </div>

                <!-- Zil Sesi Seviyesi -->
                <div class="form-group u-mb-0">
                    <label class="form-label" style="font-size: 12px; font-weight: 600; display: flex; justify-content: space-between;">
                        <span><i class="fas fa-bell"></i> <?php echo t('phone_settings.field_ring_volume'); ?></span>
                        <span id="my-phone-vol-label" style="color: var(--primary); font-weight: 700;">100%</span>
                    </label>
                    <input type="range" id="my_phone_ring_slider" min="0" max="100" value="100" style="width: 100%; cursor: pointer;" oninput="updateVolSlider(this.value)">
                </div>
            </div>

            <!-- Card 3: desk IP phone registration details (credentials) -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-key u-primary"></i> <?php echo t('my_phone.sip_credentials_title'); ?>
                </h3>
                <div style="font-size: 12.5px; background: var(--bg-input); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span class="u-muted"><?php echo t('my_phone.sip_server'); ?>:</span>
                        <strong style="font-family: monospace;"><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? '127.0.0.1'); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span class="u-muted"><?php echo t('my_phone.sip_port'); ?>:</span>
                        <strong style="font-family: monospace;">5060 (UDP/TCP)</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span class="u-muted"><?php echo t('my_phone.sip_username'); ?>:</span>
                        <strong style="font-family: monospace; color: var(--primary);"><?php echo htmlspecialchars($ext); ?></strong>
                    </div>
                    <div class="u-flex-between">
                        <span class="u-muted"><?php echo t('my_phone.sip_password'); ?>:</span>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="password" id="my_phone_sip_pass_val" value="<?php echo htmlspecialchars($details['sip_password'] ?? ''); ?>" readonly style="background: transparent; border: none; font-family: monospace; width: 110px; text-align: right; color: var(--text-main);" />
                            <button type="button" class="btn btn-xs btn-ghost" onclick="toggleSipPassVisibility()" style="padding: 2px 6px;" title="<?php echo t('my_phone.show_hide'); ?>">
                                <i class="fas fa-eye" id="my_phone_pass_eye"></i>
                            </button>
                            <button type="button" class="btn btn-xs btn-ghost" onclick="copySipPassword()" style="padding: 2px 6px;" title="<?php echo t('common.copy'); ?>">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kart 4: Mobil Uygulama & Cihaz Bilgileri -->
            <div class="card" style="padding: 24px; border-radius: 14px;">
                <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 18px; color: var(--text-main); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <span class="u-flex-center">
                        <i class="fab fa-android" style="color: #3DDC84;"></i> <?php echo t('my_phone.mobile_app_info'); ?>
                    </span>
                    <div class="u-flex-center">
                        <button type="button" class="btn btn-xs btn-success" onclick="openQrLoginModal()" style="font-size: 11px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="fas fa-qrcode"></i> <?php echo t('my_phone.mobile_qr'); ?>
                        </button>
                        <a href="<?php echo htmlspecialchars(ANDROID_PLAY_URL); ?>" target="_blank" rel="noopener" class="btn btn-xs btn-primary" style="font-size: 11px; padding: 4px 10px;">
                            <i class="fab fa-google-play"></i> <?php echo t('common.get_on_google_play'); ?>
                        </a>
                    </div>
                </h3>

                <!-- QR code quick mobile sign-in intro box -->
                <div style="background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(16,185,129,0.06)); border: 1px dashed rgba(37,99,235,0.28); border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 38px; height: 38px; border-radius: 8px; background: rgba(37,99,235,0.12); display: flex; align-items: center; justify-content: center; color: var(--primary); font-size: 18px;">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <div>
                            <div class="u-fw-700 u-fs-13 u-text-main"><?php echo t('my_phone.quick_mobile_login'); ?></div>
                            <div style="font-size: 11.5px; color: var(--text-muted);"><?php echo t('my_phone.quick_mobile_login_desc'); ?></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-primary" onclick="openQrLoginModal()" style="font-size: 11.5px; padding: 6px 14px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="fas fa-qrcode"></i> <?php echo t('my_phone.show_qr'); ?>
                    </button>
                </div>

                <?php if (empty($mobileDevices)): ?>
                    <div style="font-size: 12.5px; background: var(--bg-input); padding: 16px; border-radius: 10px; border: 1px solid var(--border-color); color: var(--text-muted); text-align: center;">
                        <i class="fas fa-mobile-alt" style="font-size: 24px; margin-bottom: 8px; opacity: 0.5; display: block;"></i>
                        <?php echo t('my_phone.no_device'); ?><br>
                        <?php echo t('my_phone.no_device_desc'); ?>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($mobileDevices as $md): ?>
                            <div style="font-size: 12.5px; background: var(--bg-input); padding: 14px 16px; border-radius: 10px; border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 8px;">
                                <div class="u-flex-between">
                                    <strong class="u-fs-13 u-text-main">
                                        <i class="fas fa-mobile-alt u-mr-6 u-primary"></i>
                                        <?php echo htmlspecialchars($md['device_name'] ?: 'Android Cihaz'); ?>
                                    </strong>
                                    <span class="badge badge-success" style="font-size: 10px; padding: 3px 8px;">
                                        v<?php echo htmlspecialchars($md['app_version'] ?: '1.0'); ?>
                                    </span>
                                </div>
                                <div style="display: flex; justify-content: space-between; color: var(--text-muted); font-size: 11.5px;">
                                    <span><?php echo t('my_phone.platform'); ?>:</span>
                                    <span><?php echo htmlspecialchars(ucfirst($md['platform'])); ?></span>
                                </div>
                                <div style="display: flex; justify-content: space-between; color: var(--text-muted); font-size: 11.5px;">
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
        <div id="tab-pane-voicemail" class="card" style="display: <?php echo $currentTab === 'voicemail' ? 'block' : 'none'; ?>; padding: 24px; border-radius: 14px;">
            <div class="card-header" style="padding: 0 0 16px 14px; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px;">
                <div class="card-title u-flex-center u-title">
                    <i class="fas fa-voicemail u-primary"></i> <?php echo t('my_phone.voicemail_inbox', 'Sesli Posta Kutum'); ?>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: var(--text-muted);">
                    <span><i class="fas fa-phone-alt text-info"></i> <?php echo t('my_phone.vm_internal'); ?>: <strong class="badge badge-info u-fs-11">*97</strong></span>
                    <span><i class="fas fa-hashtag text-warning"></i> <?php echo t('my_phone.vm_remote'); ?>: <strong class="badge badge-secondary u-fs-11">*98</strong></span>
                    <a href="/my-phone?tab=voicemail" class="btn btn-secondary btn-sm" title="<?php echo t('common.refresh'); ?>"><i class="fas fa-sync-alt"></i></a>
                </div>
            </div>

            <!-- Info card -->
            <div style="background: var(--bg-input); border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; font-size: 12px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-info-circle text-primary" style="font-size: 18px;"></i>
                    <span><?php echo t('my_phone.vm_desc'); ?></span>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="switchMyPhoneTab('calls')" style="font-size: 11.5px;">
                    <i class="fas fa-cog"></i> <?php echo t('my_phone.vm_settings'); ?>
                </button>
            </div>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="u-w-50">#</th>
                            <th><?php echo t('my_phone.col_datetime'); ?></th>
                            <th><?php echo t('my_phone.col_caller'); ?></th>
                            <th><?php echo t('my_phone.col_folder'); ?></th>
                            <th><?php echo t('my_phone.col_duration'); ?></th>
                            <th><?php echo t('my_phone.col_audio'); ?></th>
                            <th class="text-right"><?php echo t('my_phone.col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="voicemailMessagesList">
                        <?php if (empty($vmMessages)): ?>
                            <?php echo uiTableEmptyRow(7, t('my_phone.vm_empty'), 'fa-inbox'); ?>
                        <?php else: ?>
                            <?php foreach ($vmMessages as $vm): ?>
                                <tr>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($vm['number']); ?></span></td>
                                    <td class="u-fw-600"><?php echo htmlspecialchars($vm['origdate']); ?></td>
                                    <td>
                                        <span class="badge badge-info"><i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($vm['callerid'] ?: 'Bilinmeyen'); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $vm['folder'] === 'INBOX' ? 'badge-primary' : 'badge-secondary'; ?>">
                                            <?php echo htmlspecialchars($vm['folder_name']); ?>
                                        </span>
                                    </td>
                                    <td><span class="badge badge-secondary"><?php echo htmlspecialchars($vm['duration_formatted']); ?></span></td>
                                    <td>
                                        <?php if ($vm['has_audio']): ?>
                                            <audio controls preload="none" style="height: 30px; max-width: 220px;">
                                                <source src="/api/voicemail.php?action=play&ext=<?php echo urlencode($ext); ?>&folder=<?php echo urlencode($vm['folder']); ?>&msg=<?php echo urlencode($vm['number']); ?>" type="audio/wav">
                                            </audio>
                                        <?php else: ?>
                                            <span class="text-muted u-fs-11"><?php echo t('my_phone.no_audio'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <div style="display: inline-flex; gap: 4px;">
                                            <?php if ($vm['has_audio']): ?>
                                                <a href="/api/voicemail.php?action=play&ext=<?php echo urlencode($ext); ?>&folder=<?php echo urlencode($vm['folder']); ?>&msg=<?php echo urlencode($vm['number']); ?>&download=1" class="btn btn-secondary btn-sm" title="<?php echo t('common.download'); ?>">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="deleteVoicemailMessage('<?php echo htmlspecialchars($vm['id']); ?>')" title="<?php echo t('common.delete'); ?>">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal: quick mobile sign-in with a QR code -->
<div class="modal-overlay" id="qrLoginModal" style="display: none;">
    <div class="modal-card" style="max-width: 440px; text-align: center;">
        <div class="modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(37, 99, 235, 0.12); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 18px;">
                    <i class="fas fa-qrcode"></i>
                </div>
                <div>
                    <h3 style="font-size: 16px; font-weight: 700; margin: 0; text-align: left;"><?php echo t('my_phone.mobile_qr'); ?></h3>
                    <small style="color: var(--text-muted); font-size: 11px; display: block; text-align: left;"><?php echo t('my_phone.lbl_extension'); ?>: <?php echo htmlspecialchars($ext); ?> (<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>)</small>
                </div>
            </div>
            <button class="btn btn-secondary btn-sm" onclick="closeQrLoginModal()" style="padding: 4px 10px;" title="<?php echo t('common.close'); ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" style="padding: 24px 20px;">
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px; line-height: 1.5;">
                <?php echo t('my_phone.qr_howto'); ?>
            </p>

            <div id="qrCodeContainer" style="display: flex; justify-content: center; align-items: center; min-height: 240px; background: #ffffff; padding: 16px; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 12px rgba(0,0,0,0.06); margin: 0 auto; max-width: 250px;">
                <div id="qrLoadingSpinner" class="u-muted u-text-center">
                    <i class="fas fa-spinner fa-spin" style="font-size: 28px; color: var(--primary); margin-bottom: 8px; display: block;"></i>
                    <?php echo t('my_phone.qr_generating'); ?>
                </div>
                <div id="qrSvgWrapper" style="display: none; width: 100%;"></div>
            </div>

            <div id="qrSuccessAlert" style="display: none; margin-top: 16px; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); border-radius: 8px; padding: 12px; color: #059669; font-weight: 600; font-size: 13px;">
                <i class="fas fa-check-circle u-mr-6"></i> <?php echo t('my_phone.qr_paired'); ?>: <span id="qrPairedDevice"></span>
            </div>

            <div id="qrCountdownContainer" style="margin-top: 16px; font-size: 12px; color: var(--text-muted); display: flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-clock"></i> <?php echo t('my_phone.qr_remaining'); ?>: <strong id="qrCountdown" class="u-primary">10:00</strong>
                <button type="button" class="btn btn-ghost btn-xs" onclick="generateNewQrCode()" title="<?php echo t('my_phone.qr_new'); ?>" style="padding: 2px 8px; font-size: 11px;">
                    <i class="fas fa-sync-alt"></i> <?php echo t('common.refresh'); ?>
                </button>
            </div>

            <div style="margin-top: 20px; font-size: 11.5px; color: var(--text-muted); background: var(--bg-input); padding: 12px 14px; border-radius: 8px; text-align: left; line-height: 1.5; border: 1px solid var(--border-color);">
                <i class="fas fa-shield-alt u-primary u-mr-4"></i>
                <?php echo t('my_phone.qr_secure'); ?>
            </div>
        </div>
    </div>
</div>

<!-- WaveSurfer audio player modal (component shared with the CDR reports) -->
<?php require dirname(__DIR__, 2) . '/cdr_audio_player.php'; ?>

<script src="<?php echo asset('/assets/js/my_phone.js'); ?>"></script>
